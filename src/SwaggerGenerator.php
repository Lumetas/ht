<?php

namespace Lum\Ht;

use Symfony\Component\Yaml\Yaml;

class SwaggerGenerator
{
    private array $spec = [];
    private string $baseUrl = '';
    private array $variables = [];
    private array $examples = [];
    private array $requests = [];

    public function load(string $file): void
    {
        if (!file_exists($file)) {
            throw new \RuntimeException("File not found: {$file}");
        }

        $content = file_get_contents($file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($ext === 'yaml' || $ext === 'yml') {
            $this->spec = Yaml::parse($content);
        } else {
            $this->spec = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON: " . json_last_error_msg());
            }
        }
    }

    public function generate(): string
    {
        $this->parseBaseUrl();
        $this->extractVariables();
        $this->generateRequests();

        return $this->render();
    }

    private function parseBaseUrl(): void
    {
        $servers = $this->spec['servers'] ?? [];
        
        if (!empty($servers) && isset($servers[0]['url'])) {
            $this->baseUrl = rtrim($servers[0]['url'], '/');
        } elseif (isset($this->spec['host'], $this->spec['basePath'])) {
            $scheme = $this->spec['schemes'][0] ?? 'https';
            $this->baseUrl = "{$scheme}://{$this->spec['host']}{$this->spec['basePath']}";
        }
    }

    private function resolveRef(string $ref): ?array
    {
        if (!str_starts_with($ref, '#/')) {
            return null;
        }

        $parts = explode('/', substr($ref, 2));
        $current = $this->spec;

        foreach ($parts as $part) {
            if (!isset($current[$part])) {
                return null;
            }
            $current = $current[$part];
        }

        return is_array($current) ? $current : null;
    }

    private function resolveParameters(array $parameters): array
    {
        $resolved = [];
        
        foreach ($parameters as $param) {
            if (isset($param['$ref'])) {
                $resolvedParam = $this->resolveRef($param['$ref']);
                if ($resolvedParam) {
                    $resolved[] = $resolvedParam;
                }
            } else {
                $resolved[] = $param;
            }
        }

        return $resolved;
    }

    private function extractVariables(): void
    {
        $this->variables = [];

        $this->variables['baseUrl'] = $this->baseUrl;

        if (!empty($this->spec['variables'])) {
            foreach ($this->spec['variables'] as $name => $var) {
                $default = $var['default'] ?? '';
                $this->variables[$name] = $default;
            }
        }

        $paths = $this->spec['paths'] ?? [];
        
        foreach ($paths as $path => $methods) {
            foreach ($methods as $method => $details) {
                if (in_array($method, ['parameters', 'summary', 'description', 'servers'])) {
                    continue;
                }

                $parameters = $this->resolveParameters($details['parameters'] ?? []);
                $this->extractParameters($parameters, $path, $method);
            }
        }
    }

    private function extractParameters(array $parameters, string $path, string $method): void
    {
        foreach ($parameters as $param) {
            if (!isset($param['name']) || !isset($param['in'])) {
                continue;
            }

            $name = $param['name'];
            $in = $param['in'];
            
            $varName = $this->makeVariableName($path, $method, $name, $in);

            if (!isset($this->variables[$varName])) {
                $example = $param['example'] ?? $param['default'] ?? '';
                
                if ($in === 'path' && empty($example)) {
                    $example = $name;
                }
                
                $this->variables[$varName] = $example;
                
                if (!empty($example)) {
                    $this->examples[$varName] = $example;
                }
            }
        }
    }

    private function makeVariableName(string $path, string $method, string $name, string $in): string
    {
        $cleanPath = trim($path, '/');
        $cleanPath = preg_replace('/[{}]/', '', $cleanPath);
        $cleanPath = str_replace('/', '_', $cleanPath);
        
        $operationId = "{$method}_{$cleanPath}";
        
        return "{$operationId}_{$in}_{$name}";
    }

    private function generateRequests(): void
    {
        $this->requests = [];
        
        $paths = $this->spec['paths'] ?? [];

        foreach ($paths as $path => $methods) {
            foreach ($methods as $method => $details) {
                if (in_array($method, ['parameters', 'summary', 'description', 'servers'])) {
                    continue;
                }

                $this->generateRequest($path, $method, $details);
            }
        }
    }

    private function generateRequest(string $path, string $method, array $details): void
    {
        $operationId = $details['operationId'] ?? strtolower($method) . '_' . trim($path, '/');
        $operationId = preg_replace('/[^a-zA-Z0-9_]/', '_', $operationId);
        
        $url = '{{baseUrl}}' . $path;
        
        $headers = [];
        $queryParams = [];
        $pathParams = [];
        $body = null;

        $security = $details['security'] ?? $this->spec['security'] ?? [];
        if (!empty($security)) {
            $this->variables['token'] = $this->variables['token'] ?? '';
            $this->examples['token'] = $this->examples['token'] ?? '';
            $headers['Authorization'] = '{{token}}';
        }

        $parameters = $this->resolveParameters($details['parameters'] ?? []);

        foreach ($parameters as $param) {
            if (!isset($param['name']) || !isset($param['in'])) {
                continue;
            }

            $name = $param['name'];
            $in = $param['in'];
            
            $varName = $this->makeVariableName($path, $method, $name, $in);
            
            if ($in === 'header') {
                $headerName = $param['schema']['x-alt-name'] ?? $name;
                $headers[$headerName] = '{{' . $varName . '}}';
            } elseif ($in === 'query') {
                $queryParams[$name] = $varName;
            } elseif ($in === 'path') {
                $pathParams[$name] = '{{' . $varName . '}}';
            }
        }

        if (isset($details['requestBody'])) {
            $content = $details['requestBody']['content'] ?? [];
            
            if (isset($content['application/json'])) {
                $schema = $content['application/json']['schema'] ?? [];
                $body = $this->generateJsonBody($schema);
            }
        }

        $url = $this->substitutePathParams($url, $pathParams);

        $responses = $details['responses'] ?? [];
        $script = $this->generateStatusScript($responses);

        $this->requests[] = [
            'name' => $operationId,
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'query' => $queryParams,
            'body' => $body,
            'summary' => $details['summary'] ?? '',
            'script' => $script,
        ];
    }

    private function generateStatusScript(array $responses): ?string
    {
        $statusMessages = [];
        
        foreach ($responses as $code => $details) {
            if (!is_numeric($code) && !preg_match('/^\d{3}$/', $code)) {
                continue;
            }
            
            $description = $details['description'] ?? "Status {$code}";
            $statusMessages[$code] = $description;
        }

        if (empty($statusMessages)) {
            return null;
        }

        $statusArray = var_export($statusMessages, true);
        
        $script = <<<PHP
<?php
\$statusMessages = {$statusArray};

if (isset(\$statusMessages[\$response->status_code])) {
    \$output->append(\$statusMessages[\$response->status_code]);
} elseif (\$response->status_code >= 500) {
    \$output->append('Internal server error: ' . \$response->status_code);
} elseif (\$response->status_code >= 400) {
    \$output->append('Client error: ' . \$response->status_code);
}
?>
PHP;

        return $script;
    }

    private function substitutePathParams(string $url, array $params): string
    {
        foreach ($params as $name => $value) {
            $url = str_replace('{' . $name . '}', $value, $url);
        }
        return $url;
    }

    private function generateJsonBody(array $schema): string
    {
        if (isset($schema['$ref'])) {
            $resolved = $this->resolveRef($schema['$ref']);
            if ($resolved) {
                return $this->generateJsonBody($resolved);
            }
            $parts = explode('/', $schema['$ref']);
            $name = end($parts);
            return '{"$ref": "' . $name . '"}';
        }

        if (isset($schema['type']) && $schema['type'] === 'object') {
            if (isset($schema['properties'])) {
                $example = [];
                foreach ($schema['properties'] as $propName => $prop) {
                    $example[$propName] = $this->generatePropertyExample($prop);
                }
                return json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
            
            if (isset($schema['allOf'])) {
                $example = [];
                foreach ($schema['allOf'] as $item) {
                    $itemExample = $this->generateJsonBody($item);
                    $decoded = json_decode($itemExample, true);
                    if (is_array($decoded)) {
                        $example = array_merge($example, $decoded);
                    }
                }
                return json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        if (isset($schema['type']) && $schema['type'] === 'array') {
            $items = $schema['items'] ?? [];
            $itemExample = $this->generatePropertyExample($items);
            return json_encode([$itemExample], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        
        return '{}';
    }

    private function generatePropertyExample(array $prop): mixed
    {
        if (isset($prop['$ref'])) {
            $resolved = $this->resolveRef($prop['$ref']);
            if ($resolved) {
                return $this->generatePropertyExample($resolved);
            }
            $parts = explode('/', $prop['$ref']);
            return end($parts);
        }

        $type = $prop['type'] ?? 'string';
        
        return match ($type) {
            'string' => $prop['example'] ?? $prop['default'] ?? 'string',
            'integer' => $prop['example'] ?? $prop['default'] ?? 0,
            'number' => $prop['example'] ?? $prop['default'] ?? 0.0,
            'boolean' => $prop['example'] ?? $prop['default'] ?? true,
            'array' => $prop['example'] ?? [],
            'object' => $prop['example'] ?? (object)[],
            default => 'value',
        };
    }

    private function render(): string
    {
        $lines = [];

        $lines[] = '# Generated from OpenAPI spec';
        $lines[] = '';

        foreach ($this->variables as $name => $default) {
            if ($name === 'baseUrl') {
                $lines[] = "@{$name} = {$default}";
            } elseif ($name === 'token') {
                $lines[] = "@{$name} = {{\${$name}}}";
            } elseif (isset($this->examples[$name])) {
                // skip - example used directly in request
            } else {
                $lines[] = "@{$name} = {{\${$name}}}";
            }
        }

        $lines[] = '';
        $lines[] = '### #main';
        $lines[] = 'GET {{baseUrl}}/';
        $lines[] = '';

        foreach ($this->requests as $request) {
            $lines[] = '### #' . $request['name'];
            
            if ($request['summary']) {
                $lines[] = '# ' . $request['summary'];
            }
            
            $lines[] = $request['method'] . ' ' . $request['url'];

            foreach ($request['headers'] as $name => $value) {
                $lines[] = "{$name}: {$value}";
            }

            foreach ($request['query'] as $name => $varName) {
                if (isset($this->examples[$varName])) {
                    $lines[] = "{$name}={$this->examples[$varName]}";
                } else {
                    $lines[] = "{$name}={{{$varName}}}";
                }
            }

            if ($request['body']) {
                $lines[] = 'Content-Type: application/json';
                $lines[] = '';
                $lines[] = $request['body'];
            }

            if ($request['script']) {
                $lines[] = '';
                $lines[] = $request['script'];
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function getVariables(): array
    {
        return $this->variables;
    }
}

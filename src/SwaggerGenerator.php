<?php

namespace Lum\Ht;

use Symfony\Component\Yaml\Yaml;

class SwaggerGenerator
{
    private array $spec = [];
    private string $baseUrl = '';
    private array $variables = [];
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

    private function extractVariables(): void
    {
        $this->variables = [];

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

                $this->extractParameters($details['parameters'] ?? [], $path, $method);
            }
        }
    }

    private function extractParameters(array $parameters, string $path, string $method): void
    {
        foreach ($parameters as $param) {
            $name = $param['name'];
            $in = $param['in'];
            
            $varName = $this->makeVariableName($path, $method, $name, $in);

            if (!isset($this->variables[$varName])) {
                $default = $param['default'] ?? '';
                
                if ($in === 'path' && empty($default)) {
                    $default = $name;
                }
                
                $this->variables[$varName] = $default;
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
        
        $url = $this->baseUrl . $path;
        
        $headers = [];
        $queryParams = [];
        $pathParams = [];
        $body = null;

        $parameters = $details['parameters'] ?? [];

        foreach ($parameters as $param) {
            $name = $param['name'];
            $in = $param['in'];
            $required = $param['required'] ?? false;
            
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

        $this->requests[] = [
            'name' => $operationId,
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'query' => $queryParams,
            'body' => $body,
            'summary' => $details['summary'] ?? '',
        ];
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
        if (isset($schema['type']) && $schema['type'] === 'object' && isset($schema['properties'])) {
            $example = [];
            foreach ($schema['properties'] as $name => $prop) {
                $example[$name] = $this->generatePropertyExample($prop);
            }
            return json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        
        if (isset($schema['$ref'])) {
            $ref = $schema['$ref'];
            $parts = explode('/', $ref);
            $name = end($parts);
            return '{"$ref": "' . $name . '"}';
        }

        return '{}';
    }

    private function generatePropertyExample(array $prop): mixed
    {
        $type = $prop['type'] ?? 'string';
        
        return match ($type) {
            'string' => $prop['example'] ?? 'string',
            'integer' => $prop['example'] ?? 0,
            'number' => $prop['example'] ?? 0.0,
            'boolean' => $prop['example'] ?? true,
            'array' => [],
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
            $lines[] = "@{$name} = {{\${$name}}}";
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
                $lines[] = "{$name}={{{$varName}}}";
            }

            if ($request['body']) {
                $lines[] = 'Content-Type: application/json';
                $lines[] = '';
                $lines[] = $request['body'];
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

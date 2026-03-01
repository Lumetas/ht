# ht - HTTP Testing Tool

A command-line HTTP client for testing APIs, written in PHP. Inspired by [ht.nvim](https://github.com/lima1909/ht.nvim) (HTTP Toolkit for Neovim).

## Features

- Define multiple HTTP requests in a single `.ht` file
- Support for global and local variables
- Variable substitution with `{{variable}}` syntax
- Environment variables support (`$VAR`)
- Command execution in variables (`>command`, `>>command` for cached)
- Pre and Post scripts (full PHP)
- Request chaining with `$api->send()`
- Output control with `$output->write()` and `$output->append()`
- JSON body support
- Headers and query parameters
- Configurable timeout, insecure SSL, proxy
- Script-only requests (requests without URL)
- Immediate request chaining in loops

## Installation

```bash
chmod +x ht
./ht --help
```

## Usage

```bash
ht <file.ht> [requestName]      # Run specific request (body only)
ht -a <file.ht> [requestName]   # Run with all info (headers, status, etc.)
ht --all <file.ht> [requestName] # Same as -a
```

If `requestName` is omitted, defaults to `main`.

## Output Modes

**Default** (body only):
```bash
$ ht file.ht myRequest
{"success":true,"data":{"id":1}}
```

**With `-a` flag** (all information):
```bash
$ ht -a file.ht myRequest
[INFO] Executing: GET https://api.example.com/data

=== Request ===
GET https://api.example.com/data

Headers:
  Content-Type: application/json

Body:
{"key":"value"}
===============

=== Response ===
Status: 200

Headers:
  HTTP/1.1 200 OK
  Content-Type: application/json

Body:
{"success":true,"data":{"id":1}}
===============
```

## File Format

### Basic Structure

```http
### #requestName
METHOD https://api.example.com/endpoint
Header-Name: header-value

{
    "body": "json"
}
```

### Comments

Lines starting with `#` are comments:

```http
# This is a comment
### #myRequest
GET https://api.example.com
# Another comment
```

### Variables

**Global variables** (available to all requests):

```http
@baseUrl = https://api.example.com
@apiKey = secret-key

### #main
GET {{baseUrl}}/users
Authorization: Bearer {{apiKey}}
```

**Local variables** (available only in current request, override global):

```http
### #getUser
@userId = 123
GET https://api.example.com/users/{{userId}}
```

**Variable types**:

| Syntax | Description |
|--------|-------------|
| `{{variable}}` | Regular variable |
| `{{$ENV_VAR}}` | Environment variable |
| `{{>command}}` | Execute command and use output |
| `{{>>command}}` | Execute command, cache result |

### Configuration

Variables starting with `@cfg.` configure the request:

```http
@cfg.timeout = 5000      # Timeout in milliseconds
@cfg.insecure = true     # Skip SSL verification
@cfg.dry_run = true      # Don't send request
@cfg.proxy = http://proxy:8080
```

### Headers

```http
### #apiRequest
GET https://api.example.com/data
Content-Type: application/json
Accept: application/json
X-Custom-Header: value
```

### Query Parameters

```http
### #search
GET https://api.example.com/search
name=John
age=30
```

### Request Body

```http
### #create
POST https://api.example.com/users
Content-Type: application/json

{
    "name": "John",
    "email": "john@example.com"
}
```

## Scripts

Scripts are written in PHP and executed before or after the request.

### Post-Script (after request)

```http
### #login
POST https://api.example.com/login
Content-Type: application/json

{
    "username": "admin",
    "password": "secret"
}

<?php
// Access response
$api->set('token', $response->json_body()['token']);
$output->write('Login successful!');

// Available functions:
var_dump($var);
print_r($var);
json_decode($json);
json_encode($data);
?>
```

### Pre-Script (before request)

```http
### #modifyRequest
GET https://api.example.com/data

<?php
#pre
// Modify request before sending
$request->method = 'POST';
$request->url = 'https://api.example.com/create';
$request->headers['X-Custom'] = 'value';
$request->body = '{"modified": true}';
$request->query['page'] = '1';
$api->set('some_var', 'value');
#post
// This runs after response
$output->append('Request completed');
?>
```

### Pre-Script modifying query parameters

Variables set in pre-script are available for URL and query replacement:

```http
### #main
GET https://api.example.com/search
name=test

<?php
#pre
$api->set('name', 'modified');
$request->query['page'] = 1;
?>
```

### Script-only requests (no URL)

You can create requests without URLs that only run scripts:

```http
### #main

<?php
$api->set('counter', 0);
while($api->get('counter') < 10) {
    $api->send('loop');
    $api->set('counter', (int)$api->get('counter') + 1);
}
?>

### #loop
GET https://api.example.com/data?count={{counter}}
```

### Script API

**$response** (post-script only):

| Property/Method | Description |
|----------------|-------------|
| `$response->body` | Response body as string |
| `$response->status_code` | HTTP status code |
| `$response->headers` | Headers as associative array |
| `$response->json_body()` | Parse JSON body (cached) |

**$request** (pre-script only):

| Property/Method | Description |
|----------------|-------------|
| `$request->url` | Request URL |
| `$request->method` | Request method (GET, POST, etc.) |
| `$request->headers` | Headers as associative array |
| `$request->body` | Request body |
| `$request->query` | Query parameters as associative array |

**$api**:

| Method | Description |
|--------|-------------|
| `$api->set(key, value)` | Set global variable |
| `$api->get(key)` | Get variable (global or local) |
| `$api->send(name)` | Execute another request immediately |

**$output**:

| Method | Description |
|--------|-------------|
| `$output->write(text)` | Replace response body with custom text |
| `$output->append(text)` | Add text after response body |

**Output behavior**:

| Mode | `write()` | `append()` | No output call |
|------|-----------|------------|-----------------|
| Without `-a` | Custom text only | Body + custom | Body only |
| With `-a` | Full info + custom | Full info + custom | Full info |

**Global functions**:
- `var_dump($var)` - Dump variable
- `print_r($var)` - Print readable
- `json_decode($json, true)` - Parse JSON
- `json_encode($data)` - Encode to JSON

### Request Chaining

```http
### #first
GET https://api.example.com/step1

<?php
$api->set('id', $response->json_body()['id']);
$api->send('second');
?>

### #second
GET https://api.example.com/step2/{{id}}
```

### Immediate chaining in loops

The `$api->send()` method executes requests immediately, enabling loops:

```http
### #main

<?php
$i = 0;
while($i < 10) {
    $api->set('i', $i);
    $api->send('loop');
    $i++;
}
?>

### #loop
GET https://api.example.com/count?value={{i}}
```

## Examples

### Simple GET

```http
@host = jsonplaceholder.org

### #main
GET https://{{host}}/users/1
```

### POST with JSON

```http
### #create
POST https://api.example.com/users
Content-Type: application/json

{
    "name": "John Doe",
    "email": "john@example.com"
}
```

### Authentication Flow

```http
@host = api.example.com

### #login
POST https://{{host}}/auth/login
Content-Type: application/json

{
    "username": "admin",
    "password": "secret"
}

<?php
$api->set('token', $response->json_body()['token']);
?>

### #getData
GET https://{{host}}/data
Authorization: Bearer {{token}}
```

### Custom Output

```http
### #custom
GET https://api.example.com/data

<?php
// Replace response with custom output
$output->write('Request completed successfully!');
?>

### #append
GET https://api.example.com/data

<?php
// Add info after response
$output->append('Cached: yes');
?>
```

### Pre-script with query modification

```http
### #main
GET https://api.example.com/search
name=test

<?php
#pre
$request->query['page'] = 1;
$request->query['limit'] = 10;
$api->set('name', 'modified');
?>
```

## Testing

Start the mock server:

```bash
php -S 127.0.0.1:8888 api.php
```

Run tests:

```bash
./run-tests.sh
```

## Requirements

- PHP 8.1+

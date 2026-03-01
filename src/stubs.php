<?php

class IDE
{

	public static function init(): void
	{
		// =========================================================================
		// OUTPUT CLASS
		// =========================================================================
		if (!class_exists(Output::class)) {
			class Output
			{
				/** Replace response body with custom text */
				public function write(string $string): void {}

				/** Add text after response body */
				public function append(string $string): void {}
			}
		}

		// =========================================================================
		// RESPONSE CLASS
		// =========================================================================
		if (!class_exists(Response::class)) {
			class Response
			{
				/** Response body as string */
				public string $body;

				/** HTTP status code */
				public int $status_code;

				/** Headers as associative array */
				public array $headers;

				/**
				 * Parse JSON body (cached)
				 * @return array<string, mixed>
				 */
				public function json_body(): array
				{
					return [];
				}

				public function __construct(int $status_code = 200)
				{
					$this->status_code = $status_code;
					$this->body = '';
					$this->headers = [];
				}
			}
		}

		// =========================================================================
		// REQUEST CLASS
		// =========================================================================
		if (!class_exists(Request::class)) {
			class Request
			{
				/** HTTP method (GET, POST, etc.) */
				public string $method;

				/** Request URL */
				public string $url;

				/** Request headers */
				public array $headers;

				/** Request body */
				public string $body;

				public function __construct()
				{
					$this->method = 'GET';
					$this->url = '';
					$this->headers = [];
					$this->body = '';
				}
			}
		}

		// =========================================================================
		// API CLASS (for chaining and variables)
		// =========================================================================
		if (!class_exists(Api::class)) {
			class Api
			{
				/** @var array<string, mixed> */
				private array $storage = [];

				/**
				 * Set global variable
				 * @param string $key
				 * @param mixed $value
				 */
				public function set(string $key, $value): void {}

				/**
				 * Get global variable
				 * @param string $key
				 * @return mixed
				 */
				public function get(string $key) {}

				/**
				 * Execute another request
				 * @param string $name Request name
				 * @return mixed
				 */
				public function send(string $name) {}
			}
		}

    // =========================================================================
    // GLOBAL VARIABLES
    // =========================================================================

		$_GLOBALS['api'] = new Api();
		$_GLOBALS['output'] = new Output();
		$_GLOBALS['response'] = new Response(200);
		$_GLOBALS['request'] = new Request();
	}
}

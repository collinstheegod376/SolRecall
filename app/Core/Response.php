<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fluent HTTP Response Abstraction
 *
 * Handles HTTP status codes, response headers, JSON payloads,
 * HTML template responses, and redirects.
 */
class Response
{
    private int $statusCode;
    private array $headers = [];
    private string $body = '';

    public function __construct(string $body = '', int $statusCode = 200, array $headers = [])
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    /**
     * Create generic response instance.
     */
    public static function make(string $body = '', int $statusCode = 200, array $headers = []): static
    {
        return new static($body, $statusCode, $headers);
    }

    /**
     * Set the HTTP status code.
     */
    public function withStatus(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Add or overwrite a response header.
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Retrieve all response headers.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Set the response body.
     */
    public function withBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Get the response body.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Format and return JSON response.
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): static
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        return new static($encoded !== false ? $encoded : '{}', $status, $headers);
    }

    /**
     * Format and return HTML response.
     */
    public static function html(string $html, int $status = 200, array $headers = []): static
    {
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return new static($html, $status, $headers);
    }

    /**
     * Create an HTTP redirect response.
     */
    public static function redirect(string $url, int $status = 302): static
    {
        $instance = new static('', $status);
        $instance->withHeader('Location', $url);
        return $instance;
    }

    /**
     * Standard success JSON response helper.
     */
    public static function success(mixed $data = null, string $message = 'Success', int $status = 200): static
    {
        return static::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Standard error JSON response helper.
     */
    public static function error(string $message, int $status = 400, array $errors = []): static
    {
        $payload = [
            'success' => false,
            'error' => $message,
        ];
        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }
        return static::json($payload, $status);
    }

    /**
     * Emit headers and output body to the client.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo $this->body;
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP Request Abstraction
 *
 * Encapsulates HTTP method, URI, query params, POST payloads,
 * parsed JSON request bodies, and headers.
 */
class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private array $getParams;
    private array $postParams;
    private array $serverParams;
    private array $cookies;
    private ?array $jsonParams = null;
    private ?string $rawBody = null;
    private array $routeParams = [];

    public function __construct(
        array $get = [],
        array $post = [],
        array $server = [],
        array $cookies = [],
        ?string $rawBody = null
    ) {
        $this->getParams = $get;
        $this->postParams = $post;
        $this->serverParams = $server;
        $this->cookies = $cookies;
        $this->rawBody = $rawBody;

        $this->method = strtoupper($this->serverParams['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $this->serverParams['REQUEST_URI'] ?? '/';

        $parsedPath = parse_url($this->uri, PHP_URL_PATH);
        $this->path = $parsedPath !== false && $parsedPath !== null ? rawurldecode($parsedPath) : '/';
    }

    /**
     * Create a Request instance populated with PHP superglobals.
     */
    public static function createFromGlobals(): static
    {
        $rawBody = file_get_contents('php://input');
        return new static(
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
            $rawBody !== false ? $rawBody : null
        );
    }

    /**
     * Get the HTTP request method (e.g. GET, POST).
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Determine if the request matches a given method.
     */
    public function isMethod(string $method): bool
    {
        return strtoupper($method) === $this->method;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * Get full requested URI including query string.
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Get cleaned path component without query string.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Retrieve parameter(s) from GET query string.
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->getParams;
        }
        return $this->getParams[$key] ?? $default;
    }

    /**
     * Retrieve parameter(s) from form POST body.
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->postParams;
        }
        return $this->postParams[$key] ?? $default;
    }

    /**
     * Retrieve parsed JSON body parameter(s).
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->jsonParams === null) {
            $raw = $this->getRawBody();
            if ($raw !== null && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                $this->jsonParams = is_array($decoded) ? $decoded : [];
            } else {
                $this->jsonParams = [];
            }
        }

        if ($key === null) {
            return $this->jsonParams;
        }
        return $this->jsonParams[$key] ?? $default;
    }

    /**
     * Retrieve raw unparsed request body string.
     */
    public function getRawBody(): ?string
    {
        return $this->rawBody;
    }

    /**
     * Retrieve input value searching JSON body, then POST, then GET, then route params.
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        $all = $this->all();
        if ($key === null) {
            return $all;
        }
        return $all[$key] ?? $default;
    }

    /**
     * Retrieve all input data merged from GET, POST, JSON, and route params.
     */
    public function all(): array
    {
        $json = $this->json();
        return array_merge(
            $this->getParams,
            $this->postParams,
            is_array($json) ? $json : [],
            $this->routeParams
        );
    }

    /**
     * Determine if a specific key exists in input.
     */
    public function has(string $key): bool
    {
        $all = $this->all();
        return array_key_exists($key, $all);
    }

    /**
     * Set matched route parameters.
     */
    public function setRouteParams(array $params): self
    {
        $this->routeParams = $params;
        return $this;
    }

    /**
     * Retrieve a route parameter by name or all route params.
     */
    public function route(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->routeParams;
        }
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Determine if the request contains JSON Content-Type.
     */
    public function isJson(): bool
    {
        $contentType = $this->header('Content-Type', '');
        return str_contains(strtolower($contentType), 'application/json');
    }

    /**
     * Determine if request accepts JSON response.
     */
    public function expectsJson(): bool
    {
        $accept = $this->header('Accept', '');
        return str_contains(strtolower($accept), 'application/json') || $this->isJson();
    }

    /**
     * Get an HTTP request header by case-insensitive name.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $headers = $this->headers();
        $normalizedName = strtolower($name);
        return $headers[$normalizedName] ?? $default;
    }

    /**
     * Get all request headers normalized with lowercase keys.
     */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->serverParams as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $headerName = strtolower(str_replace('_', '-', $key));
                $headers[$headerName] = (string) $value;
            }
        }
        return $headers;
    }

    /**
     * Extract Bearer token from Authorization header if present.
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth !== null && preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Get client IP address.
     */
    public function ip(): string
    {
        $keys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($keys as $key) {
            if (!empty($this->serverParams[$key])) {
                $ips = explode(',', (string) $this->serverParams[$key]);
                return trim($ips[0]);
            }
        }

        return '127.0.0.1';
    }

    /**
     * Get client user agent.
     */
    public function userAgent(): string
    {
        return (string) ($this->serverParams['HTTP_USER_AGENT'] ?? '');
    }

    /**
     * Sanitize string input to protect against XSS when echoing.
     */
    public function sanitize(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

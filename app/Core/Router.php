<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

/**
 * Lightweight Zero-Dependency HTTP Router
 *
 * Supports RESTful HTTP verbs, dynamic regex URL parameter extraction,
 * nested route groups with shared prefixes/middlewares, and layered middleware pipelines.
 */
class Router
{
    /** @var array<array{method: string, pattern: string, regex: string, paramNames: array<string>, handler: mixed, middlewares: array}> */
    private array $routes = [];

    /** @var array<string> */
    private array $groupPrefixStack = [];

    /** @var array<array> */
    private array $groupMiddlewareStack = [];

    /** @var mixed */
    private $notFoundHandler = null;

    /** @var mixed */
    private $methodNotAllowedHandler = null;

    public function __construct()
    {
        // Default 404 handler
        $this->notFoundHandler = function (Request $request): Response {
            if ($request->expectsJson()) {
                return Response::error('Resource not found', 404);
            }
            return Response::html('<h1>404 - Not Found</h1><p>The requested route does not exist.</p>', 404);
        };

        // Default 405 handler
        $this->methodNotAllowedHandler = function (Request $request, array $allowedMethods): Response {
            $allowed = implode(', ', $allowedMethods);
            if ($request->expectsJson()) {
                return Response::error("Method not allowed. Allowed methods: {$allowed}", 405);
            }
            return Response::html("<h1>405 - Method Not Allowed</h1><p>Allowed methods: {$allowed}</p>", 405)
                ->withHeader('Allow', $allowed);
        };
    }

    /**
     * Register a GET route.
     */
    public function get(string $pattern, mixed $handler, array $middlewares = []): self
    {
        return $this->addRoute('GET', $pattern, $handler, $middlewares);
    }

    /**
     * Register a POST route.
     */
    public function post(string $pattern, mixed $handler, array $middlewares = []): self
    {
        return $this->addRoute('POST', $pattern, $handler, $middlewares);
    }

    /**
     * Register a PUT route.
     */
    public function put(string $pattern, mixed $handler, array $middlewares = []): self
    {
        return $this->addRoute('PUT', $pattern, $handler, $middlewares);
    }

    /**
     * Register a DELETE route.
     */
    public function delete(string $pattern, mixed $handler, array $middlewares = []): self
    {
        return $this->addRoute('DELETE', $pattern, $handler, $middlewares);
    }

    /**
     * Register a PATCH route.
     */
    public function patch(string $pattern, mixed $handler, array $middlewares = []): self
    {
        return $this->addRoute('PATCH', $pattern, $handler, $middlewares);
    }

    /**
     * Register a route responding to any standard HTTP verb.
     */
    public function any(string $pattern, mixed $handler, array $middlewares = []): self
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
        foreach ($methods as $method) {
            $this->addRoute($method, $pattern, $handler, $middlewares);
        }
        return $this;
    }

    /**
     * Define a route group with shared attributes (prefix, middlewares).
     */
    public function group(array $attributes, callable $callback): void
    {
        $prefix = $attributes['prefix'] ?? '';
        $middlewares = $attributes['middleware'] ?? ($attributes['middlewares'] ?? []);
        if (!is_array($middlewares)) {
            $middlewares = [$middlewares];
        }

        $this->groupPrefixStack[] = trim($prefix, '/');
        $this->groupMiddlewareStack[] = $middlewares;

        $callback($this);

        array_pop($this->groupPrefixStack);
        array_pop($this->groupMiddlewareStack);
    }

    /**
     * Register route entry and compile regex pattern.
     */
    private function addRoute(string $method, string $pattern, mixed $handler, array $middlewares = []): self
    {
        // Compute full path with active group prefixes
        $prefix = implode('/', array_filter($this->groupPrefixStack));
        $fullPattern = '/' . trim($prefix . '/' . trim($pattern, '/'), '/');
        if ($fullPattern !== '/' && str_ends_with($fullPattern, '/')) {
            $fullPattern = rtrim($fullPattern, '/');
        }

        // Merge group middlewares
        $allMiddlewares = [];
        foreach ($this->groupMiddlewareStack as $groupMiddlewares) {
            $allMiddlewares = array_merge($allMiddlewares, $groupMiddlewares);
        }
        $allMiddlewares = array_merge($allMiddlewares, $middlewares);

        // Compile route pattern to regex and extract parameter names
        // Supports: {id}, {id:\d+}, {name:[a-zA-Z]+}
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z0-9_]+)(?::([^}]+))?\}/', function ($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            return '(' . ($matches[2] ?? '[^/]+') . ')';
        }, $fullPattern);

        $regex = '#^' . $regex . '$#u';

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $fullPattern,
            'regex' => $regex,
            'paramNames' => $paramNames,
            'handler' => $handler,
            'middlewares' => $allMiddlewares,
        ];

        return $this;
    }

    /**
     * Set custom handler for 404 Not Found.
     */
    public function setNotFoundHandler(callable $handler): void
    {
        $this->notFoundHandler = $handler;
    }

    /**
     * Set custom handler for 405 Method Not Allowed.
     */
    public function setMethodNotAllowedHandler(callable $handler): void
    {
        $this->methodNotAllowedHandler = $handler;
    }

    /**
     * Match and dispatch an incoming HTTP request.
     */
    public function dispatch(?Request $request = null): Response
    {
        $request = $request ?? Request::createFromGlobals();
        $path = '/' . trim($request->getPath(), '/');
        $method = $request->getMethod();

        $matchingPathRoutes = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                $matchingPathRoutes[] = $route;
                if ($route['method'] === $method) {
                    array_shift($matches); // Remove full match

                    $params = [];
                    foreach ($route['paramNames'] as $index => $name) {
                        $params[$name] = $matches[$index] ?? null;
                    }
                    $request->setRouteParams($params);

                    return $this->executeMiddlewarePipeline($route['middlewares'], $request, function (Request $req) use ($route, $params) {
                        return $this->invokeHandler($route['handler'], $req, $params);
                    });
                }
            }
        }

        // Path matches but wrong HTTP method -> 405 Method Not Allowed
        if (!empty($matchingPathRoutes)) {
            $allowedMethods = array_unique(array_column($matchingPathRoutes, 'method'));
            $res = call_user_func($this->methodNotAllowedHandler, $request, $allowedMethods);
            return $this->ensureResponse($res);
        }

        // No path matched -> 404 Not Found
        $res = call_user_func($this->notFoundHandler, $request);
        return $this->ensureResponse($res);
    }

    /**
     * Execute layered middleware pipeline around core action.
     */
    private function executeMiddlewarePipeline(array $middlewares, Request $request, callable $target): Response
    {
        $pipeline = array_reduce(
            array_reverse($middlewares),
            function (callable $next, mixed $middleware) {
                return function (Request $req) use ($middleware, $next): Response {
                    if (is_callable($middleware)) {
                        $res = $middleware($req, $next);
                    } elseif (is_string($middleware) && class_exists($middleware)) {
                        $instance = new $middleware();
                        if (method_exists($instance, 'handle')) {
                            $res = $instance->handle($req, $next);
                        } else {
                            throw new RuntimeException("Middleware {$middleware} must implement handle() method.");
                        }
                    } else {
                        throw new RuntimeException("Invalid middleware configuration.");
                    }
                    return $this->ensureResponse($res);
                };
            },
            function (Request $req) use ($target): Response {
                $res = $target($req);
                return $this->ensureResponse($res);
            }
        );

        return $pipeline($request);
    }

    /**
     * Invoke the route handler (Closure, Controller@action string, or [Class, action] array).
     */
    private function invokeHandler(mixed $handler, Request $request, array $params): Response
    {
        // 1. String format: "ControllerName@actionName"
        if (is_string($handler) && str_contains($handler, '@')) {
            [$controllerClass, $method] = explode('@', $handler, 2);
            if (!class_exists($controllerClass)) {
                $controllerClass = "App\\Controllers\\{$controllerClass}";
            }

            if (!class_exists($controllerClass)) {
                throw new RuntimeException("Controller class {$controllerClass} not found.");
            }

            $controllerInstance = new $controllerClass();
            if (!method_exists($controllerInstance, $method)) {
                throw new RuntimeException("Action {$method} does not exist on controller {$controllerClass}.");
            }

            $res = $controllerInstance->$method($request, ...array_values($params));
            return $this->ensureResponse($res);
        }

        // 2. Callable format: Closure or [$instance, 'method']
        if (is_callable($handler)) {
            $res = call_user_func($handler, $request, ...array_values($params));
            return $this->ensureResponse($res);
        }

        throw new RuntimeException("Invalid route handler configured.");
    }

    /**
     * Ensure any return value is wrapped into a Response instance.
     */
    private function ensureResponse(mixed $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        if (is_array($response) || is_object($response)) {
            return Response::json($response);
        }

        if (is_string($response)) {
            return Response::html($response);
        }

        return Response::make('', 204);
    }
}

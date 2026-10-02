<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * Regex-compiled router with route groups, per-route middleware and named
 * placeholders.
 *
 *   $router->get('/creators/{id}', [CreatorController::class, 'show'])
 *          ->middleware('auth');
 *
 *   $router->group(['prefix' => '/bookings', 'middleware' => ['auth']], function ($r) {
 *       $r->post('/{id}/accept', [BookingController::class, 'accept']);
 *   });
 *
 * Placeholders accept an inline pattern: `{id:\d+}`.
 */
final class Router
{
    /** @var array<string, list<array{pattern:string,params:list<string>,handler:mixed,middleware:list<string>,name:string}>> */
    private array $routes = [];

    /** @var array<string,string> Named route => path template */
    private array $names = [];

    /** @var array{prefix:string,middleware:list<string>} */
    private array $groupStack = ['prefix' => '', 'middleware' => []];

    /** @var array<string,callable> */
    private array $middlewareRegistry = [];

    /** @var array{method:string,index:int}|null Cursor for fluent ->middleware()/->name() */
    private ?array $lastRoute = null;

    private mixed $fallback = null;

    // -----------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------

    public function get(string $path, mixed $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): self
    {
        return $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, mixed $handler): self
    {
        return $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, mixed $handler): self
    {
        return $this->add('DELETE', $path, $handler);
    }

    /** @param list<string> $methods */
    public function match(array $methods, string $path, mixed $handler): self
    {
        foreach ($methods as $method) {
            $this->add(strtoupper($method), $path, $handler);
        }

        return $this;
    }

    public function any(string $path, mixed $handler): self
    {
        return $this->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $handler);
    }

    /**
     * @param array{prefix?:string,middleware?:list<string>|string} $attributes
     */
    public function group(array $attributes, callable $callback): void
    {
        $previous = $this->groupStack;

        $middleware = $attributes['middleware'] ?? [];
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }

        $this->groupStack = [
            'prefix'     => $previous['prefix'] . ($attributes['prefix'] ?? ''),
            'middleware' => array_merge($previous['middleware'], $middleware),
        ];

        $callback($this);

        $this->groupStack = $previous;
    }

    /** Attach middleware to the route registered immediately before. */
    public function middleware(string ...$names): self
    {
        if ($this->lastRoute !== null) {
            $route = &$this->routes[$this->lastRoute['method']][$this->lastRoute['index']];
            $route['middleware'] = array_values(array_unique(array_merge($route['middleware'], $names)));
        }

        return $this;
    }

    /** Name the route registered immediately before. */
    public function name(string $name): self
    {
        if ($this->lastRoute !== null) {
            $route         = &$this->routes[$this->lastRoute['method']][$this->lastRoute['index']];
            $route['name'] = $name;
            $this->names[$name] = $route['template'] ?? '';
        }

        return $this;
    }

    /** Register a named middleware implementation. */
    public function registerMiddleware(string $name, callable $handler): void
    {
        $this->middlewareRegistry[$name] = $handler;
    }

    /** Handler used when nothing matches (the admin panel renders a 404 page). */
    public function fallback(mixed $handler): void
    {
        $this->fallback = $handler;
    }

    private function add(string $method, string $path, mixed $handler): self
    {
        $template = $this->groupStack['prefix'] . $path;
        $template = '/' . trim($template, '/');
        if ($template === '/') {
            $template = '/';
        }

        [$pattern, $params] = $this->compile($template);

        $this->routes[$method][] = [
            'template'   => $template,
            'pattern'    => $pattern,
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => $this->groupStack['middleware'],
            'name'       => '',
        ];

        $this->lastRoute = ['method' => $method, 'index' => count($this->routes[$method]) - 1];

        return $this;
    }

    /**
     * Turn `/creators/{id:\d+}/packages/{packageId}` into a PCRE pattern.
     *
     * @return array{0:string,1:list<string>}
     */
    private function compile(string $template): array
    {
        $params  = [];
        $pattern = '';
        $offset  = 0;

        // Quote only the literal segments; the placeholder patterns are ours
        // and must reach PCRE unescaped.
        $found = preg_match_all(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            $template,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        if ($found > 0) {
            foreach ($matches as $match) {
                $start    = (int) $match[0][1];
                $pattern .= preg_quote(substr($template, $offset, $start - $offset), '#');

                $params[] = (string) $match[1][0];
                $regex    = (isset($match[2]) && (int) $match[2][1] !== -1 && $match[2][0] !== '')
                    ? (string) $match[2][0]
                    : '[^/]+';

                $pattern .= '(' . $regex . ')';
                $offset   = $start + strlen((string) $match[0][0]);
            }
        }

        $pattern .= preg_quote(substr($template, $offset), '#');

        return ['#^' . $pattern . '$#u', $params];
    }

    // -----------------------------------------------------------------
    // Dispatch
    // -----------------------------------------------------------------

    /**
     * Resolve and run the matching route.
     *
     * @throws HttpException 404 / 405 when nothing matches.
     */
    public function dispatch(Request $request): mixed
    {
        $method = $request->method();
        $path   = $request->path();

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches) !== 1) {
                continue;
            }

            array_shift($matches);

            $params = [];
            foreach ($route['params'] as $index => $name) {
                $params[$name] = $matches[$index] ?? '';
            }

            $request->setRouteParams($params);
            $request->setAttribute('route_name', $route['name']);

            foreach ($route['middleware'] as $middlewareName) {
                $this->runMiddleware($middlewareName, $request);
            }

            return $this->callHandler($route['handler'], $request);
        }

        // Distinguish "wrong verb" from "no such route" so clients get 405.
        $allowed = [];
        foreach ($this->routes as $otherMethod => $routes) {
            if ($otherMethod === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $path) === 1) {
                    $allowed[] = $otherMethod;
                    break;
                }
            }
        }

        if ($allowed !== []) {
            Response::methodNotAllowed(array_values(array_unique($allowed)));
        }

        if ($this->fallback !== null) {
            return $this->callHandler($this->fallback, $request);
        }

        throw HttpException::notFound('The requested endpoint does not exist.');
    }

    /**
     * Middleware may accept an argument after a colon, e.g. `permission:users.edit`.
     */
    private function runMiddleware(string $definition, Request $request): void
    {
        [$name, $argument] = array_pad(explode(':', $definition, 2), 2, null);

        if (!isset($this->middlewareRegistry[$name])) {
            throw new \RuntimeException('Unregistered middleware: ' . $name);
        }

        ($this->middlewareRegistry[$name])($request, $argument);
    }

    private function callHandler(mixed $handler, Request $request): mixed
    {
        if (is_callable($handler)) {
            return $handler($request);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;

            if (!class_exists($class)) {
                throw new \RuntimeException('Controller not found: ' . (string) $class);
            }

            $controller = new $class();

            if (!method_exists($controller, $method)) {
                throw new \RuntimeException('Action not found: ' . $class . '::' . $method);
            }

            return $controller->{$method}($request);
        }

        throw new \RuntimeException('Invalid route handler.');
    }

    /** @return array<string,string> */
    public function namedRoutes(): array
    {
        return $this->names;
    }
}

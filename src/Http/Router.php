<?php

declare(strict_types=1);

namespace App\Http;

use Closure;
use LogicException;

/**
 * Small explicit router. Patterns use `{name}` placeholders matching a single
 * path segment; parameters are passed to the handler as decoded strings.
 *
 * Every route carries a `RouteAccess`. A route without one cannot be registered,
 * so "protected by forgetting" is not a reachable state (SEC 8.2).
 */
final class Router
{
    /**
     * @var list<array{
     *     method: string, pattern: string, regex: string,
     *     handler: Closure, access: RouteAccess
     * }>
     */
    private array $routes = [];

    /**
     * @param (Closure(RouteAccess): void)|null $authorize runs before a guarded
     *        handler; a guarded route without one is a configuration error
     */
    private ?Closure $authorize;

    /** @param (Closure(RouteAccess): void)|null $authorize */
    public function __construct(?Closure $authorize = null)
    {
        $this->authorize = $authorize;
    }

    /** Installed by the composition root once the application exists. */
    public function authorizeWith(Closure $authorize): void
    {
        $this->authorize = $authorize;
    }

    /** @param Closure(Request, array<string, string>): Response $handler */
    public function add(
        string $method,
        string $pattern,
        Closure $handler,
        RouteAccess $access,
    ): self {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $this->compile($pattern),
            'handler' => $handler,
            'access' => $access,
        ];

        return $this;
    }

    /** @param Closure(Request, array<string, string>): Response $handler */
    public function get(string $pattern, Closure $handler, RouteAccess $access): self
    {
        return $this->add('GET', $pattern, $handler, $access);
    }

    /** @param Closure(Request, array<string, string>): Response $handler */
    public function post(string $pattern, Closure $handler, RouteAccess $access): self
    {
        return $this->add('POST', $pattern, $handler, $access);
    }

    /**
     * The route table as data, so a test can assert that every entry declares its
     * access instead of trusting that a reviewer noticed the missing one.
     *
     * @return list<array{method: string, pattern: string, access: RouteAccess}>
     */
    public function manifest(): array
    {
        return array_map(
            static fn (array $route): array => [
                'method' => $route['method'],
                'pattern' => $route['pattern'],
                'access' => $route['access'],
            ],
            $this->routes,
        );
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $request->method) {
                $allowed[$route['method']] = true;
                continue;
            }

            /** @var array<string, string> $params */
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }

            $this->enforce($route['access']);

            return ($route['handler'])($request, $params);
        }

        if ($allowed !== []) {
            $methods = array_keys($allowed);
            sort($methods);
            if (in_array('GET', $methods, true) && !in_array('HEAD', $methods, true)) {
                $methods[] = 'HEAD';
            }

            throw HttpException::methodNotAllowed(implode(', ', $methods));
        }

        throw HttpException::notFound();
    }

    private function enforce(RouteAccess $access): void
    {
        if ($access->isPublic) {
            return;
        }

        if ($this->authorize === null) {
            throw new LogicException(
                'A route guarded by ' . $access->describe() . ' has no authoriser configured.'
            );
        }

        // The authoriser receives the whole declaration: "signed in" and "holds
        // this permission" are different questions, and only the Gate knows how to
        // answer the first without pretending it is a permission nobody may lack.
        ($this->authorize)($access);
    }

    private function compile(string $pattern): string
    {
        $pattern = Request::normalizePath($pattern);
        $quoted = preg_quote($pattern, '#');
        $quoted = str_replace(['\{', '\}'], ['{', '}'], $quoted);

        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $quoted);

        return '#^' . $regex . '$#';
    }
}

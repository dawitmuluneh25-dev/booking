<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

/**
 * Small explicit router. Patterns use `{name}` placeholders matching a single
 * path segment; parameters are passed to the handler as decoded strings.
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: Closure}> */
    private array $routes = [];

    /** @param Closure(Request, array<string, string>): Response $handler */
    public function add(string $method, string $pattern, Closure $handler): self
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $this->compile($pattern),
            'handler' => $handler,
        ];

        return $this;
    }

    /** @param Closure(Request, array<string, string>): Response $handler */
    public function get(string $pattern, Closure $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    /** @param Closure(Request, array<string, string>): Response $handler */
    public function post(string $pattern, Closure $handler): self
    {
        return $this->add('POST', $pattern, $handler);
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

    private function compile(string $pattern): string
    {
        $pattern = Request::normalizePath($pattern);
        $quoted = preg_quote($pattern, '#');
        $quoted = str_replace(['\{', '\}'], ['{', '}'], $quoted);

        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $quoted);

        return '#^' . $regex . '$#';
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every POST handler validates the token, checked against the route table itself.
 *
 * A functional test would need a session per route and would still only cover the
 * routes somebody remembered to add. This one reads the route table and follows each
 * POST route to the controller method that serves it, so a new state-changing action
 * fails the build until it validates the token. That is the difference between a
 * policy and a habit.
 *
 * It understands one level of indirection: a method that delegates to a private
 * helper which validates is accepted, because `AdminPhysicianController::decide()`
 * serving three routes is a good pattern, not a loophole. Two levels would not be.
 */
final class CsrfCoverageTest extends TestCase
{
    public function testEveryPostRouteReachesAValidatedMethod(): void
    {
        $routes = $this->postRoutes();

        // Self-consistent rather than hardcoded: if the parser stops understanding
        // the route file, this fails instead of the test passing over nothing.
        self::assertSame(
            substr_count((string) file_get_contents($this->root() . '/routes/web.php'), '$router->post('),
            count($routes),
            'The route table did not fully parse; this test would prove less than it claims.',
        );

        $checked = [];

        foreach ($routes as $route) {
            $file = $this->controllerFile($route['controller']);
            $source = $this->source($file);
            $methods = $this->methodBodies($source);
            $validated = array_keys(array_filter($methods, [$this, 'validates']));

            $reachable = $route['action'];

            if (!in_array($reachable, $validated, true)) {
                // Depth one: the action may delegate to a helper that validates.
                $body = $methods[$reachable] ?? '';

                foreach ($validated as $helper) {
                    if (str_contains($body, '$this->' . $helper . '(')) {
                        $reachable = $helper;
                        break;
                    }
                }
            }

            $label = 'POST ' . $route['path'];

            self::assertContains(
                $reachable,
                $validated,
                implode(' ', [
                    $label,
                    'is served by ' . $route['controller'] . '::' . $route['action'] . '(),',
                    'which never validates the CSRF token.',
                ]),
            );

            $checked[] = $label;
        }

        self::assertCount(count($routes), $checked);
    }

    /**
     * POST routes, with the controller and method each one calls.
     *
     * The route file is the source of truth; parsing it is what makes this test
     * follow new routes without anybody updating a list here.
     *
     * @return list<array{path: string, controller: string, action: string}>
     */
    private function postRoutes(): array
    {
        $source = $this->source($this->root() . '/routes/web.php');

        // $name = new SomeController(  — the variable to class mapping.
        preg_match_all(
            '/\$(\w+)\s*=\s*(?:static\s+fn\s*\([^)]*\)\s*:\s*\w+\s*=>\s*)?new\s+(\w+Controller)\s*\(/',
            $source,
            $bindings,
            PREG_SET_ORDER,
        );

        $classes = [];

        foreach ($bindings as $binding) {
            $classes[$binding[1]] = $binding[2];
        }

        // $router->post( '<path>', ... => $name->action(  or  $name()->action(
        preg_match_all(
            "/\\\$router->post\(\s*'([^']+)',\s*static\s+fn\s*\([^)]*\)\s*=>\s*\\\$(\w+)(?:\(\))?->(\w+)\(/",
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $routes = [];

        foreach ($matches as $match) {
            self::assertArrayHasKey(
                $match[2],
                $classes,
                sprintf('Could not resolve the controller behind POST %s.', $match[1]),
            );

            $routes[] = [
                'path' => $match[1],
                'controller' => $classes[$match[2]],
                'action' => $match[3],
            ];
        }

        return $routes;
    }

    private function validates(string $body): bool
    {
        return str_contains($body, 'assertCsrf(')
            || str_contains($body, 'Csrf::validate(')
            || str_contains($body, 'Csrf::check(');
    }

    /**
     * Method name to body, by brace matching from each signature.
     *
     * @return array<string, string>
     */
    private function methodBodies(string $source): array
    {
        preg_match_all('/function\s+(\w+)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE);

        $bodies = [];

        foreach ($matches[1] as $match) {
            [$name, $offset] = $match;

            $open = strpos($source, '{', $offset);

            if ($open === false) {
                continue;
            }

            $depth = 0;
            $length = strlen($source);

            for ($cursor = $open; $cursor < $length; $cursor++) {
                if ($source[$cursor] === '{') {
                    $depth++;
                } elseif ($source[$cursor] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $bodies[$name] = substr($source, $open, $cursor - $open + 1);

                        break;
                    }
                }
            }
        }

        return $bodies;
    }

    private function controllerFile(string $class): string
    {
        $path = $this->root() . '/src/Controllers/' . $class . '.php';

        self::assertFileExists($path, sprintf('%s does not exist.', $class));

        return $path;
    }

    private function source(string $path): string
    {
        $source = file_get_contents($path);

        self::assertIsString($source, sprintf('%s could not be read.', $path));

        return $source;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}

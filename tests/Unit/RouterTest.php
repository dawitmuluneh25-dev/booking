<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testItDispatchesAStaticRoute(): void
    {
        $router = (new Router())->get('/', static fn (): Response => Response::html('home'));

        $response = $router->dispatch(Request::create('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertSame('home', $response->body());
    }

    public function testItPassesDecodedRouteParameters(): void
    {
        $router = (new Router())->get(
            '/bookings/{reference}',
            static fn (Request $request, array $params): Response => Response::text($params['reference'])
        );

        $response = $router->dispatch(Request::create('GET', '/bookings/BK-260101-ABC234'));

        self::assertSame('BK-260101-ABC234', $response->body());
    }

    public function testItNormalisesTrailingSlashes(): void
    {
        $router = (new Router())->get('/bookings', static fn (): Response => Response::text('list'));

        self::assertSame('list', $router->dispatch(Request::create('GET', '/bookings/'))->body());
    }

    public function testUnknownRouteThrowsNotFound(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);

        (new Router())->dispatch(Request::create('GET', '/nope'));
    }

    public function testWrongMethodReturnsAllowHeader(): void
    {
        $router = (new Router())->post('/bookings', static fn (): Response => Response::text('created'));

        try {
            $router->dispatch(Request::create('GET', '/bookings'));
            self::fail('Expected an HttpException.');
        } catch (HttpException $exception) {
            self::assertSame(405, $exception->status);
            self::assertSame('POST', $exception->headers['Allow']);
        }
    }
}

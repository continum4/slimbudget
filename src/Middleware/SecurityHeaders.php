<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

final class SecurityHeaders implements MiddlewareInterface
{
    public function __construct(private bool $hsts)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request)
            ->withHeader('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'")
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cache-Control', 'no-store');
        return $this->hsts ? $response->withHeader('Strict-Transport-Security', 'max-age=31536000') : $response;
    }
}

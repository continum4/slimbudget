<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Exception\HttpForbiddenException;

final class RequireAdmin implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        if ($request->getAttribute('user')['role'] !== 'admin') {
            throw new HttpForbiddenException($request);
        }
        return $handler->handle($request);
    }
}

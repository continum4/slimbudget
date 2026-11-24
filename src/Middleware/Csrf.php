<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Exception\HttpBadRequestException;

final class Csrf implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $token = ((array) $request->getParsedBody())['_csrf'] ?? ($_POST['_csrf'] ?? '');
            if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
                throw new HttpBadRequestException($request);
            }
        }
        return $handler->handle($request);
    }
}

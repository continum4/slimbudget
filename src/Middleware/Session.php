<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

final class Session implements MiddlewareInterface
{
    private const IDLE = 8 * 3600;

    public function __construct(private string $path, private bool $https)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        session_save_path($this->path);
        session_name('slimbudget');
        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => $this->https,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
            'gc_maxlifetime' => self::IDLE,
        ]);
        if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > self::IDLE) {
            session_unset();
            session_regenerate_id(true);
        }
        $_SESSION['seen'] = time();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        // What the user typed in a rejected form lives for a single request
        $_SESSION['old_now'] = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
        return $handler->handle($request);
    }
}

<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Database;
use App\Services\Month;
use App\Services\Settings;
use App\Services\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as PsrResponse;
use Slim\Views\Twig;

/** Requires a session. Reads the user on every request, so a deactivation or role change applies at once. */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(private Database $db, private Twig $twig, private Translator $translator, private Settings $settings)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $user = isset($_SESSION['user_id']) ? $this->db->row('SELECT * FROM users WHERE id=? AND active=1', [$_SESSION['user_id']]) : null;
        if (!$user) {
            unset($_SESSION['user_id']);
            return new PsrResponse(302)->withHeader('Location', '/login');
        }
        $this->translator->use($user['locale'] ?: $this->settings->get('locale'));
        $path = $request->getUri()->getPath();
        if ($user['must_change_password'] && !in_array($path, ['/profile', '/profile/password', '/logout'], true)) {
            return new PsrResponse(302)->withHeader('Location', '/profile');
        }
        $env = $this->twig->getEnvironment();
        $env->addGlobal('me', $user);
        $env->addGlobal('path', $path);
        $env->addGlobal('current_ym', Month::current());
        $env->addGlobal('review_count', (int) $this->db->value('SELECT COUNT(*) FROM expenses WHERE needs_review=1'));
        $env->addGlobal('notices', $_SESSION['notices'] ?? []);
        unset($_SESSION['notices']);
        return $handler->handle($request->withAttribute('user', $user));
    }
}

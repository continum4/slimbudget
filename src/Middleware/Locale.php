<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\Settings;
use App\Services\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/** Language before signing in: the one picked on the login page, the last one used, or the default. Authenticate decides afterwards. */
final class Locale implements MiddlewareInterface
{
    public function __construct(private Translator $translator, private Settings $settings)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $this->translator->use($_SESSION['picked_locale'] ?? $_SESSION['last_locale'] ?? $this->settings->get('locale'));
        return $handler->handle($request);
    }
}

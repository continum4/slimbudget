<?php
declare(strict_types=1);

use App\Config;
use App\Database;
use App\Logger;
use App\Middleware\Csrf;
use App\Middleware\Locale;
use App\Middleware\SecurityHeaders;
use App\Middleware\Session;
use App\Services\Money;
use App\Services\Month;
use App\Services\Settings;
use App\Services\Translator;
use DI\Container;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Twig\TwigFilter;
use Twig\TwigFunction;

$config = Config::load();
$root = dirname(__DIR__);
$var = $config['var_dir'];

$container = new Container([
    'config' => $config,
    Database::class => static fn (): Database => new Database($config['db']),
    Settings::class => static fn (ContainerInterface $c): Settings => new Settings($c->get(Database::class), ['locale' => $config['locale'], 'currency' => $config['currency']]),
    Translator::class => static fn (): Translator => new Translator("$root/lang", $config['locale']),
    Money::class => static fn (ContainerInterface $c): Money => Money::fromCode($c->get(Settings::class)->get('currency'), "$root/config/currencies.json"),
    Twig::class => static function (ContainerInterface $c) use ($root, $var): Twig {
        $twig = Twig::create("$root/templates", ['cache' => "$var/cache", 'auto_reload' => true, 'strict_variables' => true]);
        $env = $twig->getEnvironment();
        $lang = $c->get(Translator::class);
        // Money needs the database: resolved on first use, so error pages work without it
        $money = static fn (): Money => $c->get(Money::class);
        $env->addFilter(new TwigFilter('money', static fn ($n): string => $money()->format($n)));
        $env->addFilter(new TwigFilter('money_round', static fn ($n): string => $money()->format($n, 0)));
        $env->addFilter(new TwigFilter('amount', static fn ($n): string => $money()->input($n)));
        $env->addFilter(new TwigFilter('month', $lang->month(...)));
        $env->addFilter(new TwigFilter('month_short', $lang->monthShort(...)));
        $env->addFilter(new TwigFilter('day', $lang->day(...)));
        $env->addFilter(new TwigFilter('datetime', $lang->dateTime(...)));
        $env->addFunction(new TwigFunction('t', $lang->t(...)));
        $env->addFunction(new TwigFunction('lang_branch', $lang->branch(...)));
        $env->addFunction(new TwigFunction('locale', $lang->locale(...)));
        $env->addFunction(new TwigFunction('languages', $lang->available(...)));
        $env->addFunction(new TwigFunction('amount_pattern', static fn (): string => $money()->pattern()));
        $env->addFunction(new TwigFunction('amount_example', static fn (): string => $money()->example()));
        $env->addFunction(new TwigFunction('csrf', static fn (): string => '<input type="hidden" name="_csrf" value="' . htmlspecialchars($_SESSION['csrf'] ?? '') . '">', ['is_safe' => ['html']]));
        $env->addFunction(new TwigFunction('old', static fn (string $key, mixed $default = ''): mixed => $_SESSION['old_now'][$key] ?? $default));
        $env->addFunction(new TwigFunction('month_add', Month::add(...)));
        $env->addFunction(new TwigFunction('asset', static fn (string $file): string => "/assets/$file?v=" . @filemtime("$root/public/assets/$file")));
        return $twig;
    },
]);

$app = AppFactory::createFromContainer($container);
(require __DIR__ . '/routes.php')($app);

// Last added runs first: headers → errors → session → locale → csrf → routing
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(Csrf::class);
$app->add(Locale::class);
$app->add(new Session("$var/sessions", str_starts_with($config['url'], 'https')));
$errors = $app->addErrorMiddleware($config['env'] === 'dev', false, false);
$errors->setDefaultErrorHandler(function (Request $request, Throwable $e) use ($app, $container, $config, $var) {
    $code = $e instanceof HttpException ? $e->getCode() : 500;
    if ($code === 500) {
        Logger::write("$var/log/app.log", sprintf("%s %s\n%s", $request->getMethod(), $request->getUri()->getPath(), $e));
    }
    $lang = $container->get(Translator::class);
    $message = match ($code) {
        400, 403, 404, 405 => $lang->t("errors.$code"),
        default => $config['env'] === 'dev' ? $e->getMessage() : $lang->t('errors.500'),
    };
    return $container->get(Twig::class)->render($app->getResponseFactory()->createResponse($code), 'error.twig', ['code' => $code, 'message' => $message]);
});
$app->add(new SecurityHeaders());

return $app;

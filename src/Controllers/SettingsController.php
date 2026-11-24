<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Services\Budget;
use App\Services\Input;
use App\Services\Money;
use App\Services\Settings;
use App\Services\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class SettingsController extends Controller
{
    private const CURRENCIES = __DIR__ . '/../../config/currencies.json';

    public function __construct(Database $db, Twig $twig, Budget $budget, Translator $translator, Money $money, private Settings $settings)
    {
        parent::__construct($db, $twig, $budget, $translator, $money);
    }

    public function form(Request $request, Response $response): Response
    {
        $currencies = [];
        foreach (Money::currencies(self::CURRENCIES) as $code => $currency) {
            $currencies[$code] = $currency['name'] . ' — ' . new Money($currency)->format(1234.5);
        }
        return $this->render($response, 'settings.twig', ['currencies' => $currencies, 'currency' => $this->settings->get('currency'), 'default_locale' => $this->settings->get('locale')]);
    }

    public function save(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        return $this->attempt($request, $response, '/settings', function (Input $in) use ($user): string {
            $currency = $in->option('currency', $this->t('settings.currency'), array_keys(Money::currencies(self::CURRENCIES)));
            $locale = $in->option('locale', $this->t('settings.language'), array_map('strval', array_keys($this->translator->available())));
            $this->settings->set('currency', $currency);
            $this->settings->set('locale', $locale);
            $this->translator->use($user['locale'] ?: $locale);
            $this->notice('good', 'settings.saved');
            return '/settings';
        });
    }
}

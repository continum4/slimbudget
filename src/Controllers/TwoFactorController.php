<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Services\Budget;
use App\Services\Gate;
use App\Services\Money;
use App\Services\Translator;
use App\Services\TwoFactor;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/** Two-step verification of the signed-in user. Every change asks for the password again. */
final class TwoFactorController extends Controller
{
    private const URL = '/profile/two-step';

    public function __construct(Database $db, Twig $twig, Budget $budget, Translator $translator, Money $money, private Gate $gate, private TwoFactor $twoFactor)
    {
        parent::__construct($db, $twig, $budget, $translator, $money);
    }

    public function show(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $enabled = $user['totp_secret'] !== null;
        $data = ['enabled' => $enabled, 'available' => $this->twoFactor->available(), 'codes' => $_SESSION['recovery_codes'] ?? [], 'left' => $this->twoFactor->recoveryCodesLeft((int) $user['id'])];
        unset($_SESSION['recovery_codes']); // shown only once
        if (!$enabled && $data['available']) {
            $secret = $_SESSION['totp_setup'] ??= $this->twoFactor->newSecret();
            $uri = $this->twoFactor->uri($secret, $user['username'], $this->t('app.name'));
            $data += ['uri' => $uri, 'qr' => $this->twoFactor->qr($uri), 'secret' => implode(' ', str_split($secret, 4))];
        }
        return $this->render($response, 'two_step.twig', $data);
    }

    public function enable(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $secret = $_SESSION['totp_setup'] ?? null;
        if ($user['totp_secret'] !== null || $secret === null || !$this->twoFactor->available()) {
            return $this->redirect($response, self::URL);
        }
        $data = (array) $request->getParsedBody();
        if ($error = $this->gate->confirmPassword($request, $user, $data['current_password'] ?? null)) {
            return $this->deny($response, $error, self::URL);
        }
        $step = $this->twoFactor->step($secret, preg_replace('/\s+/', '', $this->input($data)->raw('code')));
        if ($step === null) {
            return $this->deny($response, 'two_step.bad_code', self::URL);
        }
        $this->db->transaction(function () use ($user, $secret, $step): void {
            $this->twoFactor->enable((int) $user['id'], $secret, $step);
            $_SESSION['recovery_codes'] = $this->twoFactor->newRecoveryCodes((int) $user['id']);
        });
        unset($_SESSION['totp_setup']);
        return $this->changed($request, $response, $user, 'two_step_on', 'two_step.turned_on');
    }

    public function codes(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($error = $this->confirm($request, $user)) {
            return $this->deny($response, $error, self::URL);
        }
        $_SESSION['recovery_codes'] = $this->db->transaction(fn (): array => $this->twoFactor->newRecoveryCodes((int) $user['id']));
        return $this->changed($request, $response, $user, 'recovery_codes_new', 'two_step.codes_renewed');
    }

    public function disable(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($error = $this->confirm($request, $user)) {
            return $this->deny($response, $error, self::URL);
        }
        $this->twoFactor->disable((int) $user['id']);
        return $this->changed($request, $response, $user, 'two_step_off', 'two_step.turned_off');
    }

    private function confirm(Request $request, array $user): ?string
    {
        return $user['totp_secret'] === null ? 'errors.400' : $this->gate->confirmPassword($request, $user, ((array) $request->getParsedBody())['current_password'] ?? null);
    }

    private function changed(Request $request, Response $response, array $user, string $action, string $notice): Response
    {
        $this->gate->keepThisSession((int) $user['id']);
        $this->gate->record($request, (int) $user['id'], $action);
        $this->notice('good', $notice);
        return $this->redirect($response, self::URL);
    }
}

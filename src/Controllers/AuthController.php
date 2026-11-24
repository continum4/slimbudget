<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Services\Budget;
use App\Services\Gate;
use App\Services\Input;
use App\Services\Money;
use App\Services\Password;
use App\Services\Settings;
use App\Services\Throttle;
use App\Services\Translator;
use App\Services\TwoFactor;
use App\Services\ValidationError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AuthController extends Controller
{
    private const PENDING_SECONDS = 300;
    /** Hash of a random string: keeps the response time equal when the user does not exist. */
    private const DUMMY_HASH = '$2y$12$HWYSioUvUfgzhheGh0tf4u88cOwWLXYBBitFmOt4otzUUJtxFanYG';

    public function __construct(Database $db, Twig $twig, Budget $budget, Translator $translator, Money $money, private Settings $settings, private Gate $gate, private TwoFactor $twoFactor)
    {
        parent::__construct($db, $twig, $budget, $translator, $money);
    }

    public function form(Request $request, Response $response): Response
    {
        if (isset($_SESSION['user_id'])) {
            return $this->redirect($response, '/');
        }
        $error = $_SESSION['login_error'] ?? null;
        unset($_SESSION['login_error']);
        return $this->render($response, 'login.twig', ['error' => $error]);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $username = mb_substr(mb_strtolower(trim(is_string($data['username'] ?? null) ? $data['username'] : '')), 0, 40);
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $user = $this->db->row('SELECT * FROM users WHERE username=? AND active=1', [$username]);
        $attempt = $this->gate->signIn($request, $username, $user);
        if ($attempt === null) {
            return $this->reject($response, $username, $this->tooMany());
        }
        $valid = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);
        if (!$user || !$valid) {
            $this->gate->failed($request, 'login', $username);
            return $this->reject($response, $username, $this->t('login.invalid'));
        }
        $this->gate->forgive($attempt);
        if ($user['must_change_password'] && $user['password_expires_at'] !== null && strtotime($user['password_expires_at']) < time()) {
            return $this->reject($response, $username, $this->t('login.expired'));
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->update('users', (int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        if ($user['totp_secret'] !== null) {
            session_regenerate_id(true);
            $_SESSION['pending'] = ['user_id' => (int) $user['id'], 'version' => (int) $user['session_version'], 'at' => time()];
            return $this->redirect($response, '/login/code');
        }
        return $this->signIn($request, $response, $user);
    }

    private function tooMany(): string
    {
        return $this->t('login.too_many', ['minutes' => Throttle::WINDOW_MINUTES]);
    }

    /** User whose password was right a moment ago and still owes the two-step code. */
    private function pendingUser(): ?array
    {
        $pending = $_SESSION['pending'] ?? null;
        $fresh = $pending && time() - $pending['at'] <= self::PENDING_SECONDS;
        $user = $fresh ? $this->db->row('SELECT * FROM users WHERE id=? AND active=1 AND totp_secret IS NOT NULL AND session_version=?', [$pending['user_id'], $pending['version']]) : null;
        if ($user) {
            $this->translator->use($_SESSION['picked_locale'] ?? $user['locale'] ?? $this->settings->get('locale'));
        }
        return $user;
    }

    public function codeForm(Request $request, Response $response): Response
    {
        if (!$this->pendingUser()) {
            unset($_SESSION['pending']);
            return $this->redirect($response, '/login');
        }
        $error = $_SESSION['login_error'] ?? null;
        unset($_SESSION['login_error']);
        return $this->render($response, 'login_code.twig', ['error' => $error]);
    }

    public function code(Request $request, Response $response): Response
    {
        $user = $this->pendingUser();
        if (!$user) {
            unset($_SESSION['pending']);
            return $this->redirect($response, '/login');
        }
        $attempt = $this->gate->sensitive($request, 'code', $user);
        if ($attempt === null) {
            unset($_SESSION['pending']);
            return $this->reject($response, $user['username'], $this->tooMany());
        }
        $left = $this->twoFactor->recoveryCodesLeft((int) $user['id']);
        if (!$this->twoFactor->verify($user, $this->input((array) $request->getParsedBody())->raw('code'))) {
            $this->gate->failed($request, 'code', $user['username']);
            $_SESSION['login_error'] = $this->t('login.bad_code');
            return $this->redirect($response, '/login/code');
        }
        $this->gate->forgive($attempt);
        $response = $this->signIn($request, $response, $user);
        $now = $this->twoFactor->recoveryCodesLeft((int) $user['id']);
        if ($now < $left) {
            $this->gate->record($request, (int) $user['id'], 'recovery_code_used');
            $this->notice('info', 'two_step.recovery_used', ['count' => $now]);
        }
        return $response;
    }

    private function signIn(Request $request, Response $response, array $user): Response
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['version'] = (int) $user['session_version'];
        $_SESSION['started'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $this->gate->signedIn($request, $user);
        if (isset($_SESSION['picked_locale'])) {
            // Picking a language before signing in makes it the user's own
            $this->db->update('users', (int) $user['id'], ['locale' => $_SESSION['picked_locale']]);
        }
        unset($_SESSION['pending'], $_SESSION['picked_locale'], $_SESSION['last_locale']);
        return $this->redirect($response, '/');
    }

    private function reject(Response $response, string $username, string $message): Response
    {
        $_SESSION['login_error'] = $message;
        $_SESSION['old'] = ['username' => $username];
        return $this->redirect($response, '/login');
    }

    public function logout(Request $request, Response $response): Response
    {
        $locale = $this->translator->locale();
        $_SESSION = ['last_locale' => $locale];
        session_regenerate_id(true);
        return $this->redirect($response, '/login');
    }

    /** Language picker of the login page. */
    public function language(Request $request, Response $response): Response
    {
        $locale = $this->input((array) $request->getParsedBody())->raw('locale');
        if ($this->translator->exists($locale)) {
            $_SESSION['picked_locale'] = $locale;
        }
        return $this->redirect($response, '/login');
    }

    public function profile(Request $request, Response $response): Response
    {
        return $this->render($response, 'profile.twig', ['min' => Password::MIN, 'default_locale' => $this->settings->get('locale')]);
    }

    public function changeLanguage(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        return $this->attempt($request, $response, '/profile', function (Input $in) use ($user): string {
            $locale = $in->raw('locale');
            if ($locale !== '' && !$this->translator->exists($locale)) {
                throw new ValidationError($this->t('validation.option', ['field' => $this->t('profile.language')]));
            }
            $this->db->update('users', (int) $user['id'], ['locale' => $locale ?: null]);
            $this->translator->use($locale ?: $this->settings->get('locale'));
            $this->notice('good', 'profile.language_saved');
            return '/profile';
        });
    }

    public function changePassword(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if ($error = $this->gate->confirmPassword($request, $user, $data['current_password'] ?? null)) {
            return $this->deny($response, $error, '/profile');
        }
        return $this->attempt($request, $response, '/profile', function (Input $in, array $data) use ($request, $user): string {
            $new = is_string($data['password'] ?? null) ? $data['password'] : '';
            if ($problem = Password::problem($new, $data['current_password'], $user['username'])) {
                throw new ValidationError($this->t("profile.$problem", $problem === 'short_password' ? ['min' => Password::MIN] : []));
            }
            if ($new !== ($data['password_confirm'] ?? null)) {
                throw new ValidationError($this->t('profile.mismatch'));
            }
            $this->db->update('users', (int) $user['id'], ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0, 'password_expires_at' => null]);
            $this->gate->closeOtherSessions((int) $user['id']);
            $this->gate->record($request, (int) $user['id'], 'password_changed');
            $this->notice('good', 'profile.password_saved');
            return '/';
        });
    }

    public function closeSessions(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('user')['id'];
        $this->gate->closeOtherSessions($id);
        $this->gate->record($request, $id, 'sessions_closed');
        $this->notice('good', 'profile.sessions_closed');
        return $this->redirect($response, '/profile');
    }
}

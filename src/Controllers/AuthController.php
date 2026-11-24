<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Services\Budget;
use App\Services\Input;
use App\Services\Money;
use App\Services\Settings;
use App\Services\Translator;
use App\Services\ValidationError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;
    private const MIN_PASSWORD = 10;
    /** Hash of a random string: keeps the response time equal when the user does not exist. */
    private const DUMMY_HASH = '$2y$12$HWYSioUvUfgzhheGh0tf4u88cOwWLXYBBitFmOt4otzUUJtxFanYG';

    public function __construct(Database $db, Twig $twig, Budget $budget, Translator $translator, Money $money, private Settings $settings)
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
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $this->db->run('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL ? MINUTE', [self::WINDOW_MINUTES]);
        $attempts = (int) $this->db->value('SELECT COUNT(*) FROM login_attempts WHERE username=? AND ip=?', [$username, $ip]);
        if ($attempts >= self::MAX_ATTEMPTS) {
            return $this->reject($response, $username, $this->t('login.too_many', ['minutes' => self::WINDOW_MINUTES]));
        }
        $user = $this->db->row('SELECT * FROM users WHERE username=? AND active=1', [$username]);
        $valid = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);
        if (!$user || !$valid) {
            $this->db->insert('login_attempts', ['username' => $username, 'ip' => $ip]);
            return $this->reject($response, $username, $this->t('login.invalid'));
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $this->db->run('DELETE FROM login_attempts WHERE username=?', [$username]);
        $this->db->run('UPDATE users SET last_login_at=NOW() WHERE id=?', [$user['id']]);
        if (isset($_SESSION['picked_locale'])) {
            // Picking a language before signing in makes it the user's own
            $this->db->update('users', (int) $user['id'], ['locale' => $_SESSION['picked_locale']]);
        }
        unset($_SESSION['picked_locale'], $_SESSION['last_locale']);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->update('users', (int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
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
        return $this->render($response, 'profile.twig', ['min' => self::MIN_PASSWORD, 'default_locale' => $this->settings->get('locale')]);
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
        return $this->attempt($request, $response, '/profile', function (Input $in, array $data) use ($user): string {
            $current = is_string($data['current_password'] ?? null) ? $data['current_password'] : '';
            $new = is_string($data['password'] ?? null) ? $data['password'] : '';
            if (!password_verify($current, $user['password_hash'])) {
                throw new ValidationError($this->t('profile.wrong_password'));
            }
            if (mb_strlen($new) < self::MIN_PASSWORD) {
                throw new ValidationError($this->t('profile.short_password', ['min' => self::MIN_PASSWORD]));
            }
            if ($new !== ($data['password_confirm'] ?? null)) {
                throw new ValidationError($this->t('profile.mismatch'));
            }
            $this->db->update('users', (int) $user['id'], ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0]);
            session_regenerate_id(true);
            $this->notice('good', 'profile.password_saved');
            return '/';
        });
    }
}

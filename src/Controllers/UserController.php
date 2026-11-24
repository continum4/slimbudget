<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Services\Audit;
use App\Services\Budget;
use App\Services\Gate;
use App\Services\Input;
use App\Services\Money;
use App\Services\Password;
use App\Services\Translator;
use App\Services\TwoFactor;
use App\Services\ValidationError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class UserController extends Controller
{
    private const ROLES = ['admin', 'member'];
    private const ACTIVITY = 30;

    public function __construct(Database $db, Twig $twig, Budget $budget, Translator $translator, Money $money, private Gate $gate, private TwoFactor $twoFactor)
    {
        parent::__construct($db, $twig, $budget, $translator, $money);
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->render($response, 'users.twig', [
            'users' => $this->db->all('SELECT u.*, p.name person FROM users u LEFT JOIN people p ON p.id=u.person_id ORDER BY u.active DESC, u.name'),
            'people' => $this->options('people'), 'roles' => self::ROLES, 'activity' => (new Audit($this->db))->latest(self::ACTIVITY),
        ]);
    }

    private function fields(Input $in): array
    {
        $username = mb_strtolower($in->text('username', $this->t('users.username'), 40));
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
            throw new ValidationError($this->t('users.bad_username'));
        }
        return [
            'username' => $username, 'name' => $in->text('name', $this->t('fields.name'), 80),
            'role' => $in->option('role', $this->t('users.role'), self::ROLES), 'person_id' => $in->id('person_id', $this->t('fields.person'), false),
        ];
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->attempt($request, $response, '/users', function (Input $in) use ($request): string {
            $password = Password::temporary();
            $fields = $this->fields($in);
            $this->db->insert('users', $fields + Password::temporaryFields($password));
            $this->gate->record($request, (int) $request->getAttribute('user')['id'], 'user_created', $fields['username']);
            $this->notice('good', 'users.created', ['username' => $fields['username'], 'password' => $password]);
            return '/users';
        });
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $user = $this->find($request, 'users', $args['id']);
        return $this->render($response, 'user.twig', ['user' => $user, 'people' => $this->options('people', (int) $user['person_id']), 'roles' => self::ROLES]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $user = $this->find($request, 'users', $args['id']);
        $me = $request->getAttribute('user');
        return $this->attempt($request, $response, "/users/{$user['id']}", function (Input $in) use ($request, $user, $me): string {
            $fields = $this->fields($in) + ['active' => $in->checkbox('active')];
            if ((int) $user['id'] === (int) $me['id'] && (!$fields['active'] || $fields['role'] !== 'admin')) {
                throw new ValidationError($this->t('users.self_lockout'));
            }
            // On their own account it goes through the profile, which asks for the password
            $other = (int) $user['id'] !== (int) $me['id'];
            $reset = $other && $in->checkbox('reset_password');
            $password = Password::temporary();
            if ($reset) {
                $fields += Password::temporaryFields($password);
            }
            $this->db->update('users', (int) $user['id'], $fields);
            $changes = array_keys(array_filter(['password' => $reset, 'two_step_off' => $other && $in->checkbox('two_step_off') && $user['totp_secret'] !== null, 'role' => $fields['role'] !== $user['role'], 'active' => $fields['active'] !== (int) $user['active']]));
            if (in_array('two_step_off', $changes, true)) {
                $this->twoFactor->disable((int) $user['id']);
            }
            if ($changes) {
                // Whatever was open under the old password, role or status closes
                $this->db->run('UPDATE users SET session_version=session_version+1 WHERE id=?', [$user['id']]);
            }
            $this->gate->record($request, (int) $me['id'], 'user_updated', trim($fields['username'] . ' · ' . implode(', ', array_map(fn (string $change): string => $this->t("users.changes.$change"), $changes)), ' ·'));
            $this->notice('good', $reset ? 'users.updated_password' : 'users.updated', ['password' => $password]);
            return '/users';
        });
    }
}

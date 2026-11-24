<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Input;
use App\Services\ValidationError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UserController extends Controller
{
    private const ROLES = ['admin', 'member'];

    public function index(Request $request, Response $response): Response
    {
        return $this->render($response, 'users.twig', [
            'users' => $this->db->all('SELECT u.*, p.name person FROM users u LEFT JOIN people p ON p.id=u.person_id ORDER BY u.active DESC, u.name'),
            'people' => $this->options('people'), 'roles' => self::ROLES,
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

    public static function temporaryPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'xy'), '=');
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->attempt($request, $response, '/users', function (Input $in): string {
            $password = self::temporaryPassword();
            $fields = $this->fields($in);
            $this->db->insert('users', $fields + ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
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
        return $this->attempt($request, $response, "/users/{$user['id']}", function (Input $in) use ($user, $me): string {
            $fields = $this->fields($in) + ['active' => $in->checkbox('active')];
            if ((int) $user['id'] === (int) $me['id'] && (!$fields['active'] || $fields['role'] !== 'admin')) {
                throw new ValidationError($this->t('users.self_lockout'));
            }
            $reset = $in->checkbox('reset_password');
            $password = self::temporaryPassword();
            if ($reset) {
                $fields += ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change_password' => 1];
            }
            $this->db->update('users', (int) $user['id'], $fields);
            $this->notice('good', $reset ? 'users.updated_password' : 'users.updated', ['password' => $password]);
            return '/users';
        });
    }
}

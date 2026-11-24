<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Services\Budget;
use App\Services\Input;
use App\Services\Money;
use App\Services\Translator;
use App\Services\ValidationError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

abstract class Controller
{
    public function __construct(
        protected Database $db,
        protected Twig $twig,
        protected Budget $budget,
        protected Translator $translator,
        protected Money $money,
    ) {
    }

    protected function render(Response $response, string $template, array $data = []): Response
    {
        return $this->twig->render($response, $template, $data);
    }

    protected function redirect(Response $response, string $url): Response
    {
        return $response->withStatus(303)->withHeader('Location', $url);
    }

    protected function t(string $key, array $params = []): string
    {
        return $this->translator->t($key, $params);
    }

    protected function notice(string $level, string $key, array $params = []): void
    {
        $_SESSION['notices'][] = ['level' => $level, 'text' => $this->t($key, $params)];
    }

    protected function input(array $data): Input
    {
        return new Input($data, $this->translator, $this->money);
    }

    /** Runs a POST in a transaction; if validation or the database rejects it, goes back to $errorUrl keeping what was typed. */
    protected function attempt(Request $request, Response $response, string $errorUrl, callable $fn): Response
    {
        $data = (array) $request->getParsedBody();
        try {
            return $this->redirect($response, $this->db->transaction(fn () => $fn($this->input($data), $data)));
        } catch (ValidationError $e) {
            $_SESSION['notices'][] = ['level' => 'error', 'text' => $e->getMessage()];
        } catch (\PDOException $e) {
            $key = match ((int) ($e->errorInfo[1] ?? 0)) {
                1062 => 'errors.duplicate',
                1451 => 'errors.in_use',
                1452 => 'errors.missing_option',
                default => throw $e,
            };
            $this->notice('error', $key);
        }
        unset($data['_csrf'], $data['password'], $data['password_confirm'], $data['current_password']);
        $_SESSION['old'] = $data;
        return $this->redirect($response, $errorUrl);
    }

    protected function find(Request $request, string $table, string $id): array
    {
        return $this->db->row("SELECT * FROM $table WHERE id=?", [(int) $id]) ?? throw new HttpNotFoundException($request);
    }

    /** Active categories by group (plus $include, even if inactive). */
    protected function categories(int $include = 0): array
    {
        $groups = [];
        $rows = $this->db->all('SELECT c.id, c.name, g.name group_name FROM categories c JOIN category_groups g ON g.id=c.group_id WHERE (c.active=1 AND g.active=1) OR c.id=? ORDER BY g.name, c.name', [$include]);
        foreach ($rows as $row) {
            $groups[$row['group_name']][] = $row;
        }
        return $groups;
    }

    protected function options(string $table, int $include = 0): array
    {
        return $this->db->all("SELECT * FROM $table WHERE active=1 OR id=? ORDER BY name", [$include]);
    }

    protected function merchants(): array
    {
        return array_column($this->db->all('SELECT merchant FROM expenses WHERE merchant IS NOT NULL GROUP BY merchant ORDER BY COUNT(*) DESC LIMIT 60'), 'merchant');
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Input;
use App\Services\Month;
use App\Services\ValidationError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MonthController extends Controller
{
    public const MODES = ['fixed', 'auto', 'installments'];
    private const KINDS = ['salary', 'other', 'savings'];

    public function home(Request $request, Response $response): Response
    {
        return $this->redirect($response, '/months/' . Month::current());
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $bills = $this->budget->bills($args['ym']);
        return $this->render($response, 'month.twig', [
            'ym' => $args['ym'],
            's' => $this->budget->summary($args['ym']),
            'kinds' => self::KINDS,
            'due' => array_values(array_filter($bills, static fn (array $b): bool => in_array($b['status'], ['pending', 'partial', 'overdue'], true))),
        ]);
    }

    public function open(Request $request, Response $response, array $args): Response
    {
        $this->budget->ensureMonth($args['ym']);
        $this->notice('good', 'month.opened', ['month' => $this->translator->month($args['ym'])]);
        return $this->redirect($response, '/months/' . $args['ym']);
    }

    public function addIncome(Request $request, Response $response, array $args): Response
    {
        $url = "/months/{$args['ym']}#incomes";
        return $this->attempt($request, $response, $url, function (Input $in) use ($args, $url): string {
            $this->db->insert('incomes', [
                'kind' => $in->option('kind', $this->t('incomes.kind'), self::KINDS),
                'description' => $in->text('description', $this->t('fields.description'), 120, false),
                'amount' => $in->amount('amount', $this->t('fields.amount')),
                'month_id' => $this->budget->ensureMonth($args['ym']),
            ]);
            $this->notice('good', 'incomes.added');
            return $url;
        });
    }

    public function deleteIncome(Request $request, Response $response, array $args): Response
    {
        $income = $this->find($request, 'incomes', $args['id']);
        $ym = $this->db->value('SELECT ym FROM months WHERE id=?', [$income['month_id']]);
        $this->db->run('DELETE FROM incomes WHERE id=?', [$income['id']]);
        $this->notice('good', 'incomes.deleted');
        return $this->redirect($response, "/months/$ym#incomes");
    }

    public function estimates(Request $request, Response $response, array $args): Response
    {
        $amounts = $this->db->all('SELECT e.category_id, e.amount FROM estimates e JOIN months m ON m.id=e.month_id WHERE m.ym=?', [$args['ym']]);
        return $this->render($response, 'estimates.twig', [
            'ym' => $args['ym'], 's' => $this->budget->summary($args['ym']), 'modes' => self::MODES,
            'amounts' => array_column($amounts, 'amount', 'category_id'),
        ]);
    }

    public function saveEstimates(Request $request, Response $response, array $args): Response
    {
        return $this->attempt($request, $response, "/months/{$args['ym']}/estimates", function (Input $in, array $data) use ($args): string {
            if (!is_array($data['estimates'] ?? null)) {
                throw new ValidationError($this->t('estimates.empty'));
            }
            $monthId = $this->budget->ensureMonth($args['ym']);
            foreach ($data['estimates'] as $categoryId => $fields) {
                $line = $in->nested((array) $fields);
                $mode = $line->option('mode', $this->t('estimates.mode'), self::MODES);
                $amount = $mode === 'fixed' ? $line->amount('amount', $this->t('estimates.fixed_amount'), true) : 0;
                $this->db->run('REPLACE INTO estimates (month_id, category_id, mode, amount) VALUES (?,?,?,?)', [$monthId, (int) $categoryId, $mode, $amount]);
            }
            $this->notice('good', 'estimates.saved');
            return "/months/{$args['ym']}";
        });
    }
}

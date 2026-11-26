<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Input;
use App\Services\Month;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

final class ExpenseController extends Controller
{
    private const FROM = ' FROM expenses x JOIN months m ON m.id=x.month_id JOIN categories c ON c.id=x.category_id JOIN category_groups g ON g.id=c.group_id
        JOIN people p ON p.id=x.person_id LEFT JOIN cards k ON k.id=x.card_id';
    private const SELECT = 'SELECT x.*, m.ym, c.name category, c.group_id, g.name group_name, p.name person, COALESCE(k.alias, k.name) card' . self::FROM;
    private const PER_PAGE = 50;

    /** Text and catalog filters from the query string, as [filters, SQL conditions, parameters]. */
    private function filters(array $query): array
    {
        $filters = ['q' => is_string($query['q'] ?? null) ? trim($query['q']) : ''];
        $where = $params = [];
        foreach (['group' => 'c.group_id', 'category' => 'x.category_id', 'person' => 'x.person_id', 'card' => 'x.card_id'] as $key => $column) {
            $filters[$key] = filter_var($query[$key] ?? '', FILTER_VALIDATE_INT) ?: 0;
            if ($filters[$key]) {
                $where[] = "$column=?";
                $params[] = $filters[$key];
            }
        }
        if ($filters['q'] !== '') {
            $where[] = '(x.description LIKE ? OR x.merchant LIKE ?)';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            array_push($params, $like, $like);
        }
        return [$filters, $where, $params];
    }

    public function index(Request $request, Response $response, array $args): Response
    {
        $ym = $args['ym'];
        [$filters, $where, $params] = $this->filters($request->getQueryParams());
        $sql = ' WHERE ' . implode(' AND ', ['m.ym=?', ...$where]);
        $expenses = $this->db->all(self::SELECT . $sql . ' ORDER BY x.spent_on DESC, x.id DESC', [$ym, ...$params]);
        return $this->render($response, 'expenses.twig', $this->lists() + [
            'ym' => $ym, 'expenses' => $expenses, 'filters' => $filters, 'filtered' => (bool) array_filter($filters),
            'total' => array_sum(array_column($expenses, 'amount')),
            'default_date' => $ym === Month::current() ? date('Y-m-d') : "$ym-01",
            'default_person' => $request->getAttribute('user')['person_id'],
        ]);
    }

    /** Expenses of every month, grouped by month and paged. Without filters it only shows the form. */
    public function search(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        [$filters, $where, $params] = $this->filters($query);
        foreach (['from', 'to'] as $key) {
            $filters[$key] = is_string($query[$key] ?? null) && Month::isValid($query[$key]) ? $query[$key] : '';
        }
        if ($filters['from'] && $filters['to'] && $filters['from'] > $filters['to']) {
            [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
        }
        foreach (['from' => 'm.ym>=?', 'to' => 'm.ym<=?'] as $key => $condition) {
            if ($filters[$key]) {
                $where[] = $condition;
                $params[] = $filters[$key];
            }
        }
        $active = array_filter($filters);
        $data = ['filters' => $filters, 'active' => $active, 'months' => [], 'count' => 0, 'total' => 0.0, 'page' => 1, 'pages' => 0];
        if ($active) {
            $sql = ' WHERE ' . implode(' AND ', $where);
            $months = [];
            foreach ($this->db->all('SELECT m.ym, COUNT(*) n, SUM(x.amount) total' . self::FROM . $sql . ' GROUP BY m.ym ORDER BY m.ym DESC', $params) as $row) {
                $months[$row['ym']] = ['ym' => $row['ym'], 'count' => (int) $row['n'], 'total' => (float) $row['total'], 'expenses' => []];
            }
            $data['count'] = array_sum(array_column($months, 'count'));
            $data['total'] = array_sum(array_column($months, 'total'));
            $data['pages'] = (int) ceil($data['count'] / self::PER_PAGE);
            $data['page'] = max(1, min($data['pages'], filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
            // An expense can sit in another month than its date (a bill paid early): order by month first so groups stay together
            $offset = ($data['page'] - 1) * self::PER_PAGE;
            $rows = $this->db->all(self::SELECT . $sql . ' ORDER BY m.ym DESC, x.spent_on DESC, x.id DESC LIMIT ' . self::PER_PAGE . " OFFSET $offset", $params);
            foreach ($rows as $row) {
                $months[$row['ym']]['expenses'][] = $row;
            }
            $data['months'] = array_values(array_filter($months, static fn (array $m): bool => (bool) $m['expenses']));
        }
        return $this->render($response, 'search.twig', $this->lists() + $data);
    }

    private function lists(int $category = 0, int $person = 0, int $card = 0): array
    {
        return [
            'categories' => $this->categories($category), 'people' => $this->options('people', $person),
            'cards' => $this->options('cards', $card), 'groups' => $this->options('category_groups'), 'merchants' => $this->merchants(),
        ];
    }

    private function fields(Input $in): array
    {
        return [
            'spent_on' => $in->date('spent_on', $this->t('fields.date')),
            'description' => $in->text('description', $this->t('fields.description'), 150),
            'category_id' => $in->id('category_id', $this->t('fields.category')),
            'person_id' => $in->id('person_id', $this->t('fields.person')),
            'amount' => $in->amount('amount', $this->t('fields.amount')),
            'card_id' => $in->id('card_id', $this->t('fields.card'), false),
            'merchant' => $in->text('merchant', $this->t('fields.merchant'), 80, false),
        ];
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        return $this->attempt($request, $response, "/months/{$args['ym']}/expenses", function (Input $in) use ($request): string {
            $fields = $this->fields($in);
            $ym = substr($fields['spent_on'], 0, 7);
            $this->db->insert('expenses', $fields + ['month_id' => $this->openMonth($request, $ym), 'created_by' => $request->getAttribute('user')['id']]);
            $this->notice('good', 'expenses.saved', ['month' => $this->translator->month($ym), 'description' => $fields['description']]);
            return "/months/$ym/expenses";
        });
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $expense = $this->db->row(self::SELECT . ' WHERE x.id=?', [(int) $args['id']]) ?? throw new HttpNotFoundException($request);
        $this->requireOwner($request, $expense);
        return $this->render($response, 'expense.twig', $this->lists((int) $expense['category_id'], (int) $expense['person_id'], (int) $expense['card_id']) + ['ym' => $expense['ym'], 'expense' => $expense]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $expense = $this->find($request, 'expenses', $args['id']);
        $this->requireOwner($request, $expense);
        return $this->attempt($request, $response, "/expenses/{$expense['id']}", function (Input $in) use ($request, $expense): string {
            $fields = $this->fields($in) + ['needs_review' => $in->checkbox('needs_review')];
            // The month follows the date only when it changes: a bill can be paid on another month's date
            $monthId = $fields['spent_on'] === $expense['spent_on'] ? (int) $expense['month_id'] : $this->openMonth($request, substr($fields['spent_on'], 0, 7));
            $this->db->update('expenses', (int) $expense['id'], $fields + ['month_id' => $monthId]);
            $this->notice('good', 'expenses.updated');
            return '/months/' . $this->db->value('SELECT ym FROM months WHERE id=?', [$monthId]) . '/expenses';
        });
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $expense = $this->find($request, 'expenses', $args['id']);
        $this->requireOwner($request, $expense);
        $ym = $this->db->value('SELECT ym FROM months WHERE id=?', [$expense['month_id']]);
        $this->audit($request, 'expense_deleted', "{$expense['spent_on']} · {$expense['description']} · {$expense['amount']}");
        $this->db->run('DELETE FROM expenses WHERE id=?', [$expense['id']]);
        $this->notice('good', 'expenses.deleted', ['description' => $expense['description']]);
        return $this->redirect($response, "/months/$ym/expenses");
    }

    public function review(Request $request, Response $response): Response
    {
        return $this->render($response, 'review.twig', ['expenses' => $this->db->all(self::SELECT . ' WHERE x.needs_review=1 ORDER BY m.ym, x.spent_on, x.id')]);
    }

    /** Statements paid this month and the one building up for the next. */
    private function statements(string $ym): array
    {
        $list = [];
        foreach ([$ym, Month::add($ym, 1)] as $month) {
            $list[$month] = $this->budget->cardStatements($month);
        }
        return $list;
    }

    /** Card purchases of the month: expenses paid by card plus installment plans bought that month. */
    public function cards(Request $request, Response $response, array $args): Response
    {
        $first = "{$args['ym']}-01";
        $rows = $this->db->all(
            "SELECT * FROM (
               SELECT x.spent_on bought_on, x.description, x.amount, x.merchant, k.name card, NULL installments, CONCAT('/expenses/', x.id) url, x.created_by
               FROM expenses x JOIN months m ON m.id=x.month_id JOIN cards k ON k.id=x.card_id WHERE m.ym=?
               UNION ALL
               SELECT p.purchased_on, p.concept, p.total_amount, p.merchant, k.name, p.installments, CONCAT('/installments/', p.id), NULL
               FROM installment_plans p JOIN cards k ON k.id=p.card_id WHERE p.purchased_on BETWEEN ? AND LAST_DAY(?)
             ) purchases ORDER BY bought_on DESC",
            [$args['ym'], $first, $first]
        );
        $byCard = [];
        foreach ($rows as $row) {
            $byCard[$row['card']] ??= ['name' => $row['card'], 'regular' => 0.0, 'installments' => 0.0];
            $byCard[$row['card']][$row['installments'] ? 'installments' : 'regular'] += (float) $row['amount'];
        }
        ksort($byCard);
        return $this->render($response, 'cards.twig', ['ym' => $args['ym'], 'statements' => $this->statements($args['ym']), 'rows' => $rows, 'by_card' => array_values($byCard), 'total' => array_sum(array_column($rows, 'amount'))]);
    }
}

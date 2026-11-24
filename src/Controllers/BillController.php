<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Input;
use App\Services\ValidationError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

final class BillController extends Controller
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $bills = $this->budget->bills($args['ym']);
        return $this->render($response, 'bills.twig', [
            'ym' => $args['ym'], 'bills' => $bills, 'open' => $this->budget->month($args['ym']) !== null,
            'total' => array_sum(array_column($bills, 'amount')), 'remaining' => array_sum(array_column($bills, 'remaining')),
        ]);
    }

    public function generate(Request $request, Response $response, array $args): Response
    {
        $count = $this->budget->generateBills($args['ym'], $this->budget->ensureMonth($args['ym']));
        $this->notice('good', $count ? 'bills.generated' : 'bills.none_missing', ['count' => $count]);
        return $this->redirect($response, "/months/{$args['ym']}/bills");
    }

    private function bill(Request $request, string $id): array
    {
        $row = $this->find($request, 'bills', $id);
        $ym = (string) $this->db->value('SELECT ym FROM months WHERE id=?', [$row['month_id']]);
        foreach ($this->budget->bills($ym) as $bill) {
            if ((int) $bill['id'] === (int) $row['id']) {
                return $bill + ['ym' => $ym];
            }
        }
        throw new HttpNotFoundException($request);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $bill = $this->bill($request, $args['id']);
        return $this->render($response, 'bill.twig', [
            'ym' => $bill['ym'], 'bill' => $bill, 'categories' => $this->categories((int) $bill['category_id']), 'people' => $this->options('people'),
            'cards' => $this->options('cards'), 'default_person' => $request->getAttribute('user')['person_id'],
            'payments' => $this->db->all('SELECT x.id, x.spent_on, x.description, x.amount, p.name person FROM expenses x JOIN people p ON p.id=x.person_id WHERE x.bill_id=? ORDER BY x.spent_on', [$bill['id']]),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $bill = $this->bill($request, $args['id']);
        return $this->attempt($request, $response, "/bills/{$bill['id']}", function (Input $in) use ($bill): string {
            $this->db->update('bills', (int) $bill['id'], [
                'amount' => $in->amount('amount', $this->t('fields.amount'), true),
                'due_on' => $in->date('due_on', $this->t('bills.due')),
                'unlinked_payment' => $in->amount('unlinked_payment', $this->t('bills.unlinked'), true),
            ]);
            $this->notice('good', 'bills.updated');
            return "/months/{$bill['ym']}/bills";
        });
    }

    /** Records the payment as a linked expense. */
    public function pay(Request $request, Response $response, array $args): Response
    {
        $bill = $this->bill($request, $args['id']);
        return $this->attempt($request, $response, "/bills/{$bill['id']}", function (Input $in) use ($request, $bill): string {
            if ($bill['group_id']) {
                throw new ValidationError($this->t('bills.automatic'));
            }
            $this->db->insert('expenses', [
                // Counts in the bill's month even if it is paid some days before or after
                'month_id' => $bill['month_id'],
                'spent_on' => $in->date('spent_on', $this->t('fields.date')),
                'description' => $in->text('description', $this->t('fields.description'), 150),
                'category_id' => $in->id('category_id', $this->t('fields.category')),
                'person_id' => $in->id('person_id', $this->t('fields.person')),
                'amount' => $in->amount('payment', $this->t('fields.amount')),
                'card_id' => $in->id('card_id', $this->t('fields.card'), false),
                'bill_id' => $bill['id'],
                'created_by' => $request->getAttribute('user')['id'],
            ]);
            $this->notice('good', 'bills.paid_notice', ['name' => $bill['name']]);
            return "/months/{$bill['ym']}/bills";
        });
    }
}

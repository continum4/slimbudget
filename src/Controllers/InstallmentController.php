<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Chart;
use App\Services\Input;
use App\Services\Month;
use App\Services\ValidationError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class InstallmentController extends Controller
{
    public function index(Request $request, Response $response, array $args): Response
    {
        $ym = $args['ym'];
        $plans = $this->budget->plans($ym);
        $projection = $this->budget->projection($ym);
        $isCurrent = static fn (array $p): bool => $p['active_now'] || $p['left_count'] > 0;
        return $this->render($response, 'installments.twig', [
            'ym' => $ym,
            'current' => array_values(array_filter($plans, $isCurrent)),
            'finished' => array_values(array_filter($plans, static fn (array $p): bool => !$isCurrent($p))),
            'balance' => array_sum(array_map(static fn (array $p): float => (float) $p['total_amount'] / (int) $p['installments'] * (int) $p['left_count'], $plans)),
            'projection' => $projection, 'cards' => $this->options('cards'), 'merchants' => $this->merchants(),
            'chart' => Chart::columns(
                array_map(fn (array $p): string => $this->translator->monthShort($p['ym']), $projection),
                [$this->t('installments.series') => array_column($projection, 'amount')]
            ),
        ]);
    }

    private function fields(Input $in): array
    {
        return [
            'card_id' => $in->id('card_id', $this->t('fields.card')),
            'concept' => $in->text('concept', $this->t('installments.concept'), 120),
            'total_amount' => $in->amount('total_amount', $this->t('installments.total')),
            'installments' => $in->integer('installments', $this->t('installments.count'), 1, 120),
            'start_month' => $in->month('start_month', $this->t('installments.first_month')),
            'purchased_on' => $in->date('purchased_on', $this->t('installments.purchased_on'), false),
            'merchant' => $in->text('merchant', $this->t('fields.merchant'), 80, false),
        ];
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        $url = "/months/{$args['ym']}/installments";
        return $this->attempt($request, $response, $url, function (Input $in) use ($url): string {
            $fields = $this->fields($in);
            $this->db->insert('installment_plans', $fields + ['end_month' => Month::add($fields['start_month'], $fields['installments'] - 1)]);
            $this->notice('good', 'installments.saved', ['concept' => $fields['concept']]);
            return $url;
        });
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $plan = $this->find($request, 'installment_plans', $args['id']);
        return $this->render($response, 'installment.twig', ['ym' => Month::current(), 'plan' => $plan, 'cards' => $this->options('cards', (int) $plan['card_id']), 'merchants' => $this->merchants()]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $plan = $this->find($request, 'installment_plans', $args['id']);
        return $this->attempt($request, $response, "/installments/{$plan['id']}", function (Input $in) use ($plan): string {
            $fields = $this->fields($in);
            $end = $in->month('end_month', $this->t('installments.last_month'));
            if ($end < $fields['start_month']) {
                throw new ValidationError($this->t('installments.bad_range'));
            }
            $this->db->update('installment_plans', (int) $plan['id'], $fields + ['end_month' => $end, 'settled' => $in->checkbox('settled'), 'note' => $in->text('note', $this->t('fields.note'), 255, false)]);
            $this->notice('good', 'installments.updated');
            return '/months/' . Month::current() . '/installments';
        });
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $plan = $this->find($request, 'installment_plans', $args['id']);
        $this->db->run('DELETE FROM installment_plans WHERE id=?', [$plan['id']]);
        $this->notice('good', 'installments.deleted', ['concept' => $plan['concept']]);
        return $this->redirect($response, '/months/' . Month::current() . '/installments');
    }
}

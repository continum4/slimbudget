<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\CardCycles;
use App\Services\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CardCycleController extends Controller
{
    /** Adds the dates that apply from a month; the same month replaces what it had. */
    public function save(Request $request, Response $response, array $args): Response
    {
        $card = $this->find($request, 'cards', $args['id']);
        $url = "/catalogs/cards/{$card['id']}#cycles";
        return $this->attempt($request, $response, $url, function (Input $in) use ($card, $url): string {
            $from = $in->raw('from_month') === '' ? CardCycles::BEGINNING : $in->month('from_month', $this->t('cycles.from'));
            $closing = $in->integer('closing_day', $this->t('cycles.closing_day'), 1, 31, false);
            $due = $in->integer('due_day', $this->t('cycles.due_day'), 1, 31, false);
            $this->db->run('DELETE FROM card_cycles WHERE card_id=? AND from_month=?', [$card['id'], $from]);
            $this->db->insert('card_cycles', ['card_id' => $card['id'], 'from_month' => $from, 'closing_day' => $closing, 'due_day' => $due]);
            $this->notice('good', 'cycles.saved');
            return $url;
        });
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $cycle = $this->find($request, 'card_cycles', $args['id']);
        $this->db->run('DELETE FROM card_cycles WHERE id=?', [$cycle['id']]);
        $this->notice('good', 'cycles.deleted');
        return $this->redirect($response, "/catalogs/cards/{$cycle['card_id']}#cycles");
    }
}

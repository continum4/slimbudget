<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Chart;
use App\Services\Month;
use App\Services\Trends;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class YearController extends Controller
{
    public function show(Request $request, Response $response, array $args): Response
    {
        $year = (int) $args['year'];
        $months = array_column($this->db->all('SELECT ym FROM months WHERE ym LIKE ? ORDER BY ym', ["$year-%"]), 'ym');
        $table = $income = $paid = $estimated = [];
        foreach ($months as $ym) {
            $summary = $this->budget->summary($ym);
            $income[] = $summary['total_income'];
            $paid[] = $summary['paid'];
            $estimated[] = $summary['estimated'];
            foreach ($summary['groups'] as $group) {
                $table[$group['name']][$ym] = $group['paid'];
            }
        }
        return $this->render($response, 'year.twig', [
            'year' => $year, 'ym' => $months ? end($months) : Month::current(), 'months' => $months, 'table' => $table,
            'totals' => ['paid' => $paid, 'estimated' => $estimated, 'income' => $income],
            'chart' => Chart::columns(array_map($this->translator->monthShort(...), $months), [$this->t('year.income') => $income, $this->t('year.spending') => $paid]),
        ]);
    }

    public function trends(Request $request, Response $response, array $args): Response
    {
        $year = (int) $args['year'];
        $includeFixed = ($request->getQueryParams()['fixed'] ?? '1') !== '0';
        $data = (new Trends($this->db))->year($year, $includeFixed);
        $labels = array_map($this->translator->monthShort(...), $data['months']);
        $partial = $data['in_progress'] ? array_search($data['in_progress'], $data['months'], true) : null;
        $reference = $data['typical'] === null ? null : ['value' => $data['typical'], 'label' => $this->t('trends.typical', ['amount' => $this->money->format($data['typical'], 0)])];
        return $this->render($response, 'trends.twig', [
            'year' => $year, 'ym' => $data['months'] ? end($data['months']) : Month::current(), 'd' => $data, 'include_fixed' => $includeFixed, 'labels' => $labels,
            'chart' => Chart::columns($labels, [$this->t('year.spending') => $data['totals']], $reference, $partial),
            'minis' => array_map(static fn (array $g): array => Chart::mini($g['values'], $g['typical'], $partial), $data['groups']),
        ]);
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Chart;
use App\Services\Month;
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
}

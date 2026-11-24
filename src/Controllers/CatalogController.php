<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Input;
use App\Services\Month;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/** Generic CRUD. Each field: [type, extra]; its label is the text catalogs.fields.<field>. */
final class CatalogController extends Controller
{
    private const CATALOGS = [
        'groups' => ['table' => 'category_groups', 'fields' => ['name' => ['text', 60]]],
        'categories' => ['table' => 'categories', 'fields' => ['group_id' => ['ref', 'category_groups'], 'name' => ['text', 100], 'mode' => ['option', MonthController::MODES]]],
        'people' => ['table' => 'people', 'fields' => ['name' => ['text', 60], 'spending_is_income' => ['checkbox', null]]],
        'cards' => ['table' => 'cards', 'fields' => ['name' => ['text', 80], 'alias' => ['text?', 40], 'category_id' => ['ref?', 'categories']]],
        'bills' => ['table' => 'bill_templates', 'fields' => ['name' => ['text', 80], 'day' => ['integer', [1, 31]], 'amount' => ['amount', null], 'category_id' => ['ref?', 'categories'], 'group_id' => ['ref?', 'category_groups']]],
    ];

    private function catalog(Request $request, array $args): array
    {
        return (self::CATALOGS[$args['catalog']] ?? throw new HttpNotFoundException($request)) + ['key' => $args['catalog']];
    }

    private function label(string $field): string
    {
        return $this->t("catalogs.fields.$field");
    }

    private function references(array $catalog, array $row = []): array
    {
        $references = [];
        foreach ($catalog['fields'] as $field => [$type, $extra]) {
            if (str_starts_with($type, 'ref')) {
                $current = (int) ($row[$field] ?? 0);
                $references[$field] = $extra === 'categories'
                    ? $this->db->all("SELECT c.id, CONCAT(g.name, ' · ', c.name) name FROM categories c JOIN category_groups g ON g.id=c.group_id WHERE c.active=1 OR c.id=? ORDER BY g.name, c.name", [$current])
                    : $this->db->all("SELECT id, name FROM $extra WHERE active=1 OR id=? ORDER BY name", [$current]);
            }
        }
        return $references;
    }

    public function index(Request $request, Response $response, array $args): Response
    {
        $catalog = $this->catalog($request, $args);
        $names = [];
        foreach ($catalog['fields'] as $field => [$type, $extra]) {
            if (str_starts_with($type, 'ref')) {
                $sql = $extra === 'categories' ? "SELECT c.id, CONCAT(g.name, ' · ', c.name) name FROM categories c JOIN category_groups g ON g.id=c.group_id" : "SELECT id, name FROM $extra";
                $names[$field] = array_column($this->db->all($sql), 'name', 'id');
            }
        }
        $order = match ($catalog['key']) {
            'categories' => 'active DESC, (SELECT g.name FROM category_groups g WHERE g.id=group_id), name',
            'bills' => 'active DESC, day, name',
            default => 'active DESC, name',
        };
        return $this->render($response, 'catalogs/index.twig', [
            'catalog' => $catalog, 'rows' => $this->db->all("SELECT * FROM {$catalog['table']} ORDER BY $order"),
            'cycles' => $this->currentCycles($catalog), 'names' => $names, 'references' => $this->references($catalog), 'menu' => array_keys(self::CATALOGS),
        ]);
    }

    /** Statement dates in force for each card. */
    private function currentCycles(array $catalog): array
    {
        $current = [];
        if ($catalog['key'] === 'cards') {
            foreach ($this->db->all('SELECT * FROM card_cycles WHERE from_month<=? ORDER BY from_month', [Month::current()]) as $cycle) {
                $current[$cycle['card_id']] = $cycle;
            }
        }
        return $current;
    }

    private function fields(array $catalog, Input $in): array
    {
        $data = [];
        foreach ($catalog['fields'] as $field => [$type, $extra]) {
            $label = $this->label($field);
            $data[$field] = match ($type) {
                'text', 'text?' => $in->text($field, $label, $extra, $type === 'text'),
                'integer' => $in->integer($field, $label, ...$extra),
                'amount' => $in->amount($field, $label, true),
                'checkbox' => $in->checkbox($field),
                'option' => $in->option($field, $label, $extra),
                'ref', 'ref?' => $in->id($field, $label, $type === 'ref'),
            };
        }
        return $data;
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        $catalog = $this->catalog($request, $args);
        $url = "/catalogs/{$catalog['key']}";
        return $this->attempt($request, $response, $url, function (Input $in) use ($catalog, $url): string {
            $data = $this->fields($catalog, $in);
            $this->db->insert($catalog['table'], $data);
            $this->notice('good', 'catalogs.added', ['name' => $data['name']]);
            return $url;
        });
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $catalog = $this->catalog($request, $args);
        $row = $this->find($request, $catalog['table'], $args['id']);
        return $this->render($response, 'catalogs/edit.twig', [
            'catalog' => $catalog, 'row' => $row, 'references' => $this->references($catalog, $row),
            'cycles' => $catalog['key'] === 'cards' ? $this->db->all('SELECT * FROM card_cycles WHERE card_id=? ORDER BY from_month DESC', [$row['id']]) : [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $catalog = $this->catalog($request, $args);
        $row = $this->find($request, $catalog['table'], $args['id']);
        return $this->attempt($request, $response, "/catalogs/{$catalog['key']}/{$row['id']}", function (Input $in) use ($catalog, $row): string {
            $this->db->update($catalog['table'], (int) $row['id'], $this->fields($catalog, $in) + ['active' => $in->checkbox('active')]);
            $this->notice('good', 'catalogs.saved');
            return "/catalogs/{$catalog['key']}";
        });
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $catalog = $this->catalog($request, $args);
        $row = $this->find($request, $catalog['table'], $args['id']);
        return $this->attempt($request, $response, "/catalogs/{$catalog['key']}/{$row['id']}", function () use ($catalog, $row): string {
            $this->db->run("DELETE FROM {$catalog['table']} WHERE id=?", [$row['id']]);
            $this->notice('good', 'catalogs.deleted', ['name' => $row['name']]);
            return "/catalogs/{$catalog['key']}";
        });
    }
}

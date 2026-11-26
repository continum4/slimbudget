<?php
declare(strict_types=1);

// End-to-end tests: erases the test database, starts PHP's server and walks through the app.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config;
use App\Database;
use App\Services\Month;
use Tests\e2e\Client;

$root = dirname(__DIR__, 2);
putenv('SLIMBUDGET_CONFIG=' . (getenv('SLIMBUDGET_CONFIG') ?: "$root/config/test.php"));
$config = Config::load();
if (!str_ends_with($config['db']['name'], '_test')) {
    fwrite(STDERR, "Refusing to erase '{$config['db']['name']}': the test database name must end in _test.\n");
    exit(1);
}
foreach (['cache', 'log', 'sessions'] as $dir) {
    @mkdir("{$config['var_dir']}/$dir", 0700, true);
}
$db = new Database($config['db']);
$db->run('SET FOREIGN_KEY_CHECKS=0');
foreach ($db->run('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $db->run("DROP TABLE `$table`");
}
$db->run('SET FOREIGN_KEY_CHECKS=1');
foreach (['migrate.php', 'seed.php demo', 'migrate.php'] as $command) {
    passthru('php ' . "$root/bin/$command", $code);
    if ($code) {
        exit($code);
    }
}
$host = parse_url($config['url'], PHP_URL_HOST) . ':' . parse_url($config['url'], PHP_URL_PORT);
$server = proc_open(['php', '-S', $host, '-t', "$root/public", "$root/public/index.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$config['var_dir']}/log/server.log", 'w']], $pipes);
register_shutdown_function(static fn () => proc_terminate($server));
for ($i = 0; $i < 50 && !@fsockopen(explode(':', $host)[0], (int) explode(':', $host)[1]); $i++) {
    usleep(100000);
}

$failures = $checks = 0;
$check = static function (string $name, bool $ok, mixed $detail = '') use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures++;
        echo "FAIL  $name ", is_scalar($detail) ? $detail : json_encode($detail), "\n";
    }
};
$ym = Month::current();
$next = Month::add($ym, 1);
$id = static fn (string $sql, array $params = []): int => (int) $db->value($sql, $params);

$admin = new Client($config['url']);
$admin->get('/months/' . $ym);
$check('asks to sign in', $admin->path === '/login');
$admin->post('/language', ['locale' => 'es']);
$check('login in Spanish', $admin->sees('Contraseña') && $admin->sees('lang="es"'));
$admin->post('/language', ['locale' => '../../etc']);
$check('ignores unknown language', $admin->sees('Contraseña'));
$admin->post('/language', ['locale' => 'en']);
$admin->post('/login', ['username' => 'demo', 'password' => 'wrong']);
$check('wrong password', $admin->sees('Wrong username or password.'));
$admin->post('/login', ['username' => 'demo', 'password' => 'slimbudget-demo']);
$check('signs in', $admin->path === "/months/$ym", $admin->path);

$check('every table is utf8mb4', $id("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_collation<>'utf8mb4_unicode_ci'") === 0);
$bill = $id('SELECT MIN(id) FROM bills');
$groupBill = $id('SELECT MIN(id) FROM bills WHERE group_id IS NOT NULL');
foreach (["/months/$ym", '/months/2031-01', "/months/$ym/expenses", "/months/$ym/expenses?q=a&person=1&category=x", "/months/$ym/cards", "/months/$ym/bills", "/months/$ym/installments", "/months/$ym/estimates", '/years/' . substr($ym, 0, 4), '/years/2031', '/years/' . substr($ym, 0, 4) . '/trends', '/years/' . substr($ym, 0, 4) . '/trends?fixed=0', '/years/2031/trends', '/review', '/search', '/search?q=a&person=1&category=x&page=-3', '/profile', '/users', '/users/1', '/settings', '/catalogs/groups', '/catalogs/categories', '/catalogs/people', '/catalogs/cards', '/catalogs/bills', '/catalogs/categories/1', '/catalogs/bills/1', '/expenses/1', '/installments/1', "/bills/$bill", "/bills/$groupBill"] as $path) {
    $admin->get($path);
    $check("GET $path", $admin->status === 200 && !preg_match('/(Warning|Deprecated|Notice):/', $admin->body), [$admin->status, mb_substr(trim(strip_tags($admin->body)), -300)]);
    $check("no raw keys in $path", !preg_match('/>\s*(app|nav|fields|status|modes|login|profile|month|incomes|estimates|expenses|review|search|cards|bills|installments|year|trends|group_kinds|catalogs|users|settings|errors)\.[a-z_.]+\s*</', $admin->body, $m), $m[0] ?? '');
}
foreach (['/nope', '/months/2026-13', '/expenses/999999', '/catalogs/users'] as $path) {
    $check("404 $path", $admin->get($path)->status === 404);
}
$check('CSRF is required', $admin->post("/months/$ym/expenses", ['description' => 'x'], false)->status === 400);

// Expenses
$admin->get("/months/$ym/expenses");
$expense = ['spent_on' => Month::date($ym, 3), 'description' => 'Test <b>xss</b>', 'category_id' => $id("SELECT id FROM categories WHERE name='Rent'"), 'person_id' => 1, 'amount' => '1,234.50', 'card_id' => 1, 'merchant' => 'Shop'];
$admin->post("/months/$ym/expenses", $expense);
$expenseId = $id('SELECT MAX(id) FROM expenses');
$check('expense saved', $admin->sees('Expense saved') && $db->value('SELECT amount FROM expenses WHERE id=?', [$expenseId]) === '1234.50');
$check('HTML is escaped', !str_contains($admin->body, '<b>xss'));
$admin->post("/months/$ym/expenses", ['amount' => '1,50'] + $expense);
$check('bad amount keeps the form', $admin->sees('Amount must be an amount like 1234.50.') && $admin->sees('value="Shop"'));
$check('bad date', $admin->post("/months/$ym/expenses", ['spent_on' => '2026-02-31'] + $expense)->sees('Date is not a valid date.'));
$check('missing category', $admin->post("/months/$ym/expenses", ['category_id' => 99999] + $expense)->sees('no longer exists'));
$admin->post("/expenses/$expenseId", ['spent_on' => Month::date($next, 2)] + $expense);
$check('moving the date opens the month', $admin->path === "/months/$next/expenses" && $id('SELECT COUNT(*) FROM bills b JOIN months m ON m.id=b.month_id WHERE m.ym=?', [$next]) === 8 && $id('SELECT COUNT(*) FROM estimates e JOIN months m ON m.id=e.month_id WHERE m.ym=?', [$next]) > 20);
$check('new month copies the salary', $id("SELECT i.amount FROM incomes i JOIN months m ON m.id=i.month_id WHERE m.ym=? AND kind='salary'", [$next]) === 1900);
$admin->post("/expenses/$expenseId/delete");
$check('expense deleted', $id('SELECT COUNT(*) FROM expenses WHERE id=?', [$expenseId]) === 0);

// Search across months
$first = Month::add($ym, -5);
$check('search without filters only shows the form', $admin->get('/search')->status === 200 && !$admin->sees('id="results"'));
$coffee = $id("SELECT COUNT(*) FROM expenses WHERE description LIKE '%coffee%' OR merchant LIKE '%coffee%'");
$coffeeMonths = $id("SELECT COUNT(DISTINCT month_id) FROM expenses WHERE description LIKE '%coffee%'");
$admin->get('/search?q=coffee');
$check('search finds every month', $admin->sees("id=\"results\">$coffee expenses<") && substr_count($admin->body, 'class="result-month"') === $coffeeMonths, [$coffee, $coffeeMonths]);
$check('month heading keeps the text filter', $admin->sees("href=\"/months/$ym/expenses?q=coffee\""));
$inRange = $id("SELECT COUNT(*) FROM expenses x JOIN months m ON m.id=x.month_id WHERE x.description LIKE '%coffee%' AND m.ym BETWEEN ? AND ?", [Month::add($ym, -2), $ym]);
$check('reversed range is swapped', $admin->get('/search?q=coffee&from=' . $ym . '&to=' . Month::add($ym, -2))->sees("id=\"results\">$inRange expenses<") && $admin->sees('value="' . Month::add($ym, -2) . '"'));
$check('bad month is ignored', $admin->get('/search?q=coffee&from=2026-13&to=x')->sees("id=\"results\">$coffee expenses<"));
$all = $id("SELECT COUNT(*) FROM expenses x JOIN months m ON m.id=x.month_id WHERE m.ym>=?", [$first]);
$pages = (int) ceil($all / 50);
$admin->get("/search?from=$first");
$check('search is paged', $pages > 1 && $admin->sees("Page 1 of $pages") && substr_count($admin->body, '<td class="date">') === 50 && $admin->sees('rel="next"') && !$admin->sees('rel="prev"'), [$all, $pages]);
$check('page out of range goes to the last one', $admin->get("/search?from=$first&page=999")->sees("Page $pages of $pages") && !$admin->sees('rel="next"') && $admin->sees('href="/search?from=' . $first . '&amp;page=' . ($pages - 1) . '"'));
$check('LIKE wildcards are literal', $admin->get('/search?q=%25')->sees('No expenses match.'));

// Bills
$pending = $id("SELECT b.id FROM bills b JOIN months m ON m.id=b.month_id WHERE m.ym=? AND b.name='Music'", [$next]);
$admin->get("/bills/$pending");
$admin->post("/bills/$pending/pay", ['spent_on' => Month::date($next, 20), 'description' => 'Music', 'category_id' => $id("SELECT id FROM categories WHERE name='Music'"), 'person_id' => 1, 'payment' => '5', 'card_id' => '']);
$check('partial payment', $admin->sees('Payment recorded: Music.') && $admin->sees('Partial'));
$check('group bill cannot be paid by hand', $admin->post("/bills/$groupBill/pay", ['payment' => '5'])->sees('calculated from the expenses of its group'));
$admin->post("/bills/$pending", ['amount' => '5', 'due_on' => Month::date($next, 21), 'unlinked_payment' => '0']);
$check('bill becomes paid', (bool) preg_match('/>Music<.*?Paid<\/span>/s', $admin->body));
$check('nothing to generate', $admin->post("/months/$next/bills/generate")->sees('No recurring bill was missing.'));

// Installments
$admin->post("/months/$ym/installments", ['card_id' => 1, 'concept' => 'Test plan', 'total_amount' => '1200', 'installments' => 6, 'start_month' => $next, 'purchased_on' => Month::date($ym, 1), 'merchant' => '']);
$check('plan end month', $db->value("SELECT end_month FROM installment_plans WHERE concept='Test plan'") === Month::add($next, 5));
$check('plan is a card purchase, not an expense', $admin->get("/months/$ym/cards")->sees('Test plan') && $id("SELECT COUNT(*) FROM expenses WHERE description='Test plan'") === 0);
$plan = $id("SELECT id FROM installment_plans WHERE concept='Test plan'");
$check('plan range', $admin->post("/installments/$plan", ['card_id' => 1, 'concept' => 'Test plan', 'total_amount' => '1200', 'installments' => 6, 'start_month' => $next, 'end_month' => $ym])->sees('cannot be before'));
$admin->post("/installments/$plan/delete");

// Estimates, incomes and totals
$before = $db->all('SELECT category_id, mode, amount FROM estimates e JOIN months m ON m.id=e.month_id WHERE m.ym=? ORDER BY 1', [$ym]);
$lines = [];
foreach ($before as $line) {
    $lines[$line['category_id']] = ['mode' => $line['mode'], 'amount' => $line['amount']];
}
$admin->get("/months/$ym/estimates");
$admin->post("/months/$ym/estimates", ['estimates' => $lines]);
$check('saving estimates changes nothing by itself', $admin->sees('Estimates saved.') && $before == $db->all('SELECT category_id, mode, amount FROM estimates e JOIN months m ON m.id=e.month_id WHERE m.ym=? ORDER BY 1', [$ym]));
$summary = (new App\Services\Budget($db))->summary($ym);
$check('paid equals the sum of expenses', abs($summary['paid'] - (float) $db->value('SELECT SUM(amount) FROM expenses x JOIN months m ON m.id=x.month_id WHERE m.ym=?', [$ym])) < 0.005);
$check('groups and categories are sorted', array_column($summary['groups'], 'name') === (static function (array $n): array { sort($n); return $n; })(array_column($summary['groups'], 'name')));
$admin->get("/months/$ym");
$admin->post("/months/$ym/incomes", ['kind' => 'other', 'description' => 'Sale', 'amount' => '100']);
$check('income added', abs((new App\Services\Budget($db))->summary($ym)['total_income'] - $summary['total_income'] - 100) < 0.005);
$admin->post('/incomes/' . $id("SELECT id FROM incomes WHERE description='Sale'") . '/delete');

// Card statements: informative, never part of the totals
$budget = new App\Services\Budget($db);
$previous = Month::add($ym, -1);
$green = $id("SELECT id FROM cards WHERE name='Green Card'");
$statement = static fn (string $month, int $card): array => array_column($budget->cardStatements($month), null, 'id')[$card] ?? ['regular' => 0.0, 'installments' => 0.0, 'total' => 0.0];
$spent = (float) $db->value('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE card_id=? AND spent_on LIKE ?', [$green, "$previous-%"]);
$check('calendar statement is last month\'s purchases', $spent > 0 && abs($statement($ym, $green)['regular'] - $spent) < 0.005);
$plans = (float) $db->value('SELECT COALESCE(SUM(total_amount/installments),0) FROM installment_plans WHERE card_id=? AND start_month<=? AND end_month>=?', [$green, $ym, $ym]);
$check('plus this month\'s installments', abs($statement($ym, $green)['total'] - $spent - $plans) < 0.011);
$totals = $budget->summary($ym);
$next1 = $statement($next, $green)['regular'];
$db->run('INSERT INTO expenses (month_id, spent_on, description, category_id, person_id, amount, card_id) VALUES (?,?,?,?,1,100,?)', [$id('SELECT id FROM months WHERE ym=?', [$ym]), Month::date($ym, 2), 'Statement test', $expense['category_id'], $green]);
$check('a card purchase counts once as spending', abs($budget->summary($ym)['paid'] - $totals['paid'] - 100) < 0.005);
$check('and is charged next month', abs($statement($next, $green)['regular'] - $next1 - 100) < 0.005 && abs($statement($ym, $green)['regular'] - $spent) < 0.005);
$admin->get("/catalogs/cards/$green");
$cycle = static fn (array $data): Client => $admin->get("/catalogs/cards/$green")->post("/cards/$green/cycles", $data + ['closing_day' => '', 'due_day' => '', 'from_month' => '']);
$check('closing day moves the purchase to the next statement', $cycle(['closing_day' => '1', 'due_day' => '20'])->sees('Statement dates saved.') && abs($statement($next, $green)['regular'] - (float) $db->value('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE card_id=? AND spent_on BETWEEN ? AND ?', [$green, Month::date($ym, 2), "$next-01"])) < 0.005);
$past = $statement($previous, $green);
$cycle(['closing_day' => '5', 'due_day' => '25', 'from_month' => $next]);
$check('a later change keeps earlier statements', $statement($previous, $green) == $past && $id('SELECT COUNT(*) FROM card_cycles WHERE card_id=?', [$green]) === 2);
$cycle(['closing_day' => '6', 'due_day' => '26', 'from_month' => $next]);
$check('the same month is replaced', $id('SELECT COUNT(*) FROM card_cycles WHERE card_id=?', [$green]) === 2 && $id('SELECT closing_day FROM card_cycles WHERE card_id=? AND from_month=?', [$green, $next]) === 6);
$check('bad closing day', $cycle(['closing_day' => '40'])->sees('Closing day must be between 1 and 31.') && $id('SELECT COUNT(*) FROM card_cycles WHERE card_id=?', [$green]) === 2);
foreach ($db->all('SELECT id FROM card_cycles WHERE card_id=?', [$green]) as $row) {
    $admin->get("/catalogs/cards/$green")->post("/card-cycles/{$row['id']}/delete");
}
$check('without dates it goes back to the calendar', $admin->sees('Statement dates deleted.') && abs($statement($ym, $green)['regular'] - $spent) < 0.005);
$check('editing the card keeps its dates', $admin->get('/catalogs/cards/1')->post('/catalogs/cards/1', ['name' => 'Blue Bank', 'alias' => 'Blue', 'category_id' => $db->value('SELECT category_id FROM cards WHERE id=1'), 'active' => 1])->sees('Changes saved.') && $id('SELECT COUNT(*) FROM card_cycles WHERE card_id=1') === 2);
$check('month page shows it', $admin->get("/months/$ym")->sees('Card payments') && $admin->sees('does not add to spending'));
$check('cards page shows both months', $admin->get("/months/$ym/cards")->sees('To pay in') && $admin->sees('Building up for'));
$db->run("DELETE FROM expenses WHERE description='Statement test'");
$check('totals are untouched', $budget->summary($ym)['paid'] == $totals['paid'] && $budget->summary($ym)['balance'] == $totals['balance']);

// Trends
$year = substr($ym, 0, 4);
$spent = static fn (string $kinds): float => (float) $db->value("SELECT COALESCE(SUM(x.amount),0) FROM expenses x JOIN months m ON m.id=x.month_id JOIN categories c ON c.id=x.category_id JOIN category_groups g ON g.id=c.group_id WHERE m.ym BETWEEN ? AND ? AND g.kind IN ($kinds)", ["$year-01", $ym]);
$trends = new App\Services\Trends($db);
$check('trends leave savings out', abs($trends->year((int) $year, true)['total'] - $spent("'variable','fixed'")) < 0.005);
$check('trends without fixed', abs($trends->year((int) $year, false)['total'] - $spent("'variable'")) < 0.005 && $spent("'fixed'") > 0);
$check('trends mark the month in progress', $trends->year((int) $year, true)['in_progress'] === $ym && str_contains($admin->get("/years/$year/trends")->body, 'class="s1 partial"'));
$food = $id("SELECT id FROM category_groups WHERE name='Food'");
$admin->get("/catalogs/groups/$food");
$check('group kind saved', $admin->post("/catalogs/groups/$food", ['name' => 'Food', 'kind' => 'savings', 'active' => 1])->sees('Changes saved.') && !in_array('Food', array_column($trends->year((int) $year, true)['groups'], 'name'), true));
$check('bad group kind', $admin->get("/catalogs/groups/$food")->post("/catalogs/groups/$food", ['name' => 'Food', 'kind' => 'nope', 'active' => 1])->status === 200 && $db->value('SELECT kind FROM category_groups WHERE id=?', [$food]) === 'savings');
$admin->get("/catalogs/groups/$food")->post("/catalogs/groups/$food", ['name' => 'Food', 'kind' => 'variable', 'active' => 1]);
$check('kinds do not change the budget', $budget->summary($ym)['paid'] == $totals['paid']);

// Catalogs and users
$admin->get('/catalogs/categories');
$check('category added', $admin->post('/catalogs/categories', ['group_id' => 1, 'name' => 'Brand new', 'mode' => 'fixed'])->sees('Added: Brand new.'));
$check('duplicate category', $admin->post('/catalogs/categories', ['group_id' => 1, 'name' => 'Brand new', 'mode' => 'fixed'])->sees('already one with that name'));
$check('category deleted', $admin->post('/catalogs/categories/' . $id("SELECT id FROM categories WHERE name='Brand new'") . '/delete')->sees('Deleted: Brand new.'));
$check('group in use stays', $admin->post('/catalogs/groups/1/delete')->sees('it is in use'));
$check('no self lockout', $admin->post('/users/1', ['username' => 'demo', 'name' => 'Demo', 'role' => 'member', 'person_id' => 1, 'active' => 1])->sees('You cannot deactivate yourself'));

// Currency and language
$admin->get('/settings');
$check('unknown currency', $admin->post('/settings', ['currency' => 'XXX', 'locale' => 'en'])->sees('Currency is not valid.'));
$admin->post('/settings', ['currency' => 'EUR', 'locale' => 'en']);
$check('amounts in euros', (bool) preg_match('/\d+,\d{2} €/', $admin->get("/months/$ym")->body));
$admin->get("/months/$ym/expenses")->post("/months/$ym/expenses", ['amount' => '1.234,50'] + $expense);
$check('euro input', $db->value('SELECT amount FROM expenses ORDER BY id DESC LIMIT 1') === '1234.50');
$db->run('DELETE FROM expenses ORDER BY id DESC LIMIT 1');
$language = static fn (Client $c): string => preg_match('/<html lang="([\w-]+)"/', $c->get("/months/" . Month::current())->body, $m) ? $m[1] : '';
$check('language picked at login becomes the user\'s', $db->value("SELECT locale FROM users WHERE username='demo'") === 'en');
$admin->get('/settings')->post('/settings', ['currency' => 'MXN', 'locale' => 'es']);
$check('default language reaches new visitors', (new Client($config['url']))->get('/login')->sees('Contraseña'));
$check('own language wins and Settings says so', $language($admin) === 'en' && $admin->get('/settings')->sees('You see the app in English'));
$admin->get('/profile')->post('/profile/language', ['locale' => '']);
$check('back to the default applies at once', $admin->sees('Idioma guardado.') && $language($admin) === 'es', $language($admin));
$admin->get('/settings')->post('/settings', ['currency' => 'MXN', 'locale' => 'en']);
$check('changing the default applies at once', $admin->sees('Settings saved.') && $language($admin) === 'en', $language($admin));
$admin->get('/profile')->post('/profile/language', ['locale' => 'es']);
$check('changing it in the profile applies at once', $admin->sees('Idioma guardado.') && $language($admin) === 'es');
foreach (["/months/$ym", "/months/$ym/estimates", "/months/$ym/expenses", "/months/$ym/bills", "/months/$ym/installments"] as $path) {
    $check("Spanish $path", $admin->get($path)->status === 200 && $admin->sees('lang="es"'));
}
$admin->get('/profile')->post('/profile/language', ['locale' => '']);
$admin->get('/settings')->post('/settings', ['currency' => 'USD', 'locale' => 'en']);

// Roles, deactivation, lockout and passwords
$member = new Client($config['url']);
$member->get('/login')->post('/login', ['username' => 'member', 'password' => 'slimbudget-demo']);
foreach (['/users', '/settings', '/catalogs/groups', "/months/$ym/estimates"] as $path) {
    $check("member 403 $path", $member->get($path)->status === 403);
}
$check('member sees trends', $member->get("/years/$year/trends")->status === 200);
$check('member cannot change statement dates', $member->post('/cards/1/cycles', ['closing_day' => '9'])->status === 403);
$check('member POST 403', $member->post('/catalogs/groups', ['name' => 'X'])->status === 403);
$check('member records expenses as themselves', (bool) preg_match('/required checked> Riley/', $member->get("/months/$ym/expenses")->body));
$db->run("UPDATE users SET active=0 WHERE username='member'");
$check('deactivation applies at once', $member->get("/months/$ym")->path === '/login');
$db->run("UPDATE users SET active=1 WHERE username='member'");
$guess = new Client($config['url']);
$guess->get('/login');
for ($i = 0; $i < 6; $i++) {
    $guess->post('/login', ['username' => 'member', 'password' => 'nope']);
}
$check('lockout', $guess->sees('Too many attempts'));
$check('lockout holds with the right password', $guess->post('/login', ['username' => 'member', 'password' => 'slimbudget-demo'])->sees('Too many attempts'));
$db->run('DELETE FROM login_attempts');
$db->run("UPDATE users SET must_change_password=1 WHERE username='member'");
$member->get('/login')->post('/login', ['username' => 'member', 'password' => 'slimbudget-demo']);
$check('temporary password must change', $member->path === '/profile');
$check('short password', $member->post('/profile/password', ['current_password' => 'slimbudget-demo', 'password' => 'short', 'password_confirm' => 'short'])->sees('at least 10 characters'));
$member->post('/profile/password', ['current_password' => 'slimbudget-demo', 'password' => 'a-longer-one', 'password_confirm' => 'a-longer-one']);
$check('password changed', $member->path === "/months/$ym", $member->path);
$admin->get("/months/$ym")->post('/logout');
$check('logs out', $admin->path === '/login' && $admin->get("/months/$ym")->path === '/login');
$admin->get('/login')->post('/login', ['username' => 'demo', 'password' => 'slimbudget-demo']);
$check('logging out does not pin a language', $db->value("SELECT locale FROM users WHERE username='demo'") === null);


// Members: their own expenses only, no incomes, no opening months
$db->run('DELETE FROM login_attempts');
$member->get("/months/$ym/expenses");
$member->post("/months/$ym/expenses", ['description' => 'Mine', 'amount' => '10'] + $expense);
$mine = $id("SELECT id FROM expenses WHERE description='Mine'");
$theirs = $id('SELECT MIN(id) FROM expenses WHERE created_by IS NULL');
$check('member edits their own', $member->get("/expenses/$mine")->status === 200 && $member->post("/expenses/$mine", ['description' => 'Mine too', 'amount' => '11'] + $expense)->sees('Expense updated.'));
$check('member cannot see the form of another', $member->get("/expenses/$theirs")->status === 403);
$member->get("/months/$ym/expenses");
$check('nor change it', $member->post("/expenses/$theirs", $expense)->status === 403 && $member->get("/months/$ym/expenses")->post("/expenses/$theirs/delete")->status === 403 && $id('SELECT COUNT(*) FROM expenses WHERE id=?', [$theirs]) === 1);
$check('nor gets a link to it', !str_contains($member->get("/months/$ym/expenses")->body, "\"/expenses/$theirs\"") && str_contains($member->body, "\"/expenses/$mine\""));
$check('member deletes their own', $member->post("/expenses/$mine/delete")->sees('Expense deleted') && $id("SELECT COUNT(*) FROM audit_log WHERE action='expense_deleted' AND detail LIKE '%Mine too%'") === 1);
$income = $id('SELECT MIN(id) FROM incomes');
foreach (["/months/$ym/incomes", "/incomes/$income/delete", '/months/2031-05/open'] as $path) {
    $check("member 403 $path", $member->get("/months/$ym")->post($path, ['kind' => 'other', 'amount' => '1'])->status === 403);
}
$check('member sees no income form', !str_contains($member->get("/months/$ym")->body, '/incomes'));
$member->get("/months/$ym/expenses");
$check('member cannot open a month with an expense', $member->post("/months/$ym/expenses", ['spent_on' => '2031-05-02', 'description' => 'Future'] + $expense)->sees('an admin has to open it') && $id("SELECT COUNT(*) FROM months WHERE ym='2031-05'") === 0);
$check('nor with the bills', $member->post('/months/2031-05/bills/generate')->sees('an admin has to open it') && $id("SELECT COUNT(*) FROM months WHERE ym='2031-05'") === 0);

// Strange input never ends in an error page
$admin->get("/months/$ym/expenses");
foreach (['arrays' => ['description' => ['a' => 'b'], 'amount' => ['9']], 'invalid UTF-8' => ['description' => "\xff\xfe"], 'null byte' => ['description' => "a\0b"], 'huge id' => ['category_id' => '99999999999999']] as $name => $data) {
    $admin->post("/months/$ym/expenses", $data + $expense);
    $check("strange input: $name", $admin->status === 200 && $admin->path === "/months/$ym/expenses" && !preg_match('/(Warning|Deprecated|Notice):/', $admin->body), $admin->status);
}
$check('headers', (static function () use ($config): bool {
    $headers = array_change_key_case(get_headers($config['url'] . '/login', true));
    return isset($headers['permissions-policy'], $headers['cross-origin-opener-policy'], $headers['content-security-policy']) && !isset($headers['strict-transport-security']);
})());

// Passwords and sessions
$sign = static function (string $username, string $password) use ($config): Client {
    $client = new Client($config['url']);
    return $client->get('/login')->post('/login', ['username' => $username, 'password' => $password]);
};
$change = static fn (Client $c, string $current, string $new): Client => $c->get('/profile')->post('/profile/password', ['current_password' => $current, 'password' => $new, 'password_confirm' => $new]);
$stolen = $sign('member', 'a-longer-one');
$check('two sessions at once', $stolen->path === "/months/$ym");
$check('same password', $change($member, 'a-longer-one', 'a-longer-one')->sees('must be different'));
$check('weak password', $change($member, 'a-longer-one', '1111111111')->sees('too easy to guess'));
$check('null byte in a password', $change($member, 'a-longer-one', "null\0inside-it")->sees('up to 72 bytes'));
$change($member, 'a-longer-one', 'another-long-one');
$check('changing the password keeps this session and says so', $member->path === "/months/$ym" && $member->sees('Password changed.'));
$check('and closes the others', $stolen->get("/months/$ym")->path === '/login');
$stolen = $sign('member', 'another-long-one');
$member->get('/profile')->post('/profile/sessions');
$check('signing out everywhere else', $member->sees('Your other sessions were closed') && $stolen->get("/months/$ym")->path === '/login');
for ($i = 0; $i < 6; $i++) {
    $change($member, "wrong$i", 'yet-another-long-one');
}
$check('guessing the current password is limited', $member->sees('Too many attempts') && $change($member, 'another-long-one', 'yet-another-long-one')->sees('Too many attempts'));
$db->run('DELETE FROM login_attempts');
$memberId = $id("SELECT id FROM users WHERE username='member'");
$edit = ['username' => 'member', 'name' => 'Demo Member', 'role' => 'member', 'person_id' => 2, 'active' => 1];
$admin->get("/users/$memberId")->post("/users/$memberId", $edit + ['reset_password' => 1]);
$check('a reset closes the sessions', preg_match('/Temporary password: (\S+) \(/', html_entity_decode($admin->body), $m) === 1 && $member->get("/months/$ym")->path === '/login');
$check('temporary password works', $sign('member', $m[1])->path === '/profile');
$db->run('UPDATE users SET password_expires_at=NOW() - INTERVAL 2 DAY WHERE id=?', [$memberId]);
$check('temporary password expires', $sign('member', $m[1])->sees('temporary password expired'));
$adminHash = $db->value("SELECT password_hash FROM users WHERE username='demo'");
$admin->get('/users/1')->post('/users/1', ['username' => 'demo', 'name' => 'Demo Admin', 'role' => 'admin', 'person_id' => 1, 'active' => 1, 'reset_password' => 1, 'two_step_off' => 1]);
$check('no resetting yourself without the password', $db->value("SELECT password_hash FROM users WHERE username='demo'") === $adminHash);
$db->run('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?', [password_hash('slimbudget-demo', PASSWORD_DEFAULT), $memberId]);

// Sign-in limits. The throttle is tried directly: attempts that are still being checked already count
$throttle = new App\Services\Throttle($db);
$db->run('DELETE FROM login_attempts');
$passed = count(array_filter(array_map(static fn (): ?int => $throttle->attempt('alex', '203.0.113.1'), range(1, 40))));
$check('simultaneous attempts share the limit', $passed === 5, $passed);
$db->run('DELETE FROM login_attempts');
$passed = count(array_filter(array_map(static fn (int $i): ?int => $throttle->attempt("user$i", '203.0.113.1'), range(1, 40))));
$check('many accounts from one address', $passed === 20, $passed);
$db->run('DELETE FROM login_attempts');
$passed = count(array_filter(array_map(static fn (int $i): ?int => $throttle->attempt('alex', "203.0.113.$i"), range(1, 60))));
$check('one account from many addresses', $passed === 30, $passed);
$check('a known address still gets in', $throttle->attempt('alex', '198.51.100.7', null) !== null && $throttle->attempt('alex', '198.51.100.7') === null);
$throttle->clear('alex', '203.0.113.1');
$check('signing in forgives one address only', $id("SELECT COUNT(*) FROM login_attempts WHERE username='alex'") === 30);
$db->run('DELETE FROM login_attempts');
$sign("mem\nber", 'nope');
$sign('member', 'nope');
$log = (string) file_get_contents("{$config['var_dir']}/log/auth.log");
$check('failures are logged', str_contains($log, 'failed login user=member ip=127.0.0.1') && str_contains($log, 'user=mem?ber'));
$check('logs are private', (fileperms("{$config['var_dir']}/log/auth.log") & 0007) === 0);
$db->run('DELETE FROM login_attempts');

// Two-step verification
$totp = static fn (string $secret, int $steps = 0): string => OTPHP\TOTP::createFromSecret($secret, new App\Services\Clock())->at(time() + 30 * $steps);
$member = $sign('member', 'slimbudget-demo');
$other = $sign('member', 'slimbudget-demo');
$member->get('/profile/two-step');
$check('set up shows a QR and the key', str_contains($member->body, 'data:image/svg+xml;base64,') && preg_match('/<code>([A-Z2-7 ]+)<\/code>/', $member->body, $m) === 1);
$secret = str_replace(' ', '', $m[1]);
$check('needs the password', $member->post('/profile/two-step', ['code' => $totp($secret), 'current_password' => 'nope'])->sees('current password is not right'));
$check('needs a right code', $member->post('/profile/two-step', ['code' => '000000', 'current_password' => 'slimbudget-demo'])->sees('That code is not right'));
$member->post('/profile/two-step', ['code' => $totp($secret), 'current_password' => 'slimbudget-demo']);
preg_match_all('/<code>([0-9-]{19})<\/code>/', $member->body, $codes);
$check('turned on with ten recovery codes', $member->sees('is on') && count($codes[1]) === 10);
$check('codes are shown once', !preg_match('/<code>[0-9-]{19}/', $member->get('/profile/two-step')->body) && $member->sees('10 recovery codes left'));
$check('turning it on closes the other sessions', $other->get("/months/$ym")->path === '/login');
$stored = (string) $db->value('SELECT totp_secret FROM users WHERE id=?', [$memberId]);
$check('the secret is encrypted', $stored !== '' && !str_contains($stored, $secret) && !str_contains((string) base64_decode($stored), $secret));
$db->run('UPDATE users SET locale=? WHERE id=?', ['es', $memberId]);
$half = $sign('member', 'slimbudget-demo');
$check('the code page speaks the user\'s language', $half->sees('Los 6 dígitos'));
$db->run('UPDATE users SET locale=NULL WHERE id=?', [$memberId]);
$check('the password alone is not enough', $half->path === '/login/code' && $half->get("/months/$ym")->path === '/login');
$check('wrong code', $half->get('/login/code')->post('/login/code', ['code' => '000000'])->sees('Wrong code'));
$check('a used code does not work again', $half->post('/login/code', ['code' => $totp($secret)])->sees('Wrong code'));
$check('the next code works', $half->post('/login/code', ['code' => $totp($secret, 1)])->path === "/months/$ym", $half->path);
$half = $sign('member', 'slimbudget-demo');
$check('recovery code', $half->post('/login/code', ['code' => str_replace('-', ' ', $codes[1][0])])->path === "/months/$ym" && $half->sees('9 left'));
$half = $sign('member', 'slimbudget-demo');
$check('recovery codes work once', $half->post('/login/code', ['code' => $codes[1][0]])->sees('Wrong code'));
for ($i = 0; $i < 5; $i++) {
    $half->post('/login/code', ['code' => "00000$i"]);
}
$check('codes are limited', $half->sees('Too many attempts') && $half->get('/login/code')->path === '/login');
$db->run('DELETE FROM login_attempts');
$half = $sign('member', 'slimbudget-demo');
$member->get('/profile/two-step')->post('/profile/two-step/codes', ['current_password' => 'slimbudget-demo']);
preg_match_all('/<code>([0-9-]{19})<\/code>/', $member->body, $renewed);
$check('new recovery codes replace the old', count($renewed[1]) === 10 && $half->post('/login/code', ['code' => $codes[1][1]])->sees('Wrong code'));
$check('turning it off needs the password', $member->get('/profile/two-step')->post('/profile/two-step/off', ['current_password' => 'nope'])->sees('current password is not right'));
$admin->get("/users/$memberId")->post("/users/$memberId", $edit + ['two_step_off' => 1]);
$check('an admin turns it off for someone', $db->value('SELECT totp_secret FROM users WHERE id=?', [$memberId]) === null && $id('SELECT COUNT(*) FROM recovery_codes') === 0 && $member->get("/months/$ym")->path === '/login');
$check('and the password is enough again', $sign('member', 'slimbudget-demo')->path === "/months/$ym");
$admin->get('/users');
$check('history', $admin->sees('Recent activity') && $admin->sees('User updated') && $admin->sees('member · two-step turned off') && $admin->sees('Expense deleted'));
$check('no raw keys in the history', !str_contains($admin->body, 'audit.actions.'));
foreach (['es' => 'Verificación en dos pasos', 'en' => 'Two-step verification'] as $locale => $text) {
    $admin->get('/profile')->post('/profile/language', ['locale' => $locale]);
    $check("two-step page in $locale", $admin->get('/profile/two-step')->sees($text) && !str_contains($admin->body, 'two_step.'));
}
$admin->get('/profile')->post('/profile/language', ['locale' => '']);

// Sessions end
$admin->get("/months/$ym");
foreach (glob("{$config['var_dir']}/sessions/sess_*") as $file) {
    file_put_contents($file, preg_replace('/started\|i:\d+/', 'started|i:' . (time() - 25 * 3600), (string) file_get_contents($file)));
}
$check('sessions last a day at most', $admin->get("/months/$ym")->path === '/login');

echo "$checks checks, $failures failures\n";
exit($failures ? 1 : 0);

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

$bill = $id('SELECT MIN(id) FROM bills');
$groupBill = $id('SELECT MIN(id) FROM bills WHERE group_id IS NOT NULL');
foreach (["/months/$ym", '/months/2031-01', "/months/$ym/expenses", "/months/$ym/expenses?q=a&person=1&category=x", "/months/$ym/cards", "/months/$ym/bills", "/months/$ym/installments", "/months/$ym/estimates", '/years/' . substr($ym, 0, 4), '/years/2031', '/years/' . substr($ym, 0, 4) . '/trends', '/years/' . substr($ym, 0, 4) . '/trends?fixed=0', '/years/2031/trends', '/review', '/profile', '/users', '/users/1', '/settings', '/catalogs/groups', '/catalogs/categories', '/catalogs/people', '/catalogs/cards', '/catalogs/bills', '/catalogs/categories/1', '/catalogs/bills/1', '/expenses/1', '/installments/1', "/bills/$bill", "/bills/$groupBill"] as $path) {
    $admin->get($path);
    $check("GET $path", $admin->status === 200 && !preg_match('/(Warning|Deprecated|Notice):/', $admin->body), [$admin->status, mb_substr(trim(strip_tags($admin->body)), -300)]);
    $check("no raw keys in $path", !preg_match('/>\s*(app|nav|fields|status|modes|login|profile|month|incomes|estimates|expenses|review|cards|bills|installments|year|trends|group_kinds|catalogs|users|settings|errors)\.[a-z_.]+\s*</', $admin->body, $m), $m[0] ?? '');
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

echo "$checks checks, $failures failures\n";
exit($failures ? 1 : 0);

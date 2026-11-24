<?php
declare(strict_types=1);

// Usage: php bin/seed.php starter [locale]   groups and categories from database/starter/<locale>.json
//        php bin/seed.php demo               fictional data, only on a database without categories
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database;
use App\Services\Budget;
use App\Services\Month;

$root = dirname(__DIR__);
$db = new Database(App\Config::load()['db']);
$command = $argv[1] ?? '';
$locale = $argv[2] ?? 'en';
$file = "$root/database/starter/" . basename($locale) . '.json';
if (!in_array($command, ['starter', 'demo'], true) || !is_file($file) || ($command === 'demo' && $locale !== 'en')) {
    fwrite(STDERR, "Usage: php bin/seed.php starter [locale] | demo\n");
    exit(1);
}
if ((int) $db->value('SELECT COUNT(*) FROM categories') > 0) {
    fwrite(STDERR, "The database already has categories: nothing was changed.\n");
    exit(1);
}

function seedStarter(Database $db, string $file): void
{
    foreach (json_decode(file_get_contents($file), true, 4, JSON_THROW_ON_ERROR) as $group => $categories) {
        $groupId = $db->insert('category_groups', ['name' => $group]);
        foreach ($categories as $name => $mode) {
            $db->insert('categories', ['group_id' => $groupId, 'name' => $name, 'mode' => $mode]);
        }
    }
}

function seedDemo(Database $db): void
{
    mt_srand(2026); // same data every time
    $category = array_column($db->all('SELECT name, id FROM categories'), 'id', 'name');
    $groups = array_column($db->all('SELECT name, id FROM category_groups'), 'id', 'name');
    $sam = $db->insert('people', ['name' => 'Sam']);
    $riley = $db->insert('people', ['name' => 'Riley', 'spending_is_income' => 1]);
    $blue = $db->insert('cards', ['name' => 'Blue Bank', 'alias' => 'Blue', 'category_id' => $category['Credit card'], 'closing_day' => 20, 'due_day' => 10]);
    $green = $db->insert('cards', ['name' => 'Green Card', 'alias' => 'Green', 'category_id' => $category['Credit card']]);
    foreach ([['demo', 'Demo Admin', 'admin', $sam], ['member', 'Demo Member', 'member', $riley]] as [$username, $name, $role, $person]) {
        $db->insert('users', ['username' => $username, 'name' => $name, 'role' => $role, 'person_id' => $person, 'must_change_password' => 0, 'password_hash' => password_hash('slimbudget-demo', PASSWORD_DEFAULT)]);
    }
    $now = Month::current();
    foreach ([
        [$blue, 'Laptop', 1080, 12, -4], [$blue, 'Washing machine', 540, 6, -2], [$green, 'Headphones', 120, 3, -1], [$green, 'Concert tickets', 180, 3, 1], [$green, 'Desk', 240, 4, -6],
    ] as [$card, $concept, $total, $count, $offset]) {
        $start = Month::add($now, $offset);
        $db->insert('installment_plans', ['card_id' => $card, 'concept' => $concept, 'total_amount' => $total, 'installments' => $count, 'start_month' => $start, 'end_month' => Month::add($start, $count - 1), 'purchased_on' => Month::date(Month::add($start, -1), 14), 'merchant' => 'Example Store']);
    }
    $templates = [['Rent', 1, 950, 'Rent'], ['Internet', 5, 45, 'Internet'], ['Mobile', 8, 30, 'Mobile'], ['Electricity', 12, 70, 'Electricity'], ['Video streaming', 15, 14, 'Video streaming'], ['Music', 20, 11, 'Music'], ['Emergency fund', 25, 200, 'Emergency fund']];
    foreach ($templates as [$name, $day, $amount, $cat]) {
        $db->insert('bill_templates', ['name' => $name, 'day' => $day, 'amount' => $amount, 'category_id' => $category[$cat]]);
    }
    $db->insert('bill_templates', ['name' => 'Food', 'day' => 28, 'amount' => 620, 'group_id' => $groups['Food']]);

    $fixed = ['Rent' => 950, 'Cleaning' => 40, 'Maintenance' => 25, 'Groceries' => 420, 'Delivery' => 90, 'Internet' => 45, 'Mobile' => 30, 'Electricity' => 70, 'Water' => 25, 'Video streaming' => 14, 'Music' => 11, 'Cloud storage' => 3, 'Ride hailing' => 60, 'Emergency fund' => 200];
    $random = [
        ['Groceries', 'Weekly groceries', 55, 120, 4], ['Delivery', 'Dinner delivery', 18, 34, 4], ['Coffee', 'Coffee', 4, 9, 5], ['Eating out', 'Lunch out', 22, 48, 2], ['Ride hailing', 'Ride home', 8, 19, 5],
        ['Public transport', 'Transit pass', 20, 20, 1], ['Books', 'Book', 12, 28, 1], ['Outings', 'Movie night', 18, 30, 1], ['Pharmacy', 'Pharmacy', 8, 40, 1], ['Personal care', 'Personal care', 12, 35, 2], ['Haircut', 'Haircut', 25, 25, 1], ['Gifts', 'Birthday gift', 25, 60, 1], ['Miscellaneous', 'Household items', 10, 45, 2],
    ];
    $budget = new Budget($db);
    for ($offset = -5; $offset <= 0; $offset++) {
        $ym = Month::add($now, $offset);
        $id = $budget->ensureMonth($ym);
        if ($offset === -5) {
            $db->insert('incomes', ['month_id' => $id, 'kind' => 'salary', 'description' => null, 'amount' => 1900]);
            foreach ($fixed as $name => $amount) {
                $db->run("UPDATE estimates SET mode='fixed', amount=? WHERE month_id=? AND category_id=?", [$amount, $id, $category[$name]]);
            }
        }
        $lastDay = $offset === 0 ? (int) date('j') : 28;
        $add = static function (string $cat, string $text, float $amount, int $day, int $person, ?int $card, ?int $bill = null) use ($db, $category, $ym, $id): void {
            $db->insert('expenses', ['month_id' => $id, 'spent_on' => Month::date($ym, $day), 'description' => $text, 'category_id' => $category[$cat], 'person_id' => $person, 'amount' => round($amount, 2), 'card_id' => $card, 'merchant' => $card ? 'Example Store' : null, 'bill_id' => $bill]);
        };
        foreach ($templates as [$name, $day, $amount, $cat]) {
            if ($day <= $lastDay) {
                $bill = (int) $db->value('SELECT id FROM bills WHERE month_id=? AND name=?', [$id, $name]);
                $add($cat, $name, $amount, $day, $sam, null, $bill);
            }
        }
        foreach ($random as [$cat, $text, $min, $max, $times]) {
            for ($i = 0; $i < $times; $i++) {
                $day = mt_rand(1, 28);
                $amount = mt_rand($min * 100, $max * 100) / 100;
                $person = mt_rand(1, 100) <= 60 ? $sam : $riley;
                $card = [null, $blue, $green][mt_rand(0, 2)];
                if ($day <= $lastDay) {
                    $add($cat, $text, $amount, $day, $person, $card);
                }
            }
        }
        $installments = (float) $db->value('SELECT COALESCE(SUM(total_amount/installments),0) FROM installment_plans WHERE start_month<=? AND end_month>=?', [$ym, $ym]);
        if ($installments > 0 && $lastDay >= 18) {
            $add('Credit card', 'Card payment', $installments, 18, $sam, null);
        }
    }
    $db->run("UPDATE expenses SET needs_review=1, note='Check the amount on the receipt' WHERE id=(SELECT id FROM (SELECT MAX(id) id FROM expenses WHERE card_id IS NOT NULL) x)");
}

$db->transaction(static function () use ($db, $command, $file): void {
    seedStarter($db, $file);
    if ($command === 'demo') {
        seedDemo($db);
    }
});
echo $command === 'demo' ? "Demo data loaded. Sign in as demo or member with the password slimbudget-demo.\n" : "Groups and categories loaded.\n";

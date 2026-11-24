<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\BillController;
use App\Controllers\CatalogController;
use App\Controllers\ExpenseController;
use App\Controllers\InstallmentController;
use App\Controllers\MonthController;
use App\Controllers\SettingsController;
use App\Controllers\UserController;
use App\Controllers\YearController;
use App\Middleware\Authenticate;
use App\Middleware\RequireAdmin;
use Slim\App;
use Slim\Routing\RouteCollectorProxy as Group;

return static function (App $app): void {
    $ym = '{ym:20\d{2}-(?:0[1-9]|1[0-2])}';
    $id = '{id:\d+}';

    $app->get('/login', [AuthController::class, 'form']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->post('/language', [AuthController::class, 'language']);

    $app->group('', function (Group $g) use ($ym, $id): void {
        $g->post('/logout', [AuthController::class, 'logout']);
        $g->get('/profile', [AuthController::class, 'profile']);
        $g->post('/profile/password', [AuthController::class, 'changePassword']);
        $g->post('/profile/language', [AuthController::class, 'changeLanguage']);

        $g->get('/', [MonthController::class, 'home']);
        $g->get("/months/$ym", [MonthController::class, 'show']);
        $g->post("/months/$ym/open", [MonthController::class, 'open']);
        $g->post("/months/$ym/incomes", [MonthController::class, 'addIncome']);
        $g->post("/incomes/$id/delete", [MonthController::class, 'deleteIncome']);

        $g->get("/months/$ym/expenses", [ExpenseController::class, 'index']);
        $g->post("/months/$ym/expenses", [ExpenseController::class, 'create']);
        $g->get("/months/$ym/cards", [ExpenseController::class, 'cards']);
        $g->get('/review', [ExpenseController::class, 'review']);
        $g->get("/expenses/$id", [ExpenseController::class, 'edit']);
        $g->post("/expenses/$id", [ExpenseController::class, 'update']);
        $g->post("/expenses/$id/delete", [ExpenseController::class, 'delete']);

        $g->get("/months/$ym/bills", [BillController::class, 'index']);
        $g->post("/months/$ym/bills/generate", [BillController::class, 'generate']);
        $g->get("/bills/$id", [BillController::class, 'show']);
        $g->post("/bills/$id", [BillController::class, 'update']);
        $g->post("/bills/$id/pay", [BillController::class, 'pay']);

        $g->get("/months/$ym/installments", [InstallmentController::class, 'index']);
        $g->post("/months/$ym/installments", [InstallmentController::class, 'create']);
        $g->get("/installments/$id", [InstallmentController::class, 'edit']);
        $g->post("/installments/$id", [InstallmentController::class, 'update']);
        $g->post("/installments/$id/delete", [InstallmentController::class, 'delete']);

        $g->get('/years/{year:20\d{2}}', [YearController::class, 'show']);

        $g->group('', function (Group $a) use ($ym, $id): void {
            $a->get("/months/$ym/estimates", [MonthController::class, 'estimates']);
            $a->post("/months/$ym/estimates", [MonthController::class, 'saveEstimates']);
            $a->get('/catalogs/{catalog}', [CatalogController::class, 'index']);
            $a->post('/catalogs/{catalog}', [CatalogController::class, 'create']);
            $a->get("/catalogs/{catalog}/$id", [CatalogController::class, 'edit']);
            $a->post("/catalogs/{catalog}/$id", [CatalogController::class, 'update']);
            $a->post("/catalogs/{catalog}/$id/delete", [CatalogController::class, 'delete']);
            $a->get('/users', [UserController::class, 'index']);
            $a->post('/users', [UserController::class, 'create']);
            $a->get("/users/$id", [UserController::class, 'edit']);
            $a->post("/users/$id", [UserController::class, 'update']);
            $a->get('/settings', [SettingsController::class, 'form']);
            $a->post('/settings', [SettingsController::class, 'save']);
        })->add(RequireAdmin::class);
    })->add(Authenticate::class);
};

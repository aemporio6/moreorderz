<?php
// Test helper: one "picker" process. Usage: php race_worker.php <product> <location> <qty> <saleId>
// Runs against the test database, outside any test transaction, to prove that two people can never take the same unit.
putenv('APP_ENV=testing'); putenv('DB_DATABASE=moreorderz_test'); $_ENV['APP_ENV'] = 'testing'; $_ENV['DB_DATABASE'] = 'moreorderz_test'; $_SERVER['DB_DATABASE'] = 'moreorderz_test';
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$_, $product, $loc, $qty, $sale] = $argv;
App\Services\Context::set(1, null);
try {
    usleep(random_int(0, 30000));
    App\Services\StockService::consumeForSale(1, (int) $sale, (int) $product, (int) $qty, (int) $loc);
    echo "OK\n";
} catch (Throwable $e) {
    echo 'REFUSED ' . $e->getMessage() . "\n";
}

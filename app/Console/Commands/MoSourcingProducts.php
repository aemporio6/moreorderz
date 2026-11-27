<?php

namespace App\Console\Commands;

use App\Services\SourcingProducts;
use Illuminate\Console\Command;

/** MR-1408: link every sourcing product to its product (by SKU) and share empty fields both ways. */
class MoSourcingProducts extends Command
{
    protected $signature = 'mo:sourcing-products {--company=1}';
    protected $description = 'Link sourcing products to the product master and share their empty fields';

    public function handle(): int
    {
        $n = SourcingProducts::syncAll((int) $this->option('company'));
        $this->info("Linked: {$n['linked']} · not linked (no single product with that SKU): {$n['not linked']}");
        return 0;
    }
}

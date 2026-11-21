<?php

namespace App\Console\Commands;

use App\Services\FinanceMonthly;
use App\Services\JobLog;
use Illuminate\Console\Command;

/** R-FIN-32 / R-FIN-50 monthly finance plan (1st of the month 08:00): partner monthly summaries – one lock, one run-log line. */
class MoFinanceMonthly extends Command
{
    protected $signature = 'mo:finance-monthly';
    protected $description = 'Monthly partner summaries (WhatsApp) with lock and run log';

    public function handle(): int
    {
        $sum = null;
        $r = JobLog::run('FIN_MONTHLY', null, function () use (&$sum) { $sum = FinanceMonthly::summaries(); return $sum['sent']; });
        $this->line("{$r['status']}" . ($sum ? " – sent {$sum['sent']}, not sent {$sum['not_sent']}, already sent {$sum['already']}" : ''));
        return 0;
    }
}

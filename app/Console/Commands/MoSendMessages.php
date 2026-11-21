<?php

namespace App\Console\Commands;

use App\Services\Connect\Messenger;
use Illuminate\Console\Command;

/** Sends queued WhatsApp messages and e-mails (every minute). Without a channel they wait as HELD. */
class MoSendMessages extends Command
{
    protected $signature = 'mo:send-messages {--max=100}';
    protected $description = 'Send queued WhatsApp messages and e-mails';

    public function handle(): int
    {
        $d = Messenger::sendDue((int) $this->option('max'));
        $this->line(collect($d)->map(fn ($v, $k) => "$k $v")->implode(' · '));
        $q = \App\Services\TmQueue::dispatch(200);      // R-G_CORE-46 task messages (quiet hours, claim, retry)
        $this->line('task messages: ' . collect($q)->map(fn ($v, $k) => "$k $v")->implode(' · '));
        return 0;
    }
}

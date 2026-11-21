<?php

namespace App\Services\Connect;

use Illuminate\Support\Facades\Log;

/** R-G_CORE-53 test provider "log": the message is written to the log only and marked SENT with the note "log provider" – nothing leaves the server. */
class WhatsAppLog extends Adapter
{
    public function test(): string { return 'Log provider – messages are written to the log only, nothing leaves the server.'; }
    public function send(string $phone, string $template, array $params): string { Log::info('WhatsApp (log provider) template', ['to' => '…' . substr(preg_replace('/\D/', '', $phone), -4), 'template' => $template, 'params' => $params]); return 'log-' . bin2hex(random_bytes(6)); }
    public function sendText(string $phone, string $text): string { Log::info('WhatsApp (log provider) text', ['to' => '…' . substr(preg_replace('/\D/', '', $phone), -4), 'text' => $text]); return 'log-' . bin2hex(random_bytes(6)); }
}

<?php

namespace App\Services\Connect;

/**
 * WhatsApp through 360dialog (R-G_CORE-53), built to 360dialog's published Cloud API contract (docs.360dialog.com):
 *  - send: POST https://waba-v2.360dialog.io/messages, header D360-API-KEY, the same message body as Meta's Cloud API (template or text).
 * Secret: api_key (kept only in the vault). Timeout 15 s. Built to contract and tested with 360dialog-shaped answers; NOT yet run against a real number.
 */
class WhatsApp360 extends Adapter
{
    private function base(): string { $this->need('api_key'); return rtrim((string) ($this->cfg['base'] ?? 'https://waba-v2.360dialog.io'), '/'); }
    private function post(array $body, string $what): string
    {
        $j = Api::ok($this->call('POST', $this->base() . '/messages', ['timeout' => 15, 'headers' => ['D360-API-KEY' => $this->s['api_key']], 'json' => ['messaging_product' => 'whatsapp'] + $body]), $what);
        return (string) ($j['messages'][0]['id'] ?? throw new ApiError('360dialog took the message but gave no id.'));
    }

    public function test(): string
    {
        Api::ok($this->call('GET', $this->base() . '/configs/webhook', ['timeout' => 15, 'headers' => ['D360-API-KEY' => $this->s['api_key']]]), '360dialog');
        return '360dialog answered – the API key works.';
    }

    public function send(string $phone, string $template, array $params): string
    {
        return $this->post(['to' => preg_replace('/\D/', '', $phone), 'type' => 'template', 'template' => ['name' => $template, 'language' => ['code' => $this->cfg['language'] ?? 'en'],
            'components' => $params ? [['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => mb_substr((string) $v, 0, 1000)], array_values($params))]] : []]], 'WhatsApp send');
    }

    public function sendText(string $phone, string $text): string
    {
        return $this->post(['to' => preg_replace('/\D/', '', $phone), 'type' => 'text', 'text' => ['body' => mb_substr($text, 0, 4000)]], 'WhatsApp reply');
    }
}

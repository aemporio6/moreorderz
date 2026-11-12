<?php

namespace App\Services\Connect;

/**
 * WhatsApp Cloud API (Meta), built to Meta's published contract (developers.facebook.com/docs/whatsapp/cloud-api):
 *  - send:    POST https://graph.facebook.com/{version}/{phone_number_id}/messages, Bearer access token
 *             a message that starts a conversation must be an approved template: {type: template, template{name, language{code}, components[{type: body, parameters[{type: text, text}]}]}}
 *  - webhook: GET verify (hub.mode=subscribe, hub.verify_token, hub.challenge); POST signed with X-Hub-Signature-256 = sha256 HMAC of the raw body with the app secret
 * Secrets: phone_number_id, access_token, app_secret, verify_token. Settings: version (v21.0), language (en).
 * Built to contract and tested with Meta-shaped answers; NOT yet run against a real WhatsApp Business number (templates must be approved by Meta first).
 */
class WhatsAppCloud extends Adapter
{
    private function url(string $tail = ''): string
    {
        $this->need('phone_number_id', 'access_token');
        return 'https://graph.facebook.com/' . ($this->cfg['version'] ?? 'v21.0') . '/' . rawurlencode($this->s['phone_number_id']) . $tail;
    }

    public function test(): string
    {
        $j = Api::ok($this->call('GET', $this->url(), ['headers' => ['Authorization' => 'Bearer ' . $this->s['access_token']], 'query' => ['fields' => 'display_phone_number,verified_name']]), 'WhatsApp number');
        return 'WhatsApp answered: ' . ($j['verified_name'] ?? '') . ' ' . ($j['display_phone_number'] ?? '');
    }

    /** Send one template message. Returns the provider's message id. */
    public function send(string $phone, string $template, array $params): string
    {
        $to = preg_replace('/\D/', '', $phone);
        $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => strtolower($template), 'language' => ['code' => $this->cfg['language'] ?? 'en'],
            'components' => $params ? [['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => mb_substr((string) $v, 0, 1000)], array_values($params))]] : []]];
        $j = Api::ok($this->call('POST', $this->url('/messages'), ['headers' => ['Authorization' => 'Bearer ' . $this->s['access_token']], 'json' => $body]), 'WhatsApp send');
        return (string) ($j['messages'][0]['id'] ?? throw new ApiError('WhatsApp took the message but gave no id.'));
    }

    public static function signatureOk(string $raw, ?string $header, string $appSecret): bool
    {
        return $header !== null && $appSecret !== '' && hash_equals('sha256=' . hash_hmac('sha256', $raw, $appSecret), $header);
    }
}

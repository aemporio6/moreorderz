<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Owner 27-11 09:26: beside every marketplace, bank, carrier, app, payment service, printer and own brand – its small current logo.
 *  - A service's logo is its own current site icon, fetched by the server from the service's web domain and kept (so it stays the latest:
 *    fetched again every month). Nothing is drawn by hand.
 *  - An own brand's logo is the owner's own picture (uploaded on My brands / Logos), never fetched.
 *  - The owner can upload his own picture for any row, change the domain, or add a service (+ Add) – one row, no new screen.
 *  - Names are matched by name and aliases as whole words, so "Amazon UK", "AMZ-UK Bymo" or "eBay - LAMNOON" all find their logo.
 */
class Logos
{
    /** kind => [name => [domain, aliases]] – the services this tool works with; marketplaces / banks / carriers in the lists are matched to these. */
    public const KNOWN = [
        'MARKETPLACE' => [
            'Amazon' => ['amazon.co.uk', 'amz,amazon uk,amazon de,amazon fr,amazon it,amazon es,amazon us,amazon business,fba,seller central'], 'eBay' => ['ebay.co.uk', 'ebay uk,ebay de,ebay us'],
            'TikTok Shop' => ['tiktok.com', 'tiktok,tik tok,tts'], 'Etsy' => ['etsy.com', ''], 'OnBuy' => ['onbuy.com', 'on buy'], 'Vinted' => ['vinted.co.uk', ''], 'Temu' => ['temu.com', ''],
            'Shopify' => ['shopify.com', ''], 'Walmart' => ['walmart.com', ''], 'Wayfair' => ['wayfair.co.uk', ''], 'Fruugo' => ['fruugo.co.uk', ''], 'ManoMano' => ['manomano.co.uk', ''],
            'Shein' => ['shein.com', ''], 'Depop' => ['depop.com', ''], 'Facebook Marketplace' => ['facebook.com', 'facebook,fb shop'], 'Debenhams' => ['debenhams.com', ''], 'B&Q' => ['diy.com', 'b and q,bnq'],
            'Alibaba' => ['alibaba.com', ''], '1688' => ['1688.com', ''], 'AliExpress' => ['aliexpress.com', 'ali express'], 'Made-in-China' => ['made-in-china.com', 'made in china'],
        ],
        'MONEY' => [
            'Wise' => ['wise.com', 'transferwise'], 'WorldFirst' => ['worldfirst.com', 'world first'], 'PayPal' => ['paypal.com', ''], 'Stripe' => ['stripe.com', ''], 'Payoneer' => ['payoneer.com', ''],
            'Airwallex' => ['airwallex.com', ''], 'Revolut' => ['revolut.com', ''], 'Starling' => ['starlingbank.com', 'starling bank'], 'Monzo' => ['monzo.com', ''], 'Tide' => ['tide.co', 'tide bank'],
            'HSBC' => ['hsbc.co.uk', 'hsbc uk,hsbc bank'], 'Barclays' => ['barclays.co.uk', 'barclaycard'], 'Lloyds' => ['lloydsbank.com', 'lloyds bank,lloyds tsb'], 'NatWest' => ['natwest.com', 'nat west'],
            'Santander' => ['santander.co.uk', ''], 'Halifax' => ['halifax.co.uk', ''], 'Metro Bank' => ['metrobankonline.co.uk', ''], 'TSB' => ['tsb.co.uk', ''], 'Nationwide' => ['nationwide.co.uk', ''],
            'American Express' => ['americanexpress.com', 'amex'], 'Meezan Bank' => ['meezanbank.com', 'meezan'], 'HBL' => ['hbl.com', 'habib bank'], 'UBL' => ['ubldigital.com', 'united bank'],
            'MCB' => ['mcb.com.pk', 'muslim commercial'], 'Bank Alfalah' => ['bankalfalah.com', 'alfalah'], 'Allied Bank' => ['abl.com', ''], 'JazzCash' => ['jazzcash.com.pk', 'jazz cash'], 'Easypaisa' => ['easypaisa.com.pk', 'easy paisa'],
            'Alipay' => ['alipay.com', ''], 'WeChat Pay' => ['pay.weixin.qq.com', 'wechatpay'], 'HMRC' => ['gov.uk', 'hm revenue'],
        ],
        'APP' => [
            'WhatsApp' => ['whatsapp.com', 'whats app'], 'WeChat' => ['wechat.com', 'weixin'], 'Gmail' => ['mail.google.com', 'google mail'], 'Google Drive' => ['drive.google.com', 'gdrive'],
            'Google Sheets' => ['sheets.google.com', ''], 'Google Calendar' => ['calendar.google.com', ''], 'Google Docs' => ['docs.google.com', ''], 'Outlook' => ['outlook.com', 'hotmail,office 365'],
            'OneDrive' => ['onedrive.live.com', 'one drive'], 'Excel' => ['microsoft.com', 'microsoft excel'], 'Dropbox' => ['dropbox.com', ''], 'Telegram' => ['telegram.org', ''], 'Slack' => ['slack.com', ''],
            'Xero' => ['xero.com', ''], 'QuickBooks' => ['quickbooks.intuit.com', 'quick books'], 'Companies House' => ['find-and-update.company-information.service.gov.uk', ''],
        ],
        'PRINTER' => ['Zebra' => ['zebra.com', ''], 'Brother' => ['brother.co.uk', ''], 'Dymo' => ['dymo.com', ''], 'Rollo' => ['rollo.com', ''], 'HP' => ['hp.com', 'hewlett'], 'Epson' => ['epson.co.uk', ''], 'Canon' => ['canon.co.uk', '']],
    ];

    public const KINDS = ['MARKETPLACE' => 'Marketplaces & suppliers', 'MONEY' => 'Banks & payments', 'CARRIER' => 'Carriers & forwarders', 'APP' => 'Apps & connections', 'PRINTER' => 'Printers', 'BRAND' => 'Our brands', 'OTHER' => 'Other'];

    /** Make sure every known service, every carrier of the tracking list and every own brand has its row (no picture yet = fetched by mo:logos). */
    public static function sync(int $ent = 1): int
    {
        $n = 0;
        $put = function (string $kind, string $name, ?string $domain, string $aliases = '', ?string $refT = null, ?int $refId = null, int $e = 0) use (&$n) {
            $row = DB::table('sys_logos')->where('fk_id_ent', $e)->where('kind', $kind)->where('name', $name)->first();
            if (! $row) { DB::table('sys_logos')->insert(['fk_id_ent' => $e, 'kind' => $kind, 'name' => $name, 'domain' => $domain ?: null, 'aliases' => $aliases ?: null, 'ref_table' => $refT, 'ref_id' => $refId, 'created_at' => now(), 'updated_at' => now()]); $n++; }
            elseif (! $row->domain && $domain && ! $row->f_own) DB::table('sys_logos')->where('id', $row->id)->update(['domain' => $domain, 'updated_at' => now()]);
        };
        foreach (self::KNOWN as $kind => $list) foreach ($list as $name => [$d, $a]) $put($kind, $name, $d, $a);
        if (DB::getSchemaBuilder()->hasTable('cat_tracking_carriers'))
            foreach (DB::table('cat_tracking_carriers')->where('f_active', 1)->whereIn('fk_id_ent', [0, $ent])->get(['name', 'url_pattern', 'aliases']) as $c) {
                $host = parse_url(str_replace('{n}', 'x', (string) $c->url_pattern), PHP_URL_HOST); $put('CARRIER', (string) $c->name, $host ? preg_replace('/^(www|track|tracking|parcel|apps)\./i', '', $host) : null, (string) $c->aliases);
            }
        foreach (DB::table('cat_brands')->where('fk_id_ent', $ent)->where('f_own', 1)->where('f_active', 1)->get(['id', 'brand_name', 'website']) as $b) {
            $row = DB::table('sys_logos')->where('kind', 'BRAND')->where('ref_table', 'cat_brands')->where('ref_id', $b->id)->first();
            if (! $row) $put('BRAND', trim((string) $b->brand_name), null, '', 'cat_brands', (int) $b->id, $ent);
            elseif ($row->name !== trim((string) $b->brand_name)) DB::table('sys_logos')->where('id', $row->id)->update(['name' => trim((string) $b->brand_name), 'updated_at' => now()]);
        }
        return $n;
    }

    /** Fetch the current site icon of each service that has a domain and no own picture: missing ones, and those older than 30 days ($all = every one). */
    public static function fetchAll(bool $all = false, int $max = 300): array
    {
        $ok = 0; $bad = 0;
        $q = DB::table('sys_logos')->where('f_own', 0)->where('f_active', 1)->whereNotNull('domain')->where('domain', '<>', '')
            ->when(! $all, fn ($q) => $q->where(fn ($w) => $w->whereNull('fetched_at')->orWhere('fetched_at', '<', now()->subDays(30))))->limit($max);
        foreach ($q->get(['id', 'domain']) as $r) self::fetch((int) $r->id) ? $ok++ : $bad++;
        return ['fetched' => $ok, 'not_found' => $bad];
    }

    /** One service: the site's own icon (largest offered), through the site itself first, then a public icon service. Never throws. */
    public static function fetch(int $id): bool
    {
        $r = DB::table('sys_logos')->where('id', $id)->first(); if (! $r || $r->f_own || ! $r->domain) return false;
        $d = strtolower(preg_replace('~^https?://|/.*$~i', '', trim((string) $r->domain)));
        $best = null; $note = 'no icon found';
        try {
            foreach (self::candidates($d) as $url) {
                $img = self::image($url); if (! $img) continue;
                if (! $best || $img['w'] > $best['w']) $best = $img; if ($best['w'] >= 96) break;
            }
        } catch (\Throwable $e) { $note = mb_substr($e->getMessage(), 0, 190); }
        if ($best && $best['w'] >= 16) { DB::table('sys_logos')->where('id', $id)->update(['image' => $best['bytes'], 'mime' => $best['mime'], 'fetched_at' => now(), 'fetch_note' => 'from ' . mb_substr($best['url'], 0, 180), 'updated_at' => now()]); return true; }
        DB::table('sys_logos')->where('id', $id)->update(['fetched_at' => now(), 'fetch_note' => $note, 'updated_at' => now()]);
        return false;
    }

    /** Icon links the site itself names (apple-touch-icon / icon with sizes), then /favicon.ico, then the public icon service. */
    private static function candidates(string $d): array
    {
        $out = [];
        try {
            $res = Http::timeout(8)->withHeaders(['User-Agent' => 'Mozilla/5.0 (MoreOrderz logo fetch)'])->get('https://' . $d . '/');
            if ($res->ok()) {
                $base = 'https://' . $d . '/'; $html = mb_substr((string) $res->body(), 0, 300000); $found = [];
                preg_match_all('~<link\b[^>]*>~i', $html, $m);
                foreach ($m[0] as $tag) {
                    if (! preg_match('~rel=["\']([^"\']+)["\']~i', $tag, $rel) || ! preg_match('~icon~i', $rel[1]) || ! preg_match('~href=["\']([^"\']+)["\']~i', $tag, $h)) continue;
                    $size = preg_match('~sizes=["\'](\d+)x\d+~i', $tag, $s) ? (int) $s[1] : (stripos($rel[1], 'apple') !== false ? 180 : 32);
                    $found[self::abs(html_entity_decode($h[1]), $base)] = $size;
                }
                arsort($found); $out = array_keys(array_slice($found, 0, 4, true));
            }
        } catch (\Throwable $e) {}
        $out[] = 'https://' . $d . '/apple-touch-icon.png'; $out[] = 'https://' . $d . '/favicon.ico';
        $out[] = 'https://www.google.com/s2/favicons?sz=128&domain=' . rawurlencode($d);
        return array_values(array_unique($out));
    }

    private static function abs(string $u, string $base): string
    {
        if (preg_match('~^https?://~i', $u)) return $u; if (str_starts_with($u, '//')) return 'https:' . $u;
        $p = parse_url($base); return $p['scheme'] . '://' . $p['host'] . (str_starts_with($u, '/') ? $u : '/' . $u);
    }

    /** A picture at a link: its bytes, type and width (svg counts as large). */
    private static function image(string $url): ?array
    {
        try { $res = Http::timeout(8)->withHeaders(['User-Agent' => 'Mozilla/5.0 (MoreOrderz logo fetch)'])->get($url); } catch (\Throwable $e) { return null; }
        if (! $res->ok()) return null; $b = (string) $res->body(); if ($b === '' || strlen($b) > 400000) return null;
        $mime = strtolower(trim(explode(';', (string) $res->header('Content-Type'))[0]));
        if (str_contains($mime, 'svg') || str_starts_with(ltrim($b), '<svg')) return str_contains(strtolower($b), '<script') ? null : ['bytes' => $b, 'mime' => 'image/svg+xml', 'w' => 128, 'url' => $url];
        $info = @getimagesizefromstring($b); if (! $info) return null;
        return ['bytes' => $b, 'mime' => $info['mime'] ?: $mime, 'w' => (int) $info[0], 'url' => $url];
    }

    /** What the screens need: id, name, words to match, kind, version (for the browser cache). Only rows with a picture. */
    public static function boot(int $ent): array
    {
        return DB::table('sys_logos')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->whereNotNull('image')
            ->get(['id', 'kind', 'name', 'aliases', 'updated_at'])->map(fn ($r) => ['id' => (int) $r->id, 'k' => $r->kind, 'n' => $r->name,
                'a' => array_values(array_filter(array_map('trim', explode(',', strtolower((string) $r->aliases))))), 'v' => substr(md5((string) $r->updated_at), 0, 6)])->all();
    }

    /** The Logos screen: every row with its state. */
    public static function page(int $ent): array
    {
        $rows = DB::table('sys_logos')->whereIn('fk_id_ent', [0, $ent])->orderBy('kind')->orderBy('name')
            ->get(['id', 'kind', 'name', 'aliases', 'domain', 'f_own', 'f_active', 'fetched_at', 'fetch_note', 'updated_at', DB::raw('image IS NOT NULL AS has_image'), DB::raw('image_dark IS NOT NULL AS has_dark')]);
        return ['kinds' => self::KINDS, 'rows' => $rows->map(fn ($r) => (array) $r + ['v' => substr(md5((string) $r->updated_at), 0, 6)])->all(),
            'missing' => $rows->where('has_image', 0)->where('f_active', 1)->count()];
    }

    /** Owner: add a service, or change a row (name, domain, words, own picture as a data: URL, dark picture, back to the site's own icon). */
    public static function save(int $ent, ?int $id, array $in, ?int $user): array
    {
        $row = $id ? DB::table('sys_logos')->where('id', $id)->whereIn('fk_id_ent', [0, $ent])->first() : null;
        if ($id && ! $row) throw new \InvalidArgumentException('This logo row is not there any more.');
        $set = ['updated_by' => $user, 'updated_at' => now()];
        if (array_key_exists('name', $in)) { $nm = trim((string) $in['name']); if ($nm === '' || mb_strlen($nm) > 120) throw new \InvalidArgumentException('Give a name (up to 120 letters).'); $set['name'] = $nm; }
        if (array_key_exists('domain', $in)) { $d = strtolower(preg_replace('~^https?://|/.*$~i', '', trim((string) $in['domain']))); if ($d !== '' && ! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d)) throw new \InvalidArgumentException('The web address should look like example.com'); $set['domain'] = $d ?: null; }
        if (array_key_exists('aliases', $in)) $set['aliases'] = mb_substr(trim((string) $in['aliases']), 0, 400) ?: null;
        if (array_key_exists('kind', $in)) $set['kind'] = isset(self::KINDS[$in['kind']]) ? $in['kind'] : 'OTHER';
        if (array_key_exists('f_active', $in)) $set['f_active'] = (int) (bool) $in['f_active'];
        foreach (['image' => 'image', 'image_dark' => 'image_dark'] as $k => $col) if (! empty($in[$k])) {
            [$bytes, $mime] = self::dataUrl((string) $in[$k]); $set[$col] = $bytes; if ($col === 'image') { $set['mime'] = $mime; $set['f_own'] = 1; $set['fetch_note'] = 'own picture'; }
        }
        if (! empty($in['remove_dark'])) $set['image_dark'] = null;
        if (! empty($in['site_icon'])) { $set['f_own'] = 0; $set['image'] = null; $set['fetched_at'] = null; }
        if ($row) DB::table('sys_logos')->where('id', $row->id)->update($set);
        else {
            if (empty($set['name'])) throw new \InvalidArgumentException('Give a name.');
            if (DB::table('sys_logos')->where('fk_id_ent', $ent)->where('kind', $set['kind'] ?? 'OTHER')->where('name', $set['name'])->exists()) throw new \InvalidArgumentException('This name is already in the list.');
            $id = (int) DB::table('sys_logos')->insertGetId($set + ['fk_id_ent' => $ent, 'kind' => $set['kind'] ?? 'OTHER', 'created_at' => now()]);
        }
        $r = DB::table('sys_logos')->where('id', $id)->first();
        if (! $r->f_own && $r->domain && ! $r->image) self::fetch($id);
        return ['ok' => true, 'id' => $id, 'logos' => self::boot($ent)];
    }

    /** data:image/png;base64,… → bytes; png / jpeg / webp / svg only, up to 1.5 MB; svg with script refused. */
    public static function dataUrl(string $u): array
    {
        if (! preg_match('~^data:(image/(png|jpeg|webp|svg\+xml));base64,([A-Za-z0-9+/=\s]+)$~', $u, $m)) throw new \InvalidArgumentException('Choose a PNG, JPG, WEBP or SVG picture.');
        $b = base64_decode(preg_replace('/\s+/', '', $m[3]), true); if ($b === false || strlen($b) > 1572864) throw new \InvalidArgumentException('The picture is too big (1.5 MB at most).');
        if ($m[1] === 'image/svg+xml') { if (preg_match('~<script|on\w+=|javascript:~i', $b)) throw new \InvalidArgumentException('This SVG has code inside – save it again as a plain picture.'); }
        elseif (! @getimagesizefromstring($b)) throw new \InvalidArgumentException('This file is not a picture.');
        return [$b, $m[1]];
    }

    /** The picture itself (dark version when asked and there is one). */
    public static function picture(int $id, bool $dark = false): ?array
    {
        $r = DB::table('sys_logos')->where('id', $id)->first(['image', 'image_dark', 'mime']); if (! $r || ! $r->image) return null;
        if ($dark && $r->image_dark) return [$r->image_dark, @getimagesizefromstring($r->image_dark)['mime'] ?? 'image/png'];
        return [$r->image, $r->mime ?: 'image/png'];
    }
}

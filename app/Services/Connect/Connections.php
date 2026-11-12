<?php

namespace App\Services\Connect;

use App\Services\Audit;
use App\Services\Context;
use App\Services\MarketplaceFiles;
use App\Services\Numbering;
use App\Services\SalesService;
use App\Services\TaskEngine;
use App\Services\Vault;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Outside connections (08 §4, IC-0). A connection = one row of api_connections: which adapter, for which shop or carrier, plain settings,
 * and its keys – kept only encrypted in the vault (api_connections.token_ref), never shown again after saving.
 * Every pull writes an import batch (source API) and a job run, and is booked by the same code as an uploaded file.
 */
class Connections
{
    /** adapter code → [kind, class, name, secret keys, settings with defaults, what it does, honest state] */
    public const ADAPTERS = [
        'FILE' => ['MARKETPLACE', FileOnly::class, 'Files only (upload or Drive folder)', [], [], 'Orders and money by file', 'Works today'],
        'EBAY_API' => ['MARKETPLACE', EbayApi::class, 'eBay Sell APIs', ['client_id' => 'App ID (client id)', 'client_secret' => 'Cert ID (client secret)', 'refresh_token' => 'User refresh token'], ['marketplace_id' => 'EBAY_GB', 'sandbox' => '0'], 'Orders + money (Finances API)', 'Built to contract, not tested on a real account'],
        'AMAZON_SPAPI' => ['MARKETPLACE', AmazonSpApi::class, 'Amazon Selling Partner API', ['client_id' => 'LWA client id', 'client_secret' => 'LWA client secret', 'refresh_token' => 'Seller refresh token'], ['endpoint' => 'https://sellingpartnerapi-eu.amazon.com', 'marketplace_ids' => 'A1F83G8C2ARO7P'], 'Orders (no buyer address) + money (financial event groups)', 'Built to contract, not tested on a real account'],
        'TIKTOK_SHOP_API' => ['MARKETPLACE', TikTokShopApi::class, 'TikTok Shop Partner API', ['app_key' => 'App key', 'app_secret' => 'App secret', 'access_token' => 'Access token', 'refresh_token' => 'Refresh token'], ['shop_cipher' => '', 'base' => 'https://open-api.tiktokglobalshop.com'], 'Orders + money (statements)', 'Built to contract, not tested on a real account'],
        'ONBUY_API' => ['MARKETPLACE', OnBuyApi::class, 'OnBuy Seller API', ['consumer_key' => 'Consumer key', 'secret_key' => 'Secret key'], ['site_id' => '2000'], 'Orders only (OnBuy has no money API – money by file)', 'Built to contract, not tested on a real account'],
        'TEMU_API' => ['MARKETPLACE', TemuApi::class, 'Temu Seller Partner API', ['app_key' => 'App key', 'app_secret' => 'App secret', 'access_token' => 'Access token'], ['base' => 'https://openapi-b-eu.temu.com/openapi/router'], 'Orders only (Temu has no money API – statement by file)', 'Built to the partner contract, not tested on a real account'],
        'TRACK_LINK' => ['CARRIER', TrackLink::class, 'Tracking link (carrier\'s web page)', [], [], 'Opens the carrier\'s tracking page', 'Works today'],
        'ROYALMAIL_CLICKDROP' => ['CARRIER', RoyalMailClickDrop::class, 'Royal Mail Click & Drop', ['api_key' => 'API key'], ['base' => 'https://api.parcel.royalmail.com/api/v1', 'package_format' => 'parcel', 'default_service' => 'CRL48'], 'Buys labels for orders (PDF goes through Carrier labels)', 'Built to contract, not tested on a real account'],
        'ROYALMAIL_TRACKING' => ['CARRIER', RoyalMailTracking::class, 'Royal Mail Tracking API', ['client_id' => 'Client id', 'client_secret' => 'Client secret'], [], 'Where a parcel is (events)', 'Built to contract, not tested on a real account'],
        'GOOGLE_DRIVE' => ['STORAGE', GoogleDrive::class, 'Google Drive (service account)', ['service_account_json' => 'Service account key file (paste the whole JSON)'], ['root_folder_id' => ''], 'Makes record folders; reads watched report folders', 'Built to contract, not tested on the real Drive'],
        'WHATSAPP_CLOUD' => ['MESSAGING', WhatsAppCloud::class, 'WhatsApp Cloud API (Meta)', ['phone_number_id' => 'Phone number id', 'access_token' => 'Permanent access token', 'app_secret' => 'App secret (for the webhook)', 'verify_token' => 'Webhook verify token (any word you choose)'], ['version' => 'v21.0', 'language' => 'en'], 'Sends statements, approvals, task alerts; reads replies', 'Built to contract, not tested on a real number'],
    ];

    public static function mayManage(): bool { return Context::role() === 'OWNER'; }

    public static function row(int $id): object
    {
        $c = DB::table('api_connections')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Connection not found.');
        if (Context::ent() && (int) $c->fk_id_ent !== (int) Context::ent() && (int) $c->fk_id_ent !== 0) throw new \InvalidArgumentException('Connection not found.');
        return $c;
    }

    public static function secrets(object $c): array
    {
        $cell = DB::table('sys_vault')->where(['table_name' => 'api_connections', 'record_id' => $c->id, 'column_name' => 'token_ref'])->value('cipher');
        return $cell ? (json_decode(Crypt::decryptString($cell), true) ?: []) : [];
    }

    private static function keepSecrets(object $c, array $s): void
    {
        Vault::store('api_connections', (int) $c->id, ['token_ref' => json_encode($s)]);
        DB::table('api_connections')->where('id', $c->id)->update(['token_ref' => Vault::MASK]);
    }

    public static function adapter(object $c): Adapter
    {
        $a = self::ADAPTERS[$c->adapter] ?? throw new \InvalidArgumentException('Unknown adapter ' . $c->adapter . '.');
        $cfg = (json_decode((string) $c->settings_json, true) ?: []) + $a[4];
        if ($a[0] === 'CARRIER' && $c->fk_id_carrier) $cfg['tracking_url_pattern'] = (string) DB::table('t_carriers')->where('id', $c->fk_id_carrier)->value('tracking_url_pattern');
        return new $a[1]($c, self::secrets($c), $cfg);
    }

    /** Add or change a connection. Secrets typed here go straight into the vault; an empty secret field keeps the one kept before. */
    public static function save(array $in, ?int $id = null): int
    {
        if (! self::mayManage()) throw new \InvalidArgumentException('Only the Owner sets up connections.');
        $code = (string) ($in['adapter'] ?? ''); $a = self::ADAPTERS[$code] ?? throw new \InvalidArgumentException('Choose what to connect.');
        $ent = (int) Context::ent();
        $data = ['adapter' => $code, 'provider' => mb_substr($a[2], 0, 40), 'label' => mb_substr(trim((string) ($in['label'] ?? '')) ?: $a[2], 0, 120), 'fk_id_ent' => $ent,
            'settings_json' => json_encode(array_intersect_key(array_map('strval', (array) ($in['settings'] ?? [])), $a[4]) + $a[4])];
        if ($a[0] === 'MARKETPLACE') {
            $shop = DB::table('t_branches')->where('id', (int) ($in['shop'] ?? 0))->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Choose the shop this connection belongs to.');
            $data['fk_id_branch'] = $shop->id; $data['fk_id_mp_profile'] = $shop->fk_id_mp_profile; $data['fk_id_carrier'] = null;
        } elseif ($a[0] === 'CARRIER') {
            $data['fk_id_carrier'] = DB::table('t_carriers')->where('id', (int) ($in['carrier'] ?? 0))->value('id') ?? throw new \InvalidArgumentException('Choose the carrier this connection belongs to.');
            $data['fk_id_branch'] = null;
        } else { $data['fk_id_branch'] = null; $data['fk_id_carrier'] = null; }
        $id = DB::transaction(function () use ($id, $data, $in, $a) {
            if ($id) { self::row($id); DB::table('api_connections')->where('id', $id)->update($data); }
            else $id = DB::table('api_connections')->insertGetId($data + ['status' => $a[3] ? 'NEW' : 'CONNECTED', 'connected_by' => Context::userId(), 'connected_at' => now()]);
            $c = DB::table('api_connections')->where('id', $id)->first();
            $s = self::secrets($c); $typed = false;
            foreach (array_keys($a[3]) as $k) { $v = trim((string) ($in['secrets'][$k] ?? '')); if ($v !== '' && ! Vault::isMasked($v)) { $s[$k] = $v; $typed = true; } }
            if ($typed) { unset($s['access_expires']); self::keepSecrets($c, $s); if ($c->status !== 'OFF') DB::table('api_connections')->where('id', $id)->update(['status' => 'NEW']); }
            if ($data['fk_id_branch'] && $a[0] === 'MARKETPLACE' && $c->fk_id_mp_profile) DB::table('mp_profiles')->where('id', $c->fk_id_mp_profile)->update(['fk_id_api_connection' => $id]);
            if ($data['fk_id_carrier']) DB::table('t_carriers')->where('id', $data['fk_id_carrier'])->update(['fk_id_api_connection' => $id, 'label_source' => $a[1] === RoyalMailClickDrop::class ? 'API' : DB::raw('label_source')]);
            Audit::log('api_connections', $id, 'UPDATE', ['adapter' => $data['adapter'], 'secrets' => $typed ? 'changed (kept in the vault)' : 'unchanged'], null, (int) Context::ent());
            return $id;
        });
        return $id;
    }

    /** Which secrets are filled (never their values). */
    public static function filled(object $c): array
    {
        $s = self::secrets($c); $a = self::ADAPTERS[$c->adapter] ?? null;
        return $a ? array_map(fn ($k) => ! empty($s[$k]), array_combine(array_keys($a[3]), array_keys($a[3]))) : [];
    }

    /** New tokens an adapter got while working go back into the vault. */
    public static function keepRenewed(object $c, Adapter $ad): void
    {
        if ($ad->renewed && ! empty($c->id)) { self::keepSecrets($c, array_merge(self::secrets($c), $ad->renewed)); $ad->renewed = null; }
    }

    private static function after(object $c, Adapter $ad, ?\Throwable $e = null, array $set = []): void
    {
        self::keepRenewed($c, $ad);
        if ($e) {
            $set += ['status' => $e instanceof ApiError && $e->auth ? 'EXPIRED' : 'ERROR', 'last_error' => mb_substr($e->getMessage(), 0, 255)];
            TaskEngine::open('SYS_API_SYNC', [$c->id], 'Connection "' . $c->label . '" needs a look: ' . mb_substr($e->getMessage(), 0, 160), ['ent' => (int) $c->fk_id_ent, 'module' => 'api_connections', 'record_id' => $c->id, 'fix_url' => '#/connections']);
        } else { $set += ['status' => 'CONNECTED', 'last_error' => null]; TaskEngine::close('SYS_API_SYNC', [$c->id], 'Connection works again'); }
        DB::table('api_connections')->where('id', $c->id)->update($set);
    }

    public static function test(int $id): array
    {
        $c = self::row($id); $ad = self::adapter($c);
        try { $text = $ad->test(); self::after($c, $ad, null, ['last_test' => mb_substr('OK ' . date('Y-m-d H:i') . ' – ' . $text, 0, 255)]); return ['ok' => true, 'text' => $text]; }
        catch (\Throwable $e) { self::after($c, $ad, $e, ['last_test' => mb_substr('FAILED ' . date('Y-m-d H:i') . ' – ' . $e->getMessage(), 0, 255)]); return ['ok' => false, 'text' => $e->getMessage()]; }
    }

    /** Pull orders or money of one marketplace connection for a date range, and load them like a file. */
    public static function pull(int $id, string $what, string $from, string $to): array
    {
        $c = self::row($id); $a = self::ADAPTERS[$c->adapter] ?? throw new \InvalidArgumentException('Unknown adapter.');
        if ($a[0] !== 'MARKETPLACE') throw new \InvalidArgumentException('Only a marketplace connection pulls orders and money.');
        if ($c->status === 'OFF') throw new \InvalidArgumentException('This connection is switched off.');
        if ($from > $to) [$from, $to] = [$to, $from];
        $ent = (int) $c->fk_id_ent; $prev = Context::ent(); if (! $prev) Context::set($ent, Context::userId() ?? 0);
        $job = DB::table('sys_job_runs')->insertGetId(['job_code' => 'API_PULL_' . strtoupper($what), 'fk_id_ent' => $ent, 'started_at' => now(), 'run_status' => 'RUNNING']);
        $ad = self::adapter($c); $res = ['what' => $what, 'from' => $from, 'to' => $to];
        try {
            if ($what === 'ORDERS') {
                $lines = $ad->orders($from, $to);
                $r = $lines ? SalesService::intake((int) $c->fk_id_branch, $lines) : ['new' => 0, 'updated' => 0, 'unmatched' => 0, 'short' => 0];
                $res += ['lines' => count($lines), 'orders' => count(array_unique(array_column($lines, 'order_no'))), 'new' => $r['new'], 'updated' => $r['updated'], 'unmatched' => $r['unmatched'], 'short' => $r['short']];
                $rows = count($lines); $new = $r['new'];
            } elseif ($what === 'MONEY') {
                $m = $ad->money($from, $to);
                if ($m === null) throw new \InvalidArgumentException('This marketplace has no money API: load its money file under Payouts & settlements.');
                $res += ['lines' => count($m), 'statements' => 0, 'new' => 0];
                $rows = count($m); $new = 0;
                if ($m) {
                    $path = tempnam(sys_get_temp_dir(), 'mo-api-') . '.csv'; $f = fopen($path, 'w'); fputcsv($f, MarketplaceAdapter::MONEY_HEAD); foreach ($m as $row) fputcsv($f, $row); fclose($f);
                    try { $x = MarketplaceFiles::import($ent, (int) $c->fk_id_branch, $path, 'api-' . strtolower($c->adapter) . "-$from-$to.csv", ['platform' => preg_replace('/_.*$/', '', $c->adapter) . 'API', 'currency' => $m[0][8] ?? null]); }
                    finally { @unlink($path); }
                    $res['statements'] = (int) ($x['statements_new'] ?? 0) + (int) ($x['statements_reopened'] ?? 0); $res['new'] = (int) ($x['lines_new'] ?? 0); $res['already'] = (int) ($x['lines_already'] ?? 0);
                    $new = $res['new'];
                }
            } else throw new \InvalidArgumentException('Pull ORDERS or MONEY.');
            DB::table('imp_batches')->insert(['batch_code' => Numbering::next('IMP', $ent), 'fk_id_ent' => $ent, 'fk_id_branch' => $c->fk_id_branch, 'report_type' => mb_substr($c->adapter . '_' . $what, 0, 40), 'source' => 'API',
                'file_name' => mb_substr("API pull $from – $to", 0, 200), 'period_from' => $from, 'period_to' => $to, 'rows_total' => $rows, 'rows_new' => $new, 'status' => 'SAVED', 'created_by' => Context::userId(), 'created_at' => now(), 'saved_at' => now()]);
            self::after($c, $ad, null, ['last_sync_at' => now()]);
            DB::table('sys_job_runs')->where('id', $job)->update(['finished_at' => now(), 'run_status' => 'OK', 'items_done' => $rows]);
            Audit::log('api_connections', $c->id, 'UPDATE', ['pull' => $what, 'from' => $from, 'to' => $to, 'rows' => $rows], null, $ent);
            return $res + ['ok' => true];
        } catch (\Throwable $e) {
            if ($e instanceof ApiError) self::after($c, $ad, $e);
            DB::table('sys_job_runs')->where('id', $job)->update(['finished_at' => now(), 'run_status' => 'FAILED', 'error_text' => mb_substr($e->getMessage(), 0, 1000)]);
            throw $e instanceof ApiError ? new \InvalidArgumentException($e->getMessage()) : $e;
        }
    }

    /** Scheduled pull of every working marketplace connection: orders of the last 2 days, money of the last 14 days. */
    public static function pullAll(): array
    {
        $out = [];
        foreach (DB::table('api_connections')->whereIn('status', ['CONNECTED', 'NEW'])->whereNotIn('adapter', ['FILE'])->whereNotNull('fk_id_branch')->get() as $c) {
            if ((self::ADAPTERS[$c->adapter][0] ?? '') !== 'MARKETPLACE') continue;
            Context::set((int) $c->fk_id_ent, 0);
            foreach (['ORDERS' => 2, 'MONEY' => 14] as $what => $days) {
                try { $out[] = [$c->label, $what, self::pull($c->id, $what, date('Y-m-d', strtotime("-$days days")), date('Y-m-d'))]; }
                catch (\Throwable $e) { $out[] = [$c->label, $what, ['ok' => false, 'error' => $e->getMessage()]]; }
            }
        }
        return $out;
    }

    /** Where is a parcel: the carrier's connection if it has one, else its tracking link. */
    public static function track(int $carrierId, string $trackingNo): array
    {
        $car = DB::table('t_carriers')->where('id', $carrierId)->first() ?? throw new \InvalidArgumentException('Carrier not found.');
        $c = $car->fk_id_api_connection ? DB::table('api_connections')->where('id', $car->fk_id_api_connection)->first() : null;
        if (! $c || ! in_array($c->status, ['CONNECTED', 'NEW'], true)) $c = (object) ['id' => null, 'fk_id_ent' => $car->fk_id_ent, 'adapter' => 'TRACK_LINK', 'settings_json' => null, 'fk_id_carrier' => $car->id, 'label' => 'link'];
        $ad = self::adapter($c);
        if (! $ad instanceof CarrierAdapter) throw new \InvalidArgumentException('This connection is not a carrier.');
        try { return $ad->track($trackingNo); }
        catch (ApiError $e) { if ($c->id) self::after($c, $ad, $e); return ['status' => 'ERROR', 'text' => $e->getMessage(), 'events' => [], 'url' => (new TrackLink($c, [], ['tracking_url_pattern' => $car->tracking_url_pattern]))->track($trackingNo)['url']]; }
    }

    /** Buy labels at a carrier with an API for orders that are packed; the PDF goes through Carrier labels (pages linked to their orders). */
    public static function buyLabels(int $connId, int $branchId, array $saleIds): array
    {
        $c = self::row($connId); $ad = self::adapter($c);
        if (! $ad instanceof CarrierAdapter) throw new \InvalidArgumentException('Choose a carrier connection.');
        $sales = DB::table('t_sales')->whereIn('id', $saleIds)->where('fk_id_branch', $branchId)->where('fk_id_ent', Context::ent())->get();
        if ($sales->isEmpty()) throw new \InvalidArgumentException('Choose the orders to buy labels for.');
        $parcels = []; foreach ($sales->groupBy('order_no') as $no => $ls) { $l = $ls->first();
            $parcels[] = ['order_no' => $no, 'name' => $l->ship_to_name ?: $l->cust_name, 'address1' => $l->ship_to_address, 'address2' => '', 'city' => $l->ship_to_city, 'postcode' => $l->ship_to_postcode, 'country' => $l->ship_to_country_code ?: 'GB',
                'phone' => $l->ship_to_phone, 'weight_g' => 0, 'service' => null, 'value' => round((float) $ls->sum(fn ($x) => $x->sale_rate * $x->qty), 2), 'currency' => 'GBP']; }
        try { $r = $ad->labels($parcels); self::after($c, $ad); }
        catch (ApiError $e) { self::after($c, $ad, $e); throw new \InvalidArgumentException($e->getMessage()); }
        $path = tempnam(sys_get_temp_dir(), 'mo-lbl-') . '.pdf'; file_put_contents($path, $r['pdf']);
        try { $x = \App\Services\CarrierLabels::import((int) Context::ent(), (int) $c->fk_id_carrier, $branchId, $path, 'api-labels-' . date('Ymd-His') . '.pdf'); } finally { @unlink($path); }
        foreach ($r['orders'] as $no => $trk) if ($trk) DB::table('t_sales')->where('fk_id_branch', $branchId)->where('order_no', $no)->whereNull('tracking_no')->update(['tracking_no' => $trk]);
        return ['labels' => $x, 'tracking' => $r['orders'], 'failed' => $r['failed'] ?? []];
    }
}

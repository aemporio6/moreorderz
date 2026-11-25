<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-A_SALES-77 "Export for carrier": one file per carrier layout from any order selection (the sales list with its filters, the fulfilment queue,
 * ticked rows). Phones are written the way the carrier's country wants them (national form); the parcel comes from the line, else from the product pack.
 * The column lists below are the carriers' own bulk-upload headers; a company picks its default layout in Settings › Choices (CARRIER_FILE_DEFAULT).
 */
class CarrierFiles
{
    public const TEMPLATES = [
        'TIKTOK' => ['TikTok / standard (25 columns)', null],
        'CLICK_DROP' => ['Royal Mail Click & Drop', ['Order reference' => 'order_no', 'Order date' => 'date', 'Full name' => 'name', 'Company name' => 'blank', 'Address line 1' => 'address_1', 'Address line 2' => 'address_2', 'Address line 3' => 'address_3', 'Town' => 'city', 'County' => 'county',
            'Postcode' => 'postcode', 'Country code' => 'country_code', 'Phone' => 'phone', 'Email' => 'email', 'Weight (kg)' => 'weight_kg', 'Package size' => 'package_size', 'Service code' => 'service', 'Product SKU' => 'sku', 'Product name' => 'item', 'Quantity' => 'qty', 'Unit value' => 'unit_value', 'Currency' => 'currency']],
        'EVRI' => ['Evri (bulk upload)', ['Reference' => 'order_no', 'Name' => 'name', 'Address line 1' => 'address_1', 'Address line 2' => 'address_2', 'Town' => 'city', 'County' => 'county', 'Postcode' => 'postcode', 'Country' => 'country_code', 'Email' => 'email', 'Phone' => 'phone',
            'Weight (kg)' => 'weight_kg', 'Length (cm)' => 'length', 'Width (cm)' => 'width', 'Height (cm)' => 'height', 'Contents' => 'item', 'Value' => 'value', 'Service' => 'service']],
        'DPD' => ['DPD (bulk shipments)', ['Reference 1' => 'order_no', 'Reference 2' => 'sku', 'Contact name' => 'name', 'Address 1' => 'address_1', 'Address 2' => 'address_2', 'Town' => 'city', 'County' => 'county', 'Postcode' => 'postcode', 'Country code' => 'country_code', 'Telephone' => 'phone',
            'Email' => 'email', 'Number of parcels' => 'parcels', 'Total weight (kg)' => 'weight_kg', 'Service' => 'service', 'Contents' => 'item', 'Value' => 'value', 'Delivery instructions' => 'blank']],
    ];

    public static function list(): array { return array_map(fn ($t) => $t[0], self::TEMPLATES); }

    /** The parcel of a line: what the line says, else the product pack × qty (weight) and the pack box (mm → cm). */
    public static function parcel(object $r): array
    {
        $q = max(1, (int) $r->qty); $cm = fn ($mm) => $mm ? round((float) $mm / 10, 1) : null;
        $w = (int) $r->parcel_weight_g ?: ((int) ($r->pack_weight_g ?? 0) ? (int) $r->pack_weight_g * $q : 0);
        return ['weight_g' => $w, 'length' => (float) $r->parcel_l_cm ?: $cm($r->pack_l_mm ?? null), 'width' => (float) $r->parcel_w_cm ?: $cm($r->pack_w_mm ?? null), 'height' => (float) $r->parcel_h_cm ?: $cm($r->pack_h_mm ?? null),
            'from' => (int) $r->parcel_weight_g ? 'line' : ($w ? 'product pack' : 'none')];
    }

    /** Royal Mail package size from the parcel: Letter / Large letter / Small parcel / Medium parcel. */
    public static function packageSize(array $p): string
    {
        $d = array_filter([$p['length'], $p['width'], $p['height']]); rsort($d); [$a, $b, $c] = array_pad($d, 3, 0); $w = $p['weight_g'];
        if ($d && $a <= 24 && $b <= 16.5 && $c <= 0.5 && $w <= 100) return 'Letter';
        if ($d && $a <= 35.3 && $b <= 25 && $c <= 2.5 && $w <= 750) return 'Large letter';
        if ((! $d || ($a <= 45 && $b <= 35 && $c <= 16)) && $w <= 2000) return 'Small parcel';
        return 'Medium parcel';
    }

    /** [file name, body, rows] of one layout over the lines of $q (a t_sales query aliased "s"; the caller has applied the filters and the shop scope). */
    public static function build(string $template, $q, ?string $now = null): array
    {
        if (! isset(self::TEMPLATES[$template]) || self::TEMPLATES[$template][1] === null) throw new \InvalidArgumentException('Choose a carrier layout.');
        $cols = self::TEMPLATES[$template][1]; $ent = (int) Context::ent();
        $rows = $q->leftJoin('mo_sale_fulfilment as fx', 'fx.fk_id_sale', '=', 's.id')->leftJoin('t_product_vendors as pv', 'pv.id', '=', 's.fk_product_id')->orderBy('s.d_order')->orderBy('s.id')->limit(20000)
            ->get(['s.id', 's.order_no', 's.d_order', 's.sku', 's.product_info', 's.qty', 's.sale_rate', 's.total_amount', 's.fk_id_currency', 's.cust_name', 's.cust_contacts', 's.ship_to_name', 's.ship_to_phone', 's.ship_to_address', 's.ship_to_city', 's.ship_to_postcode', 's.ship_to_country_code',
                's.parcel_weight_g', 's.parcel_l_cm', 's.parcel_w_cm', 's.parcel_h_cm', 's.carrier_service_text', 'fx.address_1', 'fx.address_2', 'fx.address_3', 'fx.state', 'fx.buyer_email', 'fx.recipient_first_name', 'fx.recipient_last_name', 'fx.recipient_phone',
                'pv.pack_weight_g', 'pv.pack_l_mm', 'pv.pack_w_mm', 'pv.pack_h_mm']);
        $cur = DB::table('cat_currency')->pluck('currency_code', 'id');
        $h = fopen('php://temp', 'w+'); fputcsv($h, array_keys($cols), ',', '"', ''); $n = 0;
        foreach ($rows as $r) { $n++; $cc = strtoupper((string) $r->ship_to_country_code) ?: 'GB'; $p = self::parcel($r);
            $addr = $r->address_1 !== null && $r->address_1 !== '' ? [$r->address_1, $r->address_2, $r->address_3] : array_pad(array_map('trim', explode(',', (string) $r->ship_to_address, 3)), 3, '');
            $name = trim(((string) $r->recipient_first_name) . ' ' . ((string) $r->recipient_last_name)) ?: ((string) $r->ship_to_name ?: (string) $r->cust_name);
            $email = (string) ($r->buyer_email ?: (preg_match('/[^\s,;]+@[^\s,;]+/', (string) $r->cust_contacts, $m) ? $m[0] : ''));
            $v = ['order_no' => $r->order_no, 'date' => date('d/m/Y', strtotime((string) $r->d_order)), 'name' => $name, 'blank' => '', 'address_1' => $addr[0], 'address_2' => $addr[1], 'address_3' => $addr[2], 'city' => $r->ship_to_city, 'county' => (string) $r->state,
                'postcode' => $r->ship_to_postcode, 'country_code' => $cc, 'phone' => Fulfilment::phone((string) ($r->recipient_phone ?: $r->ship_to_phone), $cc, 'NATIONAL'), 'email' => $email,
                'weight_kg' => $p['weight_g'] ? number_format($p['weight_g'] / 1000, 3, '.', '') : '', 'length' => $p['length'] ?: '', 'width' => $p['width'] ?: '', 'height' => $p['height'] ?: '', 'package_size' => self::packageSize($p),
                'service' => (string) $r->carrier_service_text, 'sku' => str_starts_with((string) $r->sku, 'NOSKU-') ? '' : $r->sku, 'item' => mb_substr((string) $r->product_info, 0, 100), 'qty' => (int) $r->qty,
                'unit_value' => number_format((float) $r->sale_rate, 2, '.', ''), 'value' => number_format((float) $r->total_amount, 2, '.', ''), 'currency' => $cur[$r->fk_id_currency] ?? 'GBP', 'parcels' => 1];
            fputcsv($h, array_map(fn ($c) => DsMatch::guard((string) $v[$c]), array_values($cols)), ',', '"', ''); }
        rewind($h); $body = stream_get_contents($h); fclose($h);
        return ['name' => $template . '_' . date('YmdHis', $now ? strtotime($now) : time()) . '.csv', 'body' => $body, 'rows' => $n];
    }
}

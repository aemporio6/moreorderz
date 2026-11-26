<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 26-11 22:28: "every thing comes from behind and sits everywhere by itself". A screen built by hand (old purchase entry, direct PO,
 * payments …) has plain boxes; this finds, for each box name on such a screen, the field of the central register (sys_registry) that holds
 * the same thing – so the one dropdown of the app (search, recent first, All, + Add, default, help) comes to that box on its own, fed from
 * the same list as everywhere else. Matching: the field of a form of this screen first (#/old-entry → old_entry*), then any form;
 * by field name, then by column, then by the common words below. A box with no match stays as it is.
 */
class PickMap
{
    /** box word => columns that hold it */
    public const WORDS = [
        'supplier' => ['fk_id_supplier', 'fk_id_default_supplier'], 'supplier_name' => ['fk_id_supplier'], 'seller' => ['fk_id_supplier'], 'vendor' => ['fk_id_supplier'],
        'forwarder' => ['fk_id_forwarder'], 'currency' => ['fk_id_currency', 'currency'], 'freight_currency' => ['fk_id_currency'], 'cur' => ['fk_id_currency'],
        'mode' => ['shipping_mode', 'ship_mode'], 'ship_mode' => ['shipping_mode'], 'shipping_mode' => ['shipping_mode'],
        'method' => ['payment_method'], 'pay_method' => ['payment_method'], 'paid_via' => ['paid_via'],
        'owner' => ['fk_id_owner'], 'buyer' => ['fk_id_owner'], 'warehouse' => ['fk_id_warehouse', 'fk_id_location'], 'location' => ['fk_id_location'], 'destination' => ['fk_id_warehouse'],
        'shop' => ['fk_id_branch'], 'branch' => ['fk_id_branch'], 'marketplace' => ['fk_id_marketplace'], 'carrier' => ['fk_id_carrier'], 'country' => ['fk_id_country', 'country'],
        'brand' => ['fk_id_brand', 'brand'], 'product' => ['fk_id_product'], 'category' => ['fk_id_category'], 'line_type' => ['line_type'], 'incoterm' => ['fk_id_incoterm'],
        'payment_terms' => ['fk_id_payment_term'], 'port_from' => ['fk_id_port_loading'], 'port_to' => ['fk_id_port_discharge'], 'priority' => ['priority'], 'company' => ['fk_id_ent'],
    ];

    public static function map(string $route, array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map(fn ($n) => strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) $n)), $names))));
        if (! $names) return [];
        $hint = strtolower(str_replace('-', '_', (string) preg_replace('~^#/([\w-]+).*$~', '$1', $route)));
        $rows = DB::table('sys_registry')->whereIn('type', ['select', 'multiselect'])->whereNotNull('source')->where('source', '<>', '')
            ->get(['form_key', 'field_name', 'column_name', 'label', 'help', 'type', 'source']);
        $mine = $hint !== '' ? $rows->filter(fn ($r) => str_starts_with((string) $r->form_key, $hint)) : collect();
        $out = [];
        foreach ($names as $n) {
            $cols = self::WORDS[$n] ?? [];
            $hit = $mine->firstWhere('field_name', $n) ?? $mine->first(fn ($r) => $r->column_name === $n || in_array($r->column_name, $cols, true))
                ?? $rows->firstWhere('field_name', $n) ?? $rows->first(fn ($r) => in_array($r->column_name, $cols, true));
            if (! $hit || ! Registry::exists((string) $hit->form_key)) continue;
            $f = FormAssist::field((string) $hit->form_key, (string) $hit->field_name);
            $out[$n] = ['form' => $hit->form_key, 'field' => $hit->field_name, 'label' => $hit->label, 'help' => $hit->help, 'multi' => $hit->type === 'multiselect',
                'add' => $f ? FormAssist::addWay($f) : null];
        }
        return $out;
    }
}

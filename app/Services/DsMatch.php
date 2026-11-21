<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Drop-shipping match (RULES_A_SALES R-A_SALES-19 … 26): a sale line without a product of ours is compared with the supplier orders (drp_items)
 * of the same shop and month on five fields – name, address, town, postcode, phone. Three of five (setting DS_MIN_MATCH) make it a drop-shipping line.
 */
class DsMatch
{
    public const FIELDS = ['name' => 'Delivered To', 'address' => 'Address', 'city' => 'Town', 'postcode' => 'Postcode', 'phone' => 'Phone'];
    private const TITLES = ['mr', 'mrs', 'miss', 'ms', 'dr', 'the', 'and'];
    private const UNIT = ['flat', 'apt', 'apartment', 'unit', 'suite', 'room', 'floor', 'block', 'building'];
    private const ABBR = ['street' => 'st', 'road' => 'rd', 'avenue' => 'ave', 'lane' => 'ln', 'drive' => 'dr', 'close' => 'cl', 'court' => 'ct', 'crescent' => 'cres', 'place' => 'pl', 'square' => 'sq', 'terrace' => 'ter', 'gardens' => 'gdns', 'saint' => 'st'];

    public static function minMatch(?int $ent = null): int { return max(1, min(5, (int) Settings::get('DS_MIN_MATCH', $ent ?? Context::ent(), 3))); }

    // ---------- text helpers ----------
    private static function norm(?string $s): string { return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $s)))); }
    private static function words(?string $s): array { $n = self::norm($s); return $n === '' ? [] : explode(' ', $n); }
    private static function compact(?string $s): string { return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $s)); }
    private static function hasWords(string $needle, string $hay): bool { $n = self::norm($needle); return $n !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($n, '/') . '(?![\p{L}\p{N}])/u', self::norm($hay)) === 1; }
    private static function addr(?string $s): string { return implode(' ', array_map(fn ($w) => self::ABBR[$w] ?? $w, self::words($s))); }

    /** Phone numbers in a text, as their last 9 digits (numbers with at least 9 digits). */
    public static function phones(?string $text): array
    {
        $out = []; if (preg_match_all('/\+?\d[\d \-().]{6,}\d/', (string) $text, $m)) foreach ($m[0] as $p) { $d = preg_replace('/\D/', '', $p); if (strlen($d) >= 9) $out[] = substr($d, -9); }
        return array_values(array_unique($out));
    }

    // ---------- the five fields ----------
    public static function nameMatch(?string $a, ?string $b): bool
    {
        $x = self::words($a); $y = self::words($b); if (! $x || ! $y) return false;
        if ($x === $y) return true; $sx = $x; $sy = $y; sort($sx); sort($sy); if ($sx === $sy) return true;
        [$short, $long] = count($x) <= count($y) ? [$x, $y] : [$y, $x];
        return count($short) >= 2 && ! array_diff($short, $long);
    }

    /** $saleCity = the mapped city (null when no city column is mapped); then the drp city is looked for as a whole word in the contacts text. */
    public static function cityMatch(?string $saleCity, ?string $contacts, ?string $drpCity): bool
    {
        if (self::norm($drpCity) === '') return false;
        if ($saleCity !== null && self::norm($saleCity) !== '') return self::norm($saleCity) === self::norm($drpCity) || self::hasWords($drpCity, $saleCity) || self::hasWords($saleCity, $drpCity);
        return self::hasWords($drpCity, (string) $contacts);
    }

    public static function postcodeOk(string $compact): bool { return strlen($compact) >= 3 && (preg_match('/[a-z]/', $compact) || preg_match_all('/\d/', $compact) >= 5); }

    public static function postcodeMatch(?string $salePostcode, ?string $contacts, ?string $drpPostcode): bool
    {
        $c = self::compact($drpPostcode); if (! self::postcodeOk($c)) return false;
        if ($salePostcode !== null && self::compact($salePostcode) !== '') return self::compact($salePostcode) === $c;
        $rx = '/(?<![a-z0-9])' . implode('\s?', array_map(fn ($ch) => preg_quote($ch, '/'), str_split($c))) . '(?![a-z0-9])/i';      // an optional space between characters, whole words
        return preg_match($rx, mb_strtolower((string) $contacts)) === 1;
    }

    public static function phoneMatch(?string $salePhone, ?string $contacts, ?string $drpPhone, array $own = []): bool
    {
        $d = array_diff(self::phones($drpPhone), $own); if (! $d) return false;
        $s = $salePhone !== null && trim($salePhone) !== '' ? self::phones($salePhone) : self::phones($contacts);
        return (bool) array_intersect($d, array_diff($s, $own));
    }

    /** $stop = city / postcode / county / country words of the drp item, which never count as address words. */
    public static function addressMatch(?string $drpAddress, ?string $saleText, array $stop = []): bool
    {
        $hay = self::addr($saleText); if ($hay === '' || trim((string) $drpAddress) === '') return false;
        $stopW = []; foreach ($stop as $s) foreach (self::words($s) as $w) $stopW[$w] = 1; foreach ($stop as $s) if (($c = self::compact($s)) !== '') $stopW[$c] = 1;
        $real = null;
        foreach (explode(',', (string) $drpAddress) as $part) {
            $w = self::words($part); if (! $w) continue;
            if (in_array($w[0], self::UNIT, true) && count($w) <= 3) continue;                                            // "Flat 2", "Unit 4 B"
            if ($w[0] === 'po' && ($w[1] ?? '') === 'box') continue;
            if (ctype_digit($w[0]) && count($w) <= 2 && (count($w) === 1 || isset($stopW[$w[1]]))) continue;                // number-only parts
            if (! array_filter($w, fn ($x) => ! isset($stopW[$x]))) continue;                                              // only city / postcode / county / country words
            if (isset($stopW[self::compact($part)])) continue;
            $real = self::addr($part); break;
        }
        if ($real !== null && mb_strlen($real) >= 4 && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($real, '/') . '(?![\p{L}\p{N}])/u', $hay)) return true;
        $tokens = array_values(array_unique(array_filter(explode(' ', self::addr($drpAddress)), fn ($t) => mb_strlen($t) >= 2 && ! isset($stopW[$t]) && ! in_array($t, self::UNIT, true))));
        if (count($tokens) < 2) return false;
        $hw = array_flip(explode(' ', $hay)); $hit = count(array_filter($tokens, fn ($t) => isset($hw[$t])));
        return $hit >= 2 && $hit / count($tokens) >= 0.7;
    }

    // ---------- the sale side ----------
    /** The five values of a sale line: from the marketplace's 5-matching columns (read from the stored CSV row), else the buyer name and the contacts text. */
    public static function saleSide(object $s, ?array $map = null): array
    {
        $map ??= SalesImport::columns((int) $s->fk_id_marketplace, 'MATCH'); $raw = [];
        foreach ((array) json_decode((string) ($s->raw_csv ?? ''), true) as $h => $v) if (is_scalar($v)) $raw[Parse::header($h)] = trim((string) $v);
        $get = function (string $f) use ($map, $raw) { if (empty($map[$f])) return null; $vals = []; foreach ($map[$f] as $h) if (($raw[Parse::header($h)] ?? '') !== '') $vals[] = $raw[Parse::header($h)]; return implode(', ', $vals); };
        $contacts = trim((string) $s->cust_contacts); $name = $get('ship_name'); $addr = $get('ship_address');
        return ['name' => $name !== null && $name !== '' ? $name : (string) $s->cust_name, 'address' => $addr, 'city' => $get('ship_city'), 'postcode' => $get('ship_postcode'), 'phone' => $get('ship_phone'), 'contacts' => $contacts,
            'address_text' => $addr !== null && $addr !== '' ? $addr : $contacts];
    }

    /** ['name' => bool, …, 'n' => count] for one sale line against one supplier order. */
    public static function score(array $sale, object $item, array $own = []): array
    {
        $stop = array_filter([(string) $item->ship_city, (string) $item->ship_postcode, (string) ($item->ship_county ?? ''), (string) ($item->ship_country ?? '')], fn ($x) => trim($x) !== '');
        $f = ['name' => self::nameMatch($sale['name'], $item->ship_name), 'address' => self::addressMatch($item->ship_address, $sale['address_text'], $stop), 'city' => self::cityMatch($sale['city'], $sale['contacts'] . ' ' . (string) $sale['address'], $item->ship_city),
            'postcode' => self::postcodeMatch($sale['postcode'], $sale['contacts'] . ' ' . (string) $sale['address'], $item->ship_postcode), 'phone' => self::phoneMatch($sale['phone'], $sale['contacts'], $item->ship_phone, $own)];
        return $f + ['n' => count(array_filter($f))];
    }

    private static function nameWords(?string $n): array { return array_values(array_filter(self::words($n), fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::TITLES, true))); }

    /** Only items that share a name word, the phone or the postcode with the line are scored. */
    private static function related(array $sale, object $item, array $own): bool
    {
        if (array_intersect(self::nameWords($sale['name']), self::nameWords($item->ship_name))) return true;
        if (self::phoneMatch($sale['phone'], $sale['contacts'], $item->ship_phone, $own)) return true;
        return self::postcodeMatch($sale['postcode'], $sale['contacts'] . ' ' . (string) $sale['address'], $item->ship_postcode);
    }

    // ---------- R-A_SALES-20: own phone numbers ----------
    /** Numbers on supplier orders of 3 or more different buyer names, plus the numbers listed in the setting DS_OWN_PHONES. Returns [last-9-digit numbers, how many were found by themselves]. */
    public static function ownPhones($items, ?int $ent = null): array
    {
        $by = []; foreach ($items as $i) foreach (self::phones($i->ship_phone) as $p) $by[$p][self::norm($i->ship_name)] = 1;
        $auto = array_keys(array_filter($by, fn ($names) => count($names) >= 3));
        $set = []; foreach (preg_split('/[,;\n]+/', (string) Settings::get('DS_OWN_PHONES', $ent ?? Context::ent(), '')) as $p) { $d = preg_replace('/\D/', '', $p); if (strlen($d) >= 9) $set[] = substr($d, -9); }
        return [array_values(array_unique(array_map('strval', array_merge($auto, $set)))), count($auto)];
    }

    // ---------- R-A_SALES-21: assignment ----------
    /**
     * $lines: sale rows (id, order_no, qty, d_order, product_info, cust_name, cust_contacts, raw_csv, fk_id_marketplace, sku). $items: drp_items rows. $taken: item id => text of the sale that already holds it.
     * Returns ['assign' => line id => [item, fields], 'best' => line id => n, 'note' => line id => text, 'conflicts' => [...]].
     */
    public static function assign($lines, $items, array $taken, array $own, int $min): array
    {
        $pairs = []; $best = []; $blocked = []; $tops = [];
        foreach ($lines as $l) {
            $sale = self::saleSide($l); $best[$l->id] = 0;
            if (self::norm($sale['name']) === '' && self::norm($sale['contacts']) === '' && self::norm($sale['address']) === '') continue;      // nothing to compare with
            $tw = array_flip(array_filter(self::words($l->product_info), fn ($w) => mb_strlen($w) >= 3));
            foreach ($items as $it) {
                if (! self::related($sale, $it, $own)) continue;
                $sc = self::score($sale, $it, $own); $best[$l->id] = max($best[$l->id], $sc['n']);
                if ($sc['n'] < $min) continue;
                $tops[$l->id][$sc['n']][] = (int) $it->id;
                $pairs[] = ['line' => (int) $l->id, 'item' => (int) $it->id, 'n' => $sc['n'], 'fields' => $sc, 'order' => self::norm($l->order_no) !== '' && self::norm($l->order_no) === self::norm($it->order_no) ? 1 : 0,
                    'title' => count(array_filter(self::words($it->item_title), fn ($w) => isset($tw[$w]))), 'qty' => (int) $l->qty === (int) $it->qty ? 1 : 0, 'days' => abs((strtotime((string) $l->d_order) - strtotime((string) $it->d_order)) / 86400)];
            }
        }
        usort($pairs, fn ($a, $b) => [$b['n'], $b['order'], $b['title'], $b['qty'], $a['days'], $a['item']] <=> [$a['n'], $a['order'], $a['title'], $a['qty'], $b['days'], $b['item']]);
        $assign = []; $used = []; $lost = [];
        foreach ($pairs as $p) {
            if (isset($assign[$p['line']])) continue;
            if (isset($taken[$p['item']])) { $blocked[$p['line']] ??= $taken[$p['item']]; continue; }      // linked to a sale of another import: skipped, noted
            if (isset($used[$p['item']])) { $lost[$p['line']] = 1; continue; }
            $assign[$p['line']] = ['item' => $p['item'], 'fields' => $p['fields']]; $used[$p['item']] = 1;
        }
        $note = []; $conflicts = [];
        foreach ($best as $id => $n) {
            if (isset($assign[$id])) { $top = $tops[$id][$assign[$id]['fields']['n']] ?? []; if (count($top) > 1) $conflicts[] = ['line' => $id, 'items' => $top, 'n' => $assign[$id]['fields']['n']]; continue; }
            $note[$id] = isset($blocked[$id]) ? "its drop shipping item is already linked to {$blocked[$id]}" : (isset($lost[$id]) && $n >= $min ? 'its drop shipping item went to another line of this file' : "no drop shipping item matched (best $n of 5, $min needed)");
        }
        return ['assign' => $assign, 'best' => $best, 'note' => $note, 'conflicts' => $conflicts];
    }

    // ---------- R-A_SALES-91: the smallest margin of a drop shipping line ----------
    /** Shop's own column first, then the shop's choice, then the company's (list 0 / 5 / 10 / 12 / 15 / 20 / 25 / 30 %). */
    public static function minMargin(int $shopId, ?int $ent = null): float
    {
        $own = DB::table('t_branches')->where('id', $shopId)->value('ds_min_margin_pct');
        return $own !== null ? (float) $own : (float) Choices::get('DS_MIN_MARGIN', $ent ?? Context::ent(), $shopId);
    }

    /** Opens SAL_DS_MARG for a drop shipping line below the shop's minimum (always below 0 %), closes it when the margin is fine. Returns the margin %. */
    public static function marginTask(int $saleId): ?float
    {
        $s = DB::table('t_sales')->where('id', $saleId)->first(); if (! $s) return null;
        if ($s->sale_source !== 'ds' || (float) $s->total_amount <= 0) { TaskEngine::close('SAL_DS_MARG', [$saleId], 'Not a drop shipping line any more'); return null; }
        $pct = round((float) $s->profit / (float) $s->total_amount * 100, 2); $min = self::minMargin((int) $s->fk_id_branch, (int) $s->fk_id_ent);
        if ($pct < 0 || $pct < $min) { if (! str_contains((string) $s->ds_note, 'margin accepted') || $pct < 0 && ! str_contains((string) $s->ds_note, 'loss accepted')) TaskEngine::open('SAL_DS_MARG', [$saleId], "Drop shipping margin $pct % on order {$s->order_no} (minimum " . rtrim(rtrim(number_format($min, 2, '.', ''), '0'), '.') . ' %)', ['ent' => (int) $s->fk_id_ent, 'module' => 't_sales', 'record_id' => $saleId, 'fix_url' => '#/sales?import=' . (int) $s->fk_id_import]); }
        else TaskEngine::close('SAL_DS_MARG', [$saleId], 'Margin is fine');
        return $pct;
    }

    /** "Accept with a note": the warning task closes and the note stays on the line. */
    public static function acceptMargin(int $saleId, string $note, ?object $u): array
    {
        Perm::need($u, 'orders.dsLink'); if (mb_strlen(trim($note)) < 3) throw new \InvalidArgumentException('Write the note.');
        $s = DB::table('t_sales')->where('id', $saleId)->where('fk_id_ent', Context::ent())->first() ?? abort(404, 'Sale line not found.');
        if (! Perm::shopAllowed($u, (int) $s->fk_id_branch)) abort(403, "You don't have rights for this shop.");
        if ($s->sale_source !== 'ds') throw new \InvalidArgumentException('This is not a drop shipping line.');
        $loss = (float) $s->profit < 0;
        DB::table('t_sales')->where('id', $saleId)->update(['ds_note' => mb_substr(($loss ? 'loss accepted: ' : 'margin accepted: ') . trim($note), 0, 255), 'updated_at' => now()]);
        DB::table('t_sale_change_log')->insert(['fk_id_sale' => $saleId, 'save_key' => uniqid('ma'), 'action' => 'Accept margin', 'field_name' => 'ds_note', 'field_label' => 'Drop shipping margin', 'old_value' => 'below the minimum', 'new_value' => 'accepted: ' . mb_substr(trim($note), 0, 200), 'fk_id_user' => $u->id ?? null, 'changed_by' => $u->display_name ?: $u->login_id, 'changed_at' => now()]);
        TaskEngine::close('SAL_DS_MARG', [$saleId], 'Accepted with a note');
        return ['message' => 'Accepted. The note is kept on the line.'];
    }

    public static function why(object $s): string { return str_starts_with((string) $s->sku, 'NOSKU-') ? 'No SKU' : 'SKU not in inventory'; }

    // ---------- R-A_SALES-22: one run at a time ----------
    public static function locked(callable $fn, int $wait = 60)
    {
        $name = 'ds_match_' . (int) Context::ent();
        if ((int) (DB::selectOne('SELECT GET_LOCK(?, ?) AS l', [$name, $wait])->l ?? 0) !== 1) throw new \InvalidArgumentException('Drop shipping matching is busy (another import or Re-match is running). Try again in a minute.');
        try { return $fn(); } finally { DB::selectOne('SELECT RELEASE_LOCK(?) AS l', [$name]); }
    }

    // ---------- match the not-matched lines of one shop + month and save the result ----------
    /**
     * $ids = sale line ids (Pending, not matched) of ONE shop and ONE batch month. $excludeBatch = the batch being replaced (its links do not count).
     * Returns ['matched' => n, 'still' => n, 'warnings' => [...], 'conflicts' => [...], 'no_items' => bool]. Call inside a transaction, under locked().
     */
    public static function run(array $ids, int $shopId, int $month, int $year, ?int $excludeBatch = null, bool $log = false, ?object $u = null): array
    {
        $ent = (int) Context::ent(); $out = ['matched' => 0, 'still' => 0, 'warnings' => [], 'conflicts' => [], 'no_items' => false]; if (! $ids) return $out;
        $shop = DB::table('t_branches')->where('id', $shopId)->first(); $mn = SalesImport::monthName($month, $year); $min = self::minMatch($ent);
        $lines = DB::table('t_sales')->whereIn('id', $ids)->where('status', 'Pending')->where('sale_source', 'unmatched')->where(fn ($w) => $w->whereNull('f_locked')->orWhere('f_locked', 0))->orderBy('id')->lockForUpdate()->get();
        $from = sprintf('%04d-%02d-01', $year, $month); $to = date('Y-m-t', strtotime($from));
        $items = DB::table('drp_items')->where('fk_id_ent', $ent)->where('fk_id_branch', $shopId)->where(fn ($w) => $w->where(fn ($x) => $x->where('period_month', $month)->where('period_year', $year))
            ->orWhere(fn ($x) => $x->where(fn ($y) => $y->whereNull('period_month')->orWhere('period_month', 0))->whereBetween('d_order', [$from, $to])))->orderBy('id')->get();
        if ($items->isEmpty()) {
            $out['no_items'] = true; $out['warnings'][] = 'Shop ' . trim((string) $shop->shop_no) . " has no Dropshipping Inventory items for $mn, so no drop shipping line could be matched. Upload the drop shipping CSV for this shop and month, then use Re-match on the Sales page.";
            foreach ($lines as $l) DB::table('t_sales')->where('id', $l->id)->update(['ds_match_count' => 0, 'ds_note' => mb_substr(self::why($l) . "; no drop shipping item matched (best 0 of 5, $min needed)", 0, 255), 'buy_rate' => 0, 'buy_amount' => 0, 'profit' => 0, 'cost_source' => 'unmatched']);
            $out['still'] = $lines->count(); return $out;
        }
        [$own, $auto] = self::ownPhones($items, $ent);
        if ($auto) $out['warnings'][] = "$auto phone number(s) appear on drop shipping orders of 3 or more different buyers (your own number), so phone was not used for them";
        $taken = [];
        foreach (DB::table('t_sales as s')->leftJoin('t_sale_imports as i', 'i.id', '=', 's.fk_id_import')->whereIn('s.fk_drp_item', $items->pluck('id'))->when($excludeBatch, fn ($q) => $q->where('s.fk_id_import', '<>', $excludeBatch))->get(['s.fk_drp_item', 's.order_no', 's.d_order', 'i.period_month', 'i.period_year']) as $t)
            $taken[(int) $t->fk_drp_item] = "order {$t->order_no} (" . ($t->period_month ? date('M Y', mktime(0, 0, 0, (int) $t->period_month, 1, (int) $t->period_year)) : date('M Y', strtotime((string) $t->d_order))) . ' import)';
        $a = self::assign($lines, $items, $taken, $own, $min); $byId = $items->keyBy('id'); $plat = DB::table('drp_platforms')->pluck('platform_name', 'id'); $costCur = [];
        foreach ($lines as $l) {
            if (isset($a['assign'][$l->id])) {
                $it = $byId[$a['assign'][$l->id]['item']]; $f = $a['assign'][$l->id]['fields']; $cur = strtoupper(trim((string) $it->currency)) ?: Fx::base($ent); $date = (string) ($it->d_order ?: $l->d_order);
                $buy = Fx::toBase((float) $it->total_cost, $cur, $date, $ent); $total = (float) $l->total_amount; if ($cur !== Fx::base($ent)) $costCur[$cur] = 1;
                $tied = array_values(array_diff(collect($a['conflicts'])->firstWhere('line', (int) $l->id)['items'] ?? [], [(int) $it->id]));      // other supplier orders with the same score: kept for a manual pick
                $upd = ['sale_source' => 'ds', 'fk_drp_item' => $it->id, 'ds_match_count' => $f['n'], 'ds_match_json' => json_encode($f + ($tied ? ['tied' => $tied] : [])), 'ds_note' => '', 'sale_rate' => $total, 'buy_rate' => 0, 'buy_amount' => $buy, 'profit' => round($total - $buy, 2), 'd_buy' => $date, 'cost_source' => 'dropship', 'raw_price' => 0, 'carrier_rate' => 0, 'packing_rate' => 0, 'updated_at' => now()];
                DB::table('t_sales')->where('id', $l->id)->where('status', 'Pending')->where('sale_source', 'unmatched')->update($upd); $out['matched']++; self::marginTask((int) $l->id);
                if ($log) { $key = uniqid('rm'); $who = $u ? ($u->display_name ?: $u->login_id) : 'system'; $m = fn ($v) => number_format((float) $v, 2, '.', '');
                    foreach ([['sale_source', 'Inventory', 'Not matched', 'Drop shipping (' . ($plat[$it->fk_platform] ?? 'platform') . ')'], ['fk_drp_item', 'Drop shipping item', '', "#{$it->id} - {$f['n']} of 5 columns matched"], ['buy_amount', 'Buy Amount', $m($l->buy_amount), $m($buy)], ['profit', 'Profit (recalculated)', $m($l->profit), $m($total - $buy)]] as [$fn, $fl, $old, $new])
                        DB::table('t_sale_change_log')->insert(['fk_id_sale' => $l->id, 'save_key' => $key, 'action' => 'Re-match', 'field_name' => $fn, 'field_label' => $fl, 'old_value' => $old, 'new_value' => $new, 'fk_id_user' => $u->id ?? null, 'changed_by' => $who, 'changed_at' => now()]); }
            } else {
                DB::table('t_sales')->where('id', $l->id)->update(['ds_match_count' => $a['best'][$l->id] ?? 0, 'ds_note' => mb_substr(self::why($l) . '; ' . ($a['note'][$l->id] ?? "no drop shipping item matched (best 0 of 5, $min needed)"), 0, 255), 'buy_rate' => 0, 'buy_amount' => 0, 'profit' => 0, 'cost_source' => 'unmatched', 'updated_at' => now()]);
                $out['still']++;
            }
        }
        if ($costCur) $out['warnings'][] = 'Some matched drop shipping items cost in ' . implode(', ', array_keys($costCur)) . '. Their cost was converted to ' . Fx::base($ent) . ' at the rate of the supplier order date.';
        $out['conflicts'] = $a['conflicts'];
        return $out;
    }

    // ---------- R-A_SALES-25: Re-match on the current filter ----------
    public static function rematch(array $f, ?object $u): array
    {
        Perm::need($u, 'orders.dsRematch');
        if (empty($f['marketplace']) && empty($f['batch'])) throw new \InvalidArgumentException('Choose a marketplace first.');
        $lines = SaleLines::query($f, $u)->where('s.status', 'Pending')->where('s.sale_source', 'unmatched')->where(fn ($w) => $w->whereNull('s.f_locked')->orWhere('s.f_locked', 0))->leftJoin('t_sale_imports as i', 'i.id', '=', 's.fk_id_import')
            ->orderBy('s.id')->limit(5000)->get(['s.id', 's.fk_id_branch', 's.d_order', 'i.period_month', 'i.period_year']);
        if ($lines->isEmpty()) return ['message' => 'There are no Pending not matched lines in this view.', 'matched' => 0, 'total' => 0, 'still' => 0, 'warnings' => []];
        $groups = []; foreach ($lines as $l) { $m = (int) ($l->period_month ?: date('n', strtotime((string) $l->d_order))); $y = (int) ($l->period_year ?: date('Y', strtotime((string) $l->d_order))); $groups[$l->fk_id_branch . ':' . $m . ':' . $y][] = (int) $l->id; }
        try {
            $r = self::locked(fn () => DB::transaction(function () use ($groups, $u) {
                $t = ['matched' => 0, 'still' => 0, 'warnings' => [], 'conflicts' => []];
                foreach ($groups as $k => $ids) { [$shop, $m, $y] = array_map('intval', explode(':', $k)); $x = self::run($ids, $shop, $m, $y, null, true, $u); $t['matched'] += $x['matched']; $t['still'] += $x['still']; $t['warnings'] = array_merge($t['warnings'], $x['warnings']); $t['conflicts'] = array_merge($t['conflicts'], $x['conflicts']);
                    $period = sprintf('%04d-%02d', $y, $m); $left = DB::table('t_sales')->where('fk_id_branch', $shop)->where('sale_source', 'unmatched')->whereRaw("DATE_FORMAT(d_order,'%Y-%m') = ?", [$period])->where('status', 'Pending')->count(); if (! $left) TaskEngine::close('SAL_UNMATCHED', [$shop, $period], 'All lines matched'); }
                return $t;
            }));
        } catch (\InvalidArgumentException $e) { throw $e; } catch (\Throwable $e) { report($e); throw new \InvalidArgumentException('Re-match failed, nothing was changed.'); }
        $n = $lines->count();
        return ['message' => "{$r['matched']} of $n not matched line(s) are now matched to drop shipping items. {$r['still']} still not matched.", 'matched' => $r['matched'], 'total' => $n, 'still' => $r['still'], 'warnings' => array_values(array_unique($r['warnings'])), 'conflicts' => $r['conflicts']];
    }

    /** After a supplier-orders file of a shop + month is applied (event DS_BATCH_APPLIED): its not-matched Pending lines are matched again by themselves. */
    public static function afterDsBatch(int $shopId, int $month, int $year): array
    {
        $mp = (int) DB::table('t_branches')->where('id', $shopId)->value('fk_id_marketplace'); $from = sprintf('%04d-%02d-01', $year, $month);
        $u = \Illuminate\Support\Facades\Auth::user();
        $ids = DB::table('t_sales as s')->leftJoin('t_sale_imports as i', 'i.id', '=', 's.fk_id_import')->where('s.fk_id_ent', Context::ent())->where('s.fk_id_branch', $shopId)->where('s.status', 'Pending')->where('s.sale_source', 'unmatched')
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('i.period_month', $month)->where('i.period_year', $year))->orWhere(fn ($x) => $x->whereNull('i.id')->whereBetween('s.d_order', [$from, date('Y-m-t', strtotime($from))])))->orderBy('s.id')->limit(5000)->pluck('s.id')->all();
        if (! $ids) return ['message' => 'There are no Pending not matched lines in this view.', 'matched' => 0, 'total' => 0, 'still' => 0];
        $r = self::locked(fn () => DB::transaction(fn () => self::run($ids, $shopId, $month, $year, null, true, $u)));
        return ['message' => "{$r['matched']} of " . count($ids) . " not matched line(s) are now matched to drop shipping items. {$r['still']} still not matched.", 'matched' => $r['matched'], 'total' => count($ids), 'still' => $r['still'], 'warnings' => $r['warnings']];
    }

    // ---------- R-A_SALES-21: lines where two supplier orders had the same score – a person picks ----------
    /** Drop shipping lines (Pending) whose match was a tie, each with the other candidates that are still free. */
    public static function conflicts(array $f, ?object $u): array
    {
        $see = $u && Perm::has($u, 'orders.dsSeeContact'); $out = [];
        foreach (SaleLines::query($f, $u)->where('s.sale_source', 'ds')->where('s.status', 'Pending')->where('s.ds_match_json', 'like', '%"tied"%')->orderBy('s.id')->limit(500)->get(['s.id', 's.order_no', 's.cust_name', 's.qty', 's.total_amount', 's.fk_drp_item', 's.ds_match_count', 's.ds_match_json', 's.fk_id_branch']) as $s) {
            $ids = array_map('intval', (array) (json_decode((string) $s->ds_match_json, true)['tied'] ?? [])); $free = DB::table('drp_items')->whereIn('id', $ids)->whereNotIn('id', DB::table('t_sales')->whereNotNull('fk_drp_item')->select('fk_drp_item'))->get();
            if ($free->isEmpty()) continue; $cur = DB::table('drp_items')->where('id', $s->fk_drp_item)->first();
            $row = fn ($i) => $i ? ['id' => (int) $i->id, 'order_no' => $i->order_no, 'date' => $i->d_order, 'name' => $i->ship_name, 'postcode' => $i->ship_postcode, 'phone' => $see ? (string) $i->ship_phone : self::maskPhone($i->ship_phone), 'title' => mb_substr((string) $i->item_title, 0, 80), 'qty' => (int) $i->qty, 'cost' => (float) $i->total_cost] : null;
            $out[] = ['sale' => (int) $s->id, 'order_no' => $s->order_no, 'buyer' => $s->cust_name, 'qty' => (int) $s->qty, 'total' => (float) $s->total_amount, 'n' => (int) $s->ds_match_count, 'current' => $row($cur), 'others' => $free->map($row)->all()];
        }
        return ['rows' => $out, 'can_pick' => $u && Perm::has($u, 'orders.dsLink'), 'note' => 'These lines matched two or more supplier orders equally well. The tool took the first; pick another one if it is the right order.'];
    }

    /** Manual pick: the line takes another supplier order of its tie. $itemId = 0 keeps the current one and closes the question. */
    public static function pick(int $saleId, int $itemId, ?object $u): array
    {
        Perm::need($u, 'orders.dsLink');
        return self::locked(fn () => DB::transaction(function () use ($saleId, $itemId, $u) {
            $s = DB::table('t_sales')->where('id', $saleId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? abort(404, 'Sale line not found.');
            if (! Perm::shopAllowed($u, (int) $s->fk_id_branch)) abort(403, "You don't have rights for this shop.");
            if ($s->sale_source !== 'ds' || $s->status !== 'Pending' || (int) $s->f_locked) throw new \InvalidArgumentException('Only a Pending drop shipping line can be changed.');
            $j = (array) json_decode((string) $s->ds_match_json, true); $tied = array_map('intval', (array) ($j['tied'] ?? [])); $who = $u->display_name ?: $u->login_id;
            if ($itemId === 0 || $itemId === (int) $s->fk_drp_item) { unset($j['tied']); DB::table('t_sales')->where('id', $saleId)->update(['ds_match_json' => json_encode($j + ['picked' => true])]); return ['message' => 'Kept. The line is not asked about again.']; }
            if (! in_array($itemId, $tied, true)) throw new \InvalidArgumentException('That supplier order is not one of the equal matches of this line.');
            $it = DB::table('drp_items')->where('id', $itemId)->where('fk_id_branch', $s->fk_id_branch)->first() ?? throw new \InvalidArgumentException('Drop shipping item not found for this shop.');
            if ($o = DB::table('t_sales')->where('fk_drp_item', $itemId)->first()) throw new \InvalidArgumentException("This drop shipping item is already linked to order {$o->order_no}.");
            $f = self::score(self::saleSide($s), $it); $cur = strtoupper(trim((string) $it->currency)) ?: Fx::base(); $buy = Fx::toBase((float) $it->total_cost, $cur, (string) ($it->d_order ?: $s->d_order), (int) $s->fk_id_ent); $total = (float) $s->total_amount;
            DB::table('t_sales')->where('id', $saleId)->update(['fk_drp_item' => $it->id, 'ds_match_count' => $f['n'], 'ds_match_json' => json_encode($f + ['picked' => true]), 'buy_amount' => $buy, 'profit' => round($total - $buy, 2), 'd_buy' => $it->d_order, 'f_edited' => 1, 'updated_at' => now()]);
            DB::table('t_sale_change_log')->insert(['fk_id_sale' => $saleId, 'save_key' => uniqid('pk'), 'action' => 'Pick drop shipping item', 'field_name' => 'fk_drp_item', 'field_label' => 'Drop shipping item', 'old_value' => '#' . $s->fk_drp_item, 'new_value' => "#{$it->id} (picked by hand between equal matches)", 'fk_id_user' => $u->id ?? null, 'changed_by' => $who, 'changed_at' => now()]);
            self::marginTask($saleId);
            return ['message' => "The line now uses drop shipping item #{$it->id}."];
        }));
    }

    // ---------- R-A_SALES-25: every morning each shop VA gets the list of the lines still not matched ----------
    /** One message per person per day (bell always; WhatsApp when the person has a number). Returns the number of people told. */
    public static function digest(): int
    {
        $by = [];
        foreach (DB::table('t_sales as s')->join('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.sale_source', 'unmatched')->where('s.status', 'Pending')->where('b.f_active', 1)->whereNotNull('b.fk_id_seller')->where('b.fk_id_seller', '>', 0)
            ->groupBy('b.id', 'b.shop_no', 'b.fk_id_seller', 'b.fk_id_ent')->selectRaw('b.id, b.shop_no, b.fk_id_seller va, b.fk_id_ent ent, COUNT(*) n')->get() as $r) { $by[$r->va]['ent'] = (int) $r->ent; $by[$r->va]['shops'][] = "{$r->shop_no}: {$r->n}"; $by[$r->va]['n'] = ($by[$r->va]['n'] ?? 0) + (int) $r->n; }
        $told = 0;
        foreach ($by as $va => $d) {
            $acc = DB::table('t_account')->where('id', $va)->first(); if (! $acc) continue; $text = "{$d['n']} sale line(s) still not matched – " . implode(', ', $d['shops']);
            $user = $acc->login_id ? DB::table('t_users')->where('login_id', $acc->login_id)->where('f_active', 1)->first() : null; $done = false;
            if ($user && ! DB::table('tm_notifications')->where('user_id', $user->id)->where('text', 'like', '%still not matched%')->where('created_at', '>=', today())->exists()) { Notify::push((int) $user->id, 'TASK', $text, '#/sales'); $done = true; }
            if (($p = Connect\Messenger::whatsappOf((int) $va)) && ! DB::table('tm_messages')->where('event_key', 'NMD:' . $va . ':' . date('Y-m-d'))->exists()) {      // one a day (R-G_CORE-46 one queue)
                Connect\Messenger::queueWa((int) $d['ent'], (int) $va, $p, 'NOT_MATCHED_DIGEST', ['name' => trim($acc->first_name . ' ' . $acc->sur_name), 'count' => $d['n'], 'shops' => implode(', ', $d['shops']), 'link' => rtrim((string) config('app.url'), '/') . '/#/sales'], null, null, 'NMD:' . $va . ':' . date('Y-m-d')); $done = true; }
            if ($done) $told++;
        }
        return $told;
    }

    // ---------- R-A_SALES-26: the explanation, with the supplier side masked ----------
    public static function maskPhone(?string $p): string { $d = preg_replace('/\D/', '', (string) $p); return $d === '' ? '' : (strlen($d) <= 3 ? '•••' : '••••••' . substr($d, -3)); }

    public static function explain(int $saleId, ?object $u): array
    {
        $s = DB::table('t_sales')->where('id', $saleId)->where('fk_id_ent', Context::ent())->first() ?? abort(404, 'Sale line not found.');
        if (! Perm::shopAllowed($u, (int) $s->fk_id_branch)) abort(403, "You don't have rights for this shop.");
        if ($s->sale_source !== 'ds') throw new \InvalidArgumentException('This is not a drop shipping line.');
        $it = DB::table('drp_items')->where('id', $s->fk_drp_item)->first(); $see = $u && Perm::has($u, 'orders.dsSeeContact'); $sale = self::saleSide($s); $n = (int) $s->ds_match_count;
        $out = ['title' => "Drop shipping match: $n of 5 columns (order {$s->order_no})", 'n' => $n, 'colour' => $n >= 5 ? '#28a745' : ($n === 4 ? '#20c997' : '#ffc107'), 'note' => 'At least ' . self::minMatch() . ' of the 5 columns must match. City, postcode and phone are looked for inside Cust. Contacts.',
            'can_link' => $u && Perm::has($u, 'orders.dsLink') && $s->status === 'Pending' && ! (int) $s->f_locked, 'sale' => $saleId];
        if (! $it) return $out + ['item' => null, 'gone' => 'The drop shipping item was deleted from Dropshipping Inventory.', 'rows' => []];
        $f = self::score($sale, $it, self::ownPhones(DB::table('drp_items')->where('fk_id_branch', $it->fk_id_branch)->where('period_month', $it->period_month)->where('period_year', $it->period_year)->get(['ship_phone', 'ship_name']))[0]);
        $sv = ['name' => $sale['name'], 'address' => $sale['address'] ?? ($f['address'] ? $sale['contacts'] : ''), 'city' => $sale['city'] ?? ($f['city'] ? (string) $it->ship_city : ''), 'postcode' => $sale['postcode'] ?? ($f['postcode'] ? (string) $it->ship_postcode : ''), 'phone' => $sale['phone'] ?? (self::phones($sale['contacts'])[0] ?? '')];
        $dv = ['name' => (string) $it->ship_name, 'address' => $see ? (string) $it->ship_address : (trim((string) $it->ship_address) === '' ? '' : '•••'), 'city' => (string) $it->ship_city, 'postcode' => (string) $it->ship_postcode, 'phone' => $see ? (string) $it->ship_phone : self::maskPhone($it->ship_phone)];
        $rows = []; foreach (self::FIELDS as $k => $label) $rows[] = ['column' => $label, 'drp' => trim($dv[$k]) === '' ? 'empty' : $dv[$k], 'sale' => trim((string) $sv[$k]) === '' ? 'not found' : (string) $sv[$k], 'match' => (bool) $f[$k]];
        return $out + ['item' => ['id' => $it->id, 'order_no' => $it->order_no, 'date' => $it->d_order, 'qty' => (int) $it->qty, 'cost' => number_format((float) $it->total_cost, 2) . ' ' . (strtoupper((string) $it->currency) ?: Fx::base()), 'title' => $it->item_title], 'rows' => $rows, 'masked' => ! $see];
    }

    // ---------- manual Link / Unlink with a reason ----------
    public static function link(int $saleId, int $itemId, string $reason, ?object $u): array
    {
        Perm::need($u, 'orders.dsLink'); if (mb_strlen(trim($reason)) < 3) throw new \InvalidArgumentException('Write the reason.');
        return self::locked(fn () => DB::transaction(function () use ($saleId, $itemId, $reason, $u) {
            $s = DB::table('t_sales')->where('id', $saleId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? abort(404, 'Sale line not found.');
            if (! Perm::shopAllowed($u, (int) $s->fk_id_branch)) abort(403, "You don't have rights for this shop.");
            if ($s->status !== 'Pending' || (int) $s->f_locked) throw new \InvalidArgumentException("Only Pending lines can be changed. This line is {$s->status}.");
            if ($s->sale_source !== 'unmatched') throw new \InvalidArgumentException('Only a not matched line can be linked to a drop shipping item.');
            $it = DB::table('drp_items')->where('id', $itemId)->where('fk_id_ent', $s->fk_id_ent)->where('fk_id_branch', $s->fk_id_branch)->first() ?? throw new \InvalidArgumentException('Drop shipping item not found for this shop.');
            if ($o = DB::table('t_sales')->where('fk_drp_item', $itemId)->first()) throw new \InvalidArgumentException("This drop shipping item is already linked to order {$o->order_no}.");
            $f = self::score(self::saleSide($s), $it); $cur = strtoupper(trim((string) $it->currency)) ?: Fx::base(); $buy = Fx::toBase((float) $it->total_cost, $cur, (string) ($it->d_order ?: $s->d_order), (int) $s->fk_id_ent); $total = (float) $s->total_amount;
            DB::table('t_sales')->where('id', $s->id)->update(['sale_source' => 'ds', 'fk_drp_item' => $it->id, 'ds_match_count' => $f['n'], 'ds_match_json' => json_encode($f + ['manual' => true]), 'ds_note' => '', 'sale_rate' => $total, 'buy_rate' => 0, 'buy_amount' => $buy, 'profit' => round($total - $buy, 2), 'd_buy' => $it->d_order, 'cost_source' => 'dropship', 'f_edited' => 1, 'updated_at' => now()]);
            DB::table('t_sale_change_log')->insert(['fk_id_sale' => $s->id, 'save_key' => uniqid('dl'), 'action' => 'Link drop shipping', 'field_name' => 'fk_drp_item', 'field_label' => 'Drop shipping item', 'old_value' => 'Not matched', 'new_value' => "#{$it->id} by hand: " . mb_substr(trim($reason), 0, 180), 'fk_id_user' => $u->id ?? null, 'changed_by' => $u->display_name ?: $u->login_id, 'changed_at' => now()]);
            self::marginTask((int) $s->id);
            return ['message' => "Linked to drop shipping item #{$it->id}."];
        }));
    }

    public static function unlink(int $saleId, string $reason, ?object $u): array
    {
        Perm::need($u, 'orders.dsLink'); if (mb_strlen(trim($reason)) < 3) throw new \InvalidArgumentException('Write the reason.');
        return DB::transaction(function () use ($saleId, $reason, $u) {
            $s = DB::table('t_sales')->where('id', $saleId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? abort(404, 'Sale line not found.');
            if (! Perm::shopAllowed($u, (int) $s->fk_id_branch)) abort(403, "You don't have rights for this shop.");
            if ($s->sale_source !== 'ds') throw new \InvalidArgumentException('This is not a drop shipping line.');
            if ($s->status !== 'Pending' || (int) $s->f_locked) throw new \InvalidArgumentException("Only Pending lines can be changed. This line is {$s->status}.");
            DB::table('t_sales')->where('id', $s->id)->update(['sale_source' => 'unmatched', 'fk_drp_item' => null, 'ds_match_json' => null, 'ds_note' => mb_substr(self::why($s) . '; unlinked by hand: ' . trim($reason), 0, 255), 'buy_rate' => 0, 'buy_amount' => 0, 'profit' => 0, 'cost_source' => 'unmatched', 'd_buy' => null, 'f_edited' => 1, 'updated_at' => now()]);
            DB::table('t_sale_change_log')->insert(['fk_id_sale' => $s->id, 'save_key' => uniqid('du'), 'action' => 'Unlink drop shipping', 'field_name' => 'fk_drp_item', 'field_label' => 'Drop shipping item', 'old_value' => '#' . $s->fk_drp_item, 'new_value' => 'Not matched: ' . mb_substr(trim($reason), 0, 180), 'fk_id_user' => $u->id ?? null, 'changed_by' => $u->display_name ?: $u->login_id, 'changed_at' => now()]);
            self::marginTask((int) $s->id);
            return ['message' => 'The line is not matched again.'];
        });
    }

    /** Free supplier orders of the line's shop and month that could be linked by hand, best score first. */
    public static function candidates(int $saleId, ?object $u): array
    {
        $s = DB::table('t_sales as s')->leftJoin('t_sale_imports as i', 'i.id', '=', 's.fk_id_import')->where('s.id', $saleId)->where('s.fk_id_ent', Context::ent())->first(['s.*', 'i.period_month', 'i.period_year']) ?? abort(404, 'Sale line not found.');
        if (! Perm::shopAllowed($u, (int) $s->fk_id_branch)) abort(403, "You don't have rights for this shop.");
        $m = (int) ($s->period_month ?: date('n', strtotime((string) $s->d_order))); $y = (int) ($s->period_year ?: date('Y', strtotime((string) $s->d_order))); $sale = self::saleSide($s); $rows = [];
        foreach (DB::table('drp_items')->where('fk_id_branch', $s->fk_id_branch)->where('period_month', $m)->where('period_year', $y)->whereNotIn('id', DB::table('t_sales')->whereNotNull('fk_drp_item')->select('fk_drp_item'))->limit(2000)->get() as $it) { $f = self::score($sale, $it);
            $rows[] = ['id' => $it->id, 'order_no' => $it->order_no, 'date' => $it->d_order, 'name' => $it->ship_name, 'title' => mb_substr((string) $it->item_title, 0, 80), 'qty' => (int) $it->qty, 'cost' => (float) $it->total_cost, 'n' => $f['n']]; }
        usort($rows, fn ($a, $b) => [$b['n'], $a['id']] <=> [$a['n'], $b['id']]);
        return ['rows' => array_slice($rows, 0, 50), 'line' => ['order_no' => $s->order_no, 'buyer' => $s->cust_name, 'best' => (int) $s->ds_match_count]];
    }

    // ---------- R-A_SALES-24: the not-matched CSV ----------
    public static function guard(string $v): string
    {
        if ($v === '') return $v;
        if (in_array($v[0], ['=', '@', "\t", "\r"], true)) return "'" . $v;
        if (($v[0] === '+' || $v[0] === '-') && ! preg_match('/^[+\-][\d\s.,()]*$/', $v)) return "'" . $v;      // phones and numbers stay as they are
        return $v;
    }

    /** $f = ['batch' => id] or the list filter. Returns ['name' => file name, 'body' => CSV with BOM, 'lines' => n]. */
    public static function csv(array $f, ?object $u, ?string $today = null): array
    {
        $day = date('Y-m-d', $today ? strtotime($today) : time());
        if (! empty($f['batch'])) { $b = SalesImport::batch((int) $f['batch']); $no = preg_replace('/[^A-Za-z0-9\-]/', '', (string) DB::table('t_branches')->where('id', $b->fk_id_branch)->value('shop_no'));
            $name = 'not_matched_' . $no . '_' . strtolower(date('M', mktime(0, 0, 0, (int) $b->period_month, 1, 2000))) . substr((string) $b->period_year, -2) . "_$day.csv"; }
        else { if (empty($f['marketplace'])) throw new \InvalidArgumentException('Choose a marketplace first.'); $name = "not_matched_sales_$day.csv"; }
        $q = SaleLines::query($f, $u)->where('s.sale_source', 'unmatched'); $n = (clone $q)->count();
        if ($n > 20000) throw new \InvalidArgumentException("Too many lines to export ($n). The CSV takes up to 20000 lines: narrow the filters and try again.");
        $rows = $q->orderBy('s.d_order')->orderBy('s.id')->get(['s.raw_csv', 's.ds_note', 's.order_no', 's.sku', 's.cust_name', 's.product_info', 's.qty', 's.total_amount', 's.d_order']); $heads = []; $data = [];
        foreach ($rows as $r) { $raw = json_decode((string) $r->raw_csv, true); if (! is_array($raw) || ! $raw) $raw = ['order no' => $r->order_no, 'order date' => $r->d_order, 'sku' => str_starts_with((string) $r->sku, 'NOSKU-') ? '' : $r->sku, 'buyer' => $r->cust_name, 'product' => $r->product_info, 'qty' => $r->qty, 'total' => $r->total_amount];
            foreach ($raw as $h => $v) $heads[$h] ??= 1; $data[] = [$raw, (string) $r->ds_note]; }
        $h = fopen('php://temp', 'w+'); fputcsv($h, array_merge(array_keys($heads), ['Why not matched']), ',', '"', '\\');
        foreach ($data as [$raw, $why]) fputcsv($h, array_map(fn ($v) => self::guard((string) $v), array_merge(array_map(fn ($k) => is_scalar($raw[$k] ?? '') ? (string) ($raw[$k] ?? '') : '', array_keys($heads)), [$why])), ',', '"', '\\');
        rewind($h);
        return ['name' => $name, 'body' => "\xEF\xBB\xBF" . stream_get_contents($h), 'lines' => $n];
    }
}

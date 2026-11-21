<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\DB;

/**
 * Search everything (Ctrl+K). One box finds: screens, things to create, actions, reports, settings (Choices), fields of forms,
 * rights (Owner) and records. A person only ever finds what he may open. Typing mistakes are forgiven for words of 4+ letters.
 * Before typing: what he pinned and what he opened lately.
 */
class Finder
{
    /** Named actions that are not a menu item: [title, words that also find it, url, right needed (null = everyone)]. */
    public const ACTIONS = [
        ['Sales lines', 'sales list edit sale refund carrier packing delete month loss lines', '#/sales', 'orders.SEE'],
        ['Import sales', 'import monthly sales file csv upload shop month sale columns fix not matched', '#/sales-import', 'orders.saleImport'],
        ['Upload drop shipping orders', 'drop shipping dropship supplier orders file upload csv platform aliexpress undo history', '#/ds-upload', 'orders.dsUpload'],
        ['Annual sale report', 'annual sale report year month by month va share store share net profit', '#/sales-annual', 'orders.saleReports'],
        ['Detailed sale report', 'detailed sale report shops months grid yearly', '#/sales-detailed', 'orders.saleReports'],
        ['VA statements', 'va statement payslip seller share month approve', '#/va-statements', 'orders.SEE'],
        ['Shop reminders', 'shop monthly reminders pending resolved template invoice', '#/shop-reminders', 'orders.SEE'],
        ['Carriers and postage rates', 'carrier preset matrix package service packing rate postage royal mail cheapest', '#/carriers', 'orders.SEE'],
        ['Marketplaces and their questions', 'marketplace master iso code country questions checklist shop sale columns', '#/marketplaces', 'orders.SEE'],
        ['New order', 'enter order add sale', '#/orders?do=new', 'orders.ENTER'],
        ['Load order file', 'import orders upload ebay temu amazon csv', '#/orders?do=file', 'orders.ENTER'],
        ['Make pick list', 'picking pick sheet warehouse', '#/orders?do=pick', 'orders.WAREHOUSE'],
        ['Load money file', 'settlement payout statement import tiktok amazon ebay', '#/settlements?do=file', 'money.EDIT'],
        ['Load bank file', 'bank statement csv excel import', '#/bank', 'money.EDIT'],
        ['Enter an expense', 'cost bill spend', '#/expenses?do=new', 'money.EXPENSE'],
        ['Refund an order', 'refund return money back', '#/refunds?do=new', 'money.refundManual'],
        ['Quick buy', 'local purchase buy now cash', '#/quick-buy', 'buying.BUY'],
        ['Exchange rates', 'fx currency rate usd cny', '#/fx', 'buying.SEE'],
        ['Opening stock', 'add stock first stock', '#/stock', 'stock.opening'],
        ['Move stock', 'transfer bin location', '#/stock', 'stock.CHANGE'],
        ['Adjust stock', 'write off damage correction', '#/stock', 'stock.CHANGE'],
        ['Stock count', 'count stocktake cycle count', '#/stock/counts', 'stock.SEE'],
        ['Carrier labels', 'label pdf royal mail evri upload', '#/labels', 'orders.labels'],
        ['Phone work screen', 'pick pack handover scan mobile', '#/work', 'orders.WAREHOUSE'],
        ['Month confirm', 'confirm sales month', '#/confirm', 'orders.CONFIRM'],
        ['Cash forecast', 'cash flow weeks payables', '#/forecast', 'money.forecast'],
        ['Profit views', 'profit by shop product month margin', '#/profit', 'money.SEE'],
        ['VAT return', 'vat tax boxes hmrc', '#/vat-return', 'finance.MONEY'],
        ['Roles and rights', 'permissions access who can role', '#/permissions', 'admin.permissions'],
        ['Users', 'people logins password staff account', '#/users', 'admin.users'],
        ['Choices', 'settings options decisions switches', '#/choices', 'admin.choices'],
        ['Audit trail', 'history who changed log', '#/audit', 'admin.audit'],
        ['Print queue', 'printing jobs reprint', '#/print-queue', 'print.QUEUE'],
        ['Printers', 'printer setup test page', '#/printers', 'print.SETUP'],
        ['My account', 'password 2-step two factor sign out profile', '#/me', null],
        ['My tasks', 'todo to do work list', '#/tasks', null],
    ];

    /** Scope chips of the search box: which groups / record forms each one keeps. */
    public const SCOPES = [
        'all' => null, 'pages' => ['Screens', 'Actions', 'Create', 'Reports', 'Settings', 'Fields', 'Rights'],
        'products' => '/product|listing|sku|brand|categor/i', 'orders' => '/sale|order|return|parcel|dispatch/i', 'people' => '/account|contact|people|person|staff|employee|supplier|customer|user|partner/i',
        'money' => '/bank|expense|settlement|payment|fin_|loan|budget|invoice|ledger|capital/i', 'tasks' => '/task/i',
    ];

    public static function find(string $q, ?object $u, int $per = 6, string $scope = 'all'): array
    {
        $q = trim(mb_substr($q, 0, 80)); $scope = array_key_exists($scope, self::SCOPES) ? $scope : 'all';
        if ($u === null) return ['q' => $q, 'groups' => []];
        if ($q === '') return ['q' => $q, 'groups' => self::start($u), 'scopes' => array_keys(self::SCOPES)];
        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower($q)), fn ($w) => $w !== ''));
        [$alts, $direct] = self::synonyms($q, $words);
        $one = mb_strlen($q) < 2;                       // one letter: only names that start with it (suggestions while typing)
        $groups = [];
        $add = function (string $name, array $items) use (&$groups, $per, $scope) {
            if (is_array(self::SCOPES[$scope]) && ! in_array($name, self::SCOPES[$scope], true)) return;
            if (is_string(self::SCOPES[$scope]) && $name !== 'Records') return;
            usort($items, fn ($a, $b) => $b['score'] <=> $a['score'] ?: mb_strlen($a['title']) <=> mb_strlen($b['title']) ?: strcmp($a['title'], $b['title']));
            if ($items) $groups[] = ['name' => $name, 'best' => $items[0]['score'], 'items' => array_map(fn ($x) => array_diff_key($x, ['score' => 1]), array_slice($items, 0, $name === 'Screens' ? 30 : $per))];
        };
        $hit = function (string $title, string $extra = '') use ($words, $alts, $one): int { $s = self::score($words, $title, $extra, $alts); return $one && $s < 70 ? 0 : $s; };

        // a synonym that opens a page directly ("calculator" → ICC)
        if ($direct) {      // only a page this person can really open
            $can = false;
            foreach (AppController::menu($u->role_code) as $sec) foreach ($sec['items'] as $m) if ($m['route'] === $direct[1]) $can = true;
            foreach (self::ACTIONS as [, , $url, $right]) if ($url === $direct[1] && (! $right || Perm::has($u, $right))) $can = true;
            if (! $can) $direct = null;
        }
        $top = $direct ? [['kind' => 'screen', 'title' => $direct[0], 'sub' => 'Go to', 'url' => $direct[1], 'score' => 200]] : [];

        // screens – "Section › Screen"; pages lately opened by this person come a little higher
        $it = []; $lately = DB::table('mo_recent')->where('user_id', $u->id)->where('updated_at', '>=', now()->subDays(30))->pluck('hits', 'url')->all();
        foreach (AppController::menu($u->role_code) as $sec) foreach ($sec['items'] as $m) {
            if ($direct && $m['route'] === $direct[1]) continue;
            $s = $hit($m['name'], ''); if (! $s && ($s = $hit($m['name'], $sec['name'] . ' ' . implode(' ', $m['forms'] ?? []) . ' ' . $m['no']))) $s = min($s, 35);   // only the path matches
            if ($s) $it[] = ['kind' => 'screen', 'title' => $m['name'], 'sub' => $sec['name'] . ' › ' . $m['name'] . ' · ' . $m['no'], 'url' => $m['route'], 'score' => $s + (isset($lately[$m['route']]) ? 5 : 0)];
        }
        $add('Screens', array_merge($top, $it));

        // actions
        $it = [];
        foreach (self::ACTIONS as [$t, $kw, $url, $right]) if ((! $right || Perm::has($u, $right)) && ($s = $hit($t, $kw))) $it[] = ['kind' => 'action', 'title' => $t, 'sub' => 'Action', 'url' => $url, 'score' => $s + 2];
        $add('Actions', $it);

        // create
        $it = [];
        foreach (AppController::createCatalogue($u->role_code) as $c) if ($s = $hit('New ' . $c['title'], 'create add ' . $c['key'] . ' ' . $c['dept'])) $it[] = ['kind' => 'create', 'title' => 'New ' . $c['title'], 'sub' => 'Create · ' . $c['dept'], 'url' => 'new:' . $c['key'], 'score' => $s];
        $add('Create', $it);

        // reports
        $it = [];
        foreach (ReportService::LIST as $k => $r) if (Perm::has($u, "report:$k") && ($s = $hit($r[1], $r[0] . ' report ' . $r[2]))) $it[] = ['kind' => 'report', 'title' => $r[1], 'sub' => 'Report · ' . $r[0], 'url' => "#/reports/$k", 'score' => $s];
        $add('Reports', $it);

        if (! $one) {
            // settings
            if (Perm::has($u, 'admin.choices')) {
                $it = [];
                foreach (Choices::LIST as $k => $c) if ($s = $hit($c[0], $c[1] . ' ' . str_replace('_', ' ', $k))) $it[] = ['kind' => 'setting', 'title' => $c[0], 'sub' => 'Choice (setting)', 'url' => '#/choices', 'score' => $s];
                $add('Settings', $it);
            }
            // rights
            if (Perm::has($u, 'admin.permissions')) {
                $it = [];
                foreach (Perm::catalogue() as $code => $c) if ($s = $hit($c[0], $c[1] . ' right permission')) $it[] = ['kind' => 'right', 'title' => $c[0], 'sub' => 'Right · ' . $c[1], 'url' => '#/permissions', 'score' => $s];
                $add('Rights', $it);
            }
            // fields of forms ("where do I type the HS code?") – "Form › Field", opens the form with the field focused
            $it = []; $seen = [];
            foreach (self::fieldIndex() as [$form, $ftitle, $label, $name, $help]) {
                if (! ($s = $hit($label))) continue;
                if (isset($seen[$form . $label]) || ! Perm::has($u, "form:$form:VIEW")) continue;
                $seen[$form . $label] = 1; $it[] = ['kind' => 'field', 'title' => $ftitle . ' › ' . $label, 'sub' => $help ?: 'Field', 'url' => "field:$form:$name", 'score' => $s];
                if (count($it) > 60) break;
            }
            $add('Fields', $it);
            // records
            $it = []; $keep = is_string(self::SCOPES[$scope]) ? self::SCOPES[$scope] : null;
            foreach (self::records($q, $words, $alts, $u) as $r) {
                if ($keep && ! preg_match($keep, $r->department . ' ' . $r->entity)) continue;
                $x = ['kind' => 'record', 'title' => $r->title, 'sub' => $r->subtitle, 'url' => $r->url, 'score' => self::score($words, (string) $r->title, (string) $r->search_text, $alts) ?: 30];
                if ($acts = self::recordActions($u, (string) $r->entity, (int) $r->entity_id, (string) $r->title)) $x['actions'] = $acts;
                $it[] = $x;
            }
            $add('Records', $it);
        }

        // order: pages first, then records (R-G_CORE-56); a typed code (it has a digit) puts the records first
        $order = ['Screens' => 1, 'Actions' => 2, 'Create' => 3, 'Records' => 4, 'Reports' => 5, 'Settings' => 6, 'Fields' => 7, 'Rights' => 8];
        $code = (bool) preg_match('/\d/', $q);
        $rank = fn ($g) => $g['name'] === 'Records' && $code && $g['best'] >= 50 ? 0 : $order[$g['name']];
        usort($groups, fn ($a, $b) => $rank($a) <=> $rank($b));
        foreach ($groups as &$g) { if (in_array($g['name'], ['Fields', 'Rights', 'Settings'], true)) $g['items'] = array_slice($g['items'], 0, 4); unset($g['best']); } unset($g);
        $n = array_sum(array_map(fn ($g) => count($g['items']), $groups));
        $out = ['q' => $q, 'groups' => $groups, 'count' => $n, 'scope' => $scope, 'scopes' => array_keys(self::SCOPES), 'hint' => null];
        if (! $n && ! $one) {
            $out['did_you_mean'] = self::didYouMean($words, $u);
            $out['hint'] = "No match for \"$q\". Try a SKU, an order number, a name or an amount – or open Products, Orders or People from the menu.";
            self::remember($u, $q, 0);          // every miss is kept, for the synonym list
        }
        return $out;
    }

    /** Words the query also means (sys_search_synonyms): [word => [other words]], and a page to open directly when the whole query is such a term. */
    public static function synonyms(string $q, array $words): array
    {
        $rows = self::$syn[(int) Context::ent()] ??= DB::table('sys_search_synonyms')->where('f_active', 1)->whereIn('fk_id_ent', [0, (int) Context::ent()])->get(['term', 'maps_to', 'target_url'])->all();
        $alts = []; $direct = null; $lq = mb_strtolower(trim($q));
        foreach ($rows as $r) {
            $term = mb_strtolower(trim((string) $r->term)); if ($term === '') continue;
            if ($term === $lq && $r->target_url) $direct = [ucfirst((string) $r->maps_to ?: $term), (string) $r->target_url];
            foreach ($words as $w) if ($w === $term) $alts[$w] = array_merge($alts[$w] ?? [], preg_split('/\s+/', mb_strtolower((string) $r->maps_to), -1, PREG_SPLIT_NO_EMPTY));
        }
        return [$alts, $direct];
    }

    /** The closest names to a query that found nothing (screens, actions, reports the person may open). */
    private static function didYouMean(array $words, object $u): array
    {
        $names = [];
        foreach (AppController::menu($u->role_code) as $sec) foreach ($sec['items'] as $m) $names[$m['name']] = $m['route'];
        foreach (self::ACTIONS as [$t, , $url, $right]) if (! $right || Perm::has($u, $right)) $names[$t] = $url;
        $best = [];
        foreach ($names as $name => $url) {
            $d = 99;
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) as $x) foreach ($words as $w) if (mb_strlen($w) >= 3) $d = min($d, levenshtein(self::stem($x), self::stem($w)));
            if ($d <= 3) $best[] = [$d, $name, $url];
        }
        usort($best, fn ($a, $b) => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));
        return array_map(fn ($x) => ['title' => $x[1], 'url' => $x[2], 'kind' => 'screen', 'sub' => 'Did you mean'], array_slice($best, 0, 3));
    }

    /** Buttons on a record result – only with the right. */
    private static function recordActions(object $u, string $entity, int $id, string $title): array
    {
        $a = [];
        if ($entity === 't_product_vendors') {
            if (Perm::has($u, 'stock.SEE')) $a[] = ['label' => 'Stock', 'url' => "#/stock/product/$id"];
            if (Perm::has($u, 'buying.BUY')) $a[] = ['label' => 'Quick buy', 'url' => '#/quick-buy'];
        }
        if ($entity === 't_src_po' && Perm::has($u, 'buying.SEE')) $a[] = ['label' => 'PO page', 'url' => "#/po/$id"];
        if ($entity === 't_src_cycles' && Perm::has($u, 'buying.SEE')) $a[] = ['label' => 'Cycle', 'url' => "#/cycle/$id"];
        if ($entity === 'src_research' && Perm::has($u, 'buying.SEE')) $a[] = ['label' => 'Workspace', 'url' => "#/research/$id"];
        return $a;
    }

    /** A scanned code (SKU, EAN, UPC, tracking number, order number, bin code): the one exact record, or null. */
    public static function scan(string $code, object $u): ?array
    {
        $code = trim($code); $ent = (int) Context::ent(); if ($code === '') return null;
        if (Perm::has($u, 'stock.SEE') || Perm::has($u, 'orders.SEE')) {
            $p = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('SKU_VENDOR', $code)->orWhere('ean', $code)->orWhere('upc', $code))->first(['id', 'SKU_VENDOR', 'product_name_vendor']);
            if ($p) return ['kind' => 'record', 'title' => $p->SKU_VENDOR . ' · ' . $p->product_name_vendor, 'sub' => 'Product', 'url' => Perm::has($u, 'stock.SEE') ? "#/stock/product/{$p->id}" : '#/rec/' . RecordService::formOfTable('t_product_vendors') . "/{$p->id}"];
        }
        if (Perm::has($u, 'orders.SEE')) {
            $s = Perm::mine(DB::table('t_sales'), 'fk_id_branch')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('tracking_no', $code)->orWhere('order_no', $code))->orderByDesc('id')->first(['id', 'order_no']);
            if ($s && ($f = RecordService::formOfTable('t_sales'))) return ['kind' => 'record', 'title' => 'Order ' . $s->order_no, 'sub' => 'Order line', 'url' => "#/rec/$f/{$s->id}"];
        }
        if (Perm::has($u, 'stock.SEE')) {
            $l = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('location_code', $code)->orWhere('barcode_value', $code))->first(['id', 'location_name']);
            if ($l && ($f = RecordService::formOfTable('cat_inventory_locations'))) return ['kind' => 'record', 'title' => (string) $l->location_name, 'sub' => 'Location', 'url' => "#/rec/$f/{$l->id}"];
        }
        return null;
    }

    // ---------- recent searches (sys_search_recent; SEARCH_RECENT_KEEP per person, pinned ones stay) ----------
    public static function remember(object $u, string $q, int $count, ?string $entity = null, ?int $entityId = null): void
    {
        $q = mb_substr(trim($q), 0, 200); if (mb_strlen($q) < 2) return;
        $w = ['user_id' => $u->id, 'fk_id_ent' => (int) Context::ent(), 'query_text' => $q];
        $have = DB::table('sys_search_recent')->where($w)->first();
        if ($have) DB::table('sys_search_recent')->where('id', $have->id)->update(['result_count' => $count, 'picked_entity' => $entity ?? $have->picked_entity, 'picked_entity_id' => $entityId ?? $have->picked_entity_id, 'created_at' => now()]);
        else DB::table('sys_search_recent')->insert($w + ['result_count' => $count, 'picked_entity' => $entity, 'picked_entity_id' => $entityId, 'f_pinned' => 0, 'created_at' => now()]);
        $keep = max(1, (int) Settings::get('SEARCH_RECENT_KEEP', Context::ent(), 20));
        $old = DB::table('sys_search_recent')->where('user_id', $u->id)->where('f_pinned', 0)->orderByDesc('created_at')->orderByDesc('id')->skip($keep)->take(500)->pluck('id');
        if ($old->isNotEmpty()) DB::table('sys_search_recent')->whereIn('id', $old)->delete();
    }

    public static function pinSearch(object $u, string $q, bool $on): void
    {
        self::remember($u, $q, 1);
        DB::table('sys_search_recent')->where(['user_id' => $u->id, 'fk_id_ent' => (int) Context::ent(), 'query_text' => mb_substr(trim($q), 0, 200)])->update(['f_pinned' => $on ? 1 : 0]);
    }

    /** "invoices" → "invoic", "invoice" → "invoic": words longer than 4 letters lose a final es / e / s. */
    public static function stem(string $w): string
    {
        if (mb_strlen($w) <= 4) return $w;
        foreach (['es', 'e', 's'] as $end) if (str_ends_with($w, $end)) return mb_substr($w, 0, -strlen($end));
        return $w;
    }

    /**
     * 0 = no match. Every typed word must be found (itself, its stem, a synonym, or with a small typing mistake).
     * exact title 100 · title starts 90 · a word starts 70 · inside 50 · in the extra words 40 · synonym 38 · typing mistake 25.
     */
    public static function score(array $words, string $title, string $extra = '', array $alts = []): int
    {
        $t = mb_strtolower($title); $e = mb_strtolower($extra); $tw = preg_split('/[^\p{L}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY); $ew = null; $total = 0;
        foreach ($words as $w) {
            $st = self::stem($w);
            if ($t === $w) $s = 100;
            elseif (str_starts_with($t, $w) || str_starts_with($t, $st)) $s = 90;
            elseif (array_filter($tw, fn ($x) => str_starts_with($x, $w) || str_starts_with($x, $st))) $s = 70;
            elseif (str_contains($t, $w)) $s = 50;
            elseif ($e !== '' && (str_contains($e, $w) || str_contains($e, $st))) $s = 40;
            else {
                $s = 0;
                foreach ($alts[$w] ?? [] as $alt) if (str_contains($t, $alt) || ($e !== '' && str_contains($e, $alt))) { $s = 38; break; }
                if (! $s && mb_strlen($w) >= 4) {
                    $max = mb_strlen($w) >= 8 ? 2 : 1; $ew ??= preg_split('/[^\p{L}\p{N}]+/u', $e, -1, PREG_SPLIT_NO_EMPTY);
                    foreach (array_merge($tw, $ew) as $x) if (abs(strlen($x) - strlen($w)) <= $max && levenshtein($x, $w) <= $max) { $s = 25; break; }
                }
            }
            if (! $s) return 0;
            $total += $s;
        }
        return (int) round($total / max(1, count($words)));
    }

    private static ?array $fields = null;
    private static array $syn = [];
    private static function fieldIndex(): array
    {
        if (self::$fields !== null) return self::$fields;
        $out = [];
        foreach (DB::table('sys_forms')->where('f_active', 1)->pluck('title', 'form_key') as $key => $title) {
            if (! Registry::exists($key) || ! Registry::table($key)) continue;
            foreach (Registry::fields($key) as $f) if (! empty($f['label']) && empty($f['secret'])) $out[] = [$key, $title, (string) $f['label'], (string) $f['field_name'], mb_substr((string) ($f['help'] ?? ''), 0, 160)];
        }
        return self::$fields = $out;
    }

    public static function forget(): void { self::$fields = null; self::$syn = []; }

    /** A typed amount: "1,250", "£1250.00" → [low, high] within 0.5 % (at least 0.01); "1200-1300" → that range. Null when the query is not an amount. */
    public static function amountRange(string $q): ?array
    {
        $c = preg_replace('/[£$€\s,]|(?i:gbp|usd|eur|pkr|cny)/u', '', $q);
        if (preg_match('/^(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/', $c, $m)) return [min((float) $m[1], (float) $m[2]), max((float) $m[1], (float) $m[2])];
        if (! preg_match('/^\d+(?:\.\d+)?$/', $c)) return null;
        $v = (float) $c; $d = max(0.01, $v * 0.005);
        return [$v - $d, $v + $d];
    }

    /** Records from the search index: every word must be in the row (or its synonym); amount, phone digits, e-mail; typing mistakes by sound; only forms he may see, only his shops. */
    private static function records(string $q, array $words, array $alts, object $u, int $limit = 30): array
    {
        $ent = Context::ent(); $digits = preg_replace('/\D+/', '', $q); $shops = Perm::shops($u); $amount = self::amountRange($q); $cols = ['title', 'subtitle', 'url', 'department', 'search_text', 'entity', 'entity_id'];
        $base = fn () => DB::table('sys_search_index')
            ->when($ent, fn ($x) => $x->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0)))
            ->when($shops !== null, fn ($x) => $x->where(fn ($w) => $w->whereNull('fk_id_branch')->orWhere('fk_id_branch', 0)->orWhereIn('fk_id_branch', $shops)));
        $rows = $base()->where(function ($w) use ($words, $alts, $digits, $amount, $q) {
            $w->where(function ($a) use ($words, $alts) {
                foreach ($words as $x) $a->where(function ($b) use ($x, $alts) {
                    $b->where('title', 'like', "%$x%")->orWhere('search_text', 'like', "%$x%");
                    foreach ($alts[$x] ?? [] as $alt) $b->orWhere('title', 'like', "%$alt%")->orWhere('search_text', 'like', "%$alt%");
                });
            });
            if (strlen($digits) >= 5) $w->orWhere('phone_norm', 'like', "%$digits%");
            if (str_contains($q, '@')) $w->orWhere('email_norm', 'like', '%' . mb_strtolower($q) . '%');
            if ($amount) $w->orWhereBetween('amount_value', $amount);
        })->orderByRaw('CASE WHEN title LIKE ? THEN 0 ELSE 1 END', [$words[0] . '%'])->orderByDesc('updated_at')->limit($limit)->get($cols)->all();
        if (! $rows && count($words) === 1 && mb_strlen($words[0]) >= 4 && trim($key = self::sound($words[0])) !== '')
            $rows = $base()->where('sound_key', 'like', "%$key%")->orderByDesc('updated_at')->limit($limit)->get($cols)->all();
        $ok = [];
        return array_values(array_filter($rows, fn ($r) => $ok[$r->department] ??= (Registry::exists((string) $r->department) && Perm::has($u, 'form:' . $r->department . ':VIEW'))));
    }

    /** Sound key of a text (one key per word), so "Ahmd" finds "Ahmed". */
    public static function sound(string $text): string
    {
        $out = [];
        foreach (preg_split('/[^a-z]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $w) if (strlen($w) >= 3 && ($m = metaphone($w)) !== '') $out[$m] = 1;
        return mb_substr(' ' . implode(' ', array_keys($out)) . ' ', 0, 64);
    }

    // ---------- before typing: pinned + recent ----------
    public static function start(object $u): array
    {
        $rows = DB::table('mo_recent')->where('user_id', $u->id)->where(fn ($w) => $w->where('fk_id_ent', (int) Context::ent())->orWhere('fk_id_ent', 0))->orderByDesc('f_pinned')->orderByDesc('updated_at')->orderByDesc('id')->limit(40)->get();
        $map = fn ($r) => ['kind' => $r->kind, 'title' => $r->title, 'sub' => $r->subtitle, 'url' => $r->url, 'pinned' => (bool) $r->f_pinned];
        $g = [];
        if ($p = $rows->where('f_pinned', 1)->map($map)->values()->all()) $g[] = ['name' => 'Pinned', 'items' => $p];
        if ($r = $rows->where('f_pinned', 0)->take(8)->map($map)->values()->all()) $g[] = ['name' => 'Opened lately', 'items' => $r];
        $rs = DB::table('sys_search_recent')->where('user_id', $u->id)->where('fk_id_ent', (int) Context::ent())->where(fn ($w) => $w->where('result_count', '>', 0)->orWhere('f_pinned', 1))->orderByDesc('f_pinned')->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get();
        if ($rs->isNotEmpty()) $g[] = ['name' => 'Recent searches', 'items' => $rs->map(fn ($r) => ['kind' => 'search', 'title' => $r->query_text, 'sub' => $r->f_pinned ? 'Pinned search' : 'Search again', 'url' => 'q:' . $r->query_text, 'pinned' => (bool) $r->f_pinned])->all()];
        $acts = [];
        foreach (self::ACTIONS as [$t, , $url, $right]) { if (! $right || Perm::has($u, $right)) $acts[] = ['kind' => 'action', 'title' => $t, 'sub' => 'Action', 'url' => $url]; if (count($acts) >= 6) break; }
        $g[] = ['name' => 'Try', 'items' => $acts];
        return $g;
    }

    /** Remember what was opened (from the search box or a record page); keeps the newest 60 per person, pinned ones for ever. */
    public static function opened(object $u, string $kind, string $title, ?string $sub, string $url): void
    {
        if ($url === '' || mb_strlen($url) > 255) return;
        $w = ['user_id' => $u->id, 'url' => $url];
        if (DB::table('mo_recent')->where($w)->exists()) DB::table('mo_recent')->where($w)->update(['title' => mb_substr($title, 0, 255), 'subtitle' => $sub ? mb_substr($sub, 0, 255) : null, 'hits' => DB::raw('hits + 1'), 'updated_at' => now()]);
        else DB::table('mo_recent')->insert($w + ['fk_id_ent' => str_starts_with($url, '#/rec/') ? (int) Context::ent() : 0, 'kind' => mb_substr($kind, 0, 20), 'title' => mb_substr($title, 0, 255), 'subtitle' => $sub ? mb_substr($sub, 0, 255) : null, 'created_at' => now(), 'updated_at' => now()]);
        $old = DB::table('mo_recent')->where('user_id', $u->id)->where('f_pinned', 0)->orderByDesc('updated_at')->skip(60)->take(500)->pluck('id');
        if ($old->isNotEmpty()) DB::table('mo_recent')->whereIn('id', $old)->delete();
    }

    public static function pin(object $u, string $url, bool $on, string $kind = 'screen', string $title = '', ?string $sub = null): void
    {
        if ($on && ! DB::table('mo_recent')->where(['user_id' => $u->id, 'url' => $url])->exists()) self::opened($u, $kind, $title ?: $url, $sub, $url);
        DB::table('mo_recent')->where(['user_id' => $u->id, 'url' => $url])->update(['f_pinned' => $on ? 1 : 0]);
    }
}

<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\DB;

/**
 * Owner 26-11 20:14 – "take me by the finger": on every screen one guide says where you are, what to do now (step by step), and what is wrong
 * at once – each item with the button that opens the exact place to put it right. Built from what the tool already knows: the menu help,
 * the list / record guidance (Guide), the setup steps, the person's tasks, the data-health checks and missing exchange rates.
 * Items: tone err (wrong – fix now) · warn (should be done) · todo (next step) · info.
 */
class GuideNow
{
    public static function for(object $u, int $ent, string $route, ?string $recKey = null, ?int $recId = null): array
    {
        $items = []; $add = function (string $tone, string $text, ?string $why = null, ?string $label = null, ?string $go = null) use (&$items) { $items[] = compact('tone', 'text', 'why', 'label', 'go'); };
        $base = explode('?', $route)[0];
        // where am I – the menu item of this address and its one-line explanation
        $where = null;
        foreach (AppController::menu((string) ($u->role_code ?? 'OWNER')) as $sec) foreach ($sec['items'] as $it) {
            $b = explode('?', (string) $it['route'])[0];
            $exact = $b === $base || ($base === '#/' && $b === '#/home');
            if (($exact && ! ($where['exact'] ?? false)) || (! $exact && ! ($where['exact'] ?? false) && $b !== '#/' && strlen($b) > 3 && str_starts_with($base, $b) && (! $where || strlen($b) > strlen($where['b']))))
                $where = ['b' => $b, 'exact' => $exact, 'title' => $it['name'], 'about' => $it['about'] ?? null, 'section' => $sec['name']];      // the first exact item wins (Home: the person's own home comes first)
        }
        // the record open on the screen: its next step and what stops it (Guide::record)
        if ($recKey && $recId && Registry::exists($recKey)) {
            try { $g = Guide::record($recKey, $recId, $u);
                foreach ($g['blocked'] as $b) $add('err', (string) ($b[0] ?? ''), 'This stops the record going further.', $b[1] ?? 'Fix', $b[2] ?? null);
                foreach ($g['next'] as $n) $add('todo', (string) ($n[0] ?? ''), null, $n[1] ?? 'Do it', is_string($n[2] ?? null) ? $n[2] : null);
                if (! $g['next'] && ! $g['blocked']) $add('info', $g['ok_text'] ?? 'Nothing is waiting on this record.');
            } catch (\Throwable $e) {}
        }
        // a list screen: how to add here
        if (preg_match('~^#/list/([\w-]+)~', $route, $m) && Registry::exists($m[1])) {
            try { $g = Guide::list($m[1], $u); $add('todo', $g['can_add'] ? 'To add here: ' . $g['add'] : $g['add'], $g['what'], $g['can_add'] ? '+ New' : null, $g['can_add'] ? 'action:new' : null); } catch (\Throwable $e) {}
        }
        // setup not finished (Owner)
        if (($u->role_code ?? '') === 'OWNER') {
            try { $s = SetupWizard::page($ent); if ($s['next']) { $n = collect($s['steps'])->firstWhere('key', $s['next']);
                $add('warn', 'Setup step ' . ($n['no'] ?? '') . ' of ' . $s['total'] . ': ' . ($n['title'] ?? ''), $n['what'] ?? null, 'Open setup', '#/setup'); } } catch (\Throwable $e) {}
        }
        // exchange rates missing for currencies in use (amounts left out of money pages until added)
        try {
            $baseCur = Fx::base($ent);
            $used = DB::table('t_src_po as p')->join('cat_currency as c', 'c.id', '=', 'p.fk_id_currency')->where('p.fk_id_ent', $ent)->where('p.f_active', 1)->distinct()->pluck('c.currency_code')->map(fn ($c) => strtoupper((string) $c))->filter(fn ($c) => $c !== '' && $c !== $baseCur)->values();
            $have = DB::table('fin_fx_rates')->whereIn('currency', $used->all() ?: ['-'])->distinct()->pluck('currency')->map(fn ($c) => strtoupper((string) $c))->all();
            foreach ($used->diff($have) as $c) $add('err', "No exchange rate for $c – purchases in $c are left out of the money pages.", 'Add the rate (date, 1 ' . $c . ' = ? ' . $baseCur . ') once; later dates can be added any time.', 'Add rate', '#/list/fin_fx_rate');
        } catch (\Throwable $e) {}
        // buying screens: what the sourcing cycles need now (the same checks as Needs action), most urgent first
        if (preg_match('~^#/(buying|cycle|cycle-extras|old-entry|list/(t_src_|src_|quick_buy))~', $base)) {
            try {
                $needs = ReadCache::remember('guide:needs:' . $ent, 300, fn () => collect(CycleAlerts::detect($ent))->sortBy(fn ($n) => [$n['p'], $n['due'] ?? '9999'])->take(5)->values()->all());
                foreach ($needs as $n) $add($n['p'] === 'P1' ? 'err' : 'warn', $n['title'] . ($n['detail'] ? ' – ' . $n['detail'] : ''), 'Cycle ' . ($n['cycle'] ?? '') . ' · ' . $n['chip'], 'Open', $n['opt']['fix_url'] ?? ($n['cycle'] ? '#/cycle/' . $n['cycle'] : '#/buying'));
            } catch (\Throwable $e) {}
        }
        // stock and product screens: the inventory checks (no location, no cost, below reorder …) – each opens the product list behind it
        if (preg_match('~^#/(stock|products|stock-alerts|sku-homes|putaway|inbound|icc|list/(t_product|product|location))~', $base)) {
            try { foreach (ReadCache::remember('guide:stock:' . $ent, 300, fn () => StockHealth::card($ent)['rows']) as $r) if (! empty($r['count']) && ! in_array($r['key'], ['private', 'pool', 'total'], true))
                $add(in_array($r['key'], ['nocost', 'mismatch', 'oos'], true) ? 'err' : 'warn', $r['label'] . ': ' . $r['figure'] . '.', 'Open the list and put each one right.', 'Show them', $r['link']); } catch (\Throwable $e) {}
        }
        // sales screens: shops whose sales are not loaded for more than 35 days
        if (preg_match('~^#/(sales|fulfilment|orders|sales-import|marketplaces|settlements)~', $base)) {
            try {
                $last = DB::table('t_sale_imports as i')->join('t_branches as b', 'b.id', '=', 'i.fk_id_branch')->where('i.fk_id_ent', $ent)->groupBy('i.fk_id_branch', 'b.shop_name')->selectRaw('b.shop_name n, MAX(COALESCE(i.confirmed_at, i.imported_at)) last')->get();
                $old = $last->filter(fn ($x) => ! $x->last || substr((string) $x->last, 0, 10) < date('Y-m-d', strtotime('-35 days')))->pluck('n')->take(5)->all();
                if ($old) $add('warn', 'Sales not loaded for over 35 days: ' . implode(', ', $old) . (count($old) === 5 ? ' …' : '') . '.', 'Load the marketplace order / money file of each shop.', 'Import sales', '#/sales-import');
            } catch (\Throwable $e) {}
        }
        // money screens: bank lines not matched yet
        if (preg_match('~^#/(money|bank|reconcil|forecast|ledger|list/fin_)~', $base)) {
            try { $n = (int) DB::table('fin_bank_transactions')->where('company_id', $ent)->where('match_status', 'UNMATCHED')->count();
                if ($n) $add('warn', "$n bank line(s) not matched to a sale, bill or payment.", 'Match each line (the tool suggests the match) so the books agree with the bank.', 'Match them', '#/money'); } catch (\Throwable $e) {}
        }
        // my tasks: late first
        try {
            $t = TaskLists::tiles($u);
            if ($t['late']) $add('err', $t['late'] . ' of your tasks are late.', 'Open them and finish, or move the date with a reason.', 'My tasks', '#/tasks');
            if ($t['due_today']) $add('warn', $t['due_today'] . ' task(s) due today.', null, 'My tasks', '#/tasks');
            if ($t['waiting_on_me']) $add('warn', $t['waiting_on_me'] . ' waiting for your answer (approval or someone stuck on you).', null, 'Open', '#/tasks');
            foreach (DB::table('tasks')->where('owner_user_id', $u->id)->whereIn('status', TaskLists::OPEN)->orderByRaw('due_at IS NULL')->orderBy('due_at')->limit(3)->get(['id', 'title', 'due_at']) as $x)
                $add('todo', 'Next task: ' . $x->title . ($x->due_at ? ' (due ' . substr((string) $x->due_at, 0, 10) . ')' : ''), null, 'Open task', '#/task/' . $x->id);
        } catch (\Throwable $e) {}
        // data health: the weak parts – on Home all of them, elsewhere the ones of this area
        try {
            $areas = ['costs' => ['#/sales', '#/home', '#/'], 'bank' => ['#/money', '#/bank', '#/home', '#/'], 'imports' => ['#/sales', '#/import', '#/home', '#/'], 'duplicates' => ['#/tasks', '#/home', '#/'], 'records' => ['#/home', '#/products', '#/']];
            foreach (ReadCache::remember('health:' . $ent, 600, fn () => DataHealth::score($ent))['parts'] as $p) {
                if ($p['score'] >= 90) continue; $here = collect($areas[$p['key']] ?? [])->contains(fn ($a) => $base === $a || ($a !== '#/' && str_starts_with($base, $a)));
                if ($here || $base === '#/home' || $base === '#/' || $base === '') $add($p['score'] < 70 ? 'err' : 'warn', $p['label'] . ': ' . $p['text'] . '.', 'Data health ' . $p['score'] . ' %.', 'Fix', $p['go'] ?? null);
            }
        } catch (\Throwable $e) {}
        $order = ['err' => 0, 'warn' => 1, 'todo' => 2, 'info' => 3]; usort($items, fn ($a, $b) => $order[$a['tone']] <=> $order[$b['tone']]);
        return ['where' => $where, 'items' => array_slice($items, 0, 14), 'wrong' => count(array_filter($items, fn ($i) => $i['tone'] === 'err'))];
    }
}

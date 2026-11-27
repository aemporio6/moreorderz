<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\DB;

/**
 * Owner 27-11 08:00 – the user guide, a teacher inside the tool. Nothing is written by hand: it is built from what the tool already knows –
 * the menu and its one-line help (config/menu_help), the forms of every screen with every box (label, required, help, where its choices come from,
 * its default), the rules of the screens, and a real picture of the screen when one has been made. So a new module, screen or box appears in the
 * guide by itself. Every "?" in the tool opens the guide at exactly its place (screen, form, box).
 *   map()        – how the company runs: buy → receive → stock → sell → money → partners, with the screens of each stage
 *   index()      – every group › section › screen the person may open, with its line and its guide link
 *   screen(r)    – one screen: where it is, what it is for, how to do the work step by step, every form and box, related screens, picture
 *   form(k, f)   – one form (and one box in it)
 *   search(q)    – screens, forms and boxes by any word
 */
class UserGuide
{
    public const FLOW = [
        ['Buy', 'Find a product, ask suppliers, order it and pay the deposit.', ['Research', 'Buying'], 'Owner, Sourcing'],
        ['Receive', 'Goods arrive: count them, check quality, put them on a shelf – the stock and its cost are booked by themselves.', ['Receiving & Warehouse'], 'Warehouse'],
        ['Stock', 'Every unit: where it is, whose it is, what it cost; reorder before it runs out (ICC).', ['Inventory'], 'Warehouse, Owner'],
        ['Sell', 'Listings on each marketplace, daily orders, labels, dispatch, returns.', ['Products & Listings', 'Selling', 'Dispatch', 'Returns & Customers'], 'VA, Team lead, Warehouse'],
        ['Money', 'Marketplace money files, bank, expenses, profit, month close, invoices.', ['Money', 'Month Close & Audit', 'Invoicing'], 'Finance, Accountant, Owner'],
        ['Partners', 'Each partner\'s share of stock, profit and money; statements and payouts.', ['Partners & Investors'], 'Owner, Partners'],
    ];
    public const LIST_STEPS = ['Find: type any word in the search box above the list – SKU, name, number, with or without # or -.', 'Filter: the funnel in a column heading, or More filters; the filters stay in the address, so Back returns with them.',
        'Open a row to see the whole record, its history, documents and messages.', 'Export: CSV, Excel, PDF or Word – or a saved export that goes by e-mail every day or week.', '⚙ Arrange: put the columns in your own order or hide them – only for you.'];

    private static function slug(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($s)), '-'); }
    public static function link(string $route): string { return '#/guide?s=' . rawurlencode($route); }
    private static function picture(string $route): ?string { $f = 'guide/' . self::slug(ltrim(explode('?', $route)[0], '#/') ?: 'home') . '.jpg'; return is_file(public_path($f)) ? '/' . $f : null; }

    /** The numbered buttons on the picture (made with it): [n, label, title]. */
    private static function marks(string $route): array { $f = public_path('guide/' . self::slug(ltrim(explode('?', $route)[0], '#/') ?: 'home') . '.json'); $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null; return is_array($j['marks'] ?? null) ? $j['marks'] : []; }

    private static function menu(string $role): array { return AppController::menu($role); }

    public static function map(string $role): array
    {
        $secs = collect(self::menu($role))->keyBy('name');
        return array_map(fn ($s) => ['stage' => $s[0], 'what' => $s[1], 'who' => $s[3], 'screens' => collect($s[2])->flatMap(fn ($n) => collect($secs[$n]['items'] ?? [])->where('soon', false)->take(6)
            ->map(fn ($i) => ['name' => $i['name'], 'about' => $i['about'], 'route' => $i['route'], 'guide' => self::link($i['route'])]))->values()->all()], self::FLOW);
    }

    public static function index(string $role): array
    {
        $secs = collect(self::menu($role))->keyBy('name'); $out = [];
        foreach (config('menu_help.groups') as $g) { $ss = [];
            foreach ($g['sections'] as $n) if (isset($secs[$n])) $ss[] = ['name' => $n, 'about' => $secs[$n]['about'], 'items' => collect($secs[$n]['items'])->where('soon', false)->map(fn ($i) => ['name' => $i['name'], 'about' => $i['about'], 'route' => $i['route'], 'guide' => self::link($i['route'])])->values()->all()];
            if ($ss) $out[] = ['name' => $g['name'], 'about' => $g['about'], 'sections' => $ss]; }
        return $out;
    }

    /** The menu item of a route (exact, else the longest one it starts with) and its group / section. */
    private static function find(string $role, string $route): ?array
    {
        $base = explode('?', $route)[0]; $best = null;
        foreach (self::menu($role) as $sec) foreach ($sec['items'] as $it) { $b = explode('?', (string) $it['route'])[0];
            if ($it['route'] === $route || $b === $base) return ['sec' => $sec, 'item' => $it];
            if (strlen($b) > 3 && str_starts_with($base, $b) && (! $best || strlen($b) > strlen(explode('?', $best['item']['route'])[0]))) $best = ['sec' => $sec, 'item' => $it]; }
        return $best;
    }

    private static function group(string $section): ?string { foreach (config('menu_help.groups') as $g) if (in_array($section, $g['sections'], true)) return $g['name']; return null; }

    private static function dflt(string $r): ?string
    {
        if ($r === '') return null; [$k, $v] = array_pad(explode(':', $r, 2), 2, '');
        return match ($k) { 'const' => $v === 'SELF' ? 'you' : $v, 'setting' => 'the setting ' . $v . ' (Settings)', 'session' => 'where you are working now (' . $v . ')', 'today', 'date' => 'today', default => 'by the screen\'s rule' };
    }

    public static function form(string $key, ?string $only = null): ?array
    {
        if (! Registry::exists($key)) return null; $fs = Registry::fields($key); if (! $fs) return null;
        $secs = [];
        foreach ($fs as $f) { if (! empty($f['secret']) || ($f['type'] ?? '') === 'hidden') continue; if ($only && $f['field_name'] !== $only) continue;
            [$kind, $arg] = array_pad(explode(':', (string) ($f['source'] ?? ''), 2), 2, '');
            $from = match ($kind) { 'ref' => 'the central list "' . $arg . '" (Setup › Lists) – + adds a value', 'own' => 'the records of ' . str_replace('_', ' ', preg_replace('/^(t_|cat_)/', '', $arg)) . ' – + makes a new one in place', 'enum' => 'a fixed set of choices', default => null };
            $secs[$f['section'] ?: 'Details'][] = ['name' => $f['field_name'], 'label' => $f['label'], 'type' => $f['type'], 'required' => in_array((string) ($f['required'] ?? ''), ['1', 'Y'], true), 'help' => trim((string) ($f['help'] ?? '')),
                'choices' => $from, 'default' => self::dflt((string) ($f['default_rule'] ?? '')), 'readonly' => in_array((string) ($f['readonly'] ?? ''), ['1', 'Y'], true)];
        }
        $title = (string) ($fs[0]['form_title'] ?? $key);
        $req = collect($secs)->flatten(1)->where('required', true)->pluck('label')->all();
        return ['key' => $key, 'title' => $title, 'sections' => $secs, 'steps' => array_values(array_filter([
            'Open it: + Create (top bar) and type "' . $title . '", or the screen\'s + New button.', $req ? 'Fill the boxes marked * – ' . implode(', ', array_slice($req, 0, 12)) . (count($req) > 12 ? ' …' : '') . '. "Still needed" at the top jumps to what is left.' : null,
            'Dropdowns: type to search; + adds a new value in place; your last choices come first.', 'Press "?" beside any box for its meaning; Save – the record is checked, saved and written to its history (who, when, what).'])), 'list' => '#/list/' . $key];
    }

    public static function screen(string $role, string $route): array
    {
        $m = self::find($role, $route);
        if (! $m) return ['found' => false, 'route' => $route, 'name' => 'This screen', 'about' => 'Not in your menu – search the guide below or ask the Owner for access.'];
        $it = $m['item']; $sec = $m['sec'];
        $forms = [];
        $row = DB::table('sidebar_modules')->where('id_module', $it['id'])->first(['forms', 'module_key']);
        foreach (preg_split('/[;,\s]+/', (string) ($row->forms ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $f) { $k = trim(explode('(', $f)[0], " `"); if ($k && ($x = self::form($k))) $forms[$k] = $x; }
        if (preg_match('~^#/list/([\w-]+)~', $it['route'], $mm) && ! isset($forms[$mm[1]]) && ($x = self::form($mm[1]))) $forms[$mm[1]] = $x;
        $path = array_values(array_filter([self::group($sec['name']), $sec['name'], $it['name']]));
        $steps = ['Open it: menu › ' . implode(' › ', $path) . ' – or type its name in Search (/).'];
        if (! empty($it['about'])) $steps[] = 'What it is for: ' . $it['about'] . '.';
        if (! empty($it['new_form'])) $steps[] = 'Add something new: the + New button at the top right, or + Create on the top bar.';
        if (str_starts_with($it['route'], '#/list/')) $steps = array_merge($steps, self::LIST_STEPS);
        $steps[] = 'Stuck? "Guide me" (bottom right) says what to do now on this screen and what is wrong, with the button that fixes it.';
        $related = collect($sec['items'])->where('soon', false)->reject(fn ($i) => $i['id'] === $it['id'])->take(8)->map(fn ($i) => ['name' => $i['name'], 'guide' => self::link($i['route']), 'route' => $i['route']])->values()->all();
        $stage = collect(self::FLOW)->first(fn ($s) => in_array($sec['name'], $s[2], true));
        return ['found' => true, 'route' => $it['route'], 'name' => $it['name'], 'about' => $it['about'], 'path' => $path, 'stage' => $stage ? $stage[0] : null, 'stage_what' => $stage ? $stage[1] : null,
            'steps' => $steps, 'forms' => array_values($forms), 'related' => $related, 'picture' => self::picture($it['route']), 'marks' => self::marks($it['route'])];
    }

    public static function search(string $role, string $q): array
    {
        $q = mb_strtolower(trim($q)); if (mb_strlen($q) < 2) return [];
        $hit = fn (...$t) => str_contains(mb_strtolower(implode(' ', array_map('strval', $t))), $q); $out = [];
        foreach (self::menu($role) as $sec) foreach ($sec['items'] as $it) if (! $it['soon'] && $hit($it['name'], $it['about'], $sec['name'])) $out[] = ['kind' => 'Screen', 'title' => $it['name'], 'text' => (string) $it['about'], 'go' => self::link($it['route'])];
        foreach (DB::table('sys_forms')->get(['form_key', 'title']) as $f) if ($hit($f->title)) $out[] = ['kind' => 'Form', 'title' => (string) $f->title, 'text' => '', 'go' => '#/guide?form=' . rawurlencode($f->form_key)];
        $like = '%' . addcslashes($q, '%_\\') . '%';
        foreach (DB::table('sys_registry')->where(fn ($w) => $w->where('label', 'like', $like)->orWhere('help', 'like', $like))->where(fn ($w) => $w->whereNull('secret')->orWhere('secret', 0))->limit(40)->get(['form_key', 'form_title', 'field_name', 'label', 'help']) as $f)
            $out[] = ['kind' => 'Box', 'title' => $f->label . ' – ' . ($f->form_title ?: $f->form_key), 'text' => mb_substr((string) $f->help, 0, 140), 'go' => '#/guide?form=' . rawurlencode($f->form_key) . '&field=' . rawurlencode($f->field_name)];
        return array_slice($out, 0, 80);
    }
}

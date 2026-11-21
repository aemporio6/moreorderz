<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\Registry;
use App\Services\Rights;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** JSON for the screen: boot data (user, companies, menu, Create list), forms, lists, records. */
class AppController extends Controller
{
    public function shell()
    {
        return view('app');
    }

    public function boot()
    {
        $u = Auth::user();
        $role = $u->role_code; $va = \App\Services\Perm::viewing();
        $companies = DB::table('t_enterprise')->where('f_active', 1)->orderBy('id')->get(['id', 'ent_name as name', 'ent_short as short']);
        if ($role !== 'OWNER') $companies = $companies->where('id', $u->fk_id_ent)->values();
        return [
            'user' => ['id' => $u->id, 'name' => $u->display_name ?: $u->login_id, 'role' => $va ? $va[0] : $role, 'role_name' => $va ? $va[1] : ($u->custom_role ?: $role)],
            'view_as' => $va ? $va[1] : null,
            'ent' => Context::ent(), 'companies' => $companies, 'menu' => self::menu($va ? $va[0] : $role), 'create' => self::createCatalogue($va ? $va[0] : $role),
            'setup_open' => $role === 'OWNER' && \App\Services\SetupWizard::open((int) Context::ent()),
            'tasks_open' => DB::table('tasks')->where('owner_user_id', $u->id)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->count(),
            'product_form' => RecordService::formOfTable('t_product_vendors'), 'person_forms' => \Illuminate\Support\Facades\DB::table('sys_registry')->where('table_name', 't_account')->distinct()->pluck('form_key')->all(), 'base_currency' => Settings::get('BASE_CURRENCY', Context::ent(), 'GBP'), 'today' => date('Y-m-d'),
        ];
    }

    /** Menu in the order of the work (sidebar_modules), filtered by role. */
    public static function menu(string $role): array
    {
        $rows = DB::table('sidebar_modules')->where('e_status', 'ACTIVE')->orderBy('sort_order')->orderBy('id_module')->get();
        if (\App\Services\Choices::get('MENU_NAMES', Context::ent()) === 'SIMPLE') foreach ($rows as $m) $m->module_name = self::simpleName((string) $m->module_name);
        $secs = [];
        foreach ($rows->whereNull('parent_id') as $s) $secs[$s->id_module] = ['id' => $s->id_module, 'name' => $s->module_name, 'icon' => $s->icon, 'items' => []];
        foreach ($rows->whereNotNull('parent_id') as $m) {
            if (! isset($secs[$m->parent_id]) || ! \App\Services\Perm::has(Auth::user(), 'menu:' . $m->module_key)) continue;
            $secs[$m->parent_id]['items'][] = ['id' => $m->id_module, 'no' => $m->screen_no, 'name' => $m->module_name, 'route' => self::route($m), 'forms' => array_values(array_filter(array_map(fn ($f) => trim(explode('(', $f)[0]), preg_split('/[;,\s]+/', (string) $m->forms, -1, PREG_SPLIT_NO_EMPTY)), fn ($k) => Registry::exists($k) && Registry::table($k)))];
        }
        return array_values(array_filter($secs, fn ($s) => $s['items']));
    }

    /** "Owner Home (\"Command Center\")" → "Owner Home"; "Partner Home (= My Partner Account)" → "Partner Home". Never empty. */
    public static function simpleName(string $name): string
    {
        $s = trim(preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/u', '', $name)); $s = trim(preg_split('/\s+[–—]\s+|\s+=\s+/u', $s)[0] ?? $s, " \t-–—:·");
        return $s !== '' ? $s : $name;
    }

    private static function roleSees(?string $roles, string $role): bool
    {
        return Rights::sees($roles, $role);
    }

    /** A menu item opens the list of its first data form; special screens get their own route as they are built. */
    public static function routeOf(object $m): string { return self::route($m); }

    private static function route(object $m): string
    {
        $no = (string) $m->screen_no;
        if (str_starts_with($no, '1.')) return '#/home';
        if ($no === '13.2') return '#/tasks?scope=all';
        if ($no === '13.3') return '#/tasks';
        if ($no === '3.1' || $no === '3.3') return '#/buying';
        if ($no === '6.3') return '#/fulfilment';
        if (in_array($no, ['7.1', '7.2', '7.3', '7.5'], true)) return '#/orders';
        if ($no === '4.3') return '#/work';
        if ($no === '6.2') return '#/sales';
        if ($no === '6.5') return '#/confirm';
        if ($no === '10.6') return '#/va-statements';
        if ($no === '6.7') return '#/ds-orders';
        if ($no === '6.8') return '#/shop-health';
        if ($no === '17.4') return '#/carriers';
        if ($no === '8.1') return '#/returns';
        if ($no === '4.6') return '#/stock';
        if ($no === '4.7') return '#/stock/ledger';
        if ($no === '4.8') return '#/stock/lots';
        if ($no === '4.9') return '#/stock/pool';
        if ($no === '4.12') return '#/stock/counts';
        if ($no === '4.13') return '#/stock/held';
        if ($no === '4.10') return '#/stock/ledger?act=transfer';
        if ($no === '11.3') return '#/waterfall';
        if (in_array($no, ['11.1', '11.2', '11.4', '11.5', '11.6'], true)) return '#/partners';
        if ($no === '16.1') return '#/reports';
        if ($no === '10.2') return '#/close';
        if ($no === '9.1') return '#/money';
        if ($no === '10.3') return '#/profit';
        if ($no === '6.6') return '#/refunds';
        if ($no === '9.2' || $no === '9.4') return '#/bank';
        if ($no === '9.3') return '#/settlements';
        if ($no === '9.5') return '#/expenses';
        if ($no === '9.6' || $no === '13.4') return '#/approvals';
        if ($no === '9.14') return '#/ledger';
        if ($no === '7.4') return '#/print-queue';
        if ($no === '17.9') return '#/printers';
        if ($no === '17.15') return '#/permissions';
        if ($no === '17.16') return '#/sessions';
        $map = ['4.1' => '#/stock', '10.1' => '#/close', '13.1' => '#/tasks?scope=all', '6.1' => '#/orders', '5.7' => '#/reports/price_log', '15.1' => '#/import', '15.2' => '#/import-history', '7.6' => '#/tracking-upload', '6.9' => '#/calculator', '17.14' => '#/registry', '12.9' => '#/merges', '12.1' => '#/accounts', '15.5' => '#/connections', '6.4' => '#/hubs', '13.6' => '#/messages', '14.2' => '#/drive', '15.3' => '#/drive?tab=watched', '12.7' => '#/subscriptions', '9.11' => '#/loans', '9.12' => '#/budgets', '9.7' => '#/capital-calls', '11.4' => '#/statement-questions'];
        if (isset($map[$no])) return $map[$no];
        if ($no === '9.10') return '#/forecast';
        if ($no === '17.1') return '#/setup';
        if ($no === '4.5') return '#/sku-homes';
        if ($no === '17.17') return '#/audit';
        if ($no === '17.18') return '#/settings';
        if ($no === '17.20') return '#/health';
        foreach (preg_split('/[;,\s]+/', (string) $m->forms, -1, PREG_SPLIT_NO_EMPTY) as $f) {
            $key = trim(explode('(', $f)[0], " `");
            if ($key && Registry::exists($key) && Registry::table($key)) return '#/list/' . $key;
        }
        return '#/screen/' . $no;
    }

    /** + Create: every active entry of sys_forms the role may add, grouped by department. New modules appear by themselves. */
    public static function createCatalogue(string $role): array
    {
        return DB::table('sys_forms')->where('f_active', 1)->where('f_in_create', 1)->orderBy('sort_no')->get(['form_key', 'title', 'department', 'module', 'f_quick'])
            ->filter(fn ($f) => Registry::exists($f->form_key) && Rights::can($role, 'ADD', $f->form_key))
            ->map(fn ($f) => ['key' => $f->form_key, 'title' => $f->title, 'dept' => $f->department ?: $f->module, 'quick' => (bool) $f->f_quick])->values()->all();
    }

    /** Live check of one typed field while the person types (R-F_SETUP-35: ISO code "already exists", code formats). */
    public function formLive(\Illuminate\Http\Request $r, string $key)
    {
        Rights::check('VIEW', $key);
        return \App\Services\FormRules::live($key, (string) $r->query('field'), (string) $r->query('value'), (int) $r->query('id') ?: null);
    }

    /** Job history of a person (R-C-39): newest first, renewal amber within 30 days. */
    public function jobs(\Illuminate\Http\Request $r, int $id)
    {
        Rights::check('VIEW', 't_account_job_history');
        $rows = \Illuminate\Support\Facades\DB::table('t_account_job_history as j')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'j.fk_id_marketplace')->leftJoin('t_branches as b', 'b.id', '=', 'j.fk_id_branch')->leftJoin('cat_currency as c', 'c.id', '=', 'j.fk_id_currency')
            ->where('j.fk_id_account', $id)->where('j.f_active', 1)->orderByDesc('j.id')->get(['j.*', 'm.marketplace_short as marketplace', 'b.shop_no', 'b.shop_name', 'c.currency_code']);
        $soon = date('Y-m-d', strtotime('+30 days'));
        return ['form' => 't_account_job_history', 'rows' => $rows->map(fn ($j) => (array) $j + ['renewal_soon' => $j->d_contract_renewal_date && $j->d_contract_renewal_date <= $soon && ! $j->d_end_date, 'running' => ! $j->d_end_date])->all()];
    }

    public function formCalc(\Illuminate\Http\Request $r, string $key) { Rights::check('VIEW', $key); return \App\Services\FormRules::calc($key, (array) $r->input('values', [])); }

    public function form(string $key)
    {
        Rights::check('VIEW', $key);
        return Registry::definition($key, Context::role()) + ['blank' => RecordService::blank($key)];
    }

    /** Options of a dropdown: narrowed by what is typed (q) and by the fields it follows (parent[field]=value); with the person's last used values. */
    public function options(Request $r, string $key, string $field)
    {
        Rights::check('VIEW', $key);
        return \App\Services\FormAssist::options($key, $field, $r->query('q') !== null ? (string) $r->query('q') : null, (array) $r->query('parent', []));
    }

    /** "+ Add" for a dropdown that has its own small list: the value is saved to the list and comes back selected. */
    public function addOption(Request $r, string $key, string $field)
    {
        Rights::check('ADD', $key);
        try { [$v, $l, $new] = \App\Services\FormAssist::addOption($key, $field, (string) $r->input('label')); return ['value' => $v, 'label' => $l, 'message' => $new ? 'Added and selected' : 'It was already in the list – selected']; }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    /** Up to 2 suggestion chips for a field (from the linked record · you used this last time · used most). Old answer shape "values" kept. */
    public function suggest(Request $r, string $key, string $field)
    {
        Rights::check('VIEW', $key);
        $chips = \App\Services\FormAssist::chips($key, $field, (array) $r->query('form', []));
        return ['chips' => $chips, 'values' => array_column($chips, 'value')];
    }

    /** Records that look like what is being typed in a name / code field – shown before a duplicate is saved. */
    public function lookalike(Request $r, string $key)
    {
        Rights::check('VIEW', $key);
        return ['matches' => \App\Services\FormAssist::lookalike($key, (string) $r->query('field'), (string) $r->query('value'), $r->query('id') ? (int) $r->query('id') : null)];
    }

    public function list(Request $r, string $key)
    {
        Rights::check('VIEW', $key);
        return RecordService::list($key, $r->query());
    }

    /** The different values of one column under the list's other filters (for the filter's "is any of" picker). */
    public function listValues(Request $r, string $key)
    {
        Rights::check('VIEW', $key);
        return RecordService::values($key, (string) $r->query('col'), $r->query());
    }

    /** What the filter builder offers: operators per type and the date presets. */
    public function filterMeta()
    {
        return ['ops' => \App\Services\ListFilter::OPS, 'presets' => \App\Services\ListFilter::PRESETS, 'per_page' => [25, 50, 100, 250]];
    }

    public function record(string $key, int $id)
    {
        Rights::check('VIEW', $key);
        $table = \App\Services\Registry::table($key);
        $kinds = $table ? \App\Services\Vault::kinds($table) : [];
        return ['values' => RecordService::get($key, $id), 'vault' => array_keys(array_filter($kinds, fn ($k) => $k === 'VAULT')), 'card' => array_keys(array_filter($kinds, fn ($k) => $k === 'CARD')),
            'never' => array_keys(array_filter($kinds, fn ($k) => $k === 'NEVER')), 'may_reveal' => \App\Services\Vault::mayReveal(Context::role())];
    }

    /** Reveal one secret of a record (logged as REVEAL: SHOWN or DENIED; never the value). */
    public function reveal(Request $r, string $key, int $id)
    {
        try { return \App\Services\Vault::reveal($key, $id, (string) $r->input('field')); }
        catch (\Illuminate\Auth\Access\AuthorizationException $e) { return response()->json(['message' => $e->getMessage()], 403); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function save(Request $r, string $key, ?int $id = null)
    {
        Rights::check($id ? 'EDIT' : 'ADD', $key);
        $res = RecordService::save($key, (array) $r->input('values', []), $id, ['quick' => $r->boolean('quick'), 'reason' => $r->input('reason')]);
        $title = Registry::fields($key)[0]['form_title'];
        // the label and code of what was saved: a dropdown that opened "+ Add" selects it, and the toast says what it is
        $table = Registry::table($key); $label = null; $code = null;
        if ($table && ($row = (array) DB::table($table)->where(Registry::pk($table), $res['id'])->first())) {
            $lc = Registry::labelColumn($table); $label = $lc ? (string) ($row[$lc] ?? '') : '';
            if ($table === 't_account') $label = trim(($row['first_name'] ?? '') . ' ' . ($row['sur_name'] ?? ''));
            foreach (Registry::fields($key) as $f) if (str_starts_with((string) $f['default_rule'], 'numbering:') && ! empty($row[$f['column_name']])) { $code = (string) $row[$f['column_name']]; break; }
        }
        return $res + ['label' => $label !== '' && $label !== null ? $label : ($code ?: '#' . $res['id']), 'code' => $code, 'message' => ($res['created'] ? 'Saved in ' : 'Updated in ') . $title . ($code ? ' · ' . $code : ''), 'open' => "#/list/$key", 'open_record' => "#/rec/$key/{$res['id']}"];
    }

    public function company(Request $r)
    {
        $id = (int) $r->input('ent');
        $u = Auth::user();
        abort_unless(\App\Services\Perm::has($u, 'app.company_owner') || $id === (int) $u->fk_id_ent, 403, 'You cannot work in that company.');
        abort_unless(DB::table('t_enterprise')->where('id', $id)->where('f_active', 1)->exists(), 404);
        $r->session()->put('ent', $id);
        return ['ent' => $id];
    }
}

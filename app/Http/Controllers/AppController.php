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
            'ent' => Context::ent(), 'companies' => $companies, 'words' => \App\Services\SimpleWords::MAP /* owner 26-11: the one list of easy words */, 'menu' => self::menu($va ? $va[0] : $role), 'create' => self::createCatalogue($va ? $va[0] : $role),
            'setup_open' => $role === 'OWNER' && \App\Services\SetupWizard::open((int) Context::ent()),
            'task_header' => \App\Services\TaskRoles::header($u),      // R-G_CORE-20
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
            $rt = self::route($m); $soon = str_starts_with($rt, '#/screen/');      // R-G_CORE-55: a screen not built yet = a muted "Soon" item, only the Owner sees it
            if ($soon && $role !== 'OWNER') continue;
            $secs[$m->parent_id]['items'][] = ['id' => $m->id_module, 'no' => $m->screen_no, 'name' => $m->module_name, 'route' => $rt, 'soon' => $soon, 'forms' => array_values(array_filter(array_map(fn ($f) => trim(explode('(', $f)[0]), preg_split('/[;,\s]+/', (string) $m->forms, -1, PREG_SPLIT_NO_EMPTY)), fn ($k) => Registry::exists($k) && Registry::table($k)))];
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
        if ($no === '' && str_starts_with((string) $m->url, '#/')) return (string) $m->url;      /* a row added after the numbered screens carries its own address (Inventory sharing, Data quality, Allocation plan, Task board) */
        if (str_starts_with($no, '1.')) return '#/home';
        if ($no === '3.5') return '#/old-entry';      /* R-B_SOURCING-5 / 13 old purchases */
        if ($no === '10.4') return '#/purchase-audit';      /* R-A_SALES-60 … 64 audit runs */
        if ($no === '10.7') return '#/invoices';
        if (in_array($no, ['10.11', '10.12', '10.13', '10.14', '10.15', '10.16', '10.17', '10.18'], true) && $m->url) return (string) $m->url;      /* R-G_CORE-86 Invoicing group */
        if ($no === '10.8') return '#/compliance';      /* R-ACC compliance calendar */      /* R-D-83 / 84 investor documents */
        if ($no === '13.1') return '#/tasks?scope=dashboard';      // R-G_CORE-20 boss-only dashboard
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
        return \App\Services\FormStandard::createCatalogue($role);      // owner's form standard (f): built from the registry, every main form appears by itself
    }

    /** Live check of one typed field while the person types (R-F_SETUP-35: ISO code "already exists", code formats). */
    public function formLive(\Illuminate\Http\Request $r, string $key)
    {
        Rights::check('VIEW', $key);
        return \App\Services\FormRules::live($key, (string) $r->query('field'), (string) $r->query('value'), (int) $r->query('id') ?: null, array_filter($r->only(['country_id', 'fk_id_country'])));
    }

    /** Job history of a person (R-C-39): newest first, renewal amber within 30 days. */
    public function securityHealth(\Illuminate\Http\Request $r, int $id) { Rights::check('VIEW', 't_account'); return ['rows' => \App\Services\SecurityQuestions::health($id)]; }

    /** Shop assignments of a person (R-C-41). */
    /** Person header: count chips and health (R-C-18). */
    /** R-C-10: the External Contact quick form (roles pills, repeatable rows, company, banks, PayPal, payment terms). */
    /** R-C-30 the shop's Account kit (seller accounts with their health). */
    public function shopKit(\Illuminate\Http\Request $r, int $id) { Rights::check('VIEW', (string) RecordService::formOfTable('t_branches')); try { return \App\Services\ShopKit::kit((int) \App\Services\Context::ent(), $id); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 404); } }

    /** R-C-1: people / companies filtered by party type and by managed / external, each on its own. */
    public function parties(\Illuminate\Http\Request $r) { Rights::check('VIEW', (string) RecordService::formOfTable('t_account')); try { return ['rows' => \App\Services\Party::find((int) \App\Services\Context::ent(), $r->only(['party_type', 'kind', 'role', 'q']))]; } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function externalForm(\Illuminate\Http\Request $r) { Rights::check('ADD', 'external_contact'); return \App\Services\ExternalContact::meta((int) \App\Services\Context::ent()); }
    public function externalSave(\Illuminate\Http\Request $r)
    {
        Rights::check('ADD', 'external_contact');
        try { return \App\Services\ExternalContact::create((int) \App\Services\Context::ent(), $r->all(), (int) \Illuminate\Support\Facades\Auth::id()); }
        catch (\App\Services\ValidationErrors $e) { return response()->json(['status' => 'invalid', 'message' => 'Please fix ' . count($e->errors) . ' thing(s) before saving.', 'errors' => array_map(fn ($m) => [$m], $e->errors)], 422); }
        catch (\App\Services\QuickSaveFailed $e) { return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500); }      /* R-C-9 */
        catch (\App\Services\LookAlikeFound $e) { return response()->json(['message' => $e->getMessage(), 'status' => 'duplicate', 'people' => $e->matches], 409); }      /* R-C-8 */
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }
    public function externalProgress(\Illuminate\Http\Request $r) { Rights::check('ADD', 'external_contact'); return \App\Services\ExternalContact::progress($r->all()); }      /* R-C-11 reads only */
    public function personHeader(\Illuminate\Http\Request $r, int $id) { $f = (string) RecordService::formOfTable('t_account'); Rights::check('VIEW', $f); RecordService::get($f, $id);
        $h = \App\Services\PersonHeader::for($id); $hideForms = array_filter(array_map(fn ($t) => RecordService::formOfTable($t), \App\Services\PersonKind::hiddenTables(\App\Services\PersonKind::of($id))));
        $h['chips'] = array_values(array_filter($h['chips'], fn ($c) => ! $c['url'] || ! in_array(explode('?', substr($c['url'], 7))[0], $hideForms, true)));      /* R-C-2 only the tabs of the person's kind */
        return $h + ['can_convert' => \App\Services\Perm::has($r->user(), 'people.convert'), 'kind_code' => ($k = \App\Services\PersonKind::of($id)), 'kind_badge' => \App\Services\PersonKind::BADGE[$k], 'convert_to' => $k === 'E' ? 'M' : 'E', 'convert_text' => \App\Services\PersonKind::CONFIRM[$k === 'E' ? 'M' : 'E'], 'kind_log' => \App\Services\PersonKind::log($id), 'hidden_tables' => \App\Services\PersonKind::hiddenTables($k), 'can_delete' => \App\Services\Perm::has($r->user(), 'admin.accounts'), 'deleted' => ! (int) DB::table('t_account')->where('id', $id)->value('f_active')]; }
    /** R-C-16 People list › Add role to selected. */
    public function peopleRoles() { return ['roles' => array_map(fn ($r) => ['id' => $r['id'], 'name' => $r['name']], \App\Services\ExternalContact::roles((int) \App\Services\Context::ent()))]; }
    public function peopleAddRole(\Illuminate\Http\Request $r) { try { return \App\Services\PersonRoles::addToMany((array) $r->input('ids', []), (int) $r->input('role'), $r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    /** R-C-2 convert Managed ↔ External (POST), its log, and the sections each kind shows (Settings). */
    public function personConvert(\Illuminate\Http\Request $r, int $id) { try { return \App\Services\PersonKind::convert($id, (string) $r->input('to'), $r->input('note'), $r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function personSections(\Illuminate\Http\Request $r) { \App\Services\Perm::need($r->user(), 'people.convert'); return ['rows' => \App\Services\PersonKind::rows(), 'kinds' => ['ALL' => 'Everyone', 'M' => 'Managed Account only', 'E' => 'External Contact only']]; }
    public function personSectionSet(\Illuminate\Http\Request $r, int $id) { try { return \App\Services\PersonKind::setVisible($id, (string) $r->input('visible_kind'), $r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    /** R-C-17 where a person is used (reads only); soft delete to the recycle bin (POST) – refused with the list when used; restore. */
    public function personUsed(\Illuminate\Http\Request $r, int $id) { $f = (string) RecordService::formOfTable('t_account'); Rights::check('VIEW', $f); RecordService::get($f, $id); $u = \App\Services\PartyUse::whereUsed($id); return ['used' => array_map(fn ($x, $k) => ['key' => $k, 'label' => $x[0], 'count' => $x[1], 'names' => $x[2]], $u, array_keys($u)), 'text' => \App\Services\PartyUse::text($u)]; }
    public function personDelete(\Illuminate\Http\Request $r, int $id) { try { $x = \App\Services\PartyUse::delete($id, $r->user()); return response()->json($x, $x['deleted'] ? 200 : 409); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function personRestore(\Illuminate\Http\Request $r, int $id) { try { return \App\Services\PartyUse::restore($id, $r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }

    public function personShops(\Illuminate\Http\Request $r, int $id)
    {
        Rights::check('VIEW', 't_account_branch');
        return ['rows' => \App\Services\ShopAssignments::cards($id), 'roles' => \App\Services\ShopAssignments::ROLES, 'can_edit' => \App\Services\Perm::has($r->user(), 'form:t_account_branch:EDIT'),
            'marketplaces' => DB::table('cat_marketplaces')->where('f_active', 1)->orderBy('id')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), marketplace_descr) as name")]),
            'shops' => DB::table('t_branches')->orderByDesc('f_active')->orderBy('shop_no')->get(['id', 'fk_id_marketplace as mp', 'f_active', DB::raw("TRIM(CONCAT_WS(' ', shop_no, shop_name)) as name")]),      // every shop, inactive ones greyed (R-C-42)
            'linked' => \App\Services\ShopAssignments::linked($id)];
    }
    public function personShopSave(\Illuminate\Http\Request $r, int $id, ?int $aid = null)
    {
        Rights::check($aid ? 'EDIT' : 'ADD', 't_account_branch');
        try { return \App\Services\ShopAssignments::save(['fk_id_account' => $id] + $r->only(['fk_id_branch', 'role_code', 'd_from', 'd_to']), $aid); }
        catch (\App\Services\ValidationErrors $e) { return response()->json(['message' => $e->getMessage(), 'errors' => array_map(fn ($m) => [$m], $e->errors)], 422); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }
    public function personShopRemove(\Illuminate\Http\Request $r, int $id, int $aid) { Rights::check('EDIT', 't_account_branch'); abort_unless(DB::table('t_account_branches')->where('id', $aid)->where('fk_id_account', $id)->exists(), 404); return \App\Services\ShopAssignments::remove($aid); }

    public function jobs(\Illuminate\Http\Request $r, int $id)
    {
        Rights::check('VIEW', 't_account_job_history');
        $rows = \Illuminate\Support\Facades\DB::table('t_account_job_history as j')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'j.fk_id_marketplace')->leftJoin('t_branches as b', 'b.id', '=', 'j.fk_id_branch')->leftJoin('cat_currency as c', 'c.id', '=', 'j.fk_id_currency')
            ->where('j.fk_id_account', $id)->where('j.f_active', 1)->orderByDesc('j.id')->get(['j.*', 'm.marketplace_short as marketplace', 'b.shop_no', 'b.shop_name', 'c.currency_code']);
        $rd = (int) \App\Services\Choices::get('CONTRACT_RENEWAL_DAYS', (int) Context::ent() ?: null) ?: 30; $soon = date('Y-m-d', strtotime("+$rd days"));      // Settings › Choices
        $ent = (int) \App\Services\Context::ent(); $today = date('Y-m-d');
        $out = $rows->map(fn ($j) => (array) $j + ['renewal_soon' => $j->d_contract_renewal_date && $j->d_contract_renewal_date <= $soon && ! $j->d_end_date, 'running' => ! $j->d_end_date || $j->d_end_date >= $today, 'salary_gbp' => \App\Services\JobPay::salaryGbp($j, $ent, $today)])->all();
        $run = array_filter($out, fn ($j) => $j['running'] && (! $j['d_job_start_date'] || $j['d_job_start_date'] <= $today));
        return ['form' => 't_account_job_history', 'rows' => $out, 'totals' => ['running' => count($run), 'monthly_gbp' => round(array_sum(array_map(fn ($j) => (float) $j['salary_gbp'], $run)), 2)]];
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
    /** R-B_SOURCING-23 names of saved values that are not in the option list any more (reads only). */
    public function savedLabels(Request $r, string $key, string $field) { Rights::check('VIEW', $key); return ['labels' => \App\Services\FormAssist::savedLabels($key, $field, array_slice(explode(',', (string) $r->query('ids', '')), 0, 50))]; }
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
        if ($need = \App\Services\SecureStep::page($r, $key)) return response()->json($need, 428);      // R-G_CORE-59 secure page
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

    public function record(Request $r, string $key, int $id)
    {
        Rights::check('VIEW', $key);
        if ($need = \App\Services\SecureStep::page($r, $key)) return response()->json($need, 428);      // R-G_CORE-59 secure page
        $table = \App\Services\Registry::table($key);
        $kinds = $table ? \App\Services\Vault::kinds($table) : [];
        return ['values' => \App\Services\Mask::row($key, RecordService::get($key, $id), \Illuminate\Support\Facades\Auth::user()),      // R-G_CORE-62 a hidden field is not in the answer
             'vault' => array_keys(array_filter($kinds, fn ($k) => $k === 'VAULT')), 'card' => array_keys(array_filter($kinds, fn ($k) => $k === 'CARD')),
            'never' => array_keys(array_filter($kinds, fn ($k) => $k === 'NEVER')), 'may_reveal' => \App\Services\Vault::mayReveal(Context::role())];
    }

    /** R-G_CORE-98: the code of the phone app opens secrets for this session. */
    public function secureCode(Request $r)
    {
        try { return \App\Services\SecureStep::verify($r, $r->user(), (string) $r->input('code')); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    /** Reveal one secret of a record (logged as REVEAL: SHOWN or DENIED; never the value). */
    public function reveal(Request $r, string $key, int $id)
    {
        if ($need = \App\Services\SecureStep::needed($r, $r->user())) return response()->json($need, 428);      // R-G_CORE-59 / 98 2FA before a secret
        try { return \App\Services\Vault::reveal($key, $id, (string) $r->input('field')); }
        catch (\Illuminate\Auth\Access\AuthorizationException $e) { return response()->json(['message' => $e->getMessage()], 403); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    /** "+ Add" of an own item on a person not yet saved: the person is saved as a DRAFT first (R-C-20). */
    public function draft(Request $r, string $key)
    {
        Rights::check('ADD', $key);
        try { return ['id' => \App\Services\OwnItems::draft($key, (array) $r->input('values', [])), 'message' => 'The person is saved as a draft – finish the form and press Save.']; }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function save(Request $r, string $key, ?int $id = null)
    {
        Rights::check($id ? 'EDIT' : 'ADD', $key);
        try { $res = RecordService::save($key, (array) $r->input('values', []), $id, ['quick' => $r->boolean('quick'), 'reason' => $r->input('reason'), 'dup_reason' => $r->input('dup_reason')]); }
        catch (\App\Services\LookAlikeFound $e) { return response()->json(['message' => $e->getMessage(), 'status' => 'duplicate', 'people' => $e->matches], 409); }      /* R-C-8 */
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
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

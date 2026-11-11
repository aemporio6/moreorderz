<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\Registry;
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
        $role = $u->role_code;
        $companies = DB::table('t_enterprise')->where('f_active', 1)->orderBy('id')->get(['id', 'ent_name as name', 'ent_short as short']);
        if ($role !== 'OWNER') $companies = $companies->where('id', $u->fk_id_ent)->values();
        return [
            'user' => ['id' => $u->id, 'name' => $u->display_name ?: $u->login_id, 'role' => $role],
            'ent' => Context::ent(), 'companies' => $companies, 'menu' => self::menu($role), 'create' => self::createCatalogue($role),
            'tasks_open' => DB::table('tasks')->where('owner_user_id', $u->id)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->count(),
            'base_currency' => Settings::get('BASE_CURRENCY', Context::ent(), 'GBP'), 'today' => date('Y-m-d'),
        ];
    }

    /** Menu in the order of the work (sidebar_modules), filtered by role. */
    public static function menu(string $role): array
    {
        $rows = DB::table('sidebar_modules')->where('e_status', 'ACTIVE')->orderBy('sort_order')->orderBy('id_module')->get();
        $secs = [];
        foreach ($rows->whereNull('parent_id') as $s) $secs[$s->id_module] = ['id' => $s->id_module, 'name' => $s->module_name, 'icon' => $s->icon, 'items' => []];
        foreach ($rows->whereNotNull('parent_id') as $m) {
            if (! isset($secs[$m->parent_id]) || ! self::roleSees($m->roles, $role)) continue;
            $secs[$m->parent_id]['items'][] = ['id' => $m->id_module, 'no' => $m->screen_no, 'name' => $m->module_name, 'route' => self::route($m), 'forms' => array_values(array_filter(array_map('trim', explode(';', (string) $m->forms))))];
        }
        return array_values(array_filter($secs, fn ($s) => $s['items']));
    }

    private static function roleSees(?string $roles, string $role): bool
    {
        if ($role === 'OWNER' || ! $roles) return true;
        return (bool) preg_match('/\b(all|' . preg_quote($role, '/') . ')\b/i', $roles);
    }

    /** A menu item opens the list of its first data form; special screens get their own route as they are built. */
    private static function route(object $m): string
    {
        foreach (array_filter(array_map('trim', preg_split('/[;,]/', (string) $m->forms))) as $f) {
            $key = trim(explode('(', $f)[0], " `");
            if ($key && Registry::exists($key) && Registry::table($key)) return '#/list/' . $key;
        }
        return '#/screen/' . $m->screen_no;
    }

    /** + Create: every active entry of sys_forms the role may add, grouped by department. New modules appear by themselves. */
    public static function createCatalogue(string $role): array
    {
        return DB::table('sys_forms')->where('f_active', 1)->where('f_in_create', 1)->orderBy('sort_no')->get(['form_key', 'title', 'department', 'module', 'f_quick'])
            ->filter(fn ($f) => Registry::exists($f->form_key))
            ->map(fn ($f) => ['key' => $f->form_key, 'title' => $f->title, 'dept' => $f->department ?: $f->module, 'quick' => (bool) $f->f_quick])->values()->all();
    }

    public function form(string $key)
    {
        return Registry::definition($key, Context::role()) + ['blank' => RecordService::blank($key)];
    }

    public function options(Request $r, string $key, string $field)
    {
        foreach (Registry::fields($key) as $f) {
            if ($f['field_name'] === $field) return ['options' => Registry::options($f, Context::ent(), $r->query('q'))];
        }
        abort(404);
    }

    /** Suggestions for a free-text field: the values used most on this form before. */
    public function suggest(string $key, string $field)
    {
        $table = Registry::table($key);
        foreach (Registry::fields($key) as $f) {
            if ($f['field_name'] !== $field || ! $f['column_name'] || $f['secret'] || ! Registry::hasColumn($table, $f['column_name'])) continue;
            $q = DB::table($table)->whereNotNull($f['column_name'])->where($f['column_name'], '<>', '');
            if (Registry::hasColumn($table, 'fk_id_ent') && Context::ent()) $q->where('fk_id_ent', Context::ent());
            return ['values' => $q->groupBy($f['column_name'])->orderByRaw('COUNT(*) DESC')->limit(8)->pluck($f['column_name'])];
        }
        return ['values' => []];
    }

    public function list(Request $r, string $key)
    {
        return RecordService::list($key, $r->only(['q', 'page', 'per_page', 'sort', 'dir', 'filter']));
    }

    public function record(string $key, int $id)
    {
        return ['values' => RecordService::get($key, $id)];
    }

    public function save(Request $r, string $key, ?int $id = null)
    {
        $res = RecordService::save($key, (array) $r->input('values', []), $id, ['quick' => $r->boolean('quick'), 'reason' => $r->input('reason')]);
        $title = Registry::fields($key)[0]['form_title'];
        return $res + ['message' => ($res['created'] ? 'Saved: ' : 'Updated: ') . $title, 'open' => "#/list/$key"];
    }

    public function company(Request $r)
    {
        $id = (int) $r->input('ent');
        $u = Auth::user();
        abort_unless($u->role_code === 'OWNER' || $id === (int) $u->fk_id_ent, 403, 'You cannot work in that company.');
        abort_unless(DB::table('t_enterprise')->where('id', $id)->where('f_active', 1)->exists(), 404);
        $r->session()->put('ent', $id);
        return ['ent' => $id];
    }
}

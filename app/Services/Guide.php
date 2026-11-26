<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guidance – nobody is left stuck. For every list: what the screen is, how to add, where else it is created.
 * For every record: the next step and, when something stops it, why and the button that fixes it.
 * Step playbooks ("How to do this step"), recent pages and favourites of a person.
 */
class Guide
{
    // ---------- list: guidance strip ----------
    public static function list(string $formKey, ?object $u): array
    {
        $fields = Registry::fields($formKey); if (! $fields) abort(404);
        $title = (string) $fields[0]['form_title']; $table = Registry::table($formKey);
        $form = DB::table('sys_forms')->where('form_key', $formKey)->first();
        $req = array_values(array_map(fn ($f) => $f['label'], array_filter($fields, fn ($f) => $f['required'] && ! str_starts_with((string) $f['default_rule'], 'numbering:'))));
        $numbered = collect($fields)->first(fn ($f) => str_starts_with((string) $f['default_rule'], 'numbering:'));
        $what = trim((string) ($form->help ?? '')) ?: ('Every ' . self::lower($title) . ' of the company, one row each' . ($form && ($form->department ?: $form->module) ? ' – part of ' . ($form->department ?: $form->module) : '') . '.');
        $can = $u && Perm::has($u, "form:$formKey:ADD");
        $add = ! $can ? 'Your role only looks at this list. To add here, press a refused button once and use "Ask the Owner".'
            : 'Press New (or + Create from any screen).' . ($req ? ' Needed: ' . implode(', ', array_slice($req, 0, 6)) . (count($req) > 6 ? ' and ' . (count($req) - 6) . ' more' : '') . '.' : ' No field is compulsory.') . ($numbered ? ' The ' . self::lower((string) $numbered['label']) . ' is given by the system.' : '');
        // where else it is created: the dropdowns of other forms whose "+ Add" opens this form
        $also = [];
        foreach (self::addMap()[$formKey] ?? [] as [$otherTitle, $label]) $also[$otherTitle][] = $label;
        $alsoText = array_map(fn ($t, $ls) => $t . ' (' . implode(', ', array_unique($ls)) . ')', array_keys($also), $also);
        $menu = DB::table('sidebar_modules as m')->leftJoin('sidebar_modules as s', 's.id_module', '=', 'm.parent_id')->where('m.forms', 'like', "%$formKey%")->first(['m.module_name', 's.module_name as section', 'm.screen_no']);
        $tips = ['Click a column heading to sort; the funnel on it filters that column; Filters builds and / or rules.', 'Columns, their order and widths are remembered for you; Save view keeps a set under a name.', 'Export gives exactly what you see: CSV, Excel, PDF, Copy, Print.'];
        if ($table && Schema::hasTable($table) && Registry::hasColumn($table, 'f_active')) $tips[] = 'Nothing is deleted: a record is switched off (Active = No) and can be switched on again.';
        return ['title' => $title, 'what' => $what, 'add' => $add, 'can_add' => $can, 'also' => array_slice($alsoText, 0, 8), 'also_more' => max(0, count($alsoText) - 8),
            'menu' => $menu ? trim(($menu->section ?? '') . ' › ' . $menu->module_name . ' · ' . $menu->screen_no) : null, 'tips' => $tips,
            'import' => $u && Perm::has($u, 'menu:M_15_1_IMPORT_A_FILE') ? '#/import' : null];
    }

    private static function lower(string $s): string { return implode(' ', array_map(fn ($w) => preg_match('/^[A-Z0-9]{2,}\b/', $w) ? $w : mb_strtolower($w), explode(' ', $s))); }      // 'Purchase Orders' → 'purchase orders', 'GRN lines' stays

    private static ?array $addMap = null;
    /** form → [[title of the form that has the dropdown, label of the dropdown], …] */
    private static function addMap(): array
    {
        if (self::$addMap !== null) return self::$addMap;
        $out = [];
        foreach (DB::table('sys_forms')->where('f_active', 1)->pluck('title', 'form_key') as $key => $title) {
            if (! Registry::exists($key)) continue;
            foreach (Registry::fields($key) as $f) if (in_array($f['type'], ['select', 'multiselect'], true) && ($w = FormAssist::addWay($f)) && isset($w['form']) && $w['form'] !== $key) $out[$w['form']][] = [$title, $f['label']];
        }
        return self::$addMap = $out;
    }

    public static function forget(): void { self::$addMap = null; }

    // ---------- record: next step and what stops it ----------
    /** ['next' => [[text, label, url|action]], 'blocked' => [[why, label, url]], 'state' => 'OK'|'TODO'|'BLOCKED'] */
    public static function record(string $formKey, int $id, ?object $u): array
    {
        $table = Registry::table($formKey) ?? abort(404); $row = RecordService::get($formKey, $id); $next = []; $blocked = [];
        $fields = Registry::fields($formKey); $canEdit = $u && Perm::has($u, "form:$formKey:EDIT");
        // 1. switched off
        if (array_key_exists('f_active', $row) && ! (int) $row['f_active']) $blocked[] = ['why' => 'This record is switched off – it is not offered in dropdowns and screens skip it.', 'label' => $canEdit ? 'Edit and switch it on' : null, 'action' => 'edit', 'field' => 'f_active'];
        // 2. compulsory fields still empty (a Quick save leaves them for later)
        $miss = [];
        foreach ($fields as $f) {
            $c = $f['column_name']; if (! $c || ! $f['required'] || ! array_key_exists($c, $row) || str_starts_with((string) $f['default_rule'], 'numbering:') || ! Registry::visible($f, $u->role_code ?? null)) continue;
            $v = $row[$c]; if ($v === null || $v === '' || (in_array($f['type'], ['select'], true) && (string) $v === '0')) $miss[] = $f;
        }
        if ($miss) $next[] = ['text' => 'Finish this record: ' . implode(', ', array_map(fn ($f) => $f['label'], array_slice($miss, 0, 6))) . (count($miss) > 6 ? ' and ' . (count($miss) - 6) . ' more' : '') . ' ' . (count($miss) === 1 ? 'is' : 'are') . ' still empty.',
            'label' => $canEdit ? 'Fill them in' : null, 'action' => 'edit', 'field' => $miss[0]['field_name']];
        // 3. waiting for a second person
        foreach (DB::table('sys_approvals')->where('status', 'PENDING')->where('entity_table', $table)->where('entity_id', $id)->limit(3)->get() as $a)
            $blocked[] = ['why' => 'Waiting for approval (' . strtolower(str_replace('_', ' ', (string) $a->action_code)) . '): nothing is booked until the Owner or a Manager decides – never the person who asked.', 'label' => 'Open approvals', 'url' => '#/approvals'];
        // 4. tasks on this record
        $tasks = DB::table('tasks as t')->leftJoin('t_users as o', 'o.id', '=', 't.owner_user_id')->where('t.linked_module', $table)->where('t.linked_record_id', $id)->whereNotIn('t.status', ['DONE', 'APPROVED', 'CANCELLED'])
            ->orderByRaw("FIELD(t.priority,'URGENT','HIGH','NORMAL','LOW')")->orderBy('t.due_at')->limit(5)->get(['t.id', 't.title', 't.status', 't.due_at', 't.fix_url', 't.source', 't.stuck_on_text', 't.stuck_on_type', 'o.display_name as owner']);
        foreach ($tasks as $t) {
            $late = $t->due_at && $t->due_at < date('Y-m-d');
            if ($t->status === 'STUCK') $blocked[] = ['why' => 'Stuck: "' . $t->title . '" – ' . ($t->owner ?: 'someone') . ' is waiting on ' . strtolower((string) ($t->stuck_on_type ?: 'something')) . ($t->stuck_on_text ? ': ' . $t->stuck_on_text : '') . '.', 'label' => 'Open tasks', 'url' => '#/tasks?scope=all'];
            else $next[] = ['text' => ($late ? 'Late: ' : 'To do: ') . $t->title . ($t->owner ? ' – ' . $t->owner : '') . ($t->due_at ? ' · due ' . date('j M', strtotime((string) $t->due_at)) : '') . ($t->source === 'RULE' ? ' (closes by itself when it is fixed)' : ''),
                'label' => $t->fix_url ? 'Fix' : 'Open tasks', 'url' => $t->fix_url ?: '#/tasks?scope=all', 'late' => $late];
        }
        // 4b. R-G_CORE-4 improved: tasks stuck on this supplier / forwarder (a party record)
        if ($table === 't_account') foreach (self::stuckOnParty($id) as $st) $blocked[] = ['why' => "Task {$st['ref']} \"{$st['title']}\" is stuck on this " . strtolower(DB::table('tasks')->where('id', $st['id'])->value('stuck_on_type')) . " since {$st['since']}.", 'label' => 'Open the task', 'url' => $st['link']];
        // 5. where it stands in its status list, and what comes after
        foreach ($fields as $f) {
            if (! in_array($f['column_name'], ['status', 'e_status', 'stage'], true) || $f['type'] !== 'select' || ! array_key_exists($f['column_name'], $row)) continue;
            $opts = Registry::options($f, Context::ent(), null, 60); $vals = array_column($opts, 0); $i = array_search((string) $row[$f['column_name']], $vals, true);
            if ($i !== false && $i < count($opts) - 1 && count($opts) <= 12 && ! $tasks->count() && ! $miss) {
                $nextLabel = $opts[$i + 1][1];
                if (! preg_match('/cancel|reject|no.?go|park|void|closed|lost/i', $nextLabel)) $next[] = ['text' => $f['label'] . ' is "' . $opts[$i][1] . '". After this comes "' . $nextLabel . '".', 'label' => $canEdit ? 'Change ' . self::lower((string) $f['label']) : null, 'action' => 'edit', 'field' => $f['field_name']];
            }
            break;
        }
        // 6. its own work screen
        $ws = ['src_research' => ['#/research/', 'Open the research workspace (estimate, competitors, suppliers, GO)'], 't_src_cycles' => ['#/cycle/', 'Open the buying cycle – the step bar shows what is next'], 't_src_po' => ['#/po/', 'Open the purchase order page (payments, shipments, receiving)']];
        if (isset($ws[$table])) $next[] = ['text' => $ws[$table][1] . '.', 'label' => 'Open', 'url' => $ws[$table][0] . $id];
        // 7. the month it belongs to is locked
        $lock = (string) Settings::get('GL_LOCKED_UPTO', Context::ent(), '');
        if ($lock !== '') foreach (['d_order', 'txn_date', 'expense_date', 'doc_date', 'd_entry'] as $dc) if (! empty($row[$dc]) && substr((string) $row[$dc], 0, 7) <= $lock) { $blocked[] = ['why' => 'Its month (' . substr((string) $row[$dc], 0, 7) . ') is locked. Money figures of a locked month never change; a correction is booked in the next open month.', 'label' => 'Month close', 'url' => '#/close']; break; }
        if (! $canEdit && $u) $blocked[] = ['why' => 'Your role can look at this record but not change it.', 'label' => 'Ask the Owner', 'ask' => "form:$formKey:EDIT"];
        $real = array_filter($blocked, fn ($b) => empty($b['ask']));
        return ['next' => $next, 'blocked' => $blocked, 'state' => $real ? 'BLOCKED' : ($next ? 'TODO' : 'OK'), 'ok_text' => 'Nothing is waiting on this record.'];
    }

    // ---------- step playbooks ----------
    /** The workflows and steps a playbook can be written for: code => [name, [step code => step name]]. */
    public static function workflows(): array
    {
        return [
            'SOURCING_CYCLE' => ['Buying cycle', ['rfq' => 'Quotes', 'po' => 'Purchase order', 'pay' => 'Payments', 'ship' => 'Shipment', 'grn' => 'Receiving', 'lc' => 'Landed cost', 'sample' => 'Samples', 'qc' => 'Quality check', 'customs' => 'Customs']],
            'MONTH_CLOSE' => ['Month close', array_map(fn ($c) => $c[0], CloseService::CHECKS) + ['LOCK' => 'Lock the month']],
            'IMPORT' => ['Import a file', ['choose' => 'Choose the file', 'check' => 'Check what was read', 'apply' => 'Apply', 'undo' => 'Undo an import']],
            'WAREHOUSE' => ['Warehouse', ['receive' => 'Receive goods', 'putaway' => 'Put away', 'pick' => 'Pick', 'pack' => 'Pack', 'handover' => 'Courier handover', 'return' => 'Returns', 'count' => 'Stock count']],
            'SALES' => ['Sales', ['intake' => 'Load orders', 'labels' => 'Carrier labels', 'confirm' => 'Month confirm', 'settlement' => 'Load the money file', 'refund' => 'Refunds']],
        ];
    }

    public static function playbooks(string $workflow): array
    {
        $out = [];
        foreach (DB::table('sys_playbooks')->where('workflow_code', $workflow)->where('f_active', 1)->whereIn('fk_id_ent', [0, (int) Context::ent()])->orderBy('fk_id_ent')->get() as $p)
            $out[strtolower((string) $p->step_code)] = ['id' => $p->id, 'title' => $p->title, 'checklist' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $p->checklist_text)), fn ($l) => $l !== '')), 'video' => $p->video_url];
        return $out;
    }

    /** Every step with its playbook or "missing" – the Admin's list. */
    public static function playbookCoverage(): array
    {
        $out = [];
        foreach (self::workflows() as $code => [$name, $steps]) { $have = self::playbooks($code);
            foreach ($steps as $s => $label) $out[] = ['workflow' => $code, 'workflow_name' => $name, 'step' => $s, 'step_name' => $label, 'playbook' => $have[strtolower((string) $s)] ?? null]; }
        return $out;
    }

    // ---------- recent pages and favourites (sys_user_nav) ----------
    public static function nav(object $u): array
    {
        $rows = DB::table('sys_user_nav')->where('fk_id_account', $u->id)->orderByDesc('used_at')->orderByDesc('id')->get();
        $map = fn ($r) => ['title' => $r->title, 'url' => $r->url];
        return ['recent' => $rows->where('kind', 'RECENT')->take(8)->map($map)->values()->all(), 'favourites' => $rows->where('kind', 'FAVOURITE')->sortBy('title')->map($map)->values()->all()];
    }

    public static function visited(object $u, string $title, string $url): void
    {
        if (! preg_match('~^#/[\w\-./?=&%:\[\]]{0,240}$~', $url) || $title === '') return;
        $w = ['fk_id_account' => $u->id, 'kind' => 'RECENT', 'url' => $url];
        DB::table('sys_user_nav')->where($w)->delete();
        DB::table('sys_user_nav')->insert($w + ['title' => mb_substr($title, 0, 150), 'used_at' => now()]);
        $old = DB::table('sys_user_nav')->where(['fk_id_account' => $u->id, 'kind' => 'RECENT'])->orderByDesc('used_at')->orderByDesc('id')->skip(8)->take(100)->pluck('id');
        if ($old->isNotEmpty()) DB::table('sys_user_nav')->whereIn('id', $old)->delete();
    }

    public static function favourite(object $u, string $title, string $url, bool $on): void
    {
        if (! preg_match('~^#/[\w\-./?=&%:\[\]]{0,240}$~', $url)) throw new \InvalidArgumentException('This page cannot be a favourite.');
        $w = ['fk_id_account' => $u->id, 'kind' => 'FAVOURITE', 'url' => $url];
        DB::table('sys_user_nav')->where($w)->delete();
        if ($on) { if (DB::table('sys_user_nav')->where(['fk_id_account' => $u->id, 'kind' => 'FAVOURITE'])->count() >= 30) throw new \InvalidArgumentException('30 favourites is the most – remove one first.'); DB::table('sys_user_nav')->insert($w + ['title' => mb_substr($title ?: $url, 0, 150), 'used_at' => now()]); }
    }

    // ---------- "I am stuck" on a task (R-G_CORE-4) ----------
    public const STUCK_ON = ['USER' => 'A person in the team', 'SUPPLIER' => 'A supplier', 'MARKETPLACE' => 'A marketplace', 'FORWARDER' => 'A forwarder', 'OTHER' => 'Something else'];

    public static function stuck(int $taskId, object $u, string $type, ?int $onId, ?string $text): void
    {
        $t = DB::table('tasks')->where('id', $taskId)->first() ?? abort(404);
        $cover = $t->owner_user_id && in_array((int) $t->owner_user_id, TaskVisibility::covered((int) $u->id), true);      // R-G_CORE-4 / 19: owner, boss or the cover
        if ((int) $t->owner_user_id !== (int) $u->id && ! $cover && ! Perm::has($u, 'work.taskStatus_may')) abort(403, 'This task belongs to someone else.');
        if (in_array($t->status, ['DONE', 'APPROVED', 'CANCELLED'], true)) throw new \InvalidArgumentException('This task is closed.');
        if (! isset(self::STUCK_ON[$type])) throw new \InvalidArgumentException('Choose what the task is stuck on.');
        $text = trim((string) $text); if (mb_strlen($text) > 255) throw new \InvalidArgumentException('Keep the text to 255 characters.');
        if ($type === 'USER') {
            $p = $onId ? DB::table('t_users')->where('id', $onId)->where('f_active', 1)->first() : null;
            if (! $p || (int) $p->id === (int) $t->owner_user_id) throw new \InvalidArgumentException('Choose an active person who is not the owner of the task.');
            $name = $p->display_name ?: $p->login_id;
        } elseif ($type === 'MARKETPLACE') {
            $name = $onId ? DB::table('mp_profiles')->where('id', $onId)->value(Registry::labelColumn('mp_profiles') ?: 'id') : null;
            if (! $name && $text === '') throw new \InvalidArgumentException('Refused: name the marketplace it is stuck on.');
        } else {
            if ($onId && $type !== 'OTHER' && ! in_array($onId, array_column(self::parties($type, (int) $t->fk_id_ent ?: null), 0), true)) throw new \InvalidArgumentException('Refused: pick the ' . strtolower($type) . ' from the list of ' . strtolower($type) . 's (party master).');
            $name = $onId && $type !== 'OTHER' ? trim((string) DB::table('t_account')->where('id', $onId)->selectRaw("CONCAT_WS(' ', first_name, sur_name) as n")->value('n')) : null;
            if (! $name && $text === '') throw new \InvalidArgumentException('Refused: name the ' . strtolower($type) . ' it is stuck on.');
        }
        DB::transaction(function () use ($t, $u, $type, $onId, $text, $name) {
            DB::table('tasks')->where('id', $t->id)->update(['status' => 'STUCK', 'stuck_on_type' => $type, 'stuck_on_id' => $onId ?: null, 'stuck_on_text' => $text !== '' ? $text : ($name ?: null), 'stuck_since' => now(), 'last_update_at' => now()]);
            DB::table('task_history')->insert(['task_id' => $t->id, 'at' => now(), 'user_id' => $u->id, 'field' => 'status', 'old_value' => $t->status, 'new_value' => 'STUCK', 'reason' => trim(self::STUCK_ON[$type] . ': ' . ($name ?: '') . ' ' . $text)]);
            DB::table('task_comments')->insert(['task_id' => $t->id, 'at' => now(), 'user_id' => $u->id, 'text' => 'STUCK: ' . trim(($name ? $name . ' – ' : '') . $text), 'via' => 'APP']);
            Audit::log('tasks', $t->id, 'UPDATE', ['status' => [$t->status, 'STUCK'], 'stuck_on' => [null, $type]]);
            if ($type === 'USER') Notify::push((int) $onId, 'TASK', ($u->display_name ?: $u->login_id) . ' is stuck on you: ' . $t->title . ($text !== '' ? ' – ' . $text : ''), '#/tasks?scope=stuck_on_me', (int) $t->id);
        });
    }

    /** R-G_CORE-4 picker: suppliers / forwarders from the party master (party role SUPPLIER / FORWARDER), marketplaces from the marketplace profiles. [[id, name]] */
    public static function parties(string $type, ?int $ent = null): array
    {
        if ($type === 'MARKETPLACE') return DB::table('mp_profiles')->when($ent && \Illuminate\Support\Facades\Schema::hasColumn('mp_profiles', 'fk_id_ent'), fn ($q) => $q->where('fk_id_ent', $ent))->orderBy('id')->limit(500)->get()
            ->map(fn ($m) => [(int) $m->id, (string) ($m->{Registry::labelColumn('mp_profiles') ?: 'id'} ?? $m->id)])->all();
        if (! in_array($type, ['SUPPLIER', 'FORWARDER'], true)) return [];
        return DB::table('t_account as a')->join('t_account_in_types as at', 'at.fk_id_account', '=', 'a.id')->join('cat_account_types as ty', 'ty.id', '=', 'at.fk_id_account_type')
            ->whereRaw('UPPER(ty.role_code) = ?', [$type])->where('a.f_active', 1)->where('at.f_active', 1)->when($ent, fn ($q) => $q->where('a.fk_id_ent', $ent))
            ->selectRaw("a.id, TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as n")->distinct()->orderBy('n')->limit(500)->get()->map(fn ($a) => [(int) $a->id, (string) $a->n])->all();
    }

    /** R-G_CORE-4 improved: open tasks stuck on a supplier / forwarder (shown on the party's page). */
    public static function stuckOnParty(int $accountId): array
    {
        return DB::table('tasks')->where('status', 'STUCK')->whereIn('stuck_on_type', ['SUPPLIER', 'FORWARDER'])->where('stuck_on_id', $accountId)->orderBy('stuck_since')
            ->get(['id', 'title', 'stuck_since', 'stuck_on_type'])->map(fn ($t) => ['id' => (int) $t->id, 'ref' => TaskFlow::ref((int) $t->id), 'title' => $t->title, 'since' => substr((string) $t->stuck_since, 0, 10), 'link' => '#/task/' . $t->id])->all();
    }

    /** Leaving STUCK (start again, or done) clears the four stuck fields. */
    public static function unstuck(int $taskId): void
    {
        DB::table('tasks')->where('id', $taskId)->update(['stuck_on_type' => null, 'stuck_on_id' => null, 'stuck_on_text' => null, 'stuck_since' => null]);
    }
}

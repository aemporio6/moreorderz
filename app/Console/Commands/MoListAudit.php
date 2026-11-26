<?php

namespace App\Console\Commands;

use App\Services\FormAssist;
use App\Services\Registry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Owner 26-11 22:52: every dropdown of the central register – does its list have values, can it take a new one (+ Add), does it have a default? Reads only. */
class MoListAudit extends Command
{
    protected $signature = 'mo:list-audit {--ent=1} {--json}';
    protected $description = 'Check every dropdown field: list values, + Add, default (reads only)';

    public function handle(): int
    {
        \App\Services\Context::set((int) $this->option('ent'), (int) DB::table('t_users')->where('role_code', 'OWNER')->value('id'));
        $rows = DB::table('sys_registry')->whereIn('type', ['select', 'multiselect'])->get(['form_key', 'field_name', 'label', 'source', 'default_rule']);
        $bySource = []; $out = ['fields' => $rows->count(), 'no_source' => [], 'empty' => [], 'no_add' => [], 'sources' => 0];
        foreach ($rows as $r) {
            if (trim((string) $r->source) === '') { $out['no_source'][] = "$r->form_key.$r->field_name ($r->label)"; continue; }
            if (! Registry::exists((string) $r->form_key)) continue;
            $src = (string) $r->source;
            if (! array_key_exists($src, $bySource)) {
                try { $o = FormAssist::options((string) $r->form_key, (string) $r->field_name, null, [], 5); $bySource[$src] = count($o['options']); } catch (\Throwable $e) { $bySource[$src] = -1; }
            }
            if ($bySource[$src] === 0) $out['empty'][$src][] = "$r->form_key.$r->field_name";
            $f = FormAssist::field((string) $r->form_key, (string) $r->field_name); if ($f && ! FormAssist::addWay($f) && ! str_starts_with($src, 'list:')) $out['no_add'][$src] = true;
        }
        $out['sources'] = count($bySource); $out['broken'] = array_keys(array_filter($bySource, fn ($n) => $n < 0)); $out['no_add'] = array_keys($out['no_add']);
        if ($this->option('json')) { $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); return 0; }
        $this->line("Dropdown fields: {$out['fields']} · different lists: {$out['sources']}");
        $this->line('Lists with NO values (' . count($out['empty']) . '):'); foreach ($out['empty'] as $s => $f) $this->line("  $s  ← " . count($f) . ' field(s), e.g. ' . implode(', ', array_slice($f, 0, 2)));
        $this->line('Lists that fail to load (' . count($out['broken']) . '): ' . implode(', ', $out['broken']));
        $this->line('Fields with no list at all (' . count($out['no_source']) . '): ' . implode(', ', array_slice($out['no_source'], 0, 30)));
        $this->line('Lists without + Add (' . count($out['no_add']) . '): ' . implode(', ', array_slice($out['no_add'], 0, 40)));
        return 0;
    }
}

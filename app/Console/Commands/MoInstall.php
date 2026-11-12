<?php

namespace App\Console\Commands;

use App\Services\Ledger;
use App\Services\Registry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MoInstall extends Command
{
    protected $signature = 'mo:install {--fresh : drop every table first (never on a live database)}';
    protected $description = 'Set up the MoreOrderz database: baseline schema, core tables, field registry, menu roles, chart of accounts';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            if (app()->environment('production')) { $this->error('--fresh is not allowed in production.'); return 1; }
            Schema::dropAllTables();
        }
        if (! Schema::hasTable('sys_forms')) {
            $this->info('Loading baseline schema…');
            DB::unprepared(file_get_contents(database_path('schema/baseline.sql')));
            DB::reconnect();   // the baseline file changes session settings (names, collation); start clean
        }
        Artisan::call('migrate', ['--force' => true]);
        $this->line(trim(Artisan::output()));
        $this->info('Registry: ' . self::loadRegistry() . ' fields');
        $this->info('Menu roles: ' . self::loadMenuRoles() . ' screens');
        Ledger::seedChart();
        \App\Services\PrintService::seed();
        $this->info('Printing: ' . DB::table('label_templates')->count() . ' templates, ' . DB::table('print_routes')->count() . ' routes');
        $this->info('Chart of accounts: ' . DB::table('gl_accounts')->count() . ' accounts');
        return 0;
    }

    public static function loadRegistry(): int
    {
        $fh = fopen(database_path('meta/field_registry.csv'), 'r');
        $head = fgetcsv($fh);
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            $a = array_combine($head, array_pad($r, count($head), ''));
            $rows[] = [
                'form_key' => $a['form_key'], 'form_title' => $a['form_title'], 'form_kind' => $a['form_kind'], 'section' => $a['section'] ?: null,
                'section_no' => (int) $a['section_no'] ?: 1, 'field_no' => (int) $a['field_no'] ?: 1, 'field_name' => $a['field_name'], 'label' => $a['label'],
                'type' => $a['type'], 'source' => $a['source'] ?: null, 'required' => $a['required'] === 'Y', 'in_quick' => $a['in_quick'] === 'Y',
                'default_rule' => ($a['default_rule'] ?: null), 'cascades_from' => $a['cascades_from'] ?: null, 'visible_to' => $a['visible_to'] ?: 'all',
                'readonly' => $a['readonly'] === 'Y', 'auto_correct' => $a['auto_correct'] ?: null, 'step' => $a['step'] ?: null, 'min' => $a['min'] !== '' ? $a['min'] : null,
                'max' => $a['max'] !== '' ? $a['max'] : null, 'help' => mb_substr($a['help'], 0, 600) ?: null, 'options_full' => $a['options_full'] ?: null,
                'table_name' => $a['table_name'] ?: null, 'column_name' => $a['column_name'] ?: null, 'add_form' => $a['add_form'] ?: null,
                'is_master' => $a['is_master'] === 'Y', 'required_if' => $a['required_if'] ?: null, 'show_if' => $a['show_if'] ?: null, 'secret' => $a['secret'] === 'Y',
            ];
        }
        fclose($fh);
        DB::transaction(function () use ($rows) {
            DB::table('sys_registry')->delete();
            foreach (array_chunk($rows, 300) as $chunk) DB::table('sys_registry')->insert($chunk);
        });
        Registry::forget();
        return count($rows);
    }

    public static function loadMenuRoles(): int
    {
        $fh = fopen(database_path('meta/menu_tree.csv'), 'r');
        $head = fgetcsv($fh);
        $n = 0;
        while (($r = fgetcsv($fh)) !== false) {
            $a = array_combine($head, array_pad($r, count($head), ''));
            $n += DB::table('sidebar_modules')->where('module_name', $a['screen'])->where('module_key', 'like', 'M\_' . str_replace('.', '\_', $a['item_no']) . '\_%')
                ->update(['roles' => mb_substr($a['roles'], 0, 200), 'screen_no' => $a['item_no'], 'forms' => mb_substr($a['forms'], 0, 1000)]);
        }
        fclose($fh);
        return $n;
    }
}

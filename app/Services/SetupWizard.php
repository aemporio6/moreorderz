<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\DB;

/**
 * 17.1 First-time setup wizard: the steps in order, each worked out from the data (done when the thing exists) or ticked by the Owner.
 * "Open" goes to the screen of the step; the Home banner shows until every step is done or ticked.
 */
class SetupWizard
{
    /** key => [title, what to do, screen number, can be ticked without data] */
    public const STEPS = [
        'company' => ['Company', 'Name, short code, VAT number and base currency of the company.', '12.4', false],
        'banks' => ['Bank accounts', 'Every bank, wallet and card account the money moves through, with its opening balance.', '9.2', false],
        'marketplaces' => ['Marketplace profiles', 'Amazon, eBay, TikTok, OnBuy, Temu … – each marketplace you sell on.', '17.3', false],
        'shops' => ['Shops', 'Each seller account (shop) with its marketplace and main warehouse.', '12.5', false],
        'carriers' => ['Carriers', 'Royal Mail, Evri, DPD … and their services.', '17.4', true],
        'printers' => ['Printers and test page', 'Add a printer (or use the PDF printer) and print a test page.', '17.9', true],
        'warehouse' => ['Warehouse and bins', 'Your warehouse and its bins (the bin generator makes them in one go).', '4.4', false],
        'people' => ['People and roles', 'A login for each person with the right role.', '17.15', false],
        'opening' => ['Opening balances', 'Opening stock, bank, partner capital, supplier dues on the day the tool starts.', '10.10', true],
        'imports' => ['First files', 'Load the first orders or money file of each shop (or the old tool\'s data).', '15.1', true],
        'switch' => ['Go live', 'Once the checks are green, switch from the old tool to this one.', '17.19', true],
    ];

    private static function has(string $table, array $where): bool
    {
        try { $q = DB::table($table); foreach ($where as $k => $v) $q->where($k, $v); return $q->exists(); } catch (\Throwable) { return false; }
    }

    /** What the data already shows as done. */
    private static function auto(int $ent): array
    {
        $co = DB::table('t_enterprise')->where('id', $ent)->first();
        return [
            'company' => $co && trim((string) $co->ent_name) !== '',
            'banks' => self::has('fin_bank_accounts', ['company_id' => $ent, 'is_active' => 1]),
            'marketplaces' => self::has('cat_marketplaces', ['fk_id_ent' => $ent]),
            'shops' => self::has('t_branches', ['fk_id_ent' => $ent, 'f_active' => 1]),
            'carriers' => self::has('t_carriers', []),
            'printers' => (bool) DB::table('print_jobs')->where('fk_id_ent', $ent)->where('entity_type', 'PRINTER')->exists(),
            'warehouse' => DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('kind', 'BIN')->where('f_active', 1)->exists(),
            'people' => DB::table('t_users')->where('fk_id_ent', $ent)->where('f_active', 1)->count() > 1,
            'opening' => self::has('fin_opening_locks', ['company_id' => $ent]) || DB::table('fin_lots')->where('company_id', $ent)->where('source', 'OPENING')->exists(),
            'imports' => DB::table('fin_import_batches')->where('company_id', $ent)->exists() || DB::table('t_sales')->where('fk_id_ent', $ent)->exists(),
            'switch' => (string) Settings::get('MIGRATION_LIVE', $ent, '') === '1',
        ];
    }

    public static function page(int $ent): array
    {
        $auto = self::auto($ent); $ticks = json_decode((string) Settings::get('SETUP_TICKS', $ent, '{}'), true) ?: [];
        $mods = DB::table('sidebar_modules')->whereIn('screen_no', array_column(self::STEPS, 2))->get()->keyBy('screen_no');
        $steps = []; $n = 0;
        foreach (self::STEPS as $k => [$title, $what, $no, $tickable]) {
            $n++; $done = $auto[$k] || isset($ticks[$k]);
            $steps[] = ['key' => $k, 'no' => $n, 'title' => $title, 'what' => $what, 'screen' => $no, 'open' => isset($mods[$no]) ? AppController::routeOf($mods[$no]) : null, 'done' => $done, 'by_data' => $auto[$k], 'ticked' => $ticks[$k] ?? null, 'can_tick' => $tickable];
        }
        $left = array_values(array_filter($steps, fn ($s) => ! $s['done']));
        return ['steps' => $steps, 'done' => count($steps) - count($left), 'total' => count($steps), 'next' => $left[0]['key'] ?? null, 'can_edit' => Context::role() === 'OWNER'];
    }

    public static function open(int $ent): bool { return self::page($ent)['next'] !== null; }

    /** Tick (or untick) a step. Steps that need data cannot be ticked: they turn green by themselves. */
    public static function tick(int $ent, string $key, bool $on, ?string $note = null): void
    {
        if (Context::role() !== 'OWNER') throw new \InvalidArgumentException('Only the Owner ticks a setup step.');
        $s = self::STEPS[$key] ?? throw new \InvalidArgumentException('Unknown setup step.');
        if ($on && ! $s[3]) throw new \InvalidArgumentException("\"{$s[0]}\" turns green by itself once it is filled in – open it and add what is missing.");
        $t = json_decode((string) Settings::get('SETUP_TICKS', $ent, '{}'), true) ?: [];
        if ($on) $t[$key] = ['by' => Context::userId(), 'at' => date('Y-m-d H:i'), 'note' => $note]; else unset($t[$key]);
        Settings::set('SETUP_TICKS', json_encode($t), $ent);
        Audit::log('sys_settings', 0, 'UPDATE', ['setup_step' => $key, 'ticked' => $on], $note, $ent);
    }
}

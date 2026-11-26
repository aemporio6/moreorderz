<?php

namespace App\Services;

/**
 * Settings › Warehouse (R-H-57): every warehouse switch in tabs, each with its default and help; company level, history per change;
 * changing one never changes existing data. Channel, carrier and calendar values live on their profiles (links on the page);
 * roles and rights are on the rights screen (R-H-58). Definitions: [label, type, default, help, extra] (TypedSettings).
 */
class WarehouseSettings
{
    private const YN = 'yn';

    public static function tabs(): array
    {
        $o = fn (array $x) => ['options' => $x];
        return [
            'General' => [
                'WH_ENABLED' => ['Warehouse module on', self::YN, 'N', 'Off = stock is kept per location only; the warehouse screens stay hidden.'],
                'WH_PRESET' => ['Preset', 'enum', 'SIMPLE', 'Simple or Full fills the switches (Warehouse mode page); each switch stays editable here.', $o(['SIMPLE' => 'Simple', 'FULL' => 'Full', 'CUSTOM' => 'Custom'])],
                'WH_ZONES_ON' => ['Use zones', self::YN, 'N', 'Bins are grouped in zones (pick by zone, zone in the bin code).'],
                'WH_STORAGE_MODE' => ['Storage mode', 'enum', 'FIXED', 'Fixed homes per SKU, a fixed pick bin with free reserve, or any free bin.', $o(['FIXED' => 'Fixed homes', 'HYBRID' => 'Fixed pick bin + free reserve', 'CHAOTIC' => 'Any free bin'])],
                'WH_BIN_MULTI_SKU' => ['Several SKUs per bin', self::YN, 'Y', 'No = a bin holds one SKU only.'],
                'WH_UNASSIGNED_SELLABLE' => ['Unassigned stock is sellable', self::YN, 'Y', 'Units not yet in a bin (after go-live) can be sold.'],
                'WH_RECEIVING_SELLABLE' => ['Receiving stock is sellable', self::YN, 'N', 'Units waiting for putaway can be sold.'],
                'WH_SEGREGATED_OWNERS' => ['Owners kept apart', 'people', '', 'Owners whose units sit apart (for example a consignment partner): their units have their own shelf rows, only their orders pick them, and the stock check runs per owner. Recommended: none.', ['optional' => true]],
            ],
            'Location codes' => [
                'WH_LOC_FORMAT' => ['Bin code format', 'text', '{ZONE}-{LEVEL}-{BIN}', 'Tokens {SITE} {ZONE} {LEVEL} {BIN} {POS} (R-H-4).', ['max' => 60, 'pattern' => '/\{(SITE|ZONE|LEVEL|BIN|POS)\}/', 'hint' => 'Use at least one token: {SITE} {ZONE} {LEVEL} {BIN} {POS}']],
                'WH_LOC_SEPARATOR' => ['Separator', 'text', '-', 'Between the parts of a bin code.', ['max' => 3]],
                'WH_LOC_LEVEL_STYLE' => ['Level names', 'enum', 'LETTER', 'Shelf levels as A, B, C … or 1, 2, 3 …', $o(['LETTER' => 'A, B, C …', 'NUMBER' => '1, 2, 3 …'])],
                'WH_LOC_PAD' => ['Zero padding (digits)', 'int', '0', '0 = no padding; 2 = 01, 02 …', ['min' => 0, 'max' => 4]],
                'WH_BIN_BARCODE_PREFIX' => ['Bin barcode prefix', 'text', 'L:', 'Put before the bin code in its barcode, so a scan knows it is a bin.', ['max' => 10]],
                'WH_SYSTEM_BIN_PREFIX' => ['System bin prefix', 'text', 'SYS-', 'Code prefix of Receiving, Dispatch, Returns … locations.', ['max' => 10]],
            ],
            'Receiving and putaway' => [
                'WH_PUTAWAY_STEP' => ['Receive to Receiving then put away', self::YN, 'N', 'Yes = a GRN goes to Receiving and a putaway task opens (R-H-7).'],
                'WH_SCAN_COUNTS_UNITS' => ['Each scan counts one unit', self::YN, 'N', 'Yes = scanning an item adds 1 instead of typing the quantity.'],
                'WH_RECEIVING_MAX_HOURS' => ['Putaway reminder after (hours)', 'int', '24', 'Units in Receiving longer than this open WH_PUTAWAY_WAITING.', ['min' => 1, 'max' => 720]],
                'WH_UNASSIGNED_DAYS' => ['Unassigned stock alert after (days)', 'int', '14', 'Units in the unassigned bin longer than this open WH_UNASSIGNED_STOCK.', ['min' => 1, 'max' => 365]],
                'WH_HOME_MIN_DEFAULT' => ['Home bin minimum (default)', 'int', '', 'Empty = no minimum; used when a SKU home has none.', ['min' => 0, 'max' => 100000, 'optional' => true]],
            ],
            'Reservation' => [
                'WH_RESERVE_ON' => ['Reserve stock', 'enum', 'INTAKE', 'When an order takes its units.', $o(['INTAKE' => 'At order intake', 'PAID' => 'When paid', 'PICKLIST' => 'At pick-list build'])],
                'WH_RESERVE_EXPIRY_HOURS' => ['Reservation expiry (hours, 0 = never)', 'int', '0', 'An unpicked reservation is released after this many hours.', ['min' => 0, 'max' => 720]],
                'pool_auto_cover' => ['Pool covers a short private order', 'int', '0', '1 = a private order short of its owner\'s units may take pool units; 0 = never.', ['min' => 0, 'max' => 1]],
            ],
            'Picking' => [
                'WH_PICK_MODE' => ['Pick mode', 'enum', 'SINGLE', 'One order at a time, a batch, a wave or by zone.', $o(['SINGLE' => 'One order', 'BATCH' => 'Batch', 'WAVE' => 'Wave', 'ZONE' => 'By zone'])],
                'WH_PICK_STRATEGY' => ['Which bin first', 'enum', 'HOME_FIRST', 'The bin the pick list sends the picker to first.', $o(['HOME_FIRST' => 'Home first', 'LOWEST_QTY_FIRST' => 'Lowest quantity first', 'NEAREST_TO_PACKING' => 'Nearest to packing', 'FIFO_BIN' => 'Oldest put-away first'])],
                'WH_BATCH_MAX_ORDERS' => ['Orders per batch', 'int', '20', 'Largest batch pick list.', ['min' => 1, 'max' => 200]],
                'WH_PICKLIST_AUTO_MINUTES' => ['Build pick lists every (minutes, 0 = by hand)', 'int', '0', 'The system builds a pick list from open reservations this often.', ['min' => 0, 'max' => 1440]],
                'WH_PICK_IDLE_MINUTES' => ['Pick list idle after (minutes)', 'int', '120', 'No scan for this long → the list shows as idle.', ['min' => 5, 'max' => 1440]],
                'WH_PICK_ABANDON_HOURS' => ['Pick list abandoned after (hours)', 'int', '8', 'Opens WH_PICK_ABANDONED to the supervisor.', ['min' => 1, 'max' => 72]],
                'WH_ROUTE_SNAKE' => ['Snake route', self::YN, 'N', 'Yes = walk up one aisle and down the next.'],
            ],
            'Scanning' => [
                'WH_SCAN_MODE' => ['Scanning', 'enum', 'BOTH', 'Hardware scanner, phone camera, both, or tap only.', $o(['BOTH' => 'Scanner and camera', 'HARDWARE' => 'Scanner only', 'CAMERA' => 'Camera only', 'OFF' => 'Off (tap only)'])],
                'WH_SCAN_CONFIRM_RECEIVE' => ['Scan to confirm – receive', self::YN, 'Y', ''],
                'WH_SCAN_CONFIRM_PUTAWAY' => ['Scan to confirm – putaway', self::YN, 'Y', ''],
                'WH_SCAN_CONFIRM_PICK' => ['Scan to confirm – pick', self::YN, 'Y', ''],
                'WH_PACK_SCAN_REQUIRED' => ['Scan to confirm – pack', self::YN, 'N', 'Every item is scanned into the parcel.'],
                'WH_HANDOVER_SCAN_REQUIRED' => ['Scan every parcel at handover', self::YN, 'Y', 'The courier manifest lists only scanned parcels.'],
                'WH_SCAN_CONFIRM_RETURN' => ['Scan to confirm – returns', self::YN, 'Y', ''],
                'WH_OFFLINE_COUNT' => ['Offline counting', self::YN, 'N', 'Counts saved on the phone and sent when the network is back (later phase).'],
            ],
            'Packing and dispatch' => [
                'WH_PACKING_STOCK' => ['Packing materials as stock', self::YN, 'N', 'Boxes and mailers are counted down when a parcel is packed.'],
                'WH_AUTOPRINT_PACKING_SLIP' => ['Auto-print packing slip on pack', self::YN, 'N', ''],
                'WH_HANDOVER_REOPEN_MINUTES' => ['Handover can be reopened for (minutes)', 'int', '60', 'After this a closed handover stays closed.', ['min' => 0, 'max' => 1440]],
                'WH_UNSHIPPED_AT_CONFIRM' => ['Unshipped lines at Month Confirm', 'enum', 'ASK', 'What Month Confirm does with sold lines that never left the warehouse.', $o(['ASK' => 'Ask (one-click backfill)', 'AUTO' => 'Backfill automatically', 'BLOCK' => 'Block Confirm'])],
            ],
            'Returns and held stock' => [
                'WH_RETURN_NEW_TO' => ['New / unopened returns go to', 'enum', 'HOME', '', $o(['HOME' => 'Home bin', 'RETURNS' => 'Returns area'])],
                'WH_RETURN_OPENED_TO' => ['Opened resellable returns go to', 'enum', 'RETURNS', '', $o(['RETURNS' => 'Returns area', 'HOME' => 'Home bin'])],
                'WH_RETURN_UNMATCHED_DAYS' => ['Unmatched return alert after (days)', 'int', '30', 'Opens WH_RETURN_UNMATCHED.', ['min' => 1, 'max' => 365]],
                'WH_HOLD_AGED_DAYS' => ['Held stock alert after (days)', 'int', '14', 'Units in Quarantine / Returns longer than this open WH_HOLD_AGED.', ['min' => 1, 'max' => 365]],
            ],
            'Replenishment' => [
                'WH_REPLEN_MODE' => ['Replenishment', 'enum', 'OFF', 'Below the home minimum: nothing, an alert, or a move task.', $o(['OFF' => 'Off', 'ALERT' => 'Alert', 'TASK' => 'Move task'])],
            ],
            'Counting' => [
                'WH_CYCLE_COUNT_MODE' => ['Cycle counts', 'enum', 'TRIGGERS_ONLY', 'Automatic counts on triggers, plus the ABC schedule (R-H-47).', $o(['TRIGGERS_ONLY' => 'On triggers only', 'ABC_SCHEDULE' => 'Triggers + ABC schedule', 'OFF' => 'Off'])],
                'WH_COUNT_BLIND' => ['Blind count', self::YN, 'Y', 'The counter does not see the system quantity.'],
                'WH_COUNT_ON_ZERO' => ['Ask "Is it empty?" when a pick empties a bin', self::YN, 'Y', ''],
                'WH_COUNT_DAYS_A' => ['Count A items every (days)', 'int', '30', '', ['min' => 1, 'max' => 730]],
                'WH_COUNT_DAYS_B' => ['Count B items every (days)', 'int', '90', '', ['min' => 1, 'max' => 730]],
                'WH_COUNT_DAYS_C' => ['Count C items every (days)', 'int', '180', '', ['min' => 1, 'max' => 730]],
                'WH_COUNT_DAILY_MAX_BINS' => ['Bins on one day\'s count list (max)', 'int', '20', '', ['min' => 1, 'max' => 1000]],
                'WH_RECOUNT_THRESHOLD_UNITS' => ['Recount when off by more than (units)', 'int', '2', '', ['min' => 0, 'max' => 1000]],
                'WH_RECOUNT_THRESHOLD_PCT' => ['Recount when off by more than (%)', 'pct', '5', ''],
                'WH_COUNT_APPROVAL_VALUE' => ['Count approval above (base currency)', 'decimal', '100', 'A count difference worth more waits for a second person.', ['min' => 0, 'max' => 1000000]],
                'WH_MISSING_WRITE_OFF_DAYS' => ['Missing units written off after (days)', 'int', '30', 'Opens WH_MISSING_STOCK to write them off.', ['min' => 1, 'max' => 365]],
                'WH_LOSS_LOT_RULE' => ['Which lots take a write-off', 'enum', 'FIFO', 'Oldest lots first, or pro rata by owner (owner to confirm).', $o(['FIFO' => 'Oldest lots first', 'PRO_RATA' => 'Pro rata by owner'])],
            ],
            'Adjustments' => [
                'ADJUST_APPROVAL_QTY' => ['Adjustment approval above (units)', 'int', '50', 'A bigger stock adjustment waits for the Owner.', ['min' => 0, 'max' => 100000]],
                'WH_ADJUST_NOTE_QTY' => ['Note required above (units)', 'int', '5', '', ['min' => 0, 'max' => 100000]],
                'WH_ADJUST_ALERT_PER_WEEK' => ['Unusual: adjustments per user or SKU per week', 'int', '3', 'More opens WH_ADJUST_UNUSUAL.', ['min' => 1, 'max' => 100]],
            ],
            'Slotting' => [
                'WH_ABC_DAYS' => ['ABC look-back (days)', 'int', '90', '', ['min' => 7, 'max' => 730]],
                'WH_ABC_A_PCT' => ['A class = top (% of picks)', 'pct', '80', ''],
                'WH_ABC_B_PCT' => ['B class = next (% of picks)', 'pct', '15', ''],
            ],
            'Printing' => [
                'WH_AUTOPRINT_ON_RECEIVE' => ['Auto-print product labels on receive', self::YN, 'N', ''],
                'WH_AUTOPRINT_SHELF' => ['Auto-print shelf labels', self::YN, 'Y', 'After the bin generator, a rename or a home change.'],
                'WH_AUTOPRINT_PICK_SHEET' => ['Auto-print the pick sheet', self::YN, 'N', ''],
                'WH_AUTOPRINT_LABEL' => ['Auto-print the shipping label', self::YN, 'N', ''],
                'WH_AUTOPRINT_MANIFEST' => ['Auto-print handover manifest', self::YN, 'Y', ''],
                'WH_BARCODE_TYPE' => ['Barcode type', 'enum', 'CODE128', '', $o(['CODE128' => 'Code 128', 'QR' => 'QR', 'EAN13' => 'EAN-13'])],
                'WH_BIN_LABEL_QR' => ['QR code on bin labels', self::YN, 'N', ''],
            ],
            'Calendar' => [
                'WH_WEEKEND_DAYS' => ['Weekend days', 'weekdays', '6,7', '1 = Monday … 7 = Sunday. Bank holidays per country are on the calendar page.'],
            ],
        ];
    }

    public static function defs(): array { return array_merge(...array_values(self::tabs())); }

    private static function value(string $key, int $ent, array $d): string
    {
        $v = Settings::get($key, $ent, null);
        return $v === null || $v === '' ? (string) $d[2] : (string) $v;
    }

    public static function page(int $ent): array
    {
        $tabs = [];
        foreach (self::tabs() as $tab => $defs) {
            $rows = [];
            foreach ($defs as $k => $d) $rows[] = ['key' => $k, 'label' => $d[0], 'type' => $d[1], 'default' => $d[2], 'help' => $d[3], 'value' => self::value($k, $ent, $d),
                'options' => isset($d[4]['options']) ? array_map(fn ($v, $l) => ['value' => (string) $v, 'label' => $l], array_keys($d[4]['options']), $d[4]['options']) : null,
                'min' => $d[4]['min'] ?? null, 'max' => $d[1] === 'text' ? null : ($d[4]['max'] ?? null), 'optional' => ! empty($d[4]['optional'])];
            $tabs[] = ['name' => $tab, 'rows' => $rows];
        }
        return ['tabs' => $tabs, 'loc_preview' => LocationGenerator::preview($ent) /* R-H-4 live preview */, 'history' => TypedSettings::history($ent, array_keys(self::defs()), 'WAREHOUSE_SETTING'),
            'profiles' => [['label' => 'Channels (per marketplace profile)', 'url' => '#/list/mp_profile'], ['label' => 'Carriers (per carrier profile)', 'url' => '#/list/t_carriers'], ['label' => 'Bank holidays', 'url' => '#/list/non_working_day'], ['label' => 'Printers and routes', 'url' => '#/settings/printer'], ['label' => 'Roles and rights', 'url' => '#/permissions']]];
    }

    public static function save(int $ent, string $key, $value): array
    {
        $d = self::defs()[$key] ?? throw new \InvalidArgumentException('Unknown warehouse setting.');
        [$err, $v] = TypedSettings::check($ent, $key, $d, $value);
        if ($err) throw new ValidationErrors(['value' => $err]);
        if ($key === 'WH_ABC_A_PCT' || $key === 'WH_ABC_B_PCT') {      // A + B ≤ 100
            $a = $key === 'WH_ABC_A_PCT' ? (float) $v : (float) self::value('WH_ABC_A_PCT', $ent, self::defs()['WH_ABC_A_PCT']); $b = $key === 'WH_ABC_B_PCT' ? (float) $v : (float) self::value('WH_ABC_B_PCT', $ent, self::defs()['WH_ABC_B_PCT']);
            if ($a + $b > 100) throw new ValidationErrors(['value' => 'A % + B % cannot be more than 100']);
        }
        $r = TypedSettings::store($ent, $key, $d[0], self::value($key, $ent, $d), $v, 'WAREHOUSE_SETTING');
        if (in_array($key, ['WH_UNASSIGNED_SELLABLE', 'WH_RECEIVING_SELLABLE'], true)) WarehouseSites::syncSellable($ent);      // R-H-3 the system bins follow the setting
        if ($key === 'WH_SEGREGATED_OWNERS') StockService::syncSegAll($ent);      // R-H-11 their units get (or lose) their own physical rows
        return $r;
    }
}

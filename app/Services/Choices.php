<?php

namespace App\Services;

/**
 * Choices: business decisions where more than one way is built into the tool. Every option stays available;
 * the recommended one is the default. The Owner picks per company on Settings › Choices. Each choice is one setting.
 */
class Choices
{
    /** key → [question, why it matters, default, [value => [label, what it does]]] */
    public const LIST = [
        'PROFIT_SHARE_AFTER_OVERHEADS' => ['Partners share the profit of a shop …', 'Overheads are expenses that belong to no shop. They are split between the shops by their confirmed sales of the month.', '1', [
            '1' => ['after its share of the overheads', 'A partner earns on what is really left. This is what the profit rules of the specification say.'],
            '0' => ['before overheads', 'A partner earns on the shop\'s own profit; the company carries all overheads alone.']]],
        'CLOSE_FIRST_FILE_PARTIAL' => ['When a shop\'s very first money file starts in the middle of a month …', 'Month close wants the marketplace\'s money file for the whole month.', '1', [
            '1' => ['that first month counts from the file\'s first day', 'The first month can be locked. Later months must be covered completely.'],
            '0' => ['the whole month must be covered', 'Strict: the first month stays open until a file covering it from day 1 is loaded.']]],
        'LATE_LINES_LOCKED_MONTH' => ['A sale line dated in a month that is already locked …', 'A locked month never changes.', 'FORWARD', [
            'FORWARD' => ['is confirmed and booked today, in the open month', 'Nothing waits; the locked month\'s figures stay as they were.'],
            'REFUSE' => ['is refused until someone decides', 'The line stays pending; the Owner sees a task and decides what to do with it.']]],
        'WH_UNSHIPPED_AT_CONFIRM' => ['At Month confirm, own-stock lines that were not shipped through the tool …', 'Confirm takes the units out of stock.', 'ASK', [
            'ASK' => ['stop and ask', 'The person confirming sees the lines and chooses "book as shipped now".'],
            'AUTO' => ['are booked as shipped by themselves', 'Faster; use it when dispatch is not done in the tool.']]],
        'PRINT_FAILOVER' => ['When the printer of a job is not ready …', 'Every job kind can have a backup printer.', 'ASK', [
            'ASK' => ['ask before using the backup printer', 'The person printing decides.'],
            'AUTO' => ['use the backup printer by itself', 'No stop at the packing desk.'],
            'OFF' => ['fail the job', 'Nothing prints anywhere else; a task is opened.']]],
    ];

    public static function get(string $key, int $ent): string
    {
        return (string) (Settings::get($key, $ent, null) ?? self::LIST[$key][2]);
    }

    public static function all(int $ent): array
    {
        $out = [];
        foreach (self::LIST as $key => [$q, $why, $def, $opts]) {
            $cur = self::get($key, $ent); if (! isset($opts[$cur])) $cur = $def;
            $out[] = ['key' => $key, 'question' => $q, 'why' => $why, 'value' => $cur, 'recommended' => $def,
                'options' => array_map(fn ($v, $o) => ['value' => (string) $v, 'label' => $o[0], 'effect' => $o[1], 'recommended' => (string) $v === $def], array_keys($opts), $opts)];
        }
        return $out;
    }

    public static function set(string $key, string $value, int $ent): void
    {
        if (! isset(self::LIST[$key])) throw new \InvalidArgumentException('Unknown choice.');
        if (! isset(self::LIST[$key][3][$value])) throw new \InvalidArgumentException('Choose one of the options shown.');
        $old = self::get($key, $ent);
        Settings::set($key, $value, $ent, Context::userId());
        Audit::log('sys_settings', null, 'UPDATE', ['choice' => $key, 'from' => $old, 'to' => $value], null, $ent);
    }
}

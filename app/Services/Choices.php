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
        'DEFAULT_PROFIT_ROUTE' => ['How is the profit of a shop paid out when the shop has no route of its own?', 'Every shop × month has exactly one route, so nobody is paid twice for the same units. A shop can get its own route from a chosen month (Partners › Profit routes).', 'LOT_PROFIT', [
            'LOT_PROFIT' => ['Lot profit', 'Lot owners earn the profit of the units sold from their own lots, then the agreements share the rest.'],
            'INVESTOR_INVOICING' => ['Investor invoicing', 'The four-way split rule: investor % to the lot owners by the cost of their units sold, plus owner, VA and handler %.']]],
        'LOT_OWNER_SHARE' => ['Route "lot profit": how much of the profit of his own lots\' units does a lot owner get?', 'The rest of that profit goes on down the waterfall (agreements, then the company). Applies to months locked from now on.', '100', [
            '100' => ['100 %', 'All the profit of the units sold from his own lots (after tax reserve and VA / handler shares).'], 'AGREEMENT' => ['The % of his own agreement', 'His PO agreement % for lots of that PO, else his pool / shop agreement %, else 100 %.'],
            '90' => ['90 %', ''], '80' => ['80 %', ''], '75' => ['75 %', ''], '70' => ['70 %', ''], '60' => ['60 %', ''], '50' => ['50 %', ''], '40' => ['40 %', ''], '30' => ['30 %', ''], '25' => ['25 %', ''], '20' => ['20 %', ''], '10' => ['10 %', '']]],
        'TEMU_SALE_VALUE' => ['Temu order list: which price is the sale value of a line?', 'Temu pays the seller its base price; the buyer pays the retail price (with Temu\'s own margin and VAT). The money file later settles what was really paid.', 'BASE', [
            'BASE' => ['Temu\'s base price (what the seller is paid)', 'Uses "activity goods base price", else "goods base price", × quantity.'], 'RETAIL' => ['Retail price the buyer paid', 'Uses "retail price total" of the line.']]],
        'MENU_NAMES' => ['Which names does the menu show?', 'Only the words in the menu change; every screen stays where it is.', 'SIMPLE', [
            'SIMPLE' => ['Short names', 'For example "Owner Home" – the part in brackets is left out.'], 'ORIGINAL' => ['Full names of the specification', 'For example "Owner Home ("Command Center")".']]],
        'VAULT_REVEAL' => ['Who may reveal a secret (passwords, memorable answers, full account numbers)?', 'Every reveal and every refusal is written to the audit log with the user, the record and the field – never the value.', 'OWNER', [
            'OWNER' => ['Owner only', 'Everybody else sees ••••; the Owner opens a value when it is needed.'],
            'OWNER_MANAGER' => ['Owner and Manager', 'A Manager who may open the record may also reveal its secrets.']]],
        'STATEMENT_SEND' => ['How do partners get their statement when a month is locked?', 'WhatsApp and / or e-mail to the partner\'s own number and address in People & companies. Nothing goes out until a WhatsApp connection or an e-mail server is set up – it waits and leaves then.', 'AUTO', [
            'AUTO' => ['Sent by themselves at the lock', 'Every partner gets the new statement as soon as the month is locked (and every new version).'],
            'BUTTON' => ['Only when someone presses Send', 'The statement waits on the partner page until the Owner or Finance sends it.']]],
        'TASK_DIGEST' => ['Morning WhatsApp of late and urgent tasks?', 'One message a day per person who has late or urgent tasks, to the WhatsApp number of the person.', 'WHATSAPP', [
            'WHATSAPP' => ['Yes, by WhatsApp', 'Each morning, only to people with something late or urgent.'],
            'OFF' => ['No', 'People see their tasks only in the tool.']]],
        'VAT_REGISTERED' => ['Is the company registered for VAT?', 'This is a fact about the company, not a preference. It decides where the VAT inside fees and expenses goes.', '0', [
            '0' => ['No', 'VAT paid on fees and expenses is part of the cost.'],
            '1' => ['Yes', 'VAT on marketplace fees and on expenses is kept apart as VAT to claim back (account 1400) and shown in the VAT report.']], true],
    ];

    public static function get(string $key, ?int $ent): string   // no company yet (first sign-in after install): the global value or the default
    {
        return (string) (Settings::get($key, $ent, null) ?? self::LIST[$key][2]);
    }

    public static function all(int $ent): array
    {
        $out = [];
        foreach (self::LIST as $key => $row) {
            [$q, $why, $def, $opts] = $row; $fact = ! empty($row[4]);
            $cur = self::get($key, $ent); if (! isset($opts[$cur])) $cur = $def;
            $out[] = ['key' => $key, 'question' => $q, 'why' => $why, 'value' => $cur, 'recommended' => $fact ? null : $def,
                'options' => array_map(fn ($v, $o) => ['value' => (string) $v, 'label' => $o[0], 'effect' => $o[1], 'recommended' => ! $fact && (string) $v === $def], array_keys($opts), $opts), 'fact' => $fact];
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

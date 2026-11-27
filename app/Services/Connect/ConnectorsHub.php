<?php

namespace App\Services\Connect;

use Illuminate\Support\Facades\DB;

/**
 * Owner 27-11 09:26: one place with every connector the business can use – Google (Drive, Sheets, Gmail, Calendar), Microsoft, marketplaces,
 * money services, messaging, carriers and printing – each with its logo, what it does here, how it connects and its state now:
 *   CONNECTED      a connection of it is on and has its keys
 *   READY          the code is in the tool – press Connect, sign in / paste the keys (the owner does it himself; keys go to the vault)
 *   BY_FILE        works today by file (upload / Drive folder); a direct connection is to be built
 *   TO_BUILD       not in the tool yet – it is on the plan
 * A new connector is one line here (or a new adapter in Connections::ADAPTERS, which shows by itself).
 */
class ConnectorsHub
{
    /** code => [name, group, adapters that serve it, how it connects, what it does here, by-file screen] */
    public const CATALOGUE = [
        'gdrive' => ['Google Drive', 'Google', ['GOOGLE_DRIVE'], 'Service account key (owner pastes it once)', 'Watched folders: reports dropped in a folder are read by themselves; files filed to records', '#/drive'],
        'gsheets' => ['Google Sheets', 'Google', [], 'Google sign-in (Allow)', 'Read a sheet as an import, keep a sheet up to date from a report', '#/import'],
        'gmail' => ['Gmail', 'Google', [], 'Google sign-in (Allow)', 'Invoices and files sent to a mailbox go to Import anything / "to place"', '#/import'],
        'gcal' => ['Google Calendar', 'Google', [], 'Google sign-in (Allow)', 'Due dates, shipments and tasks on your calendar', null],
        'onedrive' => ['OneDrive', 'Microsoft', [], 'Microsoft sign-in (Allow)', 'Same as Google Drive, for OneDrive folders', '#/import'],
        'excel' => ['Excel', 'Microsoft', [], 'Microsoft sign-in (Allow)', 'Excel files open and save straight from OneDrive', '#/import'],
        'outlook' => ['Outlook', 'Microsoft', [], 'Microsoft sign-in (Allow)', 'Mail attachments to Import anything', '#/import'],
        'amazon' => ['Amazon', 'Marketplaces', ['AMAZON_SPAPI'], 'Seller Central app keys (owner)', 'Orders, settlements, FBA stock and listings', '#/import'],
        'ebay' => ['eBay', 'Marketplaces', ['EBAY_API'], 'eBay developer keys (owner)', 'Orders, payouts and listings', '#/import'],
        'tiktok' => ['TikTok Shop', 'Marketplaces', ['TIKTOK_SHOP_API'], 'TikTok Shop app keys (owner)', 'Orders and settlements', '#/import'],
        'onbuy' => ['OnBuy', 'Marketplaces', ['ONBUY_API'], 'OnBuy API keys (owner)', 'Orders', '#/import'],
        'temu' => ['Temu', 'Marketplaces', ['TEMU_API'], 'Temu partner keys (owner)', 'Orders', '#/import'],
        'etsy' => ['Etsy', 'Marketplaces', [], 'Etsy sign-in (Allow)', 'Orders and monthly statements', '#/import'],
        'vinted' => ['Vinted', 'Marketplaces', [], 'No public API – by file', 'Sales by file', '#/import'],
        'shopify' => ['Shopify', 'Marketplaces', [], 'Shopify app (Allow)', 'Orders and payouts of an own web shop', '#/import'],
        'wise' => ['Wise', 'Money', [], 'Wise API token (owner)', 'Statements, the real rate of each transfer, balances', '#/import'],
        'worldfirst' => ['WorldFirst', 'Money', [], 'WorldFirst sign-in', 'Statements and the rate of each payment to suppliers', '#/import'],
        'paypal' => ['PayPal', 'Money', [], 'PayPal app keys (owner)', 'Transactions and fees', '#/import'],
        'banks' => ['Bank statements', 'Money', [], 'Bank file (CSV / OFX)', 'Bank lines matched to sales, payouts and expenses', '#/bank'],
        'ecb' => ['Exchange rates (ECB)', 'Money', ['ECB_RATES'], 'Nothing to set – public rates', 'CNY, USD, EUR … rates every working day', '#/fx'],
        'whatsapp' => ['WhatsApp', 'Messaging', ['WHATSAPP_CLOUD', 'WHATSAPP_360'], 'Business number + Meta keys (owner, with the developer)', 'Alerts out, replies and files in, chat on records', '#/contacts'],
        'wechat' => ['WeChat', 'Messaging', [], 'WeChat Official Account (owner)', 'Chat with Chinese suppliers kept on their record', '#/contacts'],
        'email' => ['E-mail (SMTP)', 'Messaging', ['SMTP'], 'Mail server settings (owner, on the server)', 'Mails the tool sends: digests, POs, statements', null],
        'royalmail' => ['Royal Mail', 'Carriers', ['ROYALMAIL_CLICKDROP', 'ROYALMAIL_TRACKING'], 'Click & Drop API key (owner)', 'Labels and tracking', '#/tracking-upload'],
        'carriers' => ['Other carriers', 'Carriers', ['TRACK_LINK'], 'Nothing to set', 'Every tracking number opens the carrier\'s own page', null],
        'printnode' => ['PrintNode', 'Printing', ['PRINTNODE'], 'PrintNode API key (owner)', 'One-click printing to any printer', '#/settings/printer'],
        'printagent' => ['Print helper', 'Printing', ['PRINT_AGENT'], 'Helper on the packing PC', 'Labels straight to the packing printer', '#/settings/printer'],
        'mytool' => ['MY TOOL', 'Own tools', ['MYTOOL'], 'Link key (owner pastes it)', 'Orders and products both ways with MY TOOL', '#/mytool-link'],
    ];

    public static function list(int $ent): array
    {
        $rows = DB::table('api_connections')->whereIn('fk_id_ent', [0, $ent])->whereNotNull('adapter')->get(['id', 'adapter', 'status', 'label', 'last_sync_at', 'last_error']);
        $out = [];
        foreach (self::CATALOGUE as $code => [$name, $group, $adapters, $how, $does, $file]) {
            $mine = $rows->whereIn('adapter', $adapters); $on = $mine->filter(fn ($c) => $c->status !== 'OFF');
            $built = array_values(array_filter($adapters, fn ($a) => isset(Connections::ADAPTERS[$a])));
            $own = $adapters && ! $built;      // set up on its own page (MY TOOL link, mail server, live rates)
            $state = $on->count() || in_array($code, ['ecb', 'carriers'], true) || ($code === 'email' && ! in_array((string) config('mail.default'), ['log', 'array', ''], true)) ? 'CONNECTED'
                : ($built ? 'READY' : ($own ? 'OWN_PAGE' : ($file ? 'BY_FILE' : 'TO_BUILD')));
            $out[] = ['code' => $code, 'name' => $name, 'group' => $group, 'how' => $how, 'does' => $does, 'file' => $file, 'adapters' => $built, 'state' => $state,
                'connections' => $mine->map(fn ($c) => ['id' => (int) $c->id, 'label' => $c->label, 'status' => $c->status, 'last' => $c->last_sync_at, 'error' => $c->last_error])->values()->all()];
        }
        return $out;
    }
}

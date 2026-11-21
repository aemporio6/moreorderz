<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Permission engine. One question everywhere: may this person do this action?
 *
 * A right has a code:
 *   <area>.<NAME>            an action group of a screen controller (its constant holds the default roles), e.g. stock.CHANGE
 *   form:<form_key>:<ACTION> VIEW / ADD / EDIT / DELETE / EXPORT of a registry form (default: the role's menu screens)
 *   menu:<module_key>        a menu item (default: the roles written on the menu row)
 * Answer = default of the built-in role  →  changed by the role's own rows (mo_role_perms)  →  changed by the person's rows (mo_user_perms).
 * A made role (mo_roles) starts with the defaults of the built-in role it copies ("like_role"); the user keeps that built-in role in role_code
 * and the made role in custom_role, so every older check still works. The Owner role can never lose a right.
 */
class Perm
{
    /** Plain labels of the screen rights (the generated catalogue only knows the code name). */
    public const LABELS = [
        'admin.accounts' => 'See the accounts dashboard (people, companies, bank accounts, cards)',
        'admin.audit' => 'See the audit trail',
        'admin.choices' => 'See the Choices (settings)',
        'admin.choices_owner' => 'See owner-only Choices',
        'admin.deleteView_owner' => 'Delete a saved view of another person',
        'admin.endSession' => 'Sign another person out',
        'admin.importHistory' => 'See the import history',
        'admin.ledger' => 'See the ledger',
        'admin.mergeDismiss' => 'Dismiss a duplicate suggestion',
        'admin.mergeDo' => 'Merge duplicates',
        'admin.mergeUndo' => 'Undo a merge',
        'admin.merges' => 'See duplicate suggestions',
        'admin.owner' => 'Owner-only setup (companies, health, backups)',
        'admin.registry' => 'See the field registry',
        'admin.saveChoice' => 'Change a Choice (setting)',
        'admin.saveView_may' => 'Save a list view for everyone',
        'admin.sessions' => 'See who is signed in',
        'admin.sessions_owner' => 'See all sessions with device details',
        'admin.users' => 'Add and change users',
        'admin.views_may' => 'Manage shared list views',
        'app.company_owner' => 'Switch company (see every company)',
        'buying.BUY' => 'Raise and change purchase orders',
        'buying.COST' => 'Enter landed costs',
        'buying.MONEY' => 'See buying prices and totals',
        'buying.PAY' => 'Pay suppliers',
        'buying.RECEIVE' => 'Receive goods',
        'buying.SEE' => 'See buying screens',
        'buying.fx' => 'Enter exchange rates',
        'close.RUN' => 'Run the month-close checks',
        'close.SEE' => 'See the month close',
        'close.lock' => 'Lock or reopen a month',
        'close.rights_owner' => 'Owner steps of the month close',
        'close.waive' => 'Waive a month-close check',
        'connect.buyLabels' => 'Buy shipping labels',
        'connect.connections' => 'See and change connections',
        'connect.drive' => 'See the Drive folders',
        'connect.driveCheck' => 'Check a Drive folder',
        'connect.driveLink' => 'Link a Drive folder',
        'connect.driveLoad' => 'Load files from a Drive folder',
        'connect.driveMake' => 'Make Drive folders',
        'connect.driveSetup' => 'Set up the Drive link',
        'connect.hubs' => 'See the carrier hubs',
        'connect.messages' => 'See the message log',
        'connect.pull' => 'Pull orders from a connection now',
        'connect.retryMessage' => 'Send a failed message again',
        'connect.sendNow' => 'Send a message now',
        'connect.sendStatement' => 'Send a statement',
        'connect.subscriptions' => 'See subscriptions and renewals',
        'connect.switchConnection' => 'Switch a connection on or off',
        'connect.testConnection' => 'Test a connection',
        'connect.track' => 'Track a parcel',
        'finance.MONEY' => 'See VAT, loans and budgets',
        'finance.calls' => 'See payment calls',
        'finance.questions' => 'Answer statement questions',
        'money.EDIT' => 'Add and change bank entries',
        'money.EXPENSE' => 'Enter expenses',
        'money.SEE' => 'See money screens',
        'money.forecast' => 'See the cash forecast',
        'money.refundDecide' => 'Approve or refuse a refund',
        'money.refundManual' => 'Enter a refund by hand',
        'money.refunds_may' => 'Decide refunds on the refunds list',
        'orders.CONFIRM' => 'Confirm the month of orders',
        'orders.ENTER' => 'Enter and change orders',
        'orders.MONEY' => 'See order prices and profit',
        'orders.SEE' => 'See orders',
        'orders.WAREHOUSE' => 'Pick, pack and dispatch',
        'orders.labelFile' => 'Download a label file',
        'orders.labels' => 'See labels of orders',
        'orders.saveTrackingLayout' => 'Save a tracking file layout',
        'orders.trackingUpload' => 'Upload tracking numbers',
        'partners.EDIT' => 'Add and change partners',
        'products.investors' => 'Set product investors (company parties) and map old investor ids',
        'products.hide' => 'Hide (archive) and restore products',
        'products.delete' => 'Delete products to the recycle bin',
        'products.marketplaceFields' => 'Build the listing fields per marketplace',
        'products.marketplaceValues' => 'Fill the marketplace fields of a product',
        'partners.MONEY' => 'See partner money',
        'partners.SEE' => 'See partners',
        'partners.show_may' => 'See any partner (not only himself)',
        'partners.viewAs' => 'View My Partner Account as a partner (read-only, audited)',
        'partners.waterfall' => 'See the profit share',
        'partners.waterfall_owner' => 'Run and post the profit share',
        'partners.withdraw' => 'Enter a partner withdrawal',
        'print.QUEUE' => 'Use the print queue',
        'print.SETUP' => 'Set up printers',
        'print.status' => 'See printer status',
        'print.test' => 'Send a test page',
        'print.testResult' => 'Record a test page result',
        'stock.CHANGE' => 'Move, count and change stock',
        'stock.MONEY' => 'See stock values',
        'stock.SEE' => 'See stock',
        'stock.adjust_owner' => 'Post a big stock adjustment',
        'stock.bins' => 'Set up bins and locations',
        'stock.check' => 'Run the stock check',
        'stock.homes' => 'Set the home bin of a product',
        'stock.lotCost' => 'Change the cost of a lot',
        'stock.lots_may' => 'See lot costs',
        'stock.opening' => 'Enter opening stock',
        'stock.ownership' => 'Change who owns stock',
        'stock.prices' => 'See selling prices',
        'stock.setPrice' => 'Change a selling price',
        'stock.variations' => 'Add and change the variations (children) of a product',
        'work.CAN_EXPORT' => 'Export lists',
        'work.taskStatus_may' => 'Change the status of any task',
        'work.tasks_may' => 'See the tasks of everyone',
    ];
    public const ACTIONS = ['VIEW' => 'See', 'ADD' => 'Add', 'EDIT' => 'Change', 'DELETE' => 'Delete', 'EXPORT' => 'Export'];

    /** The names of the rights list in the sales rules (R-A_SALES-82) → the codes this tool checks. Old names (tiktok.*) stay as aliases. */
    public const ALIASES = [
        'sale.import' => 'orders.saleImport', 'sale.edit' => 'orders.saleEdit', 'sale.carrier' => 'orders.saleCarrier', 'sale.refund' => 'orders.saleRefund', 'sale.delete' => 'orders.saleDelete', 'sale.delete_month' => 'orders.saleDeleteMonth',
        'sale.confirm' => 'orders.CONFIRM', 'sale.unlock' => 'orders.saleUnlock', 'sale.see_cost' => 'orders.MONEY', 'sale.see_investor' => 'orders.saleSeeInvestor', 'sale.export' => 'orders.saleExport',
        'ds.rematch' => 'orders.dsRematch', 'ds.link' => 'orders.dsLink', 'ds.see_contact' => 'orders.dsSeeContact',
        'case.create' => 'orders.saleCase', 'case.remark' => 'orders.saleCaseAdmin', 'case.resolve' => 'orders.saleCase',
        'health.edit_own' => 'orders.healthOwn', 'health.edit_any' => 'orders.healthAny',
        'daily_order.add' => 'orders.fulfilAdd', 'daily_order.status' => 'orders.fulfilManage', 'daily_order.bulk' => 'orders.fulfilManage', 'tiktok.add' => 'orders.fulfilAdd', 'tiktok.status' => 'orders.fulfilManage', 'tiktok.bulk' => 'orders.fulfilManage',
        'audit.run' => 'orders.salesAudit', 'report.sales' => 'orders.saleReports', 'va_statement.create' => 'orders.vaStatement', 'va_statement.approve' => 'orders.vaStatementApprove', 'setup.marketplace' => 'setup.marketplaces', 'setup.carrier' => 'setup.carriers',
    ];
    public static function code(string $code): string { return self::ALIASES[$code] ?? $code; }

    private static array $cat = [];
    private static array $extraRoles = [];
    private static array $rolePerms = [];
    private static array $userPerms = [];
    private static array $roles = [];
    /** "View as": the Owner looks at the tool as another role (read-only). [base role, role key] or null. */
    private static ?array $viewAs = null;
    private static ?array $fieldRules = null;

    public static function flush(): void { self::$cat = self::$rolePerms = self::$userPerms = self::$roles = self::$extraRoles = []; self::$viewAs = null; self::$fieldRules = null; Rights::flush(); }

    public static function viewAs(?string $base, ?string $key = null): void { self::$viewAs = $base ? [$base, $key ?: $base] : null; }
    public static function viewing(): ?array { return self::$viewAs; }

    /** Every right that exists: code => [label, group, default roles]. Screen rights come from the generated catalogue, report rights from the report list. */
    public const PRINT_KINDS = [
        'PACKING_SLIP' => ['OWNER', 'MANAGER', 'WAREHOUSE', 'TL'], 'PICK_SHEET' => ['OWNER', 'MANAGER', 'WAREHOUSE', 'TL'], 'MANIFEST' => ['OWNER', 'MANAGER', 'WAREHOUSE', 'TL'],
        'LOCATION' => ['OWNER', 'MANAGER', 'WAREHOUSE'], 'PO' => ['OWNER', 'MANAGER', 'SOURCING', 'FINANCE', 'ACCOUNTANT'], 'CARTON_MARK' => ['OWNER', 'MANAGER', 'SOURCING', 'WAREHOUSE'],
    ];

    public static function catalogue(): array
    {
        if (self::$cat) return self::$cat;
        $c = PermCatalogue::ALL;
        foreach (self::LABELS as $k => $l) if (isset($c[$k])) $c[$k][0] = $l;
        foreach (ReportService::LIST as $k => $r) $c["report:$k"] = [$r[1], 'Reports', $r[3]];
        // rights that are not one-per-screen-action
        $c['money.approve'] = ['Approve or reject waiting money items', 'Money', Approvals::APPROVERS];
        $c['stock.countApprove'] = ['Approve and post a stock count', 'Stock', ['OWNER', 'MANAGER']];
        $c['print.testPage'] = ['Print a printer test page', 'Printing', ['OWNER', 'MANAGER', 'WAREHOUSE']];
        foreach (self::PRINT_KINDS as $k => $roles) $c["print.kind_$k"] = ['Print: ' . ucfirst(strtolower(str_replace('_', ' ', $k))), 'Printing', $roles];
        $c['admin.permissions'] = ['Change roles and rights', 'Users and system', ['OWNER']];
        $c['orders.saleEdit'] = ['Change a sale line (order no, customer, quantity, price)', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.saleCarrier'] = ['Change the carrier and packing of a sale line', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.saleRefund'] = ['Refund a sale line with the nature of the loss', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.saleDelete'] = ['Delete a sale line (to the recycle bin)', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.saleDeleteMonth'] = ['Delete a whole month of sales of a shop', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL']];
        $c['orders.dsRematch'] = ['Run Re-match of not matched lines against drop shipping orders', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.dsLink'] = ['Link or unlink a drop shipping item by hand (with a reason)', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL']];
        $c['orders.dsSeeContact'] = ['See the address and phone of a drop shipping order unmasked', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL']];
        $c['orders.dsUpload'] = ['Upload a drop shipping supplier-orders file for a shop and month', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.dsUndo'] = ['Undo (delete) a drop shipping upload', 'Orders and dispatch', ['OWNER']];
        $c['orders.dsPlatformAdd'] = ['Add a supplier platform directly (others propose it)', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL']];
        $c['orders.saleSeeInvestor'] = ['See the investors of a sale line', 'Orders and dispatch', User::ROLES];
        $c['orders.salesAudit'] = ['Run the sales audits (stock, price, fee and profit checks)', 'Orders and dispatch', ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT']];
        $c['orders.csvConvert'] = ['Convert a CSV file to the standard sheet (download or into the fulfilment queue)', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.csvFormats'] = ['Map CSV columns and save convert formats', 'Orders and dispatch', ['OWNER', 'MANAGER']];
        $c['orders.importUpdate'] = ['Update existing sale lines from a file (only the mapped columns)', 'Orders and dispatch', ['OWNER', 'MANAGER']];
        $c['orders.hubFigures'] = ['Type the marketplace hub figures of a shop and month (fee buckets)', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'FINANCE']];
        $c['orders.healthOwn'] = ['Fill in the monthly account health of the shops where you are the seller or the owner', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.healthAny'] = ['See the account health of every VA and shop, and enter it for a VA', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL']];
        $c['orders.saleCase'] = ['Raise a case on a sale, write in its thread, resolve, reopen and remove it', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.saleCaseAdmin'] = ['Answer cases with admin remarks, quick-add a general case, see general cases', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL']];
        $c['orders.fulfilSee'] = ['See the daily fulfilment orders (a seller sees the ones he added)', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.fulfilAdd'] = ['Add a daily fulfilment order', 'Orders and dispatch', ['VA']];
        $c['orders.fulfilManage'] = ['Fulfilment orders: set status, carrier service and tracking, bulk change and delete, see every seller', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL']];
        $c['orders.fulfilExport'] = ['Export fulfilment orders as the carrier file', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.shopReminders'] = ['Add, resolve and remove shop monthly reminders', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.shopReminderTemplates'] = ['Set the monthly reminder templates of the shops', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL']];
        $c['setup.marketplaces'] = ['Set marketplaces and their questions', 'Setup', ['OWNER', 'MANAGER']];
        $c['setup.accHub'] = ['Set the Accountancy Hub values of a company (year end, VAT, accountant, confirmation statement)', 'Setup', ['OWNER', 'MANAGER', 'FINANCE']];
        $c['setup.carriers'] = ['Set carriers, packages, services, presets, packings and their rates', 'Setup', ['OWNER', 'MANAGER']];
        $c['orders.vaStatement'] = ['Make the VA statement of a shop and month', 'Orders and dispatch', ['OWNER', 'MANAGER', 'FINANCE', 'TL']];
        $c['orders.vaStatementApprove'] = ['Approve or send back a VA statement', 'Orders and dispatch', ['OWNER']];
        $c['orders.saleReports'] = ['See the Annual and the Detailed sale report', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'FINANCE']];
        $c['orders.saleExport'] = ['Export the sales list (PDF, Word, Excel, CSV)', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'FINANCE']];
        $c['orders.saleBulkDelete'] = ['Delete the Pending sales of a shop between two dates (bulk)', 'Orders and dispatch', ['OWNER']];
        $c['orders.saleUnlock'] = ['Unlock a confirmed month of sales (with a reason)', 'Orders and dispatch', ['OWNER']];
        $c['orders.saleImport'] = ['Import the monthly sales file of a shop', 'Orders and dispatch', ['OWNER', 'MANAGER', 'TL', 'VA']];
        $c['orders.saleColumns'] = ['Set the Sale Columns of a marketplace (which CSV column is which field)', 'Orders and dispatch', ['OWNER', 'MANAGER']];
        return self::$cat = $c;
    }

    public static function label(string $code): string
    {
        $c = self::catalogue();
        if (isset($c[$code])) return $c[$code][1] . ' › ' . $c[$code][0];
        if (preg_match('/^form:([^:]+):(\w+)$/', $code, $m)) return (Registry::exists($m[1]) ? Registry::fields($m[1])[0]['form_title'] : $m[1]) . ' › ' . (self::ACTIONS[$m[2]] ?? $m[2]);
        if (str_starts_with($code, 'menu:')) return 'Menu › ' . (DB::table('sidebar_modules')->where('module_key', substr($code, 5))->value('module_name') ?: substr($code, 5));
        return $code;
    }

    /** [base role, role key] of a person: the key is the made role when he has one, else the built-in role. */
    public static function rolesOf(?object $u): array
    {
        if (! $u) return [null, null];
        if (self::$viewAs && ($u->role_code ?? null) === 'OWNER') return self::$viewAs;
        return [$u->role_code, ($u->custom_role ?? null) ?: $u->role_code];
    }

    private static function roleRows(string $key): array
    {
        return self::$rolePerms[$key] ??= DB::table('mo_role_perms')->where('role_code', $key)->pluck('allowed', 'perm_code')->all();
    }

    private static function userRows(int $id): array
    {
        return self::$userPerms[$id] ??= DB::table('mo_user_perms')->where('user_id', $id)->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>=', date('Y-m-d')))->pluck('effect', 'perm_code')->all();
    }

    /** Default of a built-in role for a right. */
    public static function byDefault(?string $base, string $code): bool
    {
        if (! $base) return false;
        if ($base === 'OWNER') return true;
        $c = self::catalogue();
        if (isset($c[$code])) return in_array($base, $c[$code][2], true);
        if (preg_match('/^form:([^:]+):(\w+)$/', $code, $m)) return Rights::defaultCan($base, $m[2], $m[1]);
        if (str_starts_with($code, 'menu:')) return Rights::sees(DB::table('sidebar_modules')->where('module_key', substr($code, 5))->value('roles'), $base);
        return false;
    }

    /** May a role (built-in or made) do it – before any personal change. */
    public static function roleCan(?string $base, ?string $key, string $code): bool
    {
        if (! $base) return false;
        if ($key === 'OWNER') return true;
        $rows = self::roleRows((string) $key);
        if (array_key_exists($code, $rows)) return (bool) $rows[$code];
        if (preg_match('/^form:([^:]+):(\w+)$/', $code, $m)) return Rights::defaultCan($base, $m[2], $m[1], (string) $key);
        return self::byDefault($base, $code);
    }

    public static function has(?object $u, string $code): bool
    {
        $code = self::code($code);
        [$base, $key] = self::rolesOf($u);
        if (! $base) return false;
        if ($key === 'OWNER') return true;
        if (! self::$viewAs || $u->role_code !== 'OWNER') {
            $mine = self::userRows((int) $u->id);
            if (isset($mine[$code])) return $mine[$code] === 'GRANTED';
        }
        if (self::roleCan($base, $key, $code)) return true;
        if (self::$viewAs && $u->role_code === 'OWNER') return false;
        foreach (self::extraRoles((int) $u->id) as $r) if (self::roleCan(self::baseOf($r), $r, $code)) return true;      // several roles = the union of their rights (never an exact role match)
        return false;
    }

    /** The extra roles of a person beside his main role (R-A_SALES-82). */
    public static function extraRoles(int $userId): array { return self::$extraRoles[$userId] ??= DB::table('mo_user_roles')->where('user_id', $userId)->orderBy('role_code')->pluck('role_code')->all(); }

    private static function baseOf(string $role): string { if (in_array($role, User::ROLES, true)) return $role; return (string) (DB::table('mo_roles')->where('role_code', $role)->value('like_role') ?: $role); }

    public static function setExtraRoles(int $userId, array $roles, int $by): array
    {
        $main = DB::table('t_users')->where('id', $userId)->first() ?? throw new \InvalidArgumentException('Person not found.'); $known = array_keys(self::roles()); $roles = array_values(array_unique(array_filter(array_map('strval', $roles))));
        foreach ($roles as $r) { if ($r === 'OWNER') throw new \InvalidArgumentException('Owner cannot be an extra role.'); if (! in_array($r, $known, true)) throw new \InvalidArgumentException("Unknown role $r."); }
        $roles = array_values(array_diff($roles, [$main->custom_role ?: $main->role_code]));
        DB::transaction(function () use ($userId, $roles, $by) { DB::table('mo_user_roles')->where('user_id', $userId)->delete(); foreach ($roles as $r) DB::table('mo_user_roles')->insert(['user_id' => $userId, 'role_code' => $r, 'created_by' => $by, 'created_at' => now()]); Audit::log('t_users', $userId, 'UPDATE', ['extra roles' => $roles]); });
        unset(self::$extraRoles[$userId]); return $roles;
    }

    /** Why the answer is what it is – shown to the person and to the Owner. */
    public static function explain(?object $u, string $code): string
    {
        $code = self::code($code); [$base, $key] = self::rolesOf($u); $what = self::label($code);
        if (! $base) return 'Not signed in.';
        $mine = self::userRows((int) $u->id);
        if (! isset($mine[$code]) && ! self::roleCan($base, $key, $code) && ! (self::$viewAs && $u->role_code === 'OWNER')) foreach (self::extraRoles((int) $u->id) as $r) if (self::roleCan(self::baseOf($r), $r, $code)) return "Allowed: your extra role $r has \"$what\".";
        if (isset($mine[$code])) return $mine[$code] === 'GRANTED' ? "Allowed: \"$what\" was given to you personally." : "Not allowed: \"$what\" was taken away from you personally. Ask the Owner.";
        $rows = self::roleRows((string) $key);
        if (array_key_exists($code, $rows)) return $rows[$code] ? "Allowed: the role $key has \"$what\"." : "Not allowed: the role $key had \"$what\" switched off. Ask the Owner.";
        return self::byDefault($base, $code) ? "Allowed: the role $key has \"$what\"." : "Not allowed: the role $key does not have \"$what\". Ask the Owner if you need it.";
    }

    /** Stop with a plain sentence that names the right, so the person can ask for exactly that. */
    public static function need(?object $u, string $code): void
    {
        $code = self::code($code); if (self::has($u, $code)) return;
        abort(response()->json(['message' => 'Your role cannot do this here: "' . self::label($code) . '". Ask the Owner if you need it.', 'perm' => $code, 'why' => self::explain($u, $code), 'can_request' => true], 403));
    }

    // ---------- roles ----------
    public static function roles(): array
    {
        if (self::$roles) return self::$roles;
        $out = [];
        foreach (User::ROLES as $r) $out[$r] = ['code' => $r, 'label' => strlen($r) <= 2 ? $r : ucfirst(strtolower($r)), 'like' => $r, 'system' => true, 'active' => true];
        foreach (DB::table('mo_roles')->orderBy('role_code')->get() as $r) $out[$r->role_code] = ['code' => $r->role_code, 'label' => $r->label, 'like' => $r->like_role, 'system' => false, 'active' => (bool) $r->f_active, 'notes' => $r->notes];
        return self::$roles = $out;
    }

    public static function saveRole(string $code, string $label, string $like, ?string $copyFrom, ?string $notes, int $by): string
    {
        $code = strtoupper(trim($code));
        if (! preg_match('/^[A-Z][A-Z0-9_]{1,29}$/', $code)) throw new \InvalidArgumentException('Role code: capital letters, digits and _ only, 2 to 30 characters (for example LISTING_VA).');
        if (in_array($code, User::ROLES, true)) throw new \InvalidArgumentException("$code is a built-in role. Choose another code.");
        if (! in_array($like, User::ROLES, true) || $like === 'OWNER') throw new \InvalidArgumentException('Choose the built-in role this role starts from (not Owner).');
        $new = ! DB::table('mo_roles')->where('role_code', $code)->exists();
        DB::transaction(function () use ($code, $label, $like, $copyFrom, $notes, $by, $new) {
            DB::table('mo_roles')->updateOrInsert(['role_code' => $code], ['label' => trim($label) ?: $code, 'like_role' => $like, 'notes' => $notes, 'f_active' => 1, 'updated_at' => now()] + ($new ? ['created_at' => now()] : []));
            if ($new && $copyFrom && $copyFrom !== $like) foreach (DB::table('mo_role_perms')->where('role_code', $copyFrom)->get() as $p)
                DB::table('mo_role_perms')->insert(['role_code' => $code, 'perm_code' => $p->perm_code, 'allowed' => $p->allowed, 'updated_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('t_users')->where('custom_role', $code)->update(['role_code' => $like]);
            Audit::log('mo_roles', 0, $new ? 'INSERT' : 'UPDATE', ['role' => $code, 'like' => $like, 'copied_from' => $copyFrom]);
        });
        self::flush();
        return $code;
    }

    /** Switch a right on or off for a role; "null" puts the role back on the default. */
    public static function setRole(string $key, string $code, ?bool $allowed, int $by): void
    {
        if ($key === 'OWNER') throw new \InvalidArgumentException('The Owner role always has every right.');
        if (! isset(self::roles()[$key])) throw new \InvalidArgumentException('Role not found.');
        if (! self::known($code)) throw new \InvalidArgumentException('This right does not exist.');
        $base = self::roles()[$key]['like']; $before = self::roleCan($base, $key, $code);
        if ($allowed === null || $allowed === self::byDefault($base, $code)) DB::table('mo_role_perms')->where(['role_code' => $key, 'perm_code' => $code])->delete();
        else DB::table('mo_role_perms')->updateOrInsert(['role_code' => $key, 'perm_code' => $code], ['allowed' => (int) $allowed, 'updated_by' => $by, 'updated_at' => now(), 'created_at' => now()]);
        self::flush();
        Audit::log('mo_role_perms', 0, 'UPDATE', ['role' => $key, 'right' => $code, 'from' => $before, 'to' => self::roleCan($base, $key, $code)]);
    }

    public static function setUser(int $userId, string $code, ?string $effect, string $reason, ?string $until, int $by): void
    {
        $u = DB::table('t_users')->where('id', $userId)->first();
        if (! $u) throw new \InvalidArgumentException('Person not found.');
        if ($u->role_code === 'OWNER' && ! ($u->custom_role ?? null)) throw new \InvalidArgumentException('An Owner always has every right.');
        if (! self::known($code)) throw new \InvalidArgumentException('This right does not exist.');
        if ($effect === null) DB::table('mo_user_perms')->where(['user_id' => $userId, 'perm_code' => $code])->delete();
        else {
            if (! in_array($effect, ['GRANTED', 'REVOKED'], true)) throw new \InvalidArgumentException('Choose Give or Take away.');
            if (trim($reason) === '') throw new \InvalidArgumentException('Write the reason – it is kept in the history.');
            DB::table('mo_user_perms')->updateOrInsert(['user_id' => $userId, 'perm_code' => $code], ['effect' => $effect, 'reason' => mb_substr(trim($reason), 0, 255), 'until' => $until ?: null, 'updated_by' => $by, 'updated_at' => now(), 'created_at' => now()]);
        }
        self::flush();
        Audit::log('mo_user_perms', $userId, 'UPDATE', ['user' => $u->login_id, 'right' => $code, 'effect' => $effect ?? 'back to the role', 'reason' => $reason, 'until' => $until]);
    }

    /** Rights that stay with the Owner for ever – nobody can hand out the key to the rights themselves. */
    public const OWNER_ONLY = ['admin.permissions', 'orders.saleUnlock', 'orders.saleBulkDelete'];

    public static function known(string $code): bool
    {
        if (in_array($code, self::OWNER_ONLY, true)) throw new \InvalidArgumentException($code === 'admin.permissions' ? 'Changing roles and rights stays with the Owner. It cannot be given to a role or a person.' : 'This right stays with the Owner. It cannot be given to a role or a person.');
        if (isset(self::catalogue()[$code])) return true;
        if (preg_match('/^form:([^:]+):(\w+)$/', $code, $m)) return Registry::exists($m[1]) && isset(self::ACTIONS[$m[2]]);
        return str_starts_with($code, 'menu:') && DB::table('sidebar_modules')->where('module_key', substr($code, 5))->exists();
    }

    // ---------- data scope ----------
    /** Shops a person is limited to; null = all shops of the company. */
    public static function shops(?object $u): ?array
    {
        if (! $u || ($u->role_code === 'OWNER' && ! self::$viewAs)) return null;
        $ids = DB::table('mo_user_scopes')->where('user_id', $u->id)->where('kind', 'SHOP')->pluck('ref_id')->map(fn ($x) => (int) $x)->all();
        return $ids ?: null;
    }

    public static function setScope(int $userId, string $kind, array $ids, int $by): void
    {
        if (! in_array($kind, ['SHOP', 'LOCATION', 'VENDOR'], true)) throw new \InvalidArgumentException('Unknown kind.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        DB::transaction(function () use ($userId, $kind, $ids, $by) {
            DB::table('mo_user_scopes')->where(['user_id' => $userId, 'kind' => $kind])->delete();
            foreach ($ids as $id) DB::table('mo_user_scopes')->insert(['user_id' => $userId, 'kind' => $kind, 'ref_id' => $id, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
            Audit::log('mo_user_scopes', $userId, 'UPDATE', ['kind' => $kind, 'ids' => $ids ?: 'all']);
        });
    }

    /** Narrow a query to the person's shops when the table has a shop column. */
    /** A request that names a shop outside the person's shops is refused (used for every screen, by the middleware). */
    public static function shopAllowed(?object $u, int $shopId): bool
    {
        $shops = self::shops($u);
        return $shops === null || $shopId <= 0 || in_array($shopId, $shops, true);
    }

    /** Narrow a screen query to the shops of the signed-in person ($col = the shop column). Nothing happens for a person with all shops, or outside a request. */
    /** Vendors whose inventory a person is limited to (the "Vendor inventory" view); null = no limit. */
    public static function vendors(?object $u): ?array
    {
        if (! $u || ($u->role_code === 'OWNER' && ! self::$viewAs)) return null;
        $ids = DB::table('mo_user_scopes')->where('user_id', $u->id)->where('kind', 'VENDOR')->pluck('ref_id')->map(fn ($x) => (int) $x)->all();
        return $ids ?: null;
    }

    public static function mine($q, string $col)
    {
        $shops = self::shops(\Illuminate\Support\Facades\Auth::user());
        return $shops === null ? $q : $q->whereIn($col, $shops);
    }

    public static function scopeQuery($q, string $table, ?object $u, string $alias = ''): void
    {
        $shops = self::shops($u); if ($shops === null) return;
        $col = $table === 't_branches' ? Registry::pk($table) : (Registry::hasColumn($table, 'fk_id_branch') ? 'fk_id_branch' : (Registry::hasColumn($table, 'fk_id_shop') ? 'fk_id_shop' : (Registry::hasColumn($table, 'branch_id') ? 'branch_id' : null)));
        if ($col) $q->whereIn(($alias ? "$alias." : '') . $col, $shops);
    }

    // ---------- field rules ----------
    /** EDIT / READONLY / HIDDEN for a field of a form, or null when no rule is set. A person's rule wins over the role's. */
    public static function fieldMode(?object $u, string $formKey, string $field): ?string
    {
        $all = self::$fieldRules ??= DB::table('mo_field_rules')->get()->groupBy('form_key')->all();
        if (! $u || empty($all[$formKey])) return null;
        [, $key] = self::rolesOf($u); if ($key === 'OWNER') return null;
        $role = null;
        foreach ($all[$formKey] as $r) {
            if ($r->field_name !== $field && $r->field_name !== '*') continue;
            if ($r->user_id && (int) $r->user_id === (int) $u->id) return $r->mode;
            if (! $r->user_id && $r->role_code === $key) $role = $r->mode;
        }
        return $role;
    }

    public static function setField(?string $role, ?int $userId, string $formKey, string $field, ?string $mode, int $by): void
    {
        if (! Registry::exists($formKey)) throw new \InvalidArgumentException('Form not found.');
        if (! $role && ! $userId) throw new \InvalidArgumentException('Choose a role or a person.');
        if ($role && (! isset(self::roles()[$role]) || $role === 'OWNER')) throw new \InvalidArgumentException('Role not found (the Owner always sees every field).');
        if ($field !== '*' && ! in_array($field, array_column(Registry::fields($formKey), 'field_name'), true)) throw new \InvalidArgumentException("The form has no field \"$field\". Look the name up in the field registry.");
        $w = ['form_key' => $formKey, 'field_name' => $field, 'role_code' => $userId ? null : $role, 'user_id' => $userId ?: null];
        DB::table('mo_field_rules')->where($w)->delete();
        if ($mode && $mode !== 'DEFAULT') {
            if (! in_array($mode, ['EDIT', 'READONLY', 'HIDDEN'], true)) throw new \InvalidArgumentException('Choose Edit, View only or Hidden.');
            DB::table('mo_field_rules')->insert($w + ['mode' => $mode, 'updated_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
        }
        self::flush();
        Audit::log('mo_field_rules', 0, 'UPDATE', $w + ['mode' => $mode ?: 'default']);
    }

    // ---------- access requests ----------
    public static function request(object $u, string $code, ?string $note): int
    {
        if (! self::known($code)) throw new \InvalidArgumentException('This right does not exist.');
        if (self::has($u, $code)) throw new \InvalidArgumentException('You already have this right.');
        $open = DB::table('mo_access_requests')->where(['user_id' => $u->id, 'perm_code' => $code, 'status' => 'OPEN'])->value('id');
        if ($open) return (int) $open;
        $id = (int) DB::table('mo_access_requests')->insertGetId(['fk_id_ent' => Context::ent(), 'user_id' => $u->id, 'perm_code' => $code, 'note' => $note ? mb_substr($note, 0, 255) : null, 'created_at' => now(), 'updated_at' => now()]);
        Audit::log('mo_access_requests', $id, 'INSERT', ['right' => $code]);
        Notify::toRight('admin.permissions', 'ACCESS', ($u->display_name ?: $u->login_id) . ' asks for: ' . self::label($code), '#/permissions', (int) $u->id);
        return $id;
    }

    public static function decide(int $id, bool $grant, ?string $note, ?string $until, object $by): void
    {
        $q = DB::table('mo_access_requests')->where('id', $id)->first();
        if (! $q || $q->status !== 'OPEN') throw new \InvalidArgumentException('This request is already decided.');
        if (! $grant && trim((string) $note) === '') throw new \InvalidArgumentException('Say why not – the person sees it.');
        DB::transaction(function () use ($q, $grant, $note, $until, $by) {
            if ($grant) self::setUser((int) $q->user_id, $q->perm_code, 'GRANTED', 'Asked for by the person' . ($note ? ": $note" : ''), $until, (int) $by->id);
            DB::table('mo_access_requests')->where('id', $q->id)->update(['status' => $grant ? 'GRANTED' : 'REFUSED', 'decided_by' => $by->id, 'decided_note' => $note, 'updated_at' => now()]);
            Notify::push((int) $q->user_id, 'ACCESS', ($grant ? 'Granted: ' : 'Refused: ') . self::label($q->perm_code) . ($note ? ' – ' . $note : ''), '#/home');
        });
    }
}

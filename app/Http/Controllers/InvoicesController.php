<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\InvestorDocs as Docs;
use App\Services\Perm;
use Illuminate\Http\Request;

/** R-D-83 / 84 investor documents: list, generate from a settled month, payments, cancel / credit note, reopen, e-mail, print. */
class InvoicesController extends Controller
{
    private function run(callable $fn) { try { $r = $fn(); return is_array($r) ? $r : ['message' => $r]; } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    private function ent(): int { return (int) Context::ent(); }

    /** R-ACC-1 … 9 compliance calendar. */
    public function compliance(Request $r) { Perm::need($r->user(), 'money.SEE'); return \App\Services\AccCompliance::page($this->ent(), $r->only(['company', 'tab', 'area', 'type', 'assigned', 'q', 'entity', 'from', 'to', 'ym', 'per', 'page'])); }
    public function complianceGenerate(Request $r) { Perm::need($r->user(), 'money.SEE'); $n = \App\Services\AccCompliance::generate($this->ent()); return ['added' => $n, 'message' => "$n new item(s)."]; }
    public function complianceUpdate(Request $r, int $id) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => \App\Services\AccCompliance::update($this->ent(), $id, $r->only(['status', 'submission_ref', 'notes', 'amount_due', 'amount_paid', 'fk_assigned', 'due_date', 'title', 'reason']), $r->user())); }
    public function complianceDocument(Request $r, int $id) { Perm::need($r->user(), 'money.EDIT'); if (! $r->hasFile('file')) return response()->json(['message' => 'Choose the file.'], 422); return $this->run(fn () => ['id' => \App\Services\AccCompliance::addDocument($this->ent(), $id, $r->file('file'), $r->user()), 'message' => 'Proof added.']); }
    /* R-ACC-11 / 14 / 15 / 19 */
    public function complianceTypes(Request $r) { Perm::need($r->user(), 'money.SEE'); return ['rows' => array_values(array_map(fn ($t) => (array) $t, \App\Services\AccFilings::types($this->ent()))), 'areas' => \App\Services\AccFilings::AREAS, 'authorities' => \App\Services\AccFilings::AUTHORITIES, 'completions' => \App\Services\AccFilings::COMPLETIONS]; }
    public function complianceTypeSave(Request $r, string $code) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => \App\Services\AccFilings::saveType($this->ent(), $code, $r->only(['type_name', 'area', 'authority', 'completion', 'sort_order', 'reminder_days', 'f_active']), $r->user())); }
    public function complianceCreate(Request $r) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => \App\Services\AccFilings::create($this->ent(), $r->all(), $r->user())); }
    public function complianceDelete(Request $r, int $id) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => \App\Services\AccFilings::delete($this->ent(), $id, $r->user())); }
    public function complianceCompanies(Request $r) { Perm::need($r->user(), 'money.SEE'); return \App\Services\AccFilings::companies($this->ent(), $r->only(['q', 'filter'])); }
    public function compliancePeople(Request $r) { Perm::need($r->user(), 'money.SEE'); return \App\Services\AccFilings::people($this->ent(), $r->only(['q'])); }
    public function complianceDocs(Request $r) { Perm::need($r->user(), 'money.SEE'); return \App\Services\AccFilings::documents($this->ent(), $r->only(['q', 'entity'])); }
    public function complianceDocUpload(Request $r) { Perm::need($r->user(), 'money.EDIT'); if (! $r->hasFile('file')) return response()->json(['message' => 'Choose the file.'], 422); return $this->run(fn () => ['id' => \App\Services\AccFilings::uploadFor($this->ent(), $r->only(['entity_type', 'entity_id', 'doc_title']), $r->file('file'), $r->user()), 'message' => 'Document added.']); }
    public function complianceDocShow(Request $r, int $id) { Perm::need($r->user(), 'money.SEE'); return \App\Services\AccFilings::serve($this->ent(), $id); }
    public function complianceDocRemove(Request $r, int $id) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => \App\Services\AccFilings::removeDocument($this->ent(), $id, $r->user())); }
    /* R-ACC-16 / 18 / 20 / 22 Accountancy Hub */
    public function hubSettings(Request $r) { Perm::need($r->user(), 'money.SEE'); return ['values' => \App\Services\AccHubAdmin::settings($this->ent()), 'last_run' => \App\Services\AccHubAdmin::lastRun($this->ent()), 'team' => $r->user()->role_code === 'OWNER' ? \App\Services\AccHubAdmin::team($this->ent(), (string) $r->query('q', '')) : null, 'areas' => \App\Services\AccFilings::AREAS]; }
    public function hubSettingsSave(Request $r) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => \App\Services\AccHubAdmin::saveSettings($this->ent(), $r->only(['due_soon_days', 'history_months', 'horizon_months', 'acc_hub_upload_max_mb', 'cs_date_is', 'reminders_enabled', 'sender_name', 'sender_address']), $r->user())); }
    public function hubMember(Request $r, int $user) { return $this->run(fn () => \App\Services\AccHubAdmin::saveMember($this->ent(), $user, $r->only(['is_accountant', 'can_reveal']), $r->user())); }
    public function hubCloseBefore(Request $r) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => \App\Services\AccHubAdmin::closeBefore($this->ent(), (string) $r->input('date'), $r->input('area') ?: null, $r->user())); }
    public function hubRunJob(Request $r) { Perm::need($r->user(), 'money.EDIT'); $x = \App\Services\AccHubAdmin::job($this->ent()); return ['message' => $x['status'] === 'OK' ? "Run done – {$x['queued']} reminder(s) queued, {$x['failed']} failed." : 'Run ' . $x['status'] . ': ' . ($x['error'] ?? ''), 'run' => $x]; }
    public function hubPerson(Request $r, int $acc) { Perm::need($r->user(), 'money.SEE'); return $this->run(fn () => \App\Services\AccHubAdmin::person($this->ent(), $acc)); }
    public function hubPersonSave(Request $r, int $acc) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => \App\Services\AccHubAdmin::savePerson($this->ent(), $acc, $r->only(['utr', 'ni', 'sa_registered', 'poa_required', 'first_tax_year', 'fk_accountant', 'notes']), $r->user())); }
    public function hubPersonReveal(Request $r, int $acc)
    {
        if ($need = \App\Services\SecureStep::needed($r, $r->user())) return response()->json($need, 428);      /* R-G_CORE-59 the 2FA step before a secret */
        try { return \App\Services\AccHubAdmin::revealPerson($this->ent(), $acc, (string) $r->input('field'), $r->user()); }
        catch (\Illuminate\Auth\Access\AuthorizationException $e) { return response()->json(['message' => $e->getMessage()], 403); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }
    public function complianceRebuild(Request $r, int $company) { Perm::need($r->user(), 'money.EDIT'); return ['message' => \App\Services\AccCompliance::rebuild($this->ent(), $company)]; }
    public function index(Request $r) { Perm::need($r->user(), 'money.SEE'); return Docs::page($this->ent(), $r->only(['period', 'status', 'marketplace', 'shop', 'type', 'issuer', 'recipient', 'from', 'to', 'q', 'size'])); }
    public function show(Request $r, int $id)
    {
        Perm::need($r->user(), 'money.SEE');
        $d = \Illuminate\Support\Facades\DB::table('t_invoices')->where('id', $id)->where('fk_id_ent', $this->ent())->first() ?? abort(404);
        return ['html' => Docs::html($id), 'badge' => Docs::badge($d), 'status' => $d->status, 'total' => (float) $d->total, 'paid' => (float) $d->amount_paid,
            'payments' => \Illuminate\Support\Facades\DB::table('t_invoice_payments')->where('fk_id_invoice', $id)->where('f_deleted', 0)->orderBy('id')->get(['id', 'd_payment', 'amount', 'method', 'reference', 'proof_file'])];
    }
    private const IN = ['advertising', 'other_expenses', 'other_expenses_note', 'extra_3pl', 'extra_3pl_note', 'va_salary', 'owner_fixed', 'handler_fixed', 'notes', 'checked'];
    public function settlePreview(Request $r, int $import) { Perm::need($r->user(), 'money.SEE'); return $this->run(fn () => array_diff_key(\App\Services\InvestorSettle::calc($this->ent(), $import, $r->only(self::IN)), ['lines' => 1])); }
    public function settle(Request $r, int $import) { Perm::need($r->user(), 'money.approve'); return $this->run(fn () => \App\Services\InvestorSettle::settle($this->ent(), $import, $r->only(self::IN), $r->user())); }
    public function generate(Request $r, int $period) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => Docs::generate($this->ent(), $period, $r->user())); }
    public function portalGenerate(Request $r, int $period) { return $this->run(fn () => Docs::portalGenerate($this->ent(), $period, $r->user())); }      /* R-D-83: guarded inside – setting + own share */
    /** R-INV-12 who owes whom (open documents). */
    public function outstanding(Request $r) { Perm::need($r->user(), 'money.SEE'); return \App\Services\InvoiceReports::outstanding($this->ent(), $r->only(['marketplace', 'shop', 'overdue'])); }
    /** R-INV-3 hidden margin – HIDDEN_MARGIN_ROLES only; ?csv=1 downloads the 9-column file. */
    public function margin(Request $r)
    {
        $d = \App\Services\InvoiceReports::margin($this->ent(), $r->only(['from', 'to', 'marketplace', 'shop', 'investor']), $r->user());
        if ($r->query('csv') === '1') return response(\App\Services\InvoiceReports::marginCsv($d), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $d['file'] . '"']);
        return $d;
    }
    /** R-INV-9 the document as a PDF file "<number>.pdf" (A4 portrait); a portal user only his own. */
    public function pdf(Request $r, int $id)
    {
        $d = \Illuminate\Support\Facades\DB::table('t_invoices')->where('id', $id)->where('fk_id_ent', $this->ent())->first() ?? abort(404);
        if (! Perm::has($r->user(), 'money.SEE')) { try { Docs::portalList($this->ent(), $r->user()); } catch (\InvalidArgumentException) { abort(403); } if (! collect(Docs::portalList($this->ent(), $r->user())['docs'])->firstWhere('id', $id)) abort(404); }
        try { $pdf = \App\Services\PrintService::pdf(Docs::html($id), 210, 297, false); } catch (\Throwable $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $d->invoice_no . '.pdf"']);
    }
    public function portal(Request $r) { return $this->run(fn () => Docs::portalList($this->ent(), $r->user())); }      /* R-D-84 / R-G_CORE-86: own documents only – guarded inside */
    public function portalPay(Request $r, int $id) { return $this->run(fn () => Docs::portalPay($this->ent(), $id, $r->only(['amount', 'date', 'method', 'received_into', 'reference', 'notes']), $r->file('proof'), $r->user())); }
    public function portalEmail(Request $r, int $id) { return $this->run(fn () => Docs::portalEmail($this->ent(), $id, $r->user())); }
    public function pay(Request $r, int $id) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => Docs::pay($this->ent(), $id, $r->only(['amount', 'date', 'method', 'received_into', 'reference', 'notes']), $r->file('proof'), $r->user())); }
    public function unpay(Request $r, int $payment) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => Docs::deletePayment($this->ent(), $payment, $r->user())); }
    public function cancel(Request $r, int $id) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => Docs::cancel($this->ent(), $id, (string) $r->input('reason'), $r->user())); }
    public function reopen(Request $r, int $period) { Perm::need($r->user(), 'money.approve'); return $this->run(fn () => Docs::reopen($this->ent(), $period, $r->user())); }
    public function email(Request $r, int $id) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => Docs::email($this->ent(), $id, $r->input('to'), $r->user())); }
}

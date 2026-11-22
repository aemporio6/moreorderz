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

    public function index(Request $r) { Perm::need($r->user(), 'money.SEE'); return Docs::page($this->ent(), $r->only(['period', 'status'])); }
    public function show(Request $r, int $id)
    {
        Perm::need($r->user(), 'money.SEE');
        $d = \Illuminate\Support\Facades\DB::table('t_invoices')->where('id', $id)->where('fk_id_ent', $this->ent())->first() ?? abort(404);
        return ['html' => Docs::html($id), 'badge' => Docs::badge($d), 'status' => $d->status, 'total' => (float) $d->total, 'paid' => (float) $d->amount_paid,
            'payments' => \Illuminate\Support\Facades\DB::table('t_invoice_payments')->where('fk_id_invoice', $id)->where('f_deleted', 0)->orderBy('id')->get(['id', 'd_payment', 'amount', 'method', 'reference', 'proof_file'])];
    }
    public function generate(Request $r, int $period) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => Docs::generate($this->ent(), $period, $r->user())); }
    public function pay(Request $r, int $id) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => Docs::pay($this->ent(), $id, $r->only(['amount', 'date', 'method', 'received_into', 'reference', 'notes']), $r->file('proof'), $r->user())); }
    public function unpay(Request $r, int $payment) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => Docs::deletePayment($this->ent(), $payment, $r->user())); }
    public function cancel(Request $r, int $id) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => Docs::cancel($this->ent(), $id, (string) $r->input('reason'), $r->user())); }
    public function reopen(Request $r, int $period) { Perm::need($r->user(), 'money.approve'); return $this->run(fn () => Docs::reopen($this->ent(), $period, $r->user())); }
    public function email(Request $r, int $id) { Perm::need($r->user(), 'money.EDIT'); return $this->run(fn () => Docs::email($this->ent(), $id, $r->input('to'), $r->user())); }
}

<?php

namespace App\Http\Controllers;

use App\Services\CloseService as Close;
use App\Services\Context;
use Illuminate\Http\Request;

/** Month close: overview of shops × months, the 17 checks of one shop-month, waivers and the lock. */
class CloseController extends Controller
{
    private const SEE = ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT'];
    private const RUN = ['OWNER', 'MANAGER', 'FINANCE'];

    private function can(Request $r, array $roles): void
    {
        abort_unless(in_array($r->user()->role_code, $roles, true), 403, 'Your role cannot do this here. Ask the Owner if you need it.');
    }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }   // never show database text to a user
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    private function rights(Request $r): array { return ['run' => in_array($r->user()->role_code, self::RUN, true), 'owner' => $r->user()->role_code === 'OWNER']; }

    public function overview(Request $r)
    {
        $this->can($r, self::SEE);
        return Close::overview((int) Context::ent(), 6) + ['rights' => $this->rights($r)];
    }

    public function page(Request $r)
    {
        $this->can($r, self::SEE);
        $d = $r->validate(['shop' => 'required|integer', 'month' => 'required|string|size:7']);
        return $this->run(fn () => Close::page((int) Context::ent(), (int) $d['shop'], $d['month']) + ['rights' => $this->rights($r), 'profit_views' => \App\Services\ProfitService::VIEWS]);
    }

    public function check(Request $r)
    {
        $this->can($r, self::RUN);
        $d = $r->validate(['shop' => 'required|integer', 'month' => 'required|string|size:7']);
        return $this->run(function () use ($d, $r) { $p = Close::check((int) Context::ent(), (int) $d['shop'], $d['month']); return $p + ['rights' => $this->rights($r), 'message' => $p['red'] ? "{$p['red']} check(s) are red" : 'All checks pass – the month can be locked']; });
    }

    public function waive(Request $r)
    {
        $this->can($r, ['OWNER']);
        $d = $r->validate(['shop' => 'required|integer', 'month' => 'required|string|size:7', 'code' => 'required|string|max:40', 'reason' => 'required|string|max:500']);
        return $this->run(function () use ($d) { Close::waive((int) Context::ent(), (int) $d['shop'], $d['month'], $d['code'], $d['reason']); return ['message' => 'Check waived for this month']; });
    }

    public function lock(Request $r)
    {
        $this->can($r, ['OWNER']);
        $d = $r->validate(['shop' => 'required|integer', 'month' => 'required|string|size:7', 'note' => 'nullable|string|max:255']);
        return $this->run(function () use ($d) { $x = Close::lock((int) Context::ent(), (int) $d['shop'], $d['month'], (string) ($d['note'] ?? '')); return $x + ['message' => 'Month locked' . ($x['ledger_locked_up_to'] ? '. The ledger is locked up to ' . date('M Y', strtotime($x['ledger_locked_up_to'] . '-01')) : '')]; });
    }
}

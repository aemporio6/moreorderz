<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-0139 / R-D-91 (was FORM ONLY): certificates of a product (CE, UKCA, FCC …) with an expiry date get a task CERT_REMIND_DAYS (60) before they end.
 * Task PRD_CERT_EXPIRY, one per certificate; it closes by itself when the certificate is renewed (a later expiry date) or switched off.
 */
class ProductCerts
{
    public static function sweep(int $ent): array
    {
        $days = max(1, (int) (Settings::get('CERT_REMIND_DAYS', $ent, 60) ?: 60)); $edge = now()->addDays($days)->toDateString(); $opened = 0; $closed = 0;
        $certs = DB::table('product_certificates as c')->leftJoin('t_product_vendors as p', 'p.id', '=', 'c.fk_id_product')->where('c.fk_id_ent', $ent)->whereNotNull('c.expiry_date')
            ->get(['c.id', 'c.cert_no', 'c.expiry_date', 'c.f_active', 'c.fk_id_product', 'p.SKU_VENDOR as sku']);
        foreach ($certs as $c) {
            $due = (int) $c->f_active === 1 && (string) $c->expiry_date <= $edge;
            if ($due) {
                $past = (string) $c->expiry_date < date('Y-m-d');
                $t = TaskEngine::open('PRD_CERT_EXPIRY', [(int) $c->id], 'Certificate ' . ($c->cert_no ?: '#' . $c->id) . ($c->sku ? ' of ' . $c->sku : '') . ($past ? ' has expired on ' : ' expires on ') . $c->expiry_date,
                    ['ent' => $ent, 'module' => 'product_certificates', 'record_id' => (int) $c->id, 'fix_url' => '#/rec/product_certificate/' . $c->id,
                     'description' => 'Get the new certificate from the supplier or test lab, add it with its new expiry date (or switch this one off when the product is no longer sold).']);
                if ($t) $opened++;
            } elseif (TaskEngine::close('PRD_CERT_EXPIRY', [(int) $c->id], (int) $c->f_active === 1 ? 'Renewed – new expiry ' . $c->expiry_date : 'Certificate switched off')) $closed++;
        }
        return ['checked' => $certs->count(), 'open_tasks' => $opened, 'closed' => $closed, 'days' => $days];
    }
}

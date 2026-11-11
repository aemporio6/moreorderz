<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Document numbers TYPE-COMPANY-YYMM-0001. One row per company × type × month, locked while the next number is taken. */
class Numbering
{
    public static function next(string $docType, int $ent, ?string $date = null): string
    {
        $yymm = date('ym', strtotime($date ?? 'now'));
        return DB::transaction(function () use ($docType, $ent, $yymm) {
            $row = DB::table('sys_sequences')->where(['fk_id_ent' => $ent, 'doc_type' => $docType, 'yymm' => $yymm])->lockForUpdate()->first();
            if ($row) {
                $no = $row->last_no + 1;
                DB::table('sys_sequences')->where('id', $row->id)->update(['last_no' => $no]);
            } else {
                $no = 1;
                DB::table('sys_sequences')->insert(['fk_id_ent' => $ent, 'doc_type' => $docType, 'yymm' => $yymm, 'last_no' => 1]);
            }
            $short = DB::table('t_enterprise')->where('id', $ent)->value('ent_short') ?: ('C' . $ent);
            return sprintf('%s-%s-%s-%04d', $docType, strtoupper($short), $yymm, $no);
        });
    }
}

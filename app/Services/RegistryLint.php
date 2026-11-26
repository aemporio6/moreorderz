<?php

namespace App\Services;

/**
 * R-C-38 the registry loader refuses placeholder option lists and wrong "today" defaults (FIELD_REGISTRY had 34 placeholder lists in the person area):
 * a field whose options are the sample set "Standard | Premium | Seasonal | Core" or "Draft | Active | Pending | Done | Closed",
 * and a `today` default on a field named like *birth*, *lived_from*, *submitted_date*, *_starte_date.
 */
class RegistryLint
{
    private const SAMPLES = [['Standard', 'Premium', 'Seasonal', 'Core'], ['Draft', 'Active', 'Pending', 'Done', 'Closed']];

    /** @param array<int, array<string, string>> $rows registry rows (CSV columns) – returns "form.field: why" lines. */
    public static function errors(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $words = preg_split('/[\s"\[\],|]+/', trim(($r['options_sample'] ?? '') . ' ' . ($r['options_full'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            foreach (self::SAMPLES as $set) if (! array_diff($set, $words)) { $out[] = ($r['form_key'] ?? '?') . '.' . ($r['field_name'] ?? '?') . ': placeholder option list (' . implode(' | ', $set) . ')'; break; }
            if (str_starts_with((string) ($r['default_rule'] ?? ''), 'today') && preg_match('/birth|lived_from|submitted_date|_starte_date/i', ($r['field_name'] ?? '') . ' ' . ($r['column_name'] ?? '')))
                $out[] = ($r['form_key'] ?? '?') . '.' . ($r['field_name'] ?? '?') . ': "today" is not a default for this date';
        }
        return $out;
    }
}

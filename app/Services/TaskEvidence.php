<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * RULES_G_CORE R-G_CORE-29 evidence (proof): SCREENSHOT, PHOTO, FILE, LINK, REFERENCE, VOICE ("not proof").
 * Rights are checked BEFORE a file is written; max 15 MB; the image MIME must be image/*; EXIF (jpg / jpeg) → taken_at and GPS (7 decimals);
 * evidence may be added while the task is open (DONE included); files live outside the web root and are only streamed through the rights check.
 */
class TaskEvidence
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    public const IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'];
    public const AUDIO = ['ogg', 'oga', 'opus', 'mp3', 'm4a', 'aac', 'wav', 'webm', 'amr'];
    public const FILE = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip', 'mp4', 'mov'];

    /** May this person add evidence (owner / cover / boss with the status right, the assigner, a helper)? */
    public static function mayAdd(object $t, object $u): bool
    {
        return TaskFlow::mayWork($t, $u) || (int) $t->assigned_by_user_id === (int) $u->id || DB::table('task_helpers')->where('task_id', $t->id)->where('user_id', $u->id)->exists();
    }

    /** The checks that come before anything is written. */
    public static function guard(object $t, object $u): void
    {
        if (! in_array($t->status, TaskFlow::OPEN, true)) throw new \InvalidArgumentException('Refused: the task is closed.');
        if (! self::mayAdd($t, $u)) throw new \InvalidArgumentException('Refused: you cannot add evidence to this task.');
    }

    /**
     * Add one piece of evidence. $kind: null (from the file), SCREENSHOT for an image the person marks as a screenshot.
     * Returns the new task_evidence id. $voiceOk: a comment attachment may be a voice note (always allowed; it is never proof).
     */
    public static function add(int $taskId, object $u, ?UploadedFile $file, ?string $link, ?string $ref, ?string $note = null, ?string $kind = null, string $via = 'APP'): int
    {
        $t = DB::table('tasks')->where('id', $taskId)->first() ?? throw new \InvalidArgumentException('Task not found.');
        self::guard($t, $u);      // before the file is written
        $row = ['task_id' => $taskId, 'uploaded_by' => $u->id, 'via' => $via, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null, 'created_at' => now()];
        if ($file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if ($file->getSize() > self::MAX_BYTES) throw new \InvalidArgumentException('File is larger than 15 MB.');
            if (! in_array($ext, array_merge(self::IMAGE, self::AUDIO, self::FILE), true)) throw new \InvalidArgumentException('Refused: this kind of file is not accepted (photo, voice note, PDF, office, CSV, text, zip or video).');
            $mime = (string) (($file->getRealPath() ? (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) : null) ?: $file->getClientMimeType());      // the real content, not the name
            if (in_array($ext, self::IMAGE, true) && ! str_starts_with($mime, 'image/')) throw new \InvalidArgumentException('That file is not an image.');
            $type = in_array($ext, self::IMAGE, true) ? (strtoupper((string) $kind) === 'SCREENSHOT' ? 'SCREENSHOT' : 'PHOTO') : (in_array($ext, self::AUDIO, true) ? 'VOICE' : 'FILE');
            $path = $file->storeAs('task_evidence/' . $taskId, date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext, 'local');
            $row += ['type' => $type, 'file_path' => $path, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 190), 'mime' => mb_substr($mime, 0, 100)] + self::exif(Storage::disk('local')->path($path), $ext);
        } elseif ($link !== null && trim($link) !== '') {
            $l = trim($link);
            if (! preg_match('#^https?://#i', $l) || ! filter_var($l, FILTER_VALIDATE_URL)) throw new \InvalidArgumentException('Refused: a link must start with http:// or https://');
            if (mb_strlen($l) > 500) throw new \InvalidArgumentException('Refused: a link is at most 500 characters.');
            $row += ['type' => 'LINK', 'url' => $l];
        } elseif ($ref !== null) {
            if (trim($ref) === '') throw new \InvalidArgumentException('Refused: type the reference (case ID, PI, shipment ID ...).');
            if (mb_strlen(trim($ref)) > 120) throw new \InvalidArgumentException('Refused: a reference is at most 120 characters.');
            $row += ['type' => 'REFERENCE', 'reference_id' => trim($ref)];
        } else throw new \InvalidArgumentException('Add a file, a link or a reference.');
        $id = (int) DB::table('task_evidence')->insertGetId(RecordService::fillNotNull('task_evidence', $row));
        DB::table('tasks')->where('id', $taskId)->update(['last_update_at' => now()]); TaskAlerts::fresh($taskId);
        DB::table('task_history')->insert(['task_id' => $taskId, 'at' => now(), 'user_id' => $u->id, 'field' => 'evidence', 'old_value' => null, 'new_value' => $row['type'], 'reason' => mb_substr((string) ($row['file_name'] ?? $row['url'] ?? $row['reference_id'] ?? ''), 0, 255)]);
        return $id;
    }

    /** EXIF of a jpg / jpeg: DateTimeOriginal → taken_at, GPS → gps_lat / gps_lng (7 decimals). Empty for every other kind. */
    public static function exif(string $abs, string $ext): array
    {
        if (! in_array(strtolower($ext), ['jpg', 'jpeg'], true) || ! function_exists('exif_read_data')) return [];
        $x = @exif_read_data($abs); if (! $x) return [];
        $out = [];
        if (! empty($x['DateTimeOriginal']) && ($d = \DateTime::createFromFormat('Y:m:d H:i:s', (string) $x['DateTimeOriginal']))) $out['taken_at'] = $d->format('Y-m-d H:i:s');
        $deg = function ($v, $refv) { if (! is_array($v) || count($v) < 3) return null; $f = fn ($r) => str_contains((string) $r, '/') ? ((float) explode('/', $r)[0] / max(1, (float) explode('/', $r)[1])) : (float) $r;
            $n = $f($v[0]) + $f($v[1]) / 60 + $f($v[2]) / 3600; return round(in_array(strtoupper((string) $refv), ['S', 'W'], true) ? -$n : $n, 7); };
        $lat = $deg($x['GPSLatitude'] ?? null, $x['GPSLatitudeRef'] ?? 'N'); $lng = $deg($x['GPSLongitude'] ?? null, $x['GPSLongitudeRef'] ?? 'E');
        if ($lat !== null && $lng !== null) $out += ['gps_lat' => $lat, 'gps_lng' => $lng];
        return $out;
    }

    /** The gallery rows of the task page. */
    public static function gallery(int $taskId): array
    {
        $names = DB::table('t_users')->pluck('display_name', 'id');
        return DB::table('task_evidence')->where('task_id', $taskId)->orderBy('id')->get()->map(fn ($e) => ['id' => (int) $e->id, 'type' => $e->type, 'name' => $e->file_name ?: $e->url ?: $e->reference_id ?: $e->note,
            'url' => $e->url, 'file' => $e->file_path ? '/api/task-evidence/' . $e->id . '/file' : null, 'mime' => $e->mime, 'note' => $e->note, 'at' => (string) $e->created_at, 'by' => $names[$e->uploaded_by] ?? null,
            'whatsapp' => $e->via === 'WHATSAPP', 'proof' => $e->type !== 'VOICE', 'taken' => $e->taken_at ? date('d M H:i', strtotime((string) $e->taken_at)) : null,
            'map' => $e->gps_lat !== null ? 'https://www.google.com/maps?q=' . number_format((float) $e->gps_lat, 4, '.', '') . ',' . number_format((float) $e->gps_lng, 4, '.', '') : null,
            'hidden' => $e->hidden_at ? 'hidden: ' . $e->hidden_reason : null])->all();
    }

    /** Stream a file through the rights check: 404 when the person cannot see the task. */
    public static function stream(int $evidenceId, object $u)
    {
        $e = DB::table('task_evidence')->where('id', $evidenceId)->first(); $t = $e ? DB::table('tasks')->where('id', $e->task_id)->first() : null;
        if (! $e || ! $t || ! $e->file_path || ! TaskFlow::canSee($t, $u) || ! Storage::disk('local')->exists($e->file_path)) abort(404);
        $mime = (string) ($e->mime ?: 'application/octet-stream');
        $inline = (bool) preg_match('#^(image|audio|video)/|^application/pdf$#', $mime);
        $name = str_replace(['"', "\r", "\n"], '', (string) ($e->file_name ?: basename($e->file_path)));
        return response()->file(Storage::disk('local')->path($e->file_path), ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"', 'Cache-Control' => 'private, max-age=0']);
    }
}

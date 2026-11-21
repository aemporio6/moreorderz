<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\TaskComments;
use App\Services\TaskEvidence;
use App\Services\TaskFlow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-28 comments + @mentions and R-G_CORE-29 evidence (proof) – the spec examples. */
class TaskProofCommentTest extends TestCase
{
    private function task(array $x): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $x + ['fk_id_ent' => 1, 'title' => 'Check the return', 'priority' => 'NORMAL', 'status' => 'DOING', 'created_at' => now()->subDays(2),
            'last_update_at' => now()->subDays(2), 'due_at' => date('Y-m-d', strtotime('+3 days')) . ' 23:59:59', 'source' => 'MANUAL', 'due_changes' => 0, 'evidence_required' => 1, 'flags' => '', 'is_confidential' => 0]));
    }
    private function named(string $role, string $name): object
    {
        $u = $this->makeUser($role); DB::table('t_users')->where('id', $u->id)->update(['display_name' => $name, 'fk_id_ent' => 1]); $u->display_name = $name; return $u;
    }
    private function refused(callable $f, string $txt): void { try { $f(); $this->fail("expected: $txt"); } catch (\InvalidArgumentException $e) { $this->assertSame($txt, $e->getMessage()); } }

    /** A tiny JPEG with an EXIF block: DateTimeOriginal 2026:11:05 10:12:00, GPS 31°31'12.34" N 74°20'37.8" E. */
    private function exifJpeg(): string
    {
        $e = fn ($tag, $type, $count, $val) => pack('vvV', $tag, $type, $count) . $val;
        $tiff = "II*\0" . pack('V', 8)
            . pack('v', 2) . $e(0x8769, 4, 1, pack('V', 38)) . $e(0x8825, 4, 1, pack('V', 76)) . pack('V', 0)      // IFD0 → 38
            . pack('v', 1) . $e(0x9003, 2, 20, pack('V', 56)) . pack('V', 0)                                          // Exif IFD → 56
            . "2026:11:05 10:12:00\0"                                                                                // → 76
            . pack('v', 4) . $e(1, 2, 2, "N\0\0\0") . $e(2, 5, 3, pack('V', 130)) . $e(3, 2, 2, "E\0\0\0") . $e(4, 5, 3, pack('V', 154)) . pack('V', 0)      // GPS IFD → 130
            . pack('V6', 31, 1, 31, 1, 1234, 100) . pack('V6', 74, 1, 20, 1, 378, 10);
        $app1 = "Exif\0\0" . $tiff;
        $im = imagecreatetruecolor(8, 8); ob_start(); imagejpeg($im); $jpg = (string) ob_get_clean();      // a real JPEG, the EXIF block put right after SOI
        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpg, 2);
    }

    public function test_r29_evidence_rules_exif_and_streamed_through_the_rights_check(): void
    {
        Storage::fake('local');
        $mgr = $this->named('MANAGER', 'Mona Manager'); $va = $this->named('VA', 'Asad Khan'); $other = $this->named('VA', 'Bilal Other');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id]);
        $this->refused(fn () => TaskEvidence::add($t, $va, UploadedFile::fake()->create('big.jpg', 16 * 1024, 'image/jpeg'), null, null), 'File is larger than 15 MB.');
        $this->refused(fn () => TaskEvidence::add($t, $va, UploadedFile::fake()->createWithContent('fake.jpg', 'just text'), null, null), 'That file is not an image.');
        $this->refused(fn () => TaskEvidence::add($t, $va, null, 'www.example.com', null), 'Refused: a link must start with http:// or https://');
        $this->refused(fn () => TaskEvidence::add($t, $va, null, null, '  '), 'Refused: type the reference (case ID, PI, shipment ID ...).');
        $this->refused(fn () => TaskEvidence::add($t, $other, UploadedFile::fake()->createWithContent('p.jpg', $this->exifJpeg()), null, null), 'Refused: you cannot add evidence to this task.');
        $this->assertSame([], Storage::disk('local')->allFiles(), 'refused before anything is written');
        $id = TaskEvidence::add($t, $va, UploadedFile::fake()->createWithContent('shelf.jpg', $this->exifJpeg()), null, null);
        $e = DB::table('task_evidence')->find($id);
        $this->assertSame(['PHOTO', '2026-11-05 10:12:00', '31.5200944', '74.3438333'], [$e->type, (string) $e->taken_at, (string) $e->gps_lat, (string) $e->gps_lng]);
        $g = collect(TaskEvidence::gallery($t))->firstWhere('id', $id);
        $this->assertSame(['05 Nov 10:12', 'https://www.google.com/maps?q=31.5201,74.3438', true], [$g['taken'], $g['map'], $g['proof']], 'shown "taken 05 Nov 10:12 · map"');
        $this->actingAs($other)->get("/api/task-evidence/$id/file")->assertNotFound();
        $r = $this->actingAs($va)->get("/api/task-evidence/$id/file")->assertOk();
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options')); $this->assertStringStartsWith('inline', (string) $r->headers->get('Content-Disposition'));
        // a voice note is kept but is not proof; a screenshot; evidence may still be added when DONE, not when closed
        $v = TaskEvidence::add($t, $va, UploadedFile::fake()->createWithContent('note.ogg', "OggS\0\2" . str_repeat("\0", 40)), null, null);
        $this->assertSame(['VOICE', false], [DB::table('task_evidence')->where('id', $v)->value('type'), collect(TaskEvidence::gallery($t))->firstWhere('id', $v)['proof']]);
        $png = UploadedFile::fake()->image('screen.png', 20, 20);
        $this->assertSame('SCREENSHOT', DB::table('task_evidence')->where('id', TaskEvidence::add($t, $va, $png, null, null, null, 'SCREENSHOT'))->value('type'));
        DB::table('tasks')->where('id', $t)->update(['status' => 'DONE']);
        $this->assertGreaterThan(0, TaskEvidence::add($t, $va, null, 'https://example.com/case/77', null, 'case page'));
        DB::table('tasks')->where('id', $t)->update(['status' => 'APPROVED']);
        $this->refused(fn () => TaskEvidence::add($t, $va, null, null, 'PI-12'), 'Refused: the task is closed.');
    }

    public function test_r28_mentions_watcher_n9_and_confidential_skipped(): void
    {
        Storage::fake('local');
        $mgr = $this->named('MANAGER', 'Mona Manager'); $va = $this->named('VA', 'Asad Khan'); $b = $this->named('VA', 'User B');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id]);
        $this->assertFalse(TaskFlow::canSee(DB::table('tasks')->find($t), $b));
        $r = TaskComments::add($t, $mgr, '@User B please check');
        $this->assertTrue(DB::table('task_watchers')->where('task_id', $t)->where('user_id', $b->id)->exists(), 'User B becomes a watcher');
        $this->assertSame(1, DB::table('tm_messages')->where('task_id', $t)->where('user_id', $b->id)->where('rule_code', 'N9')->where('template_key', 'tm_mention')->where('channel', 'INAPP')->count());
        $this->assertStringContainsString('Mona Manager mentioned you on ' . TaskFlow::ref($t), (string) DB::table('tm_messages')->where('task_id', $t)->where('user_id', $b->id)->where('channel', 'INAPP')->value('text'));
        $this->assertSame([$b->id], json_decode((string) DB::table('task_comments')->where('id', $r['id'])->value('mentions_json'), true));
        $before = (string) DB::table('tasks')->where('id', $t)->value('last_update_at');
        TaskComments::add($t, $mgr, 'second note'); $this->assertSame($before, (string) DB::table('tasks')->where('id', $t)->value('last_update_at'), 'a Manager comment is no update');
        TaskComments::add($t, $va, 'on it @Mona_Manager'); $this->assertNotSame($before, (string) DB::table('tasks')->where('id', $t)->value('last_update_at'), 'the owner\'s comment is an update');
        $this->assertSame(1, DB::table('tm_messages')->where('task_id', $t)->where('user_id', $mgr->id)->where('rule_code', 'N9')->where('channel', 'INAPP')->count(), '@First_Last');
        // confidential: no watcher, no message, a warning to the author (new)
        $c = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'is_confidential' => 1]);
        $r = TaskComments::add($c, $va, '@User B please check');
        $this->assertFalse(DB::table('task_watchers')->where('task_id', $c)->where('user_id', $b->id)->exists());
        $this->assertSame(0, DB::table('tm_messages')->where('task_id', $c)->where('user_id', $b->id)->count());
        $this->assertSame(['User B cannot see a confidential task – not told.'], $r['warnings']);
        // limits and the attachment (stored as evidence with the comment link; a voice note is not proof)
        try { TaskComments::add($t, $va, str_repeat('x', 2001)); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: a comment is at most 2,000 characters.', $e->getMessage()); }
        $w = TaskComments::add($t, $va, 'voice from the warehouse', UploadedFile::fake()->createWithContent('v.ogg', "OggS\0\2" . str_repeat("\0", 40)));
        $fid = (int) DB::table('task_comments')->where('id', $w['id'])->value('file_id');
        $this->assertSame('VOICE', DB::table('task_evidence')->where('id', $fid)->value('type'));
        $row = collect(TaskComments::rows($t))->firstWhere('id', $w['id']); $this->assertSame(["/api/task-evidence/$fid/file", 'VOICE'], [$row['file'], $row['file_type']]);
        // the page offers the @ picker; HTTP comment returns the warnings
        $this->assertContains('@User_B', array_column($this->actingAs($va)->getJson("/api/tasks/$t/page")->assertOk()->json('people'), 'tag'));
        $this->actingAs($va)->postJson("/api/tasks/$c/comment", ['text' => 'ping @User_B'])->assertOk()->assertJsonPath('warnings.0', 'User B cannot see a confidential task – not told.');
    }
}

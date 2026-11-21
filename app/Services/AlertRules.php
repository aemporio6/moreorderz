<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Alert rules catalogue N1–N14 + MANUAL + APPROVED (R-G_CORE-49) and the alert thresholds (R-G_CORE-51).
 * - One rule screen: a Partner switches a rule on/off and picks its channels (WHATSAPP / EMAIL / INAPP). A rule that is off sends nothing.
 * - MANUAL (reminder) and APPROVED are always WhatsApp + in-app and cannot be switched off or changed.
 * - Texts are editable per language (English default, Urdu optional) and must keep the same parameters.
 * - send() is the single door every rule goes through: rule off → nothing; blocked task skipped for N2/N3/N5/N12;
 *   one tm_messages row per person × channel, never twice (event key + "|u{user}").
 * - Thresholds live in tm_settings (Partner only), each change kept in tm_settings_history.
 */
class AlertRules
{
    public const CHANNELS = ['WHATSAPP' => 'WhatsApp', 'EMAIL' => 'E-mail', 'INAPP' => 'In-app (bell)'];
    public const LANGS = ['en' => 'English', 'ur' => 'Urdu'];
    public const LOCKED_CHANNELS = ['WHATSAPP', 'INAPP'];

    /** code => [name, trigger, goes to, template, extra template, channels, when, escalation, locked, skip blocked] */
    public const CATALOGUE = [
        'N1' => ['New task', 'TASK_CREATED', 'Owner', 'tm_new_task', null, ['WHATSAPP', 'INAPP'], 'At once (an URGENT task even in quiet hours)', '', 0, 0],
        'N2' => ['Not acknowledged', 'TASK_NOT_ACK', 'Helpers + assigner (bosses for a system task)', 'tm_not_ack', null, ['WHATSAPP', 'INAPP'], 'After the category ack hours', 'After ack hours × ack_escalate_multiplier → the category escalate-to role, text starts "ESCALATED:"', 0, 1],
        'N3' => ['Due tomorrow', 'TASK_DUE_TOMORROW', 'Owner', 'tm_due_tomorrow', null, ['WHATSAPP', 'INAPP'], "At the owner's local due_tomorrow_time, once per due date", '', 0, 1],
        'N4' => ['Due today', 'TASK_DUE_TODAY', 'Owner', 'tm_digest', null, ['INAPP'], 'Only inside the N13 daily digest', '', 0, 0],
        'N5' => ['Late', 'TASK_LATE', 'Owner + assigner', 'tm_late', null, ['WHATSAPP', 'INAPP'], 'Daily at local late_time', 'Managers added from day late_manager_day', 0, 1],
        'N6' => ['Stuck', 'TASK_STUCK', 'Stuck-on person + Managers (Partners when no Manager); never the owner', 'tm_stuck', null, ['WHATSAPP', 'INAPP'], 'After stuck_alert_days, then every stuck_repeat_days', '', 0, 0],
        'N7' => ['Done – waiting approval', 'TASK_DONE', 'Assigner (system task: all Partners + Managers)', 'tm_waiting_approval', null, ['WHATSAPP', 'EMAIL', 'INAPP'], 'Repeats every approval_repeat_hours', 'After approval_manager_hours also Managers ("you may approve")', 0, 0],
        'N8' => ['Rework', 'TASK_REWORK', 'Owner', 'tm_rework', null, ['WHATSAPP', 'INAPP'], 'At once', '', 0, 0],
        'N9' => ['Mention', 'TASK_MENTION', 'Mentioned person', 'tm_mention', null, ['WHATSAPP', 'INAPP'], 'At once', '', 0, 0],
        'N10' => ['Dependency cleared', 'TASK_UNBLOCKED', 'Owner of the waiting task', 'tm_unblocked', null, ['WHATSAPP', 'INAPP'], 'At once', '', 0, 0],
        'N11' => ['Reassigned', 'TASK_REASSIGNED', 'New owner', 'tm_reassigned', null, ['WHATSAPP', 'INAPP'], 'At once', '', 0, 0],
        'N12' => ['Stale', 'TASK_STALE', 'Owner', 'tm_stale', null, ['WHATSAPP', 'INAPP'], 'Once per stale spell (stale_days without an update)', '', 0, 1],
        'N13' => ['Daily digest + team digest', 'DIGEST', 'Every person; bosses also get the team digest', 'tm_digest', 'tm_team_digest', ['WHATSAPP', 'EMAIL', 'INAPP'], "At the person's digest_time (team digest + team_digest_offset_min)", '', 0, 0],
        'N14' => ['Weekly report', 'WEEKLY_REPORT', 'Partners + Managers', 'tm_weekly_report', null, ['EMAIL', 'INAPP'], 'weekly_report (MON 09:00)', '', 0, 0],
        'MANUAL' => ['Reminder (sent by hand)', 'TASK_REMIND', 'Owner', 'tm_reminder', null, ['WHATSAPP', 'INAPP'], 'When the assigner or boss presses Remind', '', 1, 0],
        'APPROVED' => ['Approved', 'TASK_APPROVED', 'Owner', 'tm_approved', null, ['WHATSAPP', 'INAPP'], 'At once', '', 1, 0],
    ];

    /** template key => [subject, English text]. Parameters are the {names} in the text, in order. */
    public const TEXTS = [
        'tm_new_task' => ['[{task_ref}] New task: {title}', 'Hi {first_name}, new task {task_ref}: {title}. Due {due}. {link} Reply OK to acknowledge.'],
        'tm_not_ack' => ['[{task_ref}] Not acknowledged', 'Hi {first_name}, {owner_name} has not acknowledged {task_ref}: {title} after {hours} hours. {link}'],
        'tm_due_tomorrow' => ['[{task_ref}] Due tomorrow', 'Hi {first_name}, {task_ref}: {title} is due tomorrow ({due}). {link}'],
        'tm_late' => ['[{task_ref}] Late {days_late} days', 'Hi {first_name}, {task_ref}: {title} is {days_late} day(s) late (owner {owner_name}). {link}'],
        'tm_stuck' => ['[{task_ref}] Stuck on {stuck_on}', 'Hi {first_name}, {task_ref}: {title} of {owner_name} is stuck on {stuck_on} for {days} day(s). {link}'],
        'tm_waiting_approval' => ['[{task_ref}] Waiting your approval: {title}', 'Hi {first_name}, {task_ref}: {title} is done by {owner_name} and waits for your approval. {link}'],
        'tm_rework' => ['[{task_ref}] Rework', 'Hi {first_name}, {task_ref}: {title} needs rework: {reason}. {link}'],
        'tm_mention' => ['[{task_ref}] {author} mentioned you', 'Hi {first_name}, {author} mentioned you on {task_ref}: {title} – "{comment}". {link}'],
        'tm_unblocked' => ['[{task_ref}] You can start', 'Hi {first_name}, {cleared_ref} is finished. You can start {task_ref}: {title} now. {link}'],
        'tm_reassigned' => ['[{task_ref}] Reassigned to you', 'Hi {first_name}, {by} gave you {task_ref}: {title}, due {due}. Reason: {reason}. {link}'],
        'tm_stale' => ['[{task_ref}] No update for {days} days', 'Hi {first_name}, {task_ref}: {title} has had no update for {days} days. Please update it. {link}'],
        'tm_digest' => ['Your tasks today', 'Good morning {first_name}: {late} late, {today} due today, {soon} due soon, {stuck_on_me} stuck on you, {to_approve} to approve. {link}'],
        'tm_team_digest' => ['Your team today', 'Team today, {first_name}: {late} late, {stuck} stuck, {waiting} waiting approval, {over_limit} over the weekly limit. {link}'],
        'tm_weekly_report' => ['Weekly task report {week}', 'Hi {first_name}, the weekly task report for {week} is ready: {done} done, {late} late, {stuck} stuck. {link}'],
        'tm_reminder' => ['[{task_ref}] Reminder', 'Reminder from {by}: {task_ref} {title} (due {due}). {link}'],      // R-G_CORE-32 text
        'tm_approved' => ['[{task_ref}] Approved', 'Well done {first_name}, {task_ref}: {title} is approved – quality {quality}, score {score}. {link}'],
    ];

    /** key => [default, kind NUMBER / TIME / DAYTIME, label, help] */
    public const THRESHOLDS = [
        'due_tomorrow_time' => ['09:00', 'TIME', 'Due-tomorrow alert time', "N3 goes at this local time of the owner."],
        'late_time' => ['09:00', 'TIME', 'Late alert time', 'N5 goes daily at this local time.'],
        'late_manager_day' => ['3', 'NUMBER', 'Managers told from late day', 'From this many days late the Managers get N5 too.'],
        'approval_repeat_hours' => ['24', 'NUMBER', 'Repeat waiting-approval every (hours)', 'N7 is sent again after this many hours.'],
        'approval_manager_hours' => ['48', 'NUMBER', 'Managers may approve after (hours)', 'After this many hours N7 goes to the Managers too.'],
        'rule_snooze_days' => ['7', 'NUMBER', 'Rule task snooze (days)', 'A rule task a Partner closed with a reason is not opened again for this many days (R-G_CORE-97).'],
        'send_retry_max' => ['3', 'NUMBER', 'Retries before FAILED', 'A failed send is tried again this many times.'],
        'send_retry_minutes' => ['10', 'NUMBER', 'Minutes between retries', 'Wait before a failed send is tried again.'],
        'stuck_alert_days' => ['3', 'NUMBER', 'Stuck alert after (days)', 'N6 goes after a task is stuck this many days.'],
        'stuck_repeat_days' => ['2', 'NUMBER', 'Repeat stuck alert every (days)', 'N6 is sent again after this many days.'],
        'stale_days' => ['7', 'NUMBER', 'Stale after (days)', 'A task with no update this long is stale (N12).'],
        'team_digest_offset_min' => ['15', 'NUMBER', 'Team digest after own digest (minutes)', 'Bosses get the team digest this many minutes after their own.'],
        'weekly_report' => ['MON 09:00', 'DAYTIME', 'Weekly report time', 'Day and time of N14, e.g. MON 09:00.'],
        'ack_escalate_multiplier' => ['2', 'NUMBER', 'Escalate after ack hours ×', 'N2 escalates to the category escalate-to role after ack hours × this.'],
        'weekly_report_pdf' => ['1', 'NUMBER', 'Weekly report with A4 PDF (1 = yes, 0 = no)', 'Attach the A4 PDF to N14.'],
        'digest_send_empty' => ['1', 'NUMBER', 'Send an empty digest (1 = yes, 0 = no)', 'Send N13 even when the person has nothing waiting.'],
        'due_move_limit' => ['2', 'NUMBER', 'Due date moves before Partner', 'The next move of a due date needs a Partner.'],
        'score_on_time' => ['40', 'NUMBER', 'Score: on time points', 'Task score (R-G_CORE-8): points for a task approved on or before its due day.'],
        'score_late_per_day' => ['5', 'NUMBER', 'Score: minus per late day', 'On-time points lost per day late.'],
        'score_proof' => ['20', 'NUMBER', 'Score: proof points', 'Points when the task has at least one photo or file (a voice note does not count).'],
        'score_q_excellent' => ['40', 'NUMBER', 'Score: quality Excellent', 'Quality points for Excellent.'],
        'score_q_good' => ['30', 'NUMBER', 'Score: quality Good', 'Quality points for Good (also rule tasks closed by the system).'],
        'score_q_ok' => ['20', 'NUMBER', 'Score: quality OK', 'Quality points for OK.'],
        'score_q_poor' => ['10', 'NUMBER', 'Score: quality Poor', 'Quality points for Poor.'],
        'score_rework' => ['10', 'NUMBER', 'Score: minus per rework', 'Points lost for each time the task was sent back.'],
        'score_band_good' => ['80', 'NUMBER', 'Score: green from', 'A score from this value is green (good).'],
        'score_band_mid' => ['65', 'NUMBER', 'Score: amber from', 'A score from this value (below green) is amber; below it red.'],
    ];

    /** Write the catalogue, its English texts and the threshold defaults. Existing on/off, channels and edited texts are kept. */
    public static function seed(): void
    {
        $i = 0;
        foreach (self::CATALOGUE as $code => [$name, $trig, $to, $tpl, $tpl2, $ch, $when, $esc, $locked, $skip]) {
            $i++;
            $base = ['name' => $name, 'trigger_event' => $trig, 'to_role_or_person' => $to, 'template_key' => $tpl, 'extra_template_key' => $tpl2, 'when_rule' => $when, 'escalation' => $esc, 'is_locked' => $locked, 'skip_blocked' => $skip, 'sort' => $i * 10, 'module' => 'TASK'];
            if (DB::table('tm_alert_rules')->where('code', $code)->exists()) DB::table('tm_alert_rules')->where('code', $code)->update($base + ($locked ? ['is_active' => 1, 'channels_json' => json_encode(self::LOCKED_CHANNELS)] : []));
            else DB::table('tm_alert_rules')->insert($base + ['code' => $code, 'channels_json' => json_encode($locked ? self::LOCKED_CHANNELS : $ch), 'is_active' => 1]);
        }
        foreach (self::TEXTS as $key => [$subject, $body]) {
            if (! DB::table('tm_alert_texts')->where('template_key', $key)->where('lang', 'en')->exists())
                DB::table('tm_alert_texts')->insert(['template_key' => $key, 'lang' => 'en', 'subject' => $subject, 'body' => $body, 'params_json' => json_encode(self::params($body))]);
        }
        $s = 0;
        foreach (self::THRESHOLDS as $key => [$def, $kind, $label, $help]) {
            $s++;
            if (DB::table('tm_settings')->where('setting_key', $key)->exists()) DB::table('tm_settings')->where('setting_key', $key)->update(['label' => $label, 'help' => $help, 'kind' => $kind, 'sort' => $s * 10]);
            else DB::table('tm_settings')->insert(['setting_key' => $key, 'setting_value' => $def, 'label' => $label, 'help' => $help, 'kind' => $kind, 'sort' => $s * 10]);
        }
    }

    public static function params(string $body): array { preg_match_all('/\{(\w+)\}/', $body, $m); return array_values(array_unique($m[1])); }

    private static function num(string $code): int { return preg_match('/^N(\d+)$/', $code, $m) ? (int) $m[1] : 100 + (int) (self::CATALOGUE[$code][8] ?? 0); }

    private static function canManage(?object $u): bool { return Perm::has($u, 'work.alertRules'); }

    /** The rule screen: rules sorted by the number of the code, with their texts in every language. */
    public static function page(?object $u): array
    {
        $texts = DB::table('tm_alert_texts')->get()->groupBy('template_key');
        $rules = DB::table('tm_alert_rules')->get()->map(function ($r) use ($texts) {
            $r->channels = json_decode($r->channels_json, true) ?: [];
            $r->texts = [];
            foreach (array_filter([$r->template_key, $r->extra_template_key]) as $k)
                foreach (self::LANGS as $l => $ln) { $t = ($texts[$k] ?? collect())->firstWhere('lang', $l); $r->texts[] = ['key' => $k, 'lang' => $l, 'subject' => $t->subject ?? null, 'body' => $t->body ?? null, 'params' => self::params((string) (($texts[$k] ?? collect())->firstWhere('lang', 'en')->body ?? ''))]; }
            return $r;
        })->sortBy(fn ($r) => sprintf('%04d%04d', self::num($r->code), $r->sort))->values();
        return ['rules' => $rules, 'channels' => self::CHANNELS, 'langs' => self::LANGS, 'can_edit' => self::canManage($u), 'thresholds' => self::thresholds()];
    }

    /** Switch a rule on/off and set its channels (Partner only). Locked rules refuse. */
    public static function save(?object $u, string $code, array $in): array
    {
        if (! self::canManage($u)) throw new \InvalidArgumentException('Refused: only a Partner can change alert rules.');
        $r = DB::table('tm_alert_rules')->where('code', $code)->first() ?? throw new \InvalidArgumentException("Alert rule $code not found.");
        $on = array_key_exists('is_active', $in) ? (int) (bool) $in['is_active'] : (int) $r->is_active;
        $ch = array_key_exists('channels', $in) ? array_values(array_intersect(array_keys(self::CHANNELS), (array) $in['channels'])) : (json_decode($r->channels_json, true) ?: []);
        $same = ! array_diff(self::LOCKED_CHANNELS, $ch) && ! array_diff($ch, self::LOCKED_CHANNELS);
        if ($r->is_locked && (! $on || ! $same))
            throw new \InvalidArgumentException("Refused: $code is always WhatsApp + in-app and cannot be switched off.");
        if ($r->is_locked) $ch = self::LOCKED_CHANNELS;
        if ($on && ! $ch) throw new \InvalidArgumentException('Pick at least one channel, or switch the rule off.');
        DB::table('tm_alert_rules')->where('id', $r->id)->update(['is_active' => $on, 'channels_json' => json_encode($ch), 'updated_by' => $u->id ?? null, 'updated_at' => now()]);
        Audit::log('tm_alert_rules', (int) $r->id, 'UPDATE', ['on' => [$r->is_active, $on], 'channels' => [$r->channels_json, json_encode($ch)]]);
        return ['message' => "$code " . ($on ? 'on – ' . implode(' + ', array_map(fn ($c) => self::CHANNELS[$c], $ch)) : 'switched off – it sends nothing') . '.'];
    }

    /** Edit a text in one language. The parameters must be the same as the English text (none added, none lost). */
    public static function saveText(?object $u, string $key, string $lang, string $body, ?string $subject = null): array
    {
        if (! self::canManage($u)) throw new \InvalidArgumentException('Refused: only a Partner can change alert texts.');
        if (! isset(self::LANGS[$lang])) throw new \InvalidArgumentException('Unknown language.');
        $en = DB::table('tm_alert_texts')->where('template_key', $key)->where('lang', 'en')->first() ?? throw new \InvalidArgumentException("Text $key not found.");
        $body = trim($body);
        if ($body === '') throw new \InvalidArgumentException('Write the text.');
        $want = self::params($en->body); $got = self::params($body);
        if ($lang === 'en') $want = json_decode($en->params_json, true) ?: $want;
        $miss = array_diff($want, $got); $extra = array_diff($got, $want);
        if ($miss || $extra) throw new \InvalidArgumentException('Refused: keep the same parameters as the template' . ($miss ? ' – missing {' . implode('}, {', $miss) . '}' : '') . ($extra ? ' – unknown {' . implode('}, {', $extra) . '}' : '') . '.');
        $old = DB::table('tm_alert_texts')->where('template_key', $key)->where('lang', $lang)->first();
        $row = ['body' => mb_substr($body, 0, 1000), 'subject' => $subject !== null ? mb_substr(trim($subject), 0, 200) : ($old->subject ?? $en->subject), 'params_json' => json_encode($want), 'updated_by' => $u->id ?? null, 'updated_at' => now()];
        if ($old) DB::table('tm_alert_texts')->where('id', $old->id)->update($row);
        else $old = (object) ['id' => DB::table('tm_alert_texts')->insertGetId($row + ['template_key' => $key, 'lang' => $lang])];
        Audit::log('tm_alert_texts', (int) $old->id, 'UPDATE', ['text' => [$old->body ?? null, $row['body']], 'lang' => $lang]);
        return ['message' => "Text $key (" . self::LANGS[$lang] . ') saved.'];
    }

    /** Fill a text: the language asked for, English when that language has no text yet. */
    public static function render(string $key, array $vars, string $lang = 'en'): array
    {
        $t = DB::table('tm_alert_texts')->where('template_key', $key)->whereIn('lang', array_unique([$lang, 'en']))->orderByRaw('lang = ? desc', [$lang])->first()
            ?? throw new \InvalidArgumentException("Alert text $key is missing.");
        $fill = fn ($s) => preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), (string) $s);
        $params = array_map(fn ($k) => (string) ($vars[$k] ?? ''), json_decode($t->params_json, true) ?: self::params($t->body));
        return ['text' => $fill($t->body), 'subject' => $fill($t->subject), 'params' => $params, 'lang' => $t->lang];
    }

    public static function rule(string $code): ?object { return DB::table('tm_alert_rules')->where('code', $code)->first(); }

    /** Is the rule on, and is this channel one of its channels? */
    public static function allows(string $code, string $channel): bool
    {
        $r = self::rule($code);
        return $r && $r->is_active && in_array($channel, json_decode($r->channels_json, true) ?: [], true);
    }

    public static function isBlocked(?int $taskId): bool
    {
        if (! $taskId) return false;
        $dep = DB::table('tasks')->where('id', $taskId)->value('depends_on_task_id');
        return $dep && ! in_array(DB::table('tasks')->where('id', $dep)->value('status'), ['APPROVED', 'CANCELLED'], true);
    }

    /**
     * The one door for every alert: queue rule $code for one person on each of the rule's channels.
     * Returns the tm_messages ids written (empty when the rule is off, or the task is blocked for N2/N3/N5/N12).
     * $template overrides the rule's template (N13 team digest). Never twice: UNIQUE (event_key, channel).
     */
    public static function send(string $code, int $userId, array $vars, ?int $taskId, string $eventKey, ?string $template = null, string $lang = 'en'): array
    {
        $r = self::rule($code);
        if (! $r || ! $r->is_active) return [];
        if ($r->skip_blocked && self::isBlocked($taskId)) return [];
        $user = DB::table('t_users')->where('id', $userId)->first(); if (! $user) return [];
        $m = self::render($template ?? $r->template_key, $vars, $lang);
        $ids = []; $key = mb_substr($eventKey . '|u' . $userId, 0, 120);
        foreach (json_decode($r->channels_json, true) ?: [] as $ch) {
            $to = null; $error = null;
            if ($ch === 'WHATSAPP') { $to = $user->fk_id_account ? Connect\Messenger::whatsappOf((int) $user->fk_id_account) : null; if (! $to) $error = 'No WhatsApp number – add it on Alerts & Messages'; }
            if ($ch === 'EMAIL') { $to = $user->fk_id_account ? Connect\Messenger::emailOf((int) $user->fk_id_account) : null; if (! $to) $error = 'No email on file'; }
            $row = ['user_id' => $userId, 'task_id' => $taskId, 'rule_code' => $code, 'channel' => $ch, 'to_address' => $to, 'direction' => 'OUT', 'template_key' => $template ?? $r->template_key,
                'subject' => $ch === 'EMAIL' ? mb_substr($m['subject'], 0, 200) : null, 'template_params' => json_encode($m['params']), 'event_key' => $key, 'text' => $m['text'],
                'status' => $ch === 'INAPP' ? 'SENT' : ($error ? 'FAILED' : 'QUEUED'), 'error' => $error, 'sent_at' => $ch === 'INAPP' ? now() : null, 'failed_at' => $error ? now() : null, 'created_at' => now()];
            if (! DB::table('tm_messages')->insertOrIgnore($row)) continue;
            $ids[] = (int) DB::table('tm_messages')->where('event_key', $key)->where('channel', $ch)->value('id');
            if ($ch === 'INAPP') Notify::push($userId, 'TASK', $m['text'], $taskId ? '#/tasks' : null, $taskId);
        }
        if ($ids && $taskId) DB::table('tasks')->where('id', $taskId)->update(['last_notified_at' => now()]);
        return $ids;
    }

    /**
     * N6 stuck (first timed rule of R-G_CORE-50): a task stuck for stuck_alert_days, then every stuck_repeat_days.
     * Goes to the stuck-on person + Managers (Partners when there is no Manager); never the owner. Event key N6:{task}:{stuck_since}:{n}.
     */
    public static function stuckSweep(?\DateTimeInterface $now = null): int
    {
        $now = $now ? \Carbon\Carbon::instance($now) : now();
        if (! self::allows('N6', 'INAPP') && ! self::allows('N6', 'WHATSAPP') && ! self::allows('N6', 'EMAIL')) return 0;
        $after = max(1, (int) self::threshold('stuck_alert_days')); $every = max(1, (int) self::threshold('stuck_repeat_days'));
        $sent = 0;
        foreach (DB::table('tasks')->where('status', 'STUCK')->whereNotNull('stuck_since')->get() as $t) {
            $days = (int) \Carbon\Carbon::parse($t->stuck_since)->startOfDay()->diffInDays($now->copy()->startOfDay());
            if ($days < $after || ($days - $after) % $every) continue;
            $n = intdiv($days - $after, $every) + 1;
            $bosses = DB::table('t_users')->where('fk_id_ent', $t->fk_id_ent)->where('f_active', 1)->where('role_code', 'MANAGER')->pluck('id')->all()
                ?: DB::table('t_users')->where('fk_id_ent', $t->fk_id_ent)->where('f_active', 1)->whereIn('role_code', ['OWNER', 'PARTNER'])->pluck('id')->all();
            $to = array_unique(array_merge($t->stuck_on_type === 'USER' && $t->stuck_on_id ? [(int) $t->stuck_on_id] : [], array_map('intval', $bosses)));
            $owner = DB::table('t_users')->where('id', $t->owner_user_id)->value('display_name') ?? '';
            foreach (array_diff($to, [(int) $t->owner_user_id]) as $uid) {
                $name = (string) DB::table('t_users')->where('id', $uid)->value('display_name');
                $sent += count(self::send('N6', $uid, ['first_name' => strtok($name, ' ') ?: $name, 'task_ref' => 'T-' . str_pad((string) $t->id, 4, '0', STR_PAD_LEFT), 'title' => $t->title, 'owner_name' => $owner,
                    'stuck_on' => $t->stuck_on_text ?: strtolower((string) $t->stuck_on_type), 'days' => $days, 'link' => rtrim((string) config('app.url'), '/') . '/#/tasks'], (int) $t->id, "N6:$t->id:$t->stuck_since:$n"));
            }
        }
        return $sent;
    }

    // ---------- thresholds (R-G_CORE-51) ----------

    public static function threshold(string $key): string
    {
        return (string) (DB::table('tm_settings')->where('setting_key', $key)->value('setting_value') ?? (self::THRESHOLDS[$key][0] ?? ''));
    }

    public static function thresholds(): array
    {
        $hist = DB::table('tm_settings_history')->orderByDesc('id')->limit(200)->get()->groupBy('setting_key');
        return DB::table('tm_settings')->orderBy('sort')->get()->map(fn ($s) => ['key' => $s->setting_key, 'value' => $s->setting_value, 'label' => $s->label, 'help' => $s->help, 'kind' => $s->kind,
            'history' => ($hist[$s->setting_key] ?? collect())->take(5)->map(fn ($h) => ['old' => $h->old_value, 'new' => $h->new_value, 'at' => $h->changed_at, 'by' => $h->changed_by])->values()])->all();
    }

    public static function setThreshold(?object $u, string $key, string $value): array
    {
        if (! self::canManage($u)) throw new \InvalidArgumentException('Refused: only a Partner can change alert thresholds.');
        $s = DB::table('tm_settings')->where('setting_key', $key)->first() ?? throw new \InvalidArgumentException("Setting $key not found.");
        $v = strtoupper(trim($value));
        $ok = match ($s->kind) {
            'TIME' => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v),
            'DAYTIME' => (bool) preg_match('/^(MON|TUE|WED|THU|FRI|SAT|SUN) ([01]\d|2[0-3]):[0-5]\d$/', $v),
            default => (bool) preg_match('/^\d{1,4}$/', $v),
        };
        if (! $ok) throw new \InvalidArgumentException(match ($s->kind) { 'TIME' => 'Refused: use HH:MM', 'DAYTIME' => 'Refused: use DAY HH:MM, e.g. MON 09:00', default => 'Refused: a whole number of up to 4 digits' });
        if ($v === $s->setting_value) return ['message' => 'No change.'];
        DB::table('tm_settings')->where('setting_key', $key)->update(['setting_value' => $v, 'updated_by' => $u->id ?? null, 'updated_at' => now()]);
        DB::table('tm_settings_history')->insert(['setting_key' => $key, 'old_value' => $s->setting_value, 'new_value' => $v, 'changed_by' => $u->id ?? null, 'changed_at' => now()]);
        return ['message' => "$s->label: $s->setting_value → $v."];
    }
}

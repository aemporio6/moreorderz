<?php

namespace App\Services;

/**
 * RULES_G_CORE R-G_CORE-42 built-in guide "How Task Management works" (Help › Tasks, and "How tasks work" on the Tasks page):
 * the 8-step life of a task with its channel and a link to its screen, the ways a task is born, a sample WhatsApp message and the reply commands.
 * The same shape (one help page per module) can be used for every module.
 */
class TaskGuide
{
    public static function page(): array
    {
        $sample = AlertRules::render('tm_new_task', ['first_name' => 'Asad', 'task_ref' => 'T-0012', 'title' => 'Book the courier for PO-2611-0004', 'due' => 'Fri 06 Nov', 'link' => rtrim((string) config('app.url'), '/') . '/#/tasks']);
        return [
            'title' => 'How Task Management works',
            'steps' => [
                ['n' => 1, 'step' => 'Created', 'what' => 'A person, a template, a rule, a repeat or a CSV import makes the task with its owner, due date and proof rule.', 'channel' => 'Tasks page', 'link' => '#/tasks', 'link_label' => 'Tasks'],
                ['n' => 2, 'step' => 'Told', 'what' => 'The owner gets "New task" at once (an URGENT one even in quiet hours). He replies OK to acknowledge.', 'channel' => 'WhatsApp + bell', 'link' => '#/alerts', 'link_label' => 'Alert rules'],
                ['n' => 3, 'step' => 'Reminders', 'what' => 'Not acknowledged, due tomorrow, late, stuck, waiting approval, no update for a while – each on its own timer; one morning digest per person.', 'channel' => 'WhatsApp, e-mail, bell', 'link' => '#/alerts', 'link_label' => 'Alert rules & thresholds'],
                ['n' => 4, 'step' => 'Work + checklist', 'what' => 'Start, tick the checklist, write comments (@name tells a person), say "I am stuck" with what you wait for.', 'channel' => 'Task page or WhatsApp', 'link' => '#/tasks', 'link_label' => 'My tasks'],
                ['n' => 5, 'step' => 'Proof', 'what' => 'A screenshot, photo, file, link or reference – a voice note is not proof. Photos keep their time and place.', 'channel' => 'Task page (camera) or a WhatsApp photo', 'link' => '#/tasks', 'link_label' => 'My tasks'],
                ['n' => 6, 'step' => 'Done', 'what' => '"Mark done" checks the checklist, the subtasks and the proof first; then the task waits for approval.', 'channel' => 'Task page or WhatsApp DONE', 'link' => '#/tasks', 'link_label' => 'My tasks'],
                ['n' => 7, 'step' => 'Approve or rework', 'what' => 'The person who assigned it approves with a quality, or sends it back with the reason.', 'channel' => 'Waiting approval list or WhatsApp APPROVE 1–4 / REWORK', 'link' => '#/tasks?scope=to_approve', 'link_label' => 'To approve'],
                ['n' => 8, 'step' => 'Score', 'what' => 'On time, proof and quality make the score; the month leaderboard and the weekly report show them.', 'channel' => 'Team & settings, weekly e-mail', 'link' => '#/tasks/team', 'link_label' => 'Team & settings'],
            ],
            'born' => [
                ['how' => 'By a person', 'where' => 'Tasks › New task', 'link' => '#/tasks'],
                ['how' => 'From a template (by hand, or by itself when a PO is confirmed, a GRN posted, a month opened)', 'where' => 'Team & settings › Templates', 'link' => '#/task-templates'],
                ['how' => 'By a rule (a problem in the data – closes by itself when fixed)', 'where' => 'Tasks › Everyone (source Rule)', 'link' => '#/tasks?scope=all'],
                ['how' => 'By a repeat (daily, weekly, monthly, every n days)', 'where' => 'New task › Repeat', 'link' => '#/tasks'],
                ['how' => 'By a CSV import (new)', 'where' => 'Tasks › Import', 'link' => '#/tasks'],
            ],
            'sample' => $sample['text'],
            'commands' => [
                ['say' => 'OK  (or 👍)', 'does' => 'Acknowledge the task'], ['say' => 'START', 'does' => 'Start working'], ['say' => 'DONE', 'does' => 'Mark done (the proof must be there)'],
                ['say' => 'STUCK why', 'does' => 'I am stuck, with the reason'], ['say' => 'APPROVE 1–4', 'does' => 'Approve with quality 1 Poor … 4 Excellent'], ['say' => 'REWORK why', 'does' => 'Send it back with the reason'],
                ['say' => 'a photo or a file', 'does' => 'Kept as proof (a voice note is kept but is not proof)'], ['say' => 'any other text', 'does' => 'A comment on the task'],
                ['say' => 'T-0012 …', 'does' => 'Name the task when the message is not a reply'],
            ],
        ];
    }
}

<?php

/* MR-1542 release notes – newest first. Each release names the feature flags it brings (switchable per company on "What's new"). */
return [
    ['version' => '7e-222', 'date' => '2026-11-26', 'title' => 'Core part 2', 'items' => [
        'Demand forecast for the ICC: season from the same month last year and the trend of the last 90 days (Settings › Choices ICC_FORECAST).',
        'Deadline calendar: every filing, renewal and due date in one month, download for Google Calendar.',
        'Repeated returns: buyers and addresses with many returns or claims, a task each and a warning on their orders.',
        'Marketplace fee check: expected against charged fees per order, overcharges listed with a claim task.',
        'What\'s new page with switches to turn new parts on or off per company.'], 'flags' => ['RETURN_ABUSE', 'FEE_CHECK']],
    ['version' => '7e-221', 'date' => '2026-11-26', 'title' => 'Core part 1', 'items' => [
        'ICC – inventory cycle calculator: how much to order, when stock runs out, when to order again; reorder tasks; Home card.',
        'Data health score per company on Home.',
        'Daily database backup, monthly restore drill, both on System › Health.',
        'System errors (failed jobs, failing listeners, unknown events) become tasks.',
        'Home and "open in a new tab" buttons in the top bar.'], 'flags' => ['ICC', 'DATA_HEALTH']],
];

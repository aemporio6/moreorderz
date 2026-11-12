<?php

namespace App\Services\Connect;

/** No API: the shop's orders and money come in as files (upload, or a watched Drive folder). Works today for every marketplace. */
class FileOnly extends MarketplaceAdapter
{
    public function test(): string { return 'Files only: load the order and money files under Import a file (or let a watched Drive folder bring them).'; }

    public function orders(string $from, string $to): array { throw new ApiError('This shop is connected by files only: load its order file under Orders › Load order file.'); }

    public function money(string $from, string $to): ?array { return null; }
}

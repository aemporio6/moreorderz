<?php

namespace App\Services\Connect;

/** An outside service said no, or could not be reached. $auth = the keys were refused (the connection is marked EXPIRED). */
class ApiError extends \RuntimeException
{
    public function __construct(string $message, public bool $auth = false) { parent::__construct($message); }
}

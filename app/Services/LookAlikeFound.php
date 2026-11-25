<?php

namespace App\Services;

/** R-C-8: the save was stopped because the person looks like existing ones; $matches lists them. */
class LookAlikeFound extends \RuntimeException
{
    public function __construct(public array $matches) { parent::__construct('Looks like an existing person'); }
}

<?php

namespace App\Services;

/** Field errors from a service: [field => message]; controllers answer 422 with them. */
class ValidationErrors extends \InvalidArgumentException
{
    public function __construct(public array $errors) { parent::__construct((string) reset($errors)); }
}

<?php

namespace App\Services;

/** A duplicate supplier + order reference that was not forced with a reason (R-B_SOURCING-7). */
class SrcDuplicateException extends \InvalidArgumentException
{
    public function __construct(public object $existing, string $message) { parent::__construct($message); }

    public function payload(): array
    {
        return ['message' => $this->getMessage(), 'duplicate' => ['id' => (int) $this->existing->id, 'code' => $this->existing->cycle_code], 'errors' => ['order_ref' => [$this->getMessage()], '_dup' => [$this->existing->cycle_code . '|' . $this->existing->id]]];
    }
}

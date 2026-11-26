<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as Base;

/** R-G_CORE-121 command line: an error prints the same short text and its id; the details are in sys_error_log (add -v to see them while fixing). */
class Handler extends Base
{
    public function renderForConsole($output, \Throwable $e)
    {
        if ($output->isVerbose() || $e instanceof \Symfony\Component\Console\Exception\ExceptionInterface) { parent::renderForConsole($output, $e); return; }      /* a mistyped command still explains itself */
        $output->writeln(\App\Services\ErrorPages::cli($e));
    }
}

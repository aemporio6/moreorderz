<?php

namespace App\Services\Connect;

/** Print connections (helper token, PrintNode key) are used by the print engine; "Test" says how to check them. */
class PrintLink extends Adapter
{
    public function test(): string
    {
        if ($this->conn->adapter === 'PRINTNODE') {
            $this->need('api_key');
            $j = Api::ok($this->call('GET', 'https://api.printnode.com/whoami', ['basic' => [$this->s['api_key'], '']]), 'PrintNode');
            return 'PrintNode answered: account ' . ($j['email'] ?? $j['id'] ?? '');
        }
        $this->need('agent_token');
        return $this->conn->last_sync_at ? 'The print helper last reported at ' . $this->conn->last_sync_at . '.' : 'Token saved. Start the helper on the packing PC and print a test page on a "print helper" printer.';
    }
}

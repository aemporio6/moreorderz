<?php

namespace App\Services\Connect;

/**
 * One adapter = one way of talking to one kind of outside service. Which adapter a connection uses is data (api_connections.adapter);
 * nothing else in the tool asks which marketplace or carrier it is (IC-0).
 * $s = the connection's secrets (from the vault), $cfg = its plain settings. An adapter may hand back new tokens through $this->renewed.
 */
abstract class Adapter
{
    public ?array $renewed = null;

    public function __construct(protected object $conn, protected array $s, protected array $cfg) {}

    /** A cheap call that proves the keys work. Returns a short text for the screen. */
    abstract public function test(): string;

    protected function need(string ...$keys): void
    {
        $miss = array_values(array_filter($keys, fn ($k) => trim((string) ($this->s[$k] ?? $this->cfg[$k] ?? '')) === ''));
        if ($miss) throw new ApiError('The connection is missing: ' . implode(', ', $miss) . '. Open the connection and fill them in.');
    }

    protected function call(string $method, string $url, array $opt = []): \Illuminate\Http\Client\Response
    {
        return Api::call($this->conn, (string) $this->conn->adapter, $method, $url, $opt);
    }
}

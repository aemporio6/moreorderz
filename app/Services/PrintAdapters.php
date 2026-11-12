<?php

namespace App\Services;

use App\Services\Connect\Api;
use App\Services\Connect\Connections;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * One-click printing: the PDF the print engine made (exact size, calibrated) goes to the printer without the browser's print window.
 *  - IPP       network printers that take PDF (IPP / IPP Everywhere / AirPrint, e.g. the KYOCERA): an IPP Print-Job to the printer's address (ipp://host:631/ipp/print).
 *  - RAW_TSPL / RAW_ZPL / RAW_EPL  thermal label printers on the network (e.g. MUNBYN = TSPL): the page is turned into a 1-bit picture at the printer's dpi
 *              (pdftoppm) and sent as the printer's own bitmap command to port 9100.
 *  - PRINT_AGENT  printers on the packing PC (USB): the job waits for the print helper (tools/print-agent) that runs on that PC and prints it silently.
 *  - CLOUD     a cloud print service (PrintNode): the PDF goes to the service, which prints on the PC where its client runs.
 * The printer's address is printer_profiles.network_address. RAW_OPL printers: use their TSPL / EPL emulation.
 * Built to the protocols; the server must be able to reach a network printer (same network or VPN). Check every printer with its test page first.
 */
class PrintAdapters
{
    public const DIRECT = ['IPP', 'RAW_TSPL', 'RAW_ZPL', 'RAW_EPL', 'PRINT_AGENT', 'CLOUD'];

    /** @return string what happened, for the job row */
    public static function send(string $adapter, object $printer, string $pdfPath, int $copies, int $jobId, float $wMm, float $hMm): string
    {
        return match ($adapter) {
            'IPP' => self::ipp((string) $printer->network_address, $pdfPath, $copies),
            'RAW_TSPL', 'RAW_ZPL', 'RAW_EPL' => self::raw(substr($adapter, 4), $printer, $pdfPath, $copies, $wMm, $hMm),
            'PRINT_AGENT' => 'Waiting for the print helper on the packing PC',
            'CLOUD' => self::cloud($printer, $pdfPath, $copies, $jobId),
            default => throw new \RuntimeException("Adapter $adapter cannot print by itself: use \"PDF through the printer driver\" or TSPL / ZPL / EPL emulation."),
        };
    }

    // ---------- IPP ----------
    private static function attr(int $tag, string $name, string $value): string
    {
        return chr($tag) . pack('n', strlen($name)) . $name . pack('n', strlen($value)) . $value;
    }

    public static function ippRequest(string $uri, int $copies, string $pdf): string
    {
        $b = "\x01\x01" . pack('n', 0x0002) . pack('N', 1) . "\x01";
        $b .= self::attr(0x47, 'attributes-charset', 'utf-8') . self::attr(0x48, 'attributes-natural-language', 'en') . self::attr(0x45, 'printer-uri', $uri)
            . self::attr(0x42, 'requesting-user-name', 'moreorderz') . self::attr(0x42, 'job-name', 'MoreOrderz') . self::attr(0x49, 'document-format', 'application/pdf');
        $b .= "\x02" . self::attr(0x21, 'copies', pack('N', max(1, $copies))) . "\x03";
        return $b . $pdf;
    }

    private static function ipp(string $address, string $pdfPath, int $copies): string
    {
        if (! preg_match('~^(ipps?|https?)://~i', $address)) throw new \RuntimeException('Give the printer its IPP address, e.g. ipp://192.168.1.20:631/ipp/print (Printers › edit › network address).');
        $uri = $address; $http = preg_replace(['~^ipp://~i', '~^ipps://~i'], ['http://', 'https://'], $address);
        if (! parse_url($http, PHP_URL_PORT) && str_starts_with(strtolower($address), 'ipp')) $http = preg_replace('~^(https?://[^/]+)~', '$1:631', $http);
        $r = Api::call(null, 'IPP', 'POST', $http, ['headers' => ['Content-Type' => 'application/ipp'], 'raw' => self::ippRequest($uri, $copies, (string) file_get_contents($pdfPath)), 'timeout' => 30]);
        $body = $r->body();
        if ($r->failed() || strlen($body) < 4) throw new \RuntimeException('The printer did not take the job (HTTP ' . $r->status() . ').');
        $status = unpack('n', substr($body, 2, 2))[1];
        if ($status > 0x00FF) throw new \RuntimeException(sprintf('The printer refused the job (IPP status 0x%04X).', $status));
        return 'Sent to the printer over IPP';
    }

    // ---------- raw thermal ----------
    /** PDF page → rows of a 1-bit picture: [widthBytes, height, binary rows with 1 = black]. */
    public static function bitmap(string $pdfPath, int $dpi): array
    {
        $base = tempnam(sys_get_temp_dir(), 'mo-bmp-');
        $p = new Process(['pdftoppm', '-mono', '-r', (string) $dpi, '-f', '1', '-l', '1', $pdfPath, $base]); $p->setTimeout(60); $p->run();
        $file = collect(glob($base . '*.pbm'))->first();
        if (! $p->isSuccessful() || ! $file) throw new \RuntimeException('The label could not be turned into a picture for the printer.');
        $data = (string) file_get_contents($file); @unlink($file); @unlink($base);
        if (! preg_match('/^P4\s+(?:#[^\n]*\n\s*)*(\d+)\s+(\d+)\s/', $data, $m)) throw new \RuntimeException('Picture format not understood.');
        $w = (int) $m[1]; $h = (int) $m[2]; $bytes = intdiv($w + 7, 8);
        return [$bytes, $h, substr($data, strlen($m[0]), $bytes * $h), $w];
    }

    public static function rawCommands(string $lang, array $bmp, int $copies, float $wMm, float $hMm): string
    {
        [$bytes, $h, $rows] = $bmp;
        return match ($lang) {
            // TSPL BITMAP: a 0 bit prints a dot, so the picture is inverted
            'TSPL' => sprintf("SIZE %.1f mm,%.1f mm\r\nGAP 2 mm,0 mm\r\nDIRECTION 1\r\nCLS\r\nBITMAP 0,0,%d,%d,0,", $wMm, $hMm, $bytes, $h) . ($rows ^ str_repeat("\xFF", strlen($rows))) . sprintf("\r\nPRINT 1,%d\r\n", max(1, $copies)),
            // ZPL ^GFA: hex, 1 = black
            'ZPL' => sprintf("^XA^PW%d^LL%d^FO0,0^GFA,%d,%d,%d,%s^FS^PQ%d^XZ", $bytes * 8, $h, strlen($rows), strlen($rows), $bytes, strtoupper(bin2hex($rows)), max(1, $copies)),
            // EPL GW: a 0 bit prints a dot
            'EPL' => sprintf("\r\nN\r\nq%d\r\nQ%d,24\r\nGW0,0,%d,%d,", $bytes * 8, $h, $bytes, $h) . ($rows ^ str_repeat("\xFF", strlen($rows))) . sprintf("\r\nP%d\r\n", max(1, $copies)),
            default => throw new \RuntimeException("Unknown printer language $lang."),
        };
    }

    private static function raw(string $lang, object $printer, string $pdfPath, int $copies, float $wMm, float $hMm): string
    {
        $addr = trim((string) $printer->network_address);
        if (! preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $addr)) throw new \RuntimeException('Give the printer its network address, e.g. 192.168.1.30 or 192.168.1.30:9100 (Printers › edit).');
        [$host, $port] = array_pad(explode(':', $addr), 2, 9100);
        $cmd = self::rawCommands($lang, self::bitmap($pdfPath, (int) ($printer->dpi ?: 203)), $copies, $wMm, $hMm);
        $sock = @stream_socket_client("tcp://$host:$port", $errno, $err, 5);
        if (! $sock) throw new \RuntimeException("The printer at $host:$port cannot be reached ($err).");
        stream_set_timeout($sock, 15); $sent = fwrite($sock, $cmd); fclose($sock);
        if ($sent !== strlen($cmd)) throw new \RuntimeException('The printer took only part of the job.');
        return "Sent to the printer as $lang";
    }

    // ---------- cloud (PrintNode) ----------
    private static function cloud(object $printer, string $pdfPath, int $copies, int $jobId): string
    {
        $c = DB::table('api_connections')->where('adapter', 'PRINTNODE')->whereIn('fk_id_ent', [(int) $printer->fk_id_ent, 0])->whereNotIn('status', ['OFF'])->first()
            ?? throw new \RuntimeException('Set up the PrintNode connection first (System › API connections).');
        $key = Connections::secrets($c)['api_key'] ?? throw new \RuntimeException('The PrintNode connection has no API key.');
        if (! ctype_digit(trim((string) $printer->network_address))) throw new \RuntimeException('Give the printer its PrintNode printer number (Printers › edit › network address).');
        $r = Api::call($c, 'PRINTNODE', 'POST', 'https://api.printnode.com/printjobs', ['basic' => [$key, ''], 'json' => ['printerId' => (int) $printer->network_address, 'title' => 'MoreOrderz job ' . $jobId,
            'contentType' => 'pdf_base64', 'content' => base64_encode((string) file_get_contents($pdfPath)), 'source' => 'MoreOrderz', 'qty' => max(1, $copies)]]);
        if ($r->failed()) throw new \RuntimeException('PrintNode refused the job – ' . Api::errorText($r));
        return 'Sent to PrintNode (job ' . trim($r->body()) . ')';
    }

    // ---------- print helper on the packing PC ----------
    /** The helper asks for the next job of its printers. Auth: the helper's token (connection PRINT_AGENT). */
    public static function agentNext(string $token): ?array
    {
        $c = self::agentConnection($token);
        $job = DB::table('print_jobs as j')->join('printer_profiles as p', 'p.id', '=', 'j.fk_id_printer_profile')->join('print_adapters as a', 'a.id', '=', 'p.fk_id_adapter')
            ->where('a.code', 'PRINT_AGENT')->where('j.status', 'AGENT')->where('j.fk_id_ent', $c->fk_id_ent ?: DB::raw('j.fk_id_ent'))->orderBy('j.id')->first(['j.id', 'j.job_code', 'j.file_ref', 'j.copies', 'p.os_printer_name', 'p.name']);
        if (! $job) return null;
        DB::table('print_jobs')->where('id', $job->id)->update(['status' => 'AGENT_TOOK']);
        $path = storage_path('app/' . $job->file_ref);
        return ['job' => $job->id, 'code' => $job->job_code, 'printer' => $job->os_printer_name ?: $job->name, 'copies' => (int) $job->copies, 'pdf_base64' => base64_encode((string) @file_get_contents($path))];
    }

    public static function agentDone(string $token, int $jobId, bool $ok, ?string $error): void
    {
        $c = self::agentConnection($token);
        $job = DB::table('print_jobs')->where('id', $jobId)->whereIn('status', ['AGENT', 'AGENT_TOOK'])->first() ?? throw new \InvalidArgumentException('Job not found.');
        DB::table('print_jobs')->where('id', $jobId)->update($ok ? ['status' => 'PRINTED', 'printed_at' => now(), 'error_text' => 'Printed by the print helper'] : ['status' => 'FAILED', 'error_text' => mb_substr('Print helper: ' . ($error ?: 'failed'), 0, 255)]);
        DB::table('api_connections')->where('id', $c->id)->update(['last_sync_at' => now(), 'status' => 'CONNECTED']);
        if (! $ok) TaskEngine::open('WH_PRINT_FAILED', [(int) $job->fk_id_printer_profile, date('Y-m-d')], 'The print helper could not print job ' . $job->job_code . ': ' . mb_substr((string) $error, 0, 120), ['ent' => (int) $job->fk_id_ent, 'fix_url' => '#/print-queue']);
    }

    private static function agentConnection(string $token): object
    {
        foreach (DB::table('api_connections')->where('adapter', 'PRINT_AGENT')->whereNotIn('status', ['OFF'])->get() as $c)
            if (($t = Connections::secrets($c)['agent_token'] ?? '') !== '' && hash_equals($t, $token)) return $c;
        throw new \Illuminate\Auth\Access\AuthorizationException('Unknown print helper.');
    }
}

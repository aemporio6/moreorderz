<?php

namespace App\Services\Connect;

/**
 * Google Drive API v3 with a service account, built to Google's published contract (developers.google.com/drive/api):
 *  - token:   POST https://oauth2.googleapis.com/token  grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer, assertion = RS256 JWT signed with the service account key
 *  - folder:  POST https://www.googleapis.com/drive/v3/files?supportsAllDrives=true {name, mimeType: application/vnd.google-apps.folder, parents[]} → id, webViewLink
 *  - list:    GET  https://www.googleapis.com/drive/v3/files?q='<folder>' in parents and trashed=false&fields=files(id,name,mimeType,modifiedTime,size)
 *  - file:    GET  https://www.googleapis.com/drive/v3/files/<id>?alt=media   (Google Sheets are exported as .xlsx through /export)
 * The folders must be shared with the service account's e-mail. Secrets: service_account_json (the whole key file). Settings: root_folder_id.
 * Built to contract and tested with Google-shaped answers; NOT yet run against the owner's Drive.
 */
class GoogleDrive extends Adapter
{
    private const SCOPE = 'https://www.googleapis.com/auth/drive';

    private function key(): array
    {
        $this->need('service_account_json');
        $k = json_decode((string) $this->s['service_account_json'], true);
        if (empty($k['client_email']) || empty($k['private_key'])) throw new ApiError('The service account key is not a Google key file (client_email / private_key missing).');
        return $k;
    }

    public static function jwt(array $key, int $now): string
    {
        $b = fn ($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
        $head = $b(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])); $claim = $b(json_encode(['iss' => $key['client_email'], 'scope' => self::SCOPE, 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
        if (! openssl_sign("$head.$claim", $sig, $key['private_key'], OPENSSL_ALGO_SHA256)) throw new ApiError('The service account key could not sign (private_key is damaged).');
        return "$head.$claim." . $b($sig);
    }

    private function token(): string
    {
        if (! empty($this->s['access_token']) && (int) ($this->s['access_expires'] ?? 0) > time() + 60) return $this->s['access_token'];
        $j = Api::ok($this->call('POST', 'https://oauth2.googleapis.com/token', ['form' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => self::jwt($this->key(), time())]]), 'Google sign-in');
        $this->s['access_token'] = $j['access_token'] ?? throw new ApiError('Google sign-in gave no access token.');
        $this->s['access_expires'] = time() + (int) ($j['expires_in'] ?? 3600);
        $this->renewed = ['access_token' => $this->s['access_token'], 'access_expires' => $this->s['access_expires']];
        return $this->s['access_token'];
    }

    private function h(): array { return ['Authorization' => 'Bearer ' . $this->token()]; }

    public function test(): string
    {
        $root = $this->cfg['root_folder_id'] ?? '';
        if ($root === '') { Api::ok($this->call('GET', 'https://www.googleapis.com/drive/v3/about', ['headers' => $this->h(), 'query' => ['fields' => 'user']]), 'Google Drive'); return 'Google Drive accepted the key. Set the main folder id to make folders in it.'; }
        $j = Api::ok($this->call('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($root), ['headers' => $this->h(), 'query' => ['fields' => 'id,name', 'supportsAllDrives' => 'true']]), 'Google Drive main folder');
        return 'Google Drive answered: main folder "' . ($j['name'] ?? '') . '".';
    }

    /** @return array{id: string, url: string} */
    public function folder(string $name, ?string $parent = null): array
    {
        $parent = $parent ?: ($this->cfg['root_folder_id'] ?? null);
        $j = Api::ok($this->call('POST', 'https://www.googleapis.com/drive/v3/files', ['headers' => $this->h(), 'query' => ['supportsAllDrives' => 'true', 'fields' => 'id,webViewLink'],
            'json' => ['name' => mb_substr($name, 0, 200), 'mimeType' => 'application/vnd.google-apps.folder'] + ($parent ? ['parents' => [$parent]] : [])]), 'Google Drive folder');
        return ['id' => (string) $j['id'], 'url' => (string) ($j['webViewLink'] ?? 'https://drive.google.com/drive/folders/' . $j['id'])];
    }

    /** Files directly in a folder (newest first). */
    public function files(string $folderId): array
    {
        $j = Api::ok($this->call('GET', 'https://www.googleapis.com/drive/v3/files', ['headers' => $this->h(), 'query' => ['q' => "'" . str_replace("'", '', $folderId) . "' in parents and trashed=false",
            'fields' => 'files(id,name,mimeType,modifiedTime,size)', 'orderBy' => 'modifiedTime desc', 'pageSize' => 100, 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true']]), 'Google Drive folder list');
        return $j['files'] ?? [];
    }

    /** Download a file to a local temp path; a Google Sheet is exported as .xlsx. Returns [path, name]. */
    public function download(array $file): array
    {
        $sheet = ($file['mimeType'] ?? '') === 'application/vnd.google-apps.spreadsheet';
        $r = $this->call('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file['id']) . ($sheet ? '/export' : ''), ['headers' => $this->h(), 'timeout' => 120,
            'query' => $sheet ? ['mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] : ['alt' => 'media', 'supportsAllDrives' => 'true']]);
        if ($r->failed()) throw new ApiError('Google Drive download of ' . ($file['name'] ?? '') . ' failed – ' . Api::errorText($r));
        $name = (string) $file['name'] . ($sheet ? '.xlsx' : ''); $path = tempnam(sys_get_temp_dir(), 'mo-drv-') . '.' . (pathinfo($name, PATHINFO_EXTENSION) ?: 'csv');
        file_put_contents($path, $r->body());
        return [$path, $name];
    }
}

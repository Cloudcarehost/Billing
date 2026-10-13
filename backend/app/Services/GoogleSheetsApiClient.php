<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleSheetsApiClient implements GoogleSheetsWriter
{
    public function rewriteWorkbook(string $spreadsheetId, array $tabs): void
    {
        $http = $this->authorized();
        $existing = $this->sheetTitles($http, $spreadsheetId);
        $missing = array_values(array_filter(array_keys($tabs), fn (string $title) => ! in_array($title, $existing, true)));
        if ($missing !== []) {
            $http->post($this->spreadsheetUrl($spreadsheetId).':batchUpdate', [
                'requests' => array_map(fn (string $title) => ['addSheet' => ['properties' => ['title' => $title]]], $missing),
            ])->throw();
        }

        $ranges = array_map(fn (string $title) => $this->quoted($title).'!A:Z', array_keys($tabs));
        $http->post($this->spreadsheetUrl($spreadsheetId).'/values:batchClear', ['ranges' => $ranges])->throw();
        $http->post($this->spreadsheetUrl($spreadsheetId).'/values:batchUpdate', [
            'valueInputOption' => 'RAW',
            'data' => array_map(fn (string $title, array $values) => [
                'range' => $this->quoted($title).'!A1',
                'majorDimension' => 'ROWS',
                'values' => $values,
            ], array_keys($tabs), $tabs),
        ])->throw();
    }

    private function authorized(): PendingRequest
    {
        return Http::baseUrl('https://sheets.googleapis.com/v4')
            ->withToken($this->accessToken())
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * @return list<string>
     */
    private function sheetTitles(PendingRequest $http, string $spreadsheetId): array
    {
        $response = $http->get($this->spreadsheetUrl($spreadsheetId), ['fields' => 'sheets.properties.title'])->throw()->json();
        $titles = [];
        foreach ($response['sheets'] ?? [] as $sheet) {
            $title = $sheet['properties']['title'] ?? null;
            if (is_string($title) && $title !== '') {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    private function spreadsheetUrl(string $spreadsheetId): string
    {
        return '/spreadsheets/'.$spreadsheetId;
    }

    private function quoted(string $title): string
    {
        return "'".str_replace("'", "''", $title)."'";
    }

    private function accessToken(): string
    {
        $cached = Cache::get('google_sheets_access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = $this->credentials();
        $now = time();
        $jwt = $this->jwt([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], $credentials['private_key']);

        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ])->throw()->json();

        $token = $response['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Google Sheets did not return an access token.');
        }

        $ttl = max(60, ((int) ($response['expires_in'] ?? 3600)) - 60);
        Cache::put('google_sheets_access_token', $token, $ttl);

        return $token;
    }

    /** @return array{client_email: string, private_key: string} */
    private function credentials(): array
    {
        $path = (string) config('google_sheets.credentials_path');
        if ($path === '' || ! is_readable($path)) {
            throw new RuntimeException('Google Sheets service-account JSON was not found.');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $email = is_array($decoded) ? ($decoded['client_email'] ?? null) : null;
        $key = is_array($decoded) ? ($decoded['private_key'] ?? null) : null;
        if (! is_string($email) || $email === '' || ! is_string($key) || $key === '') {
            throw new RuntimeException('Google Sheets credentials JSON is missing client_email or private_key.');
        }

        return ['client_email' => $email, 'private_key' => $key];
    }

    /** @param  array<string, mixed>  $payload */
    private function jwt(array $payload, string $privateKey): string
    {
        $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $this->b64url(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $header.'.'.$body;
        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $ok || ! is_string($signature)) {
            throw new RuntimeException('Could not sign the Google Sheets service-account token.');
        }

        return $signingInput.'.'.$this->b64url($signature);
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

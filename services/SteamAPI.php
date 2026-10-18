<?php
declare(strict_types=1);

class SteamAPI
{
    private const API_HOST = 'https://api.steampowered.com';
    private const OPENID_ENDPOINT = 'https://steamcommunity.com/openid/login';
    private const TIMEOUT_SECONDS = 10;

    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $config = require __DIR__ . '/../config/config.php';
        $this->apiKey = (string) $config['steam_api_key'];
        $this->baseUrl = (string) $config['base_url'];
    }

    public function getLoginUrl(): string
    {
        $params = [
            'openid.ns'         => 'http://specs.openid.net/auth/2.0',
            'openid.mode'       => 'checkid_setup',
            'openid.return_to'  => $this->baseUrl . '/api/auth.php',
            'openid.realm'      => $this->baseUrl,
            'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
        ];

        return self::OPENID_ENDPOINT . '?' . http_build_query($params);
    }

    /**
     * Verify a Steam OpenID assertion and return the 64-bit Steam ID.
     *
     * Returns null when the response is not a successful assertion, when the
     * signature does not cover claimed_id, or when Steam rejects it.
     */
    public function validateLogin(array $params): ?string
    {
        if (($params['openid_mode'] ?? '') !== 'id_res') {
            return null;
        }

        $required = ['openid_assoc_handle', 'openid_signed', 'openid_sig', 'openid_claimed_id'];
        foreach ($required as $key) {
            if (!isset($params[$key]) || !is_string($params[$key]) || $params[$key] === '') {
                return null;
            }
        }

        // Without this check an attacker can supply any claimed_id while
        // Steam only signs the fields it was asked to sign.
        $signedFields = array_map('trim', explode(',', $params['openid_signed']));
        if (!in_array('claimed_id', $signedFields, true)) {
            return null;
        }

        $validateParams = [
            'openid.ns'           => 'http://specs.openid.net/auth/2.0',
            'openid.mode'         => 'check_authentication',
            'openid.assoc_handle' => $params['openid_assoc_handle'],
            'openid.signed'       => $params['openid_signed'],
            'openid.sig'          => $params['openid_sig'],
        ];

        foreach ($signedFields as $field) {
            $key = 'openid_' . str_replace('.', '_', $field);
            if (isset($params[$key]) && is_string($params[$key])) {
                $validateParams['openid.' . $field] = $params[$key];
            }
        }

        $result = $this->post(self::OPENID_ENDPOINT, http_build_query($validateParams));

        if ($result === null || !preg_match('/^is_valid\s*:\s*true/im', $result)) {
            return null;
        }

        if (!preg_match('#^https://steamcommunity\.com/openid/id/(\d{17,25})$#', $params['openid_claimed_id'], $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function post(string $url, string $body): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $status !== 200) {
            error_log(sprintf('Steam OpenID validation failed: status=%d error=%s', $status, $error));
            return null;
        }

        return (string) $result;
    }

    private function makeApiCall(string $endpoint, array $params = []): ?array
    {
        if ($this->apiKey === '' || $this->apiKey === 'YOUR_STEAM_API_KEY_HERE') {
            error_log('STEAM_API_KEY is not configured; refusing to call the Steam Web API.');
            return null;
        }

        $params['key'] = $this->apiKey;
        $url = self::API_HOST . $endpoint . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status !== 200) {
            error_log(sprintf('Steam API call failed: %s status=%d error=%s', $endpoint, $status, $error));
            return null;
        }

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function getPlayerSummary(string $steamId): ?array
    {
        $res = $this->makeApiCall('/ISteamUser/GetPlayerSummaries/v0002/', ['steamids' => $steamId]);
        return $res['response']['players'][0] ?? null;
    }

    public function getOwnedGames(string $steamId): array
    {
        $res = $this->makeApiCall('/IPlayerService/GetOwnedGames/v0001/', [
            'steamid' => $steamId,
            'include_appinfo' => 1,
            'include_played_free_games' => 1,
            'format' => 'json',
        ]);
        return $res['response']['games'] ?? [];
    }

    public function getRecentlyPlayedGames(string $steamId): array
    {
        $res = $this->makeApiCall('/IPlayerService/GetRecentlyPlayedGames/v0001/', [
            'steamid' => $steamId,
            'format' => 'json',
        ]);
        return $res['response']['games'] ?? [];
    }

    public function getPlayerBans(string $steamId): ?array
    {
        $res = $this->makeApiCall('/ISteamUser/GetPlayerBans/v1/', ['steamids' => $steamId]);
        return $res['players'][0] ?? null;
    }
}

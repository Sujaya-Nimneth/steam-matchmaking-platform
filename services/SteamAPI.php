<?php
// services/SteamAPI.php

class SteamAPI {
    private $apiKey;
    private $baseUrl;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->apiKey = $config['steam_api_key'];
        $this->baseUrl = $config['base_url'];
    }

    public function getLoginUrl() {
        $login_url_params = [
            'openid.ns'         => 'http://specs.openid.net/auth/2.0',
            'openid.mode'       => 'checkid_setup',
            'openid.return_to'  => $this->baseUrl . '/api/auth.php',
            'openid.realm'      => $this->baseUrl,
            'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
        ];
        return 'https://steamcommunity.com/openid/login?' . http_build_query($login_url_params);
    }

    public function validateLogin($params) {
        // Construct validation request
        $validate_params = [
            'openid.assoc_handle' => $params['openid_assoc_handle'],
            'openid.signed'       => $params['openid_signed'],
            'openid.sig'          => $params['openid_sig'],
            'openid.ns'           => 'http://specs.openid.net/auth/2.0',
            'openid.mode'         => 'check_authentication',
        ];

        $signed = explode(',', $params['openid_signed']);
        foreach ($signed as $item) {
            $val = $params['openid_' . str_replace('.', '_', $item)];
            $validate_params['openid.' . $item] = $val;
        }

        $ch = curl_init('https://steamcommunity.com/openid/login');
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($validate_params));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        if (preg_match('/is_valid\s*:\s*true/i', $result)) {
            preg_match('#^https?://steamcommunity.com/openid/id/([0-9]{17,25})#', $params['openid_claimed_id'], $matches);
            return $matches[1] ?? null;
        }
        return null;
    }

    private function makeApiCall($endpoint, $params = []) {
        $params['key'] = $this->apiKey;
        $url = $endpoint . '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    public function getPlayerSummary($steamId) {
        $res = $this->makeApiCall('http://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/', [
            'steamids' => $steamId
        ]);
        return $res['response']['players'][0] ?? null;
    }

    public function getOwnedGames($steamId) {
        $res = $this->makeApiCall('http://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
            'steamid' => $steamId,
            'include_appinfo' => 1,
            'include_played_free_games' => 1,
            'format' => 'json'
        ]);
        return $res['response']['games'] ?? [];
    }

    public function getRecentlyPlayedGames($steamId) {
        $res = $this->makeApiCall('http://api.steampowered.com/IPlayerService/GetRecentlyPlayedGames/v0001/', [
            'steamid' => $steamId,
            'format' => 'json'
        ]);
        return $res['response']['games'] ?? [];
    }

    public function getPlayerBans($steamId) {
        $res = $this->makeApiCall('http://api.steampowered.com/ISteamUser/GetPlayerBans/v1/', [
            'steamids' => $steamId
        ]);
        return $res['players'][0] ?? null;
    }
}

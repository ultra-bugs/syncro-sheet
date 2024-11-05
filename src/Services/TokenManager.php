<?php
/*
 *          M""""""""`M            dP
 *          Mmmmmm   .M            88
 *          MMMMP  .MMM  dP    dP  88  .dP   .d8888b.
 *          MMP  .MMMMM  88    88  88888"    88'  `88
 *          M' .MMMMMMM  88.  .88  88  `8b.  88.  .88
 *          M         M  `88888P'  dP   `YP  `88888P'
 *          MMMMMMMMMMM    -*-  Created by Zuko  -*-
 *
 *          * * * * * * * * * * * * * * * * * * * * *
 *          * -    - -   F.R.E.E.M.I.N.D   - -    - *
 *          * -  Copyright © 2024 (Z) Programing  - *
 *          *    -  -  All Rights Reserved  -  -    *
 *          * * * * * * * * * * * * * * * * * * * * *
 */

namespace Zuko\SyncroSheet\Services;

use Illuminate\Support\Facades\Cache;
use Revolution\Google\Sheets\Facades\Sheets;

class TokenManager
{
    private const CACHE_KEY = 'google_sheets_token';

    private const TOKEN_BUFFER = 300; // 5 minutes buffer before expiration

    /**
     * Check and refresh token if needed
     */
    public function refreshIfNeeded(): void
    {
        if ($this->shouldRefreshToken()) {
            $this->refreshToken();
        }
    }

    /**
     * Store new token data
     */
    public function storeToken(array $tokenData): void
    {
        Cache::put(self::CACHE_KEY, [
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'expires_at' => time() + ($tokenData['expires_in'] ?? 3600),
        ], now()->addDays(7));
    }

    private function shouldRefreshToken(): bool
    {
        $tokenData = Cache::get(self::CACHE_KEY);

        if (! $tokenData) {
            return true;
        }

        return ($tokenData['expires_at'] - self::TOKEN_BUFFER) <= time();
    }

    private function refreshToken(): void
    {
        $client = Sheets::getClient();
        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken();
            $this->storeToken($client->getAccessToken());
        }
    }
}

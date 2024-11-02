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

use Revolution\Google\Sheets\Facades\Sheets;
use Google\Client as GoogleAPIClient;
use Google\Service\Sheets as GoogleSheets;
use Zuko\SyncroSheet\Exceptions\GoogleSheetsException;

class GoogleClient
{
    private array $rateLimits = [];
    private $sheetsClient = null;

    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly SyncLogger $logger
    ) {}

    /**
     * Get or initialize the Sheets client
     */
    private function getClient()
    {
        if ($this->sheetsClient) {
            return $this->sheetsClient;
        }

        if (app()->bound(GoogleAPIClient::class)) {
            // Laravel environment - use facade with token
            $token = $this->tokenManager->getToken();
            $this->sheetsClient = Sheets::setAccessToken($token);
        } else {
            // Non-Laravel environment - manual setup using config
            $client = new GoogleAPIClient($this->getGoogleConfig());
            
            // Set required scopes for sheets
            $client->setScopes([GoogleSheets::DRIVE, GoogleSheets::SPREADSHEETS]);
            
            // Handle authentication based on config
            if (config('google.service.enable')) {
                $this->setupServiceAccount($client);
            } else {
                $this->setupOAuth($client);
            }
            
            $service = new GoogleSheets($client);
            $this->sheetsClient = Sheets::setService($service);
        }

        return $this->sheetsClient;
    }

    /**
     * Get Google client configuration
     */
    private function getGoogleConfig(): array
    {
        $config = config('google.config', []);
        
        // Add basic configuration
        $config['application_name'] = config('google.application_name');
        
        if (config('google.developer_key')) {
            $config['developer_key'] = config('google.developer_key');
        }

        return $config;
    }

    /**
     * Setup service account authentication
     */
    private function setupServiceAccount(GoogleAPIClient $client): void
    {
        $serviceAccountFile = config('google.service.file');
        
        if (is_array($serviceAccountFile)) {
            $client->setAuthConfig($serviceAccountFile);
        } elseif (file_exists($serviceAccountFile)) {
            $client->setAuthConfig($serviceAccountFile);
        } else {
            throw new GoogleSheetsException('Service account configuration file not found');
        }
    }

    /**
     * Setup OAuth authentication
     */
    private function setupOAuth(GoogleAPIClient $client): void
    {
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setRedirectUri(config('google.redirect_uri'));
        $client->setAccessType(config('google.access_type', 'online'));
        $client->setApprovalPrompt(config('google.approval_prompt', 'auto'));
        
        // Set the token if available
        $token = $this->tokenManager->getToken();
        if ($token) {
            $client->setAccessToken($token);
        }
    }

    /**
     * Write a batch of rows to Google Sheets
     */
    public function writeBatch(string $spreadsheetId, string $sheetName, array $rows): void
    {
        $this->checkRateLimit();

        try {
            $this->getClient()
                ->spreadsheet($spreadsheetId)
                ->sheet($sheetName)
                ->append($rows);

            $this->updateRateLimit();
            
            $this->logger->info("Written {count($rows)} rows to sheet {$sheetName}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to write to Google Sheets: {$e->getMessage()}");
            throw new GoogleSheetsException("Failed to write to Google Sheets: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Clear sheet content
     */
    public function clearSheet(string $spreadsheetId, string $sheetName): void
    {
        $this->checkRateLimit();

        try {
            $this->getClient()
                ->spreadsheet($spreadsheetId)
                ->sheet($sheetName)
                ->clear();

            $this->updateRateLimit();
            
            $this->logger->info("Cleared sheet {$sheetName}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to clear sheet: {$e->getMessage()}");
            throw new GoogleSheetsException("Failed to clear sheet: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Read all values from a sheet
     */
    public function readSheet(string $spreadsheetId, string $sheetName): array
    {
        $this->checkRateLimit();

        try {
            $values = $this->getClient()
                ->spreadsheet($spreadsheetId)
                ->sheet($sheetName)
                ->all();

            $this->updateRateLimit();
            
            return $values;
        } catch (\Exception $e) {
            $this->logger->error("Failed to read from Google Sheets: {$e->getMessage()}");
            throw new GoogleSheetsException("Failed to read from Google Sheets: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get headers from the sheet
     */
    public function getHeaders(string $spreadsheetId, string $sheetName): array
    {
        $this->checkRateLimit();

        try {
            $rows = $this->getClient()
                ->spreadsheet($spreadsheetId)
                ->sheet($sheetName)
                ->all();

            $this->updateRateLimit();
            
            return $rows[0] ?? [];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get headers: {$e->getMessage()}");
            throw new GoogleSheetsException("Failed to get headers: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Set headers in the sheet
     */
    public function setHeaders(string $spreadsheetId, string $sheetName, array $headers): void
    {
        $this->checkRateLimit();

        try {
            $this->getClient()
                ->spreadsheet($spreadsheetId)
                ->sheet($sheetName)
                ->update([$headers]); // Update first row with headers

            $this->updateRateLimit();
            
            $this->logger->info("Set headers in sheet {$sheetName}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to set headers: {$e->getMessage()}");
            throw new GoogleSheetsException("Failed to set headers: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Append rows with headers
     */
    public function appendWithHeaders(string $spreadsheetId, string $sheetName, array $rows): void
    {
        $this->checkRateLimit();

        try {
            $this->getClient()
                ->spreadsheet($spreadsheetId)
                ->sheet($sheetName)
                ->append($rows); // The client will handle header matching

            $this->updateRateLimit();
            
            $this->logger->info("Written " . count($rows) . " rows to sheet {$sheetName}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to write to Google Sheets: {$e->getMessage()}");
            throw new GoogleSheetsException("Failed to write to Google Sheets: {$e->getMessage()}", 0, $e);
        }
    }

    private function checkRateLimit(): void
    {
        $maxRequests = config('syncro-sheet.sheets.rate_limit.max_requests');
        $perSeconds = config('syncro-sheet.sheets.rate_limit.per_seconds');

        // Clean old rate limit entries
        $this->rateLimits = array_filter($this->rateLimits, function ($timestamp) use ($perSeconds) {
            return $timestamp > (time() - $perSeconds);
        });

        if (count($this->rateLimits) >= $maxRequests) {
            $sleepTime = end($this->rateLimits) - (time() - $perSeconds) + 1;
            if ($sleepTime > 0) {
                sleep($sleepTime);
            }
        }
    }

    private function updateRateLimit(): void
    {
        $this->rateLimits[] = time();
    }
} 
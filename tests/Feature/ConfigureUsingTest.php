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
 *          * -  Copyright © 2026 (Z) Programing  - *
 *          *    -  -  All Rights Reserved  -  -    *
 *          * * * * * * * * * * * * * * * * * * * * *
 */

namespace Zuko\SyncroSheet\Tests\Feature;

use Google\Client as GoogleAPIClient;
use Revolution\Google\Sheets\Providers\SheetsServiceProvider;
use Zuko\SyncroSheet\Exceptions\GoogleSheetsException;
use Zuko\SyncroSheet\Facades\SyncroSheet;
use Zuko\SyncroSheet\Services\ClientConfigurators;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\SyncLogger;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Services\TokenManager;
use Zuko\SyncroSheet\Tests\TestCase;

/**
 * Credentials (or anything else on the Google client) configured in code,
 * after the config-based sources.
 */
class ConfigureUsingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(SheetsServiceProvider::class);
        config([
            'cache.default' => 'array',
            'syncro-sheet.google.credentials' => null,
            'google.service.enable' => false,
            'google.client_id' => '',
        ]);
    }

    private static function serviceAccount(string $clientId): array
    {
        return [
            'type' => 'service_account', 'client_email' => "{$clientId}@example.iam.gserviceaccount.com",
            'client_id' => $clientId, 'private_key' => 'unused', 'private_key_id' => 'k',
        ];
    }

    /** The Google client the package builds now. */
    private function builtClient(?GoogleClient $client = null): GoogleAPIClient
    {
        $client ??= new GoogleClient(app(TokenManager::class), app(SyncLogger::class));

        return (new \ReflectionMethod($client, 'getClient'))->invoke($client)->getService()->getClient();
    }

    public function test_a_callback_provides_the_credentials(): void
    {
        SyncManager::configureUsing(fn (GoogleAPIClient $client) => $client->setAuthConfig(self::serviceAccount('from-callback')));

        $this->assertSame('from-callback', $this->builtClient()->getClientId());
    }

    public function test_the_facade_registers_callbacks_too(): void
    {
        SyncroSheet::configureUsing(fn (GoogleAPIClient $client) => $client->setAuthConfig(self::serviceAccount('via-facade')));

        $this->assertFalse(app(ClientConfigurators::class)->isEmpty());
        $this->assertSame('via-facade', $this->builtClient()->getClientId());
    }

    public function test_callbacks_run_after_the_config_and_may_override_it(): void
    {
        config(['syncro-sheet.google.credentials' => self::serviceAccount('from-config')]);
        $seen = null;
        SyncManager::configureUsing(function (GoogleAPIClient $client) use (&$seen) {
            $seen = $client->getClientId();
            $client->setAuthConfig(self::serviceAccount('from-callback'));
        });

        $this->assertSame('from-callback', $this->builtClient()->getClientId());
        $this->assertSame('from-config', $seen, 'the config credentials were applied first');
    }

    public function test_a_callback_added_later_rebuilds_the_client(): void
    {
        config(['syncro-sheet.google.credentials' => self::serviceAccount('from-config')]);
        $client = new GoogleClient(app(TokenManager::class), app(SyncLogger::class));
        $this->assertSame('from-config', $this->builtClient($client)->getClientId());

        SyncManager::configureUsing(fn (GoogleAPIClient $google) => $google->setAuthConfig(self::serviceAccount('later')));

        $this->assertSame('later', $this->builtClient($client)->getClientId());
    }

    public function test_no_credentials_from_anywhere_fails_with_the_sources_checked(): void
    {
        SyncManager::configureUsing(fn (GoogleAPIClient $client) => $client->setApplicationName('only a tweak'));

        try {
            $this->builtClient();
            $this->fail('a client without credentials was built');
        } catch (GoogleSheetsException $e) {
            $this->assertStringContainsString('configureUsing() callbacks (set no credentials)', $e->getMessage());
            $this->assertStringContainsString('queue:restart', $e->getMessage());
        }
    }

    public function test_the_credentials_setting_takes_the_json_itself(): void
    {
        config(['syncro-sheet.google.credentials' => json_encode(self::serviceAccount('inline-json'))]);

        $this->assertSame('inline-json', $this->builtClient()->getClientId());
    }
}

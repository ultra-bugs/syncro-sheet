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

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Revolution\Google\Sheets\Providers\SheetsServiceProvider;
use Zuko\SyncroSheet\Exceptions\GoogleSheetsException;
use Zuko\SyncroSheet\Jobs\PartialSyncJob;
use Zuko\SyncroSheet\Models\SyncState;
use Zuko\SyncroSheet\Notifications\SyncFailedNotification;
use Zuko\SyncroSheet\Services\ErrorHandler;
use Zuko\SyncroSheet\Services\GoogleClient;
use Zuko\SyncroSheet\Services\StateManager;
use Zuko\SyncroSheet\Services\SyncLogger;
use Zuko\SyncroSheet\Services\SyncManager;
use Zuko\SyncroSheet\Services\TokenManager;
use Zuko\SyncroSheet\Tests\Fixtures\FakeGoogleClient;
use Zuko\SyncroSheet\Tests\Fixtures\TestLedger;
use Zuko\SyncroSheet\Tests\TestCase;

/**
 * A run always ends: whatever is thrown (an \Error included), the state is
 * marked failed with the message, and a dead worker's run is ended by the next.
 */
class FailedRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        config([
            'cache.default' => 'array',
            'syncro-sheet.notifications.notify_on.error' => true,
            'syncro-sheet.notifications.mail_to' => 'ops@example.com',
        ]);

        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function lastRun(): SyncState
    {
        return SyncState::query()->latest('id')->firstOrFail();
    }

    public function test_a_run_without_credentials_fails_with_a_clear_message(): void
    {
        config([
            'syncro-sheet.google.credentials' => null,
            'google.service.enable' => false,
            'google.client_id' => '',
        ]);

        try {
            app(SyncManager::class)->sync(TestLedger::class);
            $this->fail('the run should have failed');
        } catch (GoogleSheetsException $e) {
            $this->assertStringContainsString('No Google credentials configured', $e->getMessage());
        }

        $run = $this->lastRun();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertStringContainsString('No Google credentials configured', $run->error_message);
    }

    public function test_an_error_inside_the_run_marks_it_failed_and_notifies(): void
    {
        $this->app->instance(GoogleClient::class, new class extends FakeGoogleClient
        {
            public function readSheet(string $spreadsheetId, string $sheetName): array
            {
                throw new \Error('Call to undefined method Something::missing()');
            }
        });

        try {
            app(SyncManager::class)->sync(TestLedger::class);
            $this->fail('the run should have failed');
        } catch (\Error $e) {
            $this->assertSame('Call to undefined method Something::missing()', $e->getMessage());
        }

        $run = $this->lastRun();
        $this->assertSame('failed', $run->status);
        $this->assertSame('Call to undefined method Something::missing()', $run->error_message);
        Notification::assertSentOnDemand(SyncFailedNotification::class);
        Queue::assertNotPushed(PartialSyncJob::class); // a two-way run is not retried as an export
    }

    public function test_the_failure_notification_renders(): void
    {
        $run = app(StateManager::class)->initializeSync(TestLedger::class, 'full', 'append');

        $mail = (new SyncFailedNotification($run, new \Error('boom')))->toMail(null);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertContains('Error: boom', $mail->introLines);
    }

    public function test_only_an_export_with_failed_records_is_retried(): void
    {
        $state = app(StateManager::class);
        $handler = app(ErrorHandler::class);

        $export = $state->initializeSync(TestLedger::class, 'full', 'append', SyncManager::DIRECTION_EXPORT);
        $state->failSync($export, 'quota');
        $handler->handleError($export, new \RuntimeException('quota'));
        Queue::assertNotPushed(PartialSyncJob::class);

        $handler->handleError($export, new \RuntimeException('quota'), [3, 4]);
        Queue::assertPushed(PartialSyncJob::class, 1);

        $import = $state->initializeSync(TestLedger::class, 'full', 'append', SyncManager::DIRECTION_IMPORT);
        $state->failSync($import, 'quota');
        $handler->handleError($import, new \RuntimeException('quota'), [3, 4]);
        Queue::assertPushed(PartialSyncJob::class, 1);
    }

    public function test_the_next_run_ends_runs_abandoned_by_a_dead_worker(): void
    {
        config(['syncro-sheet.defaults.timeout' => 600]);
        $state = app(StateManager::class);

        $dead = $state->initializeSync(TestLedger::class, 'full', 'append', SyncManager::DIRECTION_BIDIRECTIONAL);
        $dead->forceFill(['started_at' => now()->subSeconds(600 + StateManager::ABANDON_GRACE_SECONDS + 1)])->save();
        $live = $state->initializeSync(TestLedger::class, 'full', 'append', SyncManager::DIRECTION_BIDIRECTIONAL);

        $state->initializeSync(TestLedger::class, 'full', 'append', SyncManager::DIRECTION_BIDIRECTIONAL);

        $this->assertSame('failed', $dead->fresh()->status);
        $this->assertStringStartsWith('Abandoned: no result after 660 s', $dead->fresh()->error_message);
        $this->assertSame('running', $live->fresh()->status);
    }

    public function test_package_credentials_win_over_the_revolution_service_account(): void
    {
        config([
            'syncro-sheet.google.credentials' => [
                'type' => 'service_account', 'client_email' => 'x@example.iam.gserviceaccount.com',
                'client_id' => 'package-sa', 'private_key' => 'unused', 'private_key_id' => 'k',
            ],
            'google.service.enable' => true,
            'google.service.file' => '/nonexistent/service-account.json',
        ]);

        $this->app->register(SheetsServiceProvider::class);
        $client = new GoogleClient(app(TokenManager::class), app(SyncLogger::class));
        $sheets = (new \ReflectionMethod($client, 'getClient'))->invoke($client);

        $this->assertSame('package-sa', $sheets->getService()->getClient()->getClientId());
    }
}

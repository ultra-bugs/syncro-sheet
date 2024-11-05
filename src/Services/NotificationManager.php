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

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Zuko\SyncroSheet\Events\SyncEvent;
use Zuko\SyncroSheet\Models\SyncState;
use Zuko\SyncroSheet\Notifications\SyncCompletedNotification;
use Zuko\SyncroSheet\Notifications\SyncFailedNotification;
use Zuko\SyncroSheet\Notifications\SyncRetryNotification;

class NotificationManager
{
    /**
     * Notify about sync start
     */
    public function notifyStart(SyncState $syncState): void
    {
        Event::dispatch(SyncEvent::SYNC_STARTED, $syncState);
    }

    /**
     * Notify about chunk processing
     */
    public function notifyChunkProcessed(SyncState $syncState, int $processed): void
    {
        Event::dispatch(SyncEvent::CHUNK_PROCESSED, [
            'sync_state' => $syncState,
            'processed' => $processed,
        ]);
    }

    /**
     * Notify about sync completion
     */
    public function notifyCompletion(SyncState $syncState): void
    {
        Event::dispatch(SyncEvent::SYNC_COMPLETED, $syncState);

        if (config('syncro-sheet.notifications.notify_on.sync_completed')) {
            $this->sendNotification(new SyncCompletedNotification($syncState));
        }
    }

    /**
     * Notify about retry attempt
     */
    public function notifyRetry(SyncState $syncState, array $failedRecords, int $retryCount): void
    {
        if (config('syncro-sheet.notifications.notify_on.retry')) {
            $this->sendNotification(new SyncRetryNotification(
                $syncState,
                $failedRecords,
                $retryCount
            ));
        }
    }

    /**
     * Notify about final failure
     */
    public function notifyFinalFailure(SyncState $syncState, \Exception $exception): void
    {
        Event::dispatch(SyncEvent::SYNC_FAILED, [
            'sync_state' => $syncState,
            'error' => $exception->getMessage(),
        ]);

        if (config('syncro-sheet.notifications.notify_on.error')) {
            $this->sendNotification(new SyncFailedNotification($syncState, $exception));
        }
    }

    private function sendNotification($notification): void
    {
        $channels = config('syncro-sheet.notifications.channels', ['mail']);

        foreach ($channels as $channel) {
            if ($channel === 'mail') {
                Notification::route('mail', config('syncro-sheet.notifications.mail_to'))
                    ->notify($notification);
            } elseif ($channel === 'slack') {
                Notification::route('slack', config('syncro-sheet.notifications.slack_webhook'))
                    ->notify($notification);
            }
        }
    }
}

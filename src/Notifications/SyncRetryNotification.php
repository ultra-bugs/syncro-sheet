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

namespace Zuko\SyncroSheet\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;

class SyncRetryNotification extends BaseNotification
{
    public function __construct(
        SyncState $syncState,
        private readonly array $failedRecords,
        private readonly int $retryCount
    ) {
        parent::__construct($syncState);
    }

    protected function getMailMessage(): MailMessage
    {
        return (new MailMessage)
            ->subject("Sheet Sync Retry: {$this->getModelName()}")
            ->line("Attempting retry #{$this->retryCount} for {$this->getModelName()} sync.")
            ->line("Failed records: " . count($this->failedRecords))
            ->line("Next retry scheduled in: " . $this->getRetryDelay() . " minutes");
    }

    protected function getSlackMessage(): SlackMessage
    {
        return (new SlackMessage)
            ->warning()
            ->content("Sheet Sync Retry: {$this->getModelName()}")
            ->attachment(function ($attachment) {
                $attachment
                    ->fields([
                        'Retry Attempt' => $this->retryCount,
                        'Failed Records' => count($this->failedRecords),
                        'Next Retry In' => $this->getRetryDelay() . ' minutes',
                    ]);
            });
    }

    private function getRetryDelay(): int
    {
        return pow(2, $this->retryCount - 1);
    }
} 

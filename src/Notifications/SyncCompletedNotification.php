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

class SyncCompletedNotification extends BaseNotification
{
    protected function getMailMessage(): MailMessage
    {
        return (new MailMessage)
            ->subject("Sheet Sync Completed: {$this->getModelName()}")
            ->line("The sheet sync for {$this->getModelName()} has completed successfully.")
            ->line("Total records processed: {$this->syncState->total_processed}")
            ->line("Duration: {$this->syncState->started_at->diffForHumans($this->syncState->completed_at)}");
    }

    protected function getSlackMessage(): SlackMessage
    {
        return (new SlackMessage)
            ->success()
            ->content("Sheet Sync Completed: {$this->getModelName()}")
            ->attachment(function ($attachment) {
                $attachment
                    ->fields([
                        'Total Processed' => $this->syncState->total_processed,
                        'Duration' => $this->syncState->started_at->diffForHumans($this->syncState->completed_at),
                    ]);
            });
    }
}

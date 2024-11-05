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

class SyncFailedNotification extends BaseNotification
{
    public function __construct(
        SyncState $syncState,
        private readonly \Exception $exception
    ) {
        parent::__construct($syncState);
    }

    protected function getMailMessage(): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject("Sheet Sync Failed: {$this->getModelName()}")
            ->line("The sheet sync for {$this->getModelName()} has failed.")
            ->line("Error: {$this->exception->getMessage()}")
            ->line("Records processed before failure: {$this->syncState->total_processed}");
    }

    protected function getSlackMessage(): SlackMessage
    {
        return (new SlackMessage)
            ->error()
            ->content("Sheet Sync Failed: {$this->getModelName()}")
            ->attachment(function ($attachment) {
                $attachment
                    ->fields([
                        'Error' => $this->exception->getMessage(),
                        'Records Processed' => $this->syncState->total_processed,
                        'Last Processed ID' => $this->syncState->last_processed_id,
                    ]);
            });
    }
}

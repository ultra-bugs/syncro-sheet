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
use Illuminate\Notifications\Notification;
use Zuko\SyncroSheet\Models\SyncState;

abstract class BaseNotification extends Notification
{
    public function __construct(
        protected SyncState $syncState
    ) {
    }

    public function via($notifiable): array
    {
        return config('syncro-sheet.notifications.channels', ['mail']);
    }

    protected function getModelName(): string
    {
        return class_basename($this->syncState->model_class);
    }

    abstract protected function getMailMessage(): MailMessage;

    abstract protected function getSlackMessage(): SlackMessage;
}

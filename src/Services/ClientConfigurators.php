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

use Closure;
use Google\Client as GoogleAPIClient;

/**
 * Callbacks registered with SyncManager::configureUsing(), applied to every
 * Google client the package builds. A container singleton: callbacks survive
 * a re-resolved GoogleClient and never outlive the application instance.
 */
class ClientConfigurators
{
    /** @var array<int, Closure(GoogleAPIClient): mixed> */
    private array $callbacks = [];

    private int $version = 0;

    public function add(Closure $callback): void
    {
        $this->callbacks[] = $callback;
        $this->version++;
    }

    public function apply(GoogleAPIClient $client): void
    {
        foreach ($this->callbacks as $callback) {
            $callback($client);
        }
    }

    public function isEmpty(): bool
    {
        return $this->callbacks === [];
    }

    /** Changes whenever a callback is added: a client built before is stale. */
    public function version(): int
    {
        return $this->version;
    }
}

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

namespace Zuko\SyncroSheet\Contracts;

/**
 * Strategy for HOW the scheduler executes a sync for one model+direction.
 *
 * Implementations: InlineDriver (run now, in-process) and QueueDriver (fan out
 * to queued jobs). A fork-based parallel driver is planned for a later stage.
 */
interface SyncDriver
{
    /**
     * @param  string  $direction  export | import | both (bidirectional)
     * @param  array<string, mixed>  $options
     */
    public function dispatch(string $modelClass, string $direction, array $options = []): void;
}

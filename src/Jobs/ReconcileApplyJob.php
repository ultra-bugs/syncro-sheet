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

namespace Zuko\SyncroSheet\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Zuko\SyncroSheet\Services\Reconciler;

/**
 * Apply a chunk of reconcile decisions produced centrally by
 * {@see Reconciler::decide()}. The decide (conflict-resolution) phase already
 * ran on the dispatcher; this job only executes the agreed writes.
 */
class ReconcileApplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, array{pk: int|string, action: string, payload: array, hash: string}>  $decisions
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly array $decisions
    ) {
    }

    public function handle(Reconciler $reconciler): void
    {
        $reconciler->applyChunk($this->modelClass, $this->decisions);
    }

    public function tags(): array
    {
        return ['sheet-sync', 'reconcile', 'batch', class_basename($this->modelClass)];
    }

    public function backoff(): array
    {
        return [60, 180, 360];
    }

    public function maxExceptions(): int
    {
        return 3;
    }
}

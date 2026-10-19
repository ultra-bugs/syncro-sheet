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
use Zuko\SyncroSheet\Services\Importer;

/**
 * Upsert a chunk of already-read sheet rows into the DB. Dispatched by the
 * queue driver's import fan-out; carries the rows so it never re-reads the sheet.
 */
class ImportBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, string>        $headers
     * @param  array<int, array<mixed>>  $rows
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly array $headers,
        private readonly array $rows
    ) {
    }

    public function handle(Importer $importer): void
    {
        $importer->importRows($this->modelClass, $this->headers, $this->rows);
    }

    public function tags(): array
    {
        return ['sheet-sync', 'import', 'batch', class_basename($this->modelClass)];
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

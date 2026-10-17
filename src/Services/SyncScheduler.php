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

use Illuminate\Contracts\Container\Container;
use Zuko\SyncroSheet\Contracts\SyncDriver;
use Zuko\SyncroSheet\Services\Drivers\InlineDriver;
use Zuko\SyncroSheet\Services\Drivers\QueueDriver;

/**
 * Dispatcher for scheduled syncs: reads the configured models + directions and
 * hands each off to the configured driver (inline or queue).
 */
class SyncScheduler
{
    public function __construct(
        private readonly Container $app,
        private readonly SyncLogger $logger
    ) {
    }

    public function run(): void
    {
        $driver = $this->resolveDriver();

        foreach ((array) config('syncro-sheet.scheduler.models', []) as $key => $spec) {
            [$modelClass, $direction] = $this->normalize($key, $spec);

            $this->logger->info("Scheduled sync dispatch: {$modelClass} ({$direction})");
            $driver->dispatch($modelClass, $direction);
        }
    }

    public function resolveDriver(): SyncDriver
    {
        return match (config('syncro-sheet.scheduler.driver', 'sync')) {
            'queue' => $this->app->make(QueueDriver::class),
            default => $this->app->make(InlineDriver::class),
        };
    }

    /**
     * Accept both list form (`[Product::class]`, default direction) and map form
     * (`Product::class => 'both'` or `Product::class => ['direction' => 'export']`).
     *
     * @return array{0: string, 1: string}
     */
    private function normalize(int|string $key, mixed $spec): array
    {
        if (is_int($key)) {
            return [$spec, SyncManager::DIRECTION_EXPORT];
        }

        $direction = is_array($spec) ? ($spec['direction'] ?? 'export') : (string) $spec;

        return [$key, $this->normalizeDirection($direction)];
    }

    private function normalizeDirection(string $direction): string
    {
        return match ($direction) {
            'import', SyncManager::DIRECTION_IMPORT => SyncManager::DIRECTION_IMPORT,
            'both', SyncManager::DIRECTION_BIDIRECTIONAL => SyncManager::DIRECTION_BIDIRECTIONAL,
            default => SyncManager::DIRECTION_EXPORT,
        };
    }
}

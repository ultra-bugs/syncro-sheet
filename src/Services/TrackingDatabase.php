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

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Where the tracking tables (sync_states, sync_entries, sync_snapshots) live.
 *
 * Resolved once, in the provider's boot():
 *  1. syncro-sheet.database.connection, when set;
 *  2. otherwise a dedicated SQLite file (connection "syncro-sheet") when
 *     pdo_sqlite is available — its schema is managed here, with its own
 *     migrations table, so it never depends on the app's migrations table;
 *  3. otherwise the app's default connection, with a warning on both the
 *     package log and the app's default log channel.
 */
class TrackingDatabase
{
    public const CONNECTION = 'syncro-sheet';

    private static bool $migrating = false;

    public function __construct(private readonly SyncLogger $logger)
    {
    }

    /**
     * The resolved connection name (null = the app's default connection).
     */
    public static function connectionName(): ?string
    {
        return config('syncro-sheet.database.resolved_connection');
    }

    public function resolve(): ?string
    {
        $explicit = config('syncro-sheet.database.connection');

        if (filled($explicit)) {
            $name = (string) $explicit;
        } elseif ($this->sqliteAvailable()) {
            config(['database.connections.'.self::CONNECTION => $this->sqliteConfig()]);
            $name = self::CONNECTION;
        } else {
            $this->warnFallback();
            $name = null;
        }

        config(['syncro-sheet.database.resolved_connection' => $name]);

        return $name;
    }

    public function usesDedicatedSqlite(): bool
    {
        return self::connectionName() === self::CONNECTION;
    }

    /**
     * First boot: create the SQLite file and its schema (under a file lock, so
     * concurrent workers do not migrate twice).
     */
    public function ensureSchema(): void
    {
        $path = (string) config('database.connections.'.self::CONNECTION.'.database');
        if (is_file($path) && filesize($path) > 0) {
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        $lock = fopen($path.'.lock', 'c');
        flock($lock, LOCK_EX);

        try {
            clearstatcache(true, $path);
            if (! is_file($path) || filesize($path) === 0) {
                touch($path);
                $this->migrate();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Run the package migrations still pending on the dedicated SQLite file.
     */
    public function migrate(): void
    {
        if (self::$migrating || ! $this->usesDedicatedSqlite()) {
            return;
        }

        self::$migrating = true;
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');
            $migrator->usingConnection(self::CONNECTION, function () use ($migrator) {
                if (! $migrator->repositoryExists()) {
                    $migrator->getRepository()->createRepository();
                }
                $migrator->run([dirname(__DIR__, 2).'/database/migrations']);
            });
        } finally {
            self::$migrating = false;
        }
    }

    protected function sqliteAvailable(): bool
    {
        return extension_loaded('pdo_sqlite') && in_array('sqlite', \PDO::getAvailableDrivers(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function sqliteConfig(): array
    {
        return [
            'driver' => 'sqlite',
            'database' => config('syncro-sheet.database.sqlite_database') ?: storage_path('syncro-sheet/tracking.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
            // Queue workers write concurrently: WAL + wait on a locked database.
            'journal_mode' => 'wal',
            'busy_timeout' => 10000,
            'synchronous' => 'normal',
        ];
    }

    /**
     * Warn on the package log and on the app's default channel, at most once a
     * day (boot runs on every request).
     */
    private function warnFallback(): void
    {
        try {
            if (! Cache::add('syncro-sheet:sqlite-unavailable-warned', true, now()->addDay())) {
                return;
            }
        } catch (\Throwable) {
            // no usable cache store yet: warn anyway
        }

        $message = 'zuko/syncro-sheet: pdo_sqlite is not available, the tracking tables '
            .'(sync_states, sync_entries, sync_snapshots) use the default database connection.';
        $this->logger->warning($message);
        Log::warning($message);
    }
}

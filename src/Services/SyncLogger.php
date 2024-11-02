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

use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

class SyncLogger
{
    private Logger $logger;

    public function __construct()
    {
        $this->logger = $this->configureLogger();
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    private function configureLogger(): Logger
    {
        $logger = new Logger('sheet-sync');

        if (config('syncro-sheet.logging.separate_files')) {
            $logger->pushHandler(new RotatingFileHandler(
                storage_path('logs/sheet-sync.log'),
                30,
                Logger::INFO
            ));
        } else {
            $logger->pushHandler(Log::channel(
                config('syncro-sheet.logging.channel', 'stack')
            )->getHandlers()[0]);
        }

        return $logger;
    }
} 

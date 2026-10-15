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

namespace Zuko\SyncroSheet\Tests\Fixtures;

use Zuko\SyncroSheet\Services\GoogleClient;

/**
 * In-memory stand-in for {@see GoogleClient}: no network, no auth. Lets tests
 * feed sheet contents to the Importer and capture what the Exporter writes.
 */
class FakeGoogleClient extends GoogleClient
{
    /** @var array<int, array<int, mixed>> First row is the header row. */
    public array $sheetValues = [];

    /** @var array<int, array<string, mixed>> Rows captured by the exporter. */
    public array $appended = [];

    /** @var array<int, string> Headers written via setHeaders(). */
    public array $headersSet = [];

    public bool $cleared = false;

    public function __construct()
    {
        // Intentionally bypass the real GoogleClient dependencies.
    }

    public function readSheet(string $spreadsheetId, string $sheetName): array
    {
        return $this->sheetValues;
    }

    public function getHeaders(string $spreadsheetId, string $sheetName): array
    {
        return $this->sheetValues[0] ?? [];
    }

    public function setHeaders(string $spreadsheetId, string $sheetName, array $headers): void
    {
        $this->headersSet = $headers;
    }

    public function appendWithHeaders(string $spreadsheetId, string $sheetName, array $rows): void
    {
        $this->appended = array_merge($this->appended, $rows);
    }

    public function writeBatch(string $spreadsheetId, string $sheetName, array $rows, $model = null): void
    {
        $this->appended = array_merge($this->appended, $rows);
    }

    public function clearSheet(string $spreadsheetId, string $sheetName): void
    {
        $this->cleared = true;
        $this->sheetValues = [];
    }
}

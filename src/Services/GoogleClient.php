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

use Google\Client as GoogleAPIClient;
use Closure;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Sheets as GoogleSheets;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\ValueRange;
use Revolution\Google\Sheets\Facades\Sheets;
use Zuko\SyncroSheet\Exceptions\GoogleSheetsException;

class GoogleClient
{
    private array $rateLimits = [];

    private $sheetsClient = null;

    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly SyncLogger $logger
    ) {
    }

    /**
     * Get or initialize the Sheets client
     */
    private function getClient()
    {
        if ($this->sheetsClient) {
            return $this->sheetsClient;
        }

        if (app()->bound(GoogleAPIClient::class)) {
            // Laravel environment - use facade with token
            $token = $this->tokenManager->getToken();
            $this->sheetsClient = Sheets::setAccessToken($token);
        } else {
            // Non-Laravel environment - manual setup using config
            $client = new GoogleAPIClient($this->getGoogleConfig());

            // Set required scopes for sheets
            $client->setScopes([GoogleSheets::DRIVE, GoogleSheets::SPREADSHEETS]);

            // Handle authentication based on config
            if (config('google.service.enable')) {
                $this->setupServiceAccount($client);
            } else {
                $this->setupOAuth($client);
            }

            $service = new GoogleSheets($client);
            $this->sheetsClient = Sheets::setService($service);
        }

        return $this->sheetsClient;
    }

    /**
     * Get Google client configuration
     */
    private function getGoogleConfig(): array
    {
        $config = config('google.config', []);

        // Add basic configuration
        $config['application_name'] = config('google.application_name');

        if (config('google.developer_key')) {
            $config['developer_key'] = config('google.developer_key');
        }

        return $config;
    }

    /**
     * Setup service account authentication
     */
    private function setupServiceAccount(GoogleAPIClient $client): void
    {
        $serviceAccountFile = config('google.service.file');

        if (is_array($serviceAccountFile)) {
            $client->setAuthConfig($serviceAccountFile);

            return;
        }

        // Search for the file in multiple locations; an absolute path (the
        // usual GOOGLE_SERVICE_ACCOUNT_JSON_LOCATION form) is tried as-is first.
        $searchPaths = [
            $serviceAccountFile,
            base_path($serviceAccountFile),
            resource_path($serviceAccountFile),
            resource_path('credentials'.DIRECTORY_SEPARATOR.$serviceAccountFile),
            storage_path($serviceAccountFile),
            storage_path('credentials'.DIRECTORY_SEPARATOR.$serviceAccountFile),
        ];

        foreach ($searchPaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $client->setAuthConfig($path);

                return;
            }
        }

        throw new GoogleSheetsException(
            'Service account configuration file not found in any of the following locations: '.
            implode(', '.PHP_EOL, $searchPaths)
        );
    }

    /**
     * Setup OAuth authentication
     */
    private function setupOAuth(GoogleAPIClient $client): void
    {
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setRedirectUri(config('google.redirect_uri'));
        $client->setAccessType(config('google.access_type', 'online'));
        $client->setApprovalPrompt(config('google.approval_prompt', 'auto'));

        // Set the token if available
        $token = $this->tokenManager->getToken();
        if ($token) {
            $client->setAccessToken($token);
        }
    }

    /**
     * The (stateful, shared) revolution client scoped to one sheet and range.
     *
     * The sheet name is always quoted so names that look like A1 references
     * ("Oct25", "2025") address the tab, and the range / render options are
     * reset on every call so state never leaks from a previous operation.
     */
    private function scoped(string $spreadsheetId, string $sheetName, string $range = '')
    {
        return $this->getClient()
            ->spreadsheet($spreadsheetId)
            ->sheet("'".str_replace("'", "''", $sheetName)."'")
            ->range($range);
    }

    /**
     * Write a batch of rows to Google Sheets
     */
    public function writeBatch(string $spreadsheetId, string $sheetName, array $rows, $model = null): void
    {
        $existingData = $this->readSheet($spreadsheetId, $sheetName);
        if (empty($existingData)) {
            $this->setHeaders($spreadsheetId, $sheetName, $this->generateHeaders($model, $rows));
        }

        $this->appendRows($spreadsheetId, $sheetName, array_map('array_values', $rows), max(count($existingData), 1));
    }

    /**
     * Clear sheet content
     */
    public function clearSheet(string $spreadsheetId, string $sheetName): void
    {
        try {
            $this->call(fn () => $this->scoped($spreadsheetId, $sheetName)->clear());

            $this->logger->info("Cleared sheet {$sheetName}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to clear sheet: {$e->getMessage()}");
            throw new GoogleSheetsException("Failed to clear sheet: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Read all values from a sheet.
     *
     * Values come back typed (numbers as numbers, no display formatting) and
     * date cells as their formatted string, per `syncro-sheet.sheets.read`.
     */
    public function readSheet(string $spreadsheetId, string $sheetName): array
    {
        try {
            return $this->call(fn () => $this->scoped($spreadsheetId, $sheetName)
                ->valueRenderOption(config('syncro-sheet.sheets.read.value_render_option', 'UNFORMATTED_VALUE'))
                ->dateTimeRenderOption(config('syncro-sheet.sheets.read.date_time_render_option', 'FORMATTED_STRING'))
                ->all());
        } catch (\Exception $e) {
            $this->logger->error("Failed to read from Google Sheets: {$e->getMessage()}");
            throw new GoogleSheetsException("Failed to read from Google Sheets: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Re-read specific rows (1-based absolute indexes) in one values.batchGet,
     * with the same render options as readSheet().
     *
     * @param  array<int, int>  $rowIndexes
     * @return array<int, array<int, mixed>>  rowIndex => cell values (trailing blanks trimmed)
     */
    public function readRows(string $spreadsheetId, string $sheetName, array $rowIndexes): array
    {
        if (empty($rowIndexes)) {
            return [];
        }

        try {
            $quoted = "'".str_replace("'", "''", $sheetName)."'";
            $response = $this->call(fn () => $this->getClient()->getService()->spreadsheets_values->batchGet($spreadsheetId, [
                'ranges' => array_map(static fn (int $row) => "{$quoted}!{$row}:{$row}", array_values($rowIndexes)),
                'valueRenderOption' => config('syncro-sheet.sheets.read.value_render_option', 'UNFORMATTED_VALUE'),
                'dateTimeRenderOption' => config('syncro-sheet.sheets.read.date_time_render_option', 'FORMATTED_STRING'),
            ]));

            $rows = [];
            foreach (array_values($rowIndexes) as $position => $row) {
                $rows[$row] = $response->getValueRanges()[$position]->getValues()[0] ?? [];
            }

            return $rows;
        } catch (\Exception $e) {
            $this->logger->error("Failed to read rows from Google Sheets: {$e->getMessage()}");
            throw new GoogleSheetsException("Failed to read rows from Google Sheets: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get headers from the sheet
     */
    public function getHeaders(string $spreadsheetId, string $sheetName): array
    {
        try {
            return $this->readSheet($spreadsheetId, $sheetName)[0] ?? [];
        } catch (GoogleSheetsException $e) {
            throw new GoogleSheetsException("Failed to get headers: {$e->getPrevious()?->getMessage()}", 0, $e);
        }
    }

    /**
     * Set headers in the sheet
     */
    public function setHeaders(string $spreadsheetId, string $sheetName, array $headers): void
    {
        try {
            $this->call(fn () => $this->scoped($spreadsheetId, $sheetName, 'A1')->update([array_values($headers)]));

            $this->logger->info("Set headers in sheet {$sheetName}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to set headers: {$e->getMessage()}");
            throw new GoogleSheetsException("Failed to set headers: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Append associative rows (header => value), ordered by the sheet's header
     * row. Kept for callers that build header-keyed rows; positions are
     * resolved here instead of by the underlying client, which collapses
     * blank/duplicate headers.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function appendWithHeaders(string $spreadsheetId, string $sheetName, array $rows): void
    {
        $values = $this->readSheet($spreadsheetId, $sheetName);
        $headers = $values[0] ?? array_keys(reset($rows) ?: []);

        $lists = array_map(static function (array $row) use ($headers) {
            $list = [];
            foreach ($headers as $position => $header) {
                $list[$position] = $header === '' ? '' : ($row[$header] ?? '');
            }

            return $list;
        }, $rows);

        $this->appendRows($spreadsheetId, $sheetName, $lists, max(count($values), 1));
    }

    /**
     * Append positional rows (lists starting at column A) after the given row.
     *
     * The append is anchored on the single cell A{afterRow + 1}: anchoring on
     * the bare sheet name (or a full-width range) lets Google's table detection
     * start the row at another column when the header row has gaps. A single
     * empty anchor cell writes there; if a concurrent writer already used that
     * row, Google appends after it instead of overwriting.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function appendRows(string $spreadsheetId, string $sheetName, array $rows, int $afterRow): void
    {
        if (empty($rows)) {
            return;
        }

        try {
            $this->call(fn () => $this->scoped($spreadsheetId, $sheetName, 'A'.($afterRow + 1))
                ->append(array_map('array_values', $rows), 'RAW', 'OVERWRITE'));

            $this->logger->info('Written '.count($rows)." rows to sheet {$sheetName}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to write to Google Sheets: {$e->getMessage()}");
            throw new GoogleSheetsException("Failed to write to Google Sheets: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Update cells of existing rows, addressed by their 1-based absolute sheet
     * row index (row 1 is the header), in a single values.batchUpdate call.
     *
     * Each row's values are keyed by 0-based column index; a list writes from
     * column A, a sparse map writes only the given columns (contiguous columns
     * are grouped into one range), leaving every other cell untouched.
     *
     * @param  array<int, array<int, mixed>>  $rowsByIndex  rowIndex => [columnIndex => value]
     */
    public function updateRows(string $spreadsheetId, string $sheetName, array $rowsByIndex): void
    {
        $data = [];
        $quoted = "'".str_replace("'", "''", $sheetName)."'";
        foreach ($rowsByIndex as $index => $cells) {
            foreach ($this->contiguousRuns($cells) as $start => $run) {
                $end = $start + count($run) - 1;
                $data[] = new ValueRange([
                    'range' => $quoted.'!'.self::columnLetter($start).$index.':'.self::columnLetter($end).$index,
                    'values' => [$run],
                ]);
            }
        }

        if (empty($data)) {
            return;
        }

        try {
            $this->call(fn () => $this->getClient()->getService()->spreadsheets_values->batchUpdate(
                $spreadsheetId,
                new BatchUpdateValuesRequest(['valueInputOption' => 'RAW', 'data' => $data])
            ));

            $this->logger->info('Updated '.count($rowsByIndex)." row(s) in sheet {$sheetName}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to update rows in Google Sheets: {$e->getMessage()}");
            throw new GoogleSheetsException("Failed to update rows in Google Sheets: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Split a column-indexed cell map into runs of adjacent columns.
     *
     * @param  array<int, mixed>  $cells
     * @return array<int, array<int, mixed>>  startColumn => values
     */
    private function contiguousRuns(array $cells): array
    {
        ksort($cells);

        $runs = [];
        $start = null;
        $previous = null;
        foreach ($cells as $column => $value) {
            if ($start === null || $column !== $previous + 1) {
                $start = $column;
            }
            $runs[$start][] = $value;
            $previous = $column;
        }

        return $runs;
    }

    /**
     * 0-based column index -> A1 column letters (0 => A, 25 => Z, 26 => AA).
     */
    public static function columnLetter(int $index): string
    {
        $letter = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letter = chr(65 + ($index - 1) % 26).$letter;
        }

        return $letter;
    }

    /**
     * Generate headers from a SheetExportable model or data
     */
    private function generateHeaders($model = null, array $data = []): array
    {
        // Try to get headers from model's defaultSheetHeaders method
        if ($model && method_exists($model, 'defaultSheetHeaders')) {
            return $model->defaultSheetHeaders();
        }

        // Try to get headers from first row's keys if associative
        if (! empty($data)) {
            $firstRow = reset($data);
            if (is_array($firstRow) && ! $this->isSequentialArray($firstRow)) {
                return array_keys($firstRow);
            }
        }

        // Fallback to Excel notation (A, B, C, ...)
        $columnCount = empty($data) ? 26 : count(reset($data)); // Default to 26 columns if no data

        return array_map(static fn (int $index) => self::columnLetter($index), range(0, $columnCount - 1));
    }

    /**
     * Check if array is sequential (numeric keys) or associative
     */
    private function isSequentialArray(array $arr): bool
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private function checkRateLimit(): void
    {
        $maxRequests = config('syncro-sheet.sheets.rate_limit.max_requests');
        $perSeconds = config('syncro-sheet.sheets.rate_limit.per_seconds');

        // Clean old rate limit entries
        $this->rateLimits = array_filter($this->rateLimits, function ($timestamp) use ($perSeconds) {
            return $timestamp > (time() - $perSeconds);
        });

        if (count($this->rateLimits) >= $maxRequests) {
            // Wait until the oldest request of the window leaves it.
            $sleepTime = reset($this->rateLimits) - (time() - $perSeconds) + 1;
            if ($sleepTime > 0) {
                sleep($sleepTime);
            }
        }
    }

    private function updateRateLimit(): void
    {
        $this->rateLimits[] = time();
    }

    /**
     * Run one API call under the client-side rate limit, retrying quota (429)
     * and transient server errors (5xx) with exponential backoff + jitter, as
     * Google recommends. Other errors are rethrown at once.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function call(Closure $operation): mixed
    {
        $retries = (int) config('syncro-sheet.sheets.retry.times', 5);
        $delayMs = (int) config('syncro-sheet.sheets.retry.base_delay_ms', 2000);

        for ($attempt = 0; ; $attempt++) {
            $this->checkRateLimit();
            try {
                $result = $operation();
                $this->updateRateLimit();

                return $result;
            } catch (GoogleServiceException $e) {
                $this->updateRateLimit();
                if ($attempt >= $retries || ! in_array($e->getCode(), [429, 500, 502, 503, 504], true)) {
                    throw $e;
                }

                $waitMs = min($delayMs * (2 ** $attempt), 64000) + random_int(0, 500);
                $this->logger->warning("Google Sheets API {$e->getCode()}, retry ".($attempt + 1)."/{$retries} in {$waitMs} ms");
                usleep($waitMs * 1000);
            }
        }
    }
}

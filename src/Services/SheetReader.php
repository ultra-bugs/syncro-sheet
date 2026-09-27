<?php

namespace Zuko\SyncroSheet\Services;

use Zuko\SyncroSheet\Contracts\BidirectionalSyncable;
use Zuko\SyncroSheet\Contracts\SheetSyncable;
use Zuko\SyncroSheet\Exceptions\SyncException;

class SheetReader
{
    public function __construct(
        private readonly GoogleClient $googleClient,
        private readonly SyncLogger $logger
    ) {}

    /**
     * Read sheet data and return parsed rows with headers
     *
     * @return array{headers: array, rows: array<int, array>}
     */
    public function readSheetData(SheetSyncable $model): array
    {
        $allData = $this->googleClient->readSheet(
            $model->getSheetIdentifier(),
            $model->getSheetName()
        );

        if (empty($allData)) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = array_shift($allData);

        $rows = [];
        foreach ($allData as $rowIndex => $row) {
            $paddedRow = array_pad($row, count($headers), null);
            $rows[$rowIndex + 2] = array_combine($headers, array_slice($paddedRow, 0, count($headers)));
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Read rows from sheet that have DB IDs in the id column,
     * and rows that don't (new rows from sheet)
     */
    public function partitionByIdColumn(SheetSyncable $model): array
    {
        $idColumn = $this->getIdColumnName($model);
        if (! $idColumn) {
            throw new SyncException('Model must implement getIdColumnOnSheet() for bidirectional sync');
        }

        $data = $this->readSheetData($model);
        if (empty($data['rows'])) {
            return ['existing' => [], 'new' => [], 'headers' => $data['headers']];
        }

        $existing = [];
        $new = [];

        foreach ($data['rows'] as $sheetRowNum => $row) {
            $idValue = $row[$idColumn] ?? null;
            if ($idValue !== null && $idValue !== '') {
                $existing[$sheetRowNum] = $row;
            } else {
                $new[$sheetRowNum] = $row;
            }
        }

        $this->logger->info(sprintf(
            'Partitioned sheet: %d existing, %d new rows',
            count($existing),
            count($new)
        ));

        return [
            'existing' => $existing,
            'new' => $new,
            'headers' => $data['headers'],
        ];
    }

    public function getIdColumnName(SheetSyncable $model): ?string
    {
        if ($model instanceof BidirectionalSyncable) {
            return $model->getIdColumnOnSheet();
        }

        return null;
    }

    /**
     * Extract DB record IDs from sheet rows using the id column
     *
     * @return array<int, mixed> Map of sheetRowNumber => dbId
     */
    public function extractDbIds(SheetSyncable $model, array $rows): array
    {
        $idColumn = $this->getIdColumnName($model);
        if (! $idColumn) {
            return [];
        }

        $ids = [];
        foreach ($rows as $sheetRowNum => $row) {
            $id = $row[$idColumn] ?? null;
            if ($id !== null && $id !== '') {
                $ids[$sheetRowNum] = $id;
            }
        }

        return $ids;
    }
}

<?php

namespace Zuko\SyncroSheet\Services;

use Illuminate\Database\Eloquent\Model;
use Zuko\SyncroSheet\Contracts\SheetSyncable;

class RecordMatcher
{
    public function __construct(
        private readonly SyncLogger $logger
    ) {
    }

    /**
     * Match sheet rows to existing DB records by their IDs
     *
     * @param  array  $sheetRows  Map of sheetRowNumber => associative row data
     * @param  array  $dbIdMap  Map of sheetRowNumber => dbRecordId
     * @return array{matched: array, unmatched: array}
     */
    public function matchByIds(string $modelClass, array $sheetRows, array $dbIdMap): array
    {
        if (empty($dbIdMap)) {
            return ['matched' => [], 'unmatched' => $sheetRows];
        }

        /** @var Model $model */
        $model = new $modelClass;
        $keyName = $model->getKeyName();
        $dbIds = array_values($dbIdMap);

        $existingRecords = $modelClass::query()
            ->whereIn($keyName, $dbIds)
            ->get()
            ->keyBy($keyName);

        $matched = [];
        $unmatched = [];

        foreach ($sheetRows as $sheetRowNum => $row) {
            $dbId = $dbIdMap[$sheetRowNum] ?? null;
            if ($dbId && $existingRecords->has($dbId)) {
                $matched[$sheetRowNum] = [
                    'sheet_data' => $row,
                    'db_record' => $existingRecords->get($dbId),
                    'db_id' => $dbId,
                ];
            } else {
                $unmatched[$sheetRowNum] = $row;
            }
        }

        $this->logger->info(sprintf(
            'Matched %d records, %d unmatched for %s',
            count($matched),
            count($unmatched),
            $modelClass
        ));

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * Determine which matched records have changes (sheet differs from DB)
     *
     * @return array Records that need updating in the DB
     */
    public function detectChanges(SheetSyncable $model, array $matchedRows): array
    {
        if (! method_exists($model, 'fromSheetRow')) {
            return [];
        }

        $changed = [];

        foreach ($matchedRows as $sheetRowNum => $data) {
            $sheetAttributes = $model->fromSheetRow($data['sheet_data']);
            $dbRecord = $data['db_record'];

            $hasChanges = false;
            foreach ($sheetAttributes as $key => $value) {
                $dbValue = $dbRecord->getAttribute($key);
                if ($this->valuesAreDifferent($dbValue, $value)) {
                    $hasChanges = true;
                    break;
                }
            }

            if ($hasChanges) {
                $changed[$sheetRowNum] = [
                    'db_record' => $dbRecord,
                    'new_attributes' => $sheetAttributes,
                    'db_id' => $data['db_id'],
                ];
            }
        }

        return $changed;
    }

    private function valuesAreDifferent(mixed $dbValue, mixed $sheetValue): bool
    {
        if ($dbValue instanceof \DateTimeInterface && is_string($sheetValue)) {
            return $dbValue->format('Y-m-d H:i:s') !== $sheetValue;
        }

        if (is_numeric($dbValue) && is_numeric($sheetValue)) {
            return (float) $dbValue !== (float) $sheetValue;
        }

        return (string) $dbValue !== (string) $sheetValue;
    }
}

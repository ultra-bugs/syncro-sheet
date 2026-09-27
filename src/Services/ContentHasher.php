<?php

namespace Zuko\SyncroSheet\Services;

use Illuminate\Support\Facades\DB;

class ContentHasher
{
    public function hash(array $row): string
    {
        $normalized = $this->normalize($row);

        return md5(json_encode($normalized));
    }

    public function hasChanged(string $currentHash, ?string $storedHash): bool
    {
        if ($storedHash === null) {
            return true;
        }

        return $currentHash !== $storedHash;
    }

    public function getStoredHash(string $modelClass, int|string $recordId): ?string
    {
        return DB::table('sync_entries')
            ->where('model_class', $modelClass)
            ->where('record_id', $recordId)
            ->where('status', 'success')
            ->whereNotNull('content_hash')
            ->orderByDesc('synced_at')
            ->value('content_hash');
    }

    public function getStoredHashes(string $modelClass, array $recordIds): array
    {
        if (empty($recordIds)) {
            return [];
        }

        return DB::table('sync_entries')
            ->select('record_id', 'content_hash')
            ->where('model_class', $modelClass)
            ->whereIn('record_id', $recordIds)
            ->where('status', 'success')
            ->whereNotNull('content_hash')
            ->orderByDesc('synced_at')
            ->get()
            ->unique('record_id')
            ->pluck('content_hash', 'record_id')
            ->toArray();
    }

    private function normalize(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            if ($value === null || $value === '') {
                $normalized[$key] = '';

                continue;
            }

            if (is_numeric($value)) {
                $normalized[$key] = (string) (float) $value;

                continue;
            }

            if (is_string($value)) {
                $normalized[$key] = trim($value);

                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = json_encode($value);

                continue;
            }

            $normalized[$key] = (string) $value;
        }

        return $normalized;
    }
}

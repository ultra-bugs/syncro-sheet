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

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FuzzyRecordIdentifier
{
    private const MIN_STRING_LENGTH = 10;
    private const MAX_STRING_LENGTH = 150;
    private const MIN_IDENTIFYING_FIELDS = 2;
    private const MAX_IDENTIFYING_FIELDS = 5;
    private const SCORE_THRESHOLD = 0.6;

    private array $fieldScores = [];
    private array $schemaInfo = [];

    /**
     * Analyze model and return the best identifying fields
     */
    public function analyzeModel(Model $model): array
    {
        $this->fieldScores = [];
        $this->schemaInfo = [];

        // Get sample data
        $sampleData = $model->toSheetRow();

        // Get schema information if available
        $this->analyzeSchema($model);

        // Score each field
        foreach ($sampleData as $field => $value) {
            $this->scoreField($field, $value);
        }

        // Sort fields by score and filter those above threshold
        arsort($this->fieldScores);

        $identifyingFields = array_filter(
            $this->fieldScores,
            fn($score) => $score >= self::SCORE_THRESHOLD
        );

        // Take best fields within our limits
        $identifyingFields = array_slice(
            array_keys($identifyingFields),
            0,
            self::MAX_IDENTIFYING_FIELDS
        );

        // Ensure we have minimum required fields
        if (count($identifyingFields) < self::MIN_IDENTIFYING_FIELDS) {
            $identifyingFields = array_merge(
                $identifyingFields,
                array_slice(
                    array_keys($this->fieldScores),
                    count($identifyingFields),
                    self::MIN_IDENTIFYING_FIELDS - count($identifyingFields)
                )
            );
        }

        // Ensure created_at is always included if the model has timestamps
        if ($model->timestamps && !in_array('created_at', $identifyingFields)) {
            $identifyingFields[] = 'created_at';
        }

        return $identifyingFields;
    }

    /**
     * Analyze database schema for the model
     */
    private function analyzeSchema(Model $model): void
    {
        try {
            $table = $model->getTable();

            // Get indexes
            $indexes = Schema::getConnection()
                             ->getDoctrineSchemaManager()
                             ->listTableIndexes($table);

            foreach ($indexes as $index) {
                $columns = $index->getColumns();
                $score = $this->getIndexScore($index);

                foreach ($columns as $column) {
                    if (!isset($this->schemaInfo[$column])) {
                        $this->schemaInfo[$column] = 0;
                    }
                    $this->schemaInfo[$column] = max($this->schemaInfo[$column], $score);
                }
            }

            // Get foreign keys
            $foreignKeys = Schema::getConnection()
                                 ->getDoctrineSchemaManager()
                                 ->listTableForeignKeys($table);

            foreach ($foreignKeys as $foreignKey) {
                $columns = $foreignKey->getLocalColumns();
                foreach ($columns as $column) {
                    $this->schemaInfo[$column] = max(
                        $this->schemaInfo[$column] ?? 0,
                        0.4  // Base score for foreign keys
                    );
                }
            }

            // Get nullable information
            $columns = Schema::getConnection()
                             ->getDoctrineSchemaManager()
                             ->listTableColumns($table);

            foreach ($columns as $column) {
                if (!$column->getNotnull()) {
                    $this->schemaInfo[$column->getName()] =
                        ($this->schemaInfo[$column->getName()] ?? 0) * 0.8;  // Penalty for nullable
                }
            }

        } catch (\Exception $e) {
            // Silent fail - schema analysis is optional
            $this->schemaInfo = [];
        }
    }

    /**
     * Score an index based on its characteristics
     */
    private function getIndexScore(\Doctrine\DBAL\Schema\Index $index): float
    {
        if ($index->isPrimary()) {
            return 1.0;
        }

        if ($index->isUnique()) {
            return 0.9;
        }

        return 0.3;  // Regular index
    }

    /**
     * Score a field based on its name, value, and schema information
     */
    private function scoreField(string $field, $value): void
    {
        $score = 0;

        // Schema-based scoring
        if (isset($this->schemaInfo[$field])) {
            $score += $this->schemaInfo[$field];
        }

        // Name-based scoring
        if ($this->isLikelyIdentifier($field)) {
            $score += 0.3;
        }

        // Value-based scoring
        $valueScore = $this->getValueScore($value);
        $score += $valueScore;

        // Normalize final score
        $this->fieldScores[$field] = min(1.0, $score);
    }

    /**
     * Score a value based on its characteristics
     */
    private function getValueScore($value): float
    {
        if ($value === null) {
            return 0;
        }

        // Timestamp/Date handling
        if ($value instanceof \DateTime || $value instanceof \Carbon\Carbon) {
            if ($this->isLikelyCreationDate($value)) {
                return 0.8;
            }
            return 0.2;
        }

        // String handling
        if (is_string($value)) {
            $length = Str::length($value);

            // Avoid JSON
            if ($this->looksLikeJson($value)) {
                return 0;
            }

            // Avoid emoji/special characters
            if ($this->containsEmoji($value)) {
                return 0;
            }

            // Score based on length
            if ($length >= self::MIN_STRING_LENGTH && $length <= self::MAX_STRING_LENGTH) {
                return 0.6;
            }

            return 0.2;
        }

        // Number handling
        if (is_numeric($value)) {
            // Avoid small integers that might be status codes
            if (is_int($value) && $value >= 0 && $value <= 12) {
                return 0.1;
            }

            // Prefer numbers with 0-3 decimal places
            if (is_float($value)) {
                $decimals = strlen(substr(strrchr((string)$value, "."), 1));
                if ($decimals > 0 && $decimals <= 3) {
                    return 0.5;
                }
            }

            return 0.4;
        }

        // Array/Object handling
        if (is_array($value) || is_object($value)) {
            if ($this->isSimpleArrayOrObject($value)) {
                return 0.3;
            }
            return 0;
        }

        // Boolean values are poor identifiers
        if (is_bool($value)) {
            return 0;
        }

        return 0.1;  // Default score for unknown types
    }

    /**
     * Check if field name suggests it's an identifier
     */
    private function isLikelyIdentifier(string $field): bool
    {
        $identifierPatterns = [
            '/^id$/i',
            '/_id$/i',
            '/^uuid$/i',
            '/^email$/i',
            '/^username$/i',
            '/^slug$/i',
            '/^code$/i',
            '/^sku$/i',
            '/^reference$/i',
        ];

        foreach ($identifierPatterns as $pattern) {
            if (preg_match($pattern, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if date field likely represents creation time
     */
    private function isLikelyCreationDate($value): bool
    {
        $creationPatterns = [
            'created_at',
            'creation_date',
            'registered_at',
            'joined_at',
            'published_at'
        ];

        foreach ($creationPatterns as $pattern) {
            if (Str::contains($value, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if string looks like JSON
     */
    private function looksLikeJson(string $value): bool
    {
        if (!in_array($value[0] ?? '', ['{', '['])) {
            return false;
        }

        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Check if string contains emoji
     */
    private function containsEmoji(string $value): bool
    {
        $emojiPattern = '/[\x{1F300}-\x{1F64F}]|[\x{1F680}-\x{1F6FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]|[\x{1F900}-\x{1F9FF}]|[\x{1F1E0}-\x{1F1FF}]/u';
        return preg_match($emojiPattern, $value) === 1;
    }

    /**
     * Check if array/object is simple enough to use
     */
    private function isSimpleArrayOrObject($value): bool
    {
        $array = (array)$value;

        // Too many elements
        if (count($array) > 3) {
            return false;
        }

        // Check each value is simple
        foreach ($array as $item) {
            if (is_array($item) || is_object($item)) {
                return false;
            }
        }

        return true;
    }
}

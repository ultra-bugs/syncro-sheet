<?php
/**
 * S16/S17 helpers: a tab shaped like the production ledger — raw rows as
 * people type them, blank X1 (the id column has no header), date-separator
 * rows, no ids yet, `Profit` formulas, A and I formatted as TEXT.
 */
define('LEDGER_TAB', TAB . '_ledger');

/** @return array<int, array<int, mixed>> */
function ledger_values(Oracle $o, string $a1 = 'A1:Y', string $render = 'UNFORMATTED_VALUE'): array
{
    return $o->values($a1, $render, LEDGER_TAB);
}

/**
 * Recreate LEDGER_TAB from rows $from..$to of SOURCE_TAB (read only).
 *
 * @return array<int, array<int, mixed>> the rows written, header first
 */
function ledger_build(Oracle $o, int $from, int $to): array
{
    $sheetId = $o->freshTab(LEDGER_TAB, 300);
    $text = fn (int $col) => ['repeatCell' => ['range' => ['sheetId' => $sheetId, 'startColumnIndex' => $col, 'endColumnIndex' => $col + 1],
        'cell' => ['userEnteredFormat' => ['numberFormat' => ['type' => 'TEXT']]], 'fields' => 'userEnteredFormat.numberFormat']];
    gs_send($o->token(), 'POST', Oracle::base() . ':batchUpdate', ['requests' => [$text(0), $text(8)]]);

    $src = $o->values("A$from:J$to", 'FORMATTED_VALUE', SOURCE_TAB);
    $header = array_replace(array_fill(0, 24, ''), [0 => 'Date', 1 => 'Site', 2 => 'Acc', 3 => 'Dep', 4 => 'Out', 5 => 'Profit', 6 => 'Note', 7 => 'Note2', 8 => 'Date_Converted']);
    $rows = [$header];
    foreach ($src as $i => $r) {
        $n = $i + 2;
        $isTx = trim($r[2] ?? '') !== '';
        $rows[] = [
            $r[0] ?? '', $r[1] ?? '', $r[2] ?? '',
            is_numeric($r[3] ?? '') ? (float) $r[3] : '', is_numeric($r[4] ?? '') ? (float) $r[4] : '',
            $isTx ? "=SUM(E$n,-D$n)" : '', $r[6] ?? '', $r[7] ?? '', $isTx ? ($r[9] ?? '') : '',
        ];
    }
    $o->write(['A1' => $rows], 'USER_ENTERED', LEDGER_TAB);

    return $rows;
}

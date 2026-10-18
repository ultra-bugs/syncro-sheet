<?php
/**
 * Real-run harness: zuko/syncro-sheet bidirectional sync of Fitly's BetMate
 * BetTransaction against the live Google Sheets API.
 *
 * Usage: php run.php [setup] [S1 S2 ...]   (no args = setup + every scenario)
 *
 * DESTRUCTIVE on the app database (truncates the BetMate tables and the
 * tracking tables): run it on a throwaway database only (RT_SCRATCH_DB=1).
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/ledger-tab.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Zuko\Fitly\BetMate\Models\BetAccount;
use Zuko\Fitly\BetMate\Models\BetSite;
use Zuko\Fitly\BetMate\Models\BetTransaction as T;
use Zuko\SyncroSheet\Facades\SyncroSheet;
use Zuko\SyncroSheet\Services\Reconciler;
use Zuko\SyncroSheet\Services\SyncScheduler;
use Zuko\SyncroSheet\Services\TrackingDatabase;

if (! getenv('RT_SCRATCH_DB')) {
    fwrite(STDERR, "Refusing to run: the harness truncates tables. Set RT_SCRATCH_DB=1 on a throwaway database.\n");
    exit(2);
}

const SHEET_NAME_CONFIG = 'betmate.google_sheets.sheet_name';
// 1-based row ranges of SOURCE_TAB used as seed data.
define('SEED_ROWS', explode(':', getenv('RT_SEED_ROWS') ?: '5100:5242'));
define('LEDGER_ROWS', explode(':', getenv('RT_LEDGER_ROWS') ?: '5200:5242'));

boot();
$o = new Oracle();
$ctx = json_decode(@file_get_contents(STATE_FILE) ?: '{}', true);

function rt_tracking(): Illuminate\Database\Connection
{
    return DB::connection(TrackingDatabase::connectionName());
}

function rt_reset_tracking(): void
{
    foreach (['sync_entries', 'sync_snapshots', 'sync_states'] as $table) {
        rt_tracking()->table($table)->delete();
    }
}

/** Point the model at another tab for one scenario. */
function use_tab(string $tab): void
{
    config([SHEET_NAME_CONFIG => $tab]);
}

function sync(string $label): int
{
    $t = microtime(true);
    $state = SyncroSheet::sync(T::class);
    printf("    sync(%s): processed=%d status=%s %.1fs\n", $label, $state->total_processed, $state->status, microtime(true) - $t);

    return (int) $state->total_processed;
}

function dataRows(Oracle $o): array
{
    return array_values(array_filter(array_slice($o->values(), 1), fn ($r) => ($r[23] ?? '') !== ''));
}

function rec(int $id): ?T
{
    return T::withTrashed()->find($id);
}

/** Seed the DB with one transaction per account and day of SOURCE_TAB, recreate the test tab. */
function setup(Oracle $o, array &$ctx): void
{
    DB::statement('TRUNCATE bet_transactions, bet_accounts, bet_sites RESTART IDENTITY CASCADE');
    rt_reset_tracking();
    $src = $o->values('A' . SEED_ROWS[0] . ':J' . SEED_ROWS[1], 'FORMATTED_VALUE', SOURCE_TAB);
    $groups = [];
    foreach ($src as $r) {
        $acc = trim($r[2] ?? '');
        $dep = (float) ($r[3] ?? 0);
        $out = (float) ($r[4] ?? 0);
        if ($acc === '' || ($dep <= 0 && $out <= 0) || empty($r[9])) {
            continue;
        }
        $site = trim($r[1] ?? '') ?: 'vnd';
        $day = substr($r[9], 0, 10);
        $g = &$groups["$site|$acc|$day"];
        $g ??= ['site' => $site, 'acc' => $acc, 'user' => $r[8] ?? $acc, 'day' => $day, 'dep' => 0, 'out' => 0, 'notes' => [], 'bet' => 0];
        $g['dep'] += $dep;
        $g['out'] += $out;
        $g['bet'] += (float) ($r[5] ?? 0);
        if (! empty($r[6])) {
            $g['notes'][] = $r[6];
        }
        unset($g);
    }
    foreach ($groups as $g) {
        $site = BetSite::firstOrCreate(['code' => $g['site']], ['name' => strtoupper($g['site']), 'display_name' => strtoupper($g['site'])]);
        $acc = BetAccount::firstOrCreate(['site_id' => $site->id, 'label' => $g['acc']], ['username' => $g['user'] . '_' . $g['acc'], 'password' => 'x']);
        T::create([
            'account_id' => $acc->id, 'dep' => $g['dep'] ?: null, 'out' => $g['out'] ?: null, 'status' => 'completed',
            'processed_at' => $g['day'] . ' 12:00:00',
            'metadata' => ['note' => implode('; ', $g['notes']), 'note2' => '', 'bet_amount' => $g['bet']],
        ]);
    }
    $o->recreateTab((new T())->defaultSheetHeaders());
    $ctx = ['n' => T::count()];
    printf("setup: %d sites, %d accounts, %d transactions, tab %s recreated\n", BetSite::count(), BetAccount::count(), $ctx['n'], TAB);
}

$scenarios = [
    'S1' => function (Oracle $o, array &$ctx) {
        $p = sync('initial');
        $rows = dataRows($o);
        $ids = array_map(fn ($r) => (int) $r[23], $rows);
        Report::check('S1', 'processed == seeded records', $p === $ctx['n'], [$p, $ctx['n']]);
        Report::check('S1', 'one sheet row per record', count($rows) === $ctx['n'] && count(array_unique($ids)) === $ctx['n'], [count($rows), count(array_unique($ids))]);
        Report::check('S1', 'baseline seeded for every record', rt_tracking()->table('sync_snapshots')->count() === $ctx['n'], rt_tracking()->table('sync_snapshots')->count());
        $first = T::orderBy('id')->first();
        $row = $o->values('A' . $o->rowOf($first->id) . ':Y')[0] ?? [];
        Report::check('S1', 'Dep cell is a number (formulas/pivots can sum it)', is_int($row[3] ?? null) || is_float($row[3] ?? null), $row[3] ?? null);
        Report::check('S1', 'Date_Converted round-trips', ($row[8] ?? null) === $first->processed_at->format('Y-m-d H:i:s'), $row[8] ?? null);
    },
    'S2' => function (Oracle $o, array &$ctx) {
        $before = $o->values();
        $p = sync('no-op');
        Report::check('S2', 'idempotent: 0 processed', $p === 0, $p);
        Report::check('S2', 'sheet untouched', $before === $o->values());
    },
    'S3' => function (Oracle $o, array &$ctx) {
        $r = T::orderBy('id')->skip(1)->first();
        $rowIdx = $o->rowOf($r->id);
        $count = count(dataRows($o));
        $r->dep = (float) $r->dep + 7;
        $r->metadata = array_merge($r->metadata ?? [], ['note' => 'rt-db-edit']);
        $r->save();
        $p = sync('db edit');
        $row = $o->values("A$rowIdx:Y$rowIdx")[0] ?? [];
        Report::check('S3', 'processed 1', $p === 1, $p);
        Report::check('S3', 'updated in place (same row)', $o->rowOf($r->id) === $rowIdx && count(dataRows($o)) === $count);
        Report::check('S3', 'sheet Dep/Note reflect DB', (float) ($row[3] ?? 0) === (float) $r->dep && ($row[6] ?? '') === 'rt-db-edit', [$row[3] ?? null, $row[6] ?? null]);
        Report::check('S3', 'next run idempotent', sync('after db edit') === 0);
    },
    'S4' => function (Oracle $o, array &$ctx) {
        $r = T::orderBy('id')->skip(2)->first();
        $orig = $r->replicate();
        $idx = $o->rowOf($r->id);
        $o->write(["E$idx" => [[1234]], "G$idx" => [['rt-sheet-edit']]]);
        $p = sync('sheet edit');
        $r = rec($r->id);
        Report::check('S4', 'processed 1', $p === 1, $p);
        Report::check('S4', 'DB out/note updated', (float) $r->out === 1234.0 && ($r->metadata['note'] ?? null) === 'rt-sheet-edit', [$r->out, $r->metadata]);
        Report::check('S4', 'other metadata keys kept (bet_amount)', array_key_exists('bet_amount', $r->metadata ?? []), $r->metadata);
        Report::check('S4', 'account/processed_at/dep unchanged', $r->account_id === $orig->account_id
            && $r->processed_at->eq($orig->processed_at) && (float) $r->dep === (float) $orig->dep, [$r->account_id, (string) $r->processed_at, $r->dep]);
        Report::check('S4', 'next run idempotent', sync('after sheet edit') === 0);
    },
    'S5' => function (Oracle $o, array &$ctx) {
        $r = T::orderBy('id')->skip(3)->first();
        $idx = $o->rowOf($r->id);
        $r->update(['dep' => 111]);
        $o->write(["D$idx" => [[222]]]);
        sync('conflict sot=db');
        Report::check('S5', 'sot=db: sheet takes DB value', (float) ($o->values("D$idx")[0][0] ?? 0) === 111.0 && (float) rec($r->id)->dep === 111.0,
            [$o->values("D$idx")[0][0] ?? null, rec($r->id)->dep]);
        config(['syncro-sheet.defaults.source_of_truth' => 'sheet']);
        rec($r->id)->update(['out' => 333]);
        $o->write(["E$idx" => [[444]]]);
        sync('conflict sot=sheet');
        Report::check('S5', 'sot=sheet: DB takes sheet value', (float) rec($r->id)->out === 444.0 && (float) ($o->values("E$idx")[0][0] ?? 0) === 444.0,
            [rec($r->id)->out, $o->values("E$idx")[0][0] ?? null]);
        config(['syncro-sheet.defaults.source_of_truth' => 'db']);
        Report::check('S5', 'next run idempotent', sync('after conflicts') === 0);
    },
    'S6' => function (Oracle $o, array &$ctx) {
        $count = count(dataRows($o));
        $acc = T::orderBy('id')->first()->account_id;
        $new = T::create(['account_id' => $acc, 'dep' => 55, 'status' => 'completed', 'processed_at' => now()->startOfMinute(), 'metadata' => ['note' => 'rt-new-db']]);
        $p = sync('new db record');
        Report::check('S6', 'appended once', $o->rowOf($new->id) !== null && count(dataRows($o)) === $count + 1, [$p, count(dataRows($o)), $count]);
        Report::check('S6', 'next run idempotent', sync('after new db record') === 0);
    },
    'S7' => function (Oracle $o, array &$ctx) {
        $acc = T::orderBy('id')->first()->account;
        $next = count($o->values()) + 1;
        $row = array_fill(0, 25, '');
        [$row[1], $row[2], $row[3], $row[8], $row[23]] = [$acc->site->code, $acc->label, 77, '2026-10-18 09:00:00', 900000];
        $o->write(["A$next" => [$row]]);
        $dbCount = T::withTrashed()->count();
        sync('sheet row with unknown id');
        Report::check('S7', 'record 900000 created with that id', rec(900000) !== null, T::withTrashed()->orderByDesc('id')->first(['id', 'dep'])?->toArray());
        Report::check('S7', 'exactly one DB record created', T::withTrashed()->count() === $dbCount + 1, [T::withTrashed()->count(), $dbCount]);
        $p = sync('sheet row with unknown id #2');
        Report::check('S7', 'stable on next run (no duplication loop)', $p === 0 && T::withTrashed()->count() === $dbCount + 1, [$p, T::withTrashed()->count()]);
        $probe = T::create(['account_id' => $acc->id, 'dep' => 1, 'status' => 'completed', 'processed_at' => now()]);
        Report::check('S7', 'pgsql sequence moved past explicit id (next insert = 900001)', $probe->id === 900001, $probe->id);
        $probe->forceDelete();
        $next = count($o->values()) + 1;
        $row[23] = '';
        $row[3] = 88;
        $o->write(["A$next" => [$row]]);
        $dbCount = T::withTrashed()->count();
        sync('sheet row without id');
        $created = T::orderByDesc('id')->first();
        Report::check('S7', 'row without id: created once, id written back to that row',
            T::withTrashed()->count() === $dbCount + 1 && (float) $created->dep === 88.0 && $o->rowOf($created->id) === $next, [T::withTrashed()->count(), $dbCount, $o->rowOf($created->id), $next]);
        Report::check('S7', 'row without id: stable on next run', sync('sheet row without id #2') === 0 && T::withTrashed()->count() === $dbCount + 1);
    },
    'S8' => function (Oracle $o, array &$ctx) {
        $r = T::orderBy('id')->skip(4)->first();
        $r->delete();
        $total = T::withTrashed()->count();
        sync('after soft delete');
        Report::check('S8', 'soft-deleted record not resurrected as a new record', T::withTrashed()->count() === $total, [T::withTrashed()->count(), $total]);
        Report::check('S8', 'record stays trashed', rec($r->id)->trashed());
        Report::check('S8', 'next run idempotent', sync('after soft delete #2') === 0 && T::withTrashed()->count() === $total);
    },
    'S9' => function (Oracle $o, array &$ctx) {
        $r = T::orderBy('id')->skip(5)->first();
        $idx = $o->rowOf($r->id);
        $o->write(["F$idx" => [["=SUM(E$idx,-D$idx)"]], "K$idx" => [['rt-sheet-only']]]);
        sync('after formula write');
        $r->update(['dep' => (float) $r->dep + 1]);
        sync('db edit on formula row');
        $f = $o->values("A$idx:Y$idx", 'FORMULA')[0] ?? [];
        Report::check('S9', 'Profit formula preserved', ($f[5] ?? '') === "=SUM(E$idx,-D$idx)", $f[5] ?? null);
        Report::check('S9', 'sheet-only column K preserved', ($f[10] ?? '') === 'rt-sheet-only', $f[10] ?? null);
    },
    'S10' => function (Oracle $o, array &$ctx) {
        $tab = TAB . '_x';
        $o->freshTab($tab);
        use_tab($tab);
        try {
            rt_tracking()->table('sync_entries')->delete();
            $s = SyncroSheet::fullSync(T::class);
            $vals = $o->values('A1:Y', 'UNFORMATTED_VALUE', $tab);
            Report::check('S10', 'export fullSync: all live records written', $s->total_processed === T::count() && count($vals) - 1 === T::count(), [$s->total_processed, count($vals) - 1, T::count()]);
            Report::check('S10', 'export header == model headers', ($vals[0][23] ?? null) === 'id', $vals[0] ?? null);
            $id = (int) ($vals[1][23] ?? 0);
            $o->write(['E2' => [[999]]], 'USER_ENTERED', $tab);
            $s = SyncroSheet::importFull(T::class);
            Report::check('S10', 'importFull applied sheet edit', (float) rec($id)?->out === 999.0, [$s->total_processed, rec($id)?->out]);
        } finally {
            use_tab(TAB);
        }
    },
    'S11' => function (Oracle $o, array &$ctx) {
        $r = T::orderBy('id')->skip(6)->first();
        $r->update(['dep' => (float) $r->dep + 3]);
        $idx = $o->rowOf($r->id);
        config(['syncro-sheet.scheduler.driver' => 'queue', 'syncro-sheet.scheduler.queue.connection' => 'sync',
            'syncro-sheet.scheduler.models' => [T::class => 'both']]);
        app(SyncScheduler::class)->run();
        Report::check('S11', 'queue driver propagated DB edit', (float) ($o->values("D$idx")[0][0] ?? 0) === (float) $r->dep, [$o->values("D$idx")[0][0] ?? null, $r->dep]);
        Report::check('S11', 'inline run after queue run idempotent', sync('after queue driver') === 0);
    },
    'S12' => function (Oracle $o, array &$ctx) {
        $code = Artisan::call('sheet:sync', ['model' => T::class, '--direction' => 'both']);
        echo '    ' . trim(Artisan::output()) . "\n";
        Report::check('S12', 'artisan sheet:sync --direction=both exits 0', $code === 0, $code);
    },
    'S13' => function (Oracle $o, array &$ctx) {
        // A tab name that is also an A1 reference (column RT, row 2026).
        $tab = 'RT2026';
        $o->freshTab($tab);
        use_tab($tab);
        try {
            rt_tracking()->table('sync_snapshots')->delete();
            $p = sync('A1-like tab name');
            $vals = $o->values('A1:Y', 'UNFORMATTED_VALUE', $tab);
            Report::check('S13', 'sync targets the tab named RT2026 (quoted range)', $p === T::count() && count($vals) - 1 === T::count(), [$p, count($vals) - 1]);
        } finally {
            use_tab(TAB);
        }
    },
    'S16' => function (Oracle $o, array &$ctx) {
        $rows = ledger_build($o, (int) LEDGER_ROWS[0], (int) LEDGER_ROWS[1]);
        $expected = 0;
        foreach (array_slice($rows, 1) as $r) {
            $acc = BetAccount::query()->whereHas('site', fn ($q) => $q->where('code', ($r[1] ?: 'vnd')))->where('label', $r[2])->exists();
            $expected += ($r[2] !== '' && ((float) $r[3] > 0 || (float) $r[4] > 0) && $acc) ? 1 : 0;
        }
        DB::statement('TRUNCATE bet_transactions RESTART IDENTITY CASCADE');
        rt_reset_tracking();
        use_tab(LEDGER_TAB);
        try {
            $before = ledger_values($o, 'A1:I', 'FORMULA');
            $p1 = sync('ledger-like, rows without id');
            $after = ledger_values($o, 'A1:Y', 'FORMULA');
            $ids = [];
            $bad = [];
            foreach (array_slice($after, 1) as $i => $r) {
                if (($r[23] ?? '') !== '') {
                    $ids[] = $r[23];
                    $t = rec((int) $r[23]);
                    if (! $t || $t->account->label !== $r[2] || abs((float) $t->dep - (float) ($r[3] ?: 0)) > 0.001) {
                        $bad[] = $i + 2;
                    }
                } elseif (($r[2] ?? '') !== '') {
                    $bad[] = 'no id @' . ($i + 2);
                }
            }
            Report::check('S16', "created one record per transaction row ($expected)", $p1 === $expected && T::count() === $expected, [$p1, T::count(), $expected]);
            Report::check('S16', 'every transaction row got its own id in X, matching the record', count($ids) === $expected && count(array_unique($ids)) === $expected && $bad === [], array_slice($bad, 0, 5));
            Report::check('S16', 'separator rows untouched (no id)', ($after[count($after) - 1][23] ?? '') === '' && ($after[count($after) - 1][0] ?? '') === ($before[count($before) - 1][0] ?? ''), $after[count($after) - 1] ?? null);
            $kept = array_filter(array_keys($before), fn ($i) => ($before[$i][5] ?? '') !== ($after[$i][5] ?? '') || ($before[$i][0] ?? '') !== ($after[$i][0] ?? ''));
            Report::check('S16', 'read-only cells untouched: Profit formulas (F) and day labels (A)', $kept === [], array_slice(array_values($kept), 0, 5));
            $changed = [];
            foreach (array_keys($before) as $i) {
                foreach ([1, 2, 3, 4, 6, 7, 8] as $c) {
                    if ((string) ($before[$i][$c] ?? '') !== (string) ($after[$i][$c] ?? '')) {
                        $changed[] = [$i + 1, $c, $before[$i][$c] ?? '', $after[$i][$c] ?? ''];
                    }
                }
            }
            echo '    cells rewritten by the write-back (normalisation): ' . count($changed) . ' e.g. ' . json_encode(array_slice($changed, 0, 3), JSON_UNESCAPED_UNICODE) . "\n";
            Report::check('S16', 'next run stable (no new records, nothing processed)', sync('ledger-like #2') === 0 && T::count() === $expected, T::count());
        } finally {
            use_tab(TAB);
        }
    },
    'S17' => function (Oracle $o, array &$ctx) {
        // A person types a new row, then inserts a row above it before the plan is applied.
        use_tab(LEDGER_TAB);
        try {
            $acc = BetAccount::query()->whereNotNull('label')->first();
            $last = count(ledger_values($o)) + 1;
            $o->write(["A$last" => [['', $acc->site->code, $acc->label, 321, '', "=SUM(E$last,-D$last)", 'rt-typed', '', '2026-10-18 10:00:00']]], 'USER_ENTERED', LEDGER_TAB);
            $count = T::count();
            $reconciler = app(Reconciler::class);
            $plan = $reconciler->decide(T::class);
            gs_send($o->token(), 'POST', Oracle::base() . ':batchUpdate', ['requests' => [['insertDimension' => [
                'range' => ['sheetId' => $o->tabId(LEDGER_TAB), 'dimension' => 'ROWS', 'startIndex' => 1, 'endIndex' => 2], 'inheritFromBefore' => false]]]]);
            $reconciler->applyChunk(T::class, $plan['decisions']);
            $vals = ledger_values($o);
            Report::check('S17', 'moved row: nothing created, no id written to a wrong row', T::count() === $count && ($vals[1][23] ?? '') === '' && ($vals[$last][23] ?? '') === '', [T::count(), $count, $vals[$last] ?? null]);
            sync('after the insert');
            $vals = ledger_values($o);
            $typed = rec((int) ($vals[$last][23] ?? 0));
            Report::check('S17', 'next run creates it once, id on the right (shifted) row', T::count() === $count + 1 && $typed?->dep == 321 && ($typed->metadata['note'] ?? null) === 'rt-typed', [T::count(), $vals[$last] ?? null]);
            Report::check('S17', 'stable afterwards', sync('after the insert #2') === 0 && T::count() === $count + 1);
        } finally {
            use_tab(TAB);
        }
    },
    'S14' => function (Oracle $o, array &$ctx) {
        // Scale: the whole source tab aggregated per account and day, synced to its own tab.
        $tab = TAB . '_scale';
        $src = $o->values('A2:J', 'FORMATTED_VALUE', SOURCE_TAB);
        DB::statement('TRUNCATE bet_transactions RESTART IDENTITY CASCADE');
        rt_reset_tracking();
        $groups = [];
        foreach ($src as $r) {
            if (trim($r[2] ?? '') === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $r[9] ?? '') || ((float) ($r[3] ?? 0) <= 0 && (float) ($r[4] ?? 0) <= 0)) {
                continue;
            }
            $k = strtolower(trim($r[1] ?? '') ?: 'vnd') . '|' . trim($r[2]) . '|' . substr($r[9], 0, 10);
            $groups[$k] ??= ['dep' => 0, 'out' => 0, 'user' => $r[8] ?? ''];
            $groups[$k]['dep'] += (float) ($r[3] ?? 0);
            $groups[$k]['out'] += (float) ($r[4] ?? 0);
        }
        $rows = [];
        foreach ($groups as $k => $g) {
            [$siteCode, $label, $day] = explode('|', $k);
            $site = BetSite::firstOrCreate(['code' => $siteCode], ['name' => strtoupper($siteCode), 'display_name' => strtoupper($siteCode)]);
            $acc = BetAccount::firstOrCreate(['site_id' => $site->id, 'label' => $label], ['username' => $g['user'] . '_' . $label, 'password' => 'x']);
            $rows[] = ['account_id' => $acc->id, 'dep' => $g['dep'] ?: null, 'out' => $g['out'] ?: null, 'status' => 'completed',
                'processed_at' => "$day 12:00:00", 'metadata' => json_encode(['note' => '']), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('bet_transactions')->insert($chunk);
        }
        $o->freshTab($tab, count($rows) + 50);
        use_tab($tab);
        try {
            $n = T::count();
            $p1 = sync("scale initial ($n records)");
            $p2 = sync('scale no-op');
            T::orderBy('id')->skip(10)->first()->update(['dep' => 4242]);
            $p3 = sync('scale one edit');
            $vals = $o->values('A1:Y', 'UNFORMATTED_VALUE', $tab);
            Report::check('S14', "scale: $n records exported once, no-op, single edit", $p1 === $n && $p2 === 0 && $p3 === 1 && count($vals) - 1 === $n, [$p1, $p2, $p3, count($vals) - 1]);
        } finally {
            use_tab(TAB);
        }
    },
];

$args = array_slice($argv, 1) ?: array_merge(['setup'], array_keys($scenarios));
foreach ($args as $name) {
    echo "== $name\n";
    try {
        $name === 'setup' ? setup($o, $ctx) : $scenarios[$name]($o, $ctx);
    } catch (\Throwable $e) {
        Report::check($name, 'scenario crashed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    file_put_contents(STATE_FILE, json_encode($ctx));
}
exit(Report::summary());

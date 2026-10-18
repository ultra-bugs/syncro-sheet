<?php
/**
 * Real-run harness helpers: boot the host app, read/write the sheet through an
 * independent REST client (Oracle), collect PASS/FAIL checks (Report).
 *
 * Environment (see README.md):
 *   RT_APP              path of the Laravel app (default: cwd)
 *   RT_SPREADSHEET      spreadsheet id holding the test tabs (required)
 *   RT_SERVICE_ACCOUNT  service-account JSON with edit access (required)
 *   RT_TAB              test tab, recreated by `setup` (default: syncro_rt)
 *   RT_SOURCE_TAB       tab the seed data is copied from, read only (default: Thu chi)
 *   RT_PGLITE=1         emulate prepares on every pgsql connection (PGlite)
 */
require __DIR__ . '/sheets-rest.php';

define('APP', rtrim(getenv('RT_APP') ?: getcwd(), '/'));
define('SPREADSHEET', getenv('RT_SPREADSHEET') ?: throw new RuntimeException('RT_SPREADSHEET is required'));
define('SA', getenv('RT_SERVICE_ACCOUNT') ?: throw new RuntimeException('RT_SERVICE_ACCOUNT is required'));
define('TAB', getenv('RT_TAB') ?: 'syncro_rt');
define('SOURCE_TAB', getenv('RT_SOURCE_TAB') ?: 'Thu chi');
define('STATE_FILE', sys_get_temp_dir() . '/syncro-rt-' . TAB . '.json');

function boot(): Illuminate\Foundation\Application
{
    if (TAB === SOURCE_TAB) {
        throw new RuntimeException('Refusing to run: RT_TAB must not be the source tab');
    }
    // The model reads its tab name from config: point it at the test tab.
    putenv('BETMATE_GOOGLE_SHEETS_SHEET_NAME=' . TAB);
    $_ENV['BETMATE_GOOGLE_SHEETS_SHEET_NAME'] = TAB;
    $_SERVER['BETMATE_GOOGLE_SHEETS_SHEET_NAME'] = TAB;
    chdir(APP);
    require APP . '/vendor/autoload.php';
    $app = require APP . '/bootstrap/app.php';
    if (getenv('RT_PGLITE')) {
        pglite_patch($app);
    }
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('betmate.google_sheets.sheet_name') !== TAB) {
        throw new RuntimeException('Refusing to run: sheet_name is ' . config('betmate.google_sheets.sheet_name'));
    }
    // The app's own observer push would write the same tab concurrently.
    if (config('betmate.google_sheets.enabled')) {
        throw new RuntimeException('Refusing to run: betmate.google_sheets.enabled must be false');
    }

    return $app;
}

/**
 * PGlite (pglite-socket) drops the connection after an error in the extended
 * query protocol; emulated prepares keep PDO on the simple protocol.
 */
function pglite_patch(Illuminate\Foundation\Application $app): void
{
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) {
        foreach ($app['config']->get('database.connections', []) as $name => $connection) {
            if (($connection['driver'] ?? null) === 'pgsql') {
                $app['config']->set("database.connections.$name.options", [PDO::ATTR_EMULATE_PREPARES => true]);
            }
        }
    });
}

final class Oracle
{
    private string $token;

    public function __construct()
    {
        $this->token = gs_token(SA);
    }

    public function token(): string
    {
        return $this->token;
    }

    public static function base(): string
    {
        return 'https://sheets.googleapis.com/v4/spreadsheets/' . SPREADSHEET;
    }

    /** @return array<int, array<int, mixed>> */
    public function values(string $a1 = 'A1:Y', string $render = 'UNFORMATTED_VALUE', string $tab = TAB): array
    {
        $range = rawurlencode("'" . $tab . "'!" . $a1);

        return gs_get($this->token, self::base() . "/values/$range?valueRenderOption=$render")['values'] ?? [];
    }

    public function tabId(string $tab = TAB): ?int
    {
        foreach (gs_get($this->token, self::base() . '?fields=sheets.properties')['sheets'] as $s) {
            if ($s['properties']['title'] === $tab) {
                return $s['properties']['sheetId'];
            }
        }

        return null;
    }

    /** Delete $tab when present and add it again, empty. Returns the new sheetId. */
    public function freshTab(string $tab, int $rows = 400): int
    {
        if ($tab === SOURCE_TAB) {
            throw new RuntimeException('Refusing to recreate the source tab');
        }
        if (($id = $this->tabId($tab)) !== null) {
            gs_send($this->token, 'POST', self::base() . ':batchUpdate', ['requests' => [['deleteSheet' => ['sheetId' => $id]]]]);
        }
        $add = gs_send($this->token, 'POST', self::base() . ':batchUpdate', ['requests' => [['addSheet' => ['properties' => [
            'title' => $tab, 'gridProperties' => ['rowCount' => $rows, 'columnCount' => 26, 'frozenRowCount' => 1],
        ]]]]]);

        return $add['replies'][0]['addSheet']['properties']['sheetId'];
    }

    public function recreateTab(array $headerRow): void
    {
        $this->freshTab(TAB);
        $this->write(['A1' => [$headerRow]], 'RAW');
    }

    /** Human-like edit: USER_ENTERED, like typing in the UI. */
    public function write(array $cells, string $input = 'USER_ENTERED', string $tab = TAB): void
    {
        $data = [];
        foreach ($cells as $a1 => $values) {
            $data[] = ['range' => "'" . $tab . "'!" . $a1, 'values' => $values];
        }
        gs_send($this->token, 'POST', self::base() . '/values:batchUpdate', ['valueInputOption' => $input, 'data' => $data]);
    }

    /** 1-based sheet row holding $id in column X. */
    public function rowOf(int|string $id): ?int
    {
        foreach ($this->values('X1:X') as $i => $row) {
            if ((string) ($row[0] ?? '') === (string) $id) {
                return $i + 1;
            }
        }

        return null;
    }
}

final class Report
{
    public static array $results = [];

    public static function check(string $scenario, string $what, bool $ok, mixed $detail = null): void
    {
        self::$results[] = [$scenario, $what, $ok, $detail];
        printf("  [%s] %s — %s%s\n", $ok ? 'PASS' : 'FAIL', $scenario, $what,
            $ok || $detail === null ? '' : ' :: ' . substr(json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 600));
    }

    public static function summary(): int
    {
        $fail = array_filter(self::$results, fn ($r) => ! $r[2]);
        printf("\n%d checks, %d failed\n", count(self::$results), count($fail));

        return $fail === [] ? 0 : 1;
    }
}

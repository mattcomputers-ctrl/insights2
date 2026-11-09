<?php
/**
 * scripts/probe-cms-schema.php
 *
 * One-shot CMS schema probe for figuring out where the replacement cost
 * lives and how items link to their packaging prototype, so Margin
 * Watchdog can compute the "expected packed cost" forward-looking metric.
 *
 * Run on a host that already has CMS DB connectivity:
 *
 *   # Easiest — reuse the existing SDS server's CMS credentials:
 *   sudo -u www-data php /var/www/sds-system/scripts/probe-cms-schema.php > /tmp/cms-probe.log 2>&1
 *
 *   # Or once Precision Ink Insights is deployed with cms_db configured:
 *   sudo -u www-data php /var/www/precision-ink-insights/scripts/probe-cms-schema.php > /tmp/cms-probe.log 2>&1
 *
 * Then paste /tmp/cms-probe.log back to me. Read-only — no writes.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

// ----- Locate a config that has a cms_db section --------------------
$configCandidates = [
    __DIR__ . '/../config/config.php',         // this project, when deployed
    '/var/www/sds-system/config/config.php',   // SDS server fallback (already has CMS creds)
];

$configFile = null;
foreach ($configCandidates as $path) {
    if (file_exists($path) && is_readable($path)) {
        $cfg = @include $path;
        if (is_array($cfg) && !empty($cfg['cms_db']['host']) && ($cfg['cms_db']['password'] ?? '') !== 'CHANGE_ME') {
            $configFile = $path;
            $config     = $cfg;
            break;
        }
    }
}

if ($configFile === null) {
    fwrite(STDERR, "No usable config with cms_db found in:\n  - " . implode("\n  - ", $configCandidates) . "\n");
    exit(1);
}

echo "Using config: $configFile\n\n";

// ----- Connect to CMS via PDO ---------------------------------------
$cms = $config['cms_db'];
$drivers = PDO::getAvailableDrivers();

if (in_array('sqlsrv', $drivers, true)) {
    $dsn = sprintf('sqlsrv:Server=%s,%d;Database=%s', $cms['host'], $cms['port'] ?? 1433, $cms['name']);
} elseif (in_array('dblib', $drivers, true)) {
    $dsn = sprintf('dblib:host=%s:%d;dbname=%s', $cms['host'], $cms['port'] ?? 1433, $cms['name']);
} else {
    fwrite(STDERR, "No SQL Server PDO driver (sqlsrv/dblib) available.\n");
    exit(1);
}

try {
    $pdo = new PDO($dsn, $cms['user'], $cms['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "CMS connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ----- Helpers ------------------------------------------------------
function dump_section(string $title, callable $cb): void
{
    echo "\n";
    echo "===========================================================\n";
    echo "  $title\n";
    echo "===========================================================\n";
    try {
        $cb();
    } catch (\Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

/**
 * Print one row as "column: value" lines, skipping uninteresting columns.
 */
function print_row(array $row, array $skipColumns = []): void
{
    foreach ($row as $col => $val) {
        if (in_array($col, $skipColumns, true)) continue;
        if (is_string($val) && strlen($val) > 200) {
            $val = substr($val, 0, 200) . '…(truncated)';
        }
        echo "  " . str_pad($col, 30) . " = " . (is_null($val) ? 'NULL' : (string) $val) . "\n";
    }
}

function print_table(array $rows): void
{
    if (empty($rows)) {
        echo "  (no rows)\n";
        return;
    }
    foreach ($rows as $i => $r) {
        echo "  -- row " . ($i + 1) . " --\n";
        print_row($r);
    }
}

/**
 * Find every column on $row whose value is numerically close to $target.
 * Useful for "where does 3.4344 live?".
 */
function find_value_columns(array $row, float $target, float $tol = 0.0001): array
{
    $hits = [];
    foreach ($row as $col => $val) {
        if (is_numeric($val) && abs(((float) $val) - $target) < $tol) {
            $hits[] = "$col = $val";
        }
    }
    return $hits;
}

// ----- 1. Item E1055 — find which column has 3.4344 -----------------
dump_section('1. Item E1055 — full row (replacement cost should be 3.4344 in some column)', function () use ($pdo) {
    $row = $pdo->query("SELECT * FROM CMS.dbo.Item WHERE ItemCode = 'E1055'")->fetch();
    if (!$row) {
        echo "  (E1055 not found)\n";
        return;
    }
    print_row($row);

    echo "\n  >>> columns whose value matches 3.4344:\n";
    foreach (find_value_columns($row, 3.4344) as $hit) {
        echo "      $hit\n";
    }
});

// ----- 2. Same item via the priced views (in case rep cost lives there) -----
dump_section('2. PriceDetailExt for E1055', function () use ($pdo) {
    try {
        $rows = $pdo->query("SELECT TOP 5 * FROM CMS.dbo.PriceDetailExt WHERE ItemCode = 'E1055' OR InvItemCode = 'E1055'")->fetchAll();
        print_table($rows);
        foreach ($rows as $r) {
            $hits = find_value_columns($r, 3.4344);
            if (!empty($hits)) {
                echo "\n  >>> 3.4344 found in PriceDetailExt: " . implode(', ', $hits) . "\n";
            }
        }
    } catch (\Throwable $e) {
        echo "  (skipped: " . $e->getMessage() . ")\n";
    }
});

dump_section('3. PurchasePriceDetails for E1055', function () use ($pdo) {
    try {
        $rows = $pdo->query("SELECT TOP 5 * FROM CMS.dbo.PurchasePriceDetails WHERE ItemCode = 'E1055' OR InvItemCode = 'E1055'")->fetchAll();
        print_table($rows);
        foreach ($rows as $r) {
            $hits = find_value_columns($r, 3.4344);
            if (!empty($hits)) {
                echo "\n  >>> 3.4344 found in PurchasePriceDetails: " . implode(', ', $hits) . "\n";
            }
        }
    } catch (\Throwable $e) {
        echo "  (skipped: " . $e->getMessage() . ")\n";
    }
});

// ----- 4. Look at the Item table's column list (just the columns) ---
dump_section('4. CMS.dbo.Item — column list (look for anything cost-related)', function () use ($pdo) {
    $rows = $pdo->query("
        SELECT COLUMN_NAME, DATA_TYPE
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Item'
         ORDER BY ORDINAL_POSITION
    ")->fetchAll();
    foreach ($rows as $r) {
        echo "  " . str_pad($r['COLUMN_NAME'], 35) . $r['DATA_TYPE'] . "\n";
    }
});

// ----- 5. E1055-50 — full row + how it links to prototype 50 --------
dump_section('5. Item E1055-50 — full row (look for any column whose value = the prototype Item PK for "50")', function () use ($pdo) {
    $row = $pdo->query("SELECT * FROM CMS.dbo.Item WHERE ItemCode = 'E1055-50'")->fetch();
    if (!$row) {
        echo "  (E1055-50 not found)\n";
        return;
    }
    print_row($row);
});

// ----- 6. Find prototype "50" itself --------------------------------
dump_section('6. Packaging prototype: Item with ItemCode = "50"', function () use ($pdo) {
    $row = $pdo->query("SELECT * FROM CMS.dbo.Item WHERE ItemCode = '50'")->fetch();
    if (!$row) {
        echo "  (Item with ItemCode='50' not found — try a different identifier)\n";
        return;
    }
    print_row($row);
});

// ----- 7. The prototype's CostingRecipe and its lines ---------------
dump_section('7. Prototype 50: CostingRecipe + RecipeDetail lines (looking for 5LBPT @ 0.215)', function () use ($pdo) {
    $rows = $pdo->query("
        SELECT i.ItemCode  AS PrototypeCode,
               r.Recipe, r.RecipeNumber,
               rd.RecipeDetail, rd.Context AS RDContext, rd.Line, rd.QtyReqd,
               ing.ItemCode AS IngredientCode, ing.Description AS IngredientDesc, ing.Context AS IngredientContext
          FROM CMS.dbo.Item i
          LEFT JOIN CMS.dbo.Recipe r        ON r.Recipe   = i.CostingRecipe
          LEFT JOIN CMS.dbo.RecipeDetail rd ON rd.Recipe  = r.Recipe
          LEFT JOIN CMS.dbo.Item ing        ON ing.Item   = rd.Item
         WHERE i.ItemCode = '50'
         ORDER BY rd.Context, rd.Line
    ")->fetchAll();
    print_table($rows);
});

// ----- 8. E1055's CostingRecipe and its lines (does it have PK rows?) -----
dump_section('8. E1055 CostingRecipe + RecipeDetail (do PK lines exist on the item recipe itself?)', function () use ($pdo) {
    $rows = $pdo->query("
        SELECT i.ItemCode, r.Recipe, r.RecipeNumber,
               rd.Context AS RDContext, rd.Line, rd.QtyReqd,
               ing.ItemCode AS IngredientCode, ing.Description AS IngredientDesc, ing.Context AS IngredientContext
          FROM CMS.dbo.Item i
          LEFT JOIN CMS.dbo.Recipe r        ON r.Recipe   = i.CostingRecipe
          LEFT JOIN CMS.dbo.RecipeDetail rd ON rd.Recipe  = r.Recipe
          LEFT JOIN CMS.dbo.Item ing        ON ing.Item   = rd.Item
         WHERE i.ItemCode = 'E1055'
         ORDER BY rd.Context, rd.Line
    ")->fetchAll();
    print_table($rows);
});

// ----- 9. 5LBPT — find its replacement cost --------------------------
dump_section('9. Packaging ingredient 5LBPT — full row (replacement cost lookup test)', function () use ($pdo) {
    $row = $pdo->query("SELECT * FROM CMS.dbo.Item WHERE ItemCode = '5LBPT'")->fetch();
    if (!$row) {
        echo "  (5LBPT not found)\n";
        return;
    }
    print_row($row);
});

// ----- 10. Probe Item columns that might link to prototype ----------
dump_section('10. Columns on Item with names suggesting packaging/prototype linkage', function () use ($pdo) {
    $rows = $pdo->query("
        SELECT COLUMN_NAME, DATA_TYPE
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = 'dbo'
           AND TABLE_NAME = 'Item'
           AND (COLUMN_NAME LIKE '%Pkg%'
             OR COLUMN_NAME LIKE '%Package%'
             OR COLUMN_NAME LIKE '%Prototype%'
             OR COLUMN_NAME LIKE '%Pack%')
         ORDER BY COLUMN_NAME
    ")->fetchAll();
    print_table($rows);
});

echo "\nDone.\n";

<?php

declare(strict_types=1);

require __DIR__.'/coverage-tiers.php';

$args = array_slice($argv, 1);
$cloverPath = null;
$mode = 'ci';
$format = 'text';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--mode=')) {
        $mode = substr($arg, 7);
    } elseif (str_starts_with($arg, '--format=')) {
        $format = substr($arg, 9);
    } elseif (! str_starts_with($arg, '--')) {
        $cloverPath = $arg;
    }
}

if ($cloverPath === null || ! is_readable($cloverPath)) {
    fwrite(STDERR, "Usage: check-coverage.php <clover.xml> [--mode=ci|local] [--format=text|markdown]\n");
    exit(2);
}
if (! isset(THRESHOLDS[$mode])) {
    fwrite(STDERR, "Invalid mode: {$mode} (expected: ci|local)\n");
    exit(2);
}

$thresholds = THRESHOLDS[$mode];
$repoRoot = realpath(__DIR__.'/..');

libxml_use_internal_errors(true);
$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse Clover XML: {$cloverPath}\n");
    exit(2);
}

$files = [];
foreach ($xml->xpath('//file') as $fileNode) {
    $abs = (string) $fileNode['name'];
    if (! str_starts_with($abs, $repoRoot.'/')) {
        continue;
    }
    $rel = substr($abs, strlen($repoRoot) + 1);
    if (! str_starts_with($rel, 'app/')) {
        continue;
    }
    $metrics = $fileNode->metrics;
    if ($metrics === null) {
        continue;
    }
    $stmts = (int) $metrics['statements'];
    if ($stmts === 0) {
        continue;
    }
    $covered = (int) $metrics['coveredstatements'];
    $files[$rel] = [
        'statements' => $stmts,
        'covered' => $covered,
        'pct' => ($covered / $stmts) * 100.0,
    ];
}

$tier1 = [];
$tier2 = [];
$global = $files;
foreach ($files as $path => $data) {
    if (in_array($path, TIER1_FILES, true)) {
        $tier1[$path] = $data;

        continue;
    }
    foreach (TIER2_GLOBS as $glob) {
        if (fnmatch($glob, $path)) {
            $tier2[$path] = $data;
            break;
        }
    }
}

$tier1Failures = [];
foreach (TIER1_FILES as $path) {
    if (! isset($tier1[$path])) {
        $tier1Failures[$path] = ['pct' => null, 'reason' => 'not found in coverage report'];

        continue;
    }
    $pct = $tier1[$path]['pct'];
    if ($pct < $thresholds['tier1']) {
        $tier1Failures[$path] = [
            'pct' => $pct,
            'reason' => sprintf('%.2f%% < %.0f%%', $pct, $thresholds['tier1']),
        ];
    }
}

$aggregate = static function (array $bucket): float {
    $s = 0;
    $c = 0;
    foreach ($bucket as $f) {
        $s += $f['statements'];
        $c += $f['covered'];
    }

    return $s === 0 ? 100.0 : ($c / $s) * 100.0;
};

$tier2Pct = $aggregate($tier2);
$globalPct = $aggregate($global);

$tier1Pass = empty($tier1Failures);
$tier2Pass = $tier2Pct >= $thresholds['tier2'];
$globalPass = $globalPct >= $thresholds['global'];
$pass = $tier1Pass && $tier2Pass && $globalPass;

if ($format === 'markdown') {
    $status = $pass ? '✅ PASS' : '❌ FAIL';
    echo "## Coverage report\n\n";
    echo "**Mode:** `{$mode}` · **Status:** {$status}\n\n";
    echo "| Tier | Threshold | Result | Status |\n";
    echo "|------|-----------|--------|--------|\n";
    $t1Total = count(TIER1_FILES);
    $t1ok = $t1Total - count($tier1Failures);
    echo sprintf(
        "| Tier 1 (critical, per-file) | %.0f%% | %d/%d files pass | %s |\n",
        $thresholds['tier1'],
        $t1ok,
        $t1Total,
        $tier1Pass ? '✅' : '❌'
    );
    echo sprintf(
        "| Tier 2 (domain logic, aggregate) | %.0f%% | %.2f%% | %s |\n",
        $thresholds['tier2'],
        $tier2Pct,
        $tier2Pass ? '✅' : '❌'
    );
    echo sprintf(
        "| Global (aggregate) | %.0f%% | %.2f%% | %s |\n",
        $thresholds['global'],
        $globalPct,
        $globalPass ? '✅' : '❌'
    );
    if (! empty($tier1Failures)) {
        echo "\n### Tier 1 failures\n\n";
        echo "| File | Coverage | Issue |\n";
        echo "|------|----------|-------|\n";
        foreach ($tier1Failures as $path => $info) {
            $pctStr = $info['pct'] === null ? '—' : sprintf('%.2f%%', $info['pct']);
            echo "| `{$path}` | {$pctStr} | {$info['reason']} |\n";
        }
    }
    echo "\n<details><summary>Tier 1 — all files</summary>\n\n";
    echo "| File | Coverage |\n|------|----------|\n";
    foreach (TIER1_FILES as $path) {
        $pct = $tier1[$path]['pct'] ?? null;
        $pctStr = $pct === null ? '— (not in report)' : sprintf('%.2f%%', $pct);
        echo "| `{$path}` | {$pctStr} |\n";
    }
    echo "\n</details>\n";
} else {
    echo "Coverage report (mode: {$mode})\n";
    $t1Total = count(TIER1_FILES);
    $t1ok = $t1Total - count($tier1Failures);
    echo sprintf(
        "  Tier 1 (critical, per-file >= %.0f%%): %d/%d pass\n",
        $thresholds['tier1'],
        $t1ok,
        $t1Total
    );
    foreach ($tier1Failures as $path => $info) {
        echo "    ✗ {$path} — {$info['reason']}\n";
    }
    echo sprintf(
        "  Tier 2 (domain logic, aggregate >= %.0f%%): %.2f%% %s\n",
        $thresholds['tier2'],
        $tier2Pct,
        $tier2Pass ? '✓' : '✗'
    );
    echo sprintf(
        "  Global (aggregate >= %.0f%%): %.2f%% %s\n",
        $thresholds['global'],
        $globalPct,
        $globalPass ? '✓' : '✗'
    );
    echo "\n".($pass ? 'PASS' : 'FAIL')."\n";
}

exit($pass ? 0 : 1);

<?php

declare(strict_types=1);

require __DIR__.'/coverage-tiers.php';

$args = array_slice($argv, 1);
$cloverPath = null;
$mode = 'ci';
$outputPath = null;
$changedFiles = [];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--mode=')) {
        $mode = substr($arg, 7);
    } elseif (str_starts_with($arg, '--output=')) {
        $outputPath = substr($arg, 9);
    } elseif (str_starts_with($arg, '--changed-files=')) {
        $raw = substr($arg, 16);
        $changedFiles = array_values(array_filter(array_map('trim', explode(',', $raw))));
    } elseif (! str_starts_with($arg, '--')) {
        $cloverPath = $arg;
    }
}

if ($cloverPath === null || ! is_readable($cloverPath)) {
    fwrite(STDERR, "Usage: pr-comment.php <clover.xml> [--changed-files=a,b] [--mode=ci|local] [--output=file.md]\n");
    exit(2);
}
if (! isset(THRESHOLDS[$mode])) {
    fwrite(STDERR, "Invalid mode: {$mode}\n");
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

$byTier = ['tier1' => [], 'tier2' => [], 'global' => []];
foreach ($files as $path => $data) {
    $t = coverage_tier_of($path);
    if ($t === 'tier1') {
        $byTier['tier1'][$path] = $data;
    } elseif ($t === 'tier2') {
        $byTier['tier2'][$path] = $data;
    }
    $byTier['global'][$path] = $data;
}

$aggregate = static function (array $bucket): array {
    $s = 0;
    $c = 0;
    foreach ($bucket as $f) {
        $s += $f['statements'];
        $c += $f['covered'];
    }
    $pct = $s === 0 ? 100.0 : ($c / $s) * 100.0;

    return ['statements' => $s, 'covered' => $c, 'pct' => $pct];
};

$tier1Failures = [];
foreach (TIER1_FILES as $path) {
    if (! isset($byTier['tier1'][$path])) {
        $tier1Failures[$path] = null;

        continue;
    }
    if ($byTier['tier1'][$path]['pct'] < $thresholds['tier1']) {
        $tier1Failures[$path] = $byTier['tier1'][$path]['pct'];
    }
}
$tier2Agg = $aggregate($byTier['tier2']);
$globalAgg = $aggregate($byTier['global']);

$tier1Pass = empty($tier1Failures);
$tier2Pass = $tier2Agg['pct'] >= $thresholds['tier2'];
$globalPass = $globalAgg['pct'] >= $thresholds['global'];
$pass = $tier1Pass && $tier2Pass && $globalPass;

$badgeColor = static function (float $pct): string {
    if ($pct >= 80.0) {
        return 'brightgreen';
    }
    if ($pct >= 70.0) {
        return 'green';
    }
    if ($pct >= 60.0) {
        return 'yellow';
    }
    if ($pct >= 50.0) {
        return 'orange';
    }

    return 'red';
};

$fileIcon = static function (?float $pct, string $tier) use ($thresholds): string {
    if ($pct === null) {
        return '❓';
    }
    if ($tier === 'tier1') {
        return $pct >= $thresholds['tier1'] ? '✅' : '❌';
    }
    if ($pct >= 80.0) {
        return '✅';
    }
    if ($pct >= 60.0) {
        return '⚠️';
    }

    return '❌';
};

$tierLabel = static function (string $tier): string {
    return match ($tier) {
        'tier1' => 'Tier 1',
        'tier2' => 'Tier 2',
        default => '—',
    };
};

$globalPctStr = number_format($globalAgg['pct'], 2);
$badgeUrl = sprintf(
    'https://img.shields.io/badge/Coverage-%s%%25-%s',
    rawurlencode($globalPctStr),
    $badgeColor($globalAgg['pct'])
);

ob_start();
echo "<!-- sam-coverage-report -->\n";
echo "## 📊 Coverage Report\n\n";

$statusLine = $pass ? '✅ **PASS**' : '❌ **FAIL**';
echo "![Coverage]({$badgeUrl}) {$statusLine} · mode: `{$mode}`\n\n";
echo sprintf(
    "**Overall:** %.2f%% (%d / %d statements covered)\n\n",
    $globalAgg['pct'],
    $globalAgg['covered'],
    $globalAgg['statements']
);

echo "### 🎯 Thresholds\n\n";
echo "| Tier | Required | Actual | |\n";
echo "|------|----------|--------|--|\n";
$t1Total = count(TIER1_FILES);
$t1Ok = $t1Total - count($tier1Failures);
echo sprintf(
    "| Tier 1 (critical, per-file) | %.0f%% | %d / %d files pass | %s |\n",
    $thresholds['tier1'],
    $t1Ok,
    $t1Total,
    $tier1Pass ? '✅' : '❌'
);
echo sprintf(
    "| Tier 2 (domain logic, aggregate) | %.0f%% | %.2f%% | %s |\n",
    $thresholds['tier2'],
    $tier2Agg['pct'],
    $tier2Pass ? '✅' : '❌'
);
echo sprintf(
    "| Global (aggregate) | %.0f%% | %.2f%% | %s |\n",
    $thresholds['global'],
    $globalAgg['pct'],
    $globalPass ? '✅' : '❌'
);

$prPhpFiles = array_values(array_filter($changedFiles, static fn ($f) => str_starts_with($f, 'app/') && str_ends_with($f, '.php')));

echo "\n### 📁 Changed files in this PR";
if (count($prPhpFiles) === 0) {
    echo "\n\n_No PHP files under `app/` changed in this PR._\n";
} else {
    echo sprintf(" (%d)\n\n", count($prPhpFiles));
    echo "| File | Tier | Coverage | Lines |\n";
    echo "|------|------|----------|-------|\n";
    foreach ($prPhpFiles as $path) {
        $tier = coverage_tier_of($path);
        $data = $files[$path] ?? null;
        if ($data === null) {
            echo sprintf("| ❓ `%s` | %s | — | _excluded or unreferenced_ |\n", $path, $tierLabel($tier));

            continue;
        }
        $icon = $fileIcon($data['pct'], $tier);
        echo sprintf(
            "| %s `%s` | %s | %.2f%% | %d / %d |\n",
            $icon,
            $path,
            $tierLabel($tier),
            $data['pct'],
            $data['covered'],
            $data['statements']
        );
    }
    echo "\n_Legend:_ **Tier 1** = critical per-file ≥ ".$thresholds['tier1']."% · **Tier 2** = domain logic aggregate ≥ ".$thresholds['tier2']."% · **—** = counts toward global aggregate only.\n";
}

if (! empty($tier1Failures)) {
    $count = count($tier1Failures);
    echo "\n### 🔴 Tier 1 files below threshold ({$count})\n\n";
    echo "<details><summary>Click to expand</summary>\n\n";
    echo "| File | Required | Current |\n|------|----------|---------|\n";
    foreach ($tier1Failures as $path => $pct) {
        $cur = $pct === null ? '— (not in report)' : number_format($pct, 2).'%';
        echo sprintf("| `%s` | %.0f%% | %s |\n", $path, $thresholds['tier1'], $cur);
    }
    echo "\n</details>\n";
}

echo "\n---\n";
echo "<sub>🔄 Updated on each push · Red inline markers on uncovered lines live in the **Files changed** tab · Full Clover XML in run artifacts.</sub>\n";

$body = ob_get_clean();

if ($outputPath !== null) {
    file_put_contents($outputPath, $body);
    fprintf(STDERR, "Wrote %d bytes to %s\n", strlen($body), $outputPath);
} else {
    echo $body;
}

exit(0);

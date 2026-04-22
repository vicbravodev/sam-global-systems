<?php

declare(strict_types=1);

$args = array_slice($argv, 1);
$cloverPath = null;
$changedFiles = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--changed-files=')) {
        $raw = substr($arg, 16);
        $changedFiles = array_filter(array_map('trim', explode(',', $raw)));
    } elseif (! str_starts_with($arg, '--')) {
        $cloverPath = $arg;
    }
}

if ($cloverPath === null || ! is_readable($cloverPath)) {
    fwrite(STDERR, "Usage: annotate-coverage.php <clover.xml> [--changed-files=p1,p2,...]\n");
    exit(2);
}

$repoRoot = realpath(__DIR__.'/..');

libxml_use_internal_errors(true);
$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse Clover XML: {$cloverPath}\n");
    exit(2);
}

$filter = null;
if ($changedFiles !== null && count($changedFiles) > 0) {
    $filter = array_flip($changedFiles);
}

$total = 0;
$emitted = 0;
$maxAnnotations = 50;

foreach ($xml->xpath('//file') as $fileNode) {
    $abs = (string) $fileNode['name'];
    if (! str_starts_with($abs, $repoRoot.'/')) {
        continue;
    }
    $rel = substr($abs, strlen($repoRoot) + 1);
    if (! str_starts_with($rel, 'app/')) {
        continue;
    }
    if ($filter !== null && ! isset($filter[$rel])) {
        continue;
    }

    foreach ($fileNode->line as $line) {
        $type = (string) $line['type'];
        if ($type !== 'stmt' && $type !== 'cond' && $type !== 'method') {
            continue;
        }
        $count = (int) $line['count'];
        if ($count > 0) {
            continue;
        }
        $num = (int) $line['num'];
        $total++;
        if ($emitted < $maxAnnotations) {
            printf("::warning file=%s,line=%d::Line not covered by tests\n", $rel, $num);
            $emitted++;
        }
    }
}

if ($total > $emitted) {
    $remaining = $total - $emitted;
    printf(
        "::notice title=Coverage annotations truncated::%d more uncovered lines not annotated (cap: %d).\n",
        $remaining,
        $maxAnnotations
    );
}

fprintf(STDERR, "Emitted %d inline coverage annotations (of %d uncovered lines in changed files).\n", $emitted, $total);
exit(0);

<?php

/** Print covered and missed first-party executable lines and branch counts. */
declare(strict_types=1);

$directory = $argv[1] ?? '';
$files = glob($directory . DIRECTORY_SEPARATOR . '*.ser') ?: [];
if ($directory === '' || !is_dir($directory) || $files === []) {
    fwrite(STDERR, "Usage: php tests/coverage-report.php /external/coverage/directory\n");
    exit(1);
}

$sourceRoot = realpath(dirname(__DIR__) . '/src') . DIRECTORY_SEPARATOR;
$pluginFile = realpath(dirname(__DIR__) . '/ai-provider-for-openrouter.php');
$fail = static function (string $reason): void {
    fwrite(STDERR, "Invalid coverage data: {$reason}\n");
    exit(1);
};
$combined = [];
foreach ($files as $file) {
    $contents = file_get_contents($file);
    $coverage = $contents === false ? false : @unserialize($contents, ['allowed_classes' => false]);
    if (!is_array($coverage)) {
        $fail(basename($file) . ' is not a serialized coverage array.');
    }
    foreach ($coverage as $path => $data) {
        if (!is_string($path)) {
            $fail(basename($file) . ' has a non-string source path.');
        }
        if (strpos($path, $sourceRoot) !== 0 && $path !== $pluginFile) {
            continue;
        }
        if (!is_array($data) || !isset($data['lines']) || !is_array($data['lines'])
            || (isset($data['functions']) && !is_array($data['functions']))) {
            $fail(basename($file) . ' has malformed first-party file coverage.');
        }
        $name = $path === $pluginFile ? 'ai-provider-for-openrouter.php' : substr($path, strlen($sourceRoot));
        if (!isset($combined[$name])) {
            $combined[$name] = ['lines' => [], 'functions' => [], 'edges' => []];
        }
        foreach ($data['lines'] as $line => $status) {
            if (!is_int($line) || !is_int($status)) {
                $fail(basename($file) . ' has malformed line coverage.');
            }
            $combined[$name]['lines'][$line] = max($combined[$name]['lines'][$line] ?? -2, $status);
        }
        foreach ($data['functions'] ?? [] as $function => $functionData) {
            if (!is_array($functionData) || !isset($functionData['branches'])
                || !is_array($functionData['branches'])) {
                $fail(basename($file) . ' has malformed function coverage.');
            }
            foreach ($functionData['branches'] ?? [] as $branch => $branchData) {
                if (!is_array($branchData) || !isset($branchData['hit']) || !is_int($branchData['hit'])
                    || (isset($branchData['out_hit']) && !is_array($branchData['out_hit']))) {
                    $fail(basename($file) . ' has malformed branch coverage.');
                }
                $combined[$name]['functions'][$function][$branch] = max(
                    $combined[$name]['functions'][$function][$branch] ?? 0,
                    $branchData['hit']
                );
                foreach ($branchData['out_hit'] ?? [] as $edge => $hit) {
                    if (!is_int($hit)) {
                        $fail(basename($file) . ' has malformed edge coverage.');
                    }
                    $combined[$name]['edges'][$function][$branch][$edge] = max(
                        $combined[$name]['edges'][$function][$branch][$edge] ?? 0,
                        $hit
                    );
                }
            }
        }
    }
}

if ($combined === []) {
    $fail('no first-party source coverage was collected.');
}

ksort($combined);
$totalCoveredLines = 0;
$totalExecutableLines = 0;
$totalCoveredBranches = 0;
$totalBranches = 0;
$totalCoveredEdges = 0;
$totalEdges = 0;
foreach ($combined as $name => $data) {
    $executable = array_filter($data['lines'], static function (int $status): bool { return $status !== -2; });
    $missed = array_keys(array_filter($executable, static function (int $status): bool { return $status !== 1; }));
    $lineCount = count($executable);
    $coveredLines = $lineCount - count($missed);
    $branches = [];
    foreach ($data['functions'] as $functionBranches) {
        foreach ($functionBranches as $hit) { $branches[] = $hit; }
    }
    $branchCount = count($branches);
    $coveredBranches = count(array_filter($branches));
    $edges = [];
    foreach ($data['edges'] as $functionBranches) {
        foreach ($functionBranches as $branchEdges) {
            foreach ($branchEdges as $hit) { $edges[] = $hit; }
        }
    }
    $edgeCount = count($edges);
    $coveredEdges = count(array_filter($edges));
    $totalCoveredLines += $coveredLines;
    $totalExecutableLines += $lineCount;
    $totalCoveredBranches += $coveredBranches;
    $totalBranches += $branchCount;
    $totalCoveredEdges += $coveredEdges;
    $totalEdges += $edgeCount;
    printf("%s: lines %d/%d; Xdebug branch blocks %d/%d; edges %d/%d; missed lines %s\n",
        $name, $coveredLines, $lineCount, $coveredBranches, $branchCount, $coveredEdges, $edgeCount,
        $missed ? implode(',', $missed) : 'none');
}
if ($totalExecutableLines === 0) {
    $fail('no executable first-party lines were collected.');
}
printf("TOTAL: lines %d/%d; Xdebug branch blocks %d/%d; edges %d/%d\n",
    $totalCoveredLines, $totalExecutableLines, $totalCoveredBranches, $totalBranches,
    $totalCoveredEdges, $totalEdges);

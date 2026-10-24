<?php

/**
 * Test ratchet — fail the build on any test failure that is not already on the known list.
 *
 *   php tests/ci/ratchet.php <junit.xml> <known-failures.txt>            check (CI)
 *   php tests/ci/ratchet.php <junit.xml> <known-failures.txt> --write    rewrite the list from this run
 *
 * Why: ~190 legacy tests fail because they post long-superseded payloads (BUGS.md EN-02). A plain
 * "tests must pass" gate would be red from day one, so nobody would trust it. This lets the suite gate
 * deploys today: a NEW failure stops the build, a known one does not, and fixing a known one only ever
 * shrinks the list. The list may shrink but must never grow to hide a real regression — a reviewer sees
 * every added line in the diff.
 *
 * Exit codes: 0 no new failures · 1 new failures, or a run that did not really happen · 2 bad usage.
 */

if (PHP_SAPI !== 'cli') { exit(2); }

[$self, $junit, $knownFile] = array_pad($argv, 3, null);
$write = in_array('--write', $argv, true);
if (!$junit || !$knownFile) {
    fwrite(STDERR, "usage: php ratchet.php <junit.xml> <known-failures.txt> [--write]\n");
    exit(2);
}
if (!is_file($junit)) {
    fwrite(STDERR, "::error::No JUnit report at {$junit} — the test run crashed before it could write one.\n");
    exit(1);
}

$xml = @simplexml_load_file($junit);
if ($xml === false) {
    fwrite(STDERR, "::error::The JUnit report is not valid XML — the test run was cut short.\n");
    exit(1);
}

$total = 0;
$failing = [];
foreach ($xml->xpath('//testcase') as $case) {
    $total++;
    if (isset($case->failure) || isset($case->error)) {
        $failing[(string) $case['class'] . '::' . (string) $case['name']] = true;
    }
}
$failing = array_keys($failing);
sort($failing);

// A run that executed (almost) nothing must not look like a pass.
if ($total < 50) {
    fwrite(STDERR, "::error::Only {$total} tests ran. The suite did not run properly.\n");
    exit(1);
}

if ($write) {
    $header = "# Tests known to fail. The ratchet (tests/ci/ratchet.php) blocks any failure NOT listed here.\n"
        . "# Remove a line when you fix its test. Never add one to get a build through: a new failure is a regression.\n"
        . "# Regenerate only to shrink:  php tests/ci/ratchet.php <junit.xml> tests/ci/known-failures.txt --write\n";
    file_put_contents($knownFile, $header . implode("\n", $failing) . "\n");
    echo 'Wrote ' . count($failing) . " known failures to {$knownFile}\n";
    exit(0);
}

$known = [];
foreach (is_file($knownFile) ? file($knownFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
    $line = trim($line);
    if ($line !== '' && $line[0] !== '#') { $known[$line] = true; }
}

$new   = array_values(array_filter($failing, fn ($t) => !isset($known[$t])));
$fixed = array_values(array_filter(array_keys($known), fn ($t) => !in_array($t, $failing, true)));

$lines = [];
$lines[] = "Tests run: {$total} · failing: " . count($failing) . ' · known failures: ' . count($known);
$lines[] = 'New failures: ' . count($new) . ' · known failures now passing: ' . count($fixed);
echo implode("\n", $lines) . "\n";

if ($fixed) {
    echo "\nThese known failures now PASS — remove them from the list so they stay fixed:\n";
    foreach ($fixed as $t) { echo "  - {$t}\n"; }
}

$summary = getenv('GITHUB_STEP_SUMMARY');
if ($summary) {
    $md = "### Backend tests\n\n" . implode("  \n", $lines) . "\n";
    if ($new)   { $md .= "\n**New failures (these block the build):**\n" . implode("\n", array_map(fn ($t) => "- `{$t}`", $new)) . "\n"; }
    if ($fixed) { $md .= "\n**Known failures that now pass (remove from `tests/ci/known-failures.txt`):**\n" . implode("\n", array_map(fn ($t) => "- `{$t}`", $fixed)) . "\n"; }
    file_put_contents($summary, $md, FILE_APPEND);
}

if ($new) {
    echo "\nNEW FAILURES — this change broke something that used to work:\n";
    foreach ($new as $t) {
        echo "  - {$t}\n";
        echo "::error::New test failure: {$t}\n";
    }
    exit(1);
}

echo "\nNo new failures.\n";
exit(0);

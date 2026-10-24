<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * tests/ci/ratchet.php decides whether a deploy may go ahead, so it gets its own test: it must fail on a
 * new failure, tolerate a known one, and never mistake a crashed or empty run for a pass.
 */
class TestRatchetTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ratchet-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    /** @param array<string,bool> $results "Class::test" => passes? */
    private function junit(array $results, int $padWithPassing = 60): string
    {
        $cases = '';
        foreach ($results as $id => $passes) {
            [$class, $name] = explode('::', $id);
            $cases .= '<testcase name="' . $name . '" class="' . $class . '">' . ($passes ? '' : '<failure type="X">boom</failure>') . '</testcase>';
        }
        for ($i = 0; $i < $padWithPassing; $i++) {
            $cases .= '<testcase name="test_pad_' . $i . '" class="Tests\\Pad"/>';
        }
        $path = $this->dir . '/junit.xml';
        file_put_contents($path, '<?xml version="1.0"?><testsuites><testsuite>' . $cases . '</testsuite></testsuites>');
        return $path;
    }

    private function known(array $ids): string
    {
        $path = $this->dir . '/known.txt';
        file_put_contents($path, "# comment\n\n" . implode("\n", $ids) . "\n");
        return $path;
    }

    /** @return array{0:int,1:string} */
    private function ratchet(string $junit, string $known, string $flag = ''): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/ci/ratchet.php')
            . ' ' . escapeshellarg($junit) . ' ' . escapeshellarg($known) . ' ' . $flag . ' 2>&1';
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function test_a_known_failure_does_not_block_the_build(): void
    {
        [$code, $out] = $this->ratchet($this->junit(['Tests\\A::test_old' => false]), $this->known(['Tests\\A::test_old']));

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('No new failures', $out);
    }

    public function test_a_new_failure_blocks_the_build_and_is_named(): void
    {
        [$code, $out] = $this->ratchet(
            $this->junit(['Tests\\A::test_old' => false, 'Tests\\B::test_new' => false]),
            $this->known(['Tests\\A::test_old']),
        );

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Tests\\B::test_new', $out);
        $this->assertStringNotContainsString('Tests\\A::test_old' . "\n::error", $out);
    }

    public function test_a_fixed_known_failure_is_reported_so_it_can_be_removed(): void
    {
        [$code, $out] = $this->ratchet($this->junit(['Tests\\A::test_old' => true]), $this->known(['Tests\\A::test_old']));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('now PASS', $out);
        $this->assertStringContainsString('Tests\\A::test_old', $out);
    }

    public function test_a_report_that_is_missing_or_cut_short_fails(): void
    {
        [$code] = $this->ratchet($this->dir . '/nope.xml', $this->known([]));
        $this->assertSame(1, $code, 'a crashed run (no report) must not pass');

        file_put_contents($this->dir . '/cut.xml', '<testsuites><testsuite><testcase name="a" class="B">');
        [$code] = $this->ratchet($this->dir . '/cut.xml', $this->known([]));
        $this->assertSame(1, $code, 'a truncated report must not pass');
    }

    public function test_a_run_that_executed_almost_nothing_fails(): void
    {
        [$code, $out] = $this->ratchet($this->junit([], 3), $this->known([]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('did not run properly', $out);
    }

    public function test_write_mode_records_the_current_failures_sorted(): void
    {
        $known = $this->dir . '/new-known.txt';
        [$code] = $this->ratchet($this->junit(['Tests\\Z::test_z' => false, 'Tests\\A::test_a' => false, 'Tests\\M::ok' => true]), $known, '--write');

        $this->assertSame(0, $code);
        $lines = array_values(array_filter(file($known, FILE_IGNORE_NEW_LINES), fn ($l) => $l !== '' && $l[0] !== '#'));
        $this->assertSame(['Tests\\A::test_a', 'Tests\\Z::test_z'], $lines);
        unlink($known);
    }
}

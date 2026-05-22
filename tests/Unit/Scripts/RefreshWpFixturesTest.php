<?php

declare(strict_types=1);

/**
 * Regression tests for scripts/refresh-wp-fixtures.sh.
 *
 * Pest 4 conventions: no namespace declaration, no beforeEach + $this->X
 * shared state. Inline construction in each it() block or top-level
 * helper functions returning a context tuple.
 *
 * Tests:
 *  1. Behavioral — runs a sandboxed copy of the script against a guaranteed-
 *     unresolvable URL prefix with a pre-seeded committed fixture; the
 *     fixture must survive byte-for-byte.
 *  2. Static — script source contains `mktemp` (fix marker).
 *  3. Static — script source no longer contains a bare `rm -f "$out"` line
 *     in the failure branch.
 *  4. Static — script source contains `mv ` (the temp-to-final move).
 *
 * Tests 2–4 run on every machine. Test 1 requires bash + proc_open and is
 * skipped cleanly when unavailable.
 */

/**
 * Build an isolated sandbox copy of scripts/refresh-wp-fixtures.sh with the
 * WP.org SVN host rewritten to an unresolvable hostname, and pre-seed a
 * fixture file with a known marker so the byte-equal preservation check
 * has a load-bearing target.
 *
 * Returns the sandbox root path. The caller runs the copied script via
 * proc_open with cwd = sandbox root.
 */
function sandboxRefreshScript(): string
{
    $base = sys_get_temp_dir() . '/refresh-test-' . bin2hex(random_bytes(6));
    $scriptsDir = $base . '/scripts';
    $fixtureDir = $base . '/tests/Fixtures/wp';
    if (!mkdir($scriptsDir, 0777, true) || !mkdir($fixtureDir, 0777, true)) {
        throw new RuntimeException("Failed to create sandbox tree at {$base}");
    }

    $source = __DIR__ . '/../../../scripts/refresh-wp-fixtures.sh';
    $contents = file_get_contents($source);
    if ($contents === false) {
        throw new RuntimeException("Failed to read source script: {$source}");
    }
    // Rewrite the WP.org SVN host to a guaranteed-unresolvable hostname.
    // .invalid is RFC 6761-reserved; combining with .zzz.example makes
    // accidental DNS hits impossible.
    $rewritten = str_replace(
        'plugins.svn.wordpress.org',
        'localhost.invalid.zzz.example',
        $contents,
    );
    // Shrink curl's retry + timeout budget in the sandbox copy so the
    // behavioral test completes in a few seconds rather than the full
    // ~60s real-world retry window (--retry 3 --retry-delay 2 across 10
    // forced-DNS-failure URLs). The contract under test is committed-
    // fixture preservation, not retry timing — those two concerns can and
    // must be decoupled.
    $rewritten = str_replace(
        'curl -fsSL --retry 3 --retry-delay 2',
        'curl -fsSL --retry 0 --connect-timeout 1 --max-time 2',
        $rewritten,
    );
    $copy = $scriptsDir . '/refresh-wp-fixtures.sh';
    if (file_put_contents($copy, $rewritten) === false) {
        throw new RuntimeException("Failed to write sandbox script: {$copy}");
    }
    chmod($copy, 0755);

    // Pre-seed a committed-fixture file so the preservation check has a
    // load-bearing target. The marker is a UTF-8 ASCII-safe string so any
    // byte-level corruption (partial-write, encoding mangle) is detectable.
    $preSeed = "PRE-EXISTING FIXTURE CONTENT — MUST SURVIVE\n";
    $fixturePath = $fixtureDir . '/woocommerce.readme.txt';
    if (file_put_contents($fixturePath, $preSeed) === false) {
        throw new RuntimeException("Failed to pre-seed fixture: {$fixturePath}");
    }

    // Best-effort cleanup on PHP process exit. Pest does not give us a
    // per-test afterAll hook compatible with inline construction, so a
    // shutdown function is the most reliable cleanup.
    register_shutdown_function(static function () use ($base): void {
        if (!is_dir($base)) {
            return;
        }
        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }
            $path = $fileInfo->getPathname();
            if ($fileInfo->isDir()) {
                @rmdir($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($base);
    });

    return $base;
}

// ---------------------------------------------------------------------------
// Behavioral — forced-404 sandbox run must preserve the committed fixture
// ---------------------------------------------------------------------------

/**
 * Returns true when bash + proc_open are both unavailable — the behavioral
 * test cannot run without them and Pest will skip cleanly.
 */
function refreshTestRequirementsMissing(): bool
{
    if (!function_exists('proc_open')) {
        return true;
    }
    $bashPath = shell_exec('command -v bash 2>/dev/null');

    return $bashPath === null || trim((string) $bashPath) === '';
}

it('preserves committed fixture byte-for-byte when curl 404s on every URL', function (): void {
    $sandbox = sandboxRefreshScript();
    $fixturePath = $sandbox . '/tests/Fixtures/wp/woocommerce.readme.txt';
    $original = "PRE-EXISTING FIXTURE CONTENT — MUST SURVIVE\n";

    // Sanity check: the pre-seed wrote what we expect.
    expect(file_get_contents($fixturePath))->toBe($original);

    $proc = proc_open(
        ['bash', $sandbox . '/scripts/refresh-wp-fixtures.sh'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $sandbox,
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('proc_open failed for sandbox script');
    }
    fclose($pipes[0]);
    // Drain stdout + stderr so the child does not block.
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // Load-bearing assertion: the committed fixture survives the
    // forced-404 run byte-for-byte.
    expect(file_exists($fixturePath))->toBeTrue();
    expect(file_get_contents($fixturePath))->toBe($original);
})->skip(fn (): bool => refreshTestRequirementsMissing(), 'bash/proc_open unavailable in this environment');

// ---------------------------------------------------------------------------
// Static guards — fingerprints of the fix pattern
// ---------------------------------------------------------------------------

it('script source contains mktemp (fix marker)', function (): void {
    $scriptBody = (string) file_get_contents(__DIR__ . '/../../../scripts/refresh-wp-fixtures.sh');
    expect($scriptBody)->toContain('mktemp');
});

it('script source no longer contains an unconditional rm -f $out on its own line', function (): void {
    $scriptBody = (string) file_get_contents(__DIR__ . '/../../../scripts/refresh-wp-fixtures.sh');
    // A bare destructive line; trailing comment allowed but the standalone
    // form is forbidden — that is the destructive pattern.
    expect($scriptBody)->not->toMatch('/^\s*rm -f "\$out"\s*(?:#.*)?$/m');
});

it('script source contains mv (mktemp-mv-on-success pattern)', function (): void {
    $scriptBody = (string) file_get_contents(__DIR__ . '/../../../scripts/refresh-wp-fixtures.sh');
    expect($scriptBody)->toContain('mv ');
});

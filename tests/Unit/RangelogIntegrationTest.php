<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ChangelogException;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Exception\UnsupportedPackageException;
use n5s\Rangelog\Parser\ParserRegistry;
use n5s\Rangelog\Rangelog;
use n5s\Rangelog\Renderer\MarkdownRenderer;
use n5s\Rangelog\SourceProvider\GitHubFileResolver;
use n5s\Rangelog\SourceProvider\GitHubReleasesResolver;
use n5s\Rangelog\SourceProvider\MarkdownUrlResolver;
use n5s\Rangelog\SourceProvider\SourceProviderChain;
use n5s\Rangelog\SourceProvider\SourceProviderInterface;
use n5s\Rangelog\SourceProvider\WordPressOrgResolver;
use n5s\Rangelog\Tests\TestSupport\FixtureFetcher;

/**
 * Exercise the full library end-to-end via FixtureFetcher rather than
 * php-http/mock-client. The 8-test matrix covers 4 happy-paths × source-type
 * plus 4 edge cases.
 *
 * The file lives under tests/Unit/ so phpunit.xml.dist picks it up via the
 * existing Unit testsuite. The "integration" nature is signaled by the file
 * NAME, not by directory.
 *
 * ComposerSupportResolver is no longer in the chain; MarkdownUrlResolver is
 * the terminal fallback. wp.org supports() is URL-shape inspection (no HTTP).
 *
 * @param array<string, string> $urlToFixturePath
 *
 * @return array{0: Rangelog, 1: FixtureFetcher}
 */
function buildIntegrationClient(array $urlToFixturePath): array
{
    $fetcher = new FixtureFetcher($urlToFixturePath);

    $chain = new SourceProviderChain([
        new WordPressOrgResolver($fetcher),
        new GitHubReleasesResolver($fetcher),
        new GitHubFileResolver($fetcher),
        new MarkdownUrlResolver($fetcher),
    ]);

    $parsers = ParserRegistry::defaults();
    $renderer = new MarkdownRenderer();

    return [new Rangelog($chain, $fetcher, $parsers, $renderer), $fetcher];
}

// ---------------------------------------------------------------------------
// Happy-path #1 — GitHub Releases
// ---------------------------------------------------------------------------

it('end-to-end resolves and renders a GitHub Releases source (happy-path #1)', function (): void {
    [$client] = buildIntegrationClient([
        // GitHubReleasesResolver.supports() is URL-host inspection (no HTTP).
        // Pagination — first page returns 3 entries, second page is empty
        // to stop pagination naturally.
        'https://api.github.com/repos/symfony/console/releases?per_page=100&page=1' => 'integration/three-entry-releases.json',
        'https://api.github.com/repos/symfony/console/releases?per_page=100&page=2' => 'integration/empty-releases.json',
    ]);

    $changelog = $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '1.0.0', '99.0.0');

    expect($changelog->entries)->not->toBeEmpty();
    $rendered = $client->render($changelog);
    expect($rendered)->not->toBe('');
    expect($rendered)->toContain('## ');
});

// ---------------------------------------------------------------------------
// Happy-path #2 — GitHub File (fallback when Releases is empty)
// ---------------------------------------------------------------------------

it('end-to-end falls back to GitHub File when Releases is empty (happy-path #2)', function (): void {
    [$client] = buildIntegrationClient([
        // GitHubReleasesResolver.supports() is URL-host inspection (no HTTP).
        // GH Releases resolver resolve() fetches page=1 — empty → returns Source with 0 releases.
        // Chain falls through (iterative path) to GitHubFileResolver.
        'https://api.github.com/repos/symfony/console/releases?per_page=100&page=1' => 'integration/empty-releases.json',
        // GH File resolver tries main/CHANGELOG.md first — provide the
        // markdown fixture there.
        'https://raw.githubusercontent.com/symfony/console/main/CHANGELOG.md' => 'markdown/kac-descending.md',
    ]);

    $changelog = $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '1.0.0', '99.0.0');

    expect($changelog->entries)->not->toBeEmpty();
    $rendered = $client->render($changelog);
    expect($rendered)->toContain('## ');
});

// ---------------------------------------------------------------------------
// Happy-path #3 — WordPress.org (SVN readme.txt)
// ---------------------------------------------------------------------------

it('end-to-end resolves a WordPress plugin via SVN readme.txt (happy-path #3)', function (): void {
    // WordPressOrgResolver walks tags/{ver}/changelog.md → CHANGELOG.md →
    // readme.txt → trunk/changelog.md → CHANGELOG.md → readme.txt.
    // We provide ONLY the readme.txt fixture under tags/{ver}/, so the
    // first two attempts 404 and the third wins.
    // Using the existing woocommerce fixture whose only version is
    // '10.8.0-beta.2' — pin range.to to that version so the SVN tag
    // ltrim('v', $range->to) matches.
    [$client] = buildIntegrationClient([
        'https://plugins.svn.wordpress.org/woocommerce/tags/10.8.0-beta.2/readme.txt' => 'wp/woocommerce.readme.txt',
    ]);

    $changelog = $client->changelog(new Package('wpackagist-plugin/woocommerce', 'https://wordpress.org/plugins/woocommerce/'), '0.0.0', '10.8.0-beta.2');

    expect($changelog->entries)->not->toBeEmpty();
    $rendered = $client->render($changelog);
    expect($rendered)->toContain('## ');
});

// ---------------------------------------------------------------------------
// Happy-path #4 — MarkdownUrlResolver terminal fallback
// ---------------------------------------------------------------------------

it('end-to-end resolves a changelog via MarkdownUrlResolver terminal fallback (happy-path #4)', function (): void {
    // MarkdownUrlResolver is the terminal fallback: supports() always true.
    // Use a URL that GitHub/WordPress resolvers reject so MarkdownUrlResolver fires.
    [$client] = buildIntegrationClient([
        'https://example.com/CHANGELOG.md' => 'markdown/kac-descending.md',
    ]);

    $changelog = $client->changelog(new Package('any/package', 'https://example.com/CHANGELOG.md'), '1.0.0', '99.0.0');

    expect($changelog->entries)->not->toBeEmpty();
    $rendered = $client->render($changelog);
    expect($rendered)->not->toBe('');
});

// ---------------------------------------------------------------------------
// Edge case #5 — Chain exhausted → ChangelogNotFoundException
// ---------------------------------------------------------------------------

it('throws ChangelogNotFoundException when the chain exhausts (edge case #5)', function (): void {
    // Empty URL map: every SVN walk / GH releases / GH file / MarkdownUrl fetch
    // fires FixtureFetcher's 404 path. GitHub + WordPress resolvers reject 'example.com'
    // host (URL-host routing). MarkdownUrlResolver fires as terminal fallback:
    // supports()=true, resolve() fetches → 404 via FixtureFetcher miss → ChangelogNotFoundException.
    [$client] = buildIntegrationClient([]);

    $caught = null;
    try {
        $client->changelog(new Package('nonexistent/pkg', 'https://example.com/nonexistent/pkg'), '1.0.0', '2.0.0');
    } catch (ChangelogException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ChangelogNotFoundException::class);
    /** @var ChangelogNotFoundException $caught */
    expect($caught->getMessage())->toContain('nonexistent/pkg');
});

// ---------------------------------------------------------------------------
// Edge case #6 — wp.org SVN 404 → UnsupportedPackageException
// ---------------------------------------------------------------------------

it('throws UnsupportedPackageException when all wp.org SVN attempts 404 (edge case #6)', function (): void {
    // Empty URL map. WordPressOrgResolver.supports() uses isWordPress()
    // (no HTTP) and returns true for the wpackagist-plugin/ prefix; resolve()
    // then walks 6 SVN URLs (3 tags × 3 trunk); all 404 via FixtureFetcher's
    // miss path → UnsupportedPackageException (NOT a chain fall-through).
    [$client] = buildIntegrationClient([]);

    $caught = null;
    try {
        $client->changelog(new Package('wpackagist-plugin/premium-plugin-not-on-wp-org', 'https://wordpress.org/plugins/premium-plugin-not-on-wp-org/'), '1.0.0', '2.0.0');
    } catch (ChangelogException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(UnsupportedPackageException::class);
});

// ---------------------------------------------------------------------------
// Edge case #7 — Partial result (from version absent) → > [!WARNING]
// ---------------------------------------------------------------------------

it('marks the changelog partial and renders the > [!WARNING] admonition when from is absent (edge case #7)', function (): void {
    // GH Releases fixture contains v2.0.0, v2.5.0, v3.0.0 — all ABOVE the
    // 'from' version 1.0.0. PartialResultDetector flips isPartial=true.
    // GitHubReleasesResolver.supports() is URL-host inspection (no HTTP).
    [$client] = buildIntegrationClient([
        'https://api.github.com/repos/symfony/console/releases?per_page=100&page=1' => 'integration/three-entry-releases.json',
        'https://api.github.com/repos/symfony/console/releases?per_page=100&page=2' => 'integration/empty-releases.json',
    ]);

    $changelog = $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '1.0.0', '3.0.0');

    expect($changelog->isPartial())->toBeTrue();
    expect($changelog->getPartialReason())->not->toBeNull();

    $rendered = $client->render($changelog);
    expect($rendered)->toContain('> [!WARNING]');
});

// ---------------------------------------------------------------------------
// Edge case #8 — Empty filtered range → "_No changelog entries found._"
// ---------------------------------------------------------------------------

it('renders the empty fallback when filter yields zero entries (edge case #8)', function (): void {
    // GH Releases fixture entries: v1.0.0, v2.0.0. Requested range: 3.0.0
    // exclusive to 4.0.0 inclusive — neither version is inside. Changelog::filter
    // returns empty entries from GitHubReleases; iterative path falls through to
    // GitHubFileResolver, which also has entries (1.2.3, 1.2.0) outside the 3.0-4.0
    // range. All resolvers yield empty → MarkdownRenderer surfaces empty fallback.
    // GitHubReleasesResolver.supports() is URL-host inspection (no HTTP).
    // MarkdownUrlResolver is the terminal fallback: fetches $package->sourceUrl directly.
    // Provide below-range content for that URL too so all resolvers yield empty.
    [$client] = buildIntegrationClient([
        'https://api.github.com/repos/symfony/console/releases?per_page=100&page=1' => 'integration/two-entry-releases.json',
        'https://api.github.com/repos/symfony/console/releases?per_page=100&page=2' => 'integration/empty-releases.json',
        // GitHubFileResolver fallback: CHANGELOG.md has entries 1.2.3 + 1.2.0 — also outside
        // the 3.0-4.0 range, so filter still yields empty entries.
        'https://raw.githubusercontent.com/symfony/console/main/CHANGELOG.md' => 'markdown/kac-descending.md',
        // MarkdownUrlResolver fetches $package->sourceUrl (https://github.com/symfony/console)
        // literally; provide below-range content so filter still yields empty.
        'https://github.com/symfony/console' => 'markdown/kac-descending.md',
    ]);

    $changelog = $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '3.0.0', '4.0.0');

    expect($changelog->entries)->toBe([]);

    $rendered = $client->render($changelog);
    expect($rendered)->toContain('_No changelog entries found._');
});

// ---------------------------------------------------------------------------
// Chain fall-through tests
// ---------------------------------------------------------------------------

it('falls through from GitHubReleasesResolver to GitHubFileResolver when Releases entries are out-of-range', function (): void {
    // league/flysystem: GitHub Releases returns entries BELOW 3.32.0 (below-range),
    // so after parse + filter the Changelog is empty. The orchestrator should fall
    // through to GitHubFileResolver which returns in-range-changelog.md.
    // GitHubReleasesResolver.supports() is URL-host inspection (no HTTP).
    [$client] = buildIntegrationClient([
        // GitHubReleasesResolver::resolve() full pagination.
        'https://api.github.com/repos/thephpleague/flysystem/releases?per_page=100&page=1' => 'integration/below-range-releases.json',
        'https://api.github.com/repos/thephpleague/flysystem/releases?per_page=100&page=2' => 'integration/empty-releases.json',
        // GitHubFileResolver::resolve() — tries CHANGELOG.md on main branch first.
        'https://raw.githubusercontent.com/thephpleague/flysystem/main/CHANGELOG.md' => 'integration/in-range-changelog.md',
    ]);

    $changelog = $client->changelog(new Package('league/flysystem', 'https://github.com/thephpleague/flysystem'), '3.32.0', '3.33.0');

    // Entries must come from the markdown fixture (in-range), NOT the empty GH Releases.
    expect($changelog->entries)->not->toBeEmpty();
    expect($changelog->entries[0]->version)->toBe('3.33.0');
});

it('returns the LAST (partial) Changelog when ALL resolvers yield empty entries', function (): void {
    // Both GitHubReleasesResolver and GitHubFileResolver return out-of-range content.
    // MarkdownUrlResolver (terminal fallback) also returns out-of-range content.
    // The orchestrator must NOT throw ChangelogNotFoundException — it should return the
    // last processed Changelog (which may be isPartial=true) so the caller can
    // introspect why entries are absent.
    // GitHubReleasesResolver.supports() is URL-host inspection (no HTTP).
    // Note: MarkdownUrlResolver.supports()=true and fetches $package->sourceUrl
    // directly. We must provide a fixture for that URL with out-of-range content.
    [$client] = buildIntegrationClient([
        // GitHubReleasesResolver returns below-range entries.
        'https://api.github.com/repos/thephpleague/flysystem/releases?per_page=100&page=1' => 'integration/below-range-releases.json',
        'https://api.github.com/repos/thephpleague/flysystem/releases?per_page=100&page=2' => 'integration/empty-releases.json',
        // GitHubFileResolver also returns below-range content (KAC markdown with 0.x versions).
        'https://raw.githubusercontent.com/thephpleague/flysystem/main/CHANGELOG.md' => 'integration/below-range-changelog.md',
        // MarkdownUrlResolver fetches $package->sourceUrl literally; provide below-range content.
        'https://github.com/thephpleague/flysystem' => 'integration/below-range-changelog.md',
    ]);

    $caught = null;
    $result = null;
    try {
        $result = $client->changelog(new Package('league/flysystem', 'https://github.com/thephpleague/flysystem'), '3.32.0', '3.33.0');
    } catch (ChangelogNotFoundException $e) {
        $caught = $e;
    }

    // Must NOT throw ChangelogNotFoundException — the chain DID find sources.
    expect($caught)->toBeNull();
    // The returned Changelog has no entries (all below range).
    expect($result)->not->toBeNull();
    /** @var Changelog $result */
    expect($result->entries)->toBe([]);
});

it('propagates RateLimitedException from the first resolver without falling through', function (): void {
    // A provider chain where the first supporting provider's resolve() throws
    // RateLimitedException. The orchestrator must NOT swallow the exception and
    // fall through — it must propagate verbatim.
    $rateLimitEx = new RateLimitedException(message: 'GitHub API rate limit exceeded');

    $throwingProvider = new readonly class ($rateLimitEx) implements SourceProviderInterface {
        public function __construct(private RateLimitedException $ex)
        {
        }

        public function supports(Package $package): bool
        {
            return true;
        }

        public function resolve(Package $package, VersionRange $range): Source
        {
            throw $this->ex;
        }
    };

    $fetcher = new FixtureFetcher([]);
    $chain = new SourceProviderChain([$throwingProvider]);
    $parsers = ParserRegistry::defaults();
    $renderer = new MarkdownRenderer();
    $client = new Rangelog($chain, $fetcher, $parsers, $renderer);

    $caught = null;
    try {
        $client->changelog(new Package('league/flysystem', 'https://github.com/thephpleague/flysystem'), '3.32.0', '3.33.0');
    } catch (RateLimitedException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitedException::class);
    expect($caught)->toBe($rateLimitEx);
});

// ---------------------------------------------------------------------------
// Early-exit + chain fall-through integration
// ---------------------------------------------------------------------------

it('early-exit on out-of-range Releases page triggers chain fall-through to GitHubFileResolver', function (): void {
    // This test requires both:
    //   - IterativeSourceProviderInterface fall-through
    //   - GitHubReleasesResolver early-exit on from-boundary
    //
    // Flow:
    // (a) Packagist → thephpleague/flysystem (sourceUrl = github.com/thephpleague/flysystem.git)
    // (b) GitHubReleasesResolver::supports() — sparse-page-1 is non-empty → true
    // (c) GitHubReleasesResolver::resolve() — page 1 (sparse-page-1: 4.9.0→3.10.0).
    //     With early-exit: first entry 4.9.0 <= 5.0.0 (range->from) is FALSE,
    //     but the page DOES contain 3.10.0 which satisfies <= 5.0.0. So early-exit fires
    //     after page 1 is fully appended to $all.
    //     Page 2 URL is NOT in the fixture map (never fetched).
    // (d) Changelog::filter(5.0.0, 5.1.0) — ALL entries on sparse-page-1 are BELOW 5.0.0
    //     (max entry = 4.9.0 < 5.0.0). Filtered Changelog is empty.
    // (e) Iterative path: Changelog empty → fall through to GitHubFileResolver.
    // (f) GitHubFileResolver fetches main/CHANGELOG.md → in-range-changelog-5x.md →
    //     returns entry 5.1.0.
    //
    // Failure modes without either piece:
    //   - Without early-exit: resolve() paginates page 2 (URL absent → FetchException)
    //   - Without fall-through: chain does not fall through; returns empty Changelog
    // GitHubReleasesResolver.supports() is URL-host inspection (no HTTP).
    [$client] = buildIntegrationClient([
        'https://api.github.com/repos/thephpleague/flysystem/releases?per_page=100&page=1' => 'github/releases/sparse-page-1.json',
        // NOTE: page=2 URL intentionally absent — early-exit means it is NEVER fetched.
        'https://raw.githubusercontent.com/thephpleague/flysystem/main/CHANGELOG.md'       => 'integration/in-range-changelog-5x.md',
    ]);

    $changelog = $client->changelog(new Package('league/flysystem', 'https://github.com/thephpleague/flysystem'), '5.0.0', '5.1.0');

    expect($changelog->entries)->not->toBeEmpty();
    expect(count($changelog->entries))->toBe(1);
    expect($changelog->entries[0]->version)->toBe('5.1.0');
});

// ---------------------------------------------------------------------------
// Version-branch walking + chain fall-through integration
// ---------------------------------------------------------------------------

it('GitHubFileResolver reached via chain fall-through successfully reads CHANGELOG.md from a non-main version branch', function (): void {
    // This test requires both:
    //   - IterativeSourceProviderInterface fall-through
    //   - GitHubFileResolver version-branch walking
    //
    // Flow:
    // (a) Packagist → thephpleague/flysystem (sourceUrl = github.com/thephpleague/flysystem.git)
    // (b) GitHubReleasesResolver::supports() — below-range-releases is non-empty → true
    // (c) GitHubReleasesResolver::resolve() — pages return entries 0.1.0/0.2.0 (below 3.32.0).
    //     After parse+filter(3.32.0, 3.33.0), Changelog has 0 entries.
    // (d) Iterative path: Changelog empty → fall through to GitHubFileResolver.
    // (e) Without version-branch walking: GitHubFileResolver only probes main + master (both
    //     absent from URL map → 404), then throws ChangelogNotFoundException → test FAILS.
    // (f) With version-branch walking: derives ['3.33', '3.x', '3.33.x'] from to='3.33.0'.
    //     Walks main (404) → master (404) → 3.33 (404) → 3.x (200) → returns Source.
    //     Parser reads in-range-changelog.md: entries 3.33.0 + 3.32.0.
    //     filter(3.32.0, 3.33.0) keeps 3.33.0 only (exclusive-from drops 3.32.0).
    //
    // Failure modes without either piece:
    //   (1) Without fall-through: chain returns empty Changelog from GitHubReleasesResolver,
    //       no fall-through → test fails on not->toBeEmpty().
    //   (2) Without version-branch walking: GitHubFileResolver probes only main+master,
    //       both 404 (absent from URL map) → throws ChangelogNotFoundException.
    // GitHubReleasesResolver.supports() is URL-host inspection (no HTTP).
    [$client] = buildIntegrationClient([
        // GitHubReleasesResolver::resolve() — all entries (0.1.0, 0.2.0) below 3.32.0.
        'https://api.github.com/repos/thephpleague/flysystem/releases?per_page=100&page=1'  => 'integration/below-range-releases.json',
        'https://api.github.com/repos/thephpleague/flysystem/releases?per_page=100&page=2'  => 'integration/below-range-releases.json',
        // GitHubFileResolver: main and master absent → 404 via FixtureFetcher miss.
        // Derived branch 3.33 absent → 404 via FixtureFetcher miss.
        // Derived branch 3.x → 200 with in-range-changelog.md (entries 3.33.0 + 3.32.0).
        'https://raw.githubusercontent.com/thephpleague/flysystem/3.x/CHANGELOG.md'         => 'integration/in-range-changelog.md',
    ]);

    $changelog = $client->changelog(new Package('league/flysystem', 'https://github.com/thephpleague/flysystem'), '3.32.0', '3.33.0');

    expect($changelog->entries)->not->toBeEmpty();
    expect(count($changelog->entries))->toBe(1);
    expect($changelog->entries[0]->version)->toBe('3.33.0');
});

// ---------------------------------------------------------------------------
// v-prefixed release + unprefixed range bound integration
// ---------------------------------------------------------------------------

it('parses v-prefixed releases and filters them with unprefixed range bounds end-to-end', function (): void {
    // This test exercises the full parser+filter+resolver+chain pipeline against v-prefixed
    // GitHub Releases entries (the dominant real-world shape for symfony/*, twig/*, many
    // league/* packages).
    //
    // Depends on:
    //   - GitHubReleasesParser uses tag_name first — in this fixture tag_name === name so
    //     parser precedence does not differentiate, but the tag_name-first code path IS
    //     exercised.
    //   - Changelog::filter() normalizes both sides via VersionParser::normalize() before
    //     Comparator calls — without this, every v-prefixed entry fails the
    //     greaterThan('v7.4.8', '7.4.7') check and the Changelog is empty.
    //
    // Fixture: 3 v-prefixed entries (v7.4.9, v7.4.8, v7.4.7). Range: VersionRange::changes('7.4.7', '7.4.9')
    // Expected: 2 entries kept (v7.4.8, v7.4.9); v7.4.7 dropped by exclusive-from.
    // GitHubReleasesResolver.supports() is URL-host inspection (no HTTP).
    [$client] = buildIntegrationClient([
        // GH Releases full pagination — page 1 has all 3 v-prefixed entries.
        'https://api.github.com/repos/symfony/console/releases?per_page=100&page=1'      => 'github/v-prefixed-releases.json',
        // Page 2 is empty — stops pagination naturally.
        'https://api.github.com/repos/symfony/console/releases?per_page=100&page=2'      => 'integration/empty-releases.json',
    ]);

    $changelog = $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '7.4.7', '7.4.9');

    expect($changelog->entries)->toHaveCount(2);
    $versions = array_map(fn (ChangelogEntry $e): string => $e->version, $changelog->entries);
    // Fixture is descending (v7.4.9, v7.4.8, v7.4.7) — GitHub API convention.
    // filter() preserves insertion order; v7.4.7 is dropped by exclusive-from.
    expect($versions)->toBe(['v7.4.9', 'v7.4.8']);
});

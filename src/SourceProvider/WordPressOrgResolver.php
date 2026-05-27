<?php

declare(strict_types=1);

namespace n5s\Rangelog\SourceProvider;

use Composer\Semver\VersionParser;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\UnsupportedPackageException;
use n5s\Rangelog\Fetcher\FetcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use UnexpectedValueException;

/**
 * Resolver for WordPress.org-hosted plugins and themes.
 *
 * `supports()` inspects the URL shape via `extractKindAndSlug()` — no HTTP
 * call. WordPress packages are not on Packagist p2. Two host-insensitive URL
 * forms are accepted:
 *  - Human-facing page: host `wordpress.org`, path `/plugins/<slug>` or
 *    `/themes/<slug>` (kind from the path segment).
 *  - Canonical SVN source — the composer `source.url` for WordPress.org
 *    packages: host `plugins.svn.wordpress.org` or `themes.svn.wordpress.org`,
 *    slug = first path segment (kind from the subdomain). This is the same
 *    SVN base the resolver builds in `buildAttempts()`, so a consumer can pass
 *    the composer source URL directly.
 *
 * Path prefix `/plugins/` routes to `plugins.svn.wordpress.org`; path prefix
 * `/themes/` routes to `themes.svn.wordpress.org`. The two SVN layouts are
 * NOT symmetrical and the attempt sets differ:
 *
 * Plugins — structured layout, six-attempt walk:
 *  1. tags/{ver}/changelog.md   → GITHUB_FILE
 *  2. tags/{ver}/CHANGELOG.md   → GITHUB_FILE
 *  3. tags/{ver}/readme.txt     → WORDPRESS_ORG
 *  4. trunk/changelog.md        → GITHUB_FILE
 *  5. trunk/CHANGELOG.md        → GITHUB_FILE
 *  6. trunk/readme.txt          → WORDPRESS_ORG
 *
 * Themes — flat layout, three-attempt walk (no `tags/` namespace, no `trunk/`
 * fallback; themes are published directly under their version directory):
 *  1. {ver}/changelog.md        → GITHUB_FILE
 *  2. {ver}/CHANGELOG.md        → GITHUB_FILE
 *  3. {ver}/readme.txt          → WORDPRESS_ORG
 *
 * First HTTP 200 wins. SVN is case-sensitive, so both `changelog.md` and
 * `CHANGELOG.md` are attempted.
 *
 * `composer/semver`'s `VersionParser::normalize()` is used strictly as a
 * validity probe. On success, the SVN tag string is `ltrim($range->to, 'v')` —
 * not the normalize() output (which would have a trailing `.0` artifact,
 * e.g. `27.5` → `27.5.0.0`). On `UnexpectedValueException`:
 *  - For plugins: attempts 1-3 are skipped and the walk starts at attempt 4
 *    (the trunk fallback set).
 *  - For themes: no attempts are made and `UnsupportedPackageException` is
 *    thrown after the empty walk, since themes have no trunk fallback.
 *
 * Markdown files (changelog.md / CHANGELOG.md) carry `SourceTypes::GITHUB_FILE`;
 * readme.txt carries `SourceTypes::WORDPRESS_ORG`.
 *
 * 5xx / 429 / network failures propagate verbatim (FetchException with
 * `statusCode !== 404` is rethrown).
 *
 * PSR-3 events use the message templates `'Trying {url}'` (debug, per attempt;
 * the `total` context key carries the per-invocation attempt count — 6, 3,
 * or 0 — not a static constant), `'Resolved WP package {slug} from {url}'`
 * (info — covers both plugins and themes; the `url` context key disambiguates
 * via `plugins.svn.*` vs `themes.svn.*`), and `'All {count} SVN attempts
 * returned 404 for {slug}'` (warning).
 *
 * All-404 (or empty attempt set) throws `UnsupportedPackageException`.
 * Premium plugins and unlisted themes have no safe fall-through alternative;
 * only public WP plugins and themes are in scope.
 */
final readonly class WordPressOrgResolver implements SourceProviderInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private FetcherInterface $fetcher,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(Package $package): bool
    {
        return $this->extractKindAndSlug($package->sourceUrl) !== null;
    }

    public function resolve(Package $package, VersionRange $range): Source
    {
        $extracted = $this->extractKindAndSlug($package->sourceUrl)
            ?? throw new UnsupportedPackageException("Cannot extract WP plugin/theme slug from URL: {$package->sourceUrl}");

        $kind = $extracted['kind'];
        $slug = $extracted['slug'];

        $tagVersion = null;
        try {
            (new VersionParser())->normalize($range->to);
            $tagVersion = ltrim($range->to, 'v');
        } catch (UnexpectedValueException) {
            $tagVersion = null;
        }

        $attempts = $this->buildAttempts($kind, $slug, $tagVersion);
        $total = \count($attempts);

        foreach ($attempts as $index => $attempt) {
            $this->logger->debug('Trying {url}', [
                'url' => $attempt['url'],
                'attempt' => $index + 1,
                'total' => $total,
            ]);

            try {
                $this->fetcher->fetch(new Source(type: $attempt['type'], url: $attempt['url']));
            } catch (FetchException $e) {
                if ($e->statusCode === 404) {
                    continue;
                }
                throw $e;
            }

            $this->logger->info('Resolved WP package {slug} from {url}', [
                'slug' => $slug,
                'url' => $attempt['url'],
                'source_type' => $attempt['type'],
            ]);

            return new Source(type: $attempt['type'], url: $attempt['url']);
        }

        $this->logger->warning('All {count} SVN attempts returned 404 for {slug}', [
            'count' => $total,
            'slug' => $slug,
        ]);

        throw new UnsupportedPackageException("No SVN source found for WordPress {$kind}: {$slug}");
    }

    /**
     * @return list<array{url: string, type: string}>
     */
    private function buildAttempts(string $kind, string $slug, ?string $tagVersion): array
    {
        // $kind is guaranteed to be 'plugins' or 'themes' by extractKindAndSlug()'s regex.
        $base = "https://{$kind}.svn.wordpress.org/{$slug}";

        if ($kind === 'themes') {
            // Theme SVN layout is flat: <slug>/<version>/<file>. There is no
            // tags/ namespace and no trunk/ fallback — themes are published
            // directly under their version directory. When the version is not
            // normalizable there is nowhere to fetch from.
            if ($tagVersion === null) {
                return [];
            }

            return [
                ['url' => "{$base}/{$tagVersion}/changelog.md", 'type' => SourceTypes::GITHUB_FILE],
                ['url' => "{$base}/{$tagVersion}/CHANGELOG.md", 'type' => SourceTypes::GITHUB_FILE],
                ['url' => "{$base}/{$tagVersion}/readme.txt", 'type' => SourceTypes::WORDPRESS_ORG],
            ];
        }

        $trunk = [
            ['url' => "{$base}/trunk/changelog.md", 'type' => SourceTypes::GITHUB_FILE],
            ['url' => "{$base}/trunk/CHANGELOG.md", 'type' => SourceTypes::GITHUB_FILE],
            ['url' => "{$base}/trunk/readme.txt", 'type' => SourceTypes::WORDPRESS_ORG],
        ];

        if ($tagVersion === null) {
            return $trunk;
        }

        return [
            ['url' => "{$base}/tags/{$tagVersion}/changelog.md", 'type' => SourceTypes::GITHUB_FILE],
            ['url' => "{$base}/tags/{$tagVersion}/CHANGELOG.md", 'type' => SourceTypes::GITHUB_FILE],
            ['url' => "{$base}/tags/{$tagVersion}/readme.txt", 'type' => SourceTypes::WORDPRESS_ORG],
            ...$trunk,
        ];
    }

    /**
     * @return array{kind: string, slug: string}|null
     */
    private function extractKindAndSlug(string $url): ?array
    {
        $host = parse_url($url, \PHP_URL_HOST);
        $path = parse_url($url, \PHP_URL_PATH);
        if (! \is_string($host) || ! \is_string($path)) {
            return null;
        }

        $host = strtolower($host);

        // Human-facing page: wordpress.org/(plugins|themes)/<slug>.
        if ($host === 'wordpress.org') {
            if (preg_match('#^/(plugins|themes)/([^/]+)#', $path, $m) !== 1) {
                return null;
            }

            return ['kind' => $m[1], 'slug' => $m[2]];
        }

        // Canonical SVN source — the composer `source.url`:
        // (plugins|themes).svn.wordpress.org/<slug>. Kind comes from the
        // subdomain; slug is the first path segment.
        if ($host === 'plugins.svn.wordpress.org' || $host === 'themes.svn.wordpress.org') {
            if (preg_match('#^/([^/]+)#', $path, $m) !== 1) {
                return null;
            }

            return [
                'kind' => $host === 'plugins.svn.wordpress.org' ? 'plugins' : 'themes',
                'slug' => $m[1],
            ];
        }

        return null;
    }
}

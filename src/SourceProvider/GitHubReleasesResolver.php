<?php

declare(strict_types=1);

namespace n5s\Rangelog\SourceProvider;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Util\GitHubRepoUrl;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use UnexpectedValueException;

/**
 * Resolver for GitHub-hosted packages whose releases are managed
 * via the GitHub Releases API.
 *
 * `supports()` is a synchronous URL-host check — no HTTP probe. Returns
 * true iff `parse_url($pkg->sourceUrl)['host']` case-insensitively equals
 * `$repoHost` AND `GitHubRepoUrl::fromUrl($pkg->sourceUrl)` is non-null
 * (anchored-regex defense-in-depth). The constructor accepts optional
 * `$apiHost`/`$repoHost` so callers can target GHE instances.
 *
 * `resolve()` paginates `?per_page=100&page=1..10`. Hard cap at 10 pages
 * × 100 entries = 1000. Stops at the FIRST empty array (not only when
 * reaching the cap). When the cap is hit,
 * `Source.metadata['truncation_signal']` is set to
 * `'github_releases_capped'` (informational; `PartialResultDetector` does
 * not consume it). Early-exit (see below) that fires before the cap does
 * NOT set `truncation_signal` — that signal means "API cap hit", not "we
 * stopped early by design".
 *
 * The concatenated JSON array is stored in `Source.prefetchedBody` so
 * `HttpFetcher` short-circuits when fetching the returned Source (no
 * re-fetch of the page-1 URL).
 *
 * 5xx / 429 / network → propagate verbatim (`FetchException` /
 * `RateLimitedException`).
 *
 * Early-exit: GitHub returns releases in descending version order. Once
 * any entry on the current page satisfies `tag_name <= range->from`, all
 * subsequent pages contain only entries BELOW the requested range and
 * would be wasted HTTP calls. Pagination stops after the boundary page;
 * that page IS preserved in `$all` (check runs after `array_merge`) so no
 * in-range entries are dropped. Non-semver tag_names (date-versioned,
 * malformed) are silently skipped during the boundary scan via
 * `UnexpectedValueException` — consistent with `PartialResultDetector`
 * and `Changelog::filter`.
 */
final readonly class GitHubReleasesResolver implements SourceProviderInterface
{
    private const int PAGINATION_CAP = 10;
    private const int PER_PAGE = 100;
    private const int JSON_DECODE_DEPTH = 8;

    private LoggerInterface $logger;

    public function __construct(
        private FetcherInterface $fetcher,
        private string $apiHost = 'api.github.com',
        private string $repoHost = 'github.com',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(Package $package): bool
    {
        $host = parse_url($package->sourceUrl, \PHP_URL_HOST);
        if (! \is_string($host) || strtolower($host) !== strtolower($this->repoHost)) {
            return false;
        }

        return GitHubRepoUrl::fromUrl($package->sourceUrl) instanceof GitHubRepoUrl;
    }

    public function resolve(Package $package, VersionRange $range): Source
    {
        $repo = GitHubRepoUrl::fromUrl($package->sourceUrl)
            ?? throw new FetchException(
                message: "Source URL is not a GitHub repository: {$package->sourceUrl}",
                statusCode: 0,
            );

        $repoLabel = "{$repo->owner}/{$repo->repo}";
        $all = [];
        $pageActuallyFetched = 0;

        for ($page = 1; $page <= self::PAGINATION_CAP; ++$page) {
            $pageUrl = "https://{$this->apiHost}/repos/{$repo->owner}/{$repo->repo}/releases?per_page=" . self::PER_PAGE . "&page={$page}";
            $this->logger->debug('Fetching releases page {page} of {repo}', [
                'page' => $page,
                'repo' => $repoLabel,
            ]);

            $response = $this->fetcher->fetch(new Source(
                type: SourceTypes::GITHUB_RELEASES,
                url: $pageUrl,
            ));

            /** @var mixed $decoded */
            $decoded = json_decode($response->body, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
            if (! \is_array($decoded) || \count($decoded) === 0) {
                break;
            }

            $pageActuallyFetched = $page;
            /** @var array<int, mixed> $decoded */
            $all = array_merge($all, $decoded);

            // Stop paginating once we have walked past range->from.
            // GitHub returns releases in descending version order; once any entry
            // on the current page satisfies tag_name <= range->from, all subsequent
            // pages contain only entries BELOW the requested range and are wasted
            // HTTP calls. The CURRENT page is preserved in $all (boundary check
            // happens AFTER array_merge), so all in-range entries through the
            // from-boundary land in prefetchedBody.
            //
            // Normalization: tag_names from GitHub may carry a `v` prefix (e.g.
            // `v8.1.0`) while range->from is typically unprefixed (`1.0.0`).
            // Direct Comparator::lessThanOrEqualTo on mismatched prefixes gives
            // wrong results (v-strings sort as pre-release), so both sides are
            // normalized via VersionParser first — consistent with the approach
            // in PartialResultDetector.
            $reachedBoundary = false;
            $parser = new VersionParser();
            try {
                $normalizedFrom = $parser->normalize($range->from);
            } catch (UnexpectedValueException) {
                // Non-semver from — boundary check cannot be performed; skip early-exit.
                $normalizedFrom = null;
            }
            if ($normalizedFrom !== null) {
                foreach ($decoded as $entry) {
                    if (! \is_array($entry)) {
                        continue; // defensive against schema drift
                    }
                    $tagName = $entry['tag_name'] ?? $entry['name'] ?? null;
                    if (! \is_string($tagName)) {
                        continue;
                    }
                    if ($tagName === '') {
                        continue;
                    }
                    try {
                        $normalizedTag = $parser->normalize($tagName);
                        if (Comparator::lessThanOrEqualTo($normalizedTag, $normalizedFrom)) {
                            $reachedBoundary = true;
                            break;
                        }
                    } catch (UnexpectedValueException) {
                        // Non-semver tag: skip and keep scanning. A non-semver
                        // entry cannot satisfy the boundary check by definition.
                        continue;
                    }
                }
            }
            if ($reachedBoundary) {
                $this->logger->debug('Releases pagination boundary reached at {repo}: from={from}, stopping after page {page}', [
                    'repo' => $repoLabel,
                    'from' => $range->from,
                    'page' => $page,
                ]);
                break;
            }
        }

        $truncationSignal = ($pageActuallyFetched === self::PAGINATION_CAP
            && \count($all) === self::PAGINATION_CAP * self::PER_PAGE)
            ? 'github_releases_capped'
            : null;

        $this->logger->info('Fetched {count} releases from {repo} across {pages} pages', [
            'count' => \count($all),
            'repo' => $repoLabel,
            'pages' => $pageActuallyFetched,
        ]);

        if ($truncationSignal !== null) {
            $this->logger->notice('GitHub Releases pagination cap (1000) hit for {repo}', [
                'repo' => $repoLabel,
            ]);
        }

        $concatenated = json_encode($all, JSON_THROW_ON_ERROR);

        return new Source(
            type: SourceTypes::GITHUB_RELEASES,
            url: "https://{$this->apiHost}/repos/{$repo->owner}/{$repo->repo}/releases?per_page=" . self::PER_PAGE . '&page=1',
            metadata: [
                'pages_fetched' => $pageActuallyFetched,
                'releases_count' => \count($all),
                'truncation_signal' => $truncationSignal,
            ],
            prefetchedBody: $concatenated,
        );
    }
}

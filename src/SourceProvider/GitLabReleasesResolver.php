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
use n5s\Rangelog\Util\GitLabProjectPath;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use UnexpectedValueException;

/**
 * Resolver for GitLab-hosted packages whose releases are managed
 * via the GitLab Releases API v4. Sibling to GitHubReleasesResolver.
 *
 * `supports()` does a host check (parse_url + anchored regex via
 * GitLabProjectPath::fromUrl); zero HTTP. Returns true iff
 * `parse_url($pkg->sourceUrl)['host']` case-insensitively equals `$host`
 * AND `GitLabProjectPath::fromUrl($pkg->sourceUrl, $host)` is non-null
 * (defense-in-depth).
 *
 * `resolve()` paginates `?per_page=100&page=1..10&order_by=released_at&sort=desc`.
 * Hard cap at 10 pages × 100 entries = 1000. Stops at the FIRST empty or
 * short array.
 *
 * truncation_signal = 'gitlab_releases_capped' on cap-hit; null otherwise
 * (including early-exit).
 *
 * Resolver-side strict-tag policy: the early-exit scan reads
 * $entry['tag_name'] STRICTLY (NOT tag_name ?? name) — the parser-side
 * lenient precedence does NOT apply at this layer. Two-tier policy:
 * resolver=strict (operate on authoritative git ref), parser=lenient
 * (degrade gracefully for marketing-titled releases like 'Public release 3.6.1').
 *
 * Source.prefetchedBody = json_encode(concatenated array); HttpFetcher
 * short-circuits. The resolver MAY add 2 debug-attribution metadata keys:
 * 'host' (configured host) and 'project_path' (namespace/project). Required
 * metadata keys: 'pages_fetched', 'releases_count', 'truncation_signal'.
 *
 * 5xx/429/network propagate verbatim (FetchException/RateLimitedException).
 *
 * PSR-3 logging mirrors GitHubReleasesResolver wording with 'GitLab' /
 * '{project}' substitutions.
 *
 * Zero auth code — AuthorizingFetcher decorator in the FetcherStack handles
 * PRIVATE-TOKEN injection via metadata['_auth_headers'].
 */
final readonly class GitLabReleasesResolver implements SourceProviderInterface
{
    private const int PAGINATION_CAP = 10;
    private const int PER_PAGE = 100;
    private const int JSON_DECODE_DEPTH = 8;

    private LoggerInterface $logger;

    public function __construct(
        private FetcherInterface $fetcher,
        private string $host = 'gitlab.com',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(Package $package): bool
    {
        $host = parse_url($package->sourceUrl, \PHP_URL_HOST);
        if (! \is_string($host) || strtolower($host) !== strtolower($this->host)) {
            return false;
        }

        return GitLabProjectPath::fromUrl($package->sourceUrl, $this->host) instanceof GitLabProjectPath;
    }

    public function resolve(Package $package, VersionRange $range): Source
    {
        $project = GitLabProjectPath::fromUrl($package->sourceUrl, $this->host)
            ?? throw new FetchException(
                message: "Source URL is not a GitLab project: {$package->sourceUrl}",
                statusCode: 0,
            );

        $projectLabel = "{$project->namespace}/{$project->project}";
        $all = [];
        $pageActuallyFetched = 0;

        for ($page = 1; $page <= self::PAGINATION_CAP; ++$page) {
            $pageUrl = "https://{$this->host}/api/v4/projects/{$project->encodedPath}/releases?per_page=" . self::PER_PAGE . "&page={$page}&order_by=released_at&sort=desc";
            $this->logger->debug('Fetching releases page {page} of {project}', [
                'page' => $page,
                'project' => $projectLabel,
            ]);

            $response = $this->fetcher->fetch(new Source(
                type: SourceTypes::GITLAB_RELEASES,
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
            // GitLab returns releases in descending version order (sort=desc); once any entry
            // on the current page satisfies tag_name <= range->from, all subsequent
            // pages contain only entries BELOW the requested range and are wasted
            // HTTP calls. The CURRENT page is preserved in $all (boundary check
            // happens AFTER array_merge), so all in-range entries through the
            // from-boundary land in prefetchedBody.
            //
            // Normalization: tag_names from GitLab may carry a `v` prefix (e.g.
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
                    $tagName = $entry['tag_name'] ?? null;
                    // Resolver-side reads tag_name STRICTLY; parser-side keeps tag_name ?? name precedence.
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
                $this->logger->debug('Releases pagination boundary reached at {project}: from={from}, stopping after page {page}', [
                    'project' => $projectLabel,
                    'from' => $range->from,
                    'page' => $page,
                ]);
                break;
            }
        }

        $truncationSignal = ($pageActuallyFetched === self::PAGINATION_CAP
            && \count($all) === self::PAGINATION_CAP * self::PER_PAGE)
            ? 'gitlab_releases_capped'
            : null;

        $this->logger->info('Fetched {count} releases from {project} across {pages} pages', [
            'count' => \count($all),
            'project' => $projectLabel,
            'pages' => $pageActuallyFetched,
        ]);

        if ($truncationSignal !== null) {
            $this->logger->notice('GitLab Releases pagination cap (1000) hit for {project}', [
                'project' => $projectLabel,
            ]);
        }

        $concatenated = json_encode($all, JSON_THROW_ON_ERROR);

        return new Source(
            type: SourceTypes::GITLAB_RELEASES,
            url: "https://{$this->host}/api/v4/projects/{$project->encodedPath}/releases?per_page=" . self::PER_PAGE . '&page=1&order_by=released_at&sort=desc',
            metadata: [
                'pages_fetched' => $pageActuallyFetched,
                'releases_count' => \count($all),
                'truncation_signal' => $truncationSignal,
                'host' => $this->host,
                'project_path' => $projectLabel,
            ],
            prefetchedBody: $concatenated,
        );
    }
}

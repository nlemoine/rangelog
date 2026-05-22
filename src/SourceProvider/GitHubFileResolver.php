<?php

declare(strict_types=1);

namespace n5s\Rangelog\SourceProvider;

use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Util\GitHubRepoUrl;
use n5s\Rangelog\Util\VersionBranchDeriver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fallback resolver for GitHub-hosted packages without Releases.
 *
 * `supports()` is a synchronous URL-host check. Returns true iff
 * `parse_url($pkg->sourceUrl)['host']` case-insensitively equals `$repoHost`
 * AND `GitHubRepoUrl::fromUrl($pkg->sourceUrl)` is non-null. The constructor
 * accepts optional `$rawHost`/`$repoHost` so callers can target GHE.
 *
 * `resolve()` walks four filenames in dependabot priority order
 * (`CHANGELOG.md` > `CHANGELOG` > `HISTORY.md` > `CHANGES.md`) against
 * `raw.githubusercontent.com`. Branch detection is one-shot on the FIRST
 * file: try `main`, then `master`, then version-derived branches on 404.
 * Lock to the branch that returned 200. If all branches 404 on the first
 * file, walk remaining files on `main` only.
 *
 * The first file probe extends the branch list with version-derived
 * candidates derived from `range->to`. For `range->to = '7.4.1'`, the
 * extended list is `[main, master, 7.4, 7.x, 7.4.x]`. This covers the
 * modern PHP ecosystem convention (Symfony on `7.4`, flysystem on `3.x`,
 * Laravel illuminate/* subtree-splits on `{major}.x`) where CHANGELOG.md
 * lives on a per-major-line or per-minor-line branch rather than on
 * `main`/`master`.
 *
 * Non-semver `range->to` (e.g. wpackagist date-versions `'20231015'`) is
 * tolerated by `VersionBranchDeriver::deriveBranches()`: the util returns
 * `[]` and the resolver falls back to `[main, master]` only — it never
 * crashes.
 *
 * All 4 files × probed branches returning 404 → `ChangelogNotFoundException`
 * (NOT fall-through; this resolver is typically the chain's last).
 * 5xx / 429 / network → propagate.
 */
final readonly class GitHubFileResolver implements SourceProviderInterface
{
    /** @var list<string> */
    private const array CHANGELOG_FILES = ['CHANGELOG.md', 'CHANGELOG', 'HISTORY.md', 'CHANGES.md'];

    /** @var list<string> */
    private const array INITIAL_BRANCHES = ['main', 'master'];

    private LoggerInterface $logger;

    public function __construct(
        private FetcherInterface $fetcher,
        private string $rawHost = 'raw.githubusercontent.com',
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
        $branch = null;

        // Derive version-prefix branches from range->to once and log them.
        $derivedBranches = VersionBranchDeriver::deriveBranches($range->to);
        if ($derivedBranches !== []) {
            $this->logger->debug('Derived version branches for {package}: {branches}', [
                'package' => $package->name,
                'branches' => implode(', ', $derivedBranches),
            ]);
        }

        foreach (self::CHANGELOG_FILES as $fileIndex => $file) {
            if ($branch !== null) {
                // Branch already locked from a previous file — probe it exclusively.
                $branchesToTry = [$branch];
            } elseif ($fileIndex === 0) {
                // First file probe extends the initial list with derived candidates.
                $branchesToTry = array_merge(self::INITIAL_BRANCHES, $derivedBranches);
            } else {
                // Subsequent files when no branch was locked: fall back to INITIAL_BRANCHES
                // only. The version-branch scan already happened on the first file; repeating
                // it for CHANGELOG / HISTORY.md / CHANGES.md would multiply HTTP calls
                // without adding useful signal.
                $branchesToTry = self::INITIAL_BRANCHES;
            }

            foreach ($branchesToTry as $candidate) {
                $url = "https://{$this->rawHost}/{$repo->owner}/{$repo->repo}/{$candidate}/{$file}";
                $this->logger->debug('Trying {url}', ['url' => $url]);

                try {
                    $this->fetcher->fetch(new Source(type: SourceTypes::GITHUB_FILE, url: $url));
                } catch (FetchException $e) {
                    if ($e->statusCode === 404) {
                        continue;
                    }
                    throw $e;
                }

                if ($branch === null) {
                    $branch = $candidate;
                    $this->logger->debug('Branch {branch} confirmed for {repo}', [
                        'branch' => $branch,
                        'repo' => $repoLabel,
                    ]);
                }

                $this->logger->info('Resolved {package} from {url}', [
                    'package' => $package->name,
                    'url' => $url,
                    'branch' => $branch,
                    'file' => $file,
                ]);

                return new Source(
                    type: SourceTypes::GITHUB_FILE,
                    url: $url,
                    metadata: ['branch' => $branch, 'file' => $file],
                );
            }

            // End of inner loop — all branches returned 404 for this file.
            // After the FIRST file, lock to `main` so we don't double-probe branches.
            if ($fileIndex === 0 && $branch === null) {
                $branch = 'main';
            }
        }

        throw new ChangelogNotFoundException(
            "No changelog file found in {$repo->owner}/{$repo->repo} for package {$package->name} (checked: "
                . implode(', ', self::CHANGELOG_FILES) . ')',
        );
    }
}

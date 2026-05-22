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
use n5s\Rangelog\Util\GitLabProjectPath;
use n5s\Rangelog\Util\VersionBranchDeriver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fallback resolver for GitLab-hosted projects without releases (or whose
 * Releases pagination was exhausted by GitLabReleasesResolver).
 *
 * `supports()` performs synchronous URL-host inspection via
 * `GitLabProjectPath::fromUrl` (anchored-regex defense-in-depth). No HTTP
 * call. The constructor accepts an optional `$host` param so self-hosted
 * GitLab instances can be addressed.
 *
 * Single host parameter (default 'gitlab.com'). Walks the same 4 filenames
 * as GitHubFileResolver in dependabot priority order. Branch list =
 * [main, master, ...VersionBranchDeriver::deriveBranches($range->to)].
 *
 * Branch-lock-after-first-file invariant: the FIRST file's first 200 locks
 * the branch; subsequent files probe the locked branch only.
 *
 * All 4 files × all branches 404 → ChangelogNotFoundException;
 * 5xx / 429 / network → propagate verbatim (FetchException /
 * RateLimitedException).
 *
 * URL template: `https://{host}/api/v4/projects/{encodedPath}/repository/files/{rawurlencode(file)}/raw?ref={branch}`.
 *
 * Logging mirrors GitHubFileResolver wording with GitLab / {project}
 * substitutions.
 */
final readonly class GitLabFileResolver implements SourceProviderInterface
{
    /** @var list<string> */
    private const array CHANGELOG_FILES = ['CHANGELOG.md', 'CHANGELOG', 'HISTORY.md', 'CHANGES.md'];

    /** @var list<string> */
    private const array INITIAL_BRANCHES = ['main', 'master'];

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
                $url = "https://{$this->host}/api/v4/projects/{$project->encodedPath}/repository/files/"
                    . rawurlencode($file)
                    . "/raw?ref={$candidate}";
                $this->logger->debug('Trying {url}', ['url' => $url]);

                try {
                    $this->fetcher->fetch(new Source(type: SourceTypes::GITLAB_FILE, url: $url));
                } catch (FetchException $e) {
                    if ($e->statusCode === 404) {
                        continue;
                    }
                    throw $e;
                }

                if ($branch === null) {
                    $branch = $candidate;
                    $this->logger->debug('Branch {branch} confirmed for {project}', [
                        'branch' => $branch,
                        'project' => $projectLabel,
                    ]);
                }

                $this->logger->info('Resolved {package} from {url}', [
                    'package' => $package->name,
                    'url' => $url,
                    'branch' => $branch,
                    'file' => $file,
                ]);

                return new Source(
                    type: SourceTypes::GITLAB_FILE,
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
            "No changelog file found in {$project->namespace}/{$project->project} for package {$package->name} (checked: "
                . implode(', ', self::CHANGELOG_FILES) . ')',
        );
    }
}

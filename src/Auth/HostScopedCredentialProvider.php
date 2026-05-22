<?php

declare(strict_types=1);

namespace n5s\Rangelog\Auth;

/**
 * Credential provider that dispatches to per-host inner providers and returns
 * an empty header map for hosts not in the allowlist.
 *
 * Wraps a `host => CredentialProviderInterface` map. On `authorize($url)`,
 * parses the URL host, lowercases it, and forwards to the matching inner
 * provider. Hosts not in the map produce `[]` (no auth).
 *
 * This is the recommended wiring shape for any caller using
 * {@see AuthorizingFetcher}. Per-host primitives like
 * {@see BearerTokenProvider} and {@see GitLabTokenProvider} return their
 * configured header regardless of URL, which is unsafe in isolation because
 * package metadata can steer the URL to an arbitrary host. Wrapping them in
 * this dispatcher ensures the token only flows to the host it was issued
 * for:
 *
 *   new HostScopedCredentialProvider([
 *       'api.github.com'            => new BearerTokenProvider($ghPat),
 *       'raw.githubusercontent.com' => new BearerTokenProvider($ghPat),
 *       'gitlab.com'                => new GitLabTokenProvider($glPat),
 *   ]);
 *
 * Host matching is EXACT (no automatic subdomain matching) and
 * case-insensitive. Port numbers are ignored. URLs with no parseable host
 * (malformed, empty) produce `[]`.
 */
final readonly class HostScopedCredentialProvider implements CredentialProviderInterface
{
    /**
     * @var array<string, CredentialProviderInterface>  Map keyed by lowercased host.
     */
    private array $providers;

    /**
     * @param array<string, CredentialProviderInterface> $providers Map from host
     *        (case-insensitive, no port) to the provider for that host.
     */
    public function __construct(array $providers)
    {
        $normalized = [];
        foreach ($providers as $host => $provider) {
            $normalized[strtolower($host)] = $provider;
        }
        $this->providers = $normalized;
    }

    /**
     * @return array<string, string>
     */
    public function authorize(string $url): array
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (! \is_string($host) || $host === '') {
            return [];
        }

        $key = strtolower($host);
        if (! isset($this->providers[$key])) {
            return [];
        }

        return $this->providers[$key]->authorize($url);
    }

    /**
     * Convenience factory pre-wired for the standard public hosts of
     * github.com and gitlab.com:
     *
     *  - `$githubToken` is attached as `Authorization: Bearer {token}` for
     *    BOTH `api.github.com` (REST API) and `raw.githubusercontent.com`
     *    (raw file content). These are the two hosts the bundled GitHub
     *    resolvers contact; forgetting either leaves that surface
     *    unauthenticated and silently rate-limited at 60/hr.
     *  - `$gitlabToken` is attached as `PRIVATE-TOKEN: {token}` for
     *    `gitlab.com`.
     *
     * Passing `null` for either argument omits that mapping. Passing both
     * `null` returns a no-op provider equivalent to {@see NullCredentialProvider}.
     *
     * For self-hosted GitHub Enterprise, GitLab, or other custom hosts, use
     * the regular constructor with an explicit map.
     */
    public static function standard(
        ?string $githubToken = null,
        ?string $gitlabToken = null,
    ): self {
        $map = [];

        if ($githubToken !== null) {
            $github                           = new BearerTokenProvider($githubToken);
            $map['api.github.com']            = $github;
            $map['raw.githubusercontent.com'] = $github;
        }

        if ($gitlabToken !== null) {
            $map['gitlab.com'] = new GitLabTokenProvider($gitlabToken);
        }

        return new self($map);
    }
}

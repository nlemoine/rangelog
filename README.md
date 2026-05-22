# n5s/rangelog

A PHP library that, given a `(name, sourceUrl)` pair and a version range, returns structured changelog notes. Sources covered: GitHub Releases, in-repo `CHANGELOG.md` files, WordPress.org plugin readmes, GitLab Releases, the GitLab repository file API, and any accessible markdown URL.

[![Packagist Version](https://img.shields.io/packagist/v/n5s/rangelog.svg)](https://packagist.org/packages/n5s/rangelog)
[![CI](https://github.com/n5s/rangelog/actions/workflows/ci.yml/badge.svg)](https://github.com/n5s/rangelog/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## Why

Built to feed DIY auto-update pipelines on top of `composer update`. A typical pipeline runs `composer update`, opens a PR via [`composer-diff`](https://github.com/IonBazan/composer-diff) for the package-version delta, runs checks, and merges. What's usually missing in that PR is the actual changelog notes, the kind Dependabot and Renovate produce out of the box for npm. This library produces them for any package.

The priority is GitHub-hosted packages and WordPress.org plugins, with structured output the caller can trust. Other sources (GitLab, generic markdown URLs) are first-class but tested less broadly.

## Requirements

- PHP 8.3, 8.4, or 8.5. Strict types, typed class constants and readonly classes are used throughout.
- A PSR-18 HTTP client. For example [`php-http/curl-client`](https://packagist.org/packages/php-http/curl-client), [`symfony/http-client`](https://packagist.org/packages/symfony/http-client), or [`guzzlehttp/guzzle`](https://packagist.org/packages/guzzlehttp/guzzle) with an [HTTPlug adapter](https://docs.php-http.org/en/latest/clients/guzzle7-adapter.html).
- A PSR-17 request/stream factory. For example [`nyholm/psr7`](https://packagist.org/packages/nyholm/psr7) or [`guzzlehttp/psr7`](https://packagist.org/packages/guzzlehttp/psr7).
- Optional: any PSR-6 (`Psr\Cache\CacheItemPoolInterface`) or PSR-16 (`Psr\SimpleCache\CacheInterface`) cache.
- Optional: any PSR-3 (`Psr\Log\LoggerInterface`). Defaults to `Psr\Log\NullLogger`.

The library bundles no HTTP client, cache, or logger. All network access goes through the PSR-18 client you inject; caching and logging are the caller's concern.

## Install

```bash
composer require n5s/rangelog
```

You also need a PSR-18 client and a PSR-17 factory. Two popular minimal choices:

```bash
# Curl + nyholm/psr7
composer require php-http/curl-client nyholm/psr7
```

```bash
# Symfony HTTP Client + nyholm/psr7
composer require symfony/http-client nyholm/psr7
```

Any PSR-18 / PSR-17 implementation works. The two snippets above are popular minimal choices; pick whatever you already have in your project.

## Quickstart

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use n5s\Rangelog\Rangelog;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Fetcher\HttpFetcher;
use n5s\Rangelog\Parser\ParserRegistry;
use n5s\Rangelog\Renderer\MarkdownRenderer;
use n5s\Rangelog\SourceProvider\GitHubFileResolver;
use n5s\Rangelog\SourceProvider\GitHubReleasesResolver;
use n5s\Rangelog\SourceProvider\GitLabFileResolver;
use n5s\Rangelog\SourceProvider\GitLabReleasesResolver;
use n5s\Rangelog\SourceProvider\MarkdownUrlResolver;
use n5s\Rangelog\SourceProvider\SourceProviderChain;
use n5s\Rangelog\SourceProvider\WordPressOrgResolver;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\Psr18Client;               // or any PSR-18 client

$factory  = new Psr17Factory();                             // PSR-17 — request and stream factory.
$client   = new Psr18Client();                              // PSR-18 — any implementation; no Authorization headers here.
$fetcher  = new HttpFetcher($client, $factory);             // Wraps the PSR-18 client; caching is optional (see § Caching).

$chain    = new SourceProviderChain([                       // Manual composition (BYO) — order = first wins.
    new WordPressOrgResolver($fetcher),                     // wp.org SVN readme.txt — matches wordpress.org/plugins/* URLs.
    new GitHubReleasesResolver($fetcher),                   // Paginated Releases API (up to 1000 entries).
    new GitHubFileResolver($fetcher),                       // CHANGELOG.md / CHANGELOG / HISTORY.md / CHANGES.md fallback.
    new GitLabReleasesResolver($fetcher),                   // GitLab Releases API; optional $host for self-hosted.
    new GitLabFileResolver($fetcher),                       // GitLab Repository Files API; optional $host.
    new MarkdownUrlResolver($fetcher),                      // Generic fallback: fetches sourceUrl as-is and parses markdown.
]);

$parsers  = ParserRegistry::defaults();                     // Convenience factory: parsers keyed by SourceTypes::*.
$renderer = new MarkdownRenderer();                         // Produces GFM with empty fallback + partial admonition.

$libClient = new Rangelog($chain, $fetcher, $parsers, $renderer);

$pkg       = new Package('symfony/console', 'https://github.com/symfony/console');
$changelog = $libClient->changelog($pkg, '6.4.0', '7.1.0');
echo $libClient->render($changelog);
```

Output is a GitHub-flavored markdown string suitable for direct insertion into a PR comment.

The Quickstart above does no authentication and follows no redirects. For real auto-update pipelines that hit private repos or burn through unauthenticated GitHub's 60/hr rate limit, see the production wiring just below.

### Production wiring

The defensive composition: per-host scoped credentials, no auto-follow on redirects, caching wrapping auth so the cache short-circuits before any token is computed.

```php
use n5s\Rangelog\Auth\HostScopedCredentialProvider;
use n5s\Rangelog\Cache\Psr6CacheAdapter;
use n5s\Rangelog\Fetcher\AuthorizingFetcher;
use n5s\Rangelog\Fetcher\CachingFetcher;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Fetcher\FetcherStack;
use n5s\Rangelog\Fetcher\HttpFetcher;
use n5s\Rangelog\Fetcher\NoRedirectClient;
use n5s\Rangelog\Renderer\MarkdownRenderer;
use n5s\Rangelog\Renderer\SanitizingMarkdownRenderer;
use n5s\Rangelog\Renderer\TruncatingRenderer;
use n5s\Rangelog\SourceProvider\GitHubFileResolver;
use n5s\Rangelog\SourceProvider\GitHubReleasesResolver;
use n5s\Rangelog\SourceProvider\GitLabFileResolver;
use n5s\Rangelog\SourceProvider\GitLabReleasesResolver;
use n5s\Rangelog\SourceProvider\MarkdownUrlResolver;
use n5s\Rangelog\SourceProvider\SourceProviderChain;
use n5s\Rangelog\SourceProvider\WordPressOrgResolver;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

// 1. PSR-18 client with redirects disabled at the client level too.
$rawClient = new Psr18Client(HttpClient::create(['max_redirects' => 0]));
$client    = new NoRedirectClient($rawClient);
$factory   = new Psr17Factory();

// 2. Credentials, scoped to known hosts. Unknown hosts get [] (no auth leak).
//    ::standard() pre-wires github.com (both api + raw hosts) and gitlab.com.
//    For self-hosted GHE / GitLab, drop back to the constructor with an
//    explicit map.
$credentials = HostScopedCredentialProvider::standard(
    githubToken: $githubPat,
    gitlabToken: $gitlabPat,
);

// 3. Fetcher stack: HTTP at the base, auth then caching layered on top.
//    Effective wiring is Caching(Authorizing(Http)) — cache hits skip auth entirely.
$fetcher = new FetcherStack(
    base: new HttpFetcher($client, $factory),
    decorators: [
        fn (FetcherInterface $i) => new AuthorizingFetcher($i, $credentials),
        fn (FetcherInterface $i) => new CachingFetcher($i, new Psr6CacheAdapter($yourPsr6Pool)),
    ],
);

// 4. Resolver chain. Constrain MarkdownUrlResolver to known hosts so
//    attacker-controlled package metadata can't steer the library into
//    fetching arbitrary URLs.
$chain = new SourceProviderChain([
    new WordPressOrgResolver($fetcher),
    new GitHubReleasesResolver($fetcher),
    new GitHubFileResolver($fetcher),
    new GitLabReleasesResolver($fetcher),
    new GitLabFileResolver($fetcher),
    new MarkdownUrlResolver($fetcher, allowedHosts: [
        'raw.githubusercontent.com',
        'gitlab.com',
    ]),
]);

// 5. Renderer stack. Sanitize upstream content, then cap output size.
$renderer = new TruncatingRenderer(
    inner: new SanitizingMarkdownRenderer(
        inner: new MarkdownRenderer(),
        stripMentions: true,
        linkRewriter: fn (string $url) => 'https://my-redirect.internal/?to=' . urlencode($url),
    ),
    maxBytes: 60_000,
);
```

What this buys you:

- Tokens never go to hosts you didn't whitelist, even if upstream package metadata steers a URL to `attacker.example`.
- `MarkdownUrlResolver` rejects unknown hosts at the resolver layer (defence in depth alongside the credential scoping).
- Cached responses skip the credential code path entirely.
- Redirect responses surface as `RedirectNotFollowedException` (a PSR-18 `ClientExceptionInterface`) instead of being auto-followed across origins.
- `@user` and `@org/team` mentions in upstream changelog content are stripped before rendering — no accidental notifications when the output lands in a PR body.
- HTTP/HTTPS links in changelog content are routed through your own redirector for interstitial click-warnings.
- Output is byte-capped to fit GitHub PR-body limits.

The details are in the [Security model](#security-model) section.

## Examples

### GitHub Releases

For packages whose repo hosts changelog entries as GitHub Releases (the most common pattern for Symfony, Laravel, PHPUnit, monorepos with `gh release create`).

```php
$pkg = new Package('symfony/console', 'https://github.com/symfony/console');
$changelog = $libClient->changelog($pkg, '6.4.0', '7.1.0');
echo $libClient->render($changelog);
```

Notes: `GitHubReleasesResolver` paginates up to 1000 releases. The GitHub API is rate-limited to 60 unauthenticated requests/hour per IP; for production use, supply a PSR-18 client with `Authorization: Bearer <token>` middleware, or use `AuthorizingFetcher` (see [Auth composition](#auth-composition) below).

### GitHub file CHANGELOG.md

For packages that maintain their changelog as a markdown file in the repo (Keep-a-Changelog style or freeform).

```php
$pkg = new Package('monolog/monolog', 'https://github.com/Seldaek/monolog');
$changelog = $libClient->changelog($pkg, '3.5.0', '3.7.0');
echo $libClient->render($changelog);
```

`GitHubFileResolver` walks `CHANGELOG.md → CHANGELOG → HISTORY.md → CHANGES.md` on the `main` branch first, then on `master`. The first non-404 hit is parsed by `MarkdownParser`, which walks the `league/commonmark` AST (no regex).

Rendered output preserves KAC-style version headings and section bullets (`### Added`, `### Fixed`, etc.) per version.

### WordPress.org plugin readme

For packages whose changelog source is a WordPress.org plugin page, pass the plugin URL.

```php
$pkg = new Package('akismet', 'https://wordpress.org/plugins/akismet/');
$changelog = $libClient->changelog($pkg, '5.0', '5.3');
echo $libClient->render($changelog);
```

`WordPressOrgResolver` walks 6 SVN URLs: 3 tag-filename candidates and 3 trunk-filename candidates (`changelog.md`, `CHANGELOG.md`, `readme.txt`). The unauthenticated SVN trunk hit on `plugins.svn.wordpress.org` is the source of truth; the WordPress.org REST API truncates the changelog field at 10 KB, and SVN bypasses that.

Notes: the readme dialect uses `= 1.2.3 =` version headings rendered as `<h4>` in HTML. The `WordPressReadmeParser` reconstructs the per-version segmentation post-parse.

### GitLab Releases

For packages hosted on GitLab whose changelog entries are published as GitLab Releases.

```php
$pkg = new Package('myorg/myrepo', 'https://gitlab.com/myorg/myrepo');
$changelog = $libClient->changelog($pkg, '1.0.0', '2.0.0');
echo $libClient->render($changelog);
```

`GitLabReleasesResolver` paginates the GitLab Releases API. For self-hosted GitLab instances, pass a custom host: `new GitLabReleasesResolver($fetcher, host: 'gitlab.example.com')`.

### GitLab CHANGELOG file

For packages hosted on GitLab that maintain a markdown changelog file in the repository.

```php
$pkg = new Package('myorg/myrepo', 'https://gitlab.com/myorg/myrepo');
$changelog = $libClient->changelog($pkg, '1.0.0', '2.0.0');
echo $libClient->render($changelog);
```

`GitLabFileResolver` walks the same `CHANGELOG.md → CHANGELOG → HISTORY.md → CHANGES.md` filename candidates via the GitLab Repository Files API. For self-hosted instances, pass the optional `string $host` parameter.

### Generic markdown URL

For packages whose changelog is accessible at an arbitrary URL (not served by a recognized host).

```php
$pkg = new Package('some-lib', 'https://raw.example.com/CHANGELOG.md');
$changelog = $libClient->changelog($pkg, '1.0.0', '2.0.0');
echo $libClient->render($changelog);
```

`MarkdownUrlResolver` is the chain's generic fallback (ordered last). It fetches the URL verbatim and parses the response as markdown via `MarkdownParser`. Unlike `GitHubFileResolver`, it does not scan filename variants. The URL is taken as-is.

## Ecosystem-agnostic call shapes

The library does not validate the shape of `$name`. npm, Cargo, Maven, Go modules, Composer-style `vendor/name`, scoped npm, and other naming conventions all work. The `$sourceUrl` is what matters: its host routes the call to the right resolver.

### npm (React)

```php
$pkg = new Package('react', 'https://github.com/facebook/react');
$changelog = $libClient->changelog($pkg, '18.0.0', '18.2.0');
```

`react` is a bare npm-style name (no `/`). The library accepts any non-empty name; `https://github.com/facebook/react` routes through `GitHubReleasesResolver` the same way a `vendor/package`-shaped name would.

### Cargo (serde)

```php
$pkg = new Package('serde', 'https://github.com/serde-rs/serde');
$changelog = $libClient->changelog($pkg, '1.0.180', '1.0.200');
```

`serde` is a Cargo-style name. Same call shape: the URL host (`github.com`) selects the resolver. The name is just an identifier the caller carries through.

## Bounded output

Real changelogs can be huge: a popular package with 200 releases produces hundreds of KB of markdown. If you're piping the rendered output into a GitHub PR body (~65 KB limit), a Slack message, or an email, you want a hard cap. `TruncatingRenderer` is a decorator over `RendererInterface` that drops the oldest entries until the rendered output fits a byte budget:

```php
use n5s\Rangelog\Renderer\MarkdownRenderer;
use n5s\Rangelog\Renderer\TruncatingRenderer;

$renderer = new TruncatingRenderer(
    inner: new MarkdownRenderer(),
    maxBytes: 60_000,                   // GitHub PR body is ~65 KB, leave headroom.
);

$markdown = $renderer->render($changelog);
```

Newest entries (highest semver) are always kept. When truncation happens, the result is marked partial and the inner renderer's existing `> [!WARNING]` admonition fires at the top of the output with a reason like *"7 older entries omitted to fit 60000-byte budget"*. If the source `Changelog` was already partial (e.g. WordPress.org truncated upstream), both reasons are surfaced.

If even an empty render exceeds the budget (pathologically small `maxBytes`), the decorator falls back to the full inner render unchanged: best-effort, the caller can detect overflow themselves.

### Sanitizing upstream content

`SanitizingMarkdownRenderer` is a sibling decorator that applies markdown-level transforms before output. Two opt-in transforms:

```php
use n5s\Rangelog\Renderer\MarkdownRenderer;
use n5s\Rangelog\Renderer\SanitizingMarkdownRenderer;

$renderer = new SanitizingMarkdownRenderer(
    inner: new MarkdownRenderer(),
    stripMentions: true,                                                       // @user / @org/team → user / org/team
    linkRewriter: fn (string $url) => 'https://my-redirect.test/?to=' . urlencode($url),
);
```

- **Mention stripping** removes the leading `@` from `@user` and `@org/team` patterns outside code blocks/spans, so upstream content can't trigger notifications when rendered to GitHub PR bodies, Slack, etc.
- **Link rewriting** passes every http/https URL in standard markdown inline links through a callable. The callable decides what to do (route through an interstitial, normalize against an allowlist, replace with a warning). Other URL schemes and reference-style links are left alone.

Compose with `TruncatingRenderer` outside: sanitize first, then cap size.

Limits: this is regex-based (league/commonmark doesn't ship a markdown re-renderer). It handles fenced code blocks ```` ``` ```` and ```` ~~~ ```` and backtick code spans, but does not touch reference-style links, autolinks `<…>`, or inline HTML. See the class docblock for full details.

## Security model

Upstream content is untrusted by default. The library ships primitives that close the main attack paths when composed together; the [Production wiring](#production-wiring) snippet uses all of them.

### Wire these in production

| Concern | Shipped primitive |
|---|---|
| Tokens reaching unexpected hosts | `HostScopedCredentialProvider::standard()` (or explicit map for self-hosted) |
| Auth header following a 30x to another origin | `NoRedirectClient` + `max_redirects: 0` on your PSR-18 client |
| Generic-URL fallback fetching attacker-controlled hosts | `MarkdownUrlResolver(allowedHosts: [...])` |
| Output size DoS | `TruncatingRenderer` |

Those four close the credential-leak and resource-exhaustion paths. Each one's constructor docblock explains the threat and the trade-offs in detail.

### Reduce blast radius: minimal-scope tokens

A GitHub PAT added "just to bump the 60/hr rate limit" carries whatever scopes you attached to it. If rate limits are the only goal, issue a fine-grained PAT on a dedicated bot account with no repo access (or a classic PAT with no scopes selected). A leak of a bot account's no-scope PAT is a non-event; a leak of your personal PAT is not. GitLab has the same shape — scope `read_api` to a single project rather than using a personal token.

### Optional: sanitize rendered output

When the output lands in a place that processes markdown (PR bodies, Slack), upstream content can include phishing links or `@`-mentions that trigger notifications. `SanitizingMarkdownRenderer` rewrites link URLs through a callable of your choice and strips mentions; see [Sanitizing upstream content](#sanitizing-upstream-content). Both are opt-in — they don't matter for pipelines that just log or store the output.

### What the library cannot prevent

- **TLS misconfiguration in your PSR-18 client.** Don't disable certificate verification.
- **Auto-follow redirects inside your PSR-18 client.** PSR-18 is opaque to the library; configure your client to disable auto-follow (the wrapper above handles the case where the client still surfaces a 30x).
- **Alert spoofing.** A malicious changelog body can include `> [!WARNING]` blocks indistinguishable from the renderer's partial-result admonition. Treat any `> [!…]` block beyond the first as untrusted upstream content.

## Caching

Caching is opt-in. The library accepts either PSR-16 (preferred, simpler API) or PSR-6 via a shipped adapter.

### PSR-16 (preferred)

Wrap your base fetcher with `CachingFetcher` and pass any `Psr\SimpleCache\CacheInterface` implementation (e.g. [`cache/array-adapter`](https://packagist.org/packages/cache/array-adapter), [`symfony/cache`](https://packagist.org/packages/symfony/cache) via its `Psr16Cache` wrapper, or a redis-backed PSR-16 implementation):

```php
use n5s\Rangelog\Fetcher\CachingFetcher;
use n5s\Rangelog\Fetcher\HttpFetcher;
use Psr\SimpleCache\CacheInterface;

/** @var CacheInterface $cache  Any PSR-16 implementation. */
$fetcher = new CachingFetcher(
    inner: new HttpFetcher($client, $factory),
    cache: $cache,
);
```

### PSR-6 (via Psr6CacheAdapter)

Symfony Cache exposes pool-based PSR-6 (`CacheItemPoolInterface`). Wrap the pool with the shipped adapter:

```php
use n5s\Rangelog\Cache\Psr6CacheAdapter;
use n5s\Rangelog\Fetcher\CachingFetcher;
use n5s\Rangelog\Fetcher\HttpFetcher;
use Psr\Cache\CacheItemPoolInterface;

/** @var CacheItemPoolInterface $pool  Any PSR-6 pool. */
$fetcher = new CachingFetcher(
    inner: new HttpFetcher($client, $factory),
    cache: new Psr6CacheAdapter($pool),
);
```

Cache keys are derived from `sha256(Source.url)`: stable, opaque, and request metadata never leaks into the cache backend. The `CachingFetcher` round-trips the response's `ETag` header via `If-None-Match` on the subsequent fetch, so a 304 returns the cached body without re-counting against your rate-limit budget.

Negative results are not cached. A `FetchException` or `RateLimitedException` never poisons the cache; only successful responses are stored.

### Auth composition

For per-URL credentials, compose `AuthorizingFetcher` *inside* `CachingFetcher` so the cache short-circuits before any `authorize()` call. The recommended wiring uses `HostScopedCredentialProvider::standard()` to pre-wire the standard public hosts of github.com and gitlab.com (see [Security model](#security-model) for why per-host scoping matters):

```php
use n5s\Rangelog\Auth\HostScopedCredentialProvider;
use n5s\Rangelog\Fetcher\AuthorizingFetcher;
use n5s\Rangelog\Fetcher\CachingFetcher;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Fetcher\FetcherStack;
use n5s\Rangelog\Fetcher\HttpFetcher;

$credentials = HostScopedCredentialProvider::standard(
    githubToken: $githubPat,
    gitlabToken: $gitlabPat,
);

$fetcher = new FetcherStack(
    base: new HttpFetcher($psr18, $psr17Factory),
    decorators: [
        fn (FetcherInterface $i) => new AuthorizingFetcher($i, $credentials),  // innermost
        fn (FetcherInterface $i) => new CachingFetcher($i, $cache),            // outermost; auth runs on cache miss only
    ],
);
// Effective wiring: Caching(Authorizing(Http))
```

`::standard()` returns a `HostScopedCredentialProvider` pre-wired for `api.github.com` + `raw.githubusercontent.com` (Bearer) and `gitlab.com` (PRIVATE-TOKEN). Hosts not in the map produce `[]` (no auth headers). This is the load-bearing default: a token issued for GitHub never travels to GitLab, WordPress.org, or an attacker-controlled URL.

For **self-hosted GHE, GitLab, or other custom hosts**, use the constructor directly with an explicit map:

```php
use n5s\Rangelog\Auth\BearerTokenProvider;
use n5s\Rangelog\Auth\GitLabTokenProvider;

$credentials = new HostScopedCredentialProvider([
    'github.example.corp' => new BearerTokenProvider($ghePat),
    'gitlab.internal'     => new GitLabTokenProvider($glPat),
]);
```

Callers wanting no auth at all either omit `AuthorizingFetcher` entirely, or pass `new NullCredentialProvider()` to keep the decorator in place for symmetry with prod. Callers with custom auth needs can implement `CredentialProviderInterface` directly — but if you skip `HostScopedCredentialProvider`, your implementation MUST do its own host check (see the integration test `CrossHostLeakTest` for the contract).

## Custom Resolvers / Parsers / Renderers

Every layer is a 1–2 method interface, PSR-style. Add your own GitLab resolver, JSON renderer, or custom-format parser without subclassing anything.

- `SourceProviderInterface`: two methods, `supports(Package): bool` and `resolve(Package, VersionRange): Source`. Register a new `SourceType` string by constructing `Source(type: 'my_internal_v1', url: $url, …)` and adding a matching parser to the registry.
- `FetcherInterface`: a single method `fetch(Source): RawResponse`. Wrap your own retry, rate-limit, or auth-injection decorators around `HttpFetcher` using `FetcherStack`.
- `ChangelogParserInterface`: a single method `parse(RawResponse, VersionRange): Changelog`. Register a custom parser via `new ParserRegistry([...$defaults, 'my_internal_v1' => new MyParser()])`.
- `RendererInterface`: a single method `render(Changelog): string`. Ship an HTML, JSON, or org-mode renderer for non-markdown destinations.

The open-string `SourceType` design lets callers route any custom source through the parser registry. See `n5s\Rangelog\Domain\SourceTypes` for the six built-in constants (`GITHUB_RELEASES`, `GITHUB_FILE`, `GITLAB_RELEASES`, `GITLAB_FILE`, `WORDPRESS_ORG`, `MARKDOWN_URL`).

Authentication for HTTP-touching resolvers can be composed via `AuthorizingFetcher` + `CredentialProviderInterface` (see [Auth composition](#auth-composition) above). PSR-18 middleware still works for callers who prefer it; it's no longer the only path.

## Error Handling

All domain failure modes extend `n5s\Rangelog\Exception\ChangelogException`. Catch the base type for a safety net, or catch specific subtypes for fine-grained handling.

| Exception | When thrown | Notable fields |
|-----------|-------------|----------------|
| `ChangelogNotFoundException` | No resolver matched the package, or the matched resolver could not locate a changelog. | — |
| `FetchException` | HTTP failure from the injected PSR-18 client (non-2xx, non-304, or transport-level error). | `$statusCode`, `$bodyExcerpt` |
| `RateLimitedException` | HTTP 429 from upstream. Top-level sibling, not a child of `FetchException`. Catch this *before* the generic `FetchException` block. | `$retryAfter`, `$rateLimitReset` |
| `ParseException` | Malformed body bytes: JSON syntax error, missing required field, or malformed markdown for the source type. | — |
| `UnsupportedPackageException` | The package has a known-unresolvable shape (e.g. a WordPress.org plugin slug not found on wp.org). Does not fall through to GitHub. | — |
| `ChangelogException` | Abstract base. Catch this to handle all of the above with one block. | — |

```php
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Exception\ChangelogException;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\ParseException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Exception\UnsupportedPackageException;

try {
    $package = new Package('vendor/package', 'https://github.com/vendor/package');
    $changelog = $libClient->changelog($package, '1.0.0', '2.0.0');
} catch (RateLimitedException $e) {
    sleep($e->retryAfter ?? 60);                  // Back off and retry later.
} catch (ChangelogNotFoundException $e) {
    // No source could be located; fall back to package metadata, log, etc.
} catch (UnsupportedPackageException $e) {
    // Known-unresolvable shape; do not retry against another resolver.
} catch (FetchException $e) {
    // Network / HTTP failure; inspect $e->statusCode + $e->bodyExcerpt.
} catch (ParseException $e) {
    // Upstream body could not be parsed in the expected format.
} catch (ChangelogException $e) {
    // Safety net — should be unreachable given the per-type catches above.
}
```

Programmer-error inputs throw `\InvalidArgumentException` from `Package::__construct`, specifically: an empty `$name`, a `$name` with leading or trailing whitespace, and a `$sourceUrl` that does not parse as an `http://` or `https://` URL. The library does not enforce a vendor/name shape on `$name`: `'react'`, `'serde'`, `'@scope/pkg'`, `'com.foo:bar'`, and `'gopkg.in/yaml.v3'` are all valid. `\InvalidArgumentException` is a programmer-error sentinel and intentionally does not extend `ChangelogException`; pre-validate input if you need bulletproof handling.

## Acknowledgements

Prior art:

- [dependabot-core](https://github.com/dependabot/dependabot-core): Ruby reference for the end-to-end auto-update PR pipeline. Their `MetadataFinders::Base::ChangelogFinder` informs this library's resolver chain.
- [octochangelog](https://github.com/octokit/octochangelog): TypeScript precedent for compare-style GitHub release rendering.
- [composer-diff](https://github.com/IonBazan/composer-diff): PHP complement. `composer-diff` answers *"what packages changed?"*, this library answers *"what changed inside each package?"*.

## License

[MIT](LICENSE)

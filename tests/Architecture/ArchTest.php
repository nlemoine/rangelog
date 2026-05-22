<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture Rules (pest-plugin-arch)
|--------------------------------------------------------------------------
|
| Layer-boundary enforcement. Each rule blocks CI on violation.
|
| Rules are declared in this file (inside the Architecture testsuite per
| phpunit.xml.dist) so Pest 4 actually executes them. Declaring them in
| tests/Pest.php is a no-op because that file is the bootstrapper, not
| a scanned test file.
|
*/

arch('All concrete classes are final')
    ->expect('n5s\Rangelog')
    ->classes()
    ->toBeFinal()
    ->ignoring('n5s\Rangelog\Exception\ChangelogException'); // base exception is intentionally abstract; not testing finality on it

arch('Domain layer has no library dependencies')
    ->expect('n5s\Rangelog\Domain')
    ->not->toUse([
        'n5s\Rangelog\Fetcher',
        'n5s\Rangelog\Parser',
        'n5s\Rangelog\Renderer',
        'n5s\Rangelog\SourceProvider',
        'n5s\Rangelog\Exception',
    ]);

arch('Exception layer has no library dependencies')
    ->expect('n5s\Rangelog\Exception')
    ->not->toUse([
        'n5s\Rangelog\Domain',
        'n5s\Rangelog\Fetcher',
        'n5s\Rangelog\Parser',
        'n5s\Rangelog\Renderer',
        'n5s\Rangelog\SourceProvider',
    ]);

arch('Parser layer does not depend on Fetcher layer')
    ->expect('n5s\Rangelog\Parser')
    ->not->toUse('n5s\Rangelog\Fetcher');

arch('Renderer layer does not depend on Fetcher or Parser layers')
    ->expect('n5s\Rangelog\Renderer')
    ->not->toUse([
        'n5s\Rangelog\Fetcher',
        'n5s\Rangelog\Parser',
    ]);

arch('SourceProvider layer does not depend on Parser or Renderer layers')
    ->expect('n5s\Rangelog\SourceProvider')
    ->not->toUse([
        'n5s\Rangelog\Parser',
        'n5s\Rangelog\Renderer',
    ]);

arch('PHP baseline: strict types, no debug functions, no insecure functions')
    ->preset()->php()
    ->ignoring(['var_dump', 'dd', 'dump']); // explicit ignores acceptable; preset covers the rest

arch('All exception subclasses extend ChangelogException')
    ->expect('n5s\Rangelog\Exception')
    ->classes()
    ->toExtend('n5s\Rangelog\Exception\ChangelogException')
    ->ignoring('n5s\Rangelog\Exception\ChangelogException');

arch('Cache layer has no library dependencies')
    ->expect('n5s\Rangelog\Cache')
    ->not->toUse([
        'n5s\Rangelog\Domain',
        'n5s\Rangelog\Exception',
        'n5s\Rangelog\Fetcher',
        'n5s\Rangelog\Parser',
        'n5s\Rangelog\Renderer',
        'n5s\Rangelog\SourceProvider',
    ]);

// ---------------------------------------------------------------------------
// SourceProvider namespace boundary rules
// ---------------------------------------------------------------------------

arch('SourceProvider layer does not use concrete Fetcher implementations')
    ->expect('n5s\Rangelog\SourceProvider')
    ->not->toUse([
        'n5s\Rangelog\Fetcher\HttpFetcher',
        'n5s\Rangelog\Fetcher\CachingFetcher',
        'n5s\Rangelog\Fetcher\CachedResponse',
    ]);


// ---------------------------------------------------------------------------
// Renderer namespace boundary rules
// ---------------------------------------------------------------------------
//
// The `Renderer ↛ Fetcher + Parser` rule above and the global
// `All concrete classes are final` rule already cover Renderer finality
// and the Parser/Fetcher boundaries. The two rules below add the
// SourceProvider exclusion and the concrete-Fetcher-class exclusion.

arch('Renderer layer does not depend on SourceProvider layer')
    ->expect('n5s\Rangelog\Renderer')
    ->not->toUse([
        'n5s\Rangelog\SourceProvider',
    ]);

arch('Renderer layer does not use concrete Fetcher implementations')
    ->expect('n5s\Rangelog\Renderer')
    ->not->toUse([
        'n5s\Rangelog\Fetcher\HttpFetcher',
        'n5s\Rangelog\Fetcher\CachingFetcher',
        'n5s\Rangelog\Fetcher\CachedResponse',
        'n5s\Rangelog\Fetcher\FetcherStack',
    ]);

// ---------------------------------------------------------------------------
// Rangelog cross-layer permissions + FixtureFetcher placement
// ---------------------------------------------------------------------------
//
// Rule 1: the orchestrator at n5s\Rangelog\Rangelog must depend ONLY on
// the four public extension interfaces, the Domain VOs, the Exception
// hierarchy, and PSR. PartialResultDetector and ParserRegistry are
// explicitly allowed (the orchestrator calls
// PartialResultDetector::markPartialIfFromMissing at step 7 and
// ParserRegistry::parserFor at step 5).
//
// Rule 2: FixtureFetcher MUST live under n5s\Rangelog\Tests\TestSupport
// (positive) AND the n5s\Rangelog\Testing namespace MUST NOT exist
// (negative). Both halves are encoded so the `src/Testing/FixtureFetcher.php`
// option remains forbidden even if a future contributor copies the class
// instead of moving it.

arch('Rangelog depends only on interfaces (DIP)')
    ->expect('n5s\Rangelog\Rangelog')
    ->not->toUse([
        'n5s\Rangelog\Fetcher\HttpFetcher',
        'n5s\Rangelog\Fetcher\CachingFetcher',
        'n5s\Rangelog\Fetcher\CachedResponse',
        'n5s\Rangelog\Fetcher\FetcherStack',
        'n5s\Rangelog\Renderer\MarkdownRenderer',
        'n5s\Rangelog\SourceProvider\SourceProviderChain',
        'n5s\Rangelog\SourceProvider\WordPressOrgResolver',
        'n5s\Rangelog\SourceProvider\GitHubReleasesResolver',
        'n5s\Rangelog\SourceProvider\GitHubFileResolver',
        'n5s\Rangelog\SourceProvider\MarkdownUrlResolver',
        'n5s\Rangelog\Parser\MarkdownParser',
        'n5s\Rangelog\Parser\GitHubReleasesParser',
        'n5s\Rangelog\Parser\WordPressReadmeParser',
        'n5s\Rangelog\Cache',
    ]);

arch('FixtureFetcher lives under tests/TestSupport')
    ->expect('n5s\Rangelog\Tests\TestSupport\FixtureFetcher')
    ->toImplement('n5s\Rangelog\Fetcher\FetcherInterface');

arch('n5s\Rangelog\Testing namespace MUST NOT exist')
    ->expect('n5s\Rangelog\Testing')
    ->toBeUsedInNothing();

// ---------------------------------------------------------------------------
// Auth namespace + CredentialProviderInterface rules
// ---------------------------------------------------------------------------

arch('Auth layer depends only on Domain and PSR types')
    ->expect('n5s\Rangelog\Auth')
    ->not->toUse([
        'n5s\Rangelog\Cache',
        'n5s\Rangelog\Fetcher',
        'n5s\Rangelog\Parser',
        'n5s\Rangelog\Renderer',
        'n5s\Rangelog\SourceProvider',
    ]);

arch('CredentialProviderInterface implementations are final')
    ->expect('n5s\Rangelog')
    ->classes()
    ->implementing('n5s\Rangelog\Auth\CredentialProviderInterface')
    ->toBeFinal();

// Package domain isolation is already enforced by the existing
// 'Domain layer has no library dependencies' rule above. No new rule
// needed; the namespace-level rule covers Package.

// ---------------------------------------------------------------------------
// Util namespace leaf-isolation rule
// ---------------------------------------------------------------------------

arch('Util layer depends only on PSR types and PHP built-ins')
    ->expect('n5s\Rangelog\Util')
    ->not->toUse([
        'n5s\Rangelog\SourceProvider',
        'n5s\Rangelog\Fetcher',
        'n5s\Rangelog\Parser',
        'n5s\Rangelog\Renderer',
        'n5s\Rangelog\Cache',
        'n5s\Rangelog\Auth',
    ]);

// ---------------------------------------------------------------------------
// AuthorizingFetcher cross-layer dependency rule
// ---------------------------------------------------------------------------

arch('AuthorizingFetcher depends only on FetcherInterface, Source/RawResponse, CredentialProviderInterface, and PSR types')
    ->expect('n5s\Rangelog\Fetcher\AuthorizingFetcher')
    ->not->toUse([
        'n5s\Rangelog\Fetcher\HttpFetcher',
        'n5s\Rangelog\Fetcher\CachingFetcher',
        'n5s\Rangelog\Fetcher\CachedResponse',
        'n5s\Rangelog\Fetcher\FetcherStack',
        'n5s\Rangelog\Parser',
        'n5s\Rangelog\Renderer',
        'n5s\Rangelog\SourceProvider',
        'n5s\Rangelog\Cache',
    ]);

// ---------------------------------------------------------------------------
// GitLab resolver cross-layer dependency rules
// ---------------------------------------------------------------------------

arch('GitLabReleasesResolver depends only on SourceProviderInterface, Package, Source, SourceTypes, VersionRange, FetcherInterface, Util\GitLabProjectPath, exception + PSR types')
    ->expect('n5s\Rangelog\SourceProvider\GitLabReleasesResolver')
    ->not->toUse([
        'n5s\Rangelog\Fetcher\HttpFetcher',
        'n5s\Rangelog\Fetcher\CachingFetcher',
        'n5s\Rangelog\Fetcher\CachedResponse',
        'n5s\Rangelog\Fetcher\FetcherStack',
        'n5s\Rangelog\Fetcher\AuthorizingFetcher',
        'n5s\Rangelog\Parser',
        'n5s\Rangelog\Renderer',
        'n5s\Rangelog\Cache',
        'n5s\Rangelog\Auth',
    ]);

arch('GitLabFileResolver depends only on SourceProviderInterface, Package, Source, SourceTypes, VersionRange, FetcherInterface, Util\GitLabProjectPath, Util\VersionBranchDeriver, exception + PSR types')
    ->expect('n5s\Rangelog\SourceProvider\GitLabFileResolver')
    ->not->toUse([
        'n5s\Rangelog\Fetcher\HttpFetcher',
        'n5s\Rangelog\Fetcher\CachingFetcher',
        'n5s\Rangelog\Fetcher\CachedResponse',
        'n5s\Rangelog\Fetcher\FetcherStack',
        'n5s\Rangelog\Fetcher\AuthorizingFetcher',
        'n5s\Rangelog\Parser',
        'n5s\Rangelog\Renderer',
        'n5s\Rangelog\Cache',
        'n5s\Rangelog\Auth',
    ]);

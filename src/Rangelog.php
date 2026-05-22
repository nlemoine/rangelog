<?php

declare(strict_types=1);

namespace n5s\Rangelog;

use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Parser\ParserRegistry;
use n5s\Rangelog\Renderer\RendererInterface;
use n5s\Rangelog\SourceProvider\IterativeSourceProviderInterface;
use n5s\Rangelog\SourceProvider\PartialResultDetector;
use n5s\Rangelog\SourceProvider\SourceProviderInterface;

/**
 * Public entry point for the n5s/rangelog library.
 *
 * Four-interface constructor: `SourceProviderInterface`, `FetcherInterface`,
 * `ParserRegistry`, `RendererInterface`. No logger parameter; the orchestrator
 * emits no PSR-3 events (layer-local logging is the only signal).
 *
 * `changelog(Package $package, string $from, string $to, ?VersionRange $rangeOptions = null): Changelog`
 * is the public entry point. Returns the typed `Changelog` value object.
 * Callers construct Package with (name, sourceUrl).
 *
 * Override semantics: when `$rangeOptions !== null`, it FULLY supersedes
 * `$from`/`$to`. When null, the client constructs `VersionRange::changes($from, $to)`
 * (exclusive-from / inclusive-to).
 *
 * Malformed package names propagate as `\InvalidArgumentException` from
 * `Package::__construct` — NOT wrapped as `UnsupportedPackageException`.
 * Caller-bug throwables stay native; only DOMAIN failures extend
 * `ChangelogException`.
 *
 * The pipeline runs unconditionally; no try/catch wrapping. Layer interfaces
 * already throw the appropriate `ChangelogException` subtype for every domain
 * failure mode.
 *
 * When the injected chain implements {@see IterativeSourceProviderInterface},
 * `changelog()` runs an outer loop over
 * {@see IterativeSourceProviderInterface::resolveAll()} and applies the
 * pipeline per yielded Source, returning the FIRST non-empty filtered
 * Changelog. BYO callers whose chain only implements
 * {@see SourceProviderInterface} receive unchanged single-resolve behaviour.
 *
 * `render(Changelog $changelog): string` is a one-line delegate — pure
 * pass-through to the injected renderer. Empty fallback + partial admonition
 * are renderer-side concerns.
 *
 * Every DOMAIN failure surfaced by `changelog()` extends `ChangelogException`.
 * Callers catch the base for a safety net or specific subtypes for targeted
 * recovery.
 *
 * Exception messages MAY carry package names and URLs; the orchestrator adds
 * NO enrichment. Existing marker exceptions already constrain message content
 * to non-secret bytes. The orchestrator does not log a layered audit trail —
 * callers wanting end-to-end traces wrap `changelog()` themselves.
 */
final readonly class Rangelog
{
    public function __construct(
        private SourceProviderInterface $chain,
        private FetcherInterface $fetcher,
        private ParserRegistry $parsers,
        private RendererInterface $renderer,
    ) {
    }

    public function changelog(
        Package $package,
        string $from,
        string $to,
        ?VersionRange $rangeOptions = null,
    ): Changelog {
        // Override semantics for $rangeOptions; default to changes() (exclusive-from / inclusive-to).
        $range = $rangeOptions ?? VersionRange::changes($from, $to);

        // Fork on iterative capability. BYO callers whose chain only implements
        // SourceProviderInterface get the unchanged single-resolve path.
        if ($this->chain instanceof IterativeSourceProviderInterface) {
            return $this->resolveIterative($this->chain, $package, $range);
        }

        // Non-iterative fallback.
        $source = $this->chain->resolve($package, $range);

        return $this->processSource($source, $range);
    }

    /**
     * Iterative resolution path: iterate resolveAll(), run the pipeline per yielded
     * Source, and return the FIRST non-empty filtered Changelog. When all sources
     * yield empty entries, returns the LAST processed Changelog so the caller can
     * introspect isPartial() / getPartialReason() rather than receiving a throw.
     */
    private function resolveIterative(IterativeSourceProviderInterface $chain, Package $pkg, VersionRange $range): Changelog
    {
        $lastResult = null;

        foreach ($chain->resolveAll($pkg, $range) as $source) {
            $result = $this->processSource($source, $range);
            $lastResult = $result;

            if (\count($result->entries) > 0) {
                return $result;
            }
            // Empty after filter — fall through to next yielded Source.
        }

        // All sources yielded empty entries: return the LAST processed Changelog so the caller
        // can introspect isPartial()/getPartialReason(). resolveAll() already threw
        // ChangelogNotFoundException internally if NO provider supported (zero yields).
        return $lastResult ?? throw new ChangelogNotFoundException(
            "No resolver yielded any source for package: {$pkg->name}",
        );
    }

    /**
     * Pipeline: fetch → parserFor → parse → markPartial → filter.
     * Used by both the iterative and non-iterative paths.
     */
    private function processSource(Source $source, VersionRange $range): Changelog
    {
        // fetch → parser lookup → parse.
        $raw = $this->fetcher->fetch($source);
        $parser = $this->parsers->parserFor($source->type);
        $changelog = $parser->parse($raw, $range);

        // Pure post-processor marks the result partial when the from version is missing.
        $changelog = PartialResultDetector::markPartialIfFromMissing($changelog, $range);

        // Authoritative range boundary applied LAST.
        return $changelog->filter($range);
    }

    public function render(Changelog $changelog): string
    {
        return $this->renderer->render($changelog);
    }
}

<?php

declare(strict_types=1);

namespace n5s\Rangelog\SourceProvider;

use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Exception\UnsupportedPackageException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Composite that orchestrates an ordered list of {@see SourceProviderInterface}
 * providers.
 *
 * Composite pattern — the chain itself implements `SourceProviderInterface`,
 * enabling chain-of-chains composition.
 *
 * The chain does NOT catch exceptions thrown by inner providers.
 * `FetchException` / `RateLimitedException` / `TypeError` / anything else
 * propagates verbatim to the chain's caller. Tests assert this.
 *
 * PSR-3 events use verbatim message templates — operators rely on these
 * for log searches.
 *
 * Also implements {@see IterativeSourceProviderInterface}, adding
 * {@see resolveAll()} which lazily yields one Source per supporting
 * provider in injection order. This lets {@see \n5s\Rangelog\Rangelog}
 * fall through to the next resolver when the current one produces an
 * empty Changelog after parse + filter. The existing {@see resolve()} and
 * {@see supports()} methods are UNCHANGED (pure additive extension — BC
 * guaranteed for all existing callers).
 */
final readonly class SourceProviderChain implements SourceProviderInterface, IterativeSourceProviderInterface
{
    private LoggerInterface $logger;

    /**
     * @param list<SourceProviderInterface> $providers Providers iterated in injection order; first-supports wins.
     */
    public function __construct(
        private array $providers,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(Package $package): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($package)) {
                return true;
            }
        }

        return false;
    }

    public function resolve(Package $package, VersionRange $range): Source
    {
        foreach ($this->providers as $provider) {
            $this->logger->debug('Checking {provider} for {package}', [
                'provider' => $provider::class,
                'package' => $package->name,
            ]);
            if ($provider->supports($package)) {
                $source = $provider->resolve($package, $range);
                $this->logger->info('Resolved {package} via {provider}', [
                    'provider' => $provider::class,
                    'package' => $package->name,
                    'source_type' => $source->type,
                ]);

                return $source;
            }
        }

        $this->logger->warning('No provider in chain supports {package}', [
            'package' => $package->name,
        ]);

        throw new ChangelogNotFoundException("No resolver found for package: {$package->name}");
    }

    /**
     * Lazily yields one Source per inner provider whose supports() returns true,
     * in injection order.
     *
     * Algorithm:
     *  - Iterate $providers; skip those where supports() returns false.
     *  - For each supporting provider, call resolve() (may throw — propagates
     *    verbatim; fallthrough is ONLY for empty results).
     *  - After all providers: throw ChangelogNotFoundException if none supported
     *    (same contract as resolve() exhaustion).
     *
     * Lazy-generator semantics: each yielded Source is consumed by the caller
     * before the next iteration begins. If the caller stops iterating early
     * (e.g. found non-empty entries), no further resolve() calls happen and no
     * throw occurs even if $supportingCount would have been 0.
     *
     * @return iterable<Source>
     *
     * @throws FetchException
     * @throws RateLimitedException
     * @throws UnsupportedPackageException
     * @throws ChangelogNotFoundException
     */
    public function resolveAll(Package $package, VersionRange $range): iterable
    {
        $supportingCount = 0;

        foreach ($this->providers as $provider) {
            $this->logger->debug('Checking {provider} for {package}', [
                'provider' => $provider::class,
                'package' => $package->name,
            ]);

            if (! $provider->supports($package)) {
                continue;
            }

            ++$supportingCount;

            // Exceptions propagate verbatim — NOT caught here.
            $source = $provider->resolve($package, $range);

            $this->logger->info('Resolved {package} via {provider}', [
                'provider' => $provider::class,
                'package' => $package->name,
                'source_type' => $source->type,
            ]);

            yield $source;
        }

        if ($supportingCount === 0) {
            $this->logger->warning('No provider in chain supports {package}', [
                'package' => $package->name,
            ]);

            throw new ChangelogNotFoundException("No resolver found for package: {$package->name}");
        }
    }
}

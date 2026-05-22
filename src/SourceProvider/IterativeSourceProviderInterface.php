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

/**
 * Opt-in extension contract for source-provider chains that can yield MULTIPLE
 * candidate sources for a single package (one per supporting inner resolver).
 *
 * When the current resolver resolves a source that parses + filters into zero
 * entries, the orchestrator ({@see \n5s\Rangelog\Rangelog}) falls through to
 * the NEXT source yielded by {@see resolveAll} rather than returning the
 * empty result.
 *
 * BC contract:
 *  - {@see SourceProviderInterface} is UNCHANGED — BYO implementers that do not
 *    implement this interface continue to receive single-resolve behaviour.
 *  - Only {@see SourceProviderChain} implements this interface in v1. Chain-of-
 *    chains composition continues to work transparently.
 *
 * Exception invariant: {@see resolveAll} does NOT catch exceptions thrown by
 * inner {@see SourceProviderInterface::resolve} calls.
 * {@see \n5s\Rangelog\Exception\FetchException}, {@see \n5s\Rangelog\Exception\RateLimitedException},
 * etc. propagate verbatim to the caller. Fallthrough is ONLY triggered by an
 * empty-entries result, NEVER by an exception.
 */
interface IterativeSourceProviderInterface extends SourceProviderInterface
{
    /**
     * Lazily yields one {@see Source} per inner provider whose {@see SourceProviderInterface::supports}
     * returns true, in injection order.
     *
     * Implementations MUST:
     *  - Skip providers whose {@see SourceProviderInterface::supports} returns false (without calling resolve()).
     *  - Let exceptions from {@see SourceProviderInterface::resolve} propagate verbatim.
     *  - Throw {@see \n5s\Rangelog\Exception\ChangelogNotFoundException} when NO provider supports the
     *    package (same contract as {@see SourceProviderInterface::resolve} exhaustion).
     *
     * @return iterable<Source>
     *
     * @throws FetchException
     * @throws RateLimitedException
     * @throws UnsupportedPackageException
     * @throws ChangelogNotFoundException
     */
    public function resolveAll(Package $package, VersionRange $range): iterable;
}

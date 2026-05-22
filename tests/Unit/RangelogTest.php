<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\Changelog;
use n5s\Rangelog\Domain\ChangelogEntry;
use n5s\Rangelog\Domain\Package;
use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Domain\SourceTypes;
use n5s\Rangelog\Domain\VersionRange;
use n5s\Rangelog\Exception\ChangelogException;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\ParseException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Exception\UnsupportedPackageException;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Parser\ChangelogParserInterface;
use n5s\Rangelog\Parser\ParserRegistry;
use n5s\Rangelog\Rangelog;
use n5s\Rangelog\Renderer\RendererInterface;
use n5s\Rangelog\SourceProvider\SourceProviderInterface;
use Psr\Log\LoggerInterface;

// ---------------------------------------------------------------------------
// Helper functions — top-level anonymous-class stubs.
// All four collaborators implement the right interface; orchestration order
// is recorded via a shared `CallOrderRecorder` object (object identity is the
// simplest PHPStan-friendly path; ref-passed arrays trip
// `parameterByRef.type` at PHPStan level max).
// Helper names are prefixed `client` to avoid global collisions with the
// other unit test files that already declare `stubFetcher` / `stubParser`.
// ---------------------------------------------------------------------------

/**
 * Small object passed by reference to anonymous-class stubs so they can
 * record their call order. Object identity guarantees all stubs share the
 * same recorder without by-ref array gymnastics that PHPStan max cannot
 * follow.
 */
final class CallOrderRecorder
{
    /** @var list<string> */
    public array $order = [];

    public ?Package $capturedPackage = null;

    public ?VersionRange $capturedRange = null;
}

/**
 * @param callable(Package, VersionRange): Source $onResolve
 */
function clientStubChain(callable $onResolve, CallOrderRecorder $recorder): SourceProviderInterface
{
    return new class ($onResolve, $recorder) implements SourceProviderInterface {
        /** @var callable(Package, VersionRange): Source */
        private $onResolve;

        /**
         * @param callable(Package, VersionRange): Source $onResolve
         */
        public function __construct(callable $onResolve, private readonly CallOrderRecorder $recorder)
        {
            $this->onResolve = $onResolve;
        }

        public function supports(Package $package): bool
        {
            return true;
        }

        public function resolve(Package $package, VersionRange $range): Source
        {
            $this->recorder->order[] = 'chain.resolve';
            $this->recorder->capturedPackage = $package;
            $this->recorder->capturedRange = $range;

            return ($this->onResolve)($package, $range);
        }
    };
}

function clientStubFetcher(RawResponse|Throwable $responseOrThrowable, CallOrderRecorder $recorder): FetcherInterface
{
    return new readonly class ($responseOrThrowable, $recorder) implements FetcherInterface {
        public function __construct(
            private RawResponse|Throwable $responseOrThrowable,
            private CallOrderRecorder $recorder,
        ) {
        }

        public function fetch(Source $source): RawResponse
        {
            $this->recorder->order[] = 'fetcher.fetch';

            if ($this->responseOrThrowable instanceof Throwable) {
                throw $this->responseOrThrowable;
            }

            return $this->responseOrThrowable;
        }
    };
}

function clientStubParser(Changelog|Throwable $changelogToReturn, CallOrderRecorder $recorder): ChangelogParserInterface
{
    return new readonly class ($changelogToReturn, $recorder) implements ChangelogParserInterface {
        public function __construct(
            private Changelog|Throwable $changelogToReturn,
            private CallOrderRecorder $recorder,
        ) {
        }

        public function parse(RawResponse $response, VersionRange $range): Changelog
        {
            $this->recorder->order[] = 'parser.parse';

            if ($this->changelogToReturn instanceof Throwable) {
                throw $this->changelogToReturn;
            }

            return $this->changelogToReturn;
        }
    };
}

/**
 * Wrap the inner parser with a recording wrapper that emits a synthetic
 * 'parsers.parserFor' record into the recorder just before delegating —
 * logically the orchestrator looked up THIS parser before calling parse().
 * This sequencing is faithful to the orchestration order; pest-plugin-arch
 * doesn't have a hook on ParserRegistry::parserFor itself (final class).
 */
function clientStubParserRegistry(ChangelogParserInterface $parser, CallOrderRecorder $recorder): ParserRegistry
{
    $recordingParser = new readonly class ($parser, $recorder) implements ChangelogParserInterface {
        public function __construct(
            private ChangelogParserInterface $inner,
            private CallOrderRecorder $recorder,
        ) {
        }

        public function parse(RawResponse $response, VersionRange $range): Changelog
        {
            $this->recorder->order[] = 'parsers.parserFor';

            return $this->inner->parse($response, $range);
        }
    };

    return new ParserRegistry([
        SourceTypes::GITHUB_RELEASES  => $recordingParser,
        SourceTypes::GITHUB_FILE      => $recordingParser,
        SourceTypes::MARKDOWN_URL     => $recordingParser,
        SourceTypes::WORDPRESS_ORG    => $recordingParser,
    ]);
}

/**
 * Stub renderer with a public `$received` capture slot. The slot is the
 * Changelog instance passed to render(); test code grabs it via reflection
 * on the returned renderer instance.
 */
final class CapturingRenderer implements RendererInterface
{
    public ?Changelog $received = null;

    public int $callCount = 0;

    public function __construct(public readonly string $outputString)
    {
    }

    public function render(Changelog $changelog): string
    {
        ++$this->callCount;
        $this->received = $changelog;

        return $this->outputString;
    }
}

function clientStubRenderer(string $outputString): CapturingRenderer
{
    return new CapturingRenderer($outputString);
}

function clientDefaultSource(): Source
{
    return new Source(type: SourceTypes::GITHUB_FILE, url: 'https://example.com/CHANGELOG.md');
}

function clientDefaultRawResponse(): RawResponse
{
    return new RawResponse(
        body: '# Changelog',
        contentType: 'text/markdown',
        source: clientDefaultSource(),
    );
}

function clientDefaultChangelog(): Changelog
{
    return new Changelog([
        new ChangelogEntry(
            version: '1.5.0',
            date: null,
            sections: [],
            raw: '- entry',
        ),
    ]);
}

// ---------------------------------------------------------------------------
// Final class, exact constructor shape, no logger
// ---------------------------------------------------------------------------

it('is a final class', function (): void {
    $reflection = new ReflectionClass(Rangelog::class);

    expect($reflection->isFinal())->toBeTrue();
});

it('has EXACTLY 4 constructor parameters', function (): void {
    $ctor = new ReflectionMethod(Rangelog::class, '__construct');

    expect($ctor->getNumberOfParameters())->toBe(4);
});

it('does NOT include a LoggerInterface parameter in the constructor (no orchestrator logging)', function (): void {
    $ctor = new ReflectionMethod(Rangelog::class, '__construct');

    foreach ($ctor->getParameters() as $param) {
        $type = $param->getType();
        if ($type instanceof ReflectionNamedType) {
            expect($type->getName())->not->toBe(LoggerInterface::class);
        }
    }
});

it('declares the 4 constructor parameters as SourceProviderInterface, FetcherInterface, ParserRegistry, RendererInterface IN ORDER', function (): void {
    $ctor = new ReflectionMethod(Rangelog::class, '__construct');
    $params = $ctor->getParameters();

    expect($params[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $t0 */
    $t0 = $params[0]->getType();
    expect($t0->getName())->toBe(SourceProviderInterface::class);

    expect($params[1]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $t1 */
    $t1 = $params[1]->getType();
    expect($t1->getName())->toBe(FetcherInterface::class);

    expect($params[2]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $t2 */
    $t2 = $params[2]->getType();
    expect($t2->getName())->toBe(ParserRegistry::class);

    expect($params[3]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $t3 */
    $t3 = $params[3]->getType();
    expect($t3->getName())->toBe(RendererInterface::class);
});

// ---------------------------------------------------------------------------
// changelog() accepts Package only (string overload removed)
// ---------------------------------------------------------------------------

it('changelog() first parameter type is Package only — no string union', function (): void {
    $method = new ReflectionMethod(Rangelog::class, 'changelog');
    $params = $method->getParameters();

    $type = $params[0]->getType();
    expect($type)->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $type */
    expect($type->getName())->toBe(Package::class);
});

// ---------------------------------------------------------------------------
// rangeOptions Override semantics
// ---------------------------------------------------------------------------

it('falls back to VersionRange::changes($from, $to) when rangeOptions is null (null case)', function (): void {
    $recorder = new CallOrderRecorder();
    $chain = clientStubChain(
        static fn (): Source => clientDefaultSource(),
        $recorder,
    );
    $fetcher = clientStubFetcher(clientDefaultRawResponse(), $recorder);
    $parser = clientStubParser(clientDefaultChangelog(), $recorder);
    $parsers = clientStubParserRegistry($parser, $recorder);
    $renderer = clientStubRenderer('STUB');

    $client = new Rangelog($chain, $fetcher, $parsers, $renderer);
    $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '1.0.0', '2.0.0');

    $captured = $recorder->capturedRange;
    expect($captured)->toBeInstanceOf(VersionRange::class);
    /** @var VersionRange $captured */
    expect($captured->from)->toBe('1.0.0');
    expect($captured->to)->toBe('2.0.0');
    expect($captured->includeFrom)->toBeFalse();
    expect($captured->includeTo)->toBeTrue();
});

it('honors the rangeOptions Override semantics when non-null (non-null case)', function (): void {
    $recorder = new CallOrderRecorder();
    $chain = clientStubChain(
        static fn (): Source => clientDefaultSource(),
        $recorder,
    );
    $fetcher = clientStubFetcher(clientDefaultRawResponse(), $recorder);
    $parser = clientStubParser(clientDefaultChangelog(), $recorder);
    $parsers = clientStubParserRegistry($parser, $recorder);
    $renderer = clientStubRenderer('STUB');

    $passedRange = VersionRange::inclusive('1.5', '2.5');

    $client = new Rangelog($chain, $fetcher, $parsers, $renderer);
    $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '', '', $passedRange);

    expect($recorder->capturedRange)->toBe($passedRange);
});

// ---------------------------------------------------------------------------
// Raw \InvalidArgumentException propagation from Package::__construct
// ---------------------------------------------------------------------------

it('propagates raw \\InvalidArgumentException from Package::__construct (NOT wrapped as UnsupportedPackageException)', function (): void {
    // Package::__construct throws \InvalidArgumentException for an invalid sourceUrl.
    // Since Rangelog now accepts Package directly, the caller constructs the Package —
    // so the \InvalidArgumentException propagates from the Package constructor, not Rangelog.
    $caught = null;
    try {
        // 'not-a-url' is not a valid http(s):// URL — Package::__construct throws.
        new Package('vendor/name', 'not-a-url');
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(InvalidArgumentException::class);
    expect($caught)->not->toBeInstanceOf(ChangelogException::class);
});

// ---------------------------------------------------------------------------
// Orchestration order (chain → fetcher → parserFor → parse)
// + step 7 (PartialResultDetector) + step 8 (filter)
// ---------------------------------------------------------------------------

it('orchestrates collaborators in order: chain → fetcher → parsers → parser', function (): void {
    $recorder = new CallOrderRecorder();
    $chain = clientStubChain(
        static fn (): Source => clientDefaultSource(),
        $recorder,
    );
    $fetcher = clientStubFetcher(clientDefaultRawResponse(), $recorder);
    $parser = clientStubParser(clientDefaultChangelog(), $recorder);
    $parsers = clientStubParserRegistry($parser, $recorder);
    $renderer = clientStubRenderer('STUB');

    $client = new Rangelog($chain, $fetcher, $parsers, $renderer);
    $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '1.0.0', '2.0.0');

    expect($recorder->order)->toBe(['chain.resolve', 'fetcher.fetch', 'parsers.parserFor', 'parser.parse']);
});

it('runs PartialResultDetector after parse so isPartial flips when from is absent', function (): void {
    $recorder = new CallOrderRecorder();
    // Parsed entries do NOT contain the 'from' version 1.0.0 — only 1.5.0.
    $parsedChangelog = new Changelog([
        new ChangelogEntry(
            version: '1.5.0',
            date: null,
            sections: [],
            raw: '- entry',
        ),
    ]);

    $chain = clientStubChain(
        static fn (): Source => clientDefaultSource(),
        $recorder,
    );
    $fetcher = clientStubFetcher(clientDefaultRawResponse(), $recorder);
    $parser = clientStubParser($parsedChangelog, $recorder);
    $parsers = clientStubParserRegistry($parser, $recorder);
    $renderer = clientStubRenderer('STUB');

    $client = new Rangelog($chain, $fetcher, $parsers, $renderer);
    $result = $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '1.0.0', '2.0.0');

    expect($result->isPartial())->toBeTrue();
    expect($result->getPartialReason())->not->toBeNull();
});

it('applies Changelog::filter after PartialResultDetector so entries respect the range', function (): void {
    $recorder = new CallOrderRecorder();
    // Parsed entries include versions inside AND outside the range
    // (1.0.0, 2.0.0]: 0.9.0 (outside), 1.5.0 (inside), 2.5.0 (outside), 3.0.0 (outside).
    $parsedChangelog = new Changelog([
        new ChangelogEntry(version: '0.9.0', date: null, sections: [], raw: '0.9.0'),
        new ChangelogEntry(version: '1.5.0', date: null, sections: [], raw: '1.5.0'),
        new ChangelogEntry(version: '2.5.0', date: null, sections: [], raw: '2.5.0'),
        new ChangelogEntry(version: '3.0.0', date: null, sections: [], raw: '3.0.0'),
    ]);

    $chain = clientStubChain(
        static fn (): Source => clientDefaultSource(),
        $recorder,
    );
    $fetcher = clientStubFetcher(clientDefaultRawResponse(), $recorder);
    $parser = clientStubParser($parsedChangelog, $recorder);
    $parsers = clientStubParserRegistry($parser, $recorder);
    $renderer = clientStubRenderer('STUB');

    $client = new Rangelog($chain, $fetcher, $parsers, $renderer);
    $result = $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '1.0.0', '2.0.0');

    $versions = array_map(static fn (ChangelogEntry $e): string => $e->version, $result->entries);
    expect($versions)->toBe(['1.5.0']);
});

// ---------------------------------------------------------------------------
// Every domain failure mode extends ChangelogException
// ---------------------------------------------------------------------------

it('propagates ChangelogNotFoundException from chain — catching ChangelogException works', function (): void {
    $recorder = new CallOrderRecorder();
    $chain = new class () implements SourceProviderInterface {
        public function supports(Package $package): bool
        {
            return true;
        }

        public function resolve(Package $package, VersionRange $range): Source
        {
            throw new ChangelogNotFoundException('not found');
        }
    };
    $fetcher = clientStubFetcher(clientDefaultRawResponse(), $recorder);
    $parser = clientStubParser(clientDefaultChangelog(), $recorder);
    $parsers = clientStubParserRegistry($parser, $recorder);
    $renderer = clientStubRenderer('STUB');

    $client = new Rangelog($chain, $fetcher, $parsers, $renderer);

    $caught = null;
    try {
        $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '1.0.0', '2.0.0');
    } catch (ChangelogException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ChangelogNotFoundException::class);
});

it('propagates UnsupportedPackageException from chain — catching ChangelogException works', function (): void {
    $recorder = new CallOrderRecorder();
    $chain = new class () implements SourceProviderInterface {
        public function supports(Package $package): bool
        {
            return true;
        }

        public function resolve(Package $package, VersionRange $range): Source
        {
            throw new UnsupportedPackageException('unsupported');
        }
    };
    $fetcher = clientStubFetcher(clientDefaultRawResponse(), $recorder);
    $parser = clientStubParser(clientDefaultChangelog(), $recorder);
    $parsers = clientStubParserRegistry($parser, $recorder);
    $renderer = clientStubRenderer('STUB');

    $client = new Rangelog($chain, $fetcher, $parsers, $renderer);

    $caught = null;
    try {
        $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '1.0.0', '2.0.0');
    } catch (ChangelogException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(UnsupportedPackageException::class);
});

it('propagates FetchException from fetcher — catching ChangelogException works', function (): void {
    $recorder = new CallOrderRecorder();
    $chain = clientStubChain(
        static fn (): Source => clientDefaultSource(),
        $recorder,
    );
    $fetcher = clientStubFetcher(
        new FetchException(message: 'boom', statusCode: 500),
        $recorder,
    );
    $parser = clientStubParser(clientDefaultChangelog(), $recorder);
    $parsers = clientStubParserRegistry($parser, $recorder);
    $renderer = clientStubRenderer('STUB');

    $client = new Rangelog($chain, $fetcher, $parsers, $renderer);

    $caught = null;
    try {
        $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '1.0.0', '2.0.0');
    } catch (ChangelogException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(FetchException::class);
});

it('propagates RateLimitedException from fetcher — catching ChangelogException works', function (): void {
    $recorder = new CallOrderRecorder();
    $chain = clientStubChain(
        static fn (): Source => clientDefaultSource(),
        $recorder,
    );
    $fetcher = clientStubFetcher(
        new RateLimitedException(message: 'rate limited', retryAfter: 60),
        $recorder,
    );
    $parser = clientStubParser(clientDefaultChangelog(), $recorder);
    $parsers = clientStubParserRegistry($parser, $recorder);
    $renderer = clientStubRenderer('STUB');

    $client = new Rangelog($chain, $fetcher, $parsers, $renderer);

    $caught = null;
    try {
        $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '1.0.0', '2.0.0');
    } catch (ChangelogException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitedException::class);
});

it('propagates ParseException from parser — catching ChangelogException works', function (): void {
    $recorder = new CallOrderRecorder();
    $chain = clientStubChain(
        static fn (): Source => clientDefaultSource(),
        $recorder,
    );
    $fetcher = clientStubFetcher(clientDefaultRawResponse(), $recorder);
    $parser = clientStubParser(new ParseException('parse failed'), $recorder);
    $parsers = clientStubParserRegistry($parser, $recorder);
    $renderer = clientStubRenderer('STUB');

    $client = new Rangelog($chain, $fetcher, $parsers, $renderer);

    $caught = null;
    try {
        $client->changelog(new Package('symfony/console', 'https://github.com/symfony/console'), '1.0.0', '2.0.0');
    } catch (ChangelogException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ParseException::class);
});

// ---------------------------------------------------------------------------
// render() one-line delegate
// ---------------------------------------------------------------------------

it('render() delegates one-line to the injected renderer', function (): void {
    $recorder = new CallOrderRecorder();
    $chain = clientStubChain(
        static fn (): Source => clientDefaultSource(),
        $recorder,
    );
    $fetcher = clientStubFetcher(clientDefaultRawResponse(), $recorder);
    $parser = clientStubParser(clientDefaultChangelog(), $recorder);
    $parsers = clientStubParserRegistry($parser, $recorder);
    $renderer = clientStubRenderer('STUB OUTPUT');

    $client = new Rangelog($chain, $fetcher, $parsers, $renderer);

    $changelog = new Changelog([]);
    $output = $client->render($changelog);

    expect($output)->toBe('STUB OUTPUT');
    expect($renderer->received)->toBe($changelog);
});

<?php

declare(strict_types=1);

namespace n5s\Rangelog\Tests\Unit\Fetcher;

use InvalidArgumentException;
use n5s\Rangelog\Auth\CredentialProviderInterface;
use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Fetcher\AuthorizingFetcher;
use n5s\Rangelog\Fetcher\FetcherInterface;
use n5s\Rangelog\Tests\TestSupport\ArrayLogger;
use n5s\Rangelog\Tests\TestSupport\RecordingFetcher;
use ReflectionClass;
use ReflectionNamedType;

// ---------------------------------------------------------------------------
// Structure tests
// ---------------------------------------------------------------------------

it('is a final class implementing FetcherInterface', function (): void {
    $reflection = new ReflectionClass(AuthorizingFetcher::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(FetcherInterface::class))->toBeTrue();
});

it('requires CredentialProviderInterface in the constructor (no default)', function (): void {
    $reflection = new ReflectionClass(AuthorizingFetcher::class);

    $constructor = $reflection->getConstructor();
    expect($constructor)->not->toBeNull();

    if ($constructor === null) {
        return;
    }

    $params = $constructor->getParameters();

    // Second parameter (index 1) is $credentials
    expect(isset($params[1]))->toBeTrue();

    if (! isset($params[1])) {
        return;
    }

    $credParam = $params[1];
    $type = $credParam->getType();

    expect($type)->not->toBeNull();
    expect($credParam->isDefaultValueAvailable())->toBeFalse();

    if (! $type instanceof ReflectionNamedType) {
        return;
    }

    expect($type->getName())->toBe(CredentialProviderInterface::class);
});

// ---------------------------------------------------------------------------
// Core behaviour tests
// ---------------------------------------------------------------------------

it('does NOT write metadata[_auth_headers] when authorize() returns []', function (): void {
    $recordingInner = new RecordingFetcher();
    $credentials = new class () implements CredentialProviderInterface {
        public function authorize(string $url): array
        {
            return [];
        }
    };

    $fetcher = new AuthorizingFetcher($recordingInner, $credentials);
    $source = new Source(type: 'github_releases', url: 'https://api.github.com/repos/x/y/releases', metadata: ['existing_key' => 'existing_value']);

    $fetcher->fetch($source);

    expect($recordingInner->lastSource)->not->toBeNull();

    /** @var Source $received */
    $received = $recordingInner->lastSource;

    expect(\array_key_exists(AuthorizingFetcher::AUTH_HEADERS_METADATA_KEY, $received->metadata))->toBeFalse();
    // Original metadata preserved bit-for-bit.
    expect($received->metadata['existing_key'] ?? null)->toBe('existing_value');
});

it('stamps auth headers into metadata[_auth_headers] when authorize() returns non-empty', function (): void {
    $recordingInner = new RecordingFetcher();
    $credentials = new class () implements CredentialProviderInterface {
        public function authorize(string $url): array
        {
            return ['Authorization' => 'Bearer t', 'X-Custom' => 'v'];
        }
    };

    $fetcher = new AuthorizingFetcher($recordingInner, $credentials);
    $source = new Source(type: 'github_releases', url: 'https://api.github.com/repos/x/y/releases', metadata: ['prior_key' => 'prior_value']);

    $fetcher->fetch($source);

    expect($recordingInner->lastSource)->not->toBeNull();

    /** @var Source $received */
    $received = $recordingInner->lastSource;

    expect($received->metadata[AuthorizingFetcher::AUTH_HEADERS_METADATA_KEY])->toBe(['Authorization' => 'Bearer t', 'X-Custom' => 'v']);
    // Prior metadata keys still present.
    expect($received->metadata['prior_key'] ?? null)->toBe('prior_value');
});

it('throws InvalidArgumentException when header NAME contains CR/LF and message does NOT echo the name', function (): void {
    $recordingInner = new RecordingFetcher();
    $credentials = new class () implements CredentialProviderInterface {
        public function authorize(string $url): array
        {
            return ["Bad\rName" => 'value'];
        }
    };

    $fetcher = new AuthorizingFetcher($recordingInner, $credentials);
    $source = new Source(type: 'github_releases', url: 'https://api.github.com/repos/x/y/releases');

    $thrown = null;
    try {
        $fetcher->fetch($source);
    } catch (InvalidArgumentException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(InvalidArgumentException::class);

    if (! $thrown instanceof InvalidArgumentException) {
        return;
    }

    expect($thrown->getMessage())->not->toContain('Bad');
    expect($thrown->getMessage())->not->toContain('Name');
});

it('throws InvalidArgumentException when header VALUE contains CR/LF and message does NOT echo the value', function (): void {
    $recordingInner = new RecordingFetcher();
    $credentials = new class () implements CredentialProviderInterface {
        public function authorize(string $url): array
        {
            return ['Authorization' => "Bearer foo\r\nX-Internal: yes"];
        }
    };

    $fetcher = new AuthorizingFetcher($recordingInner, $credentials);
    $source = new Source(type: 'github_releases', url: 'https://api.github.com/repos/x/y/releases');

    $thrown = null;
    try {
        $fetcher->fetch($source);
    } catch (InvalidArgumentException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(InvalidArgumentException::class);

    if (! $thrown instanceof InvalidArgumentException) {
        return;
    }

    expect($thrown->getMessage())->not->toContain('Bearer');
    expect($thrown->getMessage())->not->toContain('X-Internal');
});

it('throws InvalidArgumentException when header NAME contains non-RFC-7230 tchar', function (): void {
    $recordingInner = new RecordingFetcher();
    $credentials = new class () implements CredentialProviderInterface {
        public function authorize(string $url): array
        {
            return ['Bad Header' => 'value']; // space is not in tchar
        }
    };

    $fetcher = new AuthorizingFetcher($recordingInner, $credentials);
    $source = new Source(type: 'github_releases', url: 'https://api.github.com/repos/x/y/releases');

    $thrown = null;
    try {
        $fetcher->fetch($source);
    } catch (InvalidArgumentException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(InvalidArgumentException::class);

    if (! $thrown instanceof InvalidArgumentException) {
        return;
    }

    expect($thrown->getMessage())->not->toContain('Bad');
    expect($thrown->getMessage())->not->toContain('Header');

    // Positive control: a fully tchar-legal name does NOT throw.
    $safeInner = new RecordingFetcher();
    $safeCredentials = new class () implements CredentialProviderInterface {
        public function authorize(string $url): array
        {
            return ['X-Custom-Auth-v2' => 'value'];
        }
    };
    $safeFetcher = new AuthorizingFetcher($safeInner, $safeCredentials);

    expect(fn (): RawResponse => $safeFetcher->fetch($source))->not->toThrow(InvalidArgumentException::class);
});

it('preserves $source->prefetchedBody through the stamp', function (): void {
    $recordingInner = new RecordingFetcher();
    $credentials = new class () implements CredentialProviderInterface {
        public function authorize(string $url): array
        {
            return ['Authorization' => 'Bearer t'];
        }
    };

    $fetcher = new AuthorizingFetcher($recordingInner, $credentials);
    $source = new Source(
        type: 'github_releases',
        url: 'https://api.github.com/repos/x/y/releases',
        metadata: [],
        prefetchedBody: 'PREFETCHED',
    );

    $fetcher->fetch($source);

    expect($recordingInner->lastSource?->prefetchedBody)->toBe('PREFETCHED');
});

it('emits exactly one debug log per call shaped as {url, count} with no header names/values', function (): void {
    $recordingInner = new RecordingFetcher();
    $logger = new ArrayLogger();
    $credentials = new class () implements CredentialProviderInterface {
        public function authorize(string $url): array
        {
            return ['Authorization' => 'Bearer t', 'X-Custom' => 'v'];
        }
    };

    $fetcher = new AuthorizingFetcher($recordingInner, $credentials, $logger);
    $source = new Source(type: 'github_releases', url: 'https://api.github.com/repos/x/y/releases');

    $fetcher->fetch($source);

    $authRecords = array_values(array_filter(
        $logger->records,
        static fn (array $r): bool => $r['level'] === 'debug' && str_contains($r['message'], 'Auth applied'),
    ));

    expect($authRecords)->toHaveCount(1);

    $record = $authRecords[0];

    expect($record['context'])->toHaveKey('url');
    expect($record['context'])->toHaveKey('count');
    expect($record['context']['count'])->toBe(2);

    // No header names or values in the context — credential leak guard.
    expect($record['context'])->not->toHaveKey('headers');
    expect($record['context'])->not->toHaveKey('name');
    expect($record['context'])->not->toHaveKey('value');
    expect($record['context'])->not->toHaveKey('authorization');

    // Message itself must not leak token values.
    expect($record['message'])->not->toContain('Bearer');
    expect($record['message'])->not->toContain('Authorization');
});

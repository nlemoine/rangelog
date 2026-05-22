<?php

declare(strict_types=1);

namespace n5s\Rangelog\Fetcher;

use DateTimeImmutable;
use DateTimeInterface;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\RateLimitedException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PSR-18 + PSR-17 wrapper that implements FetcherInterface.
 *
 * The single I/O boundary in the library — this is where
 * ClientInterface::sendRequest() is called. Resolvers and parsers
 * never touch the network directly; they go through a FetcherInterface
 * composition (typically HttpFetcher → CachingFetcher via FetcherStack).
 *
 * Constructor signature: positional order (httpClient, requestFactory,
 * userAgent, logger). $httpClient and $requestFactory are OPTIONAL —
 * pass null (or omit) to opt into the php-http/discovery fallback which
 * resolves a PSR-18 client and PSR-17 request factory from the installed
 * implementations at construction time. BYO injection still works
 * identically: pass explicit instances and the discovery code path is
 * skipped. Logger is also OPTIONAL and last; defaults to NullLogger via
 * the constructor body (PHP does not allow ?? defaults inline in
 * promoted-property syntax — same reason $httpClient/$requestFactory
 * are declared as non-promoted properties so the constructor body can
 * compute the resolved value before assignment).
 *
 * Status-code mapping:
 *   - 2xx → RawResponse with body buffered to string immediately;
 *     response ETag captured into RawResponse->source->metadata
 *     '_response_etag' for CachingFetcher round-trip.
 *   - 304 → FetchException(statusCode=304). Only meaningful inside
 *     CachingFetcher after If-None-Match was sent; outside that
 *     context, it IS an error (server signaling stale validation).
 *   - 429 → RateLimitedException(retryAfter, rateLimitReset) with
 *     headers parsed via parseRateLimitHeaders().
 *   - other non-2xx → FetchException(statusCode, bodyExcerpt) with
 *     bodyExcerpt limited to 1024 UTF-8-safe bytes via mb_strcut.
 *   - PSR-18 ClientException → FetchException(statusCode=0, previous=$e).
 *
 * Conditional GET: if Source->metadata carries '_if_none_match' as a
 * non-empty string, the request gains an If-None-Match header.
 * CachingFetcher populates this; HttpFetcher merely honors it.
 * Underscore prefix marks the metadata key as library-internal.
 *
 * Logging: four events at locked levels (debug pre-send, debug
 * post-send-success, notice on 429, error on PSR-18 exception).
 * Context arrays carry only url/type/status/bytes/ms/retryAfter/
 * rateLimitReset/message/class — NEVER request or response bodies,
 * NEVER header values.
 *
 * Headers baked into outbound requests: User-Agent ONLY.
 * No Accept-Encoding (let the PSR-18 client negotiate compression),
 * no Accept (resolvers set Accept on Source if they need it),
 * no Authorization (caller's PSR-18 middleware concern).
 *
 * Body read-once enforcement: single getContents() call into a string
 * variable on the response body, before any branching on status code.
 */
final readonly class HttpFetcher implements FetcherInterface
{
    private const string IF_NONE_MATCH_METADATA_KEY = '_if_none_match';
    private const string RESPONSE_ETAG_METADATA_KEY = '_response_etag';
    private const int BODY_EXCERPT_BYTE_LIMIT = 1024;

    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private LoggerInterface $logger;

    public function __construct(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        private string $userAgent = 'n5s/rangelog/0.x (+https://github.com/n5s/rangelog)',
        ?LoggerInterface $logger = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->logger = $logger ?? new NullLogger();
    }

    public function fetch(Source $source): RawResponse
    {
        // Short-circuit when body was pre-fetched by resolver (e.g., GitHubReleasesResolver pagination)
        if ($source->prefetchedBody !== null) {
            return new RawResponse($source->prefetchedBody, 'application/json', $source);
        }

        $request = $this->requestFactory
            ->createRequest('GET', $source->url)
            ->withHeader('User-Agent', $this->userAgent);

        $ifNoneMatch = $source->metadata[self::IF_NONE_MATCH_METADATA_KEY] ?? null;
        if (\is_string($ifNoneMatch) && $ifNoneMatch !== '') {
            $request = $request->withHeader('If-None-Match', $ifNoneMatch);
        }

        // Apply caller-supplied auth headers LAST so they replace
        // any library-set defaults (User-Agent, If-None-Match) on collision.
        $authHeaders = $source->metadata[AuthorizingFetcher::AUTH_HEADERS_METADATA_KEY] ?? [];
        if (\is_array($authHeaders)) {
            foreach ($authHeaders as $name => $value) {
                if (\is_string($name) && \is_string($value)) {
                    $request = $request->withHeader($name, $value);
                }
            }
        }

        $this->logger->debug('GET {url}', [
            'url' => $source->url,
            'type' => $source->type,
        ]);
        $startMs = (int) (microtime(true) * 1000);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('PSR-18 client error for {url}: {message}', [
                'url' => $source->url,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            throw new FetchException(
                message: 'PSR-18 client error: ' . $e->getMessage(),
                code: 0,
                previous: $e,
                statusCode: 0,
                bodyExcerpt: '',
            );
        }

        $status = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $contentType = $response->getHeaderLine('Content-Type');
        $elapsedMs = ((int) (microtime(true) * 1000)) - $startMs;

        if ($status >= 200 && $status < 300) {
            $this->logger->debug('{status} {url} ({bytes} bytes, {ms}ms)', [
                'status' => $status,
                'url' => $source->url,
                'bytes' => \strlen($body),
                'ms' => $elapsedMs,
            ]);

            $etag = $response->getHeaderLine('ETag');
            $sourceWithEtag = new Source(
                type: $source->type,
                url: $source->url,
                metadata: [
                    ...$source->metadata,
                    self::RESPONSE_ETAG_METADATA_KEY => $etag !== '' ? $etag : null,
                ],
            );

            return new RawResponse($body, $contentType, $sourceWithEtag);
        }

        if ($status === 429) {
            [$retryAfter, $rateLimitReset] = $this->parseRateLimitHeaders($response);
            $this->logger->notice('Rate limited for {url}, retry after {seconds}s', [
                'url' => $source->url,
                'retryAfter' => $retryAfter,
                'rateLimitReset' => $rateLimitReset?->format(DateTimeInterface::RFC3339),
                'seconds' => $retryAfter ?? 0,
            ]);

            throw new RateLimitedException(
                message: "Rate limited: HTTP 429 from {$source->url}",
                code: 0,
                previous: null,
                retryAfter: $retryAfter,
                rateLimitReset: $rateLimitReset,
            );
        }

        // 3xx (incl. 304), 4xx (other), 5xx
        throw new FetchException(
            message: "HTTP {$status} from {$source->url}",
            code: 0,
            previous: null,
            statusCode: $status,
            bodyExcerpt: mb_strcut($body, 0, self::BODY_EXCERPT_BYTE_LIMIT, 'UTF-8'),
        );
    }

    /**
     * @return array{0: ?int, 1: ?DateTimeImmutable}
     */
    private function parseRateLimitHeaders(ResponseInterface $response): array
    {
        $retryAfterRaw = $response->getHeaderLine('Retry-After');
        $rateLimitResetRaw = $response->getHeaderLine('X-RateLimit-Reset');

        $retryAfter = null;
        if ($retryAfterRaw !== '') {
            if (ctype_digit($retryAfterRaw)) {
                $retryAfter = (int) $retryAfterRaw;
            } else {
                $date = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231, $retryAfterRaw);
                if ($date instanceof DateTimeImmutable) {
                    $diff = $date->getTimestamp() - (new DateTimeImmutable())->getTimestamp();
                    $retryAfter = max($diff, 0);
                }
            }
        }

        $rateLimitReset = null;
        if ($rateLimitResetRaw !== '' && ctype_digit($rateLimitResetRaw)) {
            $parsed = DateTimeImmutable::createFromFormat('U', $rateLimitResetRaw);
            if ($parsed instanceof DateTimeImmutable) {
                $rateLimitReset = $parsed;
            }
        }

        // X-RateLimit-Reset takes precedence; if absent but retryAfter present, derive.
        if (!$rateLimitReset instanceof DateTimeImmutable && $retryAfter !== null) {
            $derived = (new DateTimeImmutable())->modify("+{$retryAfter} seconds");
            // PHPStan stubs still type modify() as DateTimeImmutable|false even
            // though PHP 8.3+ deprecated the false return; defensive check.
            if ($derived instanceof DateTimeImmutable) {
                $rateLimitReset = $derived;
            }
        }

        return [$retryAfter, $rateLimitReset];
    }
}

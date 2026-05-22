<?php

declare(strict_types=1);

namespace n5s\Rangelog\Fetcher;

use InvalidArgumentException;
use n5s\Rangelog\Auth\CredentialProviderInterface;
use n5s\Rangelog\Domain\RawResponse;
use n5s\Rangelog\Domain\Source;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Per-URL credential injection decorator for {@see FetcherInterface}.
 *
 * Calls $credentials->authorize($source->url) before delegating; stashes the
 * returned header map into $source->metadata['_auth_headers']; HttpFetcher reads
 * the key and applies each header via withHeader() AFTER User-Agent and
 * If-None-Match so credentials win on collision.
 *
 * COMPOSITION GUIDANCE: MUST be composed BELOW CachingFetcher in the
 * FetcherStack so that authorize() runs only on cache miss:
 *
 *   new FetcherStack(
 *       base: new HttpFetcher($psr18, $factory),
 *       decorators: [
 *           fn ($i) => new AuthorizingFetcher($i, $credentials),  // innermost
 *           fn ($i) => new CachingFetcher($i, $cache),            // outermost
 *       ],
 *   );
 *   // Effective wiring: Caching(Authorizing(Http))
 *
 * URL CONTRACT: $source->url is passed VERBATIM to authorize() — no
 * normalization, no case folding, no port collapsing. Callers whose
 * CredentialProvider keys on URL must normalize themselves.
 *
 * SECURITY — HOST SCOPING (caller responsibility):
 * `CredentialProviderInterface::authorize()` is called once per request with
 * the full source URL. A malicious or compromised upstream package can set
 * its source URL to a third-party host the caller did NOT expect (e.g. a
 * `composer.json` `support.source` pointing at `https://attacker.example/`).
 * If `authorize()` returns the same credentials for any host, every fetch
 * leaks the token to whichever URL the package metadata claims.
 *
 * Implementations MUST inspect the URL's host and return `[]` (no auth) for
 * hosts they don't issue credentials to. The shipped `NullCredentialProvider`
 * always returns `[]`. The integration test `CrossHostLeakTest` exercises
 * the contract: a GitHub PAT must not be sent to gitlab.com, a GitLab PAT
 * must not be sent to api.github.com.
 *
 * HEADER VALIDATION: every header name returned by authorize() is validated
 * against RFC 7230 tchar; every header value is checked for CR/LF.
 * Violations raise \InvalidArgumentException — programmer-error sentinel that
 * propagates RAW (NOT wrapped as ChangelogException). The exception message
 * does NOT echo the offending name or value to prevent credential leakage in
 * exception traces and logs.
 *
 * LOGGING: single debug event per call, 'Auth applied for {url}, {count}
 * headers' with context ['url' => $source->url, 'count' => count($headers)] —
 * no header names, no header values, no body content.
 */
final readonly class AuthorizingFetcher implements FetcherInterface
{
    public const string AUTH_HEADERS_METADATA_KEY = '_auth_headers';

    /**
     * RFC 7230 §3.2.6 token = 1*tchar
     *   tchar = "!" / "#" / "$" / "%" / "&" / "'" / "*" / "+" / "-" / "." /
     *           "^" / "_" / "`" / "|" / "~" / DIGIT / ALPHA
     * Sources: RFC 7230 https://datatracker.ietf.org/doc/html/rfc7230#section-3.2.6
     * Matches nyholm/psr7's MessageTrait::validateAndTrimHeader pattern.
     */
    private const string TCHAR_PATTERN = '/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/D';

    private LoggerInterface $logger;

    public function __construct(
        private FetcherInterface $inner,
        private CredentialProviderInterface $credentials,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws InvalidArgumentException When authorize() returns a header name that is not a valid
     *                                  RFC 7230 token or a header value containing CR/LF.
     */
    public function fetch(Source $source): RawResponse
    {
        $headers = $this->credentials->authorize($source->url);

        if ($headers === []) {
            // Empty return -> pass Source through unchanged.
            $this->logger->debug('Auth applied for {url}, {count} headers', [
                'url' => $source->url,
                'count' => 0,
            ]);

            return $this->inner->fetch($source);
        }

        $this->validateHeaders($headers);

        $stamped = new Source(
            type: $source->type,
            url: $source->url,
            metadata: [...$source->metadata, self::AUTH_HEADERS_METADATA_KEY => $headers],
            prefetchedBody: $source->prefetchedBody, // preserve the resolver short-circuit
        );

        $this->logger->debug('Auth applied for {url}, {count} headers', [
            'url' => $source->url,
            'count' => \count($headers),
        ]);

        return $this->inner->fetch($stamped);
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws InvalidArgumentException When any name fails RFC 7230 tchar OR any name/value contains CR/LF.
     */
    private function validateHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            // RFC 7230 tchar gate on the NAME. Caught BEFORE the CRLF check because the
            // tchar regex implicitly excludes \r and \n; doing the explicit CRLF check first
            // would still catch them, but the regex is the documented contract — order kept for
            // exception-message clarity.
            if (preg_match(self::TCHAR_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException(
                    'CredentialProvider returned invalid header name: must be RFC 7230 token chars only',
                );
            }

            // CRLF check on the VALUE only. Names already passed tchar (which excludes \r\n).
            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new InvalidArgumentException(
                    'CredentialProvider returned invalid header: name/value must not contain CR/LF',
                );
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace n5s\Rangelog\Fetcher;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client wrapper that fails closed on redirect responses.
 *
 * Wrap any PSR-18 client with this decorator to enforce strict redirect
 * behaviour: if the inner client returns a 301, 302, 303, 307, or 308
 * status, this wrapper throws {@see RedirectNotFollowedException} (a
 * PSR-18 `ClientExceptionInterface`). {@see HttpFetcher} catches it and
 * surfaces a `FetchException(statusCode=0)` with the redirect exception
 * as `previous`.
 *
 * 304 Not Modified passes through unchanged because it is NOT a redirect:
 * `CachingFetcher` depends on `FetchException(statusCode=304)` for
 * conditional-GET caching.
 *
 * LIMITATION — auto-follow inside the inner client:
 * This wrapper can only intercept redirects the inner client SURFACED. If
 * the inner PSR-18 client is configured to auto-follow redirects, the
 * redirect happens entirely inside the client and never reaches this code
 * — and the client may have forwarded the `Authorization` header across
 * origins depending on its policy. PSR-18 has no standard way to disable
 * auto-follow after client construction.
 *
 * For redirect-strict behaviour you MUST also configure the inner client:
 *
 *   - Symfony HttpClient: `HttpClient::create(['max_redirects' => 0])`
 *   - Guzzle: `new Client(['allow_redirects' => false])`
 *   - php-http/curl-client: redirects are not followed by default
 *
 * This wrapper is the second half of the defence: even when configured
 * correctly, the inner client might surface a 30x (e.g. when
 * `max_redirects` is exceeded). This wrapper catches that case and turns
 * it into a typed exception with the Location header captured for
 * diagnostics.
 */
final readonly class NoRedirectClient implements ClientInterface
{
    /**
     * @var list<int>
     */
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(private ClientInterface $inner)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);

        if (\in_array($response->getStatusCode(), self::REDIRECT_STATUSES, true)) {
            $locationHeader = $response->getHeaderLine('Location');

            throw new RedirectNotFollowedException(
                statusCode: $response->getStatusCode(),
                requestUrl: (string) $request->getUri(),
                location: $locationHeader !== '' ? $locationHeader : null,
            );
        }

        return $response;
    }
}

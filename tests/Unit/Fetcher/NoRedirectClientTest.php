<?php

declare(strict_types=1);

use n5s\Rangelog\Fetcher\NoRedirectClient;
use n5s\Rangelog\Fetcher\RedirectNotFollowedException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * In-test PSR-18 client that returns a queued response or throws a queued
 * ClientException. Records every request it sees.
 */
final class StubClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    public array $queue = [];

    /** @var list<RequestInterface> */
    public array $received = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->received[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new RuntimeException('StubClient queue empty');
        }
        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }
}

function makeRequest(string $url): RequestInterface
{
    return (new Psr17Factory())->createRequest('GET', $url);
}

// ---------------------------------------------------------------------------
// Structural
// ---------------------------------------------------------------------------

it('is a final class implementing ClientInterface', function (): void {
    $r = new ReflectionClass(NoRedirectClient::class);

    expect($r->isFinal())->toBeTrue();
    expect($r->implementsInterface(ClientInterface::class))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Pass-through (non-redirect statuses)
// ---------------------------------------------------------------------------

it('passes 2xx responses through unchanged', function (int $status): void {
    $stub = new StubClient();
    $stub->queue[] = new Response($status, [], 'body');

    $client = new NoRedirectClient($stub);
    $response = $client->sendRequest(makeRequest('https://example.test/foo'));

    expect($response->getStatusCode())->toBe($status);
    expect((string) $response->getBody())->toBe('body');
})->with([200, 201, 204]);

it('passes 304 through unchanged so conditional-GET caching keeps working', function (): void {
    $stub = new StubClient();
    $stub->queue[] = new Response(304, [], '');

    $client = new NoRedirectClient($stub);
    $response = $client->sendRequest(makeRequest('https://example.test/foo'));

    expect($response->getStatusCode())->toBe(304);
});

it('passes 4xx responses through unchanged', function (int $status): void {
    $stub = new StubClient();
    $stub->queue[] = new Response($status, [], 'err');

    $client = new NoRedirectClient($stub);
    $response = $client->sendRequest(makeRequest('https://example.test/foo'));

    expect($response->getStatusCode())->toBe($status);
})->with([400, 401, 403, 404, 429]);

it('passes 5xx responses through unchanged', function (int $status): void {
    $stub = new StubClient();
    $stub->queue[] = new Response($status, [], 'srv-err');

    $client = new NoRedirectClient($stub);
    $response = $client->sendRequest(makeRequest('https://example.test/foo'));

    expect($response->getStatusCode())->toBe($status);
})->with([500, 502, 503]);

// ---------------------------------------------------------------------------
// Redirect detection
// ---------------------------------------------------------------------------

it('throws RedirectNotFollowedException for redirect statuses', function (int $status): void {
    $stub = new StubClient();
    $stub->queue[] = new Response($status, ['Location' => 'https://attacker.example/foo'], '');

    $client = new NoRedirectClient($stub);

    expect(fn (): ResponseInterface => $client->sendRequest(makeRequest('https://api.github.com/repos/foo/bar')))
        ->toThrow(RedirectNotFollowedException::class);
})->with([301, 302, 303, 307, 308]);

it('exposes status, request URL, and Location header on the exception', function (): void {
    $stub = new StubClient();
    $stub->queue[] = new Response(302, ['Location' => 'https://attacker.example/leak'], '');

    $client = new NoRedirectClient($stub);

    $caught = null;
    try {
        $client->sendRequest(makeRequest('https://api.github.com/repos/foo/bar'));
    } catch (RedirectNotFollowedException $e) {
        $caught = $e;
    }

    if (! $caught instanceof RedirectNotFollowedException) {
        throw new RuntimeException('Expected RedirectNotFollowedException to be thrown');
    }

    expect($caught->statusCode)->toBe(302);
    expect($caught->requestUrl)->toBe('https://api.github.com/repos/foo/bar');
    expect($caught->location)->toBe('https://attacker.example/leak');
});

it('handles a redirect response without a Location header (location=null)', function (): void {
    $stub = new StubClient();
    $stub->queue[] = new Response(301, [], '');

    $client = new NoRedirectClient($stub);

    $caught = null;
    try {
        $client->sendRequest(makeRequest('https://example.test/foo'));
    } catch (RedirectNotFollowedException $e) {
        $caught = $e;
    }

    if (! $caught instanceof RedirectNotFollowedException) {
        throw new RuntimeException('Expected RedirectNotFollowedException to be thrown');
    }

    expect($caught->location)->toBeNull();
});

it('makes RedirectNotFollowedException a PSR-18 ClientExceptionInterface', function (): void {
    $e = new RedirectNotFollowedException(
        statusCode: 302,
        requestUrl: 'https://api.github.com/foo',
        location: 'https://elsewhere.example/foo',
    );

    expect($e)->toBeInstanceOf(ClientExceptionInterface::class);
});

// ---------------------------------------------------------------------------
// Inner exception propagation
// ---------------------------------------------------------------------------

it('propagates ClientExceptionInterface from the inner client', function (): void {
    $stub = new StubClient();
    $innerError = new class () extends RuntimeException implements ClientExceptionInterface {};
    $stub->queue[] = $innerError;

    $client = new NoRedirectClient($stub);

    expect(fn (): ResponseInterface => $client->sendRequest(makeRequest('https://example.test/foo')))
        ->toThrow($innerError::class);
});

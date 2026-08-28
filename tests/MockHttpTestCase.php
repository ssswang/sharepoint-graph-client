<?php

declare(strict_types=1);

namespace SharepointGraphClient\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

use PHPUnit\Framework\TestCase;

use SharepointGraphClient\GraphSite;

/**
 * Base test case that runs the library against a stubbed HTTP layer:
 * responses are queued in a Guzzle MockHandler and every request is
 * recorded for assertions.
 *
 * Assertions read from the PSR-7 request itself (fully applied),
 * not from the raw request options.
 */
abstract class MockHttpTestCase extends TestCase
{
    protected MockHandler $handler;

    /**
     * Recorded requests: [['request' => ..., 'response' => ..., 'options' => ...], ...]
     *
     * @var array<int, array{request: \Psr\Http\Message\RequestInterface, response: \Psr\Http\Message\ResponseInterface, options: array}>
     */
    protected array $history = [];

    /**
     * Create a Graph Site with a stubbed HTTP client
     */
    protected function makeSite(): GraphSite
    {
        $this->handler = new MockHandler();
        $this->history = [];

        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));

        $client = new Client([
            'handler'  => $stack,
            'base_uri' => 'https://graph.microsoft.com/v1.0/',
        ]);

        return new GraphSite($client, [
            'site_url'  => 'https://contoso.sharepoint.com/sites/team',
            'tenant'    => '11111111-2222-3333-4444-555555555555',
            'client_id' => 'client-id',
            'secret'    => 'shhh',
        ]);
    }

    /**
     * Create a Graph Site with an Access Token and the Site ID already resolved,
     * so queued responses and recorded requests start at index 0
     */
    protected function makeSiteWithToken(): GraphSite
    {
        $site = $this->makeSite();

        $this->queue(
            $this->jsonResponse(200, [
                'token_type'   => 'Bearer',
                'expires_in'   => 3599,
                'access_token' => 'TOKEN1',
            ]),
            $this->jsonResponse(200, [
                'id'     => 'SITE-ID',
                'webUrl' => 'https://contoso.sharepoint.com/sites/team',
            ]),
        );

        $site->createGraphAccessToken();
        $site->getSiteId();

        // drop the setup requests from the recorded history
        $this->history = [];

        return $site;
    }

    /**
     * Queue HTTP responses
     */
    protected function queue(Response ...$responses): void
    {
        $this->handler->append(...$responses);
    }

    /**
     * Create a JSON HTTP response
     */
    protected function jsonResponse(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body));
    }

    /**
     * Get the HTTP method of a recorded request
     */
    protected function requestMethod(int $index): string
    {
        return $this->history[$index]['request']->getMethod();
    }

    /**
     * Get the full URI of a recorded request
     */
    protected function requestUri(int $index): string
    {
        return (string) $this->history[$index]['request']->getUri();
    }

    /**
     * Get the path of a recorded request (without the query string)
     */
    protected function requestPath(int $index): string
    {
        return $this->history[$index]['request']->getUri()->getPath();
    }

    /**
     * Get the decoded query parameters of a recorded request
     *
     * (parse_str is not used because it converts dots in key names to underscores)
     */
    protected function requestQuery(int $index): array
    {
        $params = [];

        foreach (explode('&', $this->history[$index]['request']->getUri()->getQuery()) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            $params[rawurldecode($key)] = rawurldecode($value);
        }

        return $params;
    }

    /**
     * Get a header line of a recorded request
     */
    protected function requestHeader(int $index, string $name): string
    {
        return $this->history[$index]['request']->getHeaderLine($name);
    }

    /**
     * Get the JSON decoded body of a recorded request
     */
    protected function requestJson(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true) ?? [];
    }

    /**
     * Get the urlencoded body of a recorded request as an array
     */
    protected function requestForm(int $index): array
    {
        parse_str((string) $this->history[$index]['request']->getBody(), $params);

        return $params;
    }

    /**
     * Get the raw body of a recorded request
     */
    protected function requestRawBody(int $index): string
    {
        return (string) $this->history[$index]['request']->getBody();
    }

    /**
     * Get the number of recorded requests
     */
    protected function requestCount(): int
    {
        return count($this->history);
    }
}

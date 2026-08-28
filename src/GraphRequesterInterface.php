<?php

declare(strict_types=1);

namespace SharepointGraphClient;

interface GraphRequesterInterface
{
    /**
     * Send an HTTP request
     *
     * @param  string $path    URL or path relative to the Graph endpoint
     * @param  array  $options HTTP client options (see GuzzleHttp\RequestOptions)
     * @param  string $method  HTTP method name (GET, POST, PUT, PATCH, DELETE, ...)
     * @param  bool   $json    Return JSON if true, return the PSR-7 Response otherwise
     * @throws GraphException
     * @return array|\GuzzleHttp\Psr7\Response
     */
    public function request(string $path, array $options = [], string $method = 'GET', bool $json = true): mixed;

    /**
     * Get the current Graph Access Token
     *
     * @throws GraphException
     */
    public function getGraphAccessToken(): GraphAccessToken;
}

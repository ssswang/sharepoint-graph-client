<?php

declare(strict_types=1);

namespace SharepointGraphClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;

class GraphSite implements GraphRequesterInterface
{
    /**
     * Microsoft Graph API endpoint (global service)
     */
    const GRAPH = 'https://graph.microsoft.com/v1.0/';

    /**
     * Microsoft identity platform endpoint (global service)
     */
    const AUTHORITY = 'https://login.microsoftonline.com/';

    /**
     * Default Graph Scope
     */
    const SCOPE = 'https://graph.microsoft.com/.default';

    /**
     * Default configuration
     *
     * @access  protected
     * @var     array
     */
    protected const DEFAULT_CONFIG = [
        'site_url'  => null,        // SharePoint Site URL
        'tenant'    => null,        // Azure AD Tenant ID (GUID, eg. 11111111-2222-3333-4444-555555555555)
        'client_id' => null,        // Azure AD application (client) ID
        'secret'    => null,        // Azure AD application client secret
        'certificate' => null,      // Azure AD certificate credentials (see GraphAccessToken::createClientAssertion)

        // Microsoft identity platform endpoint
        // (eg. https://login.microsoftonline.us for the US Government cloud)
        'authority' => self::AUTHORITY,

        // OAuth 2.0 scope (eg. https://graph.microsoft.us/.default for the US Government cloud)
        'scope'     => self::SCOPE,

        // Microsoft Graph API endpoint
        // (eg. https://graph.microsoft.us/v1.0/ for the US Government cloud)
        'graph'     => self::GRAPH,

        // Retry configuration for throttled (429) and transient (503/504) responses
        'retry' => [
            'attempts' => 3,    // maximum number of request attempts
            'delay'    => null, // fixed delay in seconds (Retry-After header is honored when null)
        ],
    ];

    /**
     * HTTP Client object
     */
    protected Client $http;

    /**
     * Access Token
     */
    protected ?GraphAccessToken $token = null;

    /**
     * Site Configuration
     */
    protected array $config;

    /**
     * Site Hostname (eg. https://contoso.sharepoint.com)
     */
    protected string $hostname;

    /**
     * Site Path (eg. sites/team, empty for the root Site)
     */
    protected string $sitePath;

    /**
     * Graph Site ID (resolved lazily, eg. contoso.sharepoint.com,xxxx-yyyy,zzzz-yyyy)
     */
    protected ?string $siteId = null;

    /**
     * Graph Site data (resolved lazily)
     */
    protected ?array $siteData = null;

    /**
     * SharePoint Site constructor
     *
     * @throws GraphException
     */
    public function __construct(Client $http, array $config)
    {
        $this->config = array_replace_recursive(static::DEFAULT_CONFIG, $config);

        $this->http = $http;

        $url = $this->config['site_url'];

        if (! is_string($url) || $url === '') {
            throw new GraphException('The SharePoint Site URL is empty/not set');
        }

        $components = parse_url($url);

        if ($components === false || ! isset($components['scheme'], $components['host'])) {
            throw new GraphException('The SharePoint Site URL is invalid');
        }

        $this->hostname = $components['scheme'].'://'.$components['host'];
        $this->sitePath = trim($components['path'] ?? '', '/');
    }

    /**
     * Get the Graph Site configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get the SharePoint Site Hostname
     */
    public function getHostname(?string $path = null): string
    {
        return sprintf('%s/%s', $this->hostname, ltrim((string) $path, '/'));
    }

    /**
     * Get the SharePoint Site Path
     */
    public function getPath(?string $path = null): string
    {
        return sprintf('%s/%s', $this->sitePath, ltrim((string) $path, '/'));
    }

    /**
     * Get the SharePoint Site URL
     */
    public function getUrl(?string $path = null): string
    {
        return $this->getHostname($this->getPath($path));
    }

    /**
     * Get the SharePoint Site logout URL
     */
    public function getLogoutUrl(): string
    {
        return $this->getUrl('_layouts/SignOut.aspx');
    }

    /**
     * Create a Graph Site
     *
     * @static
     * @param  string $siteUrl  SharePoint Site URL (eg. https://contoso.sharepoint.com/sites/team)
     * @param  array  $settings Instantiation settings ('site' => Graph Site configuration,
     *                          'http' => Guzzle HTTP Client configuration)
     * @throws GraphException
     */
    public static function create(string $siteUrl, array $settings = []): static
    {
        $settings = array_replace_recursive($settings, [
            'site' => [],
            'http' => [], // Guzzle HTTP Client configuration
        ]);

        $settings['site']['site_url'] = $siteUrl;

        // ensure the Graph endpoint has a trailing slash, so that
        // relative request paths resolve against it correctly
        $graph = rtrim((string) ($settings['site']['graph'] ?? static::GRAPH), '/').'/';

        $settings['site']['graph'] = $graph;

        $settings['http']['base_uri'] = $graph;

        $http = new Client($settings['http']);

        return new static($http, $settings['site']);
    }

    /**
     * Parse the Graph API response
     *
     * @throws GraphException
     */
    protected function parseResponse(Response $response): array
    {
        $httpStatus = $response->getStatusCode();
        $body = (string) $response->getBody();
        $json = json_decode($body, true);

        if ($httpStatus >= 400) {
            $message = null;

            // if the response body cannot be parsed as JSON,
            // the body will be used as the error message
            if (json_last_error() !== JSON_ERROR_NONE) {
                $message = $body;
            } else {
                // Microsoft Graph error responses
                if (isset($json['error']['message']) && $message === null) {
                    $message = isset($json['error']['code'])
                        ? $json['error']['code'].': '.$json['error']['message']
                        : $json['error']['message'];
                }

                // Microsoft identity platform (AADSTS) error responses
                if (isset($json['error_description']) && $message === null) {
                    $message = $json['error_description'];
                }

                if (isset($json['error']) && $message === null) {
                    $message = is_string($json['error']) ? $json['error'] : json_encode($json['error']);
                }
            }

            throw new GraphException((string) $message, $httpStatus);
        }

        return is_array($json) ? $json : [];
    }

    /**
     * {@inheritdoc}
     *
     * The Graph Access Token is attached automatically unless
     * an Authorization header is passed inside $options.
     *
     * Throttled (429) and transient (503/504) responses are retried
     * automatically, honoring the Retry-After response header.
     *
     * Set $options['graph_auth'] to false to skip the Access Token
     * (used internally to request Access Tokens).
     */
    public function request(string $path, array $options = [], string $method = 'GET', bool $json = true): mixed
    {
        $withAuth = $options['graph_auth'] ?? true;

        unset($options['graph_auth']);

        $attempts = 0;
        $authRetried = false;
        $maxAttempts = max(1, (int) ($this->config['retry']['attempts'] ?? 3));

        while (true) {
            $attempts++;

            if ($withAuth) {
                $options = array_replace_recursive($options, [
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->getGraphAccessToken(),
                        'Accept'        => 'application/json',
                    ],
                ]);
            }

            // relative paths must not begin with a slash, otherwise they
            // would replace the Graph version segment of the base URI
            if (! preg_match('#^https?://#i', $path)) {
                $path = ltrim($path, '/');
            }

            try {
                $response = $this->http->request($method, $path, array_replace($options, [
                    'http_errors' => false, // avoid throwing exceptions when we get HTTP errors (4XX, 5XX)
                ]));
            } catch (TransferException $e) {
                throw GraphException::fromTransferException($e);
            }

            $httpStatus = $response->getStatusCode();

            // an Access Token might have expired mid-flight: refresh it once and retry
            if ($withAuth && $httpStatus === 401 && ! $authRetried && $this->canCreateGraphAccessToken()) {
                $authRetried = true;
                $this->createGraphAccessToken();

                continue;
            }

            // throttling and transient errors: retry after the requested delay
            if (in_array($httpStatus, [429, 503, 504], true) && $attempts < $maxAttempts) {
                $delay = (int) ($this->config['retry']['delay']
                    ?? $response->getHeaderLine('Retry-After'));

                if ($delay <= 0 || $delay > 120) {
                    $delay = min(2 ** $attempts, 30);
                }

                usleep($delay * 1000000);

                continue;
            }

            return $json ? $this->parseResponse($response) : $response;
        }
    }

    /**
     * Fetch all pages of a Graph collection
     *
     * Follows the @odata.nextLink pagination URLs until
     * the whole collection has been retrieved.
     *
     * @param  string $path    URL or path relative to the Graph endpoint
     * @param  array  $options HTTP client options
     * @throws GraphException
     */
    public function getAllPages(string $path, array $options = []): array
    {
        $items = [];
        $url = $path;

        while ($url !== null) {
            $json = $this->request($url, $options);

            $items = array_merge($items, $json['value'] ?? []);

            // the next link is an absolute URL that already
            // contains the query string of the next page
            $url = $json['@odata.nextLink'] ?? null;

            unset($options['query']);
        }

        return $items;
    }

    /**
     * Can an Access Token be created with the current configuration?
     */
    public function canCreateGraphAccessToken(): bool
    {
        return ! empty($this->config['tenant'])
            && ! empty($this->config['client_id'])
            && (! empty($this->config['secret']) || ! empty($this->config['certificate']));
    }

    /**
     * Create a Graph Access Token (app-only policy)
     *
     * @throws GraphException
     */
    public function createGraphAccessToken(array $extra = []): GraphAccessToken
    {
        return $this->token = GraphAccessToken::create($this, $extra);
    }

    /**
     * {@inheritdoc}
     *
     * The Access Token is created (or recreated when it has expired)
     * automatically when the Site configuration allows it.
     */
    public function getGraphAccessToken(): GraphAccessToken
    {
        if ($this->token === null) {
            if (! $this->canCreateGraphAccessToken()) {
                throw new GraphException('Invalid Graph Access Token');
            }

            return $this->createGraphAccessToken();
        }

        if ($this->token->hasExpired()) {
            if (! $this->canCreateGraphAccessToken()) {
                throw new GraphException('Expired Graph Access Token');
            }

            return $this->createGraphAccessToken();
        }

        return $this->token;
    }

    /**
     * Set the Graph Access Token (eg. a delegated token obtained via an auth code flow)
     *
     * @throws GraphException
     */
    public function setGraphAccessToken(GraphAccessToken $token): void
    {
        if ($token->hasExpired()) {
            throw new GraphException('Expired Graph Access Token');
        }

        $this->token = $token;
    }

    /**
     * Get the Graph Site ID
     *
     * Resolves the SharePoint Site URL into a Graph Site ID
     * (lazily, and only once per Graph Site instance).
     *
     * @throws GraphException
     */
    public function getSiteId(): string
    {
        if ($this->siteId !== null) {
            return $this->siteId;
        }

        // the root Site is addressed by hostname only,
        // other Sites are addressed as hostname:/path
        $address = $this->sitePath === ''
            ? 'sites/'.$this->getHostnameComponent()
            : 'sites/'.$this->getHostnameComponent().':/'.$this->sitePath;

        $json = $this->request($address, [
            'query' => ['$select' => 'id,webUrl,displayName,name'],
        ]);

        if (empty($json['id'])) {
            throw new GraphException('Unable to resolve the Graph Site ID');
        }

        $this->siteId = $json['id'];
        $this->siteData = $json;

        return $this->siteId;
    }

    /**
     * Get the URL hostname component of the Site
     */
    protected function getHostnameComponent(): string
    {
        return (string) parse_url($this->hostname, PHP_URL_HOST);
    }

    /**
     * Get the Graph Site data (available after the Site ID has been resolved)
     */
    public function getSiteData(): ?array
    {
        return $this->siteData;
    }

    /**
     * Get the Graph web URL of the Site
     */
    public function getWebUrl(): ?string
    {
        $this->getSiteId();

        return $this->siteData['webUrl'] ?? null;
    }

    /**
     * Get all Graph Drives (document libraries) of the Site
     *
     * @throws GraphException
     */
    public function getGraphDrives(array $settings = []): array
    {
        return GraphDrive::getAll($this, $settings);
    }

    /**
     * Get a Graph Drive of the Site by ID
     *
     * @throws GraphException
     */
    public function getGraphDrive(string $id, array $settings = []): GraphDrive
    {
        return GraphDrive::getByID($this, $id, $settings);
    }

    /**
     * Get the default Graph Drive of the Site
     *
     * @throws GraphException
     */
    public function getDefaultGraphDrive(array $settings = []): GraphDrive
    {
        return GraphDrive::getDefault($this, $settings);
    }

    /**
     * Get a Graph Drive of the Site by name (document library title)
     *
     * @throws GraphException
     */
    public function getGraphDriveByName(string $name, array $settings = []): GraphDrive
    {
        return GraphDrive::getByName($this, $name, $settings);
    }
}

<?php

declare(strict_types=1);

namespace SharepointGraphClient;

use DateTimeImmutable;
use DateTimeZone;

use Firebase\JWT\JWT;

class GraphAccessToken extends GraphObject
{
    /**
     * Access token
     */
    protected ?string $token = null;

    /**
     * Expire date
     */
    protected ?DateTimeImmutable $expires = null;

    /**
     * Graph Access Token constructor
     *
     * @param  string[] $json  JSON response from the OAuth 2.0 token endpoint
     * @param  string[] $extra Extra Graph Access Token properties to map
     * @throws GraphException
     */
    public function __construct(array $json, array $extra = [])
    {
        parent::__construct([
            'token'   => 'access_token',
            'expires' => 'expires_on',
        ], $extra);

        // normalize the expires_in seconds into an absolute expire date
        if (array_key_exists('expires_in', $json)) {
            $json['expires_on'] = (new DateTimeImmutable())->modify(sprintf('+%d seconds', (int) $json['expires_in']));
        }

        $this->hydrate($json);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'token'   => $this->token,
            'expires' => $this->expires,
            'extra'   => $this->extra,
        ];
    }

    /**
     * Serialize the Graph Access Token
     */
    public function __serialize(): array
    {
        return [
            'token'   => $this->token,
            'expires' => $this->expires?->getTimestamp(),
            'timezone' => $this->expires?->getTimezone()->getName(),
        ];
    }

    /**
     * Recreate the Graph Access Token
     */
    public function __unserialize(array $data): void
    {
        $this->token = $data['token'] ?? null;

        $this->expires = isset($data['expires'])
            ? (new DateTimeImmutable())
                ->setTimestamp((int) $data['expires'])
                ->setTimezone(new DateTimeZone($data['timezone'] ?? 'UTC'))
            : null;
    }

    /**
     * Graph Access Token string value
     */
    public function __toString(): string
    {
        return (string) $this->token;
    }

    /**
     * Check if the Graph Access Token has expired
     */
    public function hasExpired(): bool
    {
        return $this->expires === null || $this->expires->getTimestamp() <= time();
    }

    /**
     * Get the Graph Access Token expire date
     */
    public function expireDate(): ?DateTimeImmutable
    {
        return $this->expires;
    }

    /**
     * Create a Graph Access Token (app-only policy, client credentials grant)
     *
     * Authentication is performed against the Microsoft identity platform,
     * using either a client secret, or a certificate (client assertion).
     *
     * @param  GraphSite $site  Graph Site
     * @param  string[]  $extra Extra Graph Access Token properties to map
     * @throws GraphException
     */
    public static function create(GraphSite $site, array $extra = []): static
    {
        $config = $site->getConfig();

        if (empty($config['tenant'])) {
            throw new GraphException('The Tenant ID is empty/not set');
        }

        if (empty($config['client_id'])) {
            throw new GraphException('The Client ID is empty/not set');
        }

        if (empty($config['secret']) && empty($config['certificate'])) {
            throw new GraphException('Either a Client Secret or a Certificate is required');
        }

        $tokenUrl = sprintf('%s/%s/oauth2/v2.0/token', rtrim((string) $config['authority'], '/'), $config['tenant']);

        $payload = [
            'grant_type' => 'client_credentials',
            'client_id'  => $config['client_id'],
            'scope'      => $config['scope'],
        ];

        if (! empty($config['secret'])) {
            $payload['client_secret'] = $config['secret'];
        } else {
            $payload['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
            $payload['client_assertion'] = static::createClientAssertion($tokenUrl, (string) $config['client_id'], $config['certificate']);
        }

        $json = $site->request($tokenUrl, [
            'graph_auth' => false, // do not send an Access Token to acquire an Access Token
            'form_params' => $payload,
        ], 'POST');

        return new static($json, $extra);
    }

    /**
     * Create a JWT client assertion for certificate based authentication
     *
     * The certificate configuration array supports:
     * - private_key             : path to a PEM file, or the PEM contents
     * - private_key_passphrase  : optional passphrase of the private key
     * - thumbprint              : base64url encoded SHA-1 thumbprint of the certificate (x5t)
     *
     * @param  string $audience    Token endpoint URL (audience of the assertion)
     * @param  string $clientId    Azure AD application (client) ID
     * @param  array  $certificate Certificate configuration
     * @throws GraphException
     */
    public static function createClientAssertion(string $audience, string $clientId, array $certificate): string
    {
        if (empty($certificate['private_key'])) {
            throw new GraphException('The Certificate Private Key is empty/not set');
        }

        if (empty($certificate['thumbprint'])) {
            throw new GraphException('The Certificate Thumbprint is empty/not set');
        }

        $privateKey = (string) $certificate['private_key'];

        // load the private key from a file when it is not PEM contents
        if (! str_contains($privateKey, 'PRIVATE KEY')) {
            $contents = @file_get_contents($privateKey);

            if ($contents === false) {
                throw new GraphException('Unable to read the Certificate Private Key: '.$privateKey);
            }

            $privateKey = $contents;
        }

        $now = time();

        $claims = [
            'aud' => $audience,
            'iss' => $clientId,
            'sub' => $clientId,
            'jti' => bin2hex(random_bytes(20)),
            'nbf' => $now,
            'exp' => $now + 604800, // max lifetime allowed by the identity platform
        ];

        return JWT::encode($claims, $privateKey, 'RS256', (string) $certificate['thumbprint']);
    }
}

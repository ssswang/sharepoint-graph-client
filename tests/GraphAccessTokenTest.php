<?php

declare(strict_types=1);

namespace SharepointGraphClient\Tests;

use DateTimeImmutable;

use PHPUnit\Framework\TestCase;

use SharepointGraphClient\GraphAccessToken;
use SharepointGraphClient\GraphException;

class GraphAccessTokenTest extends TestCase
{
    public function test_hydration_from_token_endpoint_response(): void
    {
        $token = new GraphAccessToken([
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
            'access_token'  => 'abc123',
        ]);

        $this->assertSame('abc123', (string) $token);
        $this->assertFalse($token->hasExpired());
        $this->assertInstanceOf(DateTimeImmutable::class, $token->expireDate());
        $this->assertTrue($token->expireDate()->getTimestamp() > time() + 59 * 60);
    }

    public function test_expired_token_is_detected(): void
    {
        $token = new GraphAccessToken([
            'token_type'   => 'Bearer',
            'expires_on'   => (new DateTimeImmutable())->modify('-10 seconds'),
            'access_token' => 'abc123',
        ]);

        $this->assertTrue($token->hasExpired());
    }

    public function test_token_without_expiry_is_considered_expired(): void
    {
        $token = new GraphAccessToken([]);

        $this->assertTrue($token->hasExpired());
    }

    public function test_serialization_round_trip(): void
    {
        $token = new GraphAccessToken([
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'access_token' => 'abc123',
        ]);

        $restored = unserialize(serialize($token));

        $this->assertInstanceOf(GraphAccessToken::class, $restored);
        $this->assertSame('abc123', (string) $restored);
        $this->assertFalse($restored->hasExpired());
        $this->assertEquals($token->expireDate()->getTimestamp(), $restored->expireDate()->getTimestamp());
    }

    public function test_client_assertion_requires_a_private_key(): void
    {
        $this->expectException(GraphException::class);

        GraphAccessToken::createClientAssertion('https://login.microsoftonline.com/contoso/oauth2/v2.0/token', 'client-id', [
            'thumbprint' => 'abc',
        ]);
    }

    public function test_client_assertion_requires_a_thumbprint(): void
    {
        $this->expectException(GraphException::class);

        GraphAccessToken::createClientAssertion('https://login.microsoftonline.com/contoso/oauth2/v2.0/token', 'client-id', [
            'private_key' => '-----BEGIN PRIVATE KEY-----',
        ]);
    }

    public function test_client_assertion_signs_a_rs256_jwt(): void
    {
        // generate a throwaway RSA key pair
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            $this->markTestSkipped('OpenSSL is not available');
        }

        openssl_pkey_export($key, $privateKey);

        $assertion = GraphAccessToken::createClientAssertion(
            'https://login.microsoftonline.com/contoso/oauth2/v2.0/token',
            'client-id',
            ['private_key' => $privateKey, 'thumbprint' => 'thumbprint']
        );

        $parts = explode('.', $assertion);

        $this->assertCount(3, $parts);

        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('thumbprint', $header['x5t']);
        $this->assertSame('client-id', $claims['iss']);
        $this->assertSame('client-id', $claims['sub']);
        $this->assertSame('https://login.microsoftonline.com/contoso/oauth2/v2.0/token', $claims['aud']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth\AuthException;
use App\Auth\IdTokenVerifier;
use App\Auth\ProviderConfig;
use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;

/**
 * IDトークン検証のテスト。
 * 外部レビュー（docs/基本設計書_Phase2_レビュー報告書_20260725.md §7「認証」）で要求された
 * 異常系を網羅する。
 */
final class IdTokenVerifierTest extends TestCase
{
    private const KID = 'test-key-1';
    private const CLIENT_ID = 'test-client-id';
    private const TENANT_ID = '11111111-2222-3333-4444-555555555555';

    private OpenSSLAsymmetricKey $privateKey;
    /** @var array<string, mixed> */
    private array $jwks;
    /** @var OpenSSLAsymmetricKey|null */
    private ?OpenSSLAsymmetricKey $otherPrivateKey = null;

    protected function setUp(): void
    {
        $_ENV['GOOGLE_CLIENT_ID'] = self::CLIENT_ID;
        $_ENV['GOOGLE_CLIENT_SECRET'] = 'secret';
        $_ENV['GOOGLE_REDIRECT_URI'] = 'https://example.com/api/auth/google/callback';
        $_ENV['MS_CLIENT_ID'] = self::CLIENT_ID;
        $_ENV['MS_CLIENT_SECRET'] = 'secret';
        $_ENV['MS_REDIRECT_URI'] = 'https://example.com/api/auth/microsoft/callback';
        $_ENV['MS_TENANT'] = 'common';

        [$this->privateKey, $jwk] = self::generateKey(self::KID);
        $this->jwks = ['keys' => [$jwk]];
    }

    public function testValidGoogleTokenIsAccepted(): void
    {
        $claims = $this->verifyGoogle($this->googleClaims());

        $this->assertSame('google-sub-123', $claims['sub']);
        $this->assertSame('user@company.co.jp', $claims['email']);
    }

    public function testRejectsTokenSignedByUnknownKey(): void
    {
        // 攻撃者が自分の鍵で署名したトークン（kidは正規のものを詐称）
        [$attackerKey, ] = self::generateKey(self::KID);
        $this->otherPrivateKey = $attackerKey;

        $token = JWT::encode($this->googleClaims(), $attackerKey, 'RS256', self::KID);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/署名検証に失敗/');

        $this->verifierForGoogle()->verify($token, 'expected-nonce');
    }

    public function testRejectsExpiredToken(): void
    {
        $claims = $this->googleClaims();
        // leeway(60秒)を十分に超える過去
        $claims['exp'] = time() - 3600;
        $claims['iat'] = time() - 7200;

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/署名検証に失敗/');

        $this->verifyGoogle($claims);
    }

    public function testRejectsWrongAudience(): void
    {
        $claims = $this->googleClaims();
        $claims['aud'] = 'someone-elses-client-id';

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/aud/');

        $this->verifyGoogle($claims);
    }

    public function testRejectsMismatchedNonce(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/nonce/');

        $this->verifyGoogle($this->googleClaims(), 'a-different-nonce');
    }

    public function testRejectsWrongIssuer(): void
    {
        $claims = $this->googleClaims();
        $claims['iss'] = 'https://evil.example.com';

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/iss/');

        $this->verifyGoogle($claims);
    }

    public function testRejectsMultipleAudienceWithoutMatchingAzp(): void
    {
        $claims = $this->googleClaims();
        $claims['aud'] = [self::CLIENT_ID, 'another-client'];
        $claims['azp'] = 'another-client';

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/azp/');

        $this->verifyGoogle($claims);
    }

    public function testAcceptsValidMicrosoftTokenWithMatchingTid(): void
    {
        $claims = $this->verifyMicrosoft($this->microsoftClaims());

        $this->assertSame(self::TENANT_ID, $claims['tid']);
    }

    public function testRejectsMicrosoftTokenWhenIssuerAndTidDisagree(): void
    {
        // 別テナントのissuerを名乗るトークン（common利用時の典型的な攻撃）
        $claims = $this->microsoftClaims();
        $claims['iss'] = 'https://login.microsoftonline.com/99999999-9999-9999-9999-999999999999/v2.0';

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/iss と tid/');

        $this->verifyMicrosoft($claims);
    }

    public function testRejectsMicrosoftTokenWithoutTid(): void
    {
        $claims = $this->microsoftClaims();
        unset($claims['tid']);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/tid/');

        $this->verifyMicrosoft($claims);
    }

    public function testRejectsMalformedTid(): void
    {
        $claims = $this->microsoftClaims();
        // issuer文字列の組み立てに使われるため、想定外の形式は拒否する
        $claims['tid'] = '../../evil';
        $claims['iss'] = 'https://login.microsoftonline.com/../../evil/v2.0';

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/tid/');

        $this->verifyMicrosoft($claims);
    }

    // --- ヘルパー ---

    /** @return array<string, mixed> */
    private function googleClaims(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'sub' => 'google-sub-123',
            'aud' => self::CLIENT_ID,
            'exp' => time() + 3600,
            'iat' => time(),
            'nonce' => 'expected-nonce',
            'email' => 'user@company.co.jp',
            'email_verified' => true,
            'hd' => 'company.co.jp',
            'name' => 'テスト太郎',
        ];
    }

    /** @return array<string, mixed> */
    private function microsoftClaims(): array
    {
        return [
            'iss' => 'https://login.microsoftonline.com/' . self::TENANT_ID . '/v2.0',
            'sub' => 'ms-sub-123',
            'oid' => 'ms-oid-456',
            'tid' => self::TENANT_ID,
            'aud' => self::CLIENT_ID,
            'exp' => time() + 3600,
            'iat' => time(),
            'nonce' => 'expected-nonce',
            'email' => 'user@company.co.jp',
            'name' => 'テスト太郎',
        ];
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    private function verifyGoogle(array $claims, string $nonce = 'expected-nonce'): array
    {
        $token = JWT::encode($claims, $this->privateKey, 'RS256', self::KID);

        return $this->verifierForGoogle()->verify($token, $nonce);
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    private function verifyMicrosoft(array $claims, string $nonce = 'expected-nonce'): array
    {
        $token = JWT::encode($claims, $this->privateKey, 'RS256', self::KID);

        return new IdTokenVerifier(
            ProviderConfig::for(ProviderConfig::MICROSOFT),
            // common の Discovery が返す issuer はプレースホルダを含む
            ['issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0', 'jwks_uri' => 'https://example.test/jwks'],
            $this->jwks,
        )->verify($token, $nonce);
    }

    private function verifierForGoogle(): IdTokenVerifier
    {
        return new IdTokenVerifier(
            ProviderConfig::for(ProviderConfig::GOOGLE),
            ['issuer' => 'https://accounts.google.com', 'jwks_uri' => 'https://example.test/jwks'],
            $this->jwks,
        );
    }

    /** @return array{0: OpenSSLAsymmetricKey, 1: array<string, mixed>} */
    private static function generateKey(string $kid): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            self::fail('テスト用のRSA鍵を生成できませんでした。');
        }

        $details = openssl_pkey_get_details($resource);

        $jwk = [
            'kty' => 'RSA',
            'kid' => $kid,
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ];

        return [$resource, $jwk];
    }
}

<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\HttpClient;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Throwable;

/**
 * IDトークンの検証。
 *
 * 外部レビュー（docs/基本設計書_Phase2_レビュー報告書_20260725.md §2.6）で要求された
 * 検証項目に1対1で対応する。各検証の意図をコメントで明示しているのは、レビュー項目との
 * 対応をコード上で追跡できるようにするため（ADR 20260731 の採用理由）。
 */
final class IdTokenVerifier
{
    /**
     * 許可する署名アルゴリズム。alg=none や HMAC（共通鍵）への混同攻撃を防ぐため
     * 非対称鍵の RS256 のみに限定する。
     */
    private const ALLOWED_ALGORITHMS = ['RS256'];

    /** 時刻ずれの許容範囲（秒）。exp/iat の検証に使用。 */
    private const LEEWAY_SECONDS = 60;

    /**
     * @param array<string, mixed>|null $documentOverride テスト用。Discoveryの取得を差し替える
     * @param array<string, mixed>|null $jwksOverride     テスト用。JWKSの取得を差し替える
     */
    public function __construct(
        private readonly ProviderConfig $config,
        private readonly ?array $documentOverride = null,
        private readonly ?array $jwksOverride = null,
    ) {
    }

    /**
     * @return array<string, mixed> 検証済みのクレーム
     * @throws AuthException 検証に失敗した場合
     */
    public function verify(string $idToken, string $expectedNonce): array
    {
        $document = $this->documentOverride ?? Discovery::fetch($this->config->discoveryUrl);

        // --- 署名検証 ---
        // JWK::parseKeySet は JWKS の各エントリから Key（鍵素材＋アルゴリズム）を構築する。
        // JWT::decode は JWTヘッダの kid に対応する鍵を選択するため、鍵ローテーションにも追随できる。
        // 鍵側がアルゴリズムを保持するため、ヘッダの alg を信用した混同攻撃は成立しない。
        try {
            $jwks = $this->jwksOverride ?? HttpClient::getJson($document['jwks_uri']);
            $keys = JWK::parseKeySet($jwks, self::ALLOWED_ALGORITHMS[0]);

            JWT::$leeway = self::LEEWAY_SECONDS;

            // decode 内で署名・exp・iat・nbf が検証される。
            $claims = (array) JWT::decode($idToken, $keys);
        } catch (Throwable $e) {
            throw new AuthException('invalid_id_token', 'IDトークンの署名検証に失敗しました: ' . $e->getMessage());
        }

        // --- iss（発行者）の検証 ---
        $this->verifyIssuer($claims, $document['issuer']);

        // --- aud / azp の検証 ---
        $this->verifyAudience($claims);

        // --- nonce の検証（リプレイ防止） ---
        $nonce = $claims['nonce'] ?? null;

        if (!is_string($nonce) || $expectedNonce === '' || !hash_equals($expectedNonce, $nonce)) {
            throw new AuthException('invalid_id_token', 'nonce が一致しません。');
        }

        // --- sub の存在確認（アカウント識別の主キーになるため必須） ---
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new AuthException('invalid_id_token', 'sub クレームが存在しません。');
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function verifyIssuer(array $claims, string $documentIssuer): void
    {
        $iss = $claims['iss'] ?? null;

        if (!is_string($iss) || $iss === '') {
            throw new AuthException('invalid_id_token', 'iss クレームが存在しません。');
        }

        if ($this->config->name === ProviderConfig::MICROSOFT) {
            // Microsoft の common エンドポイントでは、Discovery の issuer が
            // https://login.microsoftonline.com/{tenantid}/v2.0 というプレースホルダを含む。
            // 実際のトークンの iss はテナントごとに異なるため、tid クレームで組み立て直して比較する。
            $tid = $claims['tid'] ?? null;

            if (!is_string($tid) || $tid === '') {
                throw new AuthException('invalid_id_token', 'tid クレームが存在しません（Microsoft）。');
            }

            // tid は GUID のはず。想定外の値で issuer 文字列を組み立てないよう検証する。
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $tid) !== 1) {
                throw new AuthException('invalid_id_token', 'tid クレームの形式が不正です。');
            }

            $expected = str_replace('{tenantid}', $tid, $documentIssuer);

            if (!hash_equals($expected, $iss)) {
                throw new AuthException('invalid_id_token', 'iss と tid の整合が取れていません。');
            }

            return;
        }

        // Google は https://accounts.google.com と accounts.google.com の両方を発行しうる。
        $acceptable = [$documentIssuer];

        if ($this->config->name === ProviderConfig::GOOGLE) {
            $acceptable[] = 'accounts.google.com';
            $acceptable[] = 'https://accounts.google.com';
        }

        foreach ($acceptable as $candidate) {
            if (hash_equals($candidate, $iss)) {
                return;
            }
        }

        throw new AuthException('invalid_id_token', 'iss が想定した発行者と一致しません。');
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function verifyAudience(array $claims): void
    {
        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        $matched = false;

        foreach ($audiences as $candidate) {
            if (is_string($candidate) && hash_equals($this->config->clientId, $candidate)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            throw new AuthException('invalid_id_token', 'aud が自アプリのクライアントIDと一致しません。');
        }

        // aud が複数ある場合、azp（authorized party）が自分自身であることまで確認する。
        if (is_array($aud) && count($aud) > 1) {
            $azp = $claims['azp'] ?? null;

            if (!is_string($azp) || !hash_equals($this->config->clientId, $azp)) {
                throw new AuthException('invalid_id_token', 'aud が複数ありますが azp が一致しません。');
            }
        }
    }
}

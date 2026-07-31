<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\HttpClient;

/**
 * OIDC の認可リクエスト構築とトークン交換。
 * IDトークンの検証は IdTokenVerifier が担当する（ADR 20260731 参照）。
 */
final class OidcClient
{
    public function __construct(private readonly ProviderConfig $config)
    {
    }

    public function buildAuthorizationUrl(AuthTransaction $tx): string
    {
        $document = Discovery::fetch($this->config->discoveryUrl);

        $params = [
            'client_id' => $this->config->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->config->redirectUri,
            'scope' => $this->config->scope,
            'state' => $tx->state,
            'nonce' => $tx->nonce,
            'code_challenge' => $tx->codeChallenge(),
            'code_challenge_method' => 'S256',
        ];

        $separator = str_contains($document['authorization_endpoint'], '?') ? '&' : '?';

        return $document['authorization_endpoint'] . $separator
            . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 認可コードをトークンに交換する。
     *
     * @return array<string, mixed> token_endpoint のレスポンス（id_token, access_token 等）
     */
    public function exchangeCode(string $code, AuthTransaction $tx): array
    {
        $document = Discovery::fetch($this->config->discoveryUrl);

        return HttpClient::postForm($document['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->redirectUri,
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'code_verifier' => $tx->codeVerifier,
        ]);
    }

    /**
     * UserInfo エンドポイントから追加のクレームを取得する。
     *
     * IDトークンに必要なクレーム（email 等）が含まれている場合は呼ばない。
     * トークン差し替え攻撃を防ぐため、取得した sub がIDトークンの sub と一致することを
     * 呼び出し側で必ず確認する（基本設計書_Phase2.md §3.1）。
     *
     * @return array<string, mixed>
     * @throws AuthException Discovery に userinfo_endpoint が無い場合
     */
    public function fetchUserInfo(string $accessToken): array
    {
        $document = Discovery::fetch($this->config->discoveryUrl);
        $endpoint = $document['userinfo_endpoint'] ?? null;

        if (!is_string($endpoint) || $endpoint === '') {
            throw new AuthException('server_error', 'Discovery Document に userinfo_endpoint がありません。');
        }

        return HttpClient::getJson($endpoint, ["Authorization: Bearer {$accessToken}"]);
    }
}

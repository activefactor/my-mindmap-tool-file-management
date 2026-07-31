<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repository\UserRepository;

/**
 * SSOのidentityから、アプリ内のユーザーアカウントを解決する。
 *
 * 基本設計書_Phase2.md §3.1「アカウントとプロバイダの紐付け」（v2.3）に対応。
 * メールアドレスは変更・再利用されうるため恒久的な同一性の根拠にはせず、
 * identity の一意キーは provider + sub とする。
 */
final class AccountResolver
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @return array{user: array<string, mixed>, is_new: bool}
     * @throws AuthException 別プロバイダで登録済みのメールと衝突した場合
     */
    public function resolve(string $provider, string $providerUserId, string $email, string $displayName): array
    {
        // 1. identity が既に登録済みならそのユーザー
        $user = $this->users->findByIdentity($provider, $providerUserId);

        if ($user !== null) {
            return ['user' => $user, 'is_new' => false];
        }

        // 2. 同じメールの既存ユーザーを探す
        $existing = $this->users->findByEmail($email);

        if ($existing === null) {
            $userId = $this->users->createWithIdentity($email, $displayName, $provider, $providerUserId);

            $created = $this->users->findById($userId);

            if ($created === null) {
                throw new AuthException('server_error', '作成したユーザーを取得できませんでした。');
            }

            return ['user' => $created, 'is_new' => true];
        }

        // 2-a. identity が1件も無い既存ユーザー = db/seed.php で作成した初期管理者などの
        //      「行だけ用意された未ログインアカウント」。これを conflict にすると初期管理者が
        //      永久にログインできなくなるため、初回ログインとして identity を追加する。
        //      （基本設計書 v2.2 までこのケースが抜けており、Step 2 実装時に発見・修正した）
        if ($this->users->countIdentities((int) $existing['id']) === 0) {
            $this->users->addIdentity((int) $existing['id'], $provider, $providerUserId);

            return ['user' => $existing, 'is_new' => false];
        }

        // 2-b. 既に別プロバイダの identity を持つ場合は自動統合しない。
        //      メールの再割り当てによって他人のデータへアクセスできてしまうのを防ぐ。
        throw new AuthException(
            'account_conflict',
            'このメールアドレスは別のログイン方法で既に登録されています。'
        );
    }
}

# 開発ログ: Step 1 — ローカルDocker環境の動作検証完了

**日付**: 2026-07-31
**担当**: Claude Code
**関連機能**: 開発ステップ_Phase2.md Step 1

---

## 背景・課題

同日の先の作業（`20260731_docker_local_env.md`）で `server/` 一式と `docker-compose.yml` を
作成したが、その時点の実行環境に Docker が無く、実際の起動確認ができていなかった。
その後、実行環境に Docker が導入されたため、実際に起動して検証した。

## 実施内容と結果

```bash
cp server/.env.example server/.env
docker compose up -d --build
```

- ビルド・起動: 成功（`php`, `mysql` の2コンテナとも `Up`）
- ヘルスチェック: `curl http://localhost:8080/api/health` → `HTTP 200`,
  `{"status":"ok","php_version":"8.5.9"}`
- PHP拡張: `docker compose exec php php -m` で `curl`, `openssl`, `zip`, `pdo_mysql`,
  `mbstring` の5つすべてが有効であることを確認
- Composer: `docker compose exec php composer install` が成功し、`composer.lock` を生成
- MySQL: `docker compose exec mysql mysql -umindmap -pmindmap mindmap -e "SELECT VERSION();"`
  で `8.4.11` を確認

## 決定した内容とその理由

Step 1 の完了条件（`docker-compose up` でローカル環境が起動し、ヘルスチェックへ疎通できる
こと）をすべて満たしたため、Step 1 を完了とした。`composer.lock` は依存関係のバージョンを
固定するため（現時点では依存ライブラリなしの空の lock）、`.gitignore` 対象の `vendor/` とは
別にリポジトリへコミットする。

## 試行錯誤・ハマったこと

特になし。Dockerfile・docker-compose.ymlの設計（`20260731_docker_local_env.md`参照）は
初回のビルドでそのまま成功した。

## 今後の課題・TODO

- Google / Microsoft の開発者コンソールへのローカル用リダイレクトURI登録は、Step 3
  （認証基盤）着手までに行う。
- 次はStep 2（DBスキーマ実装・migrations）に進む。

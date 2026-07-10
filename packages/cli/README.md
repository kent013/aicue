# First-party CLI (`packages/cli`)

テンプレート由来アプリの first-party CLI。[oclif](https://oclif.io) v4 ベースの
単一パッケージ + `tsc` ビルド構成。

## ブランディング (汎用化)

アプリ名を各モジュールへ直書きしない。CLI の全識別子 (bin 名・環境変数 prefix・
設定ディレクトリ名・keychain サービス名・npm パッケージ名) は `src/branding.ts` の
`APP_SLUG` 1 箇所から派生する (Laravel 側の `config('template.slug')` に相当)。

`init.sh` は初期化時にこの `APP_SLUG` と `package.json` の name/bin/oclif を
アプリ slug へ置換する。派生を壊さないため、slug は必ず `branding.ts` 経由で参照する。

## コマンド

topicSeparator は `:`。bin/dirname はアプリ slug。

| コマンド | 説明 |
| --- | --- |
| `version` | CLI バージョン表示 (`/api/v1/version` 互換ネゴシエーションは `profile:add` の verify 経路が担う) |
| `doctor` | 環境自己診断 (`envinfo` + 資格情報バックエンド) |
| `profile:add` / `profile:list` / `profile:use` | API プロファイル (URL + API キー + 接続オプション) 管理 |
| `auth:login` / `auth:logout` / `auth:status` | OAuth (Authorization Code + PKCE, loopback リダイレクト) サインイン |
| `whoami` | 有効プロファイルの組織・鍵情報表示 |

ドメイン固有コマンドはテンプレートには含めない (各アプリで追加する)。

## 基底クラス階層

`BaseCommand`(`--ci` ラッチ + 型付きエラー→終了コード変換)を根に、
`ProfileCommand` / `ReadCommand` / `AuthCommand` が用途別に分岐する。

## 開発

```sh
pnpm install        # ワークスペース依存の導入 (@oclif/core, undici, envinfo, @napi-rs/keyring 等)
pnpm -C packages/cli build       # tsc → dist/
pnpm -C packages/cli test        # vitest
pnpm -C packages/cli typecheck   # tsconfig.test.json
```

ルートからは `pnpm build:packages` / `pnpm test:packages` で一括実行できる。

## 資格情報バックエンド

OS keychain (`@napi-rs/keyring`) を優先し、無い環境では暗号化 file-store
(`<PREFIX>_MASTER_PASSWORD` / `<PREFIX>_CREDENTIAL_KEY`)、明示 opt-in
(`--allow-plaintext-credentials` / `<PREFIX>_ALLOW_PLAINTEXT_CREDENTIALS=1`) で
平文 file-store にフォールバックする。

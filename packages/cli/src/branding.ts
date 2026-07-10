/**
 * CLI ブランディングの単一ソース。
 *
 * このパッケージはテンプレート由来アプリの first-party CLI。Laravel 側の
 * `config('template.slug')` に相当する識別子をここ 1 箇所に集約し、環境変数名・
 * 設定ディレクトリ名・keychain サービス名・bin 名などをすべて派生させる。
 * アプリ名を各モジュールへ直書きしないこと (テンプレート汎用化規約)。
 *
 * `init.sh` はテンプレート初期化時にこの `APP_SLUG` の値 (と package.json /
 * bin / oclif.dirname) をアプリ slug へ置換する。ここだけが置換対象なので、
 * 他モジュールは必ず本ファイルの定数経由で slug を参照する。
 */

/** アプリ slug (小文字英数)。init.sh の置換対象。既定はテンプレート汎用値。 */
export const APP_SLUG = "app";

/** CLI 実行ファイル名 (oclif bin)。slug と同一。 */
export const BIN_NAME = APP_SLUG;

/** 環境変数の prefix (slug の大文字化)。例: APP_API_URL。 */
export const ENV_PREFIX = APP_SLUG.toUpperCase();

/** ユーザーホーム下の設定/資格情報ディレクトリ名 (例: ~/.app)。 */
export const CONFIG_DIR_NAME = `.${APP_SLUG}`;

/** OS keychain のサービス名。 */
export const KEYCHAIN_SERVICE = APP_SLUG;

/** npm パッケージ名 (doctor の name 改ざん検知の期待値)。 */
export const CLI_PACKAGE_NAME = `@${APP_SLUG}/cli`;

/** 人間向け表示名 (loopback 完了ページ等)。slug ベースの中立表現。 */
export const DISPLAY_NAME = `${BIN_NAME} CLI`;

/** `PREFIX_<suffix>` 形式の環境変数名を組み立てる。 */
export function envName(suffix: string): string {
    return `${ENV_PREFIX}_${suffix}`;
}

/**
 * CLI が参照する環境変数名の一覧。文字列直書きを避けて必ずここを参照する。
 */
export const ENV = {
    ALLOW_PLAINTEXT: envName("ALLOW_PLAINTEXT_CREDENTIALS"),
    CREDENTIAL_KEY: envName("CREDENTIAL_KEY"),
    MASTER_PASSWORD: envName("MASTER_PASSWORD"),
    DISABLE_KEYCHAIN: envName("DISABLE_KEYCHAIN"),
    API_KEY: envName("API_KEY"),
    API_URL: envName("API_URL"),
    OAUTH_CLIENT_ID: envName("OAUTH_CLIENT_ID"),
    PROFILE: envName("PROFILE"),
} as const;

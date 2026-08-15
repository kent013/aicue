## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)



【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (解析対象は app / config / database / routes。tests は対象外)
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト）
5. DTO/JsonResource パターンの遵守
6. 副作用・後退リスク
7. 波及変更の網羅性
8. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）

【この設計に固有の必須確認点】
- WebAuthn の仕様として、身元の識別子 (relying party id) と接続元 (origin) の関係検査
  (`host === rpId || str_ends_with(host, '.'.$rpId)`) は正しいか。誤検知・見逃しはあるか。
- config/passkeys.php が env() だけを読み他 config を読まない方針は、config:cache 下で正しく動くか。
- laravel/passkeys の mergeConfigFrom への依存 (上位キー単位マージ) は妥当か。
- 版 pin の検査 (composer.json の制約 + composer.lock の解決値) の実装は正しいか。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: passkey-config-hardening

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

本施策は教材設計そのものではなく、**現場作業者が撮影 PWA へログインし続けられること (認証手段の可用性・継続性)** を守る基盤改善である。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）。解析対象に **`config/` が含まれる**ため、
  新設する `config/passkeys.php` も level 10 を通す必要がある（`env()` は `mixed` を返すので必ず絞り込む）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- テストデータは必ず Factory で生成
- **DTO + JsonResource** パターン
- アーリーリターン推奨
- コードフォーマット: `composer fix`（Pint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260815-1111-passkey-config-hardening/conceptual-design.md`（Codex 概念レビュー Round 3 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | パスキー設定ブロックの明示（`config/passkeys.php` 新設） | `config/passkeys.php`（新規） | High |
| 2 | 設定事故ガード（`PasskeyConfigValidator` + `ProductionEnvGuard`） | `app/Support/PasskeyConfigValidator.php`（新規）, `app/Support/ProductionEnvGuard.php` | High |
| 3 | `.env.example` への提示 | `.env.example`, `tests/Architecture/EnvExampleInvariantTest.php` | High |
| 4 | `laravel/passkeys` の版 pin | `composer.json`, `composer.lock`, `tests/Architecture/PasskeyPackageContractTest.php` | High |
| 5 | 運用契約の記述（docs / AGENTS.md） | `docs/auth-security-mechanisms.md`, `AGENTS.md` | Medium |

**実装順序**: 1 → 2 → 3 → 4 → 5（2 は 1 の config キーに依存する。4 は独立だが lock 更新を伴うので最後にまとめる方が競合が少ない）。

---

## 施策 1: パスキー設定ブロックの明示（`config/passkeys.php` 新設）

### 変更箇所

- ファイル: `config/passkeys.php`（新規）

### 波及変更

- TypeScript 型定義: なし（設定はサーバ側のみ。クライアントは `resources/js/lib/passkeys.ts` が
  ブラウザ API を叩くだけで RP ID を知らない）
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Config/ConfigHardeningTest.php`（env 派生の固定）、
  `tests/Architecture/PasskeyPackageContractTest.php`（config cache 往復 + vendor 既定キーの残存）

### 現行コード

アプリ側に `config/passkeys.php` は**存在しない**。値は
`Laravel\Passkeys\PasskeysServiceProvider::register()` の
`mergeConfigFrom(__DIR__.'/../config/passkeys.php', 'passkeys')` により vendor 既定がそのまま入る:

```php
// vendor/laravel/passkeys/config/passkeys.php（抜粋）
'relying_party_id' => parse_url(config('app.url'), PHP_URL_HOST),
'allowed_origins'  => [config('app.url')],
'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),
'timeout' => 60000,
'guard' => 'web',
'middleware' => ['web'],
'management_middleware' => ['password.confirm'],
'throttle' => 'throttle:6,1',
'redirect' => '/',
```

読み出し側（vendor）:

```php
Passkeys::relyingPartyId()  => Config::string('passkeys.relying_party_id');   // null なら例外
Passkeys::allowedOrigins()  => Config::array('passkeys.allowed_origins', []); // 空なら RuntimeException
PasskeyAuthenticatable::getPasskeyUserHandle() => hash_hmac('sha256', "{table}|{id}", Config::string('passkeys.user_handle_secret'), binary: true);
```

### 変更後コード

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| パスキー (WebAuthn) の設定
|--------------------------------------------------------------------------
|
| laravel/passkeys の既定を上書きする**アプリ側の宣言**。
| Laravel\Passkeys\PasskeysServiceProvider の mergeConfigFrom は
| **上位キー単位の array_merge (アプリ側が勝つ)** なので、本ファイルに書いた
| キーだけが差し替わり、書いていないキー (timeout / guard / middleware /
| management_middleware / throttle / redirect) は vendor 既定が残る。
| この依存は tests/Architecture/PasskeyPackageContractTest が固定する。
|
| ⚠ **キー名は laravel/passkeys 0.2 系の契約**である。パッケージがキー名を変えると
| 本ファイルは**無言で効かなくなり既定へ戻る**。版 pin (composer.json の直接要求 +
| PasskeyPackageContractTest の解決版検査) がこの前提を守る。
|
| ⚠ **他の config ファイルを config() で読まない**。config は LoadConfiguration が
| ファイル名順に読み込むため、他ファイルへの依存は読み込み順に依存する。
| ここでは env() だけを見る (APP_KEY / APP_URL は config/app.php と同じ env を読む)。
|
| 既定値は APP_URL / APP_KEY からの導出で、同一オリジン PWA (v1 スコープ) では
| 通常 env の宣言なしで正しく動く。ただし **PASSKEYS_USER_HANDLE_SECRET だけは
| production で宣言が必須** (未宣言だと APP_KEY ローテートで登録済みパスキーが全件無効)。
| 検査は App\Support\PasskeyConfigValidator (ProductionEnvGuard 経由) が起動時に行う。
| 運用契約は docs/auth-security-mechanisms.md §5。
|
*/

$appUrl = parse_url((string) env('APP_URL', ''));

$appUrlScheme = is_array($appUrl) && is_string($appUrl['scheme'] ?? null) ? strtolower($appUrl['scheme']) : '';
$appUrlHost = is_array($appUrl) && is_string($appUrl['host'] ?? null) ? strtolower($appUrl['host']) : '';
$appUrlPort = is_array($appUrl) && is_int($appUrl['port'] ?? null) ? ':'.$appUrl['port'] : '';

// APP_URL の origin (scheme://host[:port])。path / query は落とす。
$derivedOrigin = ($appUrlScheme !== '' && $appUrlHost !== '')
    ? $appUrlScheme.'://'.$appUrlHost.$appUrlPort
    : '';

$declaredRelyingPartyId = env('PASSKEYS_RELYING_PARTY_ID');
$declaredRelyingPartyId = is_string($declaredRelyingPartyId) ? strtolower(trim($declaredRelyingPartyId)) : '';

$declaredOrigins = env('PASSKEYS_ALLOWED_ORIGINS');
$declaredOrigins = is_string($declaredOrigins) ? trim($declaredOrigins) : '';

// 宣言があれば CSV を **trim だけ**して保持する (空要素も落とさない)。
// 宣言が無い / 空文字なら APP_URL からの導出 1 件に倒す
// (env ファイルにキーだけ残す運用を壊さないため、空文字は「未宣言」と同じ扱い)。
$rawAllowedOrigins = $declaredOrigins !== ''
    ? array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', $declaredOrigins))
    : [$derivedOrigin];

$declaredUserHandleSecret = env('PASSKEYS_USER_HANDLE_SECRET');
$declaredUserHandleSecret = is_string($declaredUserHandleSecret) ? trim($declaredUserHandleSecret) : '';

return [
    /*
    | 身元の識別子 (relying party id)。パスキーはこの値に束縛され、
    | 一致するドメインでしか検証できない。host のみ (scheme / port を含めない)。
    | 未宣言なら APP_URL の host。
    */
    'relying_party_id' => $declaredRelyingPartyId !== '' ? $declaredRelyingPartyId : $appUrlHost,

    /*
    | 許可する接続元 (allowed origins)。ブラウザが申告した origin がこの列に無ければ
    | WebAuthn の手続きを受け付けない。`scheme://host[:port]` 形式。
    | framework (webauthn-lib) が読む正本で、**空要素を除いた列**。
    */
    'allowed_origins' => array_values(array_filter(
        $rawAllowedOrigins,
        static fn (string $v): bool => $v !== '',
    )),

    /*
    | 生の接続元列 (trim のみ、空要素も保持)。config 段で落ちた空要素を
    | 起動時 fail-fast で表面化させるために PasskeyConfigValidator が読む
    | (trustedproxy.raw_proxies と同じ役割)。
    */
    'raw_allowed_origins' => $rawAllowedOrigins,

    /*
    | 利用者ハンドルの導出鍵。hash_hmac の鍵として使われ、**変わると
    | 登録済みパスキーが全件無効になる**。未宣言なら APP_KEY に倒れるため、
    | APP_KEY ローテートがパスキー全件失効を意味してしまう。
    | production では宣言必須 (PasskeyConfigValidator が起動時に検査)。
    */
    'user_handle_secret' => $declaredUserHandleSecret !== ''
        ? $declaredUserHandleSecret
        : (string) env('APP_KEY', ''),

    /*
    | 導出鍵が **APP_KEY と独立して宣言されたか**。値の一致では判定しない
    | (既存パスキーを維持するために現行 APP_KEY と同じ値を意図して宣言する
    |  移行が正当なため)。config:cache 後も真偽値として残る。
    */
    'user_handle_secret_declared' => $declaredUserHandleSecret !== '',
];
```

### PHPStan適合チェック

- [x] `env()` の戻り値 `mixed` を `is_string` / `is_int` で必ず絞り込む
- [x] `parse_url()` の戻り値（`array|string|int|false|null`）を `is_array` で絞り込む
- [x] `array_filter` のコールバックは `static fn (string $v): bool`（`$rawAllowedOrigins` が `list<string>` であることが直前の `array_map` で確定している）
- [x] 配列を返す config ファイルなので DTO 化は対象外（Laravel の config 契約）

### テスト計画

- [ ] 新規: `tests/Feature/Config/ConfigHardeningTest.php` に追記（**既存 helper
      `evaluateConfigFileWithEnv()` が同ファイル内にあるため、そこへ足すのが唯一の正しい置き場**）
  - `PASSKEYS_*` 全て未設定 + `APP_URL=https://app.example.com/sub` →
    `relying_party_id === 'app.example.com'` / `allowed_origins === ['https://app.example.com']`
    （path が落ちること）/ `user_handle_secret_declared === false`
  - `APP_URL=http://localhost:8000` → `allowed_origins === ['http://localhost:8000']`（port が残ること）
  - `APP_URL=` （host 無し）→ `relying_party_id === ''` / `allowed_origins === []`（例外を投げず空に倒れる）
  - `PASSKEYS_RELYING_PARTY_ID=App.Example.COM` → 小文字化されて入る
  - `PASSKEYS_ALLOWED_ORIGINS='https://a.example.com, https://b.example.com'` →
    2 件（前後の空白が trim される）
  - `PASSKEYS_ALLOWED_ORIGINS='https://a.example.com,'`（末尾カンマ）→
    `allowed_origins` は 1 件 / `raw_allowed_origins` は 2 件で 2 件目が `''`（**落とした事実が残る**）
  - `PASSKEYS_USER_HANDLE_SECRET` 宣言時 → `user_handle_secret_declared === true` かつ値が入る
  - `PASSKEYS_USER_HANDLE_SECRET='   '`（空白のみ）→ `declared === false`（未宣言と同じ扱い）
- [ ] 既存テストの更新: なし（`config/passkeys.php` は新規のため既存アサートを壊さない）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **`mergeConfigFrom` は上位キー単位のマージ**であることに依存する。vendor がネスト構造へ変えた場合、
  本ファイルが持たないキーが消える可能性がある → 施策 4 の版 pin と、
  `PasskeyPackageContractTest` の「vendor 既定キーが残る」検査で二重に守る。
- `config:cache` 生成時に env が読まれる。**cache 生成後に env を変えても反映されない**のは
  Laravel 共通の前提であり本設計固有ではない（`route:cache` の運用要件と同じ扱い）。

---

## 施策 2: 設定事故ガード（`PasskeyConfigValidator` + `ProductionEnvGuard`）

### 変更箇所

- ファイル: `app/Support/PasskeyConfigValidator.php`（新規）
- ファイル: `app/Support/ProductionEnvGuard.php`（`violations()` に追記 + docblock の一覧に追記）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Unit/Support/PasskeyConfigValidatorTest.php`（新規）、
  `tests/Feature/Support/ProductionEnvGuardTest.php`（**既存 `beforeEach` の baseline に
  パスキーの有効値を追加する必要がある**。追加しないと既存 30 本超の
  「violations は 1 件」アサートが 2 件になって落ちる = 波及変更）

### 現行コード

```php
// app/Support/ProductionEnvGuard.php（末尾）
        // client IP の信頼境界 (TrustProxies allowlist) を起動時検証。
        $proxies = $this->stringList(config('trustedproxy.proxies', []));
        $rawProxies = $this->stringList(config('trustedproxy.raw_proxies', []), keepEmpty: true);
        try {
            (new TrustedProxiesConfigValidator)->validateForProduction($proxies, $rawProxies);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }
```

### 変更後コード

**新規 `app/Support/PasskeyConfigValidator.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * パスキー (WebAuthn) 設定 (config/passkeys.php) の production 起動時検証。
 *
 * `TrustedProxiesConfigValidator` / `TrustedHostsConfigValidator` と同形
 * (final / 純粋クラス / RuntimeException / ProductionEnvGuard から try-catch で写像)。
 *
 * 背景: パスキーは**単独でログインできる強い資格**であり、正しさが 3 つの設定値に依存する。
 * これらは既定で APP_URL / APP_KEY から導出されるため、設定事故が
 * **利用者が認証しようとした瞬間まで表面化しない** (登録はできるのに検証が全件失敗する、
 * あるいは APP_KEY ローテートで登録済みパスキーが全件無効になる)。
 * production では起動時に落として、デプロイ前に気づけるようにする。
 *
 * ⚠ 本 validator は **意図的にデプロイ時の破壊的変更**である
 * (TRUSTED_PROXIES と同性質)。`PASSKEYS_USER_HANDLE_SECRET` を宣言せずに
 * production を起動すると fail-fast する。既にパスキーが登録済みの環境では
 * **現行 APP_KEY の値をそのまま宣言すれば既存パスキーは維持される**
 * (宣言の有無で判定し、値の一致では判定しないため)。
 * 運用契約は docs/auth-security-mechanisms.md §5。
 *
 * 限界 (誇張しない): 本 validator は **書式と相互整合**しか見ない。
 * 「その host を本当に運用しているか」「TLS 証明書があるか」は検査できない。
 * また public suffix 判定は行わない (TrustedHostsConfigValidator と同じ理由)。
 */
final class PasskeyConfigValidator
{
    /** DNS 名の最大長 (末尾ドットを含まない) */
    private const MAX_DNS_NAME_LENGTH = 253;

    /** DNS ラベルの最大長 */
    private const MAX_DNS_LABEL_LENGTH = 63;

    /** 導出鍵の最小長 (短い値は typo / placeholder の可能性が高い) */
    private const MIN_USER_HANDLE_SECRET_LENGTH = 32;

    /**
     * @param  string  $relyingPartyId  config 通過後の身元の識別子 (host のみ)
     * @param  list<string>  $allowedOrigins  config 通過後の許可する接続元 (空要素除去済み)
     * @param  list<string>  $rawAllowedOrigins  生の接続元列 (trim のみ、空要素も保持)
     * @param  bool  $userHandleSecretDeclared  導出鍵が専用 env で宣言されたか
     * @param  string  $userHandleSecret  解決後の導出鍵
     *
     * @throws RuntimeException
     */
    public function validateForProduction(
        string $relyingPartyId,
        array $allowedOrigins,
        array $rawAllowedOrigins,
        bool $userHandleSecretDeclared,
        string $userHandleSecret,
    ): void {
        // 1. 身元の識別子。空 = APP_URL に host が無い (パスキーの手続きが実行時例外になる)。
        if ($relyingPartyId === '') {
            throw new RuntimeException(
                'Passkey relying party id is empty in production. '
                .'Set PASSKEYS_RELYING_PARTY_ID, or make sure APP_URL contains a host. '
                .'See docs/auth-security-mechanisms.md.'
            );
        }

        // 2. 身元の識別子は登録可能なドメイン名でなければならない。
        //    IP リテラル / localhost / 単一ラベルは WebAuthn の relying party id にできない。
        if (! $this->isDnsName($relyingPartyId) || ! str_contains($relyingPartyId, '.')) {
            throw new RuntimeException(sprintf(
                'Passkey relying party id "%s" is not a registrable domain name. '
                .'It must be a dotted DNS name (e.g. app.example.com), not an IP address, '
                .'"localhost" or a single label.',
                $relyingPartyId,
            ));
        }

        // 3. 接続元の宣言に空要素がある = 設定の書き損じ (末尾カンマ / 連続カンマ)。
        //    config 段で落ちた事実を黙って正規化せず、起動時に表面化させる。
        foreach ($rawAllowedOrigins as $raw) {
            if (trim($raw) === '') {
                throw new RuntimeException(
                    'PASSKEYS_ALLOWED_ORIGINS contains an empty entry '
                    .'(a stray or trailing comma). List each origin exactly once as '
                    .'"https://host[:port]".'
                );
            }
        }

        // 4. 接続元が 1 件も無いと vendor が手続き実行時に例外を投げる (起動時には落ちない)。
        if ($allowedOrigins === []) {
            throw new RuntimeException(
                'Passkey allowed origins are empty in production. '
                .'Set PASSKEYS_ALLOWED_ORIGINS, or make sure APP_URL contains a scheme and host.'
            );
        }

        foreach ($allowedOrigins as $origin) {
            // 5. 書式。scheme は小文字 https のみ (production の WebAuthn は TLS 必須)。
            //    path / query / fragment / userinfo / 末尾スラッシュ / 大文字 scheme を弾く。
            if (preg_match('#^https://([A-Za-z0-9.-]+)(?::(\d{1,5}))?$#', $origin, $m) !== 1) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin "%s" is invalid. '
                    .'Each origin must be "https://host[:port]" with no path, query or trailing slash '
                    .'(plain http is not allowed in production).',
                    $origin,
                ));
            }

            $host = strtolower($m[1]);
            $port = $m[2] ?? '';

            if (! $this->isDnsName($host)) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin "%s" has an invalid host. '
                    .'Each label must be 1-63 alphanumeric/hyphen characters and must not start or end with a hyphen.',
                    $origin,
                ));
            }

            if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin "%s" has an out-of-range port.',
                    $origin,
                ));
            }

            // 6. WebAuthn は「身元の識別子が接続元 host と一致するか、その上位ドメインである」
            //    ことを要求する。ここが食い違うと**全ての手続きが失敗する** (登録も検証も)。
            if ($host !== $relyingPartyId && ! str_ends_with($host, '.'.$relyingPartyId)) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin "%s" does not belong to the relying party id "%s". '
                    .'The origin host must equal the relying party id or be a subdomain of it, '
                    .'otherwise every passkey ceremony fails.',
                    $origin,
                    $relyingPartyId,
                ));
            }
        }

        // 7. 導出鍵は **APP_KEY から独立して宣言されている**こと。
        //    未宣言だと APP_KEY に倒れ、鍵ローテートで登録済みパスキーが全件無効になる。
        if (! $userHandleSecretDeclared) {
            throw new RuntimeException(
                'PASSKEYS_USER_HANDLE_SECRET is not set in production. '
                .'Without it the passkey user handle is derived from APP_KEY, so rotating APP_KEY '
                .'silently invalidates every registered passkey. '
                .'When migrating an environment that already has passkeys, declare the current APP_KEY value. '
                .'See docs/auth-security-mechanisms.md.'
            );
        }

        if (strlen($userHandleSecret) < self::MIN_USER_HANDLE_SECRET_LENGTH) {
            throw new RuntimeException(sprintf(
                'PASSKEYS_USER_HANDLE_SECRET is shorter than %d characters. '
                .'Use a long random value (e.g. php -r "echo bin2hex(random_bytes(32));").',
                self::MIN_USER_HANDLE_SECRET_LENGTH,
            ));
        }
    }

    /**
     * DNS 名として妥当か (ラベル単位で検査する)。
     *
     * 包含正規表現 `[A-Za-z0-9.-]+` だけでは `-example.com` / `example..com` /
     * `example.com.` が通ってしまうため、ドットで分割して 1 ラベルずつ見る。
     * IP リテラルはここで false になる (`192.0.2.1` は全ラベルが数字だが、
     * 呼び出し側が filter_var で先に弾く)。
     */
    private function isDnsName(string $host): bool
    {
        if ($host === '' || strlen($host) > self::MAX_DNS_NAME_LENGTH) {
            return false;
        }

        // IPv4 / IPv6 リテラルは relying party id にも origin host にも使えない。
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > self::MAX_DNS_LABEL_LENGTH) {
                return false;   // 空ラベル = 連続ドット / 先頭ドット / 末尾ドット
            }
            if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?$/', $label) !== 1) {
                return false;   // ハイフン開始 / ハイフン終了 / 不正文字
            }
        }

        return true;
    }
}
```

**`app/Support/ProductionEnvGuard.php` への追記**（`return $errors;` の直前）:

```php
        // パスキー (WebAuthn) の身元 / 接続元 / 利用者ハンドル導出鍵を起動時検証。
        // **キルスイッチが有効なときだけ**検査する (機能を止めている環境に設定を要求しない)。
        // 有効化点は config/fortify.php の Features::passkeys([...]) ただ 1 箇所。
        if (Features::enabled(Features::passkeys())) {
            $relyingPartyIdValue = config('passkeys.relying_party_id');
            $relyingPartyId = is_string($relyingPartyIdValue) ? $relyingPartyIdValue : '';
            $allowedOrigins = $this->stringList(config('passkeys.allowed_origins', []));
            $rawAllowedOrigins = $this->stringList(config('passkeys.raw_allowed_origins', []), keepEmpty: true);
            $userHandleSecretValue = config('passkeys.user_handle_secret');
            $userHandleSecret = is_string($userHandleSecretValue) ? $userHandleSecretValue : '';
            $userHandleSecretDeclared = config('passkeys.user_handle_secret_declared') === true;

            try {
                (new PasskeyConfigValidator)->validateForProduction(
                    $relyingPartyId,
                    $allowedOrigins,
                    $rawAllowedOrigins,
                    $userHandleSecretDeclared,
                    $userHandleSecret,
                );
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
```

docblock の検査項目一覧にも 1 行足す:

```
 * - パスキー設定 (身元の識別子 / 許可する接続元 / 利用者ハンドルの導出鍵。
 *   書式・相互整合・導出鍵の独立宣言。Features::passkeys() 有効時のみ)
```

`use Laravel\Fortify\Features;` の import を追加する。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`validateForProduction(): void` / `isDnsName(): bool`）
- [x] null 安全（`config()` の `mixed` は `is_string` / `=== true` で絞り込む。
      `stringList()` は既存 helper で `list<string>` を返す）
- [x] DTO を返している（本 validator は例外を投げるだけで値を返さない = 既存 2 validator と同形）
- [x] Generics の型パラメータが正しい（`list<string>` を phpdoc で宣言）
- [x] `preg_match` の `$m` は `array<int, string>` として扱い、`$m[2] ?? ''` で null 安全

### テスト計画

- [ ] 新規: `tests/Unit/Support/PasskeyConfigValidatorTest.php`
  - 正常系: `('app.example.com', ['https://app.example.com'], ['https://app.example.com'], true, str_repeat('a', 32))` が例外を投げない
  - 正常系: 接続元が下位ドメイン（`https://pwa.app.example.com`）でも通る
  - 正常系: port 付き（`https://app.example.com:8443`）が通る
  - 検査 1: 身元の識別子が空 → `'relying party id is empty'`
  - 検査 2: `localhost` / `192.0.2.1` / `-example.com` / `example..com` / `example.com.` /
    `exam ple.com` を **dataset** で回して全て reject（**DNS ラベル検査の負のコントロール**）
  - 検査 3: `rawAllowedOrigins` に空要素 → `'empty entry'`（`allowedOrigins` 側が有効でも落ちること）
  - 検査 4: 接続元が空 → `'allowed origins are empty'`
  - 検査 5: `http://app.example.com` / `HTTPS://app.example.com` / `https://app.example.com/` /
    `https://app.example.com/path` / `https://user@app.example.com` / `https://app.example.com?x=1`
    を dataset で回して全て reject
  - 検査 5: port が `0` / `70000` → reject
  - 検査 6: `https://evil.example.net`（RP ID = `app.example.com`）→ `'does not belong to'`
  - 検査 6: `https://notapp.example.com` は `.app.example.com` で終わらないので reject
    （**接尾辞一致だけの実装なら通ってしまう境界。必ずテストする**）
  - 検査 7: `declared = false` → `'is not set in production'`（値が 32 文字以上でも落ちること）
  - 検査 7: `declared = true` かつ 31 文字 → 長さ違反
  - **検査の順序**: 身元の識別子が空 かつ 導出鍵も未宣言のとき、メッセージが
    「身元の識別子」であること（最初の違反で throw する既存 2 validator と同じ挙動）
- [ ] 既存テスト更新: `tests/Feature/Support/ProductionEnvGuardTest.php`
  - `beforeEach` の baseline に 5 キーを追加
    （`passkeys.relying_party_id` = `'app.example.com'` /
    `allowed_origins` = `['https://app.example.com']` / `raw_allowed_origins` = 同上 /
    `user_handle_secret` = 32 文字 / `user_handle_secret_declared` = `true`）
  - 新規: 導出鍵が未宣言なら violation が 1 件増える
  - 新規: 接続元が RP ID と不整合なら violation
  - 新規: **`Features::passkeys()` を無効にすると、上の不正設定でも violation が 0 件になる**
    （キルスイッチ時に設定を要求しないことの固定。`config(['fortify.features' => [...]])` で
    passkeys を外して検証する）
  - 新規: `allowed_origins` に非 string が混ざった場合は `stringList()` が落とすため
    空扱いになり violation になること（**沈黙しないことの記録**。
    ただし有効値と非 string が併存する場合は非 string が黙って落ちる = 保証しない範囲として docblock に明記）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **本番の起動を止める条件が増える**（意図した破壊的変更）。既存デプロイがあると
  `PASSKEYS_USER_HANDLE_SECRET` 未設定で起動しなくなる → 施策 3・5 の `.env.example` / docs / AGENTS.md で
  「初回デプロイ前に設定が要る」ことを明記して緩和する。
- `Features::enabled()` は `config('fortify.features')` を読む静的メソッドで、
  `ProductionEnvGuard` は `AppServiceProvider::boot()` から呼ばれる。**config は boot 時点で確定済み**なので
  順序上の問題は無い（Fortify の provider boot に依存しない）。
- production 以外では**何も検査しない**ため、local で誤設定しても気づけない。
  これは既存の `ProductionEnvGuard` 全体の性質であり、本施策で変えない（誇張しない）。

---

## 施策 3: `.env.example` への提示

### 変更箇所

- ファイル: `.env.example`（L200-204 付近の「パスキーは専用の env を持たない」段落を差し替え）
- ファイル: `tests/Architecture/EnvExampleInvariantTest.php`（提示の固定）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Architecture/EnvExampleInvariantTest.php`

### 現行コード

```
# パスキー (WebAuthn) は専用の env を持たない。Fortify が APP_URL から
# relying party id (ホスト) と allowed origins ([APP_URL]) を、user handle secret を
# APP_KEY から導出する (同一オリジン PWA 前提)。
# ⚠ APP_KEY をローテートすると既存パスキーの user handle が変わり全件無効になる。
#    運用契約は docs/auth-security-mechanisms.md §5 パスキー (WebAuthn) の「運用上の注意」。
```

### 変更後コード

```
# パスキー (WebAuthn) の設定 (config/passkeys.php)。
# 利用者ハンドルの導出鍵。**production では宣言が必須** (未宣言だと起動時に fail-fast する)。
# 未宣言のときは APP_KEY から導出されるため、APP_KEY をローテートすると
# 登録済みパスキーが全件無効になる (利用者から見ると「昨日まで使えた生体認証が通らない」)。
# 32 文字以上のランダム値を生成して固定する: php -r "echo bin2hex(random_bytes(32));"
# 既にパスキーが登録されている環境を移行する場合は **現行 APP_KEY の値をそのまま**入れると
# 既存パスキーを維持できる (以後 APP_KEY のローテートはパスキーに影響しなくなる)。
# 運用手順は docs/auth-security-mechanisms.md §5。
PASSKEYS_USER_HANDLE_SECRET=
# 身元の識別子 (RP ID) と許可する接続元。未宣言なら APP_URL から導出する
# (RP ID = APP_URL の host、接続元 = scheme://host[:port])。
# 同一オリジン PWA 前提のため通常は宣言不要。別ホストから撮影 PWA を配信するときだけ宣言する。
# 接続元は CSV で、各 host は RP ID と一致するか RP ID の下位ドメインであること。
# PASSKEYS_RELYING_PARTY_ID=
# PASSKEYS_ALLOWED_ORIGINS=
```

**注意**: `PASSKEYS_RELYING_PARTY_ID` / `PASSKEYS_ALLOWED_ORIGINS` は
**コメントアウトのまま**提示する（空文字で置くと「宣言したが空」と紛らわしいため。
`GTM_CONTAINER_ID` と同じ既存の作法）。`PASSKEYS_USER_HANDLE_SECRET` だけは
**production で必須**なので、値なしのキーとして置き、テストで提示を固定する。

`tests/Architecture/EnvExampleInvariantTest.php` への追記:

```php
/*
 * パスキーの利用者ハンドル導出鍵。production で未宣言だと起動時 fail-fast するため
 * (App\Support\PasskeyConfigValidator)、.env.example に必ず提示して
 * 「設定し忘れてデプロイが落ちる」事故を減らす (TRUSTED_PROXIES と同じ理由)。
 */

test('.env.example に PASSKEYS_USER_HANDLE_SECRET が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('PASSKEYS_USER_HANDLE_SECRET=');
});
```

### PHPStan適合チェック

- [x] tests/ は PHPStan の解析対象外（`phpstan.neon` の paths は `app` / `config` / `database` / `routes`）。
      ただし既存テストの書式（`expect($contents)->toBeString()` + `/** @var string */`）にそのまま揃える

### テスト計画

- [ ] 新規: 上記 `.env.example に PASSKEYS_USER_HANDLE_SECRET が含まれる`
- [ ] 既存テスト `コミット対象 env ファイルに自己参照・前方参照の ${VAR} が無い` が引き続き green
      （追記行に `${VAR}` を書かない。`PASSKEYS_USER_HANDLE_SECRET=` は空値なので影響なし）

### リスク

- コメントアウトしたキーは `collectUnresolvedEnvRefs()` の走査対象外（`#` 始まりは skip される）ため、
  既存テストへの影響はない。

---

## 施策 4: `laravel/passkeys` の版 pin

### 変更箇所

- ファイル: `composer.json`（`require` に直接要求を追加）
- ファイル: `composer.lock`（`composer require` により content-hash と packages 情報が更新される）
- ファイル: `tests/Architecture/PasskeyPackageContractTest.php`（検査 2 本を追加）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Architecture/PasskeyPackageContractTest.php`
- **CI**: `pnpm run audit:gate`（composer audit）が走る。解決版は変わらない（v0.2.1 のまま）ので
  advisory 判定に影響しない

### 現行コード

```jsonc
// composer.json（抜粋）
"laravel/fortify": "^1.37",
"laravel/framework": "^13.8",
"laravel/mcp": "^0.8.0",
"laravel/passport": "^13.7",
```

`laravel/passkeys` は `laravel/fortify v1.37.2` の要求（`^0.2.0`）として**推移的にのみ**入り、
解決値は **v0.2.1**。一方でアプリは `Laravel\Passkeys\*` を
`PasskeyServiceProvider` / `Passkey` モデル / Response 4 本 / `SelfScopedPasskeyBinder` /
契約検査など**多数のファイルで直接 import している**。

### 変更後コード

```jsonc
"laravel/mcp": "^0.8.0",
"laravel/passkeys": "^0.2.1",
"laravel/passport": "^13.7",
```

実装手順（worktree 内で実行し、`composer.json` と `composer.lock` を**必ず同じコミットに含める**）:

```bash
composer require "laravel/passkeys:^0.2.1" --no-scripts
```

解決版は既に v0.2.1 のため**依存は 1 つも動かない**（lock の content-hash と
`laravel/passkeys` の位置づけだけが更新される）。

`tests/Architecture/PasskeyPackageContractTest.php` への追記:

```php
/*
 * 版 pin。laravel/passkeys は **0.x** であり semver の後方互換保証が無い
 * (0.3.0 で設定キー名・contract・route 名が予告なく変わりうる)。
 * 本ファイルの他の検査 9 本は **v0.2.1 に対して検証済み**であり、
 * その前提が黙って動かないように 2 つの側面を固定する:
 *
 *   - composer.json の直接要求 = 「直接 import しているので直接要求する」設計意思と許容範囲。
 *     これが無いと laravel/fortify の推移要求が緩んだ瞬間に 0.3 系が無言で入る
 *     (aicue はかつてこの状態だった)。
 *   - composer.lock の解決値 = **いま実際に動いている版**。
 *     制約だけ見ても、lock が手で書き換えられた / platform 設定で別版が入った場合を捕まえられない。
 *
 * 0.2.x を外れるときは、本ファイルの契約検査 (route 名 7 本 / confirmPassword /
 * limiter / モデル差し替え / Response contract 4 本 / binder) と config/passkeys.php の
 * キー名を再確認してから、この pin を更新すること。
 */

/** @return array<string, mixed> composer.json の require ブロック */
function composerRequireBlock(): array
{
    $raw = file_get_contents(base_path('composer.json'));
    expect($raw)->toBeString();
    /** @var string $raw */
    $decoded = json_decode($raw, true);
    expect($decoded)->toBeArray();
    /** @var array<string, mixed> $decoded */
    $require = $decoded['require'] ?? null;
    expect($require)->toBeArray();

    /** @var array<string, mixed> $require */
    return $require;
}

/** composer.lock の解決版 (例 "v0.2.1") を返す */
function lockedPackageVersion(string $name): ?string
{
    $raw = file_get_contents(base_path('composer.lock'));
    expect($raw)->toBeString();
    /** @var string $raw */
    $decoded = json_decode($raw, true);
    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    $packages = $decoded['packages'] ?? [];
    expect($packages)->toBeArray();

    /** @var array<int, array<string, mixed>> $packages */
    foreach ($packages as $package) {
        if (($package['name'] ?? null) === $name && is_string($package['version'] ?? null)) {
            return $package['version'];
        }
    }

    return null;
}

test('composer.json が laravel/passkeys を直接要求する (直接 import しているため)', function (): void {
    $require = composerRequireBlock();

    expect($require)->toHaveKey(
        'laravel/passkeys',
        'laravel/passkeys を直接 import しているのに直接要求が無い。'
        .'laravel/fortify の推移要求が緩むと 0.3 系が無言で入る'
    );

    $constraint = $require['laravel/passkeys'];
    expect($constraint)->toBeString();
    /** @var string $constraint */
    expect(str_starts_with($constraint, '^0.2.'))->toBeTrue(
        "laravel/passkeys の制約が 0.2 系を外れている: {$constraint}"
    );
});

test('composer.lock の laravel/passkeys が 0.2 系 (契約検査の検証済み範囲)', function (): void {
    $version = lockedPackageVersion('laravel/passkeys');

    expect($version)->toBeString('composer.lock に laravel/passkeys が無い');
    /** @var string $version */
    expect(str_starts_with(ltrim($version, 'v'), '0.2.'))->toBeTrue(
        "laravel/passkeys の解決版が 0.2 系を外れている: {$version}。"
        .'本ファイルの契約検査と config/passkeys.php のキー名を再確認してから pin を更新すること'
    );
});
```

**判断（概念設計の論点への回答）**: **composer.lock と composer.json の両方**を見る。
`composer.json` は「直接依存という設計意思と許容範囲」、`composer.lock` は
「契約検査が実際に検証した解決版」を固定する。どちらか一方だけでは、
(a) 直接要求が消えたのに lock がたまたま 0.2 のまま、
(b) 制約は 0.2 なのに lock が別版、のどちらかを取り逃がす。
**版番号の完全一致（`v0.2.1` ちょうど）では固定しない** — patch 更新のたびにテストが赤くなり、
pin を惰性で書き換える運用に堕ちる。0.x で契約が壊れる境界は **minor** なのでそこを固定する。

### PHPStan適合チェック

- [x] tests/ は解析対象外だが、`json_decode` の戻り値は `expect()->toBeArray()` +
      `/** @var */` で明示的に絞り込む（既存 `PhpstanWrapperInvariantTest` / `GlobalTestLockInventoryTest` と同じ作法）

### テスト計画

- [ ] 新規: `composer.json が laravel/passkeys を直接要求する`
- [ ] 新規: `composer.lock の laravel/passkeys が 0.2 系`
- [ ] 既存テスト（契約検査 9 本）が引き続き green（依存は動かないので変化しないはず）
- [ ] `composer.json` 変更後に `composer validate`（`composer.lock` との整合）が通ること

### リスク

- `composer require` が `composer.lock` の広範な再生成を起こす可能性がある。
  **解決版が変わっていないこと**（`laravel/passkeys` = v0.2.1、他パッケージの version 差分が無いこと）を
  `git diff composer.lock` で確認してからコミットする。差分が広がった場合は
  `composer update laravel/passkeys --lock` などで最小差分に留める。
- worktree 運用ルール: `composer require` は task branch 上で実行可だが、
  変更した `composer.json` / `composer.lock` を**必ずコミットする**（未コミットのまま teardown すると失われる）。

---

## 施策 5: 運用契約の記述（docs / AGENTS.md）

### 変更箇所

- ファイル: `docs/auth-security-mechanisms.md`（§5 パスキー「運用上の注意」）
- ファイル: `AGENTS.md`（運用要件のブロック。`TRUSTED_PROXIES` (T108) の隣）

### 波及変更

- テストファイル: なし（docs は既存の doc 同期テストの対象外。
  `TrustedProxiesRunbookTest` に相当する pin は**新設しない** = 今必要なものだけ作る）

### 現行コード

```markdown
### 運用上の注意

- 設定は `APP_URL` から導出される (relying party id = ホスト、allowed origins = `[APP_URL]`)。
  同一オリジン PWA 前提のため専用 env は持たない。
- **`APP_KEY` をローテートすると user handle (`hash_hmac` の鍵が `APP_KEY`) が変わり、
  登録済みパスキーが全件無効になる**。鍵ローテートを行う場合は
  `PASSKEYS_USER_HANDLE_SECRET` 相当の固定値を `config/passkeys.php` に持たせる設計変更が必要。
- 未認証の challenge 発行 (`GET /passkeys/login/options`) は `throttle:passkeys` (10/min) で絞る。
```

### 変更後コード

```markdown
### 運用上の注意

- 設定の正本は **`config/passkeys.php`**（身元の識別子 = relying party id /
  許可する接続元 = allowed origins / 利用者ハンドルの導出鍵）。
  身元の識別子と接続元は宣言が無ければ `APP_URL` から導出する
  (RP ID = host、接続元 = `scheme://host[:port]`)。同一オリジン PWA 前提のため通常は宣言不要。
- **production では `PASSKEYS_USER_HANDLE_SECRET` の宣言が必須**
  (未宣言 / 32 文字未満、および設定の書式・相互整合の違反は `App\Support\PasskeyConfigValidator`
  が `ProductionEnvGuard` 経由で起動時 fail-fast する = **初回デプロイ前に設定が要る破壊的変更**)。
  検査は `Features::passkeys()` が有効なときだけ走る。
- 導出鍵を宣言しないと利用者ハンドル (`hash_hmac` の鍵) が `APP_KEY` に倒れ、
  **`APP_KEY` をローテートした瞬間に登録済みパスキーが全件無効になる**。
  既にパスキーが登録されている環境では、`PASSKEYS_USER_HANDLE_SECRET` に
  **現行 `APP_KEY` の値をそのまま**宣言すれば既存パスキーは維持される
  (検査は「宣言されているか」を見ており、値が `APP_KEY` と同じかどうかは見ない)。
  以後 `APP_KEY` のローテートはパスキーに影響しない。
- 起動時検査が見るのは**書式と相互整合まで**である。「その host を実際に運用しているか」
  「証明書があるか」は検査できない (誇張しない)。
- 設定キー名は `laravel/passkeys` **0.2 系の契約**であり、キー名が変わると
  `config/passkeys.php` は**無言で効かなくなり既定へ戻る**。
  版は `composer.json` の直接要求と `PasskeyPackageContractTest` の解決版検査で pin する。
- 未認証の challenge 発行 (`GET /passkeys/login/options`) は `throttle:passkeys` (10/min) で絞る。
```

`AGENTS.md` の運用要件（`TRUSTED_PROXIES` の段落の直後）へ追記:

```markdown
> **運用要件 (パスキー)**: production は `PASSKEYS_USER_HANDLE_SECRET` の**明示宣言が必須**
> (未宣言 / 32 文字未満 / 身元の識別子・許可する接続元の書式不正・相互不整合は
> `PasskeyConfigValidator` が `ProductionEnvGuard` 経由で起動時 fail-fast する
> = **初回デプロイ前に設定が要る破壊的変更**)。宣言しないと利用者ハンドルが `APP_KEY` 由来になり、
> **`APP_KEY` ローテートで登録済みパスキーが全件無効**になる。既にパスキーがある環境は
> 現行 `APP_KEY` の値をそのまま宣言すれば維持できる。運用手順は
> `docs/auth-security-mechanisms.md` §5。
```

### PHPStan適合チェック

- [x] 対象外（ドキュメントのみ）

### テスト計画

- [ ] docs の記述に対応する**振る舞い**は施策 2 のテストが担保する
      （docs 専用の同期テストは新設しない = 今必要なものだけ作る）
- [ ] `.env.example` の提示は施策 3 のテストが固定する

### リスク

- AGENTS.md の運用要件が 1 つ増えるが、**デプロイ基盤が無い**ため守るのは人手のままである
  （AGENTS.md の既存注記と同じ扱い。存在しない基盤のための preflight 機構は作らない）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `composer.json` / `composer.lock` / `AGENTS.md` / `.env.example` という**他の設計と衝突しやすい共有ファイル**を触るため。特に `composer.lock` は行単位マージが実質不可能で、並行 worktree の同時変更は必ず手当てが要る。また 5 施策が「config → validator → env → 依存 pin → docs」と一本の依存線で連なっており、部分適用すると本番起動が落ちる状態（validator だけ入って config が無い等）を作れてしまう |
| 競合リスク | 同時進行の他 4 設計が `composer.lock` / `AGENTS.md` / `docs/` を触ると衝突する。`app/Support/ProductionEnvGuard.php` は他施策が触る可能性が低い。マージ順を後ろに回し、main へのマージ直前に rebase する |

## 使命・禁止事項チェック（最終確認）

- 使命への寄与: 撮影 PWA の主戦場はスマホであり、パスキーは現場作業者が最も摩擦なく使えるログイン手段。
  設定事故によるログイン不能を**デプロイ前**に止めることで、現場が使い続けられる状態を守る
- 禁止事項 1（テストなし実装）: 全 5 施策にテストを割り当て済み（施策 5 のみ docs で、
  対応する振る舞いは施策 2 のテストが担保）
- 禁止事項 2（PHPStan widen）: `config/` は解析対象。`env()` / `parse_url()` の `mixed` は
  `is_string` / `is_int` / `is_array` で絞る（`@phpstan-ignore` を使わない）
- 禁止事項 4（`response()->json()` 直書き）: 該当なし（HTTP 応答を触らない）
- 思考原則 1（フレームワークのレンジ内）: vendor の config キーと `PASSKEYS_USER_HANDLE_SECRET` という
  **パッケージが既に持つ env 名**をそのまま使い、独自の設定機構を作らない
- 思考原則 2（今必要なものだけ）: 事故が確認されている 3 値だけを扱い、
  `timeout` / `guard` / `middleware` 等は vendor 既定のまま。パスキー専用 runbook も新設しない
- 思考原則 3（後方互換の並走を残さない）: 期限付きの移行 flag を作らず、
  判定式そのものを「宣言の有無」に正すことで移行と恒久状態を同じ形にした


## 関連する現行コード

### app/Support/ProductionEnvGuard.php

```php
<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * production env に必要な必須項目を検査し、違反があれば fail-fast する SSOT。
 *
 * AppServiceProvider::boot() (production 起動時) と production:preflight コマンドの
 * 双方から参照される。検査項目:
 * - APP_KEY / CIPHERSWEET_KEY 非空 (暗号化キー未設定の起動防止)
 * - STRIPE_WEBHOOK_SECRET 非空 (Cashier の署名検証 silent skip 防止)
 * - SESSION_SECURE_COOKIE=true (HTTPS Cookie 必須)
 * - APP_DEBUG=false (stack trace / 設定露出防止)
 * - SECURITY_HSTS_ENABLED / SECURITY_CSP_ENABLED=true (セキュリティヘッダ必須)
 * - DEBUG_LOGIN_USER / DEBUG_LOGIN_PASSWORD が空 (local 専用機構の誤投入防止)
 * - TESTING_FAKE_EXTERNALS=false (Stripe 外部 fake の本番混入防止)
 * - TESTING_FAKE_LLM=false (LLM fake の本番混入防止)
 * - TESTING_FAKE_STORAGE=false (storage fake の本番混入防止)
 * - TrustHosts allowlist (Host header injection 防御の allowlist 非空・書式)
 * - TrustProxies allowlist (client IP / X-Forwarded-Proto の信頼境界。未宣言・`*`・
 *   REMOTE_ADDR・書式不正を拒否。プロキシ無し構成は `none` の明示宣言を要求する)
 */
class ProductionEnvGuard
{
    /**
     * production env に必要な必須項目を検査し、違反メッセージのリストを返す。
     *
     * @return list<string>
     */
    public function violations(): array
    {
        $errors = [];

        $appKeyValue = config('app.key');
        $appKey = is_string($appKeyValue) ? $appKeyValue : '';
        if ($appKey === '') {
            $errors[] = 'APP_KEY is required in production.';
        }

        $cipherKeyValue = config('ciphersweet.providers.string.key');
        $cipherKey = is_string($cipherKeyValue) ? $cipherKeyValue : '';
        if ($cipherKey === '') {
            $errors[] = 'CIPHERSWEET_KEY is required in production (PII encryption key).';
        }

        $stripeSecretValue = config('cashier.webhook.secret');
        $stripeSecret = is_string($stripeSecretValue) ? $stripeSecretValue : '';
        if ($stripeSecret === '') {
            $errors[] = 'STRIPE_WEBHOOK_SECRET is required in production '
                .'(Cashier silently skips signature verification when missing).';
        }

        if (config('session.secure') !== true) {
            $errors[] = 'SESSION_SECURE_COOKIE must be true in production '
                .'(current: '.var_export(config('session.secure'), true).').';
        }

        // APP_DEBUG=true は本番で stack trace / env 露出を招くため禁止。
        if (config('app.debug') === true) {
            $errors[] = 'APP_DEBUG must be false in production '
                .'(true leaks stack traces and configuration via error pages).';
        }

        if (config('security.hsts.enabled') !== true) {
            $errors[] = 'SECURITY_HSTS_ENABLED must be true in production.';
        }

        if (config('security.csp.enabled') !== true) {
            $errors[] = 'SECURITY_CSP_ENABLED must be true in production.';
        }

        $debugUserValue = config('debug.login.user');
        $debugPasswordValue = config('debug.login.password');
        $debugUser = is_string($debugUserValue) ? $debugUserValue : '';
        $debugPassword = is_string($debugPasswordValue) ? $debugPasswordValue : '';
        if ($debugUser !== '' || $debugPassword !== '') {
            $errors[] = 'DEBUG_LOGIN_USER and DEBUG_LOGIN_PASSWORD must be empty in production '
                .'(both are local-dev only; presence indicates dangerous misconfiguration).';
        }

        // 外部 fake flag は非本番専用。production で true なら課金 (Stripe) が fake に
        // 差し替わり得る危険設定のため fail-fast する (FakeExternalsServiceProvider の
        // allowlist で bind 自体は起きないが、設定として存在すること自体を拒否する)
        if (config('testing.fake_externals') === true) {
            $errors[] = 'TESTING_FAKE_EXTERNALS must be false in production '
                .'(external fakes must never be enabled in production).';
        }

        // LLM fake は production で real LLM を潰すため禁止 (fake_externals と同じ fail-secure)。
        if (config('testing.fake_llm') === true) {
            $errors[] = 'TESTING_FAKE_LLM must be false in production '
                .'(LLM fake must never be enabled in production).';
        }

        // storage fake は production で実ストレージを潰し得るため禁止。
        if (config('testing.fake_storage') === true) {
            $errors[] = 'TESTING_FAKE_STORAGE must be false in production '
                .'(storage fake must never be enabled in production).';
        }

        // Host header injection 防御の TrustHosts allowlist を起動時検証。
        // 純粋クラス TrustedHostsConfigValidator に委譲し、throw を violation メッセージへ写像する。
        $exact = $this->stringList(config('trusted_hosts.exact_hosts', []));
        $wildcard = $this->stringList(config('trusted_hosts.wildcard_suffixes', []));
        $rawWildcards = $this->stringList(config('trusted_hosts.raw_wildcard_suffixes', []), keepEmpty: true);
        try {
            (new TrustedHostsConfigValidator)->validateForProduction($exact, $wildcard, $rawWildcards);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        // client IP の信頼境界 (TrustProxies allowlist) を起動時検証。
        // 未宣言だと XFF 偽装 or hop 取りこぼしによる自己 DoS のどちらかに倒れるため、
        // production では「hop を明示宣言する」ことを起動条件にする (audit-cycle-2 High-2)。
        $proxies = $this->stringList(config('trustedproxy.proxies', []));
        $rawProxies = $this->stringList(config('trustedproxy.raw_proxies', []), keepEmpty: true);
        try {
            (new TrustedProxiesConfigValidator)->validateForProduction($proxies, $rawProxies);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    /**
     * production 起動時に違反があれば例外で fail-fast。
     */
    public function enforce(): void
    {
        $errors = $this->violations();
        if ($errors !== []) {
            throw new RuntimeException(
                "Production env baseline violations:\n- ".implode("\n- ", $errors)
            );
        }
    }

    /**
     * config 値を string list へ正規化する (非 string 要素を除外)。
     *
     * @return list<string>
     */
    private function stringList(mixed $value, bool $keepEmpty = false): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            if (! $keepEmpty && $item === '') {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }
}

```

### app/Support/TrustedProxiesConfigValidator.php

```php
<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * TrustProxies allowlist (config/trustedproxy.php) の production 起動時検証。
 *
 * `TrustedHostsConfigValidator` と同形 (final / 純粋クラス / RuntimeException)。
 * 検証ロジックを純粋クラスに切り出して unit test で直接検証可能にする。
 *
 * 背景: かつて `trustProxies(at: '*')` だった。全アドレスを trusted proxy 扱いにすると
 * `$request->ip()` が X-Forwarded-For の最左 = **クライアントが自由に書ける値**になり、
 * IP ベースの rate limit / reCAPTCHA / 監査ログがすべて無効化される (audit-cycle-2 High-2)。
 * production では「hop を明示宣言する」ことを起動条件にする。
 *
 * ⚠ 本 validator は **意図的にデプロイ時の破壊的変更**である。`TRUSTED_PROXIES` を
 * 宣言せずに production を起動すると fail-fast する。rollback は `at: '*'` へ戻すことでは
 * なく、正しい CIDR を設定すること。運用契約は docs/trusted-proxies-runbook.md。
 */
final class TrustedProxiesConfigValidator
{
    /**
     * @param  list<string>  $proxies  検証通過後の proxy 列 (config 通過後)
     * @param  list<string>  $rawProxies  生 token (空白 trim のみ、format validation 前)
     *
     * @throws RuntimeException
     */
    public function validateForProduction(array $proxies, array $rawProxies): void
    {
        $tokens = array_values(array_filter(
            array_map('trim', $rawProxies),
            static fn (string $v): bool => $v !== '',
        ));

        // 1. 全アドレス信頼は無条件で拒否する (これが High-2 の元の状態)。
        //    `*` / `**` だけでなく prefix 長 0 の CIDR (`0.0.0.0/0` / `::/0`) も同値。
        //    後者は書式として正当な CIDR なので、書式検査だけでは通り抜ける。
        foreach ($tokens as $token) {
            if (TrustedProxyToken::isAllAddresses($token)) {
                throw new RuntimeException(sprintf(
                    'TRUSTED_PROXIES contains "%s". Trusting every address lets clients forge '
                    .'X-Forwarded-For (client IP, rate limits and audit logs become attacker-controlled). '
                    .'Enumerate the actual proxy hops as IP/CIDR instead.',
                    $token,
                ));
            }
        }

        // 2. `none` sentinel (プロキシ無し構成の明示宣言) を **書式検査より先に**処理する。
        //    順序が逆だと `none` 自身が「config 段で落ちた不正値」として reject される。
        if (in_array(TrustedProxyToken::NONE, $tokens, true)) {
            if (count($tokens) !== 1) {
                throw new RuntimeException(
                    'TRUSTED_PROXIES declares "none" together with other values. '
                    .'"none" means "there is no proxy in front of this app" and must be declared alone.'
                );
            }
            if ($proxies !== []) {
                throw new RuntimeException(
                    'TRUSTED_PROXIES declares "none" but the resolved proxy list is not empty. '
                    .'This indicates a configuration inconsistency (check config/trustedproxy.php).'
                );
            }

            return; // プロキシ無し構成の明示宣言 = 正常
        }

        // 3. production で REMOTE_ADDR (直接接続元の一括信頼) は許さない。
        if (in_array(TrustedProxyToken::REMOTE_ADDR, $tokens, true)) {
            throw new RuntimeException(
                'TRUSTED_PROXIES contains "REMOTE_ADDR". Trusting the immediate peer unconditionally '
                .'is a local-development convenience and must not be used in production. '
                .'Enumerate the actual proxy hops as IP/CIDR instead.'
            );
        }

        // 4. 書式不正 (config 段の silent drop を起動時に表面化させる)。
        foreach ($tokens as $token) {
            if (! TrustedProxyToken::isTrustableAddress($token)) {
                throw new RuntimeException(sprintf(
                    'TRUSTED_PROXIES contains an invalid value "%s". '
                    .'Each entry must be a single IP address or a CIDR block (e.g. 10.0.0.0/8).',
                    $token,
                ));
            }
        }

        // 5. 未設定 (空) は production では宣言漏れとして扱う。
        if ($proxies === []) {
            throw new RuntimeException(
                'TRUSTED_PROXIES is not set in production. Enumerate every proxy hop as IP/CIDR, '
                .'or declare "none" explicitly when the app is not behind a proxy. '
                .'See docs/trusted-proxies-runbook.md.'
            );
        }
    }
}

```

### tests/Architecture/PasskeyPackageContractTest.php

```php
<?php

declare(strict_types=1);

use App\Http\Responses\Passkey\PasskeyConfirmationResponse;
use App\Http\Responses\Passkey\PasskeyDeletedResponse;
use App\Http\Responses\Passkey\PasskeyLoginResponse;
use App\Http\Responses\Passkey\PasskeyRegistrationResponse;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
use Laravel\Passkeys\Contracts\PasskeyDeletedResponse as PasskeyDeletedResponseContract;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Http\Controllers\PasskeyConfirmationController;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;
use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;
use Laravel\Passkeys\Passkeys;

/*
 * laravel/passkeys (Fortify 1.37 の推移依存) とアプリの結線契約を固定する。
 *
 * 守る事故:
 *   - パッケージ側 routes の二重登録 (Fortify が feature flag でゲートした route と衝突する)
 *   - Fortify 標準の password.confirm が復活し SSO-only ユーザーが詰む
 *   - config:cache 下で fortify-options.passkeys が落ちる
 *   - binder が vendor 実装のまま残り、他人の passkey の存在が 403 で漏れる
 *
 * DB を伴う実挙動 (他人の passkey が 404 になること) は
 * tests/Feature/Auth/PasskeyRouteAccessTest.php が担保する
 * (Architecture レーンは RefreshDatabase を持たないため DB に触れない)。
 */

/** @return list<string> Fortify が登録する passkey route の名前 */
function passkeyRouteNames(): array
{
    return [
        'passkey.login-options',
        'passkey.login',
        'passkey.confirm-options',
        'passkey.confirm',
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];
}

test('パッケージ側の passkey routes は登録されない (Fortify 側が唯一の登録点)', function (): void {
    expect(Passkeys::shouldRegisterRoutes())->toBeFalse();
});

test('passkeys feature が有効 (キルスイッチが on)', function (): void {
    expect(Features::enabled(Features::passkeys()))->toBeTrue();
});

test('passkey route 7 本が実在し vendor controller に紐づく', function (): void {
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();

    $expectedControllers = [
        PasskeyLoginController::class,
        PasskeyConfirmationController::class,
        PasskeyRegistrationController::class,
    ];

    foreach (passkeyRouteNames() as $name) {
        $route = $routes->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' が存在しない");

        $controller = $route->getAction('controller');
        expect($controller)->toBeString();

        $matched = false;
        foreach ($expectedControllers as $expected) {
            if (str_starts_with((string) $controller, $expected.'@')) {
                $matched = true;
                break;
            }
        }
        expect($matched)->toBeTrue("route '{$name}' の action が vendor controller ではない: {$controller}");
    }
});

test('passkeys の confirmPassword は false (generic recent-auth へ統一)', function (): void {
    expect(config('fortify-options.passkeys.confirmPassword'))->toBeFalse();
});

test('passkeys の throttle limiter が設定されている (未認証 challenge 無制限の防止)', function (): void {
    expect(config('fortify.limiters.passkeys'))->toBe('passkeys');
});

/*
 * config:cache 下でも値が残ることを検査する。
 * ConfigCacheCommand は `'<?php return '.var_export($config, true).';'` を書き出すため、
 * その **serialize 機構そのものを再現**して往復させる
 * (Pest から config:cache を実行すると bootstrap/cache/config.php を書き換え、
 *  --parallel 実行を壊すため実行しない)。
 */
test('config cache 往復後も fortify-options.passkeys と features が残る', function (): void {
    $subset = [
        'fortify' => config('fortify'),
        'fortify-options' => config('fortify-options'),
    ];

    $exported = var_export($subset, true);
    /** @var array<string, mixed> $roundTripped */
    $roundTripped = eval('return '.$exported.';');

    expect(data_get($roundTripped, 'fortify-options.passkeys.confirmPassword'))->toBeFalse();
    expect(data_get($roundTripped, 'fortify.features'))->toContain('passkeys');
    expect(data_get($roundTripped, 'fortify.limiters.passkeys'))->toBe('passkeys');
});

test('モデル差し替えが app 実装になっている', function (): void {
    expect(Passkeys::passkeyModel())->toBe(Passkey::class);
    expect(Passkeys::userModel())->toBe(User::class);
    expect(is_a(User::class, PasskeyUser::class, true))->toBeTrue();
});

test('Response contract 4 本が app 実装に差し替えられている (response()->json 直書きの回避)', function (): void {
    expect(app(PasskeyLoginResponseContract::class))->toBeInstanceOf(PasskeyLoginResponse::class);
    expect(app(PasskeyConfirmationResponseContract::class))->toBeInstanceOf(PasskeyConfirmationResponse::class);
    expect(app(PasskeyRegistrationResponseContract::class))->toBeInstanceOf(PasskeyRegistrationResponse::class);
    expect(app(PasskeyDeletedResponseContract::class))->toBeInstanceOf(PasskeyDeletedResponse::class);
});

/*
 * binder の **最終解決系**がアプリ実装であることを固定する。
 *
 * vendor の binder は `app($model)->resolveRouteBinding($value)` でグローバル解決するため、
 * guest 文脈でも解決に成功しうる (= その後 controller の 403 に到達して存在が漏れる)。
 * アプリ実装 (SelfScopedPasskeyBinder) は guest を DB へ行かずに 404 相当へ倒すので、
 * **DB に触れずに** 差し替えの成否を判定できる。
 */
test('passkey binder の最終解決系がアプリ実装 (guest は DB を引かずに 404 相当)', function (): void {
    $callback = app('router')->getBindingCallback('passkey');

    expect($callback)->not->toBeNull('{passkey} の explicit binder が登録されていない');

    // class binding は Router::createClassBinding により ($value, $route) の 2 引数 closure になる
    expect(fn () => $callback('1', null))->toThrow(ModelNotFoundException::class);
});

```

### tests/Architecture/EnvExampleInvariantTest.php

```php
<?php

declare(strict_types=1);

/*
 * production deploy 時に SESSION_SECURE_COOKIE / SESSION_ENCRYPT を立て忘れないよう
 * .env.example に必ず提示する invariant (aigenba T425 SEC03 由来)。
 */

test('.env.example に SESSION_SECURE_COOKIE=true が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('SESSION_SECURE_COOKIE=true');
});

test('.env.example に SESSION_ENCRYPT=true が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('SESSION_ENCRYPT=true');
});

/*
 * client IP の信頼境界 (T108 S5)。production で未宣言だと起動時 fail-fast するため、
 * .env.example に必ず提示して「設定し忘れてデプロイが落ちる」事故を減らす。
 */

test('.env.example に TRUSTED_PROXIES が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('TRUSTED_PROXIES=');
});

/*
 * テンプレート規約: 環境座標 (config/template.php) のキーは .env.example に必ず提示する。
 */

test('.env.example に TEMPLATE_APP_SLUG が含まれる', function (): void {
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */
    expect($contents)->toContain('TEMPLATE_APP_SLUG=');
});

/*
 * env ファイルの `${VAR}` nested variable は「同一ファイル内の先行定義 or 実行環境変数」しか
 * 解決できない (APP_ENV 別ロードでは他ファイルを継承しない)。自己参照 (VAR="${VAR}") や
 * 前方参照はリテラル文字列がそのまま画面に露出する事故になる (bug-hunt F-01 の実例:
 * .env.bughunt.local の APP_NAME="${APP_NAME}" が全画面のタイトル/ロゴ/フッターに露出)。
 *
 * 意図的に「実行環境からの外部注入」を期待する参照は ENV_EXTERNAL_REF_ALLOWLIST に
 * ファイル => 変数名 => 理由 で登録する (deny-by-default)。
 */

/** @var array<string, array<string, string>> */
const ENV_EXTERNAL_REF_ALLOWLIST = [
    // '.env.example' => ['SOME_VAR' => '理由'],
];

/**
 * @return array<int, array{file: string, line: int, ref: string}> 違反一覧
 */
function collectUnresolvedEnvRefs(string $relativePath): array
{
    $contents = file_get_contents(base_path($relativePath));
    expect($contents)->toBeString();
    /** @var string $contents */
    $defined = [];
    $violations = [];

    foreach (explode("\n", $contents) as $i => $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        // export プレフィックス付き定義も将来混在しうるため許容する
        if (preg_match('/^(?:export\s+)?([A-Z0-9_]+)=(.*)$/', $trimmed, $m) !== 1) {
            continue;
        }
        [$_, $key, $value] = $m;

        // 値の中の ${VAR} 参照を全て検査 (定義行より前に VAR 定義が無ければ違反)
        if (preg_match_all('/\$\{([A-Z0-9_]+)\}/', $value, $refs) > 0) {
            foreach ($refs[1] as $ref) {
                $allowed = ENV_EXTERNAL_REF_ALLOWLIST[$relativePath][$ref] ?? null;
                if ($allowed === null && ! array_key_exists($ref, $defined)) {
                    $violations[] = ['file' => $relativePath, 'line' => $i + 1, 'ref' => $ref];
                }
            }
        }

        // 定義の登録は参照検査の後 (VAR="${VAR}" の自己参照を違反にするため)
        $defined[$key] = true;
    }

    return $violations;
}

test('コミット対象 env ファイルに自己参照・前方参照の ${VAR} が無い', function (): void {
    $violations = [];
    foreach (['.env.example', '.env.bughunt.local.example', '.env.testing'] as $file) {
        $violations = array_merge($violations, collectUnresolvedEnvRefs($file));
    }
    expect($violations)->toBe([], '未解決の ${VAR} 参照: '.json_encode($violations, JSON_UNESCAPED_SLASHES));
});

```

### vendor/laravel/passkeys/config/passkeys.php (既定)

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party ID
    |--------------------------------------------------------------------------
    |
    | The relying party ID represents your application in the WebAuthn protocol.
    | This is typically your domain (e.g., "example.com"). Passkeys are bound
    | to this ID and can only be verified on matching domains.
    |
    */

    'relying_party_id' => parse_url(config('app.url'), PHP_URL_HOST),

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | The origins permitted to complete WebAuthn ceremonies. Passkeys bound
    | to the relying party ID above will only verify when the browser
    | reports one of these origins. Defaults to your application URL.
    |
    */

    'allowed_origins' => [
        config('app.url'),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Handle Secret
    |--------------------------------------------------------------------------
    |
    | Secret used to derive a stable WebAuthn user handle from each user model.
    | Set this explicitly if you rotate your application key.
    |
    */

    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),

    /*
    |--------------------------------------------------------------------------
    | WebAuthn Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in milliseconds for WebAuthn operations. This determines
    | how long users have to complete passkey registration or verification.
    |
    */

    'timeout' => 60000,

    /*
    |--------------------------------------------------------------------------
    | Authentication Guard
    |--------------------------------------------------------------------------
    |
    | The authentication guard to use when logging in users with passkeys.
    | This should match your application's primary authentication guard.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Passkeys Routes Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify which middleware Passkeys will assign to the routes
    | that it registers with the application. If necessary, you may change
    | these middleware but typically this provided default is preferred.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Passkeys Management Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify the middleware applied to passkey management routes
    | that create or delete passkeys. By default, Laravel's password
    | confirmation middleware is used.
    |
    */

    'management_middleware' => ['password.confirm'],

    /*
    |--------------------------------------------------------------------------
    | Passkeys Throttling
    |--------------------------------------------------------------------------
    |
    | Middleware used to throttle passkey endpoints. Set to null to disable.
    |
    */

    'throttle' => 'throttle:6,1',

    /*
    |--------------------------------------------------------------------------
    | Redirect
    |--------------------------------------------------------------------------
    |
    | The path to redirect to after successful passkey verification.
    |
    */

    'redirect' => '/',

];

```

# 詳細設計レビュー依頼 (aicue / T126 外部 SDK の client timeout を pin する)

## アプリの使命・禁止事項 (AGENTS.md より転記。全判断の基準とせよ)

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

### 前提環境

- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン
- Laratrust RBAC (Organization → Team → Project 階層)
- テストは `RefreshDatabase` グローバル適用 + `--parallel`。個別 `DatabaseTransactions` 禁止。テストデータは Factory

### レビュー観点

1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 (命名規約、パターン、API)
3. PHPStan level 10 適合性 (型安全性、generics、Assert 使用)
4. テスト計画の網羅性 (各施策に Pest テスト、RefreshDatabase グローバル適用に従う)
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性 (TypeScript 型定義、API Resource、テストが変更対象に含まれているか)
9. セキュリティ (認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件)
10. DESIGN.md 準拠 (UI/frontend 変更を含む場合)
11. Atomic Design 準拠 (UI/frontend 変更を含む場合)

> 本タスクは **frontend 変更を一切含まない**ため、観点 10 / 11 は「対象外」と判定してよい。

### 出力形式

- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: external-sdk-client-timeout (T126 外部 SDK の client timeout を pin する)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）— AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase はグローバル適用**（`tests/Pest.php`）で `--parallel` 実行。
  **個別 `DatabaseTransactions` 使用禁止**
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン**推奨 / `declare(strict_types=1)` + 日本語コメント
- `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex `gpt-5.5` / medium で Round 4 に **APPROVED**）
- Codex 議事: `conceptual-review-round-{1..4}.md` / `codex-history/conceptual-review-decisions-round-{1..3}.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | pin 値の単一出典クラス | `app/Support/ExternalClientTimeouts.php` (新規) | Critical |
| 2 | Stripe プロセス大域 pin の専用 provider | `app/Providers/ExternalClientTimeoutServiceProvider.php` (新規) / `bootstrap/providers.php` | Critical |
| 3 | AWS クライアント 3 構築点への配線 | `config/filesystems.php` / `config/services.php` / `app/Providers/AppServiceProvider.php` | Critical |
| 4 | `headObject` の per-command 上書き | `app/Services/Capture/TakeObjectStorage.php` | Critical |
| 5 | 面分類 enum / 免除 enum / 目録 gate | `app/Enums/Storage/S3OperationSurface.php` (新規) / `app/Enums/Storage/ExternalClientBoundaryExemption.php` (新規) / `tests/Architecture/ExternalClientTimeoutInventoryTest.php` (新規) | Critical |
| 6 | Stripe 呼び出し回数の behavioral 固定 | `tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php` (新規) / `tests/Support/Billing/CountingStripeHttpClient.php` (新規) | High |
| 7 | web 経路が `Bulk` を呼ばないことの固定 | `tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php` (新規) | High |
| 8 | timeout 例外の分類固定 (T132 整合) | `tests/Unit/Billing/GatewayFailureClassifierTest.php` (追記) | Medium |
| 9 | 帯の張り替え + docs + 既存 gate 更新 | `config/queue.php` / `mprocs.yaml` / `docs/architecture.md` / `tests/Architecture/QueueWorkerLeaseInvariantTest.php` | Critical |

実装は **1 〜 8 を先に green にしてから 9 に着手**する（Codex Round 2 の助言。
「帯を動かす前に根拠を先に置く」）。PR は 1 本（中間状態を残さない = AGENTS.md 思考原則 3）。

---

## 施策 1: pin 値の単一出典クラス

### 変更箇所

- ファイル: `app/Support/ExternalClientTimeouts.php` (新規)

### 波及変更

- TypeScript 型定義: なし（サーバ側の内部定数。フロントへ露出しない）
- API Resource / DTO: なし（DTO ではなく定数クラス。値は config へ流し込むだけで応答に載らない）
- テストファイル: 施策 5 の gate が本クラスを参照する

### 現行コード

存在しない。現状は SDK 既定値（`Stripe\HttpClient\CurlClient::DEFAULT_TIMEOUT = 80` /
AWS は無指定 = 無制限 × 3 attempts）に依存している。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 外部 SDK (Stripe / AWS) のクライアント待ち上限の**単一出典**。
 *
 * ★env で上書きできる口を作らない。`config/queue.php` の retry_after が
 *   「静的 gate は config をテスト環境の値で読むため、env 上書きを残すと
 *    gate は通るが本番の実値は別、を作れてしまう」という理由でリテラル固定なのと同じ理屈で、
 *   **gate が読む値と本番の実値を一致させる**ために定数で持つ。
 * ★config ファイルから参照するために「クラス定数」にしている
 *   (config の中で config() を呼ぶのは読み込み順に依存して壊れる)。
 *
 * ★用語: 「HTTP 試行 timeout 予算」= cURL / Guzzle に与える 1 試行あたりの上限 × attempts。
 *   **SDK 操作全体の wall-clock deadline ではない** (DNS 解決・credential provider・
 *   endpoint discovery・retry backoff はこの外側)。誇張して書かないこと。
 *
 * 運用契約: docs/architecture.md §外部 SDK の待ち上限の規約
 */
final class ExternalClientTimeouts
{
    // --- Stripe (プロセス大域。ApiRequestor の HTTP client にしか置けない) ---

    /** TCP 接続確立の上限 (SDK 既定 30s)。 */
    public const int STRIPE_CONNECT_TIMEOUT_SECONDS = 5;

    /** 1 リクエストの総時間上限 (SDK 既定 80s)。単一オブジェクトの create/retrieve/pay しか呼ばない。 */
    public const int STRIPE_TIMEOUT_SECONDS = 20;

    /**
     * SDK 内リトライ回数。**0 に pin する**。
     *
     * 課金の一回性は Stripe idempotency key とリコンサイルが担う設計 (AGENTS.md ドメイン規約 6) で、
     * SDK 自動 retry に寄せない。0 でないとジョブの外部予算が retry 数だけ倍化する。
     */
    public const int STRIPE_MAX_NETWORK_RETRIES = 0;

    // --- AWS 制御系 (SES 送信 / SNS。転送量が有界) ---

    public const int AWS_CONTROL_CONNECT_TIMEOUT_SECONDS = 5;

    public const int AWS_CONTROL_TIMEOUT_SECONDS = 15;

    // --- AWS データ系 (s3 disk のクライアント既定。本文転送があるため長い) ---

    public const int AWS_S3_CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * s3 disk クライアントの総時間上限。
     *
     * ★短くできない: Flysystem の write 経路 (`AwsS3V3Adapter::upload()` →
     *   `createOptionsFromConfig()`) は `AVAILABLE_OPTIONS` / `MUP_AVAILABLE_OPTIONS` しか
     *   転送しないため **`@http` を per-command で注入できない**。client 既定が
     *   データ系を賄う必要がある (vendor 実査済み)。
     * ★web 同期経路で使う metadata 操作は per-command で AWS_CONTROL_* へ絞る (施策 4)。
     */
    public const int AWS_S3_TIMEOUT_SECONDS = 900;

    /** AWS SDK の試行回数 (SDK 既定 3)。worst case は timeout × attempts になるため明示 pin する。 */
    public const int AWS_MAX_ATTEMPTS = 2;

    /** web 同期経路の metadata 操作は SDK 内で粘らせない (アプリ側で失敗を返して再操作を促す)。 */
    public const int AWS_CONTROL_PLANE_RETRIES = 0;

    // --- 既定キュー接続 (database) の時間予算 ---

    /**
     * `ExecuteAutoRechargeAttemptJob` の最長経路で許す Stripe HTTP 呼び出し回数。
     *
     * ★静的計数では Cashier 内部 (`createOrGetStripeCustomer`) を数えられないため、
     *   **実行時の HTTP 呼び出し回数**で固定する (施策 6)。
     */
    public const int DEFAULT_CONNECTION_STRIPE_CALL_BUDGET = 10;

    /** 既定接続のジョブが外部 SDK 待ちに使ってよい上限 (= 20s × 10 回)。 */
    public const int DEFAULT_CONNECTION_EXTERNAL_BUDGET_SECONDS = 200;

    /** 外部呼び出し以外 (DB / ロック待ち / ログ / 後始末) の予算。 */
    public const int DEFAULT_CONNECTION_LOCAL_BUDGET_SECONDS = 90;

    /** 既定接続のワーカー `--timeout`。`外部予算 + 局所予算 < これ < retry_after` を守る。 */
    public const int DEFAULT_CONNECTION_WORKER_TIMEOUT_SECONDS = 300;

    /**
     * AWS クライアント構築引数 (制御系)。
     *
     * @return array{http: array{connect_timeout: int, timeout: int}, retries: array{mode: 'legacy', max_attempts: int}}
     */
    public static function awsControlClientOptions(): array
    {
        return [
            'http' => [
                'connect_timeout' => self::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::AWS_CONTROL_TIMEOUT_SECONDS,
            ],
            'retries' => ['mode' => 'legacy', 'max_attempts' => self::AWS_MAX_ATTEMPTS],
        ];
    }

    /**
     * AWS クライアント構築引数 (s3 disk = データ系)。
     *
     * @return array{http: array{connect_timeout: int, timeout: int}, retries: array{mode: 'legacy', max_attempts: int}}
     */
    public static function awsS3ClientOptions(): array
    {
        return [
            'http' => [
                'connect_timeout' => self::AWS_S3_CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::AWS_S3_TIMEOUT_SECONDS,
            ],
            'retries' => ['mode' => 'legacy', 'max_attempts' => self::AWS_MAX_ATTEMPTS],
        ];
    }

    /**
     * S3 の **per-command** 上書き (web 同期経路の metadata 操作用)。
     *
     * `Aws\AwsClient::getCommand()` は `@http` を `+=` で合成する = **渡した側が勝つ**。
     * `@retries` は `Aws\RetryMiddleware` / `RetryMiddlewareV2` の両方が読む (vendor 実査済み)。
     *
     * @return array{'@http': array{connect_timeout: int, timeout: int}, '@retries': int}
     */
    public static function awsControlPlaneCommandOptions(): array
    {
        return [
            '@http' => [
                'connect_timeout' => self::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::AWS_CONTROL_TIMEOUT_SECONDS,
            ],
            '@retries' => self::AWS_CONTROL_PLANE_RETRIES,
        ];
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（3 メソッドとも array shape を `@return` で宣言）
- [x] null 安全（null を扱わない。全定数が `int`）
- [x] DTO を返している → **本施策は対象外**。config へ流し込む配列であり、
      HTTP 応答にも Inertia props にも載らない（DTO 化すると config が array を要求するため
      すぐ `->toArray()` する無意味な層になる）。`array{...}` shape で型安全は担保する
- [x] Generics の型パラメータが正しい（generics 不使用）
- [x] `mode` を `'legacy'` の literal string まで狭める（Codex Round 2 の指摘）

### テスト計画

- [ ] 新規: `tests/Architecture/ExternalClientTimeoutInventoryTest.php`
      — 「pin 値は SDK 既定値と異なる」（負のコントロール。施策 5 で詳述）
- [ ] 新規: 同ファイル「時間予算の序列」
      — `DEFAULT_CONNECTION_EXTERNAL_BUDGET_SECONDS == STRIPE_TIMEOUT_SECONDS * DEFAULT_CONNECTION_STRIPE_CALL_BUDGET`
- [ ] 個別 `DatabaseTransactions` を使っていないことを確認（Architecture テストは DB を触らない）

### リスク

- **Stripe 20s が短すぎる可能性**。`invoices->pay` はカードネットワーク照会を伴い、
  Stripe 側の p99 でも数秒。20s は 10 倍のヘッドルームだが、**大規模障害時に
  「以前なら 80s 待って成功したものが失敗する」ケースは増える**。
  緩和: `ExecuteAutoRechargeAttemptJob` は `$tries = 1` でリコンサイルが再試行を担うため、
  timeout は**恒久喪失にならない**（idempotency key で二重課金にもならない）。
- 値を変えるとき 1 箇所で済む反面、**全経路が同時に動く**。面ごとに定数を分けてあるのはこのため。

---

## 施策 2: Stripe プロセス大域 pin の専用 provider

### 変更箇所

- ファイル: `app/Providers/ExternalClientTimeoutServiceProvider.php` (新規)
- ファイル: `bootstrap/providers.php` (L11-25 の配列へ追加)

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし
- テストファイル: `tests/Feature/Providers/ExternalClientTimeoutServiceProviderTest.php` (新規)

### 現行コード

`AppServiceProvider` にも他のどこにも Stripe HTTP client の pin は存在しない。
`Stripe\ApiRequestor::httpClient()` は遅延で `CurlClient::instance()`（既定 80s/30s）を返す。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\ExternalClientTimeouts;
use Illuminate\Support\ServiceProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;

/**
 * 外部 SDK のプロセス大域設定を pin する専用 provider。
 *
 * ★**なぜ AppServiceProvider に混ぜないか**: この pin は PHP プロセス大域の static 状態を
 *   書き換えるため、「配線が実際に効いているか」をテストが独立に検証するには
 *   provider の boot() を単独で再実行できる必要がある。AppServiceProvider に混ぜると
 *   再実行で Event::listen 等が二重登録される。
 * ★Stripe SDK は **client ごとの timeout を支えない**。`StripeClient` の config に
 *   timeout 系のキーが無く (`BaseStripeClient::DEFAULT_CONFIG`)、`ApiRequestor` の
 *   static HTTP client だけが唯一の調整点である。したがってテナント別 timeout は持たない。
 * ★`Cashier::stripe()` / `$organization->stripe()` / `PriceService` bind の 3 系統は
 *   すべてこの HTTP client を通るため、大域 pin 1 本で全経路を覆える。
 *
 * 運用契約: docs/architecture.md §外部 SDK の待ち上限の規約
 */
final class ExternalClientTimeoutServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ★CurlClient::instance() のシングルトンを直接設定せず、専用インスタンスを
        //   ApiRequestor へ差す。シングルトンを書き換えると「誰が設定したか」が
        //   追えなくなるうえ、テストの復元先が曖昧になる。
        $client = new CurlClient;
        $client->setConnectTimeout(ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS);
        $client->setTimeout(ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS);

        ApiRequestor::setHttpClient($client);
        Stripe::setMaxNetworkRetries(ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES);
    }
}
```

`bootstrap/providers.php`:

```php
return [
    AppServiceProvider::class,
    // 外部 SDK (Stripe) のプロセス大域 timeout pin。他の provider の副作用と混ぜないため
    // 専用に切り出す (テストが boot() を単独で再実行できるようにする)
    ExternalClientTimeoutServiceProvider::class,
    AdminPanelProvider::class,
    // ... 以下既存のまま
];
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`boot(): void`）
- [x] null 安全（`new CurlClient` は null を返さない。`setTimeout()` は vendor が `int` へキャスト）
- [x] DTO を返している → 対象外（provider）
- [x] Generics の型パラメータが正しい（不使用）
- 注意: `Stripe\HttpClient\CurlClient` の `setTimeout()` は vendor に戻り型宣言が無い。
  **戻り値を使わず文として呼ぶ**（fluent chain にしない）ことで PHPStan の
  `mixed` 伝播を作らない

### テスト計画

- [ ] 新規 `tests/Feature/Providers/ExternalClientTimeoutServiceProviderTest.php`
  - `Stripe HTTP client の timeout / connect_timeout が pin 値になる`
    — **既知の初期状態から検証する**（ambient state での偽グリーン防止）:
    ```php
    $originalClient = ApiRequestor::httpClient();
    $originalRetries = Stripe::getMaxNetworkRetries();

    try {
        // 既知の「pin されていない」状態へ戻す
        ApiRequestor::setHttpClient(new CurlClient);
        Stripe::setMaxNetworkRetries(7);

        (new ExternalClientTimeoutServiceProvider($this->app))->boot();

        $client = ApiRequestor::httpClient();
        expect($client)->toBeInstanceOf(CurlClient::class);
        expect($client->getTimeout())->toBe(ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS);
        expect($client->getConnectTimeout())->toBe(ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS);
        expect(Stripe::getMaxNetworkRetries())->toBe(ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES);
    } finally {
        ApiRequestor::setHttpClient($originalClient);
        Stripe::setMaxNetworkRetries($originalRetries);
    }
    ```
    **退避直後に `try` を開く**（Codex Round 3/4 の指摘。assert 失敗時も状態を残さない）
  - `pin されていない CurlClient は SDK 既定値を返す` — 負のコントロール。
    `(new CurlClient)->getTimeout()` が `CurlClient::DEFAULT_TIMEOUT` (80) であることを確認し、
    上のテストが「何もしなくても green」ではないことを示す
  - `provider が bootstrap/providers.php に登録されている`
    — `require base_path('bootstrap/providers.php')` の配列に含まれることを確認
      （登録漏れは本番だけ pin されない = 最悪の偽グリーン）
- [ ] 個別 `DatabaseTransactions` を使っていないことを確認（本テストは DB を触らない）
- [ ] `Http::fake()` 不要（送信しない。egress guard に抵触しない）

### リスク

- **プロセス大域**であるため、同一プロセスで走る全テストへ影響する。
  ただし影響は「Stripe HTTP の timeout が短い」だけで、テストレーンでは
  `FakeExternalsServiceProvider` により Stripe gateway が fake に置換されるため
  実際の HTTP は発生しない。
- `--parallel` はプロセスを分けるので、プロセス間の干渉は無い。
  同一プロセス内は `finally` の復元で閉じる。

---

## 施策 3: AWS クライアント 3 構築点への配線

### 変更箇所

- `config/filesystems.php` (L50-61 の `disks.s3`)
- `config/services.php` (L25-39 の `ses`)
- `app/Providers/AppServiceProvider.php` (L91-105 の `SnsClient` singleton)

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし
- テストファイル:
  - `tests/Architecture/ExternalClientTimeoutInventoryTest.php`（施策 5）
  - 既存 `tests/Feature/Capture/TakeObjectStorageTest.php` の `fakeS3DiskConfig()` は
    **`filesystems.disks.s3` を丸ごと差し替える**ため、`http` / `retries` を含めないと
    施策 4 の per-command 上書き検証以外は素通しになる。**波及変更として明示**し、
    このヘルパにも同じ値を入れる

### 現行コード

```php
// config/filesystems.php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => false,
    'report' => false,
],
```

```php
// config/services.php
'ses' => [
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'options' => array_filter([...]),
    'sns_topic_arns' => array_values(array_filter(array_map(...))),
],
```

```php
// app/Providers/AppServiceProvider.php L91-105
$this->app->singleton(SnsClient::class, function (Application $app): SnsClient {
    /** @var array<string, mixed> $ses */
    $ses = (array) config('services.ses', []);
    $config = [
        'version' => 'latest',
        'region' => is_string($ses['region'] ?? null) ? $ses['region'] : 'us-east-1',
    ];
    $key = $ses['key'] ?? null;
    $secret = $ses['secret'] ?? null;
    if (is_string($key) && $key !== '' && is_string($secret) && $secret !== '') {
        $config['credentials'] = ['key' => $key, 'secret' => $secret];
    }

    return new SnsClient($config);
});
```

### 変更後コード

```php
// config/filesystems.php (先頭に use App\Support\ExternalClientTimeouts; を追加)
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => false,
    'report' => false,
    // AWS SDK は http / retries を無指定にすると「無制限 × 3 attempts」になる。
    // データ系 (本文 read/write) の値。metadata 操作は per-command で制御系へ絞る
    // (TakeObjectStorage::headObject)。FilesystemManager::createS3Driver() が
    // この配列を素通しで S3Client へ渡す。
    ...ExternalClientTimeouts::awsS3ClientOptions(),
],
```

```php
// config/services.php (先頭に use App\Support\ExternalClientTimeouts; を追加)
'ses' => [
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'options' => array_filter([
        'ConfigurationSetName' => env('SES_CONFIGURATION_SET'),
    ]),
    'sns_topic_arns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SES_SNS_TOPIC_ARNS', ''))
    ))),
    // MailManager::createSesV2Transport() が本配列を SesV2Client へ素通しする。
    // 送信は転送量が有界なので制御系の帯。
    ...ExternalClientTimeouts::awsControlClientOptions(),
],
```

```php
// app/Providers/AppServiceProvider.php
$this->app->singleton(SnsClient::class, function (Application $app): SnsClient {
    /** @var array<string, mixed> $ses */
    $ses = (array) config('services.ses', []);
    $config = [
        'version' => 'latest',
        'region' => is_string($ses['region'] ?? null) ? $ses['region'] : 'us-east-1',
        // ★config('services.ses') の http/retries を継承しない (自前で $config を組むため)。
        //   pin を明示する。無指定は「無制限 × 3 attempts」= web 経路のハング要因。
        ...ExternalClientTimeouts::awsControlClientOptions(),
    ];
    // ... 以下 credentials 部分は既存のまま
```

> **注意 (実装時に確認する)**: `config/*.php` の中でクラス定数・static メソッドを参照するのは
> 「オートローダ登録後・config 読み込み時」に評価されるため安全である
> （`config()` ヘルパを config 内で呼ぶのとは別問題）。
> `tests/Support/QueueLeaseConfig` が `config/queue.php` を **`require` で直読み**しているのと
> 同様に、`config/filesystems.php` / `config/services.php` を直読みする既存コードが
> 無いことを実装時に `rg` で確認する（あれば autoload 前提が崩れる）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（config は array を返す。shape はメソッド側で宣言済み）
- [x] null 安全（`ExternalClientTimeouts` の戻りは非 null 固定）
- [x] DTO を返している → 対象外（config 配列）
- [x] Generics の型パラメータが正しい（不使用）
- [x] `AppServiceProvider` の `$config` は `array<string, mixed>` のままで型が緩まない
      （既存と同じ形。spread で literal キーが増えるだけ）

### テスト計画

- [ ] 新規 `tests/Architecture/ExternalClientTimeoutInventoryTest.php`（施策 5 で詳述）
  - `s3 disk の config が http / retries を宣言している`
  - `services.ses の config が http / retries を宣言している`
  - **behavioral**: `s3 disk から構築した S3Client の getCommand('HeadObject', …)['@http']`
    が pin 値であること（config を読むだけでは「Laravel が素通ししている」ことを検証できない）
    - テスト環境で `AWS_DEFAULT_REGION` が空の可能性があるため、
      `Storage::build(array_merge(config('filesystems.disks.s3'), ['region' => 'us-east-1', 'bucket' => 'gate', 'key' => 'k', 'secret' => 's']))`
      で構築する（**実 config の `http` / `retries` をそのまま持ち込む**）。
      ネットワークには一切出ない（`getCommand()` は送信しない）
  - **behavioral**: `Mail::mailer('ses')->getSymfonyTransport()->ses()->getCommand('SendEmail', [...])['@http']`
    が pin 値であること
  - **behavioral**: `app(SnsClient::class)->getCommand('ConfirmSubscription', [...])['@http']` / `['@retries']`
- [ ] 既存 `tests/Feature/Capture/TakeObjectStorageTest.php` の `fakeS3DiskConfig()` に
      `http` / `retries` を追加（波及変更）
- [ ] 個別 `DatabaseTransactions` を使っていないことを確認

### リスク

- **`AWS_MAX_ATTEMPTS = 2` は現行 3 からの引き下げ**。一時的な 5xx / スロットリングでの
  自動回復が 1 回減る。緩和: SES 送信はキュージョブ（`$tries` による再配送がある）、
  S3 の bulk 操作もジョブ側で再試行される。web 同期の SNS 購読確認は
  AWS 側が再送するため恒久喪失にならない。
- **`services.ses` へキーを足すと `SesV2Client` に未知引数が渡る**。
  現状すでに `options` / `sns_topic_arns` が渡っており AWS の `ClientResolver` は
  未知キーを無視するため挙動は変わらない（実装時に `Mail::mailer('ses')` の
  解決が例外を投げないことをテストで確認する）。

---

## 施策 4: `headObject` の per-command 上書き

### 変更箇所

- ファイル: `app/Services/Capture/TakeObjectStorage.php` (L58-84 の `headObject`)

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし（`ObjectMetadataData` の形は不変）
- テストファイル: `tests/Feature/Capture/TakeObjectStorageTest.php`（`@http` / `@retries` の検証を追加）

### 現行コード

```php
public function headObject(string $path): ?ObjectMetadataData
{
    try {
        $result = $this->client()->headObject([
            'Bucket' => $this->bucket(),
            'Key' => $path,
            'ChecksumMode' => 'ENABLED',
        ]);
    } catch (S3Exception $exception) {
        if ($exception->getStatusCode() === 404) {
            return null;
        }

        throw $exception;
    }
    // ...
}
```

### 変更後コード

```php
/**
 * オブジェクトが存在しなければ null (PUT 未完了)。ChecksumMode=ENABLED で
 * ChecksumSHA256 も取得する (欠落する互換実装では null = 照合スキップの二重防御位置づけ)。
 *
 * ★**web 同期経路 (テイク登録) から呼ばれる唯一の S3 ネットワーク操作**である。
 *   s3 disk のクライアント既定はデータ系 (900s) のため、ここで per-command に
 *   制御系の帯へ絞る。`@http` は `AwsClient::getCommand()` が `+=` で合成する
 *   = 渡した側が勝つ。`@retries` は RetryMiddleware / RetryMiddlewareV2 の両方が読む。
 *   面分類は S3OperationSurface::BoundedControl。
 */
public function headObject(string $path): ?ObjectMetadataData
{
    try {
        $result = $this->client()->headObject([
            'Bucket' => $this->bucket(),
            'Key' => $path,
            'ChecksumMode' => 'ENABLED',
            ...ExternalClientTimeouts::awsControlPlaneCommandOptions(),
        ]);
    } catch (S3Exception $exception) {
        if ($exception->getStatusCode() === 404) {
            return null;
        }

        throw $exception;
    }
    // ... 以下不変
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`?ObjectMetadataData`）
- [x] null 安全（既存の `Assert::numeric` / `is_string` 分岐は不変）
- [x] DTO を返している（`ObjectMetadataData`）
- [x] Generics の型パラメータが正しい（不使用）
- [x] `awsControlPlaneCommandOptions()` の shape が `array{'@http': …, '@retries': int}` で
      spread しても `array<string, mixed>` に落ちない

### テスト計画

- [ ] 既存 `tests/Feature/Capture/TakeObjectStorageTest.php` に追記
  - 新規: `headObject は制御系の @http / @retries を積む`
    — 既存の `storageWithMockHandler()` を使い、`MockHandler::append()` に
      `function (CommandInterface $command, RequestInterface $request) { ... }` を渡して
      `$command['@http']` / `$command['@retries']` を捕捉して assert する
      （`MockHandler` は callable を受け取ると `($command, $request)` で呼ぶ）
  - 新規: `headObject の @http は s3 disk の既定 (データ系) を上書きする`
    — 捕捉した `timeout` が `AWS_S3_TIMEOUT_SECONDS` **ではなく**
      `AWS_CONTROL_TIMEOUT_SECONDS` であることを assert（負のコントロール）
- [ ] 既存の presign / ChecksumMode テストは不変（回帰確認）
- [ ] 個別 `DatabaseTransactions` を使っていないことを確認

### リスク

- **15s で切れると登録が失敗する**。S3 の `HeadObject` は通常 100ms 未満で、
  15s は 100 倍以上のヘッドルーム。失敗時はテイク登録 API がエラーを返し、
  ユーザーは再操作できる（AGENTS.md 禁止事項 8 のとおり「押せない UI」にはしない）。
- `@retries = 0` により、一時的な 503 での自動回復が無くなる。
  緩和: 撮影 PWA は登録失敗をユーザーに提示して再試行できる（同期 API の性質上、
  SDK 内で 45s 粘るより速く失敗を返す方が体験が良い）。

---

## 施策 5: 面分類 enum / 免除 enum / 目録 gate

### 変更箇所

- `app/Enums/Storage/S3OperationSurface.php` (新規)
- `app/Enums/Storage/ExternalClientBoundaryExemption.php` (新規)
- `tests/Architecture/ExternalClientTimeoutInventoryTest.php` (新規)

### 波及変更

- TypeScript 型定義: **なし**（サーバ内部の分類。フロントに露出しないため
  `ManualEnumTsSyncInvariantTest` / `NotificationTypeTsSyncInvariantTest` の対象外。
  実装時に両テストの母集団定義を読み、本 enum が対象に入らないことを確認する）
- API Resource / DTO: なし
- テストファイル: 本施策そのもの

### 現行コード

存在しない。現状 `app/` 配下で AWS/Flysystem に触れているクラスは以下（`rg` で実査）:

```
app/Http/Controllers/Webhooks/SesNotificationController.php   (Aws\Sns\Message)
app/Http/Middleware/VerifySnsSignature.php                    (Aws\Sns\Message)
app/Providers/AppServiceProvider.php                          (Aws\Sns\SnsClient を構築)
app/Services/Capture/Fakes/FakeTakeObjectStorage.php          (Aws\S3\S3Client)
app/Services/Capture/TakeObjectStorage.php                    (S3Client / Storage::disk / AwsS3V3Adapter)
app/Services/Mail/Sns/AwsSnsSignatureVerifier.php             (Aws\Sns\Message / MessageValidator)
app/Services/Mail/Sns/SnsSignatureVerifier.php                (Aws\Sns\Message)
app/Services/Render/Fakes/FakeRenderObjectStorage.php         (Filesystem)
app/Services/Render/RenderObjectStorage.php                   (Storage::disk / Filesystem)
app/Services/Storage/Fakes/FakeObjectStore.php                (Storage::disk / Filesystem)
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Storage;

/**
 * S3 集約 adapter の public メソッドが持つ「面」の分類。
 *
 * `tests/Architecture/ExternalClientTimeoutInventoryTest.php` が deny-by-default で
 * 全 public メソッドの登録を機械強制する (テストクラスへの {@see} 参照は
 * app → tests の import を生むため書かない)。
 *
 * ★分類の基準は「**転送量が有界か**」と「**per-command option を注入できるか**」の 2 軸。
 */
enum S3OperationSurface: string
{
    /**
     * S3 オブジェクト API を送信しない (ローカル署名 / 文字列生成のみ)。
     *
     * ★**credential 解決 (ECS/EC2 metadata 等) がネットワークへ出る可能性は保証外**である。
     *   「一切ネットワークに出ない」とは主張しない。
     */
    case NoObjectRequest = 'no_object_request';

    /**
     * 転送量が有界なメタデータ操作。**per-command の制御系 option を積むことが必須**。
     *
     * 適用条件: 生の S3Client を直接呼び、`@http` / `@retries` を注入できること。
     * web 同期経路 (HTTP リクエスト内) から呼んでよいのはこの面だけである。
     */
    case BoundedControl = 'bounded_control';

    /**
     * 本文転送、または Flysystem 経由で per-command option を注入できない操作。
     *
     * s3 disk のクライアント既定 (データ系の長い timeout) を継承する。
     * **web 同期経路から呼ばない** — これは規約であり、機械では証明していない
     * (呼び出しグラフ解析が要り、静的近似は偽陰性が静かに増えるため採らない)。
     * 既存の web 経路については Feature テストが `Bulk` 不使用を固定する。
     */
    case Bulk = 'bulk';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Enums\Storage;

/**
 * 「AWS SDK / Flysystem へ到達するが、S3 集約 adapter ではない」ことが正しいと裁定された理由の分類。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「adapter へ寄せるべきコード」である。
 */
enum ExternalClientBoundaryExemption: string
{
    /**
     * AWS の**値オブジェクト**だけを扱い、クライアントを構築も取得もしない。
     *
     * 適用条件: 参照が `Aws\Sns\Message` / `Aws\Sns\MessageValidator` のような
     * リクエストを送らない型に限られ、`disk()` も `getClient()` も呼ばない。
     * (証明書取得は自前 HTTP client 経由で timeout 指定済み = SDK の待ちではない)
     */
    case AwsValueObjectOnly = 'aws_value_object_only';

    /**
     * 制御系 AWS クライアントの**構築点**であり、pin 値を明示的に渡している。
     *
     * 適用条件: `ExternalClientTimeouts::awsControlClientOptions()` を
     * 構築引数へ展開しており、per-command 上書きを必要としない (転送量が有界)。
     */
    case PinnedControlClientConstruction = 'pinned_control_client_construction';

    /**
     * 外部 SDK のプロセス大域設定を pin する専用 provider。
     *
     * 適用条件: `ApiRequestor::setHttpClient()` / `Stripe::setMaxNetworkRetries()` の
     * 呼び出しが本クラスに 1 箇所ずつだけ存在し、他に副作用を持たないこと。
     */
    case GlobalSdkTimeoutPin = 'global_sdk_timeout_pin';
}
```

`tests/Architecture/ExternalClientTimeoutInventoryTest.php` の骨子（**目録 2 本 + 検査 12 本**）:

```php
/**
 * S3 / AWS SDK / Flysystem へ到達できるクラスの目録 (deny-by-default)。
 *
 * value: S3 集約 adapter は null、それ以外は免除理由 (enum + 30 文字以上の根拠)。
 *
 * @var array<class-string, array{surface: 'adapter'}|array{surface: 'exempt', reason: ExternalClientBoundaryExemption, rationale: string}>
 */
const EXTERNAL_CLIENT_BOUNDARY_INVENTORY = [
    TakeObjectStorage::class => ['surface' => 'adapter'],
    RenderObjectStorage::class => ['surface' => 'adapter'],
    FakeTakeObjectStorage::class => ['surface' => 'adapter'],
    FakeRenderObjectStorage::class => ['surface' => 'adapter'],
    FakeObjectStore::class => ['surface' => 'adapter'],
    AppServiceProvider::class => ['surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::PinnedControlClientConstruction,
        'rationale' => 'SNS 購読確認クライアントの構築点。制御系 pin を構築引数へ展開しており転送量も有界'],
    ExternalClientTimeoutServiceProvider::class => ['surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::GlobalSdkTimeoutPin,
        'rationale' => 'Stripe SDK のプロセス大域 timeout を pin する唯一の場所。他に副作用を持たない'],
    AwsSnsSignatureVerifier::class => ['surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::AwsValueObjectOnly,
        'rationale' => 'MessageValidator は署名検証のみで送信しない。証明書取得は自前 HTTP client で timeout 済み'],
    SnsSignatureVerifier::class => ['surface' => 'exempt', ...],
    SesNotificationController::class => ['surface' => 'exempt', ...],
    VerifySnsSignature::class => ['surface' => 'exempt', ...],
];

/**
 * adapter の public メソッドの面分類 (deny-by-default)。
 *
 * @var array<class-string, array<string, array{surface: S3OperationSurface, rationale: string}>>
 */
const S3_OPERATION_SURFACE_INVENTORY = [
    TakeObjectStorage::class => [
        'presignUpload' => [S3OperationSurface::NoObjectRequest, 'presign は署名計算のみで S3 へリクエストを送らない'],
        'headObject' => [S3OperationSurface::BoundedControl, 'web 同期のテイク登録から呼ぶ唯一の S3 ネットワーク操作'],
        'temporaryPlaybackUrl' => [S3OperationSurface::NoObjectRequest, '署名 URL の生成のみ。オブジェクト API を送らない'],
        'delete' => [S3OperationSurface::Bulk, 'Flysystem 経由で per-command option を注入できない。掃除ジョブ専用'],
        'exists' => [S3OperationSurface::Bulk, 'Flysystem 経由で per-command option を注入できない。掃除ジョブ専用'],
    ],
    RenderObjectStorage::class => [
        'downloadToLocal' => [S3OperationSurface::Bulk, '本文転送。所要時間がオブジェクトサイズに比例する'],
        'upload' => [S3OperationSurface::Bulk, '本文転送。所要時間がオブジェクトサイズに比例する'],
        'temporaryPlaybackUrl' => [S3OperationSurface::NoObjectRequest, '署名 URL の生成のみ'],
        'temporaryDownloadUrl' => [S3OperationSurface::NoObjectRequest, '署名 URL の生成のみ'],
        'delete' => [S3OperationSurface::Bulk, 'Flysystem 経由で per-command option を注入できない'],
        'keyPrefixFor' => [S3OperationSurface::NoObjectRequest, '文字列生成のみ。SDK を呼ばない'],
        'contentDisposition' => [S3OperationSurface::NoObjectRequest, '文字列生成のみ。SDK を呼ばない'],
    ],
];
```

検査ケース一覧（すべて Pest の `test()`）:

| # | テスト名 | 検査内容 |
|---|---|---|
| 1 | `到達境界: AWS / Flysystem へ到達するクラスは目録と対称差ゼロ` | 走査集合 == 目録キー（`missing` / `stale` 両方向） |
| 2 | `到達境界: 走査母集団が空でない` | 走査結果 0 件で fail（**空振り防止**） |
| 3 | `到達境界: 免除には 30 文字以上の根拠がある` | `mb_strlen($rationale) >= 30` |
| 4 | `到達境界: disk 名は文字列リテラルである` | `->disk($variable)` は違反（動的名は静的検査できない） |
| 5 | `到達境界: Stripe の大域 setter は pin 用 provider の 1 箇所だけ` | `ApiRequestor::setHttpClient` / `Stripe::setMaxNetworkRetries` / `CurlClient::instance` の site 集合 == `{ExternalClientTimeoutServiceProvider}` |
| 6 | `面分類: adapter の public メソッドは目録と対称差ゼロ` | Reflection の `getMethods(PUBLIC)` で宣言クラスが自身のもの == 目録キー |
| 7 | `面分類: 各 entry に 30 文字以上の根拠がある` | 同上 |
| 8 | `面分類: BoundedControl は 1 つ以上ある` | 0 件で fail（**空振り防止**。全部 `Bulk` にすれば通る、を防ぐ） |
| 9 | `pin 値は SDK 既定値と異なる` | `STRIPE_TIMEOUT_SECONDS !== CurlClient::DEFAULT_TIMEOUT` / `STRIPE_CONNECT_TIMEOUT_SECONDS !== CurlClient::DEFAULT_CONNECT_TIMEOUT` / `AWS_MAX_ATTEMPTS !== Aws\Retry\ConfigurationProvider::DEFAULT_MAX_ATTEMPTS`（**負のコントロール**） |
| 10 | `AWS config: s3 / ses が http と retries を宣言する` | `config('filesystems.disks.s3.http.timeout')` 等が pin 値 |
| 11 | `AWS behavioral: 構築したクライアントの @http / @retries が pin 値` | `Storage::build(...)` / `Mail::mailer('ses')…->ses()` / `app(SnsClient::class)` の `getCommand()` を検査 |
| 12 | `時間予算: 外部予算 + 局所予算 < worker --timeout < retry_after` | `QueueLeaseConfig::databaseConnections()['database']` と `ExternalClientTimeouts` の**厳密不等号** |

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（走査ヘルパは `list<…>` / `array<…>` を `@return` で宣言）
- [x] null 安全（`Assert::string()` / `Assert::isArray()` で narrowing。`Webmozart\Assert` 使用）
- [x] DTO を返している → 対象外（テスト内の純関数）
- [x] Generics の型パラメータが正しい（`ReflectionClass<object>` を明示）
- [x] token 走査は `QueuedJobLeaseInventoryTest::jobLeaseNormalizedTokens()` と同じ正規化を
      **`tests/Support/PhpTokenScan.php` へ切り出して共有**する（同じ実装を 2 本持たない）

### テスト計画

- [ ] 本施策そのものがテスト。追加で以下の**走査精度テスト**を
      `tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php` に置く
      （Codex Round 4 の指摘。fixture 文字列を食わせる純関数テスト）:
  - `use ... as ... の alias を解決する`
  - `完全修飾名と import 済み short name の両方を検出する`
  - `nullable / union / intersection の型宣言を検出する`
  - `constructor property promotion と attribute の型を検出する`
  - `匿名クラス内の参照を外側クラスへ誤帰属させない`
  - `コメント / 文字列リテラル中の "Aws\" を検出しない`（偽陽性の負のコントロール）
- [ ] 個別 `DatabaseTransactions` を使っていないことを確認

### リスク

- **走査の偽陰性**: 文字列キーの container 解決だけで型参照も `disk(` も出さない経路は
  検出できない（概念設計で明記済み）。docs に規約として書く。
- **走査の偽陽性**: コメント / 文字列中の `Aws\` を拾うと無関係なクラスが母集団に入る。
  `token_get_all()` ベースで `T_COMMENT` / `T_CONSTANT_ENCAPSED_STRING` を除外し、
  上記の負のコントロールで固定する。
- **維持コスト**: 目録が 11 entry。新しい S3 操作を足すたびに 2 目録の更新が要る。
  これは意図した摩擦である（deny-by-default）。

---

## 施策 6: Stripe 呼び出し回数の behavioral 固定

### 変更箇所

- `tests/Support/Billing/CountingStripeHttpClient.php` (新規)
- `tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php` (新規)

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 本施策そのもの

### 現行コード

存在しない。`config/queue.php` のコメントが「Stripe 4〜5 呼び出し」と**手計算で**書いているだけで、
機械固定は無い。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use Stripe\HttpClient\ClientInterface;

/**
 * Stripe SDK の HTTP 呼び出し回数を数える fake client (**送信しない**)。
 *
 * ★`ApiRequestor::setHttpClient()` は Stripe SDK 公式の差し込み口である。
 *   Cashier 内部 (`createOrGetStripeCustomer` 等) の呼び出しもここを通るため、
 *   静的な呼び出し site 計数では数えられない分まで含めて数えられる。
 * ★外部 HTTP は一切発生しない (AGENTS.md の egress 規約に抵触しない)。
 *
 * @implements ClientInterface
 */
final class CountingStripeHttpClient implements ClientInterface
{
    public int $calls = 0;

    /** @var list<array{status: int, body: string}> 先頭から消費する応答列 */
    private array $responses;

    /** @param list<array{status: int, body: string}> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    /** 応答列を使い切ったか (使い切っていなければ経路が想定より短い = 偽グリーン) */
    public function isExhausted(): bool
    {
        return $this->responses === [];
    }

    /**
     * @param  array<int, string>  $headers
     * @return array{0: string, 1: int, 2: array<string, list<string>>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->calls++;
        $response = array_shift($this->responses);
        // 応答列が尽きたら fail-loud (黙って空 body を返さない)
        Assert::isArray($response, 'CountingStripeHttpClient: 想定より多い Stripe 呼び出しが発生しました');

        return [$response['body'], $response['status'], []];
    }
}
```

```php
// tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php (骨子)

/**
 * 代表経路のデータセット。**なぜこれが分岐集合を代表するか**をキー名に残す
 * (Codex 詳細レビューの要求)。
 */
dataset('auto-recharge の外部呼び出し経路', [
    '成功 (customer 新規 = customer 作成の 1 呼び出しが増える最長側)' => [...],
    '成功 (customer 既存 = retrieve だけ。基準経路)' => [...],
    'カード拒否 → invoice void (後始末の追加呼び出しが載る経路)' => [...],
    '既存 invoice の再利用 (finalize 済みで InvalidRequest → pay へ進む経路)' => [...],
]);

test('既定接続の Stripe 呼び出しは予算を超えない', function (array $responses): void {
    $original = ApiRequestor::httpClient();
    $counting = new CountingStripeHttpClient($responses);

    try {
        ApiRequestor::setHttpClient($counting);

        $attempt = TicketAutoRechargeAttempt::factory()->pending()->create();
        app(AutoRechargeService::class)->executeAttempt($attempt);

        expect($counting->calls)
            ->toBeLessThanOrEqual(ExternalClientTimeouts::DEFAULT_CONNECTION_STRIPE_CALL_BUDGET);
        // ★空振り防止: 応答列を使い切っていること (経路が途中で終わっていない)
        expect($counting->isExhausted())->toBeTrue();
        // ★退化 PASS 防止: 1 回も呼ばずに green にならないこと
        expect($counting->calls)->toBeGreaterThan(0);
    } finally {
        ApiRequestor::setHttpClient($original);
    }
})->with('auto-recharge の外部呼び出し経路');
```

> **前提の明示**: このテストは **実 `CashierAutoRechargeGateway`** を使う必要がある。
> テストレーンは `FakeExternalsServiceProvider` が
> `AutoRechargeGatewayInterface` を fake に rebind しうるため、
> 本テストでは `$this->app->bind(AutoRechargeGatewayInterface::class, CashierAutoRechargeGateway::class)`
> で実装へ戻す（`config('testing.fake_externals')` の既定は false なので通常は不要だが、
> **明示して前提が変わっても壊れないようにする**）。
> `ExternalFakeWiringInvariantTest` の母集団に影響しないことを実装時に確認する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`request()` は vendor の docblock に合わせた shape を宣言）
- [x] null 安全（`array_shift` の戻りを `Assert::isArray` で narrowing）
- [x] DTO を返している → 対象外（テスト support）
- [x] Generics の型パラメータが正しい（`list<array{status: int, body: string}>`）

### テスト計画

- [ ] 本施策そのもの。テストデータは **Factory**（`TicketAutoRechargeAttempt::factory()`）で生成する
- [ ] 個別 `DatabaseTransactions` を使わない（`RefreshDatabase` グローバル適用に従う）
- [ ] `Http::fake()` 不要（Stripe SDK は `Http::` を通らない。実送信も無い）

### リスク

- **Stripe 応答 JSON の作り込みが必要**。`invoices->create` / `pay` 等が返す JSON を
  fixture で用意する必要があり、SDK のバージョン更新で形が変わると壊れる。
  緩和: 既存 `StripePriceCatalogFixtureInvariantTest` が fixture 運用の先例。
  最小限のフィールド（`id` / `object` / `amount_paid` / `amount_due` / `status`）だけを持たせる。
- **プロセス大域 setter を使う 2 本目のテスト**。`finally` で必ず復元する。
  施策 5 の検査 5 がこの 2 本以外での setter 使用を app/ 側で禁じている
  （tests/ 側は本ファイルと施策 2 のテストの 2 本のみで、gate の走査対象に tests/ を含める）。

---

## 施策 7: web 経路が `Bulk` を呼ばないことの固定

### 変更箇所

- `tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php` (新規)

### 波及変更

なし（テストのみ）

### 現行コード

存在しない。

### 変更後コード

```php
/**
 * 撮影テイク登録の web 経路が `Bulk` 面の S3 操作を呼ばないことを固定する。
 *
 * ★「Bulk を web から呼ばない」は規約であって機械証明ではない (呼び出しグラフ解析が要る)。
 *   **既存の web 経路については behavioral に固定する**、が本テストの位置づけである。
 */
test('テイク登録エンドポイントは BoundedControl / NoObjectRequest 面しか呼ばない', function (): void {
    $spy = new class extends TakeObjectStorage {
        /** @var list<string> 呼び出し順を保つ (意図しない追加呼び出しの診断用) */
        public array $calls = [];
        // 各 public メソッドを override して $this->calls[] = __FUNCTION__; を記録し、
        // headObject だけ ObjectMetadataData を返す
    };
    $this->app->instance(TakeObjectStorage::class, $spy);

    // ... Factory で組織 / プロジェクト / 予約を作り、登録エンドポイントを叩く

    $bulkMethods = array_keys(array_filter(
        S3_OPERATION_SURFACE_INVENTORY[TakeObjectStorage::class],
        static fn (array $e): bool => $e[0] === S3OperationSurface::Bulk,
    ));

    expect($spy->calls)->not->toBeEmpty();                    // 空振り防止
    expect(array_intersect($spy->calls, $bulkMethods))->toBe([]);
});
```

> 目録定数は `QueuedJobLeaseInventoryTest` のコメントどおり **他テストファイルの
> グローバル定数を参照しない**（`--parallel` はファイル単位でプロセスを分けるため未定義になりうる）。
> 面分類は **`S3OperationSurface` を返す static メソッドとして
> `tests/Support/Storage/S3SurfaceInventory.php` に置き、両テストから参照**する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全
- [x] DTO を返している → 対象外
- [x] Generics の型パラメータが正しい（`list<string>`）

### テスト計画

- [ ] 本施策そのもの。テストデータは Factory で生成
- [ ] 呼び出し**順序と回数**も `$spy->calls` に残す（Codex Round 4 の助言。診断性のため）
- [ ] 個別 `DatabaseTransactions` を使わない

### リスク

- spy が `TakeObjectStorage` を継承するため、**新しい public メソッドを足したときに
  override 漏れ**が起きる。緩和: 施策 5 の面分類目録が対称差ゼロを要求するので、
  メソッド追加時に必ず目録更新 → 本テストの spy も同時に見直される導線ができる。

---

## 施策 8: timeout 例外の分類固定 (T132 整合)

### 変更箇所

- `tests/Unit/Billing/GatewayFailureClassifierTest.php` (既存へ追記。無ければ新規)

### 波及変更

- `app/Support/Billing/GatewayFailureClassifier.php`: **変更なし**。
  `directMap()` は既に `ApiConnectionException => ProviderUnavailable` を持つ

### 現行コード

```php
// app/Support/Billing/GatewayFailureClassifier.php L127
ApiConnectionException::class => GatewayFailureClass::ProviderUnavailable, // HTTP 到達前の接続断
```

### 変更後コード

コード変更なし。テストを追加する:

```php
test('Stripe の timeout 由来例外は ProviderUnavailable に分類される', function (): void {
    // ★cURL の timeout は ApiRequestor::handleCurlError() が ApiConnectionException へ変換する。
    //   timeout を短く pin すると本例外の出現頻度が上がるため、分類の対応関係を固定する。
    //   分類表 (directMap) を変えないことの根拠を CI に残すのが目的である。
    $exception = new ApiConnectionException('Operation timed out after 20000 milliseconds');

    expect(GatewayFailureClassifier::classify($exception))
        ->toBe(GatewayFailureClass::ProviderUnavailable);

    // 観測語彙は 2 キーのまま (例外 message を載せない)
    expect(array_keys(GatewayFailureClassifier::context($exception)))
        ->toBe(['failure_class', 'error_class']);
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている / null 安全 / DTO 不要 / Generics 不使用

### テスト計画

- [ ] 上記 1 ケース。既存 `BillingGatewayFailureTaxonomyInventoryTest` の
      exact-fit 検査（宣言した catch 箇所の数 == `context(` 出現回数）に影響しないことを確認
- [ ] 個別 `DatabaseTransactions` を使わない

### リスク

- なし（テスト追加のみ）。**分類表を変えない**という判断を CI に固定するだけである。

---

## 施策 9: 帯の張り替え + docs + 既存 gate 更新

### 変更箇所

- `config/queue.php` (L37-53 の `database` 接続とコメント)
- `mprocs.yaml` (L20 の `queue` proc)
- `docs/architecture.md` (L245-282 §キューのリース期間とワーカー制限時間の規約) + 新節
- `tests/Architecture/QueueWorkerLeaseInvariantTest.php` (L399-423 の literal 600 assert)

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル:
  - `QueueWorkerLeaseInvariantTest`（literal 600 → 360）
  - `JobExclusionOrderingInvariantTest`: **変更不要**（`LOCK_TTL_SECONDS 180 < 360` /
    `uniqueFor 30 < 360` はいずれも成立。実装時に実行して確認する）
  - `QueuedJobLeaseInventoryTest`: **変更不要**（既定接続のジョブは `$timeout` を持たない）
  - `tests/Feature/Queue/WorkerTimeoutTransitionTest.php`: 実装時に 540/600 の直書きが
    無いことを確認済み（`rg` で 0 件）
- `scripts/bug-hunt-shard.sh`: **変更不要**（`BUGHUNT_WORKER_CONNECTIONS` に
  `database` は含まれない。実査済み）

### 現行コード

```php
// config/queue.php
// 600s の根拠: この接続の既知の有限上限は ExecuteAutoRechargeAttemptJob の
// Stripe 4〜5 呼び出し × SDK 上限 80s (Stripe\HttpClient\CurlClient::DEFAULT_TIMEOUT)
// = 約 400s。ワーカー --timeout 540 (< 600) がそれを上回る
'database' => [
    'driver' => 'database',
    // ...
    'retry_after' => 600,
    'after_commit' => false,
],
```

```yaml
# mprocs.yaml L20
  queue:
    shell: "php artisan queue:listen database --tries=1 --timeout=540"
```

```php
// tests/Architecture/QueueWorkerLeaseInvariantTest.php L410-415
expect($connections['database'])->toBe(
    600,
    '規則 1: config/queue.php の database.retry_after が env で上書きされた。'
    .'env('."'DB_QUEUE_RETRY_AFTER'".') ではなくリテラル 360 で持つこと',
);
```

### 変更後コード

```php
// config/queue.php
// 既定接続 (Billing 6 / Mail 2 / Notification 6)。retry_after は **リテラル**で持つ:
// 静的 gate (QueueWorkerLeaseInvariantTest) は config をテスト環境の値で読むため、
// env 上書きを残すと「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
// 360s の根拠 (T126 で SDK 既定依存を解消):
//   外部予算 200s (= Stripe 20s × 呼び出し予算 10 回。App\Support\ExternalClientTimeouts)
//   + 局所予算 90s = 290s < ワーカー --timeout 300s < retry_after 360s。
//   序列は ExternalClientTimeoutInventoryTest が厳密不等号で固定する
//   (docs/architecture.md §キューのリース期間とワーカー制限時間の規約 /
//    §外部 SDK の待ち上限の規約)。
'database' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => env('DB_QUEUE', 'default'),
    'retry_after' => 360,
    'after_commit' => false,
],
```

```yaml
# mprocs.yaml
  queue:
    shell: "php artisan queue:listen database --tries=1 --timeout=300"
```

`docs/architecture.md` の値表:

| 接続 | `retry_after` | ワーカー `--timeout` | 備考 |
|---|---|---|---|
| `database` | **360** | **300** | 外部予算 200s (Stripe 20s × 10 回) + 局所予算 90s = 290 < 300 |

さらに **新節「§外部 SDK の待ち上限の規約」** を追加し、以下を書く:

1. 面ごとの pin 値表（Stripe / AWS 制御系 / AWS データ系 / S3 per-command）
2. **「HTTP 試行 timeout 予算」は wall-clock deadline ではない**という断り書き
3. `max_network_retries = 0` の根拠（課金の一回性は idempotency key とリコンサイルが担う）
4. S3 到達境界の規約（業務層は `TakeObjectStorage` / `RenderObjectStorage` しか参照しない。
   文字列 container alias 経由の迂回は gate が検出できないので**やらない**）
5. `Bulk` 面を web 同期経路から呼ばない規約と、その保証範囲（規約であって証明ではない）

および **「帯を変更するときのデプロイ順序」**（リポジトリ外の supervisor があるため必須）:

```
1. 全 worker の supervisor 定義を --timeout=540 → 300 へ変更して再起動する
   (このときコードは旧のまま = retry_after 600。300 < 600 で規則 1 は成立)
   ★確認方法: 各 worker ホストで `pgrep -af 'queue:work database'` を実行し、
     出力の全行に `--timeout=300` が含まれること。実施主体は本番デプロイ担当。
2. 新コード (SDK pin + retry_after 360) をデプロイし、全 worker を入れ替える
3. 旧 worker が残っていないことを確認する
   ★確認方法: 同コマンドで `--timeout=540` の行が 0 件であること。
     加えてデプロイ開始時刻より前に起動した `queue:work database` プロセスが
     残っていないこと (`ps -o lstart=`)
```

**受容事項**: 手順 1 の間、旧コード（Stripe 80s 前提）のジョブが 300s で SIGALRM されうる。
`ExecuteAutoRechargeAttemptJob` は `$tries = 1` でリコンサイルが再試行を担うため、
恒久喪失にはならない（Stripe idempotency key により二重課金にもならない）。

```php
// tests/Architecture/QueueWorkerLeaseInvariantTest.php
expect($connections['database'])->toBe(
    360,
    '規則 1: config/queue.php の database.retry_after が env で上書きされた。'
    .'env('."'DB_QUEUE_RETRY_AFTER'".') ではなくリテラル 360 で持つこと',
);
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（config / yaml / md には型が無い）
- [x] null 安全 / DTO 不要 / Generics 不使用

### テスト計画

- [ ] 既存 `tests/Architecture/QueueWorkerLeaseInvariantTest.php` の更新
      （**削除・上書きではなく期待値の更新**。禁止事項 3 に抵触しない）
  - `規則 1: database の retry_after は env で上書きできない` の期待値 600 → 360
  - 既存の `規則 1: mprocs のキューワーカーは --timeout を明示し retry_after を下回る` は
    値を持たないため無変更（300 < 360 で通る）
- [ ] 新規 `ExternalClientTimeoutInventoryTest`「時間予算」ケース（施策 5 の #12）:
  ```php
  $retryAfter = QueueLeaseConfig::databaseConnections()['database'];
  $budget = ExternalClientTimeouts::DEFAULT_CONNECTION_EXTERNAL_BUDGET_SECONDS
      + ExternalClientTimeouts::DEFAULT_CONNECTION_LOCAL_BUDGET_SECONDS;

  expect($budget)->toBeLessThan(ExternalClientTimeouts::DEFAULT_CONNECTION_WORKER_TIMEOUT_SECONDS);
  expect(ExternalClientTimeouts::DEFAULT_CONNECTION_WORKER_TIMEOUT_SECONDS)->toBeLessThan($retryAfter);
  ```
- [ ] 新規 同ファイル「`mprocs.yaml` の database worker `--timeout` が定数と一致する」
      — `docs` と config とワーカー定義の 3 者が割れないようにする
      （既存 `QueueWorkerLeaseInvariantTest` は「retry_after 未満」しか見ておらず、
      `ExternalClientTimeouts` の宣言値と一致するかは見ていない）
- [ ] 個別 `DatabaseTransactions` を使わない

### リスク

- **本番 supervisor の反映漏れ**が最大のリスク。CI は検知できない。
  緩和: docs にデプロイ順序と確認コマンドを書き、運用上の破壊的変更として扱う。
- **`retry_after` を縮めると、ジョブが 360s を超えたときの二重配送が早く起きる**。
  これは規則 1（worker `--timeout` 300 < 360）で守られている。
  結果の一回性は AG-082 の 4 層（条件付き UPDATE / 台帳 UNIQUE / Stripe idempotency key）が担う。

---

## 検査が空振りしないことの保証

「gate は green だが実際は何も検査していない」を構造的に排除する。

| 機構 | 具体的な実装 | 何を防ぐか |
|---|---|---|
| **母集団 0 件で fail** | 到達境界の走査結果 / adapter の public メソッド / `driver=database` 接続 / spy の呼び出し記録 / Stripe 計数 `> 0` を、いずれも `not->toBeEmpty()` または `toBeGreaterThan(0)` で守る | 走査条件を壊して 0 件になったときに green にならない |
| **exact-fit (対称差ゼロ)** | 到達境界目録・面分類目録の両方で `array_diff` を**双方向**に取る（`missing` と `stale`） | 件数一致だけの弱い検査 / 目録の残骸 |
| **負のコントロール (SDK 既定との差)** | `STRIPE_TIMEOUT_SECONDS !== CurlClient::DEFAULT_TIMEOUT` / `STRIPE_CONNECT_TIMEOUT_SECONDS !== CurlClient::DEFAULT_CONNECT_TIMEOUT` / `AWS_MAX_ATTEMPTS !== ConfigurationProvider::DEFAULT_MAX_ATTEMPTS` | 「pin していないのに green」 |
| **負のコントロール (per-command が client 既定を上書きする)** | `headObject` の `@http.timeout` が `AWS_S3_TIMEOUT_SECONDS` **ではない**ことを assert | per-command 上書きが実は効いていないのに green |
| **負のコントロール (走査の偽陽性)** | コメント / 文字列リテラル中の `Aws\` を検出しないことを fixture で固定 | 走査が「何でも拾う」ことで対称差が常に壊れる |
| **behavioral (config だけ見ない)** | `getCommand()['@http']` / `ApiRequestor::httpClient()->getTimeout()` で**実物**を見る | Laravel / vendor が config を素通ししなくなったときに気づけない |
| **応答列の消費検査** | `CountingStripeHttpClient::isExhausted()` が true | 経路が想定より早く終わって「呼び出しが少ない」で green |
| **面分類の退化防止** | `BoundedControl` が 1 件以上あること | 全部 `Bulk` にすれば通る、を防ぐ |
| **登録漏れ検査** | `bootstrap/providers.php` に pin provider が含まれること | 本番だけ pin されない最悪の偽グリーン |
| **厳密不等号** | 時間予算の序列は `toBeLessThan`（`toBeLessThanOrEqual` にしない） | 等号で「使い切ったら間に合わない」状態を通す |

## mutation で赤化を確認する手順

新設 gate は**素の main では赤にならない**（実装後に green になるのが正常）。
「gate が本当に効いているか」を実装 worktree で以下の mutation により確認する。
**各 mutation は適用 → 対象テストが赤 → `git checkout -- <file>` で戻す**を 1 セットとする。

| # | mutation | 期待して赤くなるテスト |
|---|---|---|
| 1 | `ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS` を `80` (SDK 既定) にする | 「pin 値は SDK 既定値と異なる」 |
| 2 | `ExternalClientTimeouts::AWS_MAX_ATTEMPTS` を `3` (SDK 既定) にする | 同上 |
| 3 | `config/filesystems.php` の `...awsS3ClientOptions()` 行を削除 | 「AWS config: s3 が http / retries を宣言する」+ behavioral |
| 4 | `config/services.php` の `...awsControlClientOptions()` 行を削除 | 「AWS config: ses が …」+ behavioral |
| 5 | `ExternalClientTimeoutServiceProvider::boot()` の中身を空にする | 施策 2 の pin 検査 |
| 6 | `bootstrap/providers.php` から provider 行を削除 | 「provider が登録されている」 |
| 7 | `TakeObjectStorage::headObject()` の `...awsControlPlaneCommandOptions()` を削除 | 施策 4 の `@http` / `@retries` 検査 + 負のコントロール |
| 8 | 任意の Service（例 `TakeRegistrationService`）に `Storage::disk('s3')->exists($p);` を 1 行足す | 「到達境界: 対称差ゼロ」 |
| 9 | `TakeObjectStorage` に `public function listObjects(): array` を足す | 「面分類: 対称差ゼロ」 |
| 10 | `CashierAutoRechargeGateway` に Stripe 呼び出しを 3 つ増やす（予算超過） | 施策 6 の呼び出し予算 |
| 11 | `config/queue.php` の `retry_after` を `280` にする（予算 290 未満） | 「時間予算: 厳密不等号」 |
| 12 | `mprocs.yaml` の `--timeout` を `360` にする | 既存「規則 1」+ 新規「mprocs の値が定数と一致」 |
| 13 | `TakeRegistrationService` に `$storage->exists(...)` を足す | 施策 7 の `Bulk` 不使用検査 |
| 14 | `AppServiceProvider` に `ApiRequestor::setHttpClient(new CurlClient);` を足す | 「Stripe の大域 setter は 1 箇所だけ」 |

**記録方法**: 実装 PR の worktree で上記を順に実行し、
`devnotes/20260807-2032-todo-T126-design/mutation-log.md` に
「mutation / 実行コマンド / 赤くなったテスト名 / 復元確認」を残す。
（既存 gate の見本 `ThrottleCoverageInventoryTest` 等と同じ扱い。素の main で赤にならない
gate を「効いている」と主張するには、この記録が唯一の根拠になる）

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 変更対象が `config/*` / `app/Providers` / `app/Services/Capture` / `tests/Architecture` に散るが、**新規ファイルが中心**で既存ファイルの変更は 5 箇所（`config/filesystems.php` 1 行 / `config/services.php` 1 行 / `AppServiceProvider` 1 行 / `TakeObjectStorage` 1 行 / `config/queue.php` + `mprocs.yaml` + 既存 gate の期待値）に限られる。段階的に green を保ちながら積める（施策 1〜8 を先に green にしてから 9 を入れる）。standalone にすると大量の新規ファイルを一度に持ち込むことになり、レビュー粒度が粗くなる |
| 競合リスク | **中**。並行して設計中の T124 / T125 は `routes/web.php` と `app/Http/` が主戦場で、本タスクの主戦場（`config/*` / `app/Support` / `app/Services/Capture`）とほぼ交わらない。ただし **`docs/TODO.md` と `docs/architecture.md` は全タスクが触る**ため、マージ順で衝突しうる。`docs/architecture.md` は**新節を末尾寄りに追加**し、既存節の書き換えは値表 1 行に留めることで衝突面を最小化する |

## 未解決 / 実装時に確認すること

1. `config/filesystems.php` / `config/services.php` を `require` で直読みしている
   テスト・スクリプトが無いこと（`QueueLeaseConfig` が `config/queue.php` を直読みする前例がある）。
   あればクラス定数参照でオートロード前提が崩れるため、その箇所を先に修正する。
2. `Mail::mailer('ses')` の解決がテスト環境（`MAIL_MAILER=array` force）で例外を投げないこと。
   投げる場合は behavioral 検査を `new SesV2Client(...)` 直接構築へ落とす。
3. `S3OperationSurface` / `ExternalClientBoundaryExemption` が
   `ManualEnumTsSyncInvariantTest` / `NotificationTypeTsSyncInvariantTest` の母集団に
   入らないこと（入る場合は TS 定義の追加が波及変更として必要になる）。
4. `docs/TODO.md` の T127 行の説明文にある「最大 510 秒遅れる」という数値は
   本タスク完了後に **270 秒**へ更新が必要。TODO.md の編集は `app-todo-add` /
   `app-todo-close` スキルの責務のため、本設計の施策には含めない（実装完了後に別途行う）。


---

## 関連する現行コード

### `config/queue.php (database 接続)`

```
        ],

        // 既定接続 (Billing 6 / Mail 2 / Notification 6)。retry_after は **リテラル**で持つ:
        // 静的 gate (QueueWorkerLeaseInvariantTest) は config をテスト環境の値で読むため、
        // env 上書きを残すと「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
        // 600s の根拠: この接続の既知の有限上限は ExecuteAutoRechargeAttemptJob の
        // Stripe 4〜5 呼び出し × SDK 上限 80s (Stripe\HttpClient\CurlClient::DEFAULT_TIMEOUT)
        // = 約 400s。ワーカー --timeout 540 (< 600) がそれを上回る
        // (docs/architecture.md §キューのリース期間とワーカー制限時間の規約)。
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => 600,
            'after_commit' => false,
        ],

        // AI 解析専用 (RunManualAnalysis)。retry_after は job timeout (1,560s) より長く
        // 予約 TTL (1,800s) より短い (AnalysisTimeBudgetInvariantTest が連鎖を固定)。
```
### `config/filesystems.php (disks.s3)`

```
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],
```
### `config/services.php (ses)`

```
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        // SESv2 SendEmail にマージされる送信オプション。Configuration Set を結線して
        // バウンス/苦情イベントを SNS topic へ発行させる (未設定なら付与しない)。
        'options' => array_filter([
            'ConfigurationSetName' => env('SES_CONFIGURATION_SET'),
        ]),
        // SES/SNS 通知受信の TopicArn allowlist (カンマ区切り)。空のときは全通知を拒否。
        'sns_topic_arns' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SES_SNS_TOPIC_ARNS', ''))
        ))),
    ],
```
### `app/Providers/AppServiceProvider.php (SnsClient singleton)`

```
        );

        // SES/SNS バウンス・苦情処理。
        // - SnsClient: SubscriptionConfirmation の confirmSubscription に使う (services.ses の認証情報を流用)。
        // - SnsSignatureVerifier: SNS 署名検証 (暗号検証は AWS SDK MessageValidator に委譲)。
        $this->app->singleton(SnsClient::class, function (Application $app): SnsClient {
            /** @var array<string, mixed> $ses */
            $ses = (array) config('services.ses', []);
            $config = [
                'version' => 'latest',
                'region' => is_string($ses['region'] ?? null) ? $ses['region'] : 'us-east-1',
            ];
            $key = $ses['key'] ?? null;
            $secret = $ses['secret'] ?? null;
            if (is_string($key) && $key !== '' && is_string($secret) && $secret !== '') {
                $config['credentials'] = ['key' => $key, 'secret' => $secret];
            }

            return new SnsClient($config);
        });
        $this->app->bind(SnsSignatureVerifier::class, AwsSnsSignatureVerifier::class);

```
### `bootstrap/providers.php`

```
<?php

use App\Providers\AppServiceProvider;
use App\Providers\FakeExternalsServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\McpPassportServiceProvider;
use App\Providers\PasskeyServiceProvider;
use App\Providers\SeoServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    // passkey (laravel/passkeys) の app アダプタ。Fortify が feature flag で route を
    // 登録するため **FortifyServiceProvider より後**に置く。ただし binder / middleware の
    // 後付けは provider 順序に依存しないよう $app->booted() 内で最終上書きする
    PasskeyServiceProvider::class,
    // Passport は composer.json の dont-discover で自動 discovery を無効化し、
    // grant / repository を差し替えた本 Provider を唯一の登録点にする (WP23)
    McpPassportServiceProvider::class,
    SeoServiceProvider::class,
    // 外部 fake の条件付き rebind (flag 既定 false = no-op)。
    // AppServiceProvider の実装 bind を後勝ちで上書きするため必ず末尾側に置く
    FakeExternalsServiceProvider::class,
];
```
### `app/Services/Capture/TakeObjectStorage.php`

```
<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DataTransferObjects\Capture\ObjectMetadataData;
use App\DataTransferObjects\Capture\PresignedUploadData;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Webmozart\Assert\Assert;

/**
 * テイク動画 S3 オブジェクト操作の集約点 (概念設計 D11)。presign / HeadObject /
 * 署名 GET / 削除はすべて本クラス経由 (Feature テストでは container mock。
 * 配線検証は TakeObjectStorageTest が実 SDK オブジェクト + 偽エンドポイントで固定)。
 *
 * presigned PUT は SDK の createPresignedRequest を直接使い **ChecksumSHA256** を署名対象に
 * 含める (temporaryUploadUrl は署名できないため)。PHP SDK は content-type/length を presign
 * 署名から除外するが、checksum が本文を一意に固定するため内容・サイズは改変不能で、
 * content-type の照合は登録時の HeadObject 三点照合が担う。S3 は本文がチェックサムと
 * 一致しない PUT を拒否するため、この URL で置ける内容は申告ハッシュの 1 通りに固定される
 * = 登録後の再 PUT 差し替え防止 (概念設計 D2b)。
 */
class TakeObjectStorage
{
    public function presignUpload(string $path, string $contentType, int $sizeBytes, string $checksumSha256, CarbonImmutable $expiresAt): PresignedUploadData
    {
        $client = $this->client();
        $command = $client->getCommand('PutObject', [
            'Bucket' => $this->bucket(),
            'Key' => $path,
            'ContentType' => $contentType,
            'ContentLength' => $sizeBytes,
            'ChecksumSHA256' => $checksumSha256, // x-amz-checksum-sha256 として署名される
        ]);
        // 期限は DateTimeInterface で渡す (int timestamp は SDK 実装依存の誤解を招くため。
        // 期限・署名クエリは TakeObjectStorageTest が固定検証する)
        $request = $client->createPresignedRequest($command, $expiresAt);

        return new PresignedUploadData(
            url: (string) $request->getUri(),
            headers: [
                'Content-Type' => $contentType,
                'x-amz-checksum-sha256' => $checksumSha256,
            ],
            expiresAt: $expiresAt,
        );
    }

    /**
     * オブジェクトが存在しなければ null (PUT 未完了)。ChecksumMode=ENABLED で
     * ChecksumSHA256 も取得する (欠落する互換実装では null = 照合スキップの二重防御位置づけ)。
     */
    public function headObject(string $path): ?ObjectMetadataData
    {
        try {
            $result = $this->client()->headObject([
                'Bucket' => $this->bucket(),
                'Key' => $path,
                'ChecksumMode' => 'ENABLED',
            ]);
        } catch (S3Exception $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }

        $contentLength = $result['ContentLength'];
        Assert::numeric($contentLength, 'HeadObject の ContentLength を取得できません');
        $contentType = $result['ContentType'] ?? null;
        $checksum = $result['ChecksumSHA256'] ?? null;

        return new ObjectMetadataData(
            contentLength: (int) $contentLength,
            contentType: is_string($contentType) ? $contentType : null,
            checksumSha256: is_string($checksum) ? $checksum : null,
        );
    }

    /** 採用テイク再生用の署名 GET URL (TTL は config capture.playback_url_ttl_minutes) */
    public function temporaryPlaybackUrl(string $path): string
    {
        return Storage::disk('s3')->temporaryUrl(
            $path,
            now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
        );
    }

    /** オブジェクト削除 (存在しないキーは no-op = 冪等) */
    public function delete(string $path): void
    {
        Storage::disk('s3')->delete($path);
    }

    /** stale 掃除用の存在確認 */
    public function exists(string $path): bool
    {
        return Storage::disk('s3')->exists($path);
    }

    /**
     * s3 disk の S3Client (テストでは本メソッドを override して MockHandler client を注入する)。
     */
    protected function client(): S3Client
    {
        $disk = Storage::disk('s3');
        Assert::isInstanceOf($disk, AwsS3V3Adapter::class);

        return $disk->getClient();
    }

    protected function bucket(): string
    {
        return config()->string('filesystems.disks.s3.bucket');
    }
}
```
### `app/Services/Render/RenderObjectStorage.php (抜粋)`

```
<?php

declare(strict_types=1);

namespace App\Services\Render;

use App\Models\VideoManual;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * レンダ出力 S3 オブジェクト操作の集約点 (TakeObjectStorage と同じ Storage::disk('s3') 経由)。
 * ダウンロード/アップロード/署名 URL/削除はすべて本クラス経由 (Feature テストは Storage::fake('s3'))。
 *
 * disk 解決は disk() の 1 箇所に集約する。fake (FakeRenderObjectStorage) は disk() を s3_fake へ
 * override するだけで read/download 系を切り替えられる (後方互換の並走を残さない)。
 */
class RenderObjectStorage
{
    /** 集約された disk 解決点 (fake は本メソッドを override する。挙動不変) */
    protected function disk(): Filesystem
    {
        return Storage::disk('s3');
    }

    /** S3 素材をローカル一時ファイルへ取得する (readStream → ローカル書き込み) */
    public function downloadToLocal(string $key, string $localPath): void
    {
        $stream = $this->disk()->readStream($key);
        if ($stream === null) {
            throw new RuntimeException("S3 オブジェクトを読めません: {$key}");
        }

        $local = fopen($localPath, 'wb');
        if ($local === false) {
            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
        }

        try {
            if (stream_copy_to_stream($stream, $local) === false) {
                throw new RuntimeException("S3 オブジェクトのコピーに失敗しました: {$key}");
            }
        } finally {
            fclose($local);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** ローカル最終 mp4 を S3 出力キーへアップロードする (writeStream) */
    public function upload(string $localPath, string $key): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
        }

        try {
            $this->disk()->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** preview 再生用の署名 GET URL (TTL は config manual.render_playback_url_ttl_minutes) */
    public function temporaryPlaybackUrl(string $key): string
    {
        return $this->disk()->temporaryUrl(
            $key,
            now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
        );
    }

    /**
     * DL 用署名 URL (attachment disposition)。filename 契約 (詳細レビュー Round 1 で明文化):
     * - filename は CR/LF・制御文字を除去し、Content-Disposition は
     *   RFC 5987 (filename*=UTF-8''...) + ASCII fallback (filename="...") の両建てで署名に含める
     * - ヘッダ注入 (改行) 不能であることを Unit テストで固定
     */
    public function temporaryDownloadUrl(string $key, string $filename): string
    {
        return $this->disk()->temporaryUrl(
            $key,
            now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
            ['ResponseContentDisposition' => $this->contentDisposition($filename)],
        );
    }

    /** オブジェクト削除 (存在しないキーは no-op = 冪等) */
    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    /** manual 配下のレンダ出力 prefix (DeleteRenderOutputsJob の過大削除防止に使う) */
    public function keyPrefixFor(VideoManual $manual): string
    {
        return "projects/{$manual->project_id}/manuals/{$manual->id}/";
    }

```
### `tests/Feature/Capture/TakeObjectStorageTest.php (MockHandler ヘルパ)`

```
<?php

declare(strict_types=1);

use App\Services\Capture\TakeObjectStorage;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;

/*
 * TakeObjectStorage (施策3): presign の署名パラメータ配線と HeadObject の
 * ChecksumMode=ENABLED を実 SDK オブジェクトで固定する (mock のみでは配線ミスを
 * 見逃すため。S3Client は偽エンドポイント設定・ネットワーク非到達)。
 */

/** 偽エンドポイントの s3 disk 設定 (ネットワークに一切到達しない) */
function fakeS3DiskConfig(): void
{
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'us-east-1',
        'bucket' => 'test-bucket',
        'endpoint' => 'http://s3.invalid.test',
        'use_path_style_endpoint' => true,
        'throw' => false,
        'report' => false,
    ]);
    Storage::forgetDisk('s3');
}

/** MockHandler 注入済みの TakeObjectStorage (headObject の配線検証用) */
function storageWithMockHandler(MockHandler $handler): TakeObjectStorage
{
    $client = new S3Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
        'handler' => $handler,
    ]);

    return new class($client) extends TakeObjectStorage
    {
        public function __construct(private readonly S3Client $mockClient) {}

        protected function client(): S3Client
        {
            return $this->mockClient;
        }
    };
}

```
### `tests/Architecture/QueueWorkerLeaseInvariantTest.php (literal 600 の assert)`

```
        .'retry_after が短い接続 (database-media) で二重取得の窓を開く)',
    );
});

test('規則 1: database の retry_after は env で上書きできない', function (): void {
    // 静的 gate は config をテスト環境の値で読むため、env 上書きが残ると
    // 「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
    $repository = Env::getRepository();
    $hadOriginal = $repository->has('DB_QUEUE_RETRY_AFTER');
    $original = $hadOriginal ? $repository->get('DB_QUEUE_RETRY_AFTER') : null;

    $repository->set('DB_QUEUE_RETRY_AFTER', '1');

    try {
        $connections = QueueLeaseConfig::databaseConnections();
        expect($connections)->toHaveKey('database');
        expect($connections['database'])->toBe(
            600,
            '規則 1: config/queue.php の database.retry_after が env で上書きされた。'
            .'env('."'DB_QUEUE_RETRY_AFTER'".') ではなくリテラル 600 で持つこと',
        );
    } finally {
        if ($hadOriginal && is_string($original)) {
            $repository->set('DB_QUEUE_RETRY_AFTER', $original);
        } else {
            $repository->clear('DB_QUEUE_RETRY_AFTER');
        }
    }
});
```
### `tests/Architecture/JobExclusionOrderingInvariantTest.php (抜粋)`

```
 */

/** 入口の排他が乗るレーン (= 本番の既定キュー接続) の名前。 */
const JOB_EXCLUSION_DEFAULT_CONNECTION = 'database';

/**
 * 比較先の retry_after。
 *
 * ★ **実行時の `config('queue.default')` は使えない** — テストレーンは phpunit.xml が
 *   `QUEUE_CONNECTION=sync` を force しており、`sync` 接続は `retry_after` を持たない。
 *   「本番の既定接続」は `config/queue.php` の `env('QUEUE_CONNECTION', 'database')` の
 *   **フォールバック値**であり、それが `database` から動いていないことは
 *   下の「比較先の前提」テストがソースレベルで固定する
 *   (既存 QueueWorkerLeaseInvariantTest が「env 上書きを残すと gate が嘘をつく」として
 *    retry_after をリテラルで持たせているのと同じ発想)。
 */
function jobExclusionDefaultRetryAfter(): int
{
    return config()->integer('queue.connections.'.JOB_EXCLUSION_DEFAULT_CONNECTION.'.retry_after');
}

test('入口の排他: auto-recharge の org lock TTL は既定接続の retry_after を下回る', function (): void {
    $retryAfter = jobExclusionDefaultRetryAfter();

    expect(AutoRechargeService::LOCK_TTL_SECONDS)->toBeLessThan(
        $retryAfter,
        'org lock TTL がキューの再配送間隔以上です。ゴーストロックが同一ジョブの再配送より'
        .'長く残ると、正当な再実行を封鎖します。TTL は保証を担わないため短い側に倒すこと。',
    );
});

test('入口の排他: AutoRechargeTriggerJob の uniqueFor は既定接続の retry_after を下回る', function (): void {
    $retryAfter = jobExclusionDefaultRetryAfter();

    expect((new AutoRechargeTriggerJob(1))->uniqueFor)->toBeLessThan(
        $retryAfter,
        'uniqueFor がキューの再配送間隔以上です。ShouldBeUnique の鍵は失敗や timeout で'
        .'解放されないことがあるため (Laravel 公式)、残留時間を再配送間隔の内側に収めること。',
    );
});

test('入口の排他: uniqueFor は正の値である (実質無効化の検出)', function (): void {
    // 0 / 負値は「鍵を持たない」に等しく、ShouldBeUnique の宣言が静かに空洞化する
    expect((new AutoRechargeTriggerJob(1))->uniqueFor)->toBeGreaterThan(0);
});

test('入口の排他: 比較先の前提 — auto-recharge の 2 ジョブは既定接続で動く', function (): void {
    // ★ 接続 pin (T127: 既定キュー接続の分割) が入ると retry_after との比較が意味を失う。
    //   前提が崩れた瞬間に赤くする。
    //   ★ 他テストファイルのグローバル定数 (QUEUED_JOB_LEASE_INVENTORY) は参照しない —
    //     Pest の --parallel はファイル単位でプロセスを分けるため未定義になりうる。
    //     ジョブ実体から直接読めば単体で成立する。
    expect((new AutoRechargeTriggerJob(1))->connection)->toBeNull();
    expect((new ExecuteAutoRechargeAttemptJob(1))->connection)->toBeNull();
});

test('入口の排他: 比較先の前提 — 本番の既定キュー接続は database である', function (): void {
    // ★ 既定接続が差し替わると「どのレーンの再配送間隔と比べているのか」が変わり、
    //   gate は green のまま**別レーンと比較する偽グリーン**になる。
    //   実行時の config('queue.default') はテストレーンで sync に force されるため使えない。
    //   本番既定は config/queue.php の env() フォールバック値なので、そこをソースで固定する。
    $source = file_get_contents(config_path('queue.php'));
    expect($source)->toBeString();
    expect(str_contains((string) $source, "'default' => env('QUEUE_CONNECTION', '".JOB_EXCLUSION_DEFAULT_CONNECTION."')"))
        ->toBeTrue(
            '本番の既定キュー接続が変わりました。入口の排他 TTL / uniqueFor の比較先 (retry_after) が'
            .'意図したレーンかを再検討し、本テストと docs/architecture.md の序列を更新すること。',
        );

    // 比較先そのものが実在すること (degenerate PASS 防止)
    expect(jobExclusionDefaultRetryAfter())->toBeGreaterThan(0);
});
```
### `tests/Support/QueueLeaseConfig.php`

```
<?php

declare(strict_types=1);

namespace Tests\Support;

use Webmozart\Assert\Assert;

/**
 * キューのリース期間 (retry_after) の「リポジトリに書かれている値」を読む helper。
 *
 * ★ `config()` 経由にしない。テスト env は `QUEUE_CONNECTION=sync` であり、
 *   env 上書き (`DB_QUEUE_RETRY_AFTER` 等) も混ざるため、config() で読むと
 *   「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
 *   ここでは `config/queue.php` を **直接 require** して素の配列を読む。
 *
 * Pest のファイルスコープ関数はテストファイル間で衝突しうるため、
 * `Tests\Support\AnalysisBudget` と同じくクラスの static メソッドへ集約する
 * (QueueWorkerLeaseInvariantTest / QueuedJobLeaseInventoryTest の両方から使う)。
 */
final class QueueLeaseConfig
{
    /**
     * `driver` が `database` の接続 (接続名 => retry_after 秒)。
     *
     * @return array<string, int>
     */
    public static function databaseConnections(): array
    {
        $config = require self::configPath();
        Assert::isArray($config, 'config/queue.php が配列を返していません');
        Assert::keyExists($config, 'connections', 'config/queue.php に connections がありません');

        // 配列 offset 式のままだと narrowing が保たれないためローカル変数へ移す
        $connections = $config['connections'];
        Assert::isArray($connections, 'config/queue.php の connections が配列ではありません');

        $result = [];
        foreach ($connections as $name => $connection) {
            Assert::string($name, 'config/queue.php の接続名が文字列ではありません');
            Assert::isArray($connection, "config/queue.php の接続 {$name} が配列ではありません");

            $driver = $connection['driver'] ?? null;
            if ($driver !== 'database') {
                continue;
            }

            Assert::keyExists($connection, 'retry_after', "接続 {$name} に retry_after がありません");
            $retryAfter = $connection['retry_after'];
            Assert::integer($retryAfter, "接続 {$name} の retry_after が int ではありません");
            Assert::greaterThan($retryAfter, 0, "接続 {$name} の retry_after が正の整数ではありません");

            $result[$name] = $retryAfter;
        }

        Assert::notEmpty($result, 'driver=database の接続が 1 つもありません');

        return $result;
    }

    /** `config/queue.php` の絶対パス (テストは worktree のルートから走る)。 */
    public static function configPath(): string
    {
        return dirname(__DIR__, 2).'/config/queue.php';
    }
}
```
### `app/Support/Billing/GatewayFailureClassifier.php (directMap 抜粋)`

```
        return [
            'failure_class' => self::classify($throwable)->value,
            'error_class' => $throwable::class,
        ];
    }

    /**
     * 直接写像 (class => case) の正本。
     *
     * ★根拠は推測ではなく **vendor の throw site**。Stripe 側は
     *   `vendor/stripe/stripe-php/lib/ApiRequestor.php` の `_specificV1APIError()` の
     *   HTTP status switch が正本 (400 => InvalidRequest / 400+idempotency_error => Idempotency /
     *   400+rate_limit => RateLimit / 401 => Authentication / 402 => Card / 403 => Permission /
     *   404 => InvalidRequest / 429 => RateLimit / default => UnknownApiError)。
     *   `_specificV2APIError()` は temporary_session_expired のみ振り分けて V1 へ委譲する。
     * ★**値に GatewayFailureClass::Unknown を置かない** (unknown は写像の不在専用)。
     * ★**vendor 全件分類 gate のため、gateway 経路で通常発生しない Stripe 例外
     *   (SignatureVerificationException = webhook 署名検証用 など) も観測語彙上は分類する。**
     *   分類は「もし来たら何と呼ぶか」の宣言であって「来る」という主張ではない
     *   (母集団に穴を空けると、SDK 更新で増えた例外が無音で unknown へ落ちる)。
     *
     * @return array<class-string<Throwable>, GatewayFailureClass>
     */
    public static function directMap(): array
    {
        return [
            // --- Stripe SDK: 決済事業者側の一時的な不能 ---
            ApiConnectionException::class => GatewayFailureClass::ProviderUnavailable, // HTTP 到達前の接続断
            RateLimitException::class => GatewayFailureClass::ProviderUnavailable,     // 429 / 400+rate_limit

            // --- Stripe SDK: 要求が受理されなかった ---
            InvalidRequestException::class => GatewayFailureClass::ProviderRejected,           // 400 / 404
            AuthenticationException::class => GatewayFailureClass::ProviderRejected,           // 401
            CardException::class => GatewayFailureClass::ProviderRejected,                     // 402 (通常は typed 結果へ変換される)
            PermissionException::class => GatewayFailureClass::ProviderRejected,               // 403
            IdempotencyException::class => GatewayFailureClass::ProviderRejected,              // 400 + idempotency_error
            TemporarySessionExpiredException::class => GatewayFailureClass::ProviderRejected,  // V2: temporary_session_expired
            SignatureVerificationException::class => GatewayFailureClass::ProviderRejected,    // webhook 署名不一致 (gateway 経路では発生しない)

            // --- Stripe SDK: SDK の誤用 = 自コードの欠陥 ---
            StripeBadMethodCallException::class => GatewayFailureClass::InvariantViolation,
            StripeInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
            StripeUnexpectedValueException::class => GatewayFailureClass::InvariantViolation,

            // --- Cashier ---
            IncompletePayment::class => GatewayFailureClass::ProviderRejected,          // 追加認証 (SCA) が要る
            CustomerAlreadyCreated::class => GatewayFailureClass::InvariantViolation,   // ManagesCustomer::createAsStripeCustomer
            InvalidCustomer::class => GatewayFailureClass::InvariantViolation,          // ManagesCustomer::assertCustomerExists
            InvalidPaymentMethod::class => GatewayFailureClass::InvariantViolation,     // PaymentMethod::__construct (invalidOwner)
            InvalidInvoice::class => GatewayFailureClass::InvariantViolation,           // Invoice::__construct (invalidOwner)
            InvalidCoupon::class => GatewayFailureClass::InvariantViolation,            // 本アプリは coupon を使わない
            InvalidCustomerBalanceTransaction::class => GatewayFailureClass::InvariantViolation,
            SubscriptionUpdateFailure::class => GatewayFailureClass::InvariantViolation, // Subscription::guardAgainst*

            // --- 非 vendor 明示宣言 (reconcile の catch(Throwable) が実際に受けうるもの) ---
            QueryException::class => GatewayFailureClass::LocalFailure,
            LockTimeoutException::class => GatewayFailureClass::LocalFailure,
            AssertInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
        ];
    }

```
### `app/Services/Billing/CashierAutoRechargeGateway.php (invoice 作成 / off-session pay)`

```
        ];
    }

    public function createAutoRechargeInvoice(
        Organization $organization,
        string $priceId,
        int $quantity,
        array $metadata,
        string $idempotencyKeyBase,
    ): string {
        Assert::greaterThan($quantity, 0);

        $organization->createOrGetStripeCustomer();
        $customerId = $organization->stripe_id;
        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織では invoice を作れません');

        $stripe = $organization->stripe();

        // 順序: draft invoice を先に作り、invoice 指定で item を作る (dangling invoice item 防止)。
        // auto_advance=false で Stripe の自動 finalize / 自動回収 (Smart Retries/dunning) を切る —
        // 「失敗 = void/delete による終端保証」の前提 (遅延成功の二重課金を構造的に排除)。
        $invoice = $stripe->invoices->create([
            'customer' => $customerId,
            'collection_method' => 'charge_automatically',
            'auto_advance' => false,
            'metadata' => $metadata,
        ], ['idempotency_key' => "{$idempotencyKeyBase}:invoice"]);

        $invoiceId = $invoice->id;
        Assert::stringNotEmpty($invoiceId, 'Stripe invoice id missing');

        // basil (2025-08-27) API: トップレベル 'price' は廃止。'pricing' => ['price' => ...] を使う。
        $stripe->invoiceItems->create([
            'customer' => $customerId,
            'invoice' => $invoiceId,
            'pricing' => ['price' => $priceId],
            'quantity' => $quantity,
            'metadata' => $metadata,
        ], ['idempotency_key' => "{$idempotencyKeyBase}:item"]);

        return $invoiceId;
    }

    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto
    {
        $stripe = Cashier::stripe();

        // Stripe invoice 状態機械: draft → finalize → open → pay → paid。
        // 既 finalize 済 (リコンサイル再実行) は invalid_request になり得るため許容して pay へ進む。
        try {
            $stripe->invoices->finalizeInvoice(
                $invoiceId,
                ['auto_advance' => false],
                ['idempotency_key' => "{$idempotencyKeyBase}:finalize"],
            );
        } catch (InvalidRequestException $e) {
            if (! str_contains((string) $e->getMessage(), 'finalized')) {
                throw $e;
            }
        }

        try {
            // basil API では Invoice に payment_intent が直載りしない。InvoicePayment を expand し
            // payments.data[].payment.payment_intent から PI id を解決する。
            $paid = $stripe->invoices->pay($invoiceId, [
                'off_session' => true,
                'expand' => ['payments.data.payment'],
            ], ['idempotency_key' => "{$idempotencyKeyBase}:pay"]);
        } catch (CardException $e) {
            // card_declined / authentication_required 等 → typed 失敗 (終端判断は Service 層)
            return OffSessionChargeResultDto::failed(
                $invoiceId,
                is_string($e->getStripeCode()) ? $e->getStripeCode() : null,
                is_string($e->getDeclineCode()) ? $e->getDeclineCode() : null,
            );
        }

        $amountPaid = $paid->amount_paid;
        $amountDue = $paid->amount_due;
        Assert::integer($amountPaid);
        Assert::integer($amountDue);
```
### `app/Services/Capture/Fakes/FakeTakeObjectStorage.php`

```
<?php

declare(strict_types=1);

namespace App\Services\Capture\Fakes;

use App\DataTransferObjects\Capture\ObjectMetadataData;
use App\DataTransferObjects\Capture\PresignedUploadData;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Storage\Fakes\FakeObjectStore;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * TakeObjectStorage の fake (実 S3 非依存)。presigned PUT を signed route + s3_fake disk で emulate。
 * 契約 (PresignedUploadData / ObjectMetadataData / checksum 照合の趣旨) は実装と同一。
 */
final class FakeTakeObjectStorage extends TakeObjectStorage
{
    public function __construct(private readonly FakeObjectStore $store) {}

    public function presignUpload(string $path, string $contentType, int $sizeBytes, string $checksumSha256, CarbonImmutable $expiresAt): PresignedUploadData
    {
        // 実 S3 presign の代替: signed route。checksum を署名パラメータに固定 (D2b 再PUT差し替え防止)。
        $url = URL::temporarySignedRoute('bughunt.storage.put', $expiresAt, [
            'key' => $path,
            'checksum' => $checksumSha256,
        ]);

        return new PresignedUploadData(
            url: $url,
            headers: [
                'Content-Type' => $contentType,
                'x-amz-checksum-sha256' => $checksumSha256,
            ],
            expiresAt: $expiresAt,
        );
    }

    public function headObject(string $path): ?ObjectMetadataData
    {
        return $this->store->head($path);
    }

    public function temporaryPlaybackUrl(string $path): string
    {
        return URL::temporarySignedRoute(
            'bughunt.storage.get',
            now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
            ['key' => $path],
        );
    }

    public function delete(string $path): void
    {
        $this->store->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->store->exists($path);
    }

    /** fake モードで実 S3 経路に落ちたら fail-loud (LSP drift 検知) */
    protected function client(): S3Client
    {
        throw new RuntimeException('FakeTakeObjectStorage は実 S3 クライアントを構築しません');
    }
}
```
### `mprocs.yaml (queue procs)`

```
  #   shell: "stripe listen --forward-to localhost:8001/stripe/webhook"
  # queue worker の --timeout は「その接続で有効なワーカー制限時間」であり、
  # 必ずその接続の retry_after を下回らせる (規則 1。config/queue.php と
  # tests/Architecture/QueueWorkerLeaseInvariantTest.php が対応)。
  # ★ queue:listen では **ジョブ側の $timeout が効かない** (Listener は子
  #   `queue:work --once` に --timeout を渡さず、Worker::runNextJob() は SIGALRM を
  #   張らない)。ここの --timeout が dev における唯一の上限である。
  # ★ 接続名は必ず明示する (既定接続に頼ると QUEUE_CONNECTION 次第で
  #   どの retry_after と比較すべきか静的に決まらない)。
  queue:
    shell: "php artisan queue:listen database --tries=1 --timeout=540"
  # 専用 connection (analysis/render/media) は既定 database connection の default
  # キューを見る上の worker では拾われないため、docs/architecture.md の運用契約どおり
  # connection ごとに worker を分けて常駐させる (retry_after が connection 固有のため
  # 1 本にまとめない)。
  queue-analysis:
    shell: "php artisan queue:listen database-analysis --tries=1 --timeout=1620"
  queue-render:
    shell: "php artisan queue:listen database-render --tries=1 --timeout=1620"
  queue-media:
    shell: "php artisan queue:listen database-media --tries=1 --timeout=240"
  # pail はログ追尾クライアントであってキューワーカーではない。--timeout は
  # 「追尾を何秒で打ち切るか」であり retry_after とは無関係
```


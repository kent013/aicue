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

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Laravel 12 + Svelte 5 + Inertia のアプリ (AI-CUE) のコードレビュアーである。
TODO **T126「外部 SDK の client timeout を pin する」** の実装差分をレビューせよ。

## レビュー観点
1. **設計との一致性**: 詳細設計書の施策 1〜9 が実装されているか。設計から外れた点があるなら、
   その逸脱が**正当か** (実測に基づくか / 保証を弱めていないか) を判定せよ。
2. **正確性**: vendor (Stripe SDK / AWS SDK / Laravel) の契約理解が正しいか。
   timeout / retry の語彙 (試行回数 vs retry 回数) の取り違えが無いか。
3. **PHPStan level 10 適合性** (app/ config/ database/ routes/ が解析対象。tests/ は対象外)。
4. **DTO / JsonResource パターン**、`response()->json()` 直書きの有無。
5. **テスト網羅性と gate の実効性**: 新設 gate が「空振り」しないか。
   exact-fit (対称差ゼロ) / 負のコントロール / behavioral 検証が本当に機能するか。
   **mutation evidence が主張と一致しているか**。
6. **セキュリティ**: 到達境界の走査に**偽陰性の抜け道**が無いか。保証範囲の記述が誇張でないか。
7. **運用影響**: `retry_after` 600→360 / worker `--timeout` 540→300 の帯変更が、
   既存の不変条件 (T122 の QueueWorkerLeaseInvariantTest / QueuedJobLeaseInventoryTest、
   T131 の JobExclusionOrderingInvariantTest) を壊していないか。
   デプロイ順序の記述に穴が無いか。
8. **DESIGN.md 準拠 / Atomic Design 準拠**: 本差分は `resources/js` / `resources/css` を
   一切変更していないため**対象外**。この点を理由に指摘しないこと。

## 出力形式
- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示する
- **すでに実測で確認済みと明記されている事項** (mutation evidence / 呼び出し回数) を
  「確認できない」だけの理由で Critical にしないこと。疑うなら**どの実測が誤りうるか**を具体的に書け。

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
| 5 | 面分類 enum / 免除 enum / 目録 gate | `app/Enums/Storage/S3OperationSurface.php` (新規) / `app/Enums/Storage/ExternalClientBoundaryExemption.php` (新規) / `tests/Architecture/ExternalClientTimeoutInventoryTest.php` (新規) / `tests/Support/Storage/S3SurfaceInventory.php` (新規) / `tests/Support/ExternalClientBoundaryScanner.php` (新規) / `tests/Support/PhpTokenScan.php` (新規) / `tests/Architecture/QueuedJobLeaseInventoryTest.php` (**既存を変更**) | Critical |
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

    /**
     * AWS SDK クライアントの **試行回数** (SDK 既定 3)。worst case が timeout × attempts に
     * なるため明示 pin する。
     *
     * ★**語彙に注意 (vendor 実査)**: `retries` を array 形式で渡すと
     *   `Aws\Retry\ConfigurationProvider::unwrap()` が `max_attempts` を
     *   **初回を含む試行回数**として解釈し、`ClientResolver::_apply_retries()` が
     *   legacy モードで `maxAttempts - 1` を retry 数に使う。
     *   つまり `max_attempts = 2` は「初回 + 再試行 1 回」である。
     *   一方 per-command の `@retries` (AWS_CONTROL_PLANE_RETRIES) は
     *   **retry 回数**であり `0` = 再試行しない。**同じ数字でも意味が違う**。
     */
    public const int AWS_MAX_ATTEMPTS = 2;

    /**
     * web 同期経路の metadata 操作の **retry 回数** (`@retries`。0 = 再試行しない)。
     *
     * SDK 内で粘らせず、アプリ側で失敗を返して再操作を促す。
     * ★上の AWS_MAX_ATTEMPTS とは語彙が違う (試行回数 vs retry 回数)。
     */
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
    **退避直後に `try` を開く**（Codex の指摘。assert 失敗時も状態を残さない）。
    `ApiRequestor::httpClient()` は
    `if (!self::$_httpClient) { self::$_httpClient = CurlClient::instance(); }` の遅延生成で
    **null を返さない**（vendor 実査）。`setHttpClient()` も nullable を受けないため、
    退避値をそのまま戻せる
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
    // ★**vendor 契約に依存する**: Illuminate\Mail\MailManager::createSesV2Transport() は
    //   array_merge(config('services.ses'), ['version' => 'latest'], $config) を
    //   Arr::except(…, ['transport']) して **そのまま new SesV2Client(...)** へ渡す。
    //   したがって AWS client option は**この配列の直下**に置く必要がある
    //   (`client_options` のようにネストすると AWS の ClientResolver から見て未知キーになり
    //    黙って無視される = pin が効かない)。アプリ設定 (options / sns_topic_arns) と
    //   同居するのは Laravel 側の契約であり、この前提は
    //   ExternalClientTimeoutInventoryTest の
    //   「vendor 契約: MailManager は services.ses を SesV2Client の構築引数へ素通しする」が
    //   behavioral に固定する (Laravel が strict 化した瞬間に赤くなる)。
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
    - テスト環境で `AWS_DEFAULT_REGION` が空の可能性があるため、実 config へ
      ダミーの region / bucket / 資格情報だけを重ねて構築する
      （**実 config の `http` / `retries` はそのまま持ち込む**）。到達手順を具体化する:
      ```php
      $disk = Storage::build(array_merge(
          config()->array('filesystems.disks.s3'),
          ['region' => 'us-east-1', 'bucket' => 'gate', 'key' => 'k', 'secret' => 's'],
      ));
      Assert::isInstanceOf($disk, AwsS3V3Adapter::class); // Storage::build は Filesystem を返す
      $command = $disk->getClient()->getCommand('HeadObject', ['Bucket' => 'gate', 'Key' => 'k']);
      expect($command['@http'])->toBe([
          'connect_timeout' => ExternalClientTimeouts::AWS_S3_CONNECT_TIMEOUT_SECONDS,
          'timeout' => ExternalClientTimeouts::AWS_S3_TIMEOUT_SECONDS,
      ]);
      ```
      ネットワークには一切出ない（`getCommand()` は送信しない）
  - **behavioral**: `vendor 契約: MailManager は services.ses を SesV2Client の構築引数へ素通しする`
    — `Mail::mailer('ses')->getSymfonyTransport()` が `SesV2Transport` であることを
    `Assert::isInstanceOf` で確認したうえで `->ses()->getCommand('SendEmail', [...])['@http']`
    が pin 値であること（この 1 本が施策 3 の vendor 依存を可視化する)。
    **`new SesV2Client(...)` の直接構築へ fallback しない** — それでは
    「素通しする」という契約自体を検証できず、gate が意味を失う。
    テスト環境で mailer が解決できない場合は
    `config(['mail.default' => 'ses'])` 等で**局所的に設定を整えて MailManager 経由で解決**し、
    それでも駄目なら**前提の破綻として fail させる**
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
- `tests/Support/Storage/S3SurfaceInventory.php` (新規。面分類目録の**正本**)
- `tests/Support/ExternalClientBoundaryScanner.php` (新規。到達境界の走査)
- `tests/Support/PhpTokenScan.php` (新規。`token_get_all()` の正規化を共通化)
- `tests/Architecture/QueuedJobLeaseInventoryTest.php` (**既存を変更**。
  `jobLeaseNormalizedTokens()` を `PhpTokenScan::normalize()` への delegate にする)

### 波及変更

- TypeScript 型定義: **なし**（サーバ内部の分類。フロントに露出しないため
  `ManualEnumTsSyncInvariantTest` / `NotificationTypeTsSyncInvariantTest` の対象外。
  実装時に両テストの母集団定義を読み、本 enum が対象に入らないことを確認する）
- API Resource / DTO: なし
- テストファイル:
  - 本施策そのもの
  - **既存 `tests/Architecture/QueuedJobLeaseInventoryTest.php`**（共通化の波及）。
    切り出すのは `token_get_all()` の正規化 **1 関数だけ**に限り、
    `jobLeaseConnectionDeclarationSites()` 等の意味解析には触れない
    （T131 が走査母集団を `Tests\Support\QueuedJobPopulation` へ 1 本化したのと同じ方向。
     既存 gate の振る舞いを変えない最小の共通化）。
    回帰確認として `composer test -- --filter=QueuedJobLeaseInventoryTest` を必ず実行する

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

    /**
     * 本番の外部到達を持たないテストダブル (fake) 実装。
     *
     * 適用条件: `disk()` の引数が **s3 以外のローカル disk** (`s3_fake`) に固定されているか、
     * `client()` が例外を投げて実 SDK 経路に落ちないこと。**面分類の対象にはしない**
     * (本番の外部呼び出しを持たないため「面」を持たない)。
     */
    case TestDoubleWithoutExternalEgress = 'test_double_without_external_egress';
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
// ★`surface: adapter` の意味は「**public method ごとの面分類を要求する本番集約**」に定める。
//   fake は本番の外部到達を持たないため面を持たず、`exempt` 側で登録する
//   (adapter に混ぜると検査 6 の対称差が構造的に成立しない)。
const EXTERNAL_CLIENT_BOUNDARY_INVENTORY = [
    TakeObjectStorage::class => ['surface' => 'adapter'],
    RenderObjectStorage::class => ['surface' => 'adapter'],
    FakeTakeObjectStorage::class => ['surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::TestDoubleWithoutExternalEgress,
        'rationale' => 'client() が例外を投げ実 S3 経路へ落ちない fake。本番の外部到達を持たない'],
    FakeRenderObjectStorage::class => ['surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::TestDoubleWithoutExternalEgress,
        'rationale' => 'disk() を s3_fake (ローカル disk) に固定する fake。本番の外部到達を持たない'],
    FakeObjectStore::class => ['surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::TestDoubleWithoutExternalEgress,
        'rationale' => 's3_fake ローカル disk 上の emulation 基盤。AWS SDK をまったく構築しない'],
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

// tests/Support/Storage/S3SurfaceInventory.php — 面分類目録の**正本**
//
// ★グローバル定数ではなく **static メソッド**に置く。Pest の --parallel は
//   ファイル単位でプロセスを分けるため、他テストファイルの定数を参照すると未定義になりうる
//   (QueuedJobLeaseInventoryTest のコメントと同じ規律)。
//   Architecture テスト (施策 5) と Feature テスト (施策 7) の両方がここを読む。
final class S3SurfaceInventory
{
    /**
     * adapter の public メソッドの面分類 (deny-by-default)。
     *
     * ★**キー付き配列で統一**する (tuple にしない。PHPStan level 10 で shape が崩れる)。
     *
     * @return array<class-string, array<string, array{surface: S3OperationSurface, rationale: string}>>
     */
    public static function all(): array
    {
        return [
            TakeObjectStorage::class => [
                'presignUpload' => ['surface' => S3OperationSurface::NoObjectRequest, 'rationale' => 'presign は署名計算のみで S3 へリクエストを送らない'],
                'headObject' => ['surface' => S3OperationSurface::BoundedControl, 'rationale' => 'web 同期のテイク登録から呼ぶ唯一の S3 ネットワーク操作'],
                'temporaryPlaybackUrl' => ['surface' => S3OperationSurface::NoObjectRequest, 'rationale' => '署名 URL の生成のみでオブジェクト API を送らない'],
                'delete' => ['surface' => S3OperationSurface::Bulk, 'rationale' => 'Flysystem 経由で per-command option を注入できない掃除ジョブ専用の操作'],
                'exists' => ['surface' => S3OperationSurface::Bulk, 'rationale' => 'Flysystem 経由で per-command option を注入できない掃除ジョブ専用の操作'],
            ],
            RenderObjectStorage::class => [
                'downloadToLocal' => ['surface' => S3OperationSurface::Bulk, 'rationale' => '本文転送であり所要時間がオブジェクトサイズに比例して伸びる'],
                'upload' => ['surface' => S3OperationSurface::Bulk, 'rationale' => '本文転送であり所要時間がオブジェクトサイズに比例して伸びる'],
                'temporaryPlaybackUrl' => ['surface' => S3OperationSurface::NoObjectRequest, 'rationale' => '署名 URL の生成のみでオブジェクト API を送らない'],
                'temporaryDownloadUrl' => ['surface' => S3OperationSurface::NoObjectRequest, 'rationale' => '署名 URL の生成のみでオブジェクト API を送らない'],
                'delete' => ['surface' => S3OperationSurface::Bulk, 'rationale' => 'Flysystem 経由で per-command option を注入できない掃除ジョブ専用の操作'],
                'keyPrefixFor' => ['surface' => S3OperationSurface::NoObjectRequest, 'rationale' => '文字列生成のみで SDK をまったく呼び出さない純関数'],
                'contentDisposition' => ['surface' => S3OperationSurface::NoObjectRequest, 'rationale' => '文字列生成のみで SDK をまったく呼び出さない純関数'],
            ],
        ];
    }

    /**
     * 指定した面に属するメソッド名。
     *
     * @return list<string>
     */
    public static function methodsWithSurface(string $class, S3OperationSurface $surface): array { /* ... */ }
}
```

#### scanner 仕様（`Tests\Support\ExternalClientBoundaryScanner`）

母集団の判定条件を「**検出 token 種別 × 検出対象 namespace/class**」に分解して固定する。
走査は `PhpTokenScan::normalize()`（`T_WHITESPACE` / `T_COMMENT` / `T_DOC_COMMENT` を除去）
の結果に対して行い、**`T_CONSTANT_ENCAPSED_STRING` の中身は名前解決の対象にしない**
（コメント・文字列中の `Aws\` を拾わない）。

> **R1 は「site」ではなく「名前解決情報」である**。PHP の `use` 宣言は
> **クラス本体の外（ファイルスコープ）**に書かれるため、これを実行 site として扱い
> `NamedClass` 帰属を要求すると、正規の `use Aws\...;` を持つ全ファイルが違反になる。
> R1 は **alias マップの構築にのみ使い、それ自体では母集団へ登録しない**。
> 母集団へ入るのは **R2〜R5 の実際の参照・呼び出し site** であり、
> 帰属先はその site の scope（= 1 ファイルに複数の名前付きクラスがあっても
> 実際に使われたクラスへ正しく帰属する）。

| # | 規則 (検出理由コード) | 検出 token 種別 | 検出対象 | 例 |
|---|---|---|---|---|
| R1 | `use_import`（**alias マップ構築専用。母集団に登録しない**） | `T_USE` 直後の `T_NAME_QUALIFIED`（`as` alias は**別名側**を記録し、以降の short name 解決に使う） | `Aws\` / `League\Flysystem\` / `Illuminate\Support\Facades\Storage` / `Illuminate\Container\Attributes\Storage` / `Illuminate\Contracts\Filesystem\Filesystem` / `Illuminate\Filesystem\` | `use Aws\S3\S3Client;` |
| R2 | `fqn_reference` | `T_NAME_FULLY_QUALIFIED` / `T_NAME_QUALIFIED`（use に依らない完全修飾） | 同上 | `\Aws\S3\S3Client::class` |
| R3 | `type_declaration` | 関数/メソッドの引数・戻り値・プロパティの型トークン列（`?` / `|` / `&` を跨いで**全構成要素**を見る。constructor property promotion と attribute の引数も対象） | 同上（R1 の alias マップで short name を解決） | `private ?Filesystem $disk` |
| R4 | `disk_call` | `T_OBJECT_OPERATOR` / `T_NULLSAFE_OBJECT_OPERATOR` / `T_DOUBLE_COLON` の直後の `T_STRING` が `disk` | **receiver を問わない**（`Storage::disk()` / `app('filesystem')->disk()` / `resolve(FilesystemManager::class)->disk()` を等しく拾う） | `app('filesystem')->disk('s3')` |
| R5 | `get_client_call` | 同上で `T_STRING` が `getClient` | receiver を問わない | `$disk->getClient()` |
| R6 | `stripe_global_setter` | 同上で `T_STRING` が `setHttpClient` / `setMaxNetworkRetries` / `instance`、かつ直前の名前解決が `Stripe\` 名前空間 | `Stripe\ApiRequestor` / `Stripe\Stripe` / `Stripe\HttpClient\CurlClient` | `ApiRequestor::setHttpClient($c)` |

- **scope の種別を保持する（`null` に潰さない）**。`QueuedJobLeaseInventoryTest` と同じ
  scope 追跡（`T_CLASS` → 対応する `{` で push、対応する `}` で pop）を行いつつ、
  結果を 3 値で持つ:

  ```php
  enum ScanScopeKind
  {
      case NamedClass;      // 名前付きクラス本体
      case AnonymousClass;  // new class (...) { ... }
      case FileScope;       // クラスの外 (Pest のファイルスコープ closure を含む)
  }
  ```

  **帰属規則は用途別に分ける**（同じ規則を全規則へ当てると、許可対象の Pest テストが
  必ず違反になる — R6 の許可 site はファイルスコープ closure 内にあるため）:

  | 規則 | 帰属の要求 | 違反条件 |
  |---|---|---|
  | R1（`use` import） | **帰属を問わない**（`FileScope` が正常）。alias マップの構築にのみ使い、**母集団へ登録しない** | なし（import 単体は違反にならない。使われなければ dead import として無視） |
  | R2〜R5（到達境界の実 site） | `app/` の **`NamedClass`** への帰属を要求する | `AnonymousClass` / `FileScope` は違反（匿名クラスやファイルスコープで境界を跨ぐ抜け道を作らせない） |
  | R6（Stripe setter） | **scope を問わない**。正本は `相対パス × シンボル × site 件数` | 期待表と一致しない件数 |

  1 ファイルに名前付きクラスが複数ある場合も、**R2〜R5 の site が置かれた scope へ帰属する**
  （import だけでは母集団に入らないので「どのクラスの import か」を推測する必要がない）。

- **診断用の callable 名**: 名前付きメソッドならメソッド名、
  Pest のファイルスコープ closure なら `{closure}` を出す。
  匿名クラス内の setter も**ファイルと件数には含める**ため、
  許可ファイル内であっても件数の増加で検出できる。
- **失敗メッセージ**: `{相対パス}:{行} [{検出理由コード}] {検出した名前}` を必ず出す。
  「なぜ母集団に入ったのか」が読めない gate は維持されずに免除で潰されるため。
- **R4 の追加検査**: `disk()` の引数が `T_CONSTANT_ENCAPSED_STRING` **1 個**でなければ違反
  （動的 disk 名は静的に面を決められない）。

検査ケース一覧（すべて Pest の `test()`）:

| # | テスト名 | 検査内容 |
|---|---|---|
| 1 | `到達境界: AWS / Flysystem へ到達するクラスは目録と対称差ゼロ` | 走査集合 == 目録キー（`missing` / `stale` 両方向） |
| 2 | `到達境界: 走査母集団が空でない` | 走査結果 0 件で fail（**空振り防止**） |
| 3 | `到達境界: 免除には 30 文字以上の根拠がある` | `mb_strlen($rationale) >= 30` |
| 4 | `到達境界: disk 名は文字列リテラルである` | `->disk($variable)` は違反（動的名は静的検査できない） |
| 5 | `到達境界: Stripe の大域 setter はシンボルごとに許可箇所へ限定される` | **シンボル × 走査範囲**で期待集合を分ける（下表） |
| 5b | `到達境界: adapter 集合は面分類目録のクラスキーと一致する` | `surface === 'adapter'` のクラス集合 == `S3SurfaceInventory::all()` のキー集合（**両目録の意味を結ぶ**） |
| 6 | `面分類: adapter の public メソッドは目録と対称差ゼロ` | Reflection の `getMethods(PUBLIC)` で宣言クラスが自身のもの == 目録キー（対象は `surface === 'adapter'` の 2 クラスのみ） |
| 7 | `面分類: 各 entry に 30 文字以上の根拠がある` | 同上 |
| 8 | `面分類: BoundedControl は 1 つ以上ある` | 0 件で fail（**空振り防止**。全部 `Bulk` にすれば通る、を防ぐ） |
| 9 | `pin 値は SDK 既定値と異なる` | `STRIPE_TIMEOUT_SECONDS !== CurlClient::DEFAULT_TIMEOUT` / `STRIPE_CONNECT_TIMEOUT_SECONDS !== CurlClient::DEFAULT_CONNECT_TIMEOUT` / `AWS_MAX_ATTEMPTS !== Aws\Retry\ConfigurationProvider::DEFAULT_MAX_ATTEMPTS`（**負のコントロール**） |
| 10 | `AWS config: s3 / ses が http と retries を宣言する` | `config('filesystems.disks.s3.http.timeout')` 等が pin 値 |
| 11 | `AWS behavioral: 構築したクライアントの @http / @retries が pin 値` | `Storage::build(...)` / `Mail::mailer('ses')…->ses()` / `app(SnsClient::class)` の `getCommand()` を検査 |
| 12 | `時間予算: 外部予算 + 局所予算 < worker --timeout < retry_after` | `QueueLeaseConfig::databaseConnections()['database']` と `ExternalClientTimeouts` の**厳密不等号** |

#### 検査 5 の期待集合（シンボル × 走査範囲）

**provider は `CurlClient::instance()` を呼ばない**（意図的に `new CurlClient` を使う。
シングルトンを書き換えると「誰が設定したか」が追えず、テストの復元先も曖昧になるため）。
したがってシンボルごとに期待値を分ける。

期待値は **「相対パス × シンボル × site 件数」** で固定する
（「許可ファイルなら何件でもよい」では exact-fit にならない）。

| 相対パス | `setHttpClient` | `setMaxNetworkRetries` | `CurlClient::instance` |
|---|---|---|---|
| `app/Providers/ExternalClientTimeoutServiceProvider.php` | **1** | **1** | **0** |
| `tests/Feature/Providers/ExternalClientTimeoutServiceProviderTest.php` | **2**（既知状態への設定 + `finally` 復元） | **2**（同左） | **0** |
| `tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php` | **2**（fake 設定 + `finally` 復元） | **0** | **0** |
| 上記以外の `app/` / `tests/` 全ファイル | **0** | **0** | **0** |

- 期待値は**目録定数**として持ち、走査結果との**対称差ゼロ**（未登録ファイル / 残骸の両方向）
  と**件数一致**を検査する。
- 失敗メッセージには `{相対パス}:{行} [{シンボル}] (関数/メソッド名)` を出す。
  ただし **行番号は期待値の識別子にしない**（整形で動くため）。診断情報としてのみ出す。
- `Stripe\ApiRequestor::httpClient`（getter）は状態を変えないため**制限しない**
  （`app/` でも 0 件を要求しない。将来 pin 値を読むコードが正当に増えうる）。

走査範囲に `tests/` を含めるのは、**無関係なテストが大域状態を書き換えて
他テストを汚染する**ことを防ぐためである（`--parallel` はファイル単位でプロセスを
分けるが、同一プロセス内の実行順依存は残る）。

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
  - `匿名クラス内の site は AnonymousClass 帰属として違反になる`
  - `use Aws\… だけがあり参照 site が無いファイルは母集団に入らない`（R1 が site でないことの固定）
  - `1 ファイルに複数の名前付きクラスがあるとき、site は実際の scope のクラスへ帰属する`
  - `disk() の引数が変数なら違反になる`
- [ ] **既存 gate の回帰**: `composer test -- --filter=QueuedJobLeaseInventoryTest`
      （`PhpTokenScan` への delegate 化が既存の振る舞いを変えていないこと）
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
use Webmozart\Assert\Assert; // ★import 必須 (無いと Tests\Support\Billing\Assert に解決される)

/**
 * Stripe SDK の HTTP 呼び出し回数を数える fake client (**送信しない**)。
 *
 * ★`ApiRequestor::setHttpClient()` は Stripe SDK 公式の差し込み口である。
 *   Cashier 内部 (`createOrGetStripeCustomer` 等) の呼び出しもここを通るため、
 *   静的な呼び出し site 計数では数えられない分まで含めて数えられる。
 * ★外部 HTTP は一切発生しない (AGENTS.md の egress 規約に抵触しない)。
 * ★`Stripe\HttpClient\ClientInterface` は **generic ではない**ため `@implements` は書かない
 *   (PHPStan で不正な PHPDoc になる)。型の情報は `request()` の `@param` / `@return` で与える。
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
     * vendor の `Stripe\HttpClient\ClientInterface::request()` に型宣言が無いため、
     * **全引数に `@param` を付けて** PHPStan level 10 で mixed が伝播しないようにする。
     *
     * @param  'delete'|'get'|'post'  $method
     * @param  string  $absUrl
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>|string  $params
     * @param  bool  $hasFile
     * @param  string  $apiMode
     * @param  int|null  $maxNetworkRetries
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
    // 各行: [応答列, 期待呼び出し回数, 期待 terminal status, 期待例外クラス|null]
    // ★呼び出し回数だけでなく **経路が意図どおり終端したこと**も検証する
    //   (途中で早期 return して「呼び出しが少ないから green」になるのを防ぐ)
    '成功 (customer 新規 = customer 作成の 1 呼び出しが増える最長側)' =>
        [[...], 7, AutoRechargeAttemptStatus::Succeeded, null],
    '成功 (customer 既存 = retrieve だけ。基準経路)' =>
        [[...], 6, AutoRechargeAttemptStatus::Succeeded, null],
    'カード拒否 → invoice void (後始末の追加呼び出しが載る経路)' =>
        [[...], 8, AutoRechargeAttemptStatus::Failed, null],
    '既存 invoice の再利用 (finalize 済みで InvalidRequest → pay へ進む経路)' =>
        [[...], 4, AutoRechargeAttemptStatus::Succeeded, null],
]);

// ★PHPDoc は **closure に直接**付ける。`test()` 呼び出しの直前に置くと
//   匿名関数の PHPDoc として PHPStan に認識される保証が無く、
//   native 型が `array` の `$responses` が `list<array{status: int, body: string}>` へ
//   narrowing されない (level 10 で CountingStripeHttpClient の引数型と噛み合わない)。
test(
    '既定接続の Stripe 呼び出しは予算を超えない',
    /**
     * @param  list<array{status: int, body: string}>  $responses
     * @param  class-string<Throwable>|null  $expectedException
     */
    function (
    array $responses,
    int $expectedCalls,
    AutoRechargeAttemptStatus $expectedStatus,
    ?string $expectedException,
): void {
    $original = ApiRequestor::httpClient(); // 遅延生成のため null にならない (vendor 実査)
    $counting = new CountingStripeHttpClient($responses);

    try {
        ApiRequestor::setHttpClient($counting);
        $this->app->bind(AutoRechargeGatewayInterface::class, CashierAutoRechargeGateway::class);

        $attempt = TicketAutoRechargeAttempt::factory()->pending()->create();

        if ($expectedException !== null) {
            expect(fn () => app(AutoRechargeService::class)->executeAttempt($attempt))
                ->toThrow($expectedException);
        } else {
            app(AutoRechargeService::class)->executeAttempt($attempt);
        }

        // ★予算の上限 (定数) を超えない
        expect($counting->calls)
            ->toBeLessThanOrEqual(ExternalClientTimeouts::DEFAULT_CONNECTION_STRIPE_CALL_BUDGET);
        // ★経路ごとの厳密な回数 (増えたら気づく = ドリフト検知)
        expect($counting->calls)->toBe($expectedCalls);
        // ★空振り防止: 応答列を使い切っていること (経路が途中で終わっていない)
        expect($counting->isExhausted())->toBeTrue();
        // ★経路が意図どおり終端したこと
        expect($attempt->refresh()->status)->toBe($expectedStatus);
    } finally {
        ApiRequestor::setHttpClient($original);
    }
    },
)->with('auto-recharge の外部呼び出し経路');
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
- [x] **PHPDoc の付与先**: dataset を受けるテストの PHPDoc は `test()` の直前ではなく
      **closure に直接**付ける（`test()` 直前だと匿名関数の PHPDoc として認識される保証が無い）。
      実装時にリポジトリ内の既存 Pest dataset パターンを確認して合わせる

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

    // ★spy の脆さ対策: 親に public method が増えたら気づく (未 override があれば fail)。
    //   interface 抽出は本タスクの目的 (timeout の有限化) と無関係なので今回は行わない
    //   (AGENTS.md 思考原則 2)。代わりに「取りこぼしを検出する」側で担保する。
    $inventoryMethods = array_keys(S3SurfaceInventory::all()[TakeObjectStorage::class]);
    $overridden = [];
    foreach ((new ReflectionClass($spy))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== TakeObjectStorage::class) {
            $overridden[] = $method->getName();
        }
    }
    expect(array_values(array_diff($inventoryMethods, $overridden)))->toBe(
        [],
        'spy が override していない public method がある (親にメソッドが増えた可能性)',
    );

    $bulkMethods = S3SurfaceInventory::methodsWithSurface(
        TakeObjectStorage::class,
        S3OperationSurface::Bulk,
    );

    expect($bulkMethods)->not->toBeEmpty();                   // 目録側の空振り防止
    expect($spy->calls)->not->toBeEmpty();                    // 呼び出し記録の空振り防止
    expect(array_values(array_intersect($spy->calls, $bulkMethods)))->toBe([]);
});
```

> 目録は `QueuedJobLeaseInventoryTest` のコメントどおり **他テストファイルの
> グローバル定数を参照しない**（`--parallel` はファイル単位でプロセスを分けるため未定義になりうる）。
> 面分類の正本は **`tests/Support/Storage/S3SurfaceInventory.php` の static メソッド**で、
> 施策 5 の Architecture テストと本 Feature テストの両方がそこを読む。
> **配列形式は `['surface' => …, 'rationale' => …]` のキー付きで統一する**（tuple にしない）。

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
test('Stripe の接続断/timeout は ApiConnectionException の class 分類で ProviderUnavailable になる', function (): void {
    // ★分類は **class-based** である (message 文字列は判定に一切効かない)。
    //   timeout らしい message を使うのは可読性のためだけ。
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

> **worker の起動形態は環境で違う**: `mprocs.yaml` は **dev** で `queue:listen`、
> **本番/ステージングの supervisor** は `docs/architecture.md` の値表どおり `queue:work`。
> 確認コマンドは**両方**を拾う正規表現にする。

```
0. 実施条件 (手順 1 の前に確認する)
   - **低トラフィック時間帯**に実施する (SIGALRM で落ちる旧ジョブを最小化するため)
   - `database` キューの未処理件数が 0 に近いこと
     (`select count(*) from jobs where queue = 'default'`)
   - オートリチャージの pending attempt が滞留していないこと
     (`select count(*) from ticket_auto_recharge_attempts where status = 'pending'`)

1. 全 worker の supervisor 定義を --timeout=540 → 300 へ変更して再起動する
   (このときコードは旧のまま = retry_after 600。300 < 600 で規則 1 は成立)
   ★確認方法: 各 worker ホストで
     `pgrep -af 'artisan queue:(work|listen) database( |$)'` を実行し、
     出力の全行に `--timeout=300` が含まれること。実施主体は本番デプロイ担当。

2. 新コード (SDK pin + retry_after 360) をデプロイし、全 worker を入れ替える

3. 旧 worker が残っていないことを確認する
   ★確認方法: 同コマンドで `--timeout=540` の行が 0 件であること。
     加えてデプロイ開始時刻より前に起動した worker プロセスが残っていないこと
     (`ps -o lstart=,args= -p <pid>`)

4. 実施後、手順 0 と同じクエリで pending attempt の残留を確認し、
   残っていればリコンサイルの完了を待つ (または手動起動する)
```

**受容事項**: 手順 1 の間、旧コード（Stripe 80s 前提）のジョブが 300s で SIGALRM されうる。
`ExecuteAutoRechargeAttemptJob` は `$tries = 1` でリコンサイルが再試行を担うため、
恒久喪失にはならない（Stripe idempotency key により二重課金にもならない）。
手順 0 の実施条件はこの受容の**発生確率を下げる**ためのものであり、
「起きない」ことの保証ではない（誇張しない）。

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

| 15 | `PhpTokenScan::normalize()` からコメント除去を外す | 既存 `QueuedJobLeaseInventoryTest` の回帰（共通化が振る舞いを変えていないことの逆確認） |
| 16 | `FakeTakeObjectStorage` の目録 entry を `surface => 'adapter'` に変える | 検査 5b「adapter 集合は面分類目録のクラスキーと一致する」 |
| 17 | provider の `new CurlClient` を `CurlClient::instance()` に変える | 検査 5「`CurlClient::instance` は app/ で 0 件」 |
| 18 | 無関係なテスト（例 `tests/Unit/…`）に `ApiRequestor::setHttpClient(new CurlClient);` を足す | 検査 5 の tests/ 側 exact-fit |
| 19 | 許可済みテストファイル**内**に `setHttpClient` を 1 件追加する（3 件にする） | 検査 5 の **site 件数**一致（ファイル許可だけでは検出できない側の確認） |
| 20 | `app/` の任意 Service に `new class { public function f() { Storage::disk('s3'); } }` を足す | R1〜R5 の `AnonymousClass` 帰属違反 |

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
2. `Mail::mailer('ses')` がテスト環境（`MAIL_MAILER=array` force）で解決できること。
   **`new SesV2Client(...)` の直接構築へ落としてはならない** — それでは
   「`MailManager` が `services.ses` を素通しする」という **vendor 契約そのもの**を
   検証できなくなる（施策 3 の pin が効く根拠が消える）。
   解決できない場合はテスト内で `config(['mail.default' => 'ses'])` 等の
   **mailer 設定を局所的に整えて `MailManager` 経由で解決**する。
   それでも Laravel 経由で構築できないなら、それは**設計・バージョン前提の破綻**であり
   fallback せず fail させる（具体的な設定値だけを実装時確認に落とす）。
3. `S3OperationSurface` / `ExternalClientBoundaryExemption` が
   `ManualEnumTsSyncInvariantTest` / `NotificationTypeTsSyncInvariantTest` の母集団に
   入らないこと（入る場合は TS 定義の追加が波及変更として必要になる）。
4. `docs/TODO.md` の T127 行の説明文にある「最大 510 秒遅れる」という数値は
   本タスク完了後に **270 秒**へ更新が必要。TODO.md の編集は `app-todo-add` /
   `app-todo-close` スキルの責務のため、本設計の施策には含めない（実装完了後に別途行う）。


---

## 設計からの逸脱 (実装者の申告)

1. **免除 enum に 2 case 追加** (`InjectedPinnedControlClient` / `DefaultDiskWithoutAwsClient`)。
   設計の 4 case では実際の走査母集団を覆えなかった:
   - `SesNotificationController` は pin 済み `SnsClient` を DI で受け取るだけ (構築しないので
     `PinnedControlClientConstruction` に当たらず、client を持つので `AwsValueObjectOnly` でもない)
   - `SopTextExtractor` / `SourceDocumentService` は `Storage` facade を**既定 disk (ローカル)** に
     のみ使う (設計の `rg` は Aws/Flysystem だけを見ており、facade 参照を数えていなかった)
2. **R4 (`disk()` 呼び出し) の引数検査を緩めた**。設計は「`T_CONSTANT_ENCAPSED_STRING` 1 個でなければ違反」
   だったが、実コードには (a) 引数なしの集約 accessor `$this->disk()`
   (`RenderObjectStorage` / `FakeObjectStore`)、(b) クラス定数 `Storage::disk(FakeObjectStore::DISK)`
   があり、いずれも**静的に確定する**。よって `none` / `static` (文字列リテラル or クラス定数) を許可し、
   **変数を含む `dynamic` のみ違反**とした (禁じたい対象は動的 disk 名である、という意図は保った)。
3. **R3 を「型宣言」から「import 済み short name の参照」へ広げた**。設計の列挙 (nullable / union /
   intersection / promotion / attribute) をすべて含み、さらに `new X` / `X::class` / `instanceof` も拾う
   **上位集合**である。token 走査で「型宣言の位置」だけを厳密に切り出すより偽陰性が少ない。
4. **R5 (`getClient()`) に「同一ファイルに到達境界の参照がある」条件を付けた**。
   receiver 非依存のままだと OAuth の `AuthCodeEntity::getClient()` (`app/Passport/McpAuthCodeRepository.php`)
   まで母集団に入り、目録が意味を失うため。
5. **R6 (Stripe 大域 setter) を到達境界の母集団にも含めた**。設計の骨子では
   `boundarySites()` から除外していたが、それだと pin provider が目録の「残骸」として検出されてしまう。
6. **SNS client の `@retries` assert を試行回数の behavioral テストへ置換**。
   AWS SDK は client 既定の retries を `getCommand()['@retries']` に載せない (実測 null) ため、
   `getCommand()` では検証できない。代わりに MockHandler で実試行回数を数えた。
7. **`@http` の assert を配列完全一致からキー単位へ**。S3/SES client は `decode_content => false` を
   既定で足すため、完全一致だと vendor 都合で壊れる。pin した 2 キーのみを固定した。
8. **scanner に文字列補間 (`"{$x}"`) の brace 補正を追加**。閉じ `}` は単一文字トークンで現れるのに
   開き側は `T_CURLY_OPEN` なので、補正しないと以降の site が誤って `FileScope` 帰属になる (実測で発覚)。
9. **`app/Jobs/Manual/RunManual{Analysis,Render}.php` のコメント中の「既定 database は 600s」を 360s へ更新**
   (設計の波及変更表に無いが、帯を動かしたのでコメントが嘘になるため)。

---

## 実装差分（git diff）

```diff
diff --git a/app/Enums/Storage/ExternalClientBoundaryExemption.php b/app/Enums/Storage/ExternalClientBoundaryExemption.php
new file mode 100644
index 0000000..d3f0f29
--- /dev/null
+++ b/app/Enums/Storage/ExternalClientBoundaryExemption.php
@@ -0,0 +1,65 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Storage;
+
+/**
+ * 「AWS SDK / Flysystem へ到達するが、S3 集約 adapter ではない」ことが正しいと裁定された理由の分類。
+ *
+ * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
+ *   当てはまる case が無ければ、それは「adapter へ寄せるべきコード」である。
+ */
+enum ExternalClientBoundaryExemption: string
+{
+    /**
+     * AWS の**値オブジェクト**だけを扱い、クライアントを構築も取得もしない。
+     *
+     * 適用条件: 参照が `Aws\Sns\Message` / `Aws\Sns\MessageValidator` のような
+     * リクエストを送らない型に限られ、`disk()` も `getClient()` も呼ばない。
+     * (証明書取得は自前 HTTP client 経由で timeout 指定済み = SDK の待ちではない)
+     */
+    case AwsValueObjectOnly = 'aws_value_object_only';
+
+    /**
+     * 制御系 AWS クライアントの**構築点**であり、pin 値を明示的に渡している。
+     *
+     * 適用条件: `App\Support\ExternalClientTimeouts::awsControlClientOptions()` を
+     * 構築引数へ展開しており、per-command 上書きを必要としない (転送量が有界)。
+     */
+    case PinnedControlClientConstruction = 'pinned_control_client_construction';
+
+    /**
+     * pin 済みの制御系 AWS クライアントを **DI で受け取って使うだけ**の消費点。
+     *
+     * 適用条件: クライアントを自分で構築せず (`new` しない)、
+     * `PinnedControlClientConstruction` の構築点が渡したインスタンスをそのまま使うこと。
+     * 待ち上限は構築点の pin が決めるため、この層に per-command 上書きは要らない。
+     */
+    case InjectedPinnedControlClient = 'injected_pinned_control_client';
+
+    /**
+     * `Storage` facade を**既定 disk のみ**で使い、AWS クライアントを解決しない。
+     *
+     * 適用条件: `disk('s3')` を呼ばず、facade の既定 disk (ローカル) 経由の
+     * read/write しか行わないこと。AWS SDK の待ちがそもそも発生しない。
+     */
+    case DefaultDiskWithoutAwsClient = 'default_disk_without_aws_client';
+
+    /**
+     * 外部 SDK のプロセス大域設定を pin する専用 provider。
+     *
+     * 適用条件: `ApiRequestor::setHttpClient()` / `Stripe::setMaxNetworkRetries()` の
+     * 呼び出しが本クラスに 1 箇所ずつだけ存在し、他に副作用を持たないこと。
+     */
+    case GlobalSdkTimeoutPin = 'global_sdk_timeout_pin';
+
+    /**
+     * 本番の外部到達を持たないテストダブル (fake) 実装。
+     *
+     * 適用条件: `disk()` の引数が **s3 以外のローカル disk** (`s3_fake`) に固定されているか、
+     * `client()` が例外を投げて実 SDK 経路に落ちないこと。**面分類の対象にはしない**
+     * (本番の外部呼び出しを持たないため「面」を持たない)。
+     */
+    case TestDoubleWithoutExternalEgress = 'test_double_without_external_egress';
+}
diff --git a/app/Enums/Storage/S3OperationSurface.php b/app/Enums/Storage/S3OperationSurface.php
new file mode 100644
index 0000000..884cf76
--- /dev/null
+++ b/app/Enums/Storage/S3OperationSurface.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Storage;
+
+/**
+ * S3 集約 adapter の public メソッドが持つ「面」の分類。
+ *
+ * `tests/Architecture/ExternalClientTimeoutInventoryTest.php` が deny-by-default で
+ * 全 public メソッドの登録を機械強制する (テストクラスへの {@see} 参照は
+ * app → tests の import を生むため書かない)。
+ *
+ * ★分類の基準は「**転送量が有界か**」と「**per-command option を注入できるか**」の 2 軸。
+ */
+enum S3OperationSurface: string
+{
+    /**
+     * S3 オブジェクト API を送信しない (ローカル署名 / 文字列生成のみ)。
+     *
+     * ★**credential 解決 (ECS/EC2 metadata 等) がネットワークへ出る可能性は保証外**である。
+     *   「一切ネットワークに出ない」とは主張しない。
+     */
+    case NoObjectRequest = 'no_object_request';
+
+    /**
+     * 転送量が有界なメタデータ操作。**per-command の制御系 option を積むことが必須**。
+     *
+     * 適用条件: 生の S3Client を直接呼び、`@http` / `@retries` を注入できること。
+     * web 同期経路 (HTTP リクエスト内) から呼んでよいのはこの面だけである。
+     */
+    case BoundedControl = 'bounded_control';
+
+    /**
+     * 本文転送、または Flysystem 経由で per-command option を注入できない操作。
+     *
+     * s3 disk のクライアント既定 (データ系の長い timeout) を継承する。
+     * **web 同期経路から呼ばない** — これは規約であり、機械では証明していない
+     * (呼び出しグラフ解析が要り、静的近似は偽陰性が静かに増えるため採らない)。
+     * 既存の web 経路については Feature テストが `Bulk` 不使用を固定する。
+     */
+    case Bulk = 'bulk';
+}
diff --git a/app/Jobs/Manual/RunManualAnalysis.php b/app/Jobs/Manual/RunManualAnalysis.php
index d45e4b1..f4a4926 100644
--- a/app/Jobs/Manual/RunManualAnalysis.php
+++ b/app/Jobs/Manual/RunManualAnalysis.php
@@ -51,7 +51,7 @@ class RunManualAnalysis implements ShouldQueue
 
     public function __construct(public readonly int $analysisJobId)
     {
-        // retry_after を解析専用値にした connection (config/queue.php)。既定 database は 600s のため。
+        // retry_after を解析専用値にした connection (config/queue.php)。既定 database は 360s のため (T126)。
         // Queueable trait が $connection プロパティを既に定義しているため、プロパティ再宣言でなく
         // onConnection() で指定する (typed 再宣言は trait composition エラーになる)
         $this->onConnection('database-analysis');
diff --git a/app/Jobs/Manual/RunManualRender.php b/app/Jobs/Manual/RunManualRender.php
index 785be60..b12af44 100644
--- a/app/Jobs/Manual/RunManualRender.php
+++ b/app/Jobs/Manual/RunManualRender.php
@@ -41,7 +41,7 @@ class RunManualRender implements ShouldQueue
 
     public function __construct(public readonly int $renderJobId)
     {
-        // retry_after をレンダ専用値にした connection (config/queue.php)。既定 database は 600s のため。
+        // retry_after をレンダ専用値にした connection (config/queue.php)。既定 database は 360s のため (T126)。
         // Queueable trait が $connection プロパティを既に定義しているため onConnection() で指定する
         $this->onConnection('database-render');
     }
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index d94b487..6ea164a 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -36,6 +36,7 @@
 use App\Support\CriticalActionContext;
 use App\Support\EmailHash;
 use App\Support\EmailNormalizer;
+use App\Support\ExternalClientTimeouts;
 use App\Support\Http\RouteThrottleBinder;
 use App\Support\PasswordPolicy;
 use App\Support\ProductionEnvGuard;
@@ -94,6 +95,9 @@ public function register(): void
             $config = [
                 'version' => 'latest',
                 'region' => is_string($ses['region'] ?? null) ? $ses['region'] : 'us-east-1',
+                // ★config('services.ses') の http/retries を継承しない (自前で $config を組むため)。
+                //   pin を明示する。無指定は「無制限 × 3 attempts」= web 経路のハング要因 (T126)。
+                ...ExternalClientTimeouts::awsControlClientOptions(),
             ];
             $key = $ses['key'] ?? null;
             $secret = $ses['secret'] ?? null;
diff --git a/app/Providers/ExternalClientTimeoutServiceProvider.php b/app/Providers/ExternalClientTimeoutServiceProvider.php
new file mode 100644
index 0000000..e5b24d7
--- /dev/null
+++ b/app/Providers/ExternalClientTimeoutServiceProvider.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Providers;
+
+use App\Support\ExternalClientTimeouts;
+use Illuminate\Support\ServiceProvider;
+use Stripe\ApiRequestor;
+use Stripe\HttpClient\CurlClient;
+use Stripe\Stripe;
+
+/**
+ * 外部 SDK のプロセス大域設定を pin する専用 provider。
+ *
+ * ★**なぜ AppServiceProvider に混ぜないか**: この pin は PHP プロセス大域の static 状態を
+ *   書き換えるため、「配線が実際に効いているか」をテストが独立に検証するには
+ *   provider の boot() を単独で再実行できる必要がある。AppServiceProvider に混ぜると
+ *   再実行で Event::listen 等が二重登録される。
+ * ★Stripe SDK は **client ごとの timeout を支えない**。`StripeClient` の config に
+ *   timeout 系のキーが無く (`BaseStripeClient::DEFAULT_CONFIG`)、`ApiRequestor` の
+ *   static HTTP client だけが唯一の調整点である。したがってテナント別 timeout は持たない。
+ * ★`Cashier::stripe()` / `$organization->stripe()` / `PriceService` bind の 3 系統は
+ *   すべてこの HTTP client を通るため、大域 pin 1 本で全経路を覆える。
+ *
+ * 運用契約: docs/architecture.md §外部 SDK の待ち上限の規約
+ */
+final class ExternalClientTimeoutServiceProvider extends ServiceProvider
+{
+    public function boot(): void
+    {
+        // ★CurlClient::instance() のシングルトンを直接設定せず、専用インスタンスを
+        //   ApiRequestor へ差す。シングルトンを書き換えると「誰が設定したか」が
+        //   追えなくなるうえ、テストの復元先が曖昧になる。
+        $client = new CurlClient;
+        $client->setConnectTimeout(ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS);
+        $client->setTimeout(ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS);
+
+        ApiRequestor::setHttpClient($client);
+        Stripe::setMaxNetworkRetries(ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES);
+    }
+}
diff --git a/app/Services/Capture/TakeObjectStorage.php b/app/Services/Capture/TakeObjectStorage.php
index 0ebf442..44695f5 100644
--- a/app/Services/Capture/TakeObjectStorage.php
+++ b/app/Services/Capture/TakeObjectStorage.php
@@ -6,6 +6,7 @@
 
 use App\DataTransferObjects\Capture\ObjectMetadataData;
 use App\DataTransferObjects\Capture\PresignedUploadData;
+use App\Support\ExternalClientTimeouts;
 use Aws\S3\Exception\S3Exception;
 use Aws\S3\S3Client;
 use Carbon\CarbonImmutable;
@@ -54,6 +55,12 @@ public function presignUpload(string $path, string $contentType, int $sizeBytes,
     /**
      * オブジェクトが存在しなければ null (PUT 未完了)。ChecksumMode=ENABLED で
      * ChecksumSHA256 も取得する (欠落する互換実装では null = 照合スキップの二重防御位置づけ)。
+     *
+     * ★**web 同期経路 (テイク登録) から呼ばれる唯一の S3 ネットワーク操作**である。
+     *   s3 disk のクライアント既定はデータ系 (900s) のため、ここで per-command に
+     *   制御系の帯へ絞る。`@http` は `AwsClient::getCommand()` が `+=` で合成する
+     *   = 渡した側が勝つ。`@retries` は RetryMiddleware / RetryMiddlewareV2 の両方が読む。
+     *   面分類は App\Enums\Storage\S3OperationSurface::BoundedControl。
      */
     public function headObject(string $path): ?ObjectMetadataData
     {
@@ -62,6 +69,7 @@ public function headObject(string $path): ?ObjectMetadataData
                 'Bucket' => $this->bucket(),
                 'Key' => $path,
                 'ChecksumMode' => 'ENABLED',
+                ...ExternalClientTimeouts::awsControlPlaneCommandOptions(),
             ]);
         } catch (S3Exception $exception) {
             if ($exception->getStatusCode() === 404) {
diff --git a/app/Support/ExternalClientTimeouts.php b/app/Support/ExternalClientTimeouts.php
new file mode 100644
index 0000000..62a70a0
--- /dev/null
+++ b/app/Support/ExternalClientTimeouts.php
@@ -0,0 +1,154 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support;
+
+/**
+ * 外部 SDK (Stripe / AWS) のクライアント待ち上限の**単一出典**。
+ *
+ * ★env で上書きできる口を作らない。`config/queue.php` の retry_after が
+ *   「静的 gate は config をテスト環境の値で読むため、env 上書きを残すと
+ *    gate は通るが本番の実値は別、を作れてしまう」という理由でリテラル固定なのと同じ理屈で、
+ *   **gate が読む値と本番の実値を一致させる**ために定数で持つ。
+ * ★config ファイルから参照するために「クラス定数」にしている
+ *   (config の中で config() を呼ぶのは読み込み順に依存して壊れる)。
+ *
+ * ★用語: 「HTTP 試行 timeout 予算」= cURL / Guzzle に与える 1 試行あたりの上限 × attempts。
+ *   **SDK 操作全体の wall-clock deadline ではない** (DNS 解決・credential provider・
+ *   endpoint discovery・retry backoff はこの外側)。誇張して書かないこと。
+ *
+ * 運用契約: docs/architecture.md §外部 SDK の待ち上限の規約
+ */
+final class ExternalClientTimeouts
+{
+    // --- Stripe (プロセス大域。ApiRequestor の HTTP client にしか置けない) ---
+
+    /** TCP 接続確立の上限 (SDK 既定 30s)。 */
+    public const int STRIPE_CONNECT_TIMEOUT_SECONDS = 5;
+
+    /** 1 リクエストの総時間上限 (SDK 既定 80s)。単一オブジェクトの create/retrieve/pay しか呼ばない。 */
+    public const int STRIPE_TIMEOUT_SECONDS = 20;
+
+    /**
+     * SDK 内リトライ回数。**0 に pin する**。
+     *
+     * 課金の一回性は Stripe idempotency key とリコンサイルが担う設計 (AGENTS.md ドメイン規約 6) で、
+     * SDK 自動 retry に寄せない。0 でないとジョブの外部予算が retry 数だけ倍化する。
+     */
+    public const int STRIPE_MAX_NETWORK_RETRIES = 0;
+
+    // --- AWS 制御系 (SES 送信 / SNS。転送量が有界) ---
+
+    public const int AWS_CONTROL_CONNECT_TIMEOUT_SECONDS = 5;
+
+    public const int AWS_CONTROL_TIMEOUT_SECONDS = 15;
+
+    // --- AWS データ系 (s3 disk のクライアント既定。本文転送があるため長い) ---
+
+    public const int AWS_S3_CONNECT_TIMEOUT_SECONDS = 10;
+
+    /**
+     * s3 disk クライアントの総時間上限。
+     *
+     * ★短くできない: Flysystem の write 経路 (`AwsS3V3Adapter::upload()` →
+     *   `createOptionsFromConfig()`) は `AVAILABLE_OPTIONS` / `MUP_AVAILABLE_OPTIONS` しか
+     *   転送しないため **`@http` を per-command で注入できない**。client 既定が
+     *   データ系を賄う必要がある (vendor 実査済み)。
+     * ★web 同期経路で使う metadata 操作は per-command で AWS_CONTROL_* へ絞る。
+     */
+    public const int AWS_S3_TIMEOUT_SECONDS = 900;
+
+    /**
+     * AWS SDK クライアントの **試行回数** (SDK 既定 3)。worst case が timeout × attempts に
+     * なるため明示 pin する。
+     *
+     * ★**語彙に注意 (vendor 実査)**: `retries` を array 形式で渡すと
+     *   `Aws\Retry\ConfigurationProvider::unwrap()` が `max_attempts` を
+     *   **初回を含む試行回数**として解釈し、`ClientResolver::_apply_retries()` が
+     *   legacy モードで `maxAttempts - 1` を retry 数に使う。
+     *   つまり `max_attempts = 2` は「初回 + 再試行 1 回」である。
+     *   一方 per-command の `@retries` (AWS_CONTROL_PLANE_RETRIES) は
+     *   **retry 回数**であり `0` = 再試行しない。**同じ数字でも意味が違う**。
+     */
+    public const int AWS_MAX_ATTEMPTS = 2;
+
+    /**
+     * web 同期経路の metadata 操作の **retry 回数** (`@retries`。0 = 再試行しない)。
+     *
+     * SDK 内で粘らせず、アプリ側で失敗を返して再操作を促す。
+     * ★上の AWS_MAX_ATTEMPTS とは語彙が違う (試行回数 vs retry 回数)。
+     */
+    public const int AWS_CONTROL_PLANE_RETRIES = 0;
+
+    // --- 既定キュー接続 (database) の時間予算 ---
+
+    /**
+     * `ExecuteAutoRechargeAttemptJob` の最長経路で許す Stripe HTTP 呼び出し回数。
+     *
+     * ★静的計数では Cashier 内部 (`createOrGetStripeCustomer`) を数えられないため、
+     *   **実行時の HTTP 呼び出し回数**で固定する
+     *   (`tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php`)。
+     */
+    public const int DEFAULT_CONNECTION_STRIPE_CALL_BUDGET = 10;
+
+    /** 既定接続のジョブが外部 SDK 待ちに使ってよい上限 (= 20s × 10 回)。 */
+    public const int DEFAULT_CONNECTION_EXTERNAL_BUDGET_SECONDS = 200;
+
+    /** 外部呼び出し以外 (DB / ロック待ち / ログ / 後始末) の予算。 */
+    public const int DEFAULT_CONNECTION_LOCAL_BUDGET_SECONDS = 90;
+
+    /** 既定接続のワーカー `--timeout`。`外部予算 + 局所予算 < これ < retry_after` を守る。 */
+    public const int DEFAULT_CONNECTION_WORKER_TIMEOUT_SECONDS = 300;
+
+    /**
+     * AWS クライアント構築引数 (制御系)。
+     *
+     * @return array{http: array{connect_timeout: int, timeout: int}, retries: array{mode: 'legacy', max_attempts: int}}
+     */
+    public static function awsControlClientOptions(): array
+    {
+        return [
+            'http' => [
+                'connect_timeout' => self::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS,
+                'timeout' => self::AWS_CONTROL_TIMEOUT_SECONDS,
+            ],
+            'retries' => ['mode' => 'legacy', 'max_attempts' => self::AWS_MAX_ATTEMPTS],
+        ];
+    }
+
+    /**
+     * AWS クライアント構築引数 (s3 disk = データ系)。
+     *
+     * @return array{http: array{connect_timeout: int, timeout: int}, retries: array{mode: 'legacy', max_attempts: int}}
+     */
+    public static function awsS3ClientOptions(): array
+    {
+        return [
+            'http' => [
+                'connect_timeout' => self::AWS_S3_CONNECT_TIMEOUT_SECONDS,
+                'timeout' => self::AWS_S3_TIMEOUT_SECONDS,
+            ],
+            'retries' => ['mode' => 'legacy', 'max_attempts' => self::AWS_MAX_ATTEMPTS],
+        ];
+    }
+
+    /**
+     * S3 の **per-command** 上書き (web 同期経路の metadata 操作用)。
+     *
+     * `Aws\AwsClient::getCommand()` は `@http` を `+=` で合成する = **渡した側が勝つ**。
+     * `@retries` は `Aws\RetryMiddleware` / `RetryMiddlewareV2` の両方が読む (vendor 実査済み)。
+     *
+     * @return array{'@http': array{connect_timeout: int, timeout: int}, '@retries': int}
+     */
+    public static function awsControlPlaneCommandOptions(): array
+    {
+        return [
+            '@http' => [
+                'connect_timeout' => self::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS,
+                'timeout' => self::AWS_CONTROL_TIMEOUT_SECONDS,
+            ],
+            '@retries' => self::AWS_CONTROL_PLANE_RETRIES,
+        ];
+    }
+}
diff --git a/bootstrap/providers.php b/bootstrap/providers.php
index bf55aba..bfb1301 100644
--- a/bootstrap/providers.php
+++ b/bootstrap/providers.php
@@ -1,6 +1,7 @@
 <?php
 
 use App\Providers\AppServiceProvider;
+use App\Providers\ExternalClientTimeoutServiceProvider;
 use App\Providers\FakeExternalsServiceProvider;
 use App\Providers\Filament\AdminPanelProvider;
 use App\Providers\FortifyServiceProvider;
@@ -10,6 +11,9 @@
 
 return [
     AppServiceProvider::class,
+    // 外部 SDK (Stripe) のプロセス大域 timeout pin。他の provider の副作用と混ぜないため
+    // 専用に切り出す (テストが boot() を単独で再実行できるようにする)
+    ExternalClientTimeoutServiceProvider::class,
     AdminPanelProvider::class,
     FortifyServiceProvider::class,
     // passkey (laravel/passkeys) の app アダプタ。Fortify が feature flag で route を
diff --git a/config/filesystems.php b/config/filesystems.php
index 33d898b..d6e7c64 100644
--- a/config/filesystems.php
+++ b/config/filesystems.php
@@ -1,5 +1,7 @@
 <?php
 
+use App\Support\ExternalClientTimeouts;
+
 return [
 
     /*
@@ -58,6 +60,11 @@
             'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
             'throw' => false,
             'report' => false,
+            // AWS SDK は http / retries を無指定にすると「無制限 × 3 attempts」になる。
+            // データ系 (本文 read/write) の値。metadata 操作は per-command で制御系へ絞る
+            // (App\Services\Capture\TakeObjectStorage::headObject)。
+            // FilesystemManager::createS3Driver() がこの配列を素通しで S3Client へ渡す。
+            ...ExternalClientTimeouts::awsS3ClientOptions(),
         ],
 
         // bughunt / testing の storage fake 用ローカル disk (実 S3 非依存の emulation)。
diff --git a/config/queue.php b/config/queue.php
index 8ed6d7f..b666eb6 100644
--- a/config/queue.php
+++ b/config/queue.php
@@ -38,16 +38,18 @@
         // 既定接続 (Billing 6 / Mail 2 / Notification 6)。retry_after は **リテラル**で持つ:
         // 静的 gate (QueueWorkerLeaseInvariantTest) は config をテスト環境の値で読むため、
         // env 上書きを残すと「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
-        // 600s の根拠: この接続の既知の有限上限は ExecuteAutoRechargeAttemptJob の
-        // Stripe 4〜5 呼び出し × SDK 上限 80s (Stripe\HttpClient\CurlClient::DEFAULT_TIMEOUT)
-        // = 約 400s。ワーカー --timeout 540 (< 600) がそれを上回る
-        // (docs/architecture.md §キューのリース期間とワーカー制限時間の規約)。
+        // 360s の根拠 (T126 で SDK 既定依存を解消):
+        //   外部予算 200s (= Stripe 20s × 呼び出し予算 10 回。App\Support\ExternalClientTimeouts)
+        //   + 局所予算 90s = 290s < ワーカー --timeout 300s < retry_after 360s。
+        //   序列は ExternalClientTimeoutInventoryTest が厳密不等号で固定する
+        //   (docs/architecture.md §キューのリース期間とワーカー制限時間の規約 /
+        //    §外部 SDK の待ち上限の規約)。
         'database' => [
             'driver' => 'database',
             'connection' => env('DB_QUEUE_CONNECTION'),
             'table' => env('DB_QUEUE_TABLE', 'jobs'),
             'queue' => env('DB_QUEUE', 'default'),
-            'retry_after' => 600,
+            'retry_after' => 360,
             'after_commit' => false,
         ],
 
diff --git a/config/services.php b/config/services.php
index 40666c8..ef09b3c 100644
--- a/config/services.php
+++ b/config/services.php
@@ -1,5 +1,7 @@
 <?php
 
+use App\Support\ExternalClientTimeouts;
+
 return [
 
     /*
@@ -36,6 +38,17 @@
             'trim',
             explode(',', (string) env('SES_SNS_TOPIC_ARNS', ''))
         ))),
+        // ★**vendor 契約に依存する**: Illuminate\Mail\MailManager::createSesV2Transport() は
+        //   array_merge(config('services.ses'), ['version' => 'latest'], $config) を
+        //   Arr::except(…, ['transport']) して **そのまま new SesV2Client(...)** へ渡す。
+        //   したがって AWS client option は**この配列の直下**に置く必要がある
+        //   (`client_options` のようにネストすると AWS の ClientResolver から見て未知キーになり
+        //    黙って無視される = pin が効かない)。アプリ設定 (options / sns_topic_arns) と
+        //   同居するのは Laravel 側の契約であり、この前提は
+        //   ExternalClientTimeoutInventoryTest の
+        //   「vendor 契約: MailManager は services.ses を SesV2Client の構築引数へ素通しする」が
+        //   behavioral に固定する (Laravel が strict 化した瞬間に赤くなる)。
+        ...ExternalClientTimeouts::awsControlClientOptions(),
     ],
 
     'google' => [
diff --git a/docs/architecture.md b/docs/architecture.md
index 3336b46..dcf9950 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -256,7 +256,7 @@ ### キューのリース期間とワーカー制限時間の規約
 
 | 接続 | `retry_after` | ワーカー `--timeout` | 備考 |
 |---|---|---|---|
-| `database` | 600 | **540** | 既知の有限上限は Stripe 4〜5 呼び出し × SDK 上限 80s = 約 400s |
+| `database` | 360 | **300** | 外部予算 200s (Stripe 20s × 呼び出し予算 10 回) + 局所予算 90s = 290 < 300 (T126)。§外部 SDK の待ち上限の規約 |
 | `database-analysis` | 1680 | **1620** | ジョブ側 `$timeout` 1,560 を上回る帯 |
 | `database-render` | 1680 | **1620** | ジョブ側 `$timeout` 1,500 を上回る帯 |
 | `database-media` | 300 | **240** | 削除は冪等 + `$tries=3` なので kill されても再配布で完了する |
@@ -932,3 +932,86 @@ ## 外部 fake 配線の不変条件 (T119)
   参照してよいのは配線点と fake storage signed route の受け口を含む 4 ファイルだけで、
   allowlist の件数はテストが固定している (増やすには理由コメントと併せて 2 箇所を触る摩擦がかかる)。
   **誤検出が出ても allowlist を足す方向へ倒さない** — それが gate の目的である。
+
+## 外部 SDK の待ち上限の規約 (T126)
+
+外部 SDK (Stripe / AWS) は**無指定だと待ちが有界にならない**
+(Stripe cURL client の既定 80s × SDK 自動リトライ / AWS は timeout 無指定 = 無制限 × 3 attempts)。
+値の正本は **`App\Support\ExternalClientTimeouts`** ただ 1 つで、env で上書きできる口を作らない
+(gate が読む値と本番の実値を一致させるため。`config/queue.php` の `retry_after` と同じ理屈)。
+
+> **用語 (誇張しない)**: 「HTTP 試行 timeout 予算」= cURL / Guzzle に与える 1 試行あたりの上限 × 試行回数。
+> **SDK 操作全体の wall-clock deadline ではない** (DNS 解決・credential provider・
+> endpoint discovery・retry backoff はこの外側)。
+
+| 面 | 値 | 配線点 |
+|---|---|---|
+| Stripe (プロセス大域) | connect 5s / timeout 20s / `max_network_retries` 0 | `App\Providers\ExternalClientTimeoutServiceProvider` |
+| AWS 制御系 (SES 送信 / SNS) | connect 5s / timeout 15s / `max_attempts` 2 | `config/services.php` の `ses` / `AppServiceProvider` の `SnsClient` singleton |
+| AWS データ系 (s3 disk 既定) | connect 10s / timeout 900s / `max_attempts` 2 | `config/filesystems.php` の `disks.s3` |
+| S3 per-command (web 同期の metadata) | connect 5s / timeout 15s / `@retries` 0 | `TakeObjectStorage::headObject()` |
+
+- **Stripe は client ごとの timeout を支えない**。`StripeClient` の config に timeout 系のキーが無く、
+  `Stripe\ApiRequestor` の static HTTP client だけが唯一の調整点である。したがってテナント別 timeout は持たない。
+- **`max_network_retries = 0`** に pin する。課金の一回性は **Stripe idempotency key とリコンサイル**が
+  担う設計 (AGENTS.md ドメイン規約 6) であり、SDK 自動 retry に寄せない
+  (0 でないとジョブの外部予算が retry 数だけ倍化する)。
+- **AWS の語彙に注意**: 構築引数の `retries.max_attempts` は **初回を含む試行回数** (2 = 初回 + 再試行 1 回)、
+  per-command の `@retries` は **retry 回数** (0 = 再試行しない)。同じ数字でも意味が違う。
+- **s3 disk の既定を短くできない**: Flysystem の write 経路 (`AwsS3V3Adapter::upload()`) は
+  `@http` を per-command で転送しないため、client 既定がデータ系を賄う必要がある。
+- **`services.ses` は vendor 契約に依存する**。`Illuminate\Mail\MailManager::createSesV2Transport()` が
+  `config('services.ses')` を **そのまま `new SesV2Client(...)` へ渡す**ため、AWS client option は
+  この配列の**直下**に置く (ネストすると AWS 側から未知キーになり黙って無視される)。
+  この前提は `ExternalClientTimeoutInventoryTest` が behavioral に固定する。
+
+### S3 到達境界と面分類
+
+- 業務層は **`TakeObjectStorage` / `RenderObjectStorage`** しか参照しない。AWS SDK / Flysystem へ
+  到達しうる `app/` のクラスは `ExternalClientTimeoutInventoryTest` の目録へ
+  「adapter」か「免除 (`App\Enums\Storage\ExternalClientBoundaryExemption` + 30 文字以上の根拠)」で
+  登録が必須 (deny-by-default)。
+- adapter の public メソッドは **`App\Enums\Storage\S3OperationSurface`** で面分類する
+  (正本は `tests/Support/Storage/S3SurfaceInventory`)。分類軸は「転送量が有界か」と
+  「per-command option を注入できるか」の 2 つ。
+- **`Bulk` 面を web 同期経路から呼ばない**。これは**規約であって機械証明ではない**
+  (呼び出しグラフ解析が要る)。既存の web 経路については
+  `tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php` が behavioral に固定する。
+- **走査の保証範囲を誇張しない**: 目録の母集団は「型/クラス名の参照」「`disk()` / `getClient()` の
+  呼び出し」「Stripe 大域 setter の呼び出し」の静的検出である。**文字列キーの container 解決だけで
+  これらの token をまったく出さない迂回は検出できない**。だから**やらない**、が規約の側の担保である。
+
+### 帯を変更するときのデプロイ順序
+
+**worker の起動形態は環境で違う**: `mprocs.yaml` は **dev** で `queue:listen`、
+**本番/ステージングの supervisor** は上の値表どおり `queue:work`。確認コマンドは**両方**を拾う正規表現にする。
+
+```
+0. 実施条件 (手順 1 の前に確認する)
+   - **低トラフィック時間帯**に実施する (SIGALRM で落ちる旧ジョブを最小化するため)
+   - `database` キューの未処理件数が 0 に近いこと
+     (select count(*) from jobs where queue = 'default')
+   - オートリチャージの pending attempt が滞留していないこと
+     (select count(*) from ticket_auto_recharge_attempts where status = 'pending')
+
+1. 全 worker の supervisor 定義を --timeout=540 → 300 へ変更して再起動する
+   (このときコードは旧のまま = retry_after 600。300 < 600 で規則 1 は成立)
+   ★確認方法: 各 worker ホストで
+     pgrep -af 'artisan queue:(work|listen) database( |$)' を実行し、
+     出力の全行に --timeout=300 が含まれること。実施主体は本番デプロイ担当。
+
+2. 新コード (SDK pin + retry_after 360) をデプロイし、全 worker を入れ替える
+
+3. 旧 worker が残っていないことを確認する
+   ★確認方法: 同コマンドで --timeout=540 の行が 0 件であること。
+     加えてデプロイ開始時刻より前に起動した worker プロセスが残っていないこと
+     (ps -o lstart=,args= -p <pid>)
+
+4. 実施後、手順 0 と同じクエリで pending attempt の残留を確認し、
+   残っていればリコンサイルの完了を待つ (または手動起動する)
+```
+
+**受容事項**: 手順 1 の間、旧コード (Stripe 80s 前提) のジョブが 300s で SIGALRM されうる。
+`ExecuteAutoRechargeAttemptJob` は `$tries = 1` でリコンサイルが再試行を担うため、恒久喪失にはならない
+(Stripe idempotency key により二重課金にもならない)。手順 0 の実施条件はこの受容の**発生確率を下げる**
+ためのものであり、「起きない」ことの保証ではない。
diff --git a/tests/Architecture/ExternalClientTimeoutInventoryTest.php b/tests/Architecture/ExternalClientTimeoutInventoryTest.php
new file mode 100644
index 0000000..5f315cc
--- /dev/null
+++ b/tests/Architecture/ExternalClientTimeoutInventoryTest.php
@@ -0,0 +1,553 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Storage\ExternalClientBoundaryExemption;
+use App\Enums\Storage\S3OperationSurface;
+use App\Http\Controllers\Webhooks\SesNotificationController;
+use App\Http\Middleware\VerifySnsSignature;
+use App\Providers\AppServiceProvider;
+use App\Providers\ExternalClientTimeoutServiceProvider;
+use App\Services\Capture\Fakes\FakeTakeObjectStorage;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Mail\Sns\AwsSnsSignatureVerifier;
+use App\Services\Mail\Sns\SnsSignatureVerifier;
+use App\Services\Manual\SopTextExtractor;
+use App\Services\Manual\SourceDocumentService;
+use App\Services\Render\Fakes\FakeRenderObjectStorage;
+use App\Services\Render\RenderObjectStorage;
+use App\Services\Storage\Fakes\FakeObjectStore;
+use App\Support\ExternalClientTimeouts;
+use Aws\CommandInterface;
+use Aws\Exception\AwsException;
+use Aws\MockHandler;
+use Aws\Retry\ConfigurationProvider as AwsRetryConfigurationProvider;
+use Aws\Sns\SnsClient;
+use Illuminate\Filesystem\AwsS3V3Adapter;
+use Illuminate\Mail\Transport\SesV2Transport;
+use Illuminate\Support\Facades\Mail;
+use Illuminate\Support\Facades\Storage;
+use Stripe\HttpClient\CurlClient;
+use Tests\Support\ExternalClientBoundaryScanner;
+use Tests\Support\QueueLeaseConfig;
+use Tests\Support\ScanScopeKind;
+use Tests\Support\Storage\S3SurfaceInventory;
+use Webmozart\Assert\Assert;
+
+/*
+ * 外部 SDK (Stripe / AWS) の待ち上限を pin する不変条件 (T126)。
+ *
+ * - **到達境界の目録**: AWS SDK / Flysystem へ到達しうる app/ のクラスは、S3 集約 adapter か
+ *   免除 (enum + 30 文字以上の根拠) のどちらかで登録が必須 (deny-by-default)。
+ * - **面分類の目録**: adapter の public メソッドは「転送量が有界か / per-command option を
+ *   注入できるか」の 2 軸で分類し、対称差ゼロを機械強制する。
+ * - **behavioral**: config を読むだけでは「Laravel / vendor が config を素通ししている」ことを
+ *   検証できないため、構築済みクライアントの `getCommand()` を見る。
+ * - **負のコントロール**: pin 値が SDK 既定値と異なること / per-command 上書きが client 既定を
+ *   実際に上書きすること / 走査が空振りしないこと を明示的に固定する。
+ *
+ * 運用契約は docs/architecture.md §外部 SDK の待ち上限の規約。
+ */
+
+/**
+ * 到達境界の目録 (deny-by-default)。
+ *
+ * value: S3 集約 adapter は `['surface' => 'adapter']`、それ以外は免除理由 (enum + 30 文字以上の根拠)。
+ *
+ * ★`surface: adapter` の意味は「**public method ごとの面分類を要求する本番集約**」に定める。
+ *   fake は本番の外部到達を持たないため面を持たず、`exempt` 側で登録する
+ *   (adapter に混ぜると検査 5b の対称差が構造的に成立しない)。
+ *
+ * @var array<class-string, array{surface: 'adapter'}|array{surface: 'exempt', reason: ExternalClientBoundaryExemption, rationale: string}>
+ */
+const EXTERNAL_CLIENT_BOUNDARY_INVENTORY = [
+    TakeObjectStorage::class => ['surface' => 'adapter'],
+    RenderObjectStorage::class => ['surface' => 'adapter'],
+
+    FakeTakeObjectStorage::class => [
+        'surface' => 'exempt',
+        'reason' => ExternalClientBoundaryExemption::TestDoubleWithoutExternalEgress,
+        'rationale' => 'client() が例外を投げ実 S3 経路へ落ちない fake。本番の外部到達を持たない',
+    ],
+    FakeRenderObjectStorage::class => [
+        'surface' => 'exempt',
+        'reason' => ExternalClientBoundaryExemption::TestDoubleWithoutExternalEgress,
+        'rationale' => 'disk() を s3_fake (ローカル disk) に固定する fake。本番の外部到達を持たない',
+    ],
+    FakeObjectStore::class => [
+        'surface' => 'exempt',
+        'reason' => ExternalClientBoundaryExemption::TestDoubleWithoutExternalEgress,
+        'rationale' => 's3_fake ローカル disk 上の emulation 基盤。AWS SDK をまったく構築しない',
+    ],
+
+    AppServiceProvider::class => [
+        'surface' => 'exempt',
+        'reason' => ExternalClientBoundaryExemption::PinnedControlClientConstruction,
+        'rationale' => 'SNS 購読確認クライアントの構築点。制御系 pin を構築引数へ展開しており転送量も有界',
+    ],
+    ExternalClientTimeoutServiceProvider::class => [
+        'surface' => 'exempt',
+        'reason' => ExternalClientBoundaryExemption::GlobalSdkTimeoutPin,
+        'rationale' => 'Stripe SDK のプロセス大域 timeout を pin する唯一の場所。他に副作用を持たない',
+    ],
+
+    SesNotificationController::class => [
+        'surface' => 'exempt',
+        'reason' => ExternalClientBoundaryExemption::InjectedPinnedControlClient,
+        'rationale' => '構築点 (AppServiceProvider) が pin した SnsClient を DI で受け取るだけの消費点',
+    ],
+    AwsSnsSignatureVerifier::class => [
+        'surface' => 'exempt',
+        'reason' => ExternalClientBoundaryExemption::AwsValueObjectOnly,
+        'rationale' => 'MessageValidator は署名検証のみで送信しない。証明書取得は自前 HTTP client で timeout 済み',
+    ],
+    SnsSignatureVerifier::class => [
+        'surface' => 'exempt',
+        'reason' => ExternalClientBoundaryExemption::AwsValueObjectOnly,
+        'rationale' => 'Aws\Sns\Message を引数型に取るだけの interface。クライアントを構築も取得もしない',
+    ],
+    VerifySnsSignature::class => [
+        'surface' => 'exempt',
+        'reason' => ExternalClientBoundaryExemption::AwsValueObjectOnly,
+        'rationale' => '検証済み Aws\Sns\Message を request attribute へ載せるだけ。SDK クライアントを持たない',
+    ],
+
+    SopTextExtractor::class => [
+        'surface' => 'exempt',
+        'reason' => ExternalClientBoundaryExemption::DefaultDiskWithoutAwsClient,
+        'rationale' => 'SOP 原稿を既定 disk (ローカル) から読むだけで s3 disk も AWS client も解決しない',
+    ],
+    SourceDocumentService::class => [
+        'surface' => 'exempt',
+        'reason' => ExternalClientBoundaryExemption::DefaultDiskWithoutAwsClient,
+        'rationale' => 'アップロード原本を既定 disk (ローカル) へ置くだけで s3 disk も AWS client も解決しない',
+    ],
+];
+
+/**
+ * Stripe のプロセス大域 setter を呼んでよい箇所の期待表
+ * (**相対パス × シンボル × site 件数**)。
+ *
+ * ★「許可ファイルなら何件でもよい」では exact-fit にならないため件数まで固定する。
+ * ★provider は `CurlClient::instance()` を呼ばない (意図的に `new CurlClient` を使う。
+ *   シングルトンを書き換えると「誰が設定したか」が追えず、テストの復元先も曖昧になるため)。
+ * ★`Stripe\ApiRequestor::httpClient()` (getter) は状態を変えないため制限しない。
+ *
+ * @var array<string, array<string, int>>
+ */
+const STRIPE_GLOBAL_SETTER_EXPECTATION = [
+    'app/Providers/ExternalClientTimeoutServiceProvider.php' => [
+        'setHttpClient' => 1,
+        'setMaxNetworkRetries' => 1,
+    ],
+    'tests/Feature/Providers/ExternalClientTimeoutServiceProviderTest.php' => [
+        'setHttpClient' => 2,
+        'setMaxNetworkRetries' => 2,
+    ],
+    'tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php' => [
+        'setHttpClient' => 2,
+    ],
+];
+
+/**
+ * app/ 配下の到達境界 site を走査する。
+ *
+ * @return list<array{path: string, line: int, rule: string, name: string, scopeKind: ScanScopeKind, class: string|null, callable: string|null, diskArgument: 'none'|'static'|'dynamic'|null}>
+ */
+function externalClientBoundarySites(): array
+{
+    $root = dirname(__DIR__, 2);
+    $sites = [];
+    foreach (ExternalClientBoundaryScanner::phpFiles($root.'/app', 'app') as $relative => $source) {
+        array_push($sites, ...ExternalClientBoundaryScanner::boundarySites($relative, $source));
+    }
+
+    return $sites;
+}
+
+/**
+ * Stripe 大域 setter の site を app/ と tests/ の両方から走査する。
+ *
+ * 走査範囲に `tests/` を含めるのは、**無関係なテストが大域状態を書き換えて他テストを汚染する**
+ * ことを防ぐためである (`--parallel` はファイル単位でプロセスを分けるが、
+ * 同一プロセス内の実行順依存は残る)。
+ *
+ * @return list<array{path: string, line: int, rule: string, name: string, scopeKind: ScanScopeKind, class: string|null, callable: string|null, diskArgument: 'none'|'static'|'dynamic'|null}>
+ */
+function externalClientStripeGlobalSites(): array
+{
+    $root = dirname(__DIR__, 2);
+    $sites = [];
+    foreach (['app', 'tests'] as $directory) {
+        foreach (ExternalClientBoundaryScanner::phpFiles($root.'/'.$directory, $directory) as $relative => $source) {
+            array_push($sites, ...ExternalClientBoundaryScanner::stripeGlobalSites($relative, $source));
+        }
+    }
+
+    return $sites;
+}
+
+/** テスト環境で構築できる s3 disk (ダミー資格情報。実 config の http / retries はそのまま持ち込む)。 */
+function externalClientS3Adapter(): AwsS3V3Adapter
+{
+    $disk = Storage::build(array_merge(
+        config()->array('filesystems.disks.s3'),
+        ['region' => 'us-east-1', 'bucket' => 'gate', 'key' => 'k', 'secret' => 's'],
+    ));
+    Assert::isInstanceOf($disk, AwsS3V3Adapter::class, 's3 disk が AwsS3V3Adapter として構築されていません');
+
+    return $disk;
+}
+
+// ---------------------------------------------------------------------------
+// 到達境界の目録
+// ---------------------------------------------------------------------------
+
+test('到達境界: AWS / Flysystem へ到達するクラスは目録と対称差ゼロ', function (): void {
+    $sites = externalClientBoundarySites();
+
+    // R2〜R5 の実 site は app/ の**名前付きクラス**へ帰属していなければならない
+    // (匿名クラスやファイルスコープで境界を跨ぐ抜け道を作らせない)。
+    $scopeViolations = [];
+    $scanned = [];
+    foreach ($sites as $site) {
+        if ($site['scopeKind'] !== ScanScopeKind::NamedClass || $site['class'] === null) {
+            $scopeViolations[] = ExternalClientBoundaryScanner::describe($site)
+                ." — scope={$site['scopeKind']->name} (名前付きクラス本体へ帰属していない)";
+
+            continue;
+        }
+        $scanned[$site['class']] = true;
+    }
+
+    expect($scopeViolations)->toBe([], implode(PHP_EOL, $scopeViolations));
+
+    $scannedClasses = array_keys($scanned);
+    sort($scannedClasses);
+    $registered = array_keys(EXTERNAL_CLIENT_BOUNDARY_INVENTORY);
+    sort($registered);
+
+    $missing = array_values(array_diff($scannedClasses, $registered));
+    $stale = array_values(array_diff($registered, $scannedClasses));
+
+    $diagnostics = [];
+    foreach ($sites as $site) {
+        if ($site['class'] !== null && in_array($site['class'], $missing, true)) {
+            $diagnostics[] = ExternalClientBoundaryScanner::describe($site);
+        }
+    }
+
+    expect($missing)->toBe(
+        [],
+        '到達境界: 目録へ未登録のクラスが AWS SDK / Flysystem へ到達しています。'
+        .'S3 集約 adapter へ寄せるか、ExternalClientBoundaryExemption + 30 文字以上の根拠で登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $diagnostics),
+    );
+    expect($stale)->toBe(
+        [],
+        '到達境界: 目録に残骸があります (走査で検出されないクラスが登録されたままです)',
+    );
+});
+
+test('到達境界: 走査母集団が空でない', function (): void {
+    // 走査条件を壊して 0 件になったときに green にならないための空振り防止。
+    expect(externalClientBoundarySites())->not->toBeEmpty(
+        '到達境界: 走査結果が 0 件です (走査条件が壊れている疑い)',
+    );
+    expect(count(EXTERNAL_CLIENT_BOUNDARY_INVENTORY))->toBeGreaterThan(0);
+});
+
+test('到達境界: 免除には 30 文字以上の根拠がある', function (): void {
+    $violations = [];
+    foreach (EXTERNAL_CLIENT_BOUNDARY_INVENTORY as $class => $entry) {
+        if ($entry['surface'] !== 'exempt') {
+            continue;
+        }
+        if (mb_strlen($entry['rationale']) < 30) {
+            $violations[] = "{$class}: 免除の根拠が 30 文字未満です ({$entry['rationale']})";
+        }
+    }
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+});
+
+test('到達境界: disk 名は静的に決まる', function (): void {
+    // 動的 disk 名 (`->disk($variable)`) は静的に面を決められないため禁止する。
+    // 引数なしの `disk()` は集約 accessor (`$this->disk()`) であり disk 名を選ばない。
+    $violations = [];
+    foreach (externalClientBoundarySites() as $site) {
+        if ($site['rule'] !== 'disk_call') {
+            continue;
+        }
+        if ($site['diskArgument'] === 'dynamic') {
+            $violations[] = ExternalClientBoundaryScanner::describe($site).' — disk 名が変数です';
+        }
+    }
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+});
+
+test('到達境界: Stripe の大域 setter はシンボルごとに許可箇所へ限定される', function (): void {
+    $sites = externalClientStripeGlobalSites();
+
+    /** @var array<string, array<string, int>> $actual */
+    $actual = [];
+    /** @var array<string, list<string>> $diagnostics */
+    $diagnostics = [];
+    foreach ($sites as $site) {
+        $actual[$site['path']][$site['name']] = ($actual[$site['path']][$site['name']] ?? 0) + 1;
+        $diagnostics[$site['path']][] = ExternalClientBoundaryScanner::describe($site);
+    }
+
+    // 行番号は期待値の識別子にしない (整形で動くため)。診断情報としてのみ出す。
+    $flatten = static function (array $table): array {
+        $flat = [];
+        foreach ($table as $path => $symbols) {
+            Assert::string($path);
+            Assert::isArray($symbols);
+            foreach ($symbols as $symbol => $count) {
+                $flat["{$path} [{$symbol}]"] = $count;
+            }
+        }
+        ksort($flat);
+
+        return $flat;
+    };
+
+    $expectedFlat = $flatten(STRIPE_GLOBAL_SETTER_EXPECTATION);
+    $actualFlat = $flatten($actual);
+
+    $lines = [];
+    foreach ($diagnostics as $path => $entries) {
+        array_push($lines, ...$entries);
+    }
+
+    expect($actualFlat)->toBe(
+        $expectedFlat,
+        'Stripe のプロセス大域 setter (setHttpClient / setMaxNetworkRetries / CurlClient::instance) は'
+        .'許可した相対パス × シンボル × 件数と完全一致していなければなりません。'
+        .PHP_EOL.implode(PHP_EOL, $lines),
+    );
+});
+
+test('到達境界: adapter 集合は面分類目録のクラスキーと一致する', function (): void {
+    $adapters = [];
+    foreach (EXTERNAL_CLIENT_BOUNDARY_INVENTORY as $class => $entry) {
+        if ($entry['surface'] === 'adapter') {
+            $adapters[] = $class;
+        }
+    }
+    sort($adapters);
+
+    $surfaceClasses = array_keys(S3SurfaceInventory::all());
+    sort($surfaceClasses);
+
+    expect($adapters)->toBe(
+        $surfaceClasses,
+        '到達境界の adapter 集合と面分類目録のクラスキーが割れています (2 目録の意味が結ばれていません)',
+    );
+});
+
+// ---------------------------------------------------------------------------
+// 面分類の目録
+// ---------------------------------------------------------------------------
+
+test('面分類: adapter の public メソッドは目録と対称差ゼロ', function (): void {
+    foreach (S3SurfaceInventory::all() as $class => $methods) {
+        $reflection = new ReflectionClass($class);
+        $declared = [];
+        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
+            if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor()) {
+                continue;
+            }
+            $declared[] = $method->getName();
+        }
+        sort($declared);
+
+        $registered = array_keys($methods);
+        sort($registered);
+
+        expect($declared)->toBe(
+            $registered,
+            "面分類: {$class} の public メソッドと面分類目録が一致しません "
+            .'(tests/Support/Storage/S3SurfaceInventory.php を更新してください)',
+        );
+        expect($declared)->not->toBeEmpty();
+    }
+});
+
+test('面分類: 各 entry に 30 文字以上の根拠がある', function (): void {
+    $violations = [];
+    foreach (S3SurfaceInventory::all() as $class => $methods) {
+        foreach ($methods as $method => $entry) {
+            if (mb_strlen($entry['rationale']) < 30) {
+                $violations[] = "{$class}::{$method}: 根拠が 30 文字未満です ({$entry['rationale']})";
+            }
+        }
+    }
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+});
+
+test('面分類: BoundedControl は 1 つ以上ある', function (): void {
+    // 全部 Bulk にすれば通る、を防ぐ空振り防止。
+    $bounded = 0;
+    foreach (S3SurfaceInventory::all() as $methods) {
+        foreach ($methods as $entry) {
+            if ($entry['surface'] === S3OperationSurface::BoundedControl) {
+                $bounded++;
+            }
+        }
+    }
+
+    expect($bounded)->toBeGreaterThan(0, '面分類: BoundedControl の entry が 1 件もありません');
+});
+
+// ---------------------------------------------------------------------------
+// pin 値 / 配線
+// ---------------------------------------------------------------------------
+
+test('pin 値は SDK 既定値と異なる', function (): void {
+    // 負のコントロール: 「pin していないのに green」を構造的に排除する。
+    expect(ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS)
+        ->not->toBe(CurlClient::DEFAULT_TIMEOUT, 'Stripe の timeout が SDK 既定 (80s) のままです');
+    expect(ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS)
+        ->not->toBe(CurlClient::DEFAULT_CONNECT_TIMEOUT, 'Stripe の connect_timeout が SDK 既定 (30s) のままです');
+    expect(ExternalClientTimeouts::AWS_MAX_ATTEMPTS)
+        ->not->toBe(AwsRetryConfigurationProvider::DEFAULT_MAX_ATTEMPTS, 'AWS の max_attempts が SDK 既定 (3) のままです');
+});
+
+test('AWS config: s3 / ses が http と retries を宣言する', function (): void {
+    expect(config()->array('filesystems.disks.s3.http'))->toBe([
+        'connect_timeout' => ExternalClientTimeouts::AWS_S3_CONNECT_TIMEOUT_SECONDS,
+        'timeout' => ExternalClientTimeouts::AWS_S3_TIMEOUT_SECONDS,
+    ]);
+    expect(config()->array('filesystems.disks.s3.retries'))->toBe([
+        'mode' => 'legacy',
+        'max_attempts' => ExternalClientTimeouts::AWS_MAX_ATTEMPTS,
+    ]);
+
+    expect(config()->array('services.ses.http'))->toBe([
+        'connect_timeout' => ExternalClientTimeouts::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS,
+        'timeout' => ExternalClientTimeouts::AWS_CONTROL_TIMEOUT_SECONDS,
+    ]);
+    expect(config()->array('services.ses.retries'))->toBe([
+        'mode' => 'legacy',
+        'max_attempts' => ExternalClientTimeouts::AWS_MAX_ATTEMPTS,
+    ]);
+});
+
+test('AWS behavioral: s3 disk クライアントの @http が pin 値になる', function (): void {
+    // config を読むだけでは「Laravel が config を S3Client へ素通ししている」ことを検証できない。
+    // getCommand() は送信しない (ネットワークへ一切出ない)。
+    $command = externalClientS3Adapter()->getClient()->getCommand('HeadObject', ['Bucket' => 'gate', 'Key' => 'k']);
+
+    // ★SDK が既定で足す他キー (decode_content 等) には触れず、pin した 2 キーだけを固定する。
+    expect($command['@http']['connect_timeout'])->toBe(ExternalClientTimeouts::AWS_S3_CONNECT_TIMEOUT_SECONDS);
+    expect($command['@http']['timeout'])->toBe(ExternalClientTimeouts::AWS_S3_TIMEOUT_SECONDS);
+});
+
+test('AWS behavioral: vendor 契約: MailManager は services.ses を SesV2Client の構築引数へ素通しする', function (): void {
+    // ★`new SesV2Client(...)` の直接構築へ fallback しない — それでは「素通しする」という
+    //   vendor 契約自体を検証できず、gate が意味を失う。
+    config(['mail.default' => 'ses']);
+    $transport = Mail::mailer('ses')->getSymfonyTransport();
+    Assert::isInstanceOf($transport, SesV2Transport::class, 'ses mailer が SesV2Transport で解決されていません');
+
+    $command = $transport->ses()->getCommand('SendEmail', [
+        'Content' => ['Raw' => ['Data' => 'x']],
+    ]);
+
+    expect($command['@http']['connect_timeout'])->toBe(ExternalClientTimeouts::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS);
+    expect($command['@http']['timeout'])->toBe(ExternalClientTimeouts::AWS_CONTROL_TIMEOUT_SECONDS);
+});
+
+test('AWS behavioral: SnsClient singleton の @http が pin 値になる', function (): void {
+    $client = app(SnsClient::class);
+    $command = $client->getCommand('ConfirmSubscription', [
+        'TopicArn' => 'arn:aws:sns:us-east-1:000000000000:gate',
+        'Token' => 'token',
+    ]);
+
+    expect($command['@http']['connect_timeout'])->toBe(ExternalClientTimeouts::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS);
+    expect($command['@http']['timeout'])->toBe(ExternalClientTimeouts::AWS_CONTROL_TIMEOUT_SECONDS);
+});
+
+test('AWS behavioral: retries.max_attempts は「初回を含む試行回数」として効く', function (): void {
+    // ★語彙の確認 (vendor 実査を CI に固定する): `retries` を array で渡すと
+    //   `max_attempts` は **初回を含む試行回数**として解釈される
+    //   (per-command の `@retries` = retry 回数 とは意味が違う)。
+    //   `getCommand()` には現れない設定なので、MockHandler で**実際の試行回数**を数える。
+    //   ネットワークへは一切出ない (handler が差し替わっている)。
+    $attempts = 0;
+    $handler = new MockHandler;
+    for ($i = 0; $i < ExternalClientTimeouts::AWS_MAX_ATTEMPTS + 3; $i++) {
+        $handler->append(function (CommandInterface $command) use (&$attempts): AwsException {
+            $attempts++;
+
+            return new AwsException('gate', $command, ['connection_error' => true]);
+        });
+    }
+
+    $client = new SnsClient(array_merge(
+        [
+            'version' => 'latest',
+            'region' => 'us-east-1',
+            'credentials' => ['key' => 'k', 'secret' => 's'],
+            'handler' => $handler,
+        ],
+        ExternalClientTimeouts::awsControlClientOptions(),
+    ));
+
+    try {
+        $client->confirmSubscription(['TopicArn' => 'arn:aws:sns:us-east-1:000000000000:gate', 'Token' => 'token']);
+    } catch (AwsException) {
+        // 全試行が失敗して例外になるのが期待動作
+    }
+
+    expect($attempts)->toBe(
+        ExternalClientTimeouts::AWS_MAX_ATTEMPTS,
+        'AWS_MAX_ATTEMPTS は初回を含む試行回数として効く必要があります',
+    );
+});
+
+// ---------------------------------------------------------------------------
+// 時間予算の序列
+// ---------------------------------------------------------------------------
+
+test('時間予算: 外部予算 + 局所予算 < worker --timeout < retry_after', function (): void {
+    $connections = QueueLeaseConfig::databaseConnections();
+    expect($connections)->toHaveKey('database');
+    $retryAfter = $connections['database'];
+
+    // 外部予算は「Stripe の 1 リクエスト上限 × 呼び出し予算」で定義する (定義の割れ防止)。
+    expect(ExternalClientTimeouts::DEFAULT_CONNECTION_EXTERNAL_BUDGET_SECONDS)->toBe(
+        ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS * ExternalClientTimeouts::DEFAULT_CONNECTION_STRIPE_CALL_BUDGET,
+    );
+
+    $budget = ExternalClientTimeouts::DEFAULT_CONNECTION_EXTERNAL_BUDGET_SECONDS
+        + ExternalClientTimeouts::DEFAULT_CONNECTION_LOCAL_BUDGET_SECONDS;
+
+    // 厳密不等号 (等号で「使い切ったら間に合わない」状態を通さない)。
+    expect($budget)->toBeLessThan(ExternalClientTimeouts::DEFAULT_CONNECTION_WORKER_TIMEOUT_SECONDS);
+    expect(ExternalClientTimeouts::DEFAULT_CONNECTION_WORKER_TIMEOUT_SECONDS)->toBeLessThan($retryAfter);
+});
+
+test('時間予算: mprocs の database worker --timeout が定数と一致する', function (): void {
+    // docs / config / ワーカー定義の 3 者が割れないようにする
+    // (既存 QueueWorkerLeaseInvariantTest は「retry_after 未満」しか見ていない)。
+    $mprocs = file_get_contents(dirname(__DIR__, 2).'/mprocs.yaml');
+    Assert::string($mprocs, 'mprocs.yaml を読めません');
+
+    $matched = preg_match(
+        '/queue:listen\s+database\s+--tries=1\s+--timeout=(\d+)/',
+        $mprocs,
+        $matches,
+    );
+
+    expect($matched)->toBe(1, 'mprocs.yaml に database 接続の queue:listen 行が見つかりません');
+    expect((int) $matches[1])->toBe(
+        ExternalClientTimeouts::DEFAULT_CONNECTION_WORKER_TIMEOUT_SECONDS,
+        'mprocs.yaml の database worker --timeout が ExternalClientTimeouts の宣言値と一致しません',
+    );
+});
diff --git a/tests/Architecture/QueueWorkerLeaseInvariantTest.php b/tests/Architecture/QueueWorkerLeaseInvariantTest.php
index ab82aad..89ef794 100644
--- a/tests/Architecture/QueueWorkerLeaseInvariantTest.php
+++ b/tests/Architecture/QueueWorkerLeaseInvariantTest.php
@@ -409,9 +409,9 @@ function queueLeaseBughuntSource(): string
         $connections = QueueLeaseConfig::databaseConnections();
         expect($connections)->toHaveKey('database');
         expect($connections['database'])->toBe(
-            600,
+            360,
             '規則 1: config/queue.php の database.retry_after が env で上書きされた。'
-            .'env('."'DB_QUEUE_RETRY_AFTER'".') ではなくリテラル 600 で持つこと',
+            .'env('."'DB_QUEUE_RETRY_AFTER'".') ではなくリテラル 360 で持つこと',
         );
     } finally {
         if ($hadOriginal && is_string($original)) {
diff --git a/tests/Architecture/QueuedJobLeaseInventoryTest.php b/tests/Architecture/QueuedJobLeaseInventoryTest.php
index e9be8de..2efc2f1 100644
--- a/tests/Architecture/QueuedJobLeaseInventoryTest.php
+++ b/tests/Architecture/QueuedJobLeaseInventoryTest.php
@@ -20,6 +20,7 @@
 use App\Notifications\Billing\AutoRechargeFailedNotification;
 use App\Notifications\Billing\PaymentFailedNotification;
 use App\Notifications\Billing\RenewalReminderNotification;
+use Tests\Support\PhpTokenScan;
 use Tests\Support\QueuedJobPopulation;
 use Tests\Support\QueueLeaseConfig;
 use Webmozart\Assert\Assert;
@@ -300,26 +301,14 @@ function jobLeaseConnectionDeclarationSites(string $phpSource): array
 /**
  * `token_get_all()` を「空白・コメントを除いた添字連番のリスト」へ正規化する (純関数)。
  *
+ * ★実体は `Tests\Support\PhpTokenScan::normalize()` (T126 で共通化)。
+ *   同じ正規化を 2 本持たないための delegate であり、振る舞いは従前と同一。
+ *
  * @return list<array{id: int|null, text: string, line: int}>
  */
 function jobLeaseNormalizedTokens(string $phpSource): array
 {
-    $normalized = [];
-    foreach (token_get_all($phpSource) as $token) {
-        if (is_array($token)) {
-            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
-                continue;
-            }
-            $normalized[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];
-
-            continue;
-        }
-
-        $line = $normalized === [] ? 0 : $normalized[count($normalized) - 1]['line'];
-        $normalized[] = ['id' => null, 'text' => $token, 'line' => $line];
-    }
-
-    return $normalized;
+    return PhpTokenScan::normalize($phpSource);
 }
 
 /**
diff --git a/tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php b/tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php
new file mode 100644
index 0000000..d85173d
--- /dev/null
+++ b/tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php
@@ -0,0 +1,185 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Models\Billing\TicketAutoRecharge;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\CashierAutoRechargeGateway;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use App\Support\ExternalClientTimeouts;
+use Stripe\ApiRequestor;
+use Tests\Support\Billing\CountingStripeHttpClient;
+
+/*
+ * 既定キュー接続 (database) の **Stripe 呼び出し回数**を behavioral に固定する (T126 施策 6)。
+ *
+ * ★静的な呼び出し site 計数では Cashier 内部 (`createOrGetStripeCustomer` 等) を数えられない。
+ *   `ApiRequestor::setHttpClient()` は Stripe SDK 公式の差し込み口であり、
+ *   **実 `CashierAutoRechargeGateway`** を通した実行時の HTTP 呼び出し回数をそのまま数えられる。
+ * ★外部 HTTP は 1 バイトも発生しない (fake client が応答列を返す)。
+ * ★`config/queue.php` の retry_after は「呼び出し予算 × Stripe timeout + 局所予算」で決めている。
+ *   この回数が増えたら**帯の前提が崩れる**ため、ドリフトを CI で検知する。
+ */
+
+/**
+ * Stripe API の応答 1 件。
+ *
+ * @param  array<string, mixed>  $body
+ * @return array{status: int, body: string}
+ */
+function stripeBudgetResponse(int $status, array $body): array
+{
+    return ['status' => $status, 'body' => json_encode($body, JSON_THROW_ON_ERROR)];
+}
+
+/** @return array{status: int, body: string} */
+function stripeBudgetCustomer(): array
+{
+    return stripeBudgetResponse(200, ['id' => 'cus_gate', 'object' => 'customer']);
+}
+
+/** @return array{status: int, body: string} */
+function stripeBudgetInvoice(string $status, int $amountPaid = 0, int $amountDue = 0): array
+{
+    return stripeBudgetResponse(200, [
+        'id' => 'in_gate',
+        'object' => 'invoice',
+        'status' => $status,
+        'amount_paid' => $amountPaid,
+        'amount_due' => $amountDue,
+        'payments' => ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/invoices/in_gate/payments'],
+    ]);
+}
+
+/** @return array{status: int, body: string} */
+function stripeBudgetInvoiceItem(): array
+{
+    return stripeBudgetResponse(200, ['id' => 'ii_gate', 'object' => 'invoiceitem']);
+}
+
+/** @return array{status: int, body: string} */
+function stripeBudgetCardDeclined(): array
+{
+    return stripeBudgetResponse(402, ['error' => [
+        'type' => 'card_error',
+        'code' => 'card_declined',
+        'decline_code' => 'generic_decline',
+        'message' => 'Your card was declined.',
+    ]]);
+}
+
+/** @return array{status: int, body: string} */
+function stripeBudgetAlreadyFinalized(): array
+{
+    return stripeBudgetResponse(400, ['error' => [
+        'type' => 'invalid_request_error',
+        'message' => 'This invoice is already finalized.',
+    ]]);
+}
+
+/** 有効化済みの auto-recharge 設定 + Stripe customer 済みの組織で pending attempt を作る。 */
+function stripeBudgetPendingAttempt(bool $withInvoice = false): TicketAutoRechargeAttempt
+{
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_gate'])->save();
+    TicketAutoRecharge::factory()->enabled()->create(['organization_id' => $organization->getKey()]);
+
+    $factory = TicketAutoRechargeAttempt::factory();
+    if ($withInvoice) {
+        $factory = $factory->withInvoice('in_gate');
+    }
+
+    return $factory->create([
+        'organization_id' => $organization->getKey(),
+        'quantity' => 45,
+        'unit_amount' => 80,
+    ]);
+}
+
+/**
+ * 代表経路のデータセット。**なぜこれが分岐集合を代表するか**をキー名に残す。
+ *
+ * 各行: [応答列を返す closure, 期待呼び出し回数, 期待 terminal status]
+ * ★呼び出し回数だけでなく **経路が意図どおり終端したこと**も検証する
+ *   (途中で早期 return して「呼び出しが少ないから green」になるのを防ぐ)。
+ */
+dataset('auto-recharge の外部呼び出し経路', [
+    '成功 (customer 既存 = retrieve → invoice → item → finalize → pay の基準経路)' => [
+        fn (): array => [
+            stripeBudgetCustomer(),
+            stripeBudgetInvoice('draft'),
+            stripeBudgetInvoiceItem(),
+            stripeBudgetInvoice('open'),
+            stripeBudgetInvoice('paid', 3_600, 3_600),
+        ],
+        false,
+        5,
+        AutoRechargeAttemptStatus::Paid,
+    ],
+    'カード拒否 → invoice void (後始末の追加呼び出しが載る最長経路)' => [
+        fn (): array => [
+            stripeBudgetCustomer(),
+            stripeBudgetInvoice('draft'),
+            stripeBudgetInvoiceItem(),
+            stripeBudgetInvoice('open'),
+            stripeBudgetCardDeclined(),
+            stripeBudgetInvoice('open'),   // terminateInvoice: retrieve
+            stripeBudgetInvoice('void'),   // terminateInvoice: voidInvoice
+        ],
+        false,
+        7,
+        AutoRechargeAttemptStatus::Failed,
+    ],
+    '既存 invoice の再利用 (finalize 済みで InvalidRequest → pay へ進む経路)' => [
+        fn (): array => [
+            stripeBudgetAlreadyFinalized(),
+            stripeBudgetInvoice('paid', 3_600, 3_600),
+        ],
+        true,
+        2,
+        AutoRechargeAttemptStatus::Paid,
+    ],
+]);
+
+test(
+    '既定接続の Stripe 呼び出しは予算を超えない',
+    /**
+     * @param  callable(): list<array{status: int, body: string}>  $responses
+     */
+    function (
+        callable $responses,
+        bool $withInvoice,
+        int $expectedCalls,
+        AutoRechargeAttemptStatus $expectedStatus,
+    ): void {
+        // ApiRequestor::httpClient() は遅延生成のため null にならない (vendor 実査)。
+        $original = ApiRequestor::httpClient();
+        $counting = new CountingStripeHttpClient($responses());
+
+        try {
+            ApiRequestor::setHttpClient($counting);
+            // 実 Cashier クライアントを構築するため API キーが要る (送信は fake client が受ける)。
+            config(['cashier.secret' => 'sk_test_external_client_timeout_gate']);
+            // テストレーンの fake 配線 (FakeExternalsServiceProvider) が rebind しうるため、
+            // **実装へ明示的に戻す** (前提が変わっても本テストが無意味にならないようにする)。
+            $this->app->bind(AutoRechargeGatewayInterface::class, CashierAutoRechargeGateway::class);
+
+            $attempt = stripeBudgetPendingAttempt($withInvoice);
+
+            app(AutoRechargeService::class)->executeAttempt($attempt);
+
+            // ★予算の上限 (定数) を超えない
+            expect($counting->calls)->toBeLessThanOrEqual(ExternalClientTimeouts::DEFAULT_CONNECTION_STRIPE_CALL_BUDGET);
+            // ★経路ごとの厳密な回数 (増えたら気づく = ドリフト検知)
+            expect($counting->calls)->toBe($expectedCalls, implode(PHP_EOL, $counting->requestedUrls));
+            // ★空振り防止: 応答列を使い切っていること (経路が途中で終わっていない)
+            expect($counting->isExhausted())->toBeTrue('応答列を使い切っていません (経路が想定より短い)');
+            // ★経路が意図どおり終端したこと
+            expect($attempt->refresh()->status)->toBe($expectedStatus);
+        } finally {
+            ApiRequestor::setHttpClient($original);
+        }
+    },
+)->with('auto-recharge の外部呼び出し経路');
diff --git a/tests/Feature/Capture/TakeObjectStorageTest.php b/tests/Feature/Capture/TakeObjectStorageTest.php
index b5157bd..b5b549a 100644
--- a/tests/Feature/Capture/TakeObjectStorageTest.php
+++ b/tests/Feature/Capture/TakeObjectStorageTest.php
@@ -3,6 +3,8 @@
 declare(strict_types=1);
 
 use App\Services\Capture\TakeObjectStorage;
+use App\Support\ExternalClientTimeouts;
+use Aws\CommandInterface;
 use Aws\MockHandler;
 use Aws\Result;
 use Aws\S3\Exception\S3Exception;
@@ -30,6 +32,9 @@ function fakeS3DiskConfig(): void
         'use_path_style_endpoint' => true,
         'throw' => false,
         'report' => false,
+        // ★波及変更 (T126): 本 helper は disks.s3 を**丸ごと差し替える**ため、
+        //   実 config と同じ http / retries を入れないと pin の配線が素通しになる。
+        ...ExternalClientTimeouts::awsS3ClientOptions(),
     ]);
     Storage::forgetDisk('s3');
 }
@@ -42,6 +47,9 @@ function storageWithMockHandler(MockHandler $handler): TakeObjectStorage
         'version' => 'latest',
         'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
         'handler' => $handler,
+        // ★client 既定を**データ系 (900s)** にしておく。per-command 上書きの負のコントロールは
+        //   「捕捉した timeout がデータ系ではなく制御系である」ことで初めて意味を持つ。
+        ...ExternalClientTimeouts::awsS3ClientOptions(),
     ]);
 
     return new class($client) extends TakeObjectStorage
@@ -117,6 +125,44 @@ protected function client(): S3Client
     expect($command['Key'])->toBe('projects/1/manuals/2/cuts/3/takes/01TEST.mp4');
 });
 
+test('headObject は制御系の @http / @retries を per-command で積む', function (): void {
+    // web 同期経路 (テイク登録) から呼ぶ唯一の S3 ネットワーク操作。s3 disk のクライアント既定は
+    // データ系 (900s) なので、ここで制御系の帯へ絞れていることを実物で確認する。
+    fakeS3DiskConfig();
+    $captured = null;
+    $handler = new MockHandler;
+    $handler->append(function (CommandInterface $command) use (&$captured): Result {
+        $captured = ['@http' => $command['@http'], '@retries' => $command['@retries']];
+
+        return new Result(['ContentLength' => 1, 'ContentType' => 'video/mp4']);
+    });
+
+    storageWithMockHandler($handler)->headObject('projects/1/manuals/2/cuts/3/takes/01TEST.mp4');
+
+    expect($captured)->not->toBeNull();
+    // ★SDK が既定で足す他キー (decode_content 等) には触れず、pin した 2 キーだけを固定する。
+    expect($captured['@http']['connect_timeout'])->toBe(ExternalClientTimeouts::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS);
+    expect($captured['@http']['timeout'])->toBe(ExternalClientTimeouts::AWS_CONTROL_TIMEOUT_SECONDS);
+    expect($captured['@retries'])->toBe(ExternalClientTimeouts::AWS_CONTROL_PLANE_RETRIES);
+});
+
+test('負のコントロール: headObject の @http は s3 disk の既定 (データ系) を上書きする', function (): void {
+    fakeS3DiskConfig();
+    $captured = null;
+    $handler = new MockHandler;
+    $handler->append(function (CommandInterface $command) use (&$captured): Result {
+        $captured = $command['@http'];
+
+        return new Result(['ContentLength' => 1]);
+    });
+
+    storageWithMockHandler($handler)->headObject('projects/1/manuals/2/cuts/3/takes/01TEST.mp4');
+
+    // per-command 上書きが実は効いていないのに green、を防ぐ。
+    expect($captured['timeout'])->not->toBe(ExternalClientTimeouts::AWS_S3_TIMEOUT_SECONDS);
+    expect($captured['connect_timeout'])->not->toBe(ExternalClientTimeouts::AWS_S3_CONNECT_TIMEOUT_SECONDS);
+});
+
 test('headObject はオブジェクト不存在 (404) で null を返す (PUT 未完了)', function (): void {
     fakeS3DiskConfig();
     $handler = new MockHandler;
diff --git a/tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php b/tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php
new file mode 100644
index 0000000..1883199
--- /dev/null
+++ b/tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php
@@ -0,0 +1,120 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Capture\ObjectMetadataData;
+use App\DataTransferObjects\Capture\PresignedUploadData;
+use App\DataTransferObjects\Capture\UploadTicketClaims;
+use App\Enums\Storage\S3OperationSurface;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\TakeUploadReservation;
+use App\Models\VideoManual;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Capture\UploadTicketCodec;
+use Carbon\CarbonImmutable;
+use Tests\Support\Storage\S3SurfaceInventory;
+
+/*
+ * 撮影テイク登録の web 経路が `Bulk` 面の S3 操作を呼ばないことを固定する (T126 施策 7)。
+ *
+ * ★「Bulk を web から呼ばない」は**規約であって機械証明ではない** (呼び出しグラフ解析が要る)。
+ *   **既存の web 経路については behavioral に固定する**、が本テストの位置づけである。
+ * ★**保証範囲を誇張しない**: 固定するのは**登録成功パス**である。
+ *   三点照合の不一致など**異常系では `delete()` (Bulk 面) を意図的に呼ぶ**
+ *   (置かれた不正オブジェクトの後始末)。これは「失敗を返す側」なので web の待ちを
+ *   引き延ばす主経路にはならない、という判断であり、本テストはその判断を覆さない。
+ */
+
+test('テイク登録エンドポイントは BoundedControl / NoObjectRequest 面しか呼ばない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $reservation = TakeUploadReservation::factory()->forCut($cut)->create();
+    $reservation->refresh(); // DB 保存後の秒精度 expires_at で claims を作る
+    $ticket = app(UploadTicketCodec::class)->seal(UploadTicketClaims::fromReservation($reservation));
+
+    $spy = new class extends TakeObjectStorage
+    {
+        /** @var list<string> 呼び出し順を保つ (意図しない追加呼び出しの診断用) */
+        public array $calls = [];
+
+        public ?ObjectMetadataData $headResult = null;
+
+        public function presignUpload(string $path, string $contentType, int $sizeBytes, string $checksumSha256, CarbonImmutable $expiresAt): PresignedUploadData
+        {
+            $this->calls[] = __FUNCTION__;
+
+            return new PresignedUploadData(url: 'https://spy.invalid/put', headers: [], expiresAt: $expiresAt);
+        }
+
+        public function headObject(string $path): ?ObjectMetadataData
+        {
+            $this->calls[] = __FUNCTION__;
+
+            return $this->headResult;
+        }
+
+        public function temporaryPlaybackUrl(string $path): string
+        {
+            $this->calls[] = __FUNCTION__;
+
+            return 'https://spy.invalid/get';
+        }
+
+        public function delete(string $path): void
+        {
+            $this->calls[] = __FUNCTION__;
+        }
+
+        public function exists(string $path): bool
+        {
+            $this->calls[] = __FUNCTION__;
+
+            return true;
+        }
+    };
+    $spy->headResult = new ObjectMetadataData(
+        contentLength: $reservation->size_bytes,
+        contentType: $reservation->content_type,
+        checksumSha256: $reservation->checksum_sha256,
+    );
+    $this->app->instance(TakeObjectStorage::class, $spy);
+
+    $response = $this->actingAs($owner)->postJson(
+        "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes",
+        [
+            'ticket' => $ticket,
+            'client_take_id' => $reservation->client_take_id,
+            'duration_ms' => 5_000,
+            'captured_at' => now()->toIso8601String(),
+        ],
+    );
+
+    $response->assertCreated();
+
+    // ★spy の脆さ対策: 親に public method が増えたら気づく (未 override があれば fail)。
+    //   interface 抽出は本タスクの目的 (timeout の有限化) と無関係なので今回は行わない
+    //   (AGENTS.md 思考原則 2)。代わりに「取りこぼしを検出する」側で担保する。
+    $inventoryMethods = array_keys(S3SurfaceInventory::all()[TakeObjectStorage::class]);
+    $overridden = [];
+    foreach ((new ReflectionClass($spy))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
+        if ($method->getDeclaringClass()->getName() !== TakeObjectStorage::class) {
+            $overridden[] = $method->getName();
+        }
+    }
+    expect(array_values(array_diff($inventoryMethods, $overridden)))->toBe(
+        [],
+        'spy が override していない public method がある (親にメソッドが増えた可能性)',
+    );
+
+    $bulkMethods = S3SurfaceInventory::methodsWithSurface(TakeObjectStorage::class, S3OperationSurface::Bulk);
+
+    expect($bulkMethods)->not->toBeEmpty();  // 目録側の空振り防止
+    expect($spy->calls)->not->toBeEmpty();   // 呼び出し記録の空振り防止
+    expect(array_values(array_intersect($spy->calls, $bulkMethods)))->toBe(
+        [],
+        'テイク登録の web 同期経路が Bulk 面の S3 操作を呼びました (呼び出し順: '.implode(', ', $spy->calls).')',
+    );
+});
diff --git a/tests/Feature/Providers/ExternalClientTimeoutServiceProviderTest.php b/tests/Feature/Providers/ExternalClientTimeoutServiceProviderTest.php
new file mode 100644
index 0000000..f1b4456
--- /dev/null
+++ b/tests/Feature/Providers/ExternalClientTimeoutServiceProviderTest.php
@@ -0,0 +1,62 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Providers\ExternalClientTimeoutServiceProvider;
+use App\Support\ExternalClientTimeouts;
+use Stripe\ApiRequestor;
+use Stripe\HttpClient\CurlClient;
+use Stripe\Stripe;
+
+/*
+ * Stripe SDK のプロセス大域 timeout pin (T126 施策 2)。
+ *
+ * ★**既知の初期状態から検証する**。ambient state (別テストが既に pin 済み) のままだと
+ *   provider が何もしなくても green になる = 偽グリーンになるため、
+ *   毎回「pin されていない状態」へ戻してから boot() を再実行する。
+ * ★退避直後に try を開き、assert 失敗時も finally で必ず復元する
+ *   (プロセス大域状態を他テストへ漏らさない)。
+ * ★Http::fake() は不要 (このテストは 1 バイトも送信しない)。
+ */
+
+test('Stripe HTTP client の timeout / connect_timeout / max_network_retries が pin 値になる', function (): void {
+    // ApiRequestor::httpClient() は `if (!self::$_httpClient) { … CurlClient::instance() }` の
+    // 遅延生成のため null を返さない (vendor 実査)。setHttpClient() も nullable を受けない。
+    $originalClient = ApiRequestor::httpClient();
+    $originalRetries = Stripe::getMaxNetworkRetries();
+
+    try {
+        // 既知の「pin されていない」状態へ戻す
+        ApiRequestor::setHttpClient(new CurlClient);
+        Stripe::setMaxNetworkRetries(7);
+
+        (new ExternalClientTimeoutServiceProvider($this->app))->boot();
+
+        $client = ApiRequestor::httpClient();
+        expect($client)->toBeInstanceOf(CurlClient::class);
+        expect($client->getTimeout())->toBe(ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS);
+        expect($client->getConnectTimeout())->toBe(ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS);
+        expect(Stripe::getMaxNetworkRetries())->toBe(ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES);
+    } finally {
+        ApiRequestor::setHttpClient($originalClient);
+        Stripe::setMaxNetworkRetries($originalRetries);
+    }
+});
+
+test('負のコントロール: pin されていない CurlClient は SDK 既定値を返す', function (): void {
+    // 上のテストが「何もしなくても green」ではないことを示す。
+    $unpinned = new CurlClient;
+
+    expect($unpinned->getTimeout())->toBe(CurlClient::DEFAULT_TIMEOUT);
+    expect($unpinned->getConnectTimeout())->toBe(CurlClient::DEFAULT_CONNECT_TIMEOUT);
+    expect($unpinned->getTimeout())->not->toBe(ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS);
+    expect($unpinned->getConnectTimeout())->not->toBe(ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS);
+});
+
+test('provider が bootstrap/providers.php に登録されている', function (): void {
+    // 登録漏れは「本番だけ pin されない」= 最悪の偽グリーンになるため機械で固定する。
+    $providers = require base_path('bootstrap/providers.php');
+
+    expect($providers)->toBeArray();
+    expect($providers)->toContain(ExternalClientTimeoutServiceProvider::class);
+});
diff --git a/tests/Support/Billing/CountingStripeHttpClient.php b/tests/Support/Billing/CountingStripeHttpClient.php
new file mode 100644
index 0000000..49f6ae3
--- /dev/null
+++ b/tests/Support/Billing/CountingStripeHttpClient.php
@@ -0,0 +1,69 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use Stripe\HttpClient\ClientInterface;
+use Webmozart\Assert\Assert; // ★import 必須 (無いと Tests\Support\Billing\Assert に解決される)
+
+/**
+ * Stripe SDK の HTTP 呼び出し回数を数える fake client (**送信しない**)。
+ *
+ * ★`ApiRequestor::setHttpClient()` は Stripe SDK 公式の差し込み口である。
+ *   Cashier 内部 (`createOrGetStripeCustomer` 等) の呼び出しもここを通るため、
+ *   静的な呼び出し site 計数では数えられない分まで含めて数えられる。
+ * ★外部 HTTP は一切発生しない (AGENTS.md の egress 規約に抵触しない)。
+ * ★`Stripe\HttpClient\ClientInterface` は **generic ではない**ため `@implements` は書かない
+ *   (PHPStan で不正な PHPDoc になる)。型の情報は `request()` の `@param` / `@return` で与える。
+ */
+final class CountingStripeHttpClient implements ClientInterface
+{
+    public int $calls = 0;
+
+    /** @var list<array{status: int, body: string}> 先頭から消費する応答列 */
+    private array $responses;
+
+    /** @var list<string> 診断用の呼び出し URL 履歴 (経路のずれを読めるようにする) */
+    public array $requestedUrls = [];
+
+    /** @param list<array{status: int, body: string}> $responses */
+    public function __construct(array $responses)
+    {
+        $this->responses = $responses;
+    }
+
+    /** 応答列を使い切ったか (使い切っていなければ経路が想定より短い = 偽グリーン) */
+    public function isExhausted(): bool
+    {
+        return $this->responses === [];
+    }
+
+    /**
+     * vendor の `Stripe\HttpClient\ClientInterface::request()` に型宣言が無いため、
+     * **全引数に `@param` を付けて** PHPStan level 10 で mixed が伝播しないようにする。
+     *
+     * @param  'delete'|'get'|'post'  $method
+     * @param  string  $absUrl
+     * @param  array<int, string>  $headers
+     * @param  array<string, mixed>|string  $params
+     * @param  bool  $hasFile
+     * @param  'v1'|'v2'  $apiMode
+     * @param  int|null  $maxNetworkRetries
+     * @return array{0: string, 1: int, 2: array<string, list<string>>}
+     */
+    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
+    {
+        $this->calls++;
+        $this->requestedUrls[] = $method.' '.$absUrl;
+        $response = array_shift($this->responses);
+        // 応答列が尽きたら fail-loud (黙って空 body を返さない)
+        Assert::isArray(
+            $response,
+            'CountingStripeHttpClient: 想定より多い Stripe 呼び出しが発生しました ('
+            .implode(' / ', $this->requestedUrls).')',
+        );
+
+        return [$response['body'], $response['status'], []];
+    }
+}
diff --git a/tests/Support/ExternalClientBoundaryScanner.php b/tests/Support/ExternalClientBoundaryScanner.php
new file mode 100644
index 0000000..64cded5
--- /dev/null
+++ b/tests/Support/ExternalClientBoundaryScanner.php
@@ -0,0 +1,608 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+/**
+ * 「AWS SDK / Flysystem (= 外部ストレージ client) へ到達しうる site」と
+ * 「Stripe SDK のプロセス大域 setter を呼ぶ site」を PHP ソースから静的に走査する純関数群。
+ *
+ * ★走査は `PhpTokenScan::normalize()` (空白 / コメント / DocComment 除去) の結果に対して行う。
+ *   `T_CONSTANT_ENCAPSED_STRING` の中身は名前解決の対象にしない
+ *   (コメント・文字列中の `Aws\` を拾わない = 偽陽性の排除)。
+ *
+ * ★**保証範囲を誇張しない**: 検出できるのは「型/クラス名の参照」「`disk()` / `getClient()` の
+ *   呼び出し」「Stripe 大域 setter の呼び出し」だけである。文字列キーの container 解決だけで
+ *   これらの token をまったく出さない経路 (`app('filesystem')` の戻りを別メソッドへ渡す等) は
+ *   **検出できない**。この非対称は docs/architecture.md §外部 SDK の待ち上限の規約に明記する。
+ *
+ * ★**R1 (`use` import) は site ではない**。PHP の `use` はクラス本体の外 (ファイルスコープ) に
+ *   書かれるため、これを実行 site として扱うと正規の import を持つ全ファイルが違反になる。
+ *   R1 は **alias マップの構築にのみ使い、母集団へは登録しない**。
+ */
+final class ExternalClientBoundaryScanner
+{
+    /** 到達境界とみなす名前空間の接頭辞。 */
+    private const array TARGET_PREFIXES = [
+        'Aws\\',
+        'League\\Flysystem\\',
+        'Illuminate\\Filesystem\\',
+    ];
+
+    /** 到達境界とみなすクラス名 (完全一致)。 */
+    private const array TARGET_EXACT = [
+        'Illuminate\\Support\\Facades\\Storage',
+        'Illuminate\\Container\\Attributes\\Storage',
+        'Illuminate\\Contracts\\Filesystem\\Filesystem',
+    ];
+
+    /** Stripe のプロセス大域状態を触るシンボル (静的呼び出しで検出する)。 */
+    public const array STRIPE_GLOBAL_SYMBOLS = ['setHttpClient', 'setMaxNetworkRetries', 'instance'];
+
+    /**
+     * 到達境界の site を走査する (R2〜R6 の全規則)。
+     *
+     * ★Stripe の大域 setter (R6) も **到達境界**である (外部 SDK のプロセス大域状態へ触る)。
+     *   目録の対称差検査から外すと pin provider が「残骸」として検出されてしまう。
+     *
+     * @return list<array{
+     *     path: string,
+     *     line: int,
+     *     rule: string,
+     *     name: string,
+     *     scopeKind: ScanScopeKind,
+     *     class: string|null,
+     *     callable: string|null,
+     *     diskArgument: 'none'|'static'|'dynamic'|null,
+     * }>
+     */
+    public static function boundarySites(string $relativePath, string $phpSource): array
+    {
+        return self::scan($relativePath, $phpSource);
+    }
+
+    /**
+     * Stripe 大域 setter の site を走査する。
+     *
+     * @return list<array{
+     *     path: string,
+     *     line: int,
+     *     rule: string,
+     *     name: string,
+     *     scopeKind: ScanScopeKind,
+     *     class: string|null,
+     *     callable: string|null,
+     *     diskArgument: 'none'|'static'|'dynamic'|null,
+     * }>
+     */
+    public static function stripeGlobalSites(string $relativePath, string $phpSource): array
+    {
+        return array_values(array_filter(
+            self::scan($relativePath, $phpSource),
+            static fn (array $site): bool => $site['rule'] === 'stripe_global_setter',
+        ));
+    }
+
+    /**
+     * 全規則の site を 1 パスで走査する。
+     *
+     * @return list<array{
+     *     path: string,
+     *     line: int,
+     *     rule: string,
+     *     name: string,
+     *     scopeKind: ScanScopeKind,
+     *     class: string|null,
+     *     callable: string|null,
+     *     diskArgument: 'none'|'static'|'dynamic'|null,
+     * }>
+     */
+    public static function scan(string $relativePath, string $phpSource): array
+    {
+        $tokens = PhpTokenScan::normalize($phpSource);
+        $count = count($tokens);
+
+        $namespace = '';
+        /** @var array<string, string> $aliases short name (小文字) => FQCN */
+        $aliases = [];
+
+        $braceDepth = 0;
+        /** @var list<array{kind: ScanScopeKind, class: string|null, bodyDepth: int}> $scopes */
+        $scopes = [];
+        /** @var array{kind: ScanScopeKind, class: string|null}|null $pendingScope */
+        $pendingScope = null;
+        /** @var list<array{name: string, bodyDepth: int}> $callables */
+        $callables = [];
+        $pendingCallable = null;
+
+        $sites = [];
+
+        for ($i = 0; $i < $count; $i++) {
+            $token = $tokens[$i];
+            $id = $token['id'];
+            $text = $token['text'];
+
+            // --- namespace 宣言 ---
+            if ($id === T_NAMESPACE) {
+                $next = $tokens[$i + 1] ?? null;
+                if ($next !== null && ($next['id'] === T_NAME_QUALIFIED || $next['id'] === T_STRING)) {
+                    $namespace = $next['text'];
+                    $i++;
+                }
+
+                continue;
+            }
+
+            // --- R1: use import (alias マップ構築専用。母集団へ登録しない) ---
+            if ($id === T_USE) {
+                $next = $tokens[$i + 1] ?? null;
+                // closure の `use ($x)` は import ではない
+                if ($next !== null && $next['text'] === '(') {
+                    continue;
+                }
+                $i = self::collectUseStatement($tokens, $i, $aliases);
+
+                continue;
+            }
+
+            // --- クラス様宣言 (次の `{` で scope を push する) ---
+            if ($id === T_CLASS || $id === T_TRAIT || $id === T_INTERFACE || $id === T_ENUM) {
+                $previous = $tokens[$i - 1] ?? null;
+                if ($previous !== null && $previous['id'] === T_DOUBLE_COLON) {
+                    continue; // `Foo::class`
+                }
+
+                $next = $tokens[$i + 1] ?? null;
+                $isNamed = $next !== null && $next['id'] === T_STRING;
+                $pendingScope = [
+                    'kind' => $isNamed ? ScanScopeKind::NamedClass : ScanScopeKind::AnonymousClass,
+                    'class' => $isNamed && $next !== null
+                        ? ($namespace === '' ? $next['text'] : $namespace.'\\'.$next['text'])
+                        : null,
+                ];
+
+                continue;
+            }
+
+            // --- 関数 / メソッド宣言 (診断用の callable 名) ---
+            if ($id === T_FUNCTION) {
+                $next = $tokens[$i + 1] ?? null;
+                $name = $next !== null && $next['id'] === T_STRING ? $next['text'] : '{closure}';
+                $pendingCallable = $name;
+
+                continue;
+            }
+
+            // --- 文字列補間の `{$x}` / `${x}` ---
+            // ★閉じ `}` は**単一文字トークン**として現れるため、開き側を depth に数えないと
+            //   brace が片側だけ減り、以降の site が誤って FileScope 帰属になる (実測で発覚)。
+            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
+                $braceDepth++;
+
+                continue;
+            }
+
+            // --- brace の出入りで scope を push / pop ---
+            if ($id === null && $text === '{') {
+                $braceDepth++;
+                if ($pendingScope !== null) {
+                    $scopes[] = ['kind' => $pendingScope['kind'], 'class' => $pendingScope['class'], 'bodyDepth' => $braceDepth];
+                    $pendingScope = null;
+                } elseif ($pendingCallable !== null) {
+                    $callables[] = ['name' => $pendingCallable, 'bodyDepth' => $braceDepth];
+                    $pendingCallable = null;
+                }
+
+                continue;
+            }
+
+            if ($id === null && $text === '}') {
+                $top = $scopes === [] ? null : $scopes[count($scopes) - 1];
+                if ($top !== null && $top['bodyDepth'] === $braceDepth) {
+                    array_pop($scopes);
+                }
+                $topCallable = $callables === [] ? null : $callables[count($callables) - 1];
+                if ($topCallable !== null && $topCallable['bodyDepth'] === $braceDepth) {
+                    array_pop($callables);
+                }
+                $braceDepth--;
+
+                continue;
+            }
+
+            // 宣言だけで本体が無い (interface / abstract メソッド) の取りこぼしを残さない
+            if ($id === null && $text === ';') {
+                $pendingCallable = null;
+                $pendingScope = null;
+
+                continue;
+            }
+
+            $scopeKind = $scopes === [] ? ScanScopeKind::FileScope : $scopes[count($scopes) - 1]['kind'];
+            $scopeClass = $scopes === [] ? null : $scopes[count($scopes) - 1]['class'];
+            $callableName = $callables === [] ? null : $callables[count($callables) - 1]['name'];
+
+            // --- R2: 完全修飾 / 修飾名による参照 ---
+            if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED) {
+                if (self::isTargetName($text)) {
+                    $sites[] = self::site($relativePath, $token['line'], 'fqn_reference', ltrim($text, '\\'), $scopeKind, $scopeClass, $callableName, null);
+                }
+
+                continue;
+            }
+
+            if ($id !== T_STRING) {
+                continue;
+            }
+
+            $previous = $tokens[$i - 1] ?? null;
+            $previousId = $previous['id'] ?? null;
+            $isMemberAccess = $previousId === T_OBJECT_OPERATOR || $previousId === T_NULLSAFE_OBJECT_OPERATOR;
+            $isStaticAccess = $previousId === T_DOUBLE_COLON;
+            $next = $tokens[$i + 1] ?? null;
+            $isCall = $next !== null && $next['id'] === null && $next['text'] === '(';
+
+            // --- R4: disk() 呼び出し (receiver を問わない) ---
+            if ($text === 'disk' && ($isMemberAccess || $isStaticAccess) && $isCall) {
+                $sites[] = self::site(
+                    $relativePath,
+                    $token['line'],
+                    'disk_call',
+                    'disk',
+                    $scopeKind,
+                    $scopeClass,
+                    $callableName,
+                    self::classifyCallArgument($tokens, $i + 1),
+                );
+
+                continue;
+            }
+
+            // --- R5: getClient() 呼び出し (receiver を問わない) ---
+            if ($text === 'getClient' && ($isMemberAccess || $isStaticAccess) && $isCall) {
+                $sites[] = self::site($relativePath, $token['line'], 'get_client_call', 'getClient', $scopeKind, $scopeClass, $callableName, null);
+
+                continue;
+            }
+
+            // --- R6: Stripe のプロセス大域 setter ---
+            if (in_array($text, self::STRIPE_GLOBAL_SYMBOLS, true) && $isStaticAccess && $isCall) {
+                $receiver = $tokens[$i - 2] ?? null;
+                $receiverName = $receiver === null ? null : self::resolveName($receiver, $aliases);
+                if ($receiverName !== null && str_starts_with($receiverName, 'Stripe\\')) {
+                    $sites[] = self::site($relativePath, $token['line'], 'stripe_global_setter', $text, $scopeKind, $scopeClass, $callableName, null);
+
+                    continue;
+                }
+            }
+
+            // --- R3: import 済み short name による参照 (型宣言 / new / ::class / instanceof を含む) ---
+            if ($isMemberAccess || $isStaticAccess) {
+                continue; // メソッド名 / 定数名であってクラス参照ではない
+            }
+            if ($previousId === T_FUNCTION || $previousId === T_CONST || $previousId === T_CLASS
+                || $previousId === T_INTERFACE || $previousId === T_TRAIT || $previousId === T_ENUM
+                || $previousId === T_AS || $previousId === T_GOTO) {
+                continue; // 宣言名であって参照ではない
+            }
+            $resolved = $aliases[mb_strtolower($text)] ?? null;
+            if ($resolved !== null && self::isTargetName($resolved)) {
+                $sites[] = self::site($relativePath, $token['line'], 'imported_name_reference', $resolved, $scopeKind, $scopeClass, $callableName, null);
+            }
+        }
+
+        return self::dropOrphanGetClientSites($sites);
+    }
+
+    /**
+     * `getClient()` は receiver を問わず拾う (`app('filesystem')->disk('s3')->getClient()` を
+     * 逃さないため) 一方で、**名前が同じだけの無関係な API** (OAuth の
+     * `AuthCodeEntity::getClient()` 等) まで母集団へ入れると目録が意味を失う。
+     *
+     * そこで `get_client_call` は「**同じファイルに到達境界の名前参照または `disk()` 呼び出しがある**」
+     * ことを条件に登録する。到達境界に触れているファイル内の client 取り出しだけを見る。
+     *
+     * @param  list<array{path: string, line: int, rule: string, name: string, scopeKind: ScanScopeKind, class: string|null, callable: string|null, diskArgument: 'none'|'static'|'dynamic'|null}>  $sites
+     * @return list<array{path: string, line: int, rule: string, name: string, scopeKind: ScanScopeKind, class: string|null, callable: string|null, diskArgument: 'none'|'static'|'dynamic'|null}>
+     */
+    private static function dropOrphanGetClientSites(array $sites): array
+    {
+        $hasBoundaryReference = false;
+        foreach ($sites as $site) {
+            if (in_array($site['rule'], ['fqn_reference', 'imported_name_reference', 'disk_call'], true)) {
+                $hasBoundaryReference = true;
+
+                break;
+            }
+        }
+
+        if ($hasBoundaryReference) {
+            return $sites;
+        }
+
+        return array_values(array_filter(
+            $sites,
+            static fn (array $site): bool => $site['rule'] !== 'get_client_call',
+        ));
+    }
+
+    /**
+     * `use` 文を読み進めて alias マップへ登録し、`;` の添字を返す。
+     *
+     * `use function` / `use const` は名前解決の対象外 (クラス参照ではない)。
+     * グループ use (`use Aws\{S3\S3Client, Sns\SnsClient};`) にも対応する。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<string, string>  $aliases
+     */
+    private static function collectUseStatement(array $tokens, int $useIndex, array &$aliases): int
+    {
+        $count = count($tokens);
+        $i = $useIndex + 1;
+
+        if (($tokens[$i]['id'] ?? null) === T_FUNCTION || ($tokens[$i]['id'] ?? null) === T_CONST) {
+            // 関数 / 定数の import。`;` まで読み飛ばす
+            while ($i < $count && ! ($tokens[$i]['id'] === null && $tokens[$i]['text'] === ';')) {
+                $i++;
+            }
+
+            return $i;
+        }
+
+        $prefix = '';
+        $current = '';
+        $alias = null;
+        $expectAlias = false;
+
+        for (; $i < $count; $i++) {
+            $token = $tokens[$i];
+            $id = $token['id'];
+            $text = $token['text'];
+
+            if ($id === null && ($text === ';' || $text === '{' || $text === '}' || $text === ',')) {
+                if ($current !== '') {
+                    $fqcn = ltrim($prefix.$current, '\\');
+                    $short = $alias ?? self::shortName($fqcn);
+                    $aliases[mb_strtolower($short)] = $fqcn;
+                }
+                $current = '';
+                $alias = null;
+                $expectAlias = false;
+
+                if ($text === '{') {
+                    // グループ use: 直前までの名前が接頭辞になる
+                    $prefix = '';
+                    // `{` の直前に確定させた current を接頭辞へ戻す必要があるため再構築する
+                    $prefix = self::groupPrefix($tokens, $useIndex, $i);
+
+                    continue;
+                }
+
+                if ($text === ';') {
+                    return $i;
+                }
+
+                continue;
+            }
+
+            if ($id === T_AS) {
+                $expectAlias = true;
+
+                continue;
+            }
+
+            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
+                if ($expectAlias) {
+                    $alias = $text;
+
+                    continue;
+                }
+                $current .= $text;
+
+                continue;
+            }
+        }
+
+        return $count - 1;
+    }
+
+    /**
+     * グループ use の接頭辞 (`use Aws\{...}` の `Aws\`) を組み立てる。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function groupPrefix(array $tokens, int $useIndex, int $braceIndex): string
+    {
+        $prefix = '';
+        for ($i = $useIndex + 1; $i < $braceIndex; $i++) {
+            $id = $tokens[$i]['id'];
+            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
+                $prefix .= $tokens[$i]['text'];
+            }
+        }
+
+        return ltrim($prefix, '\\');
+    }
+
+    /**
+     * 呼び出しの引数が「静的に決まる disk 名」か判定する。
+     *
+     * - `none`: 引数なし (`$this->disk()` のような集約 accessor)
+     * - `static`: 文字列リテラル 1 個、またはクラス定数参照 1 個 (`FakeObjectStore::DISK` / `self::DISK`)
+     * - `dynamic`: 変数を含む等、静的に決められない
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  int  $openParenIndex  `(` の添字
+     * @return 'none'|'static'|'dynamic'
+     */
+    private static function classifyCallArgument(array $tokens, int $openParenIndex): string
+    {
+        $count = count($tokens);
+        $depth = 0;
+        /** @var list<array{id: int|null, text: string, line: int}> $inner */
+        $inner = [];
+
+        for ($i = $openParenIndex; $i < $count; $i++) {
+            $token = $tokens[$i];
+            if ($token['id'] === null && $token['text'] === '(') {
+                $depth++;
+                if ($depth === 1) {
+                    continue;
+                }
+            }
+            if ($token['id'] === null && $token['text'] === ')') {
+                $depth--;
+                if ($depth === 0) {
+                    break;
+                }
+            }
+            if ($depth >= 1) {
+                $inner[] = $token;
+            }
+        }
+
+        if ($inner === []) {
+            return 'none';
+        }
+
+        if (count($inner) === 1 && $inner[0]['id'] === T_CONSTANT_ENCAPSED_STRING) {
+            return 'static';
+        }
+
+        // クラス定数参照 (`Foo::BAR` / `self::BAR` / `static::BAR`) — 静的に確定する
+        if (count($inner) === 3 && $inner[1]['id'] === T_DOUBLE_COLON && $inner[2]['id'] === T_STRING) {
+            $head = $inner[0]['id'];
+            if ($head === T_STRING || $head === T_NAME_QUALIFIED || $head === T_NAME_FULLY_QUALIFIED || $head === T_STATIC) {
+                return 'static';
+            }
+        }
+
+        return 'dynamic';
+    }
+
+    /**
+     * トークンをクラス名 (FQCN) として解決する。解決できなければ null。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     * @param  array<string, string>  $aliases
+     */
+    private static function resolveName(array $token, array $aliases): ?string
+    {
+        $id = $token['id'];
+        if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED) {
+            return ltrim($token['text'], '\\');
+        }
+        if ($id === T_STRING) {
+            return $aliases[mb_strtolower($token['text'])] ?? null;
+        }
+
+        return null;
+    }
+
+    /** 到達境界の対象名か。 */
+    private static function isTargetName(string $name): bool
+    {
+        $normalized = ltrim($name, '\\');
+
+        if (in_array($normalized, self::TARGET_EXACT, true)) {
+            return true;
+        }
+
+        foreach (self::TARGET_PREFIXES as $prefix) {
+            if (str_starts_with($normalized, $prefix)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    private static function shortName(string $fqcn): string
+    {
+        $position = strrpos($fqcn, '\\');
+
+        return $position === false ? $fqcn : substr($fqcn, $position + 1);
+    }
+
+    /**
+     * @param  'none'|'static'|'dynamic'|null  $diskArgument
+     * @return array{
+     *     path: string,
+     *     line: int,
+     *     rule: string,
+     *     name: string,
+     *     scopeKind: ScanScopeKind,
+     *     class: string|null,
+     *     callable: string|null,
+     *     diskArgument: 'none'|'static'|'dynamic'|null,
+     * }
+     */
+    private static function site(
+        string $path,
+        int $line,
+        string $rule,
+        string $name,
+        ScanScopeKind $scopeKind,
+        ?string $class,
+        ?string $callable,
+        ?string $diskArgument,
+    ): array {
+        return [
+            'path' => $path,
+            'line' => $line,
+            'rule' => $rule,
+            'name' => $name,
+            'scopeKind' => $scopeKind,
+            'class' => $class,
+            'callable' => $callable,
+            'diskArgument' => $diskArgument,
+        ];
+    }
+
+    /**
+     * 失敗メッセージ用の 1 行。「なぜ母集団に入ったのか」が読める形にする。
+     *
+     * @param  array{path: string, line: int, rule: string, name: string, scopeKind: ScanScopeKind, class: string|null, callable: string|null, diskArgument: 'none'|'static'|'dynamic'|null}  $site
+     */
+    public static function describe(array $site): string
+    {
+        $callable = $site['callable'] ?? '(file scope)';
+
+        return "{$site['path']}:{$site['line']} [{$site['rule']}] {$site['name']} ({$callable})";
+    }
+
+    /**
+     * ディレクトリ配下の PHP ファイルを相対パス => ソースで返す。
+     *
+     * @return array<string, string>
+     */
+    public static function phpFiles(string $absoluteRoot, string $relativeRoot): array
+    {
+        if (! is_dir($absoluteRoot)) {
+            return [];
+        }
+
+        $iterator = new \RecursiveIteratorIterator(
+            new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS),
+        );
+
+        $files = [];
+        foreach ($iterator as $file) {
+            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
+                continue;
+            }
+            $absolute = $file->getPathname();
+            $source = file_get_contents($absolute);
+            if ($source === false) {
+                continue;
+            }
+            $relative = $relativeRoot.'/'.ltrim(str_replace($absoluteRoot, '', $absolute), '/');
+            $files[$relative] = $source;
+        }
+
+        ksort($files);
+
+        return $files;
+    }
+}
diff --git a/tests/Support/PhpTokenScan.php b/tests/Support/PhpTokenScan.php
new file mode 100644
index 0000000..b947d38
--- /dev/null
+++ b/tests/Support/PhpTokenScan.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+/**
+ * PHP ソースの静的走査で共有する `token_get_all()` の正規化 (純関数)。
+ *
+ * ★同じ正規化を 2 本持たない。`QueuedJobLeaseInventoryTest` (既存) と
+ *   `ExternalClientBoundaryScanner` (T126) の両方がここを使う。
+ * ★Pest のファイルスコープ関数はテストファイル間で衝突しうるため、
+ *   `Tests\Support\QueueLeaseConfig` と同じくクラスの static メソッドへ集約する。
+ */
+final class PhpTokenScan
+{
+    /**
+     * `token_get_all()` を「空白・コメントを除いた添字連番のリスト」へ正規化する。
+     *
+     * 単一文字トークン (`{` / `}` / `;` など) は `id => null` で表現し、
+     * 行番号は直前トークンの行を引き継ぐ (単一文字トークンは行情報を持たないため)。
+     *
+     * @return list<array{id: int|null, text: string, line: int}>
+     */
+    public static function normalize(string $phpSource): array
+    {
+        $normalized = [];
+        foreach (token_get_all($phpSource) as $token) {
+            if (is_array($token)) {
+                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
+                    continue;
+                }
+                $normalized[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];
+
+                continue;
+            }
+
+            $line = $normalized === [] ? 0 : $normalized[count($normalized) - 1]['line'];
+            $normalized[] = ['id' => null, 'text' => $token, 'line' => $line];
+        }
+
+        return $normalized;
+    }
+}
diff --git a/tests/Support/ScanScopeKind.php b/tests/Support/ScanScopeKind.php
new file mode 100644
index 0000000..69beff2
--- /dev/null
+++ b/tests/Support/ScanScopeKind.php
@@ -0,0 +1,24 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+/**
+ * 静的走査で検出した site の帰属 scope 種別。
+ *
+ * ★`null` に潰さない。「クラス本体の外」と「匿名クラスの中」は違反理由が異なる
+ *   (前者はファイルスコープの抜け道、後者は目録を迂回する抜け道) ため、
+ *   3 値で保持して失敗メッセージに理由を出せるようにする。
+ */
+enum ScanScopeKind
+{
+    /** 名前付きの型宣言 (class / interface / trait / enum) の本体。 */
+    case NamedClass;
+
+    /** `new class (...) { ... }` の本体。 */
+    case AnonymousClass;
+
+    /** クラスの外 (Pest のファイルスコープ closure を含む)。 */
+    case FileScope;
+}
diff --git a/tests/Support/Storage/S3SurfaceInventory.php b/tests/Support/Storage/S3SurfaceInventory.php
new file mode 100644
index 0000000..9425b06
--- /dev/null
+++ b/tests/Support/Storage/S3SurfaceInventory.php
@@ -0,0 +1,105 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Storage;
+
+use App\Enums\Storage\S3OperationSurface;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Render\RenderObjectStorage;
+
+/**
+ * S3 集約 adapter の public メソッドの「面」分類目録の**正本**。
+ *
+ * ★グローバル定数ではなく **static メソッド**に置く。Pest の `--parallel` は
+ *   ファイル単位でプロセスを分けるため、他テストファイルの定数を参照すると未定義になりうる
+ *   (`QueuedJobLeaseInventoryTest` のコメントと同じ規律)。
+ *   Architecture テスト (`ExternalClientTimeoutInventoryTest`) と
+ *   Feature テスト (`TakeRegistrationS3SurfaceTest`) の両方がここを読む。
+ * ★配列形式は `['surface' => …, 'rationale' => …]` の**キー付きで統一**する (tuple にしない)。
+ */
+final class S3SurfaceInventory
+{
+    /**
+     * adapter の public メソッドの面分類 (deny-by-default)。
+     *
+     * @return array<class-string, array<string, array{surface: S3OperationSurface, rationale: string}>>
+     */
+    public static function all(): array
+    {
+        return [
+            TakeObjectStorage::class => [
+                'presignUpload' => [
+                    'surface' => S3OperationSurface::NoObjectRequest,
+                    'rationale' => 'presign は署名計算のみで完結し S3 のオブジェクト API へリクエストを送らない',
+                ],
+                'headObject' => [
+                    'surface' => S3OperationSurface::BoundedControl,
+                    'rationale' => 'web 同期のテイク登録から呼ぶ唯一の S3 ネットワーク操作で転送量が有界である',
+                ],
+                'temporaryPlaybackUrl' => [
+                    'surface' => S3OperationSurface::NoObjectRequest,
+                    'rationale' => '署名 URL の文字列生成のみでオブジェクト API をまったく送らない',
+                ],
+                'delete' => [
+                    'surface' => S3OperationSurface::Bulk,
+                    'rationale' => 'Flysystem 経由で per-command option を注入できない掃除ジョブ専用の操作',
+                ],
+                'exists' => [
+                    'surface' => S3OperationSurface::Bulk,
+                    'rationale' => 'Flysystem 経由で per-command option を注入できない掃除ジョブ専用の操作',
+                ],
+            ],
+            RenderObjectStorage::class => [
+                'downloadToLocal' => [
+                    'surface' => S3OperationSurface::Bulk,
+                    'rationale' => '本文転送であり所要時間がオブジェクトサイズに比例して伸びる掃除・レンダ経路専用',
+                ],
+                'upload' => [
+                    'surface' => S3OperationSurface::Bulk,
+                    'rationale' => '本文転送であり所要時間がオブジェクトサイズに比例して伸びる掃除・レンダ経路専用',
+                ],
+                'temporaryPlaybackUrl' => [
+                    'surface' => S3OperationSurface::NoObjectRequest,
+                    'rationale' => '署名 URL の文字列生成のみでオブジェクト API をまったく送らない',
+                ],
+                'temporaryDownloadUrl' => [
+                    'surface' => S3OperationSurface::NoObjectRequest,
+                    'rationale' => '署名 URL の文字列生成のみでオブジェクト API をまったく送らない',
+                ],
+                'delete' => [
+                    'surface' => S3OperationSurface::Bulk,
+                    'rationale' => 'Flysystem 経由で per-command option を注入できない掃除ジョブ専用の操作',
+                ],
+                'keyPrefixFor' => [
+                    'surface' => S3OperationSurface::NoObjectRequest,
+                    'rationale' => '文字列生成のみで AWS SDK をまったく呼び出さない純関数であり待ちが発生しない',
+                ],
+                'contentDisposition' => [
+                    'surface' => S3OperationSurface::NoObjectRequest,
+                    'rationale' => '文字列生成のみで AWS SDK をまったく呼び出さない純関数であり待ちが発生しない',
+                ],
+            ],
+        ];
+    }
+
+    /**
+     * 指定した面に属するメソッド名。
+     *
+     * @param  class-string  $class
+     * @return list<string>
+     */
+    public static function methodsWithSurface(string $class, S3OperationSurface $surface): array
+    {
+        $methods = self::all()[$class] ?? [];
+
+        $matched = [];
+        foreach ($methods as $method => $entry) {
+            if ($entry['surface'] === $surface) {
+                $matched[] = $method;
+            }
+        }
+
+        return $matched;
+    }
+}
diff --git a/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php b/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php
new file mode 100644
index 0000000..fecfa6b
--- /dev/null
+++ b/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php
@@ -0,0 +1,244 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\ExternalClientBoundaryScanner;
+use Tests\Support\ScanScopeKind;
+
+/*
+ * 到達境界 scanner の**走査精度**を fixture 文字列で固定する (T126 施策 5)。
+ *
+ * ★目録 gate 本体 (ExternalClientTimeoutInventoryTest) は「実リポジトリの母集団」を見るため、
+ *   走査ロジックの偽陽性 / 偽陰性そのものは検証できない。ここは純関数への fixture 入力で
+ *   規則ごとの検出精度を固定する。DB は触らない。
+ */
+
+/**
+ * @param  list<array{path: string, line: int, rule: string, name: string, scopeKind: ScanScopeKind, class: string|null, callable: string|null, diskArgument: 'none'|'static'|'dynamic'|null}>  $sites
+ * @return list<array{rule: string, name: string, class: string|null, scope: string}>
+ */
+function scannerSummary(array $sites): array
+{
+    return array_map(
+        static fn (array $site): array => [
+            'rule' => $site['rule'],
+            'name' => $site['name'],
+            'class' => $site['class'],
+            'scope' => $site['scopeKind']->name,
+        ],
+        $sites,
+    );
+}
+
+test('use ... as ... の alias を解決する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Aws\S3\S3Client as Bucket;
+    class Sample { public function f(): Bucket { return $this->x; } }
+    PHP;
+
+    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([
+        ['rule' => 'imported_name_reference', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
+    ]);
+});
+
+test('完全修飾名と import 済み short name の両方を検出する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Aws\Sns\SnsClient;
+    class Sample { public function f(): void { $a = \Aws\S3\S3Client::class; $b = SnsClient::class; } }
+    PHP;
+
+    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([
+        ['rule' => 'fqn_reference', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
+        ['rule' => 'imported_name_reference', 'name' => 'Aws\Sns\SnsClient', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
+    ]);
+});
+
+test('nullable / union / intersection の型宣言を検出する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Illuminate\Contracts\Filesystem\Filesystem;
+    use Aws\S3\S3Client;
+    class Sample {
+        private ?Filesystem $a = null;
+        public function f(Filesystem|string $b, S3Client&\Countable $c): Filesystem|null { return $this->a; }
+    }
+    PHP;
+
+    $names = array_column(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source), 'name');
+
+    expect($names)->toBe([
+        'Illuminate\Contracts\Filesystem\Filesystem', // property
+        'Illuminate\Contracts\Filesystem\Filesystem', // union 引数
+        'Aws\S3\S3Client',                            // intersection 引数
+        'Illuminate\Contracts\Filesystem\Filesystem', // nullable 戻り値
+    ]);
+});
+
+test('constructor property promotion と attribute の型を検出する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Illuminate\Container\Attributes\Storage;
+    use Aws\S3\S3Client;
+    class Sample {
+        public function __construct(
+            #[Storage('s3')] private readonly S3Client $client,
+        ) {}
+    }
+    PHP;
+
+    $names = array_column(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source), 'name');
+
+    expect($names)->toBe([
+        'Illuminate\Container\Attributes\Storage',
+        'Aws\S3\S3Client',
+    ]);
+});
+
+test('匿名クラス内の site は AnonymousClass 帰属として外側クラスへ誤帰属しない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Aws\S3\S3Client;
+    class Outer {
+        public function f(): object {
+            return new class { public function g(): S3Client { return $this->c; } };
+        }
+    }
+    PHP;
+
+    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Outer.php', $source)))->toBe([
+        ['rule' => 'imported_name_reference', 'name' => 'Aws\S3\S3Client', 'class' => null, 'scope' => 'AnonymousClass'],
+    ]);
+});
+
+test('コメント / 文字列リテラル中の Aws\\ を検出しない', function (): void {
+    // 偽陽性の負のコントロール。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    /** Aws\S3\S3Client のことを説明する DocComment */
+    class Sample {
+        // Aws\Sns\SnsClient を将来使うかもしれない
+        public function f(): string { return 'Aws\S3\S3Client'; }
+    }
+    PHP;
+
+    expect(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source))->toBe([]);
+});
+
+test('use Aws\\… だけがあり参照 site が無いファイルは母集団に入らない', function (): void {
+    // R1 (import) は alias マップ構築専用であり、それ自体では site にならない。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Aws\S3\S3Client;
+    class Sample { public function f(): int { return 1; } }
+    PHP;
+
+    expect(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source))->toBe([]);
+});
+
+test('1 ファイルに複数の名前付きクラスがあるとき site は実際の scope のクラスへ帰属する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Aws\S3\S3Client;
+    class First { public function f(): int { return 1; } }
+    class Second { public function g(): S3Client { return $this->c; } }
+    PHP;
+
+    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Multi.php', $source)))->toBe([
+        ['rule' => 'imported_name_reference', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Second', 'scope' => 'NamedClass'],
+    ]);
+});
+
+test('文字列補間を含むメソッドの後でも scope 追跡が壊れない', function (): void {
+    // `"{$x}"` の閉じ `}` は単一文字トークンとして現れるため、開き側 (T_CURLY_OPEN) を
+    // depth に数えないと以降の site が FileScope へ落ちる (実測で発覚した回帰)。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Illuminate\Support\Facades\Storage;
+    class Sample {
+        public function f(string $key): string { return "prefix {$key} suffix"; }
+        public function g(): void { Storage::disk('s3')->delete('x'); }
+    }
+    PHP;
+
+    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([
+        ['rule' => 'imported_name_reference', 'name' => 'Illuminate\Support\Facades\Storage', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
+        ['rule' => 'disk_call', 'name' => 'disk', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
+    ]);
+});
+
+test('disk() の引数が変数なら dynamic として分類される', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Illuminate\Support\Facades\Storage;
+    class Sample {
+        public function f(string $name): void { Storage::disk($name)->delete('x'); }
+        public function g(): void { Storage::disk('s3')->delete('x'); }
+        public function h(): void { Storage::disk(self::DISK)->delete('x'); }
+        public function i(): void { $this->disk()->delete('x'); }
+    }
+    PHP;
+
+    $arguments = array_values(array_map(
+        static fn (array $site): ?string => $site['diskArgument'],
+        array_filter(
+            ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source),
+            static fn (array $site): bool => $site['rule'] === 'disk_call',
+        ),
+    ));
+
+    expect($arguments)->toBe(['dynamic', 'static', 'static', 'none']);
+});
+
+test('getClient() は到達境界の参照が無いファイルでは母集団に入らない', function (): void {
+    // 同名の無関係な API (OAuth の AuthCodeEntity::getClient() 等) を拾わないための条件。
+    $unrelated = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    class Sample { public function f(object $entity): object { return $entity->getClient(); } }
+    PHP;
+
+    expect(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $unrelated))->toBe([]);
+
+    $related = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Illuminate\Support\Facades\Storage;
+    class Sample { public function f(): object { return Storage::disk('s3')->getClient(); } }
+    PHP;
+
+    expect(array_column(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $related), 'rule'))
+        ->toBe(['imported_name_reference', 'disk_call', 'get_client_call']);
+});
+
+test('Stripe の大域 setter は Stripe 名前空間の receiver に限って検出される', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Stripe\ApiRequestor;
+    use Stripe\Stripe;
+    use Stripe\HttpClient\CurlClient;
+    class Sample {
+        public function f(): void {
+            ApiRequestor::setHttpClient(new CurlClient);
+            Stripe::setMaxNetworkRetries(0);
+            CurlClient::instance();
+            \App\Other\Registry::instance();
+        }
+    }
+    PHP;
+
+    expect(array_column(ExternalClientBoundaryScanner::stripeGlobalSites('app/Gate/Sample.php', $source), 'name'))
+        ->toBe(['setHttpClient', 'setMaxNetworkRetries', 'instance']);
+});
diff --git a/tests/Unit/Support/Billing/GatewayFailureClassifierTest.php b/tests/Unit/Support/Billing/GatewayFailureClassifierTest.php
index 77ed2e6..d696e5e 100644
--- a/tests/Unit/Support/Billing/GatewayFailureClassifierTest.php
+++ b/tests/Unit/Support/Billing/GatewayFailureClassifierTest.php
@@ -189,3 +189,17 @@ function billingTaxonomyInstantiate(string $class): Throwable
     expect($throwable::class)->toBe('Illuminate\Contracts\Cache\LockTimeoutException');
     expect(GatewayFailureClassifier::classify($throwable))->toBe(GatewayFailureClass::LocalFailure);
 });
+
+test('Stripe の接続断/timeout は ApiConnectionException の class 分類で ProviderUnavailable になる', function (): void {
+    // ★分類は **class-based** である (message 文字列は判定に一切効かない)。
+    //   timeout らしい message を使うのは可読性のためだけ。
+    // ★cURL の timeout は ApiRequestor::handleCurlError() が ApiConnectionException へ変換する。
+    //   timeout を短く pin (T126) すると本例外の出現頻度が上がるため、分類の対応関係を固定する。
+    //   分類表 (directMap) を**変えない**という判断を CI に残すのが目的である。
+    $exception = new ApiConnectionException('Operation timed out after 20000 milliseconds');
+
+    expect(GatewayFailureClassifier::classify($exception))->toBe(GatewayFailureClass::ProviderUnavailable);
+
+    // 観測語彙は 2 キーのまま (例外 message を載せない)
+    expect(array_keys(GatewayFailureClassifier::context($exception)))->toBe(['failure_class', 'error_class']);
+});

```

---

## mutation evidence

# T126 mutation evidence — 新設 gate が「本当に効いているか」

新設 gate は**素の main では赤にならない** (実装後に green になるのが正常)。
したがって「効いている」と主張する唯一の根拠は、**意図的な退行 (mutation) を入れたときに
期待どおり赤くなる**ことの記録である。

- 実行スクリプト: `devnotes/20260807-2127-todo-T126/run-mutations.py` (一時スクリプト。恒久化しない)
- 実行方法: `cd .claude/worktrees/tasks/T126 && python3 devnotes/20260807-2127-todo-T126/run-mutations.py`
- 各 mutation は「退避 → 適用 → 対象テスト実行 → **必ず退避から復元**」を 1 セットとする
  (`try/finally` で復元するため、途中で失敗しても mutation は残らない)
- テストは `vendor/bin/pest <file>` を直接実行した。グローバルテストロックは**worktree 横断の
  直列化**用であり、ここは非 parallel かつ worktree 固有 base DB (`app_test_44b5f445`) しか
  使わないため、ロック外の単発実行で意味が変わらない。最終の全 green 確認は
  `composer test` (= ロック配下 + `--parallel`) で別途行っている
- 実行結果: **20/20 すべて RED (`ALL RED`)**
- 復元確認: 実行後に `git diff --stat` と
  `rg -n "mutationProbe|mutation probe|listObjects" app/ tests/` で残骸ゼロを確認済み

| # | mutation | 赤くなったテスト (実測) |
|---|---|---|
| 1 | `ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS` を `80` (SDK 既定) にする | `pin 値は SDK 既定値と異なる` / `時間予算: 外部予算 + 局所予算 < worker --timeout < retry_after` |
| 2 | `ExternalClientTimeouts::AWS_MAX_ATTEMPTS` を `3` (SDK 既定) にする | `pin 値は SDK 既定値と異なる` |
| 3 | `config/filesystems.php` の `...awsS3ClientOptions()` 行を削除 | `AWS config: s3 / ses が http と retries を宣言する` / `AWS behavioral: s3 disk クライアントの @http が pin 値になる` |
| 4 | `config/services.php` の `...awsControlClientOptions()` 行を削除 | `AWS config: …` / `AWS behavioral: vendor 契約: MailManager は services.ses を SesV2Client の構築引数へ素通しする` |
| 5 | `ExternalClientTimeoutServiceProvider::boot()` の中身を空にする | `Stripe HTTP client の timeout / connect_timeout / max_network_retries が pin 値になる` |
| 6 | `bootstrap/providers.php` から provider 行を削除 | `provider が bootstrap/providers.php に登録されている` |
| 7 | `TakeObjectStorage::headObject()` の `...awsControlPlaneCommandOptions()` を削除 | `headObject は制御系の @http / @retries を per-command で積む` / `負のコントロール: headObject の @http は s3 disk の既定 (データ系) を上書きする` |
| 8 | `StorageUsageService` に `Storage::disk('s3')->exists()` を 1 行足す | `到達境界: AWS / Flysystem へ到達するクラスは目録と対称差ゼロ` |
| 9 | `TakeObjectStorage` に未登録の public メソッド (`listObjects`) を足す | `面分類: adapter の public メソッドは目録と対称差ゼロ` |
| 10 | `CashierAutoRechargeGateway` に Stripe 呼び出しを 3 つ増やす | `既定接続の Stripe 呼び出しは予算を超えない` (2 データセット) |
| 11 | `config/queue.php` の `retry_after` を `280` にする (予算 290 未満) | `時間予算: 外部予算 + 局所予算 < worker --timeout < retry_after` |
| 12 | `mprocs.yaml` の `--timeout` を `360` にする | `時間予算: mprocs の database worker --timeout が定数と一致する` / 既存 `規則 1: mprocs のキューワーカーは --timeout を明示し retry_after を下回る` |
| 13 | `TakeRegistrationService` の成功パスに `$this->storage->exists()` を足す | `テイク登録エンドポイントは BoundedControl / NoObjectRequest 面しか呼ばない` |
| 14 | `AppServiceProvider` に `ApiRequestor::setHttpClient(new CurlClient)` を足す | `到達境界: Stripe の大域 setter はシンボルごとに許可箇所へ限定される` |
| 15 | `PhpTokenScan::normalize()` からコメント除去を外す | 既存 `QueuedJobLeaseInventoryTest` の `接続経路: 接続の指定は $this->onConnection('リテラル') に限る` / `接続経路: 目録の接続宣言がソースと一致する` |
| 16 | `FakeTakeObjectStorage` の目録 entry を `surface => 'adapter'` に変える | `到達境界: adapter 集合は面分類目録のクラスキーと一致する` |
| 17 | provider の `new CurlClient` を `CurlClient::instance()` に変える | `到達境界: Stripe の大域 setter …` (`CurlClient::instance` は app/ で 0 件) |
| 18 | 無関係なテスト (`tests/Unit/Architecture/…`) に `setHttpClient` を足す | `到達境界: Stripe の大域 setter …` (tests/ 側 exact-fit) |
| 19 | 許可済みテストファイル**内**に `setHttpClient` を 1 件追加する (3 件にする) | `到達境界: Stripe の大域 setter …` (**site 件数**一致。ファイル許可だけでは検出できない側) |
| 20 | `app/` の Service に匿名クラス経由の `Storage::disk('s3')` を足す | `到達境界: AWS / Flysystem へ到達するクラスは目録と対称差ゼロ` (`AnonymousClass` 帰属違反) |

## 補足 (誇張しない)

- M15 は「共通化 (`PhpTokenScan` への delegate 化) が既存 gate の振る舞いを変えていない」ことの
  **逆確認**である。コメント除去を外すと既存 `QueuedJobLeaseInventoryTest` が赤くなる
  = delegate 先が実際に既存 gate の判定を担っていることが示せた。
- M13 が固定するのは**登録成功パス**である。三点照合の不一致など異常系では
  `TakeRegistrationService` が意図的に `delete()` (Bulk 面) を呼ぶ。テスト側にもその旨を明記した。
- mutation が赤くする対象は「新設 gate だけ」ではない (M1 は 2 本、M12 は既存 gate も赤くする)。
  これは**帯の序列が複数の検査で二重化されている**ことの表れであり、意図した設計である。


---

## テスト結果

### PHP
- `composer test` (グローバルロック配下 / `--parallel`): **3728 tests, 3726 passed, 2 skipped, 0 failed**
- `composer phpstan` (level 10, 813 files): **No errors**
- `vendor/bin/pint --test`: passed / `composer fix`: passed

### JS
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: 成功
- `pnpm test`: 126 files / 1236 tests passed
- `pnpm typecheck:packages` / `pnpm build:packages`: 成功
- `pnpm test:packages`: 10 files / 106 tests passed

### 実測で確定した値 (設計の見積もりを実測で置き換えたもの)
- auto-recharge の Stripe HTTP 呼び出し回数 (`CountingStripeHttpClient` で実測):
  - 成功 (customer 既存): **5** 回 (customers.retrieve / invoices.create / invoiceItems.create / finalizeInvoice / pay)
  - カード拒否 → void: **7** 回 (上記 + invoices.retrieve + voidInvoice)
  - 既存 invoice 再利用: **2** 回 (finalize(400 already finalized) + pay)
  - いずれも予算 `DEFAULT_CONNECTION_STRIPE_CALL_BUDGET = 10` 以下
- AWS `retries.max_attempts = 2` は **初回を含む試行回数 2** であることを MockHandler で実測確認
- `getCommand()['@retries']` は client 既定では **null** (per-command で渡したときだけ入る) ため、
  client 既定の retries は「試行回数を数える behavioral テスト」で固定した

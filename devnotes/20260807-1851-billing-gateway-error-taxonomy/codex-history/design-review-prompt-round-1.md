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

## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【前提: 概念設計は Codex conceptual-review Round 5 で APPROVED 済み】
以下は概念設計で確定し、詳細設計では蒸し返さない判断である:
- `AutoRechargeGatewayInterface` の契約は変更しない (9 メソッドいずれも wrap しない)
- 語彙は `GatewayFailureClass` 1 系統。2 系統に割らない
- `unknown` は「写像の不在」専用。写像表の値としては禁止
- `UnknownApiErrorException` は HTTP status で 2 分岐 (>=500 → provider_unavailable / それ以外・null → provider_rejected)
- fake/real parity の対象は業務分類 4 case のみ (`unknown` は対象外)
- 分類は観測のためであり制御フローを変えない
- T131 (job-execution-dedup) で確定した「原例外を report/previous しない」「tryTerminateInvoice を再利用しない」は維持

---

## 詳細設計書

# 詳細設計: billing-gateway-error-taxonomy

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md 転記）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### 思考原則（本設計で特に効くもの）

2. **今必要なものだけ作る**（オーバーエンジニアリング禁止）
3. **後方互換の並走を残さない**（書き換えると決めたら同じ PR で旧実装を消す）
5. **テストファースト**（fail を確認してから実装に入る）

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- テストデータは必ず Factory で生成
- `declare(strict_types=1)` + 日本語コメント
- **アーリーリターン** 推奨 / `composer fix`（Pint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [`conceptual-design.md`](./conceptual-design.md)（Codex conceptual-review Round 5 で **APPROVED**）
- [`recon-brief.md`](./recon-brief.md)（一次入力）

### 概念設計で確定済み・詳細設計で蒸し返さない判断

| 論点 | 結論 |
|---|---|
| `AutoRechargeGatewayInterface` の契約 | **変更しない**（9 メソッドいずれも wrap しない） |
| 語彙 | `GatewayFailureClass` **1 系統**のみ。2 系統に割らない |
| `unknown` | **写像の不在**専用。写像表の値としては禁止 |
| `UnknownApiErrorException` | HTTP status で 2 分岐（`>= 500` → `provider_unavailable` / それ以外・null → `provider_rejected`） |
| fake/real parity | 業務分類 **4 case のみ**（`unknown` は対象外） |
| 制御フロー | **変えない**（分類は観測のため） |
| T131 の確定判断 | `terminateInvoiceBestEffort()` の「原例外を report/previous しない」「`tryTerminateInvoice()` を再利用しない」は維持 |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 失敗分類語彙 `GatewayFailureClass` の新設 | `app/Enums/Billing/GatewayFailureClass.php` (新規) | High |
| S2 | 分類器 `GatewayFailureClassifier` の新設 | `app/Support/Billing/GatewayFailureClassifier.php` (新規) | High |
| S3 | `AutoRechargeService` の観測 4 箇所を分類器へ統一（`getMessage()` 全廃） | `app/Services/Billing/AutoRechargeService.php` | High |
| S4 | テスト用 spy の失敗注入を共有 fixture 経由へ（fake/real 分類一致） | `tests/Support/FakeAutoRechargeGateway.php` / `tests/Support/Billing/*` (新規) | High |
| S5 | deny-by-default 目録 gate の新設 | `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php` (新規) / `app/Enums/Security/GatewayFailureObservationExemption.php` (新規) | High |
| S6 | Unit / Feature テスト（分類の全域性・境界・制御フロー等価性・ログ語彙） | `tests/Unit/Support/Billing/*` (新規) / `tests/Feature/Billing/AutoRechargeServiceTest.php` | High |
| S7 | 運用契約の記述 | `docs/architecture.md` / `AGENTS.md` | Medium |

---

## S1: 失敗分類語彙 `GatewayFailureClass` の新設

### 変更箇所

- ファイル: `app/Enums/Billing/GatewayFailureClass.php`（新規）

### 波及変更

- TypeScript 型定義: **なし**（フロントに露出しない。ログ context 専用）
- API Resource/DTO: **なし**（HTTP 応答に載せない）
- テストファイル: S5 / S6 で参照

### 現行コード

存在しない。現行は `$e::class`（1 箇所）と `$e->getMessage()`（3 箇所）が併存している。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * 決済 gateway 消費経路で観測された失敗の分類。
 *
 * ★語彙は「**呼び出し側 / 運用担当が取れる行動**」で切る。Stripe の error code を
 *   そのまま採らない (外部語彙に依存すると増えたときに追随できない)。
 * ★case を足す条件は「運用担当が取る行動が既存 case と異なる」ことだけ。
 *   分類の粒度を過剰にしない (AGENTS.md 思考原則 2)。
 * ★**この分類は観測のためであり、制御フローを変えない。**
 *   分岐に使いたくなったら、そのときは型 (ドメイン例外) を検討し直すこと。
 * ★カード拒否 (`card_declined` / `authentication_required`) は本 enum の担当ではない。
 *   既に `OffSessionChargeResultDto` の typed 結果が持っている (語彙を二重管理しない)。
 */
enum GatewayFailureClass: string
{
    /** 決済事業者側の一時的な不能 (接続断・タイムアウト・レート制限・5xx)。同じ要求の再送で収束しうる */
    case ProviderUnavailable = 'provider_unavailable';

    /** 決済事業者が要求を受理しなかった。同じ要求を再送しても収束しない (要求内容・認証情報・利用者操作のいずれかが要る) */
    case ProviderRejected = 'provider_rejected';

    /** アプリ自身が検出した不変条件違反 (Assert / 明示的な例外 / SDK・Cashier の誤用) */
    case InvariantViolation = 'invariant_violation';

    /** 自インフラ層 (DB / cache) が返した失敗。障害・SQL 不備・制約違反のいずれもありうる */
    case LocalFailure = 'local_failure';

    /**
     * **写像表に一致が無かった**。
     *
     * ★この case が出ること自体が「分類器に欠落がある」という通知である。
     *   したがって**写像表の値として使ってはならない** (登録済みなのに unknown、という
     *   状態を作ると運用契約「unknown が出たら表へ足せ」と矛盾する)。
     *   `BillingGatewayFailureTaxonomyInventoryTest` が機械で禁止する。
     */
    case Unknown = 'unknown';
}
```

### PHPStan 適合チェック

- [x] backed enum（`string`）で `->value` が `string` に確定する
- [x] 戻り値の型が明示されている（enum 自体に処理は無い）
- [x] null 安全（該当なし）
- [x] DTO を返している（該当なし。HTTP 応答に載らない）

### テスト計画

- [x] S5 の gate が「case 集合」を fixture 側と exact fit で照合する（case の増減は必ず赤くなる）
- [x] S6 の Unit テストが全 case の分類を固定する

### リスク

- case を将来増やしたときに fixture / gate の cap を更新し忘れる → **gate が exact fit で赤くする**。

---

## S2: 分類器 `GatewayFailureClassifier` の新設

### 変更箇所

- ファイル: `app/Support/Billing/GatewayFailureClassifier.php`（新規）

配置理由: 既存の `app/Support/Billing/`（`BillingNotificationRecorder` / `StripePriceFixtures` 等）と同層。
`app/Support/JobExecution/AttemptOwnershipPreflight` と同じ「Service から切り出した純ロジック」の位置づけ。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Unit/Support/Billing/GatewayFailureClassifierTest.php`（新規）/ S5 の gate

**`Stripe\Exception\*` を import する app 側クラスが 1 つ増える**（現行 3: `CashierStripeGateway` /
`CashierAutoRechargeGateway` / `StripeScheduleGateway`）。これは意図した集約であり、
S5 の gate が「Stripe 例外型を import してよいクラス」を allowlist で固定する。

### 現行コード

存在しない。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\Billing\GatewayFailureClass;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Laravel\Cashier\Exceptions\CustomerAlreadyCreated;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Exceptions\InvalidCoupon;
use Laravel\Cashier\Exceptions\InvalidCustomer;
use Laravel\Cashier\Exceptions\InvalidCustomerBalanceTransaction;
use Laravel\Cashier\Exceptions\InvalidInvoice;
use Laravel\Cashier\Exceptions\InvalidPaymentMethod;
use Laravel\Cashier\Exceptions\SubscriptionUpdateFailure;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\BadMethodCallException as StripeBadMethodCallException;
use Stripe\Exception\CardException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidArgumentException as StripeInvalidArgumentException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\TemporarySessionExpiredException;
use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
use Stripe\Exception\UnknownApiErrorException;
use Throwable;
use Webmozart\Assert\InvalidArgumentException as AssertInvalidArgumentException;

/**
 * 決済 gateway 消費経路で捕まえた Throwable を、有界な分類 (GatewayFailureClass) へ写す純関数。
 *
 * ★**Stripe / Cashier の例外型を知る唯一の非 gateway コンポーネント**である。
 *   ここに集約することで「外部語彙が観測点へ散らばる」ことを防ぐ
 *   (集約点が 2 つになったら語彙が割れる。gate が import の allowlist を固定する)。
 * ★制御フローに使わない。分類は観測 (構造化ログ / 例外報告の文言) 専用である。
 * ★`unknown` は「写像の不在」であり、`directMap()` の値には現れない
 *   (`BillingGatewayFailureTaxonomyInventoryTest` が機械で禁止する)。
 */
final class GatewayFailureClassifier
{
    public static function classify(Throwable $throwable): GatewayFailureClass
    {
        // ★条件付き規則を先に判定する (唯一の特別扱い)。
        //   UnknownApiErrorException は ApiRequestor::_specificV1APIError() の status switch の
        //   `default:` 分岐であり、**Stripe の 5xx はすべてここに来る**。
        //   「未知」なのは error type であって status ではないため、status で細分する。
        if ($throwable instanceof UnknownApiErrorException) {
            $status = $throwable->getHttpStatus(); // vendor PHPDoc: @return null|int

            if ($status !== null && $status >= 500) {
                return GatewayFailureClass::ProviderUnavailable;
            }

            // 4xx / その他 / null。**運用上の保守的分類**であり、再送可能性の完全な意味判定ではない。
            // null で ProviderUnavailable (= 待てば直る) と言うと無行動を示唆する誤誘導になるため
            // 「調べる」側へ倒す。実際には factory が必ず status を受け取るため null は防御的分岐。
            return GatewayFailureClass::ProviderRejected;
        }

        $map = self::directMap();

        // ★実クラス → 親クラス連鎖の順に最初の一致を採る (将来のサブクラスを取りこぼさない)。
        //   グローバル SPL クラス (\RuntimeException 等) は表に入れないため、
        //   Stripe\Exception\InvalidArgumentException と Webmozart\Assert\InvalidArgumentException が
        //   共通祖先 \InvalidArgumentException で衝突することはない。
        /** @var class-string|false $class */
        $class = $throwable::class;

        while ($class !== false) {
            if (array_key_exists($class, $map)) {
                return $map[$class];
            }

            $class = get_parent_class($class);
        }

        return GatewayFailureClass::Unknown;
    }

    /**
     * 構造化ログ / 例外報告に載せる 2 キー。
     *
     * ★観測点が**同じ綴りの同じ 2 キー**を出すことをコードの構造で担保する
     *   (gate が「宣言した catch 箇所の数 == `context(` の出現回数」を exact fit で検査する)。
     * ★`error_class` は外部サービスが生成する文字列ではない (値域はコードベース + vendor の
     *   クラス名に閉じる)。**例外 message は載せない**。
     *
     * @return array{failure_class: string, error_class: class-string<Throwable>}
     */
    public static function context(Throwable $throwable): array
    {
        return [
            'failure_class' => self::classify($throwable)->value,
            'error_class' => $throwable::class,
        ];
    }

    /**
     * 直接写像 (class → case) の正本。
     *
     * ★根拠は推測ではなく **vendor の throw site**。Stripe 側は
     *   `vendor/stripe/stripe-php/lib/ApiRequestor.php` の `_specificV1APIError()` の
     *   HTTP status switch が正本 (400→InvalidRequest / 400+idempotency_error→Idempotency /
     *   400+rate_limit→RateLimit / 401→Authentication / 402→Card / 403→Permission /
     *   404→InvalidRequest / 429→RateLimit / default→UnknownApiError)。
     *   `_specificV2APIError()` は temporary_session_expired のみ振り分けて V1 へ委譲する。
     * ★**値に GatewayFailureClass::Unknown を置かない** (unknown は写像の不在専用)。
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

    /**
     * 条件付き規則を持つクラス (直接写像に入れられないもの)。
     *
     * ★`directMap()` に入れると値がダミーになり「正本」が嘘をつくため分けている。
     * ★gate が `=== [UnknownApiErrorException::class]` を**クラス同一性**で固定する
     *   (件数だけだと別クラスへ差し替えても green になる)。
     *
     * @return list<class-string<Throwable>>
     */
    public static function conditionalClasses(): array
    {
        return [UnknownApiErrorException::class];
    }
}
```

> **`Illuminate\Contracts\Cache\LockTimeoutException` は interface ではなく具象クラス**である
> （`class LockTimeoutException extends Exception`。`Contracts` 名前空間にあるが実体クラス。
> vendor で確認済み）。`AutoRechargeService` が既にこの FQCN を import して `catch` しており、
> 表のキーもこれで一致する。
> **`Illuminate\Contracts\Filesystem\LockTimeoutException` という同名の別クラスがある**ので
> import を取り違えないこと（Unit テストが実インスタンスで固定する）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`GatewayFailureClass` / `array{...}` / `array<...>` / `list<...>`）
- [x] null 安全（`getHttpStatus()` の `?int` を `!== null` で早期判定）
- [x] Generics の型パラメータが正しい（`class-string<Throwable>` / `list<class-string<Throwable>>`）
- [x] `array_key_exists()` + `get_parent_class()` の戻り型（`class-string|false`）をループ変数の PHPDoc で明示
- [x] DTO を返している（該当なし。ログ context の array shape は型で固定）

### テスト計画

- [x] 新規 Unit `tests/Unit/Support/Billing/GatewayFailureClassifierTest.php`
  - `directMap()` の全 entry について `classify(new/factory(...)) === 期待 case`（dataset 駆動）
  - 条件付き規則の境界: **500 → unavailable / 503 → unavailable / 499 → rejected / 400 → rejected / null → rejected**
  - 未知例外（`Tests\Support\Billing\UnmappedGatewayFailureForTest`）→ `Unknown`
  - 親クラス連鎖: `ApiConnectionException` を継承したテスト専用クラス → `ProviderUnavailable`
  - `context()` が**ちょうど 2 キー**で、`failure_class` が enum の `value`、
    `error_class` が実クラス名であること
  - `context()` の値に例外 message が含まれないこと（message に目印文字列を入れて検査）
- [x] 個別の `DatabaseTransactions` を使わない（Unit テストで DB を触らない）

### リスク

- 親クラス連鎖の走査が意図せぬ一致を作る → グローバル SPL クラスを表に入れないことで回避。
  gate が「表のキーがすべて vendor か非 vendor 明示宣言集合に属する」を集合一致で固定する。
- `LockTimeoutException` の同名別クラス (`Contracts\Filesystem`) を import してしまう → Unit テストが実インスタンスで固定する。

---

## S3: `AutoRechargeService` の観測 4 箇所を分類器へ統一

### 変更箇所

- ファイル: `app/Services/Billing/AutoRechargeService.php`
  - `terminateInvoiceBestEffort()` (L694-721)
  - `tryTerminateInvoice()` (L819-838)
  - `reconcile()` の attempt 隔離 catch (L990-996)
  - `reconcile()` の取りこぼし起票 catch (L1011-1016)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**（制御フロー・戻り値・状態遷移を変えない）
- テストファイル:
  - `tests/Feature/Billing/AutoRechargeServiceTest.php`
    — L685-700 の `$context['error'] === RuntimeException::class` を新語彙へ更新、
      L710-722 の report サニタイズ検査を fixture 文言へ更新
  - `tests/Feature/Billing/AutoRechargeReconcileTest.php` — ログ語彙の新規検査を追加
- ドキュメント: `docs/architecture.md` の運用契約表 (a) 行が
  「原因の分類は同ログの **`error` = 例外クラス名**」と書いており、**キー名が変わるため必ず更新する**（S7）

**対象外の catch（意図的に触らない）**: `applySetupCompletion()` L1096-1100 /
`applyReusedPaymentMethod()` L1174-1178 の `catch (Throwable $e) { report($e); }` は
**通知送信の失敗**を受けるものであり、gateway 例外の観測ではない。
gateway を消費していないため目録の `catchSites` にも入れない（gate のコメントに理由を残す）。

### 現行コード

```php
// (1) terminateInvoiceBestEffort()
$terminated = true;
$error = null;
try {
    $this->gateway->terminateInvoice($invoiceId);
} catch (Throwable $exception) {
    $terminated = false;
    $error = $exception::class;
    report(new RuntimeException(
        "auto-recharge: invoice {$invoiceId} の終端に失敗しました ({$error})",
    ));
}

Log::warning('auto-recharge: 所有権喪失後の invoice 終端', [
    'event' => ExternalCallKind::CLEANUP_LOG_EVENT,
    'job_type' => TicketAutoRechargeAttempt::class,
    'job_id' => $attempt->id,
    'attempt_ulid' => $attempt->attempt_ulid,
    'invoice_id' => $invoiceId,
    'terminated' => $terminated,
    'error' => $error,
]);

// (2) tryTerminateInvoice()
} catch (Throwable $e) {
    Log::warning('auto-recharge: invoice termination failed, keeping attempt pending', [
        'attempt_ulid' => $attempt->attempt_ulid,
        'invoice_id' => $attempt->stripe_invoice_id,
        'error' => $e->getMessage(),
    ]);

    return false;
}

// (3) reconcile() attempt 隔離
} catch (Throwable $e) {
    Log::warning('auto-recharge reconcile: attempt processing failed', [
        'attempt_ulid' => $attempt->attempt_ulid,
        'error' => $e->getMessage(),
    ]);
}

// (4) reconcile() 取りこぼし起票
} catch (Throwable $e) {
    Log::warning('auto-recharge reconcile: trigger failed', [
        'organization_id' => $organization->getKey(),
        'error' => $e->getMessage(),
    ]);
}
```

### 変更後コード

```php
// (1) terminateInvoiceBestEffort()
// ★ docblock の「`error` に入れるのは例外クラス名だけ」の段落を
//    「`failure_class` / `error_class` の 2 キーだけを載せる」に書き換える。
//    T131 で確定した「原例外を report せず previous にも繋がない」性質は維持する。
$terminated = true;
$failure = null;
try {
    $this->gateway->terminateInvoice($invoiceId);
} catch (Throwable $exception) {
    $terminated = false;
    // 有界な 2 キー (分類 + 例外クラス名) のみ。message は載せない。
    $failure = GatewayFailureClassifier::context($exception);
    // 原例外は報告しない (外部生成メッセージ / previous chain をログ基盤へ流さない)。
    report(new RuntimeException(
        "auto-recharge: invoice {$invoiceId} の終端に失敗しました "
        ."({$failure['failure_class']} / {$failure['error_class']})",
    ));
}

Log::warning('auto-recharge: 所有権喪失後の invoice 終端', [
    'event' => ExternalCallKind::CLEANUP_LOG_EVENT,
    'job_type' => TicketAutoRechargeAttempt::class,
    'job_id' => $attempt->id,
    'attempt_ulid' => $attempt->attempt_ulid,
    'invoice_id' => $invoiceId,
    'terminated' => $terminated,
    // ★成功時も**キーは常に存在させる** (集計 schema を安定させる。値は null)。
    'failure_class' => $failure['failure_class'] ?? null,
    'error_class' => $failure['error_class'] ?? null,
]);

// (2) tryTerminateInvoice()
} catch (Throwable $e) {
    Log::warning('auto-recharge: invoice termination failed, keeping attempt pending', [
        'attempt_ulid' => $attempt->attempt_ulid,
        'invoice_id' => $attempt->stripe_invoice_id,
        ...GatewayFailureClassifier::context($e),
    ]);

    return false; // ★制御フローは現行のまま (pending 維持 → リコンサイル再試行)
}

// (3) reconcile() attempt 隔離
} catch (Throwable $e) {
    // 1 attempt の失敗が他 org の回収を止めないよう隔離 (次周期で再試行)。
    Log::warning('auto-recharge reconcile: attempt processing failed', [
        'attempt_ulid' => $attempt->attempt_ulid,
        ...GatewayFailureClassifier::context($e),
    ]);
}

// (4) reconcile() 取りこぼし起票
} catch (Throwable $e) {
    Log::warning('auto-recharge reconcile: trigger failed', [
        'organization_id' => $organization->getKey(),
        ...GatewayFailureClassifier::context($e),
    ]);
}
```

`use App\Support\Billing\GatewayFailureClassifier;` を import に追加する。

> **(1) だけ spread を使わない理由**: 成功時に `failure_class` / `error_class` を
> **null で存在させる**必要があるため（キー集合が成否で変わると集計 schema が割れる）。
> 残り 3 箇所は catch 内でのみログを出すので spread で足りる。
> どちらの形でも `GatewayFailureClassifier::context(` の出現回数は 1 なので、
> gate の exact fit 検査（4 箇所 = 4 回）は成立する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（既存メソッドのシグネチャは不変）
- [x] null 安全（`$failure['failure_class'] ?? null` で null 合体。`$failure` は `array{...}|null`）
- [x] DTO を返している（該当なし。`Log::warning` の context 配列）
- [x] Generics の型パラメータが正しい（`context()` の array shape をそのまま spread）
- [x] `@phpstan-ignore-line` / baseline を使わない（禁止事項 2）

### テスト計画

- [x] **バグ修正ではないが「fail を先に見る」**: S5 の gate（`getMessage()` 0 件検査）を
      先にコミットして **main の現状で赤くなる**ことを確認してから S3 を実装する
      （思考原則 5。この gate は**素の main で実際に赤くなる**唯一の検査である）
- [x] 既存テスト更新 `tests/Feature/Billing/AutoRechargeServiceTest.php`
  - `後始末のログに外部由来のメッセージを載せない` — `$context['error']` →
    `$context['failure_class'] === 'invariant_violation'` かつ
    `$context['error_class'] === Webmozart\Assert\InvalidArgumentException::class`
  - `後始末の例外報告にも外部由来のメッセージを渡さない` — 報告文言が
    fixture 由来の文字列（`'fixture:'`）を含まないこと / `getPrevious() === null` /
    `assertReportedCount(1)` は据え置き
- [x] 新規テスト（Feature）:
  - `終端失敗のログに 4 箇所とも failure_class / error_class が載る（getMessage は載らない）`
  - `終端成功時も failure_class / error_class のキーが null で存在する`（schema 安定）
  - **制御フロー等価性**: `分類ログの導入で attempt の収束先と gateway 呼び出し回数が変わらない`
    — 終端失敗 → attempt は `pending` 維持 / `terminated` 配列の要素数 / `reconcile()` の
    戻り値 `stats` が変更前と同一であることを固定する
  - `reconcile` の 2 箇所（DB 例外を注入して `local_failure`、gateway 例外を注入して
    `provider_unavailable`）
- [x] 個別の `DatabaseTransactions` を使っていないことを確認（`tests/Pest.php` のグローバル適用に従う）

### リスク

| リスク | 対処 |
|---|---|
| ログのキー名変更（`error` → `failure_class` / `error_class`）で既存の運用ダッシュボード / 検索条件が壊れる | `docs/architecture.md` の運用契約表を同 PR で更新する（S7）。**旧キーを並走させない**（思考原則 3） |
| 制御フローを誤って変えてしまう | 既存 34 本の `AutoRechargeServiceTest` が現行の収束先を固定している。**1 本も書き換えずに green** であることを等価性の主要な証拠にする（書き換えるのはログ検査 2 本だけ） |
| `?? null` の連発が読みにくい | (1) の 1 箇所だけ。理由を docblock に残す |

---

## S4: テスト用 spy の失敗注入を共有 fixture 経由へ

### 変更箇所

- `tests/Support/Billing/GatewayFailureFixtures.php`（新規）
- `tests/Support/Billing/UnmappedGatewayFailureForTest.php`（新規）
- `tests/Support/FakeAutoRechargeGateway.php`（変更）

### 波及変更

- テストファイル: `tests/Feature/Billing/AutoRechargeServiceTest.php` の
  `$gateway->failOnTerminate = true;` 4 箇所（L299 / L637 / L685 / L710 / L784）を
  `$gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;` 等へ置換
- `App\Services\Billing\Fakes\FakeAutoRechargeGateway`（runtime fake）: **変更なし**
  （例外を 1 つも投げない契約。S5 の gate がそれをソース走査で固定する）
- `tests/Architecture/ExternalFakeWiringInvariantTest.php`: **影響なし**
  （runtime fake のクラスも bind も変えない）

### 現行コード

```php
// tests/Support/FakeAutoRechargeGateway.php
/** true にすると terminateInvoice が throw する (終端失敗 → pending 維持の再現)。 */
public bool $failOnTerminate = false;

/** true にすると resolveSubscriptionPaymentMethod が throw する。 */
public bool $failOnResolveSubscriptionPaymentMethod = false;

public function terminateInvoice(string $invoiceId): void
{
    if ($this->failOnTerminate) {
        throw new RuntimeException('fake gateway: invoice 終端失敗');
    }

    $status = $this->invoiceStatuses[$invoiceId] ?? 'open';
    if ($status === 'paid') {
        throw new RuntimeException("fake gateway: paid invoice {$invoiceId} は終端できない");
    }
    …
}
```

**これが実在する偽グリーンである**: 本物 `CashierAutoRechargeGateway::terminateInvoice()` の
paid 判定は `Assert::true(...)` = `Webmozart\Assert\InvalidArgumentException` を投げるのに対し、
spy は `RuntimeException` を投げる。分類は前者が `invariant_violation`、後者が `unknown` で
**実際に食い違う**。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use App\Enums\Billing\GatewayFailureClass;
use Illuminate\Database\QueryException;
use LogicException;
use PDOException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 「**本物の gateway が実際に伝播させる例外クラスそのもの**」を分類ごとに返す共有 fixture。
 *
 * ★fake が独自の RuntimeException を投げると、分類を記録する経路がテストで一度も
 *   本物と同じ値を見ない (偽グリーン)。fake の失敗注入をここへ集約し、
 *   `BillingGatewayFailureTaxonomyInventoryTest` が
 *   「fixture の case 集合 == 業務 4 case」「classify(fixture(case)) === case」
 *   「fixture が返すクラスが実ライブラリ名前空間に属する」を deny-by-default で固定する。
 * ★`Unknown` は parity の対象外 (写像の不在専用なので「本物と同じ例外」が存在しない)。
 *   `Unknown` の固定は分類器の Unit テストが UnmappedGatewayFailureForTest で行う。
 */
final class GatewayFailureFixtures
{
    /** fixture が返してよいクラスの名前空間 (gate が参照する) */
    public const array ALLOWED_NAMESPACE_PREFIXES = [
        'Stripe\\Exception\\',
        'Laravel\\Cashier\\Exceptions\\',
        'Illuminate\\',
        'Webmozart\\Assert\\',
    ];

    /** parity の対象 (業務分類 4 case)。`Unknown` を含めない。 */
    public static function throwableFor(GatewayFailureClass $class): Throwable
    {
        return match ($class) {
            // Stripe に到達できない (接続断) — 本物では ApiConnectionException が伝播する
            GatewayFailureClass::ProviderUnavailable => ApiConnectionException::factory(
                'fixture: stripe unreachable',
            ),
            // 要求が拒否された (400) — 本物では InvalidRequestException が伝播する
            GatewayFailureClass::ProviderRejected => InvalidRequestException::factory(
                'fixture: invalid request',
                400,
            ),
            // 本物の terminateInvoice の paid 判定 (Assert::true) と**同じクラス**
            GatewayFailureClass::InvariantViolation => self::assertFailure(),
            // reconcile が DB 例外を受ける経路
            GatewayFailureClass::LocalFailure => new QueryException(
                'pgsql',
                'select 1',
                [],
                new PDOException('fixture: db unavailable'),
            ),
            GatewayFailureClass::Unknown => throw new LogicException(
                'Unknown は parity の対象外。分類器 Unit テストの UnmappedGatewayFailureForTest を使うこと',
            ),
        };
    }

    /** Webmozart\Assert\InvalidArgumentException を「実際に Assert に投げさせて」得る。 */
    private static function assertFailure(): Throwable
    {
        try {
            Assert::true(false, 'fixture: 不変条件違反');
        } catch (Throwable $throwable) {
            return $throwable;
        }

        throw new LogicException('Assert::true(false) が例外を投げませんでした');
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use RuntimeException;

/**
 * 写像表に**載っていない**ことを目的とするテスト専用例外。
 *
 * ★`unknown` (写像の不在) の分類を固定するために使う。vendor 例外を未分類のまま
 *   fixture に使うと「vendor 全件分類」の gate と衝突するため、専用クラスを置く。
 */
final class UnmappedGatewayFailureForTest extends RuntimeException {}
```

```php
// tests/Support/FakeAutoRechargeGateway.php (差分)

-    /** true にすると terminateInvoice が throw する (終端失敗 → pending 維持の再現)。 */
-    public bool $failOnTerminate = false;
+    /**
+     * terminateInvoice が投げる失敗の**分類** (null なら投げない)。
+     *
+     * ★bool ではなく分類で指定する。投げる実体は GatewayFailureFixtures が返す
+     *   **実ライブラリ例外**であり、本物の gateway が伝播させるクラスと一致する。
+     */
+    public ?GatewayFailureClass $terminateFailure = null;

-    /** true にすると resolveSubscriptionPaymentMethod が throw する。 */
-    public bool $failOnResolveSubscriptionPaymentMethod = false;
+    /** resolveSubscriptionPaymentMethod が投げる失敗の分類 (null なら投げない)。 */
+    public ?GatewayFailureClass $resolveSubscriptionFailure = null;

     public function terminateInvoice(string $invoiceId): void
     {
-        if ($this->failOnTerminate) {
-            throw new RuntimeException('fake gateway: invoice 終端失敗');
-        }
+        if ($this->terminateFailure !== null) {
+            throw GatewayFailureFixtures::throwableFor($this->terminateFailure);
+        }

         $status = $this->invoiceStatuses[$invoiceId] ?? 'open';
         if ($status === 'paid') {
-            throw new RuntimeException("fake gateway: paid invoice {$invoiceId} は終端できない");
+            // ★本物 (CashierAutoRechargeGateway の Assert::true) と**同じクラス**を投げる
+            throw GatewayFailureFixtures::throwableFor(GatewayFailureClass::InvariantViolation);
         }
         …
     }

     public function resolveSubscriptionPaymentMethod(string $stripeSubscriptionId): ?string
     {
         $this->resolvedSubscriptions[] = $stripeSubscriptionId;

-        if ($this->failOnResolveSubscriptionPaymentMethod) {
-            throw new RuntimeException('fake gateway: resolveSubscriptionPaymentMethod failed');
-        }
+        if ($this->resolveSubscriptionFailure !== null) {
+            throw GatewayFailureFixtures::throwableFor($this->resolveSubscriptionFailure);
+        }

         return $this->subscriptionPaymentMethodId;
     }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`Throwable` / `void` / `?string`）
- [x] null 安全（`?GatewayFailureClass` を `!== null` で判定）
- [x] `match` が全 case を網羅（`Unknown` は `throw` 式で明示的に拒否 → 到達不能でも網羅性は満たす）
- [x] `ApiConnectionException::factory()` / `InvalidRequestException::factory()` の
      引数は vendor PHPDoc（`$message, $httpStatus = null, …`）に一致
- [x] `QueryException::__construct($connectionName, $sql, array $bindings, Throwable $previous)`
      は Laravel 12 のシグネチャに一致

### テスト計画

- [x] S5 の gate が fixture の全域性・分類一致・名前空間・spy の `throw` 形式を固定する
- [x] 既存の `AutoRechargeServiceTest` 5 箇所の呼び出し更新後に **34 本すべて green**
      （= spy の投げ方を変えても収束先が変わらない = 制御フロー等価性の傍証）

### リスク

| リスク | 対処 |
|---|---|
| 他の並走タスクが `failOnTerminate` を使っていると衝突する | 実装モードを **standalone** にする（後述） |
| fixture の message が新たな「外部由来文字列」に見える | fixture は**テスト側が生成する固定文字列**であり外部生成ではない。T131 のテストが検査していた「外部由来文字列を含まない」の目印を `'fake gateway'` から `'fixture:'` へ移すだけで、検査の意味は保たれる |
| `Assert::true(false)` の例外クラスが vendor 更新で変わる | Unit テストが `classify()` の結果を固定するため、変われば赤くなる |

---

## S5: deny-by-default 目録 gate の新設

### 変更箇所

- `app/Enums/Security/GatewayFailureObservationExemption.php`（新規）
- `tests/Support/Billing/GatewayObservationEntry.php`（新規）
- `tests/Support/Billing/GatewayObservationExemptionEntry.php`（新規）
- `tests/Support/Billing/GatewayConsumerPopulation.php`（新規）
- `tests/Support/Billing/VendorExceptionPopulation.php`（新規）
- `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php`（新規）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- 既存 gate との重複: **なし**。`ExternalFakeWiringInvariantTest` は runtime fake の
  bind を見る gate であり、本 gate は「分類語彙の全域性と観測点」を見る。母集団が交わらない。

### 現行コード

存在しない。

### 変更後コード（要点）

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「決済 gateway を注入されるが、gateway 例外を**観測しない**ことが正しい」と裁定された理由の分類。
 *
 * `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php` が deny-by-default で
 * 「観測目録に登録する」か「本 enum + 具体的根拠付きの exemption」かを機械強制する。
 *
 * ★置き場所は既存の gate 語彙 enum (ThrottleCoverageExemption / JobDedupExemption /
 *   DirectFetchJustification / ControllerAuthorizationExemption / NestedRouteDefenseMode) と揃える。
 */
enum GatewayFailureObservationExemption: string
{
    /**
     * gateway 例外を catch せず**伝播させる**。
     *
     * 適用条件: クラス内に gateway 呼び出しを囲む catch が 1 つも無く、失敗が
     * キューの再試行 / `failed_jobs` に載ることで可観測性が担保されること。
     * ★根拠欄には「catch しないから安全」ではなく
     *   **「catch しない結果どこに何が残るか」**を書くこと
     *   (伝播先には vendor 例外の message が載る = 本設計の保証範囲外である)。
     */
    case PropagatesToQueueFailure = 'propagates_to_queue_failure';
}
```

```php
// tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php (骨子)

/*
 * 決済 gateway 消費経路の「失敗分類の語彙」を deny-by-default で固定する。
 *
 * ★この gate が保証するもの:
 *   - gateway を注入される app クラスが全件「観測目録」か「免除」に分類されている
 *   - vendor (Stripe / Cashier) の例外クラスが全件、写像表か条件付き規則に属する
 *   - `unknown` が写像表の値に現れない (= unknown は写像の不在専用)
 *   - 条件付き規則のクラスがクラス同一性で 1 件に固定されている
 *   - fake の失敗注入が本物と同じ分類を返す (fixture 経由・実ライブラリ例外)
 *   - 観測目録のクラスが例外 message をログへ載せない (getMessage() の cap)
 *
 * ★この gate が保証しないもの:
 *   - catch が「gateway 呼び出しを囲んでいる」こと (ソース走査では位置を検査しない。
 *     配置の保証は Feature テスト = AutoRechargeServiceTest / AutoRechargeReconcileTest)
 *   - 期待値と目録を**同時に**消す変更 (宣言的 gate の性質。目的は
 *     「1 箇所の削除では通らない = レビューで必ず 2 箇所の差分が見える」こと)
 *
 * 運用契約: docs/architecture.md §オートリチャージの失敗分類
 */

const BILLING_GATEWAY_TAXONOMY_MUTATION_COVERAGE = [
    'M1' => '写像表から entry を 1 つ削ると vendor 集合一致が赤くなる',
    'M2' => '写像表に実在しないクラスを足すと集合一致が赤くなる',
    'M3' => '写像表の値に Unknown を置くと赤くなる',
    'M4' => 'conditionalClasses を別クラスへ差し替えると赤くなる',
    'M5' => 'fixture の 1 case を独自 RuntimeException にすると分類一致 / 名前空間が赤くなる',
    'M6' => 'spy に fixture 経由でない throw を戻すと赤くなる',
    'M7' => 'AutoRechargeService に $e->getMessage() を戻すと赤くなる',
    'M8' => '観測目録から AutoRechargeService を消すと未分類で赤くなる',
    'M9' => '免除 cap を書き換えると赤くなる',
    'M10' => 'context() の呼び出しを 1 つ削ると出現回数の exact fit が赤くなる',
];

/** @return array<class-string, GatewayObservationEntry> */
function billingGatewayObservers(): array
{
    return [
        AutoRechargeService::class => new GatewayObservationEntry(
            catchSites: [
                'terminateInvoiceBestEffort',  // 所有権喪失後の後始末 (T131 新設)
                'tryTerminateInvoice',         // 停止側の invoice 終端
                'reconcile',                   // attempt 隔離 + 取りこぼし起票 = 2 箇所
                'reconcile',
            ],
            rawMessageCap: 0,
            rationale: 'gateway 例外を catch して観測へ落とす唯一のクラス。4 箇所すべてが '
                .'GatewayFailureClassifier::context() の 2 キーだけを載せ、例外 message は載せない。'
                .'通知送信失敗を受ける applySetupCompletion / applyReusedPaymentMethod の '
                .'catch は gateway を消費しないため対象外。',
        ),
    ];
}

/** @return array<class-string, GatewayObservationExemptionEntry> */
function billingGatewayObservationExemptions(): array
{
    return [
        SetDefaultPaymentMethodJob::class => new GatewayObservationExemptionEntry(
            GatewayFailureObservationExemption::PropagatesToQueueFailure,
            'gateway 例外を catch せず伝播させる。失敗は queue の再試行と failed_jobs に載り、'
            .'そこには vendor 例外の message が残る (本設計の保証範囲は AutoRechargeService の'
            .'構造化ログと report 文言までであり、伝播先の redact は横断基盤の話でスコープ外)。',
        ),
        ReuseSubscriptionPaymentMethodJob::class => new GatewayObservationExemptionEntry(…),
        HandleAutoRechargeChargeFailureJob::class => new GatewayObservationExemptionEntry(…),
    ];
}

function billingGatewayObservationExemptionCap(): int
{
    return 3; // exact fit
}

/**
 * 非 vendor の明示宣言クラス (期待値の正本。分類器の写像表とは**独立した宣言**)。
 *
 * ★framework 由来に限定しない。`unknown` の運用契約 (出たクラスは必ず写像表へ足す) により、
 *   将来アプリ自身の例外クラスがここへ入りうる。
 *
 * @return list<class-string<Throwable>>
 */
function billingNonVendorExplicitClasses(): array
{
    return [
        QueryException::class,
        LockTimeoutException::class,           // Illuminate\Contracts\Cache\LockTimeoutException (具象クラス)
        AssertInvalidArgumentException::class,
    ];
}

function billingNonVendorExplicitCap(): int
{
    return 3; // exact fit
}
```

**検査一覧（test 名）**

| # | 検査 | 落ちる mutation |
|---|---|---|
| 1 | gateway を注入される app クラスが全件分類されている（未分類 / 実在しない目録 entry は fail） | M8 |
| 2 | 観測目録と免除は排他 | — |
| 3 | 免除件数が cap と一致（exact fit = 3） | M9 |
| 4 | 目録・免除の根拠が 30 文字以上 | — |
| 5 | 目録 entry の `catchSites` がすべて実在するメソッド（Reflection） | — |
| 6 | 目録 entry のクラスのソースに `getMessage()` が `rawMessageCap` を超えて現れない | M7 |
| 7 | 目録 entry のクラスのソースの `GatewayFailureClassifier::context(` 出現回数 == `count(catchSites)` | M10 |
| 8 | `keys(directMap) ∩ conditionalClasses = ∅` | — |
| 9 | `keys(directMap) ∪ conditionalClasses == vendorConcreteClasses ∪ nonVendorExplicitClasses` | M1 / M2 |
| 10 | `conditionalClasses() === [UnknownApiErrorException::class]`（クラス同一性） | M4 |
| 11 | `directMap()` の値に `GatewayFailureClass::Unknown` が現れない | M3 |
| 12 | `nonVendorExplicitClasses` の件数が cap と一致（exact fit = 3） | — |
| 13 | vendor 走査の健全性: `Stripe\Exception\` のサブ名前空間が `['OAuth']` ちょうど / `Laravel\Cashier\Exceptions\` は `[]` / 走査結果が代表クラスを含む（縮み検出） | — |
| 14 | fixture の case 集合 == `GatewayFailureClass::cases()` − `Unknown`（exact fit） | — |
| 15 | 全 fixture について `classify(fixture(case)) === case` | M5 |
| 16 | fixture が返すクラスが `ALLOWED_NAMESPACE_PREFIXES` に属する | M5 |
| 17 | spy（`Tests\Support\FakeAutoRechargeGateway`）のソースの `throw ` 出現回数 == `throw GatewayFailureFixtures::throwableFor(` の出現回数 | M6 |
| 18 | runtime fake（`App\Services\Billing\Fakes\FakeAutoRechargeGateway`）のソースに `throw ` が 0 件 | — |
| 19 | `Stripe\Exception\` を import する app クラスが allowlist と集合一致（`CashierStripeGateway` / `CashierAutoRechargeGateway` / `StripeScheduleGateway` / `GatewayFailureClassifier`） | — |
| 20 | mutation coverage 表のキー集合が想定 ID 集合と一致 | — |

母集団の導出（`GatewayConsumerPopulation::classes()`）:
`app/` の PHP ファイルを走査 → PSR-4 でクラス名へ変換 → `class_exists()` →
`ReflectionClass` の constructor と全メソッドの引数型に
`AutoRechargeGatewayInterface` が現れるものを収集し `sort()` する。
（`QueuedJobPopulation` と同じ作法。**走査の縮み**は検査 1 の `stale` 判定と
検査 13 相当の代表クラス検査で拾う）

vendor 母集団の導出（`VendorExceptionPopulation::classes()`）:
`vendor/stripe/stripe-php/lib/Exception/*.php`（**直下のみ**）と
`vendor/laravel/cashier/src/Exceptions/*.php` を glob → クラス名へ変換 →
`class_exists()` → `ReflectionClass::isInterface()` / `isAbstract()` を除外。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`array<class-string, …>` / `list<class-string<Throwable>>` / `int`）
- [x] null 安全（`file_get_contents()` の `string|false` を `Assert::string()` で narrowing。既存 gate と同作法）
- [x] `glob()` の `array|false` を `Assert::isArray()` で narrowing（`jobDedupSupportPhpFiles()` と同じ）
- [x] Generics の型パラメータが正しい
- [x] readonly promoted property の entry クラスに PHPDoc 配列型を付ける

### テスト計画

- [x] gate 自体がテスト。**素の main で赤くなるのは検査 6（`getMessage()` cap）だけ**であり、
      これを**先にコミットして赤を見る**（思考原則 5）
- [x] それ以外の検査は「新規に導入する不変条件」なので main では赤にならない。
      実効性は **mutation（M1〜M10）で 1 つずつ確認**し `mutation-log.md` に記録する（後述）
- [x] Architecture lane は `RefreshDatabase` の対象外。DB を触らない実装にする

### リスク

| リスク | 対処 |
|---|---|
| `composer update` で stripe-php / cashier の例外クラスが増減すると CI が赤くなる | **意図した副作用**。「外部の語彙が増えたことを人間に必ず知らせる」ための費用として受け入れる（概念設計の制約に明記済み）。復旧手順は `docs/architecture.md` に書く |
| `LockTimeoutException` の同名別クラス (`Contracts\Filesystem`) を import してしまう | Unit テストが実インスタンスで固定する |
| ソース走査が脆い（コメント内の `getMessage()` に反応する等） | 既存 gate（`JobExecutionDedupInventoryTest` の literal 走査）と同じ割り切り。**偽陽性側に倒れる**ので安全側 |

---

## S6: Unit / Feature テスト

### 変更箇所

- `tests/Unit/Support/Billing/GatewayFailureClassifierTest.php`（新規）
- `tests/Feature/Billing/AutoRechargeServiceTest.php`（変更 + 追加）
- `tests/Feature/Billing/AutoRechargeReconcileTest.php`（追加）

### 波及変更

- 既存 34 本の `AutoRechargeServiceTest` は**ログ検査 2 本以外書き換えない**
  （書き換えないこと自体が制御フロー等価性の証拠）

### 変更後コード（要点）

```php
// tests/Unit/Support/Billing/GatewayFailureClassifierTest.php

dataset('直接写像の全 entry', function (): Generator {
    // ★写像表そのものを dataset にする = entry を足したら必ずインスタンス生成の
    //   面倒を見ることになる (「表に足しただけで実際には作れない」を防ぐ)
    foreach (GatewayFailureClassifier::directMap() as $class => $expected) {
        yield $class => [$class, $expected];
    }
});

test('直接写像の各クラスが宣言どおりに分類される', function (string $class, GatewayFailureClass $expected): void {
    $throwable = billingTaxonomyInstantiate($class);   // クラス別の生成ヘルパ

    expect(GatewayFailureClassifier::classify($throwable))->toBe($expected);
})->with('直接写像の全 entry');

test('UnknownApiErrorException は HTTP status で分岐する', function (?int $status, GatewayFailureClass $expected): void {
    $throwable = UnknownApiErrorException::factory('boundary', $status);

    expect(GatewayFailureClassifier::classify($throwable))->toBe($expected);
})->with([
    'null (status 不明)' => [null, GatewayFailureClass::ProviderRejected],
    '400' => [400, GatewayFailureClass::ProviderRejected],
    '499 (境界の下)' => [499, GatewayFailureClass::ProviderRejected],
    '500 (境界)' => [500, GatewayFailureClass::ProviderUnavailable],
    '503' => [503, GatewayFailureClass::ProviderUnavailable],
]);

test('写像表に無い例外は unknown へ落ちる', function (): void {
    expect(GatewayFailureClassifier::classify(new UnmappedGatewayFailureForTest('x')))
        ->toBe(GatewayFailureClass::Unknown);
});

test('親クラス連鎖で分類される (将来のサブクラスを取りこぼさない)', function (): void {
    $subclass = new class('sub') extends ApiConnectionException {};

    expect(GatewayFailureClassifier::classify($subclass))->toBe(GatewayFailureClass::ProviderUnavailable);
});

test('context は 2 キーちょうどで、例外 message を含まない', function (): void {
    $context = GatewayFailureClassifier::context(
        ApiConnectionException::factory('SECRET-EXTERNAL-MESSAGE'),
    );

    expect(array_keys($context))->toBe(['failure_class', 'error_class'])
        ->and($context['failure_class'])->toBe('provider_unavailable')
        ->and($context['error_class'])->toBe(ApiConnectionException::class)
        ->and(json_encode($context))->not->toContain('SECRET-EXTERNAL-MESSAGE');
});
```

```php
// tests/Feature/Billing/AutoRechargeServiceTest.php (更新 2 本 + 追加)

test('後始末のログに外部由来のメッセージを載せない (分類 + 例外クラス名のみ)', function (): void {
    Log::spy();
    …
    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;

    $service->executeAttempt($attempt);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            if (($context['event'] ?? null) !== ExternalCallKind::CLEANUP_LOG_EVENT) {
                return false;
            }

            return $context['terminated'] === false
                && $context['failure_class'] === 'provider_unavailable'
                && $context['error_class'] === ApiConnectionException::class
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'fixture:');
        })
        ->once();
});

test('制御フロー等価性: 分類ログを出しても収束先と gateway 呼び出し回数が変わらない', function (): void {
    // 終端失敗 → attempt は pending 維持 / terminate は 1 回だけ呼ばれる /
    // 課金 (pay) には進まない、を明示的に固定する
});

test('終端成功時も failure_class / error_class のキーが null で存在する', function (): void {
    // 集計 schema の安定 (キー集合が成否で変わらない)
});
```

```php
// tests/Feature/Billing/AutoRechargeReconcileTest.php (追加)

test('reconcile の attempt 隔離ログに分類が載る (gateway 例外)', …);   // provider_unavailable
test('reconcile の取りこぼし起票ログに分類が載る (DB 例外)', …);        // local_failure
```

### PHPStan 適合チェック

- [x] dataset の generator に戻り型 `Generator` を付ける
- [x] `json_encode(..., JSON_THROW_ON_ERROR)` で `string|false` を避ける
- [x] 匿名クラスの継承（`extends ApiConnectionException`）は PHPStan で解決可能

### テスト計画

- [x] `composer test -- tests/Unit/Support/Billing/` / `tests/Feature/Billing/` / `tests/Architecture/`
- [x] 最終的に `composer test` 全件 + `composer phpstan` + `vendor/bin/pint --test`
- [x] 個別の `DatabaseTransactions` を使わない

### リスク

- 匿名クラスの `extends ApiConnectionException` が SDK の final 化で壊れる →
  現状 final ではない。壊れたら専用の名前付きテストクラスへ置き換える。

---

## S7: 運用契約の記述

### 変更箇所

- `docs/architecture.md`
  - §ジョブの重複実行と結果の一回性 の運用契約表 (a) 行:
    **`error` = 例外クラス名 → `failure_class` / `error_class` の 2 キー**へ書き換え
  - **§オートリチャージの失敗分類（新設）**: 語彙の定義表 / `unknown` の運用契約
    （検知条件・初動・owner）/ vendor 更新で gate が赤くなったときの手順
  - 規約 ↔ テスト対応表に本 gate を追加
- `AGENTS.md` ドメイン固有規約に **1 項目**追加（数行）

### 現行コード

```markdown
| (a) | 所有権喪失後の void / delete に失敗した | **アプリログ**: `event = job_ownership_lost_cleanup` かつ `terminated=false`
(原因の分類は同ログの `error` = 例外クラス名。…) | … |
```

### 変更後コード

```markdown
| (a) | … | **アプリログ**: `event = job_ownership_lost_cleanup` かつ `terminated=false`
(原因の分類は同ログの **`failure_class`** = GatewayFailureClass、**`error_class`** = 例外クラス名。
`report()` 側にも invoice id とこの 2 値だけを持つサニタイズ済み例外しか流れないため、
**この cleanup 経路では Stripe が生成した原メッセージはアプリのどこにも残らない**。
`tryTerminateInvoice()` / `reconcile()` も同じ 2 キーへ統一済み。
詳細が要るときは `invoice_id` で Stripe 側を直接確認する) | … |
```

`AGENTS.md` 追記（ドメイン固有規約 7 として）:

```markdown
7. **決済 gateway 失敗の観測語彙**: `AutoRechargeGatewayInterface` を注入されるクラスは、
   gateway 例外を **観測する (`GatewayFailureClassifier::context()` の
   `failure_class` / `error_class` の 2 キーだけをログへ載せる)** か、
   **伝播させる (`GatewayFailureObservationExemption` + 30 文字以上の根拠で免除登録)** かの
   どちらかに目録登録が必須 (`BillingGatewayFailureTaxonomyInventoryTest` が
   deny-by-default で強制)。**例外 message はログに載せない** (外部生成の可変文字列)。
   分類は**観測のためであり制御フローを変えない**。`unknown` は「写像表に一致が無かった」
   ことを意味し、写像表の値としては禁止。詳細と運用契約は
   `docs/architecture.md` §オートリチャージの失敗分類。
```

### PHPStan 適合チェック

該当なし（ドキュメント）。ただし `docs/architecture.md` の
「規約 ↔ テスト対応表」に本 gate を追加すること。

### テスト計画

- [x] `docs/architecture.md` の記述が実装と一致していることを目視 + 該当テスト名の実在確認
- [x] `AGENTS.md` の追記が 1 項目・数行に収まっていること

### リスク

- ドキュメントと実装の乖離（T131 Round 4 で実際に起きた） →
  **キー名を変える S3 と同一 PR で更新**し、レビューで差分を並べて確認する。

---

## mutation で赤化を確認する手順

**素の main で赤くなるのは S5 の検査 6（`getMessage()` cap）だけ**である。
残りは新規に導入する不変条件なので、gate と実装が同一 PR に入る以上
「main では赤くならない」。したがって実効性は **mutation で 1 つずつ確認**し、
結果を `devnotes/20260807-1851-billing-gateway-error-taxonomy/mutation-log.md` に記録する。

手順（実装 PR の worktree 内で行う）:

1. 実装が全 green の状態を作る（`composer test` / `composer phpstan` / `pint --test`）
2. 下表の mutation を **1 つだけ**適用する
3. `composer test -- tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php`
   （M5/M7 は Feature / Unit も）を実行し、**期待した検査が赤くなる**ことを確認
4. `git checkout -- <file>` で戻し、次の mutation へ進む
5. 全 mutation の結果（赤くなった test 名）を `mutation-log.md` に記録する

| ID | mutation | 期待して赤くなる検査 |
|---|---|---|
| M1 | `directMap()` から `RateLimitException` の entry を削除 | 検査 9（vendor 集合一致） |
| M2 | `directMap()` に実在しないクラス（`\Foo\BarException::class` 相当の文字列キー）を追加 | 検査 9 |
| M3 | `directMap()` の 1 entry の値を `GatewayFailureClass::Unknown` に変更 | 検査 11 |
| M4 | `conditionalClasses()` を `[RateLimitException::class]` に差し替え | 検査 10（+ 9） |
| M5 | `GatewayFailureFixtures::throwableFor()` の `InvariantViolation` を `new RuntimeException('x')` に変更 | 検査 15 / 16 + Unit（分類一致） |
| M6 | spy の `terminateInvoice` を `throw new RuntimeException('fake gateway')` に戻す | 検査 17 |
| M7 | `AutoRechargeService::tryTerminateInvoice()` のログに `'error' => $e->getMessage()` を戻す | 検査 6（+ Feature のログ検査） |
| M8 | `billingGatewayObservers()` から `AutoRechargeService` の entry を削除 | 検査 1（未分類） |
| M9 | `billingGatewayObservationExemptionCap()` を `4` に変更 | 検査 3 |
| M10 | `reconcile()` の catch から `...GatewayFailureClassifier::context($e)` を 1 つ削除 | 検査 7 |

さらに、**M7 だけは順序を逆にする**（思考原則 5「テストファースト」）:
S5 の検査 6 を**先にコミットして main の現状で赤を見る**（3 箇所の `getMessage()` が
実在するため実際に赤くなる）→ そのうえで S3 を実装して green にする。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `tests/Support/FakeAutoRechargeGateway` の**公開プロパティ名を変える**（`failOnTerminate` → `terminateFailure`）ため、同じ spy を使う 7 本の Feature テストへ波及する。並走タスクが同 spy を触ると衝突が避けられない。(2) `AutoRechargeService` は T131 で直前に触った中心ファイルであり、他タスクと同 worktree に積むと責任範囲が混ざる。(3) vendor 走査 gate を含むため、依存更新タスクと同居させると赤の原因切り分けが難しくなる |
| 競合リスク | `AutoRechargeService` / `tests/Support/FakeAutoRechargeGateway` / `docs/architecture.md` / `AGENTS.md` を触る他タスクとの競合。とくに `AGENTS.md` ドメイン固有規約は**末尾に 1 項目追加**なので、番号衝突に注意（追加時に既存 6 項目の番号を変えない） |
| 前提 | 本設計は main の T131 マージ済み状態（`b9907af`）を起点とする |

## 実装順序（テストファーストを守る）

1. **S5 の検査 6 だけ**を先に置く → `composer test -- tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php` で**赤を確認**
2. S1（enum）→ S2（分類器）→ S6 の Unit テスト（分類の全域性・境界・unknown）
3. S4（fixture + spy）→ 既存 Feature テストの呼び出し更新
4. S3（`AutoRechargeService` の 4 箇所）→ 検査 6 が green になることを確認
5. S5 の残り検査（1〜5, 7〜20）
6. S6 の Feature テスト追加（制御フロー等価性・ログ語彙）
7. S7（ドキュメント）
8. mutation M1〜M10 を実施し `mutation-log.md` に記録
9. 全検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
   `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
   （フロント側の変更は無いが、AGENTS.md の検証コマンド契約に従い全件回す）

## 使命・禁止事項チェック

| 項目 | 判定 |
|---|---|
| 使命への寄与 | 課金が静かに壊れないことは撮影・生成の前提。失敗の一次切り分け（再送で収束するか）がログ 1 行で決まる |
| 禁止事項 1（テストなし完了なし） | Architecture gate（20 検査）+ Unit + Feature を施策ごとに用意 |
| 禁止事項 2（PHPStan widen / baseline） | 使わない。型は `class-string<Throwable>` / array shape で明示 |
| 禁止事項 3（dev DB 破壊） | 該当なし |
| 禁止事項 4（`response()->json()`） | 該当なし（HTTP 応答を触らない） |
| 禁止事項 5・6（Prism / prompt） | 該当なし |
| 禁止事項 7・8（redirect / disabled UI） | 該当なし |
| 禁止事項 9（Artifact） | 使用しない。成果物はリポジトリ内ファイル |
| 思考原則 2（今必要なものだけ） | interface 契約変更・他 gateway 横展開・横断 redact をスコープ外にした |
| 思考原則 3（並走を残さない） | `$e->getMessage()` を同一 PR で全廃。`failOnTerminate` も残さない |
| 思考原則 5（テストファースト） | 検査 6 を先にコミットして赤を見る手順を明記 |


---

## 関連する現行コード（実ファイルからの抜粋）

### `app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php`（全文）

```php
<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\DataTransferObjects\Billing\DefaultPaymentMethodDto;
use App\DataTransferObjects\Billing\InvoiceStateDto;
use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
use App\Models\Organization;

/**
 * P8a (D31): オートリチャージ系 Stripe 呼び出しの抽象
 * (実装: CashierAutoRechargeGateway。fake_externals 時は FakeAutoRechargeGateway を bind)。
 *
 * AI-CUE の「狭い gateway + gateway 単位の Fake bind」規約を維持する
 * (サブスク系 = StripeGatewayInterface / チケット checkout 系 = TicketCheckoutGateway と
 * 責務境界を分ける。移植元の 30+ メソッド単一 interface へは寄せない)。
 */
interface AutoRechargeGatewayInterface
{
    /**
     * オートリチャージ用カード保存 Checkout (mode=setup)。off-session mandate 同意を伴う。
     * 無料パーソナル (サブスクなし) でも Stripe Customer を作成して通す唯一のカード登録経路。
     *
     * @param  array<string, string>  $metadata
     * @return array{id: string, url: string|null}
     */
    public function createSetupCheckout(
        Organization $organization,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): array;

    /**
     * オートリチャージ Invoice の作成 (段階 1/2)。
     * draft invoice 作成 → invoice item (price × quantity) 追加、までを行い invoice id を返す。
     * 呼び出し側 (Service) はこの戻り値を attempt.stripe_invoice_id に**保存してから**
     * payOffSessionInvoice (段階 2/2) を呼ぶこと — 保存前にプロセスが落ちても、リコンサイルが
     * 同一 $idempotencyKeyBase で再実行すれば Stripe 冪等により同一 invoice が返る。
     *
     * @param  array<string, string>  $metadata  purpose / organization_id / recharge_attempt_ulid 必須
     */
    public function createAutoRechargeInvoice(
        Organization $organization,
        string $priceId,
        int $quantity,
        array $metadata,
        string $idempotencyKeyBase,
    ): string;

    /**
     * オートリチャージ Invoice の確定と回収 (段階 2/2)。
     * finalize (draft→open) → pay(off_session)。カード起因の失敗は例外ではなく typed 結果で返す。
     * Stripe 障害・設定不備は例外のまま伝播 (fail-closed)。
     */
    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto;

    /**
     * 失敗 invoice の終端保証。open → void / draft (finalize 前) → delete。
     * 冪等 (void/delete 済み・存在しないは成功扱い)。paid の invoice に対しては例外
     * (誤 void の防止 — paid は付与経路の管轄)。
     */
    public function terminateInvoice(string $invoiceId): void;

    /**
     * リコンサイル用の invoice 現在状態取得。
     * 存在しない (draft delete 済み含む) は status='deleted' として返す。
     */
    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto;

    /**
     * customer の default PM 状態 (有無・brand・last4)。
     * リチャージ有効化の fail-closed 判定に使う。
     */
    public function getDefaultPaymentMethodState(Organization $organization): DefaultPaymentMethodDto;

    /**
     * setup_intent から payment_method id を解決する
     * (Job から Stripe API を直接触らないための境界)。
     */
    public function resolveSetupIntentPaymentMethod(string $setupIntentId): string;

    /**
     * PM を customer に attach し invoice_settings.default_payment_method に設定する。
     * 既 attach の PM は attach を skip する冪等実装。
     */
    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void;

    /**
     * P9 (T1004): サブスクリプションの決済に使われた payment_method id を解決する。
     *
     * 解決順序: `subscription.default_payment_method` →
     * `latest_invoice.payment_intent.payment_method`。双方 null なら null。空文字は返さない。
     *
     * @return non-empty-string|null
     */
    public function resolveSubscriptionPaymentMethod(string $stripeSubscriptionId): ?string;
}

```

### `app/Services/Billing/CashierAutoRechargeGateway.php`（terminateInvoice / retrieveInvoiceState / payOffSessionInvoice）

```php
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

        return OffSessionChargeResultDto::paid($invoiceId, $amountPaid, $amountDue, $this->extractPaymentIntentId($paid));
    }

    public function terminateInvoice(string $invoiceId): void
    {
        $stripe = Cashier::stripe();

        try {
            $invoice = $stripe->invoices->retrieve($invoiceId);
        } catch (InvalidRequestException $e) {
            if ($e->getHttpStatus() === 404) {
                return; // 冪等: 存在しない (draft delete 済み含む) は成功扱い
            }

            throw $e;
        }

        $status = $invoice->status;

        if ($status === 'void' || $status === 'deleted') {
            return; // 冪等: 終端済み
        }

        // paid を誤って終端しない (付与経路の管轄)。uncollectible は Stripe 上 void 可能かつ
        // 後から支払われ得るため、終端保証の対象に含めて void する (放置すると遅延成功の穴になる)。
        Assert::true(
            $status === 'draft' || $status === 'open' || $status === 'uncollectible',
            "invoice {$invoiceId} は終端できない状態です (status={$status})",
        );

        if ($status === 'draft') {
            // draft は void 不可 (Stripe 制約) — delete で終端する
            $stripe->invoices->delete($invoiceId);

            return;
        }

        $stripe->invoices->voidInvoice($invoiceId);
    }

    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto
    {
        $stripe = Cashier::stripe();

        try {
            // nested expand で PaymentIntent object まで取得する — SCA (requires_action) 判定の出典。
            $invoice = $stripe->invoices->retrieve($invoiceId, ['expand' => ['payments.data.payment.payment_intent']]);
        } catch (InvalidRequestException $e) {
            if ($e->getHttpStatus() === 404) {
                return new InvoiceStateDto('deleted', null, null, null, false, null);
            }

            throw $e;
        }

        $status = $invoice->status;
        Assert::stringNotEmpty($status, 'Stripe invoice status missing');

        return new InvoiceStateDto(
            $status,
            $invoice->amount_paid,
            $invoice->amount_due,
            $this->extractPaymentIntentId($invoice),
            $this->invoiceRequiresAction($invoice),
            is_string($invoice->hosted_invoice_url) ? $invoice->hosted_invoice_url : null,
        );
    }

    public function getDefaultPaymentMethodState(Organization $organization): DefaultPaymentMethodDto
```

### `app/Services/Billing/AutoRechargeService.php`（変更対象 4 箇所とその周辺）

```php
    /**
     * preflight 2 で中断したときの invoice 後始末。
     *
     * **canceled のときだけ**終端する:
     *  - paid  … void できない (付与経路の管轄)
     *  - failed… `terminateAndFail()` が **`stripe_invoice_id` を DB 経由で見えている状態**で
     *    終端済み (attach 済みだからこの分岐に来ている)
     *  - canceled … 停止側の `tryTerminateInvoice()` は `stripe_invoice_id === null` を
     *    「invoice 未作成」と解釈して素通りするため、こちらの永続化が停止より後だと
     *    **誰も void しない open invoice が残る**。ここで拾う。
     *
     * ★ attach に失敗した invoice は本メソッドではなく `terminateUnattachedInvoice()` の担当
     *   (あちらは status を問わず終端する)。
     */
    private function terminateInvoiceAfterOwnershipLost(
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
    ): void {
        if ($attempt->status !== AutoRechargeAttemptStatus::Canceled) {
            return; // アーリーリターン
        }

        $this->terminateInvoiceBestEffort($attempt, $invoiceId);
    }

    /**
     * attempt 行へ紐付けられなかった (条件付き UPDATE が 0 行だった) invoice の後始末。
     *
     * ★ **status を問わず終端を試みる**。この invoice ID を知っているのは自分だけであり、
     *   terminal 化させた側は `stripe_invoice_id === null` を見ているため終端できない。
     *   canceled 限定にすると failed 経路で**誰も終端しない open invoice**が残る。
     * ★ `paid` の可能性は `CashierAutoRechargeGateway::terminateInvoice()` の状態検査が
     *   `Assert` で fail-closed に分類する (例外 → `terminated=false` としてログに残る)。
     */
    private function terminateUnattachedInvoice(
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
    ): void {
        $this->terminateInvoiceBestEffort($attempt, $invoiceId);
    }

    /**
     * invoice の best-effort 終端 + 固定 event 名でのログ (上 2 つの共通部)。
     *
     * ★ `$invoiceId` を**引数で受ける**。attempt 行に永続化できなかった invoice も
     *   終端したいため、DB の値に依存しない。
     * ★ `tryTerminateInvoice($attempt)` を再利用しない理由: あちらは
     *   `$attempt->stripe_invoice_id` を読むため「永続化できなかった invoice」を扱えず、
     *   かつ独自の warning を出すのでログが二重になる。ここは固定 event の 1 行に閉じる。
     * ★ `CashierAutoRechargeGateway::terminateInvoice()` は Stripe から retrieve して
     *   void/deleted/404 → 成功扱い、paid → `Assert` で明示的な非成功、draft → delete、
     *   open/uncollectible → void と**状態検査で冪等化**されている
     *   (idempotency key より強い — 期限が無い)。
     * ★ 失敗しても**課金処理へは進まない** (呼び出し側が無条件に return する)。
     *   残った open invoice は reconcile の母集団外なので、運用契約 (docs/architecture.md) の
     *   手動収束に委ねる。
     * ★ **cleanup 専用の event 名**を使う。送信抑止の記録 (`LOG_EVENT`) は最小 7 キー schema を
     *   持つ契約であり、キー集合の違うログを同じ event 名に混ぜない。
     * ★ `error` に入れるのは**例外クラス名だけ**である (impl-review Round 2/3 反映)。
     *   Stripe SDK の例外メッセージは**外部サービスが生成する可変文字列**であり、
     *   いま既知の内容が invoice id と status だけでも、将来の SDK / API 応答で
     *   何が混ざるかの契約は無い。構造化ログには**アプリが決めた有界な語彙**だけを載せる。
     * ★ 例外報告も**原例外を渡さない** (impl-review Round 3 反映)。
     *   標準の exception handler は message とスタックトレースを記録するため、
     *   `report($exception)` では「保存場所を移しただけ」で外部生成文字列が残る。
     *   ここでは invoice id と例外クラス名だけを持つ**サニタイズ済み例外**を報告し、
     *   原例外は `previous` にも**繋がない** (reporter が previous chain を出力しうるため)。
     *   トリアージに必要な情報 (どの invoice が / どの種類の失敗か) は保たれる。
     */
    private function terminateInvoiceBestEffort(
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
    ): void {
        $terminated = true;
        $error = null;
        try {
            $this->gateway->terminateInvoice($invoiceId);
        } catch (Throwable $exception) {
            $terminated = false;
            // paid 等の「明示的な非成功」もここに落ちる。分類できる有界な値 (クラス名) のみ記録する。
            $error = $exception::class;
            // 原例外は報告しない (外部生成メッセージ / previous chain をログ基盤へ流さない)。
            report(new RuntimeException(
                "auto-recharge: invoice {$invoiceId} の終端に失敗しました ({$error})",
            ));
        }

        Log::warning('auto-recharge: 所有権喪失後の invoice 終端', [
            'event' => ExternalCallKind::CLEANUP_LOG_EVENT,
            'job_type' => TicketAutoRechargeAttempt::class,
            'job_id' => $attempt->id,
            'attempt_ulid' => $attempt->attempt_ulid,
            'invoice_id' => $invoiceId,
            'terminated' => $terminated,
            'error' => $error,
        ]);
    }

    /**
     * 課金成功の確定: 冪等付与 + attempt paid 遷移 + failure_count リセット。
     * webhook (invoice.paid) / 同期 pay / リコンサイル (ii) の全経路がここに合流する。
```

```php
    }

    /**
     * invoice 終端 → failed 遷移 (+failure_count/自動停止)。終端失敗時は pending 維持で
     * リコンサイルが再試行する (終端保証を破らない)。
     */
    public function terminateAndFail(Organization $organization, TicketAutoRechargeAttempt $attempt): void
    {
        if (! $this->tryTerminateInvoice($attempt)) {
            return; // pending 維持 → リコンサイル再試行
        }

        if ($this->transitionToTerminal($attempt, AutoRechargeAttemptStatus::Failed)) {
            $this->notifyFailed($organization, $attempt);
        }
    }

    /**
     * invoice 終端 → canceled 遷移 (決済手段の問題ではない破棄。failure_count 増分なし)。
     */
    public function terminateAndCancel(TicketAutoRechargeAttempt $attempt): void
    {
        if (! $this->tryTerminateInvoice($attempt)) {
            return;
        }

        $this->transitionToTerminal($attempt, AutoRechargeAttemptStatus::Canceled);
    }

    private function tryTerminateInvoice(TicketAutoRechargeAttempt $attempt): bool
    {
        if ($attempt->stripe_invoice_id === null) {
            return true; // invoice 未作成 = 課金され得ない
        }

        try {
            $this->gateway->terminateInvoice($attempt->stripe_invoice_id);

            return true;
        } catch (Throwable $e) {
            Log::warning('auto-recharge: invoice termination failed, keeping attempt pending', [
                'attempt_ulid' => $attempt->attempt_ulid,
                'invoice_id' => $attempt->stripe_invoice_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
```

```php
    /**
     * pending attempt の回収と取りこぼし起票 (5 分岐)。
     * webhook が terminal-ack で恒久 drop した「課金済み・付与なし」の唯一のセーフティネット。
     *
     * @return array{recovered_paid: int, retried: int, sca_reminded: int, expired: int, triggered: int}
     */
    public function reconcile(): array
    {
        $stats = ['recovered_paid' => 0, 'retried' => 0, 'sca_reminded' => 0, 'expired' => 0, 'triggered' => 0];
        $now = CarbonImmutable::now();
        $expiryHours = $this->pendingExpiryHours();

        $pendings = TicketAutoRechargeAttempt::query()
            ->where('status', AutoRechargeAttemptStatus::Pending->value)
            ->orderBy('id')
            ->get();

        foreach ($pendings as $attempt) {
            $organization = $attempt->organization;
            Assert::isInstanceOf($organization, Organization::class);
            $createdAt = $attempt->created_at;
            Assert::notNull($createdAt);
            $age = CarbonImmutable::instance($createdAt);

            try {
                if ($attempt->stripe_invoice_id === null) {
                    // (i) invoice 未作成: scheduler 周期 (15 分) 超で再実行。同一 key base で
                    // Stripe 冪等が効くため二重課金しない。
                    if ($age->addMinutes(15) <= $now) {
                        $this->executeAttempt($attempt);
                        $stats['retried']++;
                    }

                    continue;
                }

                $state = $this->gateway->retrieveInvoiceState($attempt->stripe_invoice_id);

                if ($state->status === 'paid') {
                    // (ii) webhook 未着 / terminal drop の回収。付与は ledger 冪等。
                    $amountPaid = $state->amountPaid;
                    $amountDue = $state->amountDue;
                    Assert::integer($amountPaid);
                    Assert::integer($amountDue);
                    $this->recordSuccessfulCharge($organization, $attempt, $attempt->stripe_invoice_id, $amountPaid, $amountDue, $state->paymentIntentId);
                    $stats['recovered_paid']++;

                    continue;
                }

                if ($state->status === 'void' || $state->status === 'deleted') {
                    // invoice は既に課金不能 — attempt を canceled で閉じる (終端保証は満たされている)。
                    $this->transitionToTerminal($attempt, AutoRechargeAttemptStatus::Canceled);
                    $stats['expired']++;

                    continue;
                }

                // SCA 判定は Stripe 側 PaymentIntent 状態 (state) を第一出典、attempt の
                // failure_code (同期 pay の CardException 記録) を補助にする (webhook 到着順に依存しない)。
                $isSca = $state->requiresAction || $attempt->failure_code === 'authentication_required';

                if ($age->addHours($expiryHours) <= $now) {
                    // (iv) 期限切れ終端。SCA 放置は failed (+failure_count) — 放置ループ防止。
                    // それ以外 (draft のまま等、決済手段の問題ではない) は canceled。
                    if ($isSca) {
                        $this->terminateAndFail($organization, $attempt);
                    } else {
                        $this->terminateAndCancel($attempt);
                    }
                    $stats['expired']++;

                    continue;
                }

                if ($isSca) {
                    // (iii) SCA 待ち: 日次リマインダ (dedup は JST date bucket)。
                    $this->notifyActionRequired($organization, $attempt);
                    $stats['sca_reminded']++;
                }
            } catch (Throwable $e) {
                // 1 attempt の失敗が他 org の回収を止めないよう隔離 (次周期で再試行)。
                Log::warning('auto-recharge reconcile: attempt processing failed', [
                    'attempt_ulid' => $attempt->attempt_ulid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // (v) 取りこぼし起票: enabled な org で閾値割れ・pending なし (job 消失の回収)。
        $configs = TicketAutoRecharge::query()->where('enabled', true)->orderBy('id')->get();
        foreach ($configs as $config) {
            $organization = $config->organization;
            Assert::isInstanceOf($organization, Organization::class);

            try {
                $attempt = $this->maybeCreateAttempt($organization);
                if ($attempt !== null) {
                    ExecuteAutoRechargeAttemptJob::dispatch($attempt->id);
                    $stats['triggered']++;
                }
            } catch (Throwable $e) {
                Log::warning('auto-recharge reconcile: trigger failed', [
                    'organization_id' => $organization->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }
```

### `tests/Support/FakeAutoRechargeGateway.php`（テスト用 spy。変更対象）

```php
/**
 * AutoRechargeGatewayInterface のテスト用 spy (Stripe に到達しない)。
 *
 * 呼び出しを記録し、テスト側が結果を注入できる。invoice の状態は内部 map で保持し、
 * terminate / retrieve が現実の Stripe と同じ状態機械 (draft/open → void / paid は終端不可)
 * を近似する。
 */
final class FakeAutoRechargeGateway implements AutoRechargeGatewayInterface
{
    /** @var list<array{organizationId: int, successUrl: string, cancelUrl: string, metadata: array<string, string>, idempotencyKey: string}> */
    public array $setupCheckouts = [];

    /** @var list<array{organizationId: int, priceId: string, quantity: int, metadata: array<string, string>, keyBase: string}> */
    public array $createdInvoices = [];

    /** @var list<array{invoiceId: string, keyBase: string}> */
    public array $payCalls = [];

    /** @var list<string> */
    public array $terminated = [];

    /** @var list<array{organizationId: int, paymentMethodId: string}> */
    public array $defaultPaymentMethodsSet = [];

    /** default PM 状態 (有効化 fail-closed 判定の注入点)。 */
    public ?DefaultPaymentMethodDto $defaultPaymentMethod = null;

    /** payOffSessionInvoice の返り値 (null なら paid を合成する)。 */
    public ?OffSessionChargeResultDto $payResult = null;

    /** retrieveInvoiceState の返り値 (null なら invoiceStates → 内部 status map の順で解決する)。 */
    public ?InvoiceStateDto $invoiceState = null;

    /** invoiceId => InvoiceStateDto の個別注入 (org ごとに異なる状態を作るテスト用)。 */
    /** @var array<string, InvoiceStateDto> */
    public array $invoiceStates = [];

    /** invoiceId => status (retrieveInvoiceState / terminateInvoice の内部状態)。 */
    /** @var array<string, string> */
    public array $invoiceStatuses = [];

    /** true にすると terminateInvoice が throw する (終端失敗 → pending 維持の再現)。 */
    public bool $failOnTerminate = false;

    /**
     * createAutoRechargeInvoice が invoice ID を返す**直前**に呼ばれる hook
     * (`FakeRenderComposer::$duringCompose` と同じ作法)。
     *
     * 「Stripe 側の作成は成功したが、返る前に停止側が terminal 化した」
     * = attach 0 行になる競合点を決定論的に再現するために使う。
     */
    public ?Closure $duringCreateInvoice = null;

    /** @var list<string> resolveSubscriptionPaymentMethod を要求された subscription id (T1004) */
    public array $resolvedSubscriptions = [];

    /** resolveSubscriptionPaymentMethod の返り値 (null = 解決不能)。 */
    public ?string $subscriptionPaymentMethodId = 'pm_test_subscription';

    /** true にすると resolveSubscriptionPaymentMethod が throw する。 */
    public bool $failOnResolveSubscriptionPaymentMethod = false;

    /** createSetupCheckout が返す url (null = 進行中 replay の再現)。 */
    public ?string $setupUrl = 'https://checkout.stripe.test/c/setup/cs_setup_test';

    /** paid 合成時に使う実回収額 (null なら quantity × unit を使う呼び出し側の期待に委ねる)。 */
    public ?int $payAmountPaid = null;

    public ?string $payPaymentIntentId = 'pi_test_autorecharge';

    public function createSetupCheckout(
        Organization $organization,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
```

```php
        return OffSessionChargeResultDto::paid($invoiceId, $amount, $amount, $this->payPaymentIntentId);
    }

    public function terminateInvoice(string $invoiceId): void
    {
        if ($this->failOnTerminate) {
            throw new RuntimeException('fake gateway: invoice 終端失敗');
        }

        $status = $this->invoiceStatuses[$invoiceId] ?? 'open';
        if ($status === 'paid') {
            throw new RuntimeException("fake gateway: paid invoice {$invoiceId} は終端できない");
        }

        $this->terminated[] = $invoiceId;
        $this->invoiceStatuses[$invoiceId] = $status === 'draft' ? 'deleted' : 'void';
    }

    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto
    {
        if (isset($this->invoiceStates[$invoiceId])) {
            return $this->invoiceStates[$invoiceId];
        }

        if ($this->invoiceState !== null) {
            return $this->invoiceState;
        }

        return new InvoiceStateDto(
            $this->invoiceStatuses[$invoiceId] ?? 'open',
            null,
            null,
            null,
            false,
            'https://invoice.stripe.test/i/'.$invoiceId,
        );
    }

    public function getDefaultPaymentMethodState(Organization $organization): DefaultPaymentMethodDto
    {
        return $this->defaultPaymentMethod ?? DefaultPaymentMethodDto::none();
    }

    public function resolveSetupIntentPaymentMethod(string $setupIntentId): string
    {
        return 'pm_test_'.substr(hash('sha256', $setupIntentId), 0, 16);
    }

    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void
    {
        $this->defaultPaymentMethodsSet[] = [
            'organizationId' => (int) $organization->getKey(),
            'paymentMethodId' => $paymentMethodId,
        ];
        $this->defaultPaymentMethod = new DefaultPaymentMethodDto($paymentMethodId, 'visa', '4242');
    }

    public function resolveSubscriptionPaymentMethod(string $stripeSubscriptionId): ?string
    {
        $this->resolvedSubscriptions[] = $stripeSubscriptionId;

        if ($this->failOnResolveSubscriptionPaymentMethod) {
            throw new RuntimeException('fake gateway: resolveSubscriptionPaymentMethod failed');
        }

        return $this->subscriptionPaymentMethodId;
    }

    /** 有効化 fail-closed を通過させる (default PM ありの状態を注入する)。 */
    public function withDefaultPaymentMethod(string $paymentMethodId = 'pm_test_default'): self
    {
        $this->defaultPaymentMethod = new DefaultPaymentMethodDto($paymentMethodId, 'visa', '4242');

        return $this;
    }
}
```

### `app/Services/Billing/Fakes/FakeAutoRechargeGateway.php`（runtime fake。変更しない）

```php

/**
 * AutoRechargeGatewayInterface の runtime fake (fake_externals 環境専用。Stripe に到達しない)。
 *
 * 契約 = FakeTicketCheckoutGateway と同じ「外部ステップを skip した中立帰還」:
 * - setup Checkout は決定的な session id + アプリ内帰還 URL を返す
 * - **課金は一切成立させない** (payOffSessionInvoice は常に card_declined の typed 失敗)。
 *   fake 環境で自動購入が「成功」すると台帳に偽の付与行が残るため、成功側には倒さない
 * - default PM は「無し」を返す = fake 環境ではオートリチャージを有効化できない (fail-closed)
 */
final class FakeAutoRechargeGateway implements AutoRechargeGatewayInterface
{
    public function createSetupCheckout(
        Organization $organization,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): array {
        // idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)。
        $token = substr(hash('sha256', $idempotencyKey), 0, 32);

        return [
            'id' => "cs_bughuntfake_setup_{$token}",
            'url' => FakeExternalUrl::neutralReturn($cancelUrl),
        ];
    }

    public function createAutoRechargeInvoice(
        Organization $organization,
        string $priceId,
        int $quantity,
        array $metadata,
        string $idempotencyKeyBase,
    ): string {
        return 'in_bughuntfake_'.substr(hash('sha256', $idempotencyKeyBase), 0, 24);
    }

    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto
    {
        // fake は決済を成立させない (偽の付与行を台帳に残さない)。
        return OffSessionChargeResultDto::failed($invoiceId, 'card_declined', 'generic_decline');
    }

    public function terminateInvoice(string $invoiceId): void
    {
        // no-op: fake 環境は実 Stripe を叩かない (終端は常に成功扱い)。
    }

```

### `app/Enums/Security/ExternalCallKind.php`（既存のログ event 定数の作法）

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 所有権再検証 (preflight suppression) が守る外部呼び出しの種別。
 *
 * Manual ドメイン (例外経由) と Billing ドメイン (structured return) の**双方**が
 * 同じ語彙を共有するためにここへ置く (`tests/Architecture/JobExecutionDedupInventoryTest.php`
 * の目録もこの enum を使う。テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★case を足すとき: 「取り消せない外部副作用を持つか」を基準にする。
 *   ローカル CPU (ffmpeg) や冪等な読み取り (S3 GET) は本 enum の対象ではない。
 *
 * ★ログ event 定数を enum に同居させているのはやや変則だが、代替 (専用の Support クラス新設) は
 *   「ログ event 定数だけのために専用クラスを作る」ことになり AGENTS.md 思考原則 2
 *   (今必要なものだけ作る) に反するため採らない。
 */
enum ExternalCallKind: string
{
    /**
     * 所有権喪失で**外部送信を抑止した**ときの固定 event 名。
     *
     * ログ基盤で頻度を集計し「残余窓 1 が実際にどれだけ開いているか」を測るために固定する。
     * Manual / Billing の両方がこの 1 箇所を参照する (literal の直書きは
     * JobExecutionDedupInventoryTest が deny-by-default で検出する)。
     *
     * ★この event の**必須キー集合を 7 キーで固定**する
     *   (event / job_type / job_id / expected_status / actual_status / stage / external_call)。
     *   集計 schema を揃えるためであり、**PII を含まないドメイン固有キーの追加は可**
     *   (Billing は `attempt_ulid` を 1 本足す)。必須キーを欠くログを同じ event 名で出さない。
     */
    public const string LOG_EVENT = 'job_ownership_lost';

    /**
     * 所有権喪失**後の後始末** (open invoice の終端等) の固定 event 名。
     *
     * 抑止の記録とは schema が違う (expected/actual status も stage も持たない) ため
     * 別 event にする。集計時は「抑止 → 後始末の成否」の 2 段で追える。
     */
    public const string CLEANUP_LOG_EVENT = 'job_ownership_lost_cleanup';

    /** LLM 補完 (Prism 経由)。**provider 側に冪等キーが無い** = 呼んだら取り消せない */
    case LlmCompletion = 'llm_completion';

    /** オブジェクトストレージへの PUT (レンダ出力の S3 アップロード) */
    case ObjectStoragePut = 'object_storage_put';

    /** Stripe invoice の作成 (課金の前段。open invoice を残す) */
    case StripeInvoiceCreate = 'stripe_invoice_create';

    /** Stripe invoice の off-session 支払い (実際に金が動く) */
    case StripeInvoicePay = 'stripe_invoice_pay';
}

```

### 既存の deny-by-default 目録 gate の見本 `tests/Architecture/JobExecutionDedupInventoryTest.php`（冒頭と代表的な検査）

```php
/*
 * 裁定 AG-082「入口の排他 / 結果の一回性」の aicue 実装を deny-by-default で固定する。
 *
 * キューに載る全クラス (ShouldQueue 実装) は、次のいずれかに**必ず**分類される:
 *   - 保証側: JobDedupGuarantee (永続状態遷移の機構) + PreflightRequirement + 30 文字以上の根拠
 *   - 免除:   JobDedupExemption + 30 文字以上の根拠
 * 未分類は fail (新しいジョブを足したら必ずここへ登録する)。
 *
 * ★母集団は QueuedJobLeaseInventoryTest と**同一の実装** (Tests\Support\QueuedJobPopulation)
 *   を使う。2 実装に分けると片方だけ更新される drift が起きるため。
 *
 * ★ **この gate が保証するもの / しないもの**:
 *   - 保証する: 母集団の全クラスが分類されている / 期待する外部呼び出し種別と checkpoint 登録の
 *     **集合一致** / 再検証点の実在と制御方式に一致する戻り型 / 根拠 30 文字以上 / 免除 cap /
 *     `PreflightRequirement` 実装が 2 種に閉じている / 固定 event literal が 1 箇所に閉じている。
 *   - **保証しない**: preflight が**外部呼び出しの直前に置かれている**こと
 *     (Reflection では検査できない = 名前だけ存在する空メソッドでも green になる。
 *      配置の保証は Feature テスト = AnalysisPipelineTest / RenderPipelineTest /
 *      AutoRechargeServiceTest が「所有権喪失時に fake が 1 回も呼ばれない」ことで担う)。
 *     期待値 map と目録を**同時に**消す変更 (宣言的 gate の性質。1 箇所の削除では通らない
 *     = レビューで必ず 2 箇所の差分が見えることが目的)。
 *
 * 運用契約: docs/architecture.md §ジョブの重複実行と結果の一回性
 */

```

```php

/**
 * 免除件数の上限 (形骸化ガード)。**現在値ちょうど** (exact fit)。
 *
 * 「以下」ではなく「一致」で固定するのは、増やすときも減らすときも必ずこの数字を
 * 書き換えさせるためである (ModelDirectFetchInvariantTest と同じ作法)。
 */
function jobDedupExemptionCap(): int
{
    return 14;
}

/**
 * case 別の件数 (分類の偏り検出)。**array_sum で全体 cap を導出しない**
 * (全体 cap と case 別を独立に書かせることで、片方だけの書き換えを差分に必ず現す)。
 *
 * @return array<string, int>
 */
function jobDedupExemptionCapByCase(): array
{
    return [
        JobDedupExemption::DuplicateDeliveryAccepted->value => 8,
        JobDedupExemption::IdempotentDeletion->value => 2,
        JobDedupExemption::ConvergentStateSync->value => 3,
        JobDedupExemption::GuardedByDownstreamConstraint->value => 1,
    ];
}

/**
 * tests/Support/JobDedup/ 配下の PHP ファイル絶対パス一覧。
 *
 * @return list<string>
 */
function jobDedupSupportPhpFiles(): array
{
    $paths = glob(base_path('tests/Support/JobDedup').DIRECTORY_SEPARATOR.'*.php');
    Assert::isArray($paths, 'tests/Support/JobDedup を走査できません');
    sort($paths);

    return array_values($paths);
}

/** tests/Support/JobDedup 配下のパスを PSR-4 でクラス名へ変換する (純関数)。 */
function jobDedupSupportClassNameForPath(string $path): string
{
    return 'Tests\\Support\\JobDedup\\'.basename($path, '.php');
}

test('キューに載る全クラスが保証側 or 免除に分類されている (未分類は fail)', function (): void {
    $scanned = QueuedJobPopulation::shouldQueueClasses();
    $classified = array_merge(array_keys(jobDedupGuarantees()), array_keys(jobDedupExemptions()));
    sort($classified);

    $missing = array_values(array_diff($scanned, $classified));
    $stale = array_values(array_diff($classified, $scanned));

    expect($missing)->toBe([], '未分類の ShouldQueue 実装がある: '.implode(', ', $missing));
    expect($stale)->toBe([], '目録に実在しないクラスが残っている: '.implode(', ', $stale));
});

test('母集団の走査が縮んでいない (Job / Mailable / Notification の 3 系統が入る)', function (): void {
    $scanned = QueuedJobPopulation::shouldQueueClasses();

    expect($scanned)->toContain(RunManualAnalysis::class);
    expect($scanned)->toContain(InquiryReceivedMail::class);
    expect($scanned)->toContain(PaymentFailedNotification::class);
});

test('保証側と免除は排他 (同じクラスが両方に居ない)', function (): void {
    $both = array_intersect(array_keys(jobDedupGuarantees()), array_keys(jobDedupExemptions()));

    expect(array_values($both))->toBe([]);
});

/*
 * ★ 「QUEUED_JOB_LEASE_INVENTORY と目録のキー集合が一致する」という直接検査は**置かない**。
 *   (a) 両 gate が同じ `QueuedJobPopulation::shouldQueueClasses()` に対して
 *       それぞれ対称差 = 空を要求するため、両方 green なら一致は必然 (推移律)。
 *   (b) 他テストファイルのグローバル定数を参照すると、Pest の --parallel が
 *       ファイル単位でプロセスを分けたとき未定義になりうる。
 *   drift の構造的な防止は「母集団の走査実装を 1 本にしたこと」で達成されている。
 */

test('preflight の再検証点が実在し、登録された制御方式に一致する戻り型を持つ (※配置までは検査しない)', function (): void {
    // ★ この gate が固定できるのは**再検証点の実在と戻り型まで**である。
    //   「外部呼び出しの直前で呼ばれていること」は Reflection では検査できない
    //   (名前だけ存在する空メソッドでも green になる)。
    //   配置の保証は Feature テスト (所有権喪失時に LLM / S3 / Stripe fake が
    //   1 回も呼ばれないこと) の担当である。
    foreach (jobDedupGuarantees() as $class => $entry) {
        foreach ($entry->preflights as $preflight) {
            if (! $preflight instanceof PreflightCheckpoint) {
                continue;
            }

```

```php
test('目録の根拠は 30 文字以上 (constructor と gate の二重固定)', function (): void {
    foreach (jobDedupGuarantees() as $class => $entry) {
        expect(mb_strlen($entry->rationale))->toBeGreaterThanOrEqual(
            30,
            "{$class}: 保証側の根拠は 30 文字以上で書くこと",
        );

        foreach ($entry->preflights as $preflight) {
            if ($preflight instanceof NoExternalCall) {
                expect(mb_strlen($preflight->rationale))->toBeGreaterThanOrEqual(
                    30,
                    "{$class}: 「外部呼び出しなし」の根拠は 30 文字以上で書くこと",
                );
            }
        }
    }

    foreach (jobDedupExemptions() as $class => $entry) {
        expect(mb_strlen($entry->rationale))->toBeGreaterThanOrEqual(
            30,
            "{$class}: 免除の根拠は 30 文字以上で書くこと",
        );
    }
});

test('免除件数が全体 cap / case 別 cap と一致する (形骸化ガード)', function (): void {
    $exemptions = jobDedupExemptions();

    expect(count($exemptions))->toBe(
        jobDedupExemptionCap(),
        '免除件数が目録の宣言と一致しません。増減させたら jobDedupExemptionCap() も書き換えること '
        .'(「以下」ではなく「一致」で固定するのは、1 件直しても数字が下がらない「黙って足せる枠」を作らないため)',
    );

    $byCase = [];
    foreach ($exemptions as $entry) {
        $byCase[$entry->exemption->value] = ($byCase[$entry->exemption->value] ?? 0) + 1;
    }
    ksort($byCase);
    $declared = jobDedupExemptionCapByCase();
    ksort($declared);

    expect($byCase)->toBe($declared, 'case 別の免除件数が宣言と一致しません (分類の偏りは差分に必ず現すこと)');
});

test('固定 event 名の literal は ExternalCallKind 以外に直書きされていない', function (): void {
    // literal の直書きが増えると、ログ基盤での集計語彙が静かに割れる。
    // 2 つの event 名 (抑止 / 後始末) をまとめて検査する。
    // ★ single / double quote の **4 パターン**を検査する
    //   (片方だけだと "job_ownership_lost" で gate を回避できる)。
    $literals = [
        "'job_ownership_lost'", '"job_ownership_lost"',
        "'job_ownership_lost_cleanup'", '"job_ownership_lost_cleanup"',
    ];
    $violations = [];

    foreach (QueuedJobPopulation::appPhpFiles() as $path) {
        if (str_ends_with(str_replace(DIRECTORY_SEPARATOR, '/', $path), 'app/Enums/Security/ExternalCallKind.php')) {
            continue; // 正本
        }

        $source = file_get_contents($path);
        Assert::string($source, "ファイルを読み込めません: {$path}");

        foreach ($literals as $literal) {
            if (str_contains($source, $literal)) {
                $violations[] = $path.' ('.$literal.')';
            }
        }
    }

    expect($violations)->toBe(
        [],
        '固定 event 名は ExternalCallKind::LOG_EVENT / CLEANUP_LOG_EVENT を参照すること: '
        .implode(', ', $violations),
    );
});
```

### 既存の免除 enum の作法 `app/Enums/Security/ThrottleCoverageExemption.php`（冒頭）

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「保護対象群に属する route が throttle を持たないことが正しい」と裁定された理由の分類。
 *
 * `tests/Architecture/ThrottleCoverageInventoryTest.php` が deny-by-default で
 * 「throttle ちょうど 1 本」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「throttle を貼るべき route」である。
 */
enum ThrottleCoverageExemption: string
{
    /**
     * 定数メタデータ応答。
     *
     * 適用条件: DB アクセス・暗号処理・外部呼び出し・メール送信・ファイル書込を一切伴わず、
     * 応答が config と url() だけで決まる。
     */
    case StaticMetadataResponse = 'static_metadata_response';

    /**
     * vendor が登録する定数 405 (Method Not Allowed) スタブ。
     *
     * 適用条件: ハンドラが即座に固定 Response を返すだけで、本体処理へ到達しない。
     */
    case VendorMethodNotAllowedStub = 'vendor_method_not_allowed_stub';

    /**
     * セッション破棄のみを行い、推測可能な秘密を一切扱わない route。
     *
     * 適用条件: 認証済みでのみ到達でき、失敗しても攻撃者が得る情報が無い。
     */
    case SessionTeardownOnly = 'session_teardown_only';

```

### `tests/Feature/Billing/AutoRechargeServiceTest.php`（更新対象の 2 本）

```php

test('後始末ログの error は例外クラス名のみで、外部由来のメッセージを含まない', function (): void {
    // Stripe SDK の例外メッセージは外部サービスが生成する可変文字列であり、構造化ログの
    // 集計語彙へ流さない。
    Log::spy();
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
    $gateway->failOnTerminate = true; // メッセージ「fake gateway: invoice 終端失敗」で throw する

    $service->executeAttempt($attempt);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            if (($context['event'] ?? null) !== ExternalCallKind::CLEANUP_LOG_EVENT) {
                return false;
            }

            return $context['terminated'] === false
                && $context['error'] === RuntimeException::class
                && ! str_contains((string) $context['error'], 'fake gateway');
        })
        ->once();
});

test('後始末の例外報告にも外部由来のメッセージを渡さない (サニタイズ済み例外のみ)', function (): void {
    // 「構造化ログに載せない」だけでは不十分 — 標準の exception handler は message と
    // スタックトレースを記録するため、原例外をそのまま report() すると保存場所が移るだけになる。
    Exceptions::fake();
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
    $gateway->failOnTerminate = true;

    $service->executeAttempt($attempt);

    Exceptions::assertReported(function (RuntimeException $reported): bool {
        return str_contains($reported->getMessage(), 'の終端に失敗しました')
            // 外部 (fake gateway = Stripe SDK 相当) が生成した文字列を含まない
            && ! str_contains($reported->getMessage(), 'fake gateway')
            // previous chain も繋がない (reporter が previous を出力しうるため)
            && $reported->getPrevious() === null;
    });
    Exceptions::assertReportedCount(1);
});

```

### `docs/architecture.md`（キー名変更で影響を受ける運用契約表）

```markdown
   ジョブ側 `$timeout` < `retry_after` < 予約 TTL ≤ stale 閾値 (上節)。
   成立前提は「pcntl 有効 / 遅延なし / 時計ずれが小さい / シグナル順序 / supervisor 設定」。
8. **運用契約 (所有者 = 課金運用担当)** —
   - `event = job_ownership_lost` の**連続発生**は「ワーカーの停止・再開が多い」または
     「序列の前提が崩れた」の兆候。頻度を監視する。
   - **恒久回収を持たない open invoice が 2 種ある**。どちらも `reconcile()` は
     DB の pending attempt を走査するため**母集団外**であり、手動収束が必要。
     **検知元がそれぞれ違う**ので分けて書く:

     | # | 発生条件 | 検知元 | 収束手順 |
     |---|---|---|---|
     | (a) | 所有権喪失後の void / delete に失敗した | **アプリログ**: `event = job_ownership_lost_cleanup` かつ `terminated=false` (原因の分類は同ログの `error` = 例外クラス名。`report()` 側にも **invoice id と例外クラス名だけを持つサニタイズ済み例外**しか流れないため、**この cleanup 経路では Stripe が生成した原メッセージはアプリのどこにも残らない** (別経路の `tryTerminateInvoice()` は対象外)。詳細が要るときは `invoice_id` で Stripe 側を直接確認する) | 同ログの `invoice_id` を Stripe で確認し、`paid` でなければ手動 void |
     | (b) | invoice 作成成功 → `stripe_invoice_id` の永続化前にワーカーが死亡した | **アプリログには何も残らない**。Stripe 側を起点に探す — metadata `purpose=auto_recharge` を持つ `draft` / `open` invoice を列挙し、その `recharge_attempt_ulid` に対応する `ticket_auto_recharge_attempts` 行の `stripe_invoice_id` が **NULL または別 id** のものが孤児 | **原則すべて手動終端の対象**とする。`paid` でないことを確認して void / delete する |

     > **(b) を「次の実行が拾うから放置してよい」と書かない** — Stripe の idempotency key は
     > **保持期間 (数十時間程度) を過ぎると再実行で別の invoice が作られる**。
```

### vendor の事実（実ファイルで確認済み）

- `vendor/stripe/stripe-php/lib/Exception/` 直下の具象クラス 13 個。`ApiErrorException` は abstract、`ExceptionInterface` は interface。サブ名前空間は `OAuth` の 1 つのみ。
- `vendor/laravel/cashier/src/Exceptions/` 8 個、すべて `extends Exception` の具象クラス。
- `Illuminate\Contracts\Cache\LockTimeoutException` は **具象クラス**（`class ... extends Exception`）。同名の `Illuminate\Contracts\Filesystem\LockTimeoutException` が別に存在する。
- `Stripe\Exception\ApiErrorException::factory($message, $httpStatus = null, $httpBody = null, $jsonBody = null, $httpHeaders = null, $stripeCode = null)`、`getHttpStatus()` は PHPDoc `@return null|int`（戻り型宣言なし）。
- `Illuminate\Database\QueryException::__construct($connectionName, $sql, array $bindings, Throwable $previous, array $connectionDetails = [], $readWriteType = null)`。
- `app/` で `Stripe\Exception\*` を import しているのは現在 3 クラス（`CashierStripeGateway` / `CashierAutoRechargeGateway` / `StripeScheduleGateway`）。

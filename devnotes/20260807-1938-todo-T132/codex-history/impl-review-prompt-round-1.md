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

## あなたの役割

Laravel 12 + Svelte 5 + Inertia のアプリ (aicue) の **実装レビュアー**。
詳細設計書に対する実装差分をレビューし、Critical / Warning / Suggestion に分類して指摘する。

## レビュー観点

1. **設計との一致性**: 詳細設計書 (S1〜S7) の各施策が意図どおり実装されているか。設計から外れた箇所があれば、その逸脱が正当化されるか
2. **正確性**: 分類器の写像・境界条件 (HTTP status 500 境界 / null)・親クラス連鎖の走査に論理的な誤りがないか。gate の検査が「本当に落ちる」形になっているか (空虚に green にならないか)
3. **PHPStan level 10 適合性**: `@phpstan-ignore` / baseline / 型の widen を使っていないか。array shape / class-string の型付けが正しいか
4. **DTO / JsonResource パターン**: `response()->json()` 直書きがないか (本差分は HTTP 応答を触らない想定)
5. **テスト網羅性**: 施策ごとにテストがあるか。deny-by-default の gate が新しい抜け道を許していないか。negative assertion が空虚に green にならない前提保証があるか
6. **セキュリティ**: 外部生成文字列 (Stripe 例外 message) がログ / report に漏れないか。保証範囲の記述が誇張になっていないか
7. **DESIGN.md 準拠 / Atomic Design 準拠**: 本差分はフロント (`resources/js`, `resources/css`) を一切触らないため該当なし。触っていたら指摘すること
8. **後方互換の並走を残していないか** (AGENTS.md 思考原則 3): 旧 API (`failOnTerminate` / ログキー `error`) が残っていないか

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する
  - Critical = 必ず直すべき (欠陥・設計違反・保証の嘘)
  - Warning = 検討すべき
  - Suggestion = 好みの範囲
- 最後に **全体判定: APPROVED / CHANGES_REQUESTED** を明記する

## 前提 (蒸し返さないこと)

以下は概念設計 Round 5 / 詳細設計 Round 3 で APPROVED 済みの確定判断である。再議論しない:

- `AutoRechargeGatewayInterface` の契約は変更しない (9 メソッドを wrap しない)
- 語彙は `GatewayFailureClass` 1 系統のみ (2 系統に割らない)
- `unknown` は写像の不在専用 (写像表の値としては禁止)
- `UnknownApiErrorException` は HTTP status で 2 分岐する
- fake/real parity は業務 4 case のみ (`unknown` は対象外)
- 制御フローは変えない (分類は観測のため)
- T131 の確定判断 (`terminateInvoiceBestEffort()` が原例外を report せず previous にも繋がない) は維持
- vendor 走査 gate が `composer update` で赤くなるのは**意図した費用**

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
            // ★vendor の PHPDoc は @return null|int だが**戻り型宣言は無い**。
            //   `!== null` ではなく `is_int()` で narrowing して、PHPDoc の揺れに耐えさせる。
            $status = $throwable->getHttpStatus();

            if (is_int($status) && $status >= 500) {
                return GatewayFailureClass::ProviderUnavailable;
            }

            // 4xx / その他 / null / 非 int。**運用上の保守的分類**であり、
            // 再送可能性の完全な意味判定ではない。status 不明で ProviderUnavailable
            // (= 待てば直る) と言うと**無行動を示唆する誤誘導**になるため「調べる」側へ倒す。
            // 実際には factory が必ず status を受け取るため、null / 非 int は防御的分岐である。
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
- [x] null 安全（`getHttpStatus()` を `is_int()` で narrowing してから比較。vendor は戻り型宣言なし）
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
    // ★文言は**固定テンプレート**にする。report message は集計語彙になりうるため、
    //   Feature テストが**完全一致**で固定する (部分一致だと文字列の追加を検出できない)。
    report(new RuntimeException(sprintf(
        'auto-recharge: invoice %s の終端に失敗しました (%s / %s)',
        $invoiceId,
        $failure['failure_class'],
        $failure['error_class'],
    )));
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
  - `後始末の例外報告にも外部由来のメッセージを渡さない` —
    **報告文言を完全一致で固定する**（`sprintf` の期待文字列を組み立てて `===` で突き合わせ、
    部分一致検査をやめる。予期しない文字列の追加を必ず検出する）/
    `GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER` を含まないこと /
    `getPrevious() === null` / `assertReportedCount(1)` は据え置き
- [x] 新規テスト（Feature）:
  - `終端失敗のログに 4 箇所とも failure_class / error_class が載る（例外 message は載らない）`
  - **cleanup event のキー集合を成功・失敗の両方で固定する**
    （成功時も `failure_class` / `error_class` が **null で存在**する = 集計 schema が成否で割れない）
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
    /**
     * 全 fixture の message に必ず含める「外部生成文字列」の目印。
     *
     * ★これが**無いと negative assertion が空虚に green になる**。
     *   「ログにこの文字列が含まれない」という検査は、
     *   「例外 message にはこの文字列が確かに入っている」という保証とセットでしか意味を持たない。
     *   gate が全 fixture について `str_contains(getMessage(), MARKER)` を検査する。
     */
    public const string EXTERNAL_MESSAGE_MARKER = 'FIXTURE-EXTERNAL-MESSAGE';

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
                self::EXTERNAL_MESSAGE_MARKER.': stripe unreachable',
            ),
            // 要求が拒否された (400) — 本物では InvalidRequestException が伝播する
            GatewayFailureClass::ProviderRejected => InvalidRequestException::factory(
                self::EXTERNAL_MESSAGE_MARKER.': invalid request',
                400,
            ),
            // 本物の terminateInvoice の paid 判定 (Assert::true) と**同じクラス**
            GatewayFailureClass::InvariantViolation => self::assertFailure(),
            // reconcile が DB 例外を受ける経路
            GatewayFailureClass::LocalFailure => new QueryException(
                'pgsql',
                'select 1',
                [],
                // ★QueryException::formatMessage() は previous の message を取り込むため、
                //   マーカーは QueryException 自身の getMessage() にも現れる (実測で確認済み)。
                new PDOException(self::EXTERNAL_MESSAGE_MARKER.': db unavailable'),
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
            Assert::true(false, self::EXTERNAL_MESSAGE_MARKER.': 不変条件違反');
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
| negative assertion（ログにマーカーが無い）が**空虚に green** になる | `EXTERNAL_MESSAGE_MARKER` を導入し、gate が「全 fixture の `getMessage()` にマーカーが含まれる」ことを検査する。T131 のテストが使っていた目印 `'fake gateway'` はこのマーカーへ置き換える |
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
 *   - **fixture の message に外部生成文字列の目印が確かに入っている**
 *     (negative assertion が空虚に green にならないための前提保証)
 *   - 観測目録のクラスが例外 message をログへ載せない (getMessage() の cap)。
 *     ★これは gateway 観測点だけでなく**クラス全体**に掛かる設計制約である
 *       (対象クラスは gateway 以外の外部由来例外も受けうる。catch 近傍だけに限ると走査が脆い)。
 *       将来正当な必要が出たら rawMessageCap の変更が必ず差分に現れる
 *   - 旧 API (`failOnTerminate` 等) の残存が **本 gate ファイル自身 (= リテラルの正本) を除いて**
 *     0 件 (思考原則 3 の機械化)。★除外しないと**検査コード自身が hit して必ず失敗する**
 *
 * ★この gate が保証しないもの:
 *   - catch が「gateway 呼び出しを囲んでいる」こと (メソッド単位までは絞るが、
 *     catch の**中**で呼ばれているかは検査しない。配置の保証は Feature テスト =
 *     AutoRechargeServiceTest / AutoRechargeReconcileTest が
 *     「失敗時に分類が載る / 成功時にキーが null で載る」で担う)
 *   - **AST は使わない**。nikic/php-parser は vendor に存在するが直接依存ではなく
 *     transitive (phpstan / nette 経由) であり、composer の解決次第で消えうるものへ
 *     Architecture テストを依存させない (AGENTS.md 思考原則 1・2)。
 *     Reflection によるメソッド単位の切り出しで足りる
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
            // ★メソッド名 => そのメソッド内で期待する context() 呼び出し回数。
            //   ファイル全体の出現回数ではなく**メソッド単位**で検査する
            //   (ファイル総数だとコメント / 別文脈でも数が合えば green になる)。
            catchSites: [
                'terminateInvoiceBestEffort' => 1,  // 所有権喪失後の後始末 (T131 新設)
                'tryTerminateInvoice' => 1,         // 停止側の invoice 終端
                'reconcile' => 2,                   // attempt 隔離 + 取りこぼし起票
            ],
            rawMessageCap: 0,
            rationale: 'gateway 例外を catch して観測へ落とす唯一のクラス。4 箇所すべてが '
                .'GatewayFailureClassifier::context() の 2 キーだけを載せ、例外 message は載せない。'
                .'rawMessageCap=0 は gateway 観測点だけでなく**クラス全体**に掛かる設計制約である '
                .'(本クラスが受ける例外は gateway 以外も外部由来を含みうるため。'
                .'catch の近傍だけに限定すると走査が脆くなる)。'
                .'通知送信失敗を受ける applySetupCompletion / applyReusedPaymentMethod の '
                .'catch は gateway を消費しないため catchSites の対象外。',
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

```php
// tests/Support/Billing/GatewayObservationEntry.php
final class GatewayObservationEntry
{
    /**
     * @param  array<string, int>  $catchSites  メソッド名 => 期待する context() 呼び出し回数
     * @param  int  $rawMessageCap  当該クラスのソースに現れてよい `getMessage()` の件数 (exact fit)
     */
    public function __construct(
        public readonly array $catchSites,
        public readonly int $rawMessageCap,
        public readonly string $rationale,
    ) {}
}
```

**検査一覧（test 名）**

| # | 検査 | 落ちる mutation |
|---|---|---|
| 1 | gateway を注入される app クラスが全件分類されている（未分類 / 実在しない目録 entry は fail） | M8 |
| 2 | 観測目録と免除は排他 | — |
| 3 | 免除件数が cap と一致（exact fit = 3） | M9 |
| 4 | 目録・免除の根拠が 30 文字以上 | — |
| 5 | 目録 entry の `catchSites` のキーがすべて実在するメソッド（Reflection）で、値が 1 以上 | — |
| 6 | 目録 entry のクラスのソースの `getMessage()` 件数が `rawMessageCap` と**一致**（exact fit = 0） | M7 |
| 7a | **メソッド単位**: `catchSites` の各メソッドについて、`ReflectionMethod` の行範囲で切り出したソースが `catch (` を含み、`GatewayFailureClassifier::context(` の出現回数が宣言値と一致する | M10 |
| 7b | ファイル全体の `GatewayFailureClassifier::context(` 出現回数 == `array_sum(catchSites)`（宣言外メソッドへの追加を検出） | M10 |
| 8 | `keys(directMap) ∩ conditionalClasses = ∅` | — |
| 9 | `keys(directMap) ∪ conditionalClasses == vendorConcreteClasses ∪ nonVendorExplicitClasses` | M1 / M2 |
| 10 | `conditionalClasses() === [UnknownApiErrorException::class]`（クラス同一性） | M4 |
| 11 | `directMap()` の値に `GatewayFailureClass::Unknown` が現れない | M3 |
| 12 | `nonVendorExplicitClasses` の件数が cap と一致（exact fit = 3） | — |
| 13a | 実サブディレクトリ集合 == `array_keys(EXCLUDED_STRIPE_SUBNAMESPACES)`（SDK がサブ名前空間を増やしたら赤くなり、母集団定義の再検討が強制される）。`Laravel\Cashier\Exceptions\` はサブディレクトリ `[]` | — |
| 13b | 除外理由が **30 文字以上** | — |
| 13c | 直下母集団の各クラスが**除外名前空間に属さない**（`Stripe\Exception\OAuth\` 由来のクラスが母集団へ混入していない = 集合の非交差） | — |
| 13d | 走査結果が代表クラス（`ApiConnectionException` / `IncompletePayment`）を含む（**縮み検出**） | — |
| 14 | fixture の case 集合 == `GatewayFailureClass::cases()` − `Unknown`（exact fit） | — |
| 15 | 全 fixture について `classify(fixture(case)) === case` | M5 |
| 16 | fixture が返すクラスが `ALLOWED_NAMESPACE_PREFIXES` に属する | M5 |
| 17 | spy（`Tests\Support\FakeAutoRechargeGateway`）のソースの `throw ` 出現回数 == `throw GatewayFailureFixtures::throwableFor(` の出現回数 | M6 |
| 17b | 全 fixture の `getMessage()` に `EXTERNAL_MESSAGE_MARKER` が含まれる（negative assertion の前提保証） | — |
| 17c | 旧 API 名（`failOnTerminate` / `failOnResolveSubscriptionPaymentMethod`）が **本 gate ファイル自身を除く `tests/` 配下の PHP ファイル**に 0 件（思考原則 3 の機械化）。★除外は文字列一致ではなく **`realpath(__FILE__)` で正規化して比較**する（OS / パス区切りによる意図しない自己検出を避ける） | — |
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

母集団から外すサブ名前空間は**宣言済み定数 + 根拠**で持つ:

```php
/** 母集団から外す Stripe のサブ名前空間 (根拠付き。gate がサブディレクトリ集合と突き合わせる) */
public const array EXCLUDED_STRIPE_SUBNAMESPACES = [
    'OAuth' => 'Stripe Connect の OAuth 専用。本アプリは Connect を使わないため gateway 経路から到達しない',
];
```

gate は (a) 実サブディレクトリ集合 == `array_keys(EXCLUDED_STRIPE_SUBNAMESPACES)`、
(b) 除外理由が 30 文字以上、(c) **直下母集団の各クラスが除外名前空間に属さない**
（集合の非交差）を検査する。SDK がサブ名前空間を増やしたら (a) が赤くなり、
母集団定義の再検討が強制される。

> **「OAuth 配下に具象例外が 0 件であること」は要求しない**。母集団が直下の `*.php` だけなら
> `OAuth/` 配下は最初から母集団に入らず、この要求は**定義上自明**で検査の意味が無い。
> しかも OAuth 配下には実際に具象例外が存在するため、要求すると「除外する」という
> 設計意図そのものと矛盾する。非自明な保証は (a)(b)(c) の 3 本である。

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

/**
 * ★**期待値は分類器と独立に手書きで宣言する**。
 *   `directMap()` をそのまま dataset にすると、期待値と実装が同一ソースになり
 *   **写像を間違えても常に green** になる (既存 gate の「目録と期待値 map の二重宣言」と同じ作法)。
 * ★件数は固定定数で持たない。**キー集合一致の検査が正本**である
 *   (件数を別に持つと、片方だけ直したときに嘘の安心を与える)。
 *
 * @return array<class-string<Throwable>, GatewayFailureClass>
 */
function billingTaxonomyExpectedClassification(): array
{
    return [
        ApiConnectionException::class => GatewayFailureClass::ProviderUnavailable,
        RateLimitException::class => GatewayFailureClass::ProviderUnavailable,
        InvalidRequestException::class => GatewayFailureClass::ProviderRejected,
        // … directMap() の**全 entry** を手書きで列挙する
        //    (Stripe 12 + Cashier 8 + 非 vendor 3 = 23。UnknownApiErrorException は
        //     conditionalClasses() 側なのでここには入らない)。
        //    ★件数を定数で別途持たない — 正本はキー集合一致の検査である …
    ];
}

dataset('分類の期待値 (独立宣言)', function (): Generator {
    foreach (billingTaxonomyExpectedClassification() as $class => $expected) {
        yield $class => [$class, $expected];
    }
});

test('各クラスが期待どおりに分類される', function (string $class, GatewayFailureClass $expected): void {
    // クラスごとの生成ヘルパ (factory / constructor が違うため match で分ける)
    $throwable = billingTaxonomyInstantiate($class);

    expect(GatewayFailureClassifier::classify($throwable))->toBe($expected);
})->with('分類の期待値 (独立宣言)');

test('期待値表と directMap のキー集合が一致する (書き忘れ / 余剰の検出)', function (): void {
    $expected = array_keys(billingTaxonomyExpectedClassification());
    $actual = array_keys(GatewayFailureClassifier::directMap());
    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected);
});

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

test('context はキー集合と値が完全一致する (message は入り得ない)', function (): void {
    $context = GatewayFailureClassifier::context(
        ApiConnectionException::factory(GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER),
    );

    // ★キー集合と各値を**完全一致**で固定する。
    //   これ以外の値が入り得ないので、マーカー非含有は自明になる
    //   (json_encode して部分文字列を否定する形は array shape の検査として過剰)。
    expect($context)->toBe([
        'failure_class' => 'provider_unavailable',
        'error_class' => ApiConnectionException::class,
    ]);
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
                // ★マーカー非含有。gate が「fixture の message にマーカーが確かに入る」ことを
                //   保証しているため、この negative assertion は空虚にならない。
                && ! str_contains(
                    json_encode($context, JSON_THROW_ON_ERROR),
                    GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER,
                );
        })
        ->once();
});

test('後始末の例外報告は固定テンプレートと完全一致する', function (): void {
    // ★部分一致をやめる。予期しない文字列の追加を必ず検出する。
    Exceptions::assertReported(function (RuntimeException $reported) use ($invoiceId): bool {
        return $reported->getMessage() === sprintf(
            'auto-recharge: invoice %s の終端に失敗しました (%s / %s)',
            $invoiceId,
            'provider_unavailable',
            ApiConnectionException::class,
        ) && $reported->getPrevious() === null;
    });
    Exceptions::assertReportedCount(1);
});

test('制御フロー等価性: 分類ログを出しても収束先と gateway 呼び出し回数が変わらない', function (): void {
    // 終端失敗 → attempt は pending 維持 / terminate は 1 回だけ呼ばれる /
    // 課金 (pay) には進まない、を明示的に固定する
});

test('cleanup event のキー集合が成功・失敗の両方で同一である', function (): void {
    // ★集計 schema が成否で割れないことを固定する。
    //   成功時は failure_class / error_class が **null で存在**する。
    expect(array_keys($successContext))->toBe(array_keys($failureContext));
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
(原因の分類は同ログの **`failure_class`** = `GatewayFailureClass`、**`error_class`** = 例外クラス名。
**成功時も両キーは `null` で存在する**（集計 schema を成否で割らない）。
`report()` 側にも invoice id とこの 2 値だけを持つサニタイズ済み例外しか流れないため、
**この cleanup 経路で本サービスが出す構造化ログと report message には
Stripe が生成した原メッセージが残らない**
（`report()` の stack trace / vendor 側の別ログ / 伝播した queue failure は本保証の範囲外）。
`tryTerminateInvoice()` / `reconcile()` も同じ 2 キーへ統一済み。
詳細が要るときは `invoice_id` で Stripe 側を直接確認する) | … |
```

`AGENTS.md` 追記（ドメイン固有規約 **7** として）。

> **実装手順（番号衝突の防止）**: **既存末尾へ 7 として追加する。既存 1〜6 は renumber しない。**
> 実装時点で同趣旨の項目が既に存在する場合は、追記ではなく**その項目を更新**すること
> （AGENTS.md は他タスクも触るため、マージ時に番号がずれていないか必ず確認する）。


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
- 保証範囲の誇張（「アプリのどこにも残らない」）→ 文言を
  「**本サービスが出す構造化ログと report message には残らない**」に限定した。
  `report()` の stack trace / vendor 側ログ / 伝播した queue failure は範囲外であると明記する。
- `AGENTS.md` の番号衝突 → 「末尾へ 7 として追加。1〜6 は renumber しない」を実装手順に明記。

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


## 実装差分 (git diff)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index a5918cc..8509234 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -363,3 +363,12 @@ ## ドメイン固有規約
    保証を代替できる長さに伸ばさない (`JobExclusionOrderingInvariantTest` が
    `retry_after` 未満を固定)。**閉じない窓と運用上の所有者**は `docs/architecture.md`
    §ジョブの重複実行と結果の一回性 が正本。
+7. **決済 gateway 失敗の観測語彙**: `AutoRechargeGatewayInterface` を注入されるクラスは、
+   gateway 例外を **観測する (`GatewayFailureClassifier::context()` の
+   `failure_class` / `error_class` の 2 キーだけをログへ載せる)** か、
+   **伝播させる (`GatewayFailureObservationExemption` + 30 文字以上の根拠で免除登録)** かの
+   どちらかに目録登録が必須 (`BillingGatewayFailureTaxonomyInventoryTest` が
+   deny-by-default で強制)。**例外 message はログに載せない** (外部生成の可変文字列)。
+   分類は**観測のためであり制御フローを変えない**。`unknown` は「写像表に一致が無かった」
+   ことを意味し、写像表の値としては禁止。詳細と運用契約は
+   `docs/architecture.md` §オートリチャージの失敗分類。
diff --git a/app/Enums/Billing/GatewayFailureClass.php b/app/Enums/Billing/GatewayFailureClass.php
new file mode 100644
index 0000000..af1285c
--- /dev/null
+++ b/app/Enums/Billing/GatewayFailureClass.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * 決済 gateway 消費経路で観測された失敗の分類。
+ *
+ * ★語彙は「**呼び出し側 / 運用担当が取れる行動**」で切る。Stripe の error code を
+ *   そのまま採らない (外部語彙に依存すると増えたときに追随できない)。
+ * ★case を足す条件は「運用担当が取る行動が既存 case と異なる」ことだけ。
+ *   分類の粒度を過剰にしない (AGENTS.md 思考原則 2)。
+ * ★**この分類は観測のためであり、制御フローを変えない。**
+ *   分岐に使いたくなったら、そのときは型 (ドメイン例外) を検討し直すこと。
+ * ★カード拒否 (`card_declined` / `authentication_required`) は本 enum の担当ではない。
+ *   既に `OffSessionChargeResultDto` の typed 結果が持っている (語彙を二重管理しない)。
+ */
+enum GatewayFailureClass: string
+{
+    /** 決済事業者側の一時的な不能 (接続断・タイムアウト・レート制限・5xx)。同じ要求の再送で収束しうる */
+    case ProviderUnavailable = 'provider_unavailable';
+
+    /** 決済事業者が要求を受理しなかった。同じ要求を再送しても収束しない (要求内容・認証情報・利用者操作のいずれかが要る) */
+    case ProviderRejected = 'provider_rejected';
+
+    /** アプリ自身が検出した不変条件違反 (Assert / 明示的な例外 / SDK・Cashier の誤用) */
+    case InvariantViolation = 'invariant_violation';
+
+    /** 自インフラ層 (DB / cache) が返した失敗。障害・SQL 不備・制約違反のいずれもありうる */
+    case LocalFailure = 'local_failure';
+
+    /**
+     * **写像表に一致が無かった**。
+     *
+     * ★この case が出ること自体が「分類器に欠落がある」という通知である。
+     *   したがって**写像表の値として使ってはならない** (登録済みなのに unknown、という
+     *   状態を作ると運用契約「unknown が出たら表へ足せ」と矛盾する)。
+     *   `BillingGatewayFailureTaxonomyInventoryTest` が機械で禁止する。
+     */
+    case Unknown = 'unknown';
+}
diff --git a/app/Enums/Security/GatewayFailureObservationExemption.php b/app/Enums/Security/GatewayFailureObservationExemption.php
new file mode 100644
index 0000000..bc3a6a1
--- /dev/null
+++ b/app/Enums/Security/GatewayFailureObservationExemption.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 「決済 gateway を注入されるが、gateway 例外を**観測しない**ことが正しい」と裁定された理由の分類。
+ *
+ * `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php` が deny-by-default で
+ * 「観測目録に登録する」か「本 enum + 具体的根拠付きの exemption」かを機械強制する。
+ *
+ * ★置き場所は既存の gate 語彙 enum (ThrottleCoverageExemption / JobDedupExemption /
+ *   DirectFetchJustification / ControllerAuthorizationExemption / NestedRouteDefenseMode) と揃える。
+ */
+enum GatewayFailureObservationExemption: string
+{
+    /**
+     * gateway 例外を catch せず**伝播させる**。
+     *
+     * 適用条件: クラス内に gateway 呼び出しを囲む catch が 1 つも無く、失敗が
+     * キューの再試行 / `failed_jobs` に載ることで可観測性が担保されること。
+     * ★根拠欄には「catch しないから安全」ではなく
+     *   **「catch しない結果どこに何が残るか」**を書くこと
+     *   (伝播先には vendor 例外の message が載る = 本設計の保証範囲外である)。
+     */
+    case PropagatesToQueueFailure = 'propagates_to_queue_failure';
+}
diff --git a/app/Services/Billing/AutoRechargeService.php b/app/Services/Billing/AutoRechargeService.php
index 97263f2..91092d7 100644
--- a/app/Services/Billing/AutoRechargeService.php
+++ b/app/Services/Billing/AutoRechargeService.php
@@ -27,6 +27,7 @@
 use App\Notifications\Billing\AutoRechargeEnabledNotification;
 use App\Notifications\Billing\AutoRechargeFailedNotification;
 use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use App\Support\Billing\GatewayFailureClassifier;
 use App\Support\JobExecution\AttemptOwnershipPreflight;
 use Carbon\CarbonImmutable;
 use Illuminate\Contracts\Cache\LockTimeoutException;
@@ -680,10 +681,12 @@ private function terminateUnattachedInvoice(
      *   手動収束に委ねる。
      * ★ **cleanup 専用の event 名**を使う。送信抑止の記録 (`LOG_EVENT`) は最小 7 キー schema を
      *   持つ契約であり、キー集合の違うログを同じ event 名に混ぜない。
-     * ★ `error` に入れるのは**例外クラス名だけ**である (impl-review Round 2/3 反映)。
+     * ★ ログに載せるのは `GatewayFailureClassifier` が返す**2 キーだけ**である
+     *   (`failure_class` = 有界な分類 / `error_class` = 例外クラス名。T132)。
      *   Stripe SDK の例外メッセージは**外部サービスが生成する可変文字列**であり、
      *   いま既知の内容が invoice id と status だけでも、将来の SDK / API 応答で
      *   何が混ざるかの契約は無い。構造化ログには**アプリが決めた有界な語彙**だけを載せる。
+     *   ★成功時も 2 キーは **null で存在させる** (集計 schema を成否で割らない)。
      * ★ 例外報告も**原例外を渡さない** (impl-review Round 3 反映)。
      *   標準の exception handler は message とスタックトレースを記録するため、
      *   `report($exception)` では「保存場所を移しただけ」で外部生成文字列が残る。
@@ -696,17 +699,22 @@ private function terminateInvoiceBestEffort(
         string $invoiceId,
     ): void {
         $terminated = true;
-        $error = null;
+        $failure = null;
         try {
             $this->gateway->terminateInvoice($invoiceId);
         } catch (Throwable $exception) {
             $terminated = false;
-            // paid 等の「明示的な非成功」もここに落ちる。分類できる有界な値 (クラス名) のみ記録する。
-            $error = $exception::class;
+            // paid 等の「明示的な非成功」もここに落ちる。有界な 2 キー (分類 + 例外クラス名) のみ記録する。
+            $failure = GatewayFailureClassifier::context($exception);
             // 原例外は報告しない (外部生成メッセージ / previous chain をログ基盤へ流さない)。
-            report(new RuntimeException(
-                "auto-recharge: invoice {$invoiceId} の終端に失敗しました ({$error})",
-            ));
+            // ★文言は**固定テンプレート**にする。report message は集計語彙になりうるため、
+            //   Feature テストが**完全一致**で固定する (部分一致だと文字列の追加を検出できない)。
+            report(new RuntimeException(sprintf(
+                'auto-recharge: invoice %s の終端に失敗しました (%s / %s)',
+                $invoiceId,
+                $failure['failure_class'],
+                $failure['error_class'],
+            )));
         }
 
         Log::warning('auto-recharge: 所有権喪失後の invoice 終端', [
@@ -716,7 +724,10 @@ private function terminateInvoiceBestEffort(
             'attempt_ulid' => $attempt->attempt_ulid,
             'invoice_id' => $invoiceId,
             'terminated' => $terminated,
-            'error' => $error,
+            // ★成功時も**キーは常に存在させる** (集計 schema を安定させる。値は null)。
+            //   ここだけ spread を使わないのはこのためである。
+            'failure_class' => $failure['failure_class'] ?? null,
+            'error_class' => $failure['error_class'] ?? null,
         ]);
     }
 
@@ -830,10 +841,10 @@ private function tryTerminateInvoice(TicketAutoRechargeAttempt $attempt): bool
             Log::warning('auto-recharge: invoice termination failed, keeping attempt pending', [
                 'attempt_ulid' => $attempt->attempt_ulid,
                 'invoice_id' => $attempt->stripe_invoice_id,
-                'error' => $e->getMessage(),
+                ...GatewayFailureClassifier::context($e),
             ]);
 
-            return false;
+            return false; // ★制御フローは現行のまま (pending 維持 → リコンサイル再試行)
         }
     }
 
@@ -991,7 +1002,7 @@ public function reconcile(): array
                 // 1 attempt の失敗が他 org の回収を止めないよう隔離 (次周期で再試行)。
                 Log::warning('auto-recharge reconcile: attempt processing failed', [
                     'attempt_ulid' => $attempt->attempt_ulid,
-                    'error' => $e->getMessage(),
+                    ...GatewayFailureClassifier::context($e),
                 ]);
             }
         }
@@ -1011,7 +1022,7 @@ public function reconcile(): array
             } catch (Throwable $e) {
                 Log::warning('auto-recharge reconcile: trigger failed', [
                     'organization_id' => $organization->getKey(),
-                    'error' => $e->getMessage(),
+                    ...GatewayFailureClassifier::context($e),
                 ]);
             }
         }
diff --git a/app/Support/Billing/GatewayFailureClassifier.php b/app/Support/Billing/GatewayFailureClassifier.php
new file mode 100644
index 0000000..e87dc97
--- /dev/null
+++ b/app/Support/Billing/GatewayFailureClassifier.php
@@ -0,0 +1,174 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Billing;
+
+use App\Enums\Billing\GatewayFailureClass;
+use Illuminate\Contracts\Cache\LockTimeoutException;
+use Illuminate\Database\QueryException;
+use Laravel\Cashier\Exceptions\CustomerAlreadyCreated;
+use Laravel\Cashier\Exceptions\IncompletePayment;
+use Laravel\Cashier\Exceptions\InvalidCoupon;
+use Laravel\Cashier\Exceptions\InvalidCustomer;
+use Laravel\Cashier\Exceptions\InvalidCustomerBalanceTransaction;
+use Laravel\Cashier\Exceptions\InvalidInvoice;
+use Laravel\Cashier\Exceptions\InvalidPaymentMethod;
+use Laravel\Cashier\Exceptions\SubscriptionUpdateFailure;
+use Stripe\Exception\ApiConnectionException;
+use Stripe\Exception\AuthenticationException;
+use Stripe\Exception\BadMethodCallException as StripeBadMethodCallException;
+use Stripe\Exception\CardException;
+use Stripe\Exception\IdempotencyException;
+use Stripe\Exception\InvalidArgumentException as StripeInvalidArgumentException;
+use Stripe\Exception\InvalidRequestException;
+use Stripe\Exception\PermissionException;
+use Stripe\Exception\RateLimitException;
+use Stripe\Exception\SignatureVerificationException;
+use Stripe\Exception\TemporarySessionExpiredException;
+use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
+use Stripe\Exception\UnknownApiErrorException;
+use Throwable;
+use Webmozart\Assert\InvalidArgumentException as AssertInvalidArgumentException;
+
+/**
+ * 決済 gateway 消費経路で捕まえた Throwable を、有界な分類 (GatewayFailureClass) へ写す純関数。
+ *
+ * ★**Stripe / Cashier の例外型を知る唯一の非 gateway コンポーネント**である。
+ *   ここに集約することで「外部語彙が観測点へ散らばる」ことを防ぐ
+ *   (集約点が 2 つになったら語彙が割れる。gate が import の allowlist を固定する)。
+ * ★制御フローに使わない。分類は観測 (構造化ログ / 例外報告の文言) 専用である。
+ * ★`unknown` は「写像の不在」であり、`directMap()` の値には現れない
+ *   (`BillingGatewayFailureTaxonomyInventoryTest` が機械で禁止する)。
+ */
+final class GatewayFailureClassifier
+{
+    public static function classify(Throwable $throwable): GatewayFailureClass
+    {
+        // ★条件付き規則を先に判定する (唯一の特別扱い)。
+        //   UnknownApiErrorException は ApiRequestor::_specificV1APIError() の status switch の
+        //   `default:` 分岐であり、**Stripe の 5xx はすべてここに来る**。
+        //   「未知」なのは error type であって status ではないため、status で細分する。
+        if ($throwable instanceof UnknownApiErrorException) {
+            // ★vendor の PHPDoc は @return null|int だが**戻り型宣言は無い**。
+            //   `!== null` ではなく `is_int()` で narrowing して、PHPDoc の揺れに耐えさせる。
+            $status = $throwable->getHttpStatus();
+
+            if (is_int($status) && $status >= 500) {
+                return GatewayFailureClass::ProviderUnavailable;
+            }
+
+            // 4xx / その他 / null / 非 int。**運用上の保守的分類**であり、
+            // 再送可能性の完全な意味判定ではない。status 不明で ProviderUnavailable
+            // (= 待てば直る) と言うと**無行動を示唆する誤誘導**になるため「調べる」側へ倒す。
+            // 実際には factory が必ず status を受け取るため、null / 非 int は防御的分岐である。
+            return GatewayFailureClass::ProviderRejected;
+        }
+
+        $map = self::directMap();
+
+        // ★実クラス → 親クラス連鎖の順に最初の一致を採る (将来のサブクラスを取りこぼさない)。
+        //   グローバル SPL クラス (\RuntimeException 等) は表に入れないため、
+        //   Stripe\Exception\InvalidArgumentException と Webmozart\Assert\InvalidArgumentException が
+        //   共通祖先 \InvalidArgumentException で衝突することはない。
+        $class = $throwable::class;
+
+        do {
+            if (array_key_exists($class, $map)) {
+                return $map[$class];
+            }
+
+            // get_parent_class() は最上位クラスで false を返す (= 連鎖の終端)。
+            $class = get_parent_class($class);
+        } while ($class !== false);
+
+        return GatewayFailureClass::Unknown;
+    }
+
+    /**
+     * 構造化ログ / 例外報告に載せる 2 キー。
+     *
+     * ★観測点が**同じ綴りの同じ 2 キー**を出すことをコードの構造で担保する
+     *   (gate が「宣言した catch 箇所の数 == `context(` の出現回数」を exact fit で検査する)。
+     * ★`error_class` は外部サービスが生成する文字列ではない (値域はコードベース + vendor の
+     *   クラス名に閉じる)。**例外 message は載せない**。
+     *
+     * @return array{failure_class: string, error_class: class-string<Throwable>}
+     */
+    public static function context(Throwable $throwable): array
+    {
+        return [
+            'failure_class' => self::classify($throwable)->value,
+            'error_class' => $throwable::class,
+        ];
+    }
+
+    /**
+     * 直接写像 (class => case) の正本。
+     *
+     * ★根拠は推測ではなく **vendor の throw site**。Stripe 側は
+     *   `vendor/stripe/stripe-php/lib/ApiRequestor.php` の `_specificV1APIError()` の
+     *   HTTP status switch が正本 (400 => InvalidRequest / 400+idempotency_error => Idempotency /
+     *   400+rate_limit => RateLimit / 401 => Authentication / 402 => Card / 403 => Permission /
+     *   404 => InvalidRequest / 429 => RateLimit / default => UnknownApiError)。
+     *   `_specificV2APIError()` は temporary_session_expired のみ振り分けて V1 へ委譲する。
+     * ★**値に GatewayFailureClass::Unknown を置かない** (unknown は写像の不在専用)。
+     * ★**vendor 全件分類 gate のため、gateway 経路で通常発生しない Stripe 例外
+     *   (SignatureVerificationException = webhook 署名検証用 など) も観測語彙上は分類する。**
+     *   分類は「もし来たら何と呼ぶか」の宣言であって「来る」という主張ではない
+     *   (母集団に穴を空けると、SDK 更新で増えた例外が無音で unknown へ落ちる)。
+     *
+     * @return array<class-string<Throwable>, GatewayFailureClass>
+     */
+    public static function directMap(): array
+    {
+        return [
+            // --- Stripe SDK: 決済事業者側の一時的な不能 ---
+            ApiConnectionException::class => GatewayFailureClass::ProviderUnavailable, // HTTP 到達前の接続断
+            RateLimitException::class => GatewayFailureClass::ProviderUnavailable,     // 429 / 400+rate_limit
+
+            // --- Stripe SDK: 要求が受理されなかった ---
+            InvalidRequestException::class => GatewayFailureClass::ProviderRejected,           // 400 / 404
+            AuthenticationException::class => GatewayFailureClass::ProviderRejected,           // 401
+            CardException::class => GatewayFailureClass::ProviderRejected,                     // 402 (通常は typed 結果へ変換される)
+            PermissionException::class => GatewayFailureClass::ProviderRejected,               // 403
+            IdempotencyException::class => GatewayFailureClass::ProviderRejected,              // 400 + idempotency_error
+            TemporarySessionExpiredException::class => GatewayFailureClass::ProviderRejected,  // V2: temporary_session_expired
+            SignatureVerificationException::class => GatewayFailureClass::ProviderRejected,    // webhook 署名不一致 (gateway 経路では発生しない)
+
+            // --- Stripe SDK: SDK の誤用 = 自コードの欠陥 ---
+            StripeBadMethodCallException::class => GatewayFailureClass::InvariantViolation,
+            StripeInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
+            StripeUnexpectedValueException::class => GatewayFailureClass::InvariantViolation,
+
+            // --- Cashier ---
+            IncompletePayment::class => GatewayFailureClass::ProviderRejected,          // 追加認証 (SCA) が要る
+            CustomerAlreadyCreated::class => GatewayFailureClass::InvariantViolation,   // ManagesCustomer::createAsStripeCustomer
+            InvalidCustomer::class => GatewayFailureClass::InvariantViolation,          // ManagesCustomer::assertCustomerExists
+            InvalidPaymentMethod::class => GatewayFailureClass::InvariantViolation,     // PaymentMethod::__construct (invalidOwner)
+            InvalidInvoice::class => GatewayFailureClass::InvariantViolation,           // Invoice::__construct (invalidOwner)
+            InvalidCoupon::class => GatewayFailureClass::InvariantViolation,            // 本アプリは coupon を使わない
+            InvalidCustomerBalanceTransaction::class => GatewayFailureClass::InvariantViolation,
+            SubscriptionUpdateFailure::class => GatewayFailureClass::InvariantViolation, // Subscription::guardAgainst*
+
+            // --- 非 vendor 明示宣言 (reconcile の catch(Throwable) が実際に受けうるもの) ---
+            QueryException::class => GatewayFailureClass::LocalFailure,
+            LockTimeoutException::class => GatewayFailureClass::LocalFailure,
+            AssertInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
+        ];
+    }
+
+    /**
+     * 条件付き規則を持つクラス (直接写像に入れられないもの)。
+     *
+     * ★`directMap()` に入れると値がダミーになり「正本」が嘘をつくため分けている。
+     * ★gate が `=== [UnknownApiErrorException::class]` を**クラス同一性**で固定する
+     *   (件数だけだと別クラスへ差し替えても green になる)。
+     *
+     * @return list<class-string<Throwable>>
+     */
+    public static function conditionalClasses(): array
+    {
+        return [UnknownApiErrorException::class];
+    }
+}
diff --git a/docs/architecture.md b/docs/architecture.md
index 7a60cdb..b51e94b 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -338,7 +338,7 @@ ### ジョブの重複実行と結果の一回性
 
      | # | 発生条件 | 検知元 | 収束手順 |
      |---|---|---|---|
-     | (a) | 所有権喪失後の void / delete に失敗した | **アプリログ**: `event = job_ownership_lost_cleanup` かつ `terminated=false` (原因の分類は同ログの `error` = 例外クラス名。`report()` 側にも **invoice id と例外クラス名だけを持つサニタイズ済み例外**しか流れないため、**この cleanup 経路では Stripe が生成した原メッセージはアプリのどこにも残らない** (別経路の `tryTerminateInvoice()` は対象外)。詳細が要るときは `invoice_id` で Stripe 側を直接確認する) | 同ログの `invoice_id` を Stripe で確認し、`paid` でなければ手動 void |
+     | (a) | 所有権喪失後の void / delete に失敗した | **アプリログ**: `event = job_ownership_lost_cleanup` かつ `terminated=false` (原因の分類は同ログの **`failure_class`** = `GatewayFailureClass`、**`error_class`** = 例外クラス名。**成功時も両キーは `null` で存在する** (集計 schema を成否で割らない)。`report()` 側にも invoice id とこの 2 値だけを持つサニタイズ済み例外しか流れないため、**この cleanup 経路で本サービスが出す構造化ログと report message には Stripe が生成した原メッセージが残らない** (`report()` の stack trace / vendor 側の別ログ / 伝播した queue failure は本保証の範囲外)。`tryTerminateInvoice()` / `reconcile()` も同じ 2 キーへ統一済み。詳細が要るときは `invoice_id` で Stripe 側を直接確認する) | 同ログの `invoice_id` を Stripe で確認し、`paid` でなければ手動 void |
      | (b) | invoice 作成成功 → `stripe_invoice_id` の永続化前にワーカーが死亡した | **アプリログには何も残らない**。Stripe 側を起点に探す — metadata `purpose=auto_recharge` を持つ `draft` / `open` invoice を列挙し、その `recharge_attempt_ulid` に対応する `ticket_auto_recharge_attempts` 行の `stripe_invoice_id` が **NULL または別 id** のものが孤児 | **原則すべて手動終端の対象**とする。`paid` でないことを確認して void / delete する |
 
      > **(b) を「次の実行が拾うから放置してよい」と書かない** — Stripe の idempotency key は
@@ -374,6 +374,59 @@ ### ジョブの重複実行と結果の一回性
 | 所有権喪失時に invoice 作成・支払いを抑止し、必要な既作成 invoice を終端する | `AutoRechargeServiceTest` |
 | ログコンテキストに PII を含めない | `JobOwnershipLostContextTest` |
 | 固定 event 名の literal が 1 箇所に閉じる | `JobExecutionDedupInventoryTest` |
+| gateway を注入されるクラスが観測目録 or 免除に分類される / vendor 例外が全件分類される / `unknown` が写像表の値に現れない / fake の失敗注入が本物と同じ分類になる | `BillingGatewayFailureTaxonomyInventoryTest` |
+| 分類器の写像・境界 (`UnknownApiErrorException` の HTTP status) ・`context()` の array shape | `GatewayFailureClassifierTest` |
+| 失敗分類が実際にログへ載る / 成功時も null で存在する / 制御フローが変わらない | `AutoRechargeServiceTest` / `AutoRechargeReconcileTest` |
+
+### オートリチャージの失敗分類
+
+決済 gateway (`AutoRechargeGatewayInterface`) の消費経路で捕まえた例外は、
+`App\Support\Billing\GatewayFailureClassifier` が**有界な語彙**へ写してからログに載せる。
+**分類は観測のためであり、制御フローを変えない** (課金の振る舞いは分類の導入前後で同一)。
+
+| `failure_class` | 意味 | 運用担当が取る行動 |
+|---|---|---|
+| `provider_unavailable` | 決済事業者側の一時的な不能 (接続断・レート制限・5xx) | 同じ要求の再送で収束しうる。頻度を監視する |
+| `provider_rejected` | 決済事業者が要求を受理しなかった (400/401/402/403 等) | 再送しても収束しない。要求内容・認証情報・利用者操作を確認する |
+| `invariant_violation` | アプリ自身が検出した不変条件違反 (Assert / SDK・Cashier の誤用) | **アプリの欠陥**。コードを直す |
+| `local_failure` | 自インフラ層 (DB / cache) の失敗 | インフラを確認する |
+| `unknown` | **写像表に一致が無かった** | 下記「`unknown` の運用契約」 |
+
+ログに載るのは `failure_class` と `error_class` (例外クラス名) の **2 キーだけ**である。
+**例外 message は載せない** (外部サービスが生成する可変文字列であり、
+構造化ログの集計語彙にしない)。
+
+**`unknown` の運用契約 (所有者 = 課金運用担当)**
+
+- **検知条件**: `failure_class = unknown` を含む warning が 1 件でも出たら検知とみなす
+  (`unknown` は「分類器に欠落がある」という通知そのものであり、正常状態では出ない)。
+- **初動**: 同ログの `error_class` を見て、そのクラスを
+  `GatewayFailureClassifier::directMap()` (または条件付き規則) へ追加し、
+  `GatewayFailureClassifierTest` の期待値表にも**独立に**書く。
+  **`unknown` を写像表の値として書いてはならない** (gate が機械的に禁止する)。
+- vendor 由来のクラスなら `BillingGatewayFailureTaxonomyInventoryTest` の検査 9 が
+  同時に赤くなっているはずなので、CI の赤と突き合わせる。
+
+**vendor 更新 (`composer update`) で gate が赤くなったときの手順**
+
+`BillingGatewayFailureTaxonomyInventoryTest` は stripe-php / cashier の例外クラス集合を走査し
+「写像表 == 実在クラス集合」を要求する。**依存更新で赤くなるのは意図した費用**であり
+(外部の語彙が増えたことを人間に必ず知らせるための仕掛け)、soft-fail 化しない。
+
+1. 検査 9 の失敗メッセージが挙げるクラス名を確認する。
+2. 増えたクラスは vendor の throw site を読んで**行動で切る**分類を決め、
+   `GatewayFailureClassifier::directMap()` と `GatewayFailureClassifierTest` の期待値表の
+   **両方**へ追加する (二重宣言なのは、片方だけでは写像を間違えても green になるため)。
+   HTTP status 等の条件で分岐が要るものだけ `conditionalClasses()` 側へ置く。
+3. 消えたクラスは両方から削除する。
+4. 検査 13 (a) が赤い場合は SDK が**サブ名前空間を増減させた**ことを意味する。
+   `VendorExceptionPopulation::EXCLUDED_STRIPE_SUBNAMESPACES` に
+   30 文字以上の根拠付きで宣言するか、母集団定義そのものを再検討する。
+
+**Stripe 例外型を知ってよいクラス**は gateway 実装 3 本
+(`CashierStripeGateway` / `CashierAutoRechargeGateway` / `StripeScheduleGateway`) と
+`GatewayFailureClassifier` の**計 4 つに閉じる** (検査 19 が allowlist で固定)。
+集約点が増えると観測語彙が割れるため、新しい観測点を作らず分類器を使うこと。
 
 ### AI 解析ジョブの運用契約
 
diff --git a/tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php b/tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php
new file mode 100644
index 0000000..d44de44
--- /dev/null
+++ b/tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php
@@ -0,0 +1,517 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\GatewayFailureClass;
+use App\Enums\Security\GatewayFailureObservationExemption;
+use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
+use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
+use App\Jobs\Billing\SetDefaultPaymentMethodJob;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\CashierAutoRechargeGateway;
+use App\Services\Billing\CashierStripeGateway;
+use App\Services\Billing\StripeScheduleGateway;
+use App\Support\Billing\GatewayFailureClassifier;
+use Illuminate\Contracts\Cache\LockTimeoutException;
+use Illuminate\Database\QueryException;
+use Laravel\Cashier\Exceptions\IncompletePayment;
+use Stripe\Exception\ApiConnectionException;
+use Stripe\Exception\UnknownApiErrorException;
+use Tests\Support\Billing\GatewayConsumerPopulation;
+use Tests\Support\Billing\GatewayFailureFixtures;
+use Tests\Support\Billing\GatewayObservationEntry;
+use Tests\Support\Billing\GatewayObservationExemptionEntry;
+use Tests\Support\Billing\VendorExceptionPopulation;
+use Tests\Support\FakeAutoRechargeGateway;
+use Tests\Support\QueuedJobPopulation;
+use Webmozart\Assert\Assert;
+use Webmozart\Assert\InvalidArgumentException as AssertInvalidArgumentException;
+
+/*
+ * 決済 gateway 消費経路の「失敗分類の語彙」を deny-by-default で固定する。
+ *
+ * ★この gate が保証するもの:
+ *   - gateway を注入される app クラスが全件「観測目録」か「免除」に分類されている
+ *   - vendor (Stripe / Cashier) の例外クラスが全件、写像表か条件付き規則に属する
+ *   - `unknown` が写像表の値に現れない (= unknown は写像の不在専用)
+ *   - 条件付き規則のクラスがクラス同一性で 1 件に固定されている
+ *   - fake の失敗注入が本物と同じ分類を返す (fixture 経由・実ライブラリ例外)
+ *   - **fixture の message に外部生成文字列の目印が確かに入っている**
+ *     (negative assertion が空虚に green にならないための前提保証)
+ *   - 観測目録のクラスが例外 message をログへ載せない (getMessage() の cap)。
+ *     ★これは gateway 観測点だけでなく**クラス全体**に掛かる設計制約である
+ *       (対象クラスは gateway 以外の外部由来例外も受けうる。catch 近傍だけに限ると走査が脆い)。
+ *       将来正当な必要が出たら rawMessageCap の変更が必ず差分に現れる
+ *   - 旧 API (`failOnTerminate` 等) の残存が **本 gate ファイル自身 (= リテラルの正本) を除いて**
+ *     0 件 (思考原則 3 の機械化)。★除外しないと**検査コード自身が hit して必ず失敗する**
+ *
+ * ★この gate が保証しないもの:
+ *   - catch が「gateway 呼び出しを囲んでいる」こと (メソッド単位までは絞るが、
+ *     catch の**中**で呼ばれているかは検査しない。配置の保証は Feature テスト =
+ *     AutoRechargeServiceTest / AutoRechargeReconcileTest が
+ *     「失敗時に分類が載る / 成功時にキーが null で載る」で担う)
+ *   - **AST は使わない**。nikic/php-parser は vendor に存在するが直接依存ではなく
+ *     transitive (phpstan / nette 経由) であり、composer の解決次第で消えうるものへ
+ *     Architecture テストを依存させない (AGENTS.md 思考原則 1・2)。
+ *     Reflection によるメソッド単位の切り出しで足りる
+ *   - 期待値と目録を**同時に**消す変更 (宣言的 gate の性質。目的は
+ *     「1 箇所の削除では通らない = レビューで必ず 2 箇所の差分が見える」こと)
+ *
+ * 運用契約: docs/architecture.md §オートリチャージの失敗分類
+ */
+
+const BILLING_GATEWAY_TAXONOMY_MUTATION_COVERAGE = [
+    'M1' => '写像表から entry を 1 つ削ると vendor 集合一致が赤くなる',
+    'M2' => '写像表に実在しないクラスを足すと集合一致が赤くなる',
+    'M3' => '写像表の値に Unknown を置くと赤くなる',
+    'M4' => 'conditionalClasses を別クラスへ差し替えると赤くなる',
+    'M5' => 'fixture の 1 case を独自 RuntimeException にすると分類一致 / 名前空間が赤くなる',
+    'M6' => 'spy に fixture 経由でない throw を戻すと赤くなる',
+    'M7' => 'AutoRechargeService に $e->getMessage() を戻すと赤くなる',
+    'M8' => '観測目録から AutoRechargeService を消すと未分類で赤くなる',
+    'M9' => '免除 cap を書き換えると赤くなる',
+    'M10' => 'context() の呼び出しを 1 つ削ると出現回数の exact fit が赤くなる',
+];
+
+/** @return array<class-string, GatewayObservationEntry> */
+function billingGatewayObservers(): array
+{
+    return [
+        AutoRechargeService::class => new GatewayObservationEntry(
+            // ★メソッド名 => そのメソッド内で期待する context() 呼び出し回数。
+            //   ファイル全体の出現回数ではなく**メソッド単位**で検査する
+            //   (ファイル総数だとコメント / 別文脈でも数が合えば green になる)。
+            catchSites: [
+                'terminateInvoiceBestEffort' => 1,  // 所有権喪失後の後始末 (T131 新設)
+                'tryTerminateInvoice' => 1,         // 停止側の invoice 終端
+                'reconcile' => 2,                   // attempt 隔離 + 取りこぼし起票
+            ],
+            rawMessageCap: 0,
+            rationale: 'gateway 例外を catch して観測へ落とす唯一のクラス。4 箇所すべてが '
+                .'GatewayFailureClassifier::context() の 2 キーだけを載せ、例外 message は載せない。'
+                .'rawMessageCap=0 は gateway 観測点だけでなく**クラス全体**に掛かる設計制約である '
+                .'(本クラスが受ける例外は gateway 以外も外部由来を含みうるため。'
+                .'catch の近傍だけに限定すると走査が脆くなる)。'
+                .'通知送信失敗を受ける applySetupCompletion / applyReusedPaymentMethod の '
+                .'catch は gateway を消費しないため catchSites の対象外。',
+        ),
+    ];
+}
+
+/** @return array<class-string, GatewayObservationExemptionEntry> */
+function billingGatewayObservationExemptions(): array
+{
+    return [
+        SetDefaultPaymentMethodJob::class => new GatewayObservationExemptionEntry(
+            GatewayFailureObservationExemption::PropagatesToQueueFailure,
+            'gateway 例外を catch せず伝播させる。失敗は queue の再試行と failed_jobs に載り、'
+            .'そこには vendor 例外の message が残る (本設計の保証範囲は AutoRechargeService の'
+            .'構造化ログと report 文言までであり、伝播先の redact は横断基盤の話でスコープ外)。',
+        ),
+        ReuseSubscriptionPaymentMethodJob::class => new GatewayObservationExemptionEntry(
+            GatewayFailureObservationExemption::PropagatesToQueueFailure,
+            'gateway 例外 (resolveSubscriptionPaymentMethod) を catch せず伝播させる。'
+            .'失敗は queue の再試行と failed_jobs に載り、そこには vendor 例外の message が残る。'
+            .'サブスク PM 再利用は失敗しても業務が止まらない補助経路であり、'
+            .'観測点をここに増やすと語彙の集約点が割れる。',
+        ),
+        HandleAutoRechargeChargeFailureJob::class => new GatewayObservationExemptionEntry(
+            GatewayFailureObservationExemption::PropagatesToQueueFailure,
+            'gateway 例外 (retrieveInvoiceState / terminateInvoice) を catch せず伝播させる。'
+            .'失敗は queue の再試行と failed_jobs に載り、そこには vendor 例外の message が残る。'
+            .'終端の再試行はキューに委ね、本 Job 内で握り潰さない (fail-closed)。',
+        ),
+    ];
+}
+
+function billingGatewayObservationExemptionCap(): int
+{
+    return 3; // exact fit
+}
+
+/**
+ * 非 vendor の明示宣言クラス (期待値の正本。分類器の写像表とは**独立した宣言**)。
+ *
+ * ★framework 由来に限定しない。`unknown` の運用契約 (出たクラスは必ず写像表へ足す) により、
+ *   将来アプリ自身の例外クラスがここへ入りうる。
+ *
+ * @return list<class-string<Throwable>>
+ */
+function billingNonVendorExplicitClasses(): array
+{
+    return [
+        QueryException::class,
+        LockTimeoutException::class,           // Illuminate\Contracts\Cache\LockTimeoutException (具象クラス)
+        AssertInvalidArgumentException::class,
+    ];
+}
+
+function billingNonVendorExplicitCap(): int
+{
+    return 3; // exact fit
+}
+
+/** `Stripe\Exception\` を import してよい app クラス (集約点の allowlist)。 */
+function billingStripeExceptionImportAllowlist(): array
+{
+    return [
+        CashierAutoRechargeGateway::class,
+        CashierStripeGateway::class,
+        GatewayFailureClassifier::class,
+        StripeScheduleGateway::class,
+    ];
+}
+
+/** クラスのソースを読む (Reflection で実ファイルを特定する)。 */
+function billingGatewaySourceOf(string $class): string
+{
+    $path = (new ReflectionClass($class))->getFileName();
+    Assert::string($path, "{$class}: ソースファイルを特定できません");
+    $source = file_get_contents($path);
+    Assert::string($source, "{$class}: ソースを読み込めません");
+
+    return $source;
+}
+
+/** メソッド本体のソースを行範囲で切り出す (AST を使わない割り切り)。 */
+function billingGatewayMethodSource(string $class, string $method): string
+{
+    $reflection = new ReflectionMethod($class, $method);
+    $start = $reflection->getStartLine();
+    $end = $reflection->getEndLine();
+    Assert::integer($start, "{$class}::{$method}: 開始行を特定できません");
+    Assert::integer($end, "{$class}::{$method}: 終了行を特定できません");
+
+    $lines = explode("\n", billingGatewaySourceOf($class));
+
+    return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
+}
+
+// ---------------------------------------------------------------------------
+// 検査 1〜5: 観測目録 / 免除の deny-by-default
+// ---------------------------------------------------------------------------
+
+test('検査 1: gateway を注入される app クラスが全件分類されている (未分類は fail)', function (): void {
+    $scanned = GatewayConsumerPopulation::classes();
+    $classified = array_merge(
+        array_keys(billingGatewayObservers()),
+        array_keys(billingGatewayObservationExemptions()),
+    );
+    sort($classified);
+
+    $missing = array_values(array_diff($scanned, $classified));
+    $stale = array_values(array_diff($classified, $scanned));
+
+    expect($missing)->toBe([], '未分類の gateway 消費クラスがある: '.implode(', ', $missing));
+    expect($stale)->toBe([], '目録に実在しないクラスが残っている: '.implode(', ', $stale));
+
+    // ★走査の縮み検出 (母集団が空に落ちても green にならない)
+    expect($scanned)->toContain(AutoRechargeService::class);
+    expect($scanned)->toContain(SetDefaultPaymentMethodJob::class);
+});
+
+test('検査 2: 観測目録と免除は排他 (同じクラスが両方に居ない)', function (): void {
+    $both = array_intersect(
+        array_keys(billingGatewayObservers()),
+        array_keys(billingGatewayObservationExemptions()),
+    );
+
+    expect(array_values($both))->toBe([]);
+});
+
+test('検査 3: 免除件数が cap と一致する (形骸化ガード)', function (): void {
+    expect(count(billingGatewayObservationExemptions()))->toBe(
+        billingGatewayObservationExemptionCap(),
+        '免除件数が宣言と一致しません。増減させたら billingGatewayObservationExemptionCap() も書き換えること',
+    );
+});
+
+test('検査 4: 目録・免除の根拠が 30 文字以上 (constructor と gate の二重固定)', function (): void {
+    foreach (billingGatewayObservers() as $class => $entry) {
+        expect(mb_strlen($entry->rationale))->toBeGreaterThanOrEqual(30, "{$class}: 観測目録の根拠が短すぎます");
+    }
+
+    foreach (billingGatewayObservationExemptions() as $class => $entry) {
+        expect(mb_strlen($entry->rationale))->toBeGreaterThanOrEqual(30, "{$class}: 免除の根拠が短すぎます");
+    }
+});
+
+test('検査 5: catchSites のキーが実在メソッドで、期待回数が 1 以上', function (): void {
+    foreach (billingGatewayObservers() as $class => $entry) {
+        $reflection = new ReflectionClass($class);
+        foreach ($entry->catchSites as $method => $expected) {
+            expect($reflection->hasMethod($method))->toBeTrue("{$class}::{$method} が実在しません");
+            expect($expected)->toBeGreaterThanOrEqual(1, "{$class}::{$method}: 期待回数は 1 以上で宣言すること");
+        }
+    }
+});
+
+// ---------------------------------------------------------------------------
+// 検査 6〜7: 観測点の形 (message を載せない / 分類器を必ず通す)
+// ---------------------------------------------------------------------------
+
+test('検査 6: 観測目録のクラスは例外 message をログへ載せない (getMessage の cap と一致)', function (): void {
+    foreach (billingGatewayObservers() as $class => $entry) {
+        expect(substr_count(billingGatewaySourceOf($class), 'getMessage()'))->toBe(
+            $entry->rawMessageCap,
+            "{$class}: getMessage() の出現件数が rawMessageCap と一致しません",
+        );
+    }
+});
+
+test('検査 7a: catchSites の各メソッドが catch を持ち、context() の回数が宣言と一致する', function (): void {
+    foreach (billingGatewayObservers() as $class => $entry) {
+        foreach ($entry->catchSites as $method => $expected) {
+            $source = billingGatewayMethodSource($class, $method);
+
+            expect(str_contains($source, 'catch ('))->toBeTrue("{$class}::{$method}: catch がありません");
+            expect(substr_count($source, 'GatewayFailureClassifier::context('))->toBe(
+                $expected,
+                "{$class}::{$method}: GatewayFailureClassifier::context() の回数が宣言と一致しません",
+            );
+        }
+    }
+});
+
+test('検査 7b: ファイル全体の context() 回数が catchSites の総和と一致する', function (): void {
+    foreach (billingGatewayObservers() as $class => $entry) {
+        expect(substr_count(billingGatewaySourceOf($class), 'GatewayFailureClassifier::context('))->toBe(
+            array_sum($entry->catchSites),
+            "{$class}: 宣言外のメソッドで context() を呼んでいます (catchSites を更新すること)",
+        );
+    }
+});
+
+// ---------------------------------------------------------------------------
+// 検査 8〜13: 分類語彙の全域性 (vendor 走査 gate)
+// ---------------------------------------------------------------------------
+
+test('検査 8: 写像表と条件付き規則は排他', function (): void {
+    $both = array_intersect(
+        array_keys(GatewayFailureClassifier::directMap()),
+        GatewayFailureClassifier::conditionalClasses(),
+    );
+
+    expect(array_values($both))->toBe([]);
+});
+
+test('検査 9: 分類対象の集合が vendor 母集団 + 非 vendor 明示宣言と一致する', function (): void {
+    $classified = array_merge(
+        array_keys(GatewayFailureClassifier::directMap()),
+        GatewayFailureClassifier::conditionalClasses(),
+    );
+    sort($classified);
+
+    $expected = array_merge(VendorExceptionPopulation::classes(), billingNonVendorExplicitClasses());
+    sort($expected);
+
+    $missing = array_values(array_diff($expected, $classified));
+    $stale = array_values(array_diff($classified, $expected));
+
+    expect($missing)->toBe(
+        [],
+        '未分類の例外クラスがある (composer update で vendor の語彙が増えた可能性がある。'
+        .'復旧手順は docs/architecture.md §オートリチャージの失敗分類): '.implode(', ', $missing),
+    );
+    expect($stale)->toBe([], '実在しない / 母集団外のクラスが写像表に残っている: '.implode(', ', $stale));
+});
+
+test('検査 10: 条件付き規則のクラスがクラス同一性で固定されている', function (): void {
+    expect(GatewayFailureClassifier::conditionalClasses())->toBe([UnknownApiErrorException::class]);
+});
+
+test('検査 11: 写像表の値に Unknown が現れない (unknown は写像の不在専用)', function (): void {
+    $unknown = array_keys(array_filter(
+        GatewayFailureClassifier::directMap(),
+        static fn (GatewayFailureClass $case): bool => $case === GatewayFailureClass::Unknown,
+    ));
+
+    expect($unknown)->toBe(
+        [],
+        'unknown は「写像表に一致が無かった」ことの通知であり、表の値として使ってはならない: '
+        .implode(', ', $unknown),
+    );
+});
+
+test('検査 12: 非 vendor 明示宣言の件数が cap と一致する', function (): void {
+    expect(count(billingNonVendorExplicitClasses()))->toBe(billingNonVendorExplicitCap());
+});
+
+test('検査 13: vendor 母集団の除外宣言がサブディレクトリ集合と一致し、母集団と交差しない', function (): void {
+    $stripeDir = base_path('vendor/stripe/stripe-php/lib/Exception');
+
+    // (a) 実サブディレクトリ集合 == 除外宣言のキー集合
+    $declared = array_keys(VendorExceptionPopulation::EXCLUDED_STRIPE_SUBNAMESPACES);
+    sort($declared);
+    expect(VendorExceptionPopulation::subdirectories($stripeDir))->toBe(
+        $declared,
+        'Stripe SDK がサブ名前空間を増減させました。母集団定義 (EXCLUDED_STRIPE_SUBNAMESPACES) を再検討すること',
+    );
+
+    // (b) 除外理由は 30 文字以上
+    foreach (VendorExceptionPopulation::EXCLUDED_STRIPE_SUBNAMESPACES as $sub => $reason) {
+        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(30, "{$sub}: 除外理由は 30 文字以上で書くこと");
+    }
+
+    // (c) 直下母集団の各クラスが除外名前空間に属さない (集合の非交差)
+    foreach (VendorExceptionPopulation::stripeClasses() as $class) {
+        foreach ($declared as $sub) {
+            expect(str_starts_with($class, 'Stripe\\Exception\\'.$sub.'\\'))->toBeFalse(
+                "{$class}: 除外宣言した名前空間のクラスが母集団へ混入しています",
+            );
+        }
+    }
+
+    // (d) 走査の縮み検出 (代表クラス)
+    expect(VendorExceptionPopulation::stripeClasses())->toContain(ApiConnectionException::class);
+    expect(VendorExceptionPopulation::cashierClasses())->toContain(IncompletePayment::class);
+});
+
+// ---------------------------------------------------------------------------
+// 検査 14〜18: fake / spy の parity
+// ---------------------------------------------------------------------------
+
+test('検査 14: fixture の case 集合が業務 4 case (cases() - Unknown) と一致する', function (): void {
+    $expected = array_values(array_filter(
+        GatewayFailureClass::cases(),
+        static fn (GatewayFailureClass $case): bool => $case !== GatewayFailureClass::Unknown,
+    ));
+
+    expect(GatewayFailureFixtures::parityCases())->toBe($expected);
+    expect(GatewayFailureFixtures::parityCases())->toHaveCount(count(GatewayFailureClass::cases()) - 1);
+});
+
+test('検査 15: fixture が返す例外の分類が宣言 case と一致する (fake/real parity)', function (): void {
+    foreach (GatewayFailureFixtures::parityCases() as $case) {
+        $throwable = GatewayFailureFixtures::throwableFor($case);
+
+        expect(GatewayFailureClassifier::classify($throwable))->toBe(
+            $case,
+            "{$case->value}: fixture の例外が宣言と違う分類になります (".$throwable::class.')',
+        );
+    }
+});
+
+test('検査 16: fixture が返すクラスが実ライブラリ名前空間に属する', function (): void {
+    foreach (GatewayFailureFixtures::parityCases() as $case) {
+        $class = GatewayFailureFixtures::throwableFor($case)::class;
+
+        $allowed = false;
+        foreach (GatewayFailureFixtures::ALLOWED_NAMESPACE_PREFIXES as $prefix) {
+            if (str_starts_with($class, $prefix)) {
+                $allowed = true;
+
+                break;
+            }
+        }
+
+        expect($allowed)->toBeTrue(
+            "{$case->value}: fixture が実ライブラリ以外のクラス ({$class}) を返しています。"
+            .'独自例外を投げると本物との分類 parity が崩れる',
+        );
+    }
+});
+
+test('検査 17: spy の throw がすべて fixture 経由である', function (): void {
+    $source = billingGatewaySourceOf(FakeAutoRechargeGateway::class);
+
+    expect(substr_count($source, 'throw GatewayFailureFixtures::throwableFor('))->toBe(
+        substr_count($source, 'throw '),
+        'spy が fixture を経由しない throw を持っています (本物との分類 parity が崩れる)',
+    );
+});
+
+test('検査 17b: 全 fixture の message に外部生成文字列の目印が含まれる', function (): void {
+    // ★negative assertion (「ログにマーカーが含まれない」) が空虚に green にならないための前提保証。
+    foreach (GatewayFailureFixtures::parityCases() as $case) {
+        $message = GatewayFailureFixtures::throwableFor($case)->getMessage();
+
+        expect(str_contains($message, GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER))->toBeTrue(
+            "{$case->value}: fixture の message にマーカーが入っていません",
+        );
+    }
+});
+
+test('検査 17c: 旧 API 名が tests/ 配下に残っていない (後方互換の並走を残さない)', function (): void {
+    // ★除外は文字列一致ではなく realpath で正規化して比較する (自己検出の回避)。
+    $self = realpath(__FILE__);
+    Assert::string($self, '自ファイルの realpath を解決できません');
+
+    $legacyNames = ['failOnTerminate', 'failOnResolveSubscriptionPaymentMethod'];
+    $violations = [];
+
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator(base_path('tests'), FilesystemIterator::SKIP_DOTS),
+    );
+    foreach ($iterator as $file) {
+        Assert::isInstanceOf($file, SplFileInfo::class);
+        if (! $file->isFile() || $file->getExtension() !== 'php') {
+            continue;
+        }
+
+        $path = realpath($file->getPathname());
+        Assert::string($path, 'テストファイルの realpath を解決できません');
+        if ($path === $self) {
+            continue; // 本ファイルはリテラルの正本
+        }
+
+        $source = file_get_contents($path);
+        Assert::string($source, "ソースを読み込めません: {$path}");
+
+        foreach ($legacyNames as $name) {
+            if (str_contains($source, $name)) {
+                $violations[] = $path.' => '.$name;
+            }
+        }
+    }
+
+    sort($violations);
+
+    expect($violations)->toBe([], '旧 API 名が残っています: '.implode(', ', $violations));
+});
+
+test('検査 18: runtime fake は例外を 1 つも投げない', function (): void {
+    $source = billingGatewaySourceOf(App\Services\Billing\Fakes\FakeAutoRechargeGateway::class);
+
+    expect(substr_count($source, 'throw '))->toBe(
+        0,
+        'runtime fake (fake_externals 環境) は例外を投げない契約である',
+    );
+});
+
+// ---------------------------------------------------------------------------
+// 検査 19〜20: 集約点と mutation coverage
+// ---------------------------------------------------------------------------
+
+test('検査 19: Stripe 例外型を import する app クラスが allowlist と一致する', function (): void {
+    $found = [];
+    foreach (QueuedJobPopulation::appPhpFiles() as $path) {
+        $source = file_get_contents($path);
+        Assert::string($source, "ソースを読み込めません: {$path}");
+
+        if (! str_contains($source, 'use Stripe\\Exception\\')) {
+            continue;
+        }
+
+        $found[] = QueuedJobPopulation::classNameForPath($path);
+    }
+    sort($found);
+
+    $allowlist = billingStripeExceptionImportAllowlist();
+    sort($allowlist);
+
+    expect($found)->toBe(
+        $allowlist,
+        'Stripe 例外型を知るクラスは gateway 実装 + GatewayFailureClassifier に閉じる '
+        .'(集約点が増えると観測語彙が割れる)',
+    );
+});
+
+test('検査 20: mutation coverage 表のキー集合が想定 ID 集合と一致する', function (): void {
+    expect(array_keys(BILLING_GATEWAY_TAXONOMY_MUTATION_COVERAGE))
+        ->toBe(['M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8', 'M9', 'M10']);
+
+    foreach (BILLING_GATEWAY_TAXONOMY_MUTATION_COVERAGE as $id => $description) {
+        expect(mb_strlen($description))->toBeGreaterThanOrEqual(10, "{$id}: mutation の説明が短すぎます");
+    }
+});
diff --git a/tests/Feature/Billing/AutoRechargeReconcileTest.php b/tests/Feature/Billing/AutoRechargeReconcileTest.php
index a2fecfd..d7c62e0 100644
--- a/tests/Feature/Billing/AutoRechargeReconcileTest.php
+++ b/tests/Feature/Billing/AutoRechargeReconcileTest.php
@@ -6,6 +6,7 @@
 use App\DataTransferObjects\Billing\InvoiceStateDto;
 use App\Enums\Billing\AutoRechargeAttemptStatus;
 use App\Enums\Billing\BillingNotificationType;
+use App\Enums\Billing\GatewayFailureClass;
 use App\Models\Billing\BillingNotification;
 use App\Models\Billing\TicketAutoRechargeAttempt;
 use App\Models\Billing\TicketLedgerEntry;
@@ -15,7 +16,12 @@
 use Carbon\CarbonImmutable;
 use Illuminate\Console\Scheduling\Event;
 use Illuminate\Console\Scheduling\Schedule;
+use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\Log;
 use Illuminate\Support\Facades\Queue;
+use Illuminate\Support\Facades\Schema;
+use Stripe\Exception\ApiConnectionException;
+use Tests\Support\Billing\GatewayFailureFixtures;
 use Tests\Support\FakeAutoRechargeGateway;
 
 /*
@@ -166,6 +172,66 @@
     expect($good->fresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
 });
 
+test('隔離ログに失敗分類が載る (gateway 例外 → provider_unavailable)', function (): void {
+    // ★分類は観測のためであり制御フローを変えない: 隔離は現行どおり続行する。
+    Log::spy();
+    [$organization] = createOrganizationWithOwner();
+    $attempt = TicketAutoRechargeAttempt::factory()->for($organization)->create([
+        'created_at' => CarbonImmutable::now()->subMinutes(20),
+    ]);
+    enableAutoRecharge($organization);
+    // 本物の gateway が伝播させる実ライブラリ例外を invoice 作成中に注入する
+    $this->gateway->duringCreateInvoice = function (): void {
+        throw GatewayFailureFixtures::throwableFor(GatewayFailureClass::ProviderUnavailable);
+    };
+
+    $stats = app(AutoRechargeService::class)->reconcile();
+
+    // 隔離されるので reconcile 自体は例外を投げず、attempt は pending のまま次周期へ回る
+    expect($stats['retried'])->toBe(0);
+    expect($attempt->fresh()->status)->toBe(AutoRechargeAttemptStatus::Pending);
+
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context): bool {
+            if ($message !== 'auto-recharge reconcile: attempt processing failed') {
+                return false;
+            }
+
+            return $context['failure_class'] === 'provider_unavailable'
+                && $context['error_class'] === ApiConnectionException::class
+                && ! str_contains(
+                    json_encode($context, JSON_THROW_ON_ERROR),
+                    GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER,
+                );
+        })
+        ->once();
+});
+
+test('取りこぼし起票ログに失敗分類が載る (DB 例外 → local_failure)', function (): void {
+    Log::spy();
+    [$organization] = createOrganizationWithOwner();
+    enableAutoRecharge($organization);
+
+    // 単価表を実際に引けなくして DB 例外 (QueryException) を起こす。
+    // RefreshDatabase のトランザクション内なのでテスト終了時に巻き戻る。
+    Schema::rename('ticket_volume_prices', 'ticket_volume_prices_missing');
+
+    $stats = app(AutoRechargeService::class)->reconcile();
+
+    expect($stats['triggered'])->toBe(0);
+
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context): bool {
+            if ($message !== 'auto-recharge reconcile: trigger failed') {
+                return false;
+            }
+
+            return $context['failure_class'] === 'local_failure'
+                && $context['error_class'] === QueryException::class;
+        })
+        ->once();
+});
+
 test('リコンサイルコマンドは 0 で終了し統計を出力する', function (): void {
     $this->artisan('billing:reconcile-auto-recharge')
         ->expectsOutputToContain('auto-recharge reconcile:')
diff --git a/tests/Feature/Billing/AutoRechargeServiceTest.php b/tests/Feature/Billing/AutoRechargeServiceTest.php
index 462237f..7ad868c 100644
--- a/tests/Feature/Billing/AutoRechargeServiceTest.php
+++ b/tests/Feature/Billing/AutoRechargeServiceTest.php
@@ -7,6 +7,7 @@
 use App\Enums\Billing\AutoRechargeAttemptStatus;
 use App\Enums\Billing\AutoRechargeDisabledReason;
 use App\Enums\Billing\BillingNotificationType;
+use App\Enums\Billing\GatewayFailureClass;
 use App\Enums\Billing\TicketLedgerKind;
 use App\Enums\Security\ExternalCallKind;
 use App\Models\Billing\BillingNotification;
@@ -23,6 +24,9 @@
 use Illuminate\Support\Facades\Exceptions;
 use Illuminate\Support\Facades\Log;
 use Illuminate\Validation\ValidationException;
+use Stripe\Exception\ApiConnectionException;
+use Stripe\Exception\InvalidRequestException;
+use Tests\Support\Billing\GatewayFailureFixtures;
 use Tests\Support\FakeAttemptOwnershipPreflight;
 use Tests\Support\FakeAutoRechargeGateway;
 
@@ -296,7 +300,7 @@ function grantTickets(Organization $organization, int $amount): void
 
     $attempt = $service->maybeCreateAttempt($organization);
     $attempt->forceFill(['stripe_invoice_id' => 'in_stuck'])->save();
-    $gateway->failOnTerminate = true;
+    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;
 
     $service->terminateAndFail($organization, $attempt);
 
@@ -634,7 +638,7 @@ function autoRechargePendingAttempt(
     $gateway->withDefaultPaymentMethod();
     $attempt = autoRechargePendingAttempt($organization, $owner, $service);
     $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
-    $gateway->failOnTerminate = true;
+    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;
 
     $service->executeAttempt($attempt);
 
@@ -658,11 +662,16 @@ function autoRechargePendingAttempt(
             }
             $keys = array_keys($context);
             sort($keys);
-            $expected = ['attempt_ulid', 'error', 'event', 'invoice_id', 'job_id', 'job_type', 'terminated'];
+            $expected = [
+                'attempt_ulid', 'error_class', 'event', 'failure_class',
+                'invoice_id', 'job_id', 'job_type', 'terminated',
+            ];
 
             return $keys === $expected
                 && $context['terminated'] === true
-                && $context['error'] === null
+                // ★成功時も 2 キーは null で存在する (集計 schema を成否で割らない)
+                && $context['failure_class'] === null
+                && $context['error_class'] === null
                 && $context['attempt_ulid'] === $attempt->attempt_ulid;
         })
         ->once();
@@ -674,7 +683,7 @@ function autoRechargePendingAttempt(
         ->once();
 });
 
-test('後始末ログの error は例外クラス名のみで、外部由来のメッセージを含まない', function (): void {
+test('後始末のログに外部由来のメッセージを載せない (分類 + 例外クラス名のみ)', function (): void {
     // Stripe SDK の例外メッセージは外部サービスが生成する可変文字列であり、構造化ログの
     // 集計語彙へ流さない。
     Log::spy();
@@ -682,7 +691,8 @@ function autoRechargePendingAttempt(
     $gateway->withDefaultPaymentMethod();
     $attempt = autoRechargePendingAttempt($organization, $owner, $service);
     $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
-    $gateway->failOnTerminate = true; // メッセージ「fake gateway: invoice 終端失敗」で throw する
+    // 本物の gateway が伝播させる実ライブラリ例外を投げる (message にマーカーが入る)
+    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;
 
     $service->executeAttempt($attempt);
 
@@ -693,13 +703,19 @@ function autoRechargePendingAttempt(
             }
 
             return $context['terminated'] === false
-                && $context['error'] === RuntimeException::class
-                && ! str_contains((string) $context['error'], 'fake gateway');
+                && $context['failure_class'] === 'provider_unavailable'
+                && $context['error_class'] === ApiConnectionException::class
+                // ★マーカー非含有。gate が「fixture の message にマーカーが確かに入る」ことを
+                //   保証しているため、この negative assertion は空虚にならない。
+                && ! str_contains(
+                    json_encode($context, JSON_THROW_ON_ERROR),
+                    GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER,
+                );
         })
         ->once();
 });
 
-test('後始末の例外報告にも外部由来のメッセージを渡さない (サニタイズ済み例外のみ)', function (): void {
+test('後始末の例外報告は固定テンプレートと完全一致する (外部由来のメッセージを渡さない)', function (): void {
     // 「構造化ログに載せない」だけでは不十分 — 標準の exception handler は message と
     // スタックトレースを記録するため、原例外をそのまま report() すると保存場所が移るだけになる。
     Exceptions::fake();
@@ -707,17 +723,24 @@ function autoRechargePendingAttempt(
     $gateway->withDefaultPaymentMethod();
     $attempt = autoRechargePendingAttempt($organization, $owner, $service);
     $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
-    $gateway->failOnTerminate = true;
+    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;
 
     $service->executeAttempt($attempt);
 
-    Exceptions::assertReported(function (RuntimeException $reported): bool {
-        return str_contains($reported->getMessage(), 'の終端に失敗しました')
-            // 外部 (fake gateway = Stripe SDK 相当) が生成した文字列を含まない
-            && ! str_contains($reported->getMessage(), 'fake gateway')
-            // previous chain も繋がない (reporter が previous を出力しうるため)
-            && $reported->getPrevious() === null;
-    });
+    // ★部分一致をやめ**完全一致**で固定する (予期しない文字列の追加を必ず検出する)。
+    //   invoice_id は pay preflight より前に永続化されているため DB から取れる。
+    $invoiceId = $attempt->refresh()->stripe_invoice_id;
+    expect($invoiceId)->not->toBeNull();
+    $expected = sprintf(
+        'auto-recharge: invoice %s の終端に失敗しました (%s / %s)',
+        $invoiceId,
+        'provider_unavailable',
+        ApiConnectionException::class,
+    );
+
+    Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported->getMessage() === $expected
+        // previous chain も繋がない (reporter が previous を出力しうるため)
+        && $reported->getPrevious() === null);
     Exceptions::assertReportedCount(1);
 });
 
@@ -781,13 +804,109 @@ function autoRechargePendingAttempt(
     $gateway->withDefaultPaymentMethod();
     $attempt = autoRechargePendingAttempt($organization, $owner, $service);
     $attempt->forceFill(['stripe_invoice_id' => 'in_stuck_precondition'])->save();
-    $gateway->failOnTerminate = true;
+    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;
 
     $service->terminateAndFail($organization, $attempt);
 
     expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Pending);
 });
 
+/** 所有権喪失 → 後始末までを 1 シナリオ実行する (cleanup ログの発生源)。 */
+function autoRechargeRunCleanupScenario(?GatewayFailureClass $terminateFailure): void
+{
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+    $gateway->terminateFailure = $terminateFailure;
+
+    $service->executeAttempt($attempt);
+}
+
+test('cleanup event のキー集合が成功・失敗の両方で同一である (集計 schema を成否で割らない)', function (): void {
+    // ★Log::spy() は既に mock 済みなら再作成しないため、1 本の spy で 2 シナリオを記録する。
+    Log::spy();
+
+    autoRechargeRunCleanupScenario(null);                                        // 終端成功
+    autoRechargeRunCleanupScenario(GatewayFailureClass::ProviderUnavailable);     // 終端失敗
+
+    $contexts = [];
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context) use (&$contexts): bool {
+            if (($context['event'] ?? null) !== ExternalCallKind::CLEANUP_LOG_EVENT) {
+                return false;
+            }
+            // ★Mockery は照合と件数検証で closure を複数回呼ぶため、成否をキーにして
+            //   冪等に記録する (append だと重複して数が合わない)。
+            $contexts[$context['terminated'] === true ? 'success' : 'failure'] = $context;
+
+            return true;
+        })
+        ->twice();
+
+    expect(array_keys($contexts))->toEqualCanonicalizing(['success', 'failure']);
+    $success = $contexts['success'];
+    $failure = $contexts['failure'];
+
+    expect(array_keys($success))->toBe(array_keys($failure));
+    // 成功時も 2 キーは **null で存在**する
+    expect($success['terminated'])->toBeTrue()
+        ->and($success['failure_class'])->toBeNull()
+        ->and($success['error_class'])->toBeNull();
+    expect($failure['terminated'])->toBeFalse()
+        ->and($failure['failure_class'])->toBe('provider_unavailable')
+        ->and($failure['error_class'])->toBe(ApiConnectionException::class);
+});
+
+test('制御フロー等価性: 分類ログを出しても収束先と gateway 呼び出し回数が変わらない', function (): void {
+    // ★分類は**観測のため**であり課金の振る舞いを変えない。終端失敗時の収束先
+    //   (pending 維持) と gateway 呼び出し回数を明示的に固定する。
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+    $gateway->terminateFailure = GatewayFailureClass::ProviderUnavailable;
+
+    $service->executeAttempt($attempt);
+
+    // 所有権喪失で canceled 化済み (preflight が terminal 化させた側の結果)
+    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Canceled);
+    // 終端は失敗したので terminated 配列は空のまま / 課金 (pay) には進まない
+    expect($gateway->terminated)->toBe([]);
+    expect($gateway->payCalls)->toBe([]);
+    expect($gateway->createdInvoices)->toHaveCount(1);
+    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::Grant)->count())->toBe(0);
+});
+
+test('停止側の終端失敗ログにも分類が載る (message は載らない)', function (): void {
+    // tryTerminateInvoice の catch。制御フローは現行のまま (pending 維持)。
+    Log::spy();
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $attempt->forceFill(['stripe_invoice_id' => 'in_try_terminate'])->save();
+    $gateway->terminateFailure = GatewayFailureClass::ProviderRejected;
+
+    $service->terminateAndCancel($attempt);
+
+    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Pending);
+
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context): bool {
+            if ($message !== 'auto-recharge: invoice termination failed, keeping attempt pending') {
+                return false;
+            }
+
+            return $context['failure_class'] === 'provider_rejected'
+                && $context['error_class'] === InvalidRequestException::class
+                && ! str_contains(
+                    json_encode($context, JSON_THROW_ON_ERROR),
+                    GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER,
+                );
+        })
+        ->once();
+});
+
 test('冪等キーは 2 本ある: 同一 invoice の付与は台帳 1 件・attempt 遷移も 1 回', function (): void {
     [$organization, $owner, $gateway, $service] = autoRechargeSetup();
     $gateway->withDefaultPaymentMethod();
diff --git a/tests/Support/Billing/GatewayConsumerPopulation.php b/tests/Support/Billing/GatewayConsumerPopulation.php
new file mode 100644
index 0000000..df44605
--- /dev/null
+++ b/tests/Support/Billing/GatewayConsumerPopulation.php
@@ -0,0 +1,67 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use ReflectionClass;
+use ReflectionMethod;
+use ReflectionNamedType;
+use Tests\Support\QueuedJobPopulation;
+
+/**
+ * 「決済 gateway (`AutoRechargeGatewayInterface`) を注入される app クラス」の母集団を決める唯一の実装。
+ *
+ * ★判定は **constructor と全メソッドの引数型**に interface が現れることだけを見る
+ *   (`QueuedJobPopulation` と同じ作法で `app/` を走査 → PSR-4 → `class_exists()` → Reflection)。
+ *   gateway の**実装クラス** (`implements AutoRechargeGatewayInterface`) は
+ *   「注入される側」ではないので母集団に入らない。
+ * ★**走査の縮み**は gate の代表クラス検査で拾う (母集団が 0 件に落ちても green にならない)。
+ */
+final class GatewayConsumerPopulation
+{
+    /** @return list<class-string> */
+    public static function classes(): array
+    {
+        $classes = [];
+        foreach (QueuedJobPopulation::appPhpFiles() as $path) {
+            $class = QueuedJobPopulation::classNameForPath($path);
+            if (! class_exists($class)) {
+                continue;
+            }
+
+            $reflection = new ReflectionClass($class);
+            if (! self::injectsGateway($reflection)) {
+                continue;
+            }
+
+            $classes[] = $reflection->getName();
+        }
+
+        sort($classes);
+
+        return $classes;
+    }
+
+    /** @param ReflectionClass<object> $reflection */
+    private static function injectsGateway(ReflectionClass $reflection): bool
+    {
+        $methods = $reflection->getMethods();
+        $constructor = $reflection->getConstructor();
+        if ($constructor instanceof ReflectionMethod) {
+            $methods[] = $constructor;
+        }
+
+        foreach ($methods as $method) {
+            foreach ($method->getParameters() as $parameter) {
+                $type = $parameter->getType();
+                if ($type instanceof ReflectionNamedType && $type->getName() === AutoRechargeGatewayInterface::class) {
+                    return true;
+                }
+            }
+        }
+
+        return false;
+    }
+}
diff --git a/tests/Support/Billing/GatewayFailureFixtures.php b/tests/Support/Billing/GatewayFailureFixtures.php
new file mode 100644
index 0000000..c4875a6
--- /dev/null
+++ b/tests/Support/Billing/GatewayFailureFixtures.php
@@ -0,0 +1,105 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use App\Enums\Billing\GatewayFailureClass;
+use Illuminate\Database\QueryException;
+use LogicException;
+use PDOException;
+use Stripe\Exception\ApiConnectionException;
+use Stripe\Exception\InvalidRequestException;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 「**本物の gateway が実際に伝播させる例外クラスそのもの**」を分類ごとに返す共有 fixture。
+ *
+ * ★fake が独自の RuntimeException を投げると、分類を記録する経路がテストで一度も
+ *   本物と同じ値を見ない (偽グリーン)。fake の失敗注入をここへ集約し、
+ *   `BillingGatewayFailureTaxonomyInventoryTest` が
+ *   「fixture の case 集合 == 業務 4 case」「classify(fixture(case)) === case」
+ *   「fixture が返すクラスが実ライブラリ名前空間に属する」を deny-by-default で固定する。
+ * ★`Unknown` は parity の対象外 (写像の不在専用なので「本物と同じ例外」が存在しない)。
+ *   `Unknown` の固定は分類器の Unit テストが UnmappedGatewayFailureForTest で行う。
+ */
+final class GatewayFailureFixtures
+{
+    /**
+     * 全 fixture の message に必ず含める「外部生成文字列」の目印。
+     *
+     * ★これが**無いと negative assertion が空虚に green になる**。
+     *   「ログにこの文字列が含まれない」という検査は、
+     *   「例外 message にはこの文字列が確かに入っている」という保証とセットでしか意味を持たない。
+     *   gate が全 fixture について `str_contains(getMessage(), MARKER)` を検査する。
+     */
+    public const string EXTERNAL_MESSAGE_MARKER = 'FIXTURE-EXTERNAL-MESSAGE';
+
+    /**
+     * fixture が返してよいクラスの名前空間 (gate が参照する)。
+     *
+     * @var list<string>
+     */
+    public const array ALLOWED_NAMESPACE_PREFIXES = [
+        'Stripe\\Exception\\',
+        'Laravel\\Cashier\\Exceptions\\',
+        'Illuminate\\',
+        'Webmozart\\Assert\\',
+    ];
+
+    /** parity の対象 (業務分類 4 case)。`Unknown` を含めない。 */
+    public static function throwableFor(GatewayFailureClass $class): Throwable
+    {
+        return match ($class) {
+            // Stripe に到達できない (接続断) — 本物では ApiConnectionException が伝播する
+            GatewayFailureClass::ProviderUnavailable => ApiConnectionException::factory(
+                self::EXTERNAL_MESSAGE_MARKER.': stripe unreachable',
+            ),
+            // 要求が拒否された (400) — 本物では InvalidRequestException が伝播する
+            GatewayFailureClass::ProviderRejected => InvalidRequestException::factory(
+                self::EXTERNAL_MESSAGE_MARKER.': invalid request',
+                400,
+            ),
+            // 本物の terminateInvoice の paid 判定 (Assert::true) と**同じクラス**
+            GatewayFailureClass::InvariantViolation => self::assertFailure(),
+            // reconcile が DB 例外を受ける経路
+            GatewayFailureClass::LocalFailure => new QueryException(
+                'pgsql',
+                'select 1',
+                [],
+                // ★QueryException::formatMessage() は previous の message を取り込むため、
+                //   マーカーは QueryException 自身の getMessage() にも現れる (実測で確認済み)。
+                new PDOException(self::EXTERNAL_MESSAGE_MARKER.': db unavailable'),
+            ),
+            GatewayFailureClass::Unknown => throw new LogicException(
+                'Unknown は parity の対象外。分類器 Unit テストの UnmappedGatewayFailureForTest を使うこと',
+            ),
+        };
+    }
+
+    /**
+     * parity 対象の業務 4 case (`Unknown` を除く全 case)。
+     *
+     * @return list<GatewayFailureClass>
+     */
+    public static function parityCases(): array
+    {
+        return array_values(array_filter(
+            GatewayFailureClass::cases(),
+            static fn (GatewayFailureClass $case): bool => $case !== GatewayFailureClass::Unknown,
+        ));
+    }
+
+    /** Webmozart\Assert\InvalidArgumentException を「実際に Assert に投げさせて」得る。 */
+    private static function assertFailure(): Throwable
+    {
+        try {
+            Assert::true(false, self::EXTERNAL_MESSAGE_MARKER.': 不変条件違反');
+        } catch (Throwable $throwable) {
+            return $throwable;
+        }
+
+        throw new LogicException('Assert::true(false) が例外を投げませんでした');
+    }
+}
diff --git a/tests/Support/Billing/GatewayObservationEntry.php b/tests/Support/Billing/GatewayObservationEntry.php
new file mode 100644
index 0000000..217cb50
--- /dev/null
+++ b/tests/Support/Billing/GatewayObservationEntry.php
@@ -0,0 +1,32 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * 「決済 gateway 例外を catch して観測へ落とす」と裁定されたクラスの目録エントリ。
+ *
+ * ★`catchSites` は**メソッド単位**で宣言する。ファイル全体の出現回数だけだと
+ *   コメント / 別文脈でも数が合えば green になるため、
+ *   `BillingGatewayFailureTaxonomyInventoryTest` が ReflectionMethod の行範囲で切り出して検査する。
+ */
+final readonly class GatewayObservationEntry
+{
+    /**
+     * @param  array<string, int>  $catchSites  メソッド名 => そのメソッド内で期待する context() 呼び出し回数
+     * @param  int  $rawMessageCap  当該クラスのソースに現れてよい `getMessage()` の件数 (exact fit)
+     * @param  non-empty-string  $rationale  30 文字以上
+     */
+    public function __construct(
+        public array $catchSites,
+        public int $rawMessageCap,
+        public string $rationale,
+    ) {
+        Assert::notEmpty($catchSites, 'catchSites を 1 件以上宣言すること');
+        Assert::greaterThanEq($rawMessageCap, 0, 'rawMessageCap は 0 以上で宣言すること');
+        Assert::greaterThanEq(mb_strlen($rationale), 30, '観測目録の根拠は 30 文字以上で書くこと');
+    }
+}
diff --git a/tests/Support/Billing/GatewayObservationExemptionEntry.php b/tests/Support/Billing/GatewayObservationExemptionEntry.php
new file mode 100644
index 0000000..81df8e0
--- /dev/null
+++ b/tests/Support/Billing/GatewayObservationExemptionEntry.php
@@ -0,0 +1,20 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use App\Enums\Security\GatewayFailureObservationExemption;
+use Webmozart\Assert\Assert;
+
+/** 「決済 gateway 例外を観測しないことが正しい」と裁定されたクラスの目録エントリ。 */
+final readonly class GatewayObservationExemptionEntry
+{
+    /** @param non-empty-string $rationale 30 文字以上 */
+    public function __construct(
+        public GatewayFailureObservationExemption $exemption,
+        public string $rationale,
+    ) {
+        Assert::greaterThanEq(mb_strlen($rationale), 30, '免除の根拠は 30 文字以上で書くこと');
+    }
+}
diff --git a/tests/Support/Billing/UnmappedGatewayFailureForTest.php b/tests/Support/Billing/UnmappedGatewayFailureForTest.php
new file mode 100644
index 0000000..38ed415
--- /dev/null
+++ b/tests/Support/Billing/UnmappedGatewayFailureForTest.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use RuntimeException;
+
+/**
+ * 写像表に**載っていない**ことを目的とするテスト専用例外。
+ *
+ * ★`unknown` (写像の不在) の分類を固定するために使う。vendor 例外を未分類のまま
+ *   fixture に使うと「vendor 全件分類」の gate と衝突するため、専用クラスを置く。
+ */
+final class UnmappedGatewayFailureForTest extends RuntimeException {}
diff --git a/tests/Support/Billing/VendorExceptionPopulation.php b/tests/Support/Billing/VendorExceptionPopulation.php
new file mode 100644
index 0000000..32ff377
--- /dev/null
+++ b/tests/Support/Billing/VendorExceptionPopulation.php
@@ -0,0 +1,115 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use FilesystemIterator;
+use ReflectionClass;
+use SplFileInfo;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 分類対象となる vendor 例外クラスの母集団 (Stripe SDK / Cashier)。
+ *
+ * ★`vendor/stripe/stripe-php/lib/Exception/*.php` (**直下のみ**) と
+ *   `vendor/laravel/cashier/src/Exceptions/*.php` を glob → クラス名へ変換 →
+ *   `class_exists()` → interface / abstract を除外する。
+ * ★`composer update` で例外クラスが増減すると gate が赤くなる。これは
+ *   **意図した費用**であり「外部の語彙が増えたことを人間に必ず知らせる」ための仕掛けである
+ *   (復旧手順は `docs/architecture.md` §オートリチャージの失敗分類)。
+ */
+final class VendorExceptionPopulation
+{
+    /**
+     * 母集団から外す Stripe のサブ名前空間 (根拠付き。gate がサブディレクトリ集合と突き合わせる)。
+     *
+     * @var array<string, string>
+     */
+    public const array EXCLUDED_STRIPE_SUBNAMESPACES = [
+        'OAuth' => 'Stripe Connect の OAuth 専用。本アプリは Connect を使わないため gateway 経路から到達しない',
+    ];
+
+    /** @return list<class-string<Throwable>> */
+    public static function classes(): array
+    {
+        $classes = array_merge(self::stripeClasses(), self::cashierClasses());
+        sort($classes);
+
+        return array_values($classes);
+    }
+
+    /** @return list<class-string<Throwable>> */
+    public static function stripeClasses(): array
+    {
+        return self::concreteThrowables(
+            base_path('vendor/stripe/stripe-php/lib/Exception'),
+            'Stripe\\Exception\\',
+        );
+    }
+
+    /** @return list<class-string<Throwable>> */
+    public static function cashierClasses(): array
+    {
+        return self::concreteThrowables(
+            base_path('vendor/laravel/cashier/src/Exceptions'),
+            'Laravel\\Cashier\\Exceptions\\',
+        );
+    }
+
+    /**
+     * ディレクトリ**直下**のサブディレクトリ名一覧 (除外宣言との突き合わせ用)。
+     *
+     * @return list<string>
+     */
+    public static function subdirectories(string $directory): array
+    {
+        $names = [];
+        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
+            Assert::isInstanceOf($entry, SplFileInfo::class);
+            if ($entry->isDir()) {
+                $names[] = $entry->getFilename();
+            }
+        }
+
+        sort($names);
+
+        return $names;
+    }
+
+    /**
+     * ディレクトリ直下の `*.php` のうち、具象 Throwable クラスだけを返す。
+     *
+     * @return list<class-string<Throwable>>
+     */
+    private static function concreteThrowables(string $directory, string $namespace): array
+    {
+        $paths = glob($directory.DIRECTORY_SEPARATOR.'*.php');
+        Assert::isArray($paths, "vendor 例外ディレクトリを走査できません: {$directory}");
+
+        $classes = [];
+        foreach ($paths as $path) {
+            $class = $namespace.basename($path, '.php');
+            if (! class_exists($class)) {
+                continue;
+            }
+
+            $reflection = new ReflectionClass($class);
+            if ($reflection->isInterface() || $reflection->isAbstract()) {
+                continue;
+            }
+            if (! $reflection->implementsInterface(Throwable::class)) {
+                continue;
+            }
+
+            /** @var class-string<Throwable> $name */
+            $name = $reflection->getName();
+            $classes[] = $name;
+        }
+
+        sort($classes);
+
+        return array_values($classes);
+    }
+}
diff --git a/tests/Support/FakeAutoRechargeGateway.php b/tests/Support/FakeAutoRechargeGateway.php
index d12d5e5..0e747ec 100644
--- a/tests/Support/FakeAutoRechargeGateway.php
+++ b/tests/Support/FakeAutoRechargeGateway.php
@@ -7,10 +7,11 @@
 use App\DataTransferObjects\Billing\DefaultPaymentMethodDto;
 use App\DataTransferObjects\Billing\InvoiceStateDto;
 use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
+use App\Enums\Billing\GatewayFailureClass;
 use App\Models\Organization;
 use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
 use Closure;
-use RuntimeException;
+use Tests\Support\Billing\GatewayFailureFixtures;
 
 /**
  * AutoRechargeGatewayInterface のテスト用 spy (Stripe に到達しない)。
@@ -53,8 +54,13 @@ final class FakeAutoRechargeGateway implements AutoRechargeGatewayInterface
     /** @var array<string, string> */
     public array $invoiceStatuses = [];
 
-    /** true にすると terminateInvoice が throw する (終端失敗 → pending 維持の再現)。 */
-    public bool $failOnTerminate = false;
+    /**
+     * terminateInvoice が投げる失敗の**分類** (null なら投げない)。
+     *
+     * ★bool ではなく分類で指定する。投げる実体は GatewayFailureFixtures が返す
+     *   **実ライブラリ例外**であり、本物の gateway が伝播させるクラスと一致する。
+     */
+    public ?GatewayFailureClass $terminateFailure = null;
 
     /**
      * createAutoRechargeInvoice が invoice ID を返す**直前**に呼ばれる hook
@@ -71,8 +77,8 @@ final class FakeAutoRechargeGateway implements AutoRechargeGatewayInterface
     /** resolveSubscriptionPaymentMethod の返り値 (null = 解決不能)。 */
     public ?string $subscriptionPaymentMethodId = 'pm_test_subscription';
 
-    /** true にすると resolveSubscriptionPaymentMethod が throw する。 */
-    public bool $failOnResolveSubscriptionPaymentMethod = false;
+    /** resolveSubscriptionPaymentMethod が投げる失敗の分類 (null なら投げない)。 */
+    public ?GatewayFailureClass $resolveSubscriptionFailure = null;
 
     /** createSetupCheckout が返す url (null = 進行中 replay の再現)。 */
     public ?string $setupUrl = 'https://checkout.stripe.test/c/setup/cs_setup_test';
@@ -152,13 +158,14 @@ public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBa
 
     public function terminateInvoice(string $invoiceId): void
     {
-        if ($this->failOnTerminate) {
-            throw new RuntimeException('fake gateway: invoice 終端失敗');
+        if ($this->terminateFailure !== null) {
+            throw GatewayFailureFixtures::throwableFor($this->terminateFailure);
         }
 
         $status = $this->invoiceStatuses[$invoiceId] ?? 'open';
         if ($status === 'paid') {
-            throw new RuntimeException("fake gateway: paid invoice {$invoiceId} は終端できない");
+            // ★本物 (CashierAutoRechargeGateway の Assert::true) と**同じクラス**を投げる
+            throw GatewayFailureFixtures::throwableFor(GatewayFailureClass::InvariantViolation);
         }
 
         $this->terminated[] = $invoiceId;
@@ -208,8 +215,8 @@ public function resolveSubscriptionPaymentMethod(string $stripeSubscriptionId):
     {
         $this->resolvedSubscriptions[] = $stripeSubscriptionId;
 
-        if ($this->failOnResolveSubscriptionPaymentMethod) {
-            throw new RuntimeException('fake gateway: resolveSubscriptionPaymentMethod failed');
+        if ($this->resolveSubscriptionFailure !== null) {
+            throw GatewayFailureFixtures::throwableFor($this->resolveSubscriptionFailure);
         }
 
         return $this->subscriptionPaymentMethodId;
diff --git a/tests/Unit/Support/Billing/GatewayFailureClassifierTest.php b/tests/Unit/Support/Billing/GatewayFailureClassifierTest.php
new file mode 100644
index 0000000..77ed2e6
--- /dev/null
+++ b/tests/Unit/Support/Billing/GatewayFailureClassifierTest.php
@@ -0,0 +1,191 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\GatewayFailureClass;
+use App\Support\Billing\GatewayFailureClassifier;
+use Illuminate\Contracts\Cache\LockTimeoutException;
+use Illuminate\Database\QueryException;
+use Laravel\Cashier\Exceptions\CustomerAlreadyCreated;
+use Laravel\Cashier\Exceptions\IncompletePayment;
+use Laravel\Cashier\Exceptions\InvalidCoupon;
+use Laravel\Cashier\Exceptions\InvalidCustomer;
+use Laravel\Cashier\Exceptions\InvalidCustomerBalanceTransaction;
+use Laravel\Cashier\Exceptions\InvalidInvoice;
+use Laravel\Cashier\Exceptions\InvalidPaymentMethod;
+use Laravel\Cashier\Exceptions\SubscriptionUpdateFailure;
+use Laravel\Cashier\Payment;
+use Stripe\Exception\ApiConnectionException;
+use Stripe\Exception\AuthenticationException;
+use Stripe\Exception\BadMethodCallException as StripeBadMethodCallException;
+use Stripe\Exception\CardException;
+use Stripe\Exception\IdempotencyException;
+use Stripe\Exception\InvalidArgumentException as StripeInvalidArgumentException;
+use Stripe\Exception\InvalidRequestException;
+use Stripe\Exception\PermissionException;
+use Stripe\Exception\RateLimitException;
+use Stripe\Exception\SignatureVerificationException;
+use Stripe\Exception\TemporarySessionExpiredException;
+use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
+use Stripe\Exception\UnknownApiErrorException;
+use Stripe\PaymentIntent;
+use Tests\Support\Billing\GatewayFailureFixtures;
+use Tests\Support\Billing\UnmappedGatewayFailureForTest;
+use Webmozart\Assert\Assert;
+use Webmozart\Assert\InvalidArgumentException as AssertInvalidArgumentException;
+
+/*
+ * 分類器の全域性・境界・context の array shape を固定する。
+ *
+ * ★DB を触らない (Unit レーン。RefreshDatabase はグローバル適用だがクエリを発行しない)。
+ */
+
+/**
+ * ★**期待値は分類器と独立に手書きで宣言する**。
+ *   `directMap()` をそのまま dataset にすると、期待値と実装が同一ソースになり
+ *   **写像を間違えても常に green** になる (既存 gate の「目録と期待値 map の二重宣言」と同じ作法)。
+ * ★件数は固定定数で持たない。**キー集合一致の検査が正本**である
+ *   (件数を別に持つと、片方だけ直したときに嘘の安心を与える)。
+ *
+ * @return array<class-string<Throwable>, GatewayFailureClass>
+ */
+function billingTaxonomyExpectedClassification(): array
+{
+    return [
+        // --- Stripe SDK ---
+        ApiConnectionException::class => GatewayFailureClass::ProviderUnavailable,
+        RateLimitException::class => GatewayFailureClass::ProviderUnavailable,
+        InvalidRequestException::class => GatewayFailureClass::ProviderRejected,
+        AuthenticationException::class => GatewayFailureClass::ProviderRejected,
+        CardException::class => GatewayFailureClass::ProviderRejected,
+        PermissionException::class => GatewayFailureClass::ProviderRejected,
+        IdempotencyException::class => GatewayFailureClass::ProviderRejected,
+        TemporarySessionExpiredException::class => GatewayFailureClass::ProviderRejected,
+        SignatureVerificationException::class => GatewayFailureClass::ProviderRejected,
+        StripeBadMethodCallException::class => GatewayFailureClass::InvariantViolation,
+        StripeInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
+        StripeUnexpectedValueException::class => GatewayFailureClass::InvariantViolation,
+
+        // --- Cashier ---
+        IncompletePayment::class => GatewayFailureClass::ProviderRejected,
+        CustomerAlreadyCreated::class => GatewayFailureClass::InvariantViolation,
+        InvalidCustomer::class => GatewayFailureClass::InvariantViolation,
+        InvalidPaymentMethod::class => GatewayFailureClass::InvariantViolation,
+        InvalidInvoice::class => GatewayFailureClass::InvariantViolation,
+        InvalidCoupon::class => GatewayFailureClass::InvariantViolation,
+        InvalidCustomerBalanceTransaction::class => GatewayFailureClass::InvariantViolation,
+        SubscriptionUpdateFailure::class => GatewayFailureClass::InvariantViolation,
+
+        // --- 非 vendor 明示宣言 ---
+        QueryException::class => GatewayFailureClass::LocalFailure,
+        LockTimeoutException::class => GatewayFailureClass::LocalFailure,
+        AssertInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
+    ];
+}
+
+/**
+ * 期待値表のクラスを**実インスタンス**として生成する。
+ *
+ * ★factory / constructor が違うため match で分ける。**実インスタンスで固定する**ことに意味がある
+ *   (`LockTimeoutException` は `Contracts\Cache` と `Contracts\Filesystem` に同名クラスがあり、
+ *    import を取り違えても文字列比較では気づけない)。
+ */
+function billingTaxonomyInstantiate(string $class): Throwable
+{
+    $throwable = match ($class) {
+        ApiConnectionException::class => ApiConnectionException::factory('connection'),
+        RateLimitException::class => RateLimitException::factory('rate limit', 429),
+        InvalidRequestException::class => InvalidRequestException::factory('invalid request', 400),
+        AuthenticationException::class => AuthenticationException::factory('auth', 401),
+        CardException::class => CardException::factory('card', 402),
+        PermissionException::class => PermissionException::factory('permission', 403),
+        IdempotencyException::class => IdempotencyException::factory('idempotency', 400),
+        TemporarySessionExpiredException::class => TemporarySessionExpiredException::factory('expired', 400),
+        SignatureVerificationException::class => SignatureVerificationException::factory('signature'),
+        StripeBadMethodCallException::class => new StripeBadMethodCallException('bad method call'),
+        StripeInvalidArgumentException::class => new StripeInvalidArgumentException('invalid argument'),
+        StripeUnexpectedValueException::class => new StripeUnexpectedValueException('unexpected value'),
+
+        IncompletePayment::class => new IncompletePayment(new Payment(new PaymentIntent('pi_test')), 'incomplete'),
+        CustomerAlreadyCreated::class => new CustomerAlreadyCreated('already created'),
+        InvalidCustomer::class => new InvalidCustomer('invalid customer'),
+        InvalidPaymentMethod::class => new InvalidPaymentMethod('invalid payment method'),
+        InvalidInvoice::class => new InvalidInvoice('invalid invoice'),
+        InvalidCoupon::class => new InvalidCoupon('invalid coupon'),
+        InvalidCustomerBalanceTransaction::class => new InvalidCustomerBalanceTransaction('invalid transaction'),
+        SubscriptionUpdateFailure::class => new SubscriptionUpdateFailure('update failure'),
+
+        QueryException::class => new QueryException('pgsql', 'select 1', [], new PDOException('db')),
+        LockTimeoutException::class => new LockTimeoutException('lock timeout'),
+        AssertInvalidArgumentException::class => new AssertInvalidArgumentException('assert'),
+
+        default => throw new LogicException("生成方法が未定義のクラスです: {$class}"),
+    };
+
+    // 生成物が宣言どおりのクラスであること (import 取り違えの検出)
+    Assert::same($throwable::class, $class, "生成したインスタンスのクラスが宣言と一致しません: {$class}");
+
+    return $throwable;
+}
+
+dataset('分類の期待値 (独立宣言)', function (): Generator {
+    foreach (billingTaxonomyExpectedClassification() as $class => $expected) {
+        yield $class => [$class, $expected];
+    }
+});
+
+test('各クラスが期待どおりに分類される', function (string $class, GatewayFailureClass $expected): void {
+    expect(GatewayFailureClassifier::classify(billingTaxonomyInstantiate($class)))->toBe($expected);
+})->with('分類の期待値 (独立宣言)');
+
+test('期待値表と directMap のキー集合が一致する (書き忘れ / 余剰の検出)', function (): void {
+    $expected = array_keys(billingTaxonomyExpectedClassification());
+    $actual = array_keys(GatewayFailureClassifier::directMap());
+    sort($expected);
+    sort($actual);
+
+    expect($actual)->toBe($expected);
+});
+
+test('UnknownApiErrorException は HTTP status で分岐する', function (?int $status, GatewayFailureClass $expected): void {
+    expect(GatewayFailureClassifier::classify(UnknownApiErrorException::factory('boundary', $status)))
+        ->toBe($expected);
+})->with([
+    'null (status 不明)' => [null, GatewayFailureClass::ProviderRejected],
+    '400' => [400, GatewayFailureClass::ProviderRejected],
+    '499 (境界の下)' => [499, GatewayFailureClass::ProviderRejected],
+    '500 (境界)' => [500, GatewayFailureClass::ProviderUnavailable],
+    '503' => [503, GatewayFailureClass::ProviderUnavailable],
+]);
+
+test('写像表に無い例外は unknown へ落ちる', function (): void {
+    expect(GatewayFailureClassifier::classify(new UnmappedGatewayFailureForTest('x')))
+        ->toBe(GatewayFailureClass::Unknown);
+});
+
+test('親クラス連鎖で分類される (将来のサブクラスを取りこぼさない)', function (): void {
+    $subclass = new class('sub') extends ApiConnectionException {};
+
+    expect(GatewayFailureClassifier::classify($subclass))->toBe(GatewayFailureClass::ProviderUnavailable);
+});
+
+test('context はキー集合と値が完全一致する (message は入り得ない)', function (): void {
+    $context = GatewayFailureClassifier::context(
+        ApiConnectionException::factory(GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER),
+    );
+
+    // ★キー集合と各値を**完全一致**で固定する。
+    //   これ以外の値が入り得ないので、マーカー非含有は自明になる
+    //   (json_encode して部分文字列を否定する形は array shape の検査として過剰)。
+    expect($context)->toBe([
+        'failure_class' => 'provider_unavailable',
+        'error_class' => ApiConnectionException::class,
+    ]);
+});
+
+test('LockTimeoutException は Contracts\Cache の具象クラスである (同名別クラスの取り違え検出)', function (): void {
+    $throwable = new LockTimeoutException('lock timeout');
+
+    expect($throwable::class)->toBe('Illuminate\Contracts\Cache\LockTimeoutException');
+    expect(GatewayFailureClassifier::classify($throwable))->toBe(GatewayFailureClass::LocalFailure);
+});

```

## テスト結果

### composer test (全件)
{"tool":"pest","result":"passed","tests":3688,"passed":3686,"assertions":14883,"duration_ms":260939,"skipped":2}

### composer phpstan (level 10)
[OK] No errors  (809 files)

### vendor/bin/pint --test
{"tool":"pint","result":"passed"}

### pnpm lint / typecheck / test / build
eslint OK / tsc --noEmit OK / Test Files 126 passed (126), Tests 1236 passed (1236) / built in 4.00s

### pnpm typecheck:packages / build:packages / test:packages
OK / OK / Test Files 10 passed (10), Tests 106 passed (106)

### composer test:browser (chromium / webkit の 2 レーン)
chromium: 14 tests, 11 passed, 3 skipped
webkit:   14 tests, 11 passed, 3 skipped

### テストファースト (思考原則 5) の実測
S5 検査 6 (getMessage cap) を実装前に単独で走らせ、main の現状 3 箇所で赤くなることを実測:
`App\Services\Billing\AutoRechargeService: getMessage() の出現件数が rawMessageCap と一致しません / Failed asserting that 3 is identical to 0.`

### 偽グリーンの実査 (設計 S4 の判定の検証)
実装した分類器で実測した結果、設計の判定どおり食い違いが実在した:
```
real(paid)             Webmozart\Assert\InvalidArgumentException   => invariant_violation
spy(paid)              RuntimeException                            => unknown
spy(failOnTerminate)   RuntimeException                            => unknown
```

### mutation M1〜M10 の赤化確認 (1 つずつ適用 → 実行 → 復元)
| ID | 赤化した検査 | 設計の期待 | 一致 |
|---|---|---|---|
| M1 | 検査 9 | 検査 9 | ○ |
| M2 | 検査 9 | 検査 9 | ○ |
| M3 | 検査 11 | 検査 11 | ○ |
| M4 | 検査 8 / 9 / 10 | 検査 10 (+9) | ○ |
| M5 | 検査 15 / 16 / 17b (Unit は green のまま) | 検査 15/16 + Unit | △ |
| M6 | 検査 17 | 検査 17 | ○ |
| M7 | 検査 6 | 検査 6 | ○ |
| M8 | 検査 1 / 5 / 6 / 7a / 7b | 検査 1 | ○ |
| M9 | 検査 3 | 検査 3 | ○ |
| M10 | 検査 7a / 7b | 検査 7 | ○ |

M5 で Unit テストが green のままだったのは、Unit が `GatewayFailureFixtures` に依存せず
期待値を独立宣言しているため (設計意図どおりの独立性)。fixture の破壊は Architecture gate の
検査 15 / 16 / 17b が 3 本同時に捕まえる。

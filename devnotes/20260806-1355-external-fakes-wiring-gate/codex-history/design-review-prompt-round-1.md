## アプリの使命 (North Star)

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

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考えてから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。設計の方向性が正しいと確認できてから行え。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

## あなたの役割

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。
本件は **tests/ 配下のみを追加する テスト基盤 (Architecture gate)** の詳細設計です。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (ただし解析対象は app/config/database/routes のみ。tests/ は対象外)
- Pest。tests/Pest.php で Feature/Unit は RefreshDatabase + StrayLlmCallGuard、Architecture は TestCase のみ
- テストは artisan test --parallel --processes=4
- 既存の同型実装: tests/Support/Security/PrimaryKeyStaticQueryScanner.php (token_get_all の純粋 helper) +
  tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php (走査器自体の positive/negative 固定)

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 (命名規約、パターン、テストレーン構成)
3. 型安全性の規約遵守 (docblock の list<> / class-string)
4. テスト計画の網羅性 (mutation による受入条件が実際に成立するか)
5. 検査の有効性: 提案された gate は「登録漏れが無音になる」問題を実際に捕まえられるか。偽グリーンになる箇所は無いか
6. 状態リーク (container / static / config / env) と並列・順序依存のリスク
7. 実装者がそのまま着手できる粒度になっているか。曖昧で判断が割れる箇所は無いか
8. 副作用・後退リスク (既存テストへの巻き添え)
9. スコープ (AGENTS.md 思考原則 2「今必要なものだけ作る」。過大になっていないか)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: external-fakes-wiring-gate (fake 配線の実証検査)

概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex レビュー 3 ラウンド反映済み)

---

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 本タスクの寄与: 撮影データの保管 (`TakeObjectStorage`) と課金 (チケット / サブスク) は
> 外部サービスに直結する。fake 配線が黙って崩れるとテストが緑のまま**実 S3 / 実 Stripe** を叩く。
> 現場の撮影データと課金は取り返しがつかない副作用を持つため、無音の失敗が最も高くつく。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き / 5. Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST での `redirect()->intended()` / 8. 必須条件未充足による disabled UI
9. Artifact の使用

> 本タスクは **tests/ 配下のみ**を追加する。4〜9 は非該当。1 が本タスクそのもの。

### コーディングルール

- `declare(strict_types=1)` + 日本語コメント。
- **PHPStan は `app` / `config` / `database` / `routes` のみを解析する** (`phpstan.neon`)。
  tests/ は解析対象外なので、型は**規約として**明示する (docblock の `list<>` / `class-string` を必ず書く)。
- Pest。`tests/Pest.php` により **Architecture lane は `RefreshDatabase` なし・`StrayLlmCallGuard` なし**、
  Unit/Feature lane は `RefreshDatabase` + `StrayLlmCallGuard` あり。
- テストは `--parallel --processes=4` で走る (`scripts/run-test.sh`)。
- フォーマットは `composer fix` (Pint)。
- **アプリコード (`app/` / `config/` / `bootstrap/` / `routes/`) は 1 行も変更しない。**

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | fake 配線 inventory (宣言) | `tests/Support/ExternalFakes/ExternalFakeBinding.php` (新規)<br>`tests/Support/ExternalFakes/ExternalFakeWiringInventory.php` (新規) | High |
| 2 | 走査 helper (字句解析 + 母集団導出) | `tests/Support/ExternalFakes/FakeWiringSourceScanner.php` (新規)<br>`tests/Support/ExternalFakes/FakeClassCatalog.php` (新規) | High |
| 3 | 実証ベースの配線 gate (柱 1) | `tests/Architecture/ExternalFakeWiringInvariantTest.php` (新規) | High |
| 4 | 本番コードの fake 参照 全走査 gate (柱 3c) | `tests/Architecture/FakeClassReferenceInvariantTest.php` (新規) | High |
| 5 | 走査 helper 自身の positive/negative 固定 | `tests/Unit/Architecture/FakeWiringSourceScannerTest.php` (新規) | High |
| 6 | ドキュメント追記 | `docs/architecture.md` (追記) | Medium |

**変更ファイルは 6 本 (新規 5 + 追記 1)。既存テストの改変は 0 本。**

> 施策 5 は「gate 自体がセキュリティ機構であり、走査器が壊れたら gate は静かに無力化する」という
> 既存 aicue の流儀 (`PrimaryKeyStaticQueryScanner` + `tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php`) に
> 合わせた必須項目。省略しない。

### 波及変更 (全施策共通)

- TypeScript 型定義: **なし** (フロントエンド非関与)
- API Resource / DTO: **なし**
- 既存テストファイル: **なし** (`tests/Feature/Providers/FakeExternalsServiceProviderTest.php` と
  `tests/Feature/Storage/FakeStorageRouteTest.php` は振る舞い回帰として現状維持)
- DESIGN.md / Atomic Design: **非該当**

---

## 施策 1: fake 配線 inventory (宣言)

### 新規ファイル: `tests/Support/ExternalFakes/ExternalFakeBinding.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

/**
 * container 差し替え 1 本の宣言 (fake 配線 gate の inventory 要素)。
 *
 * 「宣言 (本ファイル)」と「実証 (ExternalFakeWiringInvariantTest)」を分離する。
 * 本クラスは値の器であり判定ロジックを持たない。
 */
final readonly class ExternalFakeBinding
{
    /**
     * @param  class-string  $abstract  container から解決するキー (interface または具象クラス)
     * @param  class-string  $real      flag off のときに解決されるべきクラス (厳密一致)
     * @param  class-string  $fake      flag on + allowlist 内で解決されるべきクラス (厳密一致)
     * @param  string  $flag            capability flag の config キー
     * @param  list<string>  $allowedEnvironments  fake を許可する環境 allowlist
     * @param  string  $risk            なぜ外部副作用として危険か (レビュー用説明。機械照合しない)
     */
    public function __construct(
        public string $abstract,
        public string $real,
        public string $fake,
        public string $flag,
        public array $allowedEnvironments,
        public string $risk,
    ) {}

    /** データセット名 (テスト出力に出る識別子) */
    public function label(): string
    {
        return class_basename($this->abstract);
    }
}
```

### 新規ファイル: `tests/Support/ExternalFakes/ExternalFakeWiringInventory.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

use App\Http\Controllers\Testing\GetFakeStorageObjectController;
use App\Http\Controllers\Testing\PutFakeStorageObjectController;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Billing\CashierAutoRechargeGateway;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\CashierTicketCheckoutGateway;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use App\Services\Capture\Fakes\FakeTakeObjectStorage;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Render\Fakes\FakeRenderObjectStorage;
use App\Services\Render\RenderObjectStorage;
use App\Support\FakeStorageGate;

/**
 * 外部 fake の container 差し替え inventory (deny-by-default の正本)。
 *
 * 責務境界:
 * - 本 inventory と ExternalFakeWiringInvariantTest が見るのは **非本番側の配線**だけ。
 * - **本番混入防止の正本は `App\Support\ProductionEnvGuard`** (配備前 = production:preflight /
 *   起動時 = AppServiceProvider::boot の 2 経路) + `tests/Feature/Support/ProductionEnvGuardTest`。
 *   ここで二重実装しない。
 * - LLM (Prism) fake は container ではなく `Prompt::$fake` (プロセスグローバル static) を書き換える
 *   ため inventory の対象外。専用テストが別枠で見る。
 */
final class ExternalFakeWiringInventory
{
    /** 課金 fake の capability flag */
    public const string PAYMENT_FLAG = 'testing.fake_externals';

    /** storage fake の capability flag */
    public const string STORAGE_FLAG = 'testing.fake_storage';

    /** LLM fake の capability flag (container 差し替えではないため bindings() には現れない) */
    public const string LLM_FLAG = 'testing.fake_llm';

    /** 課金 fake の env allowlist (FakeExternalsServiceProvider::PAYMENT_FAKE_ENVIRONMENTS と対) */
    private const array PAYMENT_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /** storage fake の env allowlist (FakeStorageGate の predicate と対。testing は runningUnitTests 前提) */
    private const array STORAGE_ENVIRONMENTS = ['testing', 'bughunt.local'];

    /**
     * fake の実体ではないが FakeExternalsServiceProvider が参照してよい配線基盤クラス。
     *
     * 「provider が参照する fake 系クラス = bindings() の fake ∪ 本集合」を集合一致で検査するため、
     * ここに載っていないクラスを provider が参照した時点で gate が赤くなる。
     *
     * @return list<class-string>
     */
    public static function providerReferenceExceptions(): array
    {
        return [
            // LLM static fake の install 窓口 (container 配線を行わない)
            CannedPromptFakeRegistrar::class,
            // storage fake の有効化 predicate (SSOT。container 配線を行わない)
            FakeStorageGate::class,
            // fake storage signed route の受け口 (route action。container 配線を行わない)
            PutFakeStorageObjectController::class,
            GetFakeStorageObjectController::class,
        ];
    }

    /**
     * container 差し替えの全宣言。
     *
     * ここに entry を足すと、ExternalFakeWiringInvariantTest の data-driven 検査
     * (対照 / 実証 / allowlist 外) が自動的に 3 本増える = 書き忘れが構造的に起きない。
     *
     * @return list<ExternalFakeBinding>
     */
    public static function bindings(): array
    {
        return [
            new ExternalFakeBinding(
                abstract: TicketCheckoutGateway::class,
                real: CashierTicketCheckoutGateway::class,
                fake: FakeTicketCheckoutGateway::class,
                flag: self::PAYMENT_FLAG,
                allowedEnvironments: self::PAYMENT_ENVIRONMENTS,
                risk: 'チケットスポット購入の Stripe Checkout。配線が外れると実 Stripe に実課金セッションを作る。',
            ),
            new ExternalFakeBinding(
                abstract: StripeGatewayInterface::class,
                real: CashierStripeGateway::class,
                fake: FakeStripeGateway::class,
                flag: self::PAYMENT_FLAG,
                allowedEnvironments: self::PAYMENT_ENVIRONMENTS,
                risk: 'サブスク Checkout / Customer Portal。配線が外れると実 Stripe に契約を作る。',
            ),
            new ExternalFakeBinding(
                abstract: AutoRechargeGatewayInterface::class,
                real: CashierAutoRechargeGateway::class,
                fake: FakeAutoRechargeGateway::class,
                flag: self::PAYMENT_FLAG,
                allowedEnvironments: self::PAYMENT_ENVIRONMENTS,
                risk: 'オートリチャージの off-session invoice。配線が外れると実カードへ請求が飛ぶ。',
            ),
            new ExternalFakeBinding(
                abstract: TakeObjectStorage::class,
                real: TakeObjectStorage::class,
                fake: FakeTakeObjectStorage::class,
                flag: self::STORAGE_FLAG,
                allowedEnvironments: self::STORAGE_ENVIRONMENTS,
                risk: '撮影テイクの S3 presign / HeadObject。abstract が具象クラスのため、'
                    .'bind を消しても Laravel が本物を自動組み立てして無音で実 S3 を叩く。',
            ),
            new ExternalFakeBinding(
                abstract: RenderObjectStorage::class,
                real: RenderObjectStorage::class,
                fake: FakeRenderObjectStorage::class,
                flag: self::STORAGE_FLAG,
                allowedEnvironments: self::STORAGE_ENVIRONMENTS,
                risk: 'レンダ出力の S3 read/write。TakeObjectStorage と同じく具象クラス起点で無音になる。',
            ),
        ];
    }
}
```

> **注意 (実装者向け)**: `real` が `abstract` と同じ 2 entry (storage) は誤記ではない。
> **abstract が具象クラスで、本物側の bind が存在しない**ことがそのまま「登録漏れが無音になる」
> 理由なので、inventory でも同じクラスを書く。

### PHPStan 適合チェック

- [x] tests/ は PHPStan 解析対象外 (`phpstan.neon` の paths)。ただし docblock の
      `list<>` / `class-string` は規約として必ず書く
- [x] 配列返却ではなく値オブジェクト (`ExternalFakeBinding`) を返す
- [x] `final readonly` + `declare(strict_types=1)`

---

## 施策 2: 走査 helper

### 新規ファイル: `tests/Support/ExternalFakes/FakeClassCatalog.php`

fake クラスの**母集団をディレクトリ / 命名から動的導出**する (ハードコード一覧を持たない)。

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

/**
 * fake クラスの母集団導出 (ハードコード一覧を持たない = fake が増えたら自動で母集団に入る)。
 *
 * 定義 1「fake 実装クラス」 = app/**\/Fakes/ と app/**\/Testing/ 配下の全クラス
 * 定義 2「fake 命名クラス」 = app/ 配下でクラス名が Fake で始まる or Fake で終わるクラス
 *
 * PSR-4 (App\ => app/) 前提で path から FQCN を導出する。
 */
final class FakeClassCatalog
{
    /**
     * 定義 2 のうち定義 1 に属さなくてよい例外 (fake の実体ではなく配線基盤)。
     *
     * @return array<class-string, string> class => 理由
     */
    public static function placementExceptions(): array
    {
        return [
            \App\Providers\FakeExternalsServiceProvider::class
                => 'fake の実装ではなく唯一の配線 provider。Providers/ 配下にある必然性がある。',
            \App\Support\FakeStorageGate::class
                => 'fake の実装ではなく gate predicate (有効化条件の SSOT)。provider と action guard の双方が参照する。',
        ];
    }

    /**
     * 定義 1: fake 実装クラス。
     *
     * @return list<class-string>
     */
    public static function implementationClasses(): array { /* app/ を再帰走査し Fakes|Testing ディレクトリ配下の .php を FQCN 化 */ }

    /**
     * 定義 2: fake 命名クラス。
     *
     * @return list<class-string>
     */
    public static function namedClasses(): array { /* app/ 全 .php を FQCN 化し basename が Fake* / *Fake のものを返す */ }

    /**
     * app/ 配下の全 PHP ファイル (repo ルートからの相対パス)。
     *
     * @return list<string>
     */
    public static function sourceFiles(): array { /* RecursiveDirectoryIterator */ }

    /** path → FQCN (PSR-4: app/Foo/Bar.php => App\Foo\Bar) */
    public static function classFromPath(string $relativePath): string { /* … */ }
}
```

**実装メモ**:

- ディレクトリ判定は path segment の完全一致 (`/Fakes/` / `/Testing/`) で行う。
  `Fakes` を含む別名ディレクトリ (`FakesHelper` 等) を誤って含めない。
- `namedClasses()` の判定は **class basename** に対して
  `str_starts_with($name, 'Fake') || str_ends_with($name, 'Fake')`。
- 1 ファイル 1 クラス (Pint / PSR-4 が強制) を前提にし、FQCN は path から導出する
  (token 解析より安定)。この前提は docblock に明記する。

### 新規ファイル: `tests/Support/ExternalFakes/FakeWiringSourceScanner.php`

`token_get_all` ベースの字句解析器 (既存 `PrimaryKeyStaticQueryScanner` /
`AuthorizationMarkerScanner` と同じ流儀。**コメント / docblock は除去し、文字列リテラルは
「中身をコードとして解釈しない」が FQCN 完全一致だけは照合する**)。

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

/**
 * FakeExternalsServiceProvider の container 呼び出し形と、app/ 配下のクラス参照を
 * token ベースで抽出する純粋 helper (I/O を持たない。引数は PHP ソース文字列)。
 *
 * ★設計判断
 *  - 「禁止 API を列挙する」のではなく「**許可された呼び出し形を列挙し、残りを禁止する**」。
 *    API 名の列挙は未知 API (rebinding / 将来の Container API) で必ず抜けられる。
 *  - `make()` は**引数まで**固定する。`$this->app->make(SomeRegistrar::class)->register()` という
 *    委譲で配線を別クラスへ逃がせるため (既存の CannedPromptFakeRegistrar が現に委譲パターン)。
 *  - 誤検出は分類 1 行で解消できるが検出漏れは永久に気付けない、という非対称性から
 *    **過剰検出側 (fail-closed)** へ倒す。
 *
 * ★限界 (テストの docblock にも明記する)
 *  - 到達可能性を判定しない (`if (false) { … }` 中の呼び出しも候補になる)。
 *  - 変数経由の container (`$c = $this->app; $c->bind(...)`) は
 *    disallowedIndirectAccess() の `$this->app` の**非呼び出し出現**検出で捕まえる。
 *  - 非 bracketed namespace 前提 (Pint が強制)。
 */
final class FakeWiringSourceScanner
{
    /** 許可する `$this->app->` の呼び出し形 (method => 許可する第 1 引数の class-string list。null = 引数不問) */
    private const array ALLOWED_APP_CALLS = [
        'bind' => null,                                     // 差し替え本体 (組は bindPairs() で inventory 照合)
        'make' => [                                          // container 配線を行わないことを分類済みの 2 件のみ
            \App\Support\FakeStorageGate::class,
            \App\Services\AI\Testing\CannedPromptFakeRegistrar::class,
        ],
        'environment' => [],                                 // 空 list = 引数なしのみ許可
    ];

    /**
     * 許可外の `$this->app-><method>(…)` 呼び出し。
     *
     * @return list<string> 例: "singleton(App\Foo::class, …)" / "make(App\Bar::class)"
     */
    public static function disallowedContainerCalls(string $source): array;

    /**
     * `$this->app->bind(A::class, B::class)` の (abstract, concrete) 組。
     *
     * 第 2 引数が `::class` 定数でない (closure 等) 場合は concrete を `null` として返し、
     * 呼び出し側テストで「fake 差し替えは ::class 対 ::class の形に限る」を fail させる。
     *
     * @return list<array{abstract: string, concrete: string|null}>
     */
    public static function bindPairs(string $source): array;

    /**
     * `$this->app` 以外から container へ到達する形 (未知 API 経由の抜け道封じ)。
     *
     * 検出対象: `app(` / `resolve(` / `App::` facade / `Container::getInstance()` /
     * `$this->app` の**メソッド呼び出し以外の出現** (変数への代入・引数渡し)。
     *
     * @return list<string>
     */
    public static function disallowedIndirectAccess(string $source): array;

    /**
     * ソースが参照するクラス FQCN の集合。
     *
     * 収集元 3 系統:
     *  1. `use A\B\C;` (グループ use / alias 付きも解決)
     *  2. `\A\B\C::class` などの完全修飾名トークン (T_NAME_FULLY_QUALIFIED)
     *  3. **文字列リテラルの内容が FQCN と完全一致**するもの
     *     (`app()->bind('App\…\FakeX')` のような文字列経由の抜け道封じ。完全一致のみ、部分一致はしない)
     *
     * @param  list<class-string>  $candidates  照合する FQCN 母集団 (3 の完全一致に使う)
     * @return list<class-string>
     */
    public static function referencedClasses(string $source, array $candidates): array;
}
```

---

## 施策 3: 実証ベースの配線 gate

### 新規ファイル: `tests/Architecture/ExternalFakeWiringInvariantTest.php`

```php
<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\FakeExternalsServiceProvider;
use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Prompt;
use Tests\Support\ExternalFakes\ExternalFakeWiringInventory;
use Tests\Support\ExternalFakes\FakeWiringSourceScanner;

/*
 * 外部 fake 配線の実証 gate (c2c: external-fakes-wiring-gate 柱 1)。
 *
 * Laravel は abstract が具象クラスなら設定が無くても自動組み立てするため、
 * **差し替えの登録漏れは例外にならず、本物が静かに動く**。
 * したがって「宣言と実装の字面が一致するか」ではなく
 * 「**実際に解決して中身を確かめる**」層を持つ。
 *
 * 判定は必ず `$resolved::class === $expected` の**厳密一致**で行う
 * (FakeTakeObjectStorage は TakeObjectStorage を継承しているため、instanceof では
 *  fake でも real 判定が通ってしまう = 対照実行が無意味になる)。
 *
 * 責務境界: 本番混入防止の正本は ProductionEnvGuard (+ ProductionEnvGuardTest)。
 * 本 gate は非本番側の配線だけを見る。
 *
 * 状態リーク対策 (Architecture lane は RefreshDatabase も StrayLlmCallGuard も無い):
 *  - container の復元は Pest の test case ごとの app 再構築に任せる
 *    (対照と実証を**独立 test case** に分け、テスト順序に依存させない)。
 *  - config / env を書き換える test case は try/finally で原値復元する。
 *  - Prompt::$fake は static なので、test 本体の finally で stopFaking() し、
 *    **同一 test case 内で** isFaking() === false を assert する。
 *    afterEach はフェイルセーフとして併置する (検査表現ではない)。
 */

/** gate mutation (M3〜M7) の被覆表。キー集合は MUTATION_IDS と完全一致すること */
const MUTATION_COVERAGE = [
    'M3' => 'bootstrap/providers.php に FakeExternalsServiceProvider が登録されている',
    'M4' => 'FakeExternalsServiceProvider は AppServiceProvider より後に登録される (後勝ち)',
    'M5' => 'provider の bind 組は inventory と集合一致する',
    'M6' => 'provider の container 呼び出しは許可された形だけ',
    'M7' => '本番コードは fake 実装クラスを参照しない (FakeClassReferenceInvariantTest が担当)',
];

const MUTATION_IDS = ['M3', 'M4', 'M5', 'M6', 'M7'];

afterEach(function (): void {
    // フェイルセーフ: LLM fake の static がテスト境界を越えないようにする。
    if (Prompt::isFaking()) {
        Prompt::stopFaking();
    }
});

/** provider ソース (走査系テストの共通入力) */
function fakeWiringProviderSource(): string
{
    return (string) file_get_contents(base_path('app/Providers/FakeExternalsServiceProvider.php'));
}

/** inventory を Pest データセット化する */
dataset('external fake bindings', function (): Generator {
    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
        yield $binding->label() => [$binding];
    }
});
```

### テスト一覧 (施策 3)

| # | test 名 | 内容 | 捕まえる mutation |
|---|---|---|---|
| 3-1 | `対照: flag off では {binding} が real に厳密一致で解決される` | 素の app で `app($abstract)::class === $real` | (偽グリーン防止の土台) |
| 3-2 | `実証: flag on + allowlist 環境で {binding} が fake に厳密一致で解決される` | `config([$flag => true])` → `(new FakeExternalsServiceProvider($this->app))->register()` → `app($abstract)::class === $fake`。try/finally で config 復元 | **M1 / M2** |
| 3-3 | `provider 単体: flag on でも allowlist 外 env では {binding} が real のまま` | `$this->app['env'] = 'production'` → register() → real 厳密一致。try/finally で env / config 復元 | — |
| 3-4 | `provider 単体: 課金 flag on + allowlist 外 env は warning を出す` | `Log::spy()` → register() → `Log::shouldHaveReceived('warning')->once()` | — |
| 3-5 | `登録点: bootstrap/providers.php に FakeExternalsServiceProvider が登録されている` | `require base_path('bootstrap/providers.php')` の配列に含まれる | **M3** |
| 3-6 | `登録点: FakeExternalsServiceProvider は AppServiceProvider より後 (後勝ち)` | 同配列の index 比較 | **M4** |
| 3-7 | `登録点: 起動済み container に provider がロードされている` | `array_key_exists(FakeExternalsServiceProvider::class, $this->app->getLoadedProviders())` | **M3** |
| 3-8 | `網羅性: provider の bind 組が inventory と集合一致する` | `FakeWiringSourceScanner::bindPairs()` の (abstract, concrete) 集合 == inventory の (abstract, fake) 集合。`concrete === null` (closure 等) があれば fail | **M5** |
| 3-9 | `網羅性: provider の container 呼び出しは許可された形だけ` | `disallowedContainerCalls()` と `disallowedIndirectAccess()` が両方 0 件 | **M6** |
| 3-10 | `網羅性: provider が参照する fake 系クラスは inventory + 明示例外に一致する` | `referencedClasses(provider, implementationClasses ∪ namedClasses)` == inventory の fake 5 件 ∪ `providerReferenceExceptions()` 4 件 (= 9 件) | **M5** |
| 3-11 | `LLM: bughunt.local ∧ fake_llm=true でのみ Prompt fake が立ち、stopFaking で戻る` | env/flag の 4 組合せ (`bughunt.local`×on / `testing`×on / `local`×on / `bughunt.local`×off) を検証し、finally で `stopFaking()` → `isFaking() === false` を assert | — |
| 3-12 | `mutation coverage: MUTATION_COVERAGE のキー集合が MUTATION_IDS と一致する` | 集合一致 | (形骸化防止) |

**3-2 の実装骨子** (storage / payment の両方に効く共通形):

```php
test('実証: flag on + allowlist 環境で fake に厳密一致で解決される', function (ExternalFakeBinding $binding): void {
    $original = config($binding->flag);

    try {
        config([$binding->flag => true]);
        // 環境 allowlist に testing が含まれることを inventory 側で保証している
        // (storage は FakeStorageGate が testing ∧ runningUnitTests を要求する = Architecture lane で成立)
        expect($binding->allowedEnvironments)->toContain('testing');

        (new FakeExternalsServiceProvider($this->app))->register();

        // ★厳密一致 (instanceof は使わない。fake が real を継承しているため)
        expect(app($binding->abstract)::class)->toBe($binding->fake);
    } finally {
        config([$binding->flag => $original]);
    }
})->with('external fake bindings');
```

> **なぜ finally の config 復元だけで足りるか**: container の binding は復元しないが、
> Pest / Laravel `TestCase` は **test case ごとに Application を作り直す**ため、
> 上書きされた binding は次の test case へ持ち越されない。逆に
> 「flag を戻して provider を再実走すれば real に戻る」は**成立しない**
> (provider は early return するだけで巻き戻さない) ので、そういう検査は**書かない**。

### リスク

- Architecture lane に `RefreshDatabase` が無い → 解決対象の constructor が DB に触れると落ちる。
  実査で 5 本すべて DB 非依存を確認済み (`TakeObjectStorage` / `RenderObjectStorage` は
  constructor なし、`Cashier*Gateway` も constructor なし、`FakeTakeObjectStorage` は
  `FakeObjectStore` のみ注入)。**新 entry を足す実装者はここを必ず確認する**。
- 3-11 は `Prompt::$fake` (プロセスグローバル) を触るため、finally + afterEach の二重防御を必ず書く。

---

## 施策 4: 本番コードの fake 参照 全走査 gate

### 新規ファイル: `tests/Architecture/FakeClassReferenceInvariantTest.php`

```php
<?php

declare(strict_types=1);

use Tests\Support\ExternalFakes\FakeClassCatalog;
use Tests\Support\ExternalFakes\FakeWiringSourceScanner;

/*
 * 本番コードが fake のクラス名を 1 度も参照しないことの全走査
 * (c2c: external-fakes-wiring-gate 柱 3(c))。
 *
 * fake クラス名は**ディレクトリと命名から動的導出**する (ハードコード一覧を持たない)。
 * 現時点の違反は 0 件 = 「増えないこと」を今固定するのが最安。
 */

/** 参照 allowlist: fake 実装クラスを参照してよい本番ファイル (app/ 相対) */
const FAKE_REFERENCE_ALLOWED = [
    // 唯一の配線点 (何を fake にするかの決定はここに集約する)
    'Providers/FakeExternalsServiceProvider.php',
    // fake storage signed route の受け口 (FakeStorageGate 成立時のみ route 登録される)
    'Http/Controllers/Testing/PutFakeStorageObjectController.php',
    'Http/Controllers/Testing/GetFakeStorageObjectController.php',
];
```

| # | test 名 | 内容 | 捕まえる mutation |
|---|---|---|---|
| 4-1 | `配置規約: Fake 命名クラスは Fakes/ か Testing/ 配下にのみ存在する` | `namedClasses()` ⊆ `implementationClasses()` ∪ `placementExceptions()` のキー。例外は理由付き 2 件のみ | (母集団の逃げ道封じ) |
| 4-2 | `配置例外は 2 件から増えていない` | `placementExceptions()` のキー集合を固定 (増やすときは理由を書いた上で明示的に更新させる) | (形骸化防止) |
| 4-3 | `本番コードは fake 実装クラスを参照しない` | `sourceFiles()` を走査し、allowlist 外のファイルで `referencedClasses($src, implementationClasses())` が非空なら fail。ただし**そのファイル自身が fake 実装クラス**なら除外 (fake 同士の参照は正当) | **M7** |
| 4-4 | `参照 allowlist は 3 件から増えていない` | `FAKE_REFERENCE_ALLOWED` の集合を固定 | (形骸化防止) |

> 4-2 / 4-4 は「allowlist を黙って足して gate を無力化する」ことを 1 段止める。
> 更新するときは**理由をコメントで書いたうえで**両方を触ることになる (意図的な摩擦)。

### リスク

- 走査対象が `app/` 全ファイル (数百) になるが、`token_get_all` は 1 ファイル数 ms で
  既存の同型テスト (`ScenarioWritePathInventoryTest` 等) と同等コスト。実測で 1 秒未満の想定。
- 誤検出が出たら **allowlist を足すのではなく**、まず「本当に本番コードから fake を参照しているのか」を
  疑う (それが本 gate の目的)。

---

## 施策 5: 走査 helper 自身の positive/negative 固定

### 新規ファイル: `tests/Unit/Architecture/FakeWiringSourceScannerTest.php`

gate 自体がセキュリティ機構であり、**走査器が壊れたら gate は静かに無力化する**。
既存 `tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php` と同じ位置づけで恒久固定する。

| # | ケース | 期待 |
|---|---|---|
| 5-1 | `$this->app->bind(A::class, B::class)` | `bindPairs()` が 1 組を返す |
| 5-2 | `$this->app->bind(A::class, fn () => new B)` | `concrete === null` (呼び出し側テストで fail させる) |
| 5-3 | `$this->app->singleton(A::class, B::class)` | `disallowedContainerCalls()` が 1 件 (**M6** 相当) |
| 5-4 | `$this->app->rebinding(...)` / 未知 API | `disallowedContainerCalls()` が 1 件 (**API 名の列挙に依存しないこと**の証明) |
| 5-5 | `$this->app->make(FakeStorageGate::class)` | 許可 (0 件) |
| 5-6 | `$this->app->make(SomeRegistrar::class)` | `disallowedContainerCalls()` が 1 件 (**委譲による逃げ道**の封じ) |
| 5-7 | `app()->bind(...)` / `resolve(...)` / `Container::getInstance()->bind(...)` | `disallowedIndirectAccess()` が各 1 件 |
| 5-8 | `$container = $this->app;` (呼び出し以外の出現) | `disallowedIndirectAccess()` が 1 件 |
| 5-9 | コメント / docblock 中の `$this->app->singleton(` | 0 件 (誤検出しない) |
| 5-10 | 文字列リテラル `'App\Services\Billing\Fakes\FakeStripeGateway'` | `referencedClasses()` が検出する (完全一致のみ) |
| 5-11 | 文字列リテラル `'説明文に FakeStripeGateway と書いただけ'` | 検出しない (部分一致しない) |
| 5-12 | グループ use / alias 付き use | 正しく FQCN を解決する |

---

## 施策 6: ドキュメント追記

`docs/architecture.md` に「外部 fake 配線の不変条件」節を追記する (数行):

- fake の差し替えは `FakeExternalsServiceProvider` の `$this->app->bind(A::class, B::class)` **のみ**で行う。
- 新しい差し替えを足したら `tests/Support/ExternalFakes/ExternalFakeWiringInventory::bindings()` に
  登録する (未登録は `ExternalFakeWiringInvariantTest` が deny-by-default で fail させる)。
- 本番混入防止の正本は `ProductionEnvGuard` (配備前 + 起動時)。fake 配線 gate はこれを二重実装しない。
- fake 実装クラスは `app/**/Fakes/` か `app/**/Testing/` に置く (配置例外は 2 件のみ)。

> `docs/architecture.md` は既存の「シナリオ整合の共有不変条件」等と同じ粒度で書く。
> AGENTS.md への追記は**行わない** (家系の正典が未裁定のため、規約として固定するのは時期尚早。
> 裁定後に AGENTS.md ドメイン固有規約へ昇格させるかを判断する = 後続 TODO 候補)。

---

## テスト計画 (受入条件)

### 段階 1: 穴の実在確認 (実装前 / テストファースト)

以下の mutation を **1 つずつ**当てて `composer test -- --testsuite=Architecture --testsuite=Feature` を回し、
**すべて緑のまま**であることを記録する (= 現行検査に穴があることの実証)。
mutation は当てたら**必ず `git checkout -- <file>` で戻す**。コミットしない。

| ID | mutation |
|---|---|
| M1 | `app/Providers/FakeExternalsServiceProvider.php` の `$this->app->bind(AutoRechargeGatewayInterface::class, FakeAutoRechargeGateway::class);` 行を削除 |
| M2 | 同 `$this->app->bind(TakeObjectStorage::class, FakeTakeObjectStorage::class);` 行を削除 |
| M3 | `bootstrap/providers.php` から `FakeExternalsServiceProvider::class,` 行を削除 |
| M4 | 同ファイルで `FakeExternalsServiceProvider::class,` を `AppServiceProvider::class,` の直前へ移動 |
| M5 | provider に `$this->app->bind(\App\Services\Render\VideoComposer::class, \App\Services\Render\Fakes\FakeRenderObjectStorage::class);` を追加 (inventory 未登録の組) |
| M6 | provider の `bind(` を 1 箇所 `singleton(` に変更 |
| M7 | 任意の Service (例 `app/Services/Billing/QuotaService.php`) に `use App\Services\Billing\Fakes\FakeStripeGateway;` を追加 |

> M2 は既存 `FakeStorageRouteTest` が赤くなる可能性がある (fake storage が bind されなくなるため)。
> **その場合は「M2 は既存 Feature テストが部分的に捕まえている」と記録し、
> 段階 3 で Architecture gate 単独 (`--testsuite=Architecture`) でも赤くなることを確認する**
> (Architecture 側が不変条件の正本という位置づけを崩さない)。

### 段階 2: 実装

施策 1 → 2 → 5 (helper の Unit テスト) → 3 → 4 → 6 の順。
helper を先に固定してから gate を書く (走査器が壊れたら gate が無音で無力化するため)。

### 段階 3: mutation の再確認

段階 1 と同じ 7 つの mutation を再度当て、**それぞれ対応する gate が赤くなる**ことを確認する。

| ID | 赤くなるテスト |
|---|---|
| M1 / M2 | 3-2 (該当 binding の data case) |
| M3 | 3-5 / 3-7 |
| M4 | 3-6 |
| M5 | 3-8 (+ 3-10) |
| M6 | 3-9 |
| M7 | 4-3 |

### 段階 4: 全体検証

| コマンド | 期待 |
|---|---|
| `composer test -- --testsuite=Architecture` | 全緑 |
| `composer test -- --testsuite=Architecture` (2 回目) | 全緑 (**再実行安定性**。別プロセスなので同一プロセス内リークの検出ではない) |
| `composer test -- --testsuite=Architecture --order-by=random` (**seed をログに残す**) | 全緑 (同一プロセス内の test order 依存が無いこと。失敗時は記録した seed で再現する) |
| `composer test` | 全緑 (既存 Feature テストへの巻き添えが無いこと) |
| `composer phpstan` | 全緑 (tests/ は解析対象外だが app/ を触っていないことの確認を兼ねる) |
| `vendor/bin/pint --test` | 全緑 |
| `git status` | `app/` / `config/` / `bootstrap/` / `routes/` に差分が無いこと |

> テストレーンは**ホスト全体でのグローバルロック**配下 (AGENTS.md)。待たされるのは正常で、
> 30 秒ごとに heartbeat が stderr に出る。**kill しない / ロックファイルを消さない**。

### 不変条件の登録

- 「外部 fake の差し替えは inventory 登録が必須」→ `ExternalFakeWiringInvariantTest` (Architecture)
- 「本番コードは fake クラスを参照しない」→ `FakeClassReferenceInvariantTest` (Architecture)
- 「本番に fake flag を混入させない」→ **既存** `ProductionEnvGuard` + `ProductionEnvGuardTest` (再実装しない)

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 追加するのは tests/ 配下の新規 5 ファイル + `docs/architecture.md` 追記のみで、アプリコードに触れない。既存テストの改変も無い。他 TODO と競合する面が無く、単独 worktree で完結する |
| 競合リスク | `docs/architecture.md` の追記位置のみ (他タスクが同ファイルを触ると conflict しうるが、節追加なので解消は容易) |

---

## 段階分け

### このタスクでやる

- 柱 1 (実証ベースの配線検査) — 施策 1〜3, 5
- 柱 3(c) (本番コードの fake クラス名 全走査) — 施策 4
- ドキュメント追記 — 施策 6

### 後続 TODO 候補 (このタスクではやらない)

| 候補 | やらない理由 | 発火条件 |
|---|---|---|
| 柱 2: 別プロセスでの実測 (子プロセス probe) | agenda 未裁定で観測点の定義が家系正典に依存 / aicue は外部ログイン driver を fake 化しておらず主対象が無い / bug-hunt の env 注入は `bug-hunt-shard.sh self-test` が既に検証済み / コストが高い | 家系の正典が確定し、かつ aicue が外部ログイン driver か起動順依存の差し替えを持ったとき |
| 柱 3(b): 起動時の実環境変数 二重判定 | `ProductionEnvGuard` が配備前 (`production:preflight`) と起動時 (`AppServiceProvider::boot`) の両方で fake flag 3 本を検査済み。残差は「config キャッシュを信用せず実 env を読む」ことだけで、具体的な事故シナリオを踏んでいない (思考原則 2) | production で fake flag 起因の incident / near-miss が出た / `config:cache` 前提の deploy 手順を変えた / capability flag が 3 本から増えた / 家系 agenda が「3 段そろえる」で裁定された |
| AGENTS.md ドメイン固有規約への昇格 | 家系の正典が未裁定。今 AGENTS.md に固定すると裁定後に書き換えになる | 家系 agenda の裁定後 |
| 宣言 SSOT (`config/testing.php` ⇔ 実装の一致) | 別 feature `external-fakes-declaration` の範囲 | — |
| 未登録の外部通信の実行時遮断 | 別 feature `external-egress-default-deny` の範囲 | — |

---

## 関連する現行コード (抜粋)

### app/Providers/FakeExternalsServiceProvider.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Testing\GetFakeStorageObjectController;
use App\Http\Controllers\Testing\PutFakeStorageObjectController;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use App\Services\Capture\Fakes\FakeTakeObjectStorage;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Render\Fakes\FakeRenderObjectStorage;
use App\Services\Render\RenderObjectStorage;
use App\Support\FakeStorageGate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 外部サービス fake の配線 (系統別に capability flag を分離)。
 *
 * bootstrap/providers.php で AppServiceProvider より後に登録する (後勝ち rebind)。
 * fail-secure 二軸:
 * 1. flag === true (既定 false = 完全 no-op)
 * 2. 環境 allowlist。denylist (非 production) ではなく allowlist で倒す = staging 等の
 *    未知環境で flag が誤設定されても fake しない (warning ログで検出可能にする)。
 *    production は加えて ProductionEnvGuard が flag=true を deploy 時 fail-fast で拒否する。
 *
 * fake 対象は 2 系統で capability flag も allowlist も異なる:
 * - Stripe 課金 gateway: config('testing.fake_externals') が capability flag。
 *   container bind (per-test 隔離が効くため testing 可)。register() で配線。
 * - LLM (Prism): config('testing.fake_llm') が capability flag (fake_externals から分離)。
 *   Prompt::$fake は static (プロセスグローバル) のため testing/local を除外し bughunt.local のみ配線。
 *   bughunt 既定は real-llm (fake_llm off) で install しない。--fake-llm 時のみ install する。
 *   LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
 */
class FakeExternalsServiceProvider extends ServiceProvider
{
    /** Stripe 課金 gateway fake を許可する環境 allowlist (container bind。per-test 隔離が効くため testing 可) */
    private const array PAYMENT_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /** LLM (Prism) fake の install を許可する環境 allowlist (Prompt::$fake は static。testing/local を除外) */
    private const array LLM_FAKE_ENVIRONMENTS = ['bughunt.local'];

    public function register(): void
    {
        // capability ごとに独立 private method へ分離する (early return が他 capability を巻き込まない)。
        $this->registerPaymentFakes(); // Stripe: fake_externals 依存 (挙動不変)
        $this->registerStorageFakes(); // storage: fake_storage (FakeStorageGate) 依存 — 独立
    }

    public function boot(): void
    {
        $this->bootLlmFake();       // LLM: fake_llm 依存 (挙動不変)
        $this->bootStorageRoutes(); // storage signed route — 独立
    }

    /** Stripe 課金 gateway fake (fake_externals + PAYMENT_FAKE_ENVIRONMENTS。挙動不変) */
    private function registerPaymentFakes(): void
    {
        if (config('testing.fake_externals') !== true) {
            return;
        }

        $environment = $this->app->environment();
        if (! in_array($environment, self::PAYMENT_FAKE_ENVIRONMENTS, true)) {
            Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
                'environment' => $environment,
            ]);

            return;
        }

        // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
        $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
        $this->app->bind(AutoRechargeGatewayInterface::class, FakeAutoRechargeGateway::class);
    }

    /** LLM (Prism) fake (fake_llm + LLM_FAKE_ENVIRONMENTS。挙動不変) */
    private function bootLlmFake(): void
    {
        // LLM fake は fake_llm (既定 false = real LLM) で判定する。bughunt 既定は real-llm で、
        // --fake-llm 指定時のみ TESTING_FAKE_LLM=true が注入され install される。
        // Stripe fake (register) は従来どおり fake_externals 依存で不変。
        if (config('testing.fake_llm') !== true) {
            return;
        }

        // LLM fake は Prompt::$fake (プロセスグローバル static) を書き換えるため、
        // per-test で static を占有する testing、実 API 検証を潰す local は allowlist から除外する。
        // LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
        // (Stripe と違い warning は出さない: testing/local の除外は誤設定ではなく設計上の除外)
        if (! in_array($this->app->environment(), self::LLM_FAKE_ENVIRONMENTS, true)) {
            return;
        }

        // Browser lane (tests/Pest.php) と同一の install API を使う (Prompt::installFake の封じ込め)。
        $this->app->make(CannedPromptFakeRegistrar::class)->install();
    }

    /**
     * storage fake: FakeStorageGate 成立時のみ concrete → fake へ rebind (gate = predicate SSOT)。
     * env allowlist / production 拒否は gate に一元化される。
     */
    private function registerStorageFakes(): void
    {
        if (! $this->app->make(FakeStorageGate::class)->enabled()) {
            return;
        }

        $this->app->bind(TakeObjectStorage::class, FakeTakeObjectStorage::class);
        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);
    }

    /** storage fake の signed route (gate 成立時のみ。web CSRF group 外 = signed のみ) */
    private function bootStorageRoutes(): void
    {
        if (! $this->app->make(FakeStorageGate::class)->enabled()) {
            return;
        }

        // 冪等化: boot() が複数回走っても (route:cache 併用・テストの provider 再実走等)
        // 同名 route を二重登録しない。通常の bootstrap では未登録 = そのまま登録される。
        if (Route::has('bughunt.storage.put')) {
            return;
        }

        Route::middleware('signed')->group(function (): void {
            Route::put('/_fake-storage/object', PutFakeStorageObjectController::class)
                ->name('bughunt.storage.put');
            Route::get('/_fake-storage/object', GetFakeStorageObjectController::class)
                ->name('bughunt.storage.get');
        });
    }
}
```

### app/Support/FakeStorageGate.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * storage fake の有効化 predicate の SSOT (fail-secure 二軸)。
 *
 * route 登録 (FakeExternalsServiceProvider) と signed route action guard の双方が
 * 本メソッドを参照する (登録条件より実行時条件が弱いと route cache 残存で素通りするため
 * 完全一致させる)。
 *
 * 二軸:
 * 1. capability flag: config('testing.fake_storage') === true (既定 false = 完全 no-op)
 * 2. env allowlist: bughunt.local ∨ (testing ∧ runningUnitTests)
 *    - bughunt.local: 実 bug-hunt runtime
 *    - testing ∧ runningUnitTests: 自動テストのみ (testing を HTTP 実行環境として素通ししない)
 *
 * production は ProductionEnvGuard が flag=true を deploy 時 fail-fast で拒否する (二重防御)。
 */
final readonly class FakeStorageGate
{
    public function __construct(private Application $app) {}

    public function enabled(): bool
    {
        if (config('testing.fake_storage') !== true) {
            return false;
        }

        $env = $this->app->environment();
        if ($env === 'bughunt.local') {
            return true;
        }

        return $env === 'testing' && $this->app->runningUnitTests();
    }
}
```

### bootstrap/providers.php (全文)

```php
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

### tests/Pest.php (lane 構成の抜粋 L26-70)

```php
/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature / Unit は TestCase + RefreshDatabase。
| Architecture はファイル走査中心のため DB を使わない (TestCase のみ)。
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Vite manifest 不在でも view が描画できるよう test では Vite をスタブする
        $this->withoutVite();

        // 未 fake の LLM 呼び出しを fail-fast させる guard。
        // (1) accumulator clear → (2) Prompt::stopFaking() → (3) PrismManager 差し替え
        // の 3 段で前テスト残留状態を一掃しつつ install する。テスト本体で
        // Prism::fake([...]) / Prompt::fake([...]) を呼ぶと guard は透過される。
        // Prism 基盤を直接テストする稀な Unit テストのみ
        // StrayLlmCallGuard::uninstallForTest($this->app) で opt-out できる。
        StrayLlmCallGuard::install($this->app);
    })
    ->afterEach(function (): void {
        try {
            // stray call が記録されていれば test を fail させる (Service 層の
            // try/catch fallback で guard 例外が握り潰されてもここで必ず赤くなる)
            StrayLlmCallGuard::flushAndFailIfStray();
        } finally {
            // flush が throw しても次テストへ accumulator / Prompt::$fake を漏らさない
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
        }
    })
    ->in('Feature', 'Unit');

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
    })
    ->in('Architecture');

```

### tests/Feature/Providers/FakeExternalsServiceProviderTest.php (既存。改変しない)

```php
<?php

declare(strict_types=1);

use App\Prompts\ExampleSummaryPrompt;
use App\Providers\FakeExternalsServiceProvider;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\CashierTicketCheckoutGateway;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Prompt;

/*
 * FakeExternalsServiceProvider: config('testing.fake_externals') が capability flag。
 * fail-secure 二軸 (flag 既定 false = 完全 no-op / 環境 allowlist) を固定する。
 * Pest はテスト毎に app を再構築するため register() 再実行の container 汚染は漏れない。
 *
 * boot() は LLM (Prism) fake を配線する。Prompt::$fake は static (プロセスグローバル) のため
 * allowlist は bughunt.local のみ (testing/local は除外)。static リークを避けるため
 * afterEach で必ず stopFaking する (テスト本体が例外で落ちても到達させる)。
 */

afterEach(function (): void {
    // boot() が install した Prompt::$fake (static) を各テスト境界でリークさせない。
    Prompt::stopFaking();
});

test('既定 (flag=false) では両 gateway とも Cashier 実装に解決される', function (): void {
    expect(config('testing.fake_externals'))->toBeFalse();
    expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(CashierTicketCheckoutGateway::class);
    expect(app(StripeGatewayInterface::class))->toBeInstanceOf(CashierStripeGateway::class);
});

test('flag=true かつ allowlist 環境 (testing) では両 gateway が fake に解決される', function (): void {
    config(['testing.fake_externals' => true]);
    (new FakeExternalsServiceProvider($this->app))->register();

    expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(FakeTicketCheckoutGateway::class);
    expect(app(StripeGatewayInterface::class))->toBeInstanceOf(FakeStripeGateway::class);
});

test('flag=true でも allowlist 外の環境 (production) では fake に bind せず warning を出す', function (): void {
    config(['testing.fake_externals' => true]);
    Log::spy();

    $originalEnv = $this->app['env'];
    try {
        $this->app['env'] = 'production';
        (new FakeExternalsServiceProvider($this->app))->register();
    } finally {
        $this->app['env'] = $originalEnv;
    }

    expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(CashierTicketCheckoutGateway::class);
    expect(app(StripeGatewayInterface::class))->toBeInstanceOf(CashierStripeGateway::class);
    Log::shouldHaveReceived('warning')->once();
});

/*
 * boot(): LLM (Prism) fake は config('testing.fake_llm') が capability flag (fake_externals から分離)。
 * 環境 allowlist は bughunt.local のみ。bughunt 既定は real-llm (fake_llm off) で install しない。
 * 各テストは env と config を try/finally で原値復元する (static/config 汚染を漏らさない)。
 */

test('boot: env=bughunt.local ∧ fake_llm=true で Prompt fake が有効になり canned を返す', function (): void {
    // 万一の FX 解決 HTTP を stray にしない防御。
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);

    $originalEnv = $this->app['env'];
    $originalFlag = config('testing.fake_llm');
    try {
        config(['testing.fake_llm' => true]);
        $this->app['env'] = 'bughunt.local';
        (new FakeExternalsServiceProvider($this->app))->boot();

        expect(Prompt::isFaking())->toBeTrue();

        // 代表 prompt が canned を返す (stray call 0 = 実 API 未到達)。
        $summary = ExampleSummaryPrompt::make('本文')->executeSync();
        expect($summary)->toBeString();
        expect(trim((string) $summary))->not->toBe('');
    } finally {
        Prompt::stopFaking();
        config(['testing.fake_llm' => $originalFlag]);
        $this->app['env'] = $originalEnv;
    }
});

test('boot: env=testing ∧ fake_llm=true では Prompt::$fake に触れない (static 占有を避ける)', function (): void {
    $originalFlag = config('testing.fake_llm');
    try {
        // env は既定の testing のまま。
        config(['testing.fake_llm' => true]);
        (new FakeExternalsServiceProvider($this->app))->boot();

        expect(Prompt::isFaking())->toBeFalse();
    } finally {
        config(['testing.fake_llm' => $originalFlag]);
    }
});

test('boot: env=local ∧ fake_llm=true では Prompt::$fake に触れない (実 API 検証を潰さない)', function (): void {
    $originalEnv = $this->app['env'];
    $originalFlag = config('testing.fake_llm');
    try {
        config(['testing.fake_llm' => true]);
        $this->app['env'] = 'local';
        (new FakeExternalsServiceProvider($this->app))->boot();

        expect(Prompt::isFaking())->toBeFalse();
    } finally {
        config(['testing.fake_llm' => $originalFlag]);
        $this->app['env'] = $originalEnv;
    }
});

test('boot: fake_llm=false では bughunt.local でも Prompt fake を配線しない (real 経路)', function (): void {
    $originalEnv = $this->app['env'];
    $originalFlag = config('testing.fake_llm');
    try {
        config(['testing.fake_llm' => false]);
        $this->app['env'] = 'bughunt.local';
        (new FakeExternalsServiceProvider($this->app))->boot();

        expect(Prompt::isFaking())->toBeFalse();
    } finally {
        config(['testing.fake_llm' => $originalFlag]);
        $this->app['env'] = $originalEnv;
    }
});

test('boot: fake_externals=true でも fake_llm=false なら install しない (系統分離の回帰)', function (): void {
    // Stripe fake が立っていても LLM は real (fake_externals と fake_llm の分離を固定)。
    $originalEnv = $this->app['env'];
    $originalExternals = config('testing.fake_externals');
    $originalLlm = config('testing.fake_llm');
    try {
        config(['testing.fake_externals' => true]);
        config(['testing.fake_llm' => false]);
        $this->app['env'] = 'bughunt.local';
        (new FakeExternalsServiceProvider($this->app))->boot();

        expect(Prompt::isFaking())->toBeFalse();
    } finally {
        config(['testing.fake_externals' => $originalExternals]);
        config(['testing.fake_llm' => $originalLlm]);
        $this->app['env'] = $originalEnv;
    }
});
```

---

## 特に見てほしい点

1. 施策 3 の test 3-2 (実証) が、payment 系と storage 系の**両方**で本当に成立するか。
   storage は FakeStorageGate の predicate (`testing` ∧ `runningUnitTests`) を通る必要がある。
2. mutation 表 (M1〜M7) と gate の対応に漏れ / 成立しない組み合わせが無いか。
   特に M5 の「inventory 未登録の bind を追加」が本当に 3-8 で赤くなるか。
3. `FakeWiringSourceScanner` の API 分割 (disallowedContainerCalls / bindPairs /
   disallowedIndirectAccess / referencedClasses) で、実装者が迷わず書けるか。
4. スコープが過大になっていないか (施策 5 の走査器 Unit テスト 12 ケースは必要か)。

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

> 本タスクが触るのは **`tests/` の新規 7 ファイル + `docs/architecture.md` の追記**だけ。
> アプリコード (`app/` / `config/` / `bootstrap/` / `routes/`) は 1 行も変更しない。
> 禁止事項 4〜9 は非該当。1 が本タスクそのもの。

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

**変更ファイルは 8 本 (新規 7 + 追記 1)。既存テストの改変は 0 本。**

内訳: `tests/` の新規 7 ファイル + `docs/architecture.md` の追記 1。
**アプリコード (`app/` / `config/` / `bootstrap/` / `routes/`) は 1 行も変更しない。**

### パス表記の統一 (実装者向け・重要)

走査系のパスは**すべて repo ルート相対**で扱う (`app/Providers/FakeExternalsServiceProvider.php` /
`bootstrap/providers.php` / `routes/web.php`)。施策 4 で走査根を `app/` の外へ広げるため、
`app/` 相対では表現できない。allowlist もすべて repo ルート相対で書く。
例外は `FakeClassCatalog::classFromPath()` だけで、これは **`app/` 配下の repo 相対パスのみ**を
受け取り PSR-4 で FQCN 化する。

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

    /**
     * データセット名 (テスト出力に出る識別子)。
     *
     * FQCN ベースにする (class basename だと将来 namespace 違いの同名クラスが増えたとき
     * dataset 名が衝突する)。
     */
    public function label(): string
    {
        return str_replace('\\', '.', $this->abstract);
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
     * 定義 1: fake 実装クラス。母集団は app/ のみ (PSR-4 のクラス定義があるのは app/ だけ)。
     *
     * @return list<class-string>
     */
    public static function implementationClasses(): array { /* app/ を再帰走査し Fakes|Testing ディレクトリ配下の .php を FQCN 化 */ }

    /**
     * 定義 2: fake 命名クラス。母集団は app/ のみ。
     *
     * @return list<class-string>
     */
    public static function namedClasses(): array { /* app/ 全 .php を FQCN 化し basename が Fake* / *Fake のものを返す */ }

    /**
     * 参照走査の対象となる本番コード全ファイル (**repo ルート相対**パス)。
     *
     * 走査根は 4 つ: app/ • routes/ • config/ • bootstrap/
     * (app/ だけだと route 定義や config から fake を直参照する抜け道が残るため)。
     * 対象は **`.php` 拡張子のファイルのみ** (他の拡張子は走査しない)。
     *
     * @return list<string> 例: 'app/Providers/FakeExternalsServiceProvider.php', 'routes/web.php'
     */
    public static function scanFiles(): array { /* RecursiveDirectoryIterator × 4 root */ }

    /**
     * repo 相対パス → FQCN。**app/ 配下のパスのみ**を受け取る (PSR-4: app/Foo/Bar.php => App\Foo\Bar)。
     * app/ 以外を渡された場合は InvalidArgumentException を投げる (誤用を静かに通さない)。
     *
     * @return class-string
     */
    public static function classFromPath(string $repoRelativePath): string { /* … */ }
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
 * ★short class name の FQCN 正規化 (これが無いと 3-8 の集合一致が成立しない)
 *  現行 provider は `use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;` した上で
 *  `FakeTicketCheckoutGateway::class` と書く。したがって scanner はソース先頭の
 *  **namespace 宣言 + use map** を先に構築し、`A::class` の `A` を FQCN へ正規化する。
 *  対応する形:
 *   - `use A\B\C;` (末尾セグメントが short name)
 *   - `use A\B\C as D;` (alias)
 *   - `use A\B\{C, D as E};` (group use)
 *   - `\A\B\C::class` (完全修飾 = そのまま)
 *   - `C::class` で use に無いもの → 現在の namespace 配下として解決する
 *  この正規化は bindPairs() / disallowedContainerCalls() の make 引数照合 /
 *  referencedClasses() の 3 つが**共有**する (実装は private static な 1 メソッドに集約する)。
 *
 * ★限界 (テストの docblock にも明記する)
 *  - 到達可能性を判定しない (`if (false) { … }` 中の呼び出しも候補になる)。
 *  - 変数経由の container (`$c = $this->app; $c->bind(...)`) は
 *    disallowedIndirectAccess() の `$this->app` の**非呼び出し出現**検出で捕まえる。
 *  - 非 bracketed namespace 前提 (Pint が強制)。
 */
final class FakeWiringSourceScanner
{
    /**
     * 許可する `$this->app->` の呼び出し形。
     * value = 許可する第 1 引数の class-string list。null = 引数不問 / 空 list = 引数なしのみ許可。
     *
     * @var array<string, list<class-string>|null>
     */
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
     * 検出する 4 種 (**引数の個数と形まで固定する = fail-closed**):
     *  1. ALLOWED_APP_CALLS に無い method 名
     *  2. `make()` が「**位置引数ちょうど 1 個**」でない、またはその引数が許可 class-string 以外
     *     (委譲による逃げ道封じ)
     *  3. `bind()` が「**位置引数ちょうど 2 個、かつ両方が `::class` 定数**」でない呼び出し。
     *     - 例 A: `$this->app->bind($abstract, FakeRenderObjectStorage::class)`
     *       — 変数 abstract は bindPairs() が読み取れず、既存 fake を使えば参照集合も変わらないため、
     *         ここで禁止しないと 3-8 にも 3-10 にも現れない**偽グリーン**になる。
     *     - 例 B: `$this->app->bind(A::class, B::class, true)`
     *       — 第 3 引数 `$shared` は **singleton 相当**。M6 で `singleton()` を禁止した意図を
     *         同じ意味の `bind(…, true)` で回避できてしまうため、**引数 3 個も禁止**する。
     *  4. **名前付き引数 / spread unpack** を伴う呼び出し (現行 provider に不要なので fail-closed)
     *
     * @return list<string> 例: "singleton(App\Foo::class, …)" / "bind(\$abstract, …)" / "bind(A::class, B::class, true)"
     */
    public static function disallowedContainerCalls(string $source): array;

    /**
     * `$this->app->bind(A::class, B::class)` の (abstract, concrete) 組 (**FQCN 正規化済み**)。
     *
     * 第 2 引数が `::class` 定数でない (closure 等) 場合は concrete を `null` として返し、
     * 呼び出し側テストで「fake 差し替えは ::class 対 ::class の形に限る」を fail させる。
     *
     * @return list<array{abstract: class-string, concrete: class-string|null}>
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
     * 収集元 4 系統:
     *  1. `use A\B\C;` (グループ use / alias 付きも解決)
     *  2. `\A\B\C::class` などの完全修飾名トークン (T_NAME_FULLY_QUALIFIED)
     *  3. **文字列リテラルの内容が FQCN と完全一致**するもの
     *     (`app()->bind('App\…\FakeX')` のような文字列経由の抜け道封じ。完全一致のみ、部分一致はしない)
     *  4. **candidate の class basename に一致する T_STRING** を namespace / use map で解決したもの
     *     (`use` も完全修飾も無い**同一 namespace 内の short name 参照**を拾う。
     *      `FakeStorageGate::class` / `new FakeStorageGate` / `FakeStorageGate::enabled()` /
     *      型宣言・戻り値型・プロパティ型の `FakeStorageGate` がこれに当たる。
     *      配置例外クラスは通常ディレクトリに置かれるため、これが無いと現実的な抜け道になる)
     *
     * @param  list<class-string>  $candidates  照合する FQCN 母集団
     *                                          (収集元 3 の文字列完全一致と、収集元 4 の basename 照合に使う)
     * @return list<class-string>
     */
    public static function referencedClasses(string $source, array $candidates): array;
}
```

**読み取りの診断性 (Codex Round 1 Suggestion)**: ソースの読み取りは
`(string) file_get_contents()` で握り潰さない。読み取り helper 側で
`is_string($source) && $source !== ''` を assert し、失敗時は
「どのファイルが読めなかったか」が出るようにする (空文字だと gate は赤くなるが原因が見えない)。

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

/*
 * ソース走査系 mutation (M3〜M7) の被覆表。
 * M1 / M2 (inventory entry の bind 削除) は 3-2 の data-driven 解決検査が自動被覆するため
 * 本 map の対象外 (entry を足せば検査も自動で増える構造になっている)。
 *
 * 定数名は他の Architecture テストと衝突しないよう prefix する
 * (Pest のファイル直下 const / function はグローバル空間に出る)。
 */
const EXTERNAL_FAKE_WIRING_MUTATION_COVERAGE = [
    'M3' => 'bootstrap/providers.php に FakeExternalsServiceProvider が登録されている',
    'M4' => 'FakeExternalsServiceProvider は AppServiceProvider より後に登録される (後勝ち)',
    'M5' => 'provider の bind 組は inventory と集合一致する',
    'M6' => 'provider の container 呼び出しは許可された形だけ',
    'M7' => '本番コードは fake クラスを参照しない (FakeClassReferenceInvariantTest が担当)',
];

const EXTERNAL_FAKE_WIRING_SOURCE_MUTATION_IDS = ['M3', 'M4', 'M5', 'M6', 'M7'];

afterEach(function (): void {
    // フェイルセーフ: LLM fake の static がテスト境界を越えないようにする。
    if (Prompt::isFaking()) {
        Prompt::stopFaking();
    }
});

/** provider ソース (走査系テストの共通入力)。読み取り失敗を空文字で握り潰さない */
function externalFakeWiringProviderSource(): string
{
    $source = file_get_contents(base_path('app/Providers/FakeExternalsServiceProvider.php'));
    expect($source)->toBeString()->not->toBe('');

    return (string) $source;
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
| 3-2 | `実証: flag on + allowlist 環境 {env} で {binding} が fake に厳密一致で解決される` | **binding × allowedEnvironments** の data-driven。`$this->app['env'] = $env` → `config([$flag => true])` → `(new FakeExternalsServiceProvider($this->app))->register()` → `app($abstract)::class === $fake`。try/finally で env / config 復元 | **M1 / M2** |
| 3-3 | `provider 単体: flag on でも allowlist 外 env ({production, staging}) では {binding} が real のまま` | **binding × {`production`, `staging`}** の data-driven。`staging` を入れるのは「未知環境で誤設定されても fake しない」= allowlist 方式の趣旨そのものを固定するため | — |
| 3-4 | `provider 単体: 課金 flag on + allowlist 外 env は warning を出す` | `Log::spy()` → register() → `Log::shouldHaveReceived('warning')->once()` | — |
| 3-5 | `登録点: bootstrap/providers.php に FakeExternalsServiceProvider が登録されている` | `require base_path('bootstrap/providers.php')` の配列に含まれる | **M3** |
| 3-6 | `登録点: FakeExternalsServiceProvider は AppServiceProvider より後 (後勝ち)` | 同配列の index 比較 | **M4** |
| 3-7 | `登録点: 起動済み container に provider がロードされている` | `array_key_exists(FakeExternalsServiceProvider::class, $this->app->getLoadedProviders())` | **M3** |
| 3-8 | `網羅性: provider の bind 組が inventory と集合一致する` | `FakeWiringSourceScanner::bindPairs()` の (abstract, concrete) 集合 == inventory の (abstract, fake) 集合。`concrete === null` (closure 等) があれば fail | **M5** |
| 3-9 | `網羅性: provider の container 呼び出しは許可された形だけ` | `disallowedContainerCalls()` と `disallowedIndirectAccess()` が両方 0 件 | **M6** |
| 3-10 | `網羅性: provider が参照する fake 系クラスは inventory + 明示例外に一致する` | `referencedClasses(provider, implementationClasses ∪ namedClasses)` == inventory の fake 5 件 ∪ `providerReferenceExceptions()` 4 件 (= 9 件) | **M5** |
| 3-11 | `LLM: bughunt.local ∧ fake_llm=true でのみ Prompt fake が立ち、stopFaking で戻る` | env/flag の 4 組合せ (`bughunt.local`×on / `testing`×on / `local`×on / `bughunt.local`×off) を検証し、finally で `stopFaking()` → `isFaking() === false` を assert | — |
| 3-12 | `mutation coverage: 被覆表のキー集合が EXTERNAL_FAKE_WIRING_SOURCE_MUTATION_IDS と一致する` | 集合一致 | (形骸化防止) |

**3-2 の実装骨子** (storage / payment の両方に効く共通形):

```php
test('実証: flag on + allowlist 環境で fake に厳密一致で解決される',
    function (ExternalFakeBinding $binding, string $environment): void {
        $originalFlag = config($binding->flag);
        $originalEnv = $this->app['env'];

        try {
            // 環境ごとに実証する (testing だけだと local / bughunt.local の allowlist が固定されない)。
            // storage は FakeStorageGate が testing ∧ runningUnitTests を要求するが、
            // Architecture lane では runningUnitTests() が true なので成立する。
            $this->app['env'] = $environment;
            config([$binding->flag => true]);

            (new FakeExternalsServiceProvider($this->app))->register();

            // ★厳密一致 (instanceof は使わない。fake が real を継承しているため)
            expect(app($binding->abstract)::class)->toBe($binding->fake);
        } finally {
            config([$binding->flag => $originalFlag]);
            $this->app['env'] = $originalEnv;
        }
    }
)->with('external fake bindings and allowed environments');
```

dataset は inventory から **binding × allowedEnvironments** で展開する:

```php
dataset('external fake bindings and allowed environments', function (): Generator {
    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
        foreach ($binding->allowedEnvironments as $environment) {
            yield $binding->label().' @ '.$environment => [$binding, $environment];
        }
    }
});
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
 *
 * ★走査候補 (Codex Round 1 Critical): 「fake 実装クラス」だけでは足りない。
 *   配置例外 (FakeExternalsServiceProvider / FakeStorageGate) を業務コードが参照しても
 *   検出できず偽グリーンになるため、候補は
 *   implementationClasses() ∪ array_keys(placementExceptions()) とする。
 *
 * ★走査根 (Codex Round 1 Warning): app/ だけだと routes/ に Testing controller を直書きする、
 *   config/ にクラス名を書く、といった抜け道が残る。「本番コード全走査」を名乗る以上、
 *   app/ • routes/ • config/ • bootstrap/ の 4 根を走査する。
 */

/** 参照 allowlist: fake 系クラスを参照してよい本番ファイル (**repo ルート相対**) */
const FAKE_REFERENCE_ALLOWED = [
    // 唯一の配線点 (何を fake にするかの決定はここに集約する)
    'app/Providers/FakeExternalsServiceProvider.php',
    // fake storage signed route の受け口 (FakeStorageGate 成立時のみ route 登録される)
    'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
    'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
    // provider 登録点。FakeExternalsServiceProvider (配置例外クラス) を必ず参照する
    'bootstrap/providers.php',
];
```

| # | test 名 | 内容 | 捕まえる mutation |
|---|---|---|---|
| 4-1 | `配置規約: Fake 命名クラスは Fakes/ か Testing/ 配下にのみ存在する` | `namedClasses()` ⊆ `implementationClasses()` ∪ `placementExceptions()` のキー。例外は理由付き 2 件のみ | (母集団の逃げ道封じ) |
| 4-2 | `配置例外は 2 件から増えていない` | `placementExceptions()` のキー集合を固定 (増やすときは理由を書いた上で明示的に更新させる) | (形骸化防止) |
| 4-3 | `本番コードは fake クラスを参照しない` | `scanFiles()` (4 根) を走査し、allowlist 外のファイルで `referencedClasses($src, implementationClasses() ∪ array_keys(placementExceptions()))` が非空なら fail。ただし**そのファイル自身が fake 実装クラス**なら除外 (fake 同士の参照は正当。`FakeExternalUrl` 等) | **M7** |
| 4-4 | `参照 allowlist は 4 件から増えていない` | `FAKE_REFERENCE_ALLOWED` の集合を固定 (repo 相対パス) | (形骸化防止) |

> 4-2 / 4-4 は「allowlist を黙って足して gate を無力化する」ことを 1 段止める。
> 更新するときは**理由をコメントで書いたうえで**両方を触ることになる (意図的な摩擦)。

### リスク

- 走査対象は 4 根の全ファイル (数百) になるが、`token_get_all` は 1 ファイル数 ms で
  既存の同型テスト (`ScenarioWritePathInventoryTest` 等) と同等コスト。実測で 1 秒未満の想定。
- `config/testing.php` は fake の **flag** を宣言するが fake **クラス名**は書かないため、
  走査根に config/ を足しても現状違反 0 のまま (実装時に確認すること)。
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
| 5-12 | グループ use (`use App\{A, B};`) | 正しく FQCN を解決する |
| 5-13 | `use App\Services\Billing\TicketCheckoutGateway;` + `$this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class)` | `bindPairs()` が **FQCN 正規化済み**の組を返す (現行 provider の実パターン) |
| 5-14 | `use App\Foo\Bar as Baz;` + `Baz::class` | alias 経由でも FQCN に正規化される |
| 5-15 | `$this->app->make(FakeStorageGate::class)->enabled()` の chain | 許可 (0 件)。chain していても make の引数照合が効く |
| 5-16 | `$this->app->bind($abstract, FakeRenderObjectStorage::class)` (変数 abstract) | `disallowedContainerCalls()` が 1 件 (**偽グリーン封じ**。bindPairs が読み取れない形を bind 側で禁止する) |
| 5-17 | `namespace App\Support;` 直下で `use` 無しの `FakeStorageGate::class` / `new FakeStorageGate` | `referencedClasses()` が検出する (**同一 namespace short name** の抜け道封じ) |
| 5-18 | `$this->app->bind(A::class, B::class, true)` / 引数付き `make(A::class, [])` / 名前付き引数 `bind(abstract: A::class, …)` | `disallowedContainerCalls()` が各 1 件 (**`bind(…, true)` = singleton 相当**による M6 回避を封じる) |

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
| M1 / M2 | 3-2 (該当 binding × allowed env の data case すべて) |
| M3 | 3-5 / 3-7 |
| M4 | 3-6 |
| M5 | **3-8 のみ** |
| M6 | 3-9 |
| M7 | 4-3 |

> **M5 の注意 (Codex Round 1 Warning)**: 表に挙げた M5 の mutation は
> **既存の fake クラス**を concrete に使うため、3-10 (fake 参照の集合一致) は**赤くならない**
> (参照集合が変わらないため)。3-10 が赤くなるのは「**inventory 未登録の fake クラスを
> 新規に参照する**」変種 (例: `FakeExternalUrl` を provider が参照する形を足す) のとき。
> 段階 3 では M5 と併せてこの変種も 1 回試し、3-10 が赤くなることを確認する。

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
| 判断根拠 | 追加するのは tests/ 配下の新規 7 ファイル + `docs/architecture.md` 追記のみで、アプリコードに触れない。既存テストの改変も無い。他 TODO と競合する面が無く、単独 worktree で完結する |
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

## Codex レビュー履歴と残課題

| Round | 全体判定 | 指摘 | 反映 |
|---|---|---|---|
| 1 | CHANGES_REQUESTED | Critical 2 (パス基準の不一致 / 4-3 の候補集合が placement exception を落とす)、Warning 8、Suggestion 2 | 全件反映 |
| 2 | CHANGES_REQUESTED | Critical 1 (`bind($abstract, ExistingFake::class)` が全 gate をすり抜ける)、Warning 1 (同一 namespace short name)、Suggestion 1 | 全件反映 |
| 3 | CHANGES_REQUESTED | Warning 1 (`bind(A::class, B::class, true)` = singleton 相当で M6 を回避できる)、Suggestion 1 | 全件反映 |

**打ち切り理由**: 詳細設計レビューは上限 3 ラウンド (タスク指定)。
Round 3 の指摘はすべて設計へ反映済みだが、**反映後の再判定 (Round 4) は実施していない**。

**残課題 (実装者へ)**: 未検証なのは「引数個数まで固定した `disallowedContainerCalls()` の仕様に
さらに別の抜け道が無いか」の 1 点のみ。これは施策 5 (走査器の Unit テスト 18 ケース) が
実コードで positive/negative を固定するため、**実装時に必ず 5-1〜5-18 を先に書いて green にしてから**
gate 本体 (施策 3 / 4) に進むこと。新しい抜け道を見つけたら Unit テストのケースとして足す
(allowlist を緩める方向へ倒さない)。

各ラウンドのプロンプト / 返答 / 対応マトリクスは `codex-history/` と
`detailed-review-round-{1,2,3}.md` に保存済み。

---

## 台帳 (c2c) との食い違い (実装時にも再確認すること)

| # | 台帳 / brief の記述 | 実コードでの実査結果 |
|---|---|---|
| 1 | 「fake 配線を見る gate は 1 本も無い。**実証も別プロセス観測も無い**」 | `tests/Architecture/` に 0 本は事実。ただし**実証ベースの配線検査は Feature に既に 1 本ある** (`tests/Feature/Providers/FakeExternalsServiceProviderTest.php`、6 test)。穴は「AutoRecharge 未検査 / storage 未検査 / provider 登録点 未検査 / 網羅性 無し」の 4 点 |
| 2 | 「`ProductionEnvGuard` は起動時ではなく**配備前**に落とす層」 | 実際は `AppServiceProvider::boot()` (production 起動時) と `production:preflight` (配備前) の**両方**から呼ばれる。柱 3 の (a)(b) の差分として残るのは「config キャッシュを信用しない実 env 二重判定」だけ |
| 3 | 「fake 有効化フラグ 3 本」 | 事実。ただし storage は predicate が `App\Support\FakeStorageGate` に分離され、**env allowlist が payment と異なる** (`bughunt.local ∨ (testing ∧ runningUnitTests)`)。3 系統 × 別 allowlist を前提に設計する必要がある |
| 4 | (記載なし) | `FakeTakeObjectStorage` / `FakeRenderObjectStorage` は **real 具象クラスを継承**している。既存 Feature テストの `toBeInstanceOf(Real::class)` 流儀を storage へ広げると**偽グリーン**になる。本設計が厳密クラス一致を採る理由 |
| 5 | (記載なし) | storage 2 本は **abstract が具象クラスで本物側 bind が存在しない**。bind を消しても Laravel が自動組み立てするため、登録漏れが最も無音になるのはここ |

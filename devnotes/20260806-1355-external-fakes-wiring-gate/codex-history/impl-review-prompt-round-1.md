【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

Laravel + Svelte 5 (Inertia) アプリ AI-CUE の**実装レビュアー**として、以下の実装差分をレビューせよ。

本タスク (T119 / c2c feature: external-fakes-wiring-gate) は **`tests/` の新規 7 ファイル + `docs/architecture.md` 追記 1** のみで、
アプリコード (`app/` / `config/` / `bootstrap/` / `routes/`) は 1 行も変更していない。
フロントエンド差分はゼロなので DESIGN.md / Atomic Design 観点は非該当。

### レビュー観点 (優先順)

1. **設計との一致性**: 添付した詳細設計書の施策 1〜6 / テスト一覧 (3-1〜3-12, 4-1〜4-4, 5-1〜5-18) を満たしているか
2. **gate としての正確性 (最重要)**: 走査器 `FakeWiringSourceScanner` に、
   **gate をすり抜けられる抜け道**が残っていないか (fail-open になる形)。
   具体的には「fake の差し替えを追加/削除/変更したのに、どのテストも赤くならない書き方」が存在するか
3. **偽グリーンの余地**: 走査対象が空になる / 例外が握り潰される / 母集団導出が壊れると
   gate が静かに無力化しないか
4. **誤検出 (fail-closed 方向) の妥当性**: 過剰検出が現行コードで実害を出していないか
5. **テスト網羅性**: 走査器の Unit テストが positive/negative を十分に固定しているか
6. **状態リーク**: Architecture lane (RefreshDatabase / StrayLlmCallGuard なし) で
   `Prompt::$fake` (static) / container / config / env の復元が漏れていないか
7. **PHPStan 適合性**: `tests/` は解析対象外だが docblock の `list<>` / `class-string` が規約どおりか

### 出力形式

ファイルごとに判定を書き、指摘は **[Critical] / [Warning] / [Suggestion]** に分類せよ。
最後に全体判定として **APPROVED** または **CHANGES_REQUESTED** を明記せよ。

---

## user: 詳細設計書

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

## user: 実装差分 (git diff)

```diff
diff --git a/docs/architecture.md b/docs/architecture.md
index cf1b76f..d2deafe 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -701,3 +701,36 @@ ## 公開面
 | REST API v1 | `routes/api.php` → `Http/Controllers/Api/V1` | dual guard (`auth:api-key,api-oauth`) + `resolve.api-actor` |
 | MCP | `routes/ai.php` → `Mcp/Servers` | Passport OAuth 2.1 (`auth:mcp-oauth`) |
 | 管理画面 | Filament (`app/Filament`) | AdminUser guard |
+
+## 外部 fake 配線の不変条件 (T119)
+
+外部サービス (Stripe / S3 / LLM) の fake 差し替えは、**登録漏れが例外にならず本物が静かに動く**
+という性質を持つ (Laravel は abstract が具象クラスなら設定が無くても自動組み立てする)。
+撮影データと課金は取り返しがつかない副作用を持つため、以下を不変条件として固定する
+(gate は `tests/Architecture/ExternalFakeWiringInvariantTest` と
+`tests/Architecture/FakeClassReferenceInvariantTest`、走査器の固定は
+`tests/Unit/Architecture/FakeWiringSourceScannerTest`)。
+
+- **差し替えの唯一の配線点は `App\Providers\FakeExternalsServiceProvider`**。container 差し替えは
+  `$this->app->bind(A::class, B::class)` の形だけで行う (`singleton()` / `bind()` の第 3 引数
+  (= singleton 相当) / 変数 abstract / closure concrete / `app()`・`resolve()`・`App::`・
+  `Container::getInstance()` 経由は deny-by-default で fail する)。登録は
+  `bootstrap/providers.php` で **`AppServiceProvider` より後**に置く (後勝ち rebind)。
+- **新しい差し替えを足したら `tests/Support/ExternalFakes/ExternalFakeWiringInventory::bindings()`
+  に登録する**。未登録の bind 組は集合一致で検出される。登録すると「flag off で real /
+  flag on + allowlist env で fake / allowlist 外 env で real」の**実証**検査が自動で増える。
+  判定は必ず**厳密クラス一致** (`$resolved::class === $expected`) — storage fake は real の
+  サブクラスなので `instanceof` では偽グリーンになる。Architecture lane は `RefreshDatabase` を
+  使わないため、**解決対象の constructor が DB 非依存**であることを確認すること。
+- **capability flag は 3 系統で allowlist が異なる**: `testing.fake_externals` (課金。
+  local / testing / bughunt.local)、`testing.fake_storage` (`App\Support\FakeStorageGate` が
+  predicate の SSOT。bughunt.local ∨ (testing ∧ runningUnitTests))、`testing.fake_llm`
+  (bughunt.local のみ。`Prompt::$fake` は container ではなくプロセスグローバル static)。
+- **本番混入防止の正本は `App\Support\ProductionEnvGuard`** (配備前 = `production:preflight` /
+  起動時 = `AppServiceProvider::boot`)。fake 配線 gate はこれを二重実装しない。
+- **fake 実装クラスは `app/**/Fakes/` か `app/**/Testing/` に置く**。配置例外は
+  `FakeExternalsServiceProvider` (唯一の配線点) と `FakeStorageGate` (有効化 predicate) の 2 件のみ。
+- **本番コード (`app/` • `routes/` • `config/` • `bootstrap/`) は fake クラスを参照しない**。
+  参照してよいのは配線点と fake storage signed route の受け口を含む 4 ファイルだけで、
+  allowlist の件数はテストが固定している (増やすには理由コメントと併せて 2 箇所を触る摩擦がかかる)。
+  **誤検出が出ても allowlist を足す方向へ倒さない** — それが gate の目的である。
diff --git a/tests/Architecture/ExternalFakeWiringInvariantTest.php b/tests/Architecture/ExternalFakeWiringInvariantTest.php
new file mode 100644
index 0000000..92befed
--- /dev/null
+++ b/tests/Architecture/ExternalFakeWiringInvariantTest.php
@@ -0,0 +1,297 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Providers\AppServiceProvider;
+use App\Providers\FakeExternalsServiceProvider;
+use Illuminate\Support\Facades\Log;
+use Kent013\PrismPrompt\Prompt;
+use Tests\Support\ExternalFakes\ExternalFakeBinding;
+use Tests\Support\ExternalFakes\ExternalFakeWiringInventory;
+use Tests\Support\ExternalFakes\FakeClassCatalog;
+use Tests\Support\ExternalFakes\FakeWiringSourceScanner;
+
+/*
+ * 外部 fake 配線の実証 gate (c2c: external-fakes-wiring-gate 柱 1)。
+ *
+ * Laravel は abstract が具象クラスなら設定が無くても自動組み立てするため、
+ * **差し替えの登録漏れは例外にならず、本物が静かに動く**。
+ * したがって「宣言と実装の字面が一致するか」ではなく
+ * 「**実際に解決して中身を確かめる**」層を持つ。
+ *
+ * 判定は必ず `$resolved::class === $expected` の**厳密一致**で行う
+ * (FakeTakeObjectStorage は TakeObjectStorage を継承しているため、instanceof では
+ *  fake でも real 判定が通ってしまう = 対照実行が無意味になる)。
+ *
+ * 責務境界: 本番混入防止の正本は ProductionEnvGuard (+ ProductionEnvGuardTest)。
+ * 本 gate は非本番側の配線だけを見る。
+ *
+ * 状態リーク対策 (Architecture lane は RefreshDatabase も StrayLlmCallGuard も無い):
+ *  - container の復元は Pest の test case ごとの app 再構築に任せる
+ *    (対照と実証を**独立 test case** に分け、テスト順序に依存させない)。
+ *    「flag を戻して provider を再実走すれば real に戻る」は成立しない
+ *    (provider は early return するだけで binding を巻き戻さない) ため、その検査は書かない。
+ *  - config / env を書き換える test case は try/finally で原値復元する。
+ *  - Prompt::$fake は static なので、test 本体の finally で stopFaking() し、
+ *    **同一 test case 内で** isFaking() === false を assert する。
+ *    afterEach はフェイルセーフとして併置する (検査表現ではない)。
+ *
+ * 走査器 (FakeWiringSourceScanner) の限界は tests/Unit/Architecture/FakeWiringSourceScannerTest.php
+ * が positive/negative で固定している。到達可能性は判定しない (`if (false) { … }` 中も候補)。
+ */
+
+/*
+ * ソース走査系 mutation (M3〜M7) の被覆表。
+ * M1 / M2 (inventory entry の bind 削除) は 3-2 の data-driven 解決検査が自動被覆するため
+ * 本 map の対象外 (entry を足せば検査も自動で増える構造になっている)。
+ *
+ * 定数名は他の Architecture テストと衝突しないよう prefix する
+ * (Pest のファイル直下 const / function はグローバル空間に出る)。
+ */
+const EXTERNAL_FAKE_WIRING_MUTATION_COVERAGE = [
+    'M3' => 'bootstrap/providers.php に FakeExternalsServiceProvider が登録されている',
+    'M4' => 'FakeExternalsServiceProvider は AppServiceProvider より後に登録される (後勝ち)',
+    'M5' => 'provider の bind 組は inventory と集合一致する',
+    'M6' => 'provider の container 呼び出しは許可された形だけ',
+    'M7' => '本番コードは fake クラスを参照しない (FakeClassReferenceInvariantTest が担当)',
+];
+
+const EXTERNAL_FAKE_WIRING_SOURCE_MUTATION_IDS = ['M3', 'M4', 'M5', 'M6', 'M7'];
+
+/** fake 配線 provider のソース (走査系テストの共通入力。読み取り失敗は例外で落ちる) */
+function externalFakeWiringProviderSource(): string
+{
+    return FakeClassCatalog::sourceOf('app/Providers/FakeExternalsServiceProvider.php');
+}
+
+/**
+ * bootstrap/providers.php が宣言する provider 一覧。
+ *
+ * @return list<class-string>
+ */
+function externalFakeWiringRegisteredProviders(): array
+{
+    /** @var list<class-string> $providers */
+    $providers = require base_path('bootstrap/providers.php');
+
+    return $providers;
+}
+
+afterEach(function (): void {
+    // フェイルセーフ: LLM fake の static がテスト境界を越えないようにする (検査表現ではない)。
+    if (Prompt::isFaking()) {
+        Prompt::stopFaking();
+    }
+});
+
+dataset('external fake bindings', function (): Generator {
+    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
+        yield $binding->label() => [$binding];
+    }
+});
+
+dataset('external fake bindings and allowed environments', function (): Generator {
+    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
+        foreach ($binding->allowedEnvironments as $environment) {
+            yield $binding->label().' @ '.$environment => [$binding, $environment];
+        }
+    }
+});
+
+dataset('external fake bindings and denied environments', function (): Generator {
+    // production だけでなく staging も見る = 「未知環境で誤設定されても fake しない」という
+    // allowlist 方式の趣旨そのものを固定する。
+    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
+        foreach (['production', 'staging'] as $environment) {
+            yield $binding->label().' @ '.$environment => [$binding, $environment];
+        }
+    }
+});
+
+test('3-1 対照: flag off では real 実装が厳密一致で解決される', function (ExternalFakeBinding $binding): void {
+    expect(config($binding->flag))->toBeFalse();
+
+    expect(app($binding->abstract)::class)->toBe($binding->real);
+})->with('external fake bindings');
+
+test('3-2 実証: flag on + allowlist 環境で fake が厳密一致で解決される',
+    function (ExternalFakeBinding $binding, string $environment): void {
+        $originalFlag = config($binding->flag);
+        $originalEnvironment = $this->app['env'];
+
+        try {
+            // 環境ごとに実証する (testing だけだと local / bughunt.local の allowlist が固定されない)。
+            // storage は FakeStorageGate が testing ∧ runningUnitTests を要求するが、
+            // Architecture lane では runningUnitTests() が true なので成立する。
+            $this->app['env'] = $environment;
+            config([$binding->flag => true]);
+
+            (new FakeExternalsServiceProvider($this->app))->register();
+
+            // ★厳密一致 (instanceof は使わない。storage fake は real のサブクラス)
+            expect(app($binding->abstract)::class)->toBe($binding->fake);
+        } finally {
+            config([$binding->flag => $originalFlag]);
+            $this->app['env'] = $originalEnvironment;
+        }
+    }
+)->with('external fake bindings and allowed environments');
+
+test('3-3 provider 単体: flag on でも allowlist 外 env では real のまま',
+    function (ExternalFakeBinding $binding, string $environment): void {
+        $originalFlag = config($binding->flag);
+        $originalEnvironment = $this->app['env'];
+
+        try {
+            $this->app['env'] = $environment;
+            config([$binding->flag => true]);
+
+            (new FakeExternalsServiceProvider($this->app))->register();
+
+            expect(app($binding->abstract)::class)->toBe($binding->real);
+        } finally {
+            config([$binding->flag => $originalFlag]);
+            $this->app['env'] = $originalEnvironment;
+        }
+    }
+)->with('external fake bindings and denied environments');
+
+test('3-4 provider 単体: 課金 flag on + allowlist 外 env は warning を出す', function (): void {
+    $originalFlag = config(ExternalFakeWiringInventory::PAYMENT_FLAG);
+    $originalEnvironment = $this->app['env'];
+
+    try {
+        Log::spy();
+
+        $this->app['env'] = 'staging';
+        config([ExternalFakeWiringInventory::PAYMENT_FLAG => true]);
+
+        (new FakeExternalsServiceProvider($this->app))->register();
+
+        Log::shouldHaveReceived('warning')->once();
+    } finally {
+        config([ExternalFakeWiringInventory::PAYMENT_FLAG => $originalFlag]);
+        $this->app['env'] = $originalEnvironment;
+    }
+});
+
+test('3-5 登録点: bootstrap/providers.php に FakeExternalsServiceProvider が登録されている', function (): void {
+    expect(externalFakeWiringRegisteredProviders())->toContain(FakeExternalsServiceProvider::class);
+});
+
+test('3-6 登録点: FakeExternalsServiceProvider は AppServiceProvider より後 (後勝ち)', function (): void {
+    $providers = externalFakeWiringRegisteredProviders();
+
+    $fakeIndex = array_search(FakeExternalsServiceProvider::class, $providers, true);
+    $appIndex = array_search(AppServiceProvider::class, $providers, true);
+
+    expect($fakeIndex)->toBeInt()
+        ->and($appIndex)->toBeInt()
+        ->and($fakeIndex)->toBeGreaterThan($appIndex);
+});
+
+test('3-7 登録点: 起動済み container に provider がロードされている', function (): void {
+    expect(array_key_exists(FakeExternalsServiceProvider::class, $this->app->getLoadedProviders()))->toBeTrue();
+});
+
+test('3-8 網羅性: provider の bind 組が inventory と集合一致する', function (): void {
+    $pairs = FakeWiringSourceScanner::bindPairs(externalFakeWiringProviderSource());
+
+    // closure 差し替え (concrete === null) は「厳密クラス一致で実証できない形」なので許さない
+    expect(array_filter($pairs, static fn (array $pair): bool => $pair['concrete'] === null))->toBe([]);
+
+    $actual = array_map(
+        static fn (array $pair): string => $pair['abstract'].' => '.$pair['concrete'],
+        $pairs
+    );
+    $expected = array_map(
+        static fn (ExternalFakeBinding $binding): string => $binding->abstract.' => '.$binding->fake,
+        ExternalFakeWiringInventory::bindings()
+    );
+
+    sort($actual);
+    sort($expected);
+
+    expect($actual)->toBe($expected);
+});
+
+test('3-9 網羅性: provider の container 呼び出しは許可された形だけ', function (): void {
+    $source = externalFakeWiringProviderSource();
+
+    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([])
+        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toBe([]);
+});
+
+test('3-10 網羅性: provider が参照する fake 系クラスは inventory + 明示例外に一致する', function (): void {
+    $candidates = array_values(array_unique(array_merge(
+        FakeClassCatalog::implementationClasses(),
+        FakeClassCatalog::namedClasses(),
+    )));
+
+    $actual = FakeWiringSourceScanner::referencedClasses(externalFakeWiringProviderSource(), $candidates);
+
+    $expected = array_merge(
+        array_map(
+            static fn (ExternalFakeBinding $binding): string => $binding->fake,
+            ExternalFakeWiringInventory::bindings()
+        ),
+        ExternalFakeWiringInventory::providerReferenceExceptions(),
+    );
+
+    sort($actual);
+    sort($expected);
+
+    expect($actual)->toBe($expected);
+});
+
+test('3-11 LLM: bughunt.local ∧ fake_llm=true でのみ Prompt fake が立ち、stopFaking で戻る', function (): void {
+    $originalFlag = config(ExternalFakeWiringInventory::LLM_FLAG);
+    $originalEnvironment = $this->app['env'];
+
+    try {
+        expect(Prompt::isFaking())->toBeFalse();
+
+        // (1) bughunt.local ∧ on → 立つ
+        $this->app['env'] = 'bughunt.local';
+        config([ExternalFakeWiringInventory::LLM_FLAG => true]);
+        (new FakeExternalsServiceProvider($this->app))->boot();
+        expect(Prompt::isFaking())->toBeTrue();
+
+        Prompt::stopFaking();
+
+        // (2) testing ∧ on → 立たない (static をテストプロセスで占有させない)
+        $this->app['env'] = 'testing';
+        (new FakeExternalsServiceProvider($this->app))->boot();
+        expect(Prompt::isFaking())->toBeFalse();
+
+        // (3) local ∧ on → 立たない (実 API 検証を潰さない)
+        $this->app['env'] = 'local';
+        (new FakeExternalsServiceProvider($this->app))->boot();
+        expect(Prompt::isFaking())->toBeFalse();
+
+        // (4) bughunt.local ∧ off → 立たない (既定 real LLM)
+        $this->app['env'] = 'bughunt.local';
+        config([ExternalFakeWiringInventory::LLM_FLAG => false]);
+        (new FakeExternalsServiceProvider($this->app))->boot();
+        expect(Prompt::isFaking())->toBeFalse();
+    } finally {
+        // static の往復を**同一 test case 内で** assert する (afterEach はフェイルセーフ)
+        if (Prompt::isFaking()) {
+            Prompt::stopFaking();
+        }
+        expect(Prompt::isFaking())->toBeFalse();
+
+        config([ExternalFakeWiringInventory::LLM_FLAG => $originalFlag]);
+        $this->app['env'] = $originalEnvironment;
+    }
+});
+
+test('3-12 mutation coverage: 被覆表のキー集合が想定 mutation ID と一致する', function (): void {
+    $keys = array_keys(EXTERNAL_FAKE_WIRING_MUTATION_COVERAGE);
+    $ids = EXTERNAL_FAKE_WIRING_SOURCE_MUTATION_IDS;
+
+    sort($keys);
+    sort($ids);
+
+    expect($keys)->toBe($ids);
+});
diff --git a/tests/Architecture/FakeClassReferenceInvariantTest.php b/tests/Architecture/FakeClassReferenceInvariantTest.php
new file mode 100644
index 0000000..c41a6da
--- /dev/null
+++ b/tests/Architecture/FakeClassReferenceInvariantTest.php
@@ -0,0 +1,105 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Providers\FakeExternalsServiceProvider;
+use App\Support\FakeStorageGate;
+use Tests\Support\ExternalFakes\FakeClassCatalog;
+use Tests\Support\ExternalFakes\FakeWiringSourceScanner;
+
+/*
+ * 本番コードが fake のクラス名を 1 度も参照しないことの全走査
+ * (c2c: external-fakes-wiring-gate 柱 3(c))。
+ *
+ * fake クラス名は**ディレクトリと命名から動的導出**する (ハードコード一覧を持たない)。
+ * 現時点の違反は 0 件 = 「増えないこと」を今固定するのが最安。
+ *
+ * ★走査候補: 「fake 実装クラス」だけでは足りない。配置例外
+ *   (FakeExternalsServiceProvider / FakeStorageGate) を業務コードが参照しても検出できず
+ *   偽グリーンになるため、候補は implementationClasses() ∪ placementExceptions() のキーとする。
+ *
+ * ★走査根: app/ だけだと routes/ に Testing controller を直書きする、config/ にクラス名を書く、
+ *   といった抜け道が残る。「本番コード全走査」を名乗る以上、
+ *   app/ • routes/ • config/ • bootstrap/ の 4 根を走査する。
+ *
+ * ★誤検出が出たら allowlist を足す方向へ倒さない。まず「本当に本番コードから fake を
+ *   参照しているのか」を疑う (それが本 gate の目的)。
+ */
+
+/** 参照 allowlist: fake 系クラスを参照してよい本番ファイル (**repo ルート相対**) */
+const FAKE_REFERENCE_ALLOWED = [
+    // 唯一の配線点 (何を fake にするかの決定はここに集約する)
+    'app/Providers/FakeExternalsServiceProvider.php',
+    // fake storage signed route の受け口 (FakeStorageGate 成立時のみ route 登録される)
+    'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
+    'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
+    // provider 登録点。FakeExternalsServiceProvider (配置例外クラス) を必ず参照する
+    'bootstrap/providers.php',
+];
+
+test('4-1 配置規約: Fake 命名クラスは Fakes/ か Testing/ 配下にのみ存在する', function (): void {
+    $allowed = array_merge(
+        FakeClassCatalog::implementationClasses(),
+        array_keys(FakeClassCatalog::placementExceptions()),
+    );
+
+    $misplaced = array_values(array_diff(FakeClassCatalog::namedClasses(), $allowed));
+
+    expect($misplaced)->toBe([]);
+});
+
+test('4-2 配置例外は 2 件から増えていない', function (): void {
+    // 増やすときは placementExceptions() に理由を書いたうえで**ここも触る** (意図的な摩擦)。
+    expect(array_keys(FakeClassCatalog::placementExceptions()))->toBe([
+        FakeExternalsServiceProvider::class,
+        FakeStorageGate::class,
+    ]);
+});
+
+test('4-3 本番コードは fake クラスを参照しない', function (): void {
+    $implementations = FakeClassCatalog::implementationClasses();
+    $candidates = array_values(array_unique(array_merge(
+        $implementations,
+        array_keys(FakeClassCatalog::placementExceptions()),
+    )));
+    $files = FakeClassCatalog::scanFiles();
+
+    // 走査器 / 母集団導出が壊れて「空走査で緑」になるのを防ぐ (fail-closed)
+    expect($candidates)->not->toBeEmpty()
+        ->and($files)->not->toBeEmpty();
+
+    $violations = [];
+    foreach ($files as $file) {
+        if (in_array($file, FAKE_REFERENCE_ALLOWED, true)) {
+            continue;
+        }
+
+        // fake 実装クラス自身が別の fake を参照するのは正当 (FakeTakeObjectStorage → FakeObjectStore 等)
+        if (str_starts_with($file, 'app/')
+            && in_array(FakeClassCatalog::classFromPath($file), $implementations, true)) {
+            continue;
+        }
+
+        $referenced = FakeWiringSourceScanner::referencedClasses(
+            FakeClassCatalog::sourceOf($file),
+            $candidates
+        );
+
+        if ($referenced !== []) {
+            $violations[] = $file.': '.implode(', ', $referenced);
+        }
+    }
+
+    expect($violations)->toBe([]);
+});
+
+test('4-4 参照 allowlist は 4 件から増えていない', function (): void {
+    // 増やすときは理由コメントを添えて**ここも触る** (意図的な摩擦)。
+    expect(FAKE_REFERENCE_ALLOWED)->toHaveCount(4)
+        ->and(FAKE_REFERENCE_ALLOWED)->toBe([
+            'app/Providers/FakeExternalsServiceProvider.php',
+            'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
+            'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
+            'bootstrap/providers.php',
+        ]);
+});
diff --git a/tests/Support/ExternalFakes/ExternalFakeBinding.php b/tests/Support/ExternalFakes/ExternalFakeBinding.php
new file mode 100644
index 0000000..8eadcab
--- /dev/null
+++ b/tests/Support/ExternalFakes/ExternalFakeBinding.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\ExternalFakes;
+
+/**
+ * container 差し替え 1 本の宣言 (fake 配線 gate の inventory 要素)。
+ *
+ * 「宣言 (本ファイル)」と「実証 (ExternalFakeWiringInvariantTest)」を分離する。
+ * 本クラスは値の器であり判定ロジックを持たない。
+ */
+final readonly class ExternalFakeBinding
+{
+    /**
+     * @param  class-string  $abstract  container から解決するキー (interface または具象クラス)
+     * @param  class-string  $real  flag off のときに解決されるべきクラス (厳密一致)
+     * @param  class-string  $fake  flag on + allowlist 内で解決されるべきクラス (厳密一致)
+     * @param  string  $flag  capability flag の config キー
+     * @param  list<string>  $allowedEnvironments  fake を許可する環境 allowlist
+     * @param  string  $risk  なぜ外部副作用として危険か (レビュー用説明。機械照合しない)
+     */
+    public function __construct(
+        public string $abstract,
+        public string $real,
+        public string $fake,
+        public string $flag,
+        public array $allowedEnvironments,
+        public string $risk,
+    ) {}
+
+    /**
+     * データセット名 (テスト出力に出る識別子)。
+     *
+     * FQCN ベースにする (class basename だと将来 namespace 違いの同名クラスが増えたとき
+     * dataset 名が衝突する)。
+     */
+    public function label(): string
+    {
+        return str_replace('\\', '.', $this->abstract);
+    }
+}
diff --git a/tests/Support/ExternalFakes/ExternalFakeWiringInventory.php b/tests/Support/ExternalFakes/ExternalFakeWiringInventory.php
new file mode 100644
index 0000000..1fc556a
--- /dev/null
+++ b/tests/Support/ExternalFakes/ExternalFakeWiringInventory.php
@@ -0,0 +1,132 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\ExternalFakes;
+
+use App\Http\Controllers\Testing\GetFakeStorageObjectController;
+use App\Http\Controllers\Testing\PutFakeStorageObjectController;
+use App\Services\AI\Testing\CannedPromptFakeRegistrar;
+use App\Services\Billing\CashierAutoRechargeGateway;
+use App\Services\Billing\CashierStripeGateway;
+use App\Services\Billing\CashierTicketCheckoutGateway;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
+use App\Services\Billing\Fakes\FakeStripeGateway;
+use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
+use App\Services\Billing\TicketCheckoutGateway;
+use App\Services\Capture\Fakes\FakeTakeObjectStorage;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Render\Fakes\FakeRenderObjectStorage;
+use App\Services\Render\RenderObjectStorage;
+use App\Support\FakeStorageGate;
+
+/**
+ * 外部 fake の container 差し替え inventory (deny-by-default の正本)。
+ *
+ * 責務境界:
+ * - 本 inventory と ExternalFakeWiringInvariantTest が見るのは **非本番側の配線**だけ。
+ * - **本番混入防止の正本は `App\Support\ProductionEnvGuard`** (配備前 = production:preflight /
+ *   起動時 = AppServiceProvider::boot の 2 経路) + `tests/Feature/Support/ProductionEnvGuardTest`。
+ *   ここで二重実装しない。
+ * - LLM (Prism) fake は container ではなく `Prompt::$fake` (プロセスグローバル static) を書き換える
+ *   ため inventory の対象外 (ExternalFakeWiringInvariantTest の 3-11 が別枠で見る)。
+ */
+final class ExternalFakeWiringInventory
+{
+    /** 課金 fake の capability flag */
+    public const string PAYMENT_FLAG = 'testing.fake_externals';
+
+    /** storage fake の capability flag */
+    public const string STORAGE_FLAG = 'testing.fake_storage';
+
+    /** LLM fake の capability flag (container 差し替えではないため bindings() には現れない) */
+    public const string LLM_FLAG = 'testing.fake_llm';
+
+    /** 課金 fake の env allowlist (FakeExternalsServiceProvider::PAYMENT_FAKE_ENVIRONMENTS と対) */
+    private const array PAYMENT_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];
+
+    /** storage fake の env allowlist (FakeStorageGate の predicate と対。testing は runningUnitTests 前提) */
+    private const array STORAGE_ENVIRONMENTS = ['testing', 'bughunt.local'];
+
+    /**
+     * fake の実体ではないが FakeExternalsServiceProvider が参照してよい配線基盤クラス。
+     *
+     * 「provider が参照する fake 系クラス = bindings() の fake ∪ 本集合」を集合一致で検査するため、
+     * ここに載っていないクラスを provider が参照した時点で gate が赤くなる。
+     *
+     * @return list<class-string>
+     */
+    public static function providerReferenceExceptions(): array
+    {
+        return [
+            // LLM static fake の install 窓口 (container 配線を行わない)
+            CannedPromptFakeRegistrar::class,
+            // storage fake の有効化 predicate (SSOT。container 配線を行わない)
+            FakeStorageGate::class,
+            // fake storage signed route の受け口 (route action。container 配線を行わない)
+            PutFakeStorageObjectController::class,
+            GetFakeStorageObjectController::class,
+        ];
+    }
+
+    /**
+     * container 差し替えの全宣言。
+     *
+     * ここに entry を足すと、ExternalFakeWiringInvariantTest の data-driven 検査
+     * (対照 / 実証 / allowlist 外) が自動的に増える = 書き忘れが構造的に起きない。
+     *
+     * ⚠️ 新 entry を足す実装者へ: Architecture lane は RefreshDatabase を使わない。
+     * abstract / real / fake の constructor が DB に触れないことを必ず確認すること
+     * (現行 5 本は確認済み)。
+     *
+     * @return list<ExternalFakeBinding>
+     */
+    public static function bindings(): array
+    {
+        return [
+            new ExternalFakeBinding(
+                abstract: TicketCheckoutGateway::class,
+                real: CashierTicketCheckoutGateway::class,
+                fake: FakeTicketCheckoutGateway::class,
+                flag: self::PAYMENT_FLAG,
+                allowedEnvironments: self::PAYMENT_ENVIRONMENTS,
+                risk: 'チケットスポット購入の Stripe Checkout。配線が外れると実 Stripe に実課金セッションを作る。',
+            ),
+            new ExternalFakeBinding(
+                abstract: StripeGatewayInterface::class,
+                real: CashierStripeGateway::class,
+                fake: FakeStripeGateway::class,
+                flag: self::PAYMENT_FLAG,
+                allowedEnvironments: self::PAYMENT_ENVIRONMENTS,
+                risk: 'サブスク Checkout / Customer Portal。配線が外れると実 Stripe に契約を作る。',
+            ),
+            new ExternalFakeBinding(
+                abstract: AutoRechargeGatewayInterface::class,
+                real: CashierAutoRechargeGateway::class,
+                fake: FakeAutoRechargeGateway::class,
+                flag: self::PAYMENT_FLAG,
+                allowedEnvironments: self::PAYMENT_ENVIRONMENTS,
+                risk: 'オートリチャージの off-session invoice。配線が外れると実カードへ請求が飛ぶ。',
+            ),
+            new ExternalFakeBinding(
+                abstract: TakeObjectStorage::class,
+                real: TakeObjectStorage::class,
+                fake: FakeTakeObjectStorage::class,
+                flag: self::STORAGE_FLAG,
+                allowedEnvironments: self::STORAGE_ENVIRONMENTS,
+                risk: '撮影テイクの S3 presign / HeadObject。abstract が具象クラスのため、'
+                    .'bind を消しても Laravel が本物を自動組み立てして無音で実 S3 を叩く。',
+            ),
+            new ExternalFakeBinding(
+                abstract: RenderObjectStorage::class,
+                real: RenderObjectStorage::class,
+                fake: FakeRenderObjectStorage::class,
+                flag: self::STORAGE_FLAG,
+                allowedEnvironments: self::STORAGE_ENVIRONMENTS,
+                risk: 'レンダ出力の S3 read/write。TakeObjectStorage と同じく具象クラス起点で無音になる。',
+            ),
+        ];
+    }
+}
diff --git a/tests/Support/ExternalFakes/FakeClassCatalog.php b/tests/Support/ExternalFakes/FakeClassCatalog.php
new file mode 100644
index 0000000..27d61be
--- /dev/null
+++ b/tests/Support/ExternalFakes/FakeClassCatalog.php
@@ -0,0 +1,206 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\ExternalFakes;
+
+use App\Providers\FakeExternalsServiceProvider;
+use App\Support\FakeStorageGate;
+use FilesystemIterator;
+use InvalidArgumentException;
+use RecursiveDirectoryIterator;
+use RecursiveIteratorIterator;
+use RuntimeException;
+use SplFileInfo;
+
+/**
+ * fake クラスの母集団導出 (ハードコード一覧を持たない = fake が増えたら自動で母集団に入る)。
+ *
+ * 定義 1「fake 実装クラス」 = app/ 配下で `Fakes/` か `Testing/` ディレクトリに置かれた全クラス
+ * 定義 2「fake 命名クラス」 = app/ 配下でクラス名が `Fake` で始まる or `Fake` で終わるクラス
+ *
+ * 前提: **1 ファイル 1 クラス + PSR-4 (`App\` => `app/`)**。Pint / composer の PSR-4 autoload が
+ * 強制しているため、path から FQCN を導出する (token 解析より安定)。
+ *
+ * パス表記はすべて **repo ルート相対** (`app/Providers/Foo.php` / `routes/web.php`) で統一する。
+ * 唯一の例外が {@see self::classFromPath()} で、これは `app/` 配下の repo 相対パスのみを受ける。
+ */
+final class FakeClassCatalog
+{
+    /** 参照走査の走査根 (repo ルート相対)。app/ だけだと route / config 直書きの抜け道が残る */
+    private const array SCAN_ROOTS = ['app', 'routes', 'config', 'bootstrap'];
+
+    /** fake 実装クラスを置くディレクトリ名 (path segment 完全一致で判定する) */
+    private const array FAKE_DIRECTORIES = ['Fakes', 'Testing'];
+
+    /**
+     * 走査から外す生成物ディレクトリ (repo ルート相対の接頭辞)。
+     *
+     * `bootstrap/cache/` は `php artisan config:cache` 等が吐く**生成物**で .gitignore 済み。
+     * ソースではないうえ、存在するかどうかが実行環境に依存するため走査すると gate が
+     * 非決定になる (キャッシュ生成の有無で赤/緑が変わる)。
+     */
+    private const array EXCLUDED_PREFIXES = ['bootstrap/cache/'];
+
+    /** repo ルートの絶対パス (tests/Support/ExternalFakes から 3 段上) */
+    public static function repoRoot(): string
+    {
+        return dirname(__DIR__, 3);
+    }
+
+    /**
+     * 定義 2 のうち定義 1 に属さなくてよい例外 (fake の実体ではなく配線基盤)。
+     *
+     * @return array<class-string, string> class => 理由
+     */
+    public static function placementExceptions(): array
+    {
+        return [
+            FakeExternalsServiceProvider::class => 'fake の実装ではなく唯一の配線 provider。Providers/ 配下にある必然性がある。',
+            FakeStorageGate::class => 'fake の実装ではなく gate predicate (有効化条件の SSOT)。provider と action guard の双方が参照する。',
+        ];
+    }
+
+    /**
+     * 定義 1: fake 実装クラス。母集団は app/ のみ (PSR-4 のクラス定義があるのは app/ だけ)。
+     *
+     * @return list<class-string>
+     */
+    public static function implementationClasses(): array
+    {
+        $classes = [];
+        foreach (self::phpFilesUnder('app') as $path) {
+            // ファイル名を除いたディレクトリ segment に Fakes / Testing が含まれるか (完全一致)。
+            // 'FakesHelper' のような別名ディレクトリを巻き込まないため部分一致にしない。
+            $segments = explode('/', $path);
+            array_pop($segments);
+            if (array_intersect($segments, self::FAKE_DIRECTORIES) === []) {
+                continue;
+            }
+            $classes[] = self::classFromPath($path);
+        }
+
+        return $classes;
+    }
+
+    /**
+     * 定義 2: fake 命名クラス。母集団は app/ のみ。
+     *
+     * @return list<class-string>
+     */
+    public static function namedClasses(): array
+    {
+        $classes = [];
+        foreach (self::phpFilesUnder('app') as $path) {
+            $name = basename($path, '.php');
+            if (! str_starts_with($name, 'Fake') && ! str_ends_with($name, 'Fake')) {
+                continue;
+            }
+            $classes[] = self::classFromPath($path);
+        }
+
+        return $classes;
+    }
+
+    /**
+     * 参照走査の対象となる本番コード全ファイル (**repo ルート相対**パス)。
+     *
+     * 走査根は 4 つ: app/ • routes/ • config/ • bootstrap/。
+     * 対象は `.php` 拡張子のファイルのみ。
+     *
+     * @return list<string> 例: 'app/Providers/FakeExternalsServiceProvider.php', 'routes/web.php'
+     */
+    public static function scanFiles(): array
+    {
+        $files = [];
+        foreach (self::SCAN_ROOTS as $root) {
+            foreach (self::phpFilesUnder($root) as $path) {
+                $files[] = $path;
+            }
+        }
+        sort($files);
+
+        return $files;
+    }
+
+    /**
+     * repo 相対パス → FQCN。**app/ 配下のパスのみ**を受け取る (PSR-4: app/Foo/Bar.php => App\Foo\Bar)。
+     * app/ 以外を渡された場合は例外を投げる (誤用を静かに通さない)。
+     *
+     * @return class-string
+     */
+    public static function classFromPath(string $repoRelativePath): string
+    {
+        if (! str_starts_with($repoRelativePath, 'app/') || ! str_ends_with($repoRelativePath, '.php')) {
+            throw new InvalidArgumentException(
+                "classFromPath() は app/ 配下の .php パスのみを受け取る: {$repoRelativePath}"
+            );
+        }
+
+        $relative = substr($repoRelativePath, strlen('app/'), -strlen('.php'));
+
+        /** @var class-string $class */
+        $class = 'App\\'.str_replace('/', '\\', $relative);
+
+        return $class;
+    }
+
+    /**
+     * repo 相対パスのソースを読む (読み取り失敗を空文字で握り潰さない)。
+     *
+     * gate は「走査結果が空」を「違反なし」と解釈するため、読み取り失敗を黙って
+     * 空文字にすると gate が静かに無力化する。どのファイルが読めなかったかを必ず示す。
+     */
+    public static function sourceOf(string $repoRelativePath): string
+    {
+        $absolute = self::repoRoot().'/'.$repoRelativePath;
+        $source = @file_get_contents($absolute);
+
+        if (! is_string($source) || $source === '') {
+            throw new RuntimeException("ソースを読み取れない (fake 配線 gate の走査対象): {$repoRelativePath}");
+        }
+
+        return $source;
+    }
+
+    /**
+     * 走査根配下の .php ファイル (repo ルート相対・昇順)。
+     *
+     * @return list<string>
+     */
+    private static function phpFilesUnder(string $root): array
+    {
+        $absoluteRoot = self::repoRoot().'/'.$root;
+        if (! is_dir($absoluteRoot)) {
+            throw new RuntimeException("走査根が存在しない: {$root}");
+        }
+
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
+        );
+
+        $files = [];
+        /** @var SplFileInfo $file */
+        foreach ($iterator as $file) {
+            if (! $file->isFile() || $file->getExtension() !== 'php') {
+                continue;
+            }
+            $relative = $root.'/'.str_replace(
+                '\\',
+                '/',
+                substr($file->getPathname(), strlen($absoluteRoot) + 1)
+            );
+
+            foreach (self::EXCLUDED_PREFIXES as $prefix) {
+                if (str_starts_with($relative, $prefix)) {
+                    continue 2;
+                }
+            }
+
+            $files[] = $relative;
+        }
+        sort($files);
+
+        return $files;
+    }
+}
diff --git a/tests/Support/ExternalFakes/FakeWiringSourceScanner.php b/tests/Support/ExternalFakes/FakeWiringSourceScanner.php
new file mode 100644
index 0000000..313639a
--- /dev/null
+++ b/tests/Support/ExternalFakes/FakeWiringSourceScanner.php
@@ -0,0 +1,716 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\ExternalFakes;
+
+use App\Services\AI\Testing\CannedPromptFakeRegistrar;
+use App\Support\FakeStorageGate;
+
+/**
+ * FakeExternalsServiceProvider の container 呼び出し形と、本番コードのクラス参照を
+ * token ベースで抽出する純粋 helper (I/O を持たない。引数は PHP ソース文字列)。
+ *
+ * ★設計判断
+ *  - 「禁止 API を列挙する」のではなく「**許可された呼び出し形を列挙し、残りを禁止する**」。
+ *    API 名の列挙は未知 API (rebinding / 将来の Container API) で必ず抜けられる。
+ *  - `make()` は**引数まで**固定する。`$this->app->make(SomeRegistrar::class)->register()` という
+ *    委譲で配線を別クラスへ逃がせるため (既存の CannedPromptFakeRegistrar が現に委譲パターン)。
+ *  - `bind()` は「位置引数ちょうど 2 個かつ両方 `::class`」に固定する。
+ *    `bind($abstract, ExistingFake::class)` は bindPairs() が読み取れず参照集合も変わらないため
+ *    ここで禁止しないと**偽グリーン**になる。`bind(A::class, B::class, true)` は第 3 引数 $shared =
+ *    singleton 相当なので、これも禁止しないと singleton 禁止を同じ意味の書き方で回避できる。
+ *  - 誤検出は分類 1 行で解消できるが検出漏れは永久に気付けない、という非対称性から
+ *    **過剰検出側 (fail-closed)** へ倒す。
+ *
+ * ★short class name の FQCN 正規化
+ *  ソース先頭の **namespace 宣言 + use map** を先に構築し、`A::class` の `A` を FQCN へ正規化する。
+ *  対応する形: `use A\B\C;` / `use A\B\C as D;` / `use A\B\{C, D as E};` / `\A\B\C::class` /
+ *  use に無い `C::class` (現在の namespace 配下として解決)。
+ *  この正規化は bindPairs() / disallowedContainerCalls() の make 引数照合 / referencedClasses() が共有する。
+ *
+ * ★限界 (呼び出し側テストの docblock にも明記する)
+ *  - 到達可能性を判定しない (`if (false) { … }` 中の呼び出しも候補になる)。
+ *  - 変数経由の container (`$c = $this->app; $c->bind(...)`) は
+ *    disallowedIndirectAccess() の `$this->app` の**非呼び出し出現**検出で捕まえる。
+ *  - 非 bracketed namespace 前提 (Pint が強制)。
+ *  - クラス宣言名 (`class FakeStorageGate`) は参照として数えない (自己参照は漏洩の証拠ではない)。
+ */
+final class FakeWiringSourceScanner
+{
+    /**
+     * 許可する `$this->app-><method>(…)` の呼び出し形 (これ以外はすべて禁止 = deny-by-default)。
+     *
+     * value は許可する**位置引数の形**:
+     * - `classPair`: 位置引数ちょうど 2 個で両方 `::class` 定数 (差し替え本体。組は bindPairs() が inventory 照合)
+     * - `allowlistedClass`: 位置引数ちょうど 1 個で MAKE_ALLOWED_ARGUMENTS のいずれか
+     * - `none`: 位置引数なし
+     *
+     * @var array<string, string>
+     */
+    private const array ALLOWED_APP_CALLS = [
+        'bind' => 'classPair',
+        'make' => 'allowlistedClass',
+        'environment' => 'none',
+    ];
+
+    /**
+     * `make()` に渡してよいクラス (container 配線を行わないことを分類済みの 2 件のみ)。
+     *
+     * @var list<class-string>
+     */
+    private const array MAKE_ALLOWED_ARGUMENTS = [
+        FakeStorageGate::class,
+        CannedPromptFakeRegistrar::class,
+    ];
+
+    /** container へ間接到達するグローバル helper */
+    private const array CONTAINER_HELPERS = ['app', 'resolve'];
+
+    /** container へ間接到達する静的アクセス起点 (名前の末尾セグメントで判定) */
+    private const array CONTAINER_STATIC_ROOTS = ['App', 'Container'];
+
+    /** 名前を表すトークン */
+    private const array NAME_TOKEN_IDS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
+
+    /**
+     * @param  list<array{id: int, text: string, line: int}>  $tokens  コメント / 空白を除去済み
+     * @param  array<string, string>  $useMap  短縮名 => FQCN
+     * @param  list<bool>  $isImportToken  namespace / use 文に属するトークンか
+     * @param  list<bool>  $isDeclarationName  クラス様宣言の名前トークンか
+     */
+    private function __construct(
+        private readonly array $tokens,
+        private readonly string $namespace,
+        private readonly array $useMap,
+        private readonly array $isImportToken,
+        private readonly array $isDeclarationName,
+    ) {}
+
+    /**
+     * 許可外の `$this->app-><method>(…)` 呼び出し。
+     *
+     * @return list<string> 人間可読な違反説明 (行番号付き)
+     */
+    public static function disallowedContainerCalls(string $source): array
+    {
+        $scanner = self::analyze($source);
+        $violations = [];
+
+        foreach ($scanner->appMethodCalls() as $call) {
+            $method = $call['method'];
+            $line = $call['line'];
+
+            if (! array_key_exists($method, self::ALLOWED_APP_CALLS)) {
+                $violations[] = "{$method}(…) は許可された container 呼び出し形ではない (line {$line})";
+
+                continue;
+            }
+
+            // 名前付き引数 / spread unpack は引数の形を機械照合できないため fail-closed で禁止する。
+            foreach ($call['args'] as $arg) {
+                if (self::isNamedArgument($arg) || self::containsUnpack($arg)) {
+                    $violations[] = "{$method}(…) は名前付き引数 / unpack を使っている (line {$line})";
+
+                    continue 2;
+                }
+            }
+
+            $shape = self::ALLOWED_APP_CALLS[$method];
+            $arguments = $call['args'];
+
+            if ($shape === 'none' && $arguments !== []) {
+                $violations[] = "{$method}(…) は引数なしでのみ許可される (line {$line})";
+
+                continue;
+            }
+
+            if ($shape === 'classPair') {
+                if (count($arguments) !== 2
+                    || ! self::isClassConstant($arguments[0])
+                    || ! self::isClassConstant($arguments[1])) {
+                    $violations[] = "{$method}(…) は位置引数ちょうど 2 個かつ両方 ::class 定数でなければならない (line {$line})";
+                }
+
+                continue;
+            }
+
+            if ($shape === 'allowlistedClass') {
+                if (count($arguments) !== 1 || ! self::isClassConstant($arguments[0])) {
+                    $violations[] = "{$method}(…) は位置引数ちょうど 1 個の ::class 定数でなければならない (line {$line})";
+
+                    continue;
+                }
+
+                $resolved = $scanner->resolve($arguments[0][0]['text']);
+                if (! in_array($resolved, self::MAKE_ALLOWED_ARGUMENTS, true)) {
+                    $violations[] = "{$method}({$resolved}::class) は許可されていない (line {$line})";
+                }
+            }
+        }
+
+        return $violations;
+    }
+
+    /**
+     * `$this->app->bind(A::class, B::class)` の (abstract, concrete) 組 (**FQCN 正規化済み**)。
+     *
+     * 第 2 引数が `::class` 定数でない (closure 等) 場合は concrete を `null` として返し、
+     * 呼び出し側テストで「fake 差し替えは ::class 対 ::class の形に限る」を fail させる。
+     * 第 1 引数が `::class` 定数でない形 (変数 abstract など) は組として読み取れないため返さない
+     * (disallowedContainerCalls() が別途 fail させる = 見落としにはならない)。
+     *
+     * @return list<array{abstract: class-string, concrete: class-string|null}>
+     */
+    public static function bindPairs(string $source): array
+    {
+        $scanner = self::analyze($source);
+        $pairs = [];
+
+        foreach ($scanner->appMethodCalls() as $call) {
+            if ($call['method'] !== 'bind' || count($call['args']) < 2) {
+                continue;
+            }
+            if (! self::isClassConstant($call['args'][0])) {
+                continue;
+            }
+
+            /** @var class-string $abstract */
+            $abstract = $scanner->resolve($call['args'][0][0]['text']);
+
+            $concrete = null;
+            if (self::isClassConstant($call['args'][1])) {
+                /** @var class-string $concrete */
+                $concrete = $scanner->resolve($call['args'][1][0]['text']);
+            }
+
+            $pairs[] = ['abstract' => $abstract, 'concrete' => $concrete];
+        }
+
+        return $pairs;
+    }
+
+    /**
+     * `$this->app` のメソッド呼び出し以外で container へ到達する形 (未知 API 経由の抜け道封じ)。
+     *
+     * 検出対象: `app(` / `resolve(` / `App::` facade / `Container::getInstance()` /
+     * `$this->app` の**メソッド呼び出し以外の出現** (変数への代入・引数渡し・ArrayAccess)。
+     *
+     * @return list<string>
+     */
+    public static function disallowedIndirectAccess(string $source): array
+    {
+        $scanner = self::analyze($source);
+        $violations = [];
+        $count = count($scanner->tokens);
+
+        foreach ($scanner->appAccessIndexes() as $index) {
+            if ($scanner->isAppMethodCallAt($index)) {
+                continue;
+            }
+            $line = $scanner->tokens[$index]['line'];
+            $violations[] = "\$this->app がメソッド呼び出し以外で使われている (line {$line})";
+        }
+
+        for ($i = 0; $i < $count; $i++) {
+            $token = $scanner->tokens[$i];
+            if ($scanner->isImportToken[$i] || ! in_array($token['id'], self::NAME_TOKEN_IDS, true)) {
+                continue;
+            }
+
+            $segments = explode('\\', ltrim($token['text'], '\\'));
+            $last = $segments[count($segments) - 1];
+            $next = $scanner->tokens[$i + 1] ?? null;
+            $previous = $scanner->tokens[$i - 1] ?? null;
+
+            // `app(` / `resolve(` のグローバル helper 経由 (メソッド呼び出しは除く)
+            if (in_array($last, self::CONTAINER_HELPERS, true)
+                && $next !== null && $next['text'] === '('
+                && ! self::isMemberAccessBoundary($previous)) {
+                $violations[] = "{$last}() 経由で container へ到達している (line {$token['line']})";
+
+                continue;
+            }
+
+            // `App::` facade / `Container::getInstance()`
+            if (in_array($last, self::CONTAINER_STATIC_ROOTS, true)
+                && $next !== null && $next['id'] === T_DOUBLE_COLON) {
+                $violations[] = "{$last}:: 経由で container へ到達している (line {$token['line']})";
+            }
+        }
+
+        return $violations;
+    }
+
+    /**
+     * ソースが参照するクラス FQCN の集合 ($candidates との積集合)。
+     *
+     * 収集元 4 系統:
+     *  1. `use A\B\C;` (グループ use / alias 付きも解決)
+     *  2. 完全修飾 / 修飾名トークン (T_NAME_FULLY_QUALIFIED / T_NAME_QUALIFIED)
+     *  3. **文字列リテラルの内容が FQCN と完全一致**するもの (文字列経由の抜け道封じ。部分一致はしない)
+     *  4. **candidate の class basename に一致する T_STRING** を namespace / use map で解決したもの
+     *     (`use` も完全修飾も無い**同一 namespace 内の short name 参照**を拾う)
+     *
+     * クラス宣言名 (`class FakeStorageGate`) とメンバアクセス (`$x->FakeStorageGate`) は除外する。
+     *
+     * @param  list<class-string>  $candidates  照合する FQCN 母集団
+     * @return list<class-string>
+     */
+    public static function referencedClasses(string $source, array $candidates): array
+    {
+        $scanner = self::analyze($source);
+        $candidateSet = array_fill_keys($candidates, true);
+
+        /** @var array<string, true> $candidateBasenames */
+        $candidateBasenames = [];
+        foreach ($candidates as $candidate) {
+            $position = strrpos($candidate, '\\');
+            $candidateBasenames[$position === false ? $candidate : substr($candidate, $position + 1)] = true;
+        }
+
+        /** @var array<string, true> $found */
+        $found = [];
+
+        // 収集元 1: use 文
+        foreach ($scanner->useMap as $fqcn) {
+            if (isset($candidateSet[$fqcn])) {
+                $found[$fqcn] = true;
+            }
+        }
+
+        $count = count($scanner->tokens);
+        for ($i = 0; $i < $count; $i++) {
+            if ($scanner->isImportToken[$i] || $scanner->isDeclarationName[$i]) {
+                continue;
+            }
+
+            $token = $scanner->tokens[$i];
+
+            // 収集元 3: 文字列リテラルの FQCN 完全一致
+            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
+                $literal = self::normalizeStringLiteral($token['text']);
+                if ($literal !== null && isset($candidateSet[$literal])) {
+                    $found[$literal] = true;
+                }
+
+                continue;
+            }
+
+            // 収集元 2: 完全修飾 / 修飾名
+            if ($token['id'] === T_NAME_FULLY_QUALIFIED || $token['id'] === T_NAME_QUALIFIED) {
+                $fqcn = $scanner->resolve($token['text']);
+                if (isset($candidateSet[$fqcn])) {
+                    $found[$fqcn] = true;
+                }
+
+                continue;
+            }
+
+            // 収集元 4: 同一 namespace / use 経由の short name
+            if ($token['id'] === T_STRING
+                && isset($candidateBasenames[$token['text']])
+                && ! self::isMemberAccessBoundary($scanner->tokens[$i - 1] ?? null)) {
+                $fqcn = $scanner->resolve($token['text']);
+                if (isset($candidateSet[$fqcn])) {
+                    $found[$fqcn] = true;
+                }
+            }
+        }
+
+        /** @var list<class-string> $result */
+        $result = array_keys($found);
+        sort($result);
+
+        return $result;
+    }
+
+    /**
+     * ソースをトークン化し namespace / use map / 走査除外範囲を確定する。
+     */
+    private static function analyze(string $source): self
+    {
+        $tokens = self::tokenize($source);
+        $count = count($tokens);
+        $namespace = '';
+        $useMap = [];
+        $isImportToken = array_fill(0, max($count, 1), false);
+        $isDeclarationName = array_fill(0, max($count, 1), false);
+
+        // クラス本体の `use SomeTrait;` を import と誤認しないための波括弧深さ
+        // (非 bracketed namespace 前提。namespace / グループ use の波括弧は下の分岐で読み飛ばす)。
+        $braceDepth = 0;
+
+        for ($i = 0; $i < $count; $i++) {
+            $token = $tokens[$i];
+
+            if ($token['text'] === '{') {
+                $braceDepth++;
+
+                continue;
+            }
+            if ($token['text'] === '}') {
+                $braceDepth--;
+
+                continue;
+            }
+
+            if ($token['id'] === T_NAMESPACE) {
+                for ($j = $i; $j < $count; $j++) {
+                    $isImportToken[$j] = true;
+                    if (in_array($tokens[$j]['id'], [T_STRING, T_NAME_QUALIFIED], true)) {
+                        $namespace = $tokens[$j]['text'];
+                    }
+                    if ($tokens[$j]['text'] === ';' || $tokens[$j]['text'] === '{') {
+                        break;
+                    }
+                }
+                $i = $j;
+
+                continue;
+            }
+
+            if ($token['id'] === T_USE) {
+                // クロージャの `use (...)` とクラス本体の trait use は import ではない
+                // (trait use を import 扱いすると use map が汚れ、短縮名の解決が壊れる)
+                if (($tokens[$i + 1]['text'] ?? '') === '(' || $braceDepth > 0) {
+                    continue;
+                }
+
+                $statement = [];
+                for ($j = $i; $j < $count; $j++) {
+                    $isImportToken[$j] = true;
+                    $statement[] = $tokens[$j];
+                    if ($tokens[$j]['text'] === ';') {
+                        break;
+                    }
+                }
+                self::parseUseStatement($statement, $useMap);
+                $i = $j;
+
+                continue;
+            }
+
+            // クラス様宣言の名前 (自己参照は漏洩の証拠ではないので参照として数えない)
+            if (in_array($token['id'], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
+                && ($tokens[$i - 1]['id'] ?? -1) !== T_DOUBLE_COLON
+                && ($tokens[$i + 1]['id'] ?? -1) === T_STRING) {
+                $isDeclarationName[$i + 1] = true;
+            }
+        }
+
+        return new self($tokens, $namespace, $useMap, $isImportToken, $isDeclarationName);
+    }
+
+    /**
+     * `use` 文 1 本を use map へ展開する (単純 / alias / グループ / カンマ区切りに対応)。
+     *
+     * @param  list<array{id: int, text: string, line: int}>  $statement
+     * @param  array<string, string>  $useMap
+     */
+    private static function parseUseStatement(array $statement, array &$useMap): void
+    {
+        $count = count($statement);
+        $i = 1; // $statement[0] === T_USE
+
+        // `use function ...` / `use const ...` はクラス import ではない
+        if (in_array($statement[$i]['id'] ?? -1, [T_FUNCTION, T_CONST], true)) {
+            return;
+        }
+
+        $prefix = '';
+        $name = '';
+        $alias = null;
+        $expectingAlias = false;
+
+        for (; $i < $count; $i++) {
+            $token = $statement[$i];
+
+            if (in_array($token['id'], self::NAME_TOKEN_IDS, true)) {
+                if ($expectingAlias) {
+                    $alias = $token['text'];
+                } else {
+                    $name .= $token['text'];
+                }
+
+                continue;
+            }
+
+            if ($token['id'] === T_NS_SEPARATOR) {
+                $name .= '\\';
+
+                continue;
+            }
+
+            if ($token['text'] === '{') {
+                $prefix = $name;
+                $name = '';
+
+                continue;
+            }
+
+            if ($token['id'] === T_AS) {
+                $expectingAlias = true;
+
+                continue;
+            }
+
+            if ($token['text'] === ',' || $token['text'] === '}' || $token['text'] === ';') {
+                self::registerUse($prefix, $name, $alias, $useMap);
+                $name = '';
+                $alias = null;
+                $expectingAlias = false;
+
+                if ($token['text'] === '}') {
+                    $prefix = '';
+                }
+            }
+        }
+    }
+
+    /**
+     * @param  array<string, string>  $useMap
+     */
+    private static function registerUse(string $prefix, string $name, ?string $alias, array &$useMap): void
+    {
+        if ($name === '') {
+            return;
+        }
+
+        $fqcn = ltrim($prefix.$name, '\\');
+        $segments = explode('\\', $fqcn);
+        $short = $alias ?? $segments[count($segments) - 1];
+        $useMap[$short] = $fqcn;
+    }
+
+    /**
+     * 短縮名 / 修飾名を FQCN へ正規化する。
+     */
+    private function resolve(string $name): string
+    {
+        if (str_starts_with($name, '\\')) {
+            return ltrim($name, '\\');
+        }
+
+        $segments = explode('\\', $name);
+        if (isset($this->useMap[$segments[0]])) {
+            $segments[0] = $this->useMap[$segments[0]];
+
+            return implode('\\', $segments);
+        }
+
+        return $this->namespace === '' ? $name : $this->namespace.'\\'.$name;
+    }
+
+    /**
+     * `$this->app` が出現するトークン位置 (`$this` の index)。
+     *
+     * @return list<int>
+     */
+    private function appAccessIndexes(): array
+    {
+        $indexes = [];
+        $count = count($this->tokens);
+
+        for ($i = 0; $i + 2 < $count; $i++) {
+            if ($this->tokens[$i]['id'] === T_VARIABLE
+                && $this->tokens[$i]['text'] === '$this'
+                && $this->tokens[$i + 1]['id'] === T_OBJECT_OPERATOR
+                && $this->tokens[$i + 2]['id'] === T_STRING
+                && $this->tokens[$i + 2]['text'] === 'app') {
+                $indexes[] = $i;
+            }
+        }
+
+        return $indexes;
+    }
+
+    /** `$this->app-><method>(` の形か */
+    private function isAppMethodCallAt(int $index): bool
+    {
+        return ($this->tokens[$index + 3]['id'] ?? -1) === T_OBJECT_OPERATOR
+            && ($this->tokens[$index + 4]['id'] ?? -1) === T_STRING
+            && ($this->tokens[$index + 5]['text'] ?? '') === '(';
+    }
+
+    /**
+     * `$this->app-><method>(…)` の呼び出し一覧。
+     *
+     * @return list<array{method: string, line: int, args: list<list<array{id: int, text: string, line: int}>>}>
+     */
+    private function appMethodCalls(): array
+    {
+        $calls = [];
+
+        foreach ($this->appAccessIndexes() as $index) {
+            if (! $this->isAppMethodCallAt($index)) {
+                continue;
+            }
+
+            $calls[] = [
+                'method' => $this->tokens[$index + 4]['text'],
+                'line' => $this->tokens[$index + 4]['line'],
+                'args' => $this->parseArguments($index + 5),
+            ];
+        }
+
+        return $calls;
+    }
+
+    /**
+     * `(` の位置から位置引数を切り出す (トップレベルの `,` で分割)。
+     *
+     * @return list<list<array{id: int, text: string, line: int}>>
+     */
+    private function parseArguments(int $open): array
+    {
+        $depth = 0;
+        $args = [];
+        $current = [];
+        $count = count($this->tokens);
+
+        for ($i = $open; $i < $count; $i++) {
+            $text = $this->tokens[$i]['text'];
+
+            if ($text === '(' || $text === '[' || $text === '{') {
+                $depth++;
+                if ($depth === 1) {
+                    continue;
+                }
+            } elseif ($text === ')' || $text === ']' || $text === '}') {
+                $depth--;
+                if ($depth === 0) {
+                    if ($current !== []) {
+                        $args[] = $current;
+                    }
+
+                    return $args;
+                }
+            } elseif ($text === ',' && $depth === 1) {
+                $args[] = $current;
+                $current = [];
+
+                continue;
+            }
+
+            $current[] = $this->tokens[$i];
+        }
+
+        if ($current !== []) {
+            $args[] = $current;
+        }
+
+        return $args;
+    }
+
+    /**
+     * `A::class` 形の引数か (name token + `::` + `class` のちょうど 3 トークン)。
+     *
+     * @param  list<array{id: int, text: string, line: int}>  $arg
+     */
+    private static function isClassConstant(array $arg): bool
+    {
+        return count($arg) === 3
+            && in_array($arg[0]['id'], self::NAME_TOKEN_IDS, true)
+            && $arg[1]['id'] === T_DOUBLE_COLON
+            && $arg[2]['id'] === T_CLASS;
+    }
+
+    /**
+     * 名前付き引数 (`abstract: A::class`) か。
+     *
+     * @param  list<array{id: int, text: string, line: int}>  $arg
+     */
+    private static function isNamedArgument(array $arg): bool
+    {
+        return count($arg) >= 2 && $arg[0]['id'] === T_STRING && $arg[1]['text'] === ':';
+    }
+
+    /**
+     * spread unpack (`...$args`) を含むか。
+     *
+     * @param  list<array{id: int, text: string, line: int}>  $arg
+     */
+    private static function containsUnpack(array $arg): bool
+    {
+        foreach ($arg as $token) {
+            if ($token['id'] === T_ELLIPSIS) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * 直前のトークンが「名前がクラス参照ではない」ことを示す境界か
+     * (`->` / `?->` / `::` / `function` / `const` / `new` 以外の宣言文脈)。
+     *
+     * @param  array{id: int, text: string, line: int}|null  $previous
+     */
+    private static function isMemberAccessBoundary(?array $previous): bool
+    {
+        if ($previous === null) {
+            return false;
+        }
+
+        return in_array(
+            $previous['id'],
+            [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST],
+            true
+        );
+    }
+
+    /**
+     * 文字列リテラルの中身 (FQCN 表記の `\\` を 1 個の `\` へ畳む)。
+     */
+    private static function normalizeStringLiteral(string $text): ?string
+    {
+        if (strlen($text) < 2) {
+            return null;
+        }
+
+        $quote = $text[0];
+        if ($quote !== "'" && $quote !== '"') {
+            return null;
+        }
+
+        $inner = substr($text, 1, -1);
+        if ($quote === "'") {
+            $inner = str_replace("\\'", "'", $inner);
+        }
+        $inner = str_replace('\\\\', '\\', $inner);
+
+        return ltrim($inner, '\\');
+    }
+
+    /**
+     * コメント / 空白 / タグを除去したトークン列。
+     *
+     * 文字列リテラルは FQCN 完全一致の照合に要るのでトークンとしては残すが、
+     * その中身をコードとして解釈しない。
+     *
+     * @return list<array{id: int, text: string, line: int}>
+     */
+    private static function tokenize(string $source): array
+    {
+        $tokens = [];
+        $line = 1;
+
+        foreach (token_get_all($source) as $token) {
+            if (is_array($token)) {
+                $line = $token[2];
+                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
+                    continue;
+                }
+                $tokens[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];
+
+                continue;
+            }
+
+            $tokens[] = ['id' => -1, 'text' => $token, 'line' => $line];
+        }
+
+        return $tokens;
+    }
+}
diff --git a/tests/Unit/Architecture/FakeWiringSourceScannerTest.php b/tests/Unit/Architecture/FakeWiringSourceScannerTest.php
new file mode 100644
index 0000000..d26c368
--- /dev/null
+++ b/tests/Unit/Architecture/FakeWiringSourceScannerTest.php
@@ -0,0 +1,207 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Billing\Fakes\FakeExternalUrl;
+use App\Services\Billing\Fakes\FakeStripeGateway;
+use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
+use App\Services\Billing\TicketCheckoutGateway;
+use App\Support\FakeStorageGate;
+use Tests\Support\ExternalFakes\FakeWiringSourceScanner;
+
+/*
+ * fake 配線 gate の走査器 (FakeWiringSourceScanner) の positive / negative 固定。
+ *
+ * gate 自体がセキュリティ機構であり、**走査器が壊れたら gate は静かに無力化する**。
+ * PrimaryKeyStaticQueryScannerTest / AuthorizationMarkerScannerTest と同じ位置づけで、
+ * 「何を検出し、何を検出しないか」をここで恒久固定する。
+ *
+ * 新しい抜け道を見つけたら、allowlist を緩めるのではなく**ここにケースを足す**。
+ */
+
+/** 走査対象の最小ソースを組み立てる (namespace + use + メソッド本体) */
+function fakeWiringScannerSource(string $uses, string $body, string $namespace = 'App\\Providers'): string
+{
+    return "<?php\n\ndeclare(strict_types=1);\n\n"
+        ."namespace {$namespace};\n\n"
+        ."{$uses}\n\n"
+        ."final class DemoProvider\n"
+        ."{\n"
+        ."    public function register(): void\n"
+        ."    {\n"
+        ."{$body}\n"
+        ."    }\n"
+        ."}\n";
+}
+
+test('5-1: bind(A::class, B::class) は 1 組として読み取れる', function (): void {
+    $source = fakeWiringScannerSource('', '        $this->app->bind(\App\Demo\A::class, \App\Demo\B::class);');
+
+    expect(FakeWiringSourceScanner::bindPairs($source))->toBe([
+        ['abstract' => 'App\Demo\A', 'concrete' => 'App\Demo\B'],
+    ]);
+});
+
+test('5-2: bind の第 2 引数が closure なら concrete は null (呼び出し側で fail させる)', function (): void {
+    $source = fakeWiringScannerSource('', '        $this->app->bind(\App\Demo\A::class, fn () => new \App\Demo\B);');
+
+    expect(FakeWiringSourceScanner::bindPairs($source))->toBe([
+        ['abstract' => 'App\Demo\A', 'concrete' => null],
+    ]);
+});
+
+test('5-3: singleton() は許可された呼び出し形ではない', function (): void {
+    $source = fakeWiringScannerSource('', '        $this->app->singleton(\App\Demo\A::class, \App\Demo\B::class);');
+
+    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toHaveCount(1);
+});
+
+test('5-4: 未知の container API (rebinding) も検出する = API 名の列挙に依存しない', function (): void {
+    $source = fakeWiringScannerSource('', '        $this->app->rebinding(\App\Demo\A::class, fn () => null);');
+
+    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toHaveCount(1);
+});
+
+test('5-5: make(FakeStorageGate::class) は許可される', function (): void {
+    $source = fakeWiringScannerSource(
+        'use App\Support\FakeStorageGate;',
+        '        $this->app->make(FakeStorageGate::class);'
+    );
+
+    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([]);
+});
+
+test('5-6: make(未分類クラス) は委譲による逃げ道として検出する', function (): void {
+    $source = fakeWiringScannerSource('', '        $this->app->make(\App\Demo\SomeRegistrar::class)->register();');
+
+    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toHaveCount(1);
+});
+
+test('5-7: app() / resolve() / Container::getInstance() は間接到達として検出する', function (): void {
+    $helper = fakeWiringScannerSource('', '        app()->bind(\App\Demo\A::class, \App\Demo\B::class);');
+    $resolve = fakeWiringScannerSource('', '        resolve(\App\Demo\A::class);');
+    $container = fakeWiringScannerSource(
+        'use Illuminate\Container\Container;',
+        '        Container::getInstance()->bind(\App\Demo\A::class, \App\Demo\B::class);'
+    );
+
+    expect(FakeWiringSourceScanner::disallowedIndirectAccess($helper))->toHaveCount(1)
+        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($resolve))->toHaveCount(1)
+        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($container))->toHaveCount(1);
+});
+
+test('5-8: $this->app の非呼び出し出現 (変数への退避) を検出する', function (): void {
+    $source = fakeWiringScannerSource('', '        $container = $this->app;');
+
+    expect(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toHaveCount(1);
+});
+
+test('5-9: コメント / docblock 中の container 呼び出しは誤検出しない', function (): void {
+    $body = "        // \$this->app->singleton(\\App\\Demo\\A::class, \\App\\Demo\\B::class);\n"
+        ."        /** \$this->app->singleton(\\App\\Demo\\A::class, \\App\\Demo\\B::class); */\n"
+        .'        $this->app->bind(\App\Demo\A::class, \App\Demo\B::class);';
+    $source = fakeWiringScannerSource('', $body);
+
+    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([])
+        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toBe([]);
+});
+
+test('5-10: FQCN と完全一致する文字列リテラルは参照として検出する', function (): void {
+    $source = fakeWiringScannerSource('', '        $class = \'App\Services\Billing\Fakes\FakeStripeGateway\';');
+
+    expect(FakeWiringSourceScanner::referencedClasses($source, [FakeStripeGateway::class]))
+        ->toBe([FakeStripeGateway::class]);
+});
+
+test('5-11: 説明文中の class basename は部分一致では検出しない', function (): void {
+    $source = fakeWiringScannerSource('', '        $note = \'説明文に FakeStripeGateway と書いただけ\';');
+
+    expect(FakeWiringSourceScanner::referencedClasses($source, [FakeStripeGateway::class]))->toBe([]);
+});
+
+test('5-12: グループ use を FQCN へ解決する', function (): void {
+    $source = fakeWiringScannerSource(
+        'use App\Services\Billing\Fakes\{FakeExternalUrl as Ext, FakeStripeGateway};',
+        '        $this->app->bind(Ext::class, FakeStripeGateway::class);'
+    );
+
+    expect(FakeWiringSourceScanner::bindPairs($source))->toBe([
+        ['abstract' => FakeExternalUrl::class, 'concrete' => FakeStripeGateway::class],
+    ]);
+});
+
+test('5-13: use された短縮名の bind 組を FQCN 正規化して返す (現行 provider の実パターン)', function (): void {
+    $uses = "use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;\n"
+        .'use App\Services\Billing\TicketCheckoutGateway;';
+    $source = fakeWiringScannerSource(
+        $uses,
+        '        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);'
+    );
+
+    expect(FakeWiringSourceScanner::bindPairs($source))->toBe([
+        ['abstract' => TicketCheckoutGateway::class, 'concrete' => FakeTicketCheckoutGateway::class],
+    ]);
+});
+
+test('5-14: alias 付き use も FQCN へ正規化する', function (): void {
+    $source = fakeWiringScannerSource(
+        'use App\Services\Billing\Fakes\FakeStripeGateway as Aliased;',
+        '        $class = Aliased::class;'
+    );
+
+    expect(FakeWiringSourceScanner::referencedClasses($source, [FakeStripeGateway::class]))
+        ->toBe([FakeStripeGateway::class]);
+});
+
+test('5-15: make(許可クラス) の chain 呼び出しでも引数照合が効く', function (): void {
+    $uses = "use App\Services\AI\Testing\CannedPromptFakeRegistrar;\n"
+        .'use App\Support\FakeStorageGate;';
+    $body = "        \$this->app->make(FakeStorageGate::class)->enabled();\n"
+        .'        $this->app->make(CannedPromptFakeRegistrar::class)->install();';
+    $source = fakeWiringScannerSource($uses, $body);
+
+    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([])
+        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toBe([]);
+});
+
+test('5-16: bind の第 1 引数が変数の形は禁止する (bindPairs が読めない = 偽グリーン封じ)', function (): void {
+    $source = fakeWiringScannerSource(
+        'use App\Services\Render\Fakes\FakeRenderObjectStorage;',
+        '        $this->app->bind($abstract, FakeRenderObjectStorage::class);'
+    );
+
+    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toHaveCount(1)
+        ->and(FakeWiringSourceScanner::bindPairs($source))->toBe([]);
+});
+
+test('5-17: 同一 namespace の短縮名参照 (use なし) も検出する', function (): void {
+    $body = "        \$class = FakeStorageGate::class;\n"
+        .'        $gate = new FakeStorageGate($this->app);';
+    $source = fakeWiringScannerSource('', $body, 'App\Support');
+
+    expect(FakeWiringSourceScanner::referencedClasses($source, [FakeStorageGate::class]))
+        ->toBe([FakeStorageGate::class]);
+});
+
+test('5-18: bind(…, true) / 引数付き make / 名前付き引数は許可形から外れる', function (): void {
+    $shared = fakeWiringScannerSource('', '        $this->app->bind(\App\Demo\A::class, \App\Demo\B::class, true);');
+    $makeWithArgs = fakeWiringScannerSource(
+        'use App\Support\FakeStorageGate;',
+        '        $this->app->make(FakeStorageGate::class, []);'
+    );
+    $named = fakeWiringScannerSource(
+        '',
+        '        $this->app->bind(abstract: \App\Demo\A::class, concrete: \App\Demo\B::class);'
+    );
+
+    expect(FakeWiringSourceScanner::disallowedContainerCalls($shared))->toHaveCount(1)
+        ->and(FakeWiringSourceScanner::disallowedContainerCalls($makeWithArgs))->toHaveCount(1)
+        ->and(FakeWiringSourceScanner::disallowedContainerCalls($named))->toHaveCount(1);
+});
+
+test('5-19: クラス宣言名は自己参照として数えない', function (): void {
+    $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Support;\n\n"
+        ."final class FakeStorageGate\n{\n}\n";
+
+    expect(FakeWiringSourceScanner::referencedClasses($source, [FakeStorageGate::class]))->toBe([]);
+});
```

## user: テスト結果 (mutation 2 段確認を含む)

# mutation 2 段確認の記録 (T119 受入条件)

新 gate は素の main では赤にならない (穴を塞ぐ類のため)。テストファースト (AGENTS.md 思考原則 5) は
**mutation の 2 段確認**で満たす。手順は `mutations.py` / `run-mutations.sh` (本ディレクトリ) が機械化している。

- 段階 1 (実装前): mutation を当てて **緑のまま = 穴が実在する**ことを記録する
- 段階 3 (実装後): 同じ mutation を当てて **対応する gate が赤くなる**ことを確認する
- mutation は当てたら必ず `git checkout --` で戻す (コミットしない)

## 段階 1: 穴の実在確認 (実装前)

コマンド: `composer test -- --testsuite=Architecture --testsuite=Feature` (2321 tests)

| ID | mutation | 結果 | 備考 |
|---|---|---|---|
| M1 | `AutoRechargeGatewayInterface` の bind を削除 | **緑** (2319 passed / 0 failed) | 穴の実在 |
| M2 | `TakeObjectStorage` の bind を削除 | **赤** (2305 passed / **4 failed**) | 既存 Feature が部分被覆 (下記) |
| M3 | `bootstrap/providers.php` から provider を削除 | **緑** | 穴の実在 |
| M4 | provider を `AppServiceProvider` の直前へ移動 | **緑** | 穴の実在 |
| M5 | inventory 未登録の bind 組を追加 (既存 fake クラス) | **緑** | 穴の実在 |
| M5b | inventory 未登録の fake クラスを provider が新規参照 | **緑** | 穴の実在 (3-10 用の変種) |
| M6 | `bind(` を `singleton(` へ | **緑** | 穴の実在 |
| M6b | `bind(A::class, B::class, true)` (= singleton 相当) | **緑** | 穴の実在 (M6 回避路) |
| M7 | Service に fake クラスの `use` を追加 | **緑** | 穴の実在 |

### M2 の扱い (設計の想定どおり)

M2 は既存 Feature テスト 4 本が落ちた。落ち方は
`InvalidArgumentException: Missing required client configuration options: region` = **実 S3 クライアントの組み立て**で、
「bind が消えると Laravel が本物を自動組み立てして実 S3 を叩く」という本 feature の核心そのものが露出した形である。

- 落ちたのは `Tests\Feature\Capture\TakePlaybackTest` ほか計 4 test (すべて S3 region 未設定の 500)
- ただし **Architecture lane (不変条件の正本) は 1 本も赤くならなかった** = 「登録漏れを不変条件として見ている層は無い」という穴は実在する
- 段階 3 で `--testsuite=Architecture` 単独でも赤くなることを確認済み (下記)

## 段階 3: gate が赤くなることの確認 (実装後)

コマンド: `composer test -- --testsuite=Architecture` (381 tests。**Feature を含めない = Architecture 側が正本**であることの確認)

| ID | 結果 | 赤くなったテスト |
|---|---|---|
| M1 | **赤** (4 failed) | 3-2 (AutoRecharge × allowlist 3 環境) / 3-8 |
| M2 | **赤** (3 failed) | 3-2 (TakeObjectStorage × allowlist 2 環境) / 3-8 |
| M3 | **赤** (3 failed) | 3-5 / 3-6 / 3-7 |
| M4 | **赤** (1 failed) | 3-6 |
| M5 | **赤** (1 failed) | **3-8 のみ** (設計どおり。既存 fake クラスを使うため 3-10 は変化しない) |
| M5b | **赤** (1 failed) | **3-10** (未登録 fake クラスの新規参照) |
| M6 | **赤** (2 failed) | 3-9 / 3-8 (`singleton` は bind 組から外れるため) |
| M6b | **赤** (1 failed) | 3-9 (`bind(…, true)` = singleton 相当を引数個数で禁止) |
| M7 | **赤** (1 failed) | 4-3 |

設計の被覆表 (M1/M2 → 3-2、M3 → 3-5/3-7、M4 → 3-6、M5 → 3-8、M6 → 3-9、M7 → 4-3) をすべて満たし、
M1/M2 が 3-8 も、M3 が 3-6 も、M6 が 3-8 も追加で捕まえている (被覆は設計より広い)。

## 段階 4: 全体検証 (実装後・mutation なし)

| コマンド | 結果 |
|---|---|
| `composer test -- --testsuite=Architecture` | 381 passed / 0 failed |
| `composer test -- --testsuite=Architecture` (2 回目) | 381 passed / 0 failed |
| `composer test -- --testsuite=Architecture --order-by=random --random-order-seed=20260806` | 381 passed / 0 failed |
| `composer test` (全体) | 3307 tests / 3305 passed / 0 failed / 2 skipped |
| `composer phpstan` | No errors (791 files) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` | passed (フロント差分なし) |
| `git status` | `app/` / `config/` / `bootstrap/` / `routes/` に差分なし |

実装前の Architecture は 340 tests、実装後は 381 tests (+41 = 3-1〜3-12 の 37 + 4-1〜4-4 の 4)。
走査器の Unit テストは 19 tests (5-1〜5-19)。

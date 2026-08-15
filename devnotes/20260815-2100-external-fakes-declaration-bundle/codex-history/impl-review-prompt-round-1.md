## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則
## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Laravel 12 + Svelte 5 (Inertia) アプリのコードレビュアーである。
以下の実装差分をレビューせよ。

## レビュー観点
- 詳細設計との一致性 (設計から逸脱している箇所は、逸脱が正当かを判定する)
- 正確性 (論理の誤り・境界条件・テストの偽グリーン)
- PHPStan level 10 適合性 (型を緩めて黙らせていないか)
- DTO / JsonResource / Inertia パターン
- テスト網羅性 (deny-by-default の目録は「登録漏れが赤くなる」か。負のコントロールがあるか)
- セキュリティ (偽の外部サービスが本番へ混入しないか。秘密が子プロセス・一時ファイルへ漏れないか)
- **保証範囲の記述が誇張になっていないか** (このリポジトリは「保証しないもの」を明記する規約を持つ)
- DESIGN.md 準拠 / Atomic Design 準拠 (今回の差分にフロントの変更は無いので該当なし)

## 出力形式
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で書く

---

## 詳細設計書

# 詳細設計: external-fakes-declaration-bundle

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本件で特に効く追加の禁止**: PHP の `echo` / `goto` / `global` と開始タグ付きの出力記法は書かない
(AGENTS.md §禁止する文)。別プロセス観測用スクリプトの出力は `fwrite(STDOUT, …)` で書く。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** はグローバル適用、個別 `DatabaseTransactions` 禁止
- テストデータは Factory 生成。`declare(strict_types=1)` は git 追跡下 PHP 全数が対象
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) （Codex 合議 Round 3 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 差し替えの宣言集合を本番側 1 か所へ集約する | `app/Support/ExternalFakes/ExternalFakeDeclaration.php` (新) / `app/Support/ExternalFakes/ExternalFakeBinding.php` (移設) / `app/Providers/FakeExternalsServiceProvider.php` / `app/Support/FakeStorageGate.php` / `config/testing.php` / `database/seeders/Bughunt{Billing,OAuth}Seeder.php` / `tests/Support/ExternalFakes/{ExternalFakeWiringInventory,ExternalFakeBinding}.php` (削除) / `tests/Support/ExternalFakes/FakeWiringSourceScanner.php` / `tests/Architecture/{ExternalFakeWiringInvariantTest,FakeClassReferenceInvariantTest,BughuntEnvExampleContractTest}.php` / `tests/Unit/Architecture/FakeWiringSourceScannerTest.php` | 高 |
| 1c | レーン側からの直接差し替えの静的禁止 | `tests/Architecture/LaneExternalFakeBindingTest.php` (新) | 中 |
| 2 | 投入データ (seeder) の配線検査 | `tests/Support/Bughunt/{BughuntSeedRole,BughuntSeedWiringInventory,ShellFunctionWindow}.php` (新) / `tests/Architecture/BughuntSeedWiringInvariantTest.php` (新) / `tests/Architecture/BughuntOrchestratorGateInvariantTest.php` (関数窓の共有化) | 高 |
| 3 | 本番混入防止に実環境変数の二重判定を足す | `app/Support/ProductionEnvGuard.php` / `tests/Feature/Support/ProductionEnvGuardTest.php` | 中 |
| 4 | 別プロセスで差し替えを実測する | `tests/Support/ExternalFakes/fake-wiring-probe.php` (新) / `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` (新) / `tests/Architecture/ExternalFakeBootProbeTest.php` (新) | 中 |
| 5 | skill-bug-hunt に実作業が無いことの明記 | `docs/architecture.md` (§外部到達点の目録に 1 段落) | 低 |

---

## 施策 1: 差し替えの宣言集合を本番側 1 か所へ集約する

### 変更箇所

- 新設: `app/Support/ExternalFakes/ExternalFakeDeclaration.php`
- 移設: `tests/Support/ExternalFakes/ExternalFakeBinding.php` → `app/Support/ExternalFakes/ExternalFakeBinding.php`
- 削除: `tests/Support/ExternalFakes/ExternalFakeWiringInventory.php` (165 行)
- 書き換え: `app/Providers/FakeExternalsServiceProvider.php` (L60-L200 相当。定数 3 本と bind 列を削除)
- 書き換え: `app/Support/FakeStorageGate.php` (許可環境を宣言から読む)
- 書き換え: `config/testing.php` (差し替え対象の列挙を削除)
- 書き換え: `database/seeders/BughuntBillingSeeder.php` / `BughuntOAuthSeeder.php` (フラグ key を定数参照へ)
- 書き換え: `tests/Support/ExternalFakes/FakeWiringSourceScanner.php` (`bind` の許可形を変更)

### 波及変更

- TypeScript 型定義: なし (サーバ内部の配線のみ)
- API Resource/DTO: なし
- テストファイル:
  - `tests/Architecture/ExternalFakeWiringInvariantTest.php` — 参照先を宣言クラスへ。
    3-8 (bind 組の集合一致) を**削除**し、3-10 を
    「provider が参照する fake 系クラスは**配線基盤 4 件ちょうど**であり、
    **差し替え先 (swaps() の fake) を 1 件も含まない**」へ強化
    (**訂正**: 当初は「1 つも参照しない」と書いていたが成立しない。provider は
    `FakeStorageGate` / `CannedPromptFakeRegistrar` / 偽の保存先の経路の受け口 2 本を
    参照し続ける。§実装時に判明した設計の訂正 (1) を参照)
  - `tests/Architecture/FakeClassReferenceInvariantTest.php` — 参照 allowlist に宣言クラスを足す
    (件数固定の 4-4 も同じ変更で直す。**訂正**: provider は allowlist から外せない。
    5 件 → 6 件になる)
  - `tests/Architecture/BughuntEnvExampleContractTest.php` — `TESTING_FAKE_EXTERNALS => 'true'` の
    直書きをやめ、`ExternalFakeDeclaration::bughuntRequiredEnvFlags()` から組み立てる
  - `tests/Unit/Architecture/FakeWiringSourceScannerTest.php` — `bind` の許可形の変更に追随
    (正例・負例の両方)
  - `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` / `tests/Unit/Support/FakeStorageGateTest.php` /
    `tests/Feature/Auth/FakeSocialiteWiringTest.php` — **挙動不変のため原則そのまま緑**
    (緑のままであることが「挙動を変えていない」証拠になる)

### 現行コード

```php
// app/Providers/FakeExternalsServiceProvider.php (抜粋)
private const array EXTERNAL_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];
private const array SSO_FAKE_ENVIRONMENTS = ['testing', 'bughunt.local'];
private const array LLM_FAKE_ENVIRONMENTS = ['bughunt.local'];

private function registerExternalServiceFakes(): void
{
    if (config('testing.fake_externals') !== true) { return; }
    $environment = $this->app->environment();
    if (! in_array($environment, self::EXTERNAL_FAKE_ENVIRONMENTS, true)) {
        Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [...]);
        return;
    }
    $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
    $this->app->bind(AutoRechargeGatewayInterface::class, FakeAutoRechargeGateway::class);
    $this->app->bind(RecaptchaVerifier::class, RecaptchaVerifierTestFake::class);
}
// … registerSocialAuthFake() / registerStorageFakes() が同じ形で 3 本続く
```

同じ 7 組が `tests/Support/ExternalFakes/ExternalFakeWiringInventory::bindings()` にも書かれており、
`ExternalFakeWiringInvariantTest` の 3-8 が provider のソースを走査して集合一致を確かめている
(= 同じ内容を 2 か所に書いて、ずれたら落とす形)。

### 変更後コード

```php
// app/Support/ExternalFakes/ExternalFakeDeclaration.php (新設・抜粋)

/**
 * 「どの外部到達点を、どのフラグと許可環境で、どの偽の実装へ差し替えるか」の唯一の正本。
 *
 * ★本番の読み込み対象 (app/) に置く。provider・storage gate・seeder・各 gate が
 *   すべてここだけを読む (同じ集合を 2 か所に書かない)。
 * ★本クラスは値を返すだけで判定を持たない。有効・無効の判定は
 *   FakeExternalsServiceProvider (container 差し替え) と FakeStorageGate (storage) が行う。
 */
final class ExternalFakeDeclaration
{
    /** 外部サービス fake (決済 + 人間性確認 + 外部ログイン) の capability flag */
    public const string EXTERNALS_FLAG = 'testing.fake_externals';

    /** storage fake の capability flag */
    public const string STORAGE_FLAG = 'testing.fake_storage';

    /** LLM fake の capability flag (container 差し替えではないため swaps() には現れない) */
    public const string LLM_FLAG = 'testing.fake_llm';

    /** capability flag の config キー => 対応する環境変数名 (本番混入防止と env ひな型検査が読む) */
    public const array FLAG_ENVIRONMENT_VARIABLES = [
        self::EXTERNALS_FLAG => 'TESTING_FAKE_EXTERNALS',
        self::STORAGE_FLAG => 'TESTING_FAKE_STORAGE',
        self::LLM_FLAG => 'TESTING_FAKE_LLM',
    ];

    /** 外部サービス fake の許可環境 (capability 全体。個々の差し替えはこれ以下に絞れる) */
    public const array EXTERNAL_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /** 外部ログインの差し替えだけ local を外す (未認証 GET 2 本で canned アカウントに入れる = 認証バイパス。
     *  かつ local は実 IdP 連携を確かめる唯一の環境である) */
    public const array SSO_ENVIRONMENTS = ['testing', 'bughunt.local'];

    /** storage fake の許可環境 (testing での追加条件は FakeStorageGate が持つ) */
    public const array STORAGE_ENVIRONMENTS = ['testing', 'bughunt.local'];

    /** LLM fake の許可環境 (Prompt::$fake はプロセス大域の static のため testing/local を外す) */
    public const array LLM_ENVIRONMENTS = ['bughunt.local'];

    /**
     * bug-hunt レーンで偽物を外せないフラグ (正典 v1 の「安全下限集合」に当たる名前付き正本)。
     *
     * 決済・人間性確認・外部ログインは、bug-hunt の走行そのものが実課金と実 IdP 遷移を
     * 起こすため、走行オプションで実物へ戻す口を持たない。
     * この不変条件を実際に固定するのは BughuntEnvExampleContractTest であり、
     * 本メソッドはその入力元 (集合の正本) である。
     *
     * @return list<string> config キー
     */
    public static function bughuntRequiredFlags(): array
    {
        return [self::EXTERNALS_FLAG];
    }

    /** @return array<string, string> 環境変数名 => 期待値 (env ひな型検査が読む) */
    public static function bughuntRequiredEnvFlags(): array
    {
        $required = [];
        foreach (self::bughuntRequiredFlags() as $flag) {
            $required[self::FLAG_ENVIRONMENT_VARIABLES[$flag]] = 'true';
        }

        return $required;
    }

    /**
     * 差し替えてはいけない到達点 (偽物に落とすと検査そのものが無効になるもの)。
     *
     * ★責務分担: 本メソッドが止めるのは「この宣言へ足すこと」だけである。
     *   本番コードが偽物のクラス名を参照しないことの全走査は
     *   `FakeClassReferenceInvariantTest` が担い、外部到達点の目録は
     *   `ExternalSeamInventory` が担う (同じ事実を 3 か所で宣言しない)。
     *
     * @return array<class-string, string> クラス => なぜ差し替えないか
     */
    public static function neverSwapped(): array
    {
        return [
            SnsSignatureVerifier::class => '受信通知の署名検証。偽物にすると差出人の詐称を検出できなくなる。',
            UrlSafetyInspector::class => '外部 URL の安全検査 (SSRF 防御)。偽物にすると内部宛ての取得が通る。',
        ];
    }

    /**
     * container 差し替えの全宣言。
     *
     * ⚠️ entry を足す実装者へ: Architecture レーンは RefreshDatabase を使わない。
     * abstract / real / fake のコンストラクタが DB に触れないことを必ず確認すること。
     *
     * @return list<ExternalFakeBinding>
     */
    public static function swaps(): array
    {
        return [ /* 現行 ExternalFakeWiringInventory::bindings() の 7 件をそのまま移す */ ];
    }
}
```

```php
// app/Providers/FakeExternalsServiceProvider.php (変更後・抜粋)

public function register(): void
{
    $this->installDeclaredSwaps();
}

/** 宣言集合を 1 本の経路で差し替える (bind 対象の決定は宣言側にしか無い) */
private function installDeclaredSwaps(): void
{
    $environment = $this->app->environment();
    $this->warnIfExternalsFlagIsUnusable($environment);

    // storage は「登録条件と実行時条件を完全一致させる」ため判定を gate に一元化している
    // (route cache が残った状態で素通りしないようにするため)。ここでは 1 度だけ解決する。
    $storageEnabled = $this->app->make(FakeStorageGate::class)->enabled();

    foreach (ExternalFakeDeclaration::swaps() as $swap) {
        $enabled = $swap->flag === ExternalFakeDeclaration::STORAGE_FLAG
            ? $storageEnabled
            : config($swap->flag) === true
                && in_array($environment, $swap->allowedEnvironments, true);

        if (! $enabled) {
            continue;
        }

        $this->app->bind($swap->abstract, $swap->fake);
    }
}
```

```php
// app/Providers/FakeExternalsServiceProvider.php (変更後・警告の仕様。挙動は現行と 1 対 1)

/**
 * 外部サービスのフラグが立っているのに、その capability の許可環境の外にいるときだけ
 * **1 度だけ**警告する (未知の環境で誤って有効化されたことを検出可能にするため)。
 *
 * ★外部ログインだけ許可環境が狭いことについては**警告しない**。あれは誤設定ではなく
 *   設計上の除外であり、警告を出すと既存テスト
 *   (`3-4` / `FakeExternalsServiceProviderTest` が `once()` で固定している呼び出し回数) を壊す。
 * ★storage / LLM のフラグでも警告しない (現行と同じ)。
 */
private function warnIfExternalsFlagIsUnusable(string $environment): void
{
    if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true) {
        return;
    }
    if (in_array($environment, ExternalFakeDeclaration::EXTERNAL_ENVIRONMENTS, true)) {
        return;
    }

    Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
        'environment' => $environment,
    ]);
}
```

```php
// app/Support/FakeStorageGate.php (変更後・抜粋)
public function enabled(): bool
{
    if (config(ExternalFakeDeclaration::STORAGE_FLAG) !== true) {
        return false;
    }

    $env = $this->app->environment();
    if (! in_array($env, ExternalFakeDeclaration::STORAGE_ENVIRONMENTS, true)) {
        return false;
    }

    // testing は自動テスト実行中に限る (testing を HTTP 実行環境として素通ししない)
    return $env !== 'testing' || $this->app->runningUnitTests();
}
```

```php
// tests/Support/ExternalFakes/FakeWiringSourceScanner.php (変更点)
// bind の許可形を「::class 定数 2 個」から「宣言 entry のプロパティ 2 個」へ変える。
// = provider に ::class の bind を手書きすると赤くなる (差し替え先の決定を宣言側だけに閉じる)
private const array ALLOWED_APP_CALLS = [
    'bind' => 'declaredPair',   // ← 旧 'classPair'
    'make' => 'allowlistedClass',
    'environment' => 'none',
];
// 'declaredPair' = 位置引数ちょうど 2 個で、どちらも「変数のプロパティ参照」であり、
// プロパティ名が順に abstract / fake であること (名前付き引数 / unpack は従来どおり fail-closed)。
```

`config/testing.php` は各フラグの意味・既定値・本番での扱いだけを残し、
差し替え対象の列挙を削除して「対象と許可環境の正本は `ExternalFakeDeclaration`」と 1 行で指す。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`list<ExternalFakeBinding>` / `array<class-string, string>` / `list<string>`)
- [x] `ExternalFakeBinding` は `final readonly` + `class-string` 型パラメータ (移設時に変更しない)
- [x] `const array` の型は PHPStan が推論する。`FLAG_ENVIRONMENT_VARIABLES[$flag]` の
      添字アクセスは `bughuntRequiredFlags()` が config キーだけを返すため到達可能
      (キー不在で落ちないことを型で示せない場合は `Assert::keyExists()` を使い、
      型を緩める方向 (`@phpstan-ignore`) には倒さない)
- [x] 返すのは**外部応答ではなく内部の宣言データ**なので DTO / JsonResource の対象外。
      差し替え 1 本の表現には値オブジェクト `ExternalFakeBinding` を使い、素の連想配列にしない
      (`bughuntRequiredFlags()` などのフラグ一覧は `list<string>` / `array<string, string>` で足りる)

### テスト計画

- [x] 既存 `tests/Architecture/ExternalFakeWiringInvariantTest.php` の 3-1〜3-7 / 3-11 は
      **参照先の差し替えだけ**で緑のまま (実証の中身は変えない)
- [x] 3-8 (bind 組の集合一致) を削除。理由をファイル冒頭のコメントに残す
      (「差し替え先の決定が宣言 1 か所になったため、比較する相手が無くなった」)
- [x] 3-9 を維持し、走査器の許可形変更に合わせて期待値を更新
- [x] 3-10 を強化: provider が参照する fake 系クラスは**配線基盤 4 件ちょうど**であり、
      **差し替え先を 1 件も含まない**こと (期待値は同テストの定数に置く。§訂正 (1)(3))
- [x] 新規 `3-13 宣言の健全性`: `swaps()` の abstract に重複が無く、
      各 entry の `allowedEnvironments` が capability の許可環境の部分集合であること
- [x] 新規 `3-16 宣言集合の固定 (意図的な摩擦)`: `swaps()` の abstract 一覧が
      **件数付きで gate 側に写して固定**されていること。
      宣言が唯一の正本になると、entry を消したときに provider の bind もデータセットも
      同時に縮むため、**削除は他のどの検査にも映らない**。これを映すには
      「宣言とは独立にもう一度書いた一覧」が要る。本リポジトリには同じ作法の先例が 2 つある
      (`FakeClassReferenceInvariantTest` の `4-2 配置例外は 2 件から増えていない` /
      `4-4 参照 allowlist は 5 件から増えていない`)。増減させるときは 2 か所を同時に触らせる
- [x] 新規 `3-14 差し替えない対象`: `neverSwapped()` のキーが `swaps()` の abstract と
      **1 件も交わらない**こと (空集合でないことも確かめる = fail-closed)
- [x] 新規 `3-15 設定との一致`: (a) `FLAG_ENVIRONMENT_VARIABLES` の config キーが
      `config('testing')` に**全件存在する**こと、(b) `config/testing.php` のソースに現れる
      `TESTING_FAKE_*` の環境変数名の集合が宣言の環境変数名の集合と**一致する**こと
      (宣言の外に偽物のフラグが増えたらその場で落とす)。
      **`config('testing')` 全体との完全一致は要求しない** — 偽物と無関係な testing 設定を
      将来足せなくなるため (思考原則 2)
- [x] `tests/Unit/Architecture/FakeWiringSourceScannerTest.php`: `declaredPair` の
      正例 (`bind($swap->abstract, $swap->fake)`) と負例 (`::class` 2 個 / プロパティ名違い /
      引数 3 個 / 名前付き引数) を追加し、旧 `classPair` 前提のケースを消す
- [x] `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` (8 test) と
      `tests/Unit/Support/FakeStorageGateTest.php` (6 test) と
      `tests/Feature/Auth/FakeSocialiteWiringTest.php` (10 test) が**無変更で緑**であること
      (= 挙動を変えていないことの回帰)
- [x] 受入は**変異の 2 段確認**で行う (T119 と同じ作法)。実装前に「宣言から 1 entry を消す」
      (→ 3-16 が赤くなる)
      「provider に `::class` の bind を手書きする」「provider が宣言を読まずに素通しする」
      「設定にフラグを 1 本足す」の 4 通りを当てて**すべて素通りする**ことを記録し、
      実装後に**すべて赤くなる**ことを確かめて `mutation-evidence.md` に残す

### リスク

- **走査器の許可形を変えることで、既存の負のコントロールが意味を失う**。
  → 旧 `classPair` のケースを消すのではなく `declaredPair` の負例へ**置き換える**
  (検出力を落とさない)。変更前後で自己検査の件数が減らないことを確認する
- **`config/testing.php` の注釈を削るとフラグの意味が読めなくなる**。
  → 削るのは「どのクラスへ差し替えるか」の列挙だけで、フラグの意味・既定値・
  本番での扱い・SSO だけ許可環境が狭いという事実は残す (宣言クラスへの参照を 1 行足す)
- **削除の変異を映す相手が無くなる**。→ 3-16 (abstract 一覧の件数付き pin) が唯一の受け皿になる。
  この 1 本を消すと削除が無音になるため、テストのコメントに「消すと削除変異が無音になる」と書く。
  なお `ExternalSeamInventory` の目録と集合一致させる案は採らない — 同目録は
  **保存 (AWS / Flysystem) と LLM を意図的に母集団へ入れない** (AGENTS.md ドメイン規約 9) ため、
  7 件中 5 件しか覆えず、覆うために母集団を歪めることになる (別物の概念を統合しない。思考原則 4)
- **宣言クラスが偽物のクラス名を参照するため、本番コードの参照走査に穴が開いたように見える**。
  → 参照 allowlist は件数固定 (4-4) で守られており、provider が抜けて宣言クラスが入る
  = 件数は変わらない。allowlist が増えていないことを同じ変更で確かめる

---

## 施策 1c: レーン側からの直接差し替えの静的禁止

### 変更箇所

- 新設: `tests/Architecture/LaneExternalFakeBindingTest.php`

### 波及変更

- TypeScript 型定義 / API Resource/DTO: なし
- テストファイル: 新設 1 本のみ (既存テストは無変更)

### 変更後コード

```php
// tests/Architecture/LaneExternalFakeBindingTest.php (抜粋)
test('レーン側は app/ の偽の実装クラスを container へ直接結ばない', function (): void {
    $fakes = FakeClassCatalog::implementationClasses();
    $files = laneExternalFakeScanFiles(); // tests/ 配下の *.php (git 追跡下)

    expect($fakes)->not->toBeEmpty()->and($files)->not->toBeEmpty(); // 空走査で緑にしない

    $violations = [];
    foreach ($files as $file) {
        foreach (FakeWiringSourceScanner::bindPairs(FakeClassCatalog::sourceOf($file)) as $pair) {
            if ($pair['concrete'] !== null && in_array($pair['concrete'], $fakes, true)) {
                $violations[] = $file.': '.$pair['abstract'].' => '.$pair['concrete'];
            }
        }
    }

    expect($violations)->toBe([]); // 例外の登録簿は持たない (差し替えの入口は宣言 + provider の 1 本だけ)
});
```

- **per-test の代役 (`tests/Support/Fake*`) は対象外**。あれは Laravel 公式作法のテストダブルで、
  bug-hunt レーンの差し替えとは別概念である (思考原則 4)。対象は `app/` 配下の偽の実装クラスだけ
- 走査器の `bindPairs()` は現在 **`$this->app->bind(…)` しか読めない**。レーン側は
  `app()->bind(…)` と書けるため、そのままでは素通りする。よって本施策で `bindPairs()` を
  **container へ到達する 4 つの形** — `$this->app->bind` / `app()->bind` / `App::bind` /
  `Container::getInstance()->bind` — に対応させ、`use function app as …` の別名も解決する
  (走査器は既に別名の解決表を持っている)。自己検査にはこの 4 形 + 別名の正例と、
  引数が `::class` でない負例を入れる
- **保証範囲を誇張しない**: 読めるのは上の 4 形で第 2 引数が `::class` 定数のものだけである。
  変数経由の結び付け・`instance()` / `swap()` / モック機構経由には**沈黙する**

### PHPStan適合チェック

- [x] 走査結果の型は既存 `bindPairs()` の
      `list<array{abstract: class-string, concrete: class-string|null}>` をそのまま使う
      (対応する形を増やしても戻り値の型は変えない)
- [x] 新しい型を作らない (既存の走査器を再利用する)

### テスト計画

- [x] 正例: 現在の `tests/` 全走査で違反 0 件
      (**訂正**: 実装時の実測では違反 8 件 / 5 ファイルが実在した。
      例外の登録簿は作らず、レーン側を宣言 + provider の 1 本へ寄せ替えた。§訂正 (4))
- [x] 負のコントロール: 合成ソースを 4 形 (`$this->app->bind` / `app()->bind` / `App::bind` /
      `Container::getInstance()->bind`) と別名 (`use function app as c; c()->bind(…)`) で
      渡し、**すべて検出すること** (`tests/Unit/Architecture/FakeWiringSourceScannerTest.php` 側)
- [x] fail-closed: 母集団 (偽物クラス / 走査対象ファイル) が空なら赤くなること

### リスク

- **将来 per-test で本番側の偽物を使いたくなったとき赤くなる**。
  → それは正しい摩擦である (使いたい場合は宣言 + provider を通す)。
  例外の登録簿はあえて作らない

---

## 施策 2: 投入データ (seeder) の配線検査

### 変更箇所

- 新設: `tests/Support/Bughunt/BughuntSeedRole.php` (区分の enum)
- 新設: `tests/Support/Bughunt/BughuntSeedWiringInventory.php` (目録)
- 新設: `tests/Support/Bughunt/ShellFunctionWindow.php` (シェル関数の窓を切り出す純関数)
- 新設: `tests/Architecture/BughuntSeedWiringInvariantTest.php`
- 書き換え: `tests/Architecture/BughuntOrchestratorGateInvariantTest.php`
  (自前の関数窓ヘルパを削除して共有クラスへ委譲)

### 波及変更

- TypeScript 型定義 / API Resource/DTO: なし
- テストファイル: 上記 5 本。**アプリ側のコードは 1 行も変えない** (検査の新設のみ)

### 現行コード

```bash
# scripts/bug-hunt-shard.sh (cmd_provision と cmd_reseed に同じ列が手書きで 2 回ある)
artisan_for_shard "${db}" "${url}" migrate:fresh --seed --force
artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
artisan_for_shard "${db}" "${url}" db:seed --class=BughuntBillingSeeder --force
artisan_for_shard "${db}" "${url}" db:seed --class=AdminUserSeeder --force
artisan_for_shard "${db}" "${url}" db:seed --class=BughuntOAuthSeeder --force
```

`tests/Architecture/BughuntOrchestratorGateInvariantTest.php` には
関数窓を切り出す `bughuntGateFunctionWindow()` が**ファイル直下の関数**として置かれている
(Pest のファイル直下関数はグローバル空間に出るため、別のテストファイルで同名を定義できない)。

### 変更後コード

```php
// tests/Support/Bughunt/BughuntSeedRole.php
enum BughuntSeedRole
{
    /** bug-hunt 環境専用。三重ガード必須・通常の投入経路には載せない */
    case BughuntOnly;
    /** 通常経路にも載るが bug-hunt でも明示投入する。環境ガード必須 */
    case SharedWithBughunt;
    /** 開発者が手で流す fixture。bug-hunt でも明示投入するがガードは要求しない */
    case ManualFixture;
    /** bug-hunt レーンでは明示投入しない */
    case NotSeededInBughunt;
}
```

```php
// tests/Support/Bughunt/BughuntSeedWiringInventory.php (抜粋)
/**
 * database/seeders の全 seeder の区分目録 (deny-by-default・母集団は exact-fit)。
 *
 * 母集団を全 seeder に取るのは「登録しなければ検査対象から外れる」抜け道を作らないためで、
 * bug-hunt に関係しない seeder の区分は 1 行で終わる。
 *
 * @return array<class-string, array{
 *     role: BughuntSeedRole,
 *     reason: string,
 *     guardPremiseTest: non-empty-string|null,
 * }>
 */
public static function entries(): array
{
    return [
        BughuntBillingSeeder::class => [
            'role' => BughuntSeedRole::BughuntOnly,
            'reason' => '有料プラン組織へ購読とチケットを投入する。通常経路に載せると開発 DB へ課金状態が漏れる。',
            // ガードの論理 (かつ / または) を実際に動かして固定している振る舞いテスト
            'guardPremiseTest' => 'tests/Feature/Database/BughuntBillingSeederTest.php',
        ],
        BughuntOAuthSeeder::class => [/* BughuntOnly */],
        AdminUserSeeder::class => [/* SharedWithBughunt */],
        ManualTestSeeder::class => [/* ManualFixture */],
        RoleSeeder::class => [/* NotSeededInBughunt */],
        // … 残りの通常 seeder
    ];
}

/** bug-hunt レーンで明示投入する区分 (provision / reseed の列と集合一致させる相手) */
public static function seededInBughunt(): array; // BughuntOnly ∪ SharedWithBughunt ∪ ManualFixture

/** 区分ごとに run() の最初の実効文へ要求する判定語 */
public static function requiredGuardMarkers(BughuntSeedRole $role): array;
// BughuntOnly       => ['EXTERNALS_FLAG', "environment('bughunt.local')", 'isBughuntDatabase']
// SharedWithBughunt => ['shouldSeed']
// それ以外          => [] (要求しない)

// ★前提テストの紐づけは **entry のフィールド** (`guardPremiseTest`) として持つ。
//   別 mapping にすると「キー集合がガードを要求する区分と一致するか」を別途検査する必要があり、
//   目録が 2 つに割れる。
//
//   静的走査は「判定語が条件に現れること」までしか見られず、`||` と `&&` の取り違えのような
//   論理の退行は読めない。そこでガードを要求する区分の entry には、その論理を実際に
//   動かして固定しているテストを**必ず紐づける** (免除の前提を振る舞いで固定する
//   `ThrottleExemptionPremiseTest` / `IdempotencyExemptionPremiseTest` と同じ作法)。
//   ガードを要求しない区分では **null 固定** (値があったら赤)。
```

```php
// tests/Support/Bughunt/ShellFunctionWindow.php (抜粋)
/**
 * `cmd_*` 関数の窓を切り出す (**`cmd_` で始まる関数専用**)。
 *
 * 終端を「次の `^cmd_` 定義 (または末尾)」に取るため、`cmd_` 以外の関数へ使うと
 * 後続の関数を巻き込む。誤用を防ぐため、名前が `cmd_` で始まらなければ例外にする。
 * 非貪欲な `\n\}` 終端は使わない (ヒアドキュメント内の行頭 `}` で早く止まるため)。
 * 見つからないときも例外にする (静かに空文字を返して緑にしない)。
 */
public static function ofCommand(string $source, string $commandFunction): string;
```

`cmd_` 以外の関数 (`require_orchestrator` など) の窓は、既存テストが持つ
「行頭 `}` で終端する」別の切り出しをそのまま使う (2 つの切り出しは目的が違うので統合しない。
思考原則 4)。

検査項目 (`tests/Architecture/BughuntSeedWiringInvariantTest.php`):

| # | 固定する不変条件 |
|---|---|
| S-1 | 目録のキー集合が `database/seeders/` の Seeder クラス集合と**過不足なく一致**する |
| S-2 | 各 entry の理由が 30 文字以上である |
| S-3 | `cmd_provision` と `cmd_reseed` から抽出した `db:seed --class=…` の**列が順序込みで一致**する |
| S-4 | その列の集合が目録の「bug-hunt レーンで明示投入する」区分と**過不足なく一致**する |
| S-5 | `BughuntOnly` 区分は `DatabaseSeeder` の呼び出し列に**現れない** |
| S-6 | `BughuntOnly` / `SharedWithBughunt` は `run()` の**最初の実効文が `if` 文**であり、その条件に区分ごとの判定語がすべて現れる |
| S-7 | fail-closed: 抽出した列が空・目録が空なら赤くなる |
| S-8 | 負のコントロール: 合成のスクリプト断片 (reseed から 1 行落とす / 並びを入れ替える) と合成の seeder ソース (ガードの前に 1 文入れる / ガードの中に早期 return が無い) を検出する |
| S-9 | ガードを要求する区分の entry は `guardPremiseTest` を持ち、(a) そのファイルが**実在**し、(b) パスが `tests/Feature/` 配下であり、(c) **そのテストのソースが対象 seeder クラスを参照している**こと。ガードを要求しない区分は `null` であること |
| S-10 | 負のコントロール: `guardPremiseTest` を「実在するが対象 seeder を参照しない別のテスト」へ差し替えると S-9 が赤くなる |

- **S-6 の保証範囲を誇張しない**: 見るのは「最初の実効文が `if` で、条件に必要な判定語が
  すべて現れ、その本体に早期 `return` があること」までで、条件の論理 (かつ / または) までは
  見ない。現行の 2 本はいずれも「否定の論理和 → 早期 return」の形であり、
  `&&` を要求する検査は**誤り**になる (だから書かない)。
  論理そのものは既存の振る舞いテスト
  (`tests/Feature/Database/BughuntBillingSeederTest.php` /
  `BughuntOAuthSeederGuardTest.php`) が固定する **二段防御**であり、
  その紐づけを目録に持たせる (S-9) ことで「前提テストが消えたら気づく」形にする

### PHPStan適合チェック

- [x] 目録は `array<class-string, array{role: BughuntSeedRole, reason: string, guardPremiseTest: non-empty-string|null}>` で型を明示
      (前提テストの紐づけは別 mapping にせず entry のフィールドに置く = 目録を 2 つに割らない)
- [x] enum は backed でない純粋な enum (値を持つ必要が無い)
- [x] `ShellFunctionWindow::ofCommand()` は `string` を返し、見つからなければ例外 (null 返しにしない)

### テスト計画

- [x] S-1〜S-10 を Pest の Architecture レーンで実行 (DB 不使用)
- [x] 既存 `BughuntOrchestratorGateInvariantTest` が共有クラスへ委譲した後も
      **全ケース緑**であること (関数窓の切り出し結果が変わっていないことの回帰)
- [x] `ofCommand()` に `cmd_` 以外の名前を渡すと例外になること (誤用の負のコントロール)
- [x] 受入は変異の 2 段確認: 実装前に「reseed から `BughuntOAuthSeeder` を落とす」
      「`BughuntBillingSeeder` を `DatabaseSeeder` に足す」「ガードの前に 1 文入れる」の
      3 通りが**素通りする**ことを記録し、実装後に**すべて赤くなる**ことを確かめる

### リスク

- **既存テストのヘルパを共有クラスへ動かすと、そのテストが壊れる**。
  → 切り出しの正規表現は 1 文字も変えずに移し、`expect()` による表明だけを例外へ置き換える。
  移設後に既存テスト全件が緑であることを確認する
- **投入の列に順序まで要求すると、正当な並べ替えでも赤くなる**。
  → 順序には意味がある (`ManualTestSeeder` が先に走らないと `BughuntOAuthSeeder` は
  代表ユーザーを見つけられず skip する)。**意図的に順序も固定する**ことをテストの
  コメントに書き、並べ替えたいときは 2 か所を同時に直させる
- **目録が全 seeder を持つため、seeder を足すたびに登録が要る**。
  → それが deny-by-default の狙いである (登録は 1 行 + 理由)

---

## 施策 3: 本番混入防止に実環境変数の二重判定を足す

### 変更箇所

- `app/Support/ProductionEnvGuard.php` (L89-L107 の 3 ブロックを 1 つのループへ置き換え + 実環境変数の判定を追加)

### 波及変更

- TypeScript 型定義 / API Resource/DTO: なし
- テストファイル: `tests/Feature/Support/ProductionEnvGuardTest.php` に実環境変数系のケースを追加

### 現行コード

```php
if (config('testing.fake_externals') === true) {
    $errors[] = 'TESTING_FAKE_EXTERNALS must be false in production (…)';
}
if (config('testing.fake_llm') === true) { /* 同型 */ }
if (config('testing.fake_storage') === true) { /* 同型 */ }
```

### 変更後コード

```php
// 偽の外部サービスのフラグは **設定値とプロセスの実環境変数の両方**を見る。
// 設定キャッシュを作った環境と出荷先が食い違うと、キャッシュ上は false でも
// キャッシュが失われた起動で環境変数が読み直され、本番で偽物が立ちうるため
// (route:cache が古いと保護が無音で外れるのと同じ形)。
foreach (ExternalFakeDeclaration::FLAG_ENVIRONMENT_VARIABLES as $flag => $variable) {
    if (config($flag) === true) {
        $errors[] = "{$variable} must be false in production (configuration value).";
    }

    // $_SERVER / $_ENV / getenv() を独立に見る (どれか 1 つでも危険側なら違反)。
    foreach ($this->rawEnvironmentValues($variable) as $source => $raw) {
        if (! $this->isUnambiguouslyDisabled($raw)) {
            $errors[] = "{$variable} must be false in production "
                ."(process environment via {$source}: ".var_export($raw, true).').';
        }
    }
}
```

- `rawEnvironmentValues(string $variable): array<string, mixed>`: 3 経路のうち
  **値が存在するものだけ**を (経路名 => 生の値) で返す (未設定は判定対象にしない)。
  `$_SERVER` / `$_ENV` は `mixed` を持ちうるので**型を絞らずそのまま返す**
  (絞って捨てると設定破損を見逃す)
- `isUnambiguouslyDisabled(mixed $raw): bool`: 文字列の空文字 / `false` / `(false)` /
  `0` / `off` / `no` / `null` / `(null)` を大文字小文字を無視して false と読む。
  **文字列でない値を含め、それ以外はすべて違反**(解釈できない値を安全側へ倒す)。
  メッセージ生成時だけ `var_export()` で文字列にする

### PHPStan適合チェック

- [x] `rawEnvironmentValues(string $variable): array<string, mixed>` /
      `isUnambiguouslyDisabled(mixed $raw): bool` を明示 (戻り値の型と扱いを食い違わせない)
- [x] `getenv()` の戻り値 (`string|false`) は「`false` = 未設定」として判定対象から外す
      (空文字とは区別する)
- [x] 非文字列は `is_string()` で判定して**違反**にする (黙って捨てない)

### テスト計画

- [x] 既存の設定値ケース (3 フラグ) は文言変更に追随して緑
- [x] 新規: `$_SERVER['TESTING_FAKE_EXTERNALS'] = 'true'` かつ config が false のとき違反が出る
      (3 経路それぞれで 1 ケースずつ = 3 ケース)
- [x] 新規: 未設定 (3 経路とも無し) では違反が出ない
- [x] 新規: `'false'` / `'0'` / `''` では違反が出ない
- [x] 新規: `'maybe'` / 非文字列 (配列) では**違反が出る** (解釈できない値は安全側)
- [x] 新規: **未設定 / 空文字 / `'false'` を別ケースとして固定する**
      (`putenv()` は空文字と未設定の差が環境で揺れるため、`$_SERVER` / `$_ENV` は
      `unset()` と `= ''` を明示的に作り分ける)
- [x] 原値の退避と復元は 1 つのヘルパへ集約し、すべてのケースが `try/finally` で戻す
      (`getenv()` 側は `putenv("{$name}")` で未設定へ戻す)

### リスク

- **開発者の手元シェルに `TESTING_FAKE_*` が残っていると、production 検査が
  意図せず赤くなる**。→ 検査が走るのは production 起動時と `production:preflight` だけで、
  開発時の通常操作には影響しない。テスト側は必ず原値を復元する
- **`getenv()` はスレッド安全でない環境がある**。→ 読み取りのみで書き換えないため影響しない
  (テストで書くときは `putenv()` を finally で戻す)

---

## 施策 4: 別プロセスで差し替えを実測する

### 変更箇所

- 新設: `tests/Support/ExternalFakes/fake-wiring-probe.php` (観測用スクリプト)
- 新設: `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` (子プロセスの起こし方)
- 新設: `tests/Architecture/ExternalFakeBootProbeTest.php`

### 波及変更

- TypeScript 型定義 / API Resource/DTO: なし
- テストファイル: 上記 3 本。**アプリ側のコードは変えない**

### 変更後コード

```php
// tests/Support/ExternalFakes/fake-wiring-probe.php (抜粋)
// 実際の起動 (別プロセス・遅延読み込み provider・設定キャッシュの有無) の下で
// 差し替えが効いているかを観測して JSON を書き出す。
// ★責務は 4 つだけ: DB へ接続しない / container から解決する /
//   転送先 URL を組み立てて読む / 終了コードを返す。
//   HTTP サーバもブラウザも起動しない。
// ★禁止する文 (echo) を使わないため fwrite(STDOUT, …) で書く。

$app = require __DIR__.'/../../../bootstrap/app.php';

try {
    // ★読み込む環境ファイルを**専用の一時ファイルだけ**に固定する (親のチェックアウトの
    //   .env / .env.bughunt.local を読ませない = 実資格情報が子の設定へ入らない)。
    //   置き場所とファイル名は起動側が環境変数で渡す。
    //   getenv() は string|false を返すため、使う前に必ず絞る (PHPStan level 10)。
    // ★**Dotenv を読む前に**、子が実際に受け取ったプロセス環境を観測する。
    //   起動側が組み立てた配列を検査しても `env -i` を外した退行は映らない
    //   (組み立ては同じまま、親の環境だけが流れ込むため)。観測できるのは子だけである。
    $initialProcessEnvironment = getenv();
    Assert::isArray($initialProcessEnvironment);
    $processEnvironmentKeys = array_keys($initialProcessEnvironment);
    sort($processEnvironmentKeys);

    $environmentDirectory = getenv('FAKE_WIRING_PROBE_ENV_DIR');
    $environmentFile = getenv('FAKE_WIRING_PROBE_ENV_FILE');
    Assert::stringNotEmpty($environmentDirectory);
    Assert::stringNotEmpty($environmentFile);

    $app->useEnvironmentPath($environmentDirectory);
    $app->loadEnvironmentFrom($environmentFile);

    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $resolved = [];
    foreach (ExternalFakeDeclaration::swaps() as $swap) {
        $resolved[$swap->abstract] = $app->make($swap->abstract)::class;
    }

    // 外部ログインは「解決したクラス名」だけでは足りない。転送先が実際に自ホストへ
    // 閉じているかまで見る (クラス名が合っていても転送先を戻す退行を緑で通すため)。
    // ★転送先の組み立ては**偽物が有効なときだけ**行う。無効なときに呼ぶと
    //   本物の身元確認サービス向けの URL を組み立てることになり、観測の目的から外れる
    //   (どちらの場合も HTTP は出ないが、実資格情報を子プロセスへ渡さない前提を保つ)。
    $redirectHost = null;
    if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) === true) {
        // 観測する外部ログインの種類は設定から取る (名前を写経しない)。
        // 空・非文字列は観測不能として例外にする (静かに観測を飛ばして緑にしない)。
        $providers = config('template.social_providers');
        Assert::isArray($providers);
        $provider = array_key_first($providers);   // 現行の shape は 種類名 => 設定 の連想配列
        Assert::stringNotEmpty($provider);

        $redirectHost = parse_url(
            $app->make(SocialiteDriverResolver::class)->driver($provider)->redirect()->getTargetUrl(),
            PHP_URL_HOST
        );
    }

    fwrite(STDOUT, json_encode([
        'resolved' => $resolved,
        'redirect_host' => $redirectHost,
        'process_environment_keys' => $processEnvironmentKeys,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));
    exit(1);
}
```

```php
// tests/Support/ExternalFakes/FakeWiringProbeRunner.php (抜粋)
/**
 * 観測用スクリプトを子プロセスで走らせる。
 *
 * 子の環境は**完全に作り直す** (親から引き継がない)。決め方は 3 段:
 * 1. プロセスの環境変数は `env -i` で空にしてから、必要な分だけを渡す
 *    (親のシェルに残った TESTING_FAKE_* に結果を左右されない。
 *     bug-hunt のスクリプトが DB 資格情報を遮断するときと同じ手である)
 * 2. 設定の出所は**専用の一時環境ファイル 1 つだけ**にする
 *    (`FAKE_WIRING_PROBE_ENV_DIR` / `…_FILE` で子へ渡し、子が
 *     `useEnvironmentPath()` / `loadEnvironmentFrom()` で固定する)。
 *     親のチェックアウトの `.env` / `.env.bughunt.local` は**読ませない**
 *     = 実 Stripe / 外部ログイン / S3 の資格情報は子の設定に 1 つも入らない
 * 3. 設定キャッシュを無効化する。`APP_CONFIG_CACHE` を**存在しない一時パス**へ向け、
 *    キャッシュ無しの起動として観測する (共有の bootstrap/cache を作ったり消したりしない =
 *    並列実行と衝突しない)
 *
 * ★**親の実鍵を複写しない**。`APP_KEY` / `CIPHERSWEET_KEY` は起動のたびに
 *   **使い捨ての値をその場で生成する** (観測は解決とルーティングだけで、既存データの
 *   復号も DB 接続もしないため実鍵は要らない)。これで一時ファイルは秘密を 1 つも持たない。
 *   生成形式は現行の設定が受理する形に合わせる —
 *   `APP_KEY` は `'base64:'.base64_encode(random_bytes(32))`、
 *   `CIPHERSWEET_KEY` は `bin2hex(random_bytes(32))` (文字列鍵の提供元がそのまま読む 64 文字)。
 *   `random_bytes()` の失敗は握り潰さず、子を起こす前に失敗させる。
 *   **形式が妥当であることは「子が起動できたこと」自体が示す** (起動できなければ赤)。
 * ★それでも置き場所は保護する: 専用の一時ディレクトリを `0700` で作り、
 *   環境ファイルは作成時点から `0600` にし、symlink を追わない方法で書く。
 *   起動前に権限を確かめ、`0600` でなければ**子を起こさずに失敗させる**。
 *   後片付けは `finally` で行い、timeout・JSON の解釈失敗・Process の例外でも必ず通る。
 *
 * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
 * キャッシュ有りの起動は観測しない (キャッシュが古いときの挙動は本観測の範囲外で、
 * 本番混入防止は施策 3 の二重判定が受け持つ)。
 *
 * @return array{exitCode: int, output: array<string, mixed>}
 */
public static function run(string $environment, bool $fakeExternals, bool $fakeStorage, bool $fakeLlm): array;

/**
 * 一時環境ファイルに書いてよいキー (deny-by-default)。
 * 実資格情報のキーは 1 つも無く、鍵の 2 つは使い捨ての生成値である。
 */
public const array ALLOWED_ENV_FILE_KEYS = [
    'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'CIPHERSWEET_KEY',
    'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
];

/**
 * 子プロセスへ渡してよい**プロセス環境変数**のキー (上とは別物なので定数を分ける)。
 * `env -i` で空にしたうえでこの 3 つだけを載せる。
 *
 * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
 *   probe が自分で観測して返す (P-7)。両方を突き合わせて初めて `env -i` の退行が映る。
 */
public const array ALLOWED_PROCESS_ENV_KEYS = [
    'FAKE_WIRING_PROBE_ENV_DIR',
    'FAKE_WIRING_PROBE_ENV_FILE',
    // 設定キャッシュを無効化する (存在しない絶対パスを一時ディレクトリ配下に指す)
    'APP_CONFIG_CACHE',
];
```

観測点 (`tests/Architecture/ExternalFakeBootProbeTest.php`):

| # | 観測 |
|---|---|
| P-1 | `bughunt.local` + フラグ有効 → 宣言集合の全件が**偽物のクラスで厳密一致**する |
| P-2 | 同上で外部ログインの転送先ホストが**自ホスト**である (実 IdP のホストでない) |
| P-3 | 対照: `bughunt.local` + フラグ無効 → 全件が**本物のクラスで厳密一致**する (転送先は観測しない) |
| P-4 | 対照: `production` + フラグ有効 → **非ゼロ終了**し、出力に `TESTING_FAKE_EXTERNALS` が現れる |
| P-5 | fail-closed: 観測結果が空・宣言集合が空なら赤くなる |
| P-6 | 起動側が作る一時環境ファイルのキー集合が `ALLOWED_ENV_FILE_KEYS` の**部分集合**であること (実資格情報を子へ渡す変更をその場で落とす) |
| P-7 | **子が実際に受け取ったプロセス環境**のキー集合を probe が Dotenv 読み込み前に観測して返し、(a) `ALLOWED_PROCESS_ENV_KEYS` の 3 件がすべて存在し、(b) 禁止する接頭辞 (`DB_` / `PG` / `AWS_` / `STRIPE_` / `TESTING_FAKE_` / `GOOGLE_`) が **0 件**で、(c) それ以外の余りが**無い**こと (実行環境が足すキーがあるなら、理由付きで目録へ登録して初めて許す = deny-by-default) |
| P-8 | 一時環境ファイルの `APP_KEY` / `CIPHERSWEET_KEY` が**親の設定値と異なる**こと (実鍵の複写に戻したら赤くなる) |
| P-9 | 一時ディレクトリが `0700`・環境ファイルが `0600` であること。権限が違えば子を起こさずに失敗すること |
| P-10 | 正常終了・非ゼロ終了・timeout の**いずれでも**一時ディレクトリが残らないこと |
| P-11 | `APP_CONFIG_CACHE` が絶対パスで、一時ディレクトリ配下を指し、そのファイルが**存在しない**こと |

- P-4 が意図どおり効く根拠: `AppServiceProvider::boot()` は
  `ProductionEnvGuard::enforce()` を**最初に**呼ぶため、他の起動時検査より先に
  この違反が出る。P-4 は 2 段で表明する — (a) **非ゼロ終了**すること (順序に依存しない)、
  (b) 出力に `TESTING_FAKE_EXTERNALS` が現れること (順序に依存する)。
  (b) が落ちたら「起動時検査の順序が変わった可能性」を失敗メッセージに書く
  (依存を隠さず、赤で気づける形にする)
- **子プロセスへ実際の外部資格情報を渡さない**。プロセスの環境変数は `env -i` で空にし、
  設定は専用の一時環境ファイル 1 つだけから読む。書いてよいキーは 7 つで、
  そこに外部サービスの資格情報は 1 つも無い (P-6 / P-7 が 2 つの集合を別々に固定する)。
  本物側の解決に資格情報は要らない (現行の `CashierStripeGateway` はコンストラクタで
  Stripe 資格情報を受け取らない)
- **親の実鍵も渡さない**。`APP_KEY` / `CIPHERSWEET_KEY` は使い捨ての値を生成する
  (P-8 が「親の値と異なること」を固定する)。一時ディレクトリは `0700`、
  環境ファイルは `0600` で作り、`finally` で必ず消す (P-9 / P-10)

### PHPStan適合チェック

- [x] `FakeWiringProbeRunner::run()` の戻り値を `array{exitCode: int, output: array<string, mixed>}` で明示
- [x] `json_decode()` は `JSON_THROW_ON_ERROR` + `is_array()` で絞る
- [x] `getenv()` の戻り値 (`string|false`) は `Assert::stringNotEmpty()` で絞ってから使う
      (`useEnvironmentPath()` / `loadEnvironmentFrom()` へ直接渡さない)
- [x] 引数なしの `getenv()` は `array<string, string>` を返す。`Assert::isArray()` で絞り、
      キーだけを取り出して JSON へ載せる (**値は載せない** = 万一の混入を出力へ流さない)
- [x] 観測用スクリプトは PHPStan の解析対象に入る。`$app` の型は
      `Illuminate\Foundation\Application` として扱えるよう `Assert::isInstanceOf()` を使う

### テスト計画

- [x] P-1〜P-11 を Architecture レーン (DB 不使用) で実行
- [x] 実行時間の上限を明示的に設定し (`Process` の timeout)、超えたら赤にする
      (ぶら下がりを緑で見逃さない)
- [x] 受入は変異の 2 段確認: 「宣言から SSO の entry を消す」
      「偽の外部ログインの転送先を実 IdP に戻す」
      「一時環境ファイルへ `STRIPE_SECRET` を足す」
      「鍵を親の設定値の複写に戻す」
      「起動コマンドから `env -i` を外す」の 5 通りで**赤くなる**ことを確かめる
      (最後の 1 つは P-7 が子側の観測で受ける)

### リスク

- **子プロセスの起動は環境差で壊れやすい**。→ 責務を 4 つに限定し、
  DB・HTTP サーバ・ブラウザに触れない。必要な環境変数は親から明示的に渡す
- **`.env` が全く無い環境で起動できない可能性**。→ 子は親の `.env` を読まない設計で、
  起動に要る `APP_KEY` / `CIPHERSWEET_KEY` は使い捨ての値を生成して一時環境ファイルへ書く
  (親のチェックアウトの状態に依存しない)。それでも起動できない場合は**skip せず赤にする**
  (静かに検査が消える形を作らない)
- **一時ファイルが新しい秘密の漏れ口になる**。→ 秘密を**そもそも書かない** (使い捨ての鍵)。
  そのうえで `0700` / `0600` と `finally` での削除を持ち、権限が違えば子を起こさない
- **設定キャッシュがある状態を観測できない**。→ 観測対象を「キャッシュ無しの起動」に
  限ると明記する (共有の `bootstrap/cache/config.php` を作ったり消したりすると
  並列実行と衝突するため、あえて作らない)。キャッシュが古いときの本番事故は
  施策 3 の二重判定が受け持つ
- **`google` provider が宣言から消えると P-2 が落ちる**。→ 転送先を見る provider は
  `config('template.social_providers')` の先頭から取る (名前を写経しない)
- **P-3 の「本物と厳密一致」は本物側の登録に依存する**。→ 依存するのは
  `AppServiceProvider::register()` の無条件 bind 3 本 (`TicketCheckoutGateway` /
  `StripeGatewayInterface` / `AutoRechargeGatewayInterface`) と、残り 4 件の
  具象クラス (自動組み立て) だけである。**同じ表明は in-process の 3-1 が既に緑で持っている**
  ため、別プロセスで落ちたら「別プロセスでだけ本物が解決できない」という本物の信号である
  (脆さではない)。解決が例外になった場合も probe が理由を返して赤くなる (静かに緑にしない)

---

## 施策 5: skill-bug-hunt に実作業が無いことの明記

### 変更箇所

- `docs/architecture.md` の §外部到達点の目録 (標準形 v1) の末尾に 1 段落

### 波及変更

なし (文書のみ)

### 変更後コード

> **bug-hunt の手順書 (`.claude/skills/app-bug-hunt/`) 側に投入データの検査は置かない。**
> 手順書が守るのは走行の型 (禁止事項・走る順・異常の見分け方) であり、
> 「どの投入データがどの入口に配線されているか」は実行時の配線の関心事である。
> 配線の検査は `tests/Architecture/BughuntSeedWiringInvariantTest.php` が持つ。

### PHPStan適合チェック

- 該当なし (文書のみ)

### テスト計画

- [x] `docs/` の記述に対応する機械検査は施策 2 (`BughuntSeedWiringInvariantTest`) が持つ
      (文書だけを足して終わりにしない)。文書側に専用の機械検査は**足さない**

### リスク

- **「作業が無い」と書いたことが後で覆る**。→ 覆るのは手順書の骨格側に
  新しい要求が現れたときであり、その場合は台帳の boundary から議論を起こす
  (本設計はその判断を先取りしない)

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 施策 1 が `app/Providers/FakeExternalsServiceProvider.php` / `app/Support/FakeStorageGate.php` / `config/testing.php` と、外部 fake 系の Architecture テスト 4 本を同時に書き換える。分割すると「宣言はあるが provider がまだ読んでいない」中間状態が生まれ、走査器の許可形の変更と噛み合わずレーンが赤いまま止まる。施策 2〜4 は施策 1 が確定した宣言クラスを読むため、同じ worktree で順に積むのが最短である |
| 競合リスク | `config/testing.php` と `app/Support/ProductionEnvGuard.php` は他の TODO も触る可能性がある (前者は fake フラグ、後者は本番検査項目の追加)。マージ前に main を取り込んで両ファイルの差分を確認すること。`scripts/bug-hunt-shard.sh` は**読むだけ**で変更しない |

## 実装順序 (推奨)

1. 施策 1 の宣言クラスと値オブジェクトを置き、provider / gate / seeder / config を寄せ替える
   (この時点で既存の振る舞いテストが緑であることを確認 = 挙動不変の証拠)
2. 走査器の許可形を変え、自己検査 (`FakeWiringSourceScannerTest`) を先に緑にする
   (**走査器が壊れると gate は静かに無力化する**ため、走査器の検査を先に通す。T119 と同じ規律)
3. 施策 1 の Architecture テストを更新 (3-8 削除 / 3-10 強化 / 3-13〜3-16 追加)
4. 施策 1c → 施策 2 → 施策 3 → 施策 4 の順に積む
5. 施策 5 の文書を書き、`mutation-evidence.md` に変異の 2 段確認の記録を残す

---

## 実装時に判明した設計の訂正 (T177 実装セッション)

実装中に HEAD の現物と食い違った点を、設計側を直したうえで実装した。

1. **3-10「provider は fake クラスを 1 つも参照しない」は成立しない**。
   provider は差し替え先の決定を宣言へ移した後も、配線基盤として
   `FakeStorageGate` / `CannedPromptFakeRegistrar` / 偽の保存先の署名付き経路の受け口 2 本を
   参照し続ける (どれも container 差し替えを行わないクラスで、母集団の定義上は
   「偽物系クラス」に入る)。したがって 3-10 は
   **「配線基盤 4 件ちょうど、かつ差し替え先を 1 件も含まない」**に改めた。
   後半の表明が「決定は宣言側にしかない」ことの機械的な裏付けになる。

2. **参照 allowlist (4-4) から provider は外せない**。上と同じ理由。
   allowlist は宣言クラスが増えて **5 件 → 6 件**になる (provider は残置)。

3. **`providerReferenceExceptions()` は宣言クラス (app/) ではなくテスト側の定数に置いた**。
   これは「gate が期待する参照集合」であって本番の配線が読む値ではないため、
   本番コードへ持ち込むと宣言クラスがテスト固有の関心事を抱える。
   先例 (`FakeClassReferenceInvariantTest` の `FAKE_REFERENCE_ALLOWED`) と同じ置き方にした。

4. **施策 1c の「現在の `tests/` 全走査で違反 0 件」は誤りだった**。
   実測では `tests/Feature/Billing/` の 5 ファイル・8 箇所が
   `$this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class)` を直接書いていた。
   設計の方針 (例外の登録簿を作らない) は維持し、**レーン側を単一入口へ寄せ替える**ことで解いた —
   `tests/Pest.php` に `enableFakeExternals()` を新設し (既存の `enableFakeStorage()` と同じ形で
   provider を実走させる)、8 箇所をそれに置き換えた。正典 v1 の「差し替え処理を 1 本に集約し、
   全レーンがそれを共有する」に沿う形である。

5. **観測用スクリプトは PHPStan の解析対象ではない**。`phpstan.neon` の `paths` は
   `app` / `config` / `database` / `routes` で `tests` を含まない。型は同じ規律で書くが、
   「PHPStan が解析する」という設計の記述は事実と違うので訂正する。

6. **一時環境ファイルの許可キーに `APP_URL` を足した (7 → 8 件)**。
   外部ログインの転送先ホストを照合するとき、`APP_URL` を渡さないと子の既定値
   (`http://localhost`) に依存してしまい、照合の期待値を写経することになる。
   起動側が値を決めて渡し、`FakeWiringProbeRunner::probeAppHost()` から期待値を導く形にした
   (実サーバは立てない。経路の組み立てにだけ使う)。

7. **P-9 / P-10 を実際に検査できる形へ寄せた**。権限判定を
   `FakeWiringProbeRunner::assertSafePermissions()` へ切り出し、緩い権限で例外になることを
   直接固定する。P-10 の timeout 分は一時ディレクトリの**親**を引数で渡せるようにして、
   timeout 例外の後に親が空であることを観測する。

8. **S-5 は「`DatabaseSeeder` のソースに bug-hunt 専用 seeder の名前が現れないこと」で検査する**。
   呼び出し列を構文解析するより字面の方が簡単で、通常経路へ載せる書き方は必ずクラス名を書くため
   目的 (事故 2 の阻止) は達成できる。保証範囲はテストのコメントに明記した。

9. **`ShellFunctionWindow` の誤用と不在を S-11 として固定した** (`cmd_` 以外の名前は
   `InvalidArgumentException`、見つからなければ `RuntimeException`)。

10. **施策 5 は「1 段落追加」ではなく該当節の書き換えになった**。
    `docs/architecture.md` の §外部 fake 配線の不変条件 は旧 inventory (テスト側) を
    正本として説明していたため、1 段落足すだけでは節全体が誤りのまま残る。
    節を宣言集合の形へ書き換え、投入データの配線と手順書に作業が無いことを併記した。
    併せて `AGENTS.md` / `docs/architecture.md` に残っていた旧クラス名の参照 4 箇所を直した。

---

## 実装差分 (git diff)

```diff
diff --git a/app/Providers/FakeExternalsServiceProvider.php b/app/Providers/FakeExternalsServiceProvider.php
index 5bb1908..9de0530 100644
--- a/app/Providers/FakeExternalsServiceProvider.php
+++ b/app/Providers/FakeExternalsServiceProvider.php
@@ -7,154 +7,106 @@
 use App\Http\Controllers\Testing\GetFakeStorageObjectController;
 use App\Http\Controllers\Testing\PutFakeStorageObjectController;
 use App\Services\AI\Testing\CannedPromptFakeRegistrar;
-use App\Services\Auth\Fakes\FakeSocialiteDriverResolver;
-use App\Services\Auth\SocialiteDriverResolver;
-use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
-use App\Services\Billing\Contracts\StripeGatewayInterface;
-use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
-use App\Services\Billing\Fakes\FakeStripeGateway;
-use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
-use App\Services\Billing\TicketCheckoutGateway;
-use App\Services\Captcha\RecaptchaVerifier;
-use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
-use App\Services\Capture\Fakes\FakeTakeObjectStorage;
-use App\Services\Capture\TakeObjectStorage;
-use App\Services\Render\Fakes\FakeRenderObjectStorage;
-use App\Services\Render\RenderObjectStorage;
+use App\Support\ExternalFakes\ExternalFakeDeclaration;
 use App\Support\FakeStorageGate;
 use Illuminate\Support\Facades\Log;
 use Illuminate\Support\Facades\Route;
 use Illuminate\Support\ServiceProvider;
 
 /**
- * 外部サービス fake の配線 (系統別に capability flag を分離)。
+ * 偽の外部サービスの配線 (差し替え先の決定は本ファイルに 1 つも無い)。
+ *
+ * 「何をどの偽物へ差し替えるか」の正本は App\Support\ExternalFakes\ExternalFakeDeclaration で、
+ * 本 provider はその宣言を 1 本の経路で適用するだけである。
  *
  * bootstrap/providers.php で AppServiceProvider より後に登録する (後勝ち rebind)。
  * fail-secure 二軸:
- * 1. flag === true (既定 false = 完全 no-op)
+ * 1. capability flag === true (既定 false = 完全 no-op)
  * 2. 環境 allowlist。denylist (非 production) ではなく allowlist で倒す = staging 等の
- *    未知環境で flag が誤設定されても fake しない (warning ログで検出可能にする)。
- *    production は加えて ProductionEnvGuard が flag=true を deploy 時 fail-fast で拒否する。
+ *    未知環境で flag が誤設定されても偽物を立てない (warning ログで検出可能にする)。
+ *    production は加えて ProductionEnvGuard が flag=true を起動時 fail-fast で拒否する。
  *
- * fake 対象は 2 系統で capability flag も allowlist も異なる:
- * - 外部サービス (Stripe 課金 gateway + captcha 検証器 + SSO driver 解決点):
- *   config('testing.fake_externals') が capability flag。container bind (per-test 隔離が効くため
- *   testing 可)。register() で配線。
- *   **SSO (Socialite) だけは env allowlist が狭い** (SSO_FAKE_ENVIRONMENTS。**local を除く**)。
- *   docs/architecture.md §外部到達点の目録 (標準形 v1) を参照。
- * - LLM (Prism): config('testing.fake_llm') が capability flag (fake_externals から分離)。
- *   Prompt::$fake は static (プロセスグローバル) のため testing/local を除外し bughunt.local のみ配線。
- *   bughunt 既定は real-llm (fake_llm off) で install しない。--fake-llm 時のみ install する。
- *   LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
+ * capability は 3 つで許可環境も判定も異なる (すべて宣言側が正本):
+ * - 外部サービス (決済 gateway + 人間性確認 + 外部ログインの解決点): EXTERNALS_FLAG。
+ *   container 差し替えのため register() で配線する。**外部ログインだけ許可環境が狭い**。
+ * - 保存先 (S3): STORAGE_FLAG。有効化条件は FakeStorageGate に一元化する
+ *   (経路登録と実行時判定を完全一致させるため。経路キャッシュ残存で素通りしないようにする)。
+ * - LLM (Prism): LLM_FLAG。Prompt::$fake はプロセス大域の static のため container 差し替えではなく
+ *   boot() で install する (宣言の swaps() には現れない)。
  */
 class FakeExternalsServiceProvider extends ServiceProvider
 {
-    /**
-     * 外部サービス fake を許可する環境 allowlist (container bind。per-test 隔離が効くため testing 可)。
-     *
-     * ★対象は **Stripe 課金 gateway と captcha 検証器**。SSO (Socialite) は同じ capability flag を
-     *   使うが env allowlist は別 (SSO_FAKE_ENVIRONMENTS。docs/architecture.md §外部到達点の目録)。
-     */
-    private const array EXTERNAL_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];
-
-    /**
-     * SSO (Socialite) fake を許可する環境 allowlist。
-     *
-     * ★`EXTERNAL_FAKE_ENVIRONMENTS` と**別定数にする** (値が同じでも概念が違う。
-     *   思考原則 4「別物の概念を似ているからで統合しない」)。
-     * ★`local` を意図的に除外する。SSO fake は未認証 GET 2 本
-     *   (`/auth/{p}/redirect/login` → `/auth/{p}/callback`) で canned アカウントへ
-     *   ログインできる = **認証バイパス**であり、かつ `local` は開発者が
-     *   実 IdP 連携を確認する唯一の環境である (無言で fake が立つと本番 SSO の回帰を見逃す)。
-     */
-    private const array SSO_FAKE_ENVIRONMENTS = ['testing', 'bughunt.local'];
-
-    /** LLM (Prism) fake の install を許可する環境 allowlist (Prompt::$fake は static。testing/local を除外) */
-    private const array LLM_FAKE_ENVIRONMENTS = ['bughunt.local'];
-
     public function register(): void
     {
-        // capability ごとに独立 private method へ分離する (early return が他 capability を巻き込まない)。
-        $this->registerExternalServiceFakes(); // Stripe + captcha: fake_externals 依存 (挙動不変)
-        $this->registerSocialAuthFake();       // SSO: fake_externals 依存 / env allowlist は別
-        $this->registerStorageFakes();         // storage: fake_storage (FakeStorageGate) 依存 — 独立
+        $this->installDeclaredSwaps();
     }
 
     public function boot(): void
     {
-        $this->bootLlmFake();       // LLM: fake_llm 依存 (挙動不変)
-        $this->bootStorageRoutes(); // storage signed route — 独立
+        $this->bootLlmFake();       // LLM: LLM_FLAG 依存 (container 差し替えではない)
+        $this->bootStorageRoutes(); // 偽の保存先の署名付き経路 — 独立
     }
 
-    /** 外部サービス fake (fake_externals + EXTERNAL_FAKE_ENVIRONMENTS。挙動不変) */
-    private function registerExternalServiceFakes(): void
+    /**
+     * 宣言集合を 1 本の経路で差し替える (bind 対象の決定は宣言側にしか無い)。
+     */
+    private function installDeclaredSwaps(): void
     {
-        if (config('testing.fake_externals') !== true) {
-            return;
-        }
-
         $environment = $this->app->environment();
-        if (! in_array($environment, self::EXTERNAL_FAKE_ENVIRONMENTS, true)) {
-            Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
-                'environment' => $environment,
-            ]);
+        $this->warnIfExternalsFlagIsUnusable($environment);
 
-            return;
-        }
+        // 保存先だけは「登録条件と実行時条件を完全一致させる」ため判定を gate に一元化している。
+        // ここでは 1 度だけ解決する。
+        $storageEnabled = $this->app->make(FakeStorageGate::class)->enabled();
+
+        foreach (ExternalFakeDeclaration::swaps() as $swap) {
+            $enabled = $swap->flag === ExternalFakeDeclaration::STORAGE_FLAG
+                ? $storageEnabled
+                : config($swap->flag) === true
+                    && in_array($environment, $swap->allowedEnvironments, true);
 
-        // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
-        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
-        $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
-        $this->app->bind(AutoRechargeGatewayInterface::class, FakeAutoRechargeGateway::class);
+            if (! $enabled) {
+                continue;
+            }
 
-        // captcha 到達点を fake へ rebind。
-        // ★abstract が具象クラスのため、bind を消しても Laravel が本物を自動組み立てし、
-        //   RECAPTCHA_SECRET_KEY が設定された瞬間に**無言で** Google siteverify を叩く。
-        //   StrayHttpRequestGuard は bug-hunt の別プロセス実行には効かない (AGENTS.md)。
-        $this->app->bind(RecaptchaVerifier::class, RecaptchaVerifierTestFake::class);
+            $this->app->bind($swap->abstract, $swap->fake);
+        }
     }
 
     /**
-     * SSO fake (fake_externals + SSO_FAKE_ENVIRONMENTS)。
+     * 外部サービスのフラグが立っているのに、その capability の許可環境の外にいるときだけ
+     * **1 度だけ**警告する (未知の環境で誤って有効化されたことを検出可能にするため)。
      *
-     * ★warning ログは出さない。`local` の除外は**誤設定ではなく設計上の除外**であり
-     *   (LLM fake と同じ理由)、ここで warning を出すと既存の
-     *   `3-4 provider 単体: 外部サービス fake flag on + allowlist 外 env は warning を出す`
-     *   が `once()` で固定している呼び出し回数を壊す。
+     * ★外部ログインだけ許可環境が狭いことについては**警告しない**。あれは誤設定ではなく
+     *   設計上の除外である (保存先 / LLM のフラグでも警告しないのと同じ)。
      */
-    private function registerSocialAuthFake(): void
+    private function warnIfExternalsFlagIsUnusable(string $environment): void
     {
-        if (config('testing.fake_externals') !== true) {
+        if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true) {
             return;
         }
 
-        if (! in_array($this->app->environment(), self::SSO_FAKE_ENVIRONMENTS, true)) {
+        if (in_array($environment, ExternalFakeDeclaration::EXTERNAL_ENVIRONMENTS, true)) {
             return;
         }
 
-        // SSO の driver 解決点を fake へ rebind。
-        // ★abstract が具象クラスのため、bind を消しても Laravel が本物を自動組み立てし、
-        //   **無言で**実 IdP (accounts.google.com 等) へのリダイレクトに戻る (captcha と同じ構図)。
-        // ★Socialite の Factory へ直接 bind しない: SocialiteServiceProvider は DeferrableProvider で、
-        //   最初の解決時に deferred provider が読み込まれ singleton(Factory) が後勝ちで fake を消す。
-        $this->app->bind(SocialiteDriverResolver::class, FakeSocialiteDriverResolver::class);
+        Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
+            'environment' => $environment,
+        ]);
     }
 
-    /** LLM (Prism) fake (fake_llm + LLM_FAKE_ENVIRONMENTS。挙動不変) */
+    /** LLM (Prism) の偽物 (LLM_FLAG + LLM_ENVIRONMENTS。挙動不変) */
     private function bootLlmFake(): void
     {
-        // LLM fake は fake_llm (既定 false = real LLM) で判定する。bughunt 既定は real-llm で、
-        // --fake-llm 指定時のみ TESTING_FAKE_LLM=true が注入され install される。
-        // Stripe fake (register) は従来どおり fake_externals 依存で不変。
-        if (config('testing.fake_llm') !== true) {
+        // bughunt 既定は実 LLM で、--fake-llm 指定時のみ TESTING_FAKE_LLM=true が注入される。
+        if (config(ExternalFakeDeclaration::LLM_FLAG) !== true) {
             return;
         }
 
-        // LLM fake は Prompt::$fake (プロセスグローバル static) を書き換えるため、
-        // per-test で static を占有する testing、実 API 検証を潰す local は allowlist から除外する。
-        // LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
-        // (Stripe と違い warning は出さない: testing/local の除外は誤設定ではなく設計上の除外)
-        if (! in_array($this->app->environment(), self::LLM_FAKE_ENVIRONMENTS, true)) {
+        // Prompt::$fake (プロセス大域の static) を書き換えるため、per-test で static を占有する
+        // testing と、実 API 検証を潰す local は許可環境から外す。
+        // (決済と違い warning は出さない: testing/local の除外は誤設定ではなく設計上の除外)
+        if (! in_array($this->app->environment(), ExternalFakeDeclaration::LLM_ENVIRONMENTS, true)) {
             return;
         }
 
@@ -162,21 +114,7 @@ private function bootLlmFake(): void
         $this->app->make(CannedPromptFakeRegistrar::class)->install();
     }
 
-    /**
-     * storage fake: FakeStorageGate 成立時のみ concrete → fake へ rebind (gate = predicate SSOT)。
-     * env allowlist / production 拒否は gate に一元化される。
-     */
-    private function registerStorageFakes(): void
-    {
-        if (! $this->app->make(FakeStorageGate::class)->enabled()) {
-            return;
-        }
-
-        $this->app->bind(TakeObjectStorage::class, FakeTakeObjectStorage::class);
-        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);
-    }
-
-    /** storage fake の signed route (gate 成立時のみ。web CSRF group 外 = signed のみ) */
+    /** 偽の保存先の署名付き経路 (gate 成立時のみ。web CSRF group 外 = signed のみ) */
     private function bootStorageRoutes(): void
     {
         if (! $this->app->make(FakeStorageGate::class)->enabled()) {
diff --git a/app/Services/Auth/SocialiteDriverResolver.php b/app/Services/Auth/SocialiteDriverResolver.php
index 124ff0f..649dd3f 100644
--- a/app/Services/Auth/SocialiteDriverResolver.php
+++ b/app/Services/Auth/SocialiteDriverResolver.php
@@ -13,7 +13,7 @@
  * ★本クラスが `ExternalSeamInventory::socialLoginFunnel()` の名指し先である。
  *   他クラスに `Socialite::driver()` を書くと `ExternalSeamInventoryTest` が赤くなる。
  * ★非本番 (testing / bughunt.local) では `FakeSocialiteDriverResolver` へ container bind
- *   される (`ExternalFakeWiringInventory`)。**差し替え点なので `final` にしない**。
+ *   される (`ExternalFakeDeclaration`)。**差し替え点なので `final` にしない**。
  * ★責務は driver の解決 1 つだけ。intent 分岐・user 変換・state 照合の無効化などを足さない
  *   (太らせるとサブクラス差し替えが崩れる。state 照合を殺す呼び出しの封鎖は
  *   `ThrottleExemptionPremiseTest` が本ファイルも走査して守る)。
diff --git a/tests/Support/ExternalFakes/ExternalFakeBinding.php b/app/Support/ExternalFakes/ExternalFakeBinding.php
similarity index 73%
rename from tests/Support/ExternalFakes/ExternalFakeBinding.php
rename to app/Support/ExternalFakes/ExternalFakeBinding.php
index 8eadcab..9d0518a 100644
--- a/tests/Support/ExternalFakes/ExternalFakeBinding.php
+++ b/app/Support/ExternalFakes/ExternalFakeBinding.php
@@ -2,13 +2,16 @@
 
 declare(strict_types=1);
 
-namespace Tests\Support\ExternalFakes;
+namespace App\Support\ExternalFakes;
 
 /**
- * container 差し替え 1 本の宣言 (fake 配線 gate の inventory 要素)。
+ * container 差し替え 1 本の宣言 (偽の外部サービスの宣言集合の要素)。
  *
- * 「宣言 (本ファイル)」と「実証 (ExternalFakeWiringInvariantTest)」を分離する。
+ * 「宣言 (ExternalFakeDeclaration)」と「実証 (ExternalFakeWiringInvariantTest)」を分離する。
  * 本クラスは値の器であり判定ロジックを持たない。
+ *
+ * ★本番の読み込み対象 (app/) に置く。宣言を読むのは provider・gate・seeder であり、
+ *   いずれも本番側のクラスだからである (テスト側にしか無いと本番の配線が宣言を読めない)。
  */
 final readonly class ExternalFakeBinding
 {
diff --git a/app/Support/ExternalFakes/ExternalFakeDeclaration.php b/app/Support/ExternalFakes/ExternalFakeDeclaration.php
new file mode 100644
index 0000000..f7e284f
--- /dev/null
+++ b/app/Support/ExternalFakes/ExternalFakeDeclaration.php
@@ -0,0 +1,228 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\ExternalFakes;
+
+use App\Services\Auth\Fakes\FakeSocialiteDriverResolver;
+use App\Services\Auth\SocialiteDriverResolver;
+use App\Services\Billing\CashierAutoRechargeGateway;
+use App\Services\Billing\CashierStripeGateway;
+use App\Services\Billing\CashierTicketCheckoutGateway;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
+use App\Services\Billing\Fakes\FakeStripeGateway;
+use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
+use App\Services\Billing\TicketCheckoutGateway;
+use App\Services\Captcha\RecaptchaVerifier;
+use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
+use App\Services\Capture\Fakes\FakeTakeObjectStorage;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Mail\Sns\SnsSignatureVerifier;
+use App\Services\Render\Fakes\FakeRenderObjectStorage;
+use App\Services\Render\RenderObjectStorage;
+use InvalidArgumentException;
+use Kent013\SsrfPin\UrlSafetyInspector;
+
+/**
+ * 「どの外部到達点を、どのフラグと許可環境で、どの偽の実装へ差し替えるか」の唯一の正本。
+ *
+ * ★本番の読み込み対象 (app/) に置く。差し替えの配線 (FakeExternalsServiceProvider)・
+ *   storage の有効化条件 (FakeStorageGate)・bug-hunt の投入データ (seeder)・
+ *   本番混入防止 (ProductionEnvGuard) が**すべてここだけを読む** (同じ集合を 2 か所に書かない)。
+ * ★本クラスは値を返すだけで判定を持たない。有効・無効の判定は
+ *   FakeExternalsServiceProvider (container 差し替え) と FakeStorageGate (storage) が行う。
+ *
+ * 関連する目録との責務境界:
+ * - 本番コードが偽の実装のクラス名を参照しないことの全走査は FakeClassReferenceInvariantTest
+ * - 外部到達点そのものの目録は ExternalSeamInventory
+ *   (同じ事実を 3 か所で宣言しない。AGENTS.md ドメイン規約 9)
+ */
+final class ExternalFakeDeclaration
+{
+    /** 外部サービス fake (決済 + 人間性確認 + 外部ログイン) の capability flag */
+    public const string EXTERNALS_FLAG = 'testing.fake_externals';
+
+    /** storage fake の capability flag */
+    public const string STORAGE_FLAG = 'testing.fake_storage';
+
+    /** LLM fake の capability flag (container 差し替えではないため swaps() には現れない) */
+    public const string LLM_FLAG = 'testing.fake_llm';
+
+    /**
+     * capability flag の config キー => 対応する環境変数名。
+     *
+     * 本番混入防止 (ProductionEnvGuard) と bug-hunt の環境ひな型検査が読む。
+     */
+    public const array FLAG_ENVIRONMENT_VARIABLES = [
+        self::EXTERNALS_FLAG => 'TESTING_FAKE_EXTERNALS',
+        self::STORAGE_FLAG => 'TESTING_FAKE_STORAGE',
+        self::LLM_FLAG => 'TESTING_FAKE_LLM',
+    ];
+
+    /**
+     * 外部サービス fake の許可環境 (capability 全体。個々の差し替えはこれ以下に絞れる)。
+     */
+    public const array EXTERNAL_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];
+
+    /**
+     * 外部ログインの差し替えだけ `local` を外す。
+     *
+     * 未認証 GET 2 本で canned アカウントに入れる = 認証バイパスであり、かつ `local` は
+     * 実 IdP 連携を確かめる唯一の環境である (無言で偽物が立つと本番 SSO の回帰を見逃す)。
+     */
+    public const array SSO_ENVIRONMENTS = ['testing', 'bughunt.local'];
+
+    /** storage fake の許可環境 (testing での追加条件は FakeStorageGate が持つ) */
+    public const array STORAGE_ENVIRONMENTS = ['testing', 'bughunt.local'];
+
+    /** LLM fake の許可環境 (Prompt::$fake はプロセス大域の static のため testing/local を外す) */
+    public const array LLM_ENVIRONMENTS = ['bughunt.local'];
+
+    /**
+     * capability flag ごとの許可環境。
+     *
+     * ★既定を例外にする (未知のフラグを黙って空集合へ倒さない)。空集合へ倒すと
+     *   「許可環境が 1 つも無い = どこでも差し替わらない」という**無音の失効**になる。
+     *
+     * @return list<string>
+     */
+    public static function capabilityEnvironments(string $flag): array
+    {
+        return match ($flag) {
+            self::EXTERNALS_FLAG => self::EXTERNAL_ENVIRONMENTS,
+            self::STORAGE_FLAG => self::STORAGE_ENVIRONMENTS,
+            self::LLM_FLAG => self::LLM_ENVIRONMENTS,
+            default => throw new InvalidArgumentException("宣言されていない capability flag: {$flag}"),
+        };
+    }
+
+    /**
+     * bug-hunt レーンで偽物を外せないフラグ (安全下限集合)。
+     *
+     * 決済・人間性確認・外部ログインは、bug-hunt の走行そのものが実課金と実 IdP 遷移を
+     * 起こすため、走行オプションで実物へ戻す口を持たない。
+     * この不変条件を実際に固定するのは BughuntEnvExampleContractTest であり、
+     * 本メソッドはその入力元 (集合の正本) である。
+     *
+     * @return list<string> config キー
+     */
+    public static function bughuntRequiredFlags(): array
+    {
+        return [self::EXTERNALS_FLAG];
+    }
+
+    /**
+     * bug-hunt の環境ひな型が持たねばならない環境変数と値。
+     *
+     * @return array<string, string> 環境変数名 => 期待値
+     */
+    public static function bughuntRequiredEnvFlags(): array
+    {
+        $required = [];
+
+        // 添字アクセスではなく写像そのものを走査する (キー不在の分岐を作らない)。
+        foreach (self::FLAG_ENVIRONMENT_VARIABLES as $flag => $variable) {
+            if (in_array($flag, self::bughuntRequiredFlags(), true)) {
+                $required[$variable] = 'true';
+            }
+        }
+
+        return $required;
+    }
+
+    /**
+     * 差し替えてはいけない到達点 (偽物に落とすと検査そのものが無効になるもの)。
+     *
+     * ★責務分担: 本メソッドが止めるのは「この宣言へ足すこと」だけである。
+     *   本番コードが偽物のクラス名を参照しないことの全走査は
+     *   FakeClassReferenceInvariantTest が担い、外部到達点の目録は
+     *   ExternalSeamInventory が担う。
+     *
+     * @return array<class-string, string> クラス => なぜ差し替えないか
+     */
+    public static function neverSwapped(): array
+    {
+        return [
+            SnsSignatureVerifier::class => '受信通知の署名検証。偽物にすると差出人の詐称を検出できなくなる。',
+            UrlSafetyInspector::class => '外部 URL の安全検査 (SSRF 防御)。偽物にすると内部宛ての取得が通る。',
+        ];
+    }
+
+    /**
+     * container 差し替えの全宣言。
+     *
+     * ⚠️ entry を足す実装者へ: Architecture レーンは RefreshDatabase を使わない。
+     * abstract / real / fake のコンストラクタが DB に触れないことを必ず確認すること。
+     *
+     * @return list<ExternalFakeBinding>
+     */
+    public static function swaps(): array
+    {
+        return [
+            new ExternalFakeBinding(
+                abstract: TicketCheckoutGateway::class,
+                real: CashierTicketCheckoutGateway::class,
+                fake: FakeTicketCheckoutGateway::class,
+                flag: self::EXTERNALS_FLAG,
+                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
+                risk: 'チケットスポット購入の Stripe Checkout。配線が外れると実 Stripe に実課金セッションを作る。',
+            ),
+            new ExternalFakeBinding(
+                abstract: StripeGatewayInterface::class,
+                real: CashierStripeGateway::class,
+                fake: FakeStripeGateway::class,
+                flag: self::EXTERNALS_FLAG,
+                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
+                risk: 'サブスク Checkout / Customer Portal。配線が外れると実 Stripe に契約を作る。',
+            ),
+            new ExternalFakeBinding(
+                abstract: AutoRechargeGatewayInterface::class,
+                real: CashierAutoRechargeGateway::class,
+                fake: FakeAutoRechargeGateway::class,
+                flag: self::EXTERNALS_FLAG,
+                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
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
+            new ExternalFakeBinding(
+                abstract: RecaptchaVerifier::class,
+                real: RecaptchaVerifier::class,
+                fake: RecaptchaVerifierTestFake::class,
+                flag: self::EXTERNALS_FLAG,
+                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
+                risk: 'Google reCAPTCHA siteverify への外向き POST。abstract が具象クラスのため、'
+                    .'bind を消しても Laravel が本物を自動組み立てし、RECAPTCHA_SECRET_KEY が'
+                    .'設定された環境では無言で実 Google を叩く (bug-hunt の別プロセスには '
+                    .'StrayHttpRequestGuard が効かない)。',
+            ),
+            new ExternalFakeBinding(
+                abstract: SocialiteDriverResolver::class,
+                real: SocialiteDriverResolver::class,
+                fake: FakeSocialiteDriverResolver::class,
+                flag: self::EXTERNALS_FLAG,
+                allowedEnvironments: self::SSO_ENVIRONMENTS,
+                risk: 'SSO (Socialite) の driver 解決点。abstract が具象クラスのため、bind を消しても '
+                    .'Laravel が本物を自動組み立てし、**無言で**実 IdP (accounts.google.com 等) への '
+                    .'リダイレクトに戻る。bug-hunt のブラウザは別プロセスなので StrayHttpRequestGuard は効かない。',
+            ),
+        ];
+    }
+}
diff --git a/app/Support/FakeStorageGate.php b/app/Support/FakeStorageGate.php
index 13e10c1..377d48d 100644
--- a/app/Support/FakeStorageGate.php
+++ b/app/Support/FakeStorageGate.php
@@ -4,22 +4,23 @@
 
 namespace App\Support;
 
+use App\Support\ExternalFakes\ExternalFakeDeclaration;
 use Illuminate\Contracts\Foundation\Application;
 
 /**
- * storage fake の有効化 predicate の SSOT (fail-secure 二軸)。
+ * 偽の保存先の有効化条件の単一正本 (fail-secure 二軸)。
  *
- * route 登録 (FakeExternalsServiceProvider) と signed route action guard の双方が
- * 本メソッドを参照する (登録条件より実行時条件が弱いと route cache 残存で素通りするため
+ * 経路登録 (FakeExternalsServiceProvider) と署名付き経路の action guard の双方が
+ * 本メソッドを参照する (登録条件より実行時条件が弱いと経路キャッシュ残存で素通りするため
  * 完全一致させる)。
  *
  * 二軸:
- * 1. capability flag: config('testing.fake_storage') === true (既定 false = 完全 no-op)
- * 2. env allowlist: bughunt.local ∨ (testing ∧ runningUnitTests)
- *    - bughunt.local: 実 bug-hunt runtime
- *    - testing ∧ runningUnitTests: 自動テストのみ (testing を HTTP 実行環境として素通ししない)
+ * 1. capability flag: ExternalFakeDeclaration::STORAGE_FLAG === true (既定 false = 完全 no-op)
+ * 2. 許可環境: ExternalFakeDeclaration::STORAGE_ENVIRONMENTS (= bughunt.local / testing)。
+ *    ただし testing は**自動テスト実行中に限る** (testing を HTTP 実行環境として素通ししない)。
+ *    許可環境そのものは宣言側が正本で、testing への追加条件だけを本クラスが持つ。
  *
- * production は ProductionEnvGuard が flag=true を deploy 時 fail-fast で拒否する (二重防御)。
+ * production は ProductionEnvGuard が flag=true を起動時 fail-fast で拒否する (二重防御)。
  */
 final readonly class FakeStorageGate
 {
@@ -27,15 +28,15 @@ public function __construct(private Application $app) {}
 
     public function enabled(): bool
     {
-        if (config('testing.fake_storage') !== true) {
+        if (config(ExternalFakeDeclaration::STORAGE_FLAG) !== true) {
             return false;
         }
 
         $env = $this->app->environment();
-        if ($env === 'bughunt.local') {
-            return true;
+        if (! in_array($env, ExternalFakeDeclaration::STORAGE_ENVIRONMENTS, true)) {
+            return false;
         }
 
-        return $env === 'testing' && $this->app->runningUnitTests();
+        return $env !== 'testing' || $this->app->runningUnitTests();
     }
 }
diff --git a/app/Support/ProductionEnvGuard.php b/app/Support/ProductionEnvGuard.php
index ef9d1a2..2958562 100644
--- a/app/Support/ProductionEnvGuard.php
+++ b/app/Support/ProductionEnvGuard.php
@@ -4,6 +4,7 @@
 
 namespace App\Support;
 
+use App\Support\ExternalFakes\ExternalFakeDeclaration;
 use Laravel\Fortify\Features;
 use RuntimeException;
 use Throwable;
@@ -19,9 +20,9 @@
  * - APP_DEBUG=false (stack trace / 設定露出防止)
  * - SECURITY_HSTS_ENABLED / SECURITY_CSP_ENABLED=true (セキュリティヘッダ必須)
  * - DEBUG_LOGIN_USER / DEBUG_LOGIN_PASSWORD が空 (local 専用機構の誤投入防止)
- * - TESTING_FAKE_EXTERNALS=false (Stripe 外部 fake の本番混入防止)
- * - TESTING_FAKE_LLM=false (LLM fake の本番混入防止)
- * - TESTING_FAKE_STORAGE=false (storage fake の本番混入防止)
+ * - 偽の外部サービスのフラグが false (本番混入防止)。対象と環境変数名の正本は
+ *   App\Support\ExternalFakes\ExternalFakeDeclaration で、**設定値とプロセスの実環境変数の
+ *   両方**を見る (設定キャッシュが失われた起動で環境変数が読み直されるため)
  * - TrustHosts allowlist (Host header injection 防御の allowlist 非空・書式)
  * - TrustProxies allowlist (client IP / X-Forwarded-Proto の信頼境界。未宣言・`*`・
  *   REMOTE_ADDR・書式不正を拒否。プロキシ無し構成は `none` の明示宣言を要求する)
@@ -86,24 +87,26 @@ public function violations(): array
                 .'(both are local-dev only; presence indicates dangerous misconfiguration).';
         }
 
-        // 外部 fake flag は非本番専用。production で true なら課金 (Stripe) が fake に
-        // 差し替わり得る危険設定のため fail-fast する (FakeExternalsServiceProvider の
-        // allowlist で bind 自体は起きないが、設定として存在すること自体を拒否する)
-        if (config('testing.fake_externals') === true) {
-            $errors[] = 'TESTING_FAKE_EXTERNALS must be false in production '
-                .'(external fakes must never be enabled in production).';
-        }
-
-        // LLM fake は production で real LLM を潰すため禁止 (fake_externals と同じ fail-secure)。
-        if (config('testing.fake_llm') === true) {
-            $errors[] = 'TESTING_FAKE_LLM must be false in production '
-                .'(LLM fake must never be enabled in production).';
-        }
+        // 偽の外部サービスのフラグは非本番専用。production で true なら課金 (Stripe) が
+        // 偽物へ差し替わり得る危険設定のため fail-fast する (配線 provider の許可環境で
+        // bind 自体は起きないが、設定として存在すること自体を拒否する)。
+        //
+        // ★**設定値とプロセスの実環境変数の両方**を見る。設定キャッシュを作った環境と
+        //   出荷先が食い違うと、キャッシュ上は false でも、キャッシュが失われた起動で
+        //   環境変数が読み直されて本番で偽物が立ちうる (経路キャッシュが古いと保護が
+        //   無音で外れるのと同じ形)。対象と環境変数名の正本は ExternalFakeDeclaration。
+        foreach (ExternalFakeDeclaration::FLAG_ENVIRONMENT_VARIABLES as $flag => $variable) {
+            if (config($flag) === true) {
+                $errors[] = "{$variable} must be false in production (configuration value).";
+            }
 
-        // storage fake は production で実ストレージを潰し得るため禁止。
-        if (config('testing.fake_storage') === true) {
-            $errors[] = 'TESTING_FAKE_STORAGE must be false in production '
-                .'(storage fake must never be enabled in production).';
+            // $_SERVER / $_ENV / getenv() を独立に見る (どれか 1 つでも危険側なら違反)。
+            foreach ($this->rawEnvironmentValues($variable) as $source => $raw) {
+                if (! $this->isUnambiguouslyDisabled($raw)) {
+                    $errors[] = "{$variable} must be false in production "
+                        ."(process environment via {$source}: ".var_export($raw, true).').';
+                }
+            }
         }
 
         // Host header injection 防御の TrustHosts allowlist を起動時検証。
@@ -180,6 +183,53 @@ public function enforce(): void
         }
     }
 
+    /**
+     * プロセスの実環境変数を 3 経路で読み、**値が存在するものだけ**を返す。
+     *
+     * 未設定は判定対象にしない。`$_SERVER` / `$_ENV` は mixed を持ちうるので
+     * **型を絞らずそのまま返す** (絞って捨てると設定破損を見逃す)。
+     *
+     * @return array<string, mixed> 経路名 => 生の値
+     */
+    private function rawEnvironmentValues(string $variable): array
+    {
+        $values = [];
+
+        if (array_key_exists($variable, $_SERVER)) {
+            $values['$_SERVER'] = $_SERVER[$variable];
+        }
+
+        if (array_key_exists($variable, $_ENV)) {
+            $values['$_ENV'] = $_ENV[$variable];
+        }
+
+        // getenv() の false は「未設定」であり、空文字とは区別する。
+        $raw = getenv($variable);
+        if ($raw !== false) {
+            $values['getenv()'] = $raw;
+        }
+
+        return $values;
+    }
+
+    /**
+     * 生の値が「間違いなく無効」と読めるか。
+     *
+     * 文字列でない値を含め、解釈できない値はすべて違反 (安全側) にする。
+     */
+    private function isUnambiguouslyDisabled(mixed $raw): bool
+    {
+        if (! is_string($raw)) {
+            return false;
+        }
+
+        return in_array(
+            strtolower($raw),
+            ['', 'false', '(false)', '0', 'off', 'no', 'null', '(null)'],
+            true
+        );
+    }
+
     /**
      * config 値を string list へ正規化する (非 string 要素を除外)。
      *
diff --git a/config/testing.php b/config/testing.php
index 634d444..18e7eb6 100644
--- a/config/testing.php
+++ b/config/testing.php
@@ -2,58 +2,48 @@
 
 declare(strict_types=1);
 
+/*
+|--------------------------------------------------------------------------
+| 偽の外部サービスの capability flag
+|--------------------------------------------------------------------------
+|
+| **何をどの偽物へ差し替えるか、どの環境で許すかの正本は
+| App\Support\ExternalFakes\ExternalFakeDeclaration である**。
+| 本ファイルが持つのは capability ごとの真偽値 3 本だけで、対象の列挙は持たない
+| (列挙をここへ写すと必ず宣言とずれる)。
+|
+| 3 本とも既定 false = 未設定の環境では完全 no-op。production では
+| ProductionEnvGuard が true を起動時 fail-fast で拒否する (設定値とプロセスの
+| 実環境変数の両方を見る)。
+|
+*/
+
 return [
 
     /*
-    |--------------------------------------------------------------------------
-    | 外部サービス fake 化の capability flag
-    |--------------------------------------------------------------------------
-    |
-    | fake_externals: **外部サービス fake の capability flag** (既定 false = no-op)。
-    | true のとき FakeExternalsServiceProvider::register() が以下を fake 実装へ bind する:
-    |   - Stripe 課金 gateway (checkout / portal / auto-recharge)
-    |   - captcha 検証器 (RecaptchaVerifier → RecaptchaVerifierTestFake)
-    |   - SSO driver 解決点 (SocialiteDriverResolver → FakeSocialiteDriverResolver)
-    | **SSO だけは env allowlist が狭い** (testing / bughunt.local のみ。**local を除外**)。
-    |  SSO fake は未認証 GET 2 本で canned アカウントへログインできる = 認証バイパスであり、
-    |  かつ local は実 IdP 連携を確認する唯一の環境であるため
-    |  (docs/architecture.md §外部到達点の目録 (標準形 v1) を参照)。
-    | Stripe / captcha の有効化は allowlist 環境 (local / testing / bughunt.local) に限定され、
-    | production では ProductionEnvGuard が true を deploy 時 fail-fast で拒否する。
-    | 既定 false = 本 flag 未設定の環境では完全 no-op。
-    |
-    | ※ LLM (Prism) fake はこの flag から分離され fake_llm が capability flag。
-    |
+    | fake_externals: 決済 gateway / 人間性確認 / 外部ログインの解決点を偽物へ差し替える。
+    | 許可環境は ExternalFakeDeclaration::EXTERNAL_ENVIRONMENTS。
+    | **外部ログインだけ許可環境が狭い** (SSO_ENVIRONMENTS。local を除く) —
+    | 未認証 GET 2 本で canned アカウントに入れる = 認証バイパスであり、かつ local は
+    | 実 IdP 連携を確かめる唯一の環境であるため。
     */
 
     'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),
 
     /*
-    |--------------------------------------------------------------------------
-    | LLM (Prism) fake 化の capability flag
-    |--------------------------------------------------------------------------
-    |
-    | fake_llm: LLM (Prism) fake を install するか。config 既定 false = real LLM。
-    | bughunt は既定 real-llm (scripts/bug-hunt-shard.sh が TESTING_FAKE_LLM=false を明示注入)。
-    | --fake-llm 指定時のみ true 注入 → FakeExternalsServiceProvider::boot が
-    | CannedPromptFakeRegistrar を install (env allowlist bughunt.local のみ)。
-    | production では ProductionEnvGuard が true を fail-fast で拒否する。
-    |
+    | fake_llm: LLM (Prism) の応答を偽物へ差し替える。
+    | 許可環境は ExternalFakeDeclaration::LLM_ENVIRONMENTS (bughunt.local のみ) —
+    | Prompt::$fake はプロセス大域の static のため testing / local を外す。
+    | bug-hunt の既定は実 LLM で、--fake-llm 指定時のみ true が注入される。
     */
 
     'fake_llm' => (bool) env('TESTING_FAKE_LLM', false),
 
     /*
-    |--------------------------------------------------------------------------
-    | S3 ストレージ fake 化のトグル (骨子)
-    |--------------------------------------------------------------------------
-    |
-    | fake_storage: S3 ストレージ fake トグル (骨子)。config 既定 false = 本番安全側。
-    | bughunt は既定 fake (scripts/bug-hunt-shard.sh が TESTING_FAKE_STORAGE=true を明示注入)。
-    | --real-storage 指定時のみ false 注入。
-    | ※ 実 S3 接続の実配線は本 item スコープ外 (consumer 未実装 = inert)。
-    | production では ProductionEnvGuard が true を fail-fast で拒否する。
-    |
+    | fake_storage: S3 の保存先を偽物へ差し替える。
+    | 許可環境は ExternalFakeDeclaration::STORAGE_ENVIRONMENTS。
+    | testing は自動テスト実行中に限る (追加条件は FakeStorageGate が持つ)。
+    | bug-hunt の既定は偽物で、--real-storage 指定時のみ false が注入される。
     */
 
     'fake_storage' => (bool) env('TESTING_FAKE_STORAGE', false),
diff --git a/database/seeders/BughuntBillingSeeder.php b/database/seeders/BughuntBillingSeeder.php
index 1f4c111..359cf6b 100644
--- a/database/seeders/BughuntBillingSeeder.php
+++ b/database/seeders/BughuntBillingSeeder.php
@@ -10,6 +10,7 @@
 use App\Models\Organization;
 use App\Services\Billing\PersonalPlanService;
 use App\Services\Billing\TicketLedgerService;
+use App\Support\ExternalFakes\ExternalFakeDeclaration;
 use Carbon\CarbonImmutable;
 use Illuminate\Database\Seeder;
 use Illuminate\Support\Facades\DB;
@@ -48,7 +49,7 @@ class BughuntBillingSeeder extends Seeder
     public function run(TicketLedgerService $tickets): void
     {
         if (
-            config('testing.fake_externals') !== true
+            config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true
             || ! app()->environment('bughunt.local')
             || ! $this->isBughuntDatabase()
         ) {
diff --git a/database/seeders/BughuntOAuthSeeder.php b/database/seeders/BughuntOAuthSeeder.php
index 58b1580..14e274f 100644
--- a/database/seeders/BughuntOAuthSeeder.php
+++ b/database/seeders/BughuntOAuthSeeder.php
@@ -8,6 +8,7 @@
 use App\Models\OauthSession;
 use App\Models\Organization;
 use App\Models\User;
+use App\Support\ExternalFakes\ExternalFakeDeclaration;
 use Illuminate\Database\Seeder;
 use Illuminate\Support\Facades\Artisan;
 use Illuminate\Support\Facades\DB;
@@ -50,7 +51,7 @@ public function run(): void
     {
         // fail-secure 三軸: fake_externals かつ bughunt.local かつ DB 名 bug_hunt* の全成立時のみ。
         if (
-            config('testing.fake_externals') !== true
+            config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true
             || ! app()->environment('bughunt.local')
             || ! $this->isBughuntDatabase()
         ) {
diff --git a/tests/Architecture/BughuntEnvExampleContractTest.php b/tests/Architecture/BughuntEnvExampleContractTest.php
index 9529c30..0345f87 100644
--- a/tests/Architecture/BughuntEnvExampleContractTest.php
+++ b/tests/Architecture/BughuntEnvExampleContractTest.php
@@ -2,6 +2,8 @@
 
 declare(strict_types=1);
 
+use App\Support\ExternalFakes\ExternalFakeDeclaration;
+
 /*
  * Architecture invariant: .env.bughunt.local.example が bug-hunt 環境の
  * 「production 同等性の最小セット」を保持すること。
@@ -16,7 +18,9 @@
  *   - APP_LOCALE=ja              : bug-hunt はユーザー向け文言 (日本語) の検証環境。en のままだと
  *                                  production と異なる文言を検証してしまう
  *   - DB_DATABASE=bug_hunt       : dev DB 隔離の核 (^bug_hunt(_[1-4])?$ のみ許可)
- *   - TESTING_FAKE_EXTERNALS=true: 決済等の外部を fake に落とす (実課金を踏まない)
+ *   - 偽物を外せないフラグ    : 決済等の外部を偽物に落とす (実課金を踏まない)。
+ *                                  対象と期待値は ExternalFakeDeclaration::bughuntRequiredEnvFlags()
+ *                                  が正本で、本テストは写経せず宣言から組み立てる
  *   - ADMIN_MFA_REQUIRED=false   : true だと admin ログイン後 TOTP 強制で探索が詰む
  *
  * 併せて「秘密値を example に焼き込まない」ことも固定する (APP_KEY / CIPHERSWEET_KEY /
@@ -41,7 +45,9 @@ function bughuntEnvExampleViolations(string $content): array
         'APP_ENV' => 'bughunt.local',
         'APP_LOCALE' => 'ja',
         'DB_DATABASE' => 'bug_hunt',
-        'TESTING_FAKE_EXTERNALS' => 'true',
+        // 偽物を外せないフラグ (安全下限集合) は宣言から組み立てる。
+        // ここへ環境変数名を写経すると、宣言が増えても env ひな型の検査が追随しない。
+        ...ExternalFakeDeclaration::bughuntRequiredEnvFlags(),
         'ADMIN_MFA_REQUIRED' => 'false',
     ];
     foreach ($exactValues as $key => $expected) {
diff --git a/tests/Architecture/BughuntOrchestratorGateInvariantTest.php b/tests/Architecture/BughuntOrchestratorGateInvariantTest.php
index 97b59dc..e19ff07 100644
--- a/tests/Architecture/BughuntOrchestratorGateInvariantTest.php
+++ b/tests/Architecture/BughuntOrchestratorGateInvariantTest.php
@@ -2,6 +2,8 @@
 
 declare(strict_types=1);
 
+use Tests\Support\Bughunt\ShellFunctionWindow;
+
 /*
  * Architecture invariant (B-HARNESS-01): bug-hunt の orchestrator gate 2 層が構造的に整合すること。
  *
@@ -31,21 +33,14 @@ function bughuntGateReadSource(string $relativePath): string
 }
 
 /**
- * `^name()` 行から次の `^cmd_` 定義 (または EOF) までの関数窓を切り出す。
+ * `^cmd_name()` 行から次の `^cmd_` 定義 (または EOF) までの関数窓を切り出す。
  *
- * 非貪欲 `\n\}` 終端は使わない: 関数本体が heredoc (`<<'PY'` 等) 内に行頭 `}` を持つと
- * 最短マッチがそこで止まり真の末尾を取り逃す。`/m` + 先読みで「次の cmd_ 定義の直前まで」
- * を取れば heredoc 持ち関数でも安全側に切り出せる。
+ * 切り出しの実体は Tests\Support\Bughunt\ShellFunctionWindow に一本化してある
+ * (BughuntSeedWiringInvariantTest と共有する。同じ切り出しを 2 本持たない)。
  */
 function bughuntGateFunctionWindow(string $source, string $name): string
 {
-    $m = [];
-    // cmd_provision と cmd_provision_all を取り違えないよう `()` まで含めてアンカーする。
-    $matched = preg_match('/^'.preg_quote($name, '/').'\(\)[\s\S]*?(?=^cmd_|\z)/m', $source, $m);
-    expect($matched)->toBe(1, "関数窓が見つからない: {$name}");
-
-    /** @var array{0: string} $m */
-    return $m[0];
+    return ShellFunctionWindow::ofCommand($source, $name);
 }
 
 /**
diff --git a/tests/Architecture/BughuntSeedWiringInvariantTest.php b/tests/Architecture/BughuntSeedWiringInvariantTest.php
new file mode 100644
index 0000000..90233bb
--- /dev/null
+++ b/tests/Architecture/BughuntSeedWiringInvariantTest.php
@@ -0,0 +1,408 @@
+<?php
+
+declare(strict_types=1);
+
+use Database\Seeders\BughuntOAuthSeeder;
+use Illuminate\Database\Seeder;
+use Tests\Support\Bughunt\BughuntSeedRole;
+use Tests\Support\Bughunt\BughuntSeedWiringInventory;
+use Tests\Support\Bughunt\ShellFunctionWindow;
+
+/*
+ * Architecture invariant: bug-hunt の投入データ (seeder) の配線が目録と一致すること。
+ *
+ * 偽の外部サービスの配線は「登録漏れは無音で本物が動く」ことを理由に deny-by-default の
+ * 実証 gate を持っているのに、**投入データ側は同じ理由が当てはまるのに検査が 1 つも無かった**。
+ * 起きうる無音の事故は 3 つ (BughuntSeedWiringInventory の docblock に列挙)。
+ *
+ * SoT:
+ *   - scripts/bug-hunt-shard.sh の cmd_provision / cmd_reseed (実際に流す列)
+ *   - database/seeders/ の各 seeder (環境ガードの実体)
+ *   - tests/Support/Bughunt/BughuntSeedWiringInventory.php (区分の目録)
+ *
+ * **保証範囲を誇張しない**: 見るのは静的な字面である。条件の論理 (かつ / または) は読めないため、
+ * ガードを要求する区分には振る舞いテストを目録から紐づける (S-9)。
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+/**
+ * database/seeders 配下の Seeder クラス一覧 (実在するものだけ)。
+ *
+ * @return list<class-string>
+ */
+function bughuntSeedDeclaredSeederClasses(): array
+{
+    $root = base_path('database/seeders');
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
+    );
+
+    $classes = [];
+    /** @var SplFileInfo $file */
+    foreach ($iterator as $file) {
+        if (! $file->isFile() || $file->getExtension() !== 'php') {
+            continue;
+        }
+
+        $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
+        $class = 'Database\\Seeders\\'.str_replace('/', '\\', $relative);
+
+        if (class_exists($class) && is_subclass_of($class, Seeder::class)) {
+            $classes[] = $class;
+        }
+    }
+
+    sort($classes);
+
+    return $classes;
+}
+
+/** bug-hunt の shard スクリプト本体 (読み取り失敗は例外で落ちる) */
+function bughuntSeedShardSource(): string
+{
+    $source = file_get_contents(base_path('scripts/bug-hunt-shard.sh'));
+    if (! is_string($source) || $source === '') {
+        throw new RuntimeException('scripts/bug-hunt-shard.sh が読めない');
+    }
+
+    return $source;
+}
+
+/**
+ * シェル関数の窓から `db:seed --class=<名前>` の列を出現順に取り出す。
+ *
+ * @return list<string> クラスの短い名前 (出現順)
+ */
+function bughuntSeedClassSequence(string $window): array
+{
+    $matches = [];
+    preg_match_all('/db:seed\s+--class=([A-Za-z0-9_]+)/', $window, $matches);
+
+    /** @var array{0: list<string>, 1: list<string>} $matches */
+    return $matches[1];
+}
+
+/** クラスの短い名前 (FQCN の末尾セグメント) */
+function bughuntSeedShortName(string $class): string
+{
+    $position = strrpos($class, '\\');
+
+    return $position === false ? $class : substr($class, $position + 1);
+}
+
+/**
+ * `run()` の最初の実効文の形を返す。
+ *
+ * - `first`: 最初の実効トークンの字句 (`if` なら 'if')
+ * - `condition`: 最初の実効文が `if` のときの条件式の字句 (それ以外は空文字)
+ * - `body`: 同じく `if` の本体の字句 (それ以外は空文字)
+ *
+ * @return array{first: string, condition: string, body: string}
+ */
+function bughuntSeedRunGuardShape(string $source): array
+{
+    /** @var list<array{id: int, text: string}> $tokens */
+    $tokens = [];
+    foreach (token_get_all($source) as $token) {
+        if (is_array($token)) {
+            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
+                continue;
+            }
+            $tokens[] = ['id' => $token[0], 'text' => $token[1]];
+
+            continue;
+        }
+        $tokens[] = ['id' => -1, 'text' => $token];
+    }
+
+    $count = count($tokens);
+    $signature = null;
+    for ($i = 0; $i + 1 < $count; $i++) {
+        if ($tokens[$i]['id'] === T_FUNCTION
+            && $tokens[$i + 1]['id'] === T_STRING
+            && $tokens[$i + 1]['text'] === 'run') {
+            $signature = $i;
+            break;
+        }
+    }
+
+    if ($signature === null) {
+        throw new RuntimeException('run() の宣言が見つからない');
+    }
+
+    // 引数リストを読み飛ばし、本体の `{` を見つける。
+    $bodyStart = null;
+    $depth = 0;
+    for ($i = $signature; $i < $count; $i++) {
+        $text = $tokens[$i]['text'];
+        if ($text === '(') {
+            $depth++;
+        } elseif ($text === ')') {
+            $depth--;
+        } elseif ($text === '{' && $depth === 0) {
+            $bodyStart = $i;
+            break;
+        }
+    }
+
+    if ($bodyStart === null) {
+        throw new RuntimeException('run() の本体が見つからない');
+    }
+
+    $first = $tokens[$bodyStart + 1] ?? null;
+    if ($first === null) {
+        throw new RuntimeException('run() の本体が空である');
+    }
+
+    if ($first['id'] !== T_IF) {
+        return ['first' => $first['text'], 'condition' => '', 'body' => ''];
+    }
+
+    // 条件式 (`if` の直後の括弧)
+    $condition = '';
+    $conditionEnd = null;
+    $depth = 0;
+    for ($i = $bodyStart + 2; $i < $count; $i++) {
+        $text = $tokens[$i]['text'];
+        if ($text === '(') {
+            $depth++;
+            if ($depth === 1) {
+                continue;
+            }
+        } elseif ($text === ')') {
+            $depth--;
+            if ($depth === 0) {
+                $conditionEnd = $i;
+                break;
+            }
+        }
+        $condition .= $text;
+    }
+
+    if ($conditionEnd === null) {
+        throw new RuntimeException('if の条件式を読み取れない');
+    }
+
+    // 本体 (`{ … }`)
+    $body = '';
+    $depth = 0;
+    for ($i = $conditionEnd + 1; $i < $count; $i++) {
+        $text = $tokens[$i]['text'];
+        if ($text === '{') {
+            $depth++;
+            if ($depth === 1) {
+                continue;
+            }
+        } elseif ($text === '}') {
+            $depth--;
+            if ($depth === 0) {
+                break;
+            }
+        }
+        $body .= $text.' ';
+    }
+
+    return ['first' => 'if', 'condition' => $condition, 'body' => $body];
+}
+
+test('S-1 目録のキー集合が database/seeders の Seeder クラス集合と過不足なく一致する', function (): void {
+    $declared = bughuntSeedDeclaredSeederClasses();
+    $registered = array_keys(BughuntSeedWiringInventory::entries());
+
+    // 走査が壊れて「空母集団で緑」になるのを防ぐ (fail-closed)
+    expect($declared)->not->toBeEmpty();
+
+    sort($registered);
+
+    expect($registered)->toBe($declared);
+});
+
+test('S-2 各 entry の理由が 30 文字以上である', function (): void {
+    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
+        expect(mb_strlen($entry['reason']))
+            ->toBeGreaterThanOrEqual(30, "理由が短すぎる: {$class}");
+    }
+});
+
+test('S-3 cmd_provision と cmd_reseed の投入列が順序込みで一致する', function (): void {
+    $source = bughuntSeedShardSource();
+
+    $provision = bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_provision'));
+    $reseed = bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_reseed'));
+
+    // ★順序にも意味がある (ManualTestSeeder が先に走らないと BughuntOAuthSeeder は
+    //   代表ユーザーを見つけられず skip する)。並べ替えたいときは 2 か所を同時に直すこと。
+    expect($provision)->not->toBeEmpty()
+        ->and($reseed)->toBe($provision);
+});
+
+test('S-4 投入列の集合が目録の「bug-hunt で明示投入する」区分と過不足なく一致する', function (): void {
+    $sequence = bughuntSeedClassSequence(
+        ShellFunctionWindow::ofCommand(bughuntSeedShardSource(), 'cmd_provision')
+    );
+
+    $expected = array_map(
+        bughuntSeedShortName(...),
+        BughuntSeedWiringInventory::seededInBughunt()
+    );
+
+    $actual = array_values(array_unique($sequence));
+    sort($actual);
+    sort($expected);
+
+    expect($expected)->not->toBeEmpty()
+        ->and($actual)->toBe($expected);
+});
+
+test('S-5 BughuntOnly 区分は DatabaseSeeder の呼び出し列に現れない', function (): void {
+    $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));
+    expect($source)->toBeString();
+    /** @var string $source */
+    $bughuntOnly = [];
+    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
+        if ($entry['role'] === BughuntSeedRole::BughuntOnly) {
+            $bughuntOnly[] = bughuntSeedShortName($class);
+        }
+    }
+
+    expect($bughuntOnly)->not->toBeEmpty();
+
+    foreach ($bughuntOnly as $name) {
+        // ★見るのは字面である (DatabaseSeeder のソースに名前が現れないこと)。
+        //   通常経路へ載せる書き方は必ずクラス名を書くため、これで 3 つ目の事故を止められる。
+        expect($source)->not->toContain($name);
+    }
+});
+
+test('S-6 ガードを要求する区分は run() の最初の実効文が if で、判定語と早期 return を持つ', function (): void {
+    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
+        $markers = BughuntSeedWiringInventory::requiredGuardMarkers($entry['role']);
+        if ($markers === []) {
+            continue;
+        }
+
+        $file = (new ReflectionClass($class))->getFileName();
+        expect($file)->toBeString();
+        /** @var string $file */
+        $source = file_get_contents($file);
+        expect($source)->toBeString();
+        /** @var string $source */
+        $shape = bughuntSeedRunGuardShape($source);
+
+        expect($shape['first'])->toBe('if', "{$class} の run() の最初の実効文が if でない");
+
+        foreach ($markers as $marker) {
+            // ★`toContain()` は可変長の needle を取るため、失敗メッセージは
+            //   `toBeTrue()` 側へ渡す (第 2 引数を message と誤用しない)。
+            expect(str_contains($shape['condition'], $marker))
+                ->toBeTrue("{$class} のガード条件に {$marker} が無い");
+        }
+
+        expect(str_contains($shape['body'], 'return'))
+            ->toBeTrue("{$class} のガードに早期 return が無い");
+    }
+});
+
+test('S-7 fail-closed: 投入列も目録も空でないこと', function (): void {
+    $source = bughuntSeedShardSource();
+
+    expect(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_provision')))->not->toBeEmpty()
+        ->and(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_reseed')))->not->toBeEmpty()
+        ->and(BughuntSeedWiringInventory::entries())->not->toBeEmpty()
+        ->and(BughuntSeedWiringInventory::seededInBughunt())->not->toBeEmpty();
+});
+
+test('S-8 負のコントロール: 投入列の欠落 / 並べ替え / ガードの後退を検出する', function (): void {
+    // (a) reseed から 1 行落とす
+    $dropped = <<<'SH'
+    cmd_provision() {
+        artisan_for_shard db:seed --class=ManualTestSeeder --force
+        artisan_for_shard db:seed --class=BughuntOAuthSeeder --force
+    }
+    cmd_reseed() {
+        artisan_for_shard db:seed --class=ManualTestSeeder --force
+    }
+    SH;
+    expect(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($dropped, 'cmd_reseed')))
+        ->not->toBe(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($dropped, 'cmd_provision')));
+
+    // (b) 並びを入れ替える
+    $reordered = <<<'SH'
+    cmd_provision() {
+        artisan_for_shard db:seed --class=ManualTestSeeder --force
+        artisan_for_shard db:seed --class=BughuntOAuthSeeder --force
+    }
+    cmd_reseed() {
+        artisan_for_shard db:seed --class=BughuntOAuthSeeder --force
+        artisan_for_shard db:seed --class=ManualTestSeeder --force
+    }
+    SH;
+    expect(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($reordered, 'cmd_reseed')))
+        ->not->toBe(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($reordered, 'cmd_provision')));
+
+    // (c) ガードの前に 1 文入れる
+    $beforeGuard = "<?php\nclass X {\n public function run(): void {\n"
+        ."  \$this->command->info('start');\n"
+        ."  if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true) { return; }\n"
+        ." }\n}\n";
+    expect(bughuntSeedRunGuardShape($beforeGuard)['first'])->not->toBe('if');
+
+    // (d) ガードの中に早期 return が無い
+    $noReturn = "<?php\nclass X {\n public function run(): void {\n"
+        ."  if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true) { \$this->command->warn('skip'); }\n"
+        ." }\n}\n";
+    $shape = bughuntSeedRunGuardShape($noReturn);
+    expect($shape['first'])->toBe('if')
+        ->and($shape['body'])->not->toContain('return');
+
+    // (e) 正のコントロール: 判定語を落とすと条件照合が落ちる
+    expect($shape['condition'])->not->toContain('isBughuntDatabase');
+});
+
+test('S-9 ガードを要求する区分は対象 seeder を参照する前提テストを持つ', function (): void {
+    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
+        $markers = BughuntSeedWiringInventory::requiredGuardMarkers($entry['role']);
+        $premise = $entry['guardPremiseTest'];
+
+        if ($markers === []) {
+            expect($premise)->toBeNull("ガードを要求しない区分に前提テストが紐づいている: {$class}");
+
+            continue;
+        }
+
+        expect($premise)->toBeString("前提テストが紐づいていない: {$class}");
+        /** @var string $premise */
+        expect(str_starts_with($premise, 'tests/Feature/'))
+            ->toBeTrue("前提テストは tests/Feature/ 配下であること: {$premise}");
+
+        $path = base_path($premise);
+        expect(is_file($path))->toBeTrue("前提テストが実在しない: {$premise}");
+
+        $source = file_get_contents($path);
+        expect($source)->toBeString();
+        /** @var string $source */
+        expect(str_contains($source, bughuntSeedShortName($class)))
+            ->toBeTrue("前提テストが対象 seeder を参照していない: {$premise}");
+    }
+});
+
+test('S-10 負のコントロール: 前提テストの差し替えを検出する', function (): void {
+    // 実在するが対象 seeder を参照しない別のテストへ差し替えた状態を作る。
+    $unrelated = base_path('tests/Feature/Database/ManualTestSeederTest.php');
+    expect(is_file($unrelated))->toBeTrue();
+
+    $source = file_get_contents($unrelated);
+    expect($source)->toBeString();
+    /** @var string $source */
+    expect($source)->not->toContain(bughuntSeedShortName(BughuntOAuthSeeder::class));
+});
+
+test('S-11 ShellFunctionWindow は cmd_ 以外の名前と不在を例外にする', function (): void {
+    $source = bughuntSeedShardSource();
+
+    expect(fn (): string => ShellFunctionWindow::ofCommand($source, 'require_orchestrator'))
+        ->toThrow(InvalidArgumentException::class);
+
+    expect(fn (): string => ShellFunctionWindow::ofCommand($source, 'cmd_does_not_exist'))
+        ->toThrow(RuntimeException::class);
+});
diff --git a/tests/Architecture/ExternalFakeBootProbeTest.php b/tests/Architecture/ExternalFakeBootProbeTest.php
new file mode 100644
index 0000000..e555fff
--- /dev/null
+++ b/tests/Architecture/ExternalFakeBootProbeTest.php
@@ -0,0 +1,263 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\ExternalFakes\ExternalFakeBinding;
+use App\Support\ExternalFakes\ExternalFakeDeclaration;
+use Symfony\Component\Process\Exception\ProcessTimedOutException;
+use Tests\Support\ExternalFakes\FakeWiringProbeRunner;
+
+/*
+ * 別プロセスで「宣言した差し替えが実際に効いているか」を実測する
+ * (c2c: external-fakes-wiring-gate 柱 2)。
+ *
+ * in-process の実証 (ExternalFakeWiringInvariantTest) は provider を手で再実走させるため、
+ * 「実際の起動 (遅延読み込み provider・設定の解決順) でも効いているか」までは示せない。
+ * ここでは子プロセスを起こし、起動しきったアプリの container から解決して観測する。
+ *
+ * ★子プロセスへ実際の外部資格情報を渡さない。プロセスの環境変数は `env -i` で空にし、
+ *   設定は専用の一時環境ファイル 1 つだけから読む。書いてよいキーに外部サービスの
+ *   資格情報は 1 つも無く、鍵の 2 つは使い捨ての生成値である (P-6 / P-7 / P-8)。
+ *
+ * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
+ * キャッシュが古いときの本番事故は ProductionEnvGuard の二重判定が受け持つ。
+ */
+
+/**
+ * 一時ディレクトリの親の登録簿 (走行後の後片付けに使う)。
+ *
+ * @return list<string>
+ */
+function externalFakeProbeBaseDirectories(?string $add = null): array
+{
+    /** @var list<string> $bases */
+    static $bases = [];
+
+    if ($add !== null) {
+        $bases[] = $add;
+    }
+
+    return $bases;
+}
+
+afterAll(function (): void {
+    foreach (externalFakeProbeBaseDirectories() as $base) {
+        if (is_dir($base)) {
+            @rmdir($base);
+        }
+    }
+});
+
+/**
+ * 観測を 1 回だけ走らせて使い回す (子プロセスの起動は高価なため)。
+ *
+ * 一時ディレクトリの親をケースごとに用意し、走行後に空であることを P-10 が確かめる。
+ *
+ * @return array{
+ *     exitCode: int,
+ *     output: array<string, mixed>,
+ *     envFileValues: array<string, string>,
+ *     directory: string,
+ *     directoryMode: int,
+ *     envFileMode: int,
+ *     configCachePath: string,
+ *     configCacheExists: bool,
+ *     baseDirectory: string,
+ * }
+ */
+function externalFakeProbeRun(string $case): array
+{
+    /** @var array<string, array<string, mixed>> $cache */
+    static $cache = [];
+
+    if (! array_key_exists($case, $cache)) {
+        $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
+        if (! mkdir($base, 0700) || ! is_dir($base)) {
+            throw new RuntimeException("観測用の親ディレクトリを作れない: {$base}");
+        }
+        externalFakeProbeBaseDirectories($base);
+
+        $result = match ($case) {
+            // 偽物側: storage も含めて宣言の全件を偽物にする
+            'fake' => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base),
+            // 対照: フラグを全部落とすと本物が解決される
+            'real' => FakeWiringProbeRunner::run('bughunt.local', false, false, false, $base),
+            // 対照: production はフラグが立っていると起動そのものが失敗する
+            'production' => FakeWiringProbeRunner::run('production', true, false, false, $base),
+            default => throw new InvalidArgumentException("未知の観測ケース: {$case}"),
+        };
+
+        $cache[$case] = [...$result, 'baseDirectory' => $base];
+    }
+
+    /** @var array{exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>, directory: string, directoryMode: int, envFileMode: int, configCachePath: string, configCacheExists: bool, baseDirectory: string} $entry */
+    $entry = $cache[$case];
+
+    return $entry;
+}
+
+/**
+ * 観測結果の `resolved` を「解決キー => 実際に解決されたクラス」として取り出す。
+ *
+ * @param  array<string, mixed>  $output
+ * @return array<string, string>
+ */
+function externalFakeProbeResolved(array $output): array
+{
+    $resolved = $output['resolved'] ?? null;
+    expect($resolved)->toBeArray('観測結果に resolved が無い: '.json_encode($output));
+
+    /** @var array<string, mixed> $resolved */
+    $result = [];
+    foreach ($resolved as $abstract => $class) {
+        expect($class)->toBeString();
+        /** @var string $class */
+        $result[(string) $abstract] = $class;
+    }
+
+    return $result;
+}
+
+test('P-1 実測: bughunt.local + フラグ有効なら宣言の全件が偽物のクラスで厳密一致する', function (): void {
+    $run = externalFakeProbeRun('fake');
+
+    expect($run['exitCode'])->toBe(0, '観測が失敗した: '.json_encode($run['output']));
+
+    $expected = [];
+    foreach (ExternalFakeDeclaration::swaps() as $swap) {
+        $expected[$swap->abstract] = $swap->fake;
+    }
+
+    expect(externalFakeProbeResolved($run['output']))->toBe($expected);
+});
+
+test('P-2 実測: 外部ログインの転送先ホストが自ホストである (実 IdP でない)', function (): void {
+    $run = externalFakeProbeRun('fake');
+
+    expect($run['output']['redirect_host'] ?? null)->toBe(FakeWiringProbeRunner::probeAppHost());
+});
+
+test('P-3 対照: フラグ無効なら宣言の全件が本物のクラスで厳密一致する', function (): void {
+    $run = externalFakeProbeRun('real');
+
+    expect($run['exitCode'])->toBe(0, '観測が失敗した: '.json_encode($run['output']));
+
+    $expected = [];
+    foreach (ExternalFakeDeclaration::swaps() as $swap) {
+        $expected[$swap->abstract] = $swap->real;
+    }
+
+    // 転送先は偽物が有効なときだけ観測する (本物向けの URL を組み立てない)。
+    // `??` は null を「不在」と同一視するため array_key_exists で存在を先に確かめる。
+    expect(externalFakeProbeResolved($run['output']))->toBe($expected)
+        ->and(array_key_exists('redirect_host', $run['output']))->toBeTrue()
+        ->and($run['output']['redirect_host'])->toBeNull();
+});
+
+test('P-4 対照: production + フラグ有効は起動が失敗し、出力にフラグ名が現れる', function (): void {
+    $run = externalFakeProbeRun('production');
+
+    // (a) 順序に依存しない表明
+    expect($run['exitCode'])->not->toBe(0);
+
+    // (b) 順序に依存する表明。AppServiceProvider::boot() は ProductionEnvGuard::enforce() を
+    //     最初に呼ぶため、他の起動時検査より先にこの違反が出る。
+    //     落ちたら「起動時検査の順序が変わった可能性」を疑うこと。
+    $error = $run['output']['error'] ?? '';
+    expect($error)->toBeString();
+    /** @var string $error */
+    expect(str_contains($error, 'TESTING_FAKE_EXTERNALS'))
+        ->toBeTrue('起動時検査の順序が変わった可能性がある (出力: '.$error.')');
+});
+
+test('P-5 fail-closed: 宣言集合も観測結果も空でない', function (): void {
+    expect(ExternalFakeDeclaration::swaps())->not->toBeEmpty()
+        ->and(externalFakeProbeResolved(externalFakeProbeRun('fake')['output']))->not->toBeEmpty();
+});
+
+test('P-6 一時環境ファイルのキー集合が許可集合の部分集合である', function (): void {
+    $keys = array_keys(externalFakeProbeRun('fake')['envFileValues']);
+
+    expect($keys)->not->toBeEmpty()
+        ->and(array_values(array_diff($keys, FakeWiringProbeRunner::ALLOWED_ENV_FILE_KEYS)))->toBe([]);
+});
+
+test('P-7 子が実際に受け取ったプロセス環境が許可した 3 件ちょうどである', function (): void {
+    $keys = externalFakeProbeRun('fake')['output']['process_environment_keys'] ?? null;
+    expect($keys)->toBeArray();
+    /** @var list<mixed> $keys */
+    $actual = array_map(static fn (mixed $key): string => (string) $key, $keys);
+
+    // (b) 危険な接頭辞が 1 件も無いこと
+    foreach (['DB_', 'PG', 'AWS_', 'STRIPE_', 'TESTING_FAKE_', 'GOOGLE_'] as $prefix) {
+        $leaked = array_values(array_filter(
+            $actual,
+            static fn (string $key): bool => str_starts_with($key, $prefix)
+        ));
+        expect($leaked)->toBe([], "禁止する接頭辞 {$prefix} のキーが子へ流れている");
+    }
+
+    // (a)(c) 許可した 3 件がすべて存在し、それ以外の余りが無いこと (deny-by-default)
+    $expected = FakeWiringProbeRunner::ALLOWED_PROCESS_ENV_KEYS;
+    sort($actual);
+    sort($expected);
+
+    expect($actual)->toBe($expected);
+});
+
+test('P-8 一時環境ファイルの鍵は親の設定値の複写ではない', function (): void {
+    $values = externalFakeProbeRun('fake')['envFileValues'];
+
+    expect($values['APP_KEY'] ?? null)->not->toBe(config('app.key'))
+        ->and($values['CIPHERSWEET_KEY'] ?? null)->not->toBe(config('ciphersweet.providers.string.key'));
+});
+
+test('P-9 一時ディレクトリ 0700 / 環境ファイル 0600 であり、違えば子を起こさない', function (): void {
+    $run = externalFakeProbeRun('fake');
+
+    expect($run['directoryMode'])->toBe(0700)
+        ->and($run['envFileMode'])->toBe(0600);
+
+    // 権限が緩い状態では子を起こさずに失敗すること (負のコントロール)。
+    expect(fn () => FakeWiringProbeRunner::assertSafePermissions(0755, 0600))
+        ->toThrow(RuntimeException::class);
+    expect(fn () => FakeWiringProbeRunner::assertSafePermissions(0700, 0644))
+        ->toThrow(RuntimeException::class);
+});
+
+test('P-10 正常終了・非ゼロ終了・timeout のいずれでも一時ディレクトリが残らない', function (): void {
+    foreach (['fake', 'real', 'production'] as $case) {
+        $run = externalFakeProbeRun($case);
+
+        expect(is_dir($run['directory']))->toBeFalse("一時ディレクトリが残っている: {$case}")
+            ->and(array_values(array_diff(scandir($run['baseDirectory']) ?: [], ['.', '..'])))
+            ->toBe([], "一時ディレクトリの親に残骸がある: {$case}");
+    }
+
+    // timeout でも finally を必ず通ること。
+    $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
+    expect(mkdir($base, 0700))->toBeTrue();
+
+    try {
+        expect(fn (): array => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base, 0.01))
+            ->toThrow(ProcessTimedOutException::class);
+
+        expect(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
+    } finally {
+        rmdir($base);
+    }
+});
+
+test('P-11 設定キャッシュの指し先は一時ディレクトリ配下の絶対パスで、存在しない', function (): void {
+    $run = externalFakeProbeRun('fake');
+
+    expect(str_starts_with($run['configCachePath'], '/'))->toBeTrue()
+        ->and(str_starts_with($run['configCachePath'], $run['directory'].'/'))->toBeTrue()
+        ->and($run['configCacheExists'])->toBeFalse();
+});
+
+test('P-12 宣言の型: 観測が読む swaps() は ExternalFakeBinding の列である', function (): void {
+    foreach (ExternalFakeDeclaration::swaps() as $swap) {
+        expect($swap)->toBeInstanceOf(ExternalFakeBinding::class);
+    }
+});
diff --git a/tests/Architecture/ExternalFakeWiringInvariantTest.php b/tests/Architecture/ExternalFakeWiringInvariantTest.php
index 6af63a2..c9c0568 100644
--- a/tests/Architecture/ExternalFakeWiringInvariantTest.php
+++ b/tests/Architecture/ExternalFakeWiringInvariantTest.php
@@ -2,17 +2,28 @@
 
 declare(strict_types=1);
 
+use App\Http\Controllers\Testing\GetFakeStorageObjectController;
+use App\Http\Controllers\Testing\PutFakeStorageObjectController;
 use App\Providers\AppServiceProvider;
 use App\Providers\FakeExternalsServiceProvider;
+use App\Services\AI\Testing\CannedPromptFakeRegistrar;
+use App\Services\Auth\SocialiteDriverResolver;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\TicketCheckoutGateway;
+use App\Services\Captcha\RecaptchaVerifier;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Render\RenderObjectStorage;
+use App\Support\ExternalFakes\ExternalFakeBinding;
+use App\Support\ExternalFakes\ExternalFakeDeclaration;
+use App\Support\FakeStorageGate;
 use Illuminate\Support\Facades\Log;
 use Kent013\PrismPrompt\Prompt;
-use Tests\Support\ExternalFakes\ExternalFakeBinding;
-use Tests\Support\ExternalFakes\ExternalFakeWiringInventory;
 use Tests\Support\ExternalFakes\FakeClassCatalog;
 use Tests\Support\ExternalFakes\FakeWiringSourceScanner;
 
 /*
- * 外部 fake 配線の実証 gate (c2c: external-fakes-wiring-gate 柱 1)。
+ * 偽の外部サービスの配線の実証 gate (c2c: external-fakes-wiring-gate 柱 1)。
  *
  * Laravel は abstract が具象クラスなら設定が無くても自動組み立てするため、
  * **差し替えの登録漏れは例外にならず、本物が静かに動く**。
@@ -23,6 +34,11 @@
  * (FakeTakeObjectStorage は TakeObjectStorage を継承しているため、instanceof では
  *  fake でも real 判定が通ってしまう = 対照実行が無意味になる)。
  *
+ * ★宣言の正本は App\Support\ExternalFakes\ExternalFakeDeclaration (本番側) である。
+ *   かつて同じ集合をテスト側にも書き、provider のソースを走査して集合一致を確かめる検査
+ *   (旧 3-8) を持っていたが、**差し替え先の決定が宣言 1 か所になったので比較する相手が
+ *   無くなった**ため削除した。宣言から entry を消す変異を映すのは 3-16 だけである。
+ *
  * 責務境界: 本番混入防止の正本は ProductionEnvGuard (+ ProductionEnvGuardTest)。
  * 本 gate は非本番側の配線だけを見る。
  *
@@ -42,8 +58,9 @@
 
 /*
  * ソース走査系 mutation (M3〜M7) の被覆表。
- * M1 / M2 (inventory entry の bind 削除) は 3-2 の data-driven 解決検査が自動被覆するため
- * 本 map の対象外 (entry を足せば検査も自動で増える構造になっている)。
+ * M1 / M2 (宣言 entry の削除) は 3-2 の data-driven 解決検査が自動被覆する…のではなく
+ * **3-16 の件数付き pin だけ**が映す (entry を消すと provider の bind もデータセットも
+ * 同時に縮むため。詳細は 3-16 のコメント)。
  *
  * 定数名は他の Architecture テストと衝突しないよう prefix する
  * (Pest のファイル直下 const / function はグローバル空間に出る)。
@@ -51,14 +68,32 @@
 const EXTERNAL_FAKE_WIRING_MUTATION_COVERAGE = [
     'M3' => 'bootstrap/providers.php に FakeExternalsServiceProvider が登録されている',
     'M4' => 'FakeExternalsServiceProvider は AppServiceProvider より後に登録される (後勝ち)',
-    'M5' => 'provider の bind 組は inventory と集合一致する',
+    'M5' => 'provider は差し替え先のクラス名を 1 つも参照しない (決定は宣言側だけにある)',
     'M6' => 'provider の container 呼び出しは許可された形だけ',
     'M7' => '本番コードは fake クラスを参照しない (FakeClassReferenceInvariantTest が担当)',
 ];
 
 const EXTERNAL_FAKE_WIRING_SOURCE_MUTATION_IDS = ['M3', 'M4', 'M5', 'M6', 'M7'];
 
-/** fake 配線 provider のソース (走査系テストの共通入力。読み取り失敗は例外で落ちる) */
+/**
+ * 配線 provider が参照してよい配線基盤クラス (偽物の実体ではないもの)。
+ *
+ * 「provider が参照する偽物系クラス = 本集合ちょうど」を集合一致で検査する (3-10)。
+ * ここに載っていないクラスを provider が参照した時点で赤くなり、とくに
+ * **差し替え先 (swaps() の fake) が 1 つでも現れたら赤くなる**
+ * = 差し替え先の決定が宣言側にしか無いことの機械的な裏付けになる。
+ */
+const EXTERNAL_FAKE_WIRING_PROVIDER_REFERENCE_EXCEPTIONS = [
+    // LLM の偽物を立てる窓口 (container 配線を行わない)
+    CannedPromptFakeRegistrar::class,
+    // 偽の保存先の有効化条件 (container 配線を行わない)
+    FakeStorageGate::class,
+    // 偽の保存先の署名付き経路の受け口 (route action。container 配線を行わない)
+    PutFakeStorageObjectController::class,
+    GetFakeStorageObjectController::class,
+];
+
+/** 配線 provider のソース (走査系テストの共通入力。読み取り失敗は例外で落ちる) */
 function externalFakeWiringProviderSource(): string
 {
     return FakeClassCatalog::sourceOf('app/Providers/FakeExternalsServiceProvider.php');
@@ -85,13 +120,13 @@ function externalFakeWiringRegisteredProviders(): array
 });
 
 dataset('external fake bindings', function (): Generator {
-    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
+    foreach (ExternalFakeDeclaration::swaps() as $binding) {
         yield $binding->label() => [$binding];
     }
 });
 
 dataset('external fake bindings and allowed environments', function (): Generator {
-    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
+    foreach (ExternalFakeDeclaration::swaps() as $binding) {
         foreach ($binding->allowedEnvironments as $environment) {
             yield $binding->label().' @ '.$environment => [$binding, $environment];
         }
@@ -101,7 +136,7 @@ function externalFakeWiringRegisteredProviders(): array
 dataset('external fake bindings and denied environments', function (): Generator {
     // production だけでなく staging も見る = 「未知環境で誤設定されても fake しない」という
     // allowlist 方式の趣旨そのものを固定する。
-    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
+    foreach (ExternalFakeDeclaration::swaps() as $binding) {
         foreach (['production', 'staging'] as $environment) {
             yield $binding->label().' @ '.$environment => [$binding, $environment];
         }
@@ -157,20 +192,20 @@ function (ExternalFakeBinding $binding, string $environment): void {
 )->with('external fake bindings and denied environments');
 
 test('3-4 provider 単体: 外部サービス fake flag on + allowlist 外 env は warning を出す', function (): void {
-    $originalFlag = config(ExternalFakeWiringInventory::EXTERNALS_FLAG);
+    $originalFlag = config(ExternalFakeDeclaration::EXTERNALS_FLAG);
     $originalEnvironment = $this->app['env'];
 
     try {
         Log::spy();
 
         $this->app['env'] = 'staging';
-        config([ExternalFakeWiringInventory::EXTERNALS_FLAG => true]);
+        config([ExternalFakeDeclaration::EXTERNALS_FLAG => true]);
 
         (new FakeExternalsServiceProvider($this->app))->register();
 
         Log::shouldHaveReceived('warning')->once();
     } finally {
-        config([ExternalFakeWiringInventory::EXTERNALS_FLAG => $originalFlag]);
+        config([ExternalFakeDeclaration::EXTERNALS_FLAG => $originalFlag]);
         $this->app['env'] = $originalEnvironment;
     }
 });
@@ -194,27 +229,6 @@ function (ExternalFakeBinding $binding, string $environment): void {
     expect(array_key_exists(FakeExternalsServiceProvider::class, $this->app->getLoadedProviders()))->toBeTrue();
 });
 
-test('3-8 網羅性: provider の bind 組が inventory と集合一致する', function (): void {
-    $pairs = FakeWiringSourceScanner::bindPairs(externalFakeWiringProviderSource());
-
-    // closure 差し替え (concrete === null) は「厳密クラス一致で実証できない形」なので許さない
-    expect(array_filter($pairs, static fn (array $pair): bool => $pair['concrete'] === null))->toBe([]);
-
-    $actual = array_map(
-        static fn (array $pair): string => $pair['abstract'].' => '.$pair['concrete'],
-        $pairs
-    );
-    $expected = array_map(
-        static fn (ExternalFakeBinding $binding): string => $binding->abstract.' => '.$binding->fake,
-        ExternalFakeWiringInventory::bindings()
-    );
-
-    sort($actual);
-    sort($expected);
-
-    expect($actual)->toBe($expected);
-});
-
 test('3-9 網羅性: provider の container 呼び出しは許可された形だけ', function (): void {
     $source = externalFakeWiringProviderSource();
 
@@ -222,30 +236,33 @@ function (ExternalFakeBinding $binding, string $environment): void {
         ->and(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toBe([]);
 });
 
-test('3-10 網羅性: provider が参照する fake 系クラスは inventory + 明示例外に一致する', function (): void {
+test('3-10 網羅性: provider が参照する fake 系クラスは配線基盤 4 件ちょうど (差し替え先を含まない)', function (): void {
     $candidates = array_values(array_unique(array_merge(
         FakeClassCatalog::implementationClasses(),
         FakeClassCatalog::namedClasses(),
     )));
 
-    $actual = FakeWiringSourceScanner::referencedClasses(externalFakeWiringProviderSource(), $candidates);
+    // 走査器 / 母集団導出が壊れて「空走査で緑」になるのを防ぐ (fail-closed)
+    expect($candidates)->not->toBeEmpty();
 
-    $expected = array_merge(
-        array_map(
-            static fn (ExternalFakeBinding $binding): string => $binding->fake,
-            ExternalFakeWiringInventory::bindings()
-        ),
-        ExternalFakeWiringInventory::providerReferenceExceptions(),
-    );
+    $actual = FakeWiringSourceScanner::referencedClasses(externalFakeWiringProviderSource(), $candidates);
+    $expected = EXTERNAL_FAKE_WIRING_PROVIDER_REFERENCE_EXCEPTIONS;
 
     sort($actual);
     sort($expected);
 
     expect($actual)->toBe($expected);
+
+    // 差し替え先が 1 つでも provider に現れたら赤くする (決定は宣言側にしか無い)。
+    $fakes = array_map(
+        static fn (ExternalFakeBinding $binding): string => $binding->fake,
+        ExternalFakeDeclaration::swaps()
+    );
+    expect(array_values(array_intersect($actual, $fakes)))->toBe([]);
 });
 
 test('3-11 LLM: bughunt.local ∧ fake_llm=true でのみ Prompt fake が立ち、stopFaking で戻る', function (): void {
-    $originalFlag = config(ExternalFakeWiringInventory::LLM_FLAG);
+    $originalFlag = config(ExternalFakeDeclaration::LLM_FLAG);
     $originalEnvironment = $this->app['env'];
 
     try {
@@ -253,7 +270,7 @@ function (ExternalFakeBinding $binding, string $environment): void {
 
         // (1) bughunt.local ∧ on → 立つ
         $this->app['env'] = 'bughunt.local';
-        config([ExternalFakeWiringInventory::LLM_FLAG => true]);
+        config([ExternalFakeDeclaration::LLM_FLAG => true]);
         (new FakeExternalsServiceProvider($this->app))->boot();
         expect(Prompt::isFaking())->toBeTrue();
 
@@ -271,7 +288,7 @@ function (ExternalFakeBinding $binding, string $environment): void {
 
         // (4) bughunt.local ∧ off → 立たない (既定 real LLM)
         $this->app['env'] = 'bughunt.local';
-        config([ExternalFakeWiringInventory::LLM_FLAG => false]);
+        config([ExternalFakeDeclaration::LLM_FLAG => false]);
         (new FakeExternalsServiceProvider($this->app))->boot();
         expect(Prompt::isFaking())->toBeFalse();
     } finally {
@@ -281,7 +298,7 @@ function (ExternalFakeBinding $binding, string $environment): void {
         }
         expect(Prompt::isFaking())->toBeFalse();
 
-        config([ExternalFakeWiringInventory::LLM_FLAG => $originalFlag]);
+        config([ExternalFakeDeclaration::LLM_FLAG => $originalFlag]);
         $this->app['env'] = $originalEnvironment;
     }
 });
@@ -295,3 +312,94 @@ function (ExternalFakeBinding $binding, string $environment): void {
 
     expect($keys)->toBe($ids);
 });
+
+test('3-13 宣言の健全性: abstract に重複が無く、許可環境は capability の部分集合である', function (): void {
+    $swaps = ExternalFakeDeclaration::swaps();
+    expect($swaps)->not->toBeEmpty();
+
+    $abstracts = array_map(
+        static fn (ExternalFakeBinding $binding): string => $binding->abstract,
+        $swaps
+    );
+    expect(array_values(array_unique($abstracts)))->toBe($abstracts);
+
+    foreach ($swaps as $binding) {
+        // 未宣言の flag は capabilityEnvironments() が例外にする (黙って空集合へ倒さない)。
+        $capability = ExternalFakeDeclaration::capabilityEnvironments($binding->flag);
+
+        expect($binding->allowedEnvironments)->not->toBeEmpty()
+            ->and(array_values(array_diff($binding->allowedEnvironments, $capability)))
+            ->toBe([], "{$binding->abstract} の許可環境が capability の許可環境を超えている");
+    }
+});
+
+test('3-14 差し替えない対象: neverSwapped() は swaps() の abstract と 1 件も交わらない', function (): void {
+    $neverSwapped = array_keys(ExternalFakeDeclaration::neverSwapped());
+
+    // 空集合で緑にしない (宣言そのものが消えたら赤くする)
+    expect($neverSwapped)->not->toBeEmpty();
+
+    foreach (ExternalFakeDeclaration::neverSwapped() as $class => $reason) {
+        expect(class_exists($class) || interface_exists($class))->toBeTrue("実在しないクラス: {$class}")
+            ->and(mb_strlen($reason))->toBeGreaterThanOrEqual(30);
+    }
+
+    $abstracts = array_map(
+        static fn (ExternalFakeBinding $binding): string => $binding->abstract,
+        ExternalFakeDeclaration::swaps()
+    );
+
+    expect(array_values(array_intersect($neverSwapped, $abstracts)))->toBe([]);
+});
+
+test('3-15 設定との一致: 宣言の flag が config に実在し、config 側に宣言外の偽物 flag が無い', function (): void {
+    $variables = ExternalFakeDeclaration::FLAG_ENVIRONMENT_VARIABLES;
+    expect($variables)->not->toBeEmpty();
+
+    // (a) 宣言した config キーが実在すること (キー名の typo を黙って通さない)。
+    foreach ($variables as $flag => $variable) {
+        expect(str_starts_with($flag, 'testing.'))->toBeTrue("capability flag は testing.* であること: {$flag}")
+            ->and(config()->has($flag))->toBeTrue("config に存在しない capability flag: {$flag}");
+    }
+
+    // (b) config/testing.php に現れる TESTING_FAKE_* の集合が宣言と一致すること
+    //     (宣言の外に偽物のフラグが増えたらその場で落とす)。
+    //     ★config('testing') 全体との完全一致は要求しない — 偽物と無関係な testing 設定を
+    //       将来足せなくなるため。
+    $matches = [];
+    preg_match_all(
+        '/TESTING_FAKE_[A-Z_]+/',
+        FakeClassCatalog::sourceOf('config/testing.php'),
+        $matches
+    );
+
+    $found = array_values(array_unique($matches[0]));
+    $declared = array_values($variables);
+    sort($found);
+    sort($declared);
+
+    expect($found)->toBe($declared);
+});
+
+test('3-16 宣言集合の固定 (意図的な摩擦): abstract 一覧が件数付きで一致する', function (): void {
+    // ★この検査を消すと「宣言から entry を消す」変異が**どこにも映らなくなる**。
+    //   宣言が唯一の正本なので、entry を消すと provider の bind もデータセットも同時に縮み、
+    //   3-1〜3-3 は縮んだ母集団のまま緑になる。映すには「宣言とは独立にもう一度書いた一覧」が要る
+    //   (同じ作法の先例: FakeClassReferenceInvariantTest の 4-2 / 4-4)。
+    //   増減させるときは宣言と本 test の 2 か所を同時に触ること。
+    $abstracts = array_map(
+        static fn (ExternalFakeBinding $binding): string => $binding->abstract,
+        ExternalFakeDeclaration::swaps()
+    );
+
+    expect($abstracts)->toHaveCount(7)
+        ->and($abstracts)->toBe([
+            TicketCheckoutGateway::class,
+            StripeGatewayInterface::class,
+            AutoRechargeGatewayInterface::class,
+            TakeObjectStorage::class,
+            RenderObjectStorage::class,
+            RecaptchaVerifier::class,
+            SocialiteDriverResolver::class,
+        ]);
+});
diff --git a/tests/Architecture/FakeClassReferenceInvariantTest.php b/tests/Architecture/FakeClassReferenceInvariantTest.php
index d7aa2de..ce4116a 100644
--- a/tests/Architecture/FakeClassReferenceInvariantTest.php
+++ b/tests/Architecture/FakeClassReferenceInvariantTest.php
@@ -28,7 +28,10 @@
 
 /** 参照 allowlist: fake 系クラスを参照してよい本番ファイル (**repo ルート相対**) */
 const FAKE_REFERENCE_ALLOWED = [
-    // 唯一の配線点 (何を fake にするかの決定はここに集約する)
+    // 何を偽物にするかの決定の唯一の正本 (差し替え先のクラス名はここにだけ現れる)
+    'app/Support/ExternalFakes/ExternalFakeDeclaration.php',
+    // 唯一の配線点。差し替え先は宣言から読むので、ここに現れる偽物系クラスは
+    // 配線基盤の 4 件だけである (ExternalFakeWiringInvariantTest の 3-10 が集合で固定する)
     'app/Providers/FakeExternalsServiceProvider.php',
     // fake storage signed route の受け口 (FakeStorageGate 成立時のみ route 登録される)
     'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
@@ -99,10 +102,11 @@
     expect($violations)->toBe([]);
 });
 
-test('4-4 参照 allowlist は 5 件から増えていない', function (): void {
+test('4-4 参照 allowlist は 6 件から増えていない', function (): void {
     // 増やすときは理由コメントを添えて**ここも触る** (意図的な摩擦)。
-    expect(FAKE_REFERENCE_ALLOWED)->toHaveCount(5)
+    expect(FAKE_REFERENCE_ALLOWED)->toHaveCount(6)
         ->and(FAKE_REFERENCE_ALLOWED)->toBe([
+            'app/Support/ExternalFakes/ExternalFakeDeclaration.php',
             'app/Providers/FakeExternalsServiceProvider.php',
             'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
             'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
diff --git a/tests/Architecture/LaneExternalFakeBindingTest.php b/tests/Architecture/LaneExternalFakeBindingTest.php
new file mode 100644
index 0000000..b576164
--- /dev/null
+++ b/tests/Architecture/LaneExternalFakeBindingTest.php
@@ -0,0 +1,95 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\ExternalFakes\FakeClassCatalog;
+use Tests\Support\ExternalFakes\FakeWiringSourceScanner;
+use Tests\Support\TrackedPhpSourceFiles;
+
+/*
+ * レーン側 (tests/) から本番の偽の実装クラスを container へ直接結ぶことの静的禁止
+ * (正典 v1 の「差し替え処理を 1 本に集約し、レーン側からの直呼びを静的に禁じる」)。
+ *
+ * 差し替えの入口は「宣言 (App\Support\ExternalFakes\ExternalFakeDeclaration) +
+ * 配線 provider (FakeExternalsServiceProvider)」の 1 本だけである。レーン側で同じことを
+ * 書けると、宣言に載っていない差し替えがテストの中だけで成立し、
+ * 「宣言と実際の差し替えが一致している」という保証が意味を失う。
+ *
+ * ★per-test の代役 (tests/Support/Fake*) は対象外である。あれは Laravel 公式作法の
+ *   テストダブルであり、bug-hunt レーンの差し替えとは別概念である (思考原則 4)。
+ *   対象は **app/ 配下の偽の実装クラス**を container へ結ぶ形だけ。
+ *
+ * ★例外の登録簿は持たない。本番側の偽物を使いたくなったら宣言 + provider を通す
+ *   (赤くなるのは正しい摩擦である)。
+ *
+ * **保証範囲を誇張しない**: 読めるのは container へ到達する 4 形
+ * (`$this->app->bind` / `app()->bind` / `App::bind` / `Container::getInstance()->bind`) で、
+ * 第 2 引数が `::class` 定数のものだけである。変数経由の結び付け・`instance()` /
+ * `swap()`・モック機構経由には**沈黙する** (走査器の自己検査 5-24 / 5-25 が境界を固定する)。
+ */
+
+/**
+ * 走査対象 (git 追跡下の tests/ 配下の .php、repo ルート相対)。
+ *
+ * @return list<string>
+ */
+function laneExternalFakeScanFiles(): array
+{
+    $files = [];
+    foreach (TrackedPhpSourceFiles::all(FakeClassCatalog::repoRoot()) as $file) {
+        if (str_starts_with($file['relative'], 'tests/')) {
+            $files[] = $file['relative'];
+        }
+    }
+
+    return $files;
+}
+
+test('レーン側は app/ の偽の実装クラスを container へ直接結ばない', function (): void {
+    $fakes = FakeClassCatalog::implementationClasses();
+    $files = laneExternalFakeScanFiles();
+
+    // 母集団が空になったら「違反なし」ではなく赤にする (走査の故障を緑で見逃さない)。
+    expect($fakes)->not->toBeEmpty()
+        ->and($files)->not->toBeEmpty();
+
+    $violations = [];
+    foreach ($files as $file) {
+        foreach (FakeWiringSourceScanner::bindPairs(FakeClassCatalog::sourceOf($file)) as $pair) {
+            if ($pair['concrete'] !== null && in_array($pair['concrete'], $fakes, true)) {
+                $violations[] = $file.': '.$pair['abstract'].' => '.$pair['concrete'];
+            }
+        }
+    }
+
+    expect($violations)->toBe([]);
+});
+
+test('負のコントロール: レーン側の直接の結び付けを 4 形すべてで検出する', function (): void {
+    $fakes = FakeClassCatalog::implementationClasses();
+    expect($fakes)->not->toBeEmpty();
+
+    // 実在する偽の実装クラスを 1 つ選び、レーン側で結んだ体の合成ソースを作る。
+    $fake = $fakes[0];
+    $bodies = [
+        '$this->app->bind(\App\Demo\A::class, \\'.$fake.'::class);',
+        'app()->bind(\App\Demo\A::class, \\'.$fake.'::class);',
+        'App::bind(\App\Demo\A::class, \\'.$fake.'::class);',
+        'Container::getInstance()->bind(\App\Demo\A::class, \\'.$fake.'::class);',
+    ];
+
+    foreach ($bodies as $body) {
+        $source = "<?php\n\nnamespace Tests\\Demo;\n\n"
+            ."use Illuminate\\Container\\Container;\n"
+            ."use Illuminate\\Support\\Facades\\App;\n\n"
+            ."final class DemoTest\n{\n    public function run(): void\n    {\n"
+            ."        {$body}\n    }\n}\n";
+
+        $concretes = array_map(
+            static fn (array $pair): ?string => $pair['concrete'],
+            FakeWiringSourceScanner::bindPairs($source)
+        );
+
+        expect($concretes)->toBe([$fake], "レーン側の結び付けを読み取れない形がある: {$body}");
+    }
+});
diff --git a/tests/Feature/Billing/BillingCustomerSynchronizerTest.php b/tests/Feature/Billing/BillingCustomerSynchronizerTest.php
index 00751dc..4105d6d 100644
--- a/tests/Feature/Billing/BillingCustomerSynchronizerTest.php
+++ b/tests/Feature/Billing/BillingCustomerSynchronizerTest.php
@@ -8,7 +8,6 @@
 use App\Jobs\Billing\SyncBillingCustomerDetails;
 use App\Services\Billing\BillingCustomerSynchronizer;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
-use App\Services\Billing\Fakes\FakeStripeGateway;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Queue;
 use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;
@@ -159,7 +158,7 @@ function synchronizer(): BillingCustomerSynchronizer
 test('job は StripeGatewayInterface へ委譲する (fake bind 時は実 Stripe を叩かない)', function (): void {
     [$organization] = createOrganizationWithOwner();
     $organization->forceFill(['stripe_id' => 'cus_test_3'])->save();
-    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+    enableFakeExternals();
 
     // fake gateway の syncCustomerDetails は no-op。例外なく完走することを固定する
     (new SyncBillingCustomerDetails($organization))->handle(app(StripeGatewayInterface::class));
diff --git a/tests/Feature/Billing/BillingPageTest.php b/tests/Feature/Billing/BillingPageTest.php
index 91d2ec3..acf1c30 100644
--- a/tests/Feature/Billing/BillingPageTest.php
+++ b/tests/Feature/Billing/BillingPageTest.php
@@ -3,8 +3,6 @@
 declare(strict_types=1);
 
 use App\Models\User;
-use App\Services\Billing\Contracts\StripeGatewayInterface;
-use App\Services\Billing\Fakes\FakeStripeGateway;
 use App\Services\Billing\TicketLedgerService;
 use Illuminate\Support\Str;
 use Inertia\Testing\AssertableInertia as Assert;
@@ -111,7 +109,7 @@
 
 test('owner の checkout は fake gateway 経由で中立帰還 URL へ遷移する (happy path)', function (): void {
     [, $owner] = createOrganizationWithOwner();
-    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+    enableFakeExternals();
 
     $response = $this->actingAs($owner)->post('/billing/checkout', [
         'plan_code' => 'standard',
@@ -131,7 +129,7 @@
     // (未契約 / ActiveFreePlan の遮断は BillingPortalGuardTest が固定)。
     [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
     contractPaidPlan($organization);
-    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+    enableFakeExternals();
 
     $response = $this->actingAs($owner)->post('/billing/portal');
 
diff --git a/tests/Feature/Billing/BillingPlansPageTest.php b/tests/Feature/Billing/BillingPlansPageTest.php
index f1b80aa..23dcc02 100644
--- a/tests/Feature/Billing/BillingPlansPageTest.php
+++ b/tests/Feature/Billing/BillingPlansPageTest.php
@@ -3,8 +3,6 @@
 declare(strict_types=1);
 
 use App\Models\User;
-use App\Services\Billing\Contracts\StripeGatewayInterface;
-use App\Services\Billing\Fakes\FakeStripeGateway;
 use Illuminate\Support\Str;
 use Inertia\Testing\AssertableInertia;
 use Inertia\Testing\AssertableInertia as Assert;
@@ -94,7 +92,7 @@
 
 test('POST /billing/checkout は plan_code + subscription_attempt_token で成立する (P9 の冪等 token 必須)', function (): void {
     [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
-    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+    enableFakeExternals();
 
     $response = $this->actingAs($owner)->post('/billing/checkout', [
         'plan_code' => 'standard',
diff --git a/tests/Feature/Billing/BillingPortalGuardTest.php b/tests/Feature/Billing/BillingPortalGuardTest.php
index 9db12d0..ab796fb 100644
--- a/tests/Feature/Billing/BillingPortalGuardTest.php
+++ b/tests/Feature/Billing/BillingPortalGuardTest.php
@@ -2,9 +2,6 @@
 
 declare(strict_types=1);
 
-use App\Services\Billing\Contracts\StripeGatewayInterface;
-use App\Services\Billing\Fakes\FakeStripeGateway;
-
 /*
  * P8b (bs-11): Customer Portal の事前ガード。
  *
@@ -16,7 +13,7 @@
 test('未契約 org (サブスク行なし) の owner は portal に到達せず error flash で戻る', function (): void {
     [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
     // Fake gateway を bind しておき「呼ばれない」ことを到達判定に使う
-    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+    enableFakeExternals();
 
     $response = $this->from('/billing')->actingAs($owner)->post('/billing/portal');
 
@@ -29,7 +26,7 @@
 test('ActiveFreePlan (canceled サブスク行が残る) org の owner も portal に到達しない', function (): void {
     [$organization, $owner] = createOrganizationWithOwner(); // free_plan_code='personal'
     createFakeSubscription($organization, status: 'canceled');
-    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+    enableFakeExternals();
 
     $response = $this->from('/billing')->actingAs($owner)->post('/billing/portal');
 
@@ -40,7 +37,7 @@
 test('有償サブスクを持つ owner は従来どおり Portal URL へ遷移する', function (): void {
     [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
     contractPaidPlan($organization);
-    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+    enableFakeExternals();
 
     $response = $this->actingAs($owner)->post('/billing/portal');
 
diff --git a/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php b/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php
index 087885d..2f5a6f5 100644
--- a/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php
+++ b/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php
@@ -9,7 +9,6 @@
 use App\Models\Organization;
 use App\Models\User;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
-use App\Services\Billing\Fakes\FakeStripeGateway;
 use App\Services\Billing\SubscriptionService;
 use Carbon\CarbonImmutable;
 use Illuminate\Support\Str;
@@ -63,7 +62,7 @@ function startGuardCheckout(Organization $organization, User $user, ?Plan $plan
 }
 
 beforeEach(function (): void {
-    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+    enableFakeExternals();
 });
 
 test('非 production では未 sync の test mode Price でも checkout できる', function (): void {
diff --git a/tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php b/tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php
index 304332a..939df25 100644
--- a/tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php
+++ b/tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php
@@ -5,8 +5,8 @@
 use App\Providers\FakeExternalsServiceProvider;
 use App\Services\Captcha\RecaptchaVerifier;
 use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
+use App\Support\ExternalFakes\ExternalFakeDeclaration;
 use Illuminate\Support\Facades\Http;
-use Tests\Support\ExternalFakes\ExternalFakeWiringInventory;
 
 /*
  * captcha 到達点 (Google siteverify) の fake 配線を**外向き通信の有無**で固定する。
@@ -30,7 +30,7 @@ function recaptchaFakeSiteverify(): void
 }
 
 test('fake 配線時は secret があっても Google siteverify を叩かずに true を返す', function (): void {
-    $flag = ExternalFakeWiringInventory::EXTERNALS_FLAG;
+    $flag = ExternalFakeDeclaration::EXTERNALS_FLAG;
     $originalFlag = config($flag);
     $originalEnvironment = $this->app['env'];
     $originalSecret = config('services.recaptcha.secret_key');
@@ -56,7 +56,7 @@ function recaptchaFakeSiteverify(): void
 });
 
 test('flag off では secret がある限り siteverify へ 1 回だけ出る (負のコントロール)', function (): void {
-    $flag = ExternalFakeWiringInventory::EXTERNALS_FLAG;
+    $flag = ExternalFakeDeclaration::EXTERNALS_FLAG;
     $originalSecret = config('services.recaptcha.secret_key');
 
     try {
diff --git a/tests/Feature/Support/ProductionEnvGuardTest.php b/tests/Feature/Support/ProductionEnvGuardTest.php
index c5b0d6c..591aa1b 100644
--- a/tests/Feature/Support/ProductionEnvGuardTest.php
+++ b/tests/Feature/Support/ProductionEnvGuardTest.php
@@ -334,3 +334,133 @@
     expect($errors)->toHaveCount(1);
     expect($errors[0])->toContain('must be lists of strings');
 });
+
+/*
+ * 実環境変数の二重判定 (T177 施策 3)。
+ *
+ * 設定キャッシュを作った環境と出荷先が食い違うと、キャッシュ上は false でも、
+ * キャッシュが失われた起動で環境変数が読み直されて本番で偽物が立ちうる。
+ * そこで設定値とは独立に $_SERVER / $_ENV / getenv() の 3 経路を見る。
+ *
+ * ★原値の退避と復元は下のヘルパへ集約し、すべてのケースが try/finally で戻す
+ *   (putenv は空文字と未設定の差が環境で揺れるため、$_SERVER / $_ENV 側は
+ *    unset() と = '' を明示的に作り分ける)。
+ */
+
+/**
+ * 3 経路の原値を退避し、callback 実行後に必ず復元する。
+ *
+ * @param  array{server?: string, env?: string, putenv?: string}  $values  設定する経路と値
+ */
+function withRawEnvironmentValue(string $variable, array $values, Closure $callback): void
+{
+    $hadServer = array_key_exists($variable, $_SERVER);
+    $hadEnv = array_key_exists($variable, $_ENV);
+    $originalServer = $_SERVER[$variable] ?? null;
+    $originalEnv = $_ENV[$variable] ?? null;
+    $originalPutenv = getenv($variable);
+
+    try {
+        if (array_key_exists('server', $values)) {
+            $_SERVER[$variable] = $values['server'];
+        }
+        if (array_key_exists('env', $values)) {
+            $_ENV[$variable] = $values['env'];
+        }
+        if (array_key_exists('putenv', $values)) {
+            putenv("{$variable}={$values['putenv']}");
+        }
+
+        $callback();
+    } finally {
+        if ($hadServer) {
+            $_SERVER[$variable] = $originalServer;
+        } else {
+            unset($_SERVER[$variable]);
+        }
+
+        if ($hadEnv) {
+            $_ENV[$variable] = $originalEnv;
+        } else {
+            unset($_ENV[$variable]);
+        }
+
+        if ($originalPutenv === false) {
+            putenv($variable);
+        } else {
+            putenv("{$variable}={$originalPutenv}");
+        }
+    }
+}
+
+test('config が false でも $_SERVER に true が残っていれば violation', function (): void {
+    withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => 'true'], function (): void {
+        $errors = (new ProductionEnvGuard)->violations();
+        expect($errors)->toHaveCount(1);
+        expect($errors[0])->toContain('TESTING_FAKE_EXTERNALS');
+        expect($errors[0])->toContain('$_SERVER');
+    });
+});
+
+test('config が false でも $_ENV に true が残っていれば violation', function (): void {
+    withRawEnvironmentValue('TESTING_FAKE_LLM', ['env' => 'true'], function (): void {
+        $errors = (new ProductionEnvGuard)->violations();
+        expect($errors)->toHaveCount(1);
+        expect($errors[0])->toContain('$_ENV');
+    });
+});
+
+test('config が false でも getenv() に true が残っていれば violation', function (): void {
+    withRawEnvironmentValue('TESTING_FAKE_STORAGE', ['putenv' => 'true'], function (): void {
+        $errors = (new ProductionEnvGuard)->violations();
+        expect($errors)->toHaveCount(1);
+        expect($errors[0])->toContain('getenv()');
+    });
+});
+
+test('3 経路とも未設定なら violation は出ない', function (): void {
+    expect((new ProductionEnvGuard)->violations())->toBe([]);
+});
+
+test('無効と読める値 (false / 0 / 空文字) では violation は出ない', function (): void {
+    foreach (['false', 'FALSE', '(false)', '0', 'off', 'no', 'null', ''] as $value) {
+        withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => $value], function () use ($value): void {
+            expect((new ProductionEnvGuard)->violations())->toBe([], "無効と読めるはずの値: '{$value}'");
+        });
+    }
+});
+
+test('解釈できない値 (maybe / 非文字列) は安全側で violation', function (): void {
+    withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => 'maybe'], function (): void {
+        expect((new ProductionEnvGuard)->violations())->toHaveCount(1);
+    });
+
+    // 非文字列 (配列) も黙って捨てず違反にする。
+    $variable = 'TESTING_FAKE_EXTERNALS';
+    $had = array_key_exists($variable, $_SERVER);
+    try {
+        $_SERVER[$variable] = ['true'];
+        expect((new ProductionEnvGuard)->violations())->toHaveCount(1);
+    } finally {
+        if (! $had) {
+            unset($_SERVER[$variable]);
+        }
+    }
+});
+
+test('未設定 / 空文字 / false を別ケースとして固定する', function (): void {
+    $variable = 'TESTING_FAKE_STORAGE';
+
+    // 未設定: 判定対象にしない
+    expect((new ProductionEnvGuard)->violations())->toBe([]);
+
+    // 空文字: 無効と読む
+    withRawEnvironmentValue($variable, ['server' => ''], function (): void {
+        expect((new ProductionEnvGuard)->violations())->toBe([]);
+    });
+
+    // 'false': 無効と読む
+    withRawEnvironmentValue($variable, ['server' => 'false'], function (): void {
+        expect((new ProductionEnvGuard)->violations())->toBe([]);
+    });
+});
diff --git a/tests/Pest.php b/tests/Pest.php
index 87db412..30584db 100644
--- a/tests/Pest.php
+++ b/tests/Pest.php
@@ -18,6 +18,7 @@
 use App\Services\Recovery\StuckWorkRecoverySweeper;
 use App\Services\Recovery\StuckWorkStreamRegistry;
 use App\Services\Storage\Fakes\FakeObjectStore;
+use App\Support\ExternalFakes\ExternalFakeDeclaration;
 use Carbon\CarbonImmutable;
 use Illuminate\Foundation\Testing\RefreshDatabase;
 use Illuminate\Support\Facades\Storage;
@@ -335,7 +336,7 @@ function attachProjectMember(
  */
 function enableFakeStorage(): void
 {
-    config()->set('testing.fake_storage', true);
+    config()->set(ExternalFakeDeclaration::STORAGE_FLAG, true);
 
     $provider = new FakeExternalsServiceProvider(app());
     $provider->register();
@@ -347,3 +348,23 @@ function enableFakeStorage(): void
 
     Storage::fake(FakeObjectStore::DISK);
 }
+
+/**
+ * 宣言された外部サービスの偽物 (決済 gateway / 人間性確認 / 外部ログインの解決点) を
+ * レーンで有効にする。
+ *
+ * ★レーン側で個別の偽物を container へ直接結ばない。差し替えの入口は
+ *   「宣言 (ExternalFakeDeclaration) + 配線 provider」の 1 本だけであり、
+ *   レーンもその 1 本を共有する (LaneExternalFakeBindingTest が直結を静的に禁じる)。
+ * ★有効になるのは宣言のうち EXTERNALS_FLAG を持つ差し替え**全部**である
+ *   (1 つだけ選んで立てる口は用意しない = 宣言と実際の差し替えを一致させるため)。
+ *
+ * 各テストは setUp の refreshApplication で fresh app + fresh config を得るため、
+ * 明示的な後始末は不要 (テスト間リークしない)。
+ */
+function enableFakeExternals(): void
+{
+    config()->set(ExternalFakeDeclaration::EXTERNALS_FLAG, true);
+
+    (new FakeExternalsServiceProvider(app()))->register();
+}
diff --git a/tests/Support/Bughunt/BughuntSeedRole.php b/tests/Support/Bughunt/BughuntSeedRole.php
new file mode 100644
index 0000000..6a46690
--- /dev/null
+++ b/tests/Support/Bughunt/BughuntSeedRole.php
@@ -0,0 +1,26 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Bughunt;
+
+/**
+ * bug-hunt レーンにおける投入データ (seeder) の区分。
+ *
+ * 「bug-hunt で明示投入するか」と「環境ガードを要求するか」の 2 軸で分ける。
+ * 値を持つ必要が無いので backed enum にしない。
+ */
+enum BughuntSeedRole
+{
+    /** bug-hunt 環境専用。三重ガード必須・通常の投入経路 (DatabaseSeeder) には載せない */
+    case BughuntOnly;
+
+    /** 通常経路にも載るが bug-hunt でも明示投入する。環境ガード必須 */
+    case SharedWithBughunt;
+
+    /** 開発者が手で流す fixture。bug-hunt でも明示投入するがガードは要求しない */
+    case ManualFixture;
+
+    /** bug-hunt レーンでは明示投入しない (`migrate:fresh --seed` 経由か、そもそも流さない) */
+    case NotSeededInBughunt;
+}
diff --git a/tests/Support/Bughunt/BughuntSeedWiringInventory.php b/tests/Support/Bughunt/BughuntSeedWiringInventory.php
new file mode 100644
index 0000000..31a8cf3
--- /dev/null
+++ b/tests/Support/Bughunt/BughuntSeedWiringInventory.php
@@ -0,0 +1,141 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Bughunt;
+
+use Database\Seeders\AdminUserSeeder;
+use Database\Seeders\BughuntBillingSeeder;
+use Database\Seeders\BughuntOAuthSeeder;
+use Database\Seeders\DatabaseSeeder;
+use Database\Seeders\ManualTestSeeder;
+use Database\Seeders\PermissionSeeder;
+use Database\Seeders\PlanSeeder;
+use Database\Seeders\RolePermissionSeeder;
+use Database\Seeders\RoleSeeder;
+use Database\Seeders\TicketVolumePriceSeeder;
+
+/**
+ * database/seeders の全 seeder の区分目録 (deny-by-default・母集団は過不足なく一致)。
+ *
+ * 母集団を全 seeder に取るのは「登録しなければ検査対象から外れる」抜け道を作らないためで、
+ * bug-hunt に関係しない seeder の区分は 1 行で終わる。
+ *
+ * 固定する事故は 3 つ (概念設計の穴 2):
+ * 1. provision にだけ seeder を足して reseed に足し忘れる (子セッションの reseed で状態が消える)
+ * 2. bug-hunt 専用 seeder を DatabaseSeeder に足す (全環境の `migrate:fresh --seed` で走る)
+ * 3. 新しい bug-hunt 専用 seeder を環境ガード無しで足す (手元の db:seed で dev DB が汚れる)
+ */
+final class BughuntSeedWiringInventory
+{
+    /**
+     * 区分と理由の目録。
+     *
+     * `guardPremiseTest` はガードの論理 (かつ / または) を実際に動かして固定している
+     * 振る舞いテストのパス。静的走査は「判定語が条件に現れること」までしか見られず、
+     * `||` と `&&` の取り違えのような論理の退行は読めないため、ガードを要求する区分には
+     * 前提テストを必ず紐づける (免除の前提を振る舞いで固定する
+     * ThrottleExemptionPremiseTest / IdempotencyExemptionPremiseTest と同じ作法)。
+     * ガードを要求しない区分では null 固定 (値があったら赤)。
+     *
+     * @return array<class-string, array{
+     *     role: BughuntSeedRole,
+     *     reason: string,
+     *     guardPremiseTest: non-empty-string|null,
+     * }>
+     */
+    public static function entries(): array
+    {
+        return [
+            BughuntBillingSeeder::class => [
+                'role' => BughuntSeedRole::BughuntOnly,
+                'reason' => '有料プラン組織へ購読とチケットを投入する。通常経路に載せると開発 DB へ課金状態が漏れる。',
+                'guardPremiseTest' => 'tests/Feature/Database/BughuntBillingSeederTest.php',
+            ],
+            BughuntOAuthSeeder::class => [
+                'role' => BughuntSeedRole::BughuntOnly,
+                'reason' => 'CLI の認証状態と旧 MCP トークンを直付与する。通常経路に載せると開発 DB へ既知の資格情報が入る。',
+                'guardPremiseTest' => 'tests/Feature/Database/BughuntOAuthSeederGuardTest.php',
+            ],
+            AdminUserSeeder::class => [
+                'role' => BughuntSeedRole::SharedWithBughunt,
+                'reason' => '固定の管理者を作る。開発環境では通常経路に載るが、bug-hunt では管理画面の探索用に明示投入する。',
+                'guardPremiseTest' => 'tests/Feature/Admin/AdminUserSeederTest.php',
+            ],
+            ManualTestSeeder::class => [
+                'role' => BughuntSeedRole::ManualFixture,
+                'reason' => '手順書と動画マニュアルの見本を作る開発用 fixture。既知の資格情報を持たないためガードを要求しない。',
+                'guardPremiseTest' => null,
+            ],
+            DatabaseSeeder::class => [
+                'role' => BughuntSeedRole::NotSeededInBughunt,
+                'reason' => '通常経路の束ね役。bug-hunt では migrate:fresh --seed 経由で走るため明示投入しない。',
+                'guardPremiseTest' => null,
+            ],
+            RoleSeeder::class => [
+                'role' => BughuntSeedRole::NotSeededInBughunt,
+                'reason' => '役割の定義。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
+                'guardPremiseTest' => null,
+            ],
+            PermissionSeeder::class => [
+                'role' => BughuntSeedRole::NotSeededInBughunt,
+                'reason' => '権限の定義。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
+                'guardPremiseTest' => null,
+            ],
+            RolePermissionSeeder::class => [
+                'role' => BughuntSeedRole::NotSeededInBughunt,
+                'reason' => '役割と権限の紐付け。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
+                'guardPremiseTest' => null,
+            ],
+            PlanSeeder::class => [
+                'role' => BughuntSeedRole::NotSeededInBughunt,
+                'reason' => 'プラン定義。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
+                'guardPremiseTest' => null,
+            ],
+            TicketVolumePriceSeeder::class => [
+                'role' => BughuntSeedRole::NotSeededInBughunt,
+                'reason' => 'チケットの傾斜単価。通常経路 (DatabaseSeeder) に載るため bug-hunt で明示投入しない。',
+                'guardPremiseTest' => null,
+            ],
+        ];
+    }
+
+    /**
+     * bug-hunt レーンで明示投入する区分のクラス (provision / reseed の列と集合一致させる相手)。
+     *
+     * @return list<class-string>
+     */
+    public static function seededInBughunt(): array
+    {
+        $classes = [];
+        foreach (self::entries() as $class => $entry) {
+            if ($entry['role'] !== BughuntSeedRole::NotSeededInBughunt) {
+                $classes[] = $class;
+            }
+        }
+
+        return $classes;
+    }
+
+    /**
+     * 区分ごとに `run()` の最初の実効文の条件へ要求する判定語。
+     *
+     * ★見るのは「語が条件に現れること」までである。条件の論理 (かつ / または) までは見ない
+     *   — 現行の 2 本はいずれも「否定の論理和 → 早期 return」の形であり、`&&` を要求する
+     *   検査は**誤り**になる。論理そのものは guardPremiseTest が振る舞いで固定する。
+     *
+     * @return list<string>
+     */
+    public static function requiredGuardMarkers(BughuntSeedRole $role): array
+    {
+        return match ($role) {
+            BughuntSeedRole::BughuntOnly => [
+                'EXTERNALS_FLAG',
+                "environment('bughunt.local')",
+                'isBughuntDatabase',
+            ],
+            BughuntSeedRole::SharedWithBughunt => ['shouldSeed'],
+            BughuntSeedRole::ManualFixture, BughuntSeedRole::NotSeededInBughunt => [],
+        };
+    }
+}
diff --git a/tests/Support/Bughunt/ShellFunctionWindow.php b/tests/Support/Bughunt/ShellFunctionWindow.php
new file mode 100644
index 0000000..83cf7b3
--- /dev/null
+++ b/tests/Support/Bughunt/ShellFunctionWindow.php
@@ -0,0 +1,49 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Bughunt;
+
+use InvalidArgumentException;
+use RuntimeException;
+
+/**
+ * bug-hunt のシェルスクリプトから `cmd_*` 関数の窓を切り出す純関数 (**`cmd_` で始まる関数専用**)。
+ *
+ * 終端を「次の `^cmd_` 定義 (または末尾)」に取るため、`cmd_` 以外の関数へ使うと
+ * 後続の関数を巻き込む。誤用を防ぐため、名前が `cmd_` で始まらなければ例外にする。
+ *
+ * 非貪欲な `\n\}` 終端は使わない: 関数本体がヒアドキュメント (`<<'PY'` 等) 内に
+ * 行頭 `}` を持つと最短一致がそこで止まり、真の末尾を取り逃す。
+ *
+ * 見つからないときも例外にする (静かに空文字を返して緑にしない)。
+ */
+final class ShellFunctionWindow
+{
+    /**
+     * `cmd_<名前>()` の定義行から次の `^cmd_` 定義 (または末尾) までを返す。
+     */
+    public static function ofCommand(string $source, string $commandFunction): string
+    {
+        if (! str_starts_with($commandFunction, 'cmd_')) {
+            throw new InvalidArgumentException(
+                "ofCommand() は cmd_ で始まる関数専用である (次の cmd_ 定義まで切り出すため): {$commandFunction}"
+            );
+        }
+
+        $matches = [];
+        // cmd_provision と cmd_provision_all を取り違えないよう `()` まで含めてアンカーする。
+        $matched = preg_match(
+            '/^'.preg_quote($commandFunction, '/').'\(\)[\s\S]*?(?=^cmd_|\z)/m',
+            $source,
+            $matches
+        );
+
+        if ($matched !== 1) {
+            throw new RuntimeException("シェル関数の窓が見つからない: {$commandFunction}");
+        }
+
+        /** @var array{0: string} $matches */
+        return $matches[0];
+    }
+}
diff --git a/tests/Support/ExternalFakes/ExternalFakeWiringInventory.php b/tests/Support/ExternalFakes/ExternalFakeWiringInventory.php
deleted file mode 100644
index ca40dac..0000000
--- a/tests/Support/ExternalFakes/ExternalFakeWiringInventory.php
+++ /dev/null
@@ -1,165 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace Tests\Support\ExternalFakes;
-
-use App\Http\Controllers\Testing\GetFakeStorageObjectController;
-use App\Http\Controllers\Testing\PutFakeStorageObjectController;
-use App\Services\AI\Testing\CannedPromptFakeRegistrar;
-use App\Services\Auth\Fakes\FakeSocialiteDriverResolver;
-use App\Services\Auth\SocialiteDriverResolver;
-use App\Services\Billing\CashierAutoRechargeGateway;
-use App\Services\Billing\CashierStripeGateway;
-use App\Services\Billing\CashierTicketCheckoutGateway;
-use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
-use App\Services\Billing\Contracts\StripeGatewayInterface;
-use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
-use App\Services\Billing\Fakes\FakeStripeGateway;
-use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
-use App\Services\Billing\TicketCheckoutGateway;
-use App\Services\Captcha\RecaptchaVerifier;
-use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
-use App\Services\Capture\Fakes\FakeTakeObjectStorage;
-use App\Services\Capture\TakeObjectStorage;
-use App\Services\Render\Fakes\FakeRenderObjectStorage;
-use App\Services\Render\RenderObjectStorage;
-use App\Support\FakeStorageGate;
-
-/**
- * 外部 fake の container 差し替え inventory (deny-by-default の正本)。
- *
- * 責務境界:
- * - 本 inventory と ExternalFakeWiringInvariantTest が見るのは **非本番側の配線**だけ。
- * - **本番混入防止の正本は `App\Support\ProductionEnvGuard`** (配備前 = production:preflight /
- *   起動時 = AppServiceProvider::boot の 2 経路) + `tests/Feature/Support/ProductionEnvGuardTest`。
- *   ここで二重実装しない。
- * - LLM (Prism) fake は container ではなく `Prompt::$fake` (プロセスグローバル static) を書き換える
- *   ため inventory の対象外 (ExternalFakeWiringInvariantTest の 3-11 が別枠で見る)。
- */
-final class ExternalFakeWiringInventory
-{
-    /** 外部サービス fake (Stripe 課金 + captcha) の capability flag */
-    public const string EXTERNALS_FLAG = 'testing.fake_externals';
-
-    /** storage fake の capability flag */
-    public const string STORAGE_FLAG = 'testing.fake_storage';
-
-    /** LLM fake の capability flag (container 差し替えではないため bindings() には現れない) */
-    public const string LLM_FLAG = 'testing.fake_llm';
-
-    /** 外部サービス fake の env allowlist (FakeExternalsServiceProvider::EXTERNAL_FAKE_ENVIRONMENTS と対) */
-    private const array EXTERNAL_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];
-
-    /** storage fake の env allowlist (FakeStorageGate の predicate と対。testing は runningUnitTests 前提) */
-    private const array STORAGE_ENVIRONMENTS = ['testing', 'bughunt.local'];
-
-    /**
-     * SSO fake の env allowlist (FakeExternalsServiceProvider::SSO_FAKE_ENVIRONMENTS と対)。
-     *
-     * ★`local` を含めない。SSO fake は未認証 GET 2 本で canned アカウントへログインできる
-     *   = 認証バイパスであり、かつ local は実 IdP 連携を確認する唯一の環境である。
-     */
-    private const array SSO_ENVIRONMENTS = ['testing', 'bughunt.local'];
-
-    /**
-     * fake の実体ではないが FakeExternalsServiceProvider が参照してよい配線基盤クラス。
-     *
-     * 「provider が参照する fake 系クラス = bindings() の fake ∪ 本集合」を集合一致で検査するため、
-     * ここに載っていないクラスを provider が参照した時点で gate が赤くなる。
-     *
-     * @return list<class-string>
-     */
-    public static function providerReferenceExceptions(): array
-    {
-        return [
-            // LLM static fake の install 窓口 (container 配線を行わない)
-            CannedPromptFakeRegistrar::class,
-            // storage fake の有効化 predicate (SSOT。container 配線を行わない)
-            FakeStorageGate::class,
-            // fake storage signed route の受け口 (route action。container 配線を行わない)
-            PutFakeStorageObjectController::class,
-            GetFakeStorageObjectController::class,
-        ];
-    }
-
-    /**
-     * container 差し替えの全宣言。
-     *
-     * ここに entry を足すと、ExternalFakeWiringInvariantTest の data-driven 検査
-     * (対照 / 実証 / allowlist 外) が自動的に増える = 書き忘れが構造的に起きない。
-     *
-     * ⚠️ 新 entry を足す実装者へ: Architecture lane は RefreshDatabase を使わない。
-     * abstract / real / fake の constructor が DB に触れないことを必ず確認すること
-     * (現行 5 本は確認済み)。
-     *
-     * @return list<ExternalFakeBinding>
-     */
-    public static function bindings(): array
-    {
-        return [
-            new ExternalFakeBinding(
-                abstract: TicketCheckoutGateway::class,
-                real: CashierTicketCheckoutGateway::class,
-                fake: FakeTicketCheckoutGateway::class,
-                flag: self::EXTERNALS_FLAG,
-                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
-                risk: 'チケットスポット購入の Stripe Checkout。配線が外れると実 Stripe に実課金セッションを作る。',
-            ),
-            new ExternalFakeBinding(
-                abstract: StripeGatewayInterface::class,
-                real: CashierStripeGateway::class,
-                fake: FakeStripeGateway::class,
-                flag: self::EXTERNALS_FLAG,
-                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
-                risk: 'サブスク Checkout / Customer Portal。配線が外れると実 Stripe に契約を作る。',
-            ),
-            new ExternalFakeBinding(
-                abstract: AutoRechargeGatewayInterface::class,
-                real: CashierAutoRechargeGateway::class,
-                fake: FakeAutoRechargeGateway::class,
-                flag: self::EXTERNALS_FLAG,
-                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
-                risk: 'オートリチャージの off-session invoice。配線が外れると実カードへ請求が飛ぶ。',
-            ),
-            new ExternalFakeBinding(
-                abstract: TakeObjectStorage::class,
-                real: TakeObjectStorage::class,
-                fake: FakeTakeObjectStorage::class,
-                flag: self::STORAGE_FLAG,
-                allowedEnvironments: self::STORAGE_ENVIRONMENTS,
-                risk: '撮影テイクの S3 presign / HeadObject。abstract が具象クラスのため、'
-                    .'bind を消しても Laravel が本物を自動組み立てして無音で実 S3 を叩く。',
-            ),
-            new ExternalFakeBinding(
-                abstract: RenderObjectStorage::class,
-                real: RenderObjectStorage::class,
-                fake: FakeRenderObjectStorage::class,
-                flag: self::STORAGE_FLAG,
-                allowedEnvironments: self::STORAGE_ENVIRONMENTS,
-                risk: 'レンダ出力の S3 read/write。TakeObjectStorage と同じく具象クラス起点で無音になる。',
-            ),
-            new ExternalFakeBinding(
-                abstract: RecaptchaVerifier::class,
-                real: RecaptchaVerifier::class,
-                fake: RecaptchaVerifierTestFake::class,
-                flag: self::EXTERNALS_FLAG,
-                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
-                risk: 'Google reCAPTCHA siteverify への外向き POST。abstract が具象クラスのため、'
-                    .'bind を消しても Laravel が本物を自動組み立てし、RECAPTCHA_SECRET_KEY が'
-                    .'設定された環境では無言で実 Google を叩く (bug-hunt の別プロセスには '
-                    .'StrayHttpRequestGuard が効かない)。',
-            ),
-            new ExternalFakeBinding(
-                abstract: SocialiteDriverResolver::class,
-                real: SocialiteDriverResolver::class,
-                fake: FakeSocialiteDriverResolver::class,
-                flag: self::EXTERNALS_FLAG,
-                allowedEnvironments: self::SSO_ENVIRONMENTS,
-                risk: 'SSO (Socialite) の driver 解決点。abstract が具象クラスのため、bind を消しても '
-                    .'Laravel が本物を自動組み立てし、**無言で**実 IdP (accounts.google.com 等) への '
-                    .'リダイレクトに戻る。bug-hunt のブラウザは別プロセスなので StrayHttpRequestGuard は効かない。',
-            ),
-        ];
-    }
-}
diff --git a/tests/Support/ExternalFakes/FakeWiringProbeRunner.php b/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
new file mode 100644
index 0000000..8fac6a1
--- /dev/null
+++ b/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
@@ -0,0 +1,277 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\ExternalFakes;
+
+use RuntimeException;
+use Symfony\Component\Process\Process;
+
+/**
+ * 観測用スクリプト (fake-wiring-probe.php) を子プロセスで走らせる。
+ *
+ * 子の環境は**完全に作り直す** (親から引き継がない)。決め方は 3 段:
+ * 1. プロセスの環境変数は `env -i` で空にしてから、必要な分だけを渡す
+ *    (親のシェルに残った TESTING_FAKE_* に結果を左右されない。
+ *     bug-hunt のスクリプトが DB 資格情報を遮断するときと同じ手である)
+ * 2. 設定の出所は**専用の一時環境ファイル 1 つだけ**にする
+ *    (`FAKE_WIRING_PROBE_ENV_DIR` / `…_FILE` で子へ渡し、子が
+ *     `useEnvironmentPath()` / `loadEnvironmentFrom()` で固定する)。
+ *     親のチェックアウトの `.env` / `.env.bughunt.local` は**読ませない**
+ *     = 実 Stripe / 外部ログイン / S3 の資格情報は子の設定に 1 つも入らない
+ * 3. 設定キャッシュを無効化する。`APP_CONFIG_CACHE` を**存在しない一時パス**へ向け、
+ *    キャッシュ無しの起動として観測する (共有の bootstrap/cache を作ったり消したりしない =
+ *    並列実行と衝突しない)
+ *
+ * ★**親の実鍵を複写しない**。`APP_KEY` / `CIPHERSWEET_KEY` は起動のたびに
+ *   **使い捨ての値をその場で生成する** (観測は解決と経路の組み立てだけで、既存データの
+ *   復号も DB 接続もしないため実鍵は要らない)。これで一時ファイルは秘密を 1 つも持たない。
+ * ★それでも置き場所は保護する: 専用の一時ディレクトリを 0700 で作り、環境ファイルは
+ *   作成時点から 0600 にする。起動前に権限を確かめ、0600 でなければ**子を起こさずに失敗させる**。
+ *   後片付けは finally で行い、timeout・JSON の解釈失敗・Process の例外でも必ず通る。
+ *
+ * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
+ * キャッシュ有りの起動は観測しない (キャッシュが古いときの挙動は本観測の範囲外で、
+ * 本番混入防止は ProductionEnvGuard の二重判定が受け持つ)。
+ */
+final class FakeWiringProbeRunner
+{
+    /**
+     * 一時環境ファイルに書いてよいキー (deny-by-default)。
+     * 実資格情報のキーは 1 つも無く、鍵の 2 つは使い捨ての生成値である。
+     *
+     * @var list<string>
+     */
+    public const array ALLOWED_ENV_FILE_KEYS = [
+        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
+        'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
+    ];
+
+    /**
+     * 子プロセスへ渡してよい**プロセス環境変数**のキー (上とは別物なので定数を分ける)。
+     * `env -i` で空にしたうえでこの 3 つだけを載せる。
+     *
+     * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
+     *   probe が自分で観測して返す。両方を突き合わせて初めて `env -i` の退行が映る。
+     *
+     * @var list<string>
+     */
+    public const array ALLOWED_PROCESS_ENV_KEYS = [
+        'FAKE_WIRING_PROBE_ENV_DIR',
+        'FAKE_WIRING_PROBE_ENV_FILE',
+        // 設定キャッシュを無効化する (存在しない絶対パスを一時ディレクトリ配下に指す)
+        'APP_CONFIG_CACHE',
+    ];
+
+    /** 観測に使う自ホストの URL (実サーバは立てない。経路の組み立てにだけ使う) */
+    private const string PROBE_APP_URL = 'http://127.0.0.1:65535';
+
+    /** 環境ファイルの名前 (一時ディレクトリ内で固定) */
+    private const string ENV_FILE_NAME = '.env.probe';
+
+    /**
+     * 観測を 1 回走らせる。
+     *
+     * @param  string|null  $baseDirectory  一時ディレクトリを作る親 (省略時は sys_get_temp_dir())
+     * @return array{
+     *     exitCode: int,
+     *     output: array<string, mixed>,
+     *     envFileValues: array<string, string>,
+     *     directory: string,
+     *     directoryMode: int,
+     *     envFileMode: int,
+     *     configCachePath: string,
+     *     configCacheExists: bool,
+     * }
+     */
+    public static function run(
+        string $environment,
+        bool $fakeExternals,
+        bool $fakeStorage,
+        bool $fakeLlm,
+        ?string $baseDirectory = null,
+        float $timeout = 120.0,
+    ): array {
+        $base = $baseDirectory ?? sys_get_temp_dir();
+        $directory = $base.'/fake-wiring-probe-'.bin2hex(random_bytes(8));
+
+        if (! mkdir($directory, 0700) || ! is_dir($directory)) {
+            throw new RuntimeException("観測用の一時ディレクトリを作れない: {$directory}");
+        }
+
+        try {
+            chmod($directory, 0700);
+
+            $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
+            $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
+            self::writeEnvFile($envFilePath, $values);
+
+            $directoryMode = self::mode($directory);
+            $envFileMode = self::mode($envFilePath);
+
+            // 起動前に権限を確かめ、違えば子を起こさない (秘密を持たない設計だが置き場所は守る)。
+            self::assertSafePermissions($directoryMode, $envFileMode);
+
+            $configCachePath = $directory.'/config-cache-absent.php';
+
+            $process = new Process(
+                [
+                    'env', '-i',
+                    'FAKE_WIRING_PROBE_ENV_DIR='.$directory,
+                    'FAKE_WIRING_PROBE_ENV_FILE='.self::ENV_FILE_NAME,
+                    'APP_CONFIG_CACHE='.$configCachePath,
+                    PHP_BINARY,
+                    self::probeScriptPath(),
+                ],
+                FakeClassCatalog::repoRoot(),
+                null,
+                null,
+                $timeout,
+            );
+            $process->run();
+
+            return [
+                'exitCode' => $process->getExitCode() ?? -1,
+                'output' => self::decode($process->getOutput()),
+                'envFileValues' => $values,
+                'directory' => $directory,
+                'directoryMode' => $directoryMode,
+                'envFileMode' => $envFileMode,
+                'configCachePath' => $configCachePath,
+                'configCacheExists' => file_exists($configCachePath),
+            ];
+        } finally {
+            self::removeDirectory($directory);
+        }
+    }
+
+    /**
+     * 一時環境ファイルへ書く内容 (許可キー以外は 1 つも作らない)。
+     *
+     * @return array<string, string>
+     */
+    public static function envFileValues(
+        string $environment,
+        bool $fakeExternals,
+        bool $fakeStorage,
+        bool $fakeLlm,
+    ): array {
+        // 実鍵は複写せず、起動のたびに使い捨ての値を生成する。
+        // 形式は現行の設定が受理する形に合わせる (妥当性は「子が起動できたこと」自体が示す)。
+        $values = [
+            'APP_ENV' => $environment,
+            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
+            'APP_URL' => self::PROBE_APP_URL,
+            'APP_DEBUG' => 'false',
+            'CIPHERSWEET_KEY' => bin2hex(random_bytes(32)),
+            'TESTING_FAKE_EXTERNALS' => $fakeExternals ? 'true' : 'false',
+            'TESTING_FAKE_STORAGE' => $fakeStorage ? 'true' : 'false',
+            'TESTING_FAKE_LLM' => $fakeLlm ? 'true' : 'false',
+        ];
+
+        foreach (array_keys($values) as $key) {
+            if (! in_array($key, self::ALLOWED_ENV_FILE_KEYS, true)) {
+                throw new RuntimeException("一時環境ファイルへ書けないキー: {$key}");
+            }
+        }
+
+        return $values;
+    }
+
+    /**
+     * 一時ディレクトリ 0700 / 環境ファイル 0600 でなければ例外にする (子を起こさない)。
+     */
+    public static function assertSafePermissions(int $directoryMode, int $envFileMode): void
+    {
+        if ($directoryMode !== 0700 || $envFileMode !== 0600) {
+            throw new RuntimeException(
+                '観測用の一時ファイルの権限が想定と違うため子プロセスを起こさない ('
+                .sprintf('dir=%04o file=%04o', $directoryMode, $envFileMode).')'
+            );
+        }
+    }
+
+    /** 観測用スクリプトの絶対パス */
+    public static function probeScriptPath(): string
+    {
+        return __DIR__.'/fake-wiring-probe.php';
+    }
+
+    /** 観測が組み立てる自ホストの host 部 (転送先の照合に使う) */
+    public static function probeAppHost(): string
+    {
+        $host = parse_url(self::PROBE_APP_URL, PHP_URL_HOST);
+        if (! is_string($host) || $host === '') {
+            throw new RuntimeException('観測用 APP_URL から host を取り出せない');
+        }
+
+        return $host;
+    }
+
+    /**
+     * @param  array<string, string>  $values
+     */
+    private static function writeEnvFile(string $path, array $values): void
+    {
+        // 'x' は既存ファイルがあれば失敗する (乗っ取られた置き場所へ書き足さない)。
+        $handle = fopen($path, 'x');
+        if ($handle === false) {
+            throw new RuntimeException("観測用の環境ファイルを作れない: {$path}");
+        }
+
+        // 中身を書く**前に**権限を絞る。
+        chmod($path, 0600);
+
+        $lines = '';
+        foreach ($values as $key => $value) {
+            $lines .= $key.'='.$value."\n";
+        }
+
+        fwrite($handle, $lines);
+        fclose($handle);
+    }
+
+    private static function mode(string $path): int
+    {
+        clearstatcache(true, $path);
+        $permissions = fileperms($path);
+
+        return $permissions === false ? -1 : ($permissions & 0777);
+    }
+
+    /**
+     * @return array<string, mixed>
+     */
+    private static function decode(string $output): array
+    {
+        if (trim($output) === '') {
+            return [];
+        }
+
+        $decoded = json_decode($output, true);
+
+        return is_array($decoded) ? $decoded : ['raw_output' => $output];
+    }
+
+    private static function removeDirectory(string $directory): void
+    {
+        if (! is_dir($directory)) {
+            return;
+        }
+
+        foreach (scandir($directory) ?: [] as $entry) {
+            if ($entry === '.' || $entry === '..') {
+                continue;
+            }
+            $path = $directory.'/'.$entry;
+            if (is_dir($path)) {
+                self::removeDirectory($path);
+
+                continue;
+            }
+            unlink($path);
+        }
+
+        rmdir($directory);
+    }
+}
diff --git a/tests/Support/ExternalFakes/FakeWiringSourceScanner.php b/tests/Support/ExternalFakes/FakeWiringSourceScanner.php
index f3f6f0c..3fad0fd 100644
--- a/tests/Support/ExternalFakes/FakeWiringSourceScanner.php
+++ b/tests/Support/ExternalFakes/FakeWiringSourceScanner.php
@@ -16,10 +16,12 @@
  *    API 名の列挙は未知 API (rebinding / 将来の Container API) で必ず抜けられる。
  *  - `make()` は**引数まで**固定する。`$this->app->make(SomeRegistrar::class)->register()` という
  *    委譲で配線を別クラスへ逃がせるため (既存の CannedPromptFakeRegistrar が現に委譲パターン)。
- *  - `bind()` は「位置引数ちょうど 2 個かつ両方 `::class`」に固定する。
- *    `bind($abstract, ExistingFake::class)` は bindPairs() が読み取れず参照集合も変わらないため
- *    ここで禁止しないと**偽グリーン**になる。`bind(A::class, B::class, true)` は第 3 引数 $shared =
- *    singleton 相当なので、これも禁止しないと singleton 禁止を同じ意味の書き方で回避できる。
+ *  - `bind()` は「位置引数ちょうど 2 個で、どちらも宣言 entry のプロパティ参照
+ *    (`$swap->abstract` / `$swap->fake`)」に固定する。**`::class` を直に書く形も禁止**で、
+ *    差し替え先の決定を宣言 (`ExternalFakeDeclaration`) だけに閉じるための摩擦である
+ *    (provider に 1 組でも手書きすると、宣言を読まない差し替えが無音で増える)。
+ *    `bind(A::class, B::class, true)` は第 3 引数 $shared = singleton 相当なので、
+ *    これも禁止しないと singleton 禁止を同じ意味の書き方で回避できる。
  *  - 誤検出は分類 1 行で解消できるが検出漏れは永久に気付けない、という非対称性から
  *    **過剰検出側 (fail-closed)** へ倒す。
  *
@@ -50,18 +52,30 @@ final class FakeWiringSourceScanner
      * 許可する `$this->app-><method>(…)` の呼び出し形 (これ以外はすべて禁止 = deny-by-default)。
      *
      * value は許可する**位置引数の形**:
-     * - `classPair`: 位置引数ちょうど 2 個で両方 `::class` 定数 (差し替え本体。組は bindPairs() が inventory 照合)
+     * - `declaredPair`: 位置引数ちょうど 2 個で、どちらも変数のプロパティ参照であり、
+     *   プロパティ名が順に `abstract` / `fake` であること (= 宣言 entry を読んだ差し替えだけを許す)
      * - `allowlistedClass`: 位置引数ちょうど 1 個で MAKE_ALLOWED_ARGUMENTS のいずれか
      * - `none`: 位置引数なし
      *
      * @var array<string, string>
      */
     private const array ALLOWED_APP_CALLS = [
-        'bind' => 'classPair',
+        'bind' => 'declaredPair',
         'make' => 'allowlistedClass',
         'environment' => 'none',
     ];
 
+    /**
+     * `declaredPair` が要求するプロパティ名 (順序込み)。
+     *
+     * 宣言 entry (App\Support\ExternalFakes\ExternalFakeBinding) の
+     * 「解決キー」と「差し替え先」のプロパティ名であり、順序が入れ替わると
+     * 差し替えの向きが逆になるため位置ごとに固定する。
+     *
+     * @var list<string>
+     */
+    private const array DECLARED_PAIR_PROPERTIES = ['abstract', 'fake'];
+
     /**
      * `make()` に渡してよいクラス (container 配線を行わないことを分類済みの 2 件のみ)。
      *
@@ -146,11 +160,13 @@ public static function disallowedContainerCalls(string $source): array
                 continue;
             }
 
-            if ($shape === 'classPair') {
+            if ($shape === 'declaredPair') {
                 if (count($arguments) !== 2
-                    || ! self::isClassConstant($arguments[0])
-                    || ! self::isClassConstant($arguments[1])) {
-                    $violations[] = "{$method}(…) は位置引数ちょうど 2 個かつ両方 ::class 定数でなければならない (line {$line})";
+                    || ! self::isDeclaredProperty($arguments[0], self::DECLARED_PAIR_PROPERTIES[0])
+                    || ! self::isDeclaredProperty($arguments[1], self::DECLARED_PAIR_PROPERTIES[1])) {
+                    $violations[] = "{$method}(…) は位置引数ちょうど 2 個で、順に "
+                        .'$<変数>->'.self::DECLARED_PAIR_PROPERTIES[0].' / $<変数>->'
+                        .self::DECLARED_PAIR_PROPERTIES[1]." でなければならない (line {$line})";
                 }
 
                 continue;
@@ -174,12 +190,18 @@ public static function disallowedContainerCalls(string $source): array
     }
 
     /**
-     * `$this->app->bind(A::class, B::class)` の (abstract, concrete) 組 (**FQCN 正規化済み**)。
+     * container へ `bind(A::class, B::class)` する組 (**FQCN 正規化済み**)。
+     *
+     * 読める呼び出し形は **container へ到達する 4 つ**である —
+     * `$this->app->bind` / `app()->bind` (`resolve()` と `use function … as …` の別名を含む) /
+     * `App::bind` (facade。別名解決あり) / `Container::getInstance()->bind`。
      *
      * 第 2 引数が `::class` 定数でない (closure 等) 場合は concrete を `null` として返し、
-     * 呼び出し側テストで「fake 差し替えは ::class 対 ::class の形に限る」を fail させる。
-     * 第 1 引数が `::class` 定数でない形 (変数 abstract など) は組として読み取れないため返さない
-     * (disallowedContainerCalls() が別途 fail させる = 見落としにはならない)。
+     * 呼び出し側テストで「差し替えは ::class 対 ::class の形に限る」を fail させる。
+     * 第 1 引数が `::class` 定数でない形 (変数 abstract など) は組として読み取れないため返さない。
+     *
+     * **保証範囲を誇張しない**: 読めるのは上の 4 形だけである。変数経由の結び付け
+     * (`$container->bind(…)`)・`instance()` / `swap()`・モック機構経由には**沈黙する**。
      *
      * @return list<array{abstract: class-string, concrete: class-string|null}>
      */
@@ -188,21 +210,18 @@ public static function bindPairs(string $source): array
         $scanner = self::analyze($source);
         $pairs = [];
 
-        foreach ($scanner->appMethodCalls() as $call) {
-            if ($call['method'] !== 'bind' || count($call['args']) < 2) {
-                continue;
-            }
-            if (! self::isClassConstant($call['args'][0])) {
+        foreach ($scanner->containerBindCalls() as $args) {
+            if (count($args) < 2 || ! self::isClassConstant($args[0])) {
                 continue;
             }
 
             /** @var class-string $abstract */
-            $abstract = $scanner->resolve($call['args'][0][0]['text']);
+            $abstract = $scanner->resolve($args[0][0]['text']);
 
             $concrete = null;
-            if (self::isClassConstant($call['args'][1])) {
+            if (self::isClassConstant($args[1])) {
                 /** @var class-string $concrete */
-                $concrete = $scanner->resolve($call['args'][1][0]['text']);
+                $concrete = $scanner->resolve($args[1][0]['text']);
             }
 
             $pairs[] = ['abstract' => $abstract, 'concrete' => $concrete];
@@ -653,6 +672,144 @@ private function appMethodCalls(): array
         return $calls;
     }
 
+    /**
+     * container へ到達する `bind(` 呼び出しの位置引数一覧。
+     *
+     * 対象は container へ到達する 4 形 — `$this->app->bind` / `app()->bind` /
+     * `App::bind` / `Container::getInstance()->bind` (別名は解決してから照合する)。
+     *
+     * @return list<list<list<array{id: int, text: string, line: int}>>>
+     */
+    private function containerBindCalls(): array
+    {
+        $calls = [];
+        $count = count($this->tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            $token = $this->tokens[$i];
+
+            if ($this->isImportToken[$i] || $token['id'] !== T_STRING || $token['text'] !== 'bind') {
+                continue;
+            }
+            if (($this->tokens[$i + 1]['text'] ?? '') !== '(') {
+                continue;
+            }
+            if (! $this->isContainerReceiverBefore($i)) {
+                continue;
+            }
+
+            $calls[] = $this->parseArguments($i + 1);
+        }
+
+        return $calls;
+    }
+
+    /**
+     * `bind` トークンの直前が container を指す受け手か。
+     */
+    private function isContainerReceiverBefore(int $index): bool
+    {
+        $operator = $this->tokens[$index - 1] ?? null;
+        if ($operator === null) {
+            return false;
+        }
+
+        // App::bind(…) (facade。use alias も解決する)
+        if ($operator['id'] === T_DOUBLE_COLON) {
+            return $this->isContainerStaticName($this->tokens[$index - 2] ?? null);
+        }
+
+        if (! in_array($operator['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
+            return false;
+        }
+
+        $receiver = $this->tokens[$index - 2] ?? null;
+        if ($receiver === null) {
+            return false;
+        }
+
+        // $this->app->bind(…)
+        if ($receiver['id'] === T_STRING
+            && $receiver['text'] === 'app'
+            && in_array($this->tokens[$index - 3]['id'] ?? -1, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
+            && ($this->tokens[$index - 4]['id'] ?? -1) === T_VARIABLE
+            && ($this->tokens[$index - 4]['text'] ?? '') === '$this') {
+            return true;
+        }
+
+        // ここから先は `…()->bind(…)` の形だけが対象
+        if ($receiver['text'] !== ')') {
+            return false;
+        }
+
+        $open = $this->matchingOpenParen($index - 2);
+        if ($open === null) {
+            return false;
+        }
+
+        $callee = $this->tokens[$open - 1] ?? null;
+        if ($callee === null) {
+            return false;
+        }
+
+        // app()->bind(…) / resolve()->bind(…) (`use function app as …` の別名も解決する)
+        if (in_array($callee['id'], self::NAME_TOKEN_IDS, true)
+            && ! self::isMemberAccessBoundary($this->tokens[$open - 2] ?? null)
+            && in_array($this->resolveFunctionName($callee['text']), self::CONTAINER_HELPERS, true)) {
+            return true;
+        }
+
+        // Container::getInstance()->bind(…)
+        return $callee['id'] === T_STRING
+            && $callee['text'] === 'getInstance'
+            && ($this->tokens[$open - 2]['id'] ?? -1) === T_DOUBLE_COLON
+            && $this->isContainerStaticName($this->tokens[$open - 3] ?? null);
+    }
+
+    /**
+     * 静的アクセスの起点が container を指す名前か (FQCN 解決 + 末尾セグメントの二重線)。
+     *
+     * @param  array{id: int, text: string, line: int}|null  $token
+     */
+    private function isContainerStaticName(?array $token): bool
+    {
+        if ($token === null || ! in_array($token['id'], self::NAME_TOKEN_IDS, true)) {
+            return false;
+        }
+
+        $segments = explode('\\', ltrim($token['text'], '\\'));
+        $last = $segments[count($segments) - 1];
+
+        return in_array($this->resolve($token['text']), self::CONTAINER_STATIC_FQCNS, true)
+            || in_array($last, self::CONTAINER_STATIC_ROOTS, true);
+    }
+
+    /**
+     * `)` の位置に対応する `(` の位置 (見つからなければ null)。
+     */
+    private function matchingOpenParen(int $closeIndex): ?int
+    {
+        $depth = 0;
+
+        for ($i = $closeIndex; $i >= 0; $i--) {
+            $text = $this->tokens[$i]['text'];
+
+            if ($text === ')') {
+                $depth++;
+
+                continue;
+            }
+            if ($text === '(') {
+                $depth--;
+                if ($depth === 0) {
+                    return $i;
+                }
+            }
+        }
+
+        return null;
+    }
+
     /**
      * `(` の位置から位置引数を切り出す (トップレベルの `,` で分割)。
      *
@@ -712,6 +869,20 @@ private static function isClassConstant(array $arg): bool
             && $arg[2]['id'] === T_CLASS;
     }
 
+    /**
+     * 宣言 entry のプロパティ参照 (`$swap->abstract`) か。
+     *
+     * @param  list<array{id: int, text: string, line: int}>  $arg
+     */
+    private static function isDeclaredProperty(array $arg, string $property): bool
+    {
+        return count($arg) === 3
+            && $arg[0]['id'] === T_VARIABLE
+            && in_array($arg[1]['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
+            && $arg[2]['id'] === T_STRING
+            && $arg[2]['text'] === $property;
+    }
+
     /**
      * 名前付き引数 (`abstract: A::class`) か。
      *
diff --git a/tests/Support/ExternalFakes/fake-wiring-probe.php b/tests/Support/ExternalFakes/fake-wiring-probe.php
new file mode 100644
index 0000000..8c18778
--- /dev/null
+++ b/tests/Support/ExternalFakes/fake-wiring-probe.php
@@ -0,0 +1,81 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Auth\SocialiteDriverResolver;
+use App\Support\ExternalFakes\ExternalFakeDeclaration;
+use Illuminate\Contracts\Console\Kernel;
+use Illuminate\Foundation\Application;
+use Webmozart\Assert\Assert;
+
+/*
+ * 別プロセスで「宣言した差し替えが実際に効いているか」を観測して JSON を書き出す。
+ *
+ * ★責務は 4 つだけ: DB へ接続しない / container から解決する /
+ *   転送先 URL を組み立てて読む / 終了コードを返す。
+ *   HTTP サーバもブラウザも起動しない。
+ * ★禁止する文 (echo) を使わないため fwrite(STDOUT, …) で書く (AGENTS.md §禁止する文)。
+ * ★読み込む環境ファイルを**専用の一時ファイルだけ**に固定する (親のチェックアウトの
+ *   .env / .env.bughunt.local を読ませない = 実資格情報が子の設定へ入らない)。
+ */
+
+require __DIR__.'/../../../vendor/autoload.php';
+
+/** @var Application $app */
+$app = require __DIR__.'/../../../bootstrap/app.php';
+
+try {
+    Assert::isInstanceOf($app, Application::class);
+
+    // ★**Dotenv を読む前に**、子が実際に受け取ったプロセス環境を観測する。
+    //   起動側が組み立てた配列を検査しても `env -i` を外した退行は映らない
+    //   (組み立ては同じまま、親の環境だけが流れ込むため)。観測できるのは子だけである。
+    $initialProcessEnvironment = getenv();
+    Assert::isArray($initialProcessEnvironment);
+    $processEnvironmentKeys = array_keys($initialProcessEnvironment);
+    sort($processEnvironmentKeys);
+
+    $environmentDirectory = getenv('FAKE_WIRING_PROBE_ENV_DIR');
+    $environmentFile = getenv('FAKE_WIRING_PROBE_ENV_FILE');
+    Assert::stringNotEmpty($environmentDirectory);
+    Assert::stringNotEmpty($environmentFile);
+
+    $app->useEnvironmentPath($environmentDirectory);
+    $app->loadEnvironmentFrom($environmentFile);
+
+    $app->make(Kernel::class)->bootstrap();
+
+    $resolved = [];
+    foreach (ExternalFakeDeclaration::swaps() as $swap) {
+        $resolved[$swap->abstract] = $app->make($swap->abstract)::class;
+    }
+
+    // 外部ログインは「解決したクラス名」だけでは足りない。転送先が実際に自ホストへ
+    // 閉じているかまで見る (クラス名が合っていても転送先を戻す退行を緑で通すため)。
+    // ★転送先の組み立ては**偽物が有効なときだけ**行う。無効なときに呼ぶと本物の
+    //   身元確認サービス向けの URL を組み立てることになり、観測の目的から外れる。
+    $redirectHost = null;
+    if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) === true) {
+        // 観測する外部ログインの種類は設定から取る (名前を写経しない)。
+        $providers = config('template.social_providers');
+        Assert::isArray($providers);
+        $provider = array_key_first($providers);
+        Assert::stringNotEmpty($provider);
+
+        $target = $app->make(SocialiteDriverResolver::class)->driver($provider)->redirect()->getTargetUrl();
+        $host = parse_url($target, PHP_URL_HOST);
+        $redirectHost = is_string($host) ? $host : null;
+    }
+
+    fwrite(STDOUT, json_encode([
+        'resolved' => $resolved,
+        'redirect_host' => $redirectHost,
+        'process_environment_keys' => $processEnvironmentKeys,
+    ], JSON_THROW_ON_ERROR));
+
+    exit(0);
+} catch (Throwable $e) {
+    fwrite(STDOUT, json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));
+
+    exit(1);
+}
diff --git a/tests/Unit/Architecture/FakeWiringSourceScannerTest.php b/tests/Unit/Architecture/FakeWiringSourceScannerTest.php
index 6af8846..878aa28 100644
--- a/tests/Unit/Architecture/FakeWiringSourceScannerTest.php
+++ b/tests/Unit/Architecture/FakeWiringSourceScannerTest.php
@@ -50,6 +50,29 @@ function fakeWiringScannerSource(string $uses, string $body, string $namespace =
     ]);
 });
 
+test('5-2b: 宣言 entry のプロパティ 2 個を渡す bind は許可形である (現行 provider の実パターン)', function (): void {
+    $source = fakeWiringScannerSource('', '        $this->app->bind($swap->abstract, $swap->fake);');
+
+    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([])
+        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toBe([]);
+});
+
+test('5-2c: ::class を直に書く bind は許可形から外れる (差し替え先の手書きを封じる)', function (): void {
+    // 差し替え先の決定は宣言 (ExternalFakeDeclaration) にしか無い、という摩擦の実体。
+    $classPair = fakeWiringScannerSource('', '        $this->app->bind(\App\Demo\A::class, \App\Demo\B::class);');
+    // プロパティ名違い (差し替えの向きを取り違える形)
+    $wrongProperty = fakeWiringScannerSource('', '        $this->app->bind($swap->fake, $swap->abstract);');
+    // 引数 3 個 ($shared = singleton 相当)
+    $shared = fakeWiringScannerSource('', '        $this->app->bind($swap->abstract, $swap->fake, true);');
+    // 名前付き引数
+    $named = fakeWiringScannerSource('', '        $this->app->bind(abstract: $swap->abstract, concrete: $swap->fake);');
+
+    expect(FakeWiringSourceScanner::disallowedContainerCalls($classPair))->toHaveCount(1)
+        ->and(FakeWiringSourceScanner::disallowedContainerCalls($wrongProperty))->toHaveCount(1)
+        ->and(FakeWiringSourceScanner::disallowedContainerCalls($shared))->toHaveCount(1)
+        ->and(FakeWiringSourceScanner::disallowedContainerCalls($named))->toHaveCount(1);
+});
+
 test('5-3: singleton() は許可された呼び出し形ではない', function (): void {
     $source = fakeWiringScannerSource('', '        $this->app->singleton(\App\Demo\A::class, \App\Demo\B::class);');
 
@@ -99,7 +122,7 @@ function fakeWiringScannerSource(string $uses, string $body, string $namespace =
 test('5-9: コメント / docblock 中の container 呼び出しは誤検出しない', function (): void {
     $body = "        // \$this->app->singleton(\\App\\Demo\\A::class, \\App\\Demo\\B::class);\n"
         ."        /** \$this->app->singleton(\\App\\Demo\\A::class, \\App\\Demo\\B::class); */\n"
-        .'        $this->app->bind(\App\Demo\A::class, \App\Demo\B::class);';
+        .'        $this->app->bind($swap->abstract, $swap->fake);';
     $source = fakeWiringScannerSource('', $body);
 
     expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([])
@@ -243,6 +266,47 @@ function fakeWiringScannerSource(string $uses, string $body, string $namespace =
         ->and(FakeWiringSourceScanner::disallowedIndirectAccess($dynamicVariable))->toHaveCount(1);
 });
 
+test('5-24: bindPairs は container へ到達する 4 形と別名を読む', function (): void {
+    // レーン側 (tests/) は `app()->bind(…)` と書けるため、`$this->app->bind` だけを読む
+    // 走査器では LaneExternalFakeBindingTest が素通りする。4 形すべてを読むことを固定する。
+    $expected = [['abstract' => 'App\Demo\A', 'concrete' => 'App\Demo\B']];
+
+    $member = fakeWiringScannerSource('', '        $this->app->bind(\App\Demo\A::class, \App\Demo\B::class);');
+    $helper = fakeWiringScannerSource('', '        app()->bind(\App\Demo\A::class, \App\Demo\B::class);');
+    $facade = fakeWiringScannerSource(
+        'use Illuminate\Support\Facades\App;',
+        '        App::bind(\App\Demo\A::class, \App\Demo\B::class);'
+    );
+    $container = fakeWiringScannerSource(
+        'use Illuminate\Container\Container;',
+        '        Container::getInstance()->bind(\App\Demo\A::class, \App\Demo\B::class);'
+    );
+    $aliasedHelper = fakeWiringScannerSource(
+        'use function app as c;',
+        '        c()->bind(\App\Demo\A::class, \App\Demo\B::class);'
+    );
+    $aliasedContainer = fakeWiringScannerSource(
+        'use Illuminate\Container\Container as C;',
+        '        C::getInstance()->bind(\App\Demo\A::class, \App\Demo\B::class);'
+    );
+
+    foreach ([$member, $helper, $facade, $container, $aliasedHelper, $aliasedContainer] as $source) {
+        expect(FakeWiringSourceScanner::bindPairs($source))->toBe($expected);
+    }
+});
+
+test('5-25: bindPairs は container 以外の受け手の bind を読まない (誤検出しない)', function (): void {
+    // 走査器が「あらゆる ->bind(」を読むと、レーン側の無関係な API まで違反になる。
+    $unrelated = fakeWiringScannerSource('', '        $router->bind(\App\Demo\A::class, \App\Demo\B::class);');
+    // 第 2 引数が ::class でない形は concrete=null で返す (呼び出し側で判定する)
+    $closure = fakeWiringScannerSource('', '        app()->bind(\App\Demo\A::class, fn () => new \App\Demo\B);');
+
+    expect(FakeWiringSourceScanner::bindPairs($unrelated))->toBe([])
+        ->and(FakeWiringSourceScanner::bindPairs($closure))->toBe([
+            ['abstract' => 'App\Demo\A', 'concrete' => null],
+        ]);
+});
+
 test('5-23: 未分類の式経由で container を取り出す形も禁止する', function (): void {
     // container 到達 API を名前で列挙する方式は、未分類の式を挟まれると必ず抜けられる
     // (Codex 実装レビュー Round 3 の Critical)。閉じた文法から外れる形を fail-closed で拒否する。
```

---

## ドキュメント差分 (git diff)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index eed466e..9f85b8f 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -627,7 +627,7 @@ ## ドメイン固有規約
      `SocialProviderTrustPolicyTest` へ委譲する。
    - **保証範囲を誇張しない**: これは**検知**であって**遮断ではない**。
      SSO だけは別途 fake 配線 (testing / bughunt.local) で実 IdP への遷移を塞いでいるが、
-     それは**本目録の効果ではない** (`ExternalFakeWiringInventory` が正本)。
+     それは**本目録の効果ではない** (`ExternalFakeDeclaration` が正本)。
      走査根は `app/` のみで `routes/` / `config/` は見ない。
      委譲先の assert の中身を弱める改変、次元そのものの数え落とし、部分修飾名、
      文字列キーの container 解決だけの経路、vendor 内部から出る通信、他種別の宛先集合、
@@ -635,7 +635,7 @@ ## ドメイン固有規約
      **保証しないものの完全な一覧は `docs/architecture.md` §外部到達点の目録 (標準形 v1) が正本**
      (ここは要約であり、増減はそちらで管理する)。
    - 非本番の captcha は `testing.fake_externals` で `RecaptchaVerifierTestFake` へ bind される
-     (`ExternalFakeWiringInventory`)。**SSO も同じ flag で fake する**が、env allowlist は
+     (`ExternalFakeDeclaration`)。**SSO も同じ flag で fake する**が、env allowlist は
      `testing` / `bughunt.local` のみで **`local` を除く** (認証バイパス面の最小化と
      実 IdP 連携の確認手段の温存)。
    - 詳細は `docs/architecture.md` §外部到達点の目録 (標準形 v1)。
diff --git a/docs/architecture.md b/docs/architecture.md
index e6bfa57..cba408d 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1472,38 +1472,85 @@ ## 公開面
 | MCP | `routes/ai.php` → `Mcp/Servers` | Passport OAuth 2.1 (`auth:mcp-oauth`) |
 | 管理画面 | Filament (`app/Filament`) | AdminUser guard |
 
-## 外部 fake 配線の不変条件 (T119)
-
-外部サービス (Stripe / S3 / LLM) の fake 差し替えは、**登録漏れが例外にならず本物が静かに動く**
-という性質を持つ (Laravel は abstract が具象クラスなら設定が無くても自動組み立てする)。
-撮影データと課金は取り返しがつかない副作用を持つため、以下を不変条件として固定する
-(gate は `tests/Architecture/ExternalFakeWiringInvariantTest` と
-`tests/Architecture/FakeClassReferenceInvariantTest`、走査器の固定は
-`tests/Unit/Architecture/FakeWiringSourceScannerTest`)。
-
+## 偽の外部サービスの宣言と配線の不変条件 (T119 / T177)
+
+外部サービス (決済 / 保存先 / LLM) を偽物へ差し替える配線は、**登録漏れが例外にならず
+本物が静かに動く**という性質を持つ (Laravel は abstract が具象クラスなら設定が無くても
+自動組み立てする)。撮影データと課金は取り返しがつかない副作用を持つため、以下を不変条件として
+固定する (gate は `tests/Architecture/ExternalFakeWiringInvariantTest` /
+`FakeClassReferenceInvariantTest` / `LaneExternalFakeBindingTest` / `ExternalFakeBootProbeTest`、
+走査器の固定は `tests/Unit/Architecture/FakeWiringSourceScannerTest`)。
+
+- **「何をどの偽物へ差し替えるか」の唯一の正本は
+  `App\Support\ExternalFakes\ExternalFakeDeclaration`** (本番の読み込み対象に置く)。
+  差し替え 1 本は `ExternalFakeBinding` の値オブジェクトで表し、
+  capability flag / 許可環境 / 差し替えない対象もここが持つ。
+  provider・`FakeStorageGate`・bug-hunt の seeder・`ProductionEnvGuard`・
+  bug-hunt の環境ひな型検査は**すべてこの宣言を読む** (同じ集合を 2 か所に書かない)。
 - **差し替えの唯一の配線点は `App\Providers\FakeExternalsServiceProvider`**。container 差し替えは
-  `$this->app->bind(A::class, B::class)` の形だけで行う (`singleton()` / `bind()` の第 3 引数
-  (= singleton 相当) / 変数 abstract / closure concrete / `app()`・`resolve()`・`App::`・
-  `Container::getInstance()` 経由は deny-by-default で fail する)。登録は
-  `bootstrap/providers.php` で **`AppServiceProvider` より後**に置く (後勝ち rebind)。
-- **新しい差し替えを足したら `tests/Support/ExternalFakes/ExternalFakeWiringInventory::bindings()`
-  に登録する**。未登録の bind 組は集合一致で検出される。登録すると「flag off で real /
-  flag on + allowlist env で fake / allowlist 外 env で real」の**実証**検査が自動で増える。
-  判定は必ず**厳密クラス一致** (`$resolved::class === $expected`) — storage fake は real の
-  サブクラスなので `instanceof` では偽グリーンになる。Architecture lane は `RefreshDatabase` を
-  使わないため、**解決対象の constructor が DB 非依存**であることを確認すること。
-- **capability flag は 3 系統で allowlist が異なる**: `testing.fake_externals` (課金。
-  local / testing / bughunt.local)、`testing.fake_storage` (`App\Support\FakeStorageGate` が
-  predicate の SSOT。bughunt.local ∨ (testing ∧ runningUnitTests))、`testing.fake_llm`
-  (bughunt.local のみ。`Prompt::$fake` は container ではなくプロセスグローバル static)。
+  `$this->app->bind($swap->abstract, $swap->fake)` の形**だけ**で行う。
+  **`::class` を直に書く bind は許可形から外れる** = 差し替え先の決定は宣言側にしか無い
+  (`singleton()` / 第 3 引数 (= singleton 相当) / 変数 abstract / closure concrete /
+  `app()`・`resolve()`・`App::`・`Container::getInstance()` 経由も deny-by-default で fail する)。
+  登録は `bootstrap/providers.php` で **`AppServiceProvider` より後**に置く (後勝ち rebind)。
+- **新しい差し替えを足したら `ExternalFakeDeclaration::swaps()` に entry を足す**。
+  足すと「flag off で real / flag on + allowlist env で fake / allowlist 外 env で real」の
+  **実証**検査が自動で増える。判定は必ず**厳密クラス一致** (`$resolved::class === $expected`) —
+  保存先の偽物は本物のサブクラスなので `instanceof` では偽グリーンになる。
+  Architecture lane は `RefreshDatabase` を使わないため、**解決対象の constructor が DB 非依存**で
+  あることを確認すること。**entry を消す変異を映すのは `3-16` (abstract 一覧の件数付き pin) だけ**で、
+  増減させるときは宣言と gate の 2 か所を同時に触る (意図的な摩擦)。
+- **レーン側 (`tests/`) から偽の実装クラスを container へ直接結ばない**
+  (`LaneExternalFakeBindingTest` が静的に禁じる。例外の登録簿は持たない)。
+  レーンで偽物を有効にするときは `tests/Pest.php` の `enableFakeExternals()` /
+  `enableFakeStorage()` を使い、宣言 + provider の 1 本を共有する。
+  per-test の代役 (`tests/Support/Fake*`) は Laravel 公式作法のテストダブルであり本規約の対象外。
+- **capability flag は 3 系統で許可環境が異なる**: `testing.fake_externals` (決済 + 人間性確認 +
+  外部ログイン。local / testing / bughunt.local。ただし**外部ログインだけ local を除く**)、
+  `testing.fake_storage` (`App\Support\FakeStorageGate` が有効化条件の単一正本。
+  bughunt.local ∨ (testing ∧ 自動テスト実行中))、`testing.fake_llm`
+  (bughunt.local のみ。`Prompt::$fake` は container ではなくプロセス大域の static)。
+- **差し替えない対象**は `ExternalFakeDeclaration::neverSwapped()` に理由付きで宣言する
+  (受信通知の署名検証 / 外部 URL の安全検査)。宣言集合と交わったら gate が落ちる。
 - **本番混入防止の正本は `App\Support\ProductionEnvGuard`** (配備前 = `production:preflight` /
-  起動時 = `AppServiceProvider::boot`)。fake 配線 gate はこれを二重実装しない。
+  起動時 = `AppServiceProvider::boot`)。**設定値とプロセスの実環境変数 (`$_SERVER` / `$_ENV` /
+  `getenv()`) の両方**を見る — 設定キャッシュを作った環境と出荷先が食い違うと、キャッシュ上は
+  false でも、キャッシュが失われた起動で環境変数が読み直されて本番で偽物が立ちうるため。
+  解釈できない値 (`maybe` / 非文字列) は安全側で違反にする。fake 配線 gate はこれを二重実装しない。
 - **fake 実装クラスは `app/**/Fakes/` か `app/**/Testing/` に置く**。配置例外は
-  `FakeExternalsServiceProvider` (唯一の配線点) と `FakeStorageGate` (有効化 predicate) の 2 件のみ。
+  `FakeExternalsServiceProvider` (唯一の配線点) と `FakeStorageGate` (有効化条件) の 2 件のみ。
 - **本番コード (`app/` • `routes/` • `config/` • `bootstrap/`) は fake クラスを参照しない**。
-  参照してよいのは配線点と fake storage signed route の受け口を含む 4 ファイルだけで、
+  参照してよいのは宣言・配線点・偽の保存先の署名付き経路の受け口を含む 6 ファイルだけで、
   allowlist の件数はテストが固定している (増やすには理由コメントと併せて 2 箇所を触る摩擦がかかる)。
   **誤検出が出ても allowlist を足す方向へ倒さない** — それが gate の目的である。
+- **別プロセスでの実測** (`ExternalFakeBootProbeTest`): 実際の起動の下で宣言の全件が
+  偽物 / 本物へ解決されること、外部ログインの転送先が自ホストへ閉じること、
+  production + フラグ有効なら起動そのものが失敗することを子プロセスで観測する。
+  子の環境は `env -i` で空にし、設定は使い捨ての鍵だけを書いた一時環境ファイル 1 つから読む
+  (親のチェックアウトの `.env` を読ませない = 実資格情報を子へ渡さない)。
+  **観測できるのは設定キャッシュ無しの起動だけ**である (キャッシュが古いときの事故は
+  上の二重判定が受け持つ)。
+
+### bug-hunt の投入データ (seeder) の配線 (T177)
+
+偽の外部サービスの配線と**同じ理由** (登録漏れが無音) が投入データにも当てはまるため、
+`tests/Architecture/BughuntSeedWiringInvariantTest` が deny-by-default で固定する。
+区分の目録は `tests/Support/Bughunt/BughuntSeedWiringInventory`
+(母集団は `database/seeders/` の全 seeder で、過不足なく一致することを要求する)。
+
+- `scripts/bug-hunt-shard.sh` の `cmd_provision` と `cmd_reseed` の投入列が**順序込みで一致**する
+  (順序に意味がある。並べ替えるときは 2 か所を同時に直す)
+- その列の集合が目録の「bug-hunt で明示投入する」区分と過不足なく一致する
+- bug-hunt 専用の seeder は `DatabaseSeeder` に現れない (全環境の `migrate:fresh --seed` で走らない)
+- 環境ガードを要求する区分は `run()` の**最初の実効文が `if`** で、条件に区分ごとの判定語が
+  すべて現れ、本体に早期 `return` がある
+- **静的走査は条件の論理 (かつ / または) を読めない**ため、ガードを要求する区分には
+  その論理を実際に動かして固定している振る舞いテストを目録から紐づける (前提テストが消えたら赤くなる)
+
+**bug-hunt の手順書 (`.claude/skills/app-bug-hunt/`) 側に投入データの検査は置かない。**
+手順書が守るのは走行の型 (禁止事項・走る順・異常の見分け方) であり、
+「どの投入データがどの入口に配線されているか」は実行時の配線の関心事である。
+配線の検査は上の Architecture テストが持つ。
 
 ## 外部 SDK の待ち上限の規約 (T126)
 
@@ -1744,7 +1791,7 @@ ### SSO の集約と captcha の fake 配線
   **T153 で集約先を controller からこの薄い解決点へ切り出した** — container の差し替えキーに
   なれるのは controller ではなく解決点だからである。
 - 非本番の captcha は `testing.fake_externals` で `RecaptchaVerifier` →
-  `RecaptchaVerifierTestFake` へ container bind される (`ExternalFakeWiringInventory`)。
+  `RecaptchaVerifierTestFake` へ container bind される (`ExternalFakeDeclaration`)。
   abstract が**具象クラス**のため bind を消しても Laravel が本物を自動組み立てし、
   `RECAPTCHA_SECRET_KEY` が設定された環境では**無言で** Google siteverify を叩く
   (`StrayHttpRequestGuard` は bug-hunt の別プロセス実行には効かない)。
@@ -1755,7 +1802,7 @@ ### SSO の集約と captcha の fake 配線
   canned 値 (`fake-{provider}-user` / `fake-{provider}-sso@example.com`) で、
   外部入力では切り替えられない。
   - **env allowlist は `testing` / `bughunt.local` のみで `local` を除く**
-    (`FakeExternalsServiceProvider::SSO_FAKE_ENVIRONMENTS`)。SSO fake は未認証 GET 2 本
+    (`ExternalFakeDeclaration::SSO_ENVIRONMENTS`)。SSO fake は未認証 GET 2 本
     (`/auth/{p}/redirect/login` → `/auth/{p}/callback`) で canned アカウントへログインできる
     = **認証バイパス**であり、かつ `local` は開発者が実 IdP 連携を確認する唯一の環境である。
     この除外は**誤設定ではなく設計上の除外**なので warning ログを出さない (LLM fake と同じ扱い)。
```

---

## 変異の 2 段確認 (実走記録)

# 変異の 2 段確認 (T177)

新設・強化した gate が「実際に何を落とすか」を、変異を当てて実走で確かめた記録。

## 段階 1: 実装前に素通りすること

本 TODO で新設した検査 (`3-13`〜`3-16` / `LaneExternalFakeBindingTest` /
`BughuntSeedWiringInvariantTest` の `S-1`〜`S-11` / `ExternalFakeBootProbeTest` の
`P-1`〜`P-12` / `ProductionEnvGuard` の実環境変数の判定) は、実装前の HEAD に**存在しない**。
したがって段階 1 の「素通りする」は、対応する検査が母集団ごと無いことによって成立している
(実装前の main で `composer test` は 5000 件超が緑であり、下記の変異はどれも検出されない)。

例外は 1 つある。**施策 1c は素通りどころか実在の違反を見つけた** —
`tests/Feature/Billing/` の 5 ファイル・8 箇所が偽の実装クラスを container へ直接結んでいた。
これは「実装前は素通りする」の実測そのものであり、詳細と対応は
`detailed-design.md` §実装時に判明した設計の訂正 (4)。

## 段階 2: 実装後にすべて赤くなること

変異を 1 つ当てて対象テストを走らせ、直後に原状復帰する手順で実走した
(結果は `composer test -- --filter=…` の `result` フィールド)。

| # | 変異 | 対象 | 結果 |
|---|---|---|---|
| M-a | 宣言 (`swaps()`) から外部ログインの entry を 1 件消す | `ExternalFakeWiring` / `ExternalFakeBootProbe` | failed (= 検出) |
| M-b | provider に `::class` の bind を手書きする | `ExternalFakeWiring` | failed |
| M-c | provider が宣言を読まず素通しする (bind しない) | `ExternalFakeWiring` / `ExternalFakeBootProbe` | failed |
| M-d | `config/testing.php` に宣言外の偽物フラグを 1 本足す | `ExternalFakeWiring` | failed |
| M-e | レーン側 (`tests/`) で偽の実装クラスを直接結ぶ | `LaneExternalFakeBinding` | failed |
| M-f | `cmd_reseed` から `BughuntOAuthSeeder` を落とす | `BughuntSeedWiring` | failed |
| M-g | `BughuntBillingSeeder` を `DatabaseSeeder` に足す | `BughuntSeedWiring` | failed |
| M-h | bug-hunt 専用 seeder のガードの前に 1 文入れる | `BughuntSeedWiring` | failed |
| M-k | 偽の外部ログインの転送先を実 IdP に戻す | `ExternalFakeBootProbe` | failed |
| M-l | 一時環境ファイルへ `STRIPE_SECRET` を足す | `ExternalFakeBootProbe` | failed |
| M-m | 一時環境ファイルの鍵を親の設定値の複写に戻す | `ExternalFakeBootProbe` | failed |
| M-n | 子プロセスの起動コマンドから `env -i` を外す | `ExternalFakeBootProbe` | failed |

変異をすべて戻した後、上記 filter を通しで再実行して 167 passed を確認した
(復帰漏れが無いことの確認)。

## 変異を当てなかったもの

- **施策 3 (本番混入防止の実環境変数の判定)**: 変異ではなく**新規ケースの追加**で固定した
  (`$_SERVER` / `$_ENV` / `getenv()` の 3 経路それぞれ / 未設定 / 無効と読める値 /
  解釈できない値 / 非文字列)。加えて別プロセス観測の `P-4` が、実際の production 起動で
  設定値と 3 経路の両方から違反が出ることを実測している。
- **`P-9` の権限違反で子を起こさない分岐**: 一時ディレクトリの権限を外から壊す変異は
  再現が環境依存になるため、判定を `assertSafePermissions()` へ切り出して
  緩い権限 (0755 / 0644) で例外になることを直接固定した。

## 保証範囲を誇張しない

- 走査系の gate はいずれも**字句**を見る。宣言と provider の関係は
  「許可した呼び出し形の外に出たら赤」という閉じた文法で守っており、
  reflection / `eval` のような敵対的回避に対する完全性は主張しない。
- 投入データの検査は条件の**論理** (かつ / または) を読めない。
  そこはガードの振る舞いテストを目録から紐づけて守っている (`S-9`)。
- 別プロセス観測が見るのは**設定キャッシュ無しの起動だけ**である。

---

## テスト結果

- `composer test`: 5136 tests / 5134 passed / 0 failed / 2 skipped (22025 assertions)
- `composer phpstan`: level 10, 943 files, No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1501) / `pnpm build`: すべて green
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106): すべて green

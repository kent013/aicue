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

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

（アプリの使命・禁止事項は上記に挿入済み）

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策に Pest テスト。RefreshDatabase はグローバル適用）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（既存テストが壊れないか、壊れるなら変更対象に挙がっているか）
9. セキュリティ（AGENTS.md のセキュリティ不変条件。特に「偽の外部サービスが本番へ混入しない」「探索走行が実課金・実 IdP・実 S3 へ出ない」を弱めていないか）
10. 本件固有: 検査 (gate) の**検出力**を落としていないか。既存の検査を削除・置換する箇所で、削除後に守られなくなる不変条件が無いか
11. 本件固有: 過剰な機構を作っていないか（思考原則 2「今必要なものだけ作る」）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

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
    3-8 (bind 組の集合一致) を**削除**し、3-10 を「provider は fake クラスを 1 つも参照しない」へ強化
  - `tests/Architecture/FakeClassReferenceInvariantTest.php` — 参照 allowlist から provider を外し、
    宣言クラスを足す (件数固定の 4-4 も同じ変更で直す)
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
- [x] DTO を返す (配列返却の新設なし)

### テスト計画

- [x] 既存 `tests/Architecture/ExternalFakeWiringInvariantTest.php` の 3-1〜3-7 / 3-11 は
      **参照先の差し替えだけ**で緑のまま (実証の中身は変えない)
- [x] 3-8 (bind 組の集合一致) を削除。理由をファイル冒頭のコメントに残す
      (「差し替え先の決定が宣言 1 か所になったため、比較する相手が無くなった」)
- [x] 3-9 を維持し、走査器の許可形変更に合わせて期待値を更新
- [x] 3-10 を強化: provider が参照する fake 系クラスは**空**であること
- [x] 新規 `3-13 宣言の健全性`: `swaps()` の abstract に重複が無く、
      各 entry の `allowedEnvironments` が capability の許可環境の部分集合であること
- [x] 新規 `3-14 差し替えない対象`: `neverSwapped()` のキーが `swaps()` の abstract と
      **1 件も交わらない**こと (空集合でないことも確かめる = fail-closed)
- [x] 新規 `3-15 設定との一致`: `config('testing')` のキー集合が
      `FLAG_ENVIRONMENT_VARIABLES` の config キー集合と**過不足なく一致**すること
      (設定にフラグを足して宣言に足し忘れる形をその場で落とす)
- [x] `tests/Unit/Architecture/FakeWiringSourceScannerTest.php`: `declaredPair` の
      正例 (`bind($swap->abstract, $swap->fake)`) と負例 (`::class` 2 個 / プロパティ名違い /
      引数 3 個 / 名前付き引数) を追加し、旧 `classPair` 前提のケースを消す
- [x] `tests/Feature/Providers/FakeExternalsServiceProviderTest.php` (8 test) と
      `tests/Unit/Support/FakeStorageGateTest.php` (6 test) と
      `tests/Feature/Auth/FakeSocialiteWiringTest.php` (10 test) が**無変更で緑**であること
      (= 挙動を変えていないことの回帰)
- [x] 受入は**変異の 2 段確認**で行う (T119 と同じ作法)。実装前に「宣言から 1 entry を消す」
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
- **保証範囲を誇張しない**: 走査器が読めるのは `$this->app->bind(A::class, B::class)` /
  `app()->bind(…)` の形だけである。変数経由の結び付け・`instance()` / `swap()` /
  モック機構経由には**沈黙する**

### PHPStan適合チェック

- [x] 走査結果の型は既存 `bindPairs()` の
      `list<array{abstract: class-string, concrete: class-string|null}>` をそのまま使う
- [x] 新しい型を作らない (既存の走査器を再利用する)

### テスト計画

- [x] 正例: 現在の `tests/` 全走査で違反 0 件
- [x] 負のコントロール: 合成ソース (`$this->app->bind(RecaptchaVerifier::class, RecaptchaVerifierTestFake::class)`)
      を渡すと 1 件検出すること
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
 * @return array<class-string, array{role: BughuntSeedRole, reason: string}>
 */
public static function entries(): array
{
    return [
        BughuntBillingSeeder::class => [
            'role' => BughuntSeedRole::BughuntOnly,
            'reason' => '有料プラン組織へ購読とチケットを投入する。通常経路に載せると開発 DB へ課金状態が漏れる。',
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
```

```php
// tests/Support/Bughunt/ShellFunctionWindow.php (抜粋)
/**
 * `^name()` 行から次の `^cmd_` 定義 (または末尾) までを切り出す。
 *
 * 非貪欲な `\n\}` 終端は使わない (ヒアドキュメント内の行頭 `}` で早く止まるため)。
 * 見つからないときは例外にする (静かに空文字を返して緑にしない)。
 */
public static function of(string $source, string $name): string;
```

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
| S-8 | 負のコントロール: 合成のスクリプト断片 (reseed から 1 行落とす / 並びを入れ替える) と合成の seeder ソース (ガードの前に 1 文入れる) を検出する |

- **S-6 の保証範囲を誇張しない**: 見るのは「最初の実効文が `if` で、条件に必要な判定語が
  すべて現れること」までで、条件の論理 (かつ / または) までは見ない。
  論理そのものは既存の振る舞いテスト
  (`tests/Feature/Database/BughuntBillingSeederTest.php` /
  `BughuntOAuthSeederGuardTest.php`) が固定する **二段防御**である

### PHPStan適合チェック

- [x] 目録は `array<class-string, array{role: BughuntSeedRole, reason: string}>` で型を明示
- [x] enum は backed でない純粋な enum (値を持つ必要が無い)
- [x] `ShellFunctionWindow::of()` は `string` を返し、見つからなければ例外 (null 返しにしない)

### テスト計画

- [x] S-1〜S-8 を Pest の Architecture レーンで実行 (DB 不使用)
- [x] 既存 `BughuntOrchestratorGateInvariantTest` が共有クラスへ委譲した後も
      **全ケース緑**であること (関数窓の切り出し結果が変わっていないことの回帰)
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

- `rawEnvironmentValues()`: 3 経路のうち**値が存在するものだけ**を
  `array<string, string>` (経路名 => 生の値) で返す (未設定は判定対象にしない)
- `isUnambiguouslyDisabled()`: 空文字 / `false` / `(false)` / `0` / `off` / `no` /
  `null` / `(null)` を大文字小文字を無視して false と読む。**それ以外はすべて違反**
  (解釈できない値を安全側へ倒す)

### PHPStan適合チェック

- [x] `rawEnvironmentValues(string $variable): array<string, string>` を明示
- [x] `getenv()` の戻り値 (`string|false`) を `is_string()` で絞る
- [x] `$_SERVER` / `$_ENV` の値は `mixed` なので `is_string()` で絞り、非文字列は
      **解釈できない値として違反**にする (黙って捨てない)

### テスト計画

- [x] 既存の設定値ケース (3 フラグ) は文言変更に追随して緑
- [x] 新規: `$_SERVER['TESTING_FAKE_EXTERNALS'] = 'true'` かつ config が false のとき違反が出る
      (3 経路それぞれで 1 ケースずつ = 3 ケース)
- [x] 新規: 未設定 (3 経路とも無し) では違反が出ない
- [x] 新規: `'false'` / `'0'` / `''` では違反が出ない
- [x] 新規: `'maybe'` / 非文字列では**違反が出る** (解釈できない値は安全側)
- [x] すべて `try/finally` で `$_SERVER` / `$_ENV` の原値を復元する
      (`putenv()` を使うケースは同じ finally で戻す)

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
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $resolved = [];
    foreach (ExternalFakeDeclaration::swaps() as $swap) {
        $resolved[$swap->abstract] = $app->make($swap->abstract)::class;
    }

    // 外部ログインは「解決したクラス名」だけでは足りない。転送先が実際に自ホストへ
    // 閉じているかまで見る (クラス名が合っていても転送先を戻す退行を緑で通すため)。
    $redirect = $app->make(SocialiteDriverResolver::class)
        ->driver('google')->redirect()->getTargetUrl();

    fwrite(STDOUT, json_encode([
        'resolved' => $resolved,
        'redirect_host' => parse_url($redirect, PHP_URL_HOST),
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
 * ★子へ渡す環境変数は明示した分だけを上書きする (親のシェルに残った TESTING_FAKE_* に
 *   結果を左右されないよう、対照側でも 3 フラグをすべて明示する)。
 * ★APP_KEY / CIPHERSWEET_KEY は親の解決済み設定から渡す
 *   (.env が無い環境でも起動できるようにするため)。DB へは接続しない。
 * ★環境ファイルより**プロセスの環境変数が優先される** (Laravel の env 読み込みは
 *   既存の環境変数を上書きしない) ので、手元に .env.bughunt.local があっても結果は変わらない。
 *
 * @return array{exitCode: int, output: array<string, mixed>}
 */
public static function run(string $environment, bool $fakeExternals, bool $fakeStorage, bool $fakeLlm): array;
```

観測点 (`tests/Architecture/ExternalFakeBootProbeTest.php`):

| # | 観測 |
|---|---|
| P-1 | `bughunt.local` + フラグ有効 → 宣言集合の全件が**偽物のクラスで厳密一致**する |
| P-2 | 同上で外部ログインの転送先ホストが**自ホスト**である (実 IdP のホストでない) |
| P-3 | 対照: `bughunt.local` + フラグ無効 → 全件が**本物のクラスで厳密一致**する |
| P-4 | 対照: `production` + フラグ有効 → **非ゼロ終了**し、出力に `TESTING_FAKE_EXTERNALS` が現れる |
| P-5 | fail-closed: 観測結果が空・宣言集合が空なら赤くなる |

- P-4 が意図どおり効く根拠: `AppServiceProvider::boot()` は
  `ProductionEnvGuard::enforce()` を**最初に**呼ぶため、他の起動時検査より先に
  この違反が出る (検査順に依存する点をテストのコメントに残す)

### PHPStan適合チェック

- [x] `FakeWiringProbeRunner::run()` の戻り値を `array{exitCode: int, output: array<string, mixed>}` で明示
- [x] `json_decode()` は `JSON_THROW_ON_ERROR` + `is_array()` で絞る
- [x] 観測用スクリプトは PHPStan の解析対象に入る。`$app` の型は
      `Illuminate\Foundation\Application` として扱えるよう `Assert::isInstanceOf()` を使う

### テスト計画

- [x] P-1〜P-5 を Architecture レーン (DB 不使用) で実行
- [x] 実行時間の上限を明示的に設定し (`Process` の timeout)、超えたら赤にする
      (ぶら下がりを緑で見逃さない)
- [x] 受入は変異の 2 段確認: 「宣言から SSO の entry を消す」
      「偽の外部ログインの転送先を実 IdP に戻す」の 2 通りで**赤くなる**ことを確かめる

### リスク

- **子プロセスの起動は環境差で壊れやすい**。→ 責務を 4 つに限定し、
  DB・HTTP サーバ・ブラウザに触れない。必要な環境変数は親から明示的に渡す
- **`.env` が全く無い環境で起動できない可能性**。→ `APP_KEY` / `CIPHERSWEET_KEY` を
  親の設定値から渡す。それでも起動できない場合は**skip せず赤にする**
  (静かに検査が消える形を作らない)
- **`google` provider が宣言から消えると P-2 が落ちる**。→ 転送先を見る provider は
  `config('template.social_providers')` の先頭から取る (名前を写経しない)

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

- [x] `docs/` の記述に対応する機械検査は施策 2 が持つ (文書だけを足して終わりにしない)
- [x] 文書の更新漏れを検出する既存の仕組み (`app-update-docs`) の対象に入る

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
3. 施策 1 の Architecture テストを更新 (3-8 削除 / 3-10 強化 / 3-13〜3-15 追加)
4. 施策 1c → 施策 2 → 施策 3 → 施策 4 の順に積む
5. 施策 5 の文書を書き、`mutation-evidence.md` に変異の 2 段確認の記録を残す


---

## 関連する現行コード

### config/testing.php
```php
<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 外部サービス fake 化の capability flag
    |--------------------------------------------------------------------------
    |
    | fake_externals: **外部サービス fake の capability flag** (既定 false = no-op)。
    | true のとき FakeExternalsServiceProvider::register() が以下を fake 実装へ bind する:
    |   - Stripe 課金 gateway (checkout / portal / auto-recharge)
    |   - captcha 検証器 (RecaptchaVerifier → RecaptchaVerifierTestFake)
    |   - SSO driver 解決点 (SocialiteDriverResolver → FakeSocialiteDriverResolver)
    | **SSO だけは env allowlist が狭い** (testing / bughunt.local のみ。**local を除外**)。
    |  SSO fake は未認証 GET 2 本で canned アカウントへログインできる = 認証バイパスであり、
    |  かつ local は実 IdP 連携を確認する唯一の環境であるため
    |  (docs/architecture.md §外部到達点の目録 (標準形 v1) を参照)。
    | Stripe / captcha の有効化は allowlist 環境 (local / testing / bughunt.local) に限定され、
    | production では ProductionEnvGuard が true を deploy 時 fail-fast で拒否する。
    | 既定 false = 本 flag 未設定の環境では完全 no-op。
    |
    | ※ LLM (Prism) fake はこの flag から分離され fake_llm が capability flag。
    |
    */

    'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),

    /*
    |--------------------------------------------------------------------------
    | LLM (Prism) fake 化の capability flag
    |--------------------------------------------------------------------------
    |
    | fake_llm: LLM (Prism) fake を install するか。config 既定 false = real LLM。
    | bughunt は既定 real-llm (scripts/bug-hunt-shard.sh が TESTING_FAKE_LLM=false を明示注入)。
    | --fake-llm 指定時のみ true 注入 → FakeExternalsServiceProvider::boot が
    | CannedPromptFakeRegistrar を install (env allowlist bughunt.local のみ)。
    | production では ProductionEnvGuard が true を fail-fast で拒否する。
    |
    */

    'fake_llm' => (bool) env('TESTING_FAKE_LLM', false),

    /*
    |--------------------------------------------------------------------------
    | S3 ストレージ fake 化のトグル (骨子)
    |--------------------------------------------------------------------------
    |
    | fake_storage: S3 ストレージ fake トグル (骨子)。config 既定 false = 本番安全側。
    | bughunt は既定 fake (scripts/bug-hunt-shard.sh が TESTING_FAKE_STORAGE=true を明示注入)。
    | --real-storage 指定時のみ false 注入。
    | ※ 実 S3 接続の実配線は本 item スコープ外 (consumer 未実装 = inert)。
    | production では ProductionEnvGuard が true を fail-fast で拒否する。
    |
    */

    'fake_storage' => (bool) env('TESTING_FAKE_STORAGE', false),

];

```
### app/Providers/FakeExternalsServiceProvider.php
```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Testing\GetFakeStorageObjectController;
use App\Http\Controllers\Testing\PutFakeStorageObjectController;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Auth\Fakes\FakeSocialiteDriverResolver;
use App\Services\Auth\SocialiteDriverResolver;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use App\Services\Captcha\RecaptchaVerifier;
use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
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
 * - 外部サービス (Stripe 課金 gateway + captcha 検証器 + SSO driver 解決点):
 *   config('testing.fake_externals') が capability flag。container bind (per-test 隔離が効くため
 *   testing 可)。register() で配線。
 *   **SSO (Socialite) だけは env allowlist が狭い** (SSO_FAKE_ENVIRONMENTS。**local を除く**)。
 *   docs/architecture.md §外部到達点の目録 (標準形 v1) を参照。
 * - LLM (Prism): config('testing.fake_llm') が capability flag (fake_externals から分離)。
 *   Prompt::$fake は static (プロセスグローバル) のため testing/local を除外し bughunt.local のみ配線。
 *   bughunt 既定は real-llm (fake_llm off) で install しない。--fake-llm 時のみ install する。
 *   LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
 */
class FakeExternalsServiceProvider extends ServiceProvider
{
    /**
     * 外部サービス fake を許可する環境 allowlist (container bind。per-test 隔離が効くため testing 可)。
     *
     * ★対象は **Stripe 課金 gateway と captcha 検証器**。SSO (Socialite) は同じ capability flag を
     *   使うが env allowlist は別 (SSO_FAKE_ENVIRONMENTS。docs/architecture.md §外部到達点の目録)。
     */
    private const array EXTERNAL_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /**
     * SSO (Socialite) fake を許可する環境 allowlist。
     *
     * ★`EXTERNAL_FAKE_ENVIRONMENTS` と**別定数にする** (値が同じでも概念が違う。
     *   思考原則 4「別物の概念を似ているからで統合しない」)。
     * ★`local` を意図的に除外する。SSO fake は未認証 GET 2 本
     *   (`/auth/{p}/redirect/login` → `/auth/{p}/callback`) で canned アカウントへ
     *   ログインできる = **認証バイパス**であり、かつ `local` は開発者が
     *   実 IdP 連携を確認する唯一の環境である (無言で fake が立つと本番 SSO の回帰を見逃す)。
     */
    private const array SSO_FAKE_ENVIRONMENTS = ['testing', 'bughunt.local'];

    /** LLM (Prism) fake の install を許可する環境 allowlist (Prompt::$fake は static。testing/local を除外) */
    private const array LLM_FAKE_ENVIRONMENTS = ['bughunt.local'];

    public function register(): void
    {
        // capability ごとに独立 private method へ分離する (early return が他 capability を巻き込まない)。
        $this->registerExternalServiceFakes(); // Stripe + captcha: fake_externals 依存 (挙動不変)
        $this->registerSocialAuthFake();       // SSO: fake_externals 依存 / env allowlist は別
        $this->registerStorageFakes();         // storage: fake_storage (FakeStorageGate) 依存 — 独立
    }

    public function boot(): void
    {
        $this->bootLlmFake();       // LLM: fake_llm 依存 (挙動不変)
        $this->bootStorageRoutes(); // storage signed route — 独立
    }

    /** 外部サービス fake (fake_externals + EXTERNAL_FAKE_ENVIRONMENTS。挙動不変) */
    private function registerExternalServiceFakes(): void
    {
        if (config('testing.fake_externals') !== true) {
            return;
        }

        $environment = $this->app->environment();
        if (! in_array($environment, self::EXTERNAL_FAKE_ENVIRONMENTS, true)) {
            Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
                'environment' => $environment,
            ]);

            return;
        }

        // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
        $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
        $this->app->bind(AutoRechargeGatewayInterface::class, FakeAutoRechargeGateway::class);

        // captcha 到達点を fake へ rebind。
        // ★abstract が具象クラスのため、bind を消しても Laravel が本物を自動組み立てし、
        //   RECAPTCHA_SECRET_KEY が設定された瞬間に**無言で** Google siteverify を叩く。
        //   StrayHttpRequestGuard は bug-hunt の別プロセス実行には効かない (AGENTS.md)。
        $this->app->bind(RecaptchaVerifier::class, RecaptchaVerifierTestFake::class);
    }

    /**
     * SSO fake (fake_externals + SSO_FAKE_ENVIRONMENTS)。
     *
     * ★warning ログは出さない。`local` の除外は**誤設定ではなく設計上の除外**であり
     *   (LLM fake と同じ理由)、ここで warning を出すと既存の
     *   `3-4 provider 単体: 外部サービス fake flag on + allowlist 外 env は warning を出す`
     *   が `once()` で固定している呼び出し回数を壊す。
     */
    private function registerSocialAuthFake(): void
    {
        if (config('testing.fake_externals') !== true) {
            return;
        }

        if (! in_array($this->app->environment(), self::SSO_FAKE_ENVIRONMENTS, true)) {
            return;
        }

        // SSO の driver 解決点を fake へ rebind。
        // ★abstract が具象クラスのため、bind を消しても Laravel が本物を自動組み立てし、
        //   **無言で**実 IdP (accounts.google.com 等) へのリダイレクトに戻る (captcha と同じ構図)。
        // ★Socialite の Factory へ直接 bind しない: SocialiteServiceProvider は DeferrableProvider で、
        //   最初の解決時に deferred provider が読み込まれ singleton(Factory) が後勝ちで fake を消す。
        $this->app->bind(SocialiteDriverResolver::class, FakeSocialiteDriverResolver::class);
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
### tests/Support/ExternalFakes/ExternalFakeWiringInventory.php
```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

use App\Http\Controllers\Testing\GetFakeStorageObjectController;
use App\Http\Controllers\Testing\PutFakeStorageObjectController;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Auth\Fakes\FakeSocialiteDriverResolver;
use App\Services\Auth\SocialiteDriverResolver;
use App\Services\Billing\CashierAutoRechargeGateway;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\CashierTicketCheckoutGateway;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use App\Services\Captcha\RecaptchaVerifier;
use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
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
 *   ため inventory の対象外 (ExternalFakeWiringInvariantTest の 3-11 が別枠で見る)。
 */
final class ExternalFakeWiringInventory
{
    /** 外部サービス fake (Stripe 課金 + captcha) の capability flag */
    public const string EXTERNALS_FLAG = 'testing.fake_externals';

    /** storage fake の capability flag */
    public const string STORAGE_FLAG = 'testing.fake_storage';

    /** LLM fake の capability flag (container 差し替えではないため bindings() には現れない) */
    public const string LLM_FLAG = 'testing.fake_llm';

    /** 外部サービス fake の env allowlist (FakeExternalsServiceProvider::EXTERNAL_FAKE_ENVIRONMENTS と対) */
    private const array EXTERNAL_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /** storage fake の env allowlist (FakeStorageGate の predicate と対。testing は runningUnitTests 前提) */
    private const array STORAGE_ENVIRONMENTS = ['testing', 'bughunt.local'];

    /**
     * SSO fake の env allowlist (FakeExternalsServiceProvider::SSO_FAKE_ENVIRONMENTS と対)。
     *
     * ★`local` を含めない。SSO fake は未認証 GET 2 本で canned アカウントへログインできる
     *   = 認証バイパスであり、かつ local は実 IdP 連携を確認する唯一の環境である。
     */
    private const array SSO_ENVIRONMENTS = ['testing', 'bughunt.local'];

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
     * (対照 / 実証 / allowlist 外) が自動的に増える = 書き忘れが構造的に起きない。
     *
     * ⚠️ 新 entry を足す実装者へ: Architecture lane は RefreshDatabase を使わない。
     * abstract / real / fake の constructor が DB に触れないことを必ず確認すること
     * (現行 5 本は確認済み)。
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
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
                risk: 'チケットスポット購入の Stripe Checkout。配線が外れると実 Stripe に実課金セッションを作る。',
            ),
            new ExternalFakeBinding(
                abstract: StripeGatewayInterface::class,
                real: CashierStripeGateway::class,
                fake: FakeStripeGateway::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
                risk: 'サブスク Checkout / Customer Portal。配線が外れると実 Stripe に契約を作る。',
            ),
            new ExternalFakeBinding(
                abstract: AutoRechargeGatewayInterface::class,
                real: CashierAutoRechargeGateway::class,
                fake: FakeAutoRechargeGateway::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
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
            new ExternalFakeBinding(
                abstract: RecaptchaVerifier::class,
                real: RecaptchaVerifier::class,
                fake: RecaptchaVerifierTestFake::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
                risk: 'Google reCAPTCHA siteverify への外向き POST。abstract が具象クラスのため、'
                    .'bind を消しても Laravel が本物を自動組み立てし、RECAPTCHA_SECRET_KEY が'
                    .'設定された環境では無言で実 Google を叩く (bug-hunt の別プロセスには '
                    .'StrayHttpRequestGuard が効かない)。',
            ),
            new ExternalFakeBinding(
                abstract: SocialiteDriverResolver::class,
                real: SocialiteDriverResolver::class,
                fake: FakeSocialiteDriverResolver::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::SSO_ENVIRONMENTS,
                risk: 'SSO (Socialite) の driver 解決点。abstract が具象クラスのため、bind を消しても '
                    .'Laravel が本物を自動組み立てし、**無言で**実 IdP (accounts.google.com 等) への '
                    .'リダイレクトに戻る。bug-hunt のブラウザは別プロセスなので StrayHttpRequestGuard は効かない。',
            ),
        ];
    }
}

```
### tests/Support/ExternalFakes/ExternalFakeBinding.php
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
     * @param  class-string  $real  flag off のときに解決されるべきクラス (厳密一致)
     * @param  class-string  $fake  flag on + allowlist 内で解決されるべきクラス (厳密一致)
     * @param  string  $flag  capability flag の config キー
     * @param  list<string>  $allowedEnvironments  fake を許可する環境 allowlist
     * @param  string  $risk  なぜ外部副作用として危険か (レビュー用説明。機械照合しない)
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
### app/Support/FakeStorageGate.php
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
### tests/Architecture/ExternalFakeWiringInvariantTest.php
```php
<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\FakeExternalsServiceProvider;
use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Prompt;
use Tests\Support\ExternalFakes\ExternalFakeBinding;
use Tests\Support\ExternalFakes\ExternalFakeWiringInventory;
use Tests\Support\ExternalFakes\FakeClassCatalog;
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
 *    「flag を戻して provider を再実走すれば real に戻る」は成立しない
 *    (provider は early return するだけで binding を巻き戻さない) ため、その検査は書かない。
 *  - config / env を書き換える test case は try/finally で原値復元する。
 *  - Prompt::$fake は static なので、test 本体の finally で stopFaking() し、
 *    **同一 test case 内で** isFaking() === false を assert する。
 *    afterEach はフェイルセーフとして併置する (検査表現ではない)。
 *
 * 走査器 (FakeWiringSourceScanner) の限界は tests/Unit/Architecture/FakeWiringSourceScannerTest.php
 * が positive/negative で固定している。到達可能性は判定しない (`if (false) { … }` 中も候補)。
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

/** fake 配線 provider のソース (走査系テストの共通入力。読み取り失敗は例外で落ちる) */
function externalFakeWiringProviderSource(): string
{
    return FakeClassCatalog::sourceOf('app/Providers/FakeExternalsServiceProvider.php');
}

/**
 * bootstrap/providers.php が宣言する provider 一覧。
 *
 * @return list<class-string>
 */
function externalFakeWiringRegisteredProviders(): array
{
    /** @var list<class-string> $providers */
    $providers = require base_path('bootstrap/providers.php');

    return $providers;
}

afterEach(function (): void {
    // フェイルセーフ: LLM fake の static がテスト境界を越えないようにする (検査表現ではない)。
    if (Prompt::isFaking()) {
        Prompt::stopFaking();
    }
});

dataset('external fake bindings', function (): Generator {
    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
        yield $binding->label() => [$binding];
    }
});

dataset('external fake bindings and allowed environments', function (): Generator {
    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
        foreach ($binding->allowedEnvironments as $environment) {
            yield $binding->label().' @ '.$environment => [$binding, $environment];
        }
    }
});

dataset('external fake bindings and denied environments', function (): Generator {
    // production だけでなく staging も見る = 「未知環境で誤設定されても fake しない」という
    // allowlist 方式の趣旨そのものを固定する。
    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
        foreach (['production', 'staging'] as $environment) {
            yield $binding->label().' @ '.$environment => [$binding, $environment];
        }
    }
});

test('3-1 対照: flag off では real 実装が厳密一致で解決される', function (ExternalFakeBinding $binding): void {
    expect(config($binding->flag))->toBeFalse();

    expect(app($binding->abstract)::class)->toBe($binding->real);
})->with('external fake bindings');

test('3-2 実証: flag on + allowlist 環境で fake が厳密一致で解決される',
    function (ExternalFakeBinding $binding, string $environment): void {
        $originalFlag = config($binding->flag);
        $originalEnvironment = $this->app['env'];

        try {
            // 環境ごとに実証する (testing だけだと local / bughunt.local の allowlist が固定されない)。
            // storage は FakeStorageGate が testing ∧ runningUnitTests を要求するが、
            // Architecture lane では runningUnitTests() が true なので成立する。
            $this->app['env'] = $environment;
            config([$binding->flag => true]);

            (new FakeExternalsServiceProvider($this->app))->register();

            // ★厳密一致 (instanceof は使わない。storage fake は real のサブクラス)
            expect(app($binding->abstract)::class)->toBe($binding->fake);
        } finally {
            config([$binding->flag => $originalFlag]);
            $this->app['env'] = $originalEnvironment;
        }
    }
)->with('external fake bindings and allowed environments');

test('3-3 provider 単体: flag on でも allowlist 外 env では real のまま',
    function (ExternalFakeBinding $binding, string $environment): void {
        $originalFlag = config($binding->flag);
        $originalEnvironment = $this->app['env'];

        try {
            $this->app['env'] = $environment;
            config([$binding->flag => true]);

            (new FakeExternalsServiceProvider($this->app))->register();

            expect(app($binding->abstract)::class)->toBe($binding->real);
        } finally {
            config([$binding->flag => $originalFlag]);
            $this->app['env'] = $originalEnvironment;
        }
    }
)->with('external fake bindings and denied environments');

test('3-4 provider 単体: 外部サービス fake flag on + allowlist 外 env は warning を出す', function (): void {
    $originalFlag = config(ExternalFakeWiringInventory::EXTERNALS_FLAG);
    $originalEnvironment = $this->app['env'];

    try {
        Log::spy();

        $this->app['env'] = 'staging';
        config([ExternalFakeWiringInventory::EXTERNALS_FLAG => true]);

        (new FakeExternalsServiceProvider($this->app))->register();

        Log::shouldHaveReceived('warning')->once();
    } finally {
        config([ExternalFakeWiringInventory::EXTERNALS_FLAG => $originalFlag]);
        $this->app['env'] = $originalEnvironment;
    }
});

test('3-5 登録点: bootstrap/providers.php に FakeExternalsServiceProvider が登録されている', function (): void {
    expect(externalFakeWiringRegisteredProviders())->toContain(FakeExternalsServiceProvider::class);
});

test('3-6 登録点: FakeExternalsServiceProvider は AppServiceProvider より後 (後勝ち)', function (): void {
    $providers = externalFakeWiringRegisteredProviders();

    $fakeIndex = array_search(FakeExternalsServiceProvider::class, $providers, true);
    $appIndex = array_search(AppServiceProvider::class, $providers, true);

    expect($fakeIndex)->toBeInt()
        ->and($appIndex)->toBeInt()
        ->and($fakeIndex)->toBeGreaterThan($appIndex);
});

test('3-7 登録点: 起動済み container に provider がロードされている', function (): void {
    expect(array_key_exists(FakeExternalsServiceProvider::class, $this->app->getLoadedProviders()))->toBeTrue();
});

test('3-8 網羅性: provider の bind 組が inventory と集合一致する', function (): void {
    $pairs = FakeWiringSourceScanner::bindPairs(externalFakeWiringProviderSource());

    // closure 差し替え (concrete === null) は「厳密クラス一致で実証できない形」なので許さない
    expect(array_filter($pairs, static fn (array $pair): bool => $pair['concrete'] === null))->toBe([]);

    $actual = array_map(
        static fn (array $pair): string => $pair['abstract'].' => '.$pair['concrete'],
        $pairs
    );
    $expected = array_map(
        static fn (ExternalFakeBinding $binding): string => $binding->abstract.' => '.$binding->fake,
        ExternalFakeWiringInventory::bindings()
    );

    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

test('3-9 網羅性: provider の container 呼び出しは許可された形だけ', function (): void {
    $source = externalFakeWiringProviderSource();

    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([])
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toBe([]);
});

test('3-10 網羅性: provider が参照する fake 系クラスは inventory + 明示例外に一致する', function (): void {
    $candidates = array_values(array_unique(array_merge(
        FakeClassCatalog::implementationClasses(),
        FakeClassCatalog::namedClasses(),
    )));

    $actual = FakeWiringSourceScanner::referencedClasses(externalFakeWiringProviderSource(), $candidates);

    $expected = array_merge(
        array_map(
            static fn (ExternalFakeBinding $binding): string => $binding->fake,
            ExternalFakeWiringInventory::bindings()
        ),
        ExternalFakeWiringInventory::providerReferenceExceptions(),
    );

    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

test('3-11 LLM: bughunt.local ∧ fake_llm=true でのみ Prompt fake が立ち、stopFaking で戻る', function (): void {
    $originalFlag = config(ExternalFakeWiringInventory::LLM_FLAG);
    $originalEnvironment = $this->app['env'];

    try {
        expect(Prompt::isFaking())->toBeFalse();

        // (1) bughunt.local ∧ on → 立つ
        $this->app['env'] = 'bughunt.local';
        config([ExternalFakeWiringInventory::LLM_FLAG => true]);
        (new FakeExternalsServiceProvider($this->app))->boot();
        expect(Prompt::isFaking())->toBeTrue();

        Prompt::stopFaking();

        // (2) testing ∧ on → 立たない (static をテストプロセスで占有させない)
        $this->app['env'] = 'testing';
        (new FakeExternalsServiceProvider($this->app))->boot();
        expect(Prompt::isFaking())->toBeFalse();

        // (3) local ∧ on → 立たない (実 API 検証を潰さない)
        $this->app['env'] = 'local';
        (new FakeExternalsServiceProvider($this->app))->boot();
        expect(Prompt::isFaking())->toBeFalse();

        // (4) bughunt.local ∧ off → 立たない (既定 real LLM)
        $this->app['env'] = 'bughunt.local';
        config([ExternalFakeWiringInventory::LLM_FLAG => false]);
        (new FakeExternalsServiceProvider($this->app))->boot();
        expect(Prompt::isFaking())->toBeFalse();
    } finally {
        // static の往復を**同一 test case 内で** assert する (afterEach はフェイルセーフ)
        if (Prompt::isFaking()) {
            Prompt::stopFaking();
        }
        expect(Prompt::isFaking())->toBeFalse();

        config([ExternalFakeWiringInventory::LLM_FLAG => $originalFlag]);
        $this->app['env'] = $originalEnvironment;
    }
});

test('3-12 mutation coverage: 被覆表のキー集合が想定 mutation ID と一致する', function (): void {
    $keys = array_keys(EXTERNAL_FAKE_WIRING_MUTATION_COVERAGE);
    $ids = EXTERNAL_FAKE_WIRING_SOURCE_MUTATION_IDS;

    sort($keys);
    sort($ids);

    expect($keys)->toBe($ids);
});

```
### tests/Architecture/FakeClassReferenceInvariantTest.php
```php
<?php

declare(strict_types=1);

use App\Providers\FakeExternalsServiceProvider;
use App\Support\FakeStorageGate;
use Tests\Support\ExternalFakes\FakeClassCatalog;
use Tests\Support\ExternalFakes\FakeWiringSourceScanner;

/*
 * 本番コードが fake のクラス名を 1 度も参照しないことの全走査
 * (c2c: external-fakes-wiring-gate 柱 3(c))。
 *
 * fake クラス名は**ディレクトリと命名から動的導出**する (ハードコード一覧を持たない)。
 * 現時点の違反は 0 件 = 「増えないこと」を今固定するのが最安。
 *
 * ★走査候補: 「fake 実装クラス」だけでは足りない。配置例外
 *   (FakeExternalsServiceProvider / FakeStorageGate) を業務コードが参照しても検出できず
 *   偽グリーンになるため、候補は implementationClasses() ∪ placementExceptions() のキーとする。
 *
 * ★走査根: app/ だけだと routes/ に Testing controller を直書きする、config/ にクラス名を書く、
 *   といった抜け道が残る。「本番コード全走査」を名乗る以上、
 *   app/ • routes/ • config/ • bootstrap/ の 4 根を走査する。
 *
 * ★誤検出が出たら allowlist を足す方向へ倒さない。まず「本当に本番コードから fake を
 *   参照しているのか」を疑う (それが本 gate の目的)。
 */

/** 参照 allowlist: fake 系クラスを参照してよい本番ファイル (**repo ルート相対**) */
const FAKE_REFERENCE_ALLOWED = [
    // 唯一の配線点 (何を fake にするかの決定はここに集約する)
    'app/Providers/FakeExternalsServiceProvider.php',
    // fake storage signed route の受け口 (FakeStorageGate 成立時のみ route 登録される)
    'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
    'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
    // bug-hunt 専用の通し確認コマンド。fake storage へ実バイトを置く必要があり、
    // FakeStorageGate 成立時のみ動く (上 2 件の controller と同 species)。
    // 本番経路からは到達しない (artisan 手動実行のみ・スケジュール登録なし)。
    // ★実装条件: constructor 引数を持たず、fake は handle() の fail-secure 4 条件を
    //   通過した**後**にのみ app() で遅延解決する。
    'app/Console/Commands/Development/PipelineSmokeCommand.php',
    // provider 登録点。FakeExternalsServiceProvider (配置例外クラス) を必ず参照する
    'bootstrap/providers.php',
];

test('4-1 配置規約: Fake 命名クラスは Fakes/ か Testing/ 配下にのみ存在する', function (): void {
    $allowed = array_merge(
        FakeClassCatalog::implementationClasses(),
        array_keys(FakeClassCatalog::placementExceptions()),
    );

    $misplaced = array_values(array_diff(FakeClassCatalog::namedClasses(), $allowed));

    expect($misplaced)->toBe([]);
});

test('4-2 配置例外は 2 件から増えていない', function (): void {
    // 増やすときは placementExceptions() に理由を書いたうえで**ここも触る** (意図的な摩擦)。
    expect(array_keys(FakeClassCatalog::placementExceptions()))->toBe([
        FakeExternalsServiceProvider::class,
        FakeStorageGate::class,
    ]);
});

test('4-3 本番コードは fake クラスを参照しない', function (): void {
    $implementations = FakeClassCatalog::implementationClasses();
    $candidates = array_values(array_unique(array_merge(
        $implementations,
        array_keys(FakeClassCatalog::placementExceptions()),
    )));
    $files = FakeClassCatalog::scanFiles();

    // 走査器 / 母集団導出が壊れて「空走査で緑」になるのを防ぐ (fail-closed)
    expect($candidates)->not->toBeEmpty()
        ->and($files)->not->toBeEmpty();

    $violations = [];
    foreach ($files as $file) {
        if (in_array($file, FAKE_REFERENCE_ALLOWED, true)) {
            continue;
        }

        // fake 実装クラス自身が別の fake を参照するのは正当 (FakeTakeObjectStorage → FakeObjectStore 等)
        if (str_starts_with($file, 'app/')
            && in_array(FakeClassCatalog::classFromPath($file), $implementations, true)) {
            continue;
        }

        $referenced = FakeWiringSourceScanner::referencedClasses(
            FakeClassCatalog::sourceOf($file),
            $candidates
        );

        if ($referenced !== []) {
            $violations[] = $file.': '.implode(', ', $referenced);
        }
    }

    expect($violations)->toBe([]);
});

test('4-4 参照 allowlist は 5 件から増えていない', function (): void {
    // 増やすときは理由コメントを添えて**ここも触る** (意図的な摩擦)。
    expect(FAKE_REFERENCE_ALLOWED)->toHaveCount(5)
        ->and(FAKE_REFERENCE_ALLOWED)->toBe([
            'app/Providers/FakeExternalsServiceProvider.php',
            'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
            'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
            'app/Console/Commands/Development/PipelineSmokeCommand.php',
            'bootstrap/providers.php',
        ]);
});

```
### tests/Support/ExternalFakes/FakeWiringSourceScanner.php (抜粋 L40-230)
```php
 *    「絶対に抜けられない」ことは示せない。本 gate が守るのは
 *    **通常の実装作業で起きるドリフト** (差し替えの追加漏れ・登録点の脱落・順序反転・
 *    inventory 未登録) であり、そのために「許可形の列挙 = 閉じた文法」を要求して
 *    未分類の書き方をすべて赤にする、という方針を採る。
 *    新しい抜け道を見つけたら **Unit テスト (5-x) にケースを足して文法を狭める** —
 *    allowlist を広げる方向へは倒さない。
 */
final class FakeWiringSourceScanner
{
    /**
     * 許可する `$this->app-><method>(…)` の呼び出し形 (これ以外はすべて禁止 = deny-by-default)。
     *
     * value は許可する**位置引数の形**:
     * - `classPair`: 位置引数ちょうど 2 個で両方 `::class` 定数 (差し替え本体。組は bindPairs() が inventory 照合)
     * - `allowlistedClass`: 位置引数ちょうど 1 個で MAKE_ALLOWED_ARGUMENTS のいずれか
     * - `none`: 位置引数なし
     *
     * @var array<string, string>
     */
    private const array ALLOWED_APP_CALLS = [
        'bind' => 'classPair',
        'make' => 'allowlistedClass',
        'environment' => 'none',
    ];

    /**
     * `make()` に渡してよいクラス (container 配線を行わないことを分類済みの 2 件のみ)。
     *
     * @var list<class-string>
     */
    private const array MAKE_ALLOWED_ARGUMENTS = [
        FakeStorageGate::class,
        CannedPromptFakeRegistrar::class,
    ];

    /** container へ間接到達するグローバル helper (`use function app as c;` の alias も解決して照合する) */
    private const array CONTAINER_HELPERS = ['app', 'resolve'];

    /** container へ間接到達する静的アクセス起点 (use alias を FQCN 解決して照合する) */
    private const array CONTAINER_STATIC_FQCNS = [
        'Illuminate\Support\Facades\App',
        'Illuminate\Container\Container',
    ];

    /**
     * container へ間接到達する静的アクセス起点の短縮名 (fail-closed の予備線)。
     *
     * `use` が無く FQCN 解決が現在 namespace 配下になってしまう `App::` / `Container::` を
     * 取りこぼさないため、FQCN 一致とは**別に**末尾セグメントでも照合する。
     */
    private const array CONTAINER_STATIC_ROOTS = ['App', 'Container'];

    /** 名前を表すトークン */
    private const array NAME_TOKEN_IDS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /**
     * @param  list<array{id: int, text: string, line: int}>  $tokens  コメント / 空白を除去済み
     * @param  array<string, string>  $useMap  短縮名 => FQCN
     * @param  array<string, string>  $useFunctionMap  短縮名 => 完全修飾関数名 (`use function … as …`)
     * @param  list<bool>  $isImportToken  namespace / use 文に属するトークンか
     * @param  list<bool>  $isDeclarationName  クラス様宣言の名前トークンか
     */
    private function __construct(
        private readonly array $tokens,
        private readonly string $namespace,
        private readonly array $useMap,
        private readonly array $useFunctionMap,
        private readonly array $isImportToken,
        private readonly array $isDeclarationName,
    ) {}

    /**
     * 許可外の `$this->app-><method>(…)` 呼び出し。
     *
     * @return list<string> 人間可読な違反説明 (行番号付き)
     */
    public static function disallowedContainerCalls(string $source): array
    {
        $scanner = self::analyze($source);
        $violations = [];

        foreach ($scanner->appMethodCalls() as $call) {
            $method = $call['method'];
            $line = $call['line'];

            if (! array_key_exists($method, self::ALLOWED_APP_CALLS)) {
                $violations[] = "{$method}(…) は許可された container 呼び出し形ではない (line {$line})";

                continue;
            }

            // 名前付き引数 / spread unpack は引数の形を機械照合できないため fail-closed で禁止する。
            foreach ($call['args'] as $arg) {
                if (self::isNamedArgument($arg) || self::containsUnpack($arg)) {
                    $violations[] = "{$method}(…) は名前付き引数 / unpack を使っている (line {$line})";

                    continue 2;
                }
            }

            $shape = self::ALLOWED_APP_CALLS[$method];
            $arguments = $call['args'];

            if ($shape === 'none' && $arguments !== []) {
                $violations[] = "{$method}(…) は引数なしでのみ許可される (line {$line})";

                continue;
            }

            if ($shape === 'classPair') {
                if (count($arguments) !== 2
                    || ! self::isClassConstant($arguments[0])
                    || ! self::isClassConstant($arguments[1])) {
                    $violations[] = "{$method}(…) は位置引数ちょうど 2 個かつ両方 ::class 定数でなければならない (line {$line})";
                }

                continue;
            }

            if ($shape === 'allowlistedClass') {
                if (count($arguments) !== 1 || ! self::isClassConstant($arguments[0])) {
                    $violations[] = "{$method}(…) は位置引数ちょうど 1 個の ::class 定数でなければならない (line {$line})";

                    continue;
                }

                $resolved = $scanner->resolve($arguments[0][0]['text']);
                if (! in_array($resolved, self::MAKE_ALLOWED_ARGUMENTS, true)) {
                    $violations[] = "{$method}({$resolved}::class) は許可されていない (line {$line})";
                }
            }
        }

        return $violations;
    }

    /**
     * `$this->app->bind(A::class, B::class)` の (abstract, concrete) 組 (**FQCN 正規化済み**)。
     *
     * 第 2 引数が `::class` 定数でない (closure 等) 場合は concrete を `null` として返し、
     * 呼び出し側テストで「fake 差し替えは ::class 対 ::class の形に限る」を fail させる。
     * 第 1 引数が `::class` 定数でない形 (変数 abstract など) は組として読み取れないため返さない
     * (disallowedContainerCalls() が別途 fail させる = 見落としにはならない)。
     *
     * @return list<array{abstract: class-string, concrete: class-string|null}>
     */
    public static function bindPairs(string $source): array
    {
        $scanner = self::analyze($source);
        $pairs = [];

        foreach ($scanner->appMethodCalls() as $call) {
            if ($call['method'] !== 'bind' || count($call['args']) < 2) {
                continue;
            }
            if (! self::isClassConstant($call['args'][0])) {
                continue;
            }

            /** @var class-string $abstract */
            $abstract = $scanner->resolve($call['args'][0][0]['text']);

            $concrete = null;
            if (self::isClassConstant($call['args'][1])) {
                /** @var class-string $concrete */
                $concrete = $scanner->resolve($call['args'][1][0]['text']);
            }

            $pairs[] = ['abstract' => $abstract, 'concrete' => $concrete];
        }

        return $pairs;
    }

    /**
     * `$this->app` のメソッド呼び出し以外で container へ到達する形 (未知 API 経由の抜け道封じ)。
     *
     * 検出対象: `app(` / `resolve(` / `App::` facade / `Container::getInstance()` /
     * `$this->app` の**メソッド呼び出し以外の出現** (変数への代入・引数渡し・ArrayAccess)。
     *
     * @return list<string>
     */
    public static function disallowedIndirectAccess(string $source): array
    {
        $scanner = self::analyze($source);
        $violations = [];
        $count = count($scanner->tokens);

        foreach ($scanner->appAccessIndexes() as $index) {
            if ($scanner->isAppMethodCallAt($index)) {
                continue;
```
### app/Support/ProductionEnvGuard.php (抜粋 L85-110)
```php
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
```
### scripts/bug-hunt-shard.sh (cmd_provision 抜粋 L1105-1132 / cmd_reseed 抜粋 L1339-1351)
```bash
    prepare_mode_and_preflight

    clear_stale_config
    ensure_fresh_assets

    # (a) DB 作成 (admin 経路。既存なら skip。中身は次の migrate:fresh が正本)
    if ! pg_owner_for_shard exists "${db}" | grep -q 1; then
        pg_admin_for_provision createdb "${db}"
    fi

    # (b) migrate:fresh + seed (runtime 経路、自 DB のみ)。テンプレート共通シーダーのみ実行する
    #     (ドメイン固有シーダーはアプリ側で本ブロックに追記する)。
    artisan_for_shard "${db}" "${url}" migrate:fresh --seed --force
    artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
    # 有料プラン組織に active subscription + 初期チケットを付与 (三重ガード付き)。
    # free 組織は未契約のまま = 課金なし経路の探索能力を温存する。
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntBillingSeeder --force
    # 管理画面 (Filament admin) 探索用 admin user。AdminUserSeeder は local 限定 (DatabaseSeeder が
    # local でしか呼ばない) のため bughunt では明示 seed する。admin MFA は .env.bughunt.local の
    # ADMIN_MFA_REQUIRED=false で無効化済 (email+password ログイン可)。
    artisan_for_shard "${db}" "${url}" db:seed --class=AdminUserSeeder --force
    # CLI OAuth client + CLI session + legacy MCP token を直付与 (fake_externals かつ bughunt.local かつ
    # bug_hunt DB の三重ガード付き。config('testing.fake_externals') 未導入なら seeder 側で no-op)。
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntOAuthSeeder --force

    # (b2) Filament 静的アセット publish (F-13 対策)。冪等 (marker + 実在確認で skip)。
    ensure_filament_assets "${db}" "${url}"

...
cmd_reseed() {
    local shard=$1 run_id=$2
    local db url
    db="$(shard_db "${shard}")"; url="$(shard_url "${shard}")"
    artisan_for_shard "${db}" "${url}" migrate:fresh --seed --force
    artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
    # 有料プラン組織に active subscription + 初期チケットを付与 (三重ガード付き)。
    # free 組織は未契約のまま = 課金なし経路の探索能力を温存する。
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntBillingSeeder --force
    artisan_for_shard "${db}" "${url}" db:seed --class=AdminUserSeeder --force
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntOAuthSeeder --force
    echo "reseeded: ${db}"
}
```
### tests/Architecture/BughuntOrchestratorGateInvariantTest.php (抜粋 L24-115 関数窓ヘルパ)
```php
{
    $contents = file_get_contents(base_path($relativePath));
    expect($contents)->toBeString("{$relativePath} が読めない");
    /** @var string $contents */
    expect($contents)->not->toBe('', "{$relativePath} が空");

    return $contents;
}

/**
 * `^name()` 行から次の `^cmd_` 定義 (または EOF) までの関数窓を切り出す。
 *
 * 非貪欲 `\n\}` 終端は使わない: 関数本体が heredoc (`<<'PY'` 等) 内に行頭 `}` を持つと
 * 最短マッチがそこで止まり真の末尾を取り逃す。`/m` + 先読みで「次の cmd_ 定義の直前まで」
 * を取れば heredoc 持ち関数でも安全側に切り出せる。
 */
function bughuntGateFunctionWindow(string $source, string $name): string
{
    $m = [];
    // cmd_provision と cmd_provision_all を取り違えないよう `()` まで含めてアンカーする。
    $matched = preg_match('/^'.preg_quote($name, '/').'\(\)[\s\S]*?(?=^cmd_|\z)/m', $source, $m);
    expect($matched)->toBe(1, "関数窓が見つからない: {$name}");

    /** @var array{0: string} $m */
    return $m[0];
}

/**
 * heredoc を持たない関数 (require_orchestrator) 用の窓。行頭 `}` で終端する。
 * cmd_ 窓 (次の cmd_ 定義まで) は他関数を巻き込むため、gate 本体の検査には使わない。
 */
function bughuntGateBraceWindow(string $source, string $name): string
{
    $m = [];
    $matched = preg_match('/^'.preg_quote($name, '/').'\(\)\s*\{[\s\S]*?^\}/m', $source, $m);
    expect($matched)->toBe(1, "関数窓が見つからない: {$name}");

    /** @var array{0: string} $m */
    return $m[0];
}

/**
 * `local ...` 行が「副作用を持たない引数束縛」かを判定する。
 *
 * bash の `local x="$(cmd)"` / `local x=`cmd`` / `local x=$(< <(cmd))` は **コマンドを実行する**。
 * `local` を一律で前置きとみなすと、gate より前に任意コマンドを差し込めてしまい
 * 「gate が最初の実効文」の保証が silent hole になる (impl-review R1 Critical)。
 * したがって command substitution / process substitution / backtick を含む `local` は
 * **実効文として扱う** (= gate より前にあれば fail させる)。
 */
function bughuntGateIsInertLocal(string $trimmed): bool
{
    if (preg_match('/^local\s/', $trimmed) !== 1) {
        return false;
    }

    // $(...) / `...` / <(...) / >(...) はいずれもコマンドを起動する。
    return preg_match('/\$\(|`|<\(|>\(/', $trimmed) !== 1;
}

/**
 * 関数窓から「最初の実効文」を返す。関数定義行・`{`・コメント・空行・引数束縛のみの
 * `local ...` 宣言は読み飛ばす (= 副作用を持たない前置き)。
 * ただしコマンド置換を含む `local` は副作用を持つため読み飛ばさない。
 *
 * aigenba 版の「gate が特定の呼び出しより前に現れる」より強く、「gate が最初の実効文である」
 * ことを直接固定する (AI-CUE の cmd_teardown は aigenba と本体構造が異なり、
 * 特定呼び出しへのアンカーが脆いため)。
 */
function bughuntGateFirstEffectiveStatement(string $window): string
{
    // `/u` は必須 (PcreUnicodeModifierGateTest): 非 UTF-8 モードの `\R` はバイト 0x85 (NEL)
    // にも一致し、日本語コメントを文字途中で分断して行構造を壊す。
    foreach (preg_split('/\R/u', $window) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || $trimmed === '{') {
            continue;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\(\)\s*\{?$/', $trimmed) === 1) {
            continue; // 関数定義行
        }
        if (bughuntGateIsInertLocal($trimmed)) {
            continue; // 引数束縛のみ (副作用なし)
        }

        return $trimmed;
    }

    return '';
}

test('bug-hunt-shard.sh の require_orchestrator が default-deny (token 無し → die 1) であること', function (): void {
```

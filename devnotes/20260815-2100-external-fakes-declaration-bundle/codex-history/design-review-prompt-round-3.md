Round 2 の指摘へ対応した。対応マトリクスと修正後の詳細設計書 (全文) を示す。

# 対応マトリクス: design-review Round 2

## [Critical] 施策 1: 宣言から entry を消す変異を検出できない
- 判断: 対応する (ただし提案された `ExternalSeamInventory` との集合一致は採らない)
- 根拠:
  - 指摘は正しい。宣言が唯一の正本になると、削除時に provider の bind もデータセットも
    同時に縮むため、どの検査にも映らない。旧 3-8 が持っていた検出力である。
  - 一方 `ExternalSeamInventory` との集合一致は採れない。同目録は
    **ファイル保存 (AWS / Flysystem) と LLM (Prism) を意図的に母集団へ入れない**
    (AGENTS.md ドメイン規約 9。理由は「同じ到達事実を 2 箇所で宣言しない」)。
    採ると 7 件中 5 件しか覆えず、覆うために目録の母集団を歪めることになる (思考原則 4)。
- 対応内容: 3-16 を新設し、`swaps()` の abstract 一覧を**件数付きで gate 側に写して固定**する。
  本リポジトリに同じ作法の先例が 2 つある (`FakeClassReferenceInvariantTest` の
  「配置例外は 2 件から増えていない」「参照 allowlist は 5 件から増えていない」)。
  リスク欄に「3-16 を消すと削除が無音になる」ことと、上の不採用理由を明記した。

## [Warning] 施策 1: 「DTO を返す」の記述が設計と不一致
- 判断: 対応する
- 対応内容: 「外部応答ではなく内部の宣言データなので DTO / JsonResource の対象外。
  差し替え 1 本は値オブジェクト `ExternalFakeBinding` を使う」へ書き換えた。

## [Warning] 施策 1c: `bindPairs()` は `app()->bind(…)` を読めない
- 判断: 対応する
- 根拠: 指摘のとおり。現行の `bindPairs()` は `$this->app->bind(…)` だけを読み、
  `app()->bind(…)` は別経路 (`disallowedIndirectAccess`) で「間接到達」として扱われている。
  レーン側は素直に `app()->bind(…)` と書くため、そのままでは素通りする。
- 対応内容: `bindPairs()` を container へ到達する 4 形 (`$this->app->bind` / `app()->bind` /
  `App::bind` / `Container::getInstance()->bind`) に対応させ、`use function app as …` の
  別名解決も使う。自己検査に 4 形 + 別名の正例と、`::class` でない負例を入れる。
  provider 側の検査 (`disallowedContainerCalls` / `disallowedIndirectAccess`) は変えない。

## [Warning] 施策 2: S-9 が実在確認だけで対応を保証しない
- 判断: 対応する
- 対応内容: S-9 を 3 条件へ (実在 / `tests/Feature/` 配下 / **テストのソースが対象 seeder
  クラスを参照している**)、加えて S-10 (無関係な既存テストへ差し替えると赤くなる負のコントロール)
  を追加した。ガードを要求しない区分は `null` 固定 (値があったら赤) も明記。

## [Warning] 施策 2: 前提テストの紐づけが entry の一部か別 mapping か曖昧
- 判断: 対応する
- 対応内容: **entry のフィールド**に固定した
  (`array{role: BughuntSeedRole, reason: string, guardPremiseTest: non-empty-string|null}`)。
  別 mapping にするとキー集合の一致検査が別途要り、目録が 2 つに割れるため。

## [Critical] 施策 4: 子プロセスの環境隔離の説明が矛盾 / `.env` 経由で実資格情報が入る
- 判断: 対応する
- 根拠: 指摘のとおり。「明示分だけ上書き」は親の環境を継承する意味になり、
  かつ Laravel が `.env` を読む限り実資格情報は子の設定へ入る。
- 対応内容: 隔離を 3 段で定義し直した。
  1. プロセスの環境変数は `env -i` で空にしてから必要分だけ渡す
     (bug-hunt スクリプトが DB 資格情報を遮断するときと同じ手)
  2. 設定の出所を**専用の一時環境ファイル 1 つだけ**にする
     (子が `useEnvironmentPath()` / `loadEnvironmentFrom()` で固定。親の `.env` /
     `.env.bughunt.local` は読ませない)
  3. 書いてよいキーを 7 つに限り (`ALLOWED_ENV_KEYS`)、P-6 が集合で固定する。
     受入の変異にも「一時環境ファイルへ `STRIPE_SECRET` を足すと赤くなる」を追加した

## [Critical] 施策 4: probe の `$provider` が未定義
- 判断: 対応する
- 対応内容: `config('template.social_providers')` (現行 shape は 種類名 => 設定 の連想配列) から
  `array_key_first()` で取り、`Assert::isArray()` / `Assert::stringNotEmpty()` で
  空・非文字列を fail-closed に落とすコードを probe へ書いた。

## [Warning] 施策 4: 設定キャッシュ有無の期待動作が未定義
- 判断: 対応する (2 択のうち「キャッシュ無しの隔離条件で観測する」を選ぶ)
- 根拠: 共有の `bootstrap/cache/config.php` を作ったり消したりする方式は、指摘のとおり
  並列実行と衝突する。
- 対応内容: `APP_CONFIG_CACHE` を存在しない一時パスへ向け、**キャッシュ無しの起動**として
  観測することを明記。あわせて「キャッシュ有りの起動は観測しない (その事故は施策 3 の
  二重判定が受け持つ)」を保証しないものとして書いた。

## [参考] P-3 / P-4 の再判定
- Round 2 で「反論は妥当」と再判定され、追加の変更要求は無し。設計はそのまま維持する。


---

## 修正後の詳細設計書 (全文)

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
- [x] 3-10 を強化: provider が参照する fake 系クラスは**空**であること
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
- [x] `ShellFunctionWindow::of()` は `string` を返し、見つからなければ例外 (null 返しにしない)

### テスト計画

- [x] S-1〜S-8 を Pest の Architecture レーンで実行 (DB 不使用)
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
    $app->useEnvironmentPath(getenv('FAKE_WIRING_PROBE_ENV_DIR'));
    $app->loadEnvironmentFrom(getenv('FAKE_WIRING_PROBE_ENV_FILE'));

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
 * 一時環境ファイルに書いてよいキーは `ALLOWED_ENV_KEYS` の 7 つだけである
 * (`APP_ENV` / `APP_KEY` / `CIPHERSWEET_KEY` / 3 つのフラグ / `APP_DEBUG`)。
 * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
 * キャッシュ有りの起動は観測しない (キャッシュが古いときの挙動は本観測の範囲外で、
 * 本番混入防止は施策 3 の二重判定が受け持つ)。
 *
 * @return array{exitCode: int, output: array<string, mixed>}
 */
public static function run(string $environment, bool $fakeExternals, bool $fakeStorage, bool $fakeLlm): array;

/** 一時環境ファイルに書いてよいキー (deny-by-default。実資格情報のキーは 1 つも無い) */
public const array ALLOWED_ENV_KEYS = [
    'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'CIPHERSWEET_KEY',
    'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
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
| P-6 | 起動側が作る一時環境ファイルのキー集合が `ALLOWED_ENV_KEYS` の**部分集合**であること (実資格情報を子へ渡す変更をその場で落とす) |

- P-4 が意図どおり効く根拠: `AppServiceProvider::boot()` は
  `ProductionEnvGuard::enforce()` を**最初に**呼ぶため、他の起動時検査より先に
  この違反が出る。P-4 は 2 段で表明する — (a) **非ゼロ終了**すること (順序に依存しない)、
  (b) 出力に `TESTING_FAKE_EXTERNALS` が現れること (順序に依存する)。
  (b) が落ちたら「起動時検査の順序が変わった可能性」を失敗メッセージに書く
  (依存を隠さず、赤で気づける形にする)
- **子プロセスへ実際の外部資格情報を渡さない**。プロセスの環境変数は `env -i` で空にし、
  設定は専用の一時環境ファイル 1 つだけから読む。書いてよいキーは 7 つで、
  そこに外部サービスの資格情報は 1 つも無い (P-6 が集合で固定する)。
  本物側の解決に資格情報は要らない (現行の `CashierStripeGateway` はコンストラクタで
  Stripe 資格情報を受け取らない)

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
      「偽の外部ログインの転送先を実 IdP に戻す」
      「一時環境ファイルへ `STRIPE_SECRET` を足す」の 3 通りで**赤くなる**ことを確かめる

### リスク

- **子プロセスの起動は環境差で壊れやすい**。→ 責務を 4 つに限定し、
  DB・HTTP サーバ・ブラウザに触れない。必要な環境変数は親から明示的に渡す
- **`.env` が全く無い環境で起動できない可能性**。→ 子は親の `.env` を読まない設計なので、
  `APP_KEY` / `CIPHERSWEET_KEY` は親の解決済み設定から一時環境ファイルへ書き出す。
  それでも起動できない場合は**skip せず赤にする** (静かに検査が消える形を作らない)
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
3. 施策 1 の Architecture テストを更新 (3-8 削除 / 3-10 強化 / 3-13〜3-15 追加)
4. 施策 1c → 施策 2 → 施策 3 → 施策 4 の順に積む
5. 施策 5 の文書を書き、`mutation-evidence.md` に変異の 2 段確認の記録を残す


---

再レビューを依頼する。施策ごとの判定と全体判定 (APPROVED / CHANGES_REQUESTED) を明示すること。

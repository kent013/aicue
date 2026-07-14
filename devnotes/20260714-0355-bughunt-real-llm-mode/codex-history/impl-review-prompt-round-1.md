# 実装レビュー依頼 (T036: bughunt real-llm モード)

## アプリの使命 (North Star)
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


## 思考原則 — 全議論に適用
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。設計の方向性が正しいと確認できてから調整せよ。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: レビュアー役割

あなたは Laravel + Svelte + bash の実装レビュアー。本 diff (TODO T036) をレビューする。
観点:
1. **設計との一致性**: 下記詳細設計書の 8 施策 (config 3フラグ分離 / provider boot 条件を fake_llm へ / ProductionEnvGuard の 2 guard 追加 / bug-hunt-shard.sh のモードフラグ・実キー注入・fail-fast・self-test / env example / SKILL.md / story / テスト) を正しく実装しているか。
2. **正確性・回帰**: 既存の dev DB 保護 (env -i 隔離・用途別 guard・orchestrator gate)・coverage・worker 配線を壊していないか。特に scripts/bug-hunt-shard.sh の env -i 展開順・クォート、秘密 (ANTHROPIC_API_KEY) の xtrace/echo/manifest 非漏洩。
3. **PHPStan 適合** (level 10)・DTO/JsonResource パターン (本 item は該当なし)。
4. **テスト網羅性**: フラグ/キー分離・fail-fast・秘密漏洩防止・引数解析 (相互排他/provision専用) を self-test [z] が固定しているか。provider boot / guard / config 既定の PHP テスト。
5. **セキュリティ**: real-llm 既定で実キーが serve/worker のみに載り、migrate/seed/verify/echo/manifest に漏れないか。
6. **不要な複雑化**の有無。

出力形式: ファイルごとに [Critical]/[Warning]/[Suggestion] 分類 + 全体判定 **APPROVED / CHANGES_REQUESTED**。

---

## user

### 品質ゲート結果
- composer test: 1655 passed / 2 skipped / 0 failed
- composer phpstan: No errors (level 10, 638 files)
- vendor/bin/pint --test: passed
- pnpm lint / typecheck / test (535) / build: all green
- bash scripts/bug-hunt-shard.sh self-test: all passed ([a]〜[z] 全通過。[z] は新規モード制御セクション)
- bash scripts/bug-hunt-inventory-check.sh: drift なし

### 詳細設計書
```markdown
# 詳細設計: bughunt real-llm モード (既定) と fake-llm / real-storage オプション

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を
生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置 (SECI)。
- v1 スコープ: 字幕のみ / 撮影は PWA (同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md）

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び (`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き (`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）、**RefreshDatabase** + `--parallel`（`tests/Pest.php` グローバル適用、
  個別 `DatabaseTransactions` 禁止）
- テストデータは Factory 生成。DTO + JsonResource パターン。アーリーリターン推奨。
- `composer fix`（Pint）/ `pnpm lint:fix`。PHP 8.4 + Laravel 12 + Svelte 5 + Inertia + TypeScript。
- 本 item は **config / provider / support / shell script / doc 中心**で、新モデル・API・DTO・TypeScript 型・
  Inertia Props の追加は**なし**（波及セクションで「なし」を明示）。

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex `gpt-5.4` レビュー Round 4 で **APPROVED**）
- レビュー履歴: `conceptual-review-round-{1..4}.md` / `codex-history/conceptual-review-*`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `config/testing.php` に `fake_llm` / `fake_storage` を追加（判定軸の系統別分離） | `config/testing.php` | High |
| 2 | `FakeExternalsServiceProvider::boot()` の LLM fake 条件を `fake_externals` → `fake_llm` へ | `app/Providers/FakeExternalsServiceProvider.php` | High |
| 3 | `ProductionEnvGuard` に `fake_llm` / `fake_storage` の production fail-secure guard を追加 | `app/Support/ProductionEnvGuard.php` | High |
| 4 | `scripts/bug-hunt-shard.sh` にモードフラグ・実キー注入・fail-fast・self-test 分岐を追加 | `scripts/bug-hunt-shard.sh` | High |
| 5 | `.env.bughunt.local.example` にフラグ説明・実キー注入の記載を追加 | `.env.bughunt.local.example` | Medium |
| 6 | `app-bug-hunt/SKILL.md` の既定引数・禁止事項 4・モード表・環境前提を real-llm 前提へ | `.claude/skills/app-bug-hunt/SKILL.md` | Medium |
| 7 | `stories/S3-core-journey.md` を実 AI 走行前提へ更新 | `.claude/skills/app-bug-hunt/stories/S3-core-journey.md` | Low |
| 8 | 既存テストの波及更新 + 新規テスト（provider boot 条件 / guard / config 既定） | `tests/Feature/Providers/FakeExternalsServiceProviderTest.php`, `tests/Feature/Support/ProductionEnvGuardTest.php`, 新規 `tests/Feature/Config/TestingFlagsDefaultTest.php` | High |

---

## 施策 1: `config/testing.php` に `fake_llm` / `fake_storage` を追加

### 変更箇所
- ファイル: `config/testing.php`（全体。現在 `fake_externals` のみ）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 新規 `tests/Feature/Config/TestingFlagsDefaultTest.php`（施策 8）。既存
  `FakeExternalsServiceProviderTest` / `ProductionEnvGuardTest` の baseline に新 key を追加（施策 8）。

### 現行コード
```php
return [
    'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),
];
```

### 変更後コード
```php
return [

    /*
    | fake_externals: Stripe 課金 fake の capability flag（既定 false = no-op）。
    | 有効化は allowlist 環境（local / testing / bughunt.local）に限定、production は
    | ProductionEnvGuard が true を fail-fast で拒否する。
    */
    'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),

    /*
    | fake_llm: LLM (Prism) fake を install するか。config 既定 false = real LLM。
    | bughunt は既定 real-llm（scripts/bug-hunt-shard.sh が TESTING_FAKE_LLM=false を明示注入）。
    | --fake-llm 指定時のみ true 注入 → FakeExternalsServiceProvider::boot が
    | CannedPromptFakeRegistrar を install（env allowlist bughunt.local のみ）。
    | production では ProductionEnvGuard が true を fail-fast で拒否する。
    */
    'fake_llm' => (bool) env('TESTING_FAKE_LLM', false),

    /*
    | fake_storage: S3 ストレージ fake トグル（骨子）。config 既定 false = 本番安全側。
    | bughunt は既定 fake（scripts/bug-hunt-shard.sh が TESTING_FAKE_STORAGE=true を明示注入）。
    | --real-storage 指定時のみ false 注入。※実 S3 接続の実配線は本 item スコープ外（consumer 未実装 = inert）。
    | production では ProductionEnvGuard が true を fail-fast で拒否する。
    */
    'fake_storage' => (bool) env('TESTING_FAKE_STORAGE', false),

];
```

### PHPStan 適合チェック
- [x] `(bool) env(...)` で戻り値 bool 固定（`mixed` 分岐なし）。config ファイルは array<string,mixed> 返却で従来同様。
- [x] 新規 null 参照なし。

### テスト計画
- [x] 新規 `TestingFlagsDefaultTest`: testing 環境で `config('testing.fake_llm') === false` かつ
  `config('testing.fake_storage') === false`（phpunit.xml は両 env を設定しない = config 既定を固定）。
- [x] bool 型であること（`toBeBool()`）を assert し `mixed` 前提を排除（Codex 概念 R1 Suggestion）。

### リスク
- 低。既存 `fake_externals` の挙動は不変。新 key は既定 false で誰も読まなければ no-op。

---

## 施策 2: `FakeExternalsServiceProvider::boot()` の LLM fake 条件を `fake_llm` へ

### 変更箇所
- ファイル: `app/Providers/FakeExternalsServiceProvider.php`（`boot()` の early-return 条件 + クラス docblock）

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: **`tests/Feature/Providers/FakeExternalsServiceProviderTest.php` の boot 系テスト
  （L63-133）を `fake_externals` → `fake_llm` キーへ更新（施策 8、必須波及）**。
- **`register()`（Stripe）経路は不変**（`fake_externals` 依存のまま）。Stripe 系テスト（L32-61）は変更しない。

### 現行コード
```php
public function boot(): void
{
    if (config('testing.fake_externals') !== true) {
        return;
    }
    if (! in_array($this->app->environment(), self::LLM_FAKE_ENVIRONMENTS, true)) {
        return;
    }
    $this->app->make(CannedPromptFakeRegistrar::class)->install();
}
```

### 変更後コード
```php
public function boot(): void
{
    // LLM fake は fake_llm（既定 false = real LLM）で判定する。bughunt 既定は real-llm で、
    // --fake-llm 指定時のみ TESTING_FAKE_LLM=true が注入され install される。
    // Stripe fake（register）は従来どおり fake_externals 依存で不変。
    if (config('testing.fake_llm') !== true) {
        return;
    }

    // Prompt::$fake（プロセスグローバル static）を書き換えるため、per-test で static を占有する
    // testing、実 API 検証を潰す local は allowlist から除外する（bughunt.local のみ）。
    if (! in_array($this->app->environment(), self::LLM_FAKE_ENVIRONMENTS, true)) {
        return;
    }

    // Browser lane（tests/Pest.php）と同一の install API（Prompt::installFake の封じ込め）。
    $this->app->make(CannedPromptFakeRegistrar::class)->install();
}
```

- クラス docblock も更新: 「LLM (Prism): `fake_llm` が capability flag。bughunt 既定は real-llm（fake_llm off）で
  install しない。`--fake-llm` 時のみ bughunt.local で install」へ。`register()` は「Stripe: `fake_externals`
  依存で不変」と明記。**「LLM fake 許可環境は `bughunt.local` のみ（定数 `LLM_FAKE_ENVIRONMENTS` が正本）」を
  boot() docblock に 1 行で明示**（docblock/定数/テスト名の三者一致。Codex 詳細 R1 Warning 反映）。

### PHPStan 適合チェック
- [x] `config('testing.fake_llm')` は `!== true` の厳密比較（mixed → bool 判定は既存 `fake_externals` と同一パターン）。
- [x] 戻り値 void、null 参照なし、generics 変更なし。

### テスト計画（施策 8 に集約、要点）
- [x] `boot: env=bughunt.local ∧ fake_llm=true` で `Prompt::isFaking()===true` かつ canned 応答（実 API 未到達）。
- [x] `boot: fake_llm=false` では bughunt.local でも install しない（`Prompt::isFaking()===false` = real 経路）。
- [x] `boot: env=testing/local ∧ fake_llm=true` では install しない（static 占有回避）。
- [x] `register()`（Stripe）は `fake_externals` 依存のまま不変であることを既存テストで確認（変更しない）。

### リスク
- **中**: bughunt が `fake_externals=true` のまま `fake_llm` 未注入で走ると LLM が real になる。これは意図した
  既定（real-llm）だが、実キー未設定だと 401。→ 施策 4 の provision fail-fast で構造的に防ぐ。
- 既存 Browser lane / Feature の Prism fake 経路は `CannedPromptFakeRegistrar` を明示 install するため影響なし。

---

## 施策 3: `ProductionEnvGuard` に `fake_llm` / `fake_storage` guard を追加

### 変更箇所
- ファイル: `app/Support/ProductionEnvGuard.php`（`violations()` 内、`fake_externals` ブロックの直後）+ クラス docblock

### 波及変更
- テストファイル: **`tests/Feature/Support/ProductionEnvGuardTest.php` の `beforeEach` baseline に
  `fake_llm=false` / `fake_storage=false` を追加**（追加しないと「全項目埋め = violations 空」テストが
  新 guard で 2 件検出して落ちる、必須波及）+ 新規 violation テスト 2 件（施策 8）。

### 現行コード（抜粋）
```php
if (config('testing.fake_externals') === true) {
    $errors[] = 'TESTING_FAKE_EXTERNALS must be false in production '
        .'(external fakes must never be enabled in production).';
}
```

### 変更後コード（追加分）
```php
if (config('testing.fake_externals') === true) {
    $errors[] = 'TESTING_FAKE_EXTERNALS must be false in production '
        .'(external fakes must never be enabled in production).';
}

// LLM fake は production で real LLM を潰すため禁止（fake_externals と同じ fail-secure）。
if (config('testing.fake_llm') === true) {
    $errors[] = 'TESTING_FAKE_LLM must be false in production '
        .'(LLM fake must never be enabled in production).';
}

// storage fake は production で実ストレージを潰し得るため禁止。
if (config('testing.fake_storage') === true) {
    $errors[] = 'TESTING_FAKE_STORAGE must be false in production '
        .'(storage fake must never be enabled in production).';
}
```

- クラス docblock の検査項目リストに 2 行追記（`TESTING_FAKE_LLM=false` / `TESTING_FAKE_STORAGE=false`）。

### PHPStan 適合チェック
- [x] 既存 `fake_externals` ブロックと同一パターン（`=== true` 厳密比較、`$errors` は `list<string>`）。
- [x] 戻り値型・generics 不変。

### テスト計画（施策 8）
- [x] `TESTING_FAKE_LLM=true` なら violation 1 件（メッセージに `TESTING_FAKE_LLM`）。
- [x] `TESTING_FAKE_STORAGE=true` なら violation 1 件（メッセージに `TESTING_FAKE_STORAGE`）。
- [x] baseline（全項目正常 + 新 key false）で violations 空を維持。
- [x] `enforce()` の RuntimeException 経路は既存テストで担保。

### リスク
- 低。production 起動時（AppServiceProvider::boot）と `production:preflight` の双方が同 guard を参照するため、
  新 guard は両経路で自動的に効く。

---

## 施策 4: `scripts/bug-hunt-shard.sh` にモードフラグ・実キー注入・fail-fast・self-test 分岐

本 item の中核。**dev DB 保護の `env -i` 隔離・用途別 guard・orchestrator gate は一切壊さない**。

### 変更箇所（機能単位）

1. **引数解析（`main`）**: `--real-llm` / `--fake-llm` / `--real-storage` を追加。グローバル
   `LLM_MODE`（`real`|`fake`、既定 `real`）と `STORAGE_MODE`（`fake`|`real`、既定 `fake`）を確定。
   - `--real-llm` と `--fake-llm` の同時指定は **fail-fast**（Codex 概念 R3）。
   - **モードフラグ（3 種いずれか）が指定されたら** `--coverage` と同様 **provision / provision-all 専用**
     （それ以外の subcommand に付くと exit 2。`teardown --real-llm` 等も拒否。Codex 詳細 R2 反映）。
2. **親 dotenv reader**（`MAIN_ENV_FILE=".env"`、sandbox 差し替え可）: `ANTHROPIC_API_KEY` を xtrace 無効区間で読む。
3. **モード env 構築（`build_mode_env`）**: **`MODE_ENV`（フラグのみ）と `LLM_KEY_ENV`（実キー）を分離**。
   両フラグを常に明示注入、real-llm 時のみ `LLM_KEY_ENV` に実キーを載せる（最小権限）。秘密取扱区間は xtrace 退避。
4. **実キー fail-fast（`assert_llm_key_present`）**: real-llm ∧ キー空/未設定なら `--fake-llm` 案内付きで die。
5. **注入点**: **serve / `start_shard_workers` へ `MODE_ENV` + `LLM_KEY_ENV`**、専用 verify tinker へ `MODE_ENV` のみ、
   **`artisan_for_shard`（migrate/seed）へは注入なし**（秘密の配布面積を最小化。Codex 詳細 R1/R2 反映）。
6. **実効 env 検証（provision (c)）**: `fake_llm` / `fake_storage` の config 実効値をモード期待値と突合。
7. **preflight 共通化（`prepare_mode_and_preflight`）**: cmd_provision 冒頭 / cmd_provision_all ループ前で同一呼び出し。
8. **keepdb-check / usage / self-test** にモード分岐を反映。

### 波及変更
- TypeScript / DTO / テスト（PHP）: なし。**shell self-test（`cmd_self_test`）に新セクション追加**（下記テスト計画）。

### 主要コード（設計）

**（a）引数解析（`main` 抜粋）**
```bash
LLM_MODE="real"       # real（既定）| fake
STORAGE_MODE="fake"   # fake（既定）| real
local _llm_flag_real=0 _llm_flag_fake=0 _storage_flag_real=0
# ... while ループに追加 ...
    --real-llm)     LLM_MODE="real"; _llm_flag_real=1; shift ;;
    --fake-llm)     LLM_MODE="fake"; _llm_flag_fake=1; shift ;;
    --real-storage) STORAGE_MODE="real"; _storage_flag_real=1; shift ;;
# ループ後: 相互排他 + 適用範囲チェック（--coverage と同じ流儀）
if [[ "${_llm_flag_real}" == 1 && "${_llm_flag_fake}" == 1 ]]; then
    die 2 "--real-llm と --fake-llm は同時指定できません（モードを 1 つ選ぶ）"
fi
# ★ どのモードフラグでも provision 系専用（teardown --real-llm 等も拒否。Codex 詳細 R2 反映）。
if [[ "${_llm_flag_real}" == 1 || "${_llm_flag_fake}" == 1 || "${_storage_flag_real}" == 1 ]]; then
    [[ "${sub}" == "provision" || "${sub}" == "provision-all" ]] \
        || die 2 "--real-llm / --fake-llm / --real-storage は provision または provision-all でのみ使える"
fi
```
> 実装注: 同時指定・適用範囲は **専用フラグ変数**（`_llm_flag_real` / `_llm_flag_fake` / `_storage_flag_real`）で
> 判定する（`LLM_MODE`/`STORAGE_MODE` の上書きだけだと「既定と同値の明示指定」や「非既定モードのみ検査」で
> 取りこぼすため）。

**（a2）秘密取扱の xtrace ガード（Codex 詳細 R2 Critical 反映）**
```bash
# 本スクリプトは既定 set -euo pipefail（-x 無し）だが、-x 有効時も秘密を trace に出さない防御。
# BEGIN/END で秘密取扱区間（キー代入・LLM_KEY_ENV 展開のプロセス起動）を挟む。ネスト非対応（単純用途）。
_SECRET_XTRACE_SAVED=0
secret_xtrace_off()     { case $- in *x*) _SECRET_XTRACE_SAVED=1; set +x ;; *) _SECRET_XTRACE_SAVED=0 ;; esac; }
secret_xtrace_restore() { [[ "${_SECRET_XTRACE_SAVED}" == 1 ]] && set -x; return 0; }
```

**（b）親 dotenv reader（xtrace 無効区間・完全一致・堅牢クォート処理。Codex 詳細 R1 Critical 反映）**
```bash
MAIN_ENV_FILE  # sandbox: "${BUGHUNT_SANDBOX}/.env" / 通常: ".env"

# 親 .env から 1 キーを読む。値はログ・stderr に出さない（xtrace 局所退避）。
# キーは awk 完全一致（正規表現メタ文字の事故を防ぐ）。export 前置・単/ダブルクォート・
# 非クォート値の後置コメントを正しく処理する（実キー誤読で real-llm 判定を誤らせないため）。
main_env_get() {
    { set +x; } 2>/dev/null
    [[ -f "${MAIN_ENV_FILE}" ]] || { printf ''; return 0; }
    local v
    v="$(awk -v k="$1" '
        { s=$0; sub(/^[[:space:]]*export[[:space:]]+/, "", s);
          eq=index(s, "=");
          if (eq>0 && substr(s,1,eq-1)==k) { print substr(s, eq+1); exit } }
    ' "${MAIN_ENV_FILE}")"
    if   [[ "${v}" == \"*\" ]]; then v="${v#\"}"; v="${v%\"}";      # "..." を剥がす
    elif [[ "${v}" == \'*\' ]]; then v="${v#\'}"; v="${v%\'}";      # '...' を剥がす
    else v="${v%%[[:space:]]#*}"; fi                                # 非クォート時のみ後置 # コメント除去
    v="${v#"${v%%[![:space:]]*}"}"; v="${v%"${v##*[![:space:]]}"}"  # 前後空白除去
    printf '%s' "${v}"
}
```
> **Laravel `env()` の bool 正規化（実装注、Codex R1 Suggestion 反映）**: `env('TESTING_FAKE_LLM', false)` は
> 文字列 `"true"/"false"` を PHP の `true/false` へ正規化する（Laravel の `Env::getOption`）。よって script が
> 注入する literal `TESTING_FAKE_LLM=false` は `config('testing.fake_llm') === false` になる。加えて phpdotenv は
> immutable モードで既存 env を上書きしないため、**env -i 注入値が `.env.bughunt.local` の同名値より優先**され、
> script 注入が実行時の正本になる。`(bool) env(...)` は fake_externals と一貫でそのまま採用（filter_var 化は不要）。

**（c）モード env 構築（フラグと実キーを分離 = 最小権限。Codex 詳細 R1 Warning 反映）**
```bash
# MODE_ENV:    フラグのみ（TESTING_FAKE_LLM / TESTING_FAKE_STORAGE）。serve/worker/実効 env 検証に注入可（秘密なし）。
# LLM_KEY_ENV: ANTHROPIC_API_KEY（実キー）。serve/worker のみに注入（実 LLM を呼ぶプロセスに限定）。
#              値はここでのみ扱い、echo / manifest_update / migrate/seed/verify には渡さない。
build_mode_env() {
    secret_xtrace_off                             # キー代入を trace に出さない
    MODE_ENV=(); LLM_KEY_ENV=()
    if [[ "${LLM_MODE}" == "fake" ]]; then
        MODE_ENV+=("TESTING_FAKE_LLM=true")
    else
        MODE_ENV+=("TESTING_FAKE_LLM=false")     # real も明示注入（残留 env による反転防止。Codex 概念 R3）
        local key; key="$(main_env_get ANTHROPIC_API_KEY)"
        LLM_KEY_ENV+=("ANTHROPIC_API_KEY=${key}")  # serve/worker 限定
    fi
    if [[ "${STORAGE_MODE}" == "real" ]]; then
        MODE_ENV+=("TESTING_FAKE_STORAGE=false")
    else
        MODE_ENV+=("TESTING_FAKE_STORAGE=true")   # 既定 fake も明示注入
    fi
    secret_xtrace_restore
}
```

**（d）実キー fail-fast + 共通 preflight（キーは build_mode_env の単一読取。assert は配列を検査。Codex R3 Critical 反映）**
```bash
# グローバル初期化（set -u 安全: assert が build_mode_env 前に呼ばれても ${#LLM_KEY_ENV[@]} が壊れない）
MODE_ENV=(); LLM_KEY_ENV=()

# real-llm ∧ キー空/未設定なら die。キーの読取は build_mode_env の 1 箇所のみ（ここでは再読しない）。
# 既に構築済みの LLM_KEY_ENV を検査する（単一正本）。値を trace に出さないよう xtrace 退避で囲む。
assert_llm_key_present() {
    [[ "${LLM_MODE}" == "real" ]] || return 0
    secret_xtrace_off
    if [[ "${#LLM_KEY_ENV[@]}" -ne 1 || "${LLM_KEY_ENV[0]}" == "ANTHROPIC_API_KEY=" ]]; then
        secret_xtrace_restore
        die 1 "real-llm（既定）だが ${MAIN_ENV_FILE} に ANTHROPIC_API_KEY が無い/空です。\
実キーで探索するか、--fake-llm で canned 応答に切り替えてください（fake-llm は再現/切り分け用）。（キー値はログに出しません）"
    fi
    secret_xtrace_restore
}

# cmd_provision 冒頭と cmd_provision_all のループ前で共通に呼ぶ（分岐差を作らない）。
# build_mode_env（キー読取）→ assert_llm_key_present（配列検査）の順で LLM_KEY_ENV を単一正本にする。
prepare_mode_and_preflight() {
    build_mode_env
    assert_llm_key_present
}
```

**（e）注入点の展開（最小権限: serve/worker はフラグ+キー、verify はフラグのみ）**
```bash
# serve 起動 / start_shard_workers の env -i 行末尾付近に（フラグ + 実キー）。
# LLM_KEY_ENV を展開するプロセス起動は xtrace ガードで挟む（-x 有効時も値を trace に出さない）:
    secret_xtrace_off
    env -i PATH="${PATH}" HOME="${HOME}" \
        DB_CONNECTION=pgsql DB_HOST="..." DB_PORT="..." \
        DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="..." APP_URL="${url}" \
        ${coverage_env[@]+"${coverage_env[@]}"} \
        ${MODE_ENV[@]+"${MODE_ENV[@]}"} ${LLM_KEY_ENV[@]+"${LLM_KEY_ENV[@]}"} \
        nohup php artisan serve --env=bughunt.local --port="${port}" --no-reload > ... &
    serve_pid=$!
    secret_xtrace_restore
    # start_shard_workers 内の queue:listen 起動 env -i も同様に secret_xtrace_off/restore で挟む。

# 実効 env 検証 tinker（provision (c)）は MODE_ENV のみ（実キー不要。config 写像の検証が目的。xtrace ガード不要）:
    ${MODE_ENV[@]+"${MODE_ENV[@]}"} \
```
- `migrate/seed`（`artisan_for_shard`）には **MODE_ENV も LLM_KEY_ENV も注入しない**（LLM 非依存 = 不要、
  秘密の配布面積を最小化）。実効 env 検証 tinker のみ `MODE_ENV`（フラグ）を共有し、serve/worker と同一フラグで
  config が解決されることを fail-fast で担保する。実キー（`LLM_KEY_ENV`）は **serve/worker の 2 経路のみ**に存在する。
- **キーの読取は `build_mode_env` の 1 箇所のみ**（`main_env_get` 呼出）。`assert_llm_key_present` は再読せず
  構築済み `LLM_KEY_ENV` を検査する（単一正本。Codex 詳細 R3 反映）。
- **秘密取扱の xtrace ガード区間は 4 箇所**: (1) `build_mode_env` 本体（唯一のキー読取）、
  (2) `assert_llm_key_present`（配列検査）、(3) serve 起動、(4) worker 起動（`start_shard_workers` の env -i ループ）。
  これで -x 有効実行でもキー値・キーを含む代入/起動が trace に出ない。

**（f）実効 env 検証の拡張（provision (c)）**
```php
// tinker --execute の echo に追加
"fake_llm" => config("testing.fake_llm"),
"fake_storage" => config("testing.fake_storage"),
```
```python
# python 突合に追加（mode から期待値を導出）
expected["fake_llm"]     = (LLM_MODE == "fake")
expected["fake_storage"] = (STORAGE_MODE == "fake")
# filesystem は fake_storage（既定）時のみ local を必須化。real-storage は inert のため緩める。
if STORAGE_MODE == "fake":
    expected["filesystem"] = "local"
else:
    e.pop("filesystem", None)  # real-storage 骨子: filesystem 期待を課さない
```
- 実効 env 検証 tinker は **既存 `artisan_for_shard` を使わず、serve と同型の専用 env -i ブロック**で実行し
  `${MODE_ENV[@]+...}`（フラグのみ）を展開する（`artisan_for_shard` は migrate/seed と共用のため MODE_ENV を
  載せない方針）。これで serve/worker と同一フラグで config が解決されることを fail-fast で検証できる
  （config key / env 名の typo を provision 段階で検出）。実キーは verify に載せない。

**（g）provision / provision-all の preflight**
```bash
# cmd_provision 冒頭（env 検証の直後、createdb の前）と cmd_provision_all のループ前で共通に:
prepare_mode_and_preflight   # build_mode_env → assert_llm_key_present（順序統一）
```

**（h）keepdb-check / usage / self-test**
- `keepdb-check`: `--keep-db` reuse は provision をスキップするため **走行中の mode は provision 時に確定した
  ものを保持する**（mode 変更は再 provision が必要）。keepdb-check にはモードフラグを受け付けず、その旨を
  usage/エラー文言に明記（「mode を変えるには --keep-db を外して再 provision」）。
- `usage`: モードフラグ表と「`--real-storage` は未実装トグル（inert）」を明記（Codex 概念 R2/R3）。

### PHPStan 適合チェック
- 対象外（shell script）。ただし provision (c) の tinker 内 PHP 断片は既存同様に構文維持。

### テスト計画（`cmd_self_test` に新セクション `[z]` を追加、実資源に触れない）
- [x] `[z1]` `build_mode_env`: `LLM_MODE=real` → `MODE_ENV` に `TESTING_FAKE_LLM=false` と
      `TESTING_FAKE_STORAGE=true`、`LLM_KEY_ENV` に `ANTHROPIC_API_KEY=<値>` を含む。`LLM_MODE=fake` →
      `MODE_ENV` に `TESTING_FAKE_LLM=true`、`LLM_KEY_ENV` は空。`STORAGE_MODE=real` → `TESTING_FAKE_STORAGE=false`。
      **`MODE_ENV` には実キーが決して含まれない**（フラグ/キー分離の回帰）。
- [x] `[z1b]` `main_env_get`: `export KEY=v` / `KEY="v"` / `KEY='v'` / `KEY=v  # comment` を正しく取得し、
      非対象キー（部分一致・メタ文字キー）を誤取得しない（完全一致）。
- [x] `[z2]` `assert_llm_key_present`: sandbox `MAIN_ENV_FILE` にキー無し ∧ `LLM_MODE=real` → die(rc=1)。
      キー有り → rc=0。`LLM_MODE=fake` → キー無しでも rc=0。
- [x] `[z3]` 秘密漏洩防止（stdout/stderr 別捕捉 + xtrace 有効実行。Codex 詳細 R2/R3 反映）: real-llm で
      `MAIN_ENV_FILE` にダミーキー（例 `sk-ant-SELFTEST-DUMMY`）を置き、(i) 通常実行と (ii) `set -x` 有効実行の
      両方で **`prepare_mode_and_preflight`（= build_mode_env + assert_llm_key_present）の成功経路**を走らせ、
      **stdout と stderr のどちらにもダミーキー値が現れない**ことを grep で確認（値は `LLM_KEY_ENV` 配列内のみ。
      キー名 `ANTHROPIC_API_KEY` の露出は可）。`MODE_ENV` にキー値が含まれないことも確認。
- [x] `[z4]` 引数解析: `--real-llm --fake-llm` 同時 → exit 2。`teardown --real-llm` / `self-test --real-storage` /
      `--fake-llm` を非 provision subcommand に付与 → exit 2。`provision --fake-llm` / `provision --real-storage`
      （dryrun）→ 0。
- [x] `[z5]` 実効 env 検証の期待値導出（python 断片）を real/fake/real-storage で単体評価し diff が空になること
      （tinker 実行はせず、期待値ロジックのみ。実資源不要）。
- [x] 既存セクション `[a]〜[y]` を回帰させない（特に `[x]` coverage フラグ・`[y]` worker 配線・env -i 隔離）。

### リスク
- **中〜高（最重要）**: `env -i` 行への `MODE_ENV` 展開が既存の `${coverage_env[@]+...}` と同居する。展開順・
  クオートを誤ると serve 起動が壊れる。→ 既存 `coverage_env` と同一の `${arr[@]+"${arr[@]}"}` パターンを踏襲し、
  self-test の serve 非起動 dryrun + `[y]`/`[x]` 構造検査で回帰を検出。
- **秘密漏洩**: 実キーを `manifest_update` / `echo` に渡さない。self-test `[z3]` で機械的に固定。
- **serve の env passthrough**: `--no-reload` 前提（既存コメント L842）で env -i の変数が php -S 子に届く。
  `ANTHROPIC_API_KEY` も同経路で届く（DB_* と同じ）。worker（queue:listen）は通常継承で届く。

---

## 施策 5: `.env.bughunt.local.example` のフラグ説明追記

### 変更箇所
- ファイル: `.env.bughunt.local.example`（`TESTING_FAKE_EXTERNALS` 説明ブロック周辺）

### 波及変更
- なし（テンプレート env の例示ファイル）。

### 変更後（追記内容の要点）
- `TESTING_FAKE_EXTERNALS` の説明を「**Stripe 課金 fake の capability flag**（LLM は別フラグ `fake_llm` に分離）」へ更新。
- 追記（**冒頭に「実効値は script 注入が正本。ここは説明用」を前置**して誤設定を防ぐ。Codex 詳細 R1 Suggestion）:
  ```dotenv
  # ▼ 以下 TESTING_FAKE_* の実効値は scripts/bug-hunt-shard.sh が provision 時に env 注入する値が正本。
  #   このファイルの記載は説明用で、実行時既定は script 注入が保証する（コピー忘れでも既定は崩れない）。
  # LLM (Prism/Anthropic) の fake トグル。bug-hunt 既定は real-llm（実 API 接続）。
  # 実効値は scripts/bug-hunt-shard.sh が provision 時に env へ明示注入する（この dotenv より優先）:
  #   real-llm（既定/--real-llm） → TESTING_FAKE_LLM=false + 親 .env の ANTHROPIC_API_KEY を serve/worker に注入
  #   --fake-llm                  → TESTING_FAKE_LLM=true（T035 の canned 応答。再現/切り分け用）
  # real-llm では親リポジトリ .env の ANTHROPIC_API_KEY（実キー）が必須。未設定なら provision が fail-fast。
  # TESTING_FAKE_LLM=false   # ← 実効値は script 注入が正本（ここは説明用）
  #
  # S3 ストレージ fake トグル。bug-hunt 既定は fake（filesystems.default=local）。
  #   --real-storage → TESTING_FAKE_STORAGE=false（※実 S3 接続の実配線は未実装 = inert トグル）
  # TESTING_FAKE_STORAGE=true  # ← 実効値は script 注入が正本（ここは説明用）
  ```
- `ANTHROPIC_API_KEY` 自体はこのファイルに実値を書かない（親 `.env` 由来を serve に注入する旨を明記）。

### テスト計画
- example ファイルのため自動テストなし。施策 4 の self-test がフラグ注入の実挙動を担保する。

### リスク
- 低（ドキュメント）。実行時既定は script 注入が正本のため、コピー忘れでも既定は崩れない。

---

## 施策 6: `app-bug-hunt/SKILL.md` を real-llm 前提へ

### 変更箇所
- ファイル: `.claude/skills/app-bug-hunt/SKILL.md`
  - front-matter `argument-hint` と「引数」節: 既定を `--all --coverage --parallel --deviate --real-llm 相当`へ。
  - **禁止事項 4 を改訂**（下記）。
  - 「引数」表に `--real-llm`（既定）/ `--fake-llm` / `--real-storage`（未実装トグル）の行を追加。
  - 「環境の前提知識」表: 外部サービス行を「**LLM=real（実 Anthropic）、その他は fake / 外部通信なし**」へ、
    実キー必須・未設定は fail-fast、mode 表、real-llm × parallel の運用注記を追記。

### 禁止事項 4 の改訂後
```
4. **許可する実外部接続は LLM プロバイダ（Anthropic）API ドメインのみ（real-llm 既定）。** 決済 / Captcha /
   SSO / mail / S3 等その他の外部は fake / 外部通信なし。**LLM プロバイダ API ドメイン以外の外部ドメインへの
   実リクエストは従来どおり全面禁止**で、検知したら即中断して報告する（egress ガードの許可先に LLM API ドメインを
   加えるだけで、他は不変）。`--fake-llm` 時は LLM も canned（実接続なし）。real-llm は実キー必須で、未設定なら
   provision が fail-fast する（--fake-llm を案内）。
```
> egress 誤解防止（Codex 詳細 R1 Warning 反映）: 「LLM のみ許可」= 許可先ドメインを LLM API に限定する意で、
> SSRF/egress ガードの他ドメイン全面禁止は不変であることを文言で明示する。

### 波及変更
- なし（スキル文書）。禁止事項 5（誤検知）の番号繰り下げは発生しない（4 の改訂のみ）。

### テスト計画
- 文書のため自動テストなし。`scripts/bug-hunt-inventory-check.sh` の対象外（screens/operations 不変）。

### リスク
- 低。走行プロトコルの意味変更（外部遮断の対象から LLM を除外）を正確に記述する必要がある。

---

## 施策 7: `stories/S3-core-journey.md` を実 AI 走行前提へ

### 変更箇所
- ファイル: `.claude/skills/app-bug-hunt/stories/S3-core-journey.md`

### 変更方針
- 現状カードは canned 固定文言前提の記述を持たない（手順 4-6 は status 遷移ベース）。実 AI 前提として以下を追記:
  - 手順 5（解析ポーリング）: 「**実 AI 応答のため生成内容・所要時間は run ごとに変動する**。固定文言を期待しない。
    待機中の無反応・タイムアウト UX（H3）、失敗時の draft 復帰と理由提示（H4）を重点観察」。
  - 手順 6（シナリオ編集）: 「生成 Cut ツリーの**内容は非決定的**。件数 0 や不整合（H10）に注意」。
  - 逸脱: 「解析失敗（実 AI/レート制限由来）を UX バグと環境ハザードで区別して記録（Anthropic 429/5xx）」。
    環境ハザード記録は **比較可能性のため `HTTP status / 再試行回数 / 待機秒 / 発生 route` の 1 行フォーマット**で残す
    （Codex 詳細 R1 Suggestion）。

### 波及変更
- なし。

### テスト計画
- 文書のため自動テストなし。

### リスク
- 低。

---

## 施策 8: 既存テストの波及更新 + 新規テスト

### 8-1. `tests/Feature/Providers/FakeExternalsServiceProviderTest.php`（波及・必須）

- **boot 系テスト（L63-133）を `fake_externals` → `fake_llm` キーへ置換**。
  - `boot: env=bughunt.local ∧ fake_llm=true で Prompt fake 有効 + canned 応答`（`config(['testing.fake_llm'=>true])`）。
  - `boot: env=testing ∧ fake_llm=true では touch しない` / `boot: env=local ∧ fake_llm=true では touch しない`。
  - `boot: fake_llm=false では bughunt.local でも install しない（real 経路）`。
  - **追加**: `boot: fake_externals=true でも fake_llm=false なら install しない`（分離の回帰）=「Stripe fake が
    立っていても LLM は real」を固定。
- **Stripe register 系テスト（L32-61）は変更しない**（`fake_externals` 依存の不変を担保）。
- `afterEach` の `Prompt::stopFaking()` は維持（static リーク防止）。

### 8-2. `tests/Feature/Support/ProductionEnvGuardTest.php`（波及・必須）

- `beforeEach` baseline に `config(['testing.fake_llm' => false])` と `config(['testing.fake_storage' => false])` を追加。
- 新規: `test('TESTING_FAKE_LLM が true なら violation')` / `test('TESTING_FAKE_STORAGE が true なら violation')`
  （各 `toHaveCount(1)` + メッセージ contain）。
- 既存 `全項目埋め → violations 空` / `production:preflight` 系が新 guard で落ちないことを確認。

### 8-3. 新規 `tests/Feature/Config/TestingFlagsDefaultTest.php`

- テスト名に **「env 未設定前提（config 既定）」** を明記し、意図（phpunit.xml が両 env を設定しない = config
  既定を固定する回帰テスト）をコメントで明示（Codex 詳細 R1 Warning 反映）。
- `config('testing.fake_llm')` が testing 環境で `false`（かつ `toBeBool()`）。
- `config('testing.fake_storage')` が testing 環境で `false`（かつ `toBeBool()`）。
- `config('testing.fake_externals')` の既定 false 不変も同居確認（回帰防止）。
- 将来 phpunit.xml に TESTING_FAKE_* が追加された場合は本テストが検知して落ちる（= 既定変更の可視化）。

### 8-4. shell self-test（施策 4 のテスト計画 `[z1]〜[z5]`）

- `scripts/bug-hunt-shard.sh self-test` に新セクションを追加（実資源に触れない）。CI/ローカルで
  `bash scripts/bug-hunt-shard.sh self-test` として実行。

### PHPStan 適合チェック（テスト全体）
- [x] 個別 `DatabaseTransactions` を使わない（`RefreshDatabase` グローバル適用）。
- [x] `Model::create()` 手組みなし（本 item はモデル不使用。Factory 追加不要）。
- [x] config 値 assert は bool/`toBeFalse()` で型明示。

### リスク
- 既存テストの意味変更（boot 条件の键変更）を漏らすと CI が落ちる。→ 施策 2 と 8-1 を同一 PR で必ず同時変更。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更が `config/testing.php` の判定軸・provider boot 条件・guard・bughunt スクリプトという**基盤の意味変更**に跨り、施策 2↔8-1（boot 条件とそのテスト）が不可分。半端な incremental 統合は「fake_externals で LLM fake」と「fake_llm で LLM fake」が混在する中間状態を生むため、1 つの worktree で一括実装・一括テストするのが安全。 |
| 競合リスク | 他 bughunt item（worker/coverage 等）と `scripts/bug-hunt-shard.sh` を同時編集すると衝突。self-test セクションの追加位置（`[z]`）を末尾側に取り、既存セクション行を動かさない。`FakeExternalsServiceProviderTest` は他 item も触りやすいので注意。 |

## スコープ外（再掲）

- 実 S3 ストレージの実接続実装（region/bucket 設定・presigned の実 S3 疎通）。本 item は `fake_storage`
  トグル骨子（config flag + script flag + doc）まで。`--real-storage` は現状 inert。
- ffmpeg 不在（Q1 残件、レンダー実行環境の整備）。
- コード実装・TODO 登録（本フローは設計のみ）。
```

### 実装差分 (git diff HEAD)
```diff
diff --git a/.claude/skills/app-bug-hunt/SKILL.md b/.claude/skills/app-bug-hunt/SKILL.md
index 8d97c9b..e312fd6 100644
--- a/.claude/skills/app-bug-hunt/SKILL.md
+++ b/.claude/skills/app-bug-hunt/SKILL.md
@@ -2,7 +2,7 @@
 name: app-bug-hunt
 description: このアプリの LLM 探索的バグハント。専用 bughunt 環境 (直列 :8010 / 並列 shard :8011..8018) に対し隔離ブラウザ (Bash 駆動の @playwright/cli) でユーザーストーリーを実走し、UX破綻・詰み・認可漏れ (IDOR) を発見してレポートする (修正はしない)。テンプレート同梱のオプトイン基盤 (未使用時は完全 no-op)。
 user-invocable: true
-argument-hint: "省略時は --all --coverage --parallel --deviate (既定=全ストーリー並列+コードカバレッジ+逸脱)。絞るなら [S1..S7 ...] [--no-deviate] [--keep-db] 例: /app-bug-hunt, /app-bug-hunt S3"
+argument-hint: "省略時は --all --coverage --parallel --deviate --real-llm 相当 (既定=全ストーリー並列+コードカバレッジ+逸脱+実LLM接続)。絞るなら [S1..S7 ...] [--no-deviate] [--keep-db] [--fake-llm] 例: /app-bug-hunt, /app-bug-hunt S3"
 ---
 
 # 探索的バグハント (bug-hunt)
@@ -28,12 +28,12 @@ ## 使命
 
 ## 引数
 
-> **既定 (引数なし) = `--all --coverage --parallel --deviate`**
-> (= 全ストーリーを並列 shard + コード到達カバレッジ計装 + 逸脱込みで走行)。狭めたいときだけ下表で絞る。
+> **既定 (引数なし) = `--all --coverage --parallel --deviate --real-llm`**
+> (= 全ストーリーを並列 shard + コード到達カバレッジ計装 + 逸脱込み + 実 LLM 接続で走行)。狭めたいときだけ下表で絞る。
 
 | 引数 | 必須 | 説明 |
 |------|------|------|
-| (引数なし) | — | 既定で `--all --coverage --parallel --deviate` 相当を実行 (worktree 走行) |
+| (引数なし) | — | 既定で `--all --coverage --parallel --deviate --real-llm` 相当を実行 (worktree 走行) |
 | S1..S7 | No | 実行するストーリーカード (stories/ 配下、複数指定可)。明示するとその指定分だけに絞る (直列走行) |
 | --all | No | 全ストーリーを実行 (S7 は S3 の状態を前提にするため S3 の後)。既定に含まれる |
 | --coverage | No | serve を pcov 付き php で起動しコード到達カバレッジ (C3) を収集する。既定に含まれる。pcov 未導入環境では middleware が no-op で安全に続行 |
@@ -41,7 +41,10 @@ ## 引数
 | --parallel[=N] | No | 並列シャード実行 (N=2/4/6/8、cap=8、既定 4)。既定に含まれる。親はインベントリ確認 → `provision-all` → `bughunt-shard` subagent を Workflow で N 体 fan-out → `verify-run` → 統合レポート |
 | --deviate | No | 各ストーリー末尾の「逸脱アイデア」も実行する。既定に含まれる |
 | --no-deviate | No | 逸脱探索を省く |
-| --keep-db | No | Phase 0 の provision (migrate:fresh) をスキップし現状の `bug_hunt` を使う (連続実行の 2 回目以降用) |
+| --real-llm | No | LLM を実 Anthropic API に接続して走行する (既定)。親リポジトリ `.env` の `ANTHROPIC_API_KEY` が必須で、未設定なら provision が fail-fast する。生成内容・所要時間は run ごとに非決定的 |
+| --fake-llm | No | LLM を canned 応答 (T035) に切り替える (実 API 未接続)。再現・切り分け用。`--real-llm` とは同時指定不可 |
+| --real-storage | No | `TESTING_FAKE_STORAGE=false` を注入する。※実 S3 接続の実配線は未実装 = **inert トグル** (現状は挙動不変) |
+| --keep-db | No | Phase 0 の provision (migrate:fresh) をスキップし現状の `bug_hunt` を使う (連続実行の 2 回目以降用)。mode は provision 時に確定したものを保持 (mode を変えるには --keep-db を外して再 provision) |
 | --shard {i} | No | (内部用) シャード i として走行。--parallel の子として起動される |
 | --run-id {ts} | No | (内部用) 親が採番した run-id。--shard とセット |
 
@@ -66,9 +69,11 @@ ## 禁止事項 — 絶対遵守
      `DB_*`/`PG*` を遮断してから bughunt 値を注入することでこれを無力化する。生 tinker/artisan はこの遮断を
      受けられず **dev DB に書き込む**。
    - **あらゆる DB 書き込みの前に接続先 DB を検証する** (`db-check` または getDatabaseName)。検証なしの書き込み禁止。
-4. **実外部サービスに触れない。** `TESTING_FAKE_EXTERNALS=true` で LLM・決済・Captcha・SSO 等が fake される前提で
-   走行する (fake 基盤の導入状況はアプリ依存。未導入なら該当機能は fake されない点に注意)。network requests に
-   外部ドメインへの実リクエストを検知したら即中断して報告する。
+4. **許可する実外部接続は LLM プロバイダ (Anthropic) API ドメインのみ (real-llm 既定)。** 決済 / Captcha /
+   SSO / mail / S3 等その他の外部は fake / 外部通信なし。**LLM プロバイダ API ドメイン以外の外部ドメインへの
+   実リクエストは従来どおり全面禁止**で、検知したら即中断して報告する (egress ガードの許可先に LLM API ドメインを
+   加えるだけで、他は不変。SSRF/egress ガードの他ドメイン全面禁止は変わらない)。`--fake-llm` 時は LLM も canned
+   (実接続なし)。real-llm は実キー必須で、未設定なら provision が fail-fast する (`--fake-llm` を案内)。
 5. **誤検知をバグとして断定しない。** 期待仕様が設計文書 (devnotes/docs) から確認できないものは
    「要確認」に分類し、severity を付けない。
 
@@ -181,7 +186,9 @@ ### 環境の前提知識
 | 項目 | 値 |
 |---|---|
 | 対象 URL | 直列 = `http://127.0.0.1:8010` (APP_ENV=bughunt.local, DB=bug_hunt) |
-| 外部サービス | `TESTING_FAKE_EXTERNALS=true` で fake (fake 基盤の適用範囲はアプリ依存)。外部ドメインへの実 request 検知で即中断 |
+| 外部サービス | **LLM=real (実 Anthropic API 接続。既定 real-llm)**、その他 (決済/Captcha/SSO/mail/S3) は fake / 外部通信なし。許可先は LLM API ドメインのみで、それ以外の外部ドメインへの実 request 検知で即中断 |
+| LLM モード | 既定 real-llm。親 `.env` の `ANTHROPIC_API_KEY` が必須 (未設定なら provision が fail-fast → `--fake-llm` を案内)。`--fake-llm` で canned 応答 (再現/切り分け用)。生成内容・所要時間は run ごとに非決定的。**real-llm × --parallel は shard 数ぶん実 API を並行呼びするためレート/コストに注意** |
+| ストレージ | 既定 fake (`filesystems.default=local`)。`--real-storage` は inert トグル (実 S3 配線は未実装) |
 | メール | MAIL_MAILER=log。署名 URL は `tmp/bug-hunt/shard-0-cmd.sh mail-urls [--count K]` で取得 |
 | テストアカウント | ManualTestSeeder が投入 (`{role}-{plan}@example.com` / `multi-org@example.com` / `unverified@example.com`、全員 `password123`)。管理画面 admin は `admin@example.com` / `password12345` (AdminUserSeeder) |
 | 管理画面 MFA | `.env.bughunt.local` の `ADMIN_MFA_REQUIRED=false` で無効化 (email+password でログイン可) |
@@ -298,7 +305,7 @@ ## Phase 3: 逸脱探索 (--deviate 時のみ)
 
 各カード末尾の「逸脱アイデア」を実行する。加えて任意の画面で汎用逸脱を 1〜2 個試す:
 ブラウザバック直後の再操作 / リロード連打 / URL パラメータの隣接 ID 書き換え (IDOR=H9 探索) / 2 タブ同時操作。
-**逸脱中も禁止事項 4 (実外部サービス) は維持。**
+**逸脱中も禁止事項 4 (実外部接続は LLM API ドメインのみ許可、他ドメインは全面禁止) は維持。**
 
 ## Phase 4: レポート + クロージング
 
diff --git a/.claude/skills/app-bug-hunt/stories/S3-core-journey.md b/.claude/skills/app-bug-hunt/stories/S3-core-journey.md
index 0af24f5..3e7e2b4 100644
--- a/.claude/skills/app-bug-hunt/stories/S3-core-journey.md
+++ b/.claude/skills/app-bug-hunt/stories/S3-core-journey.md
@@ -8,8 +8,8 @@ ## 手順
 2. `projects.manuals.create` → タイトル・カテゴリを入力し手順書(PDF/Excel)をアップロード → `projects.manuals.store` → 作成され `projects.manuals.show` へ遷移(status=draft)。
 3. 手順書追加 `projects.manuals.source-documents.store` → アップロード完了が明示される。
 4. `projects.manuals.analyze`(AI 解析トリガー) → チケット残高が事前チェックされ、不足なら押下時にエラー(disabled でなく)。実行で status=analyzing。
-5. `projects.manuals.jobs.show` をポーリング → 進捗(extract/decompose/generate)が表示され、完了で status=ready、失敗なら draft に戻り理由が見える。
-6. `projects.manuals.edit`(シナリオ編集) → 生成された Cut(手順=step/急所=point のツリー)が表示。本文・字幕を編集し保存 `projects.manuals.scenario.update` → 楽観ロックで version が進む。別タブで先に保存すると 409 で差分再取得を促される。
+5. `projects.manuals.jobs.show` をポーリング → 進捗(extract/decompose/generate)が表示され、完了で status=ready、失敗なら draft に戻り理由が見える。**real-llm 走行のため生成内容・所要時間は run ごとに変動する。固定文言を期待しない**。待機中の無反応・タイムアウト UX(H3)、失敗時の draft 復帰と理由提示(H4)を重点観察。
+6. `projects.manuals.edit`(シナリオ編集) → 生成された Cut(手順=step/急所=point のツリー)が表示。**生成 Cut ツリーの内容は非決定的**。件数 0 や不整合(H10)に注意。本文・字幕を編集し保存 `projects.manuals.scenario.update` → 楽観ロックで version が進む。別タブで先に保存すると 409 で差分再取得を促される。
 7. 撮影(PWA面): `capture.home` → `capture.manuals.index` → `capture.manuals.show` でシナリオを見ながら、各 Cut にテイクをアップロード(`capture.takes.upload-url` → `capture.takes.store`)。カメラ不可環境ではファイル選択にフォールバック。テイクの並べ替え/コメント(`capture.takes.update`)、採用(`capture.takes.adopt`)、一括同期(`capture.manuals.sync`)。
 8. `projects.manuals.preview`(チケット非消費)で確認 → `projects.manuals.render`(video_render チケット消費) → status=rendering → `projects.manuals.render-jobs.show` ポーリング → 完了で published。
 9. `projects.manuals.render-jobs.playback` / `projects.manuals.download` で完成 mp4 を再生・DL。
@@ -19,6 +19,7 @@ ## このストーリーで消化する screens / operations
 - operations: projects.manuals.store, projects.manuals.update, projects.manuals.destroy, projects.manuals.source-documents.store, projects.manuals.analyze, projects.manuals.scenario.update, projects.manuals.preview, projects.manuals.render, capture.takes.upload-url, capture.takes.store, capture.takes.update, capture.takes.destroy, capture.takes.adopt, capture.takes.downloaded, capture.manuals.sync
 
 ## 逸脱アイデア (--deviate 時)
+- 解析失敗(実 AI/レート制限由来)を UX バグと環境ハザードで区別して記録する(Anthropic 429/5xx)。環境ハザードは比較可能性のため `HTTP status / 再試行回数 / 待機秒 / 発生 route` の 1 行フォーマットで残す。
 - analyze/render を二重送信 → 同時 in-flight が 1 本に抑えられるか(冪等)。失敗後のみ再実行できるか。
 - 解析中/レンダ中に scenario 保存 → 禁止(409/403)されるか。published 後に編集して published→ready に戻るか。
 - 残高 0 で analyze/render → 押下時エラーで詰まないか(disabled で無反応にならないか)。
diff --git a/.env.bughunt.local.example b/.env.bughunt.local.example
index 074eae2..0f2be50 100644
--- a/.env.bughunt.local.example
+++ b/.env.bughunt.local.example
@@ -54,13 +54,29 @@ CACHE_STORE=database
 # provision が起動する queue:listen worker が処理する (bug-hunt-shard.sh 参照)
 QUEUE_CONNECTION=sync
 
-# 外部サービス (LLM/Stripe/Captcha/SSO 等) を fake 化する capability flag。
+# ▼ 以下 TESTING_FAKE_* の実効値は scripts/bug-hunt-shard.sh が provision 時に env 注入する値が正本。
+#   このファイルの記載は説明用で、実行時既定は script 注入が保証する (コピー忘れでも既定は崩れない)。
+#
+# Stripe 課金 fake の capability flag (LLM は別フラグ fake_llm に分離)。
 # config('testing.fake_externals') を通して fake セットを有効化する
 # (Stripe: FakeExternalsServiceProvider が checkout/portal gateway を fake に bind。
 #  fake は決済せず中立帰還する。課金状態の正本は BughuntBillingSeeder)。
 # 運用注意: 本キーは bughunt 環境以外で有効化しない (本番は常時 false = config 既定。
 #  production では ProductionEnvGuard が fail-fast するが、flag 自体を触らないのが原則)。
 TESTING_FAKE_EXTERNALS=true
+
+# LLM (Prism/Anthropic) の fake トグル。bug-hunt 既定は real-llm (実 API 接続)。
+# 実効値は scripts/bug-hunt-shard.sh が provision 時に env へ明示注入する (この dotenv より優先):
+#   real-llm (既定/--real-llm) → TESTING_FAKE_LLM=false + 親 .env の ANTHROPIC_API_KEY を serve/worker に注入
+#   --fake-llm                 → TESTING_FAKE_LLM=true (T035 の canned 応答。再現/切り分け用)
+# real-llm では親リポジトリ .env の ANTHROPIC_API_KEY (実キー) が必須。未設定なら provision が fail-fast。
+# ANTHROPIC_API_KEY 自体はこのファイルに実値を書かない (親 .env 由来を serve に注入する)。
+# TESTING_FAKE_LLM=false   # ← 実効値は script 注入が正本 (ここは説明用)
+
+# S3 ストレージ fake トグル。bug-hunt 既定は fake (filesystems.default=local)。
+#   --real-storage → TESTING_FAKE_STORAGE=false (※実 S3 接続の実配線は未実装 = inert トグル)
+# TESTING_FAKE_STORAGE=true  # ← 実効値は script 注入が正本 (ここは説明用)
+
 MAIL_MAILER=log
 
 # 管理画面 (Filament admin) を bug-hunt で探索可能にする。既定 (true) だと admin ログイン後に
diff --git a/app/Providers/FakeExternalsServiceProvider.php b/app/Providers/FakeExternalsServiceProvider.php
index a6bf15a..5f5aa2e 100644
--- a/app/Providers/FakeExternalsServiceProvider.php
+++ b/app/Providers/FakeExternalsServiceProvider.php
@@ -13,7 +13,7 @@
 use Illuminate\Support\ServiceProvider;
 
 /**
- * 外部サービス fake の配線 (config('testing.fake_externals') が capability flag)。
+ * 外部サービス fake の配線 (系統別に capability flag を分離)。
  *
  * bootstrap/providers.php で AppServiceProvider より後に登録する (後勝ち rebind)。
  * fail-secure 二軸:
@@ -22,10 +22,13 @@
  *    未知環境で flag が誤設定されても fake しない (warning ログで検出可能にする)。
  *    production は加えて ProductionEnvGuard が flag=true を deploy 時 fail-fast で拒否する。
  *
- * fake 対象は 2 系統で allowlist が異なる:
- * - Stripe 課金 gateway: container bind (per-test 隔離が効くため testing 可)。register() で配線。
- * - LLM (Prism): Prompt::$fake は static (プロセスグローバル) のため testing/local を除外。
- *   boot() で bughunt.local のみ配線 (HTTP serve / queue worker / artisan 全 bootstrap で発火)。
+ * fake 対象は 2 系統で capability flag も allowlist も異なる:
+ * - Stripe 課金 gateway: config('testing.fake_externals') が capability flag。
+ *   container bind (per-test 隔離が効くため testing 可)。register() で配線。
+ * - LLM (Prism): config('testing.fake_llm') が capability flag (fake_externals から分離)。
+ *   Prompt::$fake は static (プロセスグローバル) のため testing/local を除外し bughunt.local のみ配線。
+ *   bughunt 既定は real-llm (fake_llm off) で install しない。--fake-llm 時のみ install する。
+ *   LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
  */
 class FakeExternalsServiceProvider extends ServiceProvider
 {
@@ -57,12 +60,16 @@ public function register(): void
 
     public function boot(): void
     {
-        if (config('testing.fake_externals') !== true) {
+        // LLM fake は fake_llm (既定 false = real LLM) で判定する。bughunt 既定は real-llm で、
+        // --fake-llm 指定時のみ TESTING_FAKE_LLM=true が注入され install される。
+        // Stripe fake (register) は従来どおり fake_externals 依存で不変。
+        if (config('testing.fake_llm') !== true) {
             return;
         }
 
         // LLM fake は Prompt::$fake (プロセスグローバル static) を書き換えるため、
-        // per-test で static を占有する testing、実 API 検証を潰す local は除外する。
+        // per-test で static を占有する testing、実 API 検証を潰す local は allowlist から除外する。
+        // LLM fake 許可環境は bughunt.local のみ (定数 LLM_FAKE_ENVIRONMENTS が正本)。
         // (Stripe と違い warning は出さない: testing/local の除外は誤設定ではなく設計上の除外)
         if (! in_array($this->app->environment(), self::LLM_FAKE_ENVIRONMENTS, true)) {
             return;
diff --git a/app/Support/ProductionEnvGuard.php b/app/Support/ProductionEnvGuard.php
index 5382a34..b2d81d0 100644
--- a/app/Support/ProductionEnvGuard.php
+++ b/app/Support/ProductionEnvGuard.php
@@ -18,7 +18,9 @@
  * - APP_DEBUG=false (stack trace / 設定露出防止)
  * - SECURITY_HSTS_ENABLED / SECURITY_CSP_ENABLED=true (セキュリティヘッダ必須)
  * - DEBUG_LOGIN_USER / DEBUG_LOGIN_PASSWORD が空 (local 専用機構の誤投入防止)
- * - TESTING_FAKE_EXTERNALS=false (外部 fake の本番混入防止)
+ * - TESTING_FAKE_EXTERNALS=false (Stripe 外部 fake の本番混入防止)
+ * - TESTING_FAKE_LLM=false (LLM fake の本番混入防止)
+ * - TESTING_FAKE_STORAGE=false (storage fake の本番混入防止)
  * - TrustHosts allowlist (Host header injection 防御の allowlist 非空・書式)
  */
 class ProductionEnvGuard
@@ -87,6 +89,18 @@ public function violations(): array
                 .'(external fakes must never be enabled in production).';
         }
 
+        // LLM fake は production で real LLM を潰すため禁止 (fake_externals と同じ fail-secure)。
+        if (config('testing.fake_llm') === true) {
+            $errors[] = 'TESTING_FAKE_LLM must be false in production '
+                .'(LLM fake must never be enabled in production).';
+        }
+
+        // storage fake は production で実ストレージを潰し得るため禁止。
+        if (config('testing.fake_storage') === true) {
+            $errors[] = 'TESTING_FAKE_STORAGE must be false in production '
+                .'(storage fake must never be enabled in production).';
+        }
+
         // Host header injection 防御の TrustHosts allowlist を起動時検証。
         // 純粋クラス TrustedHostsConfigValidator に委譲し、throw を violation メッセージへ写像する。
         $exact = $this->stringList(config('trusted_hosts.exact_hosts', []));
diff --git a/config/testing.php b/config/testing.php
index 13f5ce4..aea3f0f 100644
--- a/config/testing.php
+++ b/config/testing.php
@@ -9,14 +9,47 @@
     | 外部サービス fake 化の capability flag
     |--------------------------------------------------------------------------
     |
-    | true のとき FakeExternalsServiceProvider が外部サービス (Stripe) の
+    | fake_externals: Stripe 課金 fake の capability flag (既定 false = no-op)。
+    | true のとき FakeExternalsServiceProvider::register() が Stripe checkout/portal
     | gateway を fake 実装に bind する (bughunt / local 検証用)。
     | 有効化は allowlist 環境 (local / testing / bughunt.local) に限定され、
     | production では ProductionEnvGuard が true を deploy 時 fail-fast で拒否する。
     | 既定 false = 本 flag 未設定の環境では完全 no-op。
     |
+    | ※ LLM (Prism) fake はこの flag から分離され fake_llm が capability flag。
+    |
     */
 
     'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),
 
+    /*
+    |--------------------------------------------------------------------------
+    | LLM (Prism) fake 化の capability flag
+    |--------------------------------------------------------------------------
+    |
+    | fake_llm: LLM (Prism) fake を install するか。config 既定 false = real LLM。
+    | bughunt は既定 real-llm (scripts/bug-hunt-shard.sh が TESTING_FAKE_LLM=false を明示注入)。
+    | --fake-llm 指定時のみ true 注入 → FakeExternalsServiceProvider::boot が
+    | CannedPromptFakeRegistrar を install (env allowlist bughunt.local のみ)。
+    | production では ProductionEnvGuard が true を fail-fast で拒否する。
+    |
+    */
+
+    'fake_llm' => (bool) env('TESTING_FAKE_LLM', false),
+
+    /*
+    |--------------------------------------------------------------------------
+    | S3 ストレージ fake 化のトグル (骨子)
+    |--------------------------------------------------------------------------
+    |
+    | fake_storage: S3 ストレージ fake トグル (骨子)。config 既定 false = 本番安全側。
+    | bughunt は既定 fake (scripts/bug-hunt-shard.sh が TESTING_FAKE_STORAGE=true を明示注入)。
+    | --real-storage 指定時のみ false 注入。
+    | ※ 実 S3 接続の実配線は本 item スコープ外 (consumer 未実装 = inert)。
+    | production では ProductionEnvGuard が true を fail-fast で拒否する。
+    |
+    */
+
+    'fake_storage' => (bool) env('TESTING_FAKE_STORAGE', false),
+
 ];
diff --git a/scripts/bug-hunt-shard.sh b/scripts/bug-hunt-shard.sh
index a2a9afc..5a72244 100755
--- a/scripts/bug-hunt-shard.sh
+++ b/scripts/bug-hunt-shard.sh
@@ -29,6 +29,12 @@
 #                                      #             (既定 OFF。pcov 不在なら no-op で続行)。
 #   provision-all [--parallel=N] [--coverage] [--hold-lock]
 #                                      # (fan-out 用) lock を保持し run-id 採番 → shard 1..N を一括 provision。
+#   モードフラグ (provision / provision-all 専用。--keep-db reuse は provision 時に確定した mode を保持):
+#     --real-llm      LLM=real (既定)。親 .env の ANTHROPIC_API_KEY を serve/worker に注入。未設定なら fail-fast。
+#     --fake-llm      LLM=fake。canned 応答 (再現/切り分け用)。実 API 未接続。
+#     --real-storage  storage=real (TESTING_FAKE_STORAGE=false)。※実 S3 配線は未実装 = inert トグル。
+#     既定は real-llm + fake-storage。--real-llm と --fake-llm は同時指定不可。mode を変えるには
+#     --keep-db を外して再 provision する。
 #   reseed    --shard I --run-id TS    # 自 DB のみ migrate:fresh+seed
 #   db-check  --shard I --run-id TS    # DB 名 + User::count() 表示
 #   db-exists --shard I --run-id TS    # pg_database 存在確認 (owner role, read-only)
@@ -66,11 +72,13 @@ if [[ -n "${BUGHUNT_SANDBOX:-}" ]]; then
     TMP_BASE="${BUGHUNT_SANDBOX}/tmp/bug-hunt"
     LOCK_FILE="${BUGHUNT_SANDBOX}/bug-hunt.lock"
     ENV_FILE="${BUGHUNT_SANDBOX}/.env.bughunt.local"
+    MAIN_ENV_FILE="${BUGHUNT_SANDBOX}/.env"     # 親リポジトリ .env (実キー ANTHROPIC_API_KEY 由来)
 else
     RUN_BASE="devnotes"
     TMP_BASE="tmp/bug-hunt"
     LOCK_FILE="${WORKSPACE}/.claude/bug-hunt.lock"
     ENV_FILE=".env.bughunt.local"
+    MAIN_ENV_FILE=".env"                        # 親リポジトリ .env (実キー ANTHROPIC_API_KEY 由来)
 fi
 
 is_dryrun() { [[ -n "${BUGHUNT_SELFTEST_DRYRUN:-}" ]]; }
@@ -196,6 +204,86 @@ env_file_required() {
     echo "${v}"
 }
 
+# --- モード制御 (real-llm 既定 / fake-llm / real-storage) -----------------------
+# bug-hunt は既定 real-llm (実 Anthropic 接続)。--fake-llm 時のみ canned 応答 (T035 の
+# CannedPromptFakeRegistrar)。storage は既定 fake (実 S3 未配線 = inert トグル)。
+# フラグは provision 時に env -i で明示注入する (残留 env による反転を防ぐため両モードとも明示)。
+LLM_MODE="real"        # real (既定) | fake
+STORAGE_MODE="fake"    # fake (既定) | real
+# MODE_ENV:    フラグのみ (TESTING_FAKE_LLM / TESTING_FAKE_STORAGE)。serve/worker/実効 env 検証に注入可 (秘密なし)。
+# LLM_KEY_ENV: ANTHROPIC_API_KEY (実キー)。serve/worker のみに注入 (実 LLM を呼ぶプロセスに限定)。
+#              値はここでのみ扱い、echo / manifest_update / migrate/seed/verify には渡さない。
+# (set -u 安全: assert が build_mode_env 前に呼ばれても ${#LLM_KEY_ENV[@]} が壊れないよう空配列で初期化)
+MODE_ENV=()
+LLM_KEY_ENV=()
+
+# 秘密取扱の xtrace ガード: 本スクリプトは既定 set -euo pipefail (-x 無し) だが、-x 有効時も
+# 秘密 (キー値・キーを含む代入/起動) を trace に出さない防御。BEGIN/END で秘密取扱区間を挟む。
+# ネスト非対応 (単純用途)。
+_SECRET_XTRACE_SAVED=0
+secret_xtrace_off()     { case $- in *x*) _SECRET_XTRACE_SAVED=1; set +x ;; *) _SECRET_XTRACE_SAVED=0 ;; esac; }
+secret_xtrace_restore() { [[ "${_SECRET_XTRACE_SAVED}" == 1 ]] && set -x; return 0; }
+
+# 親 .env から 1 キーを読む。値はログ・stderr に出さない (xtrace 局所退避。command 置換の
+# subshell 内で set +x するため親の -x 状態は汚さない)。キーは awk 完全一致 (正規表現メタ文字の
+# 事故を防ぐ)。export 前置・単/ダブルクォート・非クォート値の後置コメントを正しく処理する
+# (実キー誤読で real-llm 判定を誤らせないため)。
+main_env_get() {
+    { set +x; } 2>/dev/null
+    [[ -f "${MAIN_ENV_FILE}" ]] || { printf ''; return 0; }
+    local v
+    v="$(awk -v k="$1" '
+        { s=$0; sub(/^[[:space:]]*export[[:space:]]+/, "", s);
+          eq=index(s, "=");
+          if (eq>0 && substr(s,1,eq-1)==k) { print substr(s, eq+1); exit } }
+    ' "${MAIN_ENV_FILE}")"
+    if   [[ "${v}" == \"*\" ]]; then v="${v#\"}"; v="${v%\"}"       # "..." を剥がす
+    elif [[ "${v}" == \'*\' ]]; then v="${v#\'}"; v="${v%\'}"       # '...' を剥がす
+    else v="${v%%[[:space:]]#*}"; fi                               # 非クォート時のみ後置 # コメント除去
+    v="${v#"${v%%[![:space:]]*}"}"; v="${v%"${v##*[![:space:]]}"}" # 前後空白除去
+    printf '%s' "${v}"
+}
+
+# モード env を構築する (フラグと実キーを分離 = 最小権限)。両フラグを常に明示注入し、
+# real-llm 時のみ LLM_KEY_ENV に実キーを載せる。秘密取扱区間は xtrace 退避で囲む。
+build_mode_env() {
+    secret_xtrace_off                              # キー代入を trace に出さない
+    MODE_ENV=(); LLM_KEY_ENV=()
+    if [[ "${LLM_MODE}" == "fake" ]]; then
+        MODE_ENV+=("TESTING_FAKE_LLM=true")
+    else
+        MODE_ENV+=("TESTING_FAKE_LLM=false")       # real も明示注入 (残留 env による反転防止)
+        local key; key="$(main_env_get ANTHROPIC_API_KEY)"
+        LLM_KEY_ENV+=("ANTHROPIC_API_KEY=${key}")  # serve/worker 限定
+    fi
+    if [[ "${STORAGE_MODE}" == "real" ]]; then
+        MODE_ENV+=("TESTING_FAKE_STORAGE=false")
+    else
+        MODE_ENV+=("TESTING_FAKE_STORAGE=true")     # 既定 fake も明示注入
+    fi
+    secret_xtrace_restore
+}
+
+# real-llm ∧ キー空/未設定なら die。キーの読取は build_mode_env の 1 箇所のみ (ここでは再読しない)。
+# 既に構築済みの LLM_KEY_ENV を検査する (単一正本)。値を trace に出さないよう xtrace 退避で囲む。
+assert_llm_key_present() {
+    [[ "${LLM_MODE}" == "real" ]] || return 0
+    secret_xtrace_off
+    if [[ "${#LLM_KEY_ENV[@]}" -ne 1 || "${LLM_KEY_ENV[0]}" == "ANTHROPIC_API_KEY=" ]]; then
+        secret_xtrace_restore
+        die 1 "real-llm (既定) だが ${MAIN_ENV_FILE} に ANTHROPIC_API_KEY が無い/空です。\
+実キーで探索するか、--fake-llm で canned 応答に切り替えてください (fake-llm は再現/切り分け用)。(キー値はログに出しません)"
+    fi
+    secret_xtrace_restore
+}
+
+# cmd_provision 冒頭と cmd_provision_all のループ前で共通に呼ぶ (分岐差を作らない)。
+# build_mode_env (キー読取) → assert_llm_key_present (配列検査) の順で LLM_KEY_ENV を単一正本にする。
+prepare_mode_and_preflight() {
+    build_mode_env
+    assert_llm_key_present
+}
+
 # --- ★ 用途別 wrapper (env -i で最小環境、bughunt 値のみ明示注入) --------------
 
 # artisan (migrate:fresh / db:seed / tinker / migrate) — runtime 経路。
@@ -623,18 +711,23 @@ start_shard_workers() {
     local shard=$1 db=$2 url=$3
     guard_bughunt_runtime "${db}" bughunt
     local conn pid
+    # 秘密 (LLM_KEY_ENV) を展開するプロセス起動を xtrace ガードで挟む (-x 有効時も値を trace に出さない)。
+    # worker は serve と同一の env 隔離 + モードフラグ + 実キー (real-llm 時のみ) を注入する。
+    secret_xtrace_off
     for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
         env -i PATH="${PATH}" HOME="${HOME}" \
             DB_CONNECTION=pgsql \
             DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
             DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
             APP_URL="${url}" \
+            ${MODE_ENV[@]+"${MODE_ENV[@]}"} ${LLM_KEY_ENV[@]+"${LLM_KEY_ENV[@]}"} \
             setsid php artisan queue:listen "${conn}" --env=bughunt.local \
                 --sleep=1 --tries=1 --timeout=1800 \
             > "$(worker_logfile "${shard}" "${conn}")" 2>&1 &
         pid=$!
         echo "${pid}" > "$(worker_pidfile "${shard}" "${conn}")"
     done
+    secret_xtrace_restore
     # fail-fast: 起動 1 秒後の即死検知 (artisan 起動失敗・接続不能などを provision 段階で顕在化)。
     # 併せて pid==pgid (setsid が新 session/process group を確立したこと) を検証する
     # (group kill / group 消滅待ちの前提条件を起動時不変条件として固定。Codex 詳細 R3 反映)。
@@ -757,6 +850,10 @@ cmd_provision() {
     [[ "$(env_file_get APP_ENV)" == "bughunt.local" ]] || die 1 "${ENV_FILE} の APP_ENV が bughunt.local でない"
     [[ "$(env_file_get DB_USERNAME)" == "bughunt" ]] || die 1 "${ENV_FILE} の DB_USERNAME は bughunt 固定"
 
+    # モード env を構築し real-llm の実キーを fail-fast 検証する (createdb の前)。
+    # build_mode_env (キー読取) → assert_llm_key_present (配列検査) の単一正本。
+    prepare_mode_and_preflight
+
     clear_stale_config
     ensure_fresh_assets
 
@@ -783,9 +880,19 @@ cmd_provision() {
     # (b2) Filament 静的アセット publish (F-13 対策)。冪等 (marker + 実在確認で skip)。
     ensure_filament_assets "${db}" "${url}"
 
-    # (c) 実効 env 検証 (不一致 fail-fast)
+    # (c) 実効 env 検証 (不一致 fail-fast)。serve/worker と同一フラグ (MODE_ENV) で config が
+    #     解決されることを検証する (config key / env 名の typo を provision 段階で検出)。
+    #     artisan_for_shard は migrate/seed と共用で MODE_ENV を載せないため、serve と同型の専用
+    #     env -i ブロックで MODE_ENV (フラグのみ) を展開する。実キー (LLM_KEY_ENV) は verify に載せない。
+    guard_bughunt_runtime "${db}" bughunt
     local effective
-    effective="$(artisan_for_shard "${db}" "${url}" tinker --execute='
+    effective="$(env -i PATH="${PATH}" HOME="${HOME}" \
+        DB_CONNECTION=pgsql \
+        DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
+        DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
+        APP_URL="${url}" \
+        ${MODE_ENV[@]+"${MODE_ENV[@]}"} \
+        php artisan tinker --execute='
         echo json_encode([
             "db" => config("database.connections.pgsql.database"),
             "app_url" => config("app.url"),
@@ -795,16 +902,24 @@ cmd_provision() {
             "mail" => config("mail.default"),
             "filesystem" => config("filesystems.default"),
             "admin_mfa_required" => config("admin.mfa_required"),
-        ]);' | grep -o '{.*}' | tail -1)"
-    EFFECTIVE="${effective}" DB="${db}" URL="${url}" python3 - <<'PY'
+            "fake_llm" => config("testing.fake_llm"),
+            "fake_storage" => config("testing.fake_storage"),
+        ]);' --env=bughunt.local | grep -o '{.*}' | tail -1)"
+    EFFECTIVE="${effective}" DB="${db}" URL="${url}" LLM_MODE="${LLM_MODE}" STORAGE_MODE="${STORAGE_MODE}" python3 - <<'PY'
 import json, os, sys
 e = json.loads(os.environ["EFFECTIVE"])
 expected = {
     "db": os.environ["DB"], "app_url": os.environ["URL"],
     "session": "database", "cache": "database", "queue": "sync",
-    "mail": "log", "filesystem": "local",
+    "mail": "log",
     "admin_mfa_required": False,
+    # モードから期待値を導出 (serve/worker と同一フラグで config が解決されることを固定)。
+    "fake_llm": (os.environ["LLM_MODE"] == "fake"),
+    "fake_storage": (os.environ["STORAGE_MODE"] == "fake"),
 }
+# filesystem は fake_storage (既定) 時のみ local を必須化。real-storage は inert のため緩める。
+if os.environ["STORAGE_MODE"] == "fake":
+    expected["filesystem"] = "local"
 diff = {k: (e.get(k), v) for k, v in expected.items() if e.get(k) != v}
 if diff:
     print(f"error: 隔離前提の実効 env が不一致 (実効値, 期待値): {diff}", file=sys.stderr)
@@ -841,15 +956,20 @@ PY
 
     # (e) serve 起動 + ヘルスチェック。--no-reload 必須 (ServeCommand が --env 時に
     #     passthrough 外の env を php -S 子から破棄する)。coverage_env は同じ env -i 行で明示展開する。
+    # 秘密 (LLM_KEY_ENV) を展開するプロセス起動を xtrace ガードで挟む (-x 有効時も値を trace に出さない)。
+    # coverage_env / MODE_ENV / LLM_KEY_ENV は同じ env -i 行で明示展開する (real-llm 時のみ実キーが載る)。
+    secret_xtrace_off
     env -i PATH="${PATH}" HOME="${HOME}" \
         DB_CONNECTION=pgsql \
         DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
         DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
         APP_URL="${url}" \
         ${coverage_env[@]+"${coverage_env[@]}"} \
+        ${MODE_ENV[@]+"${MODE_ENV[@]}"} ${LLM_KEY_ENV[@]+"${LLM_KEY_ENV[@]}"} \
         nohup php artisan serve --env=bughunt.local --port="${port}" --no-reload \
         > "${TMP_BASE}/serve-${shard}.log" 2>&1 &
     local serve_pid=$!
+    secret_xtrace_restore
     echo "${serve_pid}" > "${TMP_BASE}/serve-${shard}.pid"
     manifest_update "${run_id}" "${shard}" "serve_pid=${serve_pid}" "port=${port}"
     local t code=000
@@ -899,6 +1019,10 @@ cmd_provision_all() {
         die 1 "別の bug-hunt run が実行中 (${LOCK_FILE})。完了を待つこと"
     fi
 
+    # モード env / 実キーの fail-fast を run-id 採番・shard provision の前に共通で通す
+    # (real-llm でキー欠落なら shard 1 に進む前に止める。dryrun はキー検証をスキップ)。
+    is_dryrun || prepare_mode_and_preflight
+
     if [[ -n "${COVERAGE:-}" ]]; then
         echo "coverage: 全 ${n} shard の serve が pcov 付きで起動する (実装到達カバレッジ収集。pcov 不在なら no-op)" >&2
     fi
@@ -1628,6 +1752,134 @@ CURLEOF
     rm -f "$(worker_pidfile 8 database-media)"
     t_ok "queue worker wiring (derivation/drift/structure/alive/dryrun/stop 正常系+失敗系)"
 
+    echo "[z] real-llm/fake-llm/real-storage モード制御 (フラグ/キー分離・fail-fast・秘密漏洩防止・引数解析)"
+    local _e
+    local z_env="${sandbox}/main-with-key.env"
+    cat > "${z_env}" <<'ZENVEOF'
+APP_ENV=local
+ANTHROPIC_API_KEY=sk-ant-SELFTEST-DUMMY
+OTHER=x
+ZENVEOF
+    local z_env_nokey="${sandbox}/main-no-key.env"
+    cat > "${z_env_nokey}" <<'ZENVEOF'
+APP_ENV=local
+ANTHROPIC_API_KEY=
+ZENVEOF
+    local _saved_main_env="${MAIN_ENV_FILE}"
+    local _saved_llm_mode="${LLM_MODE}" _saved_storage_mode="${STORAGE_MODE}"
+    arr_has() { local needle=$1; shift; local e; for e in "$@"; do [[ "$e" == "$needle" ]] && return 0; done; return 1; }
+
+    # [z1] build_mode_env: フラグ/キー分離 (MODE_ENV には実キーが決して含まれない)
+    MAIN_ENV_FILE="${z_env}"
+    LLM_MODE="real"; STORAGE_MODE="fake"; build_mode_env
+    arr_has "TESTING_FAKE_LLM=false" "${MODE_ENV[@]}" || t_fail "[z1] real-llm で MODE_ENV に TESTING_FAKE_LLM=false が無い"
+    arr_has "TESTING_FAKE_STORAGE=true" "${MODE_ENV[@]}" || t_fail "[z1] fake-storage 既定で MODE_ENV に TESTING_FAKE_STORAGE=true が無い"
+    { [[ "${#LLM_KEY_ENV[@]}" == 1 && "${LLM_KEY_ENV[0]}" == "ANTHROPIC_API_KEY=sk-ant-SELFTEST-DUMMY" ]]; } \
+        || t_fail "[z1] real-llm で LLM_KEY_ENV に実キーが載らない"
+    arr_has "ANTHROPIC_API_KEY=sk-ant-SELFTEST-DUMMY" "${MODE_ENV[@]}" && t_fail "[z1] MODE_ENV に実キーが混入 (フラグ/キー分離違反)"
+    for _e in "${MODE_ENV[@]}"; do [[ "${_e}" == *"sk-ant-SELFTEST-DUMMY"* ]] && t_fail "[z1] MODE_ENV 要素にキー値が含まれる"; done
+    LLM_MODE="fake"; STORAGE_MODE="fake"; build_mode_env
+    arr_has "TESTING_FAKE_LLM=true" "${MODE_ENV[@]}" || t_fail "[z1] fake-llm で MODE_ENV に TESTING_FAKE_LLM=true が無い"
+    [[ "${#LLM_KEY_ENV[@]}" == 0 ]] || t_fail "[z1] fake-llm で LLM_KEY_ENV が空でない"
+    LLM_MODE="fake"; STORAGE_MODE="real"; build_mode_env
+    arr_has "TESTING_FAKE_STORAGE=false" "${MODE_ENV[@]}" || t_fail "[z1] real-storage で TESTING_FAKE_STORAGE=false が無い"
+    t_ok "[z1] build_mode_env フラグ/キー分離"
+
+    # [z1b] main_env_get: export/クォート/後置コメント/完全一致
+    local z_parse="${sandbox}/main-parse.env"
+    cat > "${z_parse}" <<'ZPEOF'
+export EXPKEY=expval
+DQ="dqval"
+SQ='sqval'
+CM=cmval  # trailing comment
+ANTHROPIC_API_KEY=realkey
+ANTHROPIC_API_KEY_SUFFIX=shouldnotmatch
+ZPEOF
+    MAIN_ENV_FILE="${z_parse}"
+    [[ "$(main_env_get EXPKEY)" == "expval" ]] || t_fail "[z1b] export 前置を剥がせない"
+    [[ "$(main_env_get DQ)" == "dqval" ]] || t_fail "[z1b] ダブルクォート除去失敗"
+    [[ "$(main_env_get SQ)" == "sqval" ]] || t_fail "[z1b] シングルクォート除去失敗"
+    [[ "$(main_env_get CM)" == "cmval" ]] || t_fail "[z1b] 後置コメント除去失敗"
+    [[ "$(main_env_get ANTHROPIC_API_KEY)" == "realkey" ]] || t_fail "[z1b] 完全一致キー取得失敗"
+    [[ -z "$(main_env_get ANTHROPIC_API)" ]] || t_fail "[z1b] 部分一致キーを誤取得"
+    [[ -z "$(main_env_get NOPE)" ]] || t_fail "[z1b] 欠損キーが空でない"
+    t_ok "[z1b] main_env_get parsing"
+
+    # [z2] assert_llm_key_present: real∧キー無し→die(1) / real∧キー有り→0 / fake∧キー無し→0
+    MAIN_ENV_FILE="${z_env}"; LLM_MODE="real"; STORAGE_MODE="fake"; build_mode_env
+    ( assert_llm_key_present ) >/dev/null 2>&1 || t_fail "[z2] real-llm ∧ キー有りで die"
+    MAIN_ENV_FILE="${z_env_nokey}"; LLM_MODE="real"; build_mode_env
+    rc=0; ( assert_llm_key_present ) >/dev/null 2>&1 || rc=$?
+    [[ "${rc}" == 1 ]] || t_fail "[z2] real-llm ∧ キー無しで die(1) しない (rc=${rc})"
+    MAIN_ENV_FILE="${z_env_nokey}"; LLM_MODE="fake"; build_mode_env
+    ( assert_llm_key_present ) >/dev/null 2>&1 || t_fail "[z2] fake-llm ∧ キー無しでも rc=0 のはず"
+    t_ok "[z2] assert_llm_key_present"
+
+    # [z3] 秘密漏洩防止: stdout/stderr どちらにもキー値が出ない (通常 + set -x 有効実行)
+    MAIN_ENV_FILE="${z_env}"; LLM_MODE="real"; STORAGE_MODE="fake"
+    local z3_out z3_err
+    z3_out="$( ( prepare_mode_and_preflight ) 2>"${sandbox}/z3.err" )"
+    z3_err="$(cat "${sandbox}/z3.err")"
+    echo "${z3_out}" | grep -q 'sk-ant-SELFTEST-DUMMY' && t_fail "[z3] 通常実行の stdout にキー値が漏洩"
+    echo "${z3_err}" | grep -q 'sk-ant-SELFTEST-DUMMY' && t_fail "[z3] 通常実行の stderr にキー値が漏洩"
+    z3_out="$( ( set -x; prepare_mode_and_preflight ) 2>"${sandbox}/z3x.err" )"
+    z3_err="$(cat "${sandbox}/z3x.err")"
+    echo "${z3_out}" | grep -q 'sk-ant-SELFTEST-DUMMY' && t_fail "[z3] set -x 実行の stdout にキー値が漏洩"
+    echo "${z3_err}" | grep -q 'sk-ant-SELFTEST-DUMMY' && t_fail "[z3] set -x 実行の stderr にキー値が漏洩 (xtrace ガード不発)"
+    prepare_mode_and_preflight
+    for _e in "${MODE_ENV[@]}"; do [[ "${_e}" == *"sk-ant-SELFTEST-DUMMY"* ]] && t_fail "[z3] MODE_ENV にキー値混入"; done
+    t_ok "[z3] 秘密漏洩防止 (通常/set -x)"
+
+    # [z4] 引数解析: 相互排他 + provision 系専用 + provision 系 dryrun 受理
+    rc=0; ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990401-000000 --real-llm --fake-llm) >/dev/null 2>&1 || rc=$?
+    [[ "${rc}" == 2 ]] || t_fail "[z4] --real-llm --fake-llm 同時指定が exit ${rc} (expected 2)"
+    for badsub in teardown reseed db-check verify-run self-test; do
+        for badflag in --real-llm --fake-llm --real-storage; do
+            rc=0; ("${SCRIPT_PATH}" "${badsub}" --run-id 20990401-000000 "${badflag}") >/dev/null 2>&1 || rc=$?
+            [[ "${rc}" == 2 ]] || t_fail "[z4] ${badflag} が ${badsub} で exit ${rc} (expected 2)"
+        done
+    done
+    export BUGHUNT_SELFTEST_DRYRUN=1
+    rc=0; ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990402-000000 --fake-llm) >/dev/null 2>&1 || rc=$?
+    [[ "${rc}" == 0 ]] || t_fail "[z4] provision --fake-llm (dryrun) が exit ${rc} (expected 0)"
+    rc=0; ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990403-000000 --real-storage) >/dev/null 2>&1 || rc=$?
+    [[ "${rc}" == 0 ]] || t_fail "[z4] provision --real-storage (dryrun) が exit ${rc} (expected 0)"
+    unset BUGHUNT_SELFTEST_DRYRUN
+    t_ok "[z4] 引数解析 (相互排他 / provision 系専用)"
+
+    # [z5] 実効 env 検証の期待値導出 (python 断片) を mode 別に単体評価
+    local z5
+    z5="$(LLM_MODE=fake STORAGE_MODE=fake python3 - <<'PY'
+import os
+expected = {}
+expected["fake_llm"] = (os.environ["LLM_MODE"] == "fake")
+expected["fake_storage"] = (os.environ["STORAGE_MODE"] == "fake")
+if os.environ["STORAGE_MODE"] == "fake":
+    expected["filesystem"] = "local"
+assert expected["fake_llm"] is True and expected["fake_storage"] is True and expected["filesystem"] == "local"
+print("ok")
+PY
+)"
+    [[ "${z5}" == "ok" ]] || t_fail "[z5] fake/fake 期待値導出が不正"
+    z5="$(LLM_MODE=real STORAGE_MODE=real python3 - <<'PY'
+import os
+expected = {}
+expected["fake_llm"] = (os.environ["LLM_MODE"] == "fake")
+expected["fake_storage"] = (os.environ["STORAGE_MODE"] == "fake")
+if os.environ["STORAGE_MODE"] == "fake":
+    expected["filesystem"] = "local"
+assert expected["fake_llm"] is False and expected["fake_storage"] is False and "filesystem" not in expected
+print("ok")
+PY
+)"
+    [[ "${z5}" == "ok" ]] || t_fail "[z5] real/real 期待値導出が不正"
+    t_ok "[z5] 実効 env 期待値導出 (real/fake/real-storage)"
+
+    # モード globals を復元 (後続に影響させない)
+    MAIN_ENV_FILE="${_saved_main_env}"
+    LLM_MODE="${_saved_llm_mode}"; STORAGE_MODE="${_saved_storage_mode}"
+    MODE_ENV=(); LLM_KEY_ENV=()
+
     rm -rf "${sandbox}"
     unset BUGHUNT_SANDBOX
     if [[ "${failures}" -gt 0 ]]; then
@@ -1640,7 +1892,7 @@ CURLEOF
 # --- 引数解析 -----------------------------------------------------------------
 
 usage() {
-    sed -n '2,55p' "${SCRIPT_PATH}" | sed 's/^# \{0,1\}//'
+    sed -n '2,54p' "${SCRIPT_PATH}" | sed 's/^# \{0,1\}//'
     exit 2
 }
 
@@ -1649,6 +1901,10 @@ main() {
     shift || true
     local shard="" run_id="" count=5 drop_db="" parallel=4 hold_lock=""
     COVERAGE=""    # --coverage: pcov 付きで serve 起動しコード到達カバレッジを収集 (既定 OFF)
+    # モードは既定 real-llm + fake-storage。専用フラグ変数で「同時指定」「適用範囲」を判定する
+    # (LLM_MODE/STORAGE_MODE の上書きだけだと「既定と同値の明示指定」を取りこぼすため)。
+    LLM_MODE="real"; STORAGE_MODE="fake"
+    local _llm_flag_real=0 _llm_flag_fake=0 _storage_flag_real=0
 
     while [[ $# -gt 0 ]]; do
         case "$1" in
@@ -1658,6 +1914,9 @@ main() {
             --parallel=*) parallel="${1#--parallel=}"; shift ;;
             --parallel) shift ;;
             --coverage) COVERAGE=1; shift ;;
+            --real-llm) LLM_MODE="real"; _llm_flag_real=1; shift ;;
+            --fake-llm) LLM_MODE="fake"; _llm_flag_fake=1; shift ;;
+            --real-storage) STORAGE_MODE="real"; _storage_flag_real=1; shift ;;
             --drop-db) drop_db="--drop-db"; shift ;;
             --hold-lock) hold_lock="--hold-lock"; shift ;;
             *) die 2 "unknown option: $1" ;;
@@ -1669,6 +1928,15 @@ main() {
             || die 2 "--coverage は provision または provision-all でのみ使える"
     fi
 
+    # モードフラグ: 相互排他 + provision 系専用 (--coverage と同じ流儀。teardown --real-llm 等も拒否)。
+    if [[ "${_llm_flag_real}" == 1 && "${_llm_flag_fake}" == 1 ]]; then
+        die 2 "--real-llm と --fake-llm は同時指定できません (モードを 1 つ選ぶ)"
+    fi
+    if [[ "${_llm_flag_real}" == 1 || "${_llm_flag_fake}" == 1 || "${_storage_flag_real}" == 1 ]]; then
+        [[ "${sub}" == "provision" || "${sub}" == "provision-all" ]] \
+            || die 2 "--real-llm / --fake-llm / --real-storage は provision または provision-all でのみ使える"
+    fi
+
     case "${sub}" in
         provision)
             validate_shard "${shard}"; validate_run_id "${run_id}"
diff --git a/tests/Feature/Config/TestingFlagsDefaultTest.php b/tests/Feature/Config/TestingFlagsDefaultTest.php
new file mode 100644
index 0000000..4f01c8b
--- /dev/null
+++ b/tests/Feature/Config/TestingFlagsDefaultTest.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * config/testing.php の系統別 fake フラグ (fake_externals / fake_llm / fake_storage) の
+ * env 未設定前提 (config 既定) を固定する回帰テスト。
+ *
+ * phpunit.xml は TESTING_FAKE_LLM / TESTING_FAKE_STORAGE / TESTING_FAKE_EXTERNALS を
+ * どれも設定しないため、testing 環境での実効値は config 既定 (すべて false) になる。
+ * 将来 phpunit.xml に TESTING_FAKE_* が追加された場合は本テストが検知して落ちる
+ * (= 既定変更の可視化)。bughunt の real-llm 既定 (fake_llm=false) はこの既定に依存する。
+ */
+
+test('fake_llm は testing 環境で既定 false (bool)', function (): void {
+    expect(config('testing.fake_llm'))->toBeFalse();
+    expect(config('testing.fake_llm'))->toBeBool();
+});
+
+test('fake_storage は testing 環境で既定 false (bool)', function (): void {
+    expect(config('testing.fake_storage'))->toBeFalse();
+    expect(config('testing.fake_storage'))->toBeBool();
+});
+
+test('fake_externals も既定 false のまま不変 (回帰防止)', function (): void {
+    expect(config('testing.fake_externals'))->toBeFalse();
+    expect(config('testing.fake_externals'))->toBeBool();
+});
diff --git a/tests/Feature/Providers/FakeExternalsServiceProviderTest.php b/tests/Feature/Providers/FakeExternalsServiceProviderTest.php
index 0c17fd7..c5f410e 100644
--- a/tests/Feature/Providers/FakeExternalsServiceProviderTest.php
+++ b/tests/Feature/Providers/FakeExternalsServiceProviderTest.php
@@ -61,18 +61,19 @@
 });
 
 /*
- * boot(): LLM (Prism) fake の環境 allowlist (bughunt.local のみ)。
+ * boot(): LLM (Prism) fake は config('testing.fake_llm') が capability flag (fake_externals から分離)。
+ * 環境 allowlist は bughunt.local のみ。bughunt 既定は real-llm (fake_llm off) で install しない。
  * 各テストは env と config を try/finally で原値復元する (static/config 汚染を漏らさない)。
  */
 
-test('boot: env=bughunt.local ∧ flag=true で Prompt fake が有効になり canned を返す', function (): void {
+test('boot: env=bughunt.local ∧ fake_llm=true で Prompt fake が有効になり canned を返す', function (): void {
     // 万一の FX 解決 HTTP を stray にしない防御。
     Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
 
     $originalEnv = $this->app['env'];
-    $originalFlag = config('testing.fake_externals');
+    $originalFlag = config('testing.fake_llm');
     try {
-        config(['testing.fake_externals' => true]);
+        config(['testing.fake_llm' => true]);
         $this->app['env'] = 'bughunt.local';
         (new FakeExternalsServiceProvider($this->app))->boot();
 
@@ -84,50 +85,69 @@
         expect(trim((string) $summary))->not->toBe('');
     } finally {
         Prompt::stopFaking();
-        config(['testing.fake_externals' => $originalFlag]);
+        config(['testing.fake_llm' => $originalFlag]);
         $this->app['env'] = $originalEnv;
     }
 });
 
-test('boot: env=testing ∧ flag=true では Prompt::$fake に触れない (static 占有を避ける)', function (): void {
-    $originalFlag = config('testing.fake_externals');
+test('boot: env=testing ∧ fake_llm=true では Prompt::$fake に触れない (static 占有を避ける)', function (): void {
+    $originalFlag = config('testing.fake_llm');
     try {
         // env は既定の testing のまま。
-        config(['testing.fake_externals' => true]);
+        config(['testing.fake_llm' => true]);
         (new FakeExternalsServiceProvider($this->app))->boot();
 
         expect(Prompt::isFaking())->toBeFalse();
     } finally {
-        config(['testing.fake_externals' => $originalFlag]);
+        config(['testing.fake_llm' => $originalFlag]);
     }
 });
 
-test('boot: env=local ∧ flag=true では Prompt::$fake に触れない (実 API 検証を潰さない)', function (): void {
+test('boot: env=local ∧ fake_llm=true では Prompt::$fake に触れない (実 API 検証を潰さない)', function (): void {
     $originalEnv = $this->app['env'];
-    $originalFlag = config('testing.fake_externals');
+    $originalFlag = config('testing.fake_llm');
     try {
-        config(['testing.fake_externals' => true]);
+        config(['testing.fake_llm' => true]);
         $this->app['env'] = 'local';
         (new FakeExternalsServiceProvider($this->app))->boot();
 
         expect(Prompt::isFaking())->toBeFalse();
     } finally {
-        config(['testing.fake_externals' => $originalFlag]);
+        config(['testing.fake_llm' => $originalFlag]);
         $this->app['env'] = $originalEnv;
     }
 });
 
-test('boot: flag=false では bughunt.local でも Prompt fake を配線しない (完全 no-op)', function (): void {
+test('boot: fake_llm=false では bughunt.local でも Prompt fake を配線しない (real 経路)', function (): void {
     $originalEnv = $this->app['env'];
-    $originalFlag = config('testing.fake_externals');
+    $originalFlag = config('testing.fake_llm');
     try {
-        config(['testing.fake_externals' => false]);
+        config(['testing.fake_llm' => false]);
+        $this->app['env'] = 'bughunt.local';
+        (new FakeExternalsServiceProvider($this->app))->boot();
+
+        expect(Prompt::isFaking())->toBeFalse();
+    } finally {
+        config(['testing.fake_llm' => $originalFlag]);
+        $this->app['env'] = $originalEnv;
+    }
+});
+
+test('boot: fake_externals=true でも fake_llm=false なら install しない (系統分離の回帰)', function (): void {
+    // Stripe fake が立っていても LLM は real (fake_externals と fake_llm の分離を固定)。
+    $originalEnv = $this->app['env'];
+    $originalExternals = config('testing.fake_externals');
+    $originalLlm = config('testing.fake_llm');
+    try {
+        config(['testing.fake_externals' => true]);
+        config(['testing.fake_llm' => false]);
         $this->app['env'] = 'bughunt.local';
         (new FakeExternalsServiceProvider($this->app))->boot();
 
         expect(Prompt::isFaking())->toBeFalse();
     } finally {
-        config(['testing.fake_externals' => $originalFlag]);
+        config(['testing.fake_externals' => $originalExternals]);
+        config(['testing.fake_llm' => $originalLlm]);
         $this->app['env'] = $originalEnv;
     }
 });
diff --git a/tests/Feature/Support/ProductionEnvGuardTest.php b/tests/Feature/Support/ProductionEnvGuardTest.php
index 5624ae1..1b31d20 100644
--- a/tests/Feature/Support/ProductionEnvGuardTest.php
+++ b/tests/Feature/Support/ProductionEnvGuardTest.php
@@ -16,6 +16,8 @@
     config(['debug.login.user' => '']);
     config(['debug.login.password' => '']);
     config(['testing.fake_externals' => false]);
+    config(['testing.fake_llm' => false]);
+    config(['testing.fake_storage' => false]);
     config(['trusted_hosts.exact_hosts' => ['app.example.com']]);
     config(['trusted_hosts.wildcard_suffixes' => []]);
     config(['trusted_hosts.raw_wildcard_suffixes' => []]);
@@ -101,6 +103,20 @@
     expect($errors[0])->toContain('TESTING_FAKE_EXTERNALS');
 });
 
+test('TESTING_FAKE_LLM が true なら violation', function (): void {
+    config(['testing.fake_llm' => true]);
+    $errors = (new ProductionEnvGuard)->violations();
+    expect($errors)->toHaveCount(1);
+    expect($errors[0])->toContain('TESTING_FAKE_LLM');
+});
+
+test('TESTING_FAKE_STORAGE が true なら violation', function (): void {
+    config(['testing.fake_storage' => true]);
+    $errors = (new ProductionEnvGuard)->violations();
+    expect($errors)->toHaveCount(1);
+    expect($errors[0])->toContain('TESTING_FAKE_STORAGE');
+});
+
 test('TrustHosts allowlist が空なら violation', function (): void {
     config(['trusted_hosts.exact_hosts' => []]);
     config(['trusted_hosts.wildcard_suffixes' => []]);
```

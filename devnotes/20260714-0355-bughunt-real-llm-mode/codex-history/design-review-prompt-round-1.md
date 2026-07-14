# レビュー依頼: 詳細設計 (bughunt real-llm モード)

## アプリの使命 (North Star / AGENTS.md より)

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、
そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化された
マニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。競合(tebiki)と異なり標準作業を起点に AI が
教材設計し撮影を指示する。熟練者の暗黙知を形式知へ変換する装置 (SECI)。

## 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて実装済み)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び (`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

## セキュリティ不変条件 (抜粋)

- 外部 fake は本番混入禁止 (ProductionEnvGuard fail-fast)。
- 実キー等の秘密情報をログ/成果物に平文で残さない。
- bughunt の dev DB 保護: 全 DB 操作は `env -i` 隔離 + DB名 regex + role guard 経由。この隔離を壊さない。

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。
【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia + TypeScript / PHPStan level 10 / Pest /
DTO + JsonResource / Laratrust RBAC。本 item は config / provider / support / **bash script** / doc 中心
(新モデル・API・DTO・TypeScript 型・Inertia Props の追加はなし)。

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)。特に **bash script の env -i 注入・
   モード判定・fail-fast・自己テストの正確性**、実効 env 検証の期待値導出。
2. 既存コードとの整合性 (命名規約、パターン、fake_externals との対称性)。
3. PHPStan level 10 適合性 (config/provider/guard の型)。
4. テスト計画の網羅性 (各施策に Pest / shell self-test。RefreshDatabase グローバル適用)。
5. 波及変更の網羅性 (既存 FakeExternalsServiceProviderTest / ProductionEnvGuardTest の必須波及が漏れていないか)。
6. 副作用・後退リスク (「本番挙動は不変」の担保、dev DB 保護 env -i 隔離を壊さないか、
   既存 self-test [a]〜[y] の回帰、Browser lane / StrayLlmCallGuard 非破壊)。
7. セキュリティ (実キーの秘密漏洩: ログ/stderr/manifest/self-test への非露出、production fail-secure)。
8. 設計の過不足 (real-storage 骨子化・ffmpeg スコープ外の妥当性)。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
  依存で不変」と明記。

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
   - モードフラグは `--coverage` と同様 **provision / provision-all 専用**（それ以外の subcommand に付くと exit 2）。
2. **親 dotenv reader**（`MAIN_ENV_FILE=".env"`、sandbox 差し替え可）: `ANTHROPIC_API_KEY` を xtrace 無効区間で読む。
3. **モード env 構築（`build_mode_env` / グローバル `MODE_ENV` 配列）**: serve / worker / 実効 env 検証の
   **単一 env 正本**。両フラグを常に明示注入し、real-llm 時のみ実キーを載せる。
4. **実キー fail-fast（`assert_llm_key_present`）**: real-llm ∧ キー空/未設定なら `--fake-llm` 案内付きで die。
5. **注入点**: `artisan_for_shard` / `start_shard_workers` / serve の `env -i` 行に `MODE_ENV` を展開。
6. **実効 env 検証（provision (c)）**: `fake_llm` / `fake_storage` の config 実効値をモード期待値と突合。
7. **provision-all**: ループ前に一度だけ `assert_llm_key_present`（全 shard 着手前に fail-fast）。
8. **keepdb-check / usage / self-test** にモード分岐を反映。

### 波及変更
- TypeScript / DTO / テスト（PHP）: なし。**shell self-test（`cmd_self_test`）に新セクション追加**（下記テスト計画）。

### 主要コード（設計）

**（a）引数解析（`main` 抜粋）**
```bash
LLM_MODE="real"       # real（既定）| fake
STORAGE_MODE="fake"   # fake（既定）| real
# ... while ループに追加 ...
    --real-llm)     LLM_MODE="real"; shift ;;
    --fake-llm)     LLM_MODE="fake"; shift ;;
    --real-storage) STORAGE_MODE="real"; shift ;;
# ループ後の相互排他・適用範囲チェック（--coverage と同じ流儀）
if [[ "${_llm_flag_real:-}" == 1 && "${_llm_flag_fake:-}" == 1 ]]; then
    die 2 "--real-llm と --fake-llm は同時指定できません（モードを 1 つ選ぶ）"
fi
if [[ "${LLM_MODE}" == "fake" || "${STORAGE_MODE}" == "real" ]]; then
    [[ "${sub}" == "provision" || "${sub}" == "provision-all" ]] \
        || die 2 "--fake-llm / --real-storage は provision または provision-all でのみ使える"
fi
```
> 実装注: 「同時指定」検出は `--real-llm` / `--fake-llm` それぞれで専用フラグ変数（`_llm_flag_real` /
> `_llm_flag_fake`）を立てて後段で判定する（`LLM_MODE` の上書きだけだと最後の指定が勝ち検出できないため）。

**（b）親 dotenv reader（xtrace 無効区間）**
```bash
MAIN_ENV_FILE  # sandbox: "${BUGHUNT_SANDBOX}/.env" / 通常: ".env"

# 親 .env から 1 キーを読む。値をログ・stderr に出さない（xtrace を局所退避）。
main_env_get() {
    { set +x; } 2>/dev/null   # 万一 -x 環境でも値を trace に出さない
    [[ -f "${MAIN_ENV_FILE}" ]] || { echo ""; return 0; }
    local v
    v="$(grep -E "^$1=" "${MAIN_ENV_FILE}" | head -1 | cut -d= -f2- || true)"
    v="${v%%[[:space:]]#*}"; v="${v#\"}"; v="${v%\"}"   # 後置コメント / 両端引用符除去
    v="${v#"${v%%[![:space:]]*}"}"; v="${v%"${v##*[![:space:]]}"}"
    printf '%s' "${v}"
}
```

**（c）モード env 構築（単一正本 `MODE_ENV`）**
```bash
# MODE_ENV: serve / worker / 実効 env 検証の env -i 行へ展開する共有配列。
# 値（特に ANTHROPIC_API_KEY）はここでのみ扱い、echo / manifest_update に渡さない。
build_mode_env() {
    MODE_ENV=()
    if [[ "${LLM_MODE}" == "fake" ]]; then
        MODE_ENV+=("TESTING_FAKE_LLM=true")
    else
        MODE_ENV+=("TESTING_FAKE_LLM=false")   # real も明示注入（残留 env による反転防止。Codex R3）
        local key; key="$(main_env_get ANTHROPIC_API_KEY)"
        MODE_ENV+=("ANTHROPIC_API_KEY=${key}")  # real-llm 時のみ実キーを載せる
    fi
    if [[ "${STORAGE_MODE}" == "real" ]]; then
        MODE_ENV+=("TESTING_FAKE_STORAGE=false")
    else
        MODE_ENV+=("TESTING_FAKE_STORAGE=true")  # 既定 fake も明示注入
    fi
}
```

**（d）実キー fail-fast**
```bash
# real-llm ∧ キー空/未設定なら die（値は出さず、キー名と退避手段のみ案内）。
assert_llm_key_present() {
    [[ "${LLM_MODE}" == "real" ]] || return 0
    local key; key="$(main_env_get ANTHROPIC_API_KEY)"
    [[ -n "${key}" ]] || die 1 "real-llm（既定）だが ${MAIN_ENV_FILE} に ANTHROPIC_API_KEY が無い/空です。\
実キーで探索するか、--fake-llm で canned 応答に切り替えてください（fake-llm は再現/切り分け用）。\
（キー値はログに出しません）"
}
```

**（e）注入点の展開（既存 env -i 行に 1 行追加）**
```bash
# artisan_for_shard / start_shard_workers / serve の env -i 行末尾付近に:
    ${MODE_ENV[@]+"${MODE_ENV[@]}"} \
```
- `artisan_for_shard` は provision の migrate/seed/tinker からも呼ばれるが、`MODE_ENV` は cmd_provision が
  設定したときのみ非空（cmd_reseed 等 child 経路では未設定 = 展開されず無害）。migrate/seed に LLM キーが
  載っても seeder は LLM 非依存で無害。**serve・worker・実効 env 検証が同一 `MODE_ENV` を共有する**ことで
  「serve と検証の mode 一致」を構造的に保証する。

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
- tinker 呼び出しも `MODE_ENV` を共有するため、注入した env が config に正しく写像されたかを fail-fast できる
  （config key / env 名の typo を provision 段階で検出）。

**（g）provision-all のループ前 fail-fast**
```bash
# cmd_provision_all: ensure_fresh_assets の前後で一度だけ
build_mode_env
assert_llm_key_present
```

**（h）keepdb-check / usage / self-test**
- `keepdb-check`: `--keep-db` reuse は provision をスキップするため **走行中の mode は provision 時に確定した
  ものを保持する**（mode 変更は再 provision が必要）。keepdb-check にはモードフラグを受け付けず、その旨を
  usage/エラー文言に明記（「mode を変えるには --keep-db を外して再 provision」）。
- `usage`: モードフラグ表と「`--real-storage` は未実装トグル（inert）」を明記（Codex 概念 R2/R3）。

### PHPStan 適合チェック
- 対象外（shell script）。ただし provision (c) の tinker 内 PHP 断片は既存同様に構文維持。

### テスト計画（`cmd_self_test` に新セクション `[z]` を追加、実資源に触れない）
- [x] `[z1]` `build_mode_env`: `LLM_MODE=real` → `TESTING_FAKE_LLM=false` を含み `TESTING_FAKE_STORAGE=true`。
      `LLM_MODE=fake` → `TESTING_FAKE_LLM=true`。`STORAGE_MODE=real` → `TESTING_FAKE_STORAGE=false`。
- [x] `[z2]` `assert_llm_key_present`: sandbox `MAIN_ENV_FILE` にキー無し ∧ `LLM_MODE=real` → die(rc=1)。
      キー有り → rc=0。`LLM_MODE=fake` → キー無しでも rc=0。
- [x] `[z3]` 秘密漏洩防止: real-llm で `MAIN_ENV_FILE` にダミーキーを置き `build_mode_env` 後、
      **die メッセージ / self-test 出力にキー値が現れない**ことを grep で確認（値は MODE_ENV 内のみ）。
- [x] `[z4]` 引数解析: `--real-llm --fake-llm` 同時 → exit 2。`--fake-llm` を `teardown` 等に付与 → exit 2。
      `provision --fake-llm`（dryrun）→ 0。
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
- 追記:
  ```dotenv
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
4. **LLM プロバイダ（Anthropic）への実接続のみ許可（real-llm 既定）。** 決済 / Captcha / SSO / mail / S3 等
   その他の外部は fake 維持。LLM プロバイダ以外の外部ドメインへの実リクエストを検知したら従来どおり即中断して
   報告する。`--fake-llm` 時は LLM も canned（実接続なし）。real-llm は実キー必須で、未設定なら provision が
   fail-fast する（--fake-llm を案内）。
```

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

- `config('testing.fake_llm')` が testing 環境で `false`（かつ `toBeBool()`）。
- `config('testing.fake_storage')` が testing 環境で `false`（かつ `toBeBool()`）。
- `config('testing.fake_externals')` の既定 false 不変も同居確認（回帰防止）。

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


---

## 関連する現行コード (抜粋)

### app/Providers/FakeExternalsServiceProvider.php (現行 boot)
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
// register(): fake_externals === true かつ PAYMENT_FAKE_ENVIRONMENTS で Stripe gateway を fake bind
// LLM_FAKE_ENVIRONMENTS = ['bughunt.local'] / PAYMENT_FAKE_ENVIRONMENTS = ['local','testing','bughunt.local']
```

### app/Support/ProductionEnvGuard.php (現行 fake_externals ブロック)
```php
if (config('testing.fake_externals') === true) {
    $errors[] = 'TESTING_FAKE_EXTERNALS must be false in production '
        .'(external fakes must never be enabled in production).';
}
// $errors は list<string>。enforce() が violations あれば RuntimeException。
// AppServiceProvider::boot() (production) と production:preflight が同 guard を参照。
```

### scripts/bug-hunt-shard.sh 既存の注入・self-test 構造 (抜粋)
```bash
# 既存 serve 起動 (env -i + coverage_env 展開)
env -i PATH="${PATH}" HOME="${HOME}" \
    DB_CONNECTION=pgsql DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
    DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
    APP_URL="${url}" \
    ${coverage_env[@]+"${coverage_env[@]}"} \
    nohup php artisan serve --env=bughunt.local --port="${port}" --no-reload > ... &

# 既存 start_shard_workers (connection ごとに env -i で queue:listen 起動)
# 既存 artisan_for_shard (migrate/seed/tinker を env -i + guard_bughunt_runtime 経由で実行)
# 既存 provision (c) 実効 env 検証: tinker で db/app_url/session/cache/queue/mail/filesystem/admin_mfa_required を
#   JSON 出力し python で expected と突合 (不一致 fail-fast)。現状 filesystem 期待は "local" 固定。
# 既存 main の --coverage: COVERAGE 変数を立て provision/provision-all のみ許可 (それ以外は die 2)
# 既存 self-test: [a]資源導出 [c-e]guard [j]wrapper封じ込め [x]coverage [y]worker配線 等を実資源なしで検証
# env -i 隔離は dev DB 保護の非交渉要件 (shell の DB_*/PG* を遮断してから bughunt 値のみ注入)
```

### config/testing.php (現行)
```php
return [ 'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false) ];
```

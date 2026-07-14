# 詳細設計レビュー Round 2 — Round 1 指摘への対応報告

Round 1 の 2 Critical + Warning に対応しました。施策 4 (bash) を中心に修正しています。

## [Critical] 施策4 main_env_get: grep 完全一致でない / クォート処理が脆弱 → 対応

`main_env_get` を **awk 完全一致** + 堅牢クォート処理に置換:

```bash
main_env_get() {
    { set +x; } 2>/dev/null
    [[ -f "${MAIN_ENV_FILE}" ]] || { printf ''; return 0; }
    local v
    v="$(awk -v k="$1" '
        { s=$0; sub(/^[[:space:]]*export[[:space:]]+/, "", s);
          eq=index(s, "=");
          if (eq>0 && substr(s,1,eq-1)==k) { print substr(s, eq+1); exit } }
    ' "${MAIN_ENV_FILE}")"
    if   [[ "${v}" == \"*\" ]]; then v="${v#\"}"; v="${v%\"}";
    elif [[ "${v}" == \'*\' ]]; then v="${v#\'}"; v="${v%\'}";
    else v="${v%%[[:space:]]#*}"; fi
    v="${v#"${v%%[![:space:]]*}"}"; v="${v%"${v##*[![:space:]]}"}"
    printf '%s' "${v}"
}
```
- export 前置 / 単・ダブルクォート / 非クォート値の後置コメントを正しく処理。キーは完全一致（メタ文字事故なし）。
- 実装注として Laravel `env()` の bool 正規化（literal `"false"` → `config()===false`）と phpdotenv immutable
  （env -i 注入が dotenv より優先 = script 注入が正本）を設計に明記。`(bool) env()` は fake_externals と一貫で据え置き。

## [Warning] 施策4 MODE_ENV を migrate/seed にも注入 = 秘密の配布面積拡大 → 対応 (フラグ/キー分離)

env を 2 分割:
- `MODE_ENV`（フラグのみ: TESTING_FAKE_LLM / TESTING_FAKE_STORAGE）→ serve / worker / 実効 env 検証 tinker に注入（秘密なし）。
- `LLM_KEY_ENV`（ANTHROPIC_API_KEY）→ **serve / worker のみ**に注入（実 LLM を呼ぶ 2 経路に限定）。
- migrate/seed（artisan_for_shard）には両方注入しない。実効 env 検証 tinker は artisan_for_shard を使わず serve 同型の
  専用 env -i で MODE_ENV（フラグのみ）を展開し config 写像を fail-fast 検証（実キーは載せない）。

## [Warning] 施策4 provision 単体 / provision-all で preflight 順序が分岐 → 対応

`prepare_mode_and_preflight()`（build_mode_env → assert_llm_key_present）に共通化し、cmd_provision 冒頭と
cmd_provision_all ループ前で同一関数を呼ぶ。

## [Warning] 施策2 docblock/定数/テスト名の三者一致 → 対応

boot() docblock に「LLM fake 許可環境は bughunt.local のみ（定数 LLM_FAKE_ENVIRONMENTS が正本）」を明示。

## [Warning] 施策6 「LLM 実接続のみ許可」が egress/SSRF 誤解 → 対応

禁止事項 4 を「許可する実外部接続は LLM プロバイダ API ドメインのみ。他の外部ドメインへの実リクエストは全面禁止・
検知で即中断（egress 許可先に LLM API を加えるだけで他は不変）」に精緻化。

## [Warning] 施策8 TestingFlagsDefaultTest が将来の phpunit env で脆い → 対応

テスト名に「env 未設定前提（config 既定）」を明記し、値 + toBeBool() を assert。将来 phpunit.xml に TESTING_FAKE_*
が追加されたら本テストが落ちて可視化される旨をコメント。

## [Suggestion] 群 → 反映

- self-test [z3] に「キー名のみ出力は可」を明文化 + [z1b] main_env_get の取得ケーステスト追加。
- 施策5 冒頭に「実効値は script 注入が正本。ここは説明用」を前置。
- 施策7 の環境ハザード記録を「HTTP status / 再試行回数 / 待機秒 / route」1 行フォーマットに。

---

上記を反映した詳細設計の該当セクション（施策 4 (b)-(g) + self-test [z]）を再掲します。全体判定をお願いします。
残 Critical/Warning があれば具体修正案を添えてください。

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
}
```

**（d）実キー fail-fast + 共通 preflight（provision / provision-all で順序を統一。Codex R1 Warning 反映）**
```bash
# real-llm ∧ キー空/未設定なら die（値は出さず、キー名と退避手段のみ案内）。
assert_llm_key_present() {
    [[ "${LLM_MODE}" == "real" ]] || return 0
    local key; key="$(main_env_get ANTHROPIC_API_KEY)"
    [[ -n "${key}" ]] || die 1 "real-llm（既定）だが ${MAIN_ENV_FILE} に ANTHROPIC_API_KEY が無い/空です。\
実キーで探索するか、--fake-llm で canned 応答に切り替えてください（fake-llm は再現/切り分け用）。（キー値はログに出しません）"
}

# cmd_provision 冒頭と cmd_provision_all のループ前で共通に呼ぶ（分岐差を作らない）。
prepare_mode_and_preflight() {
    build_mode_env
    assert_llm_key_present
}
```

**（e）注入点の展開（最小権限: serve/worker はフラグ+キー、verify はフラグのみ）**
```bash
# serve 起動 / start_shard_workers の env -i 行末尾付近に（フラグ + 実キー）:
    ${MODE_ENV[@]+"${MODE_ENV[@]}"} ${LLM_KEY_ENV[@]+"${LLM_KEY_ENV[@]}"} \

# 実効 env 検証 tinker（provision (c)）は MODE_ENV のみ（実キー不要。config 写像の検証が目的）:
    ${MODE_ENV[@]+"${MODE_ENV[@]}"} \
```
- `migrate/seed`（`artisan_for_shard`）には **MODE_ENV も LLM_KEY_ENV も注入しない**（LLM 非依存 = 不要、
  秘密の配布面積を最小化）。実効 env 検証 tinker のみ `MODE_ENV`（フラグ）を共有し、serve/worker と同一フラグで
  config が解決されることを fail-fast で担保する。実キー（`LLM_KEY_ENV`）は **serve/worker の 2 経路のみ**に存在する。

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
- [x] `[z3]` 秘密漏洩防止: real-llm で `MAIN_ENV_FILE` にダミーキーを置き `build_mode_env` 後、
      **die メッセージ / self-test 標準出力にキー値が現れない**ことを grep で確認（値は `LLM_KEY_ENV` 内のみ。
      キー名の露出は可）。
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



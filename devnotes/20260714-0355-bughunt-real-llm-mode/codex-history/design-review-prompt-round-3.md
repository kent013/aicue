# 詳細設計レビュー Round 3 — Round 2 指摘への対応報告

Round 2 の Critical + Warning に対応しました（施策 4 中心）。

## [Critical] xtrace 秘密漏洩が関数内 set +x だけでは閉じない → 対応 (プロセス起動まで含めてガード)

xtrace 退避/復元ヘルパを導入し、**秘密取扱の 3 箇所**（build_mode_env 本体 / serve 起動 / worker 起動）を挟む:

```bash
_SECRET_XTRACE_SAVED=0
secret_xtrace_off()     { case $- in *x*) _SECRET_XTRACE_SAVED=1; set +x ;; *) _SECRET_XTRACE_SAVED=0 ;; esac; }
secret_xtrace_restore() { [[ "${_SECRET_XTRACE_SAVED}" == 1 ]] && set -x; return 0; }

build_mode_env() {
    secret_xtrace_off
    MODE_ENV=(); LLM_KEY_ENV=()
    ...  # real: key="$(main_env_get ANTHROPIC_API_KEY)"; LLM_KEY_ENV+=("ANTHROPIC_API_KEY=${key}")
    secret_xtrace_restore
}

# serve / worker 起動:
secret_xtrace_off
env -i ... ${MODE_ENV[@]+"${MODE_ENV[@]}"} ${LLM_KEY_ENV[@]+"${LLM_KEY_ENV[@]}"} nohup php artisan serve ... &
serve_pid=$!
secret_xtrace_restore
```
- 本スクリプトは既定 `set -euo pipefail`（-x 無し）だが、-x 有効時も値を trace に出さない防御。
- main_env_get 内の `set +x`（subshell 内）は belt-and-suspenders として残す。

## [Warning] モードフラグ適用範囲判定が不完全 (teardown --real-llm が拒否されない) → 対応

専用フラグ変数で判定:
```bash
local _llm_flag_real=0 _llm_flag_fake=0 _storage_flag_real=0
    --real-llm)     LLM_MODE="real"; _llm_flag_real=1; shift ;;
    --fake-llm)     LLM_MODE="fake"; _llm_flag_fake=1; shift ;;
    --real-storage) STORAGE_MODE="real"; _storage_flag_real=1; shift ;;
# 相互排他
if [[ "${_llm_flag_real}" == 1 && "${_llm_flag_fake}" == 1 ]]; then die 2 "..."; fi
# どのモードフラグでも provision 系専用
if [[ "${_llm_flag_real}" == 1 || "${_llm_flag_fake}" == 1 || "${_storage_flag_real}" == 1 ]]; then
    [[ "${sub}" == "provision" || "${sub}" == "provision-all" ]] || die 2 "... は provision/provision-all でのみ"
fi
```

## [Warning] 施策4 冒頭「変更箇所」項目 3/5 が旧設計のまま → 対応

- 項目 3: 「MODE_ENV（フラグ）/ LLM_KEY_ENV（実キー）を分離。real 時のみ LLM_KEY_ENV に実キー。秘密区間は xtrace 退避」
- 項目 5: 「serve/worker へ MODE_ENV+LLM_KEY_ENV、専用 verify へ MODE_ENV のみ、artisan_for_shard（migrate/seed）へは注入なし」

## [Warning] [z3] が stdout だけでは不十分 (秘密は主に stderr の xtrace へ漏れる) → 対応

[z3] を **stdout/stderr 別捕捉 + 通常実行と xtrace 有効実行の双方**でダミーキー値の非包含を検証、に強化。
MODE_ENV にキー値が含まれないことも確認。[z4] に teardown --real-llm / self-test --real-storage の exit 2 を追加。

## 施策8 (施策4 の追加テスト不足のみ) → 上記 [z3]/[z4] 強化で解消

---

該当セクション（施策 4 の変更箇所リスト・(a)(a2)(c)(e) + self-test [z3][z4]）を再掲します。全体判定をお願いします。

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
- **秘密取扱の xtrace ガードは 3 箇所**: (1) `build_mode_env` の本体、(2) serve 起動、(3) worker 起動
  （`start_shard_workers` の env -i ループ）。これで -x 有効実行でもキー値が trace に出ない。

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
- [x] `[z3]` 秘密漏洩防止（stdout/stderr 別捕捉 + xtrace 有効実行の双方。Codex 詳細 R2 反映）: real-llm で
      `MAIN_ENV_FILE` にダミーキー（例 `sk-ant-SELFTEST-DUMMY`）を置き、(i) 通常実行と (ii) `set -x` 有効実行の
      両方で `build_mode_env`（および serve/worker 起動を模した secret_xtrace_off 区間）を走らせ、**stdout と stderr の
      どちらにもダミーキー値が現れない**ことを grep で確認（値は `LLM_KEY_ENV` 配列内のみ。キー名 `ANTHROPIC_API_KEY`
      の露出は可）。`MODE_ENV` にキー値が含まれないことも確認。
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



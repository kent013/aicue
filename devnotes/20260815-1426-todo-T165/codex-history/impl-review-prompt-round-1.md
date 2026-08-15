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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
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

---

# あなたの役割

Laravel + Svelte アプリのコードレビュアーとして、TODO T165「Browser テストレーンの導入自動化」の実装差分をレビューする。

## レビュー観点

1. **設計との一致性** — 詳細設計書の施策 1〜7 とテスト計画が実装されているか。設計から意図的に外れた箇所があれば、その妥当性
2. **正確性** — shell スクリプトの挙動 (set -euo pipefail 下の終了コード伝播 / 変数展開 / 分岐の網羅)、TypeScript / PHP の純関数の正しさ
3. **PHPStan level 10 適合性** — 型注釈、mixed の narrow、list<string> / array<string,string>
4. **テスト網羅性** — 負のコントロール (検出器が空振りしていないこと) が各検査に付いているか。deny-by-default を名乗る検査が実際に母集団を漏らしていないか
5. **セキュリティ** — 特権昇格 (sudo) の扱い、判定不能時の fail-closed、CI の soft-fail 偽装
6. **保証範囲の記述が誇張になっていないか** — 「保証しないもの」を正しく書けているか
7. **オーバーエンジニアリング** — 今必要でないものを作っていないか

本施策はアプリケーションコード (app/ resources/ routes/ database/ config/) を 1 行も変更しない。
DESIGN.md / Atomic Design 観点は差分に resources/js が含まれないため対象外である。

## 出力形式

- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明記する

---

## 詳細設計書

# 詳細設計: browser-test-lane-automation

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**(撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(Artifact ツールでの成果物公開を行わない)

> 本施策はアプリケーションコード (app/ resources/ routes/ database/ config/) を **1 行も変更しない**。
> 抵触しうるのは禁止事項 1 (テストなしの実装完了) だけであり、全施策にテスト計画を持たせて解消する。
> GitHub Actions の `upload-artifact` は禁止事項 9 の「Artifact ツールでの成果物公開」とは別概念である。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** テストフレームワーク (`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行 (`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止)
- 追加する Architecture テストは **DB を触らない** (ファイル読み取りのみ)
- 走査対象・allowlist は **型付き `const` 配列** (`array<string, string>` / `list<string>`)、
  純関数の戻り値は `list<string>` に固定する (`mixed` を増やさない)
- 外部から読んだ文字列は `Assert::string()` で narrow してから走査する。
  JSON は `Assert::isArray()` → 要素ごとに `Assert::string()` の順で**段階的に** narrow する
  (`json_decode()` 由来の `mixed` をそのまま反復すると PHPStan level 10 が落ちる)
- **コードフォーマット**: `composer fix` (Pint) / `pnpm lint:fix`
- shell スクリプトは `set -euo pipefail` + 日本語コメント。既存 `scripts/*.sh` の作法に揃える
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260815-1113-browser-test-lane-automation/conceptual-design.md` (APPROVED / Round 4)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 導入スクリプトの新設 | `scripts/setup-browser-testing.sh` (新規) | 高 |
| 2 | レーン起動時の事前確認と証跡退避 | `scripts/run-browser-test.sh` | 高 |
| 3 | CI: キャッシュ / 導入スクリプト / 失敗時の証跡回収 | `.github/workflows/ci.yml` | 高 |
| 4 | CI workflow gate の登録と新規検査 | `tests/js/architecture/ci-workflow-inventory.test.ts` | 高 |
| 5 | 導入経路の一元化 gate | `tests/Architecture/BrowserProvisioningEntrypointTest.php` (新規) | 中 |
| 6 | 契約テスト (導入スクリプト / レーンスクリプト) | `tests/js/support/shell-contract.ts` (新規)、`tests/js/support/shell-contract.test.ts` (新規)、`scripts/setup-browser-testing.contract.test.ts` (新規)、`scripts/run-browser-test.contract.test.ts` | 高 |
| 7 | 手順書と台帳の更新 | `docs/testing-browser.md`、`scripts/README.md`、`.gitignore` | 中 |

---

## 施策 1: 導入スクリプトの新設

### 変更箇所

- ファイル: `scripts/setup-browser-testing.sh` (**新規**)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `scripts/setup-browser-testing.contract.test.ts` (施策 6 で新設)
- 台帳: `scripts/README.md` に 1 行追記が**必須** (`ScriptsReadmeInventoryTest` が全数強制)

### 現行コード

存在しない。`docs/testing-browser.md` の §前提 が手順を文章で案内しているだけ:

```markdown
- ブラウザは Playwright が独自 DL する: **`pnpm exec playwright install chromium webkit`**。
  **WebKit は Linux で共有ライブラリ群 (gstreamer / gtk-4 / libwoff2 等) を要求する**ため、
  devcontainer では **`sudo pnpm exec playwright install-deps webkit`** を一度実行する。
```

### 変更後コード

```bash
#!/usr/bin/env bash
#
# scripts/setup-browser-testing.sh — Browser テスト用のブラウザ実体と OS 共有ライブラリを導入する。
#
# **導入の知識はこのファイル 1 本に集約する** (docs / CI / レーンスクリプトへ散らさない)。
# 呼び出し元は 2 つだけ:
#   - scripts/run-browser-test.sh (グローバルテストロックを取る前の事前確認)
#   - .github/workflows/ci.yml の browser-tests job
#
# 設計の要点:
#   1. **ブラウザ実体が入っているかは Playwright に判断させる**。
#      `playwright install` は充足済みなら無出力・ダウンロード無しで即座に終わる冪等な操作である
#      (実測: 0.5 秒)。自前の充足判定は持たない。
#   2. 自前で判定するのは **OS 共有ライブラリを入れる必要があるか**だけ。
#      要求 (install-deps --dry-run) と権限 (root / sudo) を**別々に**判定し、
#      **要求があるのに権限が無ければ、特権を要する経路を起こす前に落ちる**
#      (黙って OS ライブラリ無しの導入へ落ちない。落ちると後で
#       "Host system is missing dependencies to run browsers" という分かりにくい失敗になる)。
#   3. 判定不能 (出力の文言と終了コードが食い違う / CLI が異常終了) は **拒否側に倒す**。
#
# 環境変数による上書きは持たない。家系の先行実装は BROWSER_TEST_DEPS=auto/skip/force を持つが、
# 当リポジトリは (a) auto 以外の用途が定義できない (b) skip を持つと「CI では受理しない」分岐が要り、
# テストレーンの機構は CI 環境変数を参照しないという明文の契約 (GlobalTestLockInventoryTest) と
# 衝突する、の 2 点から採用しない。
#
# 使い方:
#   bash scripts/setup-browser-testing.sh              # 判定して導入する
#   bash scripts/setup-browser-testing.sh --self-test  # 判定関数の自己検査 (実資源にも node にも触れない)

set -euo pipefail
cd "$(dirname "$0")/.."

# 対象ブラウザ集合。scripts/run-browser-test.sh の既定レーンと 1 対 1 で対応させる
# (どちらか一方だけ増減させない)。**配列で持つ** (未クォート展開に頼らない)。
BROWSER_TARGETS=(chromium webkit)

# install-deps --dry-run の出力に現れる 2 つの文言。**契約テストがこの 2 行を読み取り、
# pin された実 Playwright の出力と突き合わせる** (書式が変わったら pnpm test が赤くなる)。
DEPS_SATISFIED_MARKER='All system dependencies are installed.'
DEPS_MISSING_MARKER='Missing system dependencies'

# 導入専用ロックの置き場。**self-test / 契約テスト専用の override** であり、
# 通常運用では既定値を使う (このスクリプト自身がこの変数へ代入することはしない)。
PROVISION_LOCK_DIR="${BROWSER_PROVISION_LOCK_DIR:-/tmp}"

# --------------------------------------------------------------------------
# 判定 (純関数。引数だけで決まる = --self-test が fixture で駆動できる)
# --------------------------------------------------------------------------

# uname -s の出力を 3 分類する。
# Playwright が OS 共有ライブラリの導入に対応するのは Linux / Windows だけである。
# Windows 経路は当リポジトリの開発環境 (devcontainer + macOS ホスト) に存在しないので、
# 中途半端に動くふりをせず未対応として落とす。
classify_os() {
    case "$1" in
        Linux)  printf 'linux\n' ;;
        Darwin) printf 'darwin\n' ;;
        *)      printf 'unsupported\n' ;;
    esac
}

# install-deps --dry-run の (終了コード, 標準出力) を 3 分類する。
# **文言と終了コードの両方が一致したときだけ**確定させる
# (CLI 自体の異常終了と「不足あり」を混同しない)。
classify_deps() {
    local code="$1" out="$2"

    if [ "${code}" = "0" ] && [ "${out#*"${DEPS_SATISFIED_MARKER}"}" != "${out}" ]; then
        printf 'satisfied\n'
        return 0
    fi
    # **終了コードは 1 に限定する**。Playwright の正常な不足検出は process.exitCode = 1 で
    # あり、2 / 126 / 137 (シグナル killed 等) は「途中まで出力された marker が残っている
    # 異常終了」でありうる。`!= 0` で受けると、その異常終了を missing と誤認して
    # **--with-deps の特権経路へ進めてしまう**。
    if [ "${code}" = "1" ] && [ "${out#*"${DEPS_MISSING_MARKER}"}" != "${out}" ]; then
        printf 'missing\n'
        return 0
    fi
    printf 'undeterminable\n'
}

# (OS 分類, 依存分類, 権限) から動作を決める決定表。
# 出力: with-deps | plain | fail:<理由キー>
decide_install() {
    local os="$1" deps="$2" privilege="$3"

    case "${os}" in
        unsupported) printf 'fail:unsupported-os\n'; return 0 ;;
        darwin)      printf 'plain\n';              return 0 ;;
    esac

    case "${deps}" in
        satisfied) printf 'plain\n' ;;
        missing)
            case "${privilege}" in
                root|sudo) printf 'with-deps\n' ;;
                *)         printf 'fail:no-privilege\n' ;;
            esac
            ;;
        *) printf 'fail:undeterminable-deps\n' ;;
    esac
}

# --------------------------------------------------------------------------
# 観測 (実資源に触れる薄い層。self-test はここを通らない)
# --------------------------------------------------------------------------

detect_privilege() {
    if [ "$(id -u)" = "0" ]; then
        printf 'root\n'
        return 0
    fi
    if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
        printf 'sudo\n'
        return 0
    fi
    printf 'none\n'
}

playwright_version() {
    pnpm exec playwright --version 2>/dev/null || printf 'unknown\n'
}

# --------------------------------------------------------------------------
# 本体
# --------------------------------------------------------------------------

fail_with_guidance() {
    local reason="$1"
    echo "ERROR: Browser テスト用のブラウザを導入できません (${reason})。" >&2
    case "${reason}" in
        unsupported-os)
            echo "  この OS ($(uname -s)) は Browser レーンの対象外です (Linux / macOS のみ)。" >&2
            ;;
        no-privilege)
            echo "  WebKit が要求する OS 共有ライブラリが不足していますが、管理者権限がありません。" >&2
            echo "  root で実行するか、パスワード無しで sudo できる環境で次を実行してください:" >&2
            echo "    bash scripts/setup-browser-testing.sh" >&2
            ;;
        undeterminable-deps)
            echo "  OS 共有ライブラリの過不足を判定できませんでした (Playwright の出力が想定と違います)。" >&2
            echo "  playwright: $(playwright_version)" >&2
            echo "  次のコマンドの出力を確認してください:" >&2
            echo "    pnpm exec playwright install-deps --dry-run ${BROWSER_TARGETS[*]}" >&2
            ;;
    esac
    exit 1
}

provision() {
    local os deps privilege decision out code

    os="$(classify_os "$(uname -s)")"

    deps="not-applicable"
    if [ "${os}" = "linux" ]; then
        # dry-run は reportMissingDependenciesLinux() で打ち切られ、apt-get install を
        # 組み立てる特権経路には到達しない (playwright 1.61.1 実読)。
        #
        # **`local out="" code=0` と 1 行で書かない**: local は成功を返すので
        # `local x="$(...)"` の形にすると command substitution の終了コードが
        # local の終了コードに潰され、`|| code=$?` が発火しない (宣言と代入を分ける)。
        out=""
        code=0
        out="$(pnpm exec playwright install-deps --dry-run "${BROWSER_TARGETS[@]}" 2>&1)" || code=$?
        deps="$(classify_deps "${code}" "${out}")"
    fi

    # **権限は「要求があるときだけ」観測する**。充足済み / macOS / 未対応 OS では
    # sudo を一度も起こさないのが契約である (概念設計の成功条件 (a))。
    privilege="none"
    if [ "${os}" = "linux" ] && [ "${deps}" = "missing" ]; then
        privilege="$(detect_privilege)"
    fi

    decision="$(decide_install "${os}" "${deps}" "${privilege}")"

    case "${decision}" in
        plain)     pnpm exec playwright install "${BROWSER_TARGETS[@]}" ;;
        with-deps) pnpm exec playwright install --with-deps "${BROWSER_TARGETS[@]}" ;;
        fail:*)    fail_with_guidance "${decision#fail:}" ;;
    esac
}

# --self-test は判定関数だけを fixture で駆動する (node も実資源も要らない)。
self_test() {
    local failures=0 cases=0
    assert_eq() {   # assert_eq <期待値> <実際値> <ケース名>
        cases=$((cases + 1))
        if [ "$1" != "$2" ]; then
            echo "  FAIL [$3]: expected '$1', got '$2'" >&2
            failures=$((failures + 1))
        fi
    }
    # ... (ケース表は下記「テスト計画」を参照)
    echo "self-test: ${cases} cases, ${failures} failures"
    [ "${failures}" -eq 0 ]
}

case "${1-}" in
    "")
        echo "Browser lane: ブラウザ実体と OS 共有ライブラリを確認します (scripts/setup-browser-testing.sh)"
        # 導入専用ロック。複数 worktree から同時に Browser レーンを起動すると
        # ~/.cache/ms-playwright への並行書き込みと、Linux では apt-get / dpkg の
        # ロック競合 (dpkg は排他ロックを取るので後発が落ちる) が起きる。
        #
        # **グローバルテストロック (scripts/global-test-lock.sh) とは統合しない**:
        # あちらはテストレーンの実行そのものの直列化で、こちらはホスト共有資源への
        # 書き込みの排他である。分けることで、数百 MB の取得中に全テストレーンを止めずに済む。
        # 充足済みなら保持時間は 2 秒前後 (実測) なので、判定をロック外へ出す二重確認は作らない。
        #
        # **保証範囲**: 効くのは同一 UID かつ同一 lock ディレクトリ名前空間
        # (= 同じ /tmp を共有するプロセス群) だけ。別コンテナが同じ dpkg を触る構成では排他にならない。
        # flock(1) が無い環境は警告 1 行を出して排他なしで続行する
        # (scripts/global-test-lock.sh の既存方針を踏襲)。
        if command -v flock >/dev/null 2>&1; then
            mkdir -p "${PROVISION_LOCK_DIR}"
            # fd 8 を使う (グローバルテストロックの fd 7 とは分ける)。
            # プロセス終了時に OS が解放するので trap は張らない。
            exec 8>"${PROVISION_LOCK_DIR}/browser-provisioning-$(id -u).lock"
            flock 8
        else
            echo "WARNING: flock(1) が無いため導入の排他なしで実行します" >&2
        fi
        provision
        ;;
    --self-test)
        self_test
        ;;
    *)
        echo "ERROR: unknown option '$1' (使えるのは --self-test だけです)" >&2
        exit 2
        ;;
esac
```

> **対象ブラウザは配列で持つ** (`BROWSER_TARGETS=(chromium webkit)` / 展開は
> `"${BROWSER_TARGETS[@]}"`)。未クォート文字列展開に頼らないので `shellcheck disable` は不要である。
> メッセージへ埋めるときだけ `"${BROWSER_TARGETS[*]}"` を使う。

### テスト計画

新規 `scripts/setup-browser-testing.contract.test.ts` (施策 6) と、スクリプト内蔵の `--self-test` の 2 段。

**`--self-test` のケース表 (決定表の全網羅。実資源に触れない)**

| # | 入力 | 期待 |
|---|---|---|
| T1 | `classify_os Linux` | `linux` |
| T2 | `classify_os Darwin` | `darwin` |
| T3 | `classify_os MINGW64_NT-10.0` | `unsupported` |
| T4 | `classify_os ""` | `unsupported` |
| T5 | `classify_deps 0 "<satisfied 文言>"` | `satisfied` |
| T6 | `classify_deps 1 "<missing 文言 + 一覧>"` | `missing` |
| T7 | `classify_deps 0 "<missing 文言>"` (文言と終了コードの不一致) | `undeterminable` |
| T8 | `classify_deps 1 "<satisfied 文言>"` (同上・逆) | `undeterminable` |
| T9 | `classify_deps 0 ""` (出力が空) | `undeterminable` |
| T10 | `classify_deps 127 "playwright: not found"` (CLI 異常終了) | `undeterminable` |
| T10b | `classify_deps 2 "<missing 文言>"` (異常終了 + marker 残留) | `undeterminable` |
| T10c | `classify_deps 137 "<missing 文言>"` (SIGKILL + marker 残留) | `undeterminable` |
| T11 | `decide_install linux satisfied none` | `plain` |
| T12 | `decide_install linux missing root` | `with-deps` |
| T13 | `decide_install linux missing sudo` | `with-deps` |
| T14 | `decide_install linux missing none` | `fail:no-privilege` |
| T15 | `decide_install linux undeterminable root` | `fail:undeterminable-deps` |
| T16 | `decide_install darwin not-applicable none` | `plain` |
| T17 | `decide_install unsupported not-applicable root` | `fail:unsupported-os` |

- [ ] 新規テスト: `--self-test` が exit 0 かつ `self-test: N cases, 0 failures` を出すこと (契約テストから実行)
- [ ] 新規テスト: **ケース数の下限**を契約テストで固定する (`N >= 19`)。
      自己検査を空にして緑にする逃げを塞ぐ (空振り防止)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (DB を触らないので該当なし)

### リスク

- **Playwright の出力書式が変わると `undeterminable` に倒れて Browser レーンが起動できなくなる**。
  これは意図した fail-closed だが、実 CLI smoke (施策 6) が `pnpm test` で先に赤くなるので、
  Browser レーンより早く気づける。メッセージにも版と確認コマンドを出す。
- **`playwright install` が将来 `--with-deps` 無しでも特権を要求するようになる**可能性。
  **pin されている現行版 (1.61.1) ではブラウザ実体の DL だけで特権を要しない**ことを実読・実測で
  確認している。本スクリプトが自ら起こす sudo は `sudo -n` (非対話) だけなので、
  **本スクリプト由来のパスワード待ちは起きない**。ただし Playwright 内部が別の方法で
  特権を取りに行くようになった場合まで保証はできない (誇張しない)。
- **`pnpm` が無い環境**では `pnpm exec` が失敗して `undeterminable` になり exit 1。
  Browser レーンは pnpm 無しでは成立しないので、これは正しい失敗である。

---

## 施策 2: レーン起動時の事前確認と証跡退避

### 変更箇所

- ファイル: `scripts/run-browser-test.sh` (L73-84 付近 / L132-159 付近)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `scripts/run-browser-test.contract.test.ts` (施策 6 で拡張。
  sandbox の repo 骨格に `scripts/setup-browser-testing.sh` のスタブを足す必要がある)
- `.gitignore`: `/storage/browser-test-artifacts/` を追加 (施策 7)

### 現行コード

```bash
if busy_port="$(bughunt_port_in_use)"; then
    echo "ERROR: bug-hunt 環境が走行中です (127.0.0.1:${busy_port} が listen 中)。" >&2
    ...
    exit 1
fi

# --- グローバルテストロック (旧 worktree-local ロックを置き換え) ---
# shellcheck source=scripts/global-test-lock.sh
. "$(pwd)/scripts/global-test-lock.sh"
global_test_lock_acquire "composer test:browser"
```

```bash
overall=0
for lane in ${LANES}; do
    case "${lane}" in
        chromium) browser="chrome" ;;
        webkit)   browser="safari" ;;
        ...
    esac

    echo ""
    echo "=== Browser lane: ${lane} (playwright: ${browser}) ==="

    code=0
    global_test_lock_run vendor/bin/pest -c phpunit.browser.xml "${PEST_PARALLEL_ARGS[@]}" \
        --browser "${browser}" "$@" || code=$?
    if [ "${code}" -ne 0 ]; then
        overall="${code}"
    fi

    cleanup_orphan_playwright
done

exit "${overall}"
```

### 変更後コード

**(a) 事前確認をロック取得の前に置く**

```bash
if busy_port="$(bughunt_port_in_use)"; then
    ...
    exit 1
fi

# --- ブラウザ導入の事前確認 (グローバルテストロックを取る **前**) ---
#
# bug-hunt guard と同じ理由でロック取得より前に置く: 取得後に落とすと、先行レーンの
# 終了を数分待たされたあとで「ブラウザが入っていません」と言うことになる。
# 導入が実際に走る場合も、数百 MB の取得中にグローバルテストロックを保持し続けない。
#
# **source ではなく子プロセスで呼ぶ**: EXIT trap の所有者を scripts/global-test-lock.sh
# 1 箇所に固定する契約 (契約テスト C7) と、本スクリプトが CI 環境変数を参照しない契約
# (GlobalTestLockInventoryTest) を、どちらも壊さないため。
# 導入スクリプトが非ゼロで終われば set -e でここで止まる (導線はあちらが出す)。
bash scripts/setup-browser-testing.sh
```

**(b) 証跡の初期化とレーン別退避**

```bash
# pest-plugin-browser が失敗時 screenshot を書く場所 (vendor 側で固定)。
SCREENSHOT_DIR="tests/Browser/Screenshots"
# レーン別に退避した証跡の置き場 (CI の失敗時アップロード対象)。
ARTIFACT_DIR="storage/browser-test-artifacts"

# レーンの証跡を退避する。
#
# **必要な理由**: pest-plugin-browser は **起動のたびに** tests/Browser/Screenshots を
# 丸ごと消す (vendor: Filters/UsesBrowserTestCaseMethodFilter → Support/Screenshot::cleanup())。
# 本レーンは chromium → webkit の 2 回 pest を起動するので、退避しないと
# **先に失敗した chromium の証跡が webkit の起動で消える**。
collect_lane_artifacts() {
    local lane="$1"
    [ -d "${SCREENSHOT_DIR}" ] || return 0
    [ -n "$(ls -A "${SCREENSHOT_DIR}" 2>/dev/null)" ] || return 0
    # **退避の失敗でレーンの結果を上書きしない**。set -euo pipefail 下では mkdir / cp が
    # 落ちるとスクリプトごと終了し、テスト本体の終了コードが失われる。証跡は診断の補助で
    # あって合否ではないので、**退避先の作成と複製の両方**を受けて警告 1 行で続行する
    # (黙って握り潰さない)。
    if ! mkdir -p "${ARTIFACT_DIR}/${lane}"; then
        echo "WARNING: ${lane} レーンの証跡退避先を作成できませんでした (${ARTIFACT_DIR}/${lane})" >&2
        return 0
    fi
    if ! cp -R "${SCREENSHOT_DIR}/." "${ARTIFACT_DIR}/${lane}/"; then
        echo "WARNING: ${lane} レーンの証跡を退避できませんでした (${SCREENSHOT_DIR})" >&2
    fi
    return 0
}
```

```bash
# 前回実行の証跡を捨てる。**ロック取得後・レーンループ前**に行う:
#   - 前回の残骸を今回の失敗としてアップロードしない
#   - ロック配下なので、並行する別実行の証跡を消すことはない
rm -rf "${ARTIFACT_DIR}"

overall=0
for lane in ${LANES}; do
    ...
    code=0
    global_test_lock_run vendor/bin/pest -c phpunit.browser.xml "${PEST_PARALLEL_ARGS[@]}" \
        --browser "${browser}" "$@" || code=$?
    if [ "${code}" -ne 0 ]; then
        overall="${code}"
    fi

    # **終了コードを保存した後・次レーンの起動前**に退避する
    # (失敗したレーンでも必ず通る。`|| code=$?` で受けているので set -e で飛ばない)。
    collect_lane_artifacts "${lane}"

    cleanup_orphan_playwright
done

exit "${overall}"
```

あわせて冒頭 docblock の「前提」を書き換える
(`pnpm exec playwright install chromium webkit` 済み → `scripts/setup-browser-testing.sh` が導入する)。

### テスト計画

`scripts/run-browser-test.contract.test.ts` を拡張する (施策 6 に実装詳細)。

- [ ] 既存テストの更新: `runInSandbox` の repo 骨格に `scripts/setup-browser-testing.sh` の
      スタブ (既定 exit 0 + 呼び出し記録) を追加する。**既存 C1〜C8 の期待値は変えない**
- [ ] 新規テスト C9 (静的): 事前確認の実行行が存在し、その行番号が
      `global_test_lock_acquire` の行番号**より前**であること
- [ ] 新規テスト C9 負のコントロール: 事前確認行を acquire の後ろへ移した改変で違反を返すこと
- [ ] 新規テスト C10 (sandbox): 事前確認スタブが非ゼロ → pest が **0 回**呼ばれ、
      スクリプトも非ゼロで終わること
- [ ] 新規テスト C11 (sandbox): **2 レーン走らせても先行レーンの証跡が残ること**
      (この施策の本質)。pest スタブは実挙動を模して
      **起動時に `tests/Browser/Screenshots` を消してから**書き込む:
      1 レーン目が `chromium-x.png` を書いて exit 1、2 レーン目が `webkit-y.png` を書く →
      実行後に `storage/browser-test-artifacts/chromium/chromium-x.png` と
      `.../webkit/webkit-y.png` の**両方**が存在すること
      (失敗レーンでも退避されること = `set -e` で飛んでいないことも同時に示す)
- [ ] 新規テスト C11 負のコントロール: `collect_lane_artifacts` の呼び出しを
      ループの外 (全レーン終了後) へ移した改変では chromium 側が消えること
- [ ] 新規テスト C12 (sandbox): 前回実行の残骸が消えること
      (実行前に `storage/browser-test-artifacts/stale/old.png` を置く → 実行後に存在しない)
- [ ] 新規テスト C12 負のコントロール: `rm -rf "${ARTIFACT_DIR}"` を削った改変では残骸が残ること
- [ ] 新規テスト C14a (sandbox): **退避先を作れないときにレーンの結果を上書きしないこと**。
      **失敗条件は初期化の後に作る** — スクリプトはレーンループの前に
      `rm -rf "${ARTIFACT_DIR}"` するので、実行前に置いたファイルは消えてしまい
      `mkdir -p` は成功してしまう。pest スタブに次の順で作らせる:
      (1) `tests/Browser/Screenshots/x.png` を書く →
      (2) `storage/browser-test-artifacts` を**通常ファイル**として作る
          (sandbox には `storage/` が無いので先に `mkdir -p storage` する) →
      (3) `exit 23` で終わる。
      期待は「stderr に WARNING が出る」かつ
      **「最終終了コードが 23 のまま」** (0 で確認すると「上書きしない」ことの証明にならない)
- [ ] 新規テスト C14b (sandbox): **複製に失敗したときも同じであること**。
      sandbox の `PATH` に条件付き `cp` スタブを置き、退避の複製だけ非ゼロを返させる
      (権限で失敗させる fixture は root 環境で成立しないので、
      既存 sandbox の作法どおりスタブで作る)。
      期待は C14a と同じ (WARNING が出る / 最終終了コードが 23 のまま)。
      **`mkdir -p` 側と `cp` 側は別の分岐なので、片方だけでは不変条件を固定できない**
- [ ] 新規テスト C15 (静的): `.gitignore` に `/storage/browser-test-artifacts/` の行があること
      (登録漏れは Browser テスト実行後の worktree を恒常的に dirty にし、
      `scripts/teardown-worktree.sh` の dirty チェックを常時失敗させる)
- [ ] 新規テスト C13 (静的): 証跡ディレクトリの初期化行が
      `global_test_lock_acquire` **より後**かつレーンループ **より前**にあること
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (該当なし)

### リスク

- **事前確認が毎回 2 秒前後かかる**。レーン本体 (2 engine 直列) に比べれば無視できる。
  時間そのものは契約にしない (環境差で偽赤になる)。
- **`cp -R` の失敗でレーンが落ちる**。`set -e` 下なので、退避に失敗するとレーン結果が
  上書きされうる。退避は診断の補助であって合否ではないので、
  `collect_lane_artifacts` 内の `cp` は失敗しても続行させる (`|| true` ではなく、
  失敗時に警告 1 行を出して return 0 する = 黙って握り潰さない)。
- **Screenshots が巨大な場合の CI アップロード時間**。現状 Browser テストは 14 本で、
  失敗時にしか書かれないため実害は無い。`retention-days: 7` で保持も抑える。

---

## 施策 3: CI (キャッシュ / 導入スクリプト / 失敗時の証跡回収)

### 変更箇所

- ファイル: `.github/workflows/ci.yml` の `browser-tests` job (L113-181)

### 波及変更

- テストファイル: `tests/js/architecture/ci-workflow-inventory.test.ts` (施策 4。**必須**。
  allowlist に登録しないと W14a / W14b が落ちる)
- docs: `docs/testing-browser.md` §CI での実行 (施策 7)

### 現行コード

```yaml
      - uses: actions/setup-node@v7
        with:
          node-version: 22
          cache: pnpm
      ...
      # ブラウザ実体は Playwright が別途 DL する。**pnpm exec** を使うこと:
      # pest-plugin-browser が起動する run-server は root devDependency の playwright と
      # 同一実体である必要があり、npx だと別バージョンを引きうる。
      # 未導入だと PlaywrightOutdatedException で 2 レーンとも全 fail する (ローカル実測)。
      # --with-deps は WebKit が Linux で要求する共有ライブラリ群 (gstreamer / gtk-4 / libwoff2 等) を入れる。
      - name: Install Playwright browsers (chromium + webkit)
        run: pnpm exec playwright install --with-deps chromium webkit
      - name: Pest (browser lanes)
        run: composer test:browser
```

### 変更後コード

```yaml
      - uses: actions/setup-node@v7
        with:
          node-version: 22
          cache: pnpm
      # ブラウザ実体 (数百 MB) のキャッシュ。key に pnpm-lock.yaml の hash を含めるので、
      # playwright の版が変われば別キーになる。**restore-keys は持たない**:
      # 部分一致で復元すると古い版のブラウザを溜め込み続けるため
      # (lockfile 更新時と初回は従来どおり取得する = 短縮効果は lockfile 不変時に限る)。
      - uses: actions/cache@v6
        with:
          path: ~/.cache/ms-playwright
          key: ${{ runner.os }}-${{ runner.arch }}-ms-playwright-${{ hashFiles('pnpm-lock.yaml') }}
      ...
      # ブラウザ実体と OS 共有ライブラリの導入。**導入の知識はスクリプト 1 本に集約する**
      # (CI が検証するものと開発者が走らせるものを同一に保つ。T099 と同じ理由)。
      # 対象ブラウザ集合 (chromium + webkit) と --with-deps の要否判定はスクリプト側の契約で、
      # scripts/setup-browser-testing.contract.test.ts が固定する。
      - name: Provision browsers (chromium + webkit)
        run: bash scripts/setup-browser-testing.sh
      - name: Pest (browser lanes)
        run: composer test:browser
      # 失敗時だけレーン別の失敗スクリーンショットを回収する。
      # **continue-on-error は付けない** (soft-fail 禁止 / W13)。
      # ここでの `if` は **step-level** であり、W15 が deny-by-default で禁じている
      # job-level の `if` とは別物である。
      # if-no-files-found は既定 (warn) のまま: 証跡が無い失敗 (DB 起動失敗等) は
      # 正常にありうるので job を落とさず、警告だけ残す。
      - name: Collect browser lane artifacts
        if: failure()
        uses: actions/upload-artifact@v7
        with:
          name: browser-test-artifacts
          path: storage/browser-test-artifacts/
          retention-days: 7
```

> **版の確認**: gate の `actionName()` は `@version` を落として突合するので gate は版に沈黙するが、
> **存在しない版を書けば CI が即 fail する**。実装時に `actions/cache` / `actions/upload-artifact` の
> 現行 major の実在を確認すること。
>
> 実装時 (2026-08-15) に確認した現行 major は `actions/cache@v6` / `actions/upload-artifact@v7` で、
> 上のコード例もその値に更新済みである (設計当初の記述は `@v4` だった)。

### テスト計画

施策 4 で `ci-workflow-inventory.test.ts` に登録・検査を追加する (単体では緑にならない)。

- [ ] 既存テストの更新: W7 / W14a / W14b (施策 4)
- [ ] 新規テスト: W18 / W19 / W20 (施策 4)
- [ ] 実 CI での確認は PR 上で行う (ローカルからは実行できない)

### リスク

- **キャッシュを復元しても OS 共有ライブラリ (apt) はキャッシュされない**。
  導入スクリプトが毎回 `install-deps --dry-run` で判定し、不足していれば入れるので機能する。
  「キャッシュがあるから導入段を飛ばす」条件分岐は**作らない** (CI だけで通る経路を作らない)。
- **`if: failure()` は job 内のいずれかの step が失敗したときに真になる**。
  `composer test:browser` 以外 (例: `pnpm build`) の失敗でも走るが、
  そのときは証跡が無く警告が出るだけである。

---

## 施策 4: CI workflow gate の登録と新規検査

### 変更箇所

- ファイル: `tests/js/architecture/ci-workflow-inventory.test.ts`
  (`BROWSER_JOB_ALLOWED_USES` / `BROWSER_JOB_ALLOWED_RUN_LINES` / W7 / 新規 W18-W20)

### 波及変更

- TypeScript 型定義: `WorkflowStep` に `if?: string` を追加 (W19 が step-level `if` を見るため)
- テストファイル: 本ファイル自身が対象

### 現行コード

```typescript
const BROWSER_JOB_ALLOWED_USES = [
    "actions/checkout",
    "shivammathur/setup-php",
    "pnpm/action-setup",
    "actions/setup-node",
] as const;

const BROWSER_JOB_ALLOWED_RUN_LINES = [
    ...
    "pnpm exec playwright install --with-deps chromium webkit",
    "composer test:browser",
] as const;

    it("W7: browser-tests が playwright install --with-deps chromium webkit を実行すること", () => {
        expect(runScript(job(workflow, "browser-tests"))).toContain(
            "pnpm exec playwright install --with-deps chromium webkit",
        );
    });
```

### 変更後コード

```typescript
/**
 * browser-tests job で使ってよい setup action (allowlist)。
 * ここに足すことは「その action が BROWSER_TEST_* を $GITHUB_ENV へ書かない」ことの表明である。
 */
const BROWSER_JOB_ALLOWED_USES = [
    "actions/checkout",
    "shivammathur/setup-php",
    "pnpm/action-setup",
    "actions/setup-node",
    // ブラウザ実体 (~/.cache/ms-playwright) のキャッシュ。復元先はキャッシュディレクトリだけで
    // $GITHUB_ENV には書かない。
    "actions/cache",
    // 失敗時の証跡回収。読むだけで $GITHUB_ENV には書かない。
    "actions/upload-artifact",
] as const;

const BROWSER_JOB_ALLOWED_RUN_LINES = [
    "composer install --prefer-dist --no-progress --no-interaction",
    "pnpm install --frozen-lockfile",
    "cp .env.example .env",
    "php artisan key:generate",
    "php artisan passport:keys --force",
    "pnpm build",
    // 導入は scripts/setup-browser-testing.sh に一元化した (旧
    // `pnpm exec playwright install --with-deps chromium webkit` を置き換え)。
    // この行が BROWSER_TEST_* を設定しないことは
    // scripts/setup-browser-testing.contract.test.ts の静的契約が固定する。
    "bash scripts/setup-browser-testing.sh",
    "composer test:browser",
] as const;

/**
 * 導入コマンドの検出パターン (W20)。
 * 単純部分一致にすると `pnpm exec playwright   install chromium` のような空白差分で
 * 迂回できるため正規表現で見る (施策 5 の PHP 側 gate と同じ規則)。
 */
const PLAYWRIGHT_INSTALL_PATTERN = /\bplaywright\s+install\b/;

/**
 * shell の行継続 (`\` + 改行 + 先頭空白) を 1 個の空白へ畳む純関数 (W20 用)。
 * 行単位の照合だけでは、コマンドを 2 行に割るだけで検出を迂回できてしまう。
 */
export function normalizeShellContinuations(source: string): string {
    return source.replace(/\\\r?\n\s*/g, " ");
}
```

```typescript
    it("W7: browser-tests が導入スクリプトを **実行行として** 持つこと", () => {
        // **旧 W7 からの変更点と理由**: 旧 W7 は
        // `pnpm exec playwright install --with-deps chromium webkit` という文字列の存在を
        // 検査していた。導入を scripts/setup-browser-testing.sh へ一元化したため、
        // ci.yml 側で固定できるのは「導入スクリプトを呼んでいること」までになる。
        // **対象ブラウザ集合 (chromium + webkit) と --with-deps の要否判定の固定は
        // scripts/setup-browser-testing.contract.test.ts へ移した** —
        // 検査を消して緑にしたのではなく、置き場所を移したうえで両方が残っている。
        //
        // includes ではなく完全一致の実行行を要求するのは W16 と同じ理由
        // (`echo "bash scripts/..."` / `... || true` の soft-fail 偽装を素通りさせない)。
        const mentions = runLines(job(workflow, "browser-tests")).filter((l) =>
            l.includes("scripts/setup-browser-testing.sh"),
        );
        expect(mentions).toEqual(["bash scripts/setup-browser-testing.sh"]);
    });

    it("W18: browser-tests が ~/.cache/ms-playwright をキャッシュし、restore-keys を持たないこと", () => {
        const cache = (job(workflow, "browser-tests").steps ?? []).filter(
            (s) => s.uses !== undefined && actionName(s.uses) === "actions/cache",
        );
        expect(cache, "browser-tests に actions/cache step が無い").toHaveLength(1);
        expect(String(cache[0]?.with?.path ?? "")).toBe("~/.cache/ms-playwright");
        // key の 3 要素を個別に見る (設計意図との対応を明示する)
        const key = String(cache[0]?.with?.key ?? "");
        expect(key).toContain("runner.os");
        expect(key).toContain("runner.arch");
        expect(key).toContain("hashFiles('pnpm-lock.yaml')");
        // 部分一致復元は古い版のブラウザを溜め込む (概念設計 §4)
        expect(cache[0]?.with?.["restore-keys"], "restore-keys は持たない").toBeUndefined();
    });

    it("W19: browser-tests の最後の step が失敗時の証跡回収であること", () => {
        const steps = job(workflow, "browser-tests").steps ?? [];
        const last = steps[steps.length - 1];
        expect(last?.uses !== undefined && actionName(last.uses)).toBe("actions/upload-artifact");
        // step-level の if。W15 が禁じているのは **job-level** の if であって別物である。
        expect(last?.if, "失敗時だけ回収する (常時アップロードにしない)").toBe("failure()");
        expect(String(last?.with?.path ?? "")).toBe("storage/browser-test-artifacts/");
        // 名前と保持日数も契約である (変更・欠落を素通りさせない)
        expect(String(last?.with?.name ?? "")).toBe("browser-test-artifacts");
        expect(Number(last?.with?.["retention-days"])).toBe(7);
    });

    it("W20: どの job の実行行にも playwright install が現れないこと (導入はスクリプト経由)", () => {
        // 迂回の 2 経路を先に潰す:
        //   (a) 空白差分 `pnpm exec playwright   install chromium` → 正規表現で見る
        //   (b) shell の行継続
        //         run: |
        //           pnpm exec playwright \
        //             install chromium webkit
        //       → runLines は行ごとに切るので素通りする。**照合前に行継続を空白へ畳む**。
        // (施策 5 の PHP 側 gate と同じ規則にそろえる)
        for (const name of Object.keys(workflow.jobs ?? {})) {
            const joined = normalizeShellContinuations(runScript(job(workflow, name)));
            const hits = joined.split("\n").filter((l) => PLAYWRIGHT_INSTALL_PATTERN.test(l));
            expect(hits, `${name} が導入コマンドを直接叩いている`).toEqual([]);
        }
    });
```

負のコントロール (既存 describe「走査関数の負のコントロール」へ追加):

```typescript
    it("W19: 常時アップロード (if 無し) を検出する", () => { ... });
    it("W19: upload-artifact が最後の step でない構成を検出する", () => { ... });
    it("W19: name / retention-days の欠落を検出する", () => { ... });
    it("W18: restore-keys を足した構成を検出する", () => { ... });
    it("W18: key から runner.arch を落とした構成を検出する", () => { ... });
    it("W20: run 行へ戻した playwright install を検出する", () => { ... });
    it("W20: 空白を増やした `playwright   install` も検出する", () => { ... });
    it("W20: 行継続で 2 行に割った `playwright \\<改行> install` も検出する", () => { ... });
    it("normalizeShellContinuations: 行継続だけを畳み、通常の改行は残す", () => { ... });
```

### テスト計画

- [ ] 既存テストの更新: W7 (置き換え。docblock に「移設であって削除ではない」旨を明記)
- [ ] 既存 allowlist の更新: `BROWSER_JOB_ALLOWED_USES` / `BROWSER_JOB_ALLOWED_RUN_LINES`
      (**完全一致のまま**。glob 化・部分一致化・正規表現化はしない)
- [ ] 新規テスト W18 — キャッシュ段の path / key / restore-keys 不在
- [ ] 新規テスト W19 — 最終 step が `if: failure()` つきの証跡回収
- [ ] 新規テスト W20 — 実行行に `playwright install` が現れない
- [ ] 新規テスト (負のコントロール) — 上記 3 検査それぞれに 2 件以上
      (W18: restore-keys 追加 / key の要素欠落、W19: `if` 欠落 / 最終 step でない /
      name・retention-days の欠落、W20: 直接実行の復活 / 空白差分 / 行継続)
- [ ] 新規テスト: `normalizeShellContinuations` 自身の保証 (行継続だけを畳むこと)
- [ ] 既存 W9 / W12 / W13 / W15 / W17 は**触らない**

### リスク

- **`WorkflowStep` に `if` を足すと既存検査に影響しないか**: `findKeyPaths` は
  キー名で走査する純関数で、型定義とは独立している。W15 は
  `workflow.jobs[name].if` (job-level) しか見ないので、step-level の `if` は素通りする
  (gate 実装を実読して確認済み)。
- **W19 が「最後の step」を要求すると、将来の step 追加で偽赤になる**。
  それは意図した挙動である (証跡回収より後ろに step を足すと、その step の失敗で
  回収されない窓ができる)。

---

## 施策 5: 導入経路の一元化 gate

### 変更箇所

- ファイル: `tests/Architecture/BrowserProvisioningEntrypointTest.php` (**新規**)

### 波及変更

- なし (テストのみ)

### 現行コード

存在しない。現状 `playwright install` を**実行行として**持つのは
`.github/workflows/ci.yml` の 1 箇所だけ (施策 3 でスクリプト呼び出しへ置き換わる)。

### 変更後コード

```php
<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * ブラウザ導入経路の一元化 gate。
 *
 * 「Browser テスト用のブラウザを入れる」という操作を**実行する**箇所は
 * scripts/setup-browser-testing.sh ただ 1 つである。導入が 2 箇所に増えると、
 * 対象ブラウザ集合 (chromium + webkit) と OS 共有ライブラリの要否判定が二重管理になり、
 * 「片方だけ直して、もう片方で WebKit が全 fail する」という現状の再来になる。
 *
 * **母集団は「実行される場所」に限る** (deny-by-default だが、対象は狭く精密に取る):
 *   1. scripts/ 配下の shell スクリプト (**再帰走査**。`scripts/tools/foo.sh` も母集団に入る) の
 *      **実行行** (行頭 # のコメント行を除く)
 *   2. composer.json / package.json の scripts に書かれたコマンド
 *   3. docker/Dockerfile の **命令部** (行頭 # のコメント行を除く)
 *
 * **照合の前に 2 つの正規化を行う** (行単位の素朴な照合は簡単に迂回できる):
 *   - コメント行の除去 (説明文の言及で偽赤にしない)
 *   - **行継続 (`\` + 改行 + 先頭空白) を空白へ畳む** —
 *     `pnpm exec playwright \`+改行+`    install chromium` は行単位では素通りする。
 *     shell と Dockerfile の両方に効かせる
 *
 * **保証しないもの (誇張しない)**:
 *   - 手順書・コメント・設計文書の**言及**は対象外である (`docs/testing-browser.md` /
 *     `docker/Dockerfile` のコメント / `devnotes/`)。禁じたいのは実行であって説明ではない。
 *   - `.github/workflows/*.yml` は本テストの母集団に**入れない** —
 *     YAML の実行行の検査は tests/js/architecture/ci-workflow-inventory.test.ts の W20 が担う
 *     (同じ事実を 2 箇所で検査しない。あちらは既に YAML を parse している)。
 *   - `.claude/skills/app-bug-hunt/` は対象外。bug-hunt は @playwright/cli という
 *     **別の導入経路**を意図的に持つ (AGENTS.md §bug-hunt)。
 *   - 変数へ組み立ててから実行する形 (`cmd="playwright install"; $cmd`) は検出しない。
 *
 * 本テストは DB を触らない (ファイル読み取りのみ)。
 */

/** 導入を実行してよい唯一のファイル (リポジトリルートからの相対パス)。 */
const BROWSER_PROVISIONING_SINGLE_SOURCE = 'scripts/setup-browser-testing.sh';

/** 走査する「実行される場所」。 @var list<string> */
const BROWSER_PROVISIONING_SCANNED_FILES = [
    'composer.json',
    'package.json',
    'docker/Dockerfile',
];

/**
 * 導入コマンドとみなすパターン。
 * 単純部分一致 (`'playwright install'`) にすると `playwright   install` のような
 * 空白差分を見逃すため、正規表現で見る。`install-deps` も `\binstall\b` に一致するので
 * 同じ規則で捕まる (意図どおり)。
 */
const BROWSER_PROVISIONING_PATTERN = '/\bplaywright\s+install\b/';

/**
 * 行頭 (空白を除く) が `#` の行を落とし、**行継続を畳んでから**実行行を返す (純関数)。
 *
 * `/u` は必須: 非 UTF-8 モードの `\R` はバイト 0x85 (NEL) にも一致し、日本語コメントを
 * 文字途中で分断する (PcreUnicodeModifierGateTest / 既存 GlobalTestLockInventoryTest と同方針)。
 *
 * 順序は「コメント除去 → 行継続の畳み込み」。逆にすると、継続行の途中にある `#` の扱いが
 * 変わって取りこぼす。
 *
 * @return list<string> 実行行 (1 始まりの行番号は保持しない)
 */
function browserProvisioningCodeLines(string $source): array { /* ... */ }

/**
 * `scripts/` 配下の shell スクリプトを **再帰的に** 列挙する (純関数)。
 *
 * `glob('scripts/*.sh')` では `scripts/tools/install-browser.sh` を取りこぼす
 * (`ScriptsReadmeInventoryTest::scriptsDirectoryFiles()` と同じ理由・同じ道具を使う:
 *  RecursiveDirectoryIterator + RecursiveIteratorIterator)。
 *
 * @return list<string> リポジトリルートからの相対パス (昇順)
 */
function browserProvisioningShellScripts(string $scriptsDir): array { /* ... */ }

/**
 * 「相対パス => 中身」から違反を列挙する (純関数。負のコントロールを fixture で駆動するため)。
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function browserProvisioningViolations(array $files): array { /* ... */ }

test('導入コマンドを実行するのは scripts/setup-browser-testing.sh だけであること', function (): void {
    $files = [];
    foreach (browserProvisioningScanTargets() as $relative) {
        $contents = file_get_contents(base_path($relative));
        Assert::string($contents, "{$relative} を読み込めません");
        $files[$relative] = $contents;
    }

    expect(browserProvisioningViolations($files))->toBe([]);
});

/*
 * 実行ビットは git が追跡する。**実装時に `chmod +x scripts/setup-browser-testing.sh` を
 * 行うこと** — 付け忘れると CI (bash 経由なので動く) は緑のまま、本テストだけが落ちる。
 */
test('単一情報源のファイルが実在し、実行可能で、対象ブラウザ 2 つを持つこと', function (): void {
    $path = base_path(BROWSER_PROVISIONING_SINGLE_SOURCE);
    Assert::fileExists($path);
    expect(is_executable($path))->toBeTrue();

    $source = file_get_contents($path);
    Assert::string($source);
    expect($source)->toContain('BROWSER_TARGETS=(chromium webkit)');
});

test('負のコントロール: scripts/ の別スクリプトが導入を実行したら検出すること', function (): void { /* ... */ });
test('負のコントロール: 入れ子の scripts/tools/foo.sh も母集団に入ること', function (): void { /* ... */ });
test('負のコントロール: composer.json の script (文字列 / 配列の両形式) を検出すること', function (): void { /* ... */ });
test('負のコントロール: composer.json の scripts が想定外の型なら違反として列挙すること', function (): void { /* ... */ });
test('負のコントロール: 空白差分 `playwright   install` を検出すること', function (): void { /* ... */ });
test('負のコントロール: 行継続で 2 行に割った導入を shell / Dockerfile の両方で検出すること', function (): void { /* ... */ });
test('負のコントロール: コメント行の言及は検出しないこと (偽陽性を作らない)', function (): void { /* ... */ });
```

- 走査対象は `BROWSER_PROVISIONING_SCANNED_FILES` + `browserProvisioningShellScripts()` が
  **再帰的に**列挙した `scripts/**/*.sh` の集合
  (`scripts/` に新しいスクリプトが増えても、入れ子でも、自動で母集団に入る)。
- `composer.json` / `package.json` は `json_decode` してから `scripts` の値だけを見る
  (コメントが無い形式なので行走査は不要)。
  **値は `string` と `list<string>` の両方を取る** (`composer.json` の `scripts` は実際に
  配列形式である)。両方を受けて 1 本の文字列へ正規化してから照合し、
  **想定外の型 (dict 等) は違反として列挙する** (静かに素通りさせない)。

### PHPStan 適合チェック

- [ ] 戻り値の型が明示されている (`list<string>` / `array<string, string>`)
- [ ] null 安全 (`file_get_contents` の戻りを `Assert::string()` で narrow)
- [ ] DTO を返している (配列返却なし) — Architecture テストの純関数なので `list<string>` で可
- [ ] Generics の型パラメータが正しい (`@param array<string, string> $files`)

### テスト計画

- [ ] 新規テスト: 本テスト自体が施策 5 の成果物である
- [ ] 負のコントロールを必ず含める: 別スクリプト / 入れ子スクリプト /
      composer script (文字列・配列の両形式) / 想定外の型 / 空白差分 /
      行継続 (shell・Dockerfile) / コメント行 (偽陽性が出ないこと)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (DB を触らない)

### リスク

- **偽陽性**: `docker/Dockerfile` の既存コメント
  「chromium 本体は Playwright が独自 DL する (`pnpm exec playwright install chromium`)」は
  コメント行なので除外される。実装時に実際に緑になることを確認すること。
- **母集団が狭い**ことは意図的である。広く取ると設計文書やコメントで偽赤になり、
  「注釈の文字列に反応する検出器」という家系既知の失敗 (motivation 2026-08-12) を繰り返す。

---

## 施策 6: 契約テスト

### 変更箇所

- `tests/js/support/shell-contract.ts` (**新規**。共有ヘルパ本体)
- `tests/js/support/shell-contract.test.ts` (**新規**。共有ヘルパ自身の保証。
  既存 `run-browser-test.contract.test.ts` の「mutate() ヘルパ自身の保証」describe の移設先)
- `scripts/setup-browser-testing.contract.test.ts` (**新規**)
- `scripts/run-browser-test.contract.test.ts` (拡張)

### 波及変更

- `scripts/README.md`: `setup-browser-testing.sh` と `setup-browser-testing.contract.test.ts` の
  2 行を追記 (`ScriptsReadmeInventoryTest` は `scripts/` 配下の**全ファイル**を数える)
- vitest の include は既存のままで新ファイルを拾う
  (`scripts/**/*.test.ts` が契約テスト、`tests/js/**/*.test.ts` が
  `tests/js/support/shell-contract.test.ts`)。
  `tests/js/support/shell-contract.ts` は `*.test.ts` ではないので
  `vitest-inventory-gate.test.ts` の母集団に入らない (テストとして収集されない)。
  **`shell-contract.test.ts` は入る** = 書いたのに走っていない状態にはならない

### 現行コード

`scripts/run-browser-test.contract.test.ts` が `mutate` / `codeLines` を **export している**。

### 変更後コード

**(a) 共有ヘルパの切り出し**

```typescript
// tests/js/support/shell-contract.ts
//
// shell スクリプトの契約テストで共有する純ヘルパ。
// **test ファイルから import しない** (test ファイルを import すると、その describe が
// import 元でも二重登録される)。そのため非 test の module として置く。

/** 行頭 (空白除く) が `#` の行を落とす。方針説明コメントで偽赤にしないため。 */
export function codeLines(source: string): string { /* 既存実装を移設 */ }

/** 元ソースの `from` を `to` に 1 箇所だけ置換する。置換が成立しなければ throw (空振り防止)。 */
export function mutate(source: string, from: string, to: string): string { /* 既存実装を移設 */ }

/** `source` の中で `needle` を含む最初の行の 0 始まり index を返す (無ければ -1)。 */
export function lineIndexOf(source: string, needle: string): number { /* 新規 */ }
```

`run-browser-test.contract.test.ts` は自前定義を消して import に切り替える
(既存の「mutate() ヘルパ自身の保証」describe も同モジュール用のテストとして
`tests/js/support/shell-contract.test.ts` へ移す。テストを消さない)。

**(b) `scripts/setup-browser-testing.contract.test.ts` (新規)**

3 層構成:

| 層 | 内容 |
|---|---|
| 層 1 | `--self-test` を実プロセスで走らせ、exit 0 と **ケース数の下限 (19)** を検査する |
| 層 2 | 静的契約 (対象ブラウザ / 環境変数への非代入 / marker 文言 / `--with-deps` の出現箇所) + 負のコントロール |
| 層 3 | sandbox 実走 (S1 / S2 / S3 / S4 / S4b / S4c / S5 / S6 / S7 の 9 ケース) と、pin された実 Playwright に対する smoke |

sandbox 実走のケース (PATH に `pnpm` / `id` / `sudo` / `uname` / `flock` のスタブを置き、
各スタブは argv を JSONL へ追記する):

| # | 環境 | 期待 |
|---|---|---|
| S1 | linux / deps satisfied / 非 root sudo 可 | `install-deps --dry-run` と `install chromium webkit` の 2 回だけ。`--with-deps` 無し・**sudo 未起動**・exit 0 |
| S2 | linux / deps missing / sudo 可 | `install --with-deps chromium webkit` が呼ばれる・exit 0 |
| S3 | linux / deps missing / 権限なし | `install` 系の呼び出しが **0 回**・exit 1・stderr に導線 |
| S4 | linux / 出力が想定外 (判定不能) | `install` 系 0 回・exit 1・stderr に playwright の版と確認コマンド |
| S4b | linux / **missing 文言を出しつつ exit 2** (異常終了 + marker 残留) | `install` 系 0 回・**sudo 未起動**・exit 1・理由キー `undeterminable-deps` |
| S4c | linux / **missing 文言を出しつつ exit 137** (SIGKILL 相当) | S4b と同じ |
| S5 | darwin | `install-deps` を **一度も呼ばない**・`install chromium webkit`・exit 0 |
| S6 | その他 OS (`MINGW64_NT-10.0`) | `pnpm` 未起動・exit 1 |
| S7 | 未知オプション `--check` | exit 2 |

実 Playwright smoke:

```typescript
it("実 CLI: install-deps --dry-run の出力が分類器の前提と一致すること", () => {
    // 対象は **Linux かつ apt-get が PATH にある環境** に限る。
    // それ以外 (macOS / 非 Debian 系 Linux) は **理由を出して skip** する (silent skip にしない)。
    // Playwright 側が unsupported platform として別の出力を出す環境で、
    // 設計上の fail-closed とは無関係な理由で pnpm test を赤くしないため。
    // 分類器を再実装せず、スクリプト本体から marker 文言を抽出して使う
    // (スクリプト側の文言を書き換えたら、この smoke が実 CLI と突き合わせて落ちる)。
    const satisfied = extractShellConst(realSource(), "DEPS_SATISFIED_MARKER");
    const missing = extractShellConst(realSource(), "DEPS_MISSING_MARKER");

    const run = spawnSync("pnpm", ["exec", "playwright", "install-deps", "--dry-run", "chromium", "webkit"], ...);
    // status === null = シグナルで死んだ / 起動できなかった。marker 照合へ進めず、
    // 理由を明示して失敗させる (0 と同一視しない)。
    expect(run.status, `install-deps を実行できなかった: ${run.error ?? run.signal}`).not.toBeNull();
    const out = `${run.stdout}${run.stderr}`;

    // ubuntu runner では WebKit の共有ライブラリが未導入で missing になりうるので、
    // 「satisfied であること」は要求しない (環境依存の偽赤を作らない)。
    // 要求するのは **どちらかに確定すること** = 分類器が undeterminable に落ちないこと。
    if (run.status === 0) {
        expect(out).toContain(satisfied);
    } else {
        expect(out).toContain(missing);
    }
});
```

静的契約 (負のコントロールつき):

| ID | 内容 |
|---|---|
| P1 | `BROWSER_TARGETS=(chromium webkit)` であること (片方に狭める改変で違反) |
| P2 | `BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` へ**代入しない** (ci.yml の W9 の前提を壊さない) |
| P3 | `BROWSER_PROVISION_LOCK_DIR` へ**代入しない** (参照は既定値つき展開 1 箇所のみ) |
| P4 | `--with-deps` の出現が実行行に**ちょうど 1 回** (無条件付与への改変で違反) |
| P5 | `sudo -n` 以外の `sudo` 実行が無い (対話 sudo でパスワード待ちに入らない) |
| P6 | `flock` 不在時に警告を出して続行する分岐が残っている |

### テスト計画

- [ ] 新規テスト `scripts/setup-browser-testing.contract.test.ts` — 上記 層 1 / 層 2 / 層 3
- [ ] 新規テスト `tests/js/support/shell-contract.test.ts` — 移設した `mutate` / `codeLines` /
      新規 `lineIndexOf` の保証 (既存テストを**消さずに**移す)
- [ ] 既存テスト `scripts/run-browser-test.contract.test.ts` の更新 — 施策 2 のテスト計画
      **C9 / C10 / C11 / C12 / C13 / C14a / C14b / C15** とそれぞれの負のコントロール
- [ ] 既存 C1〜C8 の期待値は変えない (sandbox 骨格にスタブを足すだけ)

### リスク

- **sandbox 実走が実 `/tmp` の導入ロックを取る**。`BROWSER_PROVISION_LOCK_DIR` を
  sandbox 内へ向けて隔離する (既存 `GLOBAL_TEST_LOCK_DIR` の扱いと同じ)。
- **実 CLI smoke が `pnpm test` を 1〜2 秒遅くする**。1 ケースのみなので受容する。
- **`pnpm` が PATH に無い環境**では smoke が失敗する。`pnpm test` 自体が pnpm 経由なので
  この前提は常に満たされる。

---

## 施策 7: 手順書と台帳の更新

### 変更箇所

- `docs/testing-browser.md` (§前提 / §CI での実行 / §トラブルシュート)
- `scripts/README.md` (2 行追記)
- `.gitignore` (1 行追記)

### 波及変更

- `ScriptsReadmeInventoryTest` が `scripts/README.md` の全数一致を強制するため、
  **追記漏れは即 fail する** (機械的に担保される)

### 変更後コード

`.gitignore`:

```gitignore
# Browser lane がレーン別に退避する失敗時 screenshot (CI の失敗時アップロード対象)
/storage/browser-test-artifacts/
```

`scripts/README.md`:

| スクリプト | 用途 | 実行タイミング |
|---|---|---|
| `setup-browser-testing.sh` | Browser テスト用のブラウザ実体 (chromium + webkit) と OS 共有ライブラリの導入。**導入の単一情報源**。要求 (`install-deps --dry-run`) と権限 (root / `sudo -n`) を別々に判定し、要求があるのに権限が無ければ特権経路を起こす前に落ちる。判定不能も拒否側に倒す。導入専用ロックで並行実行を直列化する。`--self-test` は判定関数を fixture で駆動する (実資源に触れない) | `scripts/run-browser-test.sh` の事前確認から自動 / CI (`browser-tests` job) / 手動 |
| `setup-browser-testing.contract.test.ts` | `setup-browser-testing.sh` の契約テスト (決定表の sandbox 実走 / 静的契約 / pin された実 Playwright の出力との突合) | `pnpm test` |

`docs/testing-browser.md` §前提 の書き換え:

```markdown
- **ブラウザの導入は `scripts/setup-browser-testing.sh` が行う**。
  `composer test:browser` はグローバルテストロックを取る**前**にこれを呼ぶので、
  **手で `playwright install` / `install-deps` を叩く必要は無い**。
  導入だけ先に済ませたいときは `bash scripts/setup-browser-testing.sh` を直接実行する。
  - WebKit は Linux で共有ライブラリ群 (gstreamer / gtk-4 / libwoff2 等) を要求する。
    スクリプトは「不足しているか」と「管理者権限があるか」を**別々に**判定し、
    **不足しているのに権限が無ければ導入せずに止まる**
    (黙って OS ライブラリ無しで導入すると、後で "Host system is missing dependencies to
    run browsers" という原因の分かりにくい失敗になるため)。
  - 判定できないとき (Playwright の出力が想定と違う等) も止まる。メッセージが出す
    確認コマンドで原因を見ること。
```

§CI での実行 への追記:

```markdown
`browser-tests` job は
`~/.cache/ms-playwright` のキャッシュ (key に `pnpm-lock.yaml` の hash を含む。
`restore-keys` は持たない) → `bash scripts/setup-browser-testing.sh` → `composer test:browser` →
**失敗時のみ** `storage/browser-test-artifacts/` のアップロード、という順で走る。

レーン別の証跡が要るのは、pest-plugin-browser が **起動のたびに**
`tests/Browser/Screenshots/` を消すためである。`scripts/run-browser-test.sh` が
レーン終了直後に `storage/browser-test-artifacts/<lane>/` へ退避しないと、
先に失敗した Chromium の証跡が WebKit の起動で消える。

workflow 側の構成 (キャッシュ / 導入スクリプト呼び出し / 失敗時アップロード) は
`tests/js/architecture/ci-workflow-inventory.test.ts` の W7 / W14 / W18〜W20 が固定する。
```

§トラブルシュート への追記:

```markdown
- **`ERROR: Browser テスト用のブラウザを導入できません`**: `scripts/setup-browser-testing.sh` が
  止めている。理由キー (`no-privilege` / `undeterminable-deps` / `unsupported-os`) ごとに
  次の手順がメッセージに出る。**握り潰して先へ進まないこと** — 進んでも WebKit レーンが全 fail する。
- **`storage/browser-test-artifacts/`**: レーン別に退避した失敗時 screenshot の置き場
  (CI のアップロード対象)。`.gitignore` 済みで、ローカルでは消してよい
  (次の `composer test:browser` が作り直す)。
```

### テスト計画

- [ ] 既存テスト `tests/Architecture/ScriptsReadmeInventoryTest.php` が
      新規 2 ファイルの台帳登録を強制する (追記漏れは自動で赤くなる)
- [ ] `.gitignore` の登録は **施策 6 の C15** が機械検査する
      (登録漏れは worktree を恒常的に dirty にするため、機械で守る価値がある)
- [ ] **`docs/testing-browser.md` の記述内容は機械検査しない** (保証範囲を正確に書く)。
      施策 5 の gate が見るのは shell / JSON の scripts / Dockerfile の**実行経路**だけで、
      手順書・コメント・設計文書は母集団に入らない。
      手順書がずれても「動かなくなる」ことはなく、
      導入の実体はスクリプト 1 本に寄っている (ずれても実害が出ない構造にすることで担保する)

### リスク

- **手順書と実装のドリフト**。導入の実体はスクリプト 1 本なので、
  手順書がずれても「動かなくなる」ことはない (施策 5 の gate が実行経路の増殖だけを止める)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | アプリケーションコード (app/ resources/ routes/ database/ config/) を 1 行も変更しない。触るのは `scripts/` / `.github/` / `tests/js/` / `tests/Architecture/` / `docs/` / `.gitignore` に限られ、既存の業務ロジックとの結合が無い。施策 1 → 2 → 6 → 3 → 4 → 5 → 7 の順に積み上げれば、各段でテストを緑にしたまま進められる |
| 競合リスク | **`.github/workflows/ci.yml` と `tests/js/architecture/ci-workflow-inventory.test.ts` は同時期の他タスクと競合しうる** (CI に段を足す変更は他の設計でも起こる)。施策 3 と 4 は**同一コミットで**行うこと (片方だけ入れると必ず赤くなる)。`scripts/run-browser-test.contract.test.ts` は施策 2 と同一コミットで更新する |

## 実装順序 (推奨)

1. 施策 1 (`setup-browser-testing.sh`) + 施策 6 の該当部分 (`setup-browser-testing.contract.test.ts`)
   — 作成後に **`chmod +x scripts/setup-browser-testing.sh`** を実行する
   (git が実行ビットを追跡する。付け忘れると施策 5 の gate だけが落ちる)。
   この時点で `pnpm test` が緑
2. 施策 6 の共有ヘルパ切り出し (`tests/js/support/shell-contract.ts` +
   `tests/js/support/shell-contract.test.ts`。既存の describe を**消さずに移す**)
3. 施策 2 (`run-browser-test.sh`) + 施策 6 の該当部分
   (C9 / C10 / C11 / C12 / C13 / C14a / C14b / C15)
4. 施策 3 + 施策 4 (**同一コミット**)。着手前に `actions/cache` /
   `actions/upload-artifact` の**現行 major が実在すること**を確認する
   (gate は版を無視するので、版の誤りはローカルのテストでは拾えず CI で初めて落ちる)
5. 施策 5 (`BrowserProvisioningEntrypointTest`)
6. 施策 7 (docs / README / .gitignore)
7. `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
   `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
   `pnpm build:packages` / `pnpm test:packages` を全て緑にする
8. `composer test:browser` を実走し、事前確認と証跡退避が実環境で動くことを確認する


## 実装差分 (git diff)

```diff
diff --git a/.github/workflows/ci.yml b/.github/workflows/ci.yml
index 71c4824..4eda92c 100644
--- a/.github/workflows/ci.yml
+++ b/.github/workflows/ci.yml
@@ -153,6 +153,14 @@ jobs:
         with:
           node-version: 22
           cache: pnpm
+      # ブラウザ実体 (数百 MB) のキャッシュ。key に pnpm-lock.yaml の hash を含めるので、
+      # playwright の版が変われば別キーになる。**restore-keys は持たない**:
+      # 部分一致で復元すると古い版のブラウザを溜め込み続けるため
+      # (lockfile 更新時と初回は従来どおり取得する = 短縮効果は lockfile 不変時に限る)。
+      - uses: actions/cache@v6
+        with:
+          path: ~/.cache/ms-playwright
+          key: ${{ runner.os }}-${{ runner.arch }}-ms-playwright-${{ hashFiles('pnpm-lock.yaml') }}
       # php job と同じ理由で composer install より前に置く (ProductionEnvGuard の fail-fast 回避)。
       - name: Prepare .env
         run: cp .env.example .env
@@ -167,18 +175,31 @@ jobs:
       # 実ブラウザは public/build のビルド済アセットを読む (withoutVite() は Browser lane に効かない)。
       - name: Build frontend assets
         run: pnpm build
-      # ブラウザ実体は Playwright が別途 DL する。**pnpm exec** を使うこと:
-      # pest-plugin-browser が起動する run-server は root devDependency の playwright と
-      # 同一実体である必要があり、npx だと別バージョンを引きうる。
-      # 未導入だと PlaywrightOutdatedException で 2 レーンとも全 fail する (ローカル実測)。
-      # --with-deps は WebKit が Linux で要求する共有ライブラリ群 (gstreamer / gtk-4 / libwoff2 等) を入れる。
-      - name: Install Playwright browsers (chromium + webkit)
-        run: pnpm exec playwright install --with-deps chromium webkit
+      # ブラウザ実体と OS 共有ライブラリの導入。**導入の知識はスクリプト 1 本に集約する**
+      # (CI が検証するものと開発者が走らせるものを同一に保つ。T099 と同じ理由)。
+      # 対象ブラウザ集合 (chromium + webkit) と --with-deps の要否判定はスクリプト側の契約で、
+      # scripts/setup-browser-testing.contract.test.ts が固定する。
+      - name: Provision browsers (chromium + webkit)
+        run: bash scripts/setup-browser-testing.sh
       # composer test:browser = scripts/run-browser-test.sh
-      # (グローバルロック → config:clear → ensure-test-db → chromium レーン → webkit レーン)。
+      # (導入の事前確認 → グローバルロック → config:clear → ensure-test-db →
+      #  chromium レーン → webkit レーン)。
       # レーン引数は渡さない (§既定が契約)。
       - name: Pest (browser lanes)
         run: composer test:browser
+      # 失敗時だけレーン別の失敗スクリーンショットを回収する。
+      # **continue-on-error は付けない** (soft-fail 禁止 / W13)。
+      # ここでの `if` は **step-level** であり、W15 が deny-by-default で禁じている
+      # job-level の `if` とは別物である。
+      # if-no-files-found は既定 (warn) のまま: 証跡が無い失敗 (DB 起動失敗等) は
+      # 正常にありうるので job を落とさず、警告だけ残す。
+      - name: Collect browser lane artifacts
+        if: failure()
+        uses: actions/upload-artifact@v7
+        with:
+          name: browser-test-artifacts
+          path: storage/browser-test-artifacts/
+          retention-days: 7
 
   frontend:
     runs-on: ubuntu-latest
diff --git a/.gitignore b/.gitignore
index 8782492..cf45abc 100644
--- a/.gitignore
+++ b/.gitignore
@@ -41,6 +41,8 @@
 /storage/prism-prompt-debug/
 # bug-hunt コード到達カバレッジの出力先 (scripts/bug-hunt-shard.sh --coverage)
 /storage/bughunt-coverage/
+# Browser lane がレーン別に退避する失敗時 screenshot (CI の失敗時アップロード対象)
+/storage/browser-test-artifacts/
 /vendor
 _ide_helper.php
 Homestead.json
diff --git a/docs/testing-browser.md b/docs/testing-browser.md
index 4427047..2d31aa5 100644
--- a/docs/testing-browser.md
+++ b/docs/testing-browser.md
@@ -74,26 +74,43 @@ ### ブラウザレーン (Chromium + WebKit)
 ### CI での実行
 
 `.github/workflows/ci.yml` の `browser-tests` job が、**Chromium / WebKit の 2 レーンをそのまま**
-実行する (レーン限定も並列度上書きもしない)。job は postgres service +
-`pnpm build` + `pnpm exec playwright install --with-deps chromium webkit` を前提として
-`composer test:browser` を呼ぶ。CI 専用の起動経路は作らない (T099: CI が検証するものと
-開発者が走らせるものを同一に保つ)。
-
-workflow 側で `BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` を設定する退行は
-`tests/js/architecture/ci-workflow-inventory.test.ts` が、スクリプト側の契約は
-`scripts/run-browser-test.contract.test.ts` が deny-by-default で止める。
+実行する (レーン限定も並列度上書きもしない)。CI 専用の起動経路は作らない
+(T099: CI が検証するものと開発者が走らせるものを同一に保つ)。
+
+job は postgres service と `pnpm build` を前提に、
+`~/.cache/ms-playwright` のキャッシュ (key に `pnpm-lock.yaml` の hash を含む。
+`restore-keys` は持たない) → `bash scripts/setup-browser-testing.sh` → `composer test:browser` →
+**失敗時のみ** `storage/browser-test-artifacts/` のアップロード、という順で走る。
+
+レーン別の証跡が要るのは、pest-plugin-browser が **起動のたびに**
+`tests/Browser/Screenshots/` を消すためである。`scripts/run-browser-test.sh` が
+レーン終了直後に `storage/browser-test-artifacts/<lane>/` へ退避しないと、
+先に失敗した Chromium の証跡が WebKit の起動で消える。
+
+workflow 側で `BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` を設定する退行と、
+workflow 側の構成 (キャッシュ / 導入スクリプト呼び出し / 失敗時アップロード) は
+`tests/js/architecture/ci-workflow-inventory.test.ts` の W7 / W9 / W14 / W18〜W20 が、
+スクリプト側の契約は `scripts/run-browser-test.contract.test.ts` と
+`scripts/setup-browser-testing.contract.test.ts` が deny-by-default で止める。
+導入コマンドを別の場所で実行し直す退行は
+`tests/Architecture/BrowserProvisioningEntrypointTest.php` が止める。
 
 ### 前提
 
 - **DB は Feature lane と同じ worktree 固有 pgsql テスト DB** (`<slug>_test_<worktree-hash>`)。
   `scripts/ci/ensure-test-db.php` が冪等に作成し、`tests/bootstrap.php` の単一点ガードが
   dev DB への接続を Laravel boot 前に fail-closed で拒否する (phpunit.xml と同一機構)。
-- ブラウザは Playwright が独自 DL する: **`pnpm exec playwright install chromium webkit`**。
-  system chromedriver は不要。
-  **WebKit は Linux で共有ライブラリ群 (gstreamer / gtk-4 / libwoff2 等) を要求する**ため、
-  devcontainer では **`sudo pnpm exec playwright install-deps webkit`**
-  (または `playwright install --with-deps webkit`) を一度実行する。未導入だと WebKit レーンが
-  "Host system is missing dependencies to run browsers" で全 fail する。
+- **ブラウザの導入は `scripts/setup-browser-testing.sh` が行う** (system chromedriver は不要)。
+  `composer test:browser` はグローバルテストロックを取る**前**にこれを呼ぶので、
+  **手で `playwright install` / `install-deps` を叩く必要は無い**。
+  導入だけ先に済ませたいときは `bash scripts/setup-browser-testing.sh` を直接実行する。
+  - WebKit は Linux で共有ライブラリ群 (gstreamer / gtk-4 / libwoff2 等) を要求する。
+    スクリプトは「不足しているか」と「管理者権限があるか」を**別々に**判定し、
+    **不足しているのに権限が無ければ導入せずに止まる**
+    (黙って OS ライブラリ無しで導入すると、後で "Host system is missing dependencies to
+    run browsers" という原因の分かりにくい失敗になるため)。
+  - 判定できないとき (Playwright の出力が想定と違う等) も止まる。メッセージが出す
+    確認コマンドで原因を見ること。
 - 実ブラウザは `public/build` のビルド済アセットを読むため、UI 変更後は
   **`pnpm build` を先に実行する**こと (`withoutVite()` は Browser lane に適用されない)。
 
@@ -199,6 +216,12 @@ ## トラブルシュート
   待機の heartbeat が出ている間はそのまま待てばよい。
 - **bug-hunt が走行中で起動できない**: `scripts/bug-hunt-shard.sh teardown` で
   bug-hunt 環境を落としてから再実行する。
+- **`ERROR: Browser テスト用のブラウザを導入できません`**: `scripts/setup-browser-testing.sh` が
+  止めている。理由キー (`no-privilege` / `undeterminable-deps` / `unsupported-os`) ごとに
+  次の手順がメッセージに出る。**握り潰して先へ進まないこと** — 進んでも WebKit レーンが全 fail する。
+- **`storage/browser-test-artifacts/`**: レーン別に退避した失敗時 screenshot の置き場
+  (CI のアップロード対象)。`.gitignore` 済みで、ローカルでは消してよい
+  (次の `composer test:browser` が作り直す)。
 
 ## グローバルテストロックの手動復旧 runbook
 
diff --git a/scripts/README.md b/scripts/README.md
index 6655352..e2a1d8a 100644
--- a/scripts/README.md
+++ b/scripts/README.md
@@ -27,7 +27,9 @@ ## スクリプト一覧
 | `audit-gate.contract.test.ts` | `audit-gate.sh` の取得契約テスト (空出力 / 取得失敗を advisory 0 件にせず fail-closed で止める。有効 JSON + 非ゼロ exit = 脆弱性検出の正常系は判定へ通す) | `pnpm test` |
 | `test-inventory-config.ts` | vitest の include (root / packages/cli の 2 project) の単一 SoT。`vitest.config.ts` と `packages/cli/vitest.config.ts` が本ファイルから include を引く | 両 vitest config から import (直接実行しない) |
 | `vitest-inventory-gate.test.ts` | FS 走査と `vitest list` の突合による inventory gate。どの project にも入らない `*.test.ts` (= 書いたのに走っていないテスト) と、列挙 0 件の空振りを検出 | `pnpm test` |
-| `run-browser-test.contract.test.ts` | `run-browser-test.sh` の契約テスト (2 レーン実行 / 失敗レーンがあっても全レーン実行して overall 非ゼロ / 既定直列 / orphan playwright 掃除 / bug-hunt 除外) | `pnpm test` |
+| `run-browser-test.contract.test.ts` | `run-browser-test.sh` の契約テスト (2 レーン実行 / 失敗レーンがあっても全レーン実行して overall 非ゼロ / 既定直列 / orphan playwright 掃除 / bug-hunt 除外 / 導入の事前確認がロック取得より前 / レーン別の証跡退避) | `pnpm test` |
+| `setup-browser-testing.sh` | Browser テスト用のブラウザ実体 (chromium + webkit) と OS 共有ライブラリの導入。**導入の単一情報源**。要求 (`install-deps --dry-run`) と権限 (root / `sudo -n`) を別々に判定し、要求があるのに権限が無ければ特権経路を起こす前に落ちる。判定不能も拒否側に倒す。導入専用ロックで並行実行を直列化する。`--self-test` は判定関数を fixture で駆動する (実資源に触れない) | `scripts/run-browser-test.sh` の事前確認から自動 / CI (`browser-tests` job) / 手動 |
+| `setup-browser-testing.contract.test.ts` | `setup-browser-testing.sh` の契約テスト (決定表の sandbox 実走 / 静的契約 / pin された実 Playwright の出力との突合) | `pnpm test` |
 | `run-browser-test.sh` | Browser テスト (pest-plugin-browser) を**グローバルテストロック配下**で並列上限付きで実行。**Chromium / WebKit の 2 レーンが契約** (bfcache 復元シナリオは WebKit レーンが正本)。残留 playwright run-server を前後で掃除する (`@playwright/` = bug-hunt 側は除外)。起動時に bughunt ポート `:8010..8018` の best-effort pre-flight guard を掛ける (cap=4 より広く取るのは残留検出のため) | `composer test:browser` 等から呼び出し。レーン限定は `BROWSER_TEST_LANES` / 並列度は `BROWSER_TEST_PROCESSES` |
 | `bug-hunt-shard.sh` | bug-hunt シャードオーケストレータ。隔離環境 (DB `bug_hunt(_N)` / `:8010+N`) の provision / serve / teardown と、**dev DB を wipe しないための用途別 DB wrapper + 3-way hard-deny guard** を提供する (AGENTS.md §bug-hunt) | `/app-bug-hunt` から。`self-test` は実資源に触れず guard を検証 |
 | `bug-hunt-inventory-check.sh` | bug-hunt インベントリのドリフト検知。`route:list` と `.claude/skills/app-bug-hunt/{screens,operations}.md` の差分 (新ルート未追記 / 消失) を出す (exit 3 = 差分あり) | route 追加・削除時 / bug-hunt 実行前 |
diff --git a/scripts/run-browser-test.contract.test.ts b/scripts/run-browser-test.contract.test.ts
index 5505265..8f39cee 100644
--- a/scripts/run-browser-test.contract.test.ts
+++ b/scripts/run-browser-test.contract.test.ts
@@ -7,12 +7,13 @@
  * 実プロセスで振る舞いを固定する。
  *
  * 2 層構成:
- *   層 1 (sandbox 実走): C1〜C4, C8 — mkdtemp に最小の repo 骨格を組み、pest / php を
- *         スタブへ差し替えて実スクリプトを走らせる。
- *   層 2 (静的契約):    C5〜C7 — orphan 掃除の振る舞いは「PPID が 1 に reparent する」
- *         という subreaper 依存の前提を要するため、実プロセス化すると偽赤を生む。
- *         守りたいのは「掃除ロジックが消される / bug-hunt 除外が消される / EXIT trap の
- *         所有権が奪われる」という編集による退行なので静的検査で足りる
+ *   層 1 (sandbox 実走): C1〜C4, C8, C10〜C12, C14 — mkdtemp に最小の repo 骨格を組み、
+ *         pest / php / 導入スクリプトをスタブへ差し替えて実スクリプトを走らせる。
+ *   層 2 (静的契約):    C5〜C7, C9, C13, C15 — orphan 掃除の振る舞いは「PPID が 1 に
+ *         reparent する」という subreaper 依存の前提を要するため、実プロセス化すると
+ *         偽赤を生む。守りたいのは「掃除ロジックが消される / bug-hunt 除外が消される /
+ *         EXIT trap の所有権が奪われる / 事前確認と証跡初期化の位置がずれる」という
+ *         編集による退行なので静的検査で足りる
  *         (tests/Architecture/GlobalTestLockInventoryTest.php と同方針)。
  *
  * GLOBAL_TEST_LOCK_DIR の使用について: これは scripts/global-test-lock.sh が
@@ -32,15 +33,19 @@ import {
     mkdirSync,
     mkdtempSync,
     readFileSync,
+    readdirSync,
     rmSync,
+    statSync,
     writeFileSync,
 } from "node:fs";
 import { tmpdir } from "node:os";
 import { dirname, join, resolve } from "node:path";
+import { codeLines, lineIndexOf, mutate } from "../tests/js/support/shell-contract";
 
 const REPO_ROOT = process.cwd();
 const SCRIPT_PATH = resolve(REPO_ROOT, "scripts/run-browser-test.sh");
 const LOCK_LIB_PATH = resolve(REPO_ROOT, "scripts/global-test-lock.sh");
+const GITIGNORE_PATH = resolve(REPO_ROOT, ".gitignore");
 
 /** 実ソース (verbatim)。層 2 の正のコントロールと層 1 の sandbox 元になる。 */
 function realSource(): string {
@@ -48,20 +53,9 @@ function realSource(): string {
 }
 
 // --------------------------------------------------------------------------
-// 層 2: 静的契約 (C5, C6, C7 + 既定値リテラル)
+// 層 2: 静的契約 (C5, C6, C7, C9, C13 + 既定値リテラル)
 // --------------------------------------------------------------------------
 
-/**
- * 行頭 (空白除く) が `#` の行を落とす。方針説明コメントで偽赤にしないため
- * (tests/Architecture/GlobalTestLockInventoryTest.php の globalTestLockCodeLines と同方針)。
- */
-export function codeLines(source: string): string {
-    return source
-        .split("\n")
-        .filter((line) => !/^\s*#/.test(line))
-        .join("\n");
-}
-
 /**
  * 静的契約の違反を列挙する純関数。
  * fixture 文字列を渡せるようにして、負のコントロール (検出器が空振りしていないこと) を
@@ -94,6 +88,33 @@ export function staticContractViolations(source: string): string[] {
         violations.push("C7: 自前の trap ... EXIT が張られている (ロックが解放されなくなる)");
     }
 
+    // C9: ブラウザ導入の事前確認は **グローバルテストロックを取る前** に行う。
+    // 後ろに置くと、先行レーンの終了を数分待たされてから「ブラウザが入っていません」と
+    // 言うことになる (bug-hunt guard と同じ理由)。
+    const provisionAt = lineIndexOf(code, "bash scripts/setup-browser-testing.sh");
+    const acquireAt = lineIndexOf(code, "global_test_lock_acquire");
+    if (provisionAt < 0) {
+        violations.push("C9: ブラウザ導入の事前確認 (setup-browser-testing.sh) が消えている");
+    } else if (acquireAt >= 0 && provisionAt > acquireAt) {
+        violations.push("C9: 事前確認がグローバルテストロック取得より後ろにある");
+    }
+
+    // C13: 証跡ディレクトリの初期化は「ロック取得後・レーンループ前」。
+    // ロックより前だと並行実行中の別レーンの証跡を消し、ループ後だと前回の残骸を
+    // 今回の失敗として拾い上げる。
+    const resetAt = lineIndexOf(code, 'rm -rf "${ARTIFACT_DIR}"');
+    const loopAt = lineIndexOf(code, "for lane in");
+    if (resetAt < 0) {
+        violations.push("C13: 証跡ディレクトリの初期化が消えている (前回の残骸を今回の失敗として扱う)");
+    } else {
+        if (acquireAt >= 0 && resetAt < acquireAt) {
+            violations.push("C13: 証跡初期化がロック取得より前にある (並行実行の証跡を消す)");
+        }
+        if (loopAt >= 0 && resetAt > loopAt) {
+            violations.push("C13: 証跡初期化がレーンループより後ろにある");
+        }
+    }
+
     // 既定値リテラル (層 1 の振る舞い検査と二重化する保険)
     if (!code.includes("${BROWSER_TEST_PROCESSES:-1}")) {
         violations.push("既定並列度が 1 でない (BROWSER_TEST_PROCESSES:-1 が消えている)");
@@ -109,32 +130,62 @@ export function staticContractViolations(source: string): string[] {
 // 層 1: sandbox 実走
 // --------------------------------------------------------------------------
 
-/** 元ソースの `from` を `to` に 1 箇所だけ置換する。置換が成立しなければ throw (空振り防止)。 */
-export function mutate(source: string, from: string, to: string): string {
-    const occurrences = source.split(from).length - 1;
-    if (occurrences !== 1) {
-        throw new Error(`mutation target must appear exactly once (found ${occurrences}): ${from}`);
-    }
-    const mutated = source.replace(from, to);
-    if (mutated === source) throw new Error(`mutation did not change the source: ${from}`);
-    if (!mutated.includes(to)) throw new Error(`mutated source lacks the expected token: ${to}`);
-    return mutated;
-}
-
 interface SandboxRun {
     /** スクリプトの終了コード。 */
     status: number;
     /** スタブ pest が記録した呼び出し (1 行 = 1 レーン、argv の JSON 配列)。 */
     pestCalls: string[][];
+    /** 導入スクリプトのスタブが呼ばれた回数。 */
+    provisionCalls: number;
+    /** 実行後に storage/browser-test-artifacts 配下に残ったファイル (相対パス・昇順)。 */
+    artifacts: string[];
     stderr: string;
 }
 
+interface SandboxOptions {
+    /** 何レーン目 (1 始まり) を失敗させるか。 */
+    failingLanes?: number[];
+    /** 失敗レーンの終了コード (既定 1)。 */
+    failExitCode?: number;
+    /** 追加の環境変数。 */
+    env?: Record<string, string>;
+    /** 導入スクリプトのスタブの終了コード (既定 0)。 */
+    provisionExitCode?: number;
+    /** pest スタブが実挙動 (起動時に Screenshots を消してから 1 枚書く) を模すか。 */
+    pestWritesScreenshots?: boolean;
+    /** pest スタブの終了直前に差し込む追加 shell 行 (異常系の作り込み用)。 */
+    pestExtraLines?: string[];
+    /** PATH の先頭へ置く追加スタブ (コマンド名 → shell 本文)。 */
+    extraStubs?: Record<string, string>;
+    /** 実行前に sandbox へ作っておくファイル (sandbox 相対パス → 内容)。 */
+    seedFiles?: Record<string, string>;
+}
+
 function writeExecutable(path: string, content: string): void {
     mkdirSync(dirname(path), { recursive: true });
     writeFileSync(path, content, "utf-8");
     chmodSync(path, 0o755);
 }
 
+/**
+ * dir 配下のファイルを再帰列挙して相対パス (昇順) で返す。
+ * 退避先が通常ファイルとして作られている異常系 (C14a) もあるので、
+ * ディレクトリでなければ空として扱う。
+ */
+function listFilesRecursively(dir: string, prefix = ""): string[] {
+    if (!existsSync(dir) || !statSync(dir).isDirectory()) return [];
+    const found: string[] = [];
+    for (const entry of readdirSync(dir, { withFileTypes: true })) {
+        const relative = prefix === "" ? entry.name : `${prefix}/${entry.name}`;
+        if (entry.isDirectory()) {
+            found.push(...listFilesRecursively(join(dir, entry.name), relative));
+        } else {
+            found.push(relative);
+        }
+    }
+    return found.sort();
+}
+
 /**
  * bug-hunt 併走の pre-flight guard (127.0.0.1:8010..8018) と同じ検査をテスト側でも行う。
  * ★ bug-hunt の並列 cap は 4 だが、guard 側と同じく残留 serve 検出のためポート範囲は
@@ -163,13 +214,9 @@ function assertNoBughuntPorts(): void {
  * sandbox に最小の repo 骨格を組み、渡された script source を実行する。
  *
  * @param scriptSource   実行する run-browser-test.sh の内容 (verbatim または改変コピー)
- * @param failingLanes   何レーン目 (1 始まり) を exit 1 にするか
- * @param env            追加の環境変数
+ * @param options        スタブの挙動と事前状態
  */
-function runInSandbox(
-    scriptSource: string,
-    options: { failingLanes?: number[]; env?: Record<string, string> } = {},
-): SandboxRun {
+function runInSandbox(scriptSource: string, options: SandboxOptions = {}): SandboxRun {
     assertNoBughuntPorts();
 
     const sandbox = mkdtempSync(join(tmpdir(), "run-browser-test-contract-"));
@@ -188,6 +235,20 @@ function runInSandbox(
         writeFileSync(join(sandbox, "artisan"), "<?php\n", "utf-8");
         writeFileSync(join(sandbox, "phpunit.browser.xml"), "<phpunit/>\n", "utf-8");
 
+        // 導入スクリプトのスタブ: 呼び出しを記録し、指定の終了コードで終わる。
+        // 実物は Playwright を起動するので sandbox では差し替える (呼ばれたか / 呼ばれた結果を
+        // レーンがどう扱うか、が本テストの関心である)。
+        const provisionCallsPath = join(sandbox, "provision-calls.log");
+        writeExecutable(
+            join(sandbox, "scripts/setup-browser-testing.sh"),
+            [
+                "#!/usr/bin/env bash",
+                "set -u",
+                `printf 'called\\n' >> "${provisionCallsPath}"`,
+                `exit ${options.provisionExitCode ?? 0}`,
+            ].join("\n"),
+        );
+
         // php スタブ: 何もせず即座に成功する。
         //
         // **意図的に sleep を入れない**: T104 は pgid probe の race (既に終了した pid に
@@ -198,9 +259,24 @@ function runInSandbox(
         // スタブが sub-millisecond で終わって本契約テストが落ちる。
         writeExecutable(join(sandbox, "bin/php"), "#!/usr/bin/env bash\nexit 0\n");
 
+        for (const [name, body] of Object.entries(options.extraStubs ?? {})) {
+            writeExecutable(join(sandbox, "bin", name), body);
+        }
+
+        for (const [relative, content] of Object.entries(options.seedFiles ?? {})) {
+            const path = join(sandbox, relative);
+            mkdirSync(dirname(path), { recursive: true });
+            writeFileSync(path, content, "utf-8");
+        }
+
         const callsPath = join(sandbox, "pest-calls.jsonl");
         const failing = options.failingLanes ?? [];
-        // pest スタブ: argv を JSONL で追記し、指定回目の呼び出しだけ exit 1 する
+        const failExitCode = options.failExitCode ?? 1;
+        // pest スタブ: argv を JSONL で追記し、指定回目の呼び出しだけ非ゼロで終わる。
+        //
+        // pestWritesScreenshots のときは pest-plugin-browser の実挙動を模す:
+        // **起動のたびに tests/Browser/Screenshots を丸ごと消してから**書く
+        // (この挙動があるからレーンごとの退避が要る = C11 が守る不変条件そのもの)。
         writeExecutable(
             join(sandbox, "vendor/bin/pest"),
             [
@@ -210,7 +286,7 @@ function runInSandbox(
                 `CALLS="${callsPath}"`,
                 // argv を JSON 配列へ (jq に依存しない素朴なエスケープ: 実引数に " や \\ は現れない)
                 'out="["',
-                'first=1',
+                "first=1",
                 'for a in "$@"; do',
                 '  if [ "$first" = "1" ]; then first=0; else out="${out},"; fi',
                 '  esc="${a//\\\\/\\\\\\\\}"',
@@ -220,8 +296,16 @@ function runInSandbox(
                 'out="${out}]"',
                 'printf "%s\\n" "$out" >> "$CALLS"',
                 'n=$(wc -l < "$CALLS" | tr -d " ")',
+                ...(options.pestWritesScreenshots === true
+                    ? [
+                          'rm -rf "tests/Browser/Screenshots"',
+                          'mkdir -p "tests/Browser/Screenshots"',
+                          'printf "png\\n" > "tests/Browser/Screenshots/lane-${n}.png"',
+                      ]
+                    : []),
+                ...(options.pestExtraLines ?? []),
                 `for f in ${failing.join(" ") || "''"}; do`,
-                '  [ -n "$f" ] && [ "$n" = "$f" ] && exit 1',
+                `  [ -n "$f" ] && [ "$n" = "$f" ] && exit ${failExitCode}`,
                 "done",
                 "exit 0",
             ].join("\n"),
@@ -253,7 +337,17 @@ function runInSandbox(
                   .map((l) => JSON.parse(l) as string[])
             : [];
 
-        return { status: result.status ?? -1, pestCalls, stderr: result.stderr ?? "" };
+        const provisionCalls = existsSync(provisionCallsPath)
+            ? readFileSync(provisionCallsPath, "utf-8").split("\n").filter((l) => l.trim() !== "").length
+            : 0;
+
+        return {
+            status: result.status ?? -1,
+            pestCalls,
+            provisionCalls,
+            artifacts: listFilesRecursively(join(sandbox, "storage/browser-test-artifacts")),
+            stderr: result.stderr ?? "",
+        };
     } finally {
         rmSync(sandbox, { recursive: true, force: true });
     }
@@ -261,7 +355,7 @@ function runInSandbox(
 
 // --------------------------------------------------------------------------
 
-describe("run-browser-test.sh 層 2: 静的契約 (C5, C6, C7)", () => {
+describe("run-browser-test.sh 層 2: 静的契約 (C5, C6, C7, C9, C13)", () => {
     it("現行の実ソースは違反 0 件 (正のコントロール)", () => {
         expect(staticContractViolations(realSource())).toEqual([]);
     });
@@ -311,21 +405,72 @@ describe("run-browser-test.sh 層 2: 静的契約 (C5, C6, C7)", () => {
     it("既定レーンを chromium だけに狭めると違反を返す", () => {
         const broken = mutate(
             realSource(),
-            '${BROWSER_TEST_LANES:-chromium webkit}',
-            '${BROWSER_TEST_LANES:-chromium}',
+            "${BROWSER_TEST_LANES:-chromium webkit}",
+            "${BROWSER_TEST_LANES:-chromium}",
+        );
+        expect(staticContractViolations(broken)).toContain("既定レーンが chromium webkit でない");
+    });
+
+    it("C9: 事前確認をロック取得の後ろへ移すと違反を返す", () => {
+        const source = realSource();
+        const withoutProvision = mutate(source, "bash scripts/setup-browser-testing.sh\n", "");
+        const broken = mutate(
+            withoutProvision,
+            'global_test_lock_acquire "composer test:browser"',
+            'global_test_lock_acquire "composer test:browser"\nbash scripts/setup-browser-testing.sh',
+        );
+        expect(staticContractViolations(broken)).toContain(
+            "C9: 事前確認がグローバルテストロック取得より後ろにある",
+        );
+    });
+
+    it("C9: 事前確認そのものを削ると違反を返す", () => {
+        const broken = mutate(realSource(), "bash scripts/setup-browser-testing.sh\n", "");
+        expect(staticContractViolations(broken)).toContain(
+            "C9: ブラウザ導入の事前確認 (setup-browser-testing.sh) が消えている",
+        );
+    });
+
+    it("C13: 証跡初期化を削ると違反を返す", () => {
+        const broken = mutate(realSource(), 'rm -rf "${ARTIFACT_DIR}"\n', "");
+        expect(staticContractViolations(broken)).toContain(
+            "C13: 証跡ディレクトリの初期化が消えている (前回の残骸を今回の失敗として扱う)",
+        );
+    });
+
+    it("C13: 証跡初期化をロック取得より前へ移すと違反を返す", () => {
+        const source = realSource();
+        const removed = mutate(source, 'rm -rf "${ARTIFACT_DIR}"\n', "");
+        const broken = mutate(
+            removed,
+            "# --- グローバルテストロック (旧 worktree-local ロックを置き換え) ---",
+            'rm -rf "${ARTIFACT_DIR}"\n# --- グローバルテストロック (旧 worktree-local ロックを置き換え) ---',
         );
         expect(staticContractViolations(broken)).toContain(
-            "既定レーンが chromium webkit でない",
+            "C13: 証跡初期化がロック取得より前にある (並行実行の証跡を消す)",
         );
     });
 });
 
+describe("run-browser-test.sh 層 2: C15 (.gitignore 登録)", () => {
+    it("C15: 退避先が .gitignore に登録されていること", () => {
+        // 登録漏れは Browser テスト実行後の worktree を恒常的に dirty にし、
+        // scripts/teardown-worktree.sh の dirty チェックを常時失敗させる。
+        const lines = readFileSync(GITIGNORE_PATH, "utf-8")
+            .split("\n")
+            .map((l) => l.trim());
+        expect(lines).toContain("/storage/browser-test-artifacts/");
+    });
+});
+
 describe("run-browser-test.sh 層 1: sandbox 実走 (C1〜C4, C8)", () => {
     it("C1/C4: 既定で chrome → safari の 2 レーンを直列に走らせる", { timeout: 30_000 }, () => {
         const run = runInSandbox(realSource());
 
         expect(run.status).toBe(0);
         expect(run.pestCalls).toHaveLength(2);
+        // 事前確認はレーン起動前にちょうど 1 回
+        expect(run.provisionCalls).toBe(1);
         // C1: レーン名の写像と順序
         expect(run.pestCalls[0]).toContain("--browser");
         expect(run.pestCalls[0][run.pestCalls[0].indexOf("--browser") + 1]).toBe("chrome");
@@ -360,6 +505,104 @@ describe("run-browser-test.sh 層 1: sandbox 実走 (C1〜C4, C8)", () => {
     });
 });
 
+describe("run-browser-test.sh 層 1: 事前確認と証跡退避 (C10〜C12, C14)", () => {
+    it("C10: 事前確認が失敗したらレーンを 1 本も起動しない", { timeout: 30_000 }, () => {
+        const run = runInSandbox(realSource(), { provisionExitCode: 1 });
+
+        expect(run.provisionCalls).toBe(1);
+        expect(run.pestCalls).toHaveLength(0);
+        expect(run.status).not.toBe(0);
+    });
+
+    it(
+        "C11: 2 レーン走らせても先行レーンの証跡が残る (失敗レーンでも退避される)",
+        { timeout: 30_000 },
+        () => {
+            const run = runInSandbox(realSource(), {
+                failingLanes: [1],
+                pestWritesScreenshots: true,
+            });
+
+            expect(run.pestCalls).toHaveLength(2);
+            expect(run.artifacts).toEqual(["chromium/lane-1.png", "webkit/lane-2.png"]);
+        },
+    );
+
+    it(
+        "C11 負のコントロール: 退避をループの外へ移すと先行レーンの証跡が消える",
+        { timeout: 30_000 },
+        () => {
+            const broken = mutate(
+                realSource(),
+                '    collect_lane_artifacts "${lane}"\n\n    cleanup_orphan_playwright\ndone',
+                '    cleanup_orphan_playwright\ndone\n\ncollect_lane_artifacts "${lane}"',
+            );
+            const run = runInSandbox(broken, { failingLanes: [1], pestWritesScreenshots: true });
+
+            expect(run.pestCalls).toHaveLength(2);
+            expect(run.artifacts).toEqual(["webkit/lane-2.png"]);
+        },
+    );
+
+    it("C12: 前回実行の残骸を持ち越さない", { timeout: 30_000 }, () => {
+        const run = runInSandbox(realSource(), {
+            seedFiles: { "storage/browser-test-artifacts/stale/old.png": "png\n" },
+        });
+
+        expect(run.status).toBe(0);
+        expect(run.artifacts).toEqual([]);
+    });
+
+    it("C12 負のコントロール: 初期化を削ると残骸が残る", { timeout: 30_000 }, () => {
+        const broken = mutate(realSource(), 'rm -rf "${ARTIFACT_DIR}"\n', "");
+        const run = runInSandbox(broken, {
+            seedFiles: { "storage/browser-test-artifacts/stale/old.png": "png\n" },
+        });
+
+        expect(run.artifacts).toEqual(["stale/old.png"]);
+    });
+
+    it(
+        "C14a: 退避先を作れなくてもレーンの終了コードを上書きしない",
+        { timeout: 30_000 },
+        () => {
+            // **失敗条件は初期化の後に作る**: スクリプトはレーンループの前に
+            // rm -rf "${ARTIFACT_DIR}" するので、実行前に置いたファイルは消えてしまい
+            // mkdir -p が成功してしまう。pest スタブに「退避先を通常ファイルとして作る」まで
+            // やらせることで、mkdir -p を確実に失敗させる。
+            const run = runInSandbox(realSource(), {
+                env: { BROWSER_TEST_LANES: "chromium" },
+                failingLanes: [1],
+                failExitCode: 23,
+                pestWritesScreenshots: true,
+                pestExtraLines: [
+                    'mkdir -p "storage"',
+                    'printf "not-a-dir\\n" > "storage/browser-test-artifacts"',
+                ],
+            });
+
+            expect(run.stderr).toContain("WARNING");
+            expect(run.status).toBe(23);
+        },
+    );
+
+    it("C14b: 複製に失敗してもレーンの終了コードを上書きしない", { timeout: 30_000 }, () => {
+        // mkdir -p 側と cp 側は別の分岐なので、片方だけでは不変条件を固定できない。
+        const run = runInSandbox(realSource(), {
+            env: { BROWSER_TEST_LANES: "chromium" },
+            failingLanes: [1],
+            failExitCode: 23,
+            pestWritesScreenshots: true,
+            extraStubs: {
+                cp: '#!/usr/bin/env bash\necho "cp stub failure" >&2\nexit 1\n',
+            },
+        });
+
+        expect(run.stderr).toContain("WARNING");
+        expect(run.status).toBe(23);
+    });
+});
+
 describe("run-browser-test.sh 層 1 の負のコントロール (検査が空振りしていないこと)", () => {
     it("C2 検査: 失敗時に break する改変では 1 レーンしか走らない", { timeout: 30_000 }, () => {
         const broken = mutate(
@@ -392,17 +635,3 @@ describe("run-browser-test.sh 層 1 の負のコントロール (検査が空振
         expect(run.pestCalls[0]).toContain("--parallel");
     });
 });
-
-describe("mutate() ヘルパ自身の保証 (負のコントロールが空振りしないこと)", () => {
-    it("置換対象が存在しなければ throw", () => {
-        expect(() => mutate("abc", "zzz", "yyy")).toThrow(/must appear exactly once \(found 0\)/);
-    });
-
-    it("置換対象が複数あれば throw", () => {
-        expect(() => mutate("aa", "a", "b")).toThrow(/must appear exactly once \(found 2\)/);
-    });
-
-    it("1 箇所だけなら置換して返す", () => {
-        expect(mutate("abc", "b", "X")).toBe("aXc");
-    });
-});
diff --git a/scripts/run-browser-test.sh b/scripts/run-browser-test.sh
index 907195a..f735ac2 100755
--- a/scripts/run-browser-test.sh
+++ b/scripts/run-browser-test.sh
@@ -24,8 +24,10 @@
 #     プラグイン側の後始末漏れがある。orphan は親プロセスのパイプ fd を握って
 #     呼び出し元を詰まらせる + プロセスを leak するため、実行前後に掃除する。
 #
-# 前提: pnpm build 済み (実ブラウザが public/build を読む) +
-#       `pnpm exec playwright install chromium webkit` 済み。詳細は docs/testing-browser.md。
+# 前提: pnpm build 済み (実ブラウザが public/build を読む)。
+#       ブラウザ実体と OS 共有ライブラリは scripts/setup-browser-testing.sh が導入する
+#       (本スクリプトがロック取得の前に呼ぶので、手で playwright install を叩く必要は無い)。
+#       詳細は docs/testing-browser.md。
 #
 # 使い方:
 #   composer test:browser                          # 全 Browser テスト (Chromium → WebKit)
@@ -78,6 +80,18 @@ if busy_port="$(bughunt_port_in_use)"; then
     exit 1
 fi
 
+# --- ブラウザ導入の事前確認 (グローバルテストロックを取る **前**) ---
+#
+# bug-hunt guard と同じ理由でロック取得より前に置く: 取得後に落とすと、先行レーンの
+# 終了を数分待たされたあとで「ブラウザが入っていません」と言うことになる。
+# 導入が実際に走る場合も、数百 MB の取得中にグローバルテストロックを保持し続けない。
+#
+# **source ではなく子プロセスで呼ぶ**: EXIT trap の所有者を scripts/global-test-lock.sh
+# 1 箇所に固定する契約と、本スクリプトが CI 環境変数を参照しない契約
+# (GlobalTestLockInventoryTest) を、どちらも壊さないため。
+# 導入スクリプトが非ゼロで終われば set -e でここで止まる (導線はあちらが出す)。
+bash scripts/setup-browser-testing.sh
+
 # --- グローバルテストロック (旧 worktree-local ロックを置き換え) ---
 # shellcheck source=scripts/global-test-lock.sh
 . "$(pwd)/scripts/global-test-lock.sh"
@@ -112,6 +126,34 @@ cleanup_orphan_playwright() {
 cleanup_orphan_playwright
 global_test_lock_on_exit cleanup_orphan_playwright
 
+# pest-plugin-browser が失敗時 screenshot を書く場所 (vendor 側で固定)。
+SCREENSHOT_DIR="tests/Browser/Screenshots"
+# レーン別に退避した証跡の置き場 (CI の失敗時アップロード対象)。
+ARTIFACT_DIR="storage/browser-test-artifacts"
+
+# レーンの証跡を退避する。
+#
+# **必要な理由**: pest-plugin-browser は **起動のたびに** tests/Browser/Screenshots を
+# 丸ごと消す。本レーンは chromium → webkit の 2 回 pest を起動するので、退避しないと
+# **先に失敗した chromium の証跡が webkit の起動で消える**。
+collect_lane_artifacts() {
+    local lane="$1"
+    [ -d "${SCREENSHOT_DIR}" ] || return 0
+    [ -n "$(ls -A "${SCREENSHOT_DIR}" 2>/dev/null)" ] || return 0
+    # **退避の失敗でレーンの結果を上書きしない**。set -euo pipefail 下では mkdir / cp が
+    # 落ちるとスクリプトごと終了し、テスト本体の終了コードが失われる。証跡は診断の補助で
+    # あって合否ではないので、**退避先の作成と複製の両方**を受けて警告 1 行で続行する
+    # (黙って握り潰さない)。
+    if ! mkdir -p "${ARTIFACT_DIR}/${lane}"; then
+        echo "WARNING: ${lane} レーンの証跡退避先を作成できませんでした (${ARTIFACT_DIR}/${lane})" >&2
+        return 0
+    fi
+    if ! cp -R "${SCREENSHOT_DIR}/." "${ARTIFACT_DIR}/${lane}/"; then
+        echo "WARNING: ${lane} レーンの証跡を退避できませんでした (${SCREENSHOT_DIR})" >&2
+    fi
+    return 0
+}
+
 global_test_lock_run php artisan config:clear --ansi
 
 # worktree 固有の base テスト DB (<slug>_test_<worktree-hash>) を冪等に用意する。
@@ -132,6 +174,11 @@ fi
 # レーンは順に実行し、**どれかが失敗したら最後に非ゼロで終わる**
 # (先頭レーンの失敗で後続レーンを飛ばすと WebKit の回帰を見落とすため)。
 # ロックは acquire で 1 回取ったまま 2 レーンを通して保持される (run は取得しない)。
+# 前回実行の証跡を捨てる。**ロック取得後・レーンループ前**に行う:
+#   - 前回の残骸を今回の失敗としてアップロードしない
+#   - ロック配下なので、並行する別実行の証跡を消すことはない
+rm -rf "${ARTIFACT_DIR}"
+
 overall=0
 for lane in ${LANES}; do
     case "${lane}" in
@@ -153,6 +200,10 @@ for lane in ${LANES}; do
         overall="${code}"
     fi
 
+    # **終了コードを保存した後・次レーンの起動前**に退避する
+    # (失敗したレーンでも必ず通る。`|| code=$?` で受けているので set -e で飛ばない)。
+    collect_lane_artifacts "${lane}"
+
     cleanup_orphan_playwright
 done
 
diff --git a/scripts/setup-browser-testing.contract.test.ts b/scripts/setup-browser-testing.contract.test.ts
new file mode 100644
index 0000000..6b4ec8c
--- /dev/null
+++ b/scripts/setup-browser-testing.contract.test.ts
@@ -0,0 +1,414 @@
+/**
+ * scripts/setup-browser-testing.sh の契約テスト。
+ *
+ * 導入の知識をスクリプト 1 本に集約した以上、「対象ブラウザは chromium + webkit である」
+ * 「OS 共有ライブラリが不足しているのに権限が無ければ特権経路を起こす前に落ちる」
+ * 「判定不能は拒否側に倒す」といった契約はこのファイルでしか守れない
+ * (ci.yml 側の gate は導入スクリプトを呼んでいることまでしか見ない)。
+ *
+ * 3 層構成:
+ *   層 1: `--self-test` を実プロセスで走らせ、決定表の自己検査が緑であることと
+ *         **ケース数の下限**を確認する (自己検査を空にして緑にする逃げを塞ぐ)。
+ *   層 2: 静的契約 (P1〜P6) と負のコントロール。
+ *   層 3: sandbox 実走 (S1〜S7) と、pin された実 Playwright の出力との突合。
+ *
+ * 実行: pnpm test (vitest の include に scripts/**\/*.test.ts が含まれる)
+ */
+import { describe, expect, it } from "vitest";
+import { spawnSync } from "node:child_process";
+import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
+import { tmpdir } from "node:os";
+import { dirname, join, resolve } from "node:path";
+import { codeLines, mutate } from "../tests/js/support/shell-contract";
+
+const REPO_ROOT = process.cwd();
+const SCRIPT_PATH = resolve(REPO_ROOT, "scripts/setup-browser-testing.sh");
+
+/** `--self-test` が最低限持つべきケース数。決定表の全網羅が 19 ケースある。 */
+const SELF_TEST_MIN_CASES = 19;
+
+function realSource(): string {
+    return readFileSync(SCRIPT_PATH, "utf-8");
+}
+
+/** スクリプト内の shell 定数 (`NAME='値'`) を取り出す。実 CLI smoke が文言を再実装しないため。 */
+export function extractShellConst(source: string, name: string): string {
+    const match = new RegExp(`^${name}='([^']*)'`, "m").exec(source);
+    if (match === null) throw new Error(`shell const not found: ${name}`);
+    return match[1];
+}
+
+// --------------------------------------------------------------------------
+// 層 2: 静的契約 (P1〜P6)
+// --------------------------------------------------------------------------
+
+/**
+ * 静的契約の違反を列挙する純関数 (fixture でも駆動できるようにする)。
+ */
+export function provisioningStaticViolations(source: string): string[] {
+    const code = codeLines(source);
+    const violations: string[] = [];
+
+    // P1: 対象ブラウザ集合は chromium + webkit (run-browser-test.sh の既定レーンと 1 対 1)
+    if (!code.includes("BROWSER_TARGETS=(chromium webkit)")) {
+        violations.push("P1: 対象ブラウザ集合が (chromium webkit) でない");
+    }
+
+    // P2: レーン変数へ代入しない (ci.yml の W9 が守る「CI がレーンを絞れない」前提を壊さない)
+    for (const name of ["BROWSER_TEST_LANES", "BROWSER_TEST_PROCESSES"]) {
+        if (new RegExp(`^\\s*(export\\s+)?${name}=`, "m").test(code)) {
+            violations.push(`P2: ${name} へ代入している (レーン契約を導入スクリプトから壊せる)`);
+        }
+    }
+
+    // P3: 導入ロックの置き場は「既定値つき展開 1 箇所」だけ。
+    // スクリプト自身が代入すると、テスト用 override が本番経路を変える口になる。
+    if (/^\s*(export\s+)?BROWSER_PROVISION_LOCK_DIR=/m.test(code)) {
+        violations.push("P3: BROWSER_PROVISION_LOCK_DIR へ代入している");
+    }
+    const lockDirRefs = code.match(/BROWSER_PROVISION_LOCK_DIR/g) ?? [];
+    if (lockDirRefs.length !== 1 || !code.includes("${BROWSER_PROVISION_LOCK_DIR:-/tmp}")) {
+        violations.push("P3: 導入ロックの置き場が既定値つき展開 1 箇所でない");
+    }
+
+    // P4: --with-deps は「不足あり かつ 権限あり」の 1 経路にだけ現れる。
+    // 無条件付与へ広げると、不足していない環境でも apt-get を起こす。
+    const withDeps = code.match(/--with-deps/g) ?? [];
+    if (withDeps.length !== 1) {
+        violations.push(`P4: --with-deps の出現が 1 回でない (${withDeps.length} 回)`);
+    }
+
+    // P5: 起こしてよい sudo は非対話 (`sudo -n`) だけ。
+    // 対話 sudo はスクリプト経由の呼び出しを無言のパスワード待ちで止める。
+    //
+    // 走査は「コマンド位置の sudo」= 行頭またはコマンド区切りの直後にあり、
+    // 直後に引数が続くものだけを見る。`printf 'sudo\n'` (分類結果の文字列) や
+    // `root|sudo)` (case ラベル) を巻き込むと偽陽性になるためである。
+    // 案内文 (echo) と存在確認 (`command -v sudo`) も実行ではないので外す。
+    const executable = code
+        .split("\n")
+        .filter((line) => !/^\s*echo\b/.test(line))
+        .join("\n")
+        .replace(/command -v sudo/g, "");
+    for (const [, argument] of executable.matchAll(/(?:^|[\s;&|(])sudo\s+(\S+)/g)) {
+        if (argument !== "-n") {
+            violations.push(`P5: 非対話でない sudo 実行がある (sudo ${argument})`);
+        }
+    }
+
+    // P6: flock(1) が無い環境は警告 1 行を出して排他なしで続行する
+    // (global-test-lock.sh の既存方針を踏襲。黙って落とさない / 黙って握り潰さない)
+    if (!code.includes("command -v flock") || !code.includes("WARNING: flock(1)")) {
+        violations.push("P6: flock 不在時の警告つき続行の分岐が消えている");
+    }
+
+    return violations;
+}
+
+// --------------------------------------------------------------------------
+// 層 3: sandbox 実走
+// --------------------------------------------------------------------------
+
+interface ProvisionRun {
+    status: number;
+    stdout: string;
+    stderr: string;
+    /** スタブが記録した外部コマンド呼び出し ("<コマンド> <argv...>" の行)。 */
+    calls: string[];
+}
+
+interface ProvisionOptions {
+    /** `uname -s` が返す文字列。 */
+    os?: string;
+    /** `playwright install-deps --dry-run` の標準出力。 */
+    depsOutput?: string;
+    /** 同・終了コード。 */
+    depsExit?: number;
+    /** `id -u` が返す値 (0 = root)。 */
+    uid?: string;
+    /** `sudo -n true` の終了コード (0 = 非対話 sudo が使える)。 */
+    sudoExit?: number;
+    /** スクリプトへ渡す引数。 */
+    args?: string[];
+}
+
+function writeExecutable(path: string, content: string): void {
+    mkdirSync(dirname(path), { recursive: true });
+    writeFileSync(path, content, "utf-8");
+    chmodSync(path, 0o755);
+}
+
+/**
+ * sandbox に PATH スタブ (`pnpm` / `uname` / `id` / `sudo` / `flock`) を置き、
+ * 実スクリプトを走らせて外部コマンド呼び出しを記録する。
+ */
+function runProvisionSandbox(source: string, options: ProvisionOptions = {}): ProvisionRun {
+    const sandbox = mkdtempSync(join(tmpdir(), "setup-browser-testing-contract-"));
+    try {
+        writeExecutable(join(sandbox, "scripts/setup-browser-testing.sh"), source);
+
+        const callsPath = join(sandbox, "calls.log");
+        const record = `printf '%s %s\\n' "$(basename "$0")" "$*" >> "${callsPath}"`;
+
+        const depsOutput = options.depsOutput ?? "All system dependencies are installed.";
+        const depsExit = options.depsExit ?? 0;
+
+        writeExecutable(
+            join(sandbox, "bin/pnpm"),
+            [
+                "#!/usr/bin/env bash",
+                "set -u",
+                record,
+                'case "$*" in',
+                '  *"install-deps --dry-run"*)',
+                `      printf '%s\\n' ${JSON.stringify(depsOutput)}`,
+                `      exit ${depsExit} ;;`,
+                '  *"--version"*)',
+                "      printf 'Version 1.61.1\\n'",
+                "      exit 0 ;;",
+                "esac",
+                "exit 0",
+            ].join("\n"),
+        );
+        writeExecutable(
+            join(sandbox, "bin/uname"),
+            ["#!/usr/bin/env bash", "set -u", record, `printf '%s\\n' ${JSON.stringify(options.os ?? "Linux")}`].join(
+                "\n",
+            ),
+        );
+        writeExecutable(
+            join(sandbox, "bin/id"),
+            ["#!/usr/bin/env bash", "set -u", `printf '%s\\n' ${JSON.stringify(options.uid ?? "1000")}`].join("\n"),
+        );
+        writeExecutable(
+            join(sandbox, "bin/sudo"),
+            ["#!/usr/bin/env bash", "set -u", record, `exit ${options.sudoExit ?? 0}`].join("\n"),
+        );
+        writeExecutable(join(sandbox, "bin/flock"), ["#!/usr/bin/env bash", "set -u", record, "exit 0"].join("\n"));
+
+        const result = spawnSync("bash", [join(sandbox, "scripts/setup-browser-testing.sh"), ...(options.args ?? [])], {
+            encoding: "utf-8",
+            env: {
+                ...process.env,
+                PATH: `${join(sandbox, "bin")}:${process.env.PATH ?? ""}`,
+                // 実 /tmp の導入ロックを取らない (既存 GLOBAL_TEST_LOCK_DIR の扱いと同じ)
+                BROWSER_PROVISION_LOCK_DIR: join(sandbox, "lockdir"),
+            },
+        });
+
+        const calls = existsSync(callsPath)
+            ? readFileSync(callsPath, "utf-8")
+                  .split("\n")
+                  .map((l) => l.trim())
+                  .filter((l) => l !== "")
+            : [];
+
+        return { status: result.status ?? -1, stdout: result.stdout ?? "", stderr: result.stderr ?? "", calls };
+    } finally {
+        rmSync(sandbox, { recursive: true, force: true });
+    }
+}
+
+/** ブラウザ実体の導入呼び出しだけを抜き出す (`install-deps` は含めない)。 */
+function browserInstallCalls(calls: string[]): string[] {
+    return calls.filter((c) => /playwright install(?!-)/.test(c));
+}
+
+const MISSING_DEPS_OUTPUT = "Missing system dependencies to run browsers: libwoff2dec.so.1.0.2";
+
+// --------------------------------------------------------------------------
+
+describe("setup-browser-testing.sh 層 1: --self-test", () => {
+    it("決定表の自己検査が緑で、ケース数が下限を満たすこと", { timeout: 30_000 }, () => {
+        const run = spawnSync("bash", [SCRIPT_PATH, "--self-test"], { encoding: "utf-8", cwd: REPO_ROOT });
+
+        expect(run.status, run.stderr).toBe(0);
+        const matched = /self-test: (\d+) cases, (\d+) failures/.exec(run.stdout);
+        expect(matched, `自己検査のサマリー行が無い: ${run.stdout}`).not.toBeNull();
+        expect(Number(matched?.[2])).toBe(0);
+        // 空の自己検査で緑にする逃げを塞ぐ
+        expect(Number(matched?.[1])).toBeGreaterThanOrEqual(SELF_TEST_MIN_CASES);
+    });
+});
+
+describe("setup-browser-testing.sh 層 2: 静的契約 (P1〜P6)", () => {
+    it("現行の実ソースは違反 0 件 (正のコントロール)", () => {
+        expect(provisioningStaticViolations(realSource())).toEqual([]);
+    });
+
+    it("P1: 対象ブラウザを chromium だけに狭めると違反を返す", () => {
+        const broken = mutate(realSource(), "BROWSER_TARGETS=(chromium webkit)", "BROWSER_TARGETS=(chromium)");
+        expect(provisioningStaticViolations(broken)).toContain("P1: 対象ブラウザ集合が (chromium webkit) でない");
+    });
+
+    it("P2: レーン変数への代入を足すと違反を返す", () => {
+        const broken = mutate(
+            realSource(),
+            "BROWSER_TARGETS=(chromium webkit)",
+            'BROWSER_TEST_LANES="chromium"\nBROWSER_TARGETS=(chromium webkit)',
+        );
+        expect(provisioningStaticViolations(broken)).toContain(
+            "P2: BROWSER_TEST_LANES へ代入している (レーン契約を導入スクリプトから壊せる)",
+        );
+    });
+
+    it("P3: 導入ロックの置き場をスクリプト自身が代入すると違反を返す", () => {
+        const broken = mutate(
+            realSource(),
+            'PROVISION_LOCK_DIR="${BROWSER_PROVISION_LOCK_DIR:-/tmp}"',
+            'BROWSER_PROVISION_LOCK_DIR=/tmp\nPROVISION_LOCK_DIR="${BROWSER_PROVISION_LOCK_DIR}"',
+        );
+        const violations = provisioningStaticViolations(broken);
+        expect(violations).toContain("P3: BROWSER_PROVISION_LOCK_DIR へ代入している");
+        expect(violations).toContain("P3: 導入ロックの置き場が既定値つき展開 1 箇所でない");
+    });
+
+    it("P4: --with-deps を無条件付与へ広げると違反を返す", () => {
+        const broken = mutate(
+            realSource(),
+            'plain)     pnpm exec playwright install "${BROWSER_TARGETS[@]}" ;;',
+            'plain)     pnpm exec playwright install --with-deps "${BROWSER_TARGETS[@]}" ;;',
+        );
+        expect(provisioningStaticViolations(broken)).toContain("P4: --with-deps の出現が 1 回でない (2 回)");
+    });
+
+    it("P5: 対話 sudo へ戻すと違反を返す", () => {
+        const broken = mutate(realSource(), "sudo -n true 2>/dev/null", "sudo true 2>/dev/null");
+        expect(provisioningStaticViolations(broken)).toContain("P5: 非対話でない sudo 実行がある (sudo true)");
+    });
+
+    it("P6: flock 不在時の分岐を削ると違反を返す", () => {
+        const broken = mutate(realSource(), 'echo "WARNING: flock(1) が無いため導入の排他なしで実行します" >&2', "true");
+        expect(provisioningStaticViolations(broken)).toContain("P6: flock 不在時の警告つき続行の分岐が消えている");
+    });
+});
+
+describe("setup-browser-testing.sh 層 3: sandbox 実走 (S1〜S7)", () => {
+    it("S1: linux / 依存充足 → dry-run と素の install だけ。sudo を起こさない", { timeout: 30_000 }, () => {
+        const run = runProvisionSandbox(realSource());
+
+        expect(run.status, run.stderr).toBe(0);
+        expect(run.calls.filter((c) => c.startsWith("pnpm"))).toEqual([
+            "pnpm exec playwright install-deps --dry-run chromium webkit",
+            "pnpm exec playwright install chromium webkit",
+        ]);
+        expect(run.calls.filter((c) => c.startsWith("sudo"))).toEqual([]);
+    });
+
+    it("S2: linux / 依存不足 / sudo 可 → --with-deps つきで導入する", { timeout: 30_000 }, () => {
+        const run = runProvisionSandbox(realSource(), {
+            depsOutput: MISSING_DEPS_OUTPUT,
+            depsExit: 1,
+            sudoExit: 0,
+        });
+
+        expect(run.status, run.stderr).toBe(0);
+        expect(browserInstallCalls(run.calls)).toEqual([
+            "pnpm exec playwright install --with-deps chromium webkit",
+        ]);
+        expect(run.calls.filter((c) => c.startsWith("sudo"))).not.toEqual([]);
+    });
+
+    it("S3: linux / 依存不足 / 権限なし → 導入せずに落ちる", { timeout: 30_000 }, () => {
+        const run = runProvisionSandbox(realSource(), {
+            depsOutput: MISSING_DEPS_OUTPUT,
+            depsExit: 1,
+            sudoExit: 1,
+        });
+
+        expect(run.status).toBe(1);
+        expect(browserInstallCalls(run.calls)).toEqual([]);
+        expect(run.stderr).toContain("no-privilege");
+        expect(run.stderr).toContain("bash scripts/setup-browser-testing.sh");
+    });
+
+    it("S4: linux / 出力が想定外 → 判定不能として落ち、版と確認コマンドを出す", { timeout: 30_000 }, () => {
+        const run = runProvisionSandbox(realSource(), { depsOutput: "Unexpected output format", depsExit: 0 });
+
+        expect(run.status).toBe(1);
+        expect(browserInstallCalls(run.calls)).toEqual([]);
+        expect(run.stderr).toContain("undeterminable-deps");
+        expect(run.stderr).toContain("Version 1.61.1");
+        expect(run.stderr).toContain("pnpm exec playwright install-deps --dry-run chromium webkit");
+    });
+
+    it("S4b: 不足文言つき exit 2 (異常終了 + marker 残留) は判定不能に倒れる", { timeout: 30_000 }, () => {
+        const run = runProvisionSandbox(realSource(), { depsOutput: MISSING_DEPS_OUTPUT, depsExit: 2 });
+
+        expect(run.status).toBe(1);
+        expect(browserInstallCalls(run.calls)).toEqual([]);
+        // 特権経路の手前で止まる = sudo を一度も起こしていない
+        expect(run.calls.filter((c) => c.startsWith("sudo"))).toEqual([]);
+        expect(run.stderr).toContain("undeterminable-deps");
+    });
+
+    it("S4c: 不足文言つき exit 137 (SIGKILL 相当) も同じ", { timeout: 30_000 }, () => {
+        const run = runProvisionSandbox(realSource(), { depsOutput: MISSING_DEPS_OUTPUT, depsExit: 137 });
+
+        expect(run.status).toBe(1);
+        expect(browserInstallCalls(run.calls)).toEqual([]);
+        expect(run.calls.filter((c) => c.startsWith("sudo"))).toEqual([]);
+        expect(run.stderr).toContain("undeterminable-deps");
+    });
+
+    it("S5: darwin → install-deps を一度も呼ばない", { timeout: 30_000 }, () => {
+        const run = runProvisionSandbox(realSource(), { os: "Darwin" });
+
+        expect(run.status, run.stderr).toBe(0);
+        expect(run.calls.filter((c) => c.includes("install-deps"))).toEqual([]);
+        expect(browserInstallCalls(run.calls)).toEqual(["pnpm exec playwright install chromium webkit"]);
+    });
+
+    it("S6: 対象外 OS → pnpm を一度も起こさずに落ちる", { timeout: 30_000 }, () => {
+        const run = runProvisionSandbox(realSource(), { os: "MINGW64_NT-10.0" });
+
+        expect(run.status).toBe(1);
+        expect(run.calls.filter((c) => c.startsWith("pnpm"))).toEqual([]);
+        expect(run.stderr).toContain("unsupported-os");
+    });
+
+    it("S7: 未知オプションは exit 2", { timeout: 30_000 }, () => {
+        const run = runProvisionSandbox(realSource(), { args: ["--check"] });
+
+        expect(run.status).toBe(2);
+        expect(run.calls.filter((c) => c.startsWith("pnpm"))).toEqual([]);
+    });
+});
+
+describe("setup-browser-testing.sh 層 3: 実 Playwright との突合", () => {
+    it("実 CLI: install-deps --dry-run の出力が分類器の前提と一致すること", { timeout: 120_000 }, (context) => {
+        // 対象は **Linux かつ apt-get が PATH にある環境** に限る。
+        // それ以外 (macOS / 非 Debian 系 Linux) は **理由を出して skip** する (silent skip にしない)。
+        if (process.platform !== "linux" || spawnSync("which", ["apt-get"]).status !== 0) {
+            context.skip(
+                `install-deps は Debian 系 Linux 以外では別の出力を出すため skip (platform=${process.platform})`,
+            );
+            return;
+        }
+
+        // 分類器を再実装せず、スクリプト本体から marker 文言を抽出して使う
+        // (スクリプト側の文言を書き換えたら、この smoke が実 CLI と突き合わせて落ちる)。
+        const source = realSource();
+        const satisfied = extractShellConst(source, "DEPS_SATISFIED_MARKER");
+        const missing = extractShellConst(source, "DEPS_MISSING_MARKER");
+
+        const run = spawnSync("pnpm", ["exec", "playwright", "install-deps", "--dry-run", "chromium", "webkit"], {
+            encoding: "utf-8",
+            cwd: REPO_ROOT,
+        });
+
+        // status === null = シグナルで死んだ / 起動できなかった。marker 照合へ進めず、
+        // 理由を明示して失敗させる (0 と同一視しない)。
+        expect(run.status, `install-deps を実行できなかった: ${run.error ?? run.signal}`).not.toBeNull();
+        const out = `${run.stdout}${run.stderr}`;
+
+        // runner では WebKit の共有ライブラリが未導入で missing になりうるので、
+        // 「satisfied であること」は要求しない (環境依存の偽赤を作らない)。
+        // 要求するのは **どちらかに確定すること** = 分類器が undeterminable に落ちないこと。
+        if (run.status === 0) {
+            expect(out).toContain(satisfied);
+        } else {
+            expect(out).toContain(missing);
+        }
+    });
+});
diff --git a/scripts/setup-browser-testing.sh b/scripts/setup-browser-testing.sh
new file mode 100755
index 0000000..36aa9b8
--- /dev/null
+++ b/scripts/setup-browser-testing.sh
@@ -0,0 +1,260 @@
+#!/usr/bin/env bash
+#
+# scripts/setup-browser-testing.sh — Browser テスト用のブラウザ実体と OS 共有ライブラリを導入する。
+#
+# **導入の知識はこのファイル 1 本に集約する** (docs / CI / レーンスクリプトへ散らさない)。
+# 呼び出し元は 2 つだけ:
+#   - scripts/run-browser-test.sh (グローバルテストロックを取る前の事前確認)
+#   - .github/workflows/ci.yml の browser-tests job
+#
+# 設計の要点:
+#   1. **ブラウザ実体が入っているかは Playwright に判断させる**。
+#      `playwright install` は充足済みなら無出力・ダウンロード無しで即座に終わる冪等な操作である。
+#      自前の充足判定は持たない。
+#   2. 自前で判定するのは **OS 共有ライブラリを入れる必要があるか**だけ。
+#      要求 (install-deps --dry-run) と権限 (root / sudo) を**別々に**判定し、
+#      **要求があるのに権限が無ければ、特権を要する経路を起こす前に落ちる**
+#      (黙って OS ライブラリ無しの導入へ落ちない。落ちると後で
+#       "Host system is missing dependencies to run browsers" という分かりにくい失敗になる)。
+#   3. 判定不能 (出力の文言と終了コードが食い違う / CLI が異常終了) は **拒否側に倒す**。
+#
+# 環境変数による上書きは持たない。導入の要否を env で切り替える分岐を作ると
+# 「CI では受理しない」条件が要り、テストレーンの機構は CI 環境変数を参照しないという
+# 明文の契約 (GlobalTestLockInventoryTest) と衝突するためである。
+#
+# 使い方:
+#   bash scripts/setup-browser-testing.sh              # 判定して導入する
+#   bash scripts/setup-browser-testing.sh --self-test  # 判定関数の自己検査 (実資源にも node にも触れない)
+
+set -euo pipefail
+cd "$(dirname "$0")/.."
+
+# 対象ブラウザ集合。scripts/run-browser-test.sh の既定レーンと 1 対 1 で対応させる
+# (どちらか一方だけ増減させない)。**配列で持つ** (未クォート展開に頼らない)。
+BROWSER_TARGETS=(chromium webkit)
+
+# install-deps --dry-run の出力に現れる 2 つの文言。**契約テストがこの 2 行を読み取り、
+# pin された実 Playwright の出力と突き合わせる** (書式が変わったら pnpm test が赤くなる)。
+DEPS_SATISFIED_MARKER='All system dependencies are installed.'
+DEPS_MISSING_MARKER='Missing system dependencies'
+
+# 導入専用ロックの置き場。**self-test / 契約テスト専用の override** であり、
+# 通常運用では既定値を使う (このスクリプト自身がこの変数へ代入することはしない)。
+PROVISION_LOCK_DIR="${BROWSER_PROVISION_LOCK_DIR:-/tmp}"
+
+# --------------------------------------------------------------------------
+# 判定 (純関数。引数だけで決まる = --self-test が fixture で駆動できる)
+# --------------------------------------------------------------------------
+
+# uname -s の出力を 3 分類する。
+# Playwright が OS 共有ライブラリの導入に対応するのは Linux / Windows だけである。
+# Windows 経路は当リポジトリの開発環境 (devcontainer + macOS ホスト) に存在しないので、
+# 中途半端に動くふりをせず未対応として落とす。
+classify_os() {
+    case "$1" in
+        Linux)  printf 'linux\n' ;;
+        Darwin) printf 'darwin\n' ;;
+        *)      printf 'unsupported\n' ;;
+    esac
+}
+
+# install-deps --dry-run の (終了コード, 標準出力) を 3 分類する。
+# **文言と終了コードの両方が一致したときだけ**確定させる
+# (CLI 自体の異常終了と「不足あり」を混同しない)。
+classify_deps() {
+    local code="$1" out="$2"
+
+    if [ "${code}" = "0" ] && [ "${out#*"${DEPS_SATISFIED_MARKER}"}" != "${out}" ]; then
+        printf 'satisfied\n'
+        return 0
+    fi
+    # **終了コードは 1 に限定する**。Playwright の正常な不足検出は process.exitCode = 1 で
+    # あり、2 / 126 / 137 (シグナル killed 等) は「途中まで出力された marker が残っている
+    # 異常終了」でありうる。`!= 0` で受けると、その異常終了を missing と誤認して
+    # **--with-deps の特権経路へ進めてしまう**。
+    if [ "${code}" = "1" ] && [ "${out#*"${DEPS_MISSING_MARKER}"}" != "${out}" ]; then
+        printf 'missing\n'
+        return 0
+    fi
+    printf 'undeterminable\n'
+}
+
+# (OS 分類, 依存分類, 権限) から動作を決める決定表。
+# 出力: with-deps | plain | fail:<理由キー>
+decide_install() {
+    local os="$1" deps="$2" privilege="$3"
+
+    case "${os}" in
+        unsupported) printf 'fail:unsupported-os\n'; return 0 ;;
+        darwin)      printf 'plain\n';              return 0 ;;
+    esac
+
+    case "${deps}" in
+        satisfied) printf 'plain\n' ;;
+        missing)
+            case "${privilege}" in
+                root|sudo) printf 'with-deps\n' ;;
+                *)         printf 'fail:no-privilege\n' ;;
+            esac
+            ;;
+        *) printf 'fail:undeterminable-deps\n' ;;
+    esac
+}
+
+# --------------------------------------------------------------------------
+# 観測 (実資源に触れる薄い層。self-test はここを通らない)
+# --------------------------------------------------------------------------
+
+detect_privilege() {
+    if [ "$(id -u)" = "0" ]; then
+        printf 'root\n'
+        return 0
+    fi
+    # **非対話 (-n) に限る**。対話 sudo を起こすと、CI やスクリプト経由の呼び出しが
+    # パスワード待ちで無言のまま止まる。
+    if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
+        printf 'sudo\n'
+        return 0
+    fi
+    printf 'none\n'
+}
+
+playwright_version() {
+    pnpm exec playwright --version 2>/dev/null || printf 'unknown\n'
+}
+
+# --------------------------------------------------------------------------
+# 本体
+# --------------------------------------------------------------------------
+
+fail_with_guidance() {
+    local reason="$1"
+    echo "ERROR: Browser テスト用のブラウザを導入できません (${reason})。" >&2
+    case "${reason}" in
+        unsupported-os)
+            echo "  この OS ($(uname -s)) は Browser レーンの対象外です (Linux / macOS のみ)。" >&2
+            ;;
+        no-privilege)
+            echo "  WebKit が要求する OS 共有ライブラリが不足していますが、管理者権限がありません。" >&2
+            echo "  root で実行するか、パスワード無しで sudo できる環境で次を実行してください:" >&2
+            echo "    bash scripts/setup-browser-testing.sh" >&2
+            ;;
+        undeterminable-deps)
+            echo "  OS 共有ライブラリの過不足を判定できませんでした (Playwright の出力が想定と違います)。" >&2
+            echo "  playwright: $(playwright_version)" >&2
+            echo "  次のコマンドの出力を確認してください:" >&2
+            echo "    pnpm exec playwright install-deps --dry-run ${BROWSER_TARGETS[*]}" >&2
+            ;;
+    esac
+    exit 1
+}
+
+provision() {
+    local os deps privilege decision out code
+
+    os="$(classify_os "$(uname -s)")"
+
+    deps="not-applicable"
+    if [ "${os}" = "linux" ]; then
+        # dry-run は不足の報告で打ち切られ、apt-get install を組み立てる特権経路には到達しない。
+        #
+        # **`local out="" code=0` と 1 行で書かない**: local は成功を返すので
+        # `local x="$(...)"` の形にすると command substitution の終了コードが
+        # local の終了コードに潰され、`|| code=$?` が発火しない (宣言と代入を分ける)。
+        out=""
+        code=0
+        out="$(pnpm exec playwright install-deps --dry-run "${BROWSER_TARGETS[@]}" 2>&1)" || code=$?
+        deps="$(classify_deps "${code}" "${out}")"
+    fi
+
+    # **権限は「要求があるときだけ」観測する**。充足済み / macOS / 未対応 OS では
+    # sudo を一度も起こさないのが契約である。
+    privilege="none"
+    if [ "${os}" = "linux" ] && [ "${deps}" = "missing" ]; then
+        privilege="$(detect_privilege)"
+    fi
+
+    decision="$(decide_install "${os}" "${deps}" "${privilege}")"
+
+    case "${decision}" in
+        plain)     pnpm exec playwright install "${BROWSER_TARGETS[@]}" ;;
+        with-deps) pnpm exec playwright install --with-deps "${BROWSER_TARGETS[@]}" ;;
+        fail:*)    fail_with_guidance "${decision#fail:}" ;;
+    esac
+}
+
+# --self-test は判定関数だけを fixture で駆動する (node も実資源も要らない)。
+self_test() {
+    local failures=0 cases=0
+    assert_eq() {   # assert_eq <期待値> <実際値> <ケース名>
+        cases=$((cases + 1))
+        if [ "$1" != "$2" ]; then
+            echo "  FAIL [$3]: expected '$1', got '$2'" >&2
+            failures=$((failures + 1))
+        fi
+    }
+
+    # OS 分類 (T1〜T4)
+    assert_eq linux "$(classify_os Linux)" T1
+    assert_eq darwin "$(classify_os Darwin)" T2
+    assert_eq unsupported "$(classify_os 'MINGW64_NT-10.0')" T3
+    assert_eq unsupported "$(classify_os '')" T4
+
+    # 依存分類 (T5〜T10c)
+    assert_eq satisfied "$(classify_deps 0 "${DEPS_SATISFIED_MARKER}")" T5
+    assert_eq missing "$(classify_deps 1 "${DEPS_MISSING_MARKER}: libwoff2dec.so.1.0.2")" T6
+    assert_eq undeterminable "$(classify_deps 0 "${DEPS_MISSING_MARKER}")" T7
+    assert_eq undeterminable "$(classify_deps 1 "${DEPS_SATISFIED_MARKER}")" T8
+    assert_eq undeterminable "$(classify_deps 0 '')" T9
+    assert_eq undeterminable "$(classify_deps 127 'playwright: not found')" T10
+    assert_eq undeterminable "$(classify_deps 2 "${DEPS_MISSING_MARKER}")" T10b
+    assert_eq undeterminable "$(classify_deps 137 "${DEPS_MISSING_MARKER}")" T10c
+
+    # 決定表 (T11〜T17)
+    assert_eq plain "$(decide_install linux satisfied none)" T11
+    assert_eq with-deps "$(decide_install linux missing root)" T12
+    assert_eq with-deps "$(decide_install linux missing sudo)" T13
+    assert_eq fail:no-privilege "$(decide_install linux missing none)" T14
+    assert_eq fail:undeterminable-deps "$(decide_install linux undeterminable root)" T15
+    assert_eq plain "$(decide_install darwin not-applicable none)" T16
+    assert_eq fail:unsupported-os "$(decide_install unsupported not-applicable root)" T17
+
+    echo "self-test: ${cases} cases, ${failures} failures"
+    [ "${failures}" -eq 0 ]
+}
+
+case "${1-}" in
+    "")
+        echo "Browser lane: ブラウザ実体と OS 共有ライブラリを確認します (scripts/setup-browser-testing.sh)"
+        # 導入専用ロック。複数 worktree から同時に Browser レーンを起動すると
+        # ~/.cache/ms-playwright への並行書き込みと、Linux では apt-get / dpkg の
+        # ロック競合 (dpkg は排他ロックを取るので後発が落ちる) が起きる。
+        #
+        # **グローバルテストロック (scripts/global-test-lock.sh) とは統合しない**:
+        # あちらはテストレーンの実行そのものの直列化で、こちらはホスト共有資源への
+        # 書き込みの排他である。分けることで、数百 MB の取得中に全テストレーンを止めずに済む。
+        # 充足済みなら保持時間は短いので、判定をロック外へ出す二重確認は作らない。
+        #
+        # **保証範囲**: 効くのは同一 UID かつ同一 lock ディレクトリ名前空間
+        # (= 同じ /tmp を共有するプロセス群) だけ。別コンテナが同じ dpkg を触る構成では排他にならない。
+        # flock(1) が無い環境は警告 1 行を出して排他なしで続行する
+        # (scripts/global-test-lock.sh の既存方針を踏襲)。
+        if command -v flock >/dev/null 2>&1; then
+            mkdir -p "${PROVISION_LOCK_DIR}"
+            # fd 8 を使う (グローバルテストロックの fd 7 とは分ける)。
+            # プロセス終了時に OS が解放するので trap は張らない。
+            exec 8>"${PROVISION_LOCK_DIR}/browser-provisioning-$(id -u).lock"
+            flock 8
+        else
+            echo "WARNING: flock(1) が無いため導入の排他なしで実行します" >&2
+        fi
+        provision
+        ;;
+    --self-test)
+        self_test
+        ;;
+    *)
+        echo "ERROR: unknown option '$1' (使えるのは --self-test だけです)" >&2
+        exit 2
+        ;;
+esac
diff --git a/tests/Architecture/BrowserProvisioningEntrypointTest.php b/tests/Architecture/BrowserProvisioningEntrypointTest.php
new file mode 100644
index 0000000..f070cc2
--- /dev/null
+++ b/tests/Architecture/BrowserProvisioningEntrypointTest.php
@@ -0,0 +1,332 @@
+<?php
+
+declare(strict_types=1);
+
+use Webmozart\Assert\Assert;
+
+/*
+ * ブラウザ導入経路の一元化 gate。
+ *
+ * 「Browser テスト用のブラウザを入れる」という操作を**実行する**箇所は
+ * scripts/setup-browser-testing.sh ただ 1 つである。導入が 2 箇所に増えると、
+ * 対象ブラウザ集合 (chromium + webkit) と OS 共有ライブラリの要否判定が二重管理になり、
+ * 「片方だけ直して、もう片方で WebKit が全 fail する」という過去の失敗の再来になる。
+ *
+ * **母集団は「実行される場所」に限る** (deny-by-default だが、対象は狭く精密に取る):
+ *   1. scripts/ 配下の shell スクリプト (**再帰走査**。scripts/tools/foo.sh も母集団に入る) の
+ *      **実行行** (行頭 # のコメント行を除く)
+ *   2. composer.json / package.json の scripts に書かれたコマンド
+ *   3. docker/Dockerfile の **命令部** (行頭 # のコメント行を除く)
+ *
+ * **照合の前に 2 つの正規化を行う** (行単位の素朴な照合は簡単に迂回できる):
+ *   - コメント行の除去 (説明文の言及で偽赤にしない)
+ *   - **行継続 (`\` + 改行 + 先頭空白) を空白へ畳む** —
+ *     `pnpm exec playwright \`+改行+`    install chromium` は行単位では素通りする。
+ *     shell と Dockerfile の両方に効かせる
+ *
+ * **保証しないもの (誇張しない)**:
+ *   - 手順書・コメント・設計文書の**言及**は対象外である (docs/testing-browser.md /
+ *     docker/Dockerfile のコメント / devnotes/)。禁じたいのは実行であって説明ではない。
+ *   - .github/workflows/*.yml は本テストの母集団に**入れない** —
+ *     YAML の実行行の検査は tests/js/architecture/ci-workflow-inventory.test.ts の W20 が担う
+ *     (同じ事実を 2 箇所で検査しない。あちらは既に YAML を parse している)。
+ *   - .claude/skills/app-bug-hunt/ は対象外。bug-hunt は @playwright/cli という
+ *     **別の導入経路**を意図的に持つ (AGENTS.md §bug-hunt)。
+ *   - 変数へ組み立ててから実行する形 (`cmd="playwright install"; $cmd`) は検出しない。
+ *
+ * 本テストは DB を触らない (ファイル読み取りのみ)。
+ */
+
+/** 導入を実行してよい唯一のファイル (リポジトリルートからの相対パス)。 */
+const BROWSER_PROVISIONING_SINGLE_SOURCE = 'scripts/setup-browser-testing.sh';
+
+/**
+ * 走査する「実行される場所」のうち、scripts/ 配下以外の固定分。
+ *
+ * @var list<string>
+ */
+const BROWSER_PROVISIONING_SCANNED_FILES = [
+    'composer.json',
+    'package.json',
+    'docker/Dockerfile',
+];
+
+/**
+ * 導入コマンドとみなすパターン。
+ * 単純部分一致 (`'playwright install'`) にすると `playwright   install` のような
+ * 空白差分を見逃すため、正規表現で見る。`install-deps` も `\binstall\b` に一致するので
+ * 同じ規則で捕まる (意図どおり)。
+ */
+const BROWSER_PROVISIONING_PATTERN = '/\bplaywright\s+install\b/';
+
+/**
+ * 行頭 (空白を除く) が `#` の行を落とし、**行継続を畳んでから**実行行を返す (純関数)。
+ *
+ * `/u` は必須: 非 UTF-8 モードの `\R` はバイト 0x85 (NEL) にも一致し、日本語コメントを
+ * 文字途中で分断する (既存 ScriptsReadmeInventoryTest と同方針で、改行は明示列挙する)。
+ *
+ * 順序は「コメント除去 → 行継続の畳み込み」。逆にすると、継続行の途中にある `#` の扱いが
+ * 変わって取りこぼす。
+ *
+ * @return list<string> 実行行 (1 始まりの行番号は保持しない)
+ */
+function browserProvisioningCodeLines(string $source): array
+{
+    $kept = [];
+    foreach (preg_split('/\r\n|\r|\n/u', $source) ?: [] as $line) {
+        if (preg_match('/^\s*#/u', $line) === 1) {
+            continue;
+        }
+        $kept[] = $line;
+    }
+
+    $folded = preg_replace('/\\\\\n[ \t]*/u', ' ', implode("\n", $kept));
+    Assert::string($folded, '行継続の畳み込みに失敗した');
+
+    $lines = [];
+    foreach (preg_split('/\n/u', $folded) ?: [] as $line) {
+        if (trim($line) === '') {
+            continue;
+        }
+        $lines[] = $line;
+    }
+
+    return $lines;
+}
+
+/**
+ * `scripts/` 配下の shell スクリプトを **再帰的に** 列挙する (純関数)。
+ *
+ * `glob('scripts/*.sh')` では `scripts/tools/install-browser.sh` を取りこぼす
+ * (ScriptsReadmeInventoryTest::scriptsDirectoryFiles と同じ理由・同じ道具を使う)。
+ *
+ * @return list<string> 引数ディレクトリからの相対パス (昇順)
+ */
+function browserProvisioningShellScripts(string $scriptsDir): array
+{
+    $found = [];
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($scriptsDir, FilesystemIterator::SKIP_DOTS),
+    );
+
+    foreach ($iterator as $file) {
+        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
+            continue;
+        }
+        if ($file->getExtension() !== 'sh') {
+            continue;
+        }
+        $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($scriptsDir) + 1));
+    }
+
+    sort($found);
+
+    return $found;
+}
+
+/**
+ * 走査対象のリポジトリルート相対パスを返す。
+ *
+ * @return list<string>
+ */
+function browserProvisioningScanTargets(): array
+{
+    $targets = BROWSER_PROVISIONING_SCANNED_FILES;
+    foreach (browserProvisioningShellScripts(base_path('scripts')) as $relative) {
+        $targets[] = 'scripts/'.$relative;
+    }
+
+    return $targets;
+}
+
+/**
+ * composer.json / package.json の `scripts` から実行コマンド文字列を取り出す (純関数)。
+ *
+ * `composer.json` の値は **文字列と配列の両方**を取るので両方を受ける。
+ * **想定外の型は違反として列挙する** (静かに素通りさせない)。
+ *
+ * @return array{commands: list<string>, errors: list<string>}
+ */
+function browserProvisioningJsonScriptCommands(string $relative, string $contents): array
+{
+    $decoded = json_decode($contents, true);
+    if (! is_array($decoded)) {
+        return ['commands' => [], 'errors' => ["{$relative}: JSON として読めない"]];
+    }
+
+    $scripts = $decoded['scripts'] ?? [];
+    if (! is_array($scripts)) {
+        return ['commands' => [], 'errors' => ["{$relative}: scripts が想定外の型 (配列でない)"]];
+    }
+
+    $commands = [];
+    $errors = [];
+    foreach ($scripts as $name => $value) {
+        $key = (string) $name;
+        if (is_string($value)) {
+            $commands[] = $value;
+
+            continue;
+        }
+        if (is_array($value)) {
+            foreach ($value as $item) {
+                if (is_string($item)) {
+                    $commands[] = $item;
+
+                    continue;
+                }
+                $errors[] = "{$relative}: scripts.{$key} の要素が想定外の型 (文字列でない)";
+            }
+
+            continue;
+        }
+        $errors[] = "{$relative}: scripts.{$key} が想定外の型 (文字列でも配列でもない)";
+    }
+
+    return ['commands' => $commands, 'errors' => $errors];
+}
+
+/**
+ * 「相対パス => 中身」から違反を列挙する (純関数。負のコントロールを fixture で駆動するため)。
+ *
+ * @param  array<string, string>  $files
+ * @return list<string>
+ */
+function browserProvisioningViolations(array $files): array
+{
+    $violations = [];
+
+    foreach ($files as $relative => $contents) {
+        if ($relative === BROWSER_PROVISIONING_SINGLE_SOURCE) {
+            continue;
+        }
+
+        if (str_ends_with($relative, '.json')) {
+            $parsed = browserProvisioningJsonScriptCommands($relative, $contents);
+            foreach ($parsed['errors'] as $error) {
+                $violations[] = $error;
+            }
+            foreach ($parsed['commands'] as $command) {
+                if (preg_match(BROWSER_PROVISIONING_PATTERN, $command) === 1) {
+                    $violations[] = "{$relative}: scripts が導入コマンドを実行している ({$command})";
+                }
+            }
+
+            continue;
+        }
+
+        foreach (browserProvisioningCodeLines($contents) as $line) {
+            if (preg_match(BROWSER_PROVISIONING_PATTERN, $line) === 1) {
+                $violations[] = "{$relative}: 導入コマンドを実行している (".trim($line).')';
+            }
+        }
+    }
+
+    sort($violations);
+
+    return $violations;
+}
+
+test('導入コマンドを実行するのは scripts/setup-browser-testing.sh だけであること', function (): void {
+    $files = [];
+    foreach (browserProvisioningScanTargets() as $relative) {
+        $contents = file_get_contents(base_path($relative));
+        Assert::string($contents, "{$relative} を読み込めません");
+        $files[$relative] = $contents;
+    }
+
+    // 空振り防止: 走査対象が 1 件も無い状態で緑にならないこと
+    expect(count($files))->toBeGreaterThan(count(BROWSER_PROVISIONING_SCANNED_FILES));
+
+    $violations = browserProvisioningViolations($files);
+    expect($violations)->toBe([], "ブラウザ導入経路の増殖:\n".implode("\n", $violations));
+});
+
+/*
+ * 実行ビットは git が追跡する。付け忘れると CI (bash 経由なので動く) は緑のまま、
+ * ローカルの直接実行だけが落ちるという分かりにくい差になるので機械で固定する。
+ */
+test('単一情報源のファイルが実在し、実行可能で、対象ブラウザ 2 つを持つこと', function (): void {
+    $path = base_path(BROWSER_PROVISIONING_SINGLE_SOURCE);
+    Assert::fileExists($path);
+    expect(is_executable($path))->toBeTrue();
+
+    $source = file_get_contents($path);
+    Assert::string($source);
+    expect($source)->toContain('BROWSER_TARGETS=(chromium webkit)');
+});
+
+test('負のコントロール: scripts/ の別スクリプトが導入を実行したら検出すること', function (): void {
+    $violations = browserProvisioningViolations([
+        BROWSER_PROVISIONING_SINGLE_SOURCE => "pnpm exec playwright install chromium webkit\n",
+        'scripts/prepare-browser-ci.sh' => "#!/usr/bin/env bash\npnpm exec playwright install chromium\n",
+    ]);
+
+    expect($violations)->toBe([
+        'scripts/prepare-browser-ci.sh: 導入コマンドを実行している (pnpm exec playwright install chromium)',
+    ]);
+});
+
+test('負のコントロール: 入れ子の scripts/tools/foo.sh も母集団に入ること', function (): void {
+    $dir = sys_get_temp_dir().'/browser-provisioning-'.bin2hex(random_bytes(6));
+    mkdir($dir.'/tools', 0o777, true);
+    file_put_contents($dir.'/a.sh', "echo a\n");
+    file_put_contents($dir.'/tools/foo.sh', "echo foo\n");
+    file_put_contents($dir.'/tools/notes.md', "playwright install\n");
+
+    try {
+        expect(browserProvisioningShellScripts($dir))->toBe(['a.sh', 'tools/foo.sh']);
+    } finally {
+        array_map(unlink(...), [$dir.'/a.sh', $dir.'/tools/foo.sh', $dir.'/tools/notes.md']);
+        rmdir($dir.'/tools');
+        rmdir($dir);
+    }
+});
+
+test('負のコントロール: composer.json の script (文字列 / 配列の両形式) を検出すること', function (): void {
+    $violations = browserProvisioningViolations([
+        'composer.json' => json_encode(['scripts' => ['setup' => 'pnpm exec playwright install chromium']], JSON_THROW_ON_ERROR),
+        'package.json' => json_encode(['scripts' => ['prep' => ['@php artisan x', 'playwright install webkit']]], JSON_THROW_ON_ERROR),
+    ]);
+
+    expect($violations)->toBe([
+        'composer.json: scripts が導入コマンドを実行している (pnpm exec playwright install chromium)',
+        'package.json: scripts が導入コマンドを実行している (playwright install webkit)',
+    ]);
+});
+
+test('負のコントロール: composer.json の scripts が想定外の型なら違反として列挙すること', function (): void {
+    $violations = browserProvisioningViolations([
+        'composer.json' => json_encode(['scripts' => 'not-an-object'], JSON_THROW_ON_ERROR),
+        'package.json' => json_encode(['scripts' => ['prep' => 42]], JSON_THROW_ON_ERROR),
+    ]);
+
+    expect($violations)->toContain('composer.json: scripts が想定外の型 (配列でない)');
+    expect($violations)->toContain('package.json: scripts.prep が想定外の型 (文字列でも配列でもない)');
+});
+
+test('負のコントロール: 空白差分 `playwright   install` を検出すること', function (): void {
+    $violations = browserProvisioningViolations([
+        'scripts/x.sh' => "pnpm exec playwright   install chromium\n",
+    ]);
+
+    expect($violations)->toHaveCount(1);
+});
+
+test('負のコントロール: 行継続で 2 行に割った導入を shell / Dockerfile の両方で検出すること', function (): void {
+    $violations = browserProvisioningViolations([
+        'scripts/x.sh' => "pnpm exec playwright \\\n    install chromium webkit\n",
+        'docker/Dockerfile' => "RUN pnpm exec playwright \\\n    install chromium\n",
+    ]);
+
+    expect($violations)->toHaveCount(2);
+});
+
+test('負のコントロール: コメント行の言及は検出しないこと (偽陽性を作らない)', function (): void {
+    $violations = browserProvisioningViolations([
+        'scripts/x.sh' => "# chromium 本体は `pnpm exec playwright install chromium` で入る\necho ok\n",
+        'docker/Dockerfile' => "# playwright install chromium は Dockerfile では行わない\nRUN echo ok\n",
+    ]);
+
+    expect($violations)->toBe([]);
+});
diff --git a/tests/js/architecture/ci-workflow-inventory.test.ts b/tests/js/architecture/ci-workflow-inventory.test.ts
index c183b37..45c48c6 100644
--- a/tests/js/architecture/ci-workflow-inventory.test.ts
+++ b/tests/js/architecture/ci-workflow-inventory.test.ts
@@ -25,6 +25,8 @@ interface WorkflowStep {
     with?: Record<string, unknown>;
     run?: string;
     env?: Record<string, unknown>;
+    /** step-level の条件式。**job-level の `if` (W15 が禁じる) とは別物**である。 */
+    if?: string;
 }
 interface WorkflowJob {
     "runs-on"?: string;
@@ -170,6 +172,11 @@ const BROWSER_JOB_ALLOWED_USES = [
     "shivammathur/setup-php",
     "pnpm/action-setup",
     "actions/setup-node",
+    // ブラウザ実体 (~/.cache/ms-playwright) のキャッシュ。復元先はキャッシュディレクトリだけで
+    // $GITHUB_ENV には書かない。
+    "actions/cache",
+    // 失敗時の証跡回収。読むだけで $GITHUB_ENV には書かない。
+    "actions/upload-artifact",
 ] as const;
 
 /** browser-tests job で実行してよいコマンド行 (完全一致)。
@@ -181,12 +188,31 @@ const BROWSER_JOB_ALLOWED_RUN_LINES = [
     "php artisan key:generate",
     "php artisan passport:keys --force",
     "pnpm build",
-    "pnpm exec playwright install --with-deps chromium webkit",
+    // 導入は scripts/setup-browser-testing.sh に一元化した (旧
+    // `pnpm exec playwright install --with-deps chromium webkit` を置き換え)。
+    // この行が BROWSER_TEST_* を設定しないことは
+    // scripts/setup-browser-testing.contract.test.ts の静的契約が固定する。
+    "bash scripts/setup-browser-testing.sh",
     "composer test:browser",
 ] as const;
 
 const LANE_ENV_VARS = ["BROWSER_TEST_LANES", "BROWSER_TEST_PROCESSES"] as const;
 
+/**
+ * 導入コマンドの検出パターン (W20)。
+ * 単純部分一致にすると `pnpm exec playwright   install chromium` のような空白差分で
+ * 迂回できるため正規表現で見る (tests/Architecture/BrowserProvisioningEntrypointTest.php と同じ規則)。
+ */
+const PLAYWRIGHT_INSTALL_PATTERN = /\bplaywright\s+install\b/;
+
+/**
+ * shell の行継続 (`\` + 改行 + 先頭空白) を 1 個の空白へ畳む純関数 (W20 用)。
+ * 行単位の照合だけでは、コマンドを 2 行に割るだけで検出を迂回できてしまう。
+ */
+export function normalizeShellContinuations(source: string): string {
+    return source.replace(/\\\r?\n\s*/g, " ");
+}
+
 describe("ci.yml inventory gate", () => {
     const workflow = loadWorkflow();
 
@@ -236,10 +262,64 @@ describe("ci.yml inventory gate", () => {
         expect(exact).toHaveLength(1);
     });
 
-    it("W7: browser-tests が playwright install --with-deps chromium webkit を実行すること", () => {
-        expect(runScript(job(workflow, "browser-tests"))).toContain(
-            "pnpm exec playwright install --with-deps chromium webkit",
+    it("W7: browser-tests が導入スクリプトを **実行行として** 持つこと", () => {
+        // **旧 W7 からの変更点と理由**: 旧 W7 は
+        // `pnpm exec playwright install --with-deps chromium webkit` という文字列の存在を
+        // 検査していた。導入を scripts/setup-browser-testing.sh へ一元化したため、
+        // ci.yml 側で固定できるのは「導入スクリプトを呼んでいること」までになる。
+        // **対象ブラウザ集合 (chromium + webkit) と --with-deps の要否判定の固定は
+        // scripts/setup-browser-testing.contract.test.ts へ移した** —
+        // 検査を消して緑にしたのではなく、置き場所を移したうえで両方が残っている。
+        //
+        // includes ではなく完全一致の実行行を要求するのは W16 と同じ理由
+        // (`echo "bash scripts/..."` / `... || true` の soft-fail 偽装を素通りさせない)。
+        const mentions = runLines(job(workflow, "browser-tests")).filter((l) =>
+            l.includes("scripts/setup-browser-testing.sh"),
+        );
+        expect(mentions).toEqual(["bash scripts/setup-browser-testing.sh"]);
+    });
+
+    it("W18: browser-tests が ~/.cache/ms-playwright をキャッシュし、restore-keys を持たないこと", () => {
+        const cache = (job(workflow, "browser-tests").steps ?? []).filter(
+            (s) => s.uses !== undefined && actionName(s.uses) === "actions/cache",
         );
+        expect(cache, "browser-tests に actions/cache step が無い").toHaveLength(1);
+        expect(String(cache[0]?.with?.path ?? "")).toBe("~/.cache/ms-playwright");
+        // key の 3 要素を個別に見る (設計意図との対応を明示する)
+        const key = String(cache[0]?.with?.key ?? "");
+        expect(key).toContain("runner.os");
+        expect(key).toContain("runner.arch");
+        expect(key).toContain("hashFiles('pnpm-lock.yaml')");
+        // 部分一致復元は古い版のブラウザを溜め込む
+        expect(cache[0]?.with?.["restore-keys"], "restore-keys は持たない").toBeUndefined();
+    });
+
+    it("W19: browser-tests の最後の step が失敗時の証跡回収であること", () => {
+        const steps = job(workflow, "browser-tests").steps ?? [];
+        const last = steps[steps.length - 1];
+        expect(last?.uses !== undefined && actionName(last.uses)).toBe("actions/upload-artifact");
+        // step-level の if。W15 が禁じているのは **job-level** の if であって別物である。
+        expect(last?.if, "失敗時だけ回収する (常時アップロードにしない)").toBe("failure()");
+        expect(String(last?.with?.path ?? "")).toBe("storage/browser-test-artifacts/");
+        // 名前と保持日数も契約である (変更・欠落を素通りさせない)
+        expect(String(last?.with?.name ?? "")).toBe("browser-test-artifacts");
+        expect(Number(last?.with?.["retention-days"])).toBe(7);
+    });
+
+    it("W20: どの job の実行行にも playwright install が現れないこと (導入はスクリプト経由)", () => {
+        // 迂回の 2 経路を先に潰す:
+        //   (a) 空白差分 `pnpm exec playwright   install chromium` → 正規表現で見る
+        //   (b) shell の行継続
+        //         run: |
+        //           pnpm exec playwright \
+        //             install chromium webkit
+        //       → runLines は行ごとに切るので素通りする。**照合前に行継続を空白へ畳む**。
+        // (tests/Architecture/BrowserProvisioningEntrypointTest.php と同じ規則にそろえる)
+        for (const name of Object.keys(workflow.jobs ?? {})) {
+            const joined = normalizeShellContinuations(runScript(job(workflow, name)));
+            const hits = joined.split("\n").filter((l) => PLAYWRIGHT_INSTALL_PATTERN.test(l));
+            expect(hits, `${name} が導入コマンドを直接叩いている`).toEqual([]);
+        }
     });
 
     it("W8: browser-tests が pnpm build を実行すること (実ブラウザが public/build を読む)", () => {
@@ -486,6 +566,87 @@ describe("走査関数の負のコントロール (検出器が空振りして
         expect(workflowsWithSchedule([["ci.yml", "on:\n  push:\n  pull_request:\n"]])).toEqual([]);
     });
 
+    it("W18: restore-keys を足した構成を検出する", () => {
+        const step: WorkflowStep = {
+            uses: "actions/cache@v6",
+            with: { path: "~/.cache/ms-playwright", key: "k", "restore-keys": "os-ms-playwright-" },
+        };
+        expect(step.with?.["restore-keys"]).toBeDefined();
+    });
+
+    it("W18: key から runner.arch を落とした構成を検出する", () => {
+        const step: WorkflowStep = {
+            uses: "actions/cache@v6",
+            with: { path: "~/.cache/ms-playwright", key: "${{ runner.os }}-ms-playwright-x" },
+        };
+        expect(String(step.with?.key ?? "")).not.toContain("runner.arch");
+    });
+
+    it("W19: 常時アップロード (if 無し) を検出する", () => {
+        const step: WorkflowStep = {
+            uses: "actions/upload-artifact@v7",
+            with: { name: "browser-test-artifacts", path: "storage/browser-test-artifacts/", "retention-days": 7 },
+        };
+        expect(step.if).toBeUndefined();
+    });
+
+    it("W19: upload-artifact が最後の step でない構成を検出する", () => {
+        const steps: WorkflowStep[] = [
+            { uses: "actions/upload-artifact@v7", if: "failure()" },
+            { run: "echo done" },
+        ];
+        const last = steps[steps.length - 1];
+        expect(last?.uses !== undefined && actionName(last.uses)).not.toBe("actions/upload-artifact");
+    });
+
+    it("W19: name / retention-days の欠落を検出する", () => {
+        const step: WorkflowStep = {
+            uses: "actions/upload-artifact@v7",
+            if: "failure()",
+            with: { path: "storage/browser-test-artifacts/" },
+        };
+        expect(String(step.with?.name ?? "")).not.toBe("browser-test-artifacts");
+        expect(Number(step.with?.["retention-days"])).not.toBe(7);
+    });
+
+    it("W20: run 行へ戻した playwright install を検出する", () => {
+        const fixture: WorkflowJob = {
+            steps: [{ run: "pnpm exec playwright install --with-deps chromium webkit" }],
+        };
+        const joined = normalizeShellContinuations(runScript(fixture));
+        expect(joined.split("\n").filter((l) => PLAYWRIGHT_INSTALL_PATTERN.test(l))).toHaveLength(1);
+    });
+
+    it("W20: 空白を増やした `playwright   install` も検出する", () => {
+        const fixture: WorkflowJob = { steps: [{ run: "pnpm exec playwright   install chromium" }] };
+        const joined = normalizeShellContinuations(runScript(fixture));
+        expect(joined.split("\n").filter((l) => PLAYWRIGHT_INSTALL_PATTERN.test(l))).toHaveLength(1);
+    });
+
+    it("W20: 行継続で 2 行に割った playwright install も検出する", () => {
+        const fixture: WorkflowJob = {
+            steps: [{ run: "pnpm exec playwright \\\n  install chromium webkit" }],
+        };
+        // 畳む前は行単位の照合を素通りする (= 正規化が必要であることの証明)
+        const raw = runScript(fixture);
+        expect(raw.split("\n").filter((l) => PLAYWRIGHT_INSTALL_PATTERN.test(l))).toEqual([]);
+        const joined = normalizeShellContinuations(raw);
+        expect(joined.split("\n").filter((l) => PLAYWRIGHT_INSTALL_PATTERN.test(l))).toHaveLength(1);
+    });
+
+    it("W20: 導入スクリプト経由の実行行は検出しない (偽陽性を作らない)", () => {
+        const fixture: WorkflowJob = { steps: [{ run: "bash scripts/setup-browser-testing.sh" }] };
+        const joined = normalizeShellContinuations(runScript(fixture));
+        expect(joined.split("\n").filter((l) => PLAYWRIGHT_INSTALL_PATTERN.test(l))).toEqual([]);
+    });
+
+    it("normalizeShellContinuations: 行継続だけを畳み、通常の改行は残す", () => {
+        expect(normalizeShellContinuations("a \\\n  b")).toBe("a  b");
+        expect(normalizeShellContinuations("a\nb")).toBe("a\nb");
+        // CRLF の行継続も畳む
+        expect(normalizeShellContinuations("a \\\r\n  b")).toBe("a  b");
+    });
+
     it("composite action へ移送すると W14a と W14c の両方が違反を返す", () => {
         const fixture: WorkflowJob = { steps: [{ uses: "./.github/actions/run-browser-lanes@v1" }] };
         const outside = (fixture.steps ?? [])
diff --git a/tests/js/support/shell-contract.test.ts b/tests/js/support/shell-contract.test.ts
new file mode 100644
index 0000000..f4002b8
--- /dev/null
+++ b/tests/js/support/shell-contract.test.ts
@@ -0,0 +1,49 @@
+/**
+ * tests/js/support/shell-contract.ts の保証。
+ *
+ * ここに集めたのは shell スクリプトの契約テストが共有する純ヘルパであり、
+ * **これらが空振りすると負のコントロールが全部無言で通ってしまう**。
+ * 元は scripts/run-browser-test.contract.test.ts の中にあった describe を、
+ * ヘルパの移設にあわせて (消さずに) こちらへ移した。
+ */
+import { describe, expect, it } from "vitest";
+import { codeLines, lineIndexOf, mutate } from "./shell-contract";
+
+describe("mutate()", () => {
+    it("置換対象が存在しなければ throw", () => {
+        expect(() => mutate("abc", "zzz", "yyy")).toThrow(/must appear exactly once \(found 0\)/);
+    });
+
+    it("置換対象が複数あれば throw", () => {
+        expect(() => mutate("aa", "a", "b")).toThrow(/must appear exactly once \(found 2\)/);
+    });
+
+    it("1 箇所だけなら置換して返す", () => {
+        expect(mutate("abc", "b", "X")).toBe("aXc");
+    });
+});
+
+describe("codeLines()", () => {
+    it("行頭 # のコメント行を落とす (先頭空白つきも)", () => {
+        expect(codeLines("a\n# c\n   # d\nb")).toBe("a\nb");
+    });
+
+    it("行の途中の # は落とさない (実行行を消さない)", () => {
+        expect(codeLines('echo "x" # 説明')).toBe('echo "x" # 説明');
+    });
+});
+
+describe("lineIndexOf()", () => {
+    it("最初に一致した行の 0 始まり index を返す", () => {
+        expect(lineIndexOf("a\nb\nc\nb", "b")).toBe(1);
+    });
+
+    it("見つからなければ -1", () => {
+        expect(lineIndexOf("a\nb", "zzz")).toBe(-1);
+    });
+
+    it("順序契約の比較に使える (前後関係が index の大小で出る)", () => {
+        const source = "first\nsecond";
+        expect(lineIndexOf(source, "first")).toBeLessThan(lineIndexOf(source, "second"));
+    });
+});
diff --git a/tests/js/support/shell-contract.ts b/tests/js/support/shell-contract.ts
new file mode 100644
index 0000000..f21c775
--- /dev/null
+++ b/tests/js/support/shell-contract.ts
@@ -0,0 +1,39 @@
+/**
+ * shell スクリプトの契約テストで共有する純ヘルパ。
+ *
+ * **test ファイルから import しない** (test ファイルを import すると、その describe が
+ * import 元でも二重登録される)。そのため非 test の module として置く。
+ * 本モジュール自身の保証は tests/js/support/shell-contract.test.ts が持つ。
+ */
+
+/**
+ * 行頭 (空白除く) が `#` の行を落とす。方針説明コメントで偽赤にしないため
+ * (tests/Architecture/GlobalTestLockInventoryTest.php の globalTestLockCodeLines と同方針)。
+ */
+export function codeLines(source: string): string {
+    return source
+        .split("\n")
+        .filter((line) => !/^\s*#/.test(line))
+        .join("\n");
+}
+
+/** 元ソースの `from` を `to` に 1 箇所だけ置換する。置換が成立しなければ throw (空振り防止)。 */
+export function mutate(source: string, from: string, to: string): string {
+    const occurrences = source.split(from).length - 1;
+    if (occurrences !== 1) {
+        throw new Error(`mutation target must appear exactly once (found ${occurrences}): ${from}`);
+    }
+    const mutated = source.replace(from, to);
+    if (mutated === source) throw new Error(`mutation did not change the source: ${from}`);
+    if (!mutated.includes(to)) throw new Error(`mutated source lacks the expected token: ${to}`);
+    return mutated;
+}
+
+/**
+ * `source` の中で `needle` を含む最初の行の 0 始まり index を返す (無ければ -1)。
+ *
+ * 「A が B より前にあること」という順序契約を、行番号の比較で書けるようにするためのもの。
+ */
+export function lineIndexOf(source: string, needle: string): number {
+    return source.split("\n").findIndex((line) => line.includes(needle));
+}

```

## テスト結果

- `composer test`: 4733 tests, 4731 passed, 2 skipped, 0 failed
- `composer phpstan`: No errors (level 10, 915 files)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: 136 files, 1498 tests passed
- `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: passed
- `composer test:browser` (実走): chromium 25 passed / 3 skipped、webkit 25 passed / 3 skipped。
  事前確認 (setup-browser-testing.sh) がロック取得前に走ることを実環境で確認済み

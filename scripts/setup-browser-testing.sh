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
#      `playwright install` は充足済みなら無出力・ダウンロード無しで即座に終わる冪等な操作である。
#      自前の充足判定は持たない。
#   2. 自前で判定するのは **OS 共有ライブラリを入れる必要があるか**だけ。
#      要求 (install-deps --dry-run) と権限 (root / sudo) を**別々に**判定し、
#      **要求があるのに権限が無ければ、特権を要する経路を起こす前に落ちる**
#      (黙って OS ライブラリ無しの導入へ落ちない。落ちると後で
#       "Host system is missing dependencies to run browsers" という分かりにくい失敗になる)。
#   3. 判定不能 (出力の文言と終了コードが食い違う / CLI が異常終了) は **拒否側に倒す**。
#
# 環境変数による上書きは持たない。導入の要否を env で切り替える分岐を作ると
# 「CI では受理しない」条件が要り、テストレーンの機構は CI 環境変数を参照しないという
# 明文の契約 (GlobalTestLockInventoryTest) と衝突するためである。
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
    # **非対話 (-n) に限る**。対話 sudo を起こすと、CI やスクリプト経由の呼び出しが
    # パスワード待ちで無言のまま止まる。
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
        # dry-run は不足の報告で打ち切られ、apt-get install を組み立てる特権経路には到達しない。
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
    # sudo を一度も起こさないのが契約である。
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

    # OS 分類 (T1〜T4)
    assert_eq linux "$(classify_os Linux)" T1
    assert_eq darwin "$(classify_os Darwin)" T2
    assert_eq unsupported "$(classify_os 'MINGW64_NT-10.0')" T3
    assert_eq unsupported "$(classify_os '')" T4

    # 依存分類 (T5〜T10c)
    assert_eq satisfied "$(classify_deps 0 "${DEPS_SATISFIED_MARKER}")" T5
    assert_eq missing "$(classify_deps 1 "${DEPS_MISSING_MARKER}: libwoff2dec.so.1.0.2")" T6
    assert_eq undeterminable "$(classify_deps 0 "${DEPS_MISSING_MARKER}")" T7
    assert_eq undeterminable "$(classify_deps 1 "${DEPS_SATISFIED_MARKER}")" T8
    assert_eq undeterminable "$(classify_deps 0 '')" T9
    assert_eq undeterminable "$(classify_deps 127 'playwright: not found')" T10
    assert_eq undeterminable "$(classify_deps 2 "${DEPS_MISSING_MARKER}")" T10b
    assert_eq undeterminable "$(classify_deps 137 "${DEPS_MISSING_MARKER}")" T10c

    # 決定表 (T11〜T17)
    assert_eq plain "$(decide_install linux satisfied none)" T11
    assert_eq with-deps "$(decide_install linux missing root)" T12
    assert_eq with-deps "$(decide_install linux missing sudo)" T13
    assert_eq fail:no-privilege "$(decide_install linux missing none)" T14
    assert_eq fail:undeterminable-deps "$(decide_install linux undeterminable root)" T15
    assert_eq plain "$(decide_install darwin not-applicable none)" T16
    assert_eq fail:unsupported-os "$(decide_install unsupported not-applicable root)" T17

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
        # 充足済みなら保持時間は短いので、判定をロック外へ出す二重確認は作らない。
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

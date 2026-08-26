#!/usr/bin/env bash
#
# scripts/deploy.sh — Deployer によるアプリ配布の **単一入口**。
#
# usage: bash scripts/deploy.sh <host> [--check] [--allow-dirty] [--production]
#
#   <host>          deploy/hosts.yml で定義した host 名 (**引数必須**)
#   --check         前提チェックだけを行って exit 0 (deploy しない dry-run)
#   --allow-dirty   working tree が dirty でも続行する (Deployer は origin から clone するため
#                   未 commit の tracked 変更はデプロイに反映されない点に注意)
#   --production    本番 host への deploy であることの **人間の意思表示**。
#                   TTY + 算術確認ゲートを通ったときだけ `-o production_ack=1` を注入する
#
# 既定 host は **持たない**。テンプレートには「安全な既定 host」が存在しないため、
# 引数を省いた実行は usage を出して落とす (donor は既定を staging にしていたが、
# 既定を持つと「省略しても大丈夫」という誤った期待を配ることになる)。
#
# 本番判定の知識は **このスクリプトに持たせない**:
#   - 「どの host が本番か」は deploy/hosts.yml の `stage:` (= 座標。gitignore)
#   - 機械的な fail-closed 判定は deploy/deploy.php の `deploy:confirm-stage`。
#     ack 不足 / 逆方向の不一致 / **非 TTY** の 3 つを Deployer 側でも拒否するので、
#     このスクリプトを迂回した `dep deploy <prd> -o production_ack=1` 直叩きも止まる
#     (`production_ack` は誰でも渡せる公開 option なので、それ単体では人間の確認の証明にならない)
#   - ここの責務は **人間に問う**部分 (算術チャレンジ) と、副作用 (push) の前に
#     整合チェックを済ませること。TTY 判定は両層に置く (多層防御)
# host 名リテラルの配列 (donor の `PROD_HOSTS=(...)`) は持たない
# (テンプレートに他アプリの host 名が焼き付くため。DeployPipelineWiringTest W21 が pin)。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${REPO_ROOT}"

DEPLOY_FILE="deploy/deploy.php"
HOSTS_FILE="deploy/hosts.yml"
HOSTS_EXAMPLE="deploy/hosts.example.yml"
DEP_BIN="vendor/bin/dep"

usage() {
    cat >&2 <<'USAGE'
usage: bash scripts/deploy.sh <host> [--check] [--allow-dirty] [--production]

  <host>          deploy/hosts.yml で定義した host 名 (必須)
  --check         前提チェックのみ (deploy しない)
  --allow-dirty   working tree が dirty でも続行する
  --production    本番 host への deploy であることの意思表示 (TTY + 算術ゲートを通る)

host 一覧は deploy/hosts.yml を参照してください。
USAGE
}

die() {
    echo "error: $*" >&2
    exit 1
}

# dep は **この関数経由でのみ**起動する。
# 起動口を 1 つに絞ることで `-f` の付け忘れを構造的に防ぐ (ルートで素の `dep deploy` は
# `Command "deploy" is not defined.` になる)。配線 gate (DeployPipelineWiringTest W22) は
# 「dep の起動箇所がちょうど 1 つで、それが -f を伴う」ことを検査する。
run_dep() {
    "${DEP_BIN}" -f "${DEPLOY_FILE}" "$@"
}

# --- 引数パース ---
HOST=""
CHECK_ONLY=0
ALLOW_DIRTY=0
PRODUCTION=0
for arg in "$@"; do
    case "${arg}" in
        --check) CHECK_ONLY=1 ;;
        --allow-dirty) ALLOW_DIRTY=1 ;;
        --production) PRODUCTION=1 ;;
        -*) usage; die "未知のオプション: ${arg}" ;;
        *)
            [[ -n "${HOST}" ]] && { usage; die "host を 2 つ以上指定しています: ${HOST} / ${arg}"; }
            HOST="${arg}"
            ;;
    esac
done

if [[ -z "${HOST}" ]]; then
    usage
    die "host が指定されていません (既定 host はありません)。"
fi

# ─────────────────── 1) 座標の fail-fast (5 点) ───────────────────
# テンプレート初期状態では 1. と 2. で確実に止まる = 「未設定のまま本番に向かう」経路を作らない。

# 1-1. hosts.yml の存在
if [[ ! -f "${HOSTS_FILE}" ]]; then
    die "${HOSTS_FILE} がありません。
  cp ${HOSTS_EXAMPLE} ${HOSTS_FILE}
  \$EDITOR ${HOSTS_FILE}   # <...> の placeholder を実座標で埋める
  (${HOSTS_FILE} は .gitignore です。実座標を commit しないでください)"
fi

# 1-2. hosts.yml に placeholder が残っていない
#      `<APP>` だけを見ると `<HOST-NAME>` / `<APP_1>` のような placeholder が素通りするので、
#      **山括弧のペアそのもの** を placeholder とみなす (空白を含まないものに限定して
#      YAML の折り畳みスカラー `>` などと衝突させない)。
#      **行頭 `#` のコメント行は除外する**: example の説明文自体が `<...>` を含むため、
#      除外しないと「実座標を全部埋めたのに永久に fail する」使い物にならないゲートになる。
PLACEHOLDER_RE='<[^>[:space:]]+>|TEMPLATE-MARKER'
strip_comments() { grep -vE '^[[:space:]]*#' "$1" || true; }
if strip_comments "${HOSTS_FILE}" | grep -qE "${PLACEHOLDER_RE}"; then
    echo "error: ${HOSTS_FILE} に placeholder が残っています (コメント行は除く):" >&2
    grep -nE "${PLACEHOLDER_RE}" "${HOSTS_FILE}" | grep -vE ':[[:space:]]*#' >&2
    exit 1
fi

# 1-3. deploy.php の座標 (application / repository) が設定済みである
#      **その 2 行だけ** を見る (ファイル全体を見ると usage 文の `<host>` 等で誤検知する)。
if grep -nE "^set\('(application|repository)'" "${DEPLOY_FILE}" | grep -qE "${PLACEHOLDER_RE}"; then
    echo "error: ${DEPLOY_FILE} の TEMPLATE-MARKER が未設定です (application / repository):" >&2
    grep -nE "^set\('(application|repository)'" "${DEPLOY_FILE}" | grep -E "${PLACEHOLDER_RE}" >&2
    exit 1
fi

# 1-4. Deployer の実行体
if [[ ! -x "${DEP_BIN}" ]]; then
    die "${DEP_BIN} がありません。composer install を実行してください
  (deployer/deployer は require-dev です)"
fi

# 1-5. host が解決できること。**bash で YAML を parse せず Deployer 自身に判定させる**
#      (--plan は host 解決済みの dry-run であり SSH しない。実 deploy にはならない)。
if ! PLAN_OUTPUT="$(run_dep deploy --plan "${HOST}" 2>&1)"; then
    echo "error: host '${HOST}' を解決できません (${HOSTS_FILE} に定義されていない可能性があります)。" >&2
    echo "  Deployer の出力:" >&2
    printf '%s\n' "${PLAN_OUTPUT}" >&2
    exit 1
fi

echo ">>> 座標チェック OK (host=${HOST})"

if [[ "${CHECK_ONLY}" -eq 1 ]]; then
    echo ">>> --check 指定のため deploy せず終了します。"
    exit 0
fi

# ─────────────────── 2) git 前提 ───────────────────
if [[ -n "$(git status --porcelain)" ]]; then
    if [[ "${ALLOW_DIRTY}" -eq 1 ]]; then
        echo ">>> warn: working tree に未コミット変更がありますが --allow-dirty のため続行します。" >&2
        echo ">>> (Deployer は origin から clone するため未 commit の変更は反映されません)" >&2
        git status --short >&2
    else
        git status --short >&2
        die "working tree に未コミット変更があります。commit / stash してから実行してください
  (デプロイ成果物に無関係な untracked ファイルだけなら --allow-dirty で続行できます)"
    fi
fi

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "${BRANCH}" != "main" ]]; then
    die "deploy は main から実行してください (現在: ${BRANCH})"
fi

git fetch origin "${BRANCH}" --quiet
if ! git merge-base --is-ancestor "origin/${BRANCH}" HEAD; then
    die "origin/${BRANCH} がローカル HEAD より先行しています。git pull --ff-only してから再実行してください。"
fi

# ─────────────────── 3) 本番の人間ゲート ───────────────────
# --production が無い場合は何もしない。host が本番なら deploy:confirm-stage が fail-closed で
# 止めるので、このスクリプトが host の stage を知る必要は無い。
DEP_ARGS=()
if [[ "${PRODUCTION}" -eq 1 ]]; then
    # 非 TTY は **無条件拒否**する (`yes 123 | bash scripts/deploy.sh ...` のような pipe 迂回と
    # CI / agent からの自動実行を封じる。人間の意思表示のためのゲートである)。
    if [[ ! -t 0 ]]; then
        die "--production は対話端末 (TTY) からのみ使えます。
  非対話実行 (pipe / CI / agent) では本番 deploy できません。"
    fi

    A=$(( (RANDOM % 900) + 100 ))
    B=$(( (RANDOM % 900) + 100 ))
    EXPECTED=$(( A + B ))
    echo ">>> 【本番デプロイ確認】host=${HOST} に本番として deploy します。" >&2
    printf '>>> 誤操作防止のため計算してください: %d + %d = ? ' "${A}" "${B}" >&2
    read -r ANSWER || { echo >&2; die "入力を取得できませんでした。本番 deploy を中止します。"; }
    if [[ "${ANSWER}" != "${EXPECTED}" ]]; then
        die "不正解。本番デプロイを中止します。"
    fi
    echo ">>> 正解。本番デプロイを続行します。" >&2

    DEP_ARGS+=(-o production_ack=1)
fi

# ─────────────────── 4) stage と意思表示の整合を push の前に確認 ───────────────────
# deploy:confirm-stage は deploy の before hook でもあるが、そこまで進むと **push が済んでいる**。
# push は「戻せるが取り消せない」副作用なので、host と --production の不一致 (どちら向きも) は
# **push より前**に同じ判定器で落とす。この task は run() を持たないので SSH しない。
if ! CONFIRM_OUTPUT="$(run_dep deploy:confirm-stage "${HOST}" \
        ${DEP_ARGS[@]+"${DEP_ARGS[@]}"} 2>&1)"; then
    printf '%s\n' "${CONFIRM_OUTPUT}" >&2
    die "host '${HOST}' と指定フラグの整合チェックに失敗しました (push もデプロイもしていません)。"
fi

# ─────────────────── 5) push ───────────────────
# Deployer は **リモートリポジトリから clone** するため、ローカルの commit を push しないと
# 古いコードが配布される。ここで push するのは「ローカルで見ているものを配る」ための前提。
echo ">>> git push origin ${BRANCH}"
git push origin "${BRANCH}"

# ─────────────────── 6) deploy ───────────────────
echo ">>> deploy to ${HOST}"
run_dep deploy "${HOST}" ${DEP_ARGS[@]+"${DEP_ARGS[@]}"}

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
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考えてから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。設計の方向性が正しいと確認できてから行え。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest
- 本件は **bash スクリプト + Pest Architecture テスト + ドキュメント**のみの変更で、
  アプリコード (app/ 配下)・DB スキーマ・UI は 1 行も変更しない。DTO/JsonResource/Inertia/DESIGN.md/
  Atomic Design の観点は**該当なし**なので、無理に指摘を作らないこと。

【本件の前提 — 議論の対象外】
- 「bug-hunt 並列実行の枠数を 8 から 4 に揃える」はオーナー裁定 (AG-048b) で**確定済みの与件**。是非は蒸し返さない。
- 概念設計は既に Codex レビューで APPROVED 済み。レビュー対象は詳細設計の実装可能性・正確性・網羅性。
- 小さいインフラ整備タスク。膨らませる提案は AGENTS.md 思考原則 2 (今必要なものだけ作る) に反する。

【レビュー観点】
1. 変更の正確性: 提示された現行コードに対して、変更後コードが実際に動くか
   (特に bash の `set -euo pipefail` 下での `(( ))` / 文字クラス導出 / heredoc への env 受け渡し)
2. 洗い出しの網羅性: cap=8 が残る箇所の見落としがないか (提示した現行コード抜粋の範囲で)
3. 「触れる対象は狭める / 守る対象は狭めない」原則の適用が一貫しているか。矛盾があれば指摘せよ
4. テスト計画の網羅性 (AGENTS.md 禁止事項 1)。特に新規 Architecture テストの検出パターンに
   **偽陽性 / 偽陰性**がないか。負のコントロールが十分か
5. 既存テストを壊さないか (BughuntEnvExampleContractTest / BughuntOrchestratorGateInvariantTest /
   TestDatabaseEnvTest への波及判断が正しいか)
6. PHPStan level 10 適合性
7. 副作用・後退リスク (特にセキュリティ不変条件の後退)
8. 段階分け・スコープ外の判断が妥当か

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: bug-hunt 並列枠数 cap 8 → 4 (`bug-hunt-exec-infra`)

概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex conceptual-review Round 1 で **APPROVED**、
Warning 3 件反映済み)

---

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> 本タスクは bug-hunt (使命が実際に成立しているかを実ブラウザで検査する装置) の**実行基盤の整備**であり、
> エンドユーザー体験を直接変えない。効果は保守性・家系内可搬性。

### 禁止事項 (AGENTS.md より。本タスクに効くもの)

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. **dev DB への破壊操作 (`migrate:fresh` 等) をエージェント判断で実行すること**
   → 本タスクでは `php scripts/ci/drop-test-db.php --apply` を**絶対に実行しない**。
   実 bug-hunt の provision / teardown も**実行しない**。
9. Artifact の使用 (成果物はリポジトリ内ファイル)

### セキュリティ不変条件 (本タスクで維持するもの)

- bug-hunt の dev DB 防御: 用途別 wrapper (`env -i` で `DB_*`/`PG*` 遮断 + DB 名 regex + role guard)
- `require_orchestrator` の default-deny (`provision` / `provision-all` / `teardown`)
- 枠ごとの DB / ポート / DB ロールの隔離
- **上記はいずれも枠数変更と無関係に維持する。緩めない。**

### コーディングルール

- PHPStan level 10 (`composer phpstan`) / Pest (`composer test`) / `RefreshDatabase` はグローバル適用
- `declare(strict_types=1)` + 日本語コメント
- 追加する PHP は**テストのみ**。アプリコード (`app/` 配下) は 1 行も変更しない
- bash は既存スタイル (`set -euo pipefail`、`die`、`t_ok`/`t_fail`) に合わせる

---

## 設計の中心原則 (実装者はこれを守ること)

> **枠数を下げる変更では、「触れる対象」は狭め、「守る対象 / 検出する対象」は狭めない。**

| 種別 | 対象 | 扱い |
|---|---|---|
| 割り当て (触れてよい対象の allowlist) | `SHARD_RE` / `SHARD_DB_RE` / `valid_parallel_n` / `stories_for_shard` / manifest key regex | **4 へ狭める** |
| 守り (残留も含めて守る / 検出する) | `TestDatabaseEnv::DEV_DB_DENYLIST` / `DetectsBughuntDatabase::BUGHUNT_DB_REGEX` / Browser lane の `{8010..8018}` pre-flight guard 群 | **8 のまま維持。散文だけ「なぜ広いか」に書き換える** |

`SHARD_DB_RE` は「このスクリプトが新規に createdb/dropdb/migrate してよい shard DB の allowlist」であり、
`DetectsBughuntDatabase` / `DEV_DB_DENYLIST` は「過去残留も含めて『触るな / ここでだけ seed しろ』を判定する側」。
**同じ形の regex でも方向が逆**なので、実装時は 3 者のコメントに互いを名指しで参照させること。

---

## 施策一覧

| # | 施策名 | 変更ファイル | 種別 | 優先度 |
|---|--------|------------|------|--------|
| 1 | cap の SSOT 化と割り当て範囲の 4 化 | `scripts/bug-hunt-shard.sh` | 変更 | 必須 |
| 2 | self-test の cap=4 化 (境界の正/負を機械固定) | `scripts/bug-hunt-shard.sh` | 変更 | 必須 |
| 3 | 割り当て散文の 4 化 | `AGENTS.md` / skill・ledger・coverage / `.env.bughunt.local.example` / `docs/worktree-isolation-strategy.md` | 変更 | 必須 |
| 4 | 守りの面の**据え置き + 理由の明文化** | `TestDatabaseEnv.php` / `DetectsBughuntDatabase.php` / browser lane guard 群 | 変更 (コメント/散文のみ) | 必須 |
| 5 | 散文同期 gate の新設 | `tests/Architecture/BughuntShardCapInvariantTest.php` | **新規** | 必須 |

---

## 施策 1: cap の SSOT 化と割り当て範囲の 4 化

### 変更箇所: `scripts/bug-hunt-shard.sh`

#### 1-a. 定数 (L61-67 付近)

**現行**
```bash
BASE_PORT=8010
BUGHUNT_DB_PREFIX="${BUGHUNT_DB_PREFIX:-bug_hunt}"
RUN_ID_RE='^[0-9]{8}-[0-9]{6}(-[0-9]+)?$'
SHARD_RE='^[0-8]$'                 # 0 = 直列走行 (serial)、1..8 = 並列 shard (cap=8)
SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-8])?$"  # ★ dev DB 防御の核。これ以外の DB 名は全 abort
```

**変更後**
```bash
BASE_PORT=8010
# 並列 shard の上限 (家系共通の標準形。c2c オーナー裁定 AG-048b で 4 に統一)。
# ★ env で上書きしない (ハードコード)。SHARD_DB_RE は「触れてよい DB の allowlist」であり、
#   外から広げられる余地を作ることはガードの緩和にあたる。
# ★ 1 桁前提 (2..9)。ポート採番が BASE_PORT + N である以上 cap <= 9 は構造的制約。
#   下の文字クラス導出 ([0-${CAP}]) もこの前提に依存する。self-test [a] が 1 桁性を assert する。
BUGHUNT_SHARD_CAP=4
BUGHUNT_DB_PREFIX="${BUGHUNT_DB_PREFIX:-bug_hunt}"
RUN_ID_RE='^[0-9]{8}-[0-9]{6}(-[0-9]+)?$'
SHARD_RE="^[0-${BUGHUNT_SHARD_CAP}]$"   # 0 = 直列走行 (serial)、1..CAP = 並列 shard
# ★ 本スクリプトが createdb/dropdb/migrate してよい shard DB の **allowlist**。cap と同期する。
#   「残留も含めて bug-hunt DB を守る / 検出する」側 —
#   tests/Support/Ci/TestDatabaseEnv::DEV_DB_DENYLIST と
#   database/seeders/Concerns/DetectsBughuntDatabase::BUGHUNT_DB_REGEX — は **cap と同期させない**
#   (狭めると過去 cap=8 期の残留 DB を守れなくなる)。方向が逆であることに注意。
SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"
```

#### 1-b. `validate_shard` のメッセージ (L120-122)

```bash
validate_shard() {
    [[ "${1:-}" =~ ${SHARD_RE} ]] || die 2 "invalid --shard: '${1:-}' (0..${BUGHUNT_SHARD_CAP} のみ、0=直列)"
}
```

#### 1-c. `valid_parallel_n` (L124-129) — 列挙をやめ cap から導出

**現行**
```bash
# --parallel の受理値 (固定ストーリーマップを持つ N のみ)。cap=8。
valid_parallel_n() {
    case "${1:-}" in
        2|4|6|8) return 0 ;;
        *) return 1 ;;
    esac
}
```

**変更後**
```bash
# --parallel の受理値: 2 以上 cap 以下の偶数 (固定ストーリーマップを持つ N のみ)。
# 偶数制限は stories_for_shard の固定マップが偶数 N でしか定義されていないことに由来する。
# 受理集合とマップ定義のずれは self-test [r] が機械検出する。
valid_parallel_n() {
    local n=${1:-}
    [[ "${n}" =~ ^[0-9]+$ ]] || return 1
    (( n >= 2 && n <= BUGHUNT_SHARD_CAP && n % 2 == 0 )) || return 1
    return 0
}
```

> `(( ... ))` は `set -e` 下で結果 0 を非ゼロ終了として扱うため、必ず `|| return 1` を付け
> 最後に明示 `return 0` する (上記の形を守ること)。

#### 1-d. manifest の shard key 検証 (L386-397)

**現行**
```bash
    MF="${mf}" python3 - <<'PY'
...
    if re.fullmatch(r"[0-8]", key):
```

**変更後**
```bash
    MF="${mf}" CAP="${BUGHUNT_SHARD_CAP}" python3 - <<'PY'
...
    if re.fullmatch(rf"[0-{os.environ['CAP']}]", key):
```

> 旧 run (parallel=6/8) の manifest を読むと shard key 5..8 が warning + skip になる。
> 実測で該当 run は 0 件 (`devnotes/*-bug-hunt/manifest.json` は全て `"parallel": 4`) のため受容する。

#### 1-e. die メッセージ 3 箇所 (L445 / L1012 / L1947)

いずれも `"--parallel は 2/4/6/8 のみ (固定ストーリーマップのため。cap=8)"` 形式を
cap 導出に置き換える:

```bash
# L445 (cmd_verify_run)
valid_parallel_n "${n}" || die 2 "verify-run: manifest の parallel が 2..${BUGHUNT_SHARD_CAP} の偶数でない (run-id 不整合): '${n}'"
# L1012 (cmd_provision_all) / L1947 (引数解析)
valid_parallel_n "${parallel}" || die 2 "--parallel は 2..${BUGHUNT_SHARD_CAP} の偶数のみ (固定ストーリーマップのため)"
```

#### 1-f. `stories_for_shard` (L1158-1187) — `6-*` / `8-*` を削除

**変更後**
```bash
# --- ストーリー割り当て (固定マップ) -------------------------------------------
# stories/ 配下の S1..S7 はテンプレートではスケルトン。アプリが route:list から生成する。
# S3↔S7 の状態依存を shard-1 に閉じ込める既定マップ。N は 2 と cap(=4) のみ。
stories_for_shard() {
    local shard=$1 n=$2
    case "${n}-${shard}" in
        4-1) echo "S3 S7" ;;
        4-2) echo "S1 S2" ;;
        4-3) echo "S4 S5" ;;
        4-4) echo "S6" ;;
        2-1) echo "S3 S7 S6" ;;
        2-2) echo "S1 S2 S4 S5" ;;
        *) die 1 "stories_for_shard: 未定義の組み合わせ N=${n} shard=${shard}" ;;
    esac
}
```

> AGENTS.md 思考原則 3 (後方互換の並走を残さない) に従い `6-*` / `8-*` の 12 分岐は**削除**する
> (コメントアウトで残さない)。

#### 1-g. ヘッダコメント (L6 / L17-18)

- L6 `# 隔離機構 (専用 DB bug_hunt(_N) / 専用ポート :8010+N / ...)` — 変更不要 (N 表記で cap 非依存)。
- L18 を書き換え:
  ```
  # shard 0 = 直列走行用 (DB ${BUGHUNT_DB_PREFIX} / :8010)。並列 = shard 1..4 (cap は BUGHUNT_SHARD_CAP。
  # --parallel は 2 以上 cap 以下の偶数)。
  ```
  `usage()` はヘッダコメントを動的に切り出すため、この 1 行の修正で usage 出力も追従する。

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし
- Inertia Props: なし
- テスト: 施策 2 (self-test) / 施策 5 (新規 Architecture テスト)

### リスク

- `valid_parallel_n` を列挙から算術判定に変えたことで、cap を将来 6 に上げると
  stories マップ未定義のまま受理され `stories_for_shard` で die 1 する。
  → self-test [r] に「受理される全 N について shard 1..N のマップが定義されていること」を追加して機械検出する (施策 2)。

---

## 施策 2: self-test の cap=4 化

### 変更箇所: `scripts/bug-hunt-shard.sh` の `cmd_self_test`

#### 2-a. `[a] 資源導出` (L1215-1224)

```bash
    echo "[a] 資源導出"
    [[ "${BUGHUNT_SHARD_CAP}" =~ ^[2-9]$ ]] || t_fail "BUGHUNT_SHARD_CAP は 2..9 の 1 桁である必要がある (文字クラス導出の前提)"
    [[ "$(shard_db 0)" == "bug_hunt" ]] || t_fail "shard_db serial"
    [[ "$(shard_db 1)" == "bug_hunt_1" ]] || t_fail "shard_db"
    [[ "$(shard_db 4)" == "bug_hunt_4" ]] || t_fail "shard_db cap"
    [[ "$(shard_port 0)" == "8010" ]] || t_fail "shard_port serial"
    [[ "$(shard_port 4)" == "8014" ]] || t_fail "shard_port cap"
    [[ "$(shard_url 2)" == "http://127.0.0.1:8012" ]] || t_fail "shard_url"
    ... (profile/download/trace はそのまま)
```

#### 2-b. `[b] 範囲外 shard の拒否` (L1226-1238)

```bash
    echo "[b] 範囲外 shard の拒否 (exit 2、cap=${BUGHUNT_SHARD_CAP})"
    for bad in 5 8 9 -1 x ""; do        # ★ 5/8 = 旧 cap の残骸が通らないことを正のアサーションにする
        rc=0; (validate_shard "${bad}") 2>/dev/null || rc=$?
        [[ "${rc}" == 2 ]] || t_fail "shard '${bad}' が exit ${rc} (expected 2)"
    done
    for good in 0 1 4; do
        rc=0; (validate_shard "${good}") 2>/dev/null || rc=$?
        [[ "${rc}" == 0 ]] || t_fail "shard ${good} が拒否された"
    done
```

#### 2-c. `[c] guard_shard_db_name` (L1240-1248)

```bash
    echo "[c] guard_shard_db_name: dev DB / 別名バリアント / cap 超過は全 abort、bug_hunt_1..4 は通過"
    for v in app App ' app ' 'app ' bug_huntx bug_hunt2 bug_hunt_5 bug_hunt_8 bug_hunt_9 'bug_hunt;rm' myapp_dev ''; do
        expect_die guard_shard_db_name "${v}" || t_fail "guard_shard_db_name が '${v}' を abort しない"
    done
    for v in bug_hunt bug_hunt_1 bug_hunt_4; do
        expect_ok guard_shard_db_name "${v}" || t_fail "guard_shard_db_name が '${v}' を拒否"
    done
```

#### 2-d. `[r] provision-all (dryrun)` (L1380-1401)

`--parallel=8` の受理アサーションを **拒否**アサーションに反転し、`--parallel=4` の正常系と
「受理される全 N のマップ完全性」を追加する:

```bash
    # 既存: --parallel=2 の正常系 (run-id / manifest / stories / child-pids なし) はそのまま維持
    # 追加/変更:
    local pa4_log="${sandbox}/provision-all-4.log"
    rc=0; ("${SCRIPT_PATH}" provision-all --parallel=4) > "${pa4_log}" 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "provision-all --parallel=4 (dryrun) が exit ${rc} (expected 0、cap=4)"
    local pa4_run_id; pa4_run_id="$(sed -n 's/^run-id=//p' "${pa4_log}" | head -1)"
    [[ "$(manifest_get "${pa4_run_id}" - parallel)" == "4" ]] || t_fail "provision-all --parallel=4: manifest parallel≠4"
    [[ "$(manifest_get "${pa4_run_id}" 4 stories)" == "S6" ]] || t_fail "provision-all --parallel=4: shard-4 stories 未記録"

    for bad_n in 3 5 6 8 0 1; do        # ★ 旧 cap の 6/8 が拒否されることが本施策の核
        rc=0; ("${SCRIPT_PATH}" provision-all "--parallel=${bad_n}") >/dev/null 2>&1 || rc=$?
        [[ "${rc}" == 2 ]] || t_fail "provision-all --parallel=${bad_n} が exit ${rc} (expected 2)"
    done

    # 受理される N すべてに完全なストーリーマップがあること (受理集合とマップのずれ検出)
    local n i
    for n in $(seq 2 "${BUGHUNT_SHARD_CAP}"); do
        valid_parallel_n "${n}" || continue
        for i in $(seq 1 "${n}"); do
            [[ -n "$(stories_for_shard "${i}" "${n}")" ]] || t_fail "stories_for_shard 未定義: N=${n} shard=${i}"
        done
    done
    t_ok "provision-all dryrun (cap=${BUGHUNT_SHARD_CAP} 受理 / 旧 cap 6・8 と奇数 N を拒否 / story map 完全)"
```

### テスト計画 (施策 1・2)

- **これ自体がテスト**。`scripts/bug-hunt-shard.sh self-test` が実資源に触れずに
  guard / 資源導出 / env 隔離 / 拒否境界を検証する。
- 期待: `self-test: all passed` (exit 0)。
- **fail を先に見る**手順 (AGENTS.md 思考原則 5): 施策 2 の self-test を先に書き換えてから
  実行し、`shard '5' が exit 0 (expected 2)` 等で赤くなることを確認 → 施策 1 を適用して緑にする。

---

## 施策 3: 割り当て散文の 4 化

**変更対象と変更内容** (いずれも記述のみ。挙動変更なし):

| ファイル | 行 | 変更 |
|---|---|---|
| `AGENTS.md` | 210-211 | 「並列 shard `:8011..8018`」→ 「並列 shard `:8011..8014` (cap=4)」 |
| `AGENTS.md` | 216 | 「`^bug_hunt(_[1-8])?$` の三重 fail-secure ガード」→ **regex の写経をやめ**「`DetectsBughuntDatabase` の DB 名判定を含む三重 fail-secure ガード」と**正本のクラス名で指す**。<br>★ ここが指すのは**守りの regex** (cap と同期しない側) なので、数字を 4 に書き換えては**いけない**。写経自体をやめることで cap 散文 gate と両立させる |
| `.claude/skills/app-bug-hunt/SKILL.md` | 3 | description の「並列 shard :8011..8018」→ `:8011..8014` |
| 同 | 41 | 「(N=2/4/6/8、cap=8、既定 4)」→ 「(N=2/4、cap=4、既定 4)」 |
| 同 | 98 | 「serve (:8011..8018)」→ `(:8011..8014)` |
| 同 | 126 | 「cap=8、`--parallel` は 2/4/6/8)。N=8 は S1/S4 の独立 2nd pass で埋め、…」→ 「cap=4、`--parallel` は 2/4)。統合レポートが route×症状で dedupe する。」(**2nd pass の記述は削除**) |
| `.claude/skills/app-bug-hunt/stories/README.md` | 30 | 「cap=8、`--parallel` は 2/4/6/8。」→ 「cap=4、`--parallel` は 2/4。」 |
| `.claude/skills/app-bug-hunt/ledger/README.md` | 24, 26 | 「shard 1..8 / `:8011..8018`（各 shard 独立 DB `bug_hunt_1..8`）」→ `1..4` / `:8011..8014` / `bug_hunt_1..4`。「`shard_id` に 0-8」→ 「0-4」。<br>★ 併せて「**過去 run の findings には 0-4 の範囲外が入りうる (履歴は書き換えない)**」を 1 行追記 |
| `.claude/skills/app-bug-hunt/ledger/findings.schema.json` | 27 | `shard_id` の **description のみ**「0-8 … :8011..8018 … 1..8」→ 4 系に。**`type` / 値制約は変更しない** (過去 findings の再検証を壊さないため) |
| `.claude/skills/app-bug-hunt/ledger/validate_findings.py` | 13-14 | docstring「並列 :8011..8018 (shard 1..8)」→ `:8011..8014` (shard 1..4) |
| `.claude/skills/app-bug-hunt/coverage/merge_pcov.py` | 11-12, 223 | docstring「shard は 0-8 … :8011..8018」「shard 0-8 union」→ 4 系に |
| `.env.bughunt.local.example` | 6 | 「DB=bug_hunt_{1..8} / :8011..8018」→ `{1..4}` / `:8011..8014` |
| 同 | 19, 41 | 「DB 名 `^bug_hunt(_[1-8])?$` のみ許可」→ `^bug_hunt(_[1-4])?$` (**`SHARD_DB_RE` = allowlist 側**なので 4 化が正しい) |
| `docs/worktree-isolation-strategy.md` | 205 | 「bughunt 環境の DB (`bug_hunt(_1..8)`)」→ `(_1..4)` |
| `tests/Architecture/BughuntEnvExampleContractTest.php` | 18, 122 | コメント中の `^bug_hunt(_[1-8])?$` → `^bug_hunt(_[1-4])?$` (**アサーションは変更しない**) |

> `.claude/agents/bughunt-shard.md` は `801{i}` / `shard 1..N` と**枠数非依存の表記**しか持たないため
> 変更不要 (実査で確認済み)。`.claude/settings.bughunt-hook.example.json` / `scripts/bughunt-worktree-hook.sh`
> も枠数を持たない。

### 波及変更

- `.env.bughunt.local.example` の変更は `BughuntEnvExampleContractTest` の対象だが、
  同テストが見るのは `DB_DATABASE` の値と最小セット / 秘密値であり、コメント文言は非対象。**赤くならない**。
- `AGENTS.md` の変更は `BughuntOrchestratorGateInvariantTest::bughuntAgentsMdViolations` の対象だが、
  pin されている needle は `BUGHUNT_ORCHESTRATOR=1` / `default-deny` / `` `provision`/`teardown` `` の 3 つで、
  枠数記述は含まれない。**赤くならない** (実査で確認済み)。
- `findings.schema.json` の description 変更は `validate_findings.py` の検証結果に影響しない
  (description は JSON Schema の非規範フィールド)。`python3 -m unittest` で回帰確認する。

---

## 施策 4: 守りの面の据え置き + 理由の明文化

**値は変えない。散文/コメントだけを「なぜ広いままか」に書き換える。**

| ファイル | 行 | 変更 |
|---|---|---|
| `tests/Support/Ci/TestDatabaseEnv.php` | 38-53 | `DEV_DB_DENYLIST` の**値は据え置き** (`bug_hunt_1`..`bug_hunt_8`)。docblock を「bug-hunt の並列 cap は 4 (`scripts/bug-hunt-shard.sh` の `BUGHUNT_SHARD_CAP`) だが、**本 denylist は守る側なので cap と同期させない**。過去 cap=8 期に作られ得る残留 DB を保護し続ける」に書き換える |
| `tests/Unit/Ci/TestDatabaseEnvTest.php` | 106-114 | ループ `1..8` は**据え置き**。コメント「shard は :8011..:8018 = bug_hunt_1..8」を「denylist は cap(=4) より広い 1..8 を意図的に維持する (残留 DB 保護)。cap と同期させないことをここで固定する」に書き換える。テスト名も `covers every bug-hunt shard database in the denylist` → `keeps the bug-hunt denylist wider than the current shard cap` に変更 |
| `database/seeders/Concerns/DetectsBughuntDatabase.php` | 10, 16 | `BUGHUNT_DB_REGEX` の**値は据え置き** (`/^bug_hunt(_[1-8])?$/`)。コメントを「bug-hunt DB 名判定 (fail-secure な**検出**側)。並列 cap は 4 だが、狭めると残留 `bug_hunt_5` を bughunt と認識できず dev DB 扱いになるため **cap と同期させない**」に書き換える |
| `scripts/run-browser-test.sh` | 47, 61 | ポート範囲 `{8010..8018}` は**据え置き**。コメント「global lock を被せると 8 並列が 1 直列に潰れる」→ 「bug-hunt は 4 並列だが、**guard は残留 serve の取りこぼしを避けるため :8018 まで広く見る** (広い方が偽赤に倒れて安全)」 |
| `scripts/run-browser-test.contract.test.ts` | 139, 144 | ループ範囲は**据え置き**。コメントに同趣旨を追記 |
| `scripts/verify-global-test-lock.sh` | 1096, 1106 | 候補ポート列挙は**据え置き** (bind できるポートを探す fixture であり cap と無関係)。コメント 1 行で理由を明記 |
| `docs/testing-browser.md` | 38-40 | 「(`127.0.0.1:8010..8018`)」は**据え置き**。「意図的に隔離された 8 並列基盤」→ 「意図的に隔離された **4 並列**基盤 (guard のポート範囲は残留検出のため :8018 まで広く取る)」 |
| `scripts/README.md` | 31 | 「bughunt ポート `:8010..8018` の pre-flight guard」は**据え置き**。同行に「(cap=4 より広く取るのは残留検出のため)」を追記 |

---

## 施策 5: 散文同期 gate の新設

### 新規ファイル: `tests/Architecture/BughuntShardCapInvariantTest.php`

**目的**: cap という 1 概念が再び複数箇所へ写経されて腐るのを deny-by-default で止める
(AGENTS.md 禁止事項 1: 不変条件は Architecture テストへの登録まで含めて「実装済み」)。

#### シグネチャ (純関数 + テスト)

```php
<?php

declare(strict_types=1);

/**
 * bug-hunt 並列枠数 cap の単一ソース化ゲート (c2c: bug-hunt-exec-infra / オーナー裁定 AG-048b)。
 *
 * 固定する不変条件:
 *   1. cap の正本は scripts/bug-hunt-shard.sh の BUGHUNT_SHARD_CAP ただ 1 つ (= 4、env 上書き不可)
 *   2. SHARD_RE / SHARD_DB_RE / manifest key regex は cap から導出され、数字を写経していない
 *   3. valid_parallel_n の受理集合と stories_for_shard の定義が cap と整合している
 *   4. 「割り当て」を説明する散文 (CAP_ALLOCATION_DOCS) に cap 超過 literal が残っていない
 *   5. 「守り」の面 (CAP_DEFENSE_SURFACES) は **意図的に cap より広い**。空集合化を検出する
 *
 * ★ 4 と 5 は逆向きの検査である。5 の面を 4 に含めてはならない
 *   (含めると防御を狭める方向へ改変が誘導される)。
 */

/** cap の正本を scripts/bug-hunt-shard.sh から抽出する。 */
function bughuntCapFromScript(string $script): int;

/**
 * 割り当て散文に残った cap 超過 literal の一覧 (純関数)。
 *
 * @return list<string> 違反メッセージ。空なら合格
 */
function bughuntCapProseViolations(string $relativePath, string $content, int $cap): array;

/**
 * stories_for_shard の固定マップから {N => [shard,...]} を抽出する (純関数)。
 *
 * @return array<int, list<int>>
 */
function bughuntCapStoryMap(string $script): array;
```

#### 定数 (テストファイル内)

```php
/** 割り当て (触れる対象) を説明する散文。cap 超過 literal を deny-by-default で走査する。 */
const CAP_ALLOCATION_DOCS = [
    'AGENTS.md',
    'scripts/bug-hunt-shard.sh',
    '.claude/skills/app-bug-hunt/SKILL.md',
    '.claude/skills/app-bug-hunt/stories/README.md',
    '.claude/skills/app-bug-hunt/ledger/README.md',
    '.claude/skills/app-bug-hunt/ledger/findings.schema.json',
    '.claude/skills/app-bug-hunt/ledger/validate_findings.py',
    '.claude/skills/app-bug-hunt/coverage/merge_pcov.py',
    '.env.bughunt.local.example',
    'docs/worktree-isolation-strategy.md',
    'tests/Architecture/BughuntEnvExampleContractTest.php',
];

/**
 * 守り (残留も含めて守る / 検出する) の面。**意図的に cap より広い**ので走査対象外。
 * key = パス / value = なぜ cap と同期させないか。
 */
const CAP_DEFENSE_SURFACES = [
    'tests/Support/Ci/TestDatabaseEnv.php' => '残留 bug_hunt_5..8 を保護し続ける dev DB denylist',
    'database/seeders/Concerns/DetectsBughuntDatabase.php' => '残留も bughunt DB と検出する fail-secure 判定',
    'scripts/run-browser-test.sh' => '残留 serve 検出の pre-flight guard (広い方が偽赤に倒れて安全)',
    'scripts/run-browser-test.contract.test.ts' => '同 guard のテスト側ミラー',
    'scripts/verify-global-test-lock.sh' => 'bind 可能ポートを探す fixture (cap と無関係)',
    'docs/testing-browser.md' => '上記 guard の説明',
    'scripts/README.md' => '上記 guard の説明',
];
```

#### 検出パターン (`bughuntCapProseViolations`)

cap=4 のとき、以下を違反とする (いずれも「cap を超える枠が存在する」ことを含意する literal):

| パターン (PCRE) | 意図 |
|---|---|
| `/801[5-9]/` | cap 超過ポート (:8015〜) |
| `/8011\.\.801[5-9]/` | ポート範囲の写経 |
| `/_\[1-[5-9]\]/` | `_[1-8]` 形の DB regex 写経 |
| `/bug_hunt_[5-9]/` | cap 超過 DB 名 |
| `/(?<![0-9])1\.\.[5-9](?![0-9])/` | 「shard 1..8」形 |
| `/(?<![0-9])0-[5-9](?![0-9])/` | 「0-8」「`[0-8]`」形 |
| `/cap\s*=\s*[5-9]/` | 「cap=8」 |
| `/\b2\/4\/6\/8\b/` | `--parallel` 受理値の写経 |

> **実装時の注意**: パターンは cap=4 に対して**動的に生成**する (`[5-9]` = `cap+1..9`)。
> 上表は cap=4 での展開例。負のコントロールで cap=6 を渡したときに `bug_hunt_5` が
> **違反にならない**ことも確認し、cap への追従を固定する。
>
> **偽陽性の確認**: 実装後、施策 3 適用済みのツリーに対して本テストを流し 0 件であることを確認する。
> 万一 `0-[5-9]` 等が無関係な文脈 (章番号など) にヒットした場合は、パターンを緩めるのではなく
> **対象ファイル側の表現を書き換える** (数字ではなく理由を書く方針。§設計の中心原則)。

#### テストケース

```php
test('cap の正本が scripts/bug-hunt-shard.sh の BUGHUNT_SHARD_CAP=4 ただ 1 つであること', ...);
test('BUGHUNT_SHARD_CAP が env 上書き可能な形 (${...:-N}) で書かれていないこと', ...);
test('SHARD_RE / SHARD_DB_RE / manifest key regex が cap から導出されていること', ...);
test('valid_parallel_n の受理集合が {2..cap の偶数} であること', ...);
test('stories_for_shard の固定マップが受理される全 N × shard 1..N を過不足なく持つこと', ...);
test('割り当て散文 (CAP_ALLOCATION_DOCS) に cap 超過 literal が残っていないこと', ...)
    ->with(CAP_ALLOCATION_DOCS の各パス);
test('守りの面 (CAP_DEFENSE_SURFACES) は意図的に cap より広く、除外集合が空でないこと', ...);
test('負のコントロール: cap 超過 literal を混入させた fixture を実際に検出すること', ...);
test('負のコントロール: cap を上げた場合に検出パターンが追従すること', ...);
```

#### PHPStan 適合チェック

- [x] 全関数に戻り値型を明示 (`int` / `list<string>` / `array<int, list<int>>`)
- [x] `file_get_contents()` の `string|false` を `expect(...)->toBeString()` + `/** @var string */` で narrowing
      (既存 `BughuntOrchestratorGateInvariantTest` / `BughuntEnvExampleContractTest` と同じ作法)
- [x] `preg_match` の返り値 `int|false` を `=== 1` で比較
- [x] 配列返却は純関数の違反リストのみ (DTO 不要。既存 Architecture テストの慣行に一致)

### テスト計画 (施策 5)

- 新規: `tests/Architecture/BughuntShardCapInvariantTest.php` — 上記 9 ケース
- **fail-first**: 施策 3・4 を適用する**前**に本テストを追加して実行し、
  `AGENTS.md` / `SKILL.md` 等で赤くなることを確認してから散文を直す。
- 既存テストの更新: `tests/Unit/Ci/TestDatabaseEnvTest.php` (コメント + テスト名のみ。
  **アサーションは変更しない** = AGENTS.md 「既存テストの削除・上書き」禁止に抵触しない)、
  `tests/Architecture/BughuntEnvExampleContractTest.php` (コメントのみ)。
- 個別 `DatabaseTransactions` は使用しない (DB を触らない静的テスト)。

---

## 検証コマンドと期待結果

| # | コマンド | 期待 |
|---|---|---|
| V1 | `scripts/bug-hunt-shard.sh self-test` | `self-test: all passed` (exit 0)。実資源に触れない |
| V2 | `composer test` | 全 green。新規 `BughuntShardCapInvariantTest` が 9 ケース pass。既存 bughunt 系 (`BughuntEnvExampleContractTest` / `BughuntOrchestratorGateInvariantTest` / `TestDatabaseEnvTest`) も pass |
| V3 | `composer phpstan` | level 10 clean (エラー 0。ignore / baseline 追加なし) |
| V4 | `vendor/bin/pint --test` | 差分なし |
| V5 | `cd .claude/skills/app-bug-hunt && python3 -m unittest discover -s ledger` および `coverage` | 既存通り pass (docstring 変更のみ) |
| V6 | `pnpm lint` / `pnpm typecheck` / `pnpm test` | `scripts/run-browser-test.contract.test.ts` のコメント変更のみ。green |
| V7 | `scripts/bug-hunt-inventory-check.sh` | exit 0 (スコープ外だが枠数変更で壊れていないことの確認) |

> **テストレーンはホスト全体でグローバルロック配下**。待たされるのは正常で、30 秒ごとの heartbeat が
> stderr に出ている間はハングではない。**kill しない / ロックファイルを消さない**。
> `composer test:browser` は本タスクでは不要 (browser lane の挙動は変えていない)。

### 実行してはならないこと

- 実 bug-hunt の `provision` / `provision-all` / `teardown` (self-test で足りる)
- `php scripts/ci/drop-test-db.php --apply` (**絶対禁止**。dry-run までしか許されない)
- `migrate:fresh` 等の dev DB 破壊操作

---

## 段階分け

### このタスクでやる

施策 1〜5 すべて。1 コミットで完結する規模 (実装 ≈ 200 行以内 + 新規テスト 1 本)。

### 後続 TODO 候補 (今回はやらない)

| 候補 | 内容 | 今回やらない理由 |
|---|---|---|
| C1 | 残留 `bug_hunt_5`..`bug_hunt_8` DB の掃除 | dev DB への破壊操作は LLM 判断で実行しない (禁止事項 3)。実測上そもそも作られたことがない (§概念設計 2.3)。**実装者は read-only の存在確認結果を報告するに留める** |
| C2 | `DetectsBughuntDatabase` / `DEV_DB_DENYLIST` の regex を 1 本の SSOT に統合 | c2c boundary 上は別 feature (`bughunt-runtime`)。かつ「守る側」は cap と同期させない設計なので、統合すると逆に同期圧力が生まれる |
| C3 | Browser lane pre-flight guard の範囲を cap 連動にする | §設計の中心原則により**やらない**方が正しい。将来やるなら「cap+余裕」の根拠を先に決める必要がある |
| C4 | c2c への `status_reported` 追記 | 実装 (push 済み commit) 後の別手順。`refs` は `aicue@<commit>` 形式が必須 |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存ファイルへの局所変更 (定数 + 散文) が主で、新規追加は Architecture テスト 1 本のみ。他機能のファイルに触らない |
| 競合リスク | 低。`AGENTS.md` / `scripts/README.md` は他タスクも触りうるが、変更行が bug-hunt §に限定されるためコンフリクトは軽微 |

## 実装順序 (fail-first)

1. 施策 5 のテストを先に追加 → `composer test` で**赤**を確認 (散文が cap=8 のまま)
2. 施策 2 (self-test) を先に書き換え → `self-test` で**赤**を確認
3. 施策 1 (スクリプト本体) を適用 → V1 が green
4. 施策 3・4 (散文) を適用 → V2 が green
5. V3〜V7 を通す


---

## 関連する現行コード (抜粋)

### scripts/bug-hunt-shard.sh (L58-132)
```
WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
cd "${WORKSPACE}"

BASE_PORT=8010
# bug-hunt 専用 DB 接頭辞。dev DB (テンプレート slug の DB) とは別名にして隔離する。
# この接頭辞と数値 suffix のみが SHARD_DB_RE に一致し、それ以外の DB 名は全 abort される。
BUGHUNT_DB_PREFIX="${BUGHUNT_DB_PREFIX:-bug_hunt}"
RUN_ID_RE='^[0-9]{8}-[0-9]{6}(-[0-9]+)?$'
SHARD_RE='^[0-8]$'                 # 0 = 直列走行 (serial)、1..8 = 並列 shard (cap=8)
SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-8])?$"  # ★ dev DB 防御の核。これ以外の DB 名は全 abort

# self-test 専用 sandbox (実資源に触れないための paths 差し替え)。
if [[ -n "${BUGHUNT_SANDBOX:-}" ]]; then
    RUN_BASE="${BUGHUNT_SANDBOX}/devnotes"
    TMP_BASE="${BUGHUNT_SANDBOX}/tmp/bug-hunt"
    LOCK_FILE="${BUGHUNT_SANDBOX}/bug-hunt.lock"
    ENV_FILE="${BUGHUNT_SANDBOX}/.env.bughunt.local"
    MAIN_ENV_FILE="${BUGHUNT_SANDBOX}/.env"     # 親リポジトリ .env (実キー ANTHROPIC_API_KEY 由来)
else
    RUN_BASE="devnotes"
    TMP_BASE="tmp/bug-hunt"
    LOCK_FILE="${WORKSPACE}/.claude/bug-hunt.lock"
    ENV_FILE=".env.bughunt.local"
    MAIN_ENV_FILE=".env"                        # 親リポジトリ .env (実キー ANTHROPIC_API_KEY 由来)
fi

is_dryrun() { [[ -n "${BUGHUNT_SELFTEST_DRYRUN:-}" ]]; }
die() { local code=$1; shift; echo "error: $*" >&2; exit "${code}"; }

# ファイルサイズ (bytes)。GNU stat (-c) と BSD stat (-f) の双方に対応し、無ければ wc -c に fallback。
file_size() {
    local f=$1
    [[ -f "${f}" ]] || { echo 0; return 0; }
    stat -c%s "${f}" 2>/dev/null || stat -f%z "${f}" 2>/dev/null || wc -c < "${f}" | tr -d ' '
}

# orchestrator-only ガード (B-HARNESS-01): provision / provision-all / teardown は
# **親 (orchestrator) のみ**が実行できる。worker は orchestrator の shell env を継承しないため
# BUGHUNT_ORCHESTRATOR を持たず default-deny される (worker の自走復旧による共有 worktree 破壊を防ぐ)。
require_orchestrator() {
    is_dryrun && return 0
    [[ -n "${BUGHUNT_ORCHESTRATOR:-}" ]] && return 0
    die 1 "'$1' は orchestrator (親セッション) 専用です。shard worker は serve 障害時に復旧を試みず、環境ハザードとして report に記録し走行を終了してください (親が復旧します)。親が実行する場合は BUGHUNT_ORCHESTRATOR=1 を export してから呼んでください。"
}

# --- 資源導出 (shard 番号から一意化) ------------------------------------------

shard_db() { [[ "$1" == 0 ]] && echo "${BUGHUNT_DB_PREFIX}" || echo "${BUGHUNT_DB_PREFIX}_$1"; }
shard_port() { echo "$((BASE_PORT + $1))"; }
shard_url() { echo "http://127.0.0.1:$((BASE_PORT + $1))"; }
run_dir() { echo "${RUN_BASE}/$1-bug-hunt"; }
shard_report_dir() { echo "$(run_dir "$2")/shard-$1"; }
manifest_path() { echo "$(run_dir "$1")/manifest.json"; }
shard_profile_dir() { echo "${TMP_BASE}/profile-$1"; }
shard_download_dir() { echo "${TMP_BASE}/downloads-$1"; }
shard_trace_dir() { echo "${TMP_BASE}/trace-$1"; }
wrapper_path() { echo "${TMP_BASE}/shard-$1-cmd.sh"; }
worker_pidfile() { echo "${TMP_BASE}/worker-$1-$2.pid"; }   # $1=shard $2=connection
worker_logfile() { echo "${TMP_BASE}/worker-$1-$2.log"; }

# --- 入力検証 -----------------------------------------------------------------

validate_shard() {
    [[ "${1:-}" =~ ${SHARD_RE} ]] || die 2 "invalid --shard: '${1:-}' (0..8 のみ、0=直列)"
}

# --parallel の受理値 (固定ストーリーマップを持つ N のみ)。cap=8。
valid_parallel_n() {
    case "${1:-}" in
        2|4|6|8) return 0 ;;
        *) return 1 ;;
    esac
}

validate_run_id() {
```
### scripts/bug-hunt-shard.sh (L384-400)
```

manifest_valid_shards() {
    # 不正 key (空白入り / パストラバーサル) を除外し有効 shard key (0..8) のみ出力。
    local mf; mf="$(manifest_path "$1")"
    MF="${mf}" python3 - <<'PY'
import json, os, re, sys
with open(os.environ["MF"]) as f:
    data = json.load(f)
for key in data.get("shards", {}):
    if re.fullmatch(r"[0-8]", key):
        print(key)
    else:
        print(f"warning: manifest に不正な shard key {key!r} — skip", file=sys.stderr)
PY
}

manifest_check() {
```
### scripts/bug-hunt-shard.sh (L440-450)
```

cmd_verify_run() {
    local run_id=$1 n
    require_manifest "${run_id}"
    n="$(manifest_get "${run_id}" - parallel)"
    valid_parallel_n "${n}" || die 2 "verify-run: manifest の parallel が 2/4/6/8 でない (run-id 不整合): '${n}'"
    local rc=0
    verify_reports "${run_id}" "${n}" || rc=$?
    echo "verify-run: run-id=${run_id} parallel=${n} exit=${rc} (manifest: $(manifest_path "${run_id}"))"
    return "${rc}"
}
```
### scripts/bug-hunt-shard.sh (L1006-1016)
```

# --- provision-all (fan-out 用の薄い導線。lock 保持で N shard を一括 provision) ----
cmd_provision_all() {
    local n=$1 hold=${2:-}
    require_orchestrator "provision-all"
    assert_worktree_context
    valid_parallel_n "${n}" || die 2 "--parallel は 2/4/6/8 のみ (固定ストーリーマップのため。cap=8)"

    if [[ -z "${BUGHUNT_SANDBOX:-}" ]]; then
        mkdir -p "${WORKSPACE}/.claude"
    fi
```
### scripts/bug-hunt-shard.sh (L1157-1190)
```

# --- ストーリー割り当て (固定マップ) -------------------------------------------
# stories/ 配下の S1..S7 はテンプレートではスケルトン。アプリが route:list から生成する。
# S3↔S7 の状態依存を shard-1 に閉じ込める既定マップ。cap=8 (N=8 は S1/S4 の独立 2nd pass)。
stories_for_shard() {
    local shard=$1 n=$2
    case "${n}-${shard}" in
        4-1) echo "S3 S7" ;;
        4-2) echo "S1 S2" ;;
        4-3) echo "S4 S5" ;;
        4-4) echo "S6" ;;
        2-1) echo "S3 S7 S6" ;;
        2-2) echo "S1 S2 S4 S5" ;;
        6-1) echo "S3 S7" ;;
        6-2) echo "S1" ;;
        6-3) echo "S2" ;;
        6-4) echo "S4" ;;
        6-5) echo "S5" ;;
        6-6) echo "S6" ;;
        8-1) echo "S3 S7" ;;
        8-2) echo "S1" ;;
        8-3) echo "S2" ;;
        8-4) echo "S4" ;;
        8-5) echo "S5" ;;
        8-6) echo "S6" ;;
        8-7) echo "S1" ;;
        8-8) echo "S4" ;;
        *) die 1 "stories_for_shard: 未定義の組み合わせ N=${n} shard=${shard}" ;;
    esac
}

# --- self-test (実資源に触れない) ----------------------------------------------

cmd_self_test() {
```
### scripts/bug-hunt-shard.sh (L1213-1250)
```
    expect_ok() { local fn=$1; shift; local rc=0; ( "${fn}" "$@" ) >/dev/null 2>&1 || rc=$?; [[ "${rc}" == 0 ]]; }

    echo "[a] 資源導出"
    [[ "$(shard_db 0)" == "bug_hunt" ]] || t_fail "shard_db serial"
    [[ "$(shard_db 1)" == "bug_hunt_1" ]] || t_fail "shard_db"
    [[ "$(shard_db 8)" == "bug_hunt_8" ]] || t_fail "shard_db cap=8"
    [[ "$(shard_port 0)" == "8010" ]] || t_fail "shard_port serial"
    [[ "$(shard_port 4)" == "8014" ]] || t_fail "shard_port"
    [[ "$(shard_port 8)" == "8018" ]] || t_fail "shard_port cap=8"
    [[ "$(shard_url 2)" == "http://127.0.0.1:8012" ]] || t_fail "shard_url"
    [[ "$(shard_profile_dir 1)" == "${TMP_BASE}/profile-1" ]] || t_fail "shard_profile_dir"
    [[ "$(shard_download_dir 1)" == "${TMP_BASE}/downloads-1" ]] || t_fail "shard_download_dir"
    [[ "$(shard_trace_dir 1)" == "${TMP_BASE}/trace-1" ]] || t_fail "shard_trace_dir"
    t_ok "derivations + per-shard resource uniqueness"

    echo "[b] 範囲外 shard の拒否 (exit 2、cap=8)"
    local bad good rc fp_before
    for bad in 9 -1 x ""; do
        rc=0; (validate_shard "${bad}") 2>/dev/null || rc=$?
        [[ "${rc}" == 2 ]] || t_fail "shard '${bad}' が exit ${rc} (expected 2)"
    done
    for good in 0 4 8; do
        rc=0; (validate_shard "${good}") 2>/dev/null || rc=$?
        [[ "${rc}" == 0 ]] || t_fail "shard ${good} が拒否された"
    done
    t_ok "shard validation"

    echo "[c] guard_shard_db_name: dev DB / 別名バリアントは全 abort、bug_hunt 系は通過 (cap=8)"
    local v
    for v in app App ' app ' 'app ' bug_huntx bug_hunt2 bug_hunt_9 'bug_hunt;rm' myapp_dev ''; do
        expect_die guard_shard_db_name "${v}" || t_fail "guard_shard_db_name が '${v}' を abort しない"
    done
    for v in bug_hunt bug_hunt_1 bug_hunt_4 bug_hunt_8; do
        expect_ok guard_shard_db_name "${v}" || t_fail "guard_shard_db_name が '${v}' を拒否"
    done
    t_ok "shard db name deny"

    echo "[d] guard_bughunt_runtime: user≠bughunt / DB名不正で abort、正常で通過"
```
### scripts/bug-hunt-shard.sh (L1378-1402)
```
        echo "[r][s] provision-all/lock (SKIP: flock 不在。Linux devcontainer では実行される)"
    else
    echo "[r] provision-all (dryrun): run-id 採番 + shard 1..N provision + stories 記録 + run-id 印字"
    export BUGHUNT_SELFTEST_DRYRUN=1
    local pa_log="${sandbox}/provision-all.log"
    rc=0; ("${SCRIPT_PATH}" provision-all --parallel=2) > "${pa_log}" 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "provision-all --parallel=2 (dryrun) が exit ${rc} (expected 0)"
    grep -q '^run-id=' "${pa_log}" || t_fail "provision-all が run-id= を印字しない"
    local pa_run_id; pa_run_id="$(grep '^run-id=' "${pa_log}" | head -1 | cut -d= -f2)"
    [[ -n "${pa_run_id}" ]] || t_fail "provision-all の run-id 抽出に失敗"
    [[ "$(manifest_get "${pa_run_id}" - parallel)" == "2" ]] || t_fail "provision-all: manifest parallel≠2"
    [[ "$(manifest_get "${pa_run_id}" 1 stories)" == "S3 S7 S6" ]] || t_fail "provision-all: shard-1 stories 未記録"
    [[ "$(manifest_get "${pa_run_id}" 2 stories)" == "S1 S2 S4 S5" ]] || t_fail "provision-all: shard-2 stories 未記録"
    [[ ! -f "$(run_dir "${pa_run_id}")/child-pids" ]] || t_fail "provision-all が子を起動している (child-pids 検出)"
    local pa8_log="${sandbox}/provision-all-8.log"
    rc=0; ("${SCRIPT_PATH}" provision-all --parallel=8) > "${pa8_log}" 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "provision-all --parallel=8 (dryrun) が exit ${rc} (expected 0、cap=8)"
    local pa8_run_id; pa8_run_id="$(sed -n 's/^run-id=//p' "${pa8_log}" | head -1)"
    [[ "$(manifest_get "${pa8_run_id}" - parallel)" == "8" ]] || t_fail "provision-all --parallel=8: manifest parallel≠8"
    [[ "$(manifest_get "${pa8_run_id}" 8 stories)" == "S4" ]] || t_fail "provision-all --parallel=8: shard-8 stories 未記録 (2nd pass S4)"
    rc=0; ("${SCRIPT_PATH}" provision-all --parallel=3) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 2 ]] || t_fail "provision-all --parallel=3 が exit ${rc} (expected 2、未定義 N)"
    unset BUGHUNT_SELFTEST_DRYRUN
    t_ok "provision-all dryrun (cap=8 受理 / N=3 拒否)"

```
### scripts/bug-hunt-shard.sh (L1900-1950)
```

main() {
    local sub="${1:-}"
    shift || true
    local shard="" run_id="" count=5 drop_db="" parallel=4 hold_lock=""
    COVERAGE=""    # --coverage: pcov 付きで serve 起動しコード到達カバレッジを収集 (既定 OFF)
    # モードは既定 real-llm + fake-storage。専用フラグ変数で「同時指定」「適用範囲」を判定する
    # (LLM_MODE/STORAGE_MODE の上書きだけだと「既定と同値の明示指定」を取りこぼすため)。
    LLM_MODE="real"; STORAGE_MODE="fake"
    local _llm_flag_real=0 _llm_flag_fake=0 _storage_flag_real=0

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --shard) shard="${2:-}"; shift 2 ;;
            --run-id) run_id="${2:-}"; shift 2 ;;
            --count) count="${2:-}"; shift 2 ;;
            --parallel=*) parallel="${1#--parallel=}"; shift ;;
            --parallel) shift ;;
            --coverage) COVERAGE=1; shift ;;
            --real-llm) LLM_MODE="real"; _llm_flag_real=1; shift ;;
            --fake-llm) LLM_MODE="fake"; _llm_flag_fake=1; shift ;;
            --real-storage) STORAGE_MODE="real"; _storage_flag_real=1; shift ;;
            --drop-db) drop_db="--drop-db"; shift ;;
            --hold-lock) hold_lock="--hold-lock"; shift ;;
            *) die 2 "unknown option: $1" ;;
        esac
    done

    if [[ -n "${COVERAGE}" ]]; then
        [[ "${sub}" == "provision" || "${sub}" == "provision-all" ]] \
            || die 2 "--coverage は provision または provision-all でのみ使える"
    fi

    # モードフラグ: 相互排他 + provision 系専用 (--coverage と同じ流儀。teardown --real-llm 等も拒否)。
    if [[ "${_llm_flag_real}" == 1 && "${_llm_flag_fake}" == 1 ]]; then
        die 2 "--real-llm と --fake-llm は同時指定できません (モードを 1 つ選ぶ)"
    fi
    if [[ "${_llm_flag_real}" == 1 || "${_llm_flag_fake}" == 1 || "${_storage_flag_real}" == 1 ]]; then
        [[ "${sub}" == "provision" || "${sub}" == "provision-all" ]] \
            || die 2 "--real-llm / --fake-llm / --real-storage は provision または provision-all でのみ使える"
    fi

    case "${sub}" in
        provision)
            validate_shard "${shard}"; validate_run_id "${run_id}"
            cmd_provision "${shard}" "${run_id}" ;;
        provision-all)
            valid_parallel_n "${parallel}" || die 2 "--parallel は 2/4/6/8 のみ (固定ストーリーマップのため。cap=8)"
            cmd_provision_all "${parallel}" "${hold_lock}" ;;
        reseed)
            validate_shard "${shard}"; validate_run_id "${run_id}"
```
### tests/Support/Ci/TestDatabaseEnv.php (L36-56)
```
     * dev DB 名の hard-deny 対象 (docker-compose の POSTGRES_DB / slug 既定値)。trim+lowercase 比較。
     *
     * `bug_hunt*` は allowlist regex でも構造的に除外されるが、
     * 「bug-hunt 環境の DB は絶対に触らない」(AGENTS.md §bug-hunt の dev DB 防御) という
     * 意図をコードに残す二重防御として明示列挙する。
     */
    public const DEV_DB_DENYLIST = [
        'app',
        'bug_hunt',
        'bug_hunt_1',
        'bug_hunt_2',
        'bug_hunt_3',
        'bug_hunt_4',
        'bug_hunt_5',
        'bug_hunt_6',
        'bug_hunt_7',
        'bug_hunt_8',
    ];

    /**
     * 孤児 sweep の分類ロジックのバージョン。
```
### tests/Unit/Ci/TestDatabaseEnvTest.php (L95-125)
```
it('hard-denies bug-hunt databases', function (string $variant): void {
    expect(TestDatabaseEnv::isDevDatabase($variant))->toBeTrue()
        ->and(TestDatabaseEnv::isAllowedTestDatabase($variant))->toBeFalse();
})->with([
    'bug_hunt',
    'bug_hunt_1',
    'bug_hunt_8',
    'BUG_HUNT_3',
    ' bug_hunt_5 ',
]);

it('covers every bug-hunt shard database in the denylist', function (): void {
    // shard は :8011..:8018 = bug_hunt_1..8 (scripts/bug-hunt-shard.sh)。取りこぼしを機械検出する。
    $expected = ['app', 'bug_hunt'];
    for ($i = 1; $i <= 8; $i++) {
        $expected[] = "bug_hunt_{$i}";
    }

    expect(TestDatabaseEnv::DEV_DB_DENYLIST)->toBe($expected);
});

it('does not deny unrelated names that merely start with bug_hunt', function (): void {
    expect(TestDatabaseEnv::isDevDatabase('bug_hunt_9'))->toBeFalse()
        ->and(TestDatabaseEnv::isDevDatabase('bug_hunts'))->toBeFalse()
        // allowlist に載らないので DROP 経路には到達しない (denylist は二重防御の側)
        ->and(TestDatabaseEnv::isAllowedTestDatabase('bug_hunt_9'))->toBeFalse();
});

it('assertPgsqlTestDatabaseSafe throws on bug-hunt databases', function (): void {
    TestDatabaseEnv::assertPgsqlTestDatabaseSafe('bug_hunt_3');
})->throws(InvalidArgumentException::class);
```
### tests/Architecture/BughuntOrchestratorGateInvariantTest.php (L145-215)
```
/**
 * AGENTS.md §bug-hunt が規約側に持つべき記述の違反一覧 (純関数 = 負のコントロール用)。
 *
 * @return list<string>
 */
function bughuntAgentsMdViolations(string $content): array
{
    $violations = [];
    if (! str_contains($content, 'BUGHUNT_ORCHESTRATOR=1')) {
        $violations[] = 'AGENTS.md に BUGHUNT_ORCHESTRATOR=1 の記述が無い';
    }
    if (! str_contains($content, 'default-deny')) {
        $violations[] = 'AGENTS.md に default-deny の明記が無い';
    }
    // 機械 gate の対象コマンドが規約側にも書かれていること。
    if (preg_match('/`provision`\/`teardown`/', $content) !== 1) {
        $violations[] = 'AGENTS.md に gate 対象コマンド (provision/teardown) の明記が無い';
    }

    return $violations;
}

/**
 * bughunt-shard.md (worker への散文 gate) が持つべき記述の違反一覧 (純関数)。
 *
 * @return list<string>
 */
function bughuntShardAgentViolations(string $content): array
{
    $violations = [];
    foreach (['B-HARNESS-01', '環境障害時の鉄則'] as $needle) {
        if (! str_contains($content, $needle)) {
            $violations[] = "bughunt-shard.md に「{$needle}」が無い";
        }
    }
    // 復旧を試みず報告して終了する規律。
    if (preg_match('/復旧を絶対に試みない/u', $content) !== 1) {
        $violations[] = 'bughunt-shard.md に「復旧を絶対に試みない」規律が無い';
    }
    // 禁止コマンド列 (worktree / provision 系)。
    foreach (['teardown-worktree.sh', 'setup-worktree.sh', 'provision-all'] as $needle) {
        if (! str_contains($content, $needle)) {
            $violations[] = "bughunt-shard.md の禁止コマンド列に {$needle} が無い";
        }
    }
    if (preg_match('/git worktree (add|remove|prune)/', $content) !== 1) {
        $violations[] = 'bughunt-shard.md の禁止コマンド列に git worktree 操作が無い';
    }
    // 散文 gate 単独ではなく「機械的にも拒否される」ことを worker に伝える = 2 層の呼応。
    if (! str_contains($content, 'BUGHUNT_ORCHESTRATOR')) {
        $violations[] = 'bughunt-shard.md が機械 gate (BUGHUNT_ORCHESTRATOR) に言及していない';
    }
    // 環境ハザードは自分の shard-report.md に記録して終了する。
    if (preg_match('/shard-report\.md/', $content) !== 1) {
        $violations[] = 'bughunt-shard.md に shard-report.md への記録指示が無い';
    }

    return $violations;
}

test('AGENTS.md §bug-hunt が BUGHUNT_ORCHESTRATOR の default-deny を規約として持つこと', function (): void {
    expect(bughuntAgentsMdViolations(bughuntGateReadSource('AGENTS.md')))->toBe([]);
});

test('bughunt-shard.md が環境障害鉄則・禁止コマンド列・機械 gate との呼応を持つこと', function (): void {
    expect(bughuntShardAgentViolations(bughuntGateReadSource('.claude/agents/bughunt-shard.md')))->toBe([]);
});

/*
 * 負のコントロール: gate が「壊れた配線」を実際に検出することを fixture で確認する
 * (実スクリプトは書き換えない)。
```
### tests/Architecture/BughuntEnvExampleContractTest.php (L105-125)
```
    expect(bughuntEnvExampleViolations($content))->toBe([]);
});

test('.env.bughunt.local.example の DB_DATABASE が shard script の DB 接頭辞既定と一致すること', function (): void {
    $content = file_get_contents(base_path('.env.bughunt.local.example'));
    expect($content)->toBeString();
    /** @var string $content */
    $env = [];
    expect(preg_match('/^DB_DATABASE=([^\s#]*)/m', $content, $env))->toBe(1);
    /** @var array{0: string, 1: string} $env */
    $script = file_get_contents(base_path('scripts/bug-hunt-shard.sh'));
    expect($script)->toBeString();
    /** @var string $script */
    $prefix = [];
    expect(preg_match('/^BUGHUNT_DB_PREFIX="\$\{BUGHUNT_DB_PREFIX:-([a-z_]+)\}"/m', $script, $prefix))->toBe(1);
    /** @var array{0: string, 1: string} $prefix */

    // 乖離すると直列走行 (shard 0) の DB 名が guard regex (^bug_hunt(_[1-8])?$) を外れて abort する。
    expect(trim($env[1], "\"'"))->toBe($prefix[1]);
});

```
### database/seeders/Concerns/DetectsBughuntDatabase.php (L1-25)
```
<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * bug-hunt DB 名判定の SSOT (bug-hunt 隔離規約 ^bug_hunt(_[1-8])?$ と一致)。
 * bughunt 系 seeder の fail-secure guard から参照する。
 */
trait DetectsBughuntDatabase
{
    /** bug-hunt DB 名の許容 regex (scripts/bug-hunt-shard.sh の guard と一致させる)。 */
    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';

    private function isBughuntDatabase(): bool
    {
        return preg_match(self::BUGHUNT_DB_REGEX, DB::connection()->getDatabaseName()) === 1;
    }
}
```
### AGENTS.md (L205-229)
```
  復旧手順は `docs/pnpm-global-virtual-store-runbook.md`(GVS 無効化・暗黙 peer・ENOMEM 等)

## bug-hunt (LLM 探索的バグハント、オプトイン)

`.claude/skills/app-bug-hunt/` は自由探索型の UX バグハント基盤。回帰テストでは見つからない
説明なしリダイレクト・操作詰み・IDOR・UX 破綻を、隔離 bughunt 環境 (直列 `:8010` / 並列 shard
`:8011..8018`、DB `bug_hunt(_N)`) で実ブラウザ走行して発見する (修正はしない)。起動は `/app-bug-hunt`。

- **オプトイン・完全 no-op**: 未使用時はアプリ実行に一切影響しない。`config/bughunt.php` と
  `BughuntCoverageMiddleware` は `env(BUGHUNT_PCOV)` + `function_exists('\pcov\start')` の二重 guard で
  pcov 未導入の本番/CI/dev では常に no-op。`BughuntOAuthSeeder` は fake_externals + bughunt.local +
  `^bug_hunt(_[1-8])?$` の三重 fail-secure ガードで、条件不成立なら no-op (dev DB に認証状態をばら撒かない)。
- **dev DB 防御 (非交渉)**: 全 DB 操作は `scripts/bug-hunt-shard.sh` の用途別 wrapper (`env -i` で
  shell の `DB_*`/`PG*` を遮断 + DB名 regex + role guard) 経由のみ。生 artisan/psql/tinker/createdb/dropdb 禁止。
  `provision`/`teardown` は `BUGHUNT_ORCHESTRATOR=1` を持つ親のみ (worker は default-deny)。
- **worktree 既定**: bug-hunt は worktree から走る (`scripts/bughunt-worktree-hook.sh` の PreToolUse ガードが
  main 直叩きを早期に止める。配線は `.claude/settings.bughunt-hook.example.json` を `.claude/settings.json` にマージ)。
- **スケルトン**: `screens.md` / `operations.md` / `stories/` はテンプレートでは空スケルトン。初回に
  `php artisan route:list` から生成する (SKILL.md Phase 1)。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
- **capability 語彙**: finding の `capability_tag` の正本は
  `.claude/skills/app-bug-hunt/capability-catalog.md`(SOP→シナリオ→撮影→レンダの責務境界を
  先に定義し、その上に capability_id を割り当てる。未割当は `unmapped`・tag 不能は `unknown`)。
- 検証: `scripts/bug-hunt-shard.sh self-test` (実資源に触れず guard/資源導出/env 隔離/asset 鮮度を検証)。
  Python ツール (`coverage/` `ledger/`) は `python3 -m unittest` (stdlib のみ)。

```
### .claude/skills/app-bug-hunt/SKILL.md (L36-45)
```
| (引数なし) | — | 既定で `--all --coverage --parallel --deviate --real-llm` 相当を実行 (worktree 走行) |
| S1..S7 | No | 実行するストーリーカード (stories/ 配下、複数指定可)。明示するとその指定分だけに絞る (直列走行) |
| --all | No | 全ストーリーを実行 (S7 は S3 の状態を前提にするため S3 の後)。既定に含まれる |
| --coverage | No | serve を pcov 付き php で起動しコード到達カバレッジ (C3) を収集する。既定に含まれる。pcov 未導入環境では middleware が no-op で安全に続行 |
| --no-coverage | No | カバレッジ計装を省く (既定の --coverage を打ち消す) |
| --parallel[=N] | No | 並列シャード実行 (N=2/4/6/8、cap=8、既定 4)。既定に含まれる。親はインベントリ確認 → `provision-all` → `bughunt-shard` subagent を Workflow で N 体 fan-out → `verify-run` → 統合レポート |
| --deviate | No | 各ストーリー末尾の「逸脱アイデア」も実行する。既定に含まれる |
| --no-deviate | No | 逸脱探索を省く |
| --real-llm | No | LLM を実 Anthropic API に接続して走行する (既定)。親リポジトリ `.env` の `ANTHROPIC_API_KEY` が必須で、未設定なら provision が fail-fast する。生成内容・所要時間は run ごとに非決定的 |
| --fake-llm | No | LLM を canned 応答 (T035) に切り替える (実 API 未接続)。再現・切り分け用。`--real-llm` とは同時指定不可 |
```
### .claude/skills/app-bug-hunt/SKILL.md (L120-130)
```
7. **インベントリ修正の反映**: 統合 report に記録した採用分のみを screens.md / operations.md / stories に反映する。
8. **adjudication 追記の規律 (人手判断時のみ)**: finding を誤検知 / 意図的仕様 / won't-fix と確定したら、
   cross-session の再 triage を避けるため `ledger/adjudications.jsonl` に 1 行 append (既存行は編集しない)。
   詳細スキーマは `ledger/README.md`。

ストーリー割り当ては固定マップ (`scripts/bug-hunt-shard.sh` の `stories_for_shard`。S3→S7 の状態依存を shard-1 に
閉じ込める。cap=8、`--parallel` は 2/4/6/8)。N=8 は S1/S4 の独立 2nd pass で埋め、統合レポートが route×症状で dedupe する。

### 隔離と権限

- **cookie 隔離 (S7 の要)**: 各 `playwright-cli -s=bughunt{i}` は別の隔離ブラウザ (別 cookie/storage)。
```
### .env.bughunt.local.example (L1-45)
```
# ==========================================
# bug-hunt (LLM 探索的バグハント) 専用環境設定 テンプレート
#
# 資源の対応:
#   直列走行: APP_ENV=bughunt.local / DB=bug_hunt / :8010
#   並列 shard: DB=bug_hunt_{1..8} / :8011..8018 (scripts/bug-hunt-shard.sh が注入)
#
# 使い方:
#   1. cp .env.bughunt.local.example .env.bughunt.local
#   2. APP_KEY は `APP_ENV=bughunt.local php artisan key:generate --env=bughunt.local` で生成
#   3. CIPHERSWEET_KEY は `php artisan ciphersweet:generate-key` の値を設定
#   4. DB_USERNAME / DB_PASSWORD は専用 role `bughunt` の credential に「上書き必須」
#      (dev DB へ CONNECT/CREATE/DROP 権限を持たない role)
#   5. BUGHUNT_ADMIN_USER / BUGHUNT_ADMIN_PASSWORD は CREATE DATABASE 権限を持つ admin role
#      (provision の createdb/dropdb 専用。bughunt role は CREATEDB を持たない)
#   6. DB 作成・migrate・serve 起動は scripts/bug-hunt-shard.sh provision が行うため手動不要
#
# 隔離方針 (dev DB を wipe しないための 3 軸):
#   (a) DB 名 `^bug_hunt(_[1-8])?$` のみ許可  (b) 専用 role `bughunt` (CREATEDB なし)
#   (c) PostgreSQL 権限 (dev DB への CONNECT/CREATE/DROP 不可)
# host は dev クラスタと同一を許容する (隔離は上記 3 軸で担保。host 値は guard の判定軸に含めない)。
# ==========================================

APP_ENV=bughunt.local
# 表示用アプリ名。dev の .env と同じ実値を書く (このファイルは単独ロードされるため
# "${APP_NAME}" のような自己参照は解決されず、リテラルがそのまま画面に露出する)
APP_NAME="AI-CUE"
# bug-hunt はユーザー向け文言 (日本語) の検証環境
APP_LOCALE=ja
APP_URL=http://127.0.0.1:8010

# 上書き必須: 空のまま `APP_ENV=bughunt.local php artisan key:generate --env=bughunt.local` で自動生成
APP_KEY=
# モデル暗号化列 (CipherSweet) の鍵。`php artisan ciphersweet:generate-key` で生成した値を設定する
# (空のままだと migrate/seed が StringProvider TypeError で落ちる)
CIPHERSWEET_KEY=

DB_CONNECTION=pgsql
DB_HOST=db                          # dev クラスタと同一 host を許容 (隔離は DB名+専用role+権限)
DB_PORT=5432
DB_DATABASE=bug_hunt                # provision が作成。^bug_hunt(_[1-8])?$ のみ許可
DB_USERNAME=bughunt                 # 上書き必須。dev DB へ CONNECT/CREATE/DROP 権限を持たない専用 role
DB_PASSWORD=                        # 上書き必須 (bughunt role の password)

# CREATE DATABASE 権限を持つ admin role (provision の createdb/dropdb 専用)。
```
### scripts/run-browser-test.sh (L40-70)
```

# --- bug-hunt 併走の pre-flight guard (best-effort。保証ではない) ---
#
# **ロック取得より前**に実行する。取得後に落とすと、先行レーンの終了を数分待ってから
# 「bug-hunt が走っているので実行できません」と言うことになり、待ち時間が無駄になる。
#
# bug-hunt は本ロック規約に参加しない (意図的に隔離された並列実行基盤で、
# global lock を被せると 8 並列が 1 直列に潰れる)。そのため bug-hunt の
# `playwright-cli kill-all` (@playwright/cli) が Browser lane の run-server を
# 巻き込む可能性を **こちらからは証明できない**。
#
# ここで行うのは「起動時点で bug-hunt が既に走っている」という頻度の高いケースだけを
# 捕まえる best-effort guard であり、**TOCTOU がある** (Browser lane 開始後に
# bug-hunt が起動する経路、bug-hunt が listen していない起動フェーズは捕まえられない)。
# 非干渉は保証しない — 失敗モードが偽赤であって偽グリーンではないため受容する。
#
# 検知は bash の /dev/tcp のみを使う (ss/lsof/netstat の可用性と出力形式に依存しない)。
# bug-hunt は 127.0.0.1:801N に明示 bind するので IPv4 loopback だけ見れば足りる。
# /dev/tcp が使えないシェルでは検査を skip して続行する (guard であって保証ではない)。
bughunt_port_in_use() {
    local port
    for port in {8010..8018}; do
        if (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null; then
            exec 3<&- 3>&- 2>/dev/null || true
            printf '%s\n' "${port}"
            return 0
        fi
    done
    return 1
}

```
### docs/testing-browser.md (L30-45)
```
`vendor/bin/pest -c phpunit.browser.xml` を呼ぶ。排他は **グローバルテストロック**
(`scripts/global-test-lock.sh` / `/tmp/global-test-lane-<uid>.d/lock`) に一本化されており、
`composer test` / `pnpm test` / `pnpm test:packages` を含む全テストレーンと
**同一 UID・同一マシン単位**で相互排他する (worktree をまたぐ)。旧 worktree-local な
flock は cross-worktree の相互破壊を防げないため廃止した。先行レーンがいる場合は
**待つ** (旧実装は待たずに即エラー終了していた)。並列数を未指定 (= nproc) に
すると同時起動で環境がハングし得るため既定 1 に固定している。

Browser lane は起動時に bug-hunt 環境のポート (`127.0.0.1:8010..8018`) を
best-effort で覗き、listen していれば **ロックを取る前に** fail-fast する。
bug-hunt はロック規約に参加しない (意図的に隔離された 8 並列基盤) ため、
非干渉は保証しない — TOCTOU のある guard であり、失敗モードが偽赤に留まる範囲で受容している。

### ブラウザレーン (Chromium + WebKit)

`composer test:browser` は **Chromium レーン → WebKit レーンの順で 2 回** pest を実行し、
```


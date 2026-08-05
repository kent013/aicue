# 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Laravel + Svelte 5 (Inertia) アプリ "AI-CUE" のコードレビュアーである。
TODO T117「bug-hunt 並列枠数 cap を 8 → 4 に統一 (c2c 裁定 AG-048b 追従)」の実装差分をレビューせよ。

## レビュー観点
1. **設計との一致性**: 添付の詳細設計書の施策 1〜5 が漏れなく・過不足なく実装されているか
2. **正確性**: bash の算術判定 / set -euo pipefail 下の落とし穴 / PCRE の挙動 / PHP 純関数のロジック
3. **設計の中心原則の遵守**: 「触れる対象 (allowlist) は狭める / 守る対象 (denylist・検出側・pre-flight guard) は狭めない」。
   守りの面の値が誤って 4 に縮められていないか、逆に allowlist が 8 のまま残っていないか
4. **新規 Architecture テストの検出力**: 下記 2 点を**特に重点的に**確認せよ (設計レビュー段階で Codex の再確認を受けていない箇所)
   - (a) Tier A (割り当て値) / Tier B (literal) の 2 層分離と `bughuntCapAllocationValues()` の抽出構文が、
     偽陽性を出さずに本当に必要な違反を捉えているか。抜けている「割り当て値の書き方」は無いか
   - (b) `cap-defense-ok` マーカーが Tier A を免除しない規則が本当に bypass 不能か。
     マーカー allowlist / 守りの語の 2 条件に抜け道は無いか
5. **PHPStan level 10 適合性**（`@phpstan-ignore` / baseline / 型 widen は禁止）
6. **テスト網羅性**: 既存テストのアサーションを緩めていないか、削除・無効化していないか
7. **セキュリティ**: dev DB 防御 (SHARD_DB_RE / guard 群 / require_orchestrator) が後退していないか。
   BUGHUNT_DB_PREFIX の形式検証が「検証 → SHARD_DB_RE 代入」の順で正しく効いているか

## 出力形式
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示する

## 注意
- フロントエンド (resources/js, resources/css) の差分は無い。DESIGN.md / Atomic Design 観点は該当なし
- スコープ外と明記されている項目 (bug-hunt-inventory-check.sh / config/bughunt.php / 実 bug-hunt 実行 /
  残留 DB 削除 / 他リポジトリ展開) を「やるべきだ」と指摘しないこと

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
# ★ SHARD_DB_RE の代入はここではなく 1-a2 (prefix 検証の直後) に置く。
```

#### 1-a2. `BUGHUNT_DB_PREFIX` の形式検証 → `SHARD_DB_RE` 構築 (Codex R1/R2 Warning 反映)

`BUGHUNT_DB_PREFIX` は env 上書き可能なまま `SHARD_DB_RE` (= dev DB 防御の核) に**escape なしで
埋め込まれている**。regex メタ文字が入ると allowlist が壊れる (例: `b.g_hunt` は `bXg_hunt` に
マッチしてしまう)。今回まさにこの行に「外から広げられない」と書くため、**実態を合わせる**。

**配置は `die()` の定義 (現行 L83) の直後。「検証 → 代入」の順で連続させる**
(Codex R2 Warning: 施策 5 の構造テストが「埋め込み前に検証」を要求するため、
定数ブロックで先に代入してはならない):

```bash
die() { local code=$1; shift; echo "error: $*" >&2; exit "${code}"; }

# ★ prefix は SHARD_DB_RE にそのまま埋め込まれる。regex メタ文字が入ると allowlist が壊れるため、
#   埋め込む前に形を固定する (「別名の bug-hunt DB 群を選ぶ」既存の自由度は保つ)。
[[ "${BUGHUNT_DB_PREFIX}" =~ ^[a-z][a-z0-9_]*$ ]] \
    || die 1 "BUGHUNT_DB_PREFIX が不正: '${BUGHUNT_DB_PREFIX}' (^[a-z][a-z0-9_]*\$ のみ。regex メタ文字は allowlist を壊す)"

# ★ 本スクリプトが createdb/dropdb/migrate してよい shard DB の **allowlist**。cap と同期する。
#   「残留も含めて bug-hunt DB を守る / 検出する」側 —
#   tests/Support/Ci/TestDatabaseEnv::DEV_DB_DENYLIST と
#   database/seeders/Concerns/DetectsBughuntDatabase::BUGHUNT_DB_REGEX — は **cap と同期させない**
#   (狭めると過去 cap=8 期の残留 DB を守れなくなる)。方向が逆であることに注意。
SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"
```

> `SHARD_DB_RE` は `guard_shard_db_name` (現行 L163) より前に定義されていればよく、
> `die` 定義直後は十分早い。既定値 `bug_hunt` は当然通る。

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

**Codex R1 Warning への反論 (記録)**: 「算術化すると cap を 6 に上げた瞬間に `6` が受理され、
エラーが `valid_parallel_n` の exit 2 ではなく `stories_for_shard` の die 1 になる」という指摘は
事実だが、**受理集合を `2|4` の手書き列挙に戻す案は採らない**。cap の SSOT 化が本タスクの主目的で、
列挙に戻すと同じ数字がまた 2 箇所に分かれる (今回直している問題そのもの)。
exit code がずれるのは「cap を上げたのに story map を足していない」状態でのみ起き、それは
**Architecture テストの map 完全性検査 + self-test [r] の 2 重でリリース前に必ず赤くなる**。
運用に出ない失敗モードのために SSOT を崩す取引はしない。
ただし Codex の受入条件はそのまま採用し、上記コメントに
「**cap を上げる場合は `stories_for_shard` の追加が同一変更で必須**」を明記する。

#### 1-d. manifest の shard key 検証 (L386-397)

**現行**
```bash
    MF="${mf}" python3 - <<'PY'
...
    if re.fullmatch(r"[0-8]", key):
```

**変更後** (Codex R1 Warning: parser 側でも cap の 1 桁性を fail-fast する)
```bash
    MF="${mf}" CAP="${BUGHUNT_SHARD_CAP}" python3 - <<'PY'
...
cap = os.environ["CAP"]
if not re.fullmatch(r"[2-9]", cap):
    raise SystemExit(f"invalid BUGHUNT_SHARD_CAP for manifest parser: {cap!r}")
for key in data.get("shards", {}):
    if re.fullmatch(rf"[0-{cap}]", key):
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

#### 2-0. sandbox の環境非依存化 (Codex R1 Warning 反映)

`cmd_self_test` の sandbox 初期化 (現行 L1205-1213、`RUN_BASE=` などを差し替えている箇所) で
**`BUGHUNT_DB_PREFIX` を既定値に固定し、`SHARD_DB_RE` を再導出**する。
self-test は「実資源に触れない自己検証」であり、外部 env に `BUGHUNT_DB_PREFIX` が入っていると
`shard_db 0 == bug_hunt` 等の期待値が環境依存で赤くなるため。

```bash
    # self-test は環境非依存であるべき (外部 env の BUGHUNT_DB_PREFIX に影響されない)。
    BUGHUNT_DB_PREFIX=bug_hunt
    SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"
```

併せて、`BUGHUNT_DB_PREFIX` 検証 (施策 1-a2) の負のアサーションを 1 行追加する:

```bash
    rc=0; (BUGHUNT_DB_PREFIX='b.g_hunt' "${SCRIPT_PATH}" self-test) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 1 ]] || t_fail "不正な BUGHUNT_DB_PREFIX でスクリプトが起動してしまう (exit ${rc})"
```

> 再帰呼び出しになるが、不正 prefix では**引数解析より前に die 1** するため無限再帰にはならない。
> 実装時に手元で 1 度確認すること (確認できない場合は、この 1 行を
> `expect_die` 相当の関数呼び出し形に置き換えてよい)。

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
    # ★ stories_for_shard は未定義時 die 1 する。command substitution の失敗が set -e で
    #   self-test 全体を殺さないよう、rc を分離して受ける (Codex R1 Critical 反映)。
    local n i stories srv_rc
    for n in $(seq 2 "${BUGHUNT_SHARD_CAP}"); do
        valid_parallel_n "${n}" || continue
        for i in $(seq 1 "${n}"); do
            srv_rc=0
            stories="$(stories_for_shard "${i}" "${n}")" || srv_rc=$?
            [[ "${srv_rc}" == 0 && -n "${stories}" ]] \
                || t_fail "stories_for_shard 未定義: N=${n} shard=${i} (rc=${srv_rc})"
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
 *   4. 「割り当て」を説明する散文 (CAP_ALLOCATION_DOCS) に cap 超過が残っていない
 *      - Tier A (割り当て値): 行から構文で抽出した値が cap 超過 → **マーカーで免除できない**
 *      - Tier B (literal): ポート / DB 名 / 範囲表記 → `cap-defense-ok` マーカーで免除できる
 *   5. 「守り」の面 (CAP_DEFENSE_SURFACES) は **意図的に cap より広い**。値を直接固定する
 *
 * ★ 4 と 5 は逆向きの検査である。5 の面を 4 に含めてはならない
 *   (含めると防御を狭める方向へ改変が誘導される)。
 * ★ scripts/bug-hunt-shard.sh は 4 の対象に含めない。自身のコメントが 5 の説明を持つため
 *   偽陽性になる。スクリプトは 1〜3 の構造検査で固定する。
 * ★ `cap-defense-ok` は「守りが cap より広い理由」を書く行にのみ使う。
 *   Tier A (割り当て値) は**マーカーがあっても違反**なので、bypass にはならない。
 */

/** cap の正本を scripts/bug-hunt-shard.sh から抽出する。 */
function bughuntCapFromScript(string $script): int;

/**
 * scripts/bug-hunt-shard.sh の**構造**違反の一覧 (純関数)。
 * ★ 本スクリプトは散文 scan の対象にしない (自身のコメントが守りの説明を含み偽陽性になるため。
 *   Codex R1 Critical 反映)。代わりにこの構造検査で cap 導出を固定する。
 *
 * @return list<string>
 */
function bughuntCapScriptViolations(string $script): array;

/**
 * 1 行から「割り当て値として書かれた数値」を構文で抽出する (純関数)。★ 検出ロジックの単一ソース。
 *
 * 散文検査 (Tier A) とマーカー無効化判定の**両方がこの 1 本を使う**
 * (Codex R3 Warning: 別々の正規表現集合を持つと再び差分が生じるため)。
 *
 * 抽出対象の構文 (数字の近接では判定しない):
 *   - `--parallel=8` / `--parallel は 2/4/6/8` / `--parallel: 8`
 *   - `parallel=8` / `parallel は 8`
 *   - `N=8`  (前が英数字でない場合のみ。`AG-048b` の `048` は当たらない)
 *   - `cap=8` / `cap = 8`
 *   - `shard 1..8` (`(?<![0-9])1\.\.([0-9]+)` なので `8011..8018` の `1..8` には当たらない)
 *
 * @return list<int> 抽出できた割り当て値 (順不同・重複可)
 */
function bughuntCapAllocationValues(string $line): array;

/**
 * 割り当て散文に残った cap 超過の一覧 (純関数)。
 *
 * 行単位で走査し、2 層で判定する:
 *   - **Tier A**: bughuntCapAllocationValues() の抽出値に cap 超過があれば違反。
 *     `cap-defense-ok` マーカーがあっても**免除しない** (bypass 防止)。
 *   - **Tier B**: ポート / DB 名 / 範囲表記の literal。マーカー行は免除する
 *     (「残留ポート :8018 までは guard が検出する。cap-defense-ok」のような
 *     正しい守りの説明を書けるようにするため)。
 *
 * マーカーの追加制約 (免除が効く前提条件):
 *   (a) ファイルが CAP_DEFENSE_MARKER_FILES に含まれること
 *   (b) 行に CAP_MARKER_DEFENSE_WORDS のいずれかが含まれること
 *   → どちらか欠ければマーカー自体を違反として報告する。
 *
 * @return list<string> 違反メッセージ (行番号 + tier 付き)。空なら合格
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
/**
 * 割り当て (触れる対象) を説明する散文。cap 超過 literal を deny-by-default で走査する。
 * ★ scripts/bug-hunt-shard.sh は**含めない** (自身のコメントが守りの説明を含むため。
 *   構造は bughuntCapScriptViolations() が別途固定する)。
 */
const CAP_ALLOCATION_DOCS = [
    'AGENTS.md',
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

#### 検出ロジック: 2 層構造 (Codex R3 Warning 反映)

Round 2 で足した「`--parallel` 近傍に cap 超過**数字**があるか」方式は偽陽性が広すぎた
(`parallel=4 の結果を S7 で検証する` / `--parallel=4 は AG-048b に基づく` /
`parallel 実行は 2025 年に導入した` を誤検出する)。
**「数字の近接」ではなく「割り当て値として書かれた数値」を構文で抽出する**方式に置き換える。

##### Tier A — 割り当て値 (`bughuntCapAllocationValues`)。マーカーで免除**できない**

| 抽出パターン (PCRE) | 例 | 抽出値 |
|---|---|---|
| `/--parallel\s*(?:=\|は\|:)\s*([0-9]+(?:\s*\/\s*[0-9]+)*)/u` | `--parallel は 2/4/6/8` | 2,4,6,8 |
| `/(?<![-a-z])parallel\s*(?:=\|は\|:)\s*([0-9]+(?:\s*\/\s*[0-9]+)*)/iu` | `parallel=8` | 8 |
| `/(?<![0-9A-Za-z])N\s*=\s*([0-9]+)(?![0-9])/u` | `N=8 を利用する` | 8 |
| `/cap\s*=\s*([0-9]+)(?![0-9])/iu` | `cap=8` | 8 |
| `/(?<![0-9])1\s*\.\.\s*([0-9]+)(?![0-9])/u` | `shard 1..8` | 8 |

抽出値に **cap 超過があれば違反**。マーカーがあっても免除しない。

- `S7` / `AG-048b` / `2025 年` はどのパターンにも当たらない (構文が違う)。
- `:8011..8018` は `1..8` の直前が数字 `1` なので lookbehind `(?<![0-9])` で除外される。
- `parallel=4 の結果を S7 で検証する` は 4 のみ抽出 → cap 以下なので合格。

##### Tier B — literal (ポート / DB 名 / 範囲表記)。マーカーで免除**できる**

| パターン (PCRE) | 意図 |
|---|---|
| `/801[5-9]/` | cap 超過ポート (:8015〜) |
| `/8011\s*\.\.\s*801[5-9]/` | ポート範囲の写経 |
| `/_\[1-[5-9]\]/` | `_[1-8]` 形の DB regex 写経 |
| `/bug_hunt_[5-9]/` | cap 超過 DB 名 |
| `/(?<![0-9])0-[5-9](?![0-9])/` | 「0-8」「`[0-8]`」形 |
| `/2\s*\/\s*4\s*\/\s*6/` | `--parallel` 受理値の写経 (`2/4/6/8` も `2/4/6` も捕まえる) |

> **実装時の注意**: `[5-9]` の部分は cap から**動的に生成**する (`cap+1 .. 9`)。上表は cap=4 の展開例。
> 負のコントロールで cap=6 を渡したとき `bug_hunt_5` が**違反にならない**ことを確認し、追従を固定する。

#### `cap-defense-ok` マーカーとその濫用防止 (Codex R1/R2/R3 Warning 反映)

Tier B の違反のみ、行に `cap-defense-ok` があれば免除する。
「残留ポート :8018 までは guard が検出する。cap-defense-ok」のような**正しい守りの説明**を
書けるようにするための明示 opt-out (c2c 台帳の `ref-ok` と同じ発想で、除外が目視できる)。

**bypass にならない理由**: Tier A (割り当て値) はマーカーの対象外なので、
`parallel=8 は guard 用。cap-defense-ok` や `N=8 は残留検出用。cap-defense-ok` は**通らない**
(Codex R3 が指摘した抜け道)。判定ロジックは `bughuntCapAllocationValues()` **1 本を共有**しており、
散文検査とマーカー判定で別々の正規表現集合を持たない = 再発差分が生じない。

さらにマーカーの置き場所と書き方を 2 条件で縛る:

```php
/** `cap-defense-ok` を書いてよいファイル。ここ以外のマーカーは違反。 */
const CAP_DEFENSE_MARKER_FILES = [
    'AGENTS.md',                                     // 割り当てと守りの両方を説明する規約文書
    '.claude/skills/app-bug-hunt/ledger/README.md',  // 過去 run の shard_id 範囲に触れる
];

/** マーカー行に最低 1 つ必要な「守りの語」。 */
const CAP_MARKER_DEFENSE_WORDS = ['denylist', 'guard', '残留', '防御', '検出'];
```

- (a) `CAP_DEFENSE_MARKER_FILES` 外のファイルにマーカーがあれば**違反**
- (b) マーカー行に `CAP_MARKER_DEFENSE_WORDS` が 1 つも無ければ**違反**
- (Round 2 の `CAP_MARKER_DENY_CONTEXT` は Tier A に吸収されたため**廃止**)

> 完全な自然言語判定はしない (Codex も不要と明言)。
> 「マーカーを置ける面」と「割り当て値は免除しない」の 2 点だけ閉じれば本規模には十分。
>
> **偽陽性の確認**: 実装後、施策 3 適用済みのツリーに対して本テストを流し 0 件であることを確認する。
> 万一無関係な文脈にヒットした場合は、パターンを緩めるのでもマーカーを貼るのでもなく、
> **対象ファイル側の表現を書き換える** (数字ではなく理由を書く方針。§設計の中心原則)。

#### テストケース

```php
// --- 構造 (スクリプト本体。散文 scan とは別レーン) ---
test('cap の正本が scripts/bug-hunt-shard.sh の BUGHUNT_SHARD_CAP=4 ただ 1 つであること', ...);
test('BUGHUNT_SHARD_CAP が env 上書き可能な形 (${...:-N}) で書かれていないこと', ...);
test('SHARD_RE / SHARD_DB_RE / manifest key regex が cap から導出され、数字の写経が無いこと', ...);
test('BUGHUNT_DB_PREFIX が SHARD_DB_RE 埋め込み前に形式検証されていること', ...);
test('valid_parallel_n の受理集合が {2..cap の偶数} であること', ...);
test('stories_for_shard の固定マップが受理される全 N × shard 1..N を過不足なく持ち、cap 超過 case が無いこと', ...);

// --- 散文 (割り当てを説明する文書) ---
test('割り当て散文に cap 超過 literal が残っていないこと', ...)
    ->with(CAP_ALLOCATION_DOCS の各パス);

// --- 守り (意図的に cap より広い面。値を直接固定する。Codex R2 Warning 反映) ---
test('defense surface の除外集合が空でなく、各 entry がパス実在 + 理由文字列を持つこと', ...);
test('DEV_DB_DENYLIST が cap を超える bug_hunt_5..bug_hunt_8 を保持していること', ...);
test('DetectsBughuntDatabase の regex が cap を超える _[1-8] を保持していること', ...);
test('run-browser-test.sh の pre-flight guard が cap を超える 8018 まで見ていること', ...);

// --- 負のコントロール (正の検出) ---
test('負のコントロール: Tier B literal (bug_hunt_5 / :8018 / 2/4/6) を検出すること', ...);
test('負のコントロール: Tier A 割り当て値 (--parallel=8 / parallel は 2/4/6/8 / N=8 / cap=8 / shard 1..8) を検出すること', ...);
test('負のコントロール: Tier A はマーカー付きでも違反になること (parallel=8 は guard 用。cap-defense-ok)', ...);
test('負のコントロール: allowlist 外ファイルの cap-defense-ok を違反として検出すること', ...);
test('負のコントロール: 守りの語を含まない cap-defense-ok を違反として検出すること', ...);
test('負のコントロール: 6-* / 8-* case を戻した script fixture を構造検査が検出すること', ...);

// --- 負のコントロール (偽陽性を出さないこと) ---
test('偽陽性防止: S7 / AG-048b / 2025 年 / parallel=4 の結果を S7 で検証する が違反にならないこと', ...);
test('偽陽性防止: :8011..8018 の "1..8" が Tier A に当たらないこと (Tier B では当たる)', ...);
test('偽陽性防止: cap-defense-ok 付きの正当な守り説明行 (残留ポート :8018 までは guard が検出する) が通ること', ...);
test('偽陽性防止: cap を上げた場合に検出が追従すること (cap=6 なら bug_hunt_5 も parallel=6 も違反でない)', ...);
```

> **既存テストとの役割分担** (テスト docblock に明記すること):
> `TestDatabaseEnvTest` は `DEV_DB_DENYLIST` の**全体一致**を固定する。本テストが固定するのは
> 「その denylist が **cap より広い**という意図」だけである。重複ではなく、
> 「cap を下げたときに一緒に縮められる」改変を止めるための別軸の検査。

#### PHPStan 適合チェック

- [x] 全関数に戻り値型を明示 (`int` / `list<string>` / `array<int, list<int>>`)
- [x] `file_get_contents()` の `string|false` を `expect(...)->toBeString()` + `/** @var string */` で narrowing
      (既存 `BughuntOrchestratorGateInvariantTest` / `BughuntEnvExampleContractTest` と同じ作法)
- [x] `preg_match` の返り値 `int|false` を `=== 1` で比較
- [x] 配列返却は純関数の違反リストのみ (DTO 不要。既存 Architecture テストの慣行に一致)

### テスト計画 (施策 5)

- 新規: `tests/Architecture/BughuntShardCapInvariantTest.php` — 上記 21 ケース
  (構造 6 / 散文 1 (データセット) / 守り 4 / 負のコントロール 6 / 偽陽性防止 4)
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
| V2 | `composer test` | 全 green。新規 `BughuntShardCapInvariantTest` が 21 ケース pass。既存 bughunt 系 (`BughuntEnvExampleContractTest` / `BughuntOrchestratorGateInvariantTest` / `TestDatabaseEnvTest`) も pass |
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
| C5 | (Codex R1 指摘の `BUGHUNT_DB_PREFIX` escape 問題) | **今回スコープに取り込み済み** (施策 1-a2)。後続に残さない |
| C3 | Browser lane pre-flight guard の範囲を cap 連動にする | §設計の中心原則により**やらない**方が正しい。将来やるなら「cap+余裕」の根拠を先に決める必要がある |
| C4 | c2c への `status_reported` 追記 | 実装 (push 済み commit) 後の別手順。`refs` は `aicue@<commit>` 形式が必須 |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存ファイルへの局所変更 (定数 + 散文) が主で、新規追加は Architecture テスト 1 本のみ。他機能のファイルに触らない |
| 競合リスク | 低。`AGENTS.md` / `scripts/README.md` は他タスクも触りうるが、変更行が bug-hunt §に限定されるためコンフリクトは軽微 |

## Codex レビューの最終状態 (透明性のため明記)

| フェーズ | ラウンド | 結果 |
|---|---|---|
| 概念設計 | Round 1 | **APPROVED** (Critical 0 / Warning 3 → 全件反映) |
| 詳細設計 | Round 1 | CHANGES_REQUESTED (Critical 2 / Warning 4 → 全件反映) |
| 詳細設計 | Round 2 | CHANGES_REQUESTED (Critical 0 / Warning 4 → 全件反映) |
| 詳細設計 | Round 3 | CHANGES_REQUESTED (Critical 0 / Warning 2 → 全件反映)。**ここで打ち切り** |

- **Critical は残っていない** (Round 2 以降 0 件)。Round 3 の Warning 2 件も本書に反映済み。
- ただしタスク規定の**最大 3 ラウンド**に達したため、**Round 3 の反映結果を Codex に再確認させていない**。
  未確認なのは以下の 2 点で、いずれも新規 Architecture テストの内部ロジック:
  1. Tier A / Tier B の 2 層分離と `bughuntCapAllocationValues()` の抽出構文
  2. `cap-defense-ok` マーカーが Tier A を免除しない規則
- **実装時の Codex impl-review でこの 2 点を明示的に確認対象に含めること**
  (実装後は fixture で実挙動を検証できるため、設計レビューより確度が高い)。
- レビュー生ログ: `detailed-review-round-{1,2,3}.md` / プロンプトと対応マトリクスは `codex-history/`。

---

## 実装順序 (fail-first)

1. 施策 5 のテストを先に追加 → `composer test` で**赤**を確認 (散文が cap=8 のまま)
2. 施策 2 (self-test) を先に書き換え → `self-test` で**赤**を確認
3. 施策 1 (スクリプト本体) を適用 → V1 が green
4. 施策 3・4 (散文) を適用 → V2 が green
5. V3〜V7 を通す


---

## 検証結果 (worktree 内で実行)

- `scripts/bug-hunt-shard.sh self-test`: **self-test: all passed** (exit 0)
- `composer test`: 3156 tests, **3154 passed / 0 failed** / 2 skipped, 12282 assertions
  (うち新規 BughuntShardCapInvariantTest は 30 ケース pass = 宣言 21 本 + データセット展開)
- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: green
- `pnpm test`: 124 files / **1213 passed**
- `python3 -m unittest discover -s ledger`: 70 tests OK / `-s coverage`: 62 tests OK (skipped=1)
- `scripts/bug-hunt-inventory-check.sh`: exit 0 (drift なし)

---

## 実装差分 (git diff HEAD)

```diff
diff --git a/.claude/skills/app-bug-hunt/SKILL.md b/.claude/skills/app-bug-hunt/SKILL.md
index 11c4817..4b8b989 100644
--- a/.claude/skills/app-bug-hunt/SKILL.md
+++ b/.claude/skills/app-bug-hunt/SKILL.md
@@ -1,6 +1,6 @@
 ---
 name: app-bug-hunt
-description: このアプリの LLM 探索的バグハント。専用 bughunt 環境 (直列 :8010 / 並列 shard :8011..8018) に対し隔離ブラウザ (Bash 駆動の @playwright/cli) でユーザーストーリーを実走し、UX破綻・詰み・認可漏れ (IDOR) を発見してレポートする (修正はしない)。テンプレート同梱のオプトイン基盤 (未使用時は完全 no-op)。
+description: このアプリの LLM 探索的バグハント。専用 bughunt 環境 (直列 :8010 / 並列 shard :8011..8014) に対し隔離ブラウザ (Bash 駆動の @playwright/cli) でユーザーストーリーを実走し、UX破綻・詰み・認可漏れ (IDOR) を発見してレポートする (修正はしない)。テンプレート同梱のオプトイン基盤 (未使用時は完全 no-op)。
 user-invocable: true
 argument-hint: "省略時は --all --coverage --parallel --deviate --real-llm 相当 (既定=全ストーリー並列+コードカバレッジ+逸脱+実LLM接続)。絞るなら [S1..S7 ...] [--no-deviate] [--keep-db] [--fake-llm] 例: /app-bug-hunt, /app-bug-hunt S3"
 ---
@@ -38,7 +38,7 @@ ## 引数
 | --all | No | 全ストーリーを実行 (S7 は S3 の状態を前提にするため S3 の後)。既定に含まれる |
 | --coverage | No | serve を pcov 付き php で起動しコード到達カバレッジ (C3) を収集する。既定に含まれる。pcov 未導入環境では middleware が no-op で安全に続行 |
 | --no-coverage | No | カバレッジ計装を省く (既定の --coverage を打ち消す) |
-| --parallel[=N] | No | 並列シャード実行 (N=2/4/6/8、cap=8、既定 4)。既定に含まれる。親はインベントリ確認 → `provision-all` → `bughunt-shard` subagent を Workflow で N 体 fan-out → `verify-run` → 統合レポート |
+| --parallel[=N] | No | 並列シャード実行 (N=2/4、cap=4、既定 4)。既定に含まれる。親はインベントリ確認 → `provision-all` → `bughunt-shard` subagent を Workflow で N 体 fan-out → `verify-run` → 統合レポート |
 | --deviate | No | 各ストーリー末尾の「逸脱アイデア」も実行する。既定に含まれる |
 | --no-deviate | No | 逸脱探索を省く |
 | --real-llm | No | LLM を実 Anthropic API に接続して走行する (既定)。親リポジトリ `.env` の `ANTHROPIC_API_KEY` が必須で、未設定なら provision が fail-fast する。生成内容・所要時間は run ごとに非決定的 |
@@ -95,7 +95,7 @@ ### 手順 (親 = このセッション。worktree 内から実行)
 1. **インベントリ鮮度確認** (Phase 1 と同一) を親で 1 回。子は Phase 1 をスキップ。
 2. `BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh provision-all --parallel={N} [--coverage] --hold-lock` を
    **run_in_background で常駐**させる (lock を fan-out 全期間保持 = 2 run 並走防止)。STDOUT の
-   `run-id={ts}` を控える。shard 1..N の DB (`bug_hunt_{i}`) / serve (:8011..8018) / wrapper を用意。
+   `run-id={ts}` を控える。shard 1..N の DB (`bug_hunt_{i}`) / serve (:8011..8014) / wrapper を用意。
    > **`BUGHUNT_ORCHESTRATOR=1` は必須** (B-HARNESS-01): provision / provision-all / teardown は
    > このトークンが無いと拒否される (default-deny)。**親だけが export** し、fan-out する
    > `bughunt-shard` subagent には**渡さない** (worker の自走復旧による共有 worktree 破壊を機械的に防ぐ)。
@@ -123,7 +123,7 @@ ### 手順 (親 = このセッション。worktree 内から実行)
    詳細スキーマは `ledger/README.md`。
 
 ストーリー割り当ては固定マップ (`scripts/bug-hunt-shard.sh` の `stories_for_shard`。S3→S7 の状態依存を shard-1 に
-閉じ込める。cap=8、`--parallel` は 2/4/6/8)。N=8 は S1/S4 の独立 2nd pass で埋め、統合レポートが route×症状で dedupe する。
+閉じ込める。cap=4、`--parallel` は 2/4)。統合レポートが route×症状で dedupe する。
 
 ### 隔離と権限
 
diff --git a/.claude/skills/app-bug-hunt/coverage/merge_pcov.py b/.claude/skills/app-bug-hunt/coverage/merge_pcov.py
index 3d6907b..58e5571 100644
--- a/.claude/skills/app-bug-hunt/coverage/merge_pcov.py
+++ b/.claude/skills/app-bug-hunt/coverage/merge_pcov.py
@@ -8,8 +8,8 @@ C3 middleware (BughuntCoverageMiddleware) が per-request で書き出す JSONL
 
 HONEST 注記: 本環境は pcov 未導入のため実 coverage は取得できない。
 本スクリプトは pcov 非依存の純ロジック (入力は C3 出力形の JSON) であり、
-テストは fixture の shard を union して検証する。app の shard は 0-8
-(直列 shard-0 :8010 / 並列 shard-1..8 :8011..8018)。
+テストは fixture の shard を union して検証する。app の shard は 0-4
+(直列 shard-0 :8010 / 並列 shard-1..4 :8011..8014)。
 
 依存は標準ライブラリのみ (json, argparse, glob, sys, pathlib, dataclasses)。
 
@@ -220,7 +220,7 @@ def _run_id_matches(path: str, run_id: str) -> bool:
 
 
 def merge_shards(paths: list[str], *, run_id: str = "", only: str | None = None) -> MergeResult:
-    """複数 shard を union merge。covered = ∪, all = ∪ (shard 0-8 union)。
+    """複数 shard を union merge。covered = ∪, all = ∪ (shard 0-4 union)。
 
     only 指定時は file が only prefix で始まるものだけ残す (既定 app/ 限定運用)。
     """
diff --git a/.claude/skills/app-bug-hunt/ledger/README.md b/.claude/skills/app-bug-hunt/ledger/README.md
index f6f8a8c..1440145 100644
--- a/.claude/skills/app-bug-hunt/ledger/README.md
+++ b/.claude/skills/app-bug-hunt/ledger/README.md
@@ -21,9 +21,10 @@ ## 実行環境（app bug-hunt）
 LLM 探索的バグハントは dev `app` から物理隔離された専用 bughunt 環境で走る:
 
 - **直列**: shard 0 / `:8010`
-- **並列**: shard 1..8 / `:8011..8018`（各 shard 独立 DB `bug_hunt_1..8`）
+- **並列**: shard 1..4 / `:8011..8014`（各 shard 独立 DB `bug_hunt_1..4`）
 - 外部依存は `TESTING_BROWSER_FAKES`（`config('testing.browser_fakes')`）で宣言的に fake 化。
-- `shard_id` フィールドに 0-8 を記録する（直列=0、並列=1..8）。
+- `shard_id` フィールドに 0-4 を記録する（直列=0、並列=1..4）。
+- 過去 run の findings には 0-4 の範囲外の `shard_id` が入りうる（履歴は書き換えない）。
 
 ## ユーザーストーリー（story_id）
 `story_id` は **enum 化しない自由文字列**（逸脱ストーリーを S3-dev 等で表現できるように）。app の標準ストーリー:
diff --git a/.claude/skills/app-bug-hunt/ledger/findings.schema.json b/.claude/skills/app-bug-hunt/ledger/findings.schema.json
index a29accc..336468a 100644
--- a/.claude/skills/app-bug-hunt/ledger/findings.schema.json
+++ b/.claude/skills/app-bug-hunt/ledger/findings.schema.json
@@ -24,7 +24,7 @@
     "finding_id": { "type": "string", "description": "report.md の F-xx と対応", "pattern": "^F-" },
     "run_id": { "type": "string", "minLength": 1 },
     "story_id": { "type": "string", "description": "app ストーリー S1..S8 等。逸脱は S3-dev 等。enum 化しない自由文字列 (fix-gate #4)", "minLength": 1 },
-    "shard_id": { "type": ["string", "integer", "null"], "description": "並列時のシャード番号 (0-8。直列は :8010 で shard 0、並列は :8011..8018 で shard 1..8)" },
+    "shard_id": { "type": ["string", "integer", "null"], "description": "並列時のシャード番号 (0-4。直列は :8010 で shard 0、並列は :8011..8014 で shard 1..4)。過去 run には範囲外の値が入りうるため型・値制約は課さない" },
     "capability_tag": { "type": "string", "description": "capability-catalog の id。未割当は 'unmapped'、tag 不能は 'unknown'", "minLength": 1 },
     "principal": { "type": "string", "description": "操作主体 (例 org_b_admin / guest / member_enforced_org)", "minLength": 1 },
     "tenant_relation": {
diff --git a/.claude/skills/app-bug-hunt/ledger/validate_findings.py b/.claude/skills/app-bug-hunt/ledger/validate_findings.py
index 296efef..db96405 100644
--- a/.claude/skills/app-bug-hunt/ledger/validate_findings.py
+++ b/.claude/skills/app-bug-hunt/ledger/validate_findings.py
@@ -11,7 +11,7 @@ findings.jsonl を検証し、success/kill 判定に使う KPI を出力する
 
 設計根拠: .claude/skills/app-bug-hunt/SKILL.md / coverage-audit.md
   (最小スキーマ / success-kill 基準)。app bug-hunt は直列 :8010 (shard 0) /
-  並列 :8011..8018 (shard 1..8) の専用 bughunt 環境で走る。
+  並列 :8011..8014 (shard 1..4) の専用 bughunt 環境で走る。
 """
 from __future__ import annotations
 
diff --git a/.claude/skills/app-bug-hunt/stories/README.md b/.claude/skills/app-bug-hunt/stories/README.md
index 3b7dffb..730d570 100644
--- a/.claude/skills/app-bug-hunt/stories/README.md
+++ b/.claude/skills/app-bug-hunt/stories/README.md
@@ -27,7 +27,7 @@ ## 逸脱アイデア (--deviate 時)
 
 ## 並列 fan-out マップ (scripts/bug-hunt-shard.sh の stories_for_shard)
 
-固定マップは S3↔S7 の状態依存を shard-1 に閉じ込める。cap=8、`--parallel` は 2/4/6/8。
+固定マップは S3↔S7 の状態依存を shard-1 に閉じ込める。cap=4、`--parallel` は 2/4。
 S1..S7 は browser story。CLI/REST 面・管理画面など特殊 guard を要する面は subagent fan-out に含めず親が直列追走する
 (アプリが追加する場合は S8 以降として本 README とカードに記述する)。
 
diff --git a/.env.bughunt.local.example b/.env.bughunt.local.example
index 0f2be50..8811ed2 100644
--- a/.env.bughunt.local.example
+++ b/.env.bughunt.local.example
@@ -3,7 +3,7 @@
 #
 # 資源の対応:
 #   直列走行: APP_ENV=bughunt.local / DB=bug_hunt / :8010
-#   並列 shard: DB=bug_hunt_{1..8} / :8011..8018 (scripts/bug-hunt-shard.sh が注入)
+#   並列 shard: DB=bug_hunt_{1..4} / :8011..8014 (scripts/bug-hunt-shard.sh が注入)
 #
 # 使い方:
 #   1. cp .env.bughunt.local.example .env.bughunt.local
@@ -16,7 +16,7 @@
 #   6. DB 作成・migrate・serve 起動は scripts/bug-hunt-shard.sh provision が行うため手動不要
 #
 # 隔離方針 (dev DB を wipe しないための 3 軸):
-#   (a) DB 名 `^bug_hunt(_[1-8])?$` のみ許可  (b) 専用 role `bughunt` (CREATEDB なし)
+#   (a) DB 名 `^bug_hunt(_[1-4])?$` のみ許可  (b) 専用 role `bughunt` (CREATEDB なし)
 #   (c) PostgreSQL 権限 (dev DB への CONNECT/CREATE/DROP 不可)
 # host は dev クラスタと同一を許容する (隔離は上記 3 軸で担保。host 値は guard の判定軸に含めない)。
 # ==========================================
@@ -38,7 +38,7 @@ CIPHERSWEET_KEY=
 DB_CONNECTION=pgsql
 DB_HOST=db                          # dev クラスタと同一 host を許容 (隔離は DB名+専用role+権限)
 DB_PORT=5432
-DB_DATABASE=bug_hunt                # provision が作成。^bug_hunt(_[1-8])?$ のみ許可
+DB_DATABASE=bug_hunt                # provision が作成。^bug_hunt(_[1-4])?$ のみ許可
 DB_USERNAME=bughunt                 # 上書き必須。dev DB へ CONNECT/CREATE/DROP 権限を持たない専用 role
 DB_PASSWORD=                        # 上書き必須 (bughunt role の password)
 
diff --git a/AGENTS.md b/AGENTS.md
index 98ad268..e59dd66 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -208,12 +208,13 @@ ## bug-hunt (LLM 探索的バグハント、オプトイン)
 
 `.claude/skills/app-bug-hunt/` は自由探索型の UX バグハント基盤。回帰テストでは見つからない
 説明なしリダイレクト・操作詰み・IDOR・UX 破綻を、隔離 bughunt 環境 (直列 `:8010` / 並列 shard
-`:8011..8018`、DB `bug_hunt(_N)`) で実ブラウザ走行して発見する (修正はしない)。起動は `/app-bug-hunt`。
+`:8011..8014` (cap=4)、DB `bug_hunt(_N)`) で実ブラウザ走行して発見する (修正はしない)。起動は `/app-bug-hunt`。
 
 - **オプトイン・完全 no-op**: 未使用時はアプリ実行に一切影響しない。`config/bughunt.php` と
   `BughuntCoverageMiddleware` は `env(BUGHUNT_PCOV)` + `function_exists('\pcov\start')` の二重 guard で
   pcov 未導入の本番/CI/dev では常に no-op。`BughuntOAuthSeeder` は fake_externals + bughunt.local +
-  `^bug_hunt(_[1-8])?$` の三重 fail-secure ガードで、条件不成立なら no-op (dev DB に認証状態をばら撒かない)。
+  `DetectsBughuntDatabase` の DB 名判定を含む三重 fail-secure ガードで、条件不成立なら no-op
+  (dev DB に認証状態をばら撒かない)。判定側の regex は残留 DB も検出するため cap より広い。
 - **dev DB 防御 (非交渉)**: 全 DB 操作は `scripts/bug-hunt-shard.sh` の用途別 wrapper (`env -i` で
   shell の `DB_*`/`PG*` を遮断 + DB名 regex + role guard) 経由のみ。生 artisan/psql/tinker/createdb/dropdb 禁止。
   `provision`/`teardown` は `BUGHUNT_ORCHESTRATOR=1` を持つ親のみ (worker は default-deny)。
diff --git a/database/seeders/Concerns/DetectsBughuntDatabase.php b/database/seeders/Concerns/DetectsBughuntDatabase.php
index 36f4f6e..d237d51 100644
--- a/database/seeders/Concerns/DetectsBughuntDatabase.php
+++ b/database/seeders/Concerns/DetectsBughuntDatabase.php
@@ -7,12 +7,17 @@
 use Illuminate\Support\Facades\DB;
 
 /**
- * bug-hunt DB 名判定の SSOT (bug-hunt 隔離規約 ^bug_hunt(_[1-8])?$ と一致)。
+ * bug-hunt DB 名判定の SSOT (fail-secure な**検出**側)。
  * bughunt 系 seeder の fail-secure guard から参照する。
+ *
+ * ★ 並列 cap は 4 (`scripts/bug-hunt-shard.sh` の `BUGHUNT_SHARD_CAP`) だが、本 regex は
+ *   **cap と同期させない**。狭めると残留 `bug_hunt_5` を bughunt DB と認識できず
+ *   「dev DB 扱い」になってしまう (= 検出漏れ)。同スクリプトの `SHARD_DB_RE` は
+ *   「触れてよい DB の allowlist」で方向が逆である点に注意。
  */
 trait DetectsBughuntDatabase
 {
-    /** bug-hunt DB 名の許容 regex (scripts/bug-hunt-shard.sh の guard と一致させる)。 */
+    /** bug-hunt DB 名の許容 regex (残留も検出するため cap より広い。上記 docblock 参照)。 */
     private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';
 
     private function isBughuntDatabase(): bool
diff --git a/docs/testing-browser.md b/docs/testing-browser.md
index 587e8f1..9f1f9ad 100644
--- a/docs/testing-browser.md
+++ b/docs/testing-browser.md
@@ -37,7 +37,8 @@ ## 実行
 
 Browser lane は起動時に bug-hunt 環境のポート (`127.0.0.1:8010..8018`) を
 best-effort で覗き、listen していれば **ロックを取る前に** fail-fast する。
-bug-hunt はロック規約に参加しない (意図的に隔離された 8 並列基盤) ため、
+bug-hunt はロック規約に参加しない (意図的に隔離された **4 並列**基盤。guard のポート範囲は
+残留 serve 検出のため cap と同期させず :8018 まで広く取る) ため、
 非干渉は保証しない — TOCTOU のある guard であり、失敗モードが偽赤に留まる範囲で受容している。
 
 ### ブラウザレーン (Chromium + WebKit)
diff --git a/docs/worktree-isolation-strategy.md b/docs/worktree-isolation-strategy.md
index 0008f91..3df021e 100644
--- a/docs/worktree-isolation-strategy.md
+++ b/docs/worktree-isolation-strategy.md
@@ -202,7 +202,7 @@ ## bug-hunt との関係
 
 `.claude/skills/app-bug-hunt/` は **worktree から走ることを既定**とし、
 `scripts/bughunt-worktree-hook.sh` の PreToolUse ガードが main 直叩きを早期に止める。
-bughunt 環境の DB (`bug_hunt(_1..8)`) は本書のテスト DB 分離とは**別系統の隔離**で、
+bughunt 環境の DB (`bug_hunt(_1..4)`) は本書のテスト DB 分離とは**別系統の隔離**で、
 `scripts/bug-hunt-shard.sh` の用途別 wrapper (`env -i` + DB 名 regex + role guard) が担う
 (AGENTS.md §bug-hunt)。
 
diff --git a/scripts/README.md b/scripts/README.md
index c269e9b..4563d39 100644
--- a/scripts/README.md
+++ b/scripts/README.md
@@ -28,7 +28,7 @@ ## スクリプト一覧
 | `test-inventory-config.ts` | vitest の include (root / packages/cli の 2 project) の単一 SoT。`vitest.config.ts` と `packages/cli/vitest.config.ts` が本ファイルから include を引く | 両 vitest config から import (直接実行しない) |
 | `vitest-inventory-gate.test.ts` | FS 走査と `vitest list` の突合による inventory gate。どの project にも入らない `*.test.ts` (= 書いたのに走っていないテスト) と、列挙 0 件の空振りを検出 | `pnpm test` |
 | `run-browser-test.contract.test.ts` | `run-browser-test.sh` の契約テスト (2 レーン実行 / 失敗レーンがあっても全レーン実行して overall 非ゼロ / 既定直列 / orphan playwright 掃除 / bug-hunt 除外) | `pnpm test` |
-| `run-browser-test.sh` | Browser テスト (pest-plugin-browser) を**グローバルテストロック配下**で並列上限付きで実行。**Chromium / WebKit の 2 レーンが契約** (bfcache 復元シナリオは WebKit レーンが正本)。残留 playwright run-server を前後で掃除する (`@playwright/` = bug-hunt 側は除外)。起動時に bughunt ポート `:8010..8018` の best-effort pre-flight guard を掛ける | `composer test:browser` 等から呼び出し。レーン限定は `BROWSER_TEST_LANES` / 並列度は `BROWSER_TEST_PROCESSES` |
+| `run-browser-test.sh` | Browser テスト (pest-plugin-browser) を**グローバルテストロック配下**で並列上限付きで実行。**Chromium / WebKit の 2 レーンが契約** (bfcache 復元シナリオは WebKit レーンが正本)。残留 playwright run-server を前後で掃除する (`@playwright/` = bug-hunt 側は除外)。起動時に bughunt ポート `:8010..8018` の best-effort pre-flight guard を掛ける (cap=4 より広く取るのは残留検出のため) | `composer test:browser` 等から呼び出し。レーン限定は `BROWSER_TEST_LANES` / 並列度は `BROWSER_TEST_PROCESSES` |
 | `bug-hunt-shard.sh` | bug-hunt シャードオーケストレータ。隔離環境 (DB `bug_hunt(_N)` / `:8010+N`) の provision / serve / teardown と、**dev DB を wipe しないための用途別 DB wrapper + 3-way hard-deny guard** を提供する (AGENTS.md §bug-hunt) | `/app-bug-hunt` から。`self-test` は実資源に触れず guard を検証 |
 | `bug-hunt-inventory-check.sh` | bug-hunt インベントリのドリフト検知。`route:list` と `.claude/skills/app-bug-hunt/{screens,operations}.md` の差分 (新ルート未追記 / 消失) を出す (exit 3 = 差分あり) | route 追加・削除時 / bug-hunt 実行前 |
 | `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する | `.claude/settings.json` の hook として配線 (`.claude/settings.bughunt-hook.example.json` をマージ) |
diff --git a/scripts/bug-hunt-shard.sh b/scripts/bug-hunt-shard.sh
index 6958902..f080c42 100755
--- a/scripts/bug-hunt-shard.sh
+++ b/scripts/bug-hunt-shard.sh
@@ -15,7 +15,8 @@
 #     createdb は DB 名 regex 検証後に OWNER bughunt で実行。
 #
 # シャード i = (DB ${BUGHUNT_DB_PREFIX}[_{i}], serve :8010+i, APP_URL, レポート dir)。
-# shard 0 = 直列走行用 (DB ${BUGHUNT_DB_PREFIX} / :8010)。並列 = shard 1..8 (cap=8、--parallel は 2/4/6/8)。
+# shard 0 = 直列走行用 (DB ${BUGHUNT_DB_PREFIX} / :8010)。並列 = shard 1..4 (cap は BUGHUNT_SHARD_CAP。
+# --parallel は 2 以上 cap 以下の偶数)。
 #
 # 本スクリプトは機械的制御 (lock / provision / serve / 欠落検知 / teardown / DB guard) に専念する。
 # ブラウザ探索は claude -p でも MCP サーバでもなく、外側ハーネスが .claude/agents/bughunt-shard.md を
@@ -59,12 +60,18 @@ WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
 cd "${WORKSPACE}"
 
 BASE_PORT=8010
+# 並列 shard の上限 (家系共通の標準形。c2c オーナー裁定 AG-048b で 4 に統一)。
+# ★ env で上書きしない (ハードコード)。SHARD_DB_RE は「触れてよい DB の allowlist」であり、
+#   外から広げられる余地を作ることはガードの緩和にあたる。
+# ★ 1 桁前提 (2..9)。ポート採番が BASE_PORT + N である以上 cap <= 9 は構造的制約。
+#   下の文字クラス導出 ([0-${CAP}]) もこの前提に依存する。self-test [a] が 1 桁性を assert する。
+BUGHUNT_SHARD_CAP=4
 # bug-hunt 専用 DB 接頭辞。dev DB (テンプレート slug の DB) とは別名にして隔離する。
 # この接頭辞と数値 suffix のみが SHARD_DB_RE に一致し、それ以外の DB 名は全 abort される。
 BUGHUNT_DB_PREFIX="${BUGHUNT_DB_PREFIX:-bug_hunt}"
 RUN_ID_RE='^[0-9]{8}-[0-9]{6}(-[0-9]+)?$'
-SHARD_RE='^[0-8]$'                 # 0 = 直列走行 (serial)、1..8 = 並列 shard (cap=8)
-SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-8])?$"  # ★ dev DB 防御の核。これ以外の DB 名は全 abort
+SHARD_RE="^[0-${BUGHUNT_SHARD_CAP}]$"   # 0 = 直列走行 (serial)、1..CAP = 並列 shard
+# ★ SHARD_DB_RE の代入はここではなく die() 定義直後 (BUGHUNT_DB_PREFIX の形式検証の後) に置く。
 
 # self-test 専用 sandbox (実資源に触れないための paths 差し替え)。
 if [[ -n "${BUGHUNT_SANDBOX:-}" ]]; then
@@ -84,6 +91,19 @@ fi
 is_dryrun() { [[ -n "${BUGHUNT_SELFTEST_DRYRUN:-}" ]]; }
 die() { local code=$1; shift; echo "error: $*" >&2; exit "${code}"; }
 
+# ★ prefix は SHARD_DB_RE にそのまま埋め込まれる。regex メタ文字が入ると allowlist が壊れるため
+#   (例: 'b.g_hunt' は 'bXg_hunt' にも一致してしまう)、埋め込む前に形を固定する
+#   (「別名の bug-hunt DB 群を選ぶ」既存の自由度は保つ)。
+[[ "${BUGHUNT_DB_PREFIX}" =~ ^[a-z][a-z0-9_]*$ ]] \
+    || die 1 "BUGHUNT_DB_PREFIX が不正: '${BUGHUNT_DB_PREFIX}' (^[a-z][a-z0-9_]*\$ のみ。regex メタ文字は allowlist を壊す)"
+
+# ★ 本スクリプトが createdb/dropdb/migrate してよい shard DB の **allowlist** (dev DB 防御の核)。
+#   cap と同期する。「残留も含めて bug-hunt DB を守る / 検出する」側 —
+#   tests/Support/Ci/TestDatabaseEnv::DEV_DB_DENYLIST と
+#   database/seeders/Concerns/DetectsBughuntDatabase::BUGHUNT_DB_REGEX — は **cap と同期させない**
+#   (狭めると過去 cap=8 期の残留 DB を守れなくなる)。方向が逆であることに注意。
+SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"
+
 # ファイルサイズ (bytes)。GNU stat (-c) と BSD stat (-f) の双方に対応し、無ければ wc -c に fallback。
 file_size() {
     local f=$1
@@ -118,15 +138,19 @@ worker_logfile() { echo "${TMP_BASE}/worker-$1-$2.log"; }
 # --- 入力検証 -----------------------------------------------------------------
 
 validate_shard() {
-    [[ "${1:-}" =~ ${SHARD_RE} ]] || die 2 "invalid --shard: '${1:-}' (0..8 のみ、0=直列)"
+    [[ "${1:-}" =~ ${SHARD_RE} ]] || die 2 "invalid --shard: '${1:-}' (0..${BUGHUNT_SHARD_CAP} のみ、0=直列)"
 }
 
-# --parallel の受理値 (固定ストーリーマップを持つ N のみ)。cap=8。
+# --parallel の受理値: 2 以上 cap 以下の偶数 (固定ストーリーマップを持つ N のみ)。
+# 偶数制限は stories_for_shard の固定マップが偶数 N でしか定義されていないことに由来する。
+# ★ cap を上げる場合は stories_for_shard へのマップ追加が同一変更で必須。
+#   受理集合とマップ定義のずれは self-test [r] と BughuntShardCapInvariantTest が機械検出する。
+# ★ (( ... )) は結果 0 を非ゼロ終了として扱うため、必ず `|| return 1` を付け最後に明示 return 0 する。
 valid_parallel_n() {
-    case "${1:-}" in
-        2|4|6|8) return 0 ;;
-        *) return 1 ;;
-    esac
+    local n=${1:-}
+    [[ "${n}" =~ ^[0-9]+$ ]] || return 1
+    (( n >= 2 && n <= BUGHUNT_SHARD_CAP && n % 2 == 0 )) || return 1
+    return 0
 }
 
 validate_run_id() {
@@ -383,14 +407,18 @@ PY
 }
 
 manifest_valid_shards() {
-    # 不正 key (空白入り / パストラバーサル) を除外し有効 shard key (0..8) のみ出力。
+    # 不正 key (空白入り / パストラバーサル) を除外し有効 shard key (0..cap) のみ出力。
+    # 旧 run (cap=8 期) の manifest を読むと shard key cap+1.. は warning + skip になる。
     local mf; mf="$(manifest_path "$1")"
-    MF="${mf}" python3 - <<'PY'
+    MF="${mf}" CAP="${BUGHUNT_SHARD_CAP}" python3 - <<'PY'
 import json, os, re, sys
 with open(os.environ["MF"]) as f:
     data = json.load(f)
+cap = os.environ["CAP"]
+if not re.fullmatch(r"[2-9]", cap):
+    raise SystemExit(f"invalid BUGHUNT_SHARD_CAP for manifest parser: {cap!r}")
 for key in data.get("shards", {}):
-    if re.fullmatch(r"[0-8]", key):
+    if re.fullmatch(rf"[0-{cap}]", key):
         print(key)
     else:
         print(f"warning: manifest に不正な shard key {key!r} — skip", file=sys.stderr)
@@ -442,7 +470,7 @@ cmd_verify_run() {
     local run_id=$1 n
     require_manifest "${run_id}"
     n="$(manifest_get "${run_id}" - parallel)"
-    valid_parallel_n "${n}" || die 2 "verify-run: manifest の parallel が 2/4/6/8 でない (run-id 不整合): '${n}'"
+    valid_parallel_n "${n}" || die 2 "verify-run: manifest の parallel が 2..${BUGHUNT_SHARD_CAP} の偶数でない (run-id 不整合): '${n}'"
     local rc=0
     verify_reports "${run_id}" "${n}" || rc=$?
     echo "verify-run: run-id=${run_id} parallel=${n} exit=${rc} (manifest: $(manifest_path "${run_id}"))"
@@ -1009,7 +1037,7 @@ cmd_provision_all() {
     local n=$1 hold=${2:-}
     require_orchestrator "provision-all"
     assert_worktree_context
-    valid_parallel_n "${n}" || die 2 "--parallel は 2/4/6/8 のみ (固定ストーリーマップのため。cap=8)"
+    valid_parallel_n "${n}" || die 2 "--parallel は 2..${BUGHUNT_SHARD_CAP} の偶数のみ (固定ストーリーマップのため)"
 
     if [[ -z "${BUGHUNT_SANDBOX:-}" ]]; then
         mkdir -p "${WORKSPACE}/.claude"
@@ -1157,7 +1185,7 @@ reap_orphan_browser() {
 
 # --- ストーリー割り当て (固定マップ) -------------------------------------------
 # stories/ 配下の S1..S7 はテンプレートではスケルトン。アプリが route:list から生成する。
-# S3↔S7 の状態依存を shard-1 に閉じ込める既定マップ。cap=8 (N=8 は S1/S4 の独立 2nd pass)。
+# S3↔S7 の状態依存を shard-1 に閉じ込める既定マップ。N は 2 と cap(=4) のみ。
 stories_for_shard() {
     local shard=$1 n=$2
     case "${n}-${shard}" in
@@ -1167,20 +1195,6 @@ stories_for_shard() {
         4-4) echo "S6" ;;
         2-1) echo "S3 S7 S6" ;;
         2-2) echo "S1 S2 S4 S5" ;;
-        6-1) echo "S3 S7" ;;
-        6-2) echo "S1" ;;
-        6-3) echo "S2" ;;
-        6-4) echo "S4" ;;
-        6-5) echo "S5" ;;
-        6-6) echo "S6" ;;
-        8-1) echo "S3 S7" ;;
-        8-2) echo "S1" ;;
-        8-3) echo "S2" ;;
-        8-4) echo "S4" ;;
-        8-5) echo "S5" ;;
-        8-6) echo "S6" ;;
-        8-7) echo "S1" ;;
-        8-8) echo "S4" ;;
         *) die 1 "stories_for_shard: 未定義の組み合わせ N=${n} shard=${shard}" ;;
     esac
 }
@@ -1196,6 +1210,9 @@ cmd_self_test() {
     TMP_BASE="${sandbox}/tmp/bug-hunt"
     LOCK_FILE="${sandbox}/bug-hunt.lock"
     ENV_FILE="${sandbox}/.env.bughunt.local"
+    # self-test は環境非依存であるべき (外部 env の BUGHUNT_DB_PREFIX に影響されない)。
+    BUGHUNT_DB_PREFIX=bug_hunt
+    SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"
 
     cat > "${ENV_FILE}" <<'ENVEOF'
 APP_ENV=bughunt.local
@@ -1213,36 +1230,42 @@ ENVEOF
     expect_ok() { local fn=$1; shift; local rc=0; ( "${fn}" "$@" ) >/dev/null 2>&1 || rc=$?; [[ "${rc}" == 0 ]]; }
 
     echo "[a] 資源導出"
+    local rc
+    # cap は 1 桁 (2..9) であることが文字クラス導出 ([0-${CAP}]) の前提。
+    [[ "${BUGHUNT_SHARD_CAP}" =~ ^[2-9]$ ]] || t_fail "BUGHUNT_SHARD_CAP は 2..9 の 1 桁である必要がある (文字クラス導出の前提)"
+    # 不正な BUGHUNT_DB_PREFIX では引数解析より前に die 1 する (SHARD_DB_RE への escape なし埋め込み対策)。
+    rc=0; (BUGHUNT_DB_PREFIX='b.g_hunt' "${SCRIPT_PATH}" self-test) >/dev/null 2>&1 || rc=$?
+    [[ "${rc}" == 1 ]] || t_fail "不正な BUGHUNT_DB_PREFIX でスクリプトが起動してしまう (exit ${rc})"
     [[ "$(shard_db 0)" == "bug_hunt" ]] || t_fail "shard_db serial"
     [[ "$(shard_db 1)" == "bug_hunt_1" ]] || t_fail "shard_db"
-    [[ "$(shard_db 8)" == "bug_hunt_8" ]] || t_fail "shard_db cap=8"
+    [[ "$(shard_db 4)" == "bug_hunt_4" ]] || t_fail "shard_db cap"
     [[ "$(shard_port 0)" == "8010" ]] || t_fail "shard_port serial"
-    [[ "$(shard_port 4)" == "8014" ]] || t_fail "shard_port"
-    [[ "$(shard_port 8)" == "8018" ]] || t_fail "shard_port cap=8"
+    [[ "$(shard_port 4)" == "8014" ]] || t_fail "shard_port cap"
     [[ "$(shard_url 2)" == "http://127.0.0.1:8012" ]] || t_fail "shard_url"
     [[ "$(shard_profile_dir 1)" == "${TMP_BASE}/profile-1" ]] || t_fail "shard_profile_dir"
     [[ "$(shard_download_dir 1)" == "${TMP_BASE}/downloads-1" ]] || t_fail "shard_download_dir"
     [[ "$(shard_trace_dir 1)" == "${TMP_BASE}/trace-1" ]] || t_fail "shard_trace_dir"
     t_ok "derivations + per-shard resource uniqueness"
 
-    echo "[b] 範囲外 shard の拒否 (exit 2、cap=8)"
-    local bad good rc fp_before
-    for bad in 9 -1 x ""; do
+    echo "[b] 範囲外 shard の拒否 (exit 2、cap=${BUGHUNT_SHARD_CAP})"
+    local bad good fp_before
+    # ★ 5/8 = 旧 cap (=8) の残骸が通らないことを正のアサーションにする。
+    for bad in 5 8 9 -1 x ""; do
         rc=0; (validate_shard "${bad}") 2>/dev/null || rc=$?
         [[ "${rc}" == 2 ]] || t_fail "shard '${bad}' が exit ${rc} (expected 2)"
     done
-    for good in 0 4 8; do
+    for good in 0 1 4; do
         rc=0; (validate_shard "${good}") 2>/dev/null || rc=$?
         [[ "${rc}" == 0 ]] || t_fail "shard ${good} が拒否された"
     done
     t_ok "shard validation"
 
-    echo "[c] guard_shard_db_name: dev DB / 別名バリアントは全 abort、bug_hunt 系は通過 (cap=8)"
+    echo "[c] guard_shard_db_name: dev DB / 別名バリアント / cap 超過は全 abort、bug_hunt_1..4 は通過"
     local v
-    for v in app App ' app ' 'app ' bug_huntx bug_hunt2 bug_hunt_9 'bug_hunt;rm' myapp_dev ''; do
+    for v in app App ' app ' 'app ' bug_huntx bug_hunt2 bug_hunt_5 bug_hunt_8 bug_hunt_9 'bug_hunt;rm' myapp_dev ''; do
         expect_die guard_shard_db_name "${v}" || t_fail "guard_shard_db_name が '${v}' を abort しない"
     done
-    for v in bug_hunt bug_hunt_1 bug_hunt_4 bug_hunt_8; do
+    for v in bug_hunt bug_hunt_1 bug_hunt_4; do
         expect_ok guard_shard_db_name "${v}" || t_fail "guard_shard_db_name が '${v}' を拒否"
     done
     t_ok "shard db name deny"
@@ -1343,21 +1366,22 @@ ENVEOF
     [[ -z "$(manifest_get 20260615-150000 1 nope)" ]] || t_fail "manifest_get 欠損キーが空でない"
     t_ok "run-id allocation + manifest schema"
 
-    echo "[m] stories_for_shard 固定マップ (N=4/6/8: S1..S7 を網羅 / 未定義は abort)"
+    echo "[m] stories_for_shard 固定マップ (受理される全 N で S1..S7 を網羅 / 未定義は abort、cap=${BUGHUNT_SHARD_CAP})"
     [[ "$(stories_for_shard 1 4)" == "S3 S7" ]] || t_fail "4-1 map"
     [[ "$(stories_for_shard 4 4)" == "S6" ]] || t_fail "4-4 map"
     [[ "$(stories_for_shard 1 2)" == "S3 S7 S6" ]] || t_fail "2-1 map"
-    [[ "$(stories_for_shard 6 6)" == "S6" ]] || t_fail "6-6 map"
-    [[ "$(stories_for_shard 7 8)" == "S1" ]] || t_fail "8-7 map (2nd pass)"
-    [[ "$(stories_for_shard 8 8)" == "S4" ]] || t_fail "8-8 map (2nd pass)"
     local s mapped n2
-    for n2 in 4 6 8; do
+    for n2 in $(seq 2 "${BUGHUNT_SHARD_CAP}"); do
+        valid_parallel_n "${n2}" || continue
         mapped="$(for s in $(seq 1 "${n2}"); do stories_for_shard "${s}" "${n2}"; done | tr ' ' '\n' | sort -u | tr '\n' ' ')"
         [[ "${mapped}" == "S1 S2 S3 S4 S5 S6 S7 " ]] || t_fail "N=${n2} の story union が S1..S7 でない: '${mapped}'"
     done
-    rc=0; (stories_for_shard 1 3) 2>/dev/null || rc=$?
-    [[ "${rc}" == 1 ]] || t_fail "未定義 N=3 が abort しない (exit ${rc})"
-    t_ok "stories map (N=4/6/8)"
+    # ★ 旧 cap (=8) の残骸と奇数 N がマップから消えていること (die 1) を正のアサーションにする。
+    for n2 in 3 6 8; do
+        rc=0; (stories_for_shard 1 "${n2}") 2>/dev/null || rc=$?
+        [[ "${rc}" == 1 ]] || t_fail "未定義 N=${n2} が abort しない (exit ${rc})"
+    done
+    t_ok "stories map (受理される全 N = 2..${BUGHUNT_SHARD_CAP} の偶数)"
 
     echo "[n] manifest_valid_shards: 改ざん key (空白/トラバーサル) を除外"
     local tamper_mf; tamper_mf="$(manifest_path 20260615-160000)"
@@ -1389,16 +1413,35 @@ MFEOF
     [[ "$(manifest_get "${pa_run_id}" 1 stories)" == "S3 S7 S6" ]] || t_fail "provision-all: shard-1 stories 未記録"
     [[ "$(manifest_get "${pa_run_id}" 2 stories)" == "S1 S2 S4 S5" ]] || t_fail "provision-all: shard-2 stories 未記録"
     [[ ! -f "$(run_dir "${pa_run_id}")/child-pids" ]] || t_fail "provision-all が子を起動している (child-pids 検出)"
-    local pa8_log="${sandbox}/provision-all-8.log"
-    rc=0; ("${SCRIPT_PATH}" provision-all --parallel=8) > "${pa8_log}" 2>&1 || rc=$?
-    [[ "${rc}" == 0 ]] || t_fail "provision-all --parallel=8 (dryrun) が exit ${rc} (expected 0、cap=8)"
-    local pa8_run_id; pa8_run_id="$(sed -n 's/^run-id=//p' "${pa8_log}" | head -1)"
-    [[ "$(manifest_get "${pa8_run_id}" - parallel)" == "8" ]] || t_fail "provision-all --parallel=8: manifest parallel≠8"
-    [[ "$(manifest_get "${pa8_run_id}" 8 stories)" == "S4" ]] || t_fail "provision-all --parallel=8: shard-8 stories 未記録 (2nd pass S4)"
-    rc=0; ("${SCRIPT_PATH}" provision-all --parallel=3) >/dev/null 2>&1 || rc=$?
-    [[ "${rc}" == 2 ]] || t_fail "provision-all --parallel=3 が exit ${rc} (expected 2、未定義 N)"
+    local pa4_log="${sandbox}/provision-all-4.log"
+    rc=0; ("${SCRIPT_PATH}" provision-all --parallel=4) > "${pa4_log}" 2>&1 || rc=$?
+    [[ "${rc}" == 0 ]] || t_fail "provision-all --parallel=4 (dryrun) が exit ${rc} (expected 0、cap=${BUGHUNT_SHARD_CAP})"
+    local pa4_run_id; pa4_run_id="$(sed -n 's/^run-id=//p' "${pa4_log}" | head -1)"
+    [[ "$(manifest_get "${pa4_run_id}" - parallel)" == "4" ]] || t_fail "provision-all --parallel=4: manifest parallel≠4"
+    [[ "$(manifest_get "${pa4_run_id}" 4 stories)" == "S6" ]] || t_fail "provision-all --parallel=4: shard-4 stories 未記録"
+
+    # ★ 旧 cap の 6/8 が拒否されることが本施策の核。奇数・範囲外も併せて固定する。
+    local bad_n
+    for bad_n in 3 5 6 8 0 1; do
+        rc=0; ("${SCRIPT_PATH}" provision-all "--parallel=${bad_n}") >/dev/null 2>&1 || rc=$?
+        [[ "${rc}" == 2 ]] || t_fail "provision-all --parallel=${bad_n} が exit ${rc} (expected 2)"
+    done
+
+    # 受理される N すべてに完全なストーリーマップがあること (受理集合とマップのずれ検出)。
+    # ★ stories_for_shard は未定義時 die 1 する。command substitution の失敗が set -e で
+    #   self-test 全体を殺さないよう、rc を分離して受ける。
+    local n i stories srv_rc
+    for n in $(seq 2 "${BUGHUNT_SHARD_CAP}"); do
+        valid_parallel_n "${n}" || continue
+        for i in $(seq 1 "${n}"); do
+            srv_rc=0
+            stories="$(stories_for_shard "${i}" "${n}")" || srv_rc=$?
+            [[ "${srv_rc}" == 0 && -n "${stories}" ]] \
+                || t_fail "stories_for_shard 未定義: N=${n} shard=${i} (rc=${srv_rc})"
+        done
+    done
     unset BUGHUNT_SELFTEST_DRYRUN
-    t_ok "provision-all dryrun (cap=8 受理 / N=3 拒否)"
+    t_ok "provision-all dryrun (cap=${BUGHUNT_SHARD_CAP} 受理 / 旧 cap の 6・8 と奇数 N を拒否 / story map 完全)"
 
     echo "[s] provision-all は lock 排他 (保持中の lock 下では exit 1)"
     (
@@ -1944,7 +1987,7 @@ main() {
             validate_shard "${shard}"; validate_run_id "${run_id}"
             cmd_provision "${shard}" "${run_id}" ;;
         provision-all)
-            valid_parallel_n "${parallel}" || die 2 "--parallel は 2/4/6/8 のみ (固定ストーリーマップのため。cap=8)"
+            valid_parallel_n "${parallel}" || die 2 "--parallel は 2..${BUGHUNT_SHARD_CAP} の偶数のみ (固定ストーリーマップのため)"
             cmd_provision_all "${parallel}" "${hold_lock}" ;;
         reseed)
             validate_shard "${shard}"; validate_run_id "${run_id}"
diff --git a/scripts/run-browser-test.contract.test.ts b/scripts/run-browser-test.contract.test.ts
index 6033531..5505265 100644
--- a/scripts/run-browser-test.contract.test.ts
+++ b/scripts/run-browser-test.contract.test.ts
@@ -137,6 +137,8 @@ function writeExecutable(path: string, content: string): void {
 
 /**
  * bug-hunt 併走の pre-flight guard (127.0.0.1:8010..8018) と同じ検査をテスト側でも行う。
+ * ★ bug-hunt の並列 cap は 4 だが、guard 側と同じく残留 serve 検出のためポート範囲は
+ *   cap と同期させず :8018 まで広く取る (広い方が偽赤に倒れて安全)。
  * listen していたら **明示メッセージで fail** させる (silent skip にしない =
  * 「担保されていない」を隠さない)。docs/testing-browser.md が併走を既に非推奨としている。
  */
diff --git a/scripts/run-browser-test.sh b/scripts/run-browser-test.sh
index 2355e9a..907195a 100755
--- a/scripts/run-browser-test.sh
+++ b/scripts/run-browser-test.sh
@@ -43,8 +43,8 @@ LANES="${BROWSER_TEST_LANES:-chromium webkit}"
 # **ロック取得より前**に実行する。取得後に落とすと、先行レーンの終了を数分待ってから
 # 「bug-hunt が走っているので実行できません」と言うことになり、待ち時間が無駄になる。
 #
-# bug-hunt は本ロック規約に参加しない (意図的に隔離された並列実行基盤で、
-# global lock を被せると 8 並列が 1 直列に潰れる)。そのため bug-hunt の
+# bug-hunt は本ロック規約に参加しない (意図的に隔離された 4 並列基盤で、
+# global lock を被せると並列が 1 直列に潰れる)。そのため bug-hunt の
 # `playwright-cli kill-all` (@playwright/cli) が Browser lane の run-server を
 # 巻き込む可能性を **こちらからは証明できない**。
 #
@@ -55,6 +55,8 @@ LANES="${BROWSER_TEST_LANES:-chromium webkit}"
 #
 # 検知は bash の /dev/tcp のみを使う (ss/lsof/netstat の可用性と出力形式に依存しない)。
 # bug-hunt は 127.0.0.1:801N に明示 bind するので IPv4 loopback だけ見れば足りる。
+# ★ bug-hunt の並列 cap は 4 だが、本 guard は残留 serve の取りこぼしを避けるため :8018 まで
+#   広く見る (cap と同期させない。広い方が偽赤に倒れて安全)。
 # /dev/tcp が使えないシェルでは検査を skip して続行する (guard であって保証ではない)。
 bughunt_port_in_use() {
     local port
diff --git a/scripts/verify-global-test-lock.sh b/scripts/verify-global-test-lock.sh
index 9098d58..dbe6aa5 100755
--- a/scripts/verify-global-test-lock.sh
+++ b/scripts/verify-global-test-lock.sh
@@ -1093,6 +1093,8 @@ sys.stdout.flush()
 time.sleep(120)
 PY
 
+    # 候補ポート列挙は「bind できるポートを 1 つ探す」ための fixture であり、bug-hunt の
+    # 並列 cap (=4) とは無関係。cap と同期させない (狭めると bind 候補が減るだけで、意味が無い)。
     for port in 8010 8011 8012 8013 8014 8015 8016 8017 8018; do
         python3 "${listener}" "${port}" >"${WORK}/c19.listen" 2>/dev/null &
         lpid=$!
diff --git a/tests/Architecture/BughuntEnvExampleContractTest.php b/tests/Architecture/BughuntEnvExampleContractTest.php
index 3ca5035..9529c30 100644
--- a/tests/Architecture/BughuntEnvExampleContractTest.php
+++ b/tests/Architecture/BughuntEnvExampleContractTest.php
@@ -15,7 +15,7 @@
  *                                  `${APP_NAME}` の自己参照はリテラル露出事故になる (実際に発生した)
  *   - APP_LOCALE=ja              : bug-hunt はユーザー向け文言 (日本語) の検証環境。en のままだと
  *                                  production と異なる文言を検証してしまう
- *   - DB_DATABASE=bug_hunt       : dev DB 隔離の核 (^bug_hunt(_[1-8])?$ のみ許可)
+ *   - DB_DATABASE=bug_hunt       : dev DB 隔離の核 (^bug_hunt(_[1-4])?$ のみ許可)
  *   - TESTING_FAKE_EXTERNALS=true: 決済等の外部を fake に落とす (実課金を踏まない)
  *   - ADMIN_MFA_REQUIRED=false   : true だと admin ログイン後 TOTP 強制で探索が詰む
  *
@@ -119,7 +119,7 @@ function bughuntEnvExampleViolations(string $content): array
     expect(preg_match('/^BUGHUNT_DB_PREFIX="\$\{BUGHUNT_DB_PREFIX:-([a-z_]+)\}"/m', $script, $prefix))->toBe(1);
     /** @var array{0: string, 1: string} $prefix */
 
-    // 乖離すると直列走行 (shard 0) の DB 名が guard regex (^bug_hunt(_[1-8])?$) を外れて abort する。
+    // 乖離すると直列走行 (shard 0) の DB 名が guard regex (^bug_hunt(_[1-4])?$) を外れて abort する。
     expect(trim($env[1], "\"'"))->toBe($prefix[1]);
 });
 
diff --git a/tests/Architecture/BughuntShardCapInvariantTest.php b/tests/Architecture/BughuntShardCapInvariantTest.php
new file mode 100644
index 0000000..478b06a
--- /dev/null
+++ b/tests/Architecture/BughuntShardCapInvariantTest.php
@@ -0,0 +1,660 @@
+<?php
+
+declare(strict_types=1);
+use Tests\Support\Ci\TestDatabaseEnv;
+
+/*
+ * bug-hunt 並列枠数 cap の単一ソース化ゲート (c2c: bug-hunt-exec-infra / オーナー裁定 AG-048b)。
+ *
+ * 固定する不変条件:
+ *   1. cap の正本は scripts/bug-hunt-shard.sh の BUGHUNT_SHARD_CAP ただ 1 つ (env 上書き不可)
+ *   2. SHARD_RE / SHARD_DB_RE / manifest key regex は cap から導出され、数字を写経していない
+ *   3. valid_parallel_n の受理集合と stories_for_shard の定義が cap と整合している
+ *   4. 「割り当て」を説明する散文 (CAP_ALLOCATION_DOCS) に cap 超過が残っていない
+ *      - Tier A (割り当て値): 行から構文で抽出した値が cap 超過 → **マーカーで免除できない**
+ *      - Tier B (literal): ポート / DB 名 / 範囲表記 → `cap-defense-ok` マーカーで免除できる
+ *   5. 「守り」の面 (CAP_DEFENSE_SURFACES) は **意図的に cap より広い**。値を直接固定する
+ *
+ * ★ 4 と 5 は逆向きの検査である。5 の面を 4 に含めてはならない
+ *   (含めると防御を狭める方向へ改変が誘導される)。
+ * ★ scripts/bug-hunt-shard.sh は 4 の対象に含めない。自身のコメントが 5 の説明を持つため
+ *   偽陽性になる。スクリプトは 1〜3 の構造検査で固定する。
+ * ★ `cap-defense-ok` は「守りが cap より広い理由」を書く行にのみ使う。
+ *   Tier A (割り当て値) は**マーカーがあっても違反**なので、bypass にはならない。
+ *
+ * 既存テストとの役割分担: tests/Unit/Ci/TestDatabaseEnvTest.php は DEV_DB_DENYLIST の
+ * **全体一致**を固定する。本テストが固定するのは「その denylist が **cap より広い**」という
+ * 意図だけである。重複ではなく、「cap を下げたときに一緒に denylist も縮める」改変を止める別軸の検査。
+ *
+ * 実行時の挙動 (受理 / 拒否の exit code) は `scripts/bug-hunt-shard.sh self-test` が担う
+ * 二段防御: Architecture = 静的構造、self-test = 実行配線。DB 不使用の静的検査。
+ */
+
+/** 守りの面を説明する行に付ける明示 opt-out マーカー (c2c 台帳の ref-ok と同じ発想)。 */
+const CAP_DEFENSE_MARKER = 'cap-defense-ok';
+
+/**
+ * 割り当て (触れる対象) を説明する散文。cap 超過を deny-by-default で走査する。
+ * ★ scripts/bug-hunt-shard.sh は**含めない** (自身のコメントが守りの説明を含むため。
+ *   構造は bughuntCapScriptViolations() が別途固定する)。
+ */
+const CAP_ALLOCATION_DOCS = [
+    'AGENTS.md',
+    '.claude/skills/app-bug-hunt/SKILL.md',
+    '.claude/skills/app-bug-hunt/stories/README.md',
+    '.claude/skills/app-bug-hunt/ledger/README.md',
+    '.claude/skills/app-bug-hunt/ledger/findings.schema.json',
+    '.claude/skills/app-bug-hunt/ledger/validate_findings.py',
+    '.claude/skills/app-bug-hunt/coverage/merge_pcov.py',
+    '.env.bughunt.local.example',
+    'docs/worktree-isolation-strategy.md',
+    'tests/Architecture/BughuntEnvExampleContractTest.php',
+];
+
+/**
+ * 守り (残留も含めて守る / 検出する) の面。**意図的に cap より広い**ので走査対象外。
+ * key = パス / value = なぜ cap と同期させないか。
+ */
+const CAP_DEFENSE_SURFACES = [
+    'tests/Support/Ci/TestDatabaseEnv.php' => '残留 bug_hunt_5..8 を保護し続ける dev DB denylist',
+    'database/seeders/Concerns/DetectsBughuntDatabase.php' => '残留も bughunt DB と検出する fail-secure 判定',
+    'scripts/run-browser-test.sh' => '残留 serve 検出の pre-flight guard (広い方が偽赤に倒れて安全)',
+    'scripts/run-browser-test.contract.test.ts' => '同 guard のテスト側ミラー',
+    'scripts/verify-global-test-lock.sh' => 'bind 可能ポートを探す fixture (cap と無関係)',
+    'docs/testing-browser.md' => '上記 guard の説明',
+    'scripts/README.md' => '上記 guard の説明',
+];
+
+/** `cap-defense-ok` を書いてよいファイル。ここ以外のマーカーは違反。 */
+const CAP_DEFENSE_MARKER_FILES = [
+    'AGENTS.md',                                     // 割り当てと守りの両方を説明する規約文書
+    '.claude/skills/app-bug-hunt/ledger/README.md',  // 過去 run の shard_id 範囲に触れる
+];
+
+/** マーカー行に最低 1 つ必要な「守りの語」。 */
+const CAP_MARKER_DEFENSE_WORDS = ['denylist', 'guard', '残留', '防御', '検出'];
+
+function bughuntCapReadSource(string $relativePath): string
+{
+    $contents = file_get_contents(base_path($relativePath));
+    expect($contents)->toBeString("{$relativePath} が読めない");
+    /** @var string $contents */
+    expect($contents)->not->toBe('', "{$relativePath} が空");
+
+    return $contents;
+}
+
+/**
+ * `^name()` 行から行頭 `}` までの関数窓を切り出す。
+ * 対象 3 関数 (manifest_valid_shards / valid_parallel_n / stories_for_shard) はいずれも
+ * 行頭 `}` を本体内に持たない (heredoc の python も字下げされている)。
+ */
+function bughuntCapFunctionWindow(string $source, string $name): string
+{
+    $m = [];
+    $matched = preg_match('/^'.preg_quote($name, '/').'\(\)\s*\{[\s\S]*?^\}/m', $source, $m);
+    expect($matched)->toBe(1, "関数窓が見つからない: {$name}");
+
+    /** @var array{0: string} $m */
+    return $m[0];
+}
+
+/** cap の正本を scripts/bug-hunt-shard.sh から抽出する。 */
+function bughuntCapFromScript(string $script): int
+{
+    $m = [];
+    $matched = preg_match('/^BUGHUNT_SHARD_CAP=([0-9]+)$/m', $script, $m);
+    expect($matched)->toBe(1, 'BUGHUNT_SHARD_CAP=<数値> の代入が見つからない');
+
+    /** @var array{0: string, 1: string} $m */
+    return (int) $m[1];
+}
+
+/**
+ * cap 代入そのものの違反 (SSOT 性 / 1 桁性 / env 上書き不可)。
+ *
+ * @return list<string>
+ */
+function bughuntCapSsotViolations(string $script): array
+{
+    $violations = [];
+
+    $count = (int) preg_match_all('/^BUGHUNT_SHARD_CAP=/m', $script);
+    if ($count !== 1) {
+        $violations[] = "BUGHUNT_SHARD_CAP の代入が {$count} 箇所 (正本は 1 箇所であること)";
+    }
+    if (preg_match('/^BUGHUNT_SHARD_CAP=[2-9]$/m', $script) !== 1) {
+        $violations[] = 'BUGHUNT_SHARD_CAP が 2..9 の 1 桁即値で書かれていない (文字クラス導出の前提)';
+    }
+    if (preg_match('/^BUGHUNT_SHARD_CAP=[^\n]*\$\{/m', $script) === 1) {
+        $violations[] = 'BUGHUNT_SHARD_CAP が env 上書き可能な形 (${...:-N}) で書かれている (allowlist を外から広げられる)';
+    }
+
+    return $violations;
+}
+
+/**
+ * SHARD_RE / SHARD_DB_RE / manifest key regex が cap から導出されているか。
+ *
+ * @return list<string>
+ */
+function bughuntCapDerivationViolations(string $script): array
+{
+    $violations = [];
+
+    foreach (['SHARD_RE', 'SHARD_DB_RE'] as $name) {
+        $m = [];
+        if (preg_match('/^'.$name.'=(.*)$/m', $script, $m) !== 1) {
+            $violations[] = "{$name} の代入が見つからない";
+
+            continue;
+        }
+        /** @var array{0: string, 1: string} $m */
+        $line = $m[1];
+        if (! str_contains($line, '${BUGHUNT_SHARD_CAP}')) {
+            $violations[] = "{$name} が cap から導出されていない: {$line}";
+        }
+        if (preg_match('/\[[0-9]-[0-9]\]/', $line) === 1) {
+            $violations[] = "{$name} に数字の写経が残っている: {$line}";
+        }
+    }
+
+    $window = bughuntCapFunctionWindow($script, 'manifest_valid_shards');
+    if (! str_contains($window, 'CAP="${BUGHUNT_SHARD_CAP}"')) {
+        $violations[] = 'manifest_valid_shards が cap を parser へ渡していない (CAP="${BUGHUNT_SHARD_CAP}")';
+    }
+    if (! str_contains($window, '[0-{cap}]')) {
+        $violations[] = 'manifest key regex が cap から導出されていない ([0-{cap}])';
+    }
+    if (preg_match('/\[0-[0-9]\]/', $window) === 1) {
+        $violations[] = 'manifest key regex に数字の写経が残っている';
+    }
+
+    return $violations;
+}
+
+/**
+ * BUGHUNT_DB_PREFIX が SHARD_DB_RE へ埋め込まれる**前**に形式検証されているか。
+ * (prefix は escape なしで dev DB 防御の核へ埋め込まれるため、順序が保証になる)
+ *
+ * @return list<string>
+ */
+function bughuntCapPrefixValidationViolations(string $script): array
+{
+    $violations = [];
+
+    $m = [];
+    $matched = preg_match(
+        '/\[\[\s*"\$\{BUGHUNT_DB_PREFIX\}"\s*=~\s*\^\[a-z\]\[a-z0-9_\]\*\$\s*\]\]/',
+        $script,
+        $m,
+        PREG_OFFSET_CAPTURE
+    );
+    if ($matched !== 1) {
+        return ['BUGHUNT_DB_PREFIX の形式検証 (^[a-z][a-z0-9_]*$) が無い'];
+    }
+    /** @var array{0: array{0: string, 1: int}} $m */
+    $validationOffset = $m[0][1];
+
+    $assignOffset = strpos($script, 'SHARD_DB_RE="');
+    if ($assignOffset === false) {
+        return ['SHARD_DB_RE の代入が見つからない'];
+    }
+    if ($validationOffset > $assignOffset) {
+        $violations[] = 'BUGHUNT_DB_PREFIX の形式検証が SHARD_DB_RE への埋め込みより後ろにある (検証 → 代入の順であること)';
+    }
+
+    return $violations;
+}
+
+/**
+ * valid_parallel_n が「2 以上 cap 以下の偶数」の算術判定であること (数字の列挙に戻っていないこと)。
+ *
+ * @return list<string>
+ */
+function bughuntCapParallelAcceptanceViolations(string $script): array
+{
+    $violations = [];
+    $window = bughuntCapFunctionWindow($script, 'valid_parallel_n');
+
+    if (preg_match('/\bcase\b/', $window) === 1) {
+        $violations[] = 'valid_parallel_n が case 列挙のまま (cap の SSOT 化が効いていない)';
+    }
+    if (preg_match('/[0-9]\s*\|\s*[0-9]/', $window) === 1) {
+        $violations[] = 'valid_parallel_n に受理値の数字列挙が残っている';
+    }
+    foreach (['n >= 2', 'n <= BUGHUNT_SHARD_CAP', 'n % 2 == 0'] as $needle) {
+        if (! str_contains($window, $needle)) {
+            $violations[] = "valid_parallel_n に算術条件「{$needle}」が無い";
+        }
+    }
+
+    return $violations;
+}
+
+/**
+ * stories_for_shard の固定マップから {N => [shard,...]} を抽出する (純関数)。
+ *
+ * @return array<int, list<int>>
+ */
+function bughuntCapStoryMap(string $script): array
+{
+    $window = bughuntCapFunctionWindow($script, 'stories_for_shard');
+
+    $m = [];
+    preg_match_all('/^\s*([0-9]+)-([0-9]+)\)/m', $window, $m);
+    /** @var array{1: list<string>, 2: list<string>} $m */
+    $map = [];
+    foreach ($m[1] as $i => $n) {
+        $map[(int) $n][] = (int) $m[2][$i];
+    }
+    ksort($map);
+    foreach ($map as $n => $shards) {
+        sort($shards);
+        $map[$n] = array_values($shards);
+    }
+
+    return $map;
+}
+
+/**
+ * --parallel の受理値 (2 以上 cap 以下の偶数)。
+ *
+ * @return list<int>
+ */
+function bughuntCapAcceptedParallelValues(int $cap): array
+{
+    $values = [];
+    for ($n = 2; $n <= $cap; $n += 2) {
+        $values[] = $n;
+    }
+
+    return $values;
+}
+
+/**
+ * stories_for_shard のマップが「受理される全 N × shard 1..N」を過不足なく持つか。
+ *
+ * @return list<string>
+ */
+function bughuntCapStoryMapViolations(string $script, int $cap): array
+{
+    $violations = [];
+    $map = bughuntCapStoryMap($script);
+    $accepted = bughuntCapAcceptedParallelValues($cap);
+
+    foreach (array_keys($map) as $n) {
+        if (! in_array($n, $accepted, true)) {
+            $violations[] = "stories_for_shard に受理されない N={$n} の case が残っている (cap={$cap})";
+        }
+    }
+    foreach ($accepted as $n) {
+        $expected = range(1, $n);
+        $actual = $map[$n] ?? [];
+        if ($actual !== $expected) {
+            $violations[] = "stories_for_shard: N={$n} の shard 集合が 1..{$n} と一致しない ("
+                .implode(',', $actual).')';
+        }
+    }
+
+    return $violations;
+}
+
+/**
+ * scripts/bug-hunt-shard.sh の**構造**違反の一覧 (純関数)。
+ * ★ 本スクリプトは散文 scan の対象にしない (自身のコメントが守りの説明を含み偽陽性になるため)。
+ *
+ * @return list<string>
+ */
+function bughuntCapScriptViolations(string $script): array
+{
+    $cap = bughuntCapFromScript($script);
+
+    return array_merge(
+        bughuntCapSsotViolations($script),
+        bughuntCapDerivationViolations($script),
+        bughuntCapPrefixValidationViolations($script),
+        bughuntCapParallelAcceptanceViolations($script),
+        bughuntCapStoryMapViolations($script, $cap),
+    );
+}
+
+/**
+ * 1 行から「割り当て値として書かれた数値」を構文で抽出する (純関数)。★ 検出ロジックの単一ソース。
+ *
+ * 散文検査 (Tier A) とマーカー無効化判定の**両方がこの 1 本を使う**
+ * (別々の正規表現集合を持つと再び差分が生じるため)。
+ * 数字の近接では判定しない: `S7` / `AG-048b` / `2025 年` はどの構文にも当たらない。
+ *
+ * @return list<int> 抽出できた割り当て値 (順不同・重複可)
+ */
+function bughuntCapAllocationValues(string $line): array
+{
+    $patterns = [
+        // `--parallel=8` / `--parallel は 2/4/6/8` / `--parallel: 8`
+        '/--parallel\s*(?:=|は|:)\s*([0-9]+(?:\s*\/\s*[0-9]+)*)/u',
+        // `parallel=8` / `parallel は 8` (`--parallel` は上で拾うので lookbehind で除外)
+        '/(?<![-_a-zA-Z])parallel\s*(?:=|は|:)\s*([0-9]+(?:\s*\/\s*[0-9]+)*)/iu',
+        // `N=8` / `N=2/4/6/8` (前が英数字なら不一致。`AG-048b` の `048` には当たらない)
+        '/(?<![0-9A-Za-z])N\s*=\s*([0-9]+(?:\s*\/\s*[0-9]+)*)(?![0-9])/u',
+        // `cap=8` / `cap = 8`
+        '/cap\s*=\s*([0-9]+)(?![0-9])/iu',
+        // `shard 1..8` (lookbehind により `8011..8018` の内側には当たらない)
+        '/(?<![0-9])1\s*\.\.\s*([0-9]+)(?![0-9])/u',
+    ];
+
+    $values = [];
+    foreach ($patterns as $pattern) {
+        $m = [];
+        if ((int) preg_match_all($pattern, $line, $m) < 1) {
+            continue;
+        }
+        /** @var array{1: list<string>} $m */
+        foreach ($m[1] as $group) {
+            foreach (preg_split('/\s*\/\s*/', $group) ?: [] as $number) {
+                $values[] = (int) $number;
+            }
+        }
+    }
+
+    return $values;
+}
+
+/**
+ * Tier B (literal) の検出パターン。cap から動的に生成する。
+ *
+ * @return array<string, string> label => PCRE
+ */
+function bughuntCapExcessLiteralPatterns(int $cap): array
+{
+    $patterns = [];
+
+    if ($cap < 9) {
+        $excess = '['.($cap + 1).'-9]';
+        $patterns['cap 超過ポート'] = '/801'.$excess.'/';
+        $patterns['ポート範囲の写経'] = '/8011\s*\.\.\s*801'.$excess.'/';
+        $patterns['DB regex の写経'] = '/_\[1-'.$excess.'\]/';
+        $patterns['cap 超過 DB 名'] = '/bug_hunt_'.$excess.'/';
+    }
+    if ($cap < 8) {
+        // 「0-8」形の範囲表記。9 は正規表現の文字クラス `[0-9]` と衝突するため意図的に除外する。
+        $patterns['shard 範囲表記'] = '/(?<![0-9])0-['.($cap + 1).'-8](?![0-9])/';
+    }
+
+    // `--parallel` 受理値の写経 (cap を超える偶数を含む列挙。cap=4 なら `2/4/6`)。
+    $evens = [];
+    for ($n = 2; $n <= $cap + 2; $n += 2) {
+        $evens[] = (string) $n;
+    }
+    $patterns['受理値列挙の写経'] = '/'.implode('\s*\/\s*', $evens).'/';
+
+    return $patterns;
+}
+
+/**
+ * 割り当て散文に残った cap 超過の一覧 (純関数)。
+ *
+ * - **Tier A**: bughuntCapAllocationValues() の抽出値が cap 超過なら違反。マーカーで免除しない。
+ * - **Tier B**: ポート / DB 名 / 範囲表記の literal。有効なマーカー行は免除する。
+ *
+ * マーカーが有効になる条件は (a) CAP_DEFENSE_MARKER_FILES に含まれるファイル
+ * (b) 行に CAP_MARKER_DEFENSE_WORDS のいずれかを含む、の両方。欠ければマーカー自体が違反。
+ *
+ * @return list<string> 違反メッセージ (行番号 + tier 付き)。空なら合格
+ */
+function bughuntCapProseViolations(string $relativePath, string $content, int $cap): array
+{
+    $violations = [];
+    $tierB = bughuntCapExcessLiteralPatterns($cap);
+    $lines = preg_split('/\R/u', $content) ?: [];
+
+    foreach ($lines as $index => $line) {
+        $no = $index + 1;
+        $excerpt = trim($line);
+
+        foreach (bughuntCapAllocationValues($line) as $value) {
+            if ($value > $cap) {
+                $violations[] = "{$relativePath}:{$no} [Tier A] 割り当て値 {$value} が cap({$cap}) を超える: {$excerpt}";
+            }
+        }
+
+        $markerEffective = false;
+        if (str_contains($line, CAP_DEFENSE_MARKER)) {
+            $inAllowedFile = in_array($relativePath, CAP_DEFENSE_MARKER_FILES, true);
+            $hasDefenseWord = false;
+            foreach (CAP_MARKER_DEFENSE_WORDS as $word) {
+                if (str_contains($line, $word)) {
+                    $hasDefenseWord = true;
+                    break;
+                }
+            }
+            if (! $inAllowedFile) {
+                $violations[] = "{$relativePath}:{$no} [marker] ".CAP_DEFENSE_MARKER
+                    .' は CAP_DEFENSE_MARKER_FILES 以外のファイルでは使えない: '.$excerpt;
+            } elseif (! $hasDefenseWord) {
+                $violations[] = "{$relativePath}:{$no} [marker] ".CAP_DEFENSE_MARKER
+                    .' 行に守りの語 ('.implode('/', CAP_MARKER_DEFENSE_WORDS).') が無い: '.$excerpt;
+            } else {
+                $markerEffective = true;
+            }
+        }
+
+        if ($markerEffective) {
+            continue;
+        }
+        foreach ($tierB as $label => $pattern) {
+            if (preg_match($pattern, $line) === 1) {
+                $violations[] = "{$relativePath}:{$no} [Tier B] {$label}: {$excerpt}";
+            }
+        }
+    }
+
+    return $violations;
+}
+
+// --- 構造 (スクリプト本体。散文 scan とは別レーン) -------------------------------
+
+test('cap の正本が scripts/bug-hunt-shard.sh の BUGHUNT_SHARD_CAP=4 ただ 1 つであること', function (): void {
+    $script = bughuntCapReadSource('scripts/bug-hunt-shard.sh');
+
+    expect(bughuntCapFromScript($script))->toBe(4, 'c2c オーナー裁定 AG-048b の統一値は 4');
+    expect((int) preg_match_all('/^BUGHUNT_SHARD_CAP=/m', $script))->toBe(1);
+});
+
+test('BUGHUNT_SHARD_CAP が env 上書き可能な形 (${...:-N}) で書かれていないこと', function (): void {
+    $script = bughuntCapReadSource('scripts/bug-hunt-shard.sh');
+
+    expect(bughuntCapSsotViolations($script))->toBe([]);
+});
+
+test('SHARD_RE / SHARD_DB_RE / manifest key regex が cap から導出され、数字の写経が無いこと', function (): void {
+    expect(bughuntCapDerivationViolations(bughuntCapReadSource('scripts/bug-hunt-shard.sh')))->toBe([]);
+});
+
+test('BUGHUNT_DB_PREFIX が SHARD_DB_RE 埋め込み前に形式検証されていること', function (): void {
+    expect(bughuntCapPrefixValidationViolations(bughuntCapReadSource('scripts/bug-hunt-shard.sh')))->toBe([]);
+});
+
+test('valid_parallel_n の受理集合が {2..cap の偶数} であること', function (): void {
+    expect(bughuntCapParallelAcceptanceViolations(bughuntCapReadSource('scripts/bug-hunt-shard.sh')))->toBe([]);
+});
+
+test('stories_for_shard の固定マップが受理される全 N × shard 1..N を過不足なく持ち、cap 超過 case が無いこと', function (): void {
+    $script = bughuntCapReadSource('scripts/bug-hunt-shard.sh');
+    $cap = bughuntCapFromScript($script);
+
+    expect(bughuntCapStoryMapViolations($script, $cap))->toBe([]);
+    expect(array_keys(bughuntCapStoryMap($script)))->toBe(bughuntCapAcceptedParallelValues($cap));
+});
+
+// --- 散文 (割り当てを説明する文書) ------------------------------------------------
+
+test('割り当て散文に cap 超過 literal が残っていないこと', function (string $relativePath): void {
+    $cap = bughuntCapFromScript(bughuntCapReadSource('scripts/bug-hunt-shard.sh'));
+
+    expect(bughuntCapProseViolations($relativePath, bughuntCapReadSource($relativePath), $cap))->toBe([]);
+})->with(CAP_ALLOCATION_DOCS);
+
+// --- 守り (意図的に cap より広い面。値を直接固定する) -------------------------------
+
+test('defense surface の除外集合が空でなく、各 entry がパス実在 + 理由文字列を持つこと', function (): void {
+    expect(CAP_DEFENSE_SURFACES)->not->toBe([]);
+
+    foreach (CAP_DEFENSE_SURFACES as $path => $reason) {
+        expect(file_exists(base_path($path)))->toBeTrue("defense surface が実在しない: {$path}");
+        expect($reason)->not->toBe('', "defense surface の理由が空: {$path}");
+        expect(in_array($path, CAP_ALLOCATION_DOCS, true))
+            ->toBeFalse("守りの面を割り当て散文 scan に含めてはならない: {$path}");
+    }
+});
+
+test('DEV_DB_DENYLIST が cap を超える bug_hunt_5..bug_hunt_8 を保持していること', function (): void {
+    $cap = bughuntCapFromScript(bughuntCapReadSource('scripts/bug-hunt-shard.sh'));
+
+    // 守る側は cap と同期させない (過去 cap=8 期の残留 DB を保護し続ける)。
+    // 消えていれば残留 DB 保護の後退なので、値を直接固定する。
+    for ($i = $cap + 1; $i <= 8; $i++) {
+        expect(TestDatabaseEnv::DEV_DB_DENYLIST)->toContain("bug_hunt_{$i}");
+    }
+});
+
+test('DetectsBughuntDatabase の regex が cap を超える _[1-8] を保持していること', function (): void {
+    $source = bughuntCapReadSource('database/seeders/Concerns/DetectsBughuntDatabase.php');
+
+    expect($source)->toContain('/^bug_hunt(_[1-8])?$/');
+});
+
+test('run-browser-test.sh の pre-flight guard が cap を超える 8018 まで見ていること', function (): void {
+    $source = bughuntCapReadSource('scripts/run-browser-test.sh');
+
+    expect($source)->toContain('{8010..8018}');
+});
+
+// --- 負のコントロール (正の検出) ---------------------------------------------------
+
+test('負のコントロール: Tier B literal (bug_hunt_5 / :8018 / 2/4/6) を検出すること', function (): void {
+    $fixture = implode("\n", [
+        '並列 shard は bug_hunt_5 まで作られる',
+        'ポートは :8011..8018 を使う',
+        '受理値は 2/4/6 である',
+        'shard_id には 0-8 を記録する',
+        'DB 名は ^bug_hunt(_[1-8])?$ のみ',
+    ]);
+
+    $violations = bughuntCapProseViolations('AGENTS.md', $fixture, 4);
+    expect($violations)->not->toBe([]);
+    $joined = implode("\n", $violations);
+    foreach (['cap 超過 DB 名', 'cap 超過ポート', '受理値列挙の写経', 'shard 範囲表記', 'DB regex の写経'] as $label) {
+        expect($joined)->toContain($label);
+    }
+});
+
+test('負のコントロール: Tier A 割り当て値 (--parallel=8 / parallel は 2/4/6/8 / N=8 / cap=8 / shard 1..8) を検出すること', function (): void {
+    foreach (['--parallel=8', '--parallel は 2/4/6/8', 'N=8 で走らせる', 'cap=8 とする', 'shard 1..8 に配る'] as $line) {
+        $violations = bughuntCapProseViolations('AGENTS.md', $line, 4);
+        expect(implode("\n", $violations))->toContain('[Tier A]');
+    }
+
+    expect(bughuntCapAllocationValues('--parallel は 2/4/6/8'))->toBe([2, 4, 6, 8]);
+    expect(bughuntCapAllocationValues('cap=8'))->toBe([8]);
+});
+
+test('負のコントロール: Tier A はマーカー付きでも違反になること', function (): void {
+    $line = 'parallel=8 は残留 guard 用。'.CAP_DEFENSE_MARKER;
+
+    $violations = bughuntCapProseViolations('AGENTS.md', $line, 4);
+    expect(implode("\n", $violations))->toContain('[Tier A]');
+});
+
+test('負のコントロール: allowlist 外ファイルの cap-defense-ok を違反として検出すること', function (): void {
+    $line = '残留ポート :8018 までは guard が検出する。'.CAP_DEFENSE_MARKER;
+
+    $violations = bughuntCapProseViolations('docs/worktree-isolation-strategy.md', $line, 4);
+    $joined = implode("\n", $violations);
+    expect($joined)->toContain('[marker]');
+    // マーカーが無効なので Tier B も素通りしない。
+    expect($joined)->toContain('[Tier B]');
+});
+
+test('負のコントロール: 守りの語を含まない cap-defense-ok を違反として検出すること', function (): void {
+    $line = 'ポートは :8018 まで使う。'.CAP_DEFENSE_MARKER;
+
+    $violations = bughuntCapProseViolations('AGENTS.md', $line, 4);
+    $joined = implode("\n", $violations);
+    expect($joined)->toContain('[marker]');
+    expect($joined)->toContain('[Tier B]');
+});
+
+test('負のコントロール: 6-* / 8-* case を戻した script fixture を構造検査が検出すること', function (): void {
+    $storyFixture = <<<'SH'
+    stories_for_shard() {
+        local shard=$1 n=$2
+        case "${n}-${shard}" in
+            4-1) echo "S3 S7" ;;
+            4-2) echo "S1 S2" ;;
+            4-3) echo "S4 S5" ;;
+            4-4) echo "S6" ;;
+            2-1) echo "S3 S7 S6" ;;
+            2-2) echo "S1 S2 S4 S5" ;;
+            6-1) echo "S3 S7" ;;
+            8-1) echo "S3 S7" ;;
+            *) die 1 "undefined" ;;
+        esac
+    }
+    SH;
+    $violations = bughuntCapStoryMapViolations($storyFixture, 4);
+    expect(implode("\n", $violations))->toContain('N=6')
+        ->and(implode("\n", $violations))->toContain('N=8');
+
+    // 数字の写経に戻した導出も検出する。
+    $reFixture = "SHARD_RE='^[0-8]$'\nSHARD_DB_RE=\"^\${BUGHUNT_DB_PREFIX}(_[1-8])?\$\"\n";
+    $derivation = array_filter(
+        bughuntCapDerivationViolations($reFixture.bughuntCapReadSource('scripts/bug-hunt-shard.sh')),
+        static fn (string $v): bool => str_contains($v, '写経') || str_contains($v, '導出されていない'),
+    );
+    expect($derivation)->not->toBe([]);
+
+    // env 上書き可能な形も検出する。
+    expect(bughuntCapSsotViolations("BUGHUNT_SHARD_CAP=\${BUGHUNT_SHARD_CAP:-8}\n"))->not->toBe([]);
+});
+
+// --- 負のコントロール (偽陽性を出さないこと) ---------------------------------------
+
+test('偽陽性防止: S7 / AG-048b / 2025 年 / parallel=4 の結果を S7 で検証する が違反にならないこと', function (): void {
+    $fixture = implode("\n", [
+        'S7 のストーリーは shard-1 に閉じ込める',
+        'c2c オーナー裁定 AG-048b に従う',
+        'parallel 実行は 2025 年に導入した',
+        'parallel=4 の結果を S7 で検証する',
+        '--parallel は 2/4',
+        'shard 1..N の DB を用意する',
+    ]);
+
+    expect(bughuntCapProseViolations('AGENTS.md', $fixture, 4))->toBe([]);
+});
+
+test('偽陽性防止: :8011..8018 の "1..8" が Tier A に当たらないこと (Tier B では当たる)', function (): void {
+    expect(bughuntCapAllocationValues('serve は :8011..8018 で待ち受ける'))->toBe([]);
+
+    $violations = bughuntCapProseViolations('AGENTS.md', 'serve は :8011..8018 で待ち受ける', 4);
+    expect(implode("\n", $violations))->toContain('[Tier B]')
+        ->and(implode("\n", $violations))->not->toContain('[Tier A]');
+});
+
+test('偽陽性防止: cap-defense-ok 付きの正当な守り説明行が通ること', function (): void {
+    $line = '残留ポート :8018 までは guard が検出する ('.CAP_DEFENSE_MARKER.')';
+
+    expect(bughuntCapProseViolations('AGENTS.md', $line, 4))->toBe([]);
+});
+
+test('偽陽性防止: cap を上げた場合に検出が追従すること (cap=6 なら bug_hunt_5 も parallel=6 も違反でない)', function (): void {
+    $fixture = implode("\n", [
+        'DB は bug_hunt_5 まで作る',
+        '--parallel は 2/4/6',
+        'shard 1..6 に配る',
+    ]);
+
+    expect(bughuntCapProseViolations('AGENTS.md', $fixture, 6))->toBe([]);
+    // 同じ行が cap=4 では違反になる (追従していることの対照)。
+    expect(bughuntCapProseViolations('AGENTS.md', $fixture, 4))->not->toBe([]);
+});
diff --git a/tests/Support/Ci/TestDatabaseEnv.php b/tests/Support/Ci/TestDatabaseEnv.php
index 199d41b..72efaae 100644
--- a/tests/Support/Ci/TestDatabaseEnv.php
+++ b/tests/Support/Ci/TestDatabaseEnv.php
@@ -38,6 +38,11 @@ final class TestDatabaseEnv
      * `bug_hunt*` は allowlist regex でも構造的に除外されるが、
      * 「bug-hunt 環境の DB は絶対に触らない」(AGENTS.md §bug-hunt の dev DB 防御) という
      * 意図をコードに残す二重防御として明示列挙する。
+     *
+     * ★ bug-hunt の並列 cap は 4 (`scripts/bug-hunt-shard.sh` の `BUGHUNT_SHARD_CAP`) だが、
+     *   本 denylist は**守る側**なので cap と同期させない。過去 cap=8 期に作られ得る
+     *   残留 DB (`bug_hunt_5`..`bug_hunt_8`) を保護し続けるため、意図的に cap より広い。
+     *   縮めると防御が後退する (`BughuntShardCapInvariantTest` が値を固定している)。
      */
     public const DEV_DB_DENYLIST = [
         'app',
diff --git a/tests/Unit/Ci/TestDatabaseEnvTest.php b/tests/Unit/Ci/TestDatabaseEnvTest.php
index 17a14c3..6cd9a83 100644
--- a/tests/Unit/Ci/TestDatabaseEnvTest.php
+++ b/tests/Unit/Ci/TestDatabaseEnvTest.php
@@ -103,8 +103,10 @@
     ' bug_hunt_5 ',
 ]);
 
-it('covers every bug-hunt shard database in the denylist', function (): void {
-    // shard は :8011..:8018 = bug_hunt_1..8 (scripts/bug-hunt-shard.sh)。取りこぼしを機械検出する。
+it('keeps the bug-hunt denylist wider than the current shard cap', function (): void {
+    // denylist は cap (=4) より広い 1..8 を**意図的に**維持する (過去 cap=8 期の残留 DB 保護)。
+    // cap と同期させないことをここで固定する (縮める改変 = 防御の後退)。
+    // 「cap より広い」という意図そのものは BughuntShardCapInvariantTest が別軸で固定する。
     $expected = ['app', 'bug_hunt'];
     for ($i = 1; $i <= 8; $i++) {
         $expected[] = "bug_hunt_{$i}";

```

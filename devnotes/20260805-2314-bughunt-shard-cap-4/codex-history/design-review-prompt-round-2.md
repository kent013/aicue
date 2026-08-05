# Round 2: Round 1 指摘への対応と再レビュー依頼

Round 1 の指摘 (Critical 2 / Warning 4) をすべて捌きました。対応マトリクスと、
更新後の詳細設計書全文を添付します。

**確認してほしいこと**:
1. Critical 2 件 (self-test の `set -e` 捕捉 / Architecture gate の自己参照偽陽性) が実際に閉じているか
2. 新設した `cap-defense-ok` 行マーカー方式に穴がないか
   (この方式で「守りの説明は書ける / 割り当ての腐りは検出できる」が両立しているか)
3. スコープに取り込んだ `BUGHUNT_DB_PREFIX` の形式検証 (施策 1-a2) が、
   既存挙動を壊さず・小さく閉じているか。`die` 定義位置との順序に問題はないか
4. `valid_parallel_n` を算術判定のまま残した反論が妥当か
5. Round 1 で挙がらなかった新たな Critical/Warning があれば指摘してほしい

判定は「各施策の APPROVE / REQUEST_CHANGES」と「全体判定 APPROVED / CHANGES_REQUESTED」で。
小さいインフラ整備タスクなので、膨らませる提案は避けてください。

---

## 対応マトリクス (Round 1)

# 対応マトリクス: design-review Round 1

全体判定: **CHANGES_REQUESTED** (Critical 2 / Warning 4 / Suggestion 4)。
Critical 2 件・Warning 4 件すべてに対応した (1 件は部分対応 + 根拠付き反論)。

## [Critical] 施策 2: `stories_for_shard` 完全性チェックが `set -e` 下で self-test を即死させる
- 判断: **対応する**
- 根拠: 指摘の通り。`$(stories_for_shard ...)` は未定義時 `die 1` で非ゼロ終了するため、
  `[[ -n "$(...)" ]] || t_fail` は `t_fail` に到達せず self-test プロセスごと落ちる。
  「テスト失敗として記録する」設計になっていない。
- 対応内容: 施策 2-d を Codex 提案の `rc` 分離形に差し替えた
  (`stories="$(...)" || rc=$?` → `[[ "${rc}" == 0 && -n "${stories}" ]] || t_fail`)。

## [Critical] 施策 5: `scripts/bug-hunt-shard.sh` を散文 scan 対象に含めると自己参照で偽陽性
- 判断: **対応する**
- 根拠: 指摘の通り。施策 1 で入れるコメントに `bug_hunt_5..8` / `DEV_DB_DENYLIST` /
  `cap <= 9` / `2..9` が意図的に含まれる。これらは検出パターンに当たり、
  「正しい説明を書くと赤くなる」設計になっていた。
- 対応内容: `CAP_ALLOCATION_DOCS` から `scripts/bug-hunt-shard.sh` を**外し**、
  スクリプトは**構造テスト専用** (cap 定数 / regex 導出 / manifest parser / `6-*`・`8-*` case 不在)
  に分離した。§施策 5 の定数・テストケースを書き直した。

## [Warning] 施策 5: `AGENTS.md` を scan するとを「守りが広い理由」が書けなくなる
- 判断: **対応する (方式を変更)**
- 根拠: 妥当。AGENTS.md は割り当てと守りの両方を説明する規約文書であり、
  「残留 `bug_hunt_5..8` を守る」と正しく書いた瞬間に赤くなるのは gate 設計の欠陥。
  ただし AGENTS.md を丸ごと除外すると、今回直した `:8011..8018` が再び腐る。
- 対応内容: 検出を**行単位 + 明示マーカー除外**に変更した。行に `cap-defense-ok` を含む場合は
  その行を除外する (c2c 台帳の `ref-ok` と同じ発想。除外がレビュー時に目視できる)。
  併せて「マーカーは守りの説明にのみ使う」ことをテストの docblock と AGENTS.md 側コメントに残す。
  これで AGENTS.md をスコープ限定せず全文 scan のまま維持できる。

## [Warning] 施策 1-d: manifest parser 側で cap の 1 桁性が未検証
- 判断: **対応する**
- 根拠: 妥当。bash 側 self-test だけで守っており、parser 単体では `[0-{cap}]` が壊れうる。
- 対応内容: Codex 提案の `re.fullmatch(r"[2-9]", cap)` fail-fast を施策 1-d に追加した。

## [Warning] 施策 1-c: `valid_parallel_n` の算術化で、cap を上げた瞬間 map 未定義 N が受理され exit 2 → die 1 になる
- 判断: **一部対応 + 反論**
- 根拠: 「列挙 (`2|4`) に戻す」案は採らない。cap の SSOT 化が本タスクの主目的であり、
  受理集合を再び手書き列挙に戻すと**同じ数字がまた 2 箇所になる** (今回直している問題そのもの)。
  実行時の exit code がずれるのは「cap を上げたのに story map を足していない」という
  **リリース前に必ず赤くなる**状態でのみ起きる (Architecture テストの map 完全性検査 +
  self-test [r] の 2 重で検出する)。運用に出ない失敗モードのために SSOT を崩す取引は割に合わない。
- 対応内容: 反論を設計本文に明記した上で、Codex の受入条件
  (「cap を上げる場合は `stories_for_shard` の追加が同一変更で必須」とコメント明記 +
  Architecture テストで受理集合と map 完全性を固定) は**そのまま採用**した。

## [Warning] 施策 2: self-test が `BUGHUNT_DB_PREFIX` の既定値に依存している
- 判断: **対応する**
- 根拠: 妥当。外部環境に `BUGHUNT_DB_PREFIX` が入っていると self-test が環境依存で赤くなる。
  self-test は「実資源に触れない自己検証」なので環境非依存であるべき。
- 対応内容: `cmd_self_test` の sandbox 初期化で `BUGHUNT_DB_PREFIX=bug_hunt` を固定し
  `SHARD_DB_RE` を再導出する手順を施策 2 に追加した。

## [Warning] `BUGHUNT_DB_PREFIX` を escape せず `SHARD_DB_RE` に埋めている (既存由来)
- 判断: **対応する (最小の検証を今回スコープに入れる)**
- 根拠: 既存由来の問題だが、**今回まさにその行を編集し「外から広げられない」と書く**ため、
  放置するとコメントが実態と食い違う (Codex の指摘通り)。
  `BUGHUNT_DB_PREFIX` に regex メタ文字が入ると「dev DB 防御の核」である allowlist が壊れる
  = セキュリティ不変条件に直接効く。検証 2 行で閉じられるので、
  「コメントを弱める」よりも「実態を強める」方を選ぶ。
- 対応内容: 施策 1-a に `^[a-z][a-z0-9_]*$` の fail-fast 検証を追加し、
  self-test に「不正 prefix でスクリプトが起動しないこと」の 1 アサーションを追加した。
  併せて後続 TODO 候補から外した (今回で閉じる)。

## [Suggestion] 4 件
- 施策 3・4 への肯定 (AGENTS.md の regex 写経廃止 / `findings.schema.json` の description のみ変更 /
  denylist・`DetectsBughuntDatabase` の据え置き / browser guard の据え置き)。設計変更なし。


---

## 更新後の詳細設計書 (全文)

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

#### 1-a2. `BUGHUNT_DB_PREFIX` の形式検証 (Codex R1 Warning 反映)

`BUGHUNT_DB_PREFIX` は env 上書き可能なまま `SHARD_DB_RE` (= dev DB 防御の核) に**escape なしで
埋め込まれている**。regex メタ文字が入ると allowlist が壊れる (例: `b.g_hunt` は `bXg_hunt` に
マッチしてしまう)。今回まさにこの行に「外から広げられない」と書くため、**実態を合わせる**。

`die()` の定義 (現行 L83) の**直後**に置く (定数ブロックでは `die` が未定義のため):

```bash
# ★ prefix は SHARD_DB_RE にそのまま埋め込まれる。regex メタ文字が入ると allowlist が壊れるため、
#   埋め込む前に形を固定する (「別名の bug-hunt DB 群を選ぶ」既存の自由度は保つ)。
[[ "${BUGHUNT_DB_PREFIX}" =~ ^[a-z][a-z0-9_]*$ ]] \
    || die 1 "BUGHUNT_DB_PREFIX が不正: '${BUGHUNT_DB_PREFIX}' (^[a-z][a-z0-9_]*\$ のみ。regex メタ文字は allowlist を壊す)"
```

> 既定値 `bug_hunt` は当然通る。`SHARD_DB_RE` の代入は定数ブロック (検証より前) にあるが、
> 検証は**最初のサブコマンド実行より前**に走るため、不正 prefix ではどの DB 操作にも到達しない。
> 気になる場合は `SHARD_DB_RE` の代入をこの検証の直後へ移してもよい (どちらでも可)。

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
 *   4. 「割り当て」を説明する散文 (CAP_ALLOCATION_DOCS) に cap 超過 literal が残っていない
 *      (行に `cap-defense-ok` があればその行は除外。守りの説明を書けるようにするための明示 opt-out)
 *   5. 「守り」の面 (CAP_DEFENSE_SURFACES) は **意図的に cap より広い**。空集合化を検出する
 *
 * ★ 4 と 5 は逆向きの検査である。5 の面を 4 に含めてはならない
 *   (含めると防御を狭める方向へ改変が誘導される)。
 * ★ scripts/bug-hunt-shard.sh は 4 の対象に含めない。自身のコメントが 5 の説明を持つため
 *   偽陽性になる。スクリプトは 1〜3 の構造検査で固定する。
 * ★ `cap-defense-ok` は「守りが cap より広い理由」を書く行にのみ使う。
 *   割り当ての記述を黙らせる用途に使わないこと (レビュー時の目視対象)。
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
 * 割り当て散文に残った cap 超過 literal の一覧 (純関数)。
 *
 * ★ 行単位で走査し、`cap-defense-ok` マーカーを含む行は除外する
 *   (「守りが cap より広い理由」を正しく書いた行が赤くならないようにするための明示 opt-out。
 *   c2c 台帳の `ref-ok` と同じ発想で、除外がレビュー時に目視できる)。
 *
 * @return list<string> 違反メッセージ (行番号付き)。空なら合格
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
> **`cap-defense-ok` マーカー (Codex R1 Warning 反映)**: 走査は**行単位**で行い、
> その行に `cap-defense-ok` が含まれていれば違反判定から除外する。
> AGENTS.md のような「割り当ても守りも説明する」文書で、
> 「残留 `bug_hunt_5..8` は denylist で守り続ける」といった**正しい説明**を書いたときに
> gate が赤くならないようにするための明示 opt-out。
> マーカーは**守りの説明にのみ使う**旨をテストの docblock に明記し、
> 「マーカーで割り当ての記述を黙らせない」ことをレビュー時の目視対象とする。
>
> **偽陽性の確認**: 実装後、施策 3 適用済みのツリーに対して本テストを流し 0 件であることを確認する。
> 万一無関係な文脈 (章番号など) にヒットした場合は、パターンを緩めるのでも
> マーカーを貼るのでもなく、**対象ファイル側の表現を書き換える**
> (数字ではなく理由を書く方針。§設計の中心原則)。

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

// --- 守り (意図的に cap より広い面。検出対象外であることをテスト名に残す) ---
test('defense surface は cap より広いまま維持され、除外集合が空でないこと', ...);

// --- 負のコントロール ---
test('負のコントロール: cap 超過 literal を混入させた fixture を実際に検出すること', ...);
test('負のコントロール: cap-defense-ok マーカー付きの行は違反にならないこと', ...);
test('負のコントロール: cap を上げた場合に検出パターンが追従すること (cap=6 なら bug_hunt_5 は違反でない)', ...);
test('負のコントロール: 6-* / 8-* case を戻した script fixture を構造検査が検出すること', ...);
```

#### PHPStan 適合チェック

- [x] 全関数に戻り値型を明示 (`int` / `list<string>` / `array<int, list<int>>`)
- [x] `file_get_contents()` の `string|false` を `expect(...)->toBeString()` + `/** @var string */` で narrowing
      (既存 `BughuntOrchestratorGateInvariantTest` / `BughuntEnvExampleContractTest` と同じ作法)
- [x] `preg_match` の返り値 `int|false` を `=== 1` で比較
- [x] 配列返却は純関数の違反リストのみ (DTO 不要。既存 Architecture テストの慣行に一致)

### テスト計画 (施策 5)

- 新規: `tests/Architecture/BughuntShardCapInvariantTest.php` — 上記 12 ケース
  (構造 6 / 散文 1 (データセット) / 守り 1 / 負のコントロール 4)
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
| V2 | `composer test` | 全 green。新規 `BughuntShardCapInvariantTest` が 12 ケース pass。既存 bughunt 系 (`BughuntEnvExampleContractTest` / `BughuntOrchestratorGateInvariantTest` / `TestDatabaseEnvTest`) も pass |
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

## 実装順序 (fail-first)

1. 施策 5 のテストを先に追加 → `composer test` で**赤**を確認 (散文が cap=8 のまま)
2. 施策 2 (self-test) を先に書き換え → `self-test` で**赤**を確認
3. 施策 1 (スクリプト本体) を適用 → V1 が green
4. 施策 3・4 (散文) を適用 → V2 が green
5. V3〜V7 を通す


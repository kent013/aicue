# アプリの使命 (North Star)

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

# AGENTS.md の bug-hunt 節

`.claude/skills/app-bug-hunt/` は自由探索型の UX バグハント基盤。回帰テストでは見つからない
説明なしリダイレクト・操作詰み・IDOR・UX 破綻を、隔離 bughunt 環境 (直列 `:8010` / 並列 shard
`:8011..8014` (cap=4)、DB `bug_hunt(_N)`) で実ブラウザ走行して発見する (修正はしない)。起動は `/app-bug-hunt`。

- **オプトイン・完全 no-op**: 未使用時はアプリ実行に一切影響しない。`config/bughunt.php` と
  `BughuntCoverageMiddleware` は `env(BUGHUNT_PCOV)` + `function_exists('\pcov\start')` の二重 guard で
  pcov 未導入の本番/CI/dev では常に no-op。`BughuntOAuthSeeder` は fake_externals + bughunt.local +
  `DetectsBughuntDatabase` の DB 名判定を含む三重 fail-secure ガードで、条件不成立なら no-op
  (dev DB に認証状態をばら撒かない)。判定側の regex は残留 DB も検出するため cap より広い。
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

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なアーキテクトです。**bash ベースの開発基盤**の詳細設計をレビューしてください。

【前提環境】
PHP 8.4 / Laravel 12 / Pest / PHPStan level 10 / bash (set -euo pipefail) / Linux procfs

【レビュー観点】
1. コードの正確性 (bash の罠、procfs パース、race、エッジケース)
2. 既存コードとの整合性 (命名規約、既存 self-test の書き方、guard の配置)
3. テスト計画の網羅性 (受入条件 20 件が 1 対 1 で固定されているか。すり抜けは無いか)
4. 副作用・後退リスク (特に dev DB 防御を緩めていないか)
5. セキュリティ (秘密ファイルの複製、権限)
6. 波及変更の網羅性
7. **保証範囲の誇張が無いか** (「証明」と「検出」を混同していないか)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: bughunt-harness-hardening

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 標準作業を起点に AI が教材設計し撮影を指示する（撮影者のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

本テーマはアプリ本体ではなく、その品質を検証する **bug-hunt 基盤**の改善である。
使命への貢献は間接的で、「harness 復旧に探索予算を奪われない」ことに閉じる。

### 禁止事項（AGENTS.md より。本テーマに関係する核）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. **dev DB への破壊操作をエージェント判断で実行すること**（本テーマの中心的制約）
6. prompt 文字列のコード直書き
9. Artifact の使用

加えて AGENTS.md §bug-hunt の**非交渉要件**:

> **dev DB 防御 (非交渉)**: 全 DB 操作は `scripts/bug-hunt-shard.sh` の用途別 wrapper
> (`env -i` で shell の `DB_*`/`PG*` を遮断 + DB名 regex + role guard) 経由のみ。
> 生 artisan/psql/tinker/createdb/dropdb 禁止。

### コーディングルール

- 変更対象は **bash スクリプト 2 本**と **Pest テスト**。PHP アプリコードの変更は無い
- **PHPStan level 10** / **Pint** / **Pest** の各レーンは緑を維持する
- bash は既存スタイルに合わせる（`set -euo pipefail` 前提、`local` 宣言、日本語コメント）
- **`BUGHUNT_SHARD_CAP` は env で上書きしない**という既存宣言を壊さない

## 概念設計リファレンス

`devnotes/20260810-0251-bughunt-harness-hardening/conceptual-design.md`
（conceptual-review Round 4 で **APPROVED**）

出自は bug-hunt run `20260809-152048` の報告 §8
（`devnotes/20260809-152048-bug-hunt/report.md`）。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| H-0 | **self-test を `composer test` の配線に載せる**（H-1/H-2/H-3 の受入条件が実際に走る前提） | `tests/Architecture/BughuntSelfTestExecutionTest.php` (新規) | 最高 |
| H-1 | worker group の生存判定から zombie を除外し、dropdb 到達制御を固定 | `scripts/bug-hunt-shard.sh` | 高 |
| H-2 | teardown のループ範囲を cap から導出 | `scripts/bug-hunt-shard.sh` | 中 |
| H-3 | `optimize:clear --except=cache` + `env -i` + 拡張 clear 集合の inventory | `scripts/bug-hunt-shard.sh`, `tests/Architecture/BughuntOptimizeClearTaskInventoryTest.php` (新規) | 中 |
| H-4 | `.env.bughunt.local` のコピー（mode `0600` 固定） | `scripts/setup-worktree.sh` | 中 |

---

## 施策 H-0: self-test を `composer test` の配線に載せる

### なぜ最初に要るか（調査結果）

概念設計は受入条件 20 件のうち **14 件を `self-test` レーンに置いた**。ところが実地調査の結果、
**`scripts/bug-hunt-shard.sh self-test` はどこからも自動実行されていない**:

- `composer.json` の `test` / `test:browser` スクリプトに含まれない
- `.github/workflows/ci.yml` にも無い
- `tests/Architecture/BughuntShardCapInvariantTest.php` L29-30 /
  `BughuntOrchestratorGateInvariantTest.php` L18-19 は
  「実行時の挙動は self-test が担う」と**参照している**が、**呼んではいない**

つまり現状の self-test は **人が思い出したときだけ走る**。この上に受入条件を積んでも、
「テストがあるのに走らない」状態になる（禁止事項 1 の実質的な違反）。

### 変更箇所

- 新規: `tests/Architecture/BughuntSelfTestExecutionTest.php`

### 実装

```php
<?php

declare(strict_types=1);

/*
 * bug-hunt harness の実行配線ゲート。
 *
 * scripts/bug-hunt-shard.sh self-test は「実資源に触れない自己検証」で、
 * guard / 資源導出 / env 隔離 / worker 停止判定の**実行時挙動**を担う。
 * 既存の Architecture テスト (Cap / OrchestratorGate) は静的構造だけを見ており、
 * self-test を「参照」はしていても「実行」していなかった。
 * = 二段防御の片側が自動実行されていなかった。ここで配線する。
 *
 * self-test は sandbox (mktemp -d) 内で完結し、実 DB / 実 serve / 実 worktree には触れない
 * (BUGHUNT_SANDBOX を自分で立て、RUN_BASE / TMP_BASE / LOCK_FILE / ENV_FILE を差し替える)。
 * 所要は実測 ~4 秒。
 */

test('bug-hunt harness の self-test が通ること', function (): void {
    $script = base_path('scripts/bug-hunt-shard.sh');
    expect($script)->toBeReadableFile();

    $process = Process::timeout(120)->run([$script, 'self-test']);

    expect($process->exitCode())->toBe(
        0,
        "self-test が失敗した:\n".$process->output()."\n".$process->errorOutput(),
    );
});
```

> `Illuminate\Support\Facades\Process` を使う（`shell_exec` の直書きをしない）。
> **timeout を明示**する（実測 4 秒に対し 120 秒。CI の遅さを吸収しつつ無限待ちにしない）。

### テスト計画

- [ ] 本テスト自体が受入条件の**実行手段**なので、まず「self-test を意図的に壊すと赤くなる」ことを
      手で 1 回確認する（例: `t_fail` を 1 つ足して赤 → 戻して緑）。fail 先行の代替。

### リスク

- self-test は `setsid sleep 30` 等の**実プロセスを起動する**（既存の (y6a)(y6b) ケース）。
  並列テスト実行下でも sandbox 内 pidfile を使うため他テストと干渉しない。
  ただし `composer test --parallel` で複数プロセスが同時に self-test を走らせると
  **同じ `/tmp` に別 sandbox が並ぶ**だけなので衝突しない（`mktemp -d` が一意）。

---

## 施策 H-1: worker group の生存判定から zombie を除外する

### 変更箇所

- `scripts/bug-hunt-shard.sh`:
  - 新規 helper `group_live_members()`（procfs 走査）
  - `stop_shard_workers()` の判定差し替え（L823-846 相当）
  - `cmd_teardown()` の dropdb 分岐直前に 2 回目の判定を置く
  - `cmd_self_test()` に受入条件 1〜11 のケース追加

### 現行コード（問題箇所）

```bash
kill -TERM -- "-${wpid}" 2>/dev/null || true
for t in 1 2 3 4 5; do
    kill -0 -- "-${wpid}" 2>/dev/null || break
    sleep 0.4
done
if kill -0 -- "-${wpid}" 2>/dev/null; then
    kill -KILL -- "-${wpid}" 2>/dev/null || true
    sleep 0.4
fi
if kill -0 -- "-${wpid}" 2>/dev/null; then     # ← zombie も「生存」と数える
    echo "error: ... が KILL 後も残留 ..." >&2
    rc=1
    continue
fi
rm -f "${wpidfile}"
```

### 変更後コード

```bash
# --- process group の「生きている」メンバー数 (zombie を除く) ------------------
# kill -0 -- -PGID は「シグナルを送れるか」であって「動いているか」ではない。
# zombie (state=Z) は終了済みで DB 接続も資源も持たないのに、この判定では「生存」と数える。
# PID 1 が zombie を刈らない環境 (本 devcontainer の PID 1 は sleep infinity) では
# queue:work --once の終了済み子が group に残り続け、dropdb が永久に抑止される。
#
# ★ 見たいのは「DB 接続を保持しうるプロセスが残っているか」なので、判定対象を procfs にする。
# ★ /proc/<pid>/stat の comm は括弧で囲まれ **プロセス名に空白や ')' を含みうる**ため、
#   先頭からの位置決め (awk '{print $3}' 等) は state を誤読する。
#   **最後の ") " より後ろ**を分割すれば state=1 / ppid=2 / pgrp=3 が確定する。
# ★ 走査中に消えた pid は無視する (消えたのだから残留ではない)。
#
# 出力: "<live> <zombie>" (対象 pgid のメンバー数。live は zombie を含まない)
group_member_counts() {
    local pgid=$1 live=0 zomb=0 statfile line rest state pgrp
    for statfile in /proc/[0-9]*/stat; do
        line="$(cat "${statfile}" 2>/dev/null)" || continue   # race: 消えた pid
        [[ -n "${line}" ]] || continue
        rest="${line##*') '}"                                  # comm の閉じ括弧より後ろ
        [[ "${rest}" != "${line}" ]] || continue               # 想定外の書式は数えない
        state="${rest%% *}"
        pgrp="$(echo "${rest}" | cut -d' ' -f3)"
        [[ "${pgrp}" == "${pgid}" ]] || continue
        if [[ "${state}" == "Z" ]]; then
            zomb=$((zomb + 1))
        else
            live=$((live + 1))
        fi
    done
    echo "${live} ${zomb}"
}

# 生きているメンバーが 0 なら 0 を返す (= 停止成功とみなしてよい)。
# zombie のみだった場合は stderr に理由を出す (無言で pidfile を消さない)。
group_stopped() {
    local pgid=$1 label=$2 counts live zomb
    counts="$(group_member_counts "${pgid}")"
    live="${counts%% *}"; zomb="${counts##* }"
    if [[ "${live}" != 0 ]]; then
        return 1
    fi
    if [[ "${zomb}" != 0 ]]; then
        echo "note: ${label} は zombie ${zomb} 件を残して停止 (PID 1 が刈らない環境。DB 接続は保持しない)" >&2
    fi
    return 0
}
```

`stop_shard_workers()` の最終判定を差し替える:

```bash
        kill -TERM -- "-${wpid}" 2>/dev/null || true
        for t in 1 2 3 4 5; do
            group_stopped "${wpid}" "shard-${shard} worker group (${conn}, pgid=${wpid})" >/dev/null 2>&1 && break
            sleep 0.4
        done
        if ! group_stopped "${wpid}" "shard-${shard} ..." >/dev/null 2>&1; then
            kill -KILL -- "-${wpid}" 2>/dev/null || true
            sleep 0.4
        fi
        # ★ ここで初めて stderr つきの本判定を行う (待機ループ中に note を撒かない)
        if ! group_stopped "${wpid}" "shard-${shard} worker group (${conn}, pgid=${wpid})"; then
            echo "error: shard-${shard} worker group (${conn}, pgid=${wpid}) が KILL 後も残留 (pidfile 保持: ${wpidfile})" >&2
            rc=1
            continue
        fi
        rm -f "${wpidfile}"
```

### dropdb 到達制御（受入条件 9〜11 の中核）

`cmd_teardown()` は既に `workers_stopped` で dropdb を抑止している。ここに **2 回目の判定**を足す。

```bash
        # ★ procfs 走査は一時点のスナップショットではない。TOCTOU 窓は消せないが、
        #   dropdb 分岐の**直前**でもう一度確認して窓を最小化する。
        #   2 回目で非 zombie が観測されたら fail-closed (pidfile は 1 回目で削除済みでも
        #   dropdb はしない = DB を消さない側に倒す)。
        if [[ "${drop_db}" == "--drop-db" && "${workers_stopped}" == 1 ]] && ! is_dryrun; then
            if ! recheck_shard_workers_stopped "${shard}"; then
                teardown_rc=1
                echo "warning: shard-${shard} の worker が dropdb 直前の再確認で残留 — dropdb をスキップ" >&2
            else
                pg_admin_for_provision dropdb "$(shard_db "${shard}")"   # ← DB名 guard + admin role は不変
            fi
        fi
```

`recheck_shard_workers_stopped()` は 1 回目に記録した pgid 群（`stop_shard_workers` が
`BUGHUNT_STOPPED_PGIDS` 配列に積む）を `group_stopped` で再評価する。

> **dropdb 側の guard は 1 行も触らない。** `pg_admin_for_provision dropdb` は従来どおり
> `guard_admin_provision`（`SHARD_DB_RE` 一致 + `BUGHUNT_ADMIN_USER` 明示）を通る。
> H-1 が変えるのは「そこへ**到達してよいか**」だけである。
> **`dropdb` が active connection で失敗した場合も安全側の失敗**として扱う
> （`pg_admin_for_provision` の非ゼロがそのまま `set -e` で伝播する現行挙動を維持する）。

### テスト計画（self-test へ追加。受入条件 1〜11 に 1 対 1 対応）

既存 (y6a)〜(y6d) の隣に (y7) 群を足す。**procfs パースは実プロセスを使わず
fixture 文字列で検証**し、group 判定は `setsid sleep` の実プロセスで検証する。

- [ ] **(y7a) 受入条件 5**: `group_member_counts` のパースを fixture で検証。
      `comm` に空白と `)` を含む行（例 `123 (my ) proc) Z 1 456 ...`）で
      `state=Z` / `pgrp=456` を正しく読む
- [ ] **(y7b) 受入条件 2**: メンバー 0 件 → `group_stopped` が 0 を返し、**note を出さない**
- [ ] **(y7c) 受入条件 1・8**: 全て zombie → 0 を返し、**stderr に note が出る**
- [ ] **(y7d) 受入条件 3**: 非 zombie が 1 件 → 非 0 を返す（`setsid sleep` の実プロセス）
- [ ] **(y7e) 受入条件 4**: zombie + 非 zombie 混在 → 非 0 を返す
- [ ] **(y7f) 受入条件 6**: 走査中に消える pid（存在しない `/proc/<pid>/stat`）を残留と数えない
- [ ] **(y7g) 受入条件 7**: 「1 回目ゼロ・2 回目に非 zombie 出現」→ **失敗**になる（必須ケース）。
      `group_stopped` を stub し、1 回目 0 / 2 回目 1 を返させて `recheck_...` が非 0 になることを見る
- [ ] **(y7h) 受入条件 9**: `group_live_members` 相当が非 zombie を返す状況で
      **`pg_admin_for_provision` が一度も呼ばれない**（stub して呼び出し回数 0 を assert）
- [ ] **(y7i) 受入条件 10**: 停止失敗時に pidfile が保持され teardown が失敗扱い（既存 (y6b) の延長）
- [ ] **(y7j) 受入条件 11**: dropdb 候補へ進んだ場合も `guard_shard_db_name` と
      admin role 明示を通る（`pg_admin_for_provision` の呼び出し引数を検査）

受入条件 12（raw `dropdb` 新設なし）は **Architecture テスト**側で見る（下記 H-3 と同じファイルに置く）。

### リスク

- **procfs 走査のコスト**: `/proc/[0-9]*/stat` の全走査は数百プロセス規模で数 ms。
  teardown で shard 数 × worker 数 × 数回なので実用上問題ない。
- **`cut -d' ' -f3` の連続空白**: `/proc/<pid>/stat` の該当部は単一空白区切りなので安全。
  ただし fixture テスト (y7a) でこの前提ごと固定する。
- **最大の後退リスク**は「実行中 worker が残っているのに dropdb する」こと。
  受入条件 3・4・7・9 がこれを直接固定する。

---

## 施策 H-2: teardown のループ範囲を cap から導出する

### 変更箇所

- `scripts/bug-hunt-shard.sh` `cmd_teardown()` L1151

### 現行コード → 変更後コード

```bash
# 現行
for shard in 0 1 2 3 4 5 6 7 8; do

# 変更後
# ★ 範囲は cap から導出する (リテラルを置くと cap 変更時に SHARD_DB_RE とずれ、
#   自分の guard で abort する。実際 cap=8→4 の変更時にこれが起きた)。
#   seq への外部依存を増やさないため bash 算術ループを使う。
for ((shard = 0; shard <= BUGHUNT_SHARD_CAP; shard++)); do
```

### テスト計画

- [ ] **(y8a) 受入条件 13**: `declare -f cmd_teardown` に
      **数値リテラルのループ範囲が無い**こと（`for shard in 0 1 2 ...` の形が復活していない）
- [ ] **(y8b) 受入条件 14**: **テスト用 cap で実評価**する。
      sandbox 内で `BUGHUNT_SHARD_CAP` を 2 に差し替え、`SHARD_DB_RE` を再導出したうえで
      `guard_shard_db_name "$(shard_db N)"` が **N=0..2 で allow・N=3 で deny** になることを確認する。
      本番定数は env で上書きできないままにする（差し替えは self-test 内の局所再代入で行い、
      外部から注入する経路は作らない）

---

## 施策 H-3: `optimize:clear --except=cache` + `env -i` + 拡張 clear 集合の inventory

### 変更箇所

- `scripts/bug-hunt-shard.sh` `cmd_provision_all()` L1085
- 新規: `tests/Architecture/BughuntOptimizeClearTaskInventoryTest.php`

### 現行コード → 変更後コード

```bash
# 現行
php artisan optimize:clear > /dev/null

# 変更後
# ★ --except=cache: optimize:clear は複合コマンドで、標準タスクのうち cache:clear だけが
#   cache store=database のとき **dev DB の cache 表を DELETE** しにいく。
#   provision が要るのは bootstrap cache (config/route/view/event/compiled) の破棄であって
#   アプリケーションキャッシュではない (bughunt DB は直後に migrate:fresh する)。
#   --except はキー名 'cache' とコマンド名 'cache:clear' の両方に一致する
#   (OptimizeClearCommand::handle の $exceptions->hasAny([$command, $key]))。
#   ★ このフラグを消すと dev DB 未 migrate 環境で provision 全体が落ちる。消さないこと。
# ★ env -i: このスクリプトの非交渉の原則 (shell の DB_*/PG* を遮断してから artisan を叩く) へ
#   合流させる。ただし env -i が遮断するのは**親シェル由来の env だけ**で、
#   Laravel は .env を読む。「絶対に DB へ接続しない」とは主張しない
#   (拡張 clear コマンドの集合は BughuntOptimizeClearTaskInventoryTest が別途 pin する)。
env -i PATH="${PATH}" HOME="${HOME}" php artisan optimize:clear --except=cache > /dev/null
```

### 拡張 clear 集合の inventory（新規 Architecture テスト）

```php
/*
 * optimize:clear の拡張タスク目録 (deny-by-default)。
 *
 * bug-hunt の provision は `optimize:clear --except=cache` を叩く。標準タスクのうち
 * DB に触る cache:clear は除外したが、ServiceProvider::$optimizeClearCommands 経由で
 * **パッケージが登録した clear コマンド**も同時に実行される。ここが増えると
 * 「dev DB を触らない」前提が静かに崩れる。
 *
 * ★ これは証明ではなく**検出**である。集合が増えたら赤くなる。
 * ★ 保証しないもの: 既存の同名コマンドが依存更新によって内部的に DB 接続する実装へ
 *   変わった場合、集合検査は赤くならない (集合の増加しか見ていない)。
 */
const BUGHUNT_OPTIMIZE_CLEAR_ALLOWLIST = [
    'filament' => [
        'command' => 'filament:optimize-clear',
        'package' => 'filament/support',
        'rationale' => 'Filament の component / blade キャッシュ (ファイル) の破棄。DB を触らない',
    ],
    'blade-icons' => [
        'command' => 'icons:clear',
        'package' => 'blade-ui-kit/blade-icons',
        'rationale' => 'アイコンキャッシュ (ファイル) の破棄。DB を触らない',
    ],
];
```

テストは `ServiceProvider::$optimizeClearCommands`（static property）を実アプリ起動後に読み、
**キー集合が allowlist と完全一致**することを assert する。

### テスト計画

- [ ] **(y9a) 受入条件 15**: `declare -f cmd_provision_all` に
      `optimize:clear` があり、**同じ行に `--except=cache`** があること
- [ ] **(y9b) 受入条件 16**: 同じ行が **`env -i` で始まる**こと（ambient `DB_*`/`PG*` が渡らない）
- [ ] **受入条件 17**: `BughuntOptimizeClearTaskInventoryTest` が
      `ServiceProvider::$optimizeClearCommands` のキー集合を allowlist と完全一致で固定
- [ ] **受入条件 12**（H-1 由来）: 同 Architecture テストで
      `scripts/bug-hunt-shard.sh` に **`pg_admin_for_provision` を経由しない raw `dropdb` 呼び出しが無い**
      ことを走査で固定する

### リスク

- `env -i` により `PATH` / `HOME` 以外が落ちる。`php` の解決は `PATH` で足りる
  （既存の `artisan_for_shard` も同じ形）。
- `--except=cache` を将来誰かが「不要」と判断して消すと再発する。**コメントで理由を残し**、
  self-test (y9a) が消滅を検出する。

---

## 施策 H-4: `.env.bughunt.local` を worktree へコピーする（mode `0600`）

### 変更箇所

- `scripts/setup-worktree.sh` L200-215（実行時ファイルのコピー節）

### 波及変更

- `.claude/skills/app-bug-hunt/SKILL.md` は**変更不要**（記述のほうが正しく、実装を合わせる）

### 変更後コード

```bash
# .env.bughunt.local は .gitignore 対象で worktree には決して現れない = コピーが唯一の供給路。
# bug-hunt は worktree 走行が既定 (AGENTS.md) なので、無いと provision が必ず止まる。
# ★ mode は親に追随させず 0600 に固定する。親が 0644 だと cp -p は
#   **world-readable な秘密ファイルを新たに作る**ため、契約として弱い。
# ★ コピー先が既に存在する場合も「上書き → chmod」の順で必ず 0600 に落ち着かせる。
if [[ -f "${REPO_ROOT}/.env.bughunt.local" ]]; then
    cp "${REPO_ROOT}/.env.bughunt.local" "${WORKTREE_DIR}/.env.bughunt.local"
    chmod 600 "${WORKTREE_DIR}/.env.bughunt.local"
fi
```

コピー処理は既存の `.env` / `oauth-*.key` と同じ節に置き、
**関数 `provision_runtime_files()` として切り出す**（契約テストが
`composer install` / `pnpm install` / DB 作成を走らせずに検証できるようにするため）。

### テスト計画

- [ ] 新規 `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php`
  - **受入条件 18**: 一時ディレクトリを親/worktree に見立てて `provision_runtime_files` を
    呼び、親に `.env.bughunt.local` があればコピーされる
  - **受入条件 19**: 親に無ければコピー先も作られない（no-op）
  - **受入条件 20**: **親を `0644` にしてもコピー先が `0600`** になる
    （`cp -p` に退行していないことの検出）。既存ファイルへの上書きケースも同様
- [ ] 既存の `run-browser-test.contract.test.ts` / `audit-gate.contract.test.ts` と同じく
      **契約テスト**の位置づけ。bash 関数を `bash -c 'source ...; provision_runtime_files ...'` で
      直接呼ぶ

### リスク

- `setup-worktree.sh` を関数化すると既存の実行順序が変わる恐れがある。
  **切り出しは既存ブロックをそのまま関数で包む**にとどめ、呼び出し位置は動かさない。
- 秘密ファイルの複製が 1 つ増える。`0600` 固定で world-readable は作らない。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | H-0 が他 3 施策の受入条件の実行手段なので**順序依存**がある。変更対象が bash 2 本 + Pest 3 本と小さく、1 worktree で完結する。アプリコードに触れないため他 TODO と競合しない |
| 競合リスク | 低。`scripts/bug-hunt-shard.sh` は bug-hunt 専用で、他の作業が同時に触る可能性が低い |

## 実装順序（テストファースト）

1. **H-0**: `BughuntSelfTestExecutionTest` を追加 → `composer test` で self-test が走ることを確認
   （意図的に self-test を壊して赤を見てから戻す）
2. **H-1**: self-test に (y7a)〜(y7j) を追加 → **赤を確認** → `group_member_counts` /
   `group_stopped` / `recheck_shard_workers_stopped` を実装 → 緑
3. **H-2**: self-test に (y8a)(y8b) を追加 → **赤を確認** → ループを算術化 → 緑
4. **H-3**: self-test に (y9a)(y9b)、Architecture に inventory + raw dropdb 走査を追加 →
   **赤を確認** → `--except=cache` + `env -i` を実装 → 緑
5. **H-4**: 契約テストを追加 → **赤を確認** → `provision_runtime_files` を切り出してコピー追加 → 緑
6. AGENTS.md の検証コマンド一覧を**全数**回して全緑にする:
   `composer test` / `composer test:browser` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
   `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
7. **実機確認**: `BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh provision-all --parallel=2`
   → `teardown --run-id ... --drop-db` を実走し、**dropdb が実際に成功して DB が消える**ことを見る
   （self-test は stub なので、最後に一度は実物で確認する）

## 受入条件と検証手段の対応表（20 件の全件マップ）

| # | 受入条件 | 検証 |
|---|---|---|
| 1 | 全 zombie → 成功 + pidfile 削除 | self-test (y7c) |
| 2 | 0 件 → 成功・note なし | self-test (y7b) |
| 3 | 非 zombie 1 件以上 → 失敗 + pidfile 保持 | self-test (y7d) |
| 4 | zombie/非 zombie 混在 → 失敗 | self-test (y7e) |
| 5 | `comm` の空白・`)` を誤読しない | self-test (y7a) |
| 6 | PID 消滅 race を残留と誤判定しない | self-test (y7f) |
| 7 | 2 回評価。「1 回目ゼロ・2 回目非 zombie」は失敗 | self-test (y7g) |
| 8 | zombie のみで成功したら stderr に出る | self-test (y7c) |
| 9 | 非 zombie 時に dropdb wrapper が呼ばれない | self-test (y7h) |
| 10 | 停止失敗時は pidfile 保持 + teardown 失敗 | self-test (y7i) |
| 11 | dropdb 経路は DB名 guard + admin role を必ず通る | self-test (y7j) |
| 12 | raw `dropdb` 新設なし | Architecture (inventory テスト内) |
| 13 | teardown ループにリテラル上限なし | self-test (y8a) |
| 14 | テスト用 cap で `0..cap` allow / `cap+1` deny | self-test (y8b) |
| 15 | `optimize:clear --except=cache` | self-test (y9a) |
| 16 | 同呼び出しが `env -i` 経由 | self-test (y9b) |
| 17 | `$optimizeClearCommands` を allowlist に pin | Architecture (inventory テスト) |
| 18 | `.env.bughunt.local` があればコピー | 契約テスト |
| 19 | 無ければ no-op | 契約テスト |
| 20 | コピー先 mode が `0600`（親 `0644` でも） | 契約テスト |
| — | **上記 self-test 群が自動実行される** | Architecture (H-0) |


---

## 関連する現行コード

### scripts/bug-hunt-shard.sh: stop_shard_workers (L795-848)
```bash
# setsid 起動により pid==pgid のため process group 一括 kill (master + queue:work --once 子)。
# cmdline 照合 (worker_alive) 不一致/死亡済みの stale pidfile は kill せず削除のみ (誤 kill 防止優先)。
# 停止シーケンス: TERM → group 消滅待ち (最大 2s) → KILL escalation → 再確認。
# 成功条件は **process group 全体の消滅** (master 単体判定だと終了処理中の queue:work 子の
# DB 接続が残り dropdb と race する)。kill -0 -- -PGID は cmdline 照合済みの自所有 group への
# 存在確認で待機用途として安全。全 shard 横断の pgrep 判定はしない。
# ★ 消滅を確認できた group のみ pidfile を削除する。残留/検証不能の group の pidfile は保持し
#   (追跡可能性を失わない)、戻り値 1 で失敗を通知する (呼び出し側が dropdb を抑止する)。
# (Codex 詳細 R1/R2/R3/R4 反映)
stop_shard_workers() {
    local shard=$1 conn wpidfile wpid wpgid t rc=0
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        wpidfile="$(worker_pidfile "${shard}" "${conn}")"
        [[ -f "${wpidfile}" ]] || continue
        wpid="$(cat "${wpidfile}" 2>/dev/null || echo)"
        if ! worker_alive "${shard}" "${conn}"; then
            # プロセス不存在 = 真に stale → 削除のみ。プロセスは存在するが所有確認 (cmdline 照合)
            # できない場合は、一時的な /proc 読み出し失敗や pid 再利用の可能性があり
            # 「停止済み」と誤認して追跡情報を消してはならない → pidfile 保持 + 失敗通知
            if [[ -n "${wpid}" && "${wpid}" != 0 ]] && kill -0 "${wpid}" 2>/dev/null; then
                echo "error: shard-${shard} worker (${conn}) pid=${wpid} は存在するが所有確認できない — kill せず pidfile 保持 (${wpidfile})" >&2
                rc=1
            else
                rm -f "${wpidfile}"
            fi
            continue
        fi
        # group kill の前提 (pid==pgid = setsid 成立) を停止側でも検証する。不成立のまま
        # kill -0 -- -pid すると「存在しない group が消滅済み」と誤認し実 worker を残留させる
        wpgid="$(ps -o pgid= -p "${wpid}" 2>/dev/null | tr -d ' ' || true)"
        if [[ "${wpgid}" != "${wpid}" ]]; then
            echo "error: shard-${shard} worker (${conn}) pid=${wpid} pgid=${wpgid:-?} — setsid 不成立のため group kill せず pidfile 保持 (${wpidfile})" >&2
            rc=1
            continue
        fi
        kill -TERM -- "-${wpid}" 2>/dev/null || true
        for t in 1 2 3 4 5; do
            kill -0 -- "-${wpid}" 2>/dev/null || break
            sleep 0.4
        done
        if kill -0 -- "-${wpid}" 2>/dev/null; then
            kill -KILL -- "-${wpid}" 2>/dev/null || true
            sleep 0.4
        fi
        if kill -0 -- "-${wpid}" 2>/dev/null; then
            echo "error: shard-${shard} worker group (${conn}, pgid=${wpid}) が KILL 後も残留 (pidfile 保持: ${wpidfile})" >&2
            rc=1
            continue
        fi
        rm -f "${wpidfile}"
    done
    return "${rc}"
}


```

### scripts/bug-hunt-shard.sh: cmd_teardown (L1148-1196)
```bash
cmd_teardown() {
    local run_id=$1 drop_db=${2:-}
    require_orchestrator "teardown"
    local shard pid port teardown_rc=0
    for shard in 0 1 2 3 4 5 6 7 8; do
        # 専用 connection worker の停止 (serve より先に止め、--drop-db 時の DB 接続残留を防ぐ)。
        # stop_shard_workers が cmdline 照合 → TERM → group 消滅待ち → KILL escalation →
        # 再確認まで行い、残留があれば pidfile を保持して非ゼロを返す (追跡可能性を失わない)。
        # 停止失敗 shard の dropdb は抑止する (接続保持した孤児 worker と dropdb の衝突防止)。
        local workers_stopped=1
        if ! stop_shard_workers "${shard}"; then
            workers_stopped=0
            teardown_rc=1
            echo "warning: shard-${shard} の worker 停止に失敗 — この shard の dropdb をスキップ (pidfile 保持)" >&2
        fi

        local pidfile="${TMP_BASE}/serve-${shard}.pid"
        if [[ -f "${pidfile}" ]]; then
            pid="$(cat "${pidfile}" 2>/dev/null || echo)"
            port="$(shard_port "${shard}")"
            if [[ -n "${pid}" && "${pid}" != 0 && -r "/proc/${pid}/cmdline" ]] \
                && tr '\0' ' ' < "/proc/${pid}/cmdline" | grep -q "artisan serve" \
                && tr '\0' ' ' < "/proc/${pid}/cmdline" | grep -q -- "--port=${port}"; then
                # 子 php -S worker を親より先に撃つ (親 kill で init に reparent され孤児化するのを防ぐ)。
                local wpid
                for wpid in $(pgrep -P "${pid}" 2>/dev/null || true); do
                    if [[ -r "/proc/${wpid}/cmdline" ]] \
                        && tr '\0' ' ' < "/proc/${wpid}/cmdline" | grep -q -- "-S " \
                        && tr '\0' ' ' < "/proc/${wpid}/cmdline" | grep -q -- ":${port}"; then
                        kill -TERM "${wpid}" 2>/dev/null || true
                    fi
                done
                kill -TERM "${pid}" 2>/dev/null || true
            fi
            rm -f "${pidfile}"
        fi
        if [[ "${drop_db}" == "--drop-db" && "${workers_stopped}" == 1 ]] && ! is_dryrun; then
            pg_admin_for_provision dropdb "$(shard_db "${shard}")"
        fi
        rm -f "$(wrapper_path "${shard}")"
        rm -rf "storage/bughunt-coverage/.pcov-ini-${shard}"
    done
    reap_orphan_browser
    [[ "${teardown_rc}" == 0 ]] \
        || die 1 "teardown 一部失敗: worker group が残留 (該当 shard の DB は破棄していない)。上記 warning の pidfile から手動確認・再 teardown すること"
    echo "teardown done: run-id=${run_id}"
}

# 孤児ブラウザ回収: fan-out subagent が close 前に turn budget で落ちると常駐ブラウザが孤児化する。

```

### scripts/bug-hunt-shard.sh: cmd_provision_all の該当部 (L1077-1095)
```bash
    local RUN_ID RUN_DIR
    RUN_ID="$(allocate_run_id)"
    RUN_DIR="$(run_dir "${RUN_ID}")"
    mkdir -p "${RUN_DIR}" "${TMP_BASE}"
    manifest_update "${RUN_ID}" - "parallel=${n}" "mode=fan-out" \
        "started_at=\"$(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M:%S')\""

    if ! is_dryrun; then
        php artisan optimize:clear > /dev/null
        ensure_fresh_assets
    fi

    local i
    for i in $(seq 1 "${n}"); do
        cmd_provision "${i}" "${RUN_ID}"
        manifest_update "${RUN_ID}" "${i}" "stories=\"$(stories_for_shard "${i}" "${n}")\""
    done

    echo "provisioned-all: run-id=${RUN_ID} parallel=${n} (manifest: $(manifest_path "${RUN_ID}"))"

```

### scripts/bug-hunt-shard.sh: cap / SHARD_DB_RE (L62-106)
```bash
BASE_PORT=8010
# 並列 shard の上限 (家系共通の標準形。c2c オーナー裁定 AG-048b で 4 に統一)。
# ★ env で上書きしない (ハードコード)。SHARD_DB_RE は「触れてよい DB の allowlist」であり、
#   外から広げられる余地を作ることはガードの緩和にあたる。
# ★ 1 桁前提 (2..9)。ポート採番が BASE_PORT + N である以上 cap <= 9 は構造的制約。
#   下の文字クラス導出 ([0-${CAP}]) もこの前提に依存する。self-test [a] が 1 桁性を assert する。
BUGHUNT_SHARD_CAP=4
# bug-hunt 専用 DB 接頭辞。dev DB (テンプレート slug の DB) とは別名にして隔離する。
# この接頭辞と数値 suffix のみが SHARD_DB_RE に一致し、それ以外の DB 名は全 abort される。
BUGHUNT_DB_PREFIX="${BUGHUNT_DB_PREFIX:-bug_hunt}"
RUN_ID_RE='^[0-9]{8}-[0-9]{6}(-[0-9]+)?$'
SHARD_RE="^[0-${BUGHUNT_SHARD_CAP}]$"   # 0 = 直列走行 (serial)、1..CAP = 並列 shard
# ★ SHARD_DB_RE の代入はここではなく die() 定義直後 (BUGHUNT_DB_PREFIX の形式検証の後) に置く。

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

# ★ prefix は SHARD_DB_RE にそのまま埋め込まれる。regex メタ文字が入ると allowlist が壊れるため
#   (例: 'b.g_hunt' は 'bXg_hunt' にも一致してしまう)、埋め込む前に形を固定する
#   (「別名の bug-hunt DB 群を選ぶ」既存の自由度は保つ)。
[[ "${BUGHUNT_DB_PREFIX}" =~ ^[a-z][a-z0-9_]*$ ]] \
    || die 1 "BUGHUNT_DB_PREFIX が不正: '${BUGHUNT_DB_PREFIX}' (^[a-z][a-z0-9_]*\$ のみ。regex メタ文字は allowlist を壊す)"

# ★ 本スクリプトが createdb/dropdb/migrate してよい shard DB の **allowlist** (dev DB 防御の核)。
#   cap と同期する。「残留も含めて bug-hunt DB を守る / 検出する」側 —
#   tests/Support/Ci/TestDatabaseEnv::DEV_DB_DENYLIST と
#   database/seeders/Concerns/DetectsBughuntDatabase::BUGHUNT_DB_REGEX — は **cap と同期させない**
#   (狭めると過去 cap=8 期の残留 DB を守れなくなる)。方向が逆であることに注意。
SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"


```

### scripts/bug-hunt-shard.sh: pg_admin_for_provision と guard (L204-215, L325-355)
```bash
# admin provision 経路 (pg_admin_for_provision / createdb / dropdb): DB名 regex ∧ admin_user 明示。
guard_admin_provision() {
    local db="${1:-}" admin_user="${2:-}"
    guard_shard_db_name "${db}"
    [[ -n "${admin_user}" ]] \
        || die 1 "guard_admin_provision: BUGHUNT_ADMIN_USER が未設定 (createdb/dropdb には admin role 明示必須)"
}

# --- .env.bughunt.local 読み出し ----------------------------------------------

env_file_get() {
    [[ -f "${ENV_FILE}" ]] || { echo ""; return 0; }

...
# createdb / dropdb — admin 経路 (bughunt role は CREATEDB を持たない)。
pg_admin_for_provision() {
    local op=$1 db=$2   # op ∈ {createdb, dropdb}
    local admin_user; admin_user="$(env_file_get BUGHUNT_ADMIN_USER)"
    guard_admin_provision "${db}" "${admin_user}"
    local -a op_cmd
    case "${op}" in
        createdb) op_cmd=(createdb -O bughunt "${db}") ;;   # ★ OWNER bughunt 必須
        dropdb)   op_cmd=(dropdb --if-exists "${db}") ;;
        *) die 2 "pg_admin_for_provision: unknown op '${op}'" ;;
    esac
    env -i PATH="${PATH}" \
        PGHOST="$(env_file_required DB_HOST)" PGPORT="$(env_file_required DB_PORT)" \
        PGUSER="${admin_user}" PGPASSWORD="$(env_file_get BUGHUNT_ADMIN_PASSWORD)" \
        "${op_cmd[@]}"
}

# read-only psql (pg_database 存在確認等。CREATEDB 不要) — owner bughunt role。
pg_owner_for_shard() {
    local op=$1 db=$2   # op ∈ {exists}
    guard_bughunt_runtime "${db}" bughunt
    local -a op_cmd
    case "${op}" in
        exists) op_cmd=(psql -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='${db}'") ;;
        *) die 2 "pg_owner_for_shard: unknown op '${op}'" ;;
    esac
    env -i PATH="${PATH}" \
        PGHOST="$(env_file_required DB_HOST)" PGPORT="$(env_file_required DB_PORT)" \
        PGUSER=bughunt PGPASSWORD="$(env_file_get DB_PASSWORD)" \
        "${op_cmd[@]}"
}

```

### scripts/bug-hunt-shard.sh: self-test の worker 停止ケース (y6a-y6d) (L1815-1850)
```bash
    setsid sleep 30 > /dev/null 2>&1 &
    local fake_wpid=$!
    echo "${fake_wpid}" > "$(worker_pidfile 8 database-analysis)"
    ( worker_alive() { [[ "$1" == 8 && "$2" == database-analysis ]]; }
      stop_shard_workers 8 ) || t_fail "[y6a] stop_shard_workers (stub) が非ゼロ"
    wait "${fake_wpid}" 2>/dev/null || true    # 回収してから group 不在を確認 (flaky 防止)
    kill -0 -- "-${fake_wpid}" 2>/dev/null && t_fail "[y6a] stop_shard_workers が group を停止していない"
    [[ ! -f "$(worker_pidfile 8 database-analysis)" ]] || t_fail "[y6a] 停止成功後に pidfile が残留"

    # (y6b) 失敗系 (最重要不変条件): TERM/KILL を no-op 化して「group が残留」を再現し、
    #       rc=1 + pidfile 保持を機能検証する (kill -0 は builtin へ委譲 = 実在確認は本物)
    setsid sleep 30 > /dev/null 2>&1 &
    local fake_wpid2=$!
    echo "${fake_wpid2}" > "$(worker_pidfile 8 database-analysis)"
    ( worker_alive() { [[ "$1" == 8 && "$2" == database-analysis ]]; }
      kill() { [[ "${1:-}" == "-TERM" || "${1:-}" == "-KILL" ]] && return 0; builtin kill "$@"; }
      sleep() { :; }    # 待機ループ短縮
      stop_shard_workers 8 ) && t_fail "[y6b] 停止不能 group なのに rc=0"
    [[ -f "$(worker_pidfile 8 database-analysis)" ]] || t_fail "[y6b] 停止失敗時に pidfile が削除された (追跡情報喪失)"
    builtin kill -TERM -- "-${fake_wpid2}" 2>/dev/null || true    # 後片付け
    wait "${fake_wpid2}" 2>/dev/null || true
    rm -f "$(worker_pidfile 8 database-analysis)"

    # (y6c) stale pidfile (死亡済み pid) は kill なしで削除のみ・rc=0
    echo 999999999 > "$(worker_pidfile 8 database-render)"
    stop_shard_workers 8 || t_fail "[y6c] stale pidfile で stop_shard_workers が非ゼロ"
    [[ ! -f "$(worker_pidfile 8 database-render)" ]] || t_fail "[y6c] stale pidfile が削除されない"

    # (y6d) 「pid は存在するが所有確認できない」は pidfile 保持 + rc=1 (誤 stale 判定の防止)。
    #       自プロセス (bash) の pid = 実在するが cmdline 照合に一致しない代表例
    echo $$ > "$(worker_pidfile 8 database-media)"
    stop_shard_workers 8 && t_fail "[y6d] 所有確認できない実在 pid なのに rc=0"
    [[ -f "$(worker_pidfile 8 database-media)" ]] || t_fail "[y6d] 所有確認できない実在 pid の pidfile が削除された"
    rm -f "$(worker_pidfile 8 database-media)"
    t_ok "queue worker wiring (derivation/drift/structure/alive/dryrun/stop 正常系+失敗系)"


```

### scripts/setup-worktree.sh: 実行時ファイルコピー (L195-220)
```bash
# === [2/7] 実行時ファイルのプロビジョニング ===
# .env は必須 (workspace の .env、無ければ committed の .env.example をコピー)。
# storage/oauth-*.key / public/build は runtime artifact (.gitignore 対象) で、workspace に
# あればコピー / 無ければ note して続行 (テンプレート初期状態では未生成のことがある。
# 必要になった時点で worktree 内 `php artisan passport:keys` / `pnpm build` で生成できる)。
echo ">>> [2/7] .env / storage/oauth-*.key / public/build を親からコピー"
if [[ -f "${REPO_ROOT}/.env" ]]; then
    cp "${REPO_ROOT}/.env" "${WORKTREE_DIR}/.env"
else
    cp "${REPO_ROOT}/.env.example" "${WORKTREE_DIR}/.env"   # .env 不在時は committed の .env.example をコピー
fi
for f in storage/oauth-private.key storage/oauth-public.key; do
    if [[ -f "${REPO_ROOT}/${f}" ]]; then
        cp "${REPO_ROOT}/${f}" "${WORKTREE_DIR}/${f}"
        PROVISIONED_PATHS+=("${f}")
    else
        echo "    note: ${f} が親に無いためコピーをスキップ (必要なら worktree 内で 'php artisan passport:keys')" >&2
    fi
done
if [[ -d "${REPO_ROOT}/public/build" ]]; then
    cp -r "${REPO_ROOT}/public/build" "${WORKTREE_DIR}/public/build"
    PROVISIONED_PATHS+=("public/build")
else
    echo "    note: public/build が親に無いためコピーをスキップ (必要なら worktree 内で 'pnpm build')" >&2
fi
emit_timing "2-provision"

```

### vendor: OptimizeClearCommand::getOptimizeClearTasks
```php
     *
     * @return array
     */
    public function getOptimizeClearTasks()
    {
        return [
            'config' => 'config:clear',
            'cache' => 'cache:clear',
            'compiled' => 'clear-compiled',
            'events' => 'event:clear',
            'routes' => 'route:clear',
            'views' => 'view:clear',
            ...ServiceProvider::$optimizeClearCommands,
        ];
    }

```

### 既存 Architecture テストの書き出し (BughuntShardCapInvariantTest.php L1-35)
```php
<?php

declare(strict_types=1);
use Tests\Support\Ci\TestDatabaseEnv;

/*
 * bug-hunt 並列枠数 cap の単一ソース化ゲート (c2c: bug-hunt-exec-infra / オーナー裁定 AG-048b)。
 *
 * 固定する不変条件:
 *   1. cap の正本は scripts/bug-hunt-shard.sh の BUGHUNT_SHARD_CAP ただ 1 つ (env 上書き不可)
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
 *
 * 既存テストとの役割分担: tests/Unit/Ci/TestDatabaseEnvTest.php は DEV_DB_DENYLIST の
 * **全体一致**を固定する。本テストが固定するのは「その denylist が **cap より広い**」という
 * 意図だけである。重複ではなく、「cap を下げたときに一緒に denylist も縮める」改変を止める別軸の検査。
 *
 * 実行時の挙動 (受理 / 拒否の exit code) は `scripts/bug-hunt-shard.sh self-test` が担う
 * 二段防御: Architecture = 静的構造、self-test = 実行配線。DB 不使用の静的検査。
 */

/** 守りの面を説明する行に付ける明示 opt-out マーカー (c2c 台帳の ref-ok と同じ発想)。 */
const CAP_DEFENSE_MARKER = 'cap-defense-ok';


```


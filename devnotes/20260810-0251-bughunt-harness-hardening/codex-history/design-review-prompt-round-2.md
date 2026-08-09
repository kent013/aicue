Round 1 の Critical 4 件 + Warning 7 件 + Suggestion 1 件をすべて対応した (反論なし)。

特に判定してほしい点:
- **H-1 の fail-closed 3 条件** (live>0 / unknown>0 / kill -0 成功なのに procfs 0 件) で
  fail-open の穴が塞がったか。
- **nameref による pgid 受け渡し** (`local -n` + 呼び出しごとの初期化) がスコープ問題を解いているか。
- **`parse_proc_stat_line` の分離**でテストが実装を検証する形になったか
  (テスト側でパースを複製しない)。
- **raw dropdb 走査の仕様** (非コメント行 / コマンド位置 / 許可 1 箇所) が
  偽陽性・見落としの両方を避けられているか。
- **`install -m 600`** と **source 専用 guard** が H-4 の 2 つの Critical を解いているか。

# 対応マトリクス

# 対応マトリクス: design-review (harness) Round 1

Critical 4 件 + Warning 7 件 + Suggestion 1 件。**全件対応**した（反論なし）。

## [Warning/H-0] self-test の sandbox がテスト側で強制されていない
- 判断: **対応する**
- 根拠: 妥当。`self-test` が自前で `mktemp -d` するのに依存すると、
  sandbox 化そのものが退行したとき実 `tmp/` / `devnotes/` を触る余地が残る。
- 対応内容: Pest 側で一時ディレクトリを作り **`TMPDIR` を渡して起動**する
  （`mktemp -d` の置き場をテストが握る）。`finally` で残骸を回収する。
  加えて **「self-test が自前 sandbox を立てる構造であること」を静的に見るテストを 1 本追加**した
  （`BUGHUNT_SANDBOX` と `sandbox="$(mktemp -d` の存在）。
  「通ること」だけでは sandbox 化の退行を検出できないため。

## [Warning/H-0] `Process` の import と起動方法が曖昧
- 判断: **対応する**
- 対応内容: `use Illuminate\Support\Facades\Process;` を明示し、
  **`['bash', $script, 'self-test']`** で起動する形にした（executable bit に依存しない）。

## [Critical/H-1] procfs の「読めない・解釈できない」を 0 件扱いに倒すのは fail-open
- 判断: **対応する**
- 根拠: 指摘のとおり。消滅 race（open 失敗）を無視するのは妥当だが、
  **「読めたが解釈できない」を無視すると、dropdb 直前判定で確認不能を成功に倒す**ことになる。
  ここは DB を消さない側へ倒すべき。
- 対応内容: `group_member_counts` の出力を **`live zombie unknown` の 3 値**に変え、
  `group_stopped` の fail-closed 条件を 3 つにした:
  (a) `live > 0` なら失敗、(b) **`unknown > 0` なら失敗**（確認不能）、
  (c) **`kill -0 -- -PGID` が成功しているのに procfs でメンバーを 1 件も確証できない**なら失敗
  （procfs 自体が読めていない可能性があり、「0 件だから成功」は fail-open になる）。
  (c) は Codex の代案そのままである。

## [Critical/H-1] `BUGHUNT_STOPPED_PGIDS` のスコープ設計が不足
- 判断: **対応する**
- 根拠: 指摘のとおり。グローバル配列だと shard ループを跨いで値が残り、
  前 shard の pgid で不要に dropdb を抑止したり、記録漏れで再確認が空振りしたりする。
- 対応内容: **グローバル変数を廃し bash の nameref で明示受け渡し**にした。
  `stop_shard_workers <shard> <out_pgids_array_name>` が `local -n` で受け、
  **呼び出しごとに `_out_pgids=()` で初期化**する。
  `cmd_teardown` の shard ループ内で `local -a stopped_pgids=()` を宣言し、
  `recheck_shard_workers_stopped stopped_pgids "shard-N"` へ渡す。設計にコード例を追加した。

## [Warning/H-1] fixture テスト計画と実装案が噛み合っていない
- 判断: **対応する**
- 根拠: 指摘のとおり。`group_member_counts` が `/proc/[0-9]*/stat` 固定では
  `comm` に空白や `)` を含む fixture を直接叩けず、テスト側でパースを複製すると
  **実装の検証にならない**（テストが自分のコピーを検証してしまう）。
- 対応内容: **`parse_proc_stat_line()` を独立関数として分離**し、
  1 行を渡して `"<state> <pgrp>"` を返す形にした。self-test (y7a) は**この関数自体**を
  fixture 文字列で叩く。`group_member_counts` はこの関数を呼ぶだけにする。

## [Warning/H-1] 関数名が揺れている
- 判断: **対応する**
- 対応内容: **`parse_proc_stat_line` / `group_member_counts` / `group_stopped` の 3 つに統一**し、
  概念設計の仮称 `group_live_members` は使わないと明記。「変更箇所」節の関数名も差し替えた。

## [Suggestion/H-2] self-test では `SHARD_RE` と `SHARD_DB_RE` の両方を再導出すべき
- 判断: **対応する**
- 対応内容: テスト計画に **(y8c)** を追加。同じテスト用 cap で `SHARD_RE` も再導出し、
  `0..cap` allow / `cap+1` deny を確認する（**shard 入力検証も同じ cap に従う**ことを固定）。

## [Warning/H-3] raw `dropdb` 検出の仕様が弱い
- 判断: **対応する**
- 根拠: 指摘のとおり。ファイル冒頭の説明コメントに `dropdb` の語が複数あり
  （L10 / L14 / L100 / L204 / L209 / L325 / L327 …）、素朴な grep は偽陽性になる。
  逆に `command dropdb` や変数経由は見落とす。
- 対応内容: 走査仕様を具体化した ——
  **非コメント行のみ**（`^\s*#` を除外）/ **コマンド位置に現れる `dropdb`** のみ
  （`(^|[;&|(]|\bcommand\s+)\s*dropdb\b`。`dropdb)` の case ラベルや文字列リテラルは対象外）/
  **許可位置は `pg_admin_for_provision()` 内の 1 行（現行 L333）だけ**。
  `createdb` も同じ規則で 1 箇所に固定し、対称にした。

## [Warning/H-3] allowlist は集合の増減しか検出しない（内部実装の変化は見えない）
- 判断: **対応する**
- 対応内容: リスク欄に**限界を明記**した ——
  「既存 allowlist コマンド（`filament:optimize-clear` / `icons:clear`）の内部実装が
  依存更新によって DB 接続を始めても赤くならない」。
  そのうえで allowlist の `rationale` を **package version 更新時に再確認する運用**とし、
  その旨をテストファイルのコメントにも書くことにした。

## [Warning/H-3] `env -i ... HOME="${HOME}"` は `set -u` 下で HOME 未定義だと落ちる
- 判断: **対応する**
- 対応内容: **`HOME="${HOME:-/tmp}"`** に変更し、理由をコメントに残した。

## [Critical/H-4] `cp` → `chmod 600` には world-readable の窓がある
- 判断: **対応する**
- 根拠: 指摘のとおり。親が `0644` で umask が緩いと、
  **cp 直後から chmod までの間だけ秘密ファイルが広く読める**。秘密複製の施策としては弱い。
- 対応内容: **`install -m 600 <src> <dst>`** に変更した（作成時点で mode が確定するので窓が無い）。
  受入条件 20 にも「`install -m 600` を使っていることを静的に確認する
  （`cp` + `chmod` の 2 段に退行していないこと）」を追加した。

## [Critical/H-4] `setup-worktree.sh` を source する契約テストは危険
- 判断: **対応する**
- 根拠: 指摘のとおり。現行は **top-level 実行型**（`main()` を持たず、
  引数検査 → worktree 作成 → composer install → pnpm install → DB 作成が直列）。
  素朴に `source` すると引数不足で exit 1 になるか、最悪 composer install まで走る。
  「重い副作用を避ける」という契約テストの目的と矛盾する。
- 対応内容: **source 専用 guard** を `set -euo pipefail` の直後に置く設計にした:
  `provision_runtime_files()` を先に定義 →
  `if [[ -n "${SETUP_WORKTREE_SOURCE_ONLY:-}" && "${BASH_SOURCE[0]}" != "$0" ]]; then return 0; fi`。
  実行時は env を立てないので通らない。
  `provision_runtime_files` は `REPO_ROOT` / `WORKTREE_DIR` を**引数で受け**、
  グローバル変数に依存させない。**呼び出し位置は現行のまま動かさない**。

## [Warning/H-4] 他の秘密ファイル（`.env` / oauth key）との権限契約の差が目立つ
- 判断: **対応する（差は残すが、限定を明示する）**
- 根拠: 妥当な指摘。ただし `.env` や oauth key の権限を今回まとめて変えるのは
  スコープ拡大（既存の運用に影響する）。
- 対応内容: 設計に「**今回 `0600` を固定する対象は `.env.bughunt.local` だけ**。
  既存の `.env` / `storage/oauth-*.key` の権限契約は変更しない（別施策）」と明記し、
  **関数のコメントにもこの限定を書く**ことにした。差が残ることを隠さない。


---

# 改訂後の詳細設計書

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

use Illuminate\Support\Facades\Process;

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

    // sandbox の置き場をテスト側が握る。self-test は自分で mktemp -d して
    // BUGHUNT_SANDBOX を立てるが、その置き場は TMPDIR に従う。
    // 万一 sandbox 化が退行しても、実 tmp/ ・devnotes/ を汚す前にここで気づける。
    $tmp = sys_get_temp_dir().'/bughunt-selftest-pest-'.bin2hex(random_bytes(6));
    mkdir($tmp, 0700, true);

    try {
        // executable bit に依存せず bash 経由で起動する。
        // timeout は実測 ~4 秒に対し 120 秒 (CI の遅さを吸収しつつ無限待ちにしない)。
        $process = Process::timeout(120)
            ->env(['TMPDIR' => $tmp])
            ->run(['bash', $script, 'self-test']);

        expect($process->exitCode())->toBe(
            0,
            "self-test が失敗した:\n".$process->output()."\n".$process->errorOutput(),
        );
    } finally {
        // sandbox の後始末は self-test 自身が行うが、途中で落ちた場合の残骸を回収する
        exec('rm -rf '.escapeshellarg($tmp));
    }
});

test('self-test が自前 sandbox を立てる構造であること', function (): void {
    // 上のテストは「通ること」しか見ない。sandbox 化そのものが外れる退行を静的にも押さえる。
    $source = file_get_contents(base_path('scripts/bug-hunt-shard.sh'));

    expect($source)->toContain('BUGHUNT_SANDBOX');
    expect($source)->toMatch('/sandbox="\$\(mktemp -d/');
});
```

> `Illuminate\Support\Facades\Process` を使う（`shell_exec` の直書きをしない）。
> **`bash $script` で起動**し、executable bit への依存を持たない。

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
  - 新規 helper `parse_proc_stat_line()` / `group_member_counts()` / `group_stopped()`
  - 新規 helper `recheck_shard_workers_stopped()`（dropdb 直前の 2 回目判定）
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
# --- 1 行パース (fixture でテストできるよう独立させる) ---
# 入力: /proc/<pid>/stat の 1 行 / 出力: "<state> <pgrp>" (解釈できなければ非 0)
# ★ テストが同じパースを複製すると実装の検証にならないため、**この関数自体**を fixture で叩く。
parse_proc_stat_line() {
    local line=$1 rest
    rest="${line##*') '}"                          # comm の**最後**の閉じ括弧より後ろ
    [[ "${rest}" != "${line}" ]] || return 1       # ') ' が無い = 想定外の書式
    local -a f
    read -r -a f <<< "${rest}"
    [[ ${#f[@]} -ge 3 ]] || return 1
    echo "${f[0]} ${f[2]}"                         # state / pgrp (ppid は f[1])
    return 0
}

# --- process group のメンバー内訳 (zombie を分離し、解釈不能を unknown に立てる) ---
# kill -0 -- -PGID は「シグナルを送れるか」であって「動いているか」ではない。
# zombie (state=Z) は終了済みで DB 接続も資源も持たないのに「生存」と数えられ、
# PID 1 が zombie を刈らない環境では dropdb が永久に抑止される。
#
# ★ 見たいのは「DB 接続を保持しうるプロセスが残っているか」なので判定対象を procfs にする。
# ★ **解釈できなかった行は unknown に数える** (0 件へ倒さない)。dropdb 直前判定では
#   「確認不能」は DB を消さない側に倒す = fail-closed。
# ★ 走査中に消えた pid (open 失敗) は race として無視してよい (消えたのだから残留ではない)。
#   ただし「読めたが解釈できない」は unknown であり、無視しない。
#
# 出力: "<live> <zombie> <unknown>"
group_member_counts() {
    local pgid=$1 live=0 zomb=0 unknown=0 statfile line parsed state pgrp
    for statfile in /proc/[0-9]*/stat; do
        line="$(cat "${statfile}" 2>/dev/null)" || continue   # race: 消えた pid
        [[ -n "${line}" ]] || continue
        if ! parsed="$(parse_proc_stat_line "${line}")"; then
            unknown=$((unknown + 1))                          # 読めたが解釈不能 = fail-closed 側
            continue
        fi
        state="${parsed%% *}"; pgrp="${parsed##* }"
        [[ "${pgrp}" == "${pgid}" ]] || continue
        if [[ "${state}" == "Z" ]]; then
            zomb=$((zomb + 1))
        else
            live=$((live + 1))
        fi
    done
    echo "${live} ${zomb} ${unknown}"
}

# group が停止したか。停止していれば 0。
# ★ fail-closed の 3 条件: (a) live>0 なら失敗、(b) unknown>0 なら失敗 (確認不能)、
#   (c) **kill -0 -- -PGID が成功しているのに procfs で 1 件も確証できない**なら失敗
#   (procfs が読めていない可能性があり、「0 件だから成功」と倒すと fail-open になる)。
# zombie のみだった場合は stderr に理由を出す (無言で pidfile を消さない)。
group_stopped() {
    local pgid=$1 label=$2 counts live zomb unknown
    counts="$(group_member_counts "${pgid}")"
    read -r live zomb unknown <<< "${counts}"

    if [[ "${live}" != 0 ]]; then
        return 1
    fi
    if [[ "${unknown}" != 0 ]]; then
        echo "error: ${label} の判定で /proc の解釈不能行が ${unknown} 件 — 確認不能として停止失敗に倒す" >&2
        return 1
    fi
    if [[ "${zomb}" == 0 ]] && kill -0 -- "-${pgid}" 2>/dev/null; then
        echo "error: ${label} は kill -0 が成功するのに procfs でメンバーを 1 件も確認できない — 確認不能として停止失敗に倒す" >&2
        return 1
    fi
    if [[ "${zomb}" != 0 ]]; then
        echo "note: ${label} は zombie ${zomb} 件を残して停止 (PID 1 が刈らない環境。DB 接続は保持しない)" >&2
    fi
    return 0
}
```

> **関数名は `parse_proc_stat_line` / `group_member_counts` / `group_stopped` の 3 つに統一する。**
> 概念設計にあった `group_live_members` という仮称は使わない（受入条件の記述も本設計の名前に揃える）。

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
            if ! recheck_shard_workers_stopped stopped_pgids "shard-${shard}"; then
                teardown_rc=1
                echo "warning: shard-${shard} の worker が dropdb 直前の再確認で残留 — dropdb をスキップ" >&2
            else
                pg_admin_for_provision dropdb "$(shard_db "${shard}")"   # ← DB名 guard + admin role は不変
            fi
        fi
```

`recheck_shard_workers_stopped()` は 1 回目に確認した pgid 群を `group_stopped` で再評価する。

**pgid の受け渡しはグローバル変数にしない。** shard ループを跨いで値が残ると、
前 shard の pgid で不要に dropdb を抑止したり、記録漏れで再確認が空振りしたりする。
bash の **nameref** で明示的に受け渡す:

```bash
# stop_shard_workers <shard> <out_pgids_array_name>
stop_shard_workers() {
    local shard=$1
    local -n _out_pgids=$2      # 呼び出し側の配列へ直接積む
    _out_pgids=()               # ★ 呼び出しごとに必ず初期化する
    ...
        _out_pgids+=("${wpid}")   # 停止確認できた group の pgid を記録
    ...
}

# cmd_teardown の shard ループ内
local -a stopped_pgids=()       # ★ shard ごとに新しい配列
if ! stop_shard_workers "${shard}" stopped_pgids; then
    ...
fi
```

`recheck_shard_workers_stopped()` も同じ配列を nameref で受け取り、
**全 pgid が `group_stopped` を満たすときだけ 0** を返す。

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
- [ ] **(y8c)**: 同じテスト用 cap で **`SHARD_RE`（shard 入力検証）も再導出**し、
      `0..cap` allow / `cap+1` deny になることを確認する。DB 名だけでなく
      **shard 入力の検証も同じ cap に従う**ことを固定する（Codex R1 の Suggestion）

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
# ★ set -u 下で HOME 未定義だと展開で落ちるため既定値を置く (既存の env -i 行と同じ配慮)。
env -i PATH="${PATH}" HOME="${HOME:-/tmp}" php artisan optimize:clear --except=cache > /dev/null
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
      `scripts/bug-hunt-shard.sh` の **raw `dropdb` 実行が 1 箇所だけ**であることを固定する。
      走査仕様を曖昧にしない:
  - **対象は非コメント行のみ**（`^\s*#` を除外する。ファイル冒頭の説明コメントに
    `dropdb` の語が複数あるため、素朴な grep は偽陽性になる）
  - **`dropdb` がコマンド位置に現れる行**を検出する。
    `dropdb)` のような case ラベルや `"...dropdb..."` の文字列は対象外。
    具体的には `(^|[;&|(]|\bcommand\s+)\s*dropdb\b` に一致する行を数える
  - **許可位置は `pg_admin_for_provision()` 内の `op_cmd=(dropdb --if-exists "${db}")` の
    1 行だけ**（現行 L333）。ここ以外に出たら赤くする
  - `createdb` も同じ規則で 1 箇所（L332）に固定する（対称にしておく）

### リスク

- `env -i` により `PATH` / `HOME` 以外が落ちる。`php` の解決は `PATH` で足りる
  （既存の `artisan_for_shard` も同じ形）。
- `--except=cache` を将来誰かが「不要」と判断して消すと再発する。**コメントで理由を残し**、
  self-test (y9a) が消滅を検出する。
- **inventory の限界（誇張しない）**: 受入条件 17 が検出するのは
  **`$optimizeClearCommands` の集合の増減だけ**である。
  **既存 allowlist コマンド（`filament:optimize-clear` / `icons:clear`）の内部実装が
  依存更新によって DB 接続を始めても赤くならない。**
  そのため allowlist の `rationale` は「**package version 更新時に再確認する**」運用とし、
  その旨をテストファイルのコメントにも書く。

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
    # ★ cp → chmod の 2 段にしない。親が 0644 で umask が緩いと、
    #   cp 直後から chmod までの間だけ **world-readable な秘密ファイルが存在する窓**ができる。
    #   install -m 600 は作成時点で mode を確定するのでこの窓が無い。
    install -m 600 "${REPO_ROOT}/.env.bughunt.local" "${WORKTREE_DIR}/.env.bughunt.local"
fi
```

> **今回 `0600` を固定する対象は `.env.bughunt.local` だけ**である。
> 既存の `.env` / `storage/oauth-*.key` の権限契約は**変更しない**（別施策）。
> 権限の扱いに差が残るのは承知のうえで、本テーマのスコープ（bug-hunt 基盤の既知不具合 4 件）を
> 守るためにここで線を引く。関数のコメントにこの限定を明記する。

コピー処理は既存の `.env` / `oauth-*.key` と同じ節に置き、
**関数 `provision_runtime_files()` として切り出す**（契約テストが
`composer install` / `pnpm install` / DB 作成を走らせずに検証できるようにするため）。

**ただし `setup-worktree.sh` は top-level 実行型**（`main()` を持たず、引数検査から
DB 作成まで手続きが直列に並ぶ）である。素朴に `source` すると
**引数不足で exit 1 になるか、最悪 composer install まで走る**。
そこで **source 専用 guard** を `set -euo pipefail` の直後に置く:

```bash
set -euo pipefail

# 実行時ファイルのプロビジョニング (契約テストから source して単体で叩けるよう関数化)。
provision_runtime_files() {
    local repo_root=$1 worktree_dir=$2
    ...
}

# ★ source 専用モード: 関数定義だけ取り込んで抜ける (契約テスト用)。
#   実行時 (bash setup-worktree.sh) には環境変数を立てないので通らない。
if [[ -n "${SETUP_WORKTREE_SOURCE_ONLY:-}" && "${BASH_SOURCE[0]}" != "$0" ]]; then
    return 0
fi

# ここから下は従来どおりの手続き (引数検査 → worktree 作成 → ...)
```

`provision_runtime_files` は `REPO_ROOT` / `WORKTREE_DIR` を**引数で受ける**
（グローバル変数に依存させない）。本体からは
`provision_runtime_files "${REPO_ROOT}" "${WORKTREE_DIR}"` と呼ぶだけにし、
**呼び出し位置は現行のまま動かさない**。

### テスト計画

- [ ] 新規 `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php`
  - **受入条件 18**: 一時ディレクトリを親/worktree に見立てて `provision_runtime_files` を
    呼び、親に `.env.bughunt.local` があればコピーされる
  - **受入条件 19**: 親に無ければコピー先も作られない（no-op）
  - **受入条件 20**: **親を `0644` にしてもコピー先が `0600`** になる
    （`cp -p` / `cp` + `chmod` に退行していないことの検出）。既存ファイルへの上書きケースも同様。
    あわせて **`install -m 600` を使っている**ことを静的にも確認する
    （`cp` + `chmod` の 2 段は world-readable の窓を作るため）
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


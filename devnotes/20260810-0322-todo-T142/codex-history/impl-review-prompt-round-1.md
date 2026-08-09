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

あなたはコードレビュアーです。**bash ベースの開発基盤**の実装をレビューしてください。

【レビュー観点】
1. 設計との一致性 (詳細設計どおりか。乖離があれば指摘)
2. bash の正確性 (nameref / subshell / set -u / quoting / procfs パース / race)
3. テスト網羅性 (受入条件 24 件が実際に固定されているか。すり抜けは無いか)
4. 副作用・後退リスク (**特に dev DB 防御を緩めていないか**)
5. セキュリティ (秘密ファイルの複製・権限・source guard)
6. **保証範囲の誇張が無いか** (「証明」と「検出」の混同)

【出力形式】
- ファイルごとに判定: APPROVE / REQUEST_CHANGES
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
- `scripts/bug-hunt-shard.sh` `cmd_self_test()`: **`BUGHUNT_SANDBOX` が外から与えられていれば
  それを使い、未指定のときだけ `mktemp -d` する**契約に変更する
  （現行 L1225 は無条件で `mktemp -d` して上書きしている）。これによりテストが隔離境界を握れる。

  **所有権と後始末の契約を明示的に持つ**（外部指定パスを self-test が消さないため）:

  ```bash
  local sandbox sandbox_owned=0
  if [[ -n "${BUGHUNT_SANDBOX:-}" ]]; then
      sandbox="${BUGHUNT_SANDBOX}"
      # ★ 「既存の絶対ディレクトリ」だけでは境界にならない。/ や WORKSPACE や
      #   リポジトリルートを渡されると RUN_BASE / TMP_BASE がそこへ向き、
      #   self-test が実資源を上書きしうる (削除しなくても書き込みで壊せる)。
      #   そこで **専用マーカーファイル**を要求する = 呼び出し側が「捨ててよい空き地」を
      #   意図的に用意したときだけ受け付ける。
      [[ "${sandbox}" == /* && "${sandbox}" != / ]] \
          || die 2 "BUGHUNT_SANDBOX は / 以外の絶対パスであること: '${sandbox}'"
      [[ -d "${sandbox}" ]] \
          || die 2 "BUGHUNT_SANDBOX が存在しない: '${sandbox}'"
      # 表記差 (末尾 /. や symlink) を吸収するため realpath で正規化してから比較する
      local _sb_real _ws_real
      _sb_real="$(cd "${sandbox}" && pwd -P)"
      _ws_real="$(cd "${WORKSPACE}" && pwd -P)"
      [[ "${_sb_real}" != "${_ws_real}" ]] \
          || die 2 "BUGHUNT_SANDBOX にリポジトリルートは指定できない"
      [[ -f "${sandbox}/.bughunt-selftest-sandbox" ]] \
          || die 2 "BUGHUNT_SANDBOX に専用マーカー .bughunt-selftest-sandbox が無い (捨ててよい空き地だけを受け付ける)"
      sandbox_owned=0        # ★ 借り物。self-test は絶対に削除しない
  else
      sandbox="$(mktemp -d -t bug-hunt-selftest.XXXXXX)"
      sandbox_owned=1        # 自分で作ったものだけが削除対象になりうる
  fi
  ```

  Pest 側は **空の一時ディレクトリを作り、`0600` のマーカーを置いてから**起動する。
  2 本のテストで重複するのでテストファイル内の helper にまとめる:

  ```php
  /** self-test へ渡す「捨ててよい空き地」を作る (マーカー必須)。 */
  function makeSelfTestSandbox(): string
  {
      $dir = sys_get_temp_dir().'/bughunt-selftest-pest-'.bin2hex(random_bytes(6));
      File::makeDirectory($dir, 0700, true);
      File::put($dir.'/.bughunt-selftest-sandbox', '');
      chmod($dir.'/.bughunt-selftest-sandbox', 0600);

      return $dir;
  }
  ```

  > **後始末の契約（コメントとテストで固定する）**:
  > - **外部指定 (`sandbox_owned=0`) は削除しない** — 借り物だから
  > - **内部生成 (`sandbox_owned=1`) は従来どおり削除する**
  >
  > **【実装時の訂正】** 設計段階では「現行 self-test は sandbox を削除していない（trap も無い）」と
  > 書いたが、これは調査漏れだった。`trap` は無いが **末尾に `rm -rf "${sandbox}"` がある**
  > （現行 L2005）。したがって `cleanup` を新設するのではなく、
  > **既存の `rm -rf` を `sandbox_owned == 1` で囲む**のが正しい実装になる。
  > この誤りは新規テスト「外から与えた BUGHUNT_SANDBOX を尊重し削除しないこと」が
  > 実装 1 周目で赤くなって判明した（fail-first が機能した例）。

### 実装

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
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

    // ★ 隔離境界はテスト側が握る。TMPDIR だけでは、self-test が mktemp を使わなくなり
    //   tmp/ や devnotes/ を直接参照する退行に効かない。BUGHUNT_SANDBOX を明示して渡す。
    //   self-test 側の契約: 「BUGHUNT_SANDBOX 未指定なら自分で mktemp -d、
    //   指定済みならその sandbox を使う」。
    $tmp = makeSelfTestSandbox();   // ★ マーカー付き。無いと self-test が die 2 で落ちる

    try {
        // executable bit に依存せず bash 経由で起動する。
        // timeout は実測 ~4 秒に対し 120 秒 (CI の遅さを吸収しつつ無限待ちにしない)。
        $process = Process::timeout(120)
            ->env(['BUGHUNT_SANDBOX' => $tmp, 'TMPDIR' => $tmp])
            ->run(['bash', $script, 'self-test']);

        expect($process->exitCode())->toBe(
            0,
            "self-test が失敗した:\n".$process->output()."\n".$process->errorOutput(),
        );
    } finally {
        File::deleteDirectory($tmp);
    }
});

test('self-test が外から与えた BUGHUNT_SANDBOX を尊重すること', function (): void {
    // 「通ること」だけでは隔離境界の退行を検出できない。外から渡した sandbox が
    // 実際に使われている (= その配下に成果物が作られる) ことを見る。
    $tmp = makeSelfTestSandbox();

    try {
        $process = Process::timeout(120)
            ->env(['BUGHUNT_SANDBOX' => $tmp, 'TMPDIR' => $tmp])
            ->run(['bash', base_path('scripts/bug-hunt-shard.sh'), 'self-test']);

        expect($process->exitCode())->toBe(0);
        // self-test は sandbox 配下に devnotes / tmp/bug-hunt を作る
        expect(File::isDirectory($tmp.'/tmp/bug-hunt'))->toBeTrue(
            '外から与えた BUGHUNT_SANDBOX が使われていない (隔離境界をテストが握れていない)',
        );
    } finally {
        File::deleteDirectory($tmp);
    }
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
# 1 回分のスキャンで「生きているメンバーが 0 か」を判定する内部関数。
# 出力は zombie 件数 (呼び出し側の note 用)。非 0 終了 = 停止していない。
group_scan_once() {
    local pgid=$1 label=$2 counts live zomb unknown
    counts="$(group_member_counts "${pgid}")"
    read -r live zomb unknown <<< "${counts}"

    # ★ 3 値が非負整数であることを検査する。壊れた出力を「0 件」と読んで
    #   fail-open するのを防ぐ (関数が将来壊れたときの安全弁)。
    local v
    for v in "${live}" "${zomb}" "${unknown}"; do
        [[ "${v}" =~ ^[0-9]+$ ]] || {
            echo "error: ${label} の member 集計が不正 ('${counts}') — 確認不能として停止失敗に倒す" >&2
            return 1
        }
    done

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
    echo "${zomb}"
    return 0
}

# group が停止したか。
# ★ **連続 2 回のスキャンがともに live=0** のときだけ成功とする。
#   1 回スキャンだと「zombie を観測した直後に同 PGID へ live member が現れる」窓が残り、
#   zomb != 0 の経路が kill -0 の補完条件を素通りしてしまう (Codex R2 指摘)。
#   これは TOCTOU を**証明**するものではなく**窓を縮小する検出**である。誇張しない。
group_stopped() {
    local pgid=$1 label=$2 zomb1 zomb2
    zomb1="$(group_scan_once "${pgid}" "${label}")" || return 1
    sleep 0.1
    zomb2="$(group_scan_once "${pgid}" "${label}")" || return 1
    if [[ "${zomb2}" != 0 ]]; then
        echo "note: ${label} は zombie ${zomb2} 件を残して停止 (PID 1 が刈らない環境。DB 接続は保持しない)" >&2
    fi
    return 0
}
```

> **`unknown` の可用性トレードオフ（明記する）**: `parse_proc_stat_line` が失敗した行は
> **pgrp を特定できない**ため、対象 PGID と無関係かもしれない行も `unknown` に数える。
> 安全側としては正しいが、**ホスト上の無関係な 1 プロセスの異常で全 shard の teardown が止まる**。
> これは安全性ではなく**可用性**の代償であり、意図的にそう倒している。
> コメントとリスク欄に書き、テストでもこの契約を固定する。

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

- [ ] **(y7a) 受入条件 5**: **`parse_proc_stat_line` を fixture 文字列で直接叩く**。
      `comm` に空白と `)` を含む行（例 `123 (my ) proc) Z 1 456 ...`）で
      `state=Z` / `pgrp=456` を正しく読む。`') '` を含まない行では非 0 を返す
- [ ] **(y7b) 受入条件 2**: メンバー 0 件 → `group_stopped` が 0 を返し、**note を出さない**
- [ ] **(y7c) 受入条件 1・8**: 全て zombie → 0 を返し、**stderr に note が出る**
- [ ] **(y7d) 受入条件 3**: 非 zombie が 1 件 → 非 0 を返す（`setsid sleep` の実プロセス）
- [ ] **(y7e) 受入条件 4**: zombie + 非 zombie 混在 → 非 0 を返す
- [ ] **(y7f) 受入条件 6**: 走査中に消える pid（存在しない `/proc/<pid>/stat`）を残留と数えない
- [ ] **(y7g) 受入条件 7**: 「1 回目ゼロ・2 回目に非 zombie 出現」→ **失敗**になる（必須ケース）。
      `group_stopped` を stub し、1 回目 0 / 2 回目 1 を返させて `recheck_...` が非 0 になることを見る
- [ ] **(y7h) 受入条件 9**: `group_member_counts` が非 zombie を返す状況で
      **`pg_admin_for_provision` が一度も呼ばれない**（stub して呼び出し回数 0 を assert）
- [ ] **(y7i) 受入条件 10**: 停止失敗時に pidfile が保持され teardown が失敗扱い（既存 (y6b) の延長）
- [ ] **(y7j) 受入条件 11**: dropdb 候補へ進んだ場合も `guard_shard_db_name` と
      admin role 明示を通る（`pg_admin_for_provision` の呼び出し引数を検査）

- [ ] **(y7k) 受入条件 21（新設）**: `group_member_counts` を stub して
      `unknown > 0` を返させ、`group_stopped` が**失敗**することを固定する
- [ ] **(y7l) 受入条件 22（新設）**: `group_member_counts` を stub して `0 0 0` を返させ、
      かつ `kill` を stub して `kill -0 -- -PGID` を成功させ、
      `group_stopped` が**失敗**することを固定する（fail-open の穴が塞がっている証拠）
- [ ] **(y7m) 受入条件 23（新設）**: `group_member_counts` が不正な出力（空文字 / 非数値）を
      返した場合も `group_stopped` が**失敗**することを固定する
- [ ] **(y7n) 受入条件 24（新設）**: 「1 回目 live=0 / 2 回目 live=1」で `group_stopped` が失敗する
      （連続 2 回スキャンが効いていることの直接固定。受入条件 7 の group 内版）

> **これらが無いと、今回の中核修正（fail-closed 3 条件 + 2 連続スキャン）を削除しても
> 既存の受入条件がすべて緑になりうる**（Codex R2 の指摘）。中核修正には必ず専用ケースを置く。

受入条件 12（raw `dropdb` 新設なし）は **Architecture テスト**側で見る（下記 H-3 と同じファイルに置く）。

### リスク

- **procfs 走査のコスト**: `/proc/[0-9]*/stat` の全走査は数百プロセス規模で数 ms。
  teardown で shard 数 × worker 数 × 数回なので実用上問題ない。
- **`unknown` による可用性の代償**: 上記のとおり、無関係なプロセスの `/proc` 行が
  解釈できないだけで teardown 全体が止まる。安全側に倒した結果であり、
  「止まったら procfs 側を疑う」ことを error メッセージで示す。
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
      `scripts/bug-hunt-shard.sh` に **literal な `dropdb` / `createdb` の直接呼び出しが
      許可位置以外に無い**ことを固定する。

  **保証範囲を先に限定する（誇張しない）**: これは **literal な直接呼び出しの検出**であって、
  変数展開 (`$cmd`)・関数経由・`env` 経由・`eval` まで含めた「呼び出しが無いこと」の**証明ではない**。
  そこまで見るには bash の AST 相当の解析が要る。ここでは
  「うっかり `dropdb` と書いた行が増えていないか」を保守的に検出する。

  走査方式（正規表現でコマンド位置を当てにいかない。`if dropdb` / `while dropdb` /
  `then dropdb` / `! dropdb` / `exec dropdb` / `env X=1 dropdb` を取りこぼすため）:

  1. **行頭コメント（`^\s*#`）を除外**した行のうち、
     単語境界の `dropdb` / `createdb` を含む行をすべて集める
  2. その集合が **理由付き inventory と完全一致**することを assert する（deny-by-default）
  3. inventory は 2 種類に役割を分ける。**識別キーは一意**とし、抽出結果・inventory の
     どちらにも重複キーがあれば赤にする。件数も `必須 2 件 + 非実行 13 件 = 15 件`で突き合わせる。

     **(A) 必須実行行（各ちょうど 1 行存在すること）** — 2 件

     | # | 識別キー | 役割 |
     |---|---|---|
     | A1 | `op_cmd=(createdb -O bughunt` | admin 経路の createdb 実体（現行 L332） |
     | A2 | `op_cmd=(dropdb --if-exists` | admin 経路の dropdb 実体（現行 L333） |

     **(B) 存在してよい行（wrapper 呼び出し・メッセージ・inline コメント・self-test）** — 13 件

     | # | 識別キー | 理由 | 現行行 |
     |---|---|---|---|
     | B1 | `die 1 "guard_admin_provision: BUGHUNT_ADMIN_USER` | admin role 未設定時のエラーメッセージ | L209 |
     | B2 | `local op=$1 db=$2` | inline コメント `# op ∈ {createdb, dropdb}` | L327 |
     | B3 | `pg_admin_for_provision createdb "${db}"` | **wrapper 経由**の呼び出し（raw ではない） | L909 |
     | B4 | `echo "warning: shard-${shard} の worker 停止に失敗` | dropdb スキップの警告文 | L1161 |
     | B5 | `pg_admin_for_provision dropdb "$(shard_db` | **wrapper 経由**の呼び出し（raw ではない） | L1185 |
     | B6 | `echo "[f] createdb 実行コマンドに OWNER bughunt` | self-test の見出し | L1314 |
     | B7 | `local createdb_cmd` | self-test の局所変数宣言 | L1315 |
     | B8 | `createdb_cmd="$(declare -f pg_admin_for_provision)"` | self-test の検査対象取得 | L1316 |
     | B9 | `grep -q 'createdb -O bughunt'` | self-test の検査条件 | L1317 |
     | B10 | `t_fail "createdb に OWNER bughunt` | self-test の失敗メッセージ | L1318 |
     | B11 | `t_ok "createdb OWNER bughunt"` | self-test の成功ログ | L1319 |
     | B12 | `t_fail "stop_shard_workers に process group 消滅待ちが無い` | self-test の失敗メッセージ（`dropdb と race` を含む） | L1741 |
     | B13 | `t_fail "cmd_teardown に worker 停止失敗時の dropdb 抑止が無い` | self-test の失敗メッセージ | L1753 |

     > **この 15 件は「実装前」のベースラインである。** 本 TODO の実装で
     > `recheck_shard_workers_stopped` の警告文など **`dropdb` の語を含む行が増える**見込みなので、
     > **inventory は実装完了時点の実ファイルで確定させる**（増えた行を理由付きで B に足す）。
     > 実装前の 15 件をそのまま書いて赤にしない。

  4. **新しい行が増えたら赤**になる。正当なら理由付きで inventory に 1 行足す運用にする

  > **なぜ「文字列を除外する」方式を採らないか**: bash の字句解析なしに
  > 「文字列リテラル中の `dropdb`」を正しく除外することはできない。
  > 除外を試みると、逆に**実行行を見落とす**穴を作る。
  > そこで **「literal が現れる行を全部数え、既知の行と完全一致するか」**という
  > 保守的な方式にする（inline コメントもメッセージも inventory に載せる）。
  > 冗長だが、見落としが構造的に起きない。

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
      **契約テスト**の位置づけ。bash 関数は**位置引数で渡して**呼び、
      文字列連結による shell injection を避ける:
      ```
      SETUP_WORKTREE_SOURCE_ONLY=1 bash -c 'source "$1"; provision_runtime_files "$2" "$3"' \
        _ "<script>" "<parent-dir>" "<worktree-dir>"
      ```

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
7. **実機確認は「エージェントが自動で実 DB を消す」形にしない**（禁止事項 3）。
   wrapper・DB 名 guard・admin role を通っていても、
   **「dev DB への破壊操作をエージェント判断で実行しない」という上位制約は解除されない**。
   エージェントが自動で行う検証は **self-test と非破壊の dry-run まで**に限定する:

   ```bash
   # エージェントが実行してよい範囲 (実資源に触れない / 破壊しない)
   scripts/bug-hunt-shard.sh self-test
   BUGHUNT_SELFTEST_DRYRUN=1 BUGHUNT_ORCHESTRATOR=1 \
     scripts/bug-hunt-shard.sh provision --shard 0 --run-id 20990301-000000
   ```

   **実 DB を伴う end-to-end 確認（`provision-all --parallel=2` → `teardown --drop-db`）は
   ユーザーの明示承認後、またはユーザー自身が実施する**。
   実装レポートには「self-test と dry-run までで確認した / 実機 end-to-end は未実施」と
   **正直に書く**（「実物で確認した」と書かない）。

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
| 9 | `group_member_counts` が非 zombie を返す時に dropdb wrapper が呼ばれない | self-test (y7h) |
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
| 20 | コピー先 mode が `0600`（親 `0644` でも）+ `install -m 600` を使用 | 契約テスト |
| **21** | **`unknown > 0` なら `group_stopped` が失敗** | self-test (y7k) |
| **22** | **`0 0 0` かつ `kill -0` 成功なら失敗**（fail-open の穴が塞がっている証拠） | self-test (y7l) |
| **23** | **`group_member_counts` の出力が不正なら失敗** | self-test (y7m) |
| **24** | **「1 回目 live=0 / 2 回目 live=1」で失敗**（連続 2 回スキャンの直接固定） | self-test (y7n) |
| — | **上記 self-test 群が自動実行される** | Architecture (H-0) |
| — | 外から与えた `BUGHUNT_SANDBOX` を self-test が尊重し、**削除しない** | Architecture (H-0) |

> 受入条件は概念設計の 20 件に、design-review R2 で必要と判明した
> **中核修正の直接固定 4 件 (21〜24)** を足して **24 件**になった。
> これらが無いと、fail-closed 3 条件と 2 連続スキャンを削除しても既存条件が全て緑になりうる。


---

## 実装差分 (git diff)

```diff
diff --git a/scripts/bug-hunt-shard.sh b/scripts/bug-hunt-shard.sh
index 02bec3d..871a723 100755
--- a/scripts/bug-hunt-shard.sh
+++ b/scripts/bug-hunt-shard.sh
@@ -801,8 +801,111 @@ start_shard_workers() {
 # ★ 消滅を確認できた group のみ pidfile を削除する。残留/検証不能の group の pidfile は保持し
 #   (追跡可能性を失わない)、戻り値 1 で失敗を通知する (呼び出し側が dropdb を抑止する)。
 # (Codex 詳細 R1/R2/R3/R4 反映)
+# --- /proc/<pid>/stat の 1 行パース (fixture でテストできるよう独立させる) ------
+# 入力: stat の 1 行 / 出力: "<state> <pgrp>" (解釈できなければ非 0)
+# ★ comm は括弧で囲まれ **プロセス名に空白や ')' を含みうる**ため、先頭からの位置決め
+#   (awk '{print $3}' 等) は state を誤読する。**最後の ') ' より後ろ**を分割すれば
+#   state=1 / ppid=2 / pgrp=3 が確定する。
+parse_proc_stat_line() {
+    local line=$1 rest
+    rest="${line##*') '}"                          # comm の**最後**の閉じ括弧より後ろ
+    [[ "${rest}" != "${line}" ]] || return 1       # ') ' が無い = 想定外の書式
+    local -a f
+    read -r -a f <<< "${rest}"
+    [[ ${#f[@]} -ge 3 ]] || return 1
+    echo "${f[0]} ${f[2]}"                         # state / pgrp (ppid は f[1])
+    return 0
+}
+
+# --- process group のメンバー内訳 (zombie を分離し、解釈不能を unknown に立てる) ---
+# kill -0 -- -PGID は「シグナルを送れるか」であって「動いているか」ではない。
+# zombie (state=Z) は終了済みで DB 接続も資源も持たないのに「生存」と数えられ、
+# PID 1 が zombie を刈らない環境 (本 devcontainer の PID 1 は sleep infinity) では
+# queue:work --once の終了済み子が group に残り続け dropdb が永久に抑止される。
+#
+# ★ 見たいのは「DB 接続を保持しうるプロセスが残っているか」なので判定対象を procfs にする。
+# ★ **解釈できなかった行は unknown に数える** (0 件へ倒さない)。dropdb 直前判定では
+#   「確認不能」は DB を消さない側に倒す = fail-closed。
+#   代償として、対象 PGID と無関係な行の異常でも teardown が止まる (可用性のトレードオフ)。
+# ★ 走査中に消えた pid (open 失敗) は race として無視してよい (消えたのだから残留ではない)。
+#   ただし「読めたが解釈できない」は unknown であり、無視しない。
+#
+# 出力: "<live> <zombie> <unknown>"
+group_member_counts() {
+    local pgid=$1 live=0 zomb=0 unknown=0 statfile line parsed state pgrp
+    for statfile in /proc/[0-9]*/stat; do
+        line="$(cat "${statfile}" 2>/dev/null)" || continue   # race: 消えた pid
+        [[ -n "${line}" ]] || continue
+        if ! parsed="$(parse_proc_stat_line "${line}")"; then
+            unknown=$((unknown + 1))                          # 読めたが解釈不能 = fail-closed 側
+            continue
+        fi
+        state="${parsed%% *}"; pgrp="${parsed##* }"
+        [[ "${pgrp}" == "${pgid}" ]] || continue
+        if [[ "${state}" == "Z" ]]; then
+            zomb=$((zomb + 1))
+        else
+            live=$((live + 1))
+        fi
+    done
+    echo "${live} ${zomb} ${unknown}"
+}
+
+# 1 回分の走査で「生きているメンバーが 0 か」を判定する。stdout に zombie 件数を出す。
+# fail-closed の 3 条件: (a) live>0、(b) unknown>0 (確認不能)、
+# (c) kill -0 -- -PGID が成功しているのに procfs で 1 件も確証できない (procfs が読めていない
+# 可能性を成功へ倒さない)。加えて集計値が非負整数でないときも失敗へ倒す。
+group_scan_once() {
+    local pgid=$1 label=$2 counts live zomb unknown v
+    counts="$(group_member_counts "${pgid}")"
+    read -r live zomb unknown <<< "${counts}"
+
+    for v in "${live:-}" "${zomb:-}" "${unknown:-}"; do
+        [[ "${v}" =~ ^[0-9]+$ ]] || {
+            echo "error: ${label} の member 集計が不正 ('${counts}') — 確認不能として停止失敗に倒す" >&2
+            return 1
+        }
+    done
+
+    if [[ "${live}" != 0 ]]; then
+        return 1
+    fi
+    if [[ "${unknown}" != 0 ]]; then
+        echo "error: ${label} の判定で /proc の解釈不能行が ${unknown} 件 — 確認不能として停止失敗に倒す" >&2
+        return 1
+    fi
+    if [[ "${zomb}" == 0 ]] && kill -0 -- "-${pgid}" 2>/dev/null; then
+        echo "error: ${label} は kill -0 が成功するのに procfs でメンバーを 1 件も確認できない — 確認不能として停止失敗に倒す" >&2
+        return 1
+    fi
+    echo "${zomb}"
+    return 0
+}
+
+# group が停止したか。**連続 2 回の走査がともに live=0** のときだけ成功とする。
+# 1 回走査だと「zombie を観測した直後に同 PGID へ live member が現れる」窓が残る。
+# ★ これは TOCTOU を**証明**するものではなく**窓を縮小する検出**である (誇張しない)。
+group_stopped() {
+    local pgid=$1 label=$2 zomb1 zomb2
+    zomb1="$(group_scan_once "${pgid}" "${label}")" || return 1
+    sleep 0.1
+    zomb2="$(group_scan_once "${pgid}" "${label}")" || return 1
+    if [[ "${zomb2}" != 0 ]]; then
+        echo "note: ${label} は zombie ${zomb2} 件を残して停止 (PID 1 が刈らない環境。DB 接続は保持しない)" >&2
+    fi
+    return 0
+}
+
+# stop_shard_workers <shard> [out_pgids_array_name]
+# ★ 停止確認できた group の pgid を **nameref で呼び出し側の配列へ積む** (グローバルにしない)。
+#   shard ループを跨いで値が残ると、前 shard の pgid で不要に dropdb を抑止したり、
+#   記録漏れで dropdb 直前の再確認が空振りしたりする。
 stop_shard_workers() {
     local shard=$1 conn wpidfile wpid wpgid t rc=0
+    if [[ -n "${2:-}" ]]; then
+        local -n _out_pgids=$2
+        _out_pgids=()   # ★ 呼び出しごとに必ず初期化する
+    fi
     for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
         wpidfile="$(worker_pidfile "${shard}" "${conn}")"
         [[ -f "${wpidfile}" ]] || continue
@@ -829,19 +932,26 @@ stop_shard_workers() {
         fi
         kill -TERM -- "-${wpid}" 2>/dev/null || true
         for t in 1 2 3 4 5; do
-            kill -0 -- "-${wpid}" 2>/dev/null || break
+            # 待機ループ中は note を撒かないよう出力を捨てる (本判定は下で 1 回だけ行う)
+            group_stopped "${wpid}" "shard-${shard} worker group (${conn}, pgid=${wpid})" \
+                >/dev/null 2>&1 && break
             sleep 0.4
         done
-        if kill -0 -- "-${wpid}" 2>/dev/null; then
+        if ! group_stopped "${wpid}" "shard-${shard} worker group (${conn}, pgid=${wpid})" \
+            >/dev/null 2>&1; then
             kill -KILL -- "-${wpid}" 2>/dev/null || true
             sleep 0.4
         fi
-        if kill -0 -- "-${wpid}" 2>/dev/null; then
+        # ★ 本判定 (stderr つき)。zombie だけが残った場合は note を出して成功にする。
+        if ! group_stopped "${wpid}" "shard-${shard} worker group (${conn}, pgid=${wpid})"; then
             echo "error: shard-${shard} worker group (${conn}, pgid=${wpid}) が KILL 後も残留 (pidfile 保持: ${wpidfile})" >&2
             rc=1
             continue
         fi
         rm -f "${wpidfile}"
+        if [[ -n "${2:-}" ]]; then
+            _out_pgids+=("${wpid}")   # dropdb 直前の再確認用に pgid を残す
+        fi
     done
     return "${rc}"
 }
@@ -1082,7 +1192,17 @@ cmd_provision_all() {
         "started_at=\"$(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M:%S')\""
 
     if ! is_dryrun; then
-        php artisan optimize:clear > /dev/null
+        # ★ --except=cache: optimize:clear は複合コマンドで、標準タスクのうち cache:clear だけが
+        #   cache store=database のとき **dev DB の cache 表を DELETE** しにいく。
+        #   provision が要るのは bootstrap cache (config/route/view/event/compiled) の破棄であって
+        #   アプリケーションキャッシュではない (bughunt DB は直後に migrate:fresh する)。
+        #   --except はキー名 'cache' とコマンド名 'cache:clear' の両方に一致する。
+        #   ★ このフラグを消すと dev DB 未 migrate 環境で provision 全体が落ちる。消さないこと。
+        # ★ env -i: 本スクリプトの原則 (shell の DB_*/PG* を遮断してから artisan を叩く) へ合流させる。
+        #   ただし env -i が遮断するのは親シェル由来の env だけで Laravel は .env を読む。
+        #   「絶対に DB へ接続しない」とは主張しない (拡張 clear の集合は
+        #   BughuntOptimizeClearTaskInventoryTest が別途 pin する)。
+        env -i PATH="${PATH}" HOME="${HOME:-/tmp}" php artisan optimize:clear --except=cache > /dev/null
         ensure_fresh_assets
     fi
 
@@ -1145,17 +1265,33 @@ cmd_mail_urls() {
 
 # --- teardown -----------------------------------------------------------------
 
+# dropdb 直前の再確認。stop_shard_workers が積んだ pgid 群を nameref で受け、
+# 全て group_stopped を満たすときだけ 0 を返す。
+# ★ 記録が空 (worker を 1 つも止めていない = pidfile が無かった) 場合は成功でよい。
+recheck_shard_workers_stopped() {
+    local -n _pgids=$1
+    local label=$2 pgid
+    for pgid in ${_pgids[@]+"${_pgids[@]}"}; do
+        group_stopped "${pgid}" "${label} recheck (pgid=${pgid})" || return 1
+    done
+    return 0
+}
+
 cmd_teardown() {
     local run_id=$1 drop_db=${2:-}
     require_orchestrator "teardown"
     local shard pid port teardown_rc=0
-    for shard in 0 1 2 3 4 5 6 7 8; do
+    # ★ 範囲は cap から導出する。リテラルを置くと cap 変更時に SHARD_DB_RE とずれ、
+    #   自分の guard で abort する (cap=8→4 の変更時に実際に起きた: bug_hunt_5 で die)。
+    #   seq への外部依存を増やさないため bash 算術ループを使う。
+    for ((shard = 0; shard <= BUGHUNT_SHARD_CAP; shard++)); do
         # 専用 connection worker の停止 (serve より先に止め、--drop-db 時の DB 接続残留を防ぐ)。
         # stop_shard_workers が cmdline 照合 → TERM → group 消滅待ち → KILL escalation →
         # 再確認まで行い、残留があれば pidfile を保持して非ゼロを返す (追跡可能性を失わない)。
         # 停止失敗 shard の dropdb は抑止する (接続保持した孤児 worker と dropdb の衝突防止)。
         local workers_stopped=1
-        if ! stop_shard_workers "${shard}"; then
+        local -a stopped_pgids=()   # ★ shard ごとに新しい配列 (前 shard の pgid を持ち越さない)
+        if ! stop_shard_workers "${shard}" stopped_pgids; then
             workers_stopped=0
             teardown_rc=1
             echo "warning: shard-${shard} の worker 停止に失敗 — この shard の dropdb をスキップ (pidfile 保持)" >&2
@@ -1182,7 +1318,16 @@ cmd_teardown() {
             rm -f "${pidfile}"
         fi
         if [[ "${drop_db}" == "--drop-db" && "${workers_stopped}" == 1 ]] && ! is_dryrun; then
-            pg_admin_for_provision dropdb "$(shard_db "${shard}")"
+            # ★ procfs 走査は一時点のスナップショットではない。TOCTOU 窓は消せないが、
+            #   dropdb 分岐の**直前**でもう一度確認して窓を最小化する。
+            #   再確認で残留を観測したら DB を消さない側へ倒す (fail-closed)。
+            if ! recheck_shard_workers_stopped stopped_pgids "shard-${shard}"; then
+                teardown_rc=1
+                echo "warning: shard-${shard} の worker が dropdb 直前の再確認で残留 — dropdb をスキップ" >&2
+            else
+                # DB 名 guard (SHARD_DB_RE) と admin role 明示は従来どおり wrapper 側が通す。
+                pg_admin_for_provision dropdb "$(shard_db "${shard}")"
+            fi
         fi
         rm -f "$(wrapper_path "${shard}")"
         rm -rf "storage/bughunt-coverage/.pcov-ini-${shard}"
@@ -1221,8 +1366,34 @@ stories_for_shard() {
 # --- self-test (実資源に触れない) ----------------------------------------------
 
 cmd_self_test() {
-    local sandbox failures=0
-    sandbox="$(mktemp -d -t bug-hunt-selftest.XXXXXX)"
+    local sandbox failures=0 sandbox_owned=0
+    # sandbox は「外から与えられていればそれを使い、未指定のときだけ自分で作る」。
+    # 呼び出し側 (Pest の BughuntSelfTestExecutionTest) が隔離境界を握れるようにするため。
+    #
+    # ★ 「既存の絶対ディレクトリ」だけでは境界にならない。/ や WORKSPACE を渡されると
+    #   RUN_BASE / TMP_BASE がそこへ向き、削除しなくても書き込みで実資源を壊せる。
+    #   そこで **専用マーカーファイル**を要求する = 呼び出し側が「捨ててよい空き地」を
+    #   意図的に用意したときだけ受け付ける。
+    # ★ 後始末の契約: 外部指定 (sandbox_owned=0) は**絶対に削除しない**。
+    #   内部生成 (sandbox_owned=1) も現時点では削除しない (現行挙動を変えない)。
+    #   sandbox_owned は「将来 cleanup を足すとしても owned のときだけ」を先に固定するために持つ。
+    if [[ -n "${BUGHUNT_SANDBOX:-}" ]]; then
+        sandbox="${BUGHUNT_SANDBOX}"
+        [[ "${sandbox}" == /* && "${sandbox}" != / ]] \
+            || die 2 "BUGHUNT_SANDBOX は / 以外の絶対パスであること: '${sandbox}'"
+        [[ -d "${sandbox}" ]] || die 2 "BUGHUNT_SANDBOX が存在しない: '${sandbox}'"
+        # 表記差 (末尾 /. や symlink) を吸収するため物理パスで比較する
+        local _sb_real _ws_real
+        _sb_real="$(cd "${sandbox}" && pwd -P)"
+        _ws_real="$(cd "${WORKSPACE}" && pwd -P)"
+        [[ "${_sb_real}" != "${_ws_real}" ]] \
+            || die 2 "BUGHUNT_SANDBOX にリポジトリルートは指定できない"
+        [[ -f "${sandbox}/.bughunt-selftest-sandbox" ]] \
+            || die 2 "BUGHUNT_SANDBOX に専用マーカー .bughunt-selftest-sandbox が無い (捨ててよい空き地だけを受け付ける)"
+    else
+        sandbox="$(mktemp -d -t bug-hunt-selftest.XXXXXX)"
+        sandbox_owned=1
+    fi
     export BUGHUNT_SANDBOX="${sandbox}"
     mkdir -p "${sandbox}/devnotes" "${sandbox}/tmp/bug-hunt"
     RUN_BASE="${sandbox}/devnotes"
@@ -1738,7 +1909,9 @@ CURLEOF
     stopw_def="$(declare -f stop_shard_workers)"
     echo "${stopw_def}" | grep -qF 'kill -TERM -- "-' || t_fail "stop_shard_workers が process group kill でない"
     echo "${stopw_def}" | grep -q 'worker_alive' || t_fail "stop_shard_workers に cmdline 照合 (worker_alive) が無い"
-    echo "${stopw_def}" | grep -qF 'kill -0 -- "-' || t_fail "stop_shard_workers に process group 消滅待ちが無い (master 単体判定は dropdb と race)"
+    # 成功条件が group 全体の判定であること (master 単体判定に戻すと dropdb と race する)。
+    # T142 で判定を kill -0 から procfs ベースの group_stopped へ移したため、参照先を更新した。
+    echo "${stopw_def}" | grep -qF 'group_stopped' || t_fail "stop_shard_workers に process group 単位の停止判定 (group_stopped) が無い (master 単体判定は dropdb と race)"
     echo "${stopw_def}" | grep -qF 'kill -KILL -- "-' || t_fail "stop_shard_workers に KILL escalation が無い"
     echo "${stopw_def}" | grep -q 'ps -o pgid=' || t_fail "stop_shard_workers に pid==pgid 検証が無い (setsid 不成立の group を消滅済みと誤認する)"
     echo "${stopw_def}" | grep -qF 'kill -TERM "${wpid}"' \
@@ -1848,6 +2021,186 @@ CURLEOF
     rm -f "$(worker_pidfile 8 database-media)"
     t_ok "queue worker wiring (derivation/drift/structure/alive/dryrun/stop 正常系+失敗系)"
 
+    # (y7) group 生存判定 (zombie 除外 + fail-closed)。T142 / bug-hunt run 20260809-152048 の H-1。
+    # kill -0 -- -PGID は zombie も「生存」と数えるため、PID 1 が zombie を刈らない環境で
+    # dropdb が永久に抑止されていた。判定対象を procfs にし、確認不能は失敗へ倒す。
+    echo "[y7] group 生存判定 (zombie 除外 / fail-closed / 2 連続走査)"
+
+    # (y7a) parse_proc_stat_line: comm に空白と ')' を含んでも state/pgrp を誤読しない。
+    #       ★ テスト側でパースを複製すると実装の検証にならないので、実装関数を直接叩く。
+    local parsed
+    parsed="$(parse_proc_stat_line '123 (my ) proc) Z 1 456 0 0')" \
+        || t_fail "[y7a] parse_proc_stat_line が正当な行を拒否"
+    [[ "${parsed}" == "Z 456" ]] \
+        || t_fail "[y7a] parse_proc_stat_line の誤読 (期待 'Z 456' / 実際 '${parsed}')"
+    parsed="$(parse_proc_stat_line '999 (php) S 1 777 0 0')" \
+        || t_fail "[y7a] parse_proc_stat_line が通常行を拒否"
+    [[ "${parsed}" == "S 777" ]] || t_fail "[y7a] 通常行の誤読 ('${parsed}')"
+    parse_proc_stat_line 'garbage-without-paren' \
+        && t_fail "[y7a] ') ' を含まない行を受理した"
+
+    # (y7b) メンバー 0 件 = 停止成功。zombie note は出さない。
+    #       存在しない pgid を使う (kill -0 も失敗するので補完条件にも掛からない)。
+    local out7
+    out7="$( group_stopped 999999999 "[y7b]" 2>&1 )" \
+        || t_fail "[y7b] メンバー 0 件なのに停止失敗"
+    [[ "${out7}" != *"zombie"* ]] || t_fail "[y7b] メンバー 0 件で zombie note を出した"
+
+    # (y7c) 全て zombie = 停止成功 + stderr に note。
+    # (y7d) 非 zombie が 1 件 = 停止失敗。
+    # (y7e) 混在 = 停止失敗。
+    # いずれも group_member_counts を stub して判定側の契約だけを見る。
+    out7="$( group_member_counts() { echo "0 3 0"; }
+             kill() { return 1; }   # kill -0 は失敗させる (補完条件を通さない)
+             group_stopped 4242 "[y7c]" 2>&1 )" \
+        || t_fail "[y7c] 全 zombie なのに停止失敗"
+    [[ "${out7}" == *"zombie 3 件"* ]] || t_fail "[y7c] zombie note が出ていない ('${out7}')"
+
+    ( group_member_counts() { echo "1 0 0"; }
+      group_stopped 4242 "[y7d]" ) 2>/dev/null \
+        && t_fail "[y7d] 非 zombie が居るのに停止成功"
+
+    ( group_member_counts() { echo "1 2 0"; }
+      group_stopped 4242 "[y7e]" ) 2>/dev/null \
+        && t_fail "[y7e] zombie/非 zombie 混在なのに停止成功"
+
+    # (y7f) 走査中に消えた pid を残留と数えない (実 procfs を使う)。
+    #       存在しない pgid への集計は 0 0 0 になるはず (unknown も 0)。
+    out7="$(group_member_counts 999999999)"
+    [[ "${out7}" == "0 0 0" ]] || t_fail "[y7f] 消えた pid / 無関係行を誤って数えた ('${out7}')"
+
+    # (y7k) unknown > 0 は「確認不能」= 停止失敗 (fail-closed)。
+    ( group_member_counts() { echo "0 0 1"; }
+      group_stopped 4242 "[y7k]" ) 2>/dev/null \
+        && t_fail "[y7k] unknown があるのに停止成功 (fail-open)"
+
+    # (y7l) 0 0 0 でも kill -0 が成功するなら「確認不能」= 停止失敗。
+    #       procfs が読めていない可能性を成功へ倒さない。
+    ( group_member_counts() { echo "0 0 0"; }
+      kill() { return 0; }
+      group_stopped 4242 "[y7l]" ) 2>/dev/null \
+        && t_fail "[y7l] kill -0 成功なのに procfs 0 件を停止成功へ倒した (fail-open)"
+
+    # (y7m) 集計出力が不正 (空 / 非数値) でも停止失敗へ倒す。
+    ( group_member_counts() { echo ""; }
+      group_stopped 4242 "[y7m1]" ) 2>/dev/null \
+        && t_fail "[y7m] 空の集計出力で停止成功"
+    ( group_member_counts() { echo "x y z"; }
+      group_stopped 4242 "[y7m2]" ) 2>/dev/null \
+        && t_fail "[y7m] 非数値の集計出力で停止成功"
+
+    # (y7n) 連続 2 回走査: 1 回目 live=0 / 2 回目 live=1 なら失敗。
+    #       zombie 観測経路の race 窓を縮小していることの直接固定。
+    # ★ 呼び出し回数はファイルで数える。group_member_counts は $( ) の中で呼ばれる =
+    #   subshell なので、シェル変数のインクリメントは呼び出し元へ戻らない。
+    local y7n_counter="${TMP_BASE}/y7n-calls"
+    : > "${y7n_counter}"
+    ( group_member_counts() {
+          echo x >> "${y7n_counter}"
+          if [[ "$(wc -l < "${y7n_counter}")" == 1 ]]; then echo "0 1 0"; else echo "1 1 0"; fi
+      }
+      kill() { return 1; }
+      group_stopped 4242 "[y7n]" ) 2>/dev/null \
+        && t_fail "[y7n] 2 回目に非 zombie が出たのに停止成功 (連続 2 回走査が効いていない)"
+    rm -f "${y7n_counter}"
+
+    t_ok "group 生存判定 (parse/0件/全zombie/非zombie/混在/unknown/kill矛盾/2連続走査)"
+
+    # (y7g-j) dropdb への**到達制御**。危険なのは guard の有無ではなく
+    # 「worker 停止失敗時に dropdb へ到達しないか」という制御フローそのもの。
+    echo "[y7x] dropdb 到達制御 (再確認 / wrapper 不呼び出し / guard 経由)"
+
+    # (y7g) 再確認で非 zombie を観測したら失敗する (1 回目ゼロ・2 回目非 zombie の必須ケース)。
+    local y7g_counter="${TMP_BASE}/y7g-calls"
+    : > "${y7g_counter}"
+    ( group_member_counts() {
+          echo x >> "${y7g_counter}"
+          if [[ "$(wc -l < "${y7g_counter}")" == 1 ]]; then echo "0 0 0"; else echo "1 0 0"; fi
+      }
+      kill() { return 1; }
+      _pg=(4242)
+      recheck_shard_workers_stopped _pg "[y7g]" ) 2>/dev/null \
+        && t_fail "[y7g] 再確認で非 zombie が出たのに成功"
+    rm -f "${y7g_counter}"
+
+    # (y7h) 非 zombie 残留のとき dropdb wrapper が **一度も呼ばれない**。
+    #       pg_admin_for_provision を stub し、呼ばれたら痕跡を残す。
+    local y7h_marker="${TMP_BASE}/y7h-dropdb-called"
+    rm -f "${y7h_marker}"
+    ( group_member_counts() { echo "1 0 0"; }
+      pg_admin_for_provision() { echo "$*" >> "${y7h_marker}"; }
+      _pg=(4242)
+      if recheck_shard_workers_stopped _pg "[y7h]"; then
+          pg_admin_for_provision dropdb "$(shard_db 1)"
+      fi ) 2>/dev/null
+    [[ ! -f "${y7h_marker}" ]] \
+        || t_fail "[y7h] 非 zombie 残留なのに dropdb wrapper が呼ばれた ($(cat "${y7h_marker}"))"
+
+    # (y7j) 逆に停止済みなら wrapper を通って dropdb へ進み、**DB 名 guard を必ず通る**。
+    #       guard_shard_db_name を stub して呼ばれたことと引数を確認する。
+    local y7j_marker="${TMP_BASE}/y7j-guard"
+    rm -f "${y7j_marker}"
+    ( group_member_counts() { echo "0 0 0"; }
+      kill() { return 1; }
+      guard_shard_db_name() { echo "$1" >> "${y7j_marker}"; }
+      pg_admin_for_provision() { guard_shard_db_name "$2"; }
+      _pg=(4242)
+      if recheck_shard_workers_stopped _pg "[y7j]"; then
+          pg_admin_for_provision dropdb "$(shard_db 1)"
+      fi ) 2>/dev/null
+    [[ -f "${y7j_marker}" ]] || t_fail "[y7j] 停止済みなのに dropdb 経路へ進まなかった"
+    grep -qx "$(shard_db 1)" "${y7j_marker}" \
+        || t_fail "[y7j] dropdb が DB 名 guard を通っていない (記録: $(cat "${y7j_marker}" 2>/dev/null))"
+    rm -f "${y7j_marker}"
+
+    # (y7i) teardown が worker 停止失敗時に dropdb を抑止する構造を保っていること
+    local td_def
+    td_def="$(declare -f cmd_teardown)"
+    echo "${td_def}" | grep -q 'recheck_shard_workers_stopped' \
+        || t_fail "[y7i] cmd_teardown に dropdb 直前の再確認が無い"
+    echo "${td_def}" | grep -q 'stopped_pgids' \
+        || t_fail "[y7i] cmd_teardown が pgid を shard ローカルで受け渡していない"
+
+    t_ok "dropdb 到達制御 (再確認 / wrapper 不呼び出し / DB名 guard 経由 / teardown 構造)"
+
+    # (y8) teardown のループ範囲が cap から導出されていること (T142 / H-2)。
+    echo "[y8] teardown ループ範囲の cap 導出"
+    echo "${td_def}" | grep -qE 'for shard in 0 1 2' \
+        && t_fail "[y8a] cmd_teardown に数値リテラルのループ範囲が復活している"
+    echo "${td_def}" | grep -q 'BUGHUNT_SHARD_CAP' \
+        || t_fail "[y8a] cmd_teardown のループ範囲が cap から導出されていない"
+
+    # (y8b/y8c) テスト用 cap で実評価する。0..cap は allow、cap+1 は deny。
+    #   本番定数は触らない (局所再代入 + 復元。外部注入の経路は作らない)。
+    local _saved_cap="${BUGHUNT_SHARD_CAP}" _saved_dbre="${SHARD_DB_RE}" _saved_shre="${SHARD_RE}"
+    BUGHUNT_SHARD_CAP=2
+    SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"
+    SHARD_RE="^[0-${BUGHUNT_SHARD_CAP}]$"
+    local n
+    for n in 0 1 2; do
+        ( guard_shard_db_name "$(shard_db "${n}")" ) >/dev/null 2>&1 \
+            || t_fail "[y8b] cap=2 で shard ${n} の DB 名が拒否された"
+        [[ "${n}" =~ ${SHARD_RE} ]] || t_fail "[y8c] cap=2 で shard ${n} の入力が拒否された"
+    done
+    ( guard_shard_db_name "${BUGHUNT_DB_PREFIX}_3" ) >/dev/null 2>&1 \
+        && t_fail "[y8b] cap=2 なのに ${BUGHUNT_DB_PREFIX}_3 が受理された"
+    [[ "3" =~ ${SHARD_RE} ]] && t_fail "[y8c] cap=2 なのに shard 3 の入力が受理された"
+    BUGHUNT_SHARD_CAP="${_saved_cap}"; SHARD_DB_RE="${_saved_dbre}"; SHARD_RE="${_saved_shre}"
+    t_ok "teardown ループ範囲の cap 導出 (SHARD_DB_RE / SHARD_RE の実評価)"
+
+    # (y9) provision-all の optimize:clear が dev DB を触らない形であること (T142 / H-3)。
+    echo "[y9] optimize:clear の dev DB 非接触 (--except=cache + env -i)"
+    local pa_def
+    pa_def="$(declare -f cmd_provision_all)"
+    local oc_line
+    oc_line="$(echo "${pa_def}" | grep -F 'optimize:clear' | grep -v '^\s*#' | head -1)"
+    [[ -n "${oc_line}" ]] || t_fail "[y9] cmd_provision_all に optimize:clear が無い"
+    echo "${oc_line}" | grep -qF -- '--except=cache' \
+        || t_fail "[y9a] optimize:clear に --except=cache が無い (dev DB の cache 表を触る)"
+    echo "${oc_line}" | grep -qE '(^|[[:space:]])env -i' \
+        || t_fail "[y9b] optimize:clear が env -i 経由でない (ambient DB_*/PG* が渡る)"
+    t_ok "optimize:clear (--except=cache / env -i)"
+
     echo "[z] real-llm/fake-llm/real-storage モード制御 (フラグ/キー分離・fail-fast・秘密漏洩防止・引数解析)"
     local _e
     local z_env="${sandbox}/main-with-key.env"
@@ -1976,7 +2329,12 @@ PY
     LLM_MODE="${_saved_llm_mode}"; STORAGE_MODE="${_saved_storage_mode}"
     MODE_ENV=(); LLM_KEY_ENV=()
 
-    rm -rf "${sandbox}"
+    # ★ 自分で作った sandbox だけを削除する。外から与えられた sandbox は借り物なので
+    #   絶対に消さない (呼び出し側が成果物を確認できなくなる / 危険な値を渡された時に
+    #   再帰削除が走る、の両方を防ぐ)。
+    if [[ "${sandbox_owned}" == 1 ]]; then
+        rm -rf "${sandbox}"
+    fi
     unset BUGHUNT_SANDBOX
     if [[ "${failures}" -gt 0 ]]; then
         echo "self-test: ${failures} failure(s)"
diff --git a/scripts/setup-worktree.sh b/scripts/setup-worktree.sh
index 9734b31..f8a9723 100755
--- a/scripts/setup-worktree.sh
+++ b/scripts/setup-worktree.sh
@@ -30,6 +30,28 @@
 
 set -euo pipefail
 
+# --- bug-hunt 専用 env の provisioning (契約テストから source して単体で叩けるよう関数化) ---
+# .env.bughunt.local は .gitignore 対象で worktree には決して現れない = コピーが唯一の供給路。
+# bug-hunt は worktree 走行が既定 (AGENTS.md) なので、無いと provision が必ず止まる。
+#
+# ★ mode は親に追随させず 0600 に固定する。親が 0644 だと `cp -p` は
+#   **world-readable な秘密ファイルを新たに作る**ため契約として弱い。
+#   `install -m 600` は作成時点で mode を確定するので、`cp` → `chmod` の 2 段にある
+#   「一瞬だけ広く読める窓」も無い。
+# ★ 今回 0600 を固定する対象は **.env.bughunt.local だけ**である。
+#   既存の .env / storage/oauth-*.key の権限契約は変更しない (別施策)。
+provision_bughunt_env_file() {
+    local repo_root=$1 worktree_dir=$2
+    [[ -f "${repo_root}/.env.bughunt.local" ]] || return 0   # 非利用リポジトリでは no-op
+    install -m 600 "${repo_root}/.env.bughunt.local" "${worktree_dir}/.env.bughunt.local"
+}
+
+# ★ source 専用モード: 関数定義だけ取り込んで抜ける (契約テスト用)。
+#   実行時 (bash setup-worktree.sh) は環境変数を立てないので通らない。
+if [[ -n "${SETUP_WORKTREE_SOURCE_ONLY:-}" && "${BASH_SOURCE[0]}" != "$0" ]]; then
+    return 0
+fi
+
 if [[ $# -ne 1 || -z "${1:-}" ]]; then
     echo "usage: $0 <task-id>" >&2
     echo "  ブランチ名は todo/<task-id> に固定 (custom branch 非対応)" >&2
@@ -197,7 +219,7 @@ fi
 # storage/oauth-*.key / public/build は runtime artifact (.gitignore 対象) で、workspace に
 # あればコピー / 無ければ note して続行 (テンプレート初期状態では未生成のことがある。
 # 必要になった時点で worktree 内 `php artisan passport:keys` / `pnpm build` で生成できる)。
-echo ">>> [2/7] .env / storage/oauth-*.key / public/build を親からコピー"
+echo ">>> [2/7] .env / .env.bughunt.local / storage/oauth-*.key / public/build を親からコピー"
 if [[ -f "${REPO_ROOT}/.env" ]]; then
     cp "${REPO_ROOT}/.env" "${WORKTREE_DIR}/.env"
 else
@@ -211,6 +233,11 @@ for f in storage/oauth-private.key storage/oauth-public.key; do
         echo "    note: ${f} が親に無いためコピーをスキップ (必要なら worktree 内で 'php artisan passport:keys')" >&2
     fi
 done
+if provision_bughunt_env_file "${REPO_ROOT}" "${WORKTREE_DIR}" && [[ -f "${WORKTREE_DIR}/.env.bughunt.local" ]]; then
+    PROVISIONED_PATHS+=(".env.bughunt.local")
+else
+    echo "    note: .env.bughunt.local が親に無いためコピーをスキップ (bug-hunt 未使用なら不要)" >&2
+fi
 if [[ -d "${REPO_ROOT}/public/build" ]]; then
     cp -r "${REPO_ROOT}/public/build" "${WORKTREE_DIR}/public/build"
     PROVISIONED_PATHS+=("public/build")
diff --git a/tests/Architecture/BughuntOptimizeClearTaskInventoryTest.php b/tests/Architecture/BughuntOptimizeClearTaskInventoryTest.php
new file mode 100644
index 0000000..863d81d
--- /dev/null
+++ b/tests/Architecture/BughuntOptimizeClearTaskInventoryTest.php
@@ -0,0 +1,70 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\ServiceProvider;
+
+/*
+ * optimize:clear の拡張タスク目録 (deny-by-default)。
+ *
+ * bug-hunt の provision は `optimize:clear --except=cache` を叩く (dev DB の cache 表に
+ * 触れないようにするため)。標準タスクのうち DB に触る cache:clear は除外したが、
+ * ServiceProvider::$optimizeClearCommands 経由で **パッケージが登録した clear コマンド** も
+ * 同時に実行される。ここが増えると「dev DB を触らない」前提が静かに崩れる。
+ *
+ * ★ これは証明ではなく **検出** である。集合が増えたら赤くなる。
+ * ★ 保証しないもの: 既存の同名コマンド (filament:optimize-clear / icons:clear) の内部実装が
+ *   依存更新によって DB 接続を始めても、集合検査は赤くならない (集合の増減しか見ていない)。
+ *   そのため rationale は **package version 更新時に再確認する** 運用とする。
+ */
+
+/** key = $optimizeClearCommands のキー / value = [コマンド, 登録元, 非 DB と判断した理由]。 */
+const BUGHUNT_OPTIMIZE_CLEAR_ALLOWLIST = [
+    'filament' => [
+        'filament:optimize-clear',
+        'filament/support',
+        'Filament の component / blade キャッシュ (ファイル) の破棄。DB を触らない',
+    ],
+    'blade-icons' => [
+        'icons:clear',
+        'blade-ui-kit/blade-icons',
+        'アイコンキャッシュ (ファイル) の破棄。DB を触らない',
+    ],
+];
+
+test('optimize:clear の拡張タスクが既知の allowlist と完全一致すること', function (): void {
+    $registered = ServiceProvider::$optimizeClearCommands;
+
+    expect(array_keys($registered))
+        ->toEqualCanonicalizing(
+            array_keys(BUGHUNT_OPTIMIZE_CLEAR_ALLOWLIST),
+            '$optimizeClearCommands の集合が変わった。増えた clear コマンドが DB を触らないかを'
+            .'人が判断してから allowlist に足すこと (bug-hunt の provision がこれを実行する)',
+        );
+
+    foreach (BUGHUNT_OPTIMIZE_CLEAR_ALLOWLIST as $key => [$command, $package, $rationale]) {
+        expect($registered[$key])->toBe(
+            $command,
+            "allowlist の登録コマンドが変わった: {$key} ({$package})",
+        );
+        expect($rationale)->not->toBe('', "rationale が空: {$key}");
+    }
+});
+
+test('bug-hunt の provision が optimize:clear から cache タスクを外していること', function (): void {
+    // --except は OptimizeClearCommand::handle() の $exceptions->hasAny([$command, $key]) により
+    // キー名 'cache' とコマンド名 'cache:clear' の両方に一致する。
+    $script = file_get_contents(base_path('scripts/bug-hunt-shard.sh'));
+
+    // 実行行だけを対象にする (self-test は同じ語を検査文字列として持つため、
+    // 単に 'optimize:clear' を含む行を数えると self-test 側まで拾ってしまう)。
+    $lines = array_values(array_filter(
+        explode("\n", $script),
+        fn (string $line): bool => str_contains($line, 'php artisan optimize:clear')
+            && preg_match('/^\s*#/', $line) !== 1,
+    ));
+
+    expect($lines)->toHaveCount(1, 'optimize:clear の実行行が 1 行ではない');
+    expect($lines[0])->toContain('--except=cache');
+    expect($lines[0])->toContain('env -i');
+});
diff --git a/tests/Architecture/BughuntRawDbCommandInventoryTest.php b/tests/Architecture/BughuntRawDbCommandInventoryTest.php
new file mode 100644
index 0000000..2f19238
--- /dev/null
+++ b/tests/Architecture/BughuntRawDbCommandInventoryTest.php
@@ -0,0 +1,132 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * bug-hunt harness の raw DB コマンド目録 (deny-by-default)。
+ *
+ * dev DB 防御の核は「createdb / dropdb は admin 経路 (pg_admin_for_provision) だけが実行し、
+ * その中で DB 名 regex と admin role 明示を通る」ことである。スクリプトのどこかに
+ * raw な createdb / dropdb が増えると、この一点集中が静かに崩れる。
+ *
+ * ★ 保証範囲を先に限定する: これは **literal な出現の検出**であって、
+ *   変数展開 ($cmd) / 関数経由 / env 経由 / eval まで含めた「呼び出しが無いこと」の**証明ではない**。
+ *   そこまで見るには bash の AST 相当の解析が要る。ここでは
+ *   「うっかり dropdb と書いた行が増えていないか」を保守的に検出する。
+ *
+ * ★ なぜ「文字列リテラルを除外する」方式を採らないか: bash の字句解析なしに
+ *   文字列中の dropdb を正しく除外することはできない。除外を試みると逆に**実行行を見落とす**
+ *   穴を作る。そこで「literal が現れる行を全部数え、既知の目録と完全一致するか」という
+ *   保守的な方式にする (inline コメントもメッセージも目録に載せる。冗長だが見落とさない)。
+ */
+
+/** 実行実体。各ちょうど 1 行存在しなければならない。 */
+const BUGHUNT_RAW_DB_REQUIRED = [
+    'op_cmd=(createdb -O bughunt' => 'admin 経路の createdb 実体 (OWNER bughunt 必須)',
+    'op_cmd=(dropdb --if-exists' => 'admin 経路の dropdb 実体',
+];
+
+/**
+ * 存在してよい行 (wrapper 呼び出し / メッセージ / inline コメント / self-test)。
+ * key = 一意な識別部分文字列 / value = [出現回数, 理由]。
+ */
+const BUGHUNT_RAW_DB_ALLOWED = [
+    'die 1 "guard_admin_provision: BUGHUNT_ADMIN_USER' => [1, 'admin role 未設定時のエラーメッセージ'],
+    'local op=$1 db=$2' => [1, 'inline コメント `# op ∈ {createdb, dropdb}`'],
+    '_out_pgids+=("${wpid}")' => [1, 'inline コメント (dropdb 直前の再確認用に pgid を残す)'],
+    'pg_admin_for_provision createdb "${db}"' => [1, 'wrapper 経由の createdb 呼び出し (raw ではない)'],
+    'pg_admin_for_provision dropdb "$(shard_db "${shard}")"' => [1, 'wrapper 経由の dropdb 呼び出し (raw ではない)'],
+    'echo "warning: shard-${shard} の worker 停止に失敗' => [1, 'dropdb スキップの警告文'],
+    'echo "warning: shard-${shard} の worker が dropdb 直前の再確認で残留' => [1, '再確認失敗時の警告文'],
+    'echo "[f] createdb 実行コマンドに OWNER bughunt' => [1, 'self-test の見出し'],
+    "grep -q 'createdb -O bughunt'" => [1, 'self-test の検査条件'],
+    't_fail "createdb に OWNER bughunt' => [1, 'self-test の失敗メッセージ'],
+    't_ok "createdb OWNER bughunt"' => [1, 'self-test の成功ログ'],
+    't_fail "stop_shard_workers に process group 単位の停止' => [1, 'self-test の失敗メッセージ (dropdb と race)'],
+    't_fail "cmd_teardown に worker 停止失敗時の dropdb 抑止が無い' => [1, 'self-test の失敗メッセージ'],
+    'echo "[y7x] dropdb 到達制御' => [1, 'self-test の見出し'],
+    'local y7h_marker=' => [1, 'self-test の marker パス (dropdb-called)'],
+    'pg_admin_for_provision dropdb "$(shard_db 1)"' => [2, 'self-test 内の到達制御ケース 2 件 (y7h / y7j)'],
+    't_fail "[y7h] 非 zombie 残留なのに dropdb wrapper が呼ばれた' => [1, 'self-test の失敗メッセージ'],
+    't_fail "[y7j] 停止済みなのに dropdb 経路へ進まなかった"' => [1, 'self-test の失敗メッセージ'],
+    't_fail "[y7j] dropdb が DB 名 guard を通っていない' => [1, 'self-test の失敗メッセージ'],
+    't_fail "[y7i] cmd_teardown に dropdb 直前の再確認が無い"' => [1, 'self-test の失敗メッセージ'],
+    't_ok "dropdb 到達制御' => [1, 'self-test の成功ログ'],
+];
+
+/**
+ * 行頭コメントを除いた行のうち、単語境界の createdb / dropdb を含むものを返す。
+ *
+ * @return list<string>
+ */
+function bughuntRawDbLiteralLines(string $path): array
+{
+    $lines = file($path, FILE_IGNORE_NEW_LINES);
+    expect($lines)->toBeArray();
+
+    $hits = [];
+    foreach ($lines as $line) {
+        if (preg_match('/^\s*#/', $line) === 1) {
+            continue;   // 行頭コメント (冒頭の説明文で偽陽性になるため除外)
+        }
+        if (preg_match('/\b(createdb|dropdb)\b/', $line) === 1) {
+            $hits[] = trim($line);
+        }
+    }
+
+    return $hits;
+}
+
+test('createdb / dropdb の実行実体が admin 経路にちょうど 1 行ずつ存在すること', function (): void {
+    $hits = bughuntRawDbLiteralLines(base_path('scripts/bug-hunt-shard.sh'));
+
+    foreach (BUGHUNT_RAW_DB_REQUIRED as $key => $reason) {
+        $count = count(array_filter($hits, fn (string $line): bool => str_contains($line, $key)));
+
+        expect($count)->toBe(1, "必須実行行が 1 行ではない: '{$key}' ({$reason}) → {$count} 行");
+    }
+});
+
+test('createdb / dropdb の literal が目録と完全一致すること', function (): void {
+    $hits = bughuntRawDbLiteralLines(base_path('scripts/bug-hunt-shard.sh'));
+
+    // key => 期待件数 (必須 + 許可)
+    $expected = [];
+    foreach (BUGHUNT_RAW_DB_REQUIRED as $key => $_reason) {
+        $expected[$key] = 1;
+    }
+    foreach (BUGHUNT_RAW_DB_ALLOWED as $key => [$count, $_reason]) {
+        expect($expected)->not->toHaveKey($key, "目録に重複キー: '{$key}'");
+        $expected[$key] = $count;
+    }
+
+    // 1. 各行がちょうど 1 つの目録キーに一致すること (未知の行 / 曖昧な行を弾く)
+    $unknown = [];
+    $matched = [];
+    foreach ($hits as $line) {
+        $keys = array_values(array_filter(
+            array_keys($expected),
+            fn (string $key): bool => str_contains($line, $key),
+        ));
+
+        if ($keys === []) {
+            $unknown[] = $line;
+
+            continue;
+        }
+
+        expect($keys)->toHaveCount(
+            1,
+            "1 行が複数の目録キーに一致した (識別キーが曖昧): {$line} → ".implode(' / ', $keys),
+        );
+        $matched[$keys[0]] = ($matched[$keys[0]] ?? 0) + 1;
+    }
+
+    expect($unknown)->toBe([], "目録に無い createdb/dropdb の literal 行が増えている:\n".implode("\n", $unknown));
+
+    // 2. 件数が目録と一致すること (行が消えた場合も検出する)
+    expect($matched)->toEqual($expected, '目録の期待件数と実際の出現件数が一致しない');
+
+    // 3. 合計件数も突き合わせる (必須 2 + 許可分)
+    expect(count($hits))->toBe(array_sum($expected));
+});
diff --git a/tests/Architecture/BughuntSelfTestExecutionTest.php b/tests/Architecture/BughuntSelfTestExecutionTest.php
new file mode 100644
index 0000000..e98ed27
--- /dev/null
+++ b/tests/Architecture/BughuntSelfTestExecutionTest.php
@@ -0,0 +1,92 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Process;
+
+/*
+ * bug-hunt harness の**実行配線**ゲート。
+ *
+ * scripts/bug-hunt-shard.sh self-test は「実資源に触れない自己検証」で、
+ * guard / 資源導出 / env 隔離 / worker 停止判定の **実行時挙動** を担う。
+ * 既存の Architecture テスト (BughuntShardCapInvariantTest /
+ * BughuntOrchestratorGateInvariantTest) は静的構造だけを見ており、self-test を
+ * 「参照」はしていても **呼んではいなかった** = 二段防御の片側が自動実行されていなかった。
+ * ここで composer test の配線に載せる。
+ *
+ * 隔離境界はテスト側が握る。self-test は BUGHUNT_SANDBOX が与えられていればそれを使い、
+ * 未指定のときだけ mktemp -d する契約になっている。外部指定は「捨ててよい空き地」だけを
+ * 受け付けるため専用マーカーを要求する (/ や リポジトリルートを渡す事故を構造的に防ぐ)。
+ */
+
+/** self-test へ渡す「捨ててよい空き地」を作る (マーカー必須)。 */
+function makeSelfTestSandbox(): string
+{
+    $dir = sys_get_temp_dir().'/bughunt-selftest-pest-'.bin2hex(random_bytes(6));
+    File::makeDirectory($dir, 0700, true);
+    File::put($dir.'/.bughunt-selftest-sandbox', '');
+    chmod($dir.'/.bughunt-selftest-sandbox', 0600);
+
+    return $dir;
+}
+
+test('bug-hunt harness の self-test が通ること', function (): void {
+    $script = base_path('scripts/bug-hunt-shard.sh');
+    expect(is_readable($script))->toBeTrue();
+
+    $tmp = makeSelfTestSandbox();   // ★ マーカー付き。無いと self-test が die 2 で落ちる
+
+    try {
+        // executable bit に依存せず bash 経由で起動する。
+        // timeout は実測 ~4 秒に対し 120 秒 (CI の遅さを吸収しつつ無限待ちにしない)。
+        $process = Process::timeout(120)
+            ->env(['BUGHUNT_SANDBOX' => $tmp, 'TMPDIR' => $tmp])
+            ->run(['bash', $script, 'self-test']);
+
+        expect($process->exitCode())->toBe(
+            0,
+            "self-test が失敗した:\n".$process->output()."\n".$process->errorOutput(),
+        );
+    } finally {
+        File::deleteDirectory($tmp);
+    }
+});
+
+test('self-test が外から与えた BUGHUNT_SANDBOX を尊重し削除しないこと', function (): void {
+    // 「通ること」だけでは隔離境界の退行を検出できない。外から渡した sandbox が
+    // 実際に使われ (= その配下に成果物ができ)、かつ **消されない** ことを見る。
+    $tmp = makeSelfTestSandbox();
+
+    try {
+        $process = Process::timeout(120)
+            ->env(['BUGHUNT_SANDBOX' => $tmp, 'TMPDIR' => $tmp])
+            ->run(['bash', base_path('scripts/bug-hunt-shard.sh'), 'self-test']);
+
+        expect($process->exitCode())->toBe(0, $process->errorOutput());
+        expect(File::isDirectory($tmp))->toBeTrue('外部指定 sandbox が削除された (借り物を消してはならない)');
+        expect(File::isDirectory($tmp.'/tmp/bug-hunt'))->toBeTrue(
+            '外から与えた BUGHUNT_SANDBOX が使われていない (隔離境界をテストが握れていない)',
+        );
+    } finally {
+        File::deleteDirectory($tmp);
+    }
+});
+
+test('外部 sandbox はマーカーが無ければ拒否されること', function (): void {
+    // 「捨ててよい空き地」の証拠が無いディレクトリを受け付けると、/ や リポジトリルートを
+    // 渡された時に実資源へ書き込みうる。拒否そのものを固定する。
+    $dir = sys_get_temp_dir().'/bughunt-selftest-nomarker-'.bin2hex(random_bytes(6));
+    File::makeDirectory($dir, 0700, true);
+
+    try {
+        $process = Process::timeout(120)
+            ->env(['BUGHUNT_SANDBOX' => $dir, 'TMPDIR' => $dir])
+            ->run(['bash', base_path('scripts/bug-hunt-shard.sh'), 'self-test']);
+
+        expect($process->exitCode())->not->toBe(0, 'マーカー無しの外部 sandbox が受理された');
+        expect($process->errorOutput())->toContain('.bughunt-selftest-sandbox');
+    } finally {
+        File::deleteDirectory($dir);
+    }
+});
diff --git a/tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php b/tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php
new file mode 100644
index 0000000..427e6d1
--- /dev/null
+++ b/tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php
@@ -0,0 +1,118 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Process;
+
+/*
+ * setup-worktree.sh の実行時ファイル provisioning 契約。
+ *
+ * bug-hunt は worktree 走行が既定 (AGENTS.md) だが、.env.bughunt.local は .gitignore 対象で
+ * worktree には決して現れない。親からのコピーが唯一の供給路であり、無いと provision が必ず止まる
+ * (bug-hunt run 20260809-152048 で実際に踏み、手動 cp で回避した)。
+ *
+ * 秘密ファイルの複製なので **mode は 0600 に固定**する。親が 0644 のとき `cp -p` は
+ * world-readable な秘密ファイルを新たに作るため契約として弱く、`cp` → `chmod` の 2 段にも
+ * 「一瞬だけ広く読める窓」がある。`install -m 600` は作成時点で mode を確定する。
+ *
+ * setup-worktree.sh は top-level 実行型 (main() を持たない) なので、素朴に source すると
+ * composer install / pnpm install / DB 作成まで走る。SETUP_WORKTREE_SOURCE_ONLY で
+ * 関数定義だけ取り込んで抜ける guard を使う。
+ */
+
+/**
+ * setup-worktree.sh を source して provision_bughunt_env_file だけを叩く。
+ * 引数は位置引数で渡す (文字列連結による shell injection を避ける)。
+ */
+function runProvisionBughuntEnvFile(string $parent, string $worktree): int
+{
+    $result = Process::timeout(60)
+        ->env(['SETUP_WORKTREE_SOURCE_ONLY' => '1'])
+        ->run([
+            'bash', '-c',
+            'source "$1"; provision_bughunt_env_file "$2" "$3"',
+            '_',
+            base_path('scripts/setup-worktree.sh'),
+            $parent,
+            $worktree,
+        ]);
+
+    return $result->exitCode() ?? 1;
+}
+
+/** @return array{0: string, 1: string} [親, worktree] の一時ディレクトリ */
+function makeWorktreeFixture(): array
+{
+    $base = sys_get_temp_dir().'/setup-worktree-contract-'.bin2hex(random_bytes(6));
+    File::makeDirectory($base.'/parent', 0700, true);
+    File::makeDirectory($base.'/worktree', 0700, true);
+
+    return [$base.'/parent', $base.'/worktree'];
+}
+
+test('親に .env.bughunt.local があれば worktree へコピーされる', function (): void {
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
+
+        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
+        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeTrue();
+        expect(File::get($worktree.'/.env.bughunt.local'))->toBe("APP_ENV=bughunt.local\n");
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('親に .env.bughunt.local が無ければ何もしない (bug-hunt 非利用リポジトリで no-op)', function (): void {
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
+        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeFalse();
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('親が 0644 でもコピー先は 0600 になる', function (): void {
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
+        chmod($parent.'/.env.bughunt.local', 0644);
+
+        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
+
+        $mode = fileperms($worktree.'/.env.bughunt.local') & 0777;
+        expect(decoct($mode))->toBe('600', 'コピー先が world-readable になっている (cp -p / cp+chmod への退行)');
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('コピー先が既に存在しても上書き後に 0600 になる', function (): void {
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/.env.bughunt.local', "APP_ENV=new\n");
+        chmod($parent.'/.env.bughunt.local', 0644);
+        File::put($worktree.'/.env.bughunt.local', "APP_ENV=old\n");
+        chmod($worktree.'/.env.bughunt.local', 0666);
+
+        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
+
+        expect(File::get($worktree.'/.env.bughunt.local'))->toBe("APP_ENV=new\n");
+        expect(decoct(fileperms($worktree.'/.env.bughunt.local') & 0777))->toBe('600');
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('install -m 600 を使っていること (cp + chmod の 2 段へ退行していない)', function (): void {
+    // 2 段だと cp 直後から chmod までの間だけ world-readable な秘密ファイルが存在する。
+    $source = File::get(base_path('scripts/setup-worktree.sh'));
+
+    expect($source)->toContain('install -m 600 "${repo_root}/.env.bughunt.local"');
+});

```

---

## テスト結果

- `scripts/bug-hunt-shard.sh self-test`: all passed
- 非破壊 dry-run (`BUGHUNT_SELFTEST_DRYRUN=1 ... provision --shard 0`): exit 0
- `composer test`: 4114 tests / 4112 passed / 2 skipped / 17697 assertions
  (実装前は 4102 tests。新規 Architecture テスト 12 件ぶん増えている)
- `composer test:browser` chromium / webkit: 各 22 tests / 19 passed / 3 skipped / 149 assertions
- `composer phpstan`: No errors / `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1292) / `pnpm build`: passed
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106): passed

**fail-first の確認**:
- H-0: 「外から与えた BUGHUNT_SANDBOX を尊重し削除しない」テストが**実装 1 周目で赤くなった**。
  設計段階で「現行 self-test は sandbox を削除していない (trap も無い)」と書いたのは調査漏れで、
  実際には末尾に `rm -rf "${sandbox}"` があった。テストがそれを捕まえたので
  `sandbox_owned == 1` で囲む形に修正し、設計書にも訂正を追記した。
- H-1/H-2/H-3: self-test へ (y7)(y7x)(y8)(y9) を先に足して赤を確認してから実装した。
- H-4: 契約テスト 5 本を書いた後 `git stash` で実装を外し、**5/5 が落ちる**ことを実測した。

**実機 end-to-end は未実施**: 実 DB を伴う `provision-all --parallel=2` → `teardown --drop-db` は
禁止事項 3 (dev DB への破壊操作をエージェント判断で実行しない) によりエージェントでは走らせていない。
自動検証は self-test と非破壊 dry-run までである。

**設計からの逸脱 (自己申告)**:
1. `setup-worktree.sh` の切り出しを「実行時ファイルコピーのブロック全体を `provision_runtime_files()` で包む」
   ではなく、**`.env.bughunt.local` のコピーだけを `provision_bughunt_env_file()` に切り出した**。
   既存ブロックは `PROVISIONED_PATHS` 配列を触るため、全体を包むと既存挙動へ手を入れることになる。
   テストの目的 (composer install を走らせずにコピー契約を検証する) は満たしている。
2. 既存 self-test の構造検査 1 行 (`stop_shard_workers に kill -0 -- "-" があること`) を
   `group_stopped` を参照する形へ更新した。判定機構を移したため、旧文言のままでは
   「master 単体判定に戻さない」という意図を守れなくなるため。

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

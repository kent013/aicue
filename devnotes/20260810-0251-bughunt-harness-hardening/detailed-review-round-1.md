前提: 提供された設計書のみのレビューです。実ファイル・実行結果による確認はしていません。

**全体判定: CHANGES_REQUESTED**

方向性は妥当ですが、H-1 と H-4 に dev DB 防御・秘密ファイル複製まわりの詰め不足があります。実装前に直すべきです。

**H-0: REQUEST_CHANGES**

[Warning] `self-test` 実行時の sandbox がテスト側で強制されていません。  
設計文では「sandbox 内で完結」とありますが、Pest 側は単に `scripts/bug-hunt-shard.sh self-test` を呼ぶだけです。script 内部の初期化に依存すると、退行時に実 `tmp/` / `devnotes/` / `.env.bughunt.local` を触る余地が残ります。  
修正案: Pest 側で一時ディレクトリを作り、`BUGHUNT_SANDBOX` など self-test に必要な隔離 env を明示して起動してください。

[Warning] `Process` facade の import と実行方法が曖昧です。  
`use Illuminate\Support\Facades\Process;` を明示し、スクリプトの executable bit に依存しないなら `['bash', $script, 'self-test']` で起動してください。executable 前提なら `toBeExecutableFile()` も見るべきです。

**H-1: REQUEST_CHANGES**

[Critical] procfs 走査が「読めない・解釈できない」を 0 件扱いに倒す設計です。  
`cat /proc/.../stat` 失敗や想定外形式を race として無視するのは、消滅 race には妥当ですが、既知の worker PGID を検証している局面では fail-open になり得ます。特に dropdb 直前判定では「確認不能」は DB を消さない側に倒すべきです。  
修正案: `group_member_counts` は `live zombie unknown` を返す、または既知 PGID に対して `kill -0 -- -PGID` が成功しているのに procfs で 1 件も確証できない場合は `group_stopped=false` にしてください。

[Critical] `BUGHUNT_STOPPED_PGIDS` のスコープ設計が不足しています。  
「1 回目に記録した pgid 群」とありますが、shard ごと・teardown 呼び出しごとに初期化される保証が書かれていません。前 shard の PGID が混ざると不要な dropdb 抑止、逆に記録漏れがあると再確認の意味が薄くなります。  
修正案: `local -a stopped_pgids=()` を `cmd_teardown` の shard ループ内で持ち、`stop_shard_workers "$shard" stopped_pgids` のように明示的に受け渡す設計にしてください。

[Warning] fixture テスト計画と実装案が噛み合っていません。  
`group_member_counts` は `/proc/[0-9]*/stat` 固定なので、`comm` に空白や `)` を含む fixture を直接検証できません。テスト側で同じパースを複製すると、実装の検証になりません。  
修正案: `parse_proc_stat_line()` を分離して fixture で検証するか、`PROC_ROOT` を self-test 時だけ差し替え可能にしてください。

[Warning] 関数名が揺れています。  
施策説明では `group_live_members()`、実装案では `group_member_counts()`、受入条件では「group_live_members 相当」です。  
修正案: 名前を 1 つに統一し、受入条件も同じ名前で固定してください。

**H-2: APPROVE**

大筋問題ありません。`BUGHUNT_SHARD_CAP` から算術ループで導出する方針は、cap 変更時の guard ずれを減らします。

[Suggestion] self-test では `SHARD_RE` と `SHARD_DB_RE` の両方を再導出して確認してください。DB 名だけでなく shard 入力検証も同じ cap に従うことを固定できます。

**H-3: REQUEST_CHANGES**

[Warning] raw `dropdb` 検出の仕様が弱いです。  
「raw `dropdb` 呼び出しが無いことを走査」とだけあるため、コメント・許可済み wrapper 内・文字列リテラルをどう扱うかが不明です。雑な grep だと偽陽性か、逆に `command dropdb` / 変数経由を見落とします。  
修正案: 非コメントの実行行だけを対象にし、許可位置を `pg_admin_for_provision()` 内の `op_cmd=(dropdb --if-exists ...)` 1 箇所に限定する inventory にしてください。

[Warning] `optimize:clear` の安全性は「検出」であって「証明」ではない、という注記は正しいです。ただし受入条件 17 は `$optimizeClearCommands` の集合増加しか検出しません。既存 allowlist コマンドの内部実装が DB 接続を始めても赤くなりません。  
修正案: 設計書のリスク欄にもこの限界を明記し、allowlist の rationale は package version 更新時に再確認する運用にしてください。

[Warning] `env -i PATH="${PATH}" HOME="${HOME}"` は `set -u` 下で `HOME` 未定義だと落ちます。  
修正案: `HOME="${HOME:-/tmp}"` のように既定値を置くか、既存スクリプトの env 初期化規約に合わせてください。

**H-4: REQUEST_CHANGES**

[Critical] `cp` → `chmod 600` には短い world-readable 窓があります。  
親ファイルが `0644` で umask も緩い場合、コピー直後から chmod までの間だけ秘密ファイルが広く読めます。秘密ファイルの複製施策としては弱いです。  
修正案: `install -m 600 "${REPO_ROOT}/.env.bughunt.local" "${WORKTREE_DIR}/.env.bughunt.local"` を使うか、`umask 077` 下で一時ファイルへコピーしてから rename してください。

[Critical] `setup-worktree.sh` を `source` して関数テストする前提が危険です。  
現行スクリプトは top-level 実行型に見えるため、`bash -c 'source ...; provision_runtime_files ...'` が composer install / pnpm install / DB 作成まで進む恐れがあります。契約テストが重い副作用を避けるという目的と矛盾します。  
修正案: `main()` 化して `[[ "${BASH_SOURCE[0]}" == "$0" ]] && main "$@"` にする、または `SETUP_WORKTREE_SOURCE_ONLY=1` のような source 専用 guard を冒頭で処理してください。

[Warning] `.env.bughunt.local` 以外の秘密ファイルとの扱い差が残ります。  
既存の `.env` や `storage/oauth-private.key` は今回の主対象外ですが、同じ runtime file provision に入るなら権限契約の差分が目立ちます。  
修正案: 少なくとも新規関数のコメントで「今回 0600 を固定する対象は `.env.bughunt.local`」と限定し、既存ファイルの権限変更は別施策に分ける判断を明示してください。
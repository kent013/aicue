全体として Round 1 の主要指摘はかなり改善されていますが、H-1 の新しい fail-closed 条件に対応するテスト不足と、raw `dropdb` 走査の見落としが残っています。

## H-0: REQUEST_CHANGES

[Warning] `TMPDIR` の指定だけでは、sandbox 化が退行した際に実リポジトリを保護できません。

`self-test` が `mktemp` 自体を使わなくなり、`tmp/` や `devnotes/` を直接参照する退行では、`TMPDIR` は効きません。静的テストも文字列の存在確認なので、実際にパス差し替えへ使われていることまでは保証しません。

修正案: Pest 側から `BUGHUNT_SANDBOX=$tmp` を明示して起動し、self-test は「未指定なら自分で `mktemp -d`、指定済みならその sandbox を使う」契約にしてください。これなら実行テスト自身が隔離境界を握れます。

[Suggestion] 後始末の `exec('rm -rf ...')` は、Laravel の `Filesystem::deleteDirectory()` などへ置き換えると、Process facadeを選んだ方針とも整合します。

## H-1: REQUEST_CHANGES

指定された3点についての判定は次のとおりです。

- `live > 0`: 適切に fail-closed
- `unknown > 0`: 適切に fail-closed
- `kill -0` 成功かつ確認メンバー0件: 従来の明確な fail-open は閉じている
- namerefによる受け渡し: グローバル配列のスコープ問題を解消している
- `parse_proc_stat_line` の分離: 実装関数自体をテストできる構造になっている

ただし、以下が残ります。

[Warning] 新設した fail-closed 条件に、明示的な受入テストがありません。

20件の対応表には次のケースがありません。

- パース不能行があると `unknown > 0` になり、`group_stopped` が失敗する
- `live=0 zombie=0 unknown=0` でも、`kill -0 -- -PGID` が成功すれば失敗する
- `group_member_counts` 自体が不正な出力や空出力になった場合の扱い

このままでは、今回の中核修正が削除されても既存20条件がすべて緑になる可能性があります。

修正案: 受入条件を追加するか、少なくとも補助的な self-test `(y7k)(y7l)` として、`group_member_counts` と `kill` を stub し、上記2条件を直接固定してください。また、`read` 後に3値が非負整数であることを検査し、不正なら停止失敗へ倒してください。

[Warning] `kill -0` の補完条件には、zombieを1件以上観測した場合の走査raceが残ります。

現在の条件は次の場合に成功します。

```text
procfs走査結果: live=0 zombie=1 unknown=0
走査中または直後: 同じPGIDにlive memberが出現
kill -0: 成功
```

`zomb != 0` なので条件(c)を通らず、停止済みになります。process groupへの新規参加が実際に不可能であることを別の不変条件で保証できない限り、これは残存TOCTOUです。

修正案: 「確認総数0」の特殊条件ではなく、走査前後の両方でgroup存在を確認し、必要なら連続2回のprocfsスキャンが同じくlive=0であることを停止成功条件にしてください。それでもTOCTOUを完全には証明できないため、「窓を縮小する検出」であることは維持してください。

[Warning] `group_member_counts` の `unknown` は対象PGID以外の不正行も数えます。

パース不能時はpgrpを特定できないため、安全側として合理的ですが、ホスト上の無関係な1プロセスの異常で全shardのteardownが停止します。これは安全性の問題ではなく可用性上の保証範囲です。

修正案: この意図をコメントとリスク欄へ明記し、テストでも「無関係か判別不能な行は全体を停止させる」契約を固定してください。

[Suggestion] `(y7a)` の説明はまだ「`group_member_counts` のパースをfixtureで検証」となっています。実際に叩くのは `parse_proc_stat_line` なので記述を合わせてください。またリスク欄の `cut -d` は実装から消えているため削除対象です。

## H-2: APPROVE

算術ループへの変更と、`SHARD_RE` / `SHARD_DB_RE` の双方をテスト用capで再導出する計画は妥当です。

## H-3: REQUEST_CHANGES

[Warning] raw `dropdb` の正規表現は、一般的なbashのコマンド位置を網羅していません。

提示された式では、例えば次を見落とします。

```bash
if dropdb ...; then
while dropdb ...; do
then dropdb ...
do dropdb ...
! dropdb ...
exec dropdb ...
env PGUSER=x dropdb ...
```

特に「変数経由も見落とさない」とした説明に対し、正規表現は変数展開やwrapper経由を検出できません。したがって、偽陽性は減っていますが、見落としを避けられたとは判定できません。

修正案: 保証を「literalな直接呼び出しの検出」に限定してください。そのうえで、非コメント行から許可されたcase配列行を除き、単語境界の `dropdb` / `createdb` が残らないことを検査する方式が、このスクリプトではより保守的です。文字列リテラルを許可する必要がある場合は、行単位の理由付きallowlistに固定してください。変数・関数・`env`経由まで証明するなら、正規表現ではなくbash AST相当の解析が必要です。

inventoryの保証限界、`HOME="${HOME:-/tmp}"`、`--except=cache` の固定は妥当です。

## H-4: APPROVE

`install -m 600` は、提示された `cp` 後の権限変更という設計上の窓を解消しています。既存ファイル上書き時も最終modeを固定できます。

source専用guardも、次の点でRound 1の問題を解消しています。

- `BASH_SOURCE[0] != "$0"` により通常実行では早期returnしない
- 関数定義後、引数検査やworktree作成前にreturnする
- `REPO_ROOT` / `WORKTREE_DIR` を引数化している
- 呼び出しごとに対象ディレクトリを明示できる

契約テストでは、`SETUP_WORKTREE_SOURCE_ONLY=1 bash -c 'source "$1"; ...'` のようにスクリプトパスとディレクトリを位置引数で渡し、文字列連結によるshell injectionを避けてください。

## 実装・検証計画: REQUEST_CHANGES

[Critical] 手順7の実DB `dropdb` 実走は、エージェントの実装手順としては禁止事項3に抵触します。

wrapper、DB名guard、admin roleを通っていても、「エージェント判断で破壊操作を実行しない」という上位制約は解除されません。

修正案: 手順7を「人間による明示承認後の実機確認」または「ユーザー実施の手動受入確認」に変更してください。エージェントが自動で行う検証はself-testと非破壊的なdry-runまでに限定します。

## 全体判定: CHANGES_REQUESTED

Round 1のCritical 4件そのものは、H-1の残存raceを除けば意図どおり改善されています。承認までに必要なのは主に次の3点です。

1. H-1の新しいfail-closed分岐を直接テストする  
2. raw `dropdb` 検査の保証をliteral検出へ限定するか、走査方式を強化する  
3. 実DB削除を人間承認の手動確認へ変更する
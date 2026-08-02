# bug-hunt 仕様台帳 (spec-ledger) — 既知仕様 / 誤検知の申し送り

このファイルは、過去の bug-hunt run で挙がった finding のうち **実コード裏取り + 敵対的検証の結果
「仕様 (SPEC)」または「ドキュメント側対応 (DOC)」と確定したもの**を記録する、人間可読の申し送り台帳。

機械 registry (`ledger/adjudications.jsonl`) の**対**である:

| | 正本 | 読み手 | 効果 |
|---|---|---|---|
| `ledger/adjudications.jsonl` | cross-session の**機械判定** | validator (`--annotate`) | 4-gate 一致で annotate + downrank |
| `spec-ledger.md` (本ファイル) | cross-session の**人間向け申し送り** | bug-hunt 実行者 (親 / 子 shard) | 「再起票しない」判断の根拠を渡す |

同じ説明文を両方に重複させない。機械照合が要るものは registry に、
「なぜ SPEC と確定したか」の物語は本ファイルに書く。

> **現状: 中身は空**。AI-CUE の実 run から書き起こす。
> 旧 registry の spirux 由来 18 件は AI-CUE に実在しない資産を指していたため削除済み
> (理由は `ledger/README.md` 運用ガード (d))。**他アプリの申し送りを写さない**。

---

## 使い方 (bug-hunt 実行者へ)

- finding を起票する前に本台帳を検索すること。**ここに SPEC として載っている事象は再起票しない**
  (「既知仕様」と一行記録して次へ)。
- 同一事象が再発したと感じたら、台帳の**根拠 (file:line)** を実コードで確認する。
  コードが台帳と乖離していれば **regression** の可能性があるので、その差分を根拠に新規 finding を起票してよい。
- DOC 項目は「コード正本は正しく、bug-hunt 側カード / 正本ドキュメントの記述が陳腐化していた」もの。
  該当カードが修正済みかを確認する。
- 「要確認」を SPEC に確定する判断は、**設計文書 (devnotes/docs)・実コード・テストの三点**で
  裏が取れた場合のみ。取れないものは台帳に載せず「要確認」のまま残す。
- **SPEC / DOC 確定項目には根拠 (file:line) を必ず併記する**こと。後続実装で仕様が変わった場合、
  記述と実コードが乖離するため、台帳の腐りを早期に発見できる。
- 機械照合させたい (次 run で自動 downrank したい) 項目は、本ファイルに書いたうえで
  `ledger/adjudications.jsonl` にも 1 行足す。手順は `ledger/README.md` 運用ガード (c)。

## 書式ルール

- **append-only + supersede**。既存の確定項目を黙って書き換えない。撤回するときは
  「実装で解消 (旧 SPEC を撤回)」節を作り、**撤回した事実と根拠**を残す。
- run 単位の節 (`## run {run_id} 申し送り ({date})`) を**新しい run が上**になるよう積む。
- 節の中は `### SPEC 確定 (再起票しない)` / `### DOC 確定` / `### 実装で解消 (旧 SPEC / accepted を撤回)`
  / `### CLOSED (非再発を確認)` に分ける。

---

## 初回登録テンプレート

新しい run の申し送りを書くときは、以下をコピーして埋める。**欄を削らない**
(埋められない欄がある = 三点裏取りが済んでいない ので、その項目は台帳に載せない)。

```markdown
## run {run_id} 申し送り ({YYYY-MM-DD})

### SPEC 確定 (再起票しない)

#### {finding_id} — {事象を 1 行で。何が「バグに見えた」か}
- **判定**: SPEC (意図仕様) | DOC (ドキュメント側の陳腐化)
- **根拠 (file:line)**: `path/to/File.php:123` (何をしているか) /
  `resources/js/pages/Foo/Bar.svelte:45` / `AGENTS.md#anchor` / `tests/Feature/FooTest.php`
  ※ 設計文書・実コード・テストの三点。**実在するパスのみ**書く
- **なぜ誤検知に見えたか**: {fake mode / 観測窓 / viewport 等、bug-hunt 側の事情}
- **watch_globs (機械 registry に載せる場合)**: `path/to/File.php`, `resources/js/pages/Foo/Bar.svelte`
  ※ この判定を無効化しうる実在ファイルのみ。過広 (`app/**` 等) 禁止
- **review_after_days**: {int > 0。仕様の揺れやすさで決める。例 120 / 180}
- **確定した run_id**: {run_id} (commit {short_sha})
- **再オープン条件**: {どうなったら再び finding として起票してよいか}
- **機械 registry**: `ledger/adjudications.jsonl` の `A-NNN` に登録済 / 未登録 (理由: …)
```

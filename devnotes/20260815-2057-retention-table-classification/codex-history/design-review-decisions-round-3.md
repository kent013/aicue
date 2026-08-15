# 対応マトリクス: design-review Round 3

## [Warning] `set null` は参照元列が nullable でなければ親削除が成立しない
- 判断: **対応する** (nullable まで検査する側を採る)
- 根拠: 指摘のとおり。`ON DELETE SET NULL` が宣言されていても外部キーの列に `NOT NULL` が
  付いていれば親の削除は制約違反で失敗し、結果は `restrict` と同じ (親の期限の執行を止める)。
  RC-7 の目的が「期限の執行を妨げる構造」と「子も消える構造」を拾うことである以上、
  ここを前提にして検査しないのは目的と一貫しない。
  非保証事項へ逃がす案もあるが、**判定に使っている推論そのもの**を保証しないと書くのは
  検査の根拠を空にすることになるので採らない。
- 対応内容:
  - 外部キーの一覧に `columns` を残した
    (`array{foreign_table: string, columns: list<string>, on_delete: string|null}`)。
  - `retentionNullableColumnMap(list<string> $tables): array<string, array<string, bool>>` を足し、
    **「基準データ」「基盤が寿命を持つ」に分類した表だけ**に絞って `getColumns()` を引く
    (全表を引く必要が無い。十数表で済む)。
  - 純関数 `retentionHorizonParentViolations()` を 3 引数にし、
    `set null` は**外部キーの列がすべて nullable のときだけ**非違反にする。
    複合外部キーがあるので列を 1 つずつ見る。
  - テスト計画に **NC-5** (`set null` + `NOT NULL` 列で点灯する) を追加し、
    正のコントロールを「`set null` + nullable 列では点灯しない」に直した。
  - `Builder::getColumns()` の戻り値 shape に `nullable: bool` があることを vendor 実物で確認した
    (Builder.php 397 行)。

## [Suggestion] `restrict` / `no action` の説明を正確にする
- 判断: 対応する
- 対応内容: 「親を消せなくする」→
  「削除対象の親行が子から参照されていれば**親の削除を拒否する** → 親の期限の執行を止めうる」
  に書き換えた。

## [Suggestion] `set default` の説明を正確にする
- 判断: 対応する
- 対応内容: 「子は既定値になって残る」と断定せず、
  「既定値への置換を試みるが、その値が外部キー制約を満たさなければ親の削除は失敗する。
  本リポジトリに 1 本も無いため、現れたら分類の見直しが要るものとして保守的に違反へ倒す」
  に書き換えた。

## [Warning] 施策 5 (運用文書) も `set null` を無条件に「子が残る」と書かない
- 判断: 対応する
- 対応内容: docs へ書く内容を「**列がすべて nullable な `set null`** だけが矛盾ではない」に直し、
  「`set null` でも列に `NOT NULL` が混ざれば親の削除は失敗するので違反にする」を併記する
  指示にした。`llm_call_logs` / `security_audit_events` は nullable な実例として引き続き載せる。

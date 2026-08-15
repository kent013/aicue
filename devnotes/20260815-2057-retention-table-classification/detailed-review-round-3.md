前提仮説は「RC-7は、親削除時の外部キー動作から、子表の分類と親の期限執行との明白な構造矛盾を検出する」です。この観点では、`set default`を保守的に違反とする判断は妥当ですが、`set null`には列のnullable性という条件が残っています。

## 質問への回答

1. `cascade`、`restrict`、`no action`、`set default`、`null`の扱いは妥当です。
2. `set null`を無条件に非違反とする点には、残る [Warning] があります。
3. 残る [Critical] はありません。

## 施策1: 区分 enum と値オブジェクト

**判定: APPROVE**

`hasHorizon()`が削除期限の実在を保証しないという追記により、述語の保証範囲は適切になりました。

## 施策2: 全表の分類台帳

**判定: APPROVE**

`llm_call_logs`と`security_audit_events`を、親削除後も残るが保持期限は未決という理由で`Undecided`に置く判断は、実際の`nullOnDelete()`と整合しています。

## 施策3: 実スキーマとの照合 gate

**判定: REQUEST_CHANGES**

[Warning] `set null`は、参照元列がnullableでなければ親削除時に成功しません。

PostgreSQLではFKに`ON DELETE SET NULL`が宣言されていても、対象列に`NOT NULL`制約があれば、実際の親削除はNOT NULL違反で失敗し得ます。したがって、現在の次の推論は一般には成立しません。

> `set null` = 子は列が空になって残る → 違反にしない

`llm_call_logs`など現在の実例ではnullableなので正しい一方、将来誤ったmigrationが追加された場合、RC-7は無音で通します。

修正案は、`set null`を非違反にする条件を「FKの対象列がすべてnullable」に限定することです。外部キーmapに`columns`を残し、`Builder::getColumns()`から得たnullable情報を純関数へ渡します。

概念上は次の判定になります。

```php
set null && 参照元FK列がすべてnullable
    => 非違反

set null && NOT NULL列を含む
    => 違反
```

複合FKも考慮し、対象列を一つずつ検査してください。

負のコントロールには以下を追加すると境界が固定されます。

- nullable列の`set null`は点灯しない
- NOT NULL列の`set null`は点灯する

検査範囲を増やさない判断も可能ですが、その場合は少なくとも「`set null`対象列がnullableであることは前提であり、RC-7は検査しない」と保証しないものへ明記する必要があります。ただしRC-7が親削除阻害を検出する目的なら、nullable性まで検査する方が一貫しています。

[Suggestion] `restrict` / `no action`の説明は「親を消せなくする」ではなく、より正確に次のようにしてください。

> 削除対象の親行が子から参照されていれば、親の削除を拒否する

表内の全親行が必ず参照されているわけではないためです。判定を保守的に違反へ倒す方針自体は妥当です。

[Suggestion] `set default`を違反とする判断は妥当です。ただし「子は既定値になって残る」と断定せず、次の表現が正確です。

> 既定値への置換を試みるが、その値がFK制約を満たさなければ親削除は失敗する。現在利用例がないため分類レビューを要求する。

## 施策4: 既存 gate との責務境界

**判定: APPROVE**

RC-7の変更は課金保持期限gateとの責務境界を変えません。表集合だけをRC-4で結線する構造も維持されています。

## 施策5: 運用文書

**判定: REQUEST_CHANGES**

[Warning] `docs/architecture.md`でも、`set null`を無条件に「子が残る」と説明しないよう修正が必要です。

修正案は、RC-7の実装方針に合わせて次のどちらかを記載することです。

- nullable性も検査する場合: 「対象列がすべてnullableの`set null`だけを非違反とする」
- nullable性を検査しない場合: 「対象列のnullable性を前提とし、RC-7は検査しない」

現在の`llm_call_logs`と`security_audit_events`は、nullableな実例として引き続き記載できます。

## 施策6: 規約への登録

**判定: APPROVE**

`on delete`の詳細をAGENTS.mdへ複製せず、`docs/architecture.md`へ委譲する方針は適切です。

## 全体判定

**CHANGES_REQUESTED**

`set default`を保守的に違反へ倒す判断は妥当です。残る修正点は、`set null`が実際に成立するためのnullable条件です。

これをRC-7で検査するか、非保証事項として明示的に境界外へ出せば、設計上のWarningは解消できます。
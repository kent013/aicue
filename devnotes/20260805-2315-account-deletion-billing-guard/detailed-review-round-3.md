## 各施策判定

### 施策 1: 課金責務 guard

**APPROVE**

`Assert::integer()`、入力契約、N+1 の判断記録まで明確です。

### 施策 2: 理由・action・DTO

**APPROVE**

action の導出順、重複排除、要約ラベルの責務が実装可能な粒度で定義されています。

### 施策 3: MembershipService

**APPROVE**

通常経路のロック下再評価と webhook race の限界が正確に区別されています。要約文字列への集約も妥当です。

### 施策 4: Inertia Props

**APPROVE**

blocker の詳細と操作導線を再評価済み props が担う構成は適切です。

### 施策 5: Settings UI

**APPROVE**

`withAllErrors` を全体変更せず、サーバ側で単一メッセージに集約する判断は妥当です。既存のエラー表現に影響を広げず、詳細を構造化 props に分離できています。

### 施策 6: 検知バッチ

**APPROVE**

closure DI、`RuntimeException` の global 解決、PII 制限、集約報告とも問題ありません。

### 施策 7: テスト

**REQUEST_CHANGES**

[Warning] vitest の #30 が更新後の設計と矛盾しています。

現在の記載:

> `errors.account` が配列（複数行）なら全行を表示

施策 5 では「現行の先頭1件表示を維持し、配列表示への変更は入れない」と明記されています。

修正案: #30 を次の契約へ変更してください。

> `errors.account` の単一要約文字列を danger Alert に表示する。複数 blocker の詳細は `accountDeletionBlockers` の警告に全件表示する。

[Warning] transport テスト #16b は、要約文字列についても session MessageBag ではなく、後続の `GET /settings` の実際の Inertia propsまで検証することを明記してください。

修正案: 同じ `assertInertia` 内で以下を固定します。

- `errors.account` が単一文字列
- その文字列に両組織の必要対応を含む
- `accountDeletionBlockers` が2件
- 各 blocker の action が期待どおり

### 施策 8: ドキュメント

**APPROVE**

保証範囲、検知層、外部仕様、テスト参照方法が適切に整理されています。

## 全体判定

**CHANGES_REQUESTED**

残る Critical はありません。実装設計自体は着手可能な水準です。ただし、テスト計画に旧設計の #30 が残り、transport テストの検証地点にも曖昧さがあります。この2点を文面修正すれば **APPROVED** と判断できます。
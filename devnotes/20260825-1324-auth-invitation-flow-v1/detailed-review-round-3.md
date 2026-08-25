## 施策A — APPROVE

Round 2の指摘は解消されています。

- 状態注入テストとSQL形状テストの責務が分離されている
- `organizations`対象、SoftDeletes条件、`FOR UPDATE`、organization ID bindingの4条件により、既存の`lockForMembershipWrite()`と1cを区別できる
- 非ロック`exists()`への退行がSQL形状assertで検出される
- 実際の並行実行ではなく「最終再検証の消費契約」とする保証範囲が正確
- DBエンジン固有のMVCCスケジュールを保証しない点も明示されている
- 状態・membership・`accepted_at`・fallback・verifiedまで結合して検証される

### [Suggestion] SQL観測は二段階状態として実装すると明確

one-shot注入後にも1cのSQLを記録する必要があるため、callback全体を一度で無効化せず、例えば次の状態を分けると安全です。

- `injected`: 削除注入済み
- `lockedOrganizationQuery`: 1cの対象SQLを観測済み

または、注入用`beforeExecuting`と記録用listenerを分けても構いません。実装上の注意であり、設計承認を妨げません。

---

## 施策B — APPROVE

Round 2のSuggestionも適切に反映されています。

単引用符の`\\`と`\'`が隣接する入力を追加したことで、手動アンエスケープ処理の置換順による誤復元を検出できます。既存の以下の保証も維持されています。

- 単引用符・二重引用符・16進エスケープの検出
- コメントの非検出
- 走査根、読み取り、構文解析、母集団のfail-closed
- 保証外構文の明示
- Sessionの同一インスタンス利用
- 旧resolverの削除

追加変更は必要ありません。

---

## 施策C — APPROVE

Round 2の指摘は解消されています。

redirectを一段ずつ検証することで、次の経路が個別に固定されます。

1. 登録POST → `app.entry`
2. `app.entry` → 招待組織dashboardへ直接redirect
3. dashboard → 200

これにより、途中に`verification.notice`が挟まれば2段目で失敗します。最終到達先だけを見る`followRedirects`の偽グリーンは解消されています。

JSONケースについても、201に加えてmembership成立と`email_verified_at`非nullを確認するため、通常登録が偶然201を返しただけのケースと区別できます。

なお、提示本文では施策Cの改訂節全文自体は掲載されていませんが、対応マトリクスにテスト手順と観測点が具体的に確定されているため、設計判断に必要な内容は充足しています。

## 全体判定

**APPROVED**

CriticalおよびWarningの残件はありません。実装ロジック、テスト観測点、保証範囲の記述が整合しており、A → B → Cの順で実装へ進められる状態です。
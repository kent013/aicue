# 対応マトリクス: conceptual-review Round 3

## [Critical] 列挙とロックの間に対象ユーザーが未列挙組織 B の Owner になる race が残る
- 判断: 対応する
- 根拠: 妥当。組織行ロックだけでは「列挙時点で未知の組織」を保護できない。`transferOwnership(from=X, to=U)` が組織 B の Owner を削除対象 U へ移譲し、その間に `deleteAccount(U)` が {A} だけを列挙・削除すると B が孤児化する。Owner をユーザーへ付与する唯一の経路は `transferOwnership` なので、両者を User 行で直列化すれば塞げる。
- 対応内容: canonical ロック順序を **`users` 行(id 昇順) → `organizations` 行(id 昇順)** に拡張。helper を `lockForMembershipWrite(array $userIds, array $organizationIds)` とし、`deleteAccount` は対象 User 行を先にロック→所属組織列挙→組織行ロック→述語再評価。`transferOwnership` は from/to の 2 User 行 + 組織行をロック。これで移譲側と削除側が User 行で直列化する。

## [Warning] race が残る間は効果を保証できない / 「列挙と同時移譲」の並行テストを追加せよ
- 判断: 対応する（Critical 対応で解消）＋ テスト方針は反論（構造担保）
- 根拠: 上記 User 行ロックで race は構造的に消える。真の並行テストは `RefreshDatabase`（テストを単一トランザクションで包む）下では複数コネクションの race を決定的に再現できず現行ハーネスの範囲外。既存 `transferOwnership` のロックも race テストでなく構造で担保されている。
- 対応内容: 並行正当性は canonical 順序の `lockForUpdate`（構造）で担保し、drift-guard Architecture テストがロック規約の適用漏れを検出。Feature テストは論理述語を検証。この方針を設計に明記。

## [Suggestion] SecurityEventRecorder txn 内記録 / logout 順序 / 型安全性 / スコープ
- 判断: 反論受け入れ済み・肯定コメント（対応不要）

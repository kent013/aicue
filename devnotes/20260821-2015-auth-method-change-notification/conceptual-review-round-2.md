## 全体判定: CHANGES_REQUESTED

Round 1 のうち、初回パスワード設定、通知窓口、SSO、配信先、2FA 二重送信、型安全性の方向は概ね解消しています。ただし、パスキー削除の rollback 整合性は提示された自己検証方式では完全には解消していません。

### [Critical] パスキーの「不存在確認」は当該 transaction の commit 証明にならない

`terminating()` 時点の再クエリには TOCTOU があります。

例えば次の順序です。

1. リクエスト A が削除イベントを発火
2. A の transaction が rollback し、パスキーが復元
3. termination 前にリクエスト B が同じパスキーを削除して commit
4. A の callback が「存在しない」と判定して通知
5. B も通知し、同じ削除について二重通知

この場合、状態としてパスキーは削除されていますが、A の transaction が成立したことを証明していません。「各操作 1 回につき 1 通」という提示されたテスト方針とも一致しません。また通知時刻を A のイベント時刻として保持するなら、実際の変更時刻ともずれます。

修正案: `PasskeyDeleted` listener は transaction 内で通知 intent のみをリクエストスコープへ記録し、`EnsureLoginMethodRemains` の `DB::transaction()` が正常復帰した直後に flush してください。例外時は flush されないため、commit 成否を推測する再クエリが不要になります。

middleware に通知固有の知識を入れたくない場合は、middleware が汎用的な「transaction 正常完了後の request-local callback/intents」を flush し、具体的な通知内容は listener 側に閉じ込められます。ただし汎用基盤を広げすぎず、この経路だけに限定すべきです。

少なくとも以下を Feature テストで固定する必要があります。

- 正常 commit: 1 件
- イベント発火後の例外・rollback: 0 件
- 同一パスキーへの競合削除: 合計 1 件
- response 生成時の例外: 0 件

### [Warning] transaction 監査が詳細設計への先送りになっている

「他イベントは transaction 制約下にない」という Round 1 の Warning は、まだ完全には解消していません。対応表を詳細設計で作る方針は妥当ですが、概念設計では既に以下の直接呼び出しを確定しています。

- `PasswordCredentialService::afterPersist()`
- `SocialAccountService::linkToUser()`

これらが transaction 内の場合、`after_commit=false` の database queue へ投入する問題が再発します。また `try/catch` しても、同じ DB connection の SQL エラーによって transaction 自体が失敗状態になれば、元操作の成功を保証できません。

修正案: 概念設計の前提として、少なくとも両経路について「永続化 commit 後である」または「request-local intent 経由にする」のどちらかを確定してください。発火点対応表は実装直前の補足ではなく、方式選択の入力です。

### [Warning] enum の件数が一致していない

列挙された対象は 9 種です。

1. パスワード設定
2. パスワード変更
3. パスワードリセット
4. 2FA 有効化
5. 2FA 無効化
6. 回復コード再発行
7. パスキー追加
8. パスキー削除
9. SSO 連携

「8 種」とするなら、例えばパスワード設定と変更を同じ enum case にまとめる設計判断が必要です。

修正案: 利用者へ表示する通知種別と内部の発火原因を分けて整理し、実際の enum case 名を概念設計に列挙してください。件数だけの修正でも構いませんが、設定と変更で本文が異なるなら 9 case が自然です。

### [Warning] `Notification::fake()` だけでは queue 投入を証明できない

`Notification::fake()` は通知送信を横取りするため、「正しい enum の通知が選ばれたこと」は検証できますが、実際に database queue 用ジョブへ変換されたことの証明とは分ける必要があります。

修正案:

- イベント → enum の対応: `Notification::fake()`
- 通知が queueable であること: `ShouldQueue` の Architecture/Unit テスト
- enqueue 失敗を元操作へ波及させないこと: Notifier の例外テスト
- transaction 成否と投入件数: 実経路の Feature テスト

という役割分担を詳細設計に明記してください。

### [Suggestion] 再クエリを残す場合の tenant 境界

仮に不存在確認を補助的な防御として残すなら、クラス起点の主キー検索は避け、対象ユーザーの passkey relation 経由で確認する必要があります。提示された `ModelDirectFetchInvariantTest` と cross-org 不変条件への登録・分類も必要です。

そのほかの Round 1 指摘については、以下の対応で解消したと判断します。

- 初回パスワード設定を通知対象へ追加
- best-effort 契約を専用 Notifier へ集約
- 2FA と回復コード再発行が別操作であることの vendor 根拠
- 配信先を送信時点の現登録メールに確定
- 未実装の SSO 解除を今回作らない
- SSO 新規登録と既存アカウントへの追加連携を区別
- subscriber のイベント対応を Feature テストで固定

Critical の commit 判定方式を置き換え、transaction 発火点を確定すれば APPROVED にできます。
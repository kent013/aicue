全体判定: CHANGES_REQUESTED

Round 1 の Critical だった「`recovery_pending` が通常の `claim()` から再実行される問題」は解消しています。`claimStale()` の世代更新として `attempts` を使う方向も妥当です。

ただし、トランザクション内の通知と未知イベントの扱いが未定義のため、現状のままでは承認できません。

### 1. 使命との整合性

[Suggestion] クラッシュ滞留した付与イベントの回収は、チケットを撮影・レンダリングの実行権としている AI-CUE の使命に直接貢献します。

「付与漏れを全部消す」ではなく「クラッシュ滞留の1経路を塞ぐ」と限定した期待効果も妥当です。

### 2. 禁止事項違反

[Warning] 必要なテストが実装方針に明記されていません。

禁止事項1とテストファースト規約を満たすには、少なくとも次を概念設計の変更対象へ追加してください。

- SafeToReplay の滞留行が一度だけ再処理される Feature テスト
- OrderSensitive が `recovery_pending` になり、通常再送でも再処理されないテスト
- 上限到達行が再処理されないテスト
- 元 worker と回収 worker の終局書き込み競合テスト
- 回収中の再クラッシュを次回回収できるテスト
- 未知の `type` を自動再実行しないテスト
- `ModelDirectFetchInvariantTest` / `DirectFetchInventory` の登録

修正提案: 実装方針表に Feature/Architecture テストを変更対象として明記してください。

### 3. 実現可能性

[Critical] `report()` と `Log::warning()` を `claimStale()` のトランザクション内で実行する設計は不適切です。

DB の commit が失敗・再試行された場合、状態が保存されていないのに通知だけ送られたり、同じ行について複数回通知されたりします。「1行につき1回」の保証になりません。

修正提案: `claimStale()` は型付き結果だけを返し、commit 後に呼び出し側が通知してください。例えば以下の結果型が考えられます。

- `ClaimedForReplay`
- `MovedToRecoveryPendingOrderSensitive`
- `MovedToRecoveryPendingAttemptsExhausted`
- `Skipped`

状態遷移が実際に成功した呼び出しだけが、トランザクションの外で `report()` します。

[Warning] `recoverStale(): int` の件数の意味が不明確です。

自動再処理した件数なのか、`recovery_pending` へ移した件数なのか、正常終了した件数なのかで運用上の意味が変わります。

修正提案: 戻り値を型付き集計 DTO にするか、`int` を維持するなら「claim に成功して処置を確定した行数」など契約を明記してください。Artisan の終了コードとは分離します。

### 4. 期待効果の妥当性

[Suggestion] 期待効果の表現は適切に限定されました。

ただし `report()` は通知基盤の設定次第で配送を保証しません。「観測点を作る」は妥当ですが、「運用へ確実に通知する」とまでは表現しない方が正確です。

### 5. リスク

[Critical] DB に未知または未分類の `type` が保存されていた場合の扱いがありません。

`HandledStripeWebhookEvent::replaySafety()` が網羅的でも、それは enum の有効値に入った後の網羅性です。DB の文字列を `from()` すると例外で cron 全体が止まる可能性があり、誤って Safe 側へフォールバックすると危険です。

修正提案:

- DB 文字列は `tryFrom()` 相当で変換する
- 未知値は deny-by-default で自動再実行しない
- `recovery_pending` に遷移させ、理由を `UnknownEventType` として記録・通知する
- 行単位の異常で cron 全体を止めず、次の行へ進む

この対応に伴い、`recovery_pending` に入る理由は2通りではなく3通りになります。

[Warning] 条件付き UPDATE の成否だけでは、外部・台帳側の副作用の勝者は決まりません。

`attempts` CAS は古い worker による webhook 行の上書きを防ぎますが、元 worker と回収 worker の `process()` は並行実行できます。安全性は各 SafeToReplay ハンドラの永続的な冪等性に依存します。

修正提案: SafeToReplay に分類する各イベントについて、並行実行でも一回性が成立することをテストしてください。単なる逐次再送テストでは不足です。

[Warning] 条件付き UPDATE は Eloquent インスタンスの `save()` ではなく、条件を含む単一 SQL UPDATE とする必要があります。

修正提案: `event_id` または主キーに加え、`status=received` と claim 時の `attempts` を WHERE に含め、更新件数が1である場合だけ終局化成功と判定してください。成功時の `processed_at`、失敗時の `failure_reason` の更新契約も固定してください。

### 6. スコープの適切さ

[Warning] `RecoveryPending` という1状態に複数理由をまとめること自体は妥当ですが、理由を永続化しない設計は運用上不足しています。

状態が示す次のアクションがすべて「自動停止・手動確認」なら、状態を分ける必要はありません。一方、次の情報は status/type/attempts から後で確実に復元できません。

- OrderSensitive
- AttemptsExhausted
- UnknownEventType
- 将来分類が変更された場合の当時の判断

修正提案: 型付き `WebhookRecoveryReason` を設け、専用列へ保存するのが最も明確です。migration を避けるなら `failure_reason` に機械判定可能な固定コードを保存する案もありますが、自由文との混在になるため専用列が望ましいです。この小さな migration はスコープ過大ではありません。

[Suggestion] 順序判定列、failed cron、非同期化をスコープ外とした判断は妥当です。

### 7. 型安全性

[Warning] `stringAt()` / `data_get()` はアクセス方法であって、payload 全体の型保証にはなりません。

修正提案:

- DB の `type` は `HandledStripeWebhookEvent::tryFrom()` で境界変換する
- `claimStale()` は nullable Model ではなく、処置を表す enum/DTO を返す
- 回収理由も enum にする
- `payload` は既存 `process()` の `array<string, mixed>` 契約を維持し、無検査キャストを追加しない
- PHPStan を通すために `mixed` を `array` へ widen したり、包括的 ignore を追加しない

### 確認事項への回答

- (a) 解消しています。`claim()` を変更しないことで、通常再送から OrderSensitive を復活させる経路はなくなりました。
- (b) 1状態で妥当です。ただし停止理由の永続化が必要です。状態では「次の行動」、reason では「停止理由」を表すのが自然です。
- (c) `attempts` CAS は webhook 行の世代管理として成立します。ただし並行する `process()` の副作用は止めないため、SafeToReplay 各ハンドラの並行冪等性テストが必要です。
- (d) 大枠のスコープは適切です。追加すべき最小範囲は、未知イベントの fail-closed 処理、回収理由の永続化、commit 後通知、対応テストです。
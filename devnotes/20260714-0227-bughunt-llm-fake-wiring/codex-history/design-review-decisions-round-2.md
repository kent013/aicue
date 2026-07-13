# 対応マトリクス: design-review Round 2

## [Warning] 施策5: Reflection で protected buildMessages() を呼ぶ方式は vendor 実装詳細に結合
- 判断: 対応する
- 根拠: 妥当。protected は公開契約でなく vendor 更新で本番正常でもテストのみ壊れる。record 順序依存より強い結合。
- 対応内容: reflection を廃止し、vendor の**公開契約 `PromptFake::recorded()`** を使う capture 方式へ。各テスト（dataset 1 prompt/ケース）で `CannedPromptFakeRegistrar::install()` → 対象 factory を **1 回だけ** `executeSync()` → `Prompt::getFake()->recorded()` の唯一 entry から messages を取得 → signature 一致数/正当性を検証。1 ケース 1 実行なので順序・混入は生じない（reviewer 明示の条件を満たす）。

## [Warning] 施策5-4: finally 後の isFaking()===false assertion は例外時に到達しない
- 判断: 対応する
- 対応内容: リーク検知 assertion をテスト本体の finally 後ではなく **`afterEach` 内**（`Prompt::stopFaking()` 実行直後）に置き、テスト本体が例外で落ちても必ず実行されるようにする。

## [Suggestion] 施策5-6: stray 再確認は実通信前遮断を保証できないなら既存 guard 単体テスト維持に留める
- 判断: 対応する（安全側へ）
- 対応内容: 新規に実 stray を発生させるケースは追加せず、**既存 `StrayLlmCallGuard` 単体テスト群が green のまま**であることの維持確認に留める（本変更が guard 経路を壊していないことの担保はこれで足りる）。

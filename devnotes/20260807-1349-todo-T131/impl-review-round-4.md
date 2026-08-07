[Critical] なし

[Warning] なし

**Round 3 指摘の判定**

W1: 解消  
`terminateInvoiceBestEffort()` は原例外を `report()` せず、`invoiceId` と例外クラス名だけを含む新規 `RuntimeException` を報告しており、`previous` も繋いでいない。Round 3 の「保存場所を移しただけ」問題は塞がっている。

W2: 解消  
`Exceptions::fake()`、`assertReported()`、`assertReportedCount(1)` により、報告先に渡る例外がサニタイズ済みであること、外部由来メッセージを含まないこと、原例外の追加報告がないこと、`previous` がないことを固定できている。

W3: 解消  
`(b)` の「放置してよい」が削除され、原則すべて手動終端対象に改められている。idempotency key の保持期間と、一時保留できる例外条件も明記されており、Round 3 の運用上の誤誘導は解消している。

**スコープ判断**

W1 で採らなかった 2 案を本 PR 外の独立 TODO とする線引きは妥当。今回の失敗モードは呼び出し側の `report($exception)` で閉じており、gateway interface の契約変更や exception handler の横断 redact なしに解消できている。詳細設計が interface を変更しない前提なら、この PR で境界例外化まで広げる根拠は弱い。

既存の `tryTerminateInvoice()` が `$e->getMessage()` を構造化ログへ入れている点も、T131 が新設した経路ではないなら別 TODO でよい。ただし観測語彙を統一する観点では後続で潰す価値はある。

**新規欠陥**

今回の対応そのものから [Critical] / [Warning] に相当する新規欠陥は見当たらない。

[Suggestion] docs の「Stripe が生成した原メッセージはアプリのどこにも残らない」は、この cleanup 経路についての記述だと読めるが、既存経路との差をより厳密にするなら「この経路では」と限定してもよい。

APPROVED
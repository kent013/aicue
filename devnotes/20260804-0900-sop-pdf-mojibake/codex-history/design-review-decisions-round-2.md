# 対応マトリクス: design-review Round 2

Round 2 は全施策 APPROVE / 全体判定 APPROVED。Critical / Warning は 0 件。
Suggestion 4 件はすべて「設計文書と実装コードの記述不一致」の指摘であり、すべて反映した。

## [Suggestion] 施策2: `config/manual.php` のコメント「0 バイトは unextractable」→「PDF の 0 バイトのみ unextractable」
- 判断: **対応する** (実装と一致させる)
- 対応内容: コメントを `PDF の 0 バイトのみ unextractable` に修正。

## [Suggestion] 施策3: 施策一覧の「空抽出 = unextractable」→「PDF の空抽出 = unextractable」
- 判断: **対応する**
- 対応内容: 施策一覧の施策 3 の名称を修正。

## [Suggestion] 施策5: T10 は厳密には 0 バイトではないので「媒体ごとの空入力」と表現する
- 判断: **対応する**
- 対応内容: 「媒体ごとの 0 バイト文言体系」→「媒体ごとの空入力の文言体系」。

## [Suggestion] 施策5: PHPStan チェック欄の `sampleSopPath()` → `sampleSopContents()`
- 判断: **対応する**
- 対応内容: PHPStan チェック欄とリスク欄の関数名を実際のヘルパ名に統一。

## [Suggestion] 施策5: T3 の欧文 fixture は `analysis_min_text_bytes` 以上にする
- 判断: **対応する**
- 根拠: 100 バイト未満だと `tooShort` が先に発火し、日本語本文ゲートの検証にならない。
- 対応内容: T3 の説明に「いずれも `analysis_min_text_bytes` (100) 以上にする」を明記。

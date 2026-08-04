# 対応マトリクス: conceptual-review Round 2

## [Warning] 観点4: `noJapaneseText()` の文言が非日本語 SOP に対して実行不能な次アクションを案内している
- 判断: **対応する** (指摘が正しい)
- 根拠: 英語 SOP を Excel・テキスト形式で保存し直しても日本語本文ゲートは再度落ちる。
  「次アクションが 1 つに定まる」という Round 1 での主張は、
  (c) 日本語以外の手順書 のケースで成立していなかった。
- 対応内容: 文言を
  「手順書から**十分な日本語の本文**を読み取れませんでした。
   文字が画像になっている / PDF のテキスト埋め込みが壊れている /
   日本語以外の手順書、のいずれかの可能性があります。
   **日本語の手順書**を、Excel・テキスト形式か文字を選択できる PDF でアップロードしてください。」
  へ変更。形式問題 (別形式で保存し直す) と言語スコープ (日本語原稿が必要) の
  両方を満たす案内になる。

## [Suggestion] 観点7: 判定は「日本語文字がゼロ」ではなく「比率が基準未満」なので `insufficientJapaneseText()` が正確
- 判断: **対応する** (承認阻害ではないが、名前は検査内容と一致させるべき)
- 根拠: 機能の名前に立ち返れ (思考原則)。実際の検査は
  `japaneseRatio < config('manual.analysis_min_japanese_ratio')` であり、
  「ゼロ」ではない。`insufficientJapaneseText()` が一対一で対応する。
- 対応内容: factory 名を `noJapaneseText()` → **`insufficientJapaneseText()`** へ。
  ログの reason code も `insufficient_japanese_text` に合わせる。

## [Suggestion] 観点 1/2/3/5/6: 指摘なし (解消済みと確認)
- 判断: 対応不要

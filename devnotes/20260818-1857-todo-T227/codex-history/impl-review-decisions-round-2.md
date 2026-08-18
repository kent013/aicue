# 対応マトリクス: impl-review Round 2

全体判定は APPROVED。残っていたのは文言の Suggestion 2 件で、どちらも正確さが増すため取り込んだ。

## [Suggestion] `AppNameHardcodeTest` の「このリポジトリのどこにも書かれていない語」は不正確 (テストファイル自身には在る)

- 判断: 対応する
- 根拠: 指摘のとおり。走査根は app / routes / database / resources/js / scripts の 5 本であり、
  この語が在る tests/ は走査根ではない。書き方を実態に合わせる。
- 対応内容: コメントを「**走査対象のどのファイルにも**書かれていない語」に直し、
  本ファイルには在るが tests/ は走査根ではないことを併記した。

## [Suggestion] `ClaudeHooksWiringTest` の `null` の説明は「当たらないことが正常」より「0 件でも正常」が実装に正確

- 判断: 対応する
- 根拠: 実装は `null` の glob も毎回 glob して走査域へ加える (S12c で代表を要求しないだけ)。
  ファイルが増えれば S12b はそのまま走査するので、「当たらないことが正常」は誤読を招く。
- 対応内容: 台帳の docblock を「**0 件でも正常**な glob」に直し、
  「ファイルが増えれば S12b はそのまま走査する」を補った。

## 検証

文言のみの変更 (検出範囲は変えていない)。`vendor/bin/pint --test` / `composer phpstan` /
Architecture レーンを再実行して緑を確認した。

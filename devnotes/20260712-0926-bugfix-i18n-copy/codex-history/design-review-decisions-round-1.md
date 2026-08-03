# 対応マトリクス: design-review Round 1

## [Critical] 施策 3: g-recaptcha-response の責務境界が曖昧 (messages() 個別文言 vs attributes)
- 判断: 対応する
- 根拠: 指摘の通り、`required` は `StoreInquiryRequest::messages()` の個別文言が正で、attributes は
  それ以外の rule (`string` / Recaptcha) の fallback にすぎない。設計書の記述が「attributes で対応」と
  読めるのは誤解を招く。
- 対応内容: ラベル対応表直下に「責務境界」段落を追加。required は個別 messages を正とし変更しない、
  attributes 追加は残り rule の fallback である旨を明記。

## [Warning] 施策 2: `export VAR=` 形式の検知漏れ
- 判断: 対応する
- 対応内容: 正規表現を `^(?:export\s+)?([A-Z0-9_]+)=(.*)$` に拡張 (キャプチャグループ位置は不変)。

## [Warning] 施策 3: 多義語 (name/status/description) の UI 語彙不一致
- 判断: 対応する
- 対応内容: 「差分が体験上問題になるキーは FormRequest::attributes() で局所上書き」の規約に、
  具体例 1 件 (`UpdateOrganizationRequest::attributes()` → `['name' => '組織名']`) を実装として追加。
  inline validate 側は `validate($rules, $messages, $attributes)` 第 3 引数で対応する旨も明記。
  施策一覧・変更箇所・波及変更を更新。

## [Warning] 施策 4: Validator::make の import alias / FQCN 混在時の取りこぼし
- 判断: 対応する
- 対応内容: 検出仕様を「use 文 (alias 含む) の最小パース map による解決 + FQCN 末尾セグメント一致」に
  拡張。現状の全呼び出しは単純名 `Validator::make` / `$request->validate` であることを棚卸しで確認済み。

## [Suggestion] UNPARSEABLE_CALL_INVENTORY のキー形式固定
- 判断: 採用
- 対応内容: `"{相対パス}@{行番号}#{呼び出し種別}"` に固定。行番号ずれによる stale entry は fail し
  再確認を強制する (fail-closed 側に倒れる) と明記。

## [Suggestion] ラベル表に rule キーの出典列を追加
- 判断: 見送る (一部代替)
- 根拠: 出典 (FormRequest/Controller 名) は表直前の棚卸し節に全列挙済みで、列追加は表の可読性を下げる。
  将来の追跡は施策 4 の Architecture テストが機械的に担う (どのクラスのどのキーが未登録かを
  違反メッセージで示す) ため、表の静的な出典列は冗長。

## [Suggestion] 施策 5: 文言厳密一致の方針をテストコメントで明示
- 判断: 採用
- 対応内容: テストコード例に「意図的に厳密一致 (文言変更を明示的にレビューさせる)」コメントを追加。

# 対応マトリクス: conceptual-review Round 2

## [Warning] inline validation 検出が文字列パターン限定で、未対応記法を黙って見逃す (deny-by-default になっていない)
- 判断: 対応する
- 根拠: 指摘の通り、`app/Http/Controllers/**` 限定 + `->validate([` パターンのみでは
  `validateWithBag()` や配列変数分離、他ディレクトリへの追加を成功扱いしてしまう。
- 対応内容: 第 2 検査を fail-closed の二段階に再設計。
  (1) `app/` 配下全 PHP (FormRequest 除く) から validation API 呼び出し
  (`->validate(` / `->validateWithBag(` / `Validator::make(`) を全件検出。
  (2) 各呼び出しでルール配列リテラルからキーを静的抽出し attributes 登録を要求。
  抽出できない呼び出しは理由付き inventory 登録がない限り fail。
  概念設計の施策 2-3 を更新済み。

## [Warning] 期待効果「追加時に CI で fail」が現設計では保証されない
- 判断: 対応する
- 根拠: 上記 fail-closed 化により保証可能になる。
- 対応内容: 施策 2-3 の末尾に「期待効果を FormRequest / inline validate の両面で保証する」と明記。

## [Suggestion] F-01 の効果限定・優先接触面・drift 検知別トピック・スコープ判断・型 shape
- 判断: 現状維持 (Round 2 で妥当と評価済み)

# 対応マトリクス: impl-review Round 3

## [Warning] 「1 ファイル = パス由来の型 1 つ」の検査が exact pin になっていない

- 判断: **対応する**
- 根拠: 指摘は正しい。`declared` を `ReferenceSite` の `scopeKind/class` から間接的に集めていたため、
  **参照 site を 1 つも持たない空クラス / interface / trait では集合が空**になり、
  「不一致 0 件」で緑になる = 前提検査が空振りしていた。しかも docblock は
  「宣言された型 == パス由来の FQCN」と実装より強い保証を謳っていた。
- 対応内容:
  1. `deletionPathDeclaredTypes()` を新設し、**宣言トークン (`T_CLASS` / `T_INTERFACE` /
     `T_TRAIT` / `T_ENUM`) を直接解析**して FQCN を組み立てるようにした
     (`Foo::class` は `::` の直後なので除外、匿名クラスは名前トークンが無いので除外)。
  2. 判定を**集合全体の比較** (`$declared !== [$scan['class']]`) に変えた。
     宣言 0 件も不一致として落ちる。
  3. `ScanScopeKind` 経由の間接収集は撤去した (後方互換の並走を残さない)。
- 実測: app/ の 692 ファイルすべてで `[$class]` ちょうどとなり緑。

## [Warning] 新設した宣言型 pin の赤化実測が無い

- 判断: **対応する**
- 根拠: 禁止事項 1 (不変条件は壊すと赤くなることまで確認して初めて実装済み) のとおり。
- 対応内容:
  - **負の fixture 3 本**を追加した:
    - 9 形目: 参照 site を持たない**空クラス / 空 interface** でも宣言集合が 1 件出る
    - 10 形目: **1 ファイル 2 型** / **ファイル名と一致しない型**が `[$class]` と一致しないこと
    - 正のコントロール: **匿名クラス / `::class`** を宣言型に数えないこと
  - **mutation M9** を実施し実測した (`deletionPathDeclaredTypes()` を `return []` にする =
    Round 3 で指摘された退行の再現)。**24 tests / 20 passed / 4 failed**
    (検査 4 + fixture 9 + fixture 10 + 匿名クラスの正コントロール)。
    検査 4 は app/ 全ファイルを列挙して落ちたので、集合比較にしたことで
    **宣言 0 件でも空振りしない**ことが実測できた。
  - `DELETION_PATH_MUTATION_COVERAGE` に M9 を追加した。

## [Suggestion] 表現訂正の残り 2 箇所

- 判断: **対応する**
- 根拠: 保証範囲の表現は一貫していないと意味がない。
- 対応内容:
  - 冒頭 fixture 一覧: 「interface 経由の container binding」→
    **「interface 実装への保守的な逆向き辺」**
  - `deletionPathTraverse()` 内コメント: 「container binding 越しの到達」→
    **「閉包に入った interface の実装クラスを保守的に引き込む逆向きの辺
    (bind 宣言は読まない = container binding の解決ではない)」**

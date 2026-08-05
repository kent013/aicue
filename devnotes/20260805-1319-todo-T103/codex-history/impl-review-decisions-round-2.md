# 対応マトリクス: impl-review Round 2

## [Critical] 実行されない認可式 (クロージャ / arrow function / 到達不能分岐) による誤合格

指摘は 2 つの異なる性質の問題を含むため、分けて判断した。

### (a) ネストしたクロージャ / arrow function 内のマーカー

- 判断: **対応する**
- 根拠: `$authorize = fn () => Gate::authorize('delete', $item);` は
  **字句的にも構文的にも「呼ばれていない」ことが静的に確定する**。
  トークン走査の範囲内で判定でき、かつ gate の主張
  (「ハンドラは必ず認可判断を 1 回通る」) を直接破るため、対応必須と判断した。
- 対応内容: `AuthorizationMarkerScanner::nestedFunctionMask()` を追加。
  断片の先頭に現れる `function` / `fn` (= ハンドラ本体そのもの) 以降に現れる
  `function` / `fn` の本体範囲をマスクし、その内側のマーカーを
  `authorizationMarkerOffset()` / `guardMarkerOffsets()` の両方で数えないようにした。
  - `function` は本体 `{ ... }` を括弧対応で、`fn` は同深度の `;` / `,` または
    深さが外へ出た位置までを範囲とする
  - 判定は**保守的** (迷ったら除外) にした。除外しすぎた場合の結果は
    「認可なし」= gate が fail して人間が気づく方向であり、誤合格 (沈黙) にはならない
- 実証: `ItemController::destroy` の認可を `fn () => ...` で包んだところ
  gate が「未分類」で fail することを実測 (修正前は合格していた)。
  恒久固定として Unit テストを 4 本追加 (arrow function 内 / クロージャ内 /
  クロージャが同居しても直下の認可は検出される / クロージャ内 guard は順序検証対象外)。

### (b) 到達不能分岐 (`if (false) { Gate::authorize(...); }`)

- 判断: **見送る (線引きを明文化して受け入れる)**
- 根拠: Codex 自身が指摘するとおり、これは**制御フロー解析 (AST/CFG) の領域**であり、
  トークン走査の限界を超える。ここに踏み込むのは
  - 思考原則 2「今必要なものだけ作る」に反する (現行コードベースに該当例 0 件)
  - gate の責務 (`NestedRouteIdorDefenseTest` と同じ「分類漏れ・drift を落とす」役割) を超える
  - CFG 解析器そのものが新しいバグ表面になり、gate の信頼性をむしろ下げる

  重要なのは、この抜けが**単独では成立しない**こと。到達不能分岐に認可を置いた実装は
  Feature テスト (`ItemAuthorizationTest` の viewer 403 ケース 5 本) で必ず落ちる。
  「入口の存在 = Architecture テスト / 実挙動 = Feature テスト」の 2 層で守る設計であり、
  片方だけで完全性を主張していない。
- 対応内容: 逃げずに**限界として明文化**した。
  `AuthorizationMarkerScanner` の docblock に「★本 helper の限界 (意図的な線引き)」節を追加し、
  到達可能性を判定しないこと・その代わり実挙動は Feature テストが担保することを明記。

## [Warning] `authorizationMarkerOffset()` が最初の認可だけを返し、複数認可を落としうる

- 判断: **契約として明文化する (挙動は変えない)**
- 根拠: Codex の但し書き
  「設計上『すべての認可より先にテナント境界を確定する』なら、この厳格さは妥当」が
  まさに本設計の契約である。「1 回目の認可 → guard → 2 回目の認可」という配置は、
  1 回目の認可が cross-org を 403 で弾いてしまい存在が漏れる = 不変条件 2 違反。
  したがってこれは false positive ではなく**意図した検出**。
- 対応内容: `ControllerAuthorizationGateTest` の順序検証テスト直前に契約 docblock を追加し、
  「すべての URL 整合 guard は、すべての認可判断より前」であること、
  および複数認可を意図的に違反扱いする理由を明記した。

## その他

- `ProjectRouteCurrentOrgGuardTest` / `AuthorizationMarkerScannerTest` /
  `ItemAuthorizationTest` は Round 2 で APPROVED を得たため変更なし。

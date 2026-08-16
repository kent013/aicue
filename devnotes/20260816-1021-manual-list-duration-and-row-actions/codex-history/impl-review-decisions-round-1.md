# 対応マトリクス: impl-review Round 1

Codex 返答: `impl-review-round-1.md` (全体判定 CHANGES_REQUESTED / Warning 4 件・Critical 0 件)

## [Warning] 範囲外ページの丸めが `total() > 0` のときだけ (ProjectController::manualRows)

- 判断: **対応する**
- 根拠: 0 件の一覧でも `lastPage()` は 1 を返すため、丸めないと
  `current_page=99 / last_page=1` という**内部で食い違った meta** を props に載せることになる。
  「meta が正本」と宣言している以上、正本が矛盾した値を持つ状態を残さない。
  再クエリは 0 件ページに着地したときだけ発生し、一覧の常時コストは増えない。
- 対応内容: 丸め条件から `&& $paginated->total() > 0` を削除し、理由をコメントに明記。
  テスト追加 (`ProjectShowManualsTest`): 0 件の一覧に `?page=99` /
  `?page=99999999999999999999999` で入っても `current_page=1 / last_page=1 / total=0`。

## [Warning] `category` が生文字列のまま Location に戻る (ManualListQuery)

- 判断: **対応する**
- 根拠: `0003` は絞り込みとしては効くのに `manualFilters.category` が `'0003'` のままなので、
  フィルタ select (値は `String(category.id)`) が選択状態を復元できず**表示と実際の絞り込みが食い違う**。
  巨大な数字列がそのまま着地先 URL に残るのも「生の入力を素通ししない」という設計意図に反する。
  破棄 (null 化) にしないのは、絞り込みが消えて**全件が出る**驚きの方向に倒れるためで、
  正規化なら結果集合は変わらない。
- 対応内容: `ManualListQuery::fromRequest()` で数値 category を `(string) (int)` の正規形へ畳む。
  桁溢れは `(int)` が PHP_INT_MAX へ飽和して該当なしになる (URL も有界)。
  テスト追加: 一覧側 (`0003` → `3` で絞り込み + `manualFilters.category` が正規形 /
  桁溢れは 0 件)、削除の着地側 (`ManualRowActionsTest`: `?category=000003` → `?category=3`)。

## [Warning] `EagerLoadCandidate` の前提が弱く VideoManual.php が免除領域になる

- 判断: **対応する**
- 根拠: 指摘のとおり「`output_path` を参照しない」だけでは、同じファイルに 2 本目の候補 relation を
  足しても赤くならない。deny-by-default の目録に**ファイル単位の免除**を作ってしまう。
  区分を新設した以上、前提は「1 本しか無い」ところまで機械で押さえるべきである。
- 対応内容: gate (`CurrentRenderArtifactInventoryTest`) の前提検査を強化した。
  - ケース 7: `EagerLoadCandidate` は `Models/VideoManual.php` ちょうど 1 ファイル (Canonical と同じ形)
  - ケース 8: (a) `output_path` を参照しない / (b) succeeded 条件の出現数がちょうど 1・
    `ofMany(` 1 回・`hasOne(` 1 回 / (c) `latestSucceededRender()` の宣言が在り
    `RenderKind::Render` の参照がちょうど 1 (preview を混ぜたら赤くなる)
  - scanner 自己検証を追加 (個数計測と宣言名検出が空振りしないこと)
  - 保証しないもの (helper 切り出し・動的呼び出し・別ファイルへの移設) をコメントに明記
- 補足: 「将来同ファイルに別の候補 relation が増えたら止められない」という指摘は (b) で塞いだ。

## [Warning] parity テストに `ready + succeeded render (output_path あり)` の endpoint 側が無い

- 判断: **対応する**
- 根拠: 「download endpoint が 302 を返す条件と 1 対 1」と書くなら、published が外れている
  ケースでも**両者が一致する**ことをテストが示すべきである (一覧側だけでは片肺)。
- 対応内容: `ManualRowDownloadableParityTest` に追加。選択式は行を返す (published 判定を持たない
  = 名前どおりの責務) が、一覧 props は `downloadable=false` / endpoint は 404 になることを固定。
  同じケースで `duration_ms` が null であることも併せて固定した。

## 検証

- `composer test`: 5342 tests / 5340 passed / 2 skipped (0 failed)
- `composer phpstan`: No errors / `vendor/bin/pint --test`: passed

# 対応マトリクス: impl-review Round 4

> Round 4 の全体判定は `CHANGES_REQUESTED`。指摘は [Critical] 2 件 (同じ事象の別側面) +
> [Warning] 1 件 + [Suggestion] 1 件。**すべて対応した**。

## [Critical] `test(…)->skip()` の後置で、登録したまま 7 本を評価させない経路が残る

- 判断: **対応する** (指摘は正しい。実際に無力化できた)
- 根拠 (実測): 禁止表明の `});` を `})->skip();` に変えると、
  `composer test -- --filter=ArchBaseline` は **82 tests / 75 passed / 7 skipped で全緑**だった。
  指摘のとおり、ヘッダー (`EXPECTED_CHAIN_HEADER_TOKENS`)・表明の文 (`EXPECTED_CHAIN_TOKENS`)・
  最上位の制御構造 1 件・打ち切り 0 件・7 規則の description の登録 (S4-3c の `missing` が空) の
  **すべてが一致したまま**、closure だけが実行されない。
- 対応内容: 指摘が挙げた 3 案のうち **1 案目 (生成文を後置まで閉じる) を主層**に据え、
  **2 案目 (登録簿の状態検査) を実行時の第 2 層**として併せて入れた。
  1. `ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS = ['}', ')', ';', '}']` を新設
     (closure を閉じる `}` / `test(` を閉じる `)` / 文末 `;` / `foreach` を閉じる `}`)。
  2. `ArchSurfaceScanner::tokensAfter()` を新設 (`tokensBefore()` の対。範囲外は例外)。
  3. **S4-3** (宿主ファイル内) と **テスト 38** (別ファイルの外部自己検査) の**両方**が
     表明の文の直後 4 トークンを footer と exact-fit で照合する。
     後置が 1 つでも付けば `['}', ')', '->', 'skip']` になり両方が赤くなる。
  4. **S4-3c を「登録されている」から「実行修飾を 1 つも持たない」へ強めた**。
     `new TestCaseMethodFactory(__FILE__, null)` (新品) と登録済み 7 本の
     **公開プロパティ全体を `==` で差分比較**する deny-by-default にした
     (修飾名の一覧を持たないので、Pest が修飾を増やしても自動で効く)。
     `chains` 等は別インスタンスなので `===` ではなく `==` を使う
     (`==` は private を含む全プロパティを再帰比較する)。
     `attributes` だけは Pest が description から `#[Test]` + `#[TestDox]` を必ず 2 個作るので
     新品と比べられず、**その 2 個ちょうど**を期待形にした
     (`->group()` / `->depends()` / `->with()` / `->only()` はここへ追加するので増えれば赤)。
- 実測 (注入 3 形すべてで**3 つの検査が同時に赤**になる):

  | 注入 | 赤くなる検査 |
  |---|---|
  | `})->skip();` | テスト 38 / S4-3 / S4-3c |
  | `})->todo();` | テスト 38 / S4-3 / S4-3c |
  | `})->group('x');` | テスト 38 / S4-3 / S4-3c |

  `->group('x')` は**属性しか変えない**ので、S4-3c が赤くなることが
  「属性の期待形 2 個」の分岐が現に効いている証拠である。

## [Critical] 後置の実行修飾の負例が走査器テストに無い

- 判断: **対応する**
- 対応内容: 負例 **13g** を追加した。合成した期待形チェーンの `});` を `})->skip();` に置換し、
  - 置換が実際に起きたこと (負例が負例になっていること) を先に確認
  - **表明の文とヘッダーは両者で完全に一致する**こと (= 後置を見ないと区別できない) を固定
  - 後置だけが `['}', ')', ';', '}']` と `['}', ')', '->', 'skip']` で分かれることを固定
  - 範囲外は例外 (fail-closed) であることを両方向で固定

## [Warning] 台帳の保証記述を「実行可能な状態で登録されている」まで上げる

- 判断: **対応する**
- 対応内容: D43 の「揃え続ける不変条件と保証機構」を
  「7 本が実際に Pest へ**実行可能な状態で**登録されたことまで実行時に確かめ
  (登録の有無に加えて、新品の factory との差分比較で skip / todo 等の実行修飾が
  1 つも付いていないことを見る)、**生成文の後置トークンまで exact-fit で閉じ**、…」へ更新した。

## [Suggestion] arrow function の式内部は波括弧を持たないので「本体は数えない」が厳密でない

- 判断: **対応する** (指摘のとおり。保証範囲の記述を正確にした)
- 対応内容: `topLevelControlStructureSites()` の docblock に
  「**arrow function (`fn () => …`) の式の中は区別しない**。最上位の
  `fn () => match ($x) { … }` の `match` は 1 件として現れる。**拾いすぎる側の誤差**であり
  見逃しではない」と明記し、同じことが `topLevelAbortSites()` の `throw` にも当てはまる
  (`fn () => throw new …`) ことを併記した。宿主ファイルの「ちょうど 1 件」契約は
  この形を持たないので実害はない。

## 修正後の実測

- `composer test -- --filter=ArchBaseline`: **83 tests / 83 passed / 207 assertions**
- `composer test` (全数) / `composer phpstan` / `vendor/bin/pint --test`: 報告本文の数値のとおり
- `vendor/bin/phpstan analyse --level=10` (設計固有の 3 パス): **0 errors**
- 抑止コメント・baseline 追加・型の widen・設定の緩和はいずれも使っていない

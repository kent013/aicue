# 対応マトリクス: impl-review Round 2

Critical はゼロ。Warning 1 件を対応した（反論なし）。

## [Warning] 受入条件 11 の「両カードで note が不可視」が片方しか固定されていない
- 判断: **対応する**
- 根拠: 指摘のとおり。検査していたのは Standard 選択**後**の
  `plan-selected-note-standard` だけで、初期表示時の Starter note については
  文言と存在しか見ていなかった。現在の実装では同じ `sr-only` クラスを通るので結果は同じだが、
  **将来プランや状態で class が分岐したらすり抜ける**。詳細設計は明示的に
  「両カードで note が不可視」と書いており、テストがそれを満たしていなかった。
- 対応内容: 不可視判定を `expectNoteVisuallyHidden(mixed $page, string $planCode): void` へ
  ヘルパ化し、note が出る **2 時点**で呼ぶようにした:
  - Standard を押す**前**: `plan-selected-note-starter`
  - Standard を押した**後**: `plan-selected-note-standard`
  判定条件は Round 1 で入れた 4 点 (`tiny` / `absolute` / `hidden` / `clipped`) のまま。
  要素が無い場合は `null` を返して `toMatchArray` が落ちるので、
  「note が無いのに緑」にはならない。

## [Suggestion 相当の受領] desktop の区間観測は 500ms/10 回で実用的
- 「50ms 間の一時的な移動を理論上見逃す余地はある」という留保はそのまま受け止める。
  この変更の副作用は `focus` と `scrollIntoView` だけで、どちらも一瞬だけ動いて戻る性質ではない。
  追加の作り込みはしない（過剰になる）。

## `PricingPlanCard.svelte` への計測用 testid 追加は許容と判定を受領
- molecule の責務・公開インターフェース・スタイルを変えず、
  不安定な CSS セレクタ探索より堅牢という評価。逸脱記録はそのまま残す。

## 検証（対応後）
- `composer test:browser` chromium: 22 tests / 19 passed / 3 skipped / **149 assertions**
- `composer test:browser` webkit: 22 tests / 19 passed / 3 skipped / **149 assertions**
  （Round 1 対応後は 141。初期表示側の sr-only 契約 4 点ぶん増えている）
- `vendor/bin/pint --test`: passed

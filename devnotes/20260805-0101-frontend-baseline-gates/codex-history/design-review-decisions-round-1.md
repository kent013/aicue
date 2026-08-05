# 対応マトリクス: design-review Round 1

## [Warning] `noInlineConfig` の検証が `.svelte` に閉じており `.ts` の override を見逃す (施策 4)
- 判断: **対応する**
- 根拠: 妥当。`linterOptions.noInlineConfig` はトップレベル (files 指定なし) で
  リポジトリ全体に効く設定であり、`.svelte` だけ検証しても
  「`resources/js` 全体で inline disable が効かない」という不変条件は保証できない。
  将来 `.ts` 向けの file-scoped override で `noInlineConfig: false` を戻されると素通しになる。
- 対応内容: 純関数を **2 本に分割**する。
  - `assertNoInlineConfig(resolved)` — `.svelte` **と** `.ts` の全ファイルに適用 (1 検査)
  - `assertSvelteNoUndefConfig(resolved)` — `.svelte` のみに適用
    (no-undef=error / globals 完全一致 の 2 検査)

  走査も `.svelte` 列挙と `.svelte`+`.ts` 列挙の 2 本にする。
  それぞれ 0 件なら fail (空振り防止)。
  gate の名前 (`svelte-no-undef-gate`) が指す中心は `.svelte` の no-undef のままだが、
  それを支える前提条件 (`noInlineConfig`) は前提の適用範囲どおり全体で検査する
  — と doc コメントに明記する。

## [Suggestion] `calculateConfigForFile()` が `undefined` を返すケースのガード (施策 4)
- 判断: 対応する
- 根拠: 診断性の向上として妥当。ignore 対象ファイル等で null 相当が返る可能性がある。
- 対応内容: 解決結果が object でない場合に
  「実効設定を解決できなかった (ignores に入っていないか確認せよ)」という
  明示エラーを投げるガードを入れる。

## [Suggestion] テスト名/説明で「opaque text 限定」をさらに強調 (施策 6)
- 判断: 対応する
- 対応内容: `it.each` のタイトルに `[opaque text]` を前置し、
  「1.4.11 非テキスト / alpha 合成は対象外 (PENDING_CONTRAST_PAIRS 参照)」を明示する
  `it` を 1 本追加して、pending 宣言が空でないことを固定する
  (未検査宣言そのものが消し飛ばされないようにする)。

## [Suggestion] `{@html}` の allowlist gate を別バッチで (施策 3)
- 判断: **見送る (申し送りへ集約)**
- 根拠: 既に申し送り 7-2 (`svelte/no-at-html-tags` の家系標準化) として記録済み。
  c2c 台帳 `eslint-svelte-ts-baseline` の t0 ルール集合外を aicue 単独で足すと
  新しい divergence を作るため、本バッチでは実装しない (概念設計で裁定済み)。
- 対応内容: 申し送り 7-2 の記述に「allowlist 型 gate という実装案もある」旨を補足する。

## 施策 1 / 2 / 5 / 6 / 7 の APPROVE
- 判断: 対応不要

# 対応マトリクス: design-review Round 4

## [Warning] M8: 「タイトルは truncate」と書きながらコードに反映されていない
- 判断: **対応する**
- 根拠: 指摘のとおり、`truncate` が付いていたのはメタ情報の `<p>` だけで、タイトル
  (`TextLink`) には付いていなかった。空白を含まない長いタイトルはモバイル幅を超え得る。
- 対応内容: `TextLink` は `class` prop を受け取れることを実装で確認した
  (`resources/js/components/atoms/TextLink.types.ts` の `BaseProps.class`、
  `TextLink.svelte` が `computedClass` に合成する)。よって包む要素を足さず
  `class="block truncate"` を直接付与する (`Link` が描く `<a>` は inline のため
  `block` を併せて指定する)。

## [Warning] M9: 長いタイトルの回帰テストが無い
- 判断: **対応する (ただし保証範囲は誇張しない)**
- 対応内容: `ManualListRow.test.ts` に「空白を含まない 200 文字のタイトルでも、
  タイトル要素に `truncate` が当たり、包む `div` が `min-w-0` を持つ」ケースを追加する。
  **jsdom はレイアウトを計算しない**ため、固定できるのは**スタイル契約**までであり
  「実寸で溢れない」ことの保証ではない — この非対称をテスト名・コメント・設計の
  リスク欄に明記した (保証しないものを保証すると書かない)。

## M1〜M7 (APPROVE 部分)
- 判断: 指摘なし。変更しない。

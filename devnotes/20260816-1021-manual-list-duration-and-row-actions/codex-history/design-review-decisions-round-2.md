# 対応マトリクス: design-review Round 2

## [Warning] M1: 巨大な `page` が offset 計算をオーバーフローさせ得る
- 判断: **対応する (Round 1 の反論を撤回)**
- 根拠: 指摘のとおり。`ctype_digit()` は桁数を制限せず、`(int)` は `PHP_INT_MAX` に飽和する。
  paginator の offset は `($page - 1) * $perPage` で計算されるため、
  `PHP_INT_MAX * 10` は int 範囲を超えて float 化する。Round 1 で書いた
  「OFFSET は bigint 範囲に収まる」は**根拠として成立していなかった**。
  上限は「チューニング値」ではなく**計算安全性の境界**であり、導出可能なので魔法の数にならない。
- 対応内容:
  1. 1 ページあたり件数の置き場所を `ManualListQuery::PER_PAGE` に**統一**する
     (Controller の `MANUALS_PER_PAGE` は置かず、`ManualListQuery::PER_PAGE` を参照する。
     perPage の知識を 2 か所に分散させない)。
  2. `ManualListQuery::MAX_PAGE = intdiv(PHP_INT_MAX, self::PER_PAGE)` を導出定数として置き、
     `$page = min(max(1, (int) $pageRaw), self::MAX_PAGE)` で正規化する。
     これで `($page - 1) * PER_PAGE` は必ず int 範囲に収まる。
  3. 上限超過を 1 ではなく `MAX_PAGE` へ丸めるのは、「最後のページを見たい」という
     利用者の意図に近い側へ倒すため (着地は M4 の丸めで最終ページになる)。

## [Warning] M2: docblock に「実体が残っているか」の表現が残っている
- 判断: **対応する**
- 根拠: 呼び出し側が見るのも `output_path !== null` だけで、ストレージ実体は確認していない。
  M4 / M6 で直した保証範囲の表現と揃っていなかった。
- 対応内容: relation の docblock を
  「こちらは `output_path` が NULL の行も返す。`output_path` の有無は呼び出し側が判定する」
  に書き換える (「実体」の語を残さない)。

## [Warning] M9: 極端な整数入力のテストが不足
- 判断: **対応する**
- 対応内容: `ProjectShowManualsTest` に追加する —
  - `PHP_INT_MAX` を超える数字列 (`?page=99999999999999999999999`)
  - `?page=` に `PHP_INT_MAX` そのもの (`page * PER_PAGE` が int 範囲を超える値)
  - いずれも 200 で最終ページに着地し、例外 / 500 にならない
  - `ManualRowActionsTest` 側でも、極端な `page` 付き DELETE の redirect が
    正規化後の値になり壊れないことを確認する

## [Suggestion] M8: Inertia `Link` の mock が通常 anchor と見分けられるように
- 判断: **対応する**
- 対応内容: `ManualListRow.test.ts` では `@inertiajs/svelte` を mock し、`Link` は
  **マーカー属性 (`data-inertia-link="true"`) 付きの要素を描画するスタブ**にする。
  「DL 要素が `A` タグである」に加えて「マーカー属性を持たない」ことを assert すれば、
  atom が `Link` 分岐へ移った日に必ず赤くなる。

## M3 / M4 / M5 / M6 / M7 / M8 (APPROVE 部分)
- 判断: 指摘なし。変更しない。

# 対応マトリクス: design-review Round 3

## [Critical] 施策 5: `.svelte` 内で native input を作れるのに未分類の構文がある (`<svelte:element>` / `{@html}`)

- 判断: **対応する (提案どおり診断で止める。保証外へ追い出さない)**
- 根拠: 指摘は正しい。どちらも `.svelte` の中にあり、`RegularElement` の `input` として
  現れないため、当初案では目録外で file input を増やしても緑になりえた
  (共通規約 (b) の「無言で候補から外さない」に違反)。
  `svelte/compiler` で実測して AST 形状を確認した:
  - `<svelte:element this={tag} />` → `SvelteElement` / `tag.type === "Identifier"`
  - `<svelte:element this="input" … />` → `SvelteElement` / `tag.type === "Literal"`
  - `{@html markup}` → `HtmlTag`
  - `<svelte:component this={C} />` → `SvelteComponent`
- 対応内容: 施策 5 に**要素レベルの母集団表**を追加した。
  - `RegularElement` の `input` と、`SvelteElement` で `tag` が `Literal` かつ値が
    `input`(大文字小文字を無視)は**母集団に入れて同じ判定を通す**
  - `SvelteElement` で `tag` が `Literal` 以外 → 診断 `unresolved-native-element`
  - `SvelteElement` で `Literal` かつ `input` 以外(`<svelte:element this="div">`)→ 対象外
    (静的に非 input と確定できるものを診断にすると誤検出になる)
  - `HtmlTag` → 診断 `opaque-html`。**名指しの免除目録**
    (`RAW_HTML_EXEMPTIONS` + 30 文字以上の理由 + 件数 pin)に登録されたファイルだけ許す。
    現在の実在は 1 件 (`pages/Settings/Security.svelte` の 2FA QR コード SVG) で、
    ここを無条件に沈黙させないため deny-by-default にした。
    免除は**両方向**で突き合わせる(未登録の `{@html}` は違反 / 実在しない登録も違反)
  - `SvelteComponent` / 通常 component は対象外(native input ではない。
    component 自身の `.svelte` は別途走査される)
  - 自己検査 (A) へ要素レベルの 6 ケース (16〜21) を追加し、(B) へ免除目録の
    4 ケース (35〜38) を追加した(合計 (A) 21 / (B) 17)
  - `evaluateFileInputInventory()` の引数へ免除目録と件数 pin を追加した
    (判定を 1 関数へ集約する方針を維持)
  - 保証しないものへ「**`{@html}` の中身は解析しない** — 免除は人の宣言であり
    gate が中身を確かめた結果ではない」を明記した

## [Warning] 施策 2: 「結線」という古い保証表現が箇条書きに残っている

- 判断: **対応する**
- 根拠: 直後の「呼び出しは保証しない」という記述と矛盾していた。
- 対応内容: 該当行を
  「両経路の 422 文言が、現在の中央ラベル (`formatsLabel()`) と**同じ出力契約**を
  満たすこと(完全一致で比較)」へ書き換え、「結線」の語を削除した。

## [施策 1・3・4 の APPROVE]

- 判断: 受領 (記述変更なし)

# Round 4: Round 3 指摘への対応

## 対応マトリクス

### [Warning] 観点3: native fetch が 409 契約へ入る条件が設計に不足 → **対応**

指摘のとおりです。`RequireRecentAuth` は `expectsJson()` が真のときだけ 409 を返し、偽なら 302 を返すため、
ヘッダが欠けると fetch がリダイレクトを追従して HTML を取得し、409 判定が一度も成立しません。

現行コード (`resources/js/pages/Settings/Security.svelte` の `fetchJson()`) は既に
`headers: { Accept: "application/json" }` を送っており条件は満たしていますが、
**暗黙の前提のまま**でした。設計に「`Accept: application/json` を必ず送る」を明示契約として書き、
Feature テストも `getJson()` のヘルパ任せにせず
`get('/user/two-factor-qr-code', ['Accept' => 'application/json'])` で**実ヘッダ条件による 409** を固定します。

### [Suggestion] 観点3: 両方409 / 片方だけ409 / 非409エラー の3系統をテストで分ける → **採用**

通常エラーを step-up へ誤分類しないための負のコントロールとして 3 系統を JS テストに入れます。

### [Warning] 観点4: 「回帰の恒久化」の記述が保証範囲より広い → **対応**

次のとおり限定しました。

> **回帰の恒久化 (範囲を限定して書く)**: deny-by-default 目録により、
> **Fortify が `two-factor.*` を増やした場合、および route 名に `two-factor` を含む
> アプリ側 route が増えた場合**は、分類しない限り CI が赤になる。
> それ以外の命名 (`mfa.*` 等) には**沈黙する**。

### [Warning] 観点5: 「改善アイデア」冒頭の不変条件宣言も広すぎる → **対応**

宣言自体を名前ベースへ書き換えました。

> **「route 名に `two-factor` を含む route は、recent-auth 必須か理由付き exemption の
> いずれかへ分類する」を deny-by-default の目録で機械強制する。**
>
> (不変条件の宣言を意味ベースではなく名前ベースで書くのは、gate が実際に検査できる範囲と
> 宣言を一致させるためである。意味ベースの保証は命名規約の強制を要し、本 TODO には過大。)

### [Suggestion] 観点7: 409 判定関数は `unknown` を受ける型ガードに → **採用**

既存 `parseRecentAuthStatus` と同じ流儀で、`unknown` を受けて構造を絞り込む型ガードとして書くことを明記しました。

---

Round 3 で挙がった残り 2 点はいずれも反映済みです。最終判定をお願いします。

## 修正後の概念設計 (全文)


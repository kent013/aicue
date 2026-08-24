## 施策別判定

| 施策 | 判定 |
|---|---|
| S1 | APPROVE |
| S2 | REQUEST_CHANGES |
| S3 | REQUEST_CHANGES |
| S4 | APPROVE |
| S5 | REQUEST_CHANGES |
| S6 | APPROVE |
| S7 | APPROVE |
| S8 | APPROVE |
| S9 | REQUEST_CHANGES |
| S10 | APPROVE |
| S11 | APPROVE |
| S12 | REQUEST_CHANGES |

## 残る指摘

[Warning] S2 の契約本文は構文解析失敗を診断へ統一していますが、テスト計画には未終端コメント・リテラル等が「例外になる」と残っています。また `variant-composition` の union コメントも「異なるvariant列が同じchannelへ影響」と、確定した「単位全体の非空variant列集合 `S`」の条件と不一致です。修正案として、未終端系をすべて「診断を返し、当該ファイルの `occurrences` / `pairs` は空」に統一し、`variant-composition` のコメントも `|S| >= 2` の定義へ合わせてください。

[Critical] S3 で `CssVarReferenceScan` を導入した一方、S2の公開シグネチャは依然として参照配列を返しています。

```ts
scanCssVarReferencesSource(...): readonly CssVarReference[];
scanCssVarReferences(): readonly CssVarReference[];
```

このままでは診断を返せず、実装者がどちらを正本にするか判断する必要があります。修正案は両方を `CssVarReferenceScan` 戻り値へ変更し、利用側も `.references` と `.diagnostics` を明示的に消費する形へ統一することです。

[Warning] S3 の値走査は `var(` の部分文字列検出と「第1引数が `--` で始まる」までしか定義されていません。これでは `myvar(--x)`、`var(--x garbage)`、fallback内のトップレベルカンマの扱いが実装者依存です。修正案として、`var` の関数トークン境界、最初のトップレベルカンマによる名前とfallback全体の分離、custom property名の全体一致規則を定め、これらを固定検体に追加してください。

[Warning] S5 の「`UndecidableReason` unionから機械的に導出」はTypeScriptでは実行時に不可能です。修正案として、理由一覧を `as const` の実行時配列またはオブジェクトとして正本にし、`UndecidableReason` をその要素型から導出してください。fixture網羅・表示ラベル・pending説明も同じ実行時正本から導出します。

[Critical] S9 の「行内コードspanは1〜2個のバッククォート」という前提は成立しません。Markdownのcode spanは3個以上のdelimiterも使用できます。さらに、今回追加予定の `docs/design-system.md` 本文自身に「`> ` で始まる ``` の行」という3連markerが通常本文としてあり、確定した走査契約では診断になって必ず赤くなります。修正案として、今回は保守的契約を維持し、「正規fence行以外に3個以上のmarkerを記述すること自体を禁止」と明記したうえで、対象文書から連続marker表記を除いてください。3個以上のcode spanも診断になる正例ではなく拒否例として固定します。

[Warning] S9の文書修正文は引用fenceだけを禁止対象として説明していますが、実際の契約はlist・blockquote・行中を含むすべての非top-level markerを拒否します。またS12のD51にはなお「描画されない領域（HTMLコメントと囲みコード）」という旧表現が残っています。修正案として、S9の説明を「正規top-level fence以外の3個以上のmarker」に揃え、S9の節名、D28、D51をすべて「規範判定対象外領域」に統一してください。

## 全体判定

**CHANGES_REQUESTED**

Round 6で求めた設計の中心部分はほぼ閉じていますが、公開型の矛盾と、S9が変更対象文書自身を拒否する問題が残っています。現時点では実装者の独自判断なしに着手できる具体性には達していません。上記を形式契約へ反映すれば、実装フェーズへ引き渡せる水準です。
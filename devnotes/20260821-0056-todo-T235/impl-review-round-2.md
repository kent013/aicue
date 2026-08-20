Round 1 の主要指摘 3 件のうち、診断免除と保証範囲の扱いは解消しています。ただし、大小文字違いの重複属性が未検証で、走査器に再び fail-open となる可能性が残っています。

## ファイル別判定

### `AGENTS.md`

判定: 問題なし。

`file + reason + count` という実際の保証粒度と、同数置換を検出しない限界が明記されました。「未解決形は原則解消」「免除理由は必要最小限」という義務も明確です。

同一ファイル・同一理由・同数置換を保証外にする判断も妥当です。今回の免除理由は汎用 `Input.svelte` の属性転送というファイル単位の設計に対応しており、位置固定による偽陽性のコストに見合いません。共通規約 (b) の主張縮小手続きに適合しています。

### `tests/js/support/file-input-accept-inventory.ts`

判定: 問題なし。

`spread-attribute` 以外を判定の先頭で無条件違反にしたため、fail-closed の中核は回復しています。型だけでなく実行時にも拒否している点も適切です。

[Suggestion] `ExemptibleDiagnosticReason` と `EXEMPTIBLE_DIAGNOSTIC_REASONS` は同じ集合を二重定義しています。将来的には定数配列から型を導出すると、両者のずれを構造的に防げます。

```ts
export const EXEMPTIBLE_DIAGNOSTIC_REASONS = ["spread-attribute"] as const;
export type ExemptibleDiagnosticReason =
    (typeof EXEMPTIBLE_DIAGNOSTIC_REASONS)[number];
```

現在の負例は集合の不用意な拡張を検出するため、承認を妨げる問題ではありません。

### `tests/js/support/file-input-scan.ts`

[Critical] `attributeNamed()` は大小文字を正規化した後に最初の属性だけを返していますが、重複属性が parse で拒否されると確認したテストは同じ綴りの重複だけです。

```svelte
<input type="text" TYPE="file" accept="x" />
<input type="file" accept="x" ACCEPT="y" />
```

Svelte が属性名を元の大小文字のまま保持することは今回実測されています。そのため、重複検査も大小文字を区別している可能性を除外できません。上記が parse を通る場合、`attributeNamed()` は正規化後に複数候補を得ても先頭だけを採用し、後続属性を無言で捨てます。前者では実行時に file input になりうる要素を母集団から外す可能性があります。

大小文字違いの `type` と `accept` の重複も合成入力で実測してください。

- parse が拒否するなら `parse-failed` を固定する
- parse が受理するなら、正規化後に複数件ある場合を診断へ落とす

したがって、「重複属性の分岐はすべて到達不能」とする判断は、現時点では裏取り不足です。

[Warning] `FileInputScanResult.diagnostics` の説明が「判定側で免除目録と突き合わせる」となっていますが、実際に突き合わせるのは `spread-attribute` だけで、残りは無条件違反です。保証範囲の正本なので実態に合わせるべきです。

### `tests/js/architecture/file-input-scan.test.ts`

[Critical] 大小文字違いの重複属性に対する負例が不足しています。上記 2 形を追加し、parse または走査器のどちらかで必ず fail-closed になることを固定してください。

[Warning] 未解決形のテスト群の説明に、まだ「名指しの免除目録」とあります。現在の保証は `file + reason + count` であり、同数置換を意図的に保証外にしています。AGENTS.md と目録 docblock に合わせて表現を訂正すべきです。

同数置換を検出しない負のコントロール自体は妥当です。検出を保証しない境界を実装と文書で同期させる役割を果たしています。

## 判定してほしい点への回答

1. はい。`spread-attribute` 以外を無条件違反にしたことで fail-closed は回復しています。

2. はい。保証範囲の明示的縮小、docblock、AGENTS.md、機械 pin の組み合わせは共通規約 (b) に適合します。位置を鍵にしない判断にも反対しません。

3. 一部妥当です。同じ大小文字の重複が parse で拒否されるなら、その分岐の撤去は適切です。ただし、属性名を大小文字無視で扱う変更を入れた以上、大小文字違いの重複についても到達不能であることを実測する必要があります。

全レーン再実行前であること自体は今回の変更要求理由ではありません。上記の混在ケースを閉じた後、全レーン green を最終確認してください。

CHANGES_REQUESTED
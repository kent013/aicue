## Round 4 再レビュー結果

施策2の保証表現は正しく修正されました。施策5の動的要素・raw HTML検出も方向性は妥当ですが、`{@html}`免除の識別粒度に抜けがあります。

## 施策1

判定: **APPROVE**

変更なく、問題ありません。

## 施策2

判定: **APPROVE**

「中央メソッドの呼び出し」ではなく「同じ出力契約」を保証するという説明へ正しく修正されています。Featureテストの検出範囲も誇張されていません。

## 施策3

判定: **APPROVE**

変更なく、問題ありません。

## 施策4

判定: **APPROVE**

変更なく、問題ありません。

## 施策5

判定: **REQUEST_CHANGES**

`SvelteElement`と`HtmlTag`を実測し、未知の動的要素をfail-closedにした方針は適切です。

[Critical] `RAW_HTML_EXEMPTIONS` がファイル名だけをキーにしているため、免除済みファイル内で`{@html}`が増えても検出できません。

現在の設計では、`Security.svelte`に2件目、3件目の`{@html}`を追加しても、すべて同じファイル免除に一致します。免除配列長と件数pinは1のままなので、次の記述は成立しません。

> 現在の実在は1件  
> 免除が増えれば必ずレビューに見える  
> 実測と免除を両方向で突き合わせる

修正案:

- raw HTMLも `file + occurrence` をキーにする。
- `occurrence` はファイル内の`HtmlTag`の1始まりの序数とする。
- 実測raw HTML件数・免除配列長・一意キー数を件数pinと完全一致させる。
- `occurrence`の正整数、一意性、実測にない残置、未登録の実測を検査する。
- 同じ免除済みファイルに2件目の`{@html}`を追加した合成入力が「未登録」で落ちる負例を追加する。

例えば次の形です。

```ts
interface RawHtmlExemption {
    readonly file: string;
    readonly occurrence: number;
    readonly rationale: string;
}
```

diagnosticに一律で序数を持たせたくない場合は、`opaque-html`だけを別の実測recordへ分けるか、`ScanDiagnostic`をdiscriminated unionにして`opaque-html`だけ`occurrence`を必須にしてください。

[Warning] 更新後の関数シグネチャとgateの呼び出し例が一致していません。

関数は5引数です。

```ts
evaluateFileInputInventory(
    scan,
    inventory,
    countPin,
    rawHtmlExemptions,
    rawHtmlExemptionCountPin,
)
```

一方、gateの検査項目では依然として3引数で呼び出しています。このまま実装するとTypeScriptエラーになります。

修正案:

gateの記述を次の5引数へ合わせてください。

```ts
evaluateFileInputInventory(
    scan,
    FILE_INPUT_ACCEPT_INVENTORY,
    FILE_INPUT_COUNT,
    RAW_HTML_EXEMPTIONS,
    RAW_HTML_EXEMPTION_COUNT,
)
```

[Warning] diagnosticの扱いに説明上の矛盾があります。

現在は一方で、

> `diagnostics`は全件そのまま違反へ写す

としながら、別の箇所では免除済み`opaque-html`を違反にしないとしています。

修正案:

判定順序を明確にしてください。

- `opaque-html`以外のdiagnosticは無条件で違反
- `opaque-html`はraw HTML実測集合として免除目録と両方向比較
- 免除に一致した`opaque-html`だけは違反にしない

自己検査33も、`opaque-html`以外のdiagnosticを使うと免除検査35〜38との責務が明確になります。

## 全体判定

**CHANGES_REQUESTED**

施策1〜4は承認です。施策5について、raw HTML免除を`file + occurrence`単位へ変更し、5引数のgate呼び出しとdiagnosticの例外処理を整合させれば承認可能です。
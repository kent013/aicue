## 施策別判定

### 施策 1: 判定の単一化

**APPROVE**

`AdoptedReadyTakeCoverage` を唯一の述語とし、render の 422、preview の placeholder 判定、詳細画面の coverage を同じ基準へ集約する設計は妥当です。PHPStan level 10 向けの型定義と `Assert::notNull()` の使い方にも問題ありません。

### 施策 2: 事前告知

**REQUEST_CHANGES**

[Warning] 施策2の記載に旧仕様の `playbackJobId` が残っています。

施策3では `playbackJob` への完全置換を宣言していますが、施策2のController例と `RenderProps` はまだ以下の形です。

```ts
playbackJobId: number | null;
```

このままでは実装者がどちらを正本とするか判断できず、「旧キーを残さない」という設計原則とも矛盾します。

修正案: 施策2のコード例も次に統一してください。

```ts
export interface RenderProps {
    job: RenderJobProps | null;
    previewJob: RenderJobProps | null;
    playbackJob: RenderJobProps | null;
    coverage: TakeCoverageProps;
}
```

Controller例の `playbackJobId` も `playbackJob` に置き換えてください。

[Warning] 告知文が判定条件を正確に表していません。

`missing_count` は「未採用」だけでなく「採用済みだが `ready` ではないテイク」も数えます。それに対して、

> テイクが採用されていません  
> 未撮影のカットがあります

という文言は、採用済み・処理中または失敗状態のケースでは事実と異なります。「自然言語の文意はテストしない」という保証範囲は、この不一致を許容する理由にはなりません。

修正案: canonical criterion に合わせて、例えば次のようにしてください。

```text
使用できる採用テイクがないカットがあります

{missing_count} / {total_cuts} 件のカットに、撮影・処理が完了した採用テイクがありません。
プレビューは生成できますが、該当区間は黒背景になります。
```

タイトルを短くするなら「プレビューに黒背景の区間があります」でも構いません。

### 施策 3: 事後説明

**REQUEST_CHANGES**

Round 1 の指摘に対する `playbackJobId` から `playbackJob` への完全置換は適切です。IDと説明用jobを並走させず、動画URLと実績値を同一オブジェクトから出す方が強い設計です。Factoryの `succeeded()` / `legacySucceeded()` 分離も妥当です。

ただし、施策2に旧仕様が残っているため、詳細設計全体としてはまだ一貫していません。

[Warning] 事後説明にも「未撮影」という不正確な表現が残っています。

placeholder は未採用だけでなく、採用テイクが非readyの場合にも生成されます。

修正案:

```svelte
このプレビューは {playbackNote} 件のカットに使用可能な採用テイクがないため、
その区間が黒背景になっています。
```

また、`playbackJob` はnullableなので、動画要素を含むブロック全体が次の条件内にあることを設計例でも明示してください。

```svelte
{#if playbackJob !== null && !previewInFlight}
    <!-- 注記と video -->
{/if}
```

これによりSvelte/TypeScriptのnull安全性と既存の表示条件が明確になります。

### 施策 4: Architecture gate

**APPROVE**

findingの構造的原因が判定式の複製であるため、この範囲のdeny-by-default gateは過剰ではありません。検出限界も明記され、behavioral testとの役割分担もできています。

### 施策 5: ドキュメント同期

**APPROVE**

既存番号を変更せず末尾へ追加する方針、値契約と保証外範囲を同期する方針ともに妥当です。

## テスト計画

[Warning] 検証コマンドが、今回提示された最新の `AGENTS.md` と同期していません。

設計書には次のpackage系コマンドがありません。

```text
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

修正案: `AGENTS.md` の `VERIFICATION_COMMANDS` をそのまま正本として転記し、必要な `composer test:browser` を追加してください。

Browser E-2の非クリック化、B-1の具体的fixture、B-1bのmanifest単体検証、D-8のポーリング後追随は、Round 1の指摘を十分に閉じています。

## 全体判定

**CHANGES_REQUESTED**

Round 1の本質的な設計課題には適切に対応できています。残件は設計書内の旧 `playbackJobId` 記載、非readyケースを「未撮影・未採用」と断定する文言、検証コマンドの同期です。いずれも局所修正であり、新しい機構や一般化は不要です。
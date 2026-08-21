# 全体判定: CHANGES_REQUESTED

施策1系の指摘は十分に解消されています。施策2のフィールド別エラー設計も妥当です。

ただし、施策2bの `aria-live` は「動的読み上げを保証する」という目的を確実には満たしません。ここだけ設計修正が必要です。

## 施策1: F-4-01 — APPROVE

[Critical] なし。

[Warning] なし。

`wasChanged('email')`、型 narrowing、明示的な名前付きルートへの redirect、PIIを含めないflash、Fortify contractとしてのJsonResponse維持は、いずれも妥当です。

DTO/JsonResource、PHPStan level 10、認可境界、禁止事項との不整合もありません。

## 施策1-T: Featureテスト — APPROVE

[Critical] なし。

[Warning] なし。

Round 1の不足は解消されています。

- 通知送信を `Notification::assertSentTo()` で保証
- `/email/verify` のInertia `flash.success`まで検査
- stale→再認証→元PUT再送の統合経路を固定
- JSON文字列 `""` を正確に固定
- verified状態とrecent-auth状態を明示
- グローバル`RefreshDatabase`を維持

[Suggestion] `Notification::assertSentTo($user->fresh(), ...)` は成立しますが、通知Fakeはモデルクラスと主キーで照合するため、特に再取得が必要でなければ `$user` のままでも十分です。`fresh()` のnullable性をテストコードへ持ち込まない分、後者の方が単純です。

[Suggestion] Inertiaアサーションでは、可能なら `flash.success` と併せて `VerifyEmail.svelte` に対応するcomponent名も固定すると、「正しいpropsだが誤った画面」という後退も検出できます。承認を妨げる事項ではありません。

## 施策2: F-3-01 — APPROVE

[Critical] なし。

[Warning] なし。

per-field派生は従来のvalidation順序を維持しつつ、原因フィールドだけをinvalidにしています。

- threshold不正時はthresholdのみ
- max不正時はmaxのみ
- 個別範囲は有効だが大小関係違反の場合はmaxのみ
- 押下前は表示しない
- 訂正後は現在値へ追随する

FormField→Input→FormErrorの責務分離、Atomic Design、DESIGN.mdのtoken利用にも適合します。

## 施策2b: FormErrorの動的読み上げ — REQUEST_CHANGES

[Critical] なし。

[Warning] `{#if message}` の内側に `aria-live` 要素を新規挿入する方式では、エラーの読み上げを安定して保証できません。

現在案では、メッセージ発生時に次の要素が本文込みで同時にDOMへ追加されます。

```svelte
{#if message}
    <p aria-live="polite">{message}</p>
{/if}
```

live regionは、空の状態で先にDOMに存在し、その後テキストが更新された場合に最も確実に通知されます。要素と本文の同時挿入は、支援技術とブラウザの組み合わせによって通知されない場合があります。

また、設計中の次の説明は論理的に両立しません。

> live region挿入時の静的初期エラーは読み上げられない  
> 動的エラーはlive region挿入によって読み上げられる

どちらも「本文を持つlive regionの新規挿入」だからです。

修正案は次のいずれかです。

1. FormErrorのlive regionを常時DOMへ置く

```svelte
<p
    {id}
    class="text-caption text-danger"
    aria-live="polite"
    data-testid={testId}
>
    {message ?? ""}
</p>
```

ただし、全フォームで常時live regionが生成され、複数エラーが同時発生する場合の読み上げも変わるため、共有atom全体の影響確認が必要です。

2. AutoRechargeCard内に、常時存在する専用live regionを置く

可視エラーはFormFieldで表示し、スクリーンリーダー向けの空live regionを先に配置して、押下後にその本文だけを更新します。この方が変更範囲をF-3-01へ限定できます。

3. FormErrorをopt-inにする

`live?: "polite" | "assertive"` などを追加し、AutoRecharge側だけで有効化します。この場合はFormFieldへのprop forwardingと、それぞれのテストが必要です。今回だけの要件としては、やや変更範囲が広めです。

局所性を優先するなら、案2が最も安全です。

[Warning] 「全フォーム共通の底上げ、後退なし」「過剰通知リスクは実質ない」という結論は、現時点の調査範囲では断定できません。

FormErrorの直接利用元が2コンポーネントでも、FormFieldとCheckboxの間接利用箇所はアプリ全体に広がります。複数エラーの同時表示、サーバー検証エラーの再描画、入力中のエラー更新など、通知頻度が変わる可能性があります。

グローバル変更を選ぶなら、少なくとも間接利用の代表ケースと複数エラー表示を影響範囲に含めてください。

## 施策2-T: JSテスト — REQUEST_CHANGES

[Warning] live regionのテストが静的属性確認だけになっています。

次のテストでは「属性を付けた」ことしか確認できず、「通知可能な更新構造」を保証しません。

```ts
expect(error).toHaveAttribute("aria-live", "polite");
```

修正後の構造に応じ、次の状態遷移を検査してください。

1. エラーがない時点で空のlive regionがDOMに存在する
2. 操作後、同じ要素へエラー文言が入る
3. 訂正後、同じ要素の文言が消える、または更新される

少なくとも要素の同一性を保持したまま本文が更新されることを固定する必要があります。

[Warning] 「aria-invalidが付かない」という契約には、次を使用してください。

```ts
expect(input).not.toHaveAttribute("aria-invalid");
```

現在案の次の検査は、`aria-invalid="false"` が残っていても通ります。

```ts
expect(input).not.toHaveAttribute("aria-invalid", "true");
```

現行Input atomはfalse時に属性を省略する契約なので、属性自体が存在しないことを固定する方が正確です。

[Suggestion] threshold不正値は `"-1"` に確定し、「または `"abc"`」は削除してください。`type="number"` へ非数値文字列を設定した際のsanitize挙動はDOM実装に依存しやすく、今回の分岐検査には負数で十分です。

`getByRole()`、`toHaveAccessibleDescription()`、3分岐を異なる具体値で検査する方針は適切です。

## 横断設計 — APPROVE

[Critical] なし。

[Warning] なし。

テスト先行でfail理由を確認する手順、対象テストのgreen、PHP/PHPStan/JS/build/packageを含む全検証コマンドが明記されました。禁止事項と完了条件を満たしています。

残る修正は、施策2bを「先に存在するlive regionの本文更新」として再設計し、その状態遷移をテストすることです。
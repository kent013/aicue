# 全体判定: CHANGES_REQUESTED

実装設計は適切です。共有atom変更の撤回と、常在する局所live regionへの置換で、Round 2の主要な問題は解消されています。

残るのはテスト契約と保証範囲の表現だけです。

## 施策2 — APPROVE

[Critical] なし。

[Warning] なし。

次の設計は妥当です。

- live regionを初期描画時からDOMに置き、本文だけを更新する
- 可視エラーはFormField、変化時の通知は局所live regionに分離する
- FormError/FormField/Inputへ波及させない
- threshold-firstの単一エラーをミラーする
- `aria-describedby` とlive regionを異なるタイミングの支援に使う
- `sr-only`により可視メッセージを重複させない

[Suggestion] 「確実に通知される」「重複読み上げは起きない」は、支援技術ごとの差異まで保証する表現としては強すぎます。次のように保証範囲を狭めると正確です。

> 一般的なlive regionの推奨構造である、常在要素の本文更新にする。自動テストはDOM構造と状態遷移を保証し、実際の読み上げ挙動はブラウザ・支援技術に依存する。

重複についても「別タイミングで機能することを意図しており、同一画面への可視重複は作らない」程度が安全です。

## 施策2-T — REQUEST_CHANGES

[Critical] なし。

[Warning] `aria-live="polite"` 自体の回帰テストが計画から抜けています。

現在の状態遷移テストは、常在要素と本文更新、`sr-only`は保証しますが、`aria-live`属性が削除されても通ります。それでは動的読み上げの中核契約を固定できません。

同じテストで初期要素に次を追加してください。

```ts
const liveRegion = screen.getByTestId("auto-recharge-range-error");

expect(liveRegion).toHaveClass("sr-only");
expect(liveRegion).toHaveAttribute("aria-live", "polite");
expect(liveRegion).toBeEmptyDOMElement();
```

その同じ参照に対して、押下後と訂正後を検査します。

```ts
expect(liveRegion).toHaveTextContent(
    /リチャージ後の残高は 1 〜 1000 の整数/,
);

// 有効値へ訂正後
expect(liveRegion).toBeEmptyDOMElement();
```

同一参照を使い続ければ、実装が将来 `{#if}` に戻って要素を差し替えた場合も検出できます。

[Warning] live regionのthreshold側経路も固定してください。

現状の状態遷移テストはmaxエラーだけなので、次の誤実装でも通り得ます。

```svelte
{maxError ?? ""}
```

threshold不正テストへ、同じlive regionがthreshold文言を持つことのassertを追加すれば十分です。

```ts
expect(liveRegion).toHaveTextContent(
    /リチャージ開始残高は 0 以上の整数/,
);
```

[Suggestion] 「提示開始後は現在入力に追随する」という契約をより直接固定するなら、maxの範囲エラーから大小関係エラーへ、非空文言同士が切り替わるケースも有効です。ただし、threshold/max双方のlive-region経路と有効値へのクリアを固定すれば、承認必須条件ではありません。

## 施策1 / 施策1-T / 横断 — APPROVE維持

Round 2の承認を維持します。今回の追加反映も妥当です。

- `assertSentTo($user, ...)`への簡略化
- `component('Auth/VerifyEmail')`の固定
- テストファーストと全検証コマンド
- PHPStan、DTO/JsonResource、Inertia責務、セキュリティ規約への適合

施策2-Tへ `aria-live="polite"` とthreshold側live-region文言のassertを追加すれば、全体をAPPROVEDにできます。
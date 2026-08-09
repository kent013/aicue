## 再レビュー結果

Round 1 の指摘は適切に修正されています。`PricingPlanCard.svelte` への `data-testid="plan-price"` 追加も、計測可能性を確保するための限定的な変更であり、設計逸脱として許容できます。Atomic Design、DESIGN.md、表示挙動への影響もありません。

ただし、受入条件 11 に軽微な未固定箇所が残っています。

### `resources/js/components/molecules/PricingPlanCard.svelte` — APPROVE

`plan-price` の追加は妥当です。

- molecule の責務や公開インターフェースを変更していない
- スタイルやレイアウトに影響しない
- カード内部を外側から不安定な CSS セレクタで探索するより、安定した計測点を提供する方が堅牢
- 既存の `price-caption` とも整合する

「Checkout のみ変更」というファイル計画からは逸脱しますが、受入条件を正確に固定するために必要な最小変更なので問題ありません。

### `resources/js/pages/Onboarding/Checkout.svelte` — APPROVE

文言から固定の「プラン」を除いたことで、現在の seed 値と将来の名称変更の両方に耐えます。

`selectedPlanCode` による note の存在制御と、`chosenPlanCode` による文言制御も設計どおりです。

### `tests/Browser/CaptureCutNavigationTest.php` — APPROVE

Round 1 の指摘は解消されています。

- waiter が `bool` を返し、timeout を明示できる
- 戻る側も同じ waiter に統合されている
- desktop の非スクロールを区間観測している
- Chromium / WebKit の両レーンで通過している

50ms 間の一時的な移動を理論上見逃す余地はありますが、この変更の副作用は `focus` と `scrollIntoView` であり、500ms区間の10回観測として十分実用的です。

### `tests/Browser/OnboardingPlanSelectionA11yTest.php` — REQUEST_CHANGES

[Warning] 受入条件11で要求した「両カードの sr-only 状態」が完全には固定されていません。

現在検査しているのは、Standard 選択後の `plan-selected-note-standard` だけです。初期表示時の Starter note については、文言と存在は検査していますが、`sr-only` の不可視契約は検査していません。

同じクラスを通るため実装上の結果は同じですが、詳細設計は明示的に「両カードで note が不可視」としています。将来、状態やプランによって class が分岐した場合にはすり抜けます。

修正案として、不可視判定を共通のJS関数またはPHPヘルパにし、次の2時点で実行してください。

- Standard を押す前: `plan-selected-note-starter`
- Standard を押した後: `plan-selected-note-standard`

`tiny / absolute / hidden / clipped` の現在の4条件はそのままで問題ありません。

## 全体判定

**CHANGES_REQUESTED**

実装本体と Round 1 の修正内容は承認可能です。残件は受入条件11のテスト固定範囲だけで、`PricingPlanCard.svelte` への計測用 testid 追加は許容範囲です。
**判定**: **CHANGES_REQUESTED**

**総評**
- S1〜S5 の主旨には概ね一致し、テスト量・型・DS/Atomic 配慮も高水準です。
- ただし、仕様で明示された a11y 挙動（Escape でトリガーへフォーカス復帰）をテストで固定できておらず、実装も現状のテストでは担保不足です。加えて、1点設計との軽微な不一致があります。

**ファイル別レビュー**

- `app/Http/Middleware/HandleInertiaRequests.php`
  - **判定**: OK
  - **良い点**: `slug`/権限フラグ追加、`isMemberOf` 防御、`$user->can(..., $organization)` による current org 文脈評価は設計意図に整合。
  - **Security**: cross-org 漏れ防止の方向性は妥当。`response()->json()` 直書きなし。
  - **指摘**: なし

- `resources/js/lib/shared-props.ts`
  - **判定**: OK
  - **良い点**: `CurrentOrganization` が PHP 側 array-shape と実質 1:1。`OrganizationRoleValue` union で型安全性向上。
  - **指摘**: なし

- `resources/js/components/features/organizations/OrganizationSwitcher.svelte`
  - **判定**: 要修正
  - **Critical**
    - Escape の仕様は「閉じてトリガーへ focus 復帰」ですが、現行テストが `container.firstElementChild` へ `keydown` を投げる方式で、実装が `document` リスナ依存のため検証が間接的です。**フォーカス復帰の assert が欠落**しており、S3 の a11y 要件を固定できていません。
  - **Warning**
    - 設計文では「内部は atoms（Button）+ Inertia + Lucide」とある一方、実装はネイティブ `<button>` を採用。方針変更自体は合理的ですが、**設計書との不一致**として明記・合意が必要です。
  - **Suggestion**
    - `aria-controls` は閉状態でも問題ないが、将来の SR 挙動差を避けるなら `open` 時のみ実在要素参照になるよう補助属性運用を明示するとより堅牢。

- `resources/js/components/templates/AppLayout.svelte`
  - **判定**: OK
  - **良い点**: features→templates の依存方向は適合。常設配置も仕様どおり。
  - **指摘**: なし

- `tests/Feature/Organizations/OrganizationNavSharedPropsTest.php`
  - **判定**: OK
  - **良い点**: role別、直接付与、cross-org、dangling current の防御まで押さえており非常に良い。
  - **Security**: cross-org 分離の回帰防止として有効。
  - **指摘**: なし

- `tests/Feature/Organization/OrganizationSwitchTest.php`
  - **判定**: OK
  - **良い点**: slug 画面起点でも dashboard へ戻す契約を固定できている。
  - **指摘**: なし

- `tests/js/components/features/organizations/OrganizationSwitcher.test.ts`
  - **判定**: 要修正
  - **Critical**
    - Escape ケースで **「トリガーへ focus 復帰」** を検証していない（仕様未固定）。
    - Escape 発火対象が `container.firstElementChild` で、実装の `document` keydown リスナを直接検証していない。`fireEvent.keyDown(document, { key: "Escape" })` 等で意図を一致させるべき。
  - **Suggestion**
    - `onclick={close}` の Link クリックで close されることを 1 ケース追加すると状態遷移の抜け漏れ防止になる。

- `tests/js/components/templates/AppLayout.test.ts`
  - **判定**: OK
  - **良い点**: 常設表示と `shrink-0` 回帰を固定できている。
  - **指摘**: なし

**観点別まとめ**
- 設計一致性: **一部不一致**（Button atom 利用方針、Escape focus復帰の固定不足）
- 正確性/a11y/runes: **要改善**（Escape focus復帰の検証欠落）
- PHPStan/型: OK（提示結果も問題なし）
- DTO/JsonResource/Inertia: OK（本件は Inertia shared prop で妥当）
- テスト網羅: 概ね良好、ただし a11y の要件固定が 1 点不足
- セキュリティ: OK（cross-org, slug露出制御, org文脈権限評価の方向性は適切）
- DESIGN.md/Atomic: 概ね OK（トークン/Lucide/依存方向）

**リクエスト修正（最小）**
- `tests/js/components/features/organizations/OrganizationSwitcher.test.ts` に以下を追加/修正:
  - Escape 後に `expect(screen.getByTestId("org-switcher-trigger")).toHaveFocus()`
  - Escape 発火を `document` 対象で行い、実装経路と一致させる
- （設計整合）`OrganizationSwitcher.svelte` のボタン実装方針が atom 不使用で正なら、設計書側に差分理由を明記して整合を取る。
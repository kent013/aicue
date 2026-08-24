# 付録: 是正対象 token の逆引き表 (Codex Round 1 Warning 5-1 への対応)

対象 token: `primary` / `primary-hover` / `primary-soft` / `tertiary` / `tertiary-hover` /
`success` / `warning` (`primary-soft` は `primary` の派生なので同時に動く)。

**表の作り方**: 走査単位 (文字列リテラル) ごとに、素の宣言を「通常」、修飾の連なりを持つ宣言を
その修飾の状態として、状態の内側で前景 × 背景の組を作った。
非テキストのプロパティ (`border-*` / `ring-*` / `decoration-*` / `accent-*`) は
i17 により本 gate の対象外なので、そう分かる形で並べてある。
**行番号は持たない** (s14 — 無関係な 1 行の追加で期待値の機械的な更新が常態化するため)。

**読み方**:
- `bg-*` が付いた行 = 是正後の値で AA を再計算した対象 (`contrast-measurements.md` 参照)
- `(背景は同じ宣言に無い)` = 前景だけの単位。親から継承する背景なので i22 (2) の保証外
- `(前景は同じ宣言に無い)` = 塗り面だけの単位。テキストを載せていない (アイコン・トラック・帯)
- `(非テキスト = i17 対象外)` = 枠線・focus ring・下線・フォーム部品のアクセント色

| ファイル | 状態 | 前景 | 背景 |
|---|---|---|---|
| `components/atoms/Alert.svelte` | hover | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/atoms/Alert.svelte` | hover | `text-text` | `(背景は同じ宣言に無い)` |
| `components/atoms/Alert.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/Alert.svelte` | 通常 | `border-success` | `(非テキスト = i17 対象外)` |
| `components/atoms/Alert.svelte` | 通常 | `border-warning` | `(非テキスト = i17 対象外)` |
| `components/atoms/Alert.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/atoms/Alert.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/atoms/Alert.svelte` | 通常 | `text-success` | `(背景は同じ宣言に無い)` |
| `components/atoms/Alert.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `components/atoms/Alert.svelte` | 通常 | `text-warning` | `(背景は同じ宣言に無い)` |
| `components/atoms/Badge.types.ts` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/Badge.types.ts` | 通常 | `border-success` | `(非テキスト = i17 対象外)` |
| `components/atoms/Badge.types.ts` | 通常 | `border-tertiary` | `(非テキスト = i17 対象外)` |
| `components/atoms/Badge.types.ts` | 通常 | `border-warning` | `(非テキスト = i17 対象外)` |
| `components/atoms/Badge.types.ts` | 通常 | `text-primary` | `bg-primary-soft` |
| `components/atoms/Badge.types.ts` | 通常 | `text-success` | `bg-success/10` |
| `components/atoms/Badge.types.ts` | 通常 | `text-tertiary` | `bg-tertiary/10` |
| `components/atoms/Badge.types.ts` | 通常 | `text-warning` | `bg-warning/10` |
| `components/atoms/Button.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/atoms/Button.types.ts` | hover | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/Button.types.ts` | hover | `text-neutral` | `bg-primary-hover` |
| `components/atoms/Button.types.ts` | hover | `text-neutral` | `bg-tertiary-hover` |
| `components/atoms/Button.types.ts` | hover | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/atoms/Button.types.ts` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/Button.types.ts` | 通常 | `text-neutral` | `bg-primary` |
| `components/atoms/Button.types.ts` | 通常 | `text-neutral` | `bg-success` |
| `components/atoms/Button.types.ts` | 通常 | `text-neutral` | `bg-tertiary` |
| `components/atoms/Button.types.ts` | 通常 | `text-text` | `(背景は同じ宣言に無い)` |
| `components/atoms/Checkbox.svelte` | 通常 | `accent-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/DragHandle.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/atoms/TextLink.svelte` | 通常 | `decoration-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/TextLink.svelte` | 通常 | `decoration-primary/30` | `(非テキスト = i17 対象外)` |
| `components/atoms/TextLink.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/atoms/Toggle.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/atoms/Toggle.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/atoms/input-state.ts` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/input-state.ts` | 通常 | `ring-primary/20` | `(非テキスト = i17 対象外)` |
| `components/features/auth/PasskeySection.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/features/capture/CameraRecorder.svelte` | hover | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/features/capture/CameraRecorder.svelte` | hover | `text-text` | `(背景は同じ宣言に無い)` |
| `components/features/capture/CameraRecorder.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/features/capture/CameraRecorder.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `components/features/capture/TakeStrip.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/features/invitations/PendingInvitationList.svelte` | 通常 | `text-primary` | `bg-primary-soft` |
| `components/features/manual/AnalysisPanel.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/features/manual/RenderPanel.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/features/manual/ScenarioEditor.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/features/manual/ScenarioEditor.svelte` | 通常 | `text-success` | `(背景は同じ宣言に無い)` |
| `components/features/manual/TakePickerList.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary-soft` |
| `components/features/manual/TakePickerList.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/features/notifications/NotificationListItem.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/features/notifications/NotificationListItem.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary-soft/40` |
| `components/features/notifications/NotificationListItem.svelte` | 通常 | `text-primary` | `bg-primary-soft` |
| `components/molecules/ApiKeyTabNav.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/molecules/ApiKeyTabNav.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/molecules/Breadcrumb.svelte` | hover | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/molecules/Breadcrumb.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `components/molecules/CodeSnippet.svelte` | 通常 | `text-success` | `(背景は同じ宣言に無い)` |
| `components/molecules/OrganizationChoiceCard.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/molecules/PageHeaderSection.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/molecules/Pagination.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/molecules/Pagination.svelte` | 通常 | `text-neutral` | `bg-primary` |
| `components/molecules/PasswordInput.svelte` | hover | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/molecules/PasswordInput.svelte` | hover | `text-text` | `(背景は同じ宣言に無い)` |
| `components/molecules/PasswordInput.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/molecules/PasswordInput.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `components/molecules/PendingInvitationsNotice.svelte` | hover | `text-text` | `bg-primary-soft` |
| `components/molecules/PendingInvitationsNotice.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/molecules/PendingInvitationsNotice.svelte` | 通常 | `text-text` | `bg-primary-soft/40` |
| `components/molecules/PricingPlanCard.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/molecules/PricingPlanCard.svelte` | 通常 | `border-warning` | `(非テキスト = i17 対象外)` |
| `components/molecules/PricingPlanCard.svelte` | 通常 | `text-success` | `(背景は同じ宣言に無い)` |
| `components/molecules/PricingPlanCard.svelte` | 通常 | `text-text` | `bg-warning/10` |
| `components/molecules/PricingPlanCard.svelte` | 通常 | `text-warning` | `(背景は同じ宣言に無い)` |
| `components/molecules/StatCard.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary-soft` |
| `components/molecules/StatCard.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/molecules/Tabs.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/molecules/Tabs.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/organisms/Modal.svelte` | hover | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/organisms/Modal.svelte` | hover | `text-text` | `(背景は同じ宣言に無い)` |
| `components/organisms/Modal.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/organisms/Modal.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `components/organisms/RecentAuthModal.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/organisms/ToastContainer.svelte` | hover | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/organisms/ToastContainer.svelte` | hover | `text-text` | `(背景は同じ宣言に無い)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `border-success` | `(非テキスト = i17 対象外)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `border-warning` | `(非テキスト = i17 対象外)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `text-success` | `(背景は同じ宣言に無い)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `text-warning` | `(背景は同じ宣言に無い)` |
| `components/templates/AppLayout.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/templates/AppLayout.svelte` | 通常 | `ring-primary` | `(非テキスト = i17 対象外)` |
| `components/templates/AppLayout.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/templates/AuthLayout.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/templates/GuestLayout.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/templates/_helpers/SidebarNavItems.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `pages/Billing/PurchaseTickets.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Capture/Show.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `pages/Capture/Show.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `pages/Contact/Thanks.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Dashboard.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `pages/Debug/BfcacheTrial.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Debug/BfcacheTrialAway.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Debug/Login.svelte` | hover | `(前景は同じ宣言に無い)` | `bg-primary-soft` |
| `pages/Debug/Login.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-surface` |
| `pages/Guest/Pricing.svelte` | hover | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Guest/Pricing.svelte` | hover | `text-primary-hover` | `(背景は同じ宣言に無い)` |
| `pages/Guest/Pricing.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary-soft` |
| `pages/Guest/Pricing.svelte` | 通常 | `border-primary/30` | `(非テキスト = i17 対象外)` |
| `pages/Guest/Pricing.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Guest/Pricing.svelte` | 通常 | `text-text` | `bg-primary-soft` |
| `pages/Guest/Pricing.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `pages/Invitations/Invalid.svelte` | 通常 | `text-warning` | `(背景は同じ宣言に無い)` |
| `pages/Onboarding/BillingRequired.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Onboarding/Checkout.svelte` | 通常 | `accent-primary` | `(非テキスト = i17 対象外)` |
| `pages/Onboarding/Checkout.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Onboarding/Checkout.svelte` | 通常 | `text-primary` | `bg-primary/10` |
| `pages/Organizations/ApiKeys/Index.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-success/10` |
| `pages/Organizations/ApiKeys/Index.svelte` | 通常 | `border-success` | `(非テキスト = i17 対象外)` |
| `pages/Welcome.svelte` | hover | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Welcome.svelte` | hover | `text-primary-hover` | `(背景は同じ宣言に無い)` |
| `pages/Welcome.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `pages/Welcome.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Welcome.svelte` | 通常 | `text-primary` | `bg-primary-soft` |
| `pages/Welcome.svelte` | 通常 | `text-success` | `(背景は同じ宣言に無い)` |
| `pages/Welcome.svelte` | 通常 | `text-success` | `bg-success/10` |
| `pages/Welcome.svelte` | 通常 | `text-text` | `bg-primary-soft` |
| `pages/Welcome.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |

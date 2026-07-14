Round 1 の指摘へ対応しました。各判定 (対応 / 反論) を示します。

## S1

### [Critical] currentOrganization 不整合 (非所属 org id 残存) のフォールバック → 対応
`currentOrganizationProp()` に `if (! $user->isMemberOf($organization)) { return null; }` を追加
(既存 `User::isMemberOf()` を使用)。current が非所属 org を指しても slug/name を露出しない。
S5-a にケース 6「current_organization_id が所属外 → 共有 prop currentOrganization=null」を追加。

### [Warning] can() 2 回追加の性能 → 一部対応 (計測観点を明記、過剰最適化はしない)
organizationRole 呼び出しは currentOrganizationProp(1)+各 policy(2)=計 3 回/認証リクエスト。
laratrust の team-scoped 参照で軽微。リスク欄に明記し、N+1 顕在化時の最適化余地 (role ローカル解決) も
記載。ただし Policy 契約を歪める最適化は今回しない (オーバーエンジニアリング回避)。

### [Suggestion] 内部 VO へ集約 → 見送り
Inertia 共有 prop の array-shape に専用 VO は過剰。docblock array-shape + Feature テストで型ドリフト固定。

## S2

### [Suggestion] role をユニオン型に → 対応
`OrganizationRoleValue = "organization_owner" | "organization_admin" | "organization_member"` を定義し
`CurrentOrganization.role: OrganizationRoleValue | null` に変更。

## S3

### [Critical] router.post のURL直書きは route helper 方針とズレる → 反論
本プロジェクトは **Ziggy 未導入** (package.json/composer.json に無し、JS に route() ヘルパ無し)。
POST URL は全経路で文字列パス直書きが既存標準:
- `Auth/Login.svelte` `form.post("/login")`
- `Organizations/Create.svelte` `form.post("/organizations")`
- `Admin/Users.svelte:181` `inviteForm.post(\`/organizations/${organizationSlug}/invitations\`)`
- `Projects/Show.svelte:89` `addForm.post(\`/projects/${project.id}/items\`)`
設計の `router.post(\`/organizations/${org.id}/switch\`)` はこの既存標準に一致。route helper 導入は
本施策スコープ外の広域変更のため行わない。設計に「Ziggy 未導入・文字列パス直書きが既存標準」を明記。

### [Warning] 現在組織行の no-op 押下 → 対応
現在組織行は切替 button にしない。`aria-current="true"` +「現在の組織」ラベル + Check アイコンの
非対話行として描画 (押下要素にしないため送信も発生しない)。disabled は付けない (禁止事項 8 非抵触)。

### [Warning] click-outside が pointerdown のみ → 対応
ルート要素の `onfocusout` で relatedTarget がルート外なら閉じる処理を併用。テストで
Escape / outside pointerdown / focusout の 3 経路の閉動作を固定。

### [Suggestion] aria-labelledby → 対応
パネルに `aria-labelledby="org-switcher-trigger"`、トリガーに `id="org-switcher-trigger"` を付与。

## S4

### [Warning] 折返しリスクの検証観点 → 対応
S5-c (AppLayout テスト) にトリガーの `shrink-0` クラス存在の assertion を追加 (回帰防止)。

## S5

### [Critical] OrganizationSwitchController の明示認可不足 → 反論
switch は「自分の current_organization_id を X にする」= ユーザー自身の状態変更で、必要な認可は
「X のメンバーであること」のみ。これは `MembershipScopedOrganizationBinder` が membership スコープで
解決し非メンバー/不在を等しく 404 に倒すことで**構造的に強制**される (view 認可 = membership と同義)。
既存テスト `OrganizationSwitchTest` L28-36「所属していない組織へは切り替えられない (404)」がこの
認可契約を固定済み。controller への明示 `Gate::authorize` 追加は同一判定の二重化かつ本 controller は
変更対象外 (スコープ外改修) のため行わない。S5-b にこの認可契約の参照を明記。

### [Warning] currentOrganization=null ケースの結合テスト → 対応
S5-a ケース 6 (null フォールバック)、S5-c ケース 7 (null で「組織を作成」表示・切替/管理リンク非表示) を追加。

### [Suggestion] canManageApiKeys=true 時の表示復活テスト → 対応
S5-c ケース 6 に「canManageMembers/canManageApiKeys=true でそれぞれリンクが表示される」復活ケースを追加。

### [Suggestion] ds-purity / atomic-import-graph の回帰固定明文化 → 対応
S3 に `pnpm test` (ds-purity / atomic-import-graph 含む) を回帰固定として明記。

---

以上を反映しました。反論 2 点 (S3 route helper=Ziggy 未導入で文字列パスが既存標準 / S5 switch 認可=binder
membership スコープが既存 404 テストで固定済み) の妥当性を含め、残課題があればご指摘ください。無ければ
APPROVED をお願いします。

# 対応マトリクス: design-review Round 1

## [Critical] S1: currentOrganization 不整合 (非所属 org id 残存) のフォールバック未記載
- 判断: 対応する
- 根拠: defense-in-depth として妥当。current_organization_id が何らかの理由で非所属 org を
  指した場合に slug/name を露出しないよう保険をかける。`User::isMemberOf($org)` が既存。
- 対応内容: `currentOrganizationProp()` で `$user->isMemberOf($organization)` を検証し、
  非メンバーなら `null` フォールバック。S5-a に「current_organization_id が所属外 → 共有 prop
  currentOrganization=null」テストを追加。

## [Warning] S1: can() 2 回追加の性能・role ローカル化
- 判断: 一部対応 (計測観点を明記、過剰最適化はしない)
- 根拠: role 解決重複は request-scoped で軽微。マイクロ最適化 (role のローカル化 + policy への
  引き回し) は Policy 契約を歪めるためオーバーエンジニアリング。ただし懸念は明記する。
- 対応内容: リスク欄に「organizationRole 呼び出しは currentOrganizationProp(1) + 各 policy(2) の
  計 3 回/認証リクエスト。laratrust の team-scoped 参照で軽微。将来 N+1 が顕在化したら
  role をローカル解決して policy に渡す最適化余地あり」と明記 (今回は実装しない)。

## [Suggestion] S1: array-shape を内部 VO に集約
- 判断: 見送る
- 根拠: Inertia 共有 prop の array-shape に専用 VO は過剰 (オーバーエンジニアリング禁止)。
  型ドリフトは array-shape docblock + Feature テストで十分固定。

## [Suggestion] S2: role を enum value のユニオン型に
- 判断: 対応する
- 対応内容: `shared-props.ts` に `OrganizationRoleValue = "organization_owner" | "organization_admin"
  | "organization_member"` を定義し `CurrentOrganization.role: OrganizationRoleValue | null` に。

## [Critical] S3: router.post のURL直書きは route helper 方針とズレる
- 判断: 反論する
- 根拠: 本プロジェクトは **Ziggy 未導入** (package.json/composer.json に無し、JS に route() ヘルパ
  なし)。POST URL は全経路で文字列パス直書きが既存標準:
  `Auth/Login.svelte` `form.post("/login")`, `Organizations/Create.svelte` `form.post("/organizations")`,
  `Admin/Users.svelte:181` `inviteForm.post(\`/organizations/${organizationSlug}/invitations\`)`,
  `Projects/Show.svelte:89` `addForm.post(\`/projects/${project.id}/items\`)`。
  設計の `router.post(\`/organizations/${org.id}/switch\`)` はこの既存標準に完全一致。route helper
  導入は本施策スコープ外の広域変更。
- 対応内容: 設計に「URL は既存標準の文字列パス直書き (Ziggy 未導入)」を明記し反論。

## [Warning] S3: 現在組織行の no-op 押下は誤操作誘発
- 判断: 対応する
- 対応内容: 現在組織行は切替 button ではなく非対話の現在表示にする。`aria-current="true"` +
  「現在の組織」ラベル + Check アイコン。押下しても switch は送らず (onclick は close のみ)、
  disabled 属性は付けない (禁止事項 8 非抵触)。

## [Warning] S3: click-outside が pointerdown のみでキーボード focusout に弱い
- 判断: 対応する
- 対応内容: `pointerdown` (outside クリック) に加え、ルート要素の `focusout` で
  relatedTarget がルート外なら閉じる処理を併用。テストで Escape / outside pointer / focusout の
  3 経路の閉動作を固定。

## [Suggestion] S3: aria-labelledby でトリガー関連付け
- 判断: 対応する
- 対応内容: パネルに `aria-labelledby="org-switcher-trigger"` を付与しトリガーと関連付け。

## [Warning] S4: 折返しリスクの検証観点不足
- 判断: 対応する
- 対応内容: S5-c の AppLayout テストで、トリガー描画に加え `shrink-0` クラス存在を固定 (回帰防止)。

## [Critical] S5: OrganizationSwitchController に明示的認可がない (binder 依存のみ)
- 判断: 反論する (binder membership スコープが認可契約)
- 根拠: switch は「自分の current_organization_id を X にする」= ユーザー自身の状態変更で、
  必要な認可は「X のメンバーであること」のみ。これは `MembershipScopedOrganizationBinder` が
  membership スコープで解決し非メンバー/不在を等しく 404 にすることで**構造的に強制**されている
  (既存テスト `OrganizationSwitchTest` L28-36「所属していない組織へは切り替えられない (404)」が固定済み)。
  view 認可 = membership と同義であり、controller への `Gate::authorize('view')` 追加は
  同じ判定の二重化 (かつ本 controller は今回の変更対象外 = スコープ外改修)。
- 対応内容: S5 に「switch の認可契約 = binder membership スコープ (既存 404 テストが固定)」を
  明記して反論。controller は変更しない。

## [Warning] S5: currentOrganization=null ケースの shared prop / フォールバック結合テスト不足
- 判断: 対応する
- 対応内容: S5-a に「currentOrganization=null 時に prop が null」ケース (上記 S1 Critical と統合)、
  S5-c に「currentOrganization=null で『組織を作成』表示・切替/管理リンク非表示」を追加。

## [Suggestion] S5: canManageApiKeys=true 時の表示復活テスト
- 判断: 対応する
- 対応内容: S5-c の権限出し分けケースに「canManageApiKeys=true で API キーリンクが表示される」を追加。

## [Suggestion] 横断: ds-purity / atomic-import-graph の回帰固定を明文化
- 判断: 対応する
- 対応内容: 検証コマンド節に `pnpm test` (ds-purity / atomic-import-graph 含む) の通過を実装完了条件として明記。

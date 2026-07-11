# Round 2: Round 1 指摘への対応報告

Round 1 の全 Critical / Warning に対応した。対応内容と修正後の概念設計（該当節）を示す。再レビューし、全体判定（APPROVED / CHANGES_REQUESTED）を出してほしい。

## 対応マトリクス

### [Critical] current org null を「組織なし」と同一視 → 対応済み
コードベースで裏取りした事実: `OrganizationMembershipService::removeMember` は除名時に `current_organization_id` を null 化し「次回アクセス時に選び直す」とコメントするが、**選び直す実装は現状どこにも存在しない**。指摘のとおり「所属あり・current null」は正規に発生する。

概念設計に「表示組織の解決規則」節を追加:
1. `$user->currentOrganization` 非 null → それを表示組織
2. null かつ所属組織あり → `$user->organizations()->orderBy('organizations.id')->first()` で決定的に選択し、`forceFill(['current_organization_id' => ...])->save()` で**自己修復**（`OrganizationController::store` / `OrganizationSwitchController` と同一の書き込み経路。サーバ導出のみ・payload 不信任は不変。表示と shared props `currentOrganization` の不一致を作らない）
3. 所属組織 0 件のみ → 組織作成 CTA の setup 表示

Feature テスト必須化: 「org はあるが current null → 自己修復して 200 + 当該 org のデータ表示」。

### [Critical] 未契約時の購読 CTA 遷移先の ungated 保証 → 対応済み（実装事実の確認 + 設計固定）
`routes/web.php` の実装事実: `/billing`（billing.index, L307）と `/purchase-tickets`（billing.tickets.show, L319）は `require-active-subscription` group（L348〜の業務 route group）の**外**に登録済みで、route コメントに「未契約でも checkout に到達できることを保証する」と明文化されている。

概念設計に「callout / 残高 CTA の遷移先は billing.index / billing.tickets.show に固定（両 route は課金ゲート外の既存事実）」を明記し、「未契約 org: dashboard 200 + CTA 遷移先 route も未契約で 200」を DashboardTest の不変条件として固定する。

### [Warning] project 作成 CTA の権限判定経路 → 対応済み
既存 `ProjectPolicy::create`（`$user->can('create', [Project::class, $organization])` = organizationRole の laratrust_team_id 明示判定）に委譲。ad hoc 判定を書かないことを設計に明記。

### [Warning] DashboardService 戻り値の肥大化 → 対応済み
ブロック単位 DTO 分割を明記: `DashboardPageData` を頂点に `InProgressManualData` / `RecentManualData` / `ShootingTargetData` / `BillingSummaryData` 等。nullable 状態は各 DTO で明示。

### [Warning] 効果主張が強い → 対応済み
「残高不足と容量逼迫の早期気づきを増やす」に修正。UI では低残高警告と高使用率警告を別個の表示として扱う。

### [Warning] スナップショットの stale リスク → 対応済み
進行中ジョブ項目にジョブ updated_at の「最終更新」表示を追加し、「詳細で最新の進捗を確認」導線（projects.manuals.show の既存ポーリングパネル）を明示。

### [Warning] 1 PR の観点過多 → 対応済み
「バックエンド集計（Controller/Service/DTO + Pest）→ フロント表示（Svelte + Vitest）の順にコミットを分ける」を制約・前提に追記。

### [Warning] 未読数 props 契約の 2 系統化 → 対応済み（TS 型合成）
サーバ側で DTO に未読数を載せ直すと同一リクエスト内で同じクエリが 2 回走る（shared props closure は全 Inertia 応答で評価される）ため、サーバ二重集計は避ける。代わりに `resources/js/types/dashboard.ts` でページ契約を `DashboardProps & SharedProps` の**合成型として 1 箇所に定義**し、未読数は SharedProps 側の `notifications.unreadCount` を型経由で参照する（契約の分裂を防ぎつつ二重集計もしない）。

## 修正後の概念設計（変更節の全文）

### 表示組織の解決規則（current org null ≠ 組織なし）

`current_organization_id` は「所属組織 0 件」以外でも null になり得る（`OrganizationMembershipService::removeMember` が current org からの除名時に null 化し「次回アクセス時に選び直す」とコメントしているが、選び直す実装は現状どこにも存在しない）。ダッシュボードはこの選び直しの実装点となる:

1. `$user->currentOrganization` が非 null → それを表示組織とする
2. null かつ所属組織あり → `$user->organizations()->orderBy('organizations.id')->first()` で決定的に 1 組織を選び、`forceFill(['current_organization_id' => ...])->save()` で自己修復（`OrganizationController::store` / `OrganizationSwitchController` と同一の書き込み経路 = forceFill・サーバ導出のみ。payload 不信任は不変）。表示と shared props `currentOrganization` の不一致を作らない
3. 所属組織 0 件 → 組織作成 CTA の setup 表示（`organizations.create`）

### 状態分岐（詰み防止）

- 所属組織 0 件 → setup 表示
- org はあるが project なし → プロジェクト作成 CTA。表示判定は既存 `ProjectPolicy::create` に委譲。権限なしメンバーには案内文のみ
- project はあるがマニュアル 0 件 → 「最初のマニュアルを作成」CTA（編集者）/ 「撮影対象はまだありません」（撮影者）
- サブスク未契約 org → ダッシュボードは表示（課金ゲート外）。callout / 残高 CTA の遷移先は billing.index / billing.tickets.show に固定（両 route は課金ゲート外に登録済みの既存事実）。DashboardTest で「未契約 org: dashboard 200 + CTA 遷移先 route も 200」を固定。業務 route への遷移は既存 middleware が billing へ誘導（ダッシュボード側で二重実装しない）

# 対応マトリクス: conceptual-review Round 1

## [Critical] current org null を「組織なし」と同一視している
- 判断: 対応する
- 根拠: コードベースで裏取りした。`OrganizationMembershipService::removeMember` は除名時に `current_organization_id` を null 化し「次回アクセス時に選び直す」とコメントするが、選び直す実装は現状存在しない。指摘どおり「所属あり・current null」は正規に発生する状態
- 対応内容: 概念設計に「表示組織の解決規則」節を追加。(1) currentOrganization 非 null → そのまま、(2) null かつ所属あり → `organizations()->orderBy('organizations.id')->first()` で決定的に選び forceFill で自己修復（既存の OrganizationSwitchController と同一書き込み経路）、(3) 所属 0 件のみ組織作成 CTA。「org あり current null → 自己修復して 200」の Feature テストを必須化

## [Critical] 未契約時の購読 CTA 遷移先が ungated である保証がない
- 判断: 対応する（設計への明文化。実装事実の確認済み）
- 根拠: `routes/web.php` を確認。`/billing` (L307-312) と `/purchase-tickets` (L319-322) は `require-active-subscription` group（L348〜）の**外**に登録済みで、route コメントに「未契約でも checkout に到達できることを保証する」と明記されている
- 対応内容: 概念設計に「CTA 遷移先は billing.index / billing.tickets.show に固定（両 route は課金ゲート外の既存事実）」を明記し、「未契約 org: dashboard 200 + CTA 遷移先 route も 200」の Feature テストで不変条件として固定

## [Warning] project 作成 CTA の権限判定経路が未明示
- 判断: 対応する
- 根拠: ad hoc 判定は laratrust_team_id 明示原則からの逸脱リスク。既存 `ProjectPolicy::create`（organizationRole 委譲）がそのまま使える
- 対応内容: 「表示判定は `$user->can('create', [Project::class, $organization])` = ProjectPolicy::create に委譲。ad hoc 判定を書かない」と明記

## [Warning] DashboardService の戻り値 shape 肥大化
- 判断: 対応する
- 対応内容: 「ブロック単位の DTO 分割（DashboardPageData を頂点に InProgressManualData / RecentManualData / ShootingTargetData / BillingSummaryData 等。nullable 状態を各 DTO で明示）」を実装方針に明記

## [Warning] 「残高不足エラーの事前予防」の効果主張が強い
- 判断: 対応する
- 対応内容: 期待効果を「残高不足と容量逼迫の早期気づきを増やす」に修正し、低残高警告と高使用率警告を UI 上別個に扱うことを明記

## [Warning] スナップショット表示の stale リスク
- 判断: 対応する
- 対応内容: 進行中ジョブ項目にジョブ updated_at の「最終更新」表示を追加し、「詳細で最新の進捗を確認」導線を明示

## [Warning] 1 PR のレビュー観点過多
- 判断: 対応する
- 対応内容: 「バックエンド集計 → フロント表示の順でコミットを分ける」を制約・前提に追記（詳細設計の実装モードで具体化）

## [Warning] 未読数の props 契約が 2 系統に割れる
- 判断: 対応する（TS 型合成で契約を 1 本化。サーバ二重集計はしない）
- 根拠: サーバ側で DTO に unreadCount を載せ直すと同一リクエスト内で同じクエリが 2 回走る（shared props closure は全ページで評価される）。型契約の一本化は TS 側の合成で達成できる
- 対応内容: `resources/js/types/dashboard.ts` に「`DashboardProps & SharedProps` の合成としてページ契約を 1 箇所に定義」と明記

## [Suggestion] 各所（方向性・スコープ・typed array DTO の許容）
- 判断: 現設計のまま（肯定的評価のため対応不要）

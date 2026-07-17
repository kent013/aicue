# 対応マトリクス: impl-review Round 1（CHANGES_REQUESTED / Critical 0・Warning 2・Suggestion 3）

P2 の本体（`BillingAccess::state()` / `SubscriptionService` / webhook / models / migrations / テスト群）は**全て APPROVE**、
実装者申告の**逸脱 4 件も全て「妥当」**と判定された。CHANGES_REQUESTED は Warning 2 件のみ。

## [Warning] `RenameOrganizationAction` + `BillingCustomerSynchronizer` は P2 のスコープ拡張（別 PR へ分離すべき）
- 判断: **反論する（根拠あり）**
- 根拠:
  1. **設計 P2 が明示的に配置している**（詳細設計 P2 変更箇所表 L59-61）:
     `BillingCustomerSynchronizer`（verbatim）/ `SyncBillingCustomerDetails`（Job）/ `RenameOrganizationAction` +
     `OrganizationController:98-108` の改修。Codex 自身も「**設計本文には入っていますが**」と認めている。
  2. 当該設計は **Codex 合議 16 ラウンドで APPROVED 済み**。実装フェーズで phase 配置を独断で変えるのは、
     承認済み設計からの逸脱にあたる（v2 原則: 逸脱は AGENTS.md 抵触時のみ）。
  3. **aigenba verbatim**（`BillingCustomerSynchronizer` は aigenba に実在し、rename 経路が発火点）。
     v2 原則は「aigenba にあるものは verbatim 移植」。
  4. 設計は **contact 経路（P9）と rename 経路（P2）を意図的に分けている**。rename だけ P2 に置くのは
     「P2 = サブスク層の配線」という phase 定義に沿う。
  5. **Architecture テストで不変条件を固定済み**（`BillingSyncDispatchInvariantTest`: `SyncBillingCustomerDetails::dispatch`
     の呼び出し元は `BillingCustomerSynchronizer` のみ = aigenba IV-2）。side-effect は 1 箇所に閉じている。

## [Warning] `BillingPermissionService` の直接付与モデルは P2 DoD 外の機能追加
- 判断: **反論する（根拠あり）**
- 根拠:
  1. **設計 P2 が実装契約まで規定している**（L62-64）: `BillingPermissionService`（grant/revoke/hasDirectPermission/
     getDirectManageBillingMap/ensureTeamId）/ `PermissionSeeder` への `manage-billing` 追加 /
     `OrganizationPolicy::manageBilling` を `manageApiKeys` と**同型**の OR 参照へ。
  2. **横断決定 D9 で「service + Policy の OR 参照のみ移植し、付与 UI は別 TODO」と既に切り分け済み**。
     実装はこの線を正確に守っている（**付与 UI は作っていない**）。
  3. **付与がゼロである限り挙動は不変**（誰にも直接付与されないため `manageBilling` の結論は現行と同値）。
     機能拡張ではなく、**P4 のゲート反転が参照する認可基盤の先行配置**。
  4. **aigenba verbatim**（aigenba に実在するサービス）。
- なお Codex の「P2 は挙動不変が DoD」という前提は **Round 13 で撤回済み**（P2 は cohort C/D を反転させる）。
  よって「DoD 外」の判断基準自体が更新されている。

## [Suggestion] Architecture テストに「`state()` で `plan_code` 判定禁止」を追加
- 判断: **見送る**（v2 原則「設計に無いものを足さない」）。
  ただし**指摘の価値は認める** — この guard があれば、私が過去に作り込んだ D26（`plan_code` 依存の解決順）を
  自動検出できた。設計の別 TODO（test テーマ）候補として記録する。

## [Suggestion] `ACTIVE_SUBSCRIPTION_STATUSES` の対応コメント / `InvalidArgumentException` のドメイン例外化
- 判断: **見送る**（同上。必須ではないと Codex 自身が明記）。

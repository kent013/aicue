Round 1 の Warning 2 件について、**根拠を添えて反論**したい。Suggestion 3 件は v2 原則により見送る。

## [Warning] RenameOrganizationAction + BillingCustomerSynchronizer / BillingPermissionService は P2 スコープ外

**反論する**。理由:

1. **設計 P2 が明示的に配置している**（詳細設計 P2 変更箇所表）:
   - L59: `BillingCustomerSynchronizer`（**verbatim**。stripe_id null は no-op / `SyncBillingCustomerDetails::dispatch($org)->afterCommit()`）
   - L60: `app/Jobs/Billing/SyncBillingCustomerDetails.php`
   - L61: `RenameOrganizationAction` + `OrganizationController:98-108` の改修
   - L62: `BillingPermissionService`（grant/revoke/hasDirectPermission/getDirectManageBillingMap/ensureTeamId まで規定）
   - L63: `PermissionSeeder` へ `manage-billing` 追加
   - L64: `OrganizationPolicy::manageBilling` を `manageApiKeys` と**同型**の OR 参照へ
   あなた自身も「**設計本文には入っていますが**」と認めている。

2. **当該設計はあなたとの合議 16 ラウンドで APPROVED 済み**。実装フェーズで phase 配置を独断で変えるのは、
   承認済み設計からの逸脱にあたる（v2 原則: **逸脱は AGENTS.md 抵触時のみ**。実装者・レビュアーの設計判断は根拠にしない）。

3. **aigenba verbatim**。`BillingCustomerSynchronizer` は aigenba に実在し rename 経路が発火点。
   v2 原則は「aigenba にあるものは verbatim 移植」であり、**移植しない**方が逸脱になる。

4. 設計は **contact 経路（P9）と rename 経路（P2）を意図的に分けている**。rename だけ P2 に置くのは phase 定義に沿う。

5. **`BillingPermissionService` は付与がゼロである限り挙動不変**（誰にも直接付与されないため `manageBilling` の結論は現行と同値）。
   **付与 UI は作っていない**（横断決定 **D9**: 「service + Policy の OR 参照のみ移植し、付与 UI は別 TODO」）。
   機能拡張ではなく、**P4 のゲート反転が参照する認可基盤の先行配置**。

6. **side-effect は Architecture テストで 1 箇所に固定済み**（`BillingSyncDispatchInvariantTest`:
   `SyncBillingCustomerDetails::dispatch` の呼び出し元は `BillingCustomerSynchronizer` のみ = aigenba IV-2）。

7. なお「**P2 は挙動不変が DoD**」という前提は **Round 13 であなたの指摘により撤回済み**（P2 は cohort C/D を反転させる）。
   よって「DoD 外」の判断基準自体が更新されている。

## [Suggestion] 3 件

- **Architecture テストに「`state()` で `plan_code` 判定禁止」を追加** → **見送る**（v2 原則「設計に無いものを足さない」）。
  ただし**指摘の価値は認める**: この guard があれば、私が過去に作り込んだ D26（`plan_code` 依存の解決順）を自動検出できた。
  test テーマの別 TODO 候補として記録した。
- `ACTIVE_SUBSCRIPTION_STATUSES` の対応コメント / `InvalidArgumentException` のドメイン例外化 → **見送る**
  （あなた自身が「必須ではない」と明記）。

この反論が通るなら、P2 本体は既に全ファイル APPROVE + 逸脱 4 件も妥当と判定されているため、**全体判定 APPROVED** を出してほしい。
反論に穴があれば指摘してほしい。

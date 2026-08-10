# 対応マトリクス: design-review Round 3

## [Critical] B4: `billing.auto-recharge.update` は凍結中に自動チャージを有効化・増額できる入口になる

- 判断: **対応する (allowlist から外す)**
- 根拠: 指摘のとおり、同じ更新 endpoint が有効化・閾値変更・数量変更を受けるなら、
  allowlist が**新しい課金責務を作る入口**になり deny-by-default の凍結契約と矛盾する。
  「方向制約つきの専用 route を新設する」案もあるが、実コードを読むと**そもそも不要**だった:
  - `AutoRechargeTriggerJob` を dispatch する箇所は **`TicketLedgerService::reserve()` の 1 箇所だけ**
    (実読で確認。L453)。
  - `reserve()` を呼ぶのは解析・レンダ等の**業務フロー**であり、**業務 route は凍結で全部止まる**。
  - したがって**凍結中に自動チャージが発火する経路が構造的に存在しない**。
    「凍結中に自動購入を止める手段」を allowlist で用意する必要がない。
- 対応内容:
  - `AccountDeletionFreezeAllowance` から **`AutoRechargeUpdate` case を削除**した。
    課金系で通すのは **`billing.index` と `billing.portal` の 2 本だけ**になった。
  - 上記の「発火経路が無い」根拠を設計文に明記し、**behavioral テストで固定する**:
    「予約中は `billing.auto-recharge.update` / `billing.auto-recharge.setup` が遮断される」
    「**予約中は `AutoRechargeTriggerJob` が 1 件も dispatch されない**」
    (`Queue::fake()` ではなく実 `jobs` 表で観測する。AGENTS.md ドメイン規約 11 の作法)。
  - **方向制約つき専用 route は新設しない** (思考原則 2。必要ないものを作らない)。

## [Warning] B4: `billing.portal` にも方向性リスクがある

- 判断: **対応する (既存の構造的保証を設計へ明記する)**
- 根拠: 実コードを読むと**既に縮小方向だけに制限されている**。
  `app/Services/Billing/PortalConfigurationSpec.php` が
  **`subscription_update => ['enabled' => false]`** /
  **`subscription_cancel => ['enabled' => true, 'mode' => 'at_period_end']`** を宣言し、
  `CashierStripeGateway` は「subscription_update 無効の spec 準拠 configuration で
  portal session を作る」。運用検証は `billing:ensure-portal-configuration --verify` が持つ。
  したがって Portal からは**プラン変更・新規契約ができず、解約と支払い方法更新だけ**ができる。
- 対応内容: この事実 (spec のファイル名・宣言値・検証コマンド) を設計文へ明記し、
  **「凍結中に `billing.portal` を通してよい根拠は `PortalConfigurationSpec` の
  `subscription_update: false` である」**と結び付けた。
  併せて **「spec が変わったら凍結の前提が崩れる」**ことを設計の依存関係として書き、
  `billing.portal` を allowlist に置く根拠 (`rationale()`) に spec への参照を含める。
  **新しい configuration 検証機構は作らない** (既存の `--verify` がある)。

## [Warning] B1/B2: `users` の予約列に DB 制約が無く状態機械が fail-closed でない

- 判断: **対応する (指摘が正しい)**
- 根拠: 片列状態になると `isPending()` は false → 凍結を通過 → `cancelAccountDeletion()` も no-op で
  解消できず → 日次バッチが**毎日 FAILURE を出し続ける**。検出はできても**解消できない**ので
  状態機械として閉じていない。
- 対応内容: migration に **CHECK 制約 2 本**を追加した。
  1. `(deletion_requested_at IS NULL AND deletion_purge_after IS NULL)
     OR (deletion_requested_at IS NOT NULL AND deletion_purge_after IS NOT NULL)`
  2. **`deletion_purge_after >= deletion_requested_at`** (両列 non-null だが期限が予約時刻より前、
     という別の非正規状態も防ぐ。Suggestion を採用)
  - テスト: 片列だけの INSERT/UPDATE を **DB が拒否する** / 期限が予約時刻より前の行を拒否する。
  - **migration の precondition 検査**: 制約を張る前に非正規データが 0 件であることを
    **非破壊 (SELECT のみ)** で確認する。新規列なので理論上 0 件だが、
    「制約追加 migration は既存データを壊しうる」という一般則に従い明示する。
  - **B5 の非正規行検出は残す** (DB 破損・制約無効化に対する defense-in-depth)。

## [Warning] B5: 非正規行検出のクエリと加算が `handle()` のコード例に無い

- 判断: **対応する (指摘が正しい。本文とコード例が食い違っていた)**
- 対応内容: `handle()` のコード例に **due 走査より前**の XOR 件数取得を追加した。
  0 件でなければ**件数だけを `report()`** し `$unexpected += $invalidStateCount` する
  (個々の user をログへ出さない契約は維持)。

## [Suggestion] `deletion_purge_after >= deletion_requested_at` も DB 制約に

- 判断: **対応する** (上の B1/B2 対応に統合済み)

## [Suggestion] C1b の表で `TicketLedgerEntry` が 2 行に分かれている

- 判断: **対応する**
- 対応内容: 起算点表と補助時計表を **1 つの表へ統合**し、
  `case / 起算点 / 補助時計 / failClosed 条件 / 方式` を 1 行で読めるようにした。

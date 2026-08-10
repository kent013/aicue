# 対応マトリクス: design-review Round 4 (全体判定 APPROVED)

Round 4 で全施策 APPROVE / 全体判定 **APPROVED**。Critical / Warning は 0 件。
残る [Suggestion] 3 件はすべて採用した。

## [Suggestion] M29 が本当に赤化するか (Portal spec の前提 pin)

- 判断: **対応する (無条件で前提 pin を置く)**
- 根拠: 指摘が正確である。`billing:ensure-portal-configuration --verify` が保証するのは
  「Stripe 側設定と `PortalConfigurationSpec` の**一致**」だけなので、
  **spec 自体を `subscription_update = true` に書き換えると正しい設定として受け入れられうる** =
  M29 が赤化しない可能性がある。その場合「凍結中に Portal を通してよい」根拠が
  無言で失われる。
- 対応内容: `AccountDeletionFreezeRouteGateTest` に **検査 8 (前提 pin)** を追加した:
  `subscription_update.enabled === false` / `subscription_cancel.enabled === true` /
  `subscription_cancel.mode === 'at_period_end'`。
  これは新しい検証機構ではなく、**免除・allowlist の前提を behavioral に固定する**
  本リポジトリの既存作法 (`ThrottleExemptionPremiseTest` /
  `IdempotencyExemptionPremiseTest` が先例)。
  条件付きにせず**無条件で置く** (「赤化したら足す」だと実測を忘れたときに穴が残るため)。
  mutation 表の M29 の期待赤化先をこの前提検査に更新し、
  **どのテストが赤くなったかを実装ノートに記録する**ことを明記した。

## [Suggestion] B5 の defense-in-depth に時刻順序違反も含める

- 判断: **対応する**
- 対応内容: XOR クエリに **`->orWhereColumn('deletion_purge_after', '<', 'deletion_requested_at')`**
  を追加し、CHECK 制約 2 本と対称にした。制約が無効化されたときに
  「期限が予約時刻より前 = 早期削除候補に入る」異常も検知できる。

## [Suggestion] C1b の表末尾に `TicketLedgerEntry` の重複行がある

- 判断: **対応する**
- 対応内容: 5 列構造になっていない重複行を削除した。直前の 5 列の行だけで契約は表現できている。

---

## 最終確認 (app-design Phase 2-5)

### 全施策が使命 (AGENTS.md) に寄与するか

- **PR-B (猶予つき削除)** が本丸。現場作業者の誤操作で「組織の動画マニュアル資産への
  唯一の到達手段 (Owner)」が 1 クリックで永久に失われる経路を塞ぐ。
  使命「専門知識ゼロの現場作業者でも使える」に直接寄与する。
- **PR-A (依存閉包 gate / redaction 記録)** と **PR-C (保持期間)** は、
  課金機能を持つアプリとして守るべき外部契約 (決済事業者仕様・法定保存期間) の機械化であり、
  使命の前提となる「壊れない基盤」に寄与する。
- どの施策も「あったら便利」ではなく、標準形 v1 (裁定 AG-128) が**必須**とした 3 点である。

### 禁止事項に違反していないか

| # | 禁止事項 | 適合 |
|---|---|---|
| 1 | テストなしの実装完了報告 | 全施策にテスト計画。不変条件は Architecture/Feature テストへの登録まで含む。**mutation 実測 (M1-M29) を実装完了条件にした** |
| 2 | PHPStan の widen / baseline | 各施策に PHPStan 適合チェック欄。`Assert` による narrowing を明示 |
| 3 | dev DB への破壊操作 | migration の precondition 検査は**非破壊 (SELECT のみ)**。`--apply` 系は既定 dry-run |
| 4 | `response()->json()` 直書き | Inertia props + `back()->with()`。DTO (`AccountDeletionStateDto` / `BillingRetentionPurgeResultDto`) を返す |
| 5 | Prism 直呼び | 該当なし |
| 6 | prompt 文字列の直書き | 該当なし |
| 7 | 操作系 POST での `redirect()->intended()` | `back()->with('success', …)` で完結 |
| 8 | 必須条件未充足での disabled | ブロッカーがあってもボタンを disabled にしない。押下時にサーバがエラーを返す。component テストで固定 |
| 9 | Artifact の使用 | 成果物は `devnotes/` 配下のファイルのみ |

### コーディングルールが設計に反映されているか

- PHPStan level 10 / Pest / `RefreshDatabase` グローバル (個別 `DatabaseTransactions` を使わない) /
  **テストデータは Factory** (`UserFactory::pendingDeletion()` を新設) /
  DTO + JsonResource / アーリーリターン / `declare(strict_types=1)` + 日本語コメント /
  Controller は薄く Service 委譲・transaction は Service 内 /
  月・年の加減算は `*NoOverflow` 明示 (**日は対象外**であることを gate の定数実読で確認) /
  Svelte 5 runes + DS token のみ・Lucide のみ・component 階層の単方向 import。

### 残る前提 (実装時に必ず実読で確認すること)

1. `MassAssignmentProtectedKeys` へ `deletion_requested_at` / `deletion_purge_after` /
   `stripe_customer_redacted_at` / `stripe_customer_redacted_id` の登録が要るか。
2. `JobDedupExemption` の既存 case に合うものがあるか (無ければ根拠つきで新設)。
3. `TicketLedgerKind` の TS 同期テストの有無と、新 case `CarryForward` の表示分岐。
4. `ModelDirectFetchInvariantTest` の母集団定義 (執行バッチの `whereNotNull` 走査が
   主キー同一性クエリに当たらないこと)。
5. `LoginMethodRemovalRouteTest` の母集団に新 route 2 本が入らないこと。

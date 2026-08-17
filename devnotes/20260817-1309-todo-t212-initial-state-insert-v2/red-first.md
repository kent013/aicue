# 赤の実測 (テストファースト)

実装順は詳細設計の「テストファースト計画」どおり。台帳 (`NullableStateColumnRegistry::entries()`) を
**空**にしたまま検査だけを置き、`composer test -- tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php`
を走らせて赤を実測した (worktree: `.claude/worktrees/tasks/T212`、branch `todo/T212`)。

## 赤 1-a: 走査した具象モデルの一覧 (NI-3)

`NULL_INITIAL_STATE_MODEL_CLASSES` を空のまま実行したときの実測。**42 件**が出た
(`app/Models` 配下の PHP は 43 ファイルで、うち 1 つは trait = `Models/Concerns/AppliesCriticalActionContextToAudit`)。

```
tests: 1, passed: 0, failed: 1, assertions: 5
NI-3: 走査した具象 Eloquent モデルの一覧が変わりました。
  App\Models\AdminUser / AnalysisJob / ApiKey / Billing\BillingCheckoutSession /
  Billing\BillingNotification / Billing\OrganizationQuota / Billing\Plan / Billing\PlanPrice /
  Billing\StripeWebhookEvent / Billing\Subscription / Billing\TicketAutoRecharge /
  Billing\TicketAutoRechargeAttempt / Billing\TicketCheckoutSession / Billing\TicketLedgerEntry /
  Billing\TicketReservation / Billing\TicketVolumePrice / Category / CustomTeam / Cut /
  EmailSuppression / IdempotencyKey / Inquiry / Item / LlmCallLog / McpIdempotencyKey /
  ModelAudit / OauthSession / Organization / OrganizationInvitation / Passkey / Permission /
  Project / RenderJob / Role / SecurityAuditEvent / SocialAccount / SourceDocument / Take /
  TakeUploadReservation / Team / User / VideoManual  (計 42)
```

**この時点で (b) の系統が読めていることが確かめられている** —
モデルをコンストラクタ経由でインスタンス化しているため `casts()` の畳み込みが効いており、
`newInstanceWithoutConstructor()` を使っていたら 0 件になっていた経路である。

## 赤 1-b: 母集団の実測 = 未分類 59 件 (NI-1)

モデル一覧を pin してから再実行した。**台帳が空なので母集団の全件が「未分類」として出る**。
実測は **59 件**で、詳細設計が予測した「(a) 時刻型 50 + (b) 列挙 cast 9 = 59」と一致した。

```
tests: 15, passed: 10, failed: 5, assertions: 69
NI-1 (未分類 59 件):
  analysis_jobs.step
  api_keys.expires_at
  api_keys.last_used_at
  api_keys.revoked_at
  billing_checkout_sessions.completed_at
  billing_checkout_sessions.pm_reuse_dispatched_at
  billing_notifications.failed_at
  billing_notifications.sent_at
  cuts.material_type
  inquiries.closed_at
  inquiries.source
  inquiries.terms_accepted_at
  notifications.read_at
  oauth_access_tokens.expires_at
  oauth_auth_codes.expires_at
  oauth_device_codes.expires_at
  oauth_device_codes.last_polled_at
  oauth_device_codes.user_approved_at
  oauth_refresh_tokens.expires_at
  oauth_sessions.last_used_at
  oauth_sessions.revoked_at
  organization_invitations.accepted_at
  organization_invitations.revoked_at
  organizations.deleted_at
  organizations.free_plan_activated_at
  organizations.personal_declared_at
  organizations.signup_tickets_granted_at
  organizations.stripe_customer_redacted_at
  organizations.trial_ends_at
  passkeys.last_used_at
  plan_prices.active_to
  plan_prices.synced_at
  render_jobs.error_code
  render_jobs.step
  stripe_webhook_events.processed_at
  stripe_webhook_events.recovery_reason
  subscriptions.current_period_end
  subscriptions.ends_at
  subscriptions.past_due_since
  subscriptions.trial_ends_at
  takes.captured_at
  takes.downloaded_at
  ticket_auto_recharge_attempts.resolved_at
  ticket_auto_recharges.consented_at
  ticket_auto_recharges.disabled_reason
  ticket_checkout_sessions.completed_at
  ticket_ledger_entries.carried_forward_through
  ticket_ledger_entries.expires_at
  ticket_ledger_entries.granted_at
  ticket_ledger_entries.source
  ticket_reservations.consume_expires_at
  ticket_reservations.consume_source
  ticket_volume_prices.active_to
  ticket_volume_prices.synced_at
  users.deletion_purge_after
  users.deletion_requested_at
  users.email_verified_at
  users.terms_accepted_at
  users.two_factor_confirmed_at
```

**この一覧がそのまま台帳の入力になった** = 母集団が実スキーマ起点であることの証跡である
(正典 i5。コード側の申告を母集団にしていない)。

同じ実行で以下も赤だった (いずれも pin の初期値が空のため):

- NI-3: モデルから得た表名の一覧 (実測 42 表)
- NI-4: 台帳の総件数 (実測 0 件 / 期待 59 件)
- NI-5: 「初期状態の目印」の列一覧
- NI-7: 母集団から外した作成・更新時刻の列一覧 (モデル由来の実測 77 列)

## 赤 2 / 赤 3 / 赤 4 (負のコントロール) は同じ実行で緑

NC-1..NC-8 は合成入力で判定の純関数を直接叩くため、台帳が空でも動く。上の実行で 10 件が
passed になっており、その中に次が含まれる。

- **NC-6 (空振り検知)**: 母集団が 0 件になる合成入力で `population` / `temporal` / `enumCast` が
  すべて空になること = NI-3 の 3 つの非空条件がいずれも満たされないことを確認した
- **NC-3 (AG-191 の pin の本体)**: 登録済みの列に DB 既定値が付いた合成スキーマで、その列が
  母集団から抜け「実在しない登録」が点灯することを、既定値の表現ゆれ 6 種
  (`now()` / `CURRENT_TIMESTAMP` / `'pending'` / `'pending'::character varying` / `0` / 空文字)
  すべてで確認した
- **NC-4 (除外の限定)**: `usesTimestamps()` が false のモデル / 作成時刻の列名を差し替えたモデルで
  `created_at` という名の列が母集団に**残る**ことを確認した

## 緑化のときに初期案から動かした区分 (実読による確定)

詳細設計の区分表は「初期案」であり、実装では生成点を実読して確定する決まりだった。
実読の結果、次の 6 列が初期案から移った (いずれも「行が生まれた時点で必ず NULL か」の 1 問に
実装が答えた結果である)。

| 列 | 初期案 | 確定 | 実読した根拠 |
|---|---|---|---|
| `plan_prices.active_to` | 生成時に決まりうる値 | **初期状態の目印** | `Services/Billing/PlanPriceService::replaceCurrent()` は新しい価格行を `'active_to' => null` で作り、旧行にだけ終了時刻を打つ |
| `plan_prices.synced_at` | 生成時に決まりうる値 | **初期状態の目印** | 作成経路 (`replaceCurrent` / `PlanSeeder`) は同期時刻を書かず、`billing:sync-stripe-prices` が既存行へ打つ |
| `ticket_volume_prices.active_to` | 生成時に決まりうる値 | **初期状態の目印** | `TicketVolumePriceSeeder` が `'active_to' => null` で作る (現行世代の目印) |
| `ticket_volume_prices.synced_at` | 生成時に決まりうる値 | **初期状態の目印** | 同上 (`'synced_at' => null` で作る) |
| `users.email_verified_at` | 初期状態の目印 | **生成時に決まりうる値** | `Services/Auth/SocialAccountService` は身元提供者が検証した連絡先のとき、利用者の行を作りながら `'email_verified_at' => now()` を書く = 生成時に必ず NULL ではない |
| `ticket_ledger_entries.carried_forward_through` | 初期状態の目印 | **生成時に決まりうる値** | `Services/Billing/TicketLedgerCarryForwardService` は繰越行の INSERT で集約の終端を書き込む |

`users.deletion_requested_at` / `users.deletion_purge_after` は詳細設計が「もっとも移る可能性が高い」と
書いていた 2 列だが、実読 (`Services/Organization/OrganizationMembershipService` の退会予約経路) の結果、
**どちらも既存行への打刻**であり初期案どおり「初期状態の目印」で確定した。

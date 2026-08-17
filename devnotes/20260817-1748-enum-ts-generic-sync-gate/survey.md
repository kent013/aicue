# 実測: 本リポジトリの PHP 列挙 ⇔ TS 値集合の対応関係 (2026-08-17)

数え方と再現手順は `survey.py` (設計時の使い捨て。`scripts/` へは昇格しない)、
生の出力は `survey-raw.txt`。

- PHP の文字列付き列挙: **112 本** (`app/**/*.php` の `enum X: string`)
- TS 側で「値だけの集合」として読める宣言: **48 件**
  (`export type X = "a" | "b";` と `const X = [...] as const` を正規表現で拾ったもの。
  別名参照を含む宣言は拾えていないので、これは**下限**である)

## 現在検査されている 14 組

| # | PHP 列挙 | TS ファイル | TS 宣言 | 現在の検査 |
|---|---|---|---|---|
| 1 | `Manual\VideoManualStatus` | `types/manual.ts` | `VideoManualStatus` | ManualEnumTsSyncInvariantTest |
| 2 | `Manual\ManualProgress` | `types/manual.ts` | `ManualProgress` | 同 |
| 3 | `Manual\RenderKind` | `types/manual.ts` | `RenderKind` | 同 |
| 4 | `Manual\RenderStep` | `types/manual.ts` | `RenderStep` | 同 |
| 5 | `Manual\RenderErrorCode` | `types/manual.ts` | `RenderErrorCode` | 同 |
| 6 | `Manual\RenderConflictType` | `types/manual.ts` | `RenderConflictType` | 同 |
| 7 | `Manual\ScenarioVerdict` | `types/manual.ts` | `ScenarioVerdict` | 同 |
| 8 | `Manual\ScenarioRuleCode` | `types/manual.ts` | `ScenarioRuleCode` | 同 |
| 9 | `Manual\JobStatus` | `types/manual.ts` | `AnalysisJobStatus` | 同 |
| 10 | `Manual\MaterialType` | `types/manual.ts` | `CutMaterialType` | 同 |
| 11 | `Manual\MaterialType` | `types/capture.ts` | `MaterialType` | 同 (写しが 2 ファイルにある) |
| 12 | `Notification\NotificationType` | `types/notification.ts` | `NotificationType` | NotificationTypeTsSyncInvariantTest |
| 13 | `Billing\OnboardingBillingState` | `types/billing.ts` | `BillingStateValue` | OnboardingBillingStateTsSyncInvariantTest |
| 14 | `AccountDeletionBlockerAction` | `types/account.ts` | `AccountDeletionBlockerAction` | AccountDeletionBlockerActionTsSyncInvariantTest |

## 未検査だが値集合が完全一致している 13 組 (施策 D で登録する)

| # | PHP 列挙 | TS ファイル | TS 宣言 | 備考 |
|---|---|---|---|---|
| 15 | `PlanCode` | `types/Auth.ts` | `PlanCode` | 課金プランの符号 |
| 16 | `AdminConsoleRole` | `types/admin.ts` | `ConsoleRole` | 管理画面の役割 |
| 17 | `MemberRoleState` | `types/admin.ts` | `MemberRoleState` | **正規表現では読めない** (`ConsoleRole \| "owner" \| "unassigned"` の別名参照)。型情報でのみ一致が取れる |
| 18 | `OrganizationRole` | `lib/shared-props.ts` | `OrganizationRoleValue` | 共有 props の役割 |
| 19 | `Billing\BillingFeedbackKind` | `types/billing.ts` | `BillingFeedbackKind` | 課金画面の通知種別 |
| 20 | `Billing\PurchaseFormState` | `types/billing.ts` | `PurchaseFormStateValue` | 購入フォームの状態 |
| 21 | `Manual\TakeStatus` | `types/capture.ts` | `TakeStatus` | 撮影テイクの状態 |
| 22 | `Dashboard\DashboardState` | `types/dashboard.ts` | `DashboardState` | ダッシュボードの状態 |
| 23 | `Dashboard\DashboardRole` | `types/dashboard.ts` | `DashboardRole` | ダッシュボードの役割 |
| 24 | `Manual\AnalysisStep` | `types/manual.ts` | `AnalysisStep` | 解析の段階 |
| 25 | `Manual\AnalysisConflictType` | `types/manual.ts` | `AnalysisConflictType` | 解析の衝突種別 |
| 26 | `Manual\ScenarioConflictType` | `types/manual.ts` | `ScenarioConflictType` | 台本の衝突種別 |
| 27 | `Manual\ManualSortOption` | `types/manual.ts` | `ManualSortOption` | 一覧の並び順 |

## 登録しないもの (理由つき)

| TS 宣言 | 理由 |
|---|---|
| `types/manual.ts::SelectableTakeStatus` | 「選択できるテイクの状態」という**部分集合の意図**を持つ宣言。今は `TakeStatus` と全一致だが、完全一致で縛ると意図と食い違う。逆走査 (AG-099 後半) の候補として残す |
| `types/dashboard.ts::DashboardJobStatus` | `JobStatus` の真部分集合 (進行中のみ)。注釈でそう書かれている |
| `types/capture.ts::CaptureProgress` | 対応する PHP 列挙が無い (画面側だけの語彙) |
| `components/atoms/*.types.ts` の見た目の語彙 (`ButtonVariant` / `BadgeTone` / `ModalSize` 等) | デザインシステムの語彙でサーバー側に対応が無い |
| `lib/stores/toast.ts::ToastType` / `lib/stores/flash-to-toast.ts::FLASH_KEYS` | 値集合は `AlertType` と同じだが、対応する PHP 列挙が無い |
| `lib/capture/*` / `lib/debug/*` の状態語彙 | 画面側の内部状態。サーバーへ出ない |

## 逆走査 (AG-099 後半) の見積り

施策 D の 13 組を登録した後、「値集合が完全一致するのに未登録」で残るのは
**`SelectableTakeStatus` の 1 件だけ**である。つまり後半の規則 1 を入れるときの
免除登録は 1 件で足りる見込みで、後続 TODO の規模は小さい。

**ただしこれは網羅の証拠ではない**。本実測は正規表現で読める宣言だけを数えており、
別名参照を含む宣言 (#17 の `MemberRoleState` がまさにそれ) は数えられていない。
48 件という母数は**下限**である。ここに書いた「残り 1 件」は後続 TODO の**見積りの仮説**で、
実際の残りは AG-099 後半の全数走査を入れて初めて確かめられる。

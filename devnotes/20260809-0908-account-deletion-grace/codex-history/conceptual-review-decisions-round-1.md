# 対応マトリクス: conceptual-review Round 1

## [Critical] 保持期間 purge の対象が未定義 (対象テーブル / 基準列 / 削除方式)

- 判断: **対応する**
- 根拠: 指摘のとおり。実コードを読み直したところ、**曖昧なまま進めると実害が出る**ことが確認できた。
  `TicketLedgerService::sumBalance()` (L680-701) と `balance()` (L317) は
  **`ticket_ledger_entries.delta` の SUM を残高の唯一の真実源にしている**。古い台帳行を purge すると
  **残高がその場でリセットされる**。これは「保持期限が来たら消す」を素朴に書くと必ず踏む地雷である。
- 対応内容: 概念設計に **§4-3b「保持 purge 対象の目録 (deny-by-default)」** を新設した。
  - 母集団 = `app/Models/Billing/` の全モデル + `organizations` の課金列。**exact-fit**。
  - 各対象は `BillingRetentionTarget` enum の case として (モデル / 基準列 / 対象条件 / 方式 / 30 字以上の根拠) を持つ。
  - **除外も目録に載せる** (`BillingRetentionExclusion`)。`ticket_ledger_entries` は
    「残高の SoT のため削除対象にしない」を根拠つきで登録する。分類漏れは fail。
  - dry-run 出力形式 (対象種別ごとの件数のみ・PII 非出力) を設計に固定した。

## [Warning] 即時削除を「一切変更しない」ことが改善の主目的と衝突する

- 判断: **一部対応する / 一部反論する**
- 根拠と対応:
  - **対応**: UI の**主導線は予約**であること、即時削除は明示文言つきの**副導線**であることを
    設計条件として明記した (§4-1)。
  - **反論**: 「`recent-auth` より強い確認 / 二段確認」は採らない。`recent-auth` (step-up 再認証) は
    本リポジトリの機微操作の標準関門で、退会 route は `RecentAuthRouteTest` の allowlist に既に載っている。
    ここだけ独自の強い確認を足すのは思考原則 2 (今必要なものだけ作る) に反する。
    また「二段確認で押しにくくする」は禁止事項 8 (必須条件未充足でボタンを disabled にしない = 
    押下時にエラーで説明する) の思想と逆行する。
  - **SecurityEvent 記録**は既に `SecurityEventType::AccountDeleted` が `deleteAccount()` 内で
    記録済み (実コード L842)。新規要件ではないので設計に事実として明記した。

## [Warning] `deleteAccount()` に HTTP 前提があると Console から呼べない

- 判断: **反論する (ただし設計に事実を明記)**
- 根拠: 実コードを読んだ結果、**既に分離済み**である。
  `OrganizationMembershipService::deleteAccount(User $user, ?\Closure $beforeDelete = null): void` は
  Request / Session / redirect に一切依存しない。HTTP 固有の副作用 (`Auth::logout()`) は
  **Controller が closure として注入している** (`AccountController::destroy` L30)。
  session invalidate / regenerate / redirect も Controller 側に閉じている。
- 対応内容: バッチは `$beforeDelete = null` で呼べばよいことを設計に明記した。層の混在は起きない。

## [Warning] `organizations.*` を丸ごと通すのは粗い

- 判断: **対応する (指摘より強い形へ)**
- 根拠: 指摘は正しい。`organizations.create` / `organizations.store` (新組織作成) と
  `organizations.invitations.store` (招待送信) は「退会ブロッカーを解消する」操作ではなく
  **執行時のブロッカーを増やす**操作である (新しい唯一 Owner 組織・新しい孤児メンバー予備軍)。
- 対応内容: 凍結の配線を反転させた (§4-2 を全面改訂)。
  - 当初案「業務 group (`require-active-subscription`) だけを止める」→
    **新案「`auth` + `verified` group 全体に凍結 middleware を付け、通す route 名を exact-fit の
    allowlist で持つ」= deny-by-default**。
  - 帰結として **新しい route は既定で凍結中に止まる** (fail-secure)。allowlist へ載せるには
    型付き enum (`AccountDeletionFreezeAllowance`) + 30 字以上の根拠が要る。
  - 家系の先例 (spirux の `AccountDeletionFreezeRouteGateTest`) と同じ形になり、還流もしやすい。
  - `organizations.store` / `organizations.invitations.store` は **allowlist に載せない** (= 凍結中は不可)。
    移譲・メンバー削除・ロール変更は載せる (ブロッカー解消に必要)。

## [Warning] 依存閉包 gate の到達検出が弱い (container / trait / facade / 動的)

- 判断: **対応する**
- 根拠: 妥当。laravel-claude-template では実際に「型宣言だけの注入を素通りさせていた」fail-open が
  実装レビューで見つかっている (台帳 handover の逐語)。
- 対応内容: 走査は自前で書かず **`Tests\Support\PhpReferenceScanner`** (既存。`ExternalSeamInventoryTest` /
  `ExternalClientTimeoutInventoryTest` が共有する namespace 解決 / alias / scope 追跡の基盤) に乗せることを
  設計に明記した。正負 fixture に「型注入だけ」「facade 経由」「trait 経由」「`app()`/`resolve()` literal」
  「動的メソッド名」を含める。**保証しないもの** (文字列キーの container 解決 / vendor 内部からの通信 /
  完全修飾 docblock のみ) も冒頭 docblock に明記する。

## [Warning] 「予約実行時のガード再評価が構造的に保証される」は言い過ぎ

- 判断: **対応する**
- 根拠: 表現として強すぎるのは事実。保証しているのは「判定コードが 1 本しかない」ことであって、
  「あらゆる競合で正しい」ことではない。
- 対応内容: 「同一 Service の同一メソッドが Controller と Command の両方から呼ばれる (判定が分岐しない)」
  に言い換えた。加えて Feature テスト
  **「予約時は通ったが執行時に blocker が立った場合は削除されない」** を必須テストに追加した。

## [Warning] 執行失敗時にユーザーが気づかず永久凍結に近くなる

- 判断: **対応する (ただし列は足さない)**
- 根拠: 可視化が要るのは同意。ただし `last_deletion_blocked_reason` 列は**持たない** —
  ブロッカーは `organizationsBlockingDeletion()` が**毎リクエスト再評価する導出値**であり、
  DB に写しを持つと 2 つの真実源ができて drift する (思考原則 4 / 既存 T115 の設計思想そのもの)。
- 対応内容: `/settings` の予約バナーに (a) 既存 `accountDeletionBlockers` props による
  「執行できない理由と次の一手」、(b) 「毎日 1 回自動で再試行する」旨、(c) 取消ボタンを出す。
  バッチ側は `report()` で運用にも上げる。

## [Warning] メール通知をスコープ外にしたのは乗っ取りに対して弱い

- 判断: **対応する (スコープ内へ引き上げる)**
- 根拠: 指摘が正しい。**機能の名前に立ち返る**と、この機能は「誤操作救済」であり、
  救済は**本人が気づくこと**が成立条件である。画面内通知は「予約した本人が画面を見る」前提で、
  乗っ取り起点の予約 (= 本人がログインしていない) では成立しない。30 日の窓を作っても
  気づく手段が無ければ窓は無いのと同じ。コストは Notification 1 本で小さい。
- 対応内容: PR-B に **退会予約メール通知 (`AccountDeletionRequestedNotification`)** を追加した。
  `ShouldQueue` + **予約 tx 内 dispatch** (AGENTS.md ドメイン規約 11。
  spirux の申し送り「afterCommit で外へ出せ」は aicue の規約と逆なので**採らない**)。
  `JobExecutionDedupInventoryTest` への登録も施策に含める。

## [Warning] PR-A の redaction 記録列が孤立して使われない

- 判断: **対応する**
- 根拠: 妥当。使い方が書かれていない列は死蔵する。
- 対応内容: runbook に (a) 対象組織の解決手順 (既存 `billing:detect-orphan-billing-organizations` の
  出力 id を起点にする)、(b) 二重実行時の表示 (既記録なら「YYYY-MM-DD に記録済み」で no-op)、
  (c) Stripe ダッシュボード側の操作手順と一次情報 URL・確認日、を含めることを PR-A の完了条件にした。

## [Warning] Inertia props / 型が概要どまり

- 判断: **対応する**
- 対応内容: `App\DataTransferObjects\Account\AccountDeletionStateDto`
  (`requestedAt` / `purgeAfter` / `graceDays` の nullable datetime を ISO8601 文字列で固定) を新設し、
  `resources/js/types/account.ts` に対応する型を足す。既存
  `AccountDeletionBlockerActionTsSyncInvariantTest` と同型の TS 同期テストの要否も詳細設計で判断する。

## [Warning] `billing:mark-stripe-customer-redacted {organization}` の organization 解決

- 判断: **対応する**
- 対応内容: console 引数由来 id は**クラス起点の主キー同一性クエリ**になるため、
  `ModelDirectFetchInvariantTest` + `DirectFetchInventory` への登録を PR-A の施策に明記した
  (AGENTS.md セキュリティ不変条件 3)。

## [Suggestion] 3 PR 直列 / 単一出典化 / North Star 整合

- 判断: **見送る (追加対応なし)**
- 根拠: 肯定的評価のため設計変更不要。

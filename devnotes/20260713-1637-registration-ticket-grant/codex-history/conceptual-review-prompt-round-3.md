# 概念設計レビュー Round 3（Round 2 指摘への対応）

Round 2 の指摘に対応しました。**全体判定の再評価**をお願いします。

## [Critical] exists()+insertOrIgnore は異なる冪等キー間で原子排他がない
→ **対応（アプリ層存在ガードを廃止し DB 制約で原子保証）**。
`ticket_ledger_entries` に**部分 UNIQUE index** を追加:
`CREATE UNIQUE INDEX ... ON ticket_ledger_entries (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'`。
- 「1 組織あたり `signup_grant:%` 行は高々 1」を **DB レベルで原子的に強制**。旧キー
  （`signup_grant:{subId}`）行・新 org キー（`signup_grant:org:{id}`）行を**同一述語でカバー**するため、
  ローリングデプロイで旧・新経路が別キーで同時 insert しても二重付与しない。
- `insertOrIgnore`（pgsql `ON CONFLICT DO NOTHING`、ターゲット無し）は部分 index 違反も握り潰す。
- アプリ層 `exists()` 存在ガードは**削除**（非原子な部分が消え、Round 1 の「prefix brittle」も
  単一の明示的 DB 制約へ集約）。テスト DB は pgsql（`.env.testing`/`phpunit.xml`）で部分 index 利用可。

## [Warning] 「1 組織 1 signup grant」を不変条件テストへ登録
→ **対応**。部分 index の振る舞いを検証する Feature テストを追加（同一組織へ `signup_grant:%` の
**異なる** idempotency_key を 2 回 insert しても 1 行/残高 10）。加えて `grantSignupGrant` から外部注入
キー引数を撤廃したこと自体が「外部生成キーによる回避」を構造的に封じる旨を明記。

## [Warning] 捨てアカウント評価が強すぎ
→ **対応**。「金銭価値は流出しない」→「未認証は消費不可（一次防御）。認証済み捨てアカウントは消費可能
だが、得るのは 30 日失効・1 組織 1 回・10 枚のみで実質的悪用価値は小さく**残余リスクとして受容**（既存
防御: メール認証必須・失効・組織単位 1 回 で緩和）」へ緩和。二段階予約化は過剰につき不採用を維持。

## [Warning] LP 表記の正確化
→ **対応（挙動整合の正確化）**。付与が「契約時」→「登録時」へ移るため、Pricing の現行「新規**契約**で
チケット N 枚」は事実と食い違う。よって Pricing（signup-grant-note / FAQ）を「新規**登録**で」へ修正
（Welcome は既に「新規登録で」で正確・据え置き）。波及: `tests/js/pages/Pricing.test.ts` の assertion 更新。
- 「新規ワークスペース作成で」は採用しない: **追加ワークスペース作成では付与しない**（付与は初回
  サインアップで作られる自分の個人組織単位）ため逆に過大表現になる。
- 招待経由登録は「登録時に付与済みの所属組織の残高を共有」するエッジで、LP CTA 経由の導線ではない旨を
  制約・前提に注記。

## 変更後の設計（差分の要点。全文は前ラウンドから上記 4 点を反映）

実装方針（更新後）:
1. マイグレーション: `ticket_ledger_entries` に部分 UNIQUE index を追加。
2. `TicketLedgerService::grantSignupGrant(Organization $org): void` へ改修（外部キー引数撤廃・
   org スコープキー・アプリ層存在ガードなし）。
3. `CreateNewUser::create()` の個人組織生成直後に `grantSignupGrant($org)`（登録 tx 内・純粋 insert）。
4. `StripeWebhookProcessor` を新シグネチャへ更新（`resolveInvoiceSubscriptionId` の signup 用途は除去）。
5. `Pricing.svelte` 文言「新規契約で」→「新規登録で」。
6. テスト: 登録後残高 10 の Feature テスト / 部分 index 不変条件テスト / 既存冪等テスト更新 /
   webhook テスト更新 / `Pricing.test.ts` 文言更新。

backfill はスコープ外（Round 1 で分離済）。DTO/Props/TS 型変更なし（表示は既存 `signupGrantTickets`）。

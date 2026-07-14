# 概念設計: registration-ticket-grant

## 背景・課題

bug-hunt finding **F-H1 (High)**: home / pricing の LP が「新規登録でチケット **10 枚**が無料
（30 日間有効）」と明記している（`resources/js/pages/Welcome.svelte` L349 / `Pricing.svelte`
L54,168、いずれも `page.signupGrantTickets` を表示）にもかかわらず、**新規登録 + メール認証を
完了してもチケット残高が 0 のまま**。広告表記と実挙動が齟齬している。

### 原因（コード調査で確定）

初回 signup grant（無償 10 枚）を付与する経路が **Stripe webhook の
`invoice.paid`（`billing_reason=subscription_create`）1 箇所しか存在しない**
（`app/Services/Billing/StripeWebhookProcessor.php` L266-274 →
`TicketLedgerService::grantSignupGrant($org, "signup_grant:{$subscriptionId}")`）。

- つまり **有料サブスクリプションを契約して初回請求が確定したときにしか付与されない**。
- LP が訴求する「新規登録（= Free プランで開始）」の導線には付与フックが一切なく、
  Free ユーザーは永久に 0 枚。
- 表示側（`signupGrantTickets` を返す `TicketPricingService` / DTO / Svelte）は
  **既に実装済み**で「10 枚もらえる」と描画するため、表記と実挙動の乖離が生じている。

### 正本の確認（表記 vs 実挙動、どちらが正か）

- `config/billing.php` に `signup_grant_tickets`（既定 10）/ `signup_grant_expiry_days`
  （既定 30）が定義され、`TicketLedgerService::grantSignupGrant` / `TicketPricingService`
  も揃っている。**「新規登録で無償チケットを付与する」ことは設計意図（正本）**であり、
  LP 表記が正・実挙動（付与欠落）がバグ。**無料枠を付与する方向で修正**する
  （brief の指示と一致）。

## 改善アイデア

**新規登録の完了時（個人組織のプロビジョニング時）に、初回 signup grant（無償 10 枚 / 30 日）を
冪等付与する。** 付与プリミティブは既存の `TicketLedgerService::grantSignupGrant` を再利用し、
Stripe 経路との**二重付与を構造的に防ぐ**ため、冪等キーを **subscription スコープから
organization スコープへ統一**する。

### 付与フックの選定

`app/Actions/Fortify/CreateNewUser.php::create()` の登録トランザクション内、
**個人組織を生成した直後**（招待経由でない = `$joined === null` の分岐）に
`grantSignupGrant($org)` を呼ぶ。

- **招待経由の登録は付与しない**: 招待ユーザーは既存組織（= 組織作成時に既に付与済みの組織）へ
  参加するだけで個人組織を作らない。ここへ付与すると「招待 N 人 = N×10 枚」の増幅
  （アビューズ）になる。`provisionPersonalOrganization` を通る通常登録のみに絞ることで
  **1 組織（= 1 個人組織）につき最大 1 回**という意味論が自然に成立する。
- **付与は「組織単位」で一貫する（LP 文言の整合）**: signup grant は組織の残高に入る。LP
  （公開マーケ）の主対象である自己登録者は必ず個人組織を生成して 10 枚を受け取り、招待ユーザーは
  登録時に既に付与済みの所属組織の残高を共有する。つまり「登録経路で約束が変わる」のではなく、
  付与は一貫して「新規ワークスペース（組織）作成につき 1 回」。よって LP 文言は自己登録者にとって
  正しく、変更しない。
- **同一トランザクション内**で付与し、「登録が確定した ⇒ 残高 10」という不変条件を原子的に保証する
  （Feature テストの検証対象そのもの）。`grantSignupGrant` は `grantMonthly → insertIdempotent`
  の**純粋な ledger insert のみ**で通知・イベント・外部 I/O を含まない（通知は `reserve` にのみ存在）
  ため、登録 tx 内で完結し rollback 整合性を壊さない。

### 冪等性の設計（二重付与の防止 — brief 必須要件）

`grantSignupGrant` を「**1 組織につき signup grant は高々 1 回**」というドメイン不変条件で
冪等化する。この不変条件は**アプリ層の存在チェックではなく DB 制約で原子的に保証**する
（Codex Round 2 Critical: 異なる冪等キー間はアプリ層 `exists()` では並走時に原子排他できない）:

1. 冪等キーを `signup_grant:org:{organizationId}`（organization スコープ）へ統一する。
   登録経路・Stripe 経路の**両方が同一キー**を使うため、`idempotency_key` UNIQUE の
   `insertOrIgnore` で通常経路は no-op になる。
2. `ticket_ledger_entries` に**部分 UNIQUE index** を追加する:
   `UNIQUE (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'`。
   これで「1 組織あたり `signup_grant:%` 行は高々 1」を **DB レベルで原子的に強制**する。旧キー
   （`signup_grant:{subscriptionId}`）行・新 org キー行の**双方を同一述語でカバー**するため、
   ローリングデプロイ中に旧・新経路が別キーで同時 insert しても二重付与しない。`insertOrIgnore`
   （pgsql `ON CONFLICT DO NOTHING`）は部分 index 違反も握り潰す。テスト DB は pgsql
   （`.env.testing` / `phpunit.xml`）で部分 index（LIKE 述語）は利用可能。

これにより「登録 → 後で有料契約」でも「旧経路で付与済みの組織が再契約」でも二重付与しない。
アプリ層の存在ガードは不要（DB 制約が単一の明示的不変条件として集約）。旧規約 prefix
`signup_grant:` は既存コード（`WebhookIdempotencyTest` の `like 'signup_grant:%'`）と一貫する。

### 濫用（捨てアカウント）への多層防御

登録時（メール認証前）に付与するが、**全チケット消費経路は `verified` middleware 配下**
（`routes/web.php` L153）であり、未認証アカウントはチケットを消費できない（一次防御）。使い捨て
メールを認証すれば消費可能ではあるが、そのとき得られるのは **30 日で失効・1 組織 1 回・10 枚のみ**で、
悪用には各捨てメールの認証が必要なため**実質的な悪用価値は小さく、残余リスクとして受容**する
（既存防御: メール認証必須・付与の 30 日失効・組織単位 1 回 で緩和）。「付与予約 → 認証完了で commit」の
二段階化は本 finding に対して過剰であり採用しない（「やたらに複雑な案を提案しない」）。

**定量根拠（受容判断の運用メモ）**: 付与は 30 日失効・1 組織 1 回・10 枚。名目価値上限は単価下限
`billing.ticket_unit_price_floor`(¥50) 換算で約 ¥500/組織だが、実コストは**消費時の解析/レンダ計算のみ**
（AI 解析 1 枚 / 動画レンダ 3 枚）であり、消費には各捨てメールの**認証**が前提。監視は既存 ledger
（grant 行の異常増加）で可観測。登録はフォーム POST（CSRF・セッション認証）で、必要なら登録レート制限の
追加を運用判断とする。

## 期待効果

- **使命への貢献**: 「思考ゼロで、まず触れる」導線の回復。LP が約束した無償 10 枚が実際に
  付与され、専門知識ゼロの現場ユーザーが AI 解析〜動画完成までを課金前に体験できる
  （signup grant のコメントにある「まず触れる」導線の本来の目的）。
- **広告と実挙動の一致**: 誇大表示（景表法観点でもリスク）を解消。
- **信頼回復**: 新規登録直後の残高 0 という「詰み」体験（bug-hunt が検出した UX 破綻）を除去。

## 実装方針（概要）

1. **マイグレーション追加**: `ticket_ledger_entries` に部分 UNIQUE index
   `UNIQUE (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'` を追加
   （「1 組織 1 signup grant」を DB レベルで原子保証）。**先頭で非破壊の重複監査**を行い、同一組織に
   `signup_grant:%` 行が 2 件以上あれば `RuntimeException` で **fail-closed 停止**（台帳行の削除・書換えは
   一切せず、重複補正は別承認手順へ分離）。重複ゼロのときのみ index を作成する。
   デプロイ順序: **重複監査 → index 追加（migration）→ 新コード展開**。index 作成はテーブルロックを
   取るが対象は早期アプリで小規模につき許容（大規模化時は `CREATE INDEX CONCURRENTLY` を検討）。
2. `TicketLedgerService::grantSignupGrant(Organization $org)` を **organization スコープ冪等**へ
   改修（引数 `string $idempotencyKey` を廃止し、内部で org スコープキー `signup_grant:org:{id}` 生成。
   アプリ層存在ガードは持たず、DB 部分 index + `insertOrIgnore` に委ねる）。
3. `CreateNewUser::create()` の個人組織生成直後に `grantSignupGrant($org)` を追加
   （`TicketLedgerService` を DI。登録 tx 内・純粋 insert）。
4. `StripeWebhookProcessor::grantMonthlyTickets()` の呼び出しを新シグネチャへ更新
   （`subscription_create` 時に `grantSignupGrant($org)`。org スコープキーで冪等になるため
   subscription id は signup grant には不要となり、そのための `resolveInvoiceSubscriptionId`
   参照・fail-closed 分岐は signup grant からは除去）。
5. **LP 文言の挙動整合**: `Pricing.svelte` の「新規**契約**で…」を「新規**登録**で…」へ修正
   （付与が「契約時」→「登録時」へ移るため）。Welcome は既に「新規登録で」で正確につき据え置き。
6. テスト:
   - 登録後に残高 10 になる Feature テスト（新規）。
   - **部分 index の Architecture テスト**（pgsql `pg_indexes`/`information_schema` を照会し、
     `ticket_ledger_entries` に `(organization_id) WHERE idempotency_key LIKE 'signup_grant:%'` の
     UNIQUE index が存在することを assert。課金冪等性は Architecture テストで強制する既存規約に準拠）。
   - **部分 index の不変条件 Feature テスト**（同一組織へ異なる idempotency_key の signup grant を
     2 回 insert しても 1 行/残高 10 = 実競合抑止の確認）。
   - `grantSignupGrant` 冪等テストの更新（新シグネチャ / org スコープキー）。
   - Stripe webhook テストの更新。
   - `Pricing.test.ts` の文言 assertion 更新（招待導線が LP CTA の対象外である意図をコメントで明示）。

> **注**: 既存 Free 組織への遡及付与（backfill）は本設計のスコープ外とする（Codex Round 1 の
> Critical 指摘を反映）。forward fix は「今後の登録」を修正する不具合修正であるのに対し、backfill は
> 既存ユーザーへの補償施策であり、対象定義・件数・金額影響・承認が別問題となるため、別タスクとして
> 切り出す（スコープ外参照）。

## 制約・前提

- **課金の冪等性（セキュリティ不変条件 §7）**: チケット付与は `idempotency_key` UNIQUE の
  冪等 insert で二重計上を防ぐ既存規約に完全準拠する。新規のデクリメント経路は作らない。
- **`response()->json()` 直書き禁止 / DTO・JsonResource**: 本修正はサーバ内部の付与ロジックが中心で、
  表示側 DTO（`LandingPageDto` / `PricingPageDto` / `signupGrantTickets`）は**既に実装済み**の
  ため API/Props/TS 型の変更は不要。LP 変更は文言（Svelte テキスト）の挙動整合のみで、Props 型・
  数値バインド（`page.signupGrantTickets`）は不変。
- **テストファースト / Factory 生成 / PHPStan L10**: 付与は Assert で config を検証済み。
  戻り値型・null 安全は既存 `grantSignupGrant` を踏襲。
- **メール認証との関係**: アプリ主要機能は `verified` middleware 配下（`routes/web.php` L153）で、
  未認証ユーザーはチケットを消費できない。よって「登録時付与（認証前）」でも未認証濫用で
  残高が減ることはなく、LP 表記（「新規登録で」）とも一致する。認証ゲートは付与条件にしない。

## 制約・前提（追記）

- **招待経由登録のエッジ**: 招待ユーザーは個人組織を作らず、登録時に既に付与済みの所属組織の残高を
  共有する（付与は組織作成時に 1 回）。所属組織の grant が既に消費/失効していれば招待ユーザーは 10 枚を
  見ないが、これは付与が「初回サインアップで作られる自分のワークスペース単位」である仕様の帰結で、
  招待は LP CTA（`無料で始める`→`/register`）経由の導線ではない。追加ワークスペース作成でも付与しない
  ため「新規ワークスペース作成で」は逆に過大表現になる。よって LP は「新規登録で」を維持する。

## スコープ外

- チケットの消費・予約・返金ロジック（既存の reserve→commit/release / clawback）は変更しない。
- 月次付与（`grantMonthly` / `monthly:{invoiceId}`）・買い切り付与（`grantPurchased`）の
  経路は変更しない。
- LP の文言・金額・FAQ の変更（表記は正しいため触らない）。
- 付与枚数・有効期限のポリシー変更（config 既定 10 / 30 を踏襲）。
- **既存 Free 組織への遡及付与（backfill）**: forward fix とは性質（不具合修正 vs 補償施策）・
  金額影響・承認プロセスが異なるため別タスクへ分離する。実施する場合は「個人組織として作成された」
  「`signup_grant:%` 台帳が未存在」「手動補填・別補償の対象外」等をドメイン条件で厳密に定義し、
  対象件数・金額影響・期限起算日を明文化した上で forward fix とは別承認とする。

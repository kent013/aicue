# 概念設計レビュー Round 2（前回指摘への対応）

前回 (Round 1) の指摘に対する対応を報告します。**全体判定の再評価**をお願いします。

## Critical への対応

### [Critical] backfill 対象定義が曖昧 / [Critical] forward fix と backfill を分離せよ
→ **対応（backfill を本設計スコープから完全に除外）**。本設計は forward fix に限定:
1. `TicketLedgerService::grantSignupGrant(Organization $org)` を org スコープ冪等へ改修
   （引数 `string $idempotencyKey` 廃止、内部で `signup_grant:org:{orgId}` キー生成 ＋
   「当該組織に `signup_grant:%` 台帳が既存なら no-op」の存在ガード）。
2. `CreateNewUser::create()` の個人組織生成直後（招待経由でない分岐）に `grantSignupGrant($org)` を追加。
3. `StripeWebhookProcessor` の呼び出しを新シグネチャへ更新（org スコープキーで冪等統一）。
4. テスト（登録後残高 10 の Feature テスト新規、既存冪等テスト・webhook テスト更新）。

既存 Free 組織への遡及付与（backfill）はスコープ外へ移動し、「別タスクで対象をドメイン条件
（個人組織 / `signup_grant:%` 未存在 / 補償対象外）で厳密定義し、件数・金額影響・期限起算日を
明文化して forward fix とは別承認」と明記しました。

## Warning への対応

### [Warning] 招待経由の表記整合
→ **意味論を明文化（文言変更は見送り）**。signup grant は**組織単位**の付与。LP の主対象である
自己登録者は必ず個人組織を生成し 10 枚を受領、招待ユーザーは登録時に既に付与済みの所属組織の残高を
共有する。つまり「登録経路で約束が変わる」のではなく一貫して「新規ワークスペース（組織）作成につき
1 回」。よって LP 文言は自己登録者にとって正しく変更しない、と設計に追記。

### [Warning] 登録 tx 内は ledger insert のみ / 副作用は afterCommit
→ **対応**。`grantSignupGrant → grantMonthly → insertIdempotent` は DB insert のみで通知・イベント・
外部 I/O を含まない（通知は `reserve` にのみ存在）。登録 tx 内で完結し rollback 整合を壊さない旨を明記。

### [Warning] メール認証前付与の捨てアカウント濫発
→ **対応（多層防御を明文化、二段階予約化は不採用）**。全消費経路は `verified` middleware 配下で
未認証は消費不可。捨てアカウントが得るのは 30 日で失効する非消費 ledger 行のみで金銭価値は流出しない。
二段階化（付与予約→認証で commit）は本 finding に過剰なため採用しない、と明記。

### [Warning] `signup_grant:%` prefix 判定が brittle
→ **対応**。存在ガードは `grantSignupGrant` 内に閉じた「1 組織 1 signup grant」不変条件の表現で、
旧キー互換のための移行ガードとして専用メソッドに限定。prefix `signup_grant:` は既存規約
（`WebhookIdempotencyTest` も同 prefix の LIKE を使用済）で新規導入ではない旨を明記。

## 更新後の設計本文（全文）

---

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
冪等化する:

1. 冪等キーを `signup_grant:org:{organizationId}`（organization スコープ）へ統一する。
   登録経路・Stripe 経路の**両方が同一キー**を使うため、片方が先に付与すれば他方は
   `idempotency_key` UNIQUE の `insertOrIgnore` で no-op になる。
2. さらに付与出所・キー形式の差異（旧 `signup_grant:{subscriptionId}` エントリを持つ既存組織）を
   跨いでも二重付与しないよう、付与前に「当該組織に `signup_grant:%` の台帳エントリが既に
   存在するか」を存在チェックし、あれば no-op とする。

これにより「登録 → 後で有料契約」でも「旧経路で付与済みの組織が再契約」でも二重付与しない。
存在ガードは `grantSignupGrant` 内に閉じた「1 組織 1 signup grant」不変条件の表現であり、旧キー
（`signup_grant:{subscriptionId}`）互換のための移行ガードとして専用メソッド内に限定する
（prefix `signup_grant:` は既存規約で、`WebhookIdempotencyTest` も同 prefix の LIKE を使用済）。

### 濫用（捨てアカウント）への多層防御

登録時（メール認証前）に付与するが、**全チケット消費経路は `verified` middleware 配下**
（`routes/web.php` L153）であり、未認証アカウントはチケットを消費できない。よって捨てアカウントが
得るのは 30 日で失効する**非消費の ledger 行のみ**で金銭価値は流出しない。付与は expiring・非消費で
あるため、「付与予約 → 認証完了で commit」の二段階化は本 finding に対して過剰であり採用しない
（「やたらに複雑な案を提案しない」）。

## 期待効果

- **使命への貢献**: 「思考ゼロで、まず触れる」導線の回復。LP が約束した無償 10 枚が実際に
  付与され、専門知識ゼロの現場ユーザーが AI 解析〜動画完成までを課金前に体験できる
  （signup grant のコメントにある「まず触れる」導線の本来の目的）。
- **広告と実挙動の一致**: 誇大表示（景表法観点でもリスク）を解消。
- **信頼回復**: 新規登録直後の残高 0 という「詰み」体験（bug-hunt が検出した UX 破綻）を除去。

## 実装方針（概要）

1. `TicketLedgerService::grantSignupGrant(Organization $org)` を **organization スコープ冪等**へ
   改修（引数 `string $idempotencyKey` を廃止し、内部で org スコープキー生成＋存在ガード）。
2. `CreateNewUser::create()` の個人組織生成直後に `grantSignupGrant($org)` を追加
   （`TicketLedgerService` を DI）。
3. `StripeWebhookProcessor::grantMonthlyTickets()` の呼び出しを新シグネチャへ更新
   （`subscription_create` 時に `grantSignupGrant($org)`。org スコープキーで冪等になるため
   subscription id は signup grant には不要となり、そのための `resolveInvoiceSubscriptionId`
   参照・fail-closed 分岐は signup grant からは除去）。
4. テスト: 登録後に残高 10 になる Feature テスト（新規）、`grantSignupGrant` 冪等テストの
   更新（新シグネチャ / org スコープキー）、Stripe webhook テストの更新。

> **注**: 既存 Free 組織への遡及付与（backfill）は本設計のスコープ外とする（Codex Round 1 の
> Critical 指摘を反映）。forward fix は「今後の登録」を修正する不具合修正であるのに対し、backfill は
> 既存ユーザーへの補償施策であり、対象定義・件数・金額影響・承認が別問題となるため、別タスクとして
> 切り出す（スコープ外参照）。

## 制約・前提

- **課金の冪等性（セキュリティ不変条件 §7）**: チケット付与は `idempotency_key` UNIQUE の
  冪等 insert で二重計上を防ぐ既存規約に完全準拠する。新規のデクリメント経路は作らない。
- **`response()->json()` 直書き禁止 / DTO・JsonResource**: 本修正はサーバ内部の付与ロジックのみで、
  表示側 DTO（`LandingPageDto` / `PricingPageDto` / `signupGrantTickets`）は**既に実装済み**の
  ため API/Props/TS 型の変更は不要（波及は詳細設計で最終確認）。
- **テストファースト / Factory 生成 / PHPStan L10**: 付与は Assert で config を検証済み。
  戻り値型・null 安全は既存 `grantSignupGrant` を踏襲。
- **メール認証との関係**: アプリ主要機能は `verified` middleware 配下（`routes/web.php` L153）で、
  未認証ユーザーはチケットを消費できない。よって「登録時付与（認証前）」でも未認証濫用で
  残高が減ることはなく、LP 表記（「新規登録で」）とも一致する。認証ゲートは付与条件にしない。

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


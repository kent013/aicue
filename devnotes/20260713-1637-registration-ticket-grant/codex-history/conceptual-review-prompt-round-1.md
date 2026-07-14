# アプリの使命（North Star / AGENTS.md より）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

## セキュリティ不変条件（抜粋）

- **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ。付与は idempotency_key UNIQUE の冪等 insert。
- **tenant キー不信 / cross-org 不可 / 権限判定は laratrust_team_id 明示**。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ（Laravel/Svelte エコシステム）。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。設計の方向性が正しいと確認できてから値を調整せよ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか（特に課金の冪等性・二重付与）
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（対象 finding: bug-hunt F-H1「新規登録でチケット 10 枚無料」の広告と、実際は残高 0 のままという齟齬）

### 現行コードの要点（レビューの前提）

- `TicketLedgerService::grantSignupGrant(Organization $org, string $idempotencyKey)`:
  config `billing.signup_grant_tickets`(既定10) 枚を `signup_grant_expiry_days`(既定30)日期限で
  `grantMonthly` 経由に冪等付与（`idempotency_key` UNIQUE の `insertOrIgnore`）。
- 呼び出しは **`StripeWebhookProcessor::grantMonthlyTickets()` 1 箇所のみ**:
  `invoice.paid` かつ `billing_reason=subscription_create` のとき
  `grantSignupGrant($org, "signup_grant:{$subscriptionId}")`。subscription id が無ければ fail-closed で skip。
- 登録経路 `CreateNewUser::create()` は個人組織を生成するが **signup grant を呼ばない** → Free 登録者は 0 枚。
- 表示側（`TicketPricingService::signupGrantTickets()` → `LandingPageDto`/`PricingPageDto` → Svelte
  `page.signupGrantTickets`）は既に「10 枚もらえる」と描画済み。
- `CreateNewUser` は招待経由なら既存組織へ参加し個人組織生成を skip、通常登録なら
  `provisionPersonalOrganization()` で個人組織を作る（いずれも登録 `DB::transaction` 内）。
- アプリ主要機能は `verified` middleware 配下（未認証はチケット消費不可）。

### 設計内容

（以下、conceptual-design.md 全文）

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

- **招待経由の登録は付与しない**: 招待ユーザーは既存組織（= 登録時に既に付与済みの組織）へ
  参加するだけで個人組織を作らない。ここへ付与すると「招待 N 人 = N×10 枚」の増幅
  （アビューズ）になる。`provisionPersonalOrganization` を通る通常登録のみに絞ることで
  **1 ユーザー（= 1 個人組織）につき最大 1 回**という意味論が自然に成立する。
- **同一トランザクション内**で付与し、「登録が確定した ⇒ 残高 10」という不変条件を原子的に保証する
  （Feature テストの検証対象そのもの）。

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
4. **既存 Free 組織の救済（backfill）**: signup grant 未付与の既存個人組織へ、同一の冪等付与を
   行うデータマイグレーションを追加し、finding を既存ユーザーに対しても解消する
   （append-only・冪等・追加のみ。詳細設計で要否と範囲を精査）。
5. テスト: 登録後に残高 10 になる Feature テスト（新規）、`grantSignupGrant` 冪等テストの
   更新（新シグネチャ / org スコープキー）、Stripe webhook テストの更新。

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


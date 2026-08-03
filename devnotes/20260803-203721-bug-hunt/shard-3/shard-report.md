# bug-hunt report shard-3 (run 20260803-203721)

- 対象 URL: http://127.0.0.1:8013 (DB: bug_hunt_3)
- 割当ストーリー: S4 (組織・プロジェクト管理) → S5 (課金・チケット)
- モード: LLM=real, storage=fake, mail=log, 決済=Stripe fake, --deviate 有効
- 開始: db-check OK (db=bug_hunt_3, users=11)

## 実行ストーリー
- S4: 完了。組織作成/改名/切替/2FA必須化トグル/オーナー移譲 (T044 stale-invalid 確認込み)、
  API キー発行/失効、プロジェクト CRUD、プロジェクトメンバー追加/削除、カテゴリ CRUD + 並べ替え
  (重複名/50字超えバリデーション込み)、サンプル Item CRUD、権限外直アクセス (member による
  manage/users・categories・api-keys 403、他組織 project 直 URL 404 = fail-closed) を実施。
- S5: 完了。pricing/billing.index/billing.plans/purchase-tickets の全画面、checkout (新規契約
  成功 1 件・契約済み組織での失敗循環を発見・チケット購入 T041 stale-invalid 確認)、portal
  (無償/無契約→friendly error、有償→成功+feedback)、請求先情報 (成功 + native validation 不整合発見)、
  オートリチャージ (範囲バリデーション + stale-invalid 発見 + カード登録 cancel 耐性 + member 403 直POST)、
  着地 feedback バナー (P9、session_id から実セッションを算出し purchase_processing 表示を確認 +
  one-shot 違反を発見 + cross-org IDOR は fail-closed を確認)、attempt_token 冪等 (別プラン→422 /
  他組織 token→404 を直 POST で確認) を実施。

## skip したステップ
- `debug.login-as` / `debug.login`: bughunt.local 環境では `app()->isLocal()` が false のため route 自体が
  未登録 (routes/web.php 585-591行)。意図的な fail-safe 設計であり finding ではない。ManualTestSeeder の
  パスワードログインで代替済み。
- `organizations.api-keys.sessions.revoke`: OAuth 接続セッションは CLI/MCP からの実 OAuth ログインでのみ
  作られ、ブラウザ操作だけでは生成不能なため空一覧のまま (revoke 対象が存在しない)。環境制約による skip。
- `organizations.members.two-factor.reset`: operations.md 上は S2 割当 (shard-3 の対象操作リストに
  含まれないため未実施)。
- `projects.destroy` の「最後のオーナー削除で組織が孤児化しないか」の深掘り: 時間配分優先度により
  S5 の新規手順 7-9 を優先したため未実施。「要確認」に記録。
- P9 手順7 の「purchase_received (Completed)」バナー確定表示: Stripe fake gateway は常に中立帰還
  (cancel_url へ即戻り、webhook を一切発火しない設計、`FakeStripeGateway`/`FakeTicketCheckoutGateway`
  の docblock で明言) のため、実際に決済完了 (`CheckoutSessionStatus::Completed`) 状態を作れず
  `purchase_processing` (Pending) までしか実地確認できなかった。session_id を自前算出して到達したため
  `purchase_processing` の描画・one-shot 不備・cross-org fail-closed は確認済み。
- P8a のオートリチャージ「有効化」(同意パネル `auto-recharge-consent`) 本体: `hasPaymentMethod=true` に
  到達するには実際のカード登録 webhook (`SetDefaultPaymentMethodJob`) が必要で、fake gateway が
  中立帰還のため到達不能。同意文言・range error・cancel 耐性はコードレビュー併用で確認したが、
  実際に「同意して有効にする」ボタン押下からの成功フローは未実走 (環境制約)。

## 画面カバレッジ
S4 対象 (11): organizations.create, organizations.settings, organizations.api-keys.index,
organizations.api-keys.sessions.index, organizations.onboarding.cli, organizations.onboarding.mcp,
manage.users.index, projects.index, projects.create, projects.edit, projects.categories.index — 全 11 走行
S5 対象 (4): pricing, billing.index, billing.plans, billing.tickets.show — 全 4 走行
走行 15 / 15 (未走行なし)

## 操作カバレッジ
S4 対象 (21): organizations.store ✓, organizations.update ✓, organizations.switch ✓,
organizations.transfer-ownership ✓, organizations.two-factor-requirement.update ✓ (自 2FA 未設定ガードに
より弾かれる分岐まで到達), organizations.api-keys.store ✓, organizations.api-keys.revoke ✓,
organizations.api-keys.sessions.revoke — skip (環境制約、上記),
projects.store ✓, projects.update ✓, projects.destroy ✓, projects.categories.store ✓,
projects.categories.update ✓, projects.categories.destroy ✓, projects.categories.reorder ✓,
projects.members.store ✓, projects.members.destroy ✓, projects.items.store ✓, projects.items.update ✓,
projects.items.destroy ✓, debug.login-as — skip (環境 fail-safe)
S5 対象 (6): billing.checkout ✓ (成功 + 契約済み組織での恒久失敗を発見), billing.portal ✓ (成功 + 無契約
エラー分岐), billing.tickets.checkout ✓, billing.contact.update ✓, billing.auto-recharge.update ✓,
billing.auto-recharge.setup ✓
実行 25 / 27 (skip 2、いずれも理由記載済み)

## UI/UX 検証
- H11 (視覚破綻): S4/S5 の全画面で overflow・要素重なり・テキスト切れは観測されず。
- H12 (アフォーダンス/状態): F-3 (メンバー向けオートリチャージ入力欄が disabled 見た目でない) を検出。
  それ以外のボタン活性/非活性・確認ダイアログの主/副階層は概ね明瞭 (確認ダイアログの「キャンセル」
  「削除する」等、危険操作は色分けと文言で判別可能)。
- H13 (レスポンシブ): `billing.index` (mobile 375×667 / tablet 768×1024) と `purchase-tickets`
  (mobile 375×667) で resize 確認。いずれも横スクロールなし (`scrollWidth === clientWidth` を実測)、
  カードが縦積みで折り返し、破綻なし。確認後 desktop (1280×800) に復帰済み。
  screenshots: h13-billing-mobile-375.png, h13-billing-tablet-768.png, h13-purchase-tickets-mobile-375.png
- H14 (a11y 基礎): snapshot 上、フォーム要素は概ね label/role が取得でき (`textbox "メールアドレス"` 等)、
  重大な aria 欠落は観測されなかった。F-2 (native email validation) は a11y というより i18n/一貫性の問題。

## findings

## F-1: 契約済み組織はプラン比較画面から永久にプラン変更できない (循環エラー、機能欠落)
- severity: Critical
- story/step: S5-3 (`billing.plans` / 手順3), S5-4 (`billing.checkout`)
- 再現手順:
  1. `owner-starter@example.com` / `password123` でログイン (Starter プラン組織、有効なサブスク契約あり)。
  2. `http://127.0.0.1:8013/billing/plans` を開く。
  3. Standard の「このプランへ変更」→ 確認ダイアログ「変更する」を押す。
  4. `/billing/plans` に戻り赤い alert 「既に有効なサブスクリプションがあります。プラン変更をご利用ください。」が表示される。
- 期待: 「プラン比較」画面 (`billing.plans`、見出し説明文「現在のプランの変更・新規契約ができます」) から
  Starter→Standard のような paid→paid のプラン変更が完了する。
- 実際: `SubscriptionService::startCheckoutLocked()` (app/Services/Billing/SubscriptionService.php:348-353) は
  「既存の有効な subscription があれば必ず `InvalidArgumentException` で拒否する」設計になっており、
  `BillingController::checkout()` はこれを `back()->with('error', ...)` に変換するだけ。**この例外文言自体が
  「プラン変更をご利用ください」と、まさにユーザーが今使おうとしている画面/機能を指す循環案内になっている。**
  `SubscriptionService` には swap/upgrade に相当するメソッドが存在せず (`grep function` で確認)、
  `Plans.svelte` の `canSwitchTo()` はこの制約を一切考慮せず、有効な契約が既にあっても plan_code が違えば
  常に CTA を活性化する。結果、**既に有償プランを契約している全組織 (owner/admin) が、UI 上どのプラン間でも
  永久にセルフサービスでプラン変更できない**。Personal(無料)→有償の初回契約時のみ成功する。
- 阻害されたユーザージョブ: 既存の有償契約者が上位/下位プランへセルフサービスで切り替えるという、
  課金画面の中核ジョブ (BILL-02) が一度も達成できない。エラー文言も次に何をすべきか実質的な導線を示さない
  (Stripe Customer Portal へ誘導する文言も導線もこの画面には無い)。
- 改善アクション候補: (a) `SubscriptionService` に既存サブスクの swap (Stripe Subscription Update) 経路を実装し
  `startCheckout` から分岐する。または (b) 実装するまでの暫定として `Plans.svelte::canSwitchTo` に
  「既に有効な契約がある場合は不可」の分岐を追加し、`switchBlockedReasonFor` で
  「プラン変更はお支払い管理画面 (Stripe Customer Portal) から行えます」等、実際に完了できる導線
  (`billing.portal` へのリンク) を明示する。
- 証跡: screenshots/F1-plan-change-deadend.png
- 推定原因: `app/Services/Billing/SubscriptionService.php:334-353` (`startCheckoutLocked` 内 Assert)。
  paid→paid の変更経路が未実装のまま `billing.plans` の UI だけが「変更できる」体で公開されている。
- 関連既知情報: 未調査 (devnotes/TODO.md 未確認。P9/P8b 実装ノートに記載があるか要確認)。

## F-2: 請求先メールアドレスの入力エラーがブラウザ既定 (英語) ツールチップで、アプリ内 Japanese validation と不整合
- severity: Medium
- story/step: S5-8 (`billing.contact.update`)
- 再現手順:
  1. `owner-starter@example.com` でログイン → `/billing` → 請求先情報カードのメール欄に `not-an-email` を入力 → 「請求先情報を保存」を押す。
  2. ブラウザ既定の HTML5 constraint validation ツールチップ (英語: "Please include an '@' in the email address...") が表示され、フォームは送信されない。
- 期待: アプリ内の他フォーム (プロジェクト名・カテゴリ名等) と同様、送信後に日本語のインラインエラー文言が
  フィールド直下に表示される (`FormField` の `error` prop 経由、`billing_contact_email` の server validation メッセージ)。
- 実際: `Input` コンポーネントに `type="email"` が指定されているため、ブラウザの native constraint validation が
  `submit` イベントより先に発火し、フォームの `onsubmit` (→ `router.patch`) まで到達しない。よって
  サーバ側の日本語エラーパスが機能せず、英語の native ツールチップだけが表示される。アプリ全体が日本語 UI である
  にもかかわらずここだけ英語表示になり、かつ他フィールドと見た目・挙動が異なる。
- 阻害されたユーザージョブ: 致命的な詰みではないが、日本語アプリの一貫した検証 UX を破り、外国語表示に
  戸惑うユーザーが再入力の手掛かりを得にくい。
- 改善アクション候補: `Input` の `type="email"` を `type="text"` (inputmode="email" 等) に変え、native validation に
  頼らずサーバ/クライアント双方の日本語エラー表示のみに一本化する。
- 証跡: screenshots/s5-contact-invalid-email.png
- 推定原因: `resources/js/components/features/billing/BillingContactForm.svelte:87` (`type="email"`)。
- 関連既知情報: 未調査。

## F-3: メンバー (manageBilling 権限なし) のオートリチャージ入力欄が編集不可なのに通常の入力欄と視覚的に区別できない
- severity: Medium (H12)
- story/step: S5-9 (`auto-recharge-card`)
- 再現手順:
  1. `member-starter@example.com` でログイン → `/billing` を開く。
  2. 「チケット オートリチャージ」カードの「リチャージ開始残高」「リチャージ後の残高」spinbutton を見る。
  3. 白背景・通常の枠線で見た目は編集可能な入力欄に見えるが、実際にクリック/入力しようとすると
     `fill` が `element is not editable` でタイムアウトする (実質 readonly/disabled)。
- 期待: 権限がなく編集できない入力欄は、disabled 状態が視覚的に (グレーアウト・カーソル変化等) 判別できる。
- 実際: 見た目は通常の入力可能フィールドと変わらず、下に小さいグレーの注記
  「オートリチャージの設定には組織の管理者権限が必要です」があるのみで、フィールド自体の見た目に変化がない。
- 阻害されたユーザージョブ: メンバーが自分の残高が変更できると誤認し、クリック/入力を試みて反応がなく戸惑う (H3 寄り)。
- 改善アクション候補: 非活性時は input に disabled 相当のスタイル (背景グレー・カーソル not-allowed) を適用する。
- 証跡: screenshots/s5-billing-member-readonly.png
- 推定原因: 未調査 (5分以内で特定できず。フロント側コンポーネントの disabled prop 未適用の可能性)。
- 関連既知情報: 未調査。

## F-4: 着地 feedback バナー (P9) が「one-shot」ではなくリロードで無限に復活する (URL の session_id が消費されない)
- severity: High
- story/step: S5-7 (`billing-feedback-*`)
- 再現手順:
  1. `owner-personal@example.com` でログイン → `/billing/plans` → Starter の「このプランへ変更」→ 確認 → Stripe fake
     checkout (中立帰還) を経由し `/billing/plans?fake_external=stripe` へ戻る (実装上、fake gateway は常に
     cancel_url へ中立帰還するため `POST /billing/checkout` のリクエストボディから
     `subscription_attempt_token` を取得し、`sha256("sub_start:"+token)` の先頭32桁から
     `cs_bughuntfake_{hash}` の実際の session_id を算出できることを確認した。これは実装が
     idempotency key から決定的に session_id を導出する設計であるため成立する)。
  2. その `session_id` を使い `http://127.0.0.1:8013/billing?session_id=cs_bughuntfake_<hash>` を開く →
     想定通り one-shot バナー「お支払いを確認しています。プラン反映までしばらくお待ちください。」
     (`billing-feedback-purchase_processing`) が表示される。
  3. **同じ URL のまま `playwright-cli reload` (ブラウザの単純リロード) する** → バナーが**再び表示される**。
     何度リロードしても消えない。
- 期待: S5 カード手順7「**one-shot** バナー...1 度だけ出る。...リロードで復活しないこと」
  (`Billing/Index.svelte` 44行目のコメントも「一度表示したら消える」を明言)。
- 実際: フィードバックはサーバ側 session flash ではなく **`?session_id=` クエリ文字列から都度導出**される
  純粋な GET 副作用なしロジック (`BillingController::resolveBillingFeedback()`)。フロントエンドが
  表示後に `router.replace`/`history.replaceState` 等で URL からクエリを除去していないため、
  ブラウザの通常のリロード (F5 相当) では同一 URL に再アクセスするだけで**何度でも同じバナーが復活**する。
  実運用では Stripe から `/billing?session_id=...` に着地した URL がブラウザ履歴・ブックマークに残るため、
  ユーザーが後日そのページを再訪 (履歴から戻る等) すると「支払いを確認しています」等の古い状態を
  何度でも見せられ続ける。設計コメントの「リロードで query が落ちれば feedback は null で届く」という
  前提は誤りで、ブラウザの reload は query を保持したまま再送する。
- 阻害されたユーザージョブ: 決済完了直後の状態把握 (H10 寄り: 直前操作の結果と矛盾する状態表示が
  何度でも再出現し、ユーザーが「まだ処理中」「まだ確認していない」と誤認し続ける可能性)。
- 改善アクション候補: バナー描画後に `router.replace(url.pathname, ...)` 等でクエリを URL から除去する
  (Inertia の `router.visit(..., { replace: true, preserveState: true })` や `window.history.replaceState`)。
  もしくはサーバ側で 1 回参照したら該当セッションに `feedback_shown_at` 等の消費マーカーを立てる。
- 証跡: (screenshot 省略。同一 URL への `goto`→`reload` で `billing-feedback-purchase_processing` の
  status テキストが再出現することを snapshot で確認。手順は上記に記載し誰でも再現可能)
- 推定原因: `resources/js/pages/Billing/Index.svelte:44` のコメント前提の誤り + URL クエリ scrub 未実装。
  `app/Http/Controllers/Billing/BillingController.php:449-511` (`resolveBillingFeedback`) がクエリ文字列
  そのものを正としている。
- 関連既知情報: 未調査。なお **cross-org IDOR は fail-closed で正しく防御されている**ことを別組織の
  実 session_id で確認済み (組織 A の実 session_id を組織 B の `/billing` に付けてもバナーは出ない)。

## F-5: オートリチャージの範囲エラーが値を直しても消えない (stale invalid、T041/T044 パターンからの逸脱)
- severity: Medium
- story/step: S5-9 (`auto-recharge-range-error`)
- 再現手順:
  1. `owner-starter@example.com` でログイン (Starter プラン組織) → `/billing`。
  2. 「チケット オートリチャージ」のリチャージ開始残高に `50`、リチャージ後の残高に `50` (同値、不正な組み合わせ) を入力し
     「設定を保存する」を押す → `auto-recharge-range-error`
     「リチャージ後の残高は開始残高より大きい値を指定してください」が表示される (正しい)。
  3. リチャージ開始残高を `5` に直す (今度は 5 < 50 で有効な組み合わせになる)。**ボタンは押さない。**
  4. エラー文言が消えずに残り続ける (`auto-recharge-range-error` の DOM は健在。フィールドの赤枠だけは消える)。
- 期待: 同アプリの他フォーム (チケット購入枚数 T041 / オーナー移譲の移譲先選択 T044 で確認済み) と同様、
  値を有効な組み合わせに直した時点でエラー文言が即座に消える (stale invalid を残さない)。
- 実際: `AutoRechargeCard.svelte` の `inputError` は `$state` で、`ensureValidRange()`
  (ボタン押下時のみ呼ばれる) の中でしか更新されない。`rangeError` 自体は `$derived.by` でリアクティブに
  再計算されているが、表示に使われる `inputError` はボタンを再度押すまで古い値のまま固定される。
  もう一度「設定を保存する」を押すと (今度は妥当な値のため) 成功し `onSuccess` でクリアされる、または
  再度 `ensureValidRange()` が呼ばれてエラーが消える動作は確認できた。
- 阻害されたユーザージョブ: 値を正しく直したのに「まだ間違っている」という表示が残り、ユーザーが
  無用な再確認・ためらいを強いられる (H10 寄りの矛盾表示)。
- 改善アクション候補: `inputError` を `$derived(rangeError)` にし、押下時に限定せず入力変更にも追従させる
  (T041 のチケット購入フォーム・T044 のオーナー移譲 select と同じパターンに揃える)。
- 証跡: screenshots/F5-auto-recharge-stale-invalid.png
- 推定原因: `resources/js/components/features/billing/AutoRechargeCard.svelte:44-46,160-164`
  (`inputError` が `$state` かつ `ensureValidRange()` 呼び出し時のみ更新される設計)。
- 関連既知情報: T041 (チケット購入枚数) / T044 (オーナー移譲) は同一パターンの stale-invalid 解消を
  正しく実装済みであることを本 run で確認済み (`shard-3` 内の該当節参照)。新規実装の本カードだけが
  この規約から外れている可能性が高い。

## 要確認 (仕様不明)
- 最後のオーナーがメンバー除名/組織削除経路で自分自身を除名しようとした場合、組織が孤児化しないか
  (owner 行に「削除」ボタンが出ないことは `manage/users` UI で確認済みだが、直 POST での防御は未検証)。
  severity は付けず要確認のまま。
- F-1 (プラン変更の恒久失敗) について: 「paid→paid のプラン変更は意図的に Stripe Customer Portal
  のみで行う設計」なのか、「単に未実装」なのかは devnotes/docs から確認できなかった。もし前者が仕様なら
  `billing.plans` の CTA・文言の方を仕様に合わせて直すべきで、severity の格下げも含め要確認。

## インベントリ修正提案
- 特になし。screens.md / operations.md の S4/S5 割当ては実装と一致していた (ドリフトなし)。

## Critical/High サマリ (TODO 候補)
- **F-1 (Critical)**: 契約済み組織が `billing.plans` からプラン変更を一度も完了できない (循環エラー)。
  再現: shard-report.md F-1 節。阻害ジョブ: BILL-02 (プラン申込/変更)。改善候補:
  `SubscriptionService` に swap 経路を実装 or `Plans.svelte` の CTA 文言を Portal 誘導に修正。
  関連ファイル: `app/Services/Billing/SubscriptionService.php:334-353`,
  `resources/js/pages/Billing/Plans.svelte:44-49`。
- **F-4 (High)**: 着地 feedback バナー (P9) がリロードで無限に復活する (one-shot 契約違反)。
  再現: shard-report.md F-4 節。阻害ジョブ: 決済結果の正確な状態把握。改善候補: 表示後に URL クエリを
  `history.replaceState`/Inertia `replace` で除去。関連ファイル:
  `resources/js/pages/Billing/Index.svelte:44`, `app/Http/Controllers/Billing/BillingController.php:449-511`。

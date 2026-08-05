# 対応マトリクス: conceptual-review Round 1

## [Critical] `trialing` の扱いが設計上未確定
- 判断: **対応する** (設計に明記)
- 根拠: 実装を実査した。`App\Enums\Billing\SubscriptionState::fromSubscription()` は
  `$activeStatuses = ['active', 'trialing']` を `Active` に写像する (paused / past_due は先に分岐)。
  よって `trialing` かつ `ends_at === null` は本設計の述語で**ブロック対象**になる。指摘のとおり
  設計文面からは読めなかったため、写像表を設計に明記し、検証観点に `trialing` を追加する。
- 対応内容: §4.1 に「stripe_status → SubscriptionState → 本述語」の写像表を追加。V 検証に V10 (trialing) を追加。

## [Warning] `ends_at !== null` を常に通過させる説明が強すぎる (pending proration / usage)
- 判断: **対応する** (前提を明記)
- 根拠: 実査。aicue の subscription は **base price + quantity のみ** (`subscriptions.stripe_price` /
  `quantity`、metered / usage-based price は存在しない。`grep -rn "metered\|usage_type" app/Services/Billing` = 0 件)。
  従量課金はチケットの**都度購入** (`TicketCheckoutGateway` = 別 Checkout) で表現され、subscription には載らない。
  唯一の pending proration 源は plan 変更 (`CashierStripeGateway` の `proration_behavior => 'create_prorations'`)。
  これは**既に消費した差額**であり、決済手段は Stripe 顧客側に残る。「請求先が消えたまま自動更新が続く」という
  本件の害 (I1) とは別物なので、ブロック理由にはしない。
- 対応内容: §4.1 に前提として明記 (metered なし / proration は既発生分のみ)。

## [Warning] `incomplete` / `incomplete_expired` の通過根拠
- 判断: **対応する** (根拠を明記。ただしブロック対象には**しない**)
- 根拠: `incomplete` は初回支払いの追加認証待ち。完了させられるのは**そのユーザー本人**だけで、
  退会後は完了操作の主体がいない。Stripe は 23 時間で `incomplete_expired` に落とす。
  一方でブロック対象にすると、ユーザーは自力で解消できないまま最長 23 時間退会できない
  (= AGENTS.md が嫌う「行き先のない詰み」)。害の大きさが逆転しているため通過させ、残存リスクとして明記する。
- 対応内容: §4.1 に写像表と根拠、§4.4 (残存リスク) に記載。

## [Critical] TOCTOU: subscription 行の一貫性がロック範囲に含まれるか不明
- 判断: **一部対応する / 一部反論する**
- 根拠 (反論部分): 指摘どおり subscription 行を `lockForUpdate()` すると**デッドロックを新設する**。
  webhook 側 `SubscriptionService::applySubscriptionSnapshot()` は
  **subscriptions を `lockForUpdate` → organizations を UPDATE** の順で錠を取る。
  一方 `deleteAccount()` は **users → organizations** の canonical 順。ここに subscriptions を足すと
  `orgs → subs` となり、webhook の `subs → orgs` と**逆順**になって cross-order deadlock (40P01) を生む。
  webhook 側を書き換えるのは冪等マシン (セキュリティ不変条件「課金の冪等性」) の中心を触ることになり、
  本件のスコープに対して過大。
- 根拠 (対応部分): 代わりに「**組織行ロック取得後**に subscription を読む」ことを設計で固定する。
  PostgreSQL の READ COMMITTED では各文が最新コミットを見るため、
  「組織行をロックした時点までにコミット済みの課金状態」で判定できる。さらに webhook 側は
  organizations を UPDATE するために我々が保持する org 行ロックを待つので、
  判定〜削除の間に plan_code 同期を伴う webhook が割り込むことはない。
- 対応内容: §4.4「競合 (TOCTOU) と錠の順序」を新設し、ロック順序・採らない理由・残存窓
  (支払い完了〜webhook が行を INSERT するまでの秒〜分オーダー) を明記。残存窓の受け皿は
  後続 TODO 候補「オーナー不在の課金中組織の検知」。

## [Warning] `/billing` は current org スコープ。別組織の blocker では行き先が曖昧
- 判断: **対応する**
- 根拠: 実査。`routes/web.php` の `billing.*` は route parameter を持たず
  `ResolvesCurrentOrganization` で current org を解決する。blocker が current org でない場合、
  `/billing` リンクは別組織の課金画面を開いてしまい、指摘のとおり詰みに近い。
- 対応内容: blocker DTO に `isCurrent` を持たせ、current org でない場合は
  「組織を切り替えて請求設定を開く」導線 (既存 `POST /organizations/{slug}/switch` →
  成功後にクライアントで `/billing` へ遷移) に切り替える。新 endpoint も redirect パラメータも作らない
  (open redirect 面を増やさない)。

## [Warning] props 形状の drift。enum 同期だけでは弱い
- 判断: **一部対応する / 一部見送る**
- 根拠: 詰み (= 本機能の失敗様式) を生むのは「**未知の理由値が来て UI が何も描けない**」ケースだけ。
  組織名 / slug / isCurrent はスカラで、欠落すれば `pnpm typecheck` と Feature テストが落ちる
  (静かに壊れない)。理由 enum の PHP⇔TS 同期 (既存 `Tests\Support\TsUnionValues` 再利用) と、
  props 形状を検証する Feature テストの 2 枚で足りる。生成型の導入は本件の射程外 (思考原則 2)。
- 対応内容: §6.1 のテスト項目に「props 形状の Feature 検証」を明示。

## [Warning] 「保持期間の実装をしない」は TODO 登録条件まで含めよ
- 判断: **対応する** (ただし本セッションでは TODO.md を触らない)
- 根拠: 設計フェーズは設計ファイルのみ生成する規約。TODO 登録は `app-todo-add` の責務。
- 対応内容: §6.2 の「後続 TODO 候補」を、登録する際のタイトル・前提条件 (規約正式化) 付きで具体化。

## [Warning] DTO / Inertia / ValidationException の責務分離を明記
- 判断: **対応する**
- 根拠: 妥当。表示 (props) とブロック応答 (ValidationException) が別々に文言を組み立てると必ずずれる。
- 対応内容: §4.2 に「blocker DTO は 1 本、文言生成は 1 か所」の責務分離を明記。

## [Suggestion] 使命整合 / 実現可能性 / スコープの妥当性
- 判断: 追加対応なし (現行記述で足りる)

# Round 2: 指摘への対応と再レビュー依頼

Round 1 の指摘に対する対応マトリクスと、修正後の概念設計全文を送る。
[Critical] 1 件・[Warning] 8 件はすべて設計側を修正した。[Suggestion] 1 件は
現状で満たしている旨の反論を添えた (根拠は対応マトリクス参照)。

再レビューでは、対応が指摘の意図を満たしているか、修正によって新たな
矛盾・過剰設計・保証の誇張が生じていないかを見てほしい。全体判定を明示すること。

---

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] 猶予切れ後の無料枠すり抜け: 条件が past_due / paused だけでは不足
- 判断: 対応する
- 根拠: `unpaid` は Stripe 上「契約は残り請求は未払い」であり、`canceled` (終了) と
  無料枠へ降りてよいかの答えが逆になる。現行 `SubscriptionState` は両者を `Inactive` に
  畳んでいるため、状態ごとの意味を固定しないと指摘どおり回帰する。
- 対応内容: `SubscriptionState::allowsFreePlanFallback()` を網羅 `match` で新設し、
  状態ごとに可否を明示。`Unpaid` を 1 case 追加して `canceled` と分離した
  (`grantsAccess()` は両方 false のままなので遮断挙動は不変)。各 case の理由を概念設計に列挙し、
  詳細設計で `SubscriptionState::cases()` を回すテーブル駆動テストを施策に含める。

## [Warning] PM 有無の単調修復では「両方向の回復」という主張とずれる
- 判断: 対応する (主張を弱める側に寄せる)
- 根拠: `recordPaymentMethodSnapshot` の単調性は「Stripe payload が PM を expand しない周期がある」
  ことへの防御であり、これを崩すと trial 終了判定が誤発火する。突き合わせのためだけに
  同じ列へ 2 つ目の書込意味を持ち込むほうが危険。
- 対応内容: 概念設計 (c) に「true 方向のみ修復。PM 削除の取りこぼしは対象外」と明記し、
  なぜ権利判定としては閉じるか (PM が実際に無ければ次の請求が失敗し past_due → 猶予で遮断)
  を書いた。期待効果の文も「stripe_status は両方向・PM は true 方向のみ」に修正。

## [Warning] reconcile が同じ snapshot 経路を通る保証が曖昧 / 列直書きの抜け道
- 判断: 対応する
- 根拠: 指摘のとおり、正規化の形を決めないと command 側で列を直接更新する実装になりうる。
- 対応内容: gateway は SDK オブジェクトを返さず、webhook が payload から組むのと同じ
  `SubscriptionSnapshot` + PM 有無を包んだ DTO を返す形に固定。コマンドは
  `applySubscriptionSnapshot` / `recordPaymentMethodSnapshot` 以外の書込をしないと明記した
  (詳細設計では Architecture テストで `past_due_since` の書込単一化を機械固定する)。

## [Warning] past_due_since が「観測日」になるズレ / 効果の主張が強すぎる
- 判断: 対応する
- 根拠: 事実として観測時刻であり、実際の失敗時刻ではない。誇張しない規約に合わせる。
- 対応内容: 「期待効果として主張しないこと」節を新設し、保証するのは
  「無期限の利用が止まること」と「起点が観測時刻として必ず残ること」だけだと明記。
  Stripe の請求履歴から起点を復元する案は、外部 API 呼び出しを増やす割に得るものが
  数日の厳密さしかないため採らない、と判断理由を残した。

## [Warning] AG-035 (5) の「残高切れ」側の扱いが未記録
- 判断: 対応する
- 根拠: スコープ外にするのは妥当でも、標準形としての決定を残さないと未実装と区別できない。
- 対応内容: 「残高切れの猶予は 0 (予約時点で即拒否)」を決定として `docs/architecture.md` に
  書く作業を施策に含め、`PaymentGracePolicy` が答えるのは支払い失敗の猶予だけだと明示した。

## [Warning] 二重契約防止を controller に寄せると別経路で漏れる
- 判断: 対応する (元から service 層本体の意図だったので明文化)
- 対応内容: 「拒否の本体は `startCheckout` 段 1 (最下流)。controller の変更は遷移先の選択だけで
  判定を二重に持たない」と概念設計 (e) に明記した。

## [Warning] backfill が課金データ補正になる / 手動手順を前提にしない
- 判断: 対応する
- 対応内容: 移行節に「補正は migration の中だけで完結させる。runbook にも手動 SQL / tinker で
  `past_due_since` を書かないと明記する」を追加した。

## [Warning] スコープが大きい / 途中状態を作らない
- 判断: 対応する
- 対応内容: 制約に「1 PR で完結させる (段階分割すると、その間だけ課金回避が成立する)」を追加。
  実装モードは standalone とする。

## [Warning] Stripe 読み取りの戻り値型が未定義 (PHPStan level 10 / fake の重さ)
- 判断: 対応する
- 対応内容: 上記のとおり gateway は DTO を返す形に固定。テストは interface のスタブを
  container に bind して駆動する (Stripe SDK は Laravel HTTP client を通らないため
  `Http::fake` では止められない、という前提も制約に書いた)。

## [Suggestion] 使命の位置づけを「原価管理」として書く
- 判断: 対応する
- 対応内容: 期待効果の 1 項目目を「体験を続けられる形で提供するための原価管理」と書き直した。

## [Suggestion] enum 値を UI へ直接渡さない
- 判断: 見送る (現状すでに満たしている)
- 根拠: `EntitlementDeniedReason` は `SubscriptionEntitlementDto` の中だけで使われ、
  Inertia props に露出している箇所は無い (`BillingAccess` は `->entitled` しか読まない)。
  画面へ出る文言は `RequireActiveSubscription` の既存定数と着地ページが持つ。
  今回 props へ露出させる予定も無いため、追加の変換層は作らない (今必要なものだけ作る)。

---

## 修正後の概念設計 (全文)

# 概念設計: billing-payment-grace-policy

## 背景・課題

lctl 台帳の feature `billing-gate-inversion-personal-plan` は、家系共通の裁定 AG-035 (2026-08-05) で
6 項目を確定させている。AI-CUE (本リポジトリ) はそのうち **(5) 支払い失敗時の猶予を期限として持つ**
と **(6) 決済事業者との定期的な突き合わせ** の 2 点だけが未達である
(台帳の観測点 aicue@a5553b5、2026-08-13 に再実読で確認)。本セッションでも次を確認した:

- `past_due_since` / `PaymentGracePolicy` / `payment_grace` は `app/` 配下で 0 件
- Stripe の契約状態をローカルと突き合わせるコマンドは無く、`routes/console.php` の日次配線にも無い
- ゲート反転 (`ActiveFreePlan`) 本体・無料チケット付与規則の単一化は完了済み

### いま何が起きているか (実装の実読)

`SubscriptionService::deriveEntitlement()` は次の判定になっている。

- `SubscriptionState::PastDue` (Stripe が `past_due` = 請求失敗して督促中) は `grantsAccess()=true`
- 決済手段 (PM) があれば trial 条件にも該当しないので **`entitled=true`**
- コメントにも「請求失敗中も利用継続」と明記されている

つまり **支払いが失敗したまま、いつまででも業務機能を使い続けられる**。止まるのは Stripe 側が
督促を諦めて `canceled` / `unpaid` / `paused` に落としたときだけで、その期日はアプリのどこにも
書かれておらず、アプリは自分で「どこまで使わせるか」を決めていない。これは AG-035 (5) が
「猶予は期限を持たせる」と確定させた点そのものの欠落である。

さらに、その `past_due` への遷移をアプリが知る唯一の経路は webhook である。Stripe 自身が
「通知は最大 3 日ずれうるので照合を推奨する」と明示しており (AG-035 の一次調査結論)、
webhook を 1 通落とすとローカルの `stripe_status` は古いまま固まる。復旧経路が無い
(= AG-035 (6) の欠落)。取りこぼす向きは両方ある:

- **取りこぼしで使わせ続ける**: 実際は past_due / canceled なのにローカルは active
- **取りこぼしで締め出す**: 実際は復旧して active なのにローカルは past_due のまま、
  あるいは PM 登録の通知を落として `has_payment_method=false` のまま trial 終了判定で遮断

### 仮説

「猶予の起点をデータで持ち、期限を 1 か所で判定し、日次で Stripe と突き合わせる」ことで、
支払い失敗の扱いが**アプリの明示的な決定**になる。成功の判定は次の 3 つが同時に成り立つこと:

1. 支払い失敗から所定の日数を過ぎた組織が、業務 route で遮断される (今は遮断されない)
2. 遮断は「支払い方法の更新」という次の一手のある画面へ着地する (詰みを作らない)
3. webhook を 1 通落としても、翌日の突き合わせで許可 / 遮断のどちらの向きにも収束する

## 改善アイデア

### (a) 猶予の起点を列で持つ — `subscriptions.past_due_since`

「いつ支払い失敗状態に入ったか」を `subscriptions` に nullable な日時列として持つ。

- 書き込み点は **Stripe 由来の状態同期の唯一の入口** である
  `SubscriptionService::applySubscriptionSnapshot()` に閉じる。ここは webhook
  (`StripeWebhookProcessor::syncSubscriptionState`) と、後述の日次突き合わせの**両方**が通る
  1 本道であり、対象行を `lockForUpdate()` している既存トランザクションの中に打刻を足すだけで済む
- 打刻規則は 3 つだけ:
  - `past_due` を観測 かつ 既存値が NULL → **観測時刻を打つ**
  - `past_due` を観測 かつ 既存値あり → **上書きしない** (猶予の起点を再送のたびに先送りしない)
  - `past_due` 以外を観測 → **NULL に戻す** (復旧・終了で猶予は無かったことになる)
- 書き込みがこの 1 ファイルに閉じていることは Architecture テストで機械的に固定する
  (既存の `FreePlanCodeWriteInvariantTest` と同型。家系の他リポジトリも
  `PastDueSinceWriteInvariantTest` という同名の検査を同じ関心事に割り当てている)

`organizations` ではなく `subscriptions` に置くのは、猶予が「その契約が支払われていない」ことの
属性であり、解約 → 再契約で別行になったときに前の契約の起点を引きずらせないため。
判定側 (`deriveEntitlement`) の入力も Subscription なので、読み替えが要らない。

### (b) 猶予の期限を判定する単一の正本 — `PaymentGracePolicy`

`app/Support/Billing/PaymentGracePolicy.php` を新設し、「猶予は何日か」「もう切れたか」を
**ここだけが答える**。設定値の読み口も本クラスに閉じる (`config('billing.payment_grace_days')`、
既定 14 日)。`deriveEntitlement` はこのクラスに問い合わせ、切れていれば
`EntitlementDeniedReason::PaymentGraceExpired` で否定する。

判定を `deriveEntitlement` の中へ直接書かないのは、AG-035 が「猶予を標準形として持つ」ことを
求めており、日数の意味を持つ場所が 1 つでないと、後から通知文面・画面表示・運用スクリプトが
それぞれ日数を再計算して食い違うため。逆に、**遮断するか否かの最終判定は
`deriveEntitlement` の一本道のまま**にする (entitlement の入口を 2 つにしない)。

猶予の起点が NULL のまま `past_due` を観測した場合は **遮断しない**。起点不明を遮断側に倒すと、
打刻漏れという自分側の不具合がそのまま支払い済み顧客の締め出しになる。代わりに、起点が
埋まらない窓を次の 3 つで有限にする: 単一 writer での打刻 / 日次突き合わせでの修復 / 移行時の backfill。

### (c) Stripe との定期突き合わせ — `billing:reconcile-subscription-status` (日次)

ローカルの `subscriptions` を走査し、Stripe 側の契約を 1 件ずつ読んで**食い違いがあるときだけ**
既存の単一 writer (`applySubscriptionSnapshot`) へ流し込む。突き合わせるのは 3 点:

1. `stripe_status` の相違 (両方向。締め出しにも使わせ続けにも効く)
2. `past_due` なのに猶予起点が NULL (打刻漏れの修復)
3. Stripe 側に PM があるのにローカルが `has_payment_method=false` (**true 方向のみ**。後述)

**コマンドは列を直接書かない**。書込は既存の 2 メソッド (`applySubscriptionSnapshot` /
`recordPaymentMethodSnapshot`) 経由のみで、webhook と同じ 1 本道を通る。そのために
**gateway は Stripe SDK のオブジェクトを外へ出さず**、webhook が payload から組むのと同じ
値オブジェクト (`SubscriptionSnapshot`) + PM 有無を包んだ DTO を返す
(SDK 型が service 層に漏れると PHPStan level 10 と fake の両方が重くなる)。

**PM 有無は true 方向にしか直さない** (既存の `recordPaymentMethodSnapshot` の単調性を崩さない)。
つまり「PM 登録の通知を落として誤って締め出す」側だけを修復し、「PM 削除の通知を落として
true のまま残る」側は**対象外**である。これは取りこぼしを放置してよいという意味ではなく、
有効な決済手段が実際に無ければ次の請求が失敗して `past_due` になり、猶予経路で遮断されるため、
**権利判定としては別の経路で閉じる**という整理である (この非対称を「両方向に直る」と書かない)。

**金銭は動かさない**。チケットの付与・返金には一切触れない (付与の正本は `invoice.paid` と
チケット台帳のままにする)。Stripe 側に契約が見つからない (404) ときは**状態を変えない** —
API キーの環境取り違えでも同じ 404 になるため、確認できなかったという観測として集約報告する。

### (d) 既存のリコンサイルとの責務の切り分け

| コマンド | 周期 | 見る対象 | 書く対象 |
|---|---|---|---|
| `billing:reconcile-auto-recharge` (既存) | 15 分 | `ticket_auto_recharge_attempts` (チケット自動購入の未決) | チケット台帳・attempt 行 |
| `billing:reconcile-schedules` (既存) | 日次 | Stripe Subscription Schedule (予約の作りかけ) | `stripe_schedule_id` / `schedule_setup_status` |
| `billing:reconcile-subscription-status` (新設) | 日次 | Stripe Subscription 本体の契約状態 | `stripe_status` / 猶予起点 / PM 有無 (= `applySubscriptionSnapshot` の担当列) |

15 分のものは**チケットの金銭決着**、日次の既存のものは**予約オブジェクトの作りかけ**、
新設のものは**契約状態そのもの**で、書く列が重ならない。台帳にも「作りかけの予約を直す
reconcile と、事業者の契約状態を真実として権利状態を収束させる reconcile の混同が
別リポジトリで二重計上として実際に起きた」と記録されているため、**既存コマンドに相乗りさせない**。

### (e) 遮断した先の行き先 (詰みを作らない)

猶予切れは `deriveEntitlement` が否定するので、`BillingAccess::state()` は既存の
`ExpiredCheckout` になり、`RequireActiveSubscription` の既存文言
「お支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。」
がそのまま当たる。新しい状態値は増やさない。

ただし着地には 2 つ穴がある (どちらも本設計で塞ぐ):

- **二重契約**: 遮断された管理者は `onboarding.checkout` に着地する。Stripe 側の契約は
  past_due で**まだ生きている**のに、Cashier の `valid()` は past_due を false と見るため、
  現行の `startCheckout` 段 1 の guard を素通りして **2 本目の契約を作れてしまう**。
  **拒否の本体は service 層 (`startCheckout` の段 1)** に置く — 契約開始の経路が今後増えても
  必ず通る最下流だからである。画面側 (`OnboardingController`) の変更は
  「支払い方法を更新できる画面へ逃がす」遷移先の選択だけで、判定を二重に持たない
- **無料枠へのすり抜け**: `BillingAccess::state()` は entitlement が否定されると
  `free_plan_code='personal'` の申告を見て `ActiveFreePlan` (許可) に落ちる。個人無料を申告した
  組織が後から有償契約した場合、申告は残ったままなので**猶予切れの遮断が効かない**。
  AG-035 (3) が禁じた「支払いに失敗した利用者が無料枠と同じ状態に落ちる」ことそのものである。
  対処は**状態ごとに「無料枠へ読み替えてよいか」を明示する述語**
  (`SubscriptionState::allowsFreePlanFallback()`) を置き、網羅 `match` で 1 状態ずつ決める:
  - `PastDue` / `Paused` / `Unpaid` → **不可** (Stripe 側で契約が生きており、未回収の請求がある)
  - `Inactive` (canceled / incomplete / incomplete_expired) → 可 (契約が終了・不成立なので、
    無料枠へ降りるのは現行どおり正しい経路)
  - `Active` / `UpgradeRecovery` → 可 (支払い失敗はまだ起きていない。entitlement が否定される
    のは trial 終了 & カード無しのときだけで、これは未払いの請求を残していない)

  ここで **`SubscriptionState` に `Unpaid` を 1 case 追加する**。現行は Stripe の `unpaid` を
  `canceled` と同じ `Inactive` にまとめているが、`unpaid` は「請求が未払いのまま契約は残っている」
  状態で、`canceled` (終了) とは無料枠へ降りてよいかの答えが逆になるためである
  (`grantsAccess()` は両方 false のままで、遮断の挙動は変わらない)

## 期待効果

- **使命への貢献**: AI-CUE の LLM 解析・動画合成は 1 実行ごとに実費が出る。支払い失敗のまま
  無期限に使える状態は、原価を回収できない利用を無制限に許すことになる。猶予に期限を与えることは
  「思考ゼロ・編集ゼロ」で標準化動画を作れる体験を**続けられる形で**提供するための原価管理であり、
  使命の持続条件にあたる
- **AG-035 (5)(6) の充足**: 家系 6 リポジトリのうち未達 2 つの一方を解消し、台帳の
  aicue セルを implemented に進められる
- **契約状態の取りこぼしからの回復**: webhook 欠落による「使わせ続け」と「誤った締め出し」は、
  `stripe_status` の相違として翌日の突き合わせで両方向に収束する。ただし PM 有無は true 方向
  のみの修復である ((c) 参照)

### 期待効果として主張しないこと

- **「支払い失敗の実時刻から必ず 14 日で止まる」とは言わない**。猶予の起点は
  **アプリが past_due を観測した時刻**であり、webhook を落として翌日の突き合わせで初めて
  気づいた場合や、移行で backfill した既存行では、実際の失敗時刻より後ろにずれる
  (ずれは常に利用者に有利な向き)。Stripe の請求履歴から実際の失敗時刻を復元する案は、
  移行と日次実行のために外部 API 呼び出しを増やす割に、得られるのは数日の厳密さでしかないため採らない。
  本設計が保証するのは「**無期限の利用が止まること**」と「起点が観測時刻として必ず記録に残ること」である

## 実装方針（概要）

| # | 変更対象 | 内容 |
|---|---|---|
| 1 | `database/migrations/` (2 本) | `subscriptions.past_due_since` の追加 + 既存 past_due 行の backfill |
| 2 | `config/billing.php` | `payment_grace_days` (既定 14) |
| 3 | `app/Support/Billing/PaymentGracePolicy.php` (新規) | 猶予日数と期限切れ判定の単一の正本 |
| 4 | `app/Enums/Billing/EntitlementDeniedReason.php` / `SubscriptionState.php` | 否定理由 `PaymentGraceExpired` の追加 / `Unpaid` case と無料枠読み替え可否の述語 |
| 5 | `app/Services/Billing/SubscriptionService.php` | 猶予起点の打刻 (単一 writer) + `deriveEntitlement` の猶予切れ否定 + 新規契約 guard + 収束要否の述語 |
| 6 | `app/Models/Billing/Subscription.php` | 列の cast と property 宣言 |
| 7 | `app/Services/Billing/Contracts/StripeGatewayInterface.php` + Cashier 実装 + Fake | Stripe 契約の読み取り 1 メソッド |
| 8 | `app/Console/Commands/Billing/ReconcileSubscriptionStatus.php` (新規) + `routes/console.php` | 日次突き合わせと配線 |
| 9 | `app/Services/Billing/BillingAccess.php` | 契約が生きている間の無料枠すり抜けを塞ぐ |
| 10 | `app/Http/Controllers/Onboarding/OnboardingController.php` | 支払い不健全な契約がある組織を課金画面 (支払い方法の更新) へ逃がす |
| 11 | テスト | 各施策に Pest テスト + Architecture テスト 1 本 (書込単一化) |
| 12 | ドキュメント | `docs/architecture.md` (監視対象と契約) / `docs/billing-gate-inversion-runbook.md` (移行手順) |

### 移行 (既存行の扱い)

既に `past_due` の行の「いつ失敗したか」は復元できない (Stripe の invoice 履歴からは推定できるが、
そのために移行で外部 API を叩くのは筋が悪い)。よって **backfill は「移行の実行時刻」を起点として打つ**。

- 意味は「猶予はこのデプロイ時点から数え直す」。既存利用者を移行と同時に即遮断しない
  (遡って遮断すると、告知なしに突然止まる)
- 移行を 2 本に分けるのは既存の
  `add_has_payment_method_to_subscriptions` / `backfill_has_payment_method_on_subscriptions` と
  同じ作法に合わせるため
- 移行後に残る NULL の `past_due` 行は日次突き合わせが観測時刻で埋める (前述の (c) 2)
- **補正は migration の中だけで完結させる**。runbook にも「手動 SQL / tinker で
  `past_due_since` を書かない (書込は単一 writer と本 migration のみ)」と明記する
  (状態キーの書込単一化を運用手順の側からも崩さないため)

## 制約・前提

- **entitlement の入口は増やさない**: 利用可否の最終確定は `SubscriptionService::deriveEntitlement`
  の 1 本のまま。`PaymentGracePolicy` は「期限が切れたか」だけを答え、可否は答えない
- **状態キーの書込単一化**: `past_due_since` は状態キーなので `forceFill` + 単一 writer +
  Architecture テストで固定する (`free_plan_code` と同じ扱い)
- **読み取り経路で DB を書かない**: `BillingAccess::state()` は多数の GET で毎回走るため、
  猶予切れの判定でも UPDATE を発生させない (打刻は webhook と日次コマンドだけが行う)
- **外部到達点の目録**: Stripe 読み取りは既に目録登録済みの `CashierStripeGateway` に
  メソッドを足す形にする (新しい到達点クラスを作らない)。待ち上限は既存の Stripe
  クライアント pin をそのまま継承する
- **テストから実 Stripe を叩かない**: 突き合わせは gateway interface 越しにし、
  テストは interface のスタブ実装を container に bind して駆動する
  (Stripe SDK は Laravel HTTP client を通らないため `Http::fake` では止まらない。
  AGENTS.md の「テストレーンの外部 HTTP 出口」も Stripe SDK は保証範囲外と明記している)
- **1 PR で完結させる**: 猶予の遮断だけ先に入れて「無料枠すり抜け」「二重契約」の穴塞ぎを
  後続にすると、その間だけ課金回避が成立する。段階分割せず standalone の 1 実装として通す

## スコープ外

- **チケット残高切れの猶予**: 残高 0 は現行どおり予約時点で即拒否する (猶予を設けない)。
  AG-035 (5) の「残高切れ」側は、前払いチケットという仕組み上「借金して使わせる」ことになるため
  本設計では採らない。**ただし無言の未実装にはしない** — 「残高切れの猶予は 0 (予約時点で即拒否)」
  を標準形の決定として `docs/architecture.md` に書き、`PaymentGracePolicy` が答えるのは
  支払い失敗の猶予だけであることを明示する
- **猶予中であることの画面表示・事前予告通知**: 支払い失敗そのものの通知は既存の
  `PaymentFailedNotification` (Stripe の督促のたびに送られる) が担っている。猶予残日数の
  ダッシュボード表示は UI・TS 型・Svelte を巻き込むため別 TODO とする
- **Stripe 側の督促設定 (dunning) の変更**: Stripe ダッシュボード側の運用設定は触らない。
  本設計の猶予は「Stripe が諦めるより先に、アプリが自分で線を引く」ための上限である
- **`billing:reconcile-schedules` の再構築ロジック**: 予約 (Schedule) の phases 再構築は
  別責務のため触らない
- **無料枠 (`free_plan_code`) の付け外しの自動化**: 有償契約時に個人無料の申告を退役させる
  `retireForPaidSubscription` は現状どこからも呼ばれていないが、これを配線すると
  「解約したら自動で無料枠へ戻る」という既存の説明と矛盾する。本設計では配線せず、
  すり抜けは (e) の読み替え抑止で塞ぐ

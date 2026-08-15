# Round 4: 指摘への対応と再レビュー依頼

Round 3 の [Warning] 3 件・[Suggestion] 2 件はすべて設計側を修正した (反論なし)。
対応マトリクスと修正後の概念設計全文を送る。全体判定を明示すること。

---

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 3

## [Warning] 判断基準を「請求の存在」から「有料価値の提供済みかつ未回収」へ正す
- 判断: 対応する (指摘のとおり、こちらの言い方が不正確だった)
- 根拠: `incomplete` でも初回 invoice は存在しうるので「未回収の請求が無い」は事実に反する。
  本質は「有料の利用権・チケットをまだ提供していない」ことである。
- 対応内容: (e) の基準文を
  「**有料の利用権・チケットを提供済みで、その対価が未回収か** (請求書が存在するかではない)」
  に差し替え、6 状態の理由も同じ言い方に統一した。併せて、この基準が依存する不変条件
  「有料の価値は支払い確定より前に渡さない」を暗黙にしないため、次の 2 点を詳細設計の
  テスト計画へ入れると明記した:
  (i) `incomplete` の契約は entitlement を得ない
  (ii) 有料チケットの付与契機は `invoice.paid` / 決済確定済みの checkout だけで、契約作成時に
  付くのは組織生涯 1 回の無償 signup grant だけである (無料枠の申告でも同じだけ付くため
  有料の前渡しではない)

## [Warning] 期待効果に「翌日の突き合わせで両方向に収束」の古い表現が残っている
- 判断: 対応する
- 対応内容: 「**次にその契約の突き合わせが正常完了したとき**に Stripe 側の観測値へ両方向に
  収束する。PM は true 方向のみ」に書き直した。

## [Warning] 三重ロックの責務と有効期限が未定義
- 判断: 対応する (3 層は残す)
- 根拠: 手動実行の排他は要件である (障害対応で運用者が手で流す経路がある)。また既存の
  `billing:reconcile-auto-recharge` が同じ 3 層構成で本番稼働しており、同種のコマンドで
  形を変えると運用手順が枝分かれする。
- 対応内容: 各層の責務を (c) に明記 — `onOneServer` = 複数 scheduler ノードの同時起動防止 /
  `withoutOverlapping` = 前回実行との重複防止 / `Cache::lock` = 手動実行を含む本体の排他。
  キーと有効期限は詳細設計で固定し、取得失敗は異常終了コードで即終了。有効期限切れで
  自動解放されても書込は単一 writer 経由で冪等なため壊れない (= ロックを手で消す運用手順を作らない)
  ことも書いた。

## [Suggestion] 報告が実際に運用アラート経路へ出ることをテストする
- 判断: 対応する
- 対応内容: 監視対象の項に「報告が実際に運用アラート経路へ出ることをテストで確かめる
  (既存 `DetectOrphanBillingOrganizationsCommandTest` と同じ spy)」を追加した。

## [Suggestion] `?bool` の null の意味を型・PHPDoc で固定し、書込条件を `=== true` にする
- 判断: 対応する
- 対応内容: (c) に「null は『決済手段が無い』ではなく『観測できなかった』であることを DTO の
  PHPDoc に書く。書込条件は `=== true` の厳密比較で、truthy 判定にしないことをテストで固定する」
  と明記した。

## [Suggestion] Stripe status → 内部状態 → fallback 可否のテーブル駆動テスト / スコープ判断
- 判断: 変更なし (すでに反映済み。詳細設計のテスト計画で 1 本のテーブル駆動テストとして固定する)

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

1. 支払い失敗を観測してから所定の日数を過ぎた組織が、業務 route で遮断される (今は遮断されない)
2. 遮断は「支払い方法の更新」という次の一手のある画面へ着地する (詰みを作らない)
3. **日次突き合わせが正常に完了した契約について**、`stripe_status` は Stripe 側の観測値へ
   収束し、PM 登録の取りこぼしは true 方向に修復される。収束が起きるのは「翌日」ではなく
   **次に突き合わせが正常完了したとき**であり、Stripe が契約を返さない (404) / API 障害 /
   scheduler 停止のときは収束しない (これらは無言にせず観測して報告する。(c) 参照)

`billing:reconcile-auto-recharge` (既存 15 分) と `billing:reconcile-schedules` (既存日次) は
どちらもこの穴を埋めていない。前者はチケット自動購入の未決金の回収、後者は予約オブジェクトの
作りかけの修復であり、契約状態そのものを Stripe と突き合わせる経路は 1 本も無い ((d) の表)。

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
  `PastDueSinceWriteInvariantTest` という同名の検査を同じ関心事に割り当てている)。
  **保証範囲を誇張しない**: 走査根は `app/` だけなので、`database/migrations/` の
  backfill (後述) と生 SQL は本検査の母集団に入らない。移行が 1 本きりであることは
  ファイル一覧として詳細設計に固定し、runbook で手動 SQL を禁じることで補う

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

**PM 有無は「観測できなかった」を型で区別する**。DTO では `?bool` にし、**null = 観測できなかった**
(応答に決済手段のフィールドが無い / 展開できなかった) を意味させる。**null は「決済手段が無い」
ではない**ことを DTO の PHPDoc に書き、書込条件は `=== true` の厳密比較にする (truthy 判定に
しないことをテストで固定する)。`false` へ縮約すると既存の単調性を守れないためである。
書き込むのは **明示的に `true` を観測できたときだけ**で、
「PM 登録の通知を落として誤って締め出す」側だけを修復する。「PM 削除の通知を落として
true のまま残る」側は**対象外**である。これは取りこぼしを放置してよいという意味ではなく、
有効な決済手段が実際に無ければ次の請求が失敗して `past_due` になり、猶予経路で遮断されるため、
**権利判定としては別の経路で閉じる**という整理である (この非対称を「両方向に直る」と書かない)。

**金銭は動かさない**。チケットの付与・返金には一切触れない (付与の正本は `invoice.paid` と
チケット台帳のままにする)。Stripe 側に契約が見つからない (404) ときは**状態を変えない** —
API キーの環境取り違えでも同じ 404 になるため、確認できなかったという観測として集約報告する。

**走査の運用契約** (未確認を成功扱いにしないための最低限):

- **重複起動を抑止する**。3 層の責務を分けて書く (既存 `billing:reconcile-auto-recharge` と同型):
  - `onOneServer`: 複数 scheduler ノードからの同時起動を防ぐ
  - `withoutOverlapping`: scheduler から見た前回実行との重複を防ぐ
  - `Cache::lock`: **手動実行を含む**コマンド本体の排他 (障害対応で運用者が手で流す経路がある
    ため残す)。キーと有効期限を詳細設計で固定し、取得できなければ**異常終了コードで即終了**する。
    有効期限が切れて自動解放されても、書込は単一 writer 経由で冪等なため壊れない
    (= ロックファイルを手で消すような運用手順を作らない)
- **`chunkById` で分割して走査する** (契約数に比例して増える全件読み込みをしない)
- **1 件の失敗で全走査を止めない**。失敗した契約は数えて次へ進む
- **1 実行につき 1 回だけ集約報告する**: 確認 / 収束 / 未確認 (404) / 失敗の 4 件数と、
  未確認・失敗の organization id だけを載せる (組織名・メールは載せない。既存の
  `billing:detect-orphan-billing-organizations` の報告契約と同じ形)
- **失敗が 1 件でもあれば異常終了コードで終わる** (scheduler の `onFailure` から報告に載る)。
  未確認 (404) は状態を変えないので正常終了だが、**件数が 0 でなければ必ず報告に出る** =
  無言で成功にしない
- **監視対象**: 本コマンドの終了コードと報告。未確認・失敗が続く契約は
  「Stripe と突き合わせられていない契約」であり、そこでは猶予も遮断も動かない。
  **報告が実際に運用アラート経路へ出ることをテストで確かめる** (「監視対象」と書くだけで
  報告が捨てられていては意味がない。既存の
  `DetectOrphanBillingOrganizationsCommandTest` と同じ spy で観測する)

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
  (`SubscriptionState::allowsFreePlanFallback()`) を置き、網羅 `match` で 1 状態ずつ決める。
  判断の基準は 1 つ、**有料の利用権・チケットを提供済みで、その対価が未回収か**である
  (「請求書が存在するか」ではない。初回決済が通っていない契約にも未払いの請求書は存在しうるが、
  そこではまだ何も提供していない):
  - `PastDue` → **不可**。有料期間を提供したうえで請求が失敗し督促中 = 提供済み・未回収
  - `Unpaid` → **不可**。督促を終えても未払いのまま契約が残っている = 提供済み・未回収
  - `Paused` → 可。trial 終了時にカードが無くて read-only になった状態で、有料期間は提供していない
  - `Inactive` (canceled / incomplete / incomplete_expired) → 可。契約が終了済み、または
    初回決済が通らず成立しなかった状態で、有料の提供に対する未回収を残さない
  - `Active` / `UpgradeRecovery` → 可。支払い失敗は起きていない (ここで entitlement が
    否定されるのは trial 終了 & カード無しのときだけ)

  この基準は「**有料の価値は支払い確定より前に渡さない**」という既存の不変条件に依存している。
  依存を暗黙にしないため、詳細設計で次の 2 点をテストとして固定する:
  (i) `incomplete` の契約は entitlement を得ない、(ii) 有料チケットの付与契機は
  `invoice.paid` / 決済確定済みの checkout だけである (契約作成時に付くのは組織生涯 1 回の
  無償 signup grant だけで、これは無料枠の申告でも同じだけ付く = 有料の前渡しではない)

  ここで **`SubscriptionState` に `Unpaid` を 1 case 追加する**。現行は Stripe の `unpaid` を
  `canceled` と同じ `Inactive` にまとめているが、`unpaid` は「請求が未払いのまま契約は残っている」
  状態で、`canceled` (終了) とは無料枠へ降りてよいかの答えが逆になるためである
  (`grantsAccess()` は両方 false のままで、遮断の挙動は変わらない)。

  **`Incomplete` をさらに分割はしない**。Stripe の `incomplete` は初回請求が完了していない状態で、
  提供済みの利用も未回収の請求も無く、23 時間で自動的に `incomplete_expired` になる。ここで
  無料枠の読み替えを止めると、**カード認証待ちの数時間だけ無料利用者を締め出す**副作用のほうが
  大きい。無料枠は「暗黙の空欄」ではなく申告済みの明示レコードであり、有料契約の初回決済が
  通らなかったことはその申告を取り消す理由にならない (AG-035 (3) が禁じたのは
  「サブスクリプション不在 = 無料」という暗黙の読み替えであって、明示申告の否定ではない)

## 期待効果

- **使命への貢献**: AI-CUE の LLM 解析・動画合成は 1 実行ごとに実費が出る。支払い失敗のまま
  無期限に使える状態は、原価を回収できない利用を無制限に許すことになる。猶予に期限を与えることは
  「思考ゼロ・編集ゼロ」で標準化動画を作れる体験を**続けられる形で**提供するための原価管理であり、
  使命の持続条件にあたる
- **AG-035 (5)(6) の充足**: 家系 6 リポジトリのうち未達 2 つの一方を解消し、台帳の
  aicue セルを implemented に進められる
- **契約状態の取りこぼしからの回復**: `stripe_status` は、**次にその契約の突き合わせが
  正常完了したとき**に Stripe 側の観測値へ両方向に収束する (webhook 欠落による
  「使わせ続け」と「誤った締め出し」の両方に効く)。PM 有無は true 方向のみの修復である ((c) 参照)

### 期待効果として主張しないこと

- **「支払い失敗の実時刻から必ず 14 日で止まる」とは言わない**。猶予の起点は
  **アプリが past_due を観測した時刻**であり、webhook を落として翌日の突き合わせで初めて
  気づいた場合や、移行で backfill した既存行では、実際の失敗時刻より後ろにずれる
  (ずれは常に利用者に有利な向き)。Stripe の請求履歴から実際の失敗時刻を復元する案は、
  移行と日次実行のために外部 API 呼び出しを増やす割に、得られるのは数日の厳密さでしかないため採らない。
  本設計が保証するのは「**アプリが `past_due` を観測できた契約では無期限の利用が止まること**」と
  「起点が観測時刻として必ず記録に残ること」である
- **観測できていない契約は止まらない**。webhook を落とし、かつ日次突き合わせが 404 や API 障害で
  その契約を確認できない状態が続けば、`past_due_since` は作られず遮断も起きない。設計自身が
  404 で状態を変えないと決めている以上これは避けられないので、**未確認・失敗の件数を
  監視対象として定義する**ことで埋め合わせる ((c) の運用契約)

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

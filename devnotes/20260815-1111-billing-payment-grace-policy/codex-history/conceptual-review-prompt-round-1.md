## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【本件の追加文脈】
本設計は家系共通の機能台帳 lctl の feature `billing-gate-inversion-personal-plan` における
裁定 AG-035 の確定項目 (5)(6) の欠落を埋めるものである。裁定の確定内容は次のとおり:
(1) 無料のためのプラン行を新設しない (2) 実行可否の判定根拠はチケット残高に統一する
(3) 「サブスクリプション不在 = 無料枠として許可」と書くことを禁じる。解約・支払い失敗・猶予中は
残高とは別に明示的に持つ (4) 無料チケットの付与ルールを 1 か所で定義する
(5) 支払い失敗・残高切れ時にどこまで使わせるかの猶予を標準形に持つ
(6) 決済事業者との定期的な突き合わせを標準形に足す。
本リポジトリ (aicue) は (1)(3 の一部)(4) は充足済みで、(5)(6) が未達である。

---

## 概念設計

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
3. Stripe 側に PM があるのにローカルが `has_payment_method=false` (単調な true 方向のみ)

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
  支払い不健全な既存契約があるときは新規契約を service 層で拒否し、支払い方法の更新へ誘導する
- **無料枠へのすり抜け**: `BillingAccess::state()` は entitlement が否定されると
  `free_plan_code='personal'` の申告を見て `ActiveFreePlan` (許可) に落ちる。個人無料を申告した
  組織が後から有償契約した場合、申告は残ったままなので**猶予切れの遮断が効かない**。
  AG-035 (3) が禁じた「支払いに失敗した利用者が無料枠と同じ状態に落ちる」ことそのものなので、
  **Stripe 側で契約が生きている状態 (past_due / paused) の間は無料枠への読み替えをしない**。
  終了済み (canceled 等) からの無料枠への降り方は現行どおり維持する

## 期待効果

- **使命への貢献**: AI-CUE の LLM 解析・動画合成は 1 実行ごとに実費が出る。支払い失敗のまま
  無期限に使える状態は、原価を回収できない利用を無制限に許すことになる。猶予に期限を与えることは
  「思考ゼロ・編集ゼロ」を持続的に提供するための原資を守ることに直結する
- **AG-035 (5)(6) の充足**: 家系 6 リポジトリのうち未達 2 つの一方を解消し、台帳の
  aicue セルを implemented に進められる
- **取りこぼしの両方向の回復**: webhook 欠落による「使わせ続け」と「誤った締め出し」の
  どちらも、翌日の突き合わせで自動的に収束する

## 実装方針（概要）

| # | 変更対象 | 内容 |
|---|---|---|
| 1 | `database/migrations/` (2 本) | `subscriptions.past_due_since` の追加 + 既存 past_due 行の backfill |
| 2 | `config/billing.php` | `payment_grace_days` (既定 14) |
| 3 | `app/Support/Billing/PaymentGracePolicy.php` (新規) | 猶予日数と期限切れ判定の単一の正本 |
| 4 | `app/Enums/Billing/EntitlementDeniedReason.php` | `PaymentGraceExpired` を追加 |
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
  テストは Fake / スタブ実装で駆動する

## スコープ外

- **チケット残高切れの猶予**: 残高 0 は現行どおり予約時点で即拒否する (猶予を設けない)。
  AG-035 (5) の「残高切れ」側は、前払いチケットという仕組み上「借金して使わせる」ことになるため
  本設計では採らない。この判断を設計に明記して、無言の未実装にしない
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

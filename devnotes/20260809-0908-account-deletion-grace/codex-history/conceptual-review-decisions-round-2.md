# 対応マトリクス: conceptual-review Round 2

## [Critical] 「最長 7 年」と宣言しながら ledger / subscriptions を無期限保持するのは不整合

- 判断: **対応する (指摘が正しい)**
- 根拠: 「除外目録は不整合を**可視化するが解決しない**」という指摘はそのとおり。
  ただし提示された 3 択のうち 1 つ (「対象外であることを明示」) は、対象の切り出し方を
  **起算点**で行えば恣意的な逃げにならないことに気づいた。実データ構造を読み直して 2 つに分けた。
- 対応内容: §4-3b を全面改訂し、**保持期間の起算点 (retention clock start)** を目録の第一級要素にした。
  1. **起算点 = 取引が終了 / 確定した時点**。継続中の取引はまだ起算していないので、
     「7 年を超えて保持している」に当たらない。これは法定保存期間の一般的な数え方
     (取引終了時から起算) と一致し、恣意的な除外ではない。
     - `Subscription` / `SubscriptionItem`: 起算 = `ends_at`。**`ends_at IS NULL` (継続中) は起算未到来**
       = 対象外。`ends_at` が保持期限より古いものは**対象**になる (今回の削除方式は詳細設計で確定)。
     - `BillingCheckoutSession` / `TicketCheckoutSession`: 起算 = `completed_at` (実在列)。
       null かつ古い行は **fail-closed** (削除せず `report()`) にする。
     - `TicketAutoRechargeAttempt`: 起算 = `resolved_at` (実在列)。同上。
     - `StripeWebhookEvent`: 起算 = `processed_at` (実在列)。未処理のまま古い行は fail-closed。
     - `TicketLedgerEntry`: 起算 = `created_at` (取引成立日)。**起算済みなので逃げられない** → 下記 2。
  2. **`TicketLedgerEntry` は「削除しない」では閉じない**ので、**PR-C2 で残高保存の畳み込み
     (carry-forward 仕訳)** を行う。設計は「保持期限より古い行を出所 (`source`) 別・
     `expires_at` 別に合算し、合計値を持つ 1 行の繰越仕訳へ置換する」。
     `SUM(delta)` が不変なので `sumBalance()` / `balance()` の値は変わらない。
     加えて **残高不変を Feature テストで実測固定**する (畳み込み前後で `availableTrueBalance()` が
     ビット単位で一致すること)。
  3. **完了条件を機械化する**: 「**起算済みかつ保持期限を超過した行が 0 件**」を
     全 target について検査する Feature テスト (`BillingRetentionHorizonTest`) を置き、
     負のコントロール (わざと古い行を作ると赤くなる) を同梱する。
     **この検査が緑になるまで台帳の `implemented` (v1) を主張しない**。
- 帰結: **PR 分割を C1 / C2 に細分し、合計 4 PR** にした (§6)。

## [Warning] 期待効果の表現が事実より強い / 禁止事項 8 を根拠に使うのは誤り

- 判断: **対応する**
- 根拠: 指摘が正しい。禁止事項 8 は「必須条件未充足を理由に disabled にするな」という規約であって、
  破壊的操作の確認ダイアログを否定する規約ではない。根拠として誤用していた。
- 対応内容:
  - 期待効果を「**既定導線における誤操作を回復可能にする**」に修正した。
  - 即時削除に独自の確認機構を足さない根拠から禁止事項 8 を削除し、
    「標準形が併存を必須にしている」「機微操作の関門は `recent-auth` に統一されている」
    「追加機構の費用対効果 (思考原則 2)」の 3 点に絞った。
  - 即時削除の**安全条件**を設計に固定した: 明示文言 / 視覚的な副導線化 / `recent-auth` /
    既存 `SecurityEventType::AccountDeleted` 記録。

## [Warning] purge バッチの失敗分離と競合処理が未定義

- 判断: **対応する (全項目)**
- 根拠: 妥当。1 人目の `ValidationException` で Command が落ちると、以降の期限到達者が
  **毎日処理されない** (しかも静かに)。
- 対応内容: §4-1 に執行バッチの契約を追加した。
  - 主キー昇順の `chunkById` で走査する (走査中の削除で行が飛ばない)。
  - **各ユーザーが独立した処理単位**。`try/catch` は 1 人ずつ。
  - `deleteAccount()` の**ロック取得後に**「予約が生きているか」「`deletion_purge_after <= now()`」を
    再確認する (抽出後に取消されたユーザーを古いスナップショットで消さない)。
    再確認は `deleteAccount()` の内側で行う (判定を Command 側に持たない)。
  - ブロッカーは**対象者単位で `report()` して次へ進む**。
  - 想定外例外も 1 人分で握って `report()` し継続する。**終了コードは常に SUCCESS**
    (cron が連鎖失敗しない)。異常は `report()` が運用へ上げる。
  - 追加 Feature テスト 3 本: 「抽出後に取消 → 削除されない」「二重バッチ (同日 2 回) で
    二重削除・二重通知が起きない」「1 人目 blocker / 2 人目成功 → 2 人目は削除される」。

## [Warning] 凍結 allowlist に認証回復系の経路が不足する可能性

- 判断: **対応する (母集団の定義を明確化)**
- 根拠: 「route 名を推測で列挙するな」は正しい。ただし**母集団は構造的に確定できる**ことを明記していなかった。
- 対応内容:
  - 凍結 middleware は `routes/web.php` の `Route::middleware(['auth','verified'])->group(...)` **にのみ**付く。
    Fortify / パスワード再設定 / メール確認 / 2FA challenge / passkey ログインの各 route は
    **この group の外**にあるため、**構造的に凍結対象外**である (認証回復は塞がれない)。
  - したがって allowlist の母集団は「その group 内の全 route 名」であり、
    `AccountDeletionFreezeRouteGateTest` が **exact-fit で母集団ごと列挙**する
    (推測列挙ではなく、`Route::getRoutes()` から機械抽出した集合との照合)。
  - `dashboard` は **allowlist から外した** (指摘のとおり詰み回避に不可欠ではない。
    遮断の着地は `/settings` = 取消ボタンのある画面)。
  - 到達性は behavioral で固定する: 「予約済みユーザーが**セッション切れから再ログイン →
    取消完了**まで到達できる」「**recent-auth 期限切れの状態から**取消完了まで到達できる」
    「**2FA 有効組織のユーザー**が取消完了まで到達できる」の 3 本を Feature/Browser で置く。

## [Warning] tx 内 dispatch だけではロールバック時の誤通知を防げない

- 判断: **対応する**
- 根拠: 「dispatch の位置」と「ジョブが参照する状態・実行可能時点」は別問題、というのは正しい。
  aicue は `QueueDispatchAtomicityGuard` が driver=database / キュー DB = 業務 DB /
  `after_commit=false` を全環境の起動時に fail-closed 検査するため、commit 前に別プロセスの worker が
  jobs 行を見ることは構造的に起きない。ただし**それは前提であって保証ではない**ので、
  「誇張しない」原則に従って二重化する。
- 対応内容: 通知ジョブ側で **予約の生存を再確認**する契約にした。
  Notification は `deletion_requested_at` / `deletion_purge_after` の値を引数に取り、
  送信直前に user を読み直して**同一の予約がまだ生きているか**を検査する。
  取消済み・再予約で値が変わった・user 不在なら**送らない**。
  Feature テストで「予約 → 即取消 → worker 実行 → メール 0 通」「二重 dispatch で 1 通」を固定する。

## [Warning] target の基準日時に一律 `created_at` は危険 (起算点)

- 判断: **対応する**
- 根拠: 妥当。長期未完了の checkout session が期限経過後に terminal 化すると、
  次の purge で即座に消える = 起算点が取引確定日なら契約違反になる。
- 対応内容: 上の [Critical] 対応と同じ。各 target が **起算イベントと対応列**を必ず持つ。
  実在列を確認済み: `billing_checkout_sessions.completed_at` / `ticket_checkout_sessions.completed_at` /
  `ticket_auto_recharge_attempts.resolved_at` / `stripe_webhook_events.processed_at`。
  **null (未確定) は fail-closed で削除せず `report()`**。target ごとに境界テスト
  (起算日時の 1 秒前 / 1 秒後) を必須にした。

## [Warning] `BillingNotification` の分類が未確定で母集団が閉じていない

- 判断: **対応する (この段階で確定)**
- 根拠: 妥当。「詳細設計で決める」は exact-fit の母集団を開いたままにする。
- 対応内容: **`BillingRetentionExclusion` に確定**。根拠:
  「メール送達の重複防止台帳 (`(type, invoice_id)` / `(type, dedup_key)` の UNIQUE が冪等の調停者) であり、
  取引そのものの記録ではない。行を消すと同じ請求書について通知が再送される。保持ポリシーの所有者は
  課金リマインダ feature 側であり、本目録の対象外とする」。

## [Warning] PR-C のスコープが「7 年保持の完結」には過小

- 判断: **対応する**
- 対応内容: PR-C を **C1 (宣言 + 単一出典 + 目録 + 起算点ベースの purge)** と
  **C2 (ledger の畳み込み)** に分割し、**台帳の `implemented` (v1) 報告は C2 完了後**にした。
  C1 単独でも main は壊れない (宣言と実処理が対応づき、未処理の残りが
  `BillingRetentionHorizonTest` で**赤として可視化される**状態…ではなく、
  C1 の時点では ledger を horizon 検査の対象に**まだ入れない**。C2 で対象に加えて緑にする。
  「入れていないこと」自体は目録に `pending_carry_forward` として記録し、C2 で消す)。

## [Warning] enum に「対象条件」「方式」を持たせると PHPStan level 10 で型が崩れる

- 判断: **対応する**
- 対応内容: `BillingRetentionTarget` enum は**識別子とメタデータ (表示名 / 起算列名 / 根拠) だけ**を持つ。
  実処理は `BillingRetentionPurger` interface (`countExpired(CarbonImmutable): int` /
  `purgeExpired(CarbonImmutable): BillingRetentionPurgeResultDto`) の型付き実装に委譲する。
  Builder / callable の配列を共通化しない。目録テストは enum case と実装クラスの
  **exact-fit 対応**を検査する。

## [Suggestion] privacy テストで節見出しと先例由来の固定文言も検査する

- 判断: **対応する**
- 対応内容: behavioral テストで (a) `data-legal-retention="billing-records"` マーカーの存在、
  (b) 節見出しの存在、(c) 先例由来の固定文言「取引関係書類等」の存在、
  (d) その要素内に config 由来の年数が現れること、の 4 点を検査する。
  「数字だけ別文脈に残る」退行を検出できる。

## [Suggestion] 即時削除の根拠から禁止事項 8 を外す

- 判断: **対応する** (上の [Warning] 対応に統合済み)

# 概念設計レビュー Round 3

Round 2 の全指摘に対応した。対応マトリクス全文は
devnotes/20260809-0908-account-deletion-grace/codex-history/conceptual-review-decisions-round-2.md にある。

## Round 2 指摘への対応サマリー

| 指摘 | 判断 | 要旨 |
|---|---|---|
| [Critical] 7 年宣言と ledger/subscriptions の無期限保持の不整合 | **対応** | 「除外目録は可視化するが解決しない」を受け入れ、逃げ道にならない切り出しを **起算点 (retention clock start)** で行った。詳細は下記 |
| [W] 期待効果が事実より強い / 禁止事項 8 の誤用 | 対応 | 期待効果を「**既定導線における誤操作を回復可能にする**」に修正。根拠から禁止事項 8 を削除し、「標準形の併存要件」「`recent-auth` への統一」「費用対効果」の 3 点に絞った。即時削除の安全条件 4 点を設計に固定 |
| [W] purge バッチの失敗分離・競合 | 対応 | `chunkById` / 1 ユーザー = 1 独立単位 / ロック取得後に予約生存と期限を再確認 / blocker は対象者単位で report して次へ / 終了コードは常に SUCCESS / 指定の 3 テストを必須化 |
| [W] 凍結 allowlist の認証回復系 | 対応 | 母集団を構造で確定した。凍結 middleware は routes/web.php の `auth`+`verified` group **にのみ**付き、Fortify/Passkeys のログイン・再設定・2FA challenge は **この group の外**にあるため構造的に凍結対象外。gate は `Route::getRoutes()` から「凍結 middleware が実際に付いた route 名」を機械抽出して exact-fit 照合する (推測列挙をやめた)。`dashboard` は allowlist から外した。到達性の behavioral 3 本を追加 |
| [W] tx 内 dispatch だけでは誤通知を防げない | 対応 | 通知ジョブ側で**送信直前に予約の生存を再確認**する契約にした。「前提であって保証ではない」ので二重化する旨も明記 |
| [W] 一律 `created_at` は危険 (起算点) | 対応 | 下記の起算点表へ。実在列を migration で確認済み |
| [W] `BillingNotification` の分類未確定 | 対応 | この段階で **exclusion に確定**。根拠 (dedup の調停者であり取引記録ではない / 所有者は課金リマインダ feature) を明記 |
| [W] PR-C のスコープが過小 | 対応 | PR-C を **C1 / C2 に分割** (合計 4 PR)。台帳の `implemented` 報告は C2 完了後 |
| [W] enum に条件・方式を持たせると型が崩れる | 対応 | enum は識別子とメタデータだけ。処理は `BillingRetentionPurger` interface の型付き実装へ委譲 |
| [S] 節見出し・固定文言も検査 | 対応 | behavioral 検査を 4 点 (マーカー / 節見出し / 「取引関係書類等」/ 要素内の年数) に拡張 |

## [Critical] への対応の中身 (ここを重点的に見てほしい)

**保持期間は「取引が終了 / 確定した時点」から数える**という定義を目録の第一級要素にした。
継続中の取引は**まだ起算していない**ので「7 年を超えて保持している」に当たらない。
これは法定保存期間の一般的な数え方と一致し、恣意的な除外ではない。

| モデル | 分類 | 起算点 (migration で実在確認済み) |
|---|---|---|
| `StripeWebhookEvent` | target | `processed_at` (未処理の古い行は fail-closed で残し report) |
| `BillingCheckoutSession` / `TicketCheckoutSession` | target | `completed_at` (null かつ古い = fail-closed) |
| `TicketAutoRechargeAttempt` | target | `resolved_at` (同上) |
| `Subscription` / `SubscriptionItem` | target | **`ends_at`**。`ends_at IS NULL` = 継続中 = **起算未到来 (対象外)** |
| `TicketLedgerEntry` | target (**PR-C2**) | `created_at` = **起算済みなので逃げられない** → **残高保存の畳み込み**で閉じる |

- **`TicketLedgerEntry` の畳み込み**: 保持期限より古い行を出所 (`source`) 別 × `expires_at` 別に合算し、
  合計 `delta` を持つ 1 行の繰越仕訳へ置換する。`SUM(delta)` が不変なので
  `sumBalance()` / `balance()` / `availableTrueBalance()` の値は変わらない。
  残高不変は畳み込み前後の実測比較で固定する。組織行ロック下で行い `reserve`/`commit` と同じロック順序に乗せる。
- **完了条件の機械化**: `BillingRetentionHorizonTest` が「起算済みかつ保持期限超過の行が
  全 target で 0 件」を検査する (負のコントロール同梱)。
  **全 target で緑になるまで台帳へ `implemented` (v1) を報告しない**。
  C1 の時点では ledger を検査対象に入れず、目録に `pending_carry_forward` として記録する
  (C2 でその記録を消して検査対象へ加える)。**「書き忘れ」と「未了」を機械が区別する**。

これで「7 年保持を宣言しながら取引履歴の SoT を無期限除外する」状態は残らない。

---

## 改訂版 概念設計

# 概念設計: account-deletion-grace (猶予期間つき削除 + 保持期間 + 事業者側データ)

> 一次入力: `devnotes/20260809-0908-account-deletion-grace/recon-brief.md`
> 正典: lctl 台帳 feature `account-deletion-billing-guard` / 標準形 v1 (裁定 AG-128, 2026-08-08)
> 実体は spirux:T1133。**オーナー決定 (猶予 30 日 / 保持 7 年 / 凍結方式 / 文面は家系の先例に揃える /
> `consent_version` は `draft-1` から動かさない) は前提であり議論の対象ではない**。

## 0. オーナー決定の転記 (逸脱不可)

| 項目 | 値 |
|---|---|
| 猶予期間 | **30 日** |
| 課金取引記録の保持 | **7 年** |
| 猶予中の扱い | **凍結方式** (users 行の生死を変えない = SoftDeletes を使わない) |
| 規約文面 | **spirux の /privacy「取引関係書類等につき最長 7 年」に揃える。独自の法的主張を書かない** |
| `config/legal.php` の `consent_version` | **`draft-1` から動かさない** |
| 追記文面の位置づけ | **法務レビュー前の草案**。設計と実装の申し送りに明記する |

## 1. 背景・課題

aicue には退会 (アカウント削除) の課金ガード (T115) だけが実在し、標準形 v1 が必須とする 3 点のうち
2 点半が無い。実査で確認した不在 (recon-brief §aicue の現状):

1. **(2) 猶予期間つき削除が完全に未実装**。`deleteAccount()` は `$freshUser->delete()` の物理削除 1 発で、
   予約列・予約 route・取消 route・日次執行バッチのいずれも存在しない。
   **誤操作 (または乗っ取り) で押した退会は 1 秒後には取り返しがつかない**。
2. **(3) 保持期間の実装が無い**。`config/legal.php` は `inquiry_retention_days` しか持たず、
   `resources/views/legal/privacy.blade.php` は保持年数の宣言を 1 行も持たないスタブである。
   規約が何も宣言していないため、実処理と対応づける相手が存在しない。
3. **(1) 決済事業者側データの扱い**が「退会経路から事業者 API を呼ばない」の Feature テスト 2 本だけで、
   **依存閉包を見る静的 gate が無い**。redaction を実施したことの記録口も runbook も無い。

課題の核は 1 である。**「削除は不可逆であり、不可逆な操作には取り消せる窓が要る」**。
現状の aicue は窓が 0 秒であり、これは使命 (現場作業者が専門知識ゼロで使える) と正面から衝突する。
現場の作業者が設定画面で操作を誤ったとき、組織の動画マニュアル資産への到達手段 (owner) が
その場で永久に失われる。

## 2. 改善アイデア

標準形 v1 の (1)(2)(3) を aicue に実装する。**3 つの PR に分割し、依存順に直列で main へ入れる**
(分割の根拠と「中途半端が残らない」ことの説明は §6)。

### PR-A: (1) 決済事業者側データの扱い

- **原則の機械化**: `tests/Architecture/AccountDeletionPathGateTest.php` を新設し、退会経路の
  **依存閉包**から決済事業者 SDK (`Stripe\*` / `Cashier::stripe()` / `->stripe()`) へ到達しないことを
  静的に検査する。現行の behavioral 2 本 (`AccountDeletionTest`) は「呼ばれなかった」ことしか言えず、
  **新しい依存を足した瞬間に沈黙する**。
- **redaction の記録口**: `organizations.stripe_customer_redacted_at` を 1 列足し、
  `billing:mark-stripe-customer-redacted {organization}` (行ロック下 1 回限り・冪等・**Stripe API は呼ばない**)
  で人手 redaction の実施を記録する。
- **runbook**: `docs/account-deletion-runbook.md` を新設。90 日 / 最大 30 日の制約は
  **一次情報 (Stripe 公式 doc) の URL と確認日を同時に書く**。引けなければ「未 pin」と明示する
  (`docs/architecture.md` 自身が現にそう書いている状態を放置しない)。

### PR-B: (2) 猶予期間つき削除 (凍結方式)

- `users` に **`deletion_requested_at` / `deletion_purge_after` の 2 列**を足す。SoftDeletes は使わない
  (users 行の生死を変えない = 凍結方式の定義)。
- **即時削除と猶予つき予約を併存**させる (標準形の必須):
  - `DELETE /settings/account` (`settings.account.destroy`) = **即時削除のまま不変** (副導線)
  - `POST /settings/account/deletion-request` = 猶予つき予約 (30 日。**UI の主導線**)
  - `DELETE /settings/account/deletion-request` = 取消
- **執行は既存の `deleteAccount()` をそのまま呼ぶ**。日次 `account:purge-deletion-requests` が
  `deletion_purge_after <= now()` の user を拾い、既存経路を通す。これにより
  **課金ガードの判定コードが Controller 経路と Command 経路で分岐しない** (判定は 1 本のまま)。
- **本人が気づく手段**として、予約時にメール通知 (`ShouldQueue` + 予約 tx 内 dispatch) を送る。
  乗っ取り起点の予約は本人がログインしていないため、画面内通知だけでは救済が成立しない。
  ただし **dispatch の位置だけでは誤通知を防げない**ので、ジョブは送信直前に user を読み直し、
  **同一の予約がまだ生きているか**を再確認する (取消済み・再予約で値が変わった・user 不在なら送らない)。
  aicue は `QueueDispatchAtomicityGuard` が driver=database / キュー DB = 業務 DB /
  `after_commit=false` を起動時に fail-closed 検査するため commit 前実行は構造的に起きないが、
  **それは前提であって保証ではない**ので二重化する。
- **凍結は deny-by-default**: `auth` + `verified` group 全体に凍結 middleware を付け、
  **予約中に通す route 名を exact-fit の allowlist** で持つ。新しい route は既定で止まる (§4-2)。

### PR-C: (3) 保持期間 (規約の宣言と実処理の対応づけ)

- `resources/views/legal/privacy.blade.php` に **保持期間の節を追記**する。文面は spirux の先例
  「取引関係書類等につき最長 7 年」に揃える。**年数の数値は `config('legal.billing_retention_years')` から
  描画する** (§4-3 の三者一致の要)。
- `config/legal.php` に `billing_retention_years => 7` を足す。**env は使わない**
  (`config/idempotency.php` の `retention_hours` と同じ理由 — 環境ごとに変えてよい運用値ではない。
  まして法務文書が宣言する値である)。
- 日次 `billing:purge-retention-expired` (**dry-run 既定 + `--apply`**。`PurgeInquiriesCommand` が先例) が
  保持期限を超えた課金取引記録を処理する。
- **三者一致の機械化**は「照合」ではなく「単一出典化」で行う (§4-3)。

## 3. 期待効果

- **使命への貢献**: **既定導線における誤操作を回復可能にする**。現場作業者が設定画面で退会を
  押しても、30 日以内なら自力で取り消せる。即時削除は明示文言つきの副導線として残るので
  「あらゆる誤削除が防げる」とは言わない (誇張しない)。
- **家系への貢献**: aicue セルが `pending` → `implemented` (v1) になる。
  laravel-claude-template / aigenba が「規約文面が無いので (3) は着手不能」と書いた共通制約を、
  aicue は文面追記で解く。その解き方 (**数値だけを config から描画して三者一致を構造化する**) は
  家系 3 リポジトリへ還流できる。
- **観測できる成功条件**: (a) 予約 → 30 日 → 執行の一巡が Feature テストで緑、(b) 予約中でも
  ログイン・取消・解約・移譲に到達できることがテストで固定、(c) 規約の年数 / config / purge 閾値が
  1 箇所を変えると 3 つとも動く (=drift しない) ことが gate で固定。

## 4. 「設計で決めるべきこと」5 点への結論

### 4-1. 凍結方式の具体形 / 即時削除との併存

**結論**: `users` に `deletion_requested_at` + `deletion_purge_after` の 2 列。SoftDeletes は使わない。
即時削除は既存 route のまま**一切変更しない**。予約・取消は**新 route 2 本**で足す。
執行は**既存 `deleteAccount()` の再利用**。

- **なぜ 2 列で、猶予日数スナップショット (aigenba 形) にしないか**: `deletion_purge_after` を絶対時刻で
  持てば「config 変更を既予約へ遡及させない」が 1 列で表現でき、バッチのクエリが
  `where deletion_purge_after <= now()` の 1 条件で済む。日数は `purge_after - requested_at` で導出できる。
  2 つの表現を持たない (思考原則 2)。`deletion_requested_at` を別に持つのは UI 表示と監査のため。
- **なぜ即時削除を予約に置き換えないか**: 標準形が**併存**を必須にしている。加えて既存
  `tests/Feature/Auth/AccountDeletionTest.php` の 16 本は既存 route の即時削除の振る舞いを固定しており、
  ここを予約に変えると 16 本すべてが赤くなる。**既存テストの意味を壊さずに機能を足せる形**を採る
  (禁止事項 3)。
- **UI の主導線は予約**。「30 日後に削除 (取り消せます)」を主ボタン、「今すぐ完全に削除する
  (取り消せません)」を明示文言つきの副導線にする。**即時削除の安全条件** (設計として固定する):
  (a) 不可逆であることの明示文言、(b) 視覚的な副導線化 (主ボタンにしない)、
  (c) `recent-auth` (step-up 再認証)、(d) 既存 `SecurityEventType::AccountDeleted` の監査記録。
  **独自の二段確認機構は足さない**。根拠は 3 点 — 標準形が即時削除の**併存**を必須にしていること、
  機微操作の関門は `recent-auth` に統一されており退会 route は既に `RecentAuthRouteTest` の
  allowlist に載っていること、追加機構の費用対効果が見合わないこと (思考原則 2)。
- **なぜ執行を専用経路にしないか**: 経路を分けると「予約実行時のガード再評価」を新規に書くことになり、
  判定が 2 箇所へ分岐する。`deleteAccount()` をそのまま呼べば、行ロック下の再評価・監査記録・
  ValidationException の契約をまるごと継承できる。
  **層の混在は起きない**: `deleteAccount(User, ?\Closure): void` は Request / Session / redirect に
  一切依存しておらず、HTTP 固有の副作用 (`Auth::logout()`) は Controller が closure として注入している
  (`AccountController::destroy`)。session invalidate / redirect も Controller 側に閉じている。
  バッチは `$beforeDelete = null` で呼ぶ。
- **予約/取消の writer は `OrganizationMembershipService` に置く**。理由は責務ではなく**ロック順序**である。
  予約列の書き込みは `lockForMembershipWrite`(users 昇順 → organizations 昇順) と同じ順序に乗せる必要があり、
  順序の SoT を 2 クラスに分けるとデッドロックの余地が生まれる。`MembershipWriteLockInventoryTest` の
  `directLock` へ 2 メソッドを登録する (drift-guard がそれを強制する)。
- **執行時にブロッカーが立っていたら**: 予約は**維持**し (取消はユーザーの明示操作のみ)、
  `report()` で観測する。予約を勝手に取り消すと「退会したつもりが残っている」、
  執行を強行するとガードの意味が消える。ユーザー側には既存の `accountDeletionBlockers` props が
  そのまま「次の一手」を出し、予約バナーが**「毎日 1 回自動で再試行する」**旨と取消ボタンを併記する。
  凍結の allowlist が解消手段 (解約・移譲) を通すので、詰みにはならない。
  - **`last_deletion_blocked_reason` のような列は持たない**。ブロッカーは
    `organizationsBlockingDeletion()` が毎リクエスト再評価する**導出値**であり、DB に写しを置くと
    真実源が 2 つになって drift する (T115 が「表示 props はスナップショットに過ぎない」と
    設計した理由と同じ。思考原則 4)。

#### 執行バッチ (`account:purge-deletion-requests`) の契約

1 人目で落ちて以降が**静かに毎日処理されない**、という失敗モードを構造的に潰す。

- **走査**: 主キー昇順の `chunkById`。走査中に行が消えても飛ばない。
- **単位**: **1 ユーザー = 1 独立処理単位**。`try/catch` は 1 人ずつ。
- **抽出後の取消への防御**: 予約の生存 (`deletion_requested_at` が非 null) と
  `deletion_purge_after <= now()` を、**`deleteAccount()` のロック取得後に再確認**する。
  判定を Command 側に置かない (バッチのスナップショットで消さない)。
- **ブロッカー**: 対象者単位で `report()` して**次へ進む**。予約は維持する。
- **想定外例外**: 1 人分で握って `report()` し継続する。
- **終了コード**: 常に SUCCESS (cron が連鎖失敗しない)。異常は `report()` が運用へ上げる。
- **必須 Feature テスト**: 「抽出後に取消 → 削除されない」/「同日 2 回実行で二重削除・二重通知なし」/
  「1 人目 blocker で落ちても 2 人目は削除される」。

### 4-2. 凍結中に何を止めるか

**結論**: **deny-by-default**。凍結 middleware `EnsureAccountNotPendingDeletion` を
`auth` + `verified` group 全体に付け、**予約中に通す route 名を exact-fit の allowlist** で持つ。
遮断時は 403 ではなく `/settings` へ redirect し、**取消ボタンのある画面**で受ける。

当初案は「`require-active-subscription` group の中だけを止める」だったが、それだと
`organizations.store` (新組織作成) と `organizations.invitations.store` (招待送信) が
**group の外にあるため素通り**する。この 2 つは「退会ブロッカーを解消する」操作ではなく、
**執行時のブロッカーを増やす**操作 (新しい唯一 Owner 組織 / 新しい孤児メンバー予備軍) である。
group 内/外の既存構造は凍結範囲の定義としては**粗い**と結論し、独立した allowlist を持つ。

| 予約中 | 対象 (allowlist の分類) | 根拠 |
|---|---|---|
| **可** | ログアウト / `session.status` / `recent-auth.*` | 取消の前提。塞いだ瞬間に誤操作救済が成立しない |
| **可** | `settings` (予約バナー + 取消) / `settings.account.deletion-request.destroy` | **取消の唯一の到達先**。ここを塞ぐと詰み |
| **可** | `settings.account.destroy` (即時削除) | 「やっぱり今すぐ消したい」を塞ぐ理由がない |
| **可** | `billing.*` / `billing.tickets.*` / `billing.auto-recharge.*` / `onboarding.*` | 退会ブロッカー (生きた課金責務) を**自分で解消する**手段 |
| **可** | `organizations.transfer-ownership` / `organizations.members.update` / `organizations.members.destroy` / `organizations.invitations.revoke` / `organizations.settings` / `organizations.switch` | 退会ブロッカー (孤児メンバー) を**自分で解消する**手段 |
| **可** | `notifications.*` | 予約・執行不能の通知を読む手段 |
| **不可 (既定)** | 上記以外すべて — 業務 route (projects / manuals / capture) / `organizations.create` / `organizations.store` / `organizations.invitations.store` / API キー発行 / `dashboard` ほか | 執行時に消えるデータと、執行を妨げるブロッカーを新しく増やさせない |

**allowlist の母集団は推測で列挙しない**。凍結 middleware は `routes/web.php` の
`Route::middleware(['auth', 'verified'])->group(...)` **にのみ**付く。したがって:

- **認証回復系は構造的に凍結対象外**である。ログイン / パスワード再設定 / メール確認 /
  2FA challenge / passkey ログインの各 route は Fortify・Passkeys が **この group の外**に登録するため、
  凍結 middleware は 1 本も走らない。「取消へ到達する前に認証で詰む」は起きない。
- `AccountDeletionFreezeRouteGateTest` は `Route::getRoutes()` から
  **「凍結 middleware が実際に付いた route 名の集合」を機械抽出**し、allowlist と **exact-fit** で
  照合する。未登録も、実在しない route 名の登録も、件数のズレも fail。
- `dashboard` は allowlist に**入れない**。取消の到達先は `/settings` であり、
  遮断の着地も `/settings` (取消ボタンのある画面) にするので、`dashboard` は詰み回避に不要。
- **到達性は behavioral で固定する** (allowlist の正しさを人間の目に頼らない):
  「予約済みユーザーが**セッション切れ → 再ログイン → 取消完了**まで到達できる」
  「**recent-auth 期限切れの状態から**取消完了まで到達できる」
  「**2FA 必須組織のユーザー**が取消完了まで到達できる」の 3 本。

- **なぜ deny-by-default か**: allowlist 方式なら **新しい route を足したときに既定で凍結中は止まる**
  (fail-secure)。「業務 group の中だけ止める」だと、group 外に新設された変更系が黙って素通りする。
  本リポジトリの既存 gate 群 (throttle / 認可 / 2FA step-up / 冪等) と同じ流儀に揃う。
- **allowlist の型**: `AccountDeletionFreezeAllowance` (型付き enum) + **30 文字以上の根拠**。
  `tests/Architecture/AccountDeletionFreezeRouteGateTest.php` が **exact-fit** で強制する
  (未登録も、実在しない route 名の登録も、件数のズレも fail)。家系の先例
  (spirux の同名 gate) と同じ形になり、還流もしやすい。
- **なぜ「一切止めない」(aigenba 形) にしないか**: オーナー決定が凍結方式であること、および
  予約中に新規の動画マニュアル・撮影テイクを作らせると 30 日後にそれごと消える (= ユーザーの損失を
  アプリが黙って増やす) ため。
- **なぜ「可」がこれだけ広いか**: AGENTS.md ドメイン規約 4 と同じ思想 —
  **行き先のない詰みを作らない**。「予約中はブロッカーを解消できない」状態を作ると、
  執行もできず取消もしなければ永久凍結になる。上表の「可」はすべて**詰み回避のために必要**である。
- **middleware の実行位置**: `EnsureAccountNotPendingDeletion` は **302 で短絡する** middleware なので、
  テナント境界 404 (`project.in-current-org`) **より後**でなければ 1 bit の存在オラクルになる
  (AGENTS.md 不変条件 10)。`bootstrap/app.php` の priority list の web 鎖の末尾
  (`RequireActiveSubscription` の直後) に append し、`TenantBoundaryOrderingTest` に登録する。
- **route:cache 前提**: group への直付けで配線する。`RouteMiddlewareBinder` の後付けは使わない
  (cached 起動では 1 本も効かず、無音で保護が外れる = T135 / AGENTS.md 運用要件)。

### 4-3. 保持年数と実処理の対応づけの機械化

**結論**: 三者「照合」ではなく **三者「単一出典化」** で機械化する。自然言語の散文は人間が書き、
**数値だけ**が `config('legal.billing_retention_years')` から流れる形にする。

```
config/legal.php  billing_retention_years = 7   ← 唯一の出典 (env を使わない)
        │
        ├─→ App\Support\Legal\BillingRetention::years() / ::threshold()   ← 唯一の解決点
        │        ├─→ resources/views/legal/privacy.blade.php  (規約の文面が描画する数値)
        │        └─→ app/Console/Commands/Billing/PurgeBillingRetentionCommand.php (purge 閾値)
```

- **機械が保証すること**:
  1. 規約 blade の保持期間節が `BillingRetention` 経由でしか年数を描画しない
     (blade に `7` の literal を書けない。`LegalConsentVersionSingleSourceTest` と同じ token 走査 +
     exact-fit caller inventory の書式)。
  2. purge コマンドの閾値が `BillingRetention::threshold()` 由来である (同上の inventory)。
  3. **実描画の behavioral 検査**: `GET /privacy` を実際に叩き、
     (a) `data-legal-retention="billing-records"` マーカー要素の存在、(b) 保持期間の節見出しの存在、
     (c) 先例由来の固定文言「取引関係書類等」の存在、(d) **その要素内に** `config` 由来の年数が
     現れること、の 4 点を検査する。静的走査だけだと「節ごと消えた」も
     「数字だけ別の文脈に残った」も検出できない。
  4. **purge の実挙動**: 閾値の 1 秒前後の境界 2 件で「片方だけ消える」ことを Feature テストで固定する。
- **機械が保証しないこと (「保証しないもの」に明記する)**:
  - **文面の日本語が法的に正しいか / 7 年が法令上妥当か**。これは法務レビューの仕事であり、
    本タスクの追記は**草案**である。
  - **散文部分の意味と実処理の一致**。機械が見るのは数値 1 つとマーカーの存在だけで、
    「取引関係書類等」という語が指す集合と purge 対象テーブル集合が一致することは保証しない。
  - **purge 対象テーブルの網羅性**。対象は inventory への人間の申告であり、
    機械は「申告なしに対象を増減できない」ことしか強制しない。
  - **`consent_version`**: 本タスクでは `draft-1` から動かさない (オーナー決定)。したがって
    「文面が変わったのに版が上がっていない」ことを機械は検出しない。**版の確定はリリース時のオーナー判断**。

### 4-3b. 保持 purge の対象目録と**起算点** (deny-by-default)

**結論**: 対象を散文で書かず、**型付き enum の目録**にする。母集団は `app/Models/Billing/` の
全モデル + `organizations` の課金列で **exact-fit**。分類漏れは fail。
そして目録の第一級要素は基準列ではなく **保持期間の起算点 (retention clock start)** である。

**なぜ目録が要るか (実コードで確認した地雷)**: `TicketLedgerService::sumBalance()` /
`balance()` は **`ticket_ledger_entries.delta` の SUM を残高の唯一の真実源にしている**。
「保持期限を超えた課金取引記録を消す」を素朴に書くと**残高がその場でリセットされる**。

**なぜ起算点が要るか**: 「最長 7 年」と宣言しながら一部を無期限に持ち続けるなら、
その宣言は実処理と一致していない。**除外目録は不整合を可視化するが解決しない**。
逃げ道にならない切り出し方は 1 つだけで、それが**起算点**である —
**保持期間は「取引が終了 / 確定した時点」から数える**。継続中の取引はまだ起算していないので、
「7 年を超えて保持している」に当たらない (法定保存期間の一般的な数え方と一致する)。
`created_at` を一律に使うと、長期未完了だった checkout session が terminal 化した翌日に消える、
という契約違反が起きる。

| モデル | 分類 | 起算点 (実在列で確認済み) | 方式 |
|---|---|---|---|
| `StripeWebhookEvent` | target | `processed_at` (未処理の古い行は fail-closed で残し `report()`) | 物理削除 |
| `BillingCheckoutSession` | target | `completed_at` (null かつ古い = fail-closed) | 物理削除 |
| `TicketCheckoutSession` | target | `completed_at` (同上) | 物理削除 |
| `TicketAutoRechargeAttempt` | target | `resolved_at` (同上) | 物理削除 |
| `Subscription` / `SubscriptionItem` | target | **`ends_at`**。`ends_at IS NULL` = 継続中 = **起算未到来 (対象外)** | 詳細設計で確定 |
| `TicketLedgerEntry` | target (**PR-C2**) | `created_at` (取引成立日 = 起算済み。逃げられない) | **残高保存の畳み込み** |
| `BillingNotification` | exclusion | — | 「メール送達の重複防止台帳で、`(type, invoice_id)` / `(type, dedup_key)` の UNIQUE が冪等の調停者。行を消すと同じ請求書の通知が再送される。取引そのものの記録ではなく、保持ポリシーの所有者は課金リマインダ feature」 |
| `TicketReservation` | exclusion | — | 「TTL で解放される一時状態。所有者は既存の `billing:release-stale-reservations`」 |
| `Plan` / `PlanPrice` / `TicketVolumePrice` | exclusion | — | 「価格カタログであって取引記録ではない。組織にも取引にも紐づかない」 |
| `OrganizationQuota` / `TicketAutoRecharge` | exclusion | — | 「現在の設定値であって取引記録ではない。行の消滅は設定の消滅を意味する」 |

- **`TicketLedgerEntry` の畳み込み (PR-C2)**: 保持期限より古い行を **出所 (`source`) 別 ×
  `expires_at` 別**に合算し、合計 `delta` を持つ 1 行の**繰越仕訳**へ置換する。
  `SUM(delta)` が不変なので `sumBalance()` / `balance()` / `availableTrueBalance()` の値は変わらない。
  **残高不変を実測で固定する** (畳み込み前後で `availableTrueBalance()` が一致すること)。
  組織の行ロック下で行い、`reserve` / `commit` と同じロック順序に乗せる。
- **enum は識別子とメタデータだけ**を持つ (表示名 / 起算列名 / 30 字以上の根拠)。
  実処理は `BillingRetentionPurger` interface (`countExpired(CarbonImmutable): int` /
  `purgeExpired(CarbonImmutable): BillingRetentionPurgeResultDto`) の型付き実装へ委譲する。
  Builder / callable の配列を共通化しない (PHPStan level 10 で型が崩れるため)。
  目録テストは enum case と実装クラスの **exact-fit 対応**を検査する。
- **dry-run 出力形式**: 対象種別ごとの**件数のみ**。organization id・メール・金額を出さない
  (`billing:detect-orphan-billing-organizations` の報告契約と同じ水準)。
- **境界テスト**: 各 target について「起算日時が保持期限の 1 秒前 / 1 秒後」の 2 件を作り、
  **片方だけが消えること**を Feature テストで固定する (母集団 0 件で緑になる空振りを潰す)。
- **完了条件の機械化 (`BillingRetentionHorizonTest`)**: 「**起算済みかつ保持期限を超過した行が
  全 target で 0 件**」を検査する。負のコントロール (わざと古い行を作ると赤くなる) を同梱する。
  **この検査が全 target で緑になるまで、台帳へ `implemented` (v1) を報告しない**。
  PR-C1 の時点では `TicketLedgerEntry` を検査対象に入れず、目録に `pending_carry_forward` として
  記録する (PR-C2 でその記録を消して検査対象に加える)。**「書き忘れ」と「未了」を機械が区別する**。

### 4-4. 決済事業者側 redaction の記録

**結論**: **記録列 1 本 + 記録コマンド 1 本 + runbook** を本タスク (PR-A) に入れる。
**Stripe API は呼ばない / 自動化しない**。

- 標準形 (1) の必須範囲は「退会経路から事業者 API を呼ばない原則の**機械化**」+「redaction の**記録**/運用手順」。
  台帳は laravel-claude-template セルに対して「**docs へ明記しただけを実装とは呼ばない**」と判定しており、
  runbook だけで済ませるとその判定が aicue にも降りてくる。
- 記録が無いと「redact 済みか」が事後に決定不能になり、二重実施と実施漏れを区別できない。列 1 本で足りる。
- 一次情報 URL: `docs/architecture.md` 自身が「台帳側に一次情報の URL が pin されていない。
  数値を運用に効かせる前に一次情報を引き直せ」と書いている。runbook 化するときに引き直し、
  **URL と確認日をセットで**書く。引けなければ「未 pin」と明記して数値を運用に効かせない。
- **列を死蔵させない**ため、runbook に以下を必ず書く (PR-A の完了条件):
  (a) **対象組織の解決手順** — 既存 `billing:detect-orphan-billing-organizations` が日次で
  `report()` する organization id を起点にする (新しい探索経路を作らない)。
  (b) **二重実行時の表示** — 既に記録があれば「YYYY-MM-DD に記録済み」を表示して no-op (冪等)。
  (c) Stripe ダッシュボード側の操作手順と、実施者・実施日の残し方。
- **console 引数由来の organization 解決**は「クラス起点の主キー同一性クエリ」に当たるため、
  `ModelDirectFetchInvariantTest` + `DirectFetchInventory` への登録が要る
  (AGENTS.md セキュリティ不変条件 3)。

### 4-5. 依存閉包の静的 gate

**結論**: **入れる** (`tests/Architecture/AccountDeletionPathGateTest.php`)。

- 現状の behavioral 2 本は「その経路で今日呼ばれなかった」しか言えない。**新しい依存を注入した瞬間に沈黙する**
  (実際、laravel-claude-template の実装レビューで「依存閉包の抽出が型宣言だけの注入を素通りさせていた」
  fail-open が見つかっている)。
- 書式は `tests/Architecture/CachePayloadPlainDataGateTest.php` に倣う: token 解析
  (regex だと**この説明コメント自身**で偽赤になる)、冒頭 docblock に「保証するもの / 保証しないもの」、
  **空振り検知・自己参照コントロール・正負 fixture** を必ず同梱する。
- **走査器を自作しない**: 既存の `Tests\Support\PhpReferenceScanner` (namespace 解決 / alias /
  scope 追跡を持ち、`ExternalSeamInventoryTest` と `ExternalClientTimeoutInventoryTest` が共有する基盤) に乗せる。
  検出すべき到達形は **型宣言だけの注入 (constructor / method parameter / promoted property) /
  facade / static call / `app()`・`resolve()`・`make()` の literal 引数 / trait 経由 / 動的メソッド名**。
  正負 fixture にこの 6 形すべてを入れる (laravel-claude-template では実際に「型宣言だけの注入を
  素通りさせていた」fail-open が実装レビューで見つかっている)。
- **保証しないもの (誇張しない)**: 文字列キーが変数の container 解決 / vendor 内部から出る通信 /
  完全修飾 docblock だけで型宣言も import も無い受け手 / 実行時 config による bind 差し替え。
- **母集団は exact-fit の目録**にする (deny-by-default)。退会経路の起点は
  `AccountController::destroy` / `OrganizationMembershipService::deleteAccount` /
  `PurgeAccountDeletionRequestsCommand::handle` の 3 つで、そこから到達する app/ 内クラスを閉包として辿る。
  免除は型付き enum (`DeletionPathSeamExemption`) + **30 文字以上の根拠**。

## 5. 実装方針 (概要)

| # | 施策 | 主な変更 | PR |
|---|---|---|---|
| A1 | 退会経路の依存閉包 gate | `tests/Architecture/AccountDeletionPathGateTest.php` (新) + fixture | A |
| A2 | redaction 記録列とコマンド | `organizations.stripe_customer_redacted_at` (migration) / `billing:mark-stripe-customer-redacted` | A |
| A3 | runbook | `docs/account-deletion-runbook.md` (新) | A |
| B1 | 予約列 | `users.deletion_requested_at` / `users.deletion_purge_after` (migration) + `User` casts | B |
| B2 | 予約 / 取消 | `OrganizationMembershipService::requestAccountDeletion()` / `cancelAccountDeletion()` + `AccountDeletionRequestController` + route 2 本 | B |
| B3 | 凍結 middleware (deny-by-default) | `EnsureAccountNotPendingDeletion` + `AccountDeletionFreezeAllowance` enum + alias + priority list + `auth`/`verified` group 付与 + `AccountDeletionFreezeRouteGateTest` (新) | B |
| B4 | 日次執行 | `account:purge-deletion-requests` + `routes/console.php` (`daily()->onOneServer()`) | B |
| B5 | UI / DTO | `AccountDeletionStateDto` (新) / `ProfileController` props / `Settings/Index.svelte` / `types/account.ts` | B |
| B6 | 監査・通知 | `SecurityEventType` 2 case + アプリ内通知 + **予約メール通知** (`ShouldQueue`, 予約 tx 内 dispatch, **送信直前に予約の生存を再確認**) | B |
| C1a | 保持年数の単一出典 | `config/legal.php` + `App\Support\Legal\BillingRetention` | C1 |
| C1b | 規約文面 (草案) | `resources/views/legal/privacy.blade.php` | C1 |
| C1c | purge の対象目録と起算点 | `BillingRetentionTarget` / `BillingRetentionExclusion` enum + `BillingRetentionPurger` interface (§4-3b) | C1 |
| C1d | purge | `billing:purge-retention-expired` (dry-run 既定) + `routes/console.php` | C1 |
| C1e | 三者一致 gate + 目録 gate + horizon | `BillingRetentionSingleSourceTest` / `BillingRetentionTargetInventoryTest` / `BillingRetentionHorizonTest` (すべて新) | C1 |
| C2a | ledger 畳み込み | 繰越仕訳による `TicketLedgerEntry` の期限処理 (残高不変を実測固定) | C2 |
| C2b | horizon に ledger を追加 | `pending_carry_forward` を目録から外し検査対象へ | C2 |

既存 gate への登録 (どれも「登録しないと赤くなる」deny-by-default):
`RecentAuthRouteTest` allowlist / `ControllerAuthorizationGateTest` (selfScoped) /
`ThrottleCoverageInventoryTest` (認証面の変更系 2 本のレーン割当。**inline throttle は T125 で使用不可**) /
`MembershipWriteLockInventoryTest` (directLock 2 メソッド) / `TenantBoundaryOrderingTest` (middleware 順序) /
`SecurityEventCoverageTest` (新 case 2 つ) / `ModelDirectFetchInvariantTest` + `DirectFetchInventory`
(バッチが主キー同一性クエリを書く場合) / `JobExecutionDedupInventoryTest` (ShouldQueue を足す場合)。

## 6. 分割の判断 (1 PR にしない根拠)

**4 PR に分割し、A → B → C1 → C2 の順に直列で main へ入れる**。

- **分割の根拠**: 変更面が migration 3 / middleware 1 / route 2 / command 3 / Architecture gate 3 /
  既存 gate 更新 8 に及ぶ。1 PR にすると Codex 実装レビューの粒度が粗くなり、
  「gate はあるが守っていない」型の fail-open (laravel-claude-template で 2 件見つかった種類) を見落とす。
- **依存順の根拠**: B の執行バッチは A の依存閉包 gate の母集団に入る。A を先に入れれば
  B は目録へ 1 行足すだけで済み、逆順だと A で母集団設計をやり直すことになる。
  C は A/B と独立だが、`routes/console.php` と `docs/architecture.md` を 3 PR とも触るため、
  並行 worktree はコンフリクトを生む。直列にする。
- **中途半端が残らないことの説明**:
  - **A 単独で完結する**: 既存の即時削除経路に対する gate と記録口であり、A の後の main は
    「原則が機械で守られ、redaction を記録できる」状態。猶予機能が無いことは A の欠落ではない。
  - **B 単独で完結する**: 予約 → 取消 → 執行の一巡が閉じており、UI から到達できる。
    保持期間が無いことは B の欠落ではない (退会と保持期間は別の問い)。
  - **C1 単独で完結する**: 規約の宣言 (草案) と実処理が対応づき、起算済み・期限超過の行が
    `TicketLedgerEntry` を除いて 0 件になる。未了は `pending_carry_forward` として**目録に明示**され、
    「書き忘れ」と機械的に区別される。
  - **C2 で閉じる**: `TicketLedgerEntry` を horizon 検査の対象に加えて緑にする。
  - どの PR も **feature flag / 後方互換の並走を残さない** (思考原則 3)。
- **台帳への報告 (`implemented` / v1) は C2 マージ後に 1 回**。A/B/C1 の途中で `implemented` を
  主張しない (v1 は 3 点込みであり、`BillingRetentionHorizonTest` が全 target で緑になって初めて
  「保持期間の実装」と呼べる)。

## 7. 制約・前提

- **オーナー決定 (§0) は前提**。値の再議論をしない。
- **`consent_version` を動かさない**。文面追記は**法務レビュー前の草案**であり、
  設計・実装・runbook・PR 説明の 4 箇所に草案である旨を書く。
- **AGENTS.md の不変条件**: 不変条件 10 (層 2 は 404 が先) / 運用要件 route:cache /
  ドメイン規約 4 (課金ゲート group) / ドメイン規約 11 (キュー投入は tx 内。
  **spirux の申し送り「通知を afterCommit で外へ出せ」は aicue の規約と逆なので採らない**)。
- **PHPStan level 10 / Pest / RefreshDatabase グローバル / Factory 必須 / DTO + JsonResource**。
- 組織削除の route は aicue に存在しないため、spirux が塞いだ「両扉」問題は発生しない
  (実査で確認済み)。組織削除を新設する PR が来たら、そのとき同じ判定を通す。

## 8. スコープ外

- **`consent_version` の版上げ**と法務レビュー (リリース時のオーナー判断)。
- **Stripe redaction の自動化** (人手 + 記録に留める)。
- **`inquiries` / アクセスログ / 動画成果物の保持期間**。別 feature。
- **メール通知の配送保証**。at-most-once (アプリ内通知センターの既存契約と同じ)。
  「メールが必ず届く」とは書かない。
- **組織単位の削除・組織の保持期間**。aicue に組織削除の route が無い。
- **inquiries / アクセスログの保持期間**。`inquiry_retention_days` が既にあり、別 feature。

## 9. Codex への論点 (合議で潰したい)

1. 凍結 allowlist (deny-by-default) に**載せ忘れると詰む route** を見落としていないか。
2. 執行時にブロッカーが立っていた場合の「予約維持 + report() + バナー」で、**永久凍結**が発生しないか。
3. 保持期間の三者一致を「単一出典化」で置いたことの穴 (節ごと消える / マーカーだけ残る)。
4. §4-3b の目録で、**消してはいけないものを消す** / **消すべきものが分類漏れする**穴が残っていないか。
5. 3 分割の依存順と、各 PR 単独で main が壊れないことの検証。

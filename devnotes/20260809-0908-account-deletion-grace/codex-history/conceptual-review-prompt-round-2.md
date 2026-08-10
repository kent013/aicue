# 概念設計レビュー Round 2

Round 1 の全指摘への対応マトリクスと、それを反映した概念設計の改訂版を送る。
対応マトリクスの全文は devnotes/20260809-0908-account-deletion-grace/codex-history/conceptual-review-decisions-round-1.md にある。

## Round 1 指摘への対応サマリー

| 指摘 | 判断 | 要旨 |
|---|---|---|
| [Critical] 保持 purge の対象未定義 | 対応 | §4-3b を新設。実コードを読み直した結果、`TicketLedgerService::sumBalance()` は **`ticket_ledger_entries.delta` の SUM を残高の唯一の真実源にしている** ことが判明した (指摘のとおり地雷が実在した)。対象を型付き enum の目録にし、**除外も根拠つきで目録に載せる** deny-by-default にした |
| [W] 即時削除の主導線 | 一部対応 / 一部反論 | UI 主導線は予約であることを明記。ただし独自の二段確認は足さない (`recent-auth` が本リポジトリの標準関門であり、押しにくくする UI は禁止事項 8 の思想と逆行する) |
| [W] `deleteAccount()` の HTTP 前提 | 反論 | 実コードで確認。`deleteAccount(User, ?Closure): void` は Request/Session/redirect に一切依存せず、`Auth::logout()` は Controller が closure で注入している。既に分離済み |
| [W] `organizations.*` 丸ごと可は粗い | 対応 (指摘より強い形) | 凍結の配線を反転。`auth`+`verified` group 全体に付け、**通す route の exact-fit allowlist (型付き enum + 30 字根拠)** を持つ deny-by-default にした。`organizations.store` / `organizations.invitations.store` は allowlist に載せない |
| [W] 依存閉包 gate の検出が弱い | 対応 | 走査器を自作せず既存 `Tests\Support\PhpReferenceScanner` に乗せる。正負 fixture に 6 形 (型注入のみ / facade / static / `app()` literal / trait / 動的メソッド名) を入れる |
| [W] 「構造的に保証される」は言い過ぎ | 対応 | 「判定コードが分岐しない」に言い換え。Feature テスト「予約時は通ったが執行時に blocker が立った場合は削除されない」を必須にした |
| [W] 執行失敗時の可視化 | 対応 (列は足さない) | バナーに理由 + 「毎日再試行」+ 取消。`last_deletion_blocked_reason` 列は持たない (ブロッカーは毎回再評価される導出値。写しを持つと真実源が 2 つになる) |
| [W] メール通知のスコープ外 | 対応 (スコープ内へ) | 指摘が正しい。乗っ取り起点の予約は本人がログインしていないため画面内通知では救済が成立しない。予約メール通知を PR-B に入れる (`ShouldQueue` + **予約 tx 内 dispatch**。aicue のドメイン規約 11 はキュー投入を tx 内と定めており、spirux の「afterCommit で外へ出せ」は採らない) |
| [W] PR-A の redaction 列が孤立 | 対応 | runbook に対象組織の解決手順 (既存 detect バッチの出力を起点) / 二重実行時の no-op 表示 / 一次情報 URL と確認日を含めることを完了条件にした |
| [W] Inertia props の型 | 対応 | `AccountDeletionStateDto` を新設し TS 型と対応させる |
| [W] mark-redacted の organization 解決 | 対応 | `ModelDirectFetchInvariantTest` + `DirectFetchInventory` への登録を施策に明記 |

## 反論した 2 点の根拠 (再掲)

1. **即時削除への二段確認**: 追加しない。機微操作の関門は `recent-auth` に統一されており
   (`RecentAuthRouteTest` が allowlist を CI 固定)、ここだけ独自機構を足すのは思考原則 2
   (今必要なものだけ作る) に反する。押しにくくする UI は禁止事項 8 の思想と逆行する。
2. **`deleteAccount()` の層混在**: 実在しない。シグネチャは
   `deleteAccount(User $user, ?\Closure $beforeDelete = null): void` で、
   HTTP 固有の副作用は呼び出し側 (`AccountController::destroy` が `static fn () => Auth::logout()` を渡す) にある。
   Console からは `$beforeDelete = null` で呼べる。

これらの反論が成立しないと考える場合は根拠を示してほしい。

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

- **使命への貢献**: 現場作業者の誤操作が 30 日以内なら自力で取り消せる。組織の動画マニュアル資産への
  到達手段 (唯一 Owner) が 1 クリックで永久に失われる経路を塞ぐ。
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
  (取り消せません)」を明示文言つきの副導線にする。**副導線に独自の二段確認は足さない** —
  機微操作の関門は `recent-auth` (step-up 再認証) が本リポジトリの標準で、退会 route は既に
  `RecentAuthRouteTest` の allowlist に載っている。ここだけ独自の強い確認を足すのは思考原則 2 に反し、
  「押しにくくする」方向の UI は禁止事項 8 の思想 (押下時にエラーで説明する) とも逆行する。
  監査記録は既存の `SecurityEventType::AccountDeleted` が `deleteAccount()` 内で担う。
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
| **可** | `dashboard` | 遮断後の一般的な着地点。空の詰みを作らない |
| **不可 (既定)** | 上記以外すべて — 業務 route (projects / manuals / capture) / `organizations.create` / `organizations.store` / `organizations.invitations.store` / API キー発行 ほか | 執行時に消えるデータと、執行を妨げるブロッカーを新しく増やさせない |

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
  3. **実描画の behavioral 検査**: `GET /privacy` を実際に叩き、`data-legal-retention="billing-records"`
     マーカー要素のテキストに `config` 由来の年数が現れることを Feature テストで固定する
     (静的走査だけだと「節ごと消えた」を検出できない)。
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

### 4-3b. 保持 purge の対象目録 (deny-by-default)

**結論**: 対象を散文で書かず、**型付き enum の目録**にする。母集団は `app/Models/Billing/` の
全モデル + `organizations` の課金列で **exact-fit**。分類漏れは fail。

**なぜ目録が要るか (実コードで確認した地雷)**: `TicketLedgerService::sumBalance()` /
`balance()` は **`ticket_ledger_entries.delta` の SUM を残高の唯一の真実源にしている**。
「保持期限を超えた課金取引記録を消す」を素朴に書くと**残高がその場でリセットされる**。
「消す対象」と同じ重みで「**消さない対象とその理由**」を機械に持たせなければ、この地雷は再発する。

- `BillingRetentionTarget` (削除する): case ごとに **(モデル / 基準日時列 / 対象条件 / 方式 /
  30 字以上の根拠)** を持つ。
  - 例: `StripeWebhookEvent` — 基準 `created_at` / 条件なし / 物理削除 /
    「決済事業者からの生 payload。取引の証跡は subscriptions と ledger 側にあり、
    生 payload を保持期限超で持ち続ける理由が無い」
  - 例: `BillingCheckoutSession` / `TicketCheckoutSession` — 基準 `created_at` /
    **terminal (完了 or 失効) のみ** / 物理削除
  - 例: `TicketAutoRechargeAttempt` — 基準 `created_at` / **terminal のみ** / 物理削除
- `BillingRetentionExclusion` (削除しない): 同じく 30 字以上の根拠が必須。
  - `TicketLedgerEntry` — 「`delta` の SUM が残高の唯一の真実源であり、古い行の削除は
    残高そのものを壊す。畳み込み仕訳の設計が別途要るため保持期限の対象にしない」
  - `Subscription` / `SubscriptionItem` — 「現在の契約状態の SoT であり、
    `ends_at` が過去でも再契約時の履歴照合に使う。削除方式は別途設計が要る」
  - `Plan` / `PlanPrice` / `TicketVolumePrice` — 「カタログであって取引記録ではない」
  - `TicketReservation` — 「TTL で解放される一時状態。既存の
    `billing:release-stale-reservations` が所有する」
  - `OrganizationQuota` / `TicketAutoRecharge` — 「現在の設定値であって取引記録ではない」
  - `BillingNotification` — 詳細設計で分類を確定する (送達台帳 = dedup キーの保持期間と
    取引記録の保持期間は別問題)
- **dry-run 出力形式**: 対象種別ごとの**件数のみ**。organization id・メール・金額を出さない
  (`billing:detect-orphan-billing-organizations` の報告契約と同じ水準)。
- **境界テスト**: 各 target について「閾値の 1 秒前 / 1 秒後」の 2 件を作り、
  **片方だけが消えること**を Feature テストで固定する (母集団 0 件で緑になる空振りを潰す)。

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
| B6 | 監査・通知 | `SecurityEventType` 2 case + アプリ内通知 + **予約メール通知** (`ShouldQueue`, 予約 tx 内 dispatch) | B |
| C1 | 保持年数の単一出典 | `config/legal.php` + `App\Support\Legal\BillingRetention` | C |
| C2 | 規約文面 (草案) | `resources/views/legal/privacy.blade.php` | C |
| C3 | purge の対象目録 | `BillingRetentionTarget` / `BillingRetentionExclusion` enum (§4-3b) | C |
| C4 | purge | `billing:purge-retention-expired` (dry-run 既定) + `routes/console.php` | C |
| C5 | 三者一致 gate + 目録 gate | `tests/Architecture/BillingRetentionSingleSourceTest.php` (新) / `BillingRetentionTargetInventoryTest.php` (新) | C |

既存 gate への登録 (どれも「登録しないと赤くなる」deny-by-default):
`RecentAuthRouteTest` allowlist / `ControllerAuthorizationGateTest` (selfScoped) /
`ThrottleCoverageInventoryTest` (認証面の変更系 2 本のレーン割当。**inline throttle は T125 で使用不可**) /
`MembershipWriteLockInventoryTest` (directLock 2 メソッド) / `TenantBoundaryOrderingTest` (middleware 順序) /
`SecurityEventCoverageTest` (新 case 2 つ) / `ModelDirectFetchInvariantTest` + `DirectFetchInventory`
(バッチが主キー同一性クエリを書く場合) / `JobExecutionDedupInventoryTest` (ShouldQueue を足す場合)。

## 6. 分割の判断 (1 PR にしない根拠)

**3 PR に分割し、A → B → C の順に直列で main へ入れる**。

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
  - **C 単独で完結する**: 規約の宣言 (草案) と実処理が対応づいた状態。
  - どの PR も **feature flag / 後方互換の並走を残さない** (思考原則 3)。
- **台帳への報告は C マージ後に 1 回**。A/B だけで `implemented` を主張しない (v1 は 3 点込み)。

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
- **`ticket_ledger_entries` / `subscriptions` の保持期限削除** (§4-3b の
  `BillingRetentionExclusion` に根拠つきで登録し、**目録の上で明示的に除外する**。
  「書き忘れ」ではなく「設計判断として今回はやらない」を機械が持つ形にする)。
- **組織単位の削除・組織の保持期間**。aicue に組織削除の route が無い。
- **inquiries / アクセスログの保持期間**。`inquiry_retention_days` が既にあり、別 feature。

## 9. Codex への論点 (合議で潰したい)

1. 凍結 allowlist (deny-by-default) に**載せ忘れると詰む route** を見落としていないか。
2. 執行時にブロッカーが立っていた場合の「予約維持 + report() + バナー」で、**永久凍結**が発生しないか。
3. 保持期間の三者一致を「単一出典化」で置いたことの穴 (節ごと消える / マーカーだけ残る)。
4. §4-3b の目録で、**消してはいけないものを消す** / **消すべきものが分類漏れする**穴が残っていないか。
5. 3 分割の依存順と、各 PR 単独で main が壊れないことの検証。

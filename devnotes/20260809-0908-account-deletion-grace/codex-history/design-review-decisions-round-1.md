# 対応マトリクス: design-review Round 1

## [Critical] B2: `config('account.deletion_grace_days')` の定義元が施策一覧に無い

- 判断: **対応する (指摘が正しい。実装不能な参照だった)**
- 対応内容: 施策 **B0** を新設した。
  - 新規 `config/account.php` に `deletion_grace_days => 30`。**env を使わない**
    (`config/idempotency.php` の `retention_hours` / `legal.billing_retention_years` と同じ理由 —
    環境ごとに変えてよい運用値ではなく、オーナーが確定したプロダクト判断である)。
  - 新規 `App\Support\Account\AccountDeletionGrace::days()` / `::purgeAfter(CarbonImmutable)` を
    **唯一の解決点**にする (`BillingRetention` と同じ形)。Service は config を直読しない。
  - `AccountDeletionGraceConfigTest`: 0 以下なら fail-fast / 値が 30 であること /
    config を読んでよいのが `AccountDeletionGrace` 1 箇所であること (token 走査)。
  - 日付加算は `addDaysNoOverflow` (`CarbonOverflowArithmeticGateTest`)。

## [Critical] B4: allowlist の `settings.account.destroy` が 30 日猶予を迂回できる

- 判断: **対応する (allowlist から外す)**
- 根拠: 指摘が正しい。予約中のユーザーが表明した意思は「30 日後に削除」であり、
  その状態で即時削除の口を開けておくのは**猶予が守ろうとしているもの (誤操作) をそのまま通す**。
  「今すぐ消したい」なら**取消 → 即時削除**という 2 手を踏めばよく、これは一貫した状態機械であり
  ユーザーに説明できる。allowlist が 1 本減るのも良い方向。
- 対応内容: `AccountDeletionFreezeAllowance` から `AccountDestroy` case を削除した。
  予約中に `settings.account.destroy` を叩くと `/settings` へ 302 する。
  behavioral テスト「**予約中は即時削除できない (取消してからなら削除できる)**」を追加した。
  B7 の UI も、予約中は削除ボタン群を出さずバナー (取消 + 次の一手) だけを出す契約にした。

## [Critical] B6: `via()` の再確認では「二重 dispatch で 1 通」を保証できない

- 判断: **対応する (主張の方を直す。dedup 機構は足さない)**
- 根拠: 指摘が正しい。同じ `requestedAt/purgeAfter` を持つ job が 2 つあれば `via()` は両方 `mail` を返す。
  ただし**追加の dedup 機構は入れない**:
  - `ShouldBeUnique` は **AGENTS.md ドメイン規約 11 が禁じている** (unique lock は dispatch 時に取得され
    rollback で解放されないため、業務 tx 内 dispatch と両立しない。`AutoRechargeTriggerJob` から
    撤去済みの先例がある)。
  - 送達台帳を新設するのは「今必要なものだけ作る」に反する。
  - **一回性は既に永続状態遷移が担っている** — `requestAccountDeletion()` は
    「既に予約中なら**通知を発火せず**冪等 no-op で返す」ため、二重送信では job が 1 つしか作られない。
    これは AGENTS.md ドメイン規約 6「入口の排他は best-effort であり、結果の一回性は
    永続状態遷移が担う」そのものである。
- 対応内容:
  - `via()` の役割を **「誤通知の防止」**と明記し (取消済み・再予約で値が変わった・user 不在)、
    **dedup ではない**ことを docblock に書いた。
  - テストを差し替えた: 「同一 payload job を 2 つ投入すると 1 通」は**主張しない**。
    代わりに **「予約 POST を 2 回叩いてもメールは 1 通 (サービス層の冪等 no-op)」**と
    **「予約 → 即取消 → worker 実行 → 0 通」**を固定する。
  - `JobExecutionDedupInventoryTest` の分類を `JobDedupGuarantee` = 「永続状態遷移
    (`deletion_requested_at` の存在) が一回性を担う」で登録する。
  - **保証しないもの**: 配送は at-most-once。job の**再試行**による重複送信は防がない
    (Laravel の retry は同一 job を再実行する)。これを誇張しない。

## [Critical] C1b: `SubscriptionItem` が表にあるのに enum に case が無い

- 判断: **対応する**
- 対応内容: `BillingRetentionTarget` に **`SubscriptionItem` case を追加**した。
  起算列は**テーブル修飾つき** `'subscriptions.ends_at'` で表現し、
  `clockStartColumn()` の契約を「自テーブルの列名、または `{table}.{column}` の修飾名」に拡張。
  `BillingRetentionTargetInventoryTest` の schema 照合も修飾名を解決できるようにする。
  purger は **6 本**になる (`StripeWebhookEvent` / `BillingCheckoutSession` /
  `TicketCheckoutSession` / `TicketAutoRechargeAttempt` / `SubscriptionItem` / `Subscription`。
  `TicketLedgerEntry` は C2)。**実行順は子 → 親** (`SubscriptionItem` → `Subscription`) で固定する。

## [Critical] C2b: 畳み込みの group key に `organization_id` が無い

- 判断: **対応する (指摘が正しい。組織を跨いだ残高合算という重大バグ)**
- 対応内容: group key を **`(organization_id, source, expires_at)`** にした。
  実コードで確認した粒度はこの 3 つで閉じる — `sumBalance()` は
  `where organization_id` + `source` (Purchased は `source IS NULL` も含む) +
  `expires_at IS NULL or > now` で合算しており、team/project 粒度は持たない。
  **`source IS NULL` (legacy 行) は独立した group として扱う** (Purchased へ寄せると
  `sumActiveHolds` の legacy 除外規則と意味がズレる)。
  6 種比較テストに **「組織ごとの残高が畳み込み前後で一致する (複数組織 fixture)」**を追加した。

## [Critical] C3a/C3b: Blade の config 直読と「config を読んでよいのは 1 箇所」が自己矛盾

- 判断: **対応する**
- 対応内容: Blade を **`\App\Support\Legal\BillingRetention::years()` の直接呼び出し**に変えた
  (config は読まない)。gate 検査 1 (`config('legal.billing_retention_years')` の読み手は
  `BillingRetention` のみ) と、検査 2 (`BillingRetention::years()` の呼び出し元 exact-fit 目録に
  blade を含める) が矛盾なく成立する。付録 A の文面案も修正した。

## [Warning] A2: `stripe_customer_redacted_at` だけでは監査列として弱い

- 判断: **対応する (列を 2 本にする)**
- 根拠: 妥当。「将来変わったら再設計」は監査列として弱いという指摘は正しく、
  列 1 本の追加で解消できるならその方が安い。
- 対応内容: `organizations.stripe_customer_redacted_id` (string, nullable) を同時に足し、
  **記録時点の `stripe_id` を写して保存**する。両列は同時に埋まり同時に null である
  (片方だけの状態を作らない invariant をテストで固定)。

## [Warning] B1: `'datetime'` cast だと mutable Carbon になり DTO の `CarbonImmutable` と食い違う

- 判断: **対応する**
- 対応内容: 両列の cast を **`'immutable_datetime'`** にした。
  `AccountDeletionStateDto::fromUser()` 側でも `CarbonImmutable::instance()` で明示変換し、
  cast 設定の変更に対して二重に守る。

## [Warning] B4: 凍結中に `logout` / `session.status` が遮断されないことが設計で潰れていない

- 判断: **対応する**
- 根拠: 実読で確認 — `session.status` は `routes/web.php` の
  `Route::middleware(['auth','verified'])->group(...)` の**外**に定義されており、
  `logout` は Fortify が group 外に登録する。したがって**構造的に母集団 `U` に入らない**。
  ただし「今そうである」ことと「これからもそうである」ことは別なので機械で固定する。
- 対応内容: `AccountDeletionFreezeRouteGateTest` に検査 7 を追加 —
  **`logout` / `session.status` が `U` に含まれないこと** (含まれたら fail = 誰かが
  group の中へ移したときに気づく)。behavioral 「**予約中でもログアウトできる**」も追加した。

## [Warning] B5: 抽出条件が `deletion_purge_after` のみで DTO の pending 定義とズレる

- 判断: **対応する**
- 対応内容: 抽出条件に **`whereNotNull('deletion_requested_at')`** を追加した。
  さらに **片列だけが埋まった非正規行を検出する invariant**
  (`whereNull('deletion_requested_at')->whereNotNull('deletion_purge_after')` とその逆が 0 件) を
  Command 内で数え、**0 件でなければ `report()` + `unexpectedFailures` に計上する**
  (黙って無視しない = fail-closed)。テストも追加した。

## [Warning] B8: `executeAccountDeletionRequest` は委譲なので `directLock` 登録と矛盾

- 判断: **対応する**
- 対応内容: `MembershipWriteLockInventoryTest` の分類を修正した。
  - `requestAccountDeletion` / `cancelAccountDeletion` → **`directLock`**
    (どちらも自 tx 冒頭で `lockForMembershipWrite(` を呼ぶ。既存の drift-guard がそのまま効く)
  - `executeAccountDeletionRequest` → **`delegatedToLocked`**
  - ただし既存の `delegatedToLocked` 検査は本文に `joinOrganization(` があることを見るハードコードなので、
    **`delegatedToLocked` を「メソッド名 => 必須の委譲先呼び出し」の map へ一般化する**
    (`acceptInvitation` 系 => `joinOrganization(`、`executeAccountDeletionRequest` => `deleteAccount(`)。
    既存 3 本の判定は等価のまま (テストの意味を弱めない)。

## [Warning] C1c: C1 は dry-run のみと言いながら signature に `--apply` がある

- 判断: **対応する**
- 対応内容: **C1 の signature から `--apply` を外した** (`--target` のみ)。
  `--apply` は **C2 で初めて追加する**。C1 の Command は dry-run 専用であることが
  signature そのもので表現される (「規約が宣言していない年数を先に運用へ効かせない」の機械化)。

## [Suggestion] A1: 閉包 exact-fit の cap 差分に理由コメント

- 判断: **対応する**
- 対応内容: PR-B で起点に `PurgeDeletionRequestsCommand::handle` を足すときに、
  目録差分の**理由をコメントで残す**ことを B8 の作業項目に明記した。

## [Suggestion] B7: DESIGN.md 準拠 (atom 名 / hex 直書きなし) をテスト観点に

- 判断: **対応する**
- 対応内容: B7 のテスト計画に「既存 `Alert` / `Button` / `TextLink` / `DangerZone` を再利用し、
  **hex 直書きを増やさない** (ds-purity テストの対象)」「component 階層の単方向 import を守る」を明記した。

## [Suggestion] C2b: 繰越行の `source` と unique 制約の関係

- 判断: **対応する**
- 対応内容: 実コードで確認した事実を設計へ書いた。
  - `ticket_ledger_entries` は `kind` (`TicketLedgerKind` enum cast) / `source` / `description` /
    `granted_at` / `expires_at` / `idempotency_key` / `reservation_id` /
    `stripe_checkout_session_id` / `payment_intent_id` / `purchase_amount` を持つ。
  - 繰越行は **`kind = TicketLedgerKind::CarryForward` (新 case)**、**`source` は引き継ぐ**
    (出所別残高を変えないため)、**取引追跡列 (`description` / `reservation_id` /
    `stripe_checkout_session_id` / `payment_intent_id` / `purchase_amount` / `granted_at`) は
    すべて null**。
  - `idempotency_key` は `carry_forward:{orgId}:{source}:{expiresAt}:{through}` にする。
    既存の signup unique index は **`idempotency_key LIKE 'signup_grant:%'` の部分 unique** なので
    衝突しない (migration を実読して確認)。
  - **波及変更**: `TicketLedgerKind` に case を足すため **TS 型同期と表示の分岐**を確認する
    (`resources/js/types/` の対応型 + 台帳表示 UI)。施策 C2b の波及変更へ明記した。

# Round 3: 指摘への対応と再レビュー依頼

Round 2 の [Warning] 2 件・[Suggestion] 1 件はすべて設計側を修正した (反論なし)。
対応マトリクスと、修正した施策 (6 のテスト計画 / 10 / 11) の該当箇所を再掲する。
各施策の判定と全体判定を明示すること。

---

## 対応マトリクス

# 対応マトリクス: design-review Round 2

## [Warning] 施策 10: 実行時間上限を chunk 開始時にしか見ておらず、ロック期限を跨ぎうる
- 判断: 対応する (指摘のとおり、1 chunk = 最大 100 回の外部呼び出しなので chunk 境界だけでは
  保証にならない)
- 対応内容: deadline の検査を **1 契約ごと** (`foreach` の先頭) へ移した。超過時は
  `$timedOut = true` にして `return false` でその場で走査を止める (残りの契約は照会しない)。
  テスト計画も差し替え: (i) chunk の**途中**で上限を超えたら残りが `$lookedUp` に現れず
  FAILURE、(ii) 2 chunk 目に入らない、(iii) 実行時間上限 (600 秒) < ロック有効期限 (900 秒)
  の関係を定数比較の 1 行テストで固定する (値を後から緩めたら赤くなる)。
  「1 呼び出しの timeout × chunk 件数が残り時間未満」という暗黙前提には依存しない形になった。

## [Warning] 施策 11: cast 免除が文字列一致なので `forceFill(['past_due_since' => 'datetime'])` も通る
- 判断: 対応する
- 根拠: 指摘のとおり、値が現実的でなくても「model 内の将来の直書きを検出する」という保証を
  設計どおりには満たしていない。免除は文脈 (`casts()` の中か) で決めるべきである。
- 対応内容: 免除条件を「**`casts()` メソッド本体の行範囲に入っており、かつ** cast 宣言に
  完全一致する行」に変更した。行範囲は文字列一致ではなく、既存の
  `Tests\Support\PhpReferenceScanner::tokens()` (行番号つきトークン列) を使い、
  `function casts` の `{` から対応する `}` までを波括弧の深さで求める。
  負のコントロールを 3 本に増やし、3 本目として
  「`casts()` の外にある `forceFill(['past_due_since' => 'datetime'])` は違反になる」を追加した。

## [Suggestion] 施策 6: 非露出テストの意図をコメントに残す
- 判断: 対応する
- 対応内容: テスト冒頭に「これは恒久の禁止ではなく現時点の設計判断の固定であり、露出させる
  ときは本テストの契約を変え、TypeScript の union と表示テストを同時に足す」と 1 文残すことを
  テスト計画へ明記した。

---

## 修正後の詳細設計 (施策 10 と 11 の全文、施策 6 のテスト計画)

## 施策 10: 日次の突き合わせコマンドと配線

### 変更箇所

- 新規: `app/Console/Commands/Billing/ReconcileSubscriptionStatus.php`
- 変更: `app/Services/Billing/SubscriptionService.php` (収束要否の述語を追加)
- 変更: `routes/console.php` (日次配線)

### 変更後コード (収束要否の述語)

```php
// SubscriptionService
    /**
     * 突き合わせで**書き込むべきか** (食い違いがあるか) を判定する。
     *
     * 差分が無いのに毎日 UPDATE すると、更新時刻だけが動き、webhook との競合窓も無駄に広がる。
     * 比較対象は **`applySubscriptionSnapshot` が書く列すべて**にする (status だけを見ると、
     * 更新日 `current_period_end` や解約予定 `ends_at` だけが変わった webhook を落としたとき
     * 永久に収束しない = 更新予告の真実源がずれたまま固まる)。
     *
     * 収束が要るのは次のいずれか:
     *   1. status が違う (両方向)
     *   2. stripe_price / quantity / trial_ends_at / ends_at が違う
     *   3. current_period_end が違う (**snapshot 側が null のときは比較しない** =
     *      「period 欠落 payload では既存値を維持する」書込規則と同じ扱いにする)
     *   4. past_due なのに猶予起点が NULL (打刻漏れの修復)
     *   5. Stripe 側で決済手段を観測できたのにローカルが false (**true 方向のみ**)
     *
     * **`organizations.plan_code` は比較対象にしない**: 同一トランザクションで同期されるため
     * subscriptions 行と食い違わない (未知 Price のときだけ据え置かれる = その回復は本経路の
     * 責務ではない。docs の「保証しないもの」に書く)。
     */
    public function needsSnapshotConvergence(
        Subscription $sub,
        SubscriptionSnapshot $snap,
        ?bool $hasPaymentMethod,
    ): bool {
        if ($sub->stripe_status !== $snap->status
            || $sub->stripe_price !== $snap->basePriceId
            || $sub->quantity !== $snap->baseQuantity) {
            return true;
        }
        if ($this->timesDiffer($sub->trial_ends_at, $snap->trialEndsAt)
            || $this->timesDiffer($sub->ends_at, $snap->endsAt)) {
            return true;
        }
        if ($snap->currentPeriodEnd !== null
            && $this->timesDiffer($sub->current_period_end, $snap->currentPeriodEnd)) {
            return true;
        }
        if ($snap->status === 'past_due' && $sub->past_due_since === null) {
            return true;
        }

        return $hasPaymentMethod === true && ! $sub->has_payment_method;
    }

    /** 日時の差分判定 (null 同士は一致。片方だけ null は差分)。秒精度で比較する。 */
    private function timesDiffer(?DateTimeInterface $local, ?CarbonImmutable $remote): bool
    {
        if ($local === null || $remote === null) {
            return $local !== $remote;
        }

        return $local->getTimestamp() !== $remote->getTimestamp();
    }
```

### 変更後コード (コマンド)

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

/**
 * Stripe の契約状態とローカルを突き合わせる (日次。AG-035 (6))。
 *
 * webhook は「最大 3 日ずれうる」と Stripe 自身が明記しており、1 通落とすとローカルの
 * stripe_status は古いまま固まる。本コマンドは **Stripe を真実として** 食い違いを収束させる
 * 唯一の経路である。
 *
 * **責務の境界** (既存 2 本と重ねない):
 *  - billing:reconcile-auto-recharge (15 分) = チケット自動購入の未決金の回収 (台帳を書く)
 *  - billing:reconcile-schedules (日次)      = 予約 (Schedule) の作りかけの修復 (schedule 列を書く)
 *  - 本コマンド (日次)                        = 契約状態そのもの (applySubscriptionSnapshot の担当列)
 *
 * **金銭は動かさない** (チケットの付与・返金には触れない)。
 * **列を直接書かない** (書込は SubscriptionService の 2 メソッド経由のみ)。
 *
 * 終了コード: 失敗 1 件以上 / ロック取得失敗 / 実行時間上限超過 → FAILURE。
 * 未確認 (404) は状態を変えないので SUCCESS だが、**件数が 0 でなければ必ず report する**。
 *
 * **監視対象**: 本コマンドの終了コードと report()。
 */
final class ReconcileSubscriptionStatus extends Command
{
    protected $signature = 'billing:reconcile-subscription-status';

    protected $description = 'Stripe の契約状態とローカルの契約状態を突き合わせて収束させる (daily)';

    /** 排他ロックの有効期限 (秒)。実行時間上限より必ず長くする。 */
    private const int LOCK_SECONDS = 900;

    /** 走査の実行時間上限 (秒)。chunk の切れ目で超過を検査して打ち切る。 */
    private const int TIME_BUDGET_SECONDS = 600;

    /** 1 chunk の件数。 */
    private const int CHUNK_SIZE = 100;

    /** report に載せる organization id の上限 (超過分は件数だけ書く)。 */
    private const int REPORTED_ID_LIMIT = 50;

    public function handle(StripeGatewayInterface $gateway, SubscriptionService $subscriptions): int
    {
        try {
            /** @var int $exitCode */
            $exitCode = Cache::lock('billing:reconcile-subscription-status', self::LOCK_SECONDS)
                ->block(5, fn (): int => $this->reconcile($gateway, $subscriptions));

            return $exitCode;
        } catch (LockTimeoutException $e) {
            $this->error('別プロセスが billing:reconcile-subscription-status を実行中。exit 1');
            Log::warning('ReconcileSubscriptionStatus: lock timeout');

            return self::FAILURE;
        }
    }
}
```

走査本体 (`reconcile`) の骨子:

```php
        $deadline = CarbonImmutable::now()->addSeconds(self::TIME_BUDGET_SECONDS);
        $checked = $converged = $missing = $failed = 0;
        $missingIds = $failedIds = [];
        $timedOut = false;

        Subscription::query()
            ->where('type', 'default')
            // Stripe 側で終了は不可逆なので、ローカルが終了扱いの行は照会しない
            // (照会対象が単調増加しない)。**帰結**: 誤って終了と書かれた行は自動回復しない。
            ->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $subs) use (...): bool {

                foreach ($subs as $sub) {
                    // **1 件ごとに**残り時間を見る。chunk 開始時だけの検査では、1 chunk が
                    // 最大 100 回の外部呼び出しを含むため、遅い応答が続くと実行時間上限どころか
                    // ロックの有効期限まで跨ぎ、2 本目のプロセスが並走しうる。
                    if (CarbonImmutable::now()->greaterThan($deadline)) {
                        $timedOut = true;

                        return false; // chunk の途中でも即座に止める (残りは照会しない)
                    }

                    $checked++;
                    try {
                        $remote = $gateway->retrieveSubscriptionState($sub->stripe_id);
                    } catch (SubscriptionLookupFailedException $e) {
                        $failed++;
                        $failedIds[] = $sub->organization_id;
                        // 例外 message は載せない (外部生成の可変文字列)。クラス名だけ。
                        // previous は無いことがある (id 欠落など gateway 自身が投げる場合) ため
                        // null 安全に落とす。
                        $previous = $e->getPrevious();
                        Log::warning('reconcile-subscription-status: lookup failed', [
                            'organization_id' => $sub->organization_id,
                            'error_class' => $previous !== null ? $previous::class : $e::class,
                        ]);

                        continue;
                    }

                    if ($remote === null) {
                        $missing++;
                        $missingIds[] = $sub->organization_id;

                        continue; // 状態は変えない
                    }

                    if (! $subscriptions->needsSnapshotConvergence($sub, $remote->snapshot, $remote->hasPaymentMethod)) {
                        continue;
                    }

                    $organization = $sub->organization;
                    Assert::isInstanceOf($organization, Organization::class);

                    $subscriptions->applySubscriptionSnapshot(
                        $organization,
                        $remote->snapshot,
                        terminated: $remote->snapshot->status === 'canceled',
                    );
                    if ($remote->hasPaymentMethod === true) {
                        $subscriptions->recordPaymentMethodSnapshot($sub, true);
                    }
                    $converged++;
                }

                return true;
            });
```

集約報告と終了コード:

```php
        $this->info(sprintf(
            'reconcile-subscription-status: checked=%d converged=%d missing=%d failed=%d',
            $checked, $converged, $missing, $failed,
        ));

        // 1 実行につき 1 回だけ report する (件数 + organization id のみ = PII を載せない)。
        if ($missing > 0 || $failed > 0) {
            report(new RuntimeException(sprintf(
                'Stripe 契約の突き合わせ未完了: missing=%d ids=%s / failed=%d ids=%s',
                $missing, $this->formatIds($missingIds), $failed, $this->formatIds($failedIds),
            )));
        }

        return ($failed > 0 || $timedOut) ? self::FAILURE : self::SUCCESS;
```

`routes/console.php` への配線:

```php
/*
|--------------------------------------------------------------------------
| Stripe 契約状態の突き合わせ (AG-035 (6))
|--------------------------------------------------------------------------
| webhook 欠落でローカルの契約状態が固まると、支払い失敗の遮断も復旧も起きない。
| 日次で Stripe を真実として収束させる。**チケット (金銭) には触れない**。
|
| 既存の 2 本とは書く列が重ならない (相乗りさせない):
|   - billing:reconcile-auto-recharge (15 分) = チケット自動購入の未決金
|   - billing:reconcile-schedules (日次)      = 予約 (Schedule) の作りかけ
|
| **監視対象**: 終了コードと report() (未確認・失敗はここにしか出ない)。
*/
Schedule::command('billing:reconcile-subscription-status')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(static fn () => report(new RuntimeException(
        'billing:reconcile-subscription-status 失敗 — Stripe と契約状態が突き合わせられていない',
    )));
```

### PHPStan適合チェック

- [x] `chunkById` のクロージャは `Collection<int, Subscription>` を型注釈
- [x] `Cache::lock()->block()` の `mixed` を `@var int` + 戻り型で絞る (既存 2 箇所と同作法)
- [x] `$sub->organization` の narrowing に `Assert::isInstanceOf`
- [x] `report()` に渡すのは例外オブジェクト (文字列を渡さない)

### テスト計画

- [ ] 新規 `tests/Feature/Billing/ReconcileSubscriptionStatusTest.php`
      (`StripeGatewayInterface` に `tests/Support/FakeStripeGateway` を bind して駆動):
  - [ ] **状態の収束**: ローカル `active` / remote `past_due` → ローカルが `past_due` になり
        `past_due_since` が打たれる
  - [ ] **逆向きの収束**: ローカル `past_due` / remote `active` → `active` + 起点が NULL に戻る
  - [ ] **打刻漏れの修復**: ローカル `past_due` + 起点 NULL / remote も `past_due` →
        起点が観測時刻で埋まる
  - [ ] **差分なしでは書かない**: すべて一致 → `updated_at` が変わらない (無駄な UPDATE をしない)
  - [ ] **status 以外の差分も収束する**: status は同じで `current_period_end` /
        `ends_at` / `trial_ends_at` / `stripe_price` / `quantity` だけが違う場合も収束する
        (更新予告の真実源がずれたまま固まらない)
  - [ ] **period 欠落は既存値を維持**: snapshot の `currentPeriodEnd` が null のときは
        差分と見なさず、ローカルの `current_period_end` を消さない
  - [ ] **PM の三値**: remote `hasPaymentMethod=null` ではローカル false のまま /
        `true` では true になる / 一度 true になった行は `null` 観測で false に戻らない
        (`=== true` の厳密比較。truthy 判定でないこと)
  - [ ] **未確認 (404)**: remote が null → 状態は 1 列も変わらず、`missing` として report される /
        終了コードは SUCCESS
  - [ ] **失敗**: `SubscriptionLookupFailedException` → 走査は次の行へ進み、
        report + 終了コード FAILURE
  - [ ] **report は 1 実行 1 回**・内容は件数と organization id のみ (PII なし)。
        `DetectOrphanBillingOrganizationsCommandTest` と同じ handler spy を使う
  - [ ] **終了済みは照会しない**: ローカル `canceled` / `incomplete_expired` の行は
        `FakeStripeGateway::$lookedUp` に現れない
  - [ ] **金銭を動かさない**: 収束の前後で `ticket_ledger_entries` の件数が変わらない
  - [ ] **多重起動**: ロック保持中の実行は FAILURE で即終了する
  - [ ] **配線**: `Schedule` の登録に `billing:reconcile-subscription-status` が daily で在り、
        `onOneServer` / `withoutOverlapping` が付いている (`AutoRechargeReconcileTest` と同型)
- [ ] **実行時間上限**は 2 ケースを固定する (`travelTo` で時計を進める fake gateway を使う):
  - [ ] chunk の**途中**で上限を超えたら、残りの契約を**照会せず** (`$lookedUp` に現れない)
        FAILURE で終わる
  - [ ] 2 chunk 目に入らないこと (chunk 境界でも止まる)
  - [ ] 上限 (600 秒) < ロックの有効期限 (900 秒) の関係が定数として保たれていること
        (定数比較の 1 行テスト。値を後から緩めたら赤くなる)

### リスク

- 契約数に比例して Stripe API 呼び出しが増える (1 契約 1 回)。日次かつ chunk 分割で、
  現在の契約数 (数十規模) では実行時間上限に届かない。上限に触れ始めたら
  「前回確認時刻の古い順に上限件数だけ処理する」形へ変えるが、**今は作らない**
  (今必要なものだけ作る)。触れたことは終了コードと report で分かる。
- ローカルが終了扱い (`canceled` / `incomplete_expired`) の行は照会対象外なので、
  誤って終了と書かれた行は自動回復しない (**保証しない**ことを docs に明記)。

---

## 施策 11: 書込単一化の Architecture テスト

### 変更箇所

- 新規: `tests/Architecture/PastDueSinceWriteInvariantTest.php`

### 変更後コード

```php
<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| past_due_since 書き込み経路の invariant
|--------------------------------------------------------------------------
|
| `subscriptions.past_due_since` は猶予の起点 = 遮断の期日を決める状態キーのため、
| 書き込み (array key 代入 / プロパティ代入) は SubscriptionService に閉じる。
| 読み取り (`->past_due_since` の比較・null 検査) は対象外。
|
| **保証範囲を誇張しない**: 走査根は app/ のみで、database/migrations/ の backfill と
| 生 SQL は母集団に入らない (移行は 1 本きりで、手動 SQL の禁止は runbook が担う)。
*/

test('app/ 内の past_due_since 書き込みは SubscriptionService に閉じる', function (): void {
    $allowlist = [
        'app/Services/Billing/SubscriptionService.php',
    ];

    // 書き込みパターン: array key 代入 と プロパティ代入 (=== / !== 比較は除外)。
    $finder = Finder::create()
        ->in(base_path('app'))
        ->files()
        ->name('*.php')
        ->contains('/([\'"])past_due_since\1\s*=>|->past_due_since\s*=[^=]/');

    $violations = [];
    foreach ($finder as $file) {
        $relative = str_replace(base_path().'/', '', (string) $file->getRealPath());
        if (in_array($relative, $allowlist, true)) {
            continue;
        }
        // **型宣言 (casts) は書き込みではない**: Subscription model の
        // `'past_due_since' => 'datetime',` だけは許す。それ以外の array key 代入は違反。
        foreach (castOnlyViolations($file->getRealPath()) as $line) {
            $violations[] = $relative.':'.$line;
        }
    }

    expect($violations)->toBe([], 'past_due_since の書き込みは SubscriptionService 経由に限定してください: '.implode(', ', $violations));
});
```

`castOnlyViolations()` は当該ファイルの `past_due_since` を含む行を 1 行ずつ見て、
**次のいずれにも当てはまらない行**を違反として返す:

- `@property` 等の docblock 行 (行頭が `*`)
- **`casts()` メソッドの本体の行範囲に入っており、かつ** cast 宣言そのもの
  (`'past_due_since' => 'datetime',` に完全一致) である行

行範囲は文字列一致ではなく **トークンで確定する**: 既存の
`Tests\Support\PhpReferenceScanner::tokens()` (行番号つきの正規化トークン列) を使い、
`function casts` の本体の `{` から対応する `}` までの行範囲を波括弧の深さで求める。
**文脈を見ずに「文字列が一致したら免除」にしない** — それだと
`forceFill(['past_due_since' => 'datetime'])` のような書込も通ってしまい、
「model 内の将来の直書きを検出する」という保証を満たさない (Codex Round 2 の [Warning])。

これにより「model の `casts()` は通るが、model 内の `forceFill(['past_due_since' => …])` は
落ちる」ようにする (Codex Round 1 の [Critical]: 汎用 array key 検出だと施策 1 の cast を
誤検出して常に赤くなる / model をまるごと allowlist に入れると将来の直書きを見逃す)。

負のコントロール (走査・免除が空振りしていないこと) を 3 本置く:

```php
test('負のコントロール: 単一 writer 自身は書き込みとして検出される', function (): void {
    $finder = Finder::create()
        ->in(base_path('app/Services/Billing'))
        ->files()
        ->name('SubscriptionService.php')
        ->contains('/([\'"])past_due_since\1\s*=>/');

    expect(iterator_count($finder))->toBe(1);
});

test('負のコントロール: cast 以外の array key 代入は違反として拾われる', function (): void {
    // 一時ファイルに `'past_due_since' => CarbonImmutable::now(),` を書いて判定関数へ通し、
    // 1 件返ることを確認する (判定関数が常に空配列を返す実装に退化していないこと)。
});

test('負のコントロール: casts() の外にある cast と同じ文字列は免除されない', function (): void {
    // `forceFill(['past_due_since' => 'datetime'])` を **casts() の外**に持つ一時ファイルを
    // 判定関数へ通し、違反として 1 件返ることを確認する
    // (免除が「文字列一致」ではなく「casts() の行範囲」で効いていること)。
});
```

### テスト計画

- 本施策自体がテストである。**負のコントロールを 2 本**必ず置く
  (正規表現や判定関数が空振りしていると検査が常に緑になるため。既存の
  `ExternalClientTimeoutInventoryTest` が同じ考え方を持つ)

### リスク

- ファイル粒度の検査であり、`SubscriptionService` 内でメソッドが増えても検出しない
  (メソッド単位の fail-first は施策 5 の behavioral テストが担う)。

---

## 施策 12: ドキュメント

### 変更箇所

- `docs/architecture.md`: 「支払い失敗の猶予と契約状態の突き合わせ」節を新設
- `docs/billing-gate-inversion-runbook.md`: 移行手順の節を追記

### 書く内容 (要点)

- 猶予の定義と唯一の正本 (`PaymentGracePolicy`)、起点は**観測時刻**であること
- **チケット残高切れの猶予は 0** (予約時点で即拒否) — これは未実装ではなく決定である
- 支払い未解決 (`PastDue` / `Unpaid`) の間だけ、無料枠への読み替えと新規契約を禁じること。
  契約終了後の債権回収は課金事業者側の仕事として entitlement と切り離すこと
- 突き合わせコマンドの責務境界 (既存 2 本との表) と**監視対象** (終了コードと report)
- **保証しないもの**: 実際の失敗時刻ではなく観測時刻であること / 未確認・失敗が続く契約では
  猶予も遮断も動かないこと / webhook との観測順序は保証しないこと (収束は最終的) /
  ローカルが終了扱いの行は照会対象外であること / PM は true 方向のみ修復すること
- runbook: backfill は migration の中だけで完結し、**手動 SQL / tinker で `past_due_since` を
  書かない**こと。デプロイ直後は全既存 past_due 行の猶予がデプロイ時刻起点になること

### テスト計画

- [ ] ドキュメントに機械検査は付けない (既存の `docs/` も同様)。ただし施策 2 のコメントと
      `docs/architecture.md` の日数記述が食い違わないよう、**日数の数値は config を正本**と書き、
      docs には既定値の出典として `config/billing.php` を参照させる

---

## 実装モード

### 施策 6 のテスト計画 (抜粋)

### テスト計画

- [ ] `tests/Feature/Billing/SubscriptionEntitlementTest.php` に追加:
  - [ ] `past_due` + PM 有 + 起点が 13 日前 → entitled (猶予中は利用継続)
  - [ ] `past_due` + PM 有 + 起点が **ちょうど 14 日前** → entitled (境界は継続)
  - [ ] `past_due` + PM 有 + 起点が 15 日前 → denied / `PaymentGraceExpired`
  - [ ] `past_due` + PM 有 + 起点 NULL → entitled (起点不明は遮断しない)
  - [ ] `past_due` + trial 終了 + PM 無 + 起点 15 日前 → `TrialEndedWithoutPaymentMethod`
        (**既存理由の優先順位が変わらない**ことの固定)
  - [ ] `active` + 起点 15 日前 (異常データ) → entitled (猶予は PastDue 限定)
- [ ] `tests/Feature/Billing/BillingAccessStateTest.php`: 猶予切れ org の state が
      `ExpiredCheckout` になる
- [ ] `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`:
  - [ ] 猶予切れ org の業務 route が redirect される / XHR は 402 + 既存 `BLOCKED_MESSAGE`
  - [ ] 猶予中 org は通る (cohort D の既存挙動が猶予中は維持される)
- [ ] 新規 `tests/Architecture/EntitlementReasonExposureTest.php` (**非露出の固定**。
      テスト冒頭コメントに「これは恒久の禁止ではなく現時点の設計判断の固定である。
      露出させるときは本テストの契約を変え、TypeScript の union と表示テストを同時に足す」と
      1 文書き残す = 機械検査を単なる禁止規則と誤解させない):
  - [ ] `app/Http/` と `resources/js/` に `EntitlementDeniedReason` /
        `SubscriptionEntitlementDto` の参照が 0 件であること (Finder 走査)。
        露出させたくなったときに赤くなり、TypeScript union・表示テストの追加を促す
  - [ ] 負のコントロール: `app/Services/Billing/` では検出される (走査が空振りしていない)

### リスク

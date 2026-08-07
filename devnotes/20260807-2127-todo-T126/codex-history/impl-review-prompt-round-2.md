# impl-review Round 2 (T126 外部 SDK の client timeout を pin)

Round 1 の指摘 ([Critical] 1 件 / [Warning] 1 件 / [Suggestion] 1 件) への対応と、
その後に `main` を取り込んだ結果を報告する。**判定を再度出してほしい**
(APPROVED か CHANGES_REQUESTED か。CHANGES_REQUESTED なら残る [Critical] を具体的に)。

## 0. 前提の変化: `main` の取り込み

Round 1 のレビュー後、中断中に進んでいた `main` (T133 キャッシュ素データ gate / T125 inline throttle
分離がマージ済み) を本ブランチへ取り込んだ。衝突は `app/Providers/AppServiceProvider.php` の
**`use` 文 1 行のみ** (`App\Support\ExternalClientTimeouts` と main 側の `App\Support\Http\RateLimiterKeys`)
で、両方を残して解消した。T126 の施策そのものへの影響は無く、取り込み後に

- 全 22 件の mutation を**再実行**して 22/22 RED を再確認
- `composer test` (3805 tests / 2 skipped) / `composer phpstan` / lint / build を再実行して全 green

を確認済みである (実測値は末尾の「テスト結果」)。

## 1. 対応マトリクス

### [Critical] 追加免除 (`DefaultDiskWithoutAwsClient` / `InjectedPinnedControlClient`) の適用条件が gate に検証されていない

**判断: 受諾 (対応済み)**。指摘のとおり「enum の docblock に書いた約束」は誰も検査しないので、
条件から外れたコードが免除の陰に隠れて gate が**偽陰性**になる。2 方向で機械検査へ寄せた。

1. **免除理由ごとの前提表を新設し、走査結果と突き合わせる** (下記 B / C)。
   `forbidden` = 「この理由を名乗るなら検出されてはいけない走査規則」、
   `required` = 「検出されなければならない規則」。例:
   - `default_disk_without_aws_client` は `disk_call` / `get_client_call` / `new_external_object` /
     `stripe_global_setter` の**いずれも持ってはならない** (= `disk(...)` を呼んだ時点でこの理由は名乗れない)
   - `injected_pinned_control_client` は上に加えて `new_external_object` を禁じる
     (自分で構築するなら pin の責任はこちらに移るため、別の理由になる)
   - `pinned_control_client_construction` は逆に `new_external_object` を**要求**する
   - 表そのものの空振り防止として「enum の全 case が前提表にキーを持つこと」と
     「検査した免除 entry が 1 件以上あること」も検査する
2. **「既定 disk が `s3` を指すと pin を迂回できる」を disk 名ではなく driver 単位で塞ぐ** (下記 D)。
   `filesystems.disks` を走査し、**`driver === 's3'` の disk 全件**が
   `ExternalClientTimeouts` の pin 値と一致する `http` / `retries` を宣言していることを要求する
   (`driver=s3` が 1 件も無ければ fail = 空振り防止つき)。
   `FILESYSTEM_DISK` が何を指しても、到達先が s3 driver である限り待ちは有界になる。

**保証範囲は誇張しない** — enum の docblock にも書いたとおり、既定 disk が `s3` を指した場合に
継承するのは**データ系の帯 (timeout 900s)** である。「有界だが短くはない」。
「既定 disk 経由でも制御系の帯になる」とは主張していない。

mutation で実効性を確認した (末尾の表 M21 / M22):
- M21: 免除 `DefaultDiskWithoutAwsClient` のクラス (`SopTextExtractor`) の `Storage::get()` を
  `Storage::disk('s3')->get()` に変える → `到達境界: 免除理由の適用条件が走査結果と矛盾しない` が RED
- M22: `config/filesystems.php` に pin 無しの `driver=s3` disk を 1 件足す →
  `AWS config: driver=s3 の disk はすべて http / retries を宣言する` が RED

### [Warning] 「customer 新規」経路が dataset から落ちている

**判断: 受諾 (対応済み)**。`stripeBudgetPendingAttempt()` に `$withStripeCustomer` を足し、
`stripe_id` が **null** の組織で `createOrGetStripeCustomer` の **create 側**へ入る dataset を追加した (下記 E)。

さらに、この 2 経路は**呼び出し回数がどちらも 5 回**で、回数一致だけでは
「分岐を取り違えていても green」になりうる。そこで既に診断用に持っていた
`CountingStripeHttpClient::$requestedUrls` を**検査へ昇格**させ、
`expectedFirstRequest` (`post https://api.stripe.com/v1/customers` か
`get https://api.stripe.com/v1/customers/cus_gate` か) で**どちらの分岐へ入ったか**を固定した。

### [Suggestion] 提示 diff に `mprocs.yaml` の hunk が無い

**判断: 事実確認のみ (コード変更なし)**。ご指摘のとおり「提示 diff の欠落」で、
Round 1 で Codex へ渡した差分の生成対象ディレクトリに `mprocs.yaml` が含まれていなかっただけである。
実装には含まれており、現在のコミット対象の diff は:

```diff
   queue:
-    shell: "php artisan queue:listen database --tries=1 --timeout=540"
+    shell: "php artisan queue:listen database --tries=1 --timeout=300"
```

値の一致は `時間予算: mprocs の database worker --timeout が定数と一致する` が機械固定しており
(mutation M12 で `--timeout=360` に変えると新旧 2 本の gate が RED になることも確認済み)、
docs / config / worker 定義の 3 者が割れない。

## 2. Round 1 以降に変更したコード (全文)

### A. `app/Enums/Storage/ExternalClientBoundaryExemption.php` (該当 case の docblock を改訂)
```php
    /**
     * pin 済みの制御系 AWS クライアントを **DI で受け取って使うだけ**の消費点。
     *
     * 適用条件: クライアントを自分で構築せず (`new` しない)、
     * `PinnedControlClientConstruction` の構築点が渡したインスタンスをそのまま使うこと。
     * 待ち上限は構築点の pin が決めるため、この層に per-command 上書きは要らない。
     */
    case InjectedPinnedControlClient = 'injected_pinned_control_client';

    /**
     * `Storage` facade を**既定 disk のみ**で使い、AWS クライアントを自分で解決しない。
     *
     * 適用条件: `disk(...)` / `getClient()` / `new Aws\…` のいずれも持たないこと
     * (この 3 つは目録 gate が走査結果で機械検査する)。
     *
     * ★**保証を誇張しない**: 既定 disk は `FILESYSTEM_DISK` で決まるため、
     *   運用が `s3` を指せばこの層も S3 へ到達する。そのときの待ちは
     *   **`driver=s3` の disk 全件に強制した pin (データ系の帯)** を継承する
     *   = 有界ではあるが長い。無制限にはならない、が「S3 へ行かない」わけでもない。
     */
    case DefaultDiskWithoutAwsClient = 'default_disk_without_aws_client';

```

### B. `tests/Architecture/ExternalClientTimeoutInventoryTest.php` — 免除の適用条件表 (新設)
```php
/**
 * 免除理由ごとの**適用条件**を走査結果で機械検査するための表 (impl-review Round 1 反映)。
 *
 * ★免除理由の適用条件が「enum の docblock に書いた約束」だけだと、gate は**偽陰性**を作る
 *   (書いてあるが誰も検査しない)。ここで「その理由を名乗るなら検出されてはいけない規則 /
 *   検出されなければならない規則」を宣言し、走査結果と突き合わせる。
 * ★`forbidden` は「この rule の site があったら免除の前提が崩れている」、
 *   `required` は「この rule の site が無かったら免除の前提が崩れている」。
 *
 * @var array<string, array{forbidden: list<string>, required: list<string>}>
 */
const EXTERNAL_CLIENT_EXEMPTION_PRECONDITIONS = [
    // 値オブジェクトだけを扱う: disk も client も取らない (`new Aws\Sns\MessageValidator` は許す
    // — 送信しない検証器であり、これを禁じると「値オブジェクトのみ」の意味が壊れる)
    'aws_value_object_only' => [
        'forbidden' => ['disk_call', 'get_client_call', 'stripe_global_setter'],
        'required' => [],
    ],
    // 構築点: `new` で AWS クライアントを組み立てていること (組み立てていないなら別の理由である)
    'pinned_control_client_construction' => [
        'forbidden' => ['disk_call', 'get_client_call', 'stripe_global_setter'],
        'required' => ['new_external_object'],
    ],
    // 消費点: **自分では構築しない** (構築するなら pin の責任はこちらに移る)
    'injected_pinned_control_client' => [
        'forbidden' => ['disk_call', 'get_client_call', 'stripe_global_setter', 'new_external_object'],
        'required' => [],
    ],
    // 既定 disk のみ: disk を**選ばない** (`disk(...)` を呼んだ時点でこの理由は名乗れない)
    'default_disk_without_aws_client' => [
        'forbidden' => ['disk_call', 'get_client_call', 'new_external_object', 'stripe_global_setter'],
        'required' => [],
    ],
    // Stripe 大域 pin: setter を持つことが存在理由。AWS 側には触れない
    'global_sdk_timeout_pin' => [
        'forbidden' => ['disk_call', 'get_client_call', 'new_external_object'],
        'required' => ['stripe_global_setter'],
    ],
    // fake: 本番の外部到達を持たない = Stripe 大域状態も触らない
    'test_double_without_external_egress' => [
        'forbidden' => ['stripe_global_setter'],
        'required' => [],
    ],
];
```

### C. 同ファイル — 検査「到達境界: 免除理由の適用条件が走査結果と矛盾しない」(新設)
```php
test('到達境界: 免除理由の適用条件が走査結果と矛盾しない', function (): void {
    // ★免除の適用条件を「enum の docblock に書いた約束」で終わらせない (impl-review Round 1)。
    //   約束を機械検査しないと、条件から外れたコードが免除に隠れて gate が偽陰性になる。
    $sitesByClass = [];
    foreach (externalClientBoundarySites() as $site) {
        if ($site['class'] === null) {
            continue;
        }
        $sitesByClass[$site['class']][] = $site;
    }

    // 前提表そのものの空振り防止: 使う免除理由はすべて前提が宣言されている
    foreach (ExternalClientBoundaryExemption::cases() as $case) {
        expect(EXTERNAL_CLIENT_EXEMPTION_PRECONDITIONS)->toHaveKey($case->value);
    }

    $violations = [];
    $checked = 0;
    foreach (EXTERNAL_CLIENT_BOUNDARY_INVENTORY as $class => $entry) {
        if ($entry['surface'] !== 'exempt') {
            continue;
        }
        $preconditions = EXTERNAL_CLIENT_EXEMPTION_PRECONDITIONS[$entry['reason']->value];
        $rules = array_column($sitesByClass[$class] ?? [], 'rule');
        $checked++;

        foreach ($preconditions['forbidden'] as $rule) {
            foreach ($sitesByClass[$class] ?? [] as $site) {
                if ($site['rule'] === $rule) {
                    $violations[] = "{$class}: 免除理由 {$entry['reason']->value} は [{$rule}] を許さない — "
                        .ExternalClientBoundaryScanner::describe($site);
                }
            }
        }
        foreach ($preconditions['required'] as $rule) {
            if (! in_array($rule, $rules, true)) {
                $violations[] = "{$class}: 免除理由 {$entry['reason']->value} は [{$rule}] を必要とするが検出されない";
            }
        }
    }

    expect($checked)->toBeGreaterThan(0, '免除 entry が 1 件も検査されていません');
    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});
```

### D. 同ファイル — 検査「AWS config: driver=s3 の disk はすべて http / retries を宣言する」(新設)
```php
test('AWS config: driver=s3 の disk はすべて http / retries を宣言する', function (): void {
    // ★**「既定 disk が s3 に設定されたら pin を迂回できる」を構造的に潰す** (impl-review Round 1)。
    //   `Storage` facade を既定 disk のまま使うクラス (免除 DefaultDiskWithoutAwsClient) は
    //   `FILESYSTEM_DISK` 次第で s3 driver へ到達しうる。したがって「特定の disk 名」ではなく
    //   **driver=s3 の disk 全件**が pin を宣言していることを要求する。
    //   これで到達先がどの s3 disk であっても待ちは有界になる (無制限にはならない)。
    /** @var array<string, mixed> $disks */
    $disks = config()->array('filesystems.disks');

    $s3Disks = [];
    $violations = [];
    foreach ($disks as $name => $disk) {
        if (! is_array($disk) || ($disk['driver'] ?? null) !== 's3') {
            continue;
        }
        $s3Disks[] = $name;

        if (($disk['http'] ?? null) !== [
            'connect_timeout' => ExternalClientTimeouts::AWS_S3_CONNECT_TIMEOUT_SECONDS,
            'timeout' => ExternalClientTimeouts::AWS_S3_TIMEOUT_SECONDS,
        ]) {
            $violations[] = "filesystems.disks.{$name}: http が ExternalClientTimeouts の pin 値と一致しません";
        }
        if (($disk['retries'] ?? null) !== ['mode' => 'legacy', 'max_attempts' => ExternalClientTimeouts::AWS_MAX_ATTEMPTS]) {
            $violations[] = "filesystems.disks.{$name}: retries が ExternalClientTimeouts の pin 値と一致しません";
        }
    }

    expect($s3Disks)->not->toBeEmpty('driver=s3 の disk が 1 件もありません (走査条件が壊れている疑い)');
    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});
```
### E. `tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php` — customer 新規経路の追加と分岐固定
```php
/** 有効化済みの auto-recharge 設定を持つ組織で pending attempt を作る。 */
function stripeBudgetPendingAttempt(bool $withInvoice = false, bool $withStripeCustomer = true): TicketAutoRechargeAttempt
{
    [$organization] = createOrganizationWithOwner();
    // stripe_id 有無で createOrGetStripeCustomer の retrieve / create 分岐が変わる
    $organization->forceFill(['stripe_id' => $withStripeCustomer ? 'cus_gate' : null])->save();
    TicketAutoRecharge::factory()->enabled()->create(['organization_id' => $organization->getKey()]);

    $factory = TicketAutoRechargeAttempt::factory();
    if ($withInvoice) {
        $factory = $factory->withInvoice('in_gate');
    }

    return $factory->create([
        'organization_id' => $organization->getKey(),
        'quantity' => 45,
        'unit_amount' => 80,
    ]);
}

/**
 * 代表経路のデータセット。**なぜこれが分岐集合を代表するか**をキー名に残す。
 *
 * 各行: [応答列を返す closure, invoice 既存か, Stripe customer 既存か,
 *        期待呼び出し回数, 期待される最初のリクエスト, 期待 terminal status]
 * ★呼び出し回数だけでなく **経路が意図どおり終端したこと**も検証する
 *   (途中で早期 return して「呼び出しが少ないから green」になるのを防ぐ)。
 */
dataset('auto-recharge の外部呼び出し経路', [
    '成功 (customer 既存 = retrieve → invoice → item → finalize → pay の基準経路)' => [
        fn (): array => [
            stripeBudgetCustomer(),
            stripeBudgetInvoice('draft'),
            stripeBudgetInvoiceItem(),
            stripeBudgetInvoice('open'),
            stripeBudgetInvoice('paid', 3_600, 3_600),
        ],
        false,
        true,
        5,
        'get https://api.stripe.com/v1/customers/cus_gate',
        AutoRechargeAttemptStatus::Paid,
    ],
    '成功 (customer 新規 = createOrGetStripeCustomer の create 側へ入る経路)' => [
        fn (): array => [
            stripeBudgetCustomer(),
            stripeBudgetInvoice('draft'),
            stripeBudgetInvoiceItem(),
            stripeBudgetInvoice('open'),
            stripeBudgetInvoice('paid', 3_600, 3_600),
        ],
        false,
        false,
        5,
        'post https://api.stripe.com/v1/customers',
        AutoRechargeAttemptStatus::Paid,
    ],
    'カード拒否 → invoice void (後始末の追加呼び出しが載る最長経路)' => [
        fn (): array => [
            stripeBudgetCustomer(),
            stripeBudgetInvoice('draft'),
            stripeBudgetInvoiceItem(),
            stripeBudgetInvoice('open'),
            stripeBudgetCardDeclined(),
            stripeBudgetInvoice('open'),   // terminateInvoice: retrieve
            stripeBudgetInvoice('void'),   // terminateInvoice: voidInvoice
        ],
        false,
        true,
        7,
        'get https://api.stripe.com/v1/customers/cus_gate',
        AutoRechargeAttemptStatus::Failed,
    ],
    '既存 invoice の再利用 (finalize 済みで InvalidRequest → pay へ進む経路)' => [
        fn (): array => [
            stripeBudgetAlreadyFinalized(),
            stripeBudgetInvoice('paid', 3_600, 3_600),
        ],
        true,
        true,
        2,
        'post https://api.stripe.com/v1/invoices/in_gate/finalize',
        AutoRechargeAttemptStatus::Paid,
    ],
]);

test(
    '既定接続の Stripe 呼び出しは予算を超えない',
    /**
     * @param  callable(): list<array{status: int, body: string}>  $responses
     */
    function (
        callable $responses,
        bool $withInvoice,
        bool $withStripeCustomer,
        int $expectedCalls,
        string $expectedFirstRequest,
        AutoRechargeAttemptStatus $expectedStatus,
    ): void {
        // ApiRequestor::httpClient() は遅延生成のため null にならない (vendor 実査)。
        $original = ApiRequestor::httpClient();
        $counting = new CountingStripeHttpClient($responses());

        try {
            ApiRequestor::setHttpClient($counting);
            // 実 Cashier クライアントを構築するため API キーが要る (送信は fake client が受ける)。
            config(['cashier.secret' => 'sk_test_external_client_timeout_gate']);
            // テストレーンの fake 配線 (FakeExternalsServiceProvider) が rebind しうるため、
            // **実装へ明示的に戻す** (前提が変わっても本テストが無意味にならないようにする)。
            $this->app->bind(AutoRechargeGatewayInterface::class, CashierAutoRechargeGateway::class);

            $attempt = stripeBudgetPendingAttempt($withInvoice, $withStripeCustomer);

            app(AutoRechargeService::class)->executeAttempt($attempt);

            // ★予算の上限 (定数) を超えない
            expect($counting->calls)->toBeLessThanOrEqual(ExternalClientTimeouts::DEFAULT_CONNECTION_STRIPE_CALL_BUDGET);
            // ★経路ごとの厳密な回数 (増えたら気づく = ドリフト検知)
            expect($counting->calls)->toBe($expectedCalls, implode(PHP_EOL, $counting->requestedUrls));
            // ★空振り防止: 応答列を使い切っていること (経路が途中で終わっていない)
            expect($counting->isExhausted())->toBeTrue('応答列を使い切っていません (経路が想定より短い)');
            // ★**どの分岐へ入ったか**を最初のリクエストで固定する
            //   (customer 既存 = GET retrieve / customer 新規 = POST create。
            //    回数だけ一致して別分岐を通っている、を防ぐ)
            expect($counting->requestedUrls[0] ?? null)->toBe($expectedFirstRequest);
            // ★経路が意図どおり終端したこと
            expect($attempt->refresh()->status)->toBe($expectedStatus);
        } finally {
            ApiRequestor::setHttpClient($original);
        }
    },
)->with('auto-recharge の外部呼び出し経路');
```

### F. `docs/architecture.md` — §S3 到達境界と面分類 に追記した 2 項
```markdown
  `tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php` が behavioral に固定する。
- 免除理由 (`ExternalClientBoundaryExemption`) には**適用条件を機械検査する前提表**が付く
  (「`disk()` を呼ばない」「`new Aws\…` しない」等)。docblock の約束だけで免除を通さない。
- **`driver=s3` の disk は全件が pin を宣言する**ことを gate が要求する。
  `Storage` facade を既定 disk のまま使う層は `FILESYSTEM_DISK` 次第で S3 へ到達しうるため、
  「特定の disk 名」ではなく driver 単位で塞ぐ。到達しても待ちはデータ系の帯 (有界) になる。
- **走査の保証範囲を誇張しない**: 目録の母集団は「型/クラス名の参照」「`new Aws\…`」
  「`disk()` / `getClient()` の呼び出し」「Stripe 大域 setter の呼び出し」の静的検出である。
  **文字列キーの container 解決だけでこれらの token をまったく出さない迂回は検出できない**。
  だから**やらない**、が規約の側の担保である。
```

## 3. mutation evidence (main 取り込み後に全 22 件を再実行 / **22/22 RED**)

Round 1 に提示した 20 件に、今回の [Critical] 対応で新設した 2 gate の分を足して再実行した。
各 mutation は「退避 → 適用 → 対象テスト実行 → **必ず退避から復元**」を 1 セットとし、
実行後に `git status --short` と `rg -n "mutationProbe|s3_unpinned_probe|listObjects" app/ tests/ config/`
で残骸ゼロを確認している。

| # | mutation | 赤くなったテスト (実測) |
|---|---|---|
| 21 | 免除 `DefaultDiskWithoutAwsClient` のクラス (`SopTextExtractor`) に `Storage::disk('s3')` を足す | `到達境界: 免除理由の適用条件が走査結果と矛盾しない` |
| 22 | `config/filesystems.php` に pin 無しの `driver=s3` disk を足す | `AWS config: driver=s3 の disk はすべて http / retries を宣言する` |

再実行で判明した追加の RED (Round 1 の表より厚くなった箇所):

- M3 (`config/filesystems.php` の `...awsS3ClientOptions()` 削除) は
  `AWS config: driver=s3 の disk はすべて…` も RED にする
- M14 (`AppServiceProvider` に `ApiRequestor::setHttpClient()` を足す) は
  `到達境界: 免除理由の適用条件が走査結果と矛盾しない` も RED にする
  (`pinned_control_client_construction` は `stripe_global_setter` を禁じているため)

## 4. テスト結果 (main 取り込み後の再実行)

### PHP
- `composer test` (グローバルロック配下 / `--parallel`): **3805 tests, 3803 passed, 2 skipped, 0 failed**
- `composer phpstan` (level 10, 815 files): **No errors** (`@phpstan-ignore` / baseline / widen は一切使っていない)
- `vendor/bin/pint --test`: passed / `composer fix`: passed

### JS
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: 成功
- `pnpm test`: 126 files / 1236 tests passed
- `pnpm typecheck:packages` / `pnpm build:packages`: 成功
- `pnpm test:packages`: 10 files / 106 tests passed

`composer test:browser` は走らせていない (本タスクは route / middleware / UI を 1 行も変えていないため)。

## 5. 既存不変条件への影響 (再確認)

- T122 `QueueWorkerLeaseInvariantTest` / `QueuedJobLeaseInventoryTest`: green。
  `retry_after` の期待値のみ 600 → 360 に更新 (削除・上書きではない)。
  規則 1 (worker `--timeout` 300 < retry_after 360) は成立。
- T131 `JobExclusionOrderingInvariantTest`: **変更なしで green**
  (`LOCK_TTL_SECONDS 180 < 360` / `uniqueFor 30 < 360`)。
- T132 `BillingGatewayFailureTaxonomyInventoryTest`: **変更なしで green**。
  timeout 由来の Stripe 例外は cURL error → `Stripe\Exception\ApiConnectionException` へ変換され、
  既存の `directMap()` が `ProviderUnavailable` へ分類する。**分類表は変えていない**
  (例外クラスが増えていないので vendor 走査の exact-fit も壊れない)。
  「timeout を短く pin すると本例外の出現頻度が上がる」ため、この対応関係を
  `tests/Unit/Support/Billing/GatewayFailureClassifierTest.php` に 1 ケース追加して固定した。

## 6. 判定のお願い

Round 1 の [Critical] は「約束を機械検査へ寄せる」形で 2 gate 新設 + mutation 2 件で実効性を示し、
[Warning] は dataset 追加 + 分岐の behavioral 固定で対応した。
残っている偽陰性・誇張・vendor 契約の誤読があれば具体的に指摘してほしい。
無ければ **APPROVED** と明記してほしい。

<?php

declare(strict_types=1);

use App\Enums\Security\ExternalCallKind;
use App\Enums\Security\JobDedupExemption;
use App\Enums\Security\JobDedupGuarantee;
use App\Jobs\Billing\AutoRechargeTriggerJob;
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
use App\Jobs\Billing\SetDefaultPaymentMethodJob;
use App\Jobs\Billing\SyncBillingCustomerDetails;
use App\Jobs\Capture\DeleteTakeObjectsJob;
use App\Jobs\Manual\DeleteRenderOutputsJob;
use App\Jobs\Manual\RunManualAnalysis;
use App\Jobs\Manual\RunManualRender;
use App\Mail\InquiryAcknowledgementMail;
use App\Mail\InquiryReceivedMail;
use App\Notifications\Account\AccountDeletionRequestedNotification;
use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
use App\Notifications\Billing\AutoRechargeDisabledNotification;
use App\Notifications\Billing\AutoRechargeEnabledNotification;
use App\Notifications\Billing\AutoRechargeFailedNotification;
use App\Notifications\Billing\PaymentFailedNotification;
use App\Notifications\Billing\RenewalReminderNotification;
use App\Services\Manual\AnalysisPipeline;
use App\Services\Manual\RenderPipeline;
use App\Support\JobExecution\AttemptOwnershipPreflight;
use Tests\Support\JobDedup\ExemptionEntry;
use Tests\Support\JobDedup\GuaranteeEntry;
use Tests\Support\JobDedup\NoExternalCall;
use Tests\Support\JobDedup\PreflightCheckpoint;
use Tests\Support\JobDedup\PreflightControlFlow;
use Tests\Support\JobDedup\PreflightRequirement;
use Tests\Support\QueuedJobPopulation;
use Webmozart\Assert\Assert;

/*
 * 裁定 AG-082「入口の排他 / 結果の一回性」の aicue 実装を deny-by-default で固定する。
 *
 * キューに載る全クラス (ShouldQueue 実装) は、次のいずれかに**必ず**分類される:
 *   - 保証側: JobDedupGuarantee (永続状態遷移の機構) + PreflightRequirement + 30 文字以上の根拠
 *   - 免除:   JobDedupExemption + 30 文字以上の根拠
 * 未分類は fail (新しいジョブを足したら必ずここへ登録する)。
 *
 * ★母集団は QueuedJobLeaseInventoryTest と**同一の実装** (Tests\Support\QueuedJobPopulation)
 *   を使う。2 実装に分けると片方だけ更新される drift が起きるため。
 *
 * ★ **この gate が保証するもの / しないもの**:
 *   - 保証する: 母集団の全クラスが分類されている / 期待する外部呼び出し種別と checkpoint 登録の
 *     **集合一致** / 再検証点の実在と制御方式に一致する戻り型 / 根拠 30 文字以上 / 免除 cap /
 *     `PreflightRequirement` 実装が 2 種に閉じている / 固定 event literal が 1 箇所に閉じている。
 *   - **保証しない**: preflight が**外部呼び出しの直前に置かれている**こと
 *     (Reflection では検査できない = 名前だけ存在する空メソッドでも green になる。
 *      配置の保証は Feature テスト = AnalysisPipelineTest / RenderPipelineTest /
 *      AutoRechargeServiceTest が「所有権喪失時に fake が 1 回も呼ばれない」ことで担う)。
 *     期待値 map と目録を**同時に**消す変更 (宣言的 gate の性質。1 箇所の削除では通らない
 *     = レビューで必ず 2 箇所の差分が見えることが目的)。
 *
 * 運用契約: docs/architecture.md §ジョブの重複実行と結果の一回性
 */

/** @return array<class-string, GuaranteeEntry> */
function jobDedupGuarantees(): array
{
    return [
        RunManualAnalysis::class => new GuaranteeEntry(
            mechanisms: [JobDedupGuarantee::PessimisticLockWithStatusGuard],
            preflights: [new PreflightCheckpoint(
                AnalysisPipeline::class, 'assertStillOwned',
                ExternalCallKind::LlmCompletion, PreflightControlFlow::ThrowsOnLoss,
            )],
            rationale: 'startJob が lockForUpdate + status===queued で running へ遷移させ、'
                .'finalize が同一 tx で materialize + tickets->commit + succeeded を原子化する。'
                .'LLM は冪等キーを持たないため、各呼び出しの直前に preflight を置く。',
        ),
        RunManualRender::class => new GuaranteeEntry(
            mechanisms: [JobDedupGuarantee::PessimisticLockWithStatusGuard],
            preflights: [new PreflightCheckpoint(
                RenderPipeline::class, 'assertStillOwned',
                ExternalCallKind::ObjectStoragePut, PreflightControlFlow::ThrowsOnLoss,
            )],
            rationale: 'startJob / finalize が AnalysisPipeline と同型。S3 PUT は取り消せない'
                .'外部副作用なので、updateProgress の後・upload の直前に preflight を置く。',
        ),
        ExecuteAutoRechargeAttemptJob::class => new GuaranteeEntry(
            // ★軸の違う 2 本の保証を**両方**登録する
            mechanisms: [
                // ★台帳の冪等キー UNIQUE は「起票の拒否」ではなく「効果確定の拒否」なので
                //   DatabaseUniqueConstraint とは別 case
                JobDedupGuarantee::IdempotentLedgerKeyUniqueConstraint, // 付与の一回性 (invoice 単位)
                JobDedupGuarantee::ConditionalStatusUpdate,             // attempt 遷移の一回性 (attempt 単位)
            ],
            // ★外部呼び出しは 2 つある。両方を登録する
            preflights: [
                new PreflightCheckpoint(
                    AttemptOwnershipPreflight::class, 'stillPending',
                    ExternalCallKind::StripeInvoiceCreate, PreflightControlFlow::ReturnsBoolean,
                ),
                new PreflightCheckpoint(
                    AttemptOwnershipPreflight::class, 'stillPending',
                    ExternalCallKind::StripeInvoicePay, PreflightControlFlow::ReturnsBoolean,
                ),
            ],
            rationale: '冪等キーが 2 本ある: 付与の一回性は台帳の recharge:{invoiceId} UNIQUE '
                .'(invoice 単位。付与は attempt 遷移より先に走るが、この UNIQUE が二重付与を拒否する)、'
                .'attempt 遷移の一回性は where status=pending の条件付き UPDATE (attempt 単位)。'
                .'org lock (TTL 180s) は best-effort で保証を担わない。',
        ),
        HandleAutoRechargeChargeFailureJob::class => new GuaranteeEntry(
            mechanisms: [
                JobDedupGuarantee::IdempotentLedgerKeyUniqueConstraint, // 順序逆転時の付与 (recordSuccessfulCharge)
                JobDedupGuarantee::ConditionalStatusUpdate,             // failure_code 記録 / terminal 遷移
            ],
            preflights: [new NoExternalCall(
                'preflight を要する (取り消せない) 外部副作用を持たない。retrieveInvoiceState は'
                .'冪等な読み取りで、terminateInvoice は Stripe の状態検査 (void/deleted/404 は成功扱い、'
                .'paid は明示的な非成功) で冪等化された**所有権喪失後の後始末**である。'
                .'課金そのものを起こす経路は ExecuteAutoRechargeAttemptJob 側にしかない。',
            )],
            rationale: '永続化はすべて AutoRechargeService へ委譲され、'
                .'recordSuccessfulCharge の付与は recharge:{invoiceId} UNIQUE、'
                .'handleChargeFailure / transitionToTerminal は where status=pending の'
                .'条件付き UPDATE で 1 attempt = 1 遷移に閉じる。',
        ),
    ];
}

/**
 * ジョブごとに **preflight を要求する外部呼び出しの種別** (期待値の正本)。
 *
 * ★ 目録 (`jobDedupGuarantees()`) とは**独立した宣言**である。
 *   目録の中だけで閉じた検査 (非空 / 重複なし / 実在) は「登録漏れ」という**不在**を
 *   検出できない — checkpoint を 1 件削っても残りが要件を満たしてしまう。
 *   期待集合との一致を要求すれば、削除は必ず赤になる。
 *
 * ★ **この検査が保証しないこと**: 本 map と目録の**両方**を同時に消せば green のままになる。
 *   これは宣言的 gate の性質であり、目的は「1 箇所の削除では通らない = レビューで必ず
 *   2 箇所の差分が見える」ことである。
 *   より強い形 (サービスのソースを走査し、外部呼び出しの実在から期待集合を導出する) は、
 *   **preflight を意図的に持たない外部呼び出し** (所有権喪失**後**の後始末である
 *   `terminateInvoice`) を別分類する必要があり複雑さが跳ねるため今回は採らない
 *   (AGENTS.md 思考原則 2)。
 *
 * ★ 空リスト = preflight を要する外部呼び出しを持たない (`preflights` は `NoExternalCall`
 *   ちょうど 1 件)。
 *
 * @return array<class-string, list<ExternalCallKind>>
 */
function jobDedupRequiredExternalCalls(): array
{
    return [
        RunManualAnalysis::class => [ExternalCallKind::LlmCompletion],
        RunManualRender::class => [ExternalCallKind::ObjectStoragePut],
        ExecuteAutoRechargeAttemptJob::class => [
            ExternalCallKind::StripeInvoiceCreate,
            ExternalCallKind::StripeInvoicePay,
        ],
        HandleAutoRechargeChargeFailureJob::class => [],
    ];
}

/** @return array<class-string, ExemptionEntry> */
function jobDedupExemptions(): array
{
    return [
        AutoRechargeTriggerJob::class => new ExemptionEntry(
            JobDedupExemption::GuardedByDownstreamConstraint,
            '起票先の tar_attempts_org_pending_unique (partial unique) が「org に pending は 1 つ」を'
            .'DB で拒否するため、重複配送は maybeCreateAttempt の pending 検査か unique violation で'
            .'no-op に収束する。ジョブ自身は課金も状態確定も行わない。',
        ),
        ReuseSubscriptionPaymentMethodJob::class => new ExemptionEntry(
            JobDedupExemption::ConvergentStateSync,
            'サブスク決済カードの流用は「default PM を同じ値に設定して snapshot を書く」冪等な'
            .'upsert であり、何度実行しても収束先が同じ。金銭は動かず、適格性 (autoEnableEligible) は'
            .'org lock 内で fail-closed に再評価される。',
        ),
        SetDefaultPaymentMethodJob::class => new ExemptionEntry(
            JobDedupExemption::ConvergentStateSync,
            'setup 完了後の default PM 設定と PM snapshot 更新は同じ値への冪等な upsert で、'
            .'再実行しても収束先が変わらない。自動有効化は org lock + lockForUpdate 下の'
            .'同一 TX で確定し、金銭は動かさない。',
        ),
        SyncBillingCustomerDetails::class => new ExemptionEntry(
            JobDedupExemption::ConvergentStateSync,
            'Organization の name を Stripe customer へ写すだけの片方向同期で、'
            .'書き込みは冪等な upsert。実行順が入れ替わっても最終的な収束先は'
            .'「最後に読んだ組織名」で同じになる (last-writer-wins が正しい)。',
        ),
        DeleteTakeObjectsJob::class => new ExemptionEntry(
            JobDedupExemption::IdempotentDeletion,
            'payload は S3 キーの list のみで、存在しないキーの削除は no-op。'
            .'ドメイン状態も課金も動かさないため、2 回目の実行は観測可能な差分を生まない。',
        ),
        DeleteRenderOutputsJob::class => new ExemptionEntry(
            JobDedupExemption::IdempotentDeletion,
            'output_path NULL / 最新 succeeded 自身 / prefix 不一致はすべて no-op で、'
            .'S3 削除は存在しないキーに対して冪等。NULL 化は検証時の値と一致する行のみを'
            .'更新する CAS なので、再実行で最新世代を誤って壊さない。',
        ),
        InquiryAcknowledgementMail::class => new ExemptionEntry(
            JobDedupExemption::DuplicateDeliveryAccepted,
            'お問い合わせ受付の自動返信メール。ドメイン状態を一切書かず、重複受信しても'
            .'「受け付けました」が 2 通届くだけで受信者を誤った操作へ誘導しない'
            .'(支払い・承認などの行動を要求しない)。',
        ),
        InquiryReceivedMail::class => new ExemptionEntry(
            JobDedupExemption::DuplicateDeliveryAccepted,
            '運営宛のお問い合わせ通知メール。ドメイン状態を書かず、重複受信しても'
            .'運営が同一問い合わせを 2 度見るだけで、利用者側に影響も金銭的操作も生じない。',
        ),
        AutoRechargeActionRequiredNotification::class => new ExemptionEntry(
            JobDedupExemption::DuplicateDeliveryAccepted,
            'SCA (カード認証) の復旧導線通知。重複受信しても遷移先は同一 invoice の'
            .'hosted_invoice_url で、認証済みなら Stripe 側が二重課金しない。'
            .'追加の支払い操作を新規に発生させないため at-least-once を受容する。',
        ),
        AutoRechargeDisabledNotification::class => new ExemptionEntry(
            JobDedupExemption::DuplicateDeliveryAccepted,
            '連続失敗によるオートリチャージ自動停止のお知らせ。状態は既に確定済みで'
            .'通知は事後報告にすぎず、重複受信しても受信者が行う操作 (カード更新) は冪等。',
        ),
        AutoRechargeEnabledNotification::class => new ExemptionEntry(
            JobDedupExemption::DuplicateDeliveryAccepted,
            'オートリチャージ自動有効化の事後通知。設定は既に確定済みで、'
            .'重複受信しても課金は発生せず、受信者に求める行動もない (確認のみ)。',
        ),
        AutoRechargeFailedNotification::class => new ExemptionEntry(
            JobDedupExemption::DuplicateDeliveryAccepted,
            'オートリチャージ失敗のお知らせ。attempt は既に failed で確定しており、'
            .'重複受信しても案内先は請求ページのカード更新導線のみで二重の支払い操作を招かない。',
        ),
        PaymentFailedNotification::class => new ExemptionEntry(
            JobDedupExemption::DuplicateDeliveryAccepted,
            'サブスク決済失敗のお知らせ。支払い自体は Stripe 側の請求書で一意に管理され、'
            .'重複受信しても同じ請求書へ誘導するだけなので二重支払いにはならない。',
        ),
        AccountDeletionRequestedNotification::class => new ExemptionEntry(
            JobDedupExemption::DuplicateDeliveryAccepted,
            '退会予約を受け付けたことのお知らせ。ドメイン状態を一切書かず (via() は予約の生存を'
            .'読むだけ)、重複受信しても案内先は /settings の取消ボタンで、支払い等の操作を'
            .'新たに発生させない。**job 実行の dedup は主張しない** — 保証しているのは'
            .'「予約操作からの job 生成は最大 1 件」までで、それは requestAccountDeletion() の'
            .'冪等 no-op (永続状態遷移) が担う。',
        ),
        RenewalReminderNotification::class => new ExemptionEntry(
            JobDedupExemption::DuplicateDeliveryAccepted,
            '契約更新のリマインダ。ドメイン状態を書かず、重複受信しても案内内容が同一で'
            .'受信者に新たな支払い操作を発生させない (更新は Stripe の自動請求が行う)。',
        ),
    ];
}

/**
 * 免除件数の上限 (形骸化ガード)。**現在値ちょうど** (exact fit)。
 *
 * 「以下」ではなく「一致」で固定するのは、増やすときも減らすときも必ずこの数字を
 * 書き換えさせるためである (ModelDirectFetchInvariantTest と同じ作法)。
 */
function jobDedupExemptionCap(): int
{
    return 15;
}

/**
 * case 別の件数 (分類の偏り検出)。**array_sum で全体 cap を導出しない**
 * (全体 cap と case 別を独立に書かせることで、片方だけの書き換えを差分に必ず現す)。
 *
 * @return array<string, int>
 */
function jobDedupExemptionCapByCase(): array
{
    return [
        JobDedupExemption::DuplicateDeliveryAccepted->value => 9,
        JobDedupExemption::IdempotentDeletion->value => 2,
        JobDedupExemption::ConvergentStateSync->value => 3,
        JobDedupExemption::GuardedByDownstreamConstraint->value => 1,
    ];
}

/**
 * tests/Support/JobDedup/ 配下の PHP ファイル絶対パス一覧。
 *
 * @return list<string>
 */
function jobDedupSupportPhpFiles(): array
{
    $paths = glob(base_path('tests/Support/JobDedup').DIRECTORY_SEPARATOR.'*.php');
    Assert::isArray($paths, 'tests/Support/JobDedup を走査できません');
    sort($paths);

    return array_values($paths);
}

/** tests/Support/JobDedup 配下のパスを PSR-4 でクラス名へ変換する (純関数)。 */
function jobDedupSupportClassNameForPath(string $path): string
{
    return 'Tests\\Support\\JobDedup\\'.basename($path, '.php');
}

test('キューに載る全クラスが保証側 or 免除に分類されている (未分類は fail)', function (): void {
    $scanned = QueuedJobPopulation::shouldQueueClasses();
    $classified = array_merge(array_keys(jobDedupGuarantees()), array_keys(jobDedupExemptions()));
    sort($classified);

    $missing = array_values(array_diff($scanned, $classified));
    $stale = array_values(array_diff($classified, $scanned));

    expect($missing)->toBe([], '未分類の ShouldQueue 実装がある: '.implode(', ', $missing));
    expect($stale)->toBe([], '目録に実在しないクラスが残っている: '.implode(', ', $stale));
});

test('母集団の走査が縮んでいない (Job / Mailable / Notification の 3 系統が入る)', function (): void {
    $scanned = QueuedJobPopulation::shouldQueueClasses();

    expect($scanned)->toContain(RunManualAnalysis::class);
    expect($scanned)->toContain(InquiryReceivedMail::class);
    expect($scanned)->toContain(PaymentFailedNotification::class);
});

test('保証側と免除は排他 (同じクラスが両方に居ない)', function (): void {
    $both = array_intersect(array_keys(jobDedupGuarantees()), array_keys(jobDedupExemptions()));

    expect(array_values($both))->toBe([]);
});

/*
 * ★ 「QUEUED_JOB_LEASE_INVENTORY と目録のキー集合が一致する」という直接検査は**置かない**。
 *   (a) 両 gate が同じ `QueuedJobPopulation::shouldQueueClasses()` に対して
 *       それぞれ対称差 = 空を要求するため、両方 green なら一致は必然 (推移律)。
 *   (b) 他テストファイルのグローバル定数を参照すると、Pest の --parallel が
 *       ファイル単位でプロセスを分けたとき未定義になりうる。
 *   drift の構造的な防止は「母集団の走査実装を 1 本にしたこと」で達成されている。
 */

test('preflight の再検証点が実在し、登録された制御方式に一致する戻り型を持つ (※配置までは検査しない)', function (): void {
    // ★ この gate が固定できるのは**再検証点の実在と戻り型まで**である。
    //   「外部呼び出しの直前で呼ばれていること」は Reflection では検査できない
    //   (名前だけ存在する空メソッドでも green になる)。
    //   配置の保証は Feature テスト (所有権喪失時に LLM / S3 / Stripe fake が
    //   1 回も呼ばれないこと) の担当である。
    foreach (jobDedupGuarantees() as $class => $entry) {
        foreach ($entry->preflights as $preflight) {
            if (! $preflight instanceof PreflightCheckpoint) {
                continue;
            }

            $reflection = new ReflectionClass($preflight->verifierClass);
            expect($reflection->hasMethod($preflight->verifierMethod))->toBeTrue(
                "{$class}: preflight 再検証点 {$preflight->verifierClass}::{$preflight->verifierMethod} が実在しません",
            );

            // ★ Manual (例外で中断 = void) と Billing (structured return = bool) を統合しないため、
            //   目録が宣言した PreflightControlFlow と実際の戻り型の一致を検査する。
            $expected = $preflight->expectedReturnType();
            $returnType = $reflection->getMethod($preflight->verifierMethod)->getReturnType();
            expect($returnType instanceof ReflectionNamedType && $returnType->getName() === $expected)->toBeTrue(
                "{$class}: preflight 再検証点の戻り型が目録の制御方式 ({$expected}) と一致しません",
            );
        }
    }
});

test('保証機構は 1 つ以上・重複なしで登録されている', function (): void {
    foreach (jobDedupGuarantees() as $class => $entry) {
        expect($entry->mechanisms)->not->toBeEmpty("{$class}: 保証機構が空です");

        // ★重複「値そのもの」を出す (順序依存の比較より失敗メッセージが読みやすい)
        $values = array_map(static fn (JobDedupGuarantee $m): string => $m->value, $entry->mechanisms);
        $duplicates = array_values(array_diff_assoc($values, array_unique($values)));
        expect($duplicates)->toBe([], "{$class}: 保証機構が重複しています: ".implode(', ', $duplicates));
    }
});

test('期待する外部呼び出し種別が全ジョブ分宣言されている (期待値の書き忘れ検出)', function (): void {
    $guaranteed = array_keys(jobDedupGuarantees());
    $required = array_keys(jobDedupRequiredExternalCalls());
    sort($guaranteed);
    sort($required);

    expect($required)->toBe(
        $guaranteed,
        'jobDedupRequiredExternalCalls() は jobDedupGuarantees() の全クラスを列挙すること',
    );
});

test('期待する外部呼び出し種別に重複がない', function (): void {
    foreach (jobDedupRequiredExternalCalls() as $class => $kinds) {
        $values = array_map(static fn (ExternalCallKind $k): string => $k->value, $kinds);
        $duplicates = array_values(array_diff_assoc($values, array_unique($values)));
        expect($duplicates)->toBe([], "{$class}: 期待種別が重複しています: ".implode(', ', $duplicates));
    }
});

test('登録済み checkpoint の種別集合が期待集合と一致する (登録漏れ / 余剰の検出)', function (): void {
    // ★ ここが completeness の要。目録の中だけで閉じた検査では
    //   「checkpoint を 1 件削った」ことを検出できない (残りが要件を満たしてしまう)。
    foreach (jobDedupGuarantees() as $class => $entry) {
        expect($entry->preflights)->not->toBeEmpty("{$class}: preflight が空です");

        $checkpoints = array_values(array_filter(
            $entry->preflights,
            static fn (PreflightRequirement $p): bool => $p instanceof PreflightCheckpoint,
        ));
        $none = array_values(array_filter(
            $entry->preflights,
            static fn (PreflightRequirement $p): bool => $p instanceof NoExternalCall,
        ));

        $expectedKinds = array_map(
            static fn (ExternalCallKind $k): string => $k->value,
            jobDedupRequiredExternalCalls()[$class] ?? [],
        );
        $registeredKinds = array_map(
            static fn (PreflightCheckpoint $c): string => $c->externalCall->value,
            $checkpoints,
        );
        sort($expectedKinds);
        sort($registeredKinds);

        expect($registeredKinds)->toBe(
            $expectedKinds,
            "{$class}: checkpoint の種別集合が期待 (jobDedupRequiredExternalCalls) と一致しません。"
            .'外部呼び出しを増減させたら両方を更新すること',
        );

        if ($expectedKinds === []) {
            // preflight を要する外部呼び出しを持たないジョブ: NoExternalCall ちょうど 1 件
            expect(count($none))->toBe(1, "{$class}: NoExternalCall は 1 件だけ登録すること");
            expect($checkpoints)->toBe([], "{$class}: 外部呼び出しなしと宣言しつつ checkpoint があります");

            continue;
        }

        // 外部呼び出しを持つジョブ: NoExternalCall と混在させない
        expect($none)->toBe([], "{$class}: NoExternalCall と PreflightCheckpoint を混在登録しています");
    }
});

test('PreflightRequirement の実装は 2 種類に閉じている (sealed 相当)', function (): void {
    // PHP に sealed type が無いため gate が閉じる。3 つ目の実装が現れたら、
    // 「外部呼び出しなし」を主張する新しい抜け道が増えていないか必ず再検討する。
    // 走査対象は tests/Support/JobDedup/ 配下のみ (app/ 全走査はコストだけで意味がない)。
    $found = [];
    foreach (jobDedupSupportPhpFiles() as $path) {
        $class = jobDedupSupportClassNameForPath($path);
        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isInterface() || ! $reflection->implementsInterface(PreflightRequirement::class)) {
            continue;
        }
        $found[] = $reflection->getName();
    }
    sort($found);

    expect($found)->toBe([NoExternalCall::class, PreflightCheckpoint::class]);
});

test('目録の根拠は 30 文字以上 (constructor と gate の二重固定)', function (): void {
    foreach (jobDedupGuarantees() as $class => $entry) {
        expect(mb_strlen($entry->rationale))->toBeGreaterThanOrEqual(
            30,
            "{$class}: 保証側の根拠は 30 文字以上で書くこと",
        );

        foreach ($entry->preflights as $preflight) {
            if ($preflight instanceof NoExternalCall) {
                expect(mb_strlen($preflight->rationale))->toBeGreaterThanOrEqual(
                    30,
                    "{$class}: 「外部呼び出しなし」の根拠は 30 文字以上で書くこと",
                );
            }
        }
    }

    foreach (jobDedupExemptions() as $class => $entry) {
        expect(mb_strlen($entry->rationale))->toBeGreaterThanOrEqual(
            30,
            "{$class}: 免除の根拠は 30 文字以上で書くこと",
        );
    }
});

test('免除件数が全体 cap / case 別 cap と一致する (形骸化ガード)', function (): void {
    $exemptions = jobDedupExemptions();

    expect(count($exemptions))->toBe(
        jobDedupExemptionCap(),
        '免除件数が目録の宣言と一致しません。増減させたら jobDedupExemptionCap() も書き換えること '
        .'(「以下」ではなく「一致」で固定するのは、1 件直しても数字が下がらない「黙って足せる枠」を作らないため)',
    );

    $byCase = [];
    foreach ($exemptions as $entry) {
        $byCase[$entry->exemption->value] = ($byCase[$entry->exemption->value] ?? 0) + 1;
    }
    ksort($byCase);
    $declared = jobDedupExemptionCapByCase();
    ksort($declared);

    expect($byCase)->toBe($declared, 'case 別の免除件数が宣言と一致しません (分類の偏りは差分に必ず現すこと)');
});

test('固定 event 名の literal は ExternalCallKind 以外に直書きされていない', function (): void {
    // literal の直書きが増えると、ログ基盤での集計語彙が静かに割れる。
    // 2 つの event 名 (抑止 / 後始末) をまとめて検査する。
    // ★ single / double quote の **4 パターン**を検査する
    //   (片方だけだと "job_ownership_lost" で gate を回避できる)。
    $literals = [
        "'job_ownership_lost'", '"job_ownership_lost"',
        "'job_ownership_lost_cleanup'", '"job_ownership_lost_cleanup"',
    ];
    $violations = [];

    foreach (QueuedJobPopulation::appPhpFiles() as $path) {
        if (str_ends_with(str_replace(DIRECTORY_SEPARATOR, '/', $path), 'app/Enums/Security/ExternalCallKind.php')) {
            continue; // 正本
        }

        $source = file_get_contents($path);
        Assert::string($source, "ファイルを読み込めません: {$path}");

        foreach ($literals as $literal) {
            if (str_contains($source, $literal)) {
                $violations[] = $path.' ('.$literal.')';
            }
        }
    }

    expect($violations)->toBe(
        [],
        '固定 event 名は ExternalCallKind::LOG_EVENT / CLEANUP_LOG_EVENT を参照すること: '
        .implode(', ', $violations),
    );
});

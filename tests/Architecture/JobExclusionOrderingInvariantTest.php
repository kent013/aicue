<?php

declare(strict_types=1);

use App\Jobs\Billing\AutoRechargeTriggerJob;
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
use App\Services\Billing\AutoRechargeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/*
 * 入口の排他 (Cache::lock TTL) の**序列**を CI 固定する。
 * ★ T137 で `ShouldBeUnique` (uniqueFor) の系統は AutoRechargeTriggerJob から撤去され、
 *   本ファイルの当該 2 テストは「実装しないこと」の固定へ**反転**した (下の反転 docblock)。
 *
 * 裁定 AG-082: 入口の排他は best-effort であり、結果の一回性を保証しない。
 * したがって「保証を代替できるほど長く」してはならない — 鍵が残留すると、
 * 正当な再実行 (§10.8-1「再実行は analyze/render 再トリガーのみ」) を最大 TTL 秒ブロックする。
 *
 * ★比較先を「マジックナンバー」ではなく **その接続の retry_after** にしているのが要点。
 *   鍵の残留がキューの再配送間隔を超えないことを保証すれば、封鎖時間が構造的に有界化される。
 *
 * 運用契約: docs/architecture.md §ジョブの重複実行と結果の一回性
 */

/** 入口の排他が乗るレーン (= 本番の既定キュー接続) の名前。 */
const JOB_EXCLUSION_DEFAULT_CONNECTION = 'database';

/**
 * 比較先の retry_after。
 *
 * ★ **実行時の `config('queue.default')` は使えない** — テストレーンは phpunit.xml が
 *   `QUEUE_CONNECTION=sync` を force しており、`sync` 接続は `retry_after` を持たない。
 *   「本番の既定接続」は `config/queue.php` の `env('QUEUE_CONNECTION', 'database')` の
 *   **フォールバック値**であり、それが `database` から動いていないことは
 *   下の「比較先の前提」テストがソースレベルで固定する
 *   (既存 QueueWorkerLeaseInvariantTest が「env 上書きを残すと gate が嘘をつく」として
 *    retry_after をリテラルで持たせているのと同じ発想)。
 */
function jobExclusionDefaultRetryAfter(): int
{
    return config()->integer('queue.connections.'.JOB_EXCLUSION_DEFAULT_CONNECTION.'.retry_after');
}

test('入口の排他: auto-recharge の org lock TTL は既定接続の retry_after を下回る', function (): void {
    $retryAfter = jobExclusionDefaultRetryAfter();

    expect(AutoRechargeService::LOCK_TTL_SECONDS)->toBeLessThan(
        $retryAfter,
        'org lock TTL がキューの再配送間隔以上です。ゴーストロックが同一ジョブの再配送より'
        .'長く残ると、正当な再実行を封鎖します。TTL は保証を担わないため短い側に倒すこと。',
    );
});

/**
 * 【契約の反転 (AG-114 確定 1 / T137)】
 * - 旧主張: AutoRechargeTriggerJob の uniqueFor は既定接続の retry_after を下回り、正の値である
 * - 旧目的: 入口排他 (ShouldBeUnique) の鍵が再配送間隔を跨いで抑止を残さないようにする
 * - 新主張: AutoRechargeTriggerJob は ShouldBeUnique を **実装しない** (入口排他を持たない)
 * - 新前提: 結果の一回性は maybeCreateAttempt の organizations 行ロック + pending 検査 +
 *   tar_attempts_org_pending_unique (partial unique) + unique violation の no-op 化が担う
 * - 前提を守る機構: AutoRechargeAttemptUniquenessTest (3 点の behavioral 固定) +
 *   JobExecutionDedupInventoryTest の GuardedByDownstreamConstraint 登録
 * - 反転根拠: UniqueLock は dispatch 呼び出し時に取得され rollback で解放されない。業務 tx の
 *   内側で dispatch する設計 (確定 1) では、ネスト深さに依らず rollback 後も uniqueFor 秒の
 *   抑止が残る。AGENTS.md ドメイン規約 6 のとおり入口排他は保証を担わないため撤去する
 */
test('入口の排他: AutoRechargeTriggerJob は ShouldBeUnique を実装しない', function (): void {
    expect(is_subclass_of(AutoRechargeTriggerJob::class, ShouldBeUnique::class))->toBeFalse(
        'AutoRechargeTriggerJob が入口排他 (ShouldBeUnique) を持っています。業務 tx の内側で'
        .'dispatch する設計では UniqueLock が rollback で解放されず、uniqueFor 秒の抑止が残ります。'
        .'一回性は永続状態遷移 (org 行ロック + pending 検査 + partial unique) が担います。',
    );
});

test('入口の排他: AutoRechargeTriggerJob は uniqueFor / uniqueId を持たない (死んだ宣言の検出)', function (): void {
    // ShouldBeUnique 無しの uniqueFor / uniqueId は何も効かない宣言であり、
    // 「排他がある」という誤読を生むため残さない
    $reflection = new ReflectionClass(AutoRechargeTriggerJob::class);
    expect(array_key_exists('uniqueFor', $reflection->getDefaultProperties()))->toBeFalse();
    expect($reflection->hasMethod('uniqueId'))->toBeFalse();
});

test('入口の排他: 比較先の前提 — auto-recharge の 2 ジョブは既定接続で動く', function (): void {
    // ★ 接続 pin (T127: 既定キュー接続の分割) が入ると retry_after との比較が意味を失う。
    //   前提が崩れた瞬間に赤くする。
    //   ★ 他テストファイルのグローバル定数 (QUEUED_JOB_LEASE_INVENTORY) は参照しない —
    //     Pest の --parallel はファイル単位でプロセスを分けるため未定義になりうる。
    //     ジョブ実体から直接読めば単体で成立する。
    expect((new AutoRechargeTriggerJob(1))->connection)->toBeNull();
    expect((new ExecuteAutoRechargeAttemptJob(1))->connection)->toBeNull();
});

test('入口の排他: 比較先の前提 — 本番の既定キュー接続は database である', function (): void {
    // ★ 既定接続が差し替わると「どのレーンの再配送間隔と比べているのか」が変わり、
    //   gate は green のまま**別レーンと比較する偽グリーン**になる。
    //   実行時の config('queue.default') はテストレーンで sync に force されるため使えない。
    //   本番既定は config/queue.php の env() フォールバック値なので、そこをソースで固定する。
    $source = file_get_contents(config_path('queue.php'));
    expect($source)->toBeString();
    expect(str_contains((string) $source, "'default' => env('QUEUE_CONNECTION', '".JOB_EXCLUSION_DEFAULT_CONNECTION."')"))
        ->toBeTrue(
            '本番の既定キュー接続が変わりました。入口の排他 TTL / uniqueFor の比較先 (retry_after) が'
            .'意図したレーンかを再検討し、本テストと docs/architecture.md の序列を更新すること。',
        );

    // 比較先そのものが実在すること (degenerate PASS 防止)
    expect(jobExclusionDefaultRetryAfter())->toBeGreaterThan(0);
});

<?php

declare(strict_types=1);

use App\Jobs\Billing\AutoRechargeTriggerJob;
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
use App\Services\Billing\AutoRechargeService;

/*
 * 入口の排他 (Cache::lock TTL / ShouldBeUnique の uniqueFor) の**序列**を CI 固定する。
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

test('入口の排他: AutoRechargeTriggerJob の uniqueFor は既定接続の retry_after を下回る', function (): void {
    $retryAfter = jobExclusionDefaultRetryAfter();

    expect((new AutoRechargeTriggerJob(1))->uniqueFor)->toBeLessThan(
        $retryAfter,
        'uniqueFor がキューの再配送間隔以上です。ShouldBeUnique の鍵は失敗や timeout で'
        .'解放されないことがあるため (Laravel 公式)、残留時間を再配送間隔の内側に収めること。',
    );
});

test('入口の排他: uniqueFor は正の値である (実質無効化の検出)', function (): void {
    // 0 / 負値は「鍵を持たない」に等しく、ShouldBeUnique の宣言が静かに空洞化する
    expect((new AutoRechargeTriggerJob(1))->uniqueFor)->toBeGreaterThan(0);
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

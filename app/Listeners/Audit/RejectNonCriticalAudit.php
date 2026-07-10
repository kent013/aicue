<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Support\CriticalActionContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Events\Auditing;

/**
 * 既定で全 audit を抑制し、`CriticalActionContext` が active なときのみ通す listener。
 *
 *  - `AuditCustom` (= `$model->isCustomEvent === true`) 経路は明示的 audit として通す
 *  - context inactive かつ custom event でもない場合: `Log::warning` + false 返却で破棄
 *  - **throw しない**: save() 後に発火する listener で throw すると DB 状態と audit の
 *    不整合になる。context 必須を強制したいモデルは saving/deleting hook 側で
 *    fail-fast させること (この listener の責務外)
 */
final class RejectNonCriticalAudit
{
    public function __construct(
        private readonly CriticalActionContext $context,
        private readonly Application $app,
    ) {}

    public function handle(Auditing $event): bool
    {
        $model = $event->model;

        /** @var mixed $isCustomEvent */
        $isCustomEvent = property_exists($model, 'isCustomEvent') ? $model->isCustomEvent : false;
        if ($isCustomEvent === true) {
            return true;
        }

        if ($this->context->isActive()) {
            return true;
        }

        $dirtyKeys = array_keys($model->getDirty());

        // 抑制はするが、auditable モデルへの context 無し変更は想定外の経路なので
        // silent drop を運用者に可視化する warning を残す。
        Log::warning('critical_attribute_change_without_context', [
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'dirty_keys' => $dirtyKeys,
            'running_in_console' => $this->app->runningInConsole(),
            'running_in_queue' => $this->resolveRunningInQueue(),
        ]);

        return false;
    }

    /**
     * `runningInQueue()` が利用可能ならそれを使い、不可なら console 実行 (かつ
     * ユニットテストではない) を queue 実行の proxy とする。
     */
    private function resolveRunningInQueue(): bool
    {
        if (method_exists($this->app, 'runningInQueue')) {
            /** @var mixed $result */
            $result = $this->app->runningInQueue();

            return $result === true;
        }

        return $this->app->runningInConsole() && ! $this->app->runningUnitTests();
    }
}

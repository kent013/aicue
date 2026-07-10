<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

/**
 * 管理パネルの Critical Action 実行中だけ active になる per-request scope の context。
 *
 * `RejectNonCriticalAudit` listener がこの context を参照し、active なときだけ
 * `model_audits` への記録を通す (= 「critical 操作中のみ audit を記録」の gating)。
 *
 * ルール:
 *  1. ServiceProvider で `$this->app->scoped()` バインドし HTTP request scope 限定
 *  2. activate() / deactivate() は必ずペアリングする (推奨は run() scope API)
 *  3. queue worker は別 container で起動するため context は継承されない
 *     (queue 経由の critical mutation はこの基盤では audit されない)
 *  4. **ネスト activate() 禁止**: 単一スロット型のためネスト呼び出しは親 context を
 *     誤って解除する原因になる。active 状態で activate() を再呼び出しすると
 *     LogicException で fail-fast する
 */
final class CriticalActionContext
{
    private bool $active = false;

    private ?string $actionName = null;

    private ?string $reason = null;

    private ?string $ticketId = null;

    private ?string $adminActionId = null;

    /**
     * 低水準 API: context を active 状態にする。
     *
     * **新規 caller は `run()` scope API を使うこと**。手動の
     * `try { activate(); ... } finally { deactivate(); }` は activate() が
     * LogicException を投げた場合に外側 context を誤解除する pairing バグになる。
     * `run()` は構造的にそれを防ぐ。
     *
     * @see self::run() — 推奨経路
     */
    public function activate(
        string $actionName,
        string $reason,
        ?string $adminActionId = null,
        ?string $ticketId = null,
    ): void {
        if ($this->active) {
            throw new LogicException(sprintf(
                'CriticalActionContext::activate() called while already active (current actionName=%s, new actionName=%s). '
                .'Nested activation is not supported. Use try/finally pairing.',
                (string) $this->actionName,
                $actionName,
            ));
        }

        $this->active = true;
        $this->actionName = $actionName;
        $this->reason = $reason;
        $this->adminActionId = $adminActionId;
        $this->ticketId = $ticketId;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->actionName = null;
        $this->reason = null;
        $this->ticketId = null;
        $this->adminActionId = null;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function actionName(): ?string
    {
        return $this->actionName;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function ticketId(): ?string
    {
        return $this->ticketId;
    }

    public function adminActionId(): ?string
    {
        return $this->adminActionId;
    }

    /**
     * scope API: callback の前後で activate / deactivate を pairing する。
     *
     * `try { activate(); ... } finally { deactivate(); }` の手書きは、activate() が
     * ネスト検知で LogicException を投げたとき finally 内 deactivate() が
     * **外側 context を誤って解除**する pairing バグになる。
     *
     * run() は activate() 成功後にのみ finally-deactivate に入るため、activate()
     * 失敗時は外側 context が無事に維持される。Critical Action の実装は必ず
     * この run() を介して書くこと。
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function run(
        string $actionName,
        string $reason,
        callable $callback,
        ?string $adminActionId = null,
        ?string $ticketId = null,
    ): mixed {
        $this->activate(
            actionName: $actionName,
            reason: $reason,
            adminActionId: $adminActionId,
            ticketId: $ticketId,
        );

        // activate() が LogicException で抜けた場合はここに到達しない → 外側 context は無事。
        try {
            return $callback();
        } finally {
            $this->deactivate();
        }
    }
}

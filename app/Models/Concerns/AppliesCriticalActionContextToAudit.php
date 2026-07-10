<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\CriticalActionContext;
use Illuminate\Database\Eloquent\Model;

/**
 * laravel-auditing の `transformAudit()` で `CriticalActionContext` の情報を
 * audit payload に注入する trait。Auditable モデルは必ずこの trait を併用する
 * (これが無いと gating 用メタデータが audit に残らない)。
 *
 *  - `tags`: 既存タグに `source:admin_panel,action:{actionName},admin_action_id:{UUID}` を merge
 *  - **updated / created / restored event**: `new_values.__reason` / `__ticket_id` /
 *    `__admin_action_id` を merge
 *  - **deleted event**: `new_values` の null 意味論 (laravel-auditing 内部契約) を保持しつつ
 *    `old_values` に同 context メタデータを merge (「削除前の状態」に context 情報を載せる)
 *
 * 使い方 (OwenIt\Auditing\Auditable も transformAudit を定義するため insteadof が必須):
 *
 * ```php
 * class Foo extends Model implements \OwenIt\Auditing\Contracts\Auditable
 * {
 *     use AppliesCriticalActionContextToAudit;
 *     use \OwenIt\Auditing\Auditable {
 *         AppliesCriticalActionContextToAudit::transformAudit insteadof \OwenIt\Auditing\Auditable;
 *     }
 * }
 * ```
 *
 * @phpstan-require-extends Model
 */
trait AppliesCriticalActionContextToAudit
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        $context = app(CriticalActionContext::class);

        if (! $context->isActive()) {
            return $data;
        }

        $newTags = ['source:admin_panel'];

        $actionName = $context->actionName();
        if ($actionName !== null) {
            $newTags[] = 'action:'.$actionName;
        }

        $adminActionId = $context->adminActionId();
        if ($adminActionId !== null) {
            $newTags[] = 'admin_action_id:'.$adminActionId;
        }

        $existingTags = $data['tags'] ?? '';
        if (is_string($existingTags) && $existingTags !== '') {
            $existing = array_map('trim', explode(',', $existingTags));
            $allTags = array_values(array_unique(array_merge($existing, $newTags)));
        } else {
            $allTags = $newTags;
        }
        $data['tags'] = implode(',', $allTags);

        $event = $data['event'] ?? null;
        $reason = $context->reason();
        $ticketId = $context->ticketId();

        if ($event === 'deleted') {
            // new_values の null 意味論を保持しつつ old_values に context メタを merge。
            // old_values が null になる異常系 (パッケージ内部の edge case) でも空 array で
            // 初期化して context だけは必ず残し、監査の最小真正性を保証する。
            /** @var mixed $oldValues */
            $oldValues = $data['old_values'] ?? null;
            $oldValuesArr = is_array($oldValues) ? $oldValues : [];

            if ($reason !== null) {
                $oldValuesArr['__reason'] = $reason;
            }
            if ($ticketId !== null) {
                $oldValuesArr['__ticket_id'] = $ticketId;
            }
            if ($adminActionId !== null) {
                $oldValuesArr['__admin_action_id'] = $adminActionId;
            }
            $data['old_values'] = $oldValuesArr;

            return $data;
        }

        /** @var mixed $newValues */
        $newValues = $data['new_values'] ?? null;
        if (! is_array($newValues)) {
            return $data;
        }

        if ($reason !== null) {
            $newValues['__reason'] = $reason;
        }
        if ($ticketId !== null) {
            $newValues['__ticket_id'] = $ticketId;
        }
        if ($adminActionId !== null) {
            $newValues['__admin_action_id'] = $adminActionId;
        }
        $data['new_values'] = $newValues;

        return $data;
    }
}

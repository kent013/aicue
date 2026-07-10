<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ModelAuditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use OwenIt\Auditing\Models\Audit as BaseAudit;

/**
 * 運営 Admin の Critical Action による Eloquent モデル属性 diff の audit。
 *
 * 監査 3 層のうち「モデル属性 diff」レイヤー (認証系イベントの SecurityAuditEvent、
 * LLM 呼び出しの LlmCallLog とは別レイヤーで併存)。記録は owen-it/laravel-auditing
 * 経由のみで、`RejectNonCriticalAudit` listener が CriticalActionContext active 時に
 * 限って通す。
 *
 * @property int $id
 * @property string|null $user_type
 * @property int|null $user_id
 * @property string $event
 * @property string $auditable_type
 * @property int $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $url
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $tags
 */
class ModelAudit extends BaseAudit
{
    /** @use HasFactory<ModelAuditFactory> */
    use HasFactory;

    protected $table = 'model_audits';

    /**
     * 書き込みは laravel-auditing の Database driver (`::create($model->toAudit())`)
     * 経由のみで、user_id 等はサーバ側 resolver が導出する (client payload は
     * この create に到達しない)。パッケージ既定の $guarded = [] (全開放) は
     * MassAssignmentSafetyTest 違反のため id のみ guard する。
     * HTTP 入力からこのモデルを fill する経路を新設してはならない。
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /** @return list<string> */
    public function parsedTags(): array
    {
        /** @var mixed $raw */
        $raw = $this->getAttribute('tags');

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $t): bool => $t !== '',
        ));
    }

    public function displaySource(): string
    {
        foreach ($this->parsedTags() as $tag) {
            if (Str::startsWith($tag, 'source:')) {
                return Str::after($tag, 'source:');
            }
        }

        return 'unknown';
    }

    public function displayAction(): ?string
    {
        foreach ($this->parsedTags() as $tag) {
            if (Str::startsWith($tag, 'action:')) {
                return Str::after($tag, 'action:');
            }
        }

        return null;
    }

    /**
     * Critical Action の理由 (`__reason`) を抽出する。
     *
     * deleted event は `new_values` が null で、trait (AppliesCriticalActionContextToAudit)
     * が `old_values` 側に context を merge するため、deleted のみ `old_values`、
     * それ以外は `new_values` を参照する。
     */
    public function displayReason(): ?string
    {
        $values = $this->event === 'deleted' ? $this->old_values : $this->new_values;

        if (! is_array($values)) {
            return null;
        }

        $reasonRaw = $values['__reason'] ?? null;

        return is_string($reasonRaw) && $reasonRaw !== '' ? $reasonRaw : null;
    }

    public function adminActionId(): ?string
    {
        foreach ($this->parsedTags() as $tag) {
            if (Str::startsWith($tag, 'admin_action_id:')) {
                return Str::after($tag, 'admin_action_id:');
            }
        }

        return null;
    }

    protected static function newFactory(): ModelAuditFactory
    {
        return ModelAuditFactory::new();
    }
}

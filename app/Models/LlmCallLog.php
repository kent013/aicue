<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LlmCallLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * LLM 呼び出しのコスト記録 (1 execution = 1 row)。
 * 書き込みは App\Services\LlmCallLogWriter → recordWithOrganization() 経由のみ。
 *
 * @property int $id
 * @property string $execution_id
 * @property int|null $organization_id
 * @property int|null $user_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $prompt_class
 * @property string|null $prompt_template
 * @property string $provider
 * @property string $model
 * @property string $finish_reason
 * @property int $step_count
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int|null $cache_write_input_tokens
 * @property int|null $cache_read_input_tokens
 * @property int|null $thought_tokens
 * @property string|null $input_cost_usd
 * @property string|null $output_cost_usd
 * @property string|null $total_cost_usd
 * @property array<string, mixed>|null $pricing_snapshot
 * @property array<string, mixed>|null $fx_snapshot
 * @property string|null $total_cost_jpy
 * @property int $duration_ms
 * @property string|null $request_id
 * @property bool $metadata_missing
 * @property string|null $failure_reason
 * @property CarbonImmutable $created_at
 *
 * @method static LlmCallLogFactory factory($count = null, $state = [])
 */
class LlmCallLog extends Model
{
    /** @use HasFactory<LlmCallLogFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * organization_id / user_id は ownership / actor キーのため $fillable から除外
     * (MassAssignmentSafetyTest)。書き込みは recordWithOrganization() が明示代入する。
     *
     * @var list<string>
     */
    protected $fillable = [
        'execution_id',
        'subject_type', 'subject_id',
        'prompt_class', 'prompt_template',
        'provider', 'model', 'finish_reason', 'step_count',
        'input_tokens', 'output_tokens',
        'cache_write_input_tokens', 'cache_read_input_tokens', 'thought_tokens',
        'input_cost_usd', 'output_cost_usd', 'total_cost_usd',
        'pricing_snapshot', 'fx_snapshot', 'total_cost_jpy',
        'duration_ms', 'request_id',
        'metadata_missing', 'failure_reason',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pricing_snapshot' => 'array',
            'fx_snapshot' => 'array',
            'metadata_missing' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 組織スコープで LLM call log を upsert する named recording method。
     *
     * organization_id / user_id は $fillable 外のため mass assignment では設定できない。
     * 本 method が `firstOrNew(['execution_id' => ...])` で row を引いた上で明示代入し、
     * 残属性を fill() で適用する単一窓口。同一 execution_id は last-write-wins backfill。
     *
     * @param  array<string, mixed>  $attributes  organization_id / user_id / execution_id を含まない属性配列
     */
    public static function recordWithOrganization(
        ?int $organizationId,
        ?int $userId,
        string $executionId,
        array $attributes,
    ): self {
        try {
            // 第一試行を savepoint (nested DB::transaction) で包む。pgsql では unique violation
            // (23505) が親 TX 全体を abort (25P02) するため、savepoint 無しだと recovery 経路の
            // SELECT が 25P02 で連鎖失敗する。nested transaction が savepoint を張り、violation 時は
            // savepoint まで rollback して親 TX を生存させる (RefreshDatabase の外側 TX 文脈でも安全)。
            return DB::transaction(fn (): self => self::writeRecord($organizationId, $userId, $executionId, $attributes));
        } catch (QueryException $e) {
            // firstOrNew(new) と save() の間に別プロセスが同 execution_id を挿入する TOCTOU race。
            // unique violation のみ捕捉し、既存行への update に fall back する
            // (last-write-wins backfill セマンティクスを維持)。他の QueryException は bubble させる。
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }

            return self::writeRecord($organizationId, $userId, $executionId, $attributes);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function writeRecord(?int $organizationId, ?int $userId, string $executionId, array $attributes): self
    {
        /** @var self $log */
        $log = self::query()->firstOrNew(['execution_id' => $executionId]);
        $log->fill($attributes);
        $log->organization_id = $organizationId;
        $log->user_id = $userId;
        $log->save();

        return $log;
    }

    /**
     * SQLSTATE 23505 (PostgreSQL) = unique_violation 専用コードなので確定。
     * 23000 (sqlite / MySQL) は汎用 integrity violation で FK / NOT NULL / CHECK
     * 違反等も含むため、message が unique 制約由来のときのみ unique 扱いに絞る。
     * 非 unique 制約違反を誤って fallback 経路に流すと本来の原因が握り潰される。
     */
    private static function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        if ($sqlState === '23505') {
            return true;
        }

        if ($sqlState === '23000') {
            $message = strtolower($e->getMessage());

            return str_contains($message, 'unique') || str_contains($message, 'duplicate');
        }

        return false;
    }
}

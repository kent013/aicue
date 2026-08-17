<?php

declare(strict_types=1);

use App\Enums\Idempotency\IdempotencyClaimStatus;
use App\Enums\Manual\AnalysisStep;
use App\Enums\Manual\RenderStep;
use App\Models\AnalysisJob;
use App\Models\RenderJob;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Tests\Support\InitialState\NullableStateColumnEntry;
use Tests\Support\InitialState\NullableStateColumnRegistry;
use Tests\Support\InitialState\NullInitialStateClass;

/*
 * Feature invariant: **NULL 自体が初期状態を表しうる列が全数分類されている** (deny-by-default)。
 *
 * SoT = devnotes/20260817-1309-todo-t212-initial-state-insert-v2/detailed-design.md
 * (lctl 台帳 feature `explicit-initial-state-on-insert` 正典 v2 / 裁定 AG-191 の不変条件 i10)。
 *
 * ★この gate が保証するもの:
 *   - NI-1: 実スキーマ由来の母集団と台帳が**両方向で集合一致**する。
 *     **登録済みの列に migration で DB 既定値を後付けすると、その列は母集団の条件
 *     (`default === null`) から外れて母集団から抜け、台帳側だけに残って赤くなる** —
 *     これが AG-191 が求めた「既定値の後付けを赤にするスキーマ pin」の本体であり、
 *     除外規則も CHECK 制約も足さずに母集団の定義そのものから出ている
 *   - NI-2: 同じ列の二重宣言が無く、根拠が 30 文字以上ある
 *   - NI-3: 空振り検知。母集団が 0 件でなく、(a) 時刻型・(b) 列挙 cast の各系統が
 *     それぞれ 1 件以上寄与し、走査した具象モデルの FQCN 一覧と表名の一覧、
 *     代表の cast 2 本が**現在値ちょうど**である
 *   - NI-4 / NI-5 / NI-6: 台帳の総件数・「初期状態の目印」の列一覧・「未確定」の列一覧を
 *     **現在値ちょうど**で pin する (無音で増減しない)
 *   - NI-7: 母集団から外した作成・更新時刻の列を 3 つの一覧 (モデル由来 / モデルを持たない表由来 /
 *     統合) すべてで完全一致 pin する (除外が無音で広がらない・件数据え置きの入れ替わりも通さない)
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **列の意味が区分どおりかは見ない**。機械が見るのは集合の一致と根拠の長さだけであり、
 *     区分が正しいかは人間のレビュー対象である
 *   - **母集団は時刻型と BackedEnum へ cast された列に限る**。nullable な文字列・数値・json・
 *     外部キーで「NULL = まだ」を表す列 (実例: billing_checkout_sessions.funding_choice /
 *     render_jobs.output_path / cuts.adopted_take_id) は母集団外であり、
 *     そこへ既定値を足しても**沈黙する**
 *   - **(b) はモデルの宣言に依存する**。app/Models にモデルを持たない表 (枠組み・外部パッケージ・
 *     中間表) の状態語彙の列は見えない。ただし cast を外す変更は台帳側が
 *     「実在しない登録」になって赤くなるので、**片方向は閉じている**
 *   - **(b) が拾う cast の形は 1 つだけ**である (文字列の cast で `enum_exists()` かつ
 *     `BackedEnum` 実装)。列挙の集まり (AsEnumCollection / AsEnumArrayObject)・
 *     引数付きの cast 文字列 (decimal:2)・Castable を実装する自前の cast・
 *     裏付けの値を持たない列挙 (BackedEnum でない enum) は**見ない**。
 *     これらの形で状態語彙を持つ列が現れたら、母集団の設計から見直すこと
 *   - **モデルから cast を読めるのは Laravel がコンストラクタで `casts()` を畳み込むから**である
 *     (`HasAttributes::initializeHasAttributes()` が `array_merge($this->casts, $this->casts())` を行い、
 *     `getCasts()` は `$this->casts` を返すだけ)。この畳み込みの実装が変われば (b) は静かに
 *     縮みうるため、**Laravel を更新したら本検査の前提を人手で再確認する**
 *     (ClaudeHooksWiringTest と同じ作法)。NI-3 のモデル FQCN 一覧・表名一覧の完全一致 pin で
 *     入れ替わりは赤くなるが、「全モデルの cast が一斉に空になる」形は一覧では捕まらない
 *     (系統別 1 件以上と代表の cast 2 本の pin が拾う)
 *   - **最初から既定値を持って生まれた列は母集団外**である (AGENTS.md ドメイン固有規約 1 (ii) /
 *     規約 2 と ScenarioWritePathInventoryTest の担当。同じ事実を 2 か所で検査しない)。
 *     新しい列を最初から既定値付きで足す変更には沈黙する
 *   - **既定値の中身は見ない**。`default === null` かどうかだけを見る
 *   - **CHECK 制約・部分一意索引・排他制約は使わない** (正典 i7 / AG-191)。
 *     列の組の整合や値域は保証しない
 *   - **Factory / Seeder は走査域外**である (家系の未決論点 q3。本裁定の範囲外)
 *   - 見るのは**現在のスキーマ**であり、`search_path` の健全性は前提であって保証ではない。
 *     S3 上の実体・ビュー・他スキーマの表は対象外である
 *   - **アプリが実際にその列の NULL を読んで分岐していることは確かめない**。
 *     区分 InitialStateMarker は人の宣言である
 */

/** 母集団に入れる時刻型 (pgsql の type_name)。 */
const NULL_INITIAL_STATE_TEMPORAL_TYPES = ['timestamp', 'timestamptz', 'date'];

/** 台帳の総件数 (cap ではなく exact-fit。増減したら必ずこの数字を書き換える)。 */
const NULL_INITIAL_STATE_COLUMN_COUNT = 59;

/**
 * 「初期状態の目印」区分の列 (現在値ちょうど。増えるときも減るときもここを書き換える)。
 */
const NULL_INITIAL_STATE_MARKER_COLUMNS = [
    'analysis_jobs.step',
    'api_keys.last_used_at',
    'api_keys.revoked_at',
    'billing_checkout_sessions.completed_at',
    'billing_checkout_sessions.pm_reuse_dispatched_at',
    'billing_notifications.failed_at',
    'billing_notifications.sent_at',
    'inquiries.closed_at',
    'notifications.read_at',
    'oauth_device_codes.last_polled_at',
    'oauth_device_codes.user_approved_at',
    'oauth_sessions.last_used_at',
    'oauth_sessions.revoked_at',
    'organization_invitations.accepted_at',
    'organization_invitations.revoked_at',
    'organizations.deleted_at',
    'organizations.free_plan_activated_at',
    'organizations.personal_declared_at',
    'organizations.signup_tickets_granted_at',
    'organizations.stripe_customer_redacted_at',
    'passkeys.last_used_at',
    'plan_prices.active_to',
    'plan_prices.synced_at',
    'render_jobs.error_code',
    'render_jobs.step',
    'stripe_webhook_events.processed_at',
    'stripe_webhook_events.recovery_reason',
    'subscriptions.past_due_since',
    'takes.downloaded_at',
    'ticket_auto_recharge_attempts.resolved_at',
    'ticket_auto_recharges.disabled_reason',
    'ticket_checkout_sessions.completed_at',
    'ticket_volume_prices.active_to',
    'ticket_volume_prices.synced_at',
    'users.deletion_purge_after',
    'users.deletion_requested_at',
    'users.two_factor_confirmed_at',
];

/**
 * 「未確定」区分の列 (現在値ちょうど。未確定を無音で増やさないための pin)。
 */
const NULL_INITIAL_STATE_UNDECIDED_COLUMNS = [];

/**
 * 母集団から外した作成・更新時刻の列 (**モデルの宣言由来**。現在値ちょうど)。
 */
const NULL_INITIAL_STATE_EXCLUDED_BY_MODEL = [
    'admin_users.created_at',
    'admin_users.updated_at',
    'analysis_jobs.created_at',
    'analysis_jobs.updated_at',
    'api_keys.created_at',
    'api_keys.updated_at',
    'billing_checkout_sessions.created_at',
    'billing_checkout_sessions.updated_at',
    'billing_notifications.created_at',
    'billing_notifications.updated_at',
    'categories.created_at',
    'categories.updated_at',
    'custom_teams.created_at',
    'custom_teams.updated_at',
    'cuts.created_at',
    'cuts.updated_at',
    'email_suppressions.created_at',
    'email_suppressions.updated_at',
    'idempotency_keys.created_at',
    'inquiries.created_at',
    'inquiries.updated_at',
    'items.created_at',
    'items.updated_at',
    'model_audits.created_at',
    'model_audits.updated_at',
    'oauth_sessions.created_at',
    'oauth_sessions.updated_at',
    'organization_invitations.created_at',
    'organization_invitations.updated_at',
    'organization_quotas.created_at',
    'organization_quotas.updated_at',
    'organizations.created_at',
    'organizations.updated_at',
    'passkeys.created_at',
    'passkeys.updated_at',
    'permissions.created_at',
    'permissions.updated_at',
    'plan_prices.created_at',
    'plan_prices.updated_at',
    'plans.created_at',
    'plans.updated_at',
    'projects.created_at',
    'projects.updated_at',
    'render_jobs.created_at',
    'render_jobs.updated_at',
    'roles.created_at',
    'roles.updated_at',
    'security_audit_events.created_at',
    'security_audit_events.updated_at',
    'social_accounts.created_at',
    'social_accounts.updated_at',
    'source_documents.created_at',
    'source_documents.updated_at',
    'stripe_webhook_events.created_at',
    'stripe_webhook_events.updated_at',
    'subscriptions.created_at',
    'subscriptions.updated_at',
    'take_upload_reservations.created_at',
    'take_upload_reservations.updated_at',
    'takes.created_at',
    'takes.updated_at',
    'teams.created_at',
    'teams.updated_at',
    'ticket_auto_recharge_attempts.created_at',
    'ticket_auto_recharge_attempts.updated_at',
    'ticket_auto_recharges.created_at',
    'ticket_auto_recharges.updated_at',
    'ticket_checkout_sessions.created_at',
    'ticket_checkout_sessions.updated_at',
    'ticket_reservations.created_at',
    'ticket_reservations.updated_at',
    'ticket_volume_prices.created_at',
    'ticket_volume_prices.updated_at',
    'users.created_at',
    'users.updated_at',
    'video_manuals.created_at',
    'video_manuals.updated_at',
];

/**
 * 母集団から外した作成・更新時刻の列 (**モデルを持たない表由来**。現在値ちょうど)。
 */
const NULL_INITIAL_STATE_EXCLUDED_BY_TABLELESS = [
    'notifications.created_at',
    'notifications.updated_at',
    'oauth_access_tokens.created_at',
    'oauth_access_tokens.updated_at',
    'oauth_clients.created_at',
    'oauth_clients.updated_at',
    'organization_user.created_at',
    'organization_user.updated_at',
    'password_reset_tokens.created_at',
    'project_members.created_at',
    'project_members.updated_at',
    'subscription_items.created_at',
    'subscription_items.updated_at',
];

/**
 * 走査対象の具象 Eloquent モデル (FQCN。現在値ちょうど)。
 */
const NULL_INITIAL_STATE_MODEL_CLASSES = [
    'App\Models\AdminUser',
    'App\Models\AnalysisJob',
    'App\Models\ApiKey',
    'App\Models\Billing\BillingCheckoutSession',
    'App\Models\Billing\BillingNotification',
    'App\Models\Billing\OrganizationQuota',
    'App\Models\Billing\Plan',
    'App\Models\Billing\PlanPrice',
    'App\Models\Billing\StripeWebhookEvent',
    'App\Models\Billing\Subscription',
    'App\Models\Billing\TicketAutoRecharge',
    'App\Models\Billing\TicketAutoRechargeAttempt',
    'App\Models\Billing\TicketCheckoutSession',
    'App\Models\Billing\TicketLedgerEntry',
    'App\Models\Billing\TicketReservation',
    'App\Models\Billing\TicketVolumePrice',
    'App\Models\Category',
    'App\Models\CustomTeam',
    'App\Models\Cut',
    'App\Models\EmailSuppression',
    'App\Models\IdempotencyKey',
    'App\Models\Inquiry',
    'App\Models\Item',
    'App\Models\LlmCallLog',
    'App\Models\McpIdempotencyKey',
    'App\Models\ModelAudit',
    'App\Models\OauthSession',
    'App\Models\Organization',
    'App\Models\OrganizationInvitation',
    'App\Models\Passkey',
    'App\Models\Permission',
    'App\Models\Project',
    'App\Models\RenderJob',
    'App\Models\Role',
    'App\Models\SecurityAuditEvent',
    'App\Models\SocialAccount',
    'App\Models\SourceDocument',
    'App\Models\Take',
    'App\Models\TakeUploadReservation',
    'App\Models\Team',
    'App\Models\User',
    'App\Models\VideoManual',
];

/**
 * 上記モデルから得た表名 (現在値ちょうど)。
 */
const NULL_INITIAL_STATE_MODEL_TABLES = [
    'admin_users',
    'analysis_jobs',
    'api_keys',
    'billing_checkout_sessions',
    'billing_notifications',
    'categories',
    'custom_teams',
    'cuts',
    'email_suppressions',
    'idempotency_keys',
    'inquiries',
    'items',
    'llm_call_logs',
    'mcp_idempotency_keys',
    'model_audits',
    'oauth_sessions',
    'organization_invitations',
    'organization_quotas',
    'organizations',
    'passkeys',
    'permissions',
    'plan_prices',
    'plans',
    'projects',
    'render_jobs',
    'roles',
    'security_audit_events',
    'social_accounts',
    'source_documents',
    'stripe_webhook_events',
    'subscriptions',
    'take_upload_reservations',
    'takes',
    'teams',
    'ticket_auto_recharge_attempts',
    'ticket_auto_recharges',
    'ticket_checkout_sessions',
    'ticket_ledger_entries',
    'ticket_reservations',
    'ticket_volume_prices',
    'users',
    'video_manuals',
];

/**
 * スキーマ照会の入口。
 *
 * **ファサードではなく具体の Builder を取る** — `Schema::` の docblock は
 * `array getTables(...)` としか書いておらず要素が mixed になる。
 * `Connection::getSchemaBuilder()` は `Illuminate\Database\Schema\Builder` を返し、
 * 実体側の shape 宣言がそのまま効く (**型を緩めて黙らせない**)。
 */
function nullInitialStateSchemaBuilder(): Builder
{
    return DB::connection()->getSchemaBuilder();
}

/**
 * 現在のスキーマの base table 名 (非修飾・sort 済み)。
 *
 * pgsql は引数なしだと全スキーマを返すため必ず現在のスキーマへ絞る。
 *
 * @return list<string>
 */
function nullInitialStateSchemaTableNames(): array
{
    $builder = nullInitialStateSchemaBuilder();
    $names = array_map(
        static fn (array $table): string => $table['name'],
        $builder->getTables($builder->getCurrentSchemaName()),
    );
    sort($names);

    return array_values($names);
}

/**
 * 表ごとの列定義 (Schema API の**生の戻り**。正規化は別の純関数が行う)。
 *
 * @return array<string, list<array<string, mixed>>>
 */
function nullInitialStateRawColumns(): array
{
    /** @var array<string, list<array<string, mixed>>>|null $cache */
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $builder = nullInitialStateSchemaBuilder();
    $schema = $builder->getCurrentSchemaName();

    $map = [];
    foreach (nullInitialStateSchemaTableNames() as $table) {
        $map[$table] = array_values($builder->getColumns($schema.'.'.$table));
    }
    $cache = $map;

    return $map;
}

/**
 * Schema API の戻りの正規化 (**純関数**。キーの存在を仮定せず fail-closed で受ける)。
 *
 * 必要なキーが欠けている / 型が想定外の要素は**適合と判定せず** problems へ積む
 * (正典 i6 の「走査で証明できない受け手は適合と判定せず未解決として扱う」)。
 * 失敗メッセージは **`name` 自体が欠けていても成立する形**にしてある
 * (表名 / 要素の位置 / 欠けているキー / 実際にあるキーの 4 つを出す)。
 *
 * @param  array<string, list<array<string, mixed>>>  $rawByTable
 * @return array{columns: array<string, list<array{name: string, type_name: string, nullable: bool,
 *          default: string|null, auto_increment: bool, generation: array<string, mixed>|null}>>,
 *          problems: list<string>}
 */
function nullInitialStateNormalizeColumns(array $rawByTable): array
{
    $required = ['name', 'type_name', 'nullable', 'default', 'auto_increment', 'generation'];

    $columns = [];
    $problems = [];

    foreach ($rawByTable as $table => $rawColumns) {
        $normalized = [];
        foreach ($rawColumns as $index => $raw) {
            $missing = array_values(array_filter(
                $required,
                static fn (string $key): bool => ! array_key_exists($key, $raw),
            ));
            $actual = array_keys($raw);
            $shown = isset($raw['name']) && is_string($raw['name']) ? $raw['name'] : '取得できず';

            if ($missing !== []) {
                $problems[] = sprintf(
                    '%s columns[%d] (列名: %s): 欠けているキー = %s / 実際のキー = %s',
                    $table,
                    $index,
                    $shown,
                    implode(', ', $missing),
                    $actual === [] ? '(無し)' : implode(', ', $actual),
                );

                continue;
            }

            $badTypes = [];
            if (! is_string($raw['name'])) {
                $badTypes[] = 'name';
            }
            if (! is_string($raw['type_name'])) {
                $badTypes[] = 'type_name';
            }
            if (! is_bool($raw['nullable'])) {
                $badTypes[] = 'nullable';
            }
            if ($raw['default'] !== null && ! is_scalar($raw['default'])) {
                $badTypes[] = 'default';
            }
            if (! is_bool($raw['auto_increment'])) {
                $badTypes[] = 'auto_increment';
            }
            if ($raw['generation'] !== null && ! is_array($raw['generation'])) {
                $badTypes[] = 'generation';
            }

            if ($badTypes !== []) {
                $problems[] = sprintf(
                    '%s columns[%d] (列名: %s): 型が想定外のキー = %s / 実際のキー = %s',
                    $table,
                    $index,
                    $shown,
                    implode(', ', $badTypes),
                    $actual === [] ? '(無し)' : implode(', ', $actual),
                );

                continue;
            }

            /** @var string $name */
            $name = $raw['name'];
            /** @var string $typeName */
            $typeName = $raw['type_name'];
            /** @var array<string, mixed>|null $generation */
            $generation = $raw['generation'];

            $normalized[] = [
                'name' => $name,
                'type_name' => $typeName,
                'nullable' => (bool) $raw['nullable'],
                'default' => $raw['default'] === null ? null : (string) $raw['default'],
                'auto_increment' => (bool) $raw['auto_increment'],
                'generation' => $generation,
            ];
        }
        $columns[$table] = $normalized;
    }

    sort($problems);

    return ['columns' => $columns, 'problems' => array_values($problems)];
}

/**
 * その cast 宣言が「裏付けの値を持つ列挙への cast」か (**純関数**)。
 *
 * 拾うのは **1 つの形だけ**である — 文字列で、`:` を含まず、`enum_exists()` が真で、
 * `BackedEnum` を実装するもの。引数付き cast (`decimal:2`) / 列挙の集まり
 * (`AsEnumCollection:...`) / `Castable` 実装クラス / 裏付けの値を持たない列挙は**入らない**。
 */
function nullInitialStateIsBackedEnumCast(mixed $cast): bool
{
    if (! is_string($cast) || $cast === '') {
        return false;
    }
    if (str_contains($cast, ':')) {
        return false;
    }
    if (! enum_exists($cast)) {
        return false;
    }

    return is_subclass_of($cast, BackedEnum::class, true);
}

/**
 * app/Models 配下の具象 Eloquent モデルから読み取る事実。
 *
 * **クラス名から表名を推測しない** (各インスタンスの `getTable()` を引く)。
 * **`newInstanceWithoutConstructor()` は使えない** — Laravel は `casts()` の戻り値を
 * `HasAttributes::initializeHasAttributes()` でコンストラクタからのみ畳み込むため、
 * コンストラクタを飛ばすと本リポジトリの全モデルの cast が空になり (b) の系統が
 * 静かに 0 件へ縮む。インスタンス化の失敗は**握り潰さずその場で fail** させる。
 *
 * @return array{models: list<string>, tables: list<string>, enumCasts: array<string, list<string>>,
 *          enumCastsByModel: array<string, array<string, string>>,
 *          lifecycle: array<string, list<string>>}
 */
function nullInitialStateModelFacts(): array
{
    /** @var array{models: list<string>, tables: list<string>, enumCasts: array<string, list<string>>, enumCastsByModel: array<string, array<string, string>>, lifecycle: array<string, list<string>>}|null $cache */
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $root = app_path('Models');
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $files[] = $file->getPathname();
    }
    sort($files);

    $models = [];
    $tables = [];
    $enumCasts = [];
    $enumCastsByModel = [];
    $lifecycle = [];

    foreach ($files as $path) {
        $relative = substr($path, strlen($root) + 1);
        $fqcn = 'App\\Models\\'.str_replace('/', '\\', substr($relative, 0, -4));

        if (! class_exists($fqcn)) {
            // trait / interface / 名前が対応しないファイルは母集団に入れない
            continue;
        }
        $reflection = new ReflectionClass($fqcn);
        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        try {
            $instance = new $fqcn;
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf(
                '%s をインスタンス化できませんでした (例外の型: %s)。'
                .'モデルの cast を読めないと (b) の系統が静かに 0 件へ縮むため、握り潰さず落としています。',
                $fqcn,
                $e::class,
            ), 0, $e);
        }

        if (! $instance instanceof Model) {
            continue;
        }

        $models[] = $fqcn;
        $table = $instance->getTable();
        $tables[] = $table;

        foreach ($instance->getCasts() as $column => $cast) {
            if (! nullInitialStateIsBackedEnumCast($cast)) {
                continue;
            }
            /** @var string $cast */
            // 同じ表を指すモデルが複数あるときは**和集合**を取る (見落としの出ない側へ倒す)
            $enumCasts[$table][] = (string) $column;
            $enumCastsByModel[$fqcn][(string) $column] = $cast;
        }

        // 作成・更新時刻の除外は**そのモデルが宣言している列名にだけ**効かせる
        if ($instance->usesTimestamps()) {
            foreach ([$instance->getCreatedAtColumn(), $instance->getUpdatedAtColumn()] as $column) {
                if (is_string($column) && $column !== '') {
                    $lifecycle[$table][] = $column;
                }
            }
        }
    }

    sort($models);
    $tables = array_values(array_unique($tables));
    sort($tables);
    foreach ($enumCasts as $table => $columns) {
        $columns = array_values(array_unique($columns));
        sort($columns);
        $enumCasts[$table] = $columns;
    }
    foreach ($lifecycle as $table => $columns) {
        $columns = array_values(array_unique($columns));
        sort($columns);
        $lifecycle[$table] = $columns;
    }
    ksort($enumCasts);
    ksort($enumCastsByModel);
    ksort($lifecycle);

    $cache = [
        'models' => array_values($models),
        'tables' => $tables,
        'enumCasts' => $enumCasts,
        'enumCastsByModel' => $enumCastsByModel,
        'lifecycle' => $lifecycle,
    ];

    return $cache;
}

/**
 * モデルを持たない表の作成・更新時刻の列名 (**純関数**)。
 *
 * 枠組み・外部パッケージ・中間表には `getCreatedAtColumn()` を尋ねる相手がいないため、
 * ここだけは枠組みの既定名との一致で外す。**外れた列一覧は NI-7 が完全一致で pin する**。
 *
 * @param  list<string>  $allTables
 * @param  list<string>  $tablesWithModel
 * @return array<string, list<string>>
 */
function nullInitialStateTablelessLifecycleColumns(array $allTables, array $tablesWithModel): array
{
    $map = [];
    foreach ($allTables as $table) {
        if (in_array($table, $tablesWithModel, true)) {
            continue;
        }
        $map[$table] = [Model::CREATED_AT, Model::UPDATED_AT];
    }

    return $map;
}

/**
 * 母集団の算出 (**純関数** = 負のコントロールから合成入力で直接呼べる)。
 *
 * 残す列の条件は **nullable かつ DB 既定値なし、生成列でない、identity でない**。
 * ここから (a) 時刻型 と (b) BackedEnum へ cast された列 の和集合を母集団にする。
 * **既定値を足した列はこの条件から外れて母集団から抜ける** = NI-1 が赤くなる (AG-191 の pin)。
 *
 * @param  array<string, list<array{name: string, type_name: string, nullable: bool,
 *          default: string|null, auto_increment: bool, generation: array<string, mixed>|null}>>  $columnsByTable
 * @param  array<string, list<string>>  $enumCastColumns  表名 => BackedEnum へ cast された列名
 * @param  array<string, list<string>>  $modelLifecycleColumns  表名 => モデルが宣言した作成 / 更新時刻の列名
 * @param  array<string, list<string>>  $tablelessLifecycleColumns  表名 => モデルを持たない表の既定名
 * @return array{population: list<string>, temporal: list<string>, enumCast: list<string>,
 *          excludedByModel: list<string>, excludedByTableless: list<string>,
 *          excludedLifecycle: list<string>}
 */
function nullInitialStatePopulation(
    array $columnsByTable,
    array $enumCastColumns,
    array $modelLifecycleColumns,
    array $tablelessLifecycleColumns,
): array {
    $population = [];
    $temporal = [];
    $enumCast = [];
    $excludedByModel = [];
    $excludedByTableless = [];

    foreach ($columnsByTable as $table => $columns) {
        foreach ($columns as $column) {
            if ($column['nullable'] !== true) {
                continue;
            }
            if ($column['default'] !== null) {
                continue;
            }
            if ($column['generation'] !== null) {
                continue;
            }
            if ($column['auto_increment'] === true) {
                continue;
            }

            $key = $table.'.'.$column['name'];
            $isTemporal = in_array($column['type_name'], NULL_INITIAL_STATE_TEMPORAL_TYPES, true);
            $isEnumCast = in_array($column['name'], $enumCastColumns[$table] ?? [], true);

            // 作成・更新時刻の除外は**時刻型の列にだけ**当てる。
            // 除外の一覧 (NI-7 が pin する) の意味を「(a) に入るはずだったのに外した列」に
            // 揃えるためで、判定を外側へ出すと時刻型ですらない同名の列まで一覧へ混ざる。
            // 除外に当たった列は母集団から**丸ごと**外れる ((b) の側にも入らない)。
            if ($isTemporal) {
                if (in_array($column['name'], $modelLifecycleColumns[$table] ?? [], true)) {
                    $excludedByModel[] = $key;

                    continue;
                }
                if (in_array($column['name'], $tablelessLifecycleColumns[$table] ?? [], true)) {
                    $excludedByTableless[] = $key;

                    continue;
                }
                $temporal[] = $key;
            }

            if ($isEnumCast) {
                $enumCast[] = $key;
            }

            if ($isTemporal || $isEnumCast) {
                $population[] = $key;
            }
        }
    }

    $population = array_values(array_unique($population));
    $excludedLifecycle = array_values(array_unique(array_merge($excludedByModel, $excludedByTableless)));

    sort($population);
    sort($temporal);
    sort($enumCast);
    sort($excludedByModel);
    sort($excludedByTableless);
    sort($excludedLifecycle);

    return [
        'population' => $population,
        'temporal' => array_values($temporal),
        'enumCast' => array_values($enumCast),
        'excludedByModel' => array_values($excludedByModel),
        'excludedByTableless' => array_values($excludedByTableless),
        'excludedLifecycle' => $excludedLifecycle,
    ];
}

/**
 * 母集団と台帳の突合 (**純関数**)。
 *
 * @param  list<string>  $population  '表名.列名'
 * @param  list<NullableStateColumnEntry>  $entries
 * @return array{unclassified: list<string>, phantom: list<string>, duplicated: list<string>}
 */
function nullInitialStateClassify(array $population, array $entries): array
{
    $declared = [];
    $duplicated = [];
    foreach ($entries as $entry) {
        $key = $entry->key();
        if (array_key_exists($key, $declared)) {
            $duplicated[] = $key;

            continue;
        }
        $declared[$key] = true;
    }

    $declaredKeys = array_keys($declared);
    $unclassified = array_values(array_diff($population, $declaredKeys));
    $phantom = array_values(array_diff($declaredKeys, $population));

    sort($unclassified);
    sort($phantom);
    sort($duplicated);

    return [
        'unclassified' => array_values($unclassified),
        'phantom' => array_values($phantom),
        'duplicated' => array_values(array_unique($duplicated)),
    ];
}

/**
 * 実スキーマ + モデル宣言から母集団を組む (副作用のある入口)。
 *
 * @return array{population: list<string>, temporal: list<string>, enumCast: list<string>,
 *          excludedByModel: list<string>, excludedByTableless: list<string>,
 *          excludedLifecycle: list<string>, problems: list<string>}
 */
function nullInitialStateActualPopulation(): array
{
    $normalized = nullInitialStateNormalizeColumns(nullInitialStateRawColumns());
    $facts = nullInitialStateModelFacts();

    $result = nullInitialStatePopulation(
        $normalized['columns'],
        $facts['enumCasts'],
        $facts['lifecycle'],
        nullInitialStateTablelessLifecycleColumns(nullInitialStateSchemaTableNames(), $facts['tables']),
    );

    return [...$result, 'problems' => $normalized['problems']];
}

/**
 * 指定区分の列キー (sort 済み)。
 *
 * @param  list<NullableStateColumnEntry>  $entries
 * @return list<string>
 */
function nullInitialStateColumnsOfClass(array $entries, NullInitialStateClass $class): array
{
    $keys = array_values(array_map(
        static fn (NullableStateColumnEntry $entry): string => $entry->key(),
        array_filter($entries, static fn (NullableStateColumnEntry $entry): bool => $entry->class === $class),
    ));
    sort($keys);

    return $keys;
}

test('NI-1: 実スキーマ由来の母集団と台帳が両方向で集合一致する', function (): void {
    $actual = nullInitialStateActualPopulation();
    $result = nullInitialStateClassify($actual['population'], NullableStateColumnRegistry::entries());

    expect($result['unclassified'])->toBe([],
        'NULL が初期状態を表しうる列のうち、区分が宣言されていないものを検出しました。'
        .'tests/Support/InitialState/NullableStateColumnRegistry.php へ区分と 30 文字以上の根拠付きで '
        .'1 行足してください (決められないなら undecided で構いません): '
        .implode(', ', $result['unclassified']));

    expect($result['phantom'])->toBe([],
        '台帳にある列が実スキーマ由来の母集団に見当たりません。'
        .'**この列に migration で DB 既定値を足していませんか。足すと新しい行は生まれた瞬間に '
        .'「済んだ」ことになります** (既定値を持つ列は母集団の条件から外れます)。'
        .'列を落とした / cast を外した場合は台帳からも消してください: '
        .implode(', ', $result['phantom']));
});

test('NI-2: 同じ列の二重宣言が無く、根拠が 30 文字以上ある', function (): void {
    $entries = NullableStateColumnRegistry::entries();
    $actual = nullInitialStateActualPopulation();

    $result = nullInitialStateClassify($actual['population'], $entries);
    expect($result['duplicated'])->toBe([],
        '同じ列が台帳に 2 回以上宣言されています (後の 1 件で上書きされる形の消失を防ぐため禁止): '
        .implode(', ', $result['duplicated']));

    $tooShort = [];
    foreach ($entries as $entry) {
        if (mb_strlen($entry->rationale) < NullableStateColumnEntry::RATIONALE_MIN_LENGTH) {
            $tooShort[] = $entry->key();
        }
    }

    expect($tooShort)->toBe([],
        '根拠が短すぎます (30 文字以上。「同上」「N/A」のような形だけの記述を弾くため): '
        .implode(', ', $tooShort));
});

test('NI-3: 空振り検知 (母集団・系統・モデル一覧・代表の cast が現在値ちょうど)', function (): void {
    $actual = nullInitialStateActualPopulation();

    expect($actual['problems'])->toBe([],
        'Schema API の戻りが想定の形をしていません (適合と判定せず落としています): '
        .implode(' / ', $actual['problems']));

    expect($actual['population'])->not->toBe([],
        '母集団が 0 件になりました。抽出が壊れています (0 件を合格にしません = 正典 i5)。');
    expect($actual['temporal'])->not->toBe([],
        '(a) 時刻型の系統が 0 件になりました。抽出が静かに縮んでいます。');
    expect($actual['enumCast'])->not->toBe([],
        '(b) 列挙 cast の系統が 0 件になりました。モデルからの cast 読み取りが壊れています。');

    $facts = nullInitialStateModelFacts();

    expect($facts['models'])->toBe(NULL_INITIAL_STATE_MODEL_CLASSES,
        '走査した具象 Eloquent モデルの一覧が変わりました。モデルを足した / 消したなら '
        .'NULL_INITIAL_STATE_MODEL_CLASSES を書き換えてください (件数だけの pin では入れ替わりが素通りします)。');

    expect($facts['tables'])->toBe(NULL_INITIAL_STATE_MODEL_TABLES,
        'モデルから得た表名の一覧が変わりました。NULL_INITIAL_STATE_MODEL_TABLES を書き換えてください。');

    // 代表の cast 2 本を**値ごと** pin する。モデル一覧が変わらないまま
    // casts() の畳み込み機序だけが壊れる形は、一覧でも件数でも捕まらないため。
    expect($facts['enumCastsByModel'][AnalysisJob::class]['step'] ?? null)->toBe(AnalysisStep::class,
        'AnalysisJob の step の cast を読めていません。Laravel が casts() をコンストラクタで '
        .'畳み込む前提が崩れた可能性があります (本検査の docblock を読み直してください)。');
    expect($facts['enumCastsByModel'][RenderJob::class]['step'] ?? null)->toBe(RenderStep::class,
        'RenderJob の step の cast を読めていません。Laravel が casts() をコンストラクタで '
        .'畳み込む前提が崩れた可能性があります (本検査の docblock を読み直してください)。');
});

test('NI-4: 台帳の総件数が現在値ちょうどである', function (): void {
    expect(NullableStateColumnRegistry::entries())->toHaveCount(NULL_INITIAL_STATE_COLUMN_COUNT,
        '台帳の件数が変わりました。列を足した / 消したなら NULL_INITIAL_STATE_COLUMN_COUNT も '
        .'書き換えてください。');
});

test('NI-5: 「初期状態の目印」区分の列一覧が現在値ちょうどである', function (): void {
    $markers = nullInitialStateColumnsOfClass(
        NullableStateColumnRegistry::entries(),
        NullInitialStateClass::InitialStateMarker,
    );
    $expected = NULL_INITIAL_STATE_MARKER_COLUMNS;
    sort($expected);

    expect($markers)->toBe($expected,
        '「初期状態の目印」の列一覧が変わりました。増えるときも減るときも '
        .'NULL_INITIAL_STATE_MARKER_COLUMNS を書き換えてください '
        .'(この一族が無音で減るのが一番危ないので pin しています)。');
});

test('NI-6: 「未確定」区分の列一覧が現在値ちょうどである', function (): void {
    $undecided = nullInitialStateColumnsOfClass(
        NullableStateColumnRegistry::entries(),
        NullInitialStateClass::Undecided,
    );
    $expected = NULL_INITIAL_STATE_UNDECIDED_COLUMNS;
    sort($expected);

    expect($undecided)->toBe($expected,
        '未確定の列一覧が変わりました。NULL_INITIAL_STATE_UNDECIDED_COLUMNS を書き換えてください '
        .'(未確定を無音で増やさないための pin です)。');
});

test('NI-7: 母集団から外した作成・更新時刻の列が 3 つの一覧すべてで現在値ちょうどである', function (): void {
    $actual = nullInitialStateActualPopulation();

    $byModel = NULL_INITIAL_STATE_EXCLUDED_BY_MODEL;
    sort($byModel);
    $byTableless = NULL_INITIAL_STATE_EXCLUDED_BY_TABLELESS;
    sort($byTableless);
    $union = array_values(array_unique(array_merge($byModel, $byTableless)));
    sort($union);

    expect($actual['excludedByModel'])->toBe($byModel,
        'モデルの宣言 (usesTimestamps + getCreatedAtColumn / getUpdatedAtColumn) 由来の除外一覧が '
        .'変わりました。NULL_INITIAL_STATE_EXCLUDED_BY_MODEL を書き換えてください。');
    expect($actual['excludedByTableless'])->toBe($byTableless,
        'モデルを持たない表の既定名由来の除外一覧が変わりました。'
        .'NULL_INITIAL_STATE_EXCLUDED_BY_TABLELESS を書き換えてください '
        .'(この経路だけが列名の一致で外しているため、無音で広がらないよう pin しています)。');
    expect($actual['excludedLifecycle'])->toBe($union,
        '統合後の除外一覧が 2 つの内訳と食い違っています (件数を保ったままの入れ替わりを通さないため)。');
});

test('NC-1: 台帳に無い列を母集団へ足すと NI-1 の未分類が点灯する', function (): void {
    $entries = [
        NullableStateColumnEntry::initialStateMarker(
            'organization_invitations',
            'accepted_at',
            '招待が受諾されるまで NULL で、受諾の瞬間に一度だけ時刻が入る。NULL のまま = 未受諾である',
        ),
    ];

    $result = nullInitialStateClassify(
        ['organization_invitations.accepted_at', 'ghost_table.ghost_at'],
        $entries,
    );

    expect($result['unclassified'])->toBe(['ghost_table.ghost_at']);
    expect($result['phantom'])->toBe([]);
});

test('NC-2: 実在しない列を台帳へ足すと NI-1 の実在しない登録が点灯する', function (): void {
    $entries = [
        NullableStateColumnEntry::initialStateMarker(
            'organization_invitations',
            'accepted_at',
            '招待が受諾されるまで NULL で、受諾の瞬間に一度だけ時刻が入る。NULL のまま = 未受諾である',
        ),
        NullableStateColumnEntry::initialStateMarker(
            'organization_invitations',
            'removed_at',
            '既に落とした列。台帳から消し忘れた幽霊登録を再現するための合成入力である',
        ),
    ];

    $result = nullInitialStateClassify(['organization_invitations.accepted_at'], $entries);

    expect($result['phantom'])->toBe(['organization_invitations.removed_at']);
    expect($result['unclassified'])->toBe([]);
});

test('NC-3: 登録済みの列に DB 既定値が付くと母集団から抜けて NI-1 が点灯する', function (): void {
    $entries = [
        NullableStateColumnEntry::initialStateMarker(
            'organization_invitations',
            'accepted_at',
            '招待が受諾されるまで NULL で、受諾の瞬間に一度だけ時刻が入る。NULL のまま = 未受諾である',
        ),
    ];

    $columnOf = static fn (?string $default): array => [
        'organization_invitations' => [
            [
                'name' => 'accepted_at',
                'type_name' => 'timestamp',
                'nullable' => true,
                'default' => $default,
                'auto_increment' => false,
                'generation' => null,
            ],
        ],
    ];

    // 既定値が無いうちは母集団に入り、集合は一致する
    $before = nullInitialStatePopulation($columnOf(null), [], [], []);
    expect($before['population'])->toBe(['organization_invitations.accepted_at']);
    expect(nullInitialStateClassify($before['population'], $entries)['phantom'])->toBe([]);

    // 既定値の**表現ゆれ**に依存していないこと (判定は「default が null でない」ことだけ)
    $defaults = ['now()', 'CURRENT_TIMESTAMP', "'pending'", "'pending'::character varying", '0', ''];
    foreach ($defaults as $default) {
        $after = nullInitialStatePopulation($columnOf($default), [], [], []);

        expect($after['population'])->toBe([], sprintf('既定値 %s で母集団から抜けるべきです', $default));
        expect(nullInitialStateClassify($after['population'], $entries)['phantom'])
            ->toBe(['organization_invitations.accepted_at'],
                sprintf('既定値 %s で「実在しない登録」が点灯するべきです', $default));
    }

    // Schema API が既定値を**文字列でない scalar** で返す形も同じ経路を通る
    // (正規化が文字列へ畳んでも「null でない」ことは変わらない = 判定は中身を見ていない)。
    $rawWithIntDefault = [
        'organization_invitations' => [
            [
                'name' => 'accepted_at',
                'type_name' => 'timestamp',
                'nullable' => true,
                'default' => 0,
                'auto_increment' => false,
                'generation' => null,
            ],
        ],
    ];
    $normalized = nullInitialStateNormalizeColumns($rawWithIntDefault);
    expect($normalized['problems'])->toBe([]);
    $afterInt = nullInitialStatePopulation($normalized['columns'], [], [], []);
    expect($afterInt['population'])->toBe([]);
    expect(nullInitialStateClassify($afterInt['population'], $entries)['phantom'])
        ->toBe(['organization_invitations.accepted_at']);
});

test('NC-4: 作成・更新時刻の除外は列名だけでは効かない', function (): void {
    $columns = [
        'widgets' => [
            [
                'name' => 'created_at',
                'type_name' => 'timestamp',
                'nullable' => true,
                'default' => null,
                'auto_increment' => false,
                'generation' => null,
            ],
        ],
    ];
    // widgets はモデルを持つ表なので、モデルを持たない表向けの既定名は当たらない
    $tableless = nullInitialStateTablelessLifecycleColumns(['widgets'], ['widgets']);
    expect($tableless)->toBe([]);

    // (1) usesTimestamps() が false のモデル = 宣言が空
    $notUsingTimestamps = nullInitialStatePopulation($columns, [], [], $tableless);
    expect($notUsingTimestamps['population'])->toBe(['widgets.created_at']);
    expect($notUsingTimestamps['excludedByModel'])->toBe([]);
    expect($notUsingTimestamps['excludedByTableless'])->toBe([]);

    // (2) 作成時刻の列名を差し替えたモデル = 宣言が別名
    $renamed = nullInitialStatePopulation($columns, [], ['widgets' => ['created_on', 'updated_on']], $tableless);
    expect($renamed['population'])->toBe(['widgets.created_at']);
    expect($renamed['excludedByModel'])->toBe([]);

    // (3) 宣言どおりの列名なら外れる (正のコントロール)
    $declared = nullInitialStatePopulation($columns, [], ['widgets' => ['created_at', 'updated_at']], $tableless);
    expect($declared['population'])->toBe([]);
    expect($declared['excludedByModel'])->toBe(['widgets.created_at']);

    // (4) モデルを持たない表なら枠組みの既定名で外れ、その旨が内訳に出る
    $withoutModel = nullInitialStatePopulation(
        $columns,
        [],
        [],
        nullInitialStateTablelessLifecycleColumns(['widgets'], []),
    );
    expect($withoutModel['population'])->toBe([]);
    expect($withoutModel['excludedByTableless'])->toBe(['widgets.created_at']);
    expect($withoutModel['excludedLifecycle'])->toBe(['widgets.created_at']);
});

test('NC-5: 同じ列を 2 回宣言すると NI-2 の二重宣言が点灯する', function (): void {
    $entries = [
        NullableStateColumnEntry::initialStateMarker(
            'organization_invitations',
            'accepted_at',
            '招待が受諾されるまで NULL で、受諾の瞬間に一度だけ時刻が入る。NULL のまま = 未受諾である',
        ),
        NullableStateColumnEntry::setAtCreation(
            'organization_invitations',
            'accepted_at',
            '同じ列をもう一度宣言して二重登録の検出を確かめるための合成入力である',
        ),
    ];

    $result = nullInitialStateClassify(['organization_invitations.accepted_at'], $entries);

    expect($result['duplicated'])->toBe(['organization_invitations.accepted_at']);
});

test('NC-6: 母集団が空になる抽出は合格にしない', function (): void {
    // 「nullable でない」「既定値がある」「生成列」「identity」のいずれかで全滅する形
    $columns = [
        'widgets' => [
            [
                'name' => 'id',
                'type_name' => 'int8',
                'nullable' => false,
                'default' => null,
                'auto_increment' => true,
                'generation' => null,
            ],
            [
                'name' => 'closed_at',
                'type_name' => 'timestamp',
                'nullable' => false,
                'default' => null,
                'auto_increment' => false,
                'generation' => null,
            ],
            [
                'name' => 'settled_at',
                'type_name' => 'timestamp',
                'nullable' => true,
                'default' => 'now()',
                'auto_increment' => false,
                'generation' => null,
            ],
            [
                'name' => 'computed_at',
                'type_name' => 'timestamp',
                'nullable' => true,
                'default' => null,
                'auto_increment' => false,
                'generation' => ['type' => 'stored', 'expression' => 'now()'],
            ],
        ],
    ];

    $result = nullInitialStatePopulation($columns, [], [], []);

    // NI-3 が課している 3 つの非空条件がいずれも満たされない = この入力では NI-3 が落ちる。
    // **0 件を合格にしない** (正典 i5) ことを、条件そのものを合成入力で外して示す。
    expect($result['population'])->toBe([]);
    expect($result['temporal'])->toBe([]);
    expect($result['enumCast'])->toBe([]);
});

test('NC-7: 拾う cast は裏付けの値を持つ列挙への cast だけである', function (): void {
    // 拾う唯一の形
    expect(nullInitialStateIsBackedEnumCast(AnalysisStep::class))->toBeTrue();
    expect(nullInitialStateIsBackedEnumCast(RenderStep::class))->toBeTrue();

    // 拾わない 5 形
    foreach (['array', 'datetime', 'encrypted'] as $builtin) {
        expect(nullInitialStateIsBackedEnumCast($builtin))->toBeFalse(sprintf('%s は拾わない', $builtin));
    }
    expect(nullInitialStateIsBackedEnumCast('decimal:2'))->toBeFalse();
    expect(nullInitialStateIsBackedEnumCast(AsEnumCollection::class.':'.AnalysisStep::class))->toBeFalse();
    expect(nullInitialStateIsBackedEnumCast(AsArrayObject::class))->toBeFalse();
    expect(nullInitialStateIsBackedEnumCast(IdempotencyClaimStatus::class))->toBeFalse();

    // 文字列ですらない cast 宣言も拾わない
    expect(nullInitialStateIsBackedEnumCast(null))->toBeFalse();
    expect(nullInitialStateIsBackedEnumCast(['array']))->toBeFalse();

    // 母集団の側でも (b) が cast の宣言だけを見ていることを確かめる
    $columns = [
        'widgets' => [
            [
                'name' => 'step',
                'type_name' => 'varchar',
                'nullable' => true,
                'default' => null,
                'auto_increment' => false,
                'generation' => null,
            ],
            [
                'name' => 'note',
                'type_name' => 'varchar',
                'nullable' => true,
                'default' => null,
                'auto_increment' => false,
                'generation' => null,
            ],
        ],
    ];

    $result = nullInitialStatePopulation($columns, ['widgets' => ['step']], [], []);
    expect($result['population'])->toBe(['widgets.step']);
    expect($result['enumCast'])->toBe(['widgets.step']);
    expect($result['temporal'])->toBe([]);
});

test('NC-8: Schema API の戻りからキーが欠けていたら適合と判定せず落ちる', function (): void {
    $raw = [
        'users' => [
            [
                'name' => 'email_verified_at',
                'type_name' => 'timestamp',
                'nullable' => true,
                'default' => null,
                'auto_increment' => false,
                'generation' => null,
            ],
            // name そのものが欠けた要素 (もっとも厳しい形)
            [
                'type_name' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
        ],
    ];

    $normalized = nullInitialStateNormalizeColumns($raw);

    expect($normalized['problems'])->toHaveCount(1);
    expect($normalized['problems'][0])
        ->toContain('users columns[1]')
        ->toContain('列名: 取得できず')
        ->toContain('欠けているキー = name, auto_increment, generation')
        ->toContain('実際のキー = type_name, nullable, default');

    // 適合と判定しない = その要素は正規化後の母集団に現れない
    expect(nullInitialStatePopulation($normalized['columns'], [], [], [])['population'])
        ->toBe(['users.email_verified_at']);

    // 型が想定外の要素も同じく落ちる (fail-closed)
    $badType = nullInitialStateNormalizeColumns([
        'users' => [
            [
                'name' => 'email_verified_at',
                'type_name' => 'timestamp',
                'nullable' => 'yes',
                'default' => null,
                'auto_increment' => false,
                'generation' => null,
            ],
        ],
    ]);
    expect($badType['problems'])->toHaveCount(1);
    expect($badType['problems'][0])->toContain('型が想定外のキー = nullable');
    expect($badType['columns']['users'])->toBe([]);
});

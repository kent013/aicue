<?php

declare(strict_types=1);

namespace Tests\Support\Security;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * クラス起点の主キー同一性クエリ (直 fetch 候補) の裁定 inventory (単一 source of truth)。
 *
 * `NestedRouteDefenseInventory` と同じく静的クラスに置く
 * (Pest のファイル読み込み順に依存する global 関数にしない)。
 *
 * **母集団は `app/**` + `routes/*.php` の全層**。層で絞らない。
 * entrypoint 層 (`app/Http` + `app/Mcp`) に絞ると「Controller が scalar id を Service に渡し
 * Service 側で global fetch する」という明白な抜け道が残るため。
 * ノイズは走査器の provenance フィルタ (識別子引数が解決済みモデル由来のものを外す) で落とす。
 */
final class DirectFetchInventory
{
    /** model を持たないが security-sensitive なテーブル (v1 は空。Passport 内部の `oauth_*` は入れない)。 */
    private const EXPLICIT_TABLES = [];

    /**
     * 走査対象 (リポジトリルート相対)。
     *
     * @return list<string>
     */
    public static function scannedPaths(): array
    {
        return ['app', 'routes'];
    }

    /**
     * `App\Models\*` に対応するテーブル名 (`DB::table(...)` 起点の対象を絞る)。
     *
     * ハードコードすると新しいモデルを足したときに
     * `DB::table('new_things')->where('id', $payloadId)` が静かに母集団から漏れるため、
     * `app/Models/` の具象モデルを列挙して `getTable()` から導出する。
     *
     * @return list<string>
     */
    public static function modelTables(): array
    {
        /** @var list<string> $tables */
        $tables = self::EXPLICIT_TABLES;

        foreach (Finder::create()->files()->in(base_path('app/Models'))->name('*.php') as $file) {
            $class = self::classOf($file);
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if (! $reflection->isInstantiable() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }
            // 通常の `new` はモデルの constructor 引数 / イベントに依存しうるため使わない
            $instance = $reflection->newInstanceWithoutConstructor();
            /** @var Model $instance */
            $tables[] = $instance->getTable();
        }

        return array_values(array_unique($tables));
    }

    /**
     * 走査対象全体から抽出した候補。
     *
     * @return list<PrimaryKeyStaticQueryCandidate>
     */
    public static function candidates(): array
    {
        $tables = self::modelTables();
        $candidates = [];

        foreach (self::sourceFiles() as $relativePath => $source) {
            foreach (PrimaryKeyStaticQueryScanner::candidates($source, $relativePath, $tables) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * 走査対象のソース (リポジトリ相対パス => 全文)。
     *
     * @return array<string, string>
     */
    public static function sourceFiles(): array
    {
        $sources = [];

        foreach (Finder::create()->files()->in(base_path('app'))->name('*.php')->sortByName() as $file) {
            $sources['app/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())] = $file->getContents();
        }
        foreach (Finder::create()->files()->in(base_path('routes'))->depth(0)->name('*.php')->sortByName() as $file) {
            $sources['routes/'.$file->getFilename()] = $file->getContents();
        }

        return $sources;
    }

    /**
     * 同一 fingerprint (path / scope / root / predicate / identity) の重複を人が確認済みの group。
     *
     * 重複があると ordinal 依存になり「裁定理由が別の候補へ横滑りする」余地が残るため、
     * 明示登録された group だけを許可する (v1 は実測 0 件)。
     *
     * @return list<string>
     */
    public static function reviewedDuplicateFingerprints(): array
    {
        return [];
    }

    /**
     * 動的列名 (`where($field, $value)`) を使うクラス起点 chain の inventory。
     *
     * 列名が字句的に確定しないため主キー同一性の候補にできない (走査器の範囲外) が、
     * 放置すると `$column = 'id'; User::query()->where($column, $payloadId);` で
     * gate を黙らせられる。実測 3 件と 0 件ではないため「0 件固定」ではなく
     * **明示 inventory + 双方向整合**で見張る。
     *
     * @return array<string, string> 記述子 => その形が安全である理由
     */
    public static function reviewedDynamicColumnPredicates(): array
    {
        return [
            'Http/Routing/MembershipScopedOrganizationBinder.php#bind#Organization.where:$field=>$value#1' => '$field は route の bindingFieldFor 由来で BINDABLE_FIELDS の allowlist を通っており、'
                    .'解決結果は同一 chain の membership スコープに閉じている',
            'Support/Billing/BillingNotificationRecorder.php#markSentBy#BillingNotification.where:$column=>$value#1' => '$column は呼び出し元がリテラルで渡す通知の dedup 列名で、request 由来の値ではない。'
                    .'BillingNotification はテナント資源でなく通知の送達記録である',
            'Support/Billing/BillingNotificationRecorder.php#markFailedReasonBy#BillingNotification.where:$column=>$value#1' => '$column は呼び出し元がリテラルで渡す通知の dedup 列名で、request 由来の値ではない。'
                    .'BillingNotification はテナント資源でなく通知の送達記録である',
        ];
    }

    /**
     * 候補 key => 裁定エントリ。
     *
     * ★ここに足す前に必ず「relation 起点 (`$organization->users()->whereKey(...)`) に
     *   直せないか」を検討すること。分類は「直せない」ことが確認できた場合の最後の手段である。
     *
     * @return array<string, DirectFetchJustificationEntry>
     */
    public static function inventory(): array
    {
        return [
            // --- 運用コマンド (HTTP から到達不能) ---
            'Console/Commands/ResetAdminMfaCommand.php#handle#AdminUser.find:$id#1' => DirectFetchJustificationEntry::operatorConsole(
                '運用者が CLI で AdminUser を id で名指しして MFA をリセットする保守コマンド。'
                .'HTTP から到達不能で scheduler / queue からも呼ばれず、--reason を監査ログへ残す',
                commandSignature: 'admin:reset-mfa {id} {--reason=}',
            ),

            // --- 認証済み actor / 検証済み token claim 由来 ---
            'Http/Controllers/Api/V1/Me/RevokeSessionController.php#destroy#OauthSession.find:$sessionId#1' => DirectFetchJustificationEntry::authenticatedActor(
                'session id は resolve.api-actor が Passport の access token レコードから解決した '
                .'ApiActorContext::$oauthSessionId であり、request payload / query string からは受け取らない',
                actorSource: 'passport_token_record',
            ),
            'Http/Middleware/EnsureLoginMethodRemains.php#handle#User.whereKey:$user->getKey()#1' => DirectFetchJustificationEntry::authenticatedActor(
                '対象は $request->user() で確定した認証中の本人のみで、他者を指せる入力が存在しない。'
                .'ロック下の再取得のために主キーで引き直している (投影評価をロック後に限定するため)',
                actorSource: 'authenticated_user',
            ),
            'Http/Middleware/ResolveApiActor.php#contextFromUserToken#OauthSession.find:$row->session_id#1' => DirectFetchJustificationEntry::authenticatedActor(
                'session id は oauth_access_tokens 行 (提示された access token 自身のレコード) の列であり、'
                .'client からは指定できない。直後に user_id / organization_id の一致も再検証している',
                actorSource: 'passport_token_record',
            ),
            'Http/Middleware/ResolveApiActor.php#contextFromUserToken#Organization.find:$organizationId#1' => DirectFetchJustificationEntry::authenticatedActor(
                'organization id は access token レコードに紐づく列で request payload からは受け取らない。'
                .'取得後に $user->isMemberOf() を毎リクエスト再検証し、除名済み token を即時失効同等に扱う',
                actorSource: 'passport_token_record',
            ),
            'Passport/Grants/McpRefreshTokenGrant.php#assertSessionRefreshable#OauthSession.find:$sessionId#1' => DirectFetchJustificationEntry::authenticatedActor(
                'session id は署名検証済み refresh token の claim から取り出した値であり、'
                .'League OAuth2 server が復号・検証を終えた後にしか本メソッドへ到達しない',
                actorSource: 'validated_token_claim',
            ),
            'Passport/McpAuthCodeRepository.php#persistNewAuthCode#User.find:$userId#1' => DirectFetchJustificationEntry::authenticatedActor(
                'user id は League が確立した AuthCodeEntity の user identifier (consent 時に認証済みの本人) で、'
                .'authorize request の payload からは受け取らない',
                actorSource: 'validated_token_claim',
            ),
            'Passport/McpAuthCodeRepository.php#persistNewAuthCode#Organization.find:$orgId#1' => DirectFetchJustificationEntry::authenticatedActor(
                'organization id は McpConsentOrganizationBinder が membership 検証後に request attributes へ'
                .'置いた値で、client の payload を直接読んでいない (attributes はサーバ側で確定するバッグ)',
                actorSource: 'validated_token_claim',
            ),
            'Services/Mcp/Auth/McpAuthorizationContext.php#for#Organization.find:$orgId#1' => DirectFetchJustificationEntry::authenticatedActor(
                'organization id は提示された access token 自身のレコード (oauth_access_tokens.organization_id) の値で、'
                .'MCP tool 引数からは受け取らない。取得後に isMemberOf() で剥奪済み membership も拒否する',
                actorSource: 'passport_token_record',
            ),

            // --- local 限定 (production では route が存在しない) ---
            'Http/Controllers/DebugLoginController.php#loginAs#User.findOrFail:$userId#1' => DirectFetchJustificationEntry::localOnly(
                'local 専用のデバッグログイン。routes/web.php 側で isLocal / runningUnitTests に囲われており '
                .'production では route 自体が登録されない。加えて LocalOnly middleware が二重防御になる',
                routeName: 'debug.login-as',
            ),

            // --- 同一クエリ内で所有者スコープが閉じている ---
            'Http/Routing/SelfScopedPasskeyBinder.php#bind#Passkey.whereKey:$id#1' => DirectFetchJustificationEntry::ownerScopedQuery(
                '所有者スコープの where を解決クエリ自体に含めている (取得後に弾くと 403/404 の差で存在が漏れる)。'
                .'relation 起点にできないのは PasskeyUser interface が vendor 型で解決され App\Models\Passkey を返せないため',
            ),

            // --- queue payload の再水和 (id は enqueue 時にサーバが確定) ---
            'Jobs/Billing/AutoRechargeTriggerJob.php#handle#Organization.find:$this->organizationId#1' => DirectFetchJustificationEntry::queuePayload(
                'organization id は予約確定 (reserve) 時にサーバが解決済みモデルから採番した値で、'
                .'HTTP 入力を経由せず dispatch される。worker 側は再水和のみ行う',
                enqueuedBy: 'App\Services\Billing\TicketLedgerService::reserve',
            ),
            'Jobs/Billing/ExecuteAutoRechargeAttemptJob.php#handle#TicketAutoRechargeAttempt.find:$this->attemptId#1' => DirectFetchJustificationEntry::queuePayload(
                'attempt id は AutoRechargeTriggerJob がサーバ側で作成した attempt 行の主キーであり、'
                .'client からは指定できない。worker 側は再水和のみ行う',
                enqueuedBy: 'App\Jobs\Billing\AutoRechargeTriggerJob::handle',
            ),
            'Jobs/Billing/HandleAutoRechargeChargeFailureJob.php#handle#TicketAutoRechargeAttempt.find:$this->attemptId#1' => DirectFetchJustificationEntry::queuePayload(
                'attempt id は署名検証済み Stripe webhook の処理中にサーバが特定した attempt 行の主キーで、'
                .'HTTP payload の値をそのまま id として使っていない',
                enqueuedBy: 'App\Services\Billing\StripeWebhookProcessor::handleInvoicePaymentFailed',
            ),
            'Jobs/Billing/ReuseSubscriptionPaymentMethodJob.php#handle#Organization.find:$this->organizationId#1' => DirectFetchJustificationEntry::queuePayload(
                'organization id は署名検証済み webhook の処理中にローカル subscription 行から解決した値であり、'
                .'webhook payload が直接指定した id ではない',
                enqueuedBy: 'App\Services\Billing\StripeWebhookProcessor::settleSubscriptionCheckout',
            ),
            'Jobs/Billing/SetDefaultPaymentMethodJob.php#handle#Organization.find:$this->organizationId#1' => DirectFetchJustificationEntry::queuePayload(
                'organization id は署名検証済み webhook の処理中にサーバ側で解決した値で、'
                .'client が指定した値をそのまま id として使っていない',
                enqueuedBy: 'App\Services\Billing\StripeWebhookProcessor::completeAutoRechargeSetup',
            ),
            'Jobs/Manual/DeleteRenderOutputsJob.php#handle#RenderJob.find:$this->renderJobId#1' => DirectFetchJustificationEntry::queuePayload(
                'render job id は reconcile 走査がサーバ側で列挙した RenderJob の主キーで、HTTP 入力を経由しない。'
                .'worker 側は再水和して出力 prefix の一致を確認してから削除する',
                enqueuedBy: 'App\Services\Manual\RenderJobService::reconcileOutputs',
            ),
            'Jobs/Manual/RunManualAnalysis.php#failed#AnalysisJob.find:$this->analysisJobId#1' => DirectFetchJustificationEntry::queuePayload(
                'analysis job id は trigger がテナント検証済みの manual から採番して dispatch した値で、'
                .'payload にモデル/組織値を持たない (payload 不信任の設計)。failed() は再水和して失敗記録のみ行う',
                enqueuedBy: 'App\Services\Manual\AnalysisJobService::trigger',
            ),
            'Jobs/Manual/RunManualRender.php#failed#RenderJob.find:$this->renderJobId#1' => DirectFetchJustificationEntry::queuePayload(
                'render job id は trigger がテナント検証済みの manual から採番して dispatch した値で、'
                .'payload にモデル/組織値を持たない。failed() は再水和して失敗記録のみ行う',
                enqueuedBy: 'App\Services\Manual\RenderJobService::trigger',
            ),
            'Services/Manual/AnalysisPipeline.php#run#AnalysisJob.findOrFail:$analysisJobId#1' => DirectFetchJustificationEntry::queuePayload(
                'RunManualAnalysis::handle が $this->analysisJobId をそのまま渡す委譲先。id は trigger が採番した'
                .'サーバ確定値で HTTP 入力を経由しない (Service 側に置くのは worker の SIGALRM 予算と分離するため)',
                enqueuedBy: 'App\Jobs\Manual\RunManualAnalysis::handle',
            ),
            'Services/Manual/RenderPipeline.php#run#RenderJob.findOrFail:$renderJobId#1' => DirectFetchJustificationEntry::queuePayload(
                'RunManualRender::handle が $this->renderJobId をそのまま渡す委譲先。id は trigger が採番した'
                .'サーバ確定値で HTTP 入力を経由しない',
                enqueuedBy: 'App\Jobs\Manual\RunManualRender::handle',
            ),

            // --- テナントスコープ済みの解決から確定した id ---
            'Services/Billing/PersonalPlanService.php#activateWithinTransaction#Organization.findOrFail:$organizationId#1' => DirectFetchJustificationEntry::idFromTenantScopedQuery(
                'id は型付き引数 Organization $org の主キーで、request からは受け取らない。'
                .'行ロック下で最新状態を取り直すために主キーで引き直している (reserve と同じ直列化点)',
            ),
            'Services/Project/DefaultProjectResolver.php#resolveForUpdate#Project.whereKey:$id#1' => DirectFetchJustificationEntry::idFromTenantScopedQuery(
                'id は直前の $organization->projects() で組織スコープ済み。HasManyThrough に lockForUpdate を'
                .'掛けると JOIN 先までロックするため、単一テーブルの主キーロックに落としている',
            ),

            // --- 同一メソッド内の走査クエリ由来 (保守処理) ---
            'Services/Billing/TicketLedgerService.php#releaseStale#TicketReservation.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
                'id は同一メソッドが status / expires_at で列挙した TicketReservation の主キー。'
                .'期限切れ予約の解放は全テナント横断の保守処理であり cron から呼ばれる (HTTP 入力を経由しない)',
            ),
            'Services/Capture/StaleUploadReservationSweeper.php#sweep#TakeUploadReservation.whereKey:$reservation->id#1' => DirectFetchJustificationEntry::sameMethodQuery(
                'id は同一メソッドが status / expires_at で列挙した予約行の主キー。孤児オブジェクト回収は'
                .'全テナント横断の保守処理で cron から呼ばれる。whereKey は CAS 更新の対象行指定に使っている',
            ),
            'Services/Manual/AnalysisJobService.php#recoverStale#AnalysisJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
                'id は同一メソッドが status / 経過時間で列挙した AnalysisJob の主キー。'
                .'stale ジョブの回復は全テナント横断の保守処理で cron から呼ばれる (HTTP 入力を経由しない)',
            ),
            'Services/Manual/RenderJobService.php#recoverStale#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
                'id は同一メソッドが status / 経過時間で列挙した RenderJob の主キー。'
                .'stale ジョブの回復は全テナント横断の保守処理で cron から呼ばれる (HTTP 入力を経由しない)',
            ),
            'Services/Manual/RenderJobService.php#reconcileOutputs#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
                'id は同一メソッドが output_path 非 NULL で列挙した RenderJob の主キー。'
                .'世代交代済み出力の整合回復は全テナント横断の保守処理で cron から呼ばれる',
            ),
            'Services/OAuth/OauthSessionListService.php#legacyMcpTokens#User.whereIn:id:in:$userIds#1' => DirectFetchJustificationEntry::sameMethodQuery(
                'user id 群は同一メソッドが t.organization_id で組織スコープ済みに列挙した token 行の列由来。'
                .'名前は暗号化列のため raw join で復号できず、復号目的で Eloquent 経由の再取得が要る',
            ),

            // --- 呼び出し元で確定した id を private ヘルパが受け取る形 ---
            'Services/Organization/OrganizationMembershipService.php#lockForMembershipWrite#DB:users.whereIn:id:in:$sortedUserIds#1' => DirectFetchJustificationEntry::internalCaller(
                'private な共通ロック境界。id は呼び出し元が解決済みモデルから keyOf() で取り出した値で、'
                .'本メソッドは行ロック取得のみ行い結果を読まない (deadlock 回避のため昇順で並べ替えている)',
                calledBy: 'App\Services\Organization\OrganizationMembershipService::transferOwnership',
            ),
            'Services/Organization/OrganizationMembershipService.php#lockForMembershipWrite#DB:organizations.whereIn:id:in:$sortedOrgIds#1' => DirectFetchJustificationEntry::internalCaller(
                'private な共通ロック境界。id は呼び出し元が解決済みモデルから keyOf() で取り出した値で、'
                .'本メソッドは行ロック取得のみ行い結果を読まない (users → organizations の順序も固定している)',
                calledBy: 'App\Services\Organization\OrganizationMembershipService::transferOwnership',
            ),

            // --- ★債務 (新規コードで使わない。fetch 時点でスコープが閉じていない) ---
            'Http/Controllers/Organizations/OrganizationOwnershipController.php#store#User.findOrFail:$userId#1' => DirectFetchJustificationEntry::globalExistenceRuleDebt(
                'payload の user_id を組織スコープ外で引いている。移譲先が組織メンバーであることの検証は'
                .'Service のロック下で行われるが、fetch 時点ではスコープが閉じていない',
                verifiedBy: 'App\Services\Organization\OrganizationMembershipService::transferOwnership',
                validationRule: 'exists:users,id',
                todoRef: 'aicue:T118',
            ),
            'Http/Controllers/Projects/ProjectMemberController.php#store#User.findOrFail:$userId#1' => DirectFetchJustificationEntry::globalExistenceRuleDebt(
                'payload の user_id を組織スコープ外で引いている。組織メンバーであることの確認は'
                .'fetch 後の organizationRole() 判定であり、fetch 時点ではスコープが閉じていない',
                verifiedBy: 'App\Http\Controllers\Projects\ProjectMemberController::store',
                validationRule: 'exists:users,id',
                todoRef: 'aicue:T118',
            ),
            'Http/Middleware/McpConsentOrganizationBinder.php#handle#Organization.find:$orgId#1' => DirectFetchJustificationEntry::globalExistenceRuleDebt(
                'consent payload の organization_id を組織スコープ外で引いている。membership 確認は'
                .'fetch 後の organizations()->whereKey()->exists() であり、fetch 時点ではスコープが閉じていない',
                verifiedBy: 'App\Http\Middleware\McpConsentOrganizationBinder::handle',
                validationRule: 'filter_var(FILTER_VALIDATE_INT, min_range=1)',
                todoRef: 'aicue:T118',
            ),
        ];
    }

    private static function classOf(SplFileInfo $file): string
    {
        $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $file->getRelativePathname());

        return 'App\\Models\\'.substr($relative, 0, -4);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support\Security;

use App\Enums\Security\RecoveryFetchShape;
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
            'Console/Commands/Development/PipelineSmokeCommand.php#resolveOrganization#Organization.whereKey:$option#1' => DirectFetchJustificationEntry::operatorConsole(
                '運用者が bug-hunt レーンで CLI から対象組織を --org=ID で名指しする通し確認コマンド。'
                .'HTTP から到達不能で scheduler / queue からも呼ばれず、実行そのものが fail-secure 4 条件'
                .'(env=bughunt.local / bug-hunt DB / fake storage / real LLM) を満たさないと開始しない。'
                .'--org 省略時は使い捨ての bug-hunt DB 内で条件を満たす組織を探索するが、最終的に触るのは'
                .'選ばれた 1 組織だけで、組織を跨ぐ read/write は 1 箇所も無い',
                commandSignature: 'dev:pipeline-smoke {--check} {--org=} {--json} {--force}',
            ),
            'Console/Commands/Billing/MarkStripeCustomerRedactedCommand.php#handle#Organization.whereKey:$organizationId#1' => DirectFetchJustificationEntry::operatorConsole(
                '運用者が CLI で組織を id で名指しし、決済事業者側 customer の redaction 実施を記録する保守コマンド。'
                .'HTTP から到達不能で scheduler / queue からも呼ばれず、cross-org の概念が無い (対象は常に 1 組織)。'
                .'行ロック下で既記録を再確認するため主キーで引いている',
                commandSignature: 'billing:mark-stripe-customer-redacted {organization} {--apply}',
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
                'attempt id は AutoRechargeService が起票と同一 tx でサーバ側に作成した attempt 行の'
                .'主キーであり、client からは指定できない。worker 側は再水和のみ行う',
                // T137: 投入点が呼び出し側 (AutoRechargeTriggerJob::handle) から起票と同一 tx の
                // createAttemptLocked へ移った (AG-114 確定 1)。
                enqueuedBy: 'App\Services\Billing\AutoRechargeService::createAttemptLocked',
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

            // --- 滞留回収の候補列挙が返した主キー (aicue:T171 で新設した分類) ---
            'Services/Manual/AnalysisJobService.php#lockStaleJob#AnalysisJob.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
                'id は滞留回収の候補列挙 (staleJobIds) が status / 経過時間で選んだ AnalysisJob の主キー。'
                .'全テナント横断の保守処理で定期実行から呼ばれ HTTP 入力を経由しない。'
                .'候補列挙と同じ述語を WHERE に入れて行ロック下で再評価するため誤回収も起きない',
                entryPoint: 'App\Services\Manual\AnalysisJobService::failStaleJob',
                stream: 'analysis_job',
                shape: RecoveryFetchShape::DomainService,
            ),
            'Services/Manual/RenderJobService.php#lockStaleJob#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
                'id は滞留回収の候補列挙 (staleJobIds) が status / 経過時間で選んだ RenderJob の主キー。'
                .'全テナント横断の保守処理で定期実行から呼ばれ HTTP 入力を経由しない。'
                .'投入待ちと実行中で閾値が分かれるが述語は 1 か所に集約してある',
                entryPoint: 'App\Services\Manual\RenderJobService::failStaleJob',
                stream: 'render_job',
                shape: RecoveryFetchShape::DomainService,
            ),
            'Services/Billing/TicketLedgerService.php#lockExpiredReservation#TicketReservation.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
                'id は滞留回収の候補列挙 (expiredReservationIds) が選んだ TicketReservation の主キー。'
                .'期限切れ予約の解放は全テナント横断の保守処理で定期実行から呼ばれる。'
                .'失効した月次 hold の判定式は会計の一部なので台帳サービスの中に閉じている',
                entryPoint: 'App\Services\Billing\TicketLedgerService::releaseExpiredReservation',
                stream: 'ticket_reservation',
                shape: RecoveryFetchShape::DomainService,
            ),
            'Services/Billing/StripeWebhookProcessor.php#claimStale#StripeWebhookEvent.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
                'id は滞留回収の候補列挙 (staleRecordIds) が status / 経過時間で選んだ通知記録の主キー。'
                .'受理は行ロック下で滞留の述語を再評価するため、待っている間に他の実行が'
                .'前へ進めた行は 1 行も返らない。HTTP 入力を経由しない保守処理である',
                entryPoint: 'App\Services\Billing\StripeWebhookProcessor::recoverStuckEvent',
                stream: 'webhook_event',
                shape: RecoveryFetchShape::DomainService,
            ),
            'Services/Recovery/Streams/StaleUploadReservationStream.php#releaseIfStillStale#TakeUploadReservation.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
                'id は同じ系列の候補列挙が status / 期限で選んだアップロード予約の主キー。'
                .'解放とパスの取得を 1 本の行ロックで済ませており、登録処理が勝った行は'
                .'述語の再評価で 0 行になる (正当なテイクの実体を消さない)',
                entryPoint: 'App\Services\Recovery\Streams\StaleUploadReservationStream::recover',
                stream: 'upload_reservation',
                shape: RecoveryFetchShape::StreamInternal,
            ),

            // --- 同一メソッド内の走査クエリ由来 (保守処理) ---
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

            // --- ★存在オラクル (payloadIdExistenceOracle) は現在 0 件。
            //     aicue:T118 で payload 由来 id 3 件 (org 移譲 / project メンバー追加 /
            //     MCP consent) を relation 起点の解決へ寄せたため。
            //     再発時はここに分類を書き、modelDirectFetchExistenceOracleCount() も同時に上げる
            //     (件数は「以下」ではなく一致で固定されているので、書き換えは必須 — c2c 裁定 AG-103)。
        ];
    }

    private static function classOf(SplFileInfo $file): string
    {
        $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $file->getRelativePathname());

        return 'App\\Models\\'.substr($relative, 0, -4);
    }
}

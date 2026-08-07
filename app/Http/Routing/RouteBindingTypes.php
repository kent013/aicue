<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Models\AnalysisJob;
use App\Models\ApiKey;
use App\Models\Category;
use App\Models\Cut;
use App\Models\Item;
use App\Models\OauthSession;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;

/**
 * route binding param の型 inventory (total inventory。分類漏れを禁止する)。
 *
 * 背景: pgsql は型不一致の比較で 22P02 (invalid input syntax) を投げるため、
 * 非適合セグメント (/projects/abc) が implicit binding に届くと QueryException →
 * **404 ではなく生 500** になる。AI-CUE はこのバグクラスを {notification} (whereUuid) と
 * {organization} (binder の normalizeIntegerId) で個別に潰していたが系統化されておらず、
 * bigint 11 param と uuid {oauthSession} が無防備だった (監査 2026-08-02)。
 *
 * 本 inventory は「全 binding param を **5 分類**のいずれかに登録する」ことを要求する
 * 単一 source of truth であり、tests/Architecture/RouteBindingTypeConstraintInventoryTest が
 * routes 定義と突合して**未登録 param の出現を fail** させる (deny-by-default)。
 * 未知 param を数値と推測することはしない。
 *
 * 分類の意味:
 *  - BIGINT:        $table->id() の PK。数値制約を Route::pattern で適用する
 *  - UUID:          $table->uuid() / HasUuids の PK。UUID 制約を適用する
 *  - CUSTOM_BINDER: Route::bind の explicit binder が入力正規化を担う。pattern は適用しない
 *  - NON_MODEL:     モデル binding ではない文字列 param。型制約の対象外
 *  - EXTERNAL:      外部 (vendor) route が持ち込む param。route identity ごとに登録する
 */
final class RouteBindingTypes
{
    /**
     * bigint PK。Route::pattern で数値制約を適用する。
     *
     * **param 名 => 対応モデル**の map で持つ。StudlyCase からのクラス名推測は
     * namespace や例外モデルで破綻するため、**対応モデル型の source of truth をここに置く**。
     * IV-3 / IV-9 が共用する。
     *
     * @var array<string, class-string<Model>>
     */
    public const BIGINT = [
        'analysisJob' => AnalysisJob::class,
        'apiKey' => ApiKey::class,
        'category' => Category::class,
        'cut' => Cut::class,
        'invitation' => OrganizationInvitation::class,
        'item' => Item::class,
        'manual' => VideoManual::class,
        'project' => Project::class,
        'renderJob' => RenderJob::class,
        'take' => Take::class,
        'user' => User::class,
    ];

    /**
     * UUID PK。Route::pattern で UUID 制約を適用する。
     *
     * @var array<string, class-string<Model>>
     */
    public const UUID = [
        'notification' => DatabaseNotification::class,
        'oauthSession' => OauthSession::class,
    ];

    /**
     * BIGINT / UUID 宣言のうち、**controller が implicit binding を使わず手動解決する** param。
     *
     * IV-9(a) の「action 引数が宣言モデル型であること」検査から**明示的に除外**する
     * (action 引数は string になるため)。除外しても pattern の型制約 (IV-3 / IV-4) と
     * 手動解決先の PK 型 (IV-9(c)) は引き続き検証されるため、22P02 / 22003 防御は落ちない。
     *
     * **除外は「param 名 + route identity」単位**で登録する (impl-review R1 Warning)。
     * param 名だけで除外すると、将来同名 param を使う**別 route が丸ごと免除**され
     * deny-by-default の穴になる。列挙されていない route で同じ param が現れたら
     * IV-9(a) は通常どおり fail する。
     *
     * route identity の規約は `routeBindingIdentity()` と同じ (name 優先 / 無ければ
     * `method:uri`)。identity の実在は IV-9 補が検証する (陳腐化した登録を残さない)。
     *
     * @var array<string, array{routes: list<string>, reason: string}>
     */
    public const MANUALLY_RESOLVED = [
        // NotificationController は $request->user()->notifications() 経由で解決する
        // (他ユーザーの通知 id は「存在しない」と同じ 404 = 存在オラクル封じ)。
        'notification' => [
            'routes' => ['notifications.open', 'notifications.read'],
            'reason' => 'cross-user 404 のため controller が $user->notifications() 経由で解決する',
        ],
        // ProjectMemberController::destroy は現在組織の users() から解決する。
        // implicit binding のままだと「不在 id = binding 404 / 実在の非メンバー = 後段短絡の
        // 302」と分岐し users.id の存在オラクルになる (audit-cycle-2 High-1 横断)。
        // {user} の意味的な親は {project} ではなく現在組織のため scopeBindings は採れない
        // (Project::users() が存在しない。Project::members() は明示メンバーのみで意味が狭い)。
        'user' => [
            'routes' => ['projects.members.destroy'],
            'reason' => '存在オラクル封じのため controller が $organization->users() 経由で解決する'
                .' (binding 段で解決しないことが不在 id と実在の非メンバーを同一応答にする根拠)',
        ],
        // AcceptInvitationInAppController は $user 宛の有効 pending 集合 (scopeActivePendingForEmail)
        // から解決する。implicit binding のままだと「不在 id = binding 404 / 実在の他人宛 =
        // 後段短絡」に分岐し 1 bit の存在オラクルになる。
        'invitation' => [
            'routes' => ['invitations.accept-in-app'],
            'reason' => '存在秘匿のため controller が認証ユーザー宛の有効 pending 集合から解決する'
                .' (binding 段で解決しないことが不在 id と実在の他人宛招待を同一の 404 にする根拠)',
        ],
    ];

    /**
     * param ごとに**許可する binding field**。`{user:slug}` のように
     * 非 PK field を指定されると Route::pattern の型制約と意味がずれるため、
     * IV-9 が「field 未指定 (= routeKeyName) か、ここに列挙された field のみ」を要求する。
     *
     * 既定は**空 = field 指定を一切許さない** (PK 解決のみ)。
     * 将来 `{manual:slug}` 等が必要になったら、その param を BIGINT/UUID から外すか
     * ここへ明示登録する (= 型制約と両立するかを人間が判断する契機になる)。
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED_BINDING_FIELDS = [];

    /**
     * explicit binder が入力正規化を担う param。**pattern は適用しない**。
     * {organization} は {organization:slug} を併用するため数値制約を掛けると
     * slug route が全滅する (概念設計 Round 1 Critical)。
     *
     * @var array<string, class-string>
     */
    public const CUSTOM_BINDER = [
        'organization' => MembershipScopedOrganizationBinder::class,
        // {passkey} は Fortify (vendor) が登録する route の param。app 側から
        // Route::pattern を掛けると vendor の route 定義変更に追随できないため、
        // binder が「認証ユーザー所有 + 数値正規化」を担う (他人の passkey は 404)。
        'passkey' => SelfScopedPasskeyBinder::class,
    ];

    /**
     * モデル binding ではない文字列 param。型制約の対象外。
     *
     * @var list<string>
     */
    public const NON_MODEL = [
        'intent', 'provider', 'userId',
    ];

    /**
     * 外部 (vendor) route が持ち込む param を **route identity ごと**に登録する inventory。
     * **pattern は適用しない**。
     *
     * **param 名だけの list では同名衝突を検出できない**:
     * vendor が非数値用途の `{user}` を追加しても `user` は既に BIGINT 登録済みなので
     * 素通りし、global な数値 pattern が vendor route を破壊する。
     * そこで **route identity => その route が持つ external param のリスト** で持ち、
     * IV-7 が「EXTERNAL 宣言された param が BIGINT / UUID と同名でないこと」を
     * **明示的に fail** させる。
     *
     * route identity には **route name を使う** (URI は prefix 設定で動くため不安定)。
     * name 無し route が対象になる場合は `method:uri` の signature を使う
     * (HTTP method は昇順ソートし、暗黙の HEAD は除外する)。
     * **route identity の実在・params 完全一致・BIGINT/UUID との衝突は IV-7 が検証する**
     * (IV-2 は param の逆方向検査であり別責務)。
     *
     * 登録手順 (**自動抽出はしない**): gate (IV-1) が未登録 param を
     * route identity・action 付きで列挙するので、人間が用途を確認して 5 分類のいずれかへ登録する。
     * 「route file 由来か」を機械判定しようとすると出自判定問題が再発するため、
     * 外部 route の自動抽出は要件にしない。
     *
     * @var array<string, list<string>>
     */
    public const EXTERNAL = [
        // Laravel MCP (vendor/laravel/mcp) の OAuth discovery。{path} は任意の後続セグメント
        'mcp.oauth.authorization-server.nested' => ['path'],
        'mcp.oauth.protected-resource.nested' => ['path'],
        // Filament admin panel。{record} は Filament の resolveRouteBindingQuery 経由
        'filament.admin.resources.admin-users.edit' => ['record'],
        'filament.admin.resources.inquiries.edit' => ['record'],
        'filament.admin.resources.model-audits.view' => ['record'],
        'filament.admin.resources.organizations.view' => ['record'],
        'filament.admin.resources.organizations.edit' => ['record'],
        'filament.admin.resources.plans.edit' => ['record'],
        'filament.admin.resources.users.view' => ['record'],
        // Filament export / import (ULID 相当の id 文字列)
        'filament.exports.download' => ['export'],
        'filament.imports.failed-rows.download' => ['import'],
        // Fortify のメール確認署名付き URL ({id} は user id・{hash} は email hash)
        'verification.verify' => ['id', 'hash'],
        // Fortify のパスワードリセット (署名トークン)
        'password.reset' => ['token'],
        // Cashier の SCA 確認画面 ({id} は Stripe PaymentIntent id)
        'cashier.payment' => ['id'],
        // Laravel の local storage 配信 route (FilesystemServiceProvider)
        'storage.local' => ['path'],
        'storage.local.upload' => ['path'],
        // Livewire のファイルプレビュー / CSS・JS モジュール配信。
        // css/js の 3 route は name を持たないため method:uri signature で登録する。
        // uri prefix は APP_KEY 由来のハッシュ (EndpointResolver::prefix) で環境ごとに
        // 変わるため、gate は prefix を `livewire/` へ正規化した identity で突合する。
        'livewire.preview-file' => ['filename'],
        'GET:livewire/css/{component}.css' => ['component'],
        'GET:livewire/css/{component}.global.css' => ['component'],
        'GET:livewire/js/{component}.js' => ['component'],
    ];

    /**
     * bigint PK の route 制約。**18 桁上限**にすることで 2 種類の pgsql 例外を同時に塞ぐ。
     *
     *  - 非数値 (/projects/abc) → 22P02 invalid_text_representation
     *  - 桁あふれ (/projects/<30桁>) → **22003 numeric_value_out_of_range**
     *
     * `[0-9]+` だと後者が regex を通過して DB へ到達し 500 になる。
     * bigint / PHP_INT_MAX = 9223372036854775807 (**64bit PHP 前提**) は 19 桁なので、
     * 18 桁の最大値 999999999999999999 は必ず範囲内 = **桁数だけで範囲内を保証できる**。
     *
     * **これはドメイン制約の導入である**: DB の bigint が許容する 19 桁 ID を意図的に排除し、
     * 「AI-CUE の route key は最大 18 桁」と定める。実 ID が 10^18 に達することは無いため
     * 運用上の制約にならないが、「適合値の挙動が不変」ではない点に注意
     * (docs/architecture.md に記録。値自体を Architecture テストで pin する)。
     *
     * 先頭ゼロ ('007') は本 pattern にマッチするが pgsql は '007'::bigint を正常に解釈するため
     * 500 にならない (該当行なしで 404)。canonical URL の要件は別問題なのでここでは制約しない。
     */
    public const BIGINT_PATTERN = '[0-9]{1,18}';

    /** Laravel の UUID 制約 (whereUuid 相当)。 */
    public const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * 登録済みの全 param 名 (gate が routes 定義と突合するために使う)。
     *
     * @return list<string>
     */
    public static function allRegistered(): array
    {
        return [
            ...array_keys(self::BIGINT),
            ...array_keys(self::UUID),
            ...array_keys(self::CUSTOM_BINDER),
            ...self::NON_MODEL,
            ...self::externalParams(),
        ];
    }

    /**
     * EXTERNAL に宣言された全 param 名を平坦化する。
     *
     * `array_merge(...array_values(self::EXTERNAL) ?: [[]])` は PHPStan の推論が不安定なため
     * 専用メソッドに切り出す (**型を緩めて回避しない**。禁止事項 #2)。
     *
     * @return list<string>
     */
    public static function externalParams(): array
    {
        $params = [];
        foreach (self::EXTERNAL as $routeParams) {
            foreach ($routeParams as $param) {
                $params[] = $param;
            }
        }

        return $params;
    }
}

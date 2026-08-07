<?php

declare(strict_types=1);

namespace App\Enums\Http;

/**
 * Inertia XHR の例外応答を **Error 画面へ差し替えなかった理由**の分類。
 *
 * `App\Exceptions\InertiaExceptionRenderer::passthroughReason()` が唯一の生成点で、
 * null (= 差し替える) 以外はすべて本 enum の case になる。
 *
 * ★**未使用 case を残さない**。tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php が
 *   「全 case が実際に生成されること」を behavioral に固定する (死んだ分類を作らない)。
 *   分類が当てはまらない応答は「差し替えてよい応答」である。
 */
enum InertiaErrorScreenPassthrough: string
{
    /** status が 400 未満 (2xx / 3xx)。Location を持つ遷移や成功応答を触らない。 */
    case SuccessOrRedirectStatus = 'success_or_redirect_status';

    /** api/* または expectsJson。(c) の統一エラー封筒 JSON が正しい応答形。 */
    case MachineReadableEnvelope = 'machine_readable_envelope';

    /** admin panel 配下。運営者向け中立テンプレート (errors.admin.*) が正しい応答形。 */
    case OperatorFacingSurface = 'operator_facing_surface';

    /** X-Inertia を持たないフルロード。自己完結 Blade が最後の砦として正しい。 */
    case NonInertiaRequest = 'non_inertia_request';

    /**
     * リクエストの X-Inertia-Version が現在の asset version と一致しない
     * (欠落・空文字・現 version が空も含む)。
     * 旧 bundle のタブには Error ページが存在せず、resolver が throw して SPA が無反応になる。
     */
    case StaleAssetVersion = 'stale_asset_version';

    /** Location / X-Inertia-Location を持つ応答。Inertia 手順上の遷移と外部遷移を壊さない。 */
    case InertiaProtocolRedirect = 'inertia_protocol_redirect';

    /** InertiaErrorScreenStatus に未登録の status (409 / 422 等)。deny-by-default の既定。 */
    case UnlistedStatus = 'unlisted_status';

    /** 5xx かつ app.debug=true。開発時に例外詳細ページを中立文言で潰さない。 */
    case DebugServerError = 'debug_server_error';
}

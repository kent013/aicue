<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「保護対象群に属する route が throttle を持たないことが正しい」と裁定された理由の分類。
 *
 * `tests/Architecture/ThrottleCoverageInventoryTest.php` が deny-by-default で
 * 「throttle ちょうど 1 本」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「throttle を貼るべき route」である。
 */
enum ThrottleCoverageExemption: string
{
    /**
     * 定数メタデータ応答。
     *
     * 適用条件: DB アクセス・暗号処理・外部呼び出し・メール送信・ファイル書込を一切伴わず、
     * 応答が config と url() だけで決まる。
     */
    case StaticMetadataResponse = 'static_metadata_response';

    /**
     * vendor が登録する定数 405 (Method Not Allowed) スタブ。
     *
     * 適用条件: ハンドラが即座に固定 Response を返すだけで、本体処理へ到達しない。
     */
    case VendorMethodNotAllowedStub = 'vendor_method_not_allowed_stub';

    /**
     * セッション破棄のみを行い、推測可能な秘密を一切扱わない route。
     *
     * 適用条件: 認証済みでのみ到達でき、失敗しても攻撃者が得る情報が無い。
     */
    case SessionTeardownOnly = 'session_teardown_only';

    /**
     * local / testing でのみ登録され、**production では route 登録自体が起きない**デバッグ用 route。
     *
     * 適用条件: `routes/*.php` 側で `app()->isLocal() || app()->runningUnitTests()` 等により
     * 登録が囲われ、かつ `LocalOnly` 相当の middleware が二重防御であること。
     * (Architecture テストは testing 環境で走るため、この route は**母集団に現れる**。
     *  「テストからは見えない」ではなく「本番には存在しない」が exemption の根拠である)
     */
    case LocalOnlyDebugRoute = 'local_only_debug_route';

    /**
     * 防御が route ではなく component 内にある。
     *
     * 適用条件: 単一 endpoint に多数の操作が相乗りしており、route 単位の bucket では
     * 無関係な操作を巻き添えにする。かつ component 側に実際の制限実装がある。
     */
    case ComponentLevelLimiter = 'component_level_limiter';

    /**
     * 有効な署名が無ければ本体処理に到達しない route。
     *
     * 適用条件: ハンドラ冒頭で署名検証を行い、不成立なら副作用ゼロで短絡する。
     */
    case SignatureRequiredBeforeEffect = 'signature_required_before_effect';
}

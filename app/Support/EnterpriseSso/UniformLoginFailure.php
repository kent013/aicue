<?php

declare(strict_types=1);

namespace App\Support\EnterpriseSso;

use App\Enums\EnterpriseSso\RejectionReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * 企業ログインの失敗の**唯一の応答**。
 *
 * ★理由によらず**同じ文言・同じ行き先・同じ入力の扱い**である。
 *   1 か所に閉じ込めているのは、応答を組み立てる場所が 2 つあると
 *   「片方だけ理由を漏らす」「片方だけ入力を flash する」形が生まれるからである。
 *
 * ★**入力を 1 つも flash しない**。Laravel は validation の失敗時に
 *   `_old_input` へ入力を退避するので、**FormRequest の失敗もここを通す**
 *   (controller で `withInput()` を呼ばないだけでは `code` / `state` がセッションに残る)。
 *
 * ★理由コードは**ログにだけ**出す (利用者に返す応答へ入れない)。
 */
final class UniformLoginFailure
{
    /** 失敗時に利用者へ見せる**唯一の**文言。 */
    public const string MESSAGE = '企業アカウントでのログインを完了できませんでした。'
        .'もう一度お試しいただくか、組織の管理者にお問い合わせください。';

    /** 応答に載せるエラーキー。 */
    public const string ERROR_KEY = 'enterprise_sso';

    /** インスタンス化しない。 */
    private function __construct() {}

    public static function response(RejectionReason $reason): RedirectResponse
    {
        Log::info('enterprise-sso login rejected', ['reason' => $reason->value]);

        // ★`withInput()` を呼ばない (入力を 1 つも残さない)。
        return redirect()->route('login')->withErrors([self::ERROR_KEY => self::MESSAGE]);
    }
}

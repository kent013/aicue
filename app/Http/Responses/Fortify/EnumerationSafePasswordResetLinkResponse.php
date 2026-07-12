<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;

/**
 * forgot-password の enumeration 抑止レスポンス (Fortify contract bind)。
 *
 * Fortify 標準は user 在/不在で異なるレスポンス (成功 flash vs エラー) を返すため
 * account enumeration を許してしまう。user 在/不在を問わず常に同一の
 * 「送信しました」flash (キーは success = flash-to-toast の消費対象) を返して抑止する。
 *
 * 成功 (SuccessfulPasswordResetLinkRequestResponse) / 失敗
 * (FailedPasswordResetLinkRequestResponse) の両契約を本クラスに差し替える。
 *
 * `STATUS_MESSAGE` は Fortify の status 言語キーに対応するメッセージ内容の意味であり、
 * flash キー名 (`success`) とは無関係。
 */
final class EnumerationSafePasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponseContract, SuccessfulPasswordResetLinkRequestResponseContract
{
    private const string STATUS_MESSAGE = 'パスワードリセット用のリンクをメールで送信しました。';

    /**
     * Fortify は status 言語キー (passwords.sent / passwords.throttled / passwords.user 等) を
     * constructor で渡す。user 在/不在を区別させないため status 値そのものは応答に反映せず、
     * 常に同一の汎用メッセージを返す。プロパティとしては保持し将来の拡張点とする。
     */
    public function __construct(private readonly string $status) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['message' => self::STATUS_MESSAGE], 200);
        }

        // flash キー統一ポリシー: web 向け操作成功 flash は success に統一する
        // (status は flash-to-toast が意図的に gating しており toast にならない = F-06)
        return back()->with('success', self::STATUS_MESSAGE);
    }

    /**
     * Fortify が渡した元の status 言語キー (デバッグ / 将来拡張用)。
     */
    public function rawStatus(): string
    {
        return $this->status;
    }
}

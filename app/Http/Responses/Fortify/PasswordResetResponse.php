<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
use Laravel\Fortify\Fortify;
use Webmozart\Assert\Assert;

/**
 * パスワードリセット完了後のレスポンス (Fortify contract bind)。
 *
 * Fortify 既定は login へ redirect し `status` を flash するが、flash-to-toast は
 * status を意図的に gating する。リセット完了を login 画面で toast 表示するため
 * web の redirect flash は汎用 `success` 文言へ寄せる (AuthLayout も consumeFlash を持つ)。
 * 一方 JSON 分岐の `message` は Fortify 既定どおり localize した status を返し API 契約を壊さない
 * (既定と差分があるのは web redirect の flash キー/文言のみ)。
 */
final class PasswordResetResponse implements PasswordResetResponseContract
{
    private const string SUCCESS_MESSAGE = 'パスワードを変更しました。ログインしてください。';

    /**
     * Fortify は status 言語キー (passwords.reset) を constructor で渡す。
     * JSON 応答では既定どおり localize した status を返し、web では汎用 success へ寄せる。
     */
    public function __construct(private readonly string $status) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            // __() の宣言型は array|string (翻訳キーが配列を指すと array)。status は必ず
            // 単一言語キー (passwords.reset) のため string に確定する。キャストで黙らせず、
            // 不変条件を Assert で実行時にも保証しつつ PHPStan Lv10 を string へ narrow する。
            $message = __($this->status);
            Assert::string($message);

            return new JsonResponse(['message' => $message], 200);
        }

        // Fortify 既定式に完全準拠 (views 無効=API 専用構成でも login 未定義で落ちない)
        return redirect(Fortify::redirects('password-reset', config('fortify.views', true) ? route('login') : null))
            ->with('success', self::SUCCESS_MESSAGE);
    }
}

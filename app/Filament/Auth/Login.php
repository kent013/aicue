<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Notifications\Notification;
use Webmozart\Assert\Assert;

/**
 * 管理画面 (/admin) のログインページ。
 *
 * vendor (Filament) の Login をそのまま使うと、試行の上限に達したときに
 * 「通知を出して早期 return」するだけで済ませる。Livewire は入力エラーを次の要求へ
 * 持ち越すため、上限に達した画面には直前の試行の理由 (「認証に失敗しました。」) が
 * 残り続け、運営者には「正しい資格情報なのに違うと言われ続ける」と見える。
 * 通知 (トースト) も、上限到達がいつ明けるのかを入力欄の位置では示さない。
 *
 * 本クラスは vendor が上限到達時にだけ通す拡張点で、次の 2 つだけを行う
 * (機能台帳 filament-login-throttle-display / 裁定 AG-017b が求める保証):
 *   (1) 前の要求で付いた入力エラーを捨てる
 *   (2) 再開までの残り秒数を含む案内を、いま表示されている入力欄の位置に出す
 *
 * 対象は**通常のログインの上限と、多要素チャレンジ専用の上限の両方**である
 * (vendor はどちらからも同じ拡張点を呼ぶ)。認証処理・上限値 (5 回) ・減衰 (60 秒) ・
 * 判定順序・鍵は vendor 側にそのまま残す (複写しない)。
 *
 * 置き場所を app/Filament/Pages/ にしないのは、panel の自動発見 (discoverPages) が
 * 通常ページとして登録し、/admin 配下に余分なページ route が生えるためである。
 *
 * 注意: 本クラスを配線すると流量制限の鍵 (`livewire-rate-limiter:sha1(クラス名|メソッド名|IP)`)
 * のクラス名部分が変わる。帰結は反映時に高々 60 秒ぶんの計数が 1 度 0 に戻ることだけで、
 * 閾値・鍵の書式規約は変わらない (`RateLimiter::for()` の名前付き制限ではない)。
 */
class Login extends BaseLogin
{
    /**
     * 上限到達の案内を載せる入力欄 (資格情報の画面)。
     */
    private const EMAIL_FIELD = 'data.email';

    /**
     * 上限到達の案内を載せる入力欄 (多要素チャレンジの画面)。
     *
     * vendor と同じ状態パスの規約: チャレンジ様式は `data.multiFactor` を statePath に持ち、
     * その下に提供元 id (`app` = AppAuthentication) の Group が入る。
     * admin panel の多要素の提供元は AppAuthentication 1 つだけである
     * (AdminPanelProvider::panel())。提供元を増やすときは本定数の選び方を見直すこと。
     */
    private const CHALLENGE_CODE_FIELD = 'data.multiFactor.app.code';

    /**
     * 流量制限の上限に達したときの通知。
     *
     * `get〜` という名前だが、vendor が上限到達時に用意している拡張点はここだけであり、
     * 「上限に達したときにだけ通る」という意味は名前ではなく呼ばれる位置が担っている。
     * 呼び出し元は 2 か所 = `authenticate()` 冒頭の通常の上限と、
     * 多要素チャレンジ専用の上限 (`isMultiFactorChallengeRateLimited()`) である。
     *
     * `resetErrorBag()` は error bag を**丸ごと**空にする。どちらの経路でも、ここに達した
     * 時点でこの要求が新たに立てたエラーは無いため (通常側は上限の評価が検証より前、
     * 多要素側はログイン欄の検証を通過してから上限の評価に入る)、消えるのは前の試行から
     * 持ち越された説明だけである。
     */
    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        // vendor の TooManyRequestsException は残り秒数を型宣言の無い public プロパティで渡す。
        // 呼び出し元 2 か所はどちらも RateLimiter::availableIn() (int) を渡すが、表示のためだけの
        // 値でここを例外にすると、上限到達中の画面が 500 になって表示の不具合が可用性の事故へ
        // 化ける。数値でなければ 0 秒として扱う (fail-soft)。
        $seconds = is_numeric($exception->secondsUntilAvailable)
            ? max(0, (int) $exception->secondsUntilAvailable)
            : 0;

        $message = __('auth.throttle', ['seconds' => $seconds]);
        Assert::string($message);

        $this->resetErrorBag();
        $this->addError($this->throttledFieldKey(), $message);

        return parent::getRateLimitedNotification($exception);
    }

    /**
     * いま表示されている様式に合わせて、案内を載せる入力欄を選ぶ。
     *
     * vendor は多要素チャレンジ中 (`userUndertakingMultiFactorAuthentication` が埋まっている間)
     * はログイン様式を隠してチャレンジ様式を出すため、同じ判定で「利用者が見ている欄」を選べる。
     */
    private function throttledFieldKey(): string
    {
        return filled($this->userUndertakingMultiFactorAuthentication)
            ? self::CHALLENGE_CODE_FIELD
            : self::EMAIL_FIELD;
    }
}

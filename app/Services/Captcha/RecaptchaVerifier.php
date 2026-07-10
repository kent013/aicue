<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use App\Exceptions\RecaptchaUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * reCAPTCHA v2 invisible のサーバ側検証 (Google siteverify)。
 *
 * SSRF 規約の適用範囲外: 宛先は固定の Google エンドポイントでユーザー制御 URL ではない
 * (hostname 固定リテラルの allowlist 相当)。
 *
 * fail-open / fail-closed の境界:
 *   - fail-open (= true + report() + 構造化ログ): transport error / timeout / Google 5xx に限定。
 *     Google 一時障害で全受付を止める方が損失大。honeypot + rate limit を backstop として残す。
 *   - fail-closed (= false): invalid token (空含む) / success=false / 4xx / hostname 不一致 /
 *     期待ホスト解決時の hostname 欠落。
 *   - secret 未設定は production では fail-closed (= 任意トークンの通過を許さない)、
 *     local / testing では fail-open で開発を止めない。いずれも監視に上げる。
 */
class RecaptchaVerifier
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function verify(?string $token, ?string $ip): bool
    {
        if ($token === null || $token === '') {
            // token なし送信は許可しない (captcha bypass を作らない)。fail-closed。
            return false;
        }

        $secret = config('services.recaptcha.secret_key');
        if (! is_string($secret) || $secret === '') {
            // secret 未設定 (設定漏れ)。production のみ fail-closed。
            $allowed = ! app()->environment('production');
            $this->reportUnavailable('missing_secret', $allowed);

            return $allowed;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ], static fn (?string $v): bool => $v !== null && $v !== ''));
        } catch (ConnectionException) {
            // transport error / timeout → fail-open。
            $this->reportUnavailable('transport', allowed: true);

            return true;
        }

        if ($response->serverError()) {
            // Google 5xx → fail-open。
            $this->reportUnavailable('server_5xx', allowed: true);

            return true;
        }

        // 4xx は判定不能だが攻撃面を作らないため fail-closed。
        if (! $response->successful()) {
            return false;
        }

        if ($response->json('success') !== true) {
            // invalid token / その他の検証失敗 → fail-closed。
            return false;
        }

        return $this->hostnameMatches($response->json('hostname'));
    }

    private function hostnameMatches(mixed $hostname): bool
    {
        $expected = $this->expectedHostname();
        if ($expected === null) {
            // app URL host が解決できない場合は hostname 検証をスキップ (success のみ採用)。
            return true;
        }

        if (! is_string($hostname)) {
            // 期待ホストが解決できるのに siteverify が hostname を返さないのは異常。
            // 攻撃面を作らないため fail-closed。
            return false;
        }

        return $hostname === $expected;
    }

    private function expectedHostname(): ?string
    {
        $appUrl = config('app.url');
        if (! is_string($appUrl) || $appUrl === '') {
            return null;
        }

        $host = parse_url($appUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * 判定不能 / fail-open 発火を監視に上げる (spam 流入の見逃しを検知可能にする)。
     */
    private function reportUnavailable(string $reason, bool $allowed): void
    {
        Log::warning('recaptcha unavailable', [
            'reason' => $reason,
            'fail_open' => $allowed,
        ]);
        report(new RecaptchaUnavailableException("reCAPTCHA verification unavailable: {$reason}"));
    }
}

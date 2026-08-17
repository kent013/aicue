<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * 中間 redirect (跳ね返り) を 1 hop だけ跨いでユーザー向け通知を届ける単一窓口。
 *
 * Laravel の一時メッセージ (flash) は「次の 1 要求で失効」する。操作 → 中間 GET →
 * 別 redirect のように**中間 GET が別 redirect を返す**経路 (例: 課金ゲートの
 * オンボーディングへの跳ね返り) では、通知が描画される前に失効し
 * 「押しても何も起きない」画面になる。本クラスはその跳ね返り地点で
 * 「ユーザーに見せる通知」だけを延命する。
 *
 * 設計境界 (延命しすぎない):
 *  - `session()->reflash()` は使わない。1 度きり表示の内部状態 (`new_api_key` =
 *    API キー平文の発行直後 1 度きり表示) まで延命し、状態の持ち越しを生むため。
 *  - `errors` は ViewErrorBag ごと延命しない。**着地画面が実際に描画するキー**
 *    (RELAYABLE_ERROR_KEYS) だけを抽出して置き直す。初期値は空 = error は一切中継しない
 *    (fail-closed)。着地画面が描画する error キーが生まれた時点で opt-in 追加する
 *    (無条件の中継は着地画面のフォーム error キーと衝突して無関係な赤字を生む)。
 *  - default 以外の名前付き error bag は中継しない (アプリ内に使用箇所が無い。fail-closed)。
 *
 * **保証範囲を誇張しない**: 保証するのは「跳ね返りの直前に呼べば通知が 1 hop 延命される」
 * ことだけである。呼び忘れは検出しない (呼び出し点の目録は持たない)。
 */
final class FlashNotificationRelay
{
    public const string SUCCESS = 'success';

    public const string ERROR = 'error';

    public const string INFO = 'info';

    public const string WARNING = 'warning';

    /** session に ViewErrorBag が入るキー (Laravel 規約)。 */
    public const string ERRORS = 'errors';

    /**
     * Inertia がユーザーへ届ける通知 flash キーの SoT。
     * `HandleInertiaRequests::share()` が読み出しに使う唯一の定義であり、一致は
     * `tests/Architecture/FlashNotificationRelayDriftTest.php` (middleware / 書き手) と
     * `tests/js/architecture/flash-keys-sync.test.ts` (画面側の読み手 = flash-to-toast) が固定する。
     *
     * @var list<string>
     */
    public const array NOTIFICATION_KEYS = [
        self::SUCCESS,
        self::ERROR,
        self::INFO,
        self::WARNING,
    ];

    /**
     * 跳ね返りの着地画面が実際に描画する error キー (opt-in allowlist)。
     * 初期状態は空 (fail-closed の no-op。ViewErrorBag 抽出は拡張点として残す)。
     *
     * @var list<string>
     */
    public const array RELAYABLE_ERROR_KEYS = [];

    /** 跳ね返りの直前に呼ぶ。通知の一時メッセージを 1 hop 延命し、表示可能な error だけを置き直す。 */
    public static function relayTo(Session $session): void
    {
        $session->keep(self::NOTIFICATION_KEYS);
        self::relayDisplayableErrors($session);
    }

    /**
     * 中継対象 error キーの契約型を宣言する accessor (拡張点)。
     *
     * 初期値は空だが、契約としては list<string> であり、RELAYABLE_ERROR_KEYS へ
     * opt-in 追加した時点で抽出が生きる。定数を直接 foreach すると初期状態では
     * 静的解析上の到達不能コードになるため、契約型で受け渡す
     * (型を偽る注釈や無視指定ではなく、拡張点の宣言として書く)。
     *
     * @return list<string>
     */
    private static function relayableErrorKeys(): array
    {
        return self::RELAYABLE_ERROR_KEYS;
    }

    /**
     * ViewErrorBag の default bag から allowlist のキーだけを抜き、新しい bag として置き直す。
     * `keep(ERRORS)` は使わない (bag 全体が延命され allowlist が無効化されるため)。
     * allowlist のキーが 1 つも無ければ何もしない (元の errors は次の保存で自然に失効する)。
     */
    private static function relayDisplayableErrors(Session $session): void
    {
        $errors = $session->get(self::ERRORS);
        if (! $errors instanceof ViewErrorBag) {
            return;
        }

        $bag = $errors->getBag('default');

        /** @var array<string, list<string>> $relayed */
        $relayed = [];
        foreach (self::relayableErrorKeys() as $key) {
            /** @var list<string> $messages */
            $messages = [];
            foreach ($bag->get($key) as $message) {
                if (is_string($message) && $message !== '') {
                    $messages[] = $message;
                }
            }
            if ($messages !== []) {
                $relayed[$key] = $messages;
            }
        }

        if ($relayed === []) {
            return;
        }

        $session->flash(self::ERRORS, (new ViewErrorBag)->put('default', new MessageBag($relayed)));
    }
}

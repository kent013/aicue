<?php

declare(strict_types=1);

namespace App\Listeners\Mail;

use App\Services\Mail\EmailSuppressionService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * 送信前チェック: 抑止リスト該当アドレスを宛先から除去する。
 *
 * MessageSending は実送信直前に発火するため、キュー worker 経路でも有効。全送信を横断
 * フックするため、Mailable 個別改修や Fortify (メール認証 / パスワードリセット) 経路への
 * 追加なしに一律適用される。
 *
 * per-recipient filter: to/cc/bcc を個別に走査し抑止宛先のみ除去。全宛先が空になったときだけ
 * 送信を中止する (`to` が全除去されて cc/bcc だけ残る誤送信を防ぐ)。
 *
 * 返り値の契約 (重要): MessageSending は `Mailer::shouldSendMessage` が `events->until()` で
 * 発火する。`until()` は **最初の non-null 返り値で打ち切る**ため、送信を許可する通常パスで
 * `true` を返すと、後続に登録された MessageSending リスナーが一切発火しなくなる
 * (実害例: E2E テスト用の招待 URL capture リスナーがリスナー登録順に依存して握り潰され、
 *  CI でオンボーディングフローが招待 URL を取得できず flaky 化する)。
 * したがって「送信を許可」は `null` を返して until を継続させ、「送信を中止」のときだけ
 * `false` を返す。`shouldSendMessage` は `until() !== false` で判定するため null/false の
 * 二値で送信可否は従来通り保たれる。
 */
final class FilterSuppressedRecipients
{
    public function __construct(private readonly EmailSuppressionService $suppressions) {}

    public function handle(MessageSending $event): ?bool
    {
        $email = $event->message;

        $keptTo = $this->filter($email->getTo());
        $keptCc = $this->filter($email->getCc());
        $keptBcc = $this->filter($email->getBcc());

        if ($keptTo === [] && $keptCc === [] && $keptBcc === []) {
            Log::info('mail.suppressed.cancelled');

            return false;
        }

        $email->to(...$keptTo);
        $email->cc(...$keptCc);
        $email->bcc(...$keptBcc);

        // 送信許可: true を返すと until が打ち切られ後続リスナーを潰すため null を返す。
        return null;
    }

    /**
     * @param  array<Address>  $addresses
     * @return list<Address>
     */
    private function filter(array $addresses): array
    {
        return array_values(array_filter(
            $addresses,
            fn (Address $addr): bool => ! $this->suppressions->isSuppressed($addr->getAddress()),
        ));
    }
}

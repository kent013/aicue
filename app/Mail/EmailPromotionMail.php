<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use SensitiveParameter;

/**
 * メールアドレスの昇格の確認メール。
 *
 * ★既存の送信の作法にそろえて**キューへ載せる** (`ShouldQueue`)。
 *   投入は昇格の行を作るのと**同一トランザクションの中**で行う (AGENTS.md ドメイン規約 11。
 *   `afterCommit` に依存しない = 行が巻き戻ればメールも投入されない)。
 * ★**`ShouldBeEncrypted` を必ず併記する**。キューに載る Mailable は job payload として
 *   直列化されるので、private property であっても**確認トークンと宛先が平文で `jobs` 表に残る**。
 *   キューを読める主体がいれば、その者が利用者として確認を完了できてしまう。
 *   Laravel が payload を暗号化するのは `ShouldBeEncrypted` を実装したものだけである。
 * ★本文に載せるのは**確認画面の URL だけ**である。宛先のメールも利用者の名前も載せない
 *   (万一 victim のアドレスが入力されても、攻撃者が任意の文面を送れない形にする)。
 * ★トークンは `#[SensitiveParameter]` で受ける (スタックトレースに出さない)。
 */
class EmailPromotionMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(#[SensitiveParameter] private readonly string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'メールアドレスの確認',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.auth.email-promotion',
            with: [
                // ★確認画面 (GET) の URL。**状態を変えない画面**であり、確定は明示の POST である。
                'confirmUrl' => route('settings.email-promotion.confirm.show', ['token' => $this->token]),
            ],
        );
    }
}

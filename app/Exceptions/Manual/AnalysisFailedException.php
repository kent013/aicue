<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use App\Enums\Manual\AnalysisFailureReason;
use RuntimeException;

/**
 * AI 解析の失敗 (ユーザー向けメッセージ付き)。AnalysisPipeline が投げ、
 * catch 経路の failJob がメッセージをそのまま error 列に保存する。
 *
 * 各 named constructor は `AnalysisFailureReason` を持つ (画像・スキャン SOP の OCR 対応)。
 * 分岐は message ではなく reason で行う (既存の `LlmOutputInvalidException::$reason` の慣行)。
 */
final class AnalysisFailedException extends RuntimeException
{
    private function __construct(string $message, public readonly AnalysisFailureReason $reason)
    {
        parent::__construct($message);
    }

    /**
     * テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ・PDF から 1 バイトも取れない)。
     *
     * OCR 対応後は画像・スキャン PDF も救済されるため、旧来の「現在未対応です」という
     * 断定文言は書き換える。**この理由はテキスト抽出段の failure であり、OCR 経路まで
     * 試して失敗した場合の終着点は `ocrEmptyOrInvalid()` が担う**。
     */
    public static function unextractable(): self
    {
        return new self(
            'テキストを抽出できません。文字が選択できる PDF か、'
            .'Excel・テキスト形式でアップロードし直してください。',
            AnalysisFailureReason::Unextractable,
        );
    }

    /**
     * 抽出はできたが日本語の本文が閾値に満たない
     * (文字化け / テキスト埋め込みの破損 / 日本語以外の手順書)。
     * 3 つの原因をアプリ側で識別する手段は無いため、どの原因でも実行できる次アクションを示す。
     */
    public static function insufficientJapaneseText(): self
    {
        return new self(
            '手順書から十分な日本語の本文を読み取れませんでした。'
            .'PDF のテキスト埋め込みが壊れている / 日本語以外の手順書、のいずれかの可能性があります。'
            .'日本語の手順書を、Excel・テキスト形式か文字を選択できる PDF でアップロードしてください。',
            AnalysisFailureReason::InsufficientJapaneseText,
        );
    }

    /** 抽出できたが本文が実質空 (min_text_bytes 未満)。画像扱いと混同しない明示文言 */
    public static function tooShort(): self
    {
        return new self(
            '手順書の本文が短すぎます。もう少し詳しい手順書をアップロードしてください。',
            AnalysisFailureReason::TooShort,
        );
    }

    /** LLM 入力上限 (UTF-8 バイト) 超過 */
    public static function tooLarge(): self
    {
        return new self(
            '手順書が大きすぎます。分割してアップロードしてください。',
            AnalysisFailureReason::TooLarge,
        );
    }

    /** パイプラインの実時間 deadline 超過 / provider の応答が client timeout を超えた */
    public static function timedOut(): self
    {
        return new self(
            '解析が時間内に終わりませんでした。手順書を分割して短くするか、'
            .'しばらく時間をおいて再実行してください。',
            AnalysisFailureReason::TimedOut,
        );
    }

    /** provider の混雑 (429 / 529 / 500・502・503・504)。入力を変えても解決しないため待つ以外の行動がない */
    public static function providerBusy(): self
    {
        return new self(
            'AI が混み合っています。しばらく時間をおいて再実行してください。',
            AnalysisFailureReason::ProviderBusy,
        );
    }

    /**
     * 応答の防御検査で拒否された (system prompt の合言葉が応答に現れた)。
     *
     * ★ 再試行しない理由は「同じ結果になるから」ではない (合言葉は毎回変わるので
     *   再実行で再現するとは限らない)。**安全性の違反が疑われる状態で、課金してまで
     *   もう一度モデルに投げない**という判断である。
     * ★ 文言で**原因を断定しない**。合言葉が保証するのは「system 側の内容が応答に出た」
     *   という検知事実だけで、手順書が原因とは限らない (モデル / provider 側の異常もありうる)。
     *   原因を手順書だと書くと、正当な SOP の記述を利用者に削らせる誘導にもなる。
     */
    public static function unsafeResponse(): self
    {
        return new self(
            '安全検査により、AI の応答を受け取れませんでした。'
            .'もう一度実行しても解消しない場合は、管理者へ連絡してください。',
            AnalysisFailureReason::UnsafeResponse,
        );
    }

    /** 入力の文字コードが壊れており、prompt に載せる前に拒否した (到達しないはずの最後の砦) */
    public static function unreadableEncoding(): self
    {
        return new self(
            '手順書の文字を正しく読み取れませんでした。'
            .'文字コードが壊れている可能性があります。'
            .'別の形式 (Excel・テキスト形式か、文字を選択できる PDF) で保存し直して'
            .'アップロードしてください。',
            AnalysisFailureReason::UnreadableEncoding,
        );
    }

    /** OCR 経路: 媒体が破損・未対応形式で読めない (画素数取得不能 / PDF ページ解析不能等) */
    public static function mediaUnreadable(): self
    {
        return new self(
            '手順書のファイルを読み取れませんでした。破損していないか確認し、'
            .'撮り直すか別のファイルでアップロードしてください。',
            AnalysisFailureReason::MediaUnreadable,
        );
    }

    /** OCR 経路: 容量・画素数・ページ数の上限超過 */
    public static function mediaTooLarge(): self
    {
        return new self(
            '手順書のファイルが大きすぎます。画像は縮小するか、PDF は分割してアップロードしてください。',
            AnalysisFailureReason::MediaTooLarge,
        );
    }

    /**
     * OCR まで試して手順を 1 つも読み取れなかった / 読み取り結果が日本語として成立しない
     * (概念設計「失敗時の利用者向け文言」の終着点)。
     */
    public static function ocrEmptyOrInvalid(): self
    {
        return new self(
            '手順を読み取れませんでした。文字がはっきり写っているか確認して、'
            .'撮り直すか別の形式でアップロードしてください。',
            AnalysisFailureReason::OcrEmptyOrInvalid,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use RuntimeException;

/**
 * AI 解析の失敗 (ユーザー向けメッセージ付き)。AnalysisPipeline が投げ、
 * catch 経路の failJob がメッセージをそのまま error 列に保存する。
 */
final class AnalysisFailedException extends RuntimeException
{
    /** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ・PDF から 1 バイトも取れない) */
    public static function unextractable(): self
    {
        return new self(
            'テキストを抽出できません。画像・スキャンの手順書は現在未対応です。'
            .'Excel・テキスト形式か、文字が選択できる PDF をアップロードしてください。'
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
            .'文字が画像になっている / PDF のテキスト埋め込みが壊れている / '
            .'日本語以外の手順書、のいずれかの可能性があります。'
            .'日本語の手順書を、Excel・テキスト形式か文字を選択できる PDF でアップロードしてください。'
        );
    }

    /** 抽出できたが本文が実質空 (min_text_bytes 未満)。画像扱いと混同しない明示文言 */
    public static function tooShort(): self
    {
        return new self('手順書の本文が短すぎます。もう少し詳しい手順書をアップロードしてください。');
    }

    /** LLM 入力上限 (UTF-8 バイト) 超過 */
    public static function tooLarge(): self
    {
        return new self('手順書が大きすぎます。分割してアップロードしてください。');
    }

    /** パイプラインの実時間 deadline 超過 / provider の応答が client timeout を超えた */
    public static function timedOut(): self
    {
        return new self(
            '解析が時間内に終わりませんでした。手順書を分割して短くするか、'
            .'しばらく時間をおいて再実行してください。'
        );
    }

    /** provider の混雑 (429 / 529 / 500・502・503・504)。入力を変えても解決しないため待つ以外の行動がない */
    public static function providerBusy(): self
    {
        return new self('AI が混み合っています。しばらく時間をおいて再実行してください。');
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
            .'もう一度実行しても解消しない場合は、管理者へ連絡してください。'
        );
    }

    /** 入力の文字コードが壊れており、prompt に載せる前に拒否した (到達しないはずの最後の砦) */
    public static function unreadableEncoding(): self
    {
        return new self(
            '手順書の文字を正しく読み取れませんでした。'
            .'文字コードが壊れている可能性があります。'
            .'別の形式 (Excel・テキスト形式か、文字を選択できる PDF) で保存し直して'
            .'アップロードしてください。'
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support\ForbiddenStatement;

/**
 * 字句 (トークン) として禁止する文の語彙。
 *
 * ★正典 (lctl feature: forbidden-statement-token-gate) の v1 が定める 3 つ
 *   (出力する文 / 飛び越す文 / 大域を持ち込む文) に、テンプレート実装が唯一の拡張として持つ
 *   開始タグ付きの出力記法を加えた **4 つに限る**。
 * ★`print` は正典が明示的に対象外としており、**禁止語彙の拡張は台帳の議題として
 *   起こす決まり**になっている。ここで勝手に足さない。
 * ★case 名に半予約語 (`Echo` 等) を使わないのは意図的である。
 *   本 enum 自身が走査対象 (`tests/`) に置かれるため、case 名を `Echo` にすると
 *   本ファイルが該当トークンを含むことになり、読み飛ばし規則に依存して緑になる。
 *   検査の正しさを検査対象自身の書き方に依存させない。
 */
enum ForbiddenStatementKind: string
{
    /** 応答の組み立て経路を迂回して直接出力へ書き出す文。 */
    case EchoStatement = 'echo';

    /** 開始タグ付きの出力記法。上と同じことを別の綴りで行う。 */
    case ShortEchoTag = 'short_echo_tag';

    /** 任意の位置へ飛び、構造から制御フローが読めなくなる文。 */
    case GotoStatement = 'goto';

    /** DI コンテナ経由の依存解決を迂回し、差し替えられない結合を作る文。 */
    case GlobalStatement = 'global';

    /**
     * トークン ID から語彙を引く (該当しなければ null)。
     *
     * ★**網羅 `match` で書き、到達不能な分岐を作らない**。
     *   写像が全 case を覆っていることは走査器の自己検査が固定する。
     */
    public static function fromTokenId(?int $tokenId): ?self
    {
        return match ($tokenId) {
            T_ECHO => self::EchoStatement,
            T_OPEN_TAG_WITH_ECHO => self::ShortEchoTag,
            T_GOTO => self::GotoStatement,
            T_GLOBAL => self::GlobalStatement,
            default => null,
        };
    }

    /** 読み飛ばし規則の適用対象か (開始タグ付き出力記法は文脈を持たないので対象外)。 */
    public function needsContextCheck(): bool
    {
        return $this !== self::ShortEchoTag;
    }

    /** 失敗メッセージ用の表示名。 */
    public function label(): string
    {
        return match ($this) {
            self::EchoStatement => 'echo 文',
            self::ShortEchoTag => '開始タグ付きの出力記法',
            self::GotoStatement => 'goto 文',
            self::GlobalStatement => 'global 文',
        };
    }
}

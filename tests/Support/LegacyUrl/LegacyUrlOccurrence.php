<?php

declare(strict_types=1);

namespace Tests\Support\LegacyUrl;

/**
 * 旧 URL / 撤去 route 名の検出 1 件。
 *
 * ★`ruleId` が識別するのは**抽出方式** (どの種別のファイルをどう読んだか) までである。
 *   同じファイル内での構文の入れ替わりまでは表さないので、許可目録は
 *   `ruleId` に加えて**一致した語 (`matched`)** と**件数**でキーを作る
 *   (`LegacyUrlAllowance::keyOf()`)。ここを `ruleId` だけにすると
 *   「同じ件数で別の旧 URL へ置き換える」迂回が通る。
 */
final readonly class LegacyUrlOccurrence
{
    public function __construct(
        /** リポジトリルート相対パス。 */
        public string $relative,
        /** 1 起点の行番号。 */
        public int $line,
        /** 検出規則の安定 ID (`LegacyUrlScanner::RULE_*`)。 */
        public string $ruleId,
        /** 一致した語 (旧パスの根、または撤去 route 名)。許可目録のキーに使う。 */
        public string $matched,
        /**
         * 根から終端までの **path 全体** (撤去 route 名のときは語そのもの)。
         *
         * ★許可目録の**区分ごとの前提**はこちらを見る。根だけで許すと
         *   「同じ根で別の path へ置き換える」迂回を止められない。
         */
        public string $path,
        /**
         * **構文文脈** (`key:<名前>` / `markdown-link` / `text` / `call:<名前>` / `expr`)。
         *
         * ★許可目録のキーに入る。同じファイル・同じ path でも**構文位置を移すと文脈が変わる**ので、
         *   「別の用途へ移して同じ件数で通す」迂回を止められる。
         * ★判定は発見的規則であり、判定できない形は `expr` / `text` へ倒れる
         *   (その場合は文脈による区別が効かない。走査器の docblock に明記)。
         */
        public string $context,
    ) {}

    /** 失敗メッセージ用の 1 行表現。 */
    public function describe(): string
    {
        return "{$this->relative}:{$this->line} [{$this->ruleId}/{$this->context}] {$this->path}";
    }
}

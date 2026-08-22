<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

/**
 * 撤去語の一致様式 (語ごとに宣言する)。
 *
 * ★AGENTS.md「静的検査 (gate) と走査器の共通規約」(e) に従い、判定は
 *   **宣言した区切りで分割したトークンの完全一致**で行う。正規表現の語境界にも
 *   素の部分文字列一致にも頼らない。
 */
enum TermMatchMode
{
    /**
     * トークン文字集合 `[A-Za-z0-9_.-]` の最長連なり (run) 全体と完全一致 (大小区別あり)。
     *
     * `password.confirm:web` は `:` が区切りなので run が `password.confirm` になり一致する。
     * `password.confirm.store` / `x-password.confirm` は run 全体が違うので一致しない。
     */
    case ExactRun;

    /**
     * run を `.` で割ったいずれかの segment と完全一致 (大小区別あり)。
     *
     * 設定パス表記 (`manual.ocr_analysis_enabled`) に当てるための様式。
     */
    case RunSegment;

    /**
     * 非 PHP の生テキストに現れる**完全修飾クラス名 + `::` + メソッド名**の完全一致。
     *
     * ★専用のトークン文字集合 `[A-Za-z0-9_\\]` を使う。`ExactRun` の文字集合では
     *   `\` と `:` が区切りになるため、完全修飾参照は複数の run へ割れて
     *   **原理的に一致しない**。
     * ★PHP のクラス参照として使われる文字列を守る様式なので、PHP の言語仕様に合わせて
     *   クラス部・メソッド部とも **ASCII 大小を無視**して比較し、先頭の `\` は落として正規化する。
     */
    case FqcnMethodReference;

    /**
     * 非 PHP の生テキストに現れる**完全修飾クラス名**そのものの完全一致。
     *
     * ★`FqcnMethodReference` と同じトークン文字集合 `[A-Za-z0-9_\\]` を使い、
     *   先頭の `\` を落とし、連続する `\` を 1 つへ畳んで (二重引用符内の
     *   エスケープ表記 `A\\B` を吸収する)、ASCII 大小を無視して比べる。
     * ★撤去した middleware の**実体クラス名**は、拡張子なしの PHP スクリプト・シェル・
     *   YAML など「PHP として扱わないファイル」からも実行可能な参照になり得るので、
     *   クラス名だけの様式が要る (メソッド名を伴わない)。
     */
    case FqcnReference;
}

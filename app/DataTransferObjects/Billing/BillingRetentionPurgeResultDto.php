<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\BillingRetentionTarget;

/**
 * 保持期間 purge の 1 target 分の結果。
 *
 * **任意メタデータ領域 (`array<string, mixed>`) は持たせない** — 何が入るか型で分からない
 * 領域を作ると、そこに organization id やメールアドレスが載って運用ログへ漏れる。
 *
 * 件数の関係:
 *   candidates      = 保持期限を超えた**決着対象**の件数 (purge 前)
 *   processed       = 実際に決着させた件数 (**決着対象のうち消えた行数**。台帳の畳み込みが
 *                     再集約のために消して作り直した寄与中の繰越行は数えない)
 *   failClosed      = 安全のため残した件数 (起算不能の異常 + 参照中で消せないもの)
 *   expiredRemaining = purge 後に残った決着対象の件数
 *
 * ★**「決着対象」の共通定義**: 各 target の保持ポリシーにより**物理削除または不可逆な
 *   明細除去の対象となるレコード数**であり、**いま継続状態を表している集約レコードは含まない**。
 *   台帳 (`ticket_ledger_entry`) では `kind = carry_forward` の繰越行のうち
 *   **まだ残高に寄与しているもの (無期限 / 失効時刻が未来) だけ**がその集約レコードに該当する。
 *   **失効した繰越行は決着対象に含まれる** — 残高に寄与しなくなった時点で物理削除の対象であり、
 *   除外したままにすると「失効済みの繰越行だけが残った組織」が
 *   永久に処理されないまま `remaining = 0` と報告される (fail-open)。
 *   他の 6 target は集約レコードを持たないので実効値は変わらない。
 *   **この定義が正本**であり、`docs/architecture.md` と
 *   `docs/billing-retention-runbook.md` はここを参照する (2 か所に書くと必ず食い違う)。
 *   **将来ほかの target が集約レコードを持ったら、この定義を読んで分類する義務がある。**
 *
 * ★**`candidates` / `processed` / `expiredRemaining` は同じ母集団を数える**。
 *   **想定外の失敗が 0 件で、かつ実行中に決着対象の集合が変化しない**なら
 *   `candidates = processed + expiredRemaining` が成り立つ
 *   (失敗した単位は巻き戻るので `expiredRemaining` 側に残る)。
 *   `processed` に「決着対象でない行の削除」を混ぜるとこの恒等式が壊れ、監視値が意味を失う。
 *   **逆は成り立たない** — 実行中に新しい期限超過レコードが commit されれば
 *   (台帳の追記経路の一部は保持処理と排他しない) 述語が正しくても崩れる。
 *   崩れたときは「述語ずれ」と「実行中の母集団変化」の**両方**を疑うこと。
 *
 * **`failClosed` は「安全に残した」であって「規約を満たした」ではない**。
 * 規約 (最長 N 年) を満たしたと言えるのは `expiredRemaining === 0` のときだけである。
 */
final readonly class BillingRetentionPurgeResultDto
{
    public function __construct(
        public BillingRetentionTarget $target,
        public int $candidates,
        public int $processed,
        public int $failClosed,
        public int $unexpectedFailures,
        public int $expiredRemaining,
    ) {}

    /**
     * dry-run (1 行も消さない) の結果。
     *
     * 何も消していないのだから残存 = 候補である (楽観的に 0 と報告しない)。
     */
    public static function dryRun(
        BillingRetentionTarget $target,
        int $candidates,
        int $failClosed,
    ): self {
        return new self(
            target: $target,
            candidates: $candidates,
            processed: 0,
            failClosed: $failClosed,
            unexpectedFailures: 0,
            expiredRemaining: $candidates,
        );
    }

    public function hasFailClosedRecords(): bool
    {
        return $this->failClosed > 0;
    }

    public function hasUnexpectedFailures(): bool
    {
        return $this->unexpectedFailures > 0;
    }

    /**
     * 規約文面の公開 (PR-C3) に進んでよいか。
     *
     * **分類を問わず期限超過 0 件**が条件である。`failClosed` を除外して「安全に残したものは
     * 数えない」とすると、規約が宣言した年数を超えた記録が残ったまま「準拠した」と言えてしまう。
     */
    public function isPublicationReady(): bool
    {
        return $this->failClosed === 0
            && $this->unexpectedFailures === 0
            && $this->expiredRemaining === 0;
    }
}

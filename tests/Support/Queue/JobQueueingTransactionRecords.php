<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

/**
 * `RecordsJobQueueingTransactionLevel::capture()` の記録先 (可変 collector)。
 * **テスト Support 内部だけの機構**である。
 *
 * ★ 配列で返すと PHP の copy-on-write により「capture 後に記録が増えないこと」を
 *   検査できない (不活性化を消しても呼び出し側の配列は増えず緑のままになる)。
 *   同一オブジェクトを参照させることで、その自己テストが実効を持つ。
 * ★ 保持するのは **クラス名 (string) と深さ (int) だけ**で job payload は持たない
 *   (不活性 listener がテスト終了まで生き残るため)。
 *
 * ★ PSR-4 の 1 ファイル 1 クラス規約に従い `RecordsJobQueueingTransactionLevel` とは
 *   別ファイルに置く (詳細設計では 1 ファイルに併記されていた)。
 */
final class JobQueueingTransactionRecords
{
    /** @var list<array{job: string, level: int}> */
    private array $records = [];

    /** capture 終了後に false へ倒され、以降の記録を捨てる (listener の不活性化)。 */
    public bool $active = true;

    public function record(string $job, int $level): void
    {
        if ($this->active) {
            $this->records[] = ['job' => $job, 'level' => $level];
        }
    }

    /** @return list<array{job: string, level: int}> */
    public function all(): array
    {
        return $this->records;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use RuntimeException;

/**
 * 生成 1 回分の入力条件 (readonly DTO)。
 *
 * ★CLI が `LedgerPins` から組み立て、**service はこれだけを見る**
 *   (service は `LedgerPins` を読まない)。こうしないと合成した正典台帳での
 *   正常系テストが書けない (CLI が `dirname(__DIR__)` で自分のリポジトリを指す作りだと、
 *   一時リポジトリでプロセスを起動しても出力先が本物のリポジトリになる)。
 *
 * ★**root を差し替える隠しオプションは CLI には作らない**。root を引数で受けるのは
 *   service とこの DTO までで、CLI 側は `dirname(__DIR__)` 固定である。
 *
 * コンストラクタで落とす 6 形:
 *  1. 期待 sha256 が 64 桁小文字 hex でない
 *  2. 期待 source commit が 40 桁小文字 hex でない
 *  3. 出力先 2 つが同一
 *  4. 出力先が root 配下の**規定のパス**でない
 *  5. 前世代台帳がある場合にその `role` が `App` でない
 *  6. `adoptNewTemplateLedger === false` なのに前世代台帳の `generated_at_commit` が
 *     期待 source commit と一致しない
 *
 * 5 は CLI からは到達しない (CLI は role ガードで**拒否 = 終了コード 3** を先に返す)。
 * 型の側でも閉じておくための防御であり、単体テストが直接構築して固定する。
 */
final readonly class FingerprintGenerationContext
{
    public function __construct(
        public string $root,
        public string $expectedTemplateLedgerSha256,
        public string $expectedSourceCommit,
        public bool $adoptNewTemplateLedger,
        public ?FingerprintLedger $previousLedger,
        public string $fingerprintOutputPath,
        public string $debtOutputPath,
    ) {
        if (preg_match('/^[0-9a-f]{64}$/', $expectedTemplateLedgerSha256) !== 1) {
            throw new RuntimeException('期待する正典台帳の sha256 が 64 桁小文字 hex でない');
        }
        if (preg_match('/^[0-9a-f]{40}$/', $expectedSourceCommit) !== 1) {
            throw new RuntimeException('期待する正典台帳の generated_at_commit が 40 桁小文字 hex でない');
        }
        if ($fingerprintOutputPath === $debtOutputPath) {
            throw new RuntimeException('2 つの生成物の出力先が同一である');
        }

        $base = rtrim($root, '/');
        if ($fingerprintOutputPath !== $base.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH) {
            throw new RuntimeException('指紋台帳の出力先が規定のパスでない: '.$fingerprintOutputPath);
        }
        if ($debtOutputPath !== $base.'/'.AdoptionDebtInventory::INVENTORY_PATH) {
            throw new RuntimeException('採用時債務一覧の出力先が規定のパスでない: '.$debtOutputPath);
        }

        if ($previousLedger !== null && $previousLedger->role !== LedgerRole::App) {
            throw new RuntimeException('前世代の指紋台帳の role が app でない');
        }
        if (! $adoptNewTemplateLedger
            && $previousLedger !== null
            && $previousLedger->generatedAtCommit !== $expectedSourceCommit) {
            throw new RuntimeException(
                '前世代の指紋台帳の generated_at_commit が pin と一致しない '
                    ."(前世代: {$previousLedger->generatedAtCommit} / pin: {$expectedSourceCommit})",
            );
        }
    }

    /** 規定の出力先を root から組み立てる (CLI と単体テストで同じ導出を使う)。 */
    public static function forRoot(
        string $root,
        string $expectedTemplateLedgerSha256,
        string $expectedSourceCommit,
        bool $adoptNewTemplateLedger,
        ?FingerprintLedger $previousLedger,
    ): self {
        $base = rtrim($root, '/');

        return new self(
            root: $root,
            expectedTemplateLedgerSha256: $expectedTemplateLedgerSha256,
            expectedSourceCommit: $expectedSourceCommit,
            adoptNewTemplateLedger: $adoptNewTemplateLedger,
            previousLedger: $previousLedger,
            fingerprintOutputPath: $base.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH,
            debtOutputPath: $base.'/'.AdoptionDebtInventory::INVENTORY_PATH,
        );
    }
}

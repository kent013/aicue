<?php

declare(strict_types=1);

namespace App\DataTransferObjects\EnterpriseSso;

use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptStoreFailure;

/**
 * 試行の使用権の取得結果 (**業務上の判定**であって例外ではない)。
 *
 * 4 分類と、行・セッションの秘密・外向きの応答の対応:
 *
 * | 分類 | 行 | セッションの秘密 | 外向きの応答 |
 * |---|---|---|---|
 * | 成功 | 消えた | **消す** | ログイン確定へ進む |
 * | 期限切れ | 消えた | **消す** | 一様な失敗 |
 * | 不在 | 無い | **消す** (再開できる試行が無い) | 一様な失敗 |
 * | 結合の不一致 | **残る** | **残す** (攻撃者が被害者の結合を消せる形にしない) | 一様な失敗 |
 *
 * 外向きの応答は 4 通りとも**同一**である。区別は内部にだけ存在する。
 *
 * ★DB・基盤の障害は本型に**入らない** — 例外として伝播しトランザクションごと巻き戻る
 *   ({@see EnterpriseSsoAttemptStoreFailure})。
 *   混ぜると「排他が壊れた」という重大な事実が一様な拒否に隠れる。
 */
final readonly class AttemptConsumeResult
{
    private function __construct(
        public bool $succeeded,
        /** 行が不可逆に消えたか (セッションの秘密を消してよいかの判断に使う)。 */
        public bool $rowIsGone,
        public ?ConsumedLoginAttempt $attempt,
    ) {}

    public static function consumed(ConsumedLoginAttempt $attempt): self
    {
        return new self(true, true, $attempt);
    }

    /** 行が無い (そもそも作られていない / 既に使われた)。再開できる試行が無い。 */
    public static function notFound(): self
    {
        return new self(false, true, null);
    }

    /** 期限切れ。**拒否と同時に行を消す** (トランザクションは巻き戻さない)。 */
    public static function expired(): self
    {
        return new self(false, true, null);
    }

    /** ブラウザ結合の不一致 (login CSRF)。**行もセッションの秘密も残す**。 */
    public static function bindingMismatch(): self
    {
        return new self(false, false, null);
    }
}

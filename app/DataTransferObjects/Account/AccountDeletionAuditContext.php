<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Account;

/**
 * 削除の到達経路 (監査 metadata 用。T160 / bug-hunt F-4-Q1)。
 *
 * ★**観測専用**である。この値で分岐する処理は 1 つも作らない (防御ではない)。
 *
 * ★`deleteAccount()` は本 context を**必須引数**で受け取る。既定引数にすると
 *   「HTTP 外なので null」と「HTTP 呼び出し元の渡し忘れ」が区別できなくなるため、
 *   名前つきコンストラクタで**判断を明示させる** (deny-by-default)。
 */
final readonly class AccountDeletionAuditContext
{
    private function __construct(
        public ?string $route,
        public ?string $method,
    ) {}

    /** HTTP 経由の削除 (route 名と HTTP メソッドを残す) */
    public static function http(?string $route, string $method): self
    {
        return new self($route, $method);
    }

    /** HTTP 外 (猶予期間の日次執行・コンソール)。route / method とも null が正常値 */
    public static function nonHttp(): self
    {
        return new self(null, null);
    }
}

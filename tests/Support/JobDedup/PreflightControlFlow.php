<?php

declare(strict_types=1);

namespace Tests\Support\JobDedup;

/**
 * preflight が所有権喪失をどう伝えるか。
 *
 * ★ Manual (例外で中断) と Billing (structured return) を**無理に統合しない**
 *   (AGENTS.md 思考原則 4)。どちらであるかを目録が明示し、
 *   gate はそれに一致する戻り型を要求する。
 */
enum PreflightControlFlow
{
    /** 失われていたら例外を投げる (戻り型 void)。Manual ドメイン */
    case ThrowsOnLoss;

    /** 送信してよいかを bool で返す (戻り型 bool)。Billing ドメイン */
    case ReturnsBoolean;
}

<?php

declare(strict_types=1);

namespace Tests\Support\ForbiddenStatement;

/**
 * 「禁止する文をそこに書くことが正しい」と裁定した理由の分類。
 *
 * `tests/Architecture/ForbiddenStatementTokenInvariantTest.php` が deny-by-default で
 * 「禁止する文を持つファイルは本 enum + 30 文字以上の具体的根拠 + 件数付きで
 *  目録に登録済みであること」を機械強制する。
 *
 * ★case は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「書いてはいけない箇所」である。
 * ★case を 1 つしか持たないのは意図的 (今必要なものだけ作る)。
 *   2 つ目が現れたときに「新しい case を足す差分」として必ず表面化し、
 *   その場で「そもそも書くべきか」を再検討させるのが狙い
 *   (`Tests\Support\Security\StrayHttpEgressExemption` と同じ作法)。
 */
enum ForbiddenStatementExemption: string
{
    /**
     * artisan を通さない素の PHP として起動される CLI の、人間向け標準出力。
     *
     * 適用条件 (すべて満たすこと):
     *  - `php <path>` として**別プロセスで直接**起動される (HTTP 応答の経路に載らない)
     *  - Laravel の Console 出力機構 (`$this->line()` 等) を持たない
     *    (持てるなら `Command` にすべきで、例外にはしない)
     *  - 標準出力への提示がそのスクリプトの機能そのものである
     */
    case StandaloneCliStdout = 'standalone_cli_stdout';
}

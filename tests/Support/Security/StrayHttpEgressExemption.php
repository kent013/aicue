<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * 「テストレーンの HTTP 出口既定拒否を opt-out することが正しい」と裁定した理由の分類。
 *
 * tests/Architecture/StrayHttpEgressLaneGateTest.php が deny-by-default で
 * 「opt-out 呼び出しを持つファイルは本 enum + 30 文字以上の具体的根拠付きで
 *  inventory に登録済みであること」を機械強制する。
 *
 * opt-out 呼び出しの定義 (gate の契約と一致させること):
 *  - `allowStrayRequests(...)` — **引数を問わず全件**
 *    (null は既定拒否を OFF に、配列は許可集合を置換する)
 *  - `preventStrayRequests(...)` のうち **引数があるもの全件**
 *    (`false` literal に限らない。`$flag` / `(bool) 0` / `prevent: false` も対象。
 *     引数ゼロの `preventStrayRequests()` はレーン既定と同値の重複宣言なので対象外)
 *
 * ★case は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「opt-out してはいけない箇所」である。
 *
 * ★case を 1 つしか持たないのは意図的 (今必要なものだけ作る)。
 *   2 つ目の opt-out が現れたときに「新しい case を足す差分」として必ず表面化し、
 *   その場で「そもそも opt-out すべきか」を再検討させるのが狙い。
 */
enum StrayHttpEgressExemption: string
{
    /**
     * レーン既定 guard そのものの定義箇所。
     *
     * 適用条件 (すべて満たすこと):
     *  - そのファイルが `Http::allowStrayRequests(...)` を呼ぶ唯一の理由が
     *    「レーン既定の許可集合を設定すること」である
     *  - 許可集合が `StrayHttpRequestGuard::ALLOWED_URL_PATTERNS` 定数 1 か所に閉じている
     *  - `allowStrayRequests(null)` / `preventStrayRequests(false)` を**呼ばない**
     *    (= 既定拒否そのものを外さない)
     */
    case GuardDefinitionSite = 'guard_definition_site';
}

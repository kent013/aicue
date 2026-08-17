<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use Carbon\CarbonImmutable;
use Webmozart\Assert\Assert;

/**
 * 「この利用者は最後にいつこのシステムに入ったか」の読み取り。
 *
 * **記録点を増やさない**: 出所は security_audit_events の `login` 行だけである
 * (書き込みの窓口は SecurityEventRecorder。本クラスは読み取り専用で 1 行も書かない)。
 * users に last_login_at 列を持たない理由は
 * devnotes/20260817-0909-user-last-login-at/conceptual-design.md §2 が正本。
 *
 * **数える対象**: `Illuminate\Auth\Events\Login` が発火したセッション確立すべて
 * (パスワード / 2FA 完了 / パスキー / SSO / remember me による自動復元 / 登録直後)。
 * remember me を**除外しない**ことが App\Listeners\Auth\StampRecentAuthOnLogin との
 * 意図的な差である (あちらの問いは「たった今資格情報を提示したか」で、本クラスの問いは
 * 「最後に入ったのはいつか」。同じイベントを別条件で読む 2 概念であり統合しない)。
 * 機械アクセス (API キー / OAuth トークン) は Login を発火しないため構造的に入らない。
 *
 * ⚠ **前提 1**: users プロバイダを持つセッション系 guard は現在 `web` だけである。
 * 新しいセッション guard / loginUsingId / impersonation / magic-link を足すときは
 * 数え方を読み直すこと (StampRecentAuthOnLogin の ⚠ 注記と同じ性質の前提に立っている)。
 *
 * ⚠ **前提 2 (guard で絞らない理由)**: 本クラスは `metadata.guard` を見ない。
 * Filament の管理画面 (`admin` guard) のログインが混ざらないのは、
 * App\Models\AdminUser が App\Models\User の派生ではない**別クラス**であり、
 * RecordSecurityEvent::asUser() が null を返して `user_id` が付かないためである
 * (= 構造で保証されている)。JSON 列 `metadata` への述語で絞る形は採らない。理由は 3 つ:
 * (1) 索引が効かなくなる、
 * (2)「どの guard を数えるか」の定義が記録側と読み取り側の 2 か所に分かれて食い違う、
 * (3) **本クラスが数えたいのは「web guard のログイン」ではなく
 *     「App\Models\User について発生したログイン」である**。guard 名で絞ると、
 *     将来 users provider の上に正当に追加されたセッション guard を**無言で除外する**。
 * **この前提は Feature テストで固定してある** (AdminUser が User を継承する変更が入れば赤くなる)。
 *
 * ⚠ **保証しないもの**: 値は「最終**ログイン**」であって「最終**活動**」ではない。
 * remember me の cookie が生きている間は再ログインが起きないため、値は
 * 実際の利用より古くなりうる (仕様。doc/02 §2.4 の項目名に従う)。
 * また security_audit_events の保持期間は未確定であり、将来 purger が入れば
 * 古い値から失われる (この依存は RetentionTableRegistry の根拠文に記録してある)。
 */
final class LastLoginLookup
{
    /**
     * 利用者 id の集合に対する最終ログイン時刻の写像を **1 クエリ**で作る。
     *
     * 行ごとに問い合わせない (N+1 を作らない)。ログイン記録の無い利用者は
     * **キーごと現れない** (null を詰めない = 呼び出し側が `?? null` で受ける)。
     *
     * @param  list<int>  $userIds
     * @return array<int, CarbonImmutable>
     */
    public function forUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return []; // 空集合に whereIn を投げない (アーリーリターン)
        }

        $rows = SecurityAuditEvent::query()
            ->select('user_id')
            ->selectRaw('max(occurred_at) as last_login_at')
            ->whereIn('user_id', $userIds)
            ->where('event_type', SecurityEventType::Login->value)
            ->groupBy('user_id')
            // 集計列にはモデルの casts が効かない (occurred_at の cast は別名には伝播しない)。
            // driver 差 (string / DateTime) を SQL 層で吸収せず、framework の cast で閉じる。
            ->withCasts(['last_login_at' => 'immutable_datetime'])
            ->get();

        /** @var array<int, CarbonImmutable> $map */
        $map = [];
        foreach ($rows as $row) {
            $userId = $row->getAttribute('user_id');
            // bigint の PHP 表現は driver 設定で int / integer-string に揺れる。
            // numeric ではなく integerish で受ける (numeric は 1.5 のような float も通してしまう)
            Assert::integerish($userId);

            $lastLoginAt = $row->getAttribute('last_login_at');
            // 集計値が null になるのは group が成立しない場合だけなので、ここは常に日時である。
            // 黙って捨てず instanceof で narrowing する (mixed を外へ出さない = level 10 対応)
            Assert::isInstanceOf($lastLoginAt, CarbonImmutable::class);

            $map[(int) $userId] = $lastLoginAt;
        }

        return $map;
    }
}

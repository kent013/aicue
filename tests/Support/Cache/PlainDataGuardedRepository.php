<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Illuminate\Cache\Repository;
use UnitEnum;

/**
 * キャッシュ書き込みの**値の実体**を検査する受け皿 (テスト実行時層)。
 *
 * ## なぜ受け皿 (Repository) 境界なのか (イベント購読ではない)
 *
 * `Illuminate\Cache\Events\KeyWritten` の購読は**差し替え可能な境界**であり、
 * テスト本体の `Event::fake()` や store 設定の `'events' => false` で無効化できる。
 * `Illuminate\Cache\Repository` の書き込みメソッドはイベント層より下にあるため、
 * どちらの影響も受けない。
 *
 * ## なぜ 4 メソッドで足りるのか (vendor 実読で確認済み)
 *
 * set → put / setMultiple → putMany / remember → rememberWithWarmth → put /
 * sear → rememberForever → forever / flexible → putMany / offsetSet → put /
 * putMany($v, null) → putManyForever → forever。
 * 合流が将来変わったら CachePayloadPlainDataGuardTest の実 API 経由テストが落ちる。
 * ★これは**標準 API の値の合流**についての主張であって、`Store` へ直接届く経路の
 *   完全性の主張ではない (そちらは静的層 L4 の担当)。
 *
 * ## 境界迂回として落とすもの
 *
 * - `tags()` — vendor の実装が `new TaggedCache($this->store, ...)` を素で生成するため、
 *   継承しても以降の書き込みが検査を通らない。加えて本番の保管方式 (database store) は
 *   タグ非対応 (`supportsTags()` が false) なので、タグを使う書き方は本番で例外になる
 * - `setStore()` — 受け皿の保管先を差し替える口 (vendor に呼び出し元 0 件)
 * - `__call()` — macro は**無条件に**落とす。macro の closure は `$this->store` へ
 *   直接到達でき、末端 4 メソッドを通らない (「同一テスト内で登録し、使い、消す」形も
 *   使用時点で捕まる)。macro でない素通しは、**保管先の非 payload API として名指しで
 *   分類した語彙だけ**を通し、それ以外は落とす (`STORE_PASSTHROUGH_METHODS`)
 *
 * ## 保管先への素通しを名指しで分類する理由 (deny-by-default)
 *
 * `Illuminate\Cache\Repository` は **`lock()` / `restoreLock()` を宣言していない**。
 * `Cache::lock(...)` は `CacheManager::__call()` → `Repository::__call()` →
 * `$this->store->lock(...)` の素通しで届く (vendor 実読)。本リポジトリはこの形を
 * 6 ファイルで使っており (静的層の role=lock-only)、排他オブジェクトは payload を運ばない。
 * よって「payload を運ばない排他 2 語彙**だけ**」を名指しで通し、それ以外の素通しは落とす。
 * この 2 語彙が静的層の TERMINAL 語彙 (payload を運ばないと分類した語彙) の**部分集合**である
 * ことは tests/Architecture/CachePayloadPlainDataGateTest.php の検査 L4g が機械で固定する
 * (許可を 2 か所で別々に育てられないようにするため)。
 *
 * ## 保証しないもの
 *
 * - **`getStore()` は落とさない**。vendor 自身が正常系で呼ぶためである — 実読の根拠:
 *   `Illuminate\Cache\RateLimiter::withoutSerializationOrCompression()` (hit/increment の経路) /
 *   `Illuminate\Cache\Repository::flushLocks()` (自己呼び出し) /
 *   `Illuminate\Console\Scheduling\CacheEventMutex` / `Illuminate\Console\CacheCommandMutex` /
 *   `Illuminate\Cache\Limiters\ConcurrencyLimiterBuilder` / `Illuminate\Cache\MemoizedStore`。
 *   よって「保管先を直接取得して書く」形を塞ぐのは**静的層 (L4) だけ**であり、
 *   vendor が `getStore()` 経由で書く値は実行時層に見えない
 * - **素通しを許した 2 語彙の先**は見ない (`$this->store->lock(...)` が保管先で何をするかは
 *   検査しない。排他は payload を持たない、が根拠である)
 * - `increment` / `decrement` は store 直行だが整数しか書けないので検査しない
 *
 * ## 許可一覧を持たない (payload について)
 *
 * vendor の書き込みも対象に含める。`config/cache.php` の `serializable_classes => false` の下では
 * **誰が入れたかに関わらず**オブジェクトを入れれば本番の読み出しが失敗するため、
 * vendor の検出は誤検出ではなく本番の潜在バグの発見である (家系の裁定 AG-107「例外を作らない」)。
 * 上の `STORE_PASSTHROUGH_METHODS` は**値を運ばない API の分類**であって、
 * 「この呼び出し元なら値を見逃す」という許可ではない。
 */
final class PlainDataGuardedRepository extends Repository
{
    /**
     * 保管先へ素通しさせる非 payload API (全小文字)。
     *
     * `Illuminate\Cache\Repository` が宣言しておらず、`__call()` 経由で
     * `Illuminate\Contracts\Cache\LockProvider` へ届く排他 2 語彙だけである。
     *
     * @var list<string>
     */
    public const array STORE_PASSTHROUGH_METHODS = ['lock', 'restorelock'];

    /**
     * {@inheritDoc}
     */
    public function put($key, $value, $ttl = null)
    {
        if (is_array($key)) {
            // vendor と同じく `$key` が配列なら putMany 形 (値の実体は $key 側)。
            PlainDataCacheGuard::inspect('put', '(many)', $key);

            return parent::put($key, $value, $ttl);
        }

        PlainDataCacheGuard::inspect('put', self::describeKey($key), $value);

        return parent::put($key, $value, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function add($key, $value, $ttl = null)
    {
        PlainDataCacheGuard::inspect('add', self::describeKey($key), $value);

        return parent::add($key, $value, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function forever($key, $value)
    {
        PlainDataCacheGuard::inspect('forever', self::describeKey($key), $value);

        return parent::forever($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function putMany(array $values, $ttl = null)
    {
        PlainDataCacheGuard::inspect('putMany', '(many)', $values);

        return parent::putMany($values, $ttl);
    }

    /**
     * {@inheritDoc}
     *
     * @return never
     */
    public function tags($names)
    {
        PlainDataCacheGuard::reportBoundary('tags', self::describeKey($names));
    }

    /**
     * {@inheritDoc}
     *
     * ★vendor の宣言は `public function setStore($store)` で **型宣言を持たない**
     *   (docblock に `@param \Illuminate\Contracts\Cache\Store $store` があるだけ)。
     *   忠実に写すので本クラスは `Store` 型を参照しない
     *   = 「Store 型を参照してよい唯一のサイトは manager の repository()」という主張と矛盾しない。
     *
     * @return never
     */
    public function setStore($store)
    {
        PlainDataCacheGuard::reportBoundary('setStore', get_debug_type($store));
    }

    /**
     * {@inheritDoc}
     *
     * macro は無条件に落とす。macro でない素通しは名指しで分類した非 payload API だけ通す
     * (クラス docblock「境界迂回として落とすもの」/「保管先への素通しを名指しで分類する理由」)。
     */
    public function __call($method, $parameters)
    {
        if (self::hasMacro($method)) {
            PlainDataCacheGuard::reportBoundary('macro', $method);
        }

        if (! in_array(strtolower($method), self::STORE_PASSTHROUGH_METHODS, true)) {
            PlainDataCacheGuard::reportBoundary('storePassthrough', $method);
        }

        return parent::__call($method, $parameters);
    }

    /** 失敗メッセージ用のキー表現 (キーは string / UnitEnum / 配列を取り得る)。 */
    private static function describeKey(mixed $key): string
    {
        if (is_string($key)) {
            return $key;
        }

        if ($key instanceof UnitEnum) {
            return $key::class.'::'.$key->name;
        }

        return get_debug_type($key);
    }
}

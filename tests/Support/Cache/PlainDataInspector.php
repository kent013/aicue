<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

/**
 * キャッシュへ書き込まれる値が**素のデータ**かを再帰検査する純関数。
 *
 * 素のデータ = 配列 / 文字列 / 数値 / 真偽値 / null だけで構成された値
 * (家系の裁定 AG-151 が定めた許可集合。AGENTS.md セキュリティ不変条件 11 と同義)。
 * DTO・Eloquent モデル・Collection・列挙型・日時オブジェクト・クロージャ・resource は違反である。
 *
 * ## 違反の種別
 *
 * - `OBJECT_FOUND` / `RESOURCE_FOUND` — 規約そのものの違反
 * - `UNKNOWN_TYPE` — **上のどれにも当てはまらない型**。閉じた resource が代表例で、
 *   `is_resource()` は false を返すが `is_scalar()` にも当たらない。
 *   「分類できなかったものを素データとして通さない」ための fail-closed 分岐である
 * - `LIMIT_EXCEEDED` — **規約違反ではなく「検査器が素のデータであることを証明できなかった」**
 *   ことを表す。自己参照配列 (`$v['self'] = &$v;`) は素朴な再帰走査を停止させないため、
 *   深さ・ノード数の上限を置き、超過は fail-closed で違反として返す
 *
 * ## 上限値の根拠
 *
 * - 深さ 32: `json_decode` の既定深さ 512 より十分浅く、キャッシュ payload としては 32 段でも異常に深い
 * - ノード 10000: **根の値を 1 と数えた総ノード数**。1 件のキャッシュ entry としては十分大きい
 *
 * 境界の直前・直後は tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が pin する。
 *
 * ## 保証しないもの
 *
 * - **値の意味**は見ない (素のデータであれば内容は問わない)
 * - 配列のキーは見ない (PHP は配列キーを int|string に限るので、キーがオブジェクトになる形は無い)
 * - **保管先へ渡ったあとの変換**は見ない (store 側の直列化・圧縮は対象外)
 */
final class PlainDataInspector
{
    /** 走査の最大深さ (配列の入れ子段数)。超過は LIMIT_EXCEEDED。 */
    public const int MAX_DEPTH = 32;

    /** 走査の最大ノード数 (**根の値を 1 と数える**)。超過は LIMIT_EXCEEDED。 */
    public const int MAX_NODES = 10000;

    /**
     * 値が素のデータかを再帰検査し、違反を返す (空配列 = 素のデータ)。
     *
     * @return list<string> "<パス> = <種別>(<詳細>)" の形
     */
    public static function violations(mixed $value, string $path = 'value'): array
    {
        /** @var list<string> $violations */
        $violations = [];
        $nodes = 0;

        self::walk($value, $path, 0, $violations, $nodes);

        return $violations;
    }

    /**
     * @param  list<string>  $violations
     */
    private static function walk(mixed $value, string $path, int $depth, array &$violations, int &$nodes): void
    {
        $nodes++;
        if ($nodes > self::MAX_NODES) {
            if (! self::alreadyReportedLimit($violations, 'nodes')) {
                $violations[] = $path.' = LIMIT_EXCEEDED(nodes)';
            }

            return;
        }

        // ★許可集合を**先に**判定して早期 return する (許可の定義を 1 か所に閉じる)。
        if ($value === null || is_scalar($value)) {
            return;
        }

        if (is_object($value)) {
            $violations[] = $path.' = OBJECT_FOUND('.$value::class.')';

            return;
        }

        if (is_resource($value)) {
            $violations[] = $path.' = RESOURCE_FOUND('.get_resource_type($value).')';

            return;
        }

        if (! is_array($value)) {
            // ★閉じた resource が代表例。is_resource() は false、is_scalar() も false。
            //   分類できないものを素データとして通さない (fail-closed)。
            $violations[] = $path.' = UNKNOWN_TYPE('.get_debug_type($value).')';

            return;
        }

        if ($depth + 1 > self::MAX_DEPTH) {
            $violations[] = $path.' = LIMIT_EXCEEDED(depth)';

            return;
        }

        foreach ($value as $key => $element) {
            self::walk(
                $element,
                $path.'['.(is_int($key) ? (string) $key : "'".$key."'").']',
                $depth + 1,
                $violations,
                $nodes,
            );

            if ($nodes > self::MAX_NODES) {
                return;
            }
        }
    }

    /**
     * @param  list<string>  $violations
     */
    private static function alreadyReportedLimit(array $violations, string $kind): bool
    {
        foreach ($violations as $violation) {
            if (str_ends_with($violation, 'LIMIT_EXCEEDED('.$kind.')')) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Manual;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * 動画マニュアル一覧のキーワード検索 (PC 一覧 / 撮影 PWA 一覧の**共通の正本**)。
 *
 * ここが 1 箇所であることに意味がある: 対象列・LIKE メタ文字のエスケープ規則・
 * 検索語の正規化を面ごとに書くと必ず食い違う (実際 T053 以降、PC 側だけに 200 文字上限があり
 * 撮影 PWA 側には無いという食い違いが生まれていた)。
 *
 * **検索対象** = `video_manuals.title` + 配下 `cuts` の**本文 4 列**。
 * doc/05 §5.2 の「原稿」は narration / subtitle を指すが、本クラスは `scene` を足して
 * 「カット本文」を対象にする。`scene` は `UpdateScenarioRequest` で唯一 `required` の
 * 本文列であり (narration / subtitle_secondary は `present` = 空文字可、
 * subtitle_primary は `nullable`)、外すと**手書きシナリオが本文検索に一切かからない**ため。
 *
 * `cuts.shooting_point` は**対象外**である。撮影者への構図指示 (doc/05 の「撮影ガイド」) で
 * あって作業内容ではなく、「手元を寄りで」のような定型句が多数のマニュアルに散らばるため、
 * 含めると精度だけが落ちる。
 *
 * **対象外だと明言するもの**: 大小文字を区別しない検索、語の分割・同義語・ランキング、
 * SOP 原本 (`source_documents`) の全文検索、作成者名の検索。
 *
 * **保証範囲を誇張しない (LIKE メタ文字のエスケープ)**:
 * `addcslashes($keyword, '%_\\')` が成立するのは **`LIKE` の既定 escape 文字が `\` である
 * DBMS** (PostgreSQL / MySQL) に限る。**sqlite では `\` は既定の escape 文字ではない**ため
 * この規則は成立しない。これは本クラスが新しく持ち込む制約ではなく、
 * 従来の title 検索と**同じ前提**である (本アプリの接続は pgsql)。
 * 検索語は PDO のバインド変数として渡るため、SQL 文字列リテラルの解釈
 * (`standard_conforming_strings`) は関与しない。
 *
 * **大小文字**: pgsql の `like` は**大小文字を区別する**。`abc` で `ABC` は hit しない。
 * これは従来の title 検索と同じ挙動であり、本改善では変えない (面によって挙動を変えないため)。
 *
 * **列名 typo の検出責務**: BODY_COLUMNS の列名を PHPStan は検証しない。
 * 検出は 2 段で負う — (1) 存在しない列は pgsql が `42703 undefined_column` を投げるため
 * 検索を通る**すべての**テストが赤くなる、(2) 4 列それぞれについて
 * 「その列にしか語を持たない manual が hit する」テストが列単位の取りこぼしを見る。
 */
final class ManualKeywordSearch
{
    /**
     * 検索語の最大長 (文字数。バイト数ではない)。
     *
     * **負荷制御のための上限**である。これを超える語を打つと**先頭 200 文字だけで検索される**
     * (打った語と違う条件で検索されることになる)。
     * かつて「title の validation が max:200 だから 201 文字目以降は一致に寄与しない」という
     * 根拠が書かれていたが、`cuts.narration` / `cuts.subtitle_secondary` は max:2000 なので
     * **その根拠はもう成立しない**。切り詰めが絞り込みを緩める方向にしか倒れないことは事実だが、
     * それを理由に「無害」とは書かない。
     */
    public const int MAX_LENGTH = 200;

    /**
     * 検索対象にする `cuts` の本文列。**この配列がカット本文の定義の正本**である。
     *
     * @var list<string>
     */
    private const array BODY_COLUMNS = [
        'scene',
        'narration',
        'subtitle_primary',
        'subtitle_secondary',
    ];

    /**
     * 生の検索語を正規化する。前後の空白を除き、空なら null、長ければ先頭 MAX_LENGTH **文字**。
     *
     * `mb_substr` を使うのは日本語を**文字数**で切るためである (`substr` はバイト数で切り、
     * UTF-8 の途中で割ると壊れた文字が LIKE に渡る)。
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, self::MAX_LENGTH);
    }

    /**
     * キーワード条件を**1 つの入れ子 group として**積む。
     *
     * **入れ子 group は必須である**。`orWhereHas` を素で積むと OR が外へ漏れ、
     * 呼び出し側が積んだ母集団条件 (`project_id` の relation 制約 / `status` の
     * ready・published 制限 / `created_by` の自作フィルタ) を**すべて無効化する**。
     * これは cross-project の manual が一覧に混ざる = テナント境界の破壊であり、
     * 本機能で最も危険な失敗様式である (`ManualKeywordSearchBoundaryTest` が固定)。
     *
     * `cuts` への条件は `orWhereHas` = 相関 EXISTS 副問い合わせであり、
     * **同一 SQL 内で完結する** (行ごとの追加クエリ = N+1 を生まない)。
     * join にしないのは、1 manual の複数カットが一致したときに行が重複し
     * paginate の総件数が壊れるためである。
     *
     * 実行計画は相関 nested-loop と hash semi-join の**どちらもありうる**。
     * PostgreSQL は WHERE 句の記述順で駆動表や索引を選ばないので、
     * 条件の並び順で計画を誘導しようとしない (施策 5 の索引が nested-loop 側を支える)。
     *
     * 受け型は**契約 interface** (`Illuminate\Contracts\Database\Eloquent\Builder`) である。
     * `Eloquent\Builder` と `Relations\Relation` の**両方**がこれを implements しているため、
     * PC 側の `$project->manuals()->with([...])` (= `HasMany`) も
     * 撮影 PWA 側の `when()` クロージャ引数 (= `Eloquent\Builder`) もそのまま渡せる。
     * **この interface は generic ではない**ので型引数 (`<VideoManual>`) は書けない
     * (書くと PHPStan level 10 が `generics.notGeneric` で落ちる)。
     * 帰結として「渡されたクエリが VideoManual を返すこと」は型では固定されず、
     * `cuts` relation の実在は実行時 (テスト) が担う — 誇張しない。
     *
     * @param  Builder  $query  VideoManual を返すクエリ (Relation でも可)
     */
    public static function apply(Builder $query, string $keyword): void
    {
        // LIKE メタ文字 (%/_/\) はリテラル検索として扱う (現行 title 検索と同じ規則)
        $like = '%'.addcslashes($keyword, '%_\\').'%';

        $query->where(function (Builder $scoped) use ($like): void {
            $scoped
                ->where('title', 'like', $like)
                ->orWhereHas('cuts', function (Builder $cuts) use ($like): void {
                    $cuts->where(function (Builder $body) use ($like): void {
                        // 入れ子 group の先頭の boolean は grammar が落とすため全件 orWhere でよい
                        foreach (self::BODY_COLUMNS as $column) {
                            $body->orWhere($column, 'like', $like);
                        }
                    });
                });
        });
    }
}

<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\VideoManual;
use App\Services\Manual\AdoptedReadyTakeCoverage;
use Webmozart\Assert\Assert;

/**
 * 撮影一覧 (Capture/Index) の 1 行分。TS 側 types/capture.ts の CaptureManualSummary と対で保守。
 * 進捗カウント (cuts_total / cuts_adopted / cuts_with_takes) は withCount 済みモデルから読む。
 * creator は表示目的のみ (検索対象外)。User.name は CipherSweet PII のため whereBlind 検索の
 * 対象にはしない (自作フィルタは created_by の id 一致で行う)。
 *
 * **制作状態 (video_manuals.status) は載せない** (T197)。撮影 PWA が出す進捗バッジは
 * カットの採用状況から導出する別の量 (types/capture.ts の captureProgressOf) であり、
 * 制作状態は表示にも分岐にも使われていなかったため。撮影対象の母集団を
 * ready / published に絞るのは CaptureManualController の責務で、こちらは変えていない。
 */
final readonly class CaptureManualSummaryData
{
    public function __construct(
        public int $id,
        public string $title,
        public ?int $categoryId,
        public ?string $categoryName,
        public int $cutsTotal,
        public int $cutsAdopted,
        public int $cutsWithTakes,
        public ?string $updatedAt,
        public ?string $creatorName,
        /**
         * 代表サムネイル 1 枚の座標。無い場合は null で、UI はプレースホルダを描く。
         * **UI はこの 1 つの値だけで判断する** (権限も状態もここで解決済み = 判断を 2 箇所に持たない)。
         */
        public ?CaptureManualCoverData $cover,
    ) {}

    /**
     * withCount('cuts', 'cuts as cuts_adopted_count', 'cuts as cuts_with_takes_count') +
     * with('category', 'creator') 済みの manual から生成する (Capture/IndexController の一覧クエリと対)。
     *
     * @param  bool  $canViewCover  代表サムネイルを見せてよいか
     *                              (`ProjectPolicy::capture` を **project 単位に 1 回**評価した結果。行ごとに評価しない)。
     *                              false のときは `coverCut` relation に**触れない** — 触ると relation 未ロード時に
     *                              行ごとの lazy load が走り N+1 になる (権限の無い利用者には eager load を張らないため)。
     */
    public static function fromManual(VideoManual $manual, bool $canViewCover): self
    {
        $cutsTotal = $manual->getAttribute('cuts_count');
        $cutsAdopted = $manual->getAttribute('cuts_adopted_count');
        $cutsWithTakes = $manual->getAttribute('cuts_with_takes_count');
        Assert::integer($cutsTotal, 'withCount(cuts) 済みの manual を渡してください');
        Assert::integer($cutsAdopted, 'withCount(cuts as cuts_adopted_count) 済みの manual を渡してください');
        Assert::integer($cutsWithTakes, 'withCount(cuts as cuts_with_takes_count) 済みの manual を渡してください');

        return new self(
            id: $manual->id,
            title: $manual->title,
            categoryId: $manual->category?->id,
            categoryName: $manual->category?->name,
            cutsTotal: $cutsTotal,
            cutsAdopted: $cutsAdopted,
            cutsWithTakes: $cutsWithTakes,
            updatedAt: $manual->updated_at?->toIso8601String(),
            creatorName: $manual->creator?->name, // 退会/削除で null (実運用では FK RESTRICT)
            cover: self::resolveCover($manual, $canViewCover),
        );
    }

    /**
     * 代表サムネイルの座標を決める (概念設計 D1-1 の層 (c) = 合成のみ)。
     *
     * 層の分担:
     *   (a) 候補選択 … `VideoManual::coverCut()` (表示順 + サムネイル生成済み)
     *   (b) 状態判定 … `AdoptedReadyTakeCoverage::readyTakeId()` へ**委譲** (自前の述語を持たない)
     *   (c) 合成    … 本メソッド
     *
     * (b) は eager load 済み relation を読むだけで **DB へ問い合わせない**
     * (`with(['coverCut.adoptedTake'])` を張るのが呼び出し側の義務)。
     *
     * (a) が選んだカットで (b) が null を返したときは**次のカットを探さずに null を返す**。
     * 候補条件 (サムネイル生成済み) と表示条件 (採用済みかつ ready) は現行コードでは一致する
     * (`thumbnail_path` は `where status=ready` の条件付き UPDATE でしか非 null にならず、
     * ready から離れる遷移が存在しない) が、一致を前提にせず安全側 = 壊れた画像を出さない側へ倒す。
     */
    private static function resolveCover(VideoManual $manual, bool $canViewCover): ?CaptureManualCoverData
    {
        if (! $canViewCover) {
            return null; // relation に触れない (未ロードのため触ると lazy load = N+1)
        }

        $cut = $manual->coverCut;
        if ($cut === null) {
            return null; // 採用テイク付き + サムネイル生成済みのカットが 1 つも無い
        }

        $takeId = AdoptedReadyTakeCoverage::readyTakeId($cut);
        if ($takeId === null) {
            return null; // 候補条件と表示条件の食い違い → 出さない
        }

        return new CaptureManualCoverData(cutId: $cut->id, takeId: $takeId);
    }

    /**
     * @return array{id: int, title: string, category_id: int|null,
     *   category_name: string|null, cuts_total: int, cuts_adopted: int, cuts_with_takes: int,
     *   updated_at: string|null, creator_name: string|null,
     *   cover: array{cut_id: int, take_id: int}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'cuts_total' => $this->cutsTotal,
            'cuts_adopted' => $this->cutsAdopted,
            'cuts_with_takes' => $this->cutsWithTakes,
            'updated_at' => $this->updatedAt,
            'creator_name' => $this->creatorName,
            'cover' => $this->cover?->toArray(),
        ];
    }
}

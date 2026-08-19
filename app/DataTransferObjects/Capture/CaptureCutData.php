<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\Cut;
use App\Models\Take;
use App\Services\Manual\AdoptedReadyTakeCoverage;
use Webmozart\Assert\Assert;

/**
 * 撮影 PWA へ返すカットの shape (takes 込み)。TS 側 types/capture.ts の CaptureCut と対で保守。
 * adopted_take_id の参照は読み取り直列化のみ (書き込み経路は CaptureTakeService に限定。
 * ScenarioWritePathInventoryTest 検出 4 が deny-by-default で固定する)。
 *
 * **「使用できる採用テイクか」の判定は自前で持たない** — DTO 側が唯一の述語
 * (`AdoptedReadyTakeCoverage::readyTakeId()`) を呼ぶ。呼び出し側が計算して渡す形にすると
 * fromCut() の呼び出し口 (詳細画面 / adopt 応答) ごとに渡し忘れうる形になり、
 * T148 が閉じた「呼び出し側が判定を組み立てる」構造へ戻るためである
 * (先例: TakeSelectionPageData → CutSequencer / ManualListItemData → ManualRowAbilities)。
 */
final readonly class CaptureCutData
{
    /**
     * @param  list<CaptureTakeData>  $takes
     * @param  int|null  $adoptedReadyTakeId  使用できる採用テイクの id
     *                                        (`AdoptedReadyTakeCoverage::readyTakeId()` の戻り値そのもの。判定は持たない)
     */
    public function __construct(
        public Cut $cut,
        public array $takes,
        public ?int $adoptedReadyTakeId,
    ) {}

    /**
     * takes は sort_order → id 順に並べ替えて保持する。採用テイクには playback URL / DL ACK
     * トークンを付与できる (詳細 GET のみ。null なら全テイク null = store/adopt 応答)。
     *
     * **`takes` は必ず eager load 済みであること**。呼び出し側から `Collection` を受け取る形は、
     * (a) 未ロードでも Eloquent の lazy load で黙って動いてしまい eager load 忘れを検出できない、
     * (b) `$cut` に属さない Take の `Collection` を渡せてしまい親子整合性を型で保証できない、
     * の 2 点で fail-open だった。ここでは `$cut->takes` relation を DTO 自身が読み、
     * 未ロードなら **`$cut->takes` へ触れる前に** 例外にする
     * (`Services/Manual/CurrentRenderArtifact::fromLoadedRenderCandidate()` と同じ
     * 「未ロードでの呼び出しは例外にする」作法)。
     * **`relationLoaded()` が保証するのは「relation cache が存在すること」だけであり、
     * それが完全な eager load 結果であることまでは判定できない**。現在の呼び出し元は
     * `with(['adoptedTake', 'takes'])` / `load('takes')` で必ず全件取得しているためこの前提で
     * 成立するが、「一部だけロードされた relation」を渡す呼び出し元が将来増えたら本チェックの外になる。
     *
     * **`relationLoaded()` だけでは親子整合性を保証しない**。
     * `$cut->setRelation('takes', $arbitraryCollection)` は `relationLoaded()` を true にしたまま
     * 任意の Collection を仕込めるため、「relation 経由なら `WHERE cut_id = ?` が
     * 親子整合性を構造的に保証する」という前提は `HasMany` クエリ経由の場合にしか成立しない。
     * よって **ロードされている全 Take について `take->cut_id === $cut->id` を明示的に検査**する
     * (DB への再問い合わせではなくメモリ上の値検査であり、N+1 は生まない)。
     * 別カット・別テナントの Take が `setRelation()` 経由で紛れ込んだ場合は、
     * ここで即座に例外にする。
     */
    public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?string $adoptedAckToken = null): self
    {
        Assert::true(
            $cut->relationLoaded('takes'),
            'takes を eager load してから呼ぶこと (呼び出し側で with([\'adoptedTake\', \'takes\']) '
            .'または load(\'takes\') を行う)',
        );

        // relation を 1 度だけローカル変数へ受け、親子整合性検査と並べ替えの両方で使い回す
        // ($cut->takes を 2 回読む必要をなくす)
        $takes = $cut->takes;
        foreach ($takes as $take) {
            Assert::same(
                $take->cut_id,
                $cut->id,
                'takes relation には対象 cut に属する Take だけを渡してください'
                .' (別カット・別テナントの Take が混入しています)',
            );
        }

        $adoptedTakeId = $cut->adopted_take_id;
        $sorted = $takes
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values()
            ->map(static function (Take $take) use ($adoptedTakeId, $adoptedPlaybackUrl, $adoptedAckToken): CaptureTakeData {
                $isAdopted = $adoptedTakeId !== null && $take->id === $adoptedTakeId;

                return CaptureTakeData::fromTake(
                    $take,
                    playbackUrl: $isAdopted ? $adoptedPlaybackUrl : null,
                    downloadAckToken: $isAdopted ? $adoptedAckToken : null,
                );
            })
            ->all();

        // 「使用できる採用テイクか」の判定は AdoptedReadyTakeCoverage が唯一の所在である
        // (ここで adopted_take_id と TakeStatus::Ready を組み直さない = T148)。
        return new self($cut, array_values($sorted), AdoptedReadyTakeCoverage::readyTakeId($cut));
    }

    /**
     * @return array{id: int, type: string, parent_cut_id: int|null, scene: string,
     *   shot_type: string, shooting_point: string|null, narration: string,
     *   subtitle_primary: string|null, subtitle_secondary: string, material_type: string|null,
     *   adopted_take_id: int|null, adopted_ready_take_id: int|null,
     *   takes: list<array{id: int, client_take_id: string, status: string, material_type: string, size_bytes: int,
     *     duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
     *     downloaded: bool, has_thumbnail: bool, playback_url: string|null,
     *     download_ack_token: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->cut->id,
            'type' => $this->cut->type->value,
            'parent_cut_id' => $this->cut->parent_cut_id,
            'scene' => $this->cut->scene,
            'shot_type' => $this->cut->shot_type->value,
            'shooting_point' => $this->cut->shooting_point,
            'narration' => $this->cut->narration,
            'subtitle_primary' => $this->cut->subtitle_primary,
            'subtitle_secondary' => $this->cut->subtitle_secondary,
            // カットの**計画** (未指定あり)。撮影 UI の出し分け (シャッター / 録画) に使う
            'material_type' => $this->cut->material_type?->value,
            'adopted_take_id' => $this->cut->adopted_take_id,
            // 通し再生が再生する対象。null = そのカットはプレースホルダになる
            // (「採用されていない」と「採用済みだが ready でない」を区別しない = 述語の意味そのまま)
            'adopted_ready_take_id' => $this->adoptedReadyTakeId,
            'takes' => array_map(
                static fn (CaptureTakeData $take): array => $take->toArray(),
                $this->takes,
            ),
        ];
    }
}

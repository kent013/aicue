<?php

declare(strict_types=1);

use App\DataTransferObjects\Capture\CaptureCutData;
use App\Models\Cut;
use App\Models\Take;
use Illuminate\Database\Eloquent\Collection;
use Webmozart\Assert\InvalidArgumentException;

/*
 * CaptureCutData::fromCut() の takes 取得契約 (design-review Round 2/3 対応)。
 *
 * - takes relation は呼び出し側が eager load してから渡す。未ロードなら例外にする
 *   (`relationLoaded('takes')` を `$cut->takes` へ触れる前に確認する fail-closed 作法)。
 * - ロードされている全 take の cut_id が対象 cut の id と一致することも検査する
 *   (`setRelation()` 経由の別カット・別テナント混入を防ぐ)。
 * - 表示順は sort_order → id で fromCut() 自身が保証する。
 */

test('takes を eager load していない cut を渡すと例外になる', function (): void {
    $cut = Cut::factory()->create();

    CaptureCutData::fromCut($cut);
})->throws(InvalidArgumentException::class);

test('takes の表示順は sort_order → id で維持される (投入順が逆でも)', function (): void {
    $cut = Cut::factory()->create();
    $first = Take::factory()->forCut($cut)->create(['sort_order' => 0]);
    $second = Take::factory()->forCut($cut)->create(['sort_order' => 1]);

    // わざと投入順を逆にして setRelation する
    $cut->setRelation('takes', new Collection([$second, $first]));

    $data = CaptureCutData::fromCut($cut);

    expect($data->takes)->toHaveCount(2);
    expect($data->takes[0]->take->id)->toBe($first->id);
    expect($data->takes[1]->take->id)->toBe($second->id);
});

test('別 cut の take が setRelation() で紛れ込んでいたら例外になる', function (): void {
    $cut = Cut::factory()->create();
    $ownTake = Take::factory()->forCut($cut)->create();
    $otherCut = Cut::factory()->create();
    $foreignTake = Take::factory()->forCut($otherCut)->create();

    // relationLoaded() は true になるが、cut_id が一致しない take が混入している
    $cut->setRelation('takes', new Collection([$ownTake, $foreignTake]));

    CaptureCutData::fromCut($cut);
})->throws(InvalidArgumentException::class);

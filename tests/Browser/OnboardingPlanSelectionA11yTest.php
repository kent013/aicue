<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| オンボーディング: プラン選択状態のアクセシビリティ (bug-hunt F-2-01)
|--------------------------------------------------------------------------
|
| /onboarding/checkout?plan=starter で該当カードが青枠 (border-primary) で強調されるが、
| その状態がアクセシビリティツリーに一切現れていなかった。契約という不可逆操作の前段で
| 「どのプランが選ばれているか」が支援技術利用者に伝わらないのは実害がある。
|
| role は偽らない: 排他選択なので aria-pressed (トグル) は誤りで、radiogroup 化は
| 契約画面のキーボード操作モデルを作り替える規模になる。青枠が伝えている一事を
| sr-only テキストで同じだけ伝える (Billing が「現在のプラン」Badge = テキストで
| 同種の状態を伝えているのと同じ手口)。
|
| 注意: 既定の createOrganizationWithOwner() は free_plan_code を立てるため
| BillingAccess が ActiveFreePlan と判定し、Checkout は /billing へリダイレクトされる。
| grandfatherFreePlan: false を明示しないとこの画面に到達できない。
|
*/

/**
 * 相対座標の前後比較 (許容差 1px)。
 *
 * getBoundingClientRect はフォント描画・Chromium/WebKit 差・小数丸めで揺れうるため、
 * 完全一致で比較すると flaky になる。「レイアウトが動いていない」ことを見たいので
 * 1px の許容差を持つ。
 *
 * @param  array<string, float>|null  $before
 * @param  array<string, float>|null  $after
 * @param  list<string>  $keys
 */
function expectRectUnchanged(?array $before, ?array $after, array $keys, string $label): void
{
    expect($before)->not->toBeNull("{$label}: 変更前の矩形が取得できていない");
    expect($after)->not->toBeNull("{$label}: 変更後の矩形が取得できていない");

    foreach ($keys as $key) {
        expect(abs($after[$key] - $before[$key]))
            ->toBeLessThanOrEqual(1.0, "{$label}.{$key} が 1px を超えて動いた");
    }
}

/**
 * sr-only の不可視契約を 1 要素について確認する。
 *
 * 矩形サイズだけでは「たまたま小さい」でも通ってしまうため、Tailwind の sr-only が
 * 実際に効いていること (absolute + overflow:hidden + clip/clip-path) まで見る。
 * プラン・状態ごとに class が分岐したときにすり抜けないよう、note が出うる各時点で呼ぶ。
 */
function expectNoteVisuallyHidden(mixed $page, string $planCode): void
{
    expect($page->script(<<<JS
        (() => {
            const el = document.querySelector('[data-testid="plan-selected-note-{$planCode}"]');
            if (el === null) return null;
            const r = el.getBoundingClientRect();
            const cs = getComputedStyle(el);
            const clipped =
                (cs.clip !== 'auto' && cs.clip !== '') ||
                (cs.clipPath !== 'none' && cs.clipPath !== '');
            return {
                tiny: r.width <= 1 && r.height <= 1,
                absolute: cs.position === 'absolute',
                hidden: cs.overflow === 'hidden',
                clipped,
            };
        })()
    JS))->toMatchArray([
        'tiny' => true,
        'absolute' => true,
        'hidden' => true,
        'clipped' => true,
    ]);
}

/** 未契約オーナーでログインし、Checkout に到達できる状態を作る */
function checkoutFixture(): void
{
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    test()->actingAs($owner);
}

test('?plan= の事前選択が sr-only テキストでアクセシビリティツリーに現れる', function (): void {
    checkoutFixture();

    // ?plan= は org-scoped に session へ積まれ canonical URL へ 303 されるため、
    // 着地は query 無しの /onboarding/checkout になる
    $page = visit('/onboarding/checkout?plan=starter')
        ->assertPathIs('/onboarding/checkout');

    expect($page->script('window.location.search'))->toBe('');

    // 受入条件 9: starter だけが「プラン名 + 初期候補」の note を持つ
    $note = $page->text('[data-testid="plan-selected-note-starter"]');
    expect($note)->toContain('Starter');
    expect($note)->toContain('初期候補');
    // まだ押していないので「選択中」とは言わない (CTA が「選択」のままなのと意味を揃える)
    expect($note)->not->toContain('選択中');

    expect($page->script(
        'document.querySelectorAll(\'[data-testid^="plan-selected-note-"]\').length'
    ))->toBe(1);
});

test('別プランを選び直すと note が移動し文言が選択中へ切り替わる', function (): void {
    checkoutFixture();

    $page = visit('/onboarding/checkout?plan=starter')
        ->assertPathIs('/onboarding/checkout');

    $page->click('[data-testid="select-plan-standard"]');

    // 受入条件 10: 旧 note が消え、新プラン名を含む note が現れる
    for ($i = 0; $i < 40; $i++) {
        $moved = $page->script(
            'document.querySelector(\'[data-testid="plan-selected-note-standard"]\') !== null'
        );

        if ($moved === true) {
            break;
        }

        usleep(100_000);
    }

    expect($page->script(
        'document.querySelector(\'[data-testid="plan-selected-note-starter"]\') === null'
    ))->toBeTrue();

    $note = $page->text('[data-testid="plan-selected-note-standard"]');
    expect($note)->toContain('Standard');
    // 押下後は CTA が「選択中」になるので note も同じ基準で切り替わる
    expect($note)->toContain('選択中');
});

test('sr-only note の追加でカードのレイアウトが動かない', function (): void {
    checkoutFixture();

    $page = visit('/onboarding/checkout?plan=starter')
        ->assertPathIs('/onboarding/checkout');

    // カード上端からの相対 top と height を測る (異なるカード同士は比較しない)。
    // 欠落を黙って握り潰さないよう、カードが無ければ null を明示的に返す。
    $measure = <<<'JS'
        (() => {
            const out = {};
            for (const code of ['starter', 'standard']) {
                const card = document.querySelector('[data-testid="plan-card-' + code + '"]');
                if (card === null) { out[code] = null; continue; }
                const base = card.getBoundingClientRect().top;
                const pick = (sel) => {
                    const el = card.querySelector(sel);
                    if (el === null) return null;
                    const r = el.getBoundingClientRect();
                    return {
                        top: Math.round((r.top - base) * 100) / 100,
                        height: Math.round(r.height * 100) / 100,
                    };
                };
                out[code] = {
                    heading: pick('h3'),
                    price: pick('[data-testid="plan-price"]'),
                    cta: pick('[data-testid="select-plan-' + code + '"]'),
                };
            }
            return out;
        })()
    JS;

    // script() は locator と違って自動待機しないため、hydration 完了までは
    // カードが DOM に無い。計測前に明示的に待つ。
    for ($i = 0; $i < 40; $i++) {
        $ready = $page->script(
            'document.querySelector(\'[data-testid="plan-card-starter"]\') !== null'
        );

        if ($ready === true) {
            break;
        }

        usleep(100_000);
    }

    $before = $page->script($measure);
    // 計測対象が取れていることを先に固定する (取れないまま比較して緑になるのを防ぐ)
    expect($before['starter'])->not->toBeNull();
    expect($before['standard'])->not->toBeNull();

    // 初期表示 (starter に note がある時点) でも不可視契約を固定する。
    // 状態やプランで class が分岐したときにすり抜けないよう、note が出る各時点で見る。
    expectNoteVisuallyHidden($page, 'starter');

    $page->click('[data-testid="select-plan-standard"]');

    for ($i = 0; $i < 40; $i++) {
        $moved = $page->script(
            'document.querySelector(\'[data-testid="plan-selected-note-standard"]\') !== null'
        );

        if ($moved === true) {
            break;
        }

        usleep(100_000);
    }

    $after = $page->script($measure);

    // Starter: note 有 → 無、CTA 文言は不変 = 交絡なしの最も強い検査。
    // 価格行も測る (headerBadges 追加で見出し行が伸びれば価格が押し下がるため)。
    expectRectUnchanged($before['starter']['heading'], $after['starter']['heading'], ['top', 'height'], 'starter.heading');
    expectRectUnchanged($before['starter']['price'], $after['starter']['price'], ['top', 'height'], 'starter.price');
    expectRectUnchanged($before['starter']['cta'], $after['starter']['cta'], ['top', 'height'], 'starter.cta');

    // Standard: note 無 → 有 だが CTA 文言が「選択」→「選択中」に変わるため、
    // CTA の height は不変条件にしない (headerBadges 由来か文言差か判別できないため)。
    expectRectUnchanged($before['standard']['heading'], $after['standard']['heading'], ['top', 'height'], 'standard.heading');
    expectRectUnchanged($before['standard']['price'], $after['standard']['price'], ['top', 'height'], 'standard.price');
    expectRectUnchanged($before['standard']['cta'], $after['standard']['cta'], ['top'], 'standard.cta');

    // 選択後 (standard に note が移った時点) でも不可視契約を固定する
    expectNoteVisuallyHidden($page, 'standard');
});

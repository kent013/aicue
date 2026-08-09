Round 1 の Warning 4 件 + Suggestion 1 件をすべて対応した (反論なし)。

対応後の検証:
- `composer test:browser` chromium / webkit とも 22 tests / 19 passed / 3 skipped / **141 assertions**
  (対応前は 94 assertions。許容差比較・価格計測・sr-only 契約の追加ぶん増えている)
- `vendor/bin/pint --test`: passed
- `PricingPlanCard.test.ts` / `OnboardingCheckout.test.ts`: 27 passed (testid 追加の影響なし)

自己申告の逸脱: 価格行を測るために `PricingPlanCard.svelte` に `data-testid="plan-price"` を
1 つ足した (施策 C は「Checkout のみ変更」の予定だった)。表示・スタイルには影響しない計測点で、
既存の `data-testid="price-caption"` と同じ性質。この逸脱が許容範囲か判定してほしい。

# 対応マトリクス

# 対応マトリクス: impl-review Round 1

Critical はゼロ。Warning 4 件 + Suggestion 1 件、すべて対応した（反論なし）。

## [Warning] Checkout の文言が「プラン名に『プラン』が含まれる場合」の重複を避けていない
- 判断: **対応する**
- 根拠: 指摘のとおり。実データ (`PlanSeeder`) の現行値は `Personal` / `Starter` / `Standard` で
  今は重複しないが、**文言が実データ名に依存している**状態は将来壊れる。
  設計で「実値を確認して調整する」と書いた条件を、そもそも成立しない形にするほうが強い。
- 対応内容: 文言から「プラン」の語を落とし、
  `{plan.name} が初期候補として表示されています` / `{plan.name} を選択中です` にした。
  なぜ「プラン」を付けないかの理由もコメントに残した。
  Browser テストの assert (「Starter」「初期候補」「選択中」の包含) はそのまま通る。

## [Warning] 受入条件 11 の「許容差 1px 以内」が実装されず完全一致になっていた
- 判断: **対応する**
- 根拠: 指摘のとおり。`toEqual()` の完全一致は、フォント描画・Chromium/WebKit 差・
  小数丸めで揺れて flaky になる。設計は 1px 許容と書いていたのに実装が守れていなかった
  （設計と実装の乖離。指摘されなければそのまま入っていた）。
- 対応内容: `expectRectUnchanged(?array $before, ?array $after, array $keys, string $label)`
  ヘルパを追加し、`abs($after[$key] - $before[$key]) <= 1.0` で比較する形にした。
  失敗時にどの要素のどのキーが動いたかが分かるよう `$label` 付きメッセージも入れた。

## [Warning] 受入条件 11 で「価格」の相対位置・高さを測っていない
- 判断: **対応する**
- 根拠: 指摘のとおり。`h3` と CTA だけでは、`headerBadges` 追加で**見出し行が伸びて
  価格行が押し下げられる**退行を検出できない。価格はまさに見出しの直下にあり、
  この施策で最も動きやすい要素だった。
- 対応内容: `PricingPlanCard.svelte` の価格 `<p>` に `data-testid="plan-price"` を追加し、
  測定対象に `price` を加えた（Starter / Standard とも `top` + `height` を検査）。
  - **設計からの逸脱**: 施策 C は「`Onboarding/Checkout.svelte` のみ変更」としていたが、
    molecule に計測用 testid を 1 つ足した。表示・スタイルには一切影響しない計測点であり、
    既存の `data-testid="price-caption"` と同じ性質。逸脱として本マトリクスに記録する。

## [Warning] `sr-only` の不可視確認が矩形サイズだけ
- 判断: **対応する**
- 根拠: 指摘のとおり。`width <= 1 && height <= 1` は「たまたま小さい」でも通る。
  設計は「1px 四方以下、**または** clip / clip-path」と書いており、
  Tailwind の `sr-only` 契約そのものを見る意図だった。
- 対応内容: `getComputedStyle` を併用し、
  `tiny` / `absolute` (`position: absolute`) / `hidden` (`overflow: hidden`) /
  `clipped` (`clip` または `clip-path` が設定されている) の 4 点を `toMatchArray` で固定した。

## [Warning] `waitUntilHeadingInViewport()` の失敗理由が分かりにくい
- 判断: **対応する**
- 根拠: 指摘のとおり。void を返して呼び出し側の assert で落とす構造だと、
  「待機 timeout」と「最終座標が条件を満たさない」が同じ失敗に見える。
  Browser lane は調査コストが高いので、失敗の切り分けが付くべき。
- 対応内容: `waitUntilInViewport(mixed $page, string $testId, int $attempts = 40): bool` に
  作り替え（testid を引数化して一覧見出し側の重複ループも統合）、
  呼び出し側は `expect(waitUntilInViewport(...))->toBeTrue('… (待機 timeout)')` と
  **明示メッセージ付きで落とす**形にした。重複していた polling ループ 1 つも削除できた。

## [Suggestion] desktop test の `usleep(500_000)` 単発は意図が弱い
- 判断: **対応する**
- 根拠: 単発 sleep だと「動いていない」のか「まだ動いていないだけ」なのか区別できない。
  区間で観測するほうが「動かない」という主張に一致する。
- 対応内容: 50ms × 10 回の polling に変え、**各回で `scrollY` が初期値のまま**であることを
  assert する形にした（区間を通して一度も動かないことの観測）。

## 検証（対応後）
- `composer test:browser` chromium: 22 tests / 19 passed / 3 skipped / **141 assertions**
- `composer test:browser` webkit: 22 tests / 19 passed / 3 skipped / **141 assertions**
  （対応前は 94 assertions。許容差比較・価格・sr-only 契約の追加で増えている）
- `vendor/bin/pint --test`: passed
- `PricingPlanCard.test.ts` / `OnboardingCheckout.test.ts`: 27 passed（testid 追加の影響なし）


---

# 修正差分 (今回の対応分のみ)

```diff
diff --git a/resources/js/components/molecules/PricingPlanCard.svelte b/resources/js/components/molecules/PricingPlanCard.svelte
index ca88815..7c82092 100644
--- a/resources/js/components/molecules/PricingPlanCard.svelte
+++ b/resources/js/components/molecules/PricingPlanCard.svelte
@@ -62,7 +62,12 @@
             {priceCaption}
         </p>
     {/if}
-    <p class="{priceCaption !== undefined && !isFree ? 'mt-0.5' : 'mt-3'} text-h2 text-text">
+    <!-- data-testid は headerBadges 追加によるレイアウト退行を測るための計測点 (T141)。
+         表示・スタイルには一切影響しない。 -->
+    <p
+        class="{priceCaption !== undefined && !isFree ? 'mt-0.5' : 'mt-3'} text-h2 text-text"
+        data-testid="plan-price"
+    >
         {#if isFree}
             <!-- 無料プラン: ¥0 表記でなく「無料」を価格として掲示する -->
             無料
diff --git a/resources/js/pages/Onboarding/Checkout.svelte b/resources/js/pages/Onboarding/Checkout.svelte
index 19878af..06dfa12 100644
--- a/resources/js/pages/Onboarding/Checkout.svelte
+++ b/resources/js/pages/Onboarding/Checkout.svelte
@@ -197,6 +197,30 @@
                             isHighlighted={selectedPlanCode === plan.code}
                             testId={`plan-card-${plan.code}`}
                         >
+                            {#snippet headerBadges()}
+                                {#if selectedPlanCode === plan.code}
+                                    <!-- 青枠 (isHighlighted) が視覚で伝えている状態を、支援技術にも
+                                         同じだけ伝える (F-2-01)。role は偽らない: 排他選択なので
+                                         aria-pressed は誤りで、radiogroup 化はキーボード操作モデルの
+                                         作り替えになる。文言にプラン名を含めるのは、カードが semantic
+                                         group ではなくテキスト単位の移動で対象が読み上げ順に依存する
+                                         のを避けるため。文言は CTA と同じ基準 (chosenPlanCode) で
+                                         切り替え、未押下を「選択済み」と誤認させない。
+                                         「プラン」の語は付けない: plan.name の実値 (Personal /
+                                         Starter / Standard) に将来「プラン」が含まれても
+                                         「プラン プラン」と重複しないようにするため。 -->
+                                    <span
+                                        class="sr-only"
+                                        data-testid={`plan-selected-note-${plan.code}`}
+                                    >
+                                        {#if chosenPlanCode === plan.code}
+                                            {plan.name} を選択中です
+                                        {:else}
+                                            {plan.name} が初期候補として表示されています
+                                        {/if}
+                                    </span>
+                                {/if}
+                            {/snippet}
                             {#snippet footerCta()}
                                 <div class="flex flex-col gap-2">
                                     {#if showRecommendedBadge(plan.code)}
diff --git a/tests/Browser/CaptureCutNavigationTest.php b/tests/Browser/CaptureCutNavigationTest.php
new file mode 100644
index 0000000..4064905
--- /dev/null
+++ b/tests/Browser/CaptureCutNavigationTest.php
@@ -0,0 +1,244 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\Take;
+use App\Models\VideoManual;
+
+/*
+|--------------------------------------------------------------------------
+| 撮影ナビ: カット選択時の視点/フォーカス移送 (bug-hunt F-1-03)
+|--------------------------------------------------------------------------
+|
+| 1 カラム (モバイル) ではシナリオ一覧の下に撮影パネルが縦積みされるため、カットを
+| タップしても撮影パネルが viewport に入らず、ユーザーが毎回手動スクロールしていた。
+| 「視点」だけ運んでフォーカスを一覧側に残すと a11y 欠落を作るので、両方運ぶことを固定する。
+|
+| 受入条件 4 (captureActive 中は動かない) は CI に実カメラが無いため
+| tests/js/lib/capture/panel-navigation.test.ts (抑止契約) と
+| tests/js/pages/CaptureShow.test.ts (ページ配線) の 2 段で固定している。
+|
+*/
+
+/**
+ * 撮影ナビの前提を一式作る。
+ *
+ * 撮影 PWA は require-active-subscription group 内 (AGENTS.md ドメイン規約 4) なので
+ * contractPaidPlan を通さないと /billing-required に着地する。
+ *
+ * cuts の件数は「viewport 外にするための手段」であって条件ではない。
+ * 実際に viewport 外であることは各テストがクリック前に assert する。
+ *
+ * @return array{0: Project, 1: VideoManual}
+ */
+function captureNavigationFixture(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization);
+
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()
+        ->forProject($project)
+        ->create(['created_by' => $owner->id, 'status' => VideoManualStatus::Ready->value]);
+
+    foreach (range(1, 20) as $index) {
+        Cut::factory()->forManual($manual)->create(['sort_order' => $index]);
+    }
+
+    test()->actingAs($owner);
+
+    return [$project, $manual];
+}
+
+/** capture.manuals.show の URL */
+function captureShowUrl(Project $project, VideoManual $manual): string
+{
+    return "/app/projects/{$project->id}/manuals/{$manual->id}";
+}
+
+/**
+ * 指定 testid の要素が「矩形全体として」viewport 内に入るまで上限付きで polling する。
+ *
+ * smooth scroll は非同期なので、クリック直後に測ると移動途中の座標を拾って flaky になる。
+ * scrollY の静止判定ではなく「目的の状態になったか」を直接見る (慣性で一瞬止まって見える
+ * ケースを踏まないため)。上限を超えたら false を返し、呼び出し側が
+ * 「待機 timeout」として明示的に落とす (無限待ちにしない / 失敗理由を曖昧にしない)。
+ */
+function waitUntilInViewport(mixed $page, string $testId, int $attempts = 40): bool
+{
+    for ($i = 0; $i < $attempts; $i++) {
+        $inside = $page->script(<<<JS
+            (() => {
+                const el = document.querySelector('[data-testid="{$testId}"]');
+                if (el === null) return false;
+                const r = el.getBoundingClientRect();
+                return r.top >= 0 && r.bottom <= window.innerHeight;
+            })()
+        JS);
+
+        if ($inside === true) {
+            return true;
+        }
+
+        usleep(100_000);
+    }
+
+    return false;
+}
+
+test('モバイル幅ではカット選択で撮影パネルが viewport に入りフォーカスも移る', function (): void {
+    [$project, $manual] = captureNavigationFixture();
+    $firstCutId = $manual->cuts()->orderBy('sort_order')->value('id');
+
+    // ★ on()->mobile() が返す On は __call のたびに新しいページを作るため、
+    //   ここで 1 度だけ materialize して以降は同じ Webpage を使い回す。
+    $page = visit(captureShowUrl($project, $manual))->on()->mobile()
+        ->assertPathIs(captureShowUrl($project, $manual));
+
+    // 前提: この時点で撮影パネルは viewport の外にある。
+    // これが成り立たないとテストは何も証明しない (修正前でも緑になってしまう)。
+    expect($page->script(<<<'JS'
+        (() => {
+            const el = document.querySelector('[data-testid="capture-right-pane"]');
+            return el.getBoundingClientRect().top >= window.innerHeight;
+        })()
+    JS))->toBeTrue();
+
+    $page->click("[data-testid=\"cut-row-{$firstCutId}\"]");
+
+    // 受入条件 1: 見出しが「矩形全体として」viewport 内 (1px 交差では不可)。
+    // 待機 timeout か座標不一致かが失敗メッセージで分かるようにする。
+    expect(waitUntilInViewport($page, 'capture-recording-heading'))
+        ->toBeTrue('撮影パネル見出しが viewport 内に入らなかった (待機 timeout)');
+
+    // 受入条件 2: フォーカスも撮影パネル先頭へ移る
+    expect($page->script(
+        'document.activeElement?.dataset?.testid ?? null'
+    ))->toBe('capture-recording-heading');
+});
+
+test('デスクトップ幅ではカット選択でスクロールも撮影パネルへのフォーカスも起きない', function (): void {
+    [$project, $manual] = captureNavigationFixture();
+    $firstCutId = $manual->cuts()->orderBy('sort_order')->value('id');
+
+    $page = visit(captureShowUrl($project, $manual))->on()->desktop()
+        ->assertPathIs(captureShowUrl($project, $manual));
+
+    $before = $page->script('window.scrollY');
+
+    $page->click("[data-testid=\"cut-row-{$firstCutId}\"]");
+
+    // 「動かない」ことの観測: 一定回数 polling して scrollY が一度も変わらないことを見る。
+    // 単発 sleep だと「まだ動いていないだけ」と区別できないため、区間で観測する。
+    for ($i = 0; $i < 10; $i++) {
+        usleep(50_000);
+        expect($page->script('window.scrollY'))
+            ->toBe($before, 'デスクトップ幅でスクロールが発生した');
+    }
+
+    // 「activeElement が変化しない」ではない: クリックした <button> にブラウザが
+    // フォーカスを移すのは通常挙動であり本実装の副作用ではない。
+    // 検証すべきは「撮影パネル見出しへプログラムフォーカスしない」こと。
+    expect($page->script(
+        'document.activeElement?.dataset?.testid ?? null'
+    ))->not->toBe('capture-recording-heading');
+});
+
+test('モバイル幅では撮影パネルからカット一覧へ視点とフォーカスの両方が戻る', function (): void {
+    [$project, $manual] = captureNavigationFixture();
+    $firstCutId = $manual->cuts()->orderBy('sort_order')->value('id');
+
+    $page = visit(captureShowUrl($project, $manual))->on()->mobile()
+        ->assertPathIs(captureShowUrl($project, $manual));
+    $page->click("[data-testid=\"cut-row-{$firstCutId}\"]");
+    expect(waitUntilInViewport($page, 'capture-recording-heading'))
+        ->toBeTrue('撮影パネル見出しが viewport 内に入らなかった (待機 timeout)');
+
+    $page->click('[data-testid="back-to-cut-list"]');
+
+    expect(waitUntilInViewport($page, 'capture-cut-list-heading'))
+        ->toBeTrue('カット一覧見出しが viewport 内に戻らなかった (待機 timeout)');
+
+    expect($page->script(
+        'document.activeElement?.dataset?.testid ?? null'
+    ))->toBe('capture-cut-list-heading');
+
+    // TextLink のボタンモード (href なし) なので URL に # が付かない
+    expect($page->script('window.location.hash'))->toBe('');
+});
+
+test('テイク再生の video のアクセシブルネームに手順ラベルが入る (F-1-02)', function (): void {
+    [$project, $manual] = captureNavigationFixture();
+    $firstCut = $manual->cuts()->orderBy('sort_order')->first();
+    $take = Take::factory()->forCut($firstCut)->create();
+
+    $page = visit(captureShowUrl($project, $manual))->on()->desktop()
+        ->assertPathIs(captureShowUrl($project, $manual));
+    $page->click("[data-testid=\"cut-row-{$firstCut->id}\"]");
+    $page->click("[data-testid=\"take-preview-{$take->id}\"]");
+
+    // ダイアログ内の video が描画されるまで待つ
+    for ($i = 0; $i < 40; $i++) {
+        $exists = $page->script(
+            'document.querySelector(\'[data-testid="take-preview-video"]\') !== null'
+        );
+
+        if ($exists === true) {
+            break;
+        }
+
+        usleep(100_000);
+    }
+
+    // 受入条件 8: 非空ではなく「どのカットのテイクか」が分かる意味内容を固定する
+    // (完全一致は i18n 変更に脆いので必要語の包含で見る)
+    expect($page->attribute('[data-testid="take-preview-video"]', 'aria-label'))
+        ->toContain('手順 1');
+});
+
+test('プレビュー動画の video にアクセシブルネームがある (F-1-02)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization);
+
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()
+        ->forProject($project)
+        ->create(['created_by' => $owner->id, 'status' => VideoManualStatus::Ready->value]);
+    Cut::factory()->forManual($manual)->create(['sort_order' => 1]);
+
+    // playbackJobId は「kind=Preview ∧ status=Succeeded ∧ output_path 非 null」でのみ引かれる
+    RenderJob::factory()
+        ->forManual($manual)
+        ->preview()
+        ->create([
+            'status' => JobStatus::Succeeded->value,
+            'output_path' => 'renders/preview-fixture.mp4',
+        ]);
+
+    $this->actingAs($owner);
+
+    $page = visit("/projects/{$project->id}/manuals/{$manual->id}")->on()->desktop()
+        ->assertPathIs("/projects/{$project->id}/manuals/{$manual->id}");
+
+    for ($i = 0; $i < 40; $i++) {
+        $exists = $page->script(
+            'document.querySelector(\'[data-testid="preview-video"]\') !== null'
+        );
+
+        if ($exists === true) {
+            break;
+        }
+
+        usleep(100_000);
+    }
+
+    // 受入条件 7: 固定文言「プレビュー動画」(playbackId は常に preview 由来なので
+    // 完成動画と取り違える経路が無く、状態分岐を持たない)
+    expect($page->attribute('[data-testid="preview-video"]', 'aria-label'))
+        ->toContain('プレビュー');
+});
diff --git a/tests/Browser/OnboardingPlanSelectionA11yTest.php b/tests/Browser/OnboardingPlanSelectionA11yTest.php
new file mode 100644
index 0000000..cca7b46
--- /dev/null
+++ b/tests/Browser/OnboardingPlanSelectionA11yTest.php
@@ -0,0 +1,212 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+|--------------------------------------------------------------------------
+| オンボーディング: プラン選択状態のアクセシビリティ (bug-hunt F-2-01)
+|--------------------------------------------------------------------------
+|
+| /onboarding/checkout?plan=starter で該当カードが青枠 (border-primary) で強調されるが、
+| その状態がアクセシビリティツリーに一切現れていなかった。契約という不可逆操作の前段で
+| 「どのプランが選ばれているか」が支援技術利用者に伝わらないのは実害がある。
+|
+| role は偽らない: 排他選択なので aria-pressed (トグル) は誤りで、radiogroup 化は
+| 契約画面のキーボード操作モデルを作り替える規模になる。青枠が伝えている一事を
+| sr-only テキストで同じだけ伝える (Billing が「現在のプラン」Badge = テキストで
+| 同種の状態を伝えているのと同じ手口)。
+|
+| 注意: 既定の createOrganizationWithOwner() は free_plan_code を立てるため
+| BillingAccess が ActiveFreePlan と判定し、Checkout は /billing へリダイレクトされる。
+| grandfatherFreePlan: false を明示しないとこの画面に到達できない。
+|
+*/
+
+/**
+ * 相対座標の前後比較 (許容差 1px)。
+ *
+ * getBoundingClientRect はフォント描画・Chromium/WebKit 差・小数丸めで揺れうるため、
+ * 完全一致で比較すると flaky になる。「レイアウトが動いていない」ことを見たいので
+ * 1px の許容差を持つ。
+ *
+ * @param  array<string, float>|null  $before
+ * @param  array<string, float>|null  $after
+ * @param  list<string>  $keys
+ */
+function expectRectUnchanged(?array $before, ?array $after, array $keys, string $label): void
+{
+    expect($before)->not->toBeNull("{$label}: 変更前の矩形が取得できていない");
+    expect($after)->not->toBeNull("{$label}: 変更後の矩形が取得できていない");
+
+    foreach ($keys as $key) {
+        expect(abs($after[$key] - $before[$key]))
+            ->toBeLessThanOrEqual(1.0, "{$label}.{$key} が 1px を超えて動いた");
+    }
+}
+
+/** 未契約オーナーでログインし、Checkout に到達できる状態を作る */
+function checkoutFixture(): void
+{
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+
+    test()->actingAs($owner);
+}
+
+test('?plan= の事前選択が sr-only テキストでアクセシビリティツリーに現れる', function (): void {
+    checkoutFixture();
+
+    // ?plan= は org-scoped に session へ積まれ canonical URL へ 303 されるため、
+    // 着地は query 無しの /onboarding/checkout になる
+    $page = visit('/onboarding/checkout?plan=starter')
+        ->assertPathIs('/onboarding/checkout');
+
+    expect($page->script('window.location.search'))->toBe('');
+
+    // 受入条件 9: starter だけが「プラン名 + 初期候補」の note を持つ
+    $note = $page->text('[data-testid="plan-selected-note-starter"]');
+    expect($note)->toContain('Starter');
+    expect($note)->toContain('初期候補');
+    // まだ押していないので「選択中」とは言わない (CTA が「選択」のままなのと意味を揃える)
+    expect($note)->not->toContain('選択中');
+
+    expect($page->script(
+        'document.querySelectorAll(\'[data-testid^="plan-selected-note-"]\').length'
+    ))->toBe(1);
+});
+
+test('別プランを選び直すと note が移動し文言が選択中へ切り替わる', function (): void {
+    checkoutFixture();
+
+    $page = visit('/onboarding/checkout?plan=starter')
+        ->assertPathIs('/onboarding/checkout');
+
+    $page->click('[data-testid="select-plan-standard"]');
+
+    // 受入条件 10: 旧 note が消え、新プラン名を含む note が現れる
+    for ($i = 0; $i < 40; $i++) {
+        $moved = $page->script(
+            'document.querySelector(\'[data-testid="plan-selected-note-standard"]\') !== null'
+        );
+
+        if ($moved === true) {
+            break;
+        }
+
+        usleep(100_000);
+    }
+
+    expect($page->script(
+        'document.querySelector(\'[data-testid="plan-selected-note-starter"]\') === null'
+    ))->toBeTrue();
+
+    $note = $page->text('[data-testid="plan-selected-note-standard"]');
+    expect($note)->toContain('Standard');
+    // 押下後は CTA が「選択中」になるので note も同じ基準で切り替わる
+    expect($note)->toContain('選択中');
+});
+
+test('sr-only note の追加でカードのレイアウトが動かない', function (): void {
+    checkoutFixture();
+
+    $page = visit('/onboarding/checkout?plan=starter')
+        ->assertPathIs('/onboarding/checkout');
+
+    // カード上端からの相対 top と height を測る (異なるカード同士は比較しない)。
+    // 欠落を黙って握り潰さないよう、カードが無ければ null を明示的に返す。
+    $measure = <<<'JS'
+        (() => {
+            const out = {};
+            for (const code of ['starter', 'standard']) {
+                const card = document.querySelector('[data-testid="plan-card-' + code + '"]');
+                if (card === null) { out[code] = null; continue; }
+                const base = card.getBoundingClientRect().top;
+                const pick = (sel) => {
+                    const el = card.querySelector(sel);
+                    if (el === null) return null;
+                    const r = el.getBoundingClientRect();
+                    return {
+                        top: Math.round((r.top - base) * 100) / 100,
+                        height: Math.round(r.height * 100) / 100,
+                    };
+                };
+                out[code] = {
+                    heading: pick('h3'),
+                    price: pick('[data-testid="plan-price"]'),
+                    cta: pick('[data-testid="select-plan-' + code + '"]'),
+                };
+            }
+            return out;
+        })()
+    JS;
+
+    // script() は locator と違って自動待機しないため、hydration 完了までは
+    // カードが DOM に無い。計測前に明示的に待つ。
+    for ($i = 0; $i < 40; $i++) {
+        $ready = $page->script(
+            'document.querySelector(\'[data-testid="plan-card-starter"]\') !== null'
+        );
+
+        if ($ready === true) {
+            break;
+        }
+
+        usleep(100_000);
+    }
+
+    $before = $page->script($measure);
+    // 計測対象が取れていることを先に固定する (取れないまま比較して緑になるのを防ぐ)
+    expect($before['starter'])->not->toBeNull();
+    expect($before['standard'])->not->toBeNull();
+
+    $page->click('[data-testid="select-plan-standard"]');
+
+    for ($i = 0; $i < 40; $i++) {
+        $moved = $page->script(
+            'document.querySelector(\'[data-testid="plan-selected-note-standard"]\') !== null'
+        );
+
+        if ($moved === true) {
+            break;
+        }
+
+        usleep(100_000);
+    }
+
+    $after = $page->script($measure);
+
+    // Starter: note 有 → 無、CTA 文言は不変 = 交絡なしの最も強い検査。
+    // 価格行も測る (headerBadges 追加で見出し行が伸びれば価格が押し下がるため)。
+    expectRectUnchanged($before['starter']['heading'], $after['starter']['heading'], ['top', 'height'], 'starter.heading');
+    expectRectUnchanged($before['starter']['price'], $after['starter']['price'], ['top', 'height'], 'starter.price');
+    expectRectUnchanged($before['starter']['cta'], $after['starter']['cta'], ['top', 'height'], 'starter.cta');
+
+    // Standard: note 無 → 有 だが CTA 文言が「選択」→「選択中」に変わるため、
+    // CTA の height は不変条件にしない (headerBadges 由来か文言差か判別できないため)。
+    expectRectUnchanged($before['standard']['heading'], $after['standard']['heading'], ['top', 'height'], 'standard.heading');
+    expectRectUnchanged($before['standard']['price'], $after['standard']['price'], ['top', 'height'], 'standard.price');
+    expectRectUnchanged($before['standard']['cta'], $after['standard']['cta'], ['top'], 'standard.cta');
+
+    // note 自身は可視領域を持たない。矩形だけでなく Tailwind の sr-only 契約
+    // (absolute + overflow:hidden + clip/clip-path) そのものも確認する。
+    expect($page->script(<<<'JS'
+        (() => {
+            const el = document.querySelector('[data-testid="plan-selected-note-standard"]');
+            const r = el.getBoundingClientRect();
+            const cs = getComputedStyle(el);
+            const clipped =
+                (cs.clip !== 'auto' && cs.clip !== '') ||
+                (cs.clipPath !== 'none' && cs.clipPath !== '');
+            return {
+                tiny: r.width <= 1 && r.height <= 1,
+                absolute: cs.position === 'absolute',
+                hidden: cs.overflow === 'hidden',
+                clipped,
+            };
+        })()
+    JS))->toMatchArray([
+        'tiny' => true,
+        'absolute' => true,
+        'hidden' => true,
+        'clipped' => true,
+    ]);
+});

```

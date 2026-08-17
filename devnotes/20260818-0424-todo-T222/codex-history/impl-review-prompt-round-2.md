# T222 実装レビュー Round 2

Round 1 の指摘に対応した。対応マトリクスは
`devnotes/20260818-0424-todo-T222/codex-history/impl-review-decisions-round-1.md` に記録済み。

## [Warning] 代表値 `success` だけでは `keep([self::SUCCESS])` へ縮めても全検査が緑 → 対応した

提案どおり、2 つの middleware へは広げず、`relayTo()` を直に呼ぶ dataset で
`NOTIFICATION_KEYS` の全キーを固定した。負のコントロール (通知キー以外は延命しない) も足した。
`ageFlashData()` を要求境界に見立てて 3 hop 進める形にしてある
(1 回目 = 種まき要求の終了 / 2 回目 = 跳ね返り要求の終了 / 3 回目 = 着地要求の終了)。

## [Critical] 必須検証コマンドが未完了 → 全数を完走させた

| コマンド | 結果 |
|---|---|
| `composer test` | **passed** — tests=5791 / passed=5789 / skipped=2 / failed=0 / assertions=25380 |
| `pnpm test` | **passed** — Test Files 161 passed (161) / Tests 2009 passed (2009) |
| `pnpm test:packages` | **passed** — Test Files 10 passed (10) / Tests 106 passed (106) |
| `composer phpstan` (level 10) | OK (989 ファイル・エラー 0) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` / `pnpm build` | OK |
| `pnpm typecheck:packages` / `pnpm build:packages` | OK |

## 追加差分 (tests/Feature/Inertia/ の Round 1 からの変更を含む全文差分)

```diff
diff --git a/tests/Feature/Inertia/FlashNotificationRelayBounceTest.php b/tests/Feature/Inertia/FlashNotificationRelayBounceTest.php
new file mode 100644
index 0000000..67f73a5
--- /dev/null
+++ b/tests/Feature/Inertia/FlashNotificationRelayBounceTest.php
@@ -0,0 +1,183 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Organization\OrganizationMembershipService;
+use App\Support\Http\FlashNotificationRelay;
+use Illuminate\Session\ArraySessionHandler;
+use Illuminate\Session\Store;
+use Illuminate\Support\Facades\Route;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * 跳ね返り (中間 redirect) を跨ぐ通知の中継 (FlashNotificationRelay::relayTo) の振る舞い固定。
+ *
+ * 対象は「中間 GET が別 redirect を返す」2 経路:
+ *   - RequireActiveSubscription (課金ゲート → onboarding へ跳ね返る)
+ *   - EnsureAccountNotPendingDeletion (退会予約中の凍結 → /settings へ跳ね返る)
+ *
+ * ★観測点は**跳ね返り応答の直後の session** である。着地画面の共有 prop に
+ *   `new_api_key` が無いことを見ても意味がない (着地画面はもともとその prop を公開しないので
+ *   `reflash()` のままでも緑になる = 偽陽性)。
+ * ★`withSession([...])` は値を置くだけで**一時メッセージの世代情報を作らない**ため、
+ *   `keep()` / `reflash()` / 要求終了時の失効を再現できない。よって本テストは
+ *   **必ず本物の要求境界を跨いで一時メッセージを作る** (テスト専用 route で
+ *   `redirect()->with(...)` / `session()->flash(...)` を実行する)。
+ *
+ * 通知キーの検査範囲: 跳ね返りを実 HTTP で通す 2 本は代表値として `success` で観測する
+ * (2 つの middleware × 4 キーには広げない)。**キー集合そのもの**は middleware に依らないので、
+ * `relayTo()` を直に呼ぶ dataset で `NOTIFICATION_KEYS` の全キーを固定する
+ * (代表値だけだと `keep([self::SUCCESS])` へ縮めても全検査が緑のままになるため)。
+ *
+ * error の中継契約 (fail-closed): `RELAYABLE_ERROR_KEYS` は空 = error は一切中継しない。
+ * **将来ここへキーを足すときは、同じ変更で「許可キーだけ残る (正例) / それ以外と
+ * 名前付き bag は残らない (負例)」を本ファイルへ足すこと。**
+ */
+
+/**
+ * 中継テスト用の種まき route を登録する。
+ * 課金ゲートにも凍結にも掛からない middleware 構成 (`web` + `auth`) にして、
+ * 「跳ね返りの 1 hop 前」に相当する要求をそのまま再現する。
+ */
+function registerFlashRelaySeedRoute(Closure $handler): void
+{
+    Route::middleware(['web', 'auth'])->get('/__flash-relay/seed', $handler);
+}
+
+/** 通知 (success) と内部状態の flash (new_api_key) を同じ要求で積む種まき。 */
+function seedNotificationAndInternalFlash(): void
+{
+    registerFlashRelaySeedRoute(function () {
+        session()->flash('new_api_key', 'sk_live_平文キー');
+
+        return redirect('/projects')->with('success', '中継テストの通知');
+    });
+}
+
+// ── 課金ゲートの跳ね返り (RequireActiveSubscription) ──
+
+test('課金ゲートの跳ね返りは通知だけを 1 hop 延命し、内部状態の flash は持ち越さない', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    seedNotificationAndInternalFlash();
+
+    // 1. 実際の要求境界を跨いで一時メッセージを作る
+    $this->actingAs($owner)->get('/__flash-relay/seed')->assertRedirect('/projects');
+
+    // 2-3. 跳ね返りを起こし、**その応答直後の session** を見る
+    $this->actingAs($owner)->get('/projects')
+        ->assertRedirect(route('onboarding.checkout'))
+        ->assertSessionHas('success', '中継テストの通知')
+        ->assertSessionMissing('new_api_key');
+
+    // 4. 着地の GET で共有 prop に載る
+    $this->actingAs($owner)->get(route('onboarding.checkout'))
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('flash.success', '中継テストの通知'));
+
+    // 5. 着地の後は失効している (延命は 1 hop だけ)。ここで再び中継を通る route は使わない
+    $this->actingAs($owner)->get(route('onboarding.checkout'))
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('flash.success', null));
+});
+
+// ── 退会予約中の凍結の跳ね返り (EnsureAccountNotPendingDeletion) ──
+
+test('凍結の跳ね返りは通知だけを 1 hop 延命し、内部状態の flash は持ち越さない', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
+    $owner->refresh();
+    seedNotificationAndInternalFlash();
+
+    $this->actingAs($owner)->get('/__flash-relay/seed')->assertRedirect('/projects');
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertRedirect(route('settings'))
+        ->assertSessionHas('success', '中継テストの通知')
+        ->assertSessionMissing('new_api_key');
+
+    $this->actingAs($owner)->get(route('settings'))
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('flash.success', '中継テストの通知'));
+
+    $this->actingAs($owner)->get(route('settings'))
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('flash.success', null));
+});
+
+// ── 延命されるキー集合そのものの固定 (middleware に依らない中継側の契約) ──
+
+/*
+ * `ageFlashData()` を要求境界に見立てて 3 hop 分進める:
+ *   1 回目 = 種まき要求の終了 (通知が「直前 hop の flash」になる)
+ *   2 回目 = 跳ね返り要求の終了 (延命されていれば着地要求で読める)
+ *   3 回目 = 着地要求の終了 (延命は 1 hop だけなのでここで失効する)
+ */
+test('中継は NOTIFICATION_KEYS の全キーを 1 hop 延命し、その次で失効する', function (string $key): void {
+    $session = new Store('flash-relay-keys', new ArraySessionHandler(120));
+    $session->start();
+    $session->flash($key, "{$key} の通知");
+    $session->ageFlashData();
+
+    FlashNotificationRelay::relayTo($session);
+
+    $session->ageFlashData();
+    expect($session->get($key))->toBe("{$key} の通知");
+
+    $session->ageFlashData();
+    expect($session->get($key))->toBeNull();
+})->with(FlashNotificationRelay::NOTIFICATION_KEYS);
+
+test('中継は通知キー以外を延命しない (負のコントロール)', function (): void {
+    $session = new Store('flash-relay-keys', new ArraySessionHandler(120));
+    $session->start();
+    $session->flash('new_api_key', 'sk_live_平文キー');
+    $session->ageFlashData();
+
+    FlashNotificationRelay::relayTo($session);
+
+    $session->ageFlashData();
+    expect($session->get('new_api_key'))->toBeNull();
+});
+
+// ── error は中継しない (RELAYABLE_ERROR_KEYS が空 = fail-closed) ──
+
+test('検証エラー (default bag) は跳ね返りで中継されない', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    registerFlashRelaySeedRoute(fn () => redirect('/projects')->withErrors(['project_name' => '名前を入力してください']));
+
+    $this->actingAs($owner)->get('/__flash-relay/seed')->assertRedirect('/projects');
+
+    $this->actingAs($owner)->get('/projects')
+        ->assertRedirect(route('onboarding.checkout'))
+        ->assertSessionMissing('errors');
+});
+
+test('名前付き error bag も跳ね返りで中継されない', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    registerFlashRelaySeedRoute(fn () => redirect('/projects')->withErrors(['project_name' => '名前を入力してください'], 'projectForm'));
+
+    $this->actingAs($owner)->get('/__flash-relay/seed')->assertRedirect('/projects');
+
+    $this->actingAs($owner)->get('/projects')
+        ->assertRedirect(route('onboarding.checkout'))
+        ->assertSessionMissing('errors');
+});
+
+/*
+ * ★この 1 本だけ HTTP を跨がない: Laravel の Store::save() は保存前に errors を
+ *   ViewErrorBag として直列化する (prepareErrorBagForSerialization) ため、
+ *   ViewErrorBag でない値は**要求境界を跨げず 500 になる**。
+ *   よって「置き直しをしない」という中継側の契約は relayTo() を直に呼んで固定する。
+ */
+test('errors に ViewErrorBag でない値が入っていても置き直されない', function (): void {
+    $session = new Store('flash-relay-contract', new ArraySessionHandler(120));
+    $session->start();
+    $session->flash(FlashNotificationRelay::ERRORS, 'ViewErrorBag ではないただの文字列');
+    // 1 hop 進め、「直前 hop で積まれた flash」の状態にする
+    $session->ageFlashData();
+
+    FlashNotificationRelay::relayTo($session);
+
+    // 延命対象に載るのは通知キーだけで、errors は置き直されない
+    expect($session->get('_flash.new'))->toBe(FlashNotificationRelay::NOTIFICATION_KEYS);
+});

```

この対応で全体判定を再度出してほしい (APPROVED / CHANGES_REQUESTED)。

<?php

declare(strict_types=1);

use Pest\Browser\Api\PendingAwaitablePage;

/*
|--------------------------------------------------------------------------
| Inertia SPA の履歴復元に対する PII 秘匿 (bug-hunt F-4-01) の Browser E2E
|--------------------------------------------------------------------------
|
| bug-hunt shard-4 の再現手順そのもの:
|   ログイン → 認証済み画面 → SPA でログアウト → ブラウザバック
|   → 期待: 認証済み画面 (PII) が **一度も描画されず** /login に倒れる
|
| 経路の区別 (docs/supported-browsers.md が正本):
|   - 経路 B (Safari の真の bfcache) は本ハーネスでは再現できない
|     → tests/Browser/AuthenticatedPageBfcacheTest.php が skip 判定付きで扱う
|   - 経路 C (Inertia の popstate 履歴復元) は **bfcache とは無関係**の Inertia 内部機構であり、
|     Chromium / WebKit の両レーンで再現する → **skip しない。恒久回帰である**
|
| テストは 3 本に分ける (実装前の red 確認をコード改変なしで行うため):
|   1. 「history state が暗号化されている」— 施策 1 の単独検証。実装前は平文で fail
|   2. 「ログアウト後の戻るで PII が復元されない」— F-4-01 の再現。実装前は PII 復元で fail
|      (暗号化が degrade しても PII が復元されて落ちるため、単体でも空振りしない)
|   3. 「ログイン中の戻るは client-side で完結する」— 後退検出 (負のコントロール)
|
| 正のコントロール (空振り green を作らない):
|   a. 一連の操作の間 JS 実行コンテキストが生存していること
|      = 本当に same-document の SPA popstate であり、フルリロードで空振りしていない
|   b. 「戻る」の前に仕込んだ MutationObserver が PII 文字列の DOM 出現を一度も記録しないこと
|      = 終状態だけでなく **途中フレームでも DOM に現れていない** ことの機械的保証
|      (「ペイントされていない」ではなく「DOM に出現していない」の検証。
|       本件の PII は Svelte の通常テキストノードとして描画されるため実用上十分)
|
| 実行: composer test:browser (Chromium / WebKit の両レーン)。
| 前提: pnpm build 済み。
*/

/**
 * PII 文字列が **途中フレームでも** DOM に現れたかを記録する監視を仕込む。
 *
 * 要件は「復元時に PII を一度も描画しない」であり、終状態だけを見る assertDontSee では
 * 「一瞬出て消えた」を取り逃す。
 *
 * 検出するのは正確には「**DOM に PII 文字列が出現したか**」であり、
 * 「ペイントされたか」ではない。本件の PII は Svelte が通常のテキストノードとして描画するため、
 * DOM 出現の検出で実用上十分 (DOM に一度も現れなければペイントもされない)。
 *
 * 同一タスク内で「追加 → 削除」されると callback 時点の `innerText` には残らないため、
 * 現在の DOM だけでなく **MutationRecord 自体** も検査する:
 *   (1) 現在の document.body.innerText
 *   (2) 各 record の addedNodes[].textContent
 *   (3) characterDataOldValue: true を指定した上での record.oldValue (テキスト置換)
 * 観測開始時点の初期状態も 1 度チェックする。
 */
function inertiaHistoryWatchForPii(PendingAwaitablePage $page, string $needle): void
{
    $encoded = json_encode($needle, JSON_THROW_ON_ERROR);

    $page->script(<<<JS
        (() => {
            const needle = {$encoded};
            const hit = (text) => typeof text === 'string' && text.includes(needle);

            window.__piiSeen = hit(document.body?.innerText);

            const observer = new MutationObserver((records) => {
                if (hit(document.body?.innerText)) { window.__piiSeen = true; return; }
                for (const record of records) {
                    if (hit(record.oldValue)) { window.__piiSeen = true; return; }
                    for (const node of record.addedNodes) {
                        if (hit(node.textContent)) { window.__piiSeen = true; return; }
                    }
                }
            });
            // 監視対象は documentElement (body 自体が置換されても observer が外れない)。
            // 判定側は live 参照の document.body?.innerText を使う
            // (documentElement.textContent にすると <script> 等の非表示テキストまで拾い
            //  偽陽性で flaky になるため、監視対象と判定対象は分ける)。
            observer.observe(document.documentElement, {
                childList: true,
                subtree: true,
                characterData: true,
                characterDataOldValue: true,
            });
            return true;
        })()
    JS);
}

/** ブラウザ側の条件が満たされるまで待つ (plugin の assertion は auto-retry しない)。 */
function inertiaHistoryWaitUntil(
    PendingAwaitablePage $page,
    string $expression,
    string $message,
    int $attempts = 100,
): void {
    for ($i = 0; $i < $attempts; $i++) {
        if ($page->script("Boolean({$expression})") === true) {
            expect(true)->toBeTrue();

            return;
        }
        usleep(50_000);
    }

    throw new RuntimeException("条件が満たされませんでした: {$message} (式: {$expression})");
}

test('認証済み画面の history state が暗号化されている', function (): void {
    // 施策 1 (EncryptHistory) が実ブラウザで効いていることの単独検証。
    // ※ 「Inertia は暗号化した page を ArrayBuffer で history state に入れる」という
    //    @inertiajs/core の実装前提に依存する。Inertia を更新したらここを見直すこと。
    // ※ 再現テスト側から分離してあるので、実装前の red 確認を
    //    「一時的にコメントアウトする」手作業なしに行える。
    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    $page = visit('/dashboard');
    $page->assertSee($owner->name);

    inertiaHistoryWaitUntil(
        $page,
        'window.history.state?.page instanceof ArrayBuffer',
        'history state が暗号化されていない (EncryptHistory 未適用、または crypto.subtle 不在)',
    );
});

test('ログアウト後のブラウザバックで Inertia 履歴から PII が復元されない', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    // bug-hunt F-4-01 の再現手順: /dashboard → /manage/users → ログアウト → 戻る
    //
    // ※ サイドバーの nav item は素の <a href> (SidebarNavItems.svelte) であり
    //   Inertia Link ではない = ここは cross-document 遷移。SPA の pushState エントリを
    //   作るのは **ログアウト自身** (router.post('/logout') → 302 追従 → '/' へ pushState) で、
    //   その 1 つ前 (= /manage/users の document エントリ) へ戻るのが本件の popstate 復元。
    $page = visit('/dashboard');
    $page->assertSee($owner->name);
    // 文言「メンバー」は AppLayout.svelte の navItems 由来。testid (nav-item-*) は
    // desktop / mobile の 2 箇所で重複するため文言 locator を使う。
    // 文言が変わったら **UI ではなく本テストを追随させる**こと。
    $page->click('メンバー');
    inertiaHistoryWaitUntil($page, "window.location.pathname === '/manage/users'", 'メンバーへ遷移しない');
    $page->assertSee($owner->name); // メンバー一覧に PII (氏名) が出ている

    // 正のコントロール: JS 実行コンテキストの生存マーカー (フルリロードで消える)
    $page->script("window.__inertiaHistoryProbe = 'alive'; true");

    // SPA でログアウト (AppLayout の router.post('/logout') = F-4-01 の再現手順)
    $page->click('@app-user-menu-toggle');
    $page->click('@logout-button');
    inertiaHistoryWaitUntil($page, "window.location.pathname === '/'", 'ログアウト後に LP へ着地しない');
    $page->assertScript('window.__inertiaHistoryProbe', 'alive'); // ここまで same-document

    // 「戻る」の前に瞬間露出の監視を仕込む (終状態の assertDontSee では
    // 「一瞬表示されて消えた」を取り逃すため)
    inertiaHistoryWatchForPii($page, $owner->name);

    // ブラウザバック = Inertia の popstate 履歴復元
    $page->back();

    inertiaHistoryWaitUntil(
        $page,
        "window.location.pathname === '/login'",
        'ログアウト後の戻るで /login に倒れない',
    );

    // 本丸: 復元 → login までの間、PII が **一度も** 描画されていない
    // (復号失敗時はコンポーネント swap 自体が起きない、という設計の機械的な証明)
    $page->assertScript('window.__piiSeen', false);

    // popstate → 再問い合わせ → login まで same-document で完結している
    // (= 本当に SPA 履歴復元経路を通った。フルリロードなら消えている)
    $page->assertScript('window.__inertiaHistoryProbe', 'alive');
    $page->assertDontSee($owner->name)->assertNoJavaScriptErrors();
});

test('ログイン中の戻るは従来どおり client-side で完結する (誤発火しない)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    $page = visit('/dashboard');
    $page->assertSee($owner->name);
    $page->script("window.__inertiaHistoryProbe = 'alive'; true");

    // Inertia Link (Dashboard.svelte の TextLink「通知を確認」) = SPA visit。
    // サイドバーの nav item は素の <a href> で cross-document のため、
    // 「client-side で完結する」ことを検証する本テストでは使えない。
    // 文言は Dashboard.svelte 由来 (testId 未付与)。変わったら本テストを追随させる。
    $page->click('通知を確認');
    inertiaHistoryWaitUntil($page, "window.location.pathname === '/notifications'", '通知へ SPA 遷移しない');
    // SPA visit なので実行コンテキストは維持されている (前提の確認)
    $page->assertScript('window.__inertiaHistoryProbe', 'alive');

    $page->back();

    inertiaHistoryWaitUntil($page, "window.location.pathname === '/dashboard'", '戻るで dashboard に戻らない');
    // 復号に成功する = 再取得も hard reload も起きない (撮影 PWA の制約を壊さない)
    $page->assertScript('window.__inertiaHistoryProbe', 'alive');
    $page->assertSee($owner->name)->assertNoJavaScriptErrors();
});

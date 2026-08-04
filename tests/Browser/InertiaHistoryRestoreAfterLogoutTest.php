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

/**
 * ブラウザ側で **JSON 204 のログアウト**を行う (画面遷移を起こさない = 履歴鍵を残したまま)。
 *
 * 実運用のログアウト導線 (router.post) は着地の Inertia page を適用して鍵を捨てるが、
 * ここでは「セッションだけ切れて、そのタブは何も知らない」状態
 * (= 期限切れ / 他デバイスからの強制ログアウトと同じ形) を決定的に作る。
 *
 * ※ tests/Browser/AuthenticatedPageBfcacheTest.php の bfcacheLogoutInBrowser() と同型だが、
 *   Pest のグローバル関数は再宣言できないため本ファイル専用の名前で持つ。
 */
function inertiaHistoryLogoutWithoutNavigation(PendingAwaitablePage $page): void
{
    $authenticated = $page->script(<<<'JS'
        (async () => {
            const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
            const token = match ? decodeURIComponent(match[1]) : '';
            await fetch('/logout', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-XSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });
            const status = await fetch('/session/status', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' },
            }).then((response) => response.json());
            return status.authenticated;
        })()
    JS);

    expect($authenticated)->toBeFalse('前提条件失敗: ブラウザ側のログアウトでセッションが無効化されていない');
}

test('セッションが切れたタブは次の Inertia visit で履歴鍵が入れ替わり、戻っても PII が出ない', function (): void {
    // T089-b: 認証失敗 (AuthenticationException) 契機の Inertia::clearHistory() を
    // 実ブラウザで一気通貫に固定する。JSON 204 のログアウトで「セッションだけ切れて
    // 画面遷移していないタブ」を作り、次の Inertia visit で **履歴鍵が旧鍵から入れ替わり、
    // かつ戻っても過去の PII が描画されない**ことを観測する。
    // (テストが示せるのはこの 2 つの挙動契約までで、「旧鍵が二度と手に入らない」ことの
    //  暗号学的証明ではない。文書側もこの範囲を超えた表現をしないこと。)
    [, $owner] = createOrganizationWithOwner();
    $this->actingAs($owner);

    $page = visit('/dashboard');
    $page->assertSee($owner->name);

    // 正のコントロール (1): 認証済み履歴が暗号化されている = 捨てるべき鍵が存在する
    inertiaHistoryWaitUntil(
        $page,
        'window.history.state?.page instanceof ArrayBuffer',
        'history state が暗号化されていない (EncryptHistory 未適用、または crypto.subtle 不在)',
    );

    // JS 実行コンテキストの生存マーカー (フルリロードで消える)
    $page->script("window.__inertiaHistoryProbe = 'alive'; true");

    // 捨てられるべき「認証済み履歴を復号できる鍵」の実値を控える。
    // ※ null 判定ではなく **値の変化** を見るのが本テストの肝。理由は下の「本丸 (1)」。
    $keyBefore = $page->script("window.sessionStorage.getItem('historyKey')");
    expect($keyBefore)->toBeString();

    inertiaHistoryLogoutWithoutNavigation($page);

    // 正のコントロール (2): 204 直後は鍵が **同一のまま** = このタブはまだ何も知らない
    // (= このあと鍵が入れ替わることに意味がある)
    expect($page->script("window.sessionStorage.getItem('historyKey')"))
        ->toBe($keyBefore, '204 ログアウト直後に履歴鍵が既に変わっている (前提が崩れ、以降の観測が空振りする)');

    // Inertia Link (Dashboard.svelte の TextLink「通知を確認」) で Inertia visit を起こす。
    // 認証が切れているのでサーバは /login へ倒し、その Inertia 応答が clearHistory を消費する。
    // 文言は Dashboard.svelte 由来 (testId 未付与)。変わったら本テストを追随させること。
    $page->click('通知を確認');
    inertiaHistoryWaitUntil(
        $page,
        "window.location.pathname === '/login'",
        'セッション切れの Inertia visit で /login に倒れない',
    );

    // 本丸 (1): **履歴鍵が旧鍵から入れ替わっている** = 以降の「戻る」で過去エントリを復号できない。
    //
    // ここを `historyKey === null` で書いてはいけない。EncryptHistory は guest 面
    // (/login) にもグローバル適用されるため、Inertia は clearHistory で鍵を消した直後に
    // **着地ページ用の新しい鍵を即座に採番して sessionStorage へ書き戻す**
    // (実測: 鍵は常に非 null のまま、値だけが入れ替わる。
    //  devnotes/20260804-0900-t089-t090-residual-risk/probe-history-key-behavior.md)。
    // したがって固定すべきは「鍵の欄が空になること」ではなく
    // 「**現在の履歴鍵が旧鍵から変わっていること**」である。
    // null も「旧鍵ではない」を満たしてしまうので、**非 null との合わせ技**にして
    // 「新しい鍵へ入れ替わる」という文書上の主張までを固定する。
    $keyEscaped = json_encode($keyBefore, JSON_THROW_ON_ERROR);
    inertiaHistoryWaitUntil(
        $page,
        "window.sessionStorage.getItem('historyKey') !== null"
        ." && window.sessionStorage.getItem('historyKey') !== {$keyEscaped}",
        '/login 着地後も旧履歴鍵が残っている (clearHistory が消費されていない)',
    );

    // 「戻る」の前に瞬間露出の監視を仕込む (終状態の assertDontSee では取り逃す)
    inertiaHistoryWatchForPii($page, $owner->name);

    $page->back();

    inertiaHistoryWaitUntil(
        $page,
        "window.location.pathname === '/login'",
        'セッション切れ後の戻るで /login に倒れない',
    );

    // 本丸 (2): 復元 → login までの間、PII が **一度も** 描画されていない
    $page->assertScript('window.__piiSeen', false);
    // same-document で完結している (= 本当に SPA 履歴復元経路を通った)
    $page->assertScript('window.__inertiaHistoryProbe', 'alive');
    $page->assertDontSee($owner->name)->assertNoJavaScriptErrors();
});

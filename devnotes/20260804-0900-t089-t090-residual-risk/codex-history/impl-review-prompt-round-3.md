# 対応マトリクス: impl-review Round 2

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 1 / Suggestion 1 + 表現の是正 1)。
指摘は 3 件ともそのとおりなので**全件対応**した。

## [Warning] `LogoutResponse` docblock の記述が追加した B1 と矛盾する

- 指摘: 「JSON 204 経路はリポジトリ内では Browser テストの補助 (**経路 B の再現**) にしか
  使われていない」は、本タスクで追加した B1 自身が **経路 C の再現**に JSON 204 を使うため
  事実と矛盾する。
- 判断: **対応する**
- 根拠: 本タスクの主目的は「決定と事実を恒久文書に正しく固定し、次に読む人を誤らせないこと」で
  あり、自分の追加によって docblock を陳腐化させたまま残すのは目的そのものに反する。
- 対応内容: 「経路 B の bfcache 再現と、経路 C の認証失敗契機 clearHistory の再現の**両方**で、
  『セッションだけ切れて、そのタブは何も知らない』状態を決定的に作るための道具として使う」
  と書き換えた。

## [Suggestion] `!== $keyBefore` は `null` でも成功してしまう

- 判断: **対応する**
- 根拠: 指摘のとおり。`docs/supported-browsers.md` に「鍵は非 null のまま値だけが入れ替わる」と
  **文書として書いた**以上、テストはその主張を固定すべきである。null 許容のままだと、
  将来 Inertia が「消しっぱなし」に変わっても気づけず、文書だけが嘘になる。
- 対応内容: assertion を
  `historyKey !== null && historyKey !== <旧鍵>` の合わせ技にした。

## [表現の是正] 「旧鍵が二度と手に入らない」は証明できる範囲を超える

- 判断: **対応する (表現を弱める)**
- 根拠: テストが示せるのは「現在の履歴鍵が旧鍵から変わった」「戻っても PII が描画されない」
  という**挙動契約**までで、暗号学的な到達不能性ではない。
  T089 以来この文書群は「保証しない範囲を必ず対で書く」書式を守っており、
  絶対表現はその方針に反する (誤った安心を与える)。
- 対応内容: 3 箇所を是正した。
  - Browser テストの docblock: 固定するのは 2 つの挙動契約であること、
    「旧鍵が二度と手に入らない」ことの証明ではないことを明記
  - `probe-history-key-behavior.md`: 同旨に書き換え + 最終的な守りは
    MutationObserver による PII 非描画であることを追記
  - `docs/supported-browsers.md`: 「ここで固定できるのは挙動契約であって暗号学的証明ではない。
    保証をこれより広く書かないこと」を追記

## [参考] Codex が「問題なし」と明示した点

- `(A)` の LogoutResponse 3 導線への drift fix 自体は
  「編集対象内の事実誤認を直す限定的な drift fix なので本タスクに含めて問題ない」と判定された。
- `(C)` の `array_values()` 削除は「PHPStan の `arrayValues.list` に従う判断は正当」と判定された。

## Round 2 指摘を反映した差分

```diff
diff --git a/app/Http/Responses/Fortify/LogoutResponse.php b/app/Http/Responses/Fortify/LogoutResponse.php
index 656fde8..551a08f 100644
--- a/app/Http/Responses/Fortify/LogoutResponse.php
+++ b/app/Http/Responses/Fortify/LogoutResponse.php
@@ -50,11 +50,23 @@
  * 「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」に限られる
  * (受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
  *
+ * **`clearHistory` の発行契機は本クラスだけではない。** セッション期限切れと
+ * 他デバイスからの強制ログアウトは「利用者が明示的に終わらせた」契機を持たないため
+ * 本クラスを通らないが、どちらも `AuthenticationException` として現れ、
+ * `bootstrap/app.php` の render callback が同じフラグを積む。
+ * その結果、上記 204 経路の残存リスク (画面遷移しないまま戻る) も、
+ * **そのタブが次に認証を要する Inertia visit を行った時点で解消する**
+ * (一度もサーバと話さないまま戻る場合だけが残る)。保証範囲の正本は
+ * `docs/supported-browsers.md`。
+ *
  * このアプリでは実運用上その条件を満たす: `/logout` を叩く導線は
- * `AppLayout.svelte` (通常画面のユーザーメニュー) と `pages/Auth/VerifyEmail.svelte`
- * (メール認証待ち画面の離脱導線) の 2 箇所で、**いずれも `router.post('/logout')` =
+ * `AppLayout.svelte` (通常画面のユーザーメニュー) / `pages/Auth/VerifyEmail.svelte`
+ * (メール認証待ち画面の離脱導線) / `pages/Auth/ConfirmRecentAuth.svelte`
+ * (再認証画面の離脱導線) の 3 箇所で、**いずれも `router.post('/logout')` =
  * Inertia visit**。302 を XHR が追従し、**正常完了時に**着地の Inertia page を適用する。
- * JSON 204 経路はリポジトリ内では Browser テストの補助 (経路 B の再現) にしか使われていない。
+ * JSON 204 経路はリポジトリ内では Browser テストの補助にしか使われていない
+ * (「セッションだけ切れて、そのタブは何も知らない」状態を決定的に作るための道具として、
+ *  経路 B の bfcache 再現と、経路 C の認証失敗契機 clearHistory の再現の両方で使う)。
  * **ログアウト導線を非 Inertia 経路で新設すると経路 C の保証条件が崩れる**。
  * この「一本である」不変条件は
  * `tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定する。
diff --git a/docs/supported-browsers.md b/docs/supported-browsers.md
index d93484b..65b7322 100644
--- a/docs/supported-browsers.md
+++ b/docs/supported-browsers.md
@@ -8,9 +8,12 @@ # サポート対象ブラウザ方針
 
 | 経路 | 担当 | 何を保証するか |
 |------|------|----------------|
-| A: HTTP / disk / proxy cache、Chrome・Firefox の bfcache | `NoStoreCacheHeadersForAuthenticatedPages` | `no-store, private` により格納拒否 / cookie 変更時 evict |
-| B: Safari の真の bfcache (`pagehide` / `pageshow`) | `resources/js/lib/bfcache-guard.ts` + `session.status` プローブ | **描画前に同期秘匿**し、セッション有効なら秘匿解除のみ (hard reload しない) |
-| C: Inertia SPA のクライアント履歴復元 (`popstate`) | `Inertia\Middleware\EncryptHistory` (web グループ) + `App\Http\Responses\Fortify\LogoutResponse` の `Inertia::clearHistory()` | ログアウト後は復号不能 → **コンポーネントを描画しないまま**再問い合わせ → `/login` |
+| A: HTTP / disk / proxy cache、Chrome・Firefox の bfcache | `App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages` | `no-store, private` により格納拒否 / cookie 変更時 evict |
+| B: Safari の真の bfcache (`pagehide` / `pageshow`) | `resources/js/lib/bfcache-guard.ts` + `session.status` プローブ (`App\Http\Controllers\Auth\SessionStatusController`) | **描画前に同期秘匿**し、セッション有効なら秘匿解除のみ (hard reload しない) |
+| C: Inertia SPA のクライアント履歴復元 (`popstate`) | `Inertia\Middleware\EncryptHistory` (web グループ) + `Inertia::clearHistory()` の発行契機 2 つ: **ログアウト** (`App\Http\Responses\Fortify\LogoutResponse`) と **認証失敗** (`bootstrap/app.php` の `AuthenticationException` render callback) | 発行契機の後は復号不能 → **コンポーネントを描画しないまま**再問い合わせ → `/login` |
+
+> 経路 B / C の実装は上表の参照点が正本 (将来の差分レビューで担当実装を辿れるよう、
+> 本書では実装ファイルを名指しする)。
 
 経路 C の保証条件は「**`clearHistory: true` を含む Inertia page をクライアントが適用したタブ**」。
 `Inertia::clearHistory()` はサーバ session にフラグを積むだけで、`sessionStorage` の
@@ -23,6 +26,22 @@ # サポート対象ブラウザ方針
 **ログアウト導線を非 Inertia 経路 (JSON 204 で完結する XHR 等) で新設すると、
 この条件が崩れて経路 C の保証が外れる。**
 
+`clearHistory` の発行契機は**ログアウトだけではない**。セッション期限切れと
+他デバイスからの強制ログアウトはどちらも `AuthenticationException` として現れ、
+`bootstrap/app.php` の render callback がそこでもフラグを積む
+(着地の `/login` が Inertia 応答なので確実に消費される)。
+これが保証するのは「**認証失敗を契機に、以後の戻るによる復元を無効化する**」ことであり、
+**過去に遡って無効化するものではない** (保証範囲と保証外は「未対応事項」節に対で書く)。
+
+> **観測上の注意**: `clearHistory` の効果は `sessionStorage` の `historyKey` が
+> **空になること**ではなく、**旧鍵が破棄されて別の鍵に入れ替わること**である。
+> `EncryptHistory` は guest 面 (`/login`) にもグローバル適用されるため、Inertia は
+> 鍵を消した直後に着地ページ用の新しい鍵を採番して書き戻す (実測)。
+> 効いているかを確かめるときは **null 判定ではなく「非 null かつ旧鍵と不一致」**を見ること
+> (`tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php` がこの形で固定している)。
+> ここで固定できるのは**挙動契約** (鍵が入れ替わり、戻っても過去の PII が描画されない) であって、
+> 「旧鍵が二度と手に入らない」ことの暗号学的証明ではない。保証をこれより広く書かないこと。
+
 「対応している」という言葉を検証レベルと切り離さないこと。
 本書では **Current (実際に回っている検証)** と **Target (到達目標)** を分けて書く。
 
@@ -113,14 +132,37 @@ ## 未対応事項 (誤読を防ぐため明示列挙する)
   現行の `/logout` 導線は 3 箇所ともに Inertia visit のため実運用では条件を満たすが、
   非 Inertia のログアウト導線を新設すると保証が外れる
   (`tests/js/architecture/logout-call-site-inventory.test.ts` が deny-by-default で固定)。
-- **上記を満たしたタブ以外は保証外**。Inertia の履歴暗号鍵は
-  `sessionStorage` = タブ単位のため、同一ブラウザの**別タブ**に残った履歴は復号できてしまう。
-  すなわち **別タブでは、現在表示されていない過去の PII が履歴から再表示され得る**
+  ただし **204 で完結したタブも、次に認証を要する Inertia visit を行った時点**で
+  認証失敗契機の `clearHistory` により鍵を失う (保証条件そのものは不変。残存が縮んだだけ)。
+- **別タブに残る Inertia 履歴は保証外 (判断済みで受容する)**。Inertia の履歴暗号鍵は
+  `sessionStorage` = タブ単位のため、同一ブラウザの**別タブ**に残った履歴は復号できてしまう
   (例: タブ B でメンバー一覧を見た後に公開ページへ遷移 → タブ A でログアウト →
-  端末を引き継いだ第三者がタブ B で「戻る」)。塞ぐには全タブへのセッション失効伝播
-  (BroadcastChannel 等) が要るため本件では扱わない。**既知の残存リスク**。
-- **セッション期限切れ / 他デバイスからの強制ログアウトは経路 C の保証外**。
-  ブラウザに `clearHistory` が届かないため鍵が残り、履歴は復号できる。
+  端末を引き継いだ第三者がタブ B で「戻る」)。
+  **塞がない理由**は「自前機構が要るから」ではなく、以下の 3 点:
+  1. 鍵だけ捨てても**そのタブが今表示している PII は消えない**ため効果が薄い
+     (別タブの脅威の主部は「戻るで出る過去の PII」ではなく「今出ている PII」)。
+  2. 効果を出すには別タブの document を落とす必要があり、それは**回収可能な撮影成果を破棄する**。
+     テイクのアップロードは presigned URL で S3 へ直接送るため、セッションが切れていても
+     アップロードは継続でき再ログイン後に finalize できる。撮影を落とさないことは使命に直結する。
+  3. 下記「認証失敗契機の `clearHistory`」により、別タブも**次にサーバと話した時点で**鍵を失う。
+     残る露出は「二度と触られない放置タブ」に限られる。
+  **運用上の補完**: 共有端末では「使い終わったらブラウザを閉じる」運用を案内する
+  (ブラウザセッションが終われば `sessionStorage` ごと消える)。
+  **再検討条件**: セッション失効の push 経路 (Reverb / Echo 等) を別目的で導入したとき /
+  「全デバイスからログアウト」を UI 機能として提供するとき /
+  bug-hunt・実機受入確認で複数タブ運用が実際に観測されたとき。
+- **セッション期限切れ / 他デバイスからの強制ログアウトは、
+  「アプリが認証失敗を検知した以降」の戻るについて保証する** (限定保証)。
+  `bootstrap/app.php` の `AuthenticationException` render callback が `Inertia::clearHistory()` を
+  積み、着地の `/login` (Inertia 応答) が消費する。契約は
+  `tests/Feature/Security/InertiaHistoryGuardTest.php` が固定する。
+  **保証しない範囲**: そのタブが**一度もサーバと話さないまま**戻る場合。
+  このときタブは表示中の画面自体に PII を出しており、塞ぐには push か polling が要るため
+  扱わない (別タブと同じ判断)。
+  **`popstate` ごとの `session.status` プローブは採らない**:
+  (1) 表示中の PII は塞げないため目的を達しない、
+  (2) 通常の戻る/進むに毎回ネットワーク往復と秘匿オーバーレイが入り、プローブ失敗時は
+      「再試行」で操作が塞がれる (現場の不安定な回線で**新しい詰み**を作る)。
 - **非 Inertia 面 (Filament `/admin`) は経路 B / C の保証外**。独自 middleware stack を持ち
   web グループを経由せず、Inertia でも描画されない。
 - **非セキュアコンテキスト (`http://` の LAN IP 等) では経路 C が degrade する**。
diff --git a/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php b/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php
index da20a5d..7b103b8 100644
--- a/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php
+++ b/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php
@@ -202,3 +202,122 @@ function inertiaHistoryWaitUntil(
     $page->assertScript('window.__inertiaHistoryProbe', 'alive');
     $page->assertSee($owner->name)->assertNoJavaScriptErrors();
 });
+
+/**
+ * ブラウザ側で **JSON 204 のログアウト**を行う (画面遷移を起こさない = 履歴鍵を残したまま)。
+ *
+ * 実運用のログアウト導線 (router.post) は着地の Inertia page を適用して鍵を捨てるが、
+ * ここでは「セッションだけ切れて、そのタブは何も知らない」状態
+ * (= 期限切れ / 他デバイスからの強制ログアウトと同じ形) を決定的に作る。
+ *
+ * ※ tests/Browser/AuthenticatedPageBfcacheTest.php の bfcacheLogoutInBrowser() と同型だが、
+ *   Pest のグローバル関数は再宣言できないため本ファイル専用の名前で持つ。
+ */
+function inertiaHistoryLogoutWithoutNavigation(PendingAwaitablePage $page): void
+{
+    $authenticated = $page->script(<<<'JS'
+        (async () => {
+            const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
+            const token = match ? decodeURIComponent(match[1]) : '';
+            await fetch('/logout', {
+                method: 'POST',
+                credentials: 'same-origin',
+                headers: {
+                    'X-XSRF-TOKEN': token,
+                    'X-Requested-With': 'XMLHttpRequest',
+                    'Accept': 'application/json',
+                },
+            });
+            const status = await fetch('/session/status', {
+                credentials: 'same-origin',
+                cache: 'no-store',
+                headers: { 'Accept': 'application/json' },
+            }).then((response) => response.json());
+            return status.authenticated;
+        })()
+    JS);
+
+    expect($authenticated)->toBeFalse('前提条件失敗: ブラウザ側のログアウトでセッションが無効化されていない');
+}
+
+test('セッションが切れたタブは次の Inertia visit で履歴鍵を失い、戻っても PII が出ない', function (): void {
+    // T089-b: 認証失敗 (AuthenticationException) 契機の Inertia::clearHistory() を
+    // 実ブラウザで一気通貫に固定する。JSON 204 のログアウトで「セッションだけ切れて
+    // 画面遷移していないタブ」を作り、次の Inertia visit で **履歴鍵が旧鍵から入れ替わり、
+    // かつ戻っても過去の PII が描画されない**ことを観測する。
+    // (テストが示せるのはこの 2 つの挙動契約までで、「旧鍵が二度と手に入らない」ことの
+    //  暗号学的証明ではない。文書側もこの範囲を超えた表現をしないこと。)
+    [, $owner] = createOrganizationWithOwner();
+    $this->actingAs($owner);
+
+    $page = visit('/dashboard');
+    $page->assertSee($owner->name);
+
+    // 正のコントロール (1): 認証済み履歴が暗号化されている = 捨てるべき鍵が存在する
+    inertiaHistoryWaitUntil(
+        $page,
+        'window.history.state?.page instanceof ArrayBuffer',
+        'history state が暗号化されていない (EncryptHistory 未適用、または crypto.subtle 不在)',
+    );
+
+    // JS 実行コンテキストの生存マーカー (フルリロードで消える)
+    $page->script("window.__inertiaHistoryProbe = 'alive'; true");
+
+    // 捨てられるべき「認証済み履歴を復号できる鍵」の実値を控える。
+    // ※ null 判定ではなく **値の変化** を見るのが本テストの肝。理由は下の「本丸 (1)」。
+    $keyBefore = $page->script("window.sessionStorage.getItem('historyKey')");
+    expect($keyBefore)->toBeString();
+
+    inertiaHistoryLogoutWithoutNavigation($page);
+
+    // 正のコントロール (2): 204 直後は鍵が **同一のまま** = このタブはまだ何も知らない
+    // (= このあと鍵が入れ替わることに意味がある)
+    expect($page->script("window.sessionStorage.getItem('historyKey')"))
+        ->toBe($keyBefore, '204 ログアウト直後に履歴鍵が既に変わっている (前提が崩れ、以降の観測が空振りする)');
+
+    // Inertia Link (Dashboard.svelte の TextLink「通知を確認」) で Inertia visit を起こす。
+    // 認証が切れているのでサーバは /login へ倒し、その Inertia 応答が clearHistory を消費する。
+    // 文言は Dashboard.svelte 由来 (testId 未付与)。変わったら本テストを追随させること。
+    $page->click('通知を確認');
+    inertiaHistoryWaitUntil(
+        $page,
+        "window.location.pathname === '/login'",
+        'セッション切れの Inertia visit で /login に倒れない',
+    );
+
+    // 本丸 (1): **履歴鍵が旧鍵から入れ替わっている** = 以降の「戻る」で過去エントリを復号できない。
+    //
+    // ここを `historyKey === null` で書いてはいけない。EncryptHistory は guest 面
+    // (/login) にもグローバル適用されるため、Inertia は clearHistory で鍵を消した直後に
+    // **着地ページ用の新しい鍵を即座に採番して sessionStorage へ書き戻す**
+    // (実測: 鍵は常に非 null のまま、値だけが入れ替わる。
+    //  devnotes/20260804-0900-t089-t090-residual-risk/probe-history-key-behavior.md)。
+    // したがって固定すべきは「鍵の欄が空になること」ではなく
+    // 「**現在の履歴鍵が旧鍵から変わっていること**」である。
+    // null も「旧鍵ではない」を満たしてしまうので、**非 null との合わせ技**にして
+    // 「新しい鍵へ入れ替わる」という文書上の主張までを固定する。
+    $keyEscaped = json_encode($keyBefore, JSON_THROW_ON_ERROR);
+    inertiaHistoryWaitUntil(
+        $page,
+        "window.sessionStorage.getItem('historyKey') !== null"
+        ." && window.sessionStorage.getItem('historyKey') !== {$keyEscaped}",
+        '/login 着地後も旧履歴鍵が残っている (clearHistory が消費されていない)',
+    );
+
+    // 「戻る」の前に瞬間露出の監視を仕込む (終状態の assertDontSee では取り逃す)
+    inertiaHistoryWatchForPii($page, $owner->name);
+
+    $page->back();
+
+    inertiaHistoryWaitUntil(
+        $page,
+        "window.location.pathname === '/login'",
+        'セッション切れ後の戻るで /login に倒れない',
+    );
+
+    // 本丸 (2): 復元 → login までの間、PII が **一度も** 描画されていない
+    $page->assertScript('window.__piiSeen', false);
+    // same-document で完結している (= 本当に SPA 履歴復元経路を通った)
+    $page->assertScript('window.__inertiaHistoryProbe', 'alive');
+    $page->assertDontSee($owner->name)->assertNoJavaScriptErrors();
+});
```

## 反映後の検証

- `composer test:browser`: **chromium 11 passed / webkit 11 passed** (各 3 skip。両レーン green)
- `vendor/bin/pint --test` (変更ファイル): passed

Round 2 の 3 件が解消できているか確認し、全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。

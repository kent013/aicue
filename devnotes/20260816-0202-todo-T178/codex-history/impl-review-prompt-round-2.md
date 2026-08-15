# Round 2: Round 1 指摘への対応

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] bootstrap/app.php — `encryptCookies(except:)` を二重に呼んで既存の `ses/*` 除外を上書きしている

- 判断: **反論する**
- 根拠: 事実誤認である。`bootstrap/app.php` に `encryptCookies` の呼び出しは
  **1 箇所しかない** (`grep -n 'encryptCookies' bootstrap/app.php` → 340 行目のみ)。
  `ses/*` が登録されているのは**その直前の `validateCsrfTokens(except:)`** (331 行目) であって
  cookie 暗号化の除外一覧ではない。差分では両者が近接して現れるため取り違えたものと考える。
  `ses/*` を cookie 暗号化の除外へ移すと SES webhook の CSRF 免除が消え、
  cookie 名として意味を成さない値が除外一覧に入る。
- 対応内容: 変更しない。Round 2 で該当行を引用して提示する。
  実測の裏づけとして、平文の世代 cookie を突き合わせる
  `tests/Feature/Auth/SessionEpochCookieTest.php` が緑であること (= 除外が効いている) と、
  SES webhook の Feature テストを含む `composer test` 全体が緑であることを添える。

## [Warning] guest が正しい印を送ると `sessionEpochMatches: true` になるのは設計とずれている

- 判断: **反論する** (設計どおりであり、名前の意味も保つ)
- 根拠: 詳細設計 S3 の controller コードは
  `authenticated: $request->user() !== null` と
  `sessionEpochMatches: SessionEpoch::matches(...)` を**独立に**組み立てており、
  実装はその通りである。設計のテスト計画にある「guest:
  `{authenticated: false, sessionEpochMatches: false}`」は**印を運ばない** guest 要求の
  期待値で、既存テストはその形のまま緑である (ヘッダを付けていない)。
  `sessionEpochMatches` は「**要求が運んだ印がこのセッションの世代と一致するか**」という
  1 つの事実を表す名前であり、ここに認証状態を畳み込むと名前が事実と食い違う
  (2 つの事実を 1 つの真偽値に混ぜることになる)。開示の判定は画面側が
  `authenticated` を先に見て未認証を `/login` へ倒すため、
  `true` になっても開示には一切到達しない (`probeSessionStatus` の分岐順で固定済み)。
  印は APP_KEY 由来なので、guest が正しい印を送れること自体が新しい漏れにもならない。
- 対応内容: 実装は変えない。意図が読み手に伝わるよう、
  「guest が正しい印を運んでも `authenticated` は false のまま」というテストを
  既に置いてある (`tests/Feature/Auth/SessionStatusProbeTest.php`)。

## [Warning] `SessionEpochSharedPropTest` の再生成テストが prop と cookie の同値を見ていない

- 判断: **対応する**
- 根拠: 妥当な指摘である。詳細設計 S2 のテスト計画は「セッション ID が要求中に
  再生成される経路でも prop と cookie が同値」= **遅延評価が効いていることの behavioral な固定**
  を求めているのに、cookie 側しか見ていなかった。即値へ戻しても赤にならない弱いテストだった。
- 対応内容: テストの中だけで「セッションを再生成してから Inertia 応答を返す」route を登録し、
  prop と cookie を直接突き合わせる形へ書き換えた。
  cookie だけを見る旧アサーションは「ログイン応答の世代 cookie は再生成後のセッション ID 由来になる」
  という別テストとして残した (本番経路の確認)。
  **負のコントロールを実測**: `Inertia::always(fn () => …)` を即値へ戻すと当該テストが赤になることを
  確認した (記録は `contract-sync-negative-control.md`)。
  詳細設計へ補正 7 として追記した。

## [Suggestion] `bfcacheContractHasToken()` はハイフン連結の改名を検出できない

- 判断: **対応する**
- 根拠: そのとおりで、`X-Session-Epoch` → `X-Session-Epoch-Renamed` は `-` が識別子文字でないため
  境界照合を素通りする。cookie 名とヘッダ名は画面側で**文字列としてしか書けない**ので、
  引用符ごとの完全一致に寄せれば負のコントロールが強くなる。
- 対応内容: `bfcacheContractHasQuotedLiteral()` を足し、cookie 名とヘッダ名の 2 行を
  二重引用符ごとの完全一致に切り替えた。属性アクセスや型宣言として現れる
  共有 prop のキー・応答キー・状態値・配線の関数名は引用符が付かないため境界照合のままにした。
  改名 2 通りがどちらも赤になることを実測し、記録と設計の補正 3・4 を更新した。


---

## [Critical] への反証: `bootstrap/app.php` の該当箇所 (325〜350 行、現物)

```php
                $shortCircuit,
            );
        }

        // Stripe webhook は署名検証 (Cashier middleware)、SES/SNS webhook は
        // SNS 署名検証 (VerifySnsSignature) で保護されるため CSRF 対象外
        $middleware->validateCsrfTokens(except: [
            'stripe/*',
            'ses/*',
        ]);

        // 世代 cookie だけは画面側 (bfcache guard) が読むため暗号化しない。
        // 中身はセッション ID から鍵付きハッシュで導出した印であってセッション ID ではない。
        // 除外を外すと画面側は復号できない文字列を読み、常に不一致 = 復元のたびに読み直しになる
        // (静かな劣化)。SessionEpochCookieTest が平文値そのもので固定する。
        $middleware->encryptCookies(except: [
            SessionEpoch::COOKIE_NAME,
        ]);

        // bug-hunt (LLM 探索的バグハント) 用コード到達カバレッジ観測器。
        // env(BUGHUNT_PCOV) と function_exists('\pcov\start') の二重 guard を通らない限り
        // 完全 no-op (handle は $next をそのまま返し、terminate は即 return)。pcov 未導入の
        // 本番/CI/dev には一切影響しない。有効化は scripts/bug-hunt-shard.sh provision --coverage 経由。
        $middleware->append(BughuntCoverageMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

```

`grep -n 'encryptCookies\|validateCsrfTokens' bootstrap/app.php` の出力:

```
331:        $middleware->validateCsrfTokens(except: [
340:        $middleware->encryptCookies(except: [
```

`encryptCookies` の呼び出しはファイル全体で **1 箇所だけ**であり、`ses/*` は
CSRF 検証の除外一覧に属します。上書きは起きていません。

裏づけ (実測):
- `tests/Feature/Auth/SessionEpochCookieTest.php` の
  「認証済み応答の世代 cookie が平文の印そのものである」が緑 = 暗号化の除外が効いている。
- SES webhook の Feature テストを含む `composer test` 全体が緑 = CSRF 除外も壊れていない。

この判断が誤りだと考える場合は、`ses/*` が cookie 暗号化の除外一覧に属するという根拠を
現物の行番号で示してください。

---

## 修正差分 (Round 1 の Warning 3 件目と Suggestion への対応)

```diff
diff --git a/devnotes/20260815-2103-bfcache-session-generation-cookie/detailed-design.md b/devnotes/20260815-2103-bfcache-session-generation-cookie/detailed-design.md
index 461a4da..b86f310 100644
--- a/devnotes/20260815-2103-bfcache-session-generation-cookie/detailed-design.md
+++ b/devnotes/20260815-2103-bfcache-session-generation-cookie/detailed-design.md
@@ -973,6 +973,50 @@ ## 申し送り (本設計のスコープ外だが、後段で必ず扱うこと
 - `docs/TODO.md` の T085 の備考に「本件マージ後に実施」の一文を足す作業は、
   後段の TODO 採番登録でまとめて行う (本設計では TODO.md を編集しない)。
 
+## 実装時の設計補正 (T178 実装で判明した差分。本書が正本)
+
+実装 (`devnotes/20260816-0202-todo-T178/`) で本書の記述を次の 6 点に直した。
+
+1. **印の書式に `D` 修飾子を付ける** — `VALUE_PATTERN` は
+   `'/^[0-9a-f]{32}$/D'` である。PHP の `$` は**末尾の改行 1 個を許す**ため、
+   付けないと `…ef\n` が書式として通ってしまい、改行を許さない画面側の JavaScript と
+   判定がずれる。S7 の書式の導出も `trim(VALUE_PATTERN, '/^$D')` にする
+   (導出結果を `[0-9a-f]{32}` と突き合わせてから使うので、外し方が壊れれば赤くなる)。
+2. **S7 の行 3 (共有 prop のキー) のサーバ側は定数参照を見る** —
+   `HandleInertiaRequests` はキーを文字列で書かず `SessionEpoch::SHARED_PROP_KEY` を
+   参照するので、文字列リテラルの実在を要求すると必ず赤になる。サーバ側は
+   **定数を参照していること**を、画面側は**値そのものの実在**を検査する。
+3. **S7 の照合方式を 2 つに分ける** — 素の部分文字列一致だと
+   `session_epoch` → `session_epoch_renamed` の改名で元の語を含んだままになり、
+   検査が赤くならない。
+   - cookie 名とヘッダ名は `-` や `_` を含み識別子の境界が効かないので、
+     **二重引用符ごとの完全一致**で照合する (画面側では文字列としてしか書けない)。
+   - 共有 prop のキー・応答キー・状態値・配線の関数名は、画面側では属性アクセスや
+     型宣言として現れるので**識別子の境界**で照合する。
+4. **S7 の保証範囲を実測に合わせて書き直す** — 負のコントロールの実測は
+   「宣言 1 行だけの改名では 6 通り中 3 通りが赤 / 語をファイルから全消しすれば
+   6 通りとも赤」であった。緑のままになる 3 つは同じ語が docblock・型宣言・許可値の配列に
+   残っているためで、これはファイル単位の実在検査の仕様どおりである。
+   実測の記録は `devnotes/20260816-0202-todo-T178/contract-sync-negative-control.md`。
+5. **救済 route のゲート目録への登録が要る (S1 の変更ファイル一覧に欠けていた)** —
+   新しい middleware は救済 route (退会予約の取消) の経路にも載るため、
+   `App\Enums\Security\RescueRouteGateDisposition` へ分類と根拠を足し、
+   `RescueRouteGateInventoryTest` の母集団件数を 10 → 11 にする
+   (deny-by-default なので登録しないと赤くなる = 無言では壊れない)。
+6. **S5 の終端判定の規則を 1 つに確定する** — 「読み直しに倒れた」の終端候補が
+   `guard-state-changed(state = "reloading")` で立ったときは、その `reloading` も
+   **観測した状態列の一部として数える**。したがって:
+   - `reloading` が状態列の先頭に来る (= 直前に `pending` を観測していない) 場合は
+     `failed-transition`。
+   - `page-hide` の `guardState` だけが `reloading` で状態変化を 1 つも観測できていない
+     場合は、状態列が空なので先頭を問わず `stale-session-reloaded` (取りこぼし時の裏取り)。
+7. **S2 の遅延評価は専用の route で固定する** — 本番の再生成経路 (ログイン / 他デバイス失効) は
+   いずれも redirect を返すので Inertia の props を持たず、prop と cookie の同値を直接
+   突き合わせられない。テストの中だけで「セッションを再生成してから Inertia 応答を返す」
+   route を登録し、prop と cookie が同じ時点のセッション ID から出ていることを確かめる。
+   即値へ戻すと赤くなることは実測済み
+   (`devnotes/20260816-0202-todo-T178/contract-sync-negative-control.md`)。
+
 ## 実装モード
 
 | 項目 | 内容 |
diff --git a/tests/Architecture/BfcacheGuardClientContractSyncTest.php b/tests/Architecture/BfcacheGuardClientContractSyncTest.php
new file mode 100644
index 0000000..be5ca42
--- /dev/null
+++ b/tests/Architecture/BfcacheGuardClientContractSyncTest.php
@@ -0,0 +1,147 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Auth\SessionStatusDto;
+use App\Http\Resources\Auth\SessionStatusResource;
+use App\Support\Auth\SessionEpoch;
+
+/*
+ * bfcache 秘匿・再検証の「言語をまたぐ名前」の契約ずれ検査。
+ *
+ * PHP 側 (App\Support\Auth\SessionEpoch / SessionStatusResource) を正本として、
+ * 画面側のファイルに同じ文字列が実在することを確かめる。cookie 名・ヘッダ名・
+ * 共有 prop のキー・応答キー・印の書式は型検査が届かないため、片側だけ変えると
+ * 静かに壊れる (常に読み直し、または常に不一致) 。
+ *
+ * **保証範囲を誇張しない**: これは**ファイル単位の語の実在検査**であり、
+ * **使われ方が正しいことは保証しない**。同じ語がコメントや型宣言に残っていれば、
+ * 実際に使う箇所だけを別名へ変えても緑のままである (実測: 宣言 1 行だけを改名した
+ * 6 通りのうち赤くなったのは 3 通り。語をファイルから全消しすれば 6 通りとも赤くなる。
+ * 記録は devnotes/20260816-0202-todo-T178/contract-sync-negative-control.md)。
+ * 意味の正しさは vitest (tests/js/lib/bfcache-guard.test.ts の分岐) と Feature テスト
+ * (tests/Feature/Auth/SessionStatusProbeTest.php の応答契約) が担う。
+ */
+
+/**
+ * 監視対象ファイル (リポジトリルート相対)。
+ *
+ * @return array<string, string>
+ */
+function bfcacheContractWatchedFiles(): array
+{
+    return [
+        'guard' => 'resources/js/lib/bfcache-guard.ts',
+        'sharedProps' => 'resources/js/lib/shared-props.ts',
+        'inertiaMiddleware' => 'app/Http/Middleware/HandleInertiaRequests.php',
+        'trial' => 'resources/js/lib/debug/bfcache-trial.ts',
+        'entrypoint' => 'resources/js/app.ts',
+    ];
+}
+
+/**
+ * その語が**識別子として**現れるか。
+ *
+ * 単なる部分文字列一致だと、片側だけを別名へ変えても (`session_epoch` →
+ * `session_epoch_renamed`) 元の語を含んでしまい検査が赤くならない。前後を
+ * 識別子文字でない位置に限ることで、名前の変更が必ず検出される。
+ */
+function bfcacheContractHasToken(string $haystack, string $token): bool
+{
+    $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($token, '/').'(?![A-Za-z0-9_])/u';
+
+    return preg_match($pattern, $haystack) === 1;
+}
+
+/**
+ * その語が**二重引用符で囲まれた文字列そのもの**として現れるか。
+ *
+ * cookie 名とヘッダ名は識別子文字でない `-` や `_` を含むため、識別子の境界だけでは
+ * `X-Session-Epoch` → `X-Session-Epoch-Renamed` のような接尾辞付きの改名を見逃す。
+ * 画面側ではこの 2 つを文字列リテラルとしてしか書けないので、引用符ごと照合する。
+ */
+function bfcacheContractHasQuotedLiteral(string $haystack, string $value): bool
+{
+    return str_contains($haystack, '"'.$value.'"');
+}
+
+function bfcacheContractFileContents(string $key): string
+{
+    $path = base_path(bfcacheContractWatchedFiles()[$key]);
+    $contents = file_get_contents($path);
+
+    expect($contents)->toBeString();
+
+    return (string) $contents;
+}
+
+test('監視対象ファイルがすべて実在する (パス変更で検査が無言で空にならない)', function (): void {
+    foreach (bfcacheContractWatchedFiles() as $key => $relative) {
+        expect(file_exists(base_path($relative)))
+            ->toBeTrue("監視対象 '{$key}' ({$relative}) が存在しない。パスを変えたなら本テストの一覧も直すこと");
+    }
+});
+
+test('世代 cookie 名とヘッダ名が画面側の guard に実在する', function (): void {
+    $guard = bfcacheContractFileContents('guard');
+
+    expect(bfcacheContractHasQuotedLiteral($guard, SessionEpoch::COOKIE_NAME))
+        ->toBeTrue('cookie 名 "'.SessionEpoch::COOKIE_NAME.'" が guard に文字列として無い')
+        ->and(bfcacheContractHasQuotedLiteral($guard, SessionEpoch::HEADER_NAME))
+        ->toBeTrue('ヘッダ名 "'.SessionEpoch::HEADER_NAME.'" が guard に文字列として無い');
+});
+
+test('共有 prop のキーがサーバ側 middleware と画面側の読み取りの両方に実在する', function (): void {
+    // サーバ側は定数を参照する (文字列を書き写さない = ずれる余地を型で消す)。
+    // 画面側は文字列でしか書けないので、こちらは値そのものの実在を見る。
+    expect(bfcacheContractFileContents('inertiaMiddleware'))->toContain('SessionEpoch::SHARED_PROP_KEY')
+        ->and(bfcacheContractHasToken(
+            bfcacheContractFileContents('sharedProps'),
+            SessionEpoch::SHARED_PROP_KEY,
+        ))->toBeTrue('共有 prop のキーが画面側の読み取りに無い');
+});
+
+test('プローブ応答のキーがすべて画面側の guard に実在する', function (): void {
+    // 応答キーは Resource を実際に toArray() して得る (文字列を検査側にも書くと
+    // 正本が 2 か所になる)。キーが増えたら検査対象も自動で増える。
+    $keys = array_keys(SessionStatusResource::make(new SessionStatusDto(
+        authenticated: true,
+        sessionEpochMatches: true,
+    ))->toArray(request()));
+
+    expect($keys)->not->toBeEmpty();
+
+    $guard = bfcacheContractFileContents('guard');
+    foreach ($keys as $key) {
+        expect(bfcacheContractHasToken($guard, (string) $key))
+            ->toBeTrue("応答キー '{$key}' が guard に無い");
+    }
+});
+
+test('印の書式が画面側の 2 ファイルに実在する', function (): void {
+    // PHP の正規表現から区切り・アンカー・修飾子を外して素の書式を得る。
+    // 期待値と突き合わせてから使うので、外し方が壊れれば degenerate PASS にならず赤くなる。
+    $pattern = trim(SessionEpoch::VALUE_PATTERN, '/^$D');
+
+    expect($pattern)->toBe('[0-9a-f]{32}')
+        ->and(bfcacheContractFileContents('guard'))->toContain($pattern)
+        ->and(bfcacheContractFileContents('sharedProps'))->toContain($pattern);
+});
+
+test('ガードの状態値 reloading が検証ページの許可語彙に実在する', function (): void {
+    // 検証ページの状態語彙が追随していないと、実機受入確認 (T085) で記録が拒否される。
+    expect(bfcacheContractHasToken(bfcacheContractFileContents('trial'), 'reloading'))
+        ->toBeTrue('検証ページの許可語彙に reloading が無い');
+});
+
+test('入口スクリプトが描画世代と現世代の読み取りを明示的に配線している', function (): void {
+    // 既定任せ (readRenderedEpoch を渡さない) にすると常に読み直しになる。
+    // 逆に描画世代の既定を cookie にすると同期判定が素通しになるため、
+    // 2 つの出所を呼び出し側で名前付きで見せることを固定する。
+    $entrypoint = bfcacheContractFileContents('entrypoint');
+
+    expect(bfcacheContractHasToken($entrypoint, 'readRenderedEpoch'))
+        ->toBeTrue('入口スクリプトが readRenderedEpoch を渡していない')
+        ->and(bfcacheContractHasToken($entrypoint, 'readCurrentEpoch'))
+        ->toBeTrue('入口スクリプトが readCurrentEpoch を渡していない');
+});
diff --git a/tests/Feature/Auth/SessionEpochSharedPropTest.php b/tests/Feature/Auth/SessionEpochSharedPropTest.php
new file mode 100644
index 0000000..f4b868f
--- /dev/null
+++ b/tests/Feature/Auth/SessionEpochSharedPropTest.php
@@ -0,0 +1,126 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+use App\Support\Auth\SessionEpoch;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Support\Str;
+use Illuminate\Testing\TestResponse;
+use Inertia\Inertia;
+
+/*
+ * 描画世代 (Inertia 共有 prop `sessionEpoch`)。
+ *
+ * 「いま画面に出ている内容がどのセッション世代の応答で来たか」を、内容と同じ 1 通で運ぶ。
+ * 世代 cookie とは**同じ出所から出た同じ値**でなければならない (ずれると
+ * 「内容は A・印は B」の取り違えが起きる)。
+ *
+ * prop は closure で共有する。vendor の Inertia\Middleware は $next の**前**に
+ * share() を呼ぶため、即値にするとセッション ID 再生成 (ログイン等) を拾えず
+ * cookie と食い違う。下の「ログイン応答」のケースがその behavioral な固定である。
+ */
+
+/** Inertia 応答の props から描画世代を取り出す。 */
+function renderedSessionEpoch(TestResponse $response): mixed
+{
+    $page = $response->viewData('page');
+
+    expect($page)->toBeArray();
+
+    /** @var array{props: array<string, mixed>} $page */
+    return $page['props'][SessionEpoch::SHARED_PROP_KEY] ?? null;
+}
+
+/** 応答に載った世代 cookie の値。 */
+function issuedSessionEpochCookie(TestResponse $response): ?string
+{
+    foreach ($response->headers->getCookies() as $cookie) {
+        if ($cookie->getName() === SessionEpoch::COOKIE_NAME) {
+            return $cookie->getValue();
+        }
+    }
+
+    return null;
+}
+
+test('認証済みの Inertia 応答で描画世代と世代 cookie が同値である', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($owner)->get('/dashboard');
+
+    $epoch = renderedSessionEpoch($response);
+
+    expect($epoch)->toBeString()
+        ->and($epoch)->toBe(issuedSessionEpochCookie($response));
+});
+
+test('guest の Inertia 応答にも描画世代が載る', function (): void {
+    $response = $this->get('/login');
+
+    expect(renderedSessionEpoch($response))->toBeString();
+});
+
+test('セッション ID が要求中に再生成される経路でも描画世代と世代 cookie が同値になる', function (): void {
+    // 遅延評価が効いていることの behavioral な固定。共有 prop を即値へ戻すと、
+    // 描画世代は「要求前のセッション ID」で固定される一方、cookie は $next の後に
+    // 導出されるので再生成後の ID になり、両者がずれて赤くなる。
+    //
+    // 本番の再生成経路 (ログイン / 他デバイス失効) はいずれも redirect を返すため
+    // Inertia の props を持たない。そこで**再生成した上で Inertia 応答を返す route**を
+    // このテストの中だけで登録し、prop と cookie を直接突き合わせる。
+    Route::middleware('web')->get('/__test/session-epoch-regenerated', function (Request $request) {
+        $request->session()->regenerate();
+
+        return Inertia::render('Dashboard');
+    });
+
+    $sessionId = Str::random(40);
+
+    $response = $this->withCookie((string) config('session.cookie'), $sessionId)
+        ->get('/__test/session-epoch-regenerated');
+
+    $epoch = renderedSessionEpoch($response);
+
+    expect($epoch)->toBeString()
+        // 再生成後の ID 由来であること (要求前の ID から導出した値ではない)
+        ->and($epoch)->not->toBe(SessionEpoch::forSession($sessionId))
+        // prop と cookie が同じ時点のセッション ID から出ていること
+        ->and($epoch)->toBe(issuedSessionEpochCookie($response));
+});
+
+test('ログイン応答の世代 cookie は再生成後のセッション ID 由来になる', function (): void {
+    $sessionId = Str::random(40);
+    User::factory()->create(['email' => 'epoch-prop@example.com']);
+
+    $login = $this->withCookie((string) config('session.cookie'), $sessionId)->post('/login', [
+        'email' => 'epoch-prop@example.com',
+        'password' => 'password',
+    ]);
+
+    $issued = issuedSessionEpochCookie($login);
+
+    expect($issued)->not->toBeNull()
+        ->and($issued)->not->toBe(SessionEpoch::forSession($sessionId));
+});
+
+test('部分再読み込みで別 prop だけを要求しても描画世代は載る', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($owner)->get('/dashboard');
+    $component = $response->viewData('page')['component'];
+
+    $partial = $this->actingAs($owner)->get('/dashboard', [
+        'X-Inertia' => 'true',
+        'X-Inertia-Version' => (string) $response->viewData('page')['version'],
+        'X-Inertia-Partial-Component' => $component,
+        'X-Inertia-Partial-Data' => 'title',
+    ]);
+
+    $props = $partial->json('props');
+
+    expect($props)->toBeArray()
+        ->and($props)->toHaveKey(SessionEpoch::SHARED_PROP_KEY)
+        ->and($props[SessionEpoch::SHARED_PROP_KEY])->toBeString();
+});

```

## 追加で実測した負のコントロール

| 改変 | 結果 |
|---|---|
| 共有 prop を `Inertia::always(SessionEpoch::current($request))` (即値) へ戻す | `SessionEpochSharedPropTest` の再生成テストが**赤** |
| guard の cookie 名を `session_epoch_renamed` へ | 契約ずれ検査が**赤** |
| guard のヘッダ名を `X-Session-Epoch-Renamed` へ | 契約ずれ検査が**赤** |

## 再実行した検証

- `composer phpstan` : No errors (level 10)
- `vendor/bin/pint --test` : passed
- `vendor/bin/pest --filter="SessionEpoch|SessionStatusProbe|BfcacheGuardClientContractSync|SupportedBrowsersDocFreshness"` : 54 passed
- (この後に `composer test` / `pnpm test` の全数を再実行します)

## 依頼

上記の反論 2 件と修正 2 件を踏まえて再判定してください。
全体判定を **APPROVED / CHANGES_REQUESTED** の 1 行で示してください。

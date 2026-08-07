# impl-review Round 2

Round 1 の指摘 (Critical 1 / Warning 1 / Suggestion 1) にすべて対応しました。
対応マトリクスと修正差分を示します。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] `AuthThrottleCoverageTest` の「inline へ戻す変更を入れたらここが必ず落ちる」が M8 実測と矛盾する

- 判断: **対応する**
- 根拠: 指摘のとおり。mutation M8 (2FA 管理だけを inline へ戻す) で本セクションは緑のままだった。
  巻き添え先が named レーンに居る限り、1 本の差し戻しでは巻き添えが復活しないためである。
  コメントの断定は**その場で反証されている嘘**であり、この codebase が最も嫌う
  「保証範囲の誇張」に当たる。mutation-evidence.md には実態を書いてあるのに
  コード側のコメントが古い主張のままだと、次に読む人はコメントを信じる。
- 対応内容: T125 セクション冒頭のコメントを書き換え、
  (a) 本セクションが固定するのは**巻き添え 429 の消滅**であること、
  (b) 1 本だけ inline へ戻しても緑のままになりうること、
  (c) **inline 差し戻しそのものの検出は目録 gate (`InlineThrottleInventoryTest` の
      「未登録」/ `ThrottleLaneAssignmentTest` の「割当一致」「レーンはすべて 1 本以上」) の担当**
  であることを明記し、「両者はセットで維持すること」を添えた。

## [Warning] `passport.token` の根拠文字列が「$request->user() は常に null」と断定している

- 判断: **対応する**
- 根拠: 同ファイル上部の premise docblock が
  「`StartSession` が無い」は「`$request->user()` が絶対に null」を意味しない、と
  保証範囲を明示的に限定しているのに、根拠文字列だけが強い断定になっていた
  (ファイル内で非対称)。指摘のとおり弱い側が正しい。
- 対応内容: 目録の根拠 2 本 (`passport.token` / `passport.device.code`) を
  「StartSession も認証 middleware も通らない構造のため、**session guard 経由で
  user へ倒れる経路が無い**」という書き方へ弱めた。
  併せて `inlineThrottleCasePremises()` の stateless 側インラインコメントと、
  `InlineThrottleBucketRationale::VendorStatelessIpBucket` の docblock にも
  同じ保証範囲の注記を入れ、3 箇所の表現を揃えた
  (enum 側は app/ にあるため、実装を読む人が最初に当たる場所である)。

## [Suggestion] `livewire.upload-file` の「bucket を専有する」は対象が曖昧

- 判断: **対応する**
- 根拠: 「専有」は guest/IP 側まで含む主張に読める。実際に唯一なのは
  **認証済み actor の inline bucket** についてのみで、未認証時に IP へ倒れる分は
  passport 2 本と同じ性質を持つ。指摘のとおり対象を明示した方が主張が締まる。
- 対応内容: 根拠文字列を「認証済み actor の inline bucket を使う route はこれ 1 本だけ」に改め、
  未認証側については passport 2 本と同性質であることを併記した。

## Round 1 で「問題なし」と判定された箇所

`RateLimiterKeys` / `FortifyServiceProvider` / `AppServiceProvider` / `routes/web.php` /
`config/fortify.php` / `ThrottleLaneAssignmentTest` / `RateLimiterKeyConventionTest` /
`RateLimiterKeysTest` / AGENTS.md・docs — 変更なし。

M8 の整理そのものは「妥当」と判定されたため、mutation-evidence.md の記述は維持する。


## 修正内容

### 1. [Critical] `tests/Feature/Security/AuthThrottleCoverageTest.php` の T125 セクション冒頭コメント

変更前:
```
 | 目録検査 (InlineThrottleInventoryTest / ThrottleLaneAssignmentTest) は
 | 「どう貼られているか」しか見ない。**あるレーンを使い切ったとき別レーンが生きているか**は
 | 実挙動でしか固定できない。inline へ戻す変更を入れたらここが必ず落ちる。
```

変更後:
```
 | 目録検査 (InlineThrottleInventoryTest / ThrottleLaneAssignmentTest) は
 | 「どう貼られているか」しか見ない。**あるレーンを使い切ったとき別レーンが生きているか**は
 | 実挙動でしか固定できない。ここが固定するのはその 1 点である。
 |
 | ★**責務境界を誇張しない** (mutation M8 の実測で確認済み):
 |   本セクションが固定するのは**巻き添え 429 が消えていること**であって、
 |   「inline へ戻したら必ず落ちる」ではない。1 本だけ inline へ戻しても、
 |   巻き添え先が named レーンに居る限りここは緑のままになる
 |   (その route 自身の上限は inline でも保たれるため)。
 |   **inline への差し戻しそのものの検出は目録 gate の担当**である
 |   (InlineThrottleInventoryTest「未登録」/ ThrottleLaneAssignmentTest「割当一致」
 |    「レーンはすべて 1 本以上」)。両者はセットで維持すること。
```

### 2. [Warning] `passport.token` / `passport.device.code` の根拠文字列の断定を弱めた

変更前 (passport.token): `session を持たないため $request->user() は常に null でキーは IP に固定される。`
変更後: `StartSession も認証 middleware も通らない構造のため、session guard 経由で user へ倒れる経路が無くキーは IP になる (premise が機械検査する)。`

変更前 (passport.device.code): `... session を持たず、キーは常に IP。認証済み actor の bucket とは交わらない。`
変更後: `... session を持たず、session guard 経由で user へ倒れる経路が無いため認証済み actor の bucket と交わらない。`

併せて表現を 3 箇所で揃えた:
- `inlineThrottleCasePremises()` の stateless 側インラインコメントに
  「『絶対に user にならない』ではない (上の保証範囲の注記を参照)」を追記
- `app/Enums/Security/InlineThrottleBucketRationale.php` の
  `VendorStatelessIpBucket` の docblock 見出しを
  「session を持たず、キーが**常に** IP へ倒れる vendor route」→
  「session guard も認証 middleware も通らず、キーが IP へ倒れる vendor route」に改め、
  保証範囲の注記 (2 つの構造的経路だけを閉じている / user resolver 差し替えの余地は残る) を追加
  (app/ 側にあり実装を読む人が最初に当たる場所であるため)

### 3. [Suggestion] `livewire.upload-file` の「bucket を専有する」の対象を明示

変更前: `T125 の移行後はこれが inline を使う唯一の認証済み actor route であり bucket を専有する。`
変更後: `T125 の移行後、**認証済み actor の inline bucket を使う route はこれ 1 本だけ**になった (未認証時に IP へ倒れる分は passport 2 本と同じ性質であり、この主張は認証済み側の bucket についてのみ成立する)。`


## 修正差分 (git diff。Round 1 からの変更分のみ)

```diff
diff --git a/app/Enums/Security/InlineThrottleBucketRationale.php b/app/Enums/Security/InlineThrottleBucketRationale.php
new file mode 100644
index 0000000..3e699ae
--- /dev/null
+++ b/app/Enums/Security/InlineThrottleBucketRationale.php
@@ -0,0 +1,64 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * inline throttle (`throttle:{max},{decay}` / パラメータなし) を持つことが
+ * 正しいと裁定された route の分類。
+ *
+ * `tests/Architecture/InlineThrottleInventoryTest.php` が deny-by-default で
+ * 「named limiter へ移すか、本 enum + 具体的根拠付きで目録登録するか」を機械強制する
+ * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
+ *
+ * ★分類は route 単位ではなく **bucket signature の性質**で定義する。
+ *   inline のキーは `ThrottleRequests::resolveRequestSignature()` が決め、
+ *   認証済みなら user id、未認証なら `{domain}|{ip}` になる。
+ *   したがって「その route が inline のときどちらのキーになりうるか」が分類の軸である。
+ *
+ * ★**自前 route 向けの case は 1 つも定義しない** (意図的)。
+ *   各 case は **action class の vendor 名前空間** (`Laravel\Passport\` / `Livewire\`) を
+ *   premise として機械検査するため、`App\...` の自前 controller はどの case にも当てはまらない。
+ *   自前 route に inline を足すと目録に登録できず必ず fail する。
+ *   これが AGENTS.md ドメイン規約 5「レーンを分けたいときは inline ではなく
+ *   named limiter を新設する」の機械化である
+ *   (premise の名前空間リスト自体を書き換えれば当然すり抜けられるが、
+ *    その差分は必ずレビューに現れる = 無言で通ることが無い)。
+ */
+enum InlineThrottleBucketRationale: string
+{
+    /**
+     * session guard も認証 middleware も通らず、キーが IP へ倒れる vendor route。
+     *
+     * ★保証範囲を誇張しない: 下の適用条件が閉じているのは
+     *   **session guard と framework の認証 middleware という 2 つの構造的経路**だけで、
+     *   「`$request->user()` が絶対に null」を意味しない
+     *   (独自 middleware が user resolver を差し替える余地は残る)。
+     *
+     * 適用条件 (すべて機械検査される):
+     *  1. action class が宣言済みの vendor 名前空間由来 (`Laravel\Passport\`)
+     *  2. 実効 middleware 列に `StartSession` が無い
+     *  3. 実効 middleware 列に `AuthenticatesRequests` 実装が無い
+     * かつ (人間の裁定として) vendor が throttle をハードコードしており
+     * 設定でも `RouteThrottleBinder` でも置換できないこと
+     * (置換しようとすると二重付与になり `ThrottleCoverageInventoryTest` が fail する)。
+     */
+    case VendorStatelessIpBucket = 'vendor_stateless_ip_bucket';
+
+    /**
+     * 認証状態によってキーが user id にも IP にもなりうる vendor route。
+     *
+     * 適用条件 (1〜3 は機械検査される):
+     *  1. action class が宣言済みの vendor 名前空間由来 (`Livewire\`)
+     *  2. 実効 middleware 列に `StartSession` が有る
+     *  3. 実効 middleware 列に `AuthenticatesRequests` 実装が無い
+     * かつ (人間の裁定として) vendor の controller middleware / package 設定が
+     * throttle を決めており、上書きに vendor 設定ファイル全体の公開が要ること
+     * (浅い merge により同一セクションの他キーを巻き添えで失う)。
+     * ★**この case の上限は 1**。2 本目が現れたら「認証済み actor の bucket を
+     *   2 本の route が共有する」= 本 TODO が潰した障害の再来なので、
+     *   named limiter 化か vendor 設定の公開かを必ず再検討すること。
+     */
+    case VendorMixedUserOrIpBucket = 'vendor_mixed_user_or_ip_bucket';
+}
diff --git a/tests/Architecture/InlineThrottleInventoryTest.php b/tests/Architecture/InlineThrottleInventoryTest.php
new file mode 100644
index 0000000..8f8cd57
--- /dev/null
+++ b/tests/Architecture/InlineThrottleInventoryTest.php
@@ -0,0 +1,353 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\InlineThrottleBucketRationale;
+use App\Support\Http\RouteThrottleBinder;
+use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+use Illuminate\Session\Middleware\StartSession;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Support\Str;
+
+/*
+ * inline throttle の残置 invariant (deny-by-default)。
+ *
+ * 「inline throttle を持つ route は目録に登録されている」を機械強制する。
+ * 未登録は fail = **自前 route へ inline を足せない** (自前向けの enum case が無いため
+ * 登録もできない)。これは AGENTS.md ドメイン規約 5 の機械化である。
+ *
+ * ★責務境界 (重複検査を作らない):
+ *   - throttle が 1 本あるか            → ThrottleCoverageInventoryTest
+ *   - inline の残置理由と共有上限        → **本テスト**
+ *   - named limiter のキー形式と衝突     → RateLimiterKeyConventionTest
+ *   - 実 HTTP での巻き添え 429 の消滅    → AuthThrottleCoverageTest
+ */
+
+/** inline 指定と判定する params (`{max},{decay}` またはパラメータなし = 既定 60,1)。 */
+function inlineThrottleParamsAreInline(string $params): bool
+{
+    return $params === '' || preg_match('/^\d+,\d+$/', $params) === 1;
+}
+
+/** throttle entry (`{class}` or `{class}:{params}`) が inline 指定か。 */
+function inlineThrottleEntryIsInline(string $entry): bool
+{
+    if (! RouteThrottleBinder::isThrottleEntry($entry)) {
+        return false;
+    }
+
+    return inlineThrottleParamsAreInline(Str::contains($entry, ':') ? Str::after($entry, ':') : '');
+}
+
+/** throttle を 1 本以上持つ route の総数の下限 (走査の空振り検出。実測 48)。 */
+function inlineThrottleThrottledRouteFloor(): int
+{
+    return 40;
+}
+
+/**
+ * case 別の **exact fit** 件数 (`<=` ではなく `===` で照合する)。
+ *
+ * ★上限ではなく「ちょうどこの数」である。`<=` にすると件数が減ったときに
+ *   余った枠が「個別の再検討なしに inline を足せる枠」として残ってしまう。
+ *   増える方向にも減る方向にも、必ずこの数値を変える差分として現れさせる。
+ *
+ * @return array<string, int>
+ */
+function inlineThrottleRationaleExactCountByCase(): array
+{
+    return [
+        InlineThrottleBucketRationale::VendorStatelessIpBucket->value => 2,
+        // ★1 から動かさない。2 本目 = 認証済み actor の bucket 共有の再来。
+        InlineThrottleBucketRationale::VendorMixedUserOrIpBucket->value => 1,
+    ];
+}
+
+/**
+ * case ごとに許す **action の由来** (vendor provenance)。
+ *
+ * ★「vendor だから inline を許す」という主張を機械化する。
+ *   middleware 構成だけを見ていると、`StartSession` あり `Authenticate` なしの
+ *   **自前 web route** が `VendorMixedUserOrIpBucket` として登録できてしまう。
+ *   action class の名前空間を case ごとに固定することで、
+ *   `App\...` の自前 controller はどの case にも当てはまらなくなる。
+ *
+ * ★この配列自体を書き換えれば当然すり抜けられる (`App\` を足す等)。
+ *   それは目録型 gate の一般的な性質であり、**その差分がレビューに現れること**が
+ *   本 gate の目的である (無言で通ることが無いこと)。
+ *
+ * @return array<string, list<string>> case => 許す action の名前空間接頭辞
+ */
+function inlineThrottleCaseVendorNamespaces(): array
+{
+    return [
+        InlineThrottleBucketRationale::VendorStatelessIpBucket->value => ['Laravel\\Passport\\'],
+        InlineThrottleBucketRationale::VendorMixedUserOrIpBucket->value => ['Livewire\\'],
+    ];
+}
+
+/**
+ * case ごとの適用条件を実効 middleware 列 + action の由来で機械化するための述語。
+ *
+ * ★分類を「作文」で終わらせないための premise 検査。vendor の更新で
+ *   session の有無や controller の名前空間が変われば、根拠の文章より先にここが落ちる。
+ *
+ * ★**保証範囲を誇張しない**: 「`StartSession` が無い」は
+ *   「`$request->user()` が絶対に null」を意味しない (独自の認証 middleware が
+ *   user resolver を差し替える余地は残る)。ここで閉じているのは
+ *   **session guard と framework の認証 middleware という 2 つの構造的な経路**だけである。
+ *
+ * @return array<string, callable(RoutingRoute): bool>
+ */
+function inlineThrottleCasePremises(): array
+{
+    $hasClass = static function (RoutingRoute $route, string $class): bool {
+        /** @var Router $router */
+        $router = Route::getFacadeRoot();
+        foreach ($router->gatherRouteMiddleware($route) as $entry) {
+            if (is_string($entry) && is_a(Str::before($entry, ':'), $class, true)) {
+                return true;
+            }
+        }
+
+        return false;
+    };
+
+    $fromVendor = static function (RoutingRoute $route, string $case): bool {
+        $action = Str::before($route->getActionName(), '@');
+        foreach (inlineThrottleCaseVendorNamespaces()[$case] ?? [] as $prefix) {
+            if (str_starts_with($action, $prefix)) {
+                return true;
+            }
+        }
+
+        return false; // Closure action もここで false (由来を証明できない)
+    };
+
+    $stateless = InlineThrottleBucketRationale::VendorStatelessIpBucket->value;
+    $mixed = InlineThrottleBucketRationale::VendorMixedUserOrIpBucket->value;
+
+    return [
+        // stateless = session guard も framework の認証 middleware も通らない
+        //           → この 2 経路では user へ倒れない (= キーは IP になる)。
+        //             「絶対に user にならない」ではない (上の保証範囲の注記を参照)
+        $stateless => static fn (RoutingRoute $route): bool => $fromVendor($route, $stateless)
+            && ! $hasClass($route, StartSession::class)
+            && ! $hasClass($route, AuthenticatesRequests::class),
+        // mixed = session はあるが auth 必須ではない → user id にも IP にもなる
+        $mixed => static fn (RoutingRoute $route): bool => $fromVendor($route, $mixed)
+            && $hasClass($route, StartSession::class)
+            && ! $hasClass($route, AuthenticatesRequests::class),
+    ];
+}
+
+/** 根拠文字列の最低文字数。 */
+function inlineThrottleReasonMinLength(): int
+{
+    return 30;
+}
+
+/**
+ * inline throttle を持つことが正しいと裁定した route の目録。
+ *
+ * @return array<string, array{InlineThrottleBucketRationale, string}>
+ */
+function inlineThrottleInventory(): array
+{
+    $statelessIp = InlineThrottleBucketRationale::VendorStatelessIpBucket;
+    $mixed = InlineThrottleBucketRationale::VendorMixedUserOrIpBucket;
+
+    return [
+        'passport.token' => [$statelessIp,
+            'Laravel\Passport\RouteRegistrar::forAccessTokens() が middleware([\'throttle\']) を'
+            .'ハードコードしており、設定でも RouteThrottleBinder でも置換できない'
+            .'(後付けすると二重付与になり ThrottleCoverageInventoryTest が fail する)。'
+            .'StartSession も認証 middleware も通らない構造のため、session guard 経由で'
+            .'user へ倒れる経路が無くキーは IP になる (premise が機械検査する)。'],
+
+        'passport.device.code' => [$statelessIp,
+            '上記 passport.token と同じく Passport がハードコードした throttle (既定 60/min)。'
+            .'device authorization grant の code 発行 endpoint で session を持たず、'
+            .'session guard 経由で user へ倒れる経路が無いため認証済み actor の bucket と交わらない。'],
+
+        'livewire.upload-file' => [$mixed,
+            'Livewire\Features\SupportFileUploads\FileUploadController::middleware() が'
+            .'config(\'livewire.temporary_file_upload.middleware\') ?: \'throttle:60,1\' を返す。'
+            .'上書きには config/livewire.php の公開が要るが mergeConfigFrom は浅い merge のため'
+            .'部分定義では temporary_file_upload 配下の disk/rules/cleanup を巻き添えで失う。'
+            .'T125 の移行後、**認証済み actor の inline bucket を使う route はこれ 1 本だけ**に'
+            .'なった (未認証時に IP へ倒れる分は passport 2 本と同じ性質であり、この主張は'
+            .'認証済み側の bucket についてのみ成立する)。'],
+    ];
+}
+
+/** route の目録キー (名前があれば名前、無ければ `{METHOD} /{uri}`)。 */
+function inlineThrottleRouteLabel(RoutingRoute $route): string
+{
+    $name = $route->getName();
+    if ($name !== null && $name !== '') {
+        return $name;
+    }
+
+    return implode('|', array_values(array_diff($route->methods(), ['HEAD']))).' /'.$route->uri();
+}
+
+/** @return array{inline: list<string>, throttled: int} 母集団の走査結果。 */
+function inlineThrottleScan(): array
+{
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+    $inline = [];
+    $throttled = 0;
+
+    foreach (Route::getRoutes() as $route) {
+        $entries = RouteThrottleBinder::throttleEntries($router, $route);
+        if ($entries === []) {
+            continue;
+        }
+        $throttled++;
+
+        foreach ($entries as $entry) {
+            if (inlineThrottleEntryIsInline($entry)) {
+                $inline[] = inlineThrottleRouteLabel($route);
+
+                break;
+            }
+        }
+    }
+
+    sort($inline);
+
+    return ['inline' => $inline, 'throttled' => $throttled];
+}
+
+test('分類器は inline 指定と named 指定を取り違えない (負のコントロール)', function (): void {
+    $throttle = 'Illuminate\Routing\Middleware\ThrottleRequests';
+
+    // inline 側
+    expect(inlineThrottleEntryIsInline($throttle.':6,1'))->toBeTrue();
+    expect(inlineThrottleEntryIsInline($throttle.':60,1'))->toBeTrue();
+    expect(inlineThrottleEntryIsInline($throttle))->toBeTrue('パラメータなし throttle は既定 60,1 の inline');
+    expect(inlineThrottleEntryIsInline('Illuminate\Routing\Middleware\ThrottleRequestsWithRedis:10,1'))
+        ->toBeTrue('redis 実装も ThrottleRequests の派生であり inline 判定の対象');
+
+    // named 側
+    expect(inlineThrottleEntryIsInline($throttle.':password-verify'))->toBeFalse();
+    expect(inlineThrottleEntryIsInline($throttle.':api-read'))->toBeFalse();
+
+    // throttle ですらない middleware
+    expect(inlineThrottleEntryIsInline('Illuminate\Auth\Middleware\Authenticate:web'))->toBeFalse();
+});
+
+test('throttle を持つ route の総数が下限を下回らない (走査の空振り検出)', function (): void {
+    $scan = inlineThrottleScan();
+
+    expect($scan['throttled'])->toBeGreaterThanOrEqual(
+        inlineThrottleThrottledRouteFloor(),
+        "throttle を持つ route が {$scan['throttled']} 件しか検出されませんでした。"
+        .'middleware 解決が壊れている可能性があります (この場合 inline 母集団も 0 件になり、'
+        .'目録検査が空振りで green になってしまう)。',
+    );
+});
+
+test('inline throttle を持つ route は目録に登録されている (未知は fail)', function (): void {
+    $inventory = inlineThrottleInventory();
+    $unknown = array_values(array_diff(inlineThrottleScan()['inline'], array_keys($inventory)));
+
+    expect($unknown)->toBe([],
+        'inline throttle (`throttle:{max},{decay}`) を持つ route が目録に未登録です。'
+        .'inline のキーは actor id だけで route 名も limiter 名も入らないため、'
+        .'**同一 actor の全 inline route が 1 bucket を共有します**。'
+        .'named limiter を新設してレーンを分けてください'
+        .'(自前 route 向けの InlineThrottleBucketRationale case は意図的に存在しません)。'
+        .PHP_EOL.implode(PHP_EOL, $unknown));
+});
+
+test('目録の key は現存する inline throttle route (stale 検出 / 母集団 0 件の検出)', function (): void {
+    $inline = inlineThrottleScan()['inline'];
+    $stale = array_values(array_diff(array_keys(inlineThrottleInventory()), $inline));
+
+    expect($stale)->toBe([],
+        '目録にあるが inline throttle を持たない route があります (named 化済み・削除済み、'
+        .'または母集団の走査が壊れている)。named 化したら目録から消してください。'
+        .PHP_EOL.implode(PHP_EOL, $stale));
+});
+
+test('目録の値は enum + 実質的な根拠文字列', function (): void {
+    $min = inlineThrottleReasonMinLength();
+    $violations = [];
+
+    foreach (inlineThrottleInventory() as $label => [$rationale, $reason]) {
+        if (! $rationale instanceof InlineThrottleBucketRationale) {
+            $violations[] = "{$label}: 第 1 要素が InlineThrottleBucketRationale ではありません";
+        }
+        if (mb_strlen($reason) < $min) {
+            $violations[] = "{$label}: 根拠が {$min} 文字未満です";
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('case 別件数が宣言値とちょうど一致する (enum 全 case を走査。未登録も fail)', function (): void {
+    $expected = inlineThrottleRationaleExactCountByCase();
+
+    $counts = [];
+    foreach (InlineThrottleBucketRationale::cases() as $case) {
+        $counts[$case->value] = 0;
+    }
+    foreach (inlineThrottleInventory() as [$rationale, $reason]) {
+        $counts[$rationale->value]++;
+    }
+
+    $violations = [];
+    foreach ($counts as $case => $count) {
+        if (! array_key_exists($case, $expected)) {
+            $violations[] = "{$case}: inlineThrottleRationaleExactCountByCase() に件数がありません";
+
+            continue;
+        }
+        // ★`>` ではなく `!==`。減った方向も差分として現れさせる (余った枠を残さない)。
+        if ($count !== $expected[$case]) {
+            $violations[] = "{$case}: {$count} 件 (宣言 {$expected[$case]} 件)";
+        }
+    }
+    foreach (array_keys($expected) as $case) {
+        if (! array_key_exists($case, $counts)) {
+            $violations[] = "{$case}: enum に存在しない case の件数宣言が残っています";
+        }
+    }
+
+    expect($violations)->toBe([],
+        '件数を増やす前に、その route を named limiter へ移せないかを必ず再検討すること。'
+        .'減った場合は宣言値を下げること (枠を残さない)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('分類 case の適用条件が実効 middleware 列と一致する (premise の固定)', function (): void {
+    // ★根拠の文章ではなく**実効 middleware 列**で分類の前提を固定する。
+    //   vendor の更新で passport が session を張るようになれば、ここが先に落ちる。
+    $premises = inlineThrottleCasePremises();
+    $inventory = inlineThrottleInventory();
+    $violations = [];
+
+    foreach (Route::getRoutes() as $route) {
+        $label = inlineThrottleRouteLabel($route);
+        if (! array_key_exists($label, $inventory)) {
+            continue;
+        }
+        $case = $inventory[$label][0]->value;
+        if (! array_key_exists($case, $premises)) {
+            $violations[] = "{$case}: premise が定義されていません";
+
+            continue;
+        }
+        if (! $premises[$case]($route)) {
+            $violations[] = "{$label}: {$case} の適用条件 (session / auth の有無) を満たしていません";
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
diff --git a/tests/Feature/Security/AuthThrottleCoverageTest.php b/tests/Feature/Security/AuthThrottleCoverageTest.php
index 21349d0..15e0af0 100644
--- a/tests/Feature/Security/AuthThrottleCoverageTest.php
+++ b/tests/Feature/Security/AuthThrottleCoverageTest.php
@@ -5,9 +5,11 @@
 use App\Http\Middleware\RequireRecentAuth;
 use App\Http\Middleware\VerifySnsSignature;
 use App\Models\User;
+use Illuminate\Auth\Middleware\Authenticate;
 use Illuminate\Routing\Middleware\ThrottleRequests;
 use Illuminate\Routing\Router;
 use Illuminate\Support\Facades\Http;
+use Illuminate\Support\Facades\Notification;
 use Illuminate\Support\Facades\Route;
 use Illuminate\Testing\TestResponse;
 use Laravel\Socialite\Facades\Socialite;
@@ -372,3 +374,199 @@ function throttleProbeResolvedClasses(string $routeName): array
         $previous = (int) $remaining;
     }
 });
+
+/*
+ |--------------------------------------------------------------------------
+ | T125: inline throttle から移した 6 レーンの独立性 (behavioral proof)
+ |--------------------------------------------------------------------------
+ |
+ | 目録検査 (InlineThrottleInventoryTest / ThrottleLaneAssignmentTest) は
+ | 「どう貼られているか」しか見ない。**あるレーンを使い切ったとき別レーンが生きているか**は
+ | 実挙動でしか固定できない。ここが固定するのはその 1 点である。
+ |
+ | ★**責務境界を誇張しない** (mutation M8 の実測で確認済み):
+ |   本セクションが固定するのは**巻き添え 429 が消えていること**であって、
+ |   「inline へ戻したら必ず落ちる」ではない。1 本だけ inline へ戻しても、
+ |   巻き添え先が named レーンに居る限りここは緑のままになる
+ |   (その route 自身の上限は inline でも保たれるため)。
+ |   **inline への差し戻しそのものの検出は目録 gate の担当**である
+ |   (InlineThrottleInventoryTest「未登録」/ ThrottleLaneAssignmentTest「割当一致」
+ |    「レーンはすべて 1 本以上」)。両者はセットで維持すること。
+ |
+ | cache store はテスト実行時 array に強制されている (phpunit.xml) ため、
+ | app を作り直す各テストで RateLimiter のバケットは空から始まる。
+ |
+ | ★**「429 でないこと」だけを見ない** (false green の防止)。
+ |   前段 middleware の短絡や throttle の付け外しでも「429 でない」は成立するため、
+ |   独立性を主張する probe では必ず `X-RateLimit-Remaining` の存在も確認し、
+ |   「throttle が実際に走ったうえで通った」ことを示す。
+ */
+
+/**
+ * 429 ではなく、かつ throttle が実際に走った (残数ヘッダがある) ことを検査する。
+ *
+ * ★命名は同ファイル既存の `throttleProbe*` に合わせる (Pest のグローバル関数汚染を抑える)。
+ * ★**このファイル内でのみ使う**。他のテストファイルから参照すると、
+ *   ファイル単独実行 / `--filter` 絞り込みでロード順に依存して未定義になりうる。
+ *   利用箇所が少ないうちは各ファイルへ直接書く (`tests/Support` のクラス化はしない)。
+ */
+function throttleProbeExpectNotThrottled(TestResponse $response, string $message): void
+{
+    expect($response->headers->get('X-RateLimit-Remaining'))->not->toBeNull(
+        $message.' (X-RateLimit-* が無い = throttle が走っていない。false green の疑い)',
+    );
+    expect($response->getStatusCode())->not->toBe(429, $message);
+}
+
+test('Livewire アップロードのレーンは再認証を巻き添えにしない (max 60 が max 6 を殺さない)', function (): void {
+    // ★本 TODO の中心的な回帰。inline のままだと livewire.upload-file (max 60) の
+    //   6 回目で共有カウンタが 6 に達し、recent-auth.password (max 6) が 429 になる。
+    $user = User::factory()->create();
+    $this->actingAs($user);
+
+    // ★消費元の空振り防止。他のレーンは「N+1 回目が 429」で消費を証明できるが、
+    //   Livewire だけは上限 60 のためループ内で 429 に到達せず、
+    //   「署名検査や middleware 順の変更で 1 枠も消費しなくなった」状態でも
+    //   probe 側が緑になってしまう。**残数が 1 ずつ減っていること**まで固定する。
+    $remainings = [];
+    for ($i = 1; $i <= 6; $i++) {
+        // 署名なしのため 401 で弾かれるが、throttle は controller より前で数える
+        $response = $this->post(route('livewire.upload-file'));
+        $remaining = $response->headers->get('X-RateLimit-Remaining');
+        expect($remaining)->not->toBeNull("{$i} 回目に X-RateLimit-* がありません (throttle が走っていない)");
+        expect($response->getStatusCode())->not->toBe(429, "{$i} 回目で既に 429 になりました");
+        $remainings[] = (int) $remaining;
+    }
+    expect($remainings[5])->toBe($remainings[0] - 5,
+        'Livewire アップロードが bucket を消費していません (消費していないなら独立性の主張が空振りする)');
+
+    throttleProbeExpectNotThrottled(
+        $this->post('/recent-auth/password', ['password' => 'wrong-password']),
+        '再認証がファイルアップロードの巻き添えで 429 になりました',
+    );
+});
+
+test('2FA 管理レーンを使い切っても再認証・パスワード設定・メール検証は 429 にならない', function (): void {
+    Notification::fake();
+    $user = User::factory()->create();
+    $this->actingAs($user);
+
+    for ($i = 1; $i <= 10; $i++) {
+        expect($this->post('/user/two-factor-authentication')->getStatusCode())
+            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
+    }
+    expect($this->post('/user/two-factor-authentication')->getStatusCode())
+        ->toBe(429, '2FA 管理レーンの上限 10/min が維持されていません');
+
+    throttleProbeExpectNotThrottled(
+        $this->post('/recent-auth/password', ['password' => 'wrong-password']),
+        '再認証が 2FA 管理の巻き添えで 429 になりました',
+    );
+    throttleProbeExpectNotThrottled(
+        $this->post('/settings/password', ['password' => 'short']),
+        'パスワード初回設定が 2FA 管理の巻き添えで 429 になりました',
+    );
+    throttleProbeExpectNotThrottled(
+        $this->post('/email/verification-notification'),
+        '認証メール再送が 2FA 管理の巻き添えで 429 になりました',
+    );
+});
+
+test('パスワード照合レーンを使い切っても初回設定・2FA 管理・メール検証は 429 にならない', function (): void {
+    Notification::fake();
+    $user = User::factory()->create();
+    $this->actingAs($user);
+
+    for ($i = 1; $i <= 6; $i++) {
+        expect($this->post('/recent-auth/password', ['password' => 'wrong-password'])->getStatusCode())
+            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
+    }
+    expect($this->post('/recent-auth/password', ['password' => 'wrong-password'])->getStatusCode())
+        ->toBe(429, 'パスワード照合レーンの上限 6/min が維持されていません');
+
+    // ★照合と初回設定を分けた根拠そのもの (同レーンだとここが 429 になる)
+    throttleProbeExpectNotThrottled(
+        $this->post('/settings/password', ['password' => 'short']),
+        'パスワード初回設定が照合レーンの巻き添えで 429 になりました',
+    );
+    throttleProbeExpectNotThrottled(
+        $this->post('/user/two-factor-authentication'),
+        '2FA 管理が照合レーンの巻き添えで 429 になりました',
+    );
+    throttleProbeExpectNotThrottled(
+        $this->post('/email/verification-notification'),
+        '認証メール再送が照合レーンの巻き添えで 429 になりました',
+    );
+});
+
+test('パスワード照合面 3 本は 1 つのレーンを共有する (1 つの秘密の試行予算)', function (): void {
+    // ★分けてはいけない結合の固定。3 面が別 bucket になると同じパスワードを 18 回/min
+    //   試せることになり、総当り耐性が現状より下がる。
+    $user = User::factory()->create();
+    $this->actingAs($user);
+
+    $probes = [
+        fn () => $this->post('/recent-auth/password', ['password' => 'wrong-password']),
+        fn () => $this->post('/user/confirm-password', ['password' => 'wrong-password']),
+        fn () => $this->put('/user/password', ['current_password' => 'wrong', 'password' => 'NewPassw0rd!', 'password_confirmation' => 'NewPassw0rd!']),
+    ];
+
+    $previous = null;
+    foreach ($probes as $probe) {
+        $remaining = $probe()->headers->get('X-RateLimit-Remaining');
+        expect($remaining)->not->toBeNull('throttle が付いていません');
+        if ($previous !== null) {
+            expect((int) $remaining)->toBe($previous - 1, 'パスワード照合面が別 bucket へ分かれています');
+        }
+        $previous = (int) $remaining;
+    }
+});
+
+test('メール検証レーンは 6/min で、使い切っても再認証は 429 にならない', function (): void {
+    Notification::fake();
+    $user = User::factory()->unverified()->create();
+    $this->actingAs($user);
+
+    for ($i = 1; $i <= 6; $i++) {
+        expect($this->post('/email/verification-notification')->getStatusCode())
+            ->not->toBe(429, "{$i} 回目で既に 429 になりました");
+    }
+    expect($this->post('/email/verification-notification')->getStatusCode())->toBe(429);
+
+    throttleProbeExpectNotThrottled(
+        $this->post('/recent-auth/password', ['password' => 'wrong-password']),
+        '再認証がメール再送の巻き添えで 429 になりました',
+    );
+});
+
+test('招待受諾 POST は 10/min で、確認画面 GET とは別 bucket である', function (): void {
+    // GET 側 invitation-accept は未認証 IP レーン (10/min)。同一 bucket だと
+    // 「リンクを開き直したら受諾できない」という詰みになる。
+    $user = User::factory()->create();
+    $this->actingAs($user);
+
+    for ($i = 1; $i <= 10; $i++) {
+        expect($this->get('/invitations/accept?token=invalid-token')->getStatusCode())
+            ->not->toBe(429, "GET {$i} 回目で既に 429 になりました");
+    }
+    expect($this->get('/invitations/accept?token=invalid-token')->getStatusCode())->toBe(429);
+
+    throttleProbeExpectNotThrottled(
+        $this->post('/invitations/accept', ['token' => 'invalid-token']),
+        '受諾 POST が確認画面 GET の巻き添えで 429 になりました',
+    );
+});
+
+test('認証は throttle より先に走る (レーンの guest 分岐が防御的冗長であることの前提固定)', function (): void {
+    // ★limiter の IP 分岐は「auth を持たない route でも同じ helper が使える」ための冗長であり、
+    //   auth 必須 route では通らない。この前提が変わったら (priority list を触ったら)
+    //   ここが落ちて、IP 分岐が実運用に載ることに気づける。
+    $resolved = throttleProbeResolvedClasses('recent-auth.password');
+
+    $authIndex = array_search(Authenticate::class, $resolved, true);
+    $throttleIndex = array_search(ThrottleRequests::class, $resolved, true);
+
+    expect($authIndex)->not->toBeFalse('Authenticate が実効列に無い');
+    expect($throttleIndex)->not->toBeFalse('ThrottleRequests が実効列に無い');
+    expect($authIndex)->toBeLessThan($throttleIndex);
+});

```

> 注: 上の diff は 3 ファイルの HEAD からの全差分です
> (`InlineThrottleInventoryTest.php` と enum は本 TODO の新規ファイルのため全文が + で出ます)。
> Round 1 から実際に変わったのは上の「修正内容」に列挙した箇所だけです。

## 再検証の結果

```
composer phpstan                                      -> [OK] No errors
vendor/bin/pint --test                                -> passed
composer test -- tests/Architecture/InlineThrottleInventoryTest.php
                                                      -> tests=7 passed=7 failed=0
composer test -- tests/Feature/Security/AuthThrottleCoverageTest.php
                                                      -> (実行中。結果は本文送信時点で確認済みのものを Round 3 で報告)
```

いずれも**コメント・根拠文字列の文言のみ**の変更であり、検査ロジック・閾値・route 指定・
limiter 登録には一切触れていません (mutation の結論も変わりません)。

## 依頼

上記対応で Round 1 の指摘が解消しているか判定し、
**全体判定 (APPROVED / CHANGES_REQUESTED)** を明記してください。

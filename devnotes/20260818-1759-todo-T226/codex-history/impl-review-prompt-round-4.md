Round 3 の指摘への対応が終わったので再レビューを依頼する。

# 対応マトリクス: impl-review Round 3

## [Warning] trait 内の `self::` を trait 自身の FQCN へ解決すると PHP の意味論と一致しない
- 判断: 対応する
- 根拠: 指摘のとおり。trait のコードは取り込んだクラスへ展開されるので `self` は trait 自身ではなく
  利用クラスを指し、同じ trait を複数のクラスが取り込めるため走査時点では 1 つに決まらない。
  trait 自身として Resolved にすると、利用側は未解決として拾えず**無言の見逃し**になる。
- 対応内容: scope の記録に「宣言が trait だったか」を持たせ、trait 本体では `self::` の
  受け手を `ReceiverName::unresolved()` にした (`ScanScopeKind` は公開している shape なので変えず、
  走査器の内部表現だけを増やした)。docblock も併せて直した。
  テストは 2 本 —
  `PhpReferenceScannerTest` の「trait 本体の self:: は未解決にする」(同じ見本の中に
  通常クラスの `self::` を並べ、片方だけが Resolved になることを固定) と、
  `ExternalClientBoundaryScannerTest` の「trait 本体の self:: 経由の大域 setter も
  fail-closed で検出する」(利用側まで伝播することの裏取り)。

## [Suggestion] `new` の直後の例外 (`self` / `static` / `parent`) を記述へ併記する
- 判断: 対応する
- 対応内容: `PhpReferenceScanner` の docblock と `docs/architecture.md` の両方へ、
  `self` / `static` / `parent` を短縮クラス名として解決しないこと、
  `static` / `parent` と trait 本体の `self` は未解決として返すことを書いた。

## 完了報告への注記
- `composer test` の全数 green を確認してから完了とする (Round 3 の指摘どおり)。


## 修正後の全差分 (git diff。全体)

```diff
diff --git a/devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md b/devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md
index b9688c1d..52088726 100644
--- a/devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md
+++ b/devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md
@@ -53,7 +53,9 @@ ## D1: 部分修飾名を解決しないまま通す (`PhpReferenceScanner`) —
 そこで本 TODO では **docblock の文面だけ**を
 「規約 **(a)・(b)** を満たしていない既知の穴であり、是正は別 TODO」と読める形へ直す (概念設計 施策 2)。
 
-- **追跡先 TODO ID**: _(未採番。監督者が起票・採番し、実装者がここへ追記する。本 TODO の完了条件の 1 つ)_
+- **追跡先 TODO ID**: T226 (部分修飾名を完全修飾名まで解決し、受け手の解決状態を判別可能にした。
+  そのうえで**外部到達点の目録 2 系統と prompt 窓口**では未解決を拾う側へ倒した。
+  残る限界は `PhpReferenceScanner` の docblock「保証しないもの」が正本)
 - **是正するときの設計条件** (Codex Round 1 観点 7): 未解決を通常の完全修飾名文字列へ**混ぜない**。
   判別できる値 (専用の種別を持つ site / 専用の戻り値) か明示的な例外で表す。
   `string|null` へ潰すと PHPStan level 10 と fail-closed の意図の両方を損ねる。
@@ -149,7 +151,7 @@ ## 別 TODO として起票を申し送るもの
 
 | # | 内容 | 根拠 | 追跡先 TODO ID |
 |---|---|---|---|
-| 1 | `PhpReferenceScanner` の部分修飾名を落とす形へ寄せる (波及 6 gate + 2 検出器)。未解決は判別できる値か例外で表し、完全修飾名の文字列へ混ぜない | D1 | _(未採番)_ |
+| 1 | `PhpReferenceScanner` の部分修飾名を落とす形へ寄せる (波及 6 gate + 2 検出器)。未解決は判別できる値か例外で表し、完全修飾名の文字列へ混ぜない | D1 | T226 (実施済み) |
 | 2 | 空振り検査を持たない走査 gate 12 本の分類と付与 | D2 | _(未採番)_ |
 
 いずれも本 TODO のスコープ外である (`conceptual-design.md` スコープ外節)。
diff --git a/docs/architecture.md b/docs/architecture.md
index 58400c30..99d803ba 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2116,11 +2116,17 @@ ### 保証しないもの (誇張しない。**本節が正本**)
 8. **`.env.bughunt.local` (git 管理外) の内容**。pin できるのは `.env.bughunt.local.example` まで
 9. **決済の別 API 表面**。検出は「client の取得・構築」に限り、新しい静的 helper が増えたときは
    規則の追加が要る
-10. **部分修飾名の解決**。`T_NAME_QUALIFIED` (`Facades\Http::get()` のような書き方) は
-    現在の namespace への相対解決も先頭 segment の alias 解決も行わない
-    (`ExternalClientBoundaryScanner` と同じ限界)。この限界は
-    `tests/Unit/Architecture/ExternalSeamScannerTest.php` が**テストとして明示的に固定**しており、
-    将来直すときは必ず差分が出る
+10. **import の無い短縮名を型宣言 / `::class` / `instanceof` の位置に書いた参照**。
+    これらの文脈の判定を走査器が実装していないため走査しない (PHP の構文上の限界ではなく
+    実装上の限界。`T_STRING` は定数名や関数名にも使われるので、文脈を見ずに一律走査すると
+    母集団が意味を失う)。**ファイル自身の名前空間の下に対象クラスが居る場合はここで見逃す**。
+    `new` の直後と静的呼び出しの受け手は解決する。ただし `self` / `static` / `parent` は
+    短縮クラス名として解決せず、`static` / `parent` と **trait 本体の `self`** は
+    未解決として返す (`PhpReferenceScanner` の docblock が正本)。
+    なお**部分修飾名** (`Facades\Http::get()`) と**受け手が未解決の静的呼び出し**
+    (`$gateway::stripe()`) は T226 で解決 / fail-closed 化済みで、
+    `tests/Unit/Architecture/PhpReferenceScannerTest.php` と
+    `tests/Unit/Architecture/ExternalSeamScannerTest.php` が両方向を固定している
 ## 冪等キーの claim と保持期間 (REST API v1 / MCP)
 
 REST API v1 の `Idempotency-Key` は **本処理の前に claim する**方式で、契約の正本は
diff --git a/tests/Architecture/AccountDeletionPathGateTest.php b/tests/Architecture/AccountDeletionPathGateTest.php
index 27ea79e6..d041d242 100644
--- a/tests/Architecture/AccountDeletionPathGateTest.php
+++ b/tests/Architecture/AccountDeletionPathGateTest.php
@@ -55,8 +55,14 @@
  *     `AccountController extends Controller` から app/ の全 Controller が入り信号が死ぬため、
  *     意図的に `implements` だけに限定している)
  *   - **`use Billable;` のような trait 取り込み**そのもの。PhpReferenceScanner はクラス本体の
- *     `use` を import として扱い site を出さないため、trait 名は記号照合に載らない
+ *     `use` を site にしないため、trait 名は記号照合に載らない
  *     (帰結として Cashier の**構造的な取り込み**は検出せず、**呼び出し**だけを見る)
+ *   - **受け手を静的に決められない静的呼び出しからの到達辺** (`$class::run()` / `static::run()` /
+ *     `parent::run()`)。名前が無いので辿る先を作れず、**閉包はここで途切れる**。
+ *     ただし**決済記号の照合は落ちない** — 受け手を見ずにメソッド名だけで判定する規則が
+ *     別にあるため、`$gateway::stripe()` のような書き方は記号として拾う (負のコントロール 11 形目)。
+ *     受け手を決められない呼び出しを閉包内で 0 件に pin しないのは、`parent::` を含めると
+ *     app/ に 31 件あり、退会経路と無関係な継承呼び出しまで免除語彙を要求することになるためである
  *   - **`Laravel\Cashier\` 名前空間の型参照そのもの** (`Subscription extends CashierSubscription` /
  *     `use Billable;`) は記号にしない。接頭辞走査は値オブジェクト・例外・モデル継承を巻き込んで
  *     信号を殺すため (ExternalSeamScanner が同じ理由で接頭辞走査を禁じている)
@@ -416,8 +422,8 @@ function deletionPathEdges(ReferenceScanResult $result, array $tokens): array
         if ($site->kind === ReferenceKind::NameReference || $site->kind === ReferenceKind::Construction) {
             $names[] = $site->name;
         }
-        if ($site->kind === ReferenceKind::StaticCall && $site->receiver !== null) {
-            $names[] = $site->receiver;
+        if ($site->kind === ReferenceKind::StaticCall && $site->receiver->isResolved()) {
+            $names[] = $site->receiver->fqcn();
         }
     }
     foreach ($tokens as $token) {
@@ -516,10 +522,10 @@ function deletionPathClassifySite(ReferenceSite $site, array $apiMethods): ?stri
         return $site->name;
     }
 
-    if ($site->kind === ReferenceKind::StaticCall && $site->receiver !== null
-        && deletionPathIsPaymentNamespace($site->receiver)
+    if ($site->kind === ReferenceKind::StaticCall && $site->receiver->isResolved()
+        && deletionPathIsPaymentNamespace($site->receiver->fqcn())
     ) {
-        return $site->receiver.'::'.$site->name.'()';
+        return $site->receiver->fqcn().'::'.$site->name.'()';
     }
 
     if ($site->kind !== ReferenceKind::MethodCall && $site->kind !== ReferenceKind::StaticCall) {
@@ -1195,10 +1201,13 @@ class Fixture {
     expect($scan['edges'])->toContain('App\Support\Billing\SomeBillingTrait');
 });
 
-test('負のコントロール 5 形目 (b): クラス本体の use が先頭 import を上書きしても辺を失わない', function (): void {
-    // ★`PhpReferenceScanner` の alias マップは `use App\...\Foo;` と クラス本体の `use Foo;` を
-    //   同じ短縮キーで扱うため、後者が前者を上書きして FQCN を失う。alias マップを辺に使うと
-    //   **trait 経由の到達が丸ごと消える** (fail-open)。トークン直読みでこれを防いでいることを固定する。
+test('負のコントロール 5 形目 (b): クラス本体の use があっても trait 経由の辺を失わない', function (): void {
+    // ★かつては `PhpReferenceScanner` の alias マップが `use App\...\Foo;` と
+    //   クラス本体の `use Foo;` を同じ短縮キーで扱い、後者が前者を上書きして FQCN を失っていた
+    //   (alias マップを辺に使うと trait 経由の到達が丸ごと消える fail-open)。
+    //   T226 で走査器側が**ファイルスコープの import だけ**を表に載せるようになったので
+    //   上書きは起きない。本 gate はもともとトークン直読みで辺を取るため、
+    //   **どちらの前提でも辺を失わない**ことを両側から固定する。
     $fixture = <<<'PHP'
     <?php
     namespace App\Models;
@@ -1209,8 +1218,7 @@ class Fixture {
     PHP;
 
     $result = PhpReferenceScanner::references('app/Models/Fixture.php', $fixture);
-    // 前提の実測: alias マップ側は上書きで短縮名に潰れている (この前提が崩れたら本テストは不要になる)。
-    expect($result->imports['shadowedtrait'] ?? null)->toBe('ShadowedTrait');
+    expect($result->imports['shadowedtrait'] ?? null)->toBe('App\Models\Concerns\ShadowedTrait');
 
     $scan = deletionPathScanSource('app/Models/Fixture.php', $fixture);
     expect($scan['edges'])->toContain('App\Models\Concerns\ShadowedTrait');
@@ -1363,6 +1371,30 @@ class SomethingElse {}
     expect($scan['declared'])->not->toBe([$scan['class']]);
 });
 
+test('負のコントロール 11 形目: 受け手を静的に決められない決済呼び出しも記号に載せる', function (): void {
+    // ★受け手を変数 / 遅延静的束縛にすると FQCN は解決できない (`ReceiverName` が未解決を返す)。
+    //   受け手だけを見て落とすと、この書き方 1 つで記号照合を素通りできる (fail-open)。
+    //   記号照合は**受け手を見ずメソッド名で**判定する規則を別に持つのでここは落ちない。
+    //   一方で到達辺は名前が無いため作れず、閉包はここで途切れる (docblock の「保証しないもの」)。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    class Fixture {
+        public function run(string $gateway): void {
+            $gateway::stripe();
+            static::createAsStripeCustomer();
+        }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    // 辺として残るのは `namespace` 宣言のトークン (ファイル自身) だけで、
+    // 受け手からの到達辺は 1 本も作れない。
+    expect(array_column($scan['payment'], 'symbol'))->toBe(['->stripe()', '->createAsStripeCustomer()'])
+        ->and($scan['edges'])->toBe(['App\Services\Organization']);
+});
+
 test('正のコントロール: 匿名クラスと ::class は宣言型に数えない', function (): void {
     $fixture = <<<'PHP'
     <?php
diff --git a/tests/Architecture/PromptDefenseWindowGateTest.php b/tests/Architecture/PromptDefenseWindowGateTest.php
index 14248268..da960ed1 100644
--- a/tests/Architecture/PromptDefenseWindowGateTest.php
+++ b/tests/Architecture/PromptDefenseWindowGateTest.php
@@ -429,6 +429,46 @@ public static function make(string $key, string $value, string $name): mixed
     expect($dynamicCalls[0]->template)->toBeNull();
     expect($dynamicCalls[0]->untrustedKeys)->toBeNull();
 
+    // (f) 先頭要素を import した部分修飾名で vendor prompt を読む形
+    //     (部分修飾名を解決しなかった頃は `PrismPrompt\Prompt` のまま照合され見逃していた)
+    $partiallyQualified = <<<'PHP'
+<?php
+namespace App\Services;
+use Kent013\PrismPrompt;
+class Sneaky { public function go(): mixed { return PrismPrompt\Prompt::load('sop-extract', []); } }
+PHP;
+    expect(PromptWindowScanner::pathsOf(
+        PromptWindowScanner::scan('app/Services/Sneaky.php', $partiallyQualified),
+        PromptWindowRule::VendorPromptLoad,
+    ))->toBe(['app/Services/Sneaky.php']);
+
+    // (g) 受け手を変数にして読み込み元を隠す形 = 未解決。**fail-closed で拾う** (規約 (b))。
+    //     `load` は vendor 直読みか窓口呼び出しか判別できないので、
+    //     窓口 1 ファイルにしか許されない側 (vendor 読み込み) として数える。
+    $unresolvedReceiver = <<<'PHP'
+<?php
+namespace App\Services;
+class Sneaky
+{
+    public function go(string $prompt): mixed { return $prompt::load('sop-extract', []); }
+
+    public function goUnattributed(string $prompt): mixed { return $prompt::loadUnattributed('sop-extract', []); }
+}
+PHP;
+    $unresolvedSites = PromptWindowScanner::scan('app/Services/Sneaky.php', $unresolvedReceiver);
+    expect(PromptWindowScanner::pathsOf($unresolvedSites, PromptWindowRule::VendorPromptLoad))
+        ->toBe(['app/Services/Sneaky.php']);
+    expect(PromptWindowScanner::pathsOf($unresolvedSites, PromptWindowRule::WindowLoadUnattributed))
+        ->toBe(['app/Services/Sneaky.php']);
+
+    // 正例: 名前空間相対の同名クラス (`App\Services\PrismPrompt\Prompt`) は vendor ではない
+    $sameNamespace = <<<'PHP'
+<?php
+namespace App\Services;
+class Innocent { public function go(): mixed { return PrismPrompt\Prompt::load('note', []); } }
+PHP;
+    expect(PromptWindowScanner::scan('app/Services/Innocent.php', $sameNamespace))->toBe([]);
+
     // 正例: コメント / 文字列リテラル中の記述には反応しない (gate 自身の説明文を数えない)
     $benign = <<<'PHP'
 <?php
diff --git a/tests/Support/ExternalClientBoundaryScanner.php b/tests/Support/ExternalClientBoundaryScanner.php
index 02e60361..28286f27 100644
--- a/tests/Support/ExternalClientBoundaryScanner.php
+++ b/tests/Support/ExternalClientBoundaryScanner.php
@@ -22,6 +22,10 @@
  *   これらの token をまったく出さない経路 (`app('filesystem')` の戻りを別メソッドへ渡す等) は
  *   **検出できない**。この非対称は docs/architecture.md §外部 SDK の待ち上限の規約に明記する。
  *
+ * ★**受け手が未解決の静的呼び出しは拾う側へ倒す** (共通規約 (b))。`$requestor::setHttpClient()` の
+ *   ように FQCN を静的に決められない書き方でも大域 setter として検出する。
+ *   偽陽性が出たら目録へ登録して理由を残す形にし、**無言で候補から外さない**。
+ *
  * ★検出理由コード: `fqn_reference` / `imported_name_reference` (クラス名の参照) /
  *   `new_external_object` (**構築点**。DI で受け取るだけの消費点と区別するために種別を分ける) /
  *   `disk_call` / `get_client_call` / `stripe_global_setter`。
@@ -136,10 +140,13 @@ public static function scan(string $relativePath, string $phpSource): array
                 ),
 
                 // R6: Stripe のプロセス大域 setter
+                // ★受け手を静的に決められない形 (`$requestor::setHttpClient()` /
+                //   `static::setMaxNetworkRetries()`) も検出する = fail-closed。
+                //   完全修飾名だけを見て落とすと、変数経由に書き換えるだけで
+                //   プロセス大域状態への到達が目録から消える (共通規約 (b))。
                 $reference->kind === ReferenceKind::StaticCall
                     && in_array($reference->name, self::STRIPE_GLOBAL_SYMBOLS, true)
-                    && $reference->receiver !== null
-                    && str_starts_with($reference->receiver, 'Stripe\\') => self::fromReference($reference, 'stripe_global_setter', $reference->name, null),
+                    && ($reference->receiver->startsWith('Stripe\\') || $reference->receiver->isUnresolved()) => self::fromReference($reference, 'stripe_global_setter', $reference->name, null),
 
                 // R7: `new Aws\…` は「構築点」であり、DI で受け取るだけの消費点と区別する
                 $reference->kind === ReferenceKind::Construction && self::isTargetName($reference->name) => self::fromReference($reference, 'new_external_object', $reference->name, null),
diff --git a/tests/Support/ExternalSeam/ExternalSeamScanner.php b/tests/Support/ExternalSeam/ExternalSeamScanner.php
index d8516e3e..9fa6b93a 100644
--- a/tests/Support/ExternalSeam/ExternalSeamScanner.php
+++ b/tests/Support/ExternalSeam/ExternalSeamScanner.php
@@ -29,6 +29,10 @@
  * ★**保証範囲を誇張しない**: 検出できるのは下記 5 規則の**静的な出現**だけである。
  *   文字列キーの container 解決だけで型名も呼び出しも出さない経路は検出できない。
  *   走査根は `app/` のみで、`routes/` / `config/` は見ない。
+ *
+ * ★**受け手が未解決の静的呼び出しは採用する側へ倒す** (共通規約 (b))。`$gateway::stripe()` の
+ *   ように FQCN を静的に決められない書き方でも決済 client の取得として採用する。
+ *   採用しすぎたら目録へ登録して理由を残す形にし、**無言で候補から外さない**。
  */
 final class ExternalSeamScanner
 {
@@ -126,8 +130,12 @@ public static function scanDirectory(string $absoluteRoot, string $relativeRoot)
     private static function classify(ReferenceSite $reference): ?ExternalSeamSite
     {
         // 決済: client の取得 (static / method の両方)
+        // ★受け手を静的に決められない静的呼び出し (`$gateway::stripe()`) も採用する
+        //   = fail-closed。完全修飾名だけを見て落とすと、変数経由に書き換えるだけで
+        //   目録登録の要求を抜けられる (共通規約 (b))。
         if ($reference->name === self::CLIENT_ACCESSOR
-            && (($reference->kind === ReferenceKind::StaticCall && $reference->receiver === self::CASHIER_FACADE)
+            && (($reference->kind === ReferenceKind::StaticCall
+                && ($reference->receiver->is(self::CASHIER_FACADE) || $reference->receiver->isUnresolved()))
                 || $reference->kind === ReferenceKind::MethodCall)
         ) {
             return self::site($reference, ExternalSeamRule::PaymentClientCall, self::CLIENT_ACCESSOR);
diff --git a/tests/Support/Llm/PromptWindowScanner.php b/tests/Support/Llm/PromptWindowScanner.php
index bc8ca4e3..7807352f 100644
--- a/tests/Support/Llm/PromptWindowScanner.php
+++ b/tests/Support/Llm/PromptWindowScanner.php
@@ -13,6 +13,7 @@
 use Kent013\PrismPrompt\TextPrompt;
 use Kent013\PrismPrompt\Values\UserInput;
 use Tests\Support\PhpReferenceScanner;
+use Tests\Support\ReceiverName;
 use Tests\Support\ReferenceKind;
 use Tests\Support\ReferenceSite;
 use Tests\Support\ScanScopeKind;
@@ -74,14 +75,18 @@ public static function scan(string $relativePath, string $phpSource): array
     /**
      * **同じ名前空間の短縮名**を補って参照 site にする。
      *
-     * `PhpReferenceScanner` は import (`use`) が無い短縮名を解決しない (同クラスの
-     * 「名前解決の限界」。既存 gate との振る舞い保存のため中立走査器側は直さない)。
+     * `PhpReferenceScanner` は import (`use`) が無い短縮名を名前参照 site にしない
+     * (同クラスの「保証しないもの」。`true` や定数まで同じ `T_STRING` で現れるため、
+     * 短縮名を一律に site 化すると母集団が意味を失う)。
      * しかし窓口一式は `App\Support\Llm` に同居しているため、そのままでは
-     * `PromptDefense.php` 内の `new GuardedPrompt(...)` や `UntrustedTextSanitizer::sanitize(...)` が
-     * 1 件も見えず、**所有権の検査が空振りしたまま緑になる**。ここを補って穴を塞ぐ。
+     * `PromptDefense.php` 内の `UntrustedTextSanitizer::sanitize(...)` の**受け手**や
+     * `PromptCanary` の型宣言が 1 件も見えず、**所有権の検査が空振りしたまま緑になる**。
+     * ここを補って穴を塞ぐ。
      *
      * ★ tokenizer は増やさない (`PhpReferenceScanner::tokens()` の正規化列を使う)。
      * ★ 補うのは**窓口一式の短縮名だけ**で、無関係な名前は 1 つも site にしない。
+     * ★ 補うのは `NameReference` だけである。`new Foo(` と静的呼び出しそのものは
+     *   中立走査器が解決するようになったので、ここで出すと**二重計上**になる。
      *
      * @param  array<string, string>  $imports  小文字 short name => FQCN
      * @return list<ReferenceSite>
@@ -135,45 +140,28 @@ private static function sameNamespaceReferences(string $relativePath, string $ph
                 continue; // メソッド名 / 宣言名であってクラス参照ではない
             }
 
+            if ($previousId === T_NEW) {
+                continue; // `new Foo(` は中立走査器が Construction として解決済み
+            }
+
             $next = $tokens[$i + 1] ?? null;
-            $isStaticCall = $next !== null && $next['id'] === T_DOUBLE_COLON;
-            if ($isStaticCall) {
+            if ($next !== null && $next['id'] === T_DOUBLE_COLON) {
                 $method = $tokens[$i + 2] ?? null;
                 $paren = $tokens[$i + 3] ?? null;
                 if ($method === null || $method['id'] !== T_STRING
                     || $paren === null || $paren['id'] !== null || $paren['text'] !== '(') {
                     continue; // `Foo::CONST` や `Foo::class`
                 }
-                // ★ 中立走査器の emission 契約に合わせ、**1 つの静的呼び出しから
-                //   StaticCall と receiver の NameReference の 2 site**を出す
-                //   (所有権の検査は NameReference 側を canonical にしているため)。
-                $references[] = self::reference(
-                    $relativePath,
-                    $method['line'],
-                    $i + 2,
-                    ReferenceKind::StaticCall,
-                    $method['text'],
-                    $candidates[$token['text']],
-                );
-                $references[] = self::reference(
-                    $relativePath,
-                    $token['line'],
-                    $i,
-                    ReferenceKind::NameReference,
-                    $candidates[$token['text']],
-                    null,
-                );
-
-                continue;
+                // ★ 静的呼び出しで補うのは**受け手の NameReference だけ**である。呼び出しそのものは
+                //   中立走査器が受け手を解決した StaticCall として出す (二重計上しない)。
+                //   所有権の検査は NameReference 側を canonical にしているためここが要る。
             }
 
             $references[] = self::reference(
                 $relativePath,
                 $token['line'],
                 $i,
-                $previousId === T_NEW ? ReferenceKind::Construction : ReferenceKind::NameReference,
                 $candidates[$token['text']],
-                null,
             );
         }
 
@@ -184,17 +172,15 @@ private static function reference(
         string $path,
         int $line,
         int $tokenIndex,
-        ReferenceKind $kind,
         string $name,
-        ?string $receiver,
     ): ReferenceSite {
         return new ReferenceSite(
             path: $path,
             line: $line,
             tokenIndex: $tokenIndex,
-            kind: $kind,
+            kind: ReferenceKind::NameReference,
             name: $name,
-            receiver: $receiver,
+            receiver: ReceiverName::absent(),
             qualified: false,
             scopeKind: ScanScopeKind::NamedClass,
             class: null,
@@ -418,16 +404,36 @@ private static function literalValue(string $literal): string
 
     private static function classify(ReferenceSite $reference): ?PromptWindowSite
     {
+        // 受け手を静的に決められない静的呼び出し。**読み込み系のメソッド名なら拾う** = fail-closed。
+        // ★`load` は「vendor 直読み」と「窓口呼び出し」のどちらか判別できないので、
+        //   **窓口 1 ファイルにしか許されない側** (VendorPromptLoad) として扱う。
+        //   窓口を迂回する経路を変数経由の書き方で隠せてはならない (共通規約 (b))。
+        if ($reference->kind === ReferenceKind::StaticCall && $reference->receiver->isUnresolved()) {
+            $rule = match ($reference->name) {
+                self::VENDOR_LOAD_METHOD => PromptWindowRule::VendorPromptLoad,
+                'loadUnattributed' => PromptWindowRule::WindowLoadUnattributed,
+                default => null,
+            };
+            if ($rule !== null) {
+                return new PromptWindowSite(
+                    $reference->path,
+                    $reference->line,
+                    $rule,
+                    '(受け手が未解決)::'.$reference->name,
+                );
+            }
+        }
+
         // `Prompt::load(` / `TextPrompt::load(` / `EmbeddingPrompt::load(`
         if ($reference->kind === ReferenceKind::StaticCall
             && $reference->name === self::VENDOR_LOAD_METHOD
-            && $reference->receiver !== null
-            && in_array($reference->receiver, self::VENDOR_PROMPT_CLASSES, true)) {
+            && $reference->receiver->isResolved()
+            && in_array($reference->receiver->fqcn(), self::VENDOR_PROMPT_CLASSES, true)) {
             return new PromptWindowSite(
                 $reference->path,
                 $reference->line,
                 PromptWindowRule::VendorPromptLoad,
-                $reference->receiver.'::load',
+                $reference->receiver->fqcn().'::load',
             );
         }
 
@@ -442,7 +448,7 @@ private static function classify(ReferenceSite $reference): ?PromptWindowSite
         }
 
         // `PromptDefense::load(` / `PromptDefense::loadUnattributed(`
-        if ($reference->kind === ReferenceKind::StaticCall && $reference->receiver === PromptDefense::class) {
+        if ($reference->kind === ReferenceKind::StaticCall && $reference->receiver->is(PromptDefense::class)) {
             $rule = match ($reference->name) {
                 'load' => PromptWindowRule::WindowLoad,
                 'loadUnattributed' => PromptWindowRule::WindowLoadUnattributed,
diff --git a/tests/Support/PhpReferenceScanner.php b/tests/Support/PhpReferenceScanner.php
index a6e93f77..2ab52bbf 100644
--- a/tests/Support/PhpReferenceScanner.php
+++ b/tests/Support/PhpReferenceScanner.php
@@ -47,21 +47,44 @@ public static function tokens(string $phpSource): array
      *   emit される。すなわち **1 つの静的呼び出しは NameReference と StaticCall の 2 site を生む**。
      *   利用側はどちらか一方だけを canonical にすること (両方を見ると二重検出になる)。
      *
-     * ★**名前解決の限界 = 共通規約 (a)・(b) を満たしていない既知の穴**
-     *   (`AGENTS.md` の「静的検査 (gate) と走査器の共通規約」):
-     *   `T_NAME_QUALIFIED` (`Foo\Bar` のような部分修飾名) は `ltrim($text, '\\')` するだけで、
-     *   「現在の namespace への相対解決」も「先頭 segment の alias 解決」も**行わない**。
-     *   したがって `use Illuminate\Support\Facades; … Facades\Http::get()` は解決できず、
-     *   **未解決であることを区別できない名前文字列として参照 site が emit される**。
-     *   **現在の**利用側 (対象クラスを完全修飾名で照合するもの) では、この文字列が対象一覧に
-     *   一致しないため、参照 site は存在しているのに違反候補として認識されず**無言で見逃される**
-     *   (= 見逃す側へ倒れている)。
-     *   抽出したときは**振る舞い保存**が目的でここを触らなかったが、
-     *   これは**規約に照らして是認された限界ではなく、是正待ちの穴**である
-     *   (是正すると本走査器を使う gate と派生検出器の判定結果が変わり、従来見逃していた参照の
-     *   顕在化、または未解決エラーによる新しい失敗が起こり得るため別 TODO で扱う。
-     *   棚卸しは `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` の D1)。
-     *   **したがって部分修飾名で書かれた参照について本走査器は検出力を主張しない。**
+     * ★**名前解決の規則** (`AGENTS.md` の「静的検査 (gate) と走査器の共通規約」(a)):
+     *   emit する `name` は**必ず完全修飾名まで解決済み**である。PHP の名前解決規則をそのまま写す。
+     *   - `T_NAME_FULLY_QUALIFIED` (`\Foo\Bar`): 先頭の `\` を落とす
+     *   - `T_NAME_QUALIFIED` (`Foo\Bar` のような部分修飾名): **先頭要素を import 表で置き換える**。
+     *     一致する import が無ければ**現在の名前空間の下**に置く
+     *     (`use Illuminate\Support\Facades;` + `Facades\Http` => `Illuminate\Support\Facades\Http`、
+     *      `namespace App\Services;` + `Support\Thing` => `App\Services\Support\Thing`)
+     *   - `T_NAME_RELATIVE` (`namespace\Foo`): 現在の名前空間の下に置く
+     *   - import 済みの短縮名 / 別名: import 表で置き換える
+     *   - import の無い短縮名でも `new X(` の位置は**構文上クラス名が確定する**ので、
+     *     現在の名前空間の下に解決する (`namespace Stripe; new StripeClient();`)
+     *   import 表は**namespace 宣言ごとに作り直し**、**ファイルスコープの `use` だけ**を、
+     *   さらに**クラスの import だけ**を登録する
+     *   (クラス本体の `use SomeTrait;` は取り込みであって import ではない。
+     *    `use function` / `use const` はクラス名を作らない。
+     *    どちらも混ぜると同名の短縮キーでクラスの import を上書きし FQCN を失う)。
+     *   `use` は宣言より前の参照には効かない (PHP 実測) ため、走査順のまま解決してよい。
+     *
+     * ★**解決できない形の扱い ((b) fail-closed)**: 静的呼び出しの受け手が変数 (`$gateway::`) /
+     *   遅延静的束縛 (`static::`) / 親クラス (`parent::`) / 式 / **trait 本体の `self::`**
+     *   (取り込んだクラスへ展開されるので trait 自身を指さない) のときは FQCN を確定できない。
+     *   これを「受け手なし」と同じ値へ潰さず、`ReceiverName` が
+     *   `ReceiverResolution::Unresolved` として返す。利用側 gate は**拾いすぎる方向**へ倒して
+     *   扱うこと (完全修飾名だけを見て黙って落とすと、変数経由に書き換えるだけで検査を抜けられる)。
+     *   なお `new` の直後の `self` / `static` / `parent` も同じ理由で名前解決の対象にしない。
+     *
+     * ★**保証しないもの (誇張しない)**: import の無い短縮名のうち、
+     *   **`new` の直後でも静的呼び出しの受け手でもない位置** (型宣言 / `::class` / `instanceof` /
+     *   `implements` / `extends`) は名前参照 site として emit しない。
+     *   PHP の規則では現在の名前空間の下に解決されるので、**これは PHP の構文上の限界ではなく、
+     *   本走査器がこれらの文脈の判定を実装していないという限界である**
+     *   (`T_STRING` は定数名・関数名・`true` などにも使われるため、文脈を見ずに一律 emit すると
+     *    母集団が意味を失う。文脈ごとの判定を足せば解決はできる)。
+     *   したがって**ファイル自身の名前空間の下に居るクラス**を、import 無しの短縮名で
+     *   この位置に書いた参照は見えない。**この形について本走査器は検出力を主張しない**。
+     *   同じ名前空間の対象を照合したい利用側は自分で補うこと
+     *   (準拠実装: `Tests\Support\Llm\PromptWindowScanner::sameNamespaceReferences()`)。
+     *   `new` の直後と静的呼び出しの受け手は上記のとおり解決するので、この穴には入らない。
      */
     public static function references(string $relativePath, string $phpSource): ReferenceScanResult
     {
@@ -69,13 +92,15 @@ public static function references(string $relativePath, string $phpSource): Refe
         $count = count($tokens);
 
         $namespace = '';
-        /** @var array<string, string> $aliases short name (小文字) => FQCN */
+        /** @var array<string, string> $aliases 現在の namespace ブロックの import 表 (小文字 short name => FQCN) */
         $aliases = [];
+        /** @var array<string, string> $imports ファイル全体の import (返却用。namespace ブロックをまたいで積む) */
+        $imports = [];
 
         $braceDepth = 0;
-        /** @var list<array{kind: ScanScopeKind, class: string|null, bodyDepth: int}> $scopes */
+        /** @var list<array{kind: ScanScopeKind, class: string|null, trait: bool, bodyDepth: int}> $scopes */
         $scopes = [];
-        /** @var array{kind: ScanScopeKind, class: string|null}|null $pendingScope */
+        /** @var array{kind: ScanScopeKind, class: string|null, trait: bool}|null $pendingScope */
         $pendingScope = null;
         /** @var list<array{name: string, bodyDepth: int}> $callables */
         $callables = [];
@@ -90,7 +115,12 @@ public static function references(string $relativePath, string $phpSource): Refe
             $text = $token['text'];
 
             // --- namespace 宣言 ---
+            // ★import 表は namespace ブロックごとに作り直される (PHP 実測: 前のブロックの
+            //   `use A as Sub;` は次のブロックの `Sub\Y` を解決しない)。捨てないと
+            //   別ブロックの別名で解決してしまう。
             if ($id === T_NAMESPACE) {
+                $namespace = '';
+                $aliases = [];
                 $next = $tokens[$i + 1] ?? null;
                 if ($next !== null && ($next['id'] === T_NAME_QUALIFIED || $next['id'] === T_STRING)) {
                     $namespace = $next['text'];
@@ -107,7 +137,17 @@ public static function references(string $relativePath, string $phpSource): Refe
                 if ($next !== null && $next['text'] === '(') {
                     continue;
                 }
-                $i = self::collectUseStatement($tokens, $i, $aliases);
+                /** @var array<string, string> $collected */
+                $collected = [];
+                $i = self::collectUseStatement($tokens, $i, $collected);
+                // ★クラス本体の `use SomeTrait;` は**取り込みであって import ではない**。
+                //   import 表へ混ぜると同名の短縮キーでファイル先頭の import を上書きし、
+                //   FQCN を失う (`use App\Concerns\Foo;` + クラス本体の `use Foo;` で
+                //   `foo => 'Foo'` になる)。名前解決の土台なのでファイルスコープに限る。
+                if ($scopes === []) {
+                    $aliases = array_merge($aliases, $collected);
+                    $imports = array_merge($imports, $collected);
+                }
 
                 continue;
             }
@@ -126,6 +166,9 @@ public static function references(string $relativePath, string $phpSource): Refe
                     'class' => $isNamed && $next !== null
                         ? ($namespace === '' ? $next['text'] : $namespace.'\\'.$next['text'])
                         : null,
+                    // ★trait は「どのクラスへ展開されるか」が走査時点で決まらない。
+                    //   `self::` の解決可否がクラスと違うので、宣言の種別を覚えておく。
+                    'trait' => $id === T_TRAIT,
                 ];
 
                 continue;
@@ -153,7 +196,12 @@ public static function references(string $relativePath, string $phpSource): Refe
             if ($id === null && $text === '{') {
                 $braceDepth++;
                 if ($pendingScope !== null) {
-                    $scopes[] = ['kind' => $pendingScope['kind'], 'class' => $pendingScope['class'], 'bodyDepth' => $braceDepth];
+                    $scopes[] = [
+                        'kind' => $pendingScope['kind'],
+                        'class' => $pendingScope['class'],
+                        'trait' => $pendingScope['trait'],
+                        'bodyDepth' => $braceDepth,
+                    ];
                     $pendingScope = null;
                 } elseif ($pendingCallable !== null) {
                     $callables[] = ['name' => $pendingCallable, 'bodyDepth' => $braceDepth];
@@ -187,10 +235,11 @@ public static function references(string $relativePath, string $phpSource): Refe
 
             $scopeKind = $scopes === [] ? ScanScopeKind::FileScope : $scopes[count($scopes) - 1]['kind'];
             $scopeClass = $scopes === [] ? null : $scopes[count($scopes) - 1]['class'];
+            $scopeIsTrait = $scopes !== [] && $scopes[count($scopes) - 1]['trait'];
             $callableName = $callables === [] ? null : $callables[count($callables) - 1]['name'];
 
-            // --- 完全修飾 / 修飾名による参照 ---
-            if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED) {
+            // --- 完全修飾 / 部分修飾 / 名前空間相対の名前による参照 ---
+            if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED || $id === T_NAME_RELATIVE) {
                 $kind = ($tokens[$i - 1]['id'] ?? null) === T_NEW
                     ? ReferenceKind::Construction
                     : ReferenceKind::NameReference;
@@ -199,8 +248,8 @@ public static function references(string $relativePath, string $phpSource): Refe
                     line: $token['line'],
                     tokenIndex: $i,
                     kind: $kind,
-                    name: ltrim($text, '\\'),
-                    receiver: null,
+                    name: self::resolveWrittenName($id, $text, $namespace, $aliases),
+                    receiver: ReceiverName::absent(),
                     qualified: true,
                     scopeKind: $scopeKind,
                     class: $scopeClass,
@@ -230,7 +279,9 @@ class: $scopeClass,
                     tokenIndex: $i,
                     kind: ReferenceKind::StaticCall,
                     name: $text,
-                    receiver: $receiverToken === null ? null : self::resolveName($receiverToken, $aliases),
+                    receiver: $receiverToken === null
+                        ? ReceiverName::unresolved()
+                        : self::resolveReceiver($receiverToken, $namespace, $scopeIsTrait ? null : $scopeClass, $aliases),
                     qualified: false,
                     scopeKind: $scopeKind,
                     class: $scopeClass,
@@ -248,7 +299,7 @@ class: $scopeClass,
                     tokenIndex: $i,
                     kind: ReferenceKind::MethodCall,
                     name: $text,
-                    receiver: null,
+                    receiver: ReceiverName::absent(),
                     qualified: false,
                     scopeKind: $scopeKind,
                     class: $scopeClass,
@@ -267,9 +318,19 @@ class: $scopeClass,
                 || $previousId === T_AS || $previousId === T_GOTO) {
                 continue; // 宣言名であって参照ではない
             }
-            $resolved = $aliases[mb_strtolower($text)] ?? null;
+            $lower = mb_strtolower($text);
+            $resolved = $aliases[$lower] ?? null;
             if ($resolved === null) {
-                continue;
+                // ★`new X(` の `X` は直前のトークンだけでクラス名だと分かるので、
+                //   import が無くても現在の名前空間の下に解決する。ここを落とすと
+                //   `namespace Stripe; new StripeClient();` のように**ファイル自身の名前空間が
+                //   対象と同じ**ときに構築点を見逃す (fail-open)。
+                //   それ以外の位置 (型宣言 / `::class` 等) の短縮名は文脈判定を実装していないので
+                //   解決しない (docblock の「保証しないもの」)。
+                if ($previousId !== T_NEW || in_array($lower, ['self', 'static', 'parent'], true)) {
+                    continue;
+                }
+                $resolved = $namespace === '' ? $text : $namespace.'\\'.$text;
             }
 
             $sites[] = new ReferenceSite(
@@ -278,7 +339,7 @@ class: $scopeClass,
                 tokenIndex: $i,
                 kind: $previousId === T_NEW ? ReferenceKind::Construction : ReferenceKind::NameReference,
                 name: $resolved,
-                receiver: null,
+                receiver: ReceiverName::absent(),
                 qualified: false,
                 scopeKind: $scopeKind,
                 class: $scopeClass,
@@ -286,13 +347,16 @@ class: $scopeClass,
             );
         }
 
-        return new ReferenceScanResult($sites, $aliases);
+        return new ReferenceScanResult($sites, $imports);
     }
 
     /**
      * `use` 文を読み進めて alias マップへ登録し、`;` の添字を返す。
      *
      * `use function` / `use const` は名前解決の対象外 (クラス参照ではない)。
+     * **グループの内側に書く形 (`use App\{Foo, function bar, const BAZ};`) も同じ扱い**で、
+     * 関数 / 定数の要素だけを飛ばす。ここを取り違えるとクラスの別名表へ関数名が入り、
+     * 同名のクラス import を上書きして部分修飾名を誤った FQCN へ解決する。
      * グループ use (`use Aws\{S3\S3Client, Sns\SnsClient};`) にも対応する。
      *
      * @param  list<array{id: int|null, text: string, line: int}>  $tokens
@@ -316,6 +380,7 @@ private static function collectUseStatement(array $tokens, int $useIndex, array
         $current = '';
         $alias = null;
         $expectAlias = false;
+        $isClassImport = true;
 
         for (; $i < $count; $i++) {
             $token = $tokens[$i];
@@ -326,7 +391,7 @@ private static function collectUseStatement(array $tokens, int $useIndex, array
                 // ★`{` の直前に溜まっている名前は**グループ use の接頭辞**であって import ではない。
                 //   ここで alias 登録すると `use Illuminate\Support\Facades\{Http, Mail};` が
                 //   `Facades` という実在しない import を作る。
-                if ($current !== '' && $text !== '{') {
+                if ($current !== '' && $text !== '{' && $isClassImport) {
                     $fqcn = ltrim($prefix.$current, '\\');
                     $short = $alias ?? self::shortName($fqcn);
                     $aliases[mb_strtolower($short)] = $fqcn;
@@ -334,6 +399,7 @@ private static function collectUseStatement(array $tokens, int $useIndex, array
                 $current = '';
                 $alias = null;
                 $expectAlias = false;
+                $isClassImport = true;
 
                 if ($text === '{') {
                     // グループ use: 直前までの名前が接頭辞になる
@@ -349,6 +415,13 @@ private static function collectUseStatement(array $tokens, int $useIndex, array
                 continue;
             }
 
+            if ($id === T_FUNCTION || $id === T_CONST) {
+                // グループの内側の `function` / `const`。この要素はクラスの別名にしない
+                $isClassImport = false;
+
+                continue;
+            }
+
             if ($id === T_AS) {
                 $expectAlias = true;
 
@@ -395,22 +468,81 @@ private static function groupPrefix(array $tokens, int $useIndex, int $braceInde
     }
 
     /**
-     * トークンをクラス名 (FQCN) として解決する。解決できなければ null。
+     * ソースに書かれた名前を PHP の名前解決規則どおり FQCN へ解決する。
+     *
+     * 部分修飾名 (`Foo\Bar`) は**先頭要素**だけが import 表の対象である
+     * (`use A\B\Foo;` + `Foo\Bar` => `A\B\Foo\Bar`)。一致する import が無ければ
+     * 現在の名前空間の下に置く (`namespace App;` + `Foo\Bar` => `App\Foo\Bar`)。
+     *
+     * @param  array<string, string>  $aliases
+     */
+    private static function resolveWrittenName(?int $id, string $text, string $namespace, array $aliases): string
+    {
+        if ($id === T_NAME_FULLY_QUALIFIED) {
+            return ltrim($text, '\\');
+        }
+
+        $separator = strpos($text, '\\');
+
+        if ($id === T_NAME_RELATIVE) {
+            // `namespace\Foo\Bar` は現在の名前空間の下を指す
+            $rest = $separator === false ? '' : substr($text, $separator + 1);
+
+            return $namespace === '' ? $rest : $namespace.'\\'.$rest;
+        }
+
+        $head = $separator === false ? $text : substr($text, 0, $separator);
+        $resolvedHead = $aliases[mb_strtolower($head)] ?? null;
+        if ($resolvedHead !== null) {
+            return $separator === false ? $resolvedHead : $resolvedHead.substr($text, $separator);
+        }
+
+        return $namespace === '' ? $text : $namespace.'\\'.$text;
+    }
+
+    /**
+     * 静的呼び出しの受け手を解決する。**確定できない形は `Unresolved` として返す** ((b) fail-closed)。
+     *
+     * `self::` は囲みのクラスが分かるので解決する。`static::` は遅延静的束縛、
+     * `parent::` は継承関係を追わないと決まらないため未解決にする。
+     * **trait 本体の `self::` も未解決**である — trait のコードは取り込んだクラスへ展開されるので
+     * `self` は trait 自身ではなく利用クラスを指し、複数のクラスが取り込める以上ここでは決まらない
+     * (呼び出し側が `$scopeClass` に null を渡す)。
      *
      * @param  array{id: int|null, text: string, line: int}  $token
+     * @param  string|null  $scopeClass  囲みのクラス FQCN (`self` が決まらない scope では null)
      * @param  array<string, string>  $aliases
      */
-    private static function resolveName(array $token, array $aliases): ?string
+    private static function resolveReceiver(array $token, string $namespace, ?string $scopeClass, array $aliases): ReceiverName
     {
         $id = $token['id'];
-        if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED) {
-            return ltrim($token['text'], '\\');
+
+        if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED || $id === T_NAME_RELATIVE) {
+            return ReceiverName::resolved(self::resolveWrittenName($id, $token['text'], $namespace, $aliases));
         }
+
         if ($id === T_STRING) {
-            return $aliases[mb_strtolower($token['text'])] ?? null;
+            $lower = mb_strtolower($token['text']);
+            if ($lower === 'self') {
+                return $scopeClass === null ? ReceiverName::unresolved() : ReceiverName::resolved($scopeClass);
+            }
+            if ($lower === 'parent') {
+                return ReceiverName::unresolved();
+            }
+            $imported = $aliases[$lower] ?? null;
+            if ($imported !== null) {
+                return ReceiverName::resolved($imported);
+            }
+
+            // ★受け手の位置に来る短縮名は**必ずクラス名**なので、import が無ければ
+            //   現在の名前空間の下に解決してよい (定数や関数名と混ざる余地が無い)。
+            return ReceiverName::resolved(
+                $namespace === '' ? $token['text'] : $namespace.'\\'.$token['text'],
+            );
         }
 
-        return null;
+        // 変数 / `static` / 式の結果など。**null へ潰さず未解決として返す**。
+        return ReceiverName::unresolved();
     }
 
     private static function shortName(string $fqcn): string
diff --git a/tests/Support/ReceiverName.php b/tests/Support/ReceiverName.php
new file mode 100644
index 00000000..7d5a184d
--- /dev/null
+++ b/tests/Support/ReceiverName.php
@@ -0,0 +1,83 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use LogicException;
+
+/**
+ * 静的呼び出しの受け手 (receiver) の解決結果。
+ *
+ * ★**`string|null` にしない**。null は「受け手が無い」とも「解決できなかった」とも読めるため、
+ *   利用側が `!== null` だけを見て未解決を落とす形 (= 無言の見逃し) を許してしまう。
+ *   3 状態を型で持たせることで、利用側の判定を読んだときに
+ *   **未解決をどう扱っているかが必ず目に見える**ようにする。
+ *
+ * ★**保証範囲を誇張しない**: 型は「未解決だと分かること」までを保証する。
+ *   `is()` / `startsWith()` は未解決を `false` へ畳むので、**これらだけで書いた判定は
+ *   未解決を落とす**。未解決を拾う側へ倒すかどうかは利用側の判断であり、
+ *   その判断を書き忘れないことは型では強制できない (レビューで見る)。
+ *   完全修飾名そのものを取り出す `fqcn()` だけは、未解決のまま呼ぶと例外になる。
+ */
+final readonly class ReceiverName
+{
+    private function __construct(
+        public ReceiverResolution $resolution,
+        private ?string $value,
+    ) {}
+
+    /** 完全修飾名まで解決できた受け手。 */
+    public static function resolved(string $fqcn): self
+    {
+        return new self(ReceiverResolution::Resolved, $fqcn);
+    }
+
+    /** 受け手は書かれているが静的に確定できない (変数 / `static` / `parent` / 式)。 */
+    public static function unresolved(): self
+    {
+        return new self(ReceiverResolution::Unresolved, null);
+    }
+
+    /** 受け手を持たない種別。 */
+    public static function absent(): self
+    {
+        return new self(ReceiverResolution::Absent, null);
+    }
+
+    public function isResolved(): bool
+    {
+        return $this->resolution === ReceiverResolution::Resolved;
+    }
+
+    public function isUnresolved(): bool
+    {
+        return $this->resolution === ReceiverResolution::Unresolved;
+    }
+
+    /** 解決済みの完全修飾名。未解決 / 受け手なしで呼ぶのは利用側の誤りなので例外にする。 */
+    public function fqcn(): string
+    {
+        if ($this->value === null) {
+            throw new LogicException(
+                '受け手が解決できていない site から完全修飾名を取り出そうとしました '
+                .'(解決状態: '.$this->resolution->name.')。'
+                .'照合の前に isResolved() / isUnresolved() で分岐してください。',
+            );
+        }
+
+        return $this->value;
+    }
+
+    /** 解決済みで、かつ指定の完全修飾名と一致するか。 */
+    public function is(string $fqcn): bool
+    {
+        return $this->value === $fqcn;
+    }
+
+    /** 解決済みで、かつ指定の名前空間接頭辞の下にあるか。 */
+    public function startsWith(string $prefix): bool
+    {
+        return $this->value !== null && str_starts_with($this->value, $prefix);
+    }
+}
diff --git a/tests/Support/ReceiverResolution.php b/tests/Support/ReceiverResolution.php
new file mode 100644
index 00000000..74bb428d
--- /dev/null
+++ b/tests/Support/ReceiverResolution.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+/**
+ * 静的呼び出しの受け手 (receiver) を完全修飾名まで解決できたか。
+ *
+ * ★**未解決を「受け手が無い」と同じ値へ潰さない**。潰すと利用側は
+ *   「見なくてよい site」と「解決できなかった site」を区別できず、
+ *   `$client::setHttpClient()` のような書き方が**無言で候補から外れる**
+ *   (`AGENTS.md` の共通規約 (b) が禁じる形)。
+ */
+enum ReceiverResolution
+{
+    /** 完全修飾名まで解決できた。 */
+    case Resolved;
+
+    /**
+     * 受け手は書かれているが、静的には確定できない。
+     *
+     * 変数 (`$gateway::`) / 遅延静的束縛 (`static::`) / 親クラス (`parent::`) /
+     * 式の結果 (`foo()::`) など。利用側は**拾いすぎる方向**へ倒して扱う。
+     */
+    case Unresolved;
+
+    /** そもそも受け手を持たない種別 (`NameReference` / `Construction` / `MethodCall`)。 */
+    case Absent;
+}
diff --git a/tests/Support/ReferenceScanResult.php b/tests/Support/ReferenceScanResult.php
index c74d37f2..acd2f509 100644
--- a/tests/Support/ReferenceScanResult.php
+++ b/tests/Support/ReferenceScanResult.php
@@ -16,7 +16,14 @@
 {
     /**
      * @param  list<ReferenceSite>  $sites
-     * @param  array<string, string>  $imports  小文字 short name => FQCN (`use` 宣言の全件)
+     * @param  array<string, string>  $imports  小文字 short name => FQCN。
+     *                                          **ファイルスコープの `use` のうちクラス / 名前空間の
+     *                                          import だけ**が載る (クラス本体の trait 取り込みと
+     *                                          `use function` / `use const` は載らない)。
+     *                                          **ファイル全体を 1 つの表へ畳んだ結果**なので、
+     *                                          namespace ブロックが複数あって同じ短縮名を使う場合は
+     *                                          後のブロックが勝つ。名前解決そのものは
+     *                                          ブロックごとの表で行っており、この表は使っていない
      */
     public function __construct(
         public array $sites,
diff --git a/tests/Support/ReferenceSite.php b/tests/Support/ReferenceSite.php
index e6ab1373..e8b4cfc8 100644
--- a/tests/Support/ReferenceSite.php
+++ b/tests/Support/ReferenceSite.php
@@ -10,6 +10,10 @@
  * ★`tokenIndex` を持たせるのは、呼び出し引数の分類 (`ExternalClientBoundaryScanner` の
  *   disk 名判定) のように「site の直後のトークン列」を見たい利用者があるため。
  *   走査器の内部表現を漏らさずに済ませる唯一の実用的な逃げ道である。
+ * ★`receiver` は**解決状態つきの値** (`ReceiverName`) である。「受け手が無い」と
+ *   「解決できなかった」を 1 つの null へ潰さないため、利用側の判定を読めば
+ *   未解決をどう扱っているかが分かる。**未解決を拾う側へ倒すかどうかは利用側の判断**であり、
+ *   型がそれを強制するわけではない (`ReceiverName` の docblock を参照)。
  */
 final readonly class ReferenceSite
 {
@@ -20,8 +24,8 @@ public function __construct(
         public ReferenceKind $kind,
         /** 名前参照 / 構築なら解決済み FQCN、呼び出しならメソッド名 */
         public string $name,
-        /** 呼び出しの receiver を解決できた場合の FQCN (できなければ null) */
-        public ?string $receiver,
+        /** 静的呼び出しの受け手 (解決結果。受け手を持たない種別は `ReceiverName::absent()`) */
+        public ReceiverName $receiver,
         /** 名前が完全修飾 / 修飾名として書かれていたか (alias 経由なら false) */
         public bool $qualified,
         public ScanScopeKind $scopeKind,
diff --git a/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php b/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php
index d1c8f948..8b190a15 100644
--- a/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php
+++ b/tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php
@@ -285,3 +285,77 @@ class Sample { public function f(SnsClient $s): S3Client { return new S3Client([
         ['rule' => 'new_external_object', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
     ]);
 });
+
+test('先頭要素を import した部分修飾名 (S3\S3Client) を解決して検出する', function (): void {
+    // T226: 部分修飾名を解決しなかった頃は `S3\S3Client` のまま照合され、
+    // 到達境界の接頭辞 `Aws\` に一致せず**無言で見逃されていた**。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    use Aws\S3;
+    class Sample { public function f(): void { $client = new S3\S3Client([]); } }
+    PHP;
+
+    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([
+        ['rule' => 'new_external_object', 'name' => 'Aws\S3\S3Client', 'class' => 'App\Gate\Sample', 'scope' => 'NamedClass'],
+    ]);
+});
+
+test('名前空間相対の部分修飾名を到達境界と取り違えない', function (): void {
+    // 先頭要素の import が無い部分修飾名は**現在の名前空間の下**に解決される。
+    // 解決しなかった頃は字面 `Aws\Bridge` が接頭辞 `Aws\` に一致し、
+    // 自前クラスを到達境界として**誤検出**していた。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    class Sample { public function f(): void { $bridge = new Aws\Bridge(); } }
+    PHP;
+
+    expect(scannerSummary(ExternalClientBoundaryScanner::scan('app/Gate/Sample.php', $source)))->toBe([]);
+});
+
+test('受け手を静的に決められない大域 setter は fail-closed で検出する', function (): void {
+    // 受け手が変数 / 遅延静的束縛の静的呼び出しは FQCN を確定できない。
+    // **未解決を黙って候補から外さない** (規約 (b))。変数経由に書き換えるだけで
+    // プロセス大域状態への到達が目録から消えては困る。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    class Sample {
+        public function f(string $requestor): void {
+            $requestor::setHttpClient($this->client);
+            static::setMaxNetworkRetries(0);
+        }
+    }
+    PHP;
+
+    expect(array_column(ExternalClientBoundaryScanner::stripeGlobalSites('app/Gate/Sample.php', $source), 'name'))
+        ->toBe(['setHttpClient', 'setMaxNetworkRetries']);
+});
+
+test('trait 本体の self:: 経由の大域 setter も fail-closed で検出する', function (): void {
+    // trait のコードは利用クラスへ展開されるため `self` は静的に決まらない。
+    // trait 自身へ解決してしまうと、この書き方で目録を抜けられる。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    trait UsesGateway {
+        public function f(): void { self::setHttpClient($this->client); }
+    }
+    PHP;
+
+    expect(array_column(ExternalClientBoundaryScanner::stripeGlobalSites('app/Gate/UsesGateway.php', $source), 'name'))
+        ->toBe(['setHttpClient']);
+});
+
+test('同じ名前空間の裸の受け手は解決され、大域 setter と取り違えない', function (): void {
+    // import の無い短縮名の受け手は現在の名前空間の下に解決される
+    // (`App\Gate\Registry`)。Stripe 名前空間ではないので検出しない。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Gate;
+    class Sample { public function f(): void { Registry::instance(); } }
+    PHP;
+
+    expect(ExternalClientBoundaryScanner::stripeGlobalSites('app/Gate/Sample.php', $source))->toBe([]);
+});
diff --git a/tests/Unit/Architecture/ExternalSeamScannerTest.php b/tests/Unit/Architecture/ExternalSeamScannerTest.php
index 0661a413..4fce4acf 100644
--- a/tests/Unit/Architecture/ExternalSeamScannerTest.php
+++ b/tests/Unit/Architecture/ExternalSeamScannerTest.php
@@ -106,22 +106,34 @@ public function go(object $client): mixed
 });
 
 test('走査器: new Stripe\StripeClient を payment_client_construction として検出する', function (): void {
+    // ★見本は完全修飾と import の 2 形で書く。`namespace App\Services\Billing;` の中で
+    //   `new Stripe\StripeClient(...)` と書くと PHP は
+    //   `App\Services\Billing\Stripe\StripeClient` を指すので、決済 client の見本にならない
+    //   (部分修飾名を解決するようになって初めてこの取り違えが見える)。
     $source = <<<'PHP'
     <?php
     namespace App\Services\Billing;
+    use Stripe\StripeClient;
     final class Probe
     {
         public function go(): mixed
         {
-            return new Stripe\StripeClient(['api_key' => 'sk_test']);
+            return new StripeClient(['api_key' => 'sk_test']);
+        }
+
+        public function goQualified(): mixed
+        {
+            return new \Stripe\StripeClient(['api_key' => 'sk_test']);
         }
     }
     PHP;
 
     $result = ExternalSeamScanner::scan('probe.php', $source);
 
-    expect(externalSeamRuleValues(...$result->adopted))
-        ->toBe([ExternalSeamRule::PaymentClientConstruction->value]);
+    expect(externalSeamRuleValues(...$result->adopted))->toBe([
+        ExternalSeamRule::PaymentClientConstruction->value,
+        ExternalSeamRule::PaymentClientConstruction->value,
+    ]);
 });
 
 test('走査器: Stripe\HttpClient\CurlClient の new は検出しない', function (): void {
@@ -430,10 +442,9 @@ public function go(): mixed
         ->and($result->adopted[0]->callable)->toBe('go');
 });
 
-test('走査器: 部分修飾名は解決しない (既存 gate と同じ限界を固定する)', function (): void {
-    // T_NAME_QUALIFIED は現在の namespace への相対解決も先頭 segment の alias 解決も
-    // 行わない。既存 ExternalClientBoundaryScanner と同じ限界であり、抽出は
-    // 振る舞い保存が目的なので直さない (直すと T126 の母集団が変わる)。
+test('走査器: 先頭要素を import した部分修飾名 (Facades\Http) を検出する', function (): void {
+    // T_NAME_QUALIFIED は先頭要素を import 表で置き換えて解決する。
+    // 解決しなかった頃はこの形が目録に出ず、外部到達点が無言で見逃されていた (T226 で是正)。
     $source = <<<'PHP'
     <?php
     namespace App\Services;
@@ -449,7 +460,72 @@ public function go(): mixed
 
     $result = ExternalSeamScanner::scan('probe.php', $source);
 
-    expect($result->adopted)->toBe([]);
+    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::HttpFacadeReference->value])
+        ->and($result->adopted[0]->class)->toBe('App\Services\Probe')
+        ->and($result->adopted[0]->callable)->toBe('go');
+});
+
+test('走査器: 先頭要素を import した部分修飾名の Cashier\Cashier::stripe() を検出する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services\Billing;
+    use Laravel\Cashier;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return Cashier\Cashier::stripe();
+        }
+    }
+    PHP;
+
+    $result = ExternalSeamScanner::scan('probe.php', $source);
+
+    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::PaymentClientCall->value])
+        ->and($result->suppressed)->toBe([]);
+});
+
+test('走査器: 受け手を静的に決められない ::stripe() は fail-closed で採用する', function (): void {
+    // 受け手が変数の静的呼び出しは FQCN を確定できない。**未解決を黙って候補から外さない**
+    // (規約 (b))。決済 client の取り出しを変数経由に書き換えるだけで目録を抜けられては困る。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services\Billing;
+    final class Probe
+    {
+        public function go(string $gateway): mixed
+        {
+            return $gateway::stripe();
+        }
+    }
+    PHP;
+
+    $result = ExternalSeamScanner::scan('probe.php', $source);
+
+    expect(externalSeamRuleValues(...$result->adopted))->toBe([ExternalSeamRule::PaymentClientCall->value])
+        ->and($result->suppressed)->toBe([]);
+});
+
+test('走査器: 名前空間相対の部分修飾名を外部到達点と取り違えない', function (): void {
+    // 先頭要素の import が無い部分修飾名は**現在の名前空間の下**に解決される。
+    // `App\Services\Billing\Cashier\Cashier` は決済 facade ではないので採用しない。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services\Billing;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return Cashier\Cashier::stripe();
+        }
+    }
+    PHP;
+
+    $result = ExternalSeamScanner::scan('probe.php', $source);
+
+    // `stripe` はメソッド名一致でも拾う規則を持たない (静的呼び出しは receiver 一致が要る)。
+    expect($result->adopted)->toBe([])
+        ->and($result->suppressed)->toBe([]);
 });
 
 test('走査器: 同名 alias (use ... as Http) を解決する', function (): void {
diff --git a/tests/Unit/Architecture/PhpReferenceScannerTest.php b/tests/Unit/Architecture/PhpReferenceScannerTest.php
new file mode 100644
index 00000000..0a7ebfdf
--- /dev/null
+++ b/tests/Unit/Architecture/PhpReferenceScannerTest.php
@@ -0,0 +1,450 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\ReceiverResolution;
+use Tests\Support\ReferenceKind;
+use Tests\Support\ReferenceSite;
+
+/*
+ * 中立走査器 `PhpReferenceScanner` の**名前解決**を合成ソースで固定する (T226)。
+ *
+ * ★負例 (わざと部分修飾で書いた参照を解決できること) と
+ *   正例 (名前空間相対の同名クラスを外部クラスと取り違えないこと) の**両方向**を置く
+ *   (`AGENTS.md` の共通規約 (c))。
+ * ★受け手を静的に決められない静的呼び出しは `ReceiverResolution::Unresolved` として返る。
+ *   **無言で候補から外さない**ことがこの走査器の契約である (同 (b))。
+ *   利用側でそれがどう効くかは `ExternalSeamScannerTest` /
+ *   `ExternalClientBoundaryScannerTest` が押さえている。
+ * ★期待値は PHP 自身の名前解決規則と同じである (`namespace` ブロックごとの import 表の
+ *   作り直し / `use` は宣言より前の参照に効かないこと、はいずれも php 8.4 で実測した)。
+ */
+
+/**
+ * 名前参照 / 構築の site 名だけを取り出す。
+ *
+ * @param  list<ReferenceSite>  $sites
+ * @return list<string>
+ */
+function referenceNames(array $sites): array
+{
+    return array_values(array_map(
+        static fn (ReferenceSite $site): string => $site->name,
+        array_filter(
+            $sites,
+            static fn (ReferenceSite $site): bool => $site->kind === ReferenceKind::NameReference
+                || $site->kind === ReferenceKind::Construction,
+        ),
+    ));
+}
+
+/**
+ * 静的呼び出しの site を「メソッド名 => 受け手の解決状態」で取り出す。
+ *
+ * @param  list<ReferenceSite>  $sites
+ * @return list<array{name: string, resolution: string, receiver: string|null}>
+ */
+function staticCallReceivers(array $sites): array
+{
+    return array_values(array_map(
+        static fn (ReferenceSite $site): array => [
+            'name' => $site->name,
+            'resolution' => $site->receiver->resolution->name,
+            'receiver' => $site->receiver->isResolved() ? $site->receiver->fqcn() : null,
+        ],
+        array_filter($sites, static fn (ReferenceSite $site): bool => $site->kind === ReferenceKind::StaticCall),
+    ));
+}
+
+// ── 部分修飾名の解決 (負例: 従来は解決できず見逃していた形) ─────────────
+
+test('先頭要素を import した部分修飾名を完全修飾名まで解決する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    use Illuminate\Support\Facades;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return Facades\Http::get('https://example.test');
+        }
+    }
+    PHP;
+
+    $result = PhpReferenceScanner::references('app/Services/Probe.php', $source);
+
+    expect(referenceNames($result->sites))->toBe(['Illuminate\Support\Facades\Http'])
+        ->and(staticCallReceivers($result->sites))->toBe([
+            ['name' => 'get', 'resolution' => 'Resolved', 'receiver' => 'Illuminate\Support\Facades\Http'],
+        ]);
+});
+
+test('別名で import した先頭要素の部分修飾名を解決する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    use Illuminate\Support\Facades as F;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new F\Http();
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
+        ->toBe(['Illuminate\Support\Facades\Http']);
+});
+
+test('グループ use で取り込んだ先頭要素に部分修飾名を続ける形を解決する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    use Aws\{S3, Sns};
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new S3\S3Client([]);
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
+        ->toBe(['Aws\S3\S3Client']);
+});
+
+test('import の無い部分修飾名は現在の名前空間の下に解決する (正例: 取り違えない)', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new Aws\Bridge();
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
+        ->toBe(['App\Services\Aws\Bridge']);
+});
+
+test('名前空間を持たないファイルの部分修飾名はそのまま大域の名前になる', function (): void {
+    $source = <<<'PHP'
+    <?php
+    $client = new Aws\Bridge();
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('routes/web.php', $source)->sites))
+        ->toBe(['Aws\Bridge']);
+});
+
+test('名前空間相対の名前 (namespace\Foo) を解決して site にする', function (): void {
+    // 従来は `T_NAME_RELATIVE` を 1 件も emit していなかった = 無言の取りこぼし。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new namespace\Helper();
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
+        ->toBe(['App\Services\Helper']);
+});
+
+test('完全修飾名は先頭の区切りだけを落とす (従来どおり)', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new \Aws\S3\S3Client([]);
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
+        ->toBe(['Aws\S3\S3Client']);
+});
+
+// ── import 表の作り方 ─────────────────────────────────────────────────
+
+test('import 表は namespace ブロックごとに作り直す', function (): void {
+    // php 8.4 実測: 前のブロックの `use ... as Sub;` は次のブロックの `Sub\Y` を解決しない。
+    $source = <<<'PHP'
+    <?php
+    namespace First { use Aws\S3 as Sub; }
+    namespace Second {
+        final class Probe
+        {
+            public function go(): mixed
+            {
+                return new Sub\S3Client([]);
+            }
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Probe.php', $source)->sites))
+        ->toBe(['Second\Sub\S3Client']);
+});
+
+test('use function / use const は同名でもクラスの import 表を上書きしない', function (): void {
+    // クラスの別名表へ関数名が入ると `S3\S3Client` が別名側で解決され、外部到達点を見逃す。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    use Aws\S3;
+    use function App\Support\s3 as S3;
+    use const App\Support\S3 as S3;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new S3\S3Client([]);
+        }
+    }
+    PHP;
+
+    $result = PhpReferenceScanner::references('app/Services/Probe.php', $source);
+
+    expect($result->imports)->toBe(['s3' => 'Aws\S3'])
+        ->and(referenceNames($result->sites))->toBe(['Aws\S3\S3Client']);
+});
+
+test('グループ use の内側の function / const も別名表へ入れない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    use Aws\{S3, function Support\s3 as Sns, const Support\SNS as Sns};
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new S3\S3Client([]);
+        }
+    }
+    PHP;
+
+    $result = PhpReferenceScanner::references('app/Services/Probe.php', $source);
+
+    expect($result->imports)->toBe(['s3' => 'Aws\S3'])
+        ->and(referenceNames($result->sites))->toBe(['Aws\S3\S3Client']);
+});
+
+test('グループ use は function / const 要素の**次**のクラス要素を取りこぼさない', function (): void {
+    // ★要素ごとの種別フラグを区切りで戻し忘れると、typed 要素より後ろのクラス import が
+    //   丸ごと落ちて部分修飾名を解決できなくなる (前の test だけでは検出できない向き)。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    use Aws\{function Support\s3 as Helper, S3, const Support\SNS as Marker, Sns};
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return [new S3\S3Client([]), new Sns\SnsClient([])];
+        }
+    }
+    PHP;
+
+    $result = PhpReferenceScanner::references('app/Services/Probe.php', $source);
+
+    expect($result->imports)->toBe(['s3' => 'Aws\S3', 'sns' => 'Aws\Sns'])
+        ->and(referenceNames($result->sites))->toBe(['Aws\S3\S3Client', 'Aws\Sns\SnsClient']);
+});
+
+test('import の無い短縮名でも new の直後なら現在の名前空間の下に解決する', function (): void {
+    // ★ファイル自身の名前空間が対象と同じ場合の見逃しを塞ぐ
+    //   (`namespace Stripe;` の中の `new StripeClient()` は `Stripe\StripeClient` である)。
+    $source = <<<'PHP'
+    <?php
+    namespace Stripe;
+    final class Probe
+    {
+        public function go(): mixed
+        {
+            return new StripeClient(['api_key' => 'sk_test']);
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Probe.php', $source)->sites))
+        ->toBe(['Stripe\StripeClient']);
+});
+
+test('new self / new static は名前解決の対象にしない', function (): void {
+    // `App\Services\self` のような実在しない FQCN を作らない (偽陽性の元になる)。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe
+    {
+        public function go(): self
+        {
+            return new self();
+        }
+
+        public function late(): static
+        {
+            return new static();
+        }
+    }
+    PHP;
+
+    expect(referenceNames(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))
+        ->toBe([]);
+});
+
+test('クラス本体の use (trait 取り込み) は import 表を上書きしない', function (): void {
+    // 上書きすると `billable => 'Billable'` になり、ファイル先頭の import が持つ FQCN を失う
+    // (= trait 経由の参照が丸ごと消える fail-open)。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Models;
+    use Laravel\Cashier\Billable;
+    final class Organization
+    {
+        use Billable;
+
+        public function go(): Billable
+        {
+            return $this;
+        }
+    }
+    PHP;
+
+    $result = PhpReferenceScanner::references('app/Models/Organization.php', $source);
+
+    expect($result->imports)->toBe(['billable' => 'Laravel\Cashier\Billable'])
+        ->and(referenceNames($result->sites))->toBe(['Laravel\Cashier\Billable']);
+});
+
+// ── 静的呼び出しの受け手 (fail-closed) ────────────────────────────────
+
+test('受け手を静的に決められない静的呼び出しは未解決として返す', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe extends Base
+    {
+        public function go(string $gateway): void
+        {
+            $gateway::make();
+            static::make();
+            parent::make();
+        }
+    }
+    PHP;
+
+    expect(staticCallReceivers(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))->toBe([
+        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
+        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
+        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
+    ]);
+});
+
+test('式の結果を受け手にした静的呼び出しも未解決として返す', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe
+    {
+        public function go(): void
+        {
+            factory()::make();
+            (new Registry())::make();
+        }
+    }
+    PHP;
+
+    expect(staticCallReceivers(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))->toBe([
+        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
+        ['name' => 'make', 'resolution' => 'Unresolved', 'receiver' => null],
+    ]);
+});
+
+test('self:: は囲みのクラスへ、import の無い短縮名は現在の名前空間の下へ解決する', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe
+    {
+        public function go(): void
+        {
+            self::make();
+            Registry::make();
+        }
+    }
+    PHP;
+
+    expect(staticCallReceivers(PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites))->toBe([
+        ['name' => 'make', 'resolution' => 'Resolved', 'receiver' => 'App\Services\Probe'],
+        ['name' => 'make', 'resolution' => 'Resolved', 'receiver' => 'App\Services\Registry'],
+    ]);
+});
+
+test('trait 本体の self:: は未解決にする (取り込んだクラスを指すため)', function (): void {
+    // ★trait のコードは利用クラスへ展開されるので `self` は trait 自身ではない。
+    //   複数のクラスが同じ trait を取り込めるため、走査時点では 1 つに決まらない。
+    //   trait 自身の FQCN として解決すると、利用側は未解決として拾えず無言の見逃しになる。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Support;
+    trait UsesGateway
+    {
+        public function run(): void
+        {
+            self::setHttpClient(null);
+        }
+    }
+    final class Direct
+    {
+        public function run(): void
+        {
+            self::setHttpClient(null);
+        }
+    }
+    PHP;
+
+    expect(staticCallReceivers(PhpReferenceScanner::references('app/Support/UsesGateway.php', $source)->sites))->toBe([
+        ['name' => 'setHttpClient', 'resolution' => 'Unresolved', 'receiver' => null],
+        ['name' => 'setHttpClient', 'resolution' => 'Resolved', 'receiver' => 'App\Support\Direct'],
+    ]);
+});
+
+test('受け手を持たない種別の receiver は Absent で、完全修飾名を取り出すと例外になる', function (): void {
+    $source = <<<'PHP'
+    <?php
+    namespace App\Services;
+    final class Probe
+    {
+        public function go(object $client): mixed
+        {
+            return $client->send();
+        }
+    }
+    PHP;
+
+    $sites = PhpReferenceScanner::references('app/Services/Probe.php', $source)->sites;
+    $methodCalls = array_values(array_filter(
+        $sites,
+        static fn (ReferenceSite $site): bool => $site->kind === ReferenceKind::MethodCall,
+    ));
+
+    expect($methodCalls)->toHaveCount(1)
+        ->and($methodCalls[0]->receiver->resolution)->toBe(ReceiverResolution::Absent)
+        ->and(static fn (): string => $methodCalls[0]->receiver->fqcn())->toThrow(LogicException::class);
+});

```

## テスト結果 (修正後)

- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `vendor/bin/pest tests/Unit/Architecture/` : 287 passed / 0 failed
- `composer test` の全数は最終確認として実行中。結果が green であることを確認してから完了報告する

残っている指摘があれば挙げてほしい。最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で書くこと。

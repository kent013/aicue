# Round 5: Round 4 の指摘への対応

**これが延長分の最後のラウンドである** (当初上限 3 → 監督者裁量で 5 まで)。

Round 4 で「承認を阻む」とされた **2 件はどちらも対応した**。
Suggestion は 4 件中 3 件を対応し、1 件 (`recordOrFail()` の caller gate) を
**理由を書いて見送った** — Codex 自身が承認阻害ではないと明示した項目である。
反論は 0 件。

---

# 対応マトリクス: impl-review Round 4

Codex の全体判定は `CHANGES_REQUESTED`。**承認を阻む 2 件**と、
**阻まない項目 (Suggestion) 4 件**に分けて返ってきた。

**承認を阻む 2 件は対応した**。Suggestion は 4 件中 3 件を対応し、1 件を見送った
(見送りの理由は下記)。反論は 0 件である。

---

## [承認を阻む] 二段の公開により、`confirm()` の token 検証と本人結合を迂回できる

- **判断**: 対応する
- **根拠**: **指摘が正しい**。Round 3 の対応で `consumeToken()` / `applyConfirmedEmail()` を
  `public` にしたが、これは**テスト容易性のために本番の操作面を広げた**ものだった。
  `applyConfirmedEmail($user, VerifiedEmail::afterConfirmation('attacker@example.com'))` は
  promotion 行も指紋照合も期限も本人結合も要らずに通る。
  `VerifiedEmail` は「トークンを正当に消費した結果」であることを表せない値なので、
  capability としても機能しない。指摘のとおり
  **「2 段構成がサービス内部の契約であること」と「各段を誰でも個別に呼べること」は別**である。
- **対応内容**: Codex が挙げた選択肢のうち**「継ぎ目だけを協力者として外に出す」**を採った。
  - `App\Contracts\Auth\EmailPromotionStageBoundary` を新設。メソッドは
    `afterConsume(User $user): void` の 1 本だけである。
  - 本番実装 `App\Services\Auth\InertEmailPromotionStageBoundary` は**何もしない**
    (`Inert` = 不活性、という名前で「処理が足されたらレビューで目に入る」形にした)。
    `AppServiceProvider::register()` で bind する。
  - **`consumeToken()` / `applyConfirmedEmail()` を `private` に戻した**。
    公開の入口は `confirm()` 1 本だけである = 操作面は Round 2 以前と同一に戻った。
  - `confirm()` は第 1 段が戻った直後に `$this->stageBoundary->afterConsume($user)` を呼ぶ。
    継ぎ目が**できるのは「その時点で任意のコードが走る」ことだけ**であり、
    メールを書くこともトークンを消費することもできない。
  - 検査は `Tests\Support\Auth\InterferingEmailPromotionStageBoundary` を container へ
    差し込み、**入口は `confirm()` のまま**割り込みを起こす。
- **回帰テスト**:
  - `EmailPromotionTest`「本番の継ぎ目は何もしない (公開入口は confirm() 1 本のまま)」 —
    container が解決するのが `Inert…` であることと、**2 段が `private` であること**を
    reflection で固定した (公開へ戻したら赤くなる)。
  - 既存の割り込みテストと正のコントロールを `confirm()` 経由へ書き直した。

---

## [承認を阻む] 走査器の深さ管理が `T_ATTRIBUTE` / 文字列内挿の開始 token を扱わない

- **判断**: 対応する
- **根拠**: **指摘が正しい**。`php -r` で token 列を実測して確認した:
  - attribute の開きは `T_ATTRIBUTE` (text は `#[`) であり、素の `[` ではない。
    開きとして数えないのに閉じの `]` だけ数えるので、**そこから深さが 1 つずれる**。
  - `"${x}"` の開きは `T_DOLLAR_OPEN_CURLY_BRACES` (text は `${`)。同じくずれる。
  - `"{$x}"` の `T_CURLY_OPEN` は **text が `{`** なので旧実装でも偶然合っていた。
    ただし**偶然に依存しない**ので id でも明示した。
  旧実装 (Round 4 修正前) をそのまま再現して走らせ、
  **attribute 形も `${}` 形も違反 0 件 = 見逃し**になることを実測した (下記「実測」)。
- **対応内容**: 整数のカウンタを**区切りの stack** へ替えた。
  - 開きの判定を `closerForOpener()` に集約し、`T_ATTRIBUTE` → `]`、
    `T_DOLLAR_OPEN_CURLY_BRACES` / `T_CURLY_OPEN` → `}`、`(` `[` `{` → 対応する閉じ、とした。
  - 閉じでは stack を pop し、**期待と食い違えば「読み切れない」として落とす**
    ((b) fail-closed)。整数カウンタでは `([)]` のような壊れた対応を検出できない。
  - 引数を読み切っても開きが残っている形も「読み切れない」にした。
  - docblock に**保証しないもの**を書いた — first-class callable `fetch(...)` は
    引数を確定できないので**違反側**に落ちること、可変メソッド名は走査対象に入らないこと。
- **回帰テスト** (指摘された 3 方向 + 正例を揃えた):
  - 見本に 4 形を追加 (attribute の後に入れ子 = 違反 / `${}` の後に入れ子 = 違反 /
    attribute つきで外側 false = 正例 / 内挿 2 形つきで外側 false = 正例)。
    一括検査を **11 呼び出し / 違反 7 件 / 正例 4 件**へ更新した。
  - 自己検査に 6 本追加 (attribute 負例 / 内挿負例 / attribute + 内挿の正例 /
    閉じの種類が食い違う形 / 閉じ切らない形 / first-class callable)。

---

## [Suggestion] 割り込みテストの docblock は「commit 済み」ではなく「level が戻った」と書くべき

- **判断**: 対応する
- **根拠**: 指摘が正しい。`RefreshDatabase` の外側のトランザクションがあるので、
  第 1 段が閉じるのは**実際には savepoint** である。
  「本番の独立トランザクションの commit と同じ可視性を証明した」とは言えない。
- **対応内容**: 文言を「第 1 段が開いた層をすべて閉じ、呼び出し前の level へ戻った」に直し、
  さらに Suggestion のとおり**継ぎ目で `DB::transactionLevel()` を実測**して
  呼び出し前の値と一致することを固定した (「段を抜けた」を主張ではなく測定にした)。

---

## [Suggestion] `flushEventListeners()` は将来 trait / observer が足された瞬間に汚染へ変わる

- **判断**: 対応する
- **根拠**: 指摘が正しい。現状のモデル構成では実害が無いことは確認済みだが、
  **その安全性はモデル定義に依存していて、依存していることがコードから見えない**。
- **対応内容**: 後始末を `SecurityAuditEvent::flushEventListeners()` (そのモデルの
  **全 event** を静的に削除する) から **`Event::forget('eloquent.created: '.SecurityAuditEvent::class)`**
  へ替えた。**張った 1 つの event 名だけ**を忘れさせるので、
  モデルに trait / observer が足されても他の購読を巻き込まない。

---

## [Suggestion] first-class callable `fetch(...)` の扱いを docblock に明記する

- **判断**: 対応する
- **対応内容**: 走査器の docblock「保証しないもの」に 1 行書き、
  **自己検査でも固定した** (書いただけにしない)。

---

## [Suggestion] `recordOrFail()` の使用者集合を Architecture gate で exact-fit に固定する

- **判断**: **見送る** (このラウンドでは作らない)
- **根拠**: Codex 自身が「今回の T253 実装の正確性は実挙動テストで既に固定されているため、
  **これは承認阻害とはしない**」と明示している。そのうえで見送る理由は 2 つある。
  1. **本リポジトリで gate を新設する費用が小さくない**。AGENTS.md
     「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」は、負例と正例・
     解決できない形を落とす分岐・母集団の空振り検査・docblock の 4 点を要求する。
     可変メソッド名 (`$r->{$m}()`) を保証範囲から外すなら、利用側 gate は
     (b) に従って**検出力の主張をその構文を除く形へ狭める**必要もある。
  2. **合議の残りが 1 ラウンドしかない**。この状況で新しい走査器を足すと、
     **その走査器自体が未レビューのまま残る**。Round 2・Round 3・Round 4 は
     いずれも「新しく足した走査器」に指摘が出ており、同じことを繰り返す確率が高い。
- **代わりに置いたもの**: `recordOrFail()` の docblock に**許される 2 つの呼び出し元を名指し**し、
  書き分けの軸 (「確定した変更を同じトランザクションで記録する」か「試行を観測するだけ」か) を
  正本として書いた (Round 4 で `APPROVE` を得ている)。
  gate 化は**別 TODO の候補**として残す。

---

## Round 4 で APPROVE / 承認阻害でないとされた点 (変更しない)

- メール更新と監査の原子性 (`recordOrFail()` を第 2 段のトランザクションの中で呼ぶ)
- 通常の括弧・配列・波括弧に対する深さ判定と、その負例・正例の対
- 監査ロールバックのテストの壊し方 (`created` で挿入後に壊し、行の存在を確認してから例外)
- `SecurityEventRecorder` の docblock の書き分けの軸
- `OidcDiscoveryService::release()` の best-effort + 固定文言の warning
---

## 実測 (走査器の見逃しの再現と、修正後の検出)

Round 4 の指摘 2 を**実測で裏取り**した。`Round 4 修正前の実装`
(整数カウンタ・text だけで開閉を判定) をそのまま再現して同じ入力に当てた結果である。

```text
=== 旧実装 (Round 4 修正前) ===
attribute 形  : array ()   <= 空 = 見逃し
${} 内挿形     : array ()   <= 空 = 見逃し
=== 現行実装 (stack) ===
attribute 形  : array ( 0 => 'a.php:2: fetch() に followRedirects: が無い' )
${} 内挿形     : array ( 0 => 'b.php:2: fetch() に followRedirects: が無い' )
```

入力はそれぞれ次である。

```php
$this->pinned->fetch(
    #[Probe]
    fn () => $this->build(followRedirects: false),
    $deadline,
);

$this->pinned->fetch("${label}", $this->build(followRedirects: false), $deadline);
```

token 列も実測した (PHP 8.4.24)。**`#[` は `T_ATTRIBUTE`、`${` は
`T_DOLLAR_OPEN_CURLY_BRACES`、`{$` は `T_CURLY_OPEN` (text は `{`)** である。

```text
T_STRING   fetch
(char)     (
T_ATTRIBUTE  #[        ← 旧実装は開きとして数えない
T_STRING   Probe
(char)     ]           ← しかし閉じは数える = ここで深さがずれる
...
T_DOLLAR_OPEN_CURLY_BRACES  ${   ← 同上
T_STRING_VARNAME            b
(char)                      }
```

したがって `T_CURLY_OPEN` については **Codex の指摘のうち「深さが崩れる」は当たらない**
(text が `{` なので旧実装でも増えていた)。ただし**偶然の一致に依存していた**ので、
修正では id でも明示した。指摘の 3 つのうち **2 つが実害、1 つが偶然セーフ**という結果である。

さらに、修正の負例が実際に噛むことを**一時的に旧挙動へ戻して赤を確認**した
(`closerForOpener()` の id 判定 2 分岐を外すと、自己検査 21 本のうち 4 本が赤くなる)。

---

## 検証コマンドの結果

- `composer test -- tests/Feature/Auth/EmailPromotionTest.php`: **43 / 43 passed** (208 assertions)
- `composer test -- tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php`: **21 / 21 passed**
- `composer test -- tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php`: **5 / 5 passed**
- `composer phpstan`: **OK (level 10)** / `vendor/bin/pint`: 適用済み
- 全レーン (`composer test` 全数 / `composer phpstan` / `pint --test`) は**このプロンプトと並行して実行中**。
  結果は返答を受けてから報告する (赤が出た場合はその内容も含めて報告する)。
  なお Round 4 提出時点の全レーンは 7279 tests / phpstan level 10 / pint いずれも green だった。

---

## 対応の差分 (Round 4 レビュー時点 → 現在・全文)

```diff
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 36ad1d34..1061f3d8 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -6,6 +6,7 @@
 
 use App\Auth\EncryptedUserProvider;
 use App\Auth\Guards\ApiKeyGuard;
+use App\Contracts\Auth\EmailPromotionStageBoundary;
 use App\Http\Routing\MembershipScopedOrganizationBinder;
 use App\Http\Routing\PublicOidcConnectionBinder;
 use App\Http\Routing\RouteBindingTypes;
@@ -24,6 +25,7 @@
 use App\Models\Organization;
 use App\Models\User;
 use App\Notifications\Channels\OrganizationScopedDatabaseChannel;
+use App\Services\Auth\InertEmailPromotionStageBoundary;
 use App\Services\Billing\CashierAutoRechargeGateway;
 use App\Services\Billing\CashierStripeGateway;
 use App\Services\Billing\CashierTicketCheckoutGateway;
@@ -118,6 +120,12 @@ public function register(): void
         });
         $this->app->bind(SnsSignatureVerifier::class, AwsSnsSignatureVerifier::class);
 
+        // メールアドレスの昇格 (T253) の 2 段の継ぎ目。**本番は何もしない実装**であり、
+        // 継ぎ目に名前を与えるためだけに存在する (段そのものを公開メソッドにすると
+        // トークンの照合を迂回する第 2 の入口ができるため。理由は
+        // App\Contracts\Auth\EmailPromotionStageBoundary の docblock が正本)。
+        $this->app->bind(EmailPromotionStageBoundary::class, InertEmailPromotionStageBoundary::class);
+
         // Critical Action 実行中フラグ。scoped() で HTTP request scope に閉じる
         // (queue worker / artisan は別 container のため context は継承されない)
         $this->app->scoped(CriticalActionContext::class);
diff --git a/app/Services/Auth/EmailPromotionService.php b/app/Services/Auth/EmailPromotionService.php
index 046e202c..7ace8544 100644
--- a/app/Services/Auth/EmailPromotionService.php
+++ b/app/Services/Auth/EmailPromotionService.php
@@ -5,6 +5,7 @@
 namespace App\Services\Auth;
 
 use App\Actions\Fortify\UpdateUserProfileInformation;
+use App\Contracts\Auth\EmailPromotionStageBoundary;
 use App\DataTransferObjects\Auth\VerifiedEmail;
 use App\Enums\EnterpriseSso\FingerprintPurpose;
 use App\Enums\SecurityEventType;
@@ -81,7 +82,10 @@
     /** PostgreSQL の unique_violation。 */
     private const string SQLSTATE_UNIQUE_VIOLATION = '23505';
 
-    public function __construct(private SecurityEventRecorder $recorder) {}
+    public function __construct(
+        private SecurityEventRecorder $recorder,
+        private EmailPromotionStageBoundary $stageBoundary,
+    ) {}
 
     /**
      * 昇格を始める (確認メールを送る)。
@@ -145,20 +149,24 @@ public function confirm(User $user, #[SensitiveParameter] string $token): bool
             return false;
         }
 
+        // ★第 1 段のトランザクションは閉じている。**ここが 2 段の継ぎ目**であり、
+        //   本番実装は何もしない (継ぎ目の存在理由は EmailPromotionStageBoundary の docblock)。
+        $this->stageBoundary->afterConsume($user);
+
         return $this->applyConfirmedEmail($user, $email);
     }
 
     /**
      * **第 1 段**: トークンを検査して消費を確定させる (ここで commit される)。
      *
-     * ★`confirm()` の入口から呼ぶのが通常の使い方である。**段を公開しているのは、
-     *   2 段構成そのものが本サービスの契約だから**であり、
-     *   「第 1 段の commit と第 2 段の間に別経路が割り込む」順序を
-     *   テストが**実際の継ぎ目で**再現できるようにするためでもある。
+     * ★**private である**。公開すると `confirm()` が担っているトークンの照合・期限・
+     *   本人結合を迂回する第 2 の入口ができる (適用せずに他人の確認トークンを
+     *   不可逆に消費する呼び方も書けてしまう)。2 段の継ぎ目を検査から捕まえる手段は
+     *   {@see EmailPromotionStageBoundary} であって、段の公開ではない。
      *
      * @return VerifiedEmail|null null = トークンが無効・期限切れ・他人のもの・対象外
      */
-    public function consumeToken(User $user, #[SensitiveParameter] string $token): ?VerifiedEmail
+    private function consumeToken(User $user, #[SensitiveParameter] string $token): ?VerifiedEmail
     {
         $fingerprint = AttemptFingerprint::of(FingerprintPurpose::EmailPromotionToken, $token);
 
@@ -223,11 +231,15 @@ private function lockedSelf(User $user): ?User
      *   「メールは変わったのに記録が無い」状態が残る (設計 E1 が要求する記録が成立しない)。
      *   記録できなければメールの変更ごと巻き戻す。
      *
+     * ★**private である**。公開すると、トークンを 1 つも消費せずに任意の
+     *   `VerifiedEmail` を適用できる入口になる (`VerifiedEmail` は
+     *   「正当に消費した結果」であることを表せない値である)。
+     *
      * @return bool true = 適用した / false = 第 1 段の後にメールが入ったので適用しない
      *
      * @throws EmailPromotionConflictException
      */
-    public function applyConfirmedEmail(User $user, VerifiedEmail $email): bool
+    private function applyConfirmedEmail(User $user, VerifiedEmail $email): bool
     {
         try {
             // ★**自分のトランザクション (savepoint) の中で**書く。
diff --git a/app/Services/Security/SecurityEventRecorder.php b/app/Services/Security/SecurityEventRecorder.php
index d44934cc..86c65b79 100644
--- a/app/Services/Security/SecurityEventRecorder.php
+++ b/app/Services/Security/SecurityEventRecorder.php
@@ -7,6 +7,7 @@
 use App\Enums\SecurityEventType;
 use App\Models\SecurityAuditEvent;
 use App\Models\User;
+use App\Services\Auth\EmailPromotionService;
 use App\Services\OAuth\OrganizationAccessRevoker;
 
 /**
@@ -34,10 +35,18 @@ public function record(SecurityEventType $type, ?User $user, array $metadata = [
     /**
      * 監査記録 (握り潰さない)。**書けなければ呼び出し元のトランザクションごと巻き戻る**。
      *
-     * 「資格情報は失効したが、その事実が監査に残っていない」状態を作らないための版である。
-     * 組織アクセスの失効 ({@see OrganizationAccessRevoker}) だけがこれを使う。
-     * 認証系の記録 (ログイン失敗など) にこれを使ってはならない —
+     * 「状態は変わったのに、その事実が監査に残っていない」を作らないための版である。
+     * 使ってよいのは**確定した変更を同じトランザクションの中で記録する経路**だけである:
+     *
+     * - 組織アクセスの失効 ({@see OrganizationAccessRevoker})
+     *   — 「資格情報は失効したが監査に残っていない」を作らない
+     * - メールアドレスの昇格の確定 ({@see EmailPromotionService::applyConfirmedEmail()})
+     *   — 「メールは変わったが監査に残っていない」を作らない
+     *
+     * 逆に、**観測でしかない記録**にこれを使ってはならない。ログイン失敗・
+     * ログイン成功のような認証の試行そのものの記録は {@see record()} を使う —
      * 監査の失敗でログインそのものを落とすことになるためである。
+     * (昇格の確定はログインの経路ではなく、**利用者の属性を変える操作**である。)
      *
      * @param  array<string, mixed>  $metadata
      */
diff --git a/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt b/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt
index bd78af8d..1475c7ca 100644
--- a/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt
+++ b/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt
@@ -10,11 +10,15 @@ use Kent013\SsrfPin\PinnedHttpClient;
  * ★負例の見本。**安全な呼び出しと危険な呼び出しが同じファイルに同居する**形を置く。
  *   ファイル単位の部分文字列一致だと安全な方を見て緑になってしまう (それを赤にする)。
  *
- * 危険な形は 4 方向ある:
+ * 危険な形は 6 方向ある:
  *   1. 引数そのものが無い (既定の true が効く)
  *   2. `followRedirects: true` (明示的に追従する)
  *   3. 値が静的に確定できない (実行時に true になりうる)
  *   4. **入れ子の別の呼び出し**に同名の引数がある (外側には無いのに在るように見える)
+ *   5. 引数の中に **attribute (`#[…]`)** があり、その後ろの入れ子に同名の引数がある
+ *      (`#[` を開きとして数えないと、`]` だけが数えられて深さが 1 つずれる)
+ *   6. 引数の中に **`"${x}"` 形の文字列内挿**があり、その後ろの入れ子に同名の引数がある
+ *      (`${` を開きとして数えないと、`}` だけが数えられて深さが 1 つずれる)
  */
 final class RedirectFollowingSample
 {
@@ -57,4 +61,37 @@ final class RedirectFollowingSample
     {
         $this->pinned->fetch($this->makeRequest(followRedirects: false), $deadline);
     }
+
+    /** ★attribute の `#[` を開きとして数えないと、`]` で深さがずれてこれが緑になってしまう。 */
+    public function attributeThenNestedOnly(): void
+    {
+        $this->pinned->fetch(
+            #[Probe]
+            fn () => $this->makeRequest(followRedirects: false),
+            $deadline,
+        );
+    }
+
+    /** ★`${` を開きとして数えないと、`}` で深さがずれてこれが緑になってしまう。 */
+    public function interpolationThenNestedOnly(): void
+    {
+        $this->pinned->fetch("${label}", $this->makeRequest(followRedirects: false), $deadline);
+    }
+
+    /** ★正例その 3。attribute があっても**外側**が false なら通す。 */
+    public function attributeWithOuterFalse(): void
+    {
+        $this->pinned->fetch(
+            #[Probe]
+            fn () => $this->makeRequest(followRedirects: true),
+            $deadline,
+            followRedirects: false,
+        );
+    }
+
+    /** ★正例その 4。文字列内挿 2 形があっても**外側**が false なら通す。 */
+    public function interpolationWithOuterFalse(): void
+    {
+        $this->pinned->fetch("{$label}${suffix}", $this->makeRequest(followRedirects: true), $deadline, followRedirects: false);
+    }
 }
diff --git a/tests/Feature/Auth/EmailPromotionTest.php b/tests/Feature/Auth/EmailPromotionTest.php
index f5a09053..61e7274d 100644
--- a/tests/Feature/Auth/EmailPromotionTest.php
+++ b/tests/Feature/Auth/EmailPromotionTest.php
@@ -2,6 +2,7 @@
 
 declare(strict_types=1);
 
+use App\Contracts\Auth\EmailPromotionStageBoundary;
 use App\Enums\SecurityEventType;
 use App\Http\Requests\Auth\ConfirmEmailPromotionRequest;
 use App\Mail\EmailPromotionMail;
@@ -9,14 +10,17 @@
 use App\Models\SecurityAuditEvent;
 use App\Models\User;
 use App\Services\Auth\EmailPromotionService;
+use App\Services\Auth\InertEmailPromotionStageBoundary;
 use App\Support\EnterpriseSso\AttemptFingerprint;
 use Illuminate\Contracts\Queue\ShouldBeEncrypted;
 use Illuminate\Database\QueryException;
 use Illuminate\Log\Events\MessageLogged;
 use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Event;
 use Illuminate\Support\Facades\Log;
 use Illuminate\Support\Facades\Mail;
 use Illuminate\Support\Facades\Validator;
+use Tests\Support\Auth\InterferingEmailPromotionStageBoundary;
 
 /*
  * メールアドレスの昇格 (E1)。
@@ -463,24 +467,40 @@ function issuePromotion(User $user, string $email = 'new@corp.example'): string
         ->assertInertia(fn ($page) => $page->where('canPromoteEmail', false));
 });
 
+test('本番の継ぎ目は何もしない (公開入口は confirm() 1 本のまま)', function (): void {
+    // ★2 段を公開メソッドにしていないことの担保。継ぎ目の本番実装が不活性であること
+    //   (= 操作面が広がっていないこと) を container の解決で直接固定する。
+    expect(app(EmailPromotionStageBoundary::class))->toBeInstanceOf(InertEmailPromotionStageBoundary::class);
+
+    $reflection = new ReflectionClass(EmailPromotionService::class);
+    foreach (['consumeToken', 'applyConfirmedEmail'] as $stage) {
+        expect($reflection->getMethod($stage)->isPrivate())->toBeTrue(
+            "{$stage}() は private であること: 公開するとトークンの照合・期限・本人結合を迂回する"
+            .'第 2 の入口ができます。'
+        );
+    }
+});
+
 test('消費の確定と適用の間に別経路がメールを入れたら、その更新を上書きしない', function (): void {
     $user = promotionUser();
     $token = issuePromotion($user);
-    $service = app(EmailPromotionService::class);
 
-    // ★**実際の継ぎ目で**割り込む。第 1 段 (消費) が戻った時点で commit は済んでいる。
+    // ★**実際の継ぎ目で**割り込む。第 1 段 (消費) のトランザクションは既に閉じている。
     //   モデルイベントの listener に頼ると割り込みが**第 1 段の内側**で走ってしまい、
-    //   「commit の後」という筋書きにならない (かつ listener の全削除は後続テストを汚す)。
-    $email = $service->consumeToken($user, $token);
-    expect($email)->not->toBeNull();
+    //   「閉じた後」という筋書きにならない (かつ listener の全削除は後続テストを汚す)。
+    //   段そのものを公開する代わりに、継ぎ目だけを差し替える。
+    $boundary = new InterferingEmailPromotionStageBoundary(encryptedEmailFor('other@corp.example'));
+    $this->app->instance(EmailPromotionStageBoundary::class, $boundary);
 
-    // 別経路がメールを入れる (プロフィール更新など)
-    $user->newQuery()->whereKey($user->getKey())->update([
-        'email' => encryptedEmailFor('other@corp.example'),
-    ]);
+    $levelBefore = DB::transactionLevel();
 
-    // ★第 2 段はロックの下で読み直し、**上書きしない**
-    expect($service->applyConfirmedEmail($user, $email))->toBeFalse();
+    // ★第 2 段はロックの下で読み直し、**上書きしない** (入口は confirm() のまま)
+    expect(app(EmailPromotionService::class)->confirm($user, $token))->toBeFalse();
+
+    // ★継ぎ目では第 1 段が開いた層がすべて閉じている (= 段を抜けている)。
+    //   `RefreshDatabase` の外側の層があるので「commit 済み」ではなく
+    //   **呼び出し前の level へ戻っている**ことを固定する。
+    expect($boundary->transactionLevelAtSeam)->toBe($levelBefore);
 
     // ★別経路の更新が残る
     expect($user->fresh()?->email)->toBe('other@corp.example');
@@ -498,14 +518,12 @@ function issuePromotion(User $user, string $email = 'new@corp.example'): string
 });
 
 test('割り込みが無ければ第 2 段は適用する (正のコントロール)', function (): void {
+    // ★弾く側だけを固定して「常に false」でも緑になる形にしない。
+    //   継ぎ目は本番のまま (何もしない) である。
     $user = promotionUser();
     $token = issuePromotion($user);
-    $service = app(EmailPromotionService::class);
-
-    $email = $service->consumeToken($user, $token);
-    expect($email)->not->toBeNull();
 
-    expect($service->applyConfirmedEmail($user, $email))->toBeTrue();
+    expect(app(EmailPromotionService::class)->confirm($user, $token))->toBeTrue();
     expect($user->fresh()?->email)->toBe('new@corp.example');
 });
 
@@ -513,15 +531,28 @@ function issuePromotion(User $user, string $email = 'new@corp.example'): string
     $user = promotionUser();
     $token = issuePromotion($user);
 
-    // ★監査テーブルへの書き込みを壊す。`recordOrFail` は握り潰さないので、
-    //   同じトランザクションのメール変更ごと巻き戻るはずである。
-    SecurityAuditEvent::creating(static fn (): never => throw new RuntimeException('監査の書き込みに失敗した'));
+    // ★監査の書き込みを**挿入の後**で壊す。`creating` で止めると行がそもそも生まれないので
+    //   「監査行が無い」は巻き戻しの証拠にならない (壊し方が弱いと主張が空振りする)。
+    //   `created` なら**一度は挿入されている**ので、外側の「監査行も無い」が
+    //   巻き戻しそのものを固定する。
+    // ★後始末は `flushEventListeners()` (そのモデルの**全 event** を静的に削除する) ではなく、
+    //   **張った 1 つの event 名だけ**を忘れさせる。モデルに trait / observer が足された日に
+    //   後続テストを汚す形にしない (`EmailPromotion` は `UsesCipherSweet` が
+    //   `retrieved` / `saving` / `saved` を張るので、全削除は暗号化を殺す)。
+    $listened = 'eloquent.created: '.SecurityAuditEvent::class;
+
+    SecurityAuditEvent::created(static function (SecurityAuditEvent $event): never {
+        // ★この時点では挿入済みで見える (巻き戻しが効いていることを外側と対で示す)
+        expect(SecurityAuditEvent::query()->whereKey($event->getKey())->exists())->toBeTrue();
+
+        throw new RuntimeException('監査の書き込みに失敗した');
+    });
 
     try {
         expect(fn () => app(EmailPromotionService::class)->confirm($user, $token))
             ->toThrow(RuntimeException::class);
     } finally {
-        SecurityAuditEvent::flushEventListeners();
+        Event::forget($listened);
     }
 
     $fresh = $user->fresh();
diff --git a/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php b/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php
index 78f24691..250ae9eb 100644
--- a/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php
+++ b/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php
@@ -309,6 +309,27 @@ public static function filesCalling(array $sources, string $method): array
      * ★**外側の引数リストの深さ 1 にある名前付き引数だけ**を見る。
      *   深さを見ないと、入れ子の別の呼び出し・配列・クロージャの中にある同名の引数を
      *   外側のものと取り違える (`fetch($this->build(followRedirects: false), …)` が通ってしまう)。
+     * ★深さは**整数のカウンタではなく区切りの stack** で持つ。PHP の開き区切りは
+     *   素の `(` `[` `{` だけではないためである (実測した token の形):
+     *
+     *   | 構文 | 開き token | 閉じ |
+     *   |---|---|---|
+     *   | attribute `#[Probe]` | `T_ATTRIBUTE` (text は `#[`) | `]` |
+     *   | 文字列内挿 `"{$x}"` | `T_CURLY_OPEN` (text は `{`) | `}` |
+     *   | 文字列内挿 `"${x}"` | `T_DOLLAR_OPEN_CURLY_BRACES` (text は `${`) | `}` |
+     *
+     *   text だけで判定すると `#[` と `${` が**開きとして数えられないのに閉じだけ数えられ**、
+     *   その場から深さが 1 つずれる (以降の入れ子が深さ 1 に見えて取り違える)。
+     *   `T_CURLY_OPEN` は text が `{` なので偶然合っていたが、**偶然に依存しない**。
+     * ★**対応の取れない区切り**が出たら「読み切れない」として落とす ((b) fail-closed)。
+     *   単なる整数カウンタでは `([)]` のような壊れた対応を検出できない。
+     *
+     * ## 保証しないもの (誇張しない)
+     *
+     * - **first-class callable** (`fetch(...)`) は引数が無い形として**違反側**へ落ちる。
+     *   呼び出しの引数を静的に確定できないためであり、G2 の狭い走査根では fail-closed が正しい。
+     * - 可変メソッド名 (`$obj->{$name}(...)`) の呼び出しは走査対象に入らない
+     *   (メソッド名が T_STRING で現れない)。
      *
      * @param  array<string, string>  $sources
      * @param  string  $literal  許すリテラル (例: `false`)
@@ -350,22 +371,35 @@ public static function callsWithoutNamedLiteral(
                 // ★**外側の引数リストの深さ 1 にあるものだけ**を対象にする。
                 //   深さを見ないと、入れ子の別の呼び出しの同名引数を外側のものと取り違える
                 //   (`fetch($this->build(followRedirects: false), $deadline)` が緑になってしまう)。
+                // ★開きは text だけで決まらない (`#[` / `${`)。stack で持ち、
+                //   対応が取れなければ「読み切れない」として落とす。
                 $valuePosition = null;
-                $depth = 1;
+                $unresolved = false;
+                /** @var list<string> $expectedClosers 外側の `(` に対応する `)` を底に積む */
+                $expectedClosers = [')'];
+
                 for ($k = $i + 2; $k < $end; $k++) {
                     $text = $tokens[$k]['text'];
+                    $closer = self::closerForOpener($tokens[$k]);
 
-                    if ($text === '(' || $text === '[' || $text === '{') {
-                        $depth++;
+                    if ($closer !== null) {
+                        $expectedClosers[] = $closer;
 
                         continue;
                     }
+
                     if ($text === ')' || $text === ']' || $text === '}') {
-                        $depth--;
+                        if (array_pop($expectedClosers) !== $text) {
+                            $unresolved = true;
+
+                            break;
+                        }
 
                         continue;
                     }
-                    if ($depth !== 1) {
+
+                    // 深さ 1 = 外側の引数リストそのもの (底の 1 件だけが残っている状態)
+                    if (count($expectedClosers) !== 1) {
                         continue;
                     }
 
@@ -381,6 +415,18 @@ public static function callsWithoutNamedLiteral(
                     }
                 }
 
+                // ★引数を最後まで読んだのに開きが閉じ切っていない形も「読み切れない」である
+                //   (`fetch($a[0), $d)` のように閉じの種類が食い違う形をここで捕まえる)。
+                if ($valuePosition === null && $expectedClosers !== [')']) {
+                    $unresolved = true;
+                }
+
+                if ($unresolved) {
+                    $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() の引数を読み切れない";
+
+                    continue;
+                }
+
                 if ($valuePosition === null) {
                     $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() に {$argument}: が無い";
 
@@ -401,6 +447,34 @@ public static function callsWithoutNamedLiteral(
         return array_values(array_unique($violations));
     }
 
+    /**
+     * トークンが**開き区切り**なら、対応する閉じ文字を返す (開きでなければ null)。
+     *
+     * ★`#[` (`T_ATTRIBUTE`) と `${` (`T_DOLLAR_OPEN_CURLY_BRACES`) は
+     *   **text が素の `[` / `{` ではない**ので、text だけを見ると開きとして数えられない。
+     *   閉じ (`]` / `}`) だけが数えられて深さが 1 つずれる。
+     * ★`T_CURLY_OPEN` (`"{$x}"` の `{`) は text が `{` なので下の text 判定でも拾えるが、
+     *   **偶然に依存しない**ように id でも明示する。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function closerForOpener(array $token): ?string
+    {
+        if ($token['id'] === T_ATTRIBUTE) {
+            return ']';
+        }
+        if ($token['id'] === T_DOLLAR_OPEN_CURLY_BRACES || $token['id'] === T_CURLY_OPEN) {
+            return '}';
+        }
+
+        return match ($token['text']) {
+            '(' => ')',
+            '[' => ']',
+            '{' => '}',
+            default => null,
+        };
+    }
+
     /**
      * `(` の位置から対応する `)` の位置を返す (見つからなければ null)。
      *
diff --git a/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php b/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php
index cee5c8ad..e6a7fe75 100644
--- a/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php
+++ b/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php
@@ -107,7 +107,7 @@ public function run(): void
     expect(EnterpriseSsoSourceScanner::sources(['app/Services/EnterpriseSso']))->not->toBe([]);
 });
 
-test('危険な fetch() の 5 形をすべて落とし、リテラルの false だけを通す (負例)', function (): void {
+test('危険な fetch() の 7 形をすべて落とし、リテラルの false だけを通す (負例)', function (): void {
     $violations = EnterpriseSsoSourceScanner::callsWithoutNamedLiteral(
         scannerFixture('RedirectFollowingSample'),
         'fetch',
@@ -115,13 +115,14 @@ public function run(): void
         'false',
     );
 
-    // ★見本の 7 呼び出しのうち、安全な 2 件 (リテラル false / 内側にも同名があるが外側は false) を通す。
+    // ★見本の 11 呼び出しのうち、安全な 4 件を通す
+    //   (リテラル false / 入れ子にも同名 / attribute つき / 文字列内挿つき。いずれも外側は false)。
     //   `makeRequest()` は fetch ではないので走査対象そのものに入らない。
-    expect($violations)->toHaveCount(5);
+    expect($violations)->toHaveCount(7);
 
     $combined = implode("\n", $violations);
-    // 引数が無い形 (引数省略 + **入れ子にしか無い**形の 2 件)
-    expect(substr_count($combined, 'followRedirects: が無い'))->toBe(2);
+    // 引数が無い形 (引数省略 + 入れ子にしか無い + attribute の後 + 文字列内挿の後 の 4 件)
+    expect(substr_count($combined, 'followRedirects: が無い'))->toBe(4);
     // 値が false でない形 (明示 true / 動的 / 式)
     expect(substr_count($combined, 'followRedirects: が false でない'))->toBe(3);
 });
@@ -169,6 +170,127 @@ public function run(): void
         ->toBe([]);
 });
 
+test('attribute の #[ を開きとして数える (深さ判定の負例)', function (): void {
+    // ★`#[` は `T_ATTRIBUTE` (text は `#[`) であり素の `[` ではない。開きとして数えないと
+    //   閉じの `]` だけが数えられ、以降の入れ子が外側に見える。
+    $sources = ['sample.php' => <<<'PHP'
+        <?php
+        final class Sample
+        {
+            public function run(): void
+            {
+                $this->pinned->fetch(
+                    #[Probe]
+                    fn () => $this->build(followRedirects: false),
+                    $deadline,
+                );
+            }
+        }
+        PHP];
+
+    $violations = EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false');
+
+    expect($violations)->toHaveCount(1);
+    expect($violations[0])->toContain('followRedirects: が無い');
+});
+
+test('文字列内挿の ${ と { を開きとして数える (深さ判定の負例)', function (): void {
+    // ★`"${x}"` は `T_DOLLAR_OPEN_CURLY_BRACES` (text は `${`)、`"{$x}"` は `T_CURLY_OPEN`。
+    //   前者を開きとして数えないと閉じの `}` だけが数えられて深さがずれる。
+    $sources = ['sample.php' => <<<'PHP'
+        <?php
+        final class Sample
+        {
+            public function run(): void
+            {
+                $this->pinned->fetch("${label}", $this->build(followRedirects: false), $deadline);
+            }
+        }
+        PHP];
+
+    $violations = EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false');
+
+    expect($violations)->toHaveCount(1);
+    expect($violations[0])->toContain('followRedirects: が無い');
+});
+
+test('attribute と文字列内挿があっても外側が false なら通す (深さ判定の正例)', function (): void {
+    $sources = ['sample.php' => <<<'PHP'
+        <?php
+        final class Sample
+        {
+            public function run(): void
+            {
+                $this->pinned->fetch(
+                    #[Probe]
+                    fn () => $this->build(followRedirects: true),
+                    "{$label}${suffix}",
+                    $deadline,
+                    followRedirects: false,
+                );
+            }
+        }
+        PHP];
+
+    expect(EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false'))
+        ->toBe([]);
+});
+
+test('区切りの対応が取れない形は「読み切れない」として落とす (fail-closed の負例)', function (): void {
+    // ★閉じの種類が食い違う形。整数のカウンタだけだと `([)]` のような壊れた対応を検出できない。
+    $sources = ['sample.php' => <<<'PHP'
+        <?php
+        final class Sample
+        {
+            public function run(): void
+            {
+                $this->pinned->fetch($a}, $deadline, followRedirects: false);
+            }
+        }
+        PHP];
+
+    $violations = EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false');
+
+    expect($violations)->toHaveCount(1);
+    expect($violations[0])->toContain('引数を読み切れない');
+});
+
+test('閉じ切らない開きが残る形も「読み切れない」として落とす (fail-closed の負例)', function (): void {
+    $sources = ['sample.php' => <<<'PHP'
+        <?php
+        final class Sample
+        {
+            public function run(): void
+            {
+                $this->pinned->fetch($a[0), $deadline);
+            }
+        }
+        PHP];
+
+    $violations = EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false');
+
+    expect($violations)->toHaveCount(1);
+    expect($violations[0])->toContain('引数を読み切れない');
+});
+
+test('first-class callable は引数を確定できないので落とす (fail-closed の負例)', function (): void {
+    $sources = ['sample.php' => <<<'PHP'
+        <?php
+        final class Sample
+        {
+            public function run(): void
+            {
+                $callable = $this->pinned->fetch(...);
+            }
+        }
+        PHP];
+
+    $violations = EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false');
+
+    expect($violations)->toHaveCount(1);
+    expect($violations[0])->toContain('followRedirects: が無い');
+});
+
 test('リテラルの false ちょうどの呼び出しは通す (正例)', function (): void {
     $sources = ['sample.php' => <<<'PHP'
         <?php
diff --git a/app/Contracts/Auth/EmailPromotionStageBoundary.php b/app/Contracts/Auth/EmailPromotionStageBoundary.php
new file mode 100644
--- /dev/null
+++ b/app/Contracts/Auth/EmailPromotionStageBoundary.php
+<?php
+
+declare(strict_types=1);
+
+namespace App\Contracts\Auth;
+
+use App\Models\User;
+use App\Services\Auth\EmailPromotionService;
+use App\Services\Auth\InertEmailPromotionStageBoundary;
+
+/**
+ * メールアドレスの昇格の**第 1 段 (消費の commit) と第 2 段 (適用) の継ぎ目**。
+ *
+ * ## なぜ本番のコードに継ぎ目があるのか
+ *
+ * {@see EmailPromotionService} は消費と適用を**別のトランザクション**に分ける
+ * (理由は同クラスの docblock「なぜ消費と適用を 2 段に分けるか」)。分けた結果、
+ * **2 段の間に別経路が `users.email` を入れる窓**が開く。第 2 段はその窓を前提に
+ * 行ロックの下で読み直して弾く — つまり**窓の存在そのものが本サービスの契約**である。
+ *
+ * その契約を検査するには「第 1 段が commit した後・第 2 段が始まる前」に割り込む必要がある。
+ * ここを塞ぐ選び方は 2 つあった:
+ *
+ * 1. **2 段を公開メソッドにする** — 却下した。`confirm()` が担っている
+ *    トークンの指紋照合・期限・本人結合を**迂回できる第 2 の入口**になる
+ *    (任意の `VerifiedEmail` を適用できてしまう)。テスト容易性のために
+ *    本番の操作面を広げてはならない。
+ * 2. **継ぎ目だけを 1 メソッドの協力者として外に出す** — こちらを採った。
+ *    本番実装 ({@see InertEmailPromotionStageBoundary}) は**何もしない**。
+ *    公開の入口は `confirm()` 1 本のままであり、操作面は 1 ミリも広がらない。
+ *
+ * ★この継ぎ目は**メールを書かない・トークンを消費しない**。できるのは
+ *   「第 1 段が終わった」という時点で任意のコードが走ることだけである。
+ */
+interface EmailPromotionStageBoundary
+{
+    /**
+     * 第 1 段のトランザクションが閉じ、第 2 段が始まる**前**に呼ばれる。
+     *
+     * 本番実装は何もしない。検査だけが割り込む。
+     */
+    public function afterConsume(User $user): void;
+}
diff --git a/app/Services/Auth/InertEmailPromotionStageBoundary.php b/app/Services/Auth/InertEmailPromotionStageBoundary.php
new file mode 100644
--- /dev/null
+++ b/app/Services/Auth/InertEmailPromotionStageBoundary.php
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Auth;
+
+use App\Contracts\Auth\EmailPromotionStageBoundary;
+use App\Models\User;
+
+/**
+ * 本番の継ぎ目。**何もしない**。
+ *
+ * ★「何もしない実装」であることを名前で言い切る (`Inert` = 不活性)。
+ *   ここに処理が足されたら、それは 2 段の間に本番の副作用を挟んだということであり、
+ *   レビューで必ず目に入る。
+ */
+final readonly class InertEmailPromotionStageBoundary implements EmailPromotionStageBoundary
+{
+    public function afterConsume(User $user): void
+    {
+        // 何もしない (継ぎ目に名前を与えるためだけの実装)
+    }
+}
diff --git a/tests/Support/Auth/InterferingEmailPromotionStageBoundary.php b/tests/Support/Auth/InterferingEmailPromotionStageBoundary.php
new file mode 100644
--- /dev/null
+++ b/tests/Support/Auth/InterferingEmailPromotionStageBoundary.php
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Auth;
+
+use App\Contracts\Auth\EmailPromotionStageBoundary;
+use App\Models\User;
+use Illuminate\Support\Facades\DB;
+
+/**
+ * 検査用の継ぎ目。**第 1 段が閉じた直後に別経路のメール更新を割り込ませる**。
+ *
+ * ★狙いは「第 1 段の commit と第 2 段の適用の間」という窓を実際に作ることである。
+ *   モデルイベントの listener で代用すると割り込みが**第 1 段の内側**で走ってしまい
+ *   (同じ接続なので自分が持つ行ロックへ再入できる)、測っているものが主張と食い違う。
+ * ★暗号文は**呼び出し側が作って渡す** (暗号化の手順を 2 か所に持たない)。
+ * ★**1 回だけ**割り込む。
+ */
+final class InterferingEmailPromotionStageBoundary implements EmailPromotionStageBoundary
+{
+    private bool $done = false;
+
+    /**
+     * 継ぎ目に着いた時点の transaction level。
+     *
+     * ★呼び出し前の level と等しければ「第 1 段が開いた層をすべて閉じた」ことになる。
+     *   検査は**この等号**で「段を抜けた」を固定する (「commit した」とは言わない —
+     *   `RefreshDatabase` の外側の層があるので、実際に閉じるのは savepoint である)。
+     */
+    public ?int $transactionLevelAtSeam = null;
+
+    /** @param string $encryptedEmail `users.email` へそのまま入れる暗号文 */
+    public function __construct(private readonly string $encryptedEmail) {}
+
+    public function afterConsume(User $user): void
+    {
+        if ($this->done) {
+            return;
+        }
+        $this->done = true;
+        $this->transactionLevelAtSeam = DB::transactionLevel();
+
+        // ★昇格の経路を通さずに `users.email` を入れる (プロフィール更新などの別経路を模す)。
+        $user->newQuery()->whereKey($user->getKey())->update(['email' => $this->encryptedEmail]);
+    }
+}
```

---

## 再確認をお願いしたい点

1. **二段の公開を閉じた形**: `consumeToken()` / `applyConfirmedEmail()` を `private` へ戻し、
   継ぎ目だけを `EmailPromotionStageBoundary` (本番は何もしない) として外へ出した。
   - 公開の操作面が Round 2 以前と**同一**に戻っていること
   - この継ぎ目でできるのが「その時点で任意のコードが走る」ことだけであり、
     **メールを書く / トークンを消費する能力を与えていない**こと
   - 本番実装が不活性であることと 2 段が `private` であることを reflection で固定した検査が、
     「公開へ戻す」退行を実際に赤くすること

   の 3 点を確認してほしい。「本番コードにテストのための継ぎ目を置く」こと自体への
   異論があれば聞きたい (Round 4 で挙げられた選択肢の 1 番目を採ったつもりである)。

2. **走査器の stack 化**: `T_ATTRIBUTE` / `T_DOLLAR_OPEN_CURLY_BRACES` / `T_CURLY_OPEN` を
   開きとして扱い、対応しない閉じと閉じ切らない開きを「読み切れない」で落とす形で、
   **まだ落とし損ねる token の形が残っていないか**。
   具体的に検討したのは次である — 見落としがあれば指摘してほしい。
   - 文字列リテラル中の括弧 → `PhpTokenScan::normalize()` は `token_get_all()` の
     単一トークンをそのまま持つので、`"("` は 1 トークンで text が `(` にならない
   - heredoc / nowdoc → `T_START_HEREDOC` / `T_ENCAPSED_AND_WHITESPACE` / `T_END_HEREDOC`。
     内挿があれば `T_CURLY_OPEN` として現れ、対応する `}` で閉じる
   - `match` 式の `{}` / 配列 unpacking / 名前付き引数を含む attribute の引数

3. **見送った Suggestion** (`recordOrFail()` の caller gate) の扱い。
   Round 4 で「承認阻害ではない」と明示されているので**このラウンドでは作らない**判断をした。
   理由は対応マトリクスに書いた 2 点 (gate 新設が AGENTS.md の 4 点を要求すること /
   残り 1 ラウンドで新しい走査器を未レビューのまま残したくないこと) である。
   この見送りが承認を阻むなら、**そう言ってほしい** (その場合は作る)。

4. これらの対応で**新しく生まれた欠陥**が無いか。特に
   - `AppServiceProvider` への binding 追加
   - `Tests\Support\Auth\InterferingEmailPromotionStageBoundary` が
     `$this->app->instance()` で差し込まれること (テスト間で漏れないか)
   - `Event::forget()` による後始末が `flushEventListeners()` より本当に狭いか

全体判定を `APPROVED` または `CHANGES_REQUESTED` で明示してほしい。

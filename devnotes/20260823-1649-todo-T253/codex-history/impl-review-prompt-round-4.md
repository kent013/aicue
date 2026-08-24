# Round 4: Round 3 の指摘への対応

**ラウンド上限について**: Round 3 で当初の上限 (3 ラウンド) に達したが、
**監督者の裁量で上限を +2 (Round 5 まで) 延長した**。Round 3 の指摘 (Warning 4 / Suggestion 1) は
**すべて対応済み**だが、その修正自体がまだレビューを受けていないためである。
このラウンドで求めるのは **Round 3 の指摘への修正差分の再判定**である。

対応・反論の内訳: **対応 5 件 / 反論 0 件 / 見送り 0 件**。
加えて、指摘には無かったが Round 3 の修正が生んだ**契約の陳腐化 1 件**と、
**新設テストの壊し方が弱い点 1 件**を自分で見つけて直した (末尾「自主的に直した 2 件」)。

---

# 対応マトリクス: impl-review Round 3

Codex の全体判定は `CHANGES_REQUESTED`。**これで合議の上限 (3 ラウンド) に達した**。

Round 3 の指摘 (Warning 4 / Suggestion 1) は**すべて対応した**が、
**その修正自体は Codex のレビューを受けていない** (4 ラウンド目を回していない)。
この点は最終報告で `status=failed` として明示する。

---

## [Warning] メール更新と security audit が原子的でない

- **判断**: 対応する
- **根拠**: **指摘が正しい**。監査の記録を第 2 段のトランザクションの**外**に置いていたので、
  記録が失敗すると「メールと `email_verified_at` は変わったのに監査行が無い」状態が残る。
  設計 E1 が「変更を記録する」と要求している以上、記録できない変更を通してはいけない。
- **対応内容**: 記録を**第 2 段のトランザクションの中**へ移し、`record()` (握り潰す) ではなく
  **`recordOrFail()`** を使う。記録できなければメールの変更ごと巻き戻る。
  blind index の衝突は従来どおり savepoint まで戻るので、2 段構成とも両立する。
- **回帰テスト**: `EmailPromotionTest`「監査を記録できなければメールの変更も巻き戻る」。
  指摘された 4 つの期待をすべて固定した — メールは null のまま / `email_verified_at` も動かない /
  監査行は無い / トークンは第 1 段で消費済みのまま。

---

## [Warning] 割り込みテストが「第 1 段の commit 後」を再現していない

- **判断**: 対応する
- **根拠**: **指摘が正しい**。`EmailPromotion::deleted()` の listener は
  `$promotion->delete()` の最中、つまり**第 1 段のトランザクションの内側**で走る。
  同じ接続なので自分が持つ行ロックへ再入でき、テストは通るが、
  **測っているものがテストの主張と違っていた** (Round 2 で問題にした窓ではない)。
- **対応内容**: Codex が挙げた選択肢のうち**「消費と適用を分離してテストから順に呼ぶ」**を採った。
  `confirm()` は入口のまま残し、2 段を `consumeToken()` / `applyConfirmedEmail()` として公開した。
  ★段を公開するのはテストのためだけではない — **2 段構成そのものが本サービスの契約**であり
  (docblock が「なぜ分けるか」を正本として持っている)、継ぎ目に名前が付くことで
  「第 1 段の後に何が起きうるか」を型と名前で示せる。
  テストは第 1 段を呼んで戻ったあと (= commit 済み) に別経路の更新を commit し、
  そのうえで第 2 段を呼ぶ。**listener を 1 つも使わない**。
- **あわせて追加**: 「割り込みが無ければ第 2 段は適用する」正のコントロール
  (弾く側だけを固定して「常に false」でも緑になる形にしない)。

---

## [Warning] `EmailPromotion::flushEventListeners()` が後続テストを汚染する

- **判断**: 対応する
- **根拠**: **指摘が正しい**。`flushEventListeners()` はそのテストが登録した closure だけでなく、
  **モデルに登録された全 listener を静的に削除する**。同一プロセスの後続テストで戻らないので、
  実行順で挙動が変わる (本番と違う状態でテストが走る)。
- **対応内容**: 上の書き直しで**割り込みテストから listener そのものを無くした**。
  新設した監査のテストでは `SecurityAuditEvent` に listener を張るが、
  こちらは**そのテストが壊したい対象そのもの**であり、他のモデルの購読を巻き込まない
  (`EmailPromotion` / `User` の listener には触れない)。

---

## [Warning] 走査器が入れ子の名前付き引数を外側の `fetch()` のものと誤認する

- **判断**: 対応する
- **根拠**: **指摘が正しい**。括弧の深さを見ずに最初に見つけた `followRedirects:` を採用していたので、
  `fetch($this->build(followRedirects: false), $deadline)` が緑になった
  (外側には引数が無く、既定の `true` が効く)。
- **対応内容**: 走査を**外側の引数リストの深さ 1 に限定**した
  (`(` `[` `{` で深さを増やし、閉じで減らす。深さ 1 以外は無視する)。
  これは AGENTS.md の走査器共通規約が言う「検出範囲を変える修正」なので、
  **同じ変更で負例・正例・docblock を揃えた**:
  - 見本に 2 形を追加 — **入れ子にしか引数が無い**形 (違反) と、
    **外側が false で入れ子にも同名がある**形 (正例。深さ判定の逆方向)
  - 自己検査に 2 本追加 (「取り違えない」「外側が false なら通す」)
  - 既存の一括検査も 7 呼び出し / 違反 5 件 / 正例 2 件へ更新
  - docblock に「深さ 1 だけを見る」ことと**その理由**を書いた

---

## [Suggestion] `release()` の失敗を完全に無言で握り潰すと障害の兆候が見えない

- **判断**: 対応する
- **根拠**: 指摘のとおり。best-effort にする判断 (Round 3 で APPROVE) は保ちつつ、
  観測だけは残せる。
- **対応内容**: `Log::warning()` を 1 行足した。**固定の文言だけ**を載せる
  (鍵・URL・接続 id を載せない)。取得 (`get()`) の失敗は従来どおり fail-closed のままである。

---

## Round 3 で APPROVE された点 (変更しない)

- 第 2 段の再ロックが競合窓を閉じていること、トークンを消費済みのままにする帰結
- `release()` の best-effort 化 (取得の失敗 / callback の失敗 / 後片付けの失敗を同じ失敗へ畳まない判断)
- ロックの寿命と時間予算の関係を設定検査で固定したこと
- 重複した `key_ops` を拒否側へ移したこと
- validation の空振り修正 (上限から生成した長さ / 配列 / 欠落 + 規則そのものの境界検査)

---

---

## 自主的に直した 2 件 (Round 3 の指摘には無い)

### (i) `SecurityEventRecorder::recordOrFail()` の docblock が実装と食い違っていた

Round 3 の修正で `EmailPromotionService` が `recordOrFail()` を使い始めたが、
docblock には **「組織アクセスの失効 ({@see OrganizationAccessRevoker}) *だけ*がこれを使う」**
「**認証系の記録にこれを使ってはならない**」と書いてあった。
`EmailPromotionService` は `App\Services\Auth` 名前空間にあるので、
**字面のうえでは新しい使用が自分の禁止条項に当たって見える**。

正本 (docblock) を実装に合わせて直した。書き分けの軸を
「認証系かどうか」ではなく **「確定した変更を同じトランザクションで記録するのか、
試行を観測するだけなのか」** に置き直し、許される 2 つの呼び出し元を名指しした。
ログイン失敗・成功のような**試行の観測**は従来どおり `record()` (best-effort) である旨も残した。

★呼び出し元の集合を検査で pin していないことは承知している。
`OrganizationAccessRevocationChokePointTest` 検査 F は
「`revoke()` の本文が `recordOrFail(` を使い `record(` を使わない」ことだけを固定しており、
**recordOrFail の使用者一覧を deny-by-default で縛ってはいない**。
ここを走査器で縛るべきかどうかについては意見が欲しい (下記の再確認事項 5)。

### (ii) 監査ロールバックのテストの壊し方が弱かった

Round 3 の指摘に応えて追加したテストは、当初 `SecurityAuditEvent::creating` で例外にしていた。
これだと**監査行がそもそも挿入されない**ので、事後の
「監査行も無い」は**巻き戻しの証拠にならない** (壊し方が弱いと主張が空振りする —
Round 2 で指摘された「validation の空振り」と同じ種類の欠陥である)。

`created` (挿入の**後**) へ移し、listener の中で
**その行が実際に見えること**を確認してから例外にした。これで外側の
「監査行が無い」が**挿入された行が巻き戻ったこと**を固定する。

`flushEventListeners()` を `SecurityAuditEvent` に対して使っている点については、
Round 3 の指摘 (`EmailPromotion` での使用) との違いを実測で確認した:

- `EmailPromotion` は `Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet` を使い、
  その `bootUsesCipherSweet()` が **`retrieved` / `saving` / `saved` を張る**
  (`vendor/spatie/laravel-ciphersweet/src/Concerns/UsesCipherSweet.php:19-31`)。
  モデルの boot は**プロセスに 1 回**なので、flush すると以降のテストで暗号化が効かなくなる
  = **指摘のとおり汚染する**。
- `SecurityAuditEvent` が使う trait は `HasFactory` だけで、
  **model event を張る trait / observer を 1 つも持たない** (モデル定義と
  `app/Providers` / `app/Observers` の grep で確認済み)。したがって flush で落ちるのは
  **そのテストが自分で張った listener だけ**である。

この違いを**テストの docblock に書いた** (「CipherSweet を使うモデルで同じことをしてはならない」)。
より強い形 (走査器で `flushEventListeners()` の使用を縛る) が要るなら、そう言ってほしい。

---

## 検証コマンドの結果

- `composer test -- tests/Feature/Auth/EmailPromotionTest.php`: **42 tests / 42 passed / 206 assertions**
- 全レーン (`composer test` 7279 / `composer phpstan` level 10 / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm test:packages`) は
  Round 3 の修正時点で全 green。上記 (i)(ii) を入れたあとの全レーン再走は
  **このプロンプトを送るのと並行して実行中**であり、結果は次のラウンドで報告する
  (もし赤が出た場合はその内容も含めて報告する)。

---

## 対応の差分 (Round 3 レビュー時点 → 現在・全文)

```diff
diff --git a/app/Services/Auth/EmailPromotionService.php b/app/Services/Auth/EmailPromotionService.php
--- a/app/Services/Auth/EmailPromotionService.php
+++ b/app/Services/Auth/EmailPromotionService.php
@@ -139,9 +139,29 @@
      */
     public function confirm(User $user, #[SensitiveParameter] string $token): bool
     {
+        $email = $this->consumeToken($user, $token);
+
+        if ($email === null) {
+            return false;
+        }
+
+        return $this->applyConfirmedEmail($user, $email);
+    }
+
+    /**
+     * **第 1 段**: トークンを検査して消費を確定させる (ここで commit される)。
+     *
+     * ★`confirm()` の入口から呼ぶのが通常の使い方である。**段を公開しているのは、
+     *   2 段構成そのものが本サービスの契約だから**であり、
+     *   「第 1 段の commit と第 2 段の間に別経路が割り込む」順序を
+     *   テストが**実際の継ぎ目で**再現できるようにするためでもある。
+     *
+     * @return VerifiedEmail|null null = トークンが無効・期限切れ・他人のもの・対象外
+     */
+    public function consumeToken(User $user, #[SensitiveParameter] string $token): ?VerifiedEmail
+    {
         $fingerprint = AttemptFingerprint::of(FingerprintPurpose::EmailPromotionToken, $token);
 
-        // ── 第 1 段: 消費を**確定させる** (ここで commit される)
         $email = DB::transaction(function () use ($user, $fingerprint): ?string {
             // ★行ロックの下で「メールを持たないこと」を再確認する (発行後に別経路で入った場合を弾く)。
             $locked = $this->lockedSelf($user);
@@ -169,12 +189,7 @@
             return $email;
         });
 
-        if ($email === null) {
-            return false;
-        }
-
-        // ── 第 2 段: 適用 (衝突しても第 1 段の消費は巻き戻らない)
-        return $this->applyVerifiedEmail($user, VerifiedEmail::afterConfirmation($email));
+        return $email === null ? null : VerifiedEmail::afterConfirmation($email);
     }
 
     /**
@@ -204,11 +219,15 @@
      *   呼び出し側が持っているインスタンスへ `forceFill()` すると、失敗したときに
      *   未保存の値がそのまま残る。
      *
+     * ★**監査の記録も同じトランザクションの中で行う**。外に出すと、監査が失敗したときに
+     *   「メールは変わったのに記録が無い」状態が残る (設計 E1 が要求する記録が成立しない)。
+     *   記録できなければメールの変更ごと巻き戻す。
+     *
      * @return bool true = 適用した / false = 第 1 段の後にメールが入ったので適用しない
      *
      * @throws EmailPromotionConflictException
      */
-    private function applyVerifiedEmail(User $user, VerifiedEmail $email): bool
+    public function applyConfirmedEmail(User $user, VerifiedEmail $email): bool
     {
         try {
             // ★**自分のトランザクション (savepoint) の中で**書く。
@@ -228,6 +247,16 @@
                     'email_verified_at' => now(),
                 ])->save();
 
+                // ★監査に残すのは**利用者と固定の事象種別だけ**である
+                //   (トークンも平文のメールも載せない。既存の変更経路と同じ event type を使う)。
+                // ★**同じトランザクションの中で**記録する。記録できなければメールの変更も巻き戻る
+                //   (「変わったのに記録が無い」を作らない) ので `recordOrFail` を使う。
+                $this->recorder->recordOrFail(
+                    SecurityEventType::EmailChanged,
+                    $locked,
+                    ['source' => 'email_promotion'],
+                );
+
                 return true;
             });
         } catch (QueryException $e) {
@@ -240,15 +269,7 @@
             throw $e;
         }
 
-        if (! $applied) {
-            return false;
-        }
-
-        // ★監査に残すのは**利用者と固定の事象種別だけ**である
-        //   (トークンも平文のメールも載せない。既存の変更経路と同じ event type を使う)。
-        $this->recorder->record(SecurityEventType::EmailChanged, $user, ['source' => 'email_promotion']);
-
-        return true;
+        return $applied;
     }
 
     /**
diff --git a/app/Services/Security/SecurityEventRecorder.php b/app/Services/Security/SecurityEventRecorder.php
--- a/app/Services/Security/SecurityEventRecorder.php
+++ b/app/Services/Security/SecurityEventRecorder.php
@@ -34,10 +34,18 @@
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
+     * - メールアドレスの昇格の確定 ({@see \App\Services\Auth\EmailPromotionService::applyConfirmedEmail()})
+     *   — 「メールは変わったが監査に残っていない」を作らない
+     *
+     * 逆に、**観測でしかない記録**にこれを使ってはならない。ログイン失敗・
+     * ログイン成功のような認証の試行そのものの記録は {@see record()} を使う —
      * 監査の失敗でログインそのものを落とすことになるためである。
+     * (昇格の確定はログインの経路ではなく、**利用者の属性を変える操作**である。)
      *
      * @param  array<string, mixed>  $metadata
      */
diff --git a/app/Services/EnterpriseSso/OidcDiscoveryService.php b/app/Services/EnterpriseSso/OidcDiscoveryService.php
--- a/app/Services/EnterpriseSso/OidcDiscoveryService.php
+++ b/app/Services/EnterpriseSso/OidcDiscoveryService.php
@@ -14,6 +14,7 @@
 use Illuminate\Contracts\Cache\Repository as CacheRepository;
 use Illuminate\Support\Facades\Cache;
 use Illuminate\Support\Facades\Config;
+use Illuminate\Support\Facades\Log;
 use Kent013\SsrfPin\Dtos\Deadline;
 use Kent013\SsrfPin\Dtos\PinnedFailure;
 use Kent013\SsrfPin\Dtos\PinnedRequest;
@@ -189,7 +190,10 @@
             try {
                 $lock->release();
             } catch (Throwable) {
-                // 後片付けの失敗は本体の結果に影響させない
+                // ★後片付けの失敗は本体の結果に影響させない。ただし**無言にはしない** —
+                //   完全に握り潰すとキャッシュ基盤の障害の兆候が見えなくなる。
+                //   載せるのは**固定の文言だけ**である (鍵も URL も接続 id も載せない)。
+                Log::warning('enterprise-sso jwks refetch lock release failed');
             }
         }
     }
diff --git a/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php b/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php
--- a/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php
+++ b/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php
@@ -306,6 +306,9 @@
      * ★**静的に確定できない値は違反として返す** ((b) fail-closed)。
      *   `followRedirects: $configured` / `! false` / `false || true` はどれも通さない —
      *   通してよいのは**リテラルちょうど 1 トークン**の場合だけである。
+     * ★**外側の引数リストの深さ 1 にある名前付き引数だけ**を見る。
+     *   深さを見ないと、入れ子の別の呼び出し・配列・クロージャの中にある同名の引数を
+     *   外側のものと取り違える (`fetch($this->build(followRedirects: false), …)` が通ってしまう)。
      *
      * @param  array<string, string>  $sources
      * @param  string  $literal  許すリテラル (例: `false`)
@@ -344,10 +347,30 @@
                     continue;
                 }
 
+                // ★**外側の引数リストの深さ 1 にあるものだけ**を対象にする。
+                //   深さを見ないと、入れ子の別の呼び出しの同名引数を外側のものと取り違える
+                //   (`fetch($this->build(followRedirects: false), $deadline)` が緑になってしまう)。
                 $valuePosition = null;
+                $depth = 1;
                 for ($k = $i + 2; $k < $end; $k++) {
+                    $text = $tokens[$k]['text'];
+
+                    if ($text === '(' || $text === '[' || $text === '{') {
+                        $depth++;
+
+                        continue;
+                    }
+                    if ($text === ')' || $text === ']' || $text === '}') {
+                        $depth--;
+
+                        continue;
+                    }
+                    if ($depth !== 1) {
+                        continue;
+                    }
+
                     if ($tokens[$k]['id'] === T_STRING
-                        && $tokens[$k]['text'] === $argument
+                        && $text === $argument
                         && ($tokens[$k + 1]['text'] ?? '') === ':'
                         // ★`?:` (三項) や `::` と取り違えない
                         && ($tokens[$k + 2]['text'] ?? '') !== ':'
diff --git a/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php b/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php
--- a/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php
+++ b/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php
@@ -107,7 +107,7 @@
     expect(EnterpriseSsoSourceScanner::sources(['app/Services/EnterpriseSso']))->not->toBe([]);
 });
 
-test('危険な fetch() の 4 形をすべて落とし、リテラルの false だけを通す (負例)', function (): void {
+test('危険な fetch() の 5 形をすべて落とし、リテラルの false だけを通す (負例)', function (): void {
     $violations = EnterpriseSsoSourceScanner::callsWithoutNamedLiteral(
         scannerFixture('RedirectFollowingSample'),
         'fetch',
@@ -115,16 +115,60 @@
         'false',
     );
 
-    // 見本の 5 呼び出しのうち、安全な 1 件だけを通す
-    expect($violations)->toHaveCount(4);
+    // ★見本の 7 呼び出しのうち、安全な 2 件 (リテラル false / 内側にも同名があるが外側は false) を通す。
+    //   `makeRequest()` は fetch ではないので走査対象そのものに入らない。
+    expect($violations)->toHaveCount(5);
 
     $combined = implode("\n", $violations);
-    // 引数が無い形
-    expect($combined)->toContain('followRedirects: が無い');
+    // 引数が無い形 (引数省略 + **入れ子にしか無い**形の 2 件)
+    expect(substr_count($combined, 'followRedirects: が無い'))->toBe(2);
     // 値が false でない形 (明示 true / 動的 / 式)
     expect(substr_count($combined, 'followRedirects: が false でない'))->toBe(3);
 });
 
+test('入れ子の呼び出しにある同名の引数を外側のものと取り違えない (深さ判定の負例)', function (): void {
+    $sources = ['sample.php' => <<<'PHP'
+        <?php
+        final class Sample
+        {
+            public function __construct(private Client $pinned) {}
+
+            public function run(): void
+            {
+                $this->pinned->fetch($this->build(followRedirects: false), $deadline);
+            }
+        }
+        PHP];
+
+    $violations = EnterpriseSsoSourceScanner::callsWithoutNamedLiteral(
+        $sources,
+        'fetch',
+        'followRedirects',
+        'false',
+    );
+
+    expect($violations)->toHaveCount(1);
+    expect($violations[0])->toContain('followRedirects: が無い');
+});
+
+test('外側が false なら、入れ子に同名の引数があっても通す (深さ判定の正例)', function (): void {
+    $sources = ['sample.php' => <<<'PHP'
+        <?php
+        final class Sample
+        {
+            public function __construct(private Client $pinned) {}
+
+            public function run(): void
+            {
+                $this->pinned->fetch($this->build(followRedirects: true), $deadline, followRedirects: false);
+            }
+        }
+        PHP];
+
+    expect(EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false'))
+        ->toBe([]);
+});
+
 test('リテラルの false ちょうどの呼び出しは通す (正例)', function (): void {
     $sources = ['sample.php' => <<<'PHP'
         <?php
diff --git a/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt b/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt
--- a/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt
+++ b/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt
@@ -10,21 +10,28 @@
  * ★負例の見本。**安全な呼び出しと危険な呼び出しが同じファイルに同居する**形を置く。
  *   ファイル単位の部分文字列一致だと安全な方を見て緑になってしまう (それを赤にする)。
  *
- * 危険な形は 3 方向ある:
+ * 危険な形は 4 方向ある:
  *   1. 引数そのものが無い (既定の true が効く)
  *   2. `followRedirects: true` (明示的に追従する)
  *   3. 値が静的に確定できない (実行時に true になりうる)
+ *   4. **入れ子の別の呼び出し**に同名の引数がある (外側には無いのに在るように見える)
  */
 final class RedirectFollowingSample
 {
     public function __construct(private PinnedHttpClient $pinned) {}
 
-    /** ★唯一の正例。リテラルの false ちょうど。 */
+    /** ★正例その 1。リテラルの false ちょうど。 */
     public function safe(): void
     {
         $this->pinned->fetch($request, $deadline, followRedirects: false);
     }
 
+    /** ★正例その 2。外側が正しく、**内側にも同名の引数がある** (深さ判定の逆方向)。 */
+    public function safeWithNestedArgument(): void
+    {
+        $this->pinned->fetch($this->makeRequest(followRedirects: true), $deadline, followRedirects: false);
+    }
+
     public function missingArgument(): void
     {
         $this->pinned->fetch($request, $deadline);
@@ -44,4 +51,10 @@
     {
         $this->pinned->fetch($request, $deadline, followRedirects: ! true);
     }
+
+    /** ★入れ子の引数を外側のものと取り違えると、これが緑になってしまう。 */
+    public function nestedOnly(): void
+    {
+        $this->pinned->fetch($this->makeRequest(followRedirects: false), $deadline);
+    }
 }
diff --git a/tests/Feature/Auth/EmailPromotionTest.php b/tests/Feature/Auth/EmailPromotionTest.php
--- a/tests/Feature/Auth/EmailPromotionTest.php
+++ b/tests/Feature/Auth/EmailPromotionTest.php
@@ -8,6 +8,7 @@
 use App\Models\EmailPromotion;
 use App\Models\SecurityAuditEvent;
 use App\Models\User;
+use App\Services\Auth\EmailPromotionService;
 use App\Support\EnterpriseSso\AttemptFingerprint;
 use Illuminate\Contracts\Queue\ShouldBeEncrypted;
 use Illuminate\Database\QueryException;
@@ -465,32 +466,23 @@
 test('消費の確定と適用の間に別経路がメールを入れたら、その更新を上書きしない', function (): void {
     $user = promotionUser();
     $token = issuePromotion($user);
+    $service = app(EmailPromotionService::class);
 
-    // ★第 1 段 (消費の commit) の**後**・第 2 段 (適用) の**前**に割り込む。
-    //   監査の記録点を使って「適用の直前」を捕まえるのではなく、
-    //   利用者の保存を観測して割り込む (適用は保存そのものなので、その 1 つ前で入る)。
-    $interrupted = false;
-    EmailPromotion::deleted(function () use ($user, &$interrupted): void {
-        if ($interrupted) {
-            return;
-        }
-        $interrupted = true;
-
-        // 別経路がメールを入れる (プロフィール更新など)
-        $user->newQuery()->whereKey($user->getKey())->update([
-            'email' => encryptedEmailFor('other@corp.example'),
-        ]);
-    });
+    // ★**実際の継ぎ目で**割り込む。第 1 段 (消費) が戻った時点で commit は済んでいる。
+    //   モデルイベントの listener に頼ると割り込みが**第 1 段の内側**で走ってしまい、
+    //   「commit の後」という筋書きにならない (かつ listener の全削除は後続テストを汚す)。
+    $email = $service->consumeToken($user, $token);
+    expect($email)->not->toBeNull();
+
+    // 別経路がメールを入れる (プロフィール更新など)
+    $user->newQuery()->whereKey($user->getKey())->update([
+        'email' => encryptedEmailFor('other@corp.example'),
+    ]);
 
-    try {
-        $this->actingAs($user)
-            ->post(route('settings.email-promotion.confirm'), ['token' => $token])
-            ->assertSessionHasErrors('email_promotion');
-    } finally {
-        EmailPromotion::flushEventListeners();
-    }
+    // ★第 2 段はロックの下で読み直し、**上書きしない**
+    expect($service->applyConfirmedEmail($user, $email))->toBeFalse();
 
-    // ★別経路の更新が残る (昇格が黙って上書きしない)
+    // ★別経路の更新が残る
     expect($user->fresh()?->email)->toBe('other@corp.example');
     // ★トークンは消費済みである (一回使用は保たれる)
     expect(EmailPromotion::query()->where('user_id', $user->id)->count())->toBe(0);
@@ -505,6 +497,58 @@
         ->assertSessionHasErrors('email_promotion');
 });
 
+test('割り込みが無ければ第 2 段は適用する (正のコントロール)', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+    $service = app(EmailPromotionService::class);
+
+    $email = $service->consumeToken($user, $token);
+    expect($email)->not->toBeNull();
+
+    expect($service->applyConfirmedEmail($user, $email))->toBeTrue();
+    expect($user->fresh()?->email)->toBe('new@corp.example');
+});
+
+test('監査を記録できなければメールの変更も巻き戻る (記録の無い変更を作らない)', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+
+    // ★監査の書き込みを**挿入の後**で壊す。`creating` で止めると行がそもそも生まれないので
+    //   「監査行が無い」は巻き戻しの証拠にならない (壊し方が弱いと主張が空振りする)。
+    //   `created` なら**一度は挿入されている**ので、外側の「監査行も無い」が
+    //   巻き戻しそのものを固定する。
+    // ★`SecurityAuditEvent` は model event を登録する trait / observer を持たない
+    //   (`HasFactory` だけ) ので、後始末の `flushEventListeners()` が
+    //   本番の購読を落とすことはない。CipherSweet を使うモデル (`UsesCipherSweet` が
+    //   `retrieved` / `saving` / `saved` を張る) で同じことをしてはならない。
+    SecurityAuditEvent::created(static function (SecurityAuditEvent $event): never {
+        // ★この時点では挿入済みで見える (巻き戻しが効いていることを外側と対で示す)
+        expect(SecurityAuditEvent::query()->whereKey($event->getKey())->exists())->toBeTrue();
+
+        throw new RuntimeException('監査の書き込みに失敗した');
+    });
+
+    try {
+        expect(fn () => app(EmailPromotionService::class)->confirm($user, $token))
+            ->toThrow(RuntimeException::class);
+    } finally {
+        SecurityAuditEvent::flushEventListeners();
+    }
+
+    $fresh = $user->fresh();
+    // ★メールは入っていない
+    expect($fresh?->email)->toBeNull();
+    // ★確認時刻も昇格の時刻へ動いていない
+    expect($fresh?->email_verified_at?->equalTo($user->email_verified_at))->toBeTrue();
+    // ★監査行も無い
+    expect(SecurityAuditEvent::query()
+        ->where('user_id', $user->id)
+        ->where('event_type', SecurityEventType::EmailChanged->value)
+        ->count())->toBe(0);
+    // ★トークンは第 1 段で消費済みである (設計どおり戻さない)
+    expect(EmailPromotion::query()->where('user_id', $user->id)->count())->toBe(0);
+});
+
 /** CipherSweet で暗号化した email 値 (別経路の更新を模すための補助)。 */
 function encryptedEmailFor(string $email): string
 {
```

---

## 参考: 変更後の全文 (差分だけでは読みにくい 2 か所)

### `EmailPromotionService::confirm()` / `consumeToken()` / `applyConfirmedEmail()` (現在)

```php
            //   `afterCommit` に依存しない — 行が巻き戻ればメールも投入されない。
            Mail::to($normalized)->send(new EmailPromotionMail($token));

            return true;
        });
    }

    /**
     * 確認トークンを消費して昇格を確定する。
     *
     * ★**確定してよいのは認証済みの本人だけ**である (`user_id` の結合を必ず照合する)。
     * ★確定では `email_verified_at` を**新しいメールを確認した時刻へ更新する**
     *   (「以前の値のまま」にしない = timestamp の意味を保つ)。
     *
     * @return bool true = 確定した / false = トークンが無効・期限切れ・他人のもの・対象外・
     *              **第 1 段の後に別経路でメールが入った** (その場合も**トークンは消費済み**である)
     *
     * @throws EmailPromotionConflictException 確認済みメールが既存利用者のものと重なった
     *                                         (★トークンは**消費済み**である)
     */
    public function confirm(User $user, #[SensitiveParameter] string $token): bool
    {
        $email = $this->consumeToken($user, $token);

        if ($email === null) {
            return false;
        }

        return $this->applyConfirmedEmail($user, $email);
    }

    /**
     * **第 1 段**: トークンを検査して消費を確定させる (ここで commit される)。
     *
     * ★`confirm()` の入口から呼ぶのが通常の使い方である。**段を公開しているのは、
     *   2 段構成そのものが本サービスの契約だから**であり、
     *   「第 1 段の commit と第 2 段の間に別経路が割り込む」順序を
     *   テストが**実際の継ぎ目で**再現できるようにするためでもある。
     *
     * @return VerifiedEmail|null null = トークンが無効・期限切れ・他人のもの・対象外
     */
    public function consumeToken(User $user, #[SensitiveParameter] string $token): ?VerifiedEmail
    {
        $fingerprint = AttemptFingerprint::of(FingerprintPurpose::EmailPromotionToken, $token);

        $email = DB::transaction(function () use ($user, $fingerprint): ?string {
            // ★行ロックの下で「メールを持たないこと」を再確認する (発行後に別経路で入った場合を弾く)。
            $locked = $this->lockedSelf($user);
            if (! $locked instanceof User || $locked->email !== null) {
                return null;
            }

            // ★relation 起点で引く (自分の行だけを見る = 他人のトークンでは何も起きない)。
            $promotion = $user->emailPromotions()
                ->where('token_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($promotion === null || $promotion->expires_at->isPast()) {
                return null;
            }

            $email = $promotion->email_encrypted;
            if (! is_string($email) || $email === '') {
                return null;
            }

            $promotion->delete();

            return $email;
        });

        return $email === null ? null : VerifiedEmail::afterConfirmation($email);
    }

    /**
     * 認証済みの自分自身の行を**ロックして**読み直す。
     *
     * ★**インスタンス起点**である (`$user->newQuery()`)。クラス起点の主キー同一性クエリで
     *   書かないのは、対象が payload 由来の id ではなく常に `Auth::id()` であることを
     *   経路の形そのもので示すためである (AGENTS.md セキュリティ不変条件 3)。
     */
    private function lockedSelf(User $user): ?User
    {
        $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();

        return $locked instanceof User ? $locked : null;
    }

    /**
     * ★`users.email` を書く**唯一の経路**である (昇格の側)。
     *
     * ★**ここでも行ロックの下で `email === null` を再確認する**。
     *   第 1 段 (消費) は commit してロックを手放しているので、その隙に別経路が
     *   メールを入れていることがありうる。再確認しないと**その更新を黙って上書きする**。
     *   上書きしないときはトークンを**消費済みのまま**にして false を返す
     *   (一回使用は保ったまま、他経路の結果を壊さない)。
     *
     * ★書き込みは**第 1 段で読み直した新しいインスタンス**に対して行う。
     *   呼び出し側が持っているインスタンスへ `forceFill()` すると、失敗したときに
     *   未保存の値がそのまま残る。
     *
     * ★**監査の記録も同じトランザクションの中で行う**。外に出すと、監査が失敗したときに
     *   「メールは変わったのに記録が無い」状態が残る (設計 E1 が要求する記録が成立しない)。
     *   記録できなければメールの変更ごと巻き戻す。
     *
     * @return bool true = 適用した / false = 第 1 段の後にメールが入ったので適用しない
     *
     * @throws EmailPromotionConflictException
     */
    public function applyConfirmedEmail(User $user, VerifiedEmail $email): bool
    {
        try {
            // ★**自分のトランザクション (savepoint) の中で**書く。
            //   pgsql は SQL エラーでトランザクション全体を aborted にするので、裸で書くと
            //   衝突が**呼び出し元のトランザクションまで巻き込む** (第 1 段の消費が使えなくなる)。
            //   savepoint の中なら巻き戻るのはこの 1 文だけである。
            $applied = DB::transaction(function () use ($user, $email): bool {
                $locked = $this->lockedSelf($user);

                if (! $locked instanceof User || $locked->email !== null) {
                    return false;
                }

                $locked->forceFill([
                    'email' => $email->value,
                    // ★**新しいメールを実際に確認した時刻**へ更新する (以前の値のままにしない)。
                    'email_verified_at' => now(),
                ])->save();

                // ★監査に残すのは**利用者と固定の事象種別だけ**である
                //   (トークンも平文のメールも載せない。既存の変更経路と同じ event type を使う)。
                // ★**同じトランザクションの中で**記録する。記録できなければメールの変更も巻き戻る
                //   (「変わったのに記録が無い」を作らない) ので `recordOrFail` を使う。
                $this->recorder->recordOrFail(
                    SecurityEventType::EmailChanged,
                    $locked,
                    ['source' => 'email_promotion'],
                );

                return true;
            });
        } catch (QueryException $e) {
            // ★変換してよいのは**メールの blind index の一意制約違反だけ**である。
            //   それ以外の一意制約違反と DB の障害は握り潰さず伝播させる。
            if ($this->isEmailBlindIndexConflict($e)) {
                throw new EmailPromotionConflictException('email is already taken by another user');
            }

            throw $e;
        }

        return $applied;
    }

    /**
     * メールの blind index の一意制約違反か。
     *
     * ★**制約名まで見る** (SQLSTATE だけで判定しない)。他の一意制約違反まで一様な応答へ
     *   畳むと、壊れていることが「よくある競合」として隠れる。
     * ★**保証範囲**: PostgreSQL の制約名が例外メッセージに現れることに依存する
     *   (本アプリは PostgreSQL 固定。準拠実装 {@see OrganizationSlugConstraintViolation})。
     */
    private function isEmailBlindIndexConflict(QueryException $e): bool
    {
        if (($e->errorInfo[0] ?? null) !== self::SQLSTATE_UNIQUE_VIOLATION) {
            return false;
        }

        return str_contains($e->getMessage(), self::EMAIL_BLIND_INDEX_CONSTRAINT);
    }
}
```

### `EnterpriseSsoSourceScanner::callsWithoutNamedLiteral()` (現在)

```php

    /**
     * 指定のメソッドの**呼び出しごと**に、名前付き引数が**特定のリテラルで**渡されているかを見る。
     *
     * ★ファイル単位の部分文字列一致にしない。「同じファイルに安全な呼び出しが 1 つあれば
     *   緑になる」形だと、**同じファイルへ既定値の呼び出しを 1 行足すだけで見逃す**。
     * ★**値まで見る**。名前付き引数の存在だけを見ると `followRedirects: true` が素通りする
     *   (gate の名前が主張していることと、実際に保証していることが食い違う)。
     * ★**静的に確定できない値は違反として返す** ((b) fail-closed)。
     *   `followRedirects: $configured` / `! false` / `false || true` はどれも通さない —
     *   通してよいのは**リテラルちょうど 1 トークン**の場合だけである。
     * ★**外側の引数リストの深さ 1 にある名前付き引数だけ**を見る。
     *   深さを見ないと、入れ子の別の呼び出し・配列・クロージャの中にある同名の引数を
     *   外側のものと取り違える (`fetch($this->build(followRedirects: false), …)` が通ってしまう)。
     *
     * @param  array<string, string>  $sources
     * @param  string  $literal  許すリテラル (例: `false`)
     * @return list<string> 引数が無い / 値が違う / 値を確定できない呼び出しの記述子
     */
    public static function callsWithoutNamedLiteral(
        array $sources,
        string $method,
        string $argument,
        string $literal,
    ): array {
        $loweredMethod = strtolower($method);

        $violations = [];
        foreach ($sources as $path => $source) {
            $tokens = PhpTokenScan::normalize($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                if ($tokens[$i]['id'] !== T_STRING || strtolower($tokens[$i]['text']) !== $loweredMethod) {
                    continue;
                }
                if (($tokens[$i + 1]['text'] ?? '') !== '(') {
                    continue;
                }
                // 宣言は呼び出しではない
                if (($tokens[$i - 1]['id'] ?? null) === T_FUNCTION) {
                    continue;
                }

                $end = self::matchingParenthesis($tokens, $i + 1);
                if ($end === null) {
                    // 括弧の対応が取れない = 解決できない形なので**落とす** ((b) fail-closed)
                    $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() の引数を読み切れない";

                    continue;
                }

                // ★**外側の引数リストの深さ 1 にあるものだけ**を対象にする。
                //   深さを見ないと、入れ子の別の呼び出しの同名引数を外側のものと取り違える
                //   (`fetch($this->build(followRedirects: false), $deadline)` が緑になってしまう)。
                $valuePosition = null;
                $depth = 1;
                for ($k = $i + 2; $k < $end; $k++) {
                    $text = $tokens[$k]['text'];

                    if ($text === '(' || $text === '[' || $text === '{') {
                        $depth++;

                        continue;
                    }
                    if ($text === ')' || $text === ']' || $text === '}') {
                        $depth--;

                        continue;
                    }
                    if ($depth !== 1) {
                        continue;
                    }

                    if ($tokens[$k]['id'] === T_STRING
                        && $text === $argument
                        && ($tokens[$k + 1]['text'] ?? '') === ':'
                        // ★`?:` (三項) や `::` と取り違えない
                        && ($tokens[$k + 2]['text'] ?? '') !== ':'
                    ) {
                        $valuePosition = $k + 2;

                        break;
                    }
                }

                if ($valuePosition === null) {
                    $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() に {$argument}: が無い";

                    continue;
                }

                // ★値は**リテラルちょうど 1 トークン**であること。
                //   次のトークンが `,` か `)` でなければ式であり、静的に確定できない。
                $value = $tokens[$valuePosition]['text'] ?? '';
                $after = $tokens[$valuePosition + 1]['text'] ?? '';

                if (strtolower($value) !== strtolower($literal) || ($after !== ',' && $after !== ')')) {
                    $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() の {$argument}: が {$literal} でない";
                }
            }
```

### `EmailPromotionTest` の該当 3 本 (現在)

```php

test('消費の確定と適用の間に別経路がメールを入れたら、その更新を上書きしない', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);
    $service = app(EmailPromotionService::class);

    // ★**実際の継ぎ目で**割り込む。第 1 段 (消費) が戻った時点で commit は済んでいる。
    //   モデルイベントの listener に頼ると割り込みが**第 1 段の内側**で走ってしまい、
    //   「commit の後」という筋書きにならない (かつ listener の全削除は後続テストを汚す)。
    $email = $service->consumeToken($user, $token);
    expect($email)->not->toBeNull();

    // 別経路がメールを入れる (プロフィール更新など)
    $user->newQuery()->whereKey($user->getKey())->update([
        'email' => encryptedEmailFor('other@corp.example'),
    ]);

    // ★第 2 段はロックの下で読み直し、**上書きしない**
    expect($service->applyConfirmedEmail($user, $email))->toBeFalse();

    // ★別経路の更新が残る
    expect($user->fresh()?->email)->toBe('other@corp.example');
    // ★トークンは消費済みである (一回使用は保たれる)
    expect(EmailPromotion::query()->where('user_id', $user->id)->count())->toBe(0);
    // ★昇格の監査は作られない (適用していないため)
    expect(SecurityAuditEvent::query()
        ->where('user_id', $user->id)
        ->where('event_type', SecurityEventType::EmailChanged->value)
        ->count())->toBe(0);
    // ★同じトークンは再利用できない
    $this->actingAs($user)
        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
        ->assertSessionHasErrors('email_promotion');
});

test('割り込みが無ければ第 2 段は適用する (正のコントロール)', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);
    $service = app(EmailPromotionService::class);

    $email = $service->consumeToken($user, $token);
    expect($email)->not->toBeNull();

    expect($service->applyConfirmedEmail($user, $email))->toBeTrue();
    expect($user->fresh()?->email)->toBe('new@corp.example');
});

test('監査を記録できなければメールの変更も巻き戻る (記録の無い変更を作らない)', function (): void {
    $user = promotionUser();
    $token = issuePromotion($user);

    // ★監査の書き込みを**挿入の後**で壊す。`creating` で止めると行がそもそも生まれないので
    //   「監査行が無い」は巻き戻しの証拠にならない (壊し方が弱いと主張が空振りする)。
    //   `created` なら**一度は挿入されている**ので、外側の「監査行も無い」が
    //   巻き戻しそのものを固定する。
    // ★`SecurityAuditEvent` は model event を登録する trait / observer を持たない
    //   (`HasFactory` だけ) ので、後始末の `flushEventListeners()` が
    //   本番の購読を落とすことはない。CipherSweet を使うモデル (`UsesCipherSweet` が
    //   `retrieved` / `saving` / `saved` を張る) で同じことをしてはならない。
    SecurityAuditEvent::created(static function (SecurityAuditEvent $event): never {
        // ★この時点では挿入済みで見える (巻き戻しが効いていることを外側と対で示す)
        expect(SecurityAuditEvent::query()->whereKey($event->getKey())->exists())->toBeTrue();

        throw new RuntimeException('監査の書き込みに失敗した');
    });

    try {
        expect(fn () => app(EmailPromotionService::class)->confirm($user, $token))
            ->toThrow(RuntimeException::class);
    } finally {
        SecurityAuditEvent::flushEventListeners();
    }

    $fresh = $user->fresh();
    // ★メールは入っていない
    expect($fresh?->email)->toBeNull();
    // ★確認時刻も昇格の時刻へ動いていない
    expect($fresh?->email_verified_at?->equalTo($user->email_verified_at))->toBeTrue();
    // ★監査行も無い
    expect(SecurityAuditEvent::query()
        ->where('user_id', $user->id)
        ->where('event_type', SecurityEventType::EmailChanged->value)
        ->count())->toBe(0);
    // ★トークンは第 1 段で消費済みである (設計どおり戻さない)
```

---

## 再確認をお願いしたい点

1. **メール更新と監査の原子性**: 記録を第 2 段のトランザクションの**中**へ移し
   `recordOrFail()` にした形が、指摘された 4 つの状態
   (メール更新済み / 監査行なし / 500 / トークン消費済み) を実際に閉じているか。
   blind index の衝突時に `EmailPromotionConflictException` へ変換する経路で、
   監査行も一緒に巻き戻ることに問題は無いか。
2. **割り込みテストの時点**: 第 1 段 (`consumeToken()`) が**戻った時点で commit 済み**であることを
   使って「commit の後・第 2 段の前」を再現した形が、Round 2/3 で問題にした窓と**同じもの**を
   測っているか。段を `public` にして継ぎ目に名前を付けた判断
   (2 段構成そのものが本サービスの契約である、という理由) に異論は無いか。
   `applyConfirmedEmail()` を公開したことで**新しい誤用の口**が開いていないか
   (第 1 段を経ずに任意の `VerifiedEmail` を適用できる) — ここは意見が欲しい。
3. **走査器の深さ判定**: `(` `[` `{` で深さを増やし深さ 1 だけを見る形で、指摘された誤認
   (`fetch($this->build(followRedirects: false), $deadline)`) を落とせているか。
   **落とし損ねる形が他に無いか** — 特に (a) 文字列の中の括弧、(b) 文字列内挿 (`"{$x}"`) の
   `T_CURLY_OPEN`、(c) attribute `#[...]`、(d) `match` 式の `{}`、(e) first-class callable
   `fetch(...)` を想定している。`PhpTokenScan::normalize()` は空白とコメントだけを落とし、
   文字列リテラルは 1 トークンにするため (a) は起きないと考えているが、確認してほしい。
4. **負例・正例の対**: 見本に足した 2 形 (入れ子にしか引数が無い = 違反 / 外側が false で
   内側にも同名 = 正例) と自己検査 2 本で、深さ判定の**両方向**が固定できているか。
5. **`recordOrFail()` の使用者集合**: 上記「自主的に直した 2 件」の (i) について、
   docblock を直すだけで足りるか、それとも**走査器で使用者を deny-by-default に縛る**べきか。
   同様に、`flushEventListeners()` の使用を縛る検査が要るか。
6. その他、これらの対応で**新しく生まれた欠陥**が無いか。

全体判定を `APPROVED` または `CHANGES_REQUESTED` で明示してほしい。
`CHANGES_REQUESTED` の場合は、**承認を阻む項目**と**阻まない項目 (Suggestion)** を
はっきり分けて書いてほしい (残りは 1 ラウンドである)。

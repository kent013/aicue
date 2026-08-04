# 対応マトリクス: impl-review Round 1

Codex 判定: **APPROVED** (Critical 0 / Warning 1 / Suggestion 2)。
Round 1 で APPROVED のため合議ループは 1 ラウンドで終了する。

## [Warning] `QuotaStatusDto::build()` の `array_values($exceeded)` が実装で外されている (設計との差分)

- 判断: **反論する (実装を正とし、設計側の差分として記録する)**
- 根拠: 詳細設計は「append のみだが list 契約を構造的に保証する」として `array_values()` を
  書いていたが、**PHPStan level 10 が `arrayValues.list` (「既に list なので効果が無い」) で
  error を出す**。禁止事項 2 により widen / baseline / `@phpstan-ignore` で黙らせることはできず、
  inline `@var` でも推論を上書きしない方針のため、**呼び出しを外すのが唯一の適法な解**である。
  設計文書がツールの実挙動と食い違っていたケースなので、実コードを正とする。
- 対応内容: `array_values()` を削除し、代わりに
  「append のみで組み立てるため `list<string>` のまま (PHPStan が推論する)。
   将来 filter 等でキーが飛ぶ操作を挟むなら、その時点で `array_values` を足すこと。」
  というコメントを `$exceeded = []` の直上に置いた。**構造保証の意図は文章として残る**ため
  設計の狙い (将来の変更で list 契約が崩れないようにする) は失われていない。
  この差分は StructuredOutput の `deviations_from_design` にも記録する。

## [Suggestion] `'/'.AdminPanelPath::resolve()` を他箇所と同じ呼び方に揃える

- 判断: **見送る**
- 根拠: `AdminPanelPath::resolve()` は docblock で「trim 済み・空なら 'admin'」= **先頭スラッシュを
  含まない**ことを契約として宣言しており (二重 fail-safe)、`'/'.` の連結はその契約に沿った
  正しい使い方である。`bootstrap/app.php` の既存利用も同じ契約前提で `resolve()` の戻り値を
  path 比較に使っており、「揃える」対象となる別の呼び方は存在しない。
  契約に沿った 1 箇所の連結を避けるためにヘルパを増やすのはオーバーエンジニアリング (思考原則 2)。

## [Suggestion] Browser テストの `click('通知を確認')` に `data-testid` を付けて堅牢化する

- 判断: **見送る**
- 根拠: 本タスクの設計スコープは T089/T090 の残存 6 論点の確定であり、Dashboard の
  リンクに testId を足すのは設計に無い変更 (思考原則 2 / 「設計に無いものを足さない」)。
  文言結合の脆さは**テスト内コメントで既に明示済み**
  (「文言は Dashboard.svelte 由来 (testId 未付与)。変わったら本テストを追随させること」)
  であり、壊れたときに追随先が分かる形にはなっている。
  testId 付与が要るなら Dashboard 側の別タスクとして扱う。

---

## Round 1 以降に入れた**追加の修正** (レビュー対象)

Round 1 で APPROVED をもらった後、**実際に検証を回した結果として 3 点の修正**が入りました。
特に (B) は Browser テストが両レーンで fail した実バグ (テスト側の誤った事後条件) の修正です。
この delta について、見落としがないか確認してください。

### (A) LogoutResponse docblock の陳腐化修正 (設計外・drift fix)

docblock が「/logout 導線は 2 箇所 (AppLayout / VerifyEmail)」と書いていたが、
実際は 3 箇所 (+ `pages/Auth/ConfirmRecentAuth.svelte`) で、`docs/supported-browsers.md` 側は
正しく 3 箇所と書いていた。本タスクの目的 (決定と事実を恒久文書に正しく固定する) に照らし、
同 docblock を編集しているついでに実コードを正として直した。

### (B) Browser テスト B1 の事後条件が誤っていた (両レーン fail → 修正)

詳細設計のテスト計画 B1 は「/login 着地後は `sessionStorage.getItem('historyKey') === null`」
を要求していたが、**実測でこれは決して満たされない**。probe 結果:

```
[probe] before-logout historyKey type=string len=114
[probe] after-204   same-as-before=true
[probe] t+0ms .. t+1900ms   null=false  changed=true  (全サンプルで同一)
```

原因: `EncryptHistory` は guest 面 (`/login`) にもグローバル適用されるため、
クライアントは `history.clear()` で旧鍵を捨てた直後に、着地ページ用の**新しい鍵を即座に採番**して
`sessionStorage` へ書き戻す。よって「欄が空になる」瞬間は観測できない。

守るべき性質は「鍵の欄が空になること」ではなく「**旧鍵が二度と手に入らないこと**」なので、
ログアウト前の鍵の実値を控えて「現在の historyKey が旧鍵と一致しないこと」を待つ形に変更した。
正のコントロール (2) も「204 直後は非 null」から「204 直後は**同一値のまま**」へ強化した。
観測結果は devnotes の probe 記録と `docs/supported-browsers.md` に固定した
(次の読者が assertion を null 判定へ「直して」しまわないため)。

### (C) QuotaStatusDto の array_values 削除 (上の対応マトリクス [Warning] のとおり)

PHPStan level 10 が `arrayValues.list` で error を出したため。禁止事項 2 により
widen / baseline / @phpstan-ignore で黙らせられないので、呼び出しを外してコメントで意図を残した。

## 検証結果 (最終)

- `composer phpstan` (level 10, 747 files): **No errors**
- `composer test`: **2631 tests / 2629 passed / 2 skipped / 0 failed**
- `pnpm test`: **105 files / 955 tests 全 pass**
  (初回は他 worktree 同時実行による load 40+ で無関係な 24 ファイルが
   `Test timed out in 5000ms`。負荷低下後のフル再実行で green)
- `composer test:browser`: **chromium 11 passed / webkit 11 passed** (各 3 skip。両レーン green)
- `pnpm build` / `pnpm typecheck` / `pnpm lint`: green
- `vendor/bin/pint --test`: **T097 の変更ファイルは全て passed**。main から継承した既存 fail が
  3 件残る (`devnotes/20260804-0900-sop-pdf-mojibake/probe/*.php` = 別タスクの設計 probe。
  main でも同じく fail する。並行 worktree との衝突を避けるため本タスクでは触らない)

## 質問

1. (B) の修正で、**セキュリティ性質の固定として十分**か
   (旧鍵の値比較で「過去エントリが復号不能になった」ことの証明になっているか)。
   より強い観測方法があれば指摘してほしい
2. (A) の設計外 drift fix は本タスクに含めてよいか
3. その他、Round 1 で見落としていた点があれば

全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。

## 追加修正の差分
```diff
diff --git a/app/DataTransferObjects/Billing/QuotaStatusDto.php b/app/DataTransferObjects/Billing/QuotaStatusDto.php
new file mode 100644
index 0000000..9b57fe9
--- /dev/null
+++ b/app/DataTransferObjects/Billing/QuotaStatusDto.php
@@ -0,0 +1,95 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\Enums\QuotaKey;
+
+/**
+ * 課金ダッシュボードに出す現行 quota の状態 (上限 + 使用量 + 超過次元)。
+ *
+ * 上限の出典は QuotaService::limits() (プラン既定 + organization override のマージ結果)。
+ * limits に key が無い = 無制限 = null。maxStorageGb は GiB 換算の表示値で、換算規則は
+ * PricingService::storageGb と同一 (intdiv(bytes, 1024**3) 切り捨て)。
+ *
+ * **超過 (exceededLabels) は「使用量 > 上限」の厳密超過のみ**を指す。
+ * 「上限ちょうど」(1/1 等) は plan の設計どおりの正常状態なので警告に含めない
+ * (>= にすると max_projects=1 の starter / personal の全組織にプロジェクトを 1 つ作った
+ *  時点から恒常警告が出て、本当の超過が埋もれる)。「上限に達した」ことへの気づきは
+ * 警告ではなく **使用量 / 上限の併記表示**が担う。
+ * 判定は**バイト等の生の単位**で行い、表示用の GiB 切り捨て値では判定しない。
+ *
+ * メンバー数は**上限のみ**を持つ (使用量も超過も出さない): max_members を
+ * QuotaService::check する呼び出し元は存在せず実効的に未強制のため、
+ * 「超過すると止まる」と読める表示をしない (App\Enums\QuotaKey の docblock 参照)。
+ *
+ * @phpstan-type QuotaStatusShape array{
+ *   maxProjects: int|null,
+ *   maxMembers: int|null,
+ *   maxStorageGb: int|null,
+ *   projectsUsed: int,
+ *   storageUsedBytes: int,
+ *   exceededLabels: list<string>
+ * }
+ */
+final readonly class QuotaStatusDto
+{
+    /**
+     * @param  list<string>  $exceededLabels  超過している次元の表示名 (QuotaKey::label())
+     */
+    public function __construct(
+        public ?int $maxProjects,
+        public ?int $maxMembers,
+        public ?int $maxStorageGb,
+        public int $projectsUsed,
+        public int $storageUsedBytes,
+        /** @var list<string> */
+        public array $exceededLabels,
+    ) {}
+
+    /**
+     * QuotaService::limits() の結果と実使用量から組み立てる。
+     *
+     * @param  array<string, int>  $limits
+     */
+    public static function build(array $limits, int $projectsUsed, int $storageUsedBytes): self
+    {
+        $projectLimit = $limits[QuotaKey::MaxProjects->value] ?? null;
+        $storageLimit = $limits[QuotaKey::MaxStorageBytes->value] ?? null;
+
+        // append のみで組み立てるため list<string> のまま (PHPStan が推論する)。
+        // 将来 filter 等でキーが飛ぶ操作を挟むなら、その時点で array_values を足すこと。
+        $exceeded = [];
+        if ($projectLimit !== null && $projectsUsed > $projectLimit) {
+            $exceeded[] = QuotaKey::MaxProjects->label();
+        }
+        if ($storageLimit !== null && $storageUsedBytes > $storageLimit) {
+            $exceeded[] = QuotaKey::MaxStorageBytes->label();
+        }
+
+        return new self(
+            maxProjects: $projectLimit,
+            maxMembers: $limits[QuotaKey::MaxMembers->value] ?? null,
+            maxStorageGb: $storageLimit === null ? null : intdiv($storageLimit, 1024 ** 3),
+            projectsUsed: $projectsUsed,
+            storageUsedBytes: $storageUsedBytes,
+            exceededLabels: $exceeded,
+        );
+    }
+
+    /**
+     * @return QuotaStatusShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'maxProjects' => $this->maxProjects,
+            'maxMembers' => $this->maxMembers,
+            'maxStorageGb' => $this->maxStorageGb,
+            'projectsUsed' => $this->projectsUsed,
+            'storageUsedBytes' => $this->storageUsedBytes,
+            'exceededLabels' => $this->exceededLabels,
+        ];
+    }
+}
diff --git a/app/Http/Responses/Fortify/LogoutResponse.php b/app/Http/Responses/Fortify/LogoutResponse.php
index 656fde8..c5780a1 100644
--- a/app/Http/Responses/Fortify/LogoutResponse.php
+++ b/app/Http/Responses/Fortify/LogoutResponse.php
@@ -50,9 +50,19 @@
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
  * JSON 204 経路はリポジトリ内では Browser テストの補助 (経路 B の再現) にしか使われていない。
  * **ログアウト導線を非 Inertia 経路で新設すると経路 C の保証条件が崩れる**。
diff --git a/docs/supported-browsers.md b/docs/supported-browsers.md
index d93484b..c21d16f 100644
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
@@ -23,6 +26,20 @@ # サポート対象ブラウザ方針
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
+> 効いているかを確かめるときは **null 判定ではなく値の変化**を見ること
+> (`tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php` がこの形で固定している)。
+
 「対応している」という言葉を検証レベルと切り離さないこと。
 本書では **Current (実際に回っている検証)** と **Target (到達目標)** を分けて書く。
 
@@ -113,14 +130,37 @@ ## 未対応事項 (誤読を防ぐため明示列挙する)
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
index da20a5d..7a21eac 100644
--- a/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php
+++ b/tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php
@@ -202,3 +202,115 @@ function inertiaHistoryWaitUntil(
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
+    // 画面遷移していないタブ」を作り、次の Inertia visit で **旧鍵が捨てられる**ことを観測する。
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
+    // 本丸 (1): **旧鍵が失われている** = 以降の「戻る」で過去エントリを復号できない。
+    //
+    // ここを `historyKey === null` で書いてはいけない。EncryptHistory は guest 面
+    // (/login) にもグローバル適用されるため、Inertia は clearHistory で鍵を消した直後に
+    // **着地ページ用の新しい鍵を即座に採番して sessionStorage へ書き戻す**
+    // (実測: 鍵は常に非 null のまま、値だけが入れ替わる)。
+    // 守るべき性質は「鍵の欄が空になること」ではなく
+    // 「**旧鍵が二度と手に入らないこと**」なので、値の変化で固定する。
+    $keyEscaped = json_encode($keyBefore, JSON_THROW_ON_ERROR);
+    inertiaHistoryWaitUntil(
+        $page,
+        "window.sessionStorage.getItem('historyKey') !== {$keyEscaped}",
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

## 新規 devnotes (probe 記録)

# probe 記録: `clearHistory` 消費後の `sessionStorage.historyKey` の実挙動

**いつ**: T097 実装中、Browser テスト B1 が Chromium / WebKit の両レーンで fail したため。

## 何が起きたか

詳細設計のテスト計画 B1 は「`/login` 着地後は
`window.sessionStorage.getItem('historyKey') === null` になっていること」を
「鍵が実際に消えたことの直接観測」として要求していた。**これが両レーンで満たされない**。

## 実測 (probe)

`tests/Browser/` に一時 probe を置き、Chromium レーンで観測した (probe は観測後に削除)。

```
[probe] before-logout historyKey type=string len=114
[probe] after-204   same-as-before=true
[probe] t+0ms  .. t+1900ms   null=false  changed=true   (全サンプルで同一)
```

- JSON 204 ログアウト直後: 鍵は **変化しない** (タブはまだ何も知らない)。設計どおり。
- `/login` 着地後: 鍵は **null にならず、値だけが入れ替わる**。しかも即座に
  (t+0ms の時点で既に changed)。

## 原因

`Inertia\Middleware\EncryptHistory` は **guest 面を含めてグローバル適用**されており
(`InertiaHistoryGuardTest` が「認証済み / 公開の区別なく `encryptHistory: true` が載る」
ことを固定している)、着地の `/login` ページ自身も暗号化対象である。
クライアントは `page.set()` 冒頭で `history.clear()` して旧鍵を捨てた直後に、
その `/login` ページを history へ入れるために**新しい鍵を採番して `sessionStorage` へ書き戻す**。
したがって「欄が空になる」瞬間は観測不能で、観測できるのは「値が変わったこと」だけ。

## 結論 (実装への反映)

守るべきセキュリティ性質は「鍵の欄が空になること」ではなく
**「旧鍵が二度と手に入らないこと」** である。B1 の assertion を

- before: `historyKey === null` を待つ
- after: ログアウト前の鍵の実値を控え、`historyKey !== <旧鍵>` を待つ

に変更した。正のコントロール (2) も「204 直後は鍵が残っている (非 null)」から
**「204 直後は鍵が同一のまま」** へ強化した (非 null より強い前提の固定になる)。

この観測結果は `docs/supported-browsers.md` の経路 C 節にも
「null 判定ではなく値の変化を見ること」として固定した
(次に読む人が assertion を null 判定へ「直して」しまわないようにするため)。

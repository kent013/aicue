## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

## 禁止事項（AGENTS.md）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`（ログイン直後フロー専用。招待送信等は back() で完結）
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する）
9. Artifact の使用

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: レビュアーの役割

あなたは Laravel + Svelte の改善実装をレビューするコードレビュアーである。TODO T239 (bughunt-profile-feedback-a11y) の実装差分をレビューせよ。以下の観点で評価すること:

- **設計との一致性**: 添付の詳細設計書 (APPROVED) の通りに実装されているか。設計から逸脱している箇所はないか。
- **正確性**: バグ修正 (F-4-01: メール変更成功フィードバック / F-3-01: オートリチャージ範囲エラー a11y) が正しく機能するか。エッジケース漏れはないか。
- **PHPStan 適合性 (level 10)**: 型安全性。null 安全。narrowing。
- **DTO/JsonResource パターン**: `response()->json()` 直書き違反はないか (今回は Fortify contract の JsonResponse/RedirectResponse 契約に従う箇所)。
- **テスト網羅性**: バグ修正がテストファーストで、正しい理由で回帰を検出できるか。既存テストの削除・カバレッジ喪失はないか。
- **セキュリティ**: 認証・認可・recent-auth ゲートへの影響。
- **DESIGN.md 準拠**: design token 経由参照 (hex 直書きを増やさない)。sr-only / aria-* の a11y 配線。
- **Atomic Design 準拠**: features/billing 配下の organism 相当が atom/molecule の責務を逆流していないか。共有 atom/molecule (FormField/Input/FormError) を不必要に改変していないか。Lucide アイコン使用 (SVG 直書きを増やさない)。

出力形式: ファイルごとに判定し、指摘を [Critical] / [Warning] / [Suggestion] に分類。最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示すること。

---

## user

### 詳細設計書（要点抜粋）

- **施策1 (F-4-01)**: `ProfileUpdatedResponse::toResponse()` で、メール変更時 (`$user->wasChanged('email')`) は `redirect()->route('verification.notice')->with('success', EMAIL_CHANGED_MESSAGE)` を返す。背景: メール変更で email_verified_at が null 化された状態で back() (/settings) へ戻すと、素の verified ゲートが verification.notice へもう一段 302 し、中間ホップで success flash が期限切れ廃棄される (bug-hunt F-4-01)。着地画面 (/email/verify、auth のみ) へ直接寄せる。判定は `!hasVerifiedEmail()` 単独ではなく `wasChanged('email')` (Codex Round 1 [Warning] 反映)。氏名のみ変更・同一 email は従来 back() 維持。expectsJson は 200 空 JSON 維持。禁止事項 #7 (intended) には抵触しない (名前付き route への明示 redirect)。

- **施策2 (F-3-01)**: `AutoRechargeCard.svelte` で範囲エラーを per-field 派生 (thresholdErrorText / maxErrorText) に再構成し、原因フィールド 1 つだけを FormField の `error` prop へ渡す (threshold-first 短絡で両欄同時 invalid にしない)。FormField 既存機構で invalid→Input aria-invalid、describedBy→FormError id が通る。可視の統合 `<p>` は撤去し、読み上げ用に常時 DOM 常在の visually-hidden な polite live region (sr-only) を 1 つ置き本文だけ更新する。妥当性ゲート `rangeError` は per-field の合流 (thresholdErrorText ?? maxErrorText) で従来 threshold-first と同値。表示は `inputErrorShown` による「押下時に初めて提示」の現行契約を維持 (禁止事項 #8)。共有 atom/molecule は改変しない。

（完全な設計書は devnotes/20260821-1517-bughunt-profile-feedback-a11y/detailed-design.md。上記は要点。）

### design system 参照 (DESIGN.md 関連抜粋)

- Input/atoms: `error` prop で danger 枠と `aria-invalid` が連動。`aria-describedby` は restProps 透過。ラベル・エラー文言・`aria-describedby` の配線は FormField molecule の責務。
- FormError/atoms: フィールド単位のエラー文言 (`text-caption text-danger`。message 無ければ何も描画しない)。フィールドに紐づかない失敗を FormError に流さない。
- FormField/molecules: children snippet に `{ id, describedBy, invalid }` を渡す。押下時に出した client エラーは、その後の入力に追随させる (stale invalid を残さない)。押下前には出さない。
- 触れた atomic ディレクトリ: `resources/js/components/features/billing/AutoRechargeCard.svelte` (organism 相当)。共有 `molecules/FormField.svelte` / `atoms/Input.svelte` / `atoms/FormError.svelte` は改変なし (既存 error prop を使うのみ)。

### テスト結果

- composer test (Pest, RefreshDatabase グローバル + --parallel): 6395 passed, 2 skipped, 0 failed (6397 tests, 30662 assertions)
- composer phpstan (level 10): No errors
- vendor/bin/pint --test: passed
- pnpm lint / typecheck / build: passed
- pnpm test (vitest): 2368 passed (173 files), AutoRechargeCard.test.ts 20 passed
- pnpm typecheck:packages / build:packages / test:packages: passed (106 tests)

テストファースト順序で、施策1-T/2-T を先に追加し「正しい理由で fail」を確認 (F-4-01: verification.notice への redirect と flash が無い / F-3-01: spinbutton に aria-invalid が付かない・live region が無い) してから実装を入れ green 化した。

### 実装差分 (git diff HEAD)

```diff
diff --git a/app/Http/Responses/Fortify/ProfileUpdatedResponse.php b/app/Http/Responses/Fortify/ProfileUpdatedResponse.php
index 2cbe5541..46ce4f83 100644
--- a/app/Http/Responses/Fortify/ProfileUpdatedResponse.php
+++ b/app/Http/Responses/Fortify/ProfileUpdatedResponse.php
@@ -4,6 +4,7 @@
 
 namespace App\Http\Responses\Fortify;
 
+use App\Models\User;
 use Illuminate\Http\JsonResponse;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
@@ -21,6 +22,13 @@ final class ProfileUpdatedResponse implements ProfileInformationUpdatedResponseC
 {
     private const string SUCCESS_MESSAGE = 'プロフィールを更新しました。';
 
+    /**
+     * メール変更時の成功メッセージ。着地は /email/verify (verification.notice) で、
+     * そこで「変更は成功した・次は認証」を明示する。新アドレス文字列は載せない
+     * (画面の auth.user.email が既に新アドレスを保持しており、メッセージへの埋め込みは冗長)。
+     */
+    private const string EMAIL_CHANGED_MESSAGE = 'メールアドレスを変更しました。新しいアドレスに認証メールを送信しましたので、認証を完了してください。';
+
     /**
      * @param  Request  $request
      */
@@ -30,6 +38,19 @@ public function toResponse($request): JsonResponse|RedirectResponse
             return new JsonResponse('', 200);
         }
 
+        // メール変更時は UpdateUserProfileInformation が email_verified_at を null 化する。
+        // その状態で back() (= /settings) へ戻すと、/settings の 'verified' (素の
+        // EnsureEmailIsVerified) が verification.notice へもう一段 302 し、素の verified は
+        // flash を keep しないため success flash がこの中間ホップで期限切れ廃棄される
+        // (bug-hunt F-4-01)。着地画面 (/email/verify、auth のみで verified ゲート外) へ
+        // 直接寄せ、そこで成功を明示する。$request->user() はこのリクエストで action が
+        // save() した同一インスタンスを memo 返しするため wasChanged('email') が読める。
+        $user = $request->user();
+        if ($user instanceof User && $user->wasChanged('email')) {
+            return redirect()->route('verification.notice')
+                ->with('success', self::EMAIL_CHANGED_MESSAGE);
+        }
+
         return back()->with('success', self::SUCCESS_MESSAGE);
     }
 }
diff --git a/resources/js/components/features/billing/AutoRechargeCard.svelte b/resources/js/components/features/billing/AutoRechargeCard.svelte
index 6b6ed008..709944a4 100644
--- a/resources/js/components/features/billing/AutoRechargeCard.svelte
+++ b/resources/js/components/features/billing/AutoRechargeCard.svelte
@@ -86,10 +86,13 @@
         return n;
     });
 
-    const rangeError = $derived.by<string | null>(() => {
-        if (parsedThreshold === null) {
-            return "リチャージ開始残高は 0 以上の整数で入力してください";
-        }
+    // 原因フィールドを 1 つに特定する raw 派生 (inputErrorShown 非依存 = 妥当性ゲート用)。
+    // threshold-first 短絡により thresholdErrorText と maxErrorText が同時に非 null にはならない。
+    const thresholdErrorText = $derived.by<string | null>(() =>
+        parsedThreshold === null ? "リチャージ開始残高は 0 以上の整数で入力してください" : null,
+    );
+    const maxErrorText = $derived.by<string | null>(() => {
+        if (parsedThreshold === null) return null; // 原因は threshold 側。max は巻き込まない
         if (parsedMax === null) {
             return `リチャージ後の残高は ${autoRecharge.minCount} 〜 ${autoRecharge.maxCountLimit} の整数で入力してください`;
         }
@@ -99,8 +102,12 @@
         return null;
     });
 
-    /** 表示中の入力エラー。提示開始後は rangeError に完全追随する (有効化で消え、理由が変われば文言も変わる) */
-    const inputError = $derived(inputErrorShown ? rangeError : null);
+    // 妥当性ゲート (ensureValidRange が参照)。単一 SoT: per-field の合流で従来の threshold-first と同値。
+    const rangeError = $derived(thresholdErrorText ?? maxErrorText);
+
+    // 表示は押下後に初めて提示する現行契約を維持 (禁止事項 #8)。提示開始後は現在入力に追随。
+    const thresholdError = $derived(inputErrorShown ? thresholdErrorText : null);
+    const maxError = $derived(inputErrorShown ? maxErrorText : null);
 
     // 適用単価: Max 枚をまとめ買いした場合の tier 単価 (同意文言の上限額と同じ計算)。
     const appliedUnit = $derived.by<number>(() => {
@@ -332,7 +339,11 @@
     {/if}
 
     <div class="mt-4 grid gap-4 md:grid-cols-2">
-        <FormField label="リチャージ開始残高 (残りがこの枚数を下回ったら購入)" id="auto-recharge-threshold">
+        <FormField
+            label="リチャージ開始残高 (残りがこの枚数を下回ったら購入)"
+            id="auto-recharge-threshold"
+            error={thresholdError}
+        >
             {#snippet children({ id, describedBy, invalid })}
                 <Input
                     {id}
@@ -351,7 +362,7 @@
                 />
             {/snippet}
         </FormField>
-        <FormField label="リチャージ後の残高 (この枚数まで補充)" id="auto-recharge-max">
+        <FormField label="リチャージ後の残高 (この枚数まで補充)" id="auto-recharge-max" error={maxError}>
             {#snippet children({ id, describedBy, invalid })}
                 <Input
                     {id}
@@ -381,15 +392,15 @@
         </p>
     {/if}
 
-    {#if inputError !== null}
-        <p
-            class="mt-2 text-caption text-danger"
-            aria-live="polite"
-            data-testid="auto-recharge-range-error"
-        >
-            {inputError}
-        </p>
-    {/if}
+    <!-- 可視の統合エラー <p> は撤去 (文言は各 FormField 内の FormError が per-field で描画する)。
+         読み上げ専用として、常時 DOM 常在の visually-hidden な polite live region を 1 つ置く。
+         要素は常在し本文だけが更新されるため、押下後のエラー出現が確実に通知される
+         (要素と本文の同時挿入だと SR が読み落とすことがあるため空要素を先に置く)。
+         テキストは提示中の単一アクティブエラー (threshold-first 短絡で常に高々 1 つ)。 -->
+    <p class="sr-only" aria-live="polite" data-testid="auto-recharge-range-error">
+        {#if inputErrorShown && (thresholdError ?? maxError)}{thresholdError ??
+                maxError}{/if}
+    </p>
 
     {#if showConsent}
         <div class="mt-4">
diff --git a/tests/Feature/Auth/FortifyResponseTest.php b/tests/Feature/Auth/FortifyResponseTest.php
index 9899bc4f..a1231330 100644
--- a/tests/Feature/Auth/FortifyResponseTest.php
+++ b/tests/Feature/Auth/FortifyResponseTest.php
@@ -6,6 +6,7 @@
 use Illuminate\Auth\Notifications\VerifyEmail;
 use Illuminate\Support\Facades\Notification;
 use Illuminate\Support\Facades\Password;
+use Inertia\Testing\AssertableInertia;
 
 /*
  * Fortify Response contract bind (app/Http/Responses/Fortify/) の応答契約の正本。
@@ -102,6 +103,105 @@
     $response->assertStatus(200);
 });
 
+/*
+ * F-4-01: メール変更成功時に認証画面 (verification.notice) で成功フィードバックを出す。
+ *
+ * バグ: メール変更で email_verified_at が null 化された状態で back() (= /settings) へ
+ * 戻すと、/settings の素の verified ゲートが verification.notice へもう一段 302 し、
+ * success flash が中間ホップで期限切れ廃棄される。着地画面 (/email/verify、auth のみ) へ
+ * 直接寄せ、そこで成功を明示する。
+ */
+
+// EMAIL_CHANGED_MESSAGE (ProfileUpdatedResponse::EMAIL_CHANGED_MESSAGE と同値であることを固定する)
+$emailChangedMessage = 'メールアドレスを変更しました。新しいアドレスに認証メールを送信しましたので、認証を完了してください。';
+
+test('メール変更 (fresh + web) は verification.notice へ redirect し success flash を載せる', function () use ($emailChangedMessage): void {
+    Notification::fake();
+    // 変更前は verified 済み。fresh を明示設定 (Factory 暗黙 default に依存させない)。
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)
+        ->withSession(['recent_auth_at' => time()])
+        ->from('/settings')
+        ->put('/user/profile-information', [
+            'name' => $user->name,
+            'email' => 'new@example.com',
+        ]);
+
+    $response->assertRedirect(route('verification.notice'));
+    $response->assertSessionHas('success', $emailChangedMessage);
+});
+
+test('メール変更の着地画面が flash.success を Inertia prop として受け取る', function () use ($emailChangedMessage): void {
+    Notification::fake();
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withSession(['recent_auth_at' => time()])
+        ->from('/settings')
+        ->put('/user/profile-information', [
+            'name' => $user->name,
+            'email' => 'new@example.com',
+        ]);
+
+    // 302 の着地先を GET し、consumeFlash が読む共有 prop 値と着地 component 名まで固定する
+    // (session だけでなく props 配線の回帰、「正しい props だが誤った画面」の後退も検出)。
+    $this->actingAs($user)
+        ->get('/email/verify')
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Auth/VerifyEmail')
+            ->where('flash.success', $emailChangedMessage));
+});
+
+test('メール変更で新アドレスへ認証メールが送信される', function (): void {
+    Notification::fake();
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withSession(['recent_auth_at' => time()])
+        ->from('/settings')
+        ->put('/user/profile-information', [
+            'name' => $user->name,
+            'email' => 'new@example.com',
+        ]);
+
+    Notification::assertSentTo($user, VerifyEmail::class);
+});
+
+test('氏名のみ更新 (web) は従来どおり back() + プロフィール更新メッセージ', function (): void {
+    Notification::fake();
+    $user = User::factory()->create();
+
+    // email は現行と同一 → wasChanged('email')=false → verification.notice へは飛ばない
+    $response = $this->actingAs($user)
+        ->withSession(['recent_auth_at' => time()])
+        ->from('/settings')
+        ->put('/user/profile-information', [
+            'name' => '更新後の名前',
+            'email' => $user->email,
+        ]);
+
+    $response->assertRedirect('/settings');
+    $response->assertSessionHas('success', 'プロフィールを更新しました。');
+    expect($response->headers->get('Location'))->not->toBe(route('verification.notice'));
+});
+
+test('メール変更でも expectsJson は 200 の空 JSON 本文を維持する', function (): void {
+    Notification::fake();
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)
+        ->withSession(['recent_auth_at' => time()])
+        ->putJson('/user/profile-information', [
+            'name' => $user->name,
+            'email' => 'new@example.com',
+        ]);
+
+    $response->assertOk();
+    expect($response->headers->get('Content-Type'))->toContain('application/json');
+    expect($response->getContent())->toBe('""');
+});
+
 test('パスワード変更は success flash を返す (web)', function (): void {
     $user = User::factory()->create();
 
diff --git a/tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php b/tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php
index 79d91e72..1ed19c0e 100644
--- a/tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php
+++ b/tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php
@@ -99,6 +99,47 @@
     );
 });
 
+test('F-4-01: stale → 再認証完了 → 元操作再送で verification.notice + success flash', function (): void {
+    Notification::fake();
+    $user = User::factory()->create(['email' => 'old@example.com']);
+
+    // (1) stale セッションで email 変更 PUT (Inertia mutation) → 409 で反映されない (1a と同契約)
+    $this->actingAs($user)
+        ->withHeaders(['X-Inertia' => 'true'])
+        ->put('/user/profile-information', [
+            'name' => $user->name,
+            'email' => 'new@example.com',
+        ])
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required');
+
+    // (2) 同一セッションで再認証 (正しいパスワード) → 鮮度が stamp される。
+    // 直前の 409 (Inertia mutation) が dropped_mutation を stash するため、confirmPassword は
+    // 204 ではなく intended への redirect を返す (詳細は RecentAuthTest が担保)。ここでは
+    // 「再認証で鮮度が stamp される」ことだけ固定し、次段で元操作の再送を通す。
+    $this->actingAs($user)
+        ->postJson('/recent-auth/password', ['password' => 'password']);
+    expect(session('recent_auth_at'))->toBeInt();
+
+    // (3) 元の email 変更 PUT を再送 → gate 通過し verification.notice + success へ着地
+    $response = $this->actingAs($user)
+        ->from('/settings')
+        ->put('/user/profile-information', [
+            'name' => $user->name,
+            'email' => 'new@example.com',
+        ]);
+
+    $response->assertRedirect(route('verification.notice'));
+    $response->assertSessionHas(
+        'success',
+        'メールアドレスを変更しました。新しいアドレスに認証メールを送信しましたので、認証を完了してください。',
+    );
+
+    $user->refresh();
+    expect($user->email)->toBe('new@example.com');
+    expect($user->email_verified_at)->toBeNull();
+});
+
 test('5: stale + email 欠落/非string は recent-auth で gate されず email 不変', function (array $payload): void {
     Notification::fake();
     $user = User::factory()->create(['email' => 'me@example.com']);
diff --git a/tests/js/components/features/billing/AutoRechargeCard.test.ts b/tests/js/components/features/billing/AutoRechargeCard.test.ts
index a29aac5f..7a516ba6 100644
--- a/tests/js/components/features/billing/AutoRechargeCard.test.ts
+++ b/tests/js/components/features/billing/AutoRechargeCard.test.ts
@@ -110,60 +110,103 @@ describe("AutoRechargeCard", () => {
         expect(screen.getByTestId("auto-recharge-max-amount").textContent).toContain("¥3,500");
     });
 
-    it("不正な入力でもボタンは押せて、押下時にエラーを表示する (禁止事項 #8)", async () => {
+    // F-3-01: 範囲エラーは原因フィールドの spinbutton へ aria-invalid + aria-describedby を配線し、
+    // 巻き込みを避ける (両欄同時 invalid を作らない)。可視の統合 <p> は撤去し、読み上げは
+    // 常在の sr-only polite live region が担う。以下は testId 非依存の利用者視点 assert。
+    const thresholdInput = () =>
+        screen.getByRole("spinbutton", { name: /リチャージ開始残高/ });
+    const maxInput = () => screen.getByRole("spinbutton", { name: /リチャージ後の残高/ });
+
+    it("max の範囲エラーは max spinbutton だけを invalid にする (F-3-01・押下時に提示)", async () => {
         renderCard({ hasPaymentMethod: true });
 
-        const maxInput = screen.getByTestId("auto-recharge-max-input");
-        await fireEvent.input(maxInput, { target: { value: "0" } });
+        // minCount(1) 未満 → parsedMax=null
+        await fireEvent.input(maxInput(), { target: { value: "0" } });
 
         const enable = screen.getByTestId("auto-recharge-enable");
-        expect(enable.hasAttribute("disabled")).toBe(false);
-
+        expect(enable.hasAttribute("disabled")).toBe(false); // 押下でブロックしない (禁止事項 #8)
         await fireEvent.click(enable);
-        expect(screen.getByTestId("auto-recharge-range-error")).not.toBeNull();
+
+        expect(maxInput()).toHaveAttribute("aria-invalid", "true");
+        expect(maxInput()).toHaveAccessibleDescription(/リチャージ後の残高は 1 〜 1000 の整数/);
+        // threshold は巻き込まない (値指定なし。Input は false 時に属性省略)
+        expect(thresholdInput()).not.toHaveAttribute("aria-invalid");
         // エラー時は同意パネルを開かない
         expect(screen.queryByTestId("auto-recharge-consent")).toBeNull();
     });
 
-    it("押下前は範囲エラーを出さない (禁止事項 #8 の契約: 押下時に初めて提示する)", async () => {
+    it("threshold の解析エラーは threshold spinbutton だけを invalid にする", async () => {
         renderCard({ hasPaymentMethod: true });
 
-        await fireEvent.input(screen.getByTestId("auto-recharge-max-input"), {
-            target: { value: "0" },
-        });
+        // 負数 → parsedThreshold=null (非数値文字列は type=number の sanitize が DOM 依存なので使わない)
+        await fireEvent.input(thresholdInput(), { target: { value: "-1" } });
+        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
 
-        expect(screen.queryByTestId("auto-recharge-range-error")).toBeNull();
+        expect(thresholdInput()).toHaveAttribute("aria-invalid", "true");
+        expect(thresholdInput()).toHaveAccessibleDescription(/リチャージ開始残高は 0 以上の整数/);
+        expect(maxInput()).not.toHaveAttribute("aria-invalid");
     });
 
-    it("押下後に値を有効へ直すと範囲エラーが消える (F-3-05: stale invalid を残さない)", async () => {
+    it("個別有効だが max<=threshold のときは max spinbutton だけを invalid にする", async () => {
         renderCard({ hasPaymentMethod: true });
 
-        const maxInput = screen.getByTestId("auto-recharge-max-input");
-        await fireEvent.input(maxInput, { target: { value: "0" } });
+        // threshold=5(既定)・max=3 (1..1000 で個別有効かつ 3<=5) → 大小関係違反は max 側
+        await fireEvent.input(maxInput(), { target: { value: "3" } });
         await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
-        expect(screen.getByTestId("auto-recharge-range-error")).not.toBeNull();
+
+        expect(maxInput()).toHaveAttribute("aria-invalid", "true");
+        expect(maxInput()).toHaveAccessibleDescription(/開始残高より大きい値/);
+        expect(thresholdInput()).not.toHaveAttribute("aria-invalid");
+    });
+
+    it("押下前は aria-invalid が付かない (禁止事項 #8 の契約: 押下時に初めて提示する)", async () => {
+        renderCard({ hasPaymentMethod: true });
+
+        await fireEvent.input(maxInput(), { target: { value: "0" } });
+
+        expect(maxInput()).not.toHaveAttribute("aria-invalid");
+    });
+
+    it("押下後に値を有効へ直すと aria-invalid が消える (F-3-05: stale invalid を残さない)", async () => {
+        renderCard({ hasPaymentMethod: true });
+
+        await fireEvent.input(maxInput(), { target: { value: "0" } });
+        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
+        expect(maxInput()).toHaveAttribute("aria-invalid", "true");
 
         // 値を有効な組み合わせへ直す → 表示中のエラーは現在の入力に追随して消える
-        await fireEvent.input(maxInput, { target: { value: "50" } });
-        expect(screen.queryByTestId("auto-recharge-range-error")).toBeNull();
+        await fireEvent.input(maxInput(), { target: { value: "50" } });
+        expect(maxInput()).not.toHaveAttribute("aria-invalid");
     });
 
-    it("無効のまま別の無効理由に変えると文言が現在の理由へ追随する", async () => {
+    it("sr-only live region は常在し、押下後に本文が出て訂正で消える (可視 <p> 撤去の後退防止)", async () => {
         renderCard({ hasPaymentMethod: true });
 
-        const maxInput = screen.getByTestId("auto-recharge-max-input");
-        // 範囲外 (minCount 未満)
-        await fireEvent.input(maxInput, { target: { value: "0" } });
+        // 同一要素を使い続け、将来 {#if} に戻って要素差し替えになった場合も検出する
+        const liveRegion = screen.getByTestId("auto-recharge-range-error");
+        // (a) 押下前: 属性が生きていて本文は空 (aria-live が消えても素通りしない)
+        expect(liveRegion).toHaveClass("sr-only");
+        expect(liveRegion).toHaveAttribute("aria-live", "polite");
+        expect(liveRegion).toBeEmptyDOMElement();
+
+        // (b) max "0" + 押下後: 本文が単一アクティブ文言で出る
+        await fireEvent.input(maxInput(), { target: { value: "0" } });
         await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
-        expect(screen.getByTestId("auto-recharge-range-error").textContent).toContain(
-            "リチャージ後の残高は",
-        );
+        expect(liveRegion).toHaveTextContent(/リチャージ後の残高は 1 〜 1000 の整数/);
 
-        // 開始残高 (既定 5) 以下 = 大小関係の違反へ理由が変わる
-        await fireEvent.input(maxInput, { target: { value: "5" } });
-        expect(screen.getByTestId("auto-recharge-range-error").textContent).toContain(
-            "開始残高より大きい値",
-        );
+        // (c) 訂正後: 本文が消える
+        await fireEvent.input(maxInput(), { target: { value: "50" } });
+        expect(liveRegion).toBeEmptyDOMElement();
+    });
+
+    it("sr-only live region は threshold 側経路の文言も運ぶ ({maxError ?? \"\"} 誤実装を落とす)", async () => {
+        renderCard({ hasPaymentMethod: true });
+
+        const liveRegion = screen.getByTestId("auto-recharge-range-error");
+        await fireEvent.input(thresholdInput(), { target: { value: "-1" } });
+        await fireEvent.click(screen.getByTestId("auto-recharge-enable"));
+
+        expect(liveRegion).toHaveTextContent(/リチャージ開始残高は 0 以上の整数/);
     });
 
     it("canManage=false では両入力が readonly かつ muted になる (F-3-03)", () => {

```

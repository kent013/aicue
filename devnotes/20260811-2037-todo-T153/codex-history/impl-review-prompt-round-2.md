# 実装レビュー Round 2 (Round 1 の [Warning] 2 件への対応)

Round 1 の指摘 2 件は**どちらも対応した**（反論・見送りは 0 件）。

---

# 対応マトリクス: impl-review Round 1

Codex 返答: `devnotes/20260811-2037-todo-T153/impl-review-round-1.md`
全体判定: **CHANGES_REQUESTED**（[Critical] 0 件 / [Warning] 2 件 / [Suggestion] 0 件）

---

## [Warning] `.env.bughunt.local.example:57-59` のコメントが施策 8 と矛盾している

- 判断: **対応する**
- 根拠: 指摘は正しい。`grep -n 'MODE_ENV=' scripts/bug-hunt-shard.sh` で確認したところ
  `MODE_ENV` に `TESTING_FAKE_EXTERNALS` は**一度も入らない**（入れると
  「スクリプトが入れた値をスクリプトが検証する」トートロジーになるので設計が意図的に外している）。
  にもかかわらず「以下 TESTING_FAKE_* の実効値は script 注入が正本」「コピー忘れでも既定は崩れない」
  と一括で書いてあったため、施策 8 で fail-fast させる**当の欠落**を「起きない」と読ませる嘘になっていた。
  施策 7 の趣旨（残すと嘘になる記述を同一 PR で直す）に照らしても直すべき。
- 対応内容: 見出しコメントを 2 系統へ分割した。
  - `TESTING_FAKE_LLM` / `TESTING_FAKE_STORAGE` → 従来どおり「script 注入が正本」
  - `TESTING_FAKE_EXTERNALS` → 「**例外で script 注入しない**。dotenv 側で true 宣言が必須で、
    欠落は provision の実効 env 検証が `('fake_externals', (None, True))` の形で fail-fast させる」
    と、注入しない**理由**（トートロジー回避）まで書いた。

## [Warning] 新規テスト #9 が「Socialite に触れず」を実証していない

- 判断: **対応する**
- 根拠: 指摘は正しい。強化前の #9 は `login` へのリダイレクトと `assertGuest()` しか見ておらず、
  「driver を解決してから login へ戻る」実装に壊れても緑のままだった
  （= テスト名が主張する内容を検査していない = 偽グリーンの一種）。
  これは詳細設計 §「検査が空振りしないことの保証」の趣旨に反する。
- 対応内容: `$enableSsoFake()` の**後**に、`driver()` が呼ばれたら `RuntimeException` を投げる
  無名サブクラスを `SocialiteDriverResolver::class` へ後勝ちで bind し、到達の有無そのものを
  検出する形へ強化した（Codex の修正案どおり）。
  さらに **強化が効いていることを mutation で実測**した（`mutation-evidence.md` の M14）:
  `callback()` が intent 判定より前に driver を解決するよう壊すと #9 のみが赤くなり、
  失敗メッセージに `intent 不在の callback が Socialite driver を解決しました: google` が出る。
  **強化前の #9 はこの mutation を検出できなかった**ことも併せて記録した。

---

## 反論・見送りはなし

Round 1 では [Critical] が 0 件で、[Warning] 2 件はいずれも実コードで裏が取れたため
両方とも対応した（設計判断の蒸し返しは 1 件も無かった）。


---

## 修正差分 (Round 1 → Round 2。他ファイルは無変更)

```diff
diff --git a/.env.bughunt.local.example b/.env.bughunt.local.example
index f9ab4e8..5c48a44 100644
--- a/.env.bughunt.local.example
+++ b/.env.bughunt.local.example
@@ -54,16 +54,23 @@ CACHE_STORE=database
 # provision が起動する queue:listen worker が処理する (bug-hunt-shard.sh 参照)
 QUEUE_CONNECTION=sync
 
-# ▼ 以下 TESTING_FAKE_* の実効値は scripts/bug-hunt-shard.sh が provision 時に env 注入する値が正本。
-#   このファイルの記載は説明用で、実行時既定は script 注入が保証する (コピー忘れでも既定は崩れない)。
+# ▼ TESTING_FAKE_LLM / TESTING_FAKE_STORAGE の実効値は scripts/bug-hunt-shard.sh が
+#   provision 時に env 注入する値が正本 (MODE_ENV)。このファイルの記載は説明用で、
+#   実行時既定は script 注入が保証する (コピー忘れでも既定は崩れない)。
+#   ★TESTING_FAKE_EXTERNALS は**例外で script 注入しない**。注入すると
+#   「スクリプトが入れた値をスクリプトが検証する」トートロジーになり、dotenv 側の欠落を
+#   検出できなくなるため。したがって**この dotenv 側で true を宣言することが必須**であり、
+#   欠落は provision の実効 env 検証が ('fake_externals', (None, True)) の形で fail-fast させる。
 #
-# 外部サービス fake (Stripe 課金 gateway + captcha 検証器) の capability flag
+# 外部サービス fake (Stripe 課金 gateway + captcha 検証器 + SSO driver 解決点) の capability flag
 # (LLM は別フラグ fake_llm に分離)。
 # config('testing.fake_externals') を通して fake セットを有効化する
 # (Stripe: FakeExternalsServiceProvider が checkout/portal gateway を fake に bind。
 #  fake は決済せず中立帰還する。課金状態の正本は BughuntBillingSeeder。
-#  captcha: RecaptchaVerifier を RecaptchaVerifierTestFake へ bind し Google siteverify へ出さない)。
-# **SSO (Socialite) は fake しない** — bug-hunt のブラウザは SSO ボタンから実 IdP へ遷移する
+#  captcha: RecaptchaVerifier を RecaptchaVerifierTestFake へ bind し Google siteverify へ出さない。
+#  SSO: SocialiteDriverResolver を FakeSocialiteDriverResolver へ bind し、SSO ボタンは
+#  自アプリの social.callback へ戻る (実 IdP へ出ない)。SSO の env allowlist は
+#  testing / bughunt.local のみで local を除く)
 # (docs/architecture.md §外部到達点の目録 (標準形 v1))。
 # 運用注意: 本キーは bughunt 環境以外で有効化しない (本番は常時 false = config 既定。
 #  production では ProductionEnvGuard が fail-fast するが、flag 自体を触らないのが原則)。
diff --git a/tests/Feature/Auth/FakeSocialiteWiringTest.php b/tests/Feature/Auth/FakeSocialiteWiringTest.php
new file mode 100644
index 0000000..6ae23fa
--- /dev/null
+++ b/tests/Feature/Auth/FakeSocialiteWiringTest.php
@@ -0,0 +1,209 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\SocialAccount;
+use App\Models\User;
+use App\Providers\FakeExternalsServiceProvider;
+use App\Services\Auth\Fakes\FakeSocialiteProvider;
+use App\Services\Auth\SocialiteDriverResolver;
+use Illuminate\Testing\TestResponse;
+use Laravel\Socialite\Contracts\Provider;
+
+/*
+ * SSO (Socialite) fake 配線の behavioral gate (T153)。
+ *
+ * bug-hunt / 自動テストレーンのブラウザが SSO ボタンから**実 IdP へ出ない**ことを、
+ * 「アプリが返すリダイレクト先」の水準で固定する。
+ *
+ * ★負のコントロール (#1) を必ず一緒に緑に保つこと。#1 が落ちると #2 以降の green は
+ *   「もともと外に出ていなかった」を見ているだけになり、検査が空振りする。
+ */
+
+/**
+ * このテスト内でだけ SSO fake を配線する (レーン既定は flag off のまま)。
+ *
+ * ★global function にしない。Pest のファイル直下 function は**グローバル空間**に出るため、
+ *   将来別ファイルに同名 helper が足されると fatal になる
+ *   (現に RecentAuthTest は「SocialAuthTest の helper と名前衝突させない」と人手で回避している)。
+ *   closure なら構造的に起きない。
+ */
+$enableSsoFake = function (): void {
+    config(['testing.fake_externals' => true]);
+    (new FakeExternalsServiceProvider(app()))->register();
+};
+
+/** リダイレクト先 URL の host 部を取り出す (Location ヘッダ不在は null) */
+$locationHost = function (TestResponse $response): ?string {
+    $location = $response->headers->get('Location');
+    if (! is_string($location)) {
+        return null;
+    }
+
+    $host = parse_url($location, PHP_URL_HOST);
+
+    return is_string($host) ? $host : null;
+};
+
+test('負のコントロール: fake 無効 (レーン既定) では social.redirect が実 IdP ホストへ出る',
+    function () use ($locationHost): void {
+        // 前提を明示する: google が config から外れたら「host が違う」ではなく
+        // 「前提が崩れた」と読めるようにする。
+        expect(config()->array('template.social_providers'))->toHaveKey('google');
+        expect(config('testing.fake_externals'))->toBeFalse();
+
+        $response = $this->get('/auth/google/redirect/login');
+
+        $host = $locationHost($response);
+
+        expect($host)->toBe('accounts.google.com')
+            ->and($host)->not->toBe(parse_url((string) config('app.url'), PHP_URL_HOST));
+    });
+
+test('fake 有効: 宣言済み全 provider の social.redirect が自アプリ host に閉じる',
+    function () use ($enableSsoFake, $locationHost): void {
+        $enableSsoFake();
+
+        $providers = array_keys(config()->array('template.social_providers'));
+
+        // 母集団 0 件で緑にならないことの保証 (provider が増えれば検査も自動で増える)
+        expect($providers)->not->toBeEmpty();
+
+        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
+
+        foreach ($providers as $provider) {
+            $response = $this->get("/auth/{$provider}/redirect/login");
+
+            expect($locationHost($response))->toBe($appHost, "provider={$provider} が自アプリ host に閉じていません")
+                ->and((string) $response->headers->get('Location'))->toBe(
+                    route('social.callback', ['provider' => $provider]),
+                    "provider={$provider} の戻り先が social.callback ではありません",
+                );
+        }
+    });
+
+test('fake 有効: register intent の round-trip で User と SocialAccount と個人組織が作られる',
+    function () use ($enableSsoFake): void {
+        $enableSsoFake();
+
+        $this->get('/auth/google/redirect/register?terms_accepted=1')
+            ->assertRedirect(route('social.callback', ['provider' => 'google']));
+
+        $this->get(route('social.callback', ['provider' => 'google']))
+            ->assertRedirect(route('dashboard'));
+
+        $this->assertAuthenticated();
+
+        $user = User::whereBlind('email', 'email_index', 'fake-google-sso@example.com')->firstOrFail();
+        expect($user->socialAccounts()->where('provider', 'google')
+            ->where('provider_user_id', 'fake-google-user')->exists())->toBeTrue()
+            ->and($user->organizations()->where('is_personal', true)->count())->toBe(1);
+    });
+
+test('fake 有効: login intent の round-trip で連携済みユーザーとしてログインする',
+    function () use ($enableSsoFake): void {
+        $enableSsoFake();
+
+        $user = User::factory()->create();
+        SocialAccount::factory()->for($user)->create([
+            'provider' => 'google',
+            'provider_user_id' => 'fake-google-user',
+        ]);
+
+        $this->get('/auth/google/redirect/login')
+            ->assertRedirect(route('social.callback', ['provider' => 'google']));
+
+        $this->get(route('social.callback', ['provider' => 'google']))
+            ->assertRedirect(route('dashboard'));
+
+        $this->assertAuthenticatedAs($user);
+    });
+
+test('fake 有効: link intent の round-trip でログイン中ユーザーに連携が付く',
+    function () use ($enableSsoFake): void {
+        $enableSsoFake();
+
+        $user = User::factory()->create();
+        $this->actingAs($user);
+
+        $this->get('/auth/google/redirect/link')
+            ->assertRedirect(route('social.callback', ['provider' => 'google']));
+
+        $this->get(route('social.callback', ['provider' => 'google']))
+            ->assertRedirect(route('settings.security'))
+            ->assertSessionHas('success');
+
+        expect($user->socialAccounts()->where('provider', 'google')
+            ->where('provider_user_id', 'fake-google-user')->count())->toBe(1);
+    });
+
+test('fake 有効: step-up intent の round-trip で recent-auth の鮮度が stamp される',
+    function () use ($enableSsoFake): void {
+        $enableSsoFake();
+
+        $user = User::factory()->create();
+        SocialAccount::factory()->for($user)->create([
+            'provider' => 'google',
+            'provider_user_id' => 'fake-google-user',
+        ]);
+
+        $this->actingAs($user)->withSession(['url.intended' => '/settings']);
+
+        $this->get('/auth/google/redirect/step-up')
+            ->assertRedirect(route('social.callback', ['provider' => 'google']));
+
+        $this->get(route('social.callback', ['provider' => 'google']))
+            ->assertRedirect('/settings');
+
+        expect(session('recent_auth_method'))->toBe('sso')
+            ->and(session('recent_auth_provider'))->toBe('google');
+    });
+
+test('fake の identity は provider ごとに決定論的で、一目で fake と分かる', function (): void {
+    $user = (new FakeSocialiteProvider('google'))->user();
+
+    expect($user->getId())->toBe('fake-google-user')
+        ->and($user->getEmail())->toBe('fake-google-sso@example.com')
+        ->and($user->getName())->toBe('SSO Fake User (google)')
+        ->and($user->getId())->toStartWith('fake-');
+});
+
+test('fake は local 環境では配線されない (実 IdP 連携の確認手段を残す)', function (): void {
+    $originalEnvironment = $this->app['env'];
+    $originalFlag = config('testing.fake_externals');
+
+    try {
+        $this->app['env'] = 'local';
+        config(['testing.fake_externals' => true]);
+
+        (new FakeExternalsServiceProvider($this->app))->register();
+
+        // ★厳密一致 (fake は real のサブクラスなので instanceof では対照が無意味になる)
+        expect(app(SocialiteDriverResolver::class)::class)->toBe(SocialiteDriverResolver::class);
+    } finally {
+        config(['testing.fake_externals' => $originalFlag]);
+        $this->app['env'] = $originalEnvironment;
+    }
+});
+
+test('fake 有効でも social.callback は intent 不在なら Socialite に触れずログインへ戻す',
+    function () use ($enableSsoFake): void {
+        $enableSsoFake();
+
+        // ★「login へ戻る」だけでは**触れていないこと**を実証できない (driver を呼んでから
+        //   login へ戻る実装に壊れても緑になる)。呼ばれたら必ず落ちる resolver を後勝ちで
+        //   bind し、到達の有無そのものを検出する。
+        $this->app->bind(SocialiteDriverResolver::class, fn (): SocialiteDriverResolver => new class extends SocialiteDriverResolver
+        {
+            public function driver(string $provider): Provider
+            {
+                throw new RuntimeException("intent 不在の callback が Socialite driver を解決しました: {$provider}");
+            }
+        });
+
+        $this->withSession([])
+            ->get(route('social.callback', ['provider' => 'google']))
+            ->assertRedirect(route('login'));
+
+        $this->assertGuest();
+    });

```

---

## 再検証結果 (修正後に実測)

```
composer phpstan       → OK (No errors)
vendor/bin/pint --test → passed
composer test          → tests=4475 passed=4473 skipped=2 failed=0
composer test -- --filter=FakeSocialiteWiring → tests=9 passed=9 failed=0
```

### 追加 mutation (M14) — #9 強化が効いていることの実測

`SocialAuthController::callback()` が **intent 判定より前**に
`$this->socialiteDriver->driver($provider)` を呼ぶよう改変すると:

```
--filter=FakeSocialiteWiring → tests=9 passed=8 failed=1
FAIL: fake 有効でも social.callback は intent 不在なら Socialite に触れずログインへ戻す
  RuntimeException: intent 不在の callback が Socialite driver を解決しました: google
  at SocialAuthController.php(82): SocialiteDriverResolver@anonymous->driver('google')
```

**強化前の #9 はこの mutation を検出できなかった**（指摘どおり）。mutation は復元済み。

---

## 依頼

上記 2 件の対応が指摘を解消しているか確認し、**全体判定 (APPROVED / CHANGES_REQUESTED)** を
1 行で示せ。新たな [Critical] / [Warning] があれば具体的な行と修正案を添えて指摘せよ。

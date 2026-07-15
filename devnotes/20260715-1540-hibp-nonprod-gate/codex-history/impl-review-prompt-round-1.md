# 使命・禁止事項・思考原則（レビュー基準）

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

## 禁止事項（AGENTS.md 正本）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

### セキュリティ不変条件（本施策に直結）

- **production では必ず `uncompromised()`(HIBP) が有効**。config/env/`fake_externals` で無効化できてはならない(fail-secure)。
- `fake_externals` は `ProductionEnvGuard` により production で `true` になれない(deploy 時 fail-fast)。

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考えろ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: あなたの役割

あなたは Laravel + Svelte アプリの実装レビュアーである。以下の観点で厳密にレビューせよ:

- **設計との一致性**: 詳細設計書どおりに実装されているか。
- **正確性**: ロジックのバグ、エッジケース、fail-secure 不変条件の崩れがないか。特に「production で HIBP が絶対に外れないこと」「未知 env が既定 ON になること」を検証せよ。
- **PHPStan 適合性(level 10)**: 型注釈・generics・null 安全。
- **テスト網羅性**: env matrix の全分岐、production 不変条件、fake_externals 非結合、HIBP 不送出(Feature)が固定されているか。テストが本当に意味のある回帰ガードになっているか(常に通ってしまう空テストでないか)。
- **セキュリティ**: HIBP 無効化がセキュリティ後退を招かないか。
- **禁止事項抵触**: 上記禁止事項に触れていないか。

出力形式:
- ファイルごとに判定を述べる。
- 指摘は Critical / Warning / Suggestion に分類する。
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示する。

---

# user

## 詳細設計書

（要旨）HIBP(uncompromised)照合を非本番環境で無効化する。従来 `App::runningUnitTests()` による testing のみ除外だったのを、denylist(`local`/`testing`/`bughunt.local`)方式へ反転し、単一述語 `PasswordPolicy::shouldCheckPwned()` に集約する。

施策:
1. `app/Support/PasswordPolicy.php`: `PWNED_CHECK_DISABLED_APP_ENVS` (typed const array) と `shouldCheckPwned()` を追加し、`rule()` を述語経由へ配線。production は述語先頭で無条件 `true`(fail-secure guard)、それ以外は denylist 非該当なら `true`(未知 env は既定 ON)。fake_externals は判定に用いない(fake が有効化され得る env はすべて denylist に含まれ推移的に無効になるため責務を APP_ENV に閉じる)。
2. `tests/Unit/Support/PasswordPolicyTest.php`: env 復元ヘルパー `withPasswordPolicyAppEnv()` を追加し、production=true / 未知 env=true / 既知 env=false / fake_externals 非結合 の env matrix と、`rule()` 配線の reflection 1 本を追加。既存 4 ケースは非削除。
3. `tests/Feature/Auth/RegistrationTest.php`: 登録 POST が `api.pwnedpasswords.com` を呼ばないことを `Http::fake` + `assertNotSent` で固定(F-4-01 非退行)。

設計上の要点:
- production 不変条件は述語 1(先頭 return true)で構造的に排除。deploy 時 fail-fast は既存 `ProductionEnvGuardTest` が担保。
- PHP 8.4 typed const `const array`(既存前例あり)。PHPStan level 10。
- Pest + RefreshDatabase グローバル + `--parallel`。個別 `DatabaseTransactions` 禁止。

## 実装差分（git diff）

```diff
diff --git a/app/Support/PasswordPolicy.php b/app/Support/PasswordPolicy.php
@@ final class PasswordPolicy
     public const int MIN_LENGTH = 12;
+    /** @var list<string> */
+    private const array PWNED_CHECK_DISABLED_APP_ENVS = ['local', 'testing', 'bughunt.local'];
+
+    public static function shouldCheckPwned(): bool
+    {
+        if (App::environment('production')) {
+            return true;
+        }
+        return ! App::environment(self::PWNED_CHECK_DISABLED_APP_ENVS);
+    }
+
     public static function rule(): Password
     {
         $rule = Password::min(self::MIN_LENGTH)->mixedCase()->numbers();
-        return App::runningUnitTests() ? $rule : $rule->uncompromised();
+        return self::shouldCheckPwned() ? $rule->uncompromised() : $rule;
     }
```

```diff
diff --git a/tests/Feature/Auth/RegistrationTest.php b/tests/Feature/Auth/RegistrationTest.php
+use Illuminate\Http\Client\Request;
+use Illuminate\Support\Facades\Http;
+
+test('登録 POST は非本番で api.pwnedpasswords.com を呼ばない (F-4-01 非退行)', function (): void {
+    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
+    $response = $this->post('/register', [
+        'name' => 'Test User', 'email' => 'newuser@example.com',
+        'password' => 'SecurePass1234', 'terms_accepted' => '1',
+    ]);
+    $response->assertSessionHasNoErrors();
+    $response->assertRedirect(route('verification.notice'));
+    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.pwnedpasswords.com'));
+})->group('auth');
```

```diff
diff --git a/tests/Unit/Support/PasswordPolicyTest.php b/tests/Unit/Support/PasswordPolicyTest.php
+function withPasswordPolicyAppEnv(string $env, Closure $assertion): void
+{
+    $original = app()->environment();
+    app()->instance('env', $env);
+    try { $assertion(); } finally { app()->instance('env', $original); }
+}
+
+test('shouldCheckPwned() は production で true (fail-secure 不変条件)', ...);
+test('shouldCheckPwned() は未知 env で既定 true', ...)->with(['staging', 'preprod', 'review']);
+test('shouldCheckPwned() は既知の開発/テスト env で false', ...)->with(['local', 'testing', 'bughunt.local']);
+test('shouldCheckPwned() は fake_externals=true の denylist env で false', ...); // local
+test('shouldCheckPwned() は PasswordPolicy が fake_externals に結合しないことを固定', ...); // staging=true
+test('rule() は述語結果を uncompromised 付与へ配線している (reflection 最小 1 本)', ...);
```

（既存 4 ケース: describe 決定論 / rules 強度 / testing で uncompromised 非含 / production で uncompromised 含 / Password::defaults 配線 は非削除で維持）

## テスト結果

- targeted: `vendor/bin/pest tests/Unit/Support/PasswordPolicyTest.php tests/Feature/Auth/RegistrationTest.php` → 20 passed, 46 assertions。
- full: `composer test` → 1786 passed, 2 skipped, 0 failed。
- `composer phpstan` (level 10) → No errors。
- `vendor/bin/pint --test` → passed。
- `pnpm typecheck` / `lint` / `test`(768 passed) / `build` → all green。

この実装をレビューし、全体判定を APPROVED / CHANGES_REQUESTED で示せ。

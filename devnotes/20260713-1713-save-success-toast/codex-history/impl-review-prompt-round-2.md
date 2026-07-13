# 実装レビュー Round 2: T026 (Round 1 指摘への対応)

Round 1 の指摘に対する対応を報告します。修正は `PasswordResetResponse.php` のみです。

## [Critical] JSON message の型安全化

Codex 提案の `trans()` 置換ではなく、より堅い narrowing を採用しました。理由:
- `trans()` の宣言型も `array|string` であり `__()` と等価。置換しても PHPStan Lv10 の型不整合は解消しない。
- 真の解決は「array でないこと」を明示すること。AGENTS.md は `Webmozart\Assert\Assert` の活用を推奨。
- キャスト `(string)` は array が来た場合 "Array" 文字列 + warning を生む silencing。Assert なら不変条件 (status は単一言語キー=string) を実行時にも保証する。

対応: `$message = __($this->status); Assert::string($message);` に変更し `use Webmozart\Assert\Assert;` を追加。
検証: `composer phpstan` OK / Feature 11 passed / pint OK。

## [Warning] docblock の「既定準拠」表現

docblock を「web の redirect flash は汎用 success 文言へ寄せる／JSON message のみ既定の localize status を維持 (差分は web redirect の flash キー・文言のみ)」と明示化しました。

## [Suggestion] toResponse の引数を `Request $request` で明示 → 見送り

理由: Fortify の `PasswordResetResponse` interface は `toResponse($request)` を型なしで宣言。実装側でパラメータ型を追加すると型の narrowing になり PHP の LSP 制約 (パラメータは反変) に反して fatal error になります。既存の Response family (`ProfileUpdatedResponse` 等) も一貫して型なし `$request` + `@param Request $request` docblock で統一しており、静的解析上の型は docblock で既に付与済み。family の一貫性と互換性維持のため現状維持とします。

## 修正後の差分 (PasswordResetResponse.php)

```diff
diff --git a/app/Http/Responses/Fortify/PasswordResetResponse.php b/app/Http/Responses/Fortify/PasswordResetResponse.php
--- /dev/null
+++ b/app/Http/Responses/Fortify/PasswordResetResponse.php
@@ -0,0 +1,52 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Responses\Fortify;
+
+use Illuminate\Http\JsonResponse;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
+use Laravel\Fortify\Fortify;
+use Webmozart\Assert\Assert;
+
+final class PasswordResetResponse implements PasswordResetResponseContract
+{
+    private const string SUCCESS_MESSAGE = 'パスワードを変更しました。ログインしてください。';
+
+    public function __construct(private readonly string $status) {}
+
+    /**
+     * @param  Request  $request
+     */
+    public function toResponse($request): JsonResponse|RedirectResponse
+    {
+        if ($request->expectsJson()) {
+            // __() の宣言型は array|string。status は必ず単一言語キー (passwords.reset) のため
+            // string に確定する。キャストで黙らせず Assert で不変条件を実行時にも保証しつつ narrow。
+            $message = __($this->status);
+            Assert::string($message);
+
+            return new JsonResponse(['message' => $message], 200);
+        }
+
+        return redirect(Fortify::redirects('password-reset', config('fortify.views', true) ? route('login') : null))
+            ->with('success', self::SUCCESS_MESSAGE);
+    }
+}
```

この対応で Critical が解消されたか、全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。

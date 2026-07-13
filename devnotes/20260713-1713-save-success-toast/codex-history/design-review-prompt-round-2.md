# 詳細設計レビュー Round 2（Round 1 指摘への対応）

Round 1 の CHANGES_REQUESTED（施策3 の Warning 2 件）に対応しました。他 Suggestion も反映しています。

## 施策3（パスワードリセット）修正

### JSON 分岐: `trans()` 戻り型 narrowing（Warning 1）
```php
public function toResponse($request): JsonResponse|RedirectResponse
{
    if ($request->expectsJson()) {
        // __() は key に配列を渡すと array を返しうるため PHPStan Lv10 で array|string 推論。
        // status は必ず単一言語キーのため (string) で明示 narrow する。
        return new JsonResponse(['message' => (string) __($this->status)], 200);
    }

    // Fortify 既定式に完全準拠 (views 無効=API 専用構成でも login 未定義で落ちない)
    return redirect(Fortify::redirects('password-reset', config('fortify.views', true) ? route('login') : null))
        ->with('success', self::SUCCESS_MESSAGE);
}
```
- Warning 1（型）: `(string) __($this->status)` で string に明示キャスト。
- Warning 2（redirect 既定準拠）: `config('fortify.views', true) ? route('login') : null` を採用し Fortify 既定式と同等化。

## 施策5（Feature テスト）追記
- reset JSON: `assertJsonPath('message', __('passwords.reset'))` まで固定。
- 非回帰（失敗系）: 無効/期限切れ token の POST `/reset-password` → `assertSessionHasErrors` +
  `assertSessionMissing('success')`（success flash を出さない）を追加。合計 7 ケース。

## 施策1 note
- `expectsJson()` 採用の意図を「AJAX の JSON 判定を厳密化する family 統一」と1行補足。

## 施策6（vitest）追記
- happy path で `addToastMock` が `('success', ...)` で呼ばれた回数 0 を明示 assert。
- GET 失敗で `'error'` 1 回 + 文言に「再生成されました」「表示取得に失敗」を含むことを assert。

---

これらの修正で施策3 の Warning 2 件は解消したと考えます。全体判定（APPROVED / CHANGES_REQUESTED）を提示し、
残指摘があれば施策ごとに [Critical]/[Warning]/[Suggestion] で示してください。

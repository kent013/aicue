**施策別レビュー**

- **施策1（プロフィール更新の success flash 化）: APPROVE**
  - [Suggestion] `expectsJson()` 採用は既存 custom response と整合しており妥当。Fortify 既定の `wantsJson()` との差分は「AJAX 判定をより厳密にする」意図として設計書に1行補足があると将来保守が楽です。

- **施策2（パスワード変更の success flash 化）: APPROVE**
  - [Suggestion] `PasswordUpdateResponse` も施策1と同様に `expectsJson()` を採る設計は一貫していて良いです。`Settings/Index.svelte` の `onSuccess: reset()` は toast 表示と独立なので副作用は低いです。

- **施策3（パスワードリセットの success flash 化）: REQUEST_CHANGES**
  - [Warning] `trans($this->status)` の戻り型が環境により `array|string` 推論となる可能性があり、PHPStan Lv10 で不安定です。  
    **修正案:** `new JsonResponse(['message' => (string) __($this->status)], 200)` のように明示キャスト、または `is_string($message)` で narrow してから返却。
  - [Warning] `redirect(Fortify::redirects('password-reset', route('login')))` は既定挙動に寄せていますが、`config('fortify.views', true)` を考慮しないため「API専用構成で login ルート非定義」時の将来リスクがあります。  
    **修正案:** Fortify 既定式と同等に  
    `Fortify::redirects('password-reset', config('fortify.views', true) ? route('login') : null)` を採用。
  - [Suggestion] `bind`（非 singleton）判断は正しいです（`$status` 注入あり）。

- **施策4（再生成 toast 正本一本化）: APPROVE**
  - [Suggestion] 「成功はサーバ flash、失敗は client toast」の責務分離が明確で、二重発火解消として適切。`GET失敗`文言の明確化も UX 的に良い修正です。

- **施策5（Feature テスト追加）: APPROVE**
  - [Suggestion] 良い網羅です。加えて reset JSON ケースは `assertJsonPath('message', __('passwords.reset'))` まで固定すると契約がより明確になります。
  - [Suggestion] セキュリティ観点として、無効/期限切れ token の既存失敗系テストが同ファイルに無ければ、非回帰用に1ケース追加すると安心です（enumeration対策文脈）。

- **施策6（vitest 更新）: APPROVE**
  - [Suggestion] `not.toHaveBeenCalledWith('success', ...)` だけでなく、必要なら呼び出し総数や `'error'` の引数精査も合わせると退行検知が強くなります。

**観点別総評**

- 正確性: 概ね良好。主に施策3の型・リダイレクト既定準拠のみ要調整。
- 既存整合性: Fortify Response family に揃っており高評価。
- PHPStan Lv10: `trans()` 戻り型のみ明示 narrow 推奨（実質必須）。
- テスト計画: Feature/vitest とも十分に実践的。
- DTO/JsonResource: Fortify contract 実装として妥当（禁止事項4との整合説明も妥当）。
- Inertia vs API: web は flash、JSON は 200/メッセージ維持で適切。
- セキュリティ: reset token 検証前提が明記されており妥当。
- DESIGN/Atomic: UI変更はトースト文言・発火源のみで逸脱なし。

**全体判定**
- **CHANGES_REQUESTED**

施策3の2点（`trans()` 型 narrowing、Fortify既定リダイレクト式への完全準拠）を反映できれば、全体 **APPROVED** 相当です。
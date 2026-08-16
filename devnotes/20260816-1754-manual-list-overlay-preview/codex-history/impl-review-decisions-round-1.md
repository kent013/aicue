# 対応マトリクス: impl-review Round 1

Codex (gpt-5.5 / reasoning=high / label=impl-review) の全体判定は **APPROVED**。
Critical / Warning は 0 件で、Suggestion が 1 件のみだった。

## [Suggestion] query log の解放を try/finally にする

対象: `tests/Unit/Manual/CurrentRenderArtifactLoadedCandidateTest.php`

- **判断**: 対応する
- **根拠**: 指摘のとおり、観測区間で例外が出た場合に `DB::disableQueryLog()` が走らず、
  query log が有効なまま後続テストへ漏れる。コストがほぼゼロで、失敗時の
  デバッグ体験 (無関係なテストが道連れで壊れる) を確実に改善する。
  スコープ拡大にも当たらない (同じテストの後始末の話)。
- **対応内容**: 観測区間を `try { … } finally { DB::disableQueryLog(); }` で包んだ。
  `$log` の取得も try 側に置き、`expect()` は finally の後で行う
  (計測の意味は変えていない = 「呼び出しだけを測る」観測区間はそのまま)。

## 逸脱・反論

なし。詳細設計書の施策 1〜6 からの逸脱は下記 1 点のみで、これは設計書のコード例が
Pest の API を取り違えていたことによる訂正である (Codex からの指摘ではなく実装中に検出):

- 設計書の Architecture テスト案は `expect($texts)->not->toContain('output_path')` /
  `expect($texts)->toContain('CurrentRenderArtifact')` と書いていたが、
  **`toContain()` は追加引数を「もう 1 つの needle」として扱う**ため、失敗メッセージを
  第 2 引数に足すと needle が 2 つになり誤判定する (実際に赤くなった)。
  `expect(in_array(…, $texts, true))->toBeTrue(…) / ->toBeFalse(…)` へ書き換え、
  検査内容は設計どおりに保ったまま失敗メッセージを残した。

## 再検証

対応後に以下を再実行して全 green を確認する:

- `composer test` / `composer phpstan` / `vendor/bin/pint --test`
- `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

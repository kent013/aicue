# 対応マトリクス: impl-review Round 1

Codex (`gpt-5.3-codex` / high) の全体判定は **APPROVED**。
Critical 0 件 / Warning 0 件 / Suggestion 1 件。全 36 ファイルが APPROVE。

## [Suggestion] `form-novalidate.test.ts` の `listSvelteFiles` を既存 architecture テストの共通ヘルパへ寄せる

- 判断: **見送る** (根拠を記録)
- 根拠:
  1. **現状「共通ヘルパ」は存在しない**。`tests/js/architecture/ds-purity.test.ts` も
     `listFiles` / `relPath` をファイル内ローカルに持っており、本テストはその既存慣習に揃えた形。
     寄せるには新しい共有モジュールを新設することになり、**設計に無いものを足す**
     (思考原則 2 / オーバーエンジニアリング禁止)。
  2. 重複しているのは 2 箇所・十数行で、しかも走査対象が異なる
     (ds-purity は `.svelte` + `.ts`、本テストは `.svelte` のみ)。共通化すると引数で
     切り替える汎用ヘルパになり、**読み手のコストは下がらない**。
  3. Codex 自身が「非ブロッカー」と明記している。
- 対応内容: 変更なし。3 箇所目の走査テストが現れた時点で抽出を検討する
  (そのときは本タスクの範囲ではなく、テスト基盤側の整理として扱う)。

## 合議結果

Round 1 で **APPROVED** のため合議終了 (追加ラウンドなし)。

Codex の観点別確認:

| 観点 | 結果 |
|---|---|
| 設計との一致性 | 施策 1〜5 を過不足なく実装と確認 |
| 正確性 (Svelte 5 runes) | `$derived` で同期漏れを回避。無限ループ要因なし |
| PHPStan / DTO / JsonResource | 該当なし (PHP 無変更) |
| テスト網羅性 | 各施策に対応テストあり。検出器の自己テストも確認 |
| セキュリティ | readonly を認可境界として扱っていない前提を維持。novalidate でサーバ検証経路を阻害しない |
| DESIGN.md 準拠 | token 逸脱なし |
| Atomic Design 準拠 | 階層逆流なし |

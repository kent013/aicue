# 対応マトリクス: impl-review Round 2

Codex 全体判定: **APPROVED**

## 未対応の指摘

なし。Round 1 の [Warning] 2 件はいずれも解消と判定された。
[Suggestion] 1 件 (media query 文字列の PHP 側複製) は
「TypeScript 側が式の正本 / PHP 側は Browser ハーネスの前提観測用」という責務分離で
受容可能と判定された (複製そのものは技術境界上残る)。

## Round 1 で行った反論の扱い

「Browser の矩形テストは fixture を長くすれば line-clamp の退行を捕まえる」という
Round 1 の前提に対して、実測 (line-clamp を flex と同じ要素へ戻して Chromium レーンを
走らせても緑) を根拠に反論した。Round 2 で「実測結果に基づいて component テストと
Browser テストの責務を正しく分離できている」と受理された。

## 残す記録 (将来の実装者向け)

- **Playwright WebKit (Linux) には `MediaRecorder` が無い** (`typeof window.MediaRecorder`
  が `"undefined"`)。撮影パネル (`CameraRecorder`) を必要とする Browser テストは
  WebKit レーンでは成立しないため、前提を明示して skip する。
  これはレーンの能力差であって iOS Safari 実機の性質ではない。
- **`inert` は Svelte 5 が DOM プロパティとして設定する**ため、属性セレクタ
  (`closest("[inert]")`) では引けない。テストはプロパティを辿ること。

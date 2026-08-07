# 対応マトリクス: conceptual-review Round 5

Codex 全体判定: CHANGES_REQUESTED (条件付き承認 —
「Error ページの CTA を通常のアンカーによるフル document navigation に固定すれば APPROVED」)。
[Critical] 0 件・[Warning] 2 件 (実質同一論点)・[Suggestion] 5 件。

## [Warning] 観点 3 / 観点 4: 419 の CTA が Inertia navigation だと CSRF token を取り直せない

- 判断: **対応する** (Codex 提示の選択肢のうち**最も単純な案** = 全 status で通常の `<a>` に統一)
- 根拠: 指摘は正当。`Link` / `router.visit()` は同じ document を保つため、
  419 の原因が「document が保持する古い CSRF token と現在の session の不一致」なら
  遷移後の POST で同じ 419 を踏み直す。D1 (419 → ログイン) が意図した
  「セッションと token を取り直す」は document を作り直して初めて成立する。
  = 戻り先規則の正しさが遷移方式に依存していた。
- 案の選択: status 別に遷移方式を出し分ける案 (`navigation: 'hard'` prop) は採らない。
  props が増え、最も壊れにくくあるべき Error 画面に条件分岐が入る。
  エラー時に SPA の操作感を優先する理由も無いため、**全 status で通常の `<a>`** が最小の設計。
- 対応内容:
  - 「戻り先はサーバ側に固定した許可一覧から出す」節に
    「遷移方式は全 status で通常の `<a href>` によるフル document navigation に固定する」を追記
  - 実装は `Button` atom の anchor モードを **`inertia` prop を渡さずに**使う
    (`Button.types.ts` の既定がネイティブ `<a>`)
  - JS テストで `Error.svelte` が `@inertiajs/svelte` の `Link` を import せず、
    `inertia` prop も `router.visit` も使っていないことを固定する
  - Browser テストによる「古い token の 419 → CTA → 新 document → POST 成功」の確認は
    **今回は入れない**。Browser レーンは Chromium + WebKit の 2 レーン契約 (docs/testing-browser.md) で
    実行コストが大きく、上記の JS テスト (遷移方式の静的固定) と Feature テスト
    (419 でログイン導線が返ること) で不変条件は機械的に押さえられるため。
    オーバーエンジニアリング禁止 (思考原則 2) に従う

## [Suggestion] 観点 1 / 2 / 5 / 6 / 7 の肯定的評価

- 判断: **見送る** (対応不要)
- 観点 7 の「遷移方式を props に含めるならリテラル型に」は、全 CTA を通常の `<a>` に
  統一したため props 追加自体が不要になった (Codex も「こちらの方が小さい設計」と評価)。

## ラウンド上限に関する記録

app-design SKILL.md の規定により概念設計レビューは**最大 5 ラウンド**。本ラウンドで上限に達した。
Round 5 の全体判定は CHANGES_REQUESTED だが、指摘は 1 論点 (CTA の遷移方式) のみで、
Codex 自身が「これを固定すれば APPROVED」と条件を明示している。その条件は本マトリクスの
とおり全面的に反映済みであり、未解消の Critical / Warning は無い。
残る検証は Phase 2 の詳細設計レビュー (design-review) で行う。

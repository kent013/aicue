# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED** (Critical 0 / Warning 1 / Suggestion 0)。
全 13 ファイルが個別に APPROVED。

## [Warning] docs/architecture.md の「分岐の網羅は Svelte 側の `satisfies`」が実装とずれている

- 判断: **対応する**
- 根拠: 指摘が正しい。今回の実装の肝はまさに「`.svelte` の `satisfies` は
  `pnpm typecheck` (= `tsc --noEmit`) で評価されないので `.ts` へ移した」ことであり、
  architecture.md に「Svelte 側」と書いたままだと、次に読む人が同じ空振りを再生産する。
  ドキュメントが誤った不変条件を宣言している状態は、テストの無い保証と同じ害がある。
- 対応内容: `docs/architecture.md` §サブスク契約 Checkout とオンボーディング着地 の
  T150 項を 2 点修正した。
  1. 「分岐の網羅」の担い手を **`resources/js/types/dashboard.ts` の `BILLING_CALLOUTS`** と明記
  2. 「**copy map を `.svelte` に置かない**」という一般則を箇条書きで追記
     (理由 = `tsc` が `.svelte` を検査しない / svelte-check 未導入 / T150 の mutation で実測。
     置き場所の先例として `types/manual.ts` を指す)

  この追記は既存行の renumber をせず、T150 項の内側への追加に留めている
  (並走 TODO との行競合を避けるため)。

## 蒸し返しの有無

設計レビューで決着済みの論点 (2 件を共通化しない / 新 enum を作らない /
CTA を権限で分岐させない / 429 は最小形・Retry-After 秒数を出さない /
閾値と課金ゲート判定は変えない / Card のまま Alert にしない) への再指摘は **なかった**。

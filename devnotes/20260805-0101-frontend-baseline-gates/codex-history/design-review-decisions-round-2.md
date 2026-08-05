# 対応マトリクス: design-review Round 2

## [Warning] 「リポジトリ全体で inline config 禁止」の宣言と検査範囲がずれている (施策 4)
- 判断: **対応する**（Codex 推奨の「走査を lint 対象に合わせる」を採る）
- 根拠: 妥当。ただし前提の整理が要る —
  **`pnpm lint` は `eslint resources/js` であり、`tests/js` や `eslint.config.js` は
  そもそも lint されていない**。lint されないファイルの inline directive は
  ESLint に読まれないので、そこを検査しても守るべきものが無い。
  したがって「リポジトリ全体」という宣言のほうが不正確だった。
- 対応内容: 2 方向で揃える。
  1. **走査を lint 対象と完全に一致させる**: `resources/js` 配下の
     `.svelte` / `.js` / `.mjs` / `.cjs` / `.ts` / `.jsx` / `.tsx`
     (= `eslint.config.js` が `files` で対象にしている拡張子集合) を全件列挙する。
     将来 `.tsx` が増えても自動的に対象に入る (deny-by-default)。
  2. **`eslint.config.js` のコメント文言を修正**する。「リポジトリ全体」ではなく
     「**lint 対象 (`pnpm lint` = `eslint resources/js`) の全ファイル**で
     inline の eslint-disable を許可しない」と書く。
     併せて「lint 対象を広げる (`pnpm lint` の引数を増やす) ときは
     `svelte-no-undef-gate` の走査範囲も同時に広げること」を明記する
     (宣言と検査の乖離が二度と生まれないようにする)。

## [Warning] `docs/template-divergence.md` D11 が修正後実装と同期していない (施策 4)
- 判断: 対応する
- 根拠: 妥当。D11 の「保証し続ける不変条件」に `.ts` 側の `noInlineConfig` 検査が無い。
  template-divergence の記録原則が「どの機構でカバーするか」を書けと定めている以上、
  実装と食い違った記録は記録として無効。
- 対応内容: D11 の不変条件を A/B/C の 3 本立てで書き直し、
  C (`noInlineConfig`) が **lint 対象の全拡張子**に適用されること、
  および lint 対象を広げたら gate の走査も広げるという運用契約を追記する。

## [Suggestion] `PENDING_CONTRAST_PAIRS.length > 0` の運用 (施策 6)
- 判断: 対応する（コメントで運用を明記する形）
- 根拠: 「pending 全解消後はこのテスト自体を消す」という出口が書かれていないと、
  永遠に残る形骸テストになる。
  一方「現在の pending 項目を完全一致で固定」は文言の微修正で落ちるため採らない
  (gate が内容ではなく文字列を守り始める)。
- 対応内容: テストと `inventory.ts` の両方に
  「1.4.11 / alpha 合成に対応したら **宣言と本 it を同時に削除**する」と明記する。

## 施策 1 / 2 / 3 / 5 / 6 / 7 の APPROVE
- 判断: 対応不要

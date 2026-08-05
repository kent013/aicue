# T111 実装レビュー 対応マトリクス (Round 1)

Codex 全体判定: **APPROVED** (Round 1) / Critical 0 / Warning 0 / Suggestion 1

| # | 分類 | 指摘 | 判断 | 根拠 |
|---|---|---|---|---|
| 1 | [Suggestion] | `packages/cli/package.json` の `undici` 宣言引き上げ (`^6.27.0` → `^6.28.0`) は設計文面からの逸脱なので、「設計では宣言変更不要だったが direct dependency の安全下限固定として残した」旨をレビュー履歴に明記せよ | **対応する** | コミットメッセージ本文と本ファイルに逸脱理由を明記した。lockfile が無い状態での fresh resolve が 6.27.0 (脆弱版) に落ちる余地を塞ぐため、下限引き上げを残す方が supply-chain 上安全 |

## 設計からの意図的な逸脱

1. **`undici` の caret 下限を `^6.28.0` へ引き上げた**
   詳細設計 §施策 D1 は「caret 範囲内なので宣言変更は**不要**」としていたが、
   `pnpm -F @app/cli update undici` の既定挙動が下限を引き上げた。これを差し戻さず残した。
   理由: lockfile 非適用の fresh resolve で 6.27.0 (advisory 該当版) に解決される余地を塞げるため。
   設計は「不要」と書いているだけで「禁止」ではなく、advisory 解消の目的に対して厳密に強い側への逸脱。

2. **lockfile の再解決範囲を最小化した**
   `pnpm update` は `@types/node` 25.9.2→25.9.5 / `@csstools/*` / `tldts` / `tough-cookie` /
   `ws` / `lru-cache` まで巻き込み +110/-94 行の diff を作った。これを破棄し、
   manifest 2 行の編集 + `pnpm install` のみで再生成した +60/-51 行の版を採用した。
   結果、版が動いたのは `undici` 6.27.0→6.28.0 / `valibot` 1.4.1→1.4.2 /
   `eslint-plugin-better-tailwindcss` 4.4.1→4.7.0 /
   `enhanced-resolve` 5.23.0→5.24.5 (plugin の依存範囲変化) の 4 件と
   `supports-color` の peer edge 再計算のみ。設計の「lockfile 更新のみ」という意図に忠実。

## 設計の分割条件の判定

詳細設計 §施策 D1 リスク欄「`eslint-plugin-better-tailwindcss` の pin 上げで是正が
**コード修正 5 ファイルを超える**なら D1 を `undici` のみに縮小し plugin を別 TODO へ分離」
→ 4.4.1 → 4.7.0 で `pnpm lint` の指摘は **0 件のまま増えなかった** ため、
分割条件に該当せず、両方を本 TODO に含めた。

## accept-risk

`docs/supply-chain/accepted-advisories.yaml` は `[]` のまま**無変更**。accept-risk 追加は **0 件**。
advisory 4 件すべてを upgrade で解消した。

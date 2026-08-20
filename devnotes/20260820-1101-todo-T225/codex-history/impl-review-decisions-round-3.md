# Round 3 対応マトリクス

| # | 指摘 | 分類 | 対応 |
|---|------|------|------|
| 1 | `php-enum-catalog.ts`: 深さ 0 の enum と深さ 0 以外の enum が同じファイルに共存すると、深さ 0 以外の enum が黙って捨てられる (fail-open) | Critical | **対応**。判定条件を `depthZero.length === 0` から `depthZero.length !== headers.length` へ変更し、**深さ 0 以外の候補が 1 件でも混ざっていれば** `unresolvable` にするよう修正した。負例 D17 (深さ 0 の string enum + 深さ 0 以外の string enum の共存) / D18 (深さ 0 の int enum + 深さ 0 以外の string enum の共存) を追加し、故障注入 (修正前の条件へ戻す) で両方が失敗する (D17 は `resolved` を誤って返す・D18 は `undefined` を誤って返す) ことを確認して元に戻した |
| 2 | `enum-ts-sync-discovery-extractor.test.ts`: 共存時の見逃しを固定する負例が無い | Warning | **対応 (#1 と同時に実施)**。D17 / D18 として追加した |
| 3 | `enum-ts-sync-discovery-extractor.test.ts` / `docs/architecture.md`: TS 母集団の説明が「tsconfig の include/exclude が単一の出典」となっているが、実際には filesystem 直接走査との一致テストが不変条件の実体である | Suggestion | **対応**。`docs/architecture.md` の記述を「tsconfig が実際に決めるが、それだけを出典とは言わない。ファイルシステムを直接歩いた集合との完全一致を独立実装の回帰テストで固定しており、この一致こそが不変条件の実体である」という表現へ改めた |
| 4 | `docs/architecture.md`: 「深さ 0 でない位置の enum は unresolvable にする」という記述が、共存時の Critical を修正するまで実態と一致していなかった | Warning | **対応 (#1 の修正を反映)**。「深さ 0 でない候補が 1 件でも混ざっていれば」という正確な条件へ揃えた |
| 5 | `docs/template-divergence.md` / `TemplateDivergenceLedgerFormatTest.php`: D29 の再判定条件が #1 の Critical のぶん未達 | Warning | **見送り (#1 を修正したため据え置き)**。共存ケースを含めて fail-closed になったと判断し、D29 削除・件数 30 件のままとする |

## 再検証

- `pnpm typecheck`: エラー無し
- `pnpm exec vitest run tests/js/architecture/enum-ts-sync*.test.ts`: 4 files / 170 tests passed (Round 3 の 168 件から 2 件増加)
- `buildPhpEnumCatalog()` の実測: `resolved=112` / `unresolvable=3` (今回の修正でも実データの結果は不変)
- 故障注入: 判定条件を Round 3 指摘前の形へ戻すと D17 (誤って `resolved`) / D18 (誤って `undefined`) の両方が失敗することを確認し元に戻した

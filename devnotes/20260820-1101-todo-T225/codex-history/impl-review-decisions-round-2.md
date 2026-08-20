# Round 2 対応マトリクス

| # | 指摘 | 分類 | 対応 |
|---|------|------|------|
| 1 | `php-enum-catalog.ts`: 波括弧付き namespace の検出が named 形しか扱わず、無名 namespace・大文字 `NAMESPACE`・コメント割り込みで fail-open になる。個別の namespace 正規表現より、共有走査器が深さ付きの enum 候補を返す設計にすべき | Critical | **対応 (根本設計を変更)**。`BRACKETED_NAMESPACE_DECLARATION` の正規表現を撤去し、`php-enums.ts` の `EnumHeader` に `depth` を追加。`scanEnumHeaders` は深さでフィルタせず**すべての深さ**の enum 候補を返すようにし、`readPhpEnumValuesFromText` は自分で `depth === 0` に絞り込む (既存の動作は不変)。`classifyPhpFile` は「深さ 0 の候補が 1 件も無い」ことを条件に `unresolvable` へ回すようにした。これにより namespace の書き方 (無名・大文字・コメント割り込み) や、それ以外の理由で深さがずれるケース (条件付き enum 宣言等) も同じ 1 つの判定で拾える。負例 D15 (無名 namespace) / D16 (大文字 NAMESPACE + コメント割り込み) を追加し、故障注入 (depth-0 判定の撤去) で D13/D15/D16 が赤くなることを確認した |
| 2 | `enum-ts-sync-discovery-extractor.test.ts`: D13 が named namespace の成功例だけで穴を固定できない (無名 / 大文字 / コメント割り込みの負例が必要) | Warning | **対応**。#1 の設計変更で個別の負例を積み増す必要が無くなったが、Codex の指摘どおり主要な表層のバリエーション (D15 無名 / D16 大文字+コメント) を明示的に固定した |
| 3 | 「resources/js の対象ファイルを全件含む」ことは未証明 (tsFiles 非依存の確認だけでは exclude が広がる回帰を検出できない) | Warning | **対応**。`collectTsUnionCandidates` と独立した実装 (プログラムを介さないファイルシステムの再帰走査) で resources/js 配下の期待する `.ts` (`.d.ts` を除く) 集合を作り、`program.getSourceFiles()` 側の集合と完全一致することを固定するテストを追加した |
| 4 | `ts-candidates.ts`: `.d.ts` の除外が docblock / `docs/architecture.md` に明記されていない | Warning | **対応**。`ts-candidates.ts` の docblock (母集団の単一出典・保証しないもの) と `docs/architecture.md` の両方に `.d.ts` を対象外とすることを明記した |
| 5 | `enum-ts-sync-discovery.test.ts`: docblock の「生のソースに enum 宣言らしい並びが無ければ」が実装 (`\benum\b` のみを見る) と食い違っている | Suggestion | **対応**。「`enum` の語が無ければ」という正確な表現へ揃えた |
| 6 | `docs/architecture.md`: 「全数走査」「未分類なく分類」「D29 の再判定条件を満たした」が実装に対して過大 | Warning | **対応 (#1 の修正を反映して更新)**。namespace 検出の一般化と `.d.ts` の明記を反映し、記述が実装と一致するよう更新した |
| 7 | `docs/template-divergence.md` / `TemplateDivergenceLedgerFormatTest.php`: D29 削除・件数 30 件が時期尚早 | Warning | **見送り (Critical を先に直したため据え置き)**。#1 の修正により、有効な string enum が三分類のどこにも入らず消える経路を塞いだと判断し、D29 削除・件数 30 件のままとする |

## 再検証

- `pnpm typecheck`: エラー無し
- `pnpm exec vitest run tests/js/architecture/enum-ts-sync*.test.ts`: 4 files / 168 tests passed (Round 2 の 165 件から 3 件増加)
- `buildPhpEnumCatalog()` の実測: `resolved=112` / `unresolvable=3` (Round 1/2 と同じ値。depth ベースの設計変更でも既存の実データに対する結果は変わらないことを確認した)
- 故障注入: 「深さ 0 判定を撤去する」でD13/D15/D16 が (クラッシュを含め) 失敗することを確認し元に戻した

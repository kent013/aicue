# Round 1 対応マトリクス

| # | 指摘 | 分類 | 対応 |
|---|------|------|------|
| 1 | `enum-ts-sync-discovery.test.ts`: TS 母集団が `ENUM_TS_MIRRORS` の import グラフに閉じている | Critical | **反論 (実測で否定)** かつ **対応 (回帰テスト追加)**。`createMirrorProgram` の rootNames は `tsconfig.json` の `parsed.fileNames` (include: `resources/js/**/*.ts`) を必ず含むため、渡す `tsFiles` 引数に関係なく resources/js 配下の全 .ts が program に載ることを実測で確認した (`createMirrorProgram([])` でも 58/59 ファイルが載る。残り 1 件は `vite-env.d.ts` で `isDeclarationFile` により正しく除外)。したがって現状のコードは指摘の欠陥を持たない。ただし**この事実を固定する回帰テストが無かった**のは正当な指摘なので、`enum-ts-sync-discovery-extractor.test.ts` に「母集団は明示した tsFiles に依存しない」テストを追加し、`createMirrorProgram([])` でも登録済みミラーと無関係な実ファイル (`resources/js/lib/stores/toast.ts`) の宣言が見つかることを固定した |
| 2 | `ts-candidates.ts`: 構文診断があるファイルを無言で `continue` している (fail-closed違反) | Critical | **対応**。`program.getSyntacticDiagnostics(source).length > 0` を検出したら `EnumTsSyncError` を投げるよう変更。負例テスト (`fixtures/candidates-broken/broken.ts`) を追加し、故障注入 (throw を continue に戻す) で赤くなることを確認した |
| 3 | `php-enum-catalog.ts`: 波括弧付き namespace 宣言の中の enum は深さ 0 前提が崩れて検出できない | Critical | **対応**。`BRACKETED_NAMESPACE_DECLARATION` を追加し、この形を検出かつ `enum` の語があれば `unresolvable` へ回す (安全側)。現リポジトリは波括弧無し namespace のみで該当ファイルは 0 件。負例 D13 (検出される) / D14 (enum が無ければ母集団から外れる) を追加し、故障注入で赤くなることを確認した |
| 4 | `php-enum-catalog.ts`: `scan()` 失敗時の救済正規表現 `\benum\s+[A-Za-z_][A-Za-z0-9_]*` がコメントを挟む書き方・非 ASCII 識別子を見逃す | Critical | **対応**。`LOOSE_ENUM_DECLARATION` を `\benum\b` (直後の並びを問わない) へ広げた。これにより `app/Mcp/Servers/AppMcpServer.php` が新たに `unresolvable` 判定になったため `KNOWN_UNRESOLVABLE_PHP_ENUMS` へ理由付きで登録 (2 件→3 件)。負例 D11 (日本語の助詞が続く形) / D12 (コメントを挟む形) を追加した |
| 5 | `reverse-sweep.ts`: `normalizeName()` が英数字以外を除去し、`Foo_Bar` と `FooBar` を同一視する (名前対応が緩すぎる) | Warning | **対応**。除去をやめ `toLowerCase()` のみにした。負例 E10 (`Foo_Bar` vs `FooBar` は対応しない) / E11 (部分文字列は対応と認めない) / E12 (大文字小文字違いは対応と認める) を追加した |
| 6 | `reverse-sweep.ts`: `ResolvedPhpEnum.name` を収集しているのに名前対応でファイル名から再計算している (収集結果を使わない) | Warning | **対応**。`shortEnumName(phpEnum.path)` の呼び出しをやめ `phpEnum.name` を直接使うよう変更。`shortEnumName` はテストの見本構築用ユーティリティとして export のまま残す。負例 E13 でファイル名の語幹と enum 名が食い違っていても `name` を見ることを固定した |
| 7 | `enum-ts-sync-discovery-extractor.test.ts`: 上記の検出穴に対する負例が不足 | Warning | **対応**。#1-#6 の修正に伴い D11-D14 / E10-E13 / collectTsUnionCandidates の 2 件 (構文エラー・母集団の非依存) を追加した |
| 8 | `enum-ts-sync-discovery.test.ts`: `catalog.unresolvable[].reason` が収集されるだけで判定・メッセージに使われていない | Warning | **対応**。「unresolvable はすべて KNOWN_UNRESOLVABLE_PHP_ENUMS に登録されている」の失敗メッセージへ reason を含めた。あわせて `KNOWN_UNRESOLVABLE_PHP_ENUMS` 自体の体裁検査 (実在・重複無し・reason 30 文字以上) を新設した |
| 9 | `docs/architecture.md`: 「全数走査」「未分類なく分類」「D29 の再判定条件を満たした」は実装に対して過大 | Warning | **対応**。#1-#6 の修正で実装が主張どおりになったことを確認したうえで、母集団の単一出典 (tsconfig の include)・fail-closed の追加分岐・名前対応の正確な定義を反映するよう記述を更新した |
| 10 | `AGENTS.md`: 「TS 側も全数走査で逆走査する」が実装より強い保証 | Warning | **見送り (実装を先に直したため据え置き)**。#1-#6 の修正により記述と実装が一致したので、文言自体は変更不要と判断した |
| 11 | `docs/template-divergence.md` / `TemplateDivergenceLedgerFormatTest.php`: D29 の削除・件数 30 件への変更が時期尚早 | Warning | **見送り (実装を先に直したため据え置き)**。#1-#6 の Critical 修正により、D29 の再判定条件 (全数走査による既定拒否の分類 + 逆走査 2 規則) を実際に満たしたと判断し、削除・件数 30 件のままとする |

## 再検証

- `pnpm typecheck`: エラー無し
- `pnpm exec vitest run tests/js/architecture/enum-ts-sync*.test.ts`: 4 files / 165 tests passed
- 故障注入 (Round 1 修正箇所すべて): 構文診断の無視化・波括弧付き namespace 検査の撤去・
  救済正規表現を狭める・`normalizeName` を緩めに戻す、の 4 件すべてで赤くなることを確認し元に戻した
  (`REVERSE_SWEEP_EXEMPTIONS` / `PHP_ENUM_EXEMPTIONS` / `KNOWN_UNRESOLVABLE_PHP_ENUMS` からの
  1 行削除 3 件と合わせて、故障注入は合計 7 件すべて赤)

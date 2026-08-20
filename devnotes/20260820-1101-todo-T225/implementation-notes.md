# 実装メモ: PHP 列挙 ⇔ TS 値域の発見の段と逆走査 (T225 / AG-099 後半)

T218 (前半) で入った「登録した写しだけを見る」汎用同期 gate
(`tests/js/architecture/enum-ts-sync.test.ts`) を土台に、
発見の段 (全数走査による既定拒否の分類) と逆走査 (2 規則) を実装した記録。

## 1. 実装した施策

| # | 施策 | 変更ファイル |
|---|------|------|
| A | `ENUM_TS_MIRRORS` の単一出典化 (DRY) | `tests/js/support/enum-ts-sync/mirror-inventory.ts` (新規) / `tests/js/architecture/enum-ts-sync.test.ts` (目録定義を移設) |
| B | PHP 側の発見の段 (全数走査) | `tests/js/support/enum-ts-sync/php-enum-catalog.ts` (新規) / `tests/js/support/enum-ts-sync/php-enums.ts` (`detectEnumHeaders` を共有抽出器として追加) |
| C | TS 側の候補走査 | `tests/js/support/enum-ts-sync/ts-candidates.ts` (新規) |
| D | 逆走査 2 規則 | `tests/js/support/enum-ts-sync/reverse-sweep.ts` (新規) |
| E | 発見の段・逆走査の gate 本体 | `tests/js/architecture/enum-ts-sync-discovery.test.ts` (新規) |
| F | 抽出器・純関数の自己検査 (負例行列) | `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts` (新規) / `tests/js/support/enum-ts-sync/fixtures/candidates/` `fixtures/candidates-broken/` (新規見本) |
| G | 規約・文書 | `AGENTS.md` ドメイン固有規約 19 / `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期 |
| H | D29 の再判定 | `docs/template-divergence.md` (D29 を削除) / `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` (件数 31→30) |

## 2. 発見の段の分類結果 (実測)

`app/` 配下の git 追跡下 `*.php` 841 本のうち、文字列付き列挙を宣言する母集団は
**resolved 112 件 + unresolvable 3 件 = 115 件**。

- **登録済み** (`ENUM_TS_MIRRORS`): 26 ユニークな PHP パス (27 行。`MaterialType.php` が
  2 つの TS 宣言へ写している分の重複を除く)
- **対象外の理由つき** (`PHP_ENUM_EXEMPTIONS`): 86 件。個別に理由を記述した
  (画面へ値を送らない内部語彙・状態機械・Architecture テストの分類語彙 等)
- **抽出できない残余** (`KNOWN_UNRESOLVABLE_PHP_ENUMS`): 3 件
  - `app/Enums/Security/DeletionPathSeamExemption.php` — case が 0 件
  - `app/Enums/Security/RescueRouteGateDisposition.php` — case の値が FQCN (逆斜線を含む)
  - `app/Mcp/Servers/AppMcpServer.php` — ヒアドキュメントを含み走査器で読み切れない
    (docblock に「enum」の語があるための安全側の過剰検出。実際には enum を宣言していない)

26 + 86 = 112 で `resolved` と一致し、未分類は 0 件であることを実測した。

## 3. 逆走査の実測

`resources/js/` 配下で文字列リテラル型だけの union に解決する型別名は 50 件超
(`tsconfig.json` の `include` が実際に決める母集団。ファイルシステムを直接歩いた集合との
完全一致を独立実装の回帰テストで固定している)。このうち未登録かつ規則に一致するのは

- 規則 1 (完全一致): `resources/js/types/manual.ts::SelectableTakeStatus`
  (`app/Enums/Manual/TakeStatus.php` と現在は値が完全一致するが、意図は部分集合)

の **1 件だけ**であり、T218 の設計時の見積り (survey.md) と一致する。
`REVERSE_SWEEP_EXEMPTIONS` へ理由付きで登録した。規則 2 (名前対応 + 値の交差) の
該当は 0 件だった。

## 4. Codex 実装レビュー

`impl-review-round-1.md` (CHANGES_REQUESTED) → `round-2.md` (CHANGES_REQUESTED) →
`round-3.md` (CHANGES_REQUESTED) → `round-4.md` (CHANGES_REQUESTED、残件は文書整合のみ) →
`round-5.md` (**APPROVED**)。判断の記録は `codex-history/impl-review-decisions-round-{1,2,3,4}.md`。

主な指摘と対応:

1. **[Round 1 Critical]** `ts-candidates.ts` が走査対象ファイルの構文診断を無言で
   読み飛ばしていた (fail-open)。例外にして gate を失敗させるよう修正した。
2. **[Round 1 Critical]** `php-enum-catalog.ts` の「scan() が拒否する字句を含むファイルの
   安全側判定」が、コメントを挟む enum 宣言や非 ASCII 識別子を見逃す狭い正規表現だった。
   `\benum\b` (直後の並びを問わない) へ広げ、新たに `app/Mcp/Servers/AppMcpServer.php` を
   `KNOWN_UNRESOLVABLE_PHP_ENUMS` へ登録した。
3. **[Round 2 Critical]** 波括弧付き namespace 宣言の検出が named 形の正規表現 1 本に
   依存しており、無名 namespace・大文字 `NAMESPACE`・コメント割り込みで見逃していた。
   **共有走査器 (`php-enums.ts`) が「深さ付きの enum 候補」を返す設計へ変更**し、
   個別の namespace 構文を当てずに「深さ 0 でない候補が 1 件でもあれば unresolvable」
   という 1 つの判定へ一般化した。
4. **[Round 3 Critical]** 上記 3 の初回修正 (`depthZero.length === 0` の判定) が、
   深さ 0 の enum と深さ 0 以外の enum が同じファイルに共存するケースで、
   深さ 0 以外の enum を黙って捨てる (fail-open) ままだった。
   `depthZero.length !== headers.length` へ修正し、1 件でも深さがずれていれば
   unresolvable にするよう改めた。
5. **[Round 4]** コード上の懸念は解消済みで、残件は TS 母集団の「単一出典」の説明が
   `docs/architecture.md` / `ts-candidates.ts` / テスト名の間で食い違っていた点のみ。
   「tsconfig が実際に決めるが、それだけを出典とは言わない。ファイルシステムを
   直接歩いた集合との完全一致が不変条件の実体」という表現へ 3 箇所とも揃えた。

## 5. 故障注入 (感度の裏取り)

以下の故障注入すべてで対応するテストが赤くなることを実測し、元に戻した。

| 注入 | 結果 |
|---|---|
| `PHP_ENUM_EXEMPTIONS` から 1 行削除 | 赤 (未分類の検出) |
| `REVERSE_SWEEP_EXEMPTIONS` を空にする | 赤 (未登録候補の検出) |
| `KNOWN_UNRESOLVABLE_PHP_ENUMS` から 1 行削除 | 赤 (stale 検出と逆) |
| `ts-candidates.ts`: 構文診断を無視する形に戻す | 赤 (故障注入で追加した負例) |
| `php-enum-catalog.ts`: 救済正規表現を狭める | 赤 (D11/D12 と KNOWN_UNRESOLVABLE の stale 検査の両方) |
| `php-enum-catalog.ts`: 深さ 0 の判定 (Round 2 の修正) を撤去する | 赤 (D13/D15/D16。クラッシュを含む) |
| `php-enum-catalog.ts`: 深さ共存の判定 (Round 3 の修正) を撤去する | 赤 (D17/D18) |
| `reverse-sweep.ts`: `normalizeName` を緩め (英数字以外を除去) に戻す | 赤 (E10) |

## 6. 検証コマンドの結果

| コマンド | 結果 |
|---|---|
| `composer test` | 6149 tests / 6147 passed / 2 skipped / 0 failed / 29323 assertions |
| `composer phpstan` (level 10) | No errors |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | passed |
| `pnpm typecheck` | エラー無し |
| `pnpm test` | 169 files / 2283 tests passed |
| `pnpm build` | 成功 |
| `pnpm typecheck:packages` / `pnpm build:packages` | 成功 |
| `pnpm test:packages` | 10 files / 106 tests passed |

## 7. 設計から変えた点

- 詳細設計 (T218 の devnotes) の「後続 TODO」は完了条件の文面だけを与えており、
  施策の具体的なファイル構成は本実装で決めた。特に、Codex レビューを経て
  **PHP 側の抽出器を「深さ付きの enum 候補を返す 1 つの共有走査器」へ一般化**した点は、
  設計時点では想定していなかった (個別の namespace 構文への対応で始めたが、
  Round 2/3 のレビューで「個別の構文にパッチを当てる設計は堅牢でない」という指摘を受け、
  根本設計を変更した)。
- `docs/template-divergence.md` の D29 (「全数走査と逆走査を持たない」という逸脱登録) は
  再判定条件を満たしたため**登録を削除**した (「解消は状態ではなく登録の削除で表す」という
  台帳の規約に従う)。登録エントリ数は 31 件→30 件。

## 8. 保証しないもの (誇張しない。正本は `docs/architecture.md`)

- 逆走査は「登録漏れが無いことの証明」ではない。名前も対応せず値も完全一致しない
  drift 済みの写しは検出できない。
- `collectTsUnionCandidates` は `resources/js/` 配下の `type X = …` というトップレベル
  宣言だけを見る。`.svelte` の中の宣言・定数配列・switch の case ラベル・`.d.ts` は対象外。
- PHP 側は本 gate 専用の字句走査器が受理できる範囲に限る
  (`docs/architecture.md` / `php-enum-catalog.ts` の docblock が詳細)。

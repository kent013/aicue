結論として、偽陰性になる実経路が2件あり、必要な故障注入テストにも未充足があります。`CHANGES_REQUESTED` です。レビューは提示された差分のみを読み、コマンド実行・書き込みは行っていません。

### [tests/js/support/enum-ts-sync/program.ts](/workspace/.claude/worktrees/tasks/T261/tests/js/support/enum-ts-sync/program.ts)

- [Critical] tsconfig を持たないパッケージが fail-closed になっていません。

  `listPackageProgramRoots()` は tsconfig を持つパッケージしか返さず、`ownerOfRelative()` はそれ以外をすべて `<root>` に割り当てます。そのため、例えば `packages/new-tool/src/status.ts` を追加しても、設計どおり「所有者の program が無い」で落ちず、ルート設定で解析されます。母集団の直和検査も「root に1回載った」と判定して通ります。

  S3 の「tsconfig を持たないパッケージはどの program にも載らず赤になる」と正反対で、NodeNext 依存型が非候補へ縮む偽陰性につながります。`packages/<name>/` への所属判定と、「解析可能な package program があるか」の判定を分離してください。

- [Warning] `parsed.projectReferences` が `ts.createProgram()` に渡されていません。

  パッケージ自身の tsconfig を読むだけでは「本番と同じ型世界」になりません。project references を使う設定では参照先が欠けます。旧実装が渡していた値でもあり、意図的に外す根拠もありません。

- [Warning] `MirrorProgram.virtualPaths` は構築されますが利用されていません。

  `sourceOf()` は別の `virtualByReal` を使っています。これは共通規約 (d) の「集めた走査結果を判定に使わない」に該当し、設計で要求した canonical path の検査も実効性がありません。

### [tests/js/support/enum-ts-sync/reverse-sweep.ts](/workspace/.claude/worktrees/tasks/T261/tests/js/support/enum-ts-sync/reverse-sweep.ts)

- [Critical] 語へ分割できない宣言名が、交差率によっては例外にならず黙って消えます。

  `matchReverseRule()` は2bの半数条件を確認してから `wordNameCorrespondence()` を呼びます。したがって、例えば PHP 値が `{a,b,c,d}`、TS の `type ___ = "a" | "x" | "y"` なら、1値は交差しますが半数条件を満たさないため `kind: "none"` で終了します。`splitWords("___")` が空であることは検査されません。

  詳細設計は「宣言名から語が1つも取れなければ例外」としています。語の非空検査を、2bの交差率による早期 return より前へ移す必要があります。現行テストは半数条件を満たす入力しか使わないため、この経路を検出できません。

### [tests/js/support/enum-ts-sync/ts-value-sets.ts](/workspace/.claude/worktrees/tasks/T261/tests/js/support/enum-ts-sync/ts-value-sets.ts)

- [Warning] 型別名の値抽出が二重実装のままです。

  `resolveTsDeclaration()` は自身で `getDeclaredTypeOfSymbol()` とリテラル走査を行った後、共有抽出器 `readResolvedStringLiteralUnion()` を呼んでいるだけです。しかも比較するのは `kind` だけで、共有抽出器が返した値集合とは比較せず、独自に作った値集合を返しています。

  S3b/S7 の「逆走査と前向きが共有する唯一の抽出器」に適合しません。共有抽出器の `values` をそのまま返し、前向き固有の診断変換だけをここで行うべきです。

### [tests/js/support/enum-ts-sync/ts-candidates.ts](/workspace/.claude/worktrees/tasks/T261/tests/js/support/enum-ts-sync/ts-candidates.ts)

- [Warning] `nameResolved` は収集されていますが、判定では使われていません。

  `reverse-sweep.ts` は `correspondenceName === null` を直接見ています。設計が `nameResolved` を判定へ渡すと定めていることに加え、共通規約 (d) に反します。片方へ統一してください。

### [tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts](/workspace/.claude/worktrees/tasks/T261/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts)

- [Warning] Round 4 Critical を受けた locator の境界試験が不足しています。

  実装は分類前に採番していますが、次の必須ケースがありません。

  - 同名の「判定保留 → 候補」で後者が `occurrence: 1`
  - 同名の「非候補 → 候補」で後者が `occurrence: 1`
  - 一方の申告が他方へ効かないこと

  現在あるのは候補同士の `NestedShadow` と、候補同士の「入れ子→最上位」です。

- [Warning] S3b が要求する共有抽出器5関数の三値分岐を直接試験していません。

  特に `switch-cases` と計算キーについて、`any` / `unknown` / enum / 正常な非候補の境界が本体走査経由でも十分に固定されていません。

- [Warning] 「`.svelte` の4形を拾う」というテスト名に対し、見本と assertion に `switch-cases` がありません。実際に固定しているのは3形です。

- [Warning] NodeNext の回帰試験がモジュール解決を通っていません。

  `API_ERROR_CODES` と `ApiErrorCode` は同一ファイル内の宣言なので、ルート設定で program を構築しても試験が通り得ます。設計が要求する `./schemas.js` のような import 越しの型を使う必要があります。

- [Warning] 除外根へ正常な `.ts` を置く境界試験は、単に「構文診断が0件」と確認しているだけです。除外根の自己点検ロジックを削除・破壊してもこのテストは通るため、故障注入1'の受け皿になっていません。

### [docs/architecture.md](/workspace/.claude/worktrees/tasks/T261/docs/architecture.md)

- [Warning] 「tsconfig を持たないパッケージはどの program にも載らず直和検査が赤くなる」という保証は、現実装では成立していません。前述のとおり `<root>` に割り当てられます。

### [docs/template-divergence.md](/workspace/.claude/worktrees/tasks/T261/docs/template-divergence.md)

- [Warning] D54 の「値集合の抽出器を2本持たない」「前向きは共有抽出器との食い違いを検査する」という保証が、`ts-value-sets.ts` の実装より強くなっています。現状は独自抽出と共有抽出を並走させ、値集合そのものは比較していません。

D54への採番変更と件数pinの現物追従自体には問題を認めません。

### 検証結果

- [Warning] `composer test` は全greenではありません。

  直列で関連46件が通ったことは、7835件のフルレーンが通ったことの代替にはなりません。AGENTS.md の完了条件が「検証コマンド全green」なので、フレーク原因を是正するか、少なくともクリーンなフル実行のgreen結果が必要です。

### 指摘なし

提示差分の範囲では、以下は設計意図と整合しています。

- `AGENTS.md`
- CLIの `client.ts` / `schemas.ts` / `oauth/login.ts` / `error-code-dispatch.test.ts`
- `resources/js/types/organization.ts`
- `LedgerPins.php` / `adoption-debt.tsv`
- `enum-ts-sync-discovery.test.ts`
- `enum-ts-sync-extractor.test.ts`
- `enum-ts-sync.test.ts`
- 追加fixture一式
- `php-enum-catalog.ts`
- `population.ts`
- `relation-inventory.ts`
- `repo-root.ts`
- `svelte-source.ts`
- `ts-literal-values.ts`

OAuthスコープの縮小は、サーバ未登録の2値を削除し、`subset` で「CLI要求値 ⊆ サーバ許可値」を固定する形になっており、最小権限の方向として妥当です。

## 全体判定

**CHANGES_REQUESTED**
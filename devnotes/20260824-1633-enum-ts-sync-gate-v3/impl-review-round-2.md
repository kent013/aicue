Round 1 の Critical 1 は実装上修正されています。一方、Critical 2 と同型の偽陰性が PHP 列挙名側に残っています。また、#10 の弱めた主張が文書全体へ反映されていません。

### [tests/js/support/enum-ts-sync/reverse-sweep.ts](/workspace/.claude/worktrees/tasks/T261/tests/js/support/enum-ts-sync/reverse-sweep.ts)

- [Critical] 語へ分割できない PHP 列挙名が、規則2bから黙って消えます。

  候補名については交差率判定より前に非空を検査するよう直っています。しかし `wordNameCorrespondence()` は次の処理を残しています。

  ```ts
  const enumWords = splitWords(enumName);
  if (enumWords.length === 0) return null;
  ```

  PHP の識別子は `_` だけの名前や非ASCII名を取り得ます。例えば PHP 列挙名が `状態`、TS候補名が `JobStatus` で値が部分的に交差する場合、2aには一致せず、2bでは `enumWords` が空になって `null`、最終的に `kind: "none"` になります。

  PHP側カタログは非ASCII識別子を保証外としておらず、むしろ既存テストは非ASCIIも見逃さない前提です。候補名と同様、`enumWords.length === 0` も例外にして fail-closed にする必要があります。交差が半分未満・半分以上の両方を負例で固定してください。

- [Warning] 新設した内部矛盾の分岐が直接試験されていません。

  `nameResolved === true && correspondenceName === null` の例外は妥当ですが、その形を手組みした負例がありません。共通規約 (c) の対象として追加すべきです。

### [tests/js/support/enum-ts-sync/program.ts](/workspace/.claude/worktrees/tasks/T261/tests/js/support/enum-ts-sync/program.ts)

- Critical 1 の実装修正は妥当です。

  所属は `listPackageDirectories()` で全パッケージから決まり、programの有無は `byOwner.has(owner)` で別に検査されています。tsconfigなしのパッケージが `<root>` へ落ちる経路は閉じています。`projectReferences` の復旧と未使用の `virtualPaths` 削除も妥当です。

- [Warning] 冒頭docblockが実装と食い違っています。

  「tsconfigを持たないパッケージはどのprogramにも載らず、母集団の直和検査が赤くなる」とありますが、実際には `ownerOf()` / `programOf()` による所有者解決で例外になります。対応マトリクス #12 の修正がこのファイルには反映されていません。

- [Warning] `findExcludedSurvivors()` が `.svelte` の読み込み失敗まで「期待した構文不正」として吸収します。

  `fs.readFileSync()` が `try` 内にあるため、追跡ファイルの欠落やI/Oエラーでも `continue` します。読み込みは `try` の外で行い、捕捉するのは `toVirtualUnit()` の構文・属性拒否だけにしてください。現在の除外根は `.ts` のみですが、docblockは将来の `.svelte` も保証しています。

### [tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts](/workspace/.claude/worktrees/tasks/T261/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts)

- [Warning] tsconfigなしパッケージの「所有者解決で落ちる分岐」を直接は試験していません。

  一時ディレクトリのテストが確認するのは以下だけです。

  - `listPackageDirectories()` に両ディレクトリが出る
  - `hasPackageTsconfig()` が false を返す

  `ownerOf()` が `<root>` を返す実装へ回帰しても、この2つは通ります。現リポジトリの「全パッケージにtsconfigがある」検査も、その回帰を検出しません。

  所属決定と `byOwner` の突き合わせを純関数へ切り出し、「所属は package、programなしなので例外」を直接固定するのが確実です。

- それ以外の追加テストは対象分岐へ到達しています。

  - 判定保留→候補、非候補→候補の採番
  - occurrenceを含む申告の分離
  - 共有抽出器の三値
  - `.svelte` の4形
  - 除外survivorの集合
  - 候補名の語が空かつ交差率が半分未満の経路

### [tests/js/support/enum-ts-sync/ts-value-sets.ts](/workspace/.claude/worktrees/tasks/T261/tests/js/support/enum-ts-sync/ts-value-sets.ts)

指摘ありません。

`resolveTsDeclaration()` は共有抽出器の `values` をそのまま返しており、`diagnoseTypeAlias()` は値集合を生成せず、前向き向けの理由へ翻訳するだけです。T22/T23の「判定保留」への診断変更も、以前より原因を正確に表しています。

### [docs/architecture.md](/workspace/.claude/worktrees/tasks/T261/docs/architecture.md)

- [Warning] #10 の弱めた主張と強い旧主張が同居しています。

  後段では「現物では偽陰性は観測されていない」「予防である」と正しく弱めています。しかし節の冒頭には依然として、ルート設定で読むと取り込みが解決せず `any` へ落ちる、と事実として書かれています。

  また「保証しないもの」には、tsconfigなしパッケージが「母集団の直和検査で赤くなる」という旧説明が残っています。実際は所有者解決時の例外です。

  次の形へ統一してください。

  - 設定差による現物の解決失敗は観測されていない
  - package自身の設定で解析することを機械固定している
  - tsconfigなしは所有者のprogram解決時に落ちる
  - 直和検査は重複・欠落した起点の別検査

### [docs/template-divergence.md](/workspace/.claude/worktrees/tasks/T261/docs/template-divergence.md)

指摘ありません。共有抽出器についての記述は現在の `ts-value-sets.ts` と一致しています。

### 検証結果

- [Warning] `composer test` のクリーンなフル実行はまだ完了していません。

  コードレビューとは別に、AGENTS.mdの完了条件上、最終承認にはフルレーンのgreen結果が必要です。

## 全体判定

**CHANGES_REQUESTED**
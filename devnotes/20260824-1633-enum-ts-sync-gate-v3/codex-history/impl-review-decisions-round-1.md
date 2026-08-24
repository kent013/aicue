# 実装レビュー Round 1 の対応マトリクス (Claude 側)

| # | 区分 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Critical | tsconfig を持たないパッケージが fail-closed でない (`<root>` へ落ちる) | **対応する** | 所属の判定 (`listPackageDirectories()` = `packages/` 直下の全ディレクトリ) と「解析できる program があるか」(`hasPackageTsconfig()`) を**分離**した。所属は tsconfig の有無で絞らないので、自前の tsconfig を持たないパッケージのファイルは `ownerOf()` の時点で例外になる。純関数の負例 (見本の木) と実リポジトリの全数検査を追加 |
| 2 | Critical | 語へ分割できない宣言名が、交差率によっては例外にならず黙って消える | **対応する** | `matchReverseRule()` の**語の非空検査を交差条件の早期 return より前**へ移した。交差が半分未満の入力で例外になることを負例で固定した |
| 3 | Warning | `projectReferences` を `ts.createProgram()` に渡していない | **対応する** | 渡すように戻した (旧実装が渡していた値であり、外す根拠が無い) |
| 4 | Warning | `MirrorProgram.virtualPaths` が判定に使われていない (共通規約 (d)) | **対応する** | `virtualPaths` を**削除**した。仮想パスの綴りは `VIRTUAL_SUFFIX` から決まる決定的な値で、正規化の一致は `buildProgram()` が組んだ直後に例外で固定している (対応表を持ち回る必要が無い) |
| 5 | Warning | 型別名の値抽出が二重実装のまま (共有抽出器の値集合を使っていない) | **対応する** | `resolveTsDeclaration()` は共有抽出器 `readResolvedStringLiteralUnion()` の `values` を**そのまま返す**形にし、前向き固有の診断だけを `diagnoseTypeAlias()` (値集合を作らない) で作るようにした。副作用として負例行列の T22 / T23 が「判定保留」の言葉になったので、行の**意味を更新**した (削除ではない) |
| 6 | Warning | `nameResolved` が収集されるだけで判定に使われていない | **対応する** | 判定側を `!candidate.nameResolved` に統一し、`nameResolved` が真なのに名前が無い形は**内部矛盾として例外**にした (両方が判定に効く形にした) |
| 7 | Warning | locator の境界試験が不足 (判定保留 → 候補 / 非候補 → 候補 / 申告が他方へ効かない) | **対応する** | 見本 `fixtures/candidates/staged-occurrence.ts` を足し、3 つとも固定した。判定保留の申告が 1 件増えたので pin を 5 → 6 に直した |
| 8 | Warning | 共有抽出器 5 関数の三値分岐を直接試験していない | **対応する** | `ts-literal-values.ts` の 5 関数 + `isIndeterminateType` を直接突く describe を新設した (`const-array` に判定保留の分岐が無いこと、計算キー / `case` / 型別名の `any` が判定保留になること、素の `any` / `unknown` が正常な非候補であること) |
| 9 | Warning | 「`.svelte` の 4 形」と言いながら `switch-cases` が無い | **対応する** | 見本 `fixtures/svelte/Sample.svelte` に分岐を足し、4 形すべてを assert した |
| 10 | Warning | NodeNext の回帰試験がモジュール解決を通っていない | **対応する (ただし主張を弱める)** | **実測すると差が出なかった** — `./schemas.js` はルートの設定 (bundler) でも解決でき、両方の設定で意味診断は 0 件だった。したがって「取り込みが解決できず候補が消える」を現物で示すことはできない。代わりに**どの設定で組まれた program に載っているか**を直接固定する試験へ差し替え (`ownerOf` が `packages/cli` を返し、その program の `moduleResolution` が NodeNext、ルートが Bundler であること)。併せて `docs/architecture.md` と故障注入の記録に「この差は現物では観測されていない。方式は偽陰性を作らない側の予防である」と明記した |
| 11 | Warning | 除外根の境界試験が故障注入 1' の受け皿になっていない | **対応する** | gate が持っていた判定を `findExcludedSurvivors()` へ切り出し、gate はそれを呼ぶだけにした。自己検査は一時ディレクトリの見本 (正常な `.ts` / 壊れた `.ts` / 正常な `.svelte` / 壊れた `.svelte` / 本番の入口を持たない拡張子) を渡して**生き残りの集合**を固定する。関数を壊すとこの試験が赤くなることを実測した |
| 12 | Warning | `docs/architecture.md` の「tsconfig を持たないパッケージは載らず直和検査が赤くなる」が実装より強い | **対応する** | #1 の修正で成立するようになったが、落ちる場所は直和検査ではなく**所有者の解決**なので、その旨へ書き直した |
| 13 | Warning | D54 の「共有抽出器」「食い違いを検査する」が実装より強い | **対応する** | #5 の修正に合わせて D54 の文面を「共有抽出器が返した値集合をそのまま返し、前向き固有の診断への翻訳だけを自分で行う」へ直した |
| 14 | Warning | `composer test` が全 green ではない | **対応する** | 指摘のとおり。並列実行時の CPU 競合で `EmailPromotionTest` 2 件と `BughuntSelfTestExecutionTest` 2 件が落ちていた (直列では 46/46 green)。他のレーンを止めたクリーンな環境でフル実行し直して報告する |

## 追加した故障注入 (レビュー指摘の再現)

指摘 #1 / #2 / #5 は「直した後に元へ戻すと赤くなる」ことを実測した (C1 / C2 / C3)。
記録は `../fault-injection-log.md`。

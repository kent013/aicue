# 対応マトリクス: design-review Round 1

すべて「対応する」。うち 4 件は**実測をやり直して**判断そのものを変えている
(`probe/probe2.ts` を新設し、指摘を反映した最終形の判定式で数え直した)。

## [Critical] S1/S9: 不正な `.svelte` 見本を追跡配置すると本番母集団が恒久的に赤くなる
- 判断: 対応する
- 根拠: 指摘のとおり。除外根は `candidates-broken` の 1 つだけなので、
  `Broken.svelte` / `DuplicateContext.svelte` を追跡すると `parse` が必ず失敗する。
- 対応内容: **不正な入力はテストの中の文字列で与える**方針へ変更した (S9)。
  追跡する `.svelte` の見本は正常な `Sample.svelte` / `Other.svelte` の 2 本だけにした。
  併せて S1 の docblock に「将来 `.svelte` を除外根へ入れるなら、除外の自己点検は
  拡張子ごとに本番と同じ入口 (`.ts` は TS の構文診断、`.svelte` は `toVirtualUnit()` の失敗) を
  使う」と条件を書いた。

## [Critical] S2: 文脈ごとに別ファイルへ割ると Svelte のスコープを再現できない
- 判断: 対応する (**設計を変更**)
- 根拠: 指摘の 2 点はどちらも正しい。実測もした
  (`probe/svelte-scope-probe.mjs`): 取り込みも書き出しも無い仮想 `.ts` は大域スクリプトになり、
  **別コンポーネントで宣言した型が解決に混入する** (`type Ref = Shared` が
  他ファイルの `"a" | "b"` に解決した)。
- 対応内容: **1 つの `.svelte` につき仮想 TS を 1 本**にし、module と実体の両方の中身を
  元の位置のまま残す形へ変更した。末尾へ `export {};` を足して**モジュール文脈**にする
  (末尾なので行・列は動かない)。これで
  (a) コンポーネント間は外部モジュールとして隔離され、
  (b) module → 実体の参照は同一ファイル内なので自然に解決し、
  (c) 同名宣言は前向きの検査が「同名が 2 件」で落とす。
  指摘された 4 つの検査をテスト計画へ入れた。

## [Critical] S3: `packages/cli` をルートの設定で読むと未解決が非候補へ静かに混ざる
- 判断: 対応する (**設計を変更**。提案の 1 つ目を採用)
- 根拠: 指摘のとおり。docblock で保証外にするだけでは「版管理下全数を型情報で走査する」と
  両立しない。
- 対応内容: **program をパッケージごとに作る**形へ変更した
  (`<root>` と `packages/*` のうち tsconfig を持つもの)。母集団の全件が
  **ちょうど 1 本の program に載ること**を過不足の両方で検査する。
  併せて提案の 2 つ目も採り、S4 に**解析不能の三値**を入れた
  (`any` / `unknown` に落ちたのに構文はそうでない宣言は `unresolvable` へ積み、
  gate が既定拒否で申告を要求する)。実測すると `unresolvable` は 3 件
  (すべて既存の見本 `t22-circular` / `t23-unresolved-import`) だった。
  NodeNext の取り込みを経由する形の負例と、ルートの program へ混ぜると赤くなる
  故障注入 3' も足した。

## [Critical] S4: `as const` / `satisfies` の包みを剥がしていない
- 判断: 対応する
- 根拠: 指摘のとおり。実測でも `as const` を剥がしたら定数配列の候補が 22 件 → 54 件に増えた。
- 対応内容: `ParenthesizedExpression` / `AsExpression` / `SatisfiesExpression` を
  繰り返し剥がす共通処理を置き、**明示の型は値と別に取る**ことを明記した
  (`SatisfiesExpression.type` を `getTypeFromTypeNode()` で解決する。
  `getTypeAtLocation(initializer)` は使わない)。負例もテスト計画へ入れた。

## [Critical] S5: (e) 向けの負例が判定式では負例にならない
- 判断: 対応する (提案の 2 つ目を採用)
- 根拠: 指摘のとおり。`DraftJobStatus` / `NonJobStatus` は語として `job` と `status` を持ち
  主要語も一致するので、本判定式では**成立するのが正しい**
  (`DashboardJobStatus` が同じ理由で成立している)。
- 対応内容: 負例を「許可語を部分文字列として含むがトークン完全一致しない形」へ差し替えた —
  接頭辞 `PrejobStatus` (語は `[prejob, status]`) / 打ち消し `JobNonstatus`
  (主要語が `nonstatus`) / 接尾辞 `JobStatusKind` (主要語が `kind`)。
  分割結果もテストで固定する。`DraftJobStatus` / `NonJobStatus` を負例にしない理由も明記した。

## [Critical] S8: 段 6 の「申告を 7 件へ整備すると緑」は成立しない
- 判断: 対応する
- 根拠: 指摘のとおり。`ApiErrorCode` 系は S11 の後でないと解けない。
  再測定で鳴った組は 10 件になり、内訳もずれていた。
- 対応内容: 実装の順序の表に「**この段で gate 全体は緑か**」の列を足し、
  段 6 は明示的に「いいえ」、**段 9 (S11) で初めて逆走査 gate が緑**になると書いた。
  段 6 で出る 9 件の内訳と、段 9 で解消する 3 件も明記した。

## [Critical] S9: 不正 fixture が本番を壊す
- 判断: 対応する (S1/S9 の Critical と同じ手当て)

## [Critical] S11: `CliLocalErrorCode` の名前が発生源と一致しない
- 判断: 対応する
- 根拠: 指摘のとおり。列挙された符号は道具が作るのではなく、サーバ側の個別の面が返す。
- 対応内容: `NON_CANONICAL_API_ERROR_CODES` へ改名し、docblock に
  「道具の内部で作る符号ではない — 発生源はサーバ側の個別の面である」と書いた。

## [Warning] S1: 「全数」と「`.d.ts` は入れない」が矛盾する
- 判断: 対応する
- 対応内容: 一覧を 2 つに分けた — `listProgramTsFiles()` (型世界の起点。`.d.ts` を**含む**) と
  `listCandidateTsFiles()` (候補を探す対象。`.d.ts` を**除く**)。docblock で使い分けを明記した。

## [Warning] S1: `git ls-files` を改行で分割すると全数を列挙できない
- 判断: 対応する
- 対応内容: `git ls-files -z` + NUL 分割へ変更した (probe2 も同じに直して再測定した)。

## [Warning] S1: 空の一時ディレクトリでは「0 件」ではなく別の失敗になる
- 判断: 対応する
- 対応内容: 生出力を受け取る純関数 `parseTrackedOutput(raw)` を切り出し、
  「正常終了したが 0 件」の分岐を単体で突くようにした (故障注入 3 の注入点でもある)。

## [Warning] S2: script 属性の受理表が未確定
- 判断: 対応する
- 対応内容: 実測した (現物に在るのは `instance: lang="ts"` 130 件 /
  `module: lang="ts"` 2 件 / `module: module` 2 件の 3 種だけ。`src` / `context` /
  `generics` は 0 件)。受理表を `lang` と `module` の 2 つに定め、
  それ以外 (`src` / `context="module"` / `generics` / 未知) を不合格にすると書いた。

## [Warning] S2: 改行・サロゲート対の位置保存が曖昧
- 判断: 対応する
- 対応内容: 「**UTF-16 の符号単位の数を変えない**」「TS が改行と認識する文字
  (LF / CR / U+2028 / U+2029) は残す」と定義し、
  LF / CRLF / 孤立 CR / 非 BMP 文字 / U+2028 を含む見本での行・列試験を足した。

## [Warning] S2: `parse` は markup も構文解析する
- 判断: 対応する
- 対応内容: 保証範囲に「script の外は候補にしないが、**ファイル全体が `parse` できることは
  前提**である」と明記した。

## [Warning] S3: `getSourceFiles()` の集合と母集団の一致は成立しない
- 判断: 対応する
- 対応内容: 一致の主張を「**母集団の全件がちょうど 1 本の program に載っている**」へ変更し、
  起点は `getRootFileNames()` と比べ、候補走査の対象は母集団の集合に限ると書いた。
  「`getSourceFiles()` 全体を母集団の一致根拠にしない」と明記した。

## [Warning] S3: 仮想 map の鍵の正規化
- 判断: 対応する
- 対応内容: host の `getCanonicalFileName` を通した綴りを鍵にし、
  仮想の `SourceFile.fileName` が鍵と完全一致することをテストで固定すると書いた。

## [Warning] S4: 派生除外は 3 集合の一致を要求すべき
- 判断: 対応する
- 対応内容: 条件 4 として「**書かれたキー == 明示型の必須プロパティ**」を足した
  (条件 5 の証人と合わせて 3 集合の一致になる)。
  「欠落・余剰の見本が外れないこと」をテスト計画へ入れた。

## [Warning] S4: `const-array` なのに `let` / `var` を受理する
- 判断: 対応する
- 対応内容: `const-array` は `NodeFlags.Const` を必須にした。
  `object-keys` には要求しない (正典が「オブジェクト (対応表) のキー」としか言わないため)。
  この非対称を docblock に書くと明記した。

## [Warning] S4: switch の「名前解決不能」分岐と故障注入 8 が到達不能
- 判断: 対応する (**当て所を変更**)
- 根拠: 指摘のとおり。`getText()` が空になることは通常ない。
- 対応内容: 受理する式の形を「識別子 / `this` / それらのプロパティ参照の連なり」に限定し、
  外れたら `nameResolved: false` で候補として残す。そのうえで
  **`nameResolved` が偽 かつ 交差あり かつ 完全一致でない**組を
  「**判定不能**」として gate を赤くする形にした (交差 0 なら規則 2 の対象になり得ないので通す)。
  実測すると現物ツリーの判定不能は 0 件 (`switch (errorName(error))` などは交差しない) で、
  見本で交差する形を作れば故障注入 8 が到達可能になる。

## [Warning] S4: error type を単なる非候補にすると (b) に抵触する
- 判断: 対応する (S3 の Critical と同じ手当て)
- 対応内容: `unresolvable` の受け皿と `KNOWN_UNRESOLVABLE_TS_DECLARATIONS` の申告を新設した。

## [Warning] S5: 単数化で `status → statu` / `statuses → statuse` になる
- 判断: 対応する
- 対応内容: 規則を差し替えた (`ies → y` / 末尾が `ses`・`xes`・`zes`・`ches`・`shes` なら
  `es` を落とす / `s` は `ss` と `us` で終わらないときだけ落とす)。
  期待値 8 組 (`status` / `statuses` / `class` / `classes` / `policy` / `policies` /
  `values` / `kinds`) をテストで固定する。probe2 でも同じ規則で測り直した
  (実測の診断が `主要語=status` に直っている)。

## [Warning] S5: 2b で `switch:` を外すことと `:` の区切りが未記載
- 判断: 対応する
- 対応内容: `switch:` の除去を**両規則の共通の前処理**に置き、区切りの集合へ `:` を足した。

## [Warning] S5: 語列が空になる候補の扱いが未定義
- 判断: 対応する
- 対応内容: 「宣言名から語が 1 つも取れなければ**例外**にする」と定めた
  (静かに名前不一致へ混ぜない)。

## [Warning] S6: 複数 root での symlink 検査の対象 root の選び方
- 判断: 対応する
- 対応内容: 「**字面で一致した根**を 1 つに確定し、その同じ根を realpath 検査へ渡す」と明記し、
  `packages/cli/src` の中から外へ抜ける symlink と `packages/cli/src` 自体が symlink の
  負例を足した。

## [Suggestion] S6: `listPackageSrcRoots()` は綴り順・通常ディレクトリだけ
- 判断: 対応する
- 対応内容: そのとおり書いた。

## [Warning] S7: 仮想単位が 0 件のときと宣言が無いときが同じ結果になる
- 判断: 対応する
- 対応内容: 「仮想単位が無い」を先に落とし、宣言が 0 件のときだけ
  「型別名が見つからない」にすると書いた。
- 併せて: 実測で**実ドリフト 2 件がどちらも定数の配列**だったので、
  前向きの検査の受理範囲を **2 形 (型別名 / `const` の配列)** へ広げた。
  登録できる形が型別名だけだと申告が実質の許可一覧に膨らむため (i11)。
  対応表のキーと分岐のラベルは引き続き登録できないことを失敗メッセージへ書く。

## [Warning] S7: S2 修正後に module → 実体の参照の見本が要る
- 判断: 対応する
- 対応内容: 前向き・逆走査の両方にその見本をテスト計画へ入れた。

## [Warning] S8: 「現行 1 件 → 6 件」と表 7 件と pin 7 が食い違う
- 判断: 対応する
- 対応内容: 再測定に基づき「現行 1 件に **7 件を足して合計 8 件**」へ統一した
  (`ApiErrorCode` の合併型が加わったため)。「設計時の見積りであり、実装時に実測と
  突き合わせる」とも書いた。

## [Warning] S8: Notification の将来変化を「規則 1 → 2a」とするのは誤り
- 判断: 対応する
- 対応内容: 「完全一致が崩れて申告が stale になり赤くなる。移り先が 2a か 2b かは
  判定対象の型名を解決できるかに依る」へ書き換えた。

## [Warning] S8: 除外根の全件構文破壊検査は `.svelte` を扱えない
- 判断: 対応する
- 対応内容: 「拡張子ごとに**本番と同じ入口**を使う (`.ts` は TS の構文診断、
  `.svelte` は `toVirtualUnit()` の失敗)」と書いた。

## [Warning] S8: `ResolvedPhpEnum.line` の波及先が未記載
- 判断: 対応する
- 対応内容: 波及変更の節に、この型を作っている場所 (`php-enum-catalog.ts` の 2 関数 /
  自己検査の `phpEnum()` ヘルパと D1〜D18・E1〜E11 の手書きオブジェクト /
  gate の合成入力) を列挙した。

## [Warning] S9: 故障注入の実現方法が未確定
- 判断: 対応する
- 対応内容: 故障注入 8 件を**表**にし、注入する純関数・注入の仕方・赤くなるテストを
  1 行ずつ書いた。併せて切り出す純関数 6 本
  (`isDerivedObjectKeys` / `buildWitnessIndex` / `switchSubjectName` /
  `auditReverseSweepExemptions` / `parseTrackedOutput` / `toVirtualUnit`) を明記した。
  「本番 API に任意の差し替え口を増やさない」とも書いた。

## [Warning] S10: テストファーストの順序が逆
- 判断: 対応する
- 対応内容: S10 の 2 つの負例を **S6 の実装前**に書き、S6 と同じ赤→緑の単位にした
  (順序の表でも段 7 に S6 + S10 を並べた)。

## [Suggestion] S10: 「1 行も変えない」の言い方
- 判断: 対応する
- 対応内容: 「**既存のケースは期待文言以外を変えない**」へ直した。

## [Warning] S11: `ApiErrorCode` は破壊的変更であり公開契約の確認が要る
- 判断: 対応する
- 対応内容: 確認して設計に根拠を残した — `package.json` の `main` は `./dist/index.js`、
  `src/index.ts` が書き出すのは `getCliVersion()` だけで `api/schemas` を再輸出しない。
  パッケージ名は `@app/cli` (作業空間の中だけで解決する名前) で登録所へ公開する設定を持たない。
  したがって外部の利用者への影響は無い。「後方互換を残さないは外部影響の確認を省く根拠に
  ならない」という指摘をそのまま設計文へ書いた。

## [Warning] S11: 429 退避の判断をテストが固定していない
- 判断: 対応する
- 対応内容: 提案の 4 系統をそのままテスト計画へ入れた
  (`rate_limited` + 429 / 旧綴り + 429 / 未知の符号 + 429 でない状態 /
  `insufficient_ability`・`actor_not_resolvable`・idempotency 系)。

## [Warning] S11: 最初の赤が記載どおりにならない
- 判断: 対応する
- 対応内容: 最初の赤を「道具側の失敗分類の検査」と「スコープ一致の検査」に変更した
  (存在しない宣言を目録へ先に足す形をやめた)。

## [Suggestion] S12: 件数は実装開始時と merge 直前に数え直す
- 判断: 対応する
- 対応内容: 「件数の扱い」節を足し、46→47 / 148→147 は設計時点の値であると明記した。

## [Suggestion] S12: D50 に「正本レーンは `pnpm test`」を維持条件として入れる
- 判断: 対応する
- 対応内容: D50 の「揃え続ける不変条件」に足した。

## [Suggestion] S13: docblock と `docs/architecture.md` の書き分け
- 判断: 対応する
- 対応内容: 3 か所の書き分け (docblock = 実装に密着した短い保証範囲 /
  `docs/architecture.md` = 理由と全体像と**保証しないものの正本** /
  `AGENTS.md` = 登録の手順と受理範囲だけ) を明記した。

## [Suggestion] S13: Svelte の parse 前提を文書にも反映する
- 判断: 対応する
- 対応内容: S8 の保証範囲と S2 の docblock の両方に書いた。

---

## 再測定の結果 (probe2.ts)

指摘を反映した最終形の判定式で数え直した。

- 母集団 `.ts` 377 本 (追跡下 378 − `.d.ts` 1) + `.svelte` 130 本 = **507 本**
- program **2 本** (`<root>` / `packages/cli`) を約 4.5 秒で構築
- 候補 **345 件** (型の合併 106 / 対応表のキー 172 / 定数の配列 54 / 分岐のラベル 13)
- 解析不能 **3 件** (すべて既存の見本)
- 派生の保留 86 件のうち証人つきで外れたのが **40 件**
- 判定不能 **0 件**
- 鳴った組 **10 件** (規則 1 = 6 / 規則 2a = 1 / 規則 2b = 3)

**Round 1 の指摘を反映した結果、実ドリフトが 1 件から 2 件に増えた**。
新しく見つかったのは `app/Enums/OAuth/CliOAuthScope.php` ⇔
`packages/cli/src/oauth/login.ts::DEFAULT_CLI_SCOPES` で、
道具側がサーバの登録していないスコープ (`evaluations:run` / `pages:bulk`) を要求している。
規則 2b が単数化の修正 (`codes → code` / `scopes → scope`) で拾えるようになった分である。

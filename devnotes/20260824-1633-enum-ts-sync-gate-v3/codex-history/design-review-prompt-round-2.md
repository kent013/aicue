# 詳細設計レビュー Round 2 の依頼

Round 1 の Critical 8 件・Warning 24 件・Suggestion 4 件をすべて捌きました。
うち 4 件は設計そのものを変更しており、判定式を最終形に直して**現物ツリーで測り直しています** (probe2.ts)。
その結果、実ドリフトが 1 件 → 2 件に増えました (CLI OAuth スコープ)。

## 対応マトリクス

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

## 修正後の詳細設計 (全文)

# 詳細設計: enum-ts-sync-gate v3 追従

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本作業に直接効く追加規約**:

- AGENTS.md §静的検査 (gate) と走査器の共通規約 **(a)〜(e)**
- AGENTS.md §走査器・gate を新設・変更するときに同じ PR で揃える **4 点**
- AGENTS.md §テンプレートとの関係 (指紋台帳・採用時債務・登録簿の件数 pin)
- app-design 3-0 段 (乖離台帳の確認段)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）— 本件は PHP の変更が無いので新規の型は増えないが、
  `composer phpstan` は緑を維持する
- **Pest** / **Vitest**。本件の正本のレーンは **`pnpm test`**
- **テストデータは必ず Factory で生成**（本件は DB を使わない）
- **アーリーリターン** 推奨
- **コードフォーマット**: `pnpm lint:fix`（`pnpm lint` の対象は `resources/js` のみ）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260824-1633-enum-ts-sync-gate-v3/conceptual-design.md` (Codex Round 4 で APPROVED)
- 実測: `devnotes/20260824-1633-enum-ts-sync-gate-v3/probe/measurements.md`
  (再現用スクリプト `probe/probe.ts`。**実装物ではない**)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 母集団モジュールの新設 (版管理下の全数 + 構文破壊見本の除外) | `tests/js/support/enum-ts-sync/population.ts` (新) | 高 |
| S2 | `.svelte` の仮想 TS 化 (1 ファイル 1 単位・モジュール文脈) | `tests/js/support/enum-ts-sync/svelte-source.ts` (新) | 高 |
| S3 | program をパッケージごとに作り、母集団を全部どれかに載せる | `tests/js/support/enum-ts-sync/program.ts` | 高 |
| S4 | 候補走査を 4 種へ + 派生の証人つき除外 + 解析不能の受け皿 | `tests/js/support/enum-ts-sync/ts-candidates.ts` | 高 |
| S5 | 規則 2 の論理和 | `tests/js/support/enum-ts-sync/reverse-sweep.ts` | 高 |
| S6 | 目録の受理範囲拡大 (`packages/*/src/` と `.svelte`) | `tests/js/support/enum-ts-sync/mirror-inventory.ts` | 中 |
| S7 | 前向きの検査を 2 形 (型別名 / const の配列) と `.svelte` へ | `tests/js/support/enum-ts-sync/ts-value-sets.ts` | 中 |
| S8 | 逆走査 gate の再整備 (申告・pin・メッセージ・保証範囲) | `tests/js/architecture/enum-ts-sync-discovery.test.ts` | 高 |
| S9 | 検出器の自己検査 (負例と故障注入) | `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts` + 見本 | 高 |
| S10 | 前向き gate の負の対照を新しい受理範囲へ | `tests/js/architecture/enum-ts-sync.test.ts` | 中 |
| S11 | 実ドリフト 2 件の是正 (API エラー符号 / CLI OAuth スコープ) | `packages/cli/src/api/schemas.ts` / `client.ts` / `oauth/login.ts` / `packages/cli/tests/` | 高 |
| S12 | 乖離台帳の手当て (D50 / 債務 1 行 / 件数 pin 2 つ) | `docs/template-divergence.md` / `adoption-debt.tsv` / `LedgerPins.php` | 中 |
| S13 | 文書の更新 | `AGENTS.md` / `docs/architecture.md` | 中 |

**設計時の実測 (probe2.ts。最終形の判定式)**:
母集団 `.ts` 377 本 (追跡下 378 本 − `.d.ts` 1 本) + `.svelte` 130 本 = 507 本 /
program 2 本 (`<root>` と `packages/cli`) を約 4.5 秒で構築 /
候補 345 件 (型の合併 106・対応表のキー 172・定数の配列 54・分岐のラベル 13) /
解析不能 3 件 / 派生の保留 86 件のうち証人つきで外れたのが 40 件 /
**鳴った組 10 件 (規則 1 = 6 / 規則 2a = 1 / 規則 2b = 3)**。
実ドリフトは **2 件** (API のエラー符号と CLI OAuth スコープ。どちらも道具パッケージ)。

---

## S1: 母集団モジュールの新設

### 変更箇所

- 新規: `tests/js/support/enum-ts-sync/population.ts`

### 波及変更

- TypeScript 型定義: 新規 (`ExcludedRoot`)
- API Resource/DTO: なし
- テストファイル: `enum-ts-sync-discovery-extractor.test.ts` に単体の負例を足す

### 変更後コード（骨子）

```ts
/**
 * 逆走査の母集団 (正典 v3 の i8)。
 *
 * **母集団**: `git ls-files -z` が返す**版管理下の `*.ts` と `*.svelte` の全数**。
 * 走査根の手書きの列挙は持たない (足し忘れが静かな穴になる)。
 * `-z` を使うのは、改行を含む合法なパスでも全数を列挙するためである。
 *
 * **2 つの一覧を区別する**:
 * - `listProgramTsFiles()` … 型世界に載せる起点。**`.d.ts` を含む**
 *   (周囲宣言が落ちると本番と違う型世界になる)
 * - `listCandidateTsFiles()` … 候補を探す対象。**`.d.ts` を除く**
 * どちらかが 0 件なら「母集団が不明」として例外にする (空振りを緑にしない)。
 *
 * **唯一の除外**: `EXCLUDED_ROOTS`。**わざと構文を壊した見本**だけを外す。
 * i14 が「構文が壊れたファイルを無言で読み飛ばさない」ので、これを母集団に入れると
 * 本番の gate が恒久的に赤くなる。申告では逃がせない (申告は候補を逃がす仕組みで、
 * 読めないファイルの受け皿ではない)。除外は `tests/js/support/enum-ts-sync/` の
 * 配下に限る (構造で縛る)。
 *
 * **保証しないもの**: 版管理外のファイル (無視されたもの・未追跡のもの) は見ない。
 * `.js` / `.mjs` / `.cjs` は母集団に入れない (本リポジトリの TS 以外の出口は
 * 本 gate の対象外である)。
 */
export interface ExcludedRoot {
    /** リポジトリ相対のディレクトリ。`tests/js/support/enum-ts-sync/` の配下だけ。 */
    readonly root: string;
    /** 外す理由 (30 文字以上)。 */
    readonly reason: string;
}

export const EXCLUDED_ROOTS = [
    {
        root: "tests/js/support/enum-ts-sync/fixtures/candidates-broken",
        reason: "候補走査が構文の壊れたファイルを無言で読み飛ばさないことの負の対照。中身は意図的に壊してある",
    },
] as const satisfies readonly ExcludedRoot[];

export const EXPECTED_EXCLUDED_ROOT_COUNT = 1;

/** `git ls-files -z` の生出力から一覧を作る**純関数** (0 件の分岐を単体で試験できるように分ける)。 */
export const parseTrackedOutput = (raw: string): readonly string[] => { … };

export const listProgramTsFiles = (root = REPO_ROOT): readonly string[] => { … };   // .d.ts を含む
export const listCandidateTsFiles = (root = REPO_ROOT): readonly string[] => { … }; // .d.ts を除く
export const listCandidateSvelteFiles = (root = REPO_ROOT): readonly string[] => { … };
/** 除外根の配下にある版管理下ファイル (除外の自己点検に使う。0 件は例外)。 */
export const listExcludedFiles = (root = REPO_ROOT): readonly string[] => { … };
/** 除外根の体裁 (配下・実在・重複無し・理由 30 文字以上)。 */
export const validateExcludedRoots = (roots = EXCLUDED_ROOTS, root = REPO_ROOT): void => { … };
```

- 除外の判定は**パスの区間一致** (`rel === root || rel.startsWith(root + "/")`)。
  素の `startsWith` にしない (兄弟ディレクトリ `candidates-broken-2/` を巻き込むため)
- **不正な `.svelte` の見本は追跡ファイルにしない** (S9 参照)。除外根は現時点で `.ts` だけを
  含む。将来 `.svelte` を除外根へ入れるなら、除外の自己点検 (S8) は
  **拡張子ごとに本番と同じ入口**を使う必要がある (`.ts` は TS の構文診断、
  `.svelte` は `toVirtualUnit()` の失敗)。この条件を docblock へ書く

### PHPStan適合チェック

- [x] PHP の変更なし

### テスト計画

- [ ] **先に赤くする**: `listCandidateTsFiles()` が `packages/cli/src/api/schemas.ts` を含むことを
      主張するテストを書く → モジュールが無いので解決に失敗して赤
- [ ] `listCandidateSvelteFiles()` が `.svelte` を返し、`listProgramTsFiles()` だけが `.d.ts` を含むこと
- [ ] 除外根の配下のファイルがどの候補一覧にも入らないこと / `listExcludedFiles()` には入ること
- [ ] `parseTrackedOutput("")` が空を返し、それを使う列挙が**例外になる**こと
      (「Git repository でない」ではなく「正常終了したが 0 件」の分岐を突く)
- [ ] 除外根の体裁の負例: 配下でないパス / 実在しないパス / 重複 / 理由 29 文字

### リスク

- `git ls-files` は shallow でない clone を前提にする。`listTrackedPhpFiles` が既に
  同じ前提で動いているので新しいリスクではない

---

## S2: `.svelte` の仮想 TS 化

### 変更箇所

- 新規: `tests/js/support/enum-ts-sync/svelte-source.ts`

### 決めたこと (レビュー Round 1 の Critical に対する結論)

**`.svelte` 1 本につき仮想 TS を 1 本だけ作る**。module 文脈と実体文脈の**両方の中身を
元の位置のまま**残し、script の外は空白で潰す。末尾に `export {};` を足して
**モジュール文脈**にする。

- **文脈ごとに別ファイルへ割らない**。割ると module の宣言を実体側から参照できなくなる
  (Svelte では参照できる)。1 本に収めれば参照は自然に解決する
- **`export {};` は必須**である。付けないと仮想ファイルが**大域スクリプト**になり、
  取り込みも書き出しも無いコンポーネント同士の宣言が**混ざる**。
  実測 (`probe/svelte-scope-probe.mjs`): `A.ts` が `type Shared = "a" | "b"` を宣言し
  `B.ts` が宣言せずに `type Ref = Shared` と書くと、`export {};` 無しでは
  **`Ref` が `"a" | "b"` に解決してしまう** (偽の候補が立つ)。付けると解決できなくなり、
  S4 の「解決できない候補は解析不能」に掛かる
- `export {};` は**末尾へ足す**ので、既存の宣言の行も列も動かない
- module と実体に**同名の宣言**があると 1 本の中で重複宣言になる。
  これは意味の診断 (本 gate は読まない) だが、
  **前向きの検査は「同名の宣言が 2 件」で落ちる** (S7)。
  逆走査は 2 件の候補として拾う (過剰検出の向き)。この扱いを docblock に書く

### 変更後コード（骨子）

```ts
/**
 * `.svelte` を第一級の解析対象にする (正典 v3 の i6)。
 *
 * `svelte/compiler` の `parse` (解析ツール向けの入口) で script の範囲を取り、
 * **script の中身以外を空白で潰した**仮想 TypeScript を 1 本作る。潰すときに
 * **UTF-16 の符号単位の数を変えない**ので、行も列も元ファイルと一致する。
 * 改行と認識される文字 (LF / CR / U+2028 / U+2029) はそのまま残す。
 *
 * **不合格にするもの (fail-closed)**:
 * - `parse` が失敗した (`.svelte` 全体の構文が壊れている)。
 *   **script の外 (目印・制御構文・スタイル) は候補にしないが、
 *   ファイル全体が `parse` できることは前提**である
 * - script の属性が受理表の外 (`lang="ts"` と `module` だけを受理する。
 *   `src` / `context="module"` / `generics` / 未知の属性は不合格)
 * - script の中身の範囲を取れない
 *
 * **保証しないもの**: 目印の中の式 (`{…}`)、`{#if}` などの制御構文の中、
 * スタイルの中は候補にしない。script の外に書いた値の一覧は候補にならない。
 */
export interface SvelteVirtualUnit {
    /** 元の `.svelte` のリポジトリ相対パス。 */
    readonly source: string;
    /** program に載せる仮想の絶対パス。 */
    readonly virtualPath: string;
    /** 行・列を保った仮想 TS (末尾に `export {};` が付く)。 */
    readonly text: string;
}

/** 仮想パスの接尾辞。実在ファイルと衝突しない綴りにする (`*.svelte.ts` は実在する)。 */
export const VIRTUAL_SUFFIX = ".__enum_ts_sync_virtual__.ts";

/** 受理する script 属性。実測 (2026-08-24) で現物に在るのはこの 2 つだけである。 */
export const ALLOWED_SCRIPT_ATTRIBUTES = new Set(["lang", "module"]);

export const toVirtualUnit = (relativePath: string, source: string): SvelteVirtualUnit => { … };

/** 仮想パス → 元の `.svelte` の相対パス。仮想でなければ `undefined`。 */
export const realPathOfVirtual = (virtualPath: string): string | undefined => { … };
```

- 属性の値も見る (`lang` は `"ts"` だけを受理する。`lang="js"` は不合格にしない —
  受理して TS として読む方が過剰検出の向きである。この判断を docblock に書く)
- 仮想パスの綴りが**版管理下に実在しない**ことを `population.ts` の一覧に対して検査する

### 実測による裏取り (設計時)

- 版管理下の `.svelte` **130 本すべてが `parse` に成功**する (svelte 5.56.3)
- 実在する script 属性は `instance: lang="ts"` 130 件 / `module: lang="ts"` 2 件 /
  `module: module` 2 件の**ちょうど 3 種**。`src` / `context` / `generics` は 0 件
- 同じ文脈の script が 2 つ以上あるファイルは 0 本

### テスト計画

- [ ] **先に赤くする**: 見本 `.svelte` (module と実体の両方を持つ) から仮想単位が返り、
      両方の宣言が読めることを主張 → モジュールが無いので赤
- [ ] **行・列の一致**: 見本の宣言の行・列が元ファイルと一致すること。
      LF / CRLF / 孤立 CR / 非 BMP 文字 (サロゲート対) / U+2028 を含む見本で固定
- [ ] **`export {};` の効き目**: 取り込みも書き出しも無い 2 つの見本コンポーネントに
      同名の宣言を置き、互いに干渉しないこと。片方でしか宣言していない名前を
      もう片方が参照しても**解決しない** (= 解析不能として落ちる) こと
- [ ] **module → 実体の参照**: 実体側の型別名が module 側の型別名を参照でき、
      値集合が読めること
- [ ] **故障注入 4**: `export {};` を足さない実装 / 文脈ごとに別ファイルへ割る実装に
      差し替えると、上の 2 つがそれぞれ赤くなる
- [ ] 不合格の負例 (**テストの中の文字列で与える。追跡ファイルにしない**):
      構文の壊れた `.svelte` / 同じ文脈の script が 2 つ / `src` 属性つき /
      `context="module"` / `generics` / 未知の属性
- [ ] 仮想パスの綴りが実在ファイルと衝突したら例外になること

---

## S3: program をパッケージごとに作る

### 変更箇所

- `tests/js/support/enum-ts-sync/program.ts` (全面的な作り直し。`buildProgram` は再利用)

### 決めたこと (レビュー Round 1 の Critical に対する結論)

**`packages/cli` をルートの設定 (bundler / ESNext) で読まない**。読むと NodeNext 前提の
取り込みが解決できず、型が `any` に落ちた宣言が「文字列リテラル型ではない = 非候補」として
**静かに消える**。i5 が言う「本番と同じ型世界」は、道具パッケージにとっては
**そのパッケージ自身の tsconfig** である。

したがって **program を複数本持つ**:

| program | 起点 |
|---|---|
| `<root>` | ルート `tsconfig.json` の全ファイル ∪ **どのパッケージにも属さない**版管理下の `*.ts` ∪ 仮想 `.svelte` |
| `packages/<name>` (tsconfig を持つものだけ) | そのパッケージの `tsconfig.json` の全ファイル ∪ そのパッケージ配下の版管理下の `*.ts` |

- **母集団の全件がちょうど 1 本の program に載ること**を検査する
  (載っていないファイルが 1 件でもあれば例外。**過不足の両方**を見る)
- 出力はしないので、起点を `rootDir` の外へ足せるよう
  `rootDir` / `outDir` / `declaration` / `declarationMap` / `composite` / `sourceMap` を
  落として組む (`noEmit: true`)
- 実測: program は 2 本、構築は合わせて約 4.5 秒 (`beforeAll` の 300 秒枠に十分収まる)

### 変更後の型

```ts
export interface MirrorProgram {
    readonly program: ts.Program;
    readonly checker: ts.TypeChecker;
    /** 仮想パス → 元の `.svelte` の相対パス。 */
    readonly virtualPaths: ReadonlyMap<string, string>;
}

export interface MirrorPrograms {
    /** 所有者 (`<root>` またはパッケージのディレクトリ) → program。 */
    readonly byOwner: ReadonlyMap<string, MirrorProgram>;
    /** 母集団の相対パス → それを載せている program。 */
    programOf(relativePath: string): MirrorProgram;
    /** 相対パス → その program 上の SourceFile (`.svelte` は仮想単位)。 */
    sourceOf(relativePath: string): ts.SourceFile;
}

/** 逆走査と前向きの検査が共通で使う。目録のファイルも所有者の program へ載る。 */
export const createMirrorPrograms = (): MirrorPrograms => { … };
```

- **`createMirrorProgram(tsFiles)` は廃止する** (後方互換の並走を残さない)。
  呼び出し側 (`enum-ts-sync.test.ts` / `enum-ts-sync-discovery.test.ts`) を
  `createMirrorPrograms()` へ揃える。前向きの gate 側の呼び出しが変わるので
  **S10 と S12 が発火する** (どのみち S6 の診断文の変更で発火する)
- `createFixtureProgram(absoluteFiles, virtualUnits?)` は**残す** (見本専用)。
  仮想単位を明示で渡せるよう引数を足す
- 仮想の対応表の鍵は host の正規化規則 (`getCanonicalFileName`) を通した綴りで持つ

### 波及変更

- TypeScript 型定義: `MirrorProgram` に `virtualPaths` / 新設 `MirrorPrograms`
- 呼び出し側: `enum-ts-sync.test.ts` / `enum-ts-sync-discovery.test.ts` /
  `enum-ts-sync-extractor.test.ts` / `enum-ts-sync-discovery-extractor.test.ts`
- テストファイル: 下記

### テスト計画

- [ ] **先に赤くする**: `createMirrorPrograms().programOf("packages/cli/src/api/schemas.ts")` が
      `packages/cli` の program を返すことを主張 → 現状は存在しないので赤
- [ ] **母集団の全件がちょうど 1 本に載る**: 母集団の相対パス集合と、
      各 program の対象集合の**直和**が完全一致すること (過不足の両方を出す)
- [ ] `getRootFileNames()` が期待する起点集合を含むこと。
      **`getSourceFiles()` 全体を母集団の一致根拠にしない** (依存ライブラリ・推移的な
      取り込み・JSON が載るため)
- [ ] 仮想 `.svelte` の `SourceFile.fileName` が `virtualPaths` の鍵と**完全一致**すること
      (正規化の食い違いを固定する)
- [ ] `packages/cli` の program が NodeNext の取り込み (`./schemas.js`) を解決できること
      (ルート設定で読むと解決できない見本と対にする)
- [ ] **故障注入 3**: 母集団の列挙を空に差し替えると「母集団が 0 件」で赤くなる
- [ ] **故障注入 3'**: `packages/cli` をルートの program へ混ぜる実装に差し替えると、
      NodeNext の取り込みを経由する型別名が解析不能になって赤くなる

### リスク

- パッケージが増えたとき、tsconfig を持たないパッケージのファイルは
  **どの program にも載らない** → 母集団の直和検査が赤くなる。
  これは fail-closed であり、そのとき「そのパッケージをどう扱うか」を判断させる形にする

---

## S4: 候補走査を 4 種へ + 派生の証人つき除外 + 解析不能の受け皿

### 変更箇所

- `tests/js/support/enum-ts-sync/ts-candidates.ts` (全面書き換え)

### 変更後の型

```ts
export type TsCandidateShape = "literal-union" | "const-array" | "object-keys" | "switch-cases";

export interface TsUnionCandidate {
    /** リポジトリルートからの相対パス (`.svelte` は仮想ではなく元のパス)。 */
    readonly file: string;
    /** 宣言の名前。分岐のラベルは `switch:<判定対象>`。 */
    readonly name: string;
    readonly shape: TsCandidateShape;
    /** 元ファイル上の行 (1 始まり)。 */
    readonly line: number;
    readonly values: ReadonlySet<string>;
    /** 分岐のラベルで判定対象の名前を決められたか。名前対応の判定に使う。 */
    readonly nameResolved: boolean;
}

/** 型を解決できず候補かどうかを決められなかった宣言 (解析不能)。 */
export interface UnresolvableTsDeclaration {
    readonly file: string;
    readonly line: number;
    readonly name: string;
    readonly reason: string;
}

export interface TsCandidateScan {
    readonly candidates: readonly TsUnionCandidate[];
    readonly unresolvable: readonly UnresolvableTsDeclaration[];
}
```

### 受理する 4 形（正典 i9）

| 形 | 受理条件 | 値集合 |
|---|---|---|
| `literal-union` | 型別名の宣言 (**入れ子も含む**)。解決した型が文字列リテラル型だけ | リテラルの値 |
| `const-array` | **`const` 束縛**の変数宣言で、包み (`as` / `satisfies` / 丸括弧) を剥がした初期化子が配列リテラル。要素が**すべて**文字列リテラル、1 件以上 | 要素の値 |
| `object-keys` | 変数宣言で、包みを剥がした初期化子がオブジェクトリテラル。プロパティが**すべて**通常の代入で、キーが文字列リテラル / 識別子 / 型検査器が文字列リテラルへ解決する計算キー。1 件以上 | キーの綴り |
| `switch-cases` | `switch` 文で、`default` を除く**すべての** `case` の式が文字列リテラル型へ解決する。1 件以上 | `case` の値 |

**包みの剥がし方**: `ParenthesizedExpression` / `AsExpression` / `SatisfiesExpression` を
繰り返し剥がして値の構文を得る。**明示の型**は別に取る —
変数宣言の型注釈 (`node.type`) を優先し、無ければ剥がす途中で見つけた
`SatisfiesExpression.type` を使う。型は `checker.getTypeFromTypeNode()` で解決する
(`getTypeAtLocation(initializer)` は `satisfies` の型ではなく値の型を返すので使わない)。

`const` 束縛の判定は `(declaration.parent.flags & ts.NodeFlags.Const) !== 0`。
**`object-keys` には `const` を要求しない** (正典は「オブジェクト (対応表) のキー」としか
言わず、`let` の対応表も写しになり得る)。`const-array` にだけ要求するのは正典の
「**定数の**配列」という言い方に合わせるためで、この非対称を docblock に書く。

`ts.TypeFlags.EnumLiteral` を持つ構成要素があれば受理しない (現行と同じ)。

### 三値にする (共通規約 (b))

「構文上は候補になり得るが、型が解決できない」を**非候補と混ぜない**。

```ts
const isUnresolvedType = (type: ts.Type, node: ts.Node | undefined): boolean =>
    (type.flags & (ts.TypeFlags.Any | ts.TypeFlags.Unknown)) !== 0
    && node !== undefined
    && node.kind !== ts.SyntaxKind.AnyKeyword
    && node.kind !== ts.SyntaxKind.UnknownKeyword;
```

- 型別名の解決結果 / 計算キーの型 / `case` の式の型 / 明示型の解決結果に対して適用する
- 当たったら `unresolvable` へ積む (**候補にも非候補にもしない**)
- gate 側は `unresolvable` の**全件が申告されている**ことを既定拒否で固定する
  (PHP 側の `KNOWN_UNRESOLVABLE_PHP_ENUMS` と同じ形。i3 の区分 3 の TS 版)

実測 (2026-08-24) の `unresolvable` は **3 件**で、すべて既存の見本である
(`fixtures/t22-circular.ts` の `X` / `Y`、`fixtures/t23-unresolved-import.ts` の `X`)。
どれも**わざと解決できない形にした見本**なので、申告に理由付きで載せる。

### 分岐のラベルの名前 (fail-closed の当て所を変える)

```ts
const switchSubjectName = (checker, expr, source): string | null => {
    const type = checker.getTypeAtLocation(expr);
    const alias = type.aliasSymbol?.name
        ?? (type.isUnion() ? type.types.map((t) => t.aliasSymbol?.name).find(isDefined) : undefined);
    if (alias !== undefined) return alias;
    // 受理する式の形: 識別子 / `this` / それらのプロパティ参照の連なり
    if (!isNameableExpression(expr)) return null;
    return expr.getText(source);
};
```

- 名前を決められなかった候補は `nameResolved: false` で**候補として残す**
- **規則 1 (完全一致) は名前を使わないのでそのまま効く**
- **規則 2 は判定できない**。そこで `reverse-sweep` 側で
  「`nameResolved` が偽 かつ 値集合が列挙と 1 値でも交差する かつ 完全一致ではない」組を
  **判定不能 (undecidable) として gate を赤くする**。交差が 0 なら規則 2 の対象になり得ないので
  黙って通す。これが「未解決を解決済みと同じ値へ混ぜない」の当て所である
- 実測: 現物ツリーで `nameResolved: false` になるのは
  `switch (errorName(error))` (呼び出し式) などで、
  **判定不能に落ちる組は 0 件**である (交差する列挙が無い)。
  見本で交差する形を作り、**故障注入 8** が到達可能になる

### 派生の除外 (3 集合一致 + 対応表以外の証人)

`object-keys` 形だけに適用する。**次をすべて満たすときだけ**外す。

1. 明示の型がある (型注釈 または `satisfies`)。型が解決できないなら外さない
2. その型に**文字列の添字シグネチャが無い**
   (`checker.getIndexInfoOfType(type, ts.IndexKind.String) === undefined`)
3. その型の**プロパティが 1 件以上あり、すべて必須**
   (`(symbol.flags & ts.SymbolFlags.Optional) === 0`)
4. **書かれたキー == 明示型の必須プロパティ** (集合として完全一致)。
   意味の診断を読まない以上、余剰キー・欠落キーを前提にしない
5. **証人がある** — その値集合と**同一の値集合**を持つ候補が、
   **`object-keys` 以外の形**の候補の中に 1 件以上ある

証人の資格を「派生除外の対象になり得ない形」に限るのは**循環の遮断**である。
任意の候補を証人にすると、同じキー集合を持つ対応表 A と B が互いを証人にして両方消える。
この形なら判定は**非派生の候補を種にした単調な到達判定**になり、
自己証人・相互証人・3 件の循環が構造的に起こらない。

**実装の順序 (2 パス)**: 第 1 パスで `object-keys` 以外の 3 形と、
`object-keys` のうち条件 1〜4 を満たすものを「保留」に分ける。
第 2 パスで保留のうち証人があるものを捨て、無いものを候補へ戻す。

実測: 保留 86 件のうち証人つきで外れたのは **40 件**、候補へ戻したのが 46 件。

### 構文の診断 (fail-closed)

母集団のファイル (仮想 `.svelte` を含む) について
`program.getSyntacticDiagnostics(source)` が 1 件でもあれば例外にする (現行と同じ)。

### PHPStan適合チェック

- [x] PHP の変更なし
- [x] 戻り値の型が明示されている / `readonly` と `ReadonlySet` を維持

### テスト計画

- [ ] **先に赤くする**: 見本に定数配列・対応表・分岐を足し、4 形すべてが拾えることを主張
- [ ] 各形の正例と負例 (非リテラルが混ざる / 数値 / TS の `enum` / 0 件 /
      `let` の配列は `const-array` にならない)
- [ ] **包みの負例**: `as const` / `satisfies Record<…>` / 丸括弧 / それらの入れ子を
      剥がして正しく読めること。`satisfies` の型を `getTypeFromTypeNode` で取っていること
      (値の型を使う実装に差し替えると赤くなる見本を置く)
- [ ] 入れ子の型別名 (関数の中) が拾えること
- [ ] `.svelte` の中の 4 形が拾えること
- [ ] **解析不能の三値**: 解決できない型別名が `unresolvable` に入り、
      候補にも非候補にもならないこと。`type X = any` は**正常な非候補**であること
- [ ] 派生の除外: `Record<Alias, string>` は外れ、`Record<string, string>` は残る
- [ ] 派生の負例セット: **型別名越しの `Record` / `Partial<Record<…>>` / union /
      intersection / `keyof` / 取り込んだ型 / `satisfies`** をそれぞれ見本に置く。
      とくに「書かれたキー ≠ 必須プロパティ」の見本 (欠落・余剰) が**外れない**こと
- [ ] **証人の負例 3 種**: 自己証人 / 2 件の相互証人 / 3 件の循環証人 —
      いずれも「外れずに候補として残る」ことを固定
- [ ] 分岐の名前: 型別名が取れる形 / 識別子とプロパティ参照の形 / 呼び出し式の形で
      `nameResolved` が期待どおりになること
- [ ] **故障注入 2 / 7 / 8** (S9 の表)

### リスク

- `object-keys` の候補が 172 件と多い。判定式が名前と値を見るので実際に鳴るのは 2 件だが、
  PHP の列挙が増えると鳴る組が増える。過剰検出の向きであり申告 1 行で吸収できる

---

## S5: 規則 2 の論理和

### 変更箇所

- `tests/js/support/enum-ts-sync/reverse-sweep.ts` (L44-99)

### 変更後の型

```ts
/** 適用した規則。申告の同一性に含める (規則が変わったら申告は stale になる)。 */
export type ReverseSweepRule = "1" | "2a" | "2b";

export interface UnregisteredMirrorCandidate {
    readonly rule: ReverseSweepRule;
    readonly php: ResolvedPhpEnum;
    readonly candidate: TsUnionCandidate;
    /** 鳴った理由 (どの規則・どの語・どの値の交差で鳴ったか)。 */
    readonly reason: string;
    readonly onlyInPhp: readonly string[];
    readonly onlyInTs: readonly string[];
}

/** 名前を決められないので規則 2 を判定できなかった組 (gate を赤くする)。 */
export interface UndecidableMirrorPair {
    readonly php: ResolvedPhpEnum;
    readonly candidate: TsUnionCandidate;
    readonly intersectionSize: number;
}

export interface ReverseSweepResult {
    readonly found: readonly UnregisteredMirrorCandidate[];
    readonly undecidable: readonly UndecidableMirrorPair[];
}
```

### 判定の順序 (排他)

1. 値集合が完全一致 → `"1"`
2. 交差が 0 なら何もしない
3. `nameResolved` が偽 → **undecidable** (gate が赤くなる)
4. **2a の名前対応**が成立 → `"2a"`
5. **2b の名前対応**が成立し **2b の交差条件**を満たす → `"2b"`
6. どれでもなければ鳴らさない

### 2a: 厳密な名前対応 + 1 値以上の交差 (現行を維持)

小文字化して比較 (**英数字以外は除去しない**)。一致 / `+s` / `+es` / `+values`。

### 2b: 語に分けた名前対応 + 両側から見て半分以上の交差 (新設)

**候補名の前処理**: 分岐のラベルの `switch:` は**両規則の共通の前処理で外す**。
区切りの集合にも `:` を含める。

**区切りの宣言** (AGENTS.md §共通規約 (e)):

- 語に割る文字は `_` `-` `.` `:` `$` と空白類
- **大文字の境界**でも割る (「小文字または数字 → 大文字」と「大文字の連なり → 大文字 + 小文字」)
- **数字の境界**でも割る (英字 ↔ 数字)
- 割った後、空の要素を捨て、すべて小文字化する

**正規化 (単数化)** — この順に 1 回だけ適用する:

1. 末尾 `ies` (長さ > 3) → `y`
2. 末尾が `ses` / `xes` / `zes` / `ches` / `shes` (長さ > 2) → 末尾 `es` を落とす
3. 末尾 `s` (長さ > 1、`ss` で終わらない、`us` で終わらない) → `s` を落とす

期待値をテストで固定する: `status → status` / `statuses → status` / `class → class` /
`classes → class` / `policy → policy` / `policies → policy` / `values → value` /
`kinds → kind`。**これ以上の語形変化は扱わない** (docblock に書く)。

**語袋**: 候補側 = 宣言名の語 ∪ ファイル名 (拡張子を除いた basename) の語。
PHP 側 = 列挙名の語。

**主要語**: 語列の**末尾の語**。候補側の主要語は**宣言名**の語列の末尾を使う
(ファイル名の語は主要語に使わない)。**宣言名から語が 1 つも取れなければ例外**にする
(静かに名前不一致へ混ぜない)。

**名前対応 (2b)**: 候補の主要語 == 列挙の主要語 かつ
`|候補の語袋 ∩ 列挙の語列| >= min(2, |列挙の語列|)`。

**交差条件 (2b)**: `|A ∩ B| >= ceil(|A| / 2)` かつ `|A ∩ B| >= ceil(|B| / 2)`。
どちらかが空なら鳴らさない。

### 負例の設計 (共通規約 (e) の 3 形)

**トークンの完全一致で判定していることを突く形にする** (素の部分文字列一致なら
一致してしまうが、トークン一致では一致しない形)。

| 形 | 見本 | なぜ不成立か |
|---|---|---|
| 接頭辞つき | `PrejobStatus` (対 `JobStatus`) | 語は `[prejob, status]`。`job` はトークンとして存在しない → 一致数 1 < 2 |
| 打ち消しつき | `JobNonstatus` (対 `JobStatus`) | 語は `[job, nonstatus]`。主要語が `nonstatus` ≠ `status` |
| 接尾辞つき | `JobStatusKind` (対 `JobStatus`) | 語は `[job, status, kind]`。主要語が `kind` ≠ `status` |

**`DraftJobStatus` / `NonJobStatus` は負例にしない** — これらは語として `job` と `status` を
持ち主要語も一致するので、本判定式では**成立するのが正しい**
(レビュー Round 1 の指摘。実際、`DashboardJobStatus` が同じ理由で成立している)。

### 診断文字列

- 規則 2a: `厳密名対応 (apierrorcode = apierrorcode) / 交差 6 値`
- 規則 2b: `語対応 [job+status] 主要語=status / 交差 2 値`

### テスト計画

- [ ] **先に赤くする**: 2b だけが拾う組 (2a では鳴らない) を主張 → 現行は鳴らないので赤
- [ ] 既存の E1〜E11 を**すべて残し**、`rule` の値を `"1"` / `"2a"` へ直す
- [ ] 単数化の期待値 8 組を固定する
- [ ] 2b の正例: 主要語一致 + 2 語一致 + 両側半分以上
- [ ] 2b の負例 3 形 (上表) と、主要語一致でも交差が片側半分未満 / 交差 0 / 空集合
- [ ] 2a と 2b の両方に該当し得る組で **2a が勝つ**こと
- [ ] `nameResolved` が偽で交差ありの組が `undecidable` に入ること (交差 0 なら入らない)
- [ ] 宣言名から語が取れない候補で例外になること
- [ ] **故障注入 5**: 論理和から 2b / 2a のどちらかを落とすと、その式専用の正例が消えて赤くなる
- [ ] `onlyInPhp` / `onlyInTs` が双方向の差分になっていること

---

## S6: 目録の受理範囲拡大

### 変更箇所

- `tests/js/support/enum-ts-sync/mirror-inventory.ts` (型と `validateMirrors`)

### 現行コード

```ts
const jsRoot = path.join(root, "resources", "js");
if (!row.ts.endsWith(".ts")) throw new EnumTsSyncError(where, `ts は .ts で終わること: ${row.ts}`);
if (!isUnder(tsAbs, jsRoot)) throw new EnumTsSyncError(where, `ts は resources/js/ 配下だけ: ${row.ts}`);
```

### 変更後コード（骨子）

```ts
/**
 * 登録できる TS の置き場。
 * - `resources/js/` … 画面側
 * - `packages/*/src/` … 付属のコマンドライン道具 (本 feature の境界は画面側に限らない)
 * `tests/js/` と `packages/*/tests/` は登録の置き場ではない (検査の見本を写しとして登録しない)。
 */
const tsRootsOf = (root: string): readonly string[] => [
    path.join(root, "resources", "js"),
    ...listPackageSrcRoots(root), // packages/*/src のうち実在する通常ディレクトリ。綴り順
];

const TS_EXTENSIONS = [".ts", ".svelte"] as const;

const matchedRoot = tsRootsOf(root).find((r) => isUnder(tsAbs, r));
if (matchedRoot === undefined) {
    throw new EnumTsSyncError(where, `ts は resources/js/ 配下か packages/*/src/ 配下だけです: ${row.ts}`);
}
// symlink の脱出検査は「字面で一致した根」に対して行う (別の根と比べると
// 拒否漏れ・誤拒否のどちらも起きる)。
if (!isUnder(fs.realpathSync(tsAbs), matchedRoot)) { … }
```

- `listPackageSrcRoots()` は綴り順に整列し、**通常ディレクトリだけ**を返す (診断を安定させる)
- `.svelte` を受理しても aicue に登録対象は現時点で 0 件である。
  正典 i6 が「`.svelte` の中の写しも登録の対象になる」と定めるため経路を用意し、
  見本で正例・負例を固定する

### 波及変更

- `enum-ts-sync.test.ts` の負の対照の期待文字列が変わる → **S10 と S12 が発火する**

### テスト計画

- [ ] **先に赤くする**: S10 の 2 つの負例 (新しい文面) を**先に**書く → 現行の文面と
      合わないので赤。そのうえで本施策を実装して緑にする (S10 と同じ赤→緑の単位)
- [ ] `packages/cli/src/api/schemas.ts` の登録行が通ること
- [ ] `.svelte` の登録行が通ること (見本の木で)
- [ ] `tests/js/setup.ts` / `packages/cli/tests/…` / `packages/cli/vitest.config.ts` は拒否
- [ ] symlink の負例を根ごとに: `packages/cli/src` の中から外へ抜ける symlink /
      `packages/cli/src` 自体が symlink
- [ ] 既存の負の対照 (絶対パス / 逆斜線 / `..` / 二重登録 / note 空) は**すべて残す**

---

## S7: 前向きの検査を 2 形と `.svelte` へ

### 変更箇所

- `tests/js/support/enum-ts-sync/ts-value-sets.ts`

### 決めたこと

前向きの検査 (`readTsUnionValues`) が受理する形を **2 つ**にする。

1. **型別名の宣言** (現行)
2. **`const` 束縛の配列** (`const X = [...] as const` / `satisfies` / 素の配列)

理由: 逆走査が実ドリフトを見つけた 2 件はどちらも**定数の配列**であり、
登録できる形が型別名だけだと**直す道が「型別名を足す」しかなくなり、
申告が実質の許可一覧に膨らむ** (i11 が禁じる形)。定数の配列は
`checker.getTypeAtLocation(name)` から要素のリテラル型として読めるので、
抽出器を 2 本持つことにはならない (`ts-candidates.ts` と同じ読み方を共有する)。

**対応表のキーと分岐のラベルは引き続き登録できない**。写しとして扱うなら
型別名か定数の配列へ切り出す — これを失敗メッセージと docblock に書く。

### `.svelte` の解決

```ts
const sourceOf = (programs: MirrorPrograms, tsFile: string): ts.SourceFile => {
    // `.svelte` は仮想単位が 1 本だけある。無ければ「仮想化されていない」で落とす
    // (「型別名が見つからない」と混ぜない)。
    …
};
```

- 「その名前の宣言がちょうど 1 つ」の検査は現行どおり。
  module と実体に同名があれば 2 件になって落ちる
- 失敗メッセージの `where` は**元の `.svelte` のパス**を出す (仮想パスを見せない)

### テスト計画

- [ ] **先に赤くする**: `const X = [...] as const` を登録して値集合が読めることを主張
- [ ] `let` の配列 / 非リテラルが混ざる配列 / 空配列は受理しないこと
- [ ] `.svelte` の中の型別名が読めること
- [ ] `.svelte` が仮想化されていないときは「仮想単位が無い」で落ちること
      (「型別名が見つからない」と別のメッセージ)
- [ ] module 宣言を実体側の型別名が参照する見本で値集合が読めること (S2 の修正後)
- [ ] module と実体に同名の型別名を置いた見本で「同名の宣言が 2 件」で落ちること
- [ ] 既存の受理・拒否 (T01〜T25) は**すべて残す**

---

## S8: 逆走査 gate の再整備

### 変更箇所

- `tests/js/architecture/enum-ts-sync-discovery.test.ts`

### docblock の書き換え (i15)

現行の次の宣言は事実でなくなるので書き換える:

- 「`resources/js/` 配下の…型別名を全数走査し」
- 「`.svelte` の中の宣言・定数配列・switch の case ラベルは走査しない」
- 「名前対応は『一致 / +s / +es / +values』の厳密な形だけを見る」

新しい**保証しないもの**:

- 版管理外のファイルは見ない。`.js` / `.mjs` / `.cjs` は母集団に入れない
- `.svelte` は script の中だけを見る (目印の中・制御構文の中・スタイルは見ない)。
  ただし**ファイル全体が `parse` できることは前提**である
- 「すべての要素が読める」形だけを候補にする (1 つでも読めない要素があれば候補にしない)
- 派生として外した対応表は、**証人 (対応表以外の候補) がある場合だけ**外れる
- 分岐のラベルと対応表のキーは**登録できない**。写しなら型別名か定数の配列へ切り出す
- パッケージの型は**そのパッケージ自身の tsconfig** で解決する
  (ルートの設定で解決するわけではない)
- 除外根 (`fixtures/candidates-broken`) の中は見ない。
  `fixtures/` の残りは**見る** (見本を書き換えると本番の候補集合も動く)

### 新設する検査

```ts
describe("逆走査の母集団 (版管理下の全数・唯一の除外)", () => {
    it("除外根の件数が pin と一致する", …);
    it("除外根の体裁 (配下・実在・重複無し・理由 30 文字以上) が守られている", …);
    it("除外根の配下は 0 件でなく、全ファイルが実際に本番と同じ入口で落ちる", () => {
        // `.ts` は TS の構文診断、`.svelte` は toVirtualUnit() の失敗で見る
        // (拡張子ごとに本番と同じ入口を使う)。
        // ここが「除外根へ正常なファイルを置いて母集団から静かに消す」経路を塞ぐ。
    });
    it("母集団が空でない (.ts と .svelte のどちらも)", …);
    it("母集団の全件がちょうど 1 本の program に載っている", …);
});

describe("TS 側の解析不能 (既定拒否の受け皿)", () => {
    it("unresolvable はすべて KNOWN_UNRESOLVABLE_TS_DECLARATIONS に登録されている", …);
    it("登録は実在・重複無し・reason が 30 文字以上・件数が pin と一致する", …);
    it("登録先が stale になっていない (今も解析不能のままである)", …);
});

describe("逆走査の判定不能", () => {
    it("判定不能な組は 0 件である (名前を決められないのに列挙と交差する分岐は無い)", …);
});
```

### 申告 (`REVERSE_SWEEP_EXEMPTIONS`) の再整備

現行 1 件に **7 件を足して合計 8 件**にする。`rule` は `"1" | "2a" | "2b"`。

| # | php | file | declaration | rule | 理由の要点 |
|---|---|---|---|---|---|
| 1 | `app/Enums/Manual/TakeStatus.php` | `resources/js/types/manual.ts` | `SelectableTakeStatus` | `"1"` | 既存。部分集合の意図 |
| 2 | `app/Enums/Manual/CutType.php` | `…/ScenarioEditor.svelte` | `DragOwner` | `"1"` | ドラッグの所有者という**別概念**で値がたまたま一致する。統合しない (思考原則 4) |
| 3 | `app/Enums/Notification/NotificationType.php` | `…/NotificationListItem.svelte` | `switch:notification.type` | `"1"` | 絵柄を選ぶ分岐。値が増えると既定の枝 (ベルの絵柄) に落ち、新種の通知が汎用の絵柄で出る (操作は詰まらない)。期待動作は「新種を足すときに絵柄も足す」。**値が増えれば完全一致が崩れて申告が stale になり赤くなる** (移り先が 2a か 2b かは判定対象の型名を解決できるかに依る) |
| 4 | `app/Enums/ApiKeyAbility.php` | `…/ApiKeys/Index.svelte` | `ABILITY_LABELS` | `"1"` | 表示ラベル表。未知の値は素の文字列で表示する退避 (`?? ability`) があるので取りこぼしが画面を壊さない |
| 5 | `app/Enums/OAuth/OAuthClientKind.php` | `…/ApiKeys/Sessions.svelte` | `CLIENT_KIND_LABELS` | `"1"` | 同上 (`?? kind`) |
| 6 | `app/Enums/EnterpriseSso/OidcConnectionStatus.php` | `tests/js/.../oidc-connection.test.ts` | `ALL_STATUSES` | `"1"` | 検査が並べた全値。写しではなく検査の入力である |
| 7 | `app/Enums/Manual/JobStatus.php` | `resources/js/types/dashboard.ts` | `DashboardJobStatus` | `"2b"` | 進行中だけを表す**意図した真部分集合**。終端の状態はダッシュボードに出ない |
| 8 | `app/Enums/ApiErrorCode.php` | `packages/cli/src/api/schemas.ts` | `ApiErrorCode` | `"2a"` | サーバの符号と道具固有の符号の**合併**。サーバ側の写しは `API_ERROR_CODES` として登録済みで、合併型は写しではない (S11) |

→ `EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT = 8`。

**この 8 件は設計時の見積りである**。実装時に走らせて実測と突き合わせ、
食い違ったら**申告を足すのではなく差分の理由を確認**してから決める。

### 解析不能の申告 (`KNOWN_UNRESOLVABLE_TS_DECLARATIONS`)

| file | line | name | 理由の要点 |
|---|---|---|---|
| `tests/js/support/enum-ts-sync/fixtures/t22-circular.ts` | 1 | `X` | 型別名が自分自身を経由して循環する見本。型検査器が解決できないことを固定するために置いてある |
| 同上 | 2 | `Y` | 同上 (循環の相方) |
| `tests/js/support/enum-ts-sync/fixtures/t23-unresolved-import.ts` | 3 | `X` | 実在しないモジュールからの取り込みに依存する見本。解決できないことを固定するために置いてある |

→ `EXPECTED_UNRESOLVABLE_TS_COUNT = 3`。行番号は変わりやすいので**同一性は
`file` と `name` だけ**で持ち、行はメッセージにだけ使う。

### PHP 側の分類の更新

- **2 件を「対象外」から「登録済み」へ移す** (道具パッケージが母集団に入ったため、
  「画面へは出ない」という理由が事実でなくなる):
  - `app/Enums/ApiErrorCode.php` → `ENUM_TS_MIRRORS` へ
  - `app/Enums/OAuth/CliOAuthScope.php` → `ENUM_TS_MIRRORS` へ
  → `EXPECTED_EXEMPTION_COUNT` 95 → **93**、`EXPECTED_MIRROR_COUNT` 29 → **31**
- **2 件の理由を事実に合わせて書き直す** (分類は「対象外」のまま。件数は変わらない):
  - `app/Enums/ApiKeyAbility.php` → 「API キー権限 (read/write)。画面はチェックボックスの
    選択状態で操作し、表示ラベル表は未知の値を素の文字列へ退避するため値域の写しを要さない」
  - `app/Enums/OAuth/OAuthClientKind.php` → 「OAuth クライアント種別。認可判定の内部語彙で、
    画面の表示ラベル表は未知の値を素の文字列へ退避するため値域の写しを要さない」

### 失敗メッセージ (i13)

```
未登録のミラー候補が見つかりました。正本は PHP 側です。
規則2a app/Enums/ApiErrorCode.php:12 (ApiErrorCode)
     ⇔ packages/cli/src/api/schemas.ts:310::ApiErrorCode (literal-union)
     厳密名対応 (apierrorcode = apierrorcode) / 交差 6 値
     PHP にだけある値: actor_not_resolvable, idempotency_in_progress, …
     TS にだけある値: quota_exceeded, rate_limit_exceeded, …
     直し方: 写しなら ENUM_TS_MIRRORS へ 1 行足して EXPECTED_MIRROR_COUNT を 1 増やす
             (登録できるのは型別名か const の配列。対応表のキーと分岐のラベルは
              いったん型別名か const の配列へ切り出す)。
             写しでないなら REVERSE_SWEEP_EXEMPTIONS へ理由 30 文字以上で登録し
             EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT を直す。
```

### 波及変更: `ResolvedPhpEnum.line` の追加

PHP 側の行を出すために `ResolvedPhpEnum` に `line` を足す
(`detectEnumHeaders` の `offset` から改行を数える。無害化した写しは長さが元と同じ)。
**この型を作っている場所をすべて直す**:

- `tests/js/support/enum-ts-sync/php-enum-catalog.ts` の `classifyPhpFile` / `buildPhpEnumCatalog`
- `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts` の `phpEnum()` ヘルパと
  D1〜D18 / E1〜E11 の手書きオブジェクト
- `tests/js/architecture/enum-ts-sync-discovery.test.ts` の合成入力

### テスト計画

- [ ] **先に赤くする**: 新しい母集団・4 形・論理和で走らせ、申告が現行 1 件のままだと
      **未登録候補が 9 件出て赤くなる**ことを確認する (S11 の是正前の実測 10 件のうち
      既存申告 1 件を除いた数)
- [ ] 申告と登録を整備すると緑になること (**S11 の完了が前提**。下の実装の順序を参照)
- [ ] 申告の stale 検査: 申告 1 件の `rule` をわざと変えると赤くなる
      (**規則が移ると申告が stale になる**負例そのもの)
- [ ] **故障注入 6**: 生死判定を「免除適用後」に変えると、
      自分自身を根拠にする申告の見本が通ってしまい負の対照が赤くなる
- [ ] メッセージに PHP 側の行と TS 側の行が両方出ること (文字列の照合)

---

## S9: 検出器の自己検査 (負例と故障注入)

### 変更箇所

- `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts`
- 追跡する見本の追加:
  - `tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts` (4 形へ拡張)
  - `tests/js/support/enum-ts-sync/fixtures/candidates/derived.ts` (派生の 7 パターン)
  - `tests/js/support/enum-ts-sync/fixtures/candidates/witness-cycle.ts` (自己 / 相互 / 3 件循環)
  - `tests/js/support/enum-ts-sync/fixtures/svelte/Sample.svelte` (module + 実体。**正常な形だけ**)
  - `tests/js/support/enum-ts-sync/fixtures/svelte/Other.svelte` (同名宣言の干渉を見る相方)

**不正な入力は追跡ファイルにしない**。構文の壊れた `.svelte`・同じ文脈の script が 2 つ・
受理しない属性・呼び出し式の分岐は、**テストの中の文字列**として
`toVirtualUnit()` / `createFixtureProgram()` に渡す。追跡ファイルにすると
母集団に入って**本番の gate が恒久的に赤**になる (レビュー Round 1 の Critical)。

**見本の値の綴り**: `fixtures/` は母集団に入るので、見本の値は
**現物の列挙と交差しない綴り** (`"a"` `"b"` `"zzz-sample-1"` など) にする。
この約束を `fixtures/` の見本ファイルの docblock へ書く。

**`.svelte` の見本の置き場**: `resources/js` の外なので `pnpm lint` /
`svelte-no-undef-gate` の対象にならない (どちらも `resources/js` を見る)。

### 既存テストの扱い (禁止事項 3)

- D1〜D18 (PHP 側の分類) は**そのまま残す** (`line` の追加に伴う機械的な更新のみ)
- E1〜E11 (突き合わせ純関数) は**残す**。`rule` の値だけ `1 → "1"` / `2 → "2a"` へ直す
- 「走査根の配下でないファイルは対象にしない」は母集団の考え方が変わるので
  「**除外根の配下は対象にしない**」へ**意味を更新**する (削除ではない)。
  置き換えたことを対応マトリクスと commit メッセージに残す
- 「走査した非宣言ファイルの集合は、ファイルシステムを直接歩いた集合と一致する」は
  「**母集団の全件がちょうど 1 本の program に載っている**」へ意味を更新する
  (独立実装で突き合わせるという性質は維持する)

### 故障注入の一覧 (i12。8 件)

**純関数へ分けて直接検査する**。本番 API に任意の差し替え口を増やさない。

| # | 注入する対象 (純関数) | 注入の仕方 | 赤くなるテスト |
|---|---|---|---|
| 1 | `validateExcludedRoots` / 除外根の一覧 | 引数で空の一覧・配下でない根を渡す | 「除外根の件数が pin と一致する」「体裁」 |
| 1' | 除外根の自己点検 | 除外根に**正常な** `.ts` を置いた見本の木を一時ディレクトリに作る | 「配下は全件が本番と同じ入口で落ちる」 |
| 2 | 派生の判定 (`isDerivedObjectKeys`) | 常に真を返す関数を渡して候補集合を作る | 「証人の無い対応表が候補に残る」 |
| 3 | 母集団の列挙 (`parseTrackedOutput`) | 空文字列を渡す | 「母集団が空でない」 |
| 4 | `toVirtualUnit` | `export {};` を付けない版 / 文脈ごとに割る版を見本へ適用 | 「別コンポーネントの型が漏れない」「module → 実体の参照が解決する」 |
| 5 | `findUnregisteredMirrorCandidates` の規則集合 | 2a / 2b のどちらかを外した述語を渡す | 「その式専用の正例が鳴る」 |
| 6 | 申告の生死判定 (`auditReverseSweepExemptions`) | 免除適用後の候補集合を渡す | 「自己根拠の申告が stale になる」 |
| 7 | 証人の索引 (`buildWitnessIndex`) | すべての候補から作る版を渡す | 「相互証人・循環証人の見本が外れない」 |
| 8 | `switchSubjectName` | 呼び出し式の分岐を含む見本 (値が列挙と交差する) を渡す | 「判定不能として赤くなる」 |

このために次を**引数を取る純関数**として切り出す:
`isDerivedObjectKeys` / `buildWitnessIndex` / `switchSubjectName` /
`auditReverseSweepExemptions` / `parseTrackedOutput` / `toVirtualUnit`。

---

## S10: 前向き gate の負の対照を新しい受理範囲へ

### 変更箇所

- `tests/js/architecture/enum-ts-sync.test.ts`

### 変更内容

1. 負の対照 1 件の期待文字列を新しい文面へ直し、道具パッケージの負例を 1 件足す

```ts
it("登録できる置き場の外の ts は拒否する", () => {
    expect(() => validateMirrors([{ ...valid, ts: "tests/js/setup.ts" }])).toThrow(
        "resources/js/ 配下か packages/*/src/ 配下だけ",
    );
});

it("道具パッケージでも src の外は拒否する", () => {
    expect(() => validateMirrors([{ ...valid, ts: "packages/cli/vitest.config.ts" }])).toThrow(
        "resources/js/ 配下か packages/*/src/ 配下だけ",
    );
});
```

2. `createMirrorProgram([...])` の呼び出しを `createMirrorPrograms()` へ揃える (S3)

**既存のケースは期待文言以外を変えない**。診断文の正しさが先で、台帳の手当ては帰結である
(決着 3)。この変更が `docs/template-fingerprints.json` のキーに触るため **S12 が発火する**。

---

## S11: 実ドリフト 2 件の是正

逆走査が見つけた**実在のドリフト 2 件**を、申告で黙らせずに直す。
どちらも道具パッケージ (`packages/cli`) にあり、他アプリから持ち込まれた符号が
そのまま残っていた形である。

### S11-a: API のエラー符号

#### 変更箇所

- `packages/cli/src/api/schemas.ts` (L283-310) / `packages/cli/src/api/client.ts` (L200-235)

#### 食い違い

サーバ `app/Enums/ApiErrorCode.php` の 11 値:
`unauthenticated` / `forbidden` / `insufficient_ability` / `actor_not_resolvable` /
`not_found` / `validation_failed` / `rate_limited` / `idempotency_conflict` /
`idempotency_in_progress` / `idempotency_indeterminate` / `internal_server_error`

道具側は `rate_limit_exceeded` を持つがサーバは `rate_limited`。
サーバの 4 値 (`insufficient_ability` / `actor_not_resolvable` /
`idempotency_in_progress` / `idempotency_indeterminate`) が道具側に無い。
当該ファイルの docblock 自身が「Mirrors `app/Enums/ApiErrorCode.php`」と書いている。

#### 変更後コード

```ts
/**
 * サーバの `App\Enums\ApiErrorCode` の写し。
 * 値集合の一致は `tests/js/architecture/enum-ts-sync.test.ts` が機械で固定する。
 * **ここに道具固有の符号を混ぜない** — 混ぜると同期の検査が成立しなくなる。
 */
export const API_ERROR_CODES = [
    "unauthenticated", "forbidden", "insufficient_ability", "actor_not_resolvable",
    "not_found", "validation_failed", "rate_limited", "idempotency_conflict",
    "idempotency_in_progress", "idempotency_indeterminate", "internal_server_error",
] as const;

/**
 * サーバの列挙には無く、封筒の形だけを共有する面 (課金・入力の無害化・撮影面の判定) が返す符号。
 * **道具の内部で作る符号ではない** — 発生源はサーバ側の個別の面である。
 */
export const NON_CANONICAL_API_ERROR_CODES = [
    "quota_exceeded", "payload_sanitization_failed", "site_not_cli_capture", "use_audits_submit",
] as const;

/** 道具が受け取り得る符号の全体。未知の符号は拒否せず状態番号へ退避する (既存の契約)。 */
export type ApiErrorCode =
    | (typeof API_ERROR_CODES)[number]
    | (typeof NON_CANONICAL_API_ERROR_CODES)[number];
```

- `client.ts` の `case "rate_limit_exceeded":` を **`case "rate_limited":`** へ差し替える。
  旧綴りは残さない (後方互換の並走を残さない)
- `client.ts` の docblock の符号の並びも新しい分類へ直す

#### 公開契約の確認 (レビュー Round 1 の Warning)

- `packages/cli/package.json` の `main` は `./dist/index.js`、`types` は `./dist/index.d.ts`
- `src/index.ts` が書き出すのは `getCliVersion()` **だけ**で、`api/schemas` を再輸出しない
- したがって `API_ERROR_CODES` は**パッケージの公開面ではない** (深い取り込みでしか届かない)
- パッケージ名は `@app/cli` (作業空間の中だけで解決する名前。`linkWorkspacePackages: true`) で、
  登録所へ公開する設定 (`publishConfig` 等) を持たない

→ 外部の利用者への影響は無いと判断する。**この根拠を設計に残す**
(「後方互換を残さない」は外部影響の確認を省く根拠にはならない)。

### S11-b: CLI OAuth のスコープ

#### 変更箇所

- `packages/cli/src/oauth/login.ts` (L45-56)

#### 食い違い

サーバ `app/Enums/OAuth/CliOAuthScope.php` の 4 値:
`cli:use` / `read` / `write` / `session.revoke`。
`app/Providers/McpPassportServiceProvider.php` が登録するスコープもこの 4 つである。

道具側の `DEFAULT_CLI_SCOPES` は 6 値で、**`evaluations:run` と `pages:bulk` が余分**。
サーバが登録していないスコープを要求しているので、認可要求が拒否されるか黙って落ちる。

#### 変更後コード

```ts
/**
 * サーバの `App\Enums\OAuth\CliOAuthScope` の写し (既定で要求するスコープ集合)。
 * 値集合の一致は `tests/js/architecture/enum-ts-sync.test.ts` が機械で固定する。
 * サーバが `McpPassportServiceProvider` で登録していないスコープを足さない
 * (要求が拒否される)。
 */
export const DEFAULT_CLI_SCOPES = ["cli:use", "read", "write", "session.revoke"] as const;
```

### 目録への登録

`ENUM_TS_MIRRORS` へ 2 行足し、`EXPECTED_MIRROR_COUNT` を 29 → **31** にする。
併せて `PHP_ENUM_EXEMPTIONS` から同じ 2 件を外す (95 → **93**)。

```ts
{
    php: "app/Enums/ApiErrorCode.php",
    ts: "packages/cli/src/api/schemas.ts",
    declaration: "API_ERROR_CODES",
    note: "付属のコマンドライン道具が応答の符号で失敗の種類を分ける (rate-limit / conflict / auth)",
},
{
    php: "app/Enums/OAuth/CliOAuthScope.php",
    ts: "packages/cli/src/oauth/login.ts",
    declaration: "DEFAULT_CLI_SCOPES",
    note: "道具がログイン時に要求するスコープ集合。サーバが登録していない値を要求すると認可が通らない",
},
```

合併型 `ApiErrorCode` は規則 2a で鳴り続けるので**申告 1 件**で逃がす (S8 の #8)。
`NON_CANONICAL_API_ERROR_CODES` はサーバの列挙と 1 値も交差しないので鳴らない。

### テスト計画

- [ ] **先に赤くする (S11-a)**: `packages/cli/tests/` に
      「`rate_limited` + 429 → `rate-limit`」の検査を足す → 現行は `rate_limit_exceeded` しか
      見ないので**状態番号への退避**で通ってしまう。したがって
      **`rate_limited` + 非 429 (例: 200 系でない任意の状態)** の組で符号による分類を突く
      形にして赤を作る
- [ ] 道具側の 3 系統を固定する:
      - `rate_limited` + 429 → `rate-limit`
      - 旧 `rate_limit_exceeded` + 429 → 未知の符号として状態番号へ退避し `rate-limit`
      - 未知の符号 + 429 でない状態 → その状態番号に対応する分類
      - `insufficient_ability` / `actor_not_resolvable` / idempotency 系の期待分類
      - 道具固有の符号 (`quota_exceeded`) → `quota`
- [ ] **S11-b の赤**: `DEFAULT_CLI_SCOPES` がサーバの登録スコープと一致することを
      検査に書く (道具側の単体で持てる) → 現行は 6 値なので赤
- [ ] 目録へ 2 行足すと `enum-ts-sync.test.ts` の値集合一致が緑になること
- [ ] `pnpm typecheck:packages` / `pnpm test:packages` / `pnpm build:packages` が緑

### リスク

- `rate_limit_exceeded` を落とすと、**古いサーバ**がその綴りを返す環境で符号による分類が
  効かなくなる。ただし 429 の状態番号への退避が残るので失敗の種類は同じ `rate-limit` になる
  (上のテストで固定する)。この判断を `client.ts` の docblock に残す
- `evaluations:run` / `pages:bulk` を落とすと、それらのスコープを本当に必要とする面が
  将来できたときに要求が足りなくなる。**現時点のサーバはそのスコープを登録していない**ので
  今は要求しても意味が無い。足すときはサーバの列挙と同時に足す (gate が強制する)

---

## S12: 乖離台帳の手当て

### 変更箇所

- `docs/template-divergence.md` (**D50 を新設**)
- `tests/Support/TemplateDivergence/adoption-debt.tsv` (`tests/js/architecture/enum-ts-sync.test.ts` の行を削除)
- `tests/Support/TemplateDivergence/LedgerPins.php`
  (`DIVERGENCE_ENTRY_COUNT` 46 → **47** / `ADOPTION_DEBT_COUNT` 148 → **147**)

### 判断の根拠 (app-design 3-0 段)

- `tests/js/architecture/enum-ts-sync.test.ts` は `docs/template-fingerprints.json` のキーであり、
  かつ `adoption-debt.tsv` に採用時ハッシュ付きで凍結されている
- S10 でこのファイルを変更するので、**「変更したまま債務に残す」は選べない**
- 3 択のうち **(3) 意図的逸脱として登録を書き債務から削る** を採る。
  (1) 採用時の姿へ戻すのは S10 の診断文の訂正を捨てることになり、
  (2) テンプレートへ同期するのはテンプレート側が正典 v2 のままなので成立しない

### D50 の中身 (要点)

- **逸脱**: 前向きの同期検査を、テンプレートの「単一ファイル・構文木のみ」ではなく、
  **共有の走査器 + 型情報 (Program + TypeChecker)** で持ち、目録を逆走査の gate と共有する
- **理由**: 正典 v3 の i4 / i5。構文木だけでは別名参照・添字アクセス・
  閉じたテンプレート文字列を読めず、その写しを登録できないため実装側に書き方の変更を強いる
- **揃え続ける不変条件**:
  - 目録 (`ENUM_TS_MIRRORS`) が前向きの検査と逆走査の**単一の出典**であること
  - 値集合の抽出器を 2 本持たないこと
  - 受理範囲の外は空集合でなく例外にすること
  - **正本のレーンは `pnpm test` であり `composer test` ではない** (レーンの非対称を台帳から追える形にする)
- **対象パス**: `tests/js/architecture/enum-ts-sync.test.ts`
- 書式 (登録メタ表の 9 行・状態の値域・対象パスの実在と重複) は
  `TemplateDivergenceLedgerFormatTest` が機械で強制する。**書式の正本は同ファイルの規約節**

### 件数の扱い

46 → 47 / 148 → 147 は**設計時点の値**である。実装の開始時と main へ入れる直前に
**現物から数え直す** (他の TODO が同じ pin を触る)。

### `tsconfig.json` は変えない

`packages/cli` は自前の tsconfig で program を作る (S3)。ルートの `include` を広げると
`pnpm typecheck` の対象まで動き、債務 pin にも触れる。

### テスト計画

- [ ] **先に赤くする**: S10 の変更を入れた時点で `TemplateDivergenceFingerprintTest` が
      `mutatedDebtPaths` で赤くなることを確認する
- [ ] D50 を書き債務の行を削り pin を直すと緑になること
- [ ] `TemplateDivergenceLedgerFormatTest` (件数の 3 点一致) が緑
- [ ] `composer test` 全体が緑

---

## S13: 文書の更新

### 変更箇所

- `AGENTS.md` ドメイン固有規約 **19**
- `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期

### 変更内容

`AGENTS.md` 19 で直すのは 3 点:

- **登録**が受理する形を「型別名の宣言**または `const` の配列**」に直す
- **逆走査**の走査範囲を「版管理下の `*.ts` と `*.svelte` の全数
  (検出器自身の構文破壊見本を除く)」に直し、拾う形が 4 種であることを足す
- 登録できる TS の置き場が `resources/js/` と `packages/*/src/` であることを足す

**正典 v3 の条文を転載しない**。書くのは aicue 固有の受理範囲・除外集合・登録の手順だけで、
正典は版 (家系の機能台帳 `enum-ts-sync-gate` の v3) で指す。

**書き分け** (2 か所に同じ文を置かない):

- **走査器・gate の docblock** … 実装に密着した短い保証範囲 (何を見て何を見ないか)
- **`docs/architecture.md`** … 理由と全体像、および**保証しないものの正本**。
  docblock からここへ相互参照する
- **`AGENTS.md`** … 登録の手順と受理範囲だけ。保証しないものは `docs/architecture.md` を指す

### テスト計画

- [ ] `docs/architecture.md` の節が実在し、`AGENTS.md` から参照されていること (人手)
- [ ] `pnpm test` / `composer test` が緑

---

## 実装の順序 (テストファースト)

**赤→緑の単位**を明示する。`ENUM_TS_SYNC` の統合 gate が緑になるのは**最後**である。

| 段 | 先に赤くするもの | 緑にする実装 | この段で gate 全体は緑か |
|---|---|---|---|
| 1 | `population.ts` の単体テスト (モジュールが無い) | S1 | いいえ (未着手) |
| 2 | `svelte-source.ts` の単体テスト (行・列 / `export {};` / module→実体) | S2 | いいえ |
| 3 | `createMirrorPrograms()` が母集団を過不足なく載せる主張 | S3 | いいえ |
| 4 | 4 形・派生の証人つき除外・解析不能の三値の単体テスト | S4 | いいえ |
| 5 | 2b 専用の正例 / (e) の 3 形の負例 / 単数化の期待値 | S5 | いいえ |
| 6 | **逆走査 gate が未登録候補 9 件で赤くなる** | S8 の申告整備 (7 件を追加) | **いいえ** — `ApiErrorCode` 系が残る |
| 7 | S10 の 2 つの負例 (新しい文面) が現行の文面と合わず赤くなる | S6 + S10 | いいえ |
| 8 | `.svelte` と `const` の配列を登録した行が読めない | S7 | いいえ |
| 9 | 道具側の失敗分類 / スコープ一致の検査が赤くなる | S11 (是正 + 目録へ 2 行 + 申告 1 件) | **はい** (ここで初めて逆走査 gate が緑) |
| 10 | `TemplateDivergenceFingerprintTest` が `mutatedDebtPaths` で赤くなる | S12 | はい |
| 11 | 故障注入 8 件 | S9 | はい |
| 12 | — | S13 (文書) | はい |

**段 6 の実測との突合**: 設計時の実測は鳴った組 10 件。既存申告 1 件を除いた 9 件が
段 6 で出る。うち 2 件 (`ApiErrorCode` の合併型と `API_ERROR_CODES`) と
1 件 (`DEFAULT_CLI_SCOPES`) は段 9 で登録・是正・申告により解消する。
残り 6 件が段 6 の申告になる (`SelectableTakeStatus` は既存申告なので数に入らない)。
**この内訳が実測とずれたら、申告を足す前に原因を確認する**。

## 後方互換・migration の扱い

- **DB の migration は無い**
- **後方互換の並走を残さない** (AGENTS.md 思考原則 3):
  - `createMirrorProgram(tsFiles)` は**廃止**し `createMirrorPrograms()` に置き換える
    (2 つの program の作り方を残さない)
  - `collectTsUnionCandidates` の `jsRoot` 引数 (走査根を差し替える負のコントロール専用) は
    **廃止**し、除外根の差し替えに置き換える
  - `reverse-sweep.ts` の `rule: 1 | 2` は `"1" | "2a" | "2b"` へ**置き換える**
  - `client.ts` の `rate_limit_exceeded` の分岐は**差し替える** (両方を受ける形にしない)
  - `DEFAULT_CLI_SCOPES` の 2 値は**削除する** (「当面残す」をしない)

## docs/template-divergence.md の登録/更新/削除の要否

| 対象 | 指紋台帳のキーか | 採用時債務か | 判断 |
|---|---|---|---|
| `tests/js/architecture/enum-ts-sync.test.ts` | **在る** | **在る** | S10 で変更するので **D50 を新設し債務から削る** (S12) |
| `tsconfig.json` | 在る | 在る | **変更しない** (S3 でパッケージごとの program を作る) |
| `tests/js/support/enum-ts-sync/*.ts` | 無い | 無い | 登録の義務なし (aicue 固有の上積み) |
| `tests/js/architecture/enum-ts-sync-discovery*.test.ts` | 無い | 無い | 同上 |
| `packages/cli/**` | 無い | 無い | 同上 |
| `AGENTS.md` / `docs/architecture.md` | 無い | 無い | 同上 |

削除する登録は無い。**実装時に `docs/template-fingerprints.json` のキーを数え直して
確認する** (他の TODO が触っている可能性がある)。

## 検証コマンド (全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 走査器・gate・目録・道具の型・乖離台帳を**同じ変更**で揃える必要がある (段 6 の赤は段 9 まで解けず、S10 の変更は S12 の手当てと不可分)。段階的に main へ入れると gate が赤いまま並走する期間ができる |
| 競合リスク | `docs/template-divergence.md` と `LedgerPins.php` は他の TODO も触る。件数 pin が衝突しやすいので、着手時と main へ入れる直前に現物から数え直す。`AGENTS.md` も同様 |

## 再測定した実測ログ

# 実測ログ (設計時 2026-08-24)

## probe2.ts — 詳細設計レビュー Round 1 を反映した最終形の判定式

`.svelte` は 1 ファイル 1 仮想 TS + 末尾 `export {};` / パッケージごとに自前 tsconfig で program /
`as const`・`satisfies`・丸括弧の剥がし / 定数配列は const 束縛 / 派生除外は 3 集合一致 + 対応表以外の証人 /
単数化の規則を修正 / 型が解決できない候補は解析不能として数える。

```
population .ts=377 (tracked .ts incl .d.ts=378) .svelte=130
php resolved=123 unresolvable=3
programs=<root>,packages/cli build ms=4695
scanned files=507 unresolvable=3
  [unresolvable] tests/js/support/enum-ts-sync/fixtures/t22-circular.ts:1::X (型別名が解決できない)
  [unresolvable] tests/js/support/enum-ts-sync/fixtures/t22-circular.ts:2::Y (型別名が解決できない)
  [unresolvable] tests/js/support/enum-ts-sync/fixtures/t23-unresolved-import.ts:3::X (型別名が解決できない)
derived pending=86 excluded(witnessed)=40 kept=46
candidates total=345 {"literal-union":106,"object-keys":172,"const-array":54,"switch-cases":13}
undecidable(名前解決不能かつ交差あり)=0
hits total=10 {"1":6,"2b":3,"2a":1}
  [規則1] app/Enums/Manual/CutType.php <-> resources/js/components/features/manual/ScenarioEditor.svelte:401::DragOwner (literal-union) 完全一致
  [規則1] app/Enums/Notification/NotificationType.php <-> resources/js/components/features/notifications/NotificationListItem.svelte:67::switch:notification.type (switch-cases) 完全一致
  [規則1] app/Enums/ApiKeyAbility.php <-> resources/js/pages/Organizations/ApiKeys/Index.svelte:61::ABILITY_LABELS (object-keys) 完全一致
  [規則1] app/Enums/OAuth/OAuthClientKind.php <-> resources/js/pages/Organizations/ApiKeys/Sessions.svelte:41::CLIENT_KIND_LABELS (object-keys) 完全一致
  [規則1] app/Enums/Manual/TakeStatus.php <-> resources/js/types/manual.ts:409::SelectableTakeStatus (literal-union) 完全一致
  [規則1] app/Enums/EnterpriseSso/OidcConnectionStatus.php <-> tests/js/components/features/sso/oidc-connection.test.ts:17::ALL_STATUSES (const-array) 完全一致
  [規則2a] app/Enums/ApiErrorCode.php <-> packages/cli/src/api/schemas.ts:310::ApiErrorCode (literal-union) 厳密名対応 (apierrorcode = apierrorcode) / 交差 6 値
  [規則2b] app/Enums/ApiErrorCode.php <-> packages/cli/src/api/schemas.ts:294::API_ERROR_CODES (const-array) 語対応 [api+error+code] 主要語=code / 交差 6 値
  [規則2b] app/Enums/OAuth/CliOAuthScope.php <-> packages/cli/src/oauth/login.ts:49::DEFAULT_CLI_SCOPES (const-array) 語対応 [cli+scope] 主要語=scope / 交差 4 値
  [規則2b] app/Enums/Manual/JobStatus.php <-> resources/js/types/dashboard.ts:10::DashboardJobStatus (literal-union) 語対応 [job+status] 主要語=status / 交差 2 値
```

## svelte-scope-probe.mjs — 仮想 TS をモジュール文脈にしないと型が別ファイルへ漏れる

A.ts が `type Shared = "a" | "b"` を宣言し、B.ts が (宣言せずに) `type Ref = Shared` と書いた場合の解決結果。

```
without export {}: [ '/v/A.ts::Shared = a|b', '/v/B.ts::Ref = a|b' ]
with export {}  : [ '/v/A.ts::Shared = a|b', '/v/B.ts::Ref = Shared' ]
```

`export {};` が無いと B.ts の `Ref` が **A.ts の型に解決されてしまう** (偽の候補が立つ)。
足すとモジュール文脈になり解決できなくなる = 本設計の「解決できない候補は解析不能として落とす」に掛かる。

## probe.ts — 概念設計時の初回計測 (履歴。判定式は上記より粗い)

```
# mode=excluded
tracked .ts=379 .svelte=130
population .ts=378 .svelte=130
php resolved=123 unresolvable=3
program build ms=3072 sourceFiles=5859
derived(object-keys)=63 witnessed(excluded)=10 witnessless(kept)=53
broken syntax files=0 
candidates total=304 {"union":106,"object-keys":163,"switch-cases":13,"const-array":22}
hits total=8 {"1":6,"2b":1,"2a":1}
  [rule 1] app/Enums/Manual/CutType.php <-> resources/js/components/features/manual/ScenarioEditor.svelte:401::DragOwner (union) exact
  [rule 1] app/Enums/Notification/NotificationType.php <-> resources/js/components/features/notifications/NotificationListItem.svelte:67::switch:notification.type (switch-cases) exact
  [rule 1] app/Enums/ApiKeyAbility.php <-> resources/js/pages/Organizations/ApiKeys/Index.svelte:61::ABILITY_LABELS (object-keys) exact
  [rule 1] app/Enums/OAuth/OAuthClientKind.php <-> resources/js/pages/Organizations/ApiKeys/Sessions.svelte:41::CLIENT_KIND_LABELS (object-keys) exact
  [rule 1] app/Enums/Manual/TakeStatus.php <-> resources/js/types/manual.ts:409::SelectableTakeStatus (union) exact
  [rule 1] app/Enums/EnterpriseSso/OidcConnectionStatus.php <-> tests/js/components/features/sso/oidc-connection.test.ts:17::ALL_STATUSES (const-array) exact
  [rule 2a] app/Enums/ApiErrorCode.php <-> packages/cli/src/api/schemas.ts:310::ApiErrorCode (union) apierrorcode = apierrorcode
  [rule 2b] app/Enums/Manual/JobStatus.php <-> resources/js/types/dashboard.ts:10::DashboardJobStatus (union) words[job+statu] head=statu
```

---

残っている Critical / Warning があれば指摘してください。無ければ全体判定 APPROVED を出してください。

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはコードレビュアーとして、Laravel + Svelte + TypeScript のテストコード実装をレビューする。

## 背景

TODO T225「PHP 列挙 ⇔ TS 値域の発見の段と逆走査 (AG-099 後半)」の実装。
T218 (前半) で「登録した写しだけを見る」汎用同期 gate
(`tests/js/architecture/enum-ts-sync.test.ts` + `tests/js/support/enum-ts-sync/`) が
実装済み。本 TODO は後半で、次の完了条件を満たす:

1. PHP の文字列付き列挙 (`app/` 配下) を全数走査し、
   「登録済み / 対象外の理由つき (30 文字以上) / 抽出できない残余」の 3 つへ
   既定拒否 (deny-by-default) で分類する新規テストファイル
   (`tests/js/architecture/enum-ts-sync-discovery.test.ts`) を追加する。
2. 逆走査 2 規則を実装する:
   - 規則 1 (完全一致): 値集合が PHP 列挙と完全一致する未登録の TS 型別名の検出
   - 規則 2 (名前対応 + 値の交差): 名前が厳密に対応し (一致 / +s / +es / +values)
     値が交差するが完全一致ではない未登録の TS 宣言の検出
   見つかった候補は理由付きで exemption 登録しない限り gate を赤くする。
3. `docs/template-divergence.md` の D29 (「全数走査と逆走査は持たない」という逸脱登録) を
   再判定し、条件を満たしたので**登録を削除**する (テンプレート差分の解消は「状態」の
   変更ではなく登録の削除で表す規約)。

## レビュー観点

1. **設計との一致性**: 上記完了条件を満たしているか
2. **正確性**: 発見の段の分類ロジック (`php-enum-catalog.ts`) ・逆走査の突き合わせ
   (`reverse-sweep.ts`) に見落としがないか。特に:
   - PHP 側の抽出器が二重化していないか (T218 の `readPhpEnumValuesFromText` と
     共有する `detectEnumHeaders` を使っているか)
   - scan() が拒否する字句 (バッククォート・ヒアドキュメント等) を含むファイルの扱いが
     fail-closed か (取りこぼしを許していないか)
   - 名前対応の判定が緩すぎて偽陽性を出しやすくなっていないか、逆に厳しすぎて
     本来検出すべき drift を見逃していないか
   - 既定拒否 (deny-by-default) が本当に「未分類は失敗」になっているか
     (分類の判定ロジックに漏れがあり、実質 fail-open になっている箇所が無いか)
   - stale な登録 (もう該当しなくなった exemption) を検出する仕組みがあるか
3. **AGENTS.md §静的検査 (gate) と走査器の共通規約 5 条への適合**:
   (a) クラス参照は完全修飾名で ((a) は本変更には無関係。文字列パスの走査のため)
   (b) fail-closed (解決できない形を落とす。見逃さない)
   (c) 検出力を負例で裏取り (両方向: 壊れた入力を検出できる / 正しい入力を誤検出しない)
   (d) 収集した走査結果を判定に使う (死んだ集計を作らない)
   (e) 語彙一致の否定形はトークン完全一致 (該当箇所があれば)
4. **新設・変更時に同じ PR で揃える 4 点**:
   1. 負例と正例 (テストファースト。今回は実装後にテストを書いたため、故障注入で
      感度を裏取りしたことを実装報告に書く)
   2. 解決できない形を落とす分岐がある
   3. 走査が空振りしていないことの検査 (母集団が空でない)
   4. docblock に走査対象と保証しないものが書いてある
5. **テスト網羅性**: 86 件の PHP_ENUM_EXEMPTIONS の reason が具体的か (使い回しの
   ボイラープレートになっていないか)。REVERSE_SWEEP_EXEMPTIONS が 1 件だけで
   十分な検証がされているか
6. **DRY**: `ENUM_TS_MIRRORS` を `mirror-inventory.ts` へ切り出して両テストファイルが
   共有する設計になっているか、既存のテスト (`enum-ts-sync.test.ts`) の動作を壊していないか

## 出力形式

ファイルごとに判定 (Critical / Warning / Suggestion) を記載し、最後に全体判定
(APPROVED / CHANGES_REQUESTED) を出すこと。

---

## 実装差分 (git diff)

diff --git a/AGENTS.md b/AGENTS.md
index f27b44e9..8770effa 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -953,16 +953,23 @@ ## ドメイン固有規約
       キュークラス) は母集団に入らない。**保証しないものの正本は
       `docs/architecture.md` §退避を正常系に持つジョブの終端方式**
       (ここは要約であり、増減はそちらで管理する)。
-19. **PHP 列挙 ⇔ TypeScript 値域の同期の登録 (T218 / 家系の裁定 AG-099 前半)**:
+19. **PHP 列挙 ⇔ TypeScript 値域の同期の登録 (T218/T225 / 家系の裁定 AG-099)**:
     PHP の文字列付き列挙の値を TS の型別名で受ける箇所を作ったら、
-    `tests/js/architecture/enum-ts-sync.test.ts` の目録へ 1 行足し、件数の pin も 1 増やす。
-    **個別の同期テストのファイルを増やさない** (増殖を止めるのが本 gate の目的)。
+    `tests/js/support/enum-ts-sync/mirror-inventory.ts` の `ENUM_TS_MIRRORS` へ
+    1 行足し、件数の pin も 1 増やす。**個別の同期テストのファイルを増やさない**
+    (増殖を止めるのが本 gate の目的)。
     - 受理する形は**型別名の宣言**で、解決した型が**文字列リテラル型だけ**であること
       (別名参照・`keyof typeof`・有限のテンプレートリテラル型は解決されるので受理する)。
       PHP 側は深さ 0 の `enum X: string` がちょうど 1 つで、本体直下の case が
       `case Name = '値';` の 1 行に一致すること
+    - **`app/` の文字列付き列挙は全数走査で既定拒否される**
+      (`tests/js/architecture/enum-ts-sync-discovery.test.ts`)。TS 側に写しを作らない
+      判断をしたら `PHP_ENUM_EXEMPTIONS` へ理由 (30 文字以上) 付きで登録すること。
+      **未分類のまま残すと gate が赤くなる**
+    - **TS 側も全数走査で逆走査する** (同ファイル)。値集合が PHP 列挙と完全一致する、
+      または名前が対応し値が交差する未登録の TS 宣言が見つかったら
+      `REVERSE_SWEEP_EXEMPTIONS` へ理由付きで登録するか、`ENUM_TS_MIRRORS` へ登録すること
     - **正本のレーンは `pnpm test`** (CI の frontend job) である。
       `composer test` だけでは値集合の同期は検証されない
     - **保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期**
-      であり、本書には写さない (2 か所に書くと必ず食い違う)。
-      全数走査と逆走査を持たないことは `docs/template-divergence.md` **D29**
+      であり、本書には写さない (2 か所に書くと必ず食い違う)
diff --git a/docs/architecture.md b/docs/architecture.md
index 2219a2aa..6c7b33e5 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2884,14 +2884,41 @@ ## PHP 列挙と TypeScript 値域の同期 (T218 / 家系の裁定 AG-099 前
   `pnpm typecheck` の対象から外す)、PHP は**テスト内の文字列** (`.php` として置くと
   strict_types 宣言 gate / 禁止文の字句走査 / Pint / PHPStan の母集団に入るため)。
 
-### 保証しないもの (誇張しない)
+## 発見の段と逆走査 (T225 / 家系の裁定 AG-099 後半)
+
+`enum-ts-sync.test.ts` は目録に登録した写しだけを見る (未登録は沈黙する)。この欠落を
+`tests/js/architecture/enum-ts-sync-discovery.test.ts` が向きを変えて埋める
+(`docs/template-divergence.md` の D29 はこの実装で再判定条件を満たし、登録を削除した)。
+
+- **発見の段 (全数走査 → 既定拒否の分類)**: `buildPhpEnumCatalog()`
+  (`tests/js/support/enum-ts-sync/php-enum-catalog.ts`) が `app/` 配下の git 追跡下の
+  `*.php` を全数走査する。抽出器は既存の `readPhpEnumValuesFromText` が使う字句走査器を
+  `detectEnumHeaders` として共有し (**2 本目の抽出器を作らない**)、値集合を読めたもの
+  (`resolved`) と読めなかったもの (`unresolvable`) に分ける。`resolved` の**すべて**が
+  「登録済み (`ENUM_TS_MIRRORS`)」か「対象外の理由つき (`PHP_ENUM_EXEMPTIONS`。理由は
+  30 文字以上)」のどちらか一方に分類されていることを固定する。`unresolvable` の
+  **すべて**が `KNOWN_UNRESOLVABLE_PHP_ENUMS` に登録されていることを固定する。
+  どの分類にも入らない PHP 列挙が 1 件でもあれば赤くする (既定拒否)。登録先が実態と
+  食い違った (stale) ときも赤くする。
+  - `scan()` が拒否する字句 (バッククォート・ヒアドキュメント等) を含むファイルは、
+    生のソースに `enum <識別子>` の並びが**無ければ**母集団から外し、
+    **あれば**安全側に倒して `unresolvable` へ回す (取りこぼしを作らない側に倒す)。
+- **逆走査 (未登録候補の検出。2 規則)**: `collectTsUnionCandidates()`
+  (`tests/js/support/enum-ts-sync/ts-candidates.ts`) が `resources/js/` 配下の
+  文字列リテラル型だけの union に解決するトップレベルの型別名を全数走査し、
+  `findUnregisteredMirrorCandidates()` (`tests/js/support/enum-ts-sync/reverse-sweep.ts`)
+  が未登録の宣言を PHP の母集団 (`resolved`。分類にかかわらず全件) と突き合わせる。
+  - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全一致する未登録の宣言 = 登録漏れの疑い。
+  - **規則 2 (名前対応 + 値の交差)**: 名前が厳密に対応し (一致 / `+s` / `+es` / `+values`)
+    値が交差するが完全一致ではない未登録の宣言 = 片方だけ値を足してズレた写しの疑い。
+    緩い名前対応 (部分集合・ファイル名を名前に混ぜる形) は採らない
+    (家系の実測で偽陽性が支配的になったため)。
+  - 見つかった候補は `REVERSE_SWEEP_EXEMPTIONS`
+    (`php` + `file` + `declaration` + `rule` の組で固定) に登録された分だけ許す。
+    未登録の候補が 1 件でもあれば赤くする。登録先が実態と食い違ったときも赤くする。
+
+### 保証しないもの (誇張しない。発見の段・逆走査を含む)
 
-- **登録していない写しは 1 件も検査していない**。全数走査による既定拒否の分類と
-  逆走査 2 規則は裁定 AG-099 の後半の担当で、本 gate には無い
-  (`docs/template-divergence.md` **D29**)。現在意図的に登録していないのは
-  `types/manual.ts::SelectableTakeStatus` (部分集合の意図) /
-  `types/dashboard.ts::DashboardJobStatus` (`JobStatus` の真部分集合) /
-  `types/capture.ts::CaptureProgress` ほか画面側だけの語彙 (対応する PHP 列挙が無い) である。
 - **値の集合だけを見る**。表示ラベル・並び順・意味は見ない。
 - **部分集合の関係は表現できない** (完全一致だけ)。
 - `.svelte` の中の宣言・定数配列 (`as const` の配列)・`switch` の case ラベルは読まない。
@@ -2906,6 +2933,11 @@ ### 保証しないもの (誇張しない)
 - **レーンの非対称**: 値集合の同期は `pnpm test` (CI の frontend job) でだけ走る。
   PHP としての妥当性は backend job (`composer test` / PHPStan)。
   **`composer test` だけでは値集合の同期は検証されない**。
+- **逆走査は「登録漏れが無いことの証明」ではない**。名前も対応せず値も完全一致しない
+  drift 済みの写しは検出できない (2 規則それぞれの意図した限界)。
+- `collectTsUnionCandidates` は `resources/js/` 配下の `type X = …` という
+  トップレベル宣言だけを見る。`.svelte` の中の宣言・定数配列・switch の case ラベルは
+  逆走査の対象にもならない。
 
 ## キャッシュ素データ規約の 2 層 (T228 / 家系の裁定 AG-151 = 正典 v2)
 
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index ab3b4b9d..f5dd4272 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 31 件
+登録エントリ: 30 件
 
 ## 記録の原則
 
@@ -1647,60 +1647,6 @@ ### 関連
 
 ---
 
-## D29 PHP 列挙と TS 値域の同期を「登録した写しだけ」で守る (全数走査と逆走査は持たない)
-
-| 行 | 内容 |
-|---|---|
-| 対象パス | `tests/js/architecture/enum-ts-sync.test.ts` |
-| 業務要件起因の説明 | 正規表現で二重引用符の literal union だけを読んでいた旧抽出器を型情報の抽出へ作り直すことが先に要り、全数走査による既定拒否の分類と逆走査まで 1 度に入れると 1 変更が扱えない大きさになる。まず登録した写しだけを見る形で着地させた |
-| 揃え続ける不変条件と保証機構 | 登録した写しの値集合が PHP 側と完全一致すること (同ファイルの目録 + 件数 pin)。抽出器が静かに間違えないことは `tests/js/architecture/enum-ts-sync-extractor.test.ts` の負例行列 |
-| 再判定の条件 | 家系の裁定 AG-099 の後半 (PHP の文字列付き列挙の全数走査による既定拒否の分類 + 逆走査 2 規則) を入れたとき |
-| 決めた日 | 2026-08-17 |
-| 決めた人 | 開発者 |
-| 根拠 | devnotes/20260817-1748-enum-ts-generic-sync-gate/ |
-| 状態 | 監視中 |
-| 見直し期限 | 2027-02-13 |
-
-| 観点 | テンプレート | 本アプリ |
-|---|---|---|
-| 母集団の決め方 | PHP の文字列付き列挙を全数走査し、既定拒否で分類する | 目録へ登録した写しだけを見る |
-| 未登録の写しの扱い | 分類されていない残余として赤くなる | 検査されない (沈黙する) |
-| 逆走査 (未登録の一致候補 / 既に食い違った写しの検出) | 2 規則を持つ | 持たない |
-| 抽出の基盤 | 型情報 | 同じ (型情報。ここは正典と揃えた) |
-
-### なぜ正当な差分か (logic-driven)
-
-置き換え前の抽出器 (本変更で削除した `tests/Support/TsUnionValues.php`) は
-「二重引用符の文字列を正規表現で拾う」実装で、別名参照を含む宣言 (`ConsoleRole | "owner" | "unassigned"`) を読めず、
-注釈の中の引用符を値として拾い、`(string & {})` を閉じた union と誤認していた。
-この抽出器の上に全数走査を載せると、**分類の入力そのものが間違ったまま母集団だけが増える**。
-先に抽出を型情報へ移し、母集団の拡張 (14 組 → 27 組) までを 1 つの変更として着地させ、
-全数走査と逆走査は後半の TODO へ分けた。
-
-### 揃えている不変条件 (これは保証し続ける)
-
-> 「登録した写しについては、PHP の列挙と TS の型別名の値集合が完全一致する」
-
-- 目録の件数は完全一致で pin する (写しが黙って消えるのを防ぐ)
-- 抽出は**型情報**で行う (正典と同じ基盤)。受理できない形は空集合ではなく例外にする
-- 抽出器の受理・拒否の境界は負例行列 (TS 27 件 / PHP 40 件) が固定する
-
-### 保証しないもの
-
-- **登録していない写しは 1 件も検査していない**。未登録の写しが食い違っても沈黙する
-- 逆走査を持たないので、「値集合が完全一致するのに未登録」の候補も、
-  「名前が対応するのに既に食い違っている写し」も自動では見つからない
-- 保証しないものの完全な一覧は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期
-
-### 関連
-
-- 実装: `tests/js/architecture/enum-ts-sync.test.ts` /
-  `tests/js/architecture/enum-ts-sync-extractor.test.ts` /
-  `tests/js/support/enum-ts-sync/`
-- 設計: `devnotes/20260817-1748-enum-ts-generic-sync-gate/`
-
----
-
 ## D30 テスト DB の作成と回収に出自の記録と孤児の分類を上積みする
 
 | 行 | 内容 |
diff --git a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
index c0004330..6bac4c1e 100644
--- a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
+++ b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
@@ -34,7 +34,7 @@
  * **明示件数との同期検査であって、例外を許す一覧ではない**。個別の D 番号を名指しして
  * 規則を免除する仕組みは持たない。登録を足した / 消したら同じ変更でこの値も直す。
  */
-const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 31;
+const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 30;
 
 /** 逸脱の登録簿の本文 (読めないことは不合格)。 */
 function templateDivergenceMarkdown(): string
diff --git a/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts b/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts
new file mode 100644
index 00000000..082bdf5d
--- /dev/null
+++ b/tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts
@@ -0,0 +1,227 @@
+/**
+ * 発見の段・逆走査 (T225) の抽出器・純関数の自己検査 (負例行列)。
+ *
+ * `enum-ts-sync-discovery.test.ts` の本体 gate は「未分類の PHP 列挙・未登録の候補が
+ * 0 件であること」しか見ない。分類そのものが静かに間違える (母集団に入れるべきものを
+ * 落とす / 入れるべきでないものを混ぜる / 候補の突き合わせが緩すぎる・厳しすぎる) と、
+ * 「0 件」という結果そのものが空虚になる。ここで抽出器・突き合わせの純関数の
+ * 受理・拒否の境界を固定する。
+ *
+ * **見本の置き方**: PHP はテスト内の文字列で書く (`classifyPhpFile` はファイルを要求しない。
+ * `.php` として置くと strict_types 宣言 gate 等の母集団に入ってしまうのを避ける。
+ * `enum-ts-sync-extractor.test.ts` と同じ理由)。TS は `fixtures/candidates/` にファイルで置く
+ * (型検査器に実ファイルが要るため。`tsconfig.json` の `exclude` に
+ * `tests/js/support/enum-ts-sync/fixtures/**` が既にあるので新設不要)。
+ *
+ * 保証しないものの正本は `docs/architecture.md` §PHP 列挙と TypeScript 値域の同期。
+ */
+import { describe, expect, it } from "vitest";
+import path from "node:path";
+import { classifyPhpFile, listTrackedPhpFiles } from "../support/enum-ts-sync/php-enum-catalog";
+import { createFixtureProgram, REPO_ROOT } from "../support/enum-ts-sync/program";
+import { collectTsUnionCandidates } from "../support/enum-ts-sync/ts-candidates";
+import { findUnregisteredMirrorCandidates, shortEnumName } from "../support/enum-ts-sync/reverse-sweep";
+import type { ResolvedPhpEnum } from "../support/enum-ts-sync/php-enum-catalog";
+import type { TsUnionCandidate } from "../support/enum-ts-sync/ts-candidates";
+
+describe("classifyPhpFile() (発見の段の PHP 側分類)", () => {
+    it("D1: 素直な string enum は resolved になる", () => {
+        const source = "<?php\nenum D1: string\n{\n    case A = 'a';\n    case B = 'b';\n}\n";
+        const result = classifyPhpFile(source, "D1.php");
+        expect(result?.kind).toBe("resolved");
+        expect(result?.kind === "resolved" && [...result.values].sort()).toEqual(["a", "b"]);
+    });
+
+    it("D2: int backing の enum は母集団から外れる (undefined)", () => {
+        const source = "<?php\nenum D2: int\n{\n    case A = 1;\n}\n";
+        expect(classifyPhpFile(source, "D2.php")).toBeUndefined();
+    });
+
+    it("D3: backing の無い pure enum は母集団から外れる (undefined)", () => {
+        const source = "<?php\nenum D3\n{\n    case A;\n}\n";
+        expect(classifyPhpFile(source, "D3.php")).toBeUndefined();
+    });
+
+    it("D4: enum を宣言していないファイルは母集団から外れる (undefined)", () => {
+        const source = "<?php\nclass D4\n{\n    public function example(): void {}\n}\n";
+        expect(classifyPhpFile(source, "D4.php")).toBeUndefined();
+    });
+
+    it("D5: 深さ 0 に enum 宣言が 2 つあると unresolvable になる (機械的に選べない)", () => {
+        const source = "<?php\nenum D5A: string\n{\n    case A = 'a';\n}\nenum D5B: string\n{\n    case A = 'a';\n}\n";
+        const result = classifyPhpFile(source, "D5.php");
+        expect(result?.kind).toBe("unresolvable");
+        expect(result?.kind === "unresolvable" && result.reason).toContain("件あります");
+    });
+
+    it("D6: case が 0 件の string enum は unresolvable になる", () => {
+        const source = "<?php\nenum D6: string\n{\n}\n";
+        const result = classifyPhpFile(source, "D6.php");
+        expect(result?.kind).toBe("unresolvable");
+        expect(result?.kind === "unresolvable" && result.reason).toContain("1 件も取り出せません");
+    });
+
+    it("D7: case の値に逆斜線を含む string enum は unresolvable になる", () => {
+        const source = "<?php\nenum D7: string\n{\n    case A = 'Foo\\\\Bar';\n}\n";
+        const result = classifyPhpFile(source, "D7.php");
+        expect(result?.kind).toBe("unresolvable");
+    });
+
+    it("D8: ファイル名の語幹と enum 名が食い違うと unresolvable になる", () => {
+        const source = "<?php\nenum Other: string\n{\n    case A = 'a';\n}\n";
+        const result = classifyPhpFile(source, "D8.php");
+        expect(result?.kind).toBe("unresolvable");
+        expect(result?.kind === "unresolvable" && result.reason).toContain("ファイル名の語幹");
+    });
+
+    it("D9: scan() が拒否する字句 (ヒアドキュメント) を含み、生のソースに enum <識別子> の並びが無いと母集団から外れる", () => {
+        const source =
+            "<?php\nclass D9\n{\n    /** ToolName の enum が配線する */\n    public function example(): string\n    {\n        return <<<EOT\nplain text\nEOT;\n    }\n}\n";
+        expect(classifyPhpFile(source, "D9.php")).toBeUndefined();
+    });
+
+    it("D10: scan() が拒否する字句を含み、生のソースに enum <識別子> の並びがあれば安全側に倒して unresolvable になる", () => {
+        const source =
+            "<?php\nenum D10: string\n{\n    case A = 'a';\n}\nclass D10Helper\n{\n    public function example(): string\n    {\n        return <<<EOT\nplain text\nEOT;\n    }\n}\n";
+        const result = classifyPhpFile(source, "D10.php");
+        expect(result?.kind).toBe("unresolvable");
+    });
+});
+
+describe("listTrackedPhpFiles() (母集団の走査根)", () => {
+    it("実リポジトリの app/ 配下は空でない", () => {
+        expect(listTrackedPhpFiles().length).toBeGreaterThan(0);
+    });
+
+    it("走査根 (app/) が実在しなければ fail-fast する", () => {
+        expect(() => listTrackedPhpFiles(path.join(REPO_ROOT, "tests/js/support/enum-ts-sync"))).toThrow(
+            "走査根が実在しません",
+        );
+    });
+});
+
+describe("collectTsUnionCandidates() (逆走査の TS 側候補走査)", () => {
+    const fixtureDir = path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/fixtures/candidates");
+    const fixtureFile = path.join(fixtureDir, "mixed.ts");
+
+    it("文字列リテラル型だけの union / 単独リテラルを候補として拾い、それ以外は拾わない", () => {
+        const program = createFixtureProgram([fixtureFile]);
+        const candidates = collectTsUnionCandidates(program, fixtureDir);
+        const byName = new Map(candidates.map((c) => [c.name, c]));
+
+        expect([...(byName.get("LiteralUnionCandidate")?.values ?? [])].sort()).toEqual(["a", "b"]);
+        expect([...(byName.get("SingleLiteralCandidate")?.values ?? [])].sort()).toEqual(["only"]);
+        expect(byName.has("NotAUnionCandidate")).toBe(false);
+        expect(byName.has("NumberCandidate")).toBe(false);
+    });
+
+    it("走査根の配下でないファイルは対象にしない", () => {
+        const program = createFixtureProgram([fixtureFile]);
+        const candidates = collectTsUnionCandidates(program, path.join(REPO_ROOT, "tests/js/support/enum-ts-sync/program-fixtures"));
+        expect(candidates.some((c) => c.name === "LiteralUnionCandidate")).toBe(false);
+    });
+});
+
+const phpEnum = (path_: string, values: readonly string[]): ResolvedPhpEnum => ({
+    path: path_,
+    name: shortEnumName(path_),
+    values: new Set(values),
+});
+
+const tsCandidate = (file: string, name: string, values: readonly string[]): TsUnionCandidate => ({
+    file,
+    name,
+    values: new Set(values),
+});
+
+describe("findUnregisteredMirrorCandidates() (逆走査の突き合わせ純関数)", () => {
+    const notRegistered = (): boolean => false;
+
+    it("E1: 値集合が完全一致する未登録の宣言は規則 1 で見つかる", () => {
+        const found = findUnregisteredMirrorCandidates(
+            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
+            [tsCandidate("resources/js/types/x.ts", "Unrelated", ["a", "b"])],
+            notRegistered,
+        );
+        expect(found).toHaveLength(1);
+        expect(found[0].rule).toBe(1);
+        expect(found[0].nameMatch).toBeNull();
+    });
+
+    it("E2: 完全一致でも登録済みなら見つからない", () => {
+        const found = findUnregisteredMirrorCandidates(
+            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
+            [tsCandidate("resources/js/types/x.ts", "Foo", ["a", "b"])],
+            () => true,
+        );
+        expect(found).toEqual([]);
+    });
+
+    it("E3: 名前が一致し値が交差 (完全一致ではない) する未登録の宣言は規則 2 で見つかる", () => {
+        const found = findUnregisteredMirrorCandidates(
+            [phpEnum("app/Enums/Foo.php", ["a", "b", "c"])],
+            [tsCandidate("resources/js/types/x.ts", "Foo", ["a", "z"])],
+            notRegistered,
+        );
+        expect(found).toHaveLength(1);
+        expect(found[0].rule).toBe(2);
+        expect(found[0].nameMatch).not.toBeNull();
+    });
+
+    it("E4: 名前が複数形接尾辞 (s) で対応し値が交差すると規則 2 で見つかる", () => {
+        const found = findUnregisteredMirrorCandidates(
+            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
+            [tsCandidate("resources/js/types/x.ts", "Foos", ["a", "z"])],
+            notRegistered,
+        );
+        expect(found).toHaveLength(1);
+        expect(found[0].rule).toBe(2);
+    });
+
+    it("E5: 複数形接尾辞 (es) でも対応する", () => {
+        const found = findUnregisteredMirrorCandidates(
+            [phpEnum("app/Enums/Box.php", ["a", "b"])],
+            [tsCandidate("resources/js/types/x.ts", "Boxes", ["a", "z"])],
+            notRegistered,
+        );
+        expect(found).toHaveLength(1);
+        expect(found[0].rule).toBe(2);
+    });
+
+    it("E6: 接尾辞 values でも対応する", () => {
+        const found = findUnregisteredMirrorCandidates(
+            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
+            [tsCandidate("resources/js/types/x.ts", "FooValues", ["a", "z"])],
+            notRegistered,
+        );
+        expect(found).toHaveLength(1);
+        expect(found[0].rule).toBe(2);
+    });
+
+    it("E7: 名前が対応しても値が交差しなければ見つからない", () => {
+        const found = findUnregisteredMirrorCandidates(
+            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
+            [tsCandidate("resources/js/types/x.ts", "Foo", ["x", "y"])],
+            notRegistered,
+        );
+        expect(found).toEqual([]);
+    });
+
+    it("E8: 値が交差しても名前が対応しなければ見つからない (緩い名前対応は採らない)", () => {
+        const found = findUnregisteredMirrorCandidates(
+            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
+            [tsCandidate("resources/js/types/x.ts", "CompletelyUnrelatedName", ["a", "b", "c"])],
+            notRegistered,
+        );
+        expect(found).toEqual([]);
+    });
+
+    it("E9: 名前も値も対応しなければ見つからない", () => {
+        const found = findUnregisteredMirrorCandidates(
+            [phpEnum("app/Enums/Foo.php", ["a", "b"])],
+            [tsCandidate("resources/js/types/x.ts", "Bar", ["x", "y"])],
+            notRegistered,
+        );
+        expect(found).toEqual([]);
+    });
+});
diff --git a/tests/js/architecture/enum-ts-sync-discovery.test.ts b/tests/js/architecture/enum-ts-sync-discovery.test.ts
new file mode 100644
index 00000000..5e6541d8
--- /dev/null
+++ b/tests/js/architecture/enum-ts-sync-discovery.test.ts
@@ -0,0 +1,368 @@
+/**
+ * PHP の文字列付き列挙の発見の段と逆走査 (家系の裁定 AG-099 後半 / T225)。
+ *
+ * `enum-ts-sync.test.ts` は「目録 (`ENUM_TS_MIRRORS`) に登録した写しだけ」を見る検査で、
+ * 登録し忘れた PHP 列挙・TS 宣言は 1 件も検査していなかった (`docs/template-divergence.md`
+ * の D29 が記録していた欠落)。本ファイルは向きを変え、次の 2 段で「登録し忘れ」を
+ * **既定拒否 (deny-by-default)** で炙り出す。
+ *
+ * ## 1. 発見の段 (全数走査 → 既定拒否の分類)
+ *
+ * `buildPhpEnumCatalog()` が `app/` 配下の git 追跡下の `*.php` を全数走査し、
+ * 値集合を読めた PHP の文字列付き列挙 (`resolved`) と、読めなかったもの (`unresolvable`)
+ * に分ける。`resolved` の**すべて**が次のどちらか一方に分類されていることを固定する。
+ *
+ * - **登録済み** (`ENUM_TS_MIRRORS` に php パスがある)
+ * - **対象外の理由つき** (`PHP_ENUM_EXEMPTIONS` に登録がある。TS 側に写しを作らない
+ *   意図的な判断で、理由を 30 文字以上で書く)
+ *
+ * `unresolvable` の**すべて**が `KNOWN_UNRESOLVABLE_PHP_ENUMS` に登録されていることを
+ * 固定する (本 gate 専用の字句走査器では値集合を読み切れないと分かっている残余)。
+ *
+ * どの分類にも入らない PHP 列挙が 1 件でもあれば赤くする (**既定拒否**)。
+ * 逆に、分類の登録先が実際にはその分類でなくなった (stale) ときも赤くする
+ * (登録が実態と食い違ったまま残るのを防ぐ)。
+ *
+ * ## 2. 逆走査 (未登録候補の検出。2 規則)
+ *
+ * `collectTsUnionCandidates()` が `resources/js/` 配下の文字列リテラル型だけの union に
+ * 解決する型別名を全数走査し、`findUnregisteredMirrorCandidates()` が
+ * 未登録 (`ENUM_TS_MIRRORS` に無い) の宣言を PHP の母集団と突き合わせて次の 2 規則で拾う。
+ *
+ * - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全一致する未登録の宣言 = 登録漏れの疑い
+ * - **規則 2 (名前対応 + 値の交差)**: 名前が厳密に対応し値が交差するが完全一致ではない
+ *   未登録の宣言 = 片方だけ値を足してズレた写しの疑い
+ *
+ * 見つかった候補は `REVERSE_SWEEP_EXEMPTIONS` に登録された分だけ許す
+ * (意図的に登録しない判断を明示する)。未登録の候補が 1 件でもあれば赤くする。
+ *
+ * **保証しないもの (誇張しない)**:
+ * - 名前も対応せず値も完全一致しない drift 済みの写しは検出できない (規則の意図した限界)
+ * - 緩い名前対応 (部分集合・ファイル名を名前に混ぜる形) は採らない。実測 (家系の記録) で
+ *   偽陽性が支配的になるため、名前対応は「一致 / +s / +es / +values」の厳密な形だけを見る
+ * - `.svelte` の中の宣言・定数配列・switch の case ラベルは走査しない
+ *   (`collectTsUnionCandidates` は `type X = …` のトップレベル宣言だけを見る)
+ * - PHP 側の母集団は `php-enum-catalog.ts` の docblock が明記する範囲に限る
+ *   (走査器が読み切れない字句を含むファイルは、生のソースに enum 宣言らしい並びが
+ *   無ければ母集団から外れる)
+ *
+ * 正本のレーンは `pnpm test`。詳細は `docs/architecture.md`
+ * §PHP 列挙と TypeScript 値域の同期。
+ */
+import { beforeAll, describe, expect, it } from "vitest";
+import fs from "node:fs";
+import path from "node:path";
+import { createMirrorProgram, REPO_ROOT, type MirrorProgram } from "../support/enum-ts-sync/program";
+import { buildPhpEnumCatalog, type PhpEnumCatalog } from "../support/enum-ts-sync/php-enum-catalog";
+import { collectTsUnionCandidates, type TsUnionCandidate } from "../support/enum-ts-sync/ts-candidates";
+import { findUnregisteredMirrorCandidates } from "../support/enum-ts-sync/reverse-sweep";
+import { ENUM_TS_MIRRORS, registeredPhpPaths, registeredTsKeys } from "../support/enum-ts-sync/mirror-inventory";
+
+interface PhpEnumExemption {
+    /** リポジトリルートからの PHP 列挙ファイルの相対パス。 */
+    readonly path: string;
+    /** TS 側に写しを作らない理由 (30 文字以上)。 */
+    readonly reason: string;
+}
+
+/**
+ * 「対象外の理由つき」に分類する PHP の文字列付き列挙。
+ * ここに無く、かつ `ENUM_TS_MIRRORS` にも無い `resolved` エントリが 1 件でもあれば
+ * 発見の段が赤くなる (既定拒否)。
+ */
+const PHP_ENUM_EXEMPTIONS = [
+    { path: "app/Auth/Context/ApiActorKind.php", reason: "認証コンテキストの内部判別 (api_key/user_token)。ログと認可判定にのみ使い、画面へ値として渡さない" },
+    { path: "app/DataTransferObjects/Manual/Render/RenderClipSource.php", reason: "レンダーパイプライン内部でクリップの取得元を表す区分。フロントは個別のフラグで結果を受け取り、この値そのものは渡らない" },
+    { path: "app/Enums/Account/AccountDeletionFreezeAllowance.php", reason: "退会凍結中に許可する route 名相当の内部許可リスト。ガード判定にのみ使い、画面には表示しない" },
+    { path: "app/Enums/AccountDeletionBlockReason.php", reason: "退会ブロックの内部理由コード。画面には理由ごとの案内文をサーバ側で確定して渡すだけである" },
+    { path: "app/Enums/ApiErrorCode.php", reason: "公開 API のエラーコード語彙。TS 側はコードで分岐せず HTTP 状態とエラー文言だけを見る" },
+    { path: "app/Enums/ApiKeyAbility.php", reason: "API キー権限 (read/write) の内部語彙。管理画面はチェックボックスの選択状態だけを見る" },
+    { path: "app/Enums/Auth/EmailVerificationGateContext.php", reason: "メール確認ゲートの発生元コンテキスト。内部のルーティング判定にのみ使う語彙である" },
+    { path: "app/Enums/Billing/AutoRechargeAttemptStatus.php", reason: "自動追加購入試行の内部状態機械。画面は結果の通知種別 (BillingFeedbackKind) 経由でしか見ない" },
+    { path: "app/Enums/Billing/AutoRechargeDisabledReason.php", reason: "自動追加購入停止の内部理由。通知本文はサーバ側で文言を確定して送る" },
+    { path: "app/Enums/Billing/BillingNotificationStatus.php", reason: "課金通知の配信状態 (queued/sent/failed) を表す内部語彙。画面には配信結果を見せない" },
+    { path: "app/Enums/Billing/BillingNotificationType.php", reason: "課金通知バッチが内部で使う通知種別。画面に出る通知分類は BillingFeedbackKind 側の語彙である" },
+    { path: "app/Enums/Billing/BillingReminderDispatchResult.php", reason: "リマインダー送信バッチの内部結果。運用ログにのみ残り画面へは出ない" },
+    { path: "app/Enums/Billing/BillingRetentionExclusion.php", reason: "課金記録の保持期限からの除外対象を表す内部語彙 (D23)。バッチ処理の内部でのみ使う" },
+    { path: "app/Enums/Billing/BillingRetentionTarget.php", reason: "課金記録の保持期限の対象を表す内部語彙 (D23)。バッチ処理の内部でのみ使う" },
+    { path: "app/Enums/Billing/EntitlementDeniedReason.php", reason: "権利否認の内部理由。画面には否認された結果だけが渡り、理由コードは渡らない" },
+    { path: "app/Enums/Billing/GatewayFailureClass.php", reason: "決済ゲートウェイ失敗の観測語彙 (ドメイン固有規約 7)。ログにのみ残り画面へは出ない" },
+    { path: "app/Enums/Billing/HandledStripeWebhookEvent.php", reason: "処理対象にする Stripe webhook イベント名の内部許可リスト。サーバ内部の分岐にのみ使う" },
+    { path: "app/Enums/Billing/PersonalPlanIneligibleReason.php", reason: "個人プランへの変更が不適格である内部理由。画面には可否だけが渡る" },
+    { path: "app/Enums/Billing/PlanPriceKind.php", reason: "プラン価格の内部種別 (base/seat)。画面は金額と数量だけを見て種別コードは見ない" },
+    { path: "app/Enums/Billing/ScheduleSetupStatus.php", reason: "定期発行スケジュール設定の内部状態機械。バッチ処理の内部でのみ使う" },
+    { path: "app/Enums/Billing/SignupFundingChoice.php", reason: "サインアップ時の資金調達方式を表す内部の選択肢。オンボーディングの内部ロジックにのみ使う" },
+    { path: "app/Enums/Billing/SubscriptionState.php", reason: "購読状態の内部状態機械。画面は OnboardingBillingState 経由でしか状態を見ない" },
+    { path: "app/Enums/Billing/SubscriptionSwapOutcome.php", reason: "プラン変更処理の内部結果を表す。運用ログにのみ残り画面へは出ない" },
+    { path: "app/Enums/Billing/TicketCheckoutSessionStatus.php", reason: "チケット購入セッションの内部状態。画面は購入完了/失敗の結果だけを見る" },
+    { path: "app/Enums/Billing/TicketLedgerKind.php", reason: "チケット台帳の内部種別 (reserve/commit/release 等)。バッチと監査ログの内部でのみ使う" },
+    { path: "app/Enums/Billing/TicketReservationStatus.php", reason: "チケット予約の内部状態 (reserve→commit/release の 2 フェーズ)。内部の排他制御にのみ使う" },
+    { path: "app/Enums/Billing/TicketSource.php", reason: "チケット発行元 (月次/購入) の内部種別。台帳の内部集計にのみ使う" },
+    { path: "app/Enums/Billing/WebhookEventStatus.php", reason: "webhook イベント処理の内部状態機械。運用ログにのみ残る" },
+    { path: "app/Enums/Billing/WebhookRecoveryReason.php", reason: "webhook 再送理由の内部語彙。運用ログにのみ残り画面へは出ない" },
+    { path: "app/Enums/Billing/WebhookReplaySafety.php", reason: "webhook 再送の安全性を表す内部判定。バッチ処理の内部でのみ使う" },
+    { path: "app/Enums/Billing/WebhookStaleClaimOutcome.php", reason: "滞留 webhook の claim 処理結果を表す内部語彙。運用ログにのみ残る" },
+    { path: "app/Enums/Capture/CaptureConflictType.php", reason: "撮影登録の競合種別を表す内部語彙。画面向けの衝突種別は Manual 側の ScenarioConflictType / AnalysisConflictType が別に持つ" },
+    { path: "app/Enums/Capture/TakeUploadReservationStatus.php", reason: "アップロード予約の内部状態機械 (ドメイン固有規約 2)。画面はアップロード進捗の表示だけを見る" },
+    { path: "app/Enums/CheckoutIntent.php", reason: "チェックアウト意図を表す内部種別。画面はリダイレクト先で結果を判断する" },
+    { path: "app/Enums/CheckoutSessionStatus.php", reason: "チェックアウトセッションの内部状態機械。画面は完了/失敗の結果だけを見る" },
+    { path: "app/Enums/EmailSuppressionReason.php", reason: "メール抑制 (bounce/complaint) の内部理由。運用ログにのみ残る" },
+    { path: "app/Enums/EmailTrustLevel.php", reason: "メールアドレスの信頼度を表す内部判定。認可ロジックの内部でのみ使う" },
+    { path: "app/Enums/Http/InertiaErrorScreenPassthrough.php", reason: "エラー画面を通過させるかどうかの内部判定語彙。ミドルウェアの内部分岐にのみ使う" },
+    { path: "app/Enums/Idempotency/IdempotencyState.php", reason: "冪等キーの内部状態機械 (ドメイン固有規約 10)。画面は完了/未完了の結果だけを見る" },
+    { path: "app/Enums/Inquiry/InquirySource.php", reason: "問い合わせ受付経路を表す内部語彙。管理側の集計にのみ使い画面へは出ない" },
+    { path: "app/Enums/Inquiry/InquiryStatus.php", reason: "問い合わせ対応状況の内部状態。管理画面はサーバ側で組み立てた一覧表示だけを受け取る" },
+    { path: "app/Enums/Inquiry/InquiryType.php", reason: "問い合わせ種別の内部語彙。管理側の振り分けにのみ使い画面へは出ない" },
+    { path: "app/Enums/LlmCostGroupBy.php", reason: "LLM コスト集計の内部グルーピングキー。管理画面はサーバ側で集計済みの結果を受け取る" },
+    { path: "app/Enums/Manual/AnalysisFailureReason.php", reason: "解析失敗理由の内部語彙。画面には理由ごとの案内文をサーバ側で確定して渡す" },
+    { path: "app/Enums/Manual/CutType.php", reason: "カット種別 (step/point) の内部判定。カット編集の内部ロジックにのみ使う" },
+    { path: "app/Enums/Manual/LlmOutputInvalidReason.php", reason: "LLM 出力不正の内部理由。画面には再試行可否の結果だけが渡る" },
+    { path: "app/Enums/Manual/ShotType.php", reason: "ショット種別 (hiki/yori) の内部語彙。台本表示は文言化済みの値を受け取るだけである" },
+    { path: "app/Enums/Mcp/ToolName.php", reason: "MCP ツール名の内部登録名。Web UI からは呼ばれない CLI/MCP 専用の語彙である" },
+    { path: "app/Enums/OAuth/CliOAuthScope.php", reason: "CLI OAuth スコープの内部語彙。認可判定にのみ使い画面へは出ない" },
+    { path: "app/Enums/OAuth/OAuthClientKind.php", reason: "OAuth クライアント種別の内部判定。認可ロジックの内部でのみ使う" },
+    { path: "app/Enums/ProjectRole.php", reason: "プロジェクトロールの内部判定。画面は権限の有無を真偽値として受け取るだけである" },
+    { path: "app/Enums/ProviderCapability.php", reason: "認証プロバイダの能力分類の内部語彙。認可ロジックの内部でのみ使う" },
+    { path: "app/Enums/QuotaKey.php", reason: "Quota 種別の内部キー。画面は使用量と上限の数値だけを受け取る" },
+    { path: "app/Enums/Recovery/NonRecoveryScheduleReasonKind.php", reason: "滞留回収をスケジュールしない理由の内部語彙 (ドメイン固有規約 14)。運用ログにのみ残る" },
+    { path: "app/Enums/Recovery/RecoveryOutcome.php", reason: "滞留回収結果の内部語彙 (ドメイン固有規約 14)。運用ログにのみ残る" },
+    { path: "app/Enums/Recovery/RecoveryStream.php", reason: "滞留回収対象ストリームの内部語彙 (ドメイン固有規約 14)。運用ログにのみ残る" },
+    { path: "app/Enums/Security/AdoptedTakeReferenceKind.php", reason: "採用テイク充足判定 (ドメイン固有規約 12) が内部で使う分類語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/ApiWriteScopeExemption.php", reason: "API 変更系スコープ検査の免除申告に使う内部語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/ControllerAuthorizationExemption.php", reason: "認可 gate の免除申告に使う内部語彙 (セキュリティ不変条件 9)。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/DirectFetchJustification.php", reason: "クラス起点の主キー同一性クエリの許可理由を表す内部語彙 (セキュリティ不変条件 3)。目録だけが参照する" },
+    { path: "app/Enums/Security/ExternalCallKind.php", reason: "外部到達点の目録 (ドメイン固有規約 9) が使う内部分類語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/ExternalSeamClassification.php", reason: "外部到達点の目録が使う内部分類語彙 (guarded/exempt)。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/ExternalSeamDimension.php", reason: "外部到達点の目録が使う内部分類の軸を表す語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/ExternalSeamKind.php", reason: "外部到達点の目録が使う外部サービス種別の内部語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/GatewayFailureObservationExemption.php", reason: "決済ゲートウェイ失敗観測の免除申告に使う内部語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/IdempotencyWiringExemption.php", reason: "冪等キー配線検査の免除申告に使う内部語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/InlineThrottleBucketRationale.php", reason: "流量制限バケット判定の内部根拠語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/InvitationResolutionScope.php", reason: "招待解決の作用域を表す内部語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/JobDedupExemption.php", reason: "ジョブ重複実行検査の免除申告に使う内部語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/JobDedupGuarantee.php", reason: "ジョブ結果の一回性を担保する機構の内部分類語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/NestedRouteDefenseMode.php", reason: "nested route のテナント境界防御方式を表す内部語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/OrgAccessRevocationExemption.php", reason: "組織アクセス失効検査の免除申告に使う内部語彙 (ドメイン固有規約 16)" },
+    { path: "app/Enums/Security/OrgAccessRevocationReason.php", reason: "組織アクセス失効の理由を表す内部語彙 (ドメイン固有規約 16)。運用ログにのみ残る" },
+    { path: "app/Enums/Security/RecoveryFetchShape.php", reason: "滞留回収の取得経路の形を表す内部語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/RenderArtifactSelectionKind.php", reason: "レンダ成果物選択式 (ドメイン固有規約 13) が使う内部分類語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/RescueRouteGateKind.php", reason: "救済 route の関門通過可否を表す内部分類語彙。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Security/ThrottleCoverageExemption.php", reason: "流量制限の付与検査の免除申告に使う内部語彙 (ドメイン固有規約 5)" },
+    { path: "app/Enums/Security/TwoFactorStepUpExemption.php", reason: "2FA step-up 検査の免除申告に使う内部語彙 (ドメイン固有規約 8)" },
+    { path: "app/Enums/SecurityEventType.php", reason: "セキュリティ監査ログのイベント種別 (21 件)。画面には出さず監査ログにのみ残る" },
+    { path: "app/Enums/Smoke/SmokeFailureClass.php", reason: "smoke テストの内部失敗分類。テスト結果のログにのみ残る" },
+    { path: "app/Enums/Smoke/SmokeStage.php", reason: "smoke テストの内部ステージ分類。テスト結果のログにのみ残る" },
+    { path: "app/Enums/Storage/ExternalClientBoundaryExemption.php", reason: "外部 storage クライアント境界検査の免除申告に使う内部語彙" },
+    { path: "app/Enums/Storage/S3OperationSurface.php", reason: "S3 操作面の内部分類語彙。SSRF 検査など Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/Support/QueueAtomicityRule.php", reason: "キュー投入原子性判定の内部語彙 (ドメイン固有規約 11)。Architecture テストの目録だけが参照する" },
+    { path: "app/Enums/TwoFactorStatus.php", reason: "2FA 状態の内部判定。画面は有効/無効の真偽値と個別の案内文だけを見る" },
+    { path: "app/Services/Marketing/ContactDestinationKind.php", reason: "マーケティング問い合わせの送信先を表す内部種別。バッチ処理の内部でのみ使う" },
+] as const satisfies readonly PhpEnumExemption[];
+
+/** `PHP_ENUM_EXEMPTIONS` の件数の pin。増えても減っても赤くする。 */
+const EXPECTED_EXEMPTION_COUNT = 86;
+
+interface UnresolvablePhpEnumEntry {
+    readonly path: string;
+    readonly reason: string;
+}
+
+/**
+ * 本 gate 専用の字句走査器では値集合を読み切れないと分かっている PHP の文字列付き列挙。
+ * `catalog.unresolvable` に現れる path はここに登録された分だけ許す。
+ */
+const KNOWN_UNRESOLVABLE_PHP_ENUMS = [
+    {
+        path: "app/Enums/Security/DeletionPathSeamExemption.php",
+        reason: "case を 1 件も持たない (0 件) ため、本走査器では値集合を抽出できない",
+    },
+    {
+        path: "app/Enums/Security/RescueRouteGateDisposition.php",
+        reason: "case の値が middleware の FQCN (逆斜線を含む文字列) で、本走査器の受理文法 (逆斜線を拒む) に一致しない",
+    },
+] as const satisfies readonly UnresolvablePhpEnumEntry[];
+
+const EXPECTED_UNRESOLVABLE_COUNT = 2;
+
+interface ReverseSweepExemption {
+    /** 一致した PHP 列挙のパス。 */
+    readonly php: string;
+    /** 未登録の TS 宣言のファイル。 */
+    readonly file: string;
+    /** 未登録の TS 宣言の名前。 */
+    readonly declaration: string;
+    readonly rule: 1 | 2;
+    /** 登録しない理由 (30 文字以上)。 */
+    readonly reason: string;
+}
+
+/**
+ * 逆走査が見つける候補のうち、意図的に登録しないものの一覧。
+ * `(php, file, declaration, rule)` の組が完全一致したものだけを免除する
+ * (php パスまで固定するので、たまたま同じ値集合を持つ**別の** PHP 列挙が現れたときは
+ * 新しい候補として検出され続ける)。
+ */
+const REVERSE_SWEEP_EXEMPTIONS = [
+    {
+        php: "app/Enums/Manual/TakeStatus.php",
+        file: "resources/js/types/manual.ts",
+        declaration: "SelectableTakeStatus",
+        rule: 1,
+        reason: "「選択できるテイクの状態」という部分集合の意図の宣言。今は TakeStatus と値が完全一致するが、意図は部分集合なので登録しない",
+    },
+] as const satisfies readonly ReverseSweepExemption[];
+
+const EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT = 1;
+
+const reverseSweepKey = (php: string, file: string, declaration: string, rule: number): string =>
+    `${php}|${file}|${declaration}|${rule}`;
+
+let catalog: PhpEnumCatalog | undefined;
+let mirrorProgram: MirrorProgram | undefined;
+let tsCandidates: readonly TsUnionCandidate[] | undefined;
+
+const requireCatalog = (): PhpEnumCatalog => {
+    if (catalog === undefined) throw new Error("catalog が初期化されていません");
+    return catalog;
+};
+
+const requireTsCandidates = (): readonly TsUnionCandidate[] => {
+    if (tsCandidates === undefined) throw new Error("tsCandidates が初期化されていません");
+    return tsCandidates;
+};
+
+beforeAll(() => {
+    catalog = buildPhpEnumCatalog();
+    mirrorProgram = createMirrorProgram([...new Set(ENUM_TS_MIRRORS.map((m) => m.ts))]);
+    tsCandidates = collectTsUnionCandidates(mirrorProgram);
+}, 300_000);
+
+describe("PHP 文字列付き列挙の発見の段 (全数走査・既定拒否の分類)", () => {
+    it("走査が空振りしていない (母集団が空でない)", () => {
+        const { resolved, unresolvable } = requireCatalog();
+        expect(resolved.length).toBeGreaterThan(0);
+        expect(resolved.length + unresolvable.length).toBeGreaterThan(0);
+    });
+
+    it("目録の件数が pin と一致する", () => {
+        expect(PHP_ENUM_EXEMPTIONS).toHaveLength(EXPECTED_EXEMPTION_COUNT);
+        expect(KNOWN_UNRESOLVABLE_PHP_ENUMS).toHaveLength(EXPECTED_UNRESOLVABLE_COUNT);
+    });
+
+    it("exemption の登録は実在・重複無し・app/ 配下の .php・reason が 30 文字以上", () => {
+        const seen = new Set<string>();
+        for (const entry of PHP_ENUM_EXEMPTIONS) {
+            expect(entry.path.startsWith("app/")).toBe(true);
+            expect(entry.path.endsWith(".php")).toBe(true);
+            expect(fs.existsSync(path.join(REPO_ROOT, entry.path))).toBe(true);
+            expect(seen.has(entry.path)).toBe(false);
+            seen.add(entry.path);
+            expect(entry.reason.length).toBeGreaterThanOrEqual(30);
+        }
+    });
+
+    it("resolved はすべて『登録済み』か『対象外の理由つき』のどちらか一方に分類される", () => {
+        const registered = registeredPhpPaths();
+        const exempt = new Set<string>(PHP_ENUM_EXEMPTIONS.map((e) => e.path));
+
+        const unclassified: string[] = [];
+        const doubleClassified: string[] = [];
+        for (const enumRow of requireCatalog().resolved) {
+            const inRegistered = registered.has(enumRow.path);
+            const inExempt = exempt.has(enumRow.path);
+            if (!inRegistered && !inExempt) unclassified.push(enumRow.path);
+            if (inRegistered && inExempt) doubleClassified.push(enumRow.path);
+        }
+
+        expect(unclassified, `未分類の PHP 列挙 (登録するか PHP_ENUM_EXEMPTIONS へ理由付きで登録すること):\n${unclassified.join("\n")}`).toEqual([]);
+        expect(doubleClassified, `登録済みと対象外の両方に分類された PHP 列挙 (どちらか一方にすること):\n${doubleClassified.join("\n")}`).toEqual([]);
+    });
+
+    it("exemption の登録先が stale になっていない (今も resolved かつ未登録のままである)", () => {
+        const registered = registeredPhpPaths();
+        const resolvedPaths = new Set(requireCatalog().resolved.map((r) => r.path));
+
+        const stale = PHP_ENUM_EXEMPTIONS.filter(
+            (e) => !resolvedPaths.has(e.path) || registered.has(e.path),
+        ).map((e) => e.path);
+
+        expect(stale, `PHP_ENUM_EXEMPTIONS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale.join("\n")}`).toEqual([]);
+    });
+
+    it("unresolvable はすべて KNOWN_UNRESOLVABLE_PHP_ENUMS に登録されている", () => {
+        const known = new Set<string>(KNOWN_UNRESOLVABLE_PHP_ENUMS.map((e) => e.path));
+        const unknown = requireCatalog().unresolvable.filter((u) => !known.has(u.path)).map((u) => u.path);
+
+        expect(unknown, `未登録の抽出不能 PHP 列挙 (KNOWN_UNRESOLVABLE_PHP_ENUMS へ理由付きで登録すること):\n${unknown.join("\n")}`).toEqual([]);
+    });
+
+    it("KNOWN_UNRESOLVABLE_PHP_ENUMS の登録先が stale になっていない (今も unresolvable のままである)", () => {
+        const actual = new Set(requireCatalog().unresolvable.map((u) => u.path));
+        const stale = KNOWN_UNRESOLVABLE_PHP_ENUMS.filter((e) => !actual.has(e.path)).map((e) => e.path);
+
+        expect(stale, `KNOWN_UNRESOLVABLE_PHP_ENUMS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale.join("\n")}`).toEqual([]);
+    });
+});
+
+describe("PHP ⇔ TS 値域の逆走査 (未登録候補の検出)", () => {
+    it("TS 側の候補走査が空振りしていない (母集団が空でない)", () => {
+        expect(requireTsCandidates().length).toBeGreaterThan(0);
+    });
+
+    it("逆走査で見つかる候補は REVERSE_SWEEP_EXEMPTIONS に登録された分だけである", () => {
+        const registered = registeredTsKeys();
+        const found = findUnregisteredMirrorCandidates(
+            requireCatalog().resolved,
+            requireTsCandidates(),
+            (file, name) => registered.has(`${file}::${name}`),
+        );
+
+        const exemptKeys = new Set(
+            REVERSE_SWEEP_EXEMPTIONS.map((e) => reverseSweepKey(e.php, e.file, e.declaration, e.rule)),
+        );
+
+        const unexempted = found.filter(
+            (f) => !exemptKeys.has(reverseSweepKey(f.php.path, f.candidate.file, f.candidate.name, f.rule)),
+        );
+
+        expect(
+            unexempted,
+            `未登録のミラー候補が見つかりました (登録するか REVERSE_SWEEP_EXEMPTIONS へ理由付きで登録すること):\n${unexempted
+                .map((f) => `規則${f.rule} ${f.php.path} <-> ${f.candidate.file}::${f.candidate.name}${f.nameMatch !== null ? ` (${f.nameMatch})` : ""}`)
+                .join("\n")}`,
+        ).toEqual([]);
+    });
+
+    it("REVERSE_SWEEP_EXEMPTIONS の件数が pin と一致し、登録先が実在・重複無し・reason が 30 文字以上", () => {
+        expect(REVERSE_SWEEP_EXEMPTIONS).toHaveLength(EXPECTED_REVERSE_SWEEP_EXEMPTION_COUNT);
+
+        const seen = new Set<string>();
+        for (const entry of REVERSE_SWEEP_EXEMPTIONS) {
+            expect(fs.existsSync(path.join(REPO_ROOT, entry.php))).toBe(true);
+            expect(fs.existsSync(path.join(REPO_ROOT, entry.file))).toBe(true);
+            const key = reverseSweepKey(entry.php, entry.file, entry.declaration, entry.rule);
+            expect(seen.has(key)).toBe(false);
+            seen.add(key);
+            expect(entry.reason.length).toBeGreaterThanOrEqual(30);
+        }
+    });
+
+    it("REVERSE_SWEEP_EXEMPTIONS の登録先が stale になっていない (今も候補として検出され続けている)", () => {
+        const registered = registeredTsKeys();
+        const found = findUnregisteredMirrorCandidates(
+            requireCatalog().resolved,
+            requireTsCandidates(),
+            (file, name) => registered.has(`${file}::${name}`),
+        );
+        const foundKeys = new Set(found.map((f) => reverseSweepKey(f.php.path, f.candidate.file, f.candidate.name, f.rule)));
+
+        const stale = REVERSE_SWEEP_EXEMPTIONS.filter(
+            (e) => !foundKeys.has(reverseSweepKey(e.php, e.file, e.declaration, e.rule)),
+        );
+
+        expect(
+            stale,
+            `REVERSE_SWEEP_EXEMPTIONS の登録が実態と食い違っている (削除するか登録し直すこと):\n${stale.map((e) => `${e.php} <-> ${e.file}::${e.declaration}`).join("\n")}`,
+        ).toEqual([]);
+    });
+});
diff --git a/tests/js/architecture/enum-ts-sync.test.ts b/tests/js/architecture/enum-ts-sync.test.ts
index 1008058a..a50ed0bb 100644
--- a/tests/js/architecture/enum-ts-sync.test.ts
+++ b/tests/js/architecture/enum-ts-sync.test.ts
@@ -1,23 +1,19 @@
 /**
  * PHP 列挙 ⇔ TypeScript 値域の汎用同期 gate (家系の裁定 AG-099 前半)。
  *
- * 目録に登録した写しについて、PHP の文字列付き列挙の値集合と TS の型別名が解決する
- * 値集合が**完全一致**することを固定する。写しが片方だけ増えると、画面の分岐に
+ * 目録 (`ENUM_TS_MIRRORS`。実体は `../support/enum-ts-sync/mirror-inventory.ts`) に
+ * 登録した写しについて、PHP の文字列付き列挙の値集合と TS の型別名が解決する値集合が
+ * **完全一致**することを固定する。写しが片方だけ増えると、画面の分岐に
  * 「どこにも当たらない値」が生まれて無言の描画漏れになる。
  *
  * **登録の仕方**: PHP の列挙の値を TS の型別名で受ける箇所を作ったら、
  * `ENUM_TS_MIRRORS` へ 1 行足し、`EXPECTED_MIRROR_COUNT` を 1 増やす。
  * 個別の検査ファイルは**増やさない** (増殖を止めるのが本 gate の目的)。
  *
- * **登録していない写しは 1 件も検査していない**。全数走査による既定拒否の分類と
- * 逆走査は AG-099 後半の担当で、本 gate には無い (`docs/template-divergence.md` D29)。
- * 現時点で意図的に登録していないものと理由:
- *
- * | TS 宣言 | 理由 |
- * |---|---|
- * | `types/manual.ts::SelectableTakeStatus` | 「選択できるテイクの状態」という部分集合の意図。今は `TakeStatus` と全一致だが完全一致で縛ると意図と食い違う |
- * | `types/dashboard.ts::DashboardJobStatus` | `JobStatus` の真部分集合 (進行中のみ) |
- * | `types/capture.ts::CaptureProgress` ほか画面側だけの語彙 | 対応する PHP 列挙が無い |
+ * **本ファイルが見るのは登録した写しだけ**である。未登録の PHP 列挙・TS 宣言の発見と、
+ * 「登録し忘れ」「名前は対応するが既に食い違った写し」の検出は
+ * `enum-ts-sync-discovery.test.ts` (裁定 AG-099 後半) の担当であり、そちらが
+ * `ENUM_TS_MIRRORS` を**登録済みの判定**に再利用する (単一の出典)。
  *
  * **レーンの非対称**: 本 gate は `pnpm test` (CI の frontend job) でだけ走る。
  * `composer test` だけでは値集合の同期は検証されない。
@@ -31,257 +27,13 @@ import { EnumTsSyncError } from "../support/enum-ts-sync/errors";
 import { REPO_ROOT, createMirrorProgram, type MirrorProgram } from "../support/enum-ts-sync/program";
 import { readTsUnionValues } from "../support/enum-ts-sync/ts-value-sets";
 import { readPhpEnumValues } from "../support/enum-ts-sync/php-enums";
+import {
+    ENUM_TS_MIRRORS,
+    EXPECTED_MIRROR_COUNT,
+    validateMirrors,
+    type EnumTsMirror,
+} from "../support/enum-ts-sync/mirror-inventory";
 
-interface EnumTsMirror {
-    /** リポジトリルートからの PHP 列挙ファイルの相対パス (`app/` 配下の `*.php`)。 */
-    readonly php: string;
-    /** リポジトリルートからの TS ファイルの相対パス (`resources/js/` 配下の `*.ts`)。 */
-    readonly ts: string;
-    /** TS 側の型別名の名前。 */
-    readonly declaration: string;
-    /** この写しが要る理由 (画面のどこが値で分岐するか)。 */
-    readonly note: string;
-}
-
-/**
- * 写しの目録。
- * `note` に最小文字数は課さない — 本目録は**免除の申告ではなく登録**であり、
- * 「検査から外す」判断ではないため (免除目録が 30 文字を課すのとは重さが違う)。
- */
-const ENUM_TS_MIRRORS = [
-    {
-        php: "app/Enums/Manual/VideoManualStatus.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "VideoManualStatus",
-        note: "詳細画面とダッシュボードが制作状態 5 値で CTA を分岐する",
-    },
-    {
-        php: "app/Enums/Manual/ManualProgress.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "ManualProgress",
-        note: "一覧の絞り込みと行バッジが 3 値で分岐する",
-    },
-    {
-        php: "app/Enums/Manual/RenderKind.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "RenderKind",
-        note: "プレビューと完成動画で受け取り口の扱いを分ける",
-    },
-    {
-        php: "app/Enums/Manual/RenderStep.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "RenderStep",
-        note: "合成の進捗表示が段の値で分岐する",
-    },
-    {
-        php: "app/Enums/Manual/RenderErrorCode.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "RenderErrorCode",
-        note: "失敗時の案内文を符号で選ぶ",
-    },
-    {
-        php: "app/Enums/Manual/RenderConflictType.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "RenderConflictType",
-        note: "409 の理由ごとに画面の受け方を変える",
-    },
-    {
-        php: "app/Enums/Manual/ScenarioVerdict.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "ScenarioVerdict",
-        note: "台本の判定バッジが 3 値で分岐する",
-    },
-    {
-        php: "app/Enums/Manual/ScenarioRuleCode.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "ScenarioRuleCode",
-        note: "台本の指摘一覧が規則の符号で文言を選ぶ",
-    },
-    {
-        php: "app/Enums/Manual/JobStatus.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "AnalysisJobStatus",
-        note: "解析ジョブの進行表示が状態で分岐する (TS 側は別名)",
-    },
-    {
-        php: "app/Enums/Manual/MaterialType.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "CutMaterialType",
-        note: "カット編集が素材種別で入力欄を切り替える",
-    },
-    {
-        php: "app/Enums/Manual/MaterialType.php",
-        ts: "resources/js/types/capture.ts",
-        declaration: "MaterialType",
-        note: "撮影 PWA 側の写し。PC 側と types を分けてあるので両方 pin する",
-    },
-    {
-        php: "app/Enums/Notification/NotificationType.php",
-        ts: "resources/js/types/notification.ts",
-        declaration: "NotificationType",
-        note: "通知一覧がアイコンと文言を種別で選ぶ",
-    },
-    {
-        php: "app/Enums/Billing/OnboardingBillingState.php",
-        ts: "resources/js/types/billing.ts",
-        declaration: "BillingStateValue",
-        note: "契約画面とダッシュボードの両方が契約状態で分岐する",
-    },
-    {
-        php: "app/Enums/AccountDeletionBlockerAction.php",
-        ts: "resources/js/types/account.ts",
-        declaration: "AccountDeletionBlockerAction",
-        note: "退会ガードの「次の一手」で導線を分岐する",
-    },
-    {
-        php: "app/Enums/PlanCode.php",
-        ts: "resources/js/types/Auth.ts",
-        declaration: "PlanCode",
-        note: "契約プランの符号で表示と導線を分岐する",
-    },
-    {
-        php: "app/Enums/AdminConsoleRole.php",
-        ts: "resources/js/types/admin.ts",
-        declaration: "ConsoleRole",
-        note: "ユーザー管理のロール遷移コマンド (TS 側は別名)",
-    },
-    {
-        php: "app/Enums/MemberRoleState.php",
-        ts: "resources/js/types/admin.ts",
-        declaration: "MemberRoleState",
-        note: "ユーザー管理の表示状態 5 値。TS 側は ConsoleRole の別名参照を含む",
-    },
-    {
-        php: "app/Enums/OrganizationRole.php",
-        ts: "resources/js/lib/shared-props.ts",
-        declaration: "OrganizationRoleValue",
-        note: "共有 props の組織ロールで画面の権限表示を分岐する",
-    },
-    {
-        php: "app/Enums/Billing/BillingFeedbackKind.php",
-        ts: "resources/js/types/billing.ts",
-        declaration: "BillingFeedbackKind",
-        note: "課金画面の通知種別で文言を選ぶ",
-    },
-    {
-        php: "app/Enums/Billing/PurchaseFormState.php",
-        ts: "resources/js/types/billing.ts",
-        declaration: "PurchaseFormStateValue",
-        note: "購入フォームの状態で入力欄の初期値を変える",
-    },
-    {
-        php: "app/Enums/Manual/TakeStatus.php",
-        ts: "resources/js/types/capture.ts",
-        declaration: "TakeStatus",
-        note: "撮影テイクの状態で再撮影・採用の可否表示を分岐する",
-    },
-    {
-        php: "app/Enums/Dashboard/DashboardState.php",
-        ts: "resources/js/types/dashboard.ts",
-        declaration: "DashboardState",
-        note: "ダッシュボードの初期状態で案内を切り替える",
-    },
-    {
-        php: "app/Enums/Dashboard/DashboardRole.php",
-        ts: "resources/js/types/dashboard.ts",
-        declaration: "DashboardRole",
-        note: "ダッシュボードの役割で出す導線を変える",
-    },
-    {
-        php: "app/Enums/Manual/AnalysisStep.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "AnalysisStep",
-        note: "解析の進捗表示が段の値で分岐する",
-    },
-    {
-        php: "app/Enums/Manual/AnalysisConflictType.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "AnalysisConflictType",
-        note: "解析要求の 409 の理由ごとに案内を変える",
-    },
-    {
-        php: "app/Enums/Manual/ScenarioConflictType.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "ScenarioConflictType",
-        note: "台本保存の 409 の理由ごとに案内を変える",
-    },
-    {
-        php: "app/Enums/Manual/ManualSortOption.php",
-        ts: "resources/js/types/manual.ts",
-        declaration: "ManualSortOption",
-        note: "一覧の並び順の選択肢を URL クエリと突き合わせる",
-    },
-] as const satisfies readonly EnumTsMirror[];
-
-/**
- * 目録の件数の pin。増えても減っても赤くする (写しが黙って消えるのを防ぐ)。
- * **これは網羅の証明ではない** — 登録していない写しは 1 件も検査していない。
- */
-const EXPECTED_MIRROR_COUNT = 27;
-
-/** `root` の**配下**にあるか (兄弟ディレクトリを通さないよう区切りまで含めて見る)。 */
-const isUnder = (absolute: string, root: string): boolean => absolute.startsWith(root + path.sep);
-
-/**
- * 目録の行の体裁を検査する純関数。
- * **program を作る前に呼ぶ** — 後回しにすると、検査の外にあるファイルを
- * 「赤くなる前に読んでしまう」ことになる。
- *
- * @param rows 目録の行
- * @param root 走査根 (既定はリポジトリのルート)。**負のコントロールが symlink や
- *             兄弟ディレクトリを含む見本の木を一時ディレクトリに作って渡すためだけ**に
- *             引数化してある (本番の呼び出しは既定値を使う)。
- */
-export const validateMirrors = (rows: readonly EnumTsMirror[], root: string = REPO_ROOT): void => {
-    const appRoot = path.join(root, "app");
-    const jsRoot = path.join(root, "resources", "js");
-    const seen = new Set<string>();
-    const seenReal = new Set<string>();
-
-    for (const row of rows) {
-        const where = `${row.php} ⇔ ${row.ts}::${row.declaration}`;
-
-        for (const relative of [row.php, row.ts]) {
-            if (path.isAbsolute(relative)) throw new EnumTsSyncError(where, `絶対パスは登録できません: ${relative}`);
-            if (relative.includes("\\")) throw new EnumTsSyncError(where, `逆斜線を含むパスは登録できません: ${relative}`);
-            const segments = relative.split("/");
-            if (segments.some((s) => s === "" || s === "." || s === "..")) {
-                throw new EnumTsSyncError(where, `. / .. / 空の区間を含むパスは登録できません: ${relative}`);
-            }
-        }
-
-        if (!row.php.endsWith(".php")) throw new EnumTsSyncError(where, `php は .php で終わること: ${row.php}`);
-        if (!row.ts.endsWith(".ts")) throw new EnumTsSyncError(where, `ts は .ts で終わること: ${row.ts}`);
-        if (row.note.trim() === "") throw new EnumTsSyncError(where, "note が空です");
-
-        const phpAbs = path.resolve(root, row.php);
-        const tsAbs = path.resolve(root, row.ts);
-        if (!isUnder(phpAbs, appRoot)) throw new EnumTsSyncError(where, `php は app/ 配下だけ: ${row.php}`);
-        if (!isUnder(tsAbs, jsRoot)) throw new EnumTsSyncError(where, `ts は resources/js/ 配下だけ: ${row.ts}`);
-
-        for (const [absolute, scanRoot, label] of [
-            [phpAbs, appRoot, row.php],
-            [tsAbs, jsRoot, row.ts],
-        ] as const) {
-            if (!fs.existsSync(absolute)) throw new EnumTsSyncError(where, `登録されたファイルが実在しません: ${label}`);
-            if (!fs.statSync(absolute).isFile()) throw new EnumTsSyncError(where, `通常ファイルではありません: ${label}`);
-            // symlink 経由で走査範囲の外へ抜けられないようにする
-            if (!isUnder(fs.realpathSync(absolute), scanRoot)) {
-                throw new EnumTsSyncError(where, `symlink の解決先が走査範囲の外です: ${label}`);
-            }
-        }
-
-        const key = `${row.ts}::${row.declaration}`;
-        if (seen.has(key)) throw new EnumTsSyncError(where, `同じ TS 宣言が 2 回登録されています: ${key}`);
-        seen.add(key);
-
-        const realKey = `${fs.realpathSync(tsAbs)}::${row.declaration}`;
-        if (seenReal.has(realKey)) {
-            throw new EnumTsSyncError(where, `symlink 越しに同じ TS 宣言が 2 回登録されています: ${realKey}`);
-        }
-        seenReal.add(realKey);
-    }
-};
 
 let mirrorProgram: MirrorProgram | undefined;
 
diff --git a/tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts b/tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts
new file mode 100644
index 00000000..563592d4
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/fixtures/candidates/mixed.ts
@@ -0,0 +1,5 @@
+/** 逆走査の候補走査 (collectTsUnionCandidates) の負のコントロール専用の見本。 */
+export type LiteralUnionCandidate = "a" | "b";
+export type SingleLiteralCandidate = "only";
+export type NotAUnionCandidate = string;
+export type NumberCandidate = 1 | 2;
diff --git a/tests/js/support/enum-ts-sync/mirror-inventory.ts b/tests/js/support/enum-ts-sync/mirror-inventory.ts
new file mode 100644
index 00000000..bdc8c08d
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/mirror-inventory.ts
@@ -0,0 +1,273 @@
+/**
+ * PHP 列挙 ⇔ TS 値域の写しの目録 (`ENUM_TS_MIRRORS`) と、その体裁を検査する
+ * `validateMirrors()`。
+ *
+ * `tests/js/architecture/enum-ts-sync.test.ts` (登録した写しの値集合が一致することを見る)
+ * と `tests/js/architecture/enum-ts-sync-discovery.test.ts` (発見の段・逆走査。
+ * どの PHP 列挙・TS 宣言が「登録済み」かを判定するのに同じ目録を使う) の**両方から使う
+ * 単一の出典**である。2 つに分かれると「片方だけ更新して食い違う」経路が生まれるため、
+ * ここへ集約している。
+ */
+import fs from "node:fs";
+import path from "node:path";
+import { EnumTsSyncError } from "./errors";
+import { REPO_ROOT } from "./program";
+
+export interface EnumTsMirror {
+    /** リポジトリルートからの PHP 列挙ファイルの相対パス (`app/` 配下の `*.php`)。 */
+    readonly php: string;
+    /** リポジトリルートからの TS ファイルの相対パス (`resources/js/` 配下の `*.ts`)。 */
+    readonly ts: string;
+    /** TS 側の型別名の名前。 */
+    readonly declaration: string;
+    /** この写しが要る理由 (画面のどこが値で分岐するか)。 */
+    readonly note: string;
+}
+
+/**
+ * 写しの目録。
+ * `note` に最小文字数は課さない — 本目録は**免除の申告ではなく登録**であり、
+ * 「検査から外す」判断ではないため (免除目録が 30 文字を課すのとは重さが違う)。
+ */
+export const ENUM_TS_MIRRORS = [
+    {
+        php: "app/Enums/Manual/VideoManualStatus.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "VideoManualStatus",
+        note: "詳細画面とダッシュボードが制作状態 5 値で CTA を分岐する",
+    },
+    {
+        php: "app/Enums/Manual/ManualProgress.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "ManualProgress",
+        note: "一覧の絞り込みと行バッジが 3 値で分岐する",
+    },
+    {
+        php: "app/Enums/Manual/RenderKind.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "RenderKind",
+        note: "プレビューと完成動画で受け取り口の扱いを分ける",
+    },
+    {
+        php: "app/Enums/Manual/RenderStep.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "RenderStep",
+        note: "合成の進捗表示が段の値で分岐する",
+    },
+    {
+        php: "app/Enums/Manual/RenderErrorCode.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "RenderErrorCode",
+        note: "失敗時の案内文を符号で選ぶ",
+    },
+    {
+        php: "app/Enums/Manual/RenderConflictType.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "RenderConflictType",
+        note: "409 の理由ごとに画面の受け方を変える",
+    },
+    {
+        php: "app/Enums/Manual/ScenarioVerdict.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "ScenarioVerdict",
+        note: "台本の判定バッジが 3 値で分岐する",
+    },
+    {
+        php: "app/Enums/Manual/ScenarioRuleCode.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "ScenarioRuleCode",
+        note: "台本の指摘一覧が規則の符号で文言を選ぶ",
+    },
+    {
+        php: "app/Enums/Manual/JobStatus.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "AnalysisJobStatus",
+        note: "解析ジョブの進行表示が状態で分岐する (TS 側は別名)",
+    },
+    {
+        php: "app/Enums/Manual/MaterialType.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "CutMaterialType",
+        note: "カット編集が素材種別で入力欄を切り替える",
+    },
+    {
+        php: "app/Enums/Manual/MaterialType.php",
+        ts: "resources/js/types/capture.ts",
+        declaration: "MaterialType",
+        note: "撮影 PWA 側の写し。PC 側と types を分けてあるので両方 pin する",
+    },
+    {
+        php: "app/Enums/Notification/NotificationType.php",
+        ts: "resources/js/types/notification.ts",
+        declaration: "NotificationType",
+        note: "通知一覧がアイコンと文言を種別で選ぶ",
+    },
+    {
+        php: "app/Enums/Billing/OnboardingBillingState.php",
+        ts: "resources/js/types/billing.ts",
+        declaration: "BillingStateValue",
+        note: "契約画面とダッシュボードの両方が契約状態で分岐する",
+    },
+    {
+        php: "app/Enums/AccountDeletionBlockerAction.php",
+        ts: "resources/js/types/account.ts",
+        declaration: "AccountDeletionBlockerAction",
+        note: "退会ガードの「次の一手」で導線を分岐する",
+    },
+    {
+        php: "app/Enums/PlanCode.php",
+        ts: "resources/js/types/Auth.ts",
+        declaration: "PlanCode",
+        note: "契約プランの符号で表示と導線を分岐する",
+    },
+    {
+        php: "app/Enums/AdminConsoleRole.php",
+        ts: "resources/js/types/admin.ts",
+        declaration: "ConsoleRole",
+        note: "ユーザー管理のロール遷移コマンド (TS 側は別名)",
+    },
+    {
+        php: "app/Enums/MemberRoleState.php",
+        ts: "resources/js/types/admin.ts",
+        declaration: "MemberRoleState",
+        note: "ユーザー管理の表示状態 5 値。TS 側は ConsoleRole の別名参照を含む",
+    },
+    {
+        php: "app/Enums/OrganizationRole.php",
+        ts: "resources/js/lib/shared-props.ts",
+        declaration: "OrganizationRoleValue",
+        note: "共有 props の組織ロールで画面の権限表示を分岐する",
+    },
+    {
+        php: "app/Enums/Billing/BillingFeedbackKind.php",
+        ts: "resources/js/types/billing.ts",
+        declaration: "BillingFeedbackKind",
+        note: "課金画面の通知種別で文言を選ぶ",
+    },
+    {
+        php: "app/Enums/Billing/PurchaseFormState.php",
+        ts: "resources/js/types/billing.ts",
+        declaration: "PurchaseFormStateValue",
+        note: "購入フォームの状態で入力欄の初期値を変える",
+    },
+    {
+        php: "app/Enums/Manual/TakeStatus.php",
+        ts: "resources/js/types/capture.ts",
+        declaration: "TakeStatus",
+        note: "撮影テイクの状態で再撮影・採用の可否表示を分岐する",
+    },
+    {
+        php: "app/Enums/Dashboard/DashboardState.php",
+        ts: "resources/js/types/dashboard.ts",
+        declaration: "DashboardState",
+        note: "ダッシュボードの初期状態で案内を切り替える",
+    },
+    {
+        php: "app/Enums/Dashboard/DashboardRole.php",
+        ts: "resources/js/types/dashboard.ts",
+        declaration: "DashboardRole",
+        note: "ダッシュボードの役割で出す導線を変える",
+    },
+    {
+        php: "app/Enums/Manual/AnalysisStep.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "AnalysisStep",
+        note: "解析の進捗表示が段の値で分岐する",
+    },
+    {
+        php: "app/Enums/Manual/AnalysisConflictType.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "AnalysisConflictType",
+        note: "解析要求の 409 の理由ごとに案内を変える",
+    },
+    {
+        php: "app/Enums/Manual/ScenarioConflictType.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "ScenarioConflictType",
+        note: "台本保存の 409 の理由ごとに案内を変える",
+    },
+    {
+        php: "app/Enums/Manual/ManualSortOption.php",
+        ts: "resources/js/types/manual.ts",
+        declaration: "ManualSortOption",
+        note: "一覧の並び順の選択肢を URL クエリと突き合わせる",
+    },
+] as const satisfies readonly EnumTsMirror[];
+
+/**
+ * 目録の件数の pin。増えても減っても赤くする (写しが黙って消えるのを防ぐ)。
+ * **これは網羅の証明ではない** — 登録していない写しは 1 件も検査していない。
+ */
+export const EXPECTED_MIRROR_COUNT = 27;
+
+/** `root` の**配下**にあるか (兄弟ディレクトリを通さないよう区切りまで含めて見る)。 */
+export const isUnder = (absolute: string, root: string): boolean => absolute.startsWith(root + path.sep);
+
+/**
+ * 目録の行の体裁を検査する純関数。
+ * **program を作る前に呼ぶ** — 後回しにすると、検査の外にあるファイルを
+ * 「赤くなる前に読んでしまう」ことになる。
+ *
+ * @param rows 目録の行
+ * @param root 走査根 (既定はリポジトリのルート)。**負のコントロールが symlink や
+ *             兄弟ディレクトリを含む見本の木を一時ディレクトリに作って渡すためだけ**に
+ *             引数化してある (本番の呼び出しは既定値を使う)。
+ */
+export const validateMirrors = (rows: readonly EnumTsMirror[], root: string = REPO_ROOT): void => {
+    const appRoot = path.join(root, "app");
+    const jsRoot = path.join(root, "resources", "js");
+    const seen = new Set<string>();
+    const seenReal = new Set<string>();
+
+    for (const row of rows) {
+        const where = `${row.php} ⇔ ${row.ts}::${row.declaration}`;
+
+        for (const relative of [row.php, row.ts]) {
+            if (path.isAbsolute(relative)) throw new EnumTsSyncError(where, `絶対パスは登録できません: ${relative}`);
+            if (relative.includes("\\")) throw new EnumTsSyncError(where, `逆斜線を含むパスは登録できません: ${relative}`);
+            const segments = relative.split("/");
+            if (segments.some((s) => s === "" || s === "." || s === "..")) {
+                throw new EnumTsSyncError(where, `. / .. / 空の区間を含むパスは登録できません: ${relative}`);
+            }
+        }
+
+        if (!row.php.endsWith(".php")) throw new EnumTsSyncError(where, `php は .php で終わること: ${row.php}`);
+        if (!row.ts.endsWith(".ts")) throw new EnumTsSyncError(where, `ts は .ts で終わること: ${row.ts}`);
+        if (row.note.trim() === "") throw new EnumTsSyncError(where, "note が空です");
+
+        const phpAbs = path.resolve(root, row.php);
+        const tsAbs = path.resolve(root, row.ts);
+        if (!isUnder(phpAbs, appRoot)) throw new EnumTsSyncError(where, `php は app/ 配下だけ: ${row.php}`);
+        if (!isUnder(tsAbs, jsRoot)) throw new EnumTsSyncError(where, `ts は resources/js/ 配下だけ: ${row.ts}`);
+
+        for (const [absolute, scanRoot, label] of [
+            [phpAbs, appRoot, row.php],
+            [tsAbs, jsRoot, row.ts],
+        ] as const) {
+            if (!fs.existsSync(absolute)) throw new EnumTsSyncError(where, `登録されたファイルが実在しません: ${label}`);
+            if (!fs.statSync(absolute).isFile()) throw new EnumTsSyncError(where, `通常ファイルではありません: ${label}`);
+            // symlink 経由で走査範囲の外へ抜けられないようにする
+            if (!isUnder(fs.realpathSync(absolute), scanRoot)) {
+                throw new EnumTsSyncError(where, `symlink の解決先が走査範囲の外です: ${label}`);
+            }
+        }
+
+        const key = `${row.ts}::${row.declaration}`;
+        if (seen.has(key)) throw new EnumTsSyncError(where, `同じ TS 宣言が 2 回登録されています: ${key}`);
+        seen.add(key);
+
+        const realKey = `${fs.realpathSync(tsAbs)}::${row.declaration}`;
+        if (seenReal.has(realKey)) {
+            throw new EnumTsSyncError(where, `symlink 越しに同じ TS 宣言が 2 回登録されています: ${realKey}`);
+        }
+        seenReal.add(realKey);
+    }
+};
+
+/** 登録済みの `(php パス)` 集合。発見の段が「登録済み」を判定するのに使う。 */
+export const registeredPhpPaths = (rows: readonly EnumTsMirror[] = ENUM_TS_MIRRORS): ReadonlySet<string> =>
+    new Set(rows.map((row) => row.php));
+
+/** 登録済みの `(ts パス, 宣言名)` 集合。逆走査が「登録済み」を判定するのに使う。 */
+export const registeredTsKeys = (rows: readonly EnumTsMirror[] = ENUM_TS_MIRRORS): ReadonlySet<string> =>
+    new Set(rows.map((row) => `${row.ts}::${row.declaration}`));
diff --git a/tests/js/support/enum-ts-sync/php-enum-catalog.ts b/tests/js/support/enum-ts-sync/php-enum-catalog.ts
new file mode 100644
index 00000000..479e310b
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/php-enum-catalog.ts
@@ -0,0 +1,144 @@
+/**
+ * PHP の文字列付き列挙の母集団を全数走査する (裁定 AG-099 後半 / 発見の段)。
+ *
+ * `readPhpEnumValues` / `enum-ts-sync.test.ts` は**登録した写しだけ**を見る検査で、
+ * 登録していない PHP 列挙は 1 件も検査していない。本モジュールは向きを変え、
+ * `app/` 配下の git 追跡下の `*.php` を**全数**走査して、
+ *
+ * - `resolved`   … 深さ 0 に string backing の enum 宣言がちょうど 1 つあり、
+ *                  case もすべて受理できたファイル (「値集合が読めた」)
+ * - `unresolvable` … string backing の enum を宣言していそうだが、
+ *                  本 gate 専用の走査器では読み切れなかったファイル
+ *
+ * の 2 つに分ける。int backing / backing 無し / enum を宣言していないファイルは
+ * **母集団に含めない** (このモジュールが見るのは「PHP の文字列付き列挙」だけである)。
+ *
+ * **抽出器は 1 本しか持たない**。`detectEnumHeaders` (`php-enums.ts`) を
+ * `readPhpEnumValuesFromText` と共用するので、母集団の発見と値集合の抽出が
+ * 食い違ったまま育つことはない。
+ *
+ * **保証しないもの (誇張しない)**:
+ * - `scan()` が拒否する字句 (バッククォート・ヒアドキュメント・閉じタグ・
+ *   未終端の文字列や注釈) を含むファイルは、ファイル全体を読み切れない。
+ *   このとき**生のソースに `enum <識別子>` の並びが無ければ母集団から外す**
+ *   (その並びが無い = 本走査器が受理する enum 宣言を書ける形になっていないと判断する)。
+ *   並びが**あれば** `unresolvable` へ回す (安全側に倒す。実測では
+ *   `app/Mcp/Servers/AppMcpServer.php` がここに該当する。ヒアドキュメントを持ち、
+ *   docblock に「ToolName **enum** が」という言及があるため候補に上がるが、
+ *   実際には enum を宣言していない。安全側に倒した結果の**意図した過剰検出**であり、
+ *   目録の登録で解消する)
+ * - 深さ 0 に enum 宣言が複数ある (稀) 場合は、どれが対象か機械的に選べないので
+ *   常に `unresolvable` にする
+ */
+import { execFileSync } from "node:child_process";
+import fs from "node:fs";
+import path from "node:path";
+import { EnumTsSyncError } from "./errors";
+import { REPO_ROOT } from "./program";
+import { detectEnumHeaders, readPhpEnumValuesFromText } from "./php-enums";
+
+/** 生のソースに「enum の後ろに識別子が続く」並びがあるか (安全側の緩い判定)。 */
+const LOOSE_ENUM_DECLARATION = /\benum\s+[A-Za-z_][A-Za-z0-9_]*/;
+
+export interface ResolvedPhpEnum {
+    /** リポジトリルートからの相対パス。 */
+    readonly path: string;
+    /** enum 宣言の名前。 */
+    readonly name: string;
+    /** case の値集合。 */
+    readonly values: ReadonlySet<string>;
+}
+
+export interface UnresolvablePhpEnum {
+    readonly path: string;
+    /** 読み切れなかった理由 (例外の文面)。 */
+    readonly reason: string;
+}
+
+export interface PhpEnumCatalog {
+    readonly resolved: readonly ResolvedPhpEnum[];
+    readonly unresolvable: readonly UnresolvablePhpEnum[];
+}
+
+/**
+ * git 追跡下の `app/` 配下 `*.php` の一覧 (母集団の単一出典)。
+ * 空を返すのは走査根が壊れているときだけなので fail-fast にする。
+ */
+export const listTrackedPhpFiles = (root: string = REPO_ROOT): readonly string[] => {
+    const appRoot = path.join(root, "app");
+    if (!fs.existsSync(appRoot) || !fs.statSync(appRoot).isDirectory()) {
+        throw new EnumTsSyncError("php-enum-catalog", `走査根が実在しません: ${appRoot}`);
+    }
+    const output = execFileSync("git", ["-C", root, "ls-files", "--", "app/**/*.php"], { encoding: "utf-8" });
+    const files = [...new Set(output.split("\n").map((line) => line.trim()).filter((line) => line !== ""))].sort();
+    if (files.length === 0) {
+        throw new EnumTsSyncError(
+            "php-enum-catalog",
+            "git ls-files が app/ 配下の *.php を 1 件も返しません (走査が空振りしています)",
+        );
+    }
+    return files;
+};
+
+/** 1 ファイル分の分類。母集団に含めないときは `undefined`。 */
+export const classifyPhpFile = (
+    source: string,
+    fileName: string,
+): { readonly kind: "resolved"; readonly name: string; readonly values: ReadonlySet<string> }
+    | { readonly kind: "unresolvable"; readonly reason: string }
+    | undefined => {
+    let headers;
+    try {
+        headers = detectEnumHeaders(source, fileName);
+    } catch (error) {
+        // scan() 自身が拒否する字句 (バッククォート等)。生のソースに enum 宣言らしい並びが
+        // 無ければ母集団から外す。あれば安全側に倒して unresolvable にする。
+        if (LOOSE_ENUM_DECLARATION.test(source)) {
+            return { kind: "unresolvable", reason: error instanceof Error ? error.message : String(error) };
+        }
+        return undefined;
+    }
+
+    if (headers.length === 0) return undefined;
+
+    if (headers.length > 1) {
+        return { kind: "unresolvable", reason: `enum 宣言が ${headers.length} 件あります (母集団を機械的に選べません)` };
+    }
+
+    const backing = headers[0].backing;
+    if (backing === undefined || backing.toLowerCase() !== "string") {
+        // 文字列付き列挙だけが対象 (int backing / backing 無しは母集団に含めない)。
+        return undefined;
+    }
+
+    try {
+        const values = readPhpEnumValuesFromText(source, fileName);
+        return { kind: "resolved", name: headers[0].name, values };
+    } catch (error) {
+        return { kind: "unresolvable", reason: error instanceof Error ? error.message : String(error) };
+    }
+};
+
+/**
+ * PHP の文字列付き列挙の母集団を全数走査する。
+ *
+ * @param root リポジトリルート (負のコントロール用に引数化してある。本番は既定値を使う)
+ */
+export const buildPhpEnumCatalog = (root: string = REPO_ROOT): PhpEnumCatalog => {
+    const resolved: ResolvedPhpEnum[] = [];
+    const unresolvable: UnresolvablePhpEnum[] = [];
+
+    for (const relative of listTrackedPhpFiles(root)) {
+        const absolute = path.join(root, relative);
+        const source = fs.readFileSync(absolute, "utf-8");
+        const classification = classifyPhpFile(source, relative);
+        if (classification === undefined) continue;
+        if (classification.kind === "resolved") {
+            resolved.push({ path: relative, name: classification.name, values: classification.values });
+        } else {
+            unresolvable.push({ path: relative, reason: classification.reason });
+        }
+    }
+
+    return { resolved, unresolvable };
+};
diff --git a/tests/js/support/enum-ts-sync/php-enums.ts b/tests/js/support/enum-ts-sync/php-enums.ts
index 34a5e771..f4f4ee1b 100644
--- a/tests/js/support/enum-ts-sync/php-enums.ts
+++ b/tests/js/support/enum-ts-sync/php-enums.ts
@@ -202,6 +202,45 @@ const CASE_SINGLE = /^case[ \t]+([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*'([^'\\\r\n
 /** 受理する case の書き方 (二重引用符。変数の埋め込みを拒むため `$` も除く)。 */
 const CASE_DOUBLE = /^case[ \t]+([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*"([^"\\$\r\n]*)"[ \t]*;$/i;
 
+/** 深さ 0 のコード状態にある enum 宣言の頭 1 件分。 */
+export interface EnumHeader {
+    /** `enum` の直後に書かれた名前。 */
+    readonly name: string;
+    /** `:` の後ろの backing 型 (無ければ `undefined`)。 */
+    readonly backing: string | undefined;
+    /** 宣言の頭の開始位置 (無害化した写し上のオフセット)。 */
+    readonly offset: number;
+    /** 宣言の頭 (`enum Name: backing` の部分) の文字数。 */
+    readonly headerLength: number;
+}
+
+/**
+ * `scan()` の結果から、深さ 0 のコード状態にある enum 宣言の頭を**件数の制約なしに**列挙する。
+ * `readPhpEnumValuesFromText` (ちょうど 1 件・string backing を要求) と
+ * `php-enum-catalog.ts` (全数走査。件数を問わず候補として拾う) の両方から使う共通の入口であり、
+ * 2 本目の字句走査器を作らないための分割である。
+ */
+const scanEnumHeaders = ({ sanitized, isCode, depth }: ScanResult, where: string): readonly EnumHeader[] => {
+    const headers: EnumHeader[] = [];
+    const enumToken = enumTokenRe();
+    for (let m = enumToken.exec(sanitized); m !== null; m = enumToken.exec(sanitized)) {
+        if (isCode[m.index] !== 1 || depth[m.index] !== 0) continue;
+        const header = ENUM_HEADER.exec(sanitized.slice(m.index));
+        if (header === null) throw new EnumTsSyncError(where, "enum 宣言の頭を読めません");
+        headers.push({ name: header[1], backing: header[2], offset: m.index, headerLength: header[0].length });
+    }
+    return headers;
+};
+
+/**
+ * 深さ 0 の enum 宣言の頭を件数の制約なしに読む (母集団の全数走査専用)。
+ * `readPhpEnumValuesFromText` と同じ `scan()` を使うので、抽出器が 2 本に分岐しない。
+ */
+export const detectEnumHeaders = (source: string, fileName: string): readonly EnumHeader[] => {
+    const { sanitized, isCode, depth } = scan(source, fileName);
+    return scanEnumHeaders({ sanitized, isCode, depth }, fileName);
+};
+
 /**
  * PHP の文字列付き列挙の値集合を読む (本体)。
  *
@@ -216,24 +255,17 @@ export const readPhpEnumValuesFromText = (source: string, fileName: string): Rea
     }
     const stem = base.slice(0, -".php".length);
 
-    const { sanitized, isCode, depth } = scan(source, where);
+    const scanResult = scan(source, where);
+    const { sanitized, isCode, depth } = scanResult;
 
     // 1. 深さ 0 の enum 宣言がちょうど 1 つ
-    const enumOffsets: number[] = [];
-    const enumToken = enumTokenRe();
-    for (let m = enumToken.exec(sanitized); m !== null; m = enumToken.exec(sanitized)) {
-        if (isCode[m.index] === 1 && depth[m.index] === 0) enumOffsets.push(m.index);
-    }
-    if (enumOffsets.length === 0) throw new EnumTsSyncError(where, "enum 宣言が見つかりません");
-    if (enumOffsets.length > 1) {
-        throw new EnumTsSyncError(where, `enum 宣言が ${enumOffsets.length} 件あります`);
+    const headers = scanEnumHeaders(scanResult, where);
+    if (headers.length === 0) throw new EnumTsSyncError(where, "enum 宣言が見つかりません");
+    if (headers.length > 1) {
+        throw new EnumTsSyncError(where, `enum 宣言が ${headers.length} 件あります`);
     }
 
-    const headerOffset = enumOffsets[0];
-    const header = ENUM_HEADER.exec(sanitized.slice(headerOffset));
-    if (header === null) throw new EnumTsSyncError(where, "enum 宣言の頭を読めません");
-    const enumName = header[1];
-    const backing = header[2];
+    const { name: enumName, backing, offset: headerOffset, headerLength } = headers[0];
     if (backing === undefined) {
         throw new EnumTsSyncError(where, "backing 型がありません (string 付きの列挙だけを受理します)");
     }
@@ -248,7 +280,7 @@ export const readPhpEnumValuesFromText = (source: string, fileName: string): Rea
 
     // 3. 本体の範囲を取る
     let bodyStart = -1;
-    for (let i = headerOffset + header[0].length; i < sanitized.length; i += 1) {
+    for (let i = headerOffset + headerLength; i < sanitized.length; i += 1) {
         if (isCode[i] === 1 && sanitized[i] === "{") {
             bodyStart = i;
             break;
diff --git a/tests/js/support/enum-ts-sync/reverse-sweep.ts b/tests/js/support/enum-ts-sync/reverse-sweep.ts
new file mode 100644
index 00000000..7895952a
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/reverse-sweep.ts
@@ -0,0 +1,95 @@
+/**
+ * 逆走査 (裁定 AG-099 後半)。
+ *
+ * `enum-ts-sync.test.ts` は「目録に登録した写しについて PHP → TS を見る」向きの検査なので、
+ * **登録し忘れた写し**は素通りする。本モジュールは向きを変え、TS 側の型別名の候補
+ * (`collectTsUnionCandidates`) と PHP の文字列付き列挙の母集団 (`buildPhpEnumCatalog`)
+ * を突き合わせ、次の 2 規則で「未登録だが対応していそうな組」を検出する。
+ *
+ * - **規則 1 (完全一致)**: 値集合が PHP 列挙と完全に一致する未登録の TS 宣言。
+ *   これは「登録を忘れているだけ」の可能性が高い最有力候補である。
+ * - **規則 2 (名前対応 + 値の交差)**: 型別名の名前が PHP 列挙名と厳密に対応し
+ *   (一致 / 複数形接尾辞 `s` `es` `values` の付加)、かつ値集合が交差するが**完全一致ではない**
+ *   未登録の TS 宣言。これは「かつて対応していたが、どちらか片方だけ値を足して
+ *   ズレた写し」を拾うためのもので、規則 1 に緩い部分集合や名前無視の条件を混ぜると
+ *   誤検出が支配的になる (家系の実測: 緩い形は偽陽性 80〜100%)。
+ *
+ * **これは「登録漏れが無いことの証明」ではなく「候補の検出」である**。
+ * 名前も対応せず値も完全一致しない drift 済みの写しは検出できない (意図した限界)。
+ */
+import type { ResolvedPhpEnum } from "./php-enum-catalog";
+import type { TsUnionCandidate } from "./ts-candidates";
+
+export interface UnregisteredMirrorCandidate {
+    readonly rule: 1 | 2;
+    readonly php: ResolvedPhpEnum;
+    readonly candidate: TsUnionCandidate;
+    /** 規則 1 は `null`。規則 2 は名前の対応関係の説明 (メッセージ用)。 */
+    readonly nameMatch: string | null;
+}
+
+/** 小文字化 + 英数字以外の除去。 */
+const normalizeName = (name: string): string => name.toLowerCase().replace(/[^a-z0-9]/g, "");
+
+/** `\` 区切りの FQCN から短い名前を取る。 */
+export const shortEnumName = (path: string): string => {
+    const base = path.split("/").pop() ?? path;
+    return base.endsWith(".php") ? base.slice(0, -".php".length) : base;
+};
+
+/** 厳密な名前対応 (一致 / +s / +es / +values)。対応しなければ `null`。 */
+const nameCorrespondence = (candidateName: string, enumName: string): string | null => {
+    const candidate = normalizeName(candidateName);
+    const target = normalizeName(enumName);
+    if (candidate === target) return `${target} = ${candidate}`;
+    for (const suffix of ["s", "es", "values"]) {
+        if (candidate === `${target}${suffix}`) return `${target} + "${suffix}" = ${candidate}`;
+    }
+    return null;
+};
+
+const sameValueSet = (a: ReadonlySet<string>, b: ReadonlySet<string>): boolean => {
+    if (a.size !== b.size) return false;
+    for (const value of a) if (!b.has(value)) return false;
+    return true;
+};
+
+const intersects = (a: ReadonlySet<string>, b: ReadonlySet<string>): boolean => {
+    for (const value of a) if (b.has(value)) return true;
+    return false;
+};
+
+/**
+ * 未登録のミラー候補を検出する。
+ *
+ * @param phpEnums   母集団のうち値集合が読めた PHP 列挙 (`resolved`)。
+ * @param candidates TS 側の型別名の候補。
+ * @param isRegistered `(file, name)` の組が既に目録に登録済みかを判定する述語
+ *                      (登録済みは検査対象から外す)。
+ */
+export const findUnregisteredMirrorCandidates = (
+    phpEnums: readonly ResolvedPhpEnum[],
+    candidates: readonly TsUnionCandidate[],
+    isRegistered: (file: string, name: string) => boolean,
+): readonly UnregisteredMirrorCandidate[] => {
+    const found: UnregisteredMirrorCandidate[] = [];
+
+    for (const candidate of candidates) {
+        if (isRegistered(candidate.file, candidate.name)) continue;
+
+        for (const phpEnum of phpEnums) {
+            if (sameValueSet(phpEnum.values, candidate.values)) {
+                found.push({ rule: 1, php: phpEnum, candidate, nameMatch: null });
+                continue;
+            }
+
+            const correspondence = nameCorrespondence(candidate.name, shortEnumName(phpEnum.path));
+            if (correspondence === null) continue;
+            if (!intersects(phpEnum.values, candidate.values)) continue;
+
+            found.push({ rule: 2, php: phpEnum, candidate, nameMatch: correspondence });
+        }
+    }
+
+    return found;
+};
diff --git a/tests/js/support/enum-ts-sync/ts-candidates.ts b/tests/js/support/enum-ts-sync/ts-candidates.ts
new file mode 100644
index 00000000..77b4ccd1
--- /dev/null
+++ b/tests/js/support/enum-ts-sync/ts-candidates.ts
@@ -0,0 +1,77 @@
+/**
+ * `resources/js/` 配下にある**文字列リテラル型だけの union に解決する型別名**を
+ * 全数走査する (裁定 AG-099 後半 / 逆走査の入力)。
+ *
+ * `readTsUnionValues` (`ts-value-sets.ts`) は「目録に登録した 1 つの宣言」を読む検査で、
+ * 受理できない形は例外にして呼び出し側の登録ミスを知らせる。本モジュールは向きが逆で、
+ * **プログラム全体から候補を拾う**ので、受理できない型別名は例外にせず**黙って読み飛ばす**
+ * (「型別名だが対象にならない」は前者では失敗、後者では単に非対象という違いである)。
+ *
+ * **保証しないもの**: 対象は `resources/js/` 配下の `.ts` ファイルのトップレベルにある
+ * `type X = …` 宣言だけ。`.svelte` の中の宣言・定数配列・switch の case ラベル・
+ * ネストした (トップレベルでない) 型別名は対象外。
+ */
+import ts from "typescript";
+import path from "node:path";
+import { REPO_ROOT, type MirrorProgram } from "./program";
+
+export interface TsUnionCandidate {
+    /** リポジトリルートからの相対パス。 */
+    readonly file: string;
+    /** 型別名の名前。 */
+    readonly name: string;
+    readonly values: ReadonlySet<string>;
+}
+
+/** `root` の配下にあるか (区切り文字まで含めて見る。兄弟ディレクトリを通さない)。 */
+const isUnder = (absolute: string, root: string): boolean => absolute === root || absolute.startsWith(root + path.sep);
+
+/** 解決した型が文字列リテラル型だけの union (または単独) なら値集合を返す。それ以外は `undefined`。 */
+const tryReadStringLiteralUnion = (checker: ts.TypeChecker, alias: ts.TypeAliasDeclaration): ReadonlySet<string> | undefined => {
+    const symbol = checker.getSymbolAtLocation(alias.name);
+    if (symbol === undefined) return undefined;
+
+    const declared = checker.getDeclaredTypeOfSymbol(symbol);
+    const parts = declared.isUnion() ? declared.types : [declared];
+
+    const values = new Set<string>();
+    for (const part of parts) {
+        if ((part.flags & ts.TypeFlags.EnumLiteral) !== 0) return undefined;
+        if (!part.isStringLiteral()) return undefined;
+        values.add(part.value);
+    }
+    if (values.size === 0) return undefined;
+    return values;
+};
+
+/**
+ * `resources/js/` 配下の全 `.ts` ファイルから、文字列リテラル型だけの union に解決する
+ * トップレベルの型別名をすべて拾う。
+ *
+ * @param jsRoot 走査根 (既定は `resources/js`。負のコントロール専用の引数)
+ */
+export const collectTsUnionCandidates = (
+    { program, checker }: MirrorProgram,
+    jsRoot: string = path.join(REPO_ROOT, "resources", "js"),
+): readonly TsUnionCandidate[] => {
+    const candidates: TsUnionCandidate[] = [];
+
+    for (const source of program.getSourceFiles()) {
+        if (source.isDeclarationFile) continue;
+        if (!isUnder(source.fileName, jsRoot)) continue;
+        if (program.getSyntacticDiagnostics(source).length > 0) continue;
+
+        for (const statement of source.statements) {
+            if (!ts.isTypeAliasDeclaration(statement)) continue;
+            const values = tryReadStringLiteralUnion(checker, statement);
+            if (values === undefined) continue;
+            candidates.push({
+                file: path.relative(REPO_ROOT, source.fileName).split(path.sep).join("/"),
+                name: statement.name.text,
+                values,
+            });
+        }
+    }
+
+    return candidates;
+};


## テスト結果

- `pnpm exec vitest run tests/js/architecture/enum-ts-sync.test.ts tests/js/architecture/enum-ts-sync-extractor.test.ts tests/js/architecture/enum-ts-sync-discovery.test.ts tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts`
  → 4 files / 154 tests passed
- `pnpm test` (全体) → 169 files / 2267 tests passed
- `pnpm typecheck` → エラー無し
- `composer test` (実行中。結果は後続ラウンドで報告する)
- 故障注入 (手動): PHP_ENUM_EXEMPTIONS から 1 行削除 → 赤 / REVERSE_SWEEP_EXEMPTIONS を
  空にする → 赤 / KNOWN_UNRESOLVABLE_PHP_ENUMS から 1 行削除 → 赤、をそれぞれ確認し
  元に戻した (全部緑に復帰)。

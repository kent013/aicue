# Round 2: Round 1 指摘への対応と改訂版詳細設計

Critical 1 件・Warning 9 件・Suggestion 3 件のすべてに対応した。主な変更は 6 点 —
(1) PHPStan の offset / クロージャ型を修正、(2) アンカーの**ちょうど 1 件**検査と
**表の連続性**検査を追加(表の切り詰め・分割をその場で落とす)、(3) 根拠の無い母集団下限
`INTEGRATION_GUIDE_GATE_FLOOR = 50` を**削除**、(4) 実施順を「最小の抽出器 + 負例を先に赤くする」
順へ組み替え、(5) 負例を 8 形 + アンカー 2 形へ拡充、(6) D40 の還流済み前提との矛盾を解消し
メタ表セルを 2 文へ圧縮、削除の判断基準を追加。

再レビューをお願いする。施策ごとの判定と全体判定 (APPROVED / CHANGES_REQUESTED) を明示すること。

---

## 対応マトリクス

# 対応マトリクス: design-review Round 1

## 施策 1

### [Warning] `(§7b)` が「§7 は番号ではなく項目名で参照する」という直前の規約と矛盾

- 判断: 対応する
- 根拠: 規約の本来の対象は **§7 の不変条件の番号(1〜10)**であり、`§7b` は節の見出しに含まれる
  固有名詞なので厳密には違反ではない。ただし直前に規約を書いた表の中で番号らしい参照が出るのは
  読み手を迷わせるので、指摘の趣旨(曖昧さの除去)を採る。
- 対応内容: 表 B の該当セルを「(下の「流量制限の付与規約」の節)」へ改めた。D40 の本文でも
  `§7b` / `§7c` の表記を落とし、節の名前(「流量制限の付与規約」/「vendor route への後付け機構と
  経路キャッシュの契約」)で書いた。併せて、§2 に置く注意書きに
  「規約が禁じるのは**不変条件の番号**での参照である」ことを 1 行で明示した。

### [Suggestion] 対象定義と 8 ゲートの意味的対応は同期検査が見ない

- 判断: 採用する(記述の追加のみ)
- 対応内容: 施策 1 のリスク節に「対象定義(実装単位の宣言)と 8 件の対応は機械では見ない。
  本文を変えるときは対象定義もレビュー対象である」を明記した。

## 施策 2

### [Critical] PHPStan level 10 で落ちる未絞り込み(`integrationGuideCells($header)[0]` / クロージャの `: array`)

- 判断: **対応する**（指摘は正しい。`list<string>` に非空の保証は無く、`|` だけのヘッダ行では
  実行時にも未定義 offset になる。`fn (): array` は要素型の無い `array` を返す形で level 10 が落ちる）
- 対応内容:
  1. ヘッダ行のセルを変数へ受け、**`count() < 3` を先に検査**してから `[0]` を参照する。
     データ行側も既に列数検査を先に置いていたが、同じ順序であることを明示した。
  2. `toThrow` へ渡すクロージャは**戻り値型 `void` のブロッククロージャ**にし、
     戻り値を捨てる形に統一した(`static function (): void { integrationGuideGateNames(...); }`)。
  3. `$matches[1]` は `preg_match(...) === 1` の枝の中だけで参照する形を維持したうえで、
     `Assert::keyExists` + `Assert::string` で明示的に絞った。

### [Warning] 同じアンカーが複数あっても最初の 1 件で緑になる

- 判断: 対応する
- 対応内容: アンカーの出現数を数え、**ちょうど 1 件でなければ例外**にした
  (0 件 = 無い / 2 件以上 = 重複でメッセージを分ける)。負例に「アンカーが 2 件」を追加。

### [Warning] `glob()` + `basename` だけでは `.php` で終わるディレクトリも数える。ディレクトリの可読性も未検証

- 判断: 対応する
- 対応内容: 走査前に `Assert::directory` / `Assert::readable` を掛け、各パスを **`is_file()`** で
  絞る。`.php` で終わるディレクトリを母集団に入れないことを docblock に明記した
  (明記した範囲について検出力を主張しない = 走査器共通規約 (b) の作法)。

### [Warning] `INTEGRATION_GUIDE_GATE_FLOOR = 50` は不変条件と結び付かない閾値

- 判断: **対応する(定数を削除する)**
- 根拠: 指摘のとおり、60 → 51 の大量欠落を通し、正当な統廃合で 49 になれば落ちる。
  根拠の無い数字であり、AGENTS.md が「値を弄る前に仕組みを見よ」と定める対象そのものである。
  ゲート母集団の全体件数は本件の不変条件ではない(本件の不変条件は**表に載せた 21 件が実在すること**)。
- 対応内容: `INTEGRATION_GUIDE_GATE_FLOOR` を削除し、空振り検査を
  「母集団が非空 + 代表ゲート 2 本が母集団に在る + 表に載せた全ゲートが実在する(正常系の検査で
  既に見ている)」の 3 点へ置き換えた。

### [Warning] テストファーストの実施順が AGENTS.md と一致していない

- 判断: **対応する**(指摘は正しい。「本体を完成させてから負例を足し、後で壊して赤を見る」は
  規約の「先に赤くしてから本体を書く」ではない。また正規表現を緩めるだけでは正例は赤にならない)
- 対応内容: 実施順を組み替えた —
  (1) **最小の抽出器**(アンカー検索 + `|` 行の収集 + 1 列目をそのまま返す)と負例テスト群を先に置く。
  この時点で負例は**受理されてしまうので赤**になる。
  (2) fail-closed 分岐を 1 つずつ実装して負例を緑にする。
  (3) 正例テストを置いて誤検出しない側を固定する。
  (4) 実文書を読む 2 本を置く(施策 1 がまだ無いのでアンカー不在の例外で赤)。
  (5) 施策 1 の本文を挿入して緑にする。
  (6) 施策 3(突合 gate の赤を確認してから付け替える)。

### [Warning] 「実行不能」の全列挙に対して負例が不足(壊れたヘッダ / 壊れた区切り / アンカー重複 / 表途中の不正行)

- 判断: 対応する
- 対応内容: 負例を **8 形**へ増やした — バッククォート欠落 / ゲート列が空 / パス表記 /
  末尾が `Test` でない / 列が足りない / **ヘッダの 1 列目が「ゲート」でない** /
  **区切り行が不正** / **表が 2 か所に分かれている**。加えてアンカー 0 件・2 件の 2 形。
  「表途中の不正行を黙って終端として扱わない」への対処として、抽出規則を強化した —
  **アンカーの領域(次の見出し行までの範囲)の中で `|` で始まる行はすべて 1 つの連続ブロックに
  属さなければならない**。ブロックが閉じた後に `|` 行が現れたら例外にする
  (件数 pin だけに頼らず、切り詰めそのものを落とす)。

### [Suggestion] 「新規テスト 6 ケース」が宣言数 7 と一致しない

- 判断: 採用する
- 対応内容: 「**7 個の `test()` 宣言 / dataset 展開後 16 シナリオ**」と分けて書いた。

### [Suggestion] docblock と本文の重複の言い方

- 判断: 採用する
- 対応内容: 本文の注意書きを「**保証しないものの詳細の正本は同期検査の docblock**」という
  参照の形へ改め、docblock 側の「本文には写さない」も「詳細は写さない」へ揃えた。

## 施策 3

### [Warning] D40 の比較表「テンプレートは独自節を持たない」が「T121/T132 で還流完了済み」と矛盾

- 判断: **対応する**(指摘は正しい。台帳の事実関係と食い違う記述だった)
- 対応内容: 該当行を書き換えた — テンプレート側は「裁定 AG-116 に基づき還流済みの 3 節を持つ
  (`T121` / `T132`)」、本アプリ側は「その 3 節に加えて『流量制限の付与規約』と
  『vendor route への後付け機構と経路キャッシュの契約』の 2 節を持つ(台帳が
  AG-116 の名指しした 3 節の外と明記)」とした。テンプレート現物を参照できない以上、
  **台帳で確認できる範囲を超えた断定をしない**という原則も本文に添えた。

### [Warning] メタ表「業務要件起因の説明」が規約の「1〜2 文」を超え、因果も過剰

- 判断: 対応する
- 対応内容: セルを **2 文**へ圧縮し、詳細(独自節の由来・行数・写像の性質)は後続の
  「なぜ正当な差分か」へ移した。理由も「本アプリ固有のセキュリティ境界と実在ゲートへの写像」へ
  限定した。

### [Warning] logic-driven の根拠が弱い / 「収束しない」という恒久差分寄りの表現

- 判断: 対応する
- 根拠: 登録簿の記録の原則が「判定軸は同じ不変条件を同じタイミング / 抽象度で保証するか」で
  あることを踏まえると、「実装名が違うだけ」では逸脱の根拠として弱い。本件が logic-driven なのは
  **索引が指す先が本アプリのゲート実体であり、実在しない名前を指す索引は無価値である**という点と、
  独自節が実測の監査所見に由来する点である。
- 対応内容: (a)「採用時点にもテンプレートにも収束しない」という表現を落とし、
  **「テンプレート現物が届くまでの監視中の登録」**であることを前面に出した。
  (b) **削除の判断基準**を明記した — 合成版の現物が配られ、その §2 の 2 表と本アプリの 2 表が
  「同じ判定を同じ抽象度で要求している」と確認できたら、D40 は削除して指紋の一致へ戻す
  (または対象パスから 1 行を外す)。(c) 状態は `監視中` のまま、見直し期限も 2027-02-28 のまま。


---

## 改訂版 詳細設計(全文)

# 詳細設計: integration-guide-gate-table-restore

## 使命・制約(絶対遵守)

### アプリの使命(North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて
   「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本設計での該当**: 4〜8 は HTTP 境界・UI・LLM 経路を一切変えないため発生しない。
1 は施策 2(同期検査の新設)が担う。2 は施策 2 の PHPStan 適合チェックで担保する。
9 は本設計の成果物をすべて `devnotes/` 配下のファイルとして出力することで守る。

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`)
- **Pest** テストフレームワーク(`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行(`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止)。**本件の新規テストは DB を使わない**静的検査であり、
  既存 Architecture テストと同じ作法(`TestCase` のみ)で置く
- テストデータは Factory 経由(本件は DB を使わないため該当なし)
- `declare(strict_types=1)` + 日本語コメント(`StrictTypesDeclarationGateTest` が
  git 追跡下の PHP 全数に deny-by-default で強制。免除の登録簿は持たない)
- アーリーリターン推奨 / `composer fix`(Pint) / PHP 8.4 + Laravel 12

## 概念設計リファレンス

- `devnotes/20260822-2305-integration-guide-gate-table-restore/conceptual-design.md`(APPROVED / Round 4)

---

## 正典(lctl feature `app-integration-guide`)の不変条件 — 全列挙と本設計での扱い

台帳の `gates` に登録されている不変条件は 5 本である。**1 本ずつ、本設計が壊さないことを示す。**

| # | 正典の不変条件(要旨) | 本設計での扱い |
|---|---|---|
| G1 | 契約文書が実在するのはテンプレートから生成された 3 リポジトリだけであり、git オブジェクトの実在で受け取り方が 3 段階に分かれる。テンプレート乖離台帳を持つかの分かれ目もこの線と完全に一致する | **触らない**。aicue は「初期コミットで中身だけを持ち込んだ」側であり、本設計はその文書の §2 に節を足すだけで、受け取り方の分類にも乖離台帳の有無にも影響しない |
| G2 | **設計時に §2〜§7 の判定を踏んだかどうかを確かめる機械は家系のどこにも無い**。文書そのものに掛かる機械は 3 本(テンプレートの指紋照合 / motivation の §7 doc-sync / テンプレートの §7 doc-sync)で、いずれも別目的である | **この結論を変えない**。施策 2 が足すのは「§2 の表が指すゲート名の実在・件数・一意性」までで、**設計者が判定を踏んだかは見ない**。この保証範囲を docblock に明記し、本文にも書かない(誇張しない) |
| G3 | 文書ファイルをコピーするだけでは到達経路が生まれない。規約ファイル・README・初期化スクリプト・文書更新スキル・ソースコメント・アーキテクチャ文書からの導線が揃って初めて設計者が文書へ届く。**件数は増え続けるため固定値としては扱わない** | **既存の導線を壊さない**。AGENTS.md 実装規約の「新規リソースの追加手順は §2」という名指しは既に在り、本設計はその参照先の中身を充実させる。導線の件数を新たに pin する検査は**作らない**(正典が固定値として扱わないと明記しているため) |
| G4 | 版の分岐は文書の中だけの話で終わらない。§7 の不変条件は各リポジトリの規約ファイル・ソースコメントから**番号で**参照されており、3 者で番号と中身の対応が崩れている | **番号に触らない**。表から §7 を参照するときは**項目名**で指す(AGENTS.md の採番注意「相互参照するときは番号ではなく項目名で指すこと」「どちらの側も renumber しない」に従う)。§7 の番号体系の統一はスコープ外 |
| G5 | `motivation:tests/Architecture/SecurityInvariantDocSyncTest.php` が契約文書 §7 と AGENTS.md の相互整合(番号体系の一致 / 締め文の数字 / 言及されたゲート名の実在)を機械照合する。テンプレートが同名 gate を移植して家系 2 本目になった | **移植しない**(スコープ外)。ただし家系が価値を認めている「言及されたゲート名の実在」の部分だけを、**§2 の 2 表に限って**施策 2 が持つ。§7 全体・AGENTS.md との相互整合・締め文の数字は対象にしない |

### 裁定 AG-116(2026-08-08)の要求と本設計の対応

> 配る中身は **(b) 合成版**とする — テンプレート現行版へ aicue の独自 3 節を還流し、
> **aicue が失った §2 ゲート表 2 本(34 行)を復帰させた統合版**を家系の正として配る。
> 合成版の作成はテンプレート側の還流タスク。

- テンプレート側の還流 3 節は `T121` / `T132` で完了済み(台帳のテンプレートセルは `implemented (t2)`)。
- 台帳は「aicue が失った §2 ゲート表 2 本の復帰」を **aicue 側の取り込み作業**と名指しし、
  テンプレートセルの担当から外している。**本設計はその 1 点だけを扱う。**
- **完了は宣言しない**。台帳セルの status を上げる判断はキュレーターの巡回の責務である。

### 正典 boundary が定める §2 の規定範囲(本設計が満たす部分)

boundary の (2) は §2 を次のように記述する — 「新しいエンティティをどのテナント層へ置くかの
決定手順 4 段」+「決めた後に機械的に従う 6 項」+「見本リソースの実装ファイル対応表 21 行」+
**「新規リソースで必ず踏む Architecture ゲート表 8 本」**+**「条件付きで発火するゲート表 13 本」**。

aicue には前 3 者が実在し、後 2 者が欠落している(boundary の (4) が
「結果として §2 のゲート表 2 本(計 34 行)が aicue には存在せず」と明記)。
**本設計が埋めるのは後 2 者だけである。**

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | §2 へゲート表 2 本を追加する(逐語復元ではなく判定規準としての写像) | `docs/app-integration-guide.md` | 高 |
| 2 | 表が指すゲート名の実在・件数・一意性を固定する同期検査を新設する | `tests/Architecture/IntegrationGuideGateTableSyncTest.php`(新規) | 高 |
| 3 | 乖離台帳の付け替え(D40 新設 / 採用時債務から 1 行削除 / 件数 pin 更新) | `docs/template-divergence.md` / `tests/Support/TemplateDivergence/adoption-debt.tsv` / `tests/Support/TemplateDivergence/LedgerPins.php` | 高(施策 1 と不可分) |

**実施順(テストファースト。思考原則 5 / 走査器を新設するときに揃える 4 点の 1 番目)**:

1. 施策 2 の**最小の抽出器 + 負例テスト群**を置く(負例が受理されて**赤**)
2. fail-closed 分岐を 1 つずつ実装して負例を緑にし、正例で誤検出しない側を固定する
3. 施策 2 の**実文書を読む 2 本**を置く(施策 1 が無いのでアンカー不在の例外で**赤**)
4. 施策 1 の本文を挿入して緑にする
5. 施策 3 —— まず突合 gate が `mutatedDebtPaths` で**赤**になることを確認し、その後に付け替える

詳細は各施策のテスト計画に書く。

---

## 施策 1: §2 へゲート表 2 本を追加する

### 変更箇所

- ファイル: `docs/app-integration-guide.md`
- 挿入位置: **L93 の直後・L95(`## 3. ロール・権限のマッピング`)の直前**
  (§2 の「見本: Item リソース」の注意点 3 項の後)
- 既存の 21 行の対応表(L62-82)と注意点(L84-93)は**1 文字も変えない**

### 波及変更

- TypeScript 型定義: なし(フロント・API・DTO を触らない)
- API Resource/DTO: なし
- テストファイル: 施策 2 の新規テストが本文を走査するため、**施策 2 とセットでしか意味を持たない**
- 他ドキュメント: なし。AGENTS.md 実装規約の「新規リソースの追加手順は §2 のチェックリスト」は
  節名で参照しており、表の追加で参照が壊れない(**AGENTS.md は変更しない**)

### 現行コード(該当箇所の末尾)

```markdown
- 子リソースの作成は親 FK を **relation 経由で代入**する
  (`$project->items()->create([...])`)。FK の mass assignment を書かない。

## 3. ロール・権限のマッピング
```

### 変更後コード(挿入する本文の全文)

```markdown
- 子リソースの作成は親 FK を **relation 経由で代入**する
  (`$project->items()->create([...])`)。FK の mass assignment を書かない。

### 新規リソースで踏む Architecture ゲートの索引

下の 2 表は「新しいドメインリソースを足すとき、どの Architecture ゲートが発火し、
何をどこへ登録しなければならないか」の索引である。上の対応表(見本 Item のどのファイルか)とは
軸が違うので併存させる。**§7 の不変条件を参照するときは番号ではなく項目名で指す**
(本書 §7 と AGENTS.md のセキュリティ不変条件は番号が 1:1 対応しないため。
禁じているのは不変条件の番号での参照であって、節の名前を使うことではない)。

> **この索引の性質**: 家系の裁定 AG-116 が定めた合成版の一部として復帰させた表である。
> テンプレート現物を参照できないため**逐語復元ではなく、本アプリの実在ゲートへ写した
> 判定規準**である(テンプレート側の還流も同じ作法を採っている)。
> 網羅性は主張しない — ここに無いゲートが発火しないという意味ではない。
> **保証しないものの詳細の正本は `tests/Architecture/IntegrationGuideGateTableSyncTest.php` の
> docblock** である(ここには写さない)。

#### 新規リソースで必ず踏む Architecture ゲート

**対象は「§2 の手順で Project 配下に *書き込み可能な* ドメインリソースを 1 つ足すこと」**
= 見本 Item と同型の実装単位である。この単位は定義上、マイグレーション(親 FK `constrained()` +
NOT NULL)/ Model / Factory / FormRequest(store・update)/ nested route(変更系を含む)/
親 Policy 経由の認可 / Feature テスト を必ず持つので、下の 8 本は条件なしに発火する。

この単位から外れる形(読み取り専用リソース / 画面だけの追加 / 組織直下に置くマスタデータ)を
足すときは、**該当しない行を設計書で名指しして外す理由を書く**。黙って外さない。

| ゲート | 何を落とすか | 何をどこへ登録するか |
|---|---|---|
| `MassAssignmentSafetyTest` | ownership / actor / tenant / secret キーが Model の `$fillable` に載ること | 登録簿は無い。新 Model の親 FK を `$fillable` に入れず、relation 経由か明示代入で書く |
| `FormRequestProhibitedKeyTest` | FormRequest が `ProhibitsProtectedKeys` を欠くこと / 保護キーの missing rule が無いこと | 新 FormRequest に trait を適用し `rules()` に missing を書く。アプリ固有の新 FK 名は `app/Support/Security/MassAssignmentProtectedKeys.php` へ追記 |
| `ValidationAttributeCoverageTest` | `rules()` のキーが利用者向け文言の attributes に無いこと(生のキー名が画面に出る) | `lang/ja/validation.php` の attributes に新しいキーを追記 |
| `ProjectRouteCurrentOrgGuardTest` | `{project}` を受ける route が middleware 層の URL 整合 guard を欠くこと(FormRequest の DB ルールが先に走り 422 と 404 の差が存在オラクルになる) | web は `project.in-route-org`、API は `api.project-in-org` を route group に付ける |
| `NestedRouteIdorDefenseTest` | param 付き route の防御方式が未分類・stale・無記名であること | `tests/Architecture/NestedRouteIdorDefenseTest.php` が読む inventory へ **parameter 単位**で `NestedRouteDefenseMode` を登録。テナント親子でない param は理由を `nonTenantReasons()` に書く |
| `TenantBoundaryOrderingTest` | テナント境界の 404 が binding の直後で閉じていないこと(1 bit の存在オラクル) | 新しい middleware を足したら `middlewareShortCircuitInventory()` に「短絡しうるか」を分類する(疑わしきは `true` 側)。`SubstituteBindings` より前に置くなら `preBindingShortCircuitInventory()` にも登録 |
| `RouteBindingTypeConstraintInventoryTest` | binding param が型分類の目録に無く、型不一致が 404 ではなく生 500 になること | `RouteBindingTypes` の 5 分類のいずれかへ登録し、分類に応じた制約を route に付ける |
| `ControllerAuthorizationGateTest` | 変更系(POST/PUT/PATCH/DELETE)ハンドラが認可判断を 1 度も通らないこと | ハンドラ冒頭(URL 整合 guard の**後**)に `Gate::authorize`。認可が不要なら exemption inventory へ enum + 「何が代わりに守っているか」を 30 文字以上で登録 |

#### 条件付きで発火するゲート

リソースの性質や付随機能が加わって初めて母集団に入るもの。

| ゲート | 発火条件 | 何をどこへ登録するか |
|---|---|---|
| `ModelDirectFetchInvariantTest` | route parameter **以外**の id 受け口(POST payload / query string / MCP tool 引数 / token claim / queue payload)を足すとき | まず relation 起点(`$organization->users()->whereKey($id)`)で書けないか検討する。書けないときだけ `DirectFetchInventory` へ `DirectFetchJustification` + 30 文字以上の具体的根拠 + case ごとの構造化 field を登録 |
| `ThrottleCoverageInventoryTest` | 保護対象群(未認証で到達しうる変更系 / 機械向け経路 `api/`・`oauth/`・`.well-known/oauth-` / 認証面の変更系)に route が入るとき | throttle をちょうど 1 本持たせるか、`ThrottleCoverageExemption` + 30 文字以上の根拠で登録(下の「流量制限の付与規約」の節) |
| `ThrottleLaneAssignmentTest` | named レーンへ route を割り当てるとき | レーンごとの route 目録へ登録する(どの route がどのレーンに属するかを固定する) |
| `IdempotentRouteCoverageTest` | `api/v1/*` に変更系 route を足すとき | `idempotent` middleware をちょうど 1 本持たせるか、型付き分類 + 30 文字以上の根拠で免除登録 |
| `ApiGuardAllowlistInvariantTest` | REST API v1 の endpoint を足すとき | guard 分類(dual / oauth / public)を宣言表へ登録 |
| `McpAuthorizationChokePointTest` | MCP tool を足すとき | 認可の関門を業務処理より前に通し、結果を捨てない。書き込み tool を足すときは冪等キーの必須化も同時に要る(別ゲートが trip-wire として発火する) |
| `QuotaKeyConfigInvariantTest` | 上限(Quota 項目)を足すとき | `config/quota.php` の limits キーと `App\Enums\QuotaKey` の case を**対で**足す |
| `PromptGuardrailTest` | LLM 呼び出しを足すとき | `app/Prompts/` の factory → 窓口 → 実行単位の 1 本道で書く(vendor 直呼びを作らない)。prompt 文字列は `resources/prompts/*.yaml` に置く |
| `PromptUntrustedInputContractTest` | untrusted 文字列を prompt に入れるとき | 窓口 `App\Support\Llm\PromptDefense` 経由にし、inventory へ帰属キーを登録(帰属の対象を持たない見本だけが空配列で exempt 登録できる) |
| `CachePayloadPlainDataGateTest` | キャッシュ書き込み経路を足すとき | 素のデータ(配列 / 文字列 / 数値 / 真偽値 / `null`)だけを入れ、書き込み経路を目録へ登録する。読み戻しは `fromArray()` 等で組み立て直して検査し、失敗したら `forget` する |
| `SsrfPinBoundaryTest` | 外部 URL(特にユーザ入力由来)を取得するとき | `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。安全境界は `config/ssrf-pin.php` に pin する |
| `DocumentTitleCoverageTest` | Inertia を render する GET named route を足すとき | ページ固有のタイトルを controller 供給メタか `config/seo.php` に持たせる(無いとサイト名だけになる) |
| `InertiaRenderPageExistsInvariantTest` | 新しいページコンポーネントを足すとき | `resources/js/pages/` に実体を置く(literal 参照と 1:1。参照先が無いページは本番で白画面になる) |

## 3. ロール・権限のマッピング
```

### 表の設計上の決めごと(施策 2 の走査契約と対になる)

- **両表とも 1 列目がゲート名**である(表 B も「発火条件」ではなくゲート名を先頭に置く)。
  列の意味が表ごとに違っても走査位置が変わらないようにするため。
- ゲート名は**バッククォート 1 対で囲み、末尾が `Test`** の英数字だけで書く。
  パス表記(`tests/Architecture/...`)や `.php` を 1 列目に書かない(2 列目以降には書いてよい)。
- アンカーになる小見出しは `#### 新規リソースで必ず踏む Architecture ゲート` と
  `#### 条件付きで発火するゲート` の 2 本で、**この文字列を変えるときは施策 2 の定数も同じ変更で直す**。

### リスク

- 文書が長くなる(§2 が約 45 行増える)。§7 が既に 310 行を占める文書なので相対的な影響は小さく、
  索引としての価値が上回ると判断した。
- 表の内容が実装からずれる余地は残る(施策 2 が見るのは名前の実在まで)。
  この非対称は施策 2 の docblock と本文の「網羅性は主張しない」で明示する。
- **対象定義(実装単位の宣言)と 8 件の対応は機械では見ない**。「この単位は必ず FormRequest を
  持つ」といった宣言が実態からずれても同期検査は緑のままである。したがって
  **本文を変えるときは対象定義もレビュー対象**であり、8 件の増減は分類基準
  (概念設計の「表 A / 表 B の分類基準」)に照らして議論する。

---

## 施策 2: 同期検査 `IntegrationGuideGateTableSyncTest` を新設する

### 変更箇所

- ファイル: `tests/Architecture/IntegrationGuideGateTableSyncTest.php`(**新規**)
- 負例は**本ファイル内の合成入力**に置く(AGENTS.md は負例の置き場を 3 通りとも認めている。
  fixture ファイルを新設しない = 変更ファイルを増やさない)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイルのみ。既存テストの変更なし
  (`docs/app-integration-guide.md` を読む既存テストは `RouteCacheExemptionPremiseTest` の
  「D19 の参照が切れていない」検査だけで、`D19` の文字列は §7c に在り続けるので影響しない)

### 走査器共通規約(AGENTS.md §静的検査 (gate) と走査器の共通規約)への適合

| 条 | 適用 | 本設計での満たし方 |
|---|---|---|
| (a) クラス参照は完全修飾名で突き合わせる | **対象外** | PHP のクラス参照を解決しない。見るのは Markdown のセル文字列と**ファイルの実在**だけである(この旨を docblock に明記する) |
| (b) 解決できない形は落とす(fail-closed) | 適用 | 走査根が読めない / §2 の見出しが無い / アンカーが無い / 表が無い / 1 列目からゲート名を取り出せない、はすべて**例外**にする。未解決を `null` や空文字列として戻り値へ混ぜない。「違反 0 件」と「母集団 0 件」は別検査で区別する |
| (c) 検出力は負例で裏取りする | 適用 | 負例 7 形 + 正例 1 形を両方向で固定する(下のテスト計画) |
| (d) 集めた走査結果を判定に使わない形を作らない | 適用 | 抽出した名前は件数 pin・実在・一意性の 3 判定すべてに使う。数えるだけの目録を作らない |
| (e) 語彙一致の否定形はトークン完全一致で判定 | 適用 | **区切りは半角縦棒 `|` の 1 文字だけ**と宣言する。セルは前後の空白を落として比較し、ゲート名は正規表現の**完全一致**(`/^[A-Za-z][A-Za-z0-9]*Test$/`)で判定する。部分文字列一致・語境界に頼らない |

### 変更後コード(新規ファイルの全文)

```php
<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * 契約文書 §2 のゲート表が指すゲート名の**実在・件数・一意性**を固定する。
 *
 * 家系の裁定 AG-116 が定めた合成版の一部として、本アプリは
 * docs/app-integration-guide.md §2 に「新規リソースで必ず踏むゲート」と
 * 「条件付きで発火するゲート」の 2 表を持つ (設計は
 * devnotes/20260822-2305-integration-guide-gate-table-restore/)。表はゲート名を名指しするため、
 * ゲートの改名・削除で**表だけが古い名前を指し続ける**と、索引を読んで登録しに行った設計者が
 * 存在しないゲートを探すことになる。それを機械で落とす。
 *
 * ★走査対象: docs/app-integration-guide.md の **§2 の範囲だけ**。
 *   `## 2. ` の行から次の `## ` の行の手前までを切り出し、その中の 2 つのアンカー小見出しの
 *   直後にある表を見る。§2 の外の同名文字列は 1 件も見ない。
 * ★アンカーは**ちょうど 1 件**でなければならない (0 件 = 表が無い / 2 件以上 = 曖昧)。
 * ★表は**1 つの連続ブロック**でなければならない。アンカーの領域 (次の見出し行までの範囲) の中で
 *   `|` で始まる行がブロックの後にもう一度現れたら例外にする (表の切り詰めを件数 pin だけに
 *   頼らず、その場で落とす)。
 * ★区切り文字の宣言 (走査器共通規約 (e)): 表の行を割るのは**半角縦棒 `|` の 1 文字だけ**である。
 *   セルは前後の空白を落として比較し、ゲート名は完全一致の正規表現で判定する。
 * ★名前解決 (同 (a)) は行わない。見るのは
 *   `tests/Architecture/<名前>.php` が**regular file として実在するか**だけである。
 *   `.php` で終わるディレクトリは母集団に入れない。
 *
 * ---------------------------------------------------------------------------
 * **この検査が保証しないもの** (誇張しない。ここが正本であり AGENTS.md や
 * 契約文書本文には詳細を写さない):
 *
 *  1. **表の構成集合そのものは固定しない**。ある行を**別の実在するゲート名へ差し替える**ことは
 *     検出しない。21 件の期待集合を本ファイルへ写すと表と検査の 2 か所に同じ一覧を持つことになり、
 *     必ず食い違う。**正本は文書側の表 1 か所**とし、ここは件数・実在・一意性に限る
 *     (`LedgerPins` の 3 定数や ForbiddenStatement の件数 pin と同じ作法)。
 *  2. 表に書かれた**発火条件・登録先の意味的な正確さ**は見ない。表が宣言する実装単位
 *     (「この単位は必ず FormRequest を持つ」等) と 8 件の対応も見ない。
 *  3. **設計者が実際に §2 を読んで登録したかは見ない**。家系の正典が
 *     「設計時に §2〜§7 の判定を踏んだかどうかを確かめる機械は家系のどこにも無い」と
 *     記録しており、本検査はその状況を変えない。
 *  4. **索引の網羅性は主張しない**。表に載っていないゲートの存在は見ないので、
 *     「ここに無いゲートは発火しない」とは読めない。
 *  5. ゲートの**中身**が生きているか (その検査が空振りしていないか) は各ゲート自身の責務である。
 *  6. 表の列のうち 2 列目以降は見ない (パス表記や別ゲート名を書いてよい欄である)。
 *  7. **ゲート母集団の全体件数は見ない**。本検査の不変条件は「表に載せた 21 件が実在すること」で
 *     あって「ゲートが N 本あること」ではないため、根拠の無い下限値は持たない。
 * ---------------------------------------------------------------------------
 *
 * 実行不能 (文書が読めない / §2 が無い / アンカーが 1 件でない / 表が無い / ヘッダや区切りが
 * 規定を外れる / 表が分割されている / 1 列目からゲート名を取り出せない) は
 * skip でも緑でもなく**不合格**にする。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/** 走査根 (リポジトリ相対)。 */
const INTEGRATION_GUIDE_SOURCE_PATH = 'docs/app-integration-guide.md';

/** ゲートの実装が置かれるディレクトリ (リポジトリ相対)。 */
const INTEGRATION_GUIDE_GATE_DIRECTORY = 'tests/Architecture';

/**
 * アンカー小見出し => 期待するゲート件数 (完全一致)。
 *
 * ★件数は**完全一致**で、増えても減っても赤になる。表の行を増減させるときは
 *   同じ変更でこの値を直す (無断の縮小を黙らせない)。
 * ★小見出しの文字列は文書側と本定数の 2 か所に現れる。**同じ変更で直す**こと
 *   (アンカーが無ければ例外になるので、片方だけ変えると必ず気付く)。
 *
 * @var array<string, int>
 */
const INTEGRATION_GUIDE_GATE_TABLES = [
    '#### 新規リソースで必ず踏む Architecture ゲート' => 8,
    '#### 条件付きで発火するゲート' => 13,
];

/** 1 列目のセルが満たすべき形 (バッククォート 1 対で囲まれた、末尾が Test の英数字)。 */
const INTEGRATION_GUIDE_GATE_CELL = '/^`([A-Za-z][A-Za-z0-9]*Test)`$/';

/** 表の最小列数 (ゲート / 説明 / 登録先)。 */
const INTEGRATION_GUIDE_MINIMUM_COLUMNS = 3;

/**
 * 走査が空振りでないことを確かめる代表ゲート (母集団に必ず在るもの)。
 *
 * @var list<string>
 */
const INTEGRATION_GUIDE_SENTINEL_GATES = [
    'ControllerAuthorizationGateTest',
    'NestedRouteIdorDefenseTest',
];

/**
 * 契約文書の本文 (読めないことは空ではなく不合格)。
 */
function integrationGuideMarkdown(): string
{
    $markdown = @file_get_contents(base_path(INTEGRATION_GUIDE_SOURCE_PATH));
    Assert::string($markdown, INTEGRATION_GUIDE_SOURCE_PATH.' を読めない (実行不能は不合格)');

    return $markdown;
}

/**
 * 本文を行へ割る (改行の種類に依存しない)。
 *
 * @return list<string>
 */
function integrationGuideLines(string $text): array
{
    $lines = preg_split('/\R/u', $text);
    Assert::isArray($lines, '本文を行へ割れない');
    Assert::allString($lines, '本文の行が文字列ではない');

    return array_values($lines);
}

/**
 * §2 の範囲だけを切り出す。
 *
 * 見出しが無いことは**空ではなく例外**にする (走査根の改名・章立ての変更で
 * 母集団が空になったまま緑になる形を作らない)。
 */
function integrationGuideSectionTwo(string $markdown): string
{
    $lines = integrationGuideLines($markdown);
    $start = null;

    foreach ($lines as $index => $line) {
        if ($start === null) {
            if (str_starts_with($line, '## 2. ')) {
                $start = $index;
            }

            continue;
        }

        if (str_starts_with($line, '## ')) {
            return implode("\n", array_slice($lines, $start, $index - $start));
        }
    }

    if ($start === null) {
        throw new RuntimeException(
            '§2 の見出し (`## 2. ` で始まる行) が '.INTEGRATION_GUIDE_SOURCE_PATH.' に無い',
        );
    }

    return implode("\n", array_slice($lines, $start));
}

/**
 * §2 の中でアンカー小見出しがちょうど 1 件あることを確かめ、その位置を返す。
 *
 * 0 件と 2 件以上でメッセージを分ける (どちらも例外)。
 *
 * @param  list<string>  $lines
 */
function integrationGuideAnchorIndex(array $lines, string $anchor): int
{
    /** @var list<int> $found */
    $found = [];

    foreach ($lines as $index => $line) {
        if (trim($line) === $anchor) {
            $found[] = $index;
        }
    }

    if ($found === []) {
        throw new RuntimeException('アンカー小見出し「'.$anchor.'」が §2 に無い');
    }

    if (count($found) > 1) {
        throw new RuntimeException(
            'アンカー小見出し「'.$anchor.'」が §2 に '.count($found)
            .' 件ある (ちょうど 1 件でなければどの表が正本か決まらない)',
        );
    }

    return $found[0];
}

/**
 * アンカーの領域から表の行だけを取り出す (1 始まりの行番号つき)。
 *
 * ★領域はアンカーの次の行から**次の見出し行 (`#` で始まる行) の手前**までである。
 * ★領域の中の `|` 行は**1 つの連続ブロック**でなければならない。ブロックが閉じた後に
 *   `|` 行が現れたら例外にする (表の切り詰め・分割をその場で落とす)。
 *
 * @param  list<string>  $lines
 * @return list<array{0: int, 1: string}>
 */
function integrationGuideTableLines(array $lines, int $anchorIndex, string $anchor): array
{
    /** @var list<array{0: int, 1: string}> $rows */
    $rows = [];
    $started = false;
    $closed = false;

    foreach (array_slice($lines, $anchorIndex + 1) as $offset => $line) {
        $trimmed = trim($line);

        if (str_starts_with($trimmed, '#')) {
            break;
        }

        if (str_starts_with($trimmed, '|')) {
            if ($closed) {
                throw new RuntimeException(
                    'アンカー「'.$anchor.'」の領域で表が 2 か所に分かれている '
                    .'(§2 内 '.($anchorIndex + $offset + 2).' 行目)。表は 1 つの連続ブロックで書く',
                );
            }

            $started = true;
            $rows[] = [$anchorIndex + $offset + 2, $trimmed];

            continue;
        }

        if ($started) {
            $closed = true;
        }
    }

    return $rows;
}

/**
 * アンカー小見出しの直後にある表のデータ行から、1 列目のゲート名を取り出す。
 *
 * ★**正常に全行を解決できたときだけ** `list<string>` を返す。解決できない行が 1 行でもあれば
 *   行番号と理由を持つ例外を投げる (未解決を戻り値へ混ぜない / 無言で候補から外さない)。
 * ★行番号は §2 の切り出しの中での 1 始まりの位置である (絶対行ではない)。
 *
 * @return list<string>
 */
function integrationGuideGateNames(string $section, string $anchor): array
{
    $lines = integrationGuideLines($section);
    $anchorIndex = integrationGuideAnchorIndex($lines, $anchor);
    $tableLines = integrationGuideTableLines($lines, $anchorIndex, $anchor);

    if (count($tableLines) < 3) {
        throw new RuntimeException(
            'アンカー「'.$anchor.'」の直後に表 (ヘッダ / 区切り / データ行) が無い',
        );
    }

    [, $headerRow] = $tableLines[0];
    $headerCells = integrationGuideCells($headerRow);

    if (count($headerCells) < INTEGRATION_GUIDE_MINIMUM_COLUMNS) {
        throw new RuntimeException(
            'アンカー「'.$anchor.'」の表のヘッダが '.INTEGRATION_GUIDE_MINIMUM_COLUMNS
            .' 列に足りない (実測 '.count($headerCells).' 列): '.$headerRow,
        );
    }

    if ($headerCells[0] !== 'ゲート') {
        throw new RuntimeException(
            'アンカー「'.$anchor.'」の表の 1 列目の見出しが「ゲート」ではない (実測: '
            .$headerCells[0].')',
        );
    }

    [, $separator] = $tableLines[1];

    if (preg_match('/^\|[\s\-:|]+\|$/', $separator) !== 1) {
        throw new RuntimeException(
            'アンカー「'.$anchor.'」の表にヘッダ区切り行が無い (実測: '.$separator.')',
        );
    }

    /** @var list<string> $names */
    $names = [];

    foreach (array_slice($tableLines, 2) as [$lineNumber, $row]) {
        $cells = integrationGuideCells($row);

        if (count($cells) < INTEGRATION_GUIDE_MINIMUM_COLUMNS) {
            throw new RuntimeException(
                '§2 内 '.$lineNumber.' 行目: 表の行が '.INTEGRATION_GUIDE_MINIMUM_COLUMNS
                .' 列に足りない (実測 '.count($cells).' 列): '.$row,
            );
        }

        if (preg_match(INTEGRATION_GUIDE_GATE_CELL, $cells[0], $matches) !== 1) {
            throw new RuntimeException(
                '§2 内 '.$lineNumber.' 行目: 1 列目からゲート名を取り出せない '
                .'(バッククォート 1 対で囲んだ、末尾が Test の英数字だけを許す。'
                .'パス表記や .php は 1 列目に書かない)。実測: '.$cells[0],
            );
        }

        Assert::keyExists($matches, 1, '正規表現の捕獲群が取れない');
        Assert::string($matches[1], '捕獲したゲート名が文字列ではない');

        $names[] = $matches[1];
    }

    return $names;
}

/**
 * 表の 1 行をセルへ割る。
 *
 * ★区切りは**半角縦棒 `|` の 1 文字だけ**である (走査器共通規約 (e) の宣言)。
 *   前後の空白は落とす。両端の空セルは区切りの副産物なので捨てる。
 *
 * @return list<string>
 */
function integrationGuideCells(string $row): array
{
    $cells = array_map(static fn (string $cell): string => trim($cell), explode('|', $row));

    if ($cells !== [] && $cells[0] === '') {
        array_shift($cells);
    }
    if ($cells !== [] && end($cells) === '') {
        array_pop($cells);
    }

    return array_values($cells);
}

/**
 * 実在するゲート名の母集団 (拡張子なし)。
 *
 * ★ディレクトリが無い・読めないことは空ではなく例外にする (fail-open を作らない)。
 * ★regular file だけを数える (`.php` で終わるディレクトリは母集団に入れない)。
 *
 * @return list<string>
 */
function integrationGuideExistingGates(): array
{
    $directory = base_path(INTEGRATION_GUIDE_GATE_DIRECTORY);
    Assert::directory($directory, INTEGRATION_GUIDE_GATE_DIRECTORY.' がディレクトリとして無い');
    Assert::readable($directory, INTEGRATION_GUIDE_GATE_DIRECTORY.' を読めない');

    $paths = glob($directory.'/*.php');
    Assert::isArray($paths, INTEGRATION_GUIDE_GATE_DIRECTORY.' を列挙できない');

    /** @var list<string> $names */
    $names = [];

    foreach ($paths as $path) {
        Assert::string($path, '列挙したパスが文字列ではない');

        if (! is_file($path)) {
            continue;
        }

        $names[] = basename($path, '.php');
    }

    sort($names);

    return $names;
}

/**
 * 抽出した名前を、件数 pin / 実在 / 一意性の 3 観点で突き合わせる (純関数)。
 *
 * ★負のコントロールは実ファイルを触らず、合成した `$tables` と `$existing` を渡して同じ関数を走らせる。
 *
 * @param  array<string, list<string>>  $tables  アンカー => 1 列目のゲート名
 * @param  array<string, int>  $expected  アンカー => 期待件数
 * @param  list<string>  $existing  実在するゲート名
 * @return list<string>
 */
function integrationGuideGateTableViolations(array $tables, array $expected, array $existing): array
{
    /** @var list<string> $violations */
    $violations = [];
    /** @var array<string, string> $seen ゲート名 => 初出のアンカー */
    $seen = [];

    foreach ($expected as $anchor => $count) {
        if (! array_key_exists($anchor, $tables)) {
            $violations[] = 'アンカー「'.$anchor.'」の表が抽出できていない';

            continue;
        }

        $names = $tables[$anchor];

        if (count($names) !== $count) {
            $violations[] = 'アンカー「'.$anchor.'」のゲート件数が '.count($names)
                .' 件で、pin した '.$count.' 件と食い違う (表を増減させたら同じ変更で pin も直す)';
        }

        foreach ($names as $name) {
            if (! in_array($name, $existing, true)) {
                $violations[] = 'ゲート `'.$name.'` が '
                    .INTEGRATION_GUIDE_GATE_DIRECTORY.' に実在しない (改名・削除で索引が腐っている)';
            }

            if (isset($seen[$name])) {
                $violations[] = 'ゲート `'.$name.'` が重複している ('
                    .$seen[$name].' と '.$anchor.')';

                continue;
            }

            $seen[$name] = $anchor;
        }
    }

    return $violations;
}

/**
 * 実ファイルから 2 表を抽出する。
 *
 * @return array<string, list<string>>
 */
function integrationGuideGateTables(): array
{
    $section = integrationGuideSectionTwo(integrationGuideMarkdown());

    /** @var array<string, list<string>> $tables */
    $tables = [];

    foreach (array_keys(INTEGRATION_GUIDE_GATE_TABLES) as $anchor) {
        $tables[$anchor] = integrationGuideGateNames($section, $anchor);
    }

    return $tables;
}

/**
 * 負のコントロール用に §2 相当の合成入力を組み立てる。
 *
 * 規定どおりの形を既定とし、引数で行だけを差し替える。
 */
function integrationGuideSyntheticSection(
    string $rows,
    ?string $anchor = null,
    ?string $header = null,
    ?string $separator = null,
    string $trailing = '',
): string {
    $anchor ??= '#### 新規リソースで必ず踏む Architecture ゲート';
    $header ??= '| ゲート | 何を落とすか | 何をどこへ登録するか |';
    $separator ??= '|---|---|---|';

    return implode("\n", [
        '## 2. ドメインモデルの配置',
        '',
        $anchor,
        '',
        $header,
        $separator,
        $rows,
        '',
        $trailing,
        '',
    ]);
}

test('§2 の 2 表が実在し、件数 pin / 実在 / 一意性を満たす', function (): void {
    $violations = integrationGuideGateTableViolations(
        integrationGuideGateTables(),
        INTEGRATION_GUIDE_GATE_TABLES,
        integrationGuideExistingGates(),
    );

    expect($violations)->toBe([], "§2 のゲート表の違反:\n".implode("\n", $violations));
});

test('走査が空振りしていない (走査根 / §2 / 各表の非空 / ゲート母集団)', function (): void {
    // 走査根と §2 が生きていること
    $section = integrationGuideSectionTwo(integrationGuideMarkdown());
    expect($section)->toContain('## 2. ');

    // 各表のデータ行が非空であること (母集団 0 件を緑にしない)
    foreach (array_keys(INTEGRATION_GUIDE_GATE_TABLES) as $anchor) {
        expect(integrationGuideGateNames($section, $anchor))->not->toBeEmpty();
    }

    // ゲート母集団が非空で、代表ゲートが在ること (全体件数の下限は持たない)
    $existing = integrationGuideExistingGates();
    expect($existing)->not->toBeEmpty();
    foreach (INTEGRATION_GUIDE_SENTINEL_GATES as $sentinel) {
        expect($existing)->toContain($sentinel);
    }
});

test('負のコントロール: 走査根を差し替えると §2 が取れず例外になる', function (): void {
    expect(static function (): void {
        integrationGuideSectionTwo("# 別の文書\n\n## 3. 別の章\n");
    })->toThrow(RuntimeException::class);
});

test('負例: 表の形が規定を外れると例外になる', function (
    string $rows,
    ?string $header,
    ?string $separator,
    string $trailing,
): void {
    $section = integrationGuideSyntheticSection($rows, null, $header, $separator, $trailing);

    expect(static function () use ($section): void {
        integrationGuideGateNames($section, '#### 新規リソースで必ず踏む Architecture ゲート');
    })->toThrow(RuntimeException::class);
})->with([
    'バッククォート欠落' => ['| MassAssignmentSafetyTest | 落とすもの | 登録先 |', null, null, ''],
    'ゲート列が空' => ['|  | 落とすもの | 登録先 |', null, null, ''],
    'パス表記' => ['| `tests/Architecture/MassAssignmentSafetyTest.php` | 落とすもの | 登録先 |', null, null, ''],
    '末尾が Test でない' => ['| `MassAssignmentSafety` | 落とすもの | 登録先 |', null, null, ''],
    '列が足りない' => ['| `MassAssignmentSafetyTest` | 落とすもの |', null, null, ''],
    'ヘッダの 1 列目が「ゲート」でない' => [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        '| 検査 | 何を落とすか | 何をどこへ登録するか |',
        null,
        '',
    ],
    'ヘッダが 3 列に足りない' => [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        '| ゲート | 何を落とすか |',
        '|---|---|',
        '',
    ],
    '区切り行が不正' => [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        null,
        '| 区切りではない | 行 | である |',
        '',
    ],
    '表が 2 か所に分かれている' => [
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        null,
        null,
        '| `ControllerAuthorizationGateTest` | 落とすもの | 登録先 |',
    ],
]);

test('負例: アンカーが 1 件でないと例外になる', function (string $section): void {
    expect(static function () use ($section): void {
        integrationGuideGateNames($section, '#### 新規リソースで必ず踏む Architecture ゲート');
    })->toThrow(RuntimeException::class);
})->with([
    'アンカーが 0 件' => [
        integrationGuideSyntheticSection(
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '#### 別の小見出し',
        ),
    ],
    'アンカーが 2 件' => [
        integrationGuideSyntheticSection('| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |')
        ."\n#### 新規リソースで必ず踏む Architecture ゲート\n",
    ],
]);

test('負例: 不存在・重複・件数不一致は違反として報告される', function (): void {
    $anchor = '#### 新規リソースで必ず踏む Architecture ゲート';
    $other = '#### 条件付きで発火するゲート';

    // 実在しないゲート名
    expect(integrationGuideGateTableViolations(
        [$anchor => ['NoSuchGateTest']],
        [$anchor => 1],
        ['MassAssignmentSafetyTest'],
    ))->not->toBeEmpty();

    // 表をまたいだ重複
    expect(integrationGuideGateTableViolations(
        [$anchor => ['MassAssignmentSafetyTest'], $other => ['MassAssignmentSafetyTest']],
        [$anchor => 1, $other => 1],
        ['MassAssignmentSafetyTest'],
    ))->not->toBeEmpty();

    // 件数不一致 (減った側)
    expect(integrationGuideGateTableViolations(
        [$anchor => ['MassAssignmentSafetyTest']],
        [$anchor => 2],
        ['MassAssignmentSafetyTest'],
    ))->not->toBeEmpty();
});

test('正例: 規定どおりの合成入力は誤検出しない', function (): void {
    $anchor = '#### 新規リソースで必ず踏む Architecture ゲート';

    $names = integrationGuideGateNames(
        integrationGuideSyntheticSection(implode("\n", [
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '| `ControllerAuthorizationGateTest` | 落とすもの | `tests/Architecture` への言及は 2 列目以降なら可 |',
        ])),
        $anchor,
    );

    expect($names)->toBe(['MassAssignmentSafetyTest', 'ControllerAuthorizationGateTest']);
    expect(integrationGuideGateTableViolations(
        [$anchor => $names],
        [$anchor => 2],
        ['MassAssignmentSafetyTest', 'ControllerAuthorizationGateTest'],
    ))->toBe([]);
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が全関数に明示されている(`string` / `int` / `list<string>` /
      `list<array{0: int, 1: string}>` / `array<string, list<string>>`)
- [x] null 安全: `file_get_contents` / `preg_split` / `glob` の失敗は
      `Webmozart\Assert\Assert` で string / array へ絞る(`false` を型へ混ぜない)。
      `Assert::allString` で行の要素型も絞る
- [x] **offset の参照前に列数を検査する**: ヘッダ・データ行とも
      `count() < INTEGRATION_GUIDE_MINIMUM_COLUMNS` を先に落としてから `[0]` を参照する
      (`list<string>` に非空の保証は無いため)
- [x] **未解決を戻り値へ混ぜない**: 抽出に失敗した行は `null` / `''` を返さず `RuntimeException`
- [x] `preg_match` は戻り値 `1` との**厳密比較**で判定する(`false` と `0` を混ぜない)
- [x] `$matches[1]` は成立枝の中で `Assert::keyExists` + `Assert::string` により明示的に絞る
- [x] `toThrow` へ渡すのは**戻り値型 `void` のブロッククロージャ**(要素型の無い `array` を
      返す短縮クロージャを作らない)
- [x] DTO 返却は不要(テスト内の純関数。配列の形は phpdoc の generics で固定)
- [x] `list<string>` を返す関数は `array_values` で連番を保証する

### テスト計画

**実施順(テストファースト。走査器を新設するときに揃える 4 点の 1 番目に従う)**:

1. **最小の抽出器を先に置く** — アンカーを探して `|` 行を集め、1 列目をそのまま返すだけの実装。
   同時に**負例テスト群(下の 4・5)を置く**。この時点で負例は
   「受理してはいけない入力を実際に受理してしまう」ので**赤になる**。
2. fail-closed 分岐を 1 つずつ実装して負例を 1 つずつ緑にする
   (アンカー一意性 → 表の連続性 → ヘッダ → 区切り → 列数 → セルの形)。
3. **正例テスト(7)を置く** — 規定どおりの入力を誤検出しない側を固定する(両方向の裏取り)。
4. **実文書を読む 2 本(1・2)を置く**。施策 1 の本文がまだ無いので
   `アンカー小見出し「…」が §2 に無い` の例外で**赤になる**ことを確認する
   (走査根は生きているが母集団が作れない状態を先に見る)。
5. 施策 1 の本文を挿入して緑にする。
6. 施策 3 へ(下記のとおり突合 gate の赤を先に確認する)。
7. `composer test`(全体)/ `composer phpstan` / `vendor/bin/pint --test` を緑にする。

**テストの内訳**: `test()` 宣言は **7 個**、dataset 展開後のシナリオは **16 件**
(正常系 1 / 空振り検査 1 / 負のコントロール 1 / 表の形の負例 9 / アンカーの負例 2 /
違反報告の負例 1 宣言・3 検証 / 正例 1)。

- [x] バグ修正ではないため再現テストは不要。新設ゲートの負例・正例で代替する
- [x] 既存テストの更新: **なし**(既存の inventory を触らない)
- [x] 個別の `DatabaseTransactions` は使わない(DB 不使用の静的検査)

### リスク

- **小見出しの文字列が文書と定数の 2 か所に現れる**。片方だけ変えるとアンカー不在の例外で
  必ず赤くなるので、黙って壊れることはない(この非対称を docblock に書く)。
- **件数 pin の更新忘れ**で表を増やしたときに赤くなる。これは仕様(無断の縮小・膨張を許さない)。
- グローバル関数名の衝突: Pest のテストファイル内関数はグローバルなので、
  `integrationGuide*` の接頭辞で全関数を分ける。既存に同名は無いことを確認済み
  (`integrationGuide` で始まる関数・`INTEGRATION_GUIDE` で始まる定数は現状 0 件)。
- **合成入力の 2 列表**: `ヘッダが 3 列に足りない` の負例では区切り行も 2 列へ揃える
  (区切りの検査より先にヘッダの列数で落ちることを期待している。落ちる順序が変わっても
  例外になる点は同じなので、負例の意図は保たれる)。

## 施策 3: 乖離台帳の付け替え

### なぜ施策 1 と不可分か

`docs/app-integration-guide.md` は **テンプレート共有ファイル**
(`docs/template-fingerprints.json` の `entries` にキーとして実在。指紋
`9377362c…`)であり、かつ**採用時債務一覧に載っている**
(`tests/Support/TemplateDivergence/adoption-debt.tsv` の 53 行目。採用時ハッシュ
`8b9aa9f3…`)。現物の sha256 は採用時ハッシュと**一致している**(実測済み = 採用時の姿のまま)。

本文を 1 行でも変えると突合 gate の `mutatedDebtPaths`(F10)が落ちる。
債務一覧が縮む契機は `AdoptionDebtInventory` の docblock と D34 が定める 2 つだけ —
「内容をテンプレートへ戻す」か「意図的逸脱として登録簿へ書く」。テンプレート現物が無いので
前者は取れない。よって**後者が唯一の経路**である。

### 3-1. `docs/template-divergence.md` へ D40 を追加

- 番号: 現在の最大が **D39**。番号は再利用しないので **D40**(欠番は正常)。
- 挿入位置: ファイル末尾(D39 のエントリの後)。
- 冒頭の件数行 `登録エントリ: 36 件` → `登録エントリ: 37 件`
  (TD12 が「明示件数 / 見出しの実数 / `LedgerPins` の固定件数」の 3 点一致を強制する)。
- 対象パスは**全登録の和集合で重複しない**こと(TD4)。
  `docs/app-integration-guide.md` は現在どの登録の対象パスにも現れていないことを確認済み。

#### 追加する本文(全文)

```markdown
## D40 契約文書のゲート索引を、本アプリの実在ゲートへ写した判定規準として持つ

| 行 | 内容 |
|---|---|
| 対象パス | `docs/app-integration-guide.md` / `tests/Architecture/IntegrationGuideGateTableSyncTest.php` |
| 業務要件起因の説明 | 本文書の §2 は「新しいドメインリソースを足すときにどの検査へ登録するか」の索引であり、指す先が本アプリのゲート実体である以上、本アプリ固有のセキュリティ境界と実在ゲート名で構成するほかない。家系の裁定 AG-116 が定めた合成版の一部だが、テンプレート現物を参照できないため逐語復元ではなく判定規準としての写像である |
| 揃え続ける不変条件と保証機構 | 索引が指すゲート名の実在・件数 (必ず踏む 8 件 / 条件付き 13 件)・表をまたいだ一意性は `tests/Architecture/IntegrationGuideGateTableSyncTest.php` が固定し続ける。§7 の不変条件を参照するときは番号ではなく項目名で指す (本文書 §7 と AGENTS.md の採番は 1:1 対応しないため、どちらの側も renumber しない) |
| 再判定の条件 | テンプレート更新の一括取り込みを行うとき / 家系の巡回で裁定 AG-116 の合成版の現物が配られたとき / §2 のゲート表の行を増減させるとき。再照合の正本は家系の機能台帳 lctl の feature `app-integration-guide` とテンプレートの `docs/app-integration-guide.md` である。合成版の §2 と本アプリの §2 が同じ判定を同じ抽象度で要求していると確認できたら本登録は削除する (下の「削除の判断基準」) |
| 決めた日 | 2026-08-22 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260822-2305-integration-guide-gate-table-restore/ |
| 状態 | 監視中 |
| 見直し期限 | 2027-02-28 |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| §2 のゲート索引 | 必ず踏む 8 本 / 条件付き 13 本 (台帳が記録する規模。行の中身は現物を参照できていない) | 同じ 8 件 / 13 件で、行は本アプリの実在ゲート名で構成する |
| 本アプリ由来の節 | 裁定 AG-116 に基づき還流済みの 3 節を持つ (エラー応答の優先順位 / テナント境界を経路解決の直後で閉じる / 新規ルート追加チェックリスト。実装は `T121` と `T132`) | 還流済みの 3 節に加えて「流量制限の付与規約」と「vendor route への後付け機構と経路キャッシュの契約」の 2 節を持つ (台帳が AG-116 の名指しした 3 節の外と明記) |
| §7 の採番 | 1〜11 | 1〜10 (renumber しない。相互参照は項目名で行う) |
| §9 (正本から生成し写しを同期検査する) | 持つ | 持たない (裁定 AG-116 が名指しした 3 節の外) |
| 索引と実装の同期検査 | 文書と実装ゲートの整合を見る gate を持つ | §2 の 2 表に限った実在・件数・一意性の検査を持つ |

### なぜ正当な差分か (logic-driven)

**本登録は「テンプレート現物が届くまでの監視中の登録」である。** 恒久の差分を主張するものではない。

逸脱が logic-driven なのは 2 点による。

1. **索引が指す先が本アプリのゲート実体である**。§2 の 2 表は「新しいドメインリソースを
   足すときにどの検査へ何を登録するか」を指すものなので、実在しないゲート名を指す索引は
   無価値になる。本アプリのゲート構成 (SOP・シナリオ・撮影テイクというテナントデータを
   守る境界の集合) はテンプレートの汎用形と同一ではないため、名前をそのまま写すことはできない。
2. **本アプリ由来の節は実測された監査所見への対処である**。「エラーを返す順番を間違えると
   他組織のデータの存在が 1 bit 漏れる」という所見と、その順番を機械で固定する規約であり、
   家系の裁定 AG-116 自身が「テンプレートに無いのは取りこぼしに近い」と評価して還流の対象にした。
   逸脱の理由は互換・UX・作業量ではない。

### 削除の判断基準 (この登録をいつ消すか)

裁定 AG-116 の合成版の現物が配られたら、その §2 の 2 表と本アプリの 2 表を突き合わせ、
**同じ判定を同じ抽象度で要求している**(記録の原則の判定軸)と確認できたら本登録は削除し、
指紋の一致へ戻す (一部だけ戻る場合は対象パス欄からそのパスを削る)。
実装名の綴りの違いだけが残る状態は逸脱として維持しない。
テンプレート現物を参照できない間は、**台帳で確認できる範囲を超えた現状断定をしない**。

### 揃えている不変条件 (これは保証し続ける)

> 「§2 のゲート索引が指すゲートは実在する。必ず踏む表は 8 件、条件付きの表は 13 件で、
> 同じゲートが 2 度現れない」

- 実在・件数・一意性は同期検査が deny-by-default ではなく**抽出した各行の未解決・不存在・
  件数不一致・重複を拒否する**形で固定する
- §7 を参照するときに番号を使わない規約は人のレビューが担う

### 保証しないもの

- **採用時ハッシュによる追跡を失う**。突合 gate は「登録済みのパスの追加の drift は検出しない
  (検出するのは一致から不一致へ移る瞬間である)。**債務パスは例外**で、採用時ハッシュとの
  一致まで見る」と定めており、債務から登録へ移した本パスは以後の内容変更を検出されない。
  再照合の契機は本登録の見直し期限とテンプレート更新の一括取り込みである
- 表に書かれた発火条件・登録先の**意味的な正確さ**は機械では見ない (同期検査の docblock が正本)
- 設計者が実際に §2 の判定を踏んだかは見ない (家系の正典が「それを確かめる機械は家系のどこにも
  無い」と記録しており、本登録はその状況を変えない)

### 関連

- 実装: `tests/Architecture/IntegrationGuideGateTableSyncTest.php`
- 設計: `devnotes/20260822-2305-integration-guide-gate-table-restore/`
```

### 3-2. `tests/Support/TemplateDivergence/adoption-debt.tsv`

53 行目(`docs/app-integration-guide.md<TAB>8b9aa9f3…`)の **1 行だけを削除**する。
ヘッダ行(`# template_ledger_commit=a078806b…`)は**触らない**
(突合 gate の F14 が指紋台帳の `generated_at_commit` と突き合わせる)。
結果: 172 行 → 171 行(データ 171 件 → 170 件)。

### 3-3. `tests/Support/TemplateDivergence/LedgerPins.php`

```php
    /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 36;
+    public const int DIVERGENCE_ENTRY_COUNT = 37;
```

```php
-    public const int ADOPTION_DEBT_COUNT = 171;
+    public const int ADOPTION_DEBT_COUNT = 170;
```

`FINGERPRINT_POPULATION_COUNT`(281)/ `TEMPLATE_LEDGER_SOURCE_COMMIT` /
`TEMPLATE_LEDGER_SOURCE_SHA256` / `ADOPTION_DEBT_DIVERGENCE_ID`(34)は**変更しない**
(指紋台帳の母集合は縮まないし、取り込んだ正典台帳の出自も変わらない。
D34 は「一覧が 0 件になったとき」以外は据え置き)。

### 波及変更

- TypeScript 型定義 / API Resource・DTO: なし
- テストファイル: 既存 `TemplateDivergenceFingerprintTest` / `TemplateDivergenceLedgerFormatTest` は
  **変更しない**(値の変更だけで緑に戻る)。両者が本施策の検証手段そのものである

### PHPStan 適合チェック

- [x] `LedgerPins` は `public const int` の値変更のみ(型宣言・可視性を変えない)
- [x] 新しいメンバを足さない(解析・ファイル I/O を持たない定数置き場という docblock の約束を守る)

### テスト計画

- [x] 施策 1 の本文変更だけを入れた状態で `TemplateDivergenceFingerprintTest` が
      **`mutatedDebtPaths` で赤になることを先に確認する**(債務パスを編集したという事実の裏取り。
      施策 3 を後に回す理由)
- [x] 債務 1 行削除 + D40 追加 + 件数 pin 2 件の更新で、
      `TemplateDivergenceFingerprintTest`(F1〜F14)と
      `TemplateDivergenceLedgerFormatTest`(TD0〜TD12)が緑に戻ることを確認する
- [x] TD12 の 3 点一致(明示件数 37 / 見出しの実数 37 / `DIVERGENCE_ENTRY_COUNT` 37)
- [x] TD3(対象パス 2 件がファイルとして実在)/ TD4(和集合で重複なし)/
      TD10(根拠 `devnotes/20260822-2305-integration-guide-gate-table-restore/` が実在)/
      TD6(見直し期限 2027-02-28 が基準日から 400 日以内かつ未期限切れ)
- [x] TD1(見出し要約に印・矢印・「解消」「済み」を含めない)

### リスク

- **他 TODO との衝突**: `DIVERGENCE_ENTRY_COUNT` / `ADOPTION_DEBT_COUNT` と
  `docs/template-divergence.md` の件数行は、別の TODO が同時に乖離を登録すると必ず衝突する。
  → 実装モードを `standalone` にする理由。
- **見直し期限の切れ**: 2027-02-28 を過ぎると CI が赤くなる(仕様)。直し方は登録簿の規約節の
  4 通りから選ぶ(検査は緩めない)。
- **債務からの離脱で追跡が緩む**: 上記のとおり D40 の「保証しないもの」に明記して補償する。

---

## 全施策に共通する確認事項

### 変更しないもの(明示)

- `AGENTS.md`(§2 への参照は節名なので壊れない。セキュリティ不変条件の採番も触らない)
- `docs/template-fingerprints.json`(テンプレート側の指紋なので**アプリ側の編集では変えない**。
  書き換えて黙らせるのは禁止行為)
- 既存の Architecture テスト・inventory・exemption 目録
- `app/` / `routes/` / `resources/` / `database/` / `config/`(コード変更 0 行)
- `docs/TODO.md`(`app-todo-add` の責務)

### 検証コマンド(全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
`pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
`pnpm build:packages` / `pnpm test:packages`

(JS 側は 1 行も変えないが、AGENTS.md の検証コマンド節が全 green を要求しているため通す)

### 波及変更の総括

インターフェース変更(API / ルート / DTO / コンポーネント Props)は**一切無い**。
よって TypeScript 型定義・Inertia Props・API Resource・既存テストへの波及は無い。
波及が発生する唯一の結線は「施策 1 の小見出し文字列 ↔ 施策 2 の定数」で、
これは同じ PR 内の 2 ファイルに閉じており、片方だけ変えると例外で赤になる。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 3 施策は不可分(本文を変えた瞬間に突合 gate が落ちるため、施策 1 だけを先にマージできない)。加えて `LedgerPins` の 2 定数と `docs/template-divergence.md` の件数行・番号 D40 は、**別の TODO が同時に乖離を登録すると必ず衝突する**共有カウンタである。単独の worktree で完結させ、乖離登録を伴う他 TODO と並走させない |
| 競合リスク | `DIVERGENCE_ENTRY_COUNT` / `ADOPTION_DEBT_COUNT` / 件数行 / D 番号の同時更新。マージ時に他 TODO が D40 を先に取っていたら D41 へ繰り上げ、件数も再計算する(番号は再利用しない) |


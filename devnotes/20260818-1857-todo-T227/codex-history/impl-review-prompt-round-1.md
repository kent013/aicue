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

あなたは Laravel + Svelte アプリのコードレビュアーである。本件は **静的検査 (Architecture テスト) の改修**であり、アプリ本体のコードは 1 行も変えていない。

## レビュー観点
1. **設計との一致性**: TODO T227 の要求 (走査 gate 12 本の分類 / 母集団非空検査の付与 / 対象外の理由を docblock へ / 負例による裏取り) を満たしているか
2. **分類の妥当性**: 「付与しない」と判定した 3 本の理由は本当に成立するか。逆に「付与」した 9 本の検査は不変条件として正しいか
3. **検査の実効性**: 付与した検査は、走査根の改名・移動・抽出条件の綻びで**必ず**赤くなるか。逆に正常な変更 (Model や FormRequest の増減など) で無意味に赤くならないか
4. **リファクタの安全性**: 既存の判定ロジックを引数化・関数抽出した箇所で、**元の検出範囲が変わっていないか** (振る舞い保存)
5. **PHPStan level 10 適合性** / **Pest の作法** (`toContain()` が可変長引数である点など)
6. **床値・代表要素の選び方**: 脆すぎないか / 緩すぎないか

## 出力形式
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で書く

---

## 参考: AGENTS.md の該当規約

## 静的検査 (gate) と走査器の共通規約

**対象**: `tests/Support/` 配下の検出器 / gate の中に直接書かれた走査ロジック /
それらを使う gate (`tests/Architecture/` / `tests/js/architecture/`)。
次の 5 条を満たす。家系の機能台帳の正典 v1 をそのまま写したもので、5 条とも
**「検査は緑なのに穴が開いていた」実測事故**から出ている
(設計と既存の食い違いの棚卸しは `devnotes/20260818-0303-scanner-common-conventions/`)。

**条ごとの適用範囲**: (b)〜(d) は**該当するすべての走査**に適用する。
(a) は**クラス名・名前参照を解決する走査**、(e) は**語彙一致を判定する走査**にだけ適用する
(文字列だけを見る走査に (a) は無意味であり、名前を解決する走査に (e) は無関係である)。

- **(a) クラス参照は完全修飾名で突き合わせる**。`use` / group use / 別名つき取り込みを解いた
  完全修飾名で比べる。短名一致は別名つき取り込み 1 つで検査が黙り、末尾の要素だけの一致は
  同名の別クラスを拾う。**構文解析ライブラリの使用は必須ではない** (家系の裁定 AG-154 の (2))。
  字句走査 + 取り込み対応表でよく、条件は (b) と (c) を満たすことだけである
- **(b) 解決できない形は落とす (fail-closed)**。判定を拾いすぎる方向へ倒すのは可、
  見逃す方向へ倒すのは不可。ここでいう「落とす」は**見逃さない**という意味であり、
  正常なコードを違反と断定することではない。具体的には次の 3 つを守る。
  - **未解決を解決済みと同じ値へ混ぜない**。gate が保証すると宣言した範囲の中で参照を
    解決できなかったら、**未解決だと判別できる結果**か解析の失敗として利用側へ返し、
    gate を失敗させる。**無言で候補から外さない**
  - **保証範囲の外にする構文は docblock へ明記する**。明記したなら、その構文について
    **検出力を主張しない** (明記せずに落ちこぼすのは (b) 違反である)。
    ただし**保証範囲は走査器 1 本の docblock だけでは決まらない** — 利用側 gate の名前・
    守ると宣言した不変条件・検出力の主張まで含めて判定する。
    **走査器の限界を書き足すことは、既にある見逃しを規約適合へ変えない**。
    保証範囲の外にした構文で保護対象の操作を書ける場合、利用側 gate は
    **検出力の主張をその構文を除く形へ明示的に狭める**か、**未解決として失敗させる**かのどちらかにする
  - **「違反が 0 件」と「母集団が 0 件」を区別する**。落とすのは後者だけである。
    違反ゼロが正常な gate はいくらでもあるが、**判定に使う母集団が空**なのに緑になる形は、
    走査根の改名・ディレクトリ移動・抽出条件の綴り間違いで**走査が壊れても気付けない**。
    適用対象は「母集団の非空が不変条件である gate」で、**入力を受け取って候補を返し、
    母集団の非空を契約としない再利用可能な検出器は対象外**である
    (その場合は検出器を**使う側の gate** が母集団の非空を持つ)
- **(c) 検出力は負例で裏取りする**。わざと違反させた入力を検出できることと、
  規定どおりの入力を誤検出しないことの**両方向**を固定する
- **(d) 集めた走査結果を判定に使わない形を作らない**。収集するが誰も参照しない出力、
  数えるだけで比べない目録を作らない
- **(e) 語彙一致の否定形は区切り文字で分割したトークンの完全一致で判定する**。
  正規表現の語境界や素の部分文字列一致に頼らない。
  **何を区切りとするかは走査ごとに宣言する** (準拠実装: `tests/js/support/ds-purity.ts` が
  スタイル記述を class トークンへ割る文字集合を宣言し、その文字集合で割れない書き方は
  許可一覧へ登録できないことも併せて書いている)。
  負例には最低でも**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を置く
  (許可語の除去を素の部分文字列で書いたため、この 3 形まで一緒に消えて検出漏れになっていた、
  が本リポジトリの実測である)

### 走査器・gate を新設・変更するときに同じ PR で揃える 4 点

**発火条件**: 走査ロジック・走査対象・名前解決・判定条件・目録のいずれかを新設または変更するとき。
**コメントや docblock を実態に合わせて訂正するだけで検出範囲を変えない変更は発火しない**
(既知の不適合はその場で直さず、棚卸しに記録して別 TODO で追跡する)。

1. **負例と正例**。テストファーストで**先に赤くしてから**本体を書く (思考原則 5)。
   既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する
2. **解決できない形を落とす分岐** ((b))
3. **走査が空振りしていないことの検査**。母集団が空でないこと / 走査根がそれぞれ生きていること
   (準拠実装: `FfmpegProcessLaunchInventoryTest` の「母集団が空でない」検査、
   `PromptGuardrailTest` の「各走査根が解決でき、いずれも空でない」検査)
4. **docblock に走査対象と保証しないものを書く**。中身の正本は docblock 側に置き、
   本書へ写さない

### 本リポジトリでの置き方

- **走査根の単一出典**: git 追跡下の PHP 全数を母集団にする走査は
  `Tests\Support\TrackedPhpSourceFiles` を使う。同じ列挙を 2 本持たない。
  母集団がそれより狭い走査は自分の根を持ってよいが、**存在しない根は fail-fast** で落とす
  (準拠実装 `PrismDirectDispatchScanner::roots()`)
- **負例の置き場は 3 通りとも認める**: 見本ファイル (`tests/Architecture/fixtures/`) /
  検出器の自己検査 (`tests/Unit/Architecture/`) / gate 内の合成入力。
  どこに置いてもよいが、**gate または検出器の docblock から辿れること**。
  1 つへ寄せる作業に見合う効果が無いため寄せない (思考原則 2)

### 検出力の主張の書き方

「検査ファイルが実在する」と「検出力が裏取りされている」は**別物**である。
後者を主張する記述は根拠を**同じ行に併記**し、併記の無い記述は**検出力未確認**と読む。
**遡及して裏取りを付ける作業は求めない** (家系の裁定 AG-154 の (1))。

> **本節の保証範囲 (誇張しない)**: 本節は**人がレビュー時に適用する規約であり、
> 機械では強制しない**。走査器の書き方を検査する仕組み (家系の先行実装が持つ走査器の索引と、
> その索引を文書へ投影して整合を見張る検査) は**作っていない**。したがって本節があっても
> 「すべての gate が 5 条を満たしている」とは読めない。**満たしていない箇所は実在し**、
> `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` に記録してある。
> 索引の新設を再検討する条件は同ディレクトリの概念設計に書いてある
> (新設 gate のレビューで規約の適用漏れが見つかった / 走査器候補の棚卸しをもう一度やる必要が出た /
> 全数性を主張する棚卸しが必要になった、の 3 つ)。

## 参考: 棚卸し D2 (本 TODO の出発点)

## D2: 走査の空振り検査が全体には無い — (b) の「母集団が 0 件」

**「違反が 0 件」ではなく「判定に使う母集団が 0 件」の話である**。違反ゼロは正常な状態であり、
落とす対象ではない。問題は母集団が空になっても緑のまま通る形で、heuristic な数え方は次のとおり
(語の揺れを拾い切れないため、**下限ではなく候補**として読むこと)。

```bash
# 空振り検査らしき表現を持たない Architecture gate
for f in tests/Architecture/*.php; do
  rg -q "not->toBe\(\[\]\)|not->toBeEmpty|toBeGreaterThan|空振り|degenerate|母集団が空" "$f" || basename "$f"
done
```

130 本中 32 本が該当し、そのうち**自分でソースを走査しているもの 12 本**が実際の候補である
(`AppNameHardcodeTest` / `BillingSyncDispatchInvariantTest` / `BugHuntInventoryCheckInvariantTest` /
`ClaudeHooksWiringTest` / `FormRequestProhibitedKeyTest` / `FreePlanCodeWriteInvariantTest` /
`MassAssignmentSafetyTest` / `NoMessageCarrying404Test` / `ProjectMemberPivotWritePathTest` /
`QueuedJobLeaseInventoryTest` / `RateLimiterKeyConventionTest` / `ValidationAttributeCoverageTest`)。

実読で確認した典型例:

- `MassAssignmentSafetyTest` — `RecursiveDirectoryIterator` で `app/Models` を走査してモデル一覧を
  作り、違反が空であることだけを assert する。**一覧が 0 件になっても緑**である。
- `FormRequestProhibitedKeyTest` — 同型 (`app/Http/Requests` の列挙)。

準拠している側の見本も実在するので、規約はそれを名指しすればよい:

- `FfmpegProcessLaunchInventoryTest` — 「母集団が空でない (degenerate PASS 防止)」を独立した
  test 1 本として持つ
- `PromptGuardrailTest` / `PromptDefenseWindowGateTest` — 「5 走査根が解決でき、いずれも空でない」

**扱い**: 12 本の分類と是正は別 TODO。規約側では「新設・変更するときに揃えるもの」の 3 番として
要求し、既存分へは遡及しない (AG-154 (1))。



## 分類メモ (今回の成果物)

# T227 空振り検査を持たない走査 gate 12 本の分類と付与

観測点: `main` @ edfb863 (T226 マージ直後)。

対象は `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` の D2 が
列挙した 12 本である。分類の基準は `AGENTS.md` §静的検査 (gate) と走査器の共通規約 (b) の
「違反が 0 件」と「母集団が 0 件」の区別、および同条の適用対象の定義
(「母集団の非空が不変条件である gate」か「入力を受け取って候補を返す再利用可能な検出器」か)。

準拠実装は `FfmpegProcessLaunchInventoryTest` の「母集団が空でない (degenerate PASS 防止)」と
`PromptGuardrailTest` の「5 走査根が解決でき、いずれも空でない」、および
`StrictTypesDeclarationGateTest` の「空振り防止 1〜4」(非空 + 床値 + 代表パス + 判定器の自己検査)。

## 分類の結果

| # | gate | 判定 | 走査根 / 母集団 | 付与した検査 (または付与しない理由) |
|---|---|---|---|---|
| 1 | `AppNameHardcodeTest` | 付与 | app / routes / database / resources/js / scripts の 5 本 | 5 本すべてが実在しファイルを持つこと |
| 2 | `BillingSyncDispatchInvariantTest` | 付与 | app/ 配下で `SyncBillingCustomerDetails::dispatch` を持つファイル | 母集団が窓口 1 本と完全一致すること |
| 3 | `ClaudeHooksWiringTest` (S12b) | 付与 | 7 本の glob が当たる実行面のファイル | 走査域が非空で、代表 5 ファイルを含むこと (S12c) |
| 4 | `FormRequestProhibitedKeyTest` | 付与 | app/Http/Requests の FormRequest | 非空 + 床値 25 + 代表クラス 2 本 |
| 5 | `FreePlanCodeWriteInvariantTest` | 付与 | app/ 配下で `free_plan_code` へ書き込むファイル | 母集団が窓口 1 本と完全一致すること |
| 6 | `MassAssignmentSafetyTest` | 付与 | app/Models の Model | 非空 + 床値 30 + 代表クラス 3 本 |
| 7 | `NoMessageCarrying404Test` | 付与 | app / routes / bootstrap の 3 本 | 3 本すべてが実在し PHP ファイルを持つこと |
| 8 | `ProjectMemberPivotWritePathTest` | 付与 | app/ の PHP ファイル | 走査ファイルが非空 + allowlist の各ファイルが実際に検出されること |
| 9 | `ValidationAttributeCoverageTest` | 付与 | app/Http/Requests と app/ (Requests を除く) の 2 つ | 2 つの母集団の非空 + 床値 |
| 10 | `BugHuntInventoryCheckInvariantTest` | 付与しない | 名指しの 2 ファイル + テスト自身が組み立てる sandbox | ディレクトリを列挙して母集団を作らない。根の改名は `Assert::fileExists` の即時 fail になる |
| 11 | `QueuedJobLeaseInventoryTest` | 付与しない | `Tests\Support\QueuedJobPopulation` | 目録との対称差 0 + 「3 系統が母集団に入っている」で非空が既に固定されている |
| 12 | `RateLimiterKeyConventionTest` | 付与しない | app/ の `RateLimiter::for()` 登録 | 非空の inventory との完全一致が非空を構造的に担保し、各 limiter は実評価もされる |

付与しない 3 本は、その理由を各 gate の docblock 冒頭に書いた (中身の正本は docblock 側に置き、
`AGENTS.md` へは写さない)。

## 負例による裏取り

付与した 9 本には、それぞれ「走査根を差し替えると母集団が空になる」ことを示す
負のコントロールのケースを併置した (母集団の列挙を走査根の引数で呼べる形へ揃えた)。
加えて実装中に**走査根そのものを一時的に壊して赤を確認**した。

- 手順: 9 本の走査根の既定値を存在しないパスへ書き換え、当該 9 ファイルだけを実行
- 結果: 付与した 9 件の空振り検査がすべて赤 (10 件目は
  `ValidationAttributeCoverageTest` の既存の stale entry 検査で、走査停止に伴う想定内の連鎖)
- 書き換えを戻した後は Architecture レーン 1121 件が緑

## 併せて行ったこと

`devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` の
「別 TODO として起票を申し送るもの」表 2 行目の追跡先 TODO ID 欄へ T227 を記入した
(同表 2 行とも ID が埋まることが元 TODO の完了条件だったため)。


## 実装差分 (git diff)

```diff
diff --git a/devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md b/devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md
index 52088726..e1aa75ae 100644
--- a/devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md
+++ b/devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md
@@ -152,7 +152,7 @@ ## 別 TODO として起票を申し送るもの
 | # | 内容 | 根拠 | 追跡先 TODO ID |
 |---|---|---|---|
 | 1 | `PhpReferenceScanner` の部分修飾名を落とす形へ寄せる (波及 6 gate + 2 検出器)。未解決は判別できる値か例外で表し、完全修飾名の文字列へ混ぜない | D1 | T226 (実施済み) |
-| 2 | 空振り検査を持たない走査 gate 12 本の分類と付与 | D2 | _(未採番)_ |
+| 2 | 空振り検査を持たない走査 gate 12 本の分類と付与 | D2 | T227 (12 本を分類し、母集団の非空が不変条件である 9 本へ検査を付与。残る 3 本は付与が要らない理由を各 gate の docblock に記録) |
 
 いずれも本 TODO のスコープ外である (`conceptual-design.md` スコープ外節)。
 **TODO の起票・採番は監督者、採番済み ID の本表への追記は実装者の作業**である
diff --git a/devnotes/20260818-1857-todo-T227/classification.md b/devnotes/20260818-1857-todo-T227/classification.md
new file mode 100644
index 00000000..e429ab57
--- /dev/null
+++ b/devnotes/20260818-1857-todo-T227/classification.md
@@ -0,0 +1,49 @@
+# T227 空振り検査を持たない走査 gate 12 本の分類と付与
+
+観測点: `main` @ edfb863 (T226 マージ直後)。
+
+対象は `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` の D2 が
+列挙した 12 本である。分類の基準は `AGENTS.md` §静的検査 (gate) と走査器の共通規約 (b) の
+「違反が 0 件」と「母集団が 0 件」の区別、および同条の適用対象の定義
+(「母集団の非空が不変条件である gate」か「入力を受け取って候補を返す再利用可能な検出器」か)。
+
+準拠実装は `FfmpegProcessLaunchInventoryTest` の「母集団が空でない (degenerate PASS 防止)」と
+`PromptGuardrailTest` の「5 走査根が解決でき、いずれも空でない」、および
+`StrictTypesDeclarationGateTest` の「空振り防止 1〜4」(非空 + 床値 + 代表パス + 判定器の自己検査)。
+
+## 分類の結果
+
+| # | gate | 判定 | 走査根 / 母集団 | 付与した検査 (または付与しない理由) |
+|---|---|---|---|---|
+| 1 | `AppNameHardcodeTest` | 付与 | app / routes / database / resources/js / scripts の 5 本 | 5 本すべてが実在しファイルを持つこと |
+| 2 | `BillingSyncDispatchInvariantTest` | 付与 | app/ 配下で `SyncBillingCustomerDetails::dispatch` を持つファイル | 母集団が窓口 1 本と完全一致すること |
+| 3 | `ClaudeHooksWiringTest` (S12b) | 付与 | 7 本の glob が当たる実行面のファイル | 走査域が非空で、代表 5 ファイルを含むこと (S12c) |
+| 4 | `FormRequestProhibitedKeyTest` | 付与 | app/Http/Requests の FormRequest | 非空 + 床値 25 + 代表クラス 2 本 |
+| 5 | `FreePlanCodeWriteInvariantTest` | 付与 | app/ 配下で `free_plan_code` へ書き込むファイル | 母集団が窓口 1 本と完全一致すること |
+| 6 | `MassAssignmentSafetyTest` | 付与 | app/Models の Model | 非空 + 床値 30 + 代表クラス 3 本 |
+| 7 | `NoMessageCarrying404Test` | 付与 | app / routes / bootstrap の 3 本 | 3 本すべてが実在し PHP ファイルを持つこと |
+| 8 | `ProjectMemberPivotWritePathTest` | 付与 | app/ の PHP ファイル | 走査ファイルが非空 + allowlist の各ファイルが実際に検出されること |
+| 9 | `ValidationAttributeCoverageTest` | 付与 | app/Http/Requests と app/ (Requests を除く) の 2 つ | 2 つの母集団の非空 + 床値 |
+| 10 | `BugHuntInventoryCheckInvariantTest` | 付与しない | 名指しの 2 ファイル + テスト自身が組み立てる sandbox | ディレクトリを列挙して母集団を作らない。根の改名は `Assert::fileExists` の即時 fail になる |
+| 11 | `QueuedJobLeaseInventoryTest` | 付与しない | `Tests\Support\QueuedJobPopulation` | 目録との対称差 0 + 「3 系統が母集団に入っている」で非空が既に固定されている |
+| 12 | `RateLimiterKeyConventionTest` | 付与しない | app/ の `RateLimiter::for()` 登録 | 非空の inventory との完全一致が非空を構造的に担保し、各 limiter は実評価もされる |
+
+付与しない 3 本は、その理由を各 gate の docblock 冒頭に書いた (中身の正本は docblock 側に置き、
+`AGENTS.md` へは写さない)。
+
+## 負例による裏取り
+
+付与した 9 本には、それぞれ「走査根を差し替えると母集団が空になる」ことを示す
+負のコントロールのケースを併置した (母集団の列挙を走査根の引数で呼べる形へ揃えた)。
+加えて実装中に**走査根そのものを一時的に壊して赤を確認**した。
+
+- 手順: 9 本の走査根の既定値を存在しないパスへ書き換え、当該 9 ファイルだけを実行
+- 結果: 付与した 9 件の空振り検査がすべて赤 (10 件目は
+  `ValidationAttributeCoverageTest` の既存の stale entry 検査で、走査停止に伴う想定内の連鎖)
+- 書き換えを戻した後は Architecture レーン 1121 件が緑
+
+## 併せて行ったこと
+
+`devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` の
+「別 TODO として起票を申し送るもの」表 2 行目の追跡先 TODO ID 欄へ T227 を記入した
+(同表 2 行とも ID が埋まることが元 TODO の完了条件だったため)。
diff --git a/tests/Architecture/AppNameHardcodeTest.php b/tests/Architecture/AppNameHardcodeTest.php
index f91a36ee..34281865 100644
--- a/tests/Architecture/AppNameHardcodeTest.php
+++ b/tests/Architecture/AppNameHardcodeTest.php
@@ -9,18 +9,71 @@
  * コード中に slug を直書きすると、テンプレート派生アプリ間の copy-paste で別アプリの
  * 名前が混入する事故が起きる (spirux の tests/bootstrap.php に aigenba- が残っていた実例)。
  *
- * 検査: app/ routes/ database/ tests/ resources/js/ scripts/ の中に
+ * 検査: app/ routes/ database/ resources/js/ scripts/ の中に
  * config('template.slug') 以外の経路で slug 既定値が現れないこと。
  * 既定 slug は 'app' で一般語のため、ここでは「.env.example の TEMPLATE_APP_SLUG 値」を
  * 動的に取得して走査する (アプリが slug を変更した後も機能する)。
+ *
+ * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
+ * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**走査根の非空が不変条件**である。
+ * 5 本の走査根はどれか 1 本が改名・移動しても違反ゼロのまま緑になるため、
+ * 「空振り検査」ケースが 5 本すべての生存 (実在かつファイルを持つ) を固定し、
+ * その直後の負のコントロールが「根を差し替えると母集団が空になる」ことを示す。
+ *
+ * ★保証範囲を誇張しない: slug が既定値 'app' のままの間、走査本体は**一般語の誤検出を避けるため
+ *   意図的に何も判定しない**。この状態でも「走査根が生きていること」は検査し続ける
+ *   (派生アプリが slug を設定した瞬間に走査が機能する状態を保つため)。
  */
 
-test('アプリ slug がコードにハードコードされていない', function (): void {
+/**
+ * slug 走査の根 (リポジトリ相対パス)。
+ *
+ * @return list<string>
+ */
+function appSlugScanRoots(): array
+{
+    return ['app', 'routes', 'database', 'resources/js', 'scripts'];
+}
+
+/**
+ * 走査根配下の全ファイル (絶対パス)。根が実在しなければ空を返す。
+ *
+ * @return list<string>
+ */
+function appSlugScanFiles(string $absoluteRoot): array
+{
+    if (! is_dir($absoluteRoot)) {
+        return [];
+    }
+
+    $files = [];
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
+    );
+    /** @var SplFileInfo $file */
+    foreach ($iterator as $file) {
+        if ($file->isFile()) {
+            $files[] = $file->getPathname();
+        }
+    }
+    sort($files);
+
+    return $files;
+}
+
+/** .env.example が宣言する TEMPLATE_APP_SLUG の値 (未宣言なら空文字)。 */
+function appSlugFromEnvExample(): string
+{
     $envExample = file_get_contents(base_path('.env.example'));
     expect($envExample)->toBeString();
     /** @var string $envExample */
     preg_match('/^TEMPLATE_APP_SLUG=(.+)$/m', $envExample, $m);
-    $slug = trim($m[1] ?? '');
+
+    return trim($m[1] ?? '');
+}
+
+test('アプリ slug がコードにハードコードされていない', function (): void {
+    $slug = appSlugFromEnvExample();
 
     // 既定値 'app' は一般語のため走査対象外 (派生アプリが固有 slug を設定した時点で発動する)
     if ($slug === '' || $slug === 'app') {
@@ -29,28 +82,30 @@
         return;
     }
 
-    $directories = ['app', 'routes', 'database', 'resources/js', 'scripts'];
     $violations = [];
-
-    foreach ($directories as $dir) {
-        $path = base_path($dir);
-        if (! is_dir($path)) {
-            continue;
-        }
-        $iterator = new RecursiveIteratorIterator(
-            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
-        );
-        /** @var SplFileInfo $file */
-        foreach ($iterator as $file) {
-            if (! $file->isFile()) {
-                continue;
-            }
-            $contents = file_get_contents($file->getPathname());
+    foreach (appSlugScanRoots() as $root) {
+        foreach (appSlugScanFiles(base_path($root)) as $path) {
+            $contents = file_get_contents($path);
             if ($contents !== false && str_contains($contents, $slug)) {
-                $violations[] = str_replace(base_path().'/', '', $file->getPathname());
+                $violations[] = str_replace(base_path().'/', '', $path);
             }
         }
     }
 
     expect($violations)->toBe([], 'slug "'.$slug.'" のハードコードを検出: '.implode(', ', $violations));
 });
+
+test('空振り検査: 5 本の走査根がいずれも生きている (実在しファイルを持つ)', function (): void {
+    foreach (appSlugScanRoots() as $root) {
+        $absolute = base_path($root);
+        expect(is_dir($absolute))->toBeTrue("走査根 {$root} が存在しません");
+        expect(appSlugScanFiles($absolute))->not->toBe([], "走査根 {$root} にファイルがありません");
+    }
+});
+
+test('負のコントロール: 走査根を差し替えると母集団が空になる', function (): void {
+    // 上の生存検査が空振りしていないことの裏取り。走査根の改名・移動を模して
+    // 実在しないパスを渡すと母集団が 0 件になる = 生存検査が赤くなる。
+    expect(appSlugScanFiles(base_path('app-renamed')))->toBe([]);
+    expect(appSlugScanFiles(base_path('resources/js-renamed')))->toBe([]);
+});
diff --git a/tests/Architecture/BillingSyncDispatchInvariantTest.php b/tests/Architecture/BillingSyncDispatchInvariantTest.php
index ae041eb8..14b9ae73 100644
--- a/tests/Architecture/BillingSyncDispatchInvariantTest.php
+++ b/tests/Architecture/BillingSyncDispatchInvariantTest.php
@@ -23,26 +23,64 @@
 | - 反転根拠: 本 job は SerializesModels で organization を再取得するため、可視化が commit 後で
 |   ある限り IV-3 は afterCommit なしで保たれる。一方 afterCommit は「commit したのに未投入」の
 |   窓を残すため、確定 1 の下では有害である
+|
+| 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
+| 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である。
+| dispatch 記法の改名・走査根の移動で母集団が 0 件になると、窓口が消えても違反ゼロで緑になる。
+| 末尾の「空振り検査」ケースが母集団を allowlist と完全一致で pin し (= 非空)、
+| その直後の負のコントロールが「一致するもののない根では母集団が空になる」ことを示す。
 */
 
-test('app/ 内の SyncBillingCustomerDetails::dispatch は BillingCustomerSynchronizer に閉じる', function (): void {
-    $allowlist = [
-        'app/Services/Billing/BillingCustomerSynchronizer.php',
-    ];
+/** 走査で母集団に入る唯一のファイル (窓口)。 */
+const BILLING_SYNC_DISPATCH_ALLOWLIST = [
+    'app/Services/Billing/BillingCustomerSynchronizer.php',
+];
+
+/**
+ * 走査根配下で `SyncBillingCustomerDetails::dispatch` を持つファイル (リポジトリ相対パス)。
+ *
+ * @param  string  $absoluteRoot  走査根の絶対パス (負のコントロールで差し替えるため引数化)
+ * @return list<string>
+ */
+function billingSyncDispatchFiles(string $absoluteRoot): array
+{
+    if (! is_dir($absoluteRoot)) {
+        return [];
+    }
 
     $finder = Finder::create()
-        ->in(base_path('app'))
+        ->in($absoluteRoot)
         ->files()
         ->name('*.php')
         ->contains('/SyncBillingCustomerDetails::dispatch/');
 
-    $violations = [];
+    $files = [];
     foreach ($finder as $file) {
-        $relative = str_replace(base_path().'/', '', (string) $file->getRealPath());
-        if (! in_array($relative, $allowlist, true)) {
-            $violations[] = $relative;
-        }
+        $files[] = str_replace(base_path().'/', '', (string) $file->getRealPath());
     }
+    sort($files);
+
+    return $files;
+}
+
+test('app/ 内の SyncBillingCustomerDetails::dispatch は BillingCustomerSynchronizer に閉じる', function (): void {
+    $violations = array_values(array_diff(
+        billingSyncDispatchFiles(base_path('app')),
+        BILLING_SYNC_DISPATCH_ALLOWLIST,
+    ));
 
     expect($violations)->toBe([], 'SyncBillingCustomerDetails の dispatch は BillingCustomerSynchronizer 経由に限定してください: '.implode(', ', $violations));
 });
+
+test('空振り検査: 走査の母集団が窓口 1 本と完全一致する (走査根が生きている)', function (): void {
+    // 母集団が空 = 窓口そのものを検出できていない状態であり、上の検査は無条件に緑になる。
+    // 完全一致で pin することで「非空」と「窓口が母集団に居ること」を同時に固定する。
+    expect(billingSyncDispatchFiles(base_path('app')))->toBe(BILLING_SYNC_DISPATCH_ALLOWLIST);
+});
+
+test('負のコントロール: 走査根を差し替えると母集団が空になる', function (): void {
+    // 上の pin が空振りしていないことの裏取り。走査根の改名・移動を模して
+    // 一致するもののないディレクトリ / 実在しないパスを渡すと母集団が 0 件になる。
+    expect(billingSyncDispatchFiles(base_path('config')))->toBe([]);
+    expect(billingSyncDispatchFiles(base_path('app-renamed')))->toBe([]);
+});
diff --git a/tests/Architecture/BugHuntInventoryCheckInvariantTest.php b/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
index 263479ee..92daf0a3 100644
--- a/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
+++ b/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
@@ -21,6 +21,17 @@
  * exit code 規約は「静的に読める宣言」ではなく **実走で** 検証する。ただし実 artisan
  * (boot + APP_KEY + DB) には依存させない: 一時 sandbox へ道具一式を複製し、`php` を
  * 固定の scan JSON を吐く shim に差し替えて走らせる (決定論・DB 不使用)。
+ *
+ * ★空振り検査 (母集団非空) の付与対象外である。理由:
+ *   本 gate は**ディレクトリを列挙して母集団を作らない**。見るのは名指しの 2 ファイル
+ *   (`scripts/bug-hunt-inventory-check.sh` / `scripts/bug-hunt-inventory.py`) と、
+ *   テスト自身が組み立てた sandbox の fixture だけである。走査根の改名・移動は
+ *   「母集団が 0 件になって緑」ではなく `Assert::fileExists` / `expect(file_exists(...))` の
+ *   即時 fail になる (= 無言の空振りが起きる形になっていない)。
+ *   シェルの実装行を読む `bhicShellCodeLines()` だけは列挙に近いが、その非空は
+ *   同じケース内の必須語句検査 (`expect($code)->toContain('scripts/bug-hunt-inventory.py')`) が
+ *   先に落ちることで担保されている。
+ *   なお目録の生成器が母集合 0 件を検出する責務は生成器側 (段 1) が持つ。
  */
 
 function bhicScriptPath(): string
diff --git a/tests/Architecture/ClaudeHooksWiringTest.php b/tests/Architecture/ClaudeHooksWiringTest.php
index 3d69273e..199c7ef7 100644
--- a/tests/Architecture/ClaudeHooksWiringTest.php
+++ b/tests/Architecture/ClaudeHooksWiringTest.php
@@ -79,6 +79,24 @@
     '.github/workflows/*',
 ];
 
+/**
+ * S12c (空振り検査) が母集団に居ることを要求する代表ファイル。
+ *
+ * S12b は「禁止語句が 1 件も無いこと」を見るので、glob が 1 つも当たらなくても緑になる。
+ * 走査域が黙って消えたことに気付けるよう、実行面の各系統から 1 本ずつ名指しで固定する。
+ * **glob ごとの件数は pin しない** (scripts の下位ディレクトリを見る glob は現在 0 件で、
+ *  下位ディレクトリを持たないことは違反ではないため)。
+ *
+ * @var list<string>
+ */
+const CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_REPRESENTATIVES = [
+    '.claude/settings.json',
+    'composer.json',
+    'docker/Dockerfile',
+    'package.json',
+    'scripts/bug-hunt-shard.sh',
+];
+
 // =============================================================================
 // ヘルパ (静的層)
 // =============================================================================
@@ -368,6 +386,32 @@ function claudeHooksExpectNotContains(string $haystack, string $needle, string $
     expect(str_contains($haystack, $needle))->toBeFalse("{$reason} (現れてはならない文字列: {$needle})");
 }
 
+/**
+ * S12b の走査域 (リポジトリ相対パスの昇順)。
+ *
+ * @param  string|null  $root  走査根の絶対パス (null = リポジトリルート)。
+ *                             負のコントロールで別の根を渡すために引数化してある
+ * @return list<string>
+ */
+function claudeHooksSelfWiringScanFiles(?string $root = null): array
+{
+    $root = rtrim($root ?? base_path(), '/');
+    $files = [];
+
+    foreach (CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS as $glob) {
+        foreach (glob($root.'/'.$glob) ?: [] as $path) {
+            if (is_file($path)) {
+                $files[] = ltrim(str_replace($root, '', $path), '/');
+            }
+        }
+    }
+
+    $files = array_values(array_unique($files));
+    sort($files);
+
+    return $files;
+}
+
 /** 台帳から起動子の実文字列を取り出す (台帳の写しではなく本物を走らせるため)。 */
 function claudeHooksLauncherCommand(string $event): string
 {
@@ -595,20 +639,32 @@ function claudeHooksWriteExitStub(string $path, int $exitCode): void
 test('S12b: 実行面のファイルが索引ツールに配線を書かせる呼び出しを持たないこと', function (): void {
     $violations = [];
 
-    foreach (CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS as $glob) {
-        foreach (glob(base_path($glob)) ?: [] as $path) {
-            if (! is_file($path)) {
-                continue;
-            }
-            if (preg_match('/code-review-graph\s+(install|init|uninstall)\b/', claudeHooksReadFile($path)) === 1) {
-                $violations[] = str_replace(base_path().'/', '', $path);
-            }
+    foreach (claudeHooksSelfWiringScanFiles() as $relative) {
+        if (preg_match('/code-review-graph\s+(install|init|uninstall)\b/', claudeHooksReadFile(base_path($relative))) === 1) {
+            $violations[] = $relative;
         }
     }
 
     expect($violations)->toBe([], "配線の正本が二重化する呼び出しがある:\n".implode("\n", $violations));
 });
 
+test('S12c (空振り検査): S12b の走査域が空でなく、代表ファイルを含むこと', function (): void {
+    $files = claudeHooksSelfWiringScanFiles();
+
+    // 非空: glob がすべて外れても S12b は違反ゼロで緑になる
+    expect($files)->not->toBe([], 'S12b の走査域が空です (glob が 1 つも当たっていません)');
+    foreach (CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_REPRESENTATIVES as $representative) {
+        // `toContain()` は可変長引数なので理由は第 2 引数に渡せない (冒頭のヘルパと同じ理由)
+        expect(in_array($representative, $files, true))
+            ->toBeTrue("S12b の走査域から {$representative} が消えています");
+    }
+});
+
+test('S12c の負のコントロール: 走査根を差し替えると走査域が空になる', function (): void {
+    // 上の非空検査が空振りしていないことの裏取り。実在しない根を渡すと 0 件になる。
+    expect(claudeHooksSelfWiringScanFiles(base_path('nonexistent-scan-root')))->toBe([]);
+});
+
 // =============================================================================
 // 実起動層: 索引更新 hook (B01〜B25)
 // =============================================================================
diff --git a/tests/Architecture/FormRequestProhibitedKeyTest.php b/tests/Architecture/FormRequestProhibitedKeyTest.php
index d6048f98..bc764f4d 100644
--- a/tests/Architecture/FormRequestProhibitedKeyTest.php
+++ b/tests/Architecture/FormRequestProhibitedKeyTest.php
@@ -3,6 +3,8 @@
 declare(strict_types=1);
 
 use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use App\Http\Requests\Projects\StoreProjectRequest;
+use App\Http\Requests\StoreInquiryRequest;
 use Illuminate\Foundation\Http\FormRequest;
 
 /*
@@ -12,6 +14,12 @@
  *
  * 例外を許す場合は ALLOWLIST に「クラス名 => 理由」を追記し、
  * docs/template-divergence.md に記録すること。
+ *
+ * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
+ * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である
+ * (app/Http/Requests が改名・移動すると違反ゼロのまま緑になる)。末尾の「空振り検査」ケースが
+ * 非空・床値・代表クラスを固定し、その直後の負のコントロールが
+ * 「走査根を差し替えると母集団が空になる = この検査は空振りしていない」ことを示す。
  */
 
 const FORM_REQUEST_ALLOWLIST = [
@@ -19,12 +27,14 @@
 ];
 
 /**
+ * @param  string|null  $base  走査根の絶対パス (null = app/Http/Requests)。
+ *                             負のコントロールで別の根を渡すために引数化してある
  * @return list<class-string>
  */
-function allFormRequestClasses(): array
+function allFormRequestClasses(?string $base = null): array
 {
     $classes = [];
-    $base = app_path('Http/Requests');
+    $base ??= app_path('Http/Requests');
     if (! is_dir($base)) {
         return [];
     }
@@ -63,3 +73,23 @@ function allFormRequestClasses(): array
 
     expect($violations)->toBe([]);
 });
+
+test('空振り検査: FormRequest の母集団が空でない (走査根 app/Http/Requests が生きている)', function (): void {
+    $classes = allFormRequestClasses();
+
+    expect(is_dir(app_path('Http/Requests')))->toBeTrue('走査根 app/Http/Requests が存在しません');
+    // 非空: 走査根が消えても違反ゼロで緑になる形を落とす
+    expect($classes)->not->toBe([], '走査根 app/Http/Requests から FormRequest が 1 件も見つかりません');
+    // 床値 (実測 34 件): 走査域が黙って狭まると赤くなる
+    expect(count($classes))->toBeGreaterThanOrEqual(25);
+    // 代表クラス: 直下とサブディレクトリの両方へ届いていること
+    expect($classes)->toContain(StoreInquiryRequest::class);
+    expect($classes)->toContain(StoreProjectRequest::class);
+});
+
+test('負のコントロール: 走査根を差し替えると FormRequest の母集団が空になる', function (): void {
+    // 上の非空検査が空振りしていないことの裏取り。走査根の改名・移動を模して
+    // 別ディレクトリ / 実在しないパスを渡すと母集団が 0 件になる = 非空検査が赤くなる。
+    expect(allFormRequestClasses(app_path('Models')))->toBe([]);
+    expect(allFormRequestClasses(app_path('Http/Requests-renamed')))->toBe([]);
+});
diff --git a/tests/Architecture/FreePlanCodeWriteInvariantTest.php b/tests/Architecture/FreePlanCodeWriteInvariantTest.php
index 4d0f5ff5..cec69cbc 100644
--- a/tests/Architecture/FreePlanCodeWriteInvariantTest.php
+++ b/tests/Architecture/FreePlanCodeWriteInvariantTest.php
@@ -14,28 +14,67 @@
 | ('personal' のみ) を DB check constraint ではなくアプリ側定数
 | (PersonalPlanService::FREE_PLAN_CODE) で守る前提の機械的補助。
 | 読み取り (`->free_plan_code` の比較) は対象外。
+|
+| 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
+| 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である。
+| 列名の改名・走査根の移動で母集団が 0 件になると、窓口が消えても違反ゼロで緑になる。
+| 末尾の「空振り検査」ケースが母集団を allowlist と完全一致で pin し (= 非空)、
+| その直後の負のコントロールが「一致するもののない根では母集団が空になる」ことを示す。
 */
 
-test('app/ 内の free_plan_code 書き込みは PersonalPlanService に閉じる', function (): void {
-    $allowlist = [
-        'app/Services/Billing/PersonalPlanService.php',
-    ];
+/** 走査で母集団に入る唯一のファイル (窓口)。 */
+const FREE_PLAN_CODE_WRITE_ALLOWLIST = [
+    'app/Services/Billing/PersonalPlanService.php',
+];
+
+/**
+ * 走査根配下で free_plan_code への書き込みを持つファイル (リポジトリ相対パス)。
+ *
+ * 書き込みパターン: array key 代入 ('free_plan_code' => / "free_plan_code" =>) と
+ * プロパティ代入 (->free_plan_code = 値。=== / !== 比較は除外)。
+ *
+ * @param  string  $absoluteRoot  走査根の絶対パス (負のコントロールで差し替えるため引数化)
+ * @return list<string>
+ */
+function freePlanCodeWriteFiles(string $absoluteRoot): array
+{
+    if (! is_dir($absoluteRoot)) {
+        return [];
+    }
 
-    // 書き込みパターン: array key 代入 ('free_plan_code' => / "free_plan_code" =>) と
-    // プロパティ代入 (->free_plan_code = 値。=== / !== 比較は除外)。
     $finder = Finder::create()
-        ->in(base_path('app'))
+        ->in($absoluteRoot)
         ->files()
         ->name('*.php')
         ->contains('/([\'"])free_plan_code\1\s*=>|->free_plan_code\s*=[^=]/');
 
-    $violations = [];
+    $files = [];
     foreach ($finder as $file) {
-        $relative = str_replace(base_path().'/', '', (string) $file->getRealPath());
-        if (! in_array($relative, $allowlist, true)) {
-            $violations[] = $relative;
-        }
+        $files[] = str_replace(base_path().'/', '', (string) $file->getRealPath());
     }
+    sort($files);
+
+    return $files;
+}
+
+test('app/ 内の free_plan_code 書き込みは PersonalPlanService に閉じる', function (): void {
+    $violations = array_values(array_diff(
+        freePlanCodeWriteFiles(base_path('app')),
+        FREE_PLAN_CODE_WRITE_ALLOWLIST,
+    ));
 
     expect($violations)->toBe([], 'free_plan_code の書き込みは PersonalPlanService 経由に限定してください: '.implode(', ', $violations));
 });
+
+test('空振り検査: 走査の母集団が窓口 1 本と完全一致する (走査根が生きている)', function (): void {
+    // 母集団が空 = 窓口そのものを検出できていない状態であり、上の検査は無条件に緑になる。
+    // 完全一致で pin することで「非空」と「窓口が母集団に居ること」を同時に固定する。
+    expect(freePlanCodeWriteFiles(base_path('app')))->toBe(FREE_PLAN_CODE_WRITE_ALLOWLIST);
+});
+
+test('負のコントロール: 走査根を差し替えると母集団が空になる', function (): void {
+    // 上の pin が空振りしていないことの裏取り。走査根の改名・移動を模して
+    // 一致するもののないディレクトリ / 実在しないパスを渡すと母集団が 0 件になる。
+    expect(freePlanCodeWriteFiles(base_path('config')))->toBe([]);
+    expect(freePlanCodeWriteFiles(base_path('app-renamed')))->toBe([]);
+});
diff --git a/tests/Architecture/MassAssignmentSafetyTest.php b/tests/Architecture/MassAssignmentSafetyTest.php
index 7697b7b8..98aa685b 100644
--- a/tests/Architecture/MassAssignmentSafetyTest.php
+++ b/tests/Architecture/MassAssignmentSafetyTest.php
@@ -2,23 +2,37 @@
 
 declare(strict_types=1);
 
+use App\Models\Billing\Subscription;
+use App\Models\Organization;
+use App\Models\User;
 use App\Support\Security\MassAssignmentProtectedKeys;
 use Illuminate\Database\Eloquent\Model;
 
 /*
  * mass-assignment 出口防御: ownership / actor / tenant / secret キーは
  * どの Model の $fillable にも含めない (明示代入 or relation 経由のみ)。
+ *
+ * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
+ * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である
+ * (app/Models が改名・移動すると違反ゼロのまま緑になる)。末尾の
+ * 「空振り検査」ケースが非空・床値・代表クラスを固定し、その直後の負のコントロールが
+ * 「走査根を差し替えると母集団が空になる = この検査は空振りしていない」ことを示す。
  */
 
 /**
  * app/Models 配下 (サブ名前空間含む。Models\Billing 等) の全 Model を列挙する。
  *
+ * @param  string|null  $base  走査根の絶対パス (null = app/Models)。
+ *                             負のコントロールで別の根を渡すために引数化してある
  * @return list<class-string<Model>>
  */
-function allModelClasses(): array
+function allModelClasses(?string $base = null): array
 {
     $classes = [];
-    $base = app_path('Models');
+    $base ??= app_path('Models');
+    if (! is_dir($base)) {
+        return [];
+    }
     $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
     );
@@ -65,3 +79,23 @@ function allModelClasses(): array
 
     expect($violations)->toBe([]);
 });
+
+test('空振り検査: Model の母集団が空でない (走査根 app/Models が生きている)', function (): void {
+    $classes = allModelClasses();
+
+    // 非空: 走査根が消えても違反ゼロで緑になる形を落とす
+    expect($classes)->not->toBe([], '走査根 app/Models から Model が 1 件も見つかりません');
+    // 床値 (実測 40 件): 走査域が黙って狭まると赤くなる
+    expect(count($classes))->toBeGreaterThanOrEqual(30);
+    // 代表クラス: サブ名前空間 (Models\Billing) まで届いていること
+    expect($classes)->toContain(User::class);
+    expect($classes)->toContain(Organization::class);
+    expect($classes)->toContain(Subscription::class);
+});
+
+test('負のコントロール: 走査根を差し替えると Model の母集団が空になる', function (): void {
+    // 上の非空検査が空振りしていないことの裏取り。走査根の改名・移動を模して
+    // 別ディレクトリ / 実在しないパスを渡すと母集団が 0 件になる = 非空検査が赤くなる。
+    expect(allModelClasses(app_path('Console')))->toBe([]);
+    expect(allModelClasses(app_path('Models-renamed')))->toBe([]);
+});
diff --git a/tests/Architecture/NoMessageCarrying404Test.php b/tests/Architecture/NoMessageCarrying404Test.php
index cd8b3b7d..c2768b12 100644
--- a/tests/Architecture/NoMessageCarrying404Test.php
+++ b/tests/Architecture/NoMessageCarrying404Test.php
@@ -18,6 +18,14 @@
  * ★実装は token ベース (正規表現へフォールバックしない)。named argument の引数順不同・
  *   複数行にまたがる呼び出し・ネストした引数式を構文的に扱い、
  *   コメントや文字列リテラル中の疑似コードは拾わない (normalize が comment を落とす)。
+ *
+ * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
+ * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**走査根の非空が不変条件**である。
+ * 3 本の走査根 (app / routes / bootstrap) はどれか 1 本が改名・移動しても、
+ * 「文言つきの 404 は 1 件も無い」は違反ゼロのまま緑になる。
+ * 「空振り検査」ケースが 3 本すべての生存 (実在かつ PHP ファイルを持つ) を固定し、
+ * その直後の負のコントロールが「根を差し替えると母集団が空になる」ことを示す。
+ * 検出器そのものの裏取りは末尾の記法ごとの正例 / 負例が担当する。
  */
 
 /**
@@ -127,6 +135,16 @@ function messageCarrying404Detected(array $arguments, int $statusPosition, int $
     return true;
 }
 
+/**
+ * 走査根 (リポジトリ相対パス)。
+ *
+ * @return list<string>
+ */
+function messageCarrying404Roots(): array
+{
+    return ['app', 'routes', 'bootstrap'];
+}
+
 /**
  * 走査対象ディレクトリの PHP ファイルから、文言つき 404 の site を集める。
  *
@@ -148,7 +166,7 @@ function messageCarrying404Sites(): array
 
     $sites = [];
 
-    foreach (['app', 'routes', 'bootstrap'] as $root) {
+    foreach (messageCarrying404Roots() as $root) {
         foreach (PhpReferenceScanner::phpFiles(base_path($root), $root) as $relativePath => $source) {
             $tokens = PhpReferenceScanner::tokens($source);
 
@@ -201,6 +219,22 @@ function messageCarrying404Sites(): array
     expect(messageCarrying404Sites())->toBe([]);
 });
 
+test('空振り検査: 3 本の走査根がいずれも生きている (実在し PHP ファイルを持つ)', function (): void {
+    foreach (messageCarrying404Roots() as $root) {
+        $absolute = base_path($root);
+        expect(is_dir($absolute))->toBeTrue("走査根 {$root} が存在しません");
+        expect(PhpReferenceScanner::phpFiles($absolute, $root))
+            ->not->toBe([], "走査根 {$root} に PHP ファイルがありません");
+    }
+});
+
+test('負のコントロール: 走査根を差し替えると母集団が空になる', function (): void {
+    // 上の生存検査が空振りしていないことの裏取り。走査根の改名・移動を模して
+    // 実在しないパスを渡すと母集団が 0 件になる = 生存検査が赤くなる。
+    expect(PhpReferenceScanner::phpFiles(base_path('app-renamed'), 'app'))->toBe([]);
+    expect(PhpReferenceScanner::phpFiles(base_path('bootstrap-renamed'), 'bootstrap'))->toBe([]);
+});
+
 /**
  * 与えたソースから、検出した site を「記法ラベル => 行番号の配列」で返す (自己検査用)。
  *
diff --git a/tests/Architecture/ProjectMemberPivotWritePathTest.php b/tests/Architecture/ProjectMemberPivotWritePathTest.php
index 60c315f1..dbd8f648 100644
--- a/tests/Architecture/ProjectMemberPivotWritePathTest.php
+++ b/tests/Architecture/ProjectMemberPivotWritePathTest.php
@@ -14,6 +14,13 @@
  * 検出 A: 文字列リテラル 'project_members' の出現 (DB::table 直書き経路の deny)
  * 検出 B: `members()->attach|detach|sync|syncWithoutDetaching|toggle` の呼び出し形
  * いずれも allowlist 外の app/ コードに現れたら fail。
+ *
+ * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
+ * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である。
+ * 走査根 app/ の移動や token 判定の綻びで検出が 0 件になると、経路が増えても違反ゼロで緑になる。
+ * 「空振り検査」ケースが (1) 走査した PHP ファイルの非空 (2) allowlist の各ファイルが
+ * 実際に検出されていること を固定し、その直後の負のコントロールが
+ * 「走査根を差し替えると検出が空になる」ことを示す。
  */
 
 final class ProjectMemberPivotWriteScanner
@@ -35,33 +42,70 @@ final class ProjectMemberPivotWriteScanner
     ];
 
     /**
-     * @return array<string, list<string>> 検出種別 => 違反ファイル (app/ 相対パス)
+     * @return array{project_members_literal: list<string>, members_relation_write: list<string>}
      */
-    public static function findViolations(): array
+    public static function allowlists(): array
+    {
+        return [
+            'project_members_literal' => self::PROJECT_MEMBERS_LITERAL_ALLOWED,
+            'members_relation_write' => self::MEMBERS_WRITE_ALLOWED,
+        ];
+    }
+
+    /**
+     * 走査根配下で検出したファイルを allowlist で絞らずに返す (空振り検査用)。
+     *
+     * @param  string|null  $rootDirectory  走査根の絶対パス (null = app/)
+     * @return array{project_members_literal: list<string>, members_relation_write: list<string>}
+     */
+    public static function findDetections(?string $rootDirectory = null): array
     {
-        $appDir = self::appDir();
-        $violations = [
+        $root = $rootDirectory ?? self::appDir();
+        $detections = [
             'project_members_literal' => [],
             'members_relation_write' => [],
         ];
 
-        foreach (self::phpFiles($appDir) as $path) {
-            $relative = substr($path, strlen($appDir) + 1);
+        foreach (self::phpFiles($root) as $path) {
+            $relative = substr($path, strlen($root) + 1);
             $source = file_get_contents($path);
             if ($source === false) {
                 throw new RuntimeException("Failed to read PHP source: {$path}");
             }
 
-            if (self::containsProjectMembersLiteral($source)
-                && ! in_array($relative, self::PROJECT_MEMBERS_LITERAL_ALLOWED, true)) {
-                $violations['project_members_literal'][] = $relative;
+            if (self::containsProjectMembersLiteral($source)) {
+                $detections['project_members_literal'][] = $relative;
             }
-            if (self::containsMembersRelationWrite($source)
-                && ! in_array($relative, self::MEMBERS_WRITE_ALLOWED, true)) {
-                $violations['members_relation_write'][] = $relative;
+            if (self::containsMembersRelationWrite($source)) {
+                $detections['members_relation_write'][] = $relative;
             }
         }
 
+        return $detections;
+    }
+
+    /**
+     * 走査した PHP ファイル (絶対パス)。走査根が実在しなければ空を返す。
+     *
+     * @return list<string>
+     */
+    public static function scannedFiles(?string $rootDirectory = null): array
+    {
+        return self::phpFiles($rootDirectory ?? self::appDir());
+    }
+
+    /**
+     * @return array<string, list<string>> 検出種別 => 違反ファイル (app/ 相対パス)
+     */
+    public static function findViolations(): array
+    {
+        $violations = [];
+        $allowlists = self::allowlists();
+
+        foreach (self::findDetections() as $kind => $detected) {
+            $violations[$kind] = array_values(array_diff($detected, $allowlists[$kind]));
+        }
+
         return $violations;
     }
 
@@ -134,7 +178,7 @@ private static function nextMeaningful(array $tokens, int $from): ?int
         return null;
     }
 
-    private static function appDir(): string
+    public static function appDir(): string
     {
         $dir = realpath(__DIR__.'/../../app');
         if ($dir === false) {
@@ -149,6 +193,10 @@ private static function appDir(): string
      */
     private static function phpFiles(string $dir): array
     {
+        if (! is_dir($dir)) {
+            return [];
+        }
+
         $files = [];
         $iterator = new RecursiveIteratorIterator(
             new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
@@ -171,3 +219,32 @@ private static function phpFiles(string $dir): array
     expect($violations['project_members_literal'])->toBe([]);
     expect($violations['members_relation_write'])->toBe([]);
 });
+
+test('空振り検査: 走査の母集団が空でなく、allowlist の各ファイルが実際に検出されている', function (): void {
+    // (1) 走査根が生きていること
+    $scanned = ProjectMemberPivotWriteScanner::scannedFiles();
+    expect($scanned)->not->toBe([], '走査根 app/ に PHP ファイルがありません');
+    expect(count($scanned))->toBeGreaterThanOrEqual(200);
+
+    // (2) 検出そのものが生きていること。allowlist は「検出されるが許す」ファイルなので、
+    //     検出結果に現れないなら token 判定が壊れている (違反ゼロは検出停止でも成立する)。
+    $detections = ProjectMemberPivotWriteScanner::findDetections();
+    foreach (ProjectMemberPivotWriteScanner::allowlists() as $kind => $allowed) {
+        foreach ($allowed as $relative) {
+            // `toContain()` は可変長引数なので理由は第 2 引数に渡せない (渡すと検索語が増える)
+            expect(in_array($relative, $detections[$kind], true))->toBeTrue(
+                "検出 {$kind} が allowlist の {$relative} を拾えていません (走査が空振りしています)",
+            );
+        }
+    }
+});
+
+test('負のコントロール: 走査根を差し替えると検出が空になる', function (): void {
+    // 上の検査が空振りしていないことの裏取り。走査根の改名・移動を模して
+    // 一致するもののないディレクトリ / 実在しないパスを渡すと検出が 0 件になる。
+    expect(ProjectMemberPivotWriteScanner::findDetections(base_path('config')))->toBe([
+        'project_members_literal' => [],
+        'members_relation_write' => [],
+    ]);
+    expect(ProjectMemberPivotWriteScanner::scannedFiles(base_path('app-renamed')))->toBe([]);
+});
diff --git a/tests/Architecture/QueuedJobLeaseInventoryTest.php b/tests/Architecture/QueuedJobLeaseInventoryTest.php
index eef16694..f9f423d0 100644
--- a/tests/Architecture/QueuedJobLeaseInventoryTest.php
+++ b/tests/Architecture/QueuedJobLeaseInventoryTest.php
@@ -43,6 +43,17 @@
  *   契約ではない (かつ本アプリに 1 件も無い)。
  *
  * 運用契約: docs/architecture.md §キューのリース期間とワーカー制限時間の規約
+ *
+ * ★空振り検査 (母集団非空) の**新規付与は不要**である。理由:
+ *   母集団の非空はすでに 2 段で固定されている。
+ *   (1) 「接続経路: キューに載る全クラスが目録に登録されている」が走査結果と
+ *       QUEUED_JOB_LEASE_INVENTORY (非空の定数) の**対称差 0** を要求するので、
+ *       走査が 0 件になれば `$stale` が全件になって赤くなる。
+ *   (2) 「接続経路: Job / Mailable / Notification の 3 系統が母集団に入っている」が
+ *       系統ごとに**代表クラスの実在**を名指しで固定する (母集団が Job ディレクトリだけに
+ *       縮んでも赤くなる)。
+ *   母集団の列挙そのものは `Tests\Support\QueuedJobPopulation` に一本化されており、
+ *   走査根の生存はそちらの利用側 2 gate が共有して押さえている。
  */
 
 /**
diff --git a/tests/Architecture/RateLimiterKeyConventionTest.php b/tests/Architecture/RateLimiterKeyConventionTest.php
index cc5f5c9c..dc88f7c1 100644
--- a/tests/Architecture/RateLimiterKeyConventionTest.php
+++ b/tests/Architecture/RateLimiterKeyConventionTest.php
@@ -26,6 +26,15 @@
  *       `RateLimiter::for()` の名前集合が inventory と完全一致すること。
  *       解析できない登録 (unresolved) は 1 件でも fail (沈黙する登録を作らせない)。
  *   (2) キーの実挙動 — 各 limiter を実際に評価し、produce されたキーが規約に合うこと。
+ *
+ * ★空振り検査 (母集団非空) の**新規付与は不要**である。理由:
+ *   走査 (`RateLimiterRegistrationScanner::scanDirectory(app_path(), 'app')`) の結果が
+ *   0 件になると、「scan で検出した limiter 名の集合が inventory と完全一致する」が
+ *   非空の inventory (`rateLimiterKeyInventory()`) と食い違って**必ず赤くなる**。
+ *   つまり母集団の非空は完全一致の pin が構造的に担保している。
+ *   加えて各 limiter は実評価されるため、登録が消えれば `rateLimiterProduceLimits()` の
+ *   `Assert::notNull` が落ちる (静的走査と実挙動の両側から空振りが塞がっている)。
+ *   走査器自身の正例 / 負例は `tests/Unit/Architecture/RateLimiterRegistrationScannerTest.php`。
  */
 
 /** キー規約の正規表現 (`{レーン}:{種別}:` の接頭辞)。 */
diff --git a/tests/Architecture/ValidationAttributeCoverageTest.php b/tests/Architecture/ValidationAttributeCoverageTest.php
index 3598b6d7..9418d5c3 100644
--- a/tests/Architecture/ValidationAttributeCoverageTest.php
+++ b/tests/Architecture/ValidationAttributeCoverageTest.php
@@ -19,6 +19,13 @@
  *
  * 規約: validation の呼び出し経路を追加する場合 (`validator()` helper 等) は、本テストの
  *       検出対象パターンにも必ず追加すること。
+ *
+ * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
+ * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である。
+ * 検査 1 は app/Http/Requests、検査 2 は app/ (Requests を除く) を走査根に持ち、
+ * どちらかが改名・移動すると未登録キーゼロのまま緑になる。末尾の「空振り検査」ケースが
+ * 2 つの母集団の非空・床値・代表要素を固定し、その直後の負のコントロールが
+ * 「走査根を差し替えると母集団が空になる」ことを示す。
  */
 
 /**
@@ -57,13 +64,17 @@
  * (FormRequestProhibitedKeyTest と同一パターン。関数名は Pest のグローバル関数衝突を避け
  * validationCoverage* プレフィックスにする)。
  *
+ * @param  string|null  $base  走査根の絶対パス (null = app/Http/Requests)。
+ *                             負のコントロールで別の根を渡すために引数化してある
  * @return list<class-string<FormRequest>>
  */
-function validationCoverageFormRequestClasses(): array
+function validationCoverageFormRequestClasses(?string $base = null): array
 {
     $classes = [];
-    $base = app_path('Http/Requests');
-    expect(is_dir($base))->toBeTrue();
+    $base ??= app_path('Http/Requests');
+    if (! is_dir($base)) {
+        return [];
+    }
 
     $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
@@ -281,27 +292,32 @@ function validationCoverageExtractArrayKeys(array $tokens, int $start, int $end)
 /**
  * app/ 配下 (app/Http/Requests を除く) の inline validation 呼び出しを走査する。
  *
- * @return array{keys: array<string, list<string>>, unparseable: list<string>}
+ * @param  string|null  $root  走査根の絶対パス (null = app/)。
+ *                             負のコントロールで別の根を渡すために引数化してある
+ * @return array{keys: array<string, list<string>>, unparseable: list<string>, scannedFiles: list<string>}
  */
-function validationCoverageScanInlineCalls(): array
+function validationCoverageScanInlineCalls(?string $root = null): array
 {
     $keysByCall = [];
     $unparseable = [];
+    $root ??= app_path();
 
-    $iterator = new RecursiveIteratorIterator(
-        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS),
-    );
     $files = [];
-    /** @var SplFileInfo $file */
-    foreach ($iterator as $file) {
-        if (! $file->isFile() || $file->getExtension() !== 'php') {
-            continue;
-        }
-        $path = $file->getPathname();
-        if (str_starts_with($path, app_path('Http/Requests').'/')) {
-            continue;
+    if (is_dir($root)) {
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
+        );
+        /** @var SplFileInfo $file */
+        foreach ($iterator as $file) {
+            if (! $file->isFile() || $file->getExtension() !== 'php') {
+                continue;
+            }
+            $path = $file->getPathname();
+            if (str_starts_with($path, app_path('Http/Requests').'/')) {
+                continue;
+            }
+            $files[] = $path;
         }
-        $files[] = $path;
     }
     sort($files);
 
@@ -384,7 +400,7 @@ function validationCoverageScanInlineCalls(): array
         }
     }
 
-    return ['keys' => $keysByCall, 'unparseable' => $unparseable];
+    return ['keys' => $keysByCall, 'unparseable' => $unparseable, 'scannedFiles' => $files];
 }
 
 // ──────────────────────────── 検査 1 (FormRequest) ────────────────────────────
@@ -427,6 +443,30 @@ function validationCoverageScanInlineCalls(): array
     expect($violations)->toBe([], 'attributes 未登録キー: '.implode(', ', $violations));
 });
 
+// ──────────────────────────── 空振り検査 (母集団が 0 件で緑にならないこと) ────────────────────────────
+
+test('空振り検査: 2 つの母集団が空でない (走査根が生きている)', function (): void {
+    // 検査 1 の母集団: app/Http/Requests の FormRequest
+    expect(is_dir(app_path('Http/Requests')))->toBeTrue('走査根 app/Http/Requests が存在しません');
+    $formRequests = validationCoverageFormRequestClasses();
+    expect($formRequests)->not->toBe([], '走査根 app/Http/Requests から FormRequest が 1 件も見つかりません');
+    expect(count($formRequests))->toBeGreaterThanOrEqual(25); // 床値 (実測 34 件)
+
+    // 検査 2 の母集団: app/ (Requests を除く) の PHP ファイル
+    $scanned = validationCoverageScanInlineCalls()['scannedFiles'];
+    expect($scanned)->not->toBe([], '走査根 app/ に inline validation の走査対象がありません');
+    expect(count($scanned))->toBeGreaterThanOrEqual(200); // 床値
+});
+
+test('負のコントロール: 走査根を差し替えると 2 つの母集団が空になる', function (): void {
+    // 上の非空検査が空振りしていないことの裏取り。走査根の改名・移動を模して
+    // 別ディレクトリ / 実在しないパスを渡すと母集団が 0 件になる。
+    expect(validationCoverageFormRequestClasses(app_path('Models')))->toBe([]);
+    expect(validationCoverageFormRequestClasses(app_path('Http/Requests-renamed')))->toBe([]);
+    expect(validationCoverageScanInlineCalls(app_path('Http/Requests'))['scannedFiles'])->toBe([]);
+    expect(validationCoverageScanInlineCalls(base_path('app-renamed'))['scannedFiles'])->toBe([]);
+});
+
 // ──────────────────────────── 検査 2 (inline validation, fail-closed) ────────────────────────────
 
 test('inline validation のルールキーが validation attributes に登録されている (fail-closed)', function (): void {

```

## テスト結果

- `composer test`: 5823 tests, 5821 passed, 2 skipped, 0 failed (Architecture レーンは 1121 件で全緑)
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (2224) / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106): すべて green
- 赤の確認: 9 本の走査根の既定値を存在しないパスへ一時的に書き換えて当該 9 ファイルを実行し、付与した 9 件の空振り検査がすべて赤になることを確認済み (書き換えは戻した)

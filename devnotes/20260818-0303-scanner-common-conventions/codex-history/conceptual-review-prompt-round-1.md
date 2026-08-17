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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は上に挿入済み）

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の前提】
- 本件は**文書変更が主体**である。AGENTS.md に「静的検査 (gate) と走査器の共通規約」節を 1 つ追加し、
  既存の走査器・gate との食い違いを devnotes に記録する。app/ 等の実装コードは 1 行も変えない。
- 家系 (複数リポジトリで共有する機能台帳 lctl) の機能 `static-scanner-substrate` の正典 v1 への追従であり、
  裁定 AG-154 (2) の解消にあたる。正典 v1 の 5 条は概念設計のブロック 1 に転記してある。
- 参照実装は同じ家系の aigenba (AGENTS.md に 5 条 + 走査器索引 + 索引の投影を検証する gate 2 本を持つ)。
  本設計は**索引と投影 gate を作らない**方向でスコープを切っている。この判断の妥当性は重点的に見てほしい。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計 (devnotes/20260818-0303-scanner-common-conventions/conceptual-design.md)

# 概念設計: 静的検査 (gate) の走査器に共通規約を与える

対象機能 (lctl): `static-scanner-substrate` / 正典 `v1`
解消する裁定: **AG-154 (2)** (「構文解析ライブラリの依存追加は求めない。正典の必須要素にはしない。
ただし条件を 2 点課す — 解決できない形は fail-closed にすること / 解決の範囲を負例で裏取りすること」)

## 背景・課題

本リポジトリはアーキテクチャ上の禁止事項を人のレビューではなく機械で見張るため、
テストコードの中に**自前のソース走査器**を多数持っている
(`tests/Support/` 配下の検出器 15 本前後 + `tests/Architecture/` の gate 130 本 +
`tests/js/architecture/` の gate 22 本。HEAD 実測)。

家系の台帳 (lctl) における本リポジトリの状態は **pending** で、その理由は
「構文解析ライブラリが無いから」ではなく **「規約が未成文だから」** である
(台帳の aicue セル / 差分巡回 2026-08-16・2026-08-17)。実際、正典 v1 が挙げる中身は
すでに個別実装として現れている:

| 正典 v1 の条 | 本リポジトリでの現れ方 (実測) |
|---|---|
| 解決できない形は落とす (fail-closed) | T180 の `NonCompoundGlobalUseScanner` (読めなかった宣言を `unresolved` として赤にする) / `StrictTypesRuntimeProbe` (子プロセスへ到達できなければ偽を返さず例外) |
| 検出力は負例で裏取りする | `tests/Architecture/fixtures/global-use/` の見本 12 本 (`php -l` を真値に名前・行まで照合) / `tests/Unit/Architecture/` の自己検査 11 本 / gate 内の合成負例 (`PromptGuardrailTest` / `JobDeferralTerminationGateTest` の E12〜E14) |
| 語彙一致の否定形はトークンの完全一致で判定する | T180 の DS 純度 gate の是正 (許可語の除去を素の部分文字列から class トークンの完全一致へ改めた。**是正前は実際に検出漏れだった**) |
| 走査結果を判定に使わない形を作らない | 各 gate が収集結果を必ず `expect` へ渡す形 |
| クラス参照は完全修飾名で突き合わせる | `PhpReferenceScanner` の alias / group use 解決、`PrismDirectDispatchScanner` の同名別クラス除外 |

つまり**実践が先行し、成文化だけが残っている**。この状態の実害は 2 つある。

1. **新しい gate を書く人が毎回自分で発明する**。T180 は「許可語の除去が素の部分文字列だったため
   検出漏れになっていた」ことを実測で見つけて直した事例だが、この教訓は
   `ds-purity.ts` の中にしか書かれておらず、次に語彙一致を書く人へ届かない。
2. **保証範囲の書き方が gate ごとにばらつく**。「保証範囲を誇張しない」という作法は
   本リポジトリの慣習として定着している (`AGENTS.md` の各節・多数の gate の docblock) が、
   何を書くべきかの正本が無く、書かない gate も残っている。

## 改善アイデア

`AGENTS.md` に **「静的検査 (gate) と走査器の共通規約」節を 1 つ**足し、正典 v1 の 5 条を
本リポジトリの語彙で成文化する。あわせて、規約に照らして既存の走査器・gate を棚卸しし、
**食い違いを本設計ディレクトリに一覧として記録する** (是正は別 TODO)。

節に書く内容は次の 3 ブロックだけにする。

### ブロック 1: 5 条 (正典 v1 の (a)〜(e) をそのまま写す)

- **(a) クラス参照は完全修飾名で突き合わせる**。`use` / group use / 別名つき取り込みを解いた
  完全修飾名で比較する (短名一致は別名一発で黙り、末尾セグメント一致は同名の別クラスを拾う)。
  **構文解析ライブラリの使用は必須ではない** (裁定 AG-154 (2))。字句走査 + 取り込み対応表でよく、
  条件は (b) と (c) を満たすことだけである。
- **(b) 解決できない形は落とす (fail-closed)**。解析不能・動的呼び出し・変数経由・未知の構文・
  **抽出 0 件**・目録が空 — いずれも「通す」ではなく「落とす」に倒す。
  判定を拾いすぎる方向へ倒すのは可、見逃す方向へ倒すのは不可。
- **(c) 検出力は負例で裏取りする**。わざと違反させた入力を検出できることと、
  規定どおりの入力を誤検出しないことの**両方向**を固定する。
- **(d) 集めた走査結果を判定に使わない形を作らない**。収集するが誰も参照しない出力、
  数えるだけで比較しない目録を作らない。
- **(e) 語彙一致の否定形は区切り文字で分割したトークンの完全一致で判定する**。
  正規表現の語境界や素の部分文字列一致に頼らない。

### ブロック 2: 走査器・gate を新設するときに同じ PR で揃えるもの (4 点)

1. 負例と正例 (**テストファーストで先に赤くしてから**本体を書く。既存の抽出器を流用して
   最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する)
2. 解決できない形を落とす分岐 ((b))
3. **走査が空振りしていないことの検査** — 母集団が空でないこと / 走査根がそれぞれ生きていること。
   ((b) の「抽出 0 件」に対応する具体形。準拠実装は `FfmpegProcessLaunchInventoryTest` の
   「母集団が空でない」1 本、`PromptGuardrailTest` の「5 走査根が解決でき、いずれも空でない」)
4. docblock に**走査対象と保証しないもの**を書く (正本は docblock 側に置き、本書へ写さない)

### ブロック 3: 本リポジトリ固有の 2 点

- **走査根の単一出典**: git 追跡下の PHP 全数を母集団にする走査は
  `Tests\Support\TrackedPhpSourceFiles` を使う (現在 6 gate が利用)。同じ列挙を 2 本持たない。
  母集団がそれより狭い走査は自分の根を持ってよいが、**存在しない根は fail-fast** で落とす
  (準拠実装 `PrismDirectDispatchScanner::roots()`)。
- **負例の置き場は 3 通りとも認める**: 見本ファイル (`tests/Architecture/fixtures/`) /
  検出器の自己検査 (`tests/Unit/Architecture/`) / gate 内の合成入力。
  どこに置いてもよいが、**gate または検出器の docblock から辿れること**。
  (実測: 現在この 3 通りが併存しており、どれも規約の趣旨を満たしている。
  1 つへ寄せる作業に見合う効果が無い)

### 検出力の主張の書き方 (AG-154 (1) と同型)

「検査ファイルが実在する」と「検出力が裏取りされている」は別物である。後者を主張する記述は
根拠を同じ行に併記し、併記の無い記述は**検出力未確認**と読む。
**遡及して裏取りを付ける作業は求めない** (AG-154 (1))。

## 期待効果

- 使命への貢献は間接である。本リポジトリの機械検査群は、撮影 PWA の 3 枚セット・
  テナント境界・プロンプト防御といった**現場作業者の安全と体験に直結する不変条件**を守っている。
  走査器が黙って見逃せばその不変条件が壊れても誰も気付かない。規約はその信頼度を支える。
- 新しい gate を書く人が (b) (c) (e) を毎回発明しなくてよくなる。とくに (e) は
  本リポジトリで**実際に検出漏れを起こした**規則である (T180)。
- 台帳の aicue セルが挙げる pending 理由 (規約が未成文) が解消し、
  実践先行の状態を実装済みとして申告できるようになる。

## 実装方針 (概要)

- `AGENTS.md` に新節を 1 つ追加する。位置は **「禁止する文」節の直後・「実装規約」節の直前**
  (直前の節がまさに走査器で強制される個別規約であり、その一般形として読める並びになる)。
- 既存節は書き換えない。各 gate の詳細 (件数・免除・保証しないもの) は既存の正本
  (docblock / `docs/architecture.md`) に残し、新節へ写さない (2 か所に書くと必ず食い違う)。
- 食い違いの一覧は本設計ディレクトリの `divergence-survey.md` に置き、
  是正が要るものは**別 TODO として起票する申し送り**にする。

## 制約・前提

- 本リポジトリは構文解析ライブラリ (nikic/php-parser) を持たない。裁定 AG-154 (2) が
  依存追加を求めないと定めているため、字句走査のままでよい。
- `AGENTS.md` の編集規約: 同じ事実を 2 か所に書かない / 件数を本書へ写さない /
  相互参照は番号ではなく項目名で指す。
- 検証コマンドは全 green が前提だが、本変更は文書のみのため
  `AGENTS.md` を読む機械検査 (`verification-commands-doc-sync.test.ts` のマーカー区間) を
  壊さないことだけ確認すればよい。

## スコープ外

- **aigenba 形の走査器索引 (`ScannerConventionIndex`) と AGENTS.md への投影 gate**。
  索引の全数性を担保するには「走査器候補を洗い出す走査器」(aigenba の `ScannerCandidateScanner`)
  が新規に要り、規模がこのタスクの 1 桁上になる。正典 v1 は索引を必須要素にしておらず、
  AG-154 (1) は遡及の棚卸しを求めていない (思考原則 2)。
  **2 度目の「索引が無いせいで漏れた」実測が出たら起票する**、と申し送りに残す。
- **食い違いの是正そのもの**。とくに `PhpReferenceScanner` の部分修飾名の扱い
  (`divergence-survey.md` D1) は 6 gate + 2 派生走査器へ波及するため、本 TODO では直さない。
- **走査器を 1 本の共通基盤へ統合すること**。現在の 3 層 (`PhpTokenScan` →
  `PhpReferenceScanner` → 各判定層) は既に単一出典であり、これ以上の統合は要らない。
- 台帳への書き戻し (`append_event`)。監督セッションの責務。

## 棚卸し結果 (同ディレクトリ divergence-survey.md)

# 規約と既存 gate の食い違い (棚卸し)

観測点: `main` @ 1194d34 (2026-08-18 実測)。
判定に使った 5 条は `conceptual-design.md` のブロック 1 (家系の正典 v1 の (a)〜(e))。

**この棚卸しは是正を求めるものではない**。AG-154 (1) は既存分への遡及を求めていない。
ここに書くのは「規約を敷いたときに、既存のどこが規約どおりでないか」を後から探し直さずに
済むようにするためであり、是正が要るものは別 TODO として起票する。

## 母集団と数え方

- 検出器: `tests/Support/` 配下でソースを読んで site を列挙するクラス。
  ```bash
  git ls-files 'tests/Support/**/*.php' | xargs -I{} sh -c 'rg -lq "token_get_all|PhpTokenScan|Finder::create|RecursiveDirectoryIterator" {} && echo {}'
  ```
- gate: `tests/Architecture/*.php` (130 本) と `tests/js/architecture/*.test.ts` (22 本)。

## D1: 部分修飾名を解決しないまま通す (`PhpReferenceScanner`) — (a) と (b) の両方に抵触

`tests/Support/PhpReferenceScanner.php` は `T_NAME_QUALIFIED` (`Foo\Bar` のような部分修飾名) を
`ltrim($text, '\\')` するだけで、**現在の名前空間への相対解決も先頭要素の別名解決も行わない**。
docblock (48-54 行) が自らこう書いている:

> `use Illuminate\Support\Facades; … Facades\Http::get()` は解決できない。
> これは既存 gate と同じ非対称であり、抽出は**振る舞い保存**が目的なのでここを直さない。

規約の観点では 2 つ問題がある。

1. (a) に反する。解決できていない名前を、あたかも解決済みの名前として emit している。
2. (b) に反する。解決できない形なのに**落とさず通す**。利用側は完全修飾名の一覧と
   突き合わせるため、`Facades\Http::get()` は一覧に一致せず**無言で母集団から外れる**。

**波及**: 本走査器を直接使う gate 6 本
(`PastDueSinceWriteInvariantTest` / `NoMessageCarrying404Test` / `LlmDefenseConfigGateTest` /
`PromptDefenseWindowGateTest` / `PromptGuardrailTest` / `AccountDeletionPathGateTest`) と、
上に乗る検出器 2 本 (`ExternalSeam\ExternalSeamScanner` / `ExternalClientBoundaryScanner`)、
さらにその先の目録 gate。**セキュリティ不変条件に直結する経路を含む**
(外部到達点の目録 / プロンプト防御の窓口)。

**扱い**: 本 TODO では直さない (波及が広く、規約の成文化とは別の作業量になる)。
`ltrim` のまま通すのではなく `unresolved` として落とす形へ寄せる是正を**別 TODO として起票する**。
現状のまま残す間は、docblock の「ここを直さない」を
「**解決していないので (b) を満たしていない。是正は別 TODO**」と読める文へ直すのが最低限である。

## D2: 走査の空振り検査が全体には無い — (b) の「抽出 0 件」

母集団が空になったときに緑のまま通る形が残っている。heuristic な数え方は次のとおり
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

## D3: 走査根の単一出典化が途中 — 本リポジトリ固有の条

`Tests\Support\TrackedPhpSourceFiles` (git 追跡下の PHP 全数) を使う gate は 6 本
(`ForbiddenStatementTokenInvariantTest` / `StrictTypesDeclarationGateTest` /
`NoNonCompoundGlobalUseTest` / `BughuntNamingResidualTest` / `RouteCacheExemptionPremiseTest` /
`LaneExternalFakeBindingTest`)。

一方、母集団がそれより狭い走査は自分の根を持っている
(`PrismDirectDispatchScanner::ROOT_DIRECTORIES` の 5 本、`NoMessageCarrying404Test` の 3 本、
`FfmpegProcessLaunchInventoryTest` の `app` 1 本 等)。

**これは食い違いではない**と判定する。全数走査と部分走査は母集団の定義が違い、
片方に寄せると走査域が黙って変わる。ただし部分走査の側には
「**存在しない根は fail-fast**」が要る (準拠実装 `PrismDirectDispatchScanner::roots()`)。
この条件を規約のブロック 3 に明記する。

## D4: 負例の置き場が 3 通りある — (c) には抵触しない

- 見本ファイル: `tests/Architecture/fixtures/global-use/` の 12 本のみ
- 検出器の自己検査: `tests/Unit/Architecture/` の 11 本 (219 test)
- gate 内の合成入力: `PromptGuardrailTest` (Prism 直呼びの正例・負例 8 本) /
  `JobDeferralTerminationGateTest` (E12 / E13 / E14) /
  `PromptDefenseWindowGateTest` (「合成負例で判定が発火し、正例では発火しない」)

**実測の結論**: `tests/Support/` の検出器で「負例による裏取りをまったく持たないもの」は
**見つからなかった**。当初 4 本 (`PhpReferenceScanner` / `PromptWindowScanner` /
`PrismDirectDispatchScanner` / `JobDeferralScanner`) を候補として挙げたが、実読すると
すべて上記 3 通りのどれかで裏取りされている
(`PhpReferenceScanner` は `ExternalClientBoundaryScannerTest` の 14 test と
`ExternalSeamScannerTest` の 21 test が別名解決・group use・scope 追跡・
コメント / 文字列リテラル除外を通して押さえている)。

したがって規約は**置き場を 1 つに強制しない**。強制するのは
「gate または検出器の docblock から負例へ辿れること」だけにする。

## D5: 保証範囲の書き方 — 慣習は定着しているが正本が無い

「保証しない / 誇張しない」を明記しているファイルは `tests/` 配下で 60 本以上ある。
`AGENTS.md` にも同じ作法が各節に散っている (テストレーンの外部 HTTP 出口 / 禁止する文 /
ドメイン固有規約 9・11・14・15・18 ほか)。

**食い違いではないが、要求として書かれた場所が無い**。新節のブロック 2 の 4 番として
「docblock に走査対象と保証しないものを書く」を置き、**中身は docblock 側を正本とする**
(件数や条件を `AGENTS.md` へ写さない)。

## 別 TODO として起票を申し送るもの

| # | 内容 | 根拠 |
|---|---|---|
| 1 | `PhpReferenceScanner` の部分修飾名を `unresolved` として落とす形へ寄せる (波及 6 gate + 2 検出器) | D1 |
| 2 | 空振り検査を持たない走査 gate 12 本の分類と付与 | D2 |

いずれも本 TODO のスコープ外である (`conceptual-design.md` スコープ外節)。

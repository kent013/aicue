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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

（アプリの使命・禁止事項は上に挿入済み）

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC

【本件固有の前提】
- 本件は文書変更 (AGENTS.md への節追加) と、テスト用走査器の docblock 修正の 2 施策だけである。
  アプリケーションコードは 1 行も変えない。
- 概念設計は本セッションの Codex レビュー Round 3 で APPROVED 済み。今回は詳細設計のレビューである。
- **重点的に見てほしい点**:
  (1) AGENTS.md へ挿入する文面そのものの正確性・読み手の誤読の余地
  (2) 挿入位置と、AGENTS.md を読む既存の機械検査への影響の洗い出しに漏れが無いか
  (3) 「新しいテストを持たない」判断が禁止事項 1 と整合しているか
  (4) docblock 修正の文面が、読み手に正しい限界を伝えるか

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ（AGENTS.md のセキュリティ不変条件）
10. DESIGN.md 準拠（UI/frontend 変更を含む場合）
11. Atomic Design 準拠（UI/frontend 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: 静的検査 (gate) と走査器の共通規約の成文化

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

`AGENTS.md` の「禁止事項」節が正本 (9 項目)。本施策に直接関わるのは次の 2 つである。

- **1. テストなしの実装完了報告** — 本施策は**新しい不変条件を機械化しない**。
  したがって「機械検査を足さないこと」自体を設計判断として明記し、
  その保証範囲 (人が読んで守る規約であり機械では強制しない) を `AGENTS.md` 本文へ書く
  (§本施策が新しいテストを持たない理由)。
- **9. Artifact の使用** — 成果物はリポジトリ内のファイルとして出力する。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** テストフレームワーク (`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行 (`tests/Pest.php` でグローバル適用)
- `declare(strict_types=1)` + 日本語コメント
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

**本施策はアプリケーションコードを 1 行も変えない**ため、上記のうち実際に効くのは
「日本語コメント」だけである。変更対象は `AGENTS.md` (文書) と
`tests/Support/PhpReferenceScanner.php` の docblock (コメント) の 2 ファイル。

## 概念設計リファレンス

- `devnotes/20260818-0303-scanner-common-conventions/conceptual-design.md` (Codex Round 3 で APPROVED)
- 棚卸し: `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `AGENTS.md` へ「静的検査 (gate) と走査器の共通規約」節を追加する | `AGENTS.md` | 高 |
| 2 | `PhpReferenceScanner` の docblock を実態に合わせて直す (コメントのみ) | `tests/Support/PhpReferenceScanner.php` | 高 |

---

## 施策 1: `AGENTS.md` へ共通規約の節を追加する

### 変更箇所

- ファイル: `AGENTS.md`
- 位置: **206 行 (「禁止する文」節の最終行) と 208 行 (`## 実装規約`) の間**

  ```
  206  「この検査があれば直接出力は 1 つも無い」とは読めない
  207  (空行)                       ← ここへ新節を挿入する
  208  ## 実装規約
  ```

- この位置にする理由: 直前の「禁止する文」節は**走査器で強制されている個別規約**であり、
  新節はその一般形として読める。直後の「実装規約」はアプリ側の書き方なので、
  検査側の規約は手前でまとまる。

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし
- テストファイル: なし (§本施策が新しいテストを持たない理由)
- `docs/` 配下: なし。**各 gate の詳細 (件数・免除・保証しないもの) は既存の正本
  (docblock / `docs/architecture.md`) に残し、新節へ写さない** (2 か所に書くと必ず食い違う)
- `docs/template-divergence.md`: **登録不要**。テンプレート (laravel-claude-template) 側も
  本規約を未成文のまま持っており、本施策は家系の標準形へ**寄る**方向の変更である。
  逸脱の判定軸 (「同じ不変条件を同じタイミング / 抽象度で保証するか」) に触れない

### 変更後の文面 (挿入する全文)

```markdown
## 静的検査 (gate) と走査器の共通規約

テストの中に置く自前のソース走査器 (`tests/Support/` 配下の検出器) と、それを使う gate
(`tests/Architecture/` / `tests/js/architecture/`) は次の 5 条を満たす。
家系の機能台帳の正典 v1 をそのまま写したもので、5 条とも
**「検査は緑なのに穴が開いていた」実測事故**から出ている
(設計と既存の食い違いの棚卸しは `devnotes/20260818-0303-scanner-common-conventions/`)。

- **(a) クラス参照は完全修飾名で突き合わせる**。`use` / group use / 別名つき取り込みを解いた
  完全修飾名で比べる。短名一致は別名つき取り込み 1 つで検査が黙り、末尾の要素だけの一致は
  同名の別クラスを拾う。**構文解析ライブラリの使用は必須ではない** (家系の裁定 AG-154 の (2))。
  字句走査 + 取り込み対応表でよく、条件は (b) と (c) を満たすことだけである。
  **完全に解決できなかった参照を完全修飾名として emit しない** — (b) で落とすか、
  走査の対象外だと明示するかのどちらかにする。対象外にしたなら、その形について
  **検出力を主張しない**
- **(b) 解決できない形は落とす (fail-closed)**。解析不能・動的呼び出し・変数経由・未知の構文は
  「通す」ではなく「落とす」に倒す。判定を拾いすぎる方向へ倒すのは可、見逃す方向へ倒すのは不可。
  - **「違反が 0 件」と「母集団が 0 件」を区別する**。落とすのは後者だけである。
    違反ゼロが正常な gate はいくらでもあるが、**判定に使う母集団が空**なのに緑になる形は、
    走査根の改名・ディレクトリ移動・抽出条件の綴り間違いで**走査が壊れても気付けない**
  - 適用対象は「母集団の非空が不変条件である gate」である。**入力を受け取って候補を返し、
    母集団の非空を契約としない再利用可能な検出器は対象外**で、0 件が正常な入力はいくらでもある。
    その場合は検出器を**使う側の gate** が母集団の非空を持つ
- **(c) 検出力は負例で裏取りする**。わざと違反させた入力を検出できることと、
  規定どおりの入力を誤検出しないことの**両方向**を固定する
- **(d) 集めた走査結果を判定に使わない形を作らない**。収集するが誰も参照しない出力、
  数えるだけで比べない目録を作らない
- **(e) 語彙一致の否定形は区切り文字で分割したトークンの完全一致で判定する**。
  正規表現の語境界や素の部分文字列一致に頼らない
  (許可語の除去を素の部分文字列で書いたため、打ち消しや接頭辞つきの記述まで一緒に消えて
  検出漏れになっていた、が本リポジトリの実測である)

### 走査器・gate を新設・変更するときに同じ PR で揃える 4 点

1. **負例と正例**。テストファーストで**先に赤くしてから**本体を書く (思考原則 5)。
   既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する
2. **解決できない形を落とす分岐** ((b))
3. **走査が空振りしていないことの検査**。母集団が空でないこと / 走査根がそれぞれ生きていること
   (準拠実装: `FfmpegProcessLaunchInventoryTest` の「母集団が空でない」1 本、
   `PromptGuardrailTest` の「5 走査根が解決でき、いずれも空でない」)
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

> **本節の保証範囲 (誇張しない)**: 本節は**人が読んで守る規約であり、機械では強制しない**。
> 走査器の書き方を検査する仕組み (家系の先行実装が持つ走査器の索引と、その索引を文書へ
> 投影して整合を見張る検査) は**作っていない**。したがって本節があっても
> 「すべての gate が 5 条を満たしている」とは読めない。**満たしていない箇所は実在し**、
> `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` に記録してある。
> 索引の新設を再検討する条件は同ディレクトリの概念設計に書いてある
> (新設 gate のレビューで規約の適用漏れが見つかった / 走査器候補の棚卸しをもう一度やる必要が出た /
> 全数性を主張する棚卸しが必要になった、の 3 つ)。
```

### 挿入時の注意 (機械検査との整合)

| 検査 | 影響 | 根拠 |
|---|---|---|
| `verification-commands-doc-sync.test.ts` | なし | 読むのは `VERIFICATION_COMMANDS` マーカー区間だけ。新節はその外側 |
| `RouteCacheExemptionPremiseTest` | なし | `route:cache` を含むファイルの**件数**を pin する。`AGENTS.md` は既に母集団に居り、新節にこの語を入れないので件数は動かない |
| `RetiredRecoveryReferenceGateTest` | なし | `AGENTS.md` を走査するが、検出語彙は撤去済みの回収コマンド名・クラス名。新節に現れない |
| `BughuntNamingResidualTest` | なし | 撤去済みの bug-hunt 語彙を見る。新節に現れない |
| `ClaudeHooksWiringTest` | なし | `CLAUDE_HOOKS_WIRING` マーカー区間だけを読む |

### 文面の書き方の制約 (`AGENTS.md` の既存規約に従う)

- **件数を本書へ写さない**。「現在 6 gate が利用」のような数はドリフトするので書かない
  (`TrackedPhpSourceFiles` の利用者数、fixtures の本数、gate の本数はすべて書かない)
- **相互参照は番号ではなく項目名で指す**
- **造語を作らない**。家系の台帳へ複写される語を新しく作らない
  (`SSC-a` のようなラベルは先行実装が持つが、本リポジトリの `AGENTS.md` は
  通し番号で参照する作法なので、正典 boundary と同じ **(a)〜(e)** をそのまま使う)

### テスト計画

- **新規テストなし** (§本施策が新しいテストを持たない理由)
- 既存検査の非退行を確認する: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` が全 green
  (`AGENTS.md` §実装規約の検証コマンド。文書中心の変更でも縮小しない)

### リスク

- **規約が守られないまま残るリスク**。機械強制が無いため、新設 gate が 5 条を外しても CI は落ちない。
  → 保証範囲として本文へ明記し、索引の新設を再検討する条件を 3 つ書いて逃げ道を塞ぐ。
- **`AGENTS.md` が長くなる**。既に 800 行超で、節を増やすと読まれない箇所が増える。
  → 新節は 5 条 + 4 点 + 2 点 + 書き方 + 保証範囲に限り、
  個別 gate の事情 (件数・免除・保証しないもの) を 1 つも写さない。

---

## 施策 2: `PhpReferenceScanner` の docblock を実態に合わせて直す

### 変更箇所

- ファイル: `tests/Support/PhpReferenceScanner.php` (50-54 行)
- **判定ロジックは 1 行も変えない**。docblock だけを書き換える

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし
- テストファイル: なし (振る舞いが変わらないため既存 35 test はそのまま緑)

### 現行コード

```php
     * ★**名前解決の限界** (現行 `ExternalClientBoundaryScanner` の挙動をそのまま保存する):
     *   `T_NAME_QUALIFIED` (`Foo\Bar` のような部分修飾名) は `ltrim($text, '\\')` するだけで、
     *   「現在の namespace への相対解決」も「先頭 segment の alias 解決」も**行わない**。
     *   したがって `use Illuminate\Support\Facades; … Facades\Http::get()` は解決できない。
     *   これは既存 gate と同じ非対称であり、抽出は**振る舞い保存**が目的なのでここを直さない。
```

### 変更後コード

```php
     * ★**名前解決の限界 = 共通規約 (b) を満たしていない既知の穴**
     *   (`AGENTS.md` の「静的検査 (gate) と走査器の共通規約」):
     *   `T_NAME_QUALIFIED` (`Foo\Bar` のような部分修飾名) は `ltrim($text, '\\')` するだけで、
     *   「現在の namespace への相対解決」も「先頭 segment の alias 解決」も**行わない**。
     *   したがって `use Illuminate\Support\Facades; … Facades\Http::get()` は解決できず、
     *   **解決できないまま完全修飾名として emit される**。利用側は完全修飾名の一覧と
     *   突き合わせるので、この形は一覧に一致せず**無言で母集団から外れる** (= 見逃す側へ倒れている)。
     *   抽出したときは**振る舞い保存**が目的でここを触らなかったが、
     *   これは**規約に照らして是認された限界ではなく、是正待ちの穴**である
     *   (直すと本走査器を使う gate と派生検出器の母集団が増えるため別 TODO で扱う。
     *   棚卸しは `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` の D1)。
     *   **したがって部分修飾名で書かれた参照について本走査器は検出力を主張しない。**
```

### この文面にする理由

現行の「**ここを直さない**」は、規約を導入した後は
「規約に照らして是認された限界」と読めてしまう。実際には (a) にも (b) にも反しており、
しかも**見逃す側へ倒れている**。読み手が「この走査器は完全修飾名で突き合わせているから安全」と
誤読するのを止めるのが本施策の目的である。

### PHPStan 適合チェック

- [x] コメントのみの変更で型に触れない (戻り値の型・generics ともに変更なし)
- [x] `declare(strict_types=1)` は既存のまま
- [x] 日本語コメントの規約に従う

### テスト計画

- **新規テストなし**。振る舞いが変わらないので `ExternalClientBoundaryScannerTest` (14 test) /
  `ExternalSeamScannerTest` (21 test) はそのまま緑であること**だけ**を確認する
- 個別の `DatabaseTransactions` を使っていないことを確認 — 該当なし (テストを足さない)

### リスク

- **穴を明記したことで「直すまで gate を信用しない」と過剰に読まれる**可能性。
  → 影響範囲を「部分修飾名で書かれた参照」に限って書き、それ以外の形 (別名つき取り込み /
  group use / 完全修飾名) の解決は従来どおり主張する。

---

## 本施策が新しいテストを持たない理由 (禁止事項 1 との関係)

`AGENTS.md` の禁止事項 1 は「テストなしの実装完了報告」を禁じ、
「不変条件は対応する Architecture/Feature テストへの登録まで含めて実装済み」と定めている。
本施策は次の理由でテストを足さない。

1. **新しい不変条件を導入していない**。5 条はいずれも既に個別実装として存在する作法であり、
   本施策が足すのは**その作法の正本の置き場所**だけである。
   新しく守らせる対象 (コード上の状態) が無いので、登録すべき不変条件が無い。
2. **機械化するには走査器候補の全数抽出器が要る**。規約の遵守を検査するには
   「走査器を洗い出す走査器」と索引が要り (先行実装 aigenba の形)、規模がこのタスクの 1 桁上になる。
   正典 v1 は索引を必須要素にしておらず、裁定 AG-154 の (1) は遡及の棚卸しを求めていない。
3. **代わりに保証範囲を本文へ書く**。「機械では強制しない」「満たしていない箇所が実在する」
   「索引を再検討する条件はこれ」を `AGENTS.md` 本文に書くことで、
   読み手が「規約があるから守られている」と誤読する経路を塞ぐ。
   これは本リポジトリが既に採っている作法 (§テストレーンの外部 HTTP 出口 / §禁止する文 の
   「保証範囲を誇張しない」) と同じ形である。

## 完了条件

1. 施策 1 / 2 の変更が入り、検証コマンドが全 green
2. `divergence-survey.md` の申し送り表の **2 行とも** 追跡先 TODO ID が埋まっている
   (**監督者が起票して採番する**。本 TODO の作業者は `docs/TODO.md` を変更しない)。
   とくに D1 はセキュリティ不変条件に波及するため、ID 未採番のまま完了扱いにしない

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 変更が 2 ファイル (文書 1 + コメント 1) に閉じ、他施策と共有するコードが無い。段階分割する粒度が無い |
| 競合リスク | `AGENTS.md` を触る他 TODO と行が競合しうる。挿入位置が「禁止する文」節の直後で固定なので、merge 時は節の順序だけ確認すれば足りる |

---

## 関連する現行コード

### AGENTS.md の挿入位置の前後 (196-215 行)
```markdown
  目録へ登録する (deny-by-default)。件数は完全一致で、増えても減っても赤になる。
  **登録の正本は目録 (`forbiddenStatementExemptions()`) だけ**で、本書には件数を写さない
  (2 か所に書くと必ず食い違う)。登録できるのは `scripts` / `tests` に限る。
  例外に登録したファイルも**全語彙を走査する** (登録の無い語彙は 1 件残らず違反になる)
- **語彙を勝手に増やさない**。`print` は正典が対象外と定めており、
  拡張は家系の機能台帳の議題として起こす決まりである
- **保証範囲を誇張しない**: 効くのは字句として現れる 4 語彙だけである。
  名前の解決が要る出力 (書式つき出力 / 変数の内容の表示 / 標準出力への書き込み)、
  Blade の `@php … @endphp` と二重波括弧の中、ヒアドキュメント本文には
  **無言で効かない** (PHP 開始タグで開いた区間は見える)。
  「この検査があれば直接出力は 1 つも無い」とは読めない

## 実装規約

- `declare(strict_types=1)` + 日本語コメント。**宣言は git 追跡下の PHP 全数が対象**で、
  免除の登録簿は持たない(`StrictTypesDeclarationGateTest` が deny-by-default で強制。
  `*.blade.php` は PHP ソースではないため対象外)。Controller は薄く(Service 委譲)、
  transaction は Service 内。保護キーは forceFill / relation で明示代入
- 月 / 年 / 四半期の加減算は**暗黙 overflow メソッドを禁止**する。既定は
  `addMonthNoOverflow` / `subYearNoOverflow` 等の `*NoOverflow`、overflow が要件なら
```

### AGENTS.md 「禁止する文」節の全文 (178-206 行) — 新節が一般形になる対象
```markdown
## 禁止する文 (echo / goto / global / 開始タグ付きの出力記法)

PHP の `echo` / `goto` / `global` の 3 文と、開始タグ付きの出力記法 (`<?` に `=` を続ける書き方) は
**書かない**。字句 (トークン) 単位の走査で検出する
(`tests/Architecture/ForbiddenStatementTokenInvariantTest.php`。
設計は `devnotes/20260815-1537-forbidden-statement-token-gate/`)。

- 理由: 出力する 2 つの記法は Laravel の応答制御 (Inertia / JsonResource / Response) を
  迂回して直接出力へ書き出すため、ヘッダ確定前に本文を流し得る。
  撮影 PWA が依存する 3 枚セット (no-store baseline / bfcache 秘匿 /
  Inertia 履歴暗号化。ドメイン規約 3) を壊し得る経路になる。
  `goto` は制御フローを構造から読めなくし、`global` は DI コンテナ経由の
  依存解決を迂回して差し替えられない結合を作る
- 走査対象は **git 追跡下の `*.php` 全件** (`.blade.php` を含む)。
  置き場所は「走査する / 例外の登録を許す (`scripts` `tests`) / 除外する
  (`devnotes`。理由必須)」の 3 つへ**排他的に分類**し、
  **どれにも分類していない置き場所が現れたら赤になる**
- 例外は `ForbiddenStatementExemption` + 30 文字以上の根拠 + **件数**付きで
  目録へ登録する (deny-by-default)。件数は完全一致で、増えても減っても赤になる。
  **登録の正本は目録 (`forbiddenStatementExemptions()`) だけ**で、本書には件数を写さない
  (2 か所に書くと必ず食い違う)。登録できるのは `scripts` / `tests` に限る。
  例外に登録したファイルも**全語彙を走査する** (登録の無い語彙は 1 件残らず違反になる)
- **語彙を勝手に増やさない**。`print` は正典が対象外と定めており、
  拡張は家系の機能台帳の議題として起こす決まりである
- **保証範囲を誇張しない**: 効くのは字句として現れる 4 語彙だけである。
  名前の解決が要る出力 (書式つき出力 / 変数の内容の表示 / 標準出力への書き込み)、
  Blade の `@php … @endphp` と二重波括弧の中、ヒアドキュメント本文には
  **無言で効かない** (PHP 開始タグで開いた区間は見える)。
  「この検査があれば直接出力は 1 つも無い」とは読めない
```

### tests/Support/PhpReferenceScanner.php (1-60 行)
```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * PHP ソースの「名前参照 / 構築 / 呼び出し」を列挙する中立走査器 (純関数)。
 *
 * ★走査は `PhpTokenScan::normalize()` (空白 / コメント / DocComment 除去) の結果に対して行う。
 *   `T_CONSTANT_ENCAPSED_STRING` の中身は名前解決の対象にしない。
 * ★**何を「外部到達」とみなすかは一切知らない**。判定は利用側 (`ExternalClientBoundaryScanner` /
 *   `Tests\Support\ExternalSeam\ExternalSeamScanner`) が行う。ここに TARGET を持ち込むと
 *   2 目録の責務が混ざる。
 * ★**`use` import は site ではない**。alias マップの構築にのみ使い、母集団へは登録しない
 *   (PHP の `use` はクラス本体の外に書かれるため、site 扱いすると正規の import を持つ
 *    全ファイルが違反になる)。ただし「ファイルがその名前空間を知っているか」の文脈判定に
 *   使えるよう `ReferenceScanResult::$imports` として返す。
 * ★`{` の数え漏れに注意: `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES` (文字列補間) の
 *   閉じ `}` は単一文字トークンで現れるため、開き側を depth に数えないと brace が片側だけ減り
 *   以降の site が誤って FileScope 帰属になる (T126 の実測で発覚した罠)。
 */
final class PhpReferenceScanner
{
    /**
     * 正規化済みトークン列 (呼び出し引数の追加解析用に利用側へ渡す)。
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function tokens(string $phpSource): array
    {
        return PhpTokenScan::normalize($phpSource);
    }

    /**
     * 参照 site と import を列挙する。
     *
     * ★**emission 契約**: `Socialite::driver('g')` の正規化トークン列は
     *   `T_STRING(Socialite)` / `T_DOUBLE_COLON` / `T_STRING(driver)` / `(` である。
     *   receiver の `Socialite` は「直前が `::` ではない」ため **`NameReference` として emit される**。
     *   加えて `driver` が `StaticCall(receiver: 'Laravel\Socialite\Facades\Socialite')` として
     *   emit される。すなわち **1 つの静的呼び出しは NameReference と StaticCall の 2 site を生む**。
     *   利用側はどちらか一方だけを canonical にすること (両方を見ると二重検出になる)。
     *
     * ★**名前解決の限界** (現行 `ExternalClientBoundaryScanner` の挙動をそのまま保存する):
     *   `T_NAME_QUALIFIED` (`Foo\Bar` のような部分修飾名) は `ltrim($text, '\\')` するだけで、
     *   「現在の namespace への相対解決」も「先頭 segment の alias 解決」も**行わない**。
     *   したがって `use Illuminate\Support\Facades; … Facades\Http::get()` は解決できない。
     *   これは既存 gate と同じ非対称であり、抽出は**振る舞い保存**が目的なのでここを直さない。
     */
    public static function references(string $relativePath, string $phpSource): ReferenceScanResult
    {
        $tokens = self::tokens($phpSource);
        $count = count($tokens);

```

### 準拠実装として名指しする空振り検査 (tests/Architecture/FfmpegProcessLaunchInventoryTest.php の末尾)
```php
        (string) config()->integer('manual.ffmpeg_max_alloc_bytes'),
    ]);
});

test('母集団が空でない (degenerate PASS 防止)', function (): void {
    // 上の「全ファイルが経由している」検査が、Finder が 1 件も返さないことで
    // 緑になっていないことを示す。
    expect(ffmpegBinaryReferencingFiles())->not->toBe([]);
});
```

## 棚卸し (divergence-survey.md)

# 規約と既存 gate の食い違い (棚卸し)

観測点: `main` @ 1194d34 (2026-08-18 実測)。
判定に使った 5 条は `conceptual-design.md` のブロック 1 (家系の正典 v1 の (a)〜(e))。

**この棚卸しは是正を求めるものではない**。AG-154 (1) は既存分への遡及を求めていない。
ここに書くのは「規約を敷いたときに、既存のどこが規約どおりでないか」を後から探し直さずに
済むようにするためであり、是正が要るものは別 TODO として起票する。

## 母集団と数え方 — **全数性を主張しない**

母集団は下記のコマンドで列挙される**候補**に限る。ここに現れない場所
(`scripts/` 配下の走査、`tests/Feature` / `tests/Unit` に直接書かれた走査、設定ファイル中の
判定など) は見ていない。全数を主張する棚卸しが必要になったら、それ自体が
走査器索引 (aigenba 形) の導入を再検討する条件になる (概念設計スコープ外節)。

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

**扱い**: 判定の是正は本 TODO では行わない (波及が広く、規約の成文化とは別の作業量になる)。
ただし**現 docblock を放置すると、規約導入後に「規約に照らして是認済みの限界」と誤読される**。
そこで本 TODO では **docblock の文面だけ**を
「規約 (b) を満たしていない既知の穴であり、是正は別 TODO」と読める形へ直す (概念設計 施策 2)。

- **追跡先 TODO ID**: _(未採番。監督者が起票した ID をここへ追記すること。これが本 TODO の完了条件の 1 つ)_
- **是正するときの設計条件** (Codex Round 1 観点 7): 未解決を通常の完全修飾名文字列へ**混ぜない**。
  判別できる値 (専用の種別を持つ site / 専用の戻り値) か明示的な例外で表す。
  `string|null` へ潰すと PHPStan level 10 と fail-closed の意図の両方を損ねる。

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

| # | 内容 | 根拠 | 追跡先 TODO ID |
|---|---|---|---|
| 1 | `PhpReferenceScanner` の部分修飾名を落とす形へ寄せる (波及 6 gate + 2 検出器)。未解決は判別できる値か例外で表し、完全修飾名の文字列へ混ぜない | D1 | _(未採番)_ |
| 2 | 空振り検査を持たない走査 gate 12 本の分類と付与 | D2 | _(未採番)_ |

いずれも本 TODO のスコープ外である (`conceptual-design.md` スコープ外節)。
**ID 欄の記入は監督者の作業**であり、本セッションは `docs/TODO.md` を変更しない。
**2 行とも ID が埋まることが本 TODO の完了条件**である
(片方だけを条件にすると、残った側が一覧に埋もれて追跡先を失う)。
とくに 1 行目はセキュリティ不変条件に波及するため、ID 未採番のまま完了扱いにしない。

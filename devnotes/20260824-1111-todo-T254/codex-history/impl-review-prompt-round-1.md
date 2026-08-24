【アプリの使命 (North Star) — AGENTS.md より】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


【禁止事項 — AGENTS.md より】
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

【参考: AGENTS.md「静的検査 (gate) と走査器の共通規約」】
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


---

## system: あなたの役割

あなたは Laravel + Svelte アプリ aicue の**実装レビュアー**である。
本 PR は **Architecture テスト 1 本 (`tests/Architecture/BughuntNamingResidualTest.php`) だけ**を変更する。
アプリコード (`app/` `routes/` `database/` `config/` `bootstrap/` `resources/`) は 1 バイトも触っていない。

レビュー観点:
1. **設計との一致性** — 添付の詳細設計 (S1/S2/S3 と §判定の含意) どおりに実装されているか。設計から逸脱・欠落した点は無いか
2. **正確性** — 述語 `bughuntNamingViolationsIn()` の判定ロジックに論理的な穴・偽陰性 (見逃し) / 偽陽性は無いか。
   とくに「申告位置の集合と実出現位置の集合の一致」が正典 v1 の 3 方向 (申告外の出現 / 申告があるのに消えた / 二重申告) を
   本当に漏れなく落とすか。逆向き差分を持たない判断は妥当か
3. **PHPStan level 10 適合性** — 型の緩め・暗黙の nullable・array shape の破れは無いか (phpstan の解析対象は app/config/database/routes なので tests は静的解析対象外である点も踏まえて評価すること)
4. **テスト網羅性** — 負のコントロール N-4 の (a)〜(l) が「検出力の裏取り」として十分か。抜けている境界は無いか
5. **fail-closed / 空振り防止** — 走査が壊れたときに緑で通る経路が残っていないか
6. **保証範囲の記述** — docblock が保証しない範囲 (沈黙する経路) を誇張なく書いているか
7. **禁止事項違反** — テスト削除・phpstan-ignore・baseline 化などが無いか

DTO/JsonResource・DESIGN.md・Atomic Design の観点は**本 PR には該当しない** (HTTP 境界も UI も無い) ので評価不要である。

出力形式:
- ファイルごとに判定
- 指摘は [Critical] / [Warning] / [Suggestion] に分類
- 最後に**全体判定を `APPROVED` または `CHANGES_REQUESTED` の 1 語**で書くこと

---

## user: レビュー対象

### 1. 詳細設計書 (正本)

# 詳細設計: rename-residual-name-gate v1 追従 (出現特定式の申告台帳)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）


<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


### 禁止事項


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


### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- 本件は **DB を 1 度も触らない静的検査**（既存 Architecture テストと同じ作法）。Factory も新モデルも無い
- **DTO + JsonResource** パターン: 本件は HTTP 応答境界を持たないため**非該当**（テストレーンの純関数のみ）
- `declare(strict_types=1)` + 日本語コメント
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 12（フロントは変更なし）

### 本件に効く追加規約（AGENTS.md）

- **思考原則 3「後方互換の並走を残さない」**: 件数 pin の定数は**同じ変更で消す**（新旧の並走を作らない）
- **思考原則 5「テストファースト」**: 負のコントロールを先に赤くする（本書 §テストファースト手順）
- **「静的検査 (gate) と走査器の共通規約」**: (b) fail-closed / (c) 負例で裏取り / (d) 集めた結果を必ず判定に使う。
  (a)（完全修飾名での突合）と (e)（区切りトークンの完全一致）は**適用対象外**であり、その理由を docblock に書く
- **「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」**: 負例と正例 / 解決できない形を落とす分岐 /
  空振りしていないことの検査 / docblock に走査対象と保証しないものを書く

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex 概念レビュー Round 1 = APPROVED）
- 台帳: lctl feature `rename-residual-name-gate`（正典 v1 = 出現特定式許可台帳 — 空縮退可 / aicue セル `update_pending`）
- 家系の基底実装: `aigenba:tests/Architecture/BugHuntSkillNamingLedgerTest.php`（`lctl get_source` で全文実読済み）
- 縮退形（申告ゼロ）の供給元: `laravel-claude-template:tests/js/architecture/retired-script-name.test.ts`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 許可の表現を「件数 pin」から「出現特定式の申告台帳」へ差し替える（旧定数は同じ変更で撤去） | `tests/Architecture/BughuntNamingResidualTest.php` | 高 |
| S2 | 走査をパス名にも掛ける（純関数の第 1 引数を照合対象へ昇格。パス名に申告の口は作らない） | 同上 | 高 |
| S3 | 空振り防止に家系名の正の対照を足し、負のコントロール N-4 を新形へ拡張し、docblock を書き直す | 同上 | 高 |

**変更ファイルは 1 本だけである**。`app/` `routes/` `database/` `config/` `bootstrap/` `resources/` は 1 バイトも触らない。
3 施策は同一ファイルの相互依存する変更なので、**1 つのコミット単位で実装する**（施策ごとの分割コミットにしない）。

## 事前実測（設計の根拠。実装セッションは再測して一致を確認すること）

現 HEAD `b207bafa` で計測した。**再現手順とスクリプトは [`evidence/`](./evidence/) に置いてある**
（`php devnotes/20260824-1013-rename-residual-name-gate-v1/evidence/verify-predicate.php` で 51 assert が緑になる）。

| 観測 | 値 | 取り方 |
|---|---|---|
| git 追跡下ファイル数 | 9925 | `git ls-files \| wc -l` |
| 全数読み込みの総バイト数 / 所要 | 52,577,870 バイト / 0.42 秒 | 本設計の述語を素の PHP で実走（`base_path` だけ差し替え） |
| `docs/TODO-closed.md` の旧名出現 | `BughuntBillingSeeder` 2 件 / `FakeExternalsServiceProvider` 3 件 | `awk` で行ごとに数えた（33 行 / 134 行 / 221 行） |
| `docs/TODO.md` の旧名出現 | 両方 0 件 | 同上 |
| パス名に旧名を持つ追跡ファイル | 0 件 | `git ls-files \| grep -E '旧名'` |
| 本設計の申告台帳（needle 5 本）を実リポジトリへ当てた結果 | 違反 0 件 | 本設計の述語 + N-1〜N-4 相当を素の PHP で実走（全 51 assert 緑） |

## S1 許可の表現を出現特定式の申告台帳へ差し替える

### 変更箇所

- ファイル: `tests/Architecture/BughuntNamingResidualTest.php`
  - L39-70 `BUGHUNT_NAMING_KNOWN_MENTIONS`（件数 pin）→ **撤去**し `BUGHUNT_NAMING_DECLARED_OCCURRENCES` を新設
  - L108-143 `bughuntNamingViolationsIn()` の突き合わせ部を差し替え、**申告台帳を引数で受ける**
  - L223-240 `N-3` を申告台帳の構造検査（+ 台帳→実物の逆方向）へ書き換え

### 波及変更

- TypeScript 型定義: **なし**（フロント非関与）
- Inertia Props / API Resource / DTO: **なし**（HTTP 境界を持たない）
- テストファイル: **本ファイルのみ**。`BUGHUNT_NAMING_KNOWN_MENTIONS` / `bughuntNamingViolationsIn` を参照する
  ファイルはリポジトリ全体で本ファイル 1 本だけ（`grep -rln` で実測 = 0 件の外部参照）
- docs: **なし**（`docs/TODO.md` への登録は `app-todo-add` の責務。本設計は**登録行に旧名を綴らない**方針をとる。§TODO 台帳との摩擦）

### 現行コード（抜粋）

```php
const BUGHUNT_NAMING_KNOWN_MENTIONS = [
    'docs/TODO-closed.md' => [
        'BughuntBillingSeeder' => 2,
        'FakeExternalsServiceProvider' => 3,
    ],
    'docs/TODO.md' => [
        'BughuntBillingSeeder' => 0,
        'FakeExternalsServiceProvider' => 0,
    ],
];

function bughuntNamingViolationsIn(string $relative, string $content): array
{
    // …除外判定…
    $pinned = BUGHUNT_NAMING_KNOWN_MENTIONS[$relative] ?? [];

    $violations = [];
    foreach (BUGHUNT_RETIRED_NAMES as $retired => $canonical) {
        $count = substr_count($content, $retired);   // ← 数値しか見ていない
        $allowed = $pinned[$retired] ?? 0;
        if ($count === $allowed) {
            continue;
        }
        // …
    }

    return $violations;
}
```

**穴**: 述語に「出現の文脈」が存在しないため、同一ファイル内で件数を保ったまま出現箇所を
すり替える書き換えに沈黙する。`$relative` は除外判定と pin 引きにしか使われていない。

### 変更後コード

ファイル冒頭（`declare` と docblock。S3 の docblock 書き直しを含む）:

```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * 家系 (機能台帳 lctl の feature `rename-residual-name-gate` / 正典 v1
 * 「出現特定式許可台帳 — 空縮退可」) の追従。
 * 改名で退いた旧名が、リポジトリの**現役の資産**に 1 件も残っていないことを機械で見張る。
 *
 * 裁定 AG-085 は「同じ関心事に名前が 2 つある状態」を、追従判断のたびに
 * 「欠落か別名か」の実読が発生することを理由に禁じている。2026-08-10 の裁定で
 * ファイル数の統合は撤回され、残る要件はこの名前の一意性だけである。
 *
 * 判定の骨子 (正典 v1):
 * - 母集団は **git 追跡下の全ファイル**。**内容とパス名の両方**を照合する
 *   (中身を直しただけのファイルが旧名のパスで復活する経路は内容走査では塞げない)。
 * - 旧名が現れてよいのは「いつ・誰が・何をしたかの記録」だけで、
 *   **出現を 1 つずつ特定する申告** (対象ファイル / 旧名 / その出現を一意に特定する
 *   周辺文字列 / 残す理由) を台帳に並べる。**行番号は使わない** (無関係な編集で動くため)。
 *   **件数は申告の本数から導く** (件数の pin を別に持たない = 二重管理を作らない)。
 * - 突き合わせは 3 方向で落ちる — 申告外の出現がある / 申告があるのに実物から消えた
 *   (周辺文字列が 1 回に特定できない) / 申告が同じ出現を二重に指している。
 *   この 3 つが「申告数と実出現数の不一致」を含意する。
 * - **パス名に申告の口は無い** (記録としてファイル名に旧名が要る事案は無いため 0 件固定)。
 *
 * ★保証範囲を誇張しない:
 *   - 見るのは**字面**である。旧名を分割して連結する書き方・別名の定数経由・
 *     動的に組み立てた文字列には**沈黙する**。
 *   - **丸ごと除外した 2 つ (`devnotes/` 配下と本ファイル自身) の中では沈黙する**。
 *     そこに旧名を書いても本検査は検出しない。
 *   - 申告について保証するのは「周辺文字列が実物にちょうど 1 回あり、それが指す出現の集合が
 *     実出現の集合と一致する」ことまでである。**その記録が意味として妥当かは人のレビュー**が見る。
 *   - 家系名が「正しい名前であること」は検査できない。正本は機能台帳であり、
 *     本検査が固定するのは「旧名が現役の資産に残っていないこと」だけである。
 *   - git 未追跡のファイルは母集団に入らない (境界は commit / CI であり、そこでは追跡下にある)。
 *
 * ★走査器共通規約 (AGENTS.md) との関係:
 *   - (a) は対象外 — クラス名を**連続した字面**として探す走査で、名前参照の解決を行わない。
 *   - (b) fail-closed — `git ls-files` の失敗と読めないファイルは**例外**にする (空集合にしない)。
 *   - (c) 検出力は N-4 の負のコントロールが**同じ純関数**を通して裏取りする。
 *   - (d) 集めた走査結果はすべて判定に使う (数えるだけの目録を持たない)。
 *   - (e) は対象外 — 区切り文字でトークン化した完全一致にすると、実在する接尾辞つきの出現
 *     (`docs/TODO-closed.md` の `FakeExternalsServiceProviderTest`) を**見逃す**方向へ倒れる。
 *     本検査は許可語の除去や否定形の語彙判定を持たないため (e) の母集団に入らない。
 *
 * ★`Tests\Support\TrackedPhpSourceFiles` / `Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets`
 *   との関係: 3 者は同じ作法 (`git ls-files`) を使うが**母集団の定義が違う**兄弟である。
 *   前者は `.php` 全数、後者は走査根 8 本 (`docs/` と `.claude/` を見ない)、本検査は
 *   **追跡下の全ファイル**である。本 feature の主敵は規約文書・スキル・手順書に残る旧名なので
 *   `docs/` と `.claude/` を母集団から外せない。列挙を 2 本持つのではなく対象の定義が違う。
 *   関心事の境界 (撤去物の不在 = surface-removal-absence-gate / 旧名の残留 = 本検査) は
 *   機能台帳が名指しで分けている。
 *
 * ★申し送り: 裁定 AG-085 の 3 件目 (並列枠数上限の検査の名前) は feature `bughunt-runtime` の
 *   管轄で、本検査の写像には**入っていない**。将来その改名を行うときは写像へ 1 件足すこと。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */
```

定数と述語:

```php
/**
 * 退役した名前 → 家系の名前。
 *
 * 出典は機能台帳の機能 bughunt-runtime
 * (aigenba / metamovics / laravel-claude-template の実測記録)。
 *
 * @var array<string, string>
 */
const BUGHUNT_RETIRED_NAMES = [
    'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
    'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
];

/**
 * 旧名の出現を許す場所の申告台帳 (**出現を 1 つずつ特定する**)。
 *
 * `needle` = その出現を一意に特定する周辺文字列。実ファイルに**ちょうど 1 回**現れ、
 * 対象の旧名を**ちょうど 1 回**含むこと。`reason` = 残す理由 (30 文字以上)。
 * **件数は申告の本数**であり、別に pin しない。
 *
 * ★**記録を動かすときは同じ変更で申告も動かす** (意図的な摩擦)。作業台帳の行が
 *   `docs/TODO.md` から `docs/TODO-closed.md` へ移ったら、正しい直し方は
 *   「記録を書き換える」のではなく**申告を足す・移す・外す**である。
 * ★申告 0 件の登録は書かない (実物から消えた申告と区別できないため)。`docs/TODO.md` は
 *   現在旧名 0 件なので**登録そのものを持たない** — deny-by-default なので 1 つ現れれば赤になる。
 *
 * @var array<string, array<string, list<array{needle: string, reason: string}>>>
 */
const BUGHUNT_NAMING_DECLARED_OCCURRENCES = [
    'docs/TODO-closed.md' => [
        'BughuntBillingSeeder' => [
            [
                'needle' => '・BughuntBillingSeeder (有料プラン組織のみ active subscription',
                'reason' => 'T015 (bug-hunt 基盤整備) の完了行。当時作った seeder の名前は当時の事実であり、書き換えると記録が嘘になる',
            ],
            [
                'needle' => '`database/seeders/BughuntBillingSeeder` → `BughuntStripeSyncSeeder`',
                'reason' => 'T214 (家系名への改名) の完了行が持つ改名の対応表。旧名側を消すと何を何へ改名したのかが読めなくなる',
            ],
        ],
        'FakeExternalsServiceProvider' => [
            [
                'needle' => '・FakeExternalsServiceProvider (flag + 環境 allowlist',
                'reason' => 'T015 (bug-hunt 基盤整備) の完了行。当時作った provider の名前は当時の事実であり、書き換えると記録が嘘になる',
            ],
            [
                'needle' => '`FakeExternalsServiceProviderTest` は 6 test ではなく 8 test',
                'reason' => 'T119 (fake 配線の実証検査) の完了行が持つ台帳との食い違いの記録。当時のテストクラス名を指しており改名できない',
            ],
            [
                'needle' => '`app/Providers/FakeExternalsServiceProvider` → `BughuntFakesServiceProvider`',
                'reason' => 'T214 (家系名への改名) の完了行が持つ改名の対応表。旧名側を消すと何を何へ改名したのかが読めなくなる',
            ],
        ],
    ],
];

/**
 * 丸ごと走査から外す置き場所 (repo 相対の接頭辞)。
 *
 * `devnotes/` の設計・レビュー・走行記録は 190 ファイル規模で旧名を含み、出現ごとの申告が
 * 実務にならない (`ForbiddenStatementTokenInvariantTest` が devnotes を理由付きで
 * 除外するのと同じ扱い)。
 *
 * ★**保証の穴として明記する**: ここでは旧名の再流入に沈黙する。
 *
 * @var list<string>
 */
const BUGHUNT_NAMING_EXCLUDED_PREFIXES = ['devnotes/'];

/**
 * 丸ごと走査から外す唯一のファイル = 本テスト自身。
 *
 * 申告の needle と負のコントロールの入力として旧名を持つため、自分を走査すると必ず自分で赤くなる。
 * **保証の穴として明記する**: 本ファイルの中に旧名を書いても本検査は沈黙する。
 * 骨抜きにならないことは (1) 申告が実出現と一致すること (N-3) と
 * (2) 家系名を実際に見つける正の対照 (N-2) の 2 つで担保する。
 */
const BUGHUNT_NAMING_SELF_PATH = 'tests/Architecture/BughuntNamingResidualTest.php';

/**
 * 置き換え先 (家系名) の番兵。家系名 => その名前が実在するファイル。
 *
 * 正典の要求「置き換え先が実在しかつ git 追跡下にあること」を満たす
 * (未追跡だと母集団に入らず走査が空振りする)。N-2 は**同じ読み取り機構**で
 * この名前を実際に見つけることまで確かめる (正の対照)。
 *
 * @var array<string, string>
 */
const BUGHUNT_NAMING_CANONICAL_SENTINELS = [
    'BughuntStripeSyncSeeder' => 'database/seeders/BughuntStripeSyncSeeder.php',
    'BughuntFakesServiceProvider' => 'app/Providers/BughuntFakesServiceProvider.php',
];

/**
 * 走査の母集団が空振りでないことを確かめる参照側の代表パス。
 *
 * @var list<string>
 */
const BUGHUNT_NAMING_SENTINEL_PATHS = [
    'bootstrap/providers.php',
    'scripts/bug-hunt-shard.sh',
];

/** 母集団の下限 (これを下回ったら列挙そのものを疑う) */
const BUGHUNT_NAMING_MINIMUM_TRACKED_FILES = 500;

/** 申告の理由に求める最小文字数 (本リポジトリの目録の作法に合わせる)。 */
const BUGHUNT_NAMING_MINIMUM_REASON_LENGTH = 30;

/**
 * `$haystack` 内の `$needle` の出現位置 (バイト位置、昇順)。
 *
 * ★重なり合う出現も**別の出現として数える** (見逃さない側へ倒す)。
 * ★空文字は出現位置を持たないので例外にする (申告の書き方の誤り)。
 *
 * @return list<int>
 */
function bughuntNamingOffsetsOf(string $haystack, string $needle): array
{
    if ($needle === '') {
        throw new RuntimeException('空文字は出現位置を持たない (旧名の残留検査の申告の書き方の誤り)');
    }

    $offsets = [];
    $from = 0;

    while (($at = strpos($haystack, $needle, $from)) !== false) {
        $offsets[] = $at;
        $from = $at + 1;
    }

    return $offsets;
}

/**
 * 1 ファイル分の違反 (純関数 = 負のコントロールが**同じ述語**を通せる)。
 *
 * 申告台帳は**引数で受ける** — 負のコントロールが実ファイルの内容に依存しないため。
 *
 * @param  array<string, array<string, list<array{needle: string, reason: string}>>>  $declarations
 * @return list<string>
 */
function bughuntNamingViolationsIn(string $relative, string $content, array $declarations): array
{
    if ($relative === BUGHUNT_NAMING_SELF_PATH) {
        return [];
    }

    foreach (BUGHUNT_NAMING_EXCLUDED_PREFIXES as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return [];
        }
    }

    $violations = [];

    foreach (BUGHUNT_RETIRED_NAMES as $retired => $canonical) {
        // (1) パス名の照合 — 申告の口は無い (0 件固定)。
        if (str_contains($relative, $retired)) {
            $violations[] = sprintf(
                'パス名に旧名が復活している: %s (旧名 %s / 家系名 %s) — パスごと家系名へ改名すること'
                .' (パス名には申告の口が無い)',
                $relative,
                $retired,
                $canonical
            );
        }

        // (2) 内容の照合 — 実出現の位置集合と、申告が指す位置集合を突き合わせる。
        $actual = bughuntNamingOffsetsOf($content, $retired);
        $declared = [];

        foreach ($declarations[$relative][$retired] ?? [] as $entry) {
            $inner = bughuntNamingOffsetsOf($entry['needle'], $retired);

            if (count($inner) !== 1) {
                $violations[] = sprintf(
                    '申告の周辺文字列が旧名をちょうど 1 回含まない: %s / 旧名 %s / 周辺文字列 "%s" (含む回数 %d)'
                    .' / 理由: %s — 出現を 1 つだけ指す文字列に書き直すこと',
                    $relative,
                    $retired,
                    $entry['needle'],
                    count($inner),
                    $entry['reason']
                );

                continue;
            }

            $hits = bughuntNamingOffsetsOf($content, $entry['needle']);

            if (count($hits) !== 1) {
                $violations[] = sprintf(
                    '申告が出現を特定できない: %s / 旧名 %s / 周辺文字列 "%s" が %d 回 (ちょうど 1 回であること)'
                    .' / 理由: %s — 記録を書き換えるのではなく、申告を足す・移す・外すこと',
                    $relative,
                    $retired,
                    $entry['needle'],
                    count($hits),
                    $entry['reason']
                );

                continue;
            }

            $declared[] = $hits[0] + $inner[0];
        }

        sort($declared);

        // 有効な申告位置は構築上必ず実出現位置に含まれる (周辺文字列は本文にちょうど 1 回・
        // 旧名をちょうど 1 回) ため、逆向きの差分は常に空になる。だから片方向だけを見る。
        $undeclared = array_values(array_diff($actual, $declared));

        if ($undeclared !== []) {
            $violations[] = sprintf(
                '申告外の出現がある: %s / 旧名 %s (家系名 %s) / 実出現 %d 件・申告 %d 件'
                .' / 未申告の位置 %s — 改名の取りこぼしなら家系名へ直すこと。記録として残すなら、'
                .'記録を書き換えるのではなく、申告を足す・移す・外すこと',
                $relative,
                $retired,
                $canonical,
                count($actual),
                count($declared),
                implode(', ', array_map('strval', $undeclared))
            );
        }

        if (count($declared) !== count(array_unique($declared))) {
            $violations[] = sprintf(
                '申告が同じ出現を二重に指している: %s / 旧名 %s / 実出現 %d 件・申告 %d 件'
                .' — 記録を書き換えるのではなく、申告を足す・移す・外すこと',
                $relative,
                $retired,
                count($actual),
                count($declared)
            );
        }
    }

    return $violations;
}

/**
 * git 追跡下の全ファイル (repo 相対パス、昇順)。
 *
 * ★対象は拡張子を問わない (シェル / 文書 / 環境ひな型も見る)。
 * ★git が使えない環境では**空を返さず例外**にする (fail-open の防止)。
 *
 * @return list<string>
 */
function bughuntNamingTrackedFiles(): array
{
    $process = new Process(['git', 'ls-files', '-z'], base_path());
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(
            'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
            .$process->getErrorOutput()
        );
    }

    $files = [];
    foreach (explode("\0", $process->getOutput()) as $relative) {
        if ($relative === '') {
            continue;
        }

        $files[] = $relative;
    }

    sort($files);

    return $files;
}

/**
 * 追跡下ファイルの中身を読む (読み取り失敗を空文字で握り潰さない)。
 *
 * 走査結果が空であることを「違反なし」と解釈する gate なので、読めなかったファイルは
 * 必ず名指しで落とす。
 */
function bughuntNamingSourceOf(string $relative): string
{
    $absolute = base_path($relative);
    $content = @file_get_contents($absolute);

    if (! is_string($content)) {
        throw new RuntimeException("追跡下ファイルを読み取れない (旧名の残留検査の走査対象): {$relative}");
    }

    return $content;
}
```

### 判定の含意（正典の 3 方向が漏れないことの根拠）

出現位置の集合一致で 3 方向すべてが落ちる。証明は 3 段で足りる。

1. **有効な申告は必ず実出現位置を指す**。`needle` は旧名をちょうど 1 回含み、本文にちょうど 1 回
   現れるので、`needle` の位置 + `needle` 内の旧名の位置は**実出現位置そのもの**である。
   したがって `$declared ⊆ $actual` が常に成り立つ（この 2 条件を満たさない申告は
   その手前の分岐で必ず違反になる）。
2. よって **`$actual − $declared` が空**なら「申告外の出現」は無い（方向 1）。
   `$declared` に**重複が無い**なら「同じ出現への二重申告」も無い（方向 3）。
   `needle` が 0 回なら「申告があるのに実物から消えた」で落ちる（方向 2）。
3. `$declared − $actual` の逆向き差分は**持つ必要が無い**（1 より常に空）。持たない判断を明記する
   （集めた結果を判定に使わない形を作らない = 走査器共通規約 (d)）。
4. **件数の不一致は上の 3 つが含意する**。件数だけを比べる形では
   「2 つの申告が同じ出現を指し、別の 1 件が未申告」という入力が緑で通る
   （実測: 申告 2 件 = 実出現 2 件）。この入力を N-4 (l) として固定する。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`list<string>` / `list<int>` / `array<string, string>`）
- [x] null 安全: `strpos()` の `false` は `!== false` で分岐、`file_get_contents()` の `false` は例外へ
- [x] 申告台帳の array shape を PHPDoc で宣言（`array<string, array<string, list<array{needle: string, reason: string}>>>`）
      = 台帳形式の崩れを静的に落とす（Codex 概念レビューの Suggestion 3）
- [x] DTO を返す必要なし（テストレーンの純関数。配列返却が適切）
- [x] Generics: `array_diff` / `array_values` / `array_unique` の結果は `list<int>` へ正規化してから使う
- [x] `@phpstan-ignore` / baseline は使わない（禁止事項 2）

### テスト計画

- [x] バグ修正ではないが、**先に赤くする**手順を §テストファースト手順 に具体化した
- [x] 既存テスト `tests/Architecture/BughuntNamingResidualTest.php` の N-1 / N-3 / N-4 を更新（削除はしない）
- [x] 新規: N-3 に「台帳 → 実物」の逆方向（申告先が追跡下にある / needle が実物にちょうど 1 回 /
      理由 30 文字以上 / 申告本数 = 実出現数）
- [x] 個別の `DatabaseTransactions` は使っていない（DB 非使用）

### リスク

- **needle が将来の文書編集で 2 回以上になると赤くなる**（正しい記録なのに赤）。これは fail-closed として
  意図どおりだが、保守者が「件数だけ直す」誤った直し方に流れやすい。→ 失敗メッセージに
  「対象ファイル / 旧名 / 周辺文字列 / 実検出回数 / 理由」と復旧手順
  「**記録を書き換えるのではなく、申告を足す・移す・外す**」を必ず含める（N-4 (b) で文言を実測する）。
- **申告の意味的な妥当性は機械では見ない**（needle が本当に「記録」を指しているかは人のレビュー）。docblock に明記する。
- 件数 pin を消すことで「合計だけを見ると内訳のすり替えが通る」という旧 docblock の警告は**不要になる**
  （出現位置の集合一致がそれを含意する）。旧文は残さず書き直す（2 か所に書くと食い違う）。

## S2 走査をパス名にも掛ける

### 変更箇所

- 同ファイル `bughuntNamingViolationsIn()` の先頭に**パス名の照合**を追加（上掲コードの `(1) パス名の照合`）

### 波及変更

- なし（純関数の内部追加。呼び出し側の署名は S1 で 3 引数化するのみ）

### 設計判断

- **パス名に申告の口は作らない**。記録としてファイル名に旧名が要る事案は存在しないため 0 件固定にする。
  正当な事情が将来出たら「除外を足す」のではなく**設計として作り直す**
  （縮退形の供給元 `laravel-claude-template` の docblock が採る姿勢に倣う）。
- 除外（`devnotes/` 接頭辞と自ファイル）は**パス名照合にも先に効く**。したがって
  `devnotes/…/BughuntBillingSeeder-memo.md` のようなパスには沈黙する（保証の穴として docblock に明記）。
- 現 HEAD の該当は 0 件なので、この施策は**今日の緑を変えない**。守るのは「退化に沈黙しない」ことである。

### リスク

- パス名照合を足しても検出できないのは、**旧名を分割して連結したディレクトリ名**（`Bughunt` + `BillingSeeder`）である。
  字面走査の限界であり docblock に明記する。

## S3 空振り防止の正の対照・負のコントロール拡張・docblock 書き直し

### 変更箇所

- `BUGHUNT_NAMING_SENTINEL_PATHS`（4 本）を **参照側 2 本**（`bootstrap/providers.php` /
  `scripts/bug-hunt-shard.sh`）と **`BUGHUNT_NAMING_CANONICAL_SENTINELS`（家系名 => 実在パスの 2 組）** に分ける
  = 番兵と写像の値が 1:1 であることを N-3 が機械で固定できるようになる（重複記述をやめる）
- `N-2` に **正の対照**（家系名を同じ読み取り機構で内容とパス名の両方から実際に見つける）を追加
- `N-4` を新形へ拡張（(a)〜(k) の 11 ケース）
- ファイル冒頭の docblock を全面的に書き直す

### 変更後コード（テスト本体）

```php
test('N-1 追跡下の内容とパス名に旧名の残留が無く、記録は申告と厳密に一致する', function (): void {
    $violations = [];

    foreach (bughuntNamingTrackedFiles() as $relative) {
        $found = bughuntNamingViolationsIn(
            $relative,
            bughuntNamingSourceOf($relative),
            BUGHUNT_NAMING_DECLARED_OCCURRENCES
        );

        foreach ($found as $violation) {
            $violations[] = $violation;
        }
    }

    expect($violations)->toBe([]);
});

test('N-2 fail-closed: 走査が空振りしていない (母集団の下限・番兵・家系名の正の対照)', function (): void {
    $files = bughuntNamingTrackedFiles();

    expect(count($files))->toBeGreaterThanOrEqual(
        BUGHUNT_NAMING_MINIMUM_TRACKED_FILES,
        '追跡下ファイルの列挙が少なすぎます (git ls-files が期待どおり動いていない可能性)',
    );

    foreach (BUGHUNT_NAMING_SENTINEL_PATHS as $sentinel) {
        expect($files)->toContain($sentinel);
    }

    // 正の対照: 「旧名 0 件」が走査の故障による偽の緑でないことを、在るはずの家系名で確かめる。
    // 置き換え先が実在しかつ git 追跡下にあること (正典の要求) もここで満たす。
    foreach (BUGHUNT_NAMING_CANONICAL_SENTINELS as $canonical => $path) {
        expect($files)->toContain($path);

        expect(bughuntNamingOffsetsOf(bughuntNamingSourceOf($path), $canonical))->not->toBe(
            [],
            "家系名 {$canonical} が {$path} の内容で見つからない — 走査条件の陳腐化を疑う",
        );
        expect(str_contains($path, $canonical))->toBeTrue(
            "家系名 {$canonical} がパス名 {$path} で見つからない — 番兵の陳腐化を疑う",
        );
    }
});

test('N-3 申告台帳と除外の構造が意図どおり (台帳から実物への逆方向も見る)', function (): void {
    // 丸ごと除外の定義は 2 つちょうど (接頭辞 devnotes/ が 1 件 + 本テスト自身が 1 件)。
    expect(BUGHUNT_NAMING_EXCLUDED_PREFIXES)->toBe(['devnotes/'])
        ->and(BUGHUNT_NAMING_SELF_PATH)->toBe('tests/Architecture/BughuntNamingResidualTest.php');

    // 退役した名前は 2 つで、家系名と 1:1 に対応する。
    expect(BUGHUNT_RETIRED_NAMES)->toBe([
        'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
        'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
    ]);

    // 置き換え先には 1 つずつ番兵がある (写像の値と番兵のキーが完全一致)。
    expect(array_keys(BUGHUNT_NAMING_CANONICAL_SENTINELS))->toBe(array_values(BUGHUNT_RETIRED_NAMES));

    // 申告台帳のキーは記録 1 冊ちょうど (docs/TODO.md は旧名 0 件なので登録を持たない)。
    expect(array_keys(BUGHUNT_NAMING_DECLARED_OCCURRENCES))->toBe(['docs/TODO-closed.md']);

    $files = bughuntNamingTrackedFiles();

    foreach (BUGHUNT_NAMING_DECLARED_OCCURRENCES as $path => $perRetiredName) {
        // ★`toContain` は可変長の needle を取るので**メッセージを渡さない** (第 2 引数は
        //   もう 1 つの needle として解釈される)。理由文を添える判定は真偽値へ落として書く。
        expect(in_array($path, $files, true))->toBeTrue(
            "申告した記録が追跡下に無い: {$path} — ファイルごと消えたなら申告も外すこと",
        );

        $content = bughuntNamingSourceOf($path);

        expect($perRetiredName)->not->toBe([], "旧名の項目を 1 つも持たない登録: {$path} — 行ごと外すこと");

        foreach ($perRetiredName as $retired => $entries) {
            expect(BUGHUNT_RETIRED_NAMES)->toHaveKey($retired);
            expect($entries)->not->toBe([], "申告 0 件の登録は意味を持たない: {$path} / {$retired} — 行ごと外すこと");

            foreach ($entries as $entry) {
                expect(mb_strlen($entry['reason']))->toBeGreaterThanOrEqual(
                    BUGHUNT_NAMING_MINIMUM_REASON_LENGTH,
                    "申告の理由が短すぎる: {$path} / {$retired}",
                );
                expect(bughuntNamingOffsetsOf($entry['needle'], $retired))->toHaveCount(
                    1,
                    "申告の周辺文字列が旧名をちょうど 1 回含まない: {$path} / {$retired} — {$entry['needle']}",
                );
                expect(bughuntNamingOffsetsOf($content, $entry['needle']))->toHaveCount(
                    1,
                    "申告の周辺文字列が実物にちょうど 1 回現れない: {$path} / {$retired} — {$entry['needle']}",
                );
            }

            // 件数は申告の本数から導く (別に pin を持たない)。
            expect(count($entries))->toBe(
                count(bughuntNamingOffsetsOf($content, $retired)),
                "申告の本数が実出現数と合わない: {$path} / {$retired}",
            );
        }
    }
});

test('N-4 負のコントロール: 同じ述語が検出する / しないの境界', function (): void {
    $retired = array_keys(BUGHUNT_RETIRED_NAMES);
    $canonical = array_values(BUGHUNT_RETIRED_NAMES);
    $seeder = $retired[0];
    $provider = $retired[1];

    // 合成の申告台帳と合成の本文 (実ファイルの内容に依存させない)。
    $reason = '負のコントロール用の合成理由 (30 文字以上であることを N-3 と同じ規則で満たす)';
    $ledger = [
        'docs/record.md' => [
            $seeder => [
                ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
            ],
        ],
    ];
    $body = "行 1: T001 で {$seeder} を作った\n行 2: ふつうの文\n";

    // (a) 申告どおりなら緑
    expect(bughuntNamingViolationsIn('docs/record.md', $body, $ledger))->toBe([]);

    // (b) ★v1 の主眼: 件数は同じだが出現箇所をすり替えた入力は赤になる
    //     (申告の周辺文字列が消え、別の位置に未申告の出現が生まれる = 2 件)
    $swapped = "行 1: ふつうの文\n行 2: T002 で {$seeder} を消した\n";
    $swappedViolations = bughuntNamingViolationsIn('docs/record.md', $swapped, $ledger);
    expect($swappedViolations)->toHaveCount(2);
    expect(implode("\n", $swappedViolations))->toContain('申告を足す・移す・外す');

    // (c) 申告外の出現が増えたら赤
    expect(bughuntNamingViolationsIn('docs/record.md', $body."後から {$seeder}\n", $ledger))->toHaveCount(1);

    // (d) 申告があるのに実物から消えたら赤
    expect(bughuntNamingViolationsIn('docs/record.md', "行 1: ふつうの文\n", $ledger))->toHaveCount(1);

    // (e) 申告の無いファイルの内容に旧名があれば赤 (deny-by-default)
    expect(bughuntNamingViolationsIn('app/Foo.php', "class Foo extends {$seeder} {}", $ledger))->toHaveCount(1);

    // (f) ★パス名に旧名を持つファイルは、内容が空でも赤
    expect(bughuntNamingViolationsIn("app/Providers/{$provider}.php", '', $ledger))->toHaveCount(1);

    // (g) 置き換え先 (家系名) は内容もパス名も誤検出しない
    expect(bughuntNamingViolationsIn("database/seeders/{$canonical[0]}.php", "class {$canonical[0]} {}", $ledger))->toBe([]);
    expect(bughuntNamingViolationsIn("app/Providers/{$canonical[1]}.php", "class {$canonical[1]} {}", $ledger))->toBe([]);

    // (h) 丸ごと除外した 2 つは沈黙する (保証の穴の実測)
    expect(bughuntNamingViolationsIn('devnotes/x/y.md', "{$seeder} {$provider}", $ledger))->toBe([]);
    expect(bughuntNamingViolationsIn(BUGHUNT_NAMING_SELF_PATH, "{$seeder} {$provider}", $ledger))->toBe([]);

    // (i) 周辺文字列が 2 回現れる (出現を特定できない) 場合も赤
    $twice = "行 1: T001 で {$seeder} を作った\n行 2: T001 で {$seeder} を作った\n";
    expect(bughuntNamingViolationsIn('docs/record.md', $twice, $ledger))->toHaveCount(2);

    // (j) 同じ出現を二重に申告したら赤
    $duplicated = [
        'docs/record.md' => [
            $seeder => [
                ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
                ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
            ],
        ],
    ];
    $duplicateViolations = bughuntNamingViolationsIn('docs/record.md', $body, $duplicated);
    expect($duplicateViolations)->toHaveCount(1);
    expect(implode("\n", $duplicateViolations))->toContain('二重に指している');

    // (k) 周辺文字列が旧名を 2 回含む (出現を 1 つに絞れていない) 申告は赤
    $ambiguous = [
        'docs/record.md' => [
            $seeder => [
                ['needle' => "T001 で {$seeder} と {$seeder}", 'reason' => $reason],
            ],
        ],
    ];
    $ambiguousViolations = bughuntNamingViolationsIn('docs/record.md', "T001 で {$seeder} と {$seeder}\n", $ambiguous);
    // 2 件は別の情報である — (1) 申告そのものが不正 / (2) その申告を採用できなかった結果として
    // 実出現が未申告になる。両方出す**診断方針を契約として固定する**。将来「原因の申告エラーが
    // あれば派生を抑制する」方針へ変えるなら、それは診断方針の変更なので期待件数も同じ変更で直す。
    expect($ambiguousViolations)->toHaveCount(2);
    expect(implode("\n", $ambiguousViolations))->toContain('ちょうど 1 回含まない');

    // (l) ★件数は一致するが 2 つの申告が同じ出現を指し、別の 1 件が未申告になる入力。
    //     件数の比較だけなら緑になるため、**出現位置の集合一致でなければ捕まらない**。
    //     この 1 ケースが「位置集合まで強める価値」の実測である。
    $twoOccurrences = "行 1: T001 で {$seeder} を作った\n行 2: T002 で {$seeder} を消した\n";
    $sameSpotTwice = [
        'docs/record.md' => [
            $seeder => [
                ['needle' => "T001 で {$seeder}", 'reason' => $reason],
                ['needle' => "で {$seeder} を作った", 'reason' => $reason],
            ],
        ],
    ];
    // 申告 2 件・実出現 2 件 = 件数は一致する (前提の確認)。
    expect(count($sameSpotTwice['docs/record.md'][$seeder]))
        ->toBe(count(bughuntNamingOffsetsOf($twoOccurrences, $seeder)));

    $sameSpotViolations = bughuntNamingViolationsIn('docs/record.md', $twoOccurrences, $sameSpotTwice);
    expect($sameSpotViolations)->toHaveCount(2);
    expect(implode("\n", $sameSpotViolations))->toContain('申告外の出現がある');
    expect(implode("\n", $sameSpotViolations))->toContain('二重に指している');
});

test('N-5 旧名のクラスは存在せず、家系名のクラスが存在する', function (): void {
    expect(class_exists('Database\Seeders\BughuntBillingSeeder'))->toBeFalse()
        ->and(class_exists('App\Providers\FakeExternalsServiceProvider'))->toBeFalse()
        ->and(class_exists('Database\Seeders\BughuntStripeSyncSeeder'))->toBeTrue()
        ->and(class_exists('App\Providers\BughuntFakesServiceProvider'))->toBeTrue();
});
```

### テスト計画

- [x] N-2: 母集団の下限（500）/ 参照側番兵 2 本 / 家系名番兵 2 組の実在・追跡下・**内容とパス名での発見**
- [x] N-4: (a) 申告どおり緑 / (b) **件数同じで出現すり替え → 赤 2 件 + 復旧手順の文言** /
      (c) 申告外の出現 → 赤 / (d) 申告があるのに消えた → 赤 / (e) 申告の無いファイル → 赤 /
      (f) **パス名に旧名 → 赤** / (g) 家系名は内容もパス名も誤検出しない / (h) 除外 2 つは沈黙 /
      (i) 周辺文字列が 2 回 → 赤 / (j) 二重申告 → 赤 / (k) 周辺文字列が旧名を 2 回含む → 赤 /
      (l) **件数は一致するが 2 申告が同じ出現を指し別の 1 件が未申告 → 赤 2 件**
- [x] **(l) が「出現位置の集合一致まで強める価値」の実測である**。件数の比較だけなら (l) は緑になる
      (申告 2 件 = 実出現 2 件)。基底実装 (aigenba) の「件数一致 + needle 一意」でも
      同じ入力は通り得るため、位置集合が押さえる分岐はこの 1 ケースで固定する
- [x] 違反リストの中身を見る判定は `implode("\n", …)` に対して `toContain()` する
      (`$violations[0]` の添字参照は空リスト時の診断が読めず、PHPStan の存在しない添字の指摘も呼ぶ)。
      併せて件数も `toHaveCount()` で固定し、余分な違反が増えた退化も検出する
- [x] N-5 は現状維持（正典が任意強化として認めている。既存テストの削除にあたるため消さない）

### 実装上の落とし穴（実測で確認済み）

- **Pest の `toContain()` は可変長 needle を取る**。第 2 引数にメッセージを渡すと
  「もう 1 つの needle」として解釈され、検査が意図せず厳しく（かつ意味不明に）なる。
  理由文を添えたい判定は `expect(in_array(...))->toBeTrue('…')` の形で書く。
- **`toHaveKey()` の第 2 引数は「期待する値」**である（メッセージではない）。メッセージを渡さない。
- 出現位置は `strpos()` の反復で取る。`substr_count()` は**重なり合う出現を数えない**ため、
  位置集合を作る側では使わない（見逃さない側へ倒す）。

### リスク

- 全ファイル読み込み（52MB / 0.42 秒）は現行と同じ性質のコスト。`--parallel` の 1 プロセスに閉じる。
- 家系名の番兵パスが改名されたら N-2 が赤くなる（意図どおり。番兵を直す）。

## テストファースト手順（先に赤くする順序）

AGENTS.md「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」の 1 に従い、
**既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する**。

1. **N-4 を新形で書き、述語はまず「内容の申告突き合わせのみ」（パス名照合なし）で実装する**
   → `(f) パス名に旧名 → 赤` が**緑になってしまう**ことを実測（= 現行の穴 2 の再現）。
   その後 `(1) パス名の照合` を足して緑にする。
2. **突き合わせを一時的に「申告の本数と実出現数の比較だけ」に落として実行する**
   → このとき**緑になってしまうのは `(b)` と `(l)` の 2 ケースだけ**である（= 現行の穴 1 の再現）。
   その後、出現位置の集合一致を入れて赤にする。
   - `(b)` すり替え: 申告 1 件・実出現 1 件で件数が一致するため、件数比較では緑
   - `(l)` 同一位置の二重申告: 申告 2 件・実出現 2 件で件数が一致するため、件数比較では緑。
     **基底実装 (aigenba) の「件数一致 + needle 一意」でも同じ入力は通り得る**ので、
     位置集合まで強める価値をここで固定する
   - `(j)` 二重申告 (実出現 1 件・申告 2 件) と `(k)` 周辺文字列が旧名を 2 回含む
     (実出現 2 件・申告 1 件) は**件数比較でも赤になる**。したがってこの 2 つは
     「位置集合の必要性」の証明ではなく、**申告の入力契約**（周辺文字列は実物にちょうど 1 回・
     旧名をちょうど 1 回含む）の負例として位置づける。手順 2 の記録に混ぜない。
3. **N-3 の各条件を 1 つずつ壊して赤を確認する** — 申告の理由を 29 文字にする / needle から旧名を除く /
   needle を 2 回現れる文字列にする / 申告を 1 件消す（本数 ≠ 実出現数）/
   申告台帳のキーへ存在しないパスを足す。確認後すべて戻す。
4. **N-2 の正の対照を壊して赤を確認する** — 家系名番兵のパスを存在しないものに差し替える /
   家系名を実在しない名前にする。確認後戻す。
5. **N-1 を実走して緑を確認する**（実測済みの期待値: 9925 ファイル / 違反 0 / 約 0.4 秒）。
6. 5 本すべて緑にしたうえで、`composer test` の全数・`composer phpstan`・`vendor/bin/pint --test` を通す。

> **テストファーストの「赤」の性質について**: 本件は旧名が現役コードに 1 件も無い状態からの改修なので、
> T214 のような「改名前ツリーへ述語を当てて 88 箇所の違反を出す」形の赤は**再現できない**。
> 代わりに上の 1・2 が「**閉じたい穴が実際に開いていること**」の実測にあたる。この 2 つの赤を
> 記録に残すこと（実装セッションの devnotes に貼る）。

## migration / 後方互換の扱い

- **DB migration は無い**（スキーマに触れない）。
- **後方互換の並走を残さない**（思考原則 3）: `BUGHUNT_NAMING_KNOWN_MENTIONS` は同じ変更で**削除**する。
  「件数 pin と申告台帳の両方を見る」段階的移行は作らない（2 つの許可機構が並ぶと、
  どちらで許されているのか読めなくなる）。
- 旧 `bughuntNamingViolationsIn(string, string)` の 2 引数版も残さない（署名を 3 引数へ変える）。
  外部参照は 0 件（実測）なので互換のための wrapper は不要。
- **運用手順の変更**: 記録ファイル（`docs/TODO*.md`）を動かすときの直し方が
  「pin の数値を直す」→「申告を足す・移す・外す」に変わる。これは docblock と失敗メッセージに書く。

## TODO 台帳との摩擦（triage の衝突 1 の決着）

- 本作業の **TODO 登録行・クローズ行には旧名を綴らない**（「bug-hunt 配線の旧名 2 件」と数で指す）。
  綴りは devnotes 側（本設計書）に置く。したがって
  **`docs/TODO.md` / `docs/TODO-closed.md` の申告を今回動かす必要は無い**。
- 摩擦の機構そのものは**残す**。旧名を綴った瞬間に「申告外の出現」で赤になるので、
  そのときは申告を足す（クローズで行が移ったら申告を移す）。この直し方を docblock に明記する。
- `docs/TODO.md` の「0 件」登録は**外す**。v1 では申告 0 件の登録が「実物から消えた申告」と
  区別できず無意味になるため。外しても deny-by-default なので保護は落ちない。

## 走査基盤の共有・非共有（triage の衝突 3 の決着）

**共有しない**（本テスト内に閉じる）。裁定の根拠は 3 つで、docblock にも要約を書く。

1. **母集団の定義が違う**。`tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets` は走査根 8 本
   （`.github` `app` `bootstrap` `config` `lang` `resources` `routes` `scripts`）に絞り、
   `docs/` `.claude/` `tests/` を見ない。本 feature の主敵は**規約文書・スキル・手順書の中の旧名**なので、
   `docs/` と `.claude/` を母集団から外せない。根を広げるとあちらの不変条件
   （`database/migrations` を含めない等）を壊す。
2. **関心事の境界が台帳で明示的に分けられている**（「撤去物の不在」対「旧名の残留」）。aigenba 側でも
   `RemovedSurfaceLedger::excluded()` が同じ線引きを名指しで宣言している。基盤共有は境界をぼかす。
3. **`docs/template-divergence.md` D40 の説明を壊さない**。D40 は「撤去物の不在 gate の共通基盤」として
   対象パスを列挙しており、本 gate を足すと登録の説明が実態と食い違う。
- そのうえで **`git ls-files` を使う列挙が 3 本目**になることは docblock に明記し、
  `TrackedPhpSourceFiles`（`.php` 全数）/ `RemovedSurfaceScanTargets`（根 8 本）/ 本検査（追跡下全数）が
  **同じ作法・違う母集団定義の兄弟**であって「同じ列挙を 2 本持つ」ものではないと書く
  （`RemovedSurfaceScanTargets` が `TrackedPhpSourceFiles` に対して行っているのと同じ書き方）。

## 乖離台帳 (docs/template-divergence.md) の登録要否

**登録は不要**。判定の根拠:

- `docs/template-fingerprints.json` の `entries`（281 キー）に
  `tests/Architecture/BughuntNamingResidualTest.php` は**無い**（実測）。
  テンプレートと共有するファイルではないので、共有ファイル変更時の登録義務は発生しない。
- `tests/Support/TemplateDivergence/adoption-debt.tsv` にも無い（実測 = 採用時債務ではない）。
- したがって `LedgerPins::DIVERGENCE_ENTRY_COUNT`（46）/ `FINGERPRINT_POPULATION_COUNT`（281）/
  `ADOPTION_DEBT_COUNT`（148）は**いずれも動かさない**。
- 「テンプレートに無い領域への上積み」ではあるが、この gate 自体は T214 で既に上積み済みで
  登録を持たない（既存の判断）。本件は**同じ上積みの内部形式の改善**であり、
  テンプレートの機構からの逸脱を新たに増やさない。
- **再判定の条件**: テンプレートが同型の gate（追跡下全数を内容とパス名で走査する旧名残留 gate）を
  配ってきたとき。そのときは D40 と同じ形（上積みを撤去して正典実装へ揃え直すか、登録を書く）で再判定する。

## 検証コマンド（全 green でコミット）

```bash
vendor/bin/pest tests/Architecture/BughuntNamingResidualTest.php   # まず本 gate 単独
composer test          # 全数 (グローバルロックで待つのは正常。kill しない)
composer phpstan       # level 10
vendor/bin/pint --test
pnpm lint && pnpm typecheck && pnpm test && pnpm build
pnpm typecheck:packages && pnpm build:packages && pnpm test:packages
```

フロントは 1 バイトも変えないので JS レーンは無関係だが、AGENTS.md の検証コマンド規約に従い全数流す。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 変更は `tests/Architecture/BughuntNamingResidualTest.php` 1 ファイルに閉じ、外部参照が 0 件（実測）。アプリコード・DB・フロントに触れないため他施策と並行して進められる |
| 競合リスク | ほぼ無い。唯一の接点は `docs/TODO.md` / `docs/TODO-closed.md` に旧名を綴る別作業（その場合は申告を足す作業が同じ変更に入る）。T249（起動 probe の共通 runner 一元化）とは対象ファイルが交わらない |

## 詳細レビュー Round 2 の反映 (2026-08-24, gpt-5.6-sol, APPROVED)

Round 1 の [Warning] 3 件を解消し、Round 2 で全施策 APPROVE / 全体 APPROVED を得た。
Round 2 の Suggestion 2 件も取り込み済みである。

- **逆向き差分を持たない理由をコードに 1 行残す**: `array_diff($actual, $declared)` の直前に
  「有効な申告位置は構築上必ず実出現位置に含まれるため逆向きの差分は常に空」というコメントを置く。
  長い証明は本書 §判定の含意 が正本で、docblock へは重複させない。
- **N-4 (k) の期待件数 2 を診断方針の契約として明記する**: 2 件は
  (1) 申告そのものが不正 / (2) その申告を採用できなかった結果としての未申告出現 の別情報である。
  将来「原因の申告エラーがあれば派生を抑制する」方針へ変えるなら**診断方針の変更**であり、
  期待件数も同じ変更で直す — この一言をテスト側のコメントに残す。
- レビューで支持された判断 (パス名 0 件固定 / 走査基盤を共有しない / `TrackedPhpSourceFiles` の
  単一出典規約と非抵触 / 位置集合が過剰設計ではないこと) は変更しない。

### 2. 実装差分 (git diff HEAD -- tests/)

```diff
diff --git a/tests/Architecture/BughuntNamingResidualTest.php b/tests/Architecture/BughuntNamingResidualTest.php
index f10c6973..0ae69f97 100644
--- a/tests/Architecture/BughuntNamingResidualTest.php
+++ b/tests/Architecture/BughuntNamingResidualTest.php
@@ -5,20 +5,56 @@
 use Symfony\Component\Process\Process;
 
 /*
- * 家系 (機能台帳 lctl の機能 bughunt-runtime) で 1 つに決まっている名前が、
- * 旧名へ戻らないことの固定。
+ * 家系 (機能台帳 lctl の feature `rename-residual-name-gate` / 正典 v1
+ * 「出現特定式許可台帳 — 空縮退可」) の追従。
+ * 改名で退いた旧名が、リポジトリの**現役の資産**に 1 件も残っていないことを機械で見張る。
  *
  * 裁定 AG-085 は「同じ関心事に名前が 2 つある状態」を、追従判断のたびに
  * 「欠落か別名か」の実読が発生することを理由に禁じている。2026-08-10 の裁定で
  * ファイル数の統合は撤回され、残る要件はこの名前の一意性だけである。
  *
+ * 判定の骨子 (正典 v1):
+ * - 母集団は **git 追跡下の全ファイル**。**内容とパス名の両方**を照合する
+ *   (中身を直しただけのファイルが旧名のパスで復活する経路は内容走査では塞げない)。
+ * - 旧名が現れてよいのは「いつ・誰が・何をしたかの記録」だけで、
+ *   **出現を 1 つずつ特定する申告** (対象ファイル / 旧名 / その出現を一意に特定する
+ *   周辺文字列 / 残す理由) を台帳に並べる。**行番号は使わない** (無関係な編集で動くため)。
+ *   **件数は申告の本数から導く** (件数の pin を別に持たない = 二重管理を作らない)。
+ * - 突き合わせは 3 方向で落ちる — 申告外の出現がある / 申告があるのに実物から消えた
+ *   (周辺文字列が 1 回に特定できない) / 申告が同じ出現を二重に指している。
+ *   この 3 つが「申告数と実出現数の不一致」を含意する。
+ * - **パス名に申告の口は無い** (記録としてファイル名に旧名が要る事案は無いため 0 件固定)。
+ *
  * ★保証範囲を誇張しない:
  *   - 見るのは**字面**である。旧名を分割して連結する書き方・別名の定数経由・
  *     動的に組み立てた文字列には**沈黙する**。
- *   - **丸ごと除外した分類 (c) の中では沈黙する**。分類 (b) は登録済みの件数だけを許容し、
- *     増減も旧名ごとの内訳の入れ替えも検出する (沈黙しない)。
+ *   - **丸ごと除外した 2 つ (`devnotes/` 配下と本ファイル自身) の中では沈黙する**。
+ *     そこに旧名を書いても本検査は検出しない (パス名の照合も除外が先に効く)。
+ *   - 申告について保証するのは「周辺文字列が実物にちょうど 1 回あり、それが指す出現の集合が
+ *     実出現の集合と一致する」ことまでである。**その記録が意味として妥当かは人のレビュー**が見る。
  *   - 家系名が「正しい名前であること」は検査できない。正本は機能台帳であり、
  *     本検査が固定するのは「旧名が現役の資産に残っていないこと」だけである。
+ *   - git 未追跡のファイルは母集団に入らない (境界は commit / CI であり、そこでは追跡下にある)。
+ *
+ * ★走査器共通規約 (AGENTS.md) との関係:
+ *   - (a) は対象外 — クラス名を**連続した字面**として探す走査で、名前参照の解決を行わない。
+ *   - (b) fail-closed — `git ls-files` の失敗と読めないファイルは**例外**にする (空集合にしない)。
+ *   - (c) 検出力は N-4 の負のコントロールが**同じ純関数**を通して裏取りする。
+ *   - (d) 集めた走査結果はすべて判定に使う (数えるだけの目録を持たない)。
+ *   - (e) は対象外 — 区切り文字でトークン化した完全一致にすると、実在する接尾辞つきの出現
+ *     (`docs/TODO-closed.md` の `FakeExternalsServiceProviderTest`) を**見逃す**方向へ倒れる。
+ *     本検査は許可語の除去や否定形の語彙判定を持たないため (e) の母集団に入らない。
+ *
+ * ★`Tests\Support\TrackedPhpSourceFiles` / `Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets`
+ *   との関係: 3 者は同じ作法 (`git ls-files`) を使うが**母集団の定義が違う**兄弟である。
+ *   前者は `.php` 全数、後者は走査根 8 本 (`docs/` と `.claude/` を見ない)、本検査は
+ *   **追跡下の全ファイル**である。本 feature の主敵は規約文書・スキル・手順書に残る旧名なので
+ *   `docs/` と `.claude/` を母集団から外せない。列挙を 2 本持つのではなく対象の定義が違う。
+ *   関心事の境界 (撤去物の不在 = surface-removal-absence-gate / 旧名の残留 = 本検査) は
+ *   機能台帳が名指しで分けている。
+ *
+ * ★申し送り: 裁定 AG-085 の 3 件目 (並列枠数上限の検査の名前) は feature `bughunt-runtime` の
+ *   管轄で、本検査の写像には**入っていない**。将来その改名を行うときは写像へ 1 件足すこと。
  *
  * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
  */
@@ -37,42 +73,53 @@
 ];
 
 /**
- * (b) 旧名を持つことが確認済みの**過去と現在の記録**と、**旧名ごとの**件数。
+ * 旧名の出現を許す場所の申告台帳 (**出現を 1 つずつ特定する**)。
  *
- * 件数は**完全一致**で、増えても減っても赤になる (`ForbiddenStatementExemption` /
- * `ROUTE_CACHE_PREMISE_KNOWN_MENTIONS` と同じ作法)。丸ごと除外にしないのは、
- * 除外したファイルの中で将来 旧名が再流入しても沈黙してしまうためである。
+ * `needle` = その出現を一意に特定する周辺文字列。実ファイルに**ちょうど 1 回**現れ、
+ * 対象の旧名を**ちょうど 1 回**含むこと。`reason` = 残す理由 (30 文字以上)。
+ * **件数は申告の本数**であり、別に pin しない。
  *
- * ★合計ではなく**旧名ごと**に固定する。合計だけを見ると「片方を 1 件減らして
- *   もう片方を 1 件増やす」書き換えが緑のまま通る。
+ * ★**記録を動かすときは同じ変更で申告も動かす** (意図的な摩擦)。作業台帳の行が
+ *   `docs/TODO.md` から `docs/TODO-closed.md` へ移ったら、正しい直し方は
+ *   「記録を書き換える」のではなく**申告を足す・移す・外す**である。
+ * ★申告 0 件の登録は書かない (実物から消えた申告と区別できないため)。`docs/TODO.md` は
+ *   現在旧名 0 件なので**登録そのものを持たない** — deny-by-default なので 1 つ現れれば赤になる。
  *
- * - `docs/TODO-closed.md`: 完了した TODO の記録。T015 / T119 が当時作ったクラスの
- *   名前は当時の事実であり、書き換えると記録が嘘になる。
- * - `docs/TODO.md`: 本件 (T214) の登録行そのものが「どの名前をどの名前へ改名するか」を
- *   書いているため旧名を含む。これも記録であって現役の資産ではない。
- *
- * ★**TODO 台帳を動かすときは本 pin も同じ変更の中で更新する** (意図的な摩擦)。
- *   T214 をクローズすると登録行が `docs/TODO.md` から `docs/TODO-closed.md` へ移り、
- *   両ファイルの件数が同時に動く。そのときは「記録を書き換える」のではなく
- *   **pin の数を直す**のが正しい直し方である。
- *
- * @var array<string, array<string, int>>
+ * @var array<string, array<string, list<array{needle: string, reason: string}>>>
  */
-const BUGHUNT_NAMING_KNOWN_MENTIONS = [
+const BUGHUNT_NAMING_DECLARED_OCCURRENCES = [
     'docs/TODO-closed.md' => [
-        'BughuntBillingSeeder' => 2,
-        'FakeExternalsServiceProvider' => 3,
-    ],
-    'docs/TODO.md' => [
-        'BughuntBillingSeeder' => 0,
-        'FakeExternalsServiceProvider' => 0,
+        'BughuntBillingSeeder' => [
+            [
+                'needle' => '・BughuntBillingSeeder (有料プラン組織のみ active subscription',
+                'reason' => 'T015 (bug-hunt 基盤整備) の完了行。当時作った seeder の名前は当時の事実であり、書き換えると記録が嘘になる',
+            ],
+            [
+                'needle' => '`database/seeders/BughuntBillingSeeder` → `BughuntStripeSyncSeeder`',
+                'reason' => 'T214 (家系名への改名) の完了行が持つ改名の対応表。旧名側を消すと何を何へ改名したのかが読めなくなる',
+            ],
+        ],
+        'FakeExternalsServiceProvider' => [
+            [
+                'needle' => '・FakeExternalsServiceProvider (flag + 環境 allowlist',
+                'reason' => 'T015 (bug-hunt 基盤整備) の完了行。当時作った provider の名前は当時の事実であり、書き換えると記録が嘘になる',
+            ],
+            [
+                'needle' => '`FakeExternalsServiceProviderTest` は 6 test ではなく 8 test',
+                'reason' => 'T119 (fake 配線の実証検査) の完了行が持つ台帳との食い違いの記録。当時のテストクラス名を指しており改名できない',
+            ],
+            [
+                'needle' => '`app/Providers/FakeExternalsServiceProvider` → `BughuntFakesServiceProvider`',
+                'reason' => 'T214 (家系名への改名) の完了行が持つ改名の対応表。旧名側を消すと何を何へ改名したのかが読めなくなる',
+            ],
+        ],
     ],
 ];
 
 /**
- * (c) 丸ごと走査から外す置き場所 (repo 相対の接頭辞)。
+ * 丸ごと走査から外す置き場所 (repo 相対の接頭辞)。
  *
- * `devnotes/` の設計・レビュー・走行記録は 190 ファイル規模で旧名を含み、件数 pin が
+ * `devnotes/` の設計・レビュー・走行記録は 190 ファイル規模で旧名を含み、出現ごとの申告が
  * 実務にならない (`ForbiddenStatementTokenInvariantTest` が devnotes を理由付きで
  * 除外するのと同じ扱い)。
  *
@@ -83,34 +130,79 @@
 const BUGHUNT_NAMING_EXCLUDED_PREFIXES = ['devnotes/'];
 
 /**
- * (c) 丸ごと走査から外す唯一のファイル = 本テスト自身。
+ * 丸ごと走査から外す唯一のファイル = 本テスト自身。
  *
- * 検出したい語を負のコントロールの入力として持つため、自分を走査すると必ず自分で赤くなる。
+ * 申告の needle と負のコントロールの入力として旧名を持つため、自分を走査すると必ず自分で赤くなる。
  * **保証の穴として明記する**: 本ファイルの中に旧名を書いても本検査は沈黙する。
+ * 骨抜きにならないことは (1) 申告が実出現と一致すること (N-3) と
+ * (2) 家系名を実際に見つける正の対照 (N-2) の 2 つで担保する。
  */
 const BUGHUNT_NAMING_SELF_PATH = 'tests/Architecture/BughuntNamingResidualTest.php';
 
 /**
- * 走査の母集団が空振りでないことを確かめる代表パス (改名後に実在するもの)。
+ * 置き換え先 (家系名) の番兵。家系名 => その名前が実在するファイル。
+ *
+ * 正典の要求「置き換え先が実在しかつ git 追跡下にあること」を満たす
+ * (未追跡だと母集団に入らず走査が空振りする)。N-2 は**同じ読み取り機構**で
+ * この名前を実際に見つけることまで確かめる (正の対照)。
+ *
+ * @var array<string, string>
+ */
+const BUGHUNT_NAMING_CANONICAL_SENTINELS = [
+    'BughuntStripeSyncSeeder' => 'database/seeders/BughuntStripeSyncSeeder.php',
+    'BughuntFakesServiceProvider' => 'app/Providers/BughuntFakesServiceProvider.php',
+];
+
+/**
+ * 走査の母集団が空振りでないことを確かめる参照側の代表パス。
  *
  * @var list<string>
  */
 const BUGHUNT_NAMING_SENTINEL_PATHS = [
     'bootstrap/providers.php',
     'scripts/bug-hunt-shard.sh',
-    'database/seeders/BughuntStripeSyncSeeder.php',
-    'app/Providers/BughuntFakesServiceProvider.php',
 ];
 
 /** 母集団の下限 (これを下回ったら列挙そのものを疑う) */
 const BUGHUNT_NAMING_MINIMUM_TRACKED_FILES = 500;
 
+/** 申告の理由に求める最小文字数 (本リポジトリの目録の作法に合わせる)。 */
+const BUGHUNT_NAMING_MINIMUM_REASON_LENGTH = 30;
+
+/**
+ * `$haystack` 内の `$needle` の出現位置 (バイト位置、昇順)。
+ *
+ * ★重なり合う出現も**別の出現として数える** (見逃さない側へ倒す)。
+ * ★空文字は出現位置を持たないので例外にする (申告の書き方の誤り)。
+ *
+ * @return list<int>
+ */
+function bughuntNamingOffsetsOf(string $haystack, string $needle): array
+{
+    if ($needle === '') {
+        throw new RuntimeException('空文字は出現位置を持たない (旧名の残留検査の申告の書き方の誤り)');
+    }
+
+    $offsets = [];
+    $from = 0;
+
+    while (($at = strpos($haystack, $needle, $from)) !== false) {
+        $offsets[] = $at;
+        $from = $at + 1;
+    }
+
+    return $offsets;
+}
+
 /**
  * 1 ファイル分の違反 (純関数 = 負のコントロールが**同じ述語**を通せる)。
  *
+ * 申告台帳は**引数で受ける** — 負のコントロールが実ファイルの内容に依存しないため。
+ *
+ * @param  array<string, array<string, list<array{needle: string, reason: string}>>>  $declarations
  * @return list<string>
  */
-function bughuntNamingViolationsIn(string $relative, string $content): array
+function bughuntNamingViolationsIn(string $relative, string $content, array $declarations): array
 {
     if ($relative === BUGHUNT_NAMING_SELF_PATH) {
         return [];
@@ -122,21 +214,90 @@ function bughuntNamingViolationsIn(string $relative, string $content): array
         }
     }
 
-    // 記録は「0 件」ではなく「pin した件数ちょうど」を旧名ごとに要求する。
-    $pinned = BUGHUNT_NAMING_KNOWN_MENTIONS[$relative] ?? [];
-
     $violations = [];
+
     foreach (BUGHUNT_RETIRED_NAMES as $retired => $canonical) {
-        $count = substr_count($content, $retired);
-        $allowed = $pinned[$retired] ?? 0;
+        // (1) パス名の照合 — 申告の口は無い (0 件固定)。
+        if (str_contains($relative, $retired)) {
+            $violations[] = sprintf(
+                'パス名に旧名が復活している: %s (旧名 %s / 家系名 %s) — パスごと家系名へ改名すること'
+                .' (パス名には申告の口が無い)',
+                $relative,
+                $retired,
+                $canonical
+            );
+        }
 
-        if ($count === $allowed) {
-            continue;
+        // (2) 内容の照合 — 実出現の位置集合と、申告が指す位置集合を突き合わせる。
+        $actual = bughuntNamingOffsetsOf($content, $retired);
+        $declared = [];
+
+        foreach ($declarations[$relative][$retired] ?? [] as $entry) {
+            $inner = bughuntNamingOffsetsOf($entry['needle'], $retired);
+
+            if (count($inner) !== 1) {
+                $violations[] = sprintf(
+                    '申告の周辺文字列が旧名をちょうど 1 回含まない: %s / 旧名 %s / 周辺文字列 "%s" (含む回数 %d)'
+                    .' / 理由: %s — 出現を 1 つだけ指す文字列に書き直すこと',
+                    $relative,
+                    $retired,
+                    $entry['needle'],
+                    count($inner),
+                    $entry['reason']
+                );
+
+                continue;
+            }
+
+            $hits = bughuntNamingOffsetsOf($content, $entry['needle']);
+
+            if (count($hits) !== 1) {
+                $violations[] = sprintf(
+                    '申告が出現を特定できない: %s / 旧名 %s / 周辺文字列 "%s" が %d 回 (ちょうど 1 回であること)'
+                    .' / 理由: %s — 記録を書き換えるのではなく、申告を足す・移す・外すこと',
+                    $relative,
+                    $retired,
+                    $entry['needle'],
+                    count($hits),
+                    $entry['reason']
+                );
+
+                continue;
+            }
+
+            $declared[] = $hits[0] + $inner[0];
+        }
+
+        sort($declared);
+
+        // 有効な申告位置は構築上必ず実出現位置に含まれる (周辺文字列は本文にちょうど 1 回・
+        // 旧名をちょうど 1 回) ため、逆向きの差分は常に空になる。だから片方向だけを見る。
+        $undeclared = array_values(array_diff($actual, $declared));
+
+        if ($undeclared !== []) {
+            $violations[] = sprintf(
+                '申告外の出現がある: %s / 旧名 %s (家系名 %s) / 実出現 %d 件・申告 %d 件'
+                .' / 未申告の位置 %s — 改名の取りこぼしなら家系名へ直すこと。記録として残すなら、'
+                .'記録を書き換えるのではなく、申告を足す・移す・外すこと',
+                $relative,
+                $retired,
+                $canonical,
+                count($actual),
+                count($declared),
+                implode(', ', array_map('strval', $undeclared))
+            );
         }
 
-        $violations[] = $allowed === 0
-            ? "{$relative}: {$retired} が {$count} 箇所残っている (家系名は {$canonical})"
-            : "{$relative}: {$retired} の出現が {$count} 箇所 (pin は {$allowed} 箇所)";
+        if (count($declared) !== count(array_unique($declared))) {
+            $violations[] = sprintf(
+                '申告が同じ出現を二重に指している: %s / 旧名 %s / 実出現 %d 件・申告 %d 件'
+                .' — 記録を書き換えるのではなく、申告を足す・移す・外すこと',
+                $relative,
+                $retired,
+                count($actual),
+                count($declared)
+            );
+        }
     }
 
     return $violations;
@@ -145,8 +306,7 @@ function bughuntNamingViolationsIn(string $relative, string $content): array
 /**
  * git 追跡下の全ファイル (repo 相対パス、昇順)。
  *
- * ★対象は拡張子を問わない (シェル / 文書 / 環境ひな型も見る) ため
- *   `Tests\Support\TrackedPhpSourceFiles` は使えない。共用クラスを新設せず本テスト内に閉じる。
+ * ★対象は拡張子を問わない (シェル / 文書 / 環境ひな型も見る)。
  * ★git が使えない環境では**空を返さず例外**にする (fail-open の防止)。
  *
  * @return list<string>
@@ -195,11 +355,17 @@ function bughuntNamingSourceOf(string $relative): string
     return $content;
 }
 
-test('N-1 追跡下の現役資産に旧名が 1 つも残っておらず、記録は pin した件数ちょうどである', function (): void {
+test('N-1 追跡下の内容とパス名に旧名の残留が無く、記録は申告と厳密に一致する', function (): void {
     $violations = [];
 
     foreach (bughuntNamingTrackedFiles() as $relative) {
-        foreach (bughuntNamingViolationsIn($relative, bughuntNamingSourceOf($relative)) as $violation) {
+        $found = bughuntNamingViolationsIn(
+            $relative,
+            bughuntNamingSourceOf($relative),
+            BUGHUNT_NAMING_DECLARED_OCCURRENCES
+        );
+
+        foreach ($found as $violation) {
             $violations[] = $violation;
         }
     }
@@ -207,7 +373,7 @@ function bughuntNamingSourceOf(string $relative): string
     expect($violations)->toBe([]);
 });
 
-test('N-2 fail-closed: 走査の母集団が空振りしていない', function (): void {
+test('N-2 fail-closed: 走査が空振りしていない (母集団の下限・番兵・家系名の正の対照)', function (): void {
     $files = bughuntNamingTrackedFiles();
 
     expect(count($files))->toBeGreaterThanOrEqual(
@@ -218,54 +384,179 @@ function bughuntNamingSourceOf(string $relative): string
     foreach (BUGHUNT_NAMING_SENTINEL_PATHS as $sentinel) {
         expect($files)->toContain($sentinel);
     }
+
+    // 正の対照: 「旧名 0 件」が走査の故障による偽の緑でないことを、在るはずの家系名で確かめる。
+    // 置き換え先が実在しかつ git 追跡下にあること (正典の要求) もここで満たす。
+    foreach (BUGHUNT_NAMING_CANONICAL_SENTINELS as $canonical => $path) {
+        expect($files)->toContain($path);
+
+        expect(bughuntNamingOffsetsOf(bughuntNamingSourceOf($path), $canonical))->not->toBe(
+            [],
+            "家系名 {$canonical} が {$path} の内容で見つからない — 走査条件の陳腐化を疑う",
+        );
+        expect(str_contains($path, $canonical))->toBeTrue(
+            "家系名 {$canonical} がパス名 {$path} で見つからない — 番兵の陳腐化を疑う",
+        );
+    }
 });
 
-test('N-3 走査の外し方が意図どおり (ファイル数ではなく**定義の数**を固定する)', function (): void {
+test('N-3 申告台帳と除外の構造が意図どおり (台帳から実物への逆方向も見る)', function (): void {
     // 丸ごと除外の定義は 2 つちょうど (接頭辞 devnotes/ が 1 件 + 本テスト自身が 1 件)。
     expect(BUGHUNT_NAMING_EXCLUDED_PREFIXES)->toBe(['devnotes/'])
         ->and(BUGHUNT_NAMING_SELF_PATH)->toBe('tests/Architecture/BughuntNamingResidualTest.php');
 
-    // 件数 pin の定義は 2 ファイル分ちょうど (TODO 台帳の 2 冊)。旧名は 2 種とも書く。
-    expect(array_keys(BUGHUNT_NAMING_KNOWN_MENTIONS))->toBe(['docs/TODO-closed.md', 'docs/TODO.md']);
-
-    foreach (BUGHUNT_NAMING_KNOWN_MENTIONS as $pinned) {
-        expect(array_keys($pinned))->toBe(array_keys(BUGHUNT_RETIRED_NAMES));
-    }
-
     // 退役した名前は 2 つで、家系名と 1:1 に対応する。
     expect(BUGHUNT_RETIRED_NAMES)->toBe([
         'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
         'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
     ]);
-});
 
-test('N-4 負のコントロール: 同じ述語が検出する / しないの境界', function (): void {
-    $retired = array_keys(BUGHUNT_RETIRED_NAMES);
-    $seeder = $retired[0];
-    $provider = $retired[1];
+    // 置き換え先には 1 つずつ番兵がある (写像の値と番兵のキーが完全一致)。
+    expect(array_keys(BUGHUNT_NAMING_CANONICAL_SENTINELS))->toBe(array_values(BUGHUNT_RETIRED_NAMES));
 
-    // (a) 現役資産に旧名があれば検出する
-    expect(bughuntNamingViolationsIn('app/Foo.php', "class Foo extends {$seeder} {}"))->toHaveCount(1);
+    // 申告台帳のキーは記録 1 冊ちょうど (docs/TODO.md は旧名 0 件なので登録を持たない)。
+    expect(array_keys(BUGHUNT_NAMING_DECLARED_OCCURRENCES))->toBe(['docs/TODO-closed.md']);
 
-    // (b) devnotes/ は丸ごと外れている (沈黙する)
-    expect(bughuntNamingViolationsIn('devnotes/x/y.md', "{$seeder} {$provider}"))->toBe([]);
+    $files = bughuntNamingTrackedFiles();
 
-    // (c) 本テスト自身も丸ごと外れている (沈黙する)
-    expect(bughuntNamingViolationsIn(BUGHUNT_NAMING_SELF_PATH, "{$seeder} {$provider}"))->toBe([]);
+    foreach (BUGHUNT_NAMING_DECLARED_OCCURRENCES as $path => $perRetiredName) {
+        // ★`toContain` は可変長の needle を取るので**メッセージを渡さない** (第 2 引数は
+        //   もう 1 つの needle として解釈される)。理由文を添える判定は真偽値へ落として書く。
+        expect(in_array($path, $files, true))->toBeTrue(
+            "申告した記録が追跡下に無い: {$path} — ファイルごと消えたなら申告も外すこと",
+        );
 
-    // (d) pin したファイルで件数がずれたら検出する (少なくても多くても)
-    // docs/TODO-closed.md の pin は T214 クローズ後の値 (seeder=2 / provider=3)。
-    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$seeder} {$seeder} {$provider} {$provider} {$provider}"))->toBe([]);
-    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$provider} {$provider} {$provider}"))->toHaveCount(1);
-    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$seeder} {$seeder} {$seeder} {$provider} {$provider} {$provider}"))->toHaveCount(1);
+        $content = bughuntNamingSourceOf($path);
+
+        expect($perRetiredName)->not->toBe([], "旧名の項目を 1 つも持たない登録: {$path} — 行ごと外すこと");
+
+        foreach ($perRetiredName as $retired => $entries) {
+            expect(BUGHUNT_RETIRED_NAMES)->toHaveKey($retired);
+            expect($entries)->not->toBe([], "申告 0 件の登録は意味を持たない: {$path} / {$retired} — 行ごと外すこと");
+
+            foreach ($entries as $entry) {
+                expect(mb_strlen($entry['reason']))->toBeGreaterThanOrEqual(
+                    BUGHUNT_NAMING_MINIMUM_REASON_LENGTH,
+                    "申告の理由が短すぎる: {$path} / {$retired}",
+                );
+                expect(bughuntNamingOffsetsOf($entry['needle'], $retired))->toHaveCount(
+                    1,
+                    "申告の周辺文字列が旧名をちょうど 1 回含まない: {$path} / {$retired} — {$entry['needle']}",
+                );
+                expect(bughuntNamingOffsetsOf($content, $entry['needle']))->toHaveCount(
+                    1,
+                    "申告の周辺文字列が実物にちょうど 1 回現れない: {$path} / {$retired} — {$entry['needle']}",
+                );
+            }
+
+            // 件数は申告の本数から導く (別に pin を持たない)。
+            expect(count($entries))->toBe(
+                count(bughuntNamingOffsetsOf($content, $retired)),
+                "申告の本数が実出現数と合わない: {$path} / {$retired}",
+            );
+        }
+    }
+});
 
-    // (e) 合計は同じだが内訳が違う入力も検出する (旧名ごとに固定しているため)
-    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$seeder} {$seeder} {$seeder} {$provider} {$provider}"))->toHaveCount(2);
+test('N-4 負のコントロール: 同じ述語が検出する / しないの境界', function (): void {
+    $retired = array_keys(BUGHUNT_RETIRED_NAMES);
+    $canonical = array_values(BUGHUNT_RETIRED_NAMES);
+    $seeder = $retired[0];
+    $provider = $retired[1];
 
-    // (f) もう 1 冊の TODO 台帳 (docs/TODO.md は T214 クローズ後、旧名ともに 0 件) でも同じ境界が働く
-    expect(bughuntNamingViolationsIn('docs/TODO.md', ''))->toBe([]);
-    expect(bughuntNamingViolationsIn('docs/TODO.md', "{$seeder}"))->toHaveCount(1);
-    expect(bughuntNamingViolationsIn('docs/TODO.md', "{$seeder} {$provider}"))->toHaveCount(2);
+    // 合成の申告台帳と合成の本文 (実ファイルの内容に依存させない)。
+    $reason = '負のコントロール用の合成理由 (30 文字以上であることを N-3 と同じ規則で満たす)';
+    $ledger = [
+        'docs/record.md' => [
+            $seeder => [
+                ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
+            ],
+        ],
+    ];
+    $body = "行 1: T001 で {$seeder} を作った\n行 2: ふつうの文\n";
+
+    // (a) 申告どおりなら緑
+    expect(bughuntNamingViolationsIn('docs/record.md', $body, $ledger))->toBe([]);
+
+    // (b) ★v1 の主眼: 件数は同じだが出現箇所をすり替えた入力は赤になる
+    //     (申告の周辺文字列が消え、別の位置に未申告の出現が生まれる = 2 件)
+    $swapped = "行 1: ふつうの文\n行 2: T002 で {$seeder} を消した\n";
+    $swappedViolations = bughuntNamingViolationsIn('docs/record.md', $swapped, $ledger);
+    expect($swappedViolations)->toHaveCount(2);
+    expect(implode("\n", $swappedViolations))->toContain('申告を足す・移す・外す');
+
+    // (c) 申告外の出現が増えたら赤
+    expect(bughuntNamingViolationsIn('docs/record.md', $body."後から {$seeder}\n", $ledger))->toHaveCount(1);
+
+    // (d) 申告があるのに実物から消えたら赤
+    expect(bughuntNamingViolationsIn('docs/record.md', "行 1: ふつうの文\n", $ledger))->toHaveCount(1);
+
+    // (e) 申告の無いファイルの内容に旧名があれば赤 (deny-by-default)
+    expect(bughuntNamingViolationsIn('app/Foo.php', "class Foo extends {$seeder} {}", $ledger))->toHaveCount(1);
+
+    // (f) ★パス名に旧名を持つファイルは、内容が空でも赤
+    expect(bughuntNamingViolationsIn("app/Providers/{$provider}.php", '', $ledger))->toHaveCount(1);
+
+    // (g) 置き換え先 (家系名) は内容もパス名も誤検出しない
+    expect(bughuntNamingViolationsIn("database/seeders/{$canonical[0]}.php", "class {$canonical[0]} {}", $ledger))->toBe([]);
+    expect(bughuntNamingViolationsIn("app/Providers/{$canonical[1]}.php", "class {$canonical[1]} {}", $ledger))->toBe([]);
+
+    // (h) 丸ごと除外した 2 つは沈黙する (保証の穴の実測)
+    expect(bughuntNamingViolationsIn('devnotes/x/y.md', "{$seeder} {$provider}", $ledger))->toBe([]);
+    expect(bughuntNamingViolationsIn(BUGHUNT_NAMING_SELF_PATH, "{$seeder} {$provider}", $ledger))->toBe([]);
+
+    // (i) 周辺文字列が 2 回現れる (出現を特定できない) 場合も赤
+    $twice = "行 1: T001 で {$seeder} を作った\n行 2: T001 で {$seeder} を作った\n";
+    expect(bughuntNamingViolationsIn('docs/record.md', $twice, $ledger))->toHaveCount(2);
+
+    // (j) 同じ出現を二重に申告したら赤
+    $duplicated = [
+        'docs/record.md' => [
+            $seeder => [
+                ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
+                ['needle' => "T001 で {$seeder} を作った", 'reason' => $reason],
+            ],
+        ],
+    ];
+    $duplicateViolations = bughuntNamingViolationsIn('docs/record.md', $body, $duplicated);
+    expect($duplicateViolations)->toHaveCount(1);
+    expect(implode("\n", $duplicateViolations))->toContain('二重に指している');
+
+    // (k) 周辺文字列が旧名を 2 回含む (出現を 1 つに絞れていない) 申告は赤
+    $ambiguous = [
+        'docs/record.md' => [
+            $seeder => [
+                ['needle' => "T001 で {$seeder} と {$seeder}", 'reason' => $reason],
+            ],
+        ],
+    ];
+    $ambiguousViolations = bughuntNamingViolationsIn('docs/record.md', "T001 で {$seeder} と {$seeder}\n", $ambiguous);
+    // 2 件は別の情報である — (1) 申告そのものが不正 / (2) その申告を採用できなかった結果として
+    // 実出現が未申告になる。両方出す**診断方針を契約として固定する**。将来「原因の申告エラーが
+    // あれば派生を抑制する」方針へ変えるなら、それは診断方針の変更なので期待件数も同じ変更で直す。
+    expect($ambiguousViolations)->toHaveCount(2);
+    expect(implode("\n", $ambiguousViolations))->toContain('ちょうど 1 回含まない');
+
+    // (l) ★件数は一致するが 2 つの申告が同じ出現を指し、別の 1 件が未申告になる入力。
+    //     件数の比較だけなら緑になるため、**出現位置の集合一致でなければ捕まらない**。
+    //     この 1 ケースが「位置集合まで強める価値」の実測である。
+    $twoOccurrences = "行 1: T001 で {$seeder} を作った\n行 2: T002 で {$seeder} を消した\n";
+    $sameSpotTwice = [
+        'docs/record.md' => [
+            $seeder => [
+                ['needle' => "T001 で {$seeder}", 'reason' => $reason],
+                ['needle' => "で {$seeder} を作った", 'reason' => $reason],
+            ],
+        ],
+    ];
+    // 申告 2 件・実出現 2 件 = 件数は一致する (前提の確認)。
+    expect(count($sameSpotTwice['docs/record.md'][$seeder]))
+        ->toBe(count(bughuntNamingOffsetsOf($twoOccurrences, $seeder)));
+
+    $sameSpotViolations = bughuntNamingViolationsIn('docs/record.md', $twoOccurrences, $sameSpotTwice);
+    expect($sameSpotViolations)->toHaveCount(2);
+    expect(implode("\n", $sameSpotViolations))->toContain('申告外の出現がある');
+    expect(implode("\n", $sameSpotViolations))->toContain('二重に指している');
 });
 
 test('N-5 旧名のクラスは存在せず、家系名のクラスが存在する', function (): void {
```

### 3. テスト結果

#### 本 gate 単独 (最終形)

```
{"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":68,"duration_ms":850}
```

#### テストファースト手順の実測 (赤の記録 — devnotes/20260824-1111-todo-T254/red-evidence.md)

- 手順 1: パス名照合を外した述語 → N-4 (f) が 0 件検出で赤 (= 現行の穴 2 の再現)
- 手順 2: 突き合わせを「申告の本数と実出現数の比較」だけに退化 → 12 ケース中
  **(b) 件数同じで出現すり替え / (l) 2 申告が同じ出現・別の 1 件が未申告 の 2 つだけが沈黙**
  (設計の予測と一致。(j)(k) は件数比較でも赤で、申告の入力契約の負例という位置づけも裏取り済み)
- 手順 3: N-3 の 5 条件を 1 つずつ壊して全て赤
- 手順 4: N-2 の正の対照を 2 通り壊して全て赤

#### 静的検査

```
composer phpstan   → [OK] No errors (1114 files, level 10)
vendor/bin/pint --test → passed
```

#### 実測値

- git 追跡下ファイル数 10066 (下限 pin 500)
- `docs/TODO-closed.md` の旧名出現: 2 件 / 3 件 (申告 5 本と完全一致)
- `docs/TODO.md` の旧名出現: 0 件 (申告の登録を持たない = deny-by-default)
- パス名に旧名を持つ追跡ファイル: 0 件

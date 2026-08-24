# 概念設計レビュー依頼 (Round 1)

## アプリの使命 (North Star) — 絶対遵守

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。
## 禁止事項

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
## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

## 追加の文脈 (レビューに必要な前提)

- 本件は社内の複数リポジトリを横断する「機能台帳 (lctl)」が確定した**正典 (canonical) v1** への追従作業である。正典 v1 の形は姉妹リポジトリ spirux の実装 (`tests/Support/PromptWaitBudget.php` + `tests/Architecture/PromptYamlContractTest.php`) であり、aicue はその 3 点 (単一読み取り器 / 検出器の負例自己テスト / 分母の到達証明) が欠けた「ひな形形 (t1)」の状態にある。設計の裁量は「aicue でどう置くか」に限られ、「正典の形を採るかどうか」は台帳側で既に決着している。
- 対象は**すべてテストコード**である (`tests/` 配下)。アプリの実行コード・DB・UI・API は 1 行も変わらない。PHPStan の `paths` は `tests` を含まないため、本変更は level 10 の検査対象外である。
- 参考: spirux の正典実装 (抜粋)。読み取り器は `array{timeout: int|null, violations: list<string>}` を返し、gate 側は違反ラベルの集合で照合する。負例 fixture は 9 類型 (missing-client-options / client-options-not-array / missing-timeout / zero / negative / numeric-string / float / bool / null) + 正例 1 本。

---

## 概念設計

# 概念設計: llm-prompt-wait-budget-v1 (LLM 待ち予算の読み取り規則を単一化する)

対象 feature: lctl 機能台帳 `llm-prompt-wait-budget`
(aicue セル: `status: update_pending` / `version: t1 (再帰全数走査・一括報告のひな形形)` /
`target_version: v1 (LLM 待ち予算宣言の既定拒否 gate — 単一読み取り器 + 検出器自己テスト形)`)

## 背景・課題

LLM への依頼文は `resources/prompts/*.yaml` に置き、そこに
「返事を何秒まで待つか」= `client_options.timeout` を書く。書き忘れると実効値は
`config/prism.php` の `request_timeout`(30 秒) に落ちる。解析パイプラインは
1 呼び出し 360 秒を前提に deadline / job timeout / retry_after / 予約 TTL の連鎖を組んでいるため、
宣言が 1 行落ちると **平常時は何も起きず、provider が黙り込んだときだけ**
「解析が途中で切れる」「ワーカーが塞がって後続が詰まる」という形で遠くに症状が出る。
だから人の注意力ではなく分母の全数固定で守る、というのが本 feature の趣旨である。

aicue は既に `tests/Architecture/PromptClientTimeoutInvariantTest.php` +
`tests/Support/PromptYaml.php` の**ひな形形 (t1)** を持っている。持っているのは 2 点だけ:

- `resources/prompts` 配下の再帰全数走査 (0 件を失敗にする / 大文字拡張子も拾う)
- 違反を配列へ集めてまとめて報告する

実コードを読んで裏取りした結果、**台帳の記述は正しく、かつ台帳より状況は悪い**。
「待ち予算 (`client_options.timeout`) を読む規則」が**3 実装**に散っている:

| # | 場所 | 読み方 | 抜け |
|---|---|---|---|
| 1 | `tests/Architecture/PromptClientTimeoutInvariantTest.php` L28-36 | インライン判定 (`client_options` が配列か → `['timeout'] ?? null` → `is_int` && `> 0`) | 分母の到達証明が無い (`not->toBeEmpty()` だけ)。負例による検出力の裏取りが無い |
| 2 | `tests/Support/AnalysisBudget.php::clientTimeoutSecondsFromYaml()` | `Webmozart\Assert` 版。対象 3 本をクラス定数でハードコード | **`0` 以下を検出しない** (`Assert::integer` までしか見ない) |
| 3 | `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` L134 | 素の配列参照 `$yaml['client_options']['timeout'] ?? null` を `toBe()` で比較 | 未宣言と「一致しない値」が同じ失敗に潰れる |

同じ規則の複数実装は正典 v1 が明示的に禁じている形であり、実害の形も分かっている
(家系の spirux では「宣言が無いとき空を 0 として扱う」実装が 1 本混ざっていたため、
宣言を 1 行消すと必要量が 0 になって**緑のまま通った** = 偽の緑)。
aicue の #2 も同じ性質の緩みを持っている (`timeout: 0` を通す)。#1 が同じ YAML を
見ているので現時点で穴は閉じているが、**穴が閉じている理由が「別のテストが偶然厳しいから」**
という状態であり、#1 が壊れたときに #2 と #3 は何も言わない。

## 改善アイデア

正典 v1 (spirux 形) と同じ「**単一読み取り器 + 検出器自己テスト**」へ寄せる。核は 3 点 + 1:

1. **読み取り規則を 1 クラスへ切り出す** — `Tests\Support\PromptWaitBudget` を新設し、
   待ち予算を読む検査**すべて**をそこへ寄せる。上表の 3 実装は同じ変更で消す
   (AGENTS.md 思考原則 3「後方互換の並走を残さない」)。
   規則は既定拒否: 未宣言 / `client_options` が非配列 / `timeout` キー無し /
   `is_int()` でない (数値文字列 `"300"` / 小数 / 真偽値 / 空値) / `<= 0` を**すべて違反**にする。
2. **検出器の自己テスト (負例)** — 上記 9 類型が**ラベルの集合として全部上がること**と、
   正例を誤検出しないことの両方向を固定する。
   件数照合にしない (1 件取りこぼして別の 1 件を二重報告しても件数だけは合う = 偽の緑)。
3. **分母の到達証明** — 走査結果に**自リポジトリの実在ファイルの固有名**が含まれることを検査する。
   現状の `expect($files)->not->toBeEmpty()` は、走査根の改名・移動で分母が
   「別のディレクトリの 1 件」に化けても緑のままになる。
4. **(構造依存要件)** ジョブの持ち時間の下限式側の YAML 読み出しも同じ読み取り器を参照させる。
   aicue は該当構造を持つ (`AnalysisBudget::DEADLINE_SECONDS = STAGE_COUNT × CLIENT_TIMEOUT_SECONDS`
   と `RunManualAnalysis::$timeout < retry_after < 予約 TTL <= stale 閾値` の連鎖)。

**壊してはいけないもの**: `AnalysisBudget::CLIENT_TIMEOUT_SECONDS`(=360) は**仕様値**であり
YAML から導出しない、という**意図的な二重化**を維持する。YAML と仕様値を突き合わせることで
初めて「YAML を勝手に変えた」ことを検出できるからで、統一するのは**読み取り規則だけ**であって
**値の出所は二重のまま**にする。

## 期待効果

- 使命への貢献: SOP → シナリオ生成の 3 段パイプライン (`sop-extract` /
  `work-decomposition` / `scenario-generation`) と OCR 経路 (`sop-extract-media`) は
  すべて 360 秒の待ち予算を前提に時間 budget 連鎖を組んでいる。宣言が落ちると
  「思考ゼロ・編集ゼロ」の中核である AI 解析が**沈黙して途中で切れる**。
  読み取り規則を 1 本にすると、どの検査から見ても同じ厳しさで落ちる。
- 具体的な改善見込み:
  - `timeout: 0` を通す実装 (#2) が消える。
  - 走査根の改名・移動 (`resources/prompts` の付け替え) が到達証明で赤くなる。
  - 検出力が負例で裏取りされ、「検査ファイルは在るが何も検出しない」状態と区別が付く
    (AGENTS.md 「検出力の主張の書き方」)。
  - 4 本目の実装が生まれても、読み取り器の docblock が「唯一の読み取り器」を宣言しているので
    レビューで見える。

## 実装方針（概要）

| # | 変更 | 対象 |
|---|---|---|
| 1 | 単一読み取り器の新設 | `tests/Support/PromptWaitBudget.php` (新規) |
| 2 | gate の書き換え + 到達証明 | `tests/Architecture/PromptClientTimeoutInvariantTest.php` |
| 3 | 検出器の自己テスト (負例 9 類型 + 正例 + 解決不能形) | `tests/Unit/Architecture/PromptWaitBudgetTest.php` (新規) + `tests/Architecture/fixtures/prompt-wait-budget/` (新規) |
| 4 | 下限式側の読み出しを寄せる | `tests/Support/AnalysisBudget.php` |
| 5 | 素の配列参照を寄せる | `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` |
| 6 | 乖離台帳への登録 | `docs/template-divergence.md` + `tests/Support/TemplateDivergence/LedgerPins.php` |
| 7 | 読み取り規則の正本への 1 行の道標 | `docs/architecture.md` |

`tests/Support/PromptYaml.php` は**触らない**。走査 (再帰・拡張子照合・parse) は既存の共有
ヘルパのままにし、新しい読み取り器は**その走査を使う別クラス**として切り出す。理由は 2 つ:

- `PromptYaml` は `PromptYamlContractTest` / `DefensiveInstructionsPresenceTest` /
  `PromptDefenseWindowGateTest` からも共有されており、走査ヘルパを変えると 3 gate に波及する。
- `PromptYaml.php` と `PromptYamlContractTest.php` は
  `tests/Support/TemplateDivergence/adoption-debt.tsv` の**採用時債務**であり、
  内容を変えると突合 gate が `mutatedDebtPaths` で落ちる (3 択の判断が要る)。
  今回は「変えない」ので債務のまま据え置ける。

`PromptClientTimeoutInvariantTest.php` は指紋台帳のキーに在り、かつ**現在テンプレートと一致**
している (app 側 sha256 = `3e2cd834…` = 台帳の指紋)。書き換えると突合 gate が
「一致していた状態から新たに不一致になった、未登録かつ非債務のパス」として落とすので、
**同じ PR で乖離登録 (D50) と `LedgerPins::DIVERGENCE_ENTRY_COUNT` の 46 → 47** を行う。

## 制約・前提

- AGENTS.md「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」の適用対象である
  (走査ロジック・判定条件を変更する)。テストファーストで**先に赤くしてから**本体を書く /
  解決できない形を落とす分岐 / 走査が空振りしていないことの検査 /
  docblock に走査対象と保証しないものを書く、の 4 点を同じ変更に含める。
- AGENTS.md「静的検査 (gate) と走査器の共通規約」のうち適用されるのは
  (b) fail-closed / (c) 負例での裏取り / (d) 使わない収集を作らない の 3 条。
  (a) はクラス名解決を伴わないので無関係、(e) は語彙一致を判定しないので無関係。
- `phpstan.neon` の `paths` は `tests` を含まない。よって本設計の変更は
  **PHPStan level 10 の検査対象外**である (型注釈は書くが「level 10 が通っている」を
  「型が保証されている」と読み替えない)。
- 待ち予算の**値そのもの** (360 秒が妥当か) は本 feature の範囲外。決めるのは
  「書き忘れを機械で見つけること」だけである。
- 分母の実数は **5 本** (`example-summary` / `scenario-generation` / `sop-extract` /
  `sop-extract-media` / `work-decomposition`)。台帳の aicue セルの note は「4 本」と
  書いているが `sop-extract-media.yaml` (2026-08-19 追加) が漏れている。
  この分母のずれは実装時の `append_event` で台帳へ併せて報告する (設計では扱わない)。

## 正典要求 (5) の扱い: 該当構造なし

正典 v1 は spirux 形の上積みとして「宣言した値が実効でない経路を規約文書へ列挙し、
その記述が消えたら赤くなる検査を置く」を含む。aicue で該当構造の有無を実読で確認した:

- **別言語の読み取り側**: `resources/prompts` を読む PHP 以外の実装は無い
  (`*.ts` / `*.js` / `*.py` / `*.sh` の全数 grep で 0 件)。
- **まとめ送り (batch) 経路**: `app/Support/Llm/` / `app/Prompts/` に batch/pool 相当は 0 件。
- **実行経路は 1 本道**: 5 本すべてが factory → `PromptDefense` → `GuardedPrompt` →
  vendor (`Kent013\PrismPrompt\Prompt`) を通る。vendor 側 `resolveClientOptions()`
  (クラスプロパティ > YAML > 空) の結果が `asText()` / `asStructured()` の両方で
  `withClientOptions()` へ渡る。媒体つき経路 (`PromptDefense::loadWithMedia()`) も
  無名クラスのコンストラクタで `loadMetadata()` を呼ぶため metadata 解決は素の
  `TextPrompt` と同じである。
- `docs/architecture.md` は逆に「**YAML の値が `config/prism.php` の
  `request_timeout` (30s) を上書きして実効である**」と記載している。

よって **spirux 形の 2 経路に相当する構造は aicue に無く、本項は非該当**と結論する。
非該当のものについて文書の節と、その節を固定する検査を新設するのは
思考原則 2 (今必要なものだけ作る) に反するので**作らない**。
代わりに、読み取り器の docblock に**主張しないこと**を明記する:

- 宣言値が**実効値であること**は読み取り器は主張しない (見るのは宣言の有無と型と正負だけ)。
- 実効性が成り立つ前提は 3 つ — (i) `app/Prompts/` の factory が
  vendor の `$clientOptions` クラスプロパティを設定しないこと、
  (ii) `resources/prompts` を読む非 PHP の実装が無いこと、
  (iii) vendor の解決順序が「クラスプロパティ > YAML > config」であること。
  **この 3 つはこの検査では見ていない** (2026-08-24 に実読で確認した事実である)。
  どれかが崩れたら spirux 形 (文書への列挙 + 記述を固定する検査) を新設する。

## 採らない案 (検討して退けたもの)

- **`client_options` の読み取り箇所を目録化する gate**: 4 本目の実装の再流入を機械で
  止める案。退ける理由は、字句走査では「読み取り実装」と「失敗メッセージの中の
  `client_options.timeout` という文字列」を区別できず、区別のために目録へメッセージ側まで
  登録すると**保護しないのに更新が要る目録**になる (AGENTS.md (d) の「数えるだけで比べない
  目録」に近い形)。部分文字列一致で緩く書くと AGENTS.md (b)/(e) に反する。
  読み取り器の docblock が唯一の規則であることを宣言し、レビューで見る形に留める。
- **`PromptYaml::paths()` の再帰を負例で裏取りする**: `paths()` は
  `base_path('resources/prompts')` を直接見る (探索根を引数で受けない) ため、
  fixture ディレクトリを食わせられない。テスト中に `resources/prompts` の下へ
  一時ファイルを作る形は、同時に走る他の gate (`PromptYamlContractTest` /
  `DefensiveInstructionsPresenceTest` / `PromptDefenseWindowGateTest`) の分母を汚して
  レーンを不安定にするので採らない。`PromptYaml.php` が採用時債務であることもあり、
  **再帰の検出力は裏取りしない**ことを gate の docblock に明記する
  (aicue の `resources/prompts` は現在サブディレクトリを持たないため、実データからも
  再帰は証明できない)。
- **`AnalysisBudget::PROMPT_NAMES` に `sop-extract-media` を足す**: `PROMPT_NAMES` は
  `STAGE_COUNT = 3` と対の「解析パイプラインの 3 段」であり、OCR 変種を混ぜると
  `DEADLINE_SECONDS = 3C` の意味が崩れる。別物を「似ているから」で統合しない (思考原則 4)。

## スコープ外

- 決済 SDK / クラウド SDK / ファイル保存層の待ち上限の単一出典化
  (feature `external-client-timeout-pinning` = aicue:T126 の範囲。台帳が `distinct_from` を宣言)。
- キューのリース期間とジョブ制限時間の整合そのもの (`queue-lease-timeout-consistency`)。
- 待ち予算の**値**の変更 (360 秒 / 60 秒はいずれも据え置き)。
- prompt YAML の他の契約 (`max_tokens` / 防御文言 / モデル名) — 既存 gate の担当。
- `tests/Support/PromptYaml.php` の走査ロジックの改良 (採用時債務のため据え置き)。

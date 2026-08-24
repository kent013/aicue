# 概念設計レビュー Round 2

Round 1 の指摘への対応を反映した。対応マトリクスと修正後の概念設計を示す。
Critical 1 件・Warning 4 件はすべて「対応する」で処理し、設計本文を書き換えた。
2 点だけ指摘の文面をそのままではなく理由を添えて調整している (下記マトリクス参照):

- 到達証明は「包含 (⊇)」にした。完全一致 (=) だと新規 prompt の追加が到達証明で赤くなり、
  新規分の検査は再帰全数走査 (既定拒否) が既に受けているため二重になる。
- 実効性の記述は docblock と architecture.md へ**役割を分けて**書く (同じ文を 2 か所へ写さない)。
  AGENTS.md が「2 か所に書くと必ず食い違う」を明示的な規約にしているため。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] 分母の到達証明が固有名 1 本では不十分 (観点 4)
- 判断: 対応する
- 根拠: 指摘のとおり。到達証明の目的は「走査根の改名・移動で分母が別物になったこと」の検出だが、
  固有名 1 本の包含だけでは**部分欠落**を通す。走査ヘルパ (`PromptYaml::paths()`) は
  1 つのディレクトリを再帰するだけなので部分欠落は起きにくいが、
  「起きにくい」は検査の緩さを正当化しない。
- 対応内容: 概念設計 §改善アイデア 3 を書き換え、**既知の 5 本の相対パス集合を包含 (⊇)** する
  形にした。完全一致 (=) にはしない — 新規 prompt の追加を到達証明がブロックすると、
  「YAML を 1 本足したら関係ないテストが赤くなる」形になり、新規分の検査は再帰全数走査が
  既定拒否で受けているため二重になる。既知の 1 本の消失・改名では赤くなる。

## [Warning] `PromptWaitBudget` の責務境界と API が曖昧 (観点 3)
- 判断: 対応する
- 根拠: 「API が曖昧だと再び素の配列参照が生まれる」は、まさに今回直している事故
  (3 実装への分裂) の再発経路である。
- 対応内容: 概念設計 §実装方針に公開 API を 3 本明記した
  (`read()` / `evaluate()` / `requirePositive()`)。戻り値は
  `array{timeout: int|null, violations: list<string>}` に固定し、
  `requirePositive()` が int を返すことで**呼び出し側に `is_int` / `> 0` の再判定を残さない**
  ことを実装条件として書いた。parse は `PromptYaml::parseOrFail()` へ委譲し、
  parse 不能・非 map・ファイル不在も違反として返す (fail-closed)。

## [Warning] 実効性の前提と再判定条件を docblock だけに閉じ込めない (観点 5)
- 判断: 対応する
- 根拠: 「重要な運用判断を 1 か所の docblock に閉じ込めない」は妥当。ただし AGENTS.md は
  「2 か所に書くと必ず食い違う」ことを繰り返し警告しているので、**役割を分ける**形で対応する
  (docblock = 読み取り器が主張しないことの正本 / architecture.md = 実効性の運用契約と
  前提が崩れたときの導入条件)。同じ文を 2 か所へ写すことはしない。
- 対応内容: 施策 7 を「1 行の道標」から「3 行 (読み取り規則の正本 / 実効性は 3 前提に依存し
  機械では見ていない / 前提が崩れたら spirux 形の文書 pin を導入する)」へ拡張した。
  書き足す先は既に実効性を説明している `docs/architecture.md` §AI 解析ジョブの運用契約。

## [Warning] 台帳の分母記述 (4 本) の訂正を後送りしない (観点 6)
- 判断: 対応する (ただし設計フェーズでは台帳へ書き込まない)
- 根拠: 指摘のとおり、到達証明が 5 本を名指しするのに台帳が 4 本と書いている状態は不整合。
  ただし本タスクの制約として設計フェーズは lctl へ書き込まない (`append_event` 禁止)。
- 対応内容: 概念設計 §制約・前提に「**実装 PR の完了報告 (`append_event`) で台帳セルの
  note ごと訂正する** (D50 登録・指紋件数更新・到達証明の 5 本と同じ変更の中で辻褄を合わせる)」
  と明記した。

## [Warning] `tests` は PHPStan 対象外なので型注釈は保証にならない (観点 7)
- 判断: 対応する
- 根拠: 事実であり、設計が「PHPStan level 10 を通せる」を品質根拠にしてはいけない。
- 対応内容: §制約・前提に「型の代わりに効かせるのは**構造**である」旨を追記
  (厳密な array shape + 呼び出し側に解釈を残さない設計)。
  `tests` を PHPStan の対象に入れる作業は範囲外 (母集団が数百ファイル、独立 TODO 相当) と明記した。

## [Suggestion] 使命との整合 / 禁止事項 / ラベル集合照合 / 仕様値の二重化 / スコープ限定
- 判断: 見送る (現設計を維持)
- 根拠: いずれも現設計を肯定する指摘であり、変更は不要。

---

## 修正後の概念設計 (全文)

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
3. **分母の到達証明** — 走査結果の相対パス集合が、**既知の 5 本の固有名を包含する**ことを検査する
   (`example-summary.yaml` / `sop-extract.yaml` / `work-decomposition.yaml` /
   `scenario-generation.yaml` / `sop-extract-media.yaml`)。
   現状の `expect($files)->not->toBeEmpty()` は、走査根の改名・移動で分母が
   「別のディレクトリの 1 件」に化けても緑のままになる。
   **固有名 1 本の確認では足りない** — 分母が部分的に欠落してもその 1 本が残れば緑になるため、
   既知分母は集合として包含を要求する。
   包含 (⊇) にして完全一致 (=) にしないのは、**新規 prompt の追加を到達証明が
   ブロックしない**ためである (新規分は再帰全数走査そのものが既定拒否で受ける)。
   既知の 1 本が消えた・改名されたときは赤になり、意図した削除ならこの集合を同じ PR で直す。
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

読み取り器の API は 1 つだけ公開し、**これ以外の経路で `timeout` を解釈しない**ことを
実装条件にする (曖昧なままだと素の配列参照が再発する)。

- `PromptWaitBudget::read(string $absolutePath, string $label): array{timeout: int|null, violations: list<string>}`
  — 走査で得た絶対パス 1 本を読み、待ち予算と違反ラベルを返す。parse は
  `PromptYaml::parseOrFail()` へ委譲するので、**parse 不能・非 map・ファイル不在も
  違反として返る** (無言で `null` を返す形を作らない = fail-closed)。
- `PromptWaitBudget::evaluate(array<string, mixed> $parsed, string $label): array{timeout: int|null, violations: list<string>}`
  — 判定の純関数。`read()` の中身であり、自己テストの入口でもある。
- `PromptWaitBudget::requirePositive(string $absolutePath, string $label): int`
  — 違反が 1 件でもあれば `RuntimeException` を投げ、正なら値を返す。
  仕様値との突合 (`AnalysisBudget` / 2 つの budget gate) が使う口。
  **呼び出し側で `timeout` を再解釈しない** (`is_int` / `> 0` の判定を書かない)。

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
  「型が保証されている」と読み替えない)。型の代わりに効かせるのは
  **構造**である — 戻り値を厳密な array shape (`array{timeout: int|null, violations: list<string>}`)
  に固定し、`timeout` の解釈を呼び出し側に一切残さない (`requirePositive()` が
  int を返すので、呼び出し側に `is_int` / `> 0` の再判定が生まれない)。
  `tests` を PHPStan の対象へ入れる作業は本 feature の範囲外である
  (母集団が数百ファイルに及び、独立した TODO として追跡すべき規模である)。
- 待ち予算の**値そのもの** (360 秒が妥当か) は本 feature の範囲外。決めるのは
  「書き忘れを機械で見つけること」だけである。
- 分母の実数は **5 本** (`example-summary` / `scenario-generation` / `sop-extract` /
  `sop-extract-media` / `work-decomposition`)。台帳の aicue セルの note は「4 本」と
  書いているが `sop-extract-media.yaml` (2026-08-19 追加) が漏れている。
  この分母のずれは**実装 PR の完了報告 (`append_event`) で台帳セルの note ごと訂正する**
  (D50 の登録・指紋件数の更新・到達証明の 5 本と同じ変更の中で辻褄を合わせる。
  設計フェーズは台帳へ書き込まない)。

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

**重要な運用判断を読み取り器の docblock だけに閉じ込めない**。実効性の根拠 (vendor の
解決順序と `config/prism.php` を上書きすること) は既に `docs/architecture.md`
§AI 解析ジョブの運用契約 に書かれているので、同じ箇所へ
(i) 宣言の**読み取り規則の正本**は `Tests\Support\PromptWaitBudget` である、
(ii) 実効性は上の 3 前提に依存し**機械では見ていない**、
(iii) 前提が崩れたら spirux 形の文書 pin (経路の列挙 + 記述を固定する検査) を導入する、
の 3 行を足す (施策 7)。

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

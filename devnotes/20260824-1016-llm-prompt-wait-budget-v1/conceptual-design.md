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
2. **検出器の自己テスト (負例)** — 公開 2 口それぞれについて両方向を固定する。
   - `violations()`: 上記 9 類型が**ラベルの集合として全部上がること** +
     正例 1 本を誤検出しないこと。件数照合にしない
     (1 件取りこぼして別の 1 件を二重報告しても件数だけは合う = 偽の緑)。
   - `violations()` の解決不能形 3 種: **ファイル不在 / parse 不能 / top-level が非 map** を
     それぞれ別に赤くする (1 件だけ確かめて「解決不能形は落ちる」と主張しない)。
   - `requirePositive()`: 違反ありで `RuntimeException`、正常時は**正の整数を返す**こと。
     これで「下の層が集めた違反を上の口が無視する」形も塞がる。
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
| 3 | 検出器の自己テスト (`violations()` の負例 9 類型 + 正例 / 解決不能形 3 種 / `requirePositive()` の 2 方向) | `tests/Unit/Architecture/PromptWaitBudgetTest.php` (新規) + `tests/Architecture/fixtures/prompt-wait-budget/` (新規) |
| 4 | 下限式側の読み出しを寄せる | `tests/Support/AnalysisBudget.php` |
| 5 | 素の配列参照を寄せる | `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` |
| 6 | 乖離台帳への登録 | `docs/template-divergence.md` + `tests/Support/TemplateDivergence/LedgerPins.php` |
| 7 | 読み取り規則の正本への 1 行の道標 | `docs/architecture.md` |

### 読み取り器の公開契約

**保証の言い方を正確にする**: 「公開メソッドが 1 本」ではなく
「**待ち予算の妥当性を判定する実装が 1 クラスに 1 つ**」である。
公開するのは用途の違う 2 つの口だけで、**どちらも検査済みの結果しか返さない**
(未検査の `timeout` を外へ出す口を作らない = 第 4 の読み取り経路が生まれない)。

```php
/** 走査した 1 本の違反ラベル (gate 用)。違反が無ければ空配列。 */
public static function violations(string $absolutePath, string $label): array;
// @return list<string>

/** 仕様値との突合に使う正の整数 (違反が 1 件でもあれば RuntimeException)。 */
public static function requirePositive(string $absolutePath, string $label): int;
```

- 判定の純関数 `evaluate(array $parsed, string $label): array`
  (PHPDoc: `@param array<string, mixed> $parsed` /
  `@return array{timeout: int|null, violations: list<string>}`) は **private** にする。
  シグネチャは PHP のネイティブ型 (`array`) で書き、shape は直前の PHPDoc で宣言する
  (`array<string, mixed>` は型宣言として書けない)。
- 自己テストは公開 2 口を通して行う (private を露出させない)。9 類型は**見本ファイル**を
  読ませるので `evaluate()` を公開する必要が無い。
- `requirePositive()` は `violations()` と**同じ private 判定**を通る。
  よって「gate は落とすが突合は通る」食い違いが構造的に起きない。

### 失敗の分類 (fail-closed の契約)

`read` 相当の内部処理は次の 3 段で、**どの段でも無言で `null` を返さない**。

| 段 | 判定 | ラベル |
|---|---|---|
| 1 | `is_file()` が false | `{label}: ファイルが無い` (自前で 1 行判定する) |
| 2 | parse 不能 / top-level が map でない | `PromptYaml::parseOrFail()` が積む既存の 2 ラベル (`parse 失敗 (…)` / `top-level が連想配列(map)でない`) |
| 3 | 待ち予算の 5 類型 (未宣言 / 非配列 / キー無し / 非 int / <= 0) | `evaluate()` が積む |

- **自前の `catch` は書かない**。段 2 の例外捕捉は既存の共有ヘルパ
  (`PromptYaml::parseOrFail()` が `Throwable` を捕まえて違反へ変換する) の中で完結している。
  ここに `catch (Throwable)` を重ねると、テストコード自身のバグまで「契約違反」へ潰れる。
- ファイル不在を段 1 で**独立したラベル**にするのは、Symfony の `Yaml::parseFile()` が
  不在も `ParseException` にするため段 2 に混ぜると「構文が壊れている」と区別できなくなるからである
  (実装確認済み: `vendor/symfony/yaml/Yaml.php` の `@throws ParseException If the file could not be read`)。
  走査由来のパスでは不在は起きないが、`requirePositive()` は**名前から組んだパス**を
  受けるので不在は現実に起きる (prompt の改名)。
- vendor の例外メッセージの文面は pin しない (段 2 のラベルは「違反が 1 件以上あること」までを固定する)。

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

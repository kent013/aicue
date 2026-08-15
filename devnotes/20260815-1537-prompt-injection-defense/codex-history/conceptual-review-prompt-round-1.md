# 前提: アプリの使命・禁止事項・思考原則

## 使命 (North Star) — AGENTS.md より
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より
## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
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

# system: あなたの役割

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【補足コンテキスト】
- 本件は複数リポジトリ共有の機能台帳 (lctl) の裁定 AG-028「プロンプトインジェクション防御の標準形 t1 に 5 リポジトリを揃える」の aicue 側追従である。標準形 t1 = 雛形検査 3 本 + 操作単位ガードレール + 窓口方式一式 (窓口クラス / 窓口通過の構造検査 gate / 入力の無害化 / 応答カナリア / 防御設定の集約ファイル)。
- 裁定は「窓口方式を持つなら雛形検査は不要、という但し書きは付けない」と明記している (雛形の書き漏れと経路の迂回は別の失敗)。
- ただし裁定は「検査は名前ではなく保証内容で揃える。同じ保証を与える実装であれば形は各自の方式に合わせてよい」とも明記している。
- 提供元 (laravel-claude-template) の窓口は untrusted / trusted の 2 引数を持つが、本設計は aicue に trusted 変数が 0 件であることを理由に trusted の入口を作らない判断をしている。この判断の妥当性も評価してほしい。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: prompt-injection-defense (窓口方式一式の追従)

- 台帳 feature: `prompt-injection-defense` (裁定 AG-028。標準形 t1)
- aicue の現在地: `t0` = 雛形検査 3 本 + Prism 直呼び禁止。窓口方式一式は 0 件
- 参照した正典: lctl `get_feature(prompt-injection-defense)` の
  laravel-claude-template セル (t1 の要素 a〜j) と spirux セル (窓口方式の提供元)

## 背景・課題

### なぜ aicue で最も重いのか

家系 6 リポジトリのうち、**実運用の LLM 解析パイプラインを持つのは aicue だけ**である。
`app/Services/Manual/AnalysisPipeline.php` が 4 段 (抽出 → 分解 → 生成 → 反映) を
1 ジョブで終端まで走らせ、その入口は**利用者がアップロードした作業手順書 (SOP)** という
完全な外部由来の文字列である。しかも成果物は「現場作業者が観て真似する教材動画」なので、
乗っ取られた出力は**現場の作業そのもの**に反映される (例: 安全上の急所カットを落とさせる)。

### 4 段のどこに外部由来の文字列が入るか (実読)

| 段 | 実装 | 外部由来の文字列 | 現在の防御 |
|----|------|------------------|-----------|
| 抽出 (extract) | `SopExtractPrompt::make($text->text, $context)` | `SopTextExtractor` が PDF/xlsx/txt から取り出した SOP 本文 | `UserInput::from()` + YAML の防御指示 |
| 分解 (decompose) | `WorkDecompositionPrompt::make($extracted->toJsonString(), $context)` | 1 段目の LLM 出力 (= SOP 由来 + モデル生成) | 同上 |
| 生成 (generate) | `ScenarioGenerationPrompt::make($decomposition->toJsonString(), $context)` | 2 段目の LLM 出力 | 同上 |
| 反映 (materialize) | `ScenarioBookendBuilder::wrap()` → `ScenarioService::materializeIntoLockedManual()` | 3 段目の LLM 出力 (カット行として DB へ) | LLM 呼び出しなし (DTO スキーマ検証のみ) |

`app/` 全体で `executeSync()` を呼ぶ箇所はこの 3 箇所だけで、いずれも
`app/Prompts/` の factory 経由である (実読で確認済み)。

### 既にあるもの (壊さずに積む)

1. `AGENTS.md` セキュリティ不変条件 4「untrusted 文字列は UserInput 型経由でのみ prompt に入れる」
2. 禁止事項 5「Prism 直呼び禁止 (`app/Prompts/` の factory 経由のみ)」— `PromptGuardrailTest`
3. `PromptUntrustedInputContractTest` の inventory (deny-by-default + 型 + 帰属)
4. `DefensiveInstructionsPresenceTest` / `PromptYamlContractTest` / `PromptClientTimeoutInvariantTest`
5. prompt 文字列の `resources/prompts/*.yaml` 外出し

### それでも残っている穴 (これが今回の対象)

- **(穴 1) 無害化が無い**。`UserInput` はタグ境界化と breakout エスケープだけを行い、
  制御文字・不可視文字・双方向制御文字 (U+202E 等) を素通しする。SOP は PDF/xlsx から
  抽出したテキストなので、これらは**正常な経路で普通に混入しうる**。
- **(穴 2) 「UserInput で渡す」が書き手の規律**であって構造ではない。inventory は
  「未分類なら fail」だが、新しい factory を**変数リスト空**で登録して生 string を
  `Prompt::load()` に渡す経路は現行 gate をすべて通過する (実装を読んで確認)。
  経路が増えたときの迂回に対して deny-by-default になっていない。
- **(穴 3) 応答の検査が無い**。モデルが乗っ取られてシステムプロンプトを吐いても、
  出力が JSON として妥当なら DTO 検証を通ってしまう。「乗っ取られた」という事実が
  どこにも観測されない (失敗しても `invalid_json` に見えるだけ)。
- **(穴 4) 防御パラメータの置き場が無い**。長さ上限は `config/manual.php` の
  `analysis_max_text_bytes` (SOP 経路の運用ポリシー) しか無く、
  「防御としての最後の砦」を置く場所が無い。

## 改善アイデア — 窓口方式一式

**1 本の窓口を通らないと LLM に文字列を渡せない形**にする。窓口の内側で
「無害化 → タグ境界化 → カナリア合流」を行い、実行単位が「vendor 実行 → 応答検査」を
1 メソッドに束ねて fail-closed にする。構造検査 gate が「窓口以外から vendor の prompt を
読み込めない」ことを機械的に固定する。

```
app/Prompts/*Prompt.php            ← 各 prompt の factory (YAML 名と変数名を宣言するだけ)
        │  PromptDefense::load(name, untrusted: [...])   ← ここ以外から vendor prompt へ到達できない
        ▼
app/Support/Llm/PromptDefense.php  ← 窓口 (無害化 → UserInput 化 → カナリア合流 → 実行単位を返す)
        │
        ▼
app/Support/Llm/GuardedPrompt.php  ← 実行単位 (executeSync = vendor 実行 + 応答検査。fail-closed)
```

### 足すもの (差分だけ)

| # | 施策 | 正典 t1 の対応要素 |
|---|------|--------------------|
| A | 窓口クラス `App\Support\Llm\PromptDefense` | (a) 窓口クラス |
| B | 実行単位 `App\Support\Llm\GuardedPrompt` (応答検査を束ねる) | (b) 実行単位 |
| C | 無害化 `App\Support\Llm\UntrustedTextSanitizer` (制御文字・不可視文字・長さ) | (c) 入力の無害化 |
| D | 応答カナリア `App\Support\Llm\PromptCanary` + YAML への slot 追加 | (d) 応答カナリア |
| E | `config/llm-defense.php` (防御パラメータの集約。env なし) | (e) 防御設定の集約 |
| F | 窓口通過の構造検査 gate `PromptDefenseWindowGateTest` | (f) 構造検査 gate |
| G | 集約設定の gate `LlmDefenseConfigGateTest` | (g) 設定 gate |
| H | 既存 3 gate の射程更新 (置き換えない・保証を縮めない) | (h)(i) 雛形検査 / 操作単位ガードレール |
| I | 実行時の振る舞いテスト + 攻撃コーパス | (j) 実行時テスト |
| J | 規約文書の更新 (`AGENTS.md` / `docs/architecture.md` / `docs/template-divergence.md`) | — |

### 二重に守らないための整理 (思考原則 2・4)

- **「untrusted は UserInput 型」の宣言を factory から窓口へ 1 本化する**。
  窓口の引数型が「生 string の連想配列」なので、factory 側は `UserInput` を
  **作れないし作る必要もない**。同じ不変条件を 2 箇所で宣言する状態を作らない。
- **`PromptUntrustedInputContractTest` は消さずに役割を純化する** (裁定は
  「窓口方式を持つなら雛形検査は不要という但し書きは付けない」)。分類 (deny-by-default) と
  帰属 (`llm_call_logs` の organization / subject) は aicue 固有の資産なのでそのまま残し、
  型検査は「窓口を通した**結果**が `UserInput` になっていること」の behavioral 確認に読み替える
  (宣言は窓口、確認は inventory、構造は窓口 gate — 3 者の役割が重ならない)。
- **長さ上限を 2 つ持つが、母集団が違う**ことを明示し機械で固定する。
  `manual.analysis_max_text_bytes` (150,000) は **SOP 経路の運用ポリシー**で
  ユーザー向け文言 (「分割してアップロードしてください」) を伴う。窓口の上限は
  **全 untrusted 値 (2・3 段目の中間 JSON も含む) にかかる構造的な最後の砦**である。
  「窓口上限 >= SOP 上限」を gate で pin し、SOP 経路では必ず先に運用ポリシー側の
  文言が出る (窓口が先に落として文言が退化することが無い) ようにする。

### aicue 固有の設計判断

1. **trusted 変数の入口を作らない**。テンプレートの窓口は `untrusted` と `trusted` の
   2 引数を持ち、「trusted の値はリテラル / クラス定数 / enum case のみ」という字句 gate で
   守っている。aicue の prompt YAML の変数は**現在 4 本すべてが untrusted** で trusted は 0 件。
   入口そのものを作らなければ「trusted に混ぜて素通しする」経路は**構造的に存在しない**
   (テンプレートより強い側への逸脱)。必要になった時点で引数と字句 gate を同時に足す。
   `docs/template-divergence.md` に理由と「保証し続ける不変条件」を登録する。
2. **カナリア漏洩は再試行しない**。`AnalysisPipeline::isTransient()` は deny-by-default なので
   新例外は自動的に非 retryable になる。ユーザー向け文言だけを追加し、
   「入力を変えずに再実行しても同じ」ことが伝わる文にする。
3. **4 段目 (反映) は窓口の対象外**。LLM を呼ばないため。ただし反映で DB へ入る文字列は
   すべて LLM 出力由来 = untrusted であり、**それを再び prompt へ戻す経路を作らない**ことを
   規約として書く (会話履歴を prompt に入れる形へ進化したら窓口の対象に含める、という
   AG-028 の監視条件の aicue 版)。窓口の引数が生 string なので、戻す経路を作ったとしても
   窓口を通るしかない = 規約を忘れても無害化とタグ境界化は効く。

## 期待効果

- **使命への貢献**: AI-CUE は「現場にある SOP を起点に AI が教材を設計する」製品である。
  起点の SOP は常に外部由来なので、そこに紛れ込ませた命令でシナリオ生成が乗っ取られると、
  **安全の急所カットが落ちた教材**が現場に配られうる。防御は使命の前提条件であり、
  「思考ゼロ・編集ゼロ」で作業者が結果をそのまま信じる製品では特に外せない。
- **経路が増えたときの迂回を構造で塞ぐ**: 新しい prompt を足す人が規約を知らなくても、
  窓口を通らないと LLM に到達できない (gate が赤くなる)。
- **家系の統一**: 裁定 AG-028 の t1 に到達し、テンプレート更新の取り込みが素直になる。

## 実装方針 (概要)

1. `config/llm-defense.php` を新設 (キーは 2 つ: `max_untrusted_bytes` / `canary_bytes`。
   文言も on/off スイッチも env も置かない)。
2. `UntrustedTextSanitizer` を新設。**構造だけ**を扱う (制御文字・不可視文字・双方向制御文字の
   除去、長さ超過の拒否)。**指示文らしい文言の除去はしない** (偽陰性と回避のいたちごっこ)。
   改行・タブは SOP の本文構造なので保持する。
3. `PromptCanary` を新設 (乱数 hex。応答に現れたら system prompt 漏洩とみなす。
   照合は大小無視 + 空白除去の 2 パス)。
4. `PromptDefense::load(string $template, array $untrusted): GuardedPrompt` を新設。
   内側で 無害化 → `UserInput::from()` → カナリア変数の合流 → `Prompt::load()` を行う。
5. `GuardedPrompt` を新設。`executeSync(): string` が vendor 実行 → カナリア照合 →
   漏洩なら `PromptResponseRejectedException` を投げて**応答を呼び出し元へ渡さない**。
   vendor prompt 型を返す public メソッドを持たない (迂回経路を構造的に消す)。
6. `app/Prompts/` の 4 factory を窓口経由へ書き換え、戻り値型を `GuardedPrompt` にする
   (`AnalysisPipeline` の 3 箇所は `->executeSync()` のままで型だけが変わる)。
   **旧経路 (`Prompt::load()` 直呼び) は同じ PR で全廃**する (思考原則 3)。
7. 4 つの YAML の `system_prompt` にカナリア slot を足す (`prompt` 側には**置かない**)。
8. gate 群 (F/G/H) とテスト (I) を追加・更新する。
9. 文書 (J) を更新する。

## 制約・前提

- vendor (`kent013/laravel-prism-prompt`) の `Prompt::load()` は
  `templateVariables` を `system_prompt` と `prompt` の両方の Blade 描画に使う
  (実読で確認)。カナリア変数を system 側だけで参照する形が成立する。
- `Prompt::fake()` / `CannedPromptResponses` は system prompt の**役割文 (signature)** で
  canned を引くため、カナリア (乱数) の混入で解決が壊れない (signature は YAML 固有句)。
- prompt キャッシュ (`cacheBreakpoints`) は 4 YAML とも未使用なので、
  呼び出しごとに変わるカナリアがキャッシュヒット率を壊す問題は起きない。
- `ExternalSeamInventory::delegations()` が `PrismDirectDispatchScanner::scannedFiles()` を
  生存確認に使っているため、scanner に手を入れるときは委譲側の前提を壊さない。
- テストレーンは外部 HTTP / LLM を既定拒否 (`StrayHttpRequestGuard` / `StrayLlmCallGuard`)。
  新規テストは `Prompt::fake()` で閉じる。

## スコープ外

- ffmpeg の字幕描画 (drawtext) へ流れる文字列の扱い — 層が違う (レンダ側の別テーマ)。
- MCP の write tool (現在 0 本) と会話履歴を持つ対話面 (aicue に存在しない)。
- 文言ベースのインジェクション検出 (「ignore previous instructions」等の語句フィルタ)。
- LLM 出力の内容審査 (「この手順は安全か」の判定)。
- prompt の多言語化・モデル変更・費用最適化。

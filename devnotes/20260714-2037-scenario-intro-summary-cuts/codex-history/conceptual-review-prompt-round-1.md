【アプリの使命 (North Star) — AGENTS.md より】

AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. response()->json() の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び（app/Prompts/ の factory 経由のみ）
6. prompt 文字列のコード直書き（resources/prompts/*.yaml に置く）
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI

【ドメイン規約 1（シナリオ整合の共有ロック規約）】
cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する。経路 inventory は ScenarioWritePathInventoryTest（Architecture テスト）へ昇格済み＝新しい書き込み経路は inventory 登録が必須。

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。今必要なものだけ作る（オーバーエンジニアリング禁止）。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション（Laravel 12 + Svelte 5 + Inertia）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か
4. 期待効果の妥当性
5. リスク: 重大な副作用・後退の可能性
6. スコープの適切さ: 過大または過小になっていないか。特に本設計は「v1 データモデル(step/point)を変えずに materialize 時にサーバ側で決定的に導入/総括カットを前後挿入する最小実装」とし、独立 CutType 化・エディタ専用ラベルをスコープ外としている。この scope 判断（doc/10 §10.1/§10.4 が CutType を step/point に意図的限定 vs doc/03 §3.5・doc/06 §6.3 の導入/総括自動挿入の設計思想）が妥当かを重点的に評価せよ。
7. 型安全性: DTO/JsonResource パターン、PHPStan level 10

【本設計固有の論点（重点評価）】
A. サーバ側決定的挿入 vs プロンプト指示（LLM 生成）の選択は妥当か。テスト可能性・スキーマ非破壊を根拠にサーバ挿入を選んでいる。
B. 導入/総括を独立 CutType にせず通常の step カットで表現する判断は、使命（機能の名前に立ち返れ = 導入/総括は手順ではない）と過剰実装回避のトレードオフとして妥当か。編集画面で「手順1/手順N」と表示される v1 許容は妥当か。
C. materialize 前に list を拡張する方式が共有ロック規約（ドメイン規約 1）・ScenarioWritePathInventoryTest に波及しないという主張は正しいか。
D. 定型文面（タイトル補間）が「作業目的の要約」という導入カットの役割を v1 で十分に満たすか。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は devnotes/20260714-2037-scenario-intro-summary-cuts/conceptual-design.md の全文）

（レビュアーは同ファイルおよび関連コードを読んでよい: resources/prompts/scenario-generation.yaml, app/DataTransferObjects/Manual/Analysis/GeneratedScenarioData.php, app/Services/Manual/AnalysisPipeline.php, app/Services/Manual/ScenarioService.php, app/Enums/Manual/CutType.php, doc/03_AI解析とシナリオ生成.md §3.5, doc/06_撮影シナリオの設計思想.md §6.3/§6.5, doc/10_実装仕様.md §10.1/§10.4）

# 概念設計: scenario-intro-summary-cuts（AIシナリオ生成の導入カット/総括カット自動挿入）

## 背景・課題

ユースケース・カバレッジ監査ギャップ #1（High）。

- `doc/03_AI解析とシナリオ生成.md` §3.5 は「生成シナリオの冒頭に**導入カット**（作業目的の要約・俯瞰ヒキ）、末尾に**総括カット**（要点・安全ポイント再掲・俯瞰ヒキ）を自動で挿入している」と記述。
- `doc/06_撮影シナリオの設計思想.md` §6.3 は設計思想→プロンプト実装の対応表で「作業の入り口説明 = 冒頭に導入カット（作業目的・設備と立ち位置）を自動挿入」と明記。

### 現状確認の結論（実装済みか？ → 未実装）

現状の生成経路を精読した結果、**導入/総括カットを生成・挿入するロジックは存在しない**:

- `resources/prompts/scenario-generation.yaml`: 出力スキーマは `type: "step"|"point"` のみ。導入/総括の指示なし。
- `app/DataTransferObjects/Manual/Analysis/GeneratedScenarioData.php`: `type` が `step`/`point` 以外なら `LlmOutputInvalidException`。導入/総括は表現できない。
- `app/Services/Manual/AnalysisPipeline.php` / `ScenarioService::materializeIntoLockedManual()`: LLM 出力の step/point ツリーをそのまま materialize するのみ。前後への定型カット挿入なし。
- grep（導入/総括/intro/summary）ヒットなし。

→ **LLM 創発でも実装済みでもない**。プロンプトは step/point しか出さない設計であり、導入/総括は構造的に生成されない。

### v1 スコープとの関係（重要）

v1 実装仕様 `doc/10_実装仕様.md` は着手前設計レビュー反映済み（§10.8 が §10.1〜§10.7 に優先）であり、その §10.1（データモデル）と §10.4（scenario-generation プロンプト）は **`CutType` を `step`(手順) / `point`(急所) の 2 値に意図的に限定**している。導入/総括を**独立した第 3 のカット種別**として持つ設計は v1 に含まれていない。

したがって本改善は「導入/総括を**独立 CutType + 専用エディタ UI で第一級表現する**」フル実装ではなく、**v1 データモデル（step/point）を変えずに、生成物の前後へ定型の導入/総括カットを決定的に挿入する**最小実装として設計する（過剰実装回避 = 思考原則「今必要なものだけ作る」）。独立 CutType 化・エディタでの「導入/総括」ラベル表示・LLM による作業固有文面生成は**スコープ外**（doc/06 §6.5「今後の検討」の系譜）。

## 改善アイデア

**materialize 時にサーバ側で決定的に導入/総括カットを前後挿入する。**

- LLM（scenario-generation プロンプト）は現状どおり step/point のみを生成する（**プロンプト・出力スキーマ・canned 応答の signature を変更しない** = 有界リトライ検証・Browser lane の `CannedPromptResponses` 1:1 signature を壊さない）。
- AnalysisPipeline が `GeneratedScenarioData::toScenarioSteps()` の list に対し、**先頭へ導入カット・末尾へ総括カット**を付与してから `materializeIntoLockedManual()` に渡す。
- 導入/総括は既存 `CutType::Step`・`ShotType::Hiki` の**通常のトップレベルカット**として表現する（新 enum・新カラム・新 DTO なし）。
- 文面は**定型テンプレート**で、対象マニュアルのタイトル（作業名）を補間して作業文脈を持たせる（例: 導入ナレーション「この動画では『{作業名}』の手順と注意点を示します。」／総括「以上で作業は完了です。要点と安全のポイントを振り返りましょう。」）。字幕②（言い切り）・字幕①も定型で用意する。
- `sort_order` は materialize が list index から採番するため、list の先頭/末尾に積むだけで整合する。

### なぜプロンプト指示ではなくサーバ挿入か

- **決定性・テスト可能性**: 禁止事項「テストなしの実装完了」を満たすには、導入/総括の存在を Feature テストで確定的に検証できる必要がある。LLM 依存（プロンプト指示のみ）では canned 応答に埋め込む＝fake を検証するに留まり、挙動を保証できない。サーバ挿入なら LLM 出力に関わらず前後カットの存在・順序・値を確定できる。
- **スキーマ非破壊**: プロンプト/出力スキーマ不変のため、DTO 検証・有界リトライ・`AnalysisTokenBudgetInvariantTest`・Browser lane canned signature に一切波及しない。
- 作業固有の高品質な導入文面（LLM 生成）は将来改善余地として残す（v1 は定型 + タイトル補間で十分に「作業の入り口説明」を満たす）。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」で標準化されたマニュアル動画を作る、という North Star に対し、生成シナリオが常に「作業目的の提示 → 手順/急所 → 総括」の教材構造（原因→結果の映像文法 / SECI の形式知化）を備える。撮影者は導入/総括の要否を判断せず、生成物がそのまま教材の型になる。
- 字幕のみ v1 でも、レンダ動画の冒頭俯瞰＋字幕で「何の作業か」、末尾俯瞰＋字幕で「要点再掲」が必ず入る。
- 決定的挿入のため、どの SOP からでも導入/総括が欠落しない（品質のばらつきを構造的に排除）。

## 実装方針（概要）

1. **定型文面の定義**: 導入/総括の narration・subtitle_primary・subtitle_secondary・shot_type(hiki)・scene を config（`config/manual.php`）＋ i18n lang ファイルで定義（プロンプト文字列ではなく DB へ入るコンテンツのため `resources/prompts` 対象外。タイトル補間値は SOP 由来 = untrusted だが LLM prompt へは入れない＝`UserInput` 不要。`ScenarioLimits` の各文字数上限に収まるようタイトルを truncate）。
2. **挿入ロジック**: `AnalysisPipeline`（生成関心）に導入/総括カットを組み立てるヘルパ（または専用 Builder）を追加し、`toScenarioSteps()` の list を `[導入] + steps + [総括]` に拡張して `materializeIntoLockedManual()` へ渡す。`ScenarioService::materializeIntoLockedManual()` は「渡された list を materialize する」汎用のまま（手動保存 `save()` 経路には一切触れない）。
3. **共有ロック規約遵守**: 新規の書き込み経路は増えない。挿入後の list は既存の terminal tx（`finalize` の `lockForUpdate()` 済み manual）内で materialize されるため、シナリオ整合の共有ロック規約（ドメイン規約 1）・`ScenarioWritePathInventoryTest` に波及なし。
4. **編集との整合**: materialize 後、導入/総括は通常のトップレベル step カットとして手動編集 `save()` で round-trip（保持・編集・削除可能）。v1 の許容挙動として明示（編集画面では「手順1/手順N」として表示される。専用ラベルはスコープ外）。

## 制約・前提

- v1 スコープ（字幕のみ / TTS 後回し / ffmpeg 合成 / 単一 Default Project）を尊重。
- `CutType` は step/point のまま（doc/10 §10.1 の意図的限定を維持）。
- 定型文面はマニュアルのタイトル（作業名）に依存。タイトル未設定時のフォールバック文面を用意する。

## スコープ外

- 導入/総括を独立した `CutType`（例 Intro/Summary）として表現すること、およびそれに伴う DTO / JsonResource / `ScenarioEditor.svelte` / `CutNavigator.svelte` / TS 型 / 手動保存 payload への波及。
- LLM による作業固有の導入/総括文面生成（v1 は定型 + タイトル補間）。
- エディタ/撮影ナビでの「導入/総括」専用ラベル・並べ替え禁止・削除禁止などの特別扱い。
- TTS ナレーション音声化（v1 は字幕のみ）。
- doc/03 例に出る「本編の分割表示」等の追加演出。


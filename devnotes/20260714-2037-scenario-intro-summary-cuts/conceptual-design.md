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
- 文面は**定型テンプレート**で、対象マニュアルのタイトル（作業名）を補間して作業文脈を持たせる（導入ナレーション例「この動画では『{作業名}』の手順と注意点を示します。」）。
- **総括カットは決定的な「要点再掲」を含める（Codex Critical D 反映）**: 総括の `subtitle_secondary` は、**今回生成された `ScenarioStepInput` リスト（`GeneratedScenarioData::toScenarioSteps()` の戻り値。DB の既存 cuts は参照しない）**から決定的に抽出した要点の連結とする（LLM 非依存）。抽出規則を確定する:
  - 走査順（Codex R3 反映）: (i) まず steps を先頭から深さ優先で辿り、各 step 配下の point（`ScenarioPointInput`）の `subtitlePrimary` のうち非空を順に収集。(ii) point 由来が 0 件なら、トップレベル step の `subtitlePrimary` 非空を同じ規則で収集（要点再掲を可能な限り保証）。(iii) いずれも 0 件のときのみ定型フォールバック。`scene` は撮影指示のため再掲元に使わない。
  - 件数: 先頭 `N` 件（`N = config()->integer('manual.summary_recap_max_points')`、既定 3。正整数を保証する typed accessor 経由）。
  - 区切り: 「／」で連結。全体が `ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS` を超える場合はまず件数を減らして収める。1 件だけでも上限超過するときは、最後に文字単位で `ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS` に truncate（末尾省略）。
  - フォールバック: (i)(ii) いずれも非空 `subtitle_primary` が 0 件のときは「作業名＋完了確認」の定型総括にフォールバックする。フォールバック文面と再掲あり文面は別 lang キーで区別する。
- **文面確定は terminal tx 内・今回生成 DTO から行う（Codex Warning C / R2 反映）**: `AnalysisPipeline::finalize` の terminal tx 内（`lockForUpdate()` 済み VideoManual）で、builder に **locked manual（タイトル取得用）と今回生成の `list<ScenarioStepInput>`** を渡して導入/総括を組み立てる。DB の既存 cuts は参照しない（再生成時に旧シナリオを総括する事故を構造的に防ぐ）。
- **責務の一本化（Codex R2 反映）**: terminal tx 内で `ScenarioBookendBuilder::wrap($lockedManual, $generatedSteps): list<ScenarioStepInput>` を呼び、`[導入] + $generatedSteps + [総括]` を返す。その戻り値を既存 `materializeIntoLockedManual()` にそのまま渡す。`AnalysisPipeline` は呼び出し位置（terminal tx 内）を提供し、`ScenarioBookendBuilder` が文面組み立てを担い、`ScenarioService::materializeIntoLockedManual()` は「渡された list を materialize する」汎用のまま。3 者の責務が重複しない。
- `sort_order` は materialize が list index から採番するため、list の先頭/末尾に積むだけで整合する。
- **型付き builder（Codex Warning 反映）**: `ScenarioBookendBuilder` は既存の `ScenarioStepInput`（typed DTO）のみを生成・返却し、`array<string,mixed>` の直組み（第 2 の非型付けシナリオ生成経路）を作らない。lang からの文面取得は `string` を保証する typed accessor 経由（PHPStan L10）。

### なぜプロンプト指示ではなくサーバ挿入か

- **決定性・テスト可能性**: 禁止事項「テストなしの実装完了」を満たすには、導入/総括の存在を Feature テストで確定的に検証できる必要がある。LLM 依存（プロンプト指示のみ）では canned 応答に埋め込む＝fake を検証するに留まり、挙動を保証できない。サーバ挿入なら LLM 出力に関わらず前後カットの存在・順序・値を確定できる。
- **スキーマ非破壊**: プロンプト/出力スキーマ不変のため、DTO 検証・有界リトライ・`AnalysisTokenBudgetInvariantTest`・Browser lane canned signature に一切波及しない。
- 作業固有の高品質な導入文面（LLM 生成）は将来改善余地として残す（v1 は定型 + タイトル補間で十分に「作業の入り口説明」を満たす）。

## 期待効果（v1 の範囲を正確に限定 — Codex Critical B/D 反映）

- **教材構造の補強**: 生成シナリオが常に「作業目的の提示（導入俯瞰）→ 手順/急所 → 要点再掲（総括俯瞰）」の教材構造（原因→結果の映像文法 / SECI の形式知化）を備える。決定的挿入のため、どの SOP からでも導入/総括が欠落しない（品質のばらつきを構造的に排除）。
- 字幕のみ v1 でも、レンダ動画の**冒頭俯瞰＋字幕**で「何の作業か」が必ず入る。**末尾俯瞰＋字幕**は、生成内容に再掲可能な要点（point もしくは step の `subtitle_primary`）がある場合に「要点再掲」を、無い稀なケースは定型の完了締めを入れる。これが本 v1 実装が主張する価値である。
- **主張しないこと（過大評価の回避）**: 撮影ナビ/編集画面の手順番号 UX の改善は主張しない。導入/総括は内部的に step カットのため、編集画面・撮影ナビでは通常の「手順」として番号採番される（後述の「既知の v1 限界」）。導入は「作業名提示＋俯瞰」までを v1 要件とし、doc/06 §6.3 の「設備と立ち位置」までの作業固有描写は v1 要件外（LLM 生成が要るため後続）。

## 実装方針（概要）

1. **定型文面の定義（責務分離 — Codex R2 Suggestion）**: 文面テキスト（narration・subtitle・scene の定型/フォールバック）は **i18n lang ファイル**に置く。構造値（要点再掲件数 N、タイトル truncate 長など）は **config（`config/manual.php`）**に置き、`config()->integer(...)` 等の**正整数を保証する typed accessor** で読む（PHPStan L10）。lang 取得も `string` 確定の typed accessor 経由。プロンプト文字列ではなく DB へ入るコンテンツのため `resources/prompts` 対象外。タイトル補間値は SOP 由来 = untrusted だが LLM prompt へは入れない＝`UserInput` 不要。`ScenarioLimits` の各文字数上限に収まるようタイトルを truncate。
2. **挿入ロジック**: `AnalysisPipeline`（生成関心）に導入/総括カットを組み立てるヘルパ（または専用 Builder）を追加し、`toScenarioSteps()` の list を `[導入] + steps + [総括]` に拡張して `materializeIntoLockedManual()` へ渡す。`ScenarioService::materializeIntoLockedManual()` は「渡された list を materialize する」汎用のまま（手動保存 `save()` 経路には一切触れない）。
3. **共有ロック規約遵守**: 新規の書き込み経路は増えない。挿入後の list は既存の terminal tx（`finalize` の `lockForUpdate()` 済み manual）内で materialize されるため、シナリオ整合の共有ロック規約（ドメイン規約 1）・`ScenarioWritePathInventoryTest` に波及なし。
4. **編集との整合**: materialize 後、導入/総括は通常のトップレベル step カットとして手動編集 `save()` で round-trip（保持・編集・削除可能）。v1 の許容挙動として明示（編集画面では「手順1/手順N」として表示される。専用ラベルはスコープ外）。

## 不変条件（テストで固定 — 禁止事項1 / Codex Warning 反映）

- materialize 直後、当該 manual のトップレベル（parent_cut_id=null）カットは **先頭 1 件が導入・末尾 1 件が総括**であり、その間に LLM 生成 step 群が入る。**重複しない・順序固定**。
- **識別の検証方法（Codex R2 反映）**: DB 上は通常 step と区別できないため、Feature テストは `ScenarioBookendBuilder` が返す DTO のフィールド値と位置（先頭/末尾）を検証する。ハードコードした翻訳文字列との照合ではなく、builder が用いる lang キー/定数と同じ値で照合し文言変更耐性を持たせる。恒久的な識別子（型・列）は独立型導入時の後続課題。
- 検証ケース（Feature）: (1) 初回生成、(2) 再生成（materialize は全置換のため重複しない）、(3) 手動 `save()` 後の再生成、(4) point 不在時のフォールバック総括。
- `ScenarioService::save()`（手動保存経路）は本改善で一切変更しない。手動編集後は導入/総括は通常 step として round-trip（保持・編集・削除可能）する v1 挙動。

## 既知の v1 限界（Codex Critical B 反映）

- 導入/総括を独立表現しないため、編集画面・撮影ナビでは通常の「手順」として採番され、実手順の番号が 1 つ後ろにズレる。v1 では許容し、専用ラベル/独立 CutType による解消を後続（スコープ外）とする。

## 制約・前提

- v1 スコープ（字幕のみ / TTS 後回し / ffmpeg 合成 / 単一 Default Project）を尊重。
- `CutType` は step/point のまま（doc/10 §10.1 の意図的限定を維持）。
- 定型文面はマニュアルのタイトル（作業名）に依存。タイトル未設定時のフォールバック文面を用意する。
- タイトル補間・再掲元カットの読み取りは terminal tx（locked manual）内で行う。

## スコープ外

- 導入/総括を独立した `CutType`（例 Intro/Summary）として表現すること、およびそれに伴う DTO / JsonResource / `ScenarioEditor.svelte` / `CutNavigator.svelte` / TS 型 / 手動保存 payload への波及。
- LLM による作業固有の導入/総括文面生成（v1 は定型 + タイトル補間）。
- エディタ/撮影ナビでの「導入/総括」専用ラベル・並べ替え禁止・削除禁止などの特別扱い。
- TTS ナレーション音声化（v1 は字幕のみ）。
- doc/03 例に出る「本編の分割表示」等の追加演出。

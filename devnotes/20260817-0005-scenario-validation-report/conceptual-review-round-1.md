全体判定: **CHANGES_REQUESTED**

**使命との整合性**
[Suggestion] 方針は North Star に合っている。生成後に「どこを確認すべきか」を示すのは、SOP 起点の教材設計と撮影品質の標準化に寄与する。特に判定を制御フローに使わず、撮影・保存を止めない点は妥当。

**禁止事項違反**
[Warning] 「費用増はゼロ」は言い過ぎ。LLM 呼び出し回数とチケット消費は増えないが、入力・出力 token は増えるため実費は微増し得る。  
修正提案: 「追加 LLM 呼び出しはゼロ」「チケット消費は据え置き」「token 増分は小さい見込み」と表現を分ける。

[Suggestion] `response()->json()` 不使用、prompt YAML 配置、Prism 直呼びなし、disabled による制御なし、という制約整理は妥当。

**実現可能性**
[Warning] `validation` を 2 段目 JSON に足すと、既存の `WorkDecompositionData::fromLlmText()` が追加キーをどう扱うかで壊れる可能性がある。厳格 schema が「未知キー禁止」なら、既存 DTO の契約変更が必要。  
修正提案: 2 段目の top-level 応答 DTO を明示する。例: `WorkDecompositionResponseData { decomposition, validation }`。JSON パースは 1 回にして、そこから `WorkDecompositionData` と `SopValidationData` を組み立てる。

[Warning] `SopValidationData::fromLlmText()` が「同じ応答テキストから別々に JSON 抽出する」設計に読める。二重パースは schema 差分・例外経路・ログの不一致を生みやすい。  
修正提案: `fromLlmPayload(array $payload)` / `fromStorage(?array $payload)` に分け、LLM 応答全体の parse は pipeline 側または response DTO 側で一元化する。

**期待効果の妥当性**
[Warning] `validation` が不正なだけで decompose 段全体をリトライし、最終的に解析失敗になる設計は、補助的な表示機能としては強すぎる可能性がある。シナリオ生成そのものは成功できるのに、レポートの不正でユーザー価値の本体を失う。  
修正提案: `validation` の必須度を設計で決める。厳格に必須にするなら「レポートなしの成功を許さない」理由を書く。補助情報に留めるなら、decomposition は厳格、validation は不正時 `null` + 監査ログ、という分離も検討する。

[Suggestion] PHP 規約検査は初期スコープとして妥当。ただし `narration_not_polite` は「ます」で終わらない検査だと「ました」「ません」などをどう扱うかが仕様になるため、詳細設計で明文化するとよい。

**リスク**
[Warning] `fromStorage()` が壊れた JSON を `null` にして非表示にするだけだと、保存契約の破損が長期間見えない。画面 500 を避ける判断は正しいが、無音化は危険。  
修正提案: `null` フォールバックに加えて、構造不正をログまたは監査イベントに残す。テストでは「壊れた保存値で Show が落ちない」だけでなく「検知される」ことも固定する。

[Warning] 最新 succeeded job の取得は、manual / organization / source_document_id の境界を明確にしないと cross-org 読み出しや古い所見表示のリスクがある。  
修正提案: `VideoManual` relation 起点で latest succeeded を取得し、class 起点の direct fetch を避ける。`source_document_id` 比較は「最新 document が存在しない場合」「解析対象 document が削除済みの場合」の表示仕様も決める。

**スコープの適切さ**
[Suggestion] Show 画面に限定し、編集画面インライン表示をスコープ外にした判断は適切。まず「確認すべき場所を示す」価値を検証できる。

[Warning] 「Show の追加クエリは 2 本、cut 件数に依存しない定数本」は、位置表記のために nested cuts を読むなら実装次第で崩れる。  
修正提案: 必要列だけを eager load する、または専用 query/service で順序付きに取得する前提を詳細設計に入れる。N+1 を防ぐテストも欲しい。

**型安全性**
[Warning] DTO 群の境界は概ね良いが、`validation_json` の storage shape、Inertia props shape、TS union の同期責務がまだ抽象的。PHPStan level 10 を通すには nullable と array shape の扱いをかなり明示する必要がある。  
修正提案: `SopValidationData::toArray()` の戻り array shape、`ScenarioReportData` の親 DTO、`AnalysisReportData|null` の props 契約を設計に追加する。Controller から生 array を組み立てず、DTO 経由に統一する。

結論として、方向性は妥当です。ただし「validation 不正で解析本体を失敗させるか」「2 段目 JSON の parse/DTO 契約をどう切るか」「費用ゼロ表現」の 3 点は詳細設計前に直した方がよいです。
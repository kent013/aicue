全体判定: **CHANGES_REQUESTED**

### 1. 使命との整合性

[Suggestion] LLM 解析がプロバイダ停止時に途中終了しないことは、SOP 起点のシナリオ生成を安定して完遂する基盤であり、North Star に整合します。アプリ実行コードを変えず、既存の時間予算不変条件をテストで強化するスコープも適切です。

### 2. 禁止事項違反

[Suggestion] 禁止事項への直接の抵触はありません。Prism 呼び出し経路、prompt YAML の配置、API/UI/DB は変更せず、テスト内の読み取り規則だけを統一する設計です。

### 3. 実現可能性

[Warning] `PromptWaitBudget` の責務境界を明確にしてください。YAML の走査・parse は `PromptYaml`、`client_options.timeout` の解釈・違反ラベル生成は `PromptWaitBudget` と分ける意図は妥当ですが、API が曖昧なままだと再び素の配列参照が生まれます。

修正提案: `PromptWaitBudget` が YAML 配列を入力に取り、`array{timeout: int|null, violations: list<string>}` を返す、という入力・出力・違反ラベルを明文化してください。`AnalysisBudget` と両 Architecture test はこの API 以外で timeout を解釈しないことを実装条件にしてください。

### 4. 期待効果の妥当性

[Critical] 「分母の到達証明」が実在ファイルの固有名を**1 本**含むことの確認だけでは不十分です。走査対象が部分的に欠落しても、その 1 本だけ残れば緑になります。今回の主要目的は全 prompt YAML を既定拒否で守ることなので、分母の部分欠落を許す到達証明は目的に届きません。

修正提案: 現行の 5 本について、走査結果の相対パス集合が期待集合を少なくとも包含することを検査してください。件数だけでなく集合照合にし、`example-summary`、3 段パイプラインの 3 本、`sop-extract-media` の各 path を明示的に確認します。新規 YAML は既存の再帰全数 gate によって自動的に検査され、既知分母の欠落はこの集合照合で検出できます。

[Suggestion] `timeout: 0`、型不正、未宣言をラベル集合で検証する方針は正しいです。件数比較を避ける点も、偽の緑を防ぐうえで合理的です。

### 5. リスク

[Warning] 「YAML 値が実効値である」前提は、今回の読み取り器では検査しないと明記されていますが、vendor の解決順や factory のクラスプロパティ設定が将来変わると、timeout 宣言 gate が緑でも実効値が変わり得ます。

修正提案: 今回は実効性検査を新設しない判断を維持してよいですが、`docs/architecture.md` の道標には、実効性を保証する既存根拠と、前提変更時に spirux 形の文書 pin を導入する条件を明記してください。`PromptWaitBudget` の docblock だけに重要な運用判断を閉じ込めないでください。

[Suggestion] `AnalysisBudget::CLIENT_TIMEOUT_SECONDS = 360` を YAML から導出しないことは適切です。値の独立した仕様値と、YAML 宣言の契約検査を保つことで、意図しない YAML の変更を検出できます。

### 6. スコープの適切さ

[Suggestion] `PromptYaml` の採用時債務と既存 gate への波及を避け、今回の対象を timeout 解釈へ限定する判断は妥当です。`sop-extract-media` を 3 段解析の `PROMPT_NAMES` に混ぜない判断も、OCR 経路と解析ステージを別概念として保てています。

[Warning] 乖離台帳の「4 本」から「5 本」への事実訂正を `append_event` のみへ後送りすると、今回新設する分母集合との説明が短期間でも不整合になります。

修正提案: 実装 PR で D50 登録・指紋カウント更新と同時に、台帳セルの分母記述も 5 本へ訂正してください。設計変更ではなく、実測済みの母集団の訂正として扱えます。

### 7. 型安全性

[Warning] `tests` が PHPStan paths 外であるため、「PHPStan level 10 を通せる」は本変更の品質保証になりません。型注釈を置くだけでは、配列 shape の誤用を自動検査できない可能性があります。

修正提案: 読み取り器の戻り値を厳密な array shape とし、fixture の各入力も `array<string, mixed>` として扱って、`timeout` を呼び出し側で再解釈しない構造にしてください。可能なら将来、tests を PHPStan 対象へ含める作業は別 feature として追跡してください。
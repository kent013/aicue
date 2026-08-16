全体判定: **CHANGES_REQUESTED**

大枠の方針は妥当です。LLM 段を増やさず、work-decomposition に validation を相乗りさせ、表示専用に留める設計は North Star と概念設計の制約に合っています。ただし詳細設計としては、型安全性・UI 実装・文字列処理にいくつか修正必須の穴があります。

**M1: プロンプトへの validation 追加**
判定: **APPROVE**

[Suggestion] prompt が長くなる影響は小さいですが、`validation` が「制御フローに使われない」ことを YAML コメントだけでなく docs 側にも同じ表現で固定する方がよいです。

**M2: LLM 出力 DTO の再構成**
判定: **REQUEST_CHANGES**

[Warning] `SopValidationData::fromStorage(?array $stored, ...)` は、壊れた保存値が JSON scalar だった場合に TypeError で詳細画面を落とす可能性があります。  
修正案: `fromStorage(mixed $stored, int $analysisJobId): ?self` にし、`null` は正常、非 array は `Log::warning` + `null` にしてください。設計の「壊れても詳細画面を落とさない」と一致します。

[Suggestion] `ScenarioVerdict::tryFrom()` 後に再度 `ScenarioVerdict::from()` するより、変数へ保持すると読みやすいです。

**M3: validation_json カラム**
判定: **APPROVE**

[Suggestion] migration timestamp が `2026_08_17` になっています。実装時点の既存 migration 順に合わせ、未来日・衝突がない名前にしてください。

**M4: パイプライン保存とリトライログ**
判定: **APPROVE**

[Suggestion] `failure_path` をログに載せる方針は良いです。テストでは validation 欠落だけでなく、`steps.*` 側の違反と区別できることも 1 ケース入れると観測条件がより固定されます。

**M5: 規約検査**
判定: **REQUEST_CHANGES**

[Warning] `rtrim($narration, self::TRAILING_MARKS)` に `。` や `！` のようなマルチバイト文字を charlist として渡すのは危険です。PHP の `rtrim` はバイト単位なので、UTF-8 文字列を壊す可能性があります。  
修正案: `preg_replace('/[\s。.!！]+$/u', '', $narration)` のように Unicode 対応の正規表現へ変更してください。

[Warning] `ScenarioRuleCheck::run()` の実装方針は概ね妥当ですが、top-level cut / child cut / orphan 的な異常データをどう扱うかが未定義です。  
修正案: stepCount は `parent_cut_id === null`、pointCount はその子として解決できた cut のみ、解決不能な cut は検査対象に含めるか除外するかを明記し、テストに 1 ケース入れてください。

**M6: props 組み立てと Controller 配線**
判定: **APPROVE**

[Suggestion] `source_document_id` と `latest source document id` の一致で鮮度判定する設計は、source document が immutable append-only である前提なら妥当です。もし既存実装に上書き更新があるなら、id ではなく checksum / version が必要です。docs に前提を明記してください。

**M7: 画面**
判定: **REQUEST_CHANGES**

[Warning] `formatPositions(finding.positions)` だけでは「ほか」を出す判定に必要な `finding.count` が渡りません。  
修正案: `formatPositions(finding.positions, finding.count)` にし、`count > positions.length` の場合に「ほか」を付ける設計へ直してください。

[Warning] `resources/js/types/manual.ts` に `SCENARIO_VERDICT_TONES` と `BadgeTone` を置くと、ドメイン型定義が UI atom の型に依存します。Atomic Design 的にも責務が混ざります。  
修正案: `types/manual.ts` は union / props 型だけにし、ラベル・tone は `components/features/manual/ScenarioReportPanel.svelte` か同階層の presentation helper に置いてください。

[Suggestion] `Button` は既存デザインに合わせ、必要なら Lucide アイコン付きにしてください。hex 直書きなし・DS token 経由の方針は妥当です。

**M8: fake / 既存テスト追随**
判定: **APPROVE**

[Suggestion] canned response 更新により既存 Feature が広く保護されるため、`WorkDecompositionResponseData` を通すテストは必須で問題ありません。

**M9: ドキュメント更新**
判定: **APPROVE**

[Suggestion] 「保証しないもの」は良いです。特に「所見は SOP への判断であり、手動編集後のシナリオ品質を保証しない」は UI 文言とも揃えてください。

**全体の追加指摘**

[Warning] 検証コマンド一覧が AGENTS.md の全量と一致していません。  
修正案: 最終検証には `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` も含めてください。少なくとも「今回触らないがリポジトリ規約上は実行対象」と明記してください。

[Suggestion] 設計全体は DTO / Inertia props / ポーリング API の分離ができており、`response()->json()` 直書きや Prism 直呼びの禁止事項には抵触していません。上記の型・文字列処理・UI helper 配置を直せば実装に進める水準です。
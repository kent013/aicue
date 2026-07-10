# 概念設計レビュー Round 2: 指摘への対応報告

Round 1 の全 Critical / Warning に対応（または根拠を添えて反論）した。対応マトリクスと
改訂後の概念設計全文を示す。再レビューし、全体判定（APPROVED / CHANGES_REQUESTED）を出してほしい。

## 対応マトリクス

### [Critical] 共有ロック規約が未固定 → 対応
概念設計 §3 冒頭に「シナリオ整合の共有不変条件」を新設:
「**cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、対象
VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する**」。
後続フェーズ（AI 解析 materialize / RenderJob 状態遷移 / テイク採用 API）を拘束する設計契約として
`docs/architecture.md` + AGENTS.md ドメイン固有規約への記録を成果物に追加。
ScenarioService::save() を最初の準拠実装と位置づけ。

### [Critical] 既存 cut の暗黙の階層/型変換 → 対応
v1 では既存 cut の階層/型変更を禁止。既存 id は「既存 type と一致する位置」にのみ出現可
（step id が points 配下に現れる / point id がトップレベルに現れるのは 422）。UI も階層をまたぐ
移動を提供しない（▲▼ は同一スコープ内のみ）。テスト計画に「階層変更 422」を追加。

### [Warning] reconcile の順序曖昧 → 対応
「段階 1: step 群確定（update/create で id 払い出し）→ 段階 2: point 群確定（親 step id を
forceFill）→ 段階 3: payload に無い既存 cut を削除」の 2 段階 + 削除に書き直した。

### [Warning] 409 時にローカル編集内容を失う → 対応
409 時は作業コピーを破棄せず conflict バナー表示。「サーバの最新を取得」は確認ダイアログで
破棄を明示同意させてから reload。成功指標に「競合による編集内容喪失ゼロ」を追加。

### [Warning] published→ready の実変更判定が未定義 → 対応
実変更 = create/delete の発生・既存 cut の isDirty()・sort_order/parent_cut_id の変更
（サーバ導出値込みの Eloquent dirty 検査 = 意味差分）。実変更なしなら published 維持。
scenario_version は doc/10 §10.8-2 の確定契約（成功時 +1）に従い常に +1
（内容同一の並行保存が 409 になる実害はリロード 1 回で、版の単調増加の単純さを優先）。

### [Warning] GET edit の IDOR inventory 未確認 → 反論（確認済み）
`tests/Architecture/NestedRouteIdorDefenseTest.php` の inventory に
`projects.manuals.edit => ScopeBindings` は**フェーズ1で登録済み**であることをコードで確認した。
今回の追加は `projects.manuals.scenario.update` のみ。

### [Warning] 2 保存系統の混乱 → 対応
「基本情報を保存」（メタ PATCH）と「シナリオを更新」（document PUT）を独立セクション・
独立ボタン・独立 dirty 判定で完全分離。

### [Warning] props/Resource の shape 乖離 → 対応
`ScenarioDocumentData` DTO を導入し、edit の Inertia props と保存成功応答を同一 DTO から生成。

### [Warning] conflict 種別の文字列ベタ書き → 対応
PHP backed enum `ScenarioConflictType`（version_mismatch / rendering / analyzing）+
TS discriminated union。既存の RecentAuthRequiredDto::CODE 409 契約と同じ code 厳格一致方式。

### [Warning] 「思考ゼロ」の言い過ぎ → 対応
「AI 生成シナリオを業務投入可能な品質まで短時間で整える編集基盤」に表現を修正し、
成功指標（保存成功率・競合による編集内容喪失ゼロ）を追加。

### [Suggestion] 空状態導線 / テストファースト → 両方採用
EmptyState + 「最初の手順を追加」ボタン、「テストファースト（fail 確認後に実装）」を明記。

---

## 改訂後の概念設計（全文）

改訂後の全文は /workspace/devnotes/20260711-0007-scenario-editing/conceptual-design.md にある
（読み込み可）。Round 1 からの差分は上記対応マトリクスの通り。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 残る指摘があれば [Critical] [Warning] [Suggestion] で分類し修正提案を添える
- 日本語で出力

# 対応マトリクス: design-review Round 5 (最終)

Codex 全体判定: **APPROVED** (Critical 0 / Warning 0)。施策 1〜6 すべて APPROVE。
指摘が無いため設計変更なし。合議ループはここで終了する。

## 最終確認 (app-design Phase 2-5)

- **使命との整合**: 「編集ゼロ」で作った完成動画を、一覧のその場で確認できるようにする変更であり、
  作った後の確認・配布の往復を減らす。新しい概念・新しい受け取り口を持ち込まない。
- **禁止事項**:
  - 1 (テストなしの完了報告): Unit / Feature / Architecture / Vitest を各施策に割り付け済み。
  - 2 (PHPStan の widen / baseline): 型は狭める方向のみ (`bool` → `?int` は値の意味づけを強める)。
    `file_get_contents` の `string|false` は既存 helper 経由で回避。
  - 4 (`response()->json()` 直書き): 追加しない。props は DTO 経由 (Inertia)。
  - 5 / 6 (LLM / prompt): 本設計は LLM を呼ばない。
  - 8 (disabled UI): プレビュー / DL は**出さないか押せるか**の 2 択。Vitest で disabled 不在を固定。
  - 9 (Artifact): 使用しない。成果物は devnotes 配下のファイルのみ。
- **コーディングルール**: Factory 前提のテスト / `RefreshDatabase` グローバル適用 (個別
  `DatabaseTransactions` なし) / DTO + PHPDoc の array shape / Svelte 5 runes + DS token +
  `@lucide/svelte` / 単方向 import。いずれも設計へ反映済み。
- **ドメイン規約 13 (T154)**: 成果物行の選択は `CurrentRenderArtifact` の中だけに存在する形へ戻し、
  Architecture テストで所在を固定した。受け取り口 (route) は 1 本も増やさない。
- **ドメイン規約 3 (3 枚セット)**: 署名 URL は props にも HTML にも載せない
  (`<video src>` は同一オリジンの app route)。no-store / bfcache 秘匿 / history 暗号化の
  前提はいずれも変わらない。

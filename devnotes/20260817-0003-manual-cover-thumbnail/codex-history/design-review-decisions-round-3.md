# 対応マトリクス: design-review Round 3 (APPROVED)

Round 3 で **APPROVED**。全 10 施策 APPROVE、Critical / Warning は 0 件。

## [Suggestion] `ofmany-sql-evidence.md` 冒頭の判定文が後段の慎重な説明と食い違う
- 判断: **対応する**
- 根拠: 冒頭で「eager load は 1 クエリで済む」と断定しながら、後段で
  「1 クエリの根拠は SQL 本文ではない」と書くのは同一文書内の不整合である。
- 対応内容: 冒頭を
  「生成 SQL を実測し、設計どおりの辞書順選択の**構造**になっていることを確認した。
  eager load のクエリ数は施策 8 の実 DB テストで固定する」に書き換えた。

## 最終確認 (使命・禁止事項チェック)

- **使命との整合**: 撮影 PWA のシナリオ選択で「読まずに選べる」ようにする =
  「思考ゼロ」で撮る導線の入口の改善であり、North Star に寄与する。
- **禁止事項**: 1 (テストなし完了) → Feature 17 ケース + Vitest 7 ケースを計画済み /
  2 (PHPStan widen) → 該当なし (generics と shape 注釈で通す) /
  4 (`response()->json()` 直書き) → Inertia props のみ、新規 endpoint なし /
  5・6 (LLM / prompt) → 該当なし / 8 (disabled UI) → ボタンを足さない /
  9 (Artifact) → 成果物はすべて `devnotes/` 配下のファイル。
- **コーディングルール**: PHPStan level 10 / Pest + Factory / `RefreshDatabase` グローバル適用 /
  DTO + Inertia props / DS token のみ / Lucide のみ / atomic import 単方向 — すべて設計に反映済み。
- **ドメイン規約**: 12 (T148 目録) → 施策 2 で登録 + 検出 A/B 判定表を明記 /
  3 (3 枚セット) → 触れない / T154 目録 → 対象外 (`render_jobs` に触れない) を明記。

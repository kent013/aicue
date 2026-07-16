# 対応マトリクス: conceptual-review Round 3（CHANGES_REQUESTED, 3 Warning・収束）

## [Warning] Architecture テストの保証範囲（import・使用まで。root 包含 / 幅単独所有は未保証）
- 判断: 対応する（保証範囲を正確に明記。過剰な AST 化はしない）
- 対応: Architecture テストの保証範囲を明確化する:
  - **保証する**: AppLayout を import するページ(allowlist 除く)は `PageContent` を **import かつ使用**する
    （テンプレートに `<PageContent` タグが出現。dead import は不可 = 部分的な未使用も落ちる）。
  - **保証しない（別手段で担保）**: 「本文ルートを包む」「幅を PageContent だけが所有」は、移行時に
    内側の重複 `max-w-*` を除去することと、代表ページの**表示回帰テスト**（中央寄せラッパ存在の確認）+
    コードレビューで担保する。完全な AST 解析は過剰（禁止事項6）につき採らない。
  - 補強: 移行ページのテンプレートに `max-w-` トークンが `<PageContent>` 経由以外で残っていないこと
    （＝競合 max-width の残存が無いこと）を、移行時のレビュー観点として明記（arch テストの soft check
    として `max-w-` 直書き残存を検出できるなら加える）。

## [Warning] Capture の "full-bleed" 用語が不正確（AppLayout <main> の padding は残る）
- 判断: 対応する（改称）
- 対応: 「full-bleed allowlist」→ **「max-width 非制約 allowlist」**に改称。意味は「PageContent の
  max-width 中央寄せを課さないページ（AppLayout 共通の padding は残る = 真の画面端 full-bleed ではない）」。
  真に padding も回避したいページは現状存在しないため、padding 回避責務は導入しない。

## [Warning] 対象境界が「確定」と言いつつ「最終確定は詳細設計」で再び未確定
- 判断: 対応する（概念設計で確定。詳細設計は照合のみ）
- 対応: 境界を概念設計で**確定**する。Capture/Index を実構造確認（通常のマニュアル選択リスト、wide grid
  なし）した結果 **移行対象**と確定。**max-width 非制約 allowlist = `Capture/Show` の 1 枚のみ**（2 カラム
  grid レコーダー面、意図的にワイド）。よって移行対象 = AppLayout 24 ページ − Capture/Show = **23 ページ**。
  詳細設計では「現行構造との照合と maxWidth 値の割当」のみ行い、差異発見時は概念設計へ戻す。

## [Suggestion] 使命整合・実現可能性・型安全性・テストファースト
- いずれも妥当（変更なし）。必須 union prop / red→green 順序は解消済み。

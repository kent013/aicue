# 対応マトリクス: design-review Round 5

Codex の全体判定は **APPROVED**。[Critical] / [Warning] / [Suggestion] いずれも 0 件。
施策 S1〜S9 と mutation 確認手順のすべてが APPROVE。

対応すべき指摘なし。詳細設計を確定とする。

## 最終確認（app-design 2-5「使命・禁止事項チェック」）

- **使命への寄与**: 再認証・パスワード設定・招待受諾は撮影導線へ至る前提であり、
  無関係な操作の巻き添え 429 で塞がる経路を消す。撮影機能そのものは変えない
  （誇張しない表現に統一済み）。
- **禁止事項**:
  - 1（テストなしの実装完了）→ 全施策にテストがあり、不変条件は Architecture / Feature テストへ登録。
  - 2（PHPStan の widen / baseline）→ 該当なし。`is_int()` / `is_string()` で narrowing する設計。
  - 4（`response()->json()` 直書き）→ controller に触れないため該当なし。
  - 5（`DatabaseTransactions` 個別使用）→ 使わない。`RefreshDatabase` グローバル適用のまま。
  - 9（Artifact）→ 成果物はすべてリポジトリ内ファイル。
- **ドメイン規約 5**: 閾値は 6/min・10/min・60/min のいずれも据え置き。
  named limiter のキーは `{レーン}:{種別}:{値}`。email をキーに入れるレーンは無いため
  `EmailNormalizer` / `EmailHash` / `Str::transliterate()` は登場しない。
- **コーディングルール**: PHPStan level 10 / Pest / Factory / `declare(strict_types=1)` /
  日本語コメントを各施策に反映済み。

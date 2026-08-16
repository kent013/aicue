# 対応マトリクス: impl-review Round 1

Codex (gpt-5.5 / reasoning=high / label=impl-review) の全体判定は **APPROVED**。
[Critical] / [Warning] / [Suggestion] のいずれも 0 件だったため、コード変更は発生していない。

## 判定の要約 (Codex 側)

- 施策 A〜J はすべて設計どおり実装されている
- 旧 `?status=` の互換受理・旧 testId (`manual-status-{id}` / `manual-filter-status`) の並走は残っていない
- 写像規則は `ManualProgress::forStatus()` 1 か所に閉じており、逆写像も同じ match から導出されている
- TS 側に写像を持たせていない (サーバが決めた値を表示するだけ)
- 撮影 PWA の進捗語彙 (`CaptureProgress`) を PC の 3 値へ寄せていない
- DESIGN.md / Atomic Design の観点で hex 直書き・階層逆流・disabled 新設は無い

## Claude 側の補足 (レビューへ渡した前提の実測)

- 施策 H の fail-first は実測で確認済み。`resources/js/types/manual.ts` の
  `VideoManualStatus` から `"published"` を、`ManualProgress` から `"completed"` を
  一時的に削除して `composer test tests/Architecture/ManualEnumTsSyncInvariantTest.php`
  を実行し、新設 2 本がともに赤 (10 tests / 8 passed / 2 failed) になることを見た。
  復元後は 10 passed。degenerate PASS ではない。
- 旧 testId の参照ゼロは `rg 'manual-status-|manual-filter-status|filterStatus' resources/ tests/`
  が 0 件 (exit=1) であることで確認した。

## 未対応として残したもの

なし。指摘が 0 件のため次ラウンドは実施しない (合議終了)。

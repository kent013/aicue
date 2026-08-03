# Codex 概念設計レビュー Round 3: bugfix-bughunt-infra

Round 2 の残指摘 (Warning 2 件 + Suggestion 2 件) に全て対応した。判定の更新を求む。

## 対応マトリクス

### [Warning] ensure_filament_assets の marker 単独判定
- 対応: skip 条件を「marker (composer.lock の filament バージョン) 一致 **かつ** 必須アセット
  (`public/js/filament/filament/app.js`・`public/css/filament/filament/app.css`) の実在」に強化。
  marker は `filament:assets` 成功後にのみ書き込む契約 (失敗時は marker を残さず次回再実行) を
  設計に明記した。

### [Warning] AdminUserSeeder guard の安全強度
- 対応: guard を「`local` は従来どおり無条件 / `bughunt.local` は接続 DB 名が
  `^bug_hunt(_[1-8])?$` に一致する場合のみ」に変更。DB 名 regex 判定は BughuntOAuthSeeder と
  共通ヘルパへ集約。テスト計画に追加:
  - bughunt.local ∧ 非 bughunt DB 名 → no-op (dev DB 防御の固定)
  - bughunt.local ∧ bug_hunt DB 名 → 作成される
    (`DB::connection()->setDatabaseName()` で接続を張り替えず DB 名のみ差し替えて検証、
    テスト末尾で復元。BughuntBillingSeederTest も同手法)

### [Suggestion] fake_external marker の非解釈契約のテスト固定
- 対応: `billing.tickets.show?fake_external=stripe` で purchased バナーが出ない
  (purchased=false) ことを検証するテストを計画に追加した。

### [Suggestion] ExternalBillingRedirect の空 URL 拒否
- 対応: DTO コンストラクタで `Assert::stringNotEmpty($url)` を明記した。

改訂済み全文は devnotes/20260712-0949-bugfix-bughunt-infra/conceptual-design.md。
全体判定 (APPROVED / CHANGES_REQUESTED) の更新を。

# 対応マトリクス: conceptual-review Round 2

## [Warning] ensure_filament_assets が marker 一致だけで skip すると部分生成/削除を誤判定
- 判断: 対応する
- 対応内容: skip 条件を「marker 一致 **かつ** 必須アセットの存在 (public/js/filament/filament/app.js・public/css/filament/filament/app.css の実在)」に強化。marker は `filament:assets` の**成功後にのみ**書き込む契約 (失敗時は marker を残さない = 次回再実行) を設計に明記。

## [Warning] AdminUserSeeder の guard 拡張が三重 guard と安全強度が揃っていない
- 判断: 対応する
- 根拠: `APP_ENV=bughunt.local` が誤って dev DB を向いた場合に既知資格情報 admin が dev DB に作成されるリスクは実在する (bughunt seeder 群の fail-secure 思想と非対称)。
- 対応内容: guard を「`local` は従来どおり無条件 / `bughunt.local` は接続 DB 名が `^bug_hunt(_[1-8])?$` に一致する場合のみ」に設計変更。DB 名 regex は BughuntOAuthSeeder と重複するため、共通ヘルパ (例: `Database\Seeders\Concerns\ConfirmsBughuntDatabase` trait または App\Support 小クラス) に集約する。テストで「bughunt.local ∧ 非 bughunt DB 名 → no-op」を固定。
- テスト手法の補足: 作成側経路 (bughunt.local ∧ bug_hunt DB) は `DB::connection()->setDatabaseName('bug_hunt')` で接続を張り替えずに DB 名のみ差し替えて検証できる (テスト末尾で復元)。BughuntBillingSeederTest も同手法を使う。

## [Suggestion] fake_external marker をアプリが解釈しない契約のテスト固定
- 判断: 対応する — `billing.tickets.show?fake_external=stripe` で purchased バナー相当が出ない (purchased=false) ことを検証するテストを 1 本追加。

## [Suggestion] ExternalBillingRedirect の空 URL 拒否
- 判断: 対応する — DTO コンストラクタで `Assert::stringNotEmpty($url)` (既存 DTO の流儀)。

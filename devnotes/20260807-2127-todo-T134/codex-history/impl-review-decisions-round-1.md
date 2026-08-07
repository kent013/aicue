# 対応マトリクス: impl-review Round 1

## [Critical] migration / factory がレビュー差分に含まれていない (施策 7 未完了に見える)

- 判断: **反論する (指摘は成立しない。差分提示の不備であり実装漏れではない)**
- 根拠: app-implement SKILL.md A-2 が規定する差分取得コマンドの pathspec が
  `app/ resources/ tests/ routes/ config/ bootstrap/ docs/` であり、`database/` を含まない。
  実装は既に完了している:
  - `database/migrations/2026_08_07_210000_drop_project_role_from_organization_invitations_table.php` (新規)
    が `organization_invitations_project_role_check` 制約と `project_role` 列を drop し、
    `down()` で列 + 制約を復元する
  - `database/factories/OrganizationInvitationFactory.php` から `editorInvitation()` /
    `shooterInvitation()` と `use App\Enums\ProjectRole;` を削除済み
  - `composer test` (3806 tests) が `RefreshDatabase` で毎回この migration を流して green
- 対応内容: Round 2 のプロンプトに `git diff HEAD -- database/` の全文を添付し、
  schema と factory に旧概念が残っていないことを提示する。コード変更は行わない。

## [Warning] `role=organization_owner` の 422 テストが無い

- 判断: **反論する (既に存在する)**
- 根拠: `tests/Feature/Organization/InvitationTest.php` に
  `test('Owner ロールでの招待は指定できない (transferOwnership のみが正規経路)')` が既にあり、
  `role => OrganizationRole::Owner->value` を POST して `assertSessionHasErrors('role')` +
  `Notification::assertNothingSent()` を検証している (Inertia レーンのため 422 ではなく
  session error bag へのリダイレクトが正しい形)。
- 対応内容: Round 2 で当該テストの本文を引用して提示する。コード変更は行わない。

## [Warning] `LockedRowReload` が構造を機械検証していない (外部入力 id の逃げ道になりうる)

- 判断: **対応する**
- 根拠: 指摘のとおり。この case は「新しい到達経路を作らない」ことだけを根拠に
  存在秘匿の視点を免除しているので、その根拠自体が壊れたことを検出できないと
  「分類したもの勝ち」になる。目録型 gate の趣旨 (deny-by-default) に照らして穴である。
- 対応内容: `tests/Architecture/InvitationResolutionInventoryTest.php` に検査
  「ロック下再読取に分類した箇所は『解決済みモデルの主キー + lockForUpdate』の形をしている」
  を追加した。(a) 本文に `lockForUpdate(` があること (b) 主キーが
  `whereKey($model->id)` / `whereKey($model->getKey())` の形 (= 既に解決済みのモデル由来) で
  あることを要求する。文字列 id / route parameter を直接渡す形は落ちる。
  空振りしないことを 2 通の mutation (M8a: `whereKey((string) $invitation->id)` へ書換 /
  M8b: `->lockForUpdate()` 削除) で確認し、`gate-mutation-log.md` §(f) に記録した。

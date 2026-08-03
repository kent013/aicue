# design-review Round 3

Round 2 の残件 (Critical 1 件 + Suggestion 1 件) に対応しました。再レビューし、全体判定を出してください。

## 対応マトリクス

- [Critical] `UpdateOrganizationRequest::attributes()` の未テスト実装: **対応**。施策 5 に新規 Feature テスト `tests/Feature/Organization/OrganizationSettingsCopyTest.php` を追加した (organizations.update の既存テストは `OrganizationBoundaryNotFoundTest` = 404 境界検証専用のみのため、文言検証は責務の異なる新ファイルに置く)。施策一覧・変更箇所・実装手順も更新済み。
- [Suggestion] FQCN の完全一致限定: **採用**。検出仕様を「FQCN 直書きは解決結果が `Illuminate\Support\Facades\Validator` と完全一致する場合のみ」に修正。

## 差分 1: 施策 5 追加テスト (新規ファイル)

```php
// tests/Feature/Organization/OrganizationSettingsCopyTest.php (新規)
// UpdateOrganizationRequest::attributes() の局所上書きが効き、グローバルの「名前」ではなく
// UI ラベル準拠の「組織名」で表示されることを厳密一致で検証する
test('組織名が空だと局所上書きされた日本語ラベルのエラー文言が返る', function (): void {
    // Factory で owner + organization を作成 (既存の組織系テストのセットアップパターンに従う)
    ...
    $response = $this->actingAs($owner)
        ->from(route('organizations.settings', $organization))
        ->patch(route('organizations.update', $organization), ['name' => '']);

    $response->assertSessionHasErrors(['name' => '組織名は必須項目です。']);
});
```

## 差分 2: 実装手順 (テストファースト順序) の更新

3. 施策 5 のテスト追加 → 「messageは必須項目です。」「reasonは必須項目です。」「名前は必須項目です。」(局所上書き前) で fail を確認
5. 施策 3 (attributes 補完 + `UpdateOrganizationRequest::attributes()` 局所上書き) → 施策 4/5 green

## 差分 3: 施策 4 検出仕様の修正

```
//       - use Illuminate\Support\Facades\Validator (as Alias) → Alias::make / Validator::make
//       - FQCN 直書きは解決結果が Illuminate\Support\Facades\Validator と完全一致する場合のみ
//         (同名の独自クラスによる過剰検出を避ける)
```

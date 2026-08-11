レビュー仮説: この設計の主リスクは「SSO fake の本番混入」ではなく、検査の空振りとテスト実装の脆さです。設計方向は妥当ですが、2点は実装前に直した方がよいです。

## 施策別判定

| 施策 | 判定 |
|---|---|
| 1. Socialite driver 解決点の切り出し | APPROVE |
| 2. SSO fake の実装 | APPROVE |
| 3. fake 配線 | APPROVE |
| 4. fake 配線 inventory 登録 | APPROVE |
| 5. 外部到達点目録の funnel retarget | APPROVE |
| 6. `stateless()` 封鎖の走査対象追随 | REQUEST_CHANGES |
| 7. 「SSO は fake しない」記述是正 | APPROVE |
| 8. bughunt provision 実効 env 検証 | APPROVE |
| 9. 新規 behavioral テスト | REQUEST_CHANGES |

## 指摘

[Warning] 施策 6: `stateless(` の単純文字列検索は弱いです。  
`->stateless (` のように空白を入れた呼び出しを検出できません。これは「OAuth state 照合の無効化を封じる」目的に対して検査がすり抜け可能です。

修正案:

```php
expect($source)->not->toMatch('/->\s*stateless\s*\(/', "{$path} が stateless() を使っています");
```

できれば `stateless` 単語だけでなく method call として検出する形にしてください。

[Warning] 施策 9: Pest ファイル内の `function enableSsoFake(): void` はグローバル関数衝突のリスクがあります。  
テストファイルは同一 PHP プロセスで読み込まれるため、将来別ファイルに同名 helper が追加されると fatal になります。

修正案: グローバル関数ではなく、ファイル内 closure にしてください。

```php
$enableSsoFake = function (): void {
    config(['testing.fake_externals' => true]);
    (new FakeExternalsServiceProvider(app()))->register();
};
```

各 test では `($enableSsoFake)();` の形で呼びます。

[Suggestion] 施策 9: 負のコントロール #1 は `google` 固定なので、`template.social_providers` から Google が外れた場合にテスト意図と無関係に落ちます。  
現状 Google 固定がプロダクト前提なら問題ありませんが、長期的には「Google SSO が登録されていること」を明示 assert してから host を検査した方が、失敗理由が読みやすいです。

[Suggestion] 施策 2: `FakeSocialiteProvider::user()` の provider 名は `ensureProviderEnabled()` 後にしか通常到達しないため実害は低いですが、email 生成に使う以上、`FakeSocialiteProvider` 単体でも provider key の文字種を軽く固定するとより堅いです。  
例: `Assert::regex($provider, '/^[a-z0-9_-]+$/')`。ただし既存 provider key に合わせてください。

## 良い点

- `Laravel\Socialite\Contracts\Factory` を直接 bind しない判断は妥当です。Socialite の deferred provider に fake が後勝ちで潰される問題を避けられています。
- SSO fake を `testing` / `bughunt.local` に限定し、`local` を除外する判断はセキュリティ上妥当です。
- ExternalSeam と ExternalFakeWiring の責務を分けている点もよいです。検知と遮断を混ぜていません。
- UI 変更なしのため DESIGN.md / Atomic Design 観点は非該当で問題ありません。

## 全体判定

CHANGES_REQUESTED

設計方針は承認できる水準ですが、`stateless()` 検査のすり抜けと Pest helper のグローバル関数化は直してから実装に入るべきです。
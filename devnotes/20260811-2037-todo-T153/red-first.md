# テストファースト: 実装前の赤 (T153)

`composer test -- --filter=FakeSocialiteWiring` を**実装前**に実行した結果。

```
tests=9  passed=2  failed=5  errors=2
```

## 通った 2 本 (= 空振り防止の証拠)

- `負のコントロール: fake 無効 (レーン既定) では social.redirect が実 IdP ホストへ出る`
  → 実装前から緑。`accounts.google.com` へ出ていることが観測できている =
    施策後に #2 が緑になったとき「もともと外に出ていなかった」ではないと言える。
- `fake 有効でも social.callback は intent 不在なら Socialite に触れずログインへ戻す`
  → 短絡分岐は resolver に到達しないので実装前から緑 (期待どおり)。

## 失敗 5 本 (assert 失敗)

| ケース | 実測 |
|---|---|
| #2 全 provider が自アプリ host に閉じる | `provider=google が自アプリ host に閉じていません` / expected `localhost` actual `accounts.google.com` |
| #3 register round-trip | Location が `https://accounts.google.com/o/oauth2/auth?...&state=...` |
| #4 login round-trip | 同上 |
| #5 link round-trip | 同上 |
| #6 step-up round-trip | 同上 (`&prompt=login` 付き) |

## エラー 2 本 (クラス未存在)

| ケース | 実測 |
|---|---|
| #7 identity 契約 pin | `Class "App\Services\Auth\Fakes\FakeSocialiteProvider" not found` |
| #8 local では配線されない | `Target class [App\Services\Auth\SocialiteDriverResolver] does not exist.` |

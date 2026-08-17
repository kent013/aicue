# Round 3: 施策 1 の 2 点の局所修正

## [Warning] MFA 専用鍵を `getAuthIdentifier()` に合わせる → 対応

```php
// 通常側は上限未満のまま、多要素チャレンジ専用の計数だけを上限まで積む
// (鍵は vendor と同じく認証識別子で組み立てる。主キーとは限らない)
$challengeKey = "filament-multi-factor-challenge:{$admin->getAuthIdentifier()}";
while (! RateLimiter::tooManyAttempts($challengeKey, maxAttempts: 5)) {
    RateLimiter::hit($challengeKey);
}
```

確認点の表も「`{認証識別子}` (`$user->getAuthIdentifier()`。主キーとは限らないので `getKey()` を使わない)」に直しました。

## [Warning] 未入力時のエラーを具体的に検査する → **一部対応・一部反論**

キーまで固定します:

```php
$component->call('authenticate')->assertHasErrors(['data.multiFactor.app.code']);
```

状態パスの根拠は vendor 実物です (`defaultMultiFactorChallengeForm()` が `statePath('data.multiFactor')`、
提供元 (`AppAuthentication::getId()` = `app`) の Group で括る)。確認点の表にも追記しました。

規則名まで書く形 (`=> 'required'`) は**今は書きません**。Livewire の規則名アサーションは
`TestsValidation::failedRules()` = テスト用 store に登録された validator に依存しており
(`store($this->target)->get('testing.validator')`)、Filament の schema 検証経路がそれを満たすかは実測しないと分かりません。
推測で契約を書くと「規則名アサーションが常に素通りする」形の偽緑を作り得ます。
そこでリスク欄に手順として明記しました:

> 入力エラーの検査はキーまで固定する。規則名まで固定する形は Livewire の規則名アサーションが
> テスト用 store の validator に依存するため、**推測で契約を書かない** — 赤の実測時に error bag の実キーと
> failed rule を確認し、規則名まで取れることが分かったら `=> 'required'` へ強化する。

この 2 点で施策 1 を承認できるか判定してください。

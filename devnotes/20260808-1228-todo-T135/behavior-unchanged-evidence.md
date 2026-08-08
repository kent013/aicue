# 振る舞い不変の実測 (route:list の全 route middleware 比較)

本 TODO は「記述と安全弁の是正」であり、**付与内容を 1 つも変えない**ことが完了条件である。
既存の目録テスト群 (`RecentAuthRouteTest` / `TwoFactorStepUpInventoryTest` /
`PasskeyRouteProtectionTest` / `PasswordConfirmMiddlewareAbsenceTest` /
`LoginMethodRemovalRouteTest` / `ThrottleCoverageInventoryTest` /
`RouteThrottleBinderTest`) を **1 行も変更せず** green にした上で、
直接の実測も採った。

## 手順

worktree 内で、実装後 (after) と **HEAD (= main) の実装** (before) の
`php artisan route:list --json` を取り、全 route の `middleware` 配列を比較した。
before は 2 provider を `git checkout HEAD --` で戻し、
`RouteMiddlewareBinder.php` を一時的に退避して採取した (採取後すぐ復元済み)。

## 結果

```
route 総数            : 207 (before / after ともに)
(method, uri, name) 集合: 完全一致
middleware 配列の差分  : 0 件
middleware 非空の route : 196
```

例 (`passkey.destroy`。**順序も含めて**一致):

```
web
Illuminate\Auth\Middleware\Authenticate:web
Illuminate\Routing\Middleware\ThrottleRequests:passkeys
App\Http\Middleware\RequireRecentAuth
App\Http\Middleware\EnsureLoginMethodRemains
```

# route:cache → route:list → route:clear の往復 (T120 の再発防止確認)

実行前の状態を記録し、**終了時に元の状態 (route cache 無し) へ戻した**。

```
$ ls bootstrap/cache/routes-v7.php     # 実行前
No such file or directory              # = 元は「無い」

$ php artisan route:cache
 INFO Routes cached successfully.
$ ls -la bootstrap/cache/routes-v7.php
-rw-r--r-- 1 vscode vscode 390452 ... bootstrap/cache/routes-v7.php

$ php artisan route:list             # ← T120 ではここが必ず落ちた
 Showing [207] routes                 # 例外なし / exit 0

$ php artisan route:clear
 INFO Route cache cleared successfully.
$ ls bootstrap/cache/routes-v7.php
No such file or directory              # = 元の状態へ復帰
```

## cached 起動でも保護が効いている根拠 (= 生成時の焼き込み)

cache 済みの状態で `route:list -v` を採ったところ、後付け済み middleware が
**cache の中に焼き込まれている**ことを確認した (実査ブリーフの実測値と一致):

```
$ grep -o "recent-auth" bootstrap/cache/routes-v7.php | wc -l
33

$ php artisan route:list --name=passkey.destroy -v
 ⇂ web
 ⇂ Illuminate\Auth\Middleware\Authenticate:web
 ⇂ Illuminate\Routing\Middleware\ThrottleRequests:passkeys
 ⇂ App\Http\Middleware\RequireRecentAuth
 ⇂ App\Http\Middleware\EnsureLoginMethodRemains

$ php artisan route:list --name=two-factor.qr-code -v
 ⇂ web
 ⇂ Illuminate\Auth\Middleware\Authenticate:web
 ⇂ App\Http\Middleware\RequireRecentAuth
 ⇂ Illuminate\Routing\Middleware\ThrottleRequests:two-factor-secret-read
```

**cached 起動で binder が skip しているにもかかわらず保護が載っている**という
本 TODO の中心的事実 (= 実効しているのは生成時の焼き込みであり、
`route:cache` の毎デプロイ再生成がその前提条件) の直接証拠になっている。

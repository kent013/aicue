# 施策 5 の fail 観測 (テストファースト)

詳細設計「実装順序」1. の実測記録。`PostBootRouteMutationInventoryTest` を
**allowlist 空**の状態で作り、`app/` 配下の 3 ファイルが列挙されて落ちることを確認した。

```
$ vendor/bin/pest tests/Architecture/PostBootRouteMutationInventoryTest.php
tests=2 passed=1 failed=1

起動後に named route を引いて加工するコードが allowlist 外にあります:
  app/Providers/FortifyServiceProvider.php
  app/Providers/PasskeyServiceProvider.php
  app/Support/Http/RouteThrottleBinder.php
```

- 実査ブリーフ / 詳細設計が「実測: 3 ファイル・7 箇所」としていた母集団と一致する。
- negative control (allowlist の 2 ファイルがトークンを含む) は allowlist 空のため
  vacuous に green。allowlist 確定後に実効になる。

施策 2 / 3 で provider 2 本を binder 経由へ移し、allowlist を
`app/Support/Http/RouteThrottleBinder.php` /
`app/Support/Http/RouteMiddlewareBinder.php` の 2 本に確定して green にする。

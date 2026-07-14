# 詳細設計レビュー Round 3

Round 2 の残 2 点への対応を報告します。

## Round 2 指摘への対応

### [Warning] S3 static callback からのヘルパ呼び出し / 型契約未確定
→ 対応。
- ヘルパを `private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void`
  と明記し、booted の static クロージャ内から `self::appendMiddlewareIfMissing(...)` で呼ぶ。
- 型契約を確定 (「実装時確認」ヘッジを削除):
  - `Illuminate\Routing\Router::getRoutes()` の戻り値は具象 `Illuminate\Routing\RouteCollection`。
  - `RouteCollection` は `RouteCollectionInterface` を実装し `getByName(string): ?Route` は同 interface の宣言メソッド。
  - ヘルパ引数型は `RouteCollectionInterface` に確定 (具象を渡せる = covariant、PHPStan L10 適合)。
  - `refreshNameLookups()` は具象専用メソッドだが callback 側の具象 `$routes` に対して呼ぶ (ヘルパ内では使わない)。

### [Warning] S5 baseline が送信後入力変更で汚れる
→ 対応。`putProfile()` 冒頭で `const submittedEmail = profileForm.email` をスナップショットし、
`onSuccess: () => { baselineEmail = submittedEmail; }` に変更。onSuccess で「サーバが受理した送信値」を
baseline に固定し、送信後〜応答前の入力変更で baseline が汚れないようにした。

### [Suggestion] S1 Assert vs LogicException
→ 見送り継続 (軽微、PHPStan L10 は通過。実装時に既存流儀へ寄せる余地は残す)。

---

以上で残る Warning は解消できていますか。全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。

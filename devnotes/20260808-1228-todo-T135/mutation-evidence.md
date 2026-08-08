# mutation 実測: 新設テストが実際に壊れを検出するか

詳細設計に mutation の手順は明記されていないが、本 TODO の成果物が
「保護そのものではなく、保護が壊れたときに気づく仕組み」であるため、
**新設した gate / 契約テストが空振り green でないこと**を mutation で実測した。
入れた mutation はすべて即座に戻し、最終差分に 1 つも残っていないことを確認済み
(`grep -c MUTATION app/Support/Http/RouteMiddlewareBinder.php` = 0)。

| # | mutation | 対象 | 結果 |
|---|---|---|---|
| M1 | `PostBootRouteMutationInventoryTest` の allowlist に、後付けトークンを含まない `app/Support/Http/RateLimiterKeys.php` を追加 | negative control | **fail**: 「allowlist の [...] が後付けトークンを 1 つも含みません」= 実装が消えた/改名されたときに gate が空振り green にならない |
| M2 | `RouteMiddlewareBinder::attachAll()` の `if ($routesAreCached)` を `if (false && $routesAreCached)` に (cached skip の無効化 = **T120 の再現**) | 契約 #1 / #1b / #8 | **fail 3 本**: `resolver すら呼ばれない (T120 恒久回帰)` / `middleware が 1 本も増えない` / `attachOnBooted() は cached 起動で resolver を呼ばない` |
| M3 | `$route->computedMiddleware = null;` を削除 (memo 破棄の無効化 = **無音の無防備**) | 契約 #6 | **fail 1 本**: `付与後に computedMiddleware が破棄されている` |
| M4 | `attachByName()` の `throw new RuntimeException(...)` の前に `return;` を挿入 (fail-fast を silent no-op へ退行) | 契約 #2 | **fail 1 本**: `routesAreCached: false で route が引けないと RuntimeException` |

## 施策 5 のテストファースト観測 (別ファイル)

`impl-step1-fail-observation.md` を参照。allowlist を空にした状態で
`app/Providers/FortifyServiceProvider.php` / `app/Providers/PasskeyServiceProvider.php` /
`app/Support/Http/RouteThrottleBinder.php` の 3 ファイルが列挙されて fail することを
実装前に確認している。

## 検出できないこと (誇張しない)

- **docblock の主張が機序と一致していること**は mutation でも検査できない
  (自然言語の主張は機械で照合できない)。今回の是正の中心である「誤った機序の記述」は
  レビューでしか守れない。
- **起動時の route cache 鮮度**も検査していない (詳細設計「何を検査しないか」)。

**ファイル別判定**

`app/Enums/Security/ControllerAuthorizationExemption.php`: APPROVED  
設計どおり。分類語彙も狭く定義されており、app → tests import 回避も妥当です。

`app/Http/Controllers/Api/V1/ItemController.php`: APPROVED  
`resolveOrganizationProject` / `resolveProjectItem` が `Gate::forUser(...)` より前にあり、層 2 → 層 3 の順序は正しいです。dual guard で `Gate::authorize` を避けている点も妥当です。

`app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php`: APPROVED  
FormRequest より前に `{project}` の org 整合を 404 化する設計に一致しています。read group 付与も no-op 前提で許容できます。

`bootstrap/app.php`: APPROVED  
alias 登録は問題ありません。

`routes/api.php`: APPROVED  
現状の宣言順は `resolve.api-actor → api-key.ability → api.project-in-org → idempotent` で正しいです。GET 系は cross-org 404 が controller 内から middleware 内へ前倒しになるだけで、正常系・権限不足系の意味は変わりません。

`tests/Support/AuthorizationMarkerScanner.php`: CHANGES_REQUESTED  
[Critical] `Gate::forUser(...)->authorize` の検出で、`authorize` の直後が `(` であることを確認していません。  
そのため `Gate::forUser($user)->authorize;` のような「authorize を呼んでいない」コードでも認可ありとして合格します。deny-by-default gate の誤合格なので修正必須です。

[Critical] `guardMarkerOffset()` が最初の guard 位置だけを返すため、複数 guard の一部が `Gate` より後に移動しても検出できません。  
例: `resolveOrganizationProject()` は Gate 前、`resolveProjectItem()` は Gate 後、という壊れ方が合格します。`lastGuardOffset < authOffset` または全 guard offset が auth より前であることを検証してください。

`tests/Unit/Architecture/AuthorizationMarkerScannerTest.php`: CHANGES_REQUESTED  
[Critical] 上記 2 点を固定する negative test が不足しています。最低限、以下を追加すべきです。

- `Gate::forUser($user)->authorize;` は不合格
- guard が複数あり、片方だけ Gate 後にある場合は不合格

`tests/Architecture/ControllerAuthorizationGateTest.php`: CHANGES_REQUESTED  
[Critical] scanner 側の `guardMarkerOffset()` が最初の guard しか返さないため、「URL 整合 guard は認可より前」のテストが誤合格します。Item update/destroy のような 2 段 guard を守るため、全 guard 呼び出しを対象にしてください。

`tests/Architecture/ProjectRouteCurrentOrgGuardTest.php`: CHANGES_REQUESTED  
[Warning] middleware 順序テストが `api-key.ability:* < api.project-in-org` を検証していません。現状の `routes/api.php` は正しいですが、将来 `api.project-in-org` が ability より前へ移動してもテストが通ります。設計書の順序契約に含まれるため、`api-key.ability:` の index も guard より前であることを固定してください。

[Suggestion] コメント内の表で「api.project-in-org が idempotent より前」と書かれていますが、壊れる条件は「idempotent が api.project-in-org より前」です。コードは正しいのでコメントだけ直すとよいです。

`tests/Architecture/NestedRouteIdorDefenseTest.php`: APPROVED  
API item update/destroy を ScopeBindings 扱いへ変更するのは実態に合っています。

`tests/Feature/Api/V1/ItemAuthorizationTest.php`: APPROVED_WITH_SUGGESTIONS  
[Suggestion] project-level の存在オラクルは body 一致まで確認できています。`{item}` についても、cross-project item / missing item / cross-org item の 404 body が同一であることを 1 本足すと、scopeBindings 化で新しい識別差分が出ていないことをより直接に証明できます。

[Suggestion] `viewerApiKey()` / `apiBearer()` は Pest の global 関数としては名前が汎用的です。将来の再宣言 fatal を避けるなら `itemAuthorizationViewerApiKey()` などに寄せた方が安全です。

`tests/Support/OAuthTestHelpers.php`: APPROVED  
global helper 削除と明示引数化は設計どおりです。

`tests/Feature/Api/OAuthDualGuardTest.php`: APPROVED  
呼び出し移行のみで、後方互換の並走を残していない点が良いです。

`docs/app-integration-guide.md`: APPROVED  
新しい不変条件と route 追加時チェックリストは実装方針と一致しています。

`docs/architecture.md`: APPROVED  
3 層モデルの説明は今回の設計意図と一致しています。

**質問への回答**

1. gate の誤合格バイパスは残っています。特に `Gate::forUser(...)->authorize` の `(` 未確認と、複数 guard の一部後置を見逃す点は修正必須です。  
2. project-level の 422/404 存在オラクルは設計上閉じています。`{item}` も `scopeBindings()` により FormRequest 前 404 へ寄りますが、body 同一性のテストを追加するとより堅いです。  
3. read group への `api.project-in-org` 付与で GET の意味的挙動は変わっていません。cross-org は従来どおり 404、正常系も同じです。追加クエリと 404 の発生位置前倒しが主な差分です。

**全体判定: CHANGES_REQUESTED**
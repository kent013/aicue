# 対応マトリクス: design-review Round 1

## [Critical] (施策 3) FormRequest は inline guard より前に走るため、cross-org + 不正 payload が 422 になり存在オラクルになる
- 判断: **対応する（新規施策 3 を追加。設計中もっとも重要な変更）**
- 根拠: **probe テストを実走させて実測し、指摘が事実であることを確認した**:

  | ケース | 現状の API | 現状の web |
  |---|---|---|
  | cross-org の**実在** project + 不正 payload (`name` 欠落) | **422** | 404 |
  | **存在しない** project id + 不正 payload | 404 | 404 |
  | cross-org の実在 project + 正常 payload | 404 | 404 |
  | cross-org + protected key payload (`project_id`) | **422** | 404 |
  | cross-org item update + 不正 payload (空 body) | **422** | 404 |

  422 と 404 の差分が「その project が実在するか」の**存在オラクル**になっている
  (不変条件 3「cross-org 不可」/ 存在秘匿の違反)。
  web 側は既に `project.in-current-org` route middleware
  (`EnsureProjectBelongsToCurrentOrganization`) でこの順序ハザードを閉じており、
  その docblock には「API v1 は org を API キーから確定する別レイヤーの責務のため対象外」
  と書かれている = **API 側には等価の防御が用意されないまま残っていた**。

  Codex は「422 になるなら route middleware へ移すか、不変条件を『valid payload に限る』と
  明記する」の 2 択を提示したが、**後者は採れない**。本設計の中心的主張が
  「cross-org は 404 のままでなければならない」である以上、
  「不正 payload のときは 422 で実在が漏れます」という但し書きを付けた時点で設計が破綻する。
- 対応内容: **施策 3「API `{project}` の存在オラクル封じ」を新設**（優先度 最高）。
  - `EnsureProjectBelongsToApiOrganization` middleware 新規（alias `api.project-in-org`）。
    web 版と同構造で、組織の解決元だけが違う（session current org → API キー/OAuth の
    request attribute `organization`）
  - `routes/api.php` の read / write 両 group へ付与。位置は
    **`resolve.api-actor` の後**（`organization` attribute が必要）
    **`idempotent` の前**（cross-org で idempotency 行を作らせない）。
    実測で middleware 順序が `api`(SubstituteBindings) → auth → throttle →
    resolve.api-actor → ability → idempotent であることを確認済み
  - API item routes に `Route::scopeBindings()` を追加し、`{item}` ∈ `{project}` を
    **routing 層**（FormRequest より前）で解決させる（web 側と同構造）
  - `ProjectRouteCurrentOrgGuardTest` を更新（API `{project}` route は
    `api.project-in-org` を**持つこと**を要求。docblock の「対象外」記述も書き換え）
  - `NestedRouteIdorDefenseTest` の `api.v1.projects.items.update/destroy` を
    `UrlIntegrityGuard` → `ScopeBindings` へ更新（概念設計では「変更しない」としていたが、
    実装が変わる以上 inventory を実態に合わせないと drift になるため方針を変更。
    その旨を設計に明記した）
  - 施策 5 にテストケース 12-15 を追加（cross-org + 不正/protected key payload で 404、
    かつ**存在しない project id と同一応答**であること = オラクルが閉じたことの定義）

## [Warning] (施策 2) `Gate :: forUser .*?-> authorize` の正規表現は誤合格する
- 判断: **対応する**
- 根拠: 正しい。`Gate::forUser($u); $other->authorize();` のような無関係な 2 文でも
  マッチしてしまう。deny-by-default gate で誤合格は最悪の失敗モード。
- 対応内容: 正規表現を廃止し、**トークンの状態機械**に置き換えた。
  `Gate` `::` `forUser` `(` を見つけたら括弧の深さを数えて対応する `)` を求め、
  その**直後**が `->` `authorize` であることを要求する（間に何も挟まない、
  途中に `;` が出たら不合格）。設計書に擬似コードで明記した。

## [Warning] (施策 2) FQCN 許容と検出仕様 (`Gate :: authorize` 前提) が矛盾している
- 判断: **対応する（`use` import 必須へ寄せ、FQCN 許容を削除）**
- 根拠: 指摘どおり矛盾していた。実査した全 46 箇所が
  `use Illuminate\Support\Facades\Gate;` の import 形式で統一されており、
  FQCN を許容する必要がない。検出仕様を単純に保つ方が gate として安全。
- 対応内容: 「合格判定したファイルは `use Illuminate\Support\Facades\Gate;` を
  import していること。無ければ fail」に変更し、FQCN 許容の記述を削除した。

## [Warning] (施策 2) `file()` / `realpath()` / `getFileName()` の失敗時処理が曖昧
- 判断: **対応する**
- 根拠: fail-secure の集約点が骨子から読み取れなかった。
- 対応内容: 失敗点を 8 つ表に列挙し（`getAction` 型不正 / Reflection 例外 /
  `getFileName()` false / `realpath()` false / `file()` false /
  `getStartLine()`・`getEndLine()` false / 断片が空 / `use` 文なし）、
  すべて「認可なし」ではなく**解決失敗として violation に積む**こと、
  violation には **route 名 / URI / HTTP メソッド / 原因**を含めることを明記した。
  「解決失敗が 1 件でもあれば fail」の専用テストを 1 本立てる。

## [Warning] (施策 4/5) write API は `idempotent` 配下だが、テスト骨子に `Idempotency-Key` が無い
- 判断: **部分的に対応する（全件付与はしない。根拠を添えて反論 + 1 ケース追加）**
- 根拠: `IdempotentRequest::handle()` を実査したところ、
  ```php
  $key = $request->header('Idempotency-Key');
  if (! is_string($key) || trim($key) === '') { return $next($request); }
  ```
  と**ヘッダ無しは完全に素通し**であり、付けないことで別のエラーになることはない。
  probe テストでも 422/404 が期待どおり観測された。既存 `ApiEndpointTest` も
  ヘッダ無しで write を叩いている。よって全件付与は不要。
  ただし「idempotency 層との相互作用が未検証」という指摘の趣旨は妥当。
- 対応内容: ケース 16 を追加 —「403 応答は Idempotency-Key で**再生されない**」
  （`IdempotentRequest` は 2xx のみ保存する仕様なので、403 がキャッシュされて
  権限回復後も 403 が返り続ける事故が起きないことの担保）。

## [Warning] (施策 5) OAuth ケース 6/7 の setup が不足
- 判断: **対応する**
- 根拠: helper 呼び出しの具体形（client 作成 / scope / Bearer header / actor の指定）が
  骨子から読み取れず、実装者が迷う。
- 対応内容: ケース 6 のテストコードを骨子に全文追加した
  （`OAuthTestHelpers::createMcpClient` → `issueCliSessionTokens(test:, user: $viewer,
  organization:, client:, scope: 'cli:use read write')` → `Authorization: Bearer` header）。
  **`user:` に viewer を渡す**ことが本質（token 所有者が認可主体になる）。

## [Warning] (施策 5) cross-org テストは valid payload だけでは不十分
- 判断: **対応する**（Critical と同一の対応）
- 対応内容: ケース 12-15 を追加（上記 Critical 参照）。

## [Warning] (施策 6) `function issueCliSessionTokens(object $test, ...)` の委譲は PHPStan level 10 と相性が悪い
- 判断: **対応する（global wrapper を残さず削除）**
- 根拠: 正しい。`object $test` から `$test->user` / `$test->org` / `$test->client` を
  読む形は未定義プロパティアクセスで level 10 に通らない。
  加えて global 関数版と静的メソッド版の 2 経路が並走するのは
  思考原則 3「後方互換の並走を残さない」に反する。
- 対応内容: global 関数を**削除**し、`OAuthDualGuardTest.php` 内の全呼び出し箇所を
  名前付き引数の静的呼び出し
  `OAuthTestHelpers::issueCliSessionTokens(test: $this, user: $this->user, ...)` へ
  置き換える方針に変更した。

## [Warning] (施策 7) docs の「ハンドラ冒頭に Gate」は FormRequest が先に走る点を隠している
- 判断: **対応する**
- 根拠: 正しい。Critical と同根の問題で、チェックリストが誤った安心を与える。
- 対応内容: チェックリストの**第 1 項目**に
  「層 2 が FormRequest より前に閉じているか」を新設し、
  確認方法（cross-org の実在リソース + 不正 payload で 404 が返ること）まで書いた。
  `docs/app-integration-guide.md` §7 の不変条件本文にも
  「層 2 は FormRequest より前で閉じる」を追記する。

## [Suggestion] (施策 1) `NoAuthorizableSubject` は濫用されやすいので docs にも「親テナントがある create は対象外」と明記
- 判断: **対応する**
- 対応内容: チェックリスト第 3 項目に
  「`NoAuthorizableSubject` は『親テナントすら無い新規作成』限定。親テナントがある create は
  対象外 = `Gate::authorize('create', [Model::class, $parent])` を書く」を追記した。

## [Suggestion] (施策 4) `Gate::forUser(...)` の追加位置と ability は妥当
- 判断: 対応不要（肯定的評価）

## 施策の再編について

Critical への対応で施策が 1 本増えたため、番号を振り直した:

| 旧 | 新 | 施策 |
|---|---|---|
| 1 | 1 | exemption 分類 enum |
| 2 | 2 | `ControllerAuthorizationGateTest` |
| — | **3** | **API `{project}` の存在オラクル封じ（新規）** |
| 3 | 4 | `Api\V1\ItemController` に `Gate::forUser` |
| 4 | 5 | `ItemAuthorizationTest` |
| 5 | 6 | OAuth helper の Support 昇格 |
| 6 | 7 | ドキュメント更新 |

実装順序も **施策 3（層 2a）→ 施策 4（層 3）** に固定した
（層 2 を閉じる前に層 3 を足すと cross-org が 403 を返す中間状態が生まれるため）。
3 層図も層 2a（middleware/routing・FormRequest より前）/ FormRequest /
層 2b（controller inline・二重防御）に分解して書き直した。

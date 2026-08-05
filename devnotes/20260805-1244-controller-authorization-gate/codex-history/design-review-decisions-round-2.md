# 対応マトリクス: design-review Round 2

## [Warning] (施策 2) 状態機械の誤合格防止が恒久テストになっていない
- 判断: **対応する**
- 根拠: 完全に正しい。「一時的にコメントアウトして落ちるか確認」は**実装時 1 回きり**の
  手動検証であり、後から解析器を改修したときの回帰を検出できない。
  gate 自体がセキュリティ機構である以上、解析器の正しさは恒久テストで固定すべき。
  Codex の「解析処理を入力トークン列に対する純粋 helper として切り出せば
  route inventory に依存せず直接テストできる」という提案は、
  責務分離（route 走査 = テスト / 字句解析 = helper）としても正しい。
- 対応内容:
  - `tests/Support/AuthorizationMarkerScanner.php`（**新規**）を追加。
    `hasAuthorizationMarker(string $methodSource): bool` と
    `importsGateFacade(string $fileSource): bool` の 2 静的メソッドを持つ純粋 helper。
    `ControllerAuthorizationGateTest` は本 helper を呼ぶだけにする
  - `tests/Unit/Architecture/AuthorizationMarkerScannerTest.php`（**新規**）に
    **positive / negative 16 ケース**を定義。Codex が挙げた 5 つを全て含む:
    | # | 内容 |
    |---|---|
    | 2 | `Gate::forUser($user)->authorize(...)` は合格 |
    | 3 | 複数行チェーンは合格 |
    | 5 | `Gate::forUser($user); $other->authorize(...)` は**不合格** |
    | 6-9 | コメント / docblock / 文字列リテラル / 可変長文字列中の `Gate::authorize` は**不合格** |
    | 13 | import のない同名 `Gate`（`use App\Support\Gate;`）は**不合格** |
    加えて 4（ネスト括弧・クロージャ引数）、10/11（`allows` は不合格）、
    14/15（lexical use / trait use を import と誤認しない）、16（import 無し）を追加
  - DB 非依存の Unit テストとして `tests/Unit/` 配下に置く
  - 施策 2 のテスト計画から「手動コメントアウト検証」を削除し、恒久自動テストに置換

## [Warning] (施策 2) import 検査の解析範囲が未定義（メソッド断片には `use` が無い）
- 判断: **対応する**
- 根拠: 決定的な指摘。**認可マーカーはメソッド断片、import はファイル全文**という
  解析範囲の違いを設計に書いておらず、そのまま実装すると
  「メソッド断片に `use` が無い → 全部 fail」という致命的な誤りになる。
  さらに `T_USE` には 3 用途（名前空間 import / クロージャの lexical use / trait use）があり、
  区別しないと誤検出する。
- 対応内容: 解析範囲を表で明示し、`T_USE` の 3 用途の判別規則を書いた:
  | 用途 | 判別 |
  |---|---|
  | 名前空間 import ✔ | 波括弧の深さ **0** かつ直後が `(` ではない |
  | クロージャの lexical use ✘ | 直後のトークンが **`(`** |
  | trait use ✘ | 波括弧の深さ **1 以上**（クラス本体の中） |
  PHP 8 では `Illuminate\Support\Facades\Gate` が `T_NAME_QUALIFIED` 1 トークンに
  まとまる場合があるため**両形に対応**することも明記した。
  この規則は `AuthorizationMarkerScannerTest` のケース 12-15 で恒久固定される。

## [Suggestion] (施策 2) テスト計画の「その後 施策 3 で認可を足す」は番号変更後は「施策 4」
- 判断: **対応する**
- 対応内容: 該当箇所を「施策 4」に修正した。

## [Warning] (施策 3) middleware の「存在」だけでなく「順序」も Architecture テストで固定すべき
- 判断: **対応する**
- 根拠: 正しい。当初案では順序契約が **docblock にしか残っていなかった**。
  順序を破ると実害が明確に出る:
  | 契約 | 破ったときに起きること |
  |---|---|
  | `resolve.api-actor` < `api.project-in-org` | `organization` attribute 未設定で **全 API `{project}` route が 500** |
  | `api.project-in-org` < `idempotent` | **cross-org リクエストで idempotency 行が作られる**（cross-org の副作用 = 不変条件 3 に抵触） |
  `gatherMiddleware()` は実行順の配列なので index 比較で機械検証できる（コストも低い）。
- 対応内容: `ProjectRouteCurrentOrgGuardTest` に
  「API の `{project}` route は middleware 順序契約を守る」テストを新設（コード全文を設計に記載）。
  `array_search()` の index 比較で
  「`resolve.api-actor` が `api.project-in-org` より前」
  「`idempotent` があれば `api.project-in-org` がその前」を検証し、
  空振り drift ガード（`$checked > 0`）も入れた。
  施策 3 のリスク表と テスト計画も更新した。

## [Warning] (施策 5) ケース 16 は現在の期待値では「403 が再生されていない」ことを証明できない
- 判断: **対応する**
- 根拠: 完全に正しい。同一キーで 1 回目も 2 回目も 403 なら、
  2 回目が「再実行されて 403」なのか「保存済み 403 を再生」なのかを**観測できない**。
  テストが主張を証明していない = 無意味なテストだった。
- 対応内容: Codex 提案の 4 ステップに設計し直し、コード全文を記載した:
  1. viewer + 固定 Idempotency-Key で `store` → 403
  2. 同じ user に `project_admin` を付与（`attachProjectMember(..., ProjectRole::Admin)`）
  3. **同一キー・同一 payload** で再送
  4. **201 + Item 作成** を確認（保存済み 403 が再生されるなら 403 のままになる）
  これにより「権限回復後も 403 が返り続ける詰み」が将来生まれないことを恒久固定できる。

## [Suggestion] (施策 5) ケース 12 と 15 の「同一応答」は正規化 JSON body の一致を assert すべき
- 判断: **対応する**
- 根拠: 正しい。「どちらも 404」だけでは「実在/不在を区別できない」という主張の証明として弱い。
  status code と body の**両方**が一致して初めて「1 bit も漏れていない」と言える。
- 対応内容: ケース 15 の期待値を
  「404 かつ **ケース 12 と JSON body まで完全一致**」に変更し、
  `expect($crossOrg->json())->toBe($missing->json())` を含むコードを設計に記載した。
  本設計で最も本質的な 1 本（オラクルが閉じたことの定義そのもの）と位置づけた。

## [Suggestion] (施策 6) 変更箇所の説明に「既存 global 関数を委譲に変更」が残っている
- 判断: **対応する**
- 根拠: Round 1 で方針を「削除」に変えたのに、変更箇所の一行説明が古いままだった（記述漏れ）。
- 対応内容:
  「既存 global 関数を**削除**し、全呼び出しを静的メソッドへ置換」に修正した。

## [Suggestion] Idempotency-Key を全 write テストへ付与しない判断は妥当（Round 1 の反論が認められた）
- 判断: 対応不要（反論が受理された）

## APPROVE された施策
- 施策 1（exemption enum）/ 施策 4（ItemController 認可）/ 施策 6（OAuth helper）/ 施策 7（docs）

## 実装手順の更新
解析器を先に固めるため、施策 2 を 2a / 2b に分割した:
1. 施策 1（enum）
2. **施策 2a**（`AuthorizationMarkerScanner` + Unit テスト 16 ケース）← 解析器の正しさを先に固定
3. 施策 2b（gate テスト本体）→ items 3 本が未分類で fail
4. 施策 6（OAuth helper 昇格）
5. 施策 5（`ItemAuthorizationTest`）→ 認可漏れと存在オラクルの 2 種類の fail を確認
6. 施策 3（層 2a を閉じる）→ ケース 12-15 が green
7. 施策 4（層 3 を足す）→ 施策 2 とケース 1-11/16 が green
8. 施策 7（docs）
9. `composer test` / `composer phpstan` / `vendor/bin/pint --test` 全 green

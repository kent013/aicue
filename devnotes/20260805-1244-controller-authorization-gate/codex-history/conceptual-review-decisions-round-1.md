# 対応マトリクス: conceptual-review Round 1

## [Warning] `ControllerAuthorizationExemption` を `app/Enums/Security/` に置く責務の曖昧さ
- 判断: **反論する (現状維持) + 根拠を明記**
- 根拠: 実査した結果 `app/Enums/Security/` には既に `NestedRouteDefenseMode` **1 件のみ**が存在し、
  これも Architecture テスト (`NestedRouteIdorDefenseTest`) からしか参照されない
  「セキュリティ不変条件の分類語彙」である。Codex が求めた「同じ設計思想なら踏襲可」の条件が
  実際に成立している。tests 配下に置くと語彙の置き場が 2 箇所に割れる。
- 対応内容: 概念設計に「#### enum の配置先を `app/Enums/Security/` にする理由」節を追加し、
  先例踏襲 + 語彙一元化の理由を明記した。

## [Warning] 文字列マーカー検出の誤合格リスク (コメント・文字列リテラル)
- 判断: **対応する**
- 根拠: 指摘は正しい。実際に本アプリの FormRequest には
  `// 認可は controller の Gate::authorize (URL 整合 guard の後)` という定型コメントが
  多数存在し (`app/Http/Requests/Capture/*` 等)、素の文字列一致だと確実に誤合格する。
  deny-by-default gate で誤合格は最悪の失敗モード。
- 対応内容: 「#### 核心: 「誤って合格」させない (静的検出の堅牢性)」節を新設。
  `token_get_all()` でトークン化し `T_COMMENT` / `T_DOC_COMMENT` /
  `T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE` を除去してから
  トークン列パターンで判定する方針を明記した。
  (PHP-Parser への依存追加は「今必要なものだけ作る」に照らして見送り。
   stdlib の `token_get_all()` で誤合格リスクの実体は消える)

## [Warning] `can:` middleware はハンドラ本体ではなく route 側を見る必要がある
- 判断: **対応する**
- 根拠: 指摘どおり。当初から意図はしていたが概念設計に明記していなかった。
  なお実査では `can:` middleware の使用箇所は現在 0 件 (`grep "'can:" routes/ app/` が空)。
- 対応内容: 「判定経路を 2 本に分ける」(ソース走査 / `$route->gatherMiddleware()`) と明記した。

## [Warning] Laratrust `strict_check=true` / `laratrust_team_id` 明示と API actor の team 文脈
- 判断: **対応する (実査で確認済みであることを設計に明記)**
- 根拠: 実査の結果、`ProjectPolicy::canManageProject` は
  `$user->organizationRole($project->organization)` を呼び、`User::organizationRole()` は
  `hasRole($role->value, $organization->laratrust_team_id)` と team id を明示している。
  判定対象の組織は **URL 上の `{project}` から導出**されており、
  actor の `current_organization_id` には一切依存しない。よって API 経路でも正しく評価される。
- 対応内容: 「#### Laratrust の team 文脈 (不変条件 5) は既に満たされている」節を追加。
  さらに Feature テストに「actor の current_organization_id が別組織でも
  URL の project の組織で判定される」ケースを追加した。

## [Warning] 「認可漏れが構造的に不可能」は強すぎる表現
- 判断: **対応する**
- 根拠: 正しい指摘。静的マーカー検出は「呼び出しの存在」しか保証しない。
  過大な効果主張は、この gate があるから Feature テストは要らない、という誤読を招く。
- 対応内容: 効果表現を「認可判断も明示裁定も存在しない状態を機械検出できる」に弱め、
  さらに「### 期待効果の限界 (この gate が保証しないこと)」節を新設して
  検出できない 3 パターン (対象違い / Policy 常時 true / 誤 actor) を明示し、
  それらは Feature/Policy テストの責務であると責務境界を切った。

## [Warning] exemption 理由の形骸化リスク
- 判断: **対応する**
- 根拠: 正しい。`SelfScopedResource` / `NoAuthorizableSubject` は汎用に見えるため、
  規律がないと「とりあえずこれ」で埋められる。
- 対応内容: 「#### exemption の形骸化を防ぐ規律 (テストで強制する)」節を新設。
  `[route => [enum, 理由文字列]]` の 2 要素必須 / 理由の最低文字数 /
  「何が代わりに守っているか」の記入規約 / enum case ごとの適用条件 docblock を規定した。

## [Warning] `debug.login-as` の記述が逆に読める
- 判断: **対応する**
- 根拠: 指摘どおり文面が曖昧だった。
- 対応内容: 「local / unit test 実行時のみ route が登録され、staging / production では
  route 登録自体が起きない」と書き直し、根拠行 (`routes/web.php:594`) を添えた。

## [Warning] `docs/app-integration-guide.md` §7 への追記が不十分
- 判断: **対応する**
- 根拠: 「不変条件として追記」だけでは運用手順が伝わらない。
- 対応内容: 追記する 4 ステップのチェックリスト (認可を置く位置 / API での `Gate::forUser` /
  exemption 登録 / `NestedRouteIdorDefenseTest` との併記 / 検証コマンド) を概念設計に明記した。

## [Warning] `ApiActorContext::$user` の non-null が型として表現されているか
- 判断: **対応する (実査で確認済みであることを明記)**
- 根拠: 実査の結果 `final readonly class ApiActorContext` の
  `public User $user` は**ネイティブ非 null 型**。`ReadsApiActor::apiActor()` の戻り値型も
  `ApiActorContext` (非 null)。PHPStan level 10 で `Gate::forUser(User)` が型保証される。
  production 型の補強は不要。
- 対応内容: 施策 2 に該当の一段落を追加した。

## [Suggestion] 使命整合 / スコープ / `response()->json()` 不使用の肯定
- 判断: 対応不要 (肯定的コメント)

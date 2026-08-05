全体判定: **CHANGES_REQUESTED**

設計の方向性は妥当です。特に「テナント境界 404」と「認可 403」を分け、`resolve*` を認可として数えない判断は正しいです。ただし、実装設計としては静的検出の堅牢性と API actor の team/role 評価条件に未確定リスクがあります。

## 1. 使命との整合性

[Suggestion]  
REST API 経由で viewer が Item を変更できる穴を塞ぐ点は、SOP・シナリオ・撮影資産の統制に直結しており North Star と整合しています。

[Suggestion]  
「変更系 route は認可か明示裁定が必須」という inventory 化も、今後の機能追加時に現場資産の権限境界を崩しにくくするため有効です。

## 2. 禁止事項違反

[Warning]  
`ControllerAuthorizationExemption` を `app/Enums/Security/` に置く方針は、実行時アプリコードにテスト専用 inventory を混ぜる可能性があります。禁止事項そのものではありませんが、設計上の責務が曖昧です。

修正提案:  
本番コードから参照しないなら `tests/Architecture/Support/` などテスト配下に置く方が自然です。既存の `NestedRouteDefenseMode` が本当に `app/Enums/Security/` にあり、同じ設計思想なら踏襲可ですが、その場合は「セキュリティ不変条件の型定義として app に置く」理由を明記してください。

## 3. 実現可能性

[Warning]  
Reflection でメソッド範囲を取り、文字列マーカーで `Gate::authorize` 等を検出する方式は実現可能ですが、コメント・文字列・未到達コード・別メソッド呼び出しで誤検出する余地があります。

修正提案:  
最低限、`token_get_all()` 等でコメントと文字列を除外して検出してください。可能なら PHP Parser / AST で `Gate::authorize`, `Gate::forUser(...)->authorize`, `$this->authorize` の call expression を見る設計に寄せるべきです。deny-by-default gate は「誤って合格」が最も危険です。

[Warning]  
`can:` middleware の検出はハンドラ本体ではなく route action middleware 側を見る必要があります。Reflection ソース走査だけに寄せると middleware 認可を取りこぼします。

修正提案:  
候補 route ごとに `Route::getAction('middleware')` と gather された middleware を検査し、`can:` を body marker とは別経路で判定する、と明記してください。

[Warning]  
`Gate::forUser($actor->user)` は正しい方向ですが、Laratrust の `strict_check=true` と `laratrust_team_id` 明示不変条件に照らして、API actor の team/organization 文脈が本当に policy に渡るかが未確定です。

修正提案:  
`ItemPolicy` / `ProjectPolicy::update` が `$project->organization` 由来の team id を明示して role 判定していることを詳細設計で確認し、Feature テストに「actor の current organization が別でも、URL 上の project organization で判定される」ケースを入れてください。

## 4. 期待効果の妥当性

[Suggestion]  
主張している効果は合理的です。特に API の viewer write を 403 にする効果は直接的で、Architecture test による将来漏れ防止も妥当です。

[Warning]  
ただし「変更系 route の認可漏れが構造的に不可能」は少し強い表現です。静的マーカー検出である以上、「認可呼び出しはあるが対象が違う」「policy が常に true」「誤った actor を渡す」までは防げません。

修正提案:  
効果表現を「変更系 route に認可判断または明示裁定が存在しない状態を検出できる」に弱め、認可内容の妥当性は Feature / Policy test の責務と明確化してください。

## 5. リスク

[Warning]  
exemption 理由が増えるほど gate が形骸化するリスクがあります。今回の 12 件は概ね妥当ですが、将来 `SelfScopedResource` や `NoAuthorizableSubject` が安易に使われると穴になります。

修正提案:  
exemption には route 名、handler、理由 enum、具体的根拠、守っている不変条件を必須にし、理由文字列の空欄禁止だけでなく「同一 enum の使い回しでも route ごとの説明必須」にしてください。

[Warning]  
`debug.login-as` の説明に「route 登録自体が起きない」とありますが、条件文の記述が「local または unit test のときだけ登録される」という意味なら文面が逆に読めます。

修正提案:  
「production/staging では route 登録されない。local/unit test のみ登録」と明確に書き直してください。

## 6. スコープの適切さ

[Suggestion]  
GET route、vendor route、既存 policy ロジックの妥当性をスコープ外にした判断は適切です。今回の目的は「変更系 route の認可入口の網羅性」なので、広げすぎていません。

[Warning]  
`docs/app-integration-guide.md` §7 への追記はよいですが、今回の設計だけで「不変条件」として固定するなら Architecture test の名前・inventory 更新手順・exemption 追加基準まで書く必要があります。

修正提案:  
ドキュメントには「新規 POST/PUT/PATCH/DELETE route 追加時のチェックリスト」を短く追加してください。

## 7. 型安全性

[Warning]  
API の `Gate::forUser($this->apiActor($request)->user)` は型安全性の核心ですが、`ApiActorContext::$user` の non-null 保証が型として表現されているかが重要です。PHPDoc だけだと PHPStan level 10 で弱い可能性があります。

修正提案:  
`ReadsApiActor::apiActor()` の戻り値型と `ApiActorContext::$user` の型を確認し、`User` non-null がネイティブ型または厳密な value object で表現されていることを前提条件にしてください。必要ならテストではなく production 型を補強する方針を詳細設計に入れてください。

[Suggestion]  
`response()->json()` を追加しない方針、既存 `ApiErrorResource` envelope を使う方針は規約に沿っています。

---

結論として、改善の狙いと裁定は概ね承認可能ですが、**静的検出の誤合格対策**と **API actor の team 文脈での policy 評価**を設計に織り込むまで APPROVED にはできません。
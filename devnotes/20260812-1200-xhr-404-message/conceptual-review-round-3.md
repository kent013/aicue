全体判定: **APPROVED**

[Critical] なし。

[Warning] なし。Round 2 の主要懸念は解消されています。collapse の安全境界を prefix inventory から切り離し、`api/*` 以外の `expectsJson()` 404 に全面適用した整理は妥当です。機械向け prefix の漏れが情報露出へ直結しなくなった点が重要です。

[Suggestion] テスト契約6項目は概念設計として十分です。ただし項目4の「既存応答を維持」は、詳細設計で具体的な代表経路と assertion に分解してください。少なくとも status、Content-Type、body shape、主要フィールドを固定し、単に status だけを見るテストにしないことが必要です。401については `AuthenticationException` による Inertia history破棄も回帰対象に含めます。

[Suggestion] OAuthについては、項目5の「仕様内エラー」に加えて、次の2種類を区別すると契約が明確になります。

- OAuthレンダラが確定する仕様内エラー: 既存のプロトコル応答を維持
- OAuth配下で既定404へ落ちる経路: `{"message":"Not Found"}` へcollapse

これにより「専用レンダラを壊さない」と「404露出を残さない」の両方を直接証明できます。

[Suggestion] JsonResourceの具体化時には、禁止事項4を守るだけでなく、次の応答契約も確認してください。

- bodyが厳密に `{"message":"..."}` であり、`{"data":{...}}` にwrapされない
- statusが404のまま
- Content-TypeがJSON
- 元例外のmessageやクラス名がシリアライズされない

既存Resourceを流用してshapeが変わるなら、用途を表す専用JsonResourceを置く方が型と契約を明確にできます。

[Suggestion] `HttpExceptionInterface` でcallback引数を型指定すると、Laravelがレンダリング時点で渡す例外型だけが対象になります。詳細設計では、実際の`ModelNotFoundException`経路がcallback到達時に`NotFoundHttpException`となることをfail-firstテストで確認してから型を確定してください。status判定という方針自体は正しいです。

[Suggestion] 文言つき404の静的検査は、単純な文字列検索では別表現や変数経由を完全には捕捉できません。Architectureテストの保証範囲を「列挙した直接記法の変更検知」と明記し、最終的な安全性はFeatureテストが担うという現在の責務分担を維持してください。

使命との整合、禁止事項、存在秘匿、層2・層3の順序、スコープ、型安全性の各観点で実装を止める問題はありません。これは例外応答の表示内容だけを変更し、tenant解決・認可・middleware priorityには触れないため、存在秘匿の順序も変えません。**詳細設計およびfail-firstテスト作成へ着手可能です。**
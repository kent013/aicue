全体判定: **CHANGES_REQUESTED**

Round 1 の主要問題は概ね解消されています。特に web 側を priority pin で構造的に閉じる方針は妥当です。ただし、不変条件の適用範囲と Architecture テストに閉じ残りがあります。

### 1. 使命との整合性

[Suggestion] SOP・撮影素材を預かる前提となるテナント分離、認証防御、監査証跡の改善であり、North Star と整合します。機能追加ではなく、事業成立に必要な信頼基盤の修復として妥当です。

### 2. 禁止事項・セキュリティ不変条件

[Critical] API の `ResolveApiActor` による存在オラクルをスコープ外へ残す判断は、依然として不変条件と矛盾します。

設計自身が「cross-org 実在は 401/403、不在は 404」と認定しています。これは「子は親に属する」「認可より前に404」「アプリ都合で緩めない」に対する既知の違反です。壊れた actor だけが利用できるとしても、membership 剥奪直後のセッションなど、攻撃可能性を否定できません。

修正提案: 今回閉じるか、少なくとも次を明示して不変条件の例外ではなく期限付きリスク受容として扱ってください。

- actor が 401/403 を受ける時点で、その actor が他組織 ID を試行できることの実証
- セッション・APIキー・OAuth actor ごとの再現象限
- exploitability がない場合は、その理由を Feature テストで固定
- exploitability がある場合は本サイクルで修正
- TODO 登録だけでなく、期限と受容責任者を必須化

`remediation_todo` と `revisit_condition` だけでは、非交渉の不変条件を延期する根拠として不足します。

### 3. 実現可能性

[Warning] S2 の priority chain 自体は実現可能ですが、「`SubstituteBindings` の直後」という表現は厳密ではありません。

`appendToPriorityList()` は middleware を注入せず、対象 route に存在する middleware 間を整列するだけです。また、鎖の途中に存在しないクラスがある route でも、期待する相対順が得られることを Laravel の実装とテストで確認する必要があります。

修正提案: 少なくとも次の実 route を `Router::gatherRouteMiddleware()` で検証してください。

- API guard のみを持つ route
- web guard のみを持つ route
- `{project}` を持たない同一 group の route
- `verified`、2FA、課金、Inertia、ability、idempotency をそれぞれ含む route

[Warning] 「`SubstituteBindings` より前の短絡は構造的にオラクルになり得ない」は一般命題として強すぎます。

前段 middleware が raw route parameter を参照してDB照会すれば、binding 前でも存在依存の応答を作れます。現在列挙した framework middleware については妥当ですが、将来の独自 middleware まで安全とは証明できません。

修正提案: 「現在登録された前段 middleware は route resource の存在に依存しない」と限定し、その性質も inventory で固定してください。

### 4. 期待効果

[Warning] 「同型の穴の再発を検出」は、現在の S4 では完全には保証されません。

分類対象が `App\Http\Middleware\*` のみなので、vendor/framework middleware、closure middleware、別namespaceの middleware が境界内に追加されても未分類のまま通る可能性があります。今回の原因には framework の `EnsureEmailIsVerified` も含まれています。

修正提案: namespace を走査するのではなく、各 route の**解決済み middleware 列に実際に現れた全クラス**を分類対象にしてください。未分類の解決済み middleware は由来を問わず fail とする必要があります。

### 5. リスク

[Warning] S5 の production fail-fast は安全側ですが、デプロイ停止リスクが高いため、運用契約がまだ片側だけです。

`production:preflight` を「組み込めば」ではなく、本番デプロイ手順上の必須 gate にする必要があります。デプロイ自動化がリポジトリ外なら、owner・確認方法・rollback 条件をrunbookに固定してください。

修正提案: fail-fast導入前の完了条件に、本番の実 proxy hop、CIDR管理主体、変更時手順、preflight実行証跡を含めてください。安全側の代替は起動継続ではなく、デプロイ前検査の必須化です。

[Warning] S7 の「enum case が `app/` で参照される」検査は、記録経路の保証として弱いです。

label表示やmatch式で参照されるだけでも通り、subscriber未登録を検出できません。

修正提案: `SecurityEventType case → 購読イベント → recorder呼び出し` の構造化mapを正本にし、enum全caseとmap keyの完全一致、購読登録、Featureテストでの永続化を検査してください。

### 6. スコープ

[Critical] スコープ外1以外は概ね妥当ですが、`ResolveApiActor` は今回のHigh-1と同じ欠陥クラスなので別サイクル送りにできません。

修正提案: 詳細設計前に actor種類別の再現テストを作り、到達可能なら今回のS1/S2へ統合してください。到達不能が証明された場合のみ、Architectureテスト付きの期限付き受容へ落とせます。

MCP、通知ポリシー、既存IPの遡及修正を外す判断は妥当です。

### 7. 型安全性

[Suggestion] `TrustedProxiesConfigValidator` の `list<non-empty-string>`、raw値保持、enum利用はPHPStan level 10と整合します。

[Warning] env CSVの正規化結果だけを型付けすると、空要素や不正値が消失して検証不能になる可能性があります。

修正提案: parserの戻り値を、例えば「raw token列」と「検証済みproxy列」を分けた型付きDTO/value objectにし、validator通過前の値を `list<non-empty-string>` と断定しないでください。

結論として、web側の方針変更とS2の採用は承認可能です。承認に必要な残件は、`ResolveApiActor` の閉鎖または到達不能証明と、S4を「解決済みmiddleware全体」のdeny-by-default検査へ広げることです。
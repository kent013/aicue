## 再評価結果

Round 1のうち、PII境界テストとSuggestion 2件は解消されています。一方、施策4の必須条件はまだ満たされていないため、全体判定は据え置きです。

### `tests/Feature/Manual/SourceDocumentSummaryPropsTest.php`

判定: 問題なし

- 別組織SOPのsentinelが、閲覧可能な別組織manualのpropsへ混入しないケースが追加された。
- cross-org直接アクセスの404とも独立しており、PII非露出とテナント境界をそれぞれ固定できている。
- `uploadedAt`の完全一致により、ISO 8601契約も明確になった。

Round 1のWarning／Suggestionは解消済みです。

### `tests/js/pages/ManualsShow.test.ts`

判定: 問題なし

- 日時表示が実際にDOMへ配線されていることをassertしている。
- formatter自体の正確性はhelper側テストの責務であり、このページテストでは現在の検証方法で十分。

Round 1のSuggestionは解消済みです。

### `tests/js/pages/CaptureShow.test.ts`

[Warning] 共通collectorは改善ですが、詳細設計が要求する観測範囲にはまだ達していません。

`reload/visit/get/post`については、以下が改善されています。

- 通常フローの母集団非空
- 同一collectorによる正常系と違反系の判定
- 実mock入口へ違反destinationを流す負のコントロール
- `/app/...`を許可する正例

ただし、詳細設計は「mockする場合の`<Link>`クリック」やform helperを含む全visit入口を共通のbefore-event emitterへ流すことを要求しています。今回の実装はそれらを観測対象外とdocblockで宣言していますが、承認済み設計の検出範囲を実装側で狭める変更です。

また、これはbefore eventの観測ではなく、個別router mockの呼び出し履歴を後から結合するcollectorです。そのため、`<Link>`やform helperが内部の別router参照を使う場合を捕捉できません。

少なくとも次のどちらかが必要です。

- 詳細設計どおり、`<Link>`などを含むvisitを共通before-event emitterへ流す
- 観測範囲を狭めるなら、詳細設計を正式に改訂し、その範囲でもF-1-02の回帰を十分に検出できる根拠を示す

docblockへの保証外記載だけでは、詳細設計との不一致は解消しません。

### `phase-a-investigation.md`

[Warning] 記録の誇張は解消されていますが、施策4の必須調査は未実施です。

今回の記録は、次の点で正確になっています。

- 静的走査だけを実施したと明示
- live観測値が存在しないことを明示
- ハーネス起因と断定していない
- 409、`X-Inertia-Location`、resource type等の分類基準を整理

しかし、詳細設計はネットワーク上の最終responseを証拠の正本とし、Playwrightハーネスで実観測することをPhase Aの必須手順にしています。今回はその調査を実施していないため、「観測できなかった」のではなく「観測を試みていない」状態です。

したがって、設計上の分岐(c)にはまだ到達していません。静的走査で明示的な遷移呼び出しが見つからないことは有力な補助証拠ですが、middleware由来の409や`X-Inertia-Location`、ブラウザ／ハーネス起因のdocument navigationを分類できません。

施策5を実装しない判断自体は現時点でも合理的です。原因不明のまま包括ガードを追加しない方針には賛成します。ただし、それは「施策4が完了し、設計条件に基づいてスキップした」とはまだ評価できません。

クリーンな単一セッションで最低1回ハーネスを走らせ、以下を記録してください。

- request URLとresource type
- response status
- `X-Inertia`と`X-Inertia-Location`の実値
- `/app/`離脱の有無
- 結果に基づく(a)/(b)/(c)の分類

## 全体判定

- 施策1〜3: 承認可能
- 施策4: 未完了
- 施策5: 未実装方針は妥当だが、条件付きスキップの成立は未確認
- PHPStan、DTO/Inertia、DESIGN、Atomic Design、セキュリティ境界: 新たな問題なし

**全体判定: CHANGES_REQUESTED**
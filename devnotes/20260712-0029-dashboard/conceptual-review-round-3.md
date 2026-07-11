全体判定: **CHANGES_REQUESTED**

Round 2 の主要な設計問題は解消されています。ただし、Resolver の競合契約を固定するテストが不足しています。

### 1. 使命との整合性

[Suggestion] 利用者に再選択を要求せず、安全な所属組織へ自己修復する設計は「思考ゼロ」と整合します。

### 2. 禁止事項違反

[Warning] 必須テストが通常系と dangling ID の2ケースだけでは、条件付きUPDATEの重要な不変条件を保証できません。

修正提案: 少なくとも以下をFeatureテストへ追加してください。

- 候補membershipがUPDATE前に消失した場合、current IDを設定しない
- 観測後にcurrent IDが別組織へ変更された場合、その変更を上書きしない
- 条件付きUPDATEが0件だった場合、最新状態を再取得して解決する

### 3. 実現可能性

[Warning] 条件付きUPDATEが0件だった場合の再解決方法が曖昧です。`$user` インスタンスは古い `current_organization_id` やキャッシュ済みrelationを保持している可能性があります。

修正提案: UPDATE後は成否にかかわらず、relationキャッシュを破棄してDBからUserを再取得し、その最新値に対して所属確認を行う契約を明記してください。無制限な再試行は避け、再確認1回で解決不能ならnullを返す設計が安全です。

### 4. 期待効果の妥当性

[Suggestion] dangling IDを画面表示から排除しつつ自動復旧する効果は合理的に期待できます。

### 5. リスク

[Suggestion] 除名直後にDB上でdangling IDが一時的に残る可能性はありますが、すべての組織データ取得がResolverの所属確認済み結果を起点とするなら、許容可能な結果整合性です。

### 6. スコープの適切さ

[Suggestion] `removeMember` や既存組織切替処理まで同時改修せず、再利用可能なResolverとして境界を設ける判断は適切です。

### 7. 型安全性

[Suggestion] Resolverの戻り値を `?Organization` に固定し、条件付きUPDATEの結果を整数件数として扱えばPHPStan level 10で実現可能です。

承認に必要なのは、UPDATE不成立時のfresh再読込契約と、条件付きUPDATEの競合防御を固定するテスト3ケースの追記です。
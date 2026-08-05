全体判定: **CHANGES_REQUESTED**

`ResolveApiActor` を今サイクルで閉じる方針は妥当です。ただし、設計内の順序契約とAPI影響評価がv2のまま残っており、現状では実装・テストの正本が矛盾しています。

### 1. 使命との整合性

[Suggestion] テナント境界、IP信頼境界、認証資格の監査性をまとめて修復しており、North Starとの整合性は十分です。

### 2. 禁止事項・セキュリティ不変条件

[Warning] 既知の存在オラクルを0件にする方針となり、Round 2のCriticalは解消されています。

ただし、S4のexemption機構は不変条件を将来緩める入口になります。`risk`等を付ければ登録可能という設計では、非交渉の不変条件と整合しません。

修正提案: exemptionは通常運用可能な仕組みにせず、少なくとも期限、承認者、Feature再現テスト、期限切れfailを必須にしてください。初期0件なら、現時点では機構自体を作らず、新規例外をArchitectureテストで無条件failさせる方が規約に忠実です。

### 3. 実現可能性

[Critical] S1とS2で新しい実行順が矛盾しています。

S1は次のままです。

```text
Authenticate → Throttle → SubstituteBindings → resolve.api-actor
```

S2では次を正本としています。

```text
Authenticate → Throttle → resolve.api-actor → SubstituteBindings
```

修正提案: S1、期待効果、API影響、テスト計画をすべて後者へ統一してください。正しい契約は概ね次です。

```text
Authenticate → Throttle → ResolveApiActor → SubstituteBindings
→ API tenant guard → ability → idempotency
```

[Warning] `prependToPriorityList(SubstituteBindings::class, ResolveApiActor::class)` が既存priority上のAuthenticate・Throttleより後になるという主張は合理的ですが、相対挿入APIを重ねた結果を実routeで固定する必要があります。

修正提案: APIキーとOAuthの両actorについて、解決済み列全体の完全な順序をArchitectureテストで検査してください。

### 4. 期待効果

[Critical] 「応答が変わるのは1象限だけ」というAPI影響評価は、`ResolveApiActor`をbinding前へ移すことで成立しなくなります。

actor解決に失敗するリクエストでは、少なくとも次が変わります。

| 対象ID | Before | After |
|---|---|---|
| 不在 | bindingの404 | actor解決の401/403 |
| 他組織実在 | actor解決の401/403 | actor解決の401/403 |

存在オラクルは閉じますが、不在IDに対するエラー優先順位は変化します。これは既存クライアントや監視に対するエラー契約変更です。

修正提案: actor状態ごとの完全な象限表を追加し、「唯一の変化」という記述を削除してください。APIキー失効、OAuthセッション失効、membership剥奪を個別にFeatureテストへ登録すべきです。

### 5. リスク

[Warning] `ResolveApiActor`がroute bindingを読まないことだけでは、前倒しの無副作用証明として不足します。

前段化により、モデル不在でもactor解決処理が走ります。DBアクセス、監査ログ、認証状態更新、例外形式などがある場合、負荷・ログ量・レスポンス契約が変わります。

修正提案: 詳細設計で副作用、DB書込み、イベント発火、監査記録の有無を確認し、不在IDリクエストで副作用がないことをテストしてください。

[Warning] S5のfail-fast方針は、runbookと必須preflightを完了条件にしたことで受容可能です。ただし「運用者記入」が未完のまま実装完了扱いにならないよう、空欄を機械検出してください。

### 6. スコープ

[Warning] 「route parameterを1つ以上持つ全route」を既存の`NestedRouteDefenseMode`へ入れる設計は概念が広すぎます。

単一のページ番号、署名トークン、非モデル値、グローバル公開リソースはnested IDORではありません。無理に分類するとinventoryが形骸化します。

修正提案: route単位ではなくparameter単位で分類するか、`NonResourceParameter`、`PublicGlobalResource`等を明示し、テナント防御対象との混同を避けてください。

### 7. 型安全性

[Suggestion] `raw_proxies: list<string>`と検証済み`proxies: list<non-empty-string>`の分離はPHPStan level 10と整合します。

[Warning] S7の構造化mapは改善されていますが、「直接記録の呼び出し元クラス」が実際にrecord処理を呼ぶことまで型やmapだけでは保証できません。

修正提案: map完全一致に加え、直接記録caseにもFeatureテストまたは明示的な記録サービス経由のArchitecture制約を課してください。

承認に必要な主要修正は、`ResolveApiActor`前倒し後の順序契約とAPIエラー象限を設計全体で統一することです。実装方針そのものには重大な閉じ残りはありません。
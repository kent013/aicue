全体判定: **CHANGES_REQUESTED**

設計方針と主要な安全性問題は解決されています。残件は実装方針ではなく、設計内に残った契約上の矛盾です。

### 1. 使命との整合性

[Suggestion] North Starとの整合性は十分です。顧客のSOPを預かるためのテナント分離・認証防御・監査性を直接改善します。

### 2. 禁止事項・セキュリティ不変条件

[Suggestion] 既知例外を0件にし、exemption機構も撤廃したため、非交渉の不変条件と整合しました。Architecture/Featureテストまで完了条件に含まれています。

### 3. 実現可能性

[Warning] S3の「binding段 (= 全app middlewareより前)」は正確ではありません。今回の最終順序では`ResolveApiActor`などが`SubstituteBindings`より前に走ります。

修正提案: 「binding段で404となり、`recent-auth`を含むbinding後の短絡middlewareより前」と書き換えてください。

[Suggestion] priority APIの利用、解決済みmiddleware列の検査、TrustProxiesのfallback経路はいずれもLaravel 12で実現可能な設計です。

### 4. 期待効果

[Critical] API影響セクション冒頭に、撤回したはずの旧契約が残っています。

> 応答が変わるのは「短絡条件が成立」かつ「対象リソースがactorのテナントに属さない」という両方が同時に成立する1象限だけ

直後のactor失敗表では、不在IDも`404 → 401/403`へ変わるため矛盾します。

修正提案: この文を削除し、次のように置き換えてください。

> 変更は、actor解決成功時のテナント境界と、actor解決失敗時のエラー優先順位の2系統に分かれる。

象限表自体は妥当です。

### 5. リスク

[Warning] S4-6のpre-binding inventoryは、性質を「明記」するだけでは機械保証になりません。

修正提案: Architectureテストで保証できる範囲を限定して記述し、各登録middlewareについて少なくとも生route parameter参照の静的検査、または実在・不在IDで応答が同一になるFeatureテストを要求してください。間接的なDB参照まで完全に静的証明できない点も明記すべきです。

[Suggestion] `touchLastUsedAt()`の副作用を認識し、書込み・イベント・監査記録を詳細設計で洗い出す方針は妥当です。

### 6. スコープ

[Suggestion] parameter単位分類への変更で、nested IDORと非リソースparameterの概念混同は解消されています。MCP、通知、過去IPの遡及修正をスコープ外とする判断も妥当です。

### 7. 型安全性

[Suggestion] raw tokenと検証後proxyの分離、enum/mapの完全一致、全security eventのFeatureテストはPHPStan level 10およびDTO的な型境界と整合します。レスポンスDTO/JsonResource規約への新たな抵触もありません。

主要設計は承認可能な状態です。残る必須修正は、API影響セクションの「1象限」記述を削除して設計内の契約を完全に統一することです。
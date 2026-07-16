ありがとうございます。  
提示された詳細設計は全体として非常に高密度で、特に「並列起草の整合」「F-07再発防止」「金銭ドメインの冪等性」を強く意識できています。  
以下、指定形式でレビューします。

**施策別判定**
- P1: **REQUEST_CHANGES**
- P2: **REQUEST_CHANGES**
- P3: **APPROVE**
- P4: **REQUEST_CHANGES**
- P5: **REQUEST_CHANGES**
- P6: **APPROVE**
- P7: **REQUEST_CHANGES**
- P8a: **REQUEST_CHANGES**
- P8b: **REQUEST_CHANGES**

**主要指摘**

- [Critical] P2↔P4 の状態モデルが文書内で二重化しています（`EffectivePlan` 系と `OnboardingBillingState` 系が混在）。  
  - 影響: P4 の「反転1行化」が成立せず、実装時に `grantsAccess()` の責務が分散し、F-07再発余地が残ります。  
  - 修正案: 判定モデルを **1つに固定**してください。推奨は既述どおり `EffectivePlan` を唯一の判定源にし、P4では `GrandfatheredLegacyFreePlan` の扱いだけを変更。`BillingAccess`/middleware/Controller の参照先を単一化。

- [Critical] P4 の backfill 分類は良いですが、**「対象集合のSQL定義」と「Factoryテストの分類表」**の同値検証がまだ設計DoDに明示不足です。  
  - 影響: 条件漏れで free org 締め出し（F-07）または遮断中org救済（収益後退）が起き得る。  
  - 修正案: migrationテストに「SQLで更新されたID集合 == 分類表でgrandfather対象と判定されたID集合」を必須追加。

- [Critical] P5 の U1（負残高）で本文に「暫定(a) clamp」と「横断決定(b)債務保全」が共存しており、**仕様矛盾**です。  
  - 影響: 金銭ロジックの解釈がブレる。  
  - 修正案: どちらかに一本化。あなたの上位方針に従うなら **(b) 債務保全** で固定し、`availableTrueBalance` は判定用非負を維持しつつ、会計残高DTOに `debt` を明示する設計へ。

- [Critical] P7 の `PlanCode::Enterprise` 除外分岐は、D1（3case）と矛盾。  
  - 影響: PHPStan `alwaysFalse` で落ちるリスクを自分で作っている。  
  - 修正案: `normalizeRaw` から Enterprise 特判を削除し、`tryFrom` 結果のみで正規化。

- [Critical] P8a は高リスク機能だが、`reconcile` 停止時の運用要件が「注意喚起」止まり。  
  - 影響: 「課金済み未付与」の長期滞留。  
  - 修正案: DoDに「`billing:reconcile-auto-recharge` 監視アラートの実装/既存監視への接続確認」を必須化（設計項目として明文化）。

- [Warning] P1 の `/pricing` 露出制御は D10で方針確定していますが、P1本文のリスク欄に未決風の記述が残っています。  
  - 修正案: 未決文言を削除し、`plans.is_active`移植採用で確定記述に更新。

- [Warning] P3 の route スコープ表記が箇所により `organizations.onboarding.*` と `onboarding.*` で揺れています。  
  - 修正案: D6の current-org 前提に合わせ、最終route名を1系統に統一して全セクションの参照を置換。

- [Warning] P8b の「disabled禁止」適用は妥当ですが、`PurchaseTickets` と `PlanCard` 双方で同じUX原則を再定義しており重複。  
  - 修正案: 共通UI規約として1節に集約し、各画面は参照のみへ。

- [Suggestion] U1〜U4は妥当。特にU1を人判断に上げた切り分けは正しいです。  
- [Suggestion] D12（quota silent退行防止）は非常に良い指摘で、必須 invariant テスト化すべきです。  
- [Suggestion] P4のF-07再発防止は「導線先が存在」「遮断理由が見える」「ループしない」の三点をDoDで固定しており良いです。

**全体判定**
- **CHANGES_REQUESTED**

優先修正はこの5点です:  
1) 判定モデル単一化（P2/P4）  
2) backfill集合同値テスト明文化（P4）  
3) U1仕様の一本化（P5）  
4) Enterprise分岐矛盾解消（P7）  
5) reconcile監視をDoD化（P8a）

必要なら次に、上記5点を反映した「差分版の設計文（置換文面）」をそのまま貼れる形で作成します。
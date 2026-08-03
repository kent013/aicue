## 施策 B-1: APPROVE

同一 deadline 内で着地と toast を観測できており、fail 分類も妥当です。

## 施策 C: REQUEST_CHANGES

- [Warning] 世代管理の実装は正しい一方、計画されたテストでは「古い secret が state に再格納されない」ことを直接観測できません。confirm 成功後は `confirming=false` のため、再格納されても DOM には表示されないからです。  
  修正案: 旧取得を保留 → reset → 新取得を開始 → 新 secret を解決して表示 → 旧取得を後から解決、の順にし、新 secret が維持され旧 secret が表示されないことを固定してください。これで後着優先と reset 無効化を一つの振る舞いとして検証できます。

## 施策 A-1 / A-2: REQUEST_CHANGES

- [Warning] B-2を適用しない場合、`ToastContainer.onDestroy(clearToasts)` も消去境界として残るため、`DESIGN.md` の「消去境界の正本 = 未認証 layout 初期化」と実装が一致しません。認証面のページ遷移でも toast が消去されます。  
  修正案: B-2を適用した場合のみ「一本化」と記載するか、B-2を条件付きではなくA-2の一部として常時適用してください。後者の方が設計状態を一意にできます。

## 施策 B-2: REQUEST_CHANGES

上記のとおり、条件付き適用では最終的な toast lifecycle 契約が二通りになります。Browser結果はバグ仮説の検証材料として使いつつ、cleanup境界の正規化自体は無条件に行う設計が整合的です。

## 全体判定

**CHANGES_REQUESTED**

残件は実装ロジックではなく、世代管理テストの観測可能性と、最終的な lifecycle 契約を一意にする点です。
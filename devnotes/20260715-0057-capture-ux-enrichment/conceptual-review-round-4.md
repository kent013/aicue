全体判定: **APPROVED**

### 1. 使命との整合性

[Suggestion] 撮り直し削減・詰み回避・テイク継続性に直結しており、North Star と整合しています。

### 2. 禁止事項違反

[Suggestion] capability 非対応時および phase 上操作不能な場合のボタン非表示は、禁止事項 #8 の disabled UI に該当しません。

### 3. 実現可能性

[Suggestion] `exact` 制約、取得後検証、資源競合時の段階的切替・復旧により、モバイル端末差を考慮した実現可能な設計です。

### 4. 期待効果

[Suggestion] 中核効果と補助効果が適切に整理され、効果の主張も妥当です。

### 5. 後退リスク

[Suggestion] `pausing` / `resuming` による操作排他、イベント確定遷移、全 phase からの `onstop → idle` 収束により、主要な競合リスクが設計上カバーされています。

### 6. スコープ

[Suggestion] 1〜4 採用・横持ち全面刷新の除外は、v1 の価値と実装リスクのバランスが取れています。

### 7. 型安全性

[Suggestion] 過渡状態を含む union、純関数による可否判定、exhaustive switch、エラー型分離は TypeScript strict に適合します。

詳細設計では、phase 遷移表、カメラ切替の各失敗段階、duration 二重加算防止、preview 排他をテストケースへ落とし込めば十分です。
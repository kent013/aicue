## 施策別判定

### 施策 1: `capture.account` route の追加

**APPROVE**

route parameter を持たない設計、既存 middleware group 内への配置、IDOR・throttle・認可ゲートの対象判定はいずれも妥当です。

### 施策 2: 表示専用 controller の追加

**APPROVE**

共有 Inertia props を再利用し、ページ固有 props を追加しない判断は適切です。`resolveMemberCurrentOrganization()` による在籍確認、PHPStan level 10 の型安全性、`SeoManager` の利用も既存パターンと整合しています。

### 施策 3: アカウント確認画面の追加

**APPROVE**

Round 1 の指摘は解消されています。特に、送信中テストの契約を Button atom の `loading`/`disabled` に正しく限定した点、URL直書きの既存規約を確認した点、nullable props を偽の値で補完しない点は妥当です。

DESIGN.md の token、Atomic Design の依存方向、Lucide、Inertia logout 経路にも違反はありません。

### 施策 4: 撮影一覧からの入口

**APPROVE**

`PageHeaderSection` への置換について、ラッパーの実装と既存テストの検査内容まで確認されており、後退リスクは十分に整理されています。

### 施策 5: ログアウト目録・docs 更新

**APPROVE**

deny-by-default inventory とドキュメントの両方を更新する設計で問題ありません。docs が機械走査対象外である保証範囲も正確に記述されています。

### 施策 6: bug-hunt 目録更新

**APPROVE**

生成差分の採用基準が明確になりました。無関係なドリフトが発生した場合に実装を停止する判断も、検査を赤のまま残さない運用として妥当です。

### 施策 7: テスト

**APPROVE**

Round 1 の指摘はすべて解消されています。

- project role が認可条件ではないことをテスト名とコメントで明示
- 非所属というテスト前提を relation query で検証
- 既存テストと同じ Inertia mock 方式を採用
- 内部IDの非描画テストに衝突しにくい値を使用
- `ProjectRole` の正しい名前空間とヘルパのシグネチャを確認
- Feature、UI、Architecture、bug-hunt inventory の各層を網羅

RefreshDatabase、Factory、PHPStan level 10、Inertia props の契約にも適合しています。

## 全体判定

**APPROVED**

残る `[Critical]` / `[Warning]` はありません。Round 1 の指摘は、単なる文言修正ではなく、テストが保証する契約と実装上の保証範囲を揃える形で解消されています。この詳細設計で実装へ進めます。
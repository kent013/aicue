### `tests/Support/AuthorizationMarkerScanner.php`

APPROVED。

`nestedFunctionMask()` の範囲計算に、直下の認可を誤ってマスクする経路は確認できません。

- メソッド／Closure route の最初の `function`・`fn` は外側として保持される
- 通常クロージャは対応する `{}` まで除外される
- arrow function は括弧・配列・波括弧の深さを追跡している
- 解析不能時に広く除外する挙動も deny-by-default と整合する

即時実行クロージャや同期 callback 内の認可も除外されますが、これは誤合格ではなく保守的な不合格です。必要なら認可をハンドラ直下へ移すことで解消できるため、セキュリティ gate として妥当です。

### `tests/Unit/Architecture/AuthorizationMarkerScannerTest.php`

APPROVED。

ネスト内の認可を除外する negative test と、直下の認可を維持する positive test の両方向が固定されています。guard 側にも同じマスクが適用される点も検証されています。

### `tests/Architecture/ControllerAuthorizationGateTest.php`

APPROVED。

「すべての guard が最初の認可より前」という契約が明文化され、実装の比較方法と一致しています。

### 到達不能分岐

責務外とする線引きを受け入れます。

トークン走査だけで一般的な到達可能性を正しく判定することはできません。`if (false)` など一部だけを特別扱いすると、不完全な制御フロー検査に見かけ上の保証を持たせることになります。限界を明記し、Architecture テストを入口検査、Feature テストを実挙動検査とする分担は妥当です。

ただし、各新規変更系 route の認可内容には対応する Feature テストが必要という運用を維持することが前提です。

追加の Critical / Warning はありません。

**全体判定: APPROVED**
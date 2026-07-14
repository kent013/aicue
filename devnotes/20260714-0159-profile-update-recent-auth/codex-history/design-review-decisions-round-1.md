# 対応マトリクス: design-review Round 1

## [Critical] S6 case 5 の dataset 境界が曖昧（string 変更を混ぜると 409/302 と衝突）
- 判断: 対応する
- 根拠: case 5 は「gate されない no-gate 分岐」の証明。string かつ変更ありを混ぜると middleware が
  委譲 → 409/302 になり期待 (422) と衝突する。正当。
- 対応内容: case 5 の dataset を厳密に「(a) `email` 欠落」「(b) `email` 非 string (配列)」の 2 種のみに
  限定し、string 値を含めないことをテスト名/データセット名で明示。gate 対象 (string 変更) は case 1a/1b が担う。

## [Warning] S3 attachRecentAuthToSensitiveRoutes の肥大化
- 判断: 対応する
- 対応内容: `appendMiddlewareIfMissing(RouteCollection $routes, string $name, string $alias): void`
  ヘルパを切り出し、無条件群 / 条件付き群の双方で再利用。

## [Warning] S5 initialUser.email 基準比較のズレ
- 判断: 対応する
- 根拠: 連続操作 (email 変更成功後さらに編集) 時、baseline が古いと precheck 判定がズレる。
  安全性はサーバ最終ゲートで担保されるが UX 改善のため対応。
- 対応内容: profile 更新成功時 (`onSuccess`) に baseline email を最新値へ更新する `$state` を導入し、
  連続操作の判定ドリフトを抑制。

## [Warning] S6 case 2 の 302/303 揺れ
- 判断: 対応する
- 対応内容: 最終 assertion を `assertRedirect(遷移先)` 主体にし、status の 302/303 断定を避ける
  (Fortify `ProfileUpdatedResponse` 実装に追従)。email 不変 + name 更新 + `Notification::assertNothingSent()` を併せて固定。

## [Warning] S6 1a に email_verified_at 不変 assertion 追加
- 判断: 対応する
- 対応内容: 1a/1b の遮断ケースに `email` 不変 + `email_verified_at` 不変 + `assertNothingSent()` を明記。

## [Suggestion] S7 viaRemember=false 対照 + put 呼び出し回数
- 判断: 採用
- 対応内容: listener テストに「viaRemember=false → stamp する」対照ケースを追加。client テストで
  stale 時の `put` 呼び出し回数 (再認証後に 1 回) を検証し二重送信回帰を捕捉。

## [Warning/横断] EmailChangeTest を必須回帰に明記
- 判断: 対応する
- 対応内容: 施策一覧の注記に、`tests/Feature/Auth/EmailChangeTest.php` を本タスクの必須回帰
  (旧アドレス通知 + email_verified_at null 化 + 重複 email 不可) として実行セットに含めると明記。

## [Suggestion] S1 Assert vs LogicException の流儀統一
- 判断: 見送り（軽微）
- 根拠: `Assert::isInstanceOf` は PHPStan 補助として十分。委譲先 RequireRecentAuth は LogicException を
  使うが、条件付き middleware は $next を直接呼ぶ分岐のみで、Assert で足りる。統一の利益は小さい。
  実装時に既存流儀に合わせる余地は残す (どちらでも PHPStan L10 は通る)。

# 対応マトリクス: impl-review Round 1

Codex 最終判定: **APPROVED**（Critical 0 / Warning 1 / Suggestion 3）。

## [Warning] withPasswordPolicyAppEnv の env 共有干渉リスク（将来 Pest 並列モデル変更時）
- 判断: 見送る
- 根拠: 現状の Pest 並列モデルはプロセス分離であり、`app()->instance('env', ...)` は各プロセスの container instance を差し替えるのみ。`withPasswordPolicyAppEnv()` は `finally` で元 env へ必ず復元しており、テスト間の env 汚染は構造的に排除済み。Codex 自身も「現状の Pest 並列モデルでは通常問題化しにくい」と付言。仮定の将来変更に備えた先回り実装はオーバーエンジニアリング（AGENTS.md 思考原則 2）。
- 対応内容: 変更なし。

## [Suggestion] denylist 特殊 env 方針を docs 側へ同期
- 判断: 見送る
- 根拠: 定数 docblock に SSOT である旨と設計根拠（conceptual-design.md 参照）を明記済み。本 TODO のスコープは PasswordPolicy 単体 + テスト 2 ファイル（詳細設計「実装モード: standalone」）。docs 追記は本施策の受け入れ基準外。
- 対応内容: 変更なし。

## [Suggestion] reflection ではなく black-box バリデーション補助ケース追加
- 判断: 見送る
- 根拠: 詳細設計が「reflection は最小 1 本に限定し、env matrix の主判定は public 述語 shouldCheckPwned() の振る舞いで固定」と明記。black-box 追加は実 HIBP 照会（外部依存/flaky）を招くか、あるいは env matrix の Unit 判定と重複する。設計方針どおり据え置く。
- 対応内容: 変更なし。

## [Suggestion] assertNotSent を parse_url host 比較へ
- 判断: 見送る
- 根拠: `str_contains($request->url(), 'api.pwnedpasswords.com')` はホスト名を含む完全 URL 文字列に対する部分一致で、HIBP エンドポイント検出には十分かつ明快。任意の可読性改善であり機能差はない。
- 対応内容: 変更なし。

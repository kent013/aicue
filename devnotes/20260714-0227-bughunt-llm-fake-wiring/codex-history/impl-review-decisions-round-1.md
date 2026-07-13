# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED**（Critical なし。Warning/Suggestion のみ）。合議終了。
以下は Warning/Suggestion への判断記録。

## [Warning] FakeExternalsServiceProvider.boot(): install() のみで uninstall() を持たない (static 残留)
- 判断: 見送る（設計上の既知の許容事項）
- 根拠: bughunt.local は隔離検証専用の長寿命プロセスで、実 API 検証用途を持たない前提（詳細設計 施策 3 リスク欄に明記済み）。static `Prompt::$fake` は bughunt プロセスの寿命全体で install されたままが正しい挙動（HTTP serve / queue worker いずれでも fake 有効）。テスト境界のリークは 5-4 の afterEach と tests/Pest.php で担保。
- 対応内容: boot() の docblock に「testing/local を除外する理由（static 占有回避）」は既に記載済み。追加変更なし。

## [Warning] CannedPromptFake.latestRecordedMessages(): is_array のみで $last['messages'] にアクセス
- 判断: 見送る
- 根拠: 親 `PromptFake::$recorded` は `array{prompt_class, messages, ...}` 型で PHPStan L10 が形状を保証しており、`is_array($last)` narrowing 後の `$last['messages']` は静的に型付けされる。`isset()` 追加は PHPStan 的に常時 true となり冗長。vendor schema drift は施策 5 の record 依存テスト（recorded() 件数検証）が検出する。
- 対応内容: 追加変更なし。

## [Suggestion] pipeline E2E に Http::assertNothingSent() 相当を追加
- 判断: 対応する
- 根拠: 詳細設計 5-5 が「Http stray なし」を検証目標に挙げており、fake 分岐が event 非発火であることの直接的な固定になる。低コスト・高価値。
- 対応内容: `CannedAnalysisPipelineTest` に `Http::assertNothingSent()` を追加。green 確認済み（bughunt から外部 HTTP に漏れないことを固定）。

## [Suggestion] forMessages() の map() 複数呼び出しをローカル変数化 / 例外文ヒント追加 / captureMessages 単一実行強調 / (none) 検証 / bughunt ケースで recorded 件数検証
- 判断: 見送る（過剰）
- 根拠: いずれも可読性・調査性の微改善で、現行テストが挙動を十分固定している。思考原則「不必要な複雑化を避ける」に従い、APPROVED 済みの範囲で追加改変はしない。map() は 3 回呼ばれるが小さな配列生成で実害なし。captureMessages は docblock で「1 回だけ実行」を明記済み。
- 対応内容: 追加変更なし。

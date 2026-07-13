# 対応マトリクス: conceptual-review Round 1

## [Critical] 実現可能性: 成功判定を serve に限定するのは危険 (queue worker 経路)
- 判断: 対応する
- 根拠: `RunManualAnalysis` は `ShouldQueue`。bughunt は `QUEUE_CONNECTION=sync` (同一 HTTP プロセス実行) の構成もあれば、T018 で追加した専用 queue worker で async 実行する構成もある。いずれにせよ `FakeExternalsServiceProvider::boot()` は **全アプリプロセス (HTTP serve / queue worker / artisan)** の bootstrap で走るため、fake は両経路で有効。指摘のとおり成功判定の主語を「serve」から「bughunt の全アプリプロセス」に是正する。
- 対応内容: 概念設計の H1・成功判定を「HTTP プロセス + queue worker の双方で実 API に出ない」に修正。boot() が全プロセスで効くこと・queue 経路の検証を必須にする旨を追記。

## [Critical] リスク: LLM allowlist に local を含めるのはスコープ過大
- 判断: 対応する
- 根拠: 課題は bughunt 隔離環境の 401。`local` 開発 serve まで runtime static fake を広げると手元の実 API 検証が canned に潰される。`Prompt::$fake` はプロセスグローバル static で影響が広い。
- 対応内容: LLM runtime allowlist を **`['bughunt.local']` のみ**に絞る (local を削除)。将来 local 必要時は別フラグ opt-in と明記。

## [Warning] 禁止事項/テスト: 回帰テストを必須成果物に明記
- 判断: 対応する
- 対応内容: 成果物に (a) testing harness 非破壊回帰テスト (b) bughunt.local 相当で 3 DTO が決定論 JSON を通過する統合テストを必須と明記。

## [Warning] signature の変更耐性が弱い
- 判断: 対応する (一部反論)
- 根拠: vendor (`kent013/laravel-prism-prompt`) の `PromptFake::record()` は `messages / provider / model` のみ記録し、YAML の `name` を fake 解決時に渡さない。よって「YAML の機械可読 stable id で分岐」は vendor 改修なしには不可 (vendor 改修はスコープ外)。したがって system message signature を採るが、指摘どおり**抽出ロジックを 1 箇所に閉じ、各 prompt と signature の 1:1 対応をテストで固定**する。
- 対応内容: signature 抽出を canned 応答クラス内の単一メソッドに閉じる旨、drift-guard を「実 prompt render → signature 一意一致 → DTO 通過」の 1:1 テストにする旨を明記。

## [Warning] 期待効果「S3 全域で発見できる」は言い過ぎ
- 判断: 対応する
- 根拠: fake 分岐は `PromptExecutionCompleted` 非発火 = `llm_call_logs` 非生成。ログ依存の UI/監査/運用導線は本番同等に検証できない。
- 対応内容: 「AI 解析 3 段の主要 UX 導線を bughunt で通せる」に表現を下げ、ログ非生成による未検証領域を明示。

## [Warning] testing 除外だけでは不十分 (rename/resolver で harness 前提が崩れる懸念)
- 判断: 対応する
- 対応内容: 既存 Browser lane と同じ install/uninstall API を維持すること、`testing` では provider が一切 `Prompt::$fake` に触れないことを明文化し、回帰テストで固定する旨を追記。

## [Warning] rename のスコープを広げない
- 判断: 対応する
- 対応内容: rename 対象を LLM fake 配線に直接関わる 3 クラス (+ tests/Pest.php の import 追随) のみに限定と明記。周辺名称整理はしない。

## [Warning] 型安全性: fromLlmText を直接通す保証を前面に
- 判断: 対応する
- 対応内容: 各 canned 応答について「実 prompt render → fake 実行 → 該当 DTO `fromLlmText()` 成功」を 1 本のテストで担保する方針を前面に明記。

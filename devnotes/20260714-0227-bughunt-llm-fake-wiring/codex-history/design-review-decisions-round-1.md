# 対応マトリクス: design-review Round 1

## [Critical] 施策1: 全 YAML 件数一致の強制は将来 fake 対象外 prompt を誤 fail させる
- 判断: 対応する
- 根拠: 妥当。将来「LLM fake 不要な prompt」を追加した瞬間に件数不一致で fail する過剰拘束。
- 対応内容: テスト 5-2 から「全 YAML 総数 = signature 件数」の等値検証を削除。代わりに**登録対象 prompt の明示 allowlist (4 factory)** に対して 1:1 対応 (各 factory の render 済 system が ちょうど 1 signature に一致) を検証。加えて signature の**ペアワイズ非部分包含**を assert (衝突の静的防止)。

## [Critical] 施策5-2: factory を executeSync して recorded から system を取る方式が不安定
- 判断: 対応する
- 根拠: 妥当。record 順序・他メッセージ混入という fake 実行副作用に依存する。
- 対応内容: 5-2 は **reflection で `TextPrompt::buildMessages()` を直接呼ぶテストヘルパ** (`renderMessages(TextPrompt): array<int,Message>`) で実 YAML render 済 messages を取得し、`forMessages()` / signature 一致数を検証する方式に変更。recorded/executeSync 副作用に依存しない。DTO 通過テスト (5-1) のみ executeSync を使う (フルパス検証の意図)。

## [Warning] 施策1: forMessages 例外に system text 要約と競合 signature を含める
- 判断: 対応する
- 対応内容: 例外文面に「抽出した system text の先頭 N 文字」と「一致した signature キー一覧」を含める。調査コスト低減。

## [Warning] 施策3: Prompt::$fake static 残留のリーク検知
- 判断: 対応する
- 対応内容: Provider 発火条件テストで finally の `Prompt::stopFaking()` を必須化し、各ケース終了時に `Prompt::isFaking()===false` へ戻ることを確認するリーク検知を明記。

## [Warning] 施策5-4: config('testing.fake_externals') の明示復元
- 判断: 対応する
- 対応内容: `config(['testing.fake_externals' => true])` を使うケースは try/finally で原値復元を必須化（env 差し替えと同様のパターン）。

## [Warning] 施策5: stray 発生時 fail-fast の 1 ケース追加
- 判断: 対応する（既存担保の再確認として）
- 対応内容: 非破壊確認に「canned fake 未 install 下で LLM 呼び出しが StrayLlmCallGuard により fail-fast する」ことを確認するケースを含め、guard 経路が本変更で壊れていないことを固定。

## [Suggestion] 施策3: 定数名の対比 (PAYMENT_FAKE_ENVIRONMENTS / LLM_FAKE_ENVIRONMENTS)
- 判断: 対応する（provider ファイル内の private const のみ・低コスト・可読性向上）
- 対応内容: 既存 `ALLOWED_ENVIRONMENTS` を `PAYMENT_FAKE_ENVIRONMENTS` に改名し、新設 `LLM_FAKE_ENVIRONMENTS` と対比。private const で外部参照なし。

## [Suggestion] 施策4: install の用途コメント
- 判断: 対応する（用途は Browser lane「専用」ではなく Browser + bughunt の**両用**なので正しく明記）
- 対応内容: registrar / Pest install 箇所に「Browser lane と bughunt 実行時の両方で共有」の短文コメント。

## [Suggestion] 施策2: canned ごとに満たす DTO 制約をテスト名で明示
- 判断: 対応する
- 対応内容: 5-1 のテスト名を「{prompt} canned が {DTO}::fromLlmText を通過する」形式にし、どの制約を満たすかを可読化。

## [Suggestion] 施策1: YAML に machine-friendly token を埋める将来移行
- 判断: 見送る（今回は不採用・根拠を残す）
- 根拠: 全 YAML の system_prompt にトークンを埋める変更は本 item のスコープ（fake 配線）を超え、prompt 本文の改変は使命上の慎重さを要する。drift-guard テスト（5-2）で silent green は既に防げるため、現時点では自然文 signature + 衝突/DTO 通過テストで十分。将来 signature 増で保守が重くなった場合の移行候補として detailed-design に明記。

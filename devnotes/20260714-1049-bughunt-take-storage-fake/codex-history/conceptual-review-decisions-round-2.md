# 対応マトリクス: conceptual-review Round 2

## [Warning] リクエスト時 guard が route 登録条件より弱い（多層防御になっていない）
- 判断: 対応する
- 根拠: route 登録 predicate（bughunt.local ∨ (testing ∧ runningUnitTests)）と action guard（fake_storage && env∈allowlist）が不一致だと、route cache 残存時に testing 非 Unit Test HTTP で素通りする。
- 対応内容: `FakeStorageGate::enabled()`（純粋クラス）に predicate を一元集約し、route 登録側と signed route action 先頭 guard の**両方が同一メソッド**を参照する。

## [Warning] PUT 受信量に絶対上限がない（disk 枯渇の恐れ）
- 判断: 対応する
- 根拠: 署名 URL があれば想定超のストリームで disk を枯渇させられる。
- 対応内容: ストリーム読込中に**絶対容量上限**（`config('capture.max_take_bytes')` から導出 = 独立調整値を増やさない）を適用。超過で即時中断・一時ファイル削除・413。予約 expected size 照合とは分離した fake 基盤保護。

## [Suggestion] atomic move は同一 filesystem 前提
- 判断: 対応する
- 対応内容: 一時ファイルを `s3_fake` root 配下に作り atomic rename が成立するようにする。rename 失敗時は一時ファイル削除。

## [Suggestion] sidecar decode のエラー処理
- 判断: 対応する
- 対応内容: 欠損キー・不正 JSON・未知 schema version を例外（または「object 未完成」= null）として明示的に扱う。

## [Suggestion] 実装完了条件に Architecture/Feature 不変条件登録を含める
- 判断: 対応する（詳細設計テスト計画で明記）

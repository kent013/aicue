全体判定: **CHANGES_REQUESTED**

Round 1 の主要論点は大幅に改善されています。ただし、360 秒の根拠と強制終了時のチケット処理には、まだ保証を言い切れない箇所があります。

### 1. 使命との整合性

[Suggestion] timeout 起因の失敗解消に成功条件を限定した点は妥当です。文字化けを blocking follow-up としたため、North Star への貢献を過大評価していません。

### 2. 禁止事項違反

[Suggestion] Prism 直呼び、prompt 直書き、JSON 応答追加はなく、禁止事項への抵触は見当たりません。ストリーミング却下も現行制約下では妥当です。

### 3. 実現可能性

[Warning] deadline 判定が「残時間が正数か」だけだと、deadline 直前でも新しい 360 秒呼び出しを開始します。これは意図された `D + C` モデルですが、実装コメントとテストで明示しないと、実装者が「残時間を HTTP timeout に設定する」方式へ変えて保証を壊す可能性があります。

修正提案: Architecture/Feature テストで「deadline の1秒前に開始した呼び出しには C 全体を許容する」ことを固定してください。

[Warning] deadline の時計には wall clock より単調増加時計が必要です。`now()` や `Carbon` の時刻差では、NTP補正や時計変更で予算が伸縮します。

修正提案: elapsed time は `hrtime(true)` などで測定し、テスト可能な小さな clock abstractionまたは既存の時計注入方式を使ってください。

### 4. 期待効果・実測

[Critical] n=3、4,000 token の測定から、16,000 token に対する 360 秒を「生成し切れる ceiling」と断定するのはまだ強すぎます。次の点が未検証です。

- 4,000→16,000 token で生成速度が線形か
- 実際の3プロンプトでも同じ速度か
- TTFT、混雑時間帯、provider throttling の分散
- 360秒超過なら必ず `max_tokens` 到達でJSON不正になるという因果

特に「360秒超過 = どのみちJSON途中切れ」は成立しません。provider遅延により、16,000未満の正常JSONが360秒を超える可能性があります。

修正提案: 360秒は「保証 ceiling」ではなく「観測に基づく運用上限」と表現してください。可能なら実プロンプトの代表入力で測定し、少なくとも p50/p95 と output token 数を記録してください。CIでは生成レートを不変条件にせず、`timeout値の一致`と`budget順序`のみ固定するのが妥当です。

[Suggestion] 計測日にはタイムゾーンを付けてください。環境日付が UTC 2026-08-03 のため、`2026-08-04 JST` などと書かないと監査上は未来日付に見えます。

### 5. 時間 budget と会計リスク

[Warning] `job timeout = D + C + M` の完全一致には安全余白がありません。タイマー精度、PHP処理、シグナル配送、ログ処理が加わると、terminal処理中に強制終了する境界になります。

修正提案: `$timeout` を算術上限と一致させず、明示的な小幅余白を持たせてください。それが TTL 制約上できないなら、「360秒はTTLを据え置ける最大値」ではなく、TTL据え置き自体を再検討すべきです。

[Warning] 内部リトライが reserve/commit/release を触らない論証は妥当ですが、`$timeout` によるプロセス強制終了は別経路です。`$tries=1` だけでは、予約が確実に release されることの証明になりません。Laravelの timeout 時に `failed()` が呼ばれる条件、`$failOnTimeout`、stale回復との関係が設計にありません。

修正提案: timeout 強制終了時について、次をFeature/運用テストで固定してください。

- terminal commit 前の timeout では予約が release または stale 回復される
- commit 後の timeout で release されない
- `failed()` の重複実行でも会計状態が変わらない

[Warning] 「3段すべてに最低1回のフル ceiling」を保証するには、段間の検証・永続化など、LLM外処理の合計が C 未満という前提が必要です。抽出0.4秒だけでは十分な根拠ではありません。

修正提案: 保証を「LLM外処理が360秒未満である限り」に弱めるか、非LLM処理の上限を別の不変条件として明記してください。

### 6. retryable 例外

[Warning] cURL・429・529・413の写像は合理的です。一方、「genericだから5xxをすべて非retryable」は安全側ですが、単発502/503を救う期待効果は満たしません。例外がHTTP statusやprevious exceptionを保持している可能性も未評価です。

修正提案: generic `PrismException` からstatusを型安全に取得できないことまで確認してください。取得可能なら500/502/503/504だけをretryableにし、取得不能なら現在のfail-fastを明示的な制約として受容してください。retryable集合はArchitectureテストだけでなく、例外ごとのFeatureテストで固定すべきです。

### 7. スコープ・型安全性

[Suggestion] 既存 `withBoundedRetry` の拡張、共通deadline、typed config accessorという範囲は過大ではありません。段別予算やreason enumを見送る判断も妥当です。

PHPStan level 10についても、`config()->integer()`と例外型ディスパッチを使う限り、概念上の問題はありません。

承認条件は、主に「360秒を保証値ではなく運用上限として扱うこと」「job timeout境界に余白を設けること」「SIGALRM timeout時のreserve→release/commitを証明すること」の3点です。
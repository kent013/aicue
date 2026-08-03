全体判定: **CHANGES_REQUESTED**

主要なCriticalは解消されています。残る修正必須点は、job全体とpipeline deadlineの起点差だけです。

### 1. 使命との整合性

[Suggestion] timeout改善に成功条件を限定し、文字化けをblocking follow-upとした整理は妥当です。

### 2. 禁止事項違反

[Suggestion] Prism直呼び、prompt直書き、型のwiden、DTO境界違反はありません。ストリーミング却下も妥当です。

### 3. 時間 budget

[Warning] `$timeout` はjobの実行開始から計測されますが、deadlineのT0は`AnalysisPipeline::run()`入口です。その前にある`startJob()`、予約作成、行ロック、データ取得などの時間が式に含まれていません。

現在の式:

```text
job実時間 = D + C + M₁ + S
```

実際の式:

```text
job実時間 = P + D + C + M₁ + S
P = handle開始からAnalysisPipeline::run()入口まで
```

通常は短時間でも、DBロック待ちがあれば無視できません。また予約TTLは`startJob()`付近から進むため、T0との差は会計budgetにも関係します。

修正提案: 次のどちらかで閉じてください。

- T0を`RunManualAnalysis::handle()`入口へ移し、pipelineへdeadlineを渡す。
- pre-pipeline予算`P`を明示し、90秒の安全余白に含める。その場合は`P + S ≤ 90`が受容条件であると記載する。

値の変更までは不要です。90秒の余白にPを含める整理が最小です。

### 4. 実測・期待効果

[Suggestion] 360秒を実測起点の運用上限とした導出は妥当です。生成レートをCIにpinしない判断も適切です。

### 5. retryable例外

[Suggestion] 例外分類と`RequestException`によるstatus判定は妥当です。「5xx全般」ではなく、実際の集合`500/502/503/504`と表現を統一してください。

### 6. チケット会計

[Suggestion] SIGALRM時の即時releaseをbest-effort、cronを含む最終収束を保証とした修正は妥当です。Featureテストではcronの実行順序が逆でも最終状態が同じことを確認できると十分です。

### 7. スコープ・型安全性

[Suggestion] 実装範囲は適切です。typed config accessor、ローカル変数へのprevious格納、`instanceof` narrowingによりPHPStan level 10にも適合可能です。

`P`の扱いを時間budgetへ明記すれば、概念設計として承認可能です。
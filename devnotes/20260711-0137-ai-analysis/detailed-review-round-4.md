## 施策別判定

- 施策1〜5: `APPROVE`
- 施策6: `REQUEST_CHANGES`
- 施策7: `APPROVE`
- 施策8〜13: `APPROVE`

## 指摘

[Critical] 施策6: `Queueable` trait が既に `$connection` プロパティを定義しているため、Job側の `public string $connection = 'database-analysis';` はプロパティ定義の非互換によりtrait compositionエラーになる可能性があります。  
修正案: Job独自のプロパティ宣言を削除し、コンストラクタで `$this->onConnection('database-analysis');` を呼んでください。

```php
public function __construct(public readonly int $analysisJobId)
{
    $this->onConnection('database-analysis');
}
```

[Warning] 専用connectionを明示したJobは、`QUEUE_CONNECTION=sync` を上書きして `database-analysis` へ投入されます。「ローカルではconnection指定に関わらずsync」という運用ノートは正しくありません。専用worker未起動時はジョブが滞留します。  
修正案: 運用ノートを訂正し、ローカル同期実行はpipeline直接呼び出し、dispatch検証は`Queue::fake()`と明記してください。また、本番のworkerプロセス定義・デプロイ手順・監視対象に`database-analysis`を必須項目として登録してください。

[Suggestion] `AnalysisTimeBudgetInvariantTest` では、Jobのconnection名が`database-analysis`であり、そのconnectionのqueue名が`analysis`であることも固定すると設定driftを検出できます。

## 全体判定

`CHANGES_REQUESTED`

時間budget、strictな文字コード判定、tokenベースの呼び出し経路検査への対応は妥当です。残る修正は施策6のQueue接続指定方法と運用契約です。
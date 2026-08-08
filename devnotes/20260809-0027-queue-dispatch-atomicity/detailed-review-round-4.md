全体判定は **CHANGES_REQUESTED** です。M3とM7は解決していますが、M6にsync判定のfail-open、M9にLaravel QueueManagerの接続キャッシュとdispatcher cloneの不整合が残っています。

**M1: APPROVE**

設計の中心部分に問題はありません。

[Suggestion] M9ヘルパのdocblockにはまだ固定値`level >= 2`が残っています。本文どおり`baseline + 1`へ一本化してください。

**M2: APPROVE**

各dispatch経路にtx levelの主契約が用意され、rollback検査との役割分担も明確です。

**M3: APPROVE**

`TicketReserveDispatchAtomicityTest`が`AutoRechargeTriggerJob`を直接filterするため、mutation #13の赤化保証は成立します。#13と#13bの分離も正しいです。

array shapeにもPHPStan level 10上の問題はありません。

[Suggestion] リスク欄に「`DB::transaction()`の戻り値はmixed」とする古い表現が1か所残っています。PHPStan適合欄と同じ説明へ揃えてください。

**M4: APPROVE**

対象ジョブごとの接続設定を検査する前提で問題ありません。

**M5: APPROVE**

変更ありません。

**M6: REQUEST_CHANGES**

[Critical] R1〜R3の除外条件が接続名ではなく`driver === 'sync'`になっています。このため、例えば`database-analysis.driver = sync`にすると、その接続はR1〜R3をすべてskipします。R4が検査するのは`connections.sync`だけなので、pin済み接続をsyncへ差し替える構成が通ります。

修正案: sync除外は接続名で判定してください。

```php
foreach ($this->referencedConnections($defaultQueue) as $name) {
    if ($name === 'sync') {
        continue; // R4/R5が担当
    }

    $config = $connections[$name] ?? null;
    // 以降、driverは必ずdatabaseを要求
}
```

これにより、`database-analysis.driver = sync`はR1違反になります。次のテストとmutationも追加してください。

- pin済み接続のdriverがsyncならR1違反
- mutation: `database-analysis.driver`を`sync`へ変更するとR1テストが落ちる

R4の`sync.driver + after_commit`検査と、それ以外のfail-closed分岐は妥当です。

**M7: APPROVE**

`phpFilesUnder(list<string> $absoluteRoots)`と`runtimePhpFiles()`の分離により、本番母集団と一時fixtureが同じ列挙コードを通ります。独立したルート集合pin、Finderとの対称差、ルート単位0件failを合わせれば空振りも閉じています。

[Suggestion] `phpFilesUnder()`では、各入力について「絶対パスである」「存在するディレクトリである」を明示検査し、不正なら例外にしてください。docblockだけでなく負のテストを置くと契約が固定されます。

**M8: APPROVE**

契約反転とmutationの対応に問題はありません。

**M9: REQUEST_CHANGES**

[Critical] dispatcher clone方式は、Laravelのqueue connectionキャッシュと整合しない可能性があります。

Queueのイベント発火は常に`Event` facadeを引き直すのではなく、生成済みQueue connectionが保持するdispatcherを使います。そのため次の問題があります。

- queue connectionがcloneへのswap前に生成済みなら、clone側listenerが`JobQueueing`を捕捉しない
- swap中に生成されたqueue connectionはQueueManagerにキャッシュされ、capture後もclone dispatcherを保持し得る
- 提案された自己テストが`Event::dispatch()`だけを見る場合、実database queue経由のこの問題を検出しない

修正案: dispatcherを交換せず、元dispatcherへlistenerを追加し、capture終了後はclosureを不活性化してください。

```php
$active = true;
$records = [];

Event::listen(JobQueueing::class, function (JobQueueing $event) use (&$active, &$records): void {
    if (! $active) {
        return;
    }

    // 記録
});

try {
    $action();
} finally {
    $active = false;
}
```

グローバル`RefreshApplication`によりテスト終了時にdispatcher自体が破棄され、規約どおり1テスト1captureなら不活性listenerはそのテスト中に1個だけ残ります。既存listenerの削除、queue connectionのdispatcher差し替え、接続キャッシュの汚染がありません。

自己テストは少なくとも以下を実database queue経由で確認してください。

- capture中に`JobQueueing`を記録する
- capture前の既存listenerもcapture中・capture後に動く
- capture後に別ジョブをdispatchしてもrecordsが増えない
- actionが例外を投げても、その後recordsが増えない

mutation #18も「active=falseを削除する」に変更すれば、capture後の非記録テストが赤になります。

**M10: REQUEST_CHANGES**

M6のsync除外条件とM9の観測方式を修正した後、その内容へ同期すれば承認できます。

**Mutation・保証範囲**

mutation表は#18を除いて意図した赤化点に対応しています。M6のpin済み接続をsyncへ変える変異を追加する必要があります。

「保証しないもの」16項目は概ね正確です。ただし項目13の`queue.default='database'`は、項目16が説明するとおりpin済みジョブには直接効きません。項目13は「対象ジョブが使う接続がdatabase driverかつ`after_commit=false`」を主文にし、default変更は非pinジョブ向けの設定手段として位置づけると矛盾がなくなります。
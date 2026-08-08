全体判定は **CHANGES_REQUESTED** です。Round 2の主要修正はほぼ成立していますが、M3のオートリチャージトリガ経路に赤化保証の取り違えがあり、M9のlistener隔離にも将来の既存listenerを破壊する問題が残っています。

**M1: APPROVE**

設計上の追加指摘はありません。

**M2: APPROVE**

削除2経路と`RenderPipeline::finalize()`に対象ジョブ別のtx level検査が追加され、主契約と補助契約の区別も正しくなりました。

**M3: REQUEST_CHANGES**

[Critical] `TicketLedgerService::reserve()`内の`AutoRechargeTriggerJob::dispatch()`をtx外へ戻すmutation #13と、期待するテストが対応していません。

`AutoRechargeAttemptDispatchAtomicityTest`が観測するのは、名称・M3テスト計画上ともに`createAttemptLocked()`内の`ExecuteAutoRechargeAttemptJob`です。したがって、`reserve()`内の`AutoRechargeTriggerJob`をtx外へ戻しても、このテストは落ちません。

修正案: `AutoRechargeTriggerJob`についても対象ジョブをfilterした`baseline + 1`テストを追加してください。配置先は既存の`AutoRechargeTriggerTest`でも専用atomicityテストでも構いません。mutation #13の期待先もそのテストへ変更します。

array shapeによる`reserve()`の戻り値伝播は問題ありません。

**M4: APPROVE**

M9共通ヘルパの修正を前提として、webhook 2経路の検査計画は妥当です。

**M5: APPROVE**

D3による継承を含む検出も維持されています。

**M6: REQUEST_CHANGES**

[Warning] fail-closedの分岐は概ね閉じましたが、R4が`sync.after_commit === true`しか確認していません。`connections.sync.driver`が`sync`以外でも、既定接続が別ならR4を通過できます。

修正案: R4で次の両方を要求してください。

```php
is_array($sync)
&& ($sync['driver'] ?? null) === 'sync'
&& ($sync['after_commit'] ?? null) === true
```

`sync.driver`欠落・非string・`database`への変更に対するテストも1件追加してください。

`PINNED_CONNECTIONS`の抽出規則と対称差検査は有効です。

**M7: REQUEST_CHANGES**

[Warning] 独立した期待ルートpinにより、`RUNTIME_ROOTS`自体のdriftは閉じています。ただし`phpFilesUnder()`は「`base_path()`からの相対ルート」を受ける契約なのに、負のコントロールでは`sys_get_temp_dir()`配下のfixture rootを渡します。

`base_path($absolutePath)`のような実装になると、workspaceパスと絶対パスが連結されてfixtureを列挙できません。

修正案はどちらかです。

- `phpFilesUnder()`を絶対パスの`list<string>`を受けるAPIにし、本番側が`base_path('app')`等へ変換する
- 相対・絶対を明示判定し、絶対パスはそのまま扱う

前者の方が純関数として明快です。

テスト5b、ルート単位0件fail、fixture経路統合の組み合わせ自体は十分です。

**M8: APPROVE**

既存契約の反転方針に問題はありません。ただしmutation #13の期待先はM3指摘どおり修正が必要です。

**M9: REQUEST_CHANGES**

[Warning] baseline、対象ジョブfilter、接続ごとの`after_commit=false`確認は主契約を正しく実装できます。Laravel 12のdatabase queueにおける`JobQueueing`の発火点とも整合します。

一方、`Event::forget(JobQueueing::class)`はcapture以前から存在した同イベントのlistenerも削除します。「現時点でgrep 0件」は恒久的な安全性になりません。

修正案: 元dispatcherを保持し、そのcloneをcapture専用dispatcherとしてswapしてください。

```php
$original = Event::getFacadeRoot();
$isolated = clone $original;
Event::swap($isolated);

try {
    Event::listen(JobQueueing::class, $listener);
    $action();
} finally {
    Event::swap($original);
}
```

これは既存listenerを無効化しません。clone側にも既存listenerが引き継がれ、追加したlistenerだけがcloneとともに破棄されます。実装時にdispatcherがclone可能であることを小さなヘルパテストで固定してください。

[Suggestion] ヘルパのdocblockに残っている「level >= 2」は削除し、`baseline + 1`だけを正本にすると記述driftを防げます。

**M10: REQUEST_CHANGES**

上記M3/M6/M7/M9の修正を文書へ反映すれば承認可能です。

「保証しないもの」15項目は概ね適切です。追加するなら、`JobQueueing`観測が対象ジョブの実際の接続設定を正しく選択できていることにも依存する点です。特にdefaultを`database`へ変えても、ジョブ自身が`database-analysis`等へpinされている場合はdefault設定ではなくpin先の`after_commit`を検査する必要があります。

**Mutation表**

16件すべてが「1検査点だけを落とす」形ではありません。

- #2はR3とD4の2点を意図的に落とすため、単一検査点ではありません。
- #10の`appPhpFiles()`変異はM7テスト6や5には関係せず、主にShouldQueue母集団テスト7と既存inventoryが担当します。
- #11の`appPhpFiles()`から`app/Jobs`除外も、`runtimePhpFiles()`を使うM7テスト5ではなく、既存のShouldQueue母集団完全性検査が担当します。
- #13は前述のとおり期待先が誤っています。

表の目的を「各変異が少なくとも意図した検査で赤になる」に変更するなら、複数テストが落ちる#2は問題ありません。#10、#11、#13は実際に担当するテスト名へ修正が必要です。
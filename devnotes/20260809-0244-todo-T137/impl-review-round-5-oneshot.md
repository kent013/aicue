[Warning] `ShouldDispatchAfterCommit` が 0 件 pin の対象外です。  
`QueueDispatchAtomicityInventoryTest` / `QueueDispatchDeferralInventory` は `ShouldQueueAfterCommit` と `ShouldHandleEventsAfterCommit` を見ていますが、Laravel には event 自体を commit 後 dispatch へ回す `Illuminate\Contracts\Events\ShouldDispatchAfterCommit` があります。tx 内でその event を dispatch し、queued listener がぶら下がると、実際の queue enqueue は commit 後へずれます。これは D1/D2/D3/D5 のどれにも映らず、event class は `ShouldQueue ∪ Mailable` の母集団にも入りません。  
対応マトリクスでは決着済みになっていない新規論点です。0 件 pin が「どの層からも迂回できない」と主張するなら、少なくとも first-party event class の `ShouldDispatchAfterCommit` 実装も 0 件 pin するか、保証しないものとして明示する必要があります。

[Suggestion] D2 の表示契約が実装より狭いです。  
設計・テスト名は `DB::afterCommit()` 検出ですが、実装は `T_DOUBLE_COLON + afterCommit(` なら任意の `SomeClass::afterCommit()` を検出します。保守上の誤解を避けるなら、実装を DB facade 系に絞るか、テスト名・docblock を「静的 `::afterCommit()` 全般を禁止」に寄せるのがよいです。

全体として、tx 内 dispatch への移設・契約反転・behavioral test はかなり詰められています。ただし 0 件 pin の標準 Laravel 経路に抜けが残っているため、この確認ラウンドでは承認できません。

CHANGES_REQUESTED
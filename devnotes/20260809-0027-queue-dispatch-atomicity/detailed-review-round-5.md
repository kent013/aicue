全体判定は **CHANGES_REQUESTED** です。M6/M7は解決していますが、M9の不活性化mutationテストがPHP配列のcopy-on-writeにより偽グリーンになります。

**M1: APPROVE**

固定値の記述も解消されています。

**M2: APPROVE**

既知の各dispatch経路について主契約と補助契約が揃っています。

**M3: APPROVE**

`TicketReserveDispatchAtomicityTest`によりmutation #13を正しく検出できます。#13bとの責務分離も妥当です。

**M4: APPROVE**

問題ありません。

**M5: APPROVE**

問題ありません。

**M6: APPROVE**

sync除外を接続名で判定したことで、pin済み接続のsync化によるfail-openは閉じました。

R4も以下を独立して保証しています。

- `connections.sync`が配列
- `driver === 'sync'`
- `after_commit === true`

mutation #17と#19もそれぞれ異なる穴を検出します。

**M7: APPROVE**

絶対パス入力、存在ディレクトリ検査、独立ルートpin、Finder対称差、ルート単位0件fail、fixture経路統合が揃っています。母集団境界の偽グリーンは十分に閉じています。

**M8: APPROVE**

問題ありません。

**M9: REQUEST_CHANGES**

[Warning] 不活性化方式そのものは正しいですが、提案された次の自己テストはmutation #18を検出できません。

> capture後に別ジョブをdispatchしてもrecordsが増えない

`capture()`は`$records`を配列として返します。返却後にlistenerが参照先のローカル`$records`へ追記しても、PHPのcopy-on-writeによって呼び出し側が保持する返却配列とは分離されます。したがって、`$active = false`を削除してlistenerが動き続けても、返却済み配列の件数は増えず、テストが緑になる可能性が高いです。

修正案: 記録状態を小さな可変collectorオブジェクトにしてください。

```php
final class JobQueueingTransactionRecords
{
    /** @var list<array{job: string, level: int}> */
    private array $records = [];

    public bool $active = true;

    public function record(string $job, int $level): void
    {
        if ($this->active) {
            $this->records[] = ['job' => $job, 'level' => $level];
        }
    }

    /** @return list<array{job: string, level: int}> */
    public function all(): array
    {
        return $this->records;
    }
}
```

`capture()`はcollectorを返し、`finally`で`$collector->active = false`にします。自己テストはcapture後のdispatch前後で`$collector->all()`の件数を比較します。これならmutation #18で同じオブジェクトのrecordsが増え、確実に赤になります。

array shapeを使う現在のassert APIを維持したければ、`only()`には`$collector->all()`を渡せば足ります。collectorはテストSupport内部だけの機構なので、過剰なDTO化には当たりません。

[Suggestion] 「1テスト1capture」規約下では不活性listenerが残ること自体は許容できます。ただしlistenerがcollectorを保持するため、記録したjob payloadそのものは保持せず、現在のようにクラス名と整数だけを保存する設計を維持してください。

**M10: REQUEST_CHANGES**

M9のcollector方式へ記述を同期すれば承認できます。

**Mutation・保証範囲**

#1〜#17、#19は意図した検査点に対応しています。#18だけが上記copy-on-write問題により現状では赤化保証を持ちません。

「保証しないもの」16項目に重大な誇張はありません。項目13と16は内容がかなり重複していますが、誤りではありません。文書を短くするなら、13を観測原理、16をpin接続選択の注意として整理する程度で十分です。
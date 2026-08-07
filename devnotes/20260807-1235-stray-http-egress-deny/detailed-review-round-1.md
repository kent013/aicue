全体判定: **CHANGES_REQUESTED**

設計の方向性は妥当です。Laravel HTTP client の公式機構に乗り、`catch (Throwable)` で握り潰される経路まで accumulator で拾う設計は、AG-105 の必須要件に対して筋が通っています。ただし、自己検査と Architecture gate に修正必須の穴があります。

## S1: StrayHttpRequestGuard

判定: **APPROVE**

[Suggestion] `localhost` 許可は実用上問題ない一方、厳密には名前解決に依存します。Browser lane が 127.0.0.1 bind で足りるなら、将来 `localhost` を残す理由を定数コメントにもう少し明示しておくとよいです。

## S2: guard の自己検査

判定: **REQUEST_CHANGES**

[Warning] self-test が `tests/Pest.php` 側の global `beforeEach` 配線に依存しており、設計上の実装順序 `S2 → S1 → S3` と噛み合っていません。S1 実装後・S3 配線前にこのテストを実行すると guard が install されず、`Http::get('https://api.frankfurter.dev/...')` が実通信に進むリスクがあります。

修正案: `StrayHttpRequestGuardTest.php` 自身に明示的な `beforeEach` を置いてください。

```php
beforeEach(function (): void {
    StrayHttpRequestGuard::install($this->app);
});
```

S3 後は二重 install になりますが、S1 の冪等化を検証する設計とも整合します。

[Warning] case D の `127.0.0.1:9` は環境依存で flaky になりえます。port 9 が閉じている前提は強めです。

修正案: `stream_socket_server('tcp://127.0.0.1:0')` で一時ポートを確保し、閉じてからそのポートへ接続する、または「`StrayRequestException` ではない」ことを主眼にして例外型を過度に固定しない形へ寄せてください。

## S3: 3 レーンへの既定配線

判定: **APPROVE**

設計は妥当です。Feature/Unit、Architecture、Browser に同じ guard を張る方針、Browser lane の保証範囲の限定、LLM guard との flush 順の扱いも現実的です。

[Suggestion] 2 guard の flush で片方の詳細が落ちる点は受容でよいですが、将来の調査効率を考えるならコメントに「同時発生時は先に throw した guard のみ表示」と明記すると十分です。

## S4: deny-by-default Architecture gate

判定: **REQUEST_CHANGES**

[Warning] opt-out 検出が `preventStrayRequests(false)` の literal に寄りすぎています。`preventStrayRequests($flag)`、`preventStrayRequests((bool) 0)`、名前付き引数などで既定拒否を外せる余地があります。deny-by-default gate としては逃げ道が残ります。

修正案: `preventStrayRequests(` に引数がある呼び出しは、少なくとも literal `true` 以外を opt-out 候補として検出してください。実装を単純にするなら、`preventStrayRequests()` の無引数だけを許可し、それ以外は inventory 必須にするのが安全です。

[Warning] gate は配線文字列の順序を見ますが、`install` / `flush` / `reset` が本当に `beforeEach` / `afterEach` closure 内にあることまでは強く保証しません。

修正案: 既存方針を維持するなら、負のコントロールに「`beforeEach` と `afterEach` の間だが closure 外に install がある」fixture を追加してください。最低限、空振りリスクをテストで固定できます。

## S5: 既存記述の是正

判定: **APPROVE**

コメント是正と docs 追記の範囲は適切です。UI/frontend 変更はなく、DESIGN.md / Atomic Design 観点は非該当です。

## S6: 初回赤化テストの是正

判定: **APPROVE**

`Http::fake(['*' => ...])` に逃げず、実際に出た外部 URL を明示 fake する規律は妥当です。アプリコードを触らず、検出された不要通信を別 TODO に分離する判断もスコープ管理として正しいです。

最小修正は S2 の self-test 独立化と、S4 の opt-out 検出強化です。そこが直れば全体は approve できる設計です。
## 施策別判定

### 施策 0: APPROVE

Round 1 の指摘どおり、リテラル値だけでなく env 上書きへの後退も検出する設計になっている。

### 施策 1: APPROVE

接続明示、有限 timeout、全 database 接続の網羅という規則 1 の要件を満たしている。

### 施策 2: REQUEST_CHANGES

[Warning] `wt` と `conn_rt` の数値形式を確認せず、`[[ ... -ge ... ]]` で算術比較している。

空文字以外の不正値が入ると、意図した invariant failure ではなく Bash の算術評価エラーになり得る。

修正案:

```bash
if [[ ! "${wt}" =~ ^[0-9]+$ ]]; then
    t_fail "BUGHUNT_WORKER_TIMEOUTS[${conn}] が正の整数でない (${wt})"
elif [[ ! "${conn_rt}" =~ ^[0-9]+$ || "${conn_rt}" -le 0 ]]; then
    t_fail "config の retry_after が正の整数でない (${conn_rt})"
elif [[ "${wt}" -le 0 || "${wt}" -ge "${conn_rt}" ]]; then
    t_fail "規則 1 違反: ..."
fi
```

### 施策 3: REQUEST_CHANGES

[Critical] ケース 6 が Bash ファイル全文を検索するため、設計どおり実装すると常に失敗する。

変更後コメント自身に旧値の ``--timeout=1800`` が含まれているため、全文に対する `/--timeout(=|\s+)\d+/` がそれを検出する。self-test 内の説明コメントにも同じ表記がある。

修正案: 検査対象を `start_shard_workers` の関数定義、さらに実行コマンド部分に限定する。少なくともコメントを除去した関数本体に対して検査する。

```php
$function = extractBashFunction($source, 'start_shard_workers');
$commands = stripBashCommentLines($function);

expect($commands)->toContain('--timeout="${wtimeout}"');
expect($commands)->not->toMatch('/--timeout(?:=|\s+)\d+/');
```

self-test は既存の `wrk_def="$(declare -f start_shard_workers)"` を対象にしているため、この問題を回避できている。Architecture テストも同じ対象範囲に揃えるべきである。

[Warning] ケース 7 の `finally` で無条件に `clear()` すると、テスト開始前から設定されていた `DB_QUEUE_RETRY_AFTER` を破壊する。

修正案: 元の値を保存し、存在していた場合は `set()` で復元、未設定の場合のみ `clear()` する。

### 施策 4: REQUEST_CHANGES

[Critical] ケース 3 の許可条件が timeout site と矛盾している。

`connectionDeclarationSites()` は `timeoutProperty` と `timeoutAssignment` も返す一方、ケース 3 は「許可されるのは queued class 内の `onConnection` site のみ」としている。このままでは正当な `RunManualAnalysis::$timeout` と `RunManualRender::$timeout` も違反になる。

修正案: ケース 3 の母集団を接続関連 kind のみに限定する。

```php
$connectionKinds = [
    'onConnection',
    'viaConnections',
    'viaConnection',
    'connectionProperty',
    'connectionAssignment',
];
```

timeout 関連はケース 5 だけで検査する。

[Warning] ケース 5 が `app/` 全クラスの `$timeout` を対象にすると、キューと無関係なクラスの `$timeout` 代入まで禁止する。

規則 2 の対象は ShouldQueue クラスなので、`class` が目録キーに含まれる timeout site に限定すべきである。クラス外や非 queued クラスの `$timeout` は本不変条件とは無関係。

[Warning] クラス状態を単一の `classBodyDepth` だけで管理すると、匿名クラスやネストした宣言内の site を外側のクラスへ誤帰属させる可能性がある。

修正案: `{class名|null, bodyDepth}` のスタックでスコープを管理する。匿名クラスも `class=null` のスコープとして push し、その内部を外側の queued class に帰属させない。

### 施策 5: REQUEST_CHANGES

[Warning] `Closure::bind()` 案は PHPStan level 10 と null 安全性に問題が残る。

`Closure::bind()` は `Closure|null` を返すため、そのまま `$invoke(...)` はできない。またクロージャ内の `$this` と protected メソッド呼び出しを PHPStan が正しく型付けできない可能性が高い。

修正案: PHP 8.4 では `ReflectionMethod::invoke()` で非 public メソッドを呼べるため、こちらの方が明確である。

```php
$method = new ReflectionMethod(
    Worker::class,
    'markJobAsFailedIfWillExceedMaxAttempts',
);

$method->invoke(
    $worker,
    'database',
    $job,
    $maxTries,
    new RuntimeException('timeout'),
);
```

これなら Worker は `app('queue.worker')` を使え、constructor 依存回避という目的も維持できる。

### 施策 6: APPROVE

規則 1・規則 2 の独立性、環境別運用、Laravel 更新時の再確認事項まで明確である。

### 施策 7: APPROVE

既存コメントのドリフト修正として妥当で、追加の波及変更は見当たらない。

## 全体判定

**CHANGES_REQUESTED**

Round 1 の指摘は実質的に解消されている。ただし施策 3 の全文正規表現はコメント中の旧値を拾って必ず失敗し、施策 4 のケース 3 は正当な `$timeout` 宣言まで拒否する。少なくともこの2点の修正が必要である。
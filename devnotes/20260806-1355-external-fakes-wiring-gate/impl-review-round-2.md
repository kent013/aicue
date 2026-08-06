## 指摘

[Critical] `$this->{'app'}` による動的プロパティアクセスが未検出です。

[FakeWiringSourceScanner.php](/workspace/.claude/worktrees/tasks/T119/tests/Support/ExternalFakes/FakeWiringSourceScanner.php) の `appAccessIndexes()` は、`$this -> T_STRING(app)` の並びだけを認識します。そのため、次の mutation は全 gate をすり抜けます。

```php
$this->{'app'}->bind(
    VideoComposer::class,
    FakeRenderObjectStorage::class,
);
```

- `disallowedContainerCalls()` / `bindPairs()` は呼び出しを認識しない
- `disallowedIndirectAccess()` も `$this->{'app'}` を認識しない
- 既存 fake クラスを使えば 3-10 の集合は変わらない
- 3-2 の既存 inventory 解決結果にも影響しない

つまり、inventory 未登録の差し替えを追加しても赤くならない fail-open が残っています。

`appAccessIndexes()` で少なくとも以下を `$this->app` と同じ container 到達として認識し、動的表記は `disallowedIndirectAccess()` で禁止する必要があります。

```php
$this->{'app'}
$this->{"app"}
```

対応する Unit mutation ケースも追加してください。文字列が静的に `app` と判定できない `$this->{$property}` も、provider 内では fail-closed で禁止するのが設計意図と一致します。

## ファイル判定

- `tests/Support/ExternalFakes/FakeWiringSourceScanner.php`: **CHANGES_REQUESTED**
  - Round 1 の class alias / function alias 経路は塞がっています。
  - 動的プロパティ経路が残っています。

- `tests/Unit/Architecture/FakeWiringSourceScannerTest.php`: **CHANGES_REQUESTED**
  - 5-20 / 5-21 は指摘を適切に固定しています。
  - `$this->{'app'}->bind(...)` の negative ケースが必要です。
  - [Suggestion] 5-19 が 5-20 / 5-21 より後にあるため、番号順に並べると確認しやすくなります。

その他の既出 6 ファイルとドキュメントについて、新たな問題は見つかりませんでした。

## 全体判定

**CHANGES_REQUESTED**
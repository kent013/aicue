## 指摘

[Critical] 動的メンバアクセスは塞がりましたが、`$this` を別の式へ渡して container を取り出す経路が残っています。

```php
get_object_vars($this)['app']->bind(
    VideoComposer::class,
    FakeRenderObjectStorage::class,
);
```

[FakeWiringSourceScanner.php](/workspace/.claude/worktrees/tasks/T119/tests/Support/ExternalFakes/FakeWiringSourceScanner.php) は次の理由ですべて見逃します。

- `$this->...` ではないため、`appAccessIndexes()` と動的メンバ検査の対象外
- `get_object_vars()` は `CONTAINER_HELPERS` の対象外
- `$this` は provider のクラススコープなので protected な `app` を取得可能
- 既存 fake を使えば 3-10 の参照集合は変化しない
- `$this->app->bind()` ではないため 3-8 にも現れない

同じ根の問題として、`('app')()->bind(...)` や callable 変数経由も現在の名前ベース検査を回避できます。

今回の設計は「provider 内の container 差し替えは許可された `$this->app->...` だけ」という閉じた文法を要求しています。個別の container 取得 APIを追加列挙するより、provider ソース内の未分類な `$this` 使用や動的 callable 呼び出しを fail-closed で拒否する必要があります。少なくとも上記 mutation を Unit テストと 3-9 で固定してください。

## ファイル判定

- `tests/Support/ExternalFakes/FakeWiringSourceScanner.php`: **CHANGES_REQUESTED**
  - Round 2 の `$this->{'app'}` / `$this->{$property}` 経路は適切に塞がっています。
  - container 起点を名前で列挙する方式に、未分類式経由の抜け道が残っています。

- `tests/Unit/Architecture/FakeWiringSourceScannerTest.php`: **CHANGES_REQUESTED**
  - 5-22 とテスト順序の修正は妥当です。
  - `get_object_vars($this)['app']->bind(...)` の negative ケースが必要です。

その他のファイルに新たな指摘はありません。

## スコープ判断

これは後続 TODO では足りません。本 TODO の最重要要件である「fake 差し替えの追加・変更が無検出にならない」に対する、現行 PHP で実行可能な直接の fail-open です。したがって本 TODO 内で必須です。

## 全体判定

**CHANGES_REQUESTED**
Round 4 の Critical 1 件・Warning 1 件への対応マトリクスと、それを反映した概念設計の該当箇所を
示します。再レビューをお願いします。

## 対応マトリクス

(以下、codex-history/conceptual-review-decisions-round-4.md の全文)

# 対応マトリクス: conceptual-review Round 4

## [Critical] `PostCommitCallbacks` を singleton にすると rollback 後の callback が残る
- 判断: 対応する
- 対応内容:
  - container binding を `singleton()` から **`scoped()`** に変更する。
  - `discard(): void` を追加し、`EnsureLoginMethodRemains::handle()` は
    `DB::transaction()` を try/catch で包み、**例外時 (rollback 時) に `discard()` を呼んでから
    再送出する**。
  - `flush()` は実行前に保持配列を空の配列へ移し、その後に実行する
    (2 回呼んでも 2 回目は何もしない。callback 実行中の例外が残りの callback や次回 flush に
    影響しない)。

## [Warning] 汎用的な名前と、実際の flush 境界が一致していない
- 判断: 対応する
- 対応内容: クラス名を `App\Support\Auth\LoginMethodRemovalPostCommitCallbacks`
  (既存の `app/Support/Auth/` 配下に同種のクラスが既にある) へ変更し、
  `EnsureLoginMethodRemains` 専用であることを名前で示す。

---

## 反映後の概念設計 (該当箇所のみ抜粋)

- 新設: `App\Support\Auth\LoginMethodRemovalPostCommitCallbacks` (`EnsureLoginMethodRemains`
  専用。container binding は `scoped()`。`push()` / `flush()` / `discard()` の 3 メソッドのみ)
- `EnsureLoginMethodRemains::handle()`:

```php
try {
    $response = DB::transaction(function () use ($request, $next, $user): Response {
        $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
        $remaining = $this->inventory->remainingAfter($locked, $this->removalFor($request, $locked));
        if ($remaining->isEmpty()) {
            return $this->reject($request);
        }
        return $this->pass($next, $request);
    });
} catch (Throwable $e) {
    $this->postCommitCallbacks->discard();
    throw $e;
}
$this->postCommitCallbacks->flush();
return $response;
```

- `flush()`: 保持配列を先に空へ移してから実行 (再入・二重呼び出し安全)
- `discard()`: 保持配列を実行せずに空にする
- `reject()` 分岐は例外を投げずに正常な値を返すため transaction は commit するが、この分岐では
  `$next()` が呼ばれず `PasskeyDeleted` も発火していないため collector は空のまま
  (`flush()` は no-op)
- `AuthMethodChangeNotifier::notifyAfterCommit()` は
  `$this->postCommitCallbacks->push(fn () => $this->notify($user, $event));` のみ

---

上記で Round 4 の Critical / Warning は解消できていますか。追加の懸念があれば
[Critical] [Warning] [Suggestion] で指摘してください。解消できていれば全体判定を
APPROVED としてください。

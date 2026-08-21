Round 3 の Critical 1 件・Warning 1 件・Suggestion 1 件への対応マトリクスと、それを反映した
概念設計の該当箇所を示します。再レビューをお願いします。

## 対応マトリクス

(以下、codex-history/conceptual-review-decisions-round-3.md の全文)

# 対応マトリクス: conceptual-review Round 3

## [Critical] `TransactionCommitted` の動的 listener は「当該 transaction 専用」にならない
- 判断: 対応する (`TransactionCommitted` 購読方式を撤回し、request-scoped collector + 明示 flush へ)
- 対応内容: 汎用の request-scoped collector `App\Support\PostCommitCallbacks` を新設する
  (シングルトンとして bind)。`push(Closure $callback): void` / `flush(): void` の 2 メソッドのみ。
  この collector は「通知」という概念を一切知らない。
  `EnsureLoginMethodRemains::handle()` を最小限変更し、`DB::transaction()` の**戻り値を
  受けた後** (= 内側の transaction が確実に commit した後、handle() が呼び出し元へ返る前) に
  `$this->postCommitCallbacks->flush()` を呼ぶ。rollback (例外) 時は `DB::transaction()` の
  呼び出し自体が例外を再送出するため flush の行に到達せず、collector も呼ばれない
  (= commit 成否と実行が 1:1)。この middleware は「どんな種類のコールバックが積まれているか」を
  一切知らない (汎用的な flush 呼び出しのみを追加する)。
  `AuthMethodChangeNotifier::notifyAfterCommit()` は
  `$this->postCommitCallbacks->push(fn () => $this->notify($user, $event, $context));` に
  実装を差し替える。`TransactionCommitted` 購読・`app()->terminating()` はいずれも不要になった。
  既存の Architecture テスト (`LoginMethodRemovalRouteTest` 等) が変わらず green であることを
  実装時に確認する。

## [Warning] `PasskeyRegistered` の transaction 根拠が不十分
- 判断: 対応する
- 対応内容: vendor `StorePasskey::__invoke()` / `DeletePasskey::__invoke()` の実装を直接読み、
  いずれも transaction を使わず単一の INSERT/DELETE + event dispatch のみであることを確認した。
  同様に `ConfirmTwoFactorAuthentication` / `DisableTwoFactorAuthentication` /
  `GenerateNewRecoveryCodes` / `CompletePasswordReset` もソースを直接確認し、いずれも
  transaction 無しと確定した。発火点対応表の根拠列をソースファイルパス付きに更新した。

## [Suggestion] 「即時」という表現がメール即時送信と誤読され得る
- 判断: 対応する
- 対応内容: 「transaction 外で直ちに queue へジョブを投入する (`notify()`)」という表記へ統一した。

---

## 反映後の概念設計 (該当箇所のみ抜粋)

### 実装方針への追加

- 新設: `App\Support\PostCommitCallbacks` (request-scoped・singleton。`push()` / `flush()` の
  2 メソッドのみ。通知固有の知識は持たない汎用機構)
- 変更: `App\Http\Middleware\EnsureLoginMethodRemains::handle()` — `DB::transaction()` が
  正常に戻った後に `PostCommitCallbacks::flush()` を呼ぶ 1 行を追加する

### パスキー削除の発火タイミング (最終版)

`AuthMethodChangeNotifier::notifyAfterCommit(User $user, AuthMethodChangeEvent $event)` は
`$this->postCommitCallbacks->push(fn () => $this->notify($user, $event));` を行うだけ。
`EnsureLoginMethodRemains::handle()` が transaction 正常終了後に `flush()` を呼ぶことで、
commit 確定後・かつ transaction の外側で `notify()` (= queue へのジョブ投入) が実行される。
rollback 時は flush 自体が呼ばれないため、通知も発火しない (1:1 対応)。
`TransactionCommitted` 購読・`app()->terminating()` はいずれも使わない。

### 発火点対応表の根拠 (更新後)

全 8 行 (`PasswordSet`/`PasswordChanged` は同じ行) について、vendor ソース (`Fortify` /
`Laravel Passkeys`) を直接確認したファイルパスを根拠列に記載する形へ更新した
(例: `PasskeyRegistered` → `vendor/laravel/passkeys/src/Actions/StorePasskey.php` を確認し、
`$user->passkeys()->create([...])` の単一 INSERT + `PasskeyRegistered::dispatch()` のみで
transaction 無し)。

---

上記で Round 3 の Critical / Warning / Suggestion は解消できていますか。追加の懸念があれば
[Critical] [Warning] [Suggestion] で指摘してください。解消できていれば全体判定を
APPROVED としてください。

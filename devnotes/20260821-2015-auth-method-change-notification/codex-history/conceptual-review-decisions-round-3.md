# 対応マトリクス: conceptual-review Round 3

## [Critical] `TransactionCommitted` の動的 listener は「当該 transaction 専用」にならない
- 判断: 対応する (`TransactionCommitted` 購読方式を撤回し、request-scoped collector + 明示 flush へ)
- 根拠: 指摘のとおり。`Event::listen(TransactionCommitted::class, ...)` はアプリ全体の
  dispatcher に登録されるため、「この passkey 削除の transaction」と「次に発火する
  `TransactionCommitted`」を対応づける根拠が実際には無い。rollback 後に**同じリクエスト内で
  別の (無関係な) transaction が commit する**可能性を排除できておらず、その場合に
  誤発火 (削除されていないのに通知) する。transaction を識別する ID も無いため、
  安全に対応づける手段が無い。Round 1 で Codex が最初に示した「明示的な post-commit flush」
  へ戻すのが最小かつ確実という指摘を採用する。
- 対応内容: 汎用の request-scoped collector `App\Support\PostCommitCallbacks` を新設する
  (シングルトンとして bind)。
  - `push(Closure $callback): void` — 実行予定のコールバックを積む
  - `flush(): void` — 積んだコールバックを**呼び出し元がすでに「正常終了した」と分かっている
    箇所からだけ呼ぶ契約**で、全件実行してから空にする
  `EnsureLoginMethodRemains::handle()` を最小限変更し、`DB::transaction()` の**戻り値を受けた後**
  (= 内側の transaction が確実に commit した後、かつ handle() が呼び出し元へ返る前) に
  `$this->postCommitCallbacks->flush()` を呼ぶ。rollback (例外) 時は `DB::transaction()` の
  呼び出し自体が例外を再送出するため flush の行に到達せず、collector も呼ばれない
  (= commit 成否と実行が 1:1)。
  この middleware は **「どんな種類のコールバックが積まれているか」を一切知らない**
  (汎用的な flush 呼び出しのみを追加する。通知固有の知識は listener / Notifier 側に閉じる)。
  `AuthMethodChangeNotifier::notifyAfterCommit()` は
  `$this->postCommitCallbacks->push(fn () => $this->notify($user, $event, $context));` に
  実装を差し替える。`TransactionCommitted` 購読・`app()->terminating()` はいずれも不要になった。
  この変更は `EnsureLoginMethodRemains` への直接の改変を伴うため、詳細設計の「波及変更」に
  明記し、既存の Architecture テスト (`LoginMethodRemovalRouteTest` 等) が変わらず green で
  あることを確認する。

## [Warning] `PasskeyRegistered` の transaction 根拠が不十分
- 判断: 対応する
- 根拠: vendor `PasskeyRegistrationController::store()` → `StorePasskey::__invoke()` を直接読み、
  `DB::transaction()` を一切使わず「検証 → `$user->passkeys()->create([...])` (単一 INSERT) →
  `PasskeyRegistered::dispatch()`」のみであることを確認した。`DeletePasskey::__invoke()` も
  同様に「`$passkey->delete()` (単一 DELETE) → `PasskeyDeleted::dispatch()`」のみで、vendor の
  Action 自体には transaction が無い (削除ルートの transaction は `EnsureLoginMethodRemains`
  という**外側の** middleware が課しているものであり、vendor Action 自身の性質ではない)。
  同様に `ConfirmTwoFactorAuthentication` / `DisableTwoFactorAuthentication` /
  `GenerateNewRecoveryCodes` / `CompletePasswordReset` (`Illuminate\Auth\Events\PasswordReset`
  の発火元) もすべて `forceFill()->save()` + `event dispatch` のみで transaction 無しを
  ソース読み込みで確認済み。
- 対応内容: 発火点対応表の根拠列を「vendor Action の実装を直接確認済み」の形に更新し、
  各行に該当ファイルパスを明記する。

## [Suggestion] 「即時」という表現がメール即時送信と誤読され得る
- 判断: 対応する
- 対応内容: 表記を「即時 enqueue (queue へのジョブ投入を transaction 外で直ちに行う。
  実際のメール配送は worker が非同期に行う)」に変更する。

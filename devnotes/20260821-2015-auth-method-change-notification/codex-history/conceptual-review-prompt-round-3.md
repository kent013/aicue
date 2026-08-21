Round 2 の Critical 1 件・Warning 3 件・Suggestion 1 件への対応マトリクスと、それを反映した
概念設計の該当箇所 (発火点対応表・パスキー削除の発火タイミング・enum 9 種) を示します。
再レビューをお願いします。

## 対応マトリクス

(以下、codex-history/conceptual-review-decisions-round-2.md の全文)

# 対応マトリクス: conceptual-review Round 2

## [Critical] パスキーの「不存在確認」は当該 transaction の commit 証明にならない (TOCTOU)
- 判断: 対応する (再クエリ方式を撤回し、別方式に置き換える)
- 対応内容: 再クエリによる自己検証を撤回し、**`Illuminate\Database\Events\TransactionCommitted`
  を購読する方式**に置き換える。`PasskeyDeleted` の listener は、`AuthMethodChangeNotifier`
  の新メソッド `notifyAfterCommit()` を呼ぶ。このメソッドは「現在の (最も内側の)
  transaction が実際に commit した瞬間にだけ発火する 1 回限りの listener」を登録し、
  発火時に通常の `notify()` (best-effort) を呼ぶ。
  - `TransactionCommitted` は AGENTS.md ドメイン規約 11 が禁止する語彙
    (`->afterCommit()` / `DB::afterCommit` / `ShouldQueueAfterCommit` 等。すべて
    `QueueDispatchAtomicityInventoryTest` で 0 件 pin) に含まれない。禁止されているのは
    Job/Notification 自身が持つ「commit 後へずらす宣言的フラグ」(grep で見えない黙った迂回) で、
    `Event::listen(TransactionCommitted::class, ...)` は明示的・grep で見える形の購読であり、
    別種の機構である。
  - `PasskeyDeleted` はロック取得後の `DB::transaction()` の内側で発火するため、
    その場で `TransactionCommitted` を購読すると、次に発火するのは必ず「今まさに開いている、
    この passkey 削除を含む transaction の commit」である (同一 PHP プロセス内でこれより
    内側の transaction は存在せず、この transaction が閉じるまで新たな外側 transaction も
    開かない)。別リクエスト (別プロセス・別 DB コネクション) の commit はこの listener には
    見えない (`TransactionCommitted` は接続の event dispatcher 経由でプロセスローカルに発火する)。
  - rollback (例外) の場合は `TransactionCommitted` が発火しないため、登録した listener は
    一度も呼ばれず、通知も飛ばない。これが「commit 成否と投入件数が 1:1」を保証する。
  - 一度発火したら内部フラグで再発火を防ぐ (同一リクエスト内で無関係な別の transaction が
    後から commit しても誤発火しない防御)。
  - `terminating()` は不要になった (commit の瞬間に安全に queue 投入できるため)。

## [Warning] transaction 監査が詳細設計への先送りになっている
- 判断: 対応する (概念設計の時点で確定させる)
- 対応内容: 発火点対応表を概念設計本文へ追加した。`PasswordCredentialService::
  afterPersist()` は `setInitial()` 内の `DB::transaction()` が返った後に呼ばれる行であり、
  かつ `change()` は最初から transaction を開かない。呼び出し元 route もいずれも
  `EnsureLoginMethodRemains` を含まず他のラップも無い (`route:list` で確認済み)。
  `SocialAccountService::linkToUser()` も transaction を開かず、呼び出し元 route も同様。
  両経路とも直接呼び出しで安全と確定した。

## [Warning] enum の件数が一致していない (8 種 vs 実際の列挙 9 件)
- 判断: 対応する
- 対応内容: 9 種に修正し、実際の case 名を明記: `PasswordSet` / `PasswordChanged` /
  `PasswordReset` / `TwoFactorEnabled` / `TwoFactorDisabled` / `RecoveryCodesRegenerated` /
  `PasskeyRegistered` / `PasskeyDeleted` / `SocialAccountLinked`。

## [Warning] `Notification::fake()` だけでは queue 投入を証明できない
- 判断: 対応する
- 対応内容: テスト方針を役割別に明記: (1) イベント→enum 対応 = `Notification::fake()`、
  (2) `ShouldQueue` 実装であること = Unit テスト、(3) enqueue 失敗の非波及 = Notifier の
  例外テスト、(4) transaction 成否と投入件数 = 実経路 Feature テスト (`jobs` 件数)。

## [Suggestion] 再クエリを残す場合の tenant 境界
- 判断: 対応不要 (前提が無くなった。再クエリを行わなくなったため)

---

## 反映後の概念設計 (該当箇所のみ抜粋)

### 発火点対応表

| イベント | 発火元 | transaction 内か | 根拠 | 発火方法 |
|---|---|---|---|---|
| `PasswordSet` / `PasswordChanged` | `PasswordCredentialService::afterPersist()` | 否 | `change()` は transaction を開かない。`setInitial()` は `DB::transaction()` の戻り値を受けた次の行で `afterPersist()` を呼ぶため既に commit 済み。呼び出し元 route に `EnsureLoginMethodRemains` 等のラップも無い | `notify()` (即時) |
| `PasswordReset` | `Illuminate\Auth\Events\PasswordReset` | 否 | `ResetUserPassword::reset()` は `forceFill()->save()` のみ | `notify()` (即時) |
| `TwoFactorEnabled` | Fortify `ConfirmTwoFactorAuthentication` | 否 | vendor action に transaction 無し | `notify()` (即時) |
| `TwoFactorDisabled` | Fortify `DisableTwoFactorAuthentication` | 否 | vendor action に transaction 無し | `notify()` (即時) |
| `RecoveryCodesRegenerated` | Fortify `GenerateNewRecoveryCodes` | 否 | vendor action に transaction 無し | `notify()` (即時) |
| `PasskeyRegistered` | Laravel Passkeys `PasskeyRegistered` | 否 | route に `EnsureLoginMethodRemains` 無し (`route:list` で確認済み) | `notify()` (即時) |
| `PasskeyDeleted` | Laravel Passkeys `PasskeyDeleted` | **是** | route に `EnsureLoginMethodRemains` あり。`DB::transaction()` が `$next()` を丸ごとラップ | `notifyAfterCommit()` |
| `SocialAccountLinked` | `SocialAccountService::linkToUser()` | 否 | `link()` は単一 INSERT のみ。呼び出し元 route にもラップ無し | `notify()` (即時) |

### パスキー削除の発火タイミング (`notifyAfterCommit()`)

`AuthMethodChangeNotifier::notifyAfterCommit(User $user, AuthMethodChangeEvent $event)` は
`Illuminate\Database\Events\TransactionCommitted` を購読する 1 回限りの listener をその場で
登録する (内部フラグで 2 回目以降の発火を無視する)。`PasskeyDeleted` はロック取得後の
`DB::transaction()` の内側で発火するため、次に発火する `TransactionCommitted` は必ず
「今まさに開いている、この削除を含む transaction の commit」であり、別リクエストの commit は
プロセスローカルなイベントのため見えない。rollback の場合は発火せず通知も飛ばない。
`terminating()` は不要になった。`EnsureLoginMethodRemains` 自体の改造は行わない。

---

上記で Round 2 の Critical / Warning は解消できていますか。追加の懸念があれば
[Critical] [Warning] [Suggestion] で指摘してください。解消できていれば全体判定を
APPROVED としてください。

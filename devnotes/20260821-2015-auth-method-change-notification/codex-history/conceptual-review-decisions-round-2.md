# 対応マトリクス: conceptual-review Round 2

## [Critical] パスキーの「不存在確認」は当該 transaction の commit 証明にならない (TOCTOU)
- 判断: 対応する (再クエリ方式を撤回し、別方式に置き換える)
- 根拠: 指摘のとおり、`terminating()` 時点の再クエリは「誰か (別リクエスト) が結果的に
  同じ行を消したこと」しか確認できず、「**この transaction が commit したこと**」の証明には
  ならない。競合削除で二重通知・誤帰属が起こり得る。
- 対応内容: 再クエリによる自己検証を撤回し、**`Illuminate\Database\Events\TransactionCommitted`
  を購読する方式**に置き換える。`PasskeyDeleted` の listener は、`AuthMethodChangeNotifier`
  の新メソッド `notifyAfterCommit()` を呼ぶ。このメソッドは「現在の (最も内側の)
  transaction が実際に commit した瞬間にだけ発火する 1 回限りの listener」を登録し、
  発火時に通常の `notify()` (best-effort) を呼ぶ。
  - `TransactionCommitted` は AGENTS.md ドメイン規約 11 が禁止する語彙
    (`->afterCommit()` / `DB::afterCommit` / `ShouldQueueAfterCommit` 等。すべて
    `QueueDispatchAtomicityInventoryTest` で 0 件 pin) に**含まれない**。禁止されているのは
    Job/Notification 自身が持つ「commit 後へずらす宣言的フラグ」(grep で見えない黙った迂回) で、
    `Event::listen(TransactionCommitted::class, ...)` は明示的・grep で見える形の購読であり、
    別種の機構である。
  - `PasskeyDeleted` はロック取得後の `DB::transaction()` の**内側**で発火するため、
    我々の listener がその場で `Event::listen(TransactionCommitted::class, ...)` を登録した
    時点で、次に発火する `TransactionCommitted` は必ず「今まさに開いている、この passkey 削除を
    含む transaction の commit」である (同一 PHP プロセス内でこれより内側の transaction は
    存在せず、この transaction が閉じるまで新たな外側 transaction も開かない)。
    別リクエスト (別プロセス・別 DB コネクション) の commit はこの listener には見えない
    (`TransactionCommitted` は接続の event dispatcher 経由でプロセスローカルに発火する)。
  - rollback (例外) の場合は `TransactionCommitted` が発火しないため、登録した listener は
    一度も呼ばれず、通知も飛ばない。これが「commit 成否と投入件数が 1:1」を保証する。
  - 一度発火したら内部フラグで再発火を防ぐ (同一リクエスト内で無関係な別の transaction が
    後から commit しても誤発火しない防御)。
  - **`terminating()` は不要になった** (commit の瞬間に安全に queue 投入できるため、
    レスポンス確定を待つ必要がない)。

## [Warning] transaction 監査が詳細設計への先送りになっている
- 判断: 対応する (概念設計の時点で確定させる)
- 対応内容: 発火点対応表を概念設計本文へ追加した (下記)。`PasswordCredentialService::
  afterPersist()` は `setInitial()` 内の `DB::transaction()` が**返った後**に呼ばれる行であり
  (コード上、`$hash = DB::transaction(...)` の次の行から `afterPersist()` を呼ぶ)、
  かつ `change()` は最初から transaction を開かない。呼び出し元の route
  (`POST /settings/password` middleware: `recent-auth`, `throttle:password-set` /
  `PUT /user/password` middleware: `throttle:password-verify`) もいずれも
  `EnsureLoginMethodRemains` を含まず、他のラッピング transaction も無い
  (`php artisan route:list` で確認済み)。`SocialAccountService::linkToUser()` も
  transaction を開かず、呼び出し元 route (`GET /auth/{provider}/callback` middleware:
  `throttle:social-callback`) も同様。両経路とも**そのまま直接呼び出しで安全**であり、
  詳細設計での再検討は不要と確定した。

## [Warning] enum の件数が一致していない (8 種 vs 実際の列挙 9 件)
- 判断: 対応する
- 根拠: 数え間違い。パスワード「設定」と「変更」は文面が異なる別 case として数えると 9 件が正しい。
- 対応内容: 概念設計・詳細設計を「9 種」に修正し、実際の enum case 名を明記する:
  `PasswordSet` / `PasswordChanged` / `PasswordReset` / `TwoFactorEnabled` /
  `TwoFactorDisabled` / `RecoveryCodesRegenerated` / `PasskeyRegistered` /
  `PasskeyDeleted` / `SocialAccountLinked`。

## [Warning] `Notification::fake()` だけでは queue 投入を証明できない
- 判断: 対応する
- 対応内容: テスト方針を役割別に明記する (詳細設計のテスト計画へ反映):
  1. イベント → enum 対応の正しさ: `Notification::fake()->assertSentTo(...)`
  2. 通知クラスが `ShouldQueue` を実装していること: Unit テスト (instanceof 検証)
  3. enqueue 失敗が元操作へ波及しないこと: `AuthMethodChangeNotifier` を対象にした例外テスト
     (通知送信側で例外を強制発生させ、呼び出し元には伝播しないことを確認)
  4. transaction 成否と投入件数の対応: 実経路の Feature テストで `jobs` テーブルの件数
     (または `Queue::fake()` の assertion) を直接検証する
     (パスキー削除成功 → 1 件 / 唯一のログイン手段で拒否される 422 ケース → 0 件)

## [Suggestion] 再クエリを残す場合の tenant 境界
- 判断: 対応不要 (前提が無くなった)
- 根拠: `TransactionCommitted` 方式への置き換えにより再クエリ自体を行わなくなったため、
  `ModelDirectFetchInvariantTest` / cross-org 不変条件への登録は不要になった。

# 対応マトリクス: design-review Round 1

## 施策 1

### [Critical] `notifyAfterCommit()` の arrow function が `Closure(): void` 契約と不整合
- 判断: 対応する
- 根拠: 指摘のとおり `fn (): mixed => $this->notify(...)` は `notify()` の `void` を暗黙に式として
  返す形になり、`push(Closure $callback): void` の PHPDoc (`@param Closure(): void $callback`)
  とも噛み合わない。素直に無名関数へ直す。
- 対応内容: `notifyAfterCommit()` を `function () use (...): void { $this->notify(...); }` へ変更。
  `push()` に `@param Closure(): void $callback` を明記。

### [Warning] テストが `$event`/`$context` を直接検査できない
- 判断: 対応する
- 根拠: getter を足すほうが「enum とメール本文の対応が正しいか」を `toMail()` の文字列突合より
  堅く固定できる。イベントとの対応を直接見たいという設計意図に合う。
- 対応内容: `AuthMethodChangedNotification` に `event(): AuthMethodChangeEvent` /
  `context(): ?string` / `occurredAt(): CarbonImmutable` の readonly getter を追加し、
  テスト計画をそれらを使う形へ更新。

### [Suggestion] `SerializesModels` の説明が不正確
- 判断: 対応する
- 根拠: 指摘のとおり、モデル再取得は queued notification を包む queue job 側の直列化に依る。
- 対応内容: docblock の表現を「queued notification の job 側直列化 (Illuminate の標準機構) が
  worker 実行時に User を ID から再取得するため」に修正。結論 (現在のメールアドレスへ送る) は
  変えない。

## 施策 2

### [Warning] 「commit 成否と通知が 1:1」は保証できない
- 判断: 対応する
- 根拠: flush 前のプロセス終了・queue 投入失敗・`notify()` の例外吸収により、commit 済みでも
  通知 0 件になり得る。best-effort の実態と保証表現を一致させる。
- 対応内容: 施策 2・6・7・8 全体の記述を「rollback した場合は通知投入を試みない。transaction
  呼び出しの正常終了後、通知投入を best-effort で 1 回試みる」に統一する。

### [Warning] `DB::transaction()` の正常終了は物理 commit と同義ではない (nested transaction)
- 判断: 対応する
- 根拠: 指摘のとおり、`RefreshDatabase` 下では外側 transaction が存在し、flush はテスト中は
  物理 commit 前に起きる。設計の言い切りを直す。
- 対応内容: 「本 middleware が最外 transaction を持つ production Web 経路」を前提として明記し、
  Feature テストの結果を「物理 commit 後の耐久性の証明」と書かない。規約 11 との整合は
  「rollback 経路では投入しない」「`DB::afterCommit()` 系を追加しない」の範囲に限定する。

### [Warning] collector に「アクティブ状態」がなく、将来の誤 flush/欠落のリスクがある
- 判断: 対応する
- 根拠: middleware 専用であることをコードで強制すべきという指摘は妥当。「今必要なものだけ
  作る」原則には反しないレベルの最小限の防御 (アクティブフラグ + fail-fast) を追加する。
- 対応内容: `LoginMethodRemovalPostCommitCallbacks` に `start()` / アクティブフラグを追加。
  非アクティブ時の `push()` は `LogicException` で fail-fast。`flush()`/`discard()` は
  実行後に非アクティブへ戻す。middleware は transaction 開始前に `start()` を呼ぶ。
  テストケースに「アクティブ外の `push()` が例外になること」を追加。

## 施策 3

### [Warning] `handlePasskeyDeleted()` は「必ず `EnsureLoginMethodRemains` 内で発火する」ことを
検証できない
- 判断: 対応する
- 根拠: 施策 2 で追加した「非アクティブ時の `push()` は fail-fast」がまさにこの前提を実行時に
  検証する機構になる。listener 側の docblock にその保証範囲を明記する。
- 対応内容: `handlePasskeyDeleted()` の docblock に「`notifyAfterCommit()` は非アクティブなら
  例外を投げる (施策 2)。deny-by-default route gate の対象外の経路から `PasskeyDeleted` が
  直接 dispatch された場合はこの例外で検出される」を追記。

### [Suggestion] `ResetUserPassword` が `PasswordCredentialService::change()` を経由しないかの
実装時再確認
- 判断: 対応する (設計時点で確認済みであることを明記)
- 根拠: `app/Actions/Fortify/ResetUserPassword.php` を確認した。`$user->forceFill(['password'
  => ...])->save()` のみで `PasswordCredentialService` を経由しない。二重通知は起きない。
- 対応内容: 設計書に確認済みである旨と確認したファイルを明記し、テスト計画へ「通知の総数が
  1 件であることの確認」を追加 (将来の実装変更で二重化したら検出できるように)。

## 施策 4

### [Suggestion] パスワード変更テストで他の auth-method-change 通知が無いことも確認する
- 判断: 対応する
- 対応内容: テスト計画へ「対象イベント以外の `AuthMethodChangedNotification` が送られていない
  こと」を追加。

## 施策 5

### [Warning] 件数 pin (15→16, 9→10) を先に固定する実装順はテストファーストに反する
- 判断: 対応する
- 根拠: 指摘のとおり。実装順序を明記すべき。
- 対応内容: 「1. Notification クラスを追加 → 2. 既存 gate が未登録検出で赤くなることを確認 →
  3. 実際の検出結果に基づき exemption を追加 → 4. cap を実測値に更新 → 5. green 確認」の順序を
  設計書へ明記し、15/9/16/10 は「設計時点の実測値 (2026-08-21 時点で `jobDedupExemptionCap()`
  および `DuplicateDeliveryAccepted` の現在値を確認済み)」であり実装時に再確認する値である旨を
  明記する。

## 施策 6

### [Warning] D36 の保証表現が best-effort より強い
- 判断: 対応する (施策 2 と同じ修正を反映)
- 対応内容: D36 の「揃えている不変条件」を best-effort 表現へ統一。

### [Warning] 「既存テストが green」だけでは内部構造の後退を検出しない
- 判断: 対応する
- 根拠: 確認したところ `tests/Feature/Auth/PasskeyDeletionAtomicityTest.php` の
  「HTTP 削除経路では同期購読の失敗で削除ごと巻き戻る」テストが、まさにロック取得〜同期
  listener〜レスポンスを同一 transaction で扱うことを実挙動で固定している (既存)。
  これを D36 の保証機構として明記する。加えて施策 8 で追加する rollback 統合テストも
  D36 の保証機構一覧へ追記する。
- 対応内容: D36 の「揃え続ける不変条件と保証機構」に上記 2 テストを名指しで追記。

## 施策 7

### [Warning] 「queue 投入成功までが保証範囲」は Notifier の例外吸収と矛盾する
- 判断: 対応する
- 対応内容: 「queue 投入を best-effort で試行するところまでを責務とし、投入成功および
  メール配送成功は保証しない」へ書き換える。

### [Suggestion] `scoped()` の説明が不正確 (HTTP 限定という言い方)
- 判断: 対応する
- 対応内容: 「HTTP リクエスト間・queue job 間で共有しない。本機構の利用対象は HTTP
  middleware だけ」に書き換える。

## 施策 8

### [Warning] rollback 統合テストがテストケース一覧から欠落
- 判断: 対応する
- 対応内容: 「削除成功 → 後続同期処理が例外 → passkey 削除が rollback → 通知 job 0 件 →
  同じ request scope で後から flush しても通知されない」の 5 段テストをケース一覧に追加。

### [Warning] `Notification::fake()` と `jobs` テーブル件数検証は同一テストで両立しない
- 判断: 対応する
- 対応内容: レーンを明記: enum 対応の検証は `Notification::fake()`、queue 投入件数の検証は
  `Queue::fake()` で `SendQueuedNotifications` を検査するか、database queue を局所設定した
  別テストで `jobs` テーブル (対象 Notification を含む job であることまで確認) を見る。
  同一テスト内で両方を使わない。

### [Warning] enqueue 失敗時の要求に対し Notifier 単体テストだけでは不足
- 判断: 対応する
- 対応内容: 主張を「`Notifier` が例外を吸収する」に単体テストでは限定し、実際のパスワード
  変更等の Feature テストで dispatcher を例外化して DB 変更・正常応答を確認するテストを
  別途追加する。

### [Warning] 波及変更に Feature テストしか記載がない
- 判断: 対応する
- 対応内容: 波及変更へ Unit テストファイル (enum・Notification・collector 用) を追記する。

### [Warning] テストファーストの赤確認手順が無い
- 判断: 対応する
- 対応内容: 実装前に確認すべき失敗 3 点 (通知 0 件になる失敗・rollback 後に callback が残る
  失敗・2 つの deny-by-default gate が未登録で赤くなる失敗) を明記する。

# 対応マトリクス: impl-review Round 1

## [Critical] 業務状態の保存と通知ジョブ投入が同一トランザクションではない (AGENTS.md ドメイン規約 11)

- 判断: **反論する**
- 根拠:
  1. この論点は本設計の**詳細設計レビュー Round 1** で既に [Warning] として指摘済みであり
     (`devnotes/20260821-2015-auth-method-change-notification/detailed-review-round-1.md`
     施策 2 の 2 つ目の [Warning])、対応は既に取り込まれている。当時の指摘は「commit と通知が
     1:1 という保証表現が best-effort 契約と一致しない」という**表現上の過大主張**への指摘であり、
     「同一トランザクション化せよ」という要求ではなかった。detailed-design.md はこれを受けて
     「rollback した場合は積んだコールバックを実行しない。transaction 呼び出しの正常終了後、
     積んだコールバックの実行を 1 回試みる (best-effort)」という表現に**明示的に絞り**、
     「規約 11 の 0 件 pin と非干渉」という**機械的整合性の主張に限定**した
     (「commit 後の耐久性の証明」とは表現しない、と detailed-design.md 537 行目付近に明記)。
     この絞り込みは detailed-review-round 2〜4 を経て APPROVED まで到達している。
  2. AGENTS.md ドメイン規約 11 が deny-by-default で 0 件 pin するのは
     `->afterCommit()` / `DB::afterCommit` / `ShouldQueueAfterCommit` /
     `ShouldHandleEventsAfterCommit` / `ShouldDispatchAfterCommit` /
     `$afterCommit` truthy / config `after_commit=>true` という**列挙された特定の Laravel
     API**である。本実装 (`LoginMethodRemovalPostCommitCallbacks` の
     start/push/flush/discard) はこの列挙のどれも使っていない。規約 11 自身の docblock も
     「動的な迂回・helper 経由の呼び出しには沈黙する」ことを明記しており、
     静的検査 (`QueueDispatchAtomicityInventoryTest`) が対象とする形と、
     本機構が対象とする形は**別物**である。
  3. `EnsureLoginMethodRemains` の transaction は「ロック取得〜controller〜同期
     listener〜レスポンス生成まで丸ごと」を囲む本アプリ固有の広い transaction であり
     (D36 として登録済み)、この transaction の**内側**で queue 投入や外部 I/O を行うことは
     既存契約 (同 middleware の docblock) が禁じている。したがって「transaction 内で
     dispatch する」という規約 11 の字義通りの適用は、そもそも本 middleware の既存契約と
     衝突し、選択肢として存在しなかった。
  4. best-effort 通知という設計方針 (二重配送も欠落も許容) は、規約 11 が想定する
     「取り消せない外部副作用の一回性」(規約 6・11 が例示するのは LLM 呼び出し・S3 PUT・
     Stripe 課金・media pipeline 等の**取り消せない業務行為**) とは脅威モデルが異なる。
     本通知が commit 後の稀なプロセス終了で欠落しても、ユーザーの認証手段の状態自体は
     正しく確定しており、再送不能な業務的損失は発生しない
     (`docs/architecture.md` §認証手段変更のメール通知ポリシー の「保証しないもの」に
     この欠落可能性は明記済み)。
  5. 以上により、Critical が要求する「状態保存と通知ジョブ投入の同一トランザクション化」は、
     (a) 既に設計レビューで検討済みかつ承認済みの判断を覆すものであり、
     (b) 本 middleware の既存契約 (transaction 内での外部 I/O 禁止) と矛盾し、
     (c) 規約 11 が保護しようとする脅威 (取り消せない業務行為の欠落) が本ケースには
     当てはまらないため、本ラウンドでは実装を変更しない。
- 対応内容: 上記の経緯を Round 2 プロンプトで提示し、再判定を依頼する。
  ただし関連する Warning (以下) は全て対応済み。

## [Warning] Feature テストが設計で必須とされた異常系を実装していない

- 判断: **一部対応する / 一部見送る**
- 対応した項目:
  - 「パスワード変更の実経路で enqueue を例外化し、パスワード更新と HTTP 応答への影響を検証する
    テスト」→ `AuthMethodChangeNotificationTest.php` に
    `PUT /user/password は通知の enqueue が例外化してもパスワード変更自体は成功する` を追加。
    `Dispatcher::send()` を例外化し、パスワード変更自体の成功と `report()` 実行の両方を固定した。
- 見送った項目:
  - 「実際の `POST /user/passkeys` を通るパスキー登録テスト」→ 見送る。
    根拠: 本アプリの既存テスト (`tests/Feature/Auth/PasskeyRouteAccessTest.php`) を調査した結果、
    実 route を通した WebAuthn ceremony の**完全な**成功パス (`PasskeyRegistered` 発火まで)
    を検証する Feature テストは T110 以前から**存在しない**(既存テストは 422 で止まる
    ceremony 検証の手前までしか通していない。有効な attestation フィクスチャが本テスト基盤に
    無いため)。既存 `PasskeyAuditTrailTest.php` も同じ理由で
    「イベント自体からも記録される (listener の直接検証)」という**直接 dispatch** の形で
    vendor イベント境界をテストしている。T110 のテストもこの既存の境界に合わせた設計であり、
    新しい WebAuthn フィクスチャ基盤の追加は本タスクの範囲外 (思考原則 2: 今必要なものだけ作る)。
  - 「メール本文に秘密情報が含まれないことの検証」→ 対応済み (別 Warning 参照)。

## [Warning] `report()` の実行をテストしていない

- 判断: **対応する**
- 対応内容: `AuthMethodChangeNotifierTest.php` に `Exceptions::fake()` +
  `Exceptions::assertReported(RuntimeException::class)` を使うテストを追加。
  併せて `assertSentTo()` のクロージャ引数に明示型 (`AuthMethodChangedNotification $n`) を付けた。

## [Warning] 秘密情報非掲載の不変条件がテストで固定されていない

- 判断: **対応する**
- 対応内容: `AuthMethodChangedNotificationTest.php` に、全 9 case × 疑わしい
  context 文字列 (reset-token / recovery-code / totp-secret / credential-id /
  provider-user-id を含む複合文字列) を渡して `toMail()` を呼び、
  `SocialAccountLinked` (provider 表示名を意図的に本文へ載せる契約) を除く 8 case で
  これらのマーカーが一切本文に現れないことを固定するテストを追加した。
  実際の呼び出し元 (`AuthMethodChangeNotifier` / `PasswordCredentialService` /
  `SocialAccountService` / `NotifyAuthMethodChange`) がこれらの値を `$context` へ渡すことは
  無い (`$context` は provider 表示名専用) ことも確認済み。

## [Warning] 最終検証が未完了

- 判断: **対応する**
- 対応内容: 本ラウンドの修正 (adoption-debt.tsv 2 件の登録移行 + D38/D39 登録 +
  LedgerPins 更新 + pint 修正 + テスト追加) を含めてフルスイートを再実行中。
  結果は Round 2 プロンプトに添付する。

## [Suggestion] 直接 dispatch テストの collector を後始末する

- 判断: **対応する**
- 対応内容: `PasskeyAuditTrailTest.php` / `PasskeyDeletionAtomicityTest.php` /
  `PasskeyRecentAuthInvalidationTest.php` の 3 テストで、`start()` 後の assertion 完了時点に
  `LoginMethodRemovalPostCommitCallbacks::discard()` を呼び、active のまま終わらないよう
  後始末した。

## ファイル別判定への対応

- `docs/template-divergence.md` の D36: Critical への反論と同じ根拠のため変更なし。
- `docs/architecture.md` の T110 節: 「保証しないもの」に既に
  「queue 投入の成功、およびメールの実配送成功は保証しない」と明記済みであることを確認。
  Critical への反論が採用されれば追加修正は不要と判断。ただし Round 2 で Codex が
  この文言の具体的な不足点を指摘した場合は追記する。

## 本ラウンドで追加で対応した目録整合 (Codex 提示外だが検証で判明)

フルスイート再実行で判明した 2 件を追加修正した (Critical/Warning の指摘外だが green 化に必須):

1. `tests/Architecture/TemplateDivergenceFingerprintTest.php`: 本ラウンドで編集した
   `PasskeyPackageContractTest.php` / `QueuedJobLeaseInventoryTest.php` が採用時債務
   (`adoption-debt.tsv`) の凍結ハッシュから変化したため、3 択のうち「意図的逸脱として登録する」
   を選択。`docs/template-divergence.md` に D38 (キュー接続リース目録)・D39 (パスキー削除の
   同期購読者 pin) を追加し、`adoption-debt.tsv` から該当 2 行を削除、
   `LedgerPins::DIVERGENCE_ENTRY_COUNT` (35→37) / `ADOPTION_DEBT_COUNT` (172→170) を更新。
2. `vendor/bin/pint --test`: `QueuedJobLeaseInventoryTest.php` の import 順・空白を
   `vendor/bin/pint` で自動修正。

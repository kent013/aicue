# T110 実装メモ: フルスイート最終確認で見つかった目録取りこぼしの修正

設計 (`devnotes/20260821-2015-auth-method-change-notification/detailed-design.md`) からの
逸脱ではなく、施策 5 (既定拒否目録への登録) の取りこぼし 2 件をフルスイート実行で検出し、
main 取り込み後に修正した。

## (1) QUEUED_JOB_LEASE_INVENTORY への登録漏れ

`tests/Architecture/QueuedJobLeaseInventoryTest.php` の
`QUEUED_JOB_LEASE_INVENTORY` は「キューに載る全クラス (ShouldQueue 実装) の接続目録」を
deny-by-default で強制する。新設の `App\Notifications\Auth\AuthMethodChangedNotification`
(`ShouldQueue` を implements) が未登録だった。

- 登録値: `null` (既定接続)。理由は他の Notification 系エントリ
  (`AutoRechargeActionRequiredNotification` 等) と同じで、本クラスは
  `onConnection()` を呼んでいないため既定接続で動く。
- 本クラスは best-effort な通知であり、二重配送・欠落のどちらも許容する設計
  (detailed-design.md §配信保証) である。接続目録は「リース期間 (retry_after と
  timeout の関係) の検査対象になるか」を分類するだけで、配信保証の強さとは無関係の軸なので、
  他の Notification と同じ `null` 分類で整合する。

## (2) PasskeyPackageContractTest の同期購読者 pin

`tests/Architecture/PasskeyPackageContractTest.php` の
「パスキー削除イベントの直接購読は同期で走る N つだけである (巻き戻りの前提)」テストは、
`PasskeyDeleted` の直接購読者を完全一致で pin している。新設の
`App\Listeners\Auth\NotifyAuthMethodChange` (`Event::subscribe` で `AppServiceProvider::boot()`
から登録) が増えたため、2 件 pin のままでは失敗する。

- 実際の購読順 `RecordSecurityEvent → NotifyAuthMethodChange → ClearRecentAuthOnPasskeyChange`
  に合わせて pin を 3 件へ更新し、テスト名 (2 つ→3つ) とコメントも合わせて直した。
- `NotifyAuthMethodChange` 自身は `ShouldQueue` を実装しない同期リスナーであり、
  `handlePasskeyDeleted()` は `AuthMethodChangeNotifier::notifyAfterCommit()` を呼ぶだけ
  (実際の通知本体 = `AuthMethodChangedNotification` は `ShouldQueue` でキューへ載る)。
  そのため「削除の巻き戻りの前提 (同期購読)」というテストの不変条件そのものは崩れておらず、
  pin の更新のみで解決する。

## 検証

`vendor/bin/pest tests/Architecture/QueuedJobLeaseInventoryTest.php
tests/Architecture/PasskeyPackageContractTest.php` で 30 tests / 30 passed を確認。
その後 origin/main (route:cache 退行修正 435e1385 込み) を merge。

## (3) フルスイート再実行で追加判明した取りこぼし (同種)

上記 2 件を修正して `composer test` を通した後、`pnpm test` で 3 件目の同種取りこぼしが
見つかった: 新設 `App\Enums\Auth\AuthMethodChangeEvent` (文字列付き PHP enum) が
`tests/js/architecture/enum-ts-sync-discovery.test.ts` の PHP 列挙 ⇔ TS 値域同期検査
(AGENTS.md ドメイン固有規約 19) で未分類のまま検出された。フロントエンドへ一切公開されない
内部分類 enum のため `PHP_ENUM_EXEMPTIONS` へ理由付きで登録し、件数 pin
(`EXPECTED_EXEMPTION_COUNT` 86→87) を更新して解消した。

また、(1)(2) で編集した 2 ファイル (`QueuedJobLeaseInventoryTest.php` /
`PasskeyPackageContractTest.php`) 自身が `tests/Support/TemplateDivergence/adoption-debt.tsv`
の採用時債務に凍結登録されていたため、編集によって
`TemplateDivergenceFingerprintTest` (採用時債務が採用時の姿から変わったことの検出) が
新たに赤くなった。3 択のうち「意図的逸脱として登録する」を選び、
`docs/template-divergence.md` へ D38 (キュー接続リース目録)・D39 (パスキー削除の
同期購読者 pin) を追加し、`adoption-debt.tsv` から該当 2 行を削除、
`LedgerPins::DIVERGENCE_ENTRY_COUNT` (35→37) / `ADOPTION_DEBT_COUNT` (172→170) /
`docs/template-divergence.md` 冒頭の「登録エントリ: N 件」を更新した。
これは以前の T110 実装セッションが `EnsureLoginMethodRemains.php` /
`JobExecutionDedupInventoryTest.php` に対して行った処理 (D36 / D37) と同種である。

これら 3 件はいずれも「設計の施策 5 (既定拒否目録への登録) の取りこぼし」であり、
設計からの逸脱ではない。

## Codex 実装レビュー (impl-review, gpt-5.6-sol, reasoning high) と未解決の Critical

上記の目録修正一式について `git diff` を取り、`app-codex-review` 規約でセッション型の
実装レビューを 3 ラウンド行った (`codex-history/impl-review-prompt-round-{1,2,3}.md` /
`impl-review-round-{1,2,3}.md` / `impl-review-decisions-round-{1,2}.md`)。

**Round 1 の [Warning] 4 件・[Suggestion] 1 件はすべて対応済み**
(enqueue 失敗の実経路テスト・`report()` テスト・秘密情報非掲載の負例・
collector 後始末・フルスイート再実行)。**Round 2 で追加された [Warning] 1 件
(SocialAccountLinked のテスト境界) も対応済み**であり、Round 3 で Codex は
「Warning はすべて解消され、Critical 1 件が唯一の残存ブロッカーである」ことを確認した。

**未解決の [Critical] (Round 1・2 で維持、Round 3 でも解消せず)**:
`App\Http\Middleware\EnsureLoginMethodRemains` のパスキー削除経路 (D36) は、
「業務状態の保存とキュー投入は同一トランザクション内で行う (`afterCommit` に依存しない)」
という AGENTS.md ドメイン規約 11 と字義上衝突する。規約 11 は明示的に**免除機構を
持たない** (0 件 pin・allow-list 無し) ため、本設計 (transaction 呼び出しの正常終了後に
`LoginMethodRemovalPostCommitCallbacks` 経由で `notify()` を投入する best-effort 機構) が
規約 11 の**列挙された特定 API**(`->afterCommit()` 等) を使っていないことは、
**静的検査 (`QueueDispatchAtomicityInventoryTest`) の検出範囲の外にあるだけ**であり、
**規約 11 の意味上の適用除外を意味しない**。

この点は詳細設計レビュー Round 1 でも [Warning] として一度議論されているが、
当時の対応は「commit と通知が 1:1 という過大な保証表現を best-effort へ絞る」という
**表現の適正化**であり、「規約 11 の対象からこの通知を除外してよいか」という
**規約適用の裁定**そのものは行われていなかった (Codex Round 2 の指摘。妥当と判断し
Round 3 で反論を取り下げた)。

**この Critical は本セッションでは解消しない**。理由は、解消に要する選択肢
(規約 11 準拠パターンへの再設計 / transactional outbox 等で通知意図を同一トランザクションで
耐久化する再設計 / 規約 11 自体への正式な適用除外の追加 / 現設計の不採用) のいずれも、
本タスクの割当スコープ (既知の目録取りこぼし修正 + マージ) を超える設計判断であり、
実装エージェント単独で確定できない (Codex Round 3 も同じ結論)。

**現在の状態 (この記録の時点)**: worktree 内の全変更 (施策 1〜8 の実装 + 本セッションの
目録修正 3 件 + Codex レビュー対応) はフルスイート green (`composer test` 6438 tests /
6436 passed / 2 skipped・`composer phpstan` No errors level 10・pint/lint/typecheck/build
全 green・`pnpm test` 2371 tests all green・`pnpm test:packages` 106 tests all green) だが、
**未コミット**。Codex APPROVED が揃っていないため、コミット・TODO クローズ・main への
マージは行っていない。人間の裁定を待つ。

## 監督セッション (2026-08-21) の裁定と適用

未解決の Critical (上記) に対し、監督セッションが選択肢 (a) を採る裁定を下した。
根拠と裁定文の全文は
`devnotes/20260821-2015-auth-method-change-notification/detailed-design.md`
「実装レビューの裁定」節に記録した。要旨は次のとおり。

- **撤去**: `App\Support\Auth\LoginMethodRemovalPostCommitCallbacks` (collector) と
  その単体テスト、`EnsureLoginMethodRemains` の try/catch + start/flush/discard 配線
  (`app/Http/Middleware/EnsureLoginMethodRemains.php` は collector 導入前の姿へ
  完全に戻り、`sha256sum` が採用時債務の pin 値と再び一致することを確認した)、
  `AuthMethodChangeNotifier::notifyAfterCommit()`、既存パスキーテスト 3 本
  (`PasskeyAuditTrailTest` / `PasskeyDeletionAtomicityTest` /
  `PasskeyRecentAuthInvalidationTest`) に足していた `start()`/`discard()` 呼び出し
- **変更**: `NotifyAuthMethodChange::handlePasskeyDeleted()` は他の 7 イベントと同じ
  private `notify()` helper (その場で `AuthMethodChangeNotifier::notify()` を呼ぶだけ)
  を使う形へ揃えた。`PasskeyDeleted` は `EnsureLoginMethodRemains` の transaction 内で
  同期発火するため、この呼び出しは自然に業務トランザクションの内側で起きる
- **テスト**: 「rollback したら通知が出ない」は `Queue::fake()` ではなく実 `jobs` 表で
  検証する形を維持・強化した (`tests/Feature/Auth/AuthMethodChangeNotificationTest.php`
  の rollback テストから collector 依存の後始末コードを削除し、
  `config(['queue.default' => 'database'])` + `jobs` テーブル件数観測だけで
  commit/rollback の両経路を固定する。`QueueDispatchAtomicityTest.php` と同型)
- **台帳の整合**: `EnsureLoginMethodRemains.php` が採用時の姿へ完全に戻ったため、
  D36 (パスキー等除去 middleware の transaction 正常終了後コールバック機構) を
  登録簿から削除し、`tests/Support/TemplateDivergence/adoption-debt.tsv` へ同ファイルの
  行を採用時ハッシュ付きで復活させた。`LedgerPins::DIVERGENCE_ENTRY_COUNT` (37→36)・
  `ADOPTION_DEBT_COUNT` (170→171) を更新した。D37 (`JobExecutionDedupInventoryTest.php`)・
  D38 (`QueuedJobLeaseInventoryTest.php`)・D39 (`PasskeyPackageContractTest.php`) は
  `AuthMethodChangedNotification` クラス自体・新設 `NotifyAuthMethodChange` listener の
  登録に起因する差分であり collector とは無関係のため、そのまま維持した
  (`JobDeferralTerminationGateTest.php` の `AuthMethodChangedNotification` 登録も
  同様にクラス自体の登録であり変更不要)
- **スコープ**: 本裁定は passkey 削除経路 (collector) のみが対象であり、
  `PasswordCredentialService` / `SocialAccountService` の「commit 後に `notify()` を
  呼ぶ」構造は対象外 (裁定文どおり)

適用後は本裁定の内容と新しい diff を提示して Codex 実装レビューを再開する。

# 対応マトリクス: design-review Round 1

## 施策 1

### [Critical] `Collection` の import が抜けている
- 判断: **対応する**
- 対応内容: `use Illuminate\Support\Collection;` をコード例に追加。phpdoc も FQCN から短縮名に統一。

### [Warning] `orphanBillingOrganizationIds()` の N+1
- 判断: **対応する** (判断記録を残す)
- 根拠: 入力は「Owner 不在の組織」= 通常 0 件の異常系集合であり、全組織走査ではない。
- 対応内容: docblock と `docs/architecture.md` 追記項目に「現時点では許容 / 件数増加時は exists subquery 化」を明記。

## 施策 2

### [Warning] `build()` の action 導出仕様が実装者依存
- 判断: **対応する**
- 対応内容: 導出規則 (理由 → action の対応 / 出力順は TransferOwnership → billing 系 / 重複なし) を docblock に固定。

### [Warning] `$reasons` の phpdoc
- 判断: **対応する**
- 対応内容: `@param list<AccountDeletionBlockReason> $reasons` を追加。

## 施策 3

### [Critical] 「権威判定」の表現が過剰
- 判断: **対応する**
- 根拠: 概念設計 §4.4 の結論 (vendor webhook とは排他しない) と docblock の表現が食い違っていた。
- 対応内容: docblock を「**通常のアプリ経路の**権威判定」に修正し、
  「組織行ロック後に課金を読むのは membership race 対策であって subscription 作成 race の完全排他ではない」
  「漏れは daily 検知が second layer」を明記。docs 追記項目 (1) も同様に修正。

### [Critical] Laratrust の pivot 列名が不確か
- 判断: **対応する** (実査で確定)
- 根拠: `database/migrations/2026_06_11_073836_laratrust_setup_tables.php` の `role_user` は
  `role_id` / `user_id` / `user_type` / **`team_id`**。`config/laratrust.php` の `foreign_keys.team = 'team_id'`。
  `organizations` 側は `laratrust_team_id`。よって
  `whereColumn('role_user.team_id', 'organizations.laratrust_team_id')` が正しい (先例: `PersonalPlanService` L188-204)。
- 対応内容: 列名の対応表を設計に明記し、「team を明示しない role 照合を書かない」を添えた。

### [Warning] `->filter()` の PHPStan narrowing
- 判断: **対応する**
- 対応内容: `->filter(fn (?AccountDeletionBlockerDto $b): bool => $b !== null)` に変更。

### [Warning] `hasAnotherOwner()` の N+1
- 判断: **対応する** (根拠の補強)
- 対応内容: 「既存実装も同形で組織ごとに呼んでいる = 既存踏襲」であることをリスク欄に明記。

## 施策 4

### [Suggestion] `use App\Models\Organization;` が未使用になる
- 判断: **対応する**
- 対応内容: 「削除する」と明記。

## 施策 5

### [Warning] action が区切りなしで連続表示される
- 判断: **対応する**
- 対応内容: `flex flex-col items-start gap-1` の縦積みコンテナに変更し、切替ボタンに testId を付与。

### [Warning] 切替失敗時のフィードバックが未設計
- 判断: **対応する**
- 対応内容: `onError` で `switchError` を立てて warning Alert 内に表示。vitest ケース 28 を追加。

### [Warning] `errors.account` 複数行化に伴う派生値の型変更
- 判断: **対応する**
- 対応内容: `accountError: string | null` → `accountErrors: string[]` に変更し、表示を `<ul>` に。vitest ケース 30 を更新。

## 施策 6

### [Critical] `Artisan::command` の closure DI が解決される前提は要確認
- 判断: **反論する** (根拠あり)
- 根拠: 既存 `routes/console.php` の `billing:release-stale-reservations` が
  `function (TicketLedgerService $tickets)` で **型 hint DI をすでに使っている** (稼働中の先例)。
  `Artisan::command()` は `Container::call` でクロージャを解決するため、型 hint 引数は DI で埋まる。
  `app()` 解決に書き換えると既存の書き方と乖離する。
- 対応内容: 反論の根拠 (既存先例) をコード例のコメントに明記した。

### [Warning] `use RuntimeException;` が必要
- 判断: **反論する** (根拠あり。むしろ書いてはいけない)
- 根拠: `routes/console.php` は **namespace 宣言が無い**ファイルであり、非複合 use は無効な import になる。
  `tests/Architecture/NoNonCompoundGlobalUseTest.php` がこれを**禁止**している (allowlist 無し)。
  namespace 無しなので `new RuntimeException(...)` はそのまま global 解決される
  (既存 `billing:reconcile-auto-recharge` の `onFailure` が同じ書き方)。
- 対応内容: 「import しない」理由をコード例のコメントに明記。合わせて、必要な**複合名** import
  (`AccountDeletionBillingGuard` / `OrganizationMembershipService`) は追加すると明記。

### [Warning] schedule/console の architecture テストがあれば更新対象に
- 判断: **対応する** (実査の結果、該当テストは存在しない)
- 根拠: `tests/Feature/Console/*` は個別コマンドの振る舞いテストのみ。schedule inventory テストは存在しない。
- 対応内容: その旨をリスク欄に明記し、代わりに本コマンド専用の Feature テストを追加。

## 施策 7

### [Critical] Stripe 非呼び出しテストが成功経路だけ
- 判断: **対応する** (指摘が正しい。ブロック経路こそ本丸)
- 対応内容: テスト #16「課金中でブロックされる経路でも Stripe を呼ばない」を追加
  (= 退会処理が解約を代行しようとしないことの固定)。

### [Warning] console command のテストが無い
- 判断: **対応する**
- 対応内容: `tests/Feature/Billing/DetectOrphanBillingOrganizationsCommandTest.php` を新設 (#21〜#24)。
  `report()` の観測は `ExceptionHandler` の Mockery spy (先例: `TicketPurchaseWebhookTest`)。
  PII 非出力 (組織名 / email を含まない) も固定。

### [Warning] `organizationsWithoutOwner()` の cross-team 誤判定テストが不足
- 判断: **対応する**
- 対応内容: guard テスト #9「別組織 (別 team) の Owner ロール保持者がいても対象組織の Owner として数えない」を追加。

## 施策 8

### [Warning] docs の「権威」表現
- 判断: **対応する** (施策 3 の Critical と同一対応)

### [Suggestion] Stripe redaction の数値は外部仕様依存
- 判断: **対応する**
- 対応内容: 「参照元と確認日を併記し、数値を鵜呑みで固定しない」を docs 追記項目に明記。

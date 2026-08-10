# 対応マトリクス: impl-review Round 2

## [Warning] 2FA 脱出テストが「同一ユーザー」の連鎖を証明していない

- **判断**: 対応する (指摘は正しい)
- **根拠**: 別ユーザーで代用すると「元の未準拠ユーザーが本当に脱出できるか」を 1 行も証明せず、
  実在した Critical (相互ブロックの詰み) の回帰防止として空振りになる。
- **対応内容**:
  - `UserFactory::withTwoFactor()` の実装を `UserFactory::enableTwoFactorFor(User $user)` へ切り出し、
    state と helper が**同一実装を共有**する形にした (2 箇所に書かない)。
  - テストを「未準拠 → `settings.security` 到達 → 同一ユーザーを準拠へ遷移 → 同一ユーザーが取消成功」の
    連鎖に書き換えた。

## [Warning] `queuedJobClasses()` の名前と「業務ジョブ」という主張が広すぎる

- **判断**: 対応する
- **対応内容**: `queuedJobClassesExceptDeletionNotice()` へ改名し、docblock に
  「退会通知**以外**の queued class であって『業務ジョブ』の一般的分類ではない」
  「新しい非業務通知が増えたら赤くなる。そのときは除外を増やす前に、凍結中にその通知が
  積まれてよいのかを先に考えること」を明記。禁止対象 (`AutoRechargeTriggerJob`) の
  名指し検査と併用する 2 段構えにした。

## [Warning] 「report + FAILURE」というテスト名なのに `report()` を検証していない

- **判断**: 対応する。**追跡した結果、実装側の欠陥が見つかった**
- **根拠**: `Exceptions::fake()` + `assertReported` を入れたところ、
  **`report(new ValidationException)` が 1 件も記録されない**ことが判明した。
  Laravel の既定 dontReport が `ValidationException` を握り潰すため、設計どおりに書いた
  「保留を report する」は**実際には無効**で、監視契約が保留について嘘になっていた。
- **対応内容**:
  - `catch (ValidationException)` の中の `report($e)` を削除し (無効であることをコメントに明記)、
    走査後に `blocked > 0` なら**件数を載せた `RuntimeException`** を 1 回 report する形へ変更。
  - `Exceptions::assertReported` / `assertReportedCount` を 4 テストへ追加
    (想定外例外 / 片列非正規 / 順序非正規 / 保留)。
  - `docs/architecture.md` と `docs/account-deletion-runbook.md` の監視契約の記述を実装に合わせた。
  - `mutation-evidence.md` に実測として記録。

## [Suggestion] `isNormalized()` の名前が DB CHECK 制約全体と一致しない

- **判断**: 対応する
- **根拠**: 指摘どおり。DB の制約は「両列とも null」も正常と認めるが、この述語は false を返す。
  実際に見ているのは「**予約として扱ってよい組か**」である。
- **対応内容**: `isValidPendingRequest()` へ改名し、docblock に
  「DB の CHECK 制約を満たすかではない。未予約 (両列 null) には false を返す」を明記。

## [Suggestion] `matches()` も非正規状態では false にする余地がある

- **判断**: 対応する
- **根拠**: 「非正規状態では外部副作用 (メール送信) も出さない」方が fail-closed で一貫する。
  コストはゼロ (述語の差し替えのみ)。
- **対応内容**: `matches()` の前提を `isPending()` → `isValidPendingRequest()` へ変更し、
  docblock に「非正規な組では false = 外部通知も出さない (fail-closed)」を明記。

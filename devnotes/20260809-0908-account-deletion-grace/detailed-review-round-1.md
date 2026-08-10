レビュー仮説: この詳細設計は「30日猶予中は凍結」「退会経路から決済事業者へ到達しない」「7年保持の宣言と実処理を一致させる」の3つを機械的に守れる必要があります。結論として、方向性はかなり整理されていますが、いくつかの実装不能・不変条件抜けがあるため **全体判定は CHANGES_REQUESTED** です。

**施策別判定**

| 施策 | 判定 |
|---|---|
| A1 退会経路の依存閉包 gate | APPROVE |
| A2 redaction 記録列とコマンド | REQUEST_CHANGES |
| A3 退会 runbook | APPROVE |
| B1 予約列 | REQUEST_CHANGES |
| B2 予約 / 取消 Service | REQUEST_CHANGES |
| B3 予約 / 取消 HTTP | APPROVE |
| B4 凍結 middleware | REQUEST_CHANGES |
| B5 日次執行バッチ | REQUEST_CHANGES |
| B6 通知・監査 | REQUEST_CHANGES |
| B7 UI | APPROVE |
| B8 既存 gate 更新 | REQUEST_CHANGES |
| C1a 保持年数の単一出典 | APPROVE |
| C1b purge 対象目録と起算点 | REQUEST_CHANGES |
| C1c purge コマンド | REQUEST_CHANGES |
| C1d 目録 gate + horizon | APPROVE |
| C2a ledger reader 目録 | APPROVE |
| C2b ledger 畳み込み | REQUEST_CHANGES |
| C2c 日次登録 + `--apply` | APPROVE |
| C2d 有効化 runbook | APPROVE |
| C3a 規約文面 | REQUEST_CHANGES |
| C3b 三者一致 gate | REQUEST_CHANGES |

**Critical**

- [Critical] B2: `config()->integer('account.deletion_grace_days')` を使うのに、`config/account.php` などの追加が施策一覧にありません。実装時に未定義 config を読むか、環境差分で壊れます。  
  修正案: B1/B2 に `config/account.php` 追加を明記し、`deletion_grace_days => 30` を固定値として置く。`AccountDeletionGraceConfigTest` 等で 0 以下 fail-fast と 30 日固定を検査してください。

- [Critical] B4: allowlist に `settings.account.destroy` が入っているため、予約中ユーザーが即時削除 route を直接叩くと 30 日猶予を迂回できます。B7 では即時削除を未予約時の副導線として残す設計なので、予約中に通す根拠が不足しています。  
  修正案: 予約中は `settings.account.destroy` を allowlist から外す。即時削除を予約中にも許すなら、「30日猶予は主導線であって強制ではない」と明文化し、予約中の即時削除の Feature/Browser テストを追加してください。

- [Critical] B6: `via()` の予約生存再確認だけでは「二重 dispatch で 1 通」は保証できません。同じ `requestedAt/purgeAfter` を持つ queued notification が2つあれば両方 `mail` を返します。  
  修正案: メール送信の dedup key を DB に持つ、`ShouldBeUnique` 相当の一意性を使う、または通知送信済み台帳で compare-and-set する設計にしてください。テストは「同一 payload job が2つ投入されても1通」にする必要があります。

- [Critical] C1b: `SubscriptionItem` が表では target として扱われていますが、`BillingRetentionTarget` enum に case がありません。`BillingRetentionTargetInventoryTest` の exact-fit と矛盾します。  
  修正案: `SubscriptionItem` を独立 target として enum に追加するか、`Subscription` purger の内部対象にするなら inventory 側にも「Subscription 配下の child target」として型付き分類を持たせてください。

- [Critical] C2b: ledger 畳み込みの group key が `(source, expires_at)` だけだと、組織を跨いで残高を合算する危険があります。本文では「組織の行ロック下」とありますが、grouping の不変条件に `organization_id` が明記されていません。  
  修正案: group key を最低でも `(organization_id, source, expires_at)` にしてください。残高 SoT が team/project/account 粒度を持つなら、その粒度も group key に含める必要があります。

- [Critical] C3a/C3b: C3a は Blade で `config('legal.billing_retention_years')` を直接読む一方、C3b は config を読んでよいのは `BillingRetention` 1 箇所だけとしています。設計内で自己矛盾しています。  
  修正案: Blade は `BillingRetention::years()` 由来の値だけを使う設計に寄せる。直接 Blade で static call するか、controller/view composer で `BillingRetention::years()` の値を渡してください。

**Warning**

- [Warning] A2: `stripe_customer_redacted_at` だけでは「どの Stripe customer を redacted 済みと記録したか」が後から検証できません。`stripe_id` が変わる経路が将来できた時点で再設計、では監査列として弱いです。  
  修正案: `stripe_customer_redacted_id` を同時に持つか、`stripe_customer_redacted_at !== null` の組織では `stripe_id` 変更を禁止する invariant を追加してください。

- [Warning] B1: DTO は `CarbonImmutable` 前提ですが、cast が `'datetime'` だと通常は mutable Carbon になり得ます。  
  修正案: `deletion_requested_at` / `deletion_purge_after` は `'immutable_datetime'` にするか、`AccountDeletionStateDto::fromUser()` で `CarbonImmutable` へ明示変換してください。

- [Warning] B4: 凍結中に `logout` や `session.status` 系 route が遮断される可能性が設計で潰れていません。ログアウト不能は UX/security ともに悪いです。  
  修正案: それらが凍結 middleware の母集団外であることを gate で固定するか、allowlist に根拠付きで追加してください。

- [Warning] B5: command の抽出条件が `deletion_purge_after` のみで、DTO の pending 定義「両列が揃う」とズレています。片列だけ壊れた行を due と数えます。  
  修正案: `whereNotNull('deletion_requested_at')` も加え、片列欠損は別途 invariant/fail-closed テストで検出してください。

- [Warning] B8: `executeAccountDeletionRequest` は設計上 `deleteAccount()` に委譲しており、本文中で直接 `lockForMembershipWrite` を呼んでいません。`MembershipWriteLockInventoryTest` の `directLock` 登録と矛盾する可能性があります。  
  修正案: inventory に delegate 分類を追加するか、drift-guard を「`deleteAccount()` 経由で canonical lock に乗る」検査にしてください。

- [Warning] C1c: C1 は dry-run のみと言いながら command signature に `--apply` があります。運用開始前に手動 apply できる形は設計意図とズレます。  
  修正案: C1 では `--apply` を受けたら FAILURE にする、または C2 で初めて `--apply` option を追加してください。

**Suggestion**

- [Suggestion] A1 はかなり強い gate なので、閉包 exact-fit の cap は PR-B で起点追加する際に「差分理由」をコメントに残すと保守しやすいです。
- [Suggestion] B7 は DESIGN.md 準拠のため、使用する既存 Alert/Button/Form atom 名と「hex 直書きなし」をテスト観点に明記すると実装レビューが楽になります。
- [Suggestion] C2b の畳み込み行は、既存 unique 制約や `source` の意味と衝突しやすいので、`source=carry_forward` にするか、既存 source を維持するなら unique 制約との関係を設計に明記してください。
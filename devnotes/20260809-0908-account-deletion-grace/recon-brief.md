# 実査ブリーフ: account-deletion-billing-guard (猶予期間つき削除と保持期間)

> lctl 台帳の正典設計と aicue の実コードを突き合わせた調査結果 (2026-08-08 実査)。
> オーナー判断が 2026-08-09 に出たため、着手可能になった。

## オーナーの決定 (設計の前提。逸脱不可)

裁定 AG-128 は猶予日数と保持年数の「値」を各アプリのプロダクト判断に委ねている。
オーナーの指示は**「一般的なもので」**であり、以下に確定した。

| 項目 | 値 | 根拠 |
|---|---|---|
| 猶予期間 | **30 日** | 家系の既定 (aigenba)。オーナー明示 |
| 課金取引記録の保持 | **7 年** | 法人税法の帳簿書類の原則保存期間。オーナー明示 |
| 猶予中の扱い | **凍結方式** | 標準形の要諦「猶予は凍結方式なら users 行の生死を変えず波及を抑えられる」 |
| 規約文面 | **家系の先例に揃える** | spirux の /privacy が持つ「取引関係書類等につき最長 7 年」相当 |

### 規約文面について (重要な制約)

**保持年数の宣言を `resources/views/legal/privacy.blade.php` へ追記する**。標準形 (3) が求める
「規約が宣言する年数と実処理の対応づけ」の相手を作るためで、これが無いと (3) は着手できない。

ただし守ること:
- **文面は家系の先例 (spirux の /privacy) に揃える**。独自の法的主張を書き起こさない。
- **`config/legal.php` の `consent_version` を `draft-1` から動かさない**。版の確定は
  リリース時のオーナー判断であり、本タスクの範囲外 (aicue:T136 のスコープ外節を参照)。
- **追記した文面は法務レビュー前の草案である**旨を、設計と実装の申し送りに明記する。
  「実装が宣言する年数」と「法務が確定する年数」が一致することの確認は人間の仕事である。

## 台帳が確定させた標準形

裁定 AG-128 (2026-08-08) が標準形 v1 を「不足 3 点込み」で確定した。必須は (1) 決済事業者側データの扱い (退会経路から事業者 API を呼ばない原則の機械化 + redaction の記録/運用手順)、(2) 猶予期間つき削除 (誤操作救済の予約 + 即時削除の併存 + 日次執行バッチ)、(3) 保持期間 (規約が宣言する年数と実処理の対応づけ) の 3 つで、これらを課金機能を持つ全アプリ (template / aicue / aigenba) に配る。猶予日数と保持年数の「値」は各アプリのプロダクト判断に委ね、「仕組みを持つこと」だけを必須とした。v1 の実体は spirux:T1133 の実装。設計上の要諦は「削除ガードは許可判定の SoT からの差分で書く」「猶予は凍結方式なら users 行の生死を変えず波及を抑えられる」の 2 点。

## aicue の現状 (実在確認済み)

課金ガード本体 (T115) のみ実在し、猶予期間・保持期間・redaction 記録は 1 行も無い。実在を確認したもの: app/Services/Billing/AccountDeletionBillingGuard.php (hasLiveBillingObligation / orphanBillingOrganizationIds)、app/DataTransferObjects/Organizations/AccountDeletionBlockerDto.php、app/Enums/AccountDeletionBlockReason.php、app/Enums/AccountDeletionBlockerAction.php、OrganizationMembershipService::deleteAccount() (行ロック下再評価 → ValidationException) / organizationsBlockingDeletion() / organizationsWithoutOwner()、routes/web.php:214 の DELETE /settings/account (settings.account.destroy, middleware recent-auth) を受ける app/Http/Controllers/Settings/AccountController.php::destroy、routes/console.php の billing:detect-orphan-billing-organizations (daily()->onOneServer())、docs/architecture.md L805-844 「退会 (アカウント削除) の課金ガード (T115)」。テストは tests/Feature/Auth/AccountDeletionTest.php (16 本。うち「退会成功経路では決済事業者 API を呼ばない」「課金中でブロックされる経路でも決済事業者 API を呼ばない (解約を代行しない)」の 2 本)、tests/Feature/Billing/AccountDeletionBillingGuardTest.php、tests/Feature/Billing/DetectOrphanBillingOrganizationsCommandTest.php、tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest.php、tests/Architecture/RecentAuthRouteTest.php:30 (allowlist に settings.account.destroy)。不在を実査で確認: deletion_requested / deletion_purge / pending_deletion / stripe_customer_redacted はリポジトリ全文で 0 件、users 系 migration は 0001_01_01_000000_create_users_table.php と 2026_06_11_071031_add_two_factor_columns_to_users_table.php のみ、app/Models/User.php に SoftDeletes 無し (deleteAccount は $freshUser->delete() の物理削除)、config/legal.php の retention は inquiry_retention_days のみ、app/Console/Commands 配下に account 系 purge 無し (PurgeInquiriesCommand のみ)、docs/account-deletion-runbook.md 不在 (docs/inquiry-deletion-runbook.md のみ)、resources/views/legal/privacy.blade.php は「4. 開示・訂正・削除」だけのスタブで保持年数の記述ゼロ (config('legal.consent_version') = 'draft-1')。組織削除の route は routes/web.php に存在しない (Organization は SoftDeletes を use するが削除経路なし) ため spirux の「両扉」問題は aicue には無い。T124〜T135 でこの機能に触れた変更は無い (git log 実読)。

## ギャップ

1. (2) 猶予期間つき削除が完全に未実装 — users に予約列が無く、退会予約 route も取消 route も日次執行バッチも存在しない。
2. (2) の帰結として「生きているが退会予約中」の第 3 状態を表現する middleware / props / 画面が無く、誤操作の救済経路がゼロである。
3. (3) 保持期間の実装が無く、config/legal.php は inquiry_retention_days しか持たず、課金取引記録の保持年数と匿名化処理を対応づける仕組みが存在しない。
4. (3) の前提である規約文面が未確定で、resources/views/legal/privacy.blade.php に保持年数の宣言が 1 行も無い (spirux が実装できた根拠 = /privacy の既存記述 が aicue には無い)。
5. (1) 決済事業者側 redaction が docs/architecture.md の運用注記のみで、記録列 (aigenba の stripe_customer_redacted_at 相当) も runbook ファイルも一次情報 URL の pin も無い。
6. (1) の「退会経路から決済事業者 API を呼ばない」原則が Feature テスト 2 本の behavioral 検査だけで、依存閉包を検査する静的 gate (template の AccountDeletionPathGateTest / spirux の DeletionPathNoStripeCallTest 相当) が tests/Architecture 配下に無い。

## 想定スコープ

【新規】database/migrations に users への予約列追加 (deletion_requested_at + 猶予日数スナップショット or purge_after) と organizations への redaction 記録列; config/account.php 新設 or config/legal.php 拡張 (grace_days / billing_retention_years); app/Http/Middleware/EnsureAccountNotPendingDeletion.php (凍結方式を採る場合) + bootstrap/app.php の alias 登録と appendToPriorityList; app/Console/Commands/Account/PurgeDeletionRequestsCommand.php と課金保持レコードの purge コマンド (PurgeInquiriesCommand.php が dry-run 既定 + --apply の先例); docs/account-deletion-runbook.md。【変更】routes/web.php (settings.account.destroy の隣に予約 POST と取消 DELETE を追加。課金ゲート group の外/内の判断が要る); app/Http/Controllers/Settings/AccountController.php (destroy に予約/即時の分岐); app/Services/Organization/OrganizationMembershipService.php (deleteAccount の lockForMembershipWrite 内で予約状態を再評価); app/Http/Controllers/Settings/ProfileController.php と resources/js/pages/Settings/Index.svelte / resources/js/types/account.ts (予約中バナー・取消導線); routes/console.php (Schedule 追加); docs/architecture.md L805-844 の節を拡張。【テスト新規】tests/Architecture/AccountDeletionPathGateTest.php (退会経路の依存閉包から Stripe SDK へ到達しないことを PhpToken::tokenize で静的検査。CachePayloadPlainDataGateTest.php が「語彙分類 → 目録 exact-fit → 空振り検知 → 自己参照コントロール → 正負 fixture」という書式の見本で、これに倣うのが当たり); tests/Feature/Auth/AccountDeletionGraceTest.php (予約→取消→執行、予約中のガード再評価、TOCTOU); tests/Feature/Billing/BillingRetentionPurgeTest.php; tests/js/pages/SettingsIndex.test.ts 拡張。【既存 gate の更新が要るもの】tests/Architecture/RecentAuthRouteTest.php の allowlist (新 route 2 本)、ControllerAuthorizationGateTest (変更系 route の認可 or exemption 登録)、ThrottleCoverageInventoryTest (認証面の変更系はレーン割当が必須。inline throttle は T125 で使用不可)、MembershipWriteLockInventoryTest (deleteAccount 周辺の書き込み経路目録)、SecurityEventCoverageTest (退会予約/取消の監査イベントを足す場合)、ModelDirectFetchInvariantTest + DirectFetchInventory (purge バッチが主キー同一性クエリを書く場合)。

## リスク

最大のリスクは「生きているが退会予約中」という第 3 状態の波及。aicue の users は物理削除前提で、FK cascade / nullOnDelete、CipherSweet の blind index (email_index) による一意照合、passkey、OAuth セッション、招待の email 照合 (emailBelongsToMember / hasPendingInvitation) がすべて噛み合っており、予約中ユーザーをログイン・招待・メンバー一覧でどう扱うかを誤ると既存の招待受諾 (T134 で作り直したばかり) とログイン導線を壊す。回避策は台帳に 2 通り実在する — aigenba は「予約中に機能制限を一切課さない」、spirux は「凍結 middleware + 業務 group 限定」。後者を採る場合、middleware の実行位置は bootstrap/app.php の priority list が正本であり、テナント境界 404 より前に 404 以外で短絡させると存在オラクルになる (AGENTS.md 不変条件 10)。次に route:cache 前提 (T135 / AGENTS.md 運用要件): 新 middleware を RouteMiddlewareBinder の後付けで配線すると cached 起動では 1 本も効かず無音で保護が外れるため、自前 route には route 定義側で直付けする。第三に deleteAccount の直列化契約 — ガードは lockForMembershipWrite (users 昇順 → organizations 昇順) の中で再評価される設計で、予約の作成/取消/執行を同じロック順序に乗せないとデッドロックか TOCTOU が入る。第四に AccountDeletionTest の既存 16 本は「ブロック時に副作用が漏れない」ことを固定しているので、予約分岐を destroy の前に挟むと既存アサーションが崩れる。最後に保持期間を config 値だけ先に焼き込むと、規約文面確定時に作り直しになる (template も aicue も同じ理由で一度見送っている)。

## 実装者への申し送り

台帳の記述と実コードの食い違いを 2 点報告する。(a) feature_yaml の boundary が「aicue は route settings.account.destroy 相当を ProfileController が受ける」と書いているが、これは誤り。実際に DELETE /settings/account (routes/web.php:214、middleware recent-auth) を受けるのは app/Http/Controllers/Settings/AccountController.php::destroy で、ProfileController は /settings 画面の props (accountDeletionBlockers) を組み立てるだけの読み取り側である。boundary の列挙にも AccountController.php が入っていない。(b) aicue セルの verification.files_touched は 2026-08-06 時点のままだが、この機能に関する実装は T115 以降 1 行も動いていない (git log 実読。T124〜T135 はいずれも別領域) ので、観測点 aicue@ad8c6a3 の判定「猶予期間つき削除と保持期間が実在しない」は 2026-08-08 現在も正しい。実装者への申し送り: (i) spirux の最重要知見「削除ガードは許可判定の SoT からの差分で書く」は aicue には既に別解で入っている — AccountDeletionBillingGuard の docblock が「これは entitlement の判定ではない」と明記し、BillingAccess / SubscriptionService::deriveEntitlement とは別の問い (将来の請求を発生させうる subscription が残っているか) に答える設計になっているので、ここを spirux 形へ作り替える必要は無い。(ii) aicue には組織削除の route が存在しない (routes/web.php に organizations.destroy 無し) ため、spirux が塞いだ「両扉」(退会と組織削除の判定不一致) は aicue では発生しない。(iii) 静的 gate を書くなら tests/Architecture/CachePayloadPlainDataGateTest.php を見本にすること — 「何を保証し、何を保証しないか (誇張しない)」を冒頭 docblock に明記し、PhpToken::tokenize で解析し (regex だと説明コメント自身で偽赤になる)、空振り検知と自己参照コントロールと正負 fixture を必ず持つ、という本リポジトリの gate 書式がそのまま使える。(iv) 決済事業者仕様 (90 日 / 最大 30 日) は docs/architecture.md 自身が「台帳側に一次情報の URL が pin されていない。数値を運用に効かせる前に一次情報を引き直せ」と書いているので、runbook 化するなら Stripe 公式ドキュメントの URL と確認日を同時に入れること。

## 設計で決めるべきこと

1. **凍結方式の具体形**。標準形は「users 行の生死を変えない」と言うが、aicue の現行
   `deleteAccount()` は `$freshUser->delete()` の物理削除である。予約状態をどう表現し、
   既存の物理削除経路 (即時削除) とどう併存させるか。標準形は**両方の併存**を必須としている。
2. **凍結中に何を止めるか**。標準形の要諦は波及を抑えることなので、止める範囲は最小にすべき。
   ログインと取消は必ず可能でなければ誤操作救済にならない。業務 route group を止めるか、
   それとも一切止めないかを、**行き先のない詰みを作らない** (AGENTS.md ドメイン規約 4 と同じ思想)
   という観点から決める。
3. **保持年数と実処理の対応づけをどう機械化するか**。規約の文面と `config/legal.php` の値と
   実際の匿名化処理の 3 者が一致していることを検査で固定する形が要る。文面は自然言語なので
   **何をどこまで機械照合できるか**を明確にし、できない部分は「保証しないもの」に書く。
4. **決済事業者側 redaction の記録**。記録列と runbook が無い。一次情報 URL の pin も含め、
   どこまでを本タスクに入れるか (標準形 (1) の必須範囲を確認すること)。
5. **依存閉包の静的 gate**。「退会経路から決済事業者 API を呼ばない」は現在 Feature テスト
   2 本の behavioral 検査だけ。template / spirux が持つ静的 gate 相当を入れるか。

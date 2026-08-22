# クローズ済み TODO 台帳の棚卸し報告

- 実施日時: 2026-08-17 09:32 (Asia/Tokyo)
- 対象: `docs/TODO-closed.md` の Closed / Obsoleted 全 entry
- 実施体制: 4 体の棚卸しエージェントが ID 範囲で分担 → 本報告が 1 本にまとめたもの
- 本報告の性格: **記録のみ**。台帳の書き換えも、機能の実装も、TODO 化も行っていない

---

## 1. 目的

T053 (「動画一覧の並べ替え・自作フィルタ・作成者/更新日メタ表示・**原稿検索**」) の記述のうち
**原稿検索が実装されていない**ことが、後続タスクの設計中に発覚した。
クローズ済みと書かれている台帳の記述が、実際のコードと食い違っていた事例である。

台帳は「何が既にあるか」の判断材料として繰り返し参照される。1 件でも過大申告があると、

- 未実装の機能を「済み」と誤認して要件から落とす
- 既にある機能を再実装する
- 設計時に誤った現行仕様を前提にする

という形で、以後のすべての設計へ伝播する。そこで **T053 が例外なのか、同種の食い違いが
他にもあるのか**を確かめるために、クローズ済み台帳を全件棚卸しした。

---

## 2. 検証範囲と方法

### 2.1 範囲

| 分担 | 範囲 | 検査した entry 数 | 照合した主張の数 |
|---|---|---|---|
| A | T001 〜 T050 | 50 | 124 |
| B | T051 〜 T100 | 48 | 62 |
| C | T101 〜 T150 | 47 | 38 |
| D | T151 〜 最後まで | 46 | 38 |
| **計** | | **191** | **262** |

- B の担当範囲で Closed 表に行があるのは 48 件 (T085 は `docs/TODO.md` の Open に残存、
  T087 は Open / Closed どちらにも行が無い)。いずれも「クローズ済みの過大申告」ではないため
  指摘には挙げていない。
- C の担当範囲は実在 ID 45 種 / 行数 47 行。**T141 と T142 が 2 行ずつ重複採番**されている
  (T141: 「bug-hunt finding のアプリ側修正」と「退会経路の依存閉包 gate (PR-A)」/
  T142: 「bug-hunt 基盤の既知不具合 4 件」と「退会の猶予期間つき削除 (PR-B)」)。
  これは実装の過大申告ではなく台帳の採番衝突なので指摘には挙げていないが、
  **「T141 を見よ」という参照が一意に解決できない**点は記録しておく。

### 2.2 方法

各 entry の本文から**利用者から見える主張**を抜き出し、次の 3 点を現行コードに当てた。

1. 実体 (ファイル / route / DTO キー / migration / component) が実在するか
2. 画面へ配線されているか (component が page から import されているか)
3. 主張どおりの条件分岐になっているか

道具は `rg` / `grep` / `find` / `ls` と部分 Read。テストクラス名・ファイルパス風トークンは
機械抽出して全数の実在を照合した (C の分担では backtick 識別子 382 個・パス風トークン 29 件)。

### 2.3 判定語彙

| severity | 意味 |
|---|---|
| `overclaim` | **一度も実装されていない**と判断したもの (痕跡が見つからない) |
| `reverted` | **実装されたが後続の TODO が意図的に置き換え / 撤去した**もの。台帳の記述だけが当時のまま残っている |
| `unclear` | 実装されたのか一度も無かったのか**判定できなかった**もの |

---

## 3. この棚卸しが保証しないこと

誇張しないために、保証の範囲を先に明示する。

1. **探索の網羅性は保証できない。** 「無い」の判定は検索語の選び方に依存する。
   別の名前で実装されている機能を見落とした可能性は排除できない。
   本報告で指摘 0 件だった範囲 (T101〜T150) は「食い違いが無いことを証明した」のではなく
   「**その方法では見つからなかった**」という意味である。
2. **`severity=unclear` の意味。** これは「実装されたのか一度も無かったのか判定できなかった」
   という保留の札であり、**問題が無いという意味ではない**。今回 `unclear` を付けた指摘は
   0 件だが、これは全件を確信を持って分類できたという意味ではない —
   確信の持てないものは指摘に積まず、§6 の「判定を保留した論点」へ散文で記録する方針を
   4 体とも採ったためである。
3. **git 履歴を一切見ていない。** 任務の制約により `git log -S` 等を実行していないため、
   「一度は存在したが後で消された」ことの直接の裏取りができていない。`reverted` の判定は
   すべて **置き換え側 TODO の記述・migration のコメント・テストのコメント・設計 devnotes**
   による間接的な根拠に基づく推定である。同様に、台帳が書く
   「既存テストの削除・無効化・緩和はゼロ」「アプリコードは 1 行も変更していない」といった
   **差分に関する主張は原理的に確かめていない**。
4. **テストを 1 本も実行していない。** テストファイルは**実在の確認まで**で、中身が主張どおりの
   検出力を持つか (空振りしていないか) は見ていない。gate が本当に赤くなるかも未検証。
5. **数値の主張は一切検証していない。** 「composer test 3313 tests passed」「mutation M1〜M17 全件で
   赤化」「候補実測 61」「exemption 11 件 (cap 14)」等が実際の数と合っているかは分からない。
6. **見た目・実機挙動は検証していない。** CSS の崩れ (T062) は class 指定の存在まで、
   実機受入が要る項目 (T185 の iOS Safari / T190 の実ブラウザ / T191 の iOS / T192 の EXIF 向き) は
   台帳自身が未達と明記しているため対象外とした。
7. **Codex レビューの結果は未確認。** entry が書く impl-review の APPROVED / Round 数は
   `codex-history` を読んでいないため確認していない。
8. 並行タスクが同じ作業ツリーへ書いている可能性があるため、**ごく最近の変更は
   反映されていないかもしれない**。

> **本報告をまとめるにあたって追加の検証は行っていない。** §4 の主張・証跡・判定は
> 各棚卸しエージェントの報告をそのまま収めたものであり、断定を強めたり指摘を増やしたりしていない。

---

## 4. 指摘の一覧

指摘は **8 件** (overclaim 2 / reverted 6 / unclear 0)。うち利用者から見えるものが 7 件。

### 4.1 overclaim (一度も実装されていないと判断)

#### T053 — 原稿検索

- **主張**: 「動画一覧の並べ替え・自作フィルタ・作成者/更新日メタ表示・**原稿検索**。
  一覧に並替(PC)/自作filter/作成者・更新日メタ追加・原稿検索」のうち **原稿検索**
- **証跡**: 検索語 (`?q=`) の解析点は `app/DataTransferObjects/Manual/ManualListQuery.php` の
  `$keyword` で、これを使う唯一の箇所は `app/Http/Controllers/Projects/ProjectController.php` の
  `manualRows()` 内 `$baseQuery->where('title', 'like', …)` の 1 条件のみ。
  cuts / narration / subtitle を対象にした検索は存在しない:
  (a) `grep -rn "whereHas('cuts'" app/` は 0 件、
  (b) `grep -rn "'narration'|'subtitle'" app/ | grep -i "like|where|search"` は 0 件、
  (c) `find app -iname "*search*"` は 0 件、
  (d) `grep -rn 原稿 app/ resources/js/` の hit は `TakePreviewPanel.svelte` の
  「ナレーション原稿を表示」トグル (テイクプレビューでの原稿テキスト表示。検索とは無関係) のみ。
  `ManualListQuery` の docblock 自身も「title の validation が max:200 なので、201 文字目以降が
  一致に寄与することは無い」と書いており、keyword が title 専用であることを前提にしている。
  なお同 entry の**他 3 要素は裏付けあり**: 並べ替えは `ManualSortOption` + `Projects/Show.svelte` の
  `manual-filter-sort`、自作フィルタは `$listQuery->mine` + `manual-filter-mine`、
  作成者/更新日メタは `ManualListItemData` の creator / updated_at を `ManualListRow.svelte:52` が表示。
  `docs/factories.md` や `doc/` 配下も含めて「原稿検索」に相当する実装を探したが痕跡が無く、
  reverted ではなく overclaim と判断した。git 実行禁止のため
  「一度でも存在したか」の確認は未実施。
- **判定**: `overclaim` / 利用者から見える

#### T008 — 通知ベルのドロップダウン

- **主張**: 「ベル (shared props 未読数 + ヘッダー **dropdown**)」
- **証跡**: `resources/js/components/molecules/NotificationBell.svelte` を全読した。実体は
  `<Link href="/notifications">` + 未読バッジだけで、コンポーネント冒頭コメントに
  「v1 はドロップダウンなし = フォーカス管理/状態を持たない最小構成」と明記されている。
  `resources/js/components/templates/AppLayout.svelte:218,236` も `<NotificationBell {unreadCount} …/>` を
  置くだけで dropdown の開閉状態を持たない。`resources/js` 全体を `grep -rn 'dropdown'` しても
  通知系の該当なし。さらに **T008 自身の設計 devnotes**
  (`devnotes/20260711-2255-notification-center/codex-history/conceptual-review-prompt-round-1.md:206` ほか) が
  「ベルのドロップダウン化」を**スコープ外**として明示列挙しており、レビュー記録
  (`conceptual-review-round-1.md:30`) も「v1 でドロップダウン…を切っているのは適切」と応答している。
  つまり dropdown は最初から作らない裁定で、台帳の記述だけが実装したかのように書かれている。
  未読数 shared props と一覧ページ遷移は実在するので、**欠けているのは dropdown の 1 点のみ**。
- **判定**: `overclaim` / 利用者から見える

### 4.2 reverted (実装されたが後続 TODO が置き換え / 撤去)

#### T006 — /manage 配下 (Users/Categories) + AdminMenuNav + 招待への project_role

- **主張**: 「/manage 配下 (Users/Categories) + AdminMenuNav」「招待への project_role 追加
  (Default Project 自動割当・受諾時 project 消失は未割当へ縮退)」
- **証跡**:
  (1) **AdminMenuNav**: `grep -rn 'AdminMenu'` の結果、実装ファイルは 1 つも無く、ヒットするのは
  撤去を固定するテストのコメントのみ (`tests/js/pages/AdminUsers.test.ts` / `AdminCategories.test.ts` の
  「独自二次左メニュー(nNav)は撤去済み (aigenba parity, T071)」/
  `tests/js/architecture/deprecated-imports.test.ts` が
  `@/components/features/admin/nNav.svelte` の再導入を禁止)。→ **T071 で撤去**。
  (2) **/manage/categories**: `routes/web.php` を `grep -n 'categories'` すると route は
  503-513 の `/projects/{project}/categories` のみで、/manage 配下は 310 行目の `manage.users.index` だけ。
  カテゴリ管理はプロジェクト詳細配下へ移設済み (同じく T071。
  `tests/js/pages/ProjectsShow.test.ts` の「T071: nNav 導線の移設先」)。
  (3) **招待の project_role**: 追加 migration
  (`2026_07_11_110000_add_project_role_to_organization_invitations_table.php`) の後に
  `database/migrations/2026_08_07_210000_drop_project_role_from_organization_invitations_table.php` があり、
  冒頭コメントが「役割付き招待の撤去 (裁定 AG-079。Default Project という概念自体が不要という
  オーナー判断の帰結)」と述べる。台帳の T134 (「アプリ内招待受諾の追加と役割付き招待の撤去」) が
  置き換え元。`app/` を `grep -rln 'project_role'` してもアプリコードに参照は残っていない。
- **判定**: `reverted` / 利用者から見える

#### T010 — BillingAccess の entitlement 判定 / has_billing_access

- **主張**: 「BillingAccess を billing entitlement 判定へ書き換え (Free=plan_code null は許可・
  有償契約中のみ active/trialing を要求)」「ダッシュボード callout 整合
  (has_billing_access リネーム + 文言/CTA 更新)」
- **証跡**: `app/Services/Billing/BillingAccess.php:26-43` が「**`plan_code` は entitlement 判定に
  一切使わない**」「移行 OR (`plan_code === null` を通す 1 行) はゲート反転で削除済み」と明記。
  無料枠は `organizations.free_plan_code = 'personal'` の明示申告 (`ActiveFreePlan`) へ
  置き換わっている。置き換えた TODO は **T075** (「決済parity P4: ゲート反転+grandfathering移行。
  BillingAccess の移行 OR 1 行を削除し state()->grantsAccess() 一本へ」)。また
  `grep -rn 'has_billing_access|hasBillingAccess' app/ resources/js` は 0 件で、
  T010 が導入したと書く shared prop 名も現存しない。遮断理由の明示 (JSON 402 / onboarding 着地) は
  T075 が引き継いでおり、利用者から見た「無言リダイレクトしない」性質自体は残っている。
- **判定**: `reverted` / 利用者から見える

#### T020 — Seeder の plan_code=free 是正

- **主張**: 「Seederのplan_code=free是正でfree無償許可(誤締め出し解消)」
- **証跡**: `database/seeders/PlanSeeder.php:24-25` のコメントが「free entitlement は
  `organizations.free_plan_code='personal'` で表現する。plan_code は entitlement 判定に使わない
  (quota 解決キーであり、利用可否は…)」となっており、seeder は無料組織に plan_code を
  載せない方式へ変わっている。台帳が挙げるテスト
  `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php` も 20 行目・49-51 行目で
  「seeder が不変条件を守っている: plan_code は載せず、無料枠は free_plan_code の明示申告で表現する」と
  現行仕様を固定している。置き換えた TODO は **T075** (ゲート反転)。
  利用者から見た結果 (無料組織の全ロールが /projects に到達できる) は維持されているので、
  消えたのは機構だけ。
- **判定**: `reverted` / 利用者から見える

#### T021 — 新規登録時のチケット 10 枚付与の契機

- **主張**: 「新規登録時のチケット10枚付与。登録完了(**CreateNewUser**)で無料チケット10枚を付与」
- **証跡**: `app/Actions/Fortify/CreateNewUser.php:109-113` に「初回 signup grant はここでは付与しない
  (P6/F2)。付与契機はプラン有効化時 (free = `PersonalPlanService::activate` /
  paid = `customer.subscription.created`)」と明記され、登録処理は組織の用意のみを行う。
  実際の付与点は `app/Http/Controllers/Onboarding/ActivatePersonalController.php:64` と
  `app/Services/Billing/StripeWebhookProcessor.php` (`grantInitialTickets`)。
  置き換えた TODO は **T077** (「決済parity P6: grant契機変更。signup grant を
  customer.subscription.created / free activate へ移設 (F2)」)。枚数 10 枚自体は
  `config/billing.php:23` の `signup_grant_tickets` 既定 10 で維持されており、二重付与防止の
  部分 UNIQUE index (`tests/Feature/Architecture/SignupGrantUniqueIndexInvariantTest.php`) も現存する。
  **台帳の記述で現行と食い違うのは「登録完了(CreateNewUser)で」という契機の部分だけ**。
- **判定**: `reverted` / 利用者から見える

#### T182 — 行 props の `downloadable`

- **主張**: 「行 props は DTO (`ManualListItemData` + `ManualListRefData`) に集約し、`duration_ms` … /
  **`downloadable`** (download endpoint が 302 を返す条件と 1 対 1) / `deletable` を追加した」
- **証跡**: `app/DataTransferObjects/Manual/ManualListItemData.php` の `toArray()` が返すキーは
  id / title / progress / category / creator / created_at / updated_at / duration_ms /
  current_finished_render_job_id / deletable で、**`downloadable` は存在しない**
  (`rg -n "downloadable" app resources/js` のヒットは
  `app/Http/Middleware/EnsureLoginMethodRemains.php` の無関係なコメント 1 件のみ)。
  これは **T189** (動画一覧からのオーバーレイプレビュー) が
  `downloadable: bool` → `current_finished_render_job_id: int|null` へ**置換**したと
  台帳自身に明記しており、あわせて **T197** が 5 値 `status` を行 payload から外して
  testId も `manual-progress-*` へ改名している。利用者から見える機能
  (再生時間表示・DL・行内削除) は
  `resources/js/components/features/manual/ManualListRow.svelte` に現存し、
  `formatDurationMs` (`resources/js/lib/manual/format-duration.ts`) ・
  `tests/Feature/Projects/ManualRowActionsTest.php` ・ `ManualListQueryCountTest.php` ・
  `ManualRowAbilityPremiseTest.php` も実在するため、**失われたのは prop 名の記述だけ**。
- **判定**: `reverted` / 利用者から見える

#### T162 — `billing:recover-stale-webhook-events` コマンド

- **主張**: 「5 分ごとの cron `billing:recover-stale-webhook-events` が `received` かつ
  `updated_at` が 15 分より古い行を拾い直す」
- **証跡**: リポジトリ全体を `rg -n "recover-stale-webhook-events"` で検索したところ、ヒットは
  `docs/TODO-closed.md` ・ devnotes ・ `tests/Architecture/RetiredRecoveryReferenceGateTest.php`
  (35 行目に撤去対象として列挙) だけで、`app/Console/Commands` 配下にも `routes/console.php` にも
  このコマンドは存在しない。ただしこれは **T171** (滞留回収 5 経路を共通基盤へ寄せ替える) が
  意図的に置き換えたもので、現行は `app/Enums/Recovery/RecoveryStream.php` の
  `case WebhookEvent = 'webhook_event'` + `app/Services/Recovery/Streams/StaleWebhookEventStream.php` +
  `routes/console.php:36` の `Schedule::command('work:recover-stuck --stream=…--apply')` で
  同じ回収を行っている。**機能自体は生きており、消えたのはコマンド名だけ**。
  `HandledStripeWebhookEvent::replaySafety()` (`app/Enums/Billing/HandledStripeWebhookEvent.php:48`) と
  `WebhookReplaySafety` の 2 値分類は現存を確認済み。
- **判定**: `reverted` / 利用者から見えない (運用系)

---

## 5. 判定ごとの件数と、次にすべきこと

| 判定 | 件数 | 対象 | 次にすべきこと |
|---|---|---|---|
| `overclaim` | 2 | T053 (原稿検索) / T008 (ベルの dropdown) | **機能を作るか、作らないと決めるかの判断が要る**。台帳の記述を直すだけでは要件が消える |
| `reverted` | 6 | T006 / T010 / T020 / T021 / T162 / T182 | **台帳の記述を現行に合わせるかどうかの判断**。機能は生きている (または意図的に落とされている) ので実装作業は発生しない |
| `unclear` | 0 | — | — |
| 指摘なし | 183 entry | — | — |

### 5.1 判断の分岐 (選択肢の提示であり、着手ではない)

**overclaim 2 件** — 「台帳を直す」だけでは足りない。要件そのものが宙に浮いている。

- **T053 の原稿検索**: `doc/05_スマホアプリ機能仕様.md` §5.2 が要求している。
  本棚卸しと同日に `devnotes/20260817-0909-manual-search-scope/` として設計済み。
  台帳側の T053 の記述をどう直すかは未決。
- **T008 の dropdown**: T008 自身の設計 devnotes が**スコープ外と明示裁定していた**ため、
  「作らないと決めたものを台帳が実装したように書いた」形である。
  作る判断をするなら新規要件、作らない判断をするなら台帳の記述訂正で閉じる。

**reverted 6 件** — 実装作業は発生しない。台帳の書き方の問題である。

- 選択肢 (a): 各 entry に「後に T0xx が置き換えた」旨の追記をする
- 選択肢 (b): 履歴として当時のまま残し、台帳は「その時点の記録」と割り切る
- どちらを採るかで台帳の性格 (現行仕様の索引 / 作業履歴) が決まるため、**方針の決定が先**。

**台帳の構造上の問題 (指摘には数えていないが記録する)**

- **T141 / T142 の重複採番** (§2.1 参照)。ID による参照が一意に解決できない。
- ID 採番規約 (`docs/TODO.md` ヘッダ) は「全体を通した最大 ID + 1」なので、
  重複は規約違反ではなく運用事故である。

### 5.2 本報告の扱い

- **`docs/TODO-closed.md` は書き換えていない。** 記述を直すかどうかは人間の判断待ちである。
- **棚卸しの指摘は TODO 化していない。** 上表の「次にすべきこと」は選択肢の提示であって、
  着手を意味しない。

---

## 6. 判定を保留した論点 (指摘に挙げなかったもの)

確信の持てない推測を指摘に積むことは避け、以下は散文で記録するに留めた。

1. **T003 / T005 の「stale 回復 cron」**: 個別コマンドとしては存在せず、
   `app/Enums/Recovery/RecoveryStream.php` (AnalysisJob / RenderJob / TicketReservation /
   WebhookEvent / UploadReservation) を回す `work:recover-stuck --stream=… --apply`
   (`routes/console.php:35-45`) へ統合されている。機能は残っているので挙げていない。
2. **T036 の `--real-storage` オプション**: `scripts/bug-hunt-shard.sh:36` が自ら
   「※実 S3 配線は未実装 = inert トグル」と注記している。フラグ自体は実在し
   `TESTING_FAKE_STORAGE=false` を注入する (同 292 行) ので overclaim とは呼べないと判断した。
3. **T072 の「D28: 月次チケット付与を廃止」**: `database/seeders/PlanSeeder.php` は全 tier
   `monthly_ticket_grant=0` で、`resources/js` ・ DTO 側に `monthlyTicketGrant` は 1 件も残っていない
   (grep 済み) ため括弧内の主張は裏付けられる。ただし
   `app/Services/Billing/StripeWebhookProcessor.php:635` の `invoice.paid` 月次付与コード自体は残っており
   (grant=0 のため実質 no-op)、`app/Filament/Resources/PlanResource.php` では管理画面から
   `monthly_ticket_grant` を 0 以外に編集できる。T076 の entry がこの窓を
   「構造的に到達不能」と明記しており、entry の記述と実装は整合すると判断した。
4. **T099 の「4 レーンを global-test-lock へ一本化」**: 現行の
   `tests/Architecture/GlobalTestLockInventoryTest.php` が管理するのは lane スクリプト 3 本
   (`run-test.sh` / `run-browser-test.sh` / `run-vitest.sh`) + 汎用ラッパ
   `with-global-test-lock.sh` の計 4 entrypoint で、`scripts/phpstan.sh` にはロックが無い。
   「4 レーン」は 4 entrypoint を指すと読めるため誇張とまでは言えず、
   かつ利用者から見える機能ではないので挙げていない。
5. **T062 (mobile 375px 崩れ修正)**: CSS の見た目の主張であり、`TakeStrip.svelte` に
   flex-wrap / `sm:` ブレークポイント指定が入っていることの確認までしかしていない (実描画は未検証)。
6. **T094 (課金 UI 入力 UX 横断是正)**: vitest 側に F-3-02/03/05 に対応するテストが
   実在することの確認に留めた。
7. **内部 gate / Architecture テストの主張** (`ManageRouteAuthGuardTest` /
   `ProjectMemberPivotWritePathTest` / `EnvExampleInvariantTest` /
   `ValidationAttributeCoverageTest` / `RecentAuthRouteTest` /
   `SignupGrantUniqueIndexInvariantTest` / `PlanSeederPriceInvariantTest` /
   `SeededFreePlanBillingAccessTest` / `ManualTestSeederTest` ほか): ファイル実在の確認のみで、
   中身の妥当性までは追っていない。

---

## 7. 裏付けが取れた主なもの (参考)

指摘に挙げなかった = 現行コードで裏付けが取れたもののうち、利用者から見える主なものを記録する。
**この一覧は網羅ではない** (§3-1)。

- **T001** `EnsureProjectBelongsToRouteOrganization` / `project.in-route-org`
- **T002** `ScenarioService` + `ScenarioEditor.svelte` の 409/419 復帰
- **T003** `SourceDocumentController` / `AnalysisPipeline` / `resources/prompts` の 3 YAML / `AnalysisPanel.svelte`
- **T004** `TakeUploadService` の 2 フェーズ予約 / `QuotaService` / `capture:purge-upload-reservations`
- **T005** `FfmpegVideoComposer` の `clip{n}.ass` 経由字幕 / `RenderPanel.svelte` / `RenderJobService` の世代交代判定
- **T007** `Welcome.svelte` ・ `/pricing` ・ `TicketCheckoutService` の attempt_token 冪等と live pending dedup ・ `PlanSeeder` の standard 4980
- **T009** `DashboardService` + DTO 5 種
- **T011** `FortifyServiceProvider:527` の `confirmPasswordView` → `recent-auth.confirm`
- **T013** `VerificationNotificationSentResponse` / `SidebarUserMenu` の設定・ログアウト常設
- **T014** `Settings/Security.svelte` のリカバリコード UI / `Organizations/Settings.svelte` の移譲 UI
- **T016** `SeoManager::setPrivateTitle` の呼び出し 6 箇所 + `ManualPageTitleTest`
- **T017** `camera.ts` の `classifyGetUserMediaError` / `CameraRecorder` の `onCameraUnavailable` 必須 prop
- **T019** `AppLayout` の組織切替 + 請求ナビ
- **T025** `OrganizationMembershipService` の唯一オーナー保護
- **T027** `GuestLayout` のモバイルメニュー / **T028** `Projects/Show.svelte` の members.store/destroy
- **T030** `acceptInvitationIfValid` / **T031** `EmailChangedSecurityNotification`
- **T037** `Capture/Show.svelte` の `grid-cols-1` / `min-w-0` / **T039** `docker/Dockerfile:11` の ffmpeg
- **T042** `PasswordInput` molecule / **T043** `TakeStrip.svelte` の削除確認ダイアログ
- **T045** `Welcome.svelte:392` / `Guest/Pricing.svelte:275` の特商法リンク
- **T046** `ScenarioBookendBuilder` / **T047** `CameraRecorder` の字幕 overlay トグル
- **T048** `ScenarioEditor` の undo/redo スタック / **T050** `TakePreviewDialog` の字幕トグル
- **T049** `projects.manuals.duplicate` route + `DuplicateVideoManualRequest` + `ManualDuplicateTest`
- **T103** `EnsureProjectBelongsToApiOrganization` + `Api/V1/ItemController` の `Gate::forUser` authorize 3 箇所
- **T106** `config/fortify.php:250` の `Features::passkeys(['confirmPassword' => false])`
- **T107** `POST /settings/password` + `PasswordCredentialService` + `RecentAuthRecoveryNotice.svelte` + `recent-auth.status`
- **T115** `AccountDeletionBillingGuard` + `AccountDeletionBlockerDto` + `billing:detect-orphan-billing-organizations`
- **T129** `resources/js/pages/Error.svelte` + `app/Exceptions/InertiaExceptionRenderer.php`
- **T134** `invitations.accept.store` / `invitations.accept-in-app` + project_role の drop migration
- **T145** `resources/views/legal/privacy.blade.php:41-46` の「4. 保有期間」と `BillingRetention::years()` 描画
- **T148** `placeholder_cut_count` の migration・`RenderJob`・`RenderManifest`・`RenderPanel.svelte`
- **T154** 完成動画の playback route (`projects.manuals.render-jobs.playback`)
- **T155** 撮影画面から詳細への復路リンク (`Capture/Show.svelte:489`)
- **T156** コピー失敗時の手動コピー導線 (`CodeSnippet.svelte` の 4 値 `CopyStatus`)
- **T157** フィールドごとの編集世代による解除 (`BillingContactForm.svelte`)
- **T161** `/debug/bfcache-trial` route / **T163** `PaymentGracePolicy` ・ `past_due_since` ・ 日次 reconcile
- **T166** `PasskeyConfigValidator` / **T169** `app/Support/Llm` 一式と `config/llm-defense.php`
- **T171** `work:recover-stuck` / **T174** `OrganizationAccessRevoker` / **T178** `IssueSessionEpochCookie` + `SessionEpoch`
- **T182〜T200 の UI 群**: `ManualListRow` / `GenerateTakeThumbnailJob` + takes.thumbnail route +
  `ThumbnailRefreshScheduler` の 2s/4s/8s/15s / `CutTakeController` + `pages/Manuals/Takes.svelte` /
  `landscape-capture.ts` + `CutSwipeBar` / `lib/dnd` の 2 本 + `DragHandle` /
  `ScenarioEditor` の clientKey 解決 / `ManualPreviewModal` / `TakeHoverPreview` /
  `scenario-preview.ts` + `ScenarioPreviewDialog` の通し再生 /
  `takes.material_type` migration + `TakeMaterialClassifier` + `EffectiveMaterialType` +
  `StillDisplayDuration` + shoot-still /
  `ManualProgress` と `?progress=` 絞り込み UI / `VideoManual::coverCut` + `ManualCoverThumbnail` /
  `capture.account` route + `pages/Capture/Account.svelte` /
  `SopValidationData` + `ScenarioRuleCheck` + `ScenarioReportPanel`

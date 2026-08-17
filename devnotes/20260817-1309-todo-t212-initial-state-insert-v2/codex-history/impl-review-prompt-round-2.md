# 実装レビュー依頼 Round 2 (aicue T212 / NULL が初期状態を表す列の分類とスキーマ pin)

## アプリの使命 (North Star) — AGENTS.md より

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## 禁止事項 (AGENTS.md より)

1. テストなしの実装完了報告 (不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen (型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作 (`migrate:fresh` 等) をエージェント判断で実行すること
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び (app/Prompts/ の factory → 窓口 PromptDefense → 実行単位 GuardedPrompt の 1 本道のみ)
6. prompt 文字列のコード直書き (resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

あなたは Laravel + Svelte アプリのコードレビュアーである。Round 1 で CHANGES_REQUESTED を出した実装差分の**修正版**をレビューする。

観点: 設計との一致性 / 正確性 (純関数の論理・fail-closed・pin の抜け道) / 裁定 AG-191 への適合 / 保証の誇張が無いか / テスト網羅性 / PHPStan 適合性。
本変更は UI / API / LLM / migration に一切触れないため、DESIGN.md / Atomic Design の観点は対象外である。

**出力形式**: ファイルごとに判定を書き、指摘は [Critical] / [Warning] / [Suggestion] に分類する。最後に全体判定 (APPROVED / CHANGES_REQUESTED) を書く。

---

## user: Round 1 のあなたの指摘

**AGENTS.md**

[Warning] 設計では台帳 ID を `aicue:T212` 形式にする前提でしたが、追加文は `T212` になっています。`docs/architecture.md` も同じです。家系台帳との照合規律を明文化している変更なので、ここは `aicue:T212` に揃えるべきです。

**docs/architecture.md**

[Warning] 同上で、見出しが `T212` です。`aicue:T212` に修正してください。

[Suggestion] 「保証しないものは本書に写さない」は設計どおりです。ここで代表例を括弧書きしている程度なら許容範囲ですが、今後増やすと docblock と乖離しやすいので最小限のままでよいです。

**tests/Support/InitialState/NullInitialStateClass.php**

判定: 問題なし。区分 3 種、`match` 網羅、余計な default なしで設計どおりです。

**tests/Support/InitialState/NullableStateColumnEntry.php**

判定: 問題なし。`private constructor`、名前付き生成子、30 文字検査、`key()` 集約はいずれも設計どおりです。

**tests/Support/InitialState/NullableStateColumnRegistry.php**

判定: 大きな問題なし。

区分を初期案から動かした 6 列は、提示された実読根拠ベースでは妥当です。特に `users.email_verified_at` と `ticket_ledger_entries.carried_forward_through` は「生成時に非 NULL がありうる」ので `SetAtCreation` が正しいです。`plan_prices.*` / `ticket_volume_prices.*` は「作成時は NULL、後続同期や世代交代で既存行へ入る」という前提が正しければ `InitialStateMarker` で問題ありません。

**tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php**

[Suggestion] `NC-3` の代表値は設計上 `0` ですが、実装は文字列 `'0'` です。実スキーマ正規化では scalar を string 化するので実害は小さいものの、設計との完全一致を重視するなら `nullInitialStateNormalizeColumns()` 経由で integer `0` も点灯させるケースを足すとよいです。

[Suggestion] `NC-6` は「NI-3 が落ちる条件」を確認していますが、NI-3 の判定ロジック自体を純関数化して直接失敗させてはいません。現状の NI-3 本体に非空 assertion があるので穴ではありませんが、将来の弱体化検知としては少し弱いです。

[Suggestion] 作成・更新時刻の除外は、時刻型だった場合に `continue` するため、現在の意図どおり「時刻型としても enum cast としても入らない」動きです。現在スキーマ上の穴は見当たりません。ただし「lifecycle 列は無条件に母集団外」と読ませたいなら、lifecycle 判定を `$isTemporal` ブロックの前に出すと意図がより明確になります。

`nullInitialStateNormalizeColumns()` の fail-closed は、必要キー欠落と型不一致の両方を `problems` に積み、NI-3 で落とすため十分です。`name` 欠落時のメッセージも設計どおり成立しています。

NI-7 の 3 一覧 pin は、内訳 2 本を exact match し、その期待値 union と actual union も比較しているため、件数据え置きの入れ替わりは通しません。

**devnotes**

[Warning] 受け入れ条件 A14 の `devnotes/20260817-1309-todo-t212-initial-state-insert-v2/red-first.md` が、提示された `git diff HEAD` には出ていません。本文で赤の実測は提示されていますが、要件は「リポジトリ内ファイルとして存在」です。未追跡または差分漏れなら追加が必要です。

**全体判定: CHANGES_REQUESTED**

実装の中核は設計にかなり合っています。ブロック理由は主に、台帳 ID 表記の設計不一致と、red-first 記録ファイルが差分上確認できない点です。コード上の純関数・pin・AG-191 経路には致命的な穴は見当たりません。
---

## user: Round 1 への対応マトリクス (Claude 側)

# 実装レビュー Round 1 への対応マトリクス

| # | 分類 | 指摘 | 判断 | 対応 |
|---|---|---|---|---|
| 1 | Warning | `AGENTS.md` の台帳 ID が `T212` になっている。設計は `aicue:T212` 形式 | **対応する** | `aicue:T212` へ修正。既存の 規約 1〜16 は無印 (`T175` 等) だが、家系台帳との照合規律を書く条そのものなので、設計と Codex の求めに合わせて修飾する |
| 2 | Warning | `docs/architecture.md` の見出しも同じく `T212` | **対応する** | `aicue:T212` へ修正 |
| 3 | Warning | 受け入れ条件 A14 の `red-first.md` が差分に出ていない | **反論する (実体は存在する)** | Round 1 に渡した diff を `-- tests/ AGENTS.md docs/` に絞っていたための見落としで、ファイルは worktree に実在し追跡下にある。Round 2 の diff は `devnotes/` を含めて渡す |
| 4 | Suggestion | NC-3 の代表値 `0` が文字列 `'0'` になっている。整数 `0` の経路も足すとよい | **対応する** | 正規化の純関数 (`nullInitialStateNormalizeColumns`) を通した**整数 0** の合成入力を NC-3 へ追加した。正規化が文字列へ畳んでも「null でない」ことは変わらない = 判定が中身を見ていないことを示す |
| 5 | Suggestion | NC-6 は NI-3 の判定ロジック自体を純関数化していないので、将来の弱体化検知としては弱い | **見送る** | NI-3 の非空条件は `expect(...)->not->toBe([])` の 3 行であり、これを純関数へ切り出しても「その純関数を呼ばなくする」改変は同じように検出できない。層を 1 つ増やすだけで守れる事象が増えないため、思考原則 2 (今必要なものだけ作る) に従い足さない |
| 6 | Suggestion | 作成・更新時刻の除外判定を `$isTemporal` ブロックの前へ出すと意図が明確になる | **反論する (挙動は現状が正しい)** | 外へ出すと**時刻型ですらない同名の列**まで NI-7 の除外一覧に混ざり、pin の意味が「(a) に入るはずだったのに外した列」からずれる。挙動は変えず、**なぜ内側なのか**と「除外に当たった列は (b) の側にも入らない」ことをコメントで明示した |
| 7 | 確認事項 | 区分を初期案から動かした 6 列は妥当 (`users.email_verified_at` / `ticket_ledger_entries.carried_forward_through` は生成時に非 NULL がありうるので `SetAtCreation` が正しい) | 追加対応なし | `red-first.md` に実読根拠を記録済み |
| 8 | 確認事項 | `nullInitialStateNormalizeColumns()` の fail-closed は十分 / NI-7 の 3 一覧 pin は件数据え置きの入れ替わりを通さない | 追加対応なし | — |

---

## user: 修正後の実装差分 (git diff HEAD。今回は devnotes/red-first.md も含む)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index bc9b705..9a3c9c1 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -478,7 +478,8 @@ ## ドメイン固有規約
    新しい書き込み経路は inventory 登録が必須。**ただし allowlist はファイル粒度**であり、
    同一ファイル内のメソッド追加は検出しない (メソッド単位の fail-first は behavioral テストが担う)。
    テイク採用 API は検出 4 (`adopted_take_id` の deny-by-default 走査) で inventory 準拠済み。
-   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件
+   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件。
+   **NULL 自体が初期状態を表す列 (DB 既定値を持たない列) は本規約の母集団外**で、規約 17 の担当である
 2. **容量 Quota (max_storage_bytes) の予約規約**: presigned アップロードの容量判定は
    `Billing/QuotaService::checkAddition` + `Capture/StorageUsageService::occupiedBytes`
    (bytes_used + bytes_pending) 経由のみ。予約 (`take_upload_reservations`) の状態遷移は
@@ -488,7 +489,8 @@ ## ドメイン固有規約
    `save()` 直後の in-memory instance の属性欠落の両方を防ぐ。ドメイン規約 1 (ii) と同じ理由)。
    **これは状態遷移ではないので上の CAS 規約とは独立である**。
    migration の `default('pending')` は既存行と Factory 以外の INSERT 経路のために残す。
-   運用契約 (media queue worker / 孤児掃除 cron) は `docs/architecture.md` §撮影 PWA
+   運用契約 (media queue worker / 孤児掃除 cron) は `docs/architecture.md` §撮影 PWA。
+   **NULL 自体が初期状態を表す列 (DB 既定値を持たない列) は本規約の母集団外**で、規約 17 の担当である
 3. **サポート対象ブラウザと履歴復元の扱い**: 「どのブラウザで何をどこまで保証しているか」の
    正本は **`docs/supported-browsers.md`**。**Inertia が描画する認証済み画面**が
    ログアウト後に復元される経路は 3 本あり、**3 枚セット**で守る
@@ -793,3 +795,19 @@ ## ドメイン固有規約
       同 gate が字句で固定する。
     - 保証しないもの (発行との隙間 / API キーの読み取りが残ること / 静的検査の限界) は
       `docs/architecture.md` §組織アクセスの失効 が正本。運用向けの説明は `docs/mcp-oauth.md`
+17. **NULL が初期状態を表す列の分類 (aicue:T212 / 家系の正典 v2、裁定 AG-191)**:
+    実スキーマの **nullable かつ DB 既定値を持たない**列のうち、**時刻型の列**と
+    **BackedEnum へ cast された列**は、`tests/Support/InitialState/NullableStateColumnRegistry.php`
+    へ区分と 30 文字以上の根拠を 1 行足す (deny-by-default。
+    `NullInitialStateColumnClassificationTest` が実スキーマと両方向で突き合わせる)。
+    区分は 3 つで、判定は**「その行が生まれた時点で、この列は必ず NULL か」の 1 問**で決まる。
+    決められないなら「未確定」に載せる (隠さない。件数と列名を検査が pin する)。
+    - **登録済みの列に migration で DB 既定値を後付けすると赤くなる**。その列が母集団の条件
+      (`default === null`) から外れて「実在しない登録」になるためで、**CHECK 制約は使わない**
+      (制約を義務化しないという正典 i7 と衝突させない)
+    - **DB 既定値を持つ状態列は 規約 1 (ii) / 規約 2 の担当**であり本目録の母集団外である
+      (同じ事実を 2 か所で検査しない)。v1 の資産
+      (`ScenarioWritePathInventoryTest` / `VideoManualService` / `TakeUploadService`) は変えない
+    - **保証しないものの正本は検査の docblock** であり、本書と `docs/architecture.md` には
+      写さない (2 か所に書くと必ず食い違う)。運用の説明は
+      `docs/architecture.md` §NULL が初期状態を表す列の分類
diff --git a/devnotes/20260817-1309-todo-t212-initial-state-insert-v2/red-first.md b/devnotes/20260817-1309-todo-t212-initial-state-insert-v2/red-first.md
new file mode 100644
index 0000000..7269ba6
--- /dev/null
+++ b/devnotes/20260817-1309-todo-t212-initial-state-insert-v2/red-first.md
@@ -0,0 +1,140 @@
+# 赤の実測 (テストファースト)
+
+実装順は詳細設計の「テストファースト計画」どおり。台帳 (`NullableStateColumnRegistry::entries()`) を
+**空**にしたまま検査だけを置き、`composer test -- tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php`
+を走らせて赤を実測した (worktree: `.claude/worktrees/tasks/T212`、branch `todo/T212`)。
+
+## 赤 1-a: 走査した具象モデルの一覧 (NI-3)
+
+`NULL_INITIAL_STATE_MODEL_CLASSES` を空のまま実行したときの実測。**42 件**が出た
+(`app/Models` 配下の PHP は 43 ファイルで、うち 1 つは trait = `Models/Concerns/AppliesCriticalActionContextToAudit`)。
+
+```
+tests: 1, passed: 0, failed: 1, assertions: 5
+NI-3: 走査した具象 Eloquent モデルの一覧が変わりました。
+  App\Models\AdminUser / AnalysisJob / ApiKey / Billing\BillingCheckoutSession /
+  Billing\BillingNotification / Billing\OrganizationQuota / Billing\Plan / Billing\PlanPrice /
+  Billing\StripeWebhookEvent / Billing\Subscription / Billing\TicketAutoRecharge /
+  Billing\TicketAutoRechargeAttempt / Billing\TicketCheckoutSession / Billing\TicketLedgerEntry /
+  Billing\TicketReservation / Billing\TicketVolumePrice / Category / CustomTeam / Cut /
+  EmailSuppression / IdempotencyKey / Inquiry / Item / LlmCallLog / McpIdempotencyKey /
+  ModelAudit / OauthSession / Organization / OrganizationInvitation / Passkey / Permission /
+  Project / RenderJob / Role / SecurityAuditEvent / SocialAccount / SourceDocument / Take /
+  TakeUploadReservation / Team / User / VideoManual  (計 42)
+```
+
+**この時点で (b) の系統が読めていることが確かめられている** —
+モデルをコンストラクタ経由でインスタンス化しているため `casts()` の畳み込みが効いており、
+`newInstanceWithoutConstructor()` を使っていたら 0 件になっていた経路である。
+
+## 赤 1-b: 母集団の実測 = 未分類 59 件 (NI-1)
+
+モデル一覧を pin してから再実行した。**台帳が空なので母集団の全件が「未分類」として出る**。
+実測は **59 件**で、詳細設計が予測した「(a) 時刻型 50 + (b) 列挙 cast 9 = 59」と一致した。
+
+```
+tests: 15, passed: 10, failed: 5, assertions: 69
+NI-1 (未分類 59 件):
+  analysis_jobs.step
+  api_keys.expires_at
+  api_keys.last_used_at
+  api_keys.revoked_at
+  billing_checkout_sessions.completed_at
+  billing_checkout_sessions.pm_reuse_dispatched_at
+  billing_notifications.failed_at
+  billing_notifications.sent_at
+  cuts.material_type
+  inquiries.closed_at
+  inquiries.source
+  inquiries.terms_accepted_at
+  notifications.read_at
+  oauth_access_tokens.expires_at
+  oauth_auth_codes.expires_at
+  oauth_device_codes.expires_at
+  oauth_device_codes.last_polled_at
+  oauth_device_codes.user_approved_at
+  oauth_refresh_tokens.expires_at
+  oauth_sessions.last_used_at
+  oauth_sessions.revoked_at
+  organization_invitations.accepted_at
+  organization_invitations.revoked_at
+  organizations.deleted_at
+  organizations.free_plan_activated_at
+  organizations.personal_declared_at
+  organizations.signup_tickets_granted_at
+  organizations.stripe_customer_redacted_at
+  organizations.trial_ends_at
+  passkeys.last_used_at
+  plan_prices.active_to
+  plan_prices.synced_at
+  render_jobs.error_code
+  render_jobs.step
+  stripe_webhook_events.processed_at
+  stripe_webhook_events.recovery_reason
+  subscriptions.current_period_end
+  subscriptions.ends_at
+  subscriptions.past_due_since
+  subscriptions.trial_ends_at
+  takes.captured_at
+  takes.downloaded_at
+  ticket_auto_recharge_attempts.resolved_at
+  ticket_auto_recharges.consented_at
+  ticket_auto_recharges.disabled_reason
+  ticket_checkout_sessions.completed_at
+  ticket_ledger_entries.carried_forward_through
+  ticket_ledger_entries.expires_at
+  ticket_ledger_entries.granted_at
+  ticket_ledger_entries.source
+  ticket_reservations.consume_expires_at
+  ticket_reservations.consume_source
+  ticket_volume_prices.active_to
+  ticket_volume_prices.synced_at
+  users.deletion_purge_after
+  users.deletion_requested_at
+  users.email_verified_at
+  users.terms_accepted_at
+  users.two_factor_confirmed_at
+```
+
+**この一覧がそのまま台帳の入力になった** = 母集団が実スキーマ起点であることの証跡である
+(正典 i5。コード側の申告を母集団にしていない)。
+
+同じ実行で以下も赤だった (いずれも pin の初期値が空のため):
+
+- NI-3: モデルから得た表名の一覧 (実測 42 表)
+- NI-4: 台帳の総件数 (実測 0 件 / 期待 59 件)
+- NI-5: 「初期状態の目印」の列一覧
+- NI-7: 母集団から外した作成・更新時刻の列一覧 (モデル由来の実測 77 列)
+
+## 赤 2 / 赤 3 / 赤 4 (負のコントロール) は同じ実行で緑
+
+NC-1..NC-8 は合成入力で判定の純関数を直接叩くため、台帳が空でも動く。上の実行で 10 件が
+passed になっており、その中に次が含まれる。
+
+- **NC-6 (空振り検知)**: 母集団が 0 件になる合成入力で `population` / `temporal` / `enumCast` が
+  すべて空になること = NI-3 の 3 つの非空条件がいずれも満たされないことを確認した
+- **NC-3 (AG-191 の pin の本体)**: 登録済みの列に DB 既定値が付いた合成スキーマで、その列が
+  母集団から抜け「実在しない登録」が点灯することを、既定値の表現ゆれ 6 種
+  (`now()` / `CURRENT_TIMESTAMP` / `'pending'` / `'pending'::character varying` / `0` / 空文字)
+  すべてで確認した
+- **NC-4 (除外の限定)**: `usesTimestamps()` が false のモデル / 作成時刻の列名を差し替えたモデルで
+  `created_at` という名の列が母集団に**残る**ことを確認した
+
+## 緑化のときに初期案から動かした区分 (実読による確定)
+
+詳細設計の区分表は「初期案」であり、実装では生成点を実読して確定する決まりだった。
+実読の結果、次の 5 列が初期案から移った (いずれも「行が生まれた時点で必ず NULL か」の 1 問に
+実装が答えた結果である)。
+
+| 列 | 初期案 | 確定 | 実読した根拠 |
+|---|---|---|---|
+| `plan_prices.active_to` | 生成時に決まりうる値 | **初期状態の目印** | `Services/Billing/PlanPriceService::replaceCurrent()` は新しい価格行を `'active_to' => null` で作り、旧行にだけ終了時刻を打つ |
+| `plan_prices.synced_at` | 生成時に決まりうる値 | **初期状態の目印** | 作成経路 (`replaceCurrent` / `PlanSeeder`) は同期時刻を書かず、`billing:sync-stripe-prices` が既存行へ打つ |
+| `ticket_volume_prices.active_to` | 生成時に決まりうる値 | **初期状態の目印** | `TicketVolumePriceSeeder` が `'active_to' => null` で作る (現行世代の目印) |
+| `ticket_volume_prices.synced_at` | 生成時に決まりうる値 | **初期状態の目印** | 同上 (`'synced_at' => null` で作る) |
+| `users.email_verified_at` | 初期状態の目印 | **生成時に決まりうる値** | `Services/Auth/SocialAccountService` は身元提供者が検証した連絡先のとき、利用者の行を作りながら `'email_verified_at' => now()` を書く = 生成時に必ず NULL ではない |
+| `ticket_ledger_entries.carried_forward_through` | 初期状態の目印 | **生成時に決まりうる値** | `Services/Billing/TicketLedgerCarryForwardService` は繰越行の INSERT で集約の終端を書き込む |
+
+`users.deletion_requested_at` / `users.deletion_purge_after` は詳細設計が「もっとも移る可能性が高い」と
+書いていた 2 列だが、実読 (`Services/Organization/OrganizationMembershipService` の退会予約経路) の結果、
+**どちらも既存行への打刻**であり初期案どおり「初期状態の目印」で確定した。
diff --git a/docs/architecture.md b/docs/architecture.md
index 03aa6ed..645aa11 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2273,6 +2273,62 @@ ## 表ごとの保持期限の分類 (T175)
 置かれ、それぞれに唯一の解決点クラスと直読禁止の検査が付いているため、5 つ目の置き場を
 作ると値が二重管理になる。**本層が持つのは「分類」であって「値」ではない**。
 
+## NULL が初期状態を表す列の分類 (aicue:T212 / 家系の正典 v2・裁定 AG-191)
+
+**実スキーマの「nullable かつ DB 既定値を持たない」列**のうち、時刻型の列と BackedEnum へ
+cast された列を全数分類し、**後から DB 既定値を足すとテストが赤くなる**ようにする層である。
+台帳は `tests/Support/InitialState/NullableStateColumnRegistry.php`、検査は
+`tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php` (Feature lane =
+実スキーマを引くため DB が要る)。
+
+**なぜこの形なのか (pin が母集団の定義そのものから出る)**: 母集団の条件を
+「nullable **かつ DB 既定値を持たない**」に置くと、登録済みの列に既定値を足した瞬間に
+その列は母集団から抜ける。台帳側には登録が残るので、両方向の集合一致が破れて赤くなる。
+**除外規則も CHECK 制約も足さずに**「既定値の後付けを赤にする」が成立する
+(裁定 AG-191 は CHECK 制約の使用を明示的に排除している = データ層へ求めるのは
+「既定値を置かない」ことだけという正典 i7 と衝突させない)。逆向きも同時に閉じており、
+新しく nullable かつ既定値なしの列を足すと未分類として赤くなる (deny-by-default)。
+
+**守りたい事故**: 撮影 PWA とレンダ基盤の進行状態は「受諾したか」「処理したか」「採用したか」の
+NULL / 非 NULL 判定で動いている。`organization_invitations.accepted_at` に既定値を足せば
+これから作られる招待はすべて受諾済みになり、`stripe_webhook_events.processed_at` なら
+処理済みになる。**既存行は変わらないのでテストも画面も静かなまま、新しい行だけが壊れる**。
+
+**区分は 3 種**で、判定は**1 つの問い** —
+「その行が生まれた時点で、この列は必ず NULL か」— で決まる
+(`Tests\Support\InitialState\NullInitialStateClass`):
+
+| 区分 | 意味 | 既定値の後付け |
+|---|---|---|
+| 初期状態の目印 | 行が生まれた時点で**必ず NULL** で、NULL 自体が「まだその段階に達していない」を意味する | **置いてはならない**。置くと新しい行が生まれた瞬間に「済んだ」ことになる |
+| 生成時に決まりうる値 | 行を作る時点で値が入りうる。NULL は該当なし / 無期限 / 未指定であって進行段階ではない | 置く理由が無い (アプリが決めている)。置けば母集団から抜けて検査が赤くなる |
+| 未確定 | どちらとも決められていない列。**隠さずここへ載せる** | 同上 |
+
+**列を足したときの手順**: migration で nullable かつ既定値なしの時刻型の列、または
+BackedEnum へ cast する列を足したら、台帳へ**区分と 30 文字以上の根拠を 1 行**足す。
+根拠には「初期状態の目印」なら *NULL が何を意味し値が入ると何が変わるか* を、
+「生成時に決まりうる値」なら *なぜ生成時に値が決まってよいのか* を、
+「未確定」なら *何が決まっていないか* を書く。件数と「初期状態の目印」「未確定」の列一覧は
+検査が**現在値ちょうど**で pin しているので、増減はどちらもテストの差分として
+レビューに現れる。母集団から外している作成・更新時刻の列も 3 つの一覧
+(モデルの宣言由来 / モデルを持たない表由来 / 統合) で同様に pin してある。
+
+**母集団の作り方**: 表と列は Schema API から取り、(a) 時刻型 (`timestamp` / `timestamptz` /
+`date`) と (b) `app/Models` の具象モデルが BackedEnum へ cast すると宣言している列の和集合を
+母集団にする。**作成・更新時刻の除外は列名の一律一致では行わない** — モデルがある表では
+そのモデルが `usesTimestamps()` を満たし列名が `getCreatedAtColumn()` /
+`getUpdatedAtColumn()` と一致するときだけ外し、モデルを持たない表 (枠組み・外部パッケージ・
+中間表) だけ枠組みの既定名で外す。`deleted_at` は**外さない** (論理削除は初期状態の目印である)。
+
+**`ScenarioWritePathInventoryTest` (v1) との責務境界**: あちらが守るのは
+「**DB 既定値を持つ状態列**を、生成点が INSERT 時に明示代入しているか」であり
+(ドメイン規約 1 (ii) / 規約 2)、母集団は既定値を**持つ**列である。本層の母集団は既定値を
+**持たない**列であり、**2 つの母集団は交わらない**。同じ事実を 2 か所で検査しない。
+
+**保証しないものは本書に写さない**。正本は検査の docblock である
+(母集団に入らない型 / cast の形 / モデルを持たない表 / 最初から既定値を持つ列 /
+Factory・Seeder が走査域外であること など)。2 か所に書くと必ず食い違う。
+
 ## パイプライン通し確認 (pipeline smoke) と LLM コストレポート (T147)
 
 `dev:pipeline-smoke` は **SOP 投入 → AI 解析 → 撮影テイク → ffmpeg 合成 → mp4** の全段が
diff --git a/tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php b/tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php
new file mode 100644
index 0000000..e33b410
--- /dev/null
+++ b/tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php
@@ -0,0 +1,1250 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Idempotency\IdempotencyClaimStatus;
+use App\Enums\Manual\AnalysisStep;
+use App\Enums\Manual\RenderStep;
+use App\Models\AnalysisJob;
+use App\Models\RenderJob;
+use Illuminate\Database\Eloquent\Casts\AsArrayObject;
+use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Database\Schema\Builder;
+use Illuminate\Support\Facades\DB;
+use Tests\Support\InitialState\NullableStateColumnEntry;
+use Tests\Support\InitialState\NullableStateColumnRegistry;
+use Tests\Support\InitialState\NullInitialStateClass;
+
+/*
+ * Feature invariant: **NULL 自体が初期状態を表しうる列が全数分類されている** (deny-by-default)。
+ *
+ * SoT = devnotes/20260817-1309-todo-t212-initial-state-insert-v2/detailed-design.md
+ * (lctl 台帳 feature `explicit-initial-state-on-insert` 正典 v2 / 裁定 AG-191 の不変条件 i10)。
+ *
+ * ★この gate が保証するもの:
+ *   - NI-1: 実スキーマ由来の母集団と台帳が**両方向で集合一致**する。
+ *     **登録済みの列に migration で DB 既定値を後付けすると、その列は母集団の条件
+ *     (`default === null`) から外れて母集団から抜け、台帳側だけに残って赤くなる** —
+ *     これが AG-191 が求めた「既定値の後付けを赤にするスキーマ pin」の本体であり、
+ *     除外規則も CHECK 制約も足さずに母集団の定義そのものから出ている
+ *   - NI-2: 同じ列の二重宣言が無く、根拠が 30 文字以上ある
+ *   - NI-3: 空振り検知。母集団が 0 件でなく、(a) 時刻型・(b) 列挙 cast の各系統が
+ *     それぞれ 1 件以上寄与し、走査した具象モデルの FQCN 一覧と表名の一覧、
+ *     代表の cast 2 本が**現在値ちょうど**である
+ *   - NI-4 / NI-5 / NI-6: 台帳の総件数・「初期状態の目印」の列一覧・「未確定」の列一覧を
+ *     **現在値ちょうど**で pin する (無音で増減しない)
+ *   - NI-7: 母集団から外した作成・更新時刻の列を 3 つの一覧 (モデル由来 / モデルを持たない表由来 /
+ *     統合) すべてで完全一致 pin する (除外が無音で広がらない・件数据え置きの入れ替わりも通さない)
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - **列の意味が区分どおりかは見ない**。機械が見るのは集合の一致と根拠の長さだけであり、
+ *     区分が正しいかは人間のレビュー対象である
+ *   - **母集団は時刻型と BackedEnum へ cast された列に限る**。nullable な文字列・数値・json・
+ *     外部キーで「NULL = まだ」を表す列 (実例: billing_checkout_sessions.funding_choice /
+ *     render_jobs.output_path / cuts.adopted_take_id) は母集団外であり、
+ *     そこへ既定値を足しても**沈黙する**
+ *   - **(b) はモデルの宣言に依存する**。app/Models にモデルを持たない表 (枠組み・外部パッケージ・
+ *     中間表) の状態語彙の列は見えない。ただし cast を外す変更は台帳側が
+ *     「実在しない登録」になって赤くなるので、**片方向は閉じている**
+ *   - **(b) が拾う cast の形は 1 つだけ**である (文字列の cast で `enum_exists()` かつ
+ *     `BackedEnum` 実装)。列挙の集まり (AsEnumCollection / AsEnumArrayObject)・
+ *     引数付きの cast 文字列 (decimal:2)・Castable を実装する自前の cast・
+ *     裏付けの値を持たない列挙 (BackedEnum でない enum) は**見ない**。
+ *     これらの形で状態語彙を持つ列が現れたら、母集団の設計から見直すこと
+ *   - **モデルから cast を読めるのは Laravel がコンストラクタで `casts()` を畳み込むから**である
+ *     (`HasAttributes::initializeHasAttributes()` が `array_merge($this->casts, $this->casts())` を行い、
+ *     `getCasts()` は `$this->casts` を返すだけ)。この畳み込みの実装が変われば (b) は静かに
+ *     縮みうるため、**Laravel を更新したら本検査の前提を人手で再確認する**
+ *     (ClaudeHooksWiringTest と同じ作法)。NI-3 のモデル FQCN 一覧・表名一覧の完全一致 pin で
+ *     入れ替わりは赤くなるが、「全モデルの cast が一斉に空になる」形は一覧では捕まらない
+ *     (系統別 1 件以上と代表の cast 2 本の pin が拾う)
+ *   - **最初から既定値を持って生まれた列は母集団外**である (AGENTS.md ドメイン固有規約 1 (ii) /
+ *     規約 2 と ScenarioWritePathInventoryTest の担当。同じ事実を 2 か所で検査しない)。
+ *     新しい列を最初から既定値付きで足す変更には沈黙する
+ *   - **既定値の中身は見ない**。`default === null` かどうかだけを見る
+ *   - **CHECK 制約・部分一意索引・排他制約は使わない** (正典 i7 / AG-191)。
+ *     列の組の整合や値域は保証しない
+ *   - **Factory / Seeder は走査域外**である (家系の未決論点 q3。本裁定の範囲外)
+ *   - 見るのは**現在のスキーマ**であり、`search_path` の健全性は前提であって保証ではない。
+ *     S3 上の実体・ビュー・他スキーマの表は対象外である
+ *   - **アプリが実際にその列の NULL を読んで分岐していることは確かめない**。
+ *     区分 InitialStateMarker は人の宣言である
+ */
+
+/** 母集団に入れる時刻型 (pgsql の type_name)。 */
+const NULL_INITIAL_STATE_TEMPORAL_TYPES = ['timestamp', 'timestamptz', 'date'];
+
+/** 台帳の総件数 (cap ではなく exact-fit。増減したら必ずこの数字を書き換える)。 */
+const NULL_INITIAL_STATE_COLUMN_COUNT = 59;
+
+/**
+ * 「初期状態の目印」区分の列 (現在値ちょうど。増えるときも減るときもここを書き換える)。
+ */
+const NULL_INITIAL_STATE_MARKER_COLUMNS = [
+    'analysis_jobs.step',
+    'api_keys.last_used_at',
+    'api_keys.revoked_at',
+    'billing_checkout_sessions.completed_at',
+    'billing_checkout_sessions.pm_reuse_dispatched_at',
+    'billing_notifications.failed_at',
+    'billing_notifications.sent_at',
+    'inquiries.closed_at',
+    'notifications.read_at',
+    'oauth_device_codes.last_polled_at',
+    'oauth_device_codes.user_approved_at',
+    'oauth_sessions.last_used_at',
+    'oauth_sessions.revoked_at',
+    'organization_invitations.accepted_at',
+    'organization_invitations.revoked_at',
+    'organizations.deleted_at',
+    'organizations.free_plan_activated_at',
+    'organizations.personal_declared_at',
+    'organizations.signup_tickets_granted_at',
+    'organizations.stripe_customer_redacted_at',
+    'passkeys.last_used_at',
+    'plan_prices.active_to',
+    'plan_prices.synced_at',
+    'render_jobs.error_code',
+    'render_jobs.step',
+    'stripe_webhook_events.processed_at',
+    'stripe_webhook_events.recovery_reason',
+    'subscriptions.past_due_since',
+    'takes.downloaded_at',
+    'ticket_auto_recharge_attempts.resolved_at',
+    'ticket_auto_recharges.disabled_reason',
+    'ticket_checkout_sessions.completed_at',
+    'ticket_volume_prices.active_to',
+    'ticket_volume_prices.synced_at',
+    'users.deletion_purge_after',
+    'users.deletion_requested_at',
+    'users.two_factor_confirmed_at',
+];
+
+/**
+ * 「未確定」区分の列 (現在値ちょうど。未確定を無音で増やさないための pin)。
+ */
+const NULL_INITIAL_STATE_UNDECIDED_COLUMNS = [];
+
+/**
+ * 母集団から外した作成・更新時刻の列 (**モデルの宣言由来**。現在値ちょうど)。
+ */
+const NULL_INITIAL_STATE_EXCLUDED_BY_MODEL = [
+    'admin_users.created_at',
+    'admin_users.updated_at',
+    'analysis_jobs.created_at',
+    'analysis_jobs.updated_at',
+    'api_keys.created_at',
+    'api_keys.updated_at',
+    'billing_checkout_sessions.created_at',
+    'billing_checkout_sessions.updated_at',
+    'billing_notifications.created_at',
+    'billing_notifications.updated_at',
+    'categories.created_at',
+    'categories.updated_at',
+    'custom_teams.created_at',
+    'custom_teams.updated_at',
+    'cuts.created_at',
+    'cuts.updated_at',
+    'email_suppressions.created_at',
+    'email_suppressions.updated_at',
+    'idempotency_keys.created_at',
+    'inquiries.created_at',
+    'inquiries.updated_at',
+    'items.created_at',
+    'items.updated_at',
+    'model_audits.created_at',
+    'model_audits.updated_at',
+    'oauth_sessions.created_at',
+    'oauth_sessions.updated_at',
+    'organization_invitations.created_at',
+    'organization_invitations.updated_at',
+    'organization_quotas.created_at',
+    'organization_quotas.updated_at',
+    'organizations.created_at',
+    'organizations.updated_at',
+    'passkeys.created_at',
+    'passkeys.updated_at',
+    'permissions.created_at',
+    'permissions.updated_at',
+    'plan_prices.created_at',
+    'plan_prices.updated_at',
+    'plans.created_at',
+    'plans.updated_at',
+    'projects.created_at',
+    'projects.updated_at',
+    'render_jobs.created_at',
+    'render_jobs.updated_at',
+    'roles.created_at',
+    'roles.updated_at',
+    'security_audit_events.created_at',
+    'security_audit_events.updated_at',
+    'social_accounts.created_at',
+    'social_accounts.updated_at',
+    'source_documents.created_at',
+    'source_documents.updated_at',
+    'stripe_webhook_events.created_at',
+    'stripe_webhook_events.updated_at',
+    'subscriptions.created_at',
+    'subscriptions.updated_at',
+    'take_upload_reservations.created_at',
+    'take_upload_reservations.updated_at',
+    'takes.created_at',
+    'takes.updated_at',
+    'teams.created_at',
+    'teams.updated_at',
+    'ticket_auto_recharge_attempts.created_at',
+    'ticket_auto_recharge_attempts.updated_at',
+    'ticket_auto_recharges.created_at',
+    'ticket_auto_recharges.updated_at',
+    'ticket_checkout_sessions.created_at',
+    'ticket_checkout_sessions.updated_at',
+    'ticket_reservations.created_at',
+    'ticket_reservations.updated_at',
+    'ticket_volume_prices.created_at',
+    'ticket_volume_prices.updated_at',
+    'users.created_at',
+    'users.updated_at',
+    'video_manuals.created_at',
+    'video_manuals.updated_at',
+];
+
+/**
+ * 母集団から外した作成・更新時刻の列 (**モデルを持たない表由来**。現在値ちょうど)。
+ */
+const NULL_INITIAL_STATE_EXCLUDED_BY_TABLELESS = [
+    'notifications.created_at',
+    'notifications.updated_at',
+    'oauth_access_tokens.created_at',
+    'oauth_access_tokens.updated_at',
+    'oauth_clients.created_at',
+    'oauth_clients.updated_at',
+    'organization_user.created_at',
+    'organization_user.updated_at',
+    'password_reset_tokens.created_at',
+    'project_members.created_at',
+    'project_members.updated_at',
+    'subscription_items.created_at',
+    'subscription_items.updated_at',
+];
+
+/**
+ * 走査対象の具象 Eloquent モデル (FQCN。現在値ちょうど)。
+ */
+const NULL_INITIAL_STATE_MODEL_CLASSES = [
+    'App\Models\AdminUser',
+    'App\Models\AnalysisJob',
+    'App\Models\ApiKey',
+    'App\Models\Billing\BillingCheckoutSession',
+    'App\Models\Billing\BillingNotification',
+    'App\Models\Billing\OrganizationQuota',
+    'App\Models\Billing\Plan',
+    'App\Models\Billing\PlanPrice',
+    'App\Models\Billing\StripeWebhookEvent',
+    'App\Models\Billing\Subscription',
+    'App\Models\Billing\TicketAutoRecharge',
+    'App\Models\Billing\TicketAutoRechargeAttempt',
+    'App\Models\Billing\TicketCheckoutSession',
+    'App\Models\Billing\TicketLedgerEntry',
+    'App\Models\Billing\TicketReservation',
+    'App\Models\Billing\TicketVolumePrice',
+    'App\Models\Category',
+    'App\Models\CustomTeam',
+    'App\Models\Cut',
+    'App\Models\EmailSuppression',
+    'App\Models\IdempotencyKey',
+    'App\Models\Inquiry',
+    'App\Models\Item',
+    'App\Models\LlmCallLog',
+    'App\Models\McpIdempotencyKey',
+    'App\Models\ModelAudit',
+    'App\Models\OauthSession',
+    'App\Models\Organization',
+    'App\Models\OrganizationInvitation',
+    'App\Models\Passkey',
+    'App\Models\Permission',
+    'App\Models\Project',
+    'App\Models\RenderJob',
+    'App\Models\Role',
+    'App\Models\SecurityAuditEvent',
+    'App\Models\SocialAccount',
+    'App\Models\SourceDocument',
+    'App\Models\Take',
+    'App\Models\TakeUploadReservation',
+    'App\Models\Team',
+    'App\Models\User',
+    'App\Models\VideoManual',
+];
+
+/**
+ * 上記モデルから得た表名 (現在値ちょうど)。
+ */
+const NULL_INITIAL_STATE_MODEL_TABLES = [
+    'admin_users',
+    'analysis_jobs',
+    'api_keys',
+    'billing_checkout_sessions',
+    'billing_notifications',
+    'categories',
+    'custom_teams',
+    'cuts',
+    'email_suppressions',
+    'idempotency_keys',
+    'inquiries',
+    'items',
+    'llm_call_logs',
+    'mcp_idempotency_keys',
+    'model_audits',
+    'oauth_sessions',
+    'organization_invitations',
+    'organization_quotas',
+    'organizations',
+    'passkeys',
+    'permissions',
+    'plan_prices',
+    'plans',
+    'projects',
+    'render_jobs',
+    'roles',
+    'security_audit_events',
+    'social_accounts',
+    'source_documents',
+    'stripe_webhook_events',
+    'subscriptions',
+    'take_upload_reservations',
+    'takes',
+    'teams',
+    'ticket_auto_recharge_attempts',
+    'ticket_auto_recharges',
+    'ticket_checkout_sessions',
+    'ticket_ledger_entries',
+    'ticket_reservations',
+    'ticket_volume_prices',
+    'users',
+    'video_manuals',
+];
+
+/**
+ * スキーマ照会の入口。
+ *
+ * **ファサードではなく具体の Builder を取る** — `Schema::` の docblock は
+ * `array getTables(...)` としか書いておらず要素が mixed になる。
+ * `Connection::getSchemaBuilder()` は `Illuminate\Database\Schema\Builder` を返し、
+ * 実体側の shape 宣言がそのまま効く (**型を緩めて黙らせない**)。
+ */
+function nullInitialStateSchemaBuilder(): Builder
+{
+    return DB::connection()->getSchemaBuilder();
+}
+
+/**
+ * 現在のスキーマの base table 名 (非修飾・sort 済み)。
+ *
+ * pgsql は引数なしだと全スキーマを返すため必ず現在のスキーマへ絞る。
+ *
+ * @return list<string>
+ */
+function nullInitialStateSchemaTableNames(): array
+{
+    $builder = nullInitialStateSchemaBuilder();
+    $names = array_map(
+        static fn (array $table): string => $table['name'],
+        $builder->getTables($builder->getCurrentSchemaName()),
+    );
+    sort($names);
+
+    return array_values($names);
+}
+
+/**
+ * 表ごとの列定義 (Schema API の**生の戻り**。正規化は別の純関数が行う)。
+ *
+ * @return array<string, list<array<string, mixed>>>
+ */
+function nullInitialStateRawColumns(): array
+{
+    /** @var array<string, list<array<string, mixed>>>|null $cache */
+    static $cache = null;
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $builder = nullInitialStateSchemaBuilder();
+    $schema = $builder->getCurrentSchemaName();
+
+    $map = [];
+    foreach (nullInitialStateSchemaTableNames() as $table) {
+        $map[$table] = array_values($builder->getColumns($schema.'.'.$table));
+    }
+    $cache = $map;
+
+    return $map;
+}
+
+/**
+ * Schema API の戻りの正規化 (**純関数**。キーの存在を仮定せず fail-closed で受ける)。
+ *
+ * 必要なキーが欠けている / 型が想定外の要素は**適合と判定せず** problems へ積む
+ * (正典 i6 の「走査で証明できない受け手は適合と判定せず未解決として扱う」)。
+ * 失敗メッセージは **`name` 自体が欠けていても成立する形**にしてある
+ * (表名 / 要素の位置 / 欠けているキー / 実際にあるキーの 4 つを出す)。
+ *
+ * @param  array<string, list<array<string, mixed>>>  $rawByTable
+ * @return array{columns: array<string, list<array{name: string, type_name: string, nullable: bool,
+ *          default: string|null, auto_increment: bool, generation: array<string, mixed>|null}>>,
+ *          problems: list<string>}
+ */
+function nullInitialStateNormalizeColumns(array $rawByTable): array
+{
+    $required = ['name', 'type_name', 'nullable', 'default', 'auto_increment', 'generation'];
+
+    $columns = [];
+    $problems = [];
+
+    foreach ($rawByTable as $table => $rawColumns) {
+        $normalized = [];
+        foreach ($rawColumns as $index => $raw) {
+            $missing = array_values(array_filter(
+                $required,
+                static fn (string $key): bool => ! array_key_exists($key, $raw),
+            ));
+            $actual = array_keys($raw);
+            $shown = isset($raw['name']) && is_string($raw['name']) ? $raw['name'] : '取得できず';
+
+            if ($missing !== []) {
+                $problems[] = sprintf(
+                    '%s columns[%d] (列名: %s): 欠けているキー = %s / 実際のキー = %s',
+                    $table,
+                    $index,
+                    $shown,
+                    implode(', ', $missing),
+                    $actual === [] ? '(無し)' : implode(', ', $actual),
+                );
+
+                continue;
+            }
+
+            $badTypes = [];
+            if (! is_string($raw['name'])) {
+                $badTypes[] = 'name';
+            }
+            if (! is_string($raw['type_name'])) {
+                $badTypes[] = 'type_name';
+            }
+            if (! is_bool($raw['nullable'])) {
+                $badTypes[] = 'nullable';
+            }
+            if ($raw['default'] !== null && ! is_scalar($raw['default'])) {
+                $badTypes[] = 'default';
+            }
+            if (! is_bool($raw['auto_increment'])) {
+                $badTypes[] = 'auto_increment';
+            }
+            if ($raw['generation'] !== null && ! is_array($raw['generation'])) {
+                $badTypes[] = 'generation';
+            }
+
+            if ($badTypes !== []) {
+                $problems[] = sprintf(
+                    '%s columns[%d] (列名: %s): 型が想定外のキー = %s / 実際のキー = %s',
+                    $table,
+                    $index,
+                    $shown,
+                    implode(', ', $badTypes),
+                    $actual === [] ? '(無し)' : implode(', ', $actual),
+                );
+
+                continue;
+            }
+
+            /** @var string $name */
+            $name = $raw['name'];
+            /** @var string $typeName */
+            $typeName = $raw['type_name'];
+            /** @var array<string, mixed>|null $generation */
+            $generation = $raw['generation'];
+
+            $normalized[] = [
+                'name' => $name,
+                'type_name' => $typeName,
+                'nullable' => (bool) $raw['nullable'],
+                'default' => $raw['default'] === null ? null : (string) $raw['default'],
+                'auto_increment' => (bool) $raw['auto_increment'],
+                'generation' => $generation,
+            ];
+        }
+        $columns[$table] = $normalized;
+    }
+
+    sort($problems);
+
+    return ['columns' => $columns, 'problems' => array_values($problems)];
+}
+
+/**
+ * その cast 宣言が「裏付けの値を持つ列挙への cast」か (**純関数**)。
+ *
+ * 拾うのは **1 つの形だけ**である — 文字列で、`:` を含まず、`enum_exists()` が真で、
+ * `BackedEnum` を実装するもの。引数付き cast (`decimal:2`) / 列挙の集まり
+ * (`AsEnumCollection:...`) / `Castable` 実装クラス / 裏付けの値を持たない列挙は**入らない**。
+ */
+function nullInitialStateIsBackedEnumCast(mixed $cast): bool
+{
+    if (! is_string($cast) || $cast === '') {
+        return false;
+    }
+    if (str_contains($cast, ':')) {
+        return false;
+    }
+    if (! enum_exists($cast)) {
+        return false;
+    }
+
+    return is_subclass_of($cast, BackedEnum::class, true);
+}
+
+/**
+ * app/Models 配下の具象 Eloquent モデルから読み取る事実。
+ *
+ * **クラス名から表名を推測しない** (各インスタンスの `getTable()` を引く)。
+ * **`newInstanceWithoutConstructor()` は使えない** — Laravel は `casts()` の戻り値を
+ * `HasAttributes::initializeHasAttributes()` でコンストラクタからのみ畳み込むため、
+ * コンストラクタを飛ばすと本リポジトリの全モデルの cast が空になり (b) の系統が
+ * 静かに 0 件へ縮む。インスタンス化の失敗は**握り潰さずその場で fail** させる。
+ *
+ * @return array{models: list<string>, tables: list<string>, enumCasts: array<string, list<string>>,
+ *          enumCastsByModel: array<string, array<string, string>>,
+ *          lifecycle: array<string, list<string>>}
+ */
+function nullInitialStateModelFacts(): array
+{
+    /** @var array{models: list<string>, tables: list<string>, enumCasts: array<string, list<string>>, enumCastsByModel: array<string, array<string, string>>, lifecycle: array<string, list<string>>}|null $cache */
+    static $cache = null;
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $root = app_path('Models');
+    $files = [];
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
+    );
+    foreach ($iterator as $file) {
+        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
+            continue;
+        }
+        $files[] = $file->getPathname();
+    }
+    sort($files);
+
+    $models = [];
+    $tables = [];
+    $enumCasts = [];
+    $enumCastsByModel = [];
+    $lifecycle = [];
+
+    foreach ($files as $path) {
+        $relative = substr($path, strlen($root) + 1);
+        $fqcn = 'App\\Models\\'.str_replace('/', '\\', substr($relative, 0, -4));
+
+        if (! class_exists($fqcn)) {
+            // trait / interface / 名前が対応しないファイルは母集団に入れない
+            continue;
+        }
+        $reflection = new ReflectionClass($fqcn);
+        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
+            continue;
+        }
+
+        try {
+            $instance = new $fqcn;
+        } catch (Throwable $e) {
+            throw new RuntimeException(sprintf(
+                '%s をインスタンス化できませんでした (例外の型: %s)。'
+                .'モデルの cast を読めないと (b) の系統が静かに 0 件へ縮むため、握り潰さず落としています。',
+                $fqcn,
+                $e::class,
+            ), 0, $e);
+        }
+
+        if (! $instance instanceof Model) {
+            continue;
+        }
+
+        $models[] = $fqcn;
+        $table = $instance->getTable();
+        $tables[] = $table;
+
+        foreach ($instance->getCasts() as $column => $cast) {
+            if (! nullInitialStateIsBackedEnumCast($cast)) {
+                continue;
+            }
+            /** @var string $cast */
+            // 同じ表を指すモデルが複数あるときは**和集合**を取る (見落としの出ない側へ倒す)
+            $enumCasts[$table][] = (string) $column;
+            $enumCastsByModel[$fqcn][(string) $column] = $cast;
+        }
+
+        // 作成・更新時刻の除外は**そのモデルが宣言している列名にだけ**効かせる
+        if ($instance->usesTimestamps()) {
+            foreach ([$instance->getCreatedAtColumn(), $instance->getUpdatedAtColumn()] as $column) {
+                if (is_string($column) && $column !== '') {
+                    $lifecycle[$table][] = $column;
+                }
+            }
+        }
+    }
+
+    sort($models);
+    $tables = array_values(array_unique($tables));
+    sort($tables);
+    foreach ($enumCasts as $table => $columns) {
+        $columns = array_values(array_unique($columns));
+        sort($columns);
+        $enumCasts[$table] = $columns;
+    }
+    foreach ($lifecycle as $table => $columns) {
+        $columns = array_values(array_unique($columns));
+        sort($columns);
+        $lifecycle[$table] = $columns;
+    }
+    ksort($enumCasts);
+    ksort($enumCastsByModel);
+    ksort($lifecycle);
+
+    $cache = [
+        'models' => array_values($models),
+        'tables' => $tables,
+        'enumCasts' => $enumCasts,
+        'enumCastsByModel' => $enumCastsByModel,
+        'lifecycle' => $lifecycle,
+    ];
+
+    return $cache;
+}
+
+/**
+ * モデルを持たない表の作成・更新時刻の列名 (**純関数**)。
+ *
+ * 枠組み・外部パッケージ・中間表には `getCreatedAtColumn()` を尋ねる相手がいないため、
+ * ここだけは枠組みの既定名との一致で外す。**外れた列一覧は NI-7 が完全一致で pin する**。
+ *
+ * @param  list<string>  $allTables
+ * @param  list<string>  $tablesWithModel
+ * @return array<string, list<string>>
+ */
+function nullInitialStateTablelessLifecycleColumns(array $allTables, array $tablesWithModel): array
+{
+    $map = [];
+    foreach ($allTables as $table) {
+        if (in_array($table, $tablesWithModel, true)) {
+            continue;
+        }
+        $map[$table] = [Model::CREATED_AT, Model::UPDATED_AT];
+    }
+
+    return $map;
+}
+
+/**
+ * 母集団の算出 (**純関数** = 負のコントロールから合成入力で直接呼べる)。
+ *
+ * 残す列の条件は **nullable かつ DB 既定値なし、生成列でない、identity でない**。
+ * ここから (a) 時刻型 と (b) BackedEnum へ cast された列 の和集合を母集団にする。
+ * **既定値を足した列はこの条件から外れて母集団から抜ける** = NI-1 が赤くなる (AG-191 の pin)。
+ *
+ * @param  array<string, list<array{name: string, type_name: string, nullable: bool,
+ *          default: string|null, auto_increment: bool, generation: array<string, mixed>|null}>>  $columnsByTable
+ * @param  array<string, list<string>>  $enumCastColumns  表名 => BackedEnum へ cast された列名
+ * @param  array<string, list<string>>  $modelLifecycleColumns  表名 => モデルが宣言した作成 / 更新時刻の列名
+ * @param  array<string, list<string>>  $tablelessLifecycleColumns  表名 => モデルを持たない表の既定名
+ * @return array{population: list<string>, temporal: list<string>, enumCast: list<string>,
+ *          excludedByModel: list<string>, excludedByTableless: list<string>,
+ *          excludedLifecycle: list<string>}
+ */
+function nullInitialStatePopulation(
+    array $columnsByTable,
+    array $enumCastColumns,
+    array $modelLifecycleColumns,
+    array $tablelessLifecycleColumns,
+): array {
+    $population = [];
+    $temporal = [];
+    $enumCast = [];
+    $excludedByModel = [];
+    $excludedByTableless = [];
+
+    foreach ($columnsByTable as $table => $columns) {
+        foreach ($columns as $column) {
+            if ($column['nullable'] !== true) {
+                continue;
+            }
+            if ($column['default'] !== null) {
+                continue;
+            }
+            if ($column['generation'] !== null) {
+                continue;
+            }
+            if ($column['auto_increment'] === true) {
+                continue;
+            }
+
+            $key = $table.'.'.$column['name'];
+            $isTemporal = in_array($column['type_name'], NULL_INITIAL_STATE_TEMPORAL_TYPES, true);
+            $isEnumCast = in_array($column['name'], $enumCastColumns[$table] ?? [], true);
+
+            // 作成・更新時刻の除外は**時刻型の列にだけ**当てる。
+            // 除外の一覧 (NI-7 が pin する) の意味を「(a) に入るはずだったのに外した列」に
+            // 揃えるためで、判定を外側へ出すと時刻型ですらない同名の列まで一覧へ混ざる。
+            // 除外に当たった列は母集団から**丸ごと**外れる ((b) の側にも入らない)。
+            if ($isTemporal) {
+                if (in_array($column['name'], $modelLifecycleColumns[$table] ?? [], true)) {
+                    $excludedByModel[] = $key;
+
+                    continue;
+                }
+                if (in_array($column['name'], $tablelessLifecycleColumns[$table] ?? [], true)) {
+                    $excludedByTableless[] = $key;
+
+                    continue;
+                }
+                $temporal[] = $key;
+            }
+
+            if ($isEnumCast) {
+                $enumCast[] = $key;
+            }
+
+            if ($isTemporal || $isEnumCast) {
+                $population[] = $key;
+            }
+        }
+    }
+
+    $population = array_values(array_unique($population));
+    $excludedLifecycle = array_values(array_unique(array_merge($excludedByModel, $excludedByTableless)));
+
+    sort($population);
+    sort($temporal);
+    sort($enumCast);
+    sort($excludedByModel);
+    sort($excludedByTableless);
+    sort($excludedLifecycle);
+
+    return [
+        'population' => $population,
+        'temporal' => array_values($temporal),
+        'enumCast' => array_values($enumCast),
+        'excludedByModel' => array_values($excludedByModel),
+        'excludedByTableless' => array_values($excludedByTableless),
+        'excludedLifecycle' => $excludedLifecycle,
+    ];
+}
+
+/**
+ * 母集団と台帳の突合 (**純関数**)。
+ *
+ * @param  list<string>  $population  '表名.列名'
+ * @param  list<NullableStateColumnEntry>  $entries
+ * @return array{unclassified: list<string>, phantom: list<string>, duplicated: list<string>}
+ */
+function nullInitialStateClassify(array $population, array $entries): array
+{
+    $declared = [];
+    $duplicated = [];
+    foreach ($entries as $entry) {
+        $key = $entry->key();
+        if (array_key_exists($key, $declared)) {
+            $duplicated[] = $key;
+
+            continue;
+        }
+        $declared[$key] = true;
+    }
+
+    $declaredKeys = array_keys($declared);
+    $unclassified = array_values(array_diff($population, $declaredKeys));
+    $phantom = array_values(array_diff($declaredKeys, $population));
+
+    sort($unclassified);
+    sort($phantom);
+    sort($duplicated);
+
+    return [
+        'unclassified' => array_values($unclassified),
+        'phantom' => array_values($phantom),
+        'duplicated' => array_values(array_unique($duplicated)),
+    ];
+}
+
+/**
+ * 実スキーマ + モデル宣言から母集団を組む (副作用のある入口)。
+ *
+ * @return array{population: list<string>, temporal: list<string>, enumCast: list<string>,
+ *          excludedByModel: list<string>, excludedByTableless: list<string>,
+ *          excludedLifecycle: list<string>, problems: list<string>}
+ */
+function nullInitialStateActualPopulation(): array
+{
+    $normalized = nullInitialStateNormalizeColumns(nullInitialStateRawColumns());
+    $facts = nullInitialStateModelFacts();
+
+    $result = nullInitialStatePopulation(
+        $normalized['columns'],
+        $facts['enumCasts'],
+        $facts['lifecycle'],
+        nullInitialStateTablelessLifecycleColumns(nullInitialStateSchemaTableNames(), $facts['tables']),
+    );
+
+    return [...$result, 'problems' => $normalized['problems']];
+}
+
+/**
+ * 指定区分の列キー (sort 済み)。
+ *
+ * @param  list<NullableStateColumnEntry>  $entries
+ * @return list<string>
+ */
+function nullInitialStateColumnsOfClass(array $entries, NullInitialStateClass $class): array
+{
+    $keys = array_values(array_map(
+        static fn (NullableStateColumnEntry $entry): string => $entry->key(),
+        array_filter($entries, static fn (NullableStateColumnEntry $entry): bool => $entry->class === $class),
+    ));
+    sort($keys);
+
+    return $keys;
+}
+
+test('NI-1: 実スキーマ由来の母集団と台帳が両方向で集合一致する', function (): void {
+    $actual = nullInitialStateActualPopulation();
+    $result = nullInitialStateClassify($actual['population'], NullableStateColumnRegistry::entries());
+
+    expect($result['unclassified'])->toBe([],
+        'NULL が初期状態を表しうる列のうち、区分が宣言されていないものを検出しました。'
+        .'tests/Support/InitialState/NullableStateColumnRegistry.php へ区分と 30 文字以上の根拠付きで '
+        .'1 行足してください (決められないなら undecided で構いません): '
+        .implode(', ', $result['unclassified']));
+
+    expect($result['phantom'])->toBe([],
+        '台帳にある列が実スキーマ由来の母集団に見当たりません。'
+        .'**この列に migration で DB 既定値を足していませんか。足すと新しい行は生まれた瞬間に '
+        .'「済んだ」ことになります** (既定値を持つ列は母集団の条件から外れます)。'
+        .'列を落とした / cast を外した場合は台帳からも消してください: '
+        .implode(', ', $result['phantom']));
+});
+
+test('NI-2: 同じ列の二重宣言が無く、根拠が 30 文字以上ある', function (): void {
+    $entries = NullableStateColumnRegistry::entries();
+    $actual = nullInitialStateActualPopulation();
+
+    $result = nullInitialStateClassify($actual['population'], $entries);
+    expect($result['duplicated'])->toBe([],
+        '同じ列が台帳に 2 回以上宣言されています (後の 1 件で上書きされる形の消失を防ぐため禁止): '
+        .implode(', ', $result['duplicated']));
+
+    $tooShort = [];
+    foreach ($entries as $entry) {
+        if (mb_strlen($entry->rationale) < NullableStateColumnEntry::RATIONALE_MIN_LENGTH) {
+            $tooShort[] = $entry->key();
+        }
+    }
+
+    expect($tooShort)->toBe([],
+        '根拠が短すぎます (30 文字以上。「同上」「N/A」のような形だけの記述を弾くため): '
+        .implode(', ', $tooShort));
+});
+
+test('NI-3: 空振り検知 (母集団・系統・モデル一覧・代表の cast が現在値ちょうど)', function (): void {
+    $actual = nullInitialStateActualPopulation();
+
+    expect($actual['problems'])->toBe([],
+        'Schema API の戻りが想定の形をしていません (適合と判定せず落としています): '
+        .implode(' / ', $actual['problems']));
+
+    expect($actual['population'])->not->toBe([],
+        '母集団が 0 件になりました。抽出が壊れています (0 件を合格にしません = 正典 i5)。');
+    expect($actual['temporal'])->not->toBe([],
+        '(a) 時刻型の系統が 0 件になりました。抽出が静かに縮んでいます。');
+    expect($actual['enumCast'])->not->toBe([],
+        '(b) 列挙 cast の系統が 0 件になりました。モデルからの cast 読み取りが壊れています。');
+
+    $facts = nullInitialStateModelFacts();
+
+    expect($facts['models'])->toBe(NULL_INITIAL_STATE_MODEL_CLASSES,
+        '走査した具象 Eloquent モデルの一覧が変わりました。モデルを足した / 消したなら '
+        .'NULL_INITIAL_STATE_MODEL_CLASSES を書き換えてください (件数だけの pin では入れ替わりが素通りします)。');
+
+    expect($facts['tables'])->toBe(NULL_INITIAL_STATE_MODEL_TABLES,
+        'モデルから得た表名の一覧が変わりました。NULL_INITIAL_STATE_MODEL_TABLES を書き換えてください。');
+
+    // 代表の cast 2 本を**値ごと** pin する。モデル一覧が変わらないまま
+    // casts() の畳み込み機序だけが壊れる形は、一覧でも件数でも捕まらないため。
+    expect($facts['enumCastsByModel'][AnalysisJob::class]['step'] ?? null)->toBe(AnalysisStep::class,
+        'AnalysisJob の step の cast を読めていません。Laravel が casts() をコンストラクタで '
+        .'畳み込む前提が崩れた可能性があります (本検査の docblock を読み直してください)。');
+    expect($facts['enumCastsByModel'][RenderJob::class]['step'] ?? null)->toBe(RenderStep::class,
+        'RenderJob の step の cast を読めていません。Laravel が casts() をコンストラクタで '
+        .'畳み込む前提が崩れた可能性があります (本検査の docblock を読み直してください)。');
+});
+
+test('NI-4: 台帳の総件数が現在値ちょうどである', function (): void {
+    expect(NullableStateColumnRegistry::entries())->toHaveCount(NULL_INITIAL_STATE_COLUMN_COUNT,
+        '台帳の件数が変わりました。列を足した / 消したなら NULL_INITIAL_STATE_COLUMN_COUNT も '
+        .'書き換えてください。');
+});
+
+test('NI-5: 「初期状態の目印」区分の列一覧が現在値ちょうどである', function (): void {
+    $markers = nullInitialStateColumnsOfClass(
+        NullableStateColumnRegistry::entries(),
+        NullInitialStateClass::InitialStateMarker,
+    );
+    $expected = NULL_INITIAL_STATE_MARKER_COLUMNS;
+    sort($expected);
+
+    expect($markers)->toBe($expected,
+        '「初期状態の目印」の列一覧が変わりました。増えるときも減るときも '
+        .'NULL_INITIAL_STATE_MARKER_COLUMNS を書き換えてください '
+        .'(この一族が無音で減るのが一番危ないので pin しています)。');
+});
+
+test('NI-6: 「未確定」区分の列一覧が現在値ちょうどである', function (): void {
+    $undecided = nullInitialStateColumnsOfClass(
+        NullableStateColumnRegistry::entries(),
+        NullInitialStateClass::Undecided,
+    );
+    $expected = NULL_INITIAL_STATE_UNDECIDED_COLUMNS;
+    sort($expected);
+
+    expect($undecided)->toBe($expected,
+        '未確定の列一覧が変わりました。NULL_INITIAL_STATE_UNDECIDED_COLUMNS を書き換えてください '
+        .'(未確定を無音で増やさないための pin です)。');
+});
+
+test('NI-7: 母集団から外した作成・更新時刻の列が 3 つの一覧すべてで現在値ちょうどである', function (): void {
+    $actual = nullInitialStateActualPopulation();
+
+    $byModel = NULL_INITIAL_STATE_EXCLUDED_BY_MODEL;
+    sort($byModel);
+    $byTableless = NULL_INITIAL_STATE_EXCLUDED_BY_TABLELESS;
+    sort($byTableless);
+    $union = array_values(array_unique(array_merge($byModel, $byTableless)));
+    sort($union);
+
+    expect($actual['excludedByModel'])->toBe($byModel,
+        'モデルの宣言 (usesTimestamps + getCreatedAtColumn / getUpdatedAtColumn) 由来の除外一覧が '
+        .'変わりました。NULL_INITIAL_STATE_EXCLUDED_BY_MODEL を書き換えてください。');
+    expect($actual['excludedByTableless'])->toBe($byTableless,
+        'モデルを持たない表の既定名由来の除外一覧が変わりました。'
+        .'NULL_INITIAL_STATE_EXCLUDED_BY_TABLELESS を書き換えてください '
+        .'(この経路だけが列名の一致で外しているため、無音で広がらないよう pin しています)。');
+    expect($actual['excludedLifecycle'])->toBe($union,
+        '統合後の除外一覧が 2 つの内訳と食い違っています (件数を保ったままの入れ替わりを通さないため)。');
+});
+
+test('NC-1: 台帳に無い列を母集団へ足すと NI-1 の未分類が点灯する', function (): void {
+    $entries = [
+        NullableStateColumnEntry::initialStateMarker(
+            'organization_invitations',
+            'accepted_at',
+            '招待が受諾されるまで NULL で、受諾の瞬間に一度だけ時刻が入る。NULL のまま = 未受諾である',
+        ),
+    ];
+
+    $result = nullInitialStateClassify(
+        ['organization_invitations.accepted_at', 'ghost_table.ghost_at'],
+        $entries,
+    );
+
+    expect($result['unclassified'])->toBe(['ghost_table.ghost_at']);
+    expect($result['phantom'])->toBe([]);
+});
+
+test('NC-2: 実在しない列を台帳へ足すと NI-1 の実在しない登録が点灯する', function (): void {
+    $entries = [
+        NullableStateColumnEntry::initialStateMarker(
+            'organization_invitations',
+            'accepted_at',
+            '招待が受諾されるまで NULL で、受諾の瞬間に一度だけ時刻が入る。NULL のまま = 未受諾である',
+        ),
+        NullableStateColumnEntry::initialStateMarker(
+            'organization_invitations',
+            'removed_at',
+            '既に落とした列。台帳から消し忘れた幽霊登録を再現するための合成入力である',
+        ),
+    ];
+
+    $result = nullInitialStateClassify(['organization_invitations.accepted_at'], $entries);
+
+    expect($result['phantom'])->toBe(['organization_invitations.removed_at']);
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('NC-3: 登録済みの列に DB 既定値が付くと母集団から抜けて NI-1 が点灯する', function (): void {
+    $entries = [
+        NullableStateColumnEntry::initialStateMarker(
+            'organization_invitations',
+            'accepted_at',
+            '招待が受諾されるまで NULL で、受諾の瞬間に一度だけ時刻が入る。NULL のまま = 未受諾である',
+        ),
+    ];
+
+    $columnOf = static fn (?string $default): array => [
+        'organization_invitations' => [
+            [
+                'name' => 'accepted_at',
+                'type_name' => 'timestamp',
+                'nullable' => true,
+                'default' => $default,
+                'auto_increment' => false,
+                'generation' => null,
+            ],
+        ],
+    ];
+
+    // 既定値が無いうちは母集団に入り、集合は一致する
+    $before = nullInitialStatePopulation($columnOf(null), [], [], []);
+    expect($before['population'])->toBe(['organization_invitations.accepted_at']);
+    expect(nullInitialStateClassify($before['population'], $entries)['phantom'])->toBe([]);
+
+    // 既定値の**表現ゆれ**に依存していないこと (判定は「default が null でない」ことだけ)
+    $defaults = ['now()', 'CURRENT_TIMESTAMP', "'pending'", "'pending'::character varying", '0', ''];
+    foreach ($defaults as $default) {
+        $after = nullInitialStatePopulation($columnOf($default), [], [], []);
+
+        expect($after['population'])->toBe([], sprintf('既定値 %s で母集団から抜けるべきです', $default));
+        expect(nullInitialStateClassify($after['population'], $entries)['phantom'])
+            ->toBe(['organization_invitations.accepted_at'],
+                sprintf('既定値 %s で「実在しない登録」が点灯するべきです', $default));
+    }
+
+    // Schema API が既定値を**文字列でない scalar** で返す形も同じ経路を通る
+    // (正規化が文字列へ畳んでも「null でない」ことは変わらない = 判定は中身を見ていない)。
+    $rawWithIntDefault = [
+        'organization_invitations' => [
+            [
+                'name' => 'accepted_at',
+                'type_name' => 'timestamp',
+                'nullable' => true,
+                'default' => 0,
+                'auto_increment' => false,
+                'generation' => null,
+            ],
+        ],
+    ];
+    $normalized = nullInitialStateNormalizeColumns($rawWithIntDefault);
+    expect($normalized['problems'])->toBe([]);
+    $afterInt = nullInitialStatePopulation($normalized['columns'], [], [], []);
+    expect($afterInt['population'])->toBe([]);
+    expect(nullInitialStateClassify($afterInt['population'], $entries)['phantom'])
+        ->toBe(['organization_invitations.accepted_at']);
+});
+
+test('NC-4: 作成・更新時刻の除外は列名だけでは効かない', function (): void {
+    $columns = [
+        'widgets' => [
+            [
+                'name' => 'created_at',
+                'type_name' => 'timestamp',
+                'nullable' => true,
+                'default' => null,
+                'auto_increment' => false,
+                'generation' => null,
+            ],
+        ],
+    ];
+    // widgets はモデルを持つ表なので、モデルを持たない表向けの既定名は当たらない
+    $tableless = nullInitialStateTablelessLifecycleColumns(['widgets'], ['widgets']);
+    expect($tableless)->toBe([]);
+
+    // (1) usesTimestamps() が false のモデル = 宣言が空
+    $notUsingTimestamps = nullInitialStatePopulation($columns, [], [], $tableless);
+    expect($notUsingTimestamps['population'])->toBe(['widgets.created_at']);
+    expect($notUsingTimestamps['excludedByModel'])->toBe([]);
+    expect($notUsingTimestamps['excludedByTableless'])->toBe([]);
+
+    // (2) 作成時刻の列名を差し替えたモデル = 宣言が別名
+    $renamed = nullInitialStatePopulation($columns, [], ['widgets' => ['created_on', 'updated_on']], $tableless);
+    expect($renamed['population'])->toBe(['widgets.created_at']);
+    expect($renamed['excludedByModel'])->toBe([]);
+
+    // (3) 宣言どおりの列名なら外れる (正のコントロール)
+    $declared = nullInitialStatePopulation($columns, [], ['widgets' => ['created_at', 'updated_at']], $tableless);
+    expect($declared['population'])->toBe([]);
+    expect($declared['excludedByModel'])->toBe(['widgets.created_at']);
+
+    // (4) モデルを持たない表なら枠組みの既定名で外れ、その旨が内訳に出る
+    $withoutModel = nullInitialStatePopulation(
+        $columns,
+        [],
+        [],
+        nullInitialStateTablelessLifecycleColumns(['widgets'], []),
+    );
+    expect($withoutModel['population'])->toBe([]);
+    expect($withoutModel['excludedByTableless'])->toBe(['widgets.created_at']);
+    expect($withoutModel['excludedLifecycle'])->toBe(['widgets.created_at']);
+});
+
+test('NC-5: 同じ列を 2 回宣言すると NI-2 の二重宣言が点灯する', function (): void {
+    $entries = [
+        NullableStateColumnEntry::initialStateMarker(
+            'organization_invitations',
+            'accepted_at',
+            '招待が受諾されるまで NULL で、受諾の瞬間に一度だけ時刻が入る。NULL のまま = 未受諾である',
+        ),
+        NullableStateColumnEntry::setAtCreation(
+            'organization_invitations',
+            'accepted_at',
+            '同じ列をもう一度宣言して二重登録の検出を確かめるための合成入力である',
+        ),
+    ];
+
+    $result = nullInitialStateClassify(['organization_invitations.accepted_at'], $entries);
+
+    expect($result['duplicated'])->toBe(['organization_invitations.accepted_at']);
+});
+
+test('NC-6: 母集団が空になる抽出は合格にしない', function (): void {
+    // 「nullable でない」「既定値がある」「生成列」「identity」のいずれかで全滅する形
+    $columns = [
+        'widgets' => [
+            [
+                'name' => 'id',
+                'type_name' => 'int8',
+                'nullable' => false,
+                'default' => null,
+                'auto_increment' => true,
+                'generation' => null,
+            ],
+            [
+                'name' => 'closed_at',
+                'type_name' => 'timestamp',
+                'nullable' => false,
+                'default' => null,
+                'auto_increment' => false,
+                'generation' => null,
+            ],
+            [
+                'name' => 'settled_at',
+                'type_name' => 'timestamp',
+                'nullable' => true,
+                'default' => 'now()',
+                'auto_increment' => false,
+                'generation' => null,
+            ],
+            [
+                'name' => 'computed_at',
+                'type_name' => 'timestamp',
+                'nullable' => true,
+                'default' => null,
+                'auto_increment' => false,
+                'generation' => ['type' => 'stored', 'expression' => 'now()'],
+            ],
+        ],
+    ];
+
+    $result = nullInitialStatePopulation($columns, [], [], []);
+
+    // NI-3 が課している 3 つの非空条件がいずれも満たされない = この入力では NI-3 が落ちる。
+    // **0 件を合格にしない** (正典 i5) ことを、条件そのものを合成入力で外して示す。
+    expect($result['population'])->toBe([]);
+    expect($result['temporal'])->toBe([]);
+    expect($result['enumCast'])->toBe([]);
+});
+
+test('NC-7: 拾う cast は裏付けの値を持つ列挙への cast だけである', function (): void {
+    // 拾う唯一の形
+    expect(nullInitialStateIsBackedEnumCast(AnalysisStep::class))->toBeTrue();
+    expect(nullInitialStateIsBackedEnumCast(RenderStep::class))->toBeTrue();
+
+    // 拾わない 5 形
+    foreach (['array', 'datetime', 'encrypted'] as $builtin) {
+        expect(nullInitialStateIsBackedEnumCast($builtin))->toBeFalse(sprintf('%s は拾わない', $builtin));
+    }
+    expect(nullInitialStateIsBackedEnumCast('decimal:2'))->toBeFalse();
+    expect(nullInitialStateIsBackedEnumCast(AsEnumCollection::class.':'.AnalysisStep::class))->toBeFalse();
+    expect(nullInitialStateIsBackedEnumCast(AsArrayObject::class))->toBeFalse();
+    expect(nullInitialStateIsBackedEnumCast(IdempotencyClaimStatus::class))->toBeFalse();
+
+    // 文字列ですらない cast 宣言も拾わない
+    expect(nullInitialStateIsBackedEnumCast(null))->toBeFalse();
+    expect(nullInitialStateIsBackedEnumCast(['array']))->toBeFalse();
+
+    // 母集団の側でも (b) が cast の宣言だけを見ていることを確かめる
+    $columns = [
+        'widgets' => [
+            [
+                'name' => 'step',
+                'type_name' => 'varchar',
+                'nullable' => true,
+                'default' => null,
+                'auto_increment' => false,
+                'generation' => null,
+            ],
+            [
+                'name' => 'note',
+                'type_name' => 'varchar',
+                'nullable' => true,
+                'default' => null,
+                'auto_increment' => false,
+                'generation' => null,
+            ],
+        ],
+    ];
+
+    $result = nullInitialStatePopulation($columns, ['widgets' => ['step']], [], []);
+    expect($result['population'])->toBe(['widgets.step']);
+    expect($result['enumCast'])->toBe(['widgets.step']);
+    expect($result['temporal'])->toBe([]);
+});
+
+test('NC-8: Schema API の戻りからキーが欠けていたら適合と判定せず落ちる', function (): void {
+    $raw = [
+        'users' => [
+            [
+                'name' => 'email_verified_at',
+                'type_name' => 'timestamp',
+                'nullable' => true,
+                'default' => null,
+                'auto_increment' => false,
+                'generation' => null,
+            ],
+            // name そのものが欠けた要素 (もっとも厳しい形)
+            [
+                'type_name' => 'timestamp',
+                'nullable' => true,
+                'default' => null,
+            ],
+        ],
+    ];
+
+    $normalized = nullInitialStateNormalizeColumns($raw);
+
+    expect($normalized['problems'])->toHaveCount(1);
+    expect($normalized['problems'][0])
+        ->toContain('users columns[1]')
+        ->toContain('列名: 取得できず')
+        ->toContain('欠けているキー = name, auto_increment, generation')
+        ->toContain('実際のキー = type_name, nullable, default');
+
+    // 適合と判定しない = その要素は正規化後の母集団に現れない
+    expect(nullInitialStatePopulation($normalized['columns'], [], [], [])['population'])
+        ->toBe(['users.email_verified_at']);
+
+    // 型が想定外の要素も同じく落ちる (fail-closed)
+    $badType = nullInitialStateNormalizeColumns([
+        'users' => [
+            [
+                'name' => 'email_verified_at',
+                'type_name' => 'timestamp',
+                'nullable' => 'yes',
+                'default' => null,
+                'auto_increment' => false,
+                'generation' => null,
+            ],
+        ],
+    ]);
+    expect($badType['problems'])->toHaveCount(1);
+    expect($badType['problems'][0])->toContain('型が想定外のキー = nullable');
+    expect($badType['columns']['users'])->toBe([]);
+});
diff --git a/tests/Support/InitialState/NullInitialStateClass.php b/tests/Support/InitialState/NullInitialStateClass.php
new file mode 100644
index 0000000..4102a62
--- /dev/null
+++ b/tests/Support/InitialState/NullInitialStateClass.php
@@ -0,0 +1,35 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\InitialState;
+
+/**
+ * NULL が意味を持つ列の区分。
+ *
+ * 判定は 1 つの問いで決まる: **その行が生まれた時点で、この列は必ず NULL か**。
+ *
+ * 母集団は**実スキーマ**の「nullable かつ DB 既定値を持たない列」であり、
+ * 人が申告したモデル一覧やディレクトリではない (申告の外に置かれた列は何をしても検出できない)。
+ */
+enum NullInitialStateClass: string
+{
+    /** 生成時は必ず NULL。NULL であること自体が「まだその段階に達していない」を意味する。 */
+    case InitialStateMarker = 'initial_state_marker';
+
+    /** 生成時に値が入りうる列。NULL は該当なし / 無期限 / 未指定であって進行段階ではない。 */
+    case SetAtCreation = 'set_at_creation';
+
+    /** どちらとも決めていない列。隠さずここへ載せる (件数と列名を gate が pin する)。 */
+    case Undecided = 'undecided';
+
+    /** 人が読む区分名 (失敗メッセージ用)。 */
+    public function label(): string
+    {
+        return match ($this) {
+            self::InitialStateMarker => '初期状態の目印',
+            self::SetAtCreation => '生成時に決まりうる値',
+            self::Undecided => '未確定',
+        };
+    }
+}
diff --git a/tests/Support/InitialState/NullableStateColumnEntry.php b/tests/Support/InitialState/NullableStateColumnEntry.php
new file mode 100644
index 0000000..64e562e
--- /dev/null
+++ b/tests/Support/InitialState/NullableStateColumnEntry.php
@@ -0,0 +1,63 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\InitialState;
+
+use InvalidArgumentException;
+
+/**
+ * 「nullable かつ DB 既定値を持たない列」1 本分の分類の宣言。
+ *
+ * **コンストラクタは private** で、区分ごとの名前付き生成子からしか作れない
+ * (RetentionTableEntry と同じ形。不正な組み合わせを型で作らせない)。
+ *
+ * 根拠の長さは gate の規則 (NI-2) とは**別に**コンストラクタでも検査する。
+ * 台帳を作った時点で落ちるので、短い根拠のまま集合比較まで進まない。
+ */
+final readonly class NullableStateColumnEntry
+{
+    /** 根拠の最低文字数 (「同上」「N/A」を機械的に弾く。gate 側の検査は NI-2)。 */
+    public const int RATIONALE_MIN_LENGTH = 30;
+
+    private function __construct(
+        public string $table,
+        public string $column,
+        public NullInitialStateClass $class,
+        public string $rationale,
+    ) {
+        if ($table === '' || $column === '') {
+            throw new InvalidArgumentException('表名と列名は空にできません');
+        }
+        // 根拠は日本語で書くため、バイト数ではなく**文字数**で数える。
+        if (mb_strlen($rationale) < self::RATIONALE_MIN_LENGTH) {
+            throw new InvalidArgumentException(
+                sprintf('%s.%s の根拠が %d 文字未満です', $table, $column, self::RATIONALE_MIN_LENGTH),
+            );
+        }
+    }
+
+    /** 生成時は必ず NULL で、NULL 自体が「まだその段階に達していない」を意味する列。 */
+    public static function initialStateMarker(string $table, string $column, string $rationale): self
+    {
+        return new self($table, $column, NullInitialStateClass::InitialStateMarker, $rationale);
+    }
+
+    /** 行を作る時点で値が入りうる列 (期限 / 外部が決めた値の写し / 任意の属性)。 */
+    public static function setAtCreation(string $table, string $column, string $rationale): self
+    {
+        return new self($table, $column, NullInitialStateClass::SetAtCreation, $rationale);
+    }
+
+    /** どちらとも決められていない列。$rationale には**何が決まっていないか**を書く。 */
+    public static function undecided(string $table, string $column, string $rationale): self
+    {
+        return new self($table, $column, NullInitialStateClass::Undecided, $rationale);
+    }
+
+    /** 集合比較の正規化キー (gate 側で文字列連結を書かないための唯一の入口)。 */
+    public function key(): string
+    {
+        return $this->table.'.'.$this->column;
+    }
+}
diff --git a/tests/Support/InitialState/NullableStateColumnRegistry.php b/tests/Support/InitialState/NullableStateColumnRegistry.php
new file mode 100644
index 0000000..9c52cf9
--- /dev/null
+++ b/tests/Support/InitialState/NullableStateColumnRegistry.php
@@ -0,0 +1,420 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\InitialState;
+
+/**
+ * 実スキーマの「nullable かつ DB 既定値を持たない」列のうち、
+ * 時刻型の列と BackedEnum へ cast された列を全数分類した台帳。
+ *
+ * ★**除外一覧を持たない**。母集団に入った列は必ずここに現れる
+ *   (除外の口を作ると、そこへ名前を足すだけで検査から逃げられる)。
+ *
+ * ★区分は「その行が生まれた時点で、この列は必ず NULL か」の**1 つの問い**で決まる。
+ *   必ず NULL なら「初期状態の目印」、生成時に値が入りうるなら「生成時に決まりうる値」、
+ *   読んでも決められないなら「未確定」へ載せる (隠さない)。
+ *
+ * ★**DB 既定値を持つ状態列はここに載らない**。そちらは AGENTS.md ドメイン固有規約 1 (ii) /
+ *   規約 2 と tests/Architecture/ScenarioWritePathInventoryTest.php の担当であり、
+ *   同じ事実を 2 か所で検査しない。
+ *
+ * 実スキーマとの両方向の集合一致は
+ * tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php が
+ * deny-by-default で強制する (保証しないものの正本も同ファイルの docblock)。
+ */
+final class NullableStateColumnRegistry
+{
+    /**
+     * 宣言の並び (**`表名.列名` をキーにした連想配列にしない**)。
+     *
+     * 連想配列にすると同じ列を 2 回書いても後の 1 件で上書きされ、**二重宣言が消えてしまう**。
+     * 並びのまま返し、キー化と二重宣言の検出は gate 側の純関数が行う。
+     *
+     * @return list<NullableStateColumnEntry>
+     */
+    public static function entries(): array
+    {
+        return [
+            // --- AI 解析 / レンダの進行段階 (状態語彙で「まだ / 済んだ」を表す) ---
+            NullableStateColumnEntry::initialStateMarker(
+                'analysis_jobs',
+                'step',
+                'AnalysisJobService::trigger() は行を作るとき status と関連しか代入せず、段階は '
+                .'AnalysisPipeline の進捗書き込みが最初に入れる。NULL = まだどの段階にも入っていない',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'render_jobs',
+                'step',
+                'RenderJobService::trigger() は段階を代入せず、RenderPipeline の進捗書き込みが '
+                .'最初に入れる。NULL = まだどの段階にも入っていない',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'render_jobs',
+                'error_code',
+                '失敗の分類はロック下の既存行へ書き戻される (RenderJobService::failJob)。'
+                .'NULL = まだ失敗していない。既定値が付くと生まれた瞬間に失敗した行ができる',
+            ),
+
+            // --- API キー / OAuth セッション / パスキーの利用と失効 ---
+            NullableStateColumnEntry::initialStateMarker(
+                'api_keys',
+                'last_used_at',
+                'ApiKeyGuard が認証成功のたびに既存行へ打刻する。NULL = 発行後まだ一度も使われて '
+                .'いない。既定値が付くと未使用のキーが使用済みに見える',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'api_keys',
+                'revoked_at',
+                '失効操作が既存行へ打刻し、以後の照合から外れる。NULL = まだ有効である。'
+                .'既定値が付くと発行した瞬間に失効したキーができる',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'api_keys',
+                'expires_at',
+                'ApiKey の発行時に有効期限を決めて書き込む列で、NULL は無期限を意味する。'
+                .'進行段階ではなく、行を作る側が決める属性である',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'oauth_sessions',
+                'last_used_at',
+                '接続が使われるたびに既存行へ打刻する。NULL = 接続を作った後まだ使われていない。'
+                .'既定値が付くと未使用の接続が使用済みに見える',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'oauth_sessions',
+                'revoked_at',
+                '組織アクセスの失効の窓口がロック下の既存行へ打刻する。NULL = まだ有効な接続である。'
+                .'既定値が付くと作った瞬間に失効した接続ができる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'passkeys',
+                'last_used_at',
+                'パスキーでの認証が成功するたびに既存行へ打刻する。NULL = 登録後まだ使われていない。'
+                .'手段保持の判断材料になるため既定値を置いてはならない',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'oauth_device_codes',
+                'user_approved_at',
+                '機器認可の流れで利用者が承認した瞬間に既存行へ打刻される。NULL = まだ承認されて '
+                .'いない。既定値が付くと発行した瞬間に承認済みの機器コードができる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'oauth_device_codes',
+                'last_polled_at',
+                '機器側が交換を試みるたびに既存行へ打刻される。NULL = まだ一度も問い合わせが来て '
+                .'いない。既定値が付くと問い合わせの間隔の判断が狂う',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'oauth_device_codes',
+                'expires_at',
+                '機器コードの発行時に有効期限を決めて書き込む列である。'
+                .'進行段階ではなく、発行する側が決める属性である',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'oauth_access_tokens',
+                'expires_at',
+                'アクセス手形の発行時に有効期限を決めて書き込む列である。'
+                .'進行段階ではなく、発行する側が決める属性である',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'oauth_refresh_tokens',
+                'expires_at',
+                '更新用手形の発行時に有効期限を決めて書き込む列である。'
+                .'進行段階ではなく、発行する側が決める属性である',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'oauth_auth_codes',
+                'expires_at',
+                '認可コードの発行時に有効期限を決めて書き込む列である。'
+                .'進行段階ではなく、発行する側が決める属性である',
+            ),
+
+            // --- 組織 / 招待 / 利用者の節目 ---
+            NullableStateColumnEntry::initialStateMarker(
+                'organization_invitations',
+                'accepted_at',
+                '招待が受諾されるまで NULL で、受諾の瞬間にロック下の既存行へ一度だけ時刻が入る。'
+                .'既定値が付くと発行した招待がすべて受諾済みになる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'organization_invitations',
+                'revoked_at',
+                '招待の取り消し操作が既存行へ打刻する。NULL = まだ取り消されていない。'
+                .'既定値が付くと発行した瞬間に取り消された招待ができる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'organizations',
+                'deleted_at',
+                '論理削除の打刻であり、組織を作る時点では必ず NULL である。'
+                .'既定値が付くと新しい組織が生まれた瞬間に削除済みとして扱われる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'organizations',
+                'free_plan_activated_at',
+                '個人プランの有効化がロック下の既存行へ打刻する。NULL = まだ有効化していない。'
+                .'既定値が付くと契約していない組織が無料枠を持つ',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'organizations',
+                'personal_declared_at',
+                '個人利用の申告がロック下の既存行へ打刻する。NULL = まだ申告していない。'
+                .'既定値が付くと申告していない組織が申告済みに見える',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'organizations',
+                'signup_tickets_granted_at',
+                '初回付与の重複防止の目印で、付与を確定した経路が既存行へ条件付きで打刻する。'
+                .'NULL = まだ付与していない。既定値が付くと初回付与が永久に行われなくなる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'organizations',
+                'stripe_customer_redacted_at',
+                '決済事業者側の顧客情報を伏せた記録で、専用コマンドが既存行へ打刻する。'
+                .'NULL = まだ伏せていない。既定値が付くと伏せていない組織が処理済みに見える',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'organizations',
+                'trial_ends_at',
+                '課金基盤が持つ試用期限の写しで、行を作る側が期限を決めて書き込む。'
+                .'NULL は試用が設定されていないことを意味し、進行段階ではない',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'users',
+                'two_factor_confirmed_at',
+                '第二要素の登録を利用者が確認した瞬間に既存行へ打刻される。NULL = 未確認である。'
+                .'既定値が付くと第二要素を持たない利用者が確認済みになり必須組織の判定が壊れる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'users',
+                'deletion_requested_at',
+                '退会予約の受付時に既存行へ打刻する (会員登録時は必ず NULL)。'
+                .'NULL = 退会予約が無い。既定値が付くと登録した瞬間に退会予約が入る',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'users',
+                'deletion_purge_after',
+                '退会予約と同時に書かれる執行期限で、会員登録時は必ず NULL である。'
+                .'既定値が付くと予約していない利用者が日次の物理削除の対象になりうる',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'users',
+                'email_verified_at',
+                'SSO 登録は身元提供者が検証した連絡先として生成時に時刻を書き込むため、'
+                .'行が生まれた時点で必ず NULL とは言えない (通常の登録経路では NULL で始まる)',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'users',
+                'terms_accepted_at',
+                '会員登録の経路がいずれも同意時刻を生成時に書き込む。'
+                .'進行段階ではなく、行を作る側が決める属性である',
+            ),
+
+            // --- 問い合わせ ---
+            NullableStateColumnEntry::initialStateMarker(
+                'inquiries',
+                'closed_at',
+                '状態が「対応済み」へ変わる更新のときだけ既存行へ打刻される (新規は打刻しない)。'
+                .'NULL = まだ対応が終わっていない。既定値が付くと届いた瞬間に対応済みになる',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'inquiries',
+                'terms_accepted_at',
+                '問い合わせを作る行為そのものが同意なので、生成時に同意時刻を書き込む。'
+                .'進行段階ではなく、行を作る側が決める属性である',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'inquiries',
+                'source',
+                '問い合わせがどの導線から届いたかを生成時に固定する分類である。'
+                .'NULL は導線を記録していない古い行を意味し、進行段階ではない',
+            ),
+
+            // --- アプリ内通知 ---
+            NullableStateColumnEntry::initialStateMarker(
+                'notifications',
+                'read_at',
+                '既読操作が既存行へ打刻する。NULL = まだ読まれていない。'
+                .'既定値が付くと届いた通知がすべて既読になり通知センターが機能しなくなる',
+            ),
+
+            // --- 決済事業者からの通知 ---
+            NullableStateColumnEntry::initialStateMarker(
+                'stripe_webhook_events',
+                'processed_at',
+                '終局の書き込みが条件付き更新で既存行へ打刻する。NULL = まだ処理していない。'
+                .'既定値が付くと届いた通知が処理済みになり業務が丸ごと落ちる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'stripe_webhook_events',
+                'recovery_reason',
+                '滞留回収が拾い直した理由を既存行へ書く列で、受信時は必ず NULL である。'
+                .'NULL = 回収の対象になっていない。既定値が付くと回収の観測が壊れる',
+            ),
+
+            // --- 継続課金の契約 ---
+            NullableStateColumnEntry::initialStateMarker(
+                'subscriptions',
+                'past_due_since',
+                '支払い遅延を観測した時刻で、書き込むのはロック下の既存行を更新する 1 箇所だけである。'
+                .'NULL = 遅延していない。既定値が付くと契約した瞬間に猶予が始まる',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'subscriptions',
+                'ends_at',
+                '決済事業者の状態の写しを取り込む経路が解約予定日として書き込む値で、'
+                .'生成時に非 NULL がありうる。NULL は解約予定が無いことを意味する',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'subscriptions',
+                'trial_ends_at',
+                '決済事業者の状態の写しを取り込む経路が試用期限として書き込む値で、'
+                .'生成時に非 NULL がありうる。NULL は試用が無いことを意味する',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'subscriptions',
+                'current_period_end',
+                '決済事業者の状態の写しを取り込む経路が現在の期間の終わりとして書き込む値で、'
+                .'生成時に非 NULL がありうる。進行段階ではなく外部が決めた値の写しである',
+            ),
+
+            // --- 契約手続き / チケット購入手続き ---
+            NullableStateColumnEntry::initialStateMarker(
+                'billing_checkout_sessions',
+                'completed_at',
+                '決済確定の通知を受けたときに既存行へ打刻する。NULL = まだ確定していない。'
+                .'既定値が付くと申込を作った瞬間に成立した取引ができる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'billing_checkout_sessions',
+                'pm_reuse_dispatched_at',
+                '支払い手段の流用処理を投入したことの目印で、確定後に既存行へ打刻する。'
+                .'NULL = まだ投入していない。既定値が付くと投入されないまま済んだことになる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'ticket_checkout_sessions',
+                'completed_at',
+                'チケット購入の決済確定を受けたときに既存行へ打刻する。NULL = まだ確定していない。'
+                .'既定値が付くと購入手続きを作った瞬間に成立した取引ができる',
+            ),
+
+            // --- 課金の通知 ---
+            NullableStateColumnEntry::initialStateMarker(
+                'billing_notifications',
+                'sent_at',
+                '送信に成功したときだけ既存行へ打刻する条件付き更新である。NULL = まだ送っていない。'
+                .'既定値が付くと予定した通知がすべて送信済みになり実際には届かなくなる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'billing_notifications',
+                'failed_at',
+                '送信に失敗したときだけ既存行へ打刻する。NULL = まだ失敗していない。'
+                .'既定値が付くと予定した通知が最初から失敗扱いになる',
+            ),
+
+            // --- オートリチャージ ---
+            NullableStateColumnEntry::setAtCreation(
+                'ticket_auto_recharges',
+                'consented_at',
+                '同意の受付が設定行そのものを生成しながら同意時刻を書き込む。'
+                .'進行段階ではなく、行を作る側が決める属性である',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'ticket_auto_recharges',
+                'disabled_reason',
+                '連続失敗などで自動購入を止めたときに既存行へ書く理由である。'
+                .'NULL = 止められていない。既定値が付くと同意した瞬間に停止した設定ができる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'ticket_auto_recharge_attempts',
+                'resolved_at',
+                '自動購入の試行が決着したときに既存行へ打刻する。NULL = まだ決着していない。'
+                .'既定値が付くと試行を作った瞬間に決着済みになり二重購入の防止が壊れる',
+            ),
+
+            // --- チケット台帳と予約 ---
+            NullableStateColumnEntry::setAtCreation(
+                'ticket_ledger_entries',
+                'granted_at',
+                '付与の行は生成時に付与時刻を書き込み、繰越の行は明示的に NULL で作る。'
+                .'NULL は付与ではない行を意味し、進行段階ではない',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'ticket_ledger_entries',
+                'expires_at',
+                '台帳の行を作る時点で有効期限を決めて書き込む。'
+                .'NULL は無期限の残高を意味し、進行段階ではない',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'ticket_ledger_entries',
+                'carried_forward_through',
+                '繰越の行を作るときに集約の終端として生成時に書き込む値である。'
+                .'NULL は繰越ではない行を意味し、進行段階ではない',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'ticket_ledger_entries',
+                'source',
+                '残高の出所を台帳の行の生成時に固定する分類である。'
+                .'NULL は出所を記録していない古い行を意味し、進行段階ではない',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'ticket_reservations',
+                'consume_source',
+                '予約の生成時に「どの出所から消費するか」を固定する (予約の保存前に代入する)。'
+                .'NULL は出所を固定していない古い行を意味し、進行段階ではない',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'ticket_reservations',
+                'consume_expires_at',
+                '予約の生成時に「どの期限の残高から消費するか」を固定する。'
+                .'NULL は無期限の残高からの消費を意味し、進行段階ではない',
+            ),
+
+            // --- 料金表 (現行世代と同期状態) ---
+            NullableStateColumnEntry::initialStateMarker(
+                'plan_prices',
+                'active_to',
+                '新しい価格へ置き換えるときに旧行へ打刻する終了時刻で、作るときは明示的に NULL である。'
+                .'NULL = まだ現行である。既定値が付くと作った瞬間に失効した価格ができる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'plan_prices',
+                'synced_at',
+                '決済事業者との同期が済んだ既存行へ打刻する。NULL = まだ同期していない。'
+                .'本番では未同期の価格を弾く判断に使うため、既定値を置いてはならない',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'ticket_volume_prices',
+                'active_to',
+                '新しい価格帯へ置き換えるときに旧行へ打刻する終了時刻で、作るときは NULL である。'
+                .'NULL = まだ現行である。既定値が付くと作った瞬間に失効した価格帯ができる',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'ticket_volume_prices',
+                'synced_at',
+                '決済事業者との同期が済んだ既存行へ打刻する。NULL = まだ同期していない。'
+                .'本番では未同期の価格帯を弾く判断に使うため、既定値を置いてはならない',
+            ),
+
+            // --- 撮影テイクとカット ---
+            NullableStateColumnEntry::initialStateMarker(
+                'takes',
+                'downloaded_at',
+                '取り出しの操作がロック下の既存行へ打刻する。NULL = まだ取り出していない。'
+                .'既定値が付くと撮ったばかりのテイクが取り出し済みに見える',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'takes',
+                'captured_at',
+                '撮影端末が申告した撮影時刻を、テイクの登録時にそのまま書き込む。'
+                .'NULL は端末が時刻を申告しなかったことを意味し、進行段階ではない',
+            ),
+            NullableStateColumnEntry::setAtCreation(
+                'cuts',
+                'material_type',
+                'シナリオを反映するときにカットの素材の種類として生成時に書き込む。'
+                .'NULL は種類が決まっていないカットを意味し、進行段階ではない',
+            ),
+        ];
+    }
+}
```

---

## user: テスト結果 (修正後)

- `composer phpstan` → `[OK] No errors`
- `vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`
- 新規検査単体は修正前に `15 passed / 74 assertions` で緑。修正後の全件 `composer test` は実行中 (グローバルテストロック待ち)

## user: 判断してほしい点

Round 1 の Warning 3 件と Suggestion 3 件のうち、対応 3 件・反論 2 件・見送り 1 件とした。反論と見送りの根拠が妥当かを判定し、残る Critical / Warning があれば挙げてほしい。

【アプリの使命 (North Star) — AGENTS.md より転記】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【思考原則 — AGENTS.md より転記】

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

【禁止事項 — AGENTS.md より転記】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【この設計に固有の前提 — 議論の対象にしないこと】
以下はオーナーが確定した決定であり、値の妥当性はレビュー対象外です。
- 猶予期間 30 日 / 課金取引記録の保持 7 年 / 猶予中は凍結方式
- 規約文面は家系の先例 (spirux の /privacy「取引関係書類等につき最長 7 年」) に揃える
- `config/legal.php` の `consent_version` を `draft-1` から動かさない
- 追記文面は法務レビュー前の草案である

指摘してほしいのは「その決定を前提にしたときの設計の正しさ」です。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: account-deletion-grace (猶予期間つき削除 + 保持期間 + 事業者側データ)

> 一次入力: `devnotes/20260809-0908-account-deletion-grace/recon-brief.md`
> 正典: lctl 台帳 feature `account-deletion-billing-guard` / 標準形 v1 (裁定 AG-128, 2026-08-08)
> 実体は spirux:T1133。**オーナー決定 (猶予 30 日 / 保持 7 年 / 凍結方式 / 文面は家系の先例に揃える /
> `consent_version` は `draft-1` から動かさない) は前提であり議論の対象ではない**。

## 0. オーナー決定の転記 (逸脱不可)

| 項目 | 値 |
|---|---|
| 猶予期間 | **30 日** |
| 課金取引記録の保持 | **7 年** |
| 猶予中の扱い | **凍結方式** (users 行の生死を変えない = SoftDeletes を使わない) |
| 規約文面 | **spirux の /privacy「取引関係書類等につき最長 7 年」に揃える。独自の法的主張を書かない** |
| `config/legal.php` の `consent_version` | **`draft-1` から動かさない** |
| 追記文面の位置づけ | **法務レビュー前の草案**。設計と実装の申し送りに明記する |

## 1. 背景・課題

aicue には退会 (アカウント削除) の課金ガード (T115) だけが実在し、標準形 v1 が必須とする 3 点のうち
2 点半が無い。実査で確認した不在 (recon-brief §aicue の現状):

1. **(2) 猶予期間つき削除が完全に未実装**。`deleteAccount()` は `$freshUser->delete()` の物理削除 1 発で、
   予約列・予約 route・取消 route・日次執行バッチのいずれも存在しない。
   **誤操作 (または乗っ取り) で押した退会は 1 秒後には取り返しがつかない**。
2. **(3) 保持期間の実装が無い**。`config/legal.php` は `inquiry_retention_days` しか持たず、
   `resources/views/legal/privacy.blade.php` は保持年数の宣言を 1 行も持たないスタブである。
   規約が何も宣言していないため、実処理と対応づける相手が存在しない。
3. **(1) 決済事業者側データの扱い**が「退会経路から事業者 API を呼ばない」の Feature テスト 2 本だけで、
   **依存閉包を見る静的 gate が無い**。redaction を実施したことの記録口も runbook も無い。

課題の核は 1 である。**「削除は不可逆であり、不可逆な操作には取り消せる窓が要る」**。
現状の aicue は窓が 0 秒であり、これは使命 (現場作業者が専門知識ゼロで使える) と正面から衝突する。
現場の作業者が設定画面で操作を誤ったとき、組織の動画マニュアル資産への到達手段 (owner) が
その場で永久に失われる。

## 2. 改善アイデア

標準形 v1 の (1)(2)(3) を aicue に実装する。**3 つの PR に分割し、依存順に直列で main へ入れる**
(分割の根拠と「中途半端が残らない」ことの説明は §6)。

### PR-A: (1) 決済事業者側データの扱い

- **原則の機械化**: `tests/Architecture/AccountDeletionPathGateTest.php` を新設し、退会経路の
  **依存閉包**から決済事業者 SDK (`Stripe\*` / `Cashier::stripe()` / `->stripe()`) へ到達しないことを
  静的に検査する。現行の behavioral 2 本 (`AccountDeletionTest`) は「呼ばれなかった」ことしか言えず、
  **新しい依存を足した瞬間に沈黙する**。
- **redaction の記録口**: `organizations.stripe_customer_redacted_at` を 1 列足し、
  `billing:mark-stripe-customer-redacted {organization}` (行ロック下 1 回限り・冪等・**Stripe API は呼ばない**)
  で人手 redaction の実施を記録する。
- **runbook**: `docs/account-deletion-runbook.md` を新設。90 日 / 最大 30 日の制約は
  **一次情報 (Stripe 公式 doc) の URL と確認日を同時に書く**。引けなければ「未 pin」と明示する
  (`docs/architecture.md` 自身が現にそう書いている状態を放置しない)。

### PR-B: (2) 猶予期間つき削除 (凍結方式)

- `users` に **`deletion_requested_at` / `deletion_purge_after` の 2 列**を足す。SoftDeletes は使わない
  (users 行の生死を変えない = 凍結方式の定義)。
- **即時削除と猶予つき予約を併存**させる (標準形の必須):
  - `DELETE /settings/account` (`settings.account.destroy`) = **即時削除のまま不変**
  - `POST /settings/account/deletion-request` = 猶予つき予約 (30 日)
  - `DELETE /settings/account/deletion-request` = 取消
- **執行は既存の `deleteAccount()` をそのまま呼ぶ**。日次 `account:purge-deletion-requests` が
  `deletion_purge_after <= now()` の user を拾い、既存経路を通す。これにより
  **「予約実行時に課金ガードを再評価する」が構造的に保証される** (template が実装コストとして挙げた
  懸念は、経路を分けないことで消える)。
- **凍結の範囲は最小**: 業務 route group (`require-active-subscription` group) だけを止める。
  ログイン・`/settings`・取消・課金・組織管理・通知は**必ず通す** (§4-2)。

### PR-C: (3) 保持期間 (規約の宣言と実処理の対応づけ)

- `resources/views/legal/privacy.blade.php` に **保持期間の節を追記**する。文面は spirux の先例
  「取引関係書類等につき最長 7 年」に揃える。**年数の数値は `config('legal.billing_retention_years')` から
  描画する** (§4-3 の三者一致の要)。
- `config/legal.php` に `billing_retention_years => 7` を足す。**env は使わない**
  (`config/idempotency.php` の `retention_hours` と同じ理由 — 環境ごとに変えてよい運用値ではない。
  まして法務文書が宣言する値である)。
- 日次 `billing:purge-retention-expired` (**dry-run 既定 + `--apply`**。`PurgeInquiriesCommand` が先例) が
  保持期限を超えた課金取引記録を処理する。
- **三者一致の機械化**は「照合」ではなく「単一出典化」で行う (§4-3)。

## 3. 期待効果

- **使命への貢献**: 現場作業者の誤操作が 30 日以内なら自力で取り消せる。組織の動画マニュアル資産への
  到達手段 (唯一 Owner) が 1 クリックで永久に失われる経路を塞ぐ。
- **家系への貢献**: aicue セルが `pending` → `implemented` (v1) になる。
  laravel-claude-template / aigenba が「規約文面が無いので (3) は着手不能」と書いた共通制約を、
  aicue は文面追記で解く。その解き方 (**数値だけを config から描画して三者一致を構造化する**) は
  家系 3 リポジトリへ還流できる。
- **観測できる成功条件**: (a) 予約 → 30 日 → 執行の一巡が Feature テストで緑、(b) 予約中でも
  ログイン・取消・解約・移譲に到達できることがテストで固定、(c) 規約の年数 / config / purge 閾値が
  1 箇所を変えると 3 つとも動く (=drift しない) ことが gate で固定。

## 4. 「設計で決めるべきこと」5 点への結論

### 4-1. 凍結方式の具体形 / 即時削除との併存

**結論**: `users` に `deletion_requested_at` + `deletion_purge_after` の 2 列。SoftDeletes は使わない。
即時削除は既存 route のまま**一切変更しない**。予約・取消は**新 route 2 本**で足す。
執行は**既存 `deleteAccount()` の再利用**。

- **なぜ 2 列で、猶予日数スナップショット (aigenba 形) にしないか**: `deletion_purge_after` を絶対時刻で
  持てば「config 変更を既予約へ遡及させない」が 1 列で表現でき、バッチのクエリが
  `where deletion_purge_after <= now()` の 1 条件で済む。日数は `purge_after - requested_at` で導出できる。
  2 つの表現を持たない (思考原則 2)。`deletion_requested_at` を別に持つのは UI 表示と監査のため。
- **なぜ即時削除を予約に置き換えないか**: 標準形が**併存**を必須にしている。加えて既存
  `tests/Feature/Auth/AccountDeletionTest.php` の 16 本は既存 route の即時削除の振る舞いを固定しており、
  ここを予約に変えると 16 本すべてが赤くなる。**既存テストの意味を壊さずに機能を足せる形**を採る
  (禁止事項 3)。UI の既定ボタンは「30 日後に削除 (予約)」、副導線として「今すぐ削除」を出す。
- **なぜ執行を専用経路にしないか**: 経路を分けると「予約実行時のガード再評価」を新規に書くことになり、
  判定が 2 箇所へ分岐する。`deleteAccount()` をそのまま呼べば、行ロック下の再評価・監査記録・
  ValidationException の契約をまるごと継承できる。`$beforeDelete` は session を持たないバッチでは `null`。
- **予約/取消の writer は `OrganizationMembershipService` に置く**。理由は責務ではなく**ロック順序**である。
  予約列の書き込みは `lockForMembershipWrite`(users 昇順 → organizations 昇順) と同じ順序に乗せる必要があり、
  順序の SoT を 2 クラスに分けるとデッドロックの余地が生まれる。`MembershipWriteLockInventoryTest` の
  `directLock` へ 2 メソッドを登録する (drift-guard がそれを強制する)。
- **執行時にブロッカーが立っていたら**: 予約は**維持**し (取消はユーザーの明示操作のみ)、
  `report()` で観測する。予約を勝手に取り消すと「退会したつもりが残っている」、
  執行を強行するとガードの意味が消える。ユーザー側には既存の `accountDeletionBlockers` props が
  そのまま「次の一手」を出す。**凍結範囲が最小なので、その一手 (解約・移譲) には到達できる**。

### 4-2. 凍結中に何を止めるか

**結論**: **`require-active-subscription` group の中の業務 route だけ**を止める。
group の外 (構造的 allowlist) は**すべて通す**。遮断時は 403 ではなく `/settings` へ redirect し、
取消ボタンのある画面で受ける。

| 予約中の可否 | 対象 | 根拠 |
|---|---|---|
| **可** | ログイン / ログアウト / セッション | 取消の前提。塞いだ瞬間に誤操作救済が成立しない |
| **可** | `/settings` (予約バナー + 取消ボタン) | **取消の唯一の到達先**。ここを塞ぐと詰み |
| **可** | `billing.*` / `billing.tickets.*` / `billing.auto-recharge.*` / `onboarding.*` | 退会ブロッカー (生きた課金責務) を**自分で解消する**手段 |
| **可** | `organizations.*` (移譲・メンバー整理) | 退会ブロッカー (孤児メンバー) を**自分で解消する**手段 |
| **可** | `notifications.*` | 予約・執行不能の通知を読む手段 |
| **不可** | `require-active-subscription` group 内の業務 route (projects / manuals / capture) | 執行時に消えるデータを新しく増やさせない。凍結の実体はここだけ |

- **なぜ「一切止めない」(aigenba 形) にしないか**: オーナー決定が凍結方式であること、および
  予約中に新規の動画マニュアル・撮影テイクを作らせると 30 日後にそれごと消える (= ユーザーの損失を
  アプリが黙って増やす) ため。
- **なぜ止める範囲を業務 group に限るか**: AGENTS.md ドメイン規約 4 と同じ思想 —
  **行き先のない詰みを作らない**。特に「予約中はブロッカーを解消できない」状態を作ると、
  執行もできず取消もしなければ永久凍結になる。上表の「可」はすべて**詰み回避のために必要**である。
- **allowlist を二重管理しない**: 課金ゲートの group 内/外という**既存の構造**をそのまま使う。
  新しい allowlist 定数を作らない。ドメイン規約 4 (新しい業務ドメインの route は group の中) が
  そのまま凍結範囲の定義にもなる。
- **middleware の実行位置**: `EnsureAccountNotPendingDeletion` は **302 で短絡する** middleware なので、
  テナント境界 404 (`project.in-route-org`) **より後**でなければ 1 bit の存在オラクルになる
  (AGENTS.md 不変条件 10)。`bootstrap/app.php` の priority list の web 鎖の末尾
  (`RequireActiveSubscription` の直後) に append し、`TenantBoundaryOrderingTest` に登録する。
- **route:cache 前提**: group への直付けで配線する。`RouteMiddlewareBinder` の後付けは使わない
  (cached 起動では 1 本も効かず、無音で保護が外れる = T135 / AGENTS.md 運用要件)。

### 4-3. 保持年数と実処理の対応づけの機械化

**結論**: 三者「照合」ではなく **三者「単一出典化」** で機械化する。自然言語の散文は人間が書き、
**数値だけ**が `config('legal.billing_retention_years')` から流れる形にする。

```
config/legal.php  billing_retention_years = 7   ← 唯一の出典 (env を使わない)
        │
        ├─→ App\Support\Legal\BillingRetention::years() / ::threshold()   ← 唯一の解決点
        │        ├─→ resources/views/legal/privacy.blade.php  (規約の文面が描画する数値)
        │        └─→ app/Console/Commands/Billing/PurgeBillingRetentionCommand.php (purge 閾値)
```

- **機械が保証すること**:
  1. 規約 blade の保持期間節が `BillingRetention` 経由でしか年数を描画しない
     (blade に `7` の literal を書けない。`LegalConsentVersionSingleSourceTest` と同じ token 走査 +
     exact-fit caller inventory の書式)。
  2. purge コマンドの閾値が `BillingRetention::threshold()` 由来である (同上の inventory)。
  3. **実描画の behavioral 検査**: `GET /privacy` を実際に叩き、`data-legal-retention="billing-records"`
     マーカー要素のテキストに `config` 由来の年数が現れることを Feature テストで固定する
     (静的走査だけだと「節ごと消えた」を検出できない)。
  4. **purge の実挙動**: 閾値の 1 秒前後の境界 2 件で「片方だけ消える」ことを Feature テストで固定する。
- **機械が保証しないこと (「保証しないもの」に明記する)**:
  - **文面の日本語が法的に正しいか / 7 年が法令上妥当か**。これは法務レビューの仕事であり、
    本タスクの追記は**草案**である。
  - **散文部分の意味と実処理の一致**。機械が見るのは数値 1 つとマーカーの存在だけで、
    「取引関係書類等」という語が指す集合と purge 対象テーブル集合が一致することは保証しない。
  - **purge 対象テーブルの網羅性**。対象は inventory への人間の申告であり、
    機械は「申告なしに対象を増減できない」ことしか強制しない。
  - **`consent_version`**: 本タスクでは `draft-1` から動かさない (オーナー決定)。したがって
    「文面が変わったのに版が上がっていない」ことを機械は検出しない。**版の確定はリリース時のオーナー判断**。

### 4-4. 決済事業者側 redaction の記録

**結論**: **記録列 1 本 + 記録コマンド 1 本 + runbook** を本タスク (PR-A) に入れる。
**Stripe API は呼ばない / 自動化しない**。

- 標準形 (1) の必須範囲は「退会経路から事業者 API を呼ばない原則の**機械化**」+「redaction の**記録**/運用手順」。
  台帳は laravel-claude-template セルに対して「**docs へ明記しただけを実装とは呼ばない**」と判定しており、
  runbook だけで済ませるとその判定が aicue にも降りてくる。
- 記録が無いと「redact 済みか」が事後に決定不能になり、二重実施と実施漏れを区別できない。列 1 本で足りる。
- 一次情報 URL: `docs/architecture.md` 自身が「台帳側に一次情報の URL が pin されていない。
  数値を運用に効かせる前に一次情報を引き直せ」と書いている。runbook 化するときに引き直し、
  **URL と確認日をセットで**書く。引けなければ「未 pin」と明記して数値を運用に効かせない。

### 4-5. 依存閉包の静的 gate

**結論**: **入れる** (`tests/Architecture/AccountDeletionPathGateTest.php`)。

- 現状の behavioral 2 本は「その経路で今日呼ばれなかった」しか言えない。**新しい依存を注入した瞬間に沈黙する**
  (実際、laravel-claude-template の実装レビューで「依存閉包の抽出が型宣言だけの注入を素通りさせていた」
  fail-open が見つかっている)。
- 書式は `tests/Architecture/CachePayloadPlainDataGateTest.php` に倣う: `PhpToken::tokenize` で解析
  (regex だと**この説明コメント自身**で偽赤になる)、冒頭 docblock に「保証するもの / 保証しないもの」、
  **空振り検知・自己参照コントロール・正負 fixture** を必ず同梱する。
- **母集団は exact-fit の目録**にする (deny-by-default)。退会経路の起点は
  `AccountController::destroy` / `OrganizationMembershipService::deleteAccount` /
  `PurgeAccountDeletionRequestsCommand::handle` の 3 つで、そこから到達する app/ 内クラスを閉包として辿る。
  免除は型付き enum (`DeletionPathSeamExemption`) + **30 文字以上の根拠**。

## 5. 実装方針 (概要)

| # | 施策 | 主な変更 | PR |
|---|---|---|---|
| A1 | 退会経路の依存閉包 gate | `tests/Architecture/AccountDeletionPathGateTest.php` (新) + fixture | A |
| A2 | redaction 記録列とコマンド | `organizations.stripe_customer_redacted_at` (migration) / `billing:mark-stripe-customer-redacted` | A |
| A3 | runbook | `docs/account-deletion-runbook.md` (新) | A |
| B1 | 予約列 | `users.deletion_requested_at` / `users.deletion_purge_after` (migration) + `User` casts | B |
| B2 | 予約 / 取消 | `OrganizationMembershipService::requestAccountDeletion()` / `cancelAccountDeletion()` + `AccountDeletionRequestController` + route 2 本 | B |
| B3 | 凍結 middleware | `EnsureAccountNotPendingDeletion` + alias + priority list + group 付与 | B |
| B4 | 日次執行 | `account:purge-deletion-requests` + `routes/console.php` (`daily()->onOneServer()`) | B |
| B5 | UI | `ProfileController` props / `Settings/Index.svelte` / `types/account.ts` | B |
| B6 | 監査・通知 | `SecurityEventType` 2 case + アプリ内通知 1 本 | B |
| C1 | 保持年数の単一出典 | `config/legal.php` + `App\Support\Legal\BillingRetention` | C |
| C2 | 規約文面 (草案) | `resources/views/legal/privacy.blade.php` | C |
| C3 | purge | `billing:purge-retention-expired` (dry-run 既定) + `routes/console.php` | C |
| C4 | 三者一致 gate | `tests/Architecture/BillingRetentionSingleSourceTest.php` (新) | C |

既存 gate への登録 (どれも「登録しないと赤くなる」deny-by-default):
`RecentAuthRouteTest` allowlist / `ControllerAuthorizationGateTest` (selfScoped) /
`ThrottleCoverageInventoryTest` (認証面の変更系 2 本のレーン割当。**inline throttle は T125 で使用不可**) /
`MembershipWriteLockInventoryTest` (directLock 2 メソッド) / `TenantBoundaryOrderingTest` (middleware 順序) /
`SecurityEventCoverageTest` (新 case 2 つ) / `ModelDirectFetchInvariantTest` + `DirectFetchInventory`
(バッチが主キー同一性クエリを書く場合) / `JobExecutionDedupInventoryTest` (ShouldQueue を足す場合)。

## 6. 分割の判断 (1 PR にしない根拠)

**3 PR に分割し、A → B → C の順に直列で main へ入れる**。

- **分割の根拠**: 変更面が migration 3 / middleware 1 / route 2 / command 3 / Architecture gate 3 /
  既存 gate 更新 8 に及ぶ。1 PR にすると Codex 実装レビューの粒度が粗くなり、
  「gate はあるが守っていない」型の fail-open (laravel-claude-template で 2 件見つかった種類) を見落とす。
- **依存順の根拠**: B の執行バッチは A の依存閉包 gate の母集団に入る。A を先に入れれば
  B は目録へ 1 行足すだけで済み、逆順だと A で母集団設計をやり直すことになる。
  C は A/B と独立だが、`routes/console.php` と `docs/architecture.md` を 3 PR とも触るため、
  並行 worktree はコンフリクトを生む。直列にする。
- **中途半端が残らないことの説明**:
  - **A 単独で完結する**: 既存の即時削除経路に対する gate と記録口であり、A の後の main は
    「原則が機械で守られ、redaction を記録できる」状態。猶予機能が無いことは A の欠落ではない。
  - **B 単独で完結する**: 予約 → 取消 → 執行の一巡が閉じており、UI から到達できる。
    保持期間が無いことは B の欠落ではない (退会と保持期間は別の問い)。
  - **C 単独で完結する**: 規約の宣言 (草案) と実処理が対応づいた状態。
  - どの PR も **feature flag / 後方互換の並走を残さない** (思考原則 3)。
- **台帳への報告は C マージ後に 1 回**。A/B だけで `implemented` を主張しない (v1 は 3 点込み)。

## 7. 制約・前提

- **オーナー決定 (§0) は前提**。値の再議論をしない。
- **`consent_version` を動かさない**。文面追記は**法務レビュー前の草案**であり、
  設計・実装・runbook・PR 説明の 4 箇所に草案である旨を書く。
- **AGENTS.md の不変条件**: 不変条件 10 (層 2 は 404 が先) / 運用要件 route:cache /
  ドメイン規約 4 (課金ゲート group) / ドメイン規約 11 (キュー投入は tx 内。
  **spirux の申し送り「通知を afterCommit で外へ出せ」は aicue の規約と逆なので採らない**)。
- **PHPStan level 10 / Pest / RefreshDatabase グローバル / Factory 必須 / DTO + JsonResource**。
- 組織削除の route は aicue に存在しないため、spirux が塞いだ「両扉」問題は発生しない
  (実査で確認済み)。組織削除を新設する PR が来たら、そのとき同じ判定を通す。

## 8. スコープ外

- **`consent_version` の版上げ**と法務レビュー (リリース時のオーナー判断)。
- **退会予約のメール通知**。予約は `recent-auth` (step-up) 必須で本人の能動操作であり、
  30 日の猶予・画面バナー・アプリ内通知・SecurityEvent 記録で誤操作救済は成立する。
  メール配送は別の失敗モード (配送遅延・到達不能) を持ち込むため今回は作らない (思考原則 2)。
  **乗っ取り起点の予約に本人が気づく手段はメールしかない**という反論は成立しうるので、
  この判断は Codex 合議に諮る (§Codex への論点)。
- **Stripe redaction の自動化** (人手 + 記録に留める)。
- **組織単位の削除・組織の保持期間**。aicue に組織削除の route が無い。
- **inquiries / アクセスログの保持期間**。`inquiry_retention_days` が既にあり、別 feature。

## 9. Codex への論点 (合議で潰したい)

1. 凍結範囲を業務 group 限定にしたことで、**予約中に到達できないと詰む経路**を見落としていないか。
2. 執行時にブロッカーが立っていた場合の「予約維持 + report()」で、**永久凍結**が発生しないか。
3. 保持期間の三者一致を「単一出典化」で置いたことの穴 (節ごと消える / マーカーだけ残る)。
4. メール通知をスコープ外にした判断の是非 (乗っ取り起点の予約)。
5. 3 分割の依存順と、各 PR 単独で main が壊れないことの検証。

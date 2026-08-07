# 概念設計レビュー依頼 (aicue / invitation-in-app-acceptance)

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【この設計の位置づけ (レビュー時に前提としてよい事実)】
- 複数リポジトリ共有の機能台帳 (lctl) の裁定 AG-113 が「標準形 v1」として本経路の還流を決定済み。
  必須 3 点 (存在秘匿の一律 404 / 受諾解決と件数・一覧で同一絞り込みを再利用 / 未ログイン・未 verified・email 空は DB を引かない) は
  リポジトリ側の裁量ではない。
- 同じ裁定系統 AG-079 が「aicue の役割付き招待 (organization_invitations.project_role) を実装ごと撤去する」を
  オーナー判断として確定済み。撤去と受諾入口の追加は同じ 2 ファイル
  (app/Models/OrganizationInvitation.php / OrganizationMembershipService::joinOrganization) を共有するため、
  同一作業単位にまとめる方針。
- 実コードは調査済み。本文中の「現状」記述はすべて実ファイルを読んで確認したもの。

---

## 概念設計

# 概念設計: invitation-in-app-acceptance

> lctl 台帳 feature: `auth-invitation-in-app-discovery` (裁定 AG-113 / 標準形 v1) +
> `auth-invitation-flow` (裁定 AG-079 の aicue セル: 役割付き招待の撤去)。
> 実査ブリーフ: `devnotes/20260807-2032-invitation-in-app-acceptance/recon-brief.md`

## 背景・課題

### (1) 発見面が行き止まりになっている

aicue には既に「招待が届いたことをアプリ内通知で知らせる」発見面がある
(`app/Notifications/InApp/InvitationReceivedNotification.php` +
`app/Services/Notification/NotificationCenterService::notifyInvitationReceived()`)。
しかし受諾の入口は**署名なし token URL 経路 1 本だけ**で、
`InvitationAcceptanceController` のクラス docblock も
「招待 email とログインユーザーの email の一致はログイン後経路では要求しない仕様」と書いている。

その結果、通知をクリックしたユーザーは
`NotificationController::open()` の `NotificationType::InvitationReceived` 分岐によって
`notifications.index` へ 303 で戻され、
`info` flash「招待はメールの受諾リンクから参加してください。」を見せられる。
**アプリ内で招待の存在を知ったのに、アプリ内では 1 歩も進めない**。
ユーザーはここでメールクライアントを開き、迷惑メールフォルダを探し、
期限切れなら再送を依頼する — という「アプリの外での作業」を強制される。

台帳の裁定 (AG-113) はこの切れ目を家系の標準形として埋めることを決めており、
aicue に対しては「**既存のアプリ内通知は捨てない。足りないのは受諾の入口だけ**」と明示している。

### (2) 受諾の根拠が「URL の所持」だけであること

現行の受諾根拠は「署名なし token URL を持っていること」である。
token は sha256 hash-only 保存で URL 推測は現実的でないが、根拠が所持である以上

- 招待メールが転送されれば **別人が踏める** (ログイン後経路は email 一致を要求しない仕様)
- 迷惑メールに振り分けられれば **本人が踏めない**
- 期限切れなら **誰も踏めない**

の 3 つが構造的に残る。標準形 v1 は、この 3 つに強い第 2 の入口
(根拠 = `auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先`) を
**既存 token 経路と置き換えずに並べて持つ**ことを求めている
(未登録の人にはメールが唯一の入口であり続けるため)。

### (3) 役割付き招待 (`project_role`) が撤去待ちのまま同じファイルを占めている

同じ裁定系統の AG-079 が
「aicue の役割付き招待 (`organization_invitations.project_role` と
Default Project への紐付け) を実装ごと撤去する」
(オーナー判断: **Default Project という概念自体が不要**) を確定させており、aicue は未着手。
撤去対象は `app/Models/OrganizationInvitation.php` の cast と
`OrganizationMembershipService::joinOrganization()` の pivot attach で、
**(1)(2) の受諾入口が触るファイルとまるごと重なる**。
入口だけ先に足すと、直後に同じ 2 ファイルを撤去側で書き換えることになる
(前回セッションの handover が台帳へ申し送り済み)。

## 改善アイデア

**A. アプリ内からの受諾経路を 1 本足す (標準形 v1)**

- `POST /invitations/{invitation}/accept-in-app` (`invitations.accept-in-app`)。
  middleware は `auth` + `verified` + throttle。`{invitation}` は **implicit binding させず**
  controller が「認証ユーザー宛の有効 pending 集合」から手動解決する。
- 解決口は `OrganizationInvitation::scopeActivePendingForEmail()` **1 つだけ**。
  `scopeActive()` (未受諾・未失効・期限内) + `whereBlind('email','email_index',$email)` +
  `whereHas('organization')` (Organization は SoftDeletes のため削除済み組織宛が自動で落ちる)。
- 宛先不一致 / 不在 id / 期限切れ / 取消済 / 受諾済 / 削除済み組織宛は**一律 404**。403 を返さない。
- 招待行 → membership の変換は既存 `joinOrganization()` (lock 付き) を**そのまま共有**する
  (署名経路との振る舞いのすれを作らない)。

**B. 既存のアプリ内通知を「発見面」として活かし、受諾へ到達させる**

- 通知 payload には**招待 id を持たせない** (論点の結論。根拠は後述「制約・前提」)。
- `NotificationController::open()` の `InvitationReceived` 分岐は
  引き続き `notifications.index` へ 303 するが、
  その `Notifications/Index` に「あなた宛に届いている招待」セクション (受諾ボタン付き) を出す。
  一覧の算出は A と**同一 scope** を再利用する。
- 全画面横断の気づきは共有 prop `invitations.pendingCount` (件数のみ) と
  最小の誘導 notice で担う。未ログイン・未 verified・email 空は**DB を引かない**。

**C. 役割付き招待 (`project_role`) を実装ごと撤去する (AG-079)**

- `organization_invitations.project_role` 列と check 制約を drop。
- 招待のロール語彙を **org ロール 2 値 (管理者 / メンバー)** に落とす
  (`AdminConsoleRole` の 3 値コマンドは**既存メンバーのロール変更**の語彙として存続)。
- 受諾時の Default Project pivot attach を削除。参加後のロール割当は
  既存の `applyConsoleRole` (ユーザー管理画面の「未割当」修復導線) が担う。
- 「編集者を招待するには先にプロジェクトを作れ」という招待時の前提検査も消える。

**D. 不変条件を目録型 gate で固定する**

`OrganizationInvitation` のクエリ起点を deny-by-default で分類させる Architecture テストを新設し、
「**受信者視点の解決は必ず `activePendingForEmail` 経由**」を機械強制する
(標準形 (b)「受諾の解決と件数/一覧の算出で同一の絞り込みを再利用」の drift 封じ)。

## 期待効果

- **使命への貢献**: AI-CUE の使命は「現場作業者が標準化されたマニュアル動画を作れる」ことにあり、
  その前提は**現場の人が組織に入れている**こと。招待受諾はオンボーディングの最初の関門で、
  現状はそこがアプリの外 (メール探し) に落ちている。
  スマホ (PWA) 中心の現場ユーザーにとって「メールを探して URL を踏む」は特に高い障壁であり、
  アプリ内で完結させる価値は大きい。
- **明確な UX の詰みの解消**: 「通知は届くが受諾できない」= 発見面が行き止まり、を消す。
  メール転送・迷惑メール振り分け・期限切れ再送依頼の 3 失敗モードに対して
  第 2 の経路が保険になる。
- **セキュリティの前進**: 新経路は「宛先本人であること」を根拠にするため、
  転送された URL を第三者が踏む既存経路より根拠が強い。
  存在秘匿 (一律 404) を最初から設計に入れるので、新しい存在オラクルを作らない。
- **不要実装の撤去**: オーナーが不要と裁定した `project_role` を注記付きで残さず消す
  (思考原則 3「後方互換の並走を残さない」)。招待の意味が
  「組織に入れる」だけに収斂し、Default Project の存在有無に招待が依存しなくなる。

## 実装方針（概要）

| # | 施策 | 主な変更 |
|---|------|---------|
| 1 | 受信者視点の単一解決口 | `OrganizationInvitation::scopeActivePendingForEmail()` 新設 |
| 2 | 受信者視点 DTO | `PendingInvitationForUserDto` 新設 (開示項目の契約) |
| 3 | 受諾サービス | `OrganizationMembershipService` に read 3 本 + 受諾 1 本。`joinOrganization()` は `bool` を返すよう変更 (ロック下再検証の結果を呼び出し側へ返す) |
| 4 | 受諾 route / Controller | `AcceptInvitationInAppController` + route + gate 4 箇所登録 + throttle |
| 5 | 発見面 → 受諾の導線 | `NotificationController` (index prop / open 分岐)、`Notifications/Index.svelte`、`NotificationListItem.svelte` 文言、`features/invitations/` の新 component |
| 6 | 横断の気づき | 共有 prop `invitations.pendingCount` + `PendingInvitationsNotice` molecule + `AppLayout` |
| 7 | `project_role` 撤去 | migration / Model / Service / FormRequest / `InvitationRowData` / Factory / `Admin/Users.svelte` / `types/admin.ts` / docs |
| 8 | 目録型 gate | `InvitationResolutionInventoryTest` + `InvitationResolutionScope` enum |

### 存在秘匿の作り方 (核心)

1. **解決を 1 本に絞る**: 受信者視点で招待に触るコードは
   `activePendingForEmail` 経由の query しか作らない。
   したがって「他人宛」「不在」「期限切れ」「取消」「受諾済」「削除済み組織宛」は
   **すべて同じ「0 件」** に collapse し、controller は `abort(404)` 1 行で畳める
   (分岐が無いので、将来理由を足しても情報が漏れる余地が構造的に無い)。
2. **binding 段で解決させない**: `{invitation}` を implicit binding させると
   不在 id だけが binding 段で 404 になり、実在の他人宛 id は後段へ進んで別応答になる
   = 1 bit の存在オラクル。controller の action 引数を `string` にして
   `NestedRouteDefenseMode::ManualOwnerScopedResolution` として登録する
   (`TenantBoundaryOrderingTest` 検査 3a が action 引数型・`MANUALLY_RESOLVED` 登録・
   explicit binder 不在を機械検証する)。
3. **受諾側を total にする**: 受諾メソッドは例外を投げず `?Organization` を返す
   (`null` = 受諾できなかった)。ロック下再検証で負けた場合も `null` → 404。
   `catch (Throwable)` で握り潰す形は採らない (インフラ障害を 404 に化けさせない)。

### 「未ログイン・未 verified・email 空は DB を引かない」の担保

サービス内の private な query builder factory 1 箇所に guard を置き、
一覧・件数・受諾解決の 3 経路すべてがそこを通る:

```
private function pendingInvitationsQuery(?User $user): ?Builder
    → $user === null / ! hasVerifiedEmail() / email === '' なら null を返す (クエリを作らない)
```

共有 prop は closure で遅延評価し、`$user` が無ければ即 `0` を返す。

## 制約・前提

### 最初に決める論点への結論: 通知 payload に招待 id を**持たせない**

| | 持たせる | 持たせない (採用) |
|---|---|---|
| 波及 | `InAppNotificationTypeInvariantTest` / `NotificationTypeTsSyncInvariantTest` / `NotificationSchemaTest` / `resources/js/types/notification.ts` の同期が連鎖 | 通知の型定義は無改造 (本文の文言のみ) |
| stale 通知 | 取消・期限切れ・受諾済みの後の通知から deep link すると必ず 404 = **また行き止まり** | 通知は「有効な招待の live な一覧」へ運ぶだけなので、無効化されていれば一覧が空 = 状態がそのまま見える |
| 標準形 (b) | 発見の入口が scope を迂回するため、drift の余地が増える | 一覧・件数・受諾のすべてが同一 scope に載る |

決定根拠は AGENTS.md 思考原則 2「今必要なものだけ作る」。
招待 id を payload に載せても**得られる能力は「1 クリック短縮」だけ**で、
代わりに 4 つの型同期 gate と stale 畳み込み設計を抱える。
しかも stale 通知からの deep link は 404 に落ちるため、
「発見したのに進めない」という本件の課題そのものを再生産する。
一覧へ運ぶ形なら、無効化された招待は「一覧に無い」という形で自然に説明でき、
`open()` は同じ scope の件数を見て
「この招待は現在有効ではありません」の flash を出すところまで一貫させられる。

### 既存を捨てない / 並べて持つ

- 署名 token 経路 (`invitations.accept` / `invitations.accept.store`) は**変更しない**。
  未登録者にはメールが唯一の入口であり続ける。
- アプリ内通知 (`InvitationReceivedNotification`) は発見面として維持する。裁定どおり。
- 変換本体 `joinOrganization()` は 1 本を共有する (戻り値の型だけ広げる)。

### 新経路だけ `verified` を要求する非対称

既存 `invitations.accept.store` は「招待された直後の未検証ユーザーも受諾できる」ことを意図して
`verified` を要求していない。新経路は受諾根拠そのものが「email 確認済み」なので `verified` 必須。
**この非対称は仕様である**ため、`docs/architecture.md` に理由を明記する
(片方だけ見て「不整合」と直されるのを防ぐ)。

### aicue 固有の前提 (標準形との差分)

- **席上限が無い**。`config/quota.php` の `max_members` は「現在強制されていない」と
  config 自身がコメントしており `QuotaService::check` の呼び出し元も無い。
  したがって標準形が言う「席満杯は flash 付き redirect」分岐は aicue では発生せず、
  **既定の 404 畳み込みだけを実装する** (使わない分岐を先回りで作らない)。
- **email の blind index に Lowercase transformer が無い** (`User` の `name` にはある)。
  よって宛先照合は**大小文字を区別する完全一致**である。
  これは既存 `acceptInvitationIfValid` の `$invitation->email !== $user->email` および
  `MatchesInvitationEmail` と同じ意味論で、**過剰一致は起きない** (fail-secure 側に倒れる)。
  大小差のある宛先は 404 になり、既存のメール経路で受諾できる。本件では変えない。
- 招待継続 (未ログインで招待リンクを踏んだときの session 保持) は
  台帳では「aicue に不在」とされているが**実在する** (`SessionInvitationToken` クラスが無いだけで、
  `InvitationAcceptanceController::show()` → session `invitation_token` →
  `CreateNewUser` / `MatchesInvitationEmail` / `resolveRegisterPrefillEmail` が fail-secure に消費)。
  本件では触らず、台帳側の観測を是正する申し送りを残す。

### gate の登録先 (deny-by-default のため必須)

| gate | 登録内容 |
|---|---|
| `tests/Support/Routing/NestedRouteDefenseInventory.php` | `invitations.accept-in-app` => `['invitation' => ManualOwnerScopedResolution]` |
| `app/Http/Routing/RouteBindingTypes.php` | `MANUALLY_RESOLVED['invitation']` に route 名 + 理由 |
| `tests/Architecture/ControllerAuthorizationGateTest.php` | `SelfScopedResource` + 30 字以上の理由 |
| `tests/Architecture/MembershipWriteLockInventoryTest.php` | 新 public メソッドを `delegatedToLocked` / `exempt` へ |
| `tests/Architecture/ThrottleCoverageInventoryTest.php` | route 名が `invitations.` 始まり = S3。exemption 枠は exact-fit (cap=25) のため**throttle を貼る**以外の選択肢は無い |

### `project_role` 撤去に伴う仕様変更 (受け入れる後退)

招待時に「編集者 / 撮影者」を指定できなくなり、招待は「管理者 / メンバー」の 2 値になる。
参加後に管理画面 (`/manage/users`) で「未割当」として可視化され、
既存のロール割当コマンドで編集者 / 撮影者へ遷移させる **2 段の運用**になる。
これはオーナー裁定 (Default Project 概念そのものが不要) の帰結であり、
3 値のまま「選べるが効かない」UI を残す方が有害 (思考原則 3)。

## スコープ外

- **Default Project 概念そのものの撤去**。`DefaultProjectResolver` は
  dashboard / 撮影 PWA の `capture.home` / ユーザー管理画面が現に使っており、
  `applyConsoleRole` の pivot 経路も生きている。AG-079 の最終形ではあるが、
  本作業単位は「招待が `project_role` を持つのをやめる」までに限る
  (招待と結合している部分だけを外し、残りは別 TODO)。
- **署名 token 経路の変更**。GET/POST `invitations.accept*` と
  `Invitations/Accept` / `Invitations/Invalid` 画面は一切触らない。
- **招待の管理者視点の面** (`Admin/Users` の招待一覧・取消)。
  `project_role` 撤去に伴う表示語彙の変更のみで、機能は変えない。
- **席上限 (`SeatAvailabilityService` 相当) の導入**。aicue に存在せず、本件で作らない。
- **通知 payload の拡張** (招待 id / 平文 token)。上記の論点で不採用。
- **Browser (Playwright) テストの新規追加**。受諾は Feature テストで固定でき、
  bfcache/履歴復元のような実機依存の不変条件を新たに導入しないため。
- **email blind index の Lowercase transformer 化**。既存の全 email 照合経路
  (`whereBlind` の呼び出し元すべて) と blind index の再計算を伴う別作業。

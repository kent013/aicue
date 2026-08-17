## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

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

【この設計に特有の、重点的に判断してほしい論点】
- **中心判断の妥当性**: `users.last_login_at` カラムを新設せず、既存の監査表 `security_audit_events` の
  `login` 行から導出する判断は正しいか。逆 (カラムを足す) の方が良い決定的な理由があるなら述べよ。
- **保持期間との結合**: 導出方式は監査表の保持期間 (現在「未確定」= purger 無し) に暗黙依存する。
  §2-4 の決着 (今は作らない + 台帳の根拠文に依存を明記) は十分か、それとも今すぐ別の手当てが要るか。
- **数え方の網羅性**: §3 の経路表に数え落とし・数え過ぎがないか。とくに remember me を数える判断
  (既存 listener StampRecentAuthOnLogin とは逆の扱いにする) の根拠は妥当か。
- **認可とプライバシー**: §4-2 の「既存の画面到達境界 (owner/admin) をそのまま使う」判断は妥当か。
  行動情報を識別情報と同じ境界に置くことに問題はないか。
- **索引の置き換え**: §5-2 で既存索引 ['user_id','event_type'] を ['user_id','event_type','occurred_at'] へ
  置き換える判断は、今必要なものだけ作る原則に照らして妥当か (過剰か、あるいは不足か)。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: user-last-login-at (最終ログイン日時の記録と表示)

## 0. ブリーフの前提の検証 (現行コードを読んだ結果)

本設計は与えられたブリーフをそのまま受けず、現行コードを読んで前提を検証した。
検証結果を先に置く (食い違いは訂正してから進む)。

| ブリーフの記述 | 検証結果 | 根拠 (実読したファイル) |
|---|---|---|
| `users` に最終ログイン日時のカラムが無い | **正しい**。`last_login` / `lastLogin` / `last_seen` は app/ resources/ database/ config/ tests/ routes/ doc/ docs/ に 1 件も無い | `database/migrations/0001_01_01_000000_create_users_table.php` / `2026_08_10_000200_add_deletion_request_columns_to_users_table.php` / `app/Models/User.php` |
| 画面に出ていない | **正しい**。`MemberRowData` は 7 フィールドで日時を 1 つも持たない | `app/DataTransferObjects/Admin/MemberRowData.php` / `resources/js/types/admin.ts` / `resources/js/pages/Admin/Users.svelte` |
| 「1. 記録する。Fortify のログイン成功イベントを購読して更新する形が素直かを確認する」 | **前提が古い。記録は既に存在する**。`Illuminate\Auth\Events\Login` は `RecordSecurityEvent` が既に購読しており、`security_audit_events` に `event_type='login'` の行が**ログインのたびに**書かれている。索引 `['user_id','event_type']` もある | `app/Listeners/RecordSecurityEvent.php` L33/L44-49 / `app/Providers/AppServiceProvider.php` L214 / `database/migrations/2026_06_11_071300_create_security_audit_events_table.php` |
| 「2. 書き込みの頻度を検討する / セッション継続中は毎リクエスト更新しない」 | **問い自体が既に解けている**。`Login` イベントは**セッション確立時にしか発火しない**。セッション継続中の通常リクエストでは 1 行も書かれない | `vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php` L573 (`login()`) / L197-202 (recaller) |
| 「4. 既存ユーザーの扱い (backfill するか null のままか)」 | **カラムを足さない結論なので backfill という操作自体が発生しない**。既存ユーザーは既存の監査行がそのまま値になる (2026-06 の表作成以降の login はすべて記録済み) | 同上 |

> **台帳を根拠にしない**: `docs/TODO-closed.md` は参照していない。上の判定はすべて実ファイルの実読による。

**帰結**: 本タスクの実体は「**記録の新設**」ではなく「**既にある記録を、認可境界を保ったまま 1 か所に見せる**」である。
以降はこの訂正後の課題設定で設計する。

---

## 1. 背景・課題

`doc/02 §2.4` のユーザーエンティティは `作成日時` / `最終ログイン日時` を持つ。
実装は前者 (`users.created_at`) を持ち、後者を**画面に出していない**。

T201 (ユーザー登録方式の見送り) の詳細設計は、適格条件 A で
「**『誰がいつ入ったか』は最終ログイン表示へ**」と要求の受け皿を明示的にここへ割り当てている
(`devnotes/20260817-0003-user-provisioning-model-divergence/detailed-design.md` §5 / §6-5)。
つまりこの項目は「あったら便利」ではなく、**別タスクの結論が依存している受け皿**である。

満たしたい運用上の問い:

- 招待したのに一度も入っていないメンバーがいないか (オンボーディング不全の検出)
- 長期間入っていない在籍者がいないか (休眠アカウントの棚卸し = 席数と権限の整理)

---

## 2. 中心的な判断: 新しいカラムを足さない (既存の記録で足りる)

AGENTS.md 思考原則 2 (今必要なものだけ作る) と 4 (別物の概念を似ているからで統合しない) の**両方**を
適用した結果、**`users.last_login_at` カラムは新設しない**。理由を順に述べる。

### 2-1. 既存の仕組みで「足りるか」を先に読んだ

既存の記録機構は 3 つあり、それぞれ別概念である。混同しないために先に切り分ける。

| 機構 | 何を持つか | 本件との関係 |
|---|---|---|
| `ModelAudit` | **モデル属性の変更差分** (誰が何をどう書き換えたか)。`RejectNonCriticalAudit` が対象を絞る | **別概念**。ログインは属性変更ではないので 1 行も出ない。**流用不可** |
| `security_audit_events` (`SecurityAuditEvent`) | **認証・権限に関わる出来事の証跡**。`login` / `login_failed` / `logout` / … の固定集合 (`SecurityEventType`) | **ここに `login` が既にある**。本件の唯一の記録点 |
| `users` の列 | 利用者の**現在状態** | 最終ログインは現在状態でもあるが、下の 2-2 の理由で列にしない |

`ModelAudit` が使えないことは確認済み (別概念)。`security_audit_events` は**使える**。

### 2-2. カラムを足すと「同じ事実の記録点が 2 つ」になる

`security_audit_events.event_type='login'` の行と `users.last_login_at` は、
**まったく同じ事実 (このユーザーのセッションがいつ確立したか) を 2 か所に書く**ことになる。
本リポジトリは「2 か所に書くと必ず食い違う」を規約本文で繰り返し禁じている
(AGENTS.md の採番注意 / 常設 hook 配線 / テンプレート逸脱台帳の各節)。

食い違いは具体的に起きる。列を足すと、以下がすべて**列側だけを更新し忘れる**経路になる:

- 将来 `Auth::login()` を呼ぶ新しい経路が増えたとき (監査は listener 1 本で自動的に拾うが、列の更新も同じ listener に相乗りさせない限り漏れる)
- 監査の書き込みが失敗したとき / 列の書き込みが失敗したとき (両者は別トランザクション・別 best-effort)

**記録点を 1 つに保つ**のが、この 2 つを同時に消す唯一の形である。

### 2-3. 書き込み負荷の観点でも導出が有利

カラム方式は「ログインのたびに `users` へ UPDATE」= 書き込みが増える。
導出方式は**書き込みが 1 バイトも増えない** (既に書かれている行を読むだけ)。
ブリーフの検討課題 2 (書き込み頻度・セッション継続中の扱い) は、導出方式では**発生しない**。

### 2-4. カラム方式に有利な唯一の論点と、その決着

唯一の実質的な反論は「`security_audit_events` の**保持期間が未確定**であり
(`tests/Support/Retention/RetentionTableRegistry.php` の `undecided` 区分)、
将来 purge が入ると**古いログインほど先に消える**」である。
休眠検出は「古い値」が最も価値を持つ用途なので、この反論は本質的である。

決着:

1. **今は purger が存在しない** (undecided = 掃除バッチが無い)。存在しない将来のために
   状態を二重化するのは思考原則 2 に反する。
2. purge の導入は**無音では起きない**。`RetentionTableClassificationTest` が
   区分と根拠の記述を deny-by-default で要求するため、`security_audit_events` を
   undecided から動かす瞬間に必ず人間のレビューを通る。
3. その瞬間に本機能への影響を考えさせるため、**本設計の成果物として
   RetentionTableRegistry の当該 entry の根拠文に「この表の login 行が
   /manage/users の最終ログイン表示の唯一の出所である」ことを追記する**
   (区分は undecided のまま変えない)。これで依存が台帳上に可視化される。
4. 表示の文言も、この制約と整合させる (§4-3)。

**結論: `users.last_login_at` を足さない。migration は列を 1 本も足さない。**

---

## 3. 何を「ログイン」と数えるか (経路ごとの明示)

導出元は `security_audit_events` の `login` 行 = `Illuminate\Auth\Events\Login` の発火である。
その発火集合を実コードで確定した (推測ではなく `SessionGuard` と各呼び出し元の実読)。

| 経路 | `Login` 発火 | 数えるか | 根拠 |
|---|---|---|---|
| パスワード (Fortify `AuthenticatedSessionController`) | する | **数える** | `SessionGuard::login()` → `fireLoginEvent()` |
| 2FA チャレンジ完了 (TOTP / リカバリコード) | する | **数える** | 2FA 完了時に初めて `login()` が呼ばれる |
| 2FA 待ちで止まった状態 (パスワードだけ通過) | **しない** | **数えない** | セッションが確立していない = まだ入っていない。数えたら「入れていないのに最終ログインが更新される」ことになる |
| パスキー (WebAuthn) | する | **数える** | `PasskeyLoginController::store()` の `$guard->login()` |
| SSO (Socialite) | する | **数える** | `app/Http/Controllers/Auth/SocialAuthController.php` L111 / L139 の `Auth::login(..., remember: true)` |
| remember me による自動ログイン復元 | する (`viaRemember=true`) | **数える** | `SessionGuard::user()` L197-202 が `fireLoginEvent($user, true)` を呼ぶ |
| 新規登録直後の自動ログイン | する | **数える** | Fortify の登録後 login |
| 招待受諾 | 受諾自体は発火しない | **数えない (数える必要が無い)** | `InvitationAcceptanceController::store` は auth 必須 = **既にログイン済み**。未ログインは register へ誘導され、そこで登録の login が数えられる |
| local 限定デバッグログイン | する | 数える (実害なし) | `DebugLoginController` は `LocalOnly` middleware の背後。production に存在しない |
| Filament 管理画面 (`admin` guard) | する | **数えない** | `$event->user` は `AdminUser` であり `User` ではない。`RecordSecurityEvent::asUser()` が `null` に丸めるため `user_id` が付かず、利用者の行には**構造的に**混ざらない |
| API キー (`api-key` guard) | しない | **数えない** | 機械アクセスであって人が入った事実ではない。`ApiKeyGuard` は `Login` を発火しない (実読で確認)。API キーの活動は `api_keys.last_used_at` が別に持つ |
| OAuth トークン (`mcp-oauth` / `api-oauth`) | しない | **数えない** | 同上。`oauth_sessions.last_used_at` が別に持つ |
| セッション継続中の通常リクエスト | しない | **数えない** | 「最終ログイン」はセッション確立の時刻であり最終活動時刻ではない (§3-2) |

### 3-1. remember me を数える判断の根拠 (既存 listener と意図的に違える)

`app/Listeners/Auth/StampRecentAuthOnLogin.php` は `viaRemember()` が true の Login を
**除外**している。本件は**除外しない**。理由は問いが違うからである。

- `StampRecentAuthOnLogin` の問い: 「**たった今、資格情報を提示したか**」
  (機微操作の step-up を免除してよいか)。cookie の自動復元は資格情報の提示ではないので除外が正しい。
- 本件の問い: 「**この人は最後にいつこのシステムに入ったか**」
  (休眠の検出)。cookie で自動的に入ったのも「入った」である。除外したら
  「毎日使っているのに半年前から未ログインに見える人」が生まれ、機能の名前が果たすべき役割を裏切る。

**同じ `Login` イベントを、2 つの機能が別の条件で読む**。これは統合してはいけない 2 概念である
(思考原則 4)。設計上は「除外しない」ことを明示的な決定として記録する。

### 3-2. 「最終ログイン」であって「最終活動」ではない

remember me を使う利用者は、cookie の寿命の間ログイン行が増えない。
よって値は「最終活動日時」より古くなりうる。**これは仕様である** (doc/02 の項目名は `最終ログイン日時`)。
最終活動を出す機能は作らない (要求されていない = 思考原則 2)。表示文言もこの区別を守る (§4-3)。

### 3-3. 将来の見直し条件 (トリップワイヤ)

`users` プロバイダを持つ**セッション系 guard を新設**するか、
`loginUsingId` / impersonation / magic-link を web guard に足したときは、
本設計の数え方を読み直すこと (`StampRecentAuthOnLogin` の ⚠ 注記と同じ性質の前提に立っている)。

---

## 4. 表示 (どこに、誰に、どう出すか)

### 4-1. 出す場所

`/manage/users` (`resources/js/pages/Admin/Users.svelte`) のメンバー一覧の各行。
既存の「名前 / メール / 2FA バッジ / 未割当バッジ」の情報ブロックに 1 行足す形にする
(操作列には入れない = 読み取り情報と操作を混ぜない)。

### 4-2. 誰に見せるか (認可)

**新しい認可境界を作らない。既存の画面到達境界をそのまま使う。**

- `UserManagementController::index` は `Gate::authorize('manageMembers', $organization)` を既に通す
  = **組織の owner / admin のみ** (編集者・撮影者・一般メンバーは 403)。
- 対象組織は `ResolvesCurrentOrganization` による**現在組織の解決のみ**で、
  URL に組織パラメータを持たない。したがって **cross-org 越境は構造的に不可能**
  (AGENTS.md セキュリティ不変条件 3)。
- メンバー集合も `$organization->users()` = relation 経由の org-scoped 解決であり、
  クラス起点の主キー同一性クエリを使わない (`ModelDirectFetchInvariantTest` の母集団に入らない)。
- 本変更は **GET のみ**で変更系 route を 1 本も足さない。よって
  `Gate::authorize` を要する変更系の追加は無い (不変条件 9 の母集団が増えない)。
- 行レベルの可視性分岐は**持たない**。この画面は既に全メンバーの氏名・メール (CipherSweet 復号値) を
  同じ境界で見せており、`MemberRowData` の doc comment がその契約を明記している。
  最終ログイン日時を同じ境界に置くのは、**新しい秘密を増やさない**という意味で整合する。
- **API / MCP / Filament には出さない**。`/manage/users` の Inertia props にのみ載せる
  (露出面を 1 つに閉じる)。

**プライバシー上の性格の明示**: 最終ログイン日時は「その人がいつシステムを使ったか」という
**行動に関する情報**であり、氏名・メールのような**識別情報**とは性格が違う。
本設計はこれを**同一の境界 (owner / admin) に置く**判断をする。根拠は、
この境界の保持者は既にメンバーの削除・ロール変更・2FA リセットという
**より強い権限**を持っており、休眠棚卸しはその職責の一部だからである。
より広い相手 (本人以外の一般メンバー) には出さない。

### 4-3. null / 記録なしの表示

導出方式では「一度もログインしていない」と「記録が残っていない」を**区別できない**
(将来 purge が入った場合。§2-4)。したがって断定しない文言を選ぶ。

- 値がある: `formatDateTime()` で `2026/08/17 09:09` 形式 (§4-4)。
- 値が無い: **`記録なし`**。
  - `未ログイン` とは書かない — 導出元が保持期間で消えうる以上、事実として断定できない。
  - 既存の `PasskeySection.svelte` が `last_used_at === null` を `未使用` と表示している流儀に沿う
    (単なる `-` ではなく意味のある日本語)。
- 「一度も入っていない人を見つける」用途は、実運用上は
  「`記録なし` の行 + `招待中` セクション」で読める。**専用の絞り込み UI は作らない** (思考原則 2)。

### 4-4. タイムゾーンと書式 (既存の作法に合わせる)

現行の作法を実読して確認した。**新しい流儀を作らない**。

- サーバ: `config/app.php` の `timezone` は `UTC`。DTO は `->toIso8601String()` で
  オフセット付き文字列を出す (`SecurityController` の `lastUsedAt` / `BillingController` の
  `current_period_end` / `NotificationListItemData` などが同一)。
- クライアント: `resources/js/lib/date-format.ts` の `formatDateTime()` を使う。
  `Intl.DateTimeFormat("ja-JP")` で**閲覧者の端末のタイムゾーン**に変換して表示する。
- `toDateTimeString()` (オフセット無し) は使わない — 端末で UTC が現地時刻として解釈され
  9 時間ずれるため。

---

## 5. 実装方針 (概要)

| # | 施策 | 変更ファイル |
|---|---|---|
| A | 最終ログインを 1 クエリで引く読み取り専用サービス | `app/Services/Security/LastLoginLookup.php` (新規) |
| B | props への追加 | `app/DataTransferObjects/Admin/MemberRowData.php` / `app/Http/Controllers/Admin/UserManagementController.php` |
| C | TS 型と画面 | `resources/js/types/admin.ts` / `resources/js/pages/Admin/Users.svelte` |
| D | 監査表の索引の置き換え | `database/migrations/{ts}_replace_security_audit_events_user_event_index.php` (新規。**列は足さない**) |
| E | テストデータ生成手段 | `database/factories/SecurityAuditEventFactory.php` (新規) + `docs/factories.md` |
| F | 保持期間台帳の根拠文に依存を明記 | `tests/Support/Retention/RetentionTableRegistry.php` (区分は変えない) |
| G | テスト | `tests/Feature/Admin/UserManagementPageTest.php` / `tests/js/pages/AdminUsers.test.ts` |

### 5-1. N+1 を作らない (施策 A)

メンバー行ごとに問い合わせない。**メンバー id の集合に対して 1 クエリ**で
`user_id => 最終 login 時刻` の写像を作り、`MemberRowData::fromUser` に渡す
(既存の `$pivotRoles` を 1 クエリで作っている流儀とまったく同じ形)。

想定クエリ (概念):

```
select user_id, max(occurred_at)
  from security_audit_events
 where user_id in (:memberIds) and event_type = 'login'
 group by user_id
```

### 5-2. 索引を 1 本に置き換える (施策 D)

現行の索引は `['user_id','event_type']` で、上のクエリの**絞り込みには効くが最大値の取得には効かない**。
`security_audit_events` は保持期間が未確定 = **単調増加が確定している**表であり、
1 利用者あたりの login 行は年単位で数千行に達しうる。

よって `['user_id','event_type','occurred_at']` へ**置き換える** (追加ではない)。
新索引は旧索引の**前方一致を完全に含む**ため、既存の利用箇所 (Filament の絞り込み等) は
そのまま効く。**旧索引は同じ migration で落とす** (AGENTS.md 思考原則 3: 並走を残さない)。

### 5-3. Factory (施策 E)

既存テストは `SecurityAuditEvent` を**読むだけ**で、生成手段を持たない
(実ログインを走らせて作っている)。本件は「3 か月前のログイン」のような
過去時刻の行が要るため `SecurityAuditEventFactory` を新設する
(AGENTS.md 実装規約: Factory の追加と `docs/factories.md` への追記は必須)。

---

## 6. 期待効果

- **使命への貢献 (間接)**: 使命は SOP → シナリオ → ナビ撮影 → 動画である。本件はその生産活動そのものではなく、
  **現場に配った席が実際に使われているかを組織管理者が見られるようにする**運用機能である。
  「専門知識ゼロの現場作業者でも作れる」を成立させるには、招待した作業者が実際に入れているかを
  管理者が確認できる必要がある (オンボーディング不全が無音で放置されない)。
  **これは撮影体験そのものを改善する施策ではない**と正直に位置づける。
- **doc/02 §2.4 の未充足項目が 1 つ減る**。
- **T201 の受け皿が実在するようになる**。T201 の適格条件 A は
  「『誰がいつ入ったか』は最終ログイン表示へ」と振り分けるが、現状その行き先が画面上に無い。
- **新しい状態を 1 つも増やさずに達成する** (列 0 本・書き込み経路 0 本)。

---

## 7. 制約・前提

- 導出元は `security_audit_events` のみ。**記録の窓口は `SecurityEventRecorder` のままで、新しい記録経路を作らない**。
- `SecurityEventType` に case を足さない (`SecurityEventCoverageTest` の map 変更は発生しない)。
- 変更系 route を足さないため、`ControllerAuthorizationGateTest` / `NestedRouteIdorDefenseTest` /
  `ThrottleCoverageInventoryTest` の各目録は**母集団が変わらない**。
- `RetentionTableClassificationTest` は**表**単位の台帳であり、列も索引も見ない。
  表を足さないので区分の追加は不要 (根拠文の追記のみ = 件数・区分は不変)。
- Svelte 5 runes + DS token のみ。新しい atom / molecule を作らず、既存の
  `text-caption text-text-secondary` の情報行として置く。アイコンは足さない。
- 必須条件未充足による disabled を作らない (そもそも操作を足さない)。

---

## 8. スコープ外 (今回作らない)

- `users.last_login_at` カラム (§2 の結論)。
- ログイン**履歴**の画面 (本件は「最後の 1 点」だけ。履歴は `security_audit_events` を持つ Filament の既存面が担う)。
- 最終**活動**日時 (§3-2)。
- 休眠アカウントの自動失効・自動通知・絞り込み UI・CSV 出力。
- 本人が自分の最終ログインを見る画面 (`/settings/*`)。
- API / MCP / JsonResource への露出。
- `security_audit_events` の保持期間の決定そのもの (区分は undecided のまま。§2-4)。
- `login_failed` の表示 (別概念。攻撃の兆候であって在籍の指標ではない)。

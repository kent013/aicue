# 前提 (アプリの使命・禁止事項・思考原則・ツール使用制限)

## アプリの使命 (AGENTS.md より転記)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項 (AGENTS.md より転記)

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# あなたの役割

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【この設計固有の争点 — 必ず判定すること】
- 遮断ゲート同士の判断が食い違っているとき、どちらに揃えるべきか。本設計は
  「2FA 必須ゲートの allowlist に退会取消を追加して通す」を選んでいる。この判断は妥当か。
  2FA 必須化の趣旨を損なわないか。セキュリティ上の後退はないか。
- 「一般化しない」という判断 (他 middleware に同種の改善を波及させない) は妥当か。
  それとも今回まとめてやるべきか。根拠を示して反論してよい。
- 再発防止の Architecture gate (救済 route のゲート通過性目録) は過剰ではないか / 不足ではないか。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: blocked-action-context (遮断時のメッセージに元操作の文脈を持たせる)

> 対象 finding: bug-hunt run `20260811-003230` の **F-4-01 (High)**。
> 一次入力: `devnotes/20260811-0146-blocked-action-context/recon-brief.md`
> 証跡: `devnotes/20260811-003230-bug-hunt/report.md` / `shard-4/shard-report.md#F-4-01`

## 背景・課題

2FA 必須組織の**未準拠**メンバーが猶予期間中に「退会を取り消す」を押すと、
`DELETE /settings/account/deletion-request` が `RequireTwoFactorForEnforcedOrganizations` に捕まり
(`ALLOWED_ROUTE_NAMES` に無い)、`/settings/security` へ汎用文言だけでリダイレクトされる。
**退会にも取消にも一言も触れない**ため、ユーザーは取り消せたのか判断できない。実際には取り消せていない。

本質は文言ではなく **ゲート同士の判断の食い違い**である。現行コードで確認した:

| ゲート | 取消 route (`settings.account.deletion-request.destroy`) の扱い |
|---|---|
| 凍結 `EnsureAccountNotPendingDeletion` | **通す** (`AccountDeletionFreezeAllowance::DeletionRequestDestroy`。根拠に「誤操作救済の本体であり、凍結中に必ず実行できなければ猶予期間を設けた意味が消える」と明記) |
| 2FA 強制 `RequireTwoFactorForEnforcedOrganizations` | **弾く** (allowlist に無い) |
| route 定義 (`routes/web.php` L236-237) | **step-up を課さない** (「救済経路に関門を足すと『取り消せない』詰みの再生産になる」) |
| controller docblock | 同上 + 奪取者リスクの受容を明記 |

`bootstrap/app.php` の priority list で 2FA ゲートは凍結より**前**に走るため、
「救済は必ず通す」と 3 箇所で結論している設計を、2FA ゲート 1 箇所が実行順の都合で先回りして覆している。

## 仮説

- **仮説 H1**: 取消 (`destroy`) を 2FA ゲートの allowlist に入れても、2FA 必須化の効力は 1mm も減らない。
  取消は権限・認証手段・業務面のいずれも増やさず、準拠判定 (`two_factor_confirmed_at`) にも触れないため。
  **検証**: 取消後も同一ユーザーが `/dashboard` 等で引き続き `settings.security` へ倒れることを実測する
  (負のコントロール)。
- **仮説 H2**: 遮断された要求が**書き込み** (非安全メソッド) のとき、「この操作は実行されていない」という
  1 文だけで H1 (説明なしリダイレクト) の中核 = 「押した結果が分からない」は解消できる。
  操作名 (route → 日本語名) の写像表は不要。
  **検証**: 遮断後に DB が変わっていないことを同一テストで assert し、文言が構造的に真であることを示す。

## 決定 1: (b) allowlist に入れて通す — (a) は補助として最小形で併用

ブリーフの選択肢 (a) メッセージに文脈を持たせる / (b) allowlist に入れて通す のうち、**(b) を主措置**とする。

### (b) を採る根拠

1. **取消は業務ではなく救済である**。凍結側は同じ問いを既に検討し「救済は通す」と結論している。
   ゲート同士の食い違いは、**より深く検討された側 (救済は通す)** に揃えるのが筋であって、
   検討されていない側 (2FA gate は取消を想定していない) に合わせる理由がない。
2. **2FA 必須の趣旨を損なわない**。ゲートの目的は「未準拠者に**プロダクトを使わせない**」ことであり、
   allowlist には既に `logout` (「離脱は常に可能」)・`session.status`・検証メール・step-up satisfier が入っている。
   退会取消は**残留の意思表示**であり、`logout` (離脱) と対称の位置にある。通しても:
   - 業務面 (dashboard / projects / billing) へは依然として到達できない
   - 認証手段は増減しない (`two-factor.disable` を意図的に外している判断とは性質が違う。
     あれは**ゲート解除手段の濫用面**であり、取消にはその性質がない)
   - 準拠判定は `two_factor_confirmed_at` のみが決める
3. **遮断のままだと「使えない」が「消える」に化ける**。猶予は `deletion_purge_after` の**絶対時刻**で
   走り続け、`account:purge-deletion-requests --apply` が期限に**不可逆な物理削除**を実行する。
   取消を塞ぐと、UX ゲートが**期限付きの破壊的ゲート**になる。2FA 必須化にその権能は与えられていない。
4. **(a) 単独ではユーザーの目的を達成しない**。本タスクの必須事項「エラーを隠すことではなく目的を達成させる」
   および AGENTS.md 禁止事項 8 の思想 (行き先のない詰みを作らない) に照らすと、
   文言改善だけで「誤操作した退会を取り消す」ジョブは依然ブロックされたままである。

### 受け入れるリスク (新規ではない)

- **セッション奪取者が取消できる**: `AccountDeletionRequestController` の docblock が既に受容済みの判断
  (失われるのは意思表示だけで本人は再予約できる。逆は本人が救済できず被害が重い)。
  2FA ゲート下で追加されるリスクは無い (奪取者は取消以外に何もできない)。
- **未準拠のまま予約を取り消せる**: 意図どおり。取消後も未準拠ユーザーは全業務面から締め出されたままで、
  2FA を設定するまで何も進まない。

### (b) で**やらないこと** (明示的に却下する)

- `settings.account.deletion-request.store` (**予約**) は allowlist に入れない。予約は救済ではなく意思表示の
  新規作成であり、遮断されても失われるものが無い (安全側に倒れる)。
- `settings.account.destroy` (即時削除) も入れない (凍結側と同じ判断: 猶予の迂回口になる)。

## 決定 2: (a) は「実行されていない」の 1 文だけ。操作名は名乗らない

(b) で取消は通るが、**同じゲートは他の書き込み要求を今後も無言で飲み込む** (例: 未準拠ユーザーが
`/settings` から「退会する」を押す → 予約されないまま汎用文言)。これは F-4-01 と同じオラクル (H1) の
残存であり、**同じ middleware の中で** 2 行で閉じられる。

- 遮断対象が**非安全メソッド** (`! $request->isMethodSafe()`) のときに限り、メッセージ先頭に
  固定の 1 文 `直前の操作は実行されていません。` を付ける。
- **この文は構造的に真である**。middleware は controller より前で短絡するため、書き込みは起きていない。
  自然言語の「何をしようとしたか」と違い、**機械で検証できる主張**である
  (遮断後に DB が変わっていないことを同一テストで assert する)。
- GET/HEAD は現行文言のまま (既存契約・既存テストを変えない)。
- XHR の 409 本文 (`TwoFactorRequiredDto::message`) も同じ文字列を共有する (2 系統に分岐を作らない)。

### 一般化の誘惑に対する立場 (思考原則 2)

以下は **今回作らない**。finding を閉じるのに不要であり、機構が増えるほど形骸化する:

- ❌ route 名 → 日本語操作名の写像表 / メッセージレジストリ (二重管理の温床。route 追加のたびに腐る)
- ❌ 「遮断された元操作」を session に積んで着地ページで復元する仕組み (状態が増える)
- ❌ 他 middleware (`EnsureAccountNotPendingDeletion` / `RequireActiveSubscription` / `RequireRecentAuth`) への
  同種変更 (今回の finding は 2FA ゲートで起きている。他は再現された事実がない)
- ❌ 共通の "blocked action context" サービス / 抽象 (1 箇所しか使わない抽象は作らない)

## 決定 3: 再発防止の機械化 — 救済 route のゲート通過性を deny-by-default 目録で固定する

**新しい不変条件 R**: 「**退会予約の取消は、認証済み web リクエストで短絡しうるゲートすべてについて、
通すか / 通さないが詰みでない理由を宣言してある**」。

- 目録: `App\Enums\Security\RescueRouteGateDisposition` (case の value = middleware FQCN)。
- 母集団: 取消 route の **resolve 済み middleware 集合** (`Router::gatherRouteMiddleware()`)。**exact-fit**。
- deny-by-default: 母集団に現れた middleware が目録に無ければ fail。目録にあって母集団に無くても fail。
- 名指し pin: 2FA ゲートと凍結 middleware は `PassesRescueRoute` 以外の disposition に**移し替えられない**。
- 宣言と実装の一致: `PassesRescueRoute` の case は実際の allowlist 定数を引いて取消 route が入っていることを
  検証する (宣言だけで緑にならない)。

この gate が閉じる再発経路は「**web group に短絡するゲートを足した人が、救済経路のことを考えずに済む**」こと
そのものである。F-4-01 はまさにその形で生まれた。

## 期待効果

- **使命への貢献**: 現場作業者が「誤って押した退会を取り消せる」= 猶予期間 (30 日) を設けた目的が実効になる。
  アカウント消失は撮影済み素材・SOP・シナリオの喪失であり、使命 (標準化されたマニュアル動画を作れる) の前提を壊す。
- 2FA 必須組織の未準拠ユーザーが、**遮断されたときに何が起きなかったかを必ず知る**。
- 「救済経路はゲートを通る」が人の記憶ではなく Architecture テストで守られる。

## 実装方針 (概要)

| # | 施策 | 変更ファイル |
|---|---|---|
| 1 | 取消 route を 2FA ゲート allowlist へ追加 | `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` |
| 2 | 非安全メソッド遮断時の「実行されていません」1 文 | 同上 |
| 3 | 救済 route ゲート目録 (新 enum + Architecture gate) | `app/Enums/Security/RescueRouteGateDisposition.php` (新規) / `tests/Architecture/RescueRouteGateInventoryTest.php` (新規) |
| 4 | 既存テストの契約更新 + 新規テスト | `tests/Feature/Auth/AccountDeletionFreezeTest.php` / `tests/Feature/Organizations/TwoFactorEnforcementTest.php` |
| 5 | ドキュメント更新 | `docs/architecture.md` §退会の猶予期間つき削除 / `docs/auth-security-mechanisms.md` |

**フロントエンド変更なし** (`resources/js/pages/Settings/Index.svelte` の取消ボタンはそのまま動くようになる)。
DESIGN.md token / Atomic Design の論点は発生しない。

## 制約・前提

- 2FA 必須化の仕組みそのものは緩めない (ブリーフの「やらないこと」)。
- 凍結方式 (users 行の生死を変えない) は変えない。
- priority list の順序は変えない (2FA ゲートが凍結より前に走る事実はそのまま。前提を変えず allowlist で解く)。
- `tests/Feature/Auth/AccountDeletionFreezeTest.php` の
  「2FA 未準拠ユーザーは 2FA ゲートが先に効く…」は**現行の遮断を意図的に pin している**ため、
  契約変更に伴い書き換えが必要になる。これは禁止事項 3 (既存テストの削除・上書き) の趣旨
  (検証を消して緑にする) には当たらないが、**設計として明示的に扱う** (施策 4)。

## スコープ外

- 他 middleware の遮断文言 (課金ゲート / 凍結 / recent-auth)。
- 予約 (`store`) の遮断挙動そのもの (文言のみ改善)。
- bug-hunt run の他 finding (F-1-01 / F-2-01 等) — 別設計ディレクトリの担当。
- 「遮断メッセージが元操作を名指しする」ことの機械保証 (自然言語は照合しない。保証しないものとして明記する)。

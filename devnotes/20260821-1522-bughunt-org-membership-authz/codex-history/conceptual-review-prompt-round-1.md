## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

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

## セキュリティ不変条件(アプリ都合で緩めない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか（特に禁止事項 8「必須条件未充足を理由にボタンを disabled にする UI」との整合を確認せよ）
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか（特に F-2-02 修正が register 誘導/T055・アプリ内受諾 AG-113 経路を壊さないか）
6. スコープの適切さ: 過大または過小になっていないか（F-2-03 で production コードを変更せずテスト固定に留める判断、F-2-01 で disabled 化を退ける判断の妥当性）
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計
# 概念設計: bughunt-org-membership-authz

bug-hunt run `20260821-095643` の「組織メンバーシップ / 認可」グループ 3 件
(F-2-02 / F-2-03 / F-2-01) への設計。証跡は
`devnotes/20260821-095643-bug-hunt/report.md` および `shard-2/shard-report.md`。

## 事前検証 (ground truth の確定)

設計前に、報告の症状が **現行コード (working tree)** で本当に再現するかを
使い捨て Pest Feature テストで確認した (テストは検証後に削除。以下は観測結果)。

| finding | 検証内容 | 観測 |
|---|---|---|
| F-2-02 | 別 email の既ログインユーザーが token を POST 受諾 | **join 成功 (再現・実在の Critical)** |
| F-2-03 (a) | `organizations.members.destroy` (HTTP) で pivot が外れるか | **外れる** (`organization_user` から消える) |
| F-2-03 (b) | 除名された編集者が `/dashboard` `/projects` `/billing` を見られるか | 除名後は当該組織へアクセス不可 (組織 0 件の着地に落ちる) |
| F-2-03 (c) | 「未割当」= attach 済み・laratrust ロール無しの行が組織データを見られるか | **全経路 403** (fail-closed) |
| F-2-01 | プロジェクト 0 件組織の `/manage/users` に事前の注記があるか | **既に注記 + 作成 CTA を表示済** (`Users.svelte` L275-290) |

**結論**: 実在する脆弱性は **F-2-02 のみ**。F-2-03 の pivot 解除は既に実装済み
(`removeMember` が `detach` する。T025 で 2026-07-13 に導入) で、「未割当」行も
アクセスは fail-closed。F-2-01 の「事前表示」も既に存在する。
したがって本設計は **(1) F-2-02 の実修正**、**(2) F-2-03 の既存不変条件をテストで固定**、
**(3) F-2-01 を AGENTS.md 禁止事項 8 に沿って最小改善** の 3 本立てとする。

## 背景・課題

### F-2-02 (Critical, 実在): 招待受諾が宛先 email を照合しない

招待受諾には 3 経路がある:

1. **register 経路** (`acceptInvitationIfValid`): 招待 email と登録 email の一致を要求済 (安全)。
   `MatchesInvitationEmail` rule と対で二重防御。
2. **アプリ内受諾** (`acceptPendingInvitation`, 裁定 AG-113): `pendingInvitationsQuery` が
   `activePendingForEmail(ログイン者の email)` に畳まれ、他人宛には構造的に到達不能 (安全)。
3. **token POST 受諾** (`acceptInvitation` → `InvitationAcceptanceController::store`): **email 照合が無い**。

`acceptInvitation` の docblock (L111-113) と Controller の docblock (L23) は
「ログイン後経路では email 一致を要求しない仕様」と明記している。これは意図的な設計だが、
経路 1・2 が email 境界を強制しているのに経路 3 だけが素通しであり、招待リンクを
(メール転送・URL 共有・ログ) で知った無関係の第三者が自分のアカウントで任意の組織へ
参加できる = **組織のメンバー境界 (誰が入れるか) という認可境界の破れ**。
使命の観点では、SOP・撮影データを扱う組織のメンバー集合が意図せぬ第三者に開くのは
標準作業の管理主体が崩れることを意味し、看過できない。

### F-2-03 (報告 Critical, 実態は既対応): 除名の不完全さ / 「未割当」行の許容

報告は「除名がロール剥奪のみで pivot を外さず、除名済みユーザーが閲覧アクセスを保持し、
`/manage/users` に『未割当』で再出現する」とする。しかし現行 `removeMember` は
`detach` (pivot 解除) + ロール剥奪 + project pivot 掃除 + current_organization_id クリア +
トークン失効を 1 トランザクションで行う。検証でも除名後アクセス不可・一覧から消滅を確認した。

報告が観測した「未割当で再出現」は現行コードでは再現しない (bug-hunt 環境の一時状態か、
並行受諾レース残渣の観測と推定)。ただし報告が指摘する **設計論点は有効**:
「attach 済みだが laratrust ロール未付与 (=『未割当』) の行を許容し続ける設計の是非」。
この状態は `joinOrganization` の並行受諾 (insertOrIgnore が 0 行の敗者側) や、
今後の経路追加で生じうる。**検証の結果、この状態はアクセスが全経路 403 (fail-closed) で、
管理画面から `applyConsoleRole` の修復経路でロールを付け直せる** ため、
情報漏洩には至らない。

### F-2-01 (Medium): プロジェクト 0 件組織のロール option

`/manage/users` のロール変更 combobox で「編集者/撮影者」が選択可能に見え、送信後に
「編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」の validation
エラーが出る (1 往復の手戻り)。

**ここで AGENTS.md 禁止事項 8「必須条件未充足を理由にボタンを disabled にする UI
(押下時にエラー表示する。DESIGN.md)」が効く。** bug-hunt の改善案「両 option を disabled に」は
この禁止事項に真っ向から反する。加えて `Users.svelte` は既にカード冒頭で
「プロジェクトがまだありません。編集者・撮影者を割り当てるには…」の注記と作成 CTA を
`!hasDefaultProject` のとき表示している (事前表示は実装済)。
したがって F-2-01 で許される改善は **disabled 化ではなく**、選択地点 (option ラベル) に
非 disabled の情報を足して手戻りを減らすことに限られる。

## 改善アイデア

### F-2-02: token POST 受諾に宛先 email 照合を追加する (経路 1・2 と揃える)

- **Service** `OrganizationMembershipService::acceptInvitation`: 招待解決後・join 前に
  `$invitation->email !== $user->email` なら `ValidationException` を投げる (権威的な server 側 gate。
  UI を迂回する直 POST も塞ぐ = 経路 2 と同じ deny-by-default 思想)。
- **Controller** `InvitationAcceptanceController::show`: ログイン済 + 有効招待 + email 不一致のとき、
  受諾ボタンを出さず「この招待は別のメールアドレス宛です。招待メールを受け取ったアドレスで
  ログインし直してください。」の案内 + ログアウト導線を表示する (画面上でも受諾させない)。
- docblock の「一致を要求しない仕様」記述を削除・更新する (禁止事項の後方互換並走を残さない:
  書き換えると決めたら旧記述を同じ変更で消す)。
- **未ログイン → register 誘導 / メール自動入力 (T055) は一切変えない** (show の guest 分岐は
  email 照合の前に return するため影響しない)。

### F-2-03: 既存不変条件を Feature テストで固定 + 設計判断の明記 (production コード変更なし)

- 現行 `removeMember` の完全除名 (pivot 解除・アクセス不可・一覧から消滅) を **HTTP 経路の
  リグレッションテスト**で固定する。AGENTS.md 禁止事項 1「不変条件は対応するテストへの登録まで
  含めて実装済み」に従い、「既に正しい」を「壊れたら落ちる」に格上げする。
- 「未割当」行が全経路 403 (fail-closed) であることをテストで固定する。
- **設計判断 (明記事項)**: 「未割当」行は **許容し続ける**。理由: (a) アクセスは fail-closed で
  情報漏洩に至らない、(b) 管理画面の `applyConsoleRole` 修復経路が正規の回復手段として既に存在、
  (c) 並行受諾レースの自然な帰結であり、これを禁止するには受諾コアへ追加機構が要る =
  思考原則「今必要なものだけ作る」に反する。よって production コードは変更しない。

### F-2-01: option ラベルに非 disabled の注記を足す (禁止事項 8 遵守)

- `Users.svelte` の `ROLE_OPTIONS` を、`hasDefaultProject` が false のとき
  「編集者」「撮影者」ラベルへ注記サフィックス (例:「編集者（要プロジェクト）」) を付す
  派生に変える。**option は選択可能なまま** (押下すれば従来どおりサーバ error bag を表示)。
  カード冒頭の既存注記 + 作成 CTA は維持する。
- 「管理者」は無条件で選べるためサフィックスを付けない。

## 期待効果

- **使命への貢献**: 組織 = 標準作業 (SOP) と撮影データの管理単位。F-2-02 修正でメンバー境界が
  意図した email 境界どおりに閉じ、第三者混入を防ぐ (機密の SOP/映像への不正参加を遮断)。
- F-2-03 のテスト固定で、最重要のセキュリティ操作 (メンバー排除) の不変条件が退行検知される。
- F-2-01 で手戻り 1 往復を減らしつつ、DESIGN.md / 禁止事項 8 のUX原則を守る。

## 実装方針(概要)

| finding | 変更 | 種別 |
|---|---|---|
| F-2-02 | `acceptInvitation` に email 照合 / `show` に不一致分岐 / Accept.svelte に mismatch 表示 / docblock 更新 / 目録 description 更新 | production + test |
| F-2-03 | HTTP 除名リグレッション + 未割当 fail-closed テスト。production 変更なし | test のみ |
| F-2-01 | `Users.svelte` option ラベル注記 (非 disabled) | frontend + test |

## 制約・前提

- Laravel 12 + Svelte 5 + Inertia + PHP 8.4、PHPStan level 10、Pest + RefreshDatabase (parallel)。
- 招待解決経路の分類は `InvitationResolutionInventoryTest` が deny-by-default で強制。
  `acceptInvitation` は `TokenHashLookup` scope のまま (email 照合は解決 **後** に足すので
  解決 scope は変わらない)。目録の説明文だけ更新する。
- セキュリティ不変条件 9 (変更系は認可を通る)・2/10 (テナント境界 404 が認可より前) は現状維持。
  受諾 route は auth 必須・email 照合は認可ではなく招待の宛先検証 (層 3 の後) として足す。
- CipherSweet 下でも `$invitation->email` / `$user->email` は復号後の平文文字列比較でよい
  (経路 1 `acceptInvitationIfValid` L179 と同じ比較。blind index は不要)。

## スコープ外

- F-2-02 の未ログイン register フロー・メール自動入力 (T055) の挙動変更。
- 並行受諾レースそのものの禁止 (「未割当」行を構造的に作らせない機構)。
- 招待フォームのロール表現の文言修正 (bug-hunt インベントリ提案 2。別グループ)。
- Q-2-01 (招待参加者の初回着地) / debug.login-as インベントリ提案。
- 他グループの findings (F-1-*, F-3-*, F-4-*)。

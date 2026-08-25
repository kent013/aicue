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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー対象の背景】
本設計は、複数リポジトリで共有する機能台帳 (lctl) の feature auth-invitation-flow の「正典 v1」(不変条件 i1〜i17) への追従設計である。正典 v1 の不変条件のうち aicue リポジトリで未充足と判定された 4 点 (i7 の一部 / i11 / i14 の一部 / i16) を埋めることが目的で、不変条件の中身自体は台帳側で確定済み (再議論しない)。設計ドキュメント内の i 番号は正典の不変条件を指す。

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
---
## 概念設計

# 概念設計: auth-invitation-flow-v1 — 家系の正典 v1 への追従 (t0 → v1)

## 背景・課題

家系の機能台帳 lctl の feature `auth-invitation-flow` (組織招待フロー) は 2026-08-24 の settle で
正典 v1 が確定した (不変条件 i1〜i17。design.md doc_sha `125e9a044a01`)。aicue セルは
**update_pending (t0 → v1)** の部分達成と判定されている (観測点 aicue@b207bafa 実読。
台帳の feature_revision `28-be3d35ffee06` を 2026-08-25 に本設計セッションで再取得して確認済み)。

**充足済み (変更不要)**:
- **i4** (受諾の全経路での宛先照合): aicue@88ccd438 が正典の採用元。早期照合 + 行ロックで
  取り直した利用者行での再照合 (`OrganizationMembershipService::acceptInvitation` +
  `joinOrganization` の 1b)。
- **i8** (宛先不一致時に組織名を画面の初期データごと落とす): `InvitationAcceptanceController::show`
  が不一致時 `organizationName: null` を渡す形も正典が採った。

**未充足 (本設計の対象)** — settle と裁定 AG-214 が挙げた 4 点:

1. **i7 の一部**: 招待元の組織が**論理削除済み**のとき、`$invitation->organization` が
   SoftDeletes の global scope で null になり `Assert::isInstanceOf` が例外を投げて **500** になる。
   「token は有効だが組織が消えている」と「token が無効」を 5xx か否かで区別できる
   **存在オラクル**が残っている。箇所は 3 つ (台帳の言う「受諾の表示・受諾・受諾の可否判定」):
   - `InvitationAcceptanceController::show()` L71-72 (受諾の表示)
   - `OrganizationMembershipService::acceptInvitation()` L147-148 (受諾)
   - `OrganizationMembershipService::acceptInvitationIfValid()` L192-193 (受諾の可否判定 —
     register 経路。ここで例外が出ると**登録そのものが 500 で失敗する**)
   なお、他の無効事由 (不在・取消済み・受諾済み・期限切れ) の同一畳み込みは実装済みで、
   アプリ内受諾 (`acceptPendingInvitation`) は scope の `whereHas('organization')` で
   既に畳めている (変更不要)。
2. **i11**: 招待継続 (未ログインで招待リンクを踏んだときの session 保持) が専用クラスでなく、
   session の生の鍵 `'invitation_token'` が 3 ファイルに散在する —
   `InvitationAcceptanceController` (put)、`CreateNewUser` (get / forget)、
   `OrganizationMembershipService` (get / forget)。鍵の一本化の機械検査も無い。
3. **i14 の一部**: 継続の型衛生つき読み出しと不正値・stale 値の破棄が、登録処理
   (`CreateNewUser::resolveInvitationToken`) と登録画面の事前入力の解決処理
   (`OrganizationMembershipService::resolveRegisterPrefillEmail`) に**重複**している。
4. **i16** (裁定 AG-214 で正典 v1 に追加): 招待経由の登録は、作成される利用者の email が
   招待の宛先であることが server 側で保証されている (i13) ことを前提に、
   **メール確認済みとして作成する** (招待メールの URL の所持を受信箱の所有の証拠と扱い、
   確認メールを別送しない)。aicue は未達 — 招待経由の登録者も unverified で作られ、
   確認メールが別送される (User は `MustVerifyEmail` 実装 +
   `config/fortify.php` で `Features::emailVerification()` 有効)。

### i16 の前提 (i13) が aicue で成立していることの確認 (2026-08-25 実読)

i13「作成される利用者の email = 招待の宛先」は aicue で server 側の三重で保証されている:

1. **validation 層**: `CreateNewUser::create()` が `MatchesInvitationEmail` rule を email に適用。
   session に active な招待 token があるとき、登録 email が招待宛先と不一致なら 422
   (`app/Rules/MatchesInvitationEmail.php` — 判定は `OrganizationInvitation::isAddressedToEmail`
   に集約。i5 充足済み)。
2. **受諾の事前照合**: `acceptInvitationIfValid()` が `isAddressedTo($user)` 不一致で join しない。
3. **最終権威 (ロック下)**: `joinOrganization()` の 1b が行ロックで取り直した User 行で
   `isAddressedTo` を再照合し、不一致は受諾不能 (false) へ畳む。

したがって「招待受諾が成立した ⇔ 作成 email = 招待宛先がロック下で確認済み」であり、
i16 の付与条件を**受諾の成立 (join 成功)** に結び付ければ前提が構造的に満たされる。
既存テスト `tests/Feature/Organization/InvitationTest.php` の「招待 email と異なる email で
register すると email エラーになる」等がこの保証を固定している。

## 改善アイデア

正典 v1 の未充足 4 点を、既存の充足済み資産 (i4/i8) を壊さずに最小の変更で埋める。
3 施策に分ける:

### 施策 A (i7): 招待元組織の論理削除を「無効招待」の同一畳み込みへ

- 3 か所の `Assert::isInstanceOf($organization, ...)` の**手前**で組織の生存を判定し、
  null (論理削除済み) を既存の無効事由と**同一応答**へ畳む:
  - `show()`: 既存の無効分岐 (理由非開示の `Invitations/Invalid` ページ直描き) へ合流。
    タイトル・文言・ステータス (200) も他事由と完全に同一にする (出し分け = オラクル)。
  - `acceptInvitation()`: 取消済みと同じ中立メッセージ `'この招待は無効です。'` の
    ValidationException へ畳む。
  - `acceptInvitationIfValid()`: null を返して個人組織生成へ fallback (登録は成功させる)。
- あわせて `joinOrganization()` のロック下再検証に**招待元組織の生存の再確認**を足す
  (i2 の「招待行の行ロックの下で受諾可能状態と**招待元組織の生存**を再検証」の文言に追従)。
  早期判定〜ロック取得の間に組織が論理削除される TOCTOU 窓を閉じ、削除済み組織への
  attach を構造的に防ぐ。失敗は既存の false 契約 (受諾不能) へ畳む。
- 応答の form は既存の「同一 route 内でのページ直描き」を維持する (正典形の「専用 route への
  差し戻し」への追従は s5 で form の差として許容されており、今回は要求されていない —
  スコープ最小)。

### 施策 B (i11 + i14): 継続クラス `InvitationContinuation` の導入と鍵一本化の機械検査

- テンプレート (laravel-claude-template@5dd85a6) の `app/Support/Auth/InvitationContinuation.php`
  を参照実装として移植する (`remember` / `resolve` / `forget` の 3 メソッド +
  型衛生: 非文字列・空文字は忘れさせて null)。
  - テンプレートの `landing()` (認証を抜けた後の着地) は**移植しない** — aicue には
    継続を見てログイン後に着地を分岐する経路が現存せず、呼び出し元の無いメソッドを
    作らない (思考原則 2「今必要なものだけ作る」。外部ログイン経路の継続は settle の
    aicue 未達一覧に含まれておらずスコープ外)。
- 3 ファイルの生の鍵直書きを**同じ変更で**継続クラス経由に置き換える
  (後方互換の並走を残さない — 旧鍵直書きの残置は禁止):
  - `InvitationAcceptanceController::show()`: `put` → `remember`
  - `CreateNewUser`: `resolveInvitationToken()` を削除し `resolve` に置換、
    登録確定時の `forget` も継続クラス経由に (i14 の terminal 消費)
  - `OrganizationMembershipService::resolveRegisterPrefillEmail()`: 型衛生 read と
    stale/invalid 破棄を `resolve` / `forget` 経由に置換 (i14 の重複解消 —
    読み出しの型衛生ロジックはリポジトリで継続クラスの 1 実装だけになる)
- 鍵の一本化を機械検査で固定する: `tests/Architecture/InvitationContinuationKeySoTTest.php`
  をテンプレートから移植 (app/ 配下の PHP を token_get_all で走査し、鍵 literal
  `'invitation_token'` の出現を継続クラス 1 ファイルへ pin。負例 IC-2 内蔵)。
  AGENTS.md「走査器・gate を新設するときの 4 点」(負例と正例 / fail-closed /
  母集団の非空 / docblock の保証範囲) を満たす — 期待値が「ちょうど [SoT の 1 ファイル]」の
  完全一致なので、走査が空振りすれば空配列 ≠ [SoT] で赤になる (母集団の非空を判定が内包)。

### 施策 C (i16): 招待経由登録への verified 付与

- `CreateNewUser::create()` の登録トランザクション内で、招待受諾が成立した
  (`acceptInvitationIfValid` が Organization を返した) ときに限り
  `email_verified_at` を立てる (`markEmailAsVerified()` — user 行は直前に自分が
  INSERT した行で、joinOrganization のロック下再照合を通過済み)。
- tx 内で verified を立てるため、Fortify が登録後に発火する `Registered` イベントの
  `SendEmailVerificationNotification` listener は `hasVerifiedEmail()` = true で
  確認メールを送らない — 「確認メールを別送しない」が既存機構のまま成立する
  (listener の条件分岐は Laravel 本体の実装。詳細設計で検証テストに含めて固定する)。
- **付与しない側 (fail-closed)**: 継続が無い通常登録、および受諾不能 (失効・取消・
  組織論理削除・並行受諾の敗北・宛先不一致) で個人組織へ fallback した登録は
  従来どおり unverified で作成し、確認メールを送る。i16 後段の「前提が成立しない登録に
  verified を与えてはならない」に一致する。受諾の成立をもって付与するので、
  validation 時点と tx の間で招待が失効した場合も付与されない (TOCTOU で緩む方向が無い)。

## 期待効果

- **使命への貢献**: 招待は aicue の組織 (現場チーム) へメンバーが入る唯一の導線。
  i7 の 500 排除は「組織が実在して消された」ことを外部の token 保持者へ教える口を塞ぎ
  (セキュリティ不変条件「層 2 = テナント境界 404/中立が先」の精神)、i16 は招待された
  現場作業者の登録直後の体験からメール確認の 1 ステップを正当に除去する
  (思考ゼロで撮影に入れる、の入口を短くする)。
- register 経路の 500 (論理削除組織の招待 token で登録が丸ごと失敗する) という
  実バグの解消。
- 継続の読み書きが 1 クラスへ集約され、鍵 drift・型衛生 drift が機械検査で恒久固定される。
- 家系台帳への status_reported (実装完了後、別途 append_event) で aicue セルの
  update_pending を解消できる状態になる。

## 実装方針 (概要)

| 施策 | 変更ファイル |
|------|-------------|
| A (i7) | `app/Http/Controllers/Organizations/InvitationAcceptanceController.php` / `app/Services/Organization/OrganizationMembershipService.php` / テスト新設 `tests/Feature/Organizations/InvitationDeletedOrganizationTest.php` (名称はテンプレートの検査に揃える) |
| B (i11+i14) | 新設 `app/Support/Auth/InvitationContinuation.php` / 新設 `tests/Architecture/InvitationContinuationKeySoTTest.php` / `InvitationAcceptanceController.php` / `app/Actions/Fortify/CreateNewUser.php` / `OrganizationMembershipService.php` / 既存テスト (`RegistrationInvitationPrefillTest` 等) の session 鍵利用は tests/ 側なので走査対象外・変更不要 |
| C (i16) | `app/Actions/Fortify/CreateNewUser.php` / 新設 `tests/Feature/Auth/InvitationRegistrationVerifiedTest.php` / 既存 `tests/Feature/Organization/InvitationTest.php` の fallback テストへ unverified 検証を追記 |

テストファースト: 各施策とも再現テスト (A: 論理削除組織で 3 経路が 500 にならず畳まれる /
B: 機械検査を先に赤で確認 / C: 招待経由登録が verified・fallback が unverified) を先に書き、
fail を確認してから実装する。

## 制約・前提

- PHP 8.4 + Laravel 12 + PHPStan level 10 + Pest (RefreshDatabase は tests/Pest.php で
  グローバル適用済み)。
- `Organization` は SoftDeletes を使用 (実測: `app/Models/Organization.php` L74)。
- 施策 B の継続クラスはテンプレートに実在するファイルの移植だが、
  `docs/template-fingerprints.json` の entries (281 件) に招待関連・`app/Support/Auth/` 配下の
  パスは 1 件も無い (2026-08-25 実測) ため、乖離台帳 (`docs/template-divergence.md`) への
  登録義務は発火しない。採用時債務一覧にも該当パス無し。
- i4/i8 の充足済み実装 (早期照合・ロック下再照合・組織名 null 落とし) と、その固定テスト
  (`InvitationTest` T3/T4/T4b/T5、`InvitationAcceptRaceTest`) を一切後退させない。
- frontend (`Invitations/Accept.svelte` / `Invalid.svelte` / `Auth/Register.svelte`) の
  props 契約は変えない (波及変更なし。i7 は既存 Invalid ページへの合流、i16 は server 側のみ)。

## スコープ外

- 正典形 (i7 の「専用 route への差し戻し」form) への転換 — s5 が form の差として許容。
- 外部ログイン (SSO) 経路の招待継続の新設 — settle の aicue 未達一覧に無い。
  aicue の SSO は企業 SSO (EnterpriseUserProvisioner) で招待とは別導線。
- i17 (署名 URL の撤去) — aigenba 固有の追従対象で aicue は元から素の token URL。
- アプリ内受諾 (`auth-invitation-in-app-discovery`) — 別 feature (裁定 AG-079)。
- 受諾時の verified **要求** — AG-214 が「しない (現状維持)」と裁定済み。
- TODO 登録 — 本設計はファイル生成のみ (呼び出し元の指示)。

【アプリの使命 (North Star) — AGENTS.md より】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


【禁止事項 — AGENTS.md より】
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

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【本件の追加文脈 — レビュー時に前提としてよい事実】
- 本件は「家系の機能台帳 lctl」が保持する正典 feature `auth-enterprise-oidc` (canonical_version v1 / 裁定 AG-200 適合) への**追従設計**である。設計の自由度は低く、正典テンプレート (laravel-claude-template) の実装を土台に aicue の作法へ写すことが求められている。独自方式の提案は「正典との乖離」を生むため、原則として採らない。
- aicue の現状は実装 0 行 (実読で確認済み)。
- 前段依存として `kent013/laravel-ssrf-pin` の ^0.4 化が別 TODO で先行する。
- aicue のソーシャルログイン (`app/Http/Controllers/Auth/SocialAuthController.php`) は既に `Auth::login()` で即時確定しており、2 要素認証の入力画面へ送る分岐を持たない (実読で確認済み)。

---

## 概念設計

# 概念設計: enterprise-oidc-sso-adoption

家系の機能台帳 lctl の feature `auth-enterprise-oidc`
(canonical_version **v1 / AG-200 適合**、feature_revision `23-30a9407c8f19`) への追従。
aicue のセルは `status: pending`（裁定 2026-08-04 で全アプリ導入決定済み・実装 0 行）である。

## 背景・課題

### 正典が求めていること

企業契約の顧客が **自社の認証基盤 (会社の共通ログイン) でそのまま aicue に入れる** 受け口。

- 組織ごとに OIDC 接続を登録する（接続先 URL は**顧客自身が入力する**）
- その組織の社員が初めて入ってきたら **その場でアカウントを作る (always-JIT)**
- **メールアドレスを本人の同定に使わない** —
  これは「登録済みメールかどうかが応答から読み取れる (アカウント存在の探り当て)」と
  「他所で確認済みと主張されたメールで乗っ取る (nOAuth)」の 2 つを同時に塞ぐ本体であり、
  正典が**削減不可**と明記している要件である。
- 裁定 **AG-200 (2026-08-18)**: 企業ログイン・ソーシャルログインの経路に
  **アプリ側の 2 要素認証を挟まない**。追加の本人確認は認証基盤側の責務、
  組織義務づけの強制は別関門 `auth-2fa-org-enforcement` に任せる。

正典実装は **laravel-claude-template** が保持する（7 サービス + 2 モデル + config + route 群 +
gate 3 本）。**aigenba** が AG-200 適合の参照実装を v1 で完了している（家系で最初）。

### aicue の現状（実読で確認）

| 正典の資産 | aicue |
|---|---|
| `app/Services/EnterpriseSso/` (7 クラス) | **0 件** |
| `config/enterprise-sso.php` | **無し** |
| `OrganizationOidcConnection` / `EnterpriseIdentity` モデル・移行 | **0 件** |
| ログイン導線 3 route / 組織側の接続管理 7 route | **0 件** |
| gate 3 本 | **0 件** |
| メールアドレス昇格フロー | **0 件** |

一方で **土台は揃っている**:

- `Kent013\SsrfPin\UrlSafetyInspector` の利用実績が 1 件ある
  (`app/Services/Mail/Sns/SnsCertificateFetcher.php` — 無認証入力が誘発する外部取得の準拠実装)
- 組織まわりの `MembershipScopedOrganizationBinder` / `{organization:slug}` 解決、
  `recent-auth` middleware、`not-pending-deletion` group、named limiter、
  `ExternalFakeDeclaration` / `ExternalSeamInventory`（外部到達点の目録）が既に稼働している
- **ソーシャルログインは既に AG-200 の形である** —
  `SocialAuthController::callback()` は `Auth::login()` で**その場でログインを確定**しており、
  2 要素認証の入力画面へ送る分岐を持たない（実読で確認）。
  つまり aicue は AG-200 に**是正ではなく維持**で臨める。

### 課題

1. 企業契約顧客が自社の共通ログインで入れない。社員の入退社に合わせた開け閉めが手作業のまま残る。
2. 前段条件が未達 — `kent013/laravel-ssrf-pin` が `^0.2` (解決版 v0.2.0)。
   正典の接続先情報の取得・トークン交換は **要求 body を運べる版 (v0.3 以降) が必須**で、
   v0.2 では実装そのものが成立しない。
3. AG-200 の形が **現状は「たまたま」保たれているだけ**で、機械で固定されていない。
   企業ログインという 2 本目の経路が増える瞬間が、分岐の複写が生まれる最大の危険点である
   （起源 spirux は実際に 2 経路へ同じ分岐を複写して update_pending になっている）。

## 改善アイデア

**正典テンプレートの実装を土台に、aicue の既存作法へ写して一式を導入する。**
独自の作り直しはしない（家系で 1 つの形を共有する feature である）。

方針は 4 本:

1. **メールで人を引かない設計を、実装と gate の二層で持つ。**
   身元表は「接続 × IdP の subject」で一意に引く。申告メールは暗号化して持つが
   **索引を意図的に持たせない**（索引があると「メールで引ける」経路が復活する）。
   これを `EnterpriseSsoEmailIdentityIsolationTest` が正規表現と**索引 0 本の実行確認**で固定する。
2. **外部取得は必ず SsrfPin の窓口を通す。**
   接続先情報の取得・鍵の取得・トークン交換の 3 経路すべて。
   `EnterpriseSsoOutboundHttpGateTest` が名前空間配下の素の HTTP 呼び出しを許可一覧なしで弾く。
3. **接続の秘密は受け渡しの型に載せない。**
   前面で秘密を扱ってよいのは**接続の登録・更新フォーム 1 本だけ**。
   `EnterpriseSsoSecretExposureGateTest` が固定する。
4. **AG-200 を「維持」として機械に持たせる。**
   企業ログインの戻り口もソーシャルログインの戻り口も、確認できた時点でログインを確定させる。
   待機ログイン（2 要素入力画面への転送）を**どちらの経路にも作らない**ことを
   静的 gate で裏当てし、主たる証明は実挙動テストに置く（aigenba の `T1220` が参照実装）。

### aicue 固有の適合（テンプレートをそのまま置けない箇所）

| 論点 | aicue での形 |
|---|---|
| 外部到達点の目録 | テンプレートには無い `ExternalSeamInventory` / `ExternalFakeDeclaration` に、企業 OIDC の到達点と試験用の差し替えを登録する（同じ事実を 2 か所に書かない規約に従う） |
| テストレーンの外向き HTTP 既定拒否 | 実 IdP へ出ない。試験用の接続先（偽の IdP）は `ExternalFakeDeclaration` の枠組みへ載せ、許可環境は外部ログインと同じ `testing` / `bughunt.local` に絞る |
| route の目録 | `RecentAuthRouteTest` / `ThrottleCoverageInventoryTest` / `ControllerAuthorizationGateTest` / `NestedRouteIdorDefenseTest` / `TenantBoundaryOrderingTest` / `AccountDeletionFreezeRouteGateTest` へ新 route を登録する（aicue は deny-by-default の目録が多く、登録漏れは赤になる） |
| 組織 route の引数解決 | 既存どおり `{organization:slug}` + `MembershipScopedOrganizationBinder`（テンプレートの識別名解決と同じ思想） |
| PII | `EnterpriseIdentity` の申告メールは CipherSweet で暗号化する（不変条件 6）。ただし **blind index は付けない**（不変条件 6 の「検索は whereBlind」は**引く必要がある PII** の話であり、ここは引かないことが要件） |

## 期待効果

- **使命への貢献（間接だが実在する）**: AI-CUE は「現場作業者が専門知識ゼロで標準化された
  マニュアル動画を作れる」ことを使命に持つ。企業導入では**現場の作業者が自分でアカウントを
  作る／パスワードを覚えることそのものが最初のハードル**であり、勤め先の共通ログインで
  そのまま入れることは「思考ゼロ」の入口側の実現である。入退社に合わせた開け閉めが
  認証基盤側に寄るため、現場管理者の運用負荷も落ちる。
- **セキュリティの前進**: 顧客入力 URL を取りに行く経路が SSRF 窓口の下で 1 本道になる。
  メールでの引き当てを構造的に禁じることで、アカウント存在の探り当てと nOAuth 型の
  乗っ取りが**設計段階で**消える。
- **家系整合**: pending 4 リポジトリのうち aicue が AG-200 適合で追従し、
  正典との乖離が 1 件減る。

## 実装方針（概要）

### 段構成（直列。前段が緑になってから次へ）

| 段 | 内容 | 主な成果物 |
|---|---|---|
| 前段 | `kent013/laravel-ssrf-pin` を `^0.4` へ | **別 TODO `ssrf-pin-v04-upgrade` の責務**（本設計は依存するだけ） |
| A | 器 — 設定・モデル・移行・Factory | `config/enterprise-sso.php`, 2 モデル + 移行 2 本 + Factory 2 本 |
| B | 取得と検証 — サービス 4 クラス | 接続先情報の取得 / トークン交換 / ID トークンの検証 / ログイン試行の保管 |
| C | ログイン導線 — サービス 3 クラス + controller + route 3 本 | 利用者の自動作成 / callback の組み立て / 接続の状態遷移, `EnterpriseSsoLoginController` |
| D | 組織側の接続管理 — controller + route 7 本 + 画面 | `OrganizationSsoConnectionController`, Svelte 画面 1 枚 |
| E | メールアドレスの昇格フロー | `App\Services\Auth\EmailPromotionService` ほか（**Auth 名前空間**） |
| F | gate 4 本 + 目録登録 | 正典 gate 3 本 + AG-200 の裏当て 1 本 |

### 名前空間の配置（正典の設計判断ごと引き継ぐ）

メールアドレスの昇格フローは `App\Services\EnterpriseSso` ではなく **`App\Services\Auth`** に置く。
これは「メールで引き当てることを禁じる設計検査の走査範囲へ入れないための意図的な配置」であり、
テンプレートの検査自身がそう説明している。aigenba も同じ名前空間を採った。
**この判断ごと引き継ぐ**（起源 spirux は別配置だが、正典はテンプレート側である）。

## 制約・前提

### 正典の不変条件（削減不可）

1. **メールアドレスで利用者を引かない**。身元表に索引を付けない。
2. **外部取得は必ず SsrfPin の窓口経由**（接続先情報 / 鍵 / トークン交換の 3 経路）。
3. **接続の秘密を扱う前面は登録・更新フォーム 1 本のみ**。受け渡しの型に秘密を載せない。
4. **共通ログイン経路に 2 要素認証を挟まない**（AG-200）。
5. **初回ログインでその場で利用者を作る (always-JIT)**。

### AGENTS.md 側の制約

- セキュリティ不変条件 **8**（外部 URL 取得は SSRF 検査経由 / 境界は `config/ssrf-pin.php` に pin）
- セキュリティ不変条件 **6**（PII は CipherSweet）
- セキュリティ不変条件 **9**（変更系 route は `Gate::authorize` を通すか exemption 登録）
- セキュリティ不変条件 **2 / 10**（子は親に属する = 認可より前に 404。層 2 は binding 直後）
- 禁止事項 **1**（テストなしの実装完了報告）・**2**（PHPStan の widen / baseline）・**4**（`response()->json()` 直書き）

### 依存

- **前段**: `kent013/laravel-ssrf-pin` `^0.4`（別 TODO `ssrf-pin-v04-upgrade`）。
  正典が要求するのは v0.3 の「要求 body 付き取得」だが、aicue は判定層の穴を塞ぐ
  v0.4 を先行させる方針が既に設計済みなので、**^0.4 に依存する**（v0.4 は v0.3 の機能を含む）。
- 別関門 `auth-2fa-org-enforcement` は aicue に実装済み
  (`RequireTwoFactorForEnforcedOrganizations`)。企業ログイン経由でも効く形を保つ。

## スコープ外

- **ソーシャル SSO の作り替え** (`auth-sso-social`)。既に AG-200 の形なので**変更しない**。
  本設計が触るのは「その形を機械で固定する gate を 1 本足す」ことだけである。
- **運営側 SSO** (`auth-admin-sso`)。
- **`acr_values` による認証強度の要求**。AG-200 が「強度を上げたい要件はこれで行う」と
  明記しているが、aicue に該当要件は無い（思考原則 2「今必要なものだけ作る」）。
- **SCIM / 自動デプロビジョニング**。入退社連動はログイン時の JIT までとする。
- **IdP 起点のログイン (IdP-initiated SSO)**。RP 起点のみ。
- **接続を無効にした後の猶予窓**（spirux にだけ設定値があるが強制する仕組みは未実装で、
  正典の形ではない）。
- **`ssrf-pin` の版上げそのもの**（別 TODO）。
- **既存ログイン手段の削除・変更**（`EnsureLoginMethodRemains` の意味論は変えない）。

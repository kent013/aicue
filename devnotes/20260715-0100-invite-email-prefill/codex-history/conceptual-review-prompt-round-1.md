## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件（抜粋）

1. tenant キー不信 / 2. 子は親に属する(nested route は認可より前に 404) / 3. cross-org 不可
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる / 5. 権限判定は laratrust_team_id 明示
6. **PII(email/name)は CipherSweet。検索は whereBlind()** / 7. 課金の冪等性 / 8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。方向性が間違っているなら設計そのものを見直せ。

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
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか
8. **セキュリティ判定の妥当性**: 本設計はメール列挙リスク・token 検証・readonly 採否のセキュリティ判定を含む。その判定が妥当か特に厳しく検証せよ。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は本レビュー対象。関連する既存コードは以下のとおり）

### 既存フロー実装の要点（レビュー判断材料）

- `InvitationAcceptanceController::show()`: 無効招待は非開示ページ、有効招待のみ `session()->put('invitation_token', $token)` して register へ redirect。
- `OrganizationInvitation::generateToken()` = `Str::random(64)`、DB は sha256 の `token_hash` のみ保存。`email` は CipherSweet 暗号化 + blind index。`isExpired/isAccepted/isRevoked` と `scopeActive` あり。
- `CreateNewUser::create()`: session token を fail-secure 解決 → `MatchesInvitationEmail` rule で招待 email と登録 email の一致を検証（不一致は 422）→ `acceptInvitationIfValid()` で招待組織参加。
- `Fortify::registerView` は現在 `socialProviders` のみ props に渡す（invitationEmail は未実装）。
- `Auth/Register.svelte` の email は空初期値・編集可。

---

（ここに conceptual-design.md 全文を貼付）

# 概念設計: invite-email-prefill（招待経由登録フォームでの招待メールアドレス自動入力）

> ユースケース・カバレッジ監査ギャップ #11 / bug-hunt Q-03 (Low, 要確認)。**セキュリティ判定込み**。

## 背景・課題

未ログインで招待リンク (`GET /invitations/accept?token=...`) を開くと、`InvitationAcceptanceController::show()` が
有効招待に対して token を session (`invitation_token`) に fail-secure 保存し、`register` へリダイレクトする (T030 フロー)。

しかし register 画面 (`Auth/Register.svelte`) の email フィールドは**空のまま**で、ユーザーは招待メールと
同一の email を手入力する必要がある。`CreateNewUser` は `MatchesInvitationEmail` rule で招待 email との一致を
サーバ側で強制するため、**タイプミスや別 email 入力をすると 422 (「招待されたメールアドレスと一致しません」) で弾かれ、
招待成立フローを踏み外す**。招待相手が期待する「クリックして登録すれば組織に参加できる」体験が損なわれる。

## セキュリティ判定（設計の最初に実施 — 本 brief の中核）

### (a) 既存フロー (T030) の確認 — 確認済み

- guest が招待リンクを開くと `show()` が `token_hash = sha256(token)` で招待を引き当てる。
- 無効 (不在/取消/受諾済/期限切れ) は理由非開示の `Invitations/Invalid` ページ (token オラクル防止)。
- **有効招待のみ** `session()->put('invitation_token', $token)` して register へ誘導。
- 登録時 `CreateNewUser` が session token を fail-secure 解決 → `MatchesInvitationEmail` で招待 email と一致検証 →
  `acceptInvitationIfValid()` で招待組織へ参加 (email 不一致/失効/取消/受諾済/既メンバーは null → 個人組織 fallback)。
- **結論**: session の `invitation_token` は「その時点で active な招待」に対してのみ設定される。email 突合はサーバ側で二重 (rule + service) に行われる。

### (b) メール列挙 (enumeration) リスク評価 — 新たな漏洩なし

- **token の推測不可能性**: `OrganizationInvitation::generateToken()` は `Str::random(64)` (62^64 空間、CSPRNG)。DB には平文非保存、`sha256` の `token_hash` のみ。総当り・推測は非現実的。
- **有効期限**: `expires_at` を `isExpired()` で判定。期限切れは prefill しない。
- **使用済み判定**: `accepted_at` (`isAccepted()`) / `revoked_at` (`isRevoked()`)。受諾済・取消済は prefill しない。
- **漏洩分析**: プリフィルする email は「active な招待 token の保持者」にのみ返る。その token を持てるのは招待メールを受け取った当人 = **自分の email を既に知っている招待相手**。よって新たな PII 漏洩を生まない。既存の `MatchesInvitationEmail` の 422 メッセージも、token 保持者に対して「この email が招待先である」ことは既に暗黙に開示している (不一致で弾かれる = 正解 email が別に存在する)。プリフィルはその情報を UX として顕在化するだけで、**列挙面 (enumeration surface) を広げない**。
- **セキュリティ不変条件 #6 (PII は CipherSweet / whereBlind)** との整合: これは at-rest 暗号化と検索方法の規約であり、当人へのレスポンス配信を禁じるものではない。既に `Auth/ResetPassword` は query 由来 email を client props に渡しており、login/register も validation error で email をエコーバックする。**当人 (token 保持者) に平文を返すこと自体は既存パターンと同型**で不変条件に抵触しない。
- **結論**: プリフィルは妥当。実装する。

### (c) 編集可 vs 読み取り専用 (ロック) — 読み取り専用 (ロック) を採用

- サーバ側 `MatchesInvitationEmail` は「session token がある間」は招待 email 以外の登録を**既に拒否**する。したがって「別 email で登録する自由」は**現状フローに存在しない** (別 email を入れても 422)。
- email を編集可能にすると、ユーザーが値を変えられてしまい → サーバで 422 → 「なぜ弾かれるのか」の混乱を生む (今回直そうとしている踏み外しを再現する)。
- **読み取り専用 (readonly)** にすれば、サーバ契約 (招待 email 固定) を UI で正直に表現でき、踏み外しを構造的に防げる。
- DESIGN.md 禁止事項 #8 (「必須条件未充足を理由にボタンを disabled にする UI」) は**送信ボタンの disabled**に関する規約で、readonly な prefill 入力とは別物 (抵触しない)。
- 招待を無視して別 email で個人登録したいユーザーは、**招待リンクではなく通常の `/register` を開けばよい** (session に token が無く従来どおり空・編集可)。招待リンク経由という文脈では email 固定が正しい役割。
- **結論**: 招待 token 由来の prefill 時のみ **readonly (ロック)**。token 無しの通常登録は従来どおり空・編集可。

## 改善アイデア

register 画面のサーバ側 (`Fortify::registerView` closure) で session の `invitation_token` を解決し、
**active な招待に限り**招待 email を Inertia props (`invitationEmail`) として `Register.svelte` に渡す。
Svelte 側は props があれば form.email を初期化し、当該フィールドを **readonly** で描画する。token 無し (通常登録) は従来どおり空・編集可。

## 期待効果

- **使命への貢献**: 招待は「現場チームを AI-CUE に引き込む」導線。招待相手が確実に組織へ参加でき、標準作業マニュアル生成の協働に到達しやすくなる (オンボーディング摩擦の除去)。
- 招待経由登録の踏み外し (email 不一致 422) を構造的に排除。
- 追加の攻撃面・PII 漏洩なし (上記判定 b)。

## 実装方針（概要）

1. **招待 email 解決の集約**: session token → active 招待 → 平文 email を返す薄い read-model resolver を用意する
   (`MatchesInvitationEmail` / `acceptInvitationIfValid` と同じ「token_hash 照合 + active 判定」ロジックの再利用/整合)。
   Fortify view closure は provider 内の static closure のため、`app()` 解決 or `Request` 経由で resolver を呼ぶ。
2. **Fortify::registerView** の props に `invitationEmail` (string|null) を追加。active 招待が無ければ null。
3. **Register.svelte**: `invitationEmail?: string | null` prop を受け、あれば `useForm` の email 初期値に採用し、
   email `Input` を `readonly` (+ 補足ラベル「招待先メールアドレス」) で描画。null なら従来挙動。
   波及: TypeScript の Props interface 更新、`tests/js/pages/Register.test.ts`。
4. 通常登録・SSO 登録・空 session 経路の非退行を維持。

## 制約・前提

- Inertia props (register view) は既存実装が plain array を返しており、`response()->json()` 禁止 (不変条件 #4) は Inertia に非適用。DTO 化は過剰 (思考原則: 今必要なものだけ)。ただし型安全のため resolver の戻り値型は `?string` で明示。
- 招待 email は CipherSweet 暗号カラム。model 属性アクセス (`$invitation->email`) で自動復号される (追加復号処理不要)。
- token が session に入るのは有効招待のみだが、register 到達までの間に失効/取消/受諾され得るため、resolver は **その場で active 再判定**する (stale prefill 防止)。
- PHPStan L10 / Pest / RefreshDatabase グローバル / Factory 生成を遵守。

## スコープ外（v1 スコープ doc/10 尊重・過剰実装しない）

- ログイン済ユーザーの招待受諾画面 (`Invitations/Accept`) の変更 (別フロー・既に token 明示)。
- email 以外の項目 (name 等) のプリフィル (招待に name 情報を持たない)。
- 招待メール文面・通知の変更。
- 「招待を無視して別 email 登録」を招待リンク経由で許す新フロー (現状サーバ契約に無く、通常 `/register` で代替可能)。
- 署名付き URL 化・token TTL 変更等、招待 token 機構そのものの再設計 (既存 T030 を尊重)。


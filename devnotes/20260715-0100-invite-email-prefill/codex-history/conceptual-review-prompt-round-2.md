# 概念設計レビュー Round 2

Round 1 の指摘 (Critical×2, Warning×多数) に対し概念設計を改訂しました。以下の対応を確認し、全体判定を再評価してください。

## Round 1 指摘への対応サマリー

### [Critical] stale token の GET/POST 不整合 → 対応
resolver `resolveRegisterPrefillEmail(Session): ?string` が **stale/invalid token を GET 時点で session から forget** する契約を追加。
これにより GET (prefill 判定) と POST (`CreateNewUser`) の招待フロー扱いが一致する。stale token の Feature テストを計画に追加。

### [Critical] 「新たな漏洩なし」は楽観的すぎる → 主張撤回・リスク受容へ
判定 (b) を「漏洩ゼロ」から「**有効 token 所持者への招待先 email (PII) 開示面の追加を限定的リスクとして受容**」に書き換え。
受容根拠: (1) 開示は当人の自分の email (第三者 PII でない)、(2) 推測不可能・期限付き・単回使用の active token 保持が条件、
(3) 招待リンクの email プリフィルは業界標準 onboarding パターン、(4) 追加の平文 email 検索 (whereBlind/平文 where) は導入せず token_hash 照合のみ。
列挙面 (enumeration surface) は広げないことは維持。

### [Warning] テスト粒度 → Feature テスト必須を計画化
active→prop あり / expired・revoked・accepted→null かつ session forget / 通常登録非退行 / SSO 表示非退行 / stale token。

### [Warning] Input atom readonly 透過 → 確認済 (atom 変更不要)
`Input.svelte` は `{...rest}` を native input に spread。`readonly` (HTMLInputAttributes) が透過する。

### [Warning] 「422 を構造的に排除」→ 「主経路の手入力ミス起因 422 を削減」に緩和
SSO 不一致・stale token では依然 fallback し得る限界を明記。

### [Warning] readonly の根拠「通常 /register を開けばよい」→ 撤回・明文化
active token が session に残る限り /register でも再 lock される点を認め、誤根拠を撤回。
「招待リンク経由セッションは招待先 email に固定 = 既存サーバ契約 (`MatchesInvitationEmail`) と一致」と明文化。
「別 email 登録への切替導線」は現行サーバ契約にも無い別機能のため **out-of-scope (意識的な既存制約継承)** に明記。

### [Warning] 判定ドリフト → 単一解決口に集約
`OrganizationInvitation::findActiveByPlainToken(string $token): ?self` (token_hash 照合 + active 判定) に集約し、
`MatchesInvitationEmail` / `acceptInvitationIfValid()` の重複クエリも寄せる (granular メッセージが要る POST `acceptInvitation()` は対象外)。

### [Suggestion] 効果の射程 → onboarding 摩擦低減に粒度調整済

---

## 改訂後の概念設計 (全文)

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

### (b) メール列挙 (enumeration) リスク評価 + PII 開示面のリスク受容

- **token の推測不可能性**: `OrganizationInvitation::generateToken()` は `Str::random(64)` (62^64 空間、CSPRNG)。DB には平文非保存、`sha256` の `token_hash` のみ。総当り・推測は非現実的。
- **有効期限**: `expires_at` を `isExpired()` で判定。期限切れは prefill しない。
- **使用済み判定**: `accepted_at` (`isAccepted()`) / `revoked_at` (`isRevoked()`)。受諾済・取消済は prefill しない。
- **列挙面 (enumeration surface) は広げない**: プリフィルは「active token の照合成功時にのみ email を返す」もので、任意 email の存在有無を問い合わせる口を新設しない。token を推測できない以上、攻撃者が任意アドレスの登録有無を列挙する手段にはならない。
- **ただし PII 開示面は増える (楽観視しない)**: 従来レスポンスは招待先 email の**平文そのもの**は返していない (422 は「別の正解 email がある」ことを暗示するに留まる)。本設計は Inertia props で **exact な招待先 email を平文で返す**。`token 保持者 = 招待相手本人` の前提は、リンク転送・共有端末・メール誤送信・肩越しの覗き見で崩れうる。よって本変更は「**有効 token 所持者に対する招待先 email (PII) の開示面の追加**」である。
- **リスク受容の判断**: この開示面の追加は以下により**限定的・許容可能**と判断し、明示的にリスク受容する。(1) 開示対象は当人の**自分の email** であり第三者 PII ではない。(2) 開示条件は「推測不可能・期限付き・単回使用の active token を URL に保持していること」に限定される。(3) 招待リンク経由の email プリフィルは Slack/GitHub 等でも採用される業界標準の onboarding パターン。(4) セキュリティ不変条件 #6 (PII は CipherSweet at-rest + 検索は whereBlind) は**保存と検索**の規約であり当人へのレスポンス配信を禁じない。既に `Auth/ResetPassword` は email を client props に渡し、login/register も validation error で email をエコーバックする既存同型パターンがある。**追加の平文 email 検索 (whereBlind/平文 where) は導入しない** (token_hash 照合のみ)。
- **結論**: 「新たな漏洩ゼロ」ではなく「**有効 token 所持者への招待先 email 開示という限定的リスクを受容した上で妥当**」と判定。実装する。

### (c) 編集可 vs 読み取り専用 (ロック) — 読み取り専用 (ロック) を採用

- サーバ側 `MatchesInvitationEmail` は「session token がある間」は招待 email 以外の登録を**既に拒否**する。したがって「別 email で登録する自由」は**現状フローに存在しない** (別 email を入れても 422)。readonly はこの**既存サーバ契約を UI で正直に表現する**もので、ユーザーの選択肢を新たに奪うものではない。
- email を編集可能にすると、ユーザーが値を変えられてしまい → サーバで 422 → 「なぜ弾かれるのか」の混乱を生む (今回直そうとしている踏み外しを再現する)。readonly なら踏み外しを構造的に防げる。
- DESIGN.md 禁止事項 #8 (「必須条件未充足を理由にボタンを disabled にする UI」) は**送信ボタンの disabled**に関する規約で、readonly な prefill 入力とは別物 (抵触しない)。
- **「別 email で登録したければ通常 `/register` を開けばよい」は誤り (Round 1 指摘を受け撤回)**: session に active token が残る限り、同一ブラウザで `/register` を開いても再び prefill+lock される (session モデル上そうなる)。したがって「招待リンク経由セッションでは招待先 email に固定される」ことを**製品仕様として明文化**する。これは既存サーバ契約 (`MatchesInvitationEmail`) と一致する挙動であり、本変更で新たに能力を奪ってはいない。
- **招待を破棄して別 email で登録する「切替導線」は out-of-scope (意識的な既存制約の継承)**: それを許すには session token を明示破棄する新導線が必要だが、現行サーバ契約にも存在しない別機能であり、v1 では作らない (過剰実装回避)。既知の制約としてスコープ外に明記する。
- **結論**: 招待 token 由来の prefill 時のみ **readonly (ロック)**。token 無しの通常登録は従来どおり空・編集可。

## 改善アイデア

register 画面のサーバ側 (`Fortify::registerView` closure) で session の `invitation_token` を解決し、
**active な招待に限り**招待 email を Inertia props (`invitationEmail`) として `Register.svelte` に渡す。
Svelte 側は props があれば form.email を初期化し、当該フィールドを **readonly** で描画する。token 無し (通常登録) は従来どおり空・編集可。

## 期待効果

- **使命への貢献 (射程を正確に)**: 招待は「現場チームを AI-CUE に引き込む」導線の**オンボーディング摩擦低減**にあたる (動画シナリオ生成・PWA 撮影という本丸機能そのものの改善ではない)。招待相手が組織へ参加しやすくなり、標準作業マニュアル生成の協働に到達しやすくなる。
- **主経路 (メール+パスワード登録) の手入力ミス起因の 422 を削減**する (「構造的排除」ではない: SSO 経由のメール不一致や、GET→POST 間の token stale 化では依然 fallback し得る。ただし stale 時は session token を破棄して通常登録に一本化する — 実装方針参照)。
- 列挙面は広げない。PII 開示面の追加は判定 (b) の通り限定的リスクとして受容済み。

## 実装方針（概要）

1. **active 招待解決の単一化 (判定ドリフト防止 — Round 1 指摘)**: 「plain token → active 招待」の照合を
   モデルの単一メソッド `OrganizationInvitation::findActiveByPlainToken(string $token): ?self`
   (`token_hash = sha256(token)` 照合 + `scopeActive` 相当の active 判定) に集約し、
   既存の `MatchesInvitationEmail` と `acceptInvitationIfValid()` の重複クエリもこれに寄せる
   (POST 受諾の `acceptInvitation()` は revoked/accepted/expired を個別メッセージに出し分けるため対象外)。
   追加の平文 email 検索 (whereBlind/平文 where) は導入しない。
2. **register prefill resolver + stale token 破棄契約 (Round 1 Critical)**: `OrganizationMembershipService` に
   `resolveRegisterPrefillEmail(Session): ?string` を追加。session の `invitation_token` を fail-secure に読み、
   (a) 非文字列/空 → forget して null、(b) `findActiveByPlainToken` が null (不在/失効/取消/受諾済) → **session から forget** して null、
   (c) active → `$invitation->email` (CipherSweet 自動復号) を返す。**stale/invalid token を GET 時点で session から破棄する**ことで、
   「UI は通常登録に見えるのにサーバは招待フロー扱い」の不整合を除去し、GET (prefill 判定) と POST (`CreateNewUser`) の契約を一致させる。
3. **Fortify::registerView** closure が `Request` 経由で上記 resolver を `app()` 解決して呼び、props に
   `invitationEmail` (string|null) を追加。active 招待が無ければ null。
4. **Register.svelte**: `invitationEmail?: string | null` prop を受け、あれば `useForm` の email 初期値に採用し、
   email `Input` を `readonly` (+ 補足ラベル「招待先メールアドレス」) で描画。null なら従来挙動。
   `Input` atom は `{...rest}` を native input へ透過するため `readonly` 対応済 (atom 変更不要 — 確認済)。
   波及: TypeScript の Props interface 更新、`tests/js/pages/Register.test.ts`。
5. 通常登録・SSO 登録・空 session 経路の非退行を維持。

## 制約・前提

- Inertia props (register view) は既存実装が plain array を返しており、`response()->json()` 禁止 (不変条件 #4) は Inertia に非適用。DTO 化は過剰 (思考原則: 今必要なものだけ)。ただし型安全のため resolver の戻り値型は `?string` で明示。
- 招待 email は CipherSweet 暗号カラム。model 属性アクセス (`$invitation->email`) で自動復号される (追加復号処理不要)。
- token が session に入るのは有効招待のみだが、register 到達までの間に失効/取消/受諾され得るため、resolver は **その場で active 再判定**する (stale prefill 防止)。
- PHPStan L10 / Pest / RefreshDatabase グローバル / Factory 生成を遵守。

## スコープ外（v1 スコープ doc/10 尊重・過剰実装しない）

- ログイン済ユーザーの招待受諾画面 (`Invitations/Accept`) の変更 (別フロー・既に token 明示)。
- email 以外の項目 (name 等) のプリフィル (招待に name 情報を持たない)。
- 招待メール文面・通知の変更。
- **「招待を破棄して別 email で登録する」切替導線** (session token 明示破棄 UI): 現行サーバ契約 (`MatchesInvitationEmail`) にも存在しない別機能。既知の制約として意識的にスコープ外とする (判定 c 参照)。
- 署名付き URL 化・token TTL 変更等、招待 token 機構そのものの再設計 (既存 T030 を尊重)。
- SSO (social) 経由の招待成立時のメール不一致ハンドリング改善 (本変更はメール+パスワード登録の prefill に限定。SSO は provider 側 email が確定するため prefill 対象外)。


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

**追加の実測 (2026-08-25)**: `show()` の分岐順は「無効判定 → guest 分岐 (session 保存 +
register 誘導) → 組織 Assert」なので、**guest + 論理削除組織**では 500 にすらならず
token が session に保存され、register の prefill に招待宛先 email が出た上で
登録 POST (`acceptInvitationIfValid` の Assert) が 500 で丸ごと失敗する経路が現存する。
畳み込みは **guest 分岐より前**で行わなければならない。

- **単一解決口での畳み込み**: `OrganizationInvitation::findActiveByPlainToken()` に
  `->whereHas('organization')` を足す (SoftDeletes の default scope により論理削除済み組織宛は
  「active でない」= null に畳まれる。アプリ内受諾の `scopeActivePendingForEmail` が既に持つ
  条件と同じ意味論)。これで同メソッドを共有する 3 利用者 (`MatchesInvitationEmail` /
  `acceptInvitationIfValid` / register prefill 解決) が一括で畳まれ、prefill は stale token を
  session から破棄する既存動線に乗る。`scopeActive` 自体は変えない (招待行の状態だけを表す
  scope として維持し、`activePendingForEmail` との条件重複を作らない)。
- 単一解決口を通らない 2 経路は `Assert::isInstanceOf` を**組織生存の明示判定**へ置き換える:
  - `show()`: 無効分岐 (理由非開示の `Invitations/Invalid` ページ直描き、guest 分岐より前) に
    「招待元組織が生きていない」を合流させる。タイトル・文言・ステータス (200) も
    他事由と完全に同一にする (出し分け = オラクル)。
  - `acceptInvitation()` (POST): 生存判定 3 つの後・宛先照合の前で、組織 null を不在・取消済みと
    同じ中立メッセージ `'この招待は無効です。'` の ValidationException へ畳む。
  - `acceptInvitationIfValid()`: 解決口の変更後も防御的に組織 null → return null
    (個人組織生成へ fallback。解決〜組織参照の間の削除 race も 500 にしない)。
- **ロック下の最終権威**: `joinOrganization()` は**冒頭の `lockForMembershipWrite` で既に
  organizations 行の `lockForUpdate` を取得しており** (canonical 順序 users 昇順 →
  organizations、その後に招待行ロック)、組織の論理削除 (= 同じ organizations 行の UPDATE) は
  この行ロックで直列化される。足りないのは**ロック取得後の生存の再読取**だけなので、
  招待行ロック下の再検証に「organizations を default scope (論理削除除外) で whereKey →
  exists 再確認」を追加する。false は既存の受諾不能契約へ畳む (全呼び出し元が消費済み)。
  ロック順序は一切変えない (新しい順序を作らない = デッドロックを導入しない。
  i2 の「招待行の行ロックの下で受諾可能状態と**招待元組織の生存**を再検証」に一致)。
- テスト: 各経路の畳み込みに加え、TOCTOU 再現 (事前検証を通過させた後に組織を論理削除して
  `joinOrganization` 相当へ到達させ、受諾不能に畳まれ membership 行が作られないこと) を
  既存テスト「受諾済み招待で joinOrganization 相当に到達しても no-op」と同じ手法で固定する。
- 応答の form は既存の「同一 route 内でのページ直描き」を維持する (正典形の「専用 route への
  差し戻し」への追従は s5 で form の差として許容されており、今回は要求されていない —
  スコープ最小)。`acceptPendingInvitation` (アプリ内受諾) は scope + ロック下再解決で
  既に畳めているため変更しない。

### 施策 B (i11 + i14): 継続クラス `InvitationContinuation` の導入と鍵一本化の機械検査

- テンプレート (laravel-claude-template@5dd85a6) の `app/Support/Auth/InvitationContinuation.php`
  を参照実装として移植する。公開契約は
  `remember(Session $session, string $token): void` /
  `resolve(Session $session): ?string` / `forget(Session $session): void` の 3 メソッド。
  session の戻り値 `mixed` の型絞り込み (非文字列・空文字は忘れさせて null) は
  `resolve()` の内部に閉じ、呼び出し側へ `mixed` を漏らさない (PHPStan level 10)。
  `Session` は**メソッド引数**で受ける (テンプレ正典形。クラスは `final readonly` の無状態で、
  「書き込みメソッドを呼ぶ受け手は型で示す」規約に従う — constructor へ session を注入すると
  リクエスト外文脈での解決が壊れやすく、テンプレの機械検査と形も割れるため採らない)。
  型衛生 (非文字列・空文字・配列・数値 → forget して null) は Unit テストで固定する。
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
  をテンプレートから移植 (app/ 配下の PHP を token_get_all で走査し、
  `T_CONSTANT_ENCAPSED_STRING` の値が `invitation_token` に一致するファイルを
  継続クラス 1 ファイルへ pin。判定は `trim($token[1], '\'"')` で**単・二重引用符の両方**を
  復元して比較する — テンプレ実装が既にこの形)。負例 IC-2 (コメント中の言及は数えない /
  literal は数える) に**二重引用符の literal も数える**正例を追加する。
  docblock に保証しないもの (動的に組み立てた鍵・別名の鍵・tests/ 配下) を明記する
  (テンプレの docblock を踏襲)。
  AGENTS.md「走査器・gate を新設するときの 4 点」(負例と正例 / fail-closed /
  母集団の非空 / docblock の保証範囲) を満たす — 期待値が「ちょうど [SoT の 1 ファイル]」の
  完全一致なので、走査が空振りすれば空配列 ≠ [SoT] で赤になる (母集団の非空を判定が内包)。

### 施策 C (i16): 招待経由登録への verified 付与

- `CreateNewUser::create()` の登録トランザクション内で、招待受諾が成立した
  (`acceptInvitationIfValid` が Organization を返した) ときに限り
  `email_verified_at` を明示 `forceFill(...)->save()` で立てる (`terms_accepted_at` と
  同じ流儀。user 行は直前に自分が INSERT した行で、joinOrganization のロック下再照合を
  通過済み)。`Illuminate\Auth\Events\Verified` は**発火しない** — この event の意味論は
  「確認フローを完了した」であり登録時付与とは別 (framework の `markEmailAsVerified()` 自体も
  event を発火しない実装 = forceFill + save のみ、2026-08-25 vendor 実読)。aicue に
  `Verified` の listener は 1 つも無い (実測。`PasskeyVerified` は別 event)。
  この判断は実装コメントに残す。
- tx 内で verified を立てるため、Fortify が登録後 (tx 外) に発火する `Registered` イベントの
  `SendEmailVerificationNotification` listener (framework の既定配線を vendor 実読で確認:
  `hasVerifiedEmail()` = true なら送らない) は確認メールを送らない —
  「確認メールを別送しない」が既存機構のまま成立する。Notification fake で
  「招待経由登録では VerifyEmail 通知が 1 通も送られない」を Feature テストに固定する
  (将来の Fortify/framework 変更への防波堤)。
- **登録直後の着地 (波及変更)**: `RegisterResponse` (Fortify contract bind) は現在
  無条件に `verification.notice` へ redirect する。verified で作られた招待登録者は
  Fortify の prompt controller が `fortify.home` (= `/go` = `app.entry`) へ再 redirect する
  ため詰みはしないが、無用な 1 hop と「認証してください」画面への一瞬の遷移を残さないよう、
  `RegisterResponse` に「`hasVerifiedEmail()` = true なら `app.entry` へ直接 redirect」の
  分岐を明示する (XHR 201 経路と既存の pending プラン意図の分岐は不変。
  招待経由は既存の else 分岐 = pending forget 側に既に居る)。
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
| A (i7) | `app/Models/OrganizationInvitation.php` (findActiveByPlainToken) / `app/Http/Controllers/Organizations/InvitationAcceptanceController.php` / `app/Services/Organization/OrganizationMembershipService.php` / テスト新設 `tests/Feature/Organizations/InvitationDeletedOrganizationTest.php` (名称はテンプレートの検査に揃える) |
| B (i11+i14) | 新設 `app/Support/Auth/InvitationContinuation.php` / 新設 `tests/Architecture/InvitationContinuationKeySoTTest.php` / `InvitationAcceptanceController.php` / `app/Actions/Fortify/CreateNewUser.php` / `OrganizationMembershipService.php` / 既存テスト (`RegistrationInvitationPrefillTest` 等) の session 鍵利用は tests/ 側なので走査対象外・変更不要 |
| C (i16) | `app/Actions/Fortify/CreateNewUser.php` / `app/Http/Responses/Fortify/RegisterResponse.php` (verified 時の着地) / 新設 `tests/Feature/Auth/InvitationRegistrationVerifiedTest.php` / 既存 `tests/Feature/Organization/InvitationTest.php` の fallback テストへ unverified 検証を追記 |

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
  props 契約は変えない (i7 は既存 Invalid ページへの合流、i16 は server 側 +
  RegisterResponse の redirect 先のみで、画面の追加・props の変更は無い)。

## スコープ外

- 正典形 (i7 の「専用 route への差し戻し」form) への転換 — s5 が form の差として許容。
- 外部ログイン (SSO) 経路の招待継続の新設 — settle の aicue 未達一覧に無い。
  aicue の SSO は企業 SSO (EnterpriseUserProvisioner) で招待とは別導線。
- i17 (署名 URL の撤去) — aigenba 固有の追従対象で aicue は元から素の token URL。
- アプリ内受諾 (`auth-invitation-in-app-discovery`) — 別 feature (裁定 AG-079)。
- 受諾時の verified **要求** — AG-214 が「しない (現状維持)」と裁定済み。
- TODO 登録 — 本設計はファイル生成のみ (呼び出し元の指示)。

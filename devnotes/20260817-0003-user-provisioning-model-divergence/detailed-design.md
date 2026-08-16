# 詳細設計: user-provisioning-model-divergence (ユーザー登録方式の要件差の評価)

> **本タスクの成果物は「実装しない」という判断そのものである。**
> アプリのコード変更は **0 件**。本書は「なぜ今作らないのか」「どうなったら作るのか」
> 「作るときは何をどの順で作るのか」を、実装できる粒度で残す設計書である。
> 作法は T193 (`devnotes/20260816-1754-video-manual-visibility-scope/detailed-design.md`) に揃える。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → `PromptDefense` → `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール (将来実装する場合に適用されるもの)

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** はグローバル適用、個別 `DatabaseTransactions` 禁止
- テストデータは必ず Factory で生成。新モデルには Factory 作成も施策に含める
- **DTO + JsonResource** パターン
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- **PII(email/name)は CipherSweet。検索は `whereBlind()`**(セキュリティ不変条件)
- **後方互換の並走を残さない**(思考原則 3)

## 概念設計リファレンス

- `devnotes/20260817-0003-user-provisioning-model-divergence/conceptual-design.md`
  (Codex 概念設計レビュー **Round 1 で APPROVED**。Warning 4 件は全件反映済み)

---

## 1. 判断

**今は作らない (テンプレート基盤の設計差として維持し、Conditional として条件付き保留する)。**

- `should_implement = false`
- **アプリコードの変更は 0 件**。`app/` `resources/` `routes/` `database/` `config/` `tests/` を
  1 行も触らない。
- `docs/template-divergence.md` への**新規登録も行わない** (§7 で根拠を示す)。
- 本タスクが生む Open タスクは無い。`docs/TODO.md` へは **Conditional** として登録する
  (登録操作は後続の別エージェントの責務。本文の草案は §7-1)。
- 別途起票できる**独立した小改善が 1 件**ある (最終ログイン日時の表示。§6-5)。
  本タスクの結論には紐付けない。

---

## 2. 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| — | **実装施策は 0 件** | なし | — |

### 非実装の成果物 (本タスクが残すもの)

| # | 成果物 | 置き場所 | 目的 |
|---|--------|---------|------|
| N1 | 概念設計 (判断の根拠・現行コードの実読事実) | `devnotes/20260817-0003-user-provisioning-model-divergence/conceptual-design.md` | 同じ議論の再燃時に、コードを読み直さずに前提へ到達できるようにする |
| N2 | 詳細設計 (本書。参照設計・昇格手順・登録判断) | 同 `detailed-design.md` | 昇格したときに設計をやり直さない |
| N3 | Codex 合議履歴 | 同 `codex-history/` | 判断の外部レビュー痕跡 |
| N4 | Conditional 登録用の本文 | 本書 §7-1 | 後続の登録エージェントがそのまま使う |
| N5 | ブリーフ前提の訂正 4 件 | 概念設計 §0 | 誤った前提のまま次の判断が積み上がるのを防ぐ |

---

## 3. 変更箇所 / 波及変更

### 変更箇所

**なし。** 本タスクで書き換えるファイルは `devnotes/` 配下のみである。

### 波及変更

| 種別 | 内容 |
|---|---|
| TypeScript 型定義 | **なし** (`resources/js/types/admin.ts` の `MemberRow` も触らない) |
| Inertia Props | **なし** |
| API Resource / DTO | **なし** (`MemberRowData` / `InvitationRowData` とも不変) |
| テストファイル | **なし** (既存テストの追加・変更・削除をいずれも行わない) |
| migration | **なし** (`users` へ `login_id` / `last_login_at` を追加しない) |
| config | **なし** (`config/fortify.php` の `'username' => 'email'` を維持) |
| ドキュメント | **なし** (`docs/template-divergence.md` への登録も行わない。§7) |

> **これは「変更が無いこと」自体が成果物である**。要件書 (`doc/02` `doc/04` `doc/05`) と
> 実装の差を見て反射的にカラムを足す変更を**入れない**ことが本タスクの結論である。

---

## 4. 何が満たされているから不要なのか (現行コードによる裏付け)

### 4-1. 要件項目ごとの充足マトリクス

「満たされている」と「別形式で満たされている」と「意図的に採らない」を区別する。

| 要件 (出典) | 現行の状態 | 判定 | 根拠 (実読) |
|---|---|---|---|
| ユーザー一覧表示 (`doc/04 §4.2`) | `/manage/users` が name / email / 役割 / 2FA 状態を表示 | **満たされている** | `app/Http/Controllers/Admin/UserManagementController::index` / `app/DataTransferObjects/Admin/MemberRowData` |
| 新規登録 (`doc/04 §4.2`) | 招待 → 本人登録。管理者の直接発行は無い | **意図的に採らない** | `OrganizationMembershipService::inviteMember` / `Actions/Fortify/CreateNewUser`。`docs/template-divergence.md` D8 が reconcile 済み |
| 編集 = 役割 (`doc/04 §4.2`) | 3 値遷移コマンドで変更可 | **満たされている** | `OrganizationMembershipService::applyConsoleRole` / `App\Enums\AdminConsoleRole` |
| 編集 = 表示名・メール (`doc/04 §4.2`) | 管理者からは不可 (本人の Settings のみ) | **意図的に採らない** | D8 の PII 最小化判断 |
| 削除 (`doc/04 §4.2`) | 組織からの除名は可。users 行の削除は本人の退会予約のみ | **v1 の組織運用上は除名で足りる** (要件が users 行の物理削除を意味していた場合は未充足) | `OrganizationMembershipService::removeMember` / `requestAccountDeletion` |
| `ユーザーID` 英数 20 字・重複不可 (`doc/02 §2.4`) | 列が無い。識別子は email | **表現できない** | `database/migrations/0001_01_01_000000_create_users_table.php` / `config/fortify.php` `'username' => 'email'` |
| パスワード 半角英数 8〜16 字 (`doc/04 §4.2`) | 12 字以上 + 大小混在 + 数字 + HIBP | **意図的に上書き (要件より強い)** | `app/Support/PasswordPolicy` |
| メールアドレス = 任意 (`doc/02 §2.4`) | **必須**かつ認証識別子 | **逆転している** | `CreateNewUser` の rules / `EncryptedUserProvider` |
| 最終ログイン日時 (`doc/02 §2.4`) | カラムは無いが **`security_audit_events` から索引付きで導出可能**。UI 露出は無い | **監査イベントから導出可能。ただし管理画面要件としては未表示 (= 未充足)** | `2026_06_11_071300_create_security_audit_events_table.php` (index `['user_id','event_type']` と `occurred_at`) / `app/Listeners/RecordSecurityEvent` (`SecurityEventType::Login`) |
| 所属 ID (`doc/02 §2.4`) | `Organization` へ写像済み | **満たされている** | `User::organizations()` |
| 権限 (管理者・一般) (`doc/02 §2.4`) | `OrganizationRole` + `ProjectRole` + Policy | **満たされている (doc/10 §10.5 の確定値)** | `App\Enums\OrganizationRole` / `App\Enums\ProjectRole` |
| ユーザー ID + パスワードでログイン (`doc/05 §5.2`) | email + パスワード / パスキー / SSO | **識別子が違う** | `config/fortify.php` |

**確定仕様との関係**: `doc/10 §10.8` が「実装時はこの節が §10.1〜§10.7 に優先する」と宣言する
確定仕様であるにもかかわらず、doc/10 は認証について
「撮影アプリは PWA/Web（同一オリジン・セッション認証）」と「§10.5 ロール」しか定めていない。
**識別子方式は確定仕様に含まれていない。**

### 4-2. 現行コード (認可・認証の入口の全体像)

#### (a) 認証識別子は email に固定されている

`config/fortify.php`:

```php
'username' => 'email',
'email' => 'email',
'lowercase_usernames' => true,
'passwords' => 'users',
```

`app/Auth/EncryptedUserProvider.php` (email だけが blind index 検索になる):

```php
foreach ($credentials as $key => $value) {
    if (str_contains($key, 'password')) { continue; }
    if ($key === 'email') {
        Assert::string($value);
        $query->whereBlind('email', 'email_index', $value);
    } else {
        $query->where($key, $value);
    }
}
```

#### (b) `users` に識別子列も最終ログイン列も無い

`database/migrations/0001_01_01_000000_create_users_table.php` が持つのは
`id` / `name`(text, 暗号化) / `email`(text, 暗号化) / `email_verified_at` /
`password`(nullable) / `terms_accepted_at` / `consent_version` / `rememberToken` / timestamps のみ。
email の一意性はマイグレーションのコメントどおり
**`blind_indexes` テーブルの partial unique + 登録時の `whereBlind` 明示チェック**で担保している。

#### (c) 撮影 PWA はメール検証済みでなければ 1 画面も開けない

`routes/web.php` L191:

```php
Route::middleware(['auth', 'verified', 'not-pending-deletion'])->group(function (): void {
```

撮影 PWA の group (`->prefix('app')->as('capture.')`) はこの内側にあり、
さらに `require-active-subscription` / `project.in-current-org` が重なる。
`User implements MustVerifyEmail` かつ `config/fortify.php` に `Features::emailVerification()`。

#### (d) 招待の受け渡し手段はメールだけ (平文 token は DB に無い)

`OrganizationMembershipService::inviteMember()`:

```php
$plainToken = OrganizationInvitation::generateToken();
$invitation->forceFill([
    'role' => $role->value,
    'token_hash' => OrganizationInvitation::hashToken($plainToken),
    'expires_at' => now()->addDays(self::EXPIRES_DAYS),
]);
$invitation->save();

Notification::route('mail', $email)->notify(new OrganizationInvitationNotification(
    organizationName: $organization->name,
    acceptUrl: url('/invitations/accept?token='.$plainToken),
));
```

アプリ内受諾 (`AcceptInvitationInAppController` / 裁定 AG-113) の受諾根拠は
**「auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先」** (`pendingInvitationsQuery`) であり、
`routes/web.php` のコメントが明記するとおり
**「未登録の人にはメールが唯一の入口」**である。

#### (e) パスキーは email に依存していない (ブリーフ前提の訂正)

`vendor/laravel/passkeys/src/PasskeyAuthenticatable.php`:

```php
public function getPasskeyUserHandle(): string
{
    return hash_hmac('sha256', $this->getTable().'|'.$this->getKey(),
        Config::string('passkeys.user_handle_secret'), binary: true);
}
```

= **テーブル名 + 主キー**の hmac。識別子を email から別の列へ変えても
**登録済みパスキーは 1 件も無効にならない**。`PASSKEYS_USER_HANDLE_SECRET` の運用要件
(AGENTS.md) が守っているのは `APP_KEY` ローテートに対する耐性であり、識別子の話ではない。
TOTP 2FA も `users.two_factor_secret` に閉じており email 非依存。

#### (f) 日常ログインはパスキーで入力を大きく減らせる (条件付き)

`vendor/laravel/passkeys/src/Http/Controllers/PasskeyLoginController::index` は
`GenerateVerificationOptions` を**引数なし**で呼び、
`allowCredentials(null)` が `[]` を返すため **discoverable credential (ユーザー名入力なし) ログイン**になる。
`config/fortify.php` に `Features::passkeys(['confirmPassword' => false])`。

**成立条件 (誇張しない)**: (1) パスキー登録済み / (2) 端末・ブラウザが対応 /
(3) TOTP 2FA が強制されていない (`app/Services/Auth/PasskeyLoginPolicy` が
TOTP confirmed を拒否する) / (4) `userVerification=REQUIRED` のため端末の生体・PIN が使える。

### 4-3. 入れた場合に壊れる既存の前提

| 前提 | 壊れ方 |
|---|---|
| `config/fortify.php` の `'username' => 'email'` と `EncryptedUserProvider` の email 分岐 | 識別子の置換で両方を同時に書き換える必要がある |
| email の一意性 = `blind_indexes` の partial unique | 新識別子を平文列にすると通常 unique が使えるが、識別子が PII 相当になった瞬間にセキュリティ不変条件 6 (PII は CipherSweet / 検索は `whereBlind`) と衝突する |
| `password_reset_tokens.email` (主キー) + Fortify `'passwords' => 'users'` broker | メールが任意になると**本人の自力回復手段が消える**。代替は管理者リセット = 権限集中の恒久化 |
| アプリ内受諾の根拠「ログイン者 email = 招待宛先」(裁定 AG-113) | email が任意になると**受諾根拠そのものが消える**。裁定の再設計が要る |
| `verified` が業務 group 全体に掛かる構造 | メール任意なら「検証を通せない利用者」が構造的に生まれる。group 設計の変更は `ManageRouteAuthGuardTest` 等の deny-by-default 目録に触れる |
| 通知経路 (`Notifiable` の mail channel / `EmailSuppression` / SES webhook) | メール未設定の層には通知が届かない |
| SSO (`SocialAccountService` / `SocialiteDriverResolver`) | IdP の identity は email 中心。ID 方式と併存させると突合規則の再設計が要る |
| **思考原則 3 (後方互換の並走を残さない)** | **「メール任意 + 管理者発行の識別子を併設」という中間案が規約上採れない**。採るなら email 識別子を消すことになり、上の全部を払う |
| `PasskeyLoginPolicy` / `getPasskeyUserHandle` / TOTP | **壊れない** (4-2 (e)) |

---

## 5. どうなったら必要になるのか (Conditional の昇格条件)

概念設計 §7-2 が正本。要点を再掲する。

### T-1 (主条件)

**記録条件 5 つ**がすべて書かれ、かつ**適格条件 A / B / C がすべて「はい」**である要求が 1 件でも来たとき。
**記録があるだけでは昇格しない。**

記録条件: (1) 対象組織・人数・役割と要求元 / (2) メールボックスを用意できない理由 /
(3) **共有メールボックス・サブアドレスでも不可である理由** / (4) 許す操作の範囲 /
(5) なりすまし許容度。

適格条件:

| # | 適格条件 | 満たさないときの行き先 |
|---|---|---|
| A | 要求の実体が **「認証の入口」** である | 「呼称が欲しい」→ 案 D (表示専用の識別子) / 「誰がいつ入ったか」→ 最終ログイン日時の表示 (§6-5) |
| B | **案 A が実地で不成立** = 概念設計 §3-4 の 6 条件のうち 1 つ以上が崩れている事実が記録されている | 成立するなら実装不要。運用手順書の整備で閉じる |
| C | **本人の自力パスワード回復について、現行の Fortify email broker では成立しないことを承知した上で、代替回復方式を設計対象に含めることが合意されている** | **「代替回復方式」には、権限集中を明示的に受容した管理者リセットも含む** (Round 2 の指摘。本文と行き先の判定を揃える)。したがって不昇格になるのは**「回復方式を決めていない」ときだけ**である。「本人だけが回復できねばならない」なら**回復方式の設計が第 1 段の前提条件**になる |

**適格条件は 3 つ (A / B / C) である。**
判定例 (「個人メールがないだけ」は B を満たさない 等) は概念設計 §7-2 の表が正本。

**適格条件から外したもの (Round 1 の指摘を反映)**:
**「対象利用者に TOTP 2FA を強制しない」は昇格の必須条件にしない。**
「メールを受け取れない利用者が入れない」問題と、「日常ログインの入力を減らせる」問題は
**別の問題**であり、混ぜると「2FA を強制する現場は永久に昇格しない」という
厳しすぎる条件になる。TOTP 強制下でもメールなし入口の必要性は独立に成立するため、
2FA 方針は**昇格後の設計における追加決定点** (§6-2 の決定点 8) として扱う。

**回復手段について (Round 1 の指摘を反映して断定を弱める)**:
「メールなしで本人だけが回復する手段は存在しない」とは**書かない**。
正確には **「現行の Fortify email broker (`password_reset_tokens.email` 主キー) では
自力回復できない」**であり、リカバリコード方式・管理者承認付き再発行・
パスキーの再登録による回復など、**代替設計はあり得る**。
ただしそれらは本設計の対象外であり、採るなら**別途設計する**。

### T-2 / T-3

- **T-2**: `verified` を業務 group から外す / メール検証の免除経路を作る判断が**別要件で**入ったとき
  (§6 の第 1 段のコストが大きく下がるので本設計を読み直す)。**自動昇格ではない。**
- **T-3**: `doc/04 §4.2` の入力制約が受入検査の対象として顧客と合意され、
  カラムの存在自体が契約要件になったとき。**案 D で足りるか**を最初に問う。
  **パスワードの「半角英数 8〜16 字」は契約要件であっても採らない** (セキュリティの後退)。

**昇格条件ではないもの**: 「ID の方が現場に馴染む」という選好のみ。

### 5-1. 昇格したときに最初にやる 4 手順 (設計のやり直しを防ぐ)

1. **要求の実体を §5 の適格条件 A で分類する**。案 D / 最終ログイン日時表示に落ちるなら
   そちらを起票して終わる (認証基盤に触らない)。
2. **§6 の第 1 段 (案 C) だけで足りるかを判定する。** 足りるなら第 2 段以降を設計しない。
3. 第 1 段を実装する場合、**「平文 token を画面に出す」ことのセキュリティ設計**
   (露出範囲・有効期限短縮・監査記録・再発行) を**先に**決める。ここが本体である。
4. 第 2 段以降 (識別子の置換) に進む場合、**旧識別子を同じ変更で消す計画**を最初に立てる
   (思考原則 3。並走させる設計は書かない)。

---

## 6. 将来実装する場合の参照設計 (**実装しない**)

> 本節は昇格時の出発点であり、**今回 1 行も実装しない**。
> コードは形を示すためのスケッチであり、そのまま使える保証はしない。

### 6-1. 段階の切り方 (認証基盤の作り替えを一度にやらない)

| 段 | 内容 | 何が成立するか | 規模 | 触る不変条件 |
|---|---|---|---|---|
| **第 1 段 (案 C)** | 招待リンクの**手渡し** + 招待経由登録の**メール検証免除** | メールを 1 通も送らずに撮影者を増やせる。**識別子は email のまま** (エイリアス / ダミーでよい) | 中 | 招待 token の秘匿 / `verified` の免除設計 |
| **第 2 段 (案 D)** | `users` または組織メンバーシップに**表示専用の識別子**を足す | 現場台帳との突合。**認証には使わない** | 小 | PII 判定 (CipherSweet 対象か) |
| **第 3 段 (案 E)** | 認証識別子を email → ID へ**置換** | 要件書の字面どおり | 特大。**本書では設計しない = 別設計必須** | §4-3 のほぼ全部 + §6-3a の移行計画 |
| 独立 | **最終ログイン日時の表示** (§6-5) | 要件の 1 項目が UI に出る | 小 | なし |

**第 1 段だけで §5 の要求が満たされるなら、第 2・3 段は作らない。**

### 6-2. 第 1 段 (案 C) の設計上の決定点

昇格時に最初に決めるべきことを列挙する。**答えは今出さない** (要求が確定していないため)。

| # | 決定点 | 選択肢と代償 |
|---|---|---|
| 1 | 平文 token をどこまで出すか | (あ) 発行直後 1 回だけ画面に出して以後は再表示不可 (再発行のみ) / (い) 一覧に常時表示。(い) は**肩越しの盗み見・スクショ・チャット転送**で資格情報が流出する。現行の「平文は DB に無くメールにしかない」という不変条件を崩す以上、(あ) が既定。**受領者がそれをどう消費するかは §6-2d で別に決める** (ここは発行側の話にすぎない) |
| 2 | 平文 token を保存するのか | 保存しない (発行応答にだけ載せる) を既定にする。保存するなら**暗号化 + 短い有効期限 + 閲覧の監査記録**が必須。`OrganizationInvitation` の「token_hash のみ保存」を崩す判断になる |
| 3 | 有効期限 | 現行 `EXPIRES_DAYS = 7`。手渡し経路は**もっと短く**する (画面表示は流出面が広い) |
| 4 | メール検証の免除範囲 | (あ) 「手渡し招待から登録した利用者だけ `email_verified_at` を確定する」/ (い) `verified` を group から外す。(い) は**全利用者に波及する**ので採らない。(あ) でも「その email の所有者であることの証明を捨てた」事実は残るので、**監査イベントに残す**こと |
| 5 | 招待メールを送るかどうかの選択 | 「メールを送らない招待」を新設するのか、既存の `inviteMember` に分岐を足すのか。**後者は 1 メソッドが 2 つの意味を持つ**ので、招待の種別を型 (enum) で表す方が良い |
| 6 | 認可 | 手渡し招待の発行は `manageMembers` (owner / admin)。`Gate::authorize` を通す (セキュリティ不変条件 9) |
| 7 | 流量制限 | 変更系かつ認証面に近いので named limiter を 1 本持つ (ドメイン規約 5。inline throttle は使えない) |
| 8 | 2FA 方針 | 対象利用者に TOTP 2FA を強制するか。強制するとパスキーログインが `PasskeyLoginPolicy` で塞がれ、日常ログインの入力削減が消える。**昇格の必須条件ではないが、第 1 段の設計時に必ず決める** |

#### 6-2a. **識別子として使う email の扱い (Round 1 Critical。第 1 段の中核)**

第 1 段は「識別子は email のまま (エイリアス / ダミーでよい)」とするが、
**「ダミーでよい」で済ませると壊れる**。実在ドメインへの誤配送・回復導線の空振り・
検証メールの送信が未整理のままになるため、次を**第 1 段の必須決定点**とする。

| # | 決定点 | 何を決めるのか / 既定案 |
|---|---|---|
| a | **合成 email のドメイン規則** | **既定は予約済みの `.invalid` に限定する** (Round 2 の指摘)。**「MX を持たない自社サブドメイン」は配送不能を保証しない** — SMTP は MX が無いときに A/AAAA レコードへフォールバックしうるため、将来 A レコードが付いた瞬間に実配送が始まる。自社ドメインを許すなら、MX 不在ではなく**送信経路での明示拒否と外部配送不能を構成し、テストで固定する**こと。`.test` は開発・テスト用途との混同を招くため、本番の識別子規則として採るかを**明示的に判断**する。**利用者が入力した任意のドメインは許さない** (誤配送とアカウント乗っ取りの両方の口になる) |
| b | **合成 email であることの記録** | 「配送先として無効」を後から機械で判別できる必要がある。**フラグ列 (例: `users.email_deliverable`) を持つか、ドメイン規則から導出するか**を決める。導出は規則変更で壊れるので、**列で持つ方を既定**とする |
| c | **メール送信の抑止** | 合成 email へ**一切送らない**。`Notifiable` の mail channel を経路ごとに塞ぐ (`routeNotificationForMail` が null を返す形が素直)。抑止しないと SES のバウンス率が上がり、`EmailSuppression` に無関係のアドレスが積まれる |
| d | **メール検証通知の抑止** | `MustVerifyEmail` の verification notification を送らない。第 1 段では**招待の手渡し受諾をもって `email_verified_at` を確定**するため、そもそも送る必要が無い |
| e | **パスワード再設定の扱い** | ⚠ **応答を利用者ごとに変えてはならない** (Round 2 の指摘)。合成 email だけ違う応答を返すと**アカウント列挙オラクル**になる。Fortify が一般化した成功応答を返すのは**意図された挙動**であり、崩さない。したがって解は「応答を変える」ではなく **「回復経路を事前に明示する」** である — ログイン画面の常設案内 / 管理者から渡す手順書 / 認証済みの設定画面のいずれか (**列挙を生まない経路**) に、その利用者の回復方法を置く。代替回復方式 (リカバリコード等) を作らないなら、**「回復手段が管理者経由しか無い」ことを運用に明記する** |
| f | **通知経路の代替** | メールが届かない層には**アプリ内通知センター** (`NotificationCenterService`) が唯一の経路になる。第 1 段の対象利用者に必要な通知が通知センターに載っているかを確認する |
| g | **初回パスキー登録を必須にするか** | 合成 email の利用者はパスワードを忘れると回復できない。**初回ログイン後にパスキー登録を促す**導線を持つかを決める。ただし **必須条件未充足でボタンを disabled にしない** (禁止事項 8) |
| h | **`email_verified_at` に由来を残すか** | 下記 6-2b |

#### 6-2b. **`email_verified_at` の由来を残す (Round 1 Warning)**

第 1 段で `email_verified_at` を立てると、
**「メールの所有を確認した」状態と「手渡し招待で業務利用を許可した」状態が同じ列に畳まれる。**
既存 middleware (`verified`) との兼ね合いでこの列を使うのはやむを得ないが、
**由来を必ず別の場所に残す**:

- 最低限: `SecurityEventType` に**手渡し招待による検証免除**の case を足し、監査イベントに残す
  (case 追加は**記録経路の同一 PR 配線が必須**。`SecurityEventCoverageTest` が deny-by-default)。
- 望ましくは: `organization_invitations` 側に招待種別を持ち、
  「その利用者がどの種別の招待で入ったか」を辿れるようにする。
- **やってはいけないこと**: 由来を残さずに `email_verified_at` だけ立てること。
  後から「この利用者のメールは本当に本人のものか」を判定できなくなる。

#### 6-2c. **平文 token の露出面 (Round 1 Critical。昇格時の必須条件)**

平文 token を画面に出すことは **credential disclosure (資格情報そのものの開示)** である。
「肩越しの盗み見・スクショ」だけでは評価として不足で、
**次のすべてを第 1 段の必須設計とする** (1 つでも欠けたら第 1 段を実装してはならない)。

> **本節の対象は招待 token だけではない (Round 3 の指摘)。**
> **§6-2d 方式 1 の「引換コード」も、それ 1 つで組織への参加が成立する
> bearer credential (持っているだけで通る資格情報) である。**
> 本節の 10 条件は**引換コードにも同じく適用する**。
> 追加の要件は §6-2e に置く。

| # | 必須条件 | 理由 / 注意 |
|---|---|---|
| 1 | **発行直後 1 回のみ表示。再表示は不可** (必要なら再発行 = 旧 token は失効) | 一覧に常時出すと露出面が恒久化する |
| 2 | **平文を保存しない** | 現行の「平文は DB に無い」を維持する。保存するなら暗号化 + 短い TTL + 閲覧の監査が必須 |
| 3 | **短い TTL** (現行のメール招待は `EXPIRES_DAYS = 7`。手渡しはこれより**明確に短く**) | 画面表示は流出面が広い |
| 4 | **single-use** (受諾で即失効) + **明示的な失効操作** | 現行の `revokeInvitation` (論理失効) を使う |
| 5 | **Inertia props に載る事実を意識する** | props は**ページのマークアップに埋め込まれて履歴に残る**。表示するページに `no-store` が掛かっていること、Inertia の history 暗号化 (ドメイン規約 3 の (C)) の対象であることを確認する |
| 6 | **URL に載せる場所を 2 つに分けて扱う** | **管理者側の表示ページ URL には載せない**のは無条件。**受領者側の受諾要求の URL** は §6-2d で方式ごとに扱いを決める (現行のメール招待は `/invitations/accept?token=…` = クエリに載っている。手渡し方式でこれをそのまま踏襲すると露出面が増える) |
| 7 | **サーバログに出さない** | 発行応答・例外・デバッグログのいずれにも平文を出さない |
| 8 | **bfcache / キャッシュ対策** | 表示ページは `no-store` baseline の対象に入れる (ドメイン規約 3 の (A))。撮影 PWA の主戦場 iOS Safari は `no-store` でも bfcache に入りうるため、(B) の bfcache guard の対象かも確認する |
| 9 | **監査**: 発行 / 表示 (閲覧) / コピー / 再発行 / 失効 | 「誰がその資格情報を見たか」が残らないと、流出時に追跡できない |
| 10 | **認可**: 発行・表示ともに `manageMembers` (`Gate::authorize`) | セキュリティ不変条件 9 |

> **条件 6 についての注意 (Round 2 Critical への対応)**: 旧版は「URL に載せない」と
> 「招待リンクを手渡す」を並べており、**自己矛盾していた**。
> リンクを渡して受領者がそこへ遷移する以上、**受領者側の最初の HTTP 要求には何かが載る**。
> 「管理画面の URL に載せない」だけでは、受領者側のブラウザ履歴・最初のリクエスト・
> リバースプロキシ / アクセスログへの露出を防げない。§6-2d で方式として決着させる。

#### 6-2d. **受領者が token をどう消費するか (Round 2 Critical。第 1 段の方式決定)**

第 1 段の中核は「**管理画面での表示**」ではなく「**受領者側の HTTP 遷移・ログ・履歴まで含めた
1 本の受け渡し方式**」である。次の 2 方式から**どちらかを選ぶ** (両方を並走させない)。

**方式 1: 引換コード方式 (推奨)**

- 受領者に渡すのは **短い一回限りの引換コード**であり、招待 token そのものではない。
- 受領者はコードを**フォームに入力し、POST で交換**する。**URL には何も載らない。**
- 利点: 受領者側の URL・履歴・`Referer`・アクセスログのいずれにも資格情報が乗らない。
  現場で口頭・紙で渡せる長さにできる。
- 代償: 画面を 1 つ増やす (コード入力フォーム)。**引換コードは招待 token と同格の資格情報**であり、
  §6-2c の 10 条件に加えて **§6-2e の要件をすべて満たす**必要がある。
  とくに **named limiter はエントロピーの代わりにならない** (Round 3 の指摘) —
  短いコードを流量制限だけで守ろうとしてはならない。

**方式 2: URL 方式 (現行のメール招待を踏襲する場合)**

採るなら次を**すべて**必須にする:

1. 受諾 route の**最初の要求で即座に token を session へ退避**し、
   **token を含まない URL へ redirect** する (以後の履歴・リロードに平文が残らない)。
2. **アクセスログのクエリ文字列を除外する** (または token パラメータをマスクする)。
3. **例外コンテキストのマスキング** (ログドライバに載る request データから除去)。
4. **`Referrer-Policy: no-referrer`** を受諾ページに付ける
   (外部リソースを踏んだときに `Referer` で漏れない)。
5. **戻る操作 / 履歴復元で Inertia props に平文が再表示されないこと**をテストで固定する
   (ドメイン規約 3 の (B) bfcache guard と (C) Inertia history 暗号化の射程を確認する)。

> **既存のメール招待との関係**: 現行の `/invitations/accept?token=…` は
> **本タスクで変更しない**。上の要件は**手渡し方式を新設する場合**の要件である。
> ただし方式 2 を採るなら、同じ route を通る以上**既存のメール招待にも同じ対策が掛かる**ので、
> 「既存経路は一切変わらない」という回帰テスト (§6-7) の期待値を先に見直すこと。

#### 6-2e. **引換コードを資格情報として保護する (方式 1 を採る場合の必須要件。Round 3)**

引換コードは**それ 1 つで組織への参加が成立する bearer credential** である。
「短くて覚えやすい」ことと「守れる」ことは両立させなければならない。

| # | 要件 | 理由 |
|---|---|---|
| 1 | **CSPRNG で生成し、最低エントロピーを要件として決める** | 人が読める短さにするほど総当りが現実的になる。**エントロピーは設計値として明示的に決める** (「短いから limiter で守る」は不可) |
| 2 | **平文を保存せず hash のみ保存する** | 現行の `OrganizationInvitation` が `token_hash` だけを持つ設計と揃える |
| 3 | **TTL / single-use / 再発行時の旧コード失効** | 有効期間と使用回数の両方を有界にする |
| 4 | **検証と失効を原子的に行う** | 同時 POST で二重交換できないようにする。本アプリの既存作法どおり**行ロック下で再検証**する (`joinOrganization` が `lockForUpdate` + ロック下再検証で並行受諾を封じているのと同じ形) |
| 5 | **POST body / validation 例外 / 監査 metadata に平文を残さない** | 「URL に載らない」だけでは足りない。フォーム経由でも例外コンテキストとログには載りうる |
| 6 | **named limiter を IP 単独依存にしない** | 共有 NAT (工場・事業所からの一括 NAT) では正規利用者が巻き添えになり、分散試行には効かない。**共有 NAT と分散試行の両方を設計時に評価する**。キーは `{レーン}:{種別}:{値}` 規約に従う (ドメイン規約 5) |

> **要件 6 の注意**: 本アプリのレート制限規約は
> 「閾値は既存値を変えない / 新しい面には既に本番稼働中の同性質エンドポイントと同値を充てる」
> である。引換コードの面は既存に同性質のものが無いので、**新レーンとして設計し、
> `ThrottleLaneAssignmentTest` / `RateLimiterKeyConventionTest` の契約に載せる**。

### 6-3. 第 2 段 (案 D) の注意

- 「表示専用の識別子」は **`users` に置くとは限らない**。
  社員番号・呼称は**組織ごとに違う**ので、`organization_user` pivot 側の属性が素直な場合がある
  (別組織に同じ人が別の呼称で入れる)。**どちらかを先に決めてから列を足す**。
- 識別子が氏名の略称・社員番号になるなら **PII 相当**である。
  セキュリティ不変条件 6 に従い CipherSweet + blind index の対象にするかを判断する
  (すると通常の unique が使えなくなり、email と同じ `blind_indexes` の partial unique 方式になる)。
- **認証には絶対に使わない**。使った瞬間に第 3 段になる。

### 6-3a. 第 3 段 (案 E) は**別設計必須**。決定点だけ置く (Round 1 Warning)

第 3 段は本書では設計しない。**「特大」の内訳**を列挙して、
昇格時に「思ったより小さい」と誤認されないようにする。

| # | 決定点 | 内容 |
|---|---|---|
| 1 | **既存ユーザーの `login_id` 採番** | 既存の全ユーザーに識別子を割り当てる必要がある。自動採番 (衝突と可読性) か、管理者が手で入れるか (移行が止まる)。**「英数 20 字以内・重複不可」を既存データに対して満たせるか**を先に確かめる |
| 2 | **データ移行の順序** | 列追加 → backfill → unique 制約 → 認証切替の 4 段。**認証を切り替えるまで新旧の識別子が並存する**が、これは移行期間の話であり恒久的な並走ではない (思考原則 3 に反しない)。移行完了時点で email 識別子を**消す**計画まで含めて 1 つの変更にする |
| 3 | **既存セッション / remember token** | 識別子の切替で既存セッションを無効化するのか維持するのか。`remember_token` は user 行に紐づくので技術的には維持できるが、**移行時に全員ログアウトさせる方が事故が少ない**場合がある |
| 4 | **SSO の紐付け** | `social_accounts` は provider + provider_user_id で紐づく。IdP が返す email との突合ルールが識別子切替でどう変わるか |
| 5 | **監査ログ上の識別子表示** | `security_audit_events` / Filament の一覧が「誰の行為か」を何で表示するか。email 表示をやめるなら Filament 側も同時に直す |
| 6 | **サポート運用** | 問い合わせ時の本人確認を何で行うか (現行は email)。**運用手順書の変更まで含めて完了**とする |
| 7 | **PII 判定** | 識別子が氏名の略称・社員番号なら PII 相当。CipherSweet + blind index の対象にするか (§6-3) |
| 8 | **回復手段** | §5 の適格条件 C。email broker が使えなくなるので代替方式が必須 |

### 6-4. 波及変更 (昇格時に必ず一緒に直すもの)

第 1 段を実装する場合の最小セット。**今回は 1 件も行わない。**

| 種別 | 対象 |
|---|---|
| Service | `app/Services/Organization/OrganizationMembershipService.php` (招待の種別分岐 / 平文 token の返し方) |
| Model | `app/Models/OrganizationInvitation.php` (種別列 / 期限の別値) |
| migration | `organization_invitations` への種別列追加 + Factory (`database/factories/OrganizationInvitationFactory.php`) の更新 |
| Controller | `app/Http/Controllers/Organizations/OrganizationInvitationController.php` (発行) / `app/Http/Controllers/Admin/UserManagementController.php` (表示) |
| FormRequest | `app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php` (種別の受付。`Rule::enum`) |
| DTO | `app/DataTransferObjects/Admin/InvitationRowData.php` (種別 / **手渡し資格情報の 1 回表示** = 方式 1 なら引換コード、方式 2 なら受諾 URL) |
| TypeScript 型 | `resources/js/types/admin.ts` の `InvitationRow` (DTO と対で保守する契約がコメントに明記されている) |
| Svelte | `resources/js/pages/Admin/Users.svelte` (発行 UI。**必須条件未充足で disabled にしない** = 禁止事項 8) |
| 監査 | `App\Enums\SecurityEventType` への case 追加は**記録経路の同一 PR 配線が必須** (`SecurityEventCoverageTest` が deny-by-default) |
| throttle | named limiter 新設 + `ThrottleCoverageInventoryTest` の目録 |
| 認可 | `Gate::authorize` (`ControllerAuthorizationGateTest` の deny-by-default) |
| ドキュメント | `docs/architecture.md` の招待の節 / `docs/auth-security-mechanisms.md` |
| **メール送信系 (Round 1 Warning で追加)** | `Illuminate\Auth\Events\Registered` の購読 (検証メールの発火点) / `MustVerifyEmail` の verification notification の抑止 / `User` の `routeNotificationForMail` (合成 email への送信抑止) / `app/Models/EmailSuppression.php` と SES webhook (`app/Http/Controllers/Webhooks/SesNotificationController.php` — 合成 email のバウンスを積ませない) |
| **回復系 (Round 1 Warning で追加)** | `config/fortify.php` の `'passwords' => 'users'` broker / `password_reset_tokens` / `Features::resetPasswords()` の画面 (**一般化応答を維持し、列挙を生まない経路で回復方法を事前提示する**。応答を利用者ごとに分けない) / 代替回復方式を採る場合はその設計一式 |
| **通知の代替経路** | `app/Services/Notification/NotificationCenterService.php` (メールが届かない層の唯一の経路になる) |
| **ログ / ヘッダ** | 受諾 URL・平文 token がアクセスログ / 例外ログ / `Referer` に出ないこと。表示ページの `no-store` (`NoStoreCacheHeadersForAuthenticatedPages`) と Inertia history 暗号化 (ドメイン規約 3) の対象確認 |
| **route middleware のテスト** | `verified` を route 単位で外す設計にするなら `ManageRouteAuthGuardTest` / `RecentAuthRouteTest` 等の deny-by-default 目録に影響する。**目録側の更新を施策に含める** |
| **受領者側の消費境界 (Round 2 Warning で追加)** | `app/Http/Controllers/Organizations/InvitationAcceptanceController.php` と受諾 route (`invitations.accept` / `invitations.accept.store`) / **引換コードの交換処理** (方式 1) または **session 退避 + token なし URL への redirect** (方式 2) / **アクセスログ・例外ログの query / token マスキング** / **`Referrer-Policy: no-referrer`** / 引換コードを新設するなら **CSRF (web group の `PreventRequestForgery`) と named limiter の境界**を明示する |
| **受領者側のテスト (Round 2 Warning で追加)** | 戻る操作・履歴復元で平文が再表示されないこと / **発行者と受領者が別端末である受け渡しの E2E** (Browser lane は Chromium + WebKit の 2 レーンが契約) |

**注意 (Codex Round 1 の Suggestion を反映)**: 第 1 段・第 3 段へ進む場合は、
**FormRequest / DTO / JsonResource の境界を最初に設計対象へ入れる**。
招待の種別が増えると入力・出力の両方の型が増え、後から足すと `response()->json()` 直書きや
配列返却へ流れやすい (禁止事項 4)。

### 6-5. 独立して起票できる小改善: 最終ログイン日時の表示

**本タスクの結論とは無関係**に実施できる (実施の可否は別途判断する)。

- 現行: `security_audit_events` に `login` が記録され索引もあるが、
  `/manage/users` には出ていない (`MemberRowData` に列が無い)。
- **位置づけ**: これは**一覧要件の一部を満たす小改善**であり、
  ユーザー登録方式そのものへの不満とは別物である
  (Round 1 の指摘。「これで要件未充足の体感の大半が消える」とは書かない)。
- 変更点: `UserManagementController::index` で
  メンバー分の最終 login イベントを**1 クエリで**取り (N+1 を作らない)、
  `MemberRowData` に `lastLoginAt` を足し、`resources/js/types/admin.ts` の `MemberRow` と
  `Admin/Users.svelte` に列を足す。
- 注意: **`users` に `last_login_at` カラムを足さない**。
  監査イベントが唯一の記録点であり、可変カラムを足すと同じ事実の記録が 2 か所になる。
- テスト: `tests/Feature/Admin/UserManagementPageTest.php` に
  「login イベントがある / 無い の 2 ケースで props が期待どおり」を追加。
  クエリ数の行数非依存も固定する (メンバー数を変えても発行クエリが増えないこと)。

### 6-6. PHPStan 適合チェック (将来実装時の観点)

- [ ] 戻り値の型が明示されている (招待発行が返す平文 token は `?string` ではなく専用 DTO)
- [ ] null 安全 (`Webmozart\Assert\Assert` を使う)
- [ ] DTO を返している (配列返却なし / `response()->json()` 直書きなし)
- [ ] enum の網羅 `match` (招待種別に `default` を置かず、case 追加で落ちるようにする)
- [ ] Generics の型パラメータ (`Builder<OrganizationInvitation>` 等)

### 6-7. テスト計画 (将来実装時。**今回は 1 件も書かない**)

- 手渡し招待で発行した token が**発行応答にだけ**現れ、一覧の再取得では出ないこと
- 平文 token が **DB に保存されていない**こと
- 手渡し招待の期限がメール招待より短いこと / **single-use** (受諾で即失効) であること
- 手渡し招待経由の登録で `email_verified_at` が確定し、撮影 PWA に到達できること
- **`email_verified_at` の由来が監査イベントに残る**こと (§6-2b)
- **合成 email 宛ての mail channel 送信が 0 件**であること
  (`Notification::fake()` で固定。招待通知 / 検証通知 / パスワード再設定のすべて)。
  **アプリ内通知 (通知センター) は別に期待件数を検証する** —
  「通知を 0 件にする」のではなく「メールだけを 0 件にし、代替経路には届ける」のが目的である
- **`/forgot-password` の応答が、アカウントの存在・配送可否によって区別できない**こと
  (列挙オラクルを作らない。Fortify の一般化応答を崩していないこと) +
  **回復経路が列挙を生まない場所に事前提示されている**こと (§6-2a-e)
- 表示ページに **`no-store`** が付いていること

**露出面のテストは §6-2d の方式ごとに期待値が違う (Round 3 の指摘。共通で書くと本文と矛盾する)**:

| 対象 | 方式 1 (引換コード) | 方式 2 (URL + session 退避) |
|---|---|---|
| URL への露出 | **token も引換コードも URL に出ない** (0 件で固定) | **初回受諾要求の URL に token が載ることだけを限定的に許容する**。それ以外はすべて 0 件 |
| redirect 後の URL | — | **token を含まないこと** |
| 遷移後の `Referer` | 出ないこと | 出ないこと (`Referrer-Policy: no-referrer`) |
| アクセスログ / 例外ログ | 出ないこと | **出ないこと** (query 除外またはマスク。URL に載ることと**ログに残ること**は別) |
| Inertia history / bfcache 復元 | 残らないこと | 残らないこと |
| 既存メール招待経路 | **一切変わらないこと** | **意図した安全化以外の既存挙動が変わらないこと** (同じ route を通るため「一切変わらない」は成立しない) |

**共通 (方式によらず)**: **POST body / validation 例外 / 監査 metadata を含め、
資格情報がアプリケーションログに保存されないこと。**
- 手渡し招待の発行・表示が `manageMembers` 非保持者から 403 になること
- named limiter が効くこと (連打で 429、他レーンを巻き添えにしないこと)
- 監査イベント (発行 / 表示 / 失効) が記録されること

### 6-8. リスク (将来実装時)

- **平文 token の受け渡しが最大のリスク**。資格情報そのものの開示 (credential disclosure) であり、
  現行の「平文は DB に無くメールにしかない」という秘匿設計を崩す。
  露出面は肩越し・スクショだけでなく **Inertia props / ブラウザ履歴 / `Referer` /
  サーバログ / キャッシュ・bfcache** に及ぶ (§6-2c の 10 条件が必須)。
  **発行側 (管理画面の表示) と受領側 (受諾要求の URL・履歴・ログ) は別の露出面**であり、
  §6-2d でどちらも閉じる方式を 1 本選ぶ。片方だけ閉じても意味が無い。
- **メール検証の免除**は「その email の所有者であることの証明」を捨てる。
  由来を残さないと後から本人性を判定できなくなる (§6-2b)。
- **合成 email の扱いを詰めないと、実在ドメインへの誤配送・回復導線の空振り・
  SES バウンス率の悪化**が起きる (§6-2a)。
  とくに回復導線: **列挙対策として一般化応答は維持する**ため、
  **利用可能な回復経路を事前提示しなければ利用者が復旧不能になる**
  (応答を分けて教えるのは列挙オラクルになるので採れない)。
- **回復手段が無い層が生まれる**。パスワードを忘れた合成 email の利用者は
  管理者の助けなしには戻れない (§5 の適格条件 C)。
- 招待の種別が増えると **`OrganizationMembershipService` の受諾経路 3 本**
  (`acceptInvitation` / `acceptInvitationIfValid` / `acceptPendingInvitation`) の
  組み合わせが増える。共通コア `joinOrganization` の外に条件を散らさないこと。

---

## 7. 差分記録の所在と `docs/template-divergence.md` の判断

### 7-0. 結論: **新規登録は不要。D8 への追記も行わない。**

**主根拠は 1 つである** (Round 1 の指摘を受けて一本化した)。2 以下は補助的事情であり、
主根拠が崩れたら 2 以下では登録不要を支えられない。

**主根拠: 本件はテンプレートからの逸脱ではない。**

1. `docs/template-divergence.md` 冒頭の定義は
   「テンプレート (laravel-claude-template) の構造から**意図的に逸脱**した箇所の正本記録」である。
   本件で問題になっている email 認証 / Fortify / CipherSweet / passkey / 招待制は
   **テンプレートが提供する形そのもの**であり、テンプレートからは逸脱していない。
   **`doc/` 要件との差は台帳の記録対象ではない。**
**補助的事情 (主根拠を補強するが、単独では登録不要の根拠にならない)**

2. **関連する管理画面の差分は既に D8 に記録されている。**
   D8 の観点表に「ユーザー作成 | (doc/04 レガシーモック: 管理者がパスワード直接発行・平文一覧表示) |
   **招待一本化** (ユーザー ID → email へマッピング)。パスワードは本人設定のみ」という行があり、
   `根拠 T006` / `状態 恒久` で登録済みである。
   **ただし D8 が押さえているのは「管理メニューの UI とロール語彙」であり、
   認証識別子そのものを D8 がカバーしているとは主張しない** (Round 1 の指摘)。
   認証識別子は主根拠 1 のとおり**そもそも逸脱ではない**ので、どの登録もカバーする必要が無い。
3. **既存登録との対象パス重複** (補助的事情にすぎない)。
   登録メタ表の規約は「対象パスは全登録の和集合で重複しないこと」を要求し、
   `app/Services/Organization/OrganizationMembershipService.php` と
   `app/Http/Controllers/Admin/UserManagementController.php` は D8 が既に押さえている。
   **これは「登録すべきなのに登録できない」理由にはならない** — 記録すべき逸脱であれば
   台帳構造や D8 の側を直してでも記録するのが正しい。よって本項は
   **「主根拠が成立しているときに、新エントリを作ると機械検査が落ちる」という付随事実**として
   のみ扱う (Round 1 の指摘を受けて格下げ)。
4. **D8 本文への追記もしない。** D8 の射程は「管理メニューの UI とロール語彙」であり、
   再判定の条件も「役割を保存概念へ戻す要件が出たとき / 家系の裁定が役割の語彙を変えたとき」である。
   認証識別子の論点を同居させると、再判定の条件と本文の内容が対応しなくなる。

**主根拠が崩れる条件 (将来ここを読み直す人へ)**: もし本アプリが
「テンプレートが提供する email 認証・招待制の形そのものから外れる」変更
(第 1 段の検証免除・第 3 段の識別子置換のいずれも該当しうる) を入れたなら、
**主根拠は成立しなくなる**。

**表現を統一する (Round 2 の指摘)**: ここは断定ではなく
**「その変更の中で登録の要否を再判定する」**である。断定にしない理由は 2 つ:
(1) テンプレート側も更新されるため、実装時点でテンプレートが同じ機能を持っている可能性がある
(そのときは逸脱ではない)。(2) 逸脱の有無は**その変更の内容が確定してから**しか判定できない。
したがって第 1 段・第 3 段を実装する PR の完了条件に
**「`docs/template-divergence.md` への登録の要否を判定し、要るなら同じ変更で登録する」**を含める
(台帳の原則「登録は逸脱を作る変更そのものに含める」)。

> **「登録するか迷ったら登録する」という原則との関係**: この原則は
> 「テンプレートの実物が手元に無いので判定に迷うことがある」ことを理由にしている。
> 本件は迷いではなく、**テンプレートの標準機能をそのまま使っている**と確定できるため、
> 原則の前提に当たらない。

### 7-1. Conditional 登録用の本文 (後続の登録エージェントはこれをそのまま使う)

```
### ユーザー登録方式の要件差 (ユーザー ID 発行 vs 招待制) — Conditional

**結論**: 今は作らない。テンプレート由来の認証基盤 (Fortify の email 識別子 / 招待制 /
CipherSweet / passkey) を維持する。設計は
devnotes/20260817-0003-user-provisioning-model-divergence/ が正本。

**根拠 (要約)**:
- 確定仕様 doc/10 は識別子方式を規定していない (doc/02 §2.4 / doc/04 §4.2 / doc/05 §5.2 は
  Excel 起源の要件章)。
- 日常ログインはパスキー (ユーザー名入力なし) で入力を大きく減らせるため、
  ID + パスワード方式へ寄せると現場作業者の入力は増える。
- 「メールボックスを持たない作業者」は共有メールボックス / サブアドレスでの代行
  オンボーディング (コード変更ゼロ) で参加できる可能性がある。**未検証**。
- 寄せると本人の自力パスワード回復が消え、管理者へ権限が集中する。
  かつ思考原則 3 (後方互換の並走を残さない) により中間案 (併設) が採れない。
- 最終ログイン日時は security_audit_events から導出可能 (UI 表示のみ無い。
  管理画面要件としては未充足だが、認証基盤とは独立の小改善で満たせる)。

**昇格条件 (T-1)**: 記録条件 5 つが揃い、適格条件 A / B / C がすべて「はい」の要求が来たとき。
とくに「共有メールボックス / サブアドレスでも不可である理由」が書かれていない要求は昇格しない。
**T-2**: メール検証の免除経路を作る判断が別要件で入ったとき (再評価の開始。自動昇格ではない)。
**T-3**: doc/04 §4.2 の入力制約が受入検査の契約要件として合意されたとき
(パスワードの「半角英数 8〜16 字」は契約要件であっても採らない)。

**昇格条件ではないもの**: 「ID の方が現場に馴染む」という選好のみ。

**docs/template-divergence.md**: 新規登録は不要。主根拠は「本件はテンプレートからの逸脱ではない」
(email 認証 / Fortify / CipherSweet / passkey / 招待制はテンプレートが提供する形そのもの)。
関連する管理画面の差分は D8 に記録済み。ただし**昇格して第 1 段・第 3 段を実装する PR は、
その変更の中で登録の要否を再判定し、要るなら同じ変更で登録すること**。
```

### 7-2. 別途起票できるもの (Conditional ではない)

- **最終ログイン日時の管理画面表示** (§6-5)。要件の 1 項目を、認証基盤に触らずに満たせる。
  実施するかは別途判断する。本タスクは Open タスクを生まない。

---

## 8. 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | アプリコードの変更が 0 件であり、成果物は `devnotes/` 配下の設計書のみである。他施策と競合するファイルを 1 つも触らないため、worktree を切る必要も他タスクとの調停も無い。TODO への登録も Open ではなく **Conditional** であり、実装レーンに載らない |
| 競合リスク | **なし** (`app/` `resources/` `routes/` `database/` `config/` `tests/` `docs/` のいずれも変更しない) |

---

## 9. 最終確認 (使命・禁止事項チェック)

- **使命への寄与**: 「作らない」判断そのものが寄与である。要件書の字面に合わせて
  ID + パスワードのログインを作ると、**現場作業者の入力を増やす**方向の後退になる
  (日常ログインはパスキーで入力を減らせる)。「思考ゼロ」の原則は
  ログインの手前から始まる。
- **禁止事項**: 実装を行わないため直接の抵触は無い。
  - 禁止事項 2 (テストなしの実装完了報告): **実装が 0 件**なのでテストも 0 件である。
    「実装したがテストが無い」状態を作らない。
  - 思考原則 2 (今必要なものだけ作る): 実需が確認されていない段階で認証基盤を作り替えない。
  - 思考原則 3 (後方互換の並走を残さない): 中間案 (識別子の併設) を**採らないと明言**した。
  - 思考原則 4 (別物の概念を似ているからで統合しない): 「呼称としての ID」「認証識別子」
    「最終ログインの表示」を**3 つの別概念として分離**した。
- **コーディングルール**: 将来実装する場合の適用対象として §6-6 / §6-7 に明記した。
- **セキュリティ不変条件**: 現行の PII CipherSweet / `whereBlind` 検索 / 認可 gate /
  throttle 目録のいずれも**変更しない**。§4-3 で「寄せた場合にどれと衝突するか」を明示した。

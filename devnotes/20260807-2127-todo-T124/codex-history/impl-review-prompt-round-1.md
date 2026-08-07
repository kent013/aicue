【アプリの使命 (North Star)】

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

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


# あなたの役割

Laravel 12 + Svelte 5 (Inertia) アプリ **AI-CUE** の実装レビュアーとして、TODO **T124「2FA 秘密GETとenableのstep-up化」** の実装差分をレビューする。

## レビュー観点

1. **設計との一致性**: 詳細設計書の施策 1〜5 が実装されているか。設計から逸脱した箇所は妥当か。設計に書かれた「保証範囲を誇張しない」姿勢がコード・ドキュメントで守られているか。
2. **正確性**: セキュリティ上の穴 (step-up の抜け道、satisfier の到達不能による詰み、409/302 分岐の取りこぼし)、競合状態、無限ループ。
3. **PHPStan level 10 適合性**: 型注釈・null 安全・generics。`@phpstan-ignore` / baseline / 型の widen は禁止。
4. **DTO/JsonResource パターン**: `response()->json()` 直書きの禁止。本実装は新規レスポンスを作らない設計。
5. **テスト網羅性**: 各施策にテストがあるか。**空振り green (常に成立するアサーション)** になっていないか。負のコントロールが機能しているか。gate が本当に検出できるか (mutation 実測を添付している)。
6. **セキュリティ**: AGENTS.md のセキュリティ不変条件、特に「変更系 route は認可を通る」「層 2 は層 3 より前」。2FA / recent-auth の脅威モデル。
7. **DESIGN.md 準拠**: `/DESIGN.md` が design token の canonical source。color / radius / typography は token 経由で参照し hex 直書き (`#RRGGBB`) を増やさない。token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか。
8. **Atomic Design 準拠**: `resources/js/components/` は `atoms/molecules/organisms/templates` の責務分離に従う。atom は単機能・状態を持たない、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide を使い、SVG 直書きを増やさない。

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する
  - [Critical] = セキュリティ穴 / 詰み / 偽グリーン / 設計の中心的意図の破壊
  - [Warning] = 直すべきだが致命的でない
  - [Suggestion] = 好みの範囲
- 最後に**全体判定**を `APPROVED` または `CHANGES_REQUESTED` の 1 語で明示する
- **推測で断定しない**。読めていない箇所は「未確認」と書く

## この実装で特に見てほしい論点

- `two-factor.enable` を step-up 対象に含めた判断 (Fortify の `force=true` が seed を再生成する一方 `two_factor_confirmed_at` を触らない = 永久ロックアウト) が、コードとテストで正しく固定されているか。
- 新設 gate (`TwoFactorStepUpInventoryTest`) が **deny-by-default** として機能するか。母集団 exact-fit・non-exemptible 名指し・cap の 3 枚が互いを補っているか。抜け道はないか。
- クライアント (`Settings/Security.svelte`) の 409 処理が **無限ループしない**か。`enrollmentStepUpRetried` / `onDelegated` / 世代管理の 3 つの相互作用に穴はないか。
- inline throttle の共有 bucket (同一 actor の全 inline route が 1 bucket) と衝突していないか。step-up を enrollment の**最初**の inline 消費に固定する設計が、実装で崩れていないか。
- satisfier の到達性 (passkey allowlist 追加) が広げすぎでないか。`passkey.confirm*` を通すことが 2FA 必須ゲートの解除にならないことは確かか。


---

## 詳細設計書

# 詳細設計: 2fa-secret-get-recent-auth (T124)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール
- **PHPStan level 10** 必須（`composer phpstan`）。解析対象は `app` / `config` / `database` / `routes`
  （`phpstan.neon` 実査。`tests/` は対象外だが、`tests/Support/` の新規クラスも型は厳密に書く）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン。本設計では**新規レスポンスを 1 本も作らない**
  （遮断応答は既存 `RecentAuthRequiredResource` + `RecentAuthRequiredDto`）
- **アーリーリターン** 推奨 / `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [概念設計](./conceptual-design.md)（Codex Round 4 で **APPROVED**）
- Codex 議論履歴: `conceptual-review-round-{1..4}.md` / `codex-history/`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 秘密 GET 2 本 + `two-factor.enable` へ recent-auth を後付け | `app/Providers/FortifyServiceProvider.php`, `tests/Architecture/RecentAuthRouteTest.php`, `tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php`(新), `tests/Feature/Auth/TwoFactorEnableStepUpTest.php`(新) | High |
| 2 | `two-factor` route の step-up deny-by-default 目録 gate を新設 | `app/Enums/Security/TwoFactorStepUpExemption.php`(新), `tests/Support/Security/RecentAuthMiddleware.php`(新), `tests/Architecture/TwoFactorStepUpInventoryTest.php`(新), `tests/Architecture/RecentAuthRouteTest.php` | High |
| 3 | 2FA 必須ゲートの allowlist に passkey satisfier 2 本を追加 | `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php`, `tests/Feature/Organizations/TwoFactorEnforcementTest.php` | High |
| 4 | enrollment の step-up precheck と 409 再開（詰み防止） | `resources/js/lib/recent-auth.ts`, `resources/js/pages/Settings/Security.svelte`, `tests/js/pages/SettingsSecurity.test.ts`, `tests/js/lib/recent-auth.test.ts` | High |
| 5 | 運用契約の記録（AGENTS.md / architecture.md への追記） | `AGENTS.md`, `docs/architecture.md` | Medium |

施策 1〜4 は **1 つの変更単位**（片方だけ入ると詰みか偽グリーンになる）。実装順は
**2 → 1 → 3 → 4 → 5**（gate を先に置いて赤を実測してから配線する = テストファースト）。

---

## 施策 1: 秘密 GET 2 本 + `two-factor.enable` へ recent-auth を後付け

### 変更箇所
- `app/Providers/FortifyServiceProvider.php` L58-76（`RECENT_AUTH_ROUTE_NAMES` 定数と docblock）
- `tests/Architecture/RecentAuthRouteTest.php` L21-57（`recentAuthRequiredRouteNames()` の allowlist）

### 波及変更
- TypeScript 型定義: **なし**（Props もレスポンス shape も変わらない）
- API Resource/DTO: **なし**（遮断応答は既存 `RecentAuthRequiredResource` が返す）
- テストファイル:
  - `tests/Architecture/RecentAuthRouteTest.php`（allowlist 3 本追加）
  - `tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php`（新規）
  - `tests/Feature/Auth/TwoFactorEnableStepUpTest.php`（新規）
  - `tests/Feature/Security/AuthThrottleCoverageTest.php` — **計画された波及変更**。
    L310-361 の 2FA 秘密 GET レーン検査は `withSession(['recent_auth_at' => ...])` を
    持たないため、recent-auth 追加で 409/302 になり 429 の観測ができなくなる。
    **各テストへ fresh session を与える 1 行を追加する**（`withSession(['recent_auth_at' => time()])`）。
    検査意図・閾値・limiter 名・アサーションは 1 文字も変えない
    （「落ちたら直す」ではなく最初から変更対象として扱う）
- フロントエンド: 施策 4 が対応（`two-factor.enable` に step-up が付くため precheck が必須になる）

### 現行コード

```php
/**
 * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
 * （中略）
 * @var list<string>
 */
private const RECENT_AUTH_ROUTE_NAMES = [
    'two-factor.recovery-codes',
    'two-factor.regenerate-recovery-codes',
    'two-factor.disable',
];
```

### 変更後コード

```php
/**
 * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
 * いずれも「第二要素の秘密の露出」または「確立済み第二要素の除去・差し替え」経路であり、
 * 通常セッション認証だけで到達させない (姉妹操作: organizations.members.two-factor.reset /
 * settings.account.destroy 等と同基準)。
 * - recovery-codes 表示 (GET) / 再生成 (POST): TOTP を伴わないログイン成立手段の露出・更新。
 * - disable (DELETE): 第二要素そのものの無効化 (bug-hunt F-H3)。
 *   ※ 2FA 必須組織の準拠ユーザーは BlockTwoFactorDisableForEnforcedOrganizations
 *     (web group、recent-auth より先行) が 422 で拒否するため、本配線が実効するのは
 *     self-disable が許可される非 enforced 組織のユーザー。
 * - qr-code / secret-key (GET, T124): TOTP seed そのものの露出。
 *   Fortify の TwoFactorSecretKeyController は two_factor_secret を**復号して平文で返し**、
 *   TwoFactorQrCodeController は otpauth:// URL (秘密を内包) を返す。どちらも
 *   two_factor_confirmed_at を見ないため、**確立済み**第二要素の seed が読める。
 *   throttle (two-factor-secret-read) は連続取得の回数上限であって step-up の代替ではない。
 * - enable (POST, T124): Fortify の TwoFactorAuthenticationController は
 *   `$request->boolean('force')` を EnableTwoFactorAuthentication へ渡す。
 *   force=true は two_factor_secret と two_factor_recovery_codes を**再生成する一方で
 *   two_factor_confirmed_at を触らない** (fortify v1.37.2 実査) ため、奪取セッションから
 *   1 回叩くだけで「誰も知らない秘密で TOTP を要求し続ける」永久ロックアウトを作れる。
 *   秘密の**読み出し**だけ塞いで**差し替え**を開けたままにしない。
 * 付与漏れは RecentAuthRouteTest (allowlist) と TwoFactorStepUpInventoryTest
 * (deny-by-default 目録) の 2 枚で検出する。
 *
 * @var list<string>
 */
private const RECENT_AUTH_ROUTE_NAMES = [
    'two-factor.recovery-codes',
    'two-factor.regenerate-recovery-codes',
    'two-factor.disable',
    'two-factor.qr-code',
    'two-factor.secret-key',
    'two-factor.enable',
];
```

`tests/Architecture/RecentAuthRouteTest.php`:

```php
        // 2FA 無効化 (第二要素そのものの除去。bug-hunt F-H3。同じく後付け配線)
        'two-factor.disable',
        // 2FA seed の露出 (T124)。qr-code は otpauth:// URL、secret-key は平文 seed を返す
        'two-factor.qr-code',
        'two-factor.secret-key',
        // 2FA enrollment 開始 (T124)。force=true が seed とリカバリコードを差し替える
        'two-factor.enable',
```

`attachRecentAuthToSensitiveRoutes()` 自体は**変更不要**（定数を回す booted callback のまま）。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（定数のみの変更。`@var list<string>` を維持）
- [x] null 安全（`appendMiddlewareIfMissing()` は `$route !== null` を既にチェック）
- [x] DTO を返している（レスポンスに触れない）
- [x] Generics の型パラメータが正しい（`list<string>` のまま）

### テスト計画

**新規 `tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php`**
（`TwoFactorRecoveryCodesStepUpTest` と同じ 4 系統構成に揃える。データは `User::factory()->withTwoFactor()`）

- `test('鮮度なしの GET QR コード (Accept: application/json) は 409 recent_auth_required で svg も url も返さない')`
  - `->get('/user/two-factor-qr-code', ['Accept' => 'application/json'])` で**実ヘッダ条件**を固定する
    （`getJson()` ヘルパ任せにしない。フロントの素 `fetch` が同じ条件で 409 契約へ入ることの証拠にする）
  - `assertStatus(409)` / `assertJsonPath('code', 'recent_auth_required')`
  - `assertJsonMissingPath('svg')` / `assertJsonMissingPath('url')`
- `test('鮮度なしの GET セットアップキーは 409 で secretKey を返さない')` — 同上、`secretKey` の不在を検査
- `test('鮮度なしの通常 GET (Accept: text/html) は recent-auth confirm へ 302 する')`
  - `assertRedirect(route('recent-auth.confirm'))`（302 分岐の契約も固定する）
- `test('fresh なら QR とセットアップキーが実際に秘密を返す (負のコントロール)')`
  - `withSession(['recent_auth_at' => time()])` で 200
  - `secretKey` が `$user->two_factor_secret` の復号値と**一致**することを検査
    （「常に失敗するから green」という空振りを排除する。遮断に意味があることの証拠）
- `test('Cache-Control: no-store が 409 応答に付く')` — 既存 `RequireRecentAuth` 契約の回帰

**新規 `tests/Feature/Auth/TwoFactorEnableStepUpTest.php`**

- `test('鮮度なしの POST enable (XHR) は 409 で two_factor_secret を作らない')`
  - `User::factory()->create()`（2FA 未設定）→ `postJson('/user/two-factor-authentication')`
  - 409 / `two_factor_secret` が null のままであること
- `test('鮮度なしの POST enable force=true は確立済み seed とリカバリコードを差し替えない (ロックアウト回帰)')`
  - `User::factory()->withTwoFactor()->create()` → `$user->refresh()`
  - **前提の明示固定**: `expect($user->two_factor_confirmed_at)->not->toBeNull()`
    （Factory が confirmed_at を立てることに暗黙依存すると、Factory 変更で
    「**確立済み** 2FA に対する差し替え」というテストの意味が沈黙して薄れる）
  - `$before = [two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at]`
  - `postJson('/user/two-factor-authentication', ['force' => true])` → 409
  - `$user->refresh()` 後に **3 カラムとも不変**であること（本施策の中心的な回帰テスト）
- `test('鮮度なしの通常 POST enable は recent-auth confirm へ 302 する')`
- `test('fresh なら force=true が seed を実際に差し替え、confirmed_at は触られない (負のコントロール)')`
  - `withSession(['recent_auth_at' => time()])` を与える
  - `two_factor_secret` / `two_factor_recovery_codes` は**変化**すること
  - `two_factor_confirmed_at` は**不変**であること
    （= Fortify が confirmed_at を触らない = 「誰も知らない秘密で TOTP を要求し続ける」
    ロックアウトが成立する仕組みそのもの。この事実が変わったら設計の前提が変わるので
    テストで固定する）
- `test('2FA 必須組織の未準拠メンバーでも enable は 2FA ゲートに阻まれない')`
  - `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES` に `two-factor.enable` が
    元から入っているため、**recent-auth 追加後も遮断の理由が step-up 側だけ**であることを固定する
    （409 の `code` が `recent_auth_required` であって `two_factor_required` でないこと）

**既存テストの更新**
- `tests/Architecture/RecentAuthRouteTest.php`: allowlist に 3 本追加（表駆動のため他は自動）
- `tests/Feature/Security/AuthThrottleCoverageTest.php`: **計画どおり**
  2FA 秘密 GET レーン検査に `withSession(['recent_auth_at' => time()])` を付与する
  （テストが落ちるかどうかを変更の条件にしない。
  **検査意図・閾値・limiter 名・アサーションは 1 文字も変えない**）
- 既存テストの削除・無効化・緩和は**ゼロ**

**個別 `DatabaseTransactions` は使わない**（`tests/Pest.php` のグローバル `RefreshDatabase` に従う）

### リスク
- **`AuthThrottleCoverageTest` の巻き添え赤**: 上記のとおり 1 行で解消できる想定だが、
  実装時に確認する。もし throttle 検査が recent-auth 通過を前提にできない構造なら、
  そのテストの意図（レーン分離の behavioral 固定）を壊さない形で fresh session を与える。
- **route:cache との関係**: `attachRecentAuthToSensitiveRoutes()` は cached 起動でも
  `CompiledRouteCollection` の nameCache 経由で同一 instance に効く（既存 docblock の主張）。
  本施策で前提を増やさない。ただし throttle 後付け側の運用要件
  （`php artisan route:cache` を毎デプロイ再生成）は引き続き有効。
- **enrollment 導線の摩擦**: ログイン直後 15 分（`auth.recent_auth_timeout`）は
  `StampRecentAuthOnLogin` により fresh のため、多くの利用者はモーダルを見ない。
  15 分超で放置した利用者だけが 1 回の再認証を求められる（施策 4 が詰みを防ぐ）。

---

## 施策 2: `two-factor` route の step-up deny-by-default 目録 gate

### 変更箇所
- `app/Enums/Security/TwoFactorStepUpExemption.php`（新規）
- `tests/Support/Security/RecentAuthMiddleware.php`（新規。判定点の単一化）
- `tests/Architecture/TwoFactorStepUpInventoryTest.php`（新規）
- `tests/Architecture/RecentAuthRouteTest.php`（`routeHasRecentAuth()` を共有判定へ委譲）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `RecentAuthRouteTest.php`（判定の重複を消す。**削除ではなく委譲**）

### 現行コード

`tests/Architecture/RecentAuthRouteTest.php`（判定が 1 箇所に閉じている＝新 gate から呼べない）:

```php
function routeHasRecentAuth(RoutingRoute $route): bool
{
    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }
        // alias 'recent-auth' / 'recent-auth:param' / 完全クラス名のいずれかを許容 (堅牢化)
        if ($middleware === RequireRecentAuth::class || str_starts_with($middleware, 'recent-auth')) {
            return true;
        }
    }

    return false;
}
```

`app/Enums/Security/TwoFactorStepUpExemption.php`: **存在しない**（実査で確認）。
`app/Enums/Security/` には `JobDedupExemption` / `GatewayFailureObservationExemption` /
`ControllerAuthorizationExemption` / `ThrottleCoverageExemption` の 4 本がある。

### 変更後コード

**`app/Enums/Security/TwoFactorStepUpExemption.php`（新規）**

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「route 名に `two-factor` を含む route が recent-auth (step-up) を持たないことが正しい」と
 * 裁定された理由の分類。
 *
 * `tests/Architecture/TwoFactorStepUpInventoryTest.php` が deny-by-default で
 * 「recent-auth を持つ」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★case は「route の識別子」ではなく「**免除してよい理由の型**」である
 *   (ThrottleCoverageExemption と同じ流儀。1 route 1 case にすると enum が route 名の
 *    写しになり、「同じ理由の免除が増えていないか」という目録の主目的が消える)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「recent-auth を貼るべき route」である。
 */
enum TwoFactorStepUpExemption: string
{
    /**
     * 未認証 (guest) で到達する第二要素チャレンジ面。
     *
     * 適用条件 (すべて満たすこと):
     *  - route middleware に `guest:` guard を持ち、認証済みでは到達できない
     *  - session に認証主体が存在せず、**step-up の概念が定義不能**である
     *  - その route 自体が第二要素の検証側 (satisfier) であり、
     *    自分自身に step-up を要求すると構造的に詰む
     */
    case PreAuthChallengeSurface = 'pre_auth_challenge_surface';

    /**
     * 成立に「その場では生成できない秘密の所持証明」を要求する route。
     *
     * 適用条件 (すべて満たすこと):
     *  - 成立条件が TOTP コード等の**所持証明**であり、session 保持だけでは成立しない
     *  - 応答が秘密を**開示しない**
     *  - 既存の第二要素を**除去・差し替えしない**
     */
    case ProofOfSecretPossessionRequired = 'proof_of_secret_possession_required';
}
```

**`tests/Support/Security/RecentAuthMiddleware.php`（新規）**

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Security;

use App\Http\Middleware\RequireRecentAuth;
use Illuminate\Routing\Route as RoutingRoute;

/**
 * route に recent-auth (step-up) middleware が付いているかの**唯一の判定点**。
 *
 * RecentAuthRouteTest (allowlist 型) と TwoFactorStepUpInventoryTest (deny-by-default 目録型)
 * の 2 つの gate が同じ述語を使う。判定を各テストに複製すると、片方だけ堅牢化されて
 * 「一方は付いていると言い、他方は付いていないと言う」ドリフトが起きる。
 */
final class RecentAuthMiddleware
{
    /**
     * 実効 middleware 列に含まれる recent-auth 系 entry の**種類数**を返す。
     *
     * ★数えるのは「種類」であって「登録回数」ではない (誇張しない):
     *   `Route::gatherMiddleware()` は `Router::uniqueMiddleware()` を通すため、
     *   **同一文字列**の二重登録は framework が畳んで 1 本になる (実査: Laravel 12
     *   `Routing/Router::uniqueMiddleware()` が値をキーに `$seen` で除去)。
     *   したがって同一 alias の重複は**実行時に観測できず、振る舞いにも差が出ない**。
     *   観測できない差分に gate を置くのは偽陽性を生むだけなので、そこは検査しない。
     *
     * ★検査する価値があるのは **別種の recent-auth が同居する**状態である。
     *   例: `recent-auth` (無条件 step-up) と `recent-auth.on-email-change` (条件付き) が
     *   同一 route に付くと、意図が矛盾した二重ゲートになる (どちらが真の契約か読めない)。
     *   これは別文字列なので dedup されず、ここで 2 と数えられる。
     *
     * 受理する entry を**厳密に**限定する (`recent-authentication` のような将来の別 alias を
     * 巻き込んで数えないため):
     *   - `recent-auth` 完全一致
     *   - `recent-auth:` 前方一致 (パラメータ付き)
     *   - `recent-auth.` 前方一致 (`recent-auth.on-email-change` 等の派生 alias)
     *   - `RequireRecentAuth::class` 完全一致
     */
    public static function countAttachedKinds(RoutingRoute $route): int
    {
        $count = 0;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if ($middleware === RequireRecentAuth::class
                || $middleware === 'recent-auth'
                || str_starts_with($middleware, 'recent-auth:')
                || str_starts_with($middleware, 'recent-auth.')) {
                $count++;
            }
        }

        return $count;
    }

    /** 1 種類以上付いているか (allowlist 型 gate 用の薄いラッパ。既存の意味を変えない)。 */
    public static function isAttached(RoutingRoute $route): bool
    {
        return self::countAttachedKinds($route) > 0;
    }
}
```

`tests/Architecture/RecentAuthRouteTest.php` は委譲に変える（**振る舞い不変**）:

```php
use Tests\Support\Security\RecentAuthMiddleware;

function routeHasRecentAuth(RoutingRoute $route): bool
{
    return RecentAuthMiddleware::isAttached($route);
}
```

**`tests/Architecture/TwoFactorStepUpInventoryTest.php`（新規）**

```php
<?php

declare(strict_types=1);

use App\Enums\Security\TwoFactorStepUpExemption;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\RecentAuthMiddleware;

/*
 * 2FA 面の step-up (recent-auth) 付与漏れ invariant (deny-by-default)。
 *
 * 「route 名に `two-factor` を含む route は recent-auth を持つか、
 *   TwoFactorStepUpExemption + 30 文字以上の根拠で免除登録されている」を機械強制する。
 *
 * ★保証範囲 (誇張しない): セレクタは**名前ベース**である。`mfa.*` / `security.*` のような
 *   別名で第二要素の状態に触る route を将来足した場合、本 gate は**沈黙する**。
 *   別名で第二要素へ触る route を足すときは、この inventory の母集団設計も同時に見直すこと。
 *   (命名規約そのものを強制する仕組みは意図的に作っていない = 過大)
 *
 * ★RecentAuthRouteTest (allowlist 型) との役割分担:
 *   あちらは「機微操作の名指し表に付いているか」、こちらは「2FA 名前空間に未分類が無いか」。
 *   判定述語は Tests\Support\Security\RecentAuthMiddleware に単一化してドリフトを防ぐ。
 */

/** 母集団セレクタ: route 名に `two-factor` を含む全 route。 */
function twoFactorStepUpPopulation(): array
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    /** @var array<string, RoutingRoute> $matched */
    $matched = [];
    foreach ($routes as $route) {
        $name = $route->getName();
        if (is_string($name) && str_contains($name, 'two-factor')) {
            $matched[$name] = $route;   // route 名は一意
        }
    }
    ksort($matched);

    return $matched;
}

/**
 * 母集団件数の **exact fit**。
 * ★下限だけでは「セレクタが壊れて 0 件」は検出できても「Fortify が 1 本足した」を見逃す。
 *   exact なら増減のどちらも必ず差分として現れ、分類の再検討を強制できる。
 *   実測値 (php artisan route:list --json): Fortify 9 本 + アプリ 2 本 = 11 本。
 */
function twoFactorStepUpPopulationSize(): int
{
    return 11;
}

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function twoFactorStepUpReasonMinLength(): int
{
    return 30;
}

/** exemption 件数の上限。**現在値ちょうど** (exact fit)。上げる前に必ず再検討すること。 */
function twoFactorStepUpExemptionCap(): int
{
    return 3;
}

/**
 * case 別上限 (分類の偏り検出)。全体 cap とは役割が違うので array_sum で導出しない。
 *
 * @return array<string, int>
 */
function twoFactorStepUpExemptionCapByCase(): array
{
    return [
        TwoFactorStepUpExemption::PreAuthChallengeSurface->value => 2,
        // ★ここが膨らむ = 「秘密を開示する route を所持証明つきとして逃がした」疑い。
        TwoFactorStepUpExemption::ProofOfSecretPossessionRequired->value => 1,
    ];
}

/**
 * **免除にできない route** の名指し固定。ここに載る route が exemption 側へ移されたり
 * recent-auth を失ったら fail する (この gate の存在理由そのものを守る = 空振り防止の核)。
 *
 * 2 系統をまとめて持つ:
 *  (a) 秘密の**開示** — 読めば第二要素を複製できる
 *  (b) 第二要素の**除去・差し替え** — 書けば正規ユーザーを締め出せる
 *
 * ★組織管理側の 2 本 (organizations.members.two-factor.reset /
 *   organizations.two-factor-requirement.update) は**入れない**。
 *   脅威系統が違い (管理者操作であり Gate 認可が別途かかる)、
 *   RecentAuthRouteTest の allowlist が既に名指しで固定しているため二重管理になる。
 *
 * @return list<string>
 */
function twoFactorNonExemptibleRoutes(): array
{
    return [
        // (a) 秘密の開示
        'two-factor.qr-code',        // otpauth:// URL (秘密を内包) と QR SVG
        'two-factor.secret-key',     // 平文 TOTP seed
        'two-factor.recovery-codes', // TOTP を伴わないログイン成立手段
        // (b) 第二要素の除去・差し替え
        'two-factor.enable',         // force=true が seed とリカバリコードを差し替える
        'two-factor.disable',        // 第二要素そのものの除去
        'two-factor.regenerate-recovery-codes', // bypass 手段の差し替え
    ];
}

/**
 * recent-auth を持たないことが正しいと裁定した route の inventory。
 *
 * @return array<string, array{TwoFactorStepUpExemption, string}>
 */
function twoFactorStepUpExemptions(): array
{
    $preAuth = TwoFactorStepUpExemption::PreAuthChallengeSurface;
    $possession = TwoFactorStepUpExemption::ProofOfSecretPossessionRequired;

    return [
        'two-factor.login' => [$preAuth,
            'guest:web guard 配下の未認証チャレンジ画面。session に認証主体が存在しないため '
            .'step-up の鮮度判定 (recent_auth_at) が定義不能であり、ここに recent-auth を課すと '
            .'ログインそのものが成立しなくなる。流量制限は fortify.limiters.two-factor が担当する。'],

        'two-factor.login.store' => [$preAuth,
            'two-factor.login と同一 URI の検証側。これ自体が第二要素の検証 = satisfier であり、'
            .'自分自身に step-up を要求すると構造的に詰む。guest:web + throttle:two-factor で '
            .'総当りは有界化されている。'],

        'two-factor.confirm' => [$possession,
            'enrollment の確認。成立には認証アプリが生成した TOTP コードの提示が必要で、'
            .'session 保持だけでは成立しない (秘密の所持証明が前提)。応答は秘密を開示せず、'
            .'既存の第二要素を除去も差し替えもしない (two_factor_confirmed_at を立てるだけ)。'
            .'秘密の入手経路である qr-code / secret-key / enable 側に step-up を課してある。'],
    ];
}

test('母集団が exact fit である (セレクタの空振り / vendor の route 追加を検出)', function (): void {
    $population = twoFactorStepUpPopulation();
    $expected = twoFactorStepUpPopulationSize();

    expect(count($population))->toBe($expected,
        '2FA route の母集団が '.count($population)."件 (期待 {$expected} 件) です。"
        .'セレクタの空振り、または Fortify / アプリ側の route 増減が起きています。'
        .'増えた route を分類してからこの数値を更新してください。'
        .PHP_EOL.implode(PHP_EOL, array_keys($population)));
});

test('母集団の各 route は recent-auth 系 middleware をちょうど 1 種類持つか exemption inventory に明示分類されている (未知は fail)', function (): void {
    $inventory = twoFactorStepUpExemptions();
    $violations = [];

    foreach (twoFactorStepUpPopulation() as $name => $route) {
        $count = RecentAuthMiddleware::countAttachedKinds($route);

        if ($count === 1) {
            continue;
        }
        if ($count === 0 && array_key_exists($name, $inventory)) {
            continue;
        }

        $violations[] = $count === 0
            ? "{$name}: recent-auth が無く exemption inventory にも未登録"
            : "{$name}: 別種の recent-auth middleware が {$count} 本同居している"
                .' (無条件 step-up と条件付き step-up の混在。契約は 1 種類ちょうど)';
    }

    expect($violations)->toBe([],
        '2FA 面の step-up 付与が不正です。recent-auth を貼るか、貼らないことが正しい理由を '
        .'twoFactorStepUpExemptions() に TwoFactorStepUpExemption + 具体的根拠付きで'
        .'登録してください。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption inventory の key は現存する母集団 route (stale 検出)', function (): void {
    $population = twoFactorStepUpPopulation();

    $stale = [];
    foreach (array_keys(twoFactorStepUpExemptions()) as $name) {
        if (! array_key_exists($name, $population)) {
            $stale[] = $name;
        }
    }

    expect($stale)->toBe([],
        'exemption inventory に現存しない route 名があります (削除/rename 済み): '.implode(', ', $stale));
});

test('exemption 登録された route は recent-auth を 1 本も持たない (死んだ exemption の検出)', function (): void {
    $population = twoFactorStepUpPopulation();
    $dead = [];

    foreach (array_keys(twoFactorStepUpExemptions()) as $name) {
        $route = $population[$name] ?? null;
        if ($route instanceof RoutingRoute && RecentAuthMiddleware::isAttached($route)) {
            $dead[] = $name;
        }
    }

    expect($dead)->toBe([],
        'recent-auth が付いているのに exemption が残っています (免除が形骸化しています)。'
        .'inventory から削除してください: '.implode(', ', $dead));
});

test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
    $minLength = twoFactorStepUpReasonMinLength();
    $violations = [];

    foreach (twoFactorStepUpExemptions() as $name => [$exemption, $reason]) {
        if (! $exemption instanceof TwoFactorStepUpExemption) {
            $violations[] = "{$name}: 第 1 要素が TwoFactorStepUpExemption ではありません";
        }
        if (mb_strlen($reason) < $minLength) {
            $violations[] = "{$name}: 理由が {$minLength} 文字未満です";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption 件数が上限ちょうどを超えない (形骸化ガード)', function (): void {
    $count = count(twoFactorStepUpExemptions());

    expect($count)->toBeLessThanOrEqual(twoFactorStepUpExemptionCap(),
        "exemption が {$count} 件あります。免除を増やす前に、その route に step-up を"
        .'課せない構造的理由が本当にあるかを再検討してください。');
});

test('exemption の case 別件数が上限を超えない (分類の偏り検出)', function (): void {
    $caps = twoFactorStepUpExemptionCapByCase();
    $counts = [];

    foreach (twoFactorStepUpExemptions() as [$exemption, $reason]) {
        $counts[$exemption->value] = ($counts[$exemption->value] ?? 0) + 1;
    }

    // 全 case が cap 表に載っていること (case 追加時の登録漏れ検出)
    foreach (TwoFactorStepUpExemption::cases() as $case) {
        expect($caps)->toHaveKey($case->value, "case {$case->value} が cap 表に未登録です");
    }

    $violations = [];
    foreach ($counts as $value => $count) {
        $cap = $caps[$value] ?? 0;
        if ($count > $cap) {
            $violations[] = "{$value}: {$count} 件 (上限 {$cap})";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('免除にできない route は必ず recent-auth 系 middleware をちょうど 1 種類持つ (免除側へ移されたら fail)', function (): void {
    $population = twoFactorStepUpPopulation();
    $inventory = twoFactorStepUpExemptions();
    $violations = [];

    foreach (twoFactorNonExemptibleRoutes() as $name) {
        $route = $population[$name] ?? null;
        if (! $route instanceof RoutingRoute) {
            $violations[] = "{$name}: 母集団に存在しません (rename / 削除?)";

            continue;
        }
        if (RecentAuthMiddleware::countAttachedKinds($route) !== 1) {
            $violations[] = "{$name}: 秘密の開示 / 第二要素の差し替え経路なのに recent-auth 系 middleware が 1 種類ではありません";
        }
        if (array_key_exists($name, $inventory)) {
            $violations[] = "{$name}: この route は exemption にできません";
        }
    }

    expect($violations)->toBe([],
        '秘密開示 / 第二要素差し替え route の step-up は免除できません (T124 の存在理由そのものです)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（enum は `: string` backed、`TwoFactorStepUpExemption` の
      case は全て string 値。`tests/Support` のクラスは `static function isAttached(): bool`）
- [x] null 安全（`$population[$name] ?? null` + `instanceof RoutingRoute` で narrowing。
      `Route::getFacadeRoot()` の戻りは `@var Router` で明示）
- [x] DTO を返している（本施策はレスポンスに触れない）
- [x] Generics の型パラメータが正しい（`array<string, RoutingRoute>` /
      `array<string, array{TwoFactorStepUpExemption, string}>` を docblock で明示）
- [x] `app/` に増えるのは enum 1 本のみ（PHPStan 解析対象）。`tests/` は解析対象外だが
      同水準の型注釈を書く

### テスト計画（gate 自身の赤化検証）

> **この施策は「テストを足す」施策なので、素の main では赤にならない。
> したがって「本当に検出できるのか」を実測で示す手順を設計に含める。**

**Step A: 未対応状態での自然な赤（テストファースト）**

施策 2 のファイル群だけを先に置き、**施策 1 の配線を入れる前に**実行する。

```bash
composer test -- tests/Architecture/TwoFactorStepUpInventoryTest.php
```

期待: 「母集団の各 route は recent-auth を持つか…」が **`two-factor.enable` /
`two-factor.qr-code` / `two-factor.secret-key` の 3 本ちょうど**を列挙して fail する。
（`two-factor.confirm` / `two-factor.login` / `two-factor.login.store` は exemption 済みなので出ない。
 出るなら inventory の書き間違い）
実測出力を `devnotes/20260807-2032-todo-T124-design/impl-step2-fail-observation.md` に残す。

**Step B: 施策 1 を入れて green** — 同じコマンド (`composer test -- <path>`) で全テスト passed。

**Step C: mutation で「空振り green ではない」ことを確認**

以下を **1 つずつ**適用 → 失敗を確認 → **必ず revert** する。結果は
`devnotes/20260807-2032-todo-T124-design/mutation-observation.md` に記録する。

| # | mutation | 期待する fail |
|---|---|---|
| m1 | `RECENT_AUTH_ROUTE_NAMES` から `'two-factor.secret-key'` を 1 本抜く | 「未分類」検査が `two-factor.secret-key` を列挙して fail + 「免除にできない route」検査も fail |
| m2 | `twoFactorStepUpExemptions()` に `'two-factor.nonexistent' => [...]` を足す | stale 検出が fail |
| m3 | `twoFactorStepUpExemptions()` に `'two-factor.disable' => [...]`（recent-auth 済み）を足す | 死んだ exemption 検出が fail |
| m4 | `two-factor.confirm` の理由を `'N/A'` に短縮 | 30 文字検査が fail |
| m5 | `two-factor.qr-code` を exemption inventory へ移し、`RECENT_AUTH_ROUTE_NAMES` から外す | 「この route は exemption にできません」が fail（全体 cap 超過も同時に fail） |
| m6 | セレクタを `str_contains($name, 'two-factor.')`（ドット付き）に狭める | 母集団 exact fit が 9 件で fail（アプリ側 2 本の取りこぼしを検出） |
| m7 | `twoFactorStepUpPopulationSize()` を 12 にする | 母集団 exact fit が fail（数値だけ書き換える運用を防げることの確認） |
| m8 | `FortifyServiceProvider::CONDITIONAL_RECENT_AUTH_ROUTES` に `'two-factor.disable' => 'recent-auth.on-email-change'` を足す（= 無条件 step-up と条件付き step-up の**別種同居**を作る） | 「1 種類ちょうど」検査が「別種の recent-auth middleware が 2 本同居している」で fail<br>※ **同一 alias の重複では赤にならない**（`Router::uniqueMiddleware()` が畳むため実行時にも観測できない）。この非対称を m8 の観測記録に明記すること |

**空振り防止の総括（設計上の保証）**
- 母集団 0 件 → exact fit (11) が fail するので**必ず**気づく
- 母集団が増えた → exact fit が fail し、分類を強制する
- exemption が形骸化 → 死んだ exemption 検出と exact-fit cap (全体 3 / case 別) が fail
- gate の目的が骨抜きに → 免除にできない 6 本の名指し固定が fail
- 別種の step-up が同居した → 「1 種類ちょうど」検査が fail
  （`recent-auth` と `recent-auth.on-email-change` の混在 = 契約が読めない状態）
- **検査しないこと（誇張しない）**: 同一 alias の重複登録は
  `Route::gatherMiddleware()` が `Router::uniqueMiddleware()` で畳むため
  **実行時に観測できず振る舞いにも差が出ない**。観測できない差分に gate は置かない
  （置くと偽陽性しか生まない）。`RouteThrottleBinder` が throttle で「2 本以上は fail」に
  しているのは、`throttle:6,1` と `throttle:named` が**別文字列で実効上限が半減する**
  ためであり、事情が異なる

### リスク
- **`str_contains($name, 'two-factor')` の過剰一致**: 将来 `two-factor-…` を含む無関係な
  route 名が生えると母集団に入る（例: 説明ページ）。その場合も exact fit が fail するので
  **沈黙はしない**。分類（recent-auth 不要なら exemption + 理由）を強制するだけで済む。
- **`gatherMiddleware()` が controller を container 解決する**: Architecture テスト実行時なので
  boot 中の副作用（`RouteThrottleBinder` の docblock が警告している問題）は起きない。
  既存 `RecentAuthRouteTest` と同条件。
- **Pest のグローバル関数名衝突**: 新規関数名は `twoFactorStepUp…` prefix で一意にする。
  `routeHasRecentAuth()` は既存の 1 箇所のみに残し、実体を `Tests\Support` へ移す。

---

## 施策 3: 2FA 必須ゲートの allowlist に passkey satisfier を追加

### 変更箇所
- `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` L41-65（`ALLOWED_ROUTE_NAMES`）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル:
  - `tests/Architecture/TwoFactorEnforcementAllowlistTest.php` — **変更不要**
    （表駆動で「全 name の実在 + 理由非空」を検査する構造。2 本追加は自動でカバー）
  - `tests/Feature/Organizations/TwoFactorEnforcementTest.php` — dataset は
    `array_keys(ALLOWED_ROUTE_NAMES)` 由来なので自動。ただし**明示ケースを 1 本足す**（後述）

### 現行コード

```php
        'recent-auth.confirm' => '機微操作前の step-up 画面 (2FA 設定動線が要求し得る)',
        'recent-auth.status' => 'step-up 状態の確認 (XHR precheck)',
        'recent-auth.password' => 'password による step-up 完了',
        // {intent} は login/register/link/step-up 共用だが、認証済みユーザーの主用途は
        // step-up (SSO-only ユーザーの再認証)。link を許してもゲート解除にはならない
        'social.redirect' => 'SSO step-up の開始 (SSO-only ユーザーの再認証)',
        'social.callback' => 'SSO step-up の callback',
```

### 変更後コード

```php
        'recent-auth.confirm' => '機微操作前の step-up 画面 (2FA 設定動線が要求し得る)',
        'recent-auth.status' => 'step-up 状態の確認 (XHR precheck)',
        'recent-auth.password' => 'password による step-up 完了',
        // passkey による step-up (T124)。2FA 必須ゲート下の未準拠ユーザーは enrollment
        // (two-factor.enable / qr-code / secret-key) に step-up を要求されるため、
        // satisfier を password と再SSO だけに絞ると **passkey-only ユーザー**
        // (password 未設定・SSO 未連携) が enrollment の入口で手段ゼロになり詰む。
        // これらは satisfier 側であり、通すこと自体は 2FA ゲートの解除にならない
        // (準拠判定は two_factor_confirmed_at のみが決める)。
        'passkey.confirm-options' => 'passkey による step-up の challenge 発行',
        'passkey.confirm' => 'passkey による step-up 完了',
        // {intent} は login/register/link/step-up 共用だが、認証済みユーザーの主用途は
        // step-up (SSO-only ユーザーの再認証)。link を許してもゲート解除にはならない
        'social.redirect' => 'SSO step-up の開始 (SSO-only ユーザーの再認証)',
        'social.callback' => 'SSO step-up の callback',
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`@var array<string, string>` の定数を維持）
- [x] null 安全（`array_key_exists($routeName, self::ALLOWED_ROUTE_NAMES)` は既存のまま）
- [x] DTO を返している（`TwoFactorRequiredResource` は不変）
- [x] Generics の型パラメータが正しい

### テスト計画

- `tests/Feature/Organizations/TwoFactorEnforcementTest.php`
  - 既存 dataset テスト `'allowlist の各 route はゲート中でも settings.security へ redirect されない'`
    は `array_keys(ALLOWED_ROUTE_NAMES)` 駆動のため **2 本が自動で追加**される
    （`passkey.confirm-options` は GET、`passkey.confirm` は POST。
     ダミー URI 生成ロジックはパラメータ無しなのでそのまま通る）
  - **新規** `test('2FA 必須ゲート下の passkey-only ユーザーは passkey step-up の challenge を取得できる')`
    - `tfeCreateOrganization(twoFactorRequired: true)` + `tfeAddMember($organization, 'pending')`
    - `Passkey::factory()->for($member)->create()`
    - `actingAs($member)->getJson('/passkeys/confirm/options')` が
      **`settings.security` へ redirect されない**こと（本施策の直接の回帰。ここは必ず固定する）
    - 加えて `assertOk()` まで固定する（「allowlist は通ったが実用上は壊れている」空振りの排除）。
      期待値は「テストが通る値」ではなく **vendor controller の正常契約から決める**。
      実装着手時に `vendor/laravel/fortify/src/Http/Controllers/` の passkey confirm-options
      controller を読み、正常系の status を確定してから書く
      （読んだ結果 200 でなければその値を書く。走らせて赤かったから緩める、はしない）。
      応答 body の細部 (challenge の中身) には踏み込まない
      （vendor update で意味の無い赤を作らないため）
  - **新規（負のコントロール）** `test('allowlist 外の passkey 管理 route はゲート中に settings.security へ 302')`
    - `passkey.registration-options`（= credential を**増やす**管理経路で satisfier ではない）が
      ゲートに阻まれること。「passkey なら何でも通す」になっていないことの証拠

### リスク
- **allowlist を広げすぎる懸念**: `passkey.confirm*` は step-up の satisfier であり、
  通しても `two_factor_confirmed_at` は立たない = **2FA 必須ゲートの解除にはならない**。
  `passkey.registration-options` / `passkey.store` / `passkey.destroy`（credential 集合を
  増減させる管理経路）は**入れない**。上の負のコントロールで固定する。
- **`passkey.confirm` の副作用**: 成立すると `StampRecentAuthOnPasskeyVerified` が
  `recent_auth_at` を stamp する。ゲート下でも step-up 鮮度が立つのは意図どおり
  （そうしないと enrollment に進めない）。

---

## 施策 4: enrollment の step-up precheck と 409 再開（詰み防止）

### 変更箇所
- `resources/js/lib/recent-auth.ts` L130-160 付近
  （409 判定の型ガードを **export 追加**、`RECENT_AUTH_CONFIRM_PATH` を **export 化**）
- `resources/js/pages/Settings/Security.svelte`
  - L22 の import 文（**変更対象として明示**）:
    `import { withRecentAuth, isRecentAuthRequiredPayload, RECENT_AUTH_CONFIRM_PATH, type RecentAuthStatus } from "@/lib/recent-auth";`
  - L76-91（`guardWithRecentAuth` に optional `onDelegated` を追加）
  - L133-208（素材 fetch と `loadEnrollmentAssets`）
  - L295-315（`enableTwoFactor`）
  - L466-478（再試行ボタン → `retryEnrollmentAssets()` 経由へ）
  - enrollment の Alert 部（step-up 不能時の専用 Alert を 1 つ追加）

### 波及変更
- TypeScript 型定義: `resources/js/lib/recent-auth.ts` に **型ガード関数 1 本を追加**
  （既存 interface は変更なし）
- API Resource/DTO: なし（サーバ側の応答契約は不変）
- テストファイル: `tests/js/lib/recent-auth.test.ts` / `tests/js/pages/SettingsSecurity.test.ts`
- `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts`: **変更不要**
  （`Settings/Security.svelte` は登録済み。`withRecentAuth` / `onStale` 代入形も既に満たしている）

### 現行コード

```ts
/** RecentAuthRequiredDto::CODE と対 (code 厳格一致で自分宛て応答のみ処理する) */
const RECENT_AUTH_REQUIRED_CODE = "recent_auth_required";
/** 遷移を許す唯一の着地 (サーバ由来 URL を無検証でグローバル遷移に使わない) */
const RECENT_AUTH_CONFIRM_PATH = "/recent-auth/confirm";
```

```svelte
    function guardWithRecentAuth(action: () => void): Promise<"fresh" | "stale" | "delegated"> {
        return withRecentAuth({
            onFresh: action,
            onStale: (status) => {
                recentAuthStatus = status;
                pendingAction = action;
                recentAuthOpen = true;
            },
        });
    }
```

```ts
// withRecentAuth の delegated 分岐 (lib/recent-auth.ts)。
// ★onDelegated 未指定だと onFresh にフォールバックする = action がそのまま再実行される
const status = await fetchRecentAuthStatus();
if (status === null) {
    addToast("info", "再認証が必要な場合は確認ページへ移動します。");
    (handlers.onDelegated ?? handlers.onFresh)();
    return "delegated";
}
```

```svelte
    async function fetchStringField(url: string, key: string): Promise<string | null> {
        try {
            return readStringField(await fetchJson<unknown>(url), key);
        } catch {
            return null;
        }
    }

    async function loadEnrollmentAssets(): Promise<void> {
        const generation = ++enrollmentGeneration;
        loadingEnrollmentAssets = true;

        const [qr, secret] = await Promise.all([
            fetchStringField("/user/two-factor-qr-code", "svg"),
            fetchStringField("/user/two-factor-secret-key", "secretKey"),
        ]);

        if (generation !== enrollmentGeneration) return;

        qrSvg = qr;
        setupKey = secret;
        enrollmentAssetsFailed = qr === null && secret === null;
        loadingEnrollmentAssets = false;
    }
```

```svelte
    function enableTwoFactor(): void {
        // 再試行時に前回の素材・エラーを持ち越さない
        resetEnrollmentAssets();
        router.post(
            "/user/two-factor-authentication",
            {},
            { /* onStart / onSuccess: confirming = true; void loadEnrollmentAssets() / onFinish */ },
        );
    }
```

```svelte
                                    <Button
                                        variant="ghost"
                                        onclick={() => void loadEnrollmentAssets()}
                                        loading={loadingEnrollmentAssets}
                                        testId="retry-enrollment-assets-button"
                                    >
```

### 変更後コード

**`resources/js/lib/recent-auth.ts`**（`RECENT_AUTH_REQUIRED_CODE` は既存のまま使い回し、
`RECENT_AUTH_CONFIRM_PATH` は `export const` に変えるだけ = 値も用途も不変）

```ts
/**
 * XHR 応答が recent-auth の 409 契約か。**status だけでは判定しない**。
 *
 * 同じ 409 を `RequireTwoFactorForEnforcedOrganizations` も返す
 * (`code: "two_factor_required"`) ため、status のみの判定は**誤食する**。
 * body の形状は信用せず unknown から絞り込む型ガードにする
 * (parseRecentAuthStatus と同じ流儀)。
 *
 * Inertia visit 側の判定 (recentAuthRedirectTarget) と同じ定数を共有し、
 * 判定点を 2 つ作らない。
 */
export function isRecentAuthRequiredPayload(status: number, body: unknown): boolean {
    if (status !== 409) return false;
    if (typeof body !== "object" || body === null) return false;
    return (body as Record<string, unknown>).code === RECENT_AUTH_REQUIRED_CODE;
}
```

**`resources/js/pages/Settings/Security.svelte`**

```svelte
    /**
     * enrollment 素材 1 本の取得結果。
     * `recentAuthRequired` は「取得失敗」とは**別事象**として上位へ返す
     * (409 を「取得失敗」に畳むと、原因と対処が一致しない表示になり再試行が無限に失敗する)。
     */
    interface EnrollmentField {
        value: string | null;
        recentAuthRequired: boolean;
    }

    /**
     * enrollment 素材の単一 endpoint を取得する。
     * ★`Accept: application/json` は**必須**。これが無いと RequireRecentAuth の
     *   expectsJson() が偽になり 302 が返って fetch がリダイレクトを追従するため、
     *   409 判定が一度も成立しない (サーバ側 Feature テストが同じヘッダ条件で固定している)。
     * 秘密が絡む経路なので失敗内容は console にも出さない。
     */
    async function fetchEnrollmentField(url: string, key: string): Promise<EnrollmentField> {
        try {
            const response = await fetch(url, { headers: { Accept: "application/json" } });
            if (!response.ok) {
                const body: unknown = await response.json().catch(() => null);
                return {
                    value: null,
                    recentAuthRequired: isRecentAuthRequiredPayload(response.status, body),
                };
            }
            return { value: readStringField(await response.json(), key), recentAuthRequired: false };
        } catch {
            return { value: null, recentAuthRequired: false };
        }
    }

    /**
     * precheck の結果を返す。
     * ★`onDelegated` を **optional 第 2 引数**として受ける (T124)。
     *   `withRecentAuth` は status 取得失敗時に `onDelegated ?? onFresh` を呼ぶため、
     *   未指定だと「action をそのまま実行してサーバの最終ゲートに委ねる」挙動になる。
     *   これは「1 回きりの mutation」なら正しいが、**409 を受けて自分を再実行する
     *   呼び出し側では無限ループになる** (409 → status 失敗 → 再取得 → 409 …)。
     *   そういう呼び出し側は必ず onDelegated を渡すこと。
     *   既存 4 呼び出し側 (recovery codes 表示 / 再生成 / passkey guard / disable) は
     *   無指定のままで挙動不変。
     */
    function guardWithRecentAuth(
        action: () => void,
        onDelegated?: () => void,
    ): Promise<"fresh" | "stale" | "delegated"> {
        return withRecentAuth({
            onFresh: action,
            onStale: (status) => {
                recentAuthStatus = status;
                pendingAction = action;
                recentAuthOpen = true;
            },
            onDelegated,
        });
    }
```

> **型チェックの注意**: 本リポジトリの `tsconfig.json` は `strict: true` のみで
> `exactOptionalPropertyTypes` は**未設定**（実査済み）のため、上記の
> `onDelegated`（`undefined` を取りうる）をそのまま渡す形で `pnpm typecheck` を通る。
> 将来 `exactOptionalPropertyTypes` を有効化した場合はここが型エラーになるので、
> `...(onDelegated === undefined ? {} : { onDelegated })` の形へ直すこと。

```svelte

    /**
     * step-up を要求されたが状態を確認できず、モーダルを出せなかった状態。
     * 「取得失敗」とは別事象なので別の状態・別の文言・別の導線で出す。
     */
    let enrollmentStepUpBlocked = $state(false);
    /**
     * 自動再開を 1 enrollment につき 1 回に制限するフラグ。
     * サーバの鮮度判定が status と 409 で食い違う異常時でも必ず停止させるための上限であり、
     * **ループを切るのは常に人間の操作**にする (再試行ボタンがこのフラグを戻す)。
     */
    let enrollmentStepUpRetried = false;

    /**
     * enrollment 素材 (QR + 手動セットアップキー) を取得する。
     * ★409 の集約はここ 1 箇所。個別 fetch から guardWithRecentAuth を呼ばない
     *   (QR と secret-key は同一 session の同一鮮度判定なので**両方 409 になるのが通常**であり、
     *    個別に呼ぶとモーダル 2 重起動と pendingAction 上書きが常時発生する)。
     */
    async function loadEnrollmentAssets(): Promise<void> {
        const generation = ++enrollmentGeneration;
        loadingEnrollmentAssets = true;

        const [qr, secret] = await Promise.all([
            fetchEnrollmentField("/user/two-factor-qr-code", "svg"),
            fetchEnrollmentField("/user/two-factor-secret-key", "secretKey"),
        ]);

        // 世代が進んでいる = 破棄済み or 新しい取得が走っている。結果も loading も触らない
        if (generation !== enrollmentGeneration) return;

        // 鮮度切れは「取得失敗」ではない。再認証モーダルを 1 回だけ開き、成立後に同じ取得を再開する
        if (qr.recentAuthRequired || secret.recentAuthRequired) {
            loadingEnrollmentAssets = false;

            // 自動再開の上限。ここを超えたら人間の操作 (再試行ボタン) を待つ
            if (enrollmentStepUpRetried) {
                enrollmentStepUpBlocked = true;

                return;
            }
            enrollmentStepUpRetried = true;

            void guardWithRecentAuth(
                () => void loadEnrollmentAssets(),
                // status 取得失敗 (delegated)。**再取得しない** (ここで再取得すると
                // 409 → status 失敗 → 再取得 の無限ループになる)。
                () => {
                    enrollmentStepUpBlocked = true;
                },
            );

            return;
        }

        qrSvg = qr.value;
        setupKey = secret.value;
        enrollmentAssetsFailed = qr.value === null && secret.value === null;
        loadingEnrollmentAssets = false;
    }

    /** 手動再試行。自動再開の上限を戻すのはここだけ (ループを切るのは人間の操作)。 */
    function retryEnrollmentAssets(): void {
        enrollmentStepUpRetried = false;
        enrollmentStepUpBlocked = false;
        void loadEnrollmentAssets();
    }
```

`resetEnrollmentAssets()` にも 2 状態のリセットを足す:

```svelte
    function resetEnrollmentAssets(): void {
        enrollmentGeneration += 1;
        qrSvg = null;
        setupKey = null;
        enrollmentAssetsFailed = false;
        enrollmentStepUpBlocked = false;
        enrollmentStepUpRetried = false;
        loadingEnrollmentAssets = false;
    }
```

```svelte
    /**
     * 有効化開始。POST /user/two-factor-authentication は recent-auth 必須になった (T124) ため
     * precheck を前段に置く。
     * ★順序が重要: step-up を enrollment の**最初**の操作にすることで、inline throttle の
     *   共有 bucket (同一 actor の全 inline route が 1 bucket) の残量が最大の時点で
     *   recent-auth.password (max 6 = 最小) を通す。enable → confirm リトライの**後**に
     *   step-up が回ると、TOTP を数回打ち間違えた利用者が再認証で 429 になる。
     */
    function enableTwoFactor(): void {
        void guardWithRecentAuth(() => {
            // 再試行時に前回の素材・エラーを持ち越さない
            resetEnrollmentAssets();
            router.post(
                "/user/two-factor-authentication",
                {},
                {
                    preserveScroll: true,
                    onStart: () => {
                        enabling = true;
                    },
                    onSuccess: () => {
                        confirming = true;
                        void loadEnrollmentAssets();
                    },
                    onFinish: () => {
                        enabling = false;
                    },
                },
            );
        });
    }
```

テンプレート側は `onclick` を `retryEnrollmentAssets` に差し替え、step-up 不能状態の
Alert を 1 つ足す（行き先のない詰みを作らない）:

```svelte
                        {:else if enrollmentStepUpBlocked}
                            <Alert
                                type="warning"
                                title="再認証が必要です"
                                testId="enrollment-step-up-blocked"
                            >
                                2 要素認証の設定情報を表示するには再認証が必要です。
                                <TextLink href={RECENT_AUTH_CONFIRM_PATH}>再認証ページ</TextLink>
                                で本人確認を済ませてから、もう一度お試しください。
                                {#snippet action()}
                                    <Button
                                        variant="ghost"
                                        onclick={retryEnrollmentAssets}
                                        loading={loadingEnrollmentAssets}
                                        testId="retry-enrollment-step-up-button"
                                    >
                                        再試行
                                    </Button>
                                {/snippet}
                            </Alert>
                        {:else if enrollmentAssetsFailed}
                            <!-- 既存の「設定情報を取得できませんでした」Alert。onclick だけ差し替え -->
                                    <Button
                                        variant="ghost"
                                        onclick={retryEnrollmentAssets}
                                        loading={loadingEnrollmentAssets}
                                        testId="retry-enrollment-assets-button"
                                    >
```

★`testId` は 2 つの Alert で重複させない。step-up 側は `retry-enrollment-step-up-button`、
取得失敗側は既存の `retry-enrollment-assets-button` のまま
（テストの選択子が曖昧にならないようにする。上のコード例は最終形で書いてある）。
★遷移先は `RECENT_AUTH_CONFIRM_PATH`（`lib/recent-auth.ts` の既存定数を export して共有）。
サーバ由来 URL は使わないので same-origin 検証を新設する必要がない。

### PHPStan適合チェック
本施策は TypeScript のみ。対応する検証は:
- [x] `pnpm typecheck`（`tsc --noEmit`）— `EnrollmentField` interface / 型ガードの戻り型を明示
- [x] `pnpm lint`（eslint + prettier）
- [x] body の形状を `unknown` から絞り込む（`as` の乱用をしない。既存 `parseRecentAuthStatus` に倣う）
- [x] DESIGN.md 準拠: **UI の見た目・token を変更しない**（既存 Alert / Button をそのまま使う）
- [x] Atomic Design 準拠: 変更は `pages/` と `lib/` のみ。新規コンポーネント無し、SVG 直書き無し
- [x] 禁止事項 8: 条件未充足での `disabled` 化を**しない**（押下 → precheck → モーダル）

### テスト計画

**`tests/js/lib/recent-auth.test.ts`（追記）**
- `it('409 + code=recent_auth_required を true と判定する')`
- `it('409 + code=two_factor_required を false と判定する（2FA 必須ゲートの 409 を誤食しない）')`
  ← **負のコントロールの核**
- `it('200 + code=recent_auth_required を false と判定する')`
- `it('body が null / 文字列 / 配列でも例外を投げず false')`

**`tests/js/pages/SettingsSecurity.test.ts`（追記。既存の fetch mock ハーネスを流用）**
- `it('有効化ボタンは stale なら再認証モーダルを開き、enable を POST しない')`
  - `/recent-auth/status` を `{recent: false, ...}` に stub
  - `routerPostMock` が `/user/two-factor-authentication` で呼ばれて**いない**こと
  - `RecentAuthModal` が表示されること
- `it('有効化ボタンは fresh なら enable を POST する')`（負のコントロール）
- `it('素材取得が両方 409 でも再認証モーダルの起動は 1 回だけ')`
  - qr / secret 両方を `{ok:false, status:409, json: {code:"recent_auth_required"}}` に stub
  - `/recent-auth/status` の fetch 呼び出しが **1 回**、モーダルが 1 つだけ描画されること
  - 「設定情報を取得できませんでした」Alert が**出ない**こと（409 を取得失敗に畳まない）
- `it('片方だけ 409 でも再認証モーダルへ倒す')`（部分的鮮度切れの一貫性）
- `it('409 以外の失敗 (500) は従来どおり取得失敗 Alert を出し、モーダルを開かない')`
  ← **通常エラーを step-up へ誤分類しないことの負のコントロール**
- `it('素材が 409 かつ /recent-auth/status が 500 のとき再取得ループしない')`
  ← **[Critical] delegated ループ回帰。この設計の中心的な安全性テスト**
  - qr / secret を 409、`/recent-auth/status` を `{ok:false, status:500}` に stub
  - `fetch` の総呼び出し回数が**有界**であること
    （素材 2 本 + status 1 本 = 3 回で止まる。4 回目以降が発火しないこと）
  - `data-testid="enrollment-step-up-blocked"` の Alert が出ること
  - モーダルが開かないこと
- `it('step-up 不能 Alert の再試行ボタンは自動再開の上限を戻して再取得する')`
  - 上の状態から `retry-enrollment-step-up-button` を押すと fetch が再発火すること
    （人間の操作でループが切れる = 上限が「詰み」にならないことの確認）
- `it('再認証成立後に素材取得が再開され QR とセットアップキーが表示される')`
  - `pendingAction` の resume 契約（モーダルの `onConfirmed`）

**Browser テスト**: 追加しない（既存 Chromium/WebKit 2 レーンの契約は変えない。
本変更は JSON fetch の分岐でありブラウザ差の対象ではない）。

### リスク
- **[Critical 起因] 自動再実行ループ**: `withRecentAuth` は status 取得失敗 (delegated) 時に
  `onDelegated ?? onFresh` を呼ぶ。`onDelegated` を渡さないと
  **409 → status 失敗 → 再取得 → 409 …** がユーザー操作なしで回り続ける
  （Codex Round 1 [Critical]。設計初版の見落とし）。
  → **3 重で止める**:
  (1) 409 分岐は `onDelegated` を**必ず**渡し、そこでは再取得しない
      （`enrollmentStepUpBlocked` を立てて Alert + 再認証ページ導線を出す）。
  (2) 自動再開は `enrollmentStepUpRetried` で **1 enrollment につき 1 回**に制限する。
      status が fresh を返し続けるのに素材が 409 を返し続ける（サーバ側の判定不整合）
      という異常時にも必ず停止する。
  (3) 上限に達した後にループを切れるのは**人間の操作**（再試行ボタン）だけにする。
  この 3 つを JS テストで固定する（fetch 呼び出し回数が有界であることを直接検査）。
- **世代管理との相互作用**: 409 分岐は `generation !== enrollmentGeneration` の後に置くため、
  破棄済みの取得が再認証モーダルを開くことはない。
- **`enableTwoFactor` の precheck 遅延**: `/recent-auth/status` の往復ぶん押下から POST まで
  遅れる。既存の `regenerateRecoveryCodes` / `disableTwoFactor` と同じ体験であり、
  `enabling` loading 状態は `onStart` で立つ。**precheck 区間は loading に覆われない**
  （既存の `showRecoveryCodes` と同じ既知の挙動。ここだけ変えると一貫性が崩れるため揃える）。

---

## 施策 5: 運用契約の記録

### 変更箇所
- `AGENTS.md` §ドメイン固有規約（新項目を 1 つ追加）
- `docs/architecture.md`（2FA 面の step-up 契約の節を追加、または既存の認証節へ追記）

### 波及変更
- テストファイル: なし（ドキュメント）
- ただし `tests/js/architecture/verification-commands-doc-sync.test.ts` が守るマーカー領域には触れない

### 変更後コード（AGENTS.md 追記案）

```markdown
8. **2FA 面の step-up (recent-auth) 規約**: route 名に `two-factor` を含む route は
   **recent-auth 系 middleware をちょうど 1 種類持つ**か、`TwoFactorStepUpExemption` +
   30 文字以上の根拠付きで exemption inventory へ登録する
   (`TwoFactorStepUpInventoryTest` が deny-by-default で強制。母集団は **exact-fit**)。
   - 「1 種類」は `recent-auth` (無条件) と `recent-auth.on-email-change` (条件付き) の
     **同居**を禁じる意味である。同一 alias の重複登録は `Router::uniqueMiddleware()` が
     畳むため実行時に観測できず、検査対象にしていない (誇張しない)。
   - **exemption にできない 6 本**が gate に名指しで固定されている:
     (a) 秘密の開示 3 本 = `two-factor.qr-code` / `two-factor.secret-key` /
     `two-factor.recovery-codes`、
     (b) 第二要素の除去・差し替え 3 本 = `two-factor.enable` / `two-factor.disable` /
     `two-factor.regenerate-recovery-codes`。
     throttle (`two-factor-secret-read`) は**連続取得の回数上限**であって step-up の
     代替ではない。
   - (b) に `two-factor.enable` が入るのは、Fortify の `force=true` が seed とリカバリコードを
     再生成する一方で `two_factor_confirmed_at` を触らないためである。開けたままにすると
     **奪取セッションから永久ロックアウトを作れる**。
   - 組織管理側の 2 本 (`organizations.members.two-factor.reset` /
     `organizations.two-factor-requirement.update`) は目録の母集団には入るが
     non-exemptible 名指しには入れない (脅威系統が違い、`RecentAuthRouteTest` の
     allowlist が既に固定している)。
   - **保証範囲を誇張しない**: セレクタは名前ベースであり、`mfa.*` 等の別名で
     第二要素へ触る route には**沈黙する**。別名の route を足すときは inventory の
     母集団設計も同時に見直すこと。
   - step-up を新しい面に課すときは **satisfier の到達性**を必ず確認する。
     2FA 必須組織のゲート (`RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`) は
     password / 再SSO / **passkey** の 3 satisfier をすべて通す (どれか 1 つでも欠けると
     その手段しか持たないユーザーが詰む)。
```

### PHPStan適合チェック
- 該当なし（ドキュメントのみ）

### テスト計画
- `composer test` / `pnpm test` の全 green を再確認（ドキュメント変更が
  `verification-commands-doc-sync` 等の doc 同期 gate に触れていないこと）

### リスク
- AGENTS.md のドメイン固有規約は既に 7 項目ある。8 項目目として追加する
  （既存項目の番号は**変えない**。他ドキュメントからの参照を壊さないため）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone**（専用 worktree `scripts/setup-worktree.sh <task-id>` 上で実装。**main 直実装はしない**） |
| 判断根拠 | (1) `FortifyServiceProvider` / `RequireTwoFactorForEnforcedOrganizations` / `Settings/Security.svelte` という**認証面の中核**を触るため、他施策と同じ worktree に混ぜると赤の切り分けが困難になる。(2) Architecture gate を新設する施策は「素の main では赤にならない」ため、Step A（配線前の自然な赤）を**単独コミットで実測**する必要があり、他の変更が混ざると観測が濁る。(3) `AuthThrottleCoverageTest` の巻き添え赤を確認する必要があり、テストレーンをグローバルロックで占有する時間が読めない。 |
| 競合リスク | 並列設計中の他 4 件（T125 / T126 / lctl 2 件）が `FortifyServiceProvider` / `tests/Architecture/` / `resources/js/pages/Settings/Security.svelte` を触る場合に衝突しうる。特に `tests/Architecture/` へ新規ファイルを足す設計は衝突しないが、`AGENTS.md` §ドメイン固有規約の**番号**は衝突する（施策 5）。マージ時に番号を振り直すのではなく、**後にマージする側が次の番号を取る**こと。 |

## 実装完了の定義（Definition of Done）

1. AGENTS.md のマーカー領域にある**検証コマンド全件**が green:
   `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
   `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
   （テスト系はそれぞれのラッパー経由で実行し、グローバルテストロックを維持する）
2. 新規テストが実際に走っていること: Feature 2 本 + Architecture 1 本 + JS 2 ファイル
3. `composer phpstan` は level 10 で No errors（新設 enum を含む）
4. 既存の 2FA / recent-auth 系テスト（`RecentAuthRouteTest` / `TwoFactorRecoveryCodesStepUpTest` /
   `TwoFactorEnforcementTest` / `AuthThrottleCoverageTest`）が全て green
5. Step A（配線前の自然な赤）の実測ログが
   `devnotes/20260807-2032-todo-T124-design/impl-step2-fail-observation.md` にある
6. Step C（mutation **m1〜m8**）の実測ログが
   `devnotes/20260807-2032-todo-T124-design/mutation-observation.md` にあり、
   **全 mutation が revert 済み**であること（`git status` clean で確認）。
   m8 については「同一 alias の重複では赤にならない」非対称も記録する
7. 既存テストの削除・無効化・緩和がゼロであること（diff で確認）
8. 実装は `.claude/worktrees/tasks/<task-id>` の worktree 上で行い、main 直実装をしていないこと
   （AGENTS.md §worktree 運用ルール）。テスト実行は `composer test` 経由のみ
   （グローバルテストロックを迂回する `vendor/bin/pest` / `vendor/bin/phpunit` の直叩きをしない）


## 実装差分 (git diff)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 8509234..23447e0 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -372,3 +372,32 @@ ## ドメイン固有規約
    分類は**観測のためであり制御フローを変えない**。`unknown` は「写像表に一致が無かった」
    ことを意味し、写像表の値としては禁止。詳細と運用契約は
    `docs/architecture.md` §オートリチャージの失敗分類。
+8. **2FA 面の step-up (recent-auth) 規約**: route 名に `two-factor` を含む route は
+   **recent-auth 系 middleware をちょうど 1 種類持つ**か、`TwoFactorStepUpExemption` +
+   30 文字以上の根拠付きで exemption inventory へ登録する
+   (`TwoFactorStepUpInventoryTest` が deny-by-default で強制。母集団は **exact-fit**)。
+   - 「1 種類」は `recent-auth` (無条件) と `recent-auth.on-email-change` (条件付き) の
+     **同居**を禁じる意味である。同一 alias の重複登録は `Router::uniqueMiddleware()` が
+     畳むため実行時に観測できず、検査対象にしていない (誇張しない)。
+   - **exemption にできない 6 本**が gate に名指しで固定されている:
+     (a) 秘密の開示 3 本 = `two-factor.qr-code` / `two-factor.secret-key` /
+     `two-factor.recovery-codes`、
+     (b) 第二要素の除去・差し替え 3 本 = `two-factor.enable` / `two-factor.disable` /
+     `two-factor.regenerate-recovery-codes`。
+     throttle (`two-factor-secret-read`) は**連続取得の回数上限**であって step-up の
+     代替ではない。
+   - (b) に `two-factor.enable` が入るのは、Fortify の `force=true` が seed とリカバリコードを
+     再生成する一方で `two_factor_confirmed_at` を触らないためである。開けたままにすると
+     **奪取セッションから永久ロックアウトを作れる**。
+   - 組織管理側の 2 本 (`organizations.members.two-factor.reset` /
+     `organizations.two-factor-requirement.update`) は目録の母集団には入るが
+     non-exemptible 名指しには入れない (脅威系統が違い、`RecentAuthRouteTest` の
+     allowlist が既に固定している)。
+   - **保証範囲を誇張しない**: セレクタは名前ベースであり、`mfa.*` 等の別名で
+     第二要素へ触る route には**沈黙する**。別名の route を足すときは inventory の
+     母集団設計も同時に見直すこと。
+   - step-up を新しい面に課すときは **satisfier の到達性**を必ず確認する。
+     2FA 必須組織のゲート (`RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`) は
+     password / 再SSO / **passkey** の 3 satisfier をすべて通す (どれか 1 つでも欠けると
+     その手段しか持たないユーザーが詰む)。詳細は `docs/architecture.md`
+     §2FA 面の step-up (recent-auth) 契約。
diff --git a/app/Enums/Security/TwoFactorStepUpExemption.php b/app/Enums/Security/TwoFactorStepUpExemption.php
new file mode 100644
index 0000000..157d211
--- /dev/null
+++ b/app/Enums/Security/TwoFactorStepUpExemption.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 「route 名に `two-factor` を含む route が recent-auth (step-up) を持たないことが正しい」と
+ * 裁定された理由の分類。
+ *
+ * `tests/Architecture/TwoFactorStepUpInventoryTest.php` が deny-by-default で
+ * 「recent-auth を持つ」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
+ * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
+ *
+ * ★case は「route の識別子」ではなく「**免除してよい理由の型**」である
+ *   (ThrottleCoverageExemption と同じ流儀。1 route 1 case にすると enum が route 名の
+ *    写しになり、「同じ理由の免除が増えていないか」という目録の主目的が消える)。
+ *
+ * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
+ *   当てはまる case が無ければ、それは「recent-auth を貼るべき route」である。
+ */
+enum TwoFactorStepUpExemption: string
+{
+    /**
+     * 未認証 (guest) で到達する第二要素チャレンジ面。
+     *
+     * 適用条件 (すべて満たすこと):
+     *  - route middleware に `guest:` guard を持ち、認証済みでは到達できない
+     *  - session に認証主体が存在せず、**step-up の概念が定義不能**である
+     *  - その route 自体が第二要素の検証側 (satisfier) であり、
+     *    自分自身に step-up を要求すると構造的に詰む
+     */
+    case PreAuthChallengeSurface = 'pre_auth_challenge_surface';
+
+    /**
+     * 成立に「その場では生成できない秘密の所持証明」を要求する route。
+     *
+     * 適用条件 (すべて満たすこと):
+     *  - 成立条件が TOTP コード等の**所持証明**であり、session 保持だけでは成立しない
+     *  - 応答が秘密を**開示しない**
+     *  - 既存の第二要素を**除去・差し替えしない**
+     */
+    case ProofOfSecretPossessionRequired = 'proof_of_secret_possession_required';
+}
diff --git a/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php b/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php
index 0b31ec5..bd2c378 100644
--- a/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php
+++ b/app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php
@@ -54,6 +54,14 @@ final class RequireTwoFactorForEnforcedOrganizations
         'recent-auth.confirm' => '機微操作前の step-up 画面 (2FA 設定動線が要求し得る)',
         'recent-auth.status' => 'step-up 状態の確認 (XHR precheck)',
         'recent-auth.password' => 'password による step-up 完了',
+        // passkey による step-up (T124)。2FA 必須ゲート下の未準拠ユーザーは enrollment
+        // (two-factor.enable / qr-code / secret-key) に step-up を要求されるため、
+        // satisfier を password と再SSO だけに絞ると **passkey-only ユーザー**
+        // (password 未設定・SSO 未連携) が enrollment の入口で手段ゼロになり詰む。
+        // これらは satisfier 側であり、通すこと自体は 2FA ゲートの解除にならない
+        // (準拠判定は two_factor_confirmed_at のみが決める)。
+        'passkey.confirm-options' => 'passkey による step-up の challenge 発行',
+        'passkey.confirm' => 'passkey による step-up 完了',
         // {intent} は login/register/link/step-up 共用だが、認証済みユーザーの主用途は
         // step-up (SSO-only ユーザーの再認証)。link を許してもゲート解除にはならない
         'social.redirect' => 'SSO step-up の開始 (SSO-only ユーザーの再認証)',
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index 0e05bde..5aeb8b4 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -57,15 +57,27 @@ class FortifyServiceProvider extends ServiceProvider
 {
     /**
      * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
-     * いずれも「確立済み第二要素の bypass / 除去」経路であり、通常セッション認証だけで
-     * 到達させない (姉妹操作: organizations.members.two-factor.reset /
+     * いずれも「第二要素の秘密の露出」または「確立済み第二要素の除去・差し替え」経路であり、
+     * 通常セッション認証だけで到達させない (姉妹操作: organizations.members.two-factor.reset /
      * settings.account.destroy 等と同基準)。
      * - recovery-codes 表示 (GET) / 再生成 (POST): TOTP を伴わないログイン成立手段の露出・更新。
      * - disable (DELETE): 第二要素そのものの無効化 (bug-hunt F-H3)。
      *   ※ 2FA 必須組織の準拠ユーザーは BlockTwoFactorDisableForEnforcedOrganizations
      *     (web group、recent-auth より先行) が 422 で拒否するため、本配線が実効するのは
      *     self-disable が許可される非 enforced 組織のユーザー。
-     * 付与漏れは RecentAuthRouteTest (Architecture) が CI で検出する。
+     * - qr-code / secret-key (GET, T124): TOTP seed そのものの露出。
+     *   Fortify の TwoFactorSecretKeyController は two_factor_secret を**復号して平文で返し**、
+     *   TwoFactorQrCodeController は otpauth:// URL (秘密を内包) を返す。どちらも
+     *   two_factor_confirmed_at を見ないため、**確立済み**第二要素の seed が読める。
+     *   throttle (two-factor-secret-read) は連続取得の回数上限であって step-up の代替ではない。
+     * - enable (POST, T124): Fortify の TwoFactorAuthenticationController は
+     *   `$request->boolean('force')` を EnableTwoFactorAuthentication へ渡す。
+     *   force=true は two_factor_secret と two_factor_recovery_codes を**再生成する一方で
+     *   two_factor_confirmed_at を触らない** (fortify v1.37.2 実査) ため、奪取セッションから
+     *   1 回叩くだけで「誰も知らない秘密で TOTP を要求し続ける」永久ロックアウトを作れる。
+     *   秘密の**読み出し**だけ塞いで**差し替え**を開けたままにしない。
+     * 付与漏れは RecentAuthRouteTest (allowlist) と TwoFactorStepUpInventoryTest
+     * (deny-by-default 目録) の 2 枚で検出する。
      *
      * @var list<string>
      */
@@ -73,6 +85,9 @@ class FortifyServiceProvider extends ServiceProvider
         'two-factor.recovery-codes',
         'two-factor.regenerate-recovery-codes',
         'two-factor.disable',
+        'two-factor.qr-code',
+        'two-factor.secret-key',
+        'two-factor.enable',
     ];
 
     /**
@@ -304,7 +319,8 @@ private function configureRateLimiters(): void
          *   closure が評価される。passkeys limiter と同じく IP へ倒す。
          *
          * ★これは**連続取得の回数上限**であって、秘密の漏えい防止でも step-up の代替でもない。
-         *   認証強度 (recent-auth 化) は aicue:T120 の後続 TODO B2 の担当。
+         *   認証強度は T124 で別途 recent-auth を後付けした (RECENT_AUTH_ROUTE_NAMES)。
+         *   この 2 つは役割が違うので、片方を理由にもう片方を外さないこと。
          */
         RateLimiter::for('two-factor-secret-read', function (Request $request): Limit {
             $identifier = $request->user()?->getAuthIdentifier();
diff --git a/docs/architecture.md b/docs/architecture.md
index 3336b46..c088562 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -932,3 +932,70 @@ ## 外部 fake 配線の不変条件 (T119)
   参照してよいのは配線点と fake storage signed route の受け口を含む 4 ファイルだけで、
   allowlist の件数はテストが固定している (増やすには理由コメントと併せて 2 箇所を触る摩擦がかかる)。
   **誤検出が出ても allowlist を足す方向へ倒さない** — それが gate の目的である。
+
+## 2FA 面の step-up (recent-auth) 契約 (T124)
+
+第二要素そのものを扱う面は、**セッション認証だけでは到達させない**。
+機械強制は 2 枚 (`RecentAuthRouteTest` の allowlist + `TwoFactorStepUpInventoryTest` の
+deny-by-default 目録) で、判定述語は `Tests\Support\Security\RecentAuthMiddleware` に
+単一化してある (2 つの gate が別々に堅牢化されてドリフトするのを防ぐ)。
+
+### 何を守るか
+
+| 系統 | route | 開けたままにすると |
+|---|---|---|
+| (a) 秘密の開示 | `two-factor.qr-code` / `two-factor.secret-key` / `two-factor.recovery-codes` | 奪取セッションから TOTP seed を読み出して**第二要素を複製**できる (以後ログインが素通し) |
+| (b) 第二要素の除去・差し替え | `two-factor.enable` / `two-factor.disable` / `two-factor.regenerate-recovery-codes` | 正規ユーザーを**締め出せる** |
+
+(b) に `two-factor.enable` が入るのは、Fortify の `TwoFactorAuthenticationController` が
+`$request->boolean('force')` をそのまま `EnableTwoFactorAuthentication` へ渡し、
+**force=true が `two_factor_secret` と `two_factor_recovery_codes` を再生成する一方で
+`two_factor_confirmed_at` を触らない**ためである (fortify v1.37.2 実査)。
+奪取セッションから 1 回叩くだけで「誰も知らない秘密で TOTP を要求し続ける」
+**永久ロックアウト**が成立する。秘密の読み出しだけ塞いで差し替えを開けたままにしない。
+
+throttle (`two-factor-secret-read`) は**連続取得の回数上限**であって step-up の代替ではない。
+
+### 目録の契約 (`TwoFactorStepUpInventoryTest`)
+
+- 母集団は **route 名に `two-factor` を含む全 route** で、件数は **exact fit** (現在 11 本)。
+  vendor が 1 本足しても必ず差分として現れ、分類を強制できる。
+- 各 route は **recent-auth 系 middleware をちょうど 1 種類**持つか、
+  `App\Enums\Security\TwoFactorStepUpExemption` + 30 文字以上の根拠で免除登録する。
+  「1 種類」は `recent-auth` (無条件) と `recent-auth.on-email-change` (条件付き) の
+  **同居**を禁じる意味である。同一 alias の重複登録は `Router::uniqueMiddleware()` が畳むため
+  **実行時に観測できず**、検査対象にしていない (誇張しない)。
+- 上の表の **6 本は exemption にできない** (名指しで固定)。免除側へ移されたら fail する。
+- 免除は現在 3 件 (`two-factor.login` / `two-factor.login.store` = 未認証チャレンジ面、
+  `two-factor.confirm` = TOTP の所持証明が前提で秘密を開示しない) で、全体 cap と
+  case 別 cap の両方が exact fit。
+- 組織管理側の 2 本 (`organizations.members.two-factor.reset` /
+  `organizations.two-factor-requirement.update`) は母集団には入るが non-exemptible 名指しには
+  入れない (脅威系統が違い、`RecentAuthRouteTest` の allowlist が既に固定している)。
+- **保証範囲を誇張しない**: セレクタは名前ベースであり、`mfa.*` 等の別名で第二要素へ触る
+  route には**沈黙する**。別名の route を足すときは母集団設計も同時に見直すこと。
+
+### satisfier の到達性 (詰みを作らない側の契約)
+
+step-up を新しい面に課したら、**その面へ到達する前に step-up を満たせる手段**が
+必ず 1 つ以上あることを確認する。2FA 必須組織のゲート
+(`RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`) は
+password (`recent-auth.password`) / 再SSO (`social.redirect` • `social.callback`) /
+**passkey** (`passkey.confirm-options` • `passkey.confirm`) の 3 satisfier をすべて通す。
+どれか 1 つでも欠けると、その手段しか持たないユーザーが enrollment の入口で手段ゼロになり詰む。
+`passkey.registration-options` / `passkey.store` / `passkey.destroy` は credential 集合を
+増減させる**管理**経路であり satisfier ではないので、allowlist に入れない
+(`TwoFactorEnforcementTest` の負のコントロールが固定)。
+
+### クライアント側 (enrollment 動線)
+
+`resources/js/pages/Settings/Security.svelte` は
+**step-up を enrollment の最初の操作に固定する** (有効化ボタン → precheck → POST)。
+inline throttle のキーは actor id だけで route も limiter 名も入らず、同一 actor の
+inline route は 1 bucket を共有するため、enable → confirm リトライの**後**に step-up が回ると
+TOTP を打ち間違えた利用者が再認証 (`recent-auth.password` = max 6 で最小) で 429 になる。
+
+素材 (QR / セットアップキー) の 409 は「取得失敗」とは**別事象**として扱い、
+自動再開は 1 enrollment につき 1 回に制限する。status が取れない (delegated) ときは
+**再取得しない** — 再取得すると 409 → status 失敗 → 再取得 の無限ループになるため、
+`enrollment-step-up-blocked` の Alert と再認証ページ導線を出して**人間の操作**を待つ。
diff --git a/resources/js/lib/recent-auth.ts b/resources/js/lib/recent-auth.ts
index 6f2dfe4..10c0250 100644
--- a/resources/js/lib/recent-auth.ts
+++ b/resources/js/lib/recent-auth.ts
@@ -130,7 +130,24 @@ export async function withRecentAuth(handlers: {
 /** RecentAuthRequiredDto::CODE と対 (code 厳格一致で自分宛て応答のみ処理する) */
 const RECENT_AUTH_REQUIRED_CODE = "recent_auth_required";
 /** 遷移を許す唯一の着地 (サーバ由来 URL を無検証でグローバル遷移に使わない) */
-const RECENT_AUTH_CONFIRM_PATH = "/recent-auth/confirm";
+export const RECENT_AUTH_CONFIRM_PATH = "/recent-auth/confirm";
+
+/**
+ * XHR 応答が recent-auth の 409 契約か。**status だけでは判定しない**。
+ *
+ * 同じ 409 を `RequireTwoFactorForEnforcedOrganizations` も返す
+ * (`code: "two_factor_required"`) ため、status のみの判定は**誤食する**。
+ * body の形状は信用せず unknown から絞り込む型ガードにする
+ * (parseRecentAuthStatus と同じ流儀)。
+ *
+ * Inertia visit 側の判定 (recentAuthRedirectTarget) と同じ定数を共有し、
+ * 判定点を 2 つ作らない。
+ */
+export function isRecentAuthRequiredPayload(status: number, body: unknown): boolean {
+    if (status !== 409) return false;
+    if (typeof body !== "object" || body === null) return false;
+    return (body as Record<string, unknown>).code === RECENT_AUTH_REQUIRED_CODE;
+}
 
 /**
  * `httpException` の `event.detail.response` (Inertia core の `HttpExceptionResponse` =
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index c10c8d9..cd53863 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -19,7 +19,12 @@
     import { Settings } from "@lucide/svelte";
     import { useForm } from "@inertiajs/svelte";
     import type { PasskeyListItem } from "@/lib/passkeys";
-    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
+    import {
+        withRecentAuth,
+        isRecentAuthRequiredPayload,
+        RECENT_AUTH_CONFIRM_PATH,
+        type RecentAuthStatus,
+    } from "@/lib/recent-auth";
     import type { SharedProps } from "@/lib/shared-props";
     import { providerLabel } from "@/lib/social";
     import { addToast } from "@/lib/stores/toast";
@@ -72,8 +77,20 @@
      * precheck の結果 (fresh / stale / delegated) を **返す**。
      * PasskeySection は precheck 区間 (`/recent-auth/status` の待ち時間) を自前の loading で
      * 覆う必要があるため戻り値を待つ。結果に関心が無い呼び出し側は `void` で明示的に捨てる。
+     *
+     * ★`onDelegated` を **optional 第 2 引数**として受ける (T124)。
+     *   `withRecentAuth` は status 取得失敗時に `onDelegated ?? onFresh` を呼ぶため、
+     *   未指定だと「action をそのまま実行してサーバの最終ゲートに委ねる」挙動になる。
+     *   これは「1 回きりの mutation」なら正しいが、**409 を受けて自分を再実行する
+     *   呼び出し側では無限ループになる** (409 → status 失敗 → 再取得 → 409 …)。
+     *   そういう呼び出し側は必ず onDelegated を渡すこと。
+     *   既存 4 呼び出し側 (recovery codes 表示 / 再生成 / passkey guard / disable) は
+     *   無指定のままで挙動不変。
      */
-    function guardWithRecentAuth(action: () => void): Promise<"fresh" | "stale" | "delegated"> {
+    function guardWithRecentAuth(
+        action: () => void,
+        onDelegated?: () => void,
+    ): Promise<"fresh" | "stale" | "delegated"> {
         return withRecentAuth({
             onFresh: action,
             onStale: (status) => {
@@ -81,6 +98,7 @@
                 pendingAction = action;
                 recentAuthOpen = true;
             },
+            onDelegated,
         });
     }
 
@@ -151,13 +169,40 @@
         return typeof value === "string" && value.trim() !== "" ? value : null;
     }
 
-    /** 単一 endpoint から文字列 field を取得する (通信失敗 / HTTP 失敗 / 不正 shape はすべて null)。
-        表示文言も再試行導線も同一のため種別は区別しない。秘密が絡む経路なので console にも出さない。 */
-    async function fetchStringField(url: string, key: string): Promise<string | null> {
+    /**
+     * enrollment 素材 1 本の取得結果。
+     * `recentAuthRequired` は「取得失敗」とは**別事象**として上位へ返す
+     * (409 を「取得失敗」に畳むと、原因と対処が一致しない表示になり再試行が無限に失敗する)。
+     */
+    interface EnrollmentField {
+        value: string | null;
+        recentAuthRequired: boolean;
+    }
+
+    /**
+     * enrollment 素材の単一 endpoint を取得する (通信失敗 / HTTP 失敗 / 不正 shape はすべて null)。
+     * 表示文言も再試行導線も同一のため種別は区別しない。秘密が絡む経路なので console にも出さない。
+     *
+     * ★`Accept: application/json` は**必須**。これが無いと RequireRecentAuth の
+     *   expectsJson() が偽になり 302 が返って fetch がリダイレクトを追従するため、
+     *   409 判定が一度も成立しない (サーバ側 Feature テストが同じヘッダ条件で固定している)。
+     */
+    async function fetchEnrollmentField(url: string, key: string): Promise<EnrollmentField> {
         try {
-            return readStringField(await fetchJson<unknown>(url), key);
+            const response = await fetch(url, { headers: { Accept: "application/json" } });
+            if (!response.ok) {
+                const body: unknown = await response.json().catch(() => null);
+                return {
+                    value: null,
+                    recentAuthRequired: isRecentAuthRequiredPayload(response.status, body),
+                };
+            }
+            return {
+                value: readStringField(await response.json(), key),
+                recentAuthRequired: false,
+            };
         } catch {
-            return null;
+            return { value: null, recentAuthRequired: false };
         }
     }
 
@@ -170,30 +215,76 @@
      */
     let enrollmentGeneration = 0;
 
+    /**
+     * step-up を要求されたが状態を確認できず、モーダルを出せなかった状態。
+     * 「取得失敗」とは別事象なので別の状態・別の文言・別の導線で出す。
+     */
+    let enrollmentStepUpBlocked = $state(false);
+    /**
+     * 自動再開を 1 enrollment につき 1 回に制限するフラグ。
+     * サーバの鮮度判定が status と 409 で食い違う異常時でも必ず停止させるための上限であり、
+     * **ループを切るのは常に人間の操作**にする (再試行ボタンがこのフラグを戻す)。
+     */
+    let enrollmentStepUpRetried = false;
+
     /**
      * enrollment 素材 (QR + 手動セットアップキー) を取得する。
      * 2 つは独立に扱い、片方が取れれば enrollment を続行できる。
      * 両方失敗したときだけ「取得失敗 (再試行可)」として提示する。
+     *
+     * ★409 (step-up 要求) の集約はここ 1 箇所。個別 fetch から guardWithRecentAuth を呼ばない
+     *   (QR と secret-key は同一 session の同一鮮度判定なので**両方 409 になるのが通常**であり、
+     *    個別に呼ぶとモーダル 2 重起動と pendingAction 上書きが常時発生する)。
      */
     async function loadEnrollmentAssets(): Promise<void> {
         const generation = ++enrollmentGeneration;
         loadingEnrollmentAssets = true;
 
         const [qr, secret] = await Promise.all([
-            fetchStringField("/user/two-factor-qr-code", "svg"),
-            fetchStringField("/user/two-factor-secret-key", "secretKey"),
+            fetchEnrollmentField("/user/two-factor-qr-code", "svg"),
+            fetchEnrollmentField("/user/two-factor-secret-key", "secretKey"),
         ]);
 
         // 世代が進んでいる = 破棄済み or 新しい取得が走っている。結果も loading も触らない
         // (finally で戻すと古い run が新しい run の loading を消してしまう)
         if (generation !== enrollmentGeneration) return;
 
-        qrSvg = qr;
-        setupKey = secret;
-        enrollmentAssetsFailed = qr === null && secret === null;
+        // 鮮度切れは「取得失敗」ではない。再認証モーダルを 1 回だけ開き、成立後に同じ取得を再開する
+        if (qr.recentAuthRequired || secret.recentAuthRequired) {
+            loadingEnrollmentAssets = false;
+
+            // 自動再開の上限。ここを超えたら人間の操作 (再試行ボタン) を待つ
+            if (enrollmentStepUpRetried) {
+                enrollmentStepUpBlocked = true;
+                return;
+            }
+            enrollmentStepUpRetried = true;
+
+            void guardWithRecentAuth(
+                () => void loadEnrollmentAssets(),
+                // status 取得失敗 (delegated)。**再取得しない** (ここで再取得すると
+                // 409 → status 失敗 → 再取得 の無限ループになる)。
+                () => {
+                    enrollmentStepUpBlocked = true;
+                },
+            );
+
+            return;
+        }
+
+        qrSvg = qr.value;
+        setupKey = secret.value;
+        enrollmentAssetsFailed = qr.value === null && secret.value === null;
         loadingEnrollmentAssets = false;
     }
 
+    /** 手動再試行。自動再開の上限を戻すのはここだけ (ループを切るのは人間の操作)。 */
+    function retryEnrollmentAssets(): void {
+        enrollmentStepUpRetried = false;
+        enrollmentStepUpBlocked = false;
+        void loadEnrollmentAssets();
+    }
+
     /**
      * enrollment 素材を画面から破棄する (開始時 / confirm 成功時 / 無効化成功時に呼ぶ)。
      * 世代を進めることで、進行中の取得結果が後から再格納されるのを防ぐ。
@@ -204,6 +295,8 @@
         qrSvg = null;
         setupKey = null;
         enrollmentAssetsFailed = false;
+        enrollmentStepUpBlocked = false;
+        enrollmentStepUpRetried = false;
         loadingEnrollmentAssets = false;
     }
 
@@ -292,26 +385,36 @@
         });
     }
 
+    /**
+     * 有効化開始。POST /user/two-factor-authentication は recent-auth 必須になった (T124) ため
+     * precheck を前段に置く。
+     * ★順序が重要: step-up を enrollment の**最初**の操作にすることで、inline throttle の
+     *   共有 bucket (同一 actor の全 inline route が 1 bucket) の残量が最大の時点で
+     *   recent-auth.password (max 6 = 最小) を通す。enable → confirm リトライの**後**に
+     *   step-up が回ると、TOTP を数回打ち間違えた利用者が再認証で 429 になる。
+     */
     function enableTwoFactor(): void {
-        // 再試行時に前回の素材・エラーを持ち越さない
-        resetEnrollmentAssets();
-        router.post(
-            "/user/two-factor-authentication",
-            {},
-            {
-                preserveScroll: true,
-                onStart: () => {
-                    enabling = true;
-                },
-                onSuccess: () => {
-                    confirming = true;
-                    void loadEnrollmentAssets();
-                },
-                onFinish: () => {
-                    enabling = false;
+        void guardWithRecentAuth(() => {
+            // 再試行時に前回の素材・エラーを持ち越さない
+            resetEnrollmentAssets();
+            router.post(
+                "/user/two-factor-authentication",
+                {},
+                {
+                    preserveScroll: true,
+                    onStart: () => {
+                        enabling = true;
+                    },
+                    onSuccess: () => {
+                        confirming = true;
+                        void loadEnrollmentAssets();
+                    },
+                    onFinish: () => {
+                        enabling = false;
+                    },
                 },
-            },
-        );
+            );
+        });
     }
 
     function confirmTwoFactor(event: SubmitEvent): void {
@@ -458,6 +561,28 @@
                             >
                                 認証アプリ設定用の情報を読み込んでいます…
                             </p>
+                        {:else if enrollmentStepUpBlocked}
+                            <!-- step-up を要求されたが状態を確認できずモーダルを出せなかった。
+                                 「取得失敗」とは別事象なので別文言・別導線で受ける (行き先のない詰みを作らない) -->
+                            <Alert
+                                type="warning"
+                                title="再認証が必要です"
+                                testId="enrollment-step-up-blocked"
+                            >
+                                2 要素認証の設定情報を表示するには再認証が必要です。
+                                <TextLink href={RECENT_AUTH_CONFIRM_PATH}>再認証ページ</TextLink>
+                                で本人確認を済ませてから、もう一度お試しください。
+                                {#snippet action()}
+                                    <Button
+                                        variant="ghost"
+                                        onclick={retryEnrollmentAssets}
+                                        loading={loadingEnrollmentAssets}
+                                        testId="retry-enrollment-step-up-button"
+                                    >
+                                        再試行
+                                    </Button>
+                                {/snippet}
+                            </Alert>
                         {:else if enrollmentAssetsFailed}
                             <Alert
                                 type="danger"
@@ -468,7 +593,7 @@
                                 {#snippet action()}
                                     <Button
                                         variant="ghost"
-                                        onclick={() => void loadEnrollmentAssets()}
+                                        onclick={retryEnrollmentAssets}
                                         loading={loadingEnrollmentAssets}
                                         testId="retry-enrollment-assets-button"
                                     >
diff --git a/tests/Architecture/RecentAuthRouteTest.php b/tests/Architecture/RecentAuthRouteTest.php
index 7956649..001910e 100644
--- a/tests/Architecture/RecentAuthRouteTest.php
+++ b/tests/Architecture/RecentAuthRouteTest.php
@@ -4,11 +4,11 @@
 
 use App\Http\Controllers\Auth\ConfirmRecentAuthController;
 use App\Http\Controllers\Auth\SocialAuthController;
-use App\Http\Middleware\RequireRecentAuth;
 use App\Listeners\Auth\StampRecentAuthOnLogin;
 use App\Listeners\Auth\StampRecentAuthOnPasskeyVerified;
 use Illuminate\Routing\Route as RoutingRoute;
 use Illuminate\Routing\Router;
+use Tests\Support\Security\RecentAuthMiddleware;
 
 /*
  * 機微操作 route に recent-auth middleware が付与されていることを CI で担保する (付与漏れ検出)。
@@ -42,6 +42,11 @@ function recentAuthRequiredRouteNames(): array
         'two-factor.regenerate-recovery-codes',
         // 2FA 無効化 (第二要素そのものの除去。bug-hunt F-H3。同じく後付け配線)
         'two-factor.disable',
+        // 2FA seed の露出 (T124)。qr-code は otpauth:// URL、secret-key は平文 seed を返す
+        'two-factor.qr-code',
+        'two-factor.secret-key',
+        // 2FA enrollment 開始 (T124)。force=true が seed とリカバリコードを差し替える
+        'two-factor.enable',
         // profile 更新 (email 変更時のみ条件付き step-up。配線は
         // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()。
         // routeHasRecentAuth は 'recent-auth.on-email-change' も str_starts_with で検出)
@@ -56,19 +61,14 @@ function recentAuthRequiredRouteNames(): array
     ];
 }
 
+/**
+ * 判定の実体は Tests\Support\Security\RecentAuthMiddleware に単一化してある (T124)。
+ * TwoFactorStepUpInventoryTest (deny-by-default 目録) と同じ述語を使い、
+ * 「一方は付いていると言い、他方は付いていないと言う」ドリフトを防ぐ。
+ */
 function routeHasRecentAuth(RoutingRoute $route): bool
 {
-    foreach ($route->gatherMiddleware() as $middleware) {
-        if (! is_string($middleware)) {
-            continue;
-        }
-        // alias 'recent-auth' / 'recent-auth:param' / 完全クラス名のいずれかを許容 (堅牢化)
-        if ($middleware === RequireRecentAuth::class || str_starts_with($middleware, 'recent-auth')) {
-            return true;
-        }
-    }
-
-    return false;
+    return RecentAuthMiddleware::isAttached($route);
 }
 
 test('機微操作 route 全件に recent-auth middleware が付与されている', function (): void {
diff --git a/tests/Architecture/TwoFactorStepUpInventoryTest.php b/tests/Architecture/TwoFactorStepUpInventoryTest.php
new file mode 100644
index 0000000..ad802b5
--- /dev/null
+++ b/tests/Architecture/TwoFactorStepUpInventoryTest.php
@@ -0,0 +1,290 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\TwoFactorStepUpExemption;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Facades\Route;
+use Tests\Support\Security\RecentAuthMiddleware;
+
+/*
+ * 2FA 面の step-up (recent-auth) 付与漏れ invariant (deny-by-default)。
+ *
+ * 「route 名に `two-factor` を含む route は recent-auth を持つか、
+ *   TwoFactorStepUpExemption + 30 文字以上の根拠で免除登録されている」を機械強制する。
+ *
+ * ★保証範囲 (誇張しない): セレクタは**名前ベース**である。`mfa.*` / `security.*` のような
+ *   別名で第二要素の状態に触る route を将来足した場合、本 gate は**沈黙する**。
+ *   別名で第二要素へ触る route を足すときは、この inventory の母集団設計も同時に見直すこと。
+ *   (命名規約そのものを強制する仕組みは意図的に作っていない = 過大)
+ *
+ * ★RecentAuthRouteTest (allowlist 型) との役割分担:
+ *   あちらは「機微操作の名指し表に付いているか」、こちらは「2FA 名前空間に未分類が無いか」。
+ *   判定述語は Tests\Support\Security\RecentAuthMiddleware に単一化してドリフトを防ぐ。
+ */
+
+/**
+ * 母集団セレクタ: route 名に `two-factor` を含む全 route。
+ *
+ * @return array<string, RoutingRoute>
+ */
+function twoFactorStepUpPopulation(): array
+{
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+
+    /** @var array<string, RoutingRoute> $matched */
+    $matched = [];
+    foreach ($routes as $route) {
+        $name = $route->getName();
+        if (is_string($name) && str_contains($name, 'two-factor')) {
+            $matched[$name] = $route;   // route 名は一意
+        }
+    }
+    ksort($matched);
+
+    return $matched;
+}
+
+/**
+ * 母集団件数の **exact fit**。
+ * ★下限だけでは「セレクタが壊れて 0 件」は検出できても「Fortify が 1 本足した」を見逃す。
+ *   exact なら増減のどちらも必ず差分として現れ、分類の再検討を強制できる。
+ *   実測値 (php artisan route:list --json): Fortify 9 本 + アプリ 2 本 = 11 本。
+ */
+function twoFactorStepUpPopulationSize(): int
+{
+    return 11;
+}
+
+/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
+function twoFactorStepUpReasonMinLength(): int
+{
+    return 30;
+}
+
+/** exemption 件数の上限。**現在値ちょうど** (exact fit)。上げる前に必ず再検討すること。 */
+function twoFactorStepUpExemptionCap(): int
+{
+    return 3;
+}
+
+/**
+ * case 別上限 (分類の偏り検出)。全体 cap とは役割が違うので array_sum で導出しない。
+ *
+ * @return array<string, int>
+ */
+function twoFactorStepUpExemptionCapByCase(): array
+{
+    return [
+        TwoFactorStepUpExemption::PreAuthChallengeSurface->value => 2,
+        // ★ここが膨らむ = 「秘密を開示する route を所持証明つきとして逃がした」疑い。
+        TwoFactorStepUpExemption::ProofOfSecretPossessionRequired->value => 1,
+    ];
+}
+
+/**
+ * **免除にできない route** の名指し固定。ここに載る route が exemption 側へ移されたり
+ * recent-auth を失ったら fail する (この gate の存在理由そのものを守る = 空振り防止の核)。
+ *
+ * 2 系統をまとめて持つ:
+ *  (a) 秘密の**開示** — 読めば第二要素を複製できる
+ *  (b) 第二要素の**除去・差し替え** — 書けば正規ユーザーを締め出せる
+ *
+ * ★組織管理側の 2 本 (organizations.members.two-factor.reset /
+ *   organizations.two-factor-requirement.update) は**入れない**。
+ *   脅威系統が違い (管理者操作であり Gate 認可が別途かかる)、
+ *   RecentAuthRouteTest の allowlist が既に名指しで固定しているため二重管理になる。
+ *
+ * @return list<string>
+ */
+function twoFactorNonExemptibleRoutes(): array
+{
+    return [
+        // (a) 秘密の開示
+        'two-factor.qr-code',        // otpauth:// URL (秘密を内包) と QR SVG
+        'two-factor.secret-key',     // 平文 TOTP seed
+        'two-factor.recovery-codes', // TOTP を伴わないログイン成立手段
+        // (b) 第二要素の除去・差し替え
+        'two-factor.enable',         // force=true が seed とリカバリコードを差し替える
+        'two-factor.disable',        // 第二要素そのものの除去
+        'two-factor.regenerate-recovery-codes', // bypass 手段の差し替え
+    ];
+}
+
+/**
+ * recent-auth を持たないことが正しいと裁定した route の inventory。
+ *
+ * @return array<string, array{TwoFactorStepUpExemption, string}>
+ */
+function twoFactorStepUpExemptions(): array
+{
+    $preAuth = TwoFactorStepUpExemption::PreAuthChallengeSurface;
+    $possession = TwoFactorStepUpExemption::ProofOfSecretPossessionRequired;
+
+    return [
+        'two-factor.login' => [$preAuth,
+            'guest:web guard 配下の未認証チャレンジ画面。session に認証主体が存在しないため '
+            .'step-up の鮮度判定 (recent_auth_at) が定義不能であり、ここに recent-auth を課すと '
+            .'ログインそのものが成立しなくなる。流量制限は fortify.limiters.two-factor が担当する。'],
+
+        'two-factor.login.store' => [$preAuth,
+            'two-factor.login と同一 URI の検証側。これ自体が第二要素の検証 = satisfier であり、'
+            .'自分自身に step-up を要求すると構造的に詰む。guest:web + throttle:two-factor で '
+            .'総当りは有界化されている。'],
+
+        'two-factor.confirm' => [$possession,
+            'enrollment の確認。成立には認証アプリが生成した TOTP コードの提示が必要で、'
+            .'session 保持だけでは成立しない (秘密の所持証明が前提)。応答は秘密を開示せず、'
+            .'既存の第二要素を除去も差し替えもしない (two_factor_confirmed_at を立てるだけ)。'
+            .'秘密の入手経路である qr-code / secret-key / enable 側に step-up を課してある。'],
+    ];
+}
+
+test('母集団が exact fit である (セレクタの空振り / vendor の route 追加を検出)', function (): void {
+    $population = twoFactorStepUpPopulation();
+    $expected = twoFactorStepUpPopulationSize();
+
+    expect(count($population))->toBe($expected,
+        '2FA route の母集団が '.count($population)."件 (期待 {$expected} 件) です。"
+        .'セレクタの空振り、または Fortify / アプリ側の route 増減が起きています。'
+        .'増えた route を分類してからこの数値を更新してください。'
+        .PHP_EOL.implode(PHP_EOL, array_keys($population)));
+});
+
+test('母集団の各 route は recent-auth 系 middleware をちょうど 1 種類持つか exemption inventory に明示分類されている (未知は fail)', function (): void {
+    $inventory = twoFactorStepUpExemptions();
+    $violations = [];
+
+    foreach (twoFactorStepUpPopulation() as $name => $route) {
+        $count = RecentAuthMiddleware::countAttachedKinds($route);
+
+        if ($count === 1) {
+            continue;
+        }
+        if ($count === 0 && array_key_exists($name, $inventory)) {
+            continue;
+        }
+
+        $violations[] = $count === 0
+            ? "{$name}: recent-auth が無く exemption inventory にも未登録"
+            : "{$name}: 別種の recent-auth middleware が {$count} 本同居している"
+                .' (無条件 step-up と条件付き step-up の混在。契約は 1 種類ちょうど)';
+    }
+
+    expect($violations)->toBe([],
+        '2FA 面の step-up 付与が不正です。recent-auth を貼るか、貼らないことが正しい理由を '
+        .'twoFactorStepUpExemptions() に TwoFactorStepUpExemption + 具体的根拠付きで'
+        .'登録してください。'.PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('exemption inventory の key は現存する母集団 route (stale 検出)', function (): void {
+    $population = twoFactorStepUpPopulation();
+
+    $stale = [];
+    foreach (array_keys(twoFactorStepUpExemptions()) as $name) {
+        if (! array_key_exists($name, $population)) {
+            $stale[] = $name;
+        }
+    }
+
+    expect($stale)->toBe([],
+        'exemption inventory に現存しない route 名があります (削除/rename 済み): '.implode(', ', $stale));
+});
+
+test('exemption 登録された route は recent-auth を 1 本も持たない (死んだ exemption の検出)', function (): void {
+    $population = twoFactorStepUpPopulation();
+    $dead = [];
+
+    foreach (array_keys(twoFactorStepUpExemptions()) as $name) {
+        $route = $population[$name] ?? null;
+        if ($route instanceof RoutingRoute && RecentAuthMiddleware::isAttached($route)) {
+            $dead[] = $name;
+        }
+    }
+
+    expect($dead)->toBe([],
+        'recent-auth が付いているのに exemption が残っています (免除が形骸化しています)。'
+        .'inventory から削除してください: '.implode(', ', $dead));
+});
+
+test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
+    $minLength = twoFactorStepUpReasonMinLength();
+    $violations = [];
+
+    foreach (twoFactorStepUpExemptions() as $name => [$exemption, $reason]) {
+        if (! $exemption instanceof TwoFactorStepUpExemption) {
+            $violations[] = "{$name}: 第 1 要素が TwoFactorStepUpExemption ではありません";
+        }
+        if (mb_strlen($reason) < $minLength) {
+            $violations[] = "{$name}: 理由が {$minLength} 文字未満です";
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('exemption 件数が上限ちょうどを超えない (形骸化ガード)', function (): void {
+    $count = count(twoFactorStepUpExemptions());
+
+    expect($count)->toBeLessThanOrEqual(twoFactorStepUpExemptionCap(),
+        "exemption が {$count} 件あります。免除を増やす前に、その route に step-up を"
+        .'課せない構造的理由が本当にあるかを再検討してください。');
+});
+
+test('exemption の case 別件数が上限を超えない (分類の偏り検出)', function (): void {
+    $caps = twoFactorStepUpExemptionCapByCase();
+    $counts = [];
+
+    foreach (twoFactorStepUpExemptions() as [$exemption]) {
+        $counts[$exemption->value] = ($counts[$exemption->value] ?? 0) + 1;
+    }
+
+    $violations = [];
+
+    // 全 case が cap 表に載っていること (case 追加時の登録漏れ検出)。
+    // ★`expect()->toHaveKey($key, $value)` の第 2 引数は**期待値**であってメッセージではない
+    //   ため使わない (使うと cap 値と文言を比較して常に fail する)。
+    foreach (TwoFactorStepUpExemption::cases() as $case) {
+        if (! array_key_exists($case->value, $caps)) {
+            $violations[] = "case {$case->value} が cap 表に未登録です";
+        }
+    }
+
+    foreach ($counts as $value => $count) {
+        $cap = $caps[$value] ?? 0;
+        if ($count > $cap) {
+            $violations[] = "{$value}: {$count} 件 (上限 {$cap})";
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('免除にできない route は必ず recent-auth 系 middleware をちょうど 1 種類持つ (免除側へ移されたら fail)', function (): void {
+    $population = twoFactorStepUpPopulation();
+    $inventory = twoFactorStepUpExemptions();
+    $violations = [];
+
+    foreach (twoFactorNonExemptibleRoutes() as $name) {
+        $route = $population[$name] ?? null;
+        if (! $route instanceof RoutingRoute) {
+            $violations[] = "{$name}: 母集団に存在しません (rename / 削除?)";
+
+            continue;
+        }
+        if (RecentAuthMiddleware::countAttachedKinds($route) !== 1) {
+            $violations[] = "{$name}: 秘密の開示 / 第二要素の差し替え経路なのに recent-auth 系 middleware が 1 種類ではありません";
+        }
+        if (array_key_exists($name, $inventory)) {
+            $violations[] = "{$name}: この route は exemption にできません";
+        }
+    }
+
+    expect($violations)->toBe([],
+        '秘密開示 / 第二要素差し替え route の step-up は免除できません (T124 の存在理由そのものです)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
diff --git a/tests/Feature/Auth/TwoFactorEnableStepUpTest.php b/tests/Feature/Auth/TwoFactorEnableStepUpTest.php
new file mode 100644
index 0000000..792763a
--- /dev/null
+++ b/tests/Feature/Auth/TwoFactorEnableStepUpTest.php
@@ -0,0 +1,103 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\User;
+
+/*
+ * 2FA enrollment 開始 (POST /user/two-factor-authentication) の recent-auth (step-up) 配線 (T124)。
+ *
+ * ★この施策の中心的な脅威: Fortify の TwoFactorAuthenticationController は
+ *   `$request->boolean('force')` を EnableTwoFactorAuthentication へそのまま渡す。
+ *   force=true は two_factor_secret と two_factor_recovery_codes を**再生成する一方で
+ *   two_factor_confirmed_at を触らない**。つまり奪取セッションから 1 回叩くだけで
+ *   「誰も知らない秘密で TOTP を要求し続ける」= 正規ユーザーの永久ロックアウトが成立する。
+ *   秘密の**読み出し** (qr-code / secret-key) だけ塞いで**差し替え**を開けたままにしない。
+ */
+
+test('鮮度なしの POST enable (XHR) は 409 で two_factor_secret を作らない', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->postJson('/user/two-factor-authentication')
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required');
+
+    $user->refresh();
+    expect($user->two_factor_secret)->toBeNull();
+});
+
+test('鮮度なしの POST enable force=true は確立済み seed とリカバリコードを差し替えない (ロックアウト回帰)', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+    $user->refresh();
+
+    // 前提の明示固定: Factory が confirmed_at を立てることに暗黙依存すると、
+    // Factory 変更で「**確立済み** 2FA に対する差し替え」というテストの意味が沈黙して薄れる。
+    expect($user->two_factor_confirmed_at)->not->toBeNull();
+
+    $beforeSecret = $user->two_factor_secret;
+    $beforeCodes = $user->two_factor_recovery_codes;
+    $beforeConfirmedAt = $user->two_factor_confirmed_at;
+
+    $this->actingAs($user)
+        ->postJson('/user/two-factor-authentication', ['force' => true])
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required');
+
+    $user->refresh();
+    expect($user->two_factor_secret)->toBe($beforeSecret);
+    expect($user->two_factor_recovery_codes)->toBe($beforeCodes);
+    expect($user->two_factor_confirmed_at?->toIso8601String())
+        ->toBe($beforeConfirmedAt?->toIso8601String());
+});
+
+test('鮮度なしの通常 POST enable は recent-auth confirm へ 302 する', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->post('/user/two-factor-authentication')
+        ->assertRedirect(route('recent-auth.confirm'));
+
+    $user->refresh();
+    expect($user->two_factor_secret)->toBeNull();
+});
+
+test('fresh なら force=true が seed を実際に差し替え、confirmed_at は触られない (負のコントロール)', function (): void {
+    // ★confirmed_at が不変であること自体が「誰も知らない秘密で TOTP を要求し続ける」
+    //   ロックアウトが成立する仕組みそのものである。この事実が変わったら設計の前提が
+    //   変わるのでテストで固定する。
+    $user = User::factory()->withTwoFactor()->create();
+    $user->refresh();
+
+    $beforeSecret = $user->two_factor_secret;
+    $beforeCodes = $user->two_factor_recovery_codes;
+    $beforeConfirmedAt = $user->two_factor_confirmed_at;
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->postJson('/user/two-factor-authentication', ['force' => true])
+        ->assertSuccessful();
+
+    $user->refresh();
+    expect($user->two_factor_secret)->not->toBe($beforeSecret);
+    expect($user->two_factor_recovery_codes)->not->toBe($beforeCodes);
+    expect($user->two_factor_confirmed_at?->toIso8601String())
+        ->toBe($beforeConfirmedAt?->toIso8601String());
+});
+
+test('2FA 必須組織の未準拠メンバーでも enable は 2FA ゲートに阻まれない (遮断理由は step-up 側だけ)', function (): void {
+    // RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES に two-factor.enable が
+    // 元から入っているため、recent-auth 追加後も遮断の理由が step-up 側だけであることを固定する。
+    [$organization] = createOrganizationWithOwner();
+    /** @var Organization $organization */
+    $organization->forceFill(['two_factor_required' => true])->save();
+
+    $member = attachOrganizationMember($organization);
+
+    $this->actingAs($member)
+        ->postJson('/user/two-factor-authentication')
+        ->assertStatus(409)
+        // two_factor_required ではないこと = 2FA ゲートではなく step-up が遮断している
+        ->assertJsonPath('code', 'recent_auth_required');
+});
diff --git a/tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php b/tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php
new file mode 100644
index 0000000..de4b42d
--- /dev/null
+++ b/tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php
@@ -0,0 +1,83 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+use Laravel\Fortify\Fortify;
+
+/*
+ * 2FA seed を返す GET 2 本 (qr-code / secret-key) の recent-auth (step-up) 配線 (T124)。
+ *
+ * Fortify 登録ルートには FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が
+ * booted callback で recent-auth middleware を後付けする。ここではその実効性
+ * (stale で遮断 = 秘密を返さない / fresh で通過 = 秘密を返す) を HTTP 経由で検証する。
+ * 付与漏れ検出は RecentAuthRouteTest / TwoFactorStepUpInventoryTest (Architecture) 側。
+ *
+ * ★`Accept: application/json` を**実ヘッダで**指定する (getJson() ヘルパ任せにしない)。
+ *   フロント (Settings/Security.svelte) の素 fetch が同じ条件で 409 契約へ入ることの証拠にする。
+ */
+
+test('鮮度なしの GET QR コード (Accept: application/json) は 409 recent_auth_required で svg も url も返さない', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)
+        ->get('/user/two-factor-qr-code', ['Accept' => 'application/json'])
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required')
+        ->assertJsonPath('redirect', route('recent-auth.confirm'))
+        ->assertJsonMissingPath('svg')
+        ->assertJsonMissingPath('url');
+});
+
+test('鮮度なしの GET セットアップキー (Accept: application/json) は 409 で secretKey を返さない', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)
+        ->get('/user/two-factor-secret-key', ['Accept' => 'application/json'])
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required')
+        ->assertJsonMissingPath('secretKey');
+});
+
+test('鮮度なしの通常 GET (Accept: text/html) は recent-auth confirm へ 302 する', function (string $uri): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)
+        ->get($uri, ['Accept' => 'text/html'])
+        ->assertRedirect(route('recent-auth.confirm'));
+})->with([
+    'qr-code' => ['/user/two-factor-qr-code'],
+    'secret-key' => ['/user/two-factor-secret-key'],
+]);
+
+test('fresh なら QR とセットアップキーが実際に秘密を返す (負のコントロール)', function (): void {
+    // 「常に失敗するから green」という空振りを排除する。遮断に意味があることの証拠。
+    $user = User::factory()->withTwoFactor()->create();
+    $user->refresh();
+
+    $secret = $user->two_factor_secret;
+    expect($secret)->toBeString();
+    $plainSecret = Fortify::currentEncrypter()->decrypt($secret);
+
+    $this->actingAs($user)
+        ->withSession(['recent_auth_at' => time()])
+        ->get('/user/two-factor-qr-code', ['Accept' => 'application/json'])
+        ->assertOk()
+        ->assertJsonPath('svg', fn (mixed $svg): bool => is_string($svg) && $svg !== '');
+
+    $this->actingAs($user)
+        ->withSession(['recent_auth_at' => time()])
+        ->get('/user/two-factor-secret-key', ['Accept' => 'application/json'])
+        ->assertOk()
+        ->assertJsonPath('secretKey', $plainSecret);
+});
+
+test('409 応答には Cache-Control: no-store が付く (RequireRecentAuth 契約の回帰)', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $response = $this->actingAs($user)
+        ->get('/user/two-factor-secret-key', ['Accept' => 'application/json'])
+        ->assertStatus(409);
+
+    expect($response->headers->get('Cache-Control'))->toContain('no-store');
+});
diff --git a/tests/Feature/Organizations/TwoFactorEnforcementTest.php b/tests/Feature/Organizations/TwoFactorEnforcementTest.php
index 194d089..9792565 100644
--- a/tests/Feature/Organizations/TwoFactorEnforcementTest.php
+++ b/tests/Feature/Organizations/TwoFactorEnforcementTest.php
@@ -6,6 +6,7 @@
 use App\Enums\SecurityEventType;
 use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
 use App\Models\Organization;
+use App\Models\Passkey;
 use App\Models\SecurityAuditEvent;
 use App\Models\User;
 use App\Notifications\User\TwoFactorResetSecurityNotification;
@@ -232,6 +233,37 @@ function tfeResetUrl(Organization $organization, User $member): string
     }
 })->with(array_keys(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES));
 
+test('2FA 必須ゲート下の passkey-only ユーザーは passkey step-up の challenge を取得できる (T124)', function (): void {
+    // enrollment (two-factor.enable / qr-code / secret-key) に step-up が課された結果、
+    // satisfier の到達性が enrollment の前提になった。password / 再SSO / passkey の
+    // どれか 1 つでも allowlist から漏れると、その手段しか持たないユーザーが入口で詰む。
+    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
+    $member = tfeAddMember($organization, 'pending');
+    Passkey::factory()->for($member)->create();
+
+    $response = $this->actingAs($member)->getJson('/passkeys/confirm/options');
+
+    // 本施策の直接の回帰: ゲートによる settings.security への redirect でないこと
+    expect($response->headers->get('Location'))->not->toBe(route('settings.security'));
+    // 期待値は vendor controller の正常契約から確定している:
+    // Laravel\Passkeys\Http\Controllers\PasskeyConfirmationController::index() は
+    // response()->json(['options' => ...]) を返す = 200。
+    // (「allowlist は通ったが実用上は壊れている」空振りを排除する)
+    $response->assertOk()->assertJsonStructure(['options']);
+});
+
+test('allowlist 外の passkey 管理 route はゲート中に settings.security へ 302 (T124 の負のコントロール)', function (): void {
+    // 「passkey なら何でも通す」になっていないことの証拠。registration-options は
+    // credential を**増やす**管理経路であり satisfier ではない。
+    [$organization] = tfeCreateOrganization(twoFactorRequired: true);
+    $member = tfeAddMember($organization, 'pending');
+
+    $this->actingAs($member)
+        ->withSession(freshRecentAuthSession())
+        ->get('/user/passkeys/options')
+        ->assertRedirect(route('settings.security'));
+});
+
 test('非許可 route の代表はゲート中必ず settings.security へ 302', function (string $path): void {
     [$organization] = tfeCreateOrganization(twoFactorRequired: true);
     $member = tfeAddMember($organization, 'disabled');
@@ -271,7 +303,11 @@ function tfeResetUrl(Organization $organization, User $member): string
     $this->get(route('settings.security'))->assertOk();
 
     // 3. Fortify 実 POST で enrollment 開始
-    $this->post('/user/two-factor-authentication')->assertRedirect();
+    //    T124: enable は step-up 必須になった (force=true の seed 差し替えによる永久ロックアウト対策)。
+    //    実運用ではログイン直後 15 分は StampRecentAuthOnLogin により fresh なので、
+    //    ここでも「step-up 済み相当」の session を与えて enrollment 本体の遷移を検証する。
+    $this->withSession(freshRecentAuthSession())
+        ->post('/user/two-factor-authentication')->assertRedirect();
     $secret = decrypt($member->fresh()->two_factor_secret);
     expect($secret)->toBeString();
 
diff --git a/tests/Support/Security/RecentAuthMiddleware.php b/tests/Support/Security/RecentAuthMiddleware.php
new file mode 100644
index 0000000..ea3d480
--- /dev/null
+++ b/tests/Support/Security/RecentAuthMiddleware.php
@@ -0,0 +1,65 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Security;
+
+use App\Http\Middleware\RequireRecentAuth;
+use Illuminate\Routing\Route as RoutingRoute;
+
+/**
+ * route に recent-auth (step-up) middleware が付いているかの**唯一の判定点**。
+ *
+ * RecentAuthRouteTest (allowlist 型) と TwoFactorStepUpInventoryTest (deny-by-default 目録型)
+ * の 2 つの gate が同じ述語を使う。判定を各テストに複製すると、片方だけ堅牢化されて
+ * 「一方は付いていると言い、他方は付いていないと言う」ドリフトが起きる。
+ */
+final class RecentAuthMiddleware
+{
+    /**
+     * 実効 middleware 列に含まれる recent-auth 系 entry の**種類数**を返す。
+     *
+     * ★数えるのは「種類」であって「登録回数」ではない (誇張しない):
+     *   `Route::gatherMiddleware()` は `Router::uniqueMiddleware()` を通すため、
+     *   **同一文字列**の二重登録は framework が畳んで 1 本になる (実査: Laravel 12
+     *   `Routing/Router::uniqueMiddleware()` が値をキーに `$seen` で除去)。
+     *   したがって同一 alias の重複は**実行時に観測できず、振る舞いにも差が出ない**。
+     *   観測できない差分に gate を置くのは偽陽性を生むだけなので、そこは検査しない。
+     *
+     * ★検査する価値があるのは **別種の recent-auth が同居する**状態である。
+     *   例: `recent-auth` (無条件 step-up) と `recent-auth.on-email-change` (条件付き) が
+     *   同一 route に付くと、意図が矛盾した二重ゲートになる (どちらが真の契約か読めない)。
+     *   これは別文字列なので dedup されず、ここで 2 と数えられる。
+     *
+     * 受理する entry を**厳密に**限定する (`recent-authentication` のような将来の別 alias を
+     * 巻き込んで数えないため):
+     *   - `recent-auth` 完全一致
+     *   - `recent-auth:` 前方一致 (パラメータ付き)
+     *   - `recent-auth.` 前方一致 (`recent-auth.on-email-change` 等の派生 alias)
+     *   - `RequireRecentAuth::class` 完全一致
+     */
+    public static function countAttachedKinds(RoutingRoute $route): int
+    {
+        $count = 0;
+
+        foreach ($route->gatherMiddleware() as $middleware) {
+            if (! is_string($middleware)) {
+                continue;
+            }
+            if ($middleware === RequireRecentAuth::class
+                || $middleware === 'recent-auth'
+                || str_starts_with($middleware, 'recent-auth:')
+                || str_starts_with($middleware, 'recent-auth.')) {
+                $count++;
+            }
+        }
+
+        return $count;
+    }
+
+    /** 1 種類以上付いているか (allowlist 型 gate 用の薄いラッパ。既存の意味を変えない)。 */
+    public static function isAttached(RoutingRoute $route): bool
+    {
+        return self::countAttachedKinds($route) > 0;
+    }
+}
diff --git a/tests/js/lib/recent-auth.test.ts b/tests/js/lib/recent-auth.test.ts
index dbdaed3..5e3003f 100644
--- a/tests/js/lib/recent-auth.test.ts
+++ b/tests/js/lib/recent-auth.test.ts
@@ -32,6 +32,7 @@ vi.mock("@/lib/stores/toast", async (importOriginal) => ({
 
 import {
     fetchRecentAuthStatus,
+    isRecentAuthRequiredPayload,
     parseRecentAuthStatus,
     registerRecentAuthRedirectHandler,
     withRecentAuth,
@@ -256,3 +257,47 @@ describe("registerRecentAuthRedirectHandler (409 の単一ハンドラ)", () =>
         expect(unsubscribe).toHaveBeenCalledTimes(1);
     });
 });
+
+describe("isRecentAuthRequiredPayload (409 契約の型ガード。T124)", () => {
+    /*
+     * status だけでは判定しない。同じ 409 を RequireTwoFactorForEnforcedOrganizations も
+     * 返す (code: "two_factor_required") ため、status のみの判定は誤食する。
+     */
+
+    it("409 + code=recent_auth_required を true と判定する", () => {
+        expect(
+            isRecentAuthRequiredPayload(409, {
+                code: "recent_auth_required",
+                message: "この操作には直近の再認証が必要です。",
+                redirect: "/recent-auth/confirm",
+            }),
+        ).toBe(true);
+    });
+
+    it("409 + code=two_factor_required を false と判定する (2FA 必須ゲートの 409 を誤食しない)", () => {
+        expect(
+            isRecentAuthRequiredPayload(409, {
+                code: "two_factor_required",
+                message: "組織は 2 段階認証を必須としています。",
+                redirect: "/settings/security",
+            }),
+        ).toBe(false);
+    });
+
+    it.each([200, 302, 422, 500])("status %i は code が一致しても false", (status) => {
+        expect(isRecentAuthRequiredPayload(status, { code: "recent_auth_required" })).toBe(false);
+    });
+
+    it.each([
+        ["null", null],
+        ["文字列 (非 JSON 応答)", "<html>error</html>"],
+        ["配列", []],
+        ["数値", 1],
+        ["undefined", undefined],
+        ["code 欠損", { message: "x" }],
+        ["code が非文字列", { code: 1 }],
+    ])("body が %s でも例外を投げず false", (_label, body) => {
+        expect(() => isRecentAuthRequiredPayload(409, body)).not.toThrow();
+        expect(isRecentAuthRequiredPayload(409, body)).toBe(false);
+    });
+});
diff --git a/tests/js/pages/SettingsSecurity.test.ts b/tests/js/pages/SettingsSecurity.test.ts
index 7b17240..488d7a9 100644
--- a/tests/js/pages/SettingsSecurity.test.ts
+++ b/tests/js/pages/SettingsSecurity.test.ts
@@ -395,3 +395,276 @@ describe("Settings/Security 2FA 無効化 (recent-auth precheck)", () => {
         expect(routerDeleteMock).toHaveBeenCalledTimes(1);
     });
 });
+
+/*
+ * T124: enrollment (有効化開始 + 素材取得) の step-up precheck と 409 再開。
+ *
+ * サーバ側で POST /user/two-factor-authentication と GET /user/two-factor-{qr-code,secret-key} が
+ * recent-auth 必須になったため、
+ *  (a) 有効化ボタンは precheck を通す (stale なら POST せずモーダル)
+ *  (b) 素材の 409 は「取得失敗」ではなく step-up 要求として扱い、1 回だけ自動再開する
+ *  (c) status が取れない (delegated) ときは **再取得しない** (409 → status 失敗 → 再取得 の
+ *      無限ループを構造的に不能にする)
+ * を固定する。
+ */
+
+/** enrollment 素材 1 本の応答指定 */
+type FieldStub = { kind: "ok"; body: unknown } | { kind: "error"; status: number; body: unknown };
+
+const RECENT_AUTH_409: FieldStub = {
+    kind: "error",
+    status: 409,
+    body: {
+        code: "recent_auth_required",
+        message: "この操作には直近の再認証が必要です。",
+        redirect: "/recent-auth/confirm",
+    },
+};
+
+interface EnrollmentStubState {
+    /** /recent-auth/status の応答 (null = HTTP 500 で status が取れない) */
+    recent: boolean | null;
+    qr: FieldStub;
+    secret: FieldStub;
+}
+
+function fieldResponse(stub: FieldStub): unknown {
+    return stub.kind === "ok"
+        ? jsonResponse(true, 200, stub.body)
+        : jsonResponse(false, stub.status, stub.body);
+}
+
+/**
+ * enrollment 用 fetch mock。**可変 state** を返し、テスト側が途中で応答を差し替えられる
+ * (mock 実装は state を毎回読むため、差し替えは即座に効く)。
+ */
+function stubEnrollmentFetch(initial: Partial<EnrollmentStubState> = {}): EnrollmentStubState {
+    const state: EnrollmentStubState = {
+        recent: true,
+        qr: { kind: "ok", body: { svg: "<svg></svg>" } },
+        secret: { kind: "ok", body: { secretKey: "SETUPKEY123" } },
+        ...initial,
+    };
+
+    fetchMock.mockImplementation((input: RequestInfo | URL) => {
+        const url = String(input);
+        if (url.includes("/recent-auth/status")) {
+            if (state.recent === null) {
+                return Promise.resolve(jsonResponse(false, 500, {}));
+            }
+            return Promise.resolve(
+                jsonResponse(true, 200, {
+                    recent: state.recent,
+                    passwordSet: true,
+                    availableProviders: [],
+                    passkeyAvailable: false,
+                    canSatisfy: true,
+                    confirmedAt: state.recent ? 1 : null,
+                }),
+            );
+        }
+        if (url.includes("/recent-auth/password")) {
+            return Promise.resolve(jsonResponse(true, 204, null));
+        }
+        if (url.includes("/user/two-factor-qr-code")) {
+            return Promise.resolve(fieldResponse(state.qr));
+        }
+        if (url.includes("/user/two-factor-secret-key")) {
+            return Promise.resolve(fieldResponse(state.secret));
+        }
+        return Promise.resolve(jsonResponse(true, 200, []));
+    });
+
+    return state;
+}
+
+/** 指定 URL 片を含む fetch 呼び出し回数 */
+function fetchCallCount(fragment: string): number {
+    return fetchMock.mock.calls.filter((call) => String(call[0]).includes(fragment)).length;
+}
+
+/**
+ * 2FA 未設定状態で描画し、有効化 → enable POST 成功 (onSuccess) まで進めて enrollment に入る。
+ *
+ * ★有効化ボタン自身の precheck は **常に fresh** で通す (ここは素材取得側の検証が目的)。
+ *   呼び出し側が指定した `state.recent` は POST 成立後に復元し、
+ *   素材の 409 を受けた precheck から効かせる。
+ * ★onSuccess の直前に fetch 呼び出し履歴を消す (以降の回数が素材取得だけを数える)。
+ */
+async function enterEnrollment(state: EnrollmentStubState): Promise<void> {
+    const recentForAssets = state.recent;
+    state.recent = true;
+
+    setTwoFactor(false);
+    render(Security, { props: {} });
+
+    await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
+    await waitFor(() => {
+        expect(routerPostMock).toHaveBeenCalledWith(
+            "/user/two-factor-authentication",
+            {},
+            expect.objectContaining({ preserveScroll: true }),
+        );
+    });
+
+    state.recent = recentForAssets;
+    fetchMock.mockClear();
+    lastVisitOptions().onSuccess?.();
+}
+
+describe("Settings/Security 有効化開始の step-up precheck (T124)", () => {
+    it("stale なら再認証モーダルを開き、enable を POST しない", async () => {
+        stubEnrollmentFetch({ recent: false });
+        setTwoFactor(false);
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(routerPostMock).not.toHaveBeenCalled();
+    });
+
+    it("fresh なら enable を POST する (負のコントロール)", async () => {
+        stubEnrollmentFetch({ recent: true });
+        setTwoFactor(false);
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
+
+        await waitFor(() => {
+            expect(routerPostMock).toHaveBeenCalledWith(
+                "/user/two-factor-authentication",
+                {},
+                expect.objectContaining({ preserveScroll: true }),
+            );
+        });
+        expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
+    });
+});
+
+describe("Settings/Security enrollment 素材の 409 (step-up) 処理 (T124)", () => {
+    it("素材取得が両方 409 でも再認証モーダルの起動は 1 回だけ (取得失敗にも畳まない)", async () => {
+        const state = stubEnrollmentFetch({
+            recent: false,
+            qr: RECENT_AUTH_409,
+            secret: RECENT_AUTH_409,
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(screen.getAllByTestId("recent-auth-modal")).toHaveLength(1);
+        // status の再取得は 1 回だけ (409 を受けた precheck)
+        expect(fetchCallCount("/recent-auth/status")).toBe(1);
+        // 409 を「取得失敗」に畳んでいない
+        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
+    });
+
+    it("片方だけ 409 でも再認証モーダルへ倒す (部分的鮮度切れの一貫性)", async () => {
+        const state = stubEnrollmentFetch({
+            recent: false,
+            qr: RECENT_AUTH_409,
+            secret: { kind: "ok", body: { secretKey: "SETUPKEY123" } },
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
+    });
+
+    it("409 以外の失敗 (500) は従来どおり取得失敗 Alert を出し、モーダルを開かない", async () => {
+        // 通常エラーを step-up へ誤分類しないことの負のコントロール
+        const state = stubEnrollmentFetch({
+            qr: { kind: "error", status: 500, body: {} },
+            secret: { kind: "error", status: 500, body: {} },
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("enrollment-assets-error")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
+        expect(screen.queryByTestId("enrollment-step-up-blocked")).toBeNull();
+    });
+
+    it("素材が 409 かつ /recent-auth/status が 500 のとき再取得ループしない", async () => {
+        // ★delegated ループ回帰。この設計の中心的な安全性テスト。
+        const state = stubEnrollmentFetch({
+            recent: null,
+            qr: RECENT_AUTH_409,
+            secret: RECENT_AUTH_409,
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("enrollment-step-up-blocked")).toBeInTheDocument();
+        });
+
+        // 素材 2 本 + status 1 本で停止する (4 回目以降が発火しない)
+        expect(fetchCallCount("/user/two-factor-qr-code")).toBe(1);
+        expect(fetchCallCount("/user/two-factor-secret-key")).toBe(1);
+        expect(fetchCallCount("/recent-auth/status")).toBe(1);
+        expect(fetchMock.mock.calls).toHaveLength(3);
+
+        // 追加の tick を与えても増えない (非同期ループの取りこぼし検出)
+        await new Promise((resolve) => setTimeout(resolve, 30));
+        expect(fetchMock.mock.calls).toHaveLength(3);
+
+        expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
+    });
+
+    it("step-up 不能 Alert の再試行ボタンは自動再開の上限を戻して再取得する", async () => {
+        const state = stubEnrollmentFetch({
+            recent: null,
+            qr: RECENT_AUTH_409,
+            secret: RECENT_AUTH_409,
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("enrollment-step-up-blocked")).toBeInTheDocument();
+        });
+
+        // 人間の操作でループを切る = 上限が「詰み」にならないことの確認
+        state.qr = { kind: "ok", body: { svg: "<svg></svg>" } };
+        state.secret = { kind: "ok", body: { secretKey: "RESUMEDKEY" } };
+        await fireEvent.click(screen.getByTestId("retry-enrollment-step-up-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("two-factor-setup-key")).toHaveTextContent("RESUMEDKEY");
+        });
+        expect(screen.queryByTestId("enrollment-step-up-blocked")).toBeNull();
+    });
+
+    it("再認証成立後に素材取得が再開され QR とセットアップキーが表示される", async () => {
+        const state = stubEnrollmentFetch({
+            recent: false,
+            qr: RECENT_AUTH_409,
+            secret: RECENT_AUTH_409,
+        });
+        await enterEnrollment(state);
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+
+        // 再認証が成立したので素材は返るようになる
+        state.qr = { kind: "ok", body: { svg: "<svg></svg>" } };
+        state.secret = { kind: "ok", body: { secretKey: "RESUMEDKEY" } };
+
+        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
+            target: { value: "current-password" },
+        });
+        await fireEvent.click(screen.getByTestId("recent-auth-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("two-factor-setup-key")).toHaveTextContent("RESUMEDKEY");
+        });
+        expect(screen.getByTestId("two-factor-qr")).toBeInTheDocument();
+    });
+});

```


## テスト結果

### composer test (PHP 全件)
```
{"tool":"pest","result":"passed","tests":3712,"passed":3710,"assertions":14962,"skipped":2}
```

### composer phpstan (level 10)
```
[OK] No errors
```

### vendor/bin/pint --test / pnpm lint / pnpm typecheck / pnpm test / pnpm build
```
pint: passed
eslint: no findings
tsc --noEmit: no errors
vitest: Test Files 126 passed (126) / Tests 1257 passed (1257)
vite build: built in 10.09s
```

### pnpm typecheck:packages / build:packages / test:packages
```
Test Files 10 passed (10) / Tests 106 passed (106)
```

### Step A (テストファースト): 施策 1 の配線**前**に施策 2 の gate を走らせた実測

`composer test -- tests/Architecture/TwoFactorStepUpInventoryTest.php`
→ 8 tests / 6 passed / **2 failed**。列挙されたのは設計の期待どおり
`two-factor.enable` / `two-factor.qr-code` / `two-factor.secret-key` の **3 本ちょうど**
(exemption 済みの `two-factor.confirm` / `two-factor.login` / `two-factor.login.store` は出ない)。

### Step C (mutation 実測): gate が空振り green でないことの証拠

baseline: `composer test -- --filter='TwoFactorStepUpInventory|RecentAuthRoute'` = 11 tests / 11 passed。
m1〜m8 を 1 つずつ適用 → 全 8 件が期待どおり fail → 全て revert 済み (git diff で確認)。

| # | mutation | 実測 |
|---|---|---|
| m1 | RECENT_AUTH_ROUTE_NAMES から `two-factor.secret-key` を抜く | failed (3 件。未分類検査 + non-exemptible 検査 + allowlist 検査) |
| m2 | exemption に実在しない route を足す | failed (3 件。stale + 全体 cap + case 別 cap) |
| m3 | recent-auth 済みの `two-factor.disable` を exemption に足す | failed (4 件。死んだ exemption + cap 2 種 + non-exemptible) |
| m4 | `two-factor.confirm` の理由を `N/A` に短縮 | failed (1 件。30 文字検査) |
| m5 | `two-factor.qr-code` を exemption へ移し配線から外す | failed (4 件) |
| m6 | セレクタを `two-factor.` (ドット付き) に狭める | failed (母集団 10 件 ≠ 11)。設計の予測は 9 件だったが実測 10 件 (`organizations.members.two-factor.reset` はドット付きでも一致するため) |
| m7 | 母集団期待値を 12 に書き換える | failed (数値だけ直す運用を防げる) |
| m8 | `recent-auth` と `recent-auth.on-email-change` の**別種同居**を作る | failed (「別種の recent-auth middleware が 2 本同居している」) |

**同一 alias の重複は検査していない** (誇張しない): 配線点 `appendMiddlewareIfMissing()` が idempotent で
そもそも作れず、仮に作れても `Route::gatherMiddleware()` が `Router::uniqueMiddleware()` で畳むため
実行時に観測できない。観測できない差分に gate を置かない。

### composer test:browser
別途実行中 (結果は本レビュー時点では未取得)。取得後に報告する。


## 設計からの逸脱 (実装者による申告)

1. **設計コード片のバグ 1 件を修正**: 設計書 L638-640 の
   `expect($caps)->toHaveKey($case->value, "case ... が cap 表に未登録です")` は、
   Pest の `toHaveKey($key, $value)` の第 2 引数が**期待値**でありメッセージではないため
   **常に fail** する (初回実行で実測)。意図 (case 追加時の cap 表登録漏れ検出) は変えずに
   violations 集約方式へ書き換えた。

2. **`tests/Feature/Security/AuthThrottleCoverageTest.php` を変更していない**:
   設計は「recent-auth 追加で 409/302 になり 429 の観測ができなくなるので
   `withSession(['recent_auth_at' => time()])` を各テストへ足す」計画だったが、
   **実測すると変更なしで 24 tests 全て green だった**。
   実効順は throttle → recent-auth (`bootstrap/app.php` の priority list) であり、
   409 応答にも `X-RateLimit-*` が付き、11 回目は 409 ではなく 429 になるため、
   レーン分離・bucket 共有の behavioral 固定はそのまま成立している。
   このファイルは他タスク (T125) も触る共有ファイルであり、不要な既存行変更は
   マージ衝突を増やすだけなので**変更しない**判断にした。設計の前提 (テストが壊れる) が
   実測で否定されたことによる逸脱である。

3. **`tests/Feature/Organizations/TwoFactorEnforcementTest.php` の既存テスト 1 本に 1 行追加**:
   `状態遷移 (Fortify 実経路): ゲート → enable → confirm → ゲート解除` は
   enable が step-up 必須になったことで seed が作られず decrypt で落ちた (実測)。
   enrollment 本体の遷移を検証するテストなので `withSession(freshRecentAuthSession())` を
   enable POST に与えた (実運用でもログイン直後 15 分は `StampRecentAuthOnLogin` で fresh)。
   アサーションは 1 文字も変えていない。

4. **`docs/architecture.md` / `AGENTS.md` は末尾追記のみ** (既存項番を renumber しない。
   並列実装 5 件のマージ衝突を最小化するため)。

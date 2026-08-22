# Round 5 (最終): Round 4 の指摘への対応

Round 4 の [Critical] 5 件・[Warning] 7 件すべてに対応した。本ラウンドが最大 5 ラウンドの最終である。
主な変更は 6 点:

1. **discovery のキャッシュ保存スキーマへ `id_token_signing_algorithms` を追加**。
   破損・空配列・未知値で forget するテストと、キャッシュ hit 後も広告集合との共通部分が成立する回帰を追加。
2. **例外の生成形を `::of(RejectionReason::…)` に統一** (previous を受け取れない構築子)。
3. **`consumeFailed` を分類から外し、DB の障害として例外にした**。
   業務上の拒否 (分類を返す) と基盤の障害 (例外・巻き戻す) を分けた。
4. **接続を変える 5 操作すべてを接続の行ロックで callback と直列化**。
   身元 0 件での issuer / client_id 変更も必ず Draft へ戻す。並行ハーネスで両方向を固定。
5. **`octet_length(subject) BETWEEN 1 AND 255` の CHECK 制約を DB に置いた**
   (pgsql の varchar(255) は文字数であってバイト数ではない、という指摘のとおり)。
6. **確認トークンの露出について保証する範囲と保証しない範囲を書き切った**。
   緩和は 60 分の期限と一回だけの consume である。

route 件数は全箇所 14 本に統一し、C1 の docblock と移行のコメントの旧記述も直した。

対応マトリクスと改訂した詳細設計の全文を示す。

## 対応マトリクス

# 対応マトリクス: design-review Round 4

## [Critical] discovery のキャッシュに広告された署名方式が無い（B1）

- 判断: **対応する**
- 根拠: 正しい。metadata DTO へ `idTokenSigningAlgorithms` を足したのに保存スキーマへ足していなかった。
  キャッシュ hit の後に B3 の「アプリの許可集合 ∩ IdP の広告集合」が成立しない。
- 対応内容: 保存スキーマへ `id_token_signing_algorithms: non-empty-list<string>` を追加。
  **破損・空配列・未知の値のいずれでも `forget` する**テストを追加した。
  「キャッシュ hit の後でも広告集合との共通部分が成立する」回帰も足した。

## [Critical] B1 のコード例が G3 で確定した例外の構築子と矛盾（B1）

- 判断: **対応する**
- 対応内容: 生成箇所を **`EnterpriseSsoAttemptRejectedException::of(RejectionReason::…)`** に統一した
  （`previous` を受け取れない構築子。理由の enum だけを持つ）。

## [Critical] `consumeFailed` の意味が未定義（B4）

- 判断: **対応する（提示された後者の案を採る）**
- 根拠: `delete()` が行に当たらないのは**基盤の障害**であって業務上の拒否ではない。
  一様な拒否へ畳むと「排他が壊れた」という重大な事実が隠れる。
- 対応内容: `consumeFailed` を分類から**外し**、`EnterpriseSsoAttemptStoreFailure` の
  **例外**にしてトランザクションを巻き戻す（行もセッションの秘密も残る）。
  分類表に「（例外）DB の障害」の行を足し、
  「業務上の拒否では例外を投げない」と「DB の障害は握り潰さない」を分けて書いた。
  負のコントロールのテストも追加した。

## [Critical] 身元 0 件での issuer / client_id 変更後の状態が未定義（D1）

- 判断: **対応する**
- 対応内容: **身元が 0 件でも、issuer / client_id を変えたら必ず `Draft` へ戻し
  `verified_at` を消す**（未検証の新構成で直ちにログインできる状態を作らない）。

## [Critical] 更新・削除と callback の直列化が不足（D1 / D2）

- 判断: **対応する**
- 根拠: 正しい。「身元 0 件を確認 → callback が JIT → 更新／削除」の順で
  **身元があるのに名前空間が変わる／身元だけが消える**。
- 対応内容: 無効化だけでなく **`update` / `verify` / `activate` / `disable` / `destroy` のすべて**が
  接続の行を `lockForUpdate()` した同一トランザクションで
  「身元の有無の確認 → 検査 → 変更」を行う。**ロックの取得順を接続の行に統一**する。
  並行ハーネスで両方向を固定する:
  callback が先なら更新・削除は「身元あり」で拒否／更新・削除が先なら callback は JIT しない。

## [Warning] pgsql の `varchar(255)` は 255 文字であって 255 バイトではない（A2）

- 判断: **対応する（DB 側にも制約を置く）**
- 根拠: 正しい。「DTO と DB が同じバイト境界」という説明は成立していなかった。
- 対応内容: **DB に CHECK 制約 `octet_length(subject) BETWEEN 1 AND 255` を置く**。
  身元の主キーなので型の検査だけに頼らない。制約の実在をスキーマの読み取りで固定する。

## [Warning] C1 の docblock と移行のコメントに旧記述が残っている

- 判断: **対応する**
- 対応内容: 「subject の指紋」→「生の subject（`COLLATE "C"`）」へ統一。
  移行の一意制約のコメントを「**最後の防波堤。違反は捕まえず再送出する**」へ更新した。

## [Warning] route 件数が複数箇所で古い（E1 / F2 / 実装モード）

- 判断: **対応する**
- 対応内容: 施策一覧の E1（3 本 → **4 本**）、F2 の見出し（10 → **14**）、
  施策一覧の F2（13 → **14**）、実装モード（13 → **14**）をすべて統一した。

## [Warning] 確認画面へのトークンの渡し方と露出範囲が未確定（E1）

- 判断: **対応する（受容し、保証範囲を書き切る）**
- 対応内容: URL の query に載せることを**受容**し、
  固定すること（GET は状態を変えない／有効・無効で画面を変えない／`Referrer-Policy: no-referrer`／
  外部リソースを読み込まない／ログ・監査・例外に完全な URL を記録しない／
  POST へはサーバが描画した form の hidden で渡し Inertia の props に置かない／失敗時に flash しない）と、
  **保証しないこと**（プロキシや CDN のアクセスログ・ブラウザの履歴・利用者が URL を貼ること）を
  明記した。緩和は **60 分の期限**と **一回だけの consume** である。

## [Warning] `EnterpriseSsoPruneScheduleTest.php` が D37 の対象パスに無い（F3）

- 判断: **対応する**
- 対応内容: 対象パスへ追加した。

## [Warning] D37 の再判定の条件がメール昇格側の正典化を含んでいない（F3）

- 判断: **対応する**
- 対応内容: 再判定の条件へ
  「メールアドレスの昇格の側が正典で指紋方式を採ったときも見直す」を追加した。

## 実装時に確認すればよいもの（Codex が承認の妨げではないと明示した項目）

そのまま設計へ残す（本設計は着手条件・保証範囲として既に書いている）:

- ssrf-pin ^0.4 の deadline / body の最終形（B2 の着手条件）
- G2 の保護対象語彙が通常のコードを誤検出しないこと（F1 の負例で裏取り）
- `COLLATE "C"` のスキーマ読み取りの表記
- 並行ハーネス上の同期点の置き方
- `PinnedHttpClient` が値の失敗以外に例外を投げる場合の扱い
  （投げるなら `previous` なしの固定例外へ変換する。B2 の方針がそのまま当てはまる）
- `subject` の許容文字（ASCII 限定か UTF-8 の非制御文字か）

---

## 改訂後の詳細設計（全文）

# 詳細設計: enterprise-oidc-sso-adoption

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

> 本設計は 5・6 に該当する変更を持たない（LLM 呼び出しを一切足さない）。
> 7 は該当あり — 企業ログインの**確定時のみ** `redirect()->intended()` を使う（ログイン直後フローなので許される）。
> 組織側の接続管理の操作系はすべて `back()->with(...)` で完結させる。
> 8 は D2 の画面に該当あり（未入力でもボタンを押せる。押下時にエラー表示する）。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** はグローバル適用済（個別 `DatabaseTransactions` 禁止）、`--parallel` 実行
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）。本設計は新モデル 4 本 → **Factory 4 本を施策に含む**
- **DTO + JsonResource** パターン / **アーリーリターン**
- `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260823-0015-enterprise-oidc-sso-adoption/conceptual-design.md`（APPROVED / Round 5）

正典: 家系の機能台帳 lctl `auth-enterprise-oidc`
（`feature_revision: 23-30a9407c8f19` / `canonical_version: v1 (AG-200)`）

## 実読で確定した前提（設計判断の根拠）

| 前提 | 実読の結果 |
|---|---|
| **DB は pgsql**（テストも本番も） | `phpunit.xml` が `DB_CONNECTION=pgsql` を `force="true"` で固定。SQLite は使わない → `SELECT … FOR UPDATE` の排他契約が本番と同じ |
| **`PinnedHttpClient` の API** | `fetch(PinnedRequest, Deadline): PinnedResponse\|PinnedFailure`。**^0.4 では要求・応答の body と大きさ制限つき読み出しを持つ**（前段 TODO の調査で実読済み） |
| **JWT の部品** | `firebase/php-jwt` **v7.0.5 が既に解決済み**（家系の台帳が言う「署名付きトークンの検証に使う部品」と同版）。推移依存なので `composer.json` へ明示する |
| **役割** | `OrganizationRole` は `Owner` / `Admin` / `Member` |
| **`users.email`** | 現在 **NOT NULL**（CipherSweet の ciphertext を `text` で保持。一意性は `blind_indexes` の partial unique） |
| **既存のソーシャル SSO** | `SocialAuthController::callback()` は `Auth::login()` で即時確定。2 要素の入力画面へ送る分岐を持たない（= AG-200 の形） |
| **並行テストのハーネス** | 別 TODO **`process-concurrency-harness-adoption`** が設計済み（`devnotes/20260822-2315-…`）。正典 `process-concurrency-test-harness` v1 の 6 要素をテンプレートからバイト一致で取り込む。**本設計の並行テストはこれに乗る**（グローバル `RefreshDatabase` のトランザクション内で作ったフィクスチャは別接続から見えないため、独自に組まない） |
| **`dontFlash` の実績** | リポジトリに**使用実績なし**。Laravel 12 の作法は `bootstrap/app.php` の `withExceptions()` 内 `$exceptions->dontFlash([...])` |
| **モデルの文書** | `docs/architecture.md` / `docs/factories.md` が実在する（新モデルの登録先） |
| **2 要素の組織義務づけ** | `RequireTwoFactorForEnforcedOrganizations` の転送先は `settings.security`（**設定ページ**であって入力画面ではない） |

## 正典の不変条件（全列挙。すべて本設計が満たす）

| # | 不変条件 | 本設計での保証機構 |
|---|---|---|
| I1 | **メールアドレスで利用者を引かない**（引き当ての鍵は **接続 × `COLLATE "C"` の subject**） | A2 の列設計 + C1 + gate G1 |
| I2 | **身元表の申告メールに索引を付けない**（暗号化はする） | A2 + G1 の「申告メールを含む索引が 0 本」検査 |
| I3 | **外部取得は必ず SsrfPin の窓口経由**（接続先情報 / 鍵 / トークン交換の 3 経路） | B1・B2 が `PinnedHttpClient` に一本化 + gate G2 |
| I4 | **接続の秘密を扱う前面は登録・更新フォーム 1 本のみ** | D2 + gate G3 |
| I5 | **受け渡しの型・例外に接続の秘密が存在しない**（例外は機械可読な理由文字列のみ） | A2 の値型 + B2 + D2 + gate G3 |
| I6 | **共通ログイン経路に 2 要素認証を挟まない**（AG-200） | C2 + gate G4 + 実挙動テスト 2 本 |
| I7 | **初回ログインでその場で利用者を作る (always-JIT)** | C1 |
| I8 | **メール昇格フローは `App\Services\Auth` 名前空間へ置く**（正典の設計判断ごと引き継ぐ） | E1 + gate G5 |

### AGENTS.md セキュリティ不変条件の対応

| AGENTS.md | 本設計での対応 |
|---|---|
| 不変条件 1（tenant キー不信） | 接続の組織は URL から解決。payload から `organization_id` を受けない（`MassAssignmentSafetyTest` の母集団に入る） |
| 不変条件 2 / 10（子は親に属する = 認可より前に 404 / 層 2 は binding 直後） | `{organization:slug}` → `{oidcConnection}` を `scopeBindings()` で解決（`Organization::oidcConnections()`）。F2 で `NestedRouteDefenseInventory` へ登録 |
| 不変条件 3（cross-org 不可） | 接続・身元はすべて組織スコープ解決。クラス起点の主キー同一性クエリを書かない |
| 不変条件 5（`laratrust_team_id` を明示） | C1 の所属・役割の付与で組織の team id を明示する |
| 不変条件 6（PII は CipherSweet） | 身元表の申告メールを暗号化。**blind index は付けない**（I2） |
| 不変条件 8（SSRF 窓口） | I3。境界は `config/ssrf-pin.php` の pin をそのまま使う（**本設計は同ファイルを変更しない**） |
| 不変条件 9（変更系 route は認可を通る） | 組織側 6 変更系は `Gate::authorize`。GET だが DB を変える開始 route は F2 で理由付きの分類を持つ |
| 不変条件 11（キャッシュは素のデータだけ） | B1 の短期保存は**素の配列とスカラーのみ**。読み戻しは DTO へ組み立て直し、失敗したら `forget` する。`CachePayloadPlainDataGateTest` の目録へ登録する |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A1 | 設定ファイル・値域 enum・**一時値の**指紋の導出 | `config/enterprise-sso.php`, `app/Enums/EnterpriseSso/*`, `app/Support/EnterpriseSso/AttemptFingerprint.php` | High |
| A2 | モデル 3 本 + 移行 3 本 + Factory 3 本 + 秘密の値型 + 文書 | `app/Models/*`, `database/migrations/*`, `database/factories/*`, `app/Casts/*`, `app/ValueObjects/*`, `docs/architecture.md`, `docs/factories.md` | High |
| A3 | `users.email` の nullable 化（企業 SSO のみの利用者） | `database/migrations/*`, `app/Models/User.php`, `app/Auth/EncryptedUserProvider.php` ほか波及 | High |
| B1 | 接続先情報と鍵の取得（`PinnedHttpClient` 一本化） | `app/Services/EnterpriseSso/OidcDiscoveryService.php` ほか DTO | High |
| B2 | トークン交換（body 付き pinned 要求） | `app/Services/EnterpriseSso/OidcTokenExchanger.php` ほか DTO | High |
| B3 | ID トークンの検証（`firebase/php-jwt`） | `app/Services/EnterpriseSso/EnterpriseIdTokenVerifier.php` ほか DTO, `composer.json` | High |
| B4 | ログイン試行の保管（原子的 consume + ブラウザ結合） | `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php` ほか, `routes/console.php` | High |
| C1 | 利用者の自動作成 (always-JIT) | `app/Services/EnterpriseSso/EnterpriseUserProvisioner.php` | High |
| C2 | 開始と戻り口・controller・route 3 本 | `app/Services/EnterpriseSso/EnterpriseCallbackAuthenticator.php`, `app/Http/Controllers/Auth/EnterpriseSsoLoginController.php`, `app/Http/Requests/Auth/*`, `routes/web.php` | High |
| D1 | 接続の状態遷移サービス | `app/Services/EnterpriseSso/OidcConnectionTransitionService.php` | High |
| D2 | 組織側の接続管理 controller・route 7 本・画面 | `app/Http/Controllers/Organizations/*`, `app/Http/Requests/Organizations/*`, `resources/js/pages/Organizations/Sso/Index.svelte`, `routes/web.php` | High |
| E1 | メールアドレスの昇格フロー（**Auth 名前空間**）+ route 4 本 | `app/Services/Auth/EmailPromotionService.php` ほか + 移行 1 本 + Factory 1 本 + `routes/web.php`, `docs/architecture.md`, `docs/factories.md` | Medium |
| F1 | gate 5 本（G1〜G5）+ 走査器 | `tests/Architecture/*`, `tests/Support/EnterpriseSso/*`, `tests/Unit/Architecture/*` | High |
| F2 | aicue 側の目録登録（**新規 14 route の全分類**） | `app/Enums/Security/*`, `tests/Support/*`, `tests/Architecture/*` | High |
| F3 | 逸脱の登録 D37 + 台帳件数の pin | `docs/template-divergence.md`, `tests/Support/TemplateDivergence/LedgerPins.php` | High |
| F4 | 試験用の偽 IdP と外部到達点の登録 | `app/Services/EnterpriseSso/Fakes/*`, `app/Http/Controllers/Testing/*`, `app/Support/ExternalFakes/ExternalFakeDeclaration.php`, `tests/Support/ExternalSeam/ExternalSeamInventory.php` | High |

---

## A1: 設定ファイル・値域 enum・指紋の鍵導出

### 変更箇所
- 新規: `config/enterprise-sso.php`
- 新規: `app/Enums/EnterpriseSso/OidcConnectionStatus.php` / `OidcSigningAlgorithm.php` / `TokenEndpointAuthMethod.php`
- 新規: `app/Support/EnterpriseSso/AttemptFingerprint.php`（用途別の指紋の導出）

### 波及変更
- TypeScript型定義: **あり** — 接続の状態の値域を `resources/js/components/features/sso/oidc-connection.ts` へ写す（正典が 2026-08-08 に画面直書きから TS 定数へ切り出した形に合わせる）。既存の enum ↔ TS 同期 gate の母集団へ載せる（F2）
- API Resource/DTO: なし
- テストファイル: `tests/Architecture/EnvExampleInvariantTest` は**対象外**（新しい環境変数を足さないため。下記）

### 指紋の鍵の出所（Codex Round 1・2 の指摘への回答）

★**永続する値には指紋を使わない**。これが Round 2 の [Critical] への回答である。

`subject`（身元の主キー）を `APP_KEY` 由来の指紋にすると、
**`APP_KEY` をローテートした瞬間に既存の指紋を再現できなくなり、
次回ログインで別の利用者・別の身元が JIT で作られる**（アカウントの分裂）。
したがって `subject` は指紋にせず、**列の照合順序で byte 一致を担保する**（A2）。

`AttemptFingerprint` が扱うのは**寿命の短い一時値だけ**である。

```php
/** 指紋の用途。**相互に使い回せない** (domain separation)。永続する値は扱わない。 */
enum FingerprintPurpose: string
{
    case State = 'enterprise-sso.state';                    // 寿命 10 分
    case Nonce = 'enterprise-sso.nonce';                    // 寿命 10 分
    case BrowserBinding = 'enterprise-sso.browser-binding'; // 寿命 10 分
    case EmailPromotionToken = 'auth.email-promotion';      // 寿命 60 分
}

/**
 * **一時値**の指紋の導出。用途ごとに domain separation する。
 *
 * 鍵は **APP_KEY から用途別ラベル付きで導出する** (HKDF)。専用の秘密を新設しない —
 * 運用要件を 1 つ増やす価値が無い (思考原則 2)。判断の根拠:
 *   APP_KEY をローテートして失効するのは **進行中の試行 (10 分) と未確認の昇格 (60 分) だけ**である。
 *   ★**身元・接続・利用者はどれも指紋に依存しない** (subject は指紋を使わない) ので、
 *     ローテートで失われる永続的なものが無い。
 *   (対比: パスキーの利用者ハンドルは APP_KEY 由来だと**登録済みパスキーが全件無効**になるため
 *    専用の秘密を要求している。ここはその条件に当たらない。)
 *
 * ★**この型に永続する値の用途を足さない**。足すと上の根拠が崩れる。
 */
final class AttemptFingerprint
{
    public static function of(FingerprintPurpose $purpose, #[SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, self::key($purpose));
    }

    /**
     * 鍵の導出の契約 (実装差を残さないために書く):
     *   - 入力鍵: `config('app.key')` の **`base64:` 接頭辞を外して base64 復号したバイト列**
     *     (復号できない設定は例外。黙って文字列のまま使わない)
     *   - salt:   空 (アプリ内で 1 つの入力鍵しか使わないので salt に載せる情報が無い)
     *   - info:   **用途の値そのもの** (`FingerprintPurpose::value`)。これが domain separation の実体
     *   - 出力長: 32 バイト
     */
    private static function key(FingerprintPurpose $purpose): string { /* hash_hkdf('sha256', …) */ }
}
```

### 変更後コード（config。**参照されない項目を作らない**）

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| エンタープライズ OIDC SSO
|--------------------------------------------------------------------------
| ★外部 URL の安全境界は **ここに書かない**。SSRF の境界の正本は
|   config/ssrf-pin.php であり、本設計はそれを変更しない (同じ事実を 2 か所に置かない)。
| ★環境変数を足さない。すべて固定値である (テンプレートの固定値方式に合わせる。
|   起源 spirux の環境変数方式とは割れているが、正典はテンプレート側である)。
*/

return [
    'discovery' => [
        'connect_timeout_seconds' => 3,
        'request_timeout_seconds' => 5,
        'cache_ttl_seconds' => 300,
        // 未知 kid での鍵の再取得の最小間隔 (増幅を防ぐ)
        'jwks_refetch_min_interval_seconds' => 60,
        'max_body_bytes' => 262144,
    ],

    'token' => [
        'connect_timeout_seconds' => 3,
        'request_timeout_seconds' => 8,
        'max_body_bytes' => 65536,
    ],

    'id_token' => [
        // 許容する時刻ずれ。**顧客の入力では広げられない** (接続の登録項目にしない)。
        'leeway_seconds' => 60,
        'max_subject_length' => 255,
    ],

    'login_attempt' => [
        'ttl_seconds' => 600,
        // 掃除の 1 回あたりの上限 (長いトランザクションを作らない)
        'prune_chunk' => 1000,
    ],

    // メールアドレスの昇格 (E1)。Auth 名前空間の機能だが、設定は本ファイルに集約する
    // (企業 SSO でしか入れない利用者のための機構であり、単独では意味を持たない)。
    'email_promotion' => [
        'ttl_seconds' => 3600,
    ],
];
```

```php
enum OidcConnectionStatus: string
{
    case Draft = 'draft';       // 登録直後 / 認証材料を更新した直後。ログインに使えない
    case Verified = 'verified'; // 接続先情報の取得に成功した。まだ使えない
    case Active = 'active';     // ログインに使える
    case Disabled = 'disabled'; // 運営が止めた
}

/** ID トークンの署名方式の許可集合。`none` と対称鍵 (HMAC) は **case に持たない**。 */
enum OidcSigningAlgorithm: string
{
    case Rs256 = 'RS256';
    case Rs384 = 'RS384';
    case Rs512 = 'RS512';
    case Es256 = 'ES256';
    case Es384 = 'ES384';
}

/** token endpoint の client 認証方式。**body 漏洩面が小さい basic を優先する**。 */
enum TokenEndpointAuthMethod: string
{
    case ClientSecretBasic = 'client_secret_basic';
    case ClientSecretPost = 'client_secret_post';
}
```

> **`none` と HMAC を「拒否リスト」でなく「enum に持たない」形にする理由**:
> 許可集合を型で表せば、拒否漏れという失敗様式そのものが消える。
> 文字列の比較で弾く形は、比較の書き忘れ 1 つで通る。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（読み出しは `Config::integer()` で型を確定させる）
- [x] null安全（`Config::integer()` は null を返さない。準拠実装 `SnsCertificateFetcher`）
- [x] DTOを返している（本施策は値域と純関数のみ）
- [x] Genericsの型パラメータが正しい（該当なし）

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseSsoConfigTest.php`
  - **全整数が正数**であること（0・負数を弾く）
  - 大小関係: `connect_timeout <= request_timeout`（discovery / token とも）
  - **上限**: `id_token.leeway_seconds <= 300`（時刻ずれを無制限に広げられない）／
    `login_attempt.ttl_seconds <= 1800`（試行が長生きしない）／
    `email_promotion.ttl_seconds <= 86400`／
    `discovery.max_body_bytes <= 1048576`／`token.max_body_bytes <= 262144`
  - `jwks_refetch_min_interval_seconds >= 1`
- [ ] 新規 `tests/Unit/Enums/OidcSigningAlgorithmTest.php` — `none` / `HS256` が `tryFrom()` で null（負のコントロール）
- [ ] 新規 `tests/Unit/Support/AttemptFingerprintTest.php`
  - **同じ入力でも用途が違えば別の指紋になる**（domain separation の実挙動）
  - **`FingerprintPurpose` に永続する値の用途が無い**（case を名指しで pin。
    足したら赤になり、A1 の根拠の見直しがレビューに出る）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- `APP_KEY` のローテートで**進行中の試行（10 分）と未確認の昇格（60 分）**が失効する。
  **これは受容した判断**であり docblock に根拠を書く。
  永続する値（`subject`）は指紋を使わないので、ローテートで失われるものはこれだけである。

---

## A2: モデル 3 本 + 移行 3 本 + Factory 3 本 + 秘密の値型

### 変更箇所
- 変更: `docs/architecture.md` / `docs/factories.md`（**新モデル 3 本を登録する**。AGENTS.md の必須手順）
- 新規: `app/Models/OrganizationOidcConnection.php` / `EnterpriseIdentity.php` / `EnterpriseSsoLoginAttempt.php`
- 新規: `database/migrations/2026_08_23_000100_create_organization_oidc_connections_table.php`
- 新規: `database/migrations/2026_08_23_000200_create_enterprise_identities_table.php`
- 新規: `database/migrations/2026_08_23_000300_create_enterprise_sso_login_attempts_table.php`
- 新規: `database/factories/OrganizationOidcConnectionFactory.php` / `EnterpriseIdentityFactory.php` / `EnterpriseSsoLoginAttemptFactory.php`
- 新規: `app/ValueObjects/EnterpriseSso/ConnectionSecret.php`（**暗黙の文字列化を持たない**秘密の値型）
- 新規: `app/Casts/EncryptedSecretCast.php`
- 変更: `app/Models/Organization.php`（`oidcConnections()` relation を追加）

### 波及変更
- TypeScript型定義: なし（画面へ出すのは D2 の DTO 経由）
- API Resource/DTO: D2 の `SsoConnectionSummary` が本モデルを入力にする
- テストファイル: `MassAssignmentSafetyTest` の母集団に 3 モデルが入る

### 移行 1: `organization_oidc_connections`

```php
Schema::create('organization_oidc_connections', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

    // 公開のログイン導線 (/enterprise/{slug}/redirect) で使う識別名。
    // ★**全体で一意**であり、**推測されてよい**。推測可能性に依存した防御を持たない —
    //   防御は接続の状態 (Active か) と state / PKCE / ブラウザ結合が担う。
    $table->string('slug', 64)->unique();

    $table->string('display_name', 100);

    // 顧客が入力する。https 必須・userinfo/query/fragment なし・正規化できる絶対 URL。
    $table->string('issuer', 255);
    $table->string('client_id', 255);

    // ★暗号化して保存する。読み出しは ConnectionSecret 値型を経由し、
    //   平文の取り出しは token 交換だけが呼ぶ 1 メソッドに集約する。索引を持たせない。
    $table->text('client_secret_encrypted');

    $table->string('status', 16)->default(OidcConnectionStatus::Draft->value);
    $table->timestamp('verified_at')->nullable();
    $table->timestamps();

    // 1 組織に複数の接続を許す (合併・複数 IdP の企業がある)。組織単位の検索用。
    $table->index('organization_id');
});
```

### 移行 2: `enterprise_identities`

```php
Schema::create('enterprise_identities', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('organization_oidc_connection_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();

    // ★IdP の subject。**これが身元の主キーである**。
    //   照合を **COLLATE "C" (バイト単位)** に固定する — 既定の照合順序では
    //   `Alice` と `alice` が同一視されうる環境があり、そうなると
    //   **別人が同じアカウントに入る**。
    //   ★指紋 (HMAC) にはしない。指紋は鍵に依存するので、APP_KEY をローテートすると
    //     既存の身元へ到達できなくなり**アカウントが分裂する** (Round 2 の指摘)。
    //     列の照合順序なら鍵に依存しない。
    $table->string('subject', 255)->collation('C');
    // ★境界は 2 層で閉じる:
    //   (1) 入力側 — VerifiedIdTokenClaims の構築時に「**バイト長 1〜255**」
    //       「制御文字を含まない」を検査する
    //   (2) DB 側 — ★pgsql の `varchar(255)` は **255 バイトではなく 255 文字**なので、
    //       バイト長の保証にならない。**CHECK 制約を別に置く**:
    //         octet_length(subject) BETWEEN 1 AND 255
    //       (身元の主キーなので、型の検査だけに頼らず DB でも閉じる)

    // ★申告メール: 暗号化して持つが **索引を意図的に付けない**。
    //   索引を付けると「メールで引ける」経路が実装として復活し、正典 v1 の I1/I2 が崩れる。
    //   blind index も付けない (configureCipherSweet で addBlindIndex を呼ばない)。
    $table->text('claimed_email_encrypted')->nullable();

    $table->timestamp('last_login_at')->nullable();
    $table->timestamps();

    // ★**最後の防波堤**である。競合制御の本体は C2 が張る接続の行ロックであり、
    //   C1 はこの制約違反を**捕まえない** (違反はそのまま伝播させる。
    //   握り潰すと「直列化が壊れた」という重大な事実が競合として隠れる)。
    //   制約名を明示するのは、違反が起きたときに出所が一目で分かるようにするためである。
    $table->unique(
        ['organization_oidc_connection_id', 'subject'],
        'enterprise_identities_connection_subject_unique',
    );

    $table->index('user_id');
});
```

### 移行 3: `enterprise_sso_login_attempts`

```php
Schema::create('enterprise_sso_login_attempts', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('organization_oidc_connection_id')->constrained()->cascadeOnDelete();

    // state の **指紋だけ**を持つ (原文を保存しない)。一意制約が使用権の唯一性の根拠。
    $table->char('state_fingerprint', 64)->unique();

    // nonce も **指紋だけ**。ID トークンの nonce を同じ用途ラベルで指紋化して定時間比較する。
    $table->char('nonce_fingerprint', 64);

    // 開始したブラウザとの結合 (login CSRF を塞ぐ本体)。
    // セッションへ置いた「結び付けの秘密」の指紋。**session ID は保存しない**。
    $table->char('browser_binding_fingerprint', 64);

    // PKCE の検証子だけは token 交換でそのまま送るので原文が要る → 暗号化して保存。
    $table->text('pkce_verifier_encrypted');

    $table->timestamp('expires_at');
    $table->timestamps();

    $table->index('expires_at');   // 期限切れ掃除の走査用
});
```

### 秘密の値型（Codex Round 1 の [Critical] への回答）

```php
/**
 * 接続の秘密 (client secret) の値型。
 *
 * ★**暗黙の文字列化を持たない** — `__toString()` を実装しない。
 *   これにより「うっかり文字列連結・ログ・例外・DTO へ載る」経路が**型で消える**。
 * ★平文の取り出しは {@see self::revealForTokenExchange()} の 1 メソッドだけである。
 *   このメソッドを呼んでよいのは OidcTokenExchanger だけであり、
 *   tests/Architecture/EnterpriseSsoSecretExposureGateTest が呼び出し元を exact-fit で pin する。
 *
 * ## 保証する範囲 (誇張しない)
 *
 * `__debugInfo()` が効くのは **`var_dump()` 系だけ**である。
 * ★**`var_export()` / `serialize()` / Reflection からは平文が見える**。
 *   任意の PHP の内省に対して隠せるとは**主張しない**。
 *   したがって守りは 3 層に分ける:
 *     1. 型 — 暗黙の文字列化を持たない (うっかりの連結・出力を消す)
 *     2. gate — **この値型をログ・dump・直列化の関数へ渡す記法**を G3 が禁じる
 *     3. **主たる証明** — 実挙動の漏洩テスト (例外・監査・ログ・要求の記録に出ない)
 */
final readonly class ConnectionSecret
{
    private function __construct(private string $plaintext) {}

    public static function fromPlaintext(#[SensitiveParameter] string $plaintext): self
    {
        return new self($plaintext);
    }

    /** ★token 交換だけが呼ぶ。他所からの呼び出しは gate が落とす。 */
    public function revealForTokenExchange(): string
    {
        return $this->plaintext;
    }

    /**
     * ★`var_dump()` 系にだけ効く。`var_export()` / `serialize()` / Reflection には効かない。
     *
     * @return array{client_secret: string}
     */
    public function __debugInfo(): array
    {
        return ['client_secret' => '********'];
    }
}
```

### モデル（要点）

```php
final class EnterpriseIdentity extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<EnterpriseIdentityFactory> */
    use HasFactory, UsesCipherSweet;

    /**
     * ★**メールアドレスで利用者を引かない** (正典 v1 / I1)。
     *   引き当ての鍵は **(organization_oidc_connection_id, 生の subject)** だけである
     *   (subject 列は `COLLATE "C"` で byte 一致。**指紋にしない** = 鍵のローテーションに依存しない)。
     *   申告メールは暗号化して持つが **blind index を付けない** —
     *   索引があると「メールで引ける」経路が復活する。
     *   これは tests/Architecture/EnterpriseSsoEmailIdentityIsolationTest が
     *   記法の走査と **「申告メールを含む索引が 0 本」のスキーマ検査** の二層で固定する。
     */
    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        // addBlindIndex を **呼ばない**。これが不変条件の実体である。
        $encryptedRow->addField('claimed_email_encrypted');
    }

    /** @var list<string> */
    protected $fillable = [];  // 生成は Provisioner が明示的に組み立てる (mass assignment を作らない)
}
```

### モデルの契約（cast / hidden / relation / Factory generics）

| モデル | cast | `$hidden` | relation | Factory |
|---|---|---|---|---|
| `OrganizationOidcConnection` | `status` → `OidcConnectionStatus::class` / `verified_at` → `immutable_datetime` / `client_secret_encrypted` → `EncryptedSecretCast::class`（`ConnectionSecret` を返す） | `client_secret_encrypted` | `organization()` (BelongsTo) / `identities()` (HasMany) / `loginAttempts()` (HasMany) | `@use HasFactory<OrganizationOidcConnectionFactory>` |
| `EnterpriseIdentity` | `last_login_at` → `immutable_datetime`（申告メールは CipherSweet が担当） | `claimed_email_encrypted` | `connection()` (BelongsTo) / `user()` (BelongsTo) | `@use HasFactory<EnterpriseIdentityFactory>` |
| `EnterpriseSsoLoginAttempt` | `expires_at` → `immutable_datetime` / `pkce_verifier_encrypted` → `encrypted` | `pkce_verifier_encrypted` / `state_fingerprint` / `nonce_fingerprint` / `browser_binding_fingerprint` | `connection()` (BelongsTo) | `@use HasFactory<EnterpriseSsoLoginAttemptFactory>` |

- `$fillable` は **3 モデルとも空**（生成は Service が明示的に組み立てる。mass assignment を作らない）
- **`toArray()` から暗号文も秘密の値型も出ない**ことをテストで固定する（`$hidden` の実効確認）

```php
// app/Models/Organization.php へ追加 (D2 の scopeBindings が引く relation)
/** @return HasMany<OrganizationOidcConnection, $this> */
public function oidcConnections(): HasMany
{
    return $this->hasMany(OrganizationOidcConnection::class);
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（relation は generics つき）
- [x] null安全（`claimed_email_encrypted` / `verified_at` は nullable を型で明示）
- [x] DTOを返している（モデルは境界の外へ出さない。D2 で DTO へ畳む）
- [x] Genericsの型パラメータが正しい（`@use HasFactory<XxxFactory>` を 3 モデルとも書く）

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseIdentityIsolationTest.php`
  - **申告メールの列（または対応する blind index）を含む索引が 0 本**である
    （**スキーマの読み取りのみ**。`migrate:fresh` 等の破壊操作を伴わない = 禁止事項 3）
  - **`subject` 列の照合順序が `C` である**（スキーマの読み取り。設定が外れたら赤）
  - **`Alice` と `alice` が別の身元になる**（照合順序の実挙動。上の検査と二層）
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseSsoModelHidingTest.php` —
      3 モデルの `toArray()` に暗号文・秘密の値型が出ない
- [ ] `subject` の**バイト長 256 以上**と**制御文字を含む値**が DTO の構築で拒否される
- [ ] **DB の CHECK 制約が実在する**（`octet_length(subject) BETWEEN 1 AND 255`。
      スキーマの読み取り。型の検査を迂回して書けないことの二層目）
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseIdentityCipherSweetTest.php` — 申告メールが平文で保存されない
- [ ] 新規 `tests/Unit/ValueObjects/ConnectionSecretTest.php`
  - **`__toString()` を持たない**（`method_exists` が false）
  - `__debugInfo()` と `json_encode()` に平文が出ない
  - ★**`var_export` / `serialize` に出ないとは検査しない**（保証していないため。
    誤った安心を与えるテストを置かない）
- [ ] Factory 3 本が `RefreshDatabase` 下で動く
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### 身元がある接続を物理削除させない（Round 3 の [Critical] への回答）

`cascadeOnDelete` は「接続を消すと身元も消える」を意味するが、**利用者は残る**。
その後で同じ IdP を再登録し、同じ `subject` で入ると
**新しい利用者が JIT で作られる**（アカウントの分裂）。
企業 SSO でしか入れない利用者は、元のアカウントへ**二度と戻れない**。

したがって:

- **身元が 1 件でもある接続は物理削除できない**（D1 の状態遷移と D2 の `destroy` で拒否する）
- 削除できるのは**身元が 0 件の接続だけ**である（登録し損ねた `Draft` の後始末が主用途）
- 運用は **無効化 (`Disabled`)** で行う。無効化なら身元は残り、
  再び有効にしたときに**同じ利用者へ戻る**
- **組織そのものの削除に伴う連鎖は本設計の対象外**とする
  （組織削除は利用者・課金・データ全体を扱う別の運用であり、ここで再設計しない）

### リスク
- **移行 3 本目（試行表）はテンプレートに無い上積み**である → F3 で逸脱 D37 として登録する。

---

## A3: `users.email` の nullable 化（企業 SSO のみの利用者）

### 変更箇所
- 新規: `database/migrations/2026_08_23_000050_make_users_email_nullable.php`
- 変更: `app/Models/User.php`（`email` の型注釈・`$fillable` の扱い）
- 変更: `app/Auth/EncryptedUserProvider.php`（null のメールで引かれない）
- 変更: `app/Actions/Fortify/UpdateUserProfileInformation.php` ほか
  「`email` を null → 非 null にしうる経路」（`email_verified_at` を消す）
- 変更: `docs/architecture.md`（`email_verified_at` の意味と更新規約）

### なぜ必要か（Codex Round 1 の [Critical] への回答）

企業 SSO でしか入れない利用者は **使えるメールアドレスを 1 件も持たない**。
選択肢は 3 つあり、採るのは (c) である:

| 案 | 判断 |
|---|---|
| (a) 仮のメール文字列を作る（`sub@example.invalid` 等） | **採らない**。偽のメールは nOAuth の再現面と衝突の温床になり、通知の誤送先にもなる |
| (b) `hasVerifiedEmail()` を認証方式込みで再定義する | **採らない**。既存の `verified` middleware の意味論を変えるのは波及が広すぎる |
| (c) **`email` を nullable にし、`email_verified_at` は now() で作る** | **採る**。「IdP が本人確認した。**確認すべきメールが無い**」の意味であり、`hasVerifiedEmail()` は既存の実装のまま真になる。middleware の意味論を変えない |

### `email_verified_at` の意味を壊さないための規約（Round 2 の [Warning] への回答）

`email = null` かつ `email_verified_at != null` という状態は、
**後から別経路で email だけが入ると、その新しいメールが自動的に確認済みになる**という穴を持つ。
したがって:

- **`email` を null → 非 null にする経路を棚卸しし、
  メール昇格（E1）以外のすべての経路で `email_verified_at` を必ず消す**
- **メール昇格の確定では、`email_verified_at` を「以前の値のまま」にせず、
  新しいメールを実際に確認した時刻へ更新する**（監査上の意味を保つ）
- 棚卸しの対象: Fortify のプロフィール更新（`UpdateUserProfileInformation`）／
  管理画面（Filament）／seeder / Factory ／その他 `email` を書く全経路
- これを **Architecture テストで deny-by-default にはしない**（`email` を書く経路の
  網羅的な静的判定は本設計の範囲を超える）。代わりに**実挙動テストで主要経路を固定**し、
  規約を `docs/architecture.md` に書く（保証範囲を誇張しない）

### 変更後コード

```php
Schema::table('users', function (Blueprint $table): void {
    // 企業 SSO でしか入れない利用者は使えるメールを持たない (正典 v1 の always-JIT)。
    // ★email の一意性は平文 unique ではなく blind_indexes の **partial unique** が担うため、
    //   null 化しても一意性の担保は変わらない (null 行は blind index を持たない)。
    $table->text('email')->nullable()->change();
});
```

### 波及変更
- **TypeScript型定義**: 設定画面などで `email` を表示している Props を `string | null` へ
- **API Resource/DTO**: 利用者を返す DTO / Resource の `email` を nullable へ
- **テストファイル**:
  - `app/Auth/EncryptedUserProvider.php` — `whereBlind('email', …)` は null 行に当たらない（挙動は変わらないが**テストで固定する**）
  - Filament の管理画面（`/manage/users`）が null のメールで壊れない
  - 通知（`Notifiable`）が null のメール宛に送らない
  - `MassAssignmentSafetyTest`（`$fillable` は変えない）

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`User::$email` の型注釈を `?string` にし、参照箇所を PHPStan level 10 が洗い出す）
- [x] DTOを返している
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/Auth/EnterpriseOnlyUserEmailTest.php`
  - メールを持たない利用者が **`verified` middleware を通る**（`email_verified_at` が入っているため）
  - メールを持たない利用者が **パスワード再設定を要求できない**（宛先が無い）
  - **昇格以外の経路で `email` を入れると `email_verified_at` が消える**
    （自動で確認済みにならない）
  - **メールでのログイン（`EncryptedUserProvider`）が null 行に当たらない**
  - 管理画面の一覧が null のメールで壊れない
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **既存テーブルへの破壊的でない変更**だが、`email` を非 null 前提で書いた既存箇所が PHPStan で洗い出される。
  洗い出しの結果が大きすぎる場合でも **型を緩めて黙らせない**（禁止事項 2）。

---

## B1: 接続先情報と鍵の取得（`PinnedHttpClient` 一本化）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/OidcDiscoveryService.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/OidcProviderMetadata.php` / `OidcJsonWebKeySet.php`

### 設計の転回（Codex Round 1 の [Critical] への回答）

**`Http` ファサード・`HttpFactory` を企業 SSO の名前空間から締め出し、`PinnedHttpClient` に一本化する。**

`SnsCertificateFetcher` が「inspect → `Http::` で取得」の形なのは、
**v0.2 の `PinnedHttpClient` が本文を返せなかったから**である。
^0.4 ではその制約が無い。制約が消えたのに古い形を写すのは**後退**であり、
検査と接続の間の TOCTOU（DNS rebinding）を自分から作り直すことになる。

したがって:

- 3 経路（discovery / JWKS / トークン交換）とも `PinnedHttpClient::fetch()` を使う
- **「DNS rebinding は解消しない」という但し書きを書かない**（pin 済み経路には当てはまらない）
- G2 が `App\Services\EnterpriseSso` 配下の `Http` / `HttpFactory` の使用を**許可一覧なしで**弾く

### 接続先 URL の入力規則（[Critical] への回答）

`config/ssrf-pin.php` は `http` も許している（他用途のため）。
**企業 OIDC 自身の入力規則として https を必須化する** — でなければ
client secret・認可コード・トークンが平文で流れる。

```php
/**
 * issuer の値オブジェクト。**型で規則を担保する** (呼び出し側の作法に頼らない)。
 *
 * 規則: https のみ / userinfo なし / query なし / fragment なし / 絶対 URL / 長さ上限。
 *
 * ★**末尾のスラッシュを正規化しない**。OIDC の issuer は**識別子であって URL の正規化対象ではない** —
 *   `https://idp.example/tenant` と `https://idp.example/tenant/` は**別の issuer** になりうる。
 *   登録した文字列をそのまま保ち、discovery 文書の issuer と**仕様どおり完全一致**させる。
 *
 * ★well-known の URL は「issuer のパスの**後ろに**」付ける
 *   (`https://idp.example/tenant` → `https://idp.example/tenant/.well-known/openid-configuration`)。
 *   パス付きの issuer で正しく組み立つことをテストが固定する。
 */
final readonly class OidcIssuerUrl { /* … */ }
```

### 変更後コード（要点）

```php
final readonly class OidcDiscoveryService
{
    public function __construct(
        private PinnedHttpClient $pinned,   // ★Http ファサード・HttpFactory を注入しない
        private CacheRepository $cache,
    ) {}

    /**
     * discovery 文書の取得と検証。
     *
     * 防御:
     *  1. **pin 済み経路** — 検査・名前解決・接続が同じ経路 (AGENTS.md 不変条件 8)。
     *     境界の正本は config/ssrf-pin.php (本設計は変更しない)
     *  2. **リダイレクトを追従しない** — 転送先が未検査のまま取得されるのを防ぐ。
     *     2xx 以外は一様に拒否する
     *  3. **issuer の完全一致** — 文書の issuer が登録済み issuer と一致すること
     *  4. **endpoint は https の絶対 URL・userinfo なし・fragment なし** —
     *     ★同一 origin は**要求しない**。OIDC 標準の要件ではなく、
     *     実在の IdP (issuer と JWKS が別 origin) を拒否する。正典も要件にしていない。
     *     ★**query は禁じない** (禁じる標準上の根拠が無い)。
     *     各 endpoint は個別に pin 済み経路を通る
     *  5. **応答サイズ上限** — 期待と違う応答を DTO に固定しない
     *
     * @throws EnterpriseSsoAttemptRejectedException ★**理由の enum だけ**を持つ
     *         (`::of(RejectionReason::…)` で作る。`previous` を受け取れない構築子なので、
     *          vendor 例外の連鎖で body が展開される経路が型で消える)
     */
    public function fetchMetadata(OidcIssuerUrl $issuer): OidcProviderMetadata
    {
        $cached = $this->cachedMetadata($issuer);
        if ($cached !== null) {
            return $cached;   // アーリーリターン
        }

        $body = $this->fetchPinned(
            $issuer->wellKnownUrl(),
            Config::integer('enterprise-sso.discovery.max_body_bytes'),
            Config::integer('enterprise-sso.discovery.connect_timeout_seconds'),
            Config::integer('enterprise-sso.discovery.request_timeout_seconds'),
        );

        $metadata = OidcProviderMetadata::fromResponseBody($body, expectedIssuer: $issuer);

        $this->rememberMetadata($issuer, $metadata);

        return $metadata;
    }
}
```

```php
final readonly class OidcProviderMetadata
{
    /**
     * @param  non-empty-list<TokenEndpointAuthMethod>  $tokenEndpointAuthMethods
     * @param  non-empty-list<OidcSigningAlgorithm>  $idTokenSigningAlgorithms  IdP が広告した署名方式
     */
    private function __construct(
        public OidcIssuerUrl $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public array $tokenEndpointAuthMethods,
        public array $idTokenSigningAlgorithms,
    ) {}

    /**
     * ★**未知の要素を array<string, mixed> のまま内側へ出さない**。
     *   必要な要素だけを「存在」と「具体型」を検査してから組み立てる。
     */
    public static function fromResponseBody(string $body, OidcIssuerUrl $expectedIssuer): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // ★例外は **理由の enum だけ**を受け取る形に統一する (G3)。
            //   previous を受け取れない構築子なので、body が例外の連鎖で展開される経路が型で消える。
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNotJson);
        }

        if (! is_array($decoded)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNotObject);
        }

        $issuer = OidcIssuerUrl::fromString(self::requireString($decoded, 'issuer'));
        if (! hash_equals($expectedIssuer->value, $issuer->value)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryIssuerMismatch);
        }

        // 各 endpoint は https の絶対 URL であること (同一 origin は要求しない)。
        $authorization = self::requireHttpsUrl($decoded, 'authorization_endpoint');
        $token = self::requireHttpsUrl($decoded, 'token_endpoint');
        $jwks = self::requireHttpsUrl($decoded, 'jwks_uri');

        // client 認証方式。★**この項目は OIDC Discovery で optional であり、
        //   欠落時の既定は client_secret_basic である** (仕様)。
        //   欠落を「対応方式なし」として拒否すると**仕様準拠の IdP を拒否する**。
        //   明示されている場合だけ basic → post の優先で選び、
        //   明示されていて **どちらも無い**場合だけ拒否する。
        $methods = self::supportedAuthMethods($decoded);   // 欠落 → [ClientSecretBasic]
        if ($methods === []) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNoSupportedAuthMethod);
        }

        // ★id_token_signing_alg_values_supported は OIDC Discovery の **必須項目**である。
        //   アプリの許可集合との共通部分を取り、空なら拒否する。
        //   B3 は「alg が **アプリの許可集合と IdP の広告集合の両方**に入る」ことを要求する
        //   (広告外の alg で署名されたトークンを通さない)。
        $algorithms = self::supportedSigningAlgorithms($decoded);   // 必須・非空・具体型を検査
        if ($algorithms === []) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNoSupportedSigningAlg);
        }

        return new self($issuer, $authorization, $token, $jwks, $methods, $algorithms);
    }
}
```

### キャッシュの保存スキーマ（不変条件 11）

| キー | 値 |
|---|---|
| `enterprise-sso:metadata:{issuer の sha256}` | `array{issuer: string, authorization_endpoint: string, token_endpoint: string, jwks_uri: string, auth_methods: non-empty-list<string>, id_token_signing_algorithms: non-empty-list<string>}`（**素の配列とスカラーのみ**）★**広告された署名方式も保存する** — 保存しないとキャッシュ hit の後に B3 の「アプリの許可集合 ∩ IdP の広告集合」が成立しない |
| `enterprise-sso:jwks:{issuer の sha256}` | `array<int, array<string, string>>`（JWK の必要要素のみ。**素の配列**） |
| `enterprise-sso:jwks-refetched-at:{接続 id}` | `int`（UNIX 時刻。**スカラー**） |

読み戻しは **DTO へ明示的に組み立て直して検査**し、失敗したら `forget` して miss 扱いにする
（**破損 / 空配列 / 未知の値**のいずれでも `forget` する）。
`CachePayloadPlainDataGateTest` の目録へ本サービスを登録する（F2）。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`requireString` / `requireHttpsUrl` が存在と型を確定させる）
- [x] DTOを返している（配列返却なし）
- [x] Genericsの型パラメータが正しい（`non-empty-list<TokenEndpointAuthMethod>`）

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php`
  - issuer 不一致を拒否する
  - **endpoint が別 origin でも受理する**（実在の IdP を拒否しないことの回帰）
  - endpoint が http / userinfo つき / fragment つきなら拒否する。**query つきは受理する**
  - **パス付きの issuer で well-known の URL が正しく組み立つ**
  - **末尾スラッシュの有無で issuer が別物として扱われる**（正規化していないことの回帰）
  - `token_endpoint_auth_methods_supported` の **欠落 / 空配列 / 未知値だけ /
    basic と post の混在**を**別々に**検査する（欠落は basic として受理する）
  - `id_token_signing_alg_values_supported` の **欠落 / 空配列 / アプリの許可集合と交わらない**を拒否する
  - 3xx 応答を**成功として扱わない**
  - サイズ上限超過を拒否する
  - JSON でない / オブジェクトでない応答を拒否する
  - 対応する client 認証方式が無い IdP を拒否する
  - **キャッシュの破損値・空配列・未知の値を読み戻したら `forget` して取り直す**
    （`auth_methods` と `id_token_signing_algorithms` の両方について）
  - **キャッシュ hit の後でも B3 の「広告集合との共通部分」が成立する**
    （広告された署名方式が保存されていることの回帰）
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcDiscoveryPinnedPathTest.php` —
      **実装が `PinnedHttpClient` を通る**（ssrf-pin のテスト seam で観測。F4）
- [ ] 新規 `tests/Unit/ValueObjects/OidcIssuerUrlTest.php` —
      http / userinfo つき / query つき / fragment つき / 相対 URL を拒否し、
      **末尾スラッシュを正規化しない**
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- pin 済み経路に一本化することで、`PinnedHttpClient` の障害が discovery の単一障害点になる。
  これは**意図した集約**であり、迂回路を作らないことが不変条件 I3 の実体である。

---

## B2: トークン交換（body 付き pinned 要求）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/OidcTokenExchanger.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/OidcTokenResponse.php`
- 新規: `app/Support/EnterpriseSso/BasicCredentials.php`（RFC 6749 §2.3.1 準拠の符号化）

### 変更後コード（要点）

```php
/**
 * 認可コードとトークンの交換。
 *
 * ★**本サービスは kent013/laravel-ssrf-pin ^0.4 の「要求 body を運べる pin 済み取得」を必要とする**。
 *   v0.2 系では実装そのものが成立しない (正典が明記)。前段 TODO ssrf-pin-v04-upgrade が先行する。
 *
 * ## 秘密を漏らさないための 4 点
 *
 *  1. **vendor の例外を外へ連結しない** — previous に載せると、要求 body (認可コード /
 *     client secret / code_verifier) が例外の連鎖からログへ展開されうる。
 *     境界で**固定の理由コードの例外**へ変換する。
 *     ★`EnterpriseSsoAttemptRejectedException` は **`previous` を受け取れない構築子**を持つ
 *     (理由の enum だけを受ける)。型で連鎖が起きない (F1 の G3 と対)
 *  2. 平文を受ける引数に **`#[SensitiveParameter]`** を付ける (スタックトレースに出さない)
 *  3. client secret は **ConnectionSecret::revealForTokenExchange()** で
 *     ここでだけ平文化する (呼び出し元は gate が exact-fit で pin する)
 *  4. client 認証は **client_secret_basic を優先** (body 漏洩面が小さい)。
 *     IdP が対応しない場合だけ client_secret_post へ落とす
 */
final readonly class OidcTokenExchanger
{
    public function __construct(private PinnedHttpClient $pinned) {}

    public function exchange(
        OrganizationOidcConnection $connection,
        OidcProviderMetadata $metadata,
        #[SensitiveParameter] string $code,
        #[SensitiveParameter] string $codeVerifier,
    ): OidcTokenResponse {
        $method = $this->chooseAuthMethod($metadata);

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => route('enterprise-sso.callback'),
            'client_id' => $connection->client_id,
            'code_verifier' => $codeVerifier,            // ★PKCE の往復の片端
        ];

        $headers = [];
        if ($method === TokenEndpointAuthMethod::ClientSecretBasic) {
            // ★RFC 6749 §2.3.1: client_id と client_secret を
            //   **application/x-www-form-urlencoded の規則で符号化してから** `:` で連結し base64 する。
            //   自前の rawurlencode 連結にしない (空白・`+`・`:`・非 ASCII で壊れる)。
            $headers['Authorization'] = BasicCredentials::header(
                $connection->client_id,
                $connection->clientSecret()->revealForTokenExchange(),
            );
        } else {
            $form['client_secret'] = $connection->clientSecret()->revealForTokenExchange();
        }

        $result = $this->pinned->fetch(
            PinnedRequest::post($metadata->tokenEndpoint, $form, $headers)
                ->withoutRedirects()
                ->withMaxBodyBytes(Config::integer('enterprise-sso.token.max_body_bytes')),
            // ★期限の渡し方は **^0.4 の確定 API に合わせて実装時に確定する**
            //   (接続と全体を別々に受けない API なら token.connect_timeout_seconds を設定ごと削除する)。
            //   存在しない API を確定形として残さない。
            $this->deadlineFromConfig(),
        );

        // ★fetch() は PinnedResponse|PinnedFailure を返す。**失敗は値で返る**ので
        //   catch では捕まらない。明示的に分岐して固定の理由コードへ変換する。
        if ($result instanceof PinnedFailure) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenExchangeFailed);
        }

        // DTO を組み立てる **前に** 応答の形を確定させる。
        //   2xx / body 上限 / JSON オブジェクト / 必須の id_token
        return OidcTokenResponse::fromPinnedResponse($result);
    }
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`PinnedFailure` と `PinnedResponse` を型で分岐する）
- [x] DTOを返している
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcTokenExchangerTest.php`
  - `code_verifier` が要求に載る（PKCE の往復の片端）
  - IdP が `client_secret_basic` に対応していれば **body に client_secret を載せない**
  - 対応方式が無ければ拒否する
  - **`PinnedFailure` が値で返ったときに固定の理由コードの例外になる**（catch では捕まらない経路）
  - 3xx を成功として扱わない
  - body 上限超過 / JSON でない / オブジェクトでない / **`id_token` が無い**応答を拒否する
  - ★漏洩の検査は**2 つに分ける**（実送信には資格情報が必ず在るので、混ぜると成立しない）:
    - **実送信要求**（transport の seam が捕らえるもの）: Basic なら Authorization ヘッダに、
      post なら body に、**資格情報が正しく含まれる**
    - **ログ / 監査 / 例外 / 診断用の HTTP 履歴**: client secret / 認可コード / トークンが
      **平文・base64・form-urlencoded のいずれの形でも残らない**
      （G3 の実挙動側の裏取り。**主たる証明はここにある**）
- [ ] 新規 `tests/Unit/Support/BasicCredentialsTest.php` —
      空白・`+`・`:`・非 ASCII を含む資格情報が RFC 6749 §2.3.1 のとおり符号化される
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### 着手条件
- 前段①（`ssrf-pin-v04-upgrade`）が完了していること
- ★**^0.4 の確定 API へ本設計を追随済みであること**（`PinnedRequest` の body の載せ方・
  期限の渡し方・`PinnedFailure` の形）。追随の結果
  `token.connect_timeout_seconds` が使えないと分かったら**設定ごと削除する**
  （「参照されない設定を作らない」を守る）

### リスク
- 前段の版上げが未了だと本施策は着手できない（TODO の依存に明記する）。

---

## B3: ID トークンの検証（`firebase/php-jwt`）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseIdTokenVerifier.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/VerifiedIdTokenClaims.php`
- 変更: `composer.json` / **`composer.lock`**（`firebase/php-jwt` を**直接依存として明示**。
  既に v7.0.5 が解決済みなので解決版は変わらないが、`composer.json` を触る以上
  `composer.lock` も同じ変更でコミットする = AGENTS.md の worktree 規則）

### 拒否条件（deny-by-default。1 つでも該当したらその試行を拒否）

| 層 | 拒否する条件 |
|---|---|
| JWT の形 | malformed（3 セグメントでない / base64url でない / ヘッダが JSON でない） |
| ヘッダ | `alg` が `OidcSigningAlgorithm` の case でない（`none` / HMAC は enum に無い）／**`alg` が IdP の広告集合（`id_token_signing_alg_values_supported`）に無い**（= アプリの許可集合と広告集合の**両方**に入ることを要求する）／`kid` の欠落 |
| JWKS | `kid` に一致する鍵が無い（→ **再取得を 1 回だけ**）／**`kid` の重複**／`kty` が `alg` と不整合／EC の `crv` が `alg` と不整合／**`use` が存在するのに** `sig` でない／**`key_ops` が存在するのに** `verify` を含まない（★`use` と `key_ops` はどちらも optional。存在するときだけ検査する。欠落を理由に有効な鍵を拒否しない） |
| 署名 | 検証に失敗した |
| claim の型 | `iss` / `sub` / `nonce` が文字列でない／`aud` が文字列でも文字列配列でもない／`exp` / `iat` / `nbf` が整数でない |
| claim の値 | `iss` が登録済み issuer と不一致／`sub` が空・長さ超過／**`exp` の欠落**／**`iat` の欠落**／`exp` 超過／`iat` が未来／`nbf` が未来（いずれも `leeway_seconds` の範囲で）／`nonce` の指紋が試行と不一致 |
| audience（★論理和で書かず 3 条に分ける） | (1) **`aud` は必ず client_id を含む** / (2) **`aud` が複数なら `azp` は必須** / (3) **`azp` が存在するなら文字列で client_id と一致** |

> **`firebase/php-jwt` の戻り値も信頼済みの型と見なさない。**
> `JWT::decode()` は `stdClass` を返す。各 claim について**存在と具体型を再検査してから**
> `VerifiedIdTokenClaims` を組み立てる（`mixed` を DTO の中へ押し込めない）。

### 鍵ローテーションの追従（[Warning] への回答）

```php
/**
 * 未知の kid での鍵の再取得。
 *
 *  - **接続 id 単位のロック**を取り、同時要求でも再取得が 1 回になる
 *  - 最終再取得時刻を **スカラー**でキャッシュに持ち、
 *    jwks_refetch_min_interval_seconds の内側では再取得しない (増幅を防ぐ)
 *  - **ロック基盤の障害時はその試行を拒否する** (再取得を無制限に許さない)
 *  - 再取得は **1 回だけ**。それでも見つからなければ拒否する
 */
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`VerifiedIdTokenClaims`）
- [x] null安全（claim ごとに存在と型を検査してから構築）
- [x] DTOを返している
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseIdTokenVerifierTest.php` —
      **上表の拒否条件を 1 行ずつ dataset で負例にする**（malformed / `alg: none` / HMAC 署名 /
      `kid` 欠落 / **`kid` 重複** / `kty` 不整合 / `crv` 不整合 / `use` が `enc` / `key_ops` に `verify` なし /
      署名不一致 / `aud` の型不正 / `exp` 欠落 / `iat` 欠落 / `iss` 不一致 / `aud` に自分がいない /
      **複数 audience で `azp` が無い** / `azp` 不一致 / `sub` 欠落・空・長さ超過 / `exp` 超過 / `nonce` 不一致）
- [ ] **広告外の `alg`** で署名されたトークンを拒否する（アプリの許可集合には在るが IdP が広告していない場合）
- [ ] **正のコントロール**: `use` と `key_ops` を**持たない**有効な鍵が受理される
      （optional な項目の欠落で有効な IdP を拒否しないことの回帰）
- [ ] 鍵ローテーション: 未知 `kid` で**再取得が 1 回だけ**起き、最小間隔の内側では再取得しない
- [ ] **同時要求でも再取得が 1 回**（接続 id 単位のロック）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 拒否条件が多いので「正常系が通らない」障害の切り分けが難しくなる。
  理由コードを条件ごとに分けて**ログには理由コードだけ**を出す（利用者への応答は一様）。

---

## B4: ログイン試行の保管（原子的 consume + ブラウザ結合）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/ConsumedLoginAttempt.php` / `AttemptConsumeResult.php`
- 新規: `app/Console/Commands/EnterpriseSso/PruneLoginAttempts.php`
- 変更: `routes/console.php`（日次の掃除を登録。準拠実装 `idempotency:prune`）

### 変更後コード（**トランザクションの巻き戻しバグを直した形**）

```php
/**
 * ログイン試行の保管。
 *
 * ## 不変条件 (これが正本。「1 文で書く」は手段ではない)
 *
 *   **同じ試行の使用権を、ちょうど 1 つの要求だけが得る。**
 *   **かつ、その試行を開始したブラウザだけが使える。**
 *
 * ## 契約する DB は pgsql である
 *
 * phpunit.xml が DB_CONNECTION=pgsql を force しており、**テストも本番も pgsql** である
 * (SQLite は使わない)。したがって `SELECT … FOR UPDATE` の排他契約は本番と同じである。
 * ★「ドライバに依存しない」とは書かない — SQLite の FOR UPDATE は同じ契約を持たない。
 *
 * ## なぜセッションに置かないか
 *
 * 同一セッションへの並行要求は route 側で `->block()` を書かない限り直列化が
 * 保証されず、「普通の get() + forget() を書いても契約を満たしたと誤認できる」形になる。
 *
 * ## なぜブラウザ結合が要るか (login CSRF)
 *
 * state の役割は「推測不能であること」だけではない。**その認可要求を開始した
 * ユーザーエージェントに結び付いていること**が要る。グローバルな表だけを根拠にすると、
 * 攻撃者が自分のブラウザで開始し自分の IdP アカウントで認可した callback URL を
 * 被害者に開かせることで、**被害者のブラウザが攻撃者のアカウントへログインする**。
 *
 * ## ブラウザ結合の秘密の一生
 *
 *  - 開始時に **CSPRNG で 32 バイト**生成する
 *  - セッションのキーは **state の指紋ごとに分ける** (複数タブが共存できる)
 *  - callback で取り出して照合する。**取り出したキーの値が非空の文字列でなければ、
 *    外向き取得を始める前に一様拒否する**
 *  - 破棄の規則:
 *    - **行が不可逆に consume された** (成功 / 期限切れで削除された) 失敗と成功では、
 *      **対応するセッションの値も削除する** (再開できない試行の秘密を残さない =
 *      開始と失敗を繰り返してセッションが太らない)
 *    - **結合の不一致のように行を保持する**場合は**秘密も保持する**
 *      (攻撃者が被害者の結合を消せる形にしない)
 *
 * ## 保存の形
 *
 * | 項目 | 形 |
 * |---|---|
 * | state | 指紋だけ (原文を保存しない) |
 * | nonce | 指紋だけ |
 * | ブラウザ結合 | セッションへ置いた秘密の指紋 (session ID は保存しない) |
 * | PKCE の検証子 | 交換でそのまま送るので原文が要る → 暗号化して保存 |
 *
 * 指紋は **用途別のラベルで導出する** ({@see AttemptFingerprint})。
 *
 * ## 保証しないもの
 *
 * - セッション cookie ごと奪われた場合のブラウザ結合は破れる (結合はセッションの秘密に依存する)
 * - 期限切れ行の掃除は日次の実行点とオンアクセスの二段であり、**即時削除ではない**
 */
final readonly class EnterpriseLoginAttemptStore
{
    /**
     * 使用権を取得する。取得できた要求だけが値を読み出せる。
     *
     * ★**トランザクションの中で例外を投げない**。投げると期限切れ行の削除も
     *   巻き戻り、「オンアクセスで掃除する」が成立しない。
     * ★**本メソッドは例外を投げず、分類結果をそのまま返す**。
     *   呼び出し側 ({@see EnterpriseCallbackAuthenticator}) が
     *   「行が消えた失敗か / 行を保持した失敗か」で**セッションの秘密の始末を分け**、
     *   その後で**外向きの一様な例外へ変換する**。
     *   (HTTP の応答が一様であることと、内部で理由を区別することは両立する。)
     */
    public function consume(string $state, #[SensitiveParameter] string $browserBindingSecret): AttemptConsumeResult
    {
        return DB::transaction(function () use ($state, $browserBindingSecret): AttemptConsumeResult {
            $row = EnterpriseSsoLoginAttempt::query()
                ->where('state_fingerprint', AttemptFingerprint::of(FingerprintPurpose::State, $state))
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return AttemptConsumeResult::notFound();
            }

            if ($row->expires_at->isPast()) {
                $row->delete();                       // ★この削除は commit される
                return AttemptConsumeResult::expired();
            }

            $expected = AttemptFingerprint::of(FingerprintPurpose::BrowserBinding, $browserBindingSecret);
            if (! hash_equals($row->browser_binding_fingerprint, $expected)) {
                // ★行を消さない (攻撃者が被害者の試行を消せる形にしない)。
                return AttemptConsumeResult::bindingMismatch();
            }

            // ★`delete()` が真を返さないのは **DB の障害**であって業務上の拒否ではない。
            //   一様な拒否へ握り潰すと「排他が壊れた」という重大な事実が隠れる。
            //   例外を投げてトランザクションを巻き戻す (行もセッションの秘密も残る)。
            if ($row->delete() !== true) {
                throw new EnterpriseSsoAttemptStoreFailure('attempt row delete did not affect a row');
            }

            // 行をそのまま外へ出さない。具体型・期限・復号結果を検査して DTO へ畳む。
            return AttemptConsumeResult::consumed(ConsumedLoginAttempt::fromModel($row));
        });
    }
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`first()` の null を早期に処理。アーリーリターン）
- [x] DTOを返している（`ConsumedLoginAttempt`。Eloquent モデルを外へ出さない）
- [x] Genericsの型パラメータが正しい

### セッションの秘密の始末は誰がやるか（Round 3 の [Critical] への回答）

`consume()` が**返す**分類は 4 通り（成功 / 不在 / 期限切れ / 結合の不一致）である。
これらは**業務上の判定**であり、例外ではない。
一方、`delete()` が行に当たらないような **DB の障害は例外**（`EnterpriseSsoAttemptStoreFailure`）として
投げ、トランザクションを巻き戻す — **業務上の拒否と基盤の障害を混ぜない**
（混ぜると「排他が壊れた」という事実が一様な拒否に隠れる）。
このうち**行が不可逆に消えたのは「成功」と「期限切れ」**で、
**行が残っているのは「不在」（そもそも無い）と「結合の不一致」**である。

`EnterpriseCallbackAuthenticator`（application service）が調停する:

| 分類 | 行 | セッションの秘密 | 外向きの応答 |
|---|---|---|---|
| 成功 | 消えた | **消す** | ログイン確定へ進む |
| 期限切れ | 消えた | **消す** | 一様な失敗 |
| 不在 | 無い | **消す**（再開できる試行が無い） | 一様な失敗 |
| 結合の不一致 | **残る** | **残す**（攻撃者が被害者の結合を消せる形にしない） | 一様な失敗 |
| （例外）DB の障害 | **残る**（巻き戻る） | **残す** | 500（一様な失敗に畳まない） |

外向きの応答は 4 通りとも**同一**である。区別は内部にだけ存在する。

### 並行テストの土台（Round 2 の [Critical] への回答）

グローバル `RefreshDatabase` の下では、テストのトランザクションの中で作ったフィクスチャは
**別接続から見えない**。したがって「2 接続を使う」だけでは並行テストは成立しない。

**別 TODO `process-concurrency-harness-adoption` のハーネスに乗る**（前段依存の 2 本目）。
本設計が使うのはその 6 要素のうち:

- **transaction 外フィクスチャ**（子から見えるように独立接続で作り、末尾で明示的に片付ける）
- **ready / go ファイルによる同期点**と**締切つきの待ち**
- **子のキャッシュ store を配列固定**（アプリ側のロックを共有させず、**DB 層だけで守れる**ことを確かめる）

正典の規定どおり **実プロセス版は 1 本に絞る**（重いため）。細かい分岐は同一プロセスのテストへ回す。
B4・C1・C2 の並行テストは**同じハーネスを共有**する。

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseLoginAttemptStoreTest.php`
  - **実プロセスの並行 1 本**（上記ハーネス）: 1 本目が行ロックを保持している間に 2 本目を開始し、
    **片方だけが成功する**（`--parallel` を同時アクセスの代用にしない）
  - **別のブラウザで callback URL を開くと失敗する**（login CSRF。結合不一致で拒否）
  - 結合不一致では**行が消えない**（他人の試行を消せない）
  - **期限切れの行は拒否と同時に消える**（トランザクションが巻き戻らないことの回帰）
  - 用途別の指紋が相互に使い回せない（`state` の指紋を結合の指紋として使えない）
  - 複数タブで同時に開始しても互いの結合の秘密を壊さない
  - **結合の秘密がセッションに無い / 非文字列のとき、外向き取得を始めずに拒否する**
  - **DB の障害（削除が行に当たらない）が一様な拒否に畳まれず例外になる**（負のコントロール）
  - **不可逆に consume された失敗ではセッションの秘密も消える**／
    **結合の不一致では行もセッションの秘密も残る**
- [ ] 新規 `tests/Feature/EnterpriseSso/PruneLoginAttemptsTest.php` —
      期限切れ行だけが消え、**進行中の通常の試行を巻き込まない**
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 行ロックの保持中に外向き HTTP を行うと、ロックが外部の応答時間に引きずられる。
  **consume はトランザクションを閉じてから外向き取得を始める**（C2 の並び順で保証する）。

---

## C1: 利用者の自動作成 (always-JIT)

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseUserProvisioner.php`

### 変更後コード（要点）

```php
/**
 * 初回ログインでの利用者の自動作成 (always-JIT)。
 *
 * ★**メールアドレスで利用者を引かない** (正典 v1 / I1)。
 *   引き当ての鍵は (接続 id, subject の指紋) だけである。
 *   申告メールは EnterpriseIdentity に暗号化して持つが、**引き当てには使わない**。
 *
 * ## 作る利用者の姿 (A3 と一体)
 *
 *  - `email` = **null** (企業 SSO でしか入れない利用者は使えるメールを持たない。
 *    仮のメール文字列を作らない — 偽のメールは衝突と誤送の温床である)
 *  - `email_verified_at` = **now()** (「IdP が本人確認した。確認すべきメールが無い」の意味。
 *    既存の verified middleware の意味論を変えずに通す)
 *  - `password` = **null** (パスワードは持たない。初回設定は既存の settings.password.store が担う)
 *  - `name` = ID トークンの `name` claim があればそれ、無ければ表示用の既定値
 *  - 所属は **接続が属する組織のみ**、役割は **OrganizationRole::Member** (最小)。
 *    付与のすべてで **組織の team id を明示する** (AGENTS.md 不変条件 5)
 *
 * ## 並行初回ログインの競合
 *
 * ★**競合制御は C2 が張る接続の行ロックが唯一の担い手である**。
 *   同一接続の callback は行ロックで直列化されるので、事前検索 → 作成の間に
 *   別の要求が割り込むことがない。
 *  - 利用者の作成・身元の作成・組織所属の作成は
 *    **C2 が開いた 1 トランザクション** (接続の行ロックを含む) の中で行う
 *  - 身元表の enterprise_identities_connection_subject_unique は
 *    **最後の防波堤として残す**が、**捕まえない** (上のコード参照)
 *  - 失敗すればトランザクション全体が巻き戻るので**孤児は残らない**
 */
/**
 * ★本メソッドは **C2 が張った接続の行ロックの中**で呼ばれる (線形化点は C2 が持つ)。
 *   ここでトランザクションを開き直さない。
 */
public function resolve(OrganizationOidcConnection $connection, VerifiedIdTokenClaims $claims): User
{
    // ★relation 起点で引く。クラス起点 (EnterpriseIdentity::query()->where('connection_id', …)) で
    //   書かない — 組織スコープの出所を型と relation で固定する (AGENTS.md 不変条件 3)。
    //   引き当ての鍵は subject の値そのもの (列の照合が COLLATE "C" なので byte 一致)。
    $existing = $connection->identities()->where('subject', $claims->subject)->first();
    if ($existing !== null) {
        return $existing->user;   // アーリーリターン
    }

    // ★一意制約違反を**捕まえない**。理由は 2 つ:
    //   (1) C2 が接続の行を lockForUpdate() しているので、同一接続の callback は既に直列化されており、
    //       正規経路でこの競合は起きない (競合制御は行ロックが唯一の担い手である)
    //   (2) pgsql は一度 SQL エラーが出るとトランザクション全体が aborted になり、
    //       **同じトランザクションの中では再検索できない** (savepoint まで戻さない限り次の SELECT も失敗する)。
    //       つまり「catch して引き当て直す」は**そもそも動かない**。
    //   一意制約は**最後の防波堤として残す**が、捕まえない。予期しない違反はそのまま伝播させる
    //   (握り潰すと、直列化が壊れたという重大な事実が「競合」として隠れる)。
    return $this->createUserWithIdentityAndMembership($connection, $claims);
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている / null安全（`findIdentity` の null を早期に処理）
- [x] DTOを返している（`User` は認証境界の値。画面へは DTO 経由で出す）
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseUserProvisionerTest.php`
  - 初回で利用者・身元・所属が 1 件ずつできる
  - 作られた利用者は `email = null` / `email_verified_at != null` / `password = null`
  - 役割が `Member` で、**別組織の役割が参照されない**（team id を明示していることの実挙動）
  - **同じ申告メールを持つ別 subject が別の利用者になる**（メールで引かないことの裏取り）
  - **大小文字違いの subject が別の利用者になる**
  - **並行初回ログインでも 1 利用者・1 身元・1 所属だけが成立する**（並行ハーネス。
    証明の主体は**接続の行ロック**である）
  - 失敗した側に**孤児の利用者が残らない**
  - **一意制約違反を握り潰さない**（意図的に違反を起こすと例外が伝播する = 負のコントロール）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- always-JIT は「接続が有効な組織の IdP が subject を出せば誰でも入れる」ことを意味する。
  絞りは**接続の有効・無効**（D1）だけである。これは正典の形であり、追加の絞りを足さない。

---

## C2: 開始と戻り口・controller・route 3 本

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseCallbackAuthenticator.php`
- 新規: `app/Http/Controllers/Auth/EnterpriseSsoLoginController.php`
- 新規: `app/Http/Requests/Auth/EnterpriseSsoCallbackRequest.php`
- 変更: `routes/web.php`

### 波及変更
- **TypeScript型定義**: ログイン画面に企業ログインの導線（`resources/js/pages/Auth/Login.svelte` の Props）
- API Resource/DTO: なし（Inertia のリダイレクトのみ。**JSON API は新設しない**）
- **テストファイル**: F2 の 6 目録

### 開始側（[Critical] への回答）

```php
/**
 * 開始。**行を作ってからリダイレクトする** (逆順だと戻ってきた state が存在しない)。
 *
 *  1. 接続を slug で解決し、**Active であること**を確かめる
 *  2. CSPRNG で state / nonce / PKCE の検証子 / ブラウザ結合の秘密を各 32 バイト生成し、
 *     base64url で符号化する
 *  3. ブラウザ結合の秘密を**セッションへ置く** (キーは state の指紋ごとに分ける)
 *  4. 試行の行を作る (state / nonce / 結合の指紋 + 暗号化した検証子 + 期限)
 *  5. 認可要求の URL を組み立ててリダイレクトする
 *
 * 認可要求の必須引数:
 *   response_type=code / scope=openid / client_id / redirect_uri /
 *   state / nonce / code_challenge / code_challenge_method=S256
 */
```

### 戻り口（AG-200 の要）

```php
/**
 * 企業 SSO の戻り口。
 *
 * ★**待機ログインを作らない** (家系の裁定 AG-200)。確認できた時点で Auth::login() で
 *   ログインを確定させ、画面へ送る。2 要素認証の入力画面へ転送する分岐を**持たない**。
 *   これは tests/Architecture/SsoTwoFactorInterpositionGateTest が
 *   企業・ソーシャルの両 controller に対して静的に裏当てし、
 *   主たる証明は tests/Feature/Auth/EnterpriseSsoLoginTest の実挙動側にある。
 *
 * ## 順序 (理由つき)
 *
 *  1. **入力の検査** (FormRequest) — スカラー型・長さ上限・code と error の排他。
 *     ★不正な入力では**外向き取得を一切開始しない**
 *  2. IdP の error 応答は一様な失敗として扱う
 *  3. セッションから**結合の秘密**を取り出す (state の指紋から試行ごとのキーを導く)。
 *     **非空の文字列でなければ、外向き取得を始めずに一様拒否する**
 *  4. **consume** (試行の行のロック。トランザクションを**閉じる**) —
 *     ロックの保持中に外向き HTTP を行うと、ロックが外部の応答時間に引きずられる。
 *     ★`consume()` は**投げずに分類を返す**ので、**本サービスが**
 *     「行が消えた失敗ならセッションの秘密も消す / 結合の不一致なら残す」を決め、
 *     そのうえで**外向きの一様な例外**へ変換する (B4 の表)
 *  5. 外向き取得 (discovery → token 交換 → JWKS) と ID トークンの検証。
 *     ★この間はどのロックも持たない
 *  6. **線形化の区間**: 1 つのトランザクションで
 *       接続の行を `lockForUpdate()` → **Active を確認** → **JIT** → commit
 *  7. Auth::login(remember: false) → session()->regenerate() → 結合の秘密を破棄
 *
 * ## 無効化 (disable) との線形化 (Round 2 の [Critical] への回答)
 *
 * 「Active を 2 回読む」だけでは競合を閉じられない (最終確認の直後に disable が commit され、
 * その後ログインが確定する窓が残る)。また JIT を確認より前に置くと、
 * **拒否されたのに利用者・身元・所属だけが残る**。
 *
 * ★**線形化点を接続の行ロックに定める**。上の 6 が線形化の区間であり、
 *   {@see OidcConnectionTransitionService} の無効化も**同じ行を `lockForUpdate()` する**。
 *   したがって両者は直列化され、次の 2 つが成り立つ:
 *     - **無効化が先に線形化したら、JIT もログインも起きない** (Active の確認で落ち、
 *       同一トランザクションなので副作用が巻き戻る)
 *     - **callback が先なら、無効化はその後に成立する** (次回から入れない)
 *   commit の後・Auth::login の前に disable が入る窓は残るが、それは
 *   「無効化より前に線形化したログイン」であり、**既存セッションの即時失効はスコープ外**という
 *   本設計の主張と整合する。
 *
 * ## 身元の名前空間を壊さない (Round 3 の [Critical] への回答)
 *
 * OIDC の身元は実質 **(issuer, subject)** であり、pairwise subject では
 * **client_id も名前空間を変えうる**。したがって、同じ接続の issuer や client_id を
 * 別の IdP のものへ変えた後に偶然同じ subject が返ると、**以前の利用者へ誤ってログインさせる**。
 * これを防ぐのは D1 の更新規則である —
 * **身元が 1 件でもある接続では issuer と client_id を変更できない** (新しい接続を作る)。
 * 本サービスは「接続 id で身元を引く」形のままでよい (名前空間の不変性を D1 が保証する)。
 *
 * ★`remember: false` である。remember cookie を許すと、接続を無効化した後も
 *   cookie から新しいセッションを開始できてしまい、
 *   「次回ログインができなくなる」という効果の主張と整合しない。
 */
public function callback(EnterpriseSsoCallbackRequest $request, EnterpriseCallbackAuthenticator $authenticator): RedirectResponse
{
    $user = $authenticator->authenticate($request->validatedInput());   // 失敗はすべて一様に例外

    Auth::login($user, remember: false);
    $request->session()->regenerate();

    return redirect()->intended(route('dashboard'));
}
```

### route

```php
/*
|--------------------------------------------------------------------------
| エンタープライズ OIDC SSO (組織 OIDC 接続 + always-JIT)
|--------------------------------------------------------------------------
| 開始導線は GET の anchor リンク (form POST にしない。CSP form-action が
| リダイレクト先 IdP に適用されるため。social.redirect と同じ理由)。
|
| ★**この経路にアプリ側の 2 要素認証を挟まない** (家系の裁定 AG-200)。
|   確認できた時点でログインを確定させる。組織義務づけの強制は別関門
|   (RequireTwoFactorForEnforcedOrganizations) が**ログイン確定後**に
|   アカウント全体のゲートとして担い、転送先は 2 要素の**設定ページ**である。
*/
Route::get('/enterprise/login', [EnterpriseSsoLoginController::class, 'show'])
    ->name('enterprise-sso.login');

// ★GET だが **DB に試行の行を作る変更操作**である (OAuth の開始)。
//   CSRF トークンの代わりに state・ブラウザ結合・流量制限・no-store が守る (F2 で分類)。
Route::get('/enterprise/{connectionSlug}/redirect', [EnterpriseSsoLoginController::class, 'redirect'])
    ->middleware(['throttle:enterprise-sso-start', 'no-store'])
    ->name('enterprise-sso.redirect');

// 戻り口。**未認証で外部へ HTTP を発射する経路**である (token 交換 + JWKS)。
Route::get('/enterprise/callback', [EnterpriseSsoLoginController::class, 'callback'])
    ->middleware(['throttle:enterprise-sso-callback', 'no-store'])
    ->name('enterprise-sso.callback');
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`Assert::isInstanceOf` で `User` を確定させる。既存 `SocialAuthController` と同形）
- [x] DTOを返している（`response()->json()` を書かない。FormRequest は入力 DTO へ変換する）
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/Auth/EnterpriseSsoLoginTest.php`
  - **2 要素認証が有効な利用者も、企業ログインでそのままログインが確定する**（AG-200 の主証明①）
  - **組織義務づけの下でも、企業ログイン後に 2 要素の設定ページへ到達できる**（AG-200 の主証明②）
  - 開始で **行が作られてからリダイレクトする**（順序）
  - 認可要求に `scope=openid` / `code_challenge_method=S256` / `state` / `nonce` が載る
  - **不正な入力（配列・長さ超過・`code` と `error` の同時）では外向き取得を開始しない**
  - IdP の `error` 応答が一様な失敗になる
  - **開始後に接続を無効化するとログインできない**
  - **並行**（並行ハーネス）: 無効化と callback を同時に走らせ、
    **無効化が先なら JIT もログインも起きない**（利用者・身元・所属が残らない）／
    **callback が先なら無効化はその後に成立する**（次回から入れない）
  - **`remember` cookie を発行しない**
  - `session()->regenerate()` が確定後に走る（セッション固定化）
  - 失敗の応答が**一様**で、接続や利用者の存在を読み取れない
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseOidcRouteRoundTripTest.php` — 偽の IdP（F4）で route の往復
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **禁止事項 7**（操作系 POST で `redirect()->intended()`）に見えるが、本経路は**ログイン直後フロー**であり
  同項の明示的な適用範囲内である（既存 `SocialAuthController` と同じ形）。

---

## D1: 接続の状態遷移サービス

### 変更箇所
- 新規: `app/Services/EnterpriseSso/OidcConnectionTransitionService.php`

### 変更後コード（要点。[Critical] への回答）

```php
/**
 * 接続の状態遷移。
 *
 * 許す遷移 (これ以外は例外):
 *   Draft            → Verified  (接続先情報の取得に成功した)
 *   Verified         → Active    (運営が有効にした)
 *   Active           → Disabled  (運営が止めた)
 *   Disabled         → Active    (運営が戻した。verified_at が残っている場合のみ)
 *   Verified/Active/Disabled → Draft  (★**client secret を更新した**)
 *
 * ## 更新の規則は 3 段に分かれる (Round 3 の [Critical] への回答)
 *
 * | 変えるもの | 規則 | 理由 |
 * |---|---|---|
 * | **issuer / client_id** | ★**身元が 1 件でもあれば変更禁止**。新しい接続を作らせる。**身元が 0 件なら変更できるが、その場合も必ず `Draft` へ戻し `verified_at` を消す** (未検証の新構成で直ちにログインできる状態を作らない) | OIDC の身元は実質 (issuer, subject) であり、pairwise subject では client_id も名前空間を変えうる。変えた後に偶然同じ subject が返ると**以前の利用者へ誤ってログインさせる** |
 * | **client_secret** | **Draft へ差し戻し + verified_at を消す** (再確認と再有効化が必須) | 名前空間は変わらないが、未検証の構成で直ちにログインできる状態を作らない |
 * | **表示名** | 状態を変えない | 認証に関与しない |
 *
 *  - 更新と状態変更は **同一トランザクション**で行う (片方だけが残る窓を作らない)
 *  - **身元が 1 件でもある接続は物理削除できない** (削除すると身元だけが消え、
 *    利用者が残ってアカウントが分裂する。運用は無効化で行う)
 *
 * ## 接続を変える操作はすべて接続の行をロックする (C2 との線形化)
 *
 * ★対象は **無効化だけではない**。`disable` / `activate` / `update` / `destroy` の**すべて**が
 * **接続の行を `lockForUpdate()` した同一トランザクション**で、
 * 「身元の有無の確認 → 検査 → 変更」を行う。
 * C2 の callback も同じ行をロックして「Active の確認 → JIT」を行うので、両者は直列化される。
 *
 * ★ロックしないと次の競合が起きる:
 *   (1) 管理操作が「身元 0 件」を確認 → (2) callback が行をロックして JIT →
 *   (3) 管理操作が issuer を更新 / 物理削除
 *   = **身元があるのに名前空間が変わる / 身元だけが消える**。
 *
 * ★**ロックの取得順を統一する** (接続の行が唯一のロック対象。他の行を先に取らない)。
 * 保証されるのは次の 2 つである:
 *   - **callback が先なら**、更新・削除は「身元あり」として拒否される
 *   - **更新・削除が先なら**、callback は `Draft` 化 (または接続の不在) により JIT しない
 *
 * ## 取得の失敗で接続を殺さない
 *
 * IdP の 5xx・鍵ローテーションの途中・DNS の一時障害を理由に**自動で無効化しない**
 * (可用性の後退になる)。失敗はすべて「そのログイン試行だけを fail-closed で拒否する」に留め、
 * 接続の状態を変えるのは**本サービスを通した運営操作だけ**である。
 */
```

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcConnectionTransitionServiceTest.php`
  - 定義外の遷移が例外になる
  - **身元がある接続の issuer / client_id の変更が拒否される**
  - **拒否された後も、旧接続で既存の利用者へログインできる**
  - **身元が 0 件の接続なら issuer / client_id を変更できる**（正のコントロール）
  - **client secret の更新は Draft へ戻り `verified_at` が消える**
  - **表示名だけの更新では状態が変わらない**
  - **新しい接続で同じ subject が来ても、旧接続の利用者へは結合されない**
  - **身元がある接続の物理削除が拒否される**／**身元が 0 件なら削除できる**
  - **身元 0 件で issuer / client_id を変更すると `Draft` へ戻り `verified_at` が消える**
  - **並行**（並行ハーネス）: callback と「更新 / 削除」を同時に走らせ、
    **callback が先なら更新・削除が身元ありとして拒否される**／
    **更新・削除が先なら callback は JIT しない**
  - **discovery の失敗で接続の状態が変わらない**（可用性の後退がないことの証明）
  - 更新の途中で失敗したとき、更新と状態変更のどちらも残らない（同一トランザクション）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 状態が増えると画面の分岐が増える。4 状態で固定し、追加しない。

---

## D2: 組織側の接続管理 controller・route 7 本・画面

### 変更箇所
- 新規: `app/Http/Controllers/Organizations/OrganizationSsoConnectionController.php`
- 新規: `app/Http/Requests/Organizations/StoreSsoConnectionRequest.php` / `UpdateSsoConnectionRequest.php`
- 新規: `app/DataTransferObjects/Organizations/SsoConnectionSummary.php`
- 新規: `app/Policies/OrganizationOidcConnectionPolicy.php`
- 新規: `resources/js/pages/Organizations/Sso/Index.svelte`
- 新規: `resources/js/components/features/sso/oidc-connection.ts`
- 変更: `routes/web.php`
- 変更: **`bootstrap/app.php`** — `withExceptions()` 内で
  `$exceptions->dontFlash(['client_secret'])` を登録する
  （★本リポジトリに `dontFlash` の使用実績が無いので、**実装点をここに確定させる**。
  登録しないと validation 失敗時に秘密が old input としてセッションへ残る）
  ★**`code` / `state` / `token` のような一般名はグローバルに登録しない** —
  他のフォームの入力復元まで黙って変えてしまう。
  これらは**経路側で閉じる**（企業 SSO の callback とメール昇格の確認は、
  失敗時に `withInput()` を使わない = 入力を一切 flash しない）

### 波及変更
- **TypeScript型定義: あり** — Inertia Props の型と状態の値域の定数
- **API Resource/DTO: あり** — `SsoConnectionSummary`（**秘密を一度も復号しない**）
- **テストファイル: あり** — `ControllerAuthorizationGateTest` / `RecentAuthRouteTest` /
  `ThrottleCoverageInventoryTest` / `NestedRouteDefenseInventory` / `TenantBoundaryOrderingTest` /
  `InertiaRenderPageExistsInvariantTest` / `DocumentTitleCoverageTest` / `ValidationAttributeCoverageTest`

### route（**更新系はすべて再認証必須**）

```php
Route::get('/organizations/{organization:slug}/sso', [OrganizationSsoConnectionController::class, 'index'])
    ->name('organizations.sso.index');

// {oidcConnection} は Organization::oidcConnections() 経由で scopeBindings が解決する。
// 親に属さない id は **binding 段で 404** (認可より前。AGENTS.md 不変条件 2 / 10)。
Route::scopeBindings()->group(function (): void {
    // 登録・更新は**接続の秘密を扱う唯一の前面**である (正典 v1 / I4)。
    Route::post('/organizations/{organization:slug}/sso', [OrganizationSsoConnectionController::class, 'store'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.store');

    Route::patch('/organizations/{organization:slug}/sso/{oidcConnection}', [OrganizationSsoConnectionController::class, 'update'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.update');

    // 確認 (接続先情報を実際に取りに行く)。**外向きの取得を伴う唯一の管理操作**なので
    // 専用の流量制限を持つ (他の管理操作と bucket を共有しない)。
    Route::post('/organizations/{organization:slug}/sso/{oidcConnection}/verify', [OrganizationSsoConnectionController::class, 'verify'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-verify'])
        ->name('organizations.sso.verify');

    Route::post('/organizations/{organization:slug}/sso/{oidcConnection}/activate', [OrganizationSsoConnectionController::class, 'activate'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.activate');

    Route::post('/organizations/{organization:slug}/sso/{oidcConnection}/disable', [OrganizationSsoConnectionController::class, 'disable'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.disable');

    Route::delete('/organizations/{organization:slug}/sso/{oidcConnection}', [OrganizationSsoConnectionController::class, 'destroy'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.destroy');
});
```

### 秘密の扱い（[Critical] への回答）

```php
/**
 * 接続の登録・更新の入力。**接続の秘密を扱ってよい唯一の前面**である (正典 v1 / I4)。
 *
 * ★Laravel は validation の失敗時に入力をセッションへ flash する。
 *   したがって client_secret を **dontFlash へ登録する** (登録しないと
 *   秘密が old input としてセッションに残る)。
 * ★**伏字の見本をそのまま更新値として受け付けない** — 未入力なら据え置きにする
 *   (伏字文字列がそのまま秘密として保存される事故を型と規則で消す)。
 * ★validation の応答・監査ログ・例外・要求の記録にも含めない。
 */
```

### action ごとの認可（**読み取りも認可を通る**）

| action | ability | 非メンバー | メンバー（権限なし） | owner / admin |
|---|---|---|---|---|
| `index` | `viewAny`（`OrganizationOidcConnection::class`, `$organization`） | **404**（binding） | **403** | 200 |
| `store` | `create` | 404 | 403 | 302（back） |
| `update` | `update` | 404 | 403 | 302 |
| `verify` | `update` | 404 | 403 | 302 |
| `activate` | `update` | 404 | 403 | 302 |
| `disable` | `update` | 404 | 403 | 302 |
| `destroy` | `delete` | 404 | 403 | 302（★**身元が 1 件でもあれば拒否**。下記） |

接続の管理は組織のログイン経路そのものを変える操作なので、
**閲覧も含めて `owner` / `admin` に限る**（`OrganizationPolicy::update` と同じ境界）。

### 削除と更新の制限（Round 3 の [Critical] への回答）

| 操作 | 身元が 0 件 | 身元が 1 件以上 |
|---|---|---|
| `destroy` | できる | **拒否**（押下時に「無効化してください」とエラー表示。★ボタンを disabled にしない = 禁止事項 8） |
| `update`（issuer / client_id） | できる（★**`Draft` へ戻る**） | **拒否**（押下時に「新しい接続を作ってください」とエラー表示） |
| `update`（client secret） | できる（`Draft` へ戻る） | できる（`Draft` へ戻る） |
| `update`（表示名） | できる | できる |
| `disable` | できる | **できる（推奨経路）** |

★**7 route のうち状態や認証材料を変える 5 本（`update` / `verify` / `activate` / `disable` / `destroy`）は、
接続の行を `lockForUpdate()` した同一トランザクションで
「身元の有無の確認 → 検査 → 変更」を行う**（D1 の線形化。callback と直列化される）。

### DTO（**一覧では秘密を一度も復号しない**）

```php
/**
 * 画面へ返す接続の要約。
 *
 * ★**接続の秘密を持たない。伏字すら持たない** —
 *   伏字の項目を持つと「一覧の生成時に復号する」実装へ誘導される。
 *   在る・無いだけを bool で返せば、一覧の経路は秘密に一度も触らない。
 */
final readonly class SsoConnectionSummary
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $displayName,
        public string $issuer,
        public string $clientId,
        public OidcConnectionStatus $status,
        public bool $hasClientSecret,          // ★復号しない
        public ?CarbonImmutable $verifiedAt,
    ) {}

    /**
     * Inertia へ渡す形。enum は value、時刻は ISO 8601 文字列、キーは camelCase。
     * TypeScript の Props と一致することをテストが固定する。
     *
     * @return array{id: int, slug: string, displayName: string, issuer: string,
     *               clientId: string, status: string, hasClientSecret: bool, verifiedAt: string|null}
     */
    public function toArray(): array { /* … */ }
}
```

### 画面（DESIGN.md / Atomic Design）

- 既存の **atoms / molecules** を使う（入力欄・ボタン・バッジは新設しない。
  無ければ molecule として足し、organism から atom を作らない = 階層を逆流させない）
- 色・角丸・字は **design token 経由**で参照する（hex 直書きを増やさない）。
  token を増やす必要が出たら `resources/css/tokens.css` との同期を同じ変更で行う
- アイコンは **Lucide**（SVG 直書きを新設しない）
- **必須条件が未充足でもボタンを disabled にしない**。押下時にエラーを表示する（禁止事項 8）
- 画面は **1 枚**（一覧 + 登録・更新フォーム）。秘密を扱う前面を 2 枚に割らない（I4）

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`toArray()` は shape つき）
- [x] null安全（`verifiedAt` は nullable を型で明示）
- [x] DTOを返している（`response()->json()` なし。Inertia へ DTO の `toArray()` を渡す）
- [x] Genericsの型パラメータが正しい（一覧は `list<SsoConnectionSummary>`）

### テスト計画
- [ ] 新規 `tests/Feature/Organizations/OrganizationSsoConnectionTest.php`
  - **他組織の接続 id を URL に入れると 403 ではなく 404**（不変条件 2 / 存在オラクル）
  - **一覧を含む 7 route すべてで**、権限のないメンバーは 403（`Gate::authorize`）
  - **更新系 6 route すべてが再認証なしで弾かれる**
  - **validation 失敗時に client secret がセッションへ残らない**（`dontFlash`）
  - **伏字の見本を送っても秘密が上書きされない**（未入力は据え置き）
  - **一覧の生成が秘密を一度も復号しない**（復号を観測する seam で検査）
  - 応答・Inertia props に client secret の原文が出ない
  - 確認 (`verify`) が専用の流量制限を持ち、他の管理操作と bucket を共有しない
  - **client secret を更新すると一覧の状態が Draft になる**（D1 との結線）
  - **身元がある接続の削除・issuer/client_id の更新が拒否され、押下時にエラーが表示される**
    （ボタンが disabled になっていないことも確認する = 禁止事項 8）
  - **callback と確認の失敗で入力が flash されない**（`code` / `state` / `token` が old input に残らない）
- [ ] 新規 `tests/js/.../oidc-connection.test.ts` — 状態の値域の TS 定数が PHP enum と一致する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **7 route を一度に足す**ので目録の登録漏れが最大の赤要因。F2 で 6 目録を明示的に潰す。

---

## E1: メールアドレスの昇格フロー（Auth 名前空間）

### 変更箇所
- 新規: `app/Services/Auth/EmailPromotionService.php`（**`App\Services\EnterpriseSso` ではない**）
- 新規: `app/Models/EmailPromotion.php` + 移行 1 本 + Factory 1 本
- 新規: `app/Http/Controllers/Auth/EmailPromotionController.php`
- 新規: `app/Http/Requests/Auth/StoreEmailPromotionRequest.php` / `ConfirmEmailPromotionRequest.php`
- 新規: `app/Mail/EmailPromotionMail.php`
- 新規: `app/Exceptions/Auth/EmailPromotionConflictException.php`
- 新規: `app/DataTransferObjects/Auth/VerifiedEmail.php`
- 新規: `app/Console/Commands/Auth/PruneEmailPromotions.php`
- 変更: `routes/web.php`（route 4 本。**既存の認証済み group の内側**）/ `routes/console.php`（掃除の登録）
- 変更: `docs/architecture.md` / `docs/factories.md`（**新モデル 1 本を登録する**）

### 名前空間の配置

```php
/**
 * 企業 SSO でしか入れない利用者が、自分で使えるメールアドレスを持つための昇格。
 *
 * ## なぜ EnterpriseSso ではなく Auth の名前空間に置くか
 *
 * 正典 (laravel-claude-template) の設計判断をそのまま引き継ぐ。
 * 「メールでの引き当てを禁じる設計検査の走査範囲へ入れないための意図的な配置」である。
 *
 * ★**これは検査の回避ではない**。昇格フローも**メールで利用者を引かない** —
 *   引き当ての鍵は常に Auth::id() (自分自身) であり、メール文字列は
 *   「その利用者に紐づける値」としてしか現れない。走査から外すのは、
 *   **メール文字列を正当に扱う唯一の場所**を禁止語の走査へ巻き込まないためであって、
 *   引き当ての禁止を緩めるためではない。この主張は
 *   tests/Architecture/EmailPromotionIdentityGateTest (G5) が
 *   「メールから利用者を引く記法を持たない」「既存アカウントとの併合をしない」の
 *   2 点で固定する。
 */
```

### 表の設計（A2 と同じ粒度）

```php
Schema::create('email_promotions', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();

    // ★トークンは **原文を保存せず指紋だけ** (用途ラベル EmailPromotionToken)。
    //   一意制約が「一回だけ consume できる」の根拠。
    $table->char('token_fingerprint', 64)->unique();

    // 昇格しようとしているメール。**CipherSweet で暗号化する** (PII。不変条件 6)。
    // ★ここにも blind index を付けない — 確定するまでは users のメールではないので、
    //   引き当てに使う理由が無い (I1 と同じ思想)。
    $table->text('email_encrypted');

    $table->timestamp('expires_at');
    $table->timestamps();

    // ★利用者ごとの未消費は **1 件だけ**にする (再送で旧トークンが失効することの DB 側の担保)。
    //   消費は行の削除なので、未消費 = 行が在ることである。
    $table->unique('user_id', 'email_promotions_user_unique');

    $table->index('expires_at');   // 期限切れ掃除の走査用
});
```

| モデル | cast | `$hidden` | relation | Factory |
|---|---|---|---|---|
| `EmailPromotion` | `expires_at` → `immutable_datetime`（メールは CipherSweet） | `email_encrypted` / `token_fingerprint` | `user()` (BelongsTo) | `@use HasFactory<EmailPromotionFactory>` |

`$fillable` は空（Service が明示的に組み立てる）。`MassAssignmentSafetyTest` の母集団に入る。

| 項目 | 形 |
|---|---|
| トークン | **原文を保存せず指紋のみ**（用途ラベル `EmailPromotionToken`。B4 と同じ導出） |
| 結合 | `user_id` を持ち、確認時に**認証済みの利用者と一致**すること |
| 期限 | `expires_at`（`config('enterprise-sso.email_promotion.ttl_seconds')`） |
| 一回使用 | **B4 と同じ原子的な形**（`SELECT … FOR UPDATE` → 検査 → `DELETE` → commit の後に拒否） |
| 再送 | 新しいトークンを発行したら**旧トークンを失効させる**（`user_id` の一意制約 + 発行時の削除） |
| 掃除 | 期限切れ行は `PruneLoginAttempts` と同じ形の日次の掃除に載せる（別コマンド） |

### route 4 本（Round 3 の [Critical] への回答）

★**すべて既存の認証済み group の中**に置く
（`Route::middleware(['auth', 'verified', 'not-pending-deletion'])->group(…)` の内側）。
`auth` を書き忘れると未認証で叩ける経路になるので、**group の外に置かない**。

```php
// （既存の認証済み group の内側）
// ログイン済みの利用者が、自分のメールアドレスを持つための昇格。
// 認可は「自分の資源」なので Gate を通さない (controller が Auth::id() だけを使う)。
// ★ControllerAuthorizationGateTest の exemption 対象になるので理由付きで inventory へ登録する (F2)。

Route::post('/settings/email-promotion', [EmailPromotionController::class, 'store'])
    ->middleware(['recent-auth', 'throttle:email-promotion'])
    ->name('settings.email-promotion.store');

Route::post('/settings/email-promotion/resend', [EmailPromotionController::class, 'resend'])
    ->middleware(['recent-auth', 'throttle:email-promotion'])
    ->name('settings.email-promotion.resend');

// ★メールのリンクが開く**確認画面** (GET)。**状態を変えない**。
//   トークンを画面へ渡し、利用者が明示のボタンで POST する。
//   メールクライアントの先読み・プレビューでは**この画面が開くだけ**で確定しない。
Route::get('/settings/email-promotion/confirm', [EmailPromotionController::class, 'showConfirm'])
    ->middleware(['throttle:email-promotion-confirm', 'no-store'])
    ->name('settings.email-promotion.confirm.show');

// 確定 (POST のみ)。
Route::post('/settings/email-promotion/confirm', [EmailPromotionController::class, 'confirm'])
    ->middleware(['throttle:email-promotion-confirm', 'no-store'])
    ->name('settings.email-promotion.confirm');
```

| route | 認証 | 認可 | throttle | recent-auth | no-store | CSRF |
|---|---|---|---|---|---|---|
| `settings.email-promotion.store` | **必要**（group） | 自分自身（exemption 登録） | `throttle:email-promotion` | **必要**（認証手段を増やす操作） | — | web の既定 |
| `settings.email-promotion.resend` | **必要**（group） | 同上 | 同上 | **必要** | — | web の既定 |
| `settings.email-promotion.confirm.show` | **必要**（group） | 母集団外（GET・状態を変えない） | `throttle:email-promotion-confirm` | 不要 | **付ける** | — |
| `settings.email-promotion.confirm` | **必要**（group） | 自分自身（exemption 登録） | 同上 | 不要（**救済の性格**。関門を足すと確定できず詰む） | **付ける** | web の既定 |

> **なぜ確認を GET 画面 + POST に割るか**: 署名付き GET のリンクだけだと、
> メールクライアントの先読みやプレビューで**利用者が意図せず確定してしまう**。
> リンクが開くのは画面までにして、**状態を変えるのは明示の POST に限る**。

### 確認トークンの受け渡しと、その保証範囲（Round 4 の [Warning] への回答）

メールのリンクは **URL の query にトークンを載せる**（`?token=…`）。
これは「メールから 1 クリックで確認画面へ来られる」ために要る。
**露出を隠しきれる方式ではない**ので、保証する範囲と**保証しない範囲**を書き切る。

**固定すること**:

- **GET は DB の状態を変えない**（画面を返すだけ）
- **トークンの有効・無効で画面を変えない**（一様。存在の探り当てを作らない）
- **`Referrer-Policy: no-referrer`** を付ける
- 確認画面は**外部リソースを一切読み込まない**（Referer が出る経路を作らない）
- **アプリのログ・監査・例外に完全な URL を記録しない**（トークンは平文でも指紋でも出さない）
- 画面から POST へ渡すときは **サーバが描画した form の hidden 項目**に載せる
  （Inertia の props に置かない。履歴の暗号化に依存しない）
- 失敗時に入力を **flash しない**（`withInput()` を使わない）。
  `token` は一般名なのでグローバルの `dontFlash` へ足さず、**経路側で閉じる**（D2 と同じ判断）
- トークンを受ける引数に **`#[SensitiveParameter]`** を付ける

**保証しないこと（誇張しない）**:

- **リバースプロキシや CDN のアクセスログ**、**ブラウザの履歴**、
  **利用者が URL を他人へ貼ること**による露出は防げない。
  緩和は **60 分の期限**と **一回だけの consume** であり、
  露出しても**使われる窓が短く、1 回しか効かない**ことに寄せている

### 昇格の条件と衝突

- **本人確認**: 確認メールのトークンを踏んだときにだけ確定する（IdP の申告メールをそのまま昇格させない）
- **認可**: 対象は**認証済みの自分自身のみ**
- **監査**: 変更を記録する（既存の監査基盤へ載せる）
- **確定時の `email_verified_at`**: ★「以前の値のまま」にせず、
  **新しいメールを実際に確認した時刻へ更新する**（A3 の規約と対。timestamp の意味を保つ）
- **衝突**: 確認済みメールが既存利用者のメールと重なったとき、
  **既存利用者を一切変更せず・併合せず・昇格も行わない**。応答は**一様**。
  ★**メールの blind index の一意制約違反だけ**を一様な応答へ変換し、
  それ以外の一意制約違反と DB の障害は**握り潰さない**。

### メール送信

新しい送信経路が 1 本増える。**既存の送信の作法**（送信基盤・目録・流量制限）へ登録する
（独自機構を足さない）。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている / null安全 / DTOを返している / Generics 正しい

### テスト計画
- [ ] 新規 `tests/Feature/Auth/EmailPromotionTest.php`
  - トークンを踏むまで昇格しない
  - **他人のトークンでは昇格しない**（`user_id` の結合）
  - **他人のアカウントを併合しない**
  - **衝突時の応答が一様**（存在を漏らさない）
  - **blind index 以外の一意制約違反は握り潰さない**（負のコントロール）
  - **競合実行**でも 1 件しか確定しない
  - 再送で旧トークンが失効する
  - 期限切れのトークンが拒否される
  - **確定で `email_verified_at` が確認した時刻へ更新される**
  - **並行の確認**（並行ハーネス）で 1 件しか確定しない
  - **確認が GET では確定しない**（GET は画面を返すだけ。状態が変わらない）
  - **確認の失敗でトークンが old input に残らない**
  - **ログ・例外・監査にトークンが出ない**
- [ ] 新規 `tests/Feature/Auth/EnterpriseOnlyUserEmailTest.php`（A3 と共有）—
      昇格前は `email = null` でパスワード再設定が使えず、昇格後に使えるようになる。
      `email_verified_at` は昇格の前後とも**確認済みのまま**
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 昇格は「メールを持たない利用者が持つようになる」変化なので、
  通知・請求・管理画面の各所が `email = null` 前提と非 null 前提の両方を跨ぐ。A3 の波及と一体で潰す。

---

## F1: gate 5 本（G1〜G5）+ 走査器

### 変更箇所
- 新規: `tests/Architecture/EnterpriseSsoEmailIdentityIsolationTest.php`（G1）
- 新規: `tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php`（G2）
- 新規: `tests/Architecture/EnterpriseSsoSecretExposureGateTest.php`（G3）
- 新規: `tests/Architecture/SsoTwoFactorInterpositionGateTest.php`（G4）
- 新規: `tests/Architecture/EmailPromotionIdentityGateTest.php`（G5）
- 新規: `tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php`（走査器の本体）
- 新規: `tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php`（走査器の自己検査 = 負例）
- 新規: `tests/Architecture/fixtures/enterprise-sso/*`（見本ファイル）

### 走査器共通規約（AGENTS.md）への適合

**発火条件に 5 本とも該当する**（走査ロジック・走査対象・名前解決・判定条件・目録の新設）。

| 条 | 適用 | 本設計での形 |
|---|---|---|
| (a) クラス参照は完全修飾名で突き合わせる | G1〜G5 | `use` / group use / 別名つき取り込みを解いた完全修飾名で比べる。構文解析ライブラリは必須ではなく、字句走査 + 取り込み対応表でよい（家系の裁定 AG-154 (2)）。既存の `Tests\Support\PhpReferenceScanner` / `PhpTokenScan` を再利用する |
| (b) 解決できない形は落とす | G1〜G5 | **下記のとおり「保証外」にせず fail-closed へ倒す** |
| (c) 検出力は負例で裏取りする | G1〜G5 | 違反する入力を検出できること／規定どおりの入力を誤検出しないことの**両方向**。置き場は `tests/Architecture/fixtures/enterprise-sso/` と `tests/Unit/Architecture/` の 2 通りで、**gate の docblock から辿れる** |
| (d) 集めた結果を必ず判定に使う | G1〜G5 | 収集して参照しない出力・数えるだけで比べない目録を作らない |
| (e) 語彙一致はトークン完全一致 | G1・G3・G4・G5 | 区切り文字集合を**走査ごとに宣言**する。負例に**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を置く |

### (b) を「保証外」ではなく fail-closed で満たす（Round 1 [Critical] / Round 2 [Critical] への回答）

正典の G2 は「変数経由の間接呼び出しは検出できない」と自ら書いている。
しかし AGENTS.md (b) は「**保証範囲の外にした構文で保護対象の操作を書けるなら、
検出力の主張を狭めるか、未解決として失敗させるかのどちらか**」と定める。

一方で **「変数経由の呼び出しをすべて未解決として落とす」は実装不能**である
（`$this->pinned->fetch()` のような通常のオブジェクト呼び出しが大量にあり、
字句走査では受け手の型まで解決できない。Round 2 の指摘）。

**採る形**（走査根が `App\Services\EnterpriseSso` という**自分たちが書く小さな領域**であることを使う）:

| 構文 | 扱い |
|---|---|
| `Http::` ファサード / `HttpFactory` の型注入 / `new Client()` 等の**固定の名前** | **完全修飾名で判定**する（(a)） |
| `$this->x->method()` のような**固定のメソッド名**の呼び出し | 受け手の**宣言型**（構築子の引数・プロパティの型・PHPDoc）から解決できる範囲で判定する。**解決できる範囲を docblock に明記**し、「すべての呼び出しを解決できる」とは主張しない |
| **動的なメソッド名** `$obj->$name()` / **可変クラス名** `new $cls` / `$cls::method()` / **可変 callable**（`call_user_func` 系・文字列や配列からの呼び出し） | ★**検出したら gate を失敗させる**（未解決を無言で候補から外さない）。走査根の中でこれらを使う正当な理由が無いので、禁じても実装が困らない |
| **保護対象らしい固定の語彙**（`request` / `get` / `post` / `send` / `fetch` …）の呼び出しで、**受け手の型が解決できない**もの（局所変数・factory の戻り値など） | ★**gate を失敗させる**（Round 3 の指摘。動的構文でなくても解決範囲の外に落ちうるため） |

### G2 が主張する範囲（Round 3 の [Critical] への回答）

G2 が主張するのは**次の 3 つの積**だけである（これ以上を主張しない）:

1. 走査根の中に**既知の禁止型・ファサードの参照**（`Http` / `HttpFactory` / vendor の HTTP クライアント）が無い
2. 走査根の中に**動的な呼び出しの形**が無い
3. 走査根の中に**受け手の型が解決できない保護対象語彙の呼び出し**が無い

★**「外向きは `PinnedHttpClient` だけである」という主張の主証明は静的側に置かない。**
主証明は次の 2 本に移す（gate の docblock がそう宣言する）:

- **DI の結線テスト**: 企業 SSO の 3 サービスへ注入されるのが `PinnedHttpClient` だけであること
- **`PinnedHttpClient` の実挙動テスト**（B1 / F4）: 実装が pin 済み経路を実際に通ること

- 完全な型解決が要る判定は作らない（PHPStan / AST への依存を持ち込まない）
- G1・G3・G4・G5 も同じ扱いにする

### 各 gate が固定するもの

| gate | 固定する内容 |
|---|---|
| G1 | 企業 SSO の名前空間・controller・身元モデルに**メールで利用者を引く記法**が無い（`whereBlind('email', …)` を含む）。加えて**申告メールの列（または対応する blind index）を含む索引が 0 本**であることを**スキーマの読み取りだけ**で確かめる（破壊操作を伴わない = 禁止事項 3） |
| G2 | `App\Services\EnterpriseSso` 配下に **`Http` ファサード・`HttpFactory` の使用が無い**（許可一覧を持たない）。**動的な呼び出しの形**と**受け手の型が解決できない保護対象語彙の呼び出し**が無い。**自動リダイレクト追従を有効にする記法**も無い。★**「外向きは `PinnedHttpClient` だけ」の主証明は DI の結線テストと実挙動テストにあると gate 自身が宣言する** |
| G3 | 接続の秘密が**受け渡しの型に存在しない**（語彙の走査に加え、**対象の型の構築子引数・公開項目・直列化の形を型単位で検査**する）。`ConnectionSecret` を**ログ・dump・直列化の関数へ渡す記法**が無い。`ConnectionSecret::revealForTokenExchange()` の**呼び出し元を exact-fit で pin**（トークン交換 1 本だけ）。例外の型が**理由の enum だけを受け取り `previous` を受け取れない構築子**を持つ（★型で連鎖が起きないので「例外に秘密が載らない」を構造で担保する）。**主たる証明は実挙動の漏洩テスト（B2・D2）にあると gate 自身が宣言する** |
| G4 | 企業・ソーシャル**両方**の戻り口に、待機ログインを作る記述・2 要素の入力画面への転送が無い。**主たる証明は実挙動側（C2 のテスト）にあると gate 自身が宣言する** |
| G5 | 昇格フローが**メールから利用者を引かない** / **既存アカウントとの併合をしない** |

### 4 点（同じ変更で揃える）

1. **負例と正例。テストファーストで先に赤くしてから本体を書く**（移植で最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する）
2. **解決できない形を落とす分岐**（上記のとおり fail-closed）
3. **走査が空振りしていないことの検査** — 母集団が空でないこと／走査根がそれぞれ生きていること。
   走査根は `Tests\Support\TrackedPhpSourceFiles` を使い、同じ列挙を 2 本持たない。
   母集団がそれより狭い走査は自分の根を持ってよいが、**存在しない根は fail-fast** で落とす
4. **docblock に走査対象と保証しないものを書く**（正本は docblock 側。本設計へ写さない）

### テスト計画
- [ ] 各 gate に対応する負例を先に書き、**赤を確認してから**本体を書く
- [ ] 走査根が空でないことの検査を 5 本とも持つ
- [ ] 走査器の自己検査で
      「**動的なメソッド名・可変クラス名・可変 callable** を未解決として落とす」ことと、
      「**通常の固定メソッド呼び出しを誤検出しない**」ことの**両方向**を固定する
- [ ] `EnterpriseSsoAttemptRejectedException` が **`previous` を受け取る構築子を持たない**
      （型の検査。B2 の「vendor 例外を連結しない」を構造で担保する）
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseSsoHttpWiringTest.php` —
      **企業 SSO の 3 サービスへ注入される HTTP の担い手が `PinnedHttpClient` だけである**
      （G2 の主張のうち「外向きは pin 済み経路だけ」の**主証明**。静的側では主張しない）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **走査器を 1 本に共有する**ので、走査根の定義を誤ると 5 本が同時に空振りする。3 の空振り検査がその唯一の防波堤になる。

---

## F2: aicue 側の目録登録（新規 14 route の全分類）

### 変更箇所
- 変更: `app/Enums/Security/ThrottleCoverageExemption.php`（既存 case で足りるなら足さない）
- 変更: `app/Enums/Security/ControllerAuthorizationExemption.php`（同上）
- 変更: `tests/Support/Routing/NestedRouteDefenseInventory.php`
- 変更: `tests/Architecture/RecentAuthRouteTest.php`（**8 route を allowlist へ**）
- 変更: `tests/Architecture/CachePayloadPlainDataGateTest.php` の目録（B1 のキャッシュ経路）
- 変更: named limiter の登録と関連目録（`RateLimiterKeyConventionTest` /
  `ThrottleLaneAssignmentTest` / `InlineThrottleInventoryTest`）— **6 本を新設**する:
  `enterprise-sso-start` / `enterprise-sso-callback` / `enterprise-sso-manage` /
  `enterprise-sso-verify` / `email-promotion` / `email-promotion-confirm`
- **変更しない**: `tests/Architecture/TwoFactorEnforcementAllowlistTest.php` / `app/Enums/Account/AccountDeletionFreezeAllowance.php`

### 追加する **14 route** の全分類（Round 1〜3 の指摘への回答）

| # | route | throttle | 認可 | nested 防御 | recent-auth |
|---|---|---|---|---|---|
| 1 | `enterprise-sso.login` | **持たない** → exemption（外向き通信をしない開始画面。GET・DB を変えない） | 母集団外（GET） | parameter なし | 不要（未認証面） |
| 2 | `enterprise-sso.redirect` | `throttle:enterprise-sso-start` | **母集団外だが明示分類する**（下記） | `{connectionSlug}` は `NonResourceParameter`（組織に属さない公開の識別名） | 不要（未認証面） |
| 3 | `enterprise-sso.callback` | `throttle:enterprise-sso-callback` | 母集団外（GET） | parameter なし | 不要（未認証面） |
| 4 | `organizations.sso.index` | **持たない** → exemption（読み取り専用。既存の一覧 route と同じ扱い） | **`Gate::authorize` あり**／`ControllerAuthorizationGateTest` の**母集団外**（GET） | `{organization}` = `ScopedBinder` | 不要（閲覧のみ） |
| 5 | `organizations.sso.store` | `throttle:enterprise-sso-manage` | `Gate::authorize` | `{organization}` = `ScopedBinder` | **必要** |
| 6 | `organizations.sso.update` | 同上 | `Gate::authorize` | + `{oidcConnection}` = `ScopeBindings` | **必要** |
| 7 | `organizations.sso.verify` | `throttle:enterprise-sso-verify`（専用） | `Gate::authorize` | 同上 | **必要** |
| 8 | `organizations.sso.activate` | `throttle:enterprise-sso-manage` | `Gate::authorize` | 同上 | **必要** |
| 9 | `organizations.sso.disable` | 同上 | `Gate::authorize` | 同上 | **必要** |
| 10 | `organizations.sso.destroy` | 同上 | `Gate::authorize` | 同上 | **必要** |

| 11 | `settings.email-promotion.store` | `throttle:email-promotion` | **exemption**（自分自身の資源のみを扱い、Gate を通さない。理由付きで inventory へ登録） | 母集団外（parameter なし） | **必要** |
| 12 | `settings.email-promotion.resend` | 同上 | 同上 | 母集団外 | **必要** |
| 13 | `settings.email-promotion.confirm.show` | `throttle:email-promotion-confirm` | 母集団外（GET・状態を変えない） | 母集団外 | 不要 |
| 14 | `settings.email-promotion.confirm` | 同上 | exemption（自分自身の資源） | 母集団外 | 不要（救済の性格） |

→ named limiter を持つのは **12 本**（2・3・5〜14）。throttle の exemption は **2 本**（1・4）。
認可の exemption は **3 本**（11・12・14）。`recent-auth` は **8 本**（5〜10・11・12）。
`no-store` は **4 本**（2・3・13・14）。

### `recent-auth` の allowlist へ足す 8 本

- 組織側の更新系 **6 本**（store / update / verify / activate / disable / destroy）—
  接続の秘密と組織のログイン経路を変える操作であり、既存の「API キー発行・失効」と同水準
- メール昇格の**発行・再送 2 本** — 認証手段を増やす操作であり、既存の `settings.password.store` と同水準
- ★**確認 (`confirm`) は足さない** — 救済の性格であり、関門を足すと確定できず詰む
  （既存の「退会予約の取消を allowlist に入れない」と同じ判断）

### 副作用のある GET の明示分類（[Warning] への回答）

`enterprise-sso.redirect` は **GET だが DB に試行の行を作る**。
`ControllerAuthorizationGateTest` の母集団は変更系 HTTP メソッドなので、
**既存の検査だけでは見落とす**。したがって:

- **OAuth の開始 GET** として理由付きで明示分類する
  （CSRF トークンの代わりに `state`・ブラウザ結合・流量制限が守る）
- 固定するテスト:
  - 未認証で叩けること自体は正常（ログインの開始である）
  - **無効な接続では行を作らない**
  - **`no-store`** が付く
  - 流量制限が効く
  - 作られる行が期限を持つ（無制限に溜まらない）

### 登録しない判断

| 目録 | 判断 |
|---|---|
| `TwoFactorEnforcementAllowlistTest`（件数 21） | **追加しない**。組織側の接続管理は業務面であり、2 要素義務づけの下で到達できなくてよい。ログイン導線は未認証面なのでゲートの母集団に入らない |
| `AccountDeletionFreezeAllowance` | **追加しない**。企業 SSO の接続管理は退会予約中に実行できなくてよい |

### テスト計画
- [ ] 目録を触った各 gate が緑
- [ ] `TwoFactorEnforcementAllowlistTest` の件数 pin が **21 のまま**（意図せぬ追加をしていない）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- exemption の case を「汎用に見えるもの」へ押し込むと gate が形骸化する。当てはまる case が無ければ、それは throttle / 認可を足すべき route である。

---

## F3: 逸脱の登録 D37 + 台帳件数の pin

### 変更箇所
- 変更: `docs/template-divergence.md`（エントリ D37 を追加。冒頭の「登録エントリ: N 件」を 36 → 37）
- 変更: `tests/Support/TemplateDivergence/LedgerPins.php`（`DIVERGENCE_ENTRY_COUNT` を 36 → 37）

### 乖離台帳の確認（app-design Phase 3-0 の確認段）

`docs/template-fingerprints.json` の `entries`（281 件）を実読して突き合わせた:

- 本設計が**新規作成**するファイルは**いずれも `entries` に無い**（テンプレートに無い領域）
- 本設計が**変更**する既存ファイル（`routes/web.php` / `routes/console.php` /
  `app/Models/User.php` / `app/Models/Organization.php` / `app/Auth/EncryptedUserProvider.php` /
  `composer.json` / `tests/Architecture/*` / `app/Enums/Security/*` / `tests/Support/*` /
  `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php`）も
  **`entries` に無い**
- `entries` に在る config は `audit` / `cache` / `ciphersweet` / `laratrust` / `ssrf-pin` の 5 本で、
  **本設計はどれも変更しない**（`config/enterprise-sso.php` は新規で、共有ファイルではない）
- したがって **`adoption-debt.tsv`（171 件）に触れるパスも無い**（`mutatedDebtPaths` で落ちない）

→ **形式上の登録義務は発生しない**。しかし記録の原則が
「**登録するか迷ったら登録する**」「テンプレートに無い領域への上積みは登録側へ倒す」と定めており、
ログイン試行の機構は**正典 v1 に無い上積み**である。よって登録する。

> **実装時の再確認**: 上記は本設計の時点の照合である。着手時に
> `docs/template-fingerprints.json` を取り直して同じ突き合わせを行う
> （テンプレート台帳が更新されていれば結論が変わりうる）。

### D37 の内容（登録メタ表 9 行）

| 行 | 値 |
|---|---|
| 対象パス | `app/Models/EnterpriseSsoLoginAttempt.php` / `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php` / `app/DataTransferObjects/EnterpriseSso/ConsumedLoginAttempt.php` / `app/DataTransferObjects/EnterpriseSso/AttemptConsumeResult.php` / `app/Support/EnterpriseSso/AttemptFingerprint.php` / `app/Enums/EnterpriseSso/FingerprintPurpose.php` / `app/Console/Commands/EnterpriseSso/PruneLoginAttempts.php` / `database/migrations/2026_08_23_000300_create_enterprise_sso_login_attempts_table.php` / `database/factories/EnterpriseSsoLoginAttemptFactory.php` / `tests/Feature/EnterpriseSso/EnterpriseLoginAttemptStoreTest.php` / `tests/Feature/EnterpriseSso/PruneLoginAttemptsTest.php` / `tests/Architecture/EnterpriseSsoPruneScheduleTest.php` |
| 業務要件起因の説明 | 正典はログイン試行の保管先を表として持たない。aicue は `state` の使用権の唯一性を**セッションドライバの種別と `->block()` の書き忘れに依存させない**ため、DB の一意制約と行ロックへ寄せた。あわせて**一時トークンの指紋方式**（用途ラベルで domain separation する導出）を機構横断の部品として持つ — 企業 SSO のログイン試行とメールアドレスの昇格が同じ導出を使う |
| 揃え続ける不変条件と保証機構 | 「同じ試行の使用権をちょうど 1 つの要求だけが得る」「その試行を開始したブラウザだけが使える」を `EnterpriseLoginAttemptStoreTest` の並行検査と別ブラウザ検査が固定する |
| 再判定の条件 | 本形が正典へ還流されて正典側の版が上がったら、独自差分ではなく**新しい正典追従**になるので登録を消す。また正典が同等の原子性とブラウザ結合を別方式で持ったときも見直す。★**メールアドレスの昇格の側が正典で指紋方式を採ったときも見直す**（本登録は機構横断の一時トークンの指紋方式を含むため、昇格側だけが正典化したら対象パスの線引きを引き直す） |
| 決めた日 | `2026-08-23` |
| 決めた人 | `開発者` |
| 根拠 | `devnotes/20260823-0015-enterprise-oidc-sso-adoption/` |
| 状態 | `監視中` |
| 見直し期限 | `2027-08-23`（基準日から 400 日以内） |

### 対象パスの線引き（Round 2 の [Warning] への回答）

| パス | 入れるか | 根拠 |
|---|---|---|
| 試行の表・モデル・Store・DTO 2 本・掃除コマンド・移行・Factory・対応テスト | **入れる** | DB 試行方式そのものを構成する固有の資産 |
| `AttemptFingerprint` / `FingerprintPurpose` | **入れる** | 一時トークンの指紋方式の中核。★**メールアドレスの昇格でも使う**ので、D37 の説明は「ログイン試行だけの資産」ではなく「**機構横断の一時トークンの指紋方式**」として書く（対象と説明の意味を食い違わせない） |
| `routes/console.php` | **入れない** | ★既存の**共有ファイル**であり、掃除の登録 1 行のためにファイル全体を D37 の対象にすると、この 1 ファイルを触る将来の逸脱と**必ず衝突する**（値域の要件「全登録の和集合で重複しない」）。**追跡は切れない** — 掃除の本体は `PruneLoginAttempts` コマンド（D37 の対象）に在り、`routes/console.php` の 1 行はその**呼び出しの登録**にすぎないため、コマンドが D37 に載っている限り機構としての追跡は保たれる。この根拠を D37 の本文に書く |
| `EnterpriseSsoLoginController` / `EnterpriseCallbackAuthenticator` | **入れない** | **正典にも在る資産**である（保管先の実装が違うだけ）。逸脱は「保管先を表にしたこと」であって controller の存在ではない |

### テスト計画
- [ ] `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が緑（9 行ちょうど・順序・値域・**和集合で重複しない**）
- [ ] `tests/Architecture/TemplateDivergenceFingerprintTest.php` が緑（件数 3 点一致）
- [ ] 新規 `tests/Architecture/EnterpriseSsoPruneScheduleTest.php` —
      **掃除コマンド 2 本が scheduler へ日次で登録されている**
      （★コマンドが在るだけでは日次の掃除は成立しない。登録そのものを固定する）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 件数の pin は**宣言行・見出しの実数・定数の 3 点一致**なので、1 か所でも忘れると赤になる。
  マージ直前に現物から取り直す（他の TODO が同時に登録を増やしうる）。

---

## F4: 試験用の偽 IdP と外部到達点の登録

### 変更箇所
- 新規: `app/Services/EnterpriseSso/Fakes/FakeOidcDiscoveryService.php` ほか（正典の「試験用の接続先 4 クラス」に相当）
- 新規: `app/Http/Controllers/Testing/FakeIdpAuthorizeController.php`（**試験環境限定で登録される**）
- 変更: `app/Support/ExternalFakes/ExternalFakeDeclaration.php`
- 変更: `tests/Support/ExternalSeam/ExternalSeamInventory.php`

### 設計の要点

- **テストレーンは外向き HTTP を既定で拒否する**（AGENTS.md）。実 IdP へ出ない。
- 偽の IdP の許可環境は**外部ログインと同じ `testing` / `bughunt.local`** に絞る
  （`local` を外す理由は既存の `SSO_ENVIRONMENTS` の docblock と同じ）
- **同じ事実を 2 か所に書かない**（AGENTS.md ドメイン規約 9）:
  差し替えの宣言は `ExternalFakeDeclaration`、外部到達点の目録は `ExternalSeamInventory` が持つ
- 本番コードが偽の実装のクラス名を参照しないことは既存の `FakeClassReferenceInvariantTest` が全走査する
- **接続先 URL の入力規則は https 必須**なので、偽の IdP は**本番のモデルに登録しない**。
  差し替えの seam でだけ扱う

### テスト計画
- [ ] `ExternalFakeWiringInvariantTest` / `ExternalSeamInventoryTest` /
      `LaneExternalFakeBindingTest` / `FakeClassReferenceInvariantTest` が緑
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseOidcFakeRoundTripTest.php` — 偽の IdP で往復が通る
- [ ] 新規 `tests/Architecture/FakeIdpRouteAbsenceTest.php`
  - **production 相当の環境で偽の route が route の一覧に存在しない**
  - **フラグ無効時に route も結線も存在しない**
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcDiscoveryPinnedPathTest.php`（B1 と共有）—
      **偽への全面差し替えとは別に**、実装が `PinnedHttpClient` を通ることを
      ssrf-pin のテスト seam で観測する
- [ ] `ProductionEnvGuard` が本番での有効化を止める（既存機構に載るだけ）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 偽の IdP は**未認証の GET で任意の subject を名乗れる**。許可環境の絞りが唯一の防波堤なので、
  既存の `SSO_ENVIRONMENTS` と同じ集合を使い、独自に緩めない。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) 新規ファイルが 50 本前後、`routes/web.php` に **14 route** を足し、9 つの目録と 2 つの台帳ファイルを触る。(2) **前段依存が 2 本**（`ssrf-pin-v04-upgrade` と `process-concurrency-harness-adoption`）あり、どちらも別 TODO で先行するため、完了を待って独立ブランチで積む必要がある。(3) A3（`users.email` の nullable 化）は既存テーブルへの変更で、PHPStan の洗い出しが広範囲に及ぶ。(4) gate 5 本をテストファーストで赤→緑にする工程が長く、他施策と混ぜると赤の出所が分からなくなる |
| 競合リスク | `routes/web.php` / `app/Models/User.php` / `tests/Support/Routing/NestedRouteDefenseInventory.php` / `tests/Architecture/RecentAuthRouteTest.php` / `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` は**他の TODO も触る中心ファイル**である。とくに `LedgerPins.php` の件数 pin は他の逸脱登録と衝突しやすいので、マージ直前に件数を取り直す |

### 段の順序（直列。前段が緑になってから次へ）

| 段 | 施策 | 前提 |
|---|---|---|
| 前段① | — | **`ssrf-pin-v04-upgrade` の完了**（受入条件 3 点: GET の本文取得 / **body 付き POST の本文取得** / どちらも SSRF 判定を通る） |
| 前段② | — | **`process-concurrency-harness-adoption` の完了**（B4・C1・C2 の並行テストが乗る土台。グローバル `RefreshDatabase` の下では独自に組めない） |
| A | A1・A2・A3・F3 | 前段 |
| B | B1・B2・B3・B4・F4 | A |
| C | C1・C2 | B |
| D | D1・D2 | C |
| E | E1 | D（A3 と一体で意味を持つ） |
| F | F1・F2 | 各段が自分の gate を持って緑にしたうえで、取りまとめる |

> **gate は最後にまとめて足さない**。各段が自分の gate を同じ変更で持って緑にする
> （禁止事項 1: 不変条件は対応するテストへの登録まで含めて「実装済み」）。
> F は目録の登録漏れを閉じる取りまとめの段である。

## 受入条件（検証コマンド）

実装の完了は**次のすべてが緑**であることをもって判断する
（AGENTS.md「検証コマンド」の一覧に従う）:

| コマンド | 対象 |
|---|---|
| `composer test` | PHP のテスト全数（Architecture / Feature / Unit） |
| `composer phpstan` | PHPStan level 10（**widen も baseline も行わない** = 禁止事項 2） |
| `vendor/bin/pint --test` | PHP の整形 |
| `pnpm lint` | JS/TS の静的検査 |
| `pnpm typecheck` | TypeScript の型 |
| `pnpm test` | **JS 側の gate の正本のレーン**（enum ↔ TS 定数の同期はここでしか走らない） |
| `pnpm build` | 本番ビルド |
| `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | ワークスペースの package |

★**`composer fix` / `pnpm lint:fix` は検証の代替にならない**（整形を当てるだけで判定しない）。
★段ごとに緑にする。最後にまとめて走らせない。

## スコープ外（明記）

- **ソーシャル SSO の作り替え**（`auth-sso-social`）。既に AG-200 の形なので**挙動を変えない**。
  本設計が触るのは「その形を機械で固定する gate（G4）を 1 本足す」ことだけである。
- **運営側 SSO**（`auth-admin-sso`）。
- **`acr_values` による認証強度の要求**。AG-200 が「強度を上げたい要件はこれで行う」と書いているが、
  aicue に該当要件は無い（思考原則 2「今必要なものだけ作る」）。
- **SCIM / 自動デプロビジョニング**、および **IdP 側の停止に連動した既存セッションの即時失効**。
  入退社連動は「次回ログインができなくなる」までとする。
- **IdP 起点のログイン（IdP-initiated SSO）**。RP 起点のみ。
- **接続を無効にした後の猶予窓**（spirux にだけ設定値があるが強制する仕組みは未実装で、正典の形ではない）。
- **`kent013/laravel-ssrf-pin` の版上げそのもの**（別 TODO `ssrf-pin-v04-upgrade`）。
  本設計は `config/ssrf-pin.php` を**変更しない**。
- **既存ログイン手段の削除・変更**（`EnsureLoginMethodRemains` の意味論は変えない）。
- **refresh token の保存とバックグラウンドでの更新**。ログインの確定にのみトークンを使い、保存しない。
- **`userinfo` endpoint の呼び出し**。身元は ID トークンの claim だけから決める（外向きの経路を増やさない）。

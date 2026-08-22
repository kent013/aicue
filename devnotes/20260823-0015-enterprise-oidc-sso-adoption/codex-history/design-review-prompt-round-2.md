# Round 2: Round 1 の指摘への対応

Round 1 の全指摘 (Critical 15 / Warning 14) に対応した。主な設計変更は 6 点:

1. **PinnedHttpClient に一本化** — `Http` ファサード・`HttpFactory` を企業 SSO の名前空間から締め出す。
   「inspect → 素の Http」の形は v0.2 の PinnedHttpClient が本文を返せなかったことの産物であり、
   ^0.4 では制約が無い。「DNS rebinding は解消しない」という但し書きも削除した。
2. **subject の一意性をバイト列由来の keyed fingerprint へ** — 照合順序に依存させない。
3. **B4 のトランザクション巻き戻しバグを修正** — 結果を表す値を返し、commit の後に拒否の例外を投げる。
   併せて契約する DB を pgsql と明記した (phpunit.xml が DB_CONNECTION=pgsql を force している)。
4. **施策 A3 を新設** — `users.email` の nullable 化。JIT 利用者は email=null / email_verified_at=now()。
   仮のメール文字列を作らず、既存 verified middleware の意味論も変えない。
5. **認証材料の更新は必ず Draft へ戻す** (D1)。
6. **client secret の露出面を 5 つとも閉じた** — 暗黙の文字列化を持たない値型 / dontFlash /
   一覧では復号しない (伏字すら DTO に持たない) / vendor 例外を連結しない / #[SensitiveParameter]。

また gate の (b) は「保証外と書く」のをやめ、**未解決を検出したら gate を失敗させる** fail-closed へ倒した。

対応マトリクスと改訂した詳細設計の全文を示す。再レビューを求める。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

## 事実確認（対応方針の前提。実読で確定した）

| 確認事項 | 結果 |
|---|---|
| テストレーンの DB | `phpunit.xml` が `DB_CONNECTION=pgsql` を `force` している。**テストも本番も pgsql** で、SQLite は使わない → `SELECT … FOR UPDATE` の排他契約は本番と同じ |
| `PinnedHttpClient` の API | 現行 v0.2 の時点で `fetch(PinnedRequest, Deadline): PinnedResponse\|PinnedFailure` を持つ。**^0.4 では要求・応答の body と大きさ制限つき読み出しを備える**（前段 TODO の実読済み調査） |
| JWT の部品 | **`firebase/php-jwt` v7.0.5 が既に解決済み**（家系の台帳が言う「署名付きトークンの検証に使う部品」と同じ版）。ただし現状は推移依存なので、直接使うなら `composer.json` へ明示する |
| 利用者の役割 | `OrganizationRole` は `Owner` / `Admin` / `Member` の 3 case |
| `users.email` | 現在 **NOT NULL**（CipherSweet の ciphertext を `text` で保持。一意性は `blind_indexes` の partial unique） |
| 画面の構成 | `resources/js/components/` は `atoms` / `molecules` / `organisms` / `templates` / `features` |

---

## A1

### [Warning] 設定テストが大小関係だけでは不足

- 判断: **対応する**
- 対応内容: 全整数について**正数・上限・大小関係**を固定する検査に書き換えた。
  とくに `id_token.leeway_seconds` と `login_attempt.ttl_seconds` は
  セキュリティ境界なので**上限も**検査する。

### [Warning] 指紋用のサーバー秘密の出所とローテーション時の挙動

- 判断: **対応する**
- 対応内容: **`APP_KEY` から用途別ラベル付きで HKDF 導出**する形に確定した。
  専用の秘密を新設しない（運用要件を 1 つ増やさない。思考原則 2）。
  `APP_KEY` をローテートすると**進行中の試行（TTL 10 分）だけが失効**し、
  それ以外に失うものが無いことを根拠として明記した。
  ラベルは `state` / `nonce` / `browser-binding` / `subject` の 4 種で
  **domain separation** し、相互に使い回せない形にする。

---

## A2

### [Critical] `subject` の大小文字同一視で I1 が誤って実装される

- 判断: **対応する**
- 根拠: 正しい。照合順序しだいで `Alice` と `alice` が同一の身元になる。
  これは「別人が同じアカウントに入る」ことを意味し、不変条件の実装として致命的である。
- 対応内容: 一意制約を**バイト列由来の keyed fingerprint** に張る形へ変更した。
  `subject` 列は監査・表示用に原文を持ち、**引き当てと一意制約は `subject_fingerprint`**（HMAC-SHA256、
  用途ラベル `subject`）で行う。照合順序に依存しない。
  大小文字違いが**別の身元**になる Feature テストを追加した。

### [Critical] 接続表の列・制約・cast・relation が示されていない

- 判断: **対応する**
- 対応内容: 3 移行すべての**列・索引・一意制約・外部キー・cast・relation** を列挙した。
  接続の識別名（slug）は**全体一意**かつ**推測されてよい**（公開のログイン導線で使う）ことを明記し、
  推測可能性に依存した防御を持たない（防御は接続の状態と PKCE・state・ブラウザ結合が担う）と書いた。

### [Warning] G1 の「索引が 0 本」は不正確

- 判断: **対応する**
- 対応内容: 「**申告メールの列（またはそれに対応する blind index）を含む索引が 0 本**」へ言い換えた。
  複合一意制約と外部キーの索引は当然に在る。

### [Critical] `EncryptedSecretStringCast` の値型が不明確

- 判断: **対応する**
- 対応内容: 値型 `ConnectionSecret` を新設し、
  **`__toString()` を持たせない**／`__debugInfo()` は伏字を返す／
  平文の取り出しは **トークン交換だけが呼ぶ 1 メソッド**に集約する、と確定した。
  暗黙の文字列化ができないので、ログ・例外・DTO へ「うっかり」載る経路が型で消える。

---

## B1

### [Critical] 検査後に素の `HttpFactory` で取得している（不変条件 8 の後退）

- 判断: **対応する（設計の誤り）**
- 根拠: 正しい。`SnsCertificateFetcher` が inspect → `Http::` の形なのは
  **v0.2 の `PinnedHttpClient` が本文を返せなかったから**であり、
  ^0.4 ではその制約が無い。制約が消えたのに古い形を写すのは後退である。
- 対応内容: **3 経路とも `PinnedHttpClient` に一本化**し、
  `App\Services\EnterpriseSso` 配下から `Http` ファサード・`HttpFactory` の使用を**禁止**する
  （G2 がこれを固定する）。「DNS rebinding は解消しない」という但し書きも削除した
  （pin 済み経路は検査と接続が同じ経路なので、その但し書きは当てはまらない）。

### [Critical] issuer が HTTPS である保証が無い

- 判断: **対応する**
- 根拠: 正しい。`config/ssrf-pin.php` は `http` を許しており、
  平文で client secret・認可コード・トークンが流れうる。
- 対応内容: 接続の入力規則として **https 必須・userinfo/query/fragment なし・
  正規化できる絶対 URL** を FormRequest と値オブジェクトの両方で必須化した。
  偽の IdP は**本番のモデルに登録せず**、差し替えの seam で扱う。

### [Warning] endpoint の同一 origin 強制は OIDC 標準の要件ではない

- 判断: **対応する（独自強化を外す）**
- 根拠: 正しい。実在の IdP（issuer と JWKS が別 origin）を拒否する。
  正典も同一 origin を要件にしていない。**正典に無い独自強化を足さない**という
  本設計の基本方針にも反していた。
- 対応内容: 同一 origin の要件を削除し、
  **各 endpoint が https の絶対 URL であること**と
  **すべて `PinnedHttpClient` を通ること**へ置き換えた。

### [Warning] キャッシュの保存スキーマ・目録登録が無い

- 判断: **対応する**
- 対応内容: discovery 文書・JWKS・再取得時刻の**保存スキーマを素の配列とスカラーで定義**し、
  キー・破損値の `forget`・`CachePayloadPlainDataGateTest` の目録登録を変更対象へ加えた。

---

## B2

### [Critical] `pinnedPost()` の契約が設計されていない

- 判断: **対応する**（B1 と同じ是正で解消）
- 対応内容: `PinnedRequest` に body を載せて `PinnedHttpClient::fetch()` する形を明記。
  pin 済み経路以外では POST できない（`Http` を名前空間から禁じる）。

### [Critical] vendor 例外の連結で body が展開されうる

- 判断: **対応する**
- 対応内容: トークン交換の境界では **vendor 例外を外へ連結しない**
  （`previous` に載せない）。固定の理由コードの例外へ変換する。
  平文を受ける引数に **`#[SensitiveParameter]`** を付ける。
  漏洩の実挙動テスト（例外・ログ・要求記録に secret / code / token が出ない）を追加した。

### [Warning] `token_endpoint_auth_methods_supported` を見ていない

- 判断: **対応する**
- 対応内容: discovery DTO に同項目を持たせ、
  **`client_secret_basic` を優先**し（body 漏洩面が小さい）、
  無ければ `client_secret_post` へ落とす。どちらも無い IdP は**拒否**する、と確定した。

---

## B3

### [Critical] JWT / JWK の拒否条件が不足

- 判断: **対応する**
- 対応内容: 使用部品を **`firebase/php-jwt` v7.0.5**（既に解決済み。`composer.json` へ明示する）と確定し、
  拒否条件を deny-by-default で列挙した:
  malformed JWT / 署名不一致 / `kid` 欠落 / **`kid` 重複** / `kty` 不整合 /
  EC の `crv` 不整合 / JWK の `use` が `sig` でない・`key_ops` に `verify` が無い /
  claim の型不正 / `exp`・`iat` の欠落。
  **戻り値を再検査してから DTO を組み立てる**責務も明記した。

### [Warning] 未知 `kid` の再取得の粒度・原子性・障害時の挙動

- 判断: **対応する**
- 対応内容: **接続 id 単位のロック**を取り、最終再取得時刻を**スカラー**で保つ。
  同時要求でも再取得が 1 回になるテストを追加。
  ロック基盤の障害時は**その試行を拒否**する（再取得を無制限に許さない）。

---

## B4

### [Critical] 期限切れの `delete()` が例外でロールバックされる

- 判断: **対応する（明確なバグ）**
- 根拠: 正しい。`DB::transaction()` の中で例外を投げると削除も巻き戻り、
  「オンアクセスで掃除する」が成立しない。
- 対応内容: トランザクションは**結果を表す値**（成功 / 期限切れ / 結合不一致 / 不在）を**返す**形にし、
  **commit の後に**拒否の例外を投げる。期限切れ行の削除は commit される。

### [Critical] 「ドライバの種別に依存しない」は誤り（SQLite）

- 判断: **対応する**
- 対応内容: 契約する DB を **pgsql** と明記した（`phpunit.xml` が
  `DB_CONNECTION=pgsql` を force しており、テストも本番も pgsql。SQLite は使わない）。
  並行の検査は **2 本の独立した接続と同期点**で行い、
  `--parallel` を同時アクセスの代用にしないと明記した。

### [Warning] ブラウザ結合の秘密の生成・保存・破棄が未定義

- 判断: **対応する**
- 対応内容: CSPRNG で 32 バイト生成／試行ごとにセッションへ保存／
  callback で取得／**成功時のみ破棄**／結合不一致では**保持**（攻撃者が被害者の結合を消せない）／
  複数タブは**試行ごとに別のキー**（`state` の指紋をセッションのキーに使う）で共存できる、と確定した。

---

## C1

### [Critical] JIT 利用者の `User` 列・`verified` middleware との整合が無い

- 判断: **対応する（施策を 1 本増やす）**
- 対応内容: 施策 **A3** を新設し、`users.email` を **nullable** にする移行と波及を設計した。
  JIT 利用者は `email = null` / `email_verified_at = now()` で作る
  （**「IdP が本人確認した。確認すべきメールが無い」**の意味）。
  これにより `hasVerifiedEmail()` が真になり、既存の `verified` middleware の意味論を**変えずに**通る。
  **仮のメール文字列を作らない**（偽のメールは nOAuth の再現と衝突の温床になる）。
  波及（`EncryptedUserProvider` / Filament / 通知 / `MassAssignmentSafetyTest`）も列挙した。

### [Critical] 初期の役割と `laratrust_team_id` の明示が無い

- 判断: **対応する**
- 対応内容: JIT の付与は **`OrganizationRole::Member`**（最小）と確定し、
  所属と役割の付与のすべてで **組織の team id を明示**する（不変条件 5）。
  別組織の役割が参照されないテストを追加した。

### [Critical] 一意制約違反を一律に身元の競合として握り潰している

- 判断: **対応する**
- 対応内容: **制約名を照合**し、`enterprise_identities` の
  `(organization_oidc_connection_id, subject_fingerprint)` の違反**だけ**を回復対象にする。
  それ以外の一意制約違反は**再送出**する、と明記した。

---

## C2

### [Critical] 開始側（redirect）の設計が無い

- 判断: **対応する**
- 対応内容: 開始側を明記した — CSPRNG 32 バイト → base64url、
  PKCE は S256、必須の要求引数（`response_type=code` / `scope=openid` /
  `client_id` / `redirect_uri` / `state` / `nonce` / `code_challenge` /
  `code_challenge_method=S256`）、保存の順序（**行を作ってからリダイレクトする**）。

### [Critical] callback の入力制約が無い

- 判断: **対応する**
- 対応内容: 専用の FormRequest で**スカラー型・長さ上限・排他**（`code` と `error` の同時受理を拒否）を検査し、
  **入力が不正なときは外向き取得を一切開始しない**ことをテストで固定する。
  IdP の `error` 応答は一様な失敗として扱う。

### [Warning] callback 時点で接続が `Active` か再確認する

- 判断: **対応する**
- 対応内容: **外向き取得の前**と**ログイン確定の直前**の 2 回、接続の状態を確かめる。

### [Warning] `remember: true` は無効化後も新しいセッションを開始できる

- 判断: **対応する**
- 対応内容: 企業 SSO は **`remember: false`** を既定にした。
  「次回ログインができなくなる」という効果の主張と、永続 cookie は整合しない。

---

## D1

### [Critical] 更新と状態遷移が整合していない

- 判断: **対応する**
- 対応内容: **表示名だけの更新**と**認証材料（issuer / client id / client secret）の更新**を分け、
  後者は **`Draft` へ戻し `verified_at` を消す**（再確認と再有効化が必須）。
  更新と状態変更を**同一トランザクション**にする。
  許す遷移に `Active`/`Verified` → `Draft`（認証材料の更新による差し戻し）を追加した。

---

## D2

### [Critical] validation error で client secret がセッションへ flash される

- 判断: **対応する**
- 対応内容: 秘密の入力名を **`dontFlash`** へ登録し、
  validation の応答・監査ログ・例外・要求の記録に含めないことを Feature テストで確認する。
  **伏字の見本をそのまま更新値として受け付けない**規則（未入力なら据え置き）も明記した。

### [Critical] `{connection}` の scoped binding の relation 名が未定義

- 判断: **対応する**
- 対応内容: `Organization::oidcConnections()` を定義し、route 引数名を `oidcConnection` に揃える
  （`scopeBindings()` が `Organization::oidcConnections()` を引く）。
  **認可より前に 404** になることを実挙動で確かめるテストを追加した。

### [Warning] `clientSecretMasked` が一覧生成時の復号を誘導する

- 判断: **対応する**
- 対応内容: DTO の項目を **`hasClientSecret: bool`** に置き換え、
  **一覧では秘密を一度も復号しない**設計にした。

### [Warning] Inertia へ渡す形の契約が無い

- 判断: **対応する**
- 対応内容: DTO に Inertia 用の `toArray()` を持たせ、
  enum は `value`、時刻は ISO 8601 文字列、キーは camelCase と確定。
  PHP の出力と TypeScript の Props が一致することをテストする。
  **JSON API は新設しない**（Inertia のみ）。

### [Warning] UI が DESIGN.md / Atomic Design の観点で判断できない

- 判断: **対応する**
- 対応内容: 既存の atoms/molecules を使う構成、design token 経由の参照（hex 直書きを増やさない）、
  Lucide のアイコン（SVG 直書きを新設しない）、
  **必須条件が未充足でもボタンを disabled にせず押下時にエラー表示する**（禁止事項 8）を明記した。

---

## E1

### [Critical] トークンの保存方式・TTL・一回使用・利用者との結合が無い

- 判断: **対応する**
- 対応内容: **原文を保存せず指紋のみ**／`user_id` を持つ／期限と消費済みの状態を持つ／
  確認時に**認証済みの利用者と一致**すること／**一回だけ consume**（B4 と同じ原子的な形）／
  再送で旧トークンが失効すること／並行確認で 1 件しか確定しないこと、を明記した。

### [Critical] `MustVerifyEmail` と昇格後の `email_verified_at` の関係

- 判断: **対応する**（A3 と一体で解決）
- 対応内容: 昇格前は `email = null` / `email_verified_at = now()`（到達可能）、
  昇格の確定で `email` が入り `email_verified_at` は**確認済みのまま**。
  昇格前はパスワード再設定が使えず、昇格後に使えるようになることをテストで固定した。

### [Warning] 利用者側の一意制約違反も対象を絞る

- 判断: **対応する**
- 対応内容: **メールの blind index の一意制約だけ**を一様な応答へ変換し、
  それ以外の違反と DB の障害は**握り潰さない**。

---

## F1

### [Critical] G2 の保証主張が実際の検出範囲より広い

- 判断: **対応する（保証外にせず、fail-closed へ倒す）**
- 根拠: AGENTS.md (b) は「保証範囲の外にした構文で保護対象の操作を書けるなら、
  検出力の主張を狭めるか、未解決として失敗させるかのどちらか」と定める。
  企業 SSO の名前空間は**自分たちが書く小さな領域**なので、
  「主張を狭める」より「**未解決を落とす**」ほうが安く強い。
- 対応内容: G1〜G5 は走査根の中で**解決できない呼び出し（変数経由・動的呼び出し・
  可変クラス名）を検出したら gate を失敗させる**。
  「保証外」と書いて見逃す形を採らない。

### [Warning] G3 の語彙走査だけでは別名の項目・配列キーで漏れる

- 判断: **対応する**
- 対応内容: 語彙の走査に加え、**対象の型（DTO / 例外 / FormRequest）の
  構築子引数・公開項目・直列化の形を型単位で検査**する。
  **主たる証明は実挙動の漏洩テスト**（B2・D2）に置くと gate 自身が宣言する。

---

## F2

### [Warning] route 数の説明が不整合

- 判断: **対応する**
- 対応内容: **10 route すべて**を「named limiter を持つ / exemption / 母集団外」で分類する表を作った。

### [Warning] 開始 route は GET だが DB を変える（副作用付き GET）

- 判断: **対応する**
- 対応内容: **OAuth の開始 GET** として理由付きで明示分類し、
  CSRF の代替となる `state`・ブラウザ結合・流量制限・`no-store` を
  Feature / Architecture のテストで固定する、と明記した。

---

## F3

### [Warning] D37 の対象パスが機構全体を覆っていない

- 判断: **対応する**
- 対応内容: Factory・`ConsumedLoginAttempt`・`routes/console.php`・対応テストまで対象パスへ含めた。
  ただし `routes/console.php` は**既存ファイルであり他の逸脱の対象パスと重複しない**ことを
  マージ直前に確認する（登録の和集合で重複しないという値域の要件）。

---

## F4

### [Critical] 偽 IdP の route が本番へ混入しうる

- 判断: **対応する**
- 対応内容: **production 相当の環境で偽の route が route の一覧に存在しない**ことと、
  フラグ無効時に **route も結線も存在しない**ことを Architecture / Feature のテストで固定する。

### [Warning] 偽への全面差し替えだけでは pin 済み経路を往復検査できない

- 判断: **対応する**
- 対応内容: 偽の IdP の往復検査とは**別に**、
  実装（`OidcDiscoveryService` / `OidcTokenExchanger`）が
  **`PinnedHttpClient` を通ること**を、ssrf-pin のテスト seam で検査する。

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
| **2 要素の組織義務づけ** | `RequireTwoFactorForEnforcedOrganizations` の転送先は `settings.security`（**設定ページ**であって入力画面ではない） |

## 正典の不変条件（全列挙。すべて本設計が満たす）

| # | 不変条件 | 本設計での保証機構 |
|---|---|---|
| I1 | **メールアドレスで利用者を引かない**（引き当ての鍵は接続 × subject の指紋） | A2 の列設計 + C1 + gate G1 |
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
| A1 | 設定ファイル・値域 enum・指紋の鍵導出 | `config/enterprise-sso.php`, `app/Enums/EnterpriseSso/*`, `app/Support/EnterpriseSso/AttemptFingerprint.php` | High |
| A2 | モデル 3 本 + 移行 3 本 + Factory 3 本 + 秘密の値型 | `app/Models/*`, `database/migrations/*`, `database/factories/*`, `app/Casts/*`, `app/ValueObjects/*` | High |
| A3 | `users.email` の nullable 化（企業 SSO のみの利用者） | `database/migrations/*`, `app/Models/User.php`, `app/Auth/EncryptedUserProvider.php` ほか波及 | High |
| B1 | 接続先情報と鍵の取得（`PinnedHttpClient` 一本化） | `app/Services/EnterpriseSso/OidcDiscoveryService.php` ほか DTO | High |
| B2 | トークン交換（body 付き pinned 要求） | `app/Services/EnterpriseSso/OidcTokenExchanger.php` ほか DTO | High |
| B3 | ID トークンの検証（`firebase/php-jwt`） | `app/Services/EnterpriseSso/EnterpriseIdTokenVerifier.php` ほか DTO, `composer.json` | High |
| B4 | ログイン試行の保管（原子的 consume + ブラウザ結合） | `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php` ほか, `routes/console.php` | High |
| C1 | 利用者の自動作成 (always-JIT) | `app/Services/EnterpriseSso/EnterpriseUserProvisioner.php` | High |
| C2 | 開始と戻り口・controller・route 3 本 | `app/Services/EnterpriseSso/EnterpriseCallbackAuthenticator.php`, `app/Http/Controllers/Auth/EnterpriseSsoLoginController.php`, `app/Http/Requests/Auth/*`, `routes/web.php` | High |
| D1 | 接続の状態遷移サービス | `app/Services/EnterpriseSso/OidcConnectionTransitionService.php` | High |
| D2 | 組織側の接続管理 controller・route 7 本・画面 | `app/Http/Controllers/Organizations/*`, `app/Http/Requests/Organizations/*`, `resources/js/pages/Organizations/Sso/Index.svelte`, `routes/web.php` | High |
| E1 | メールアドレスの昇格フロー（**Auth 名前空間**） | `app/Services/Auth/EmailPromotionService.php` ほか + 移行 1 本 + Factory 1 本 | Medium |
| F1 | gate 5 本（G1〜G5）+ 走査器 | `tests/Architecture/*`, `tests/Support/EnterpriseSso/*`, `tests/Unit/Architecture/*` | High |
| F2 | aicue 側の目録登録（10 route の全分類） | `app/Enums/Security/*`, `tests/Support/*`, `tests/Architecture/*` | High |
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

### 指紋の鍵の出所（Codex Round 1 の指摘への回答）

```php
/**
 * 試行の指紋の導出。**用途ごとに domain separation する**。
 *
 * 鍵は **APP_KEY から用途別ラベル付きで導出する** (HKDF)。専用の秘密を新設しない —
 * 運用要件を 1 つ増やす価値が無い (思考原則 2)。判断の根拠:
 *   APP_KEY をローテートすると失効するのは **進行中の試行 (TTL 10 分) だけ**である。
 *   身元・接続・利用者はどれも指紋に依存しないので、失うものが他に無い。
 *   (対比: パスキーの利用者ハンドルは APP_KEY 由来だと**登録済みパスキーが全件無効**になるため
 *    専用の秘密を要求している。ここはその条件に当たらない。)
 *
 * ラベルは 4 種で相互に使い回せない:
 *   'enterprise-sso.state' / '.nonce' / '.browser-binding' / '.subject'
 */
final class AttemptFingerprint
{
    public static function of(FingerprintPurpose $purpose, #[SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, self::key($purpose));
    }
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
    `discovery.max_body_bytes <= 1048576`／`token.max_body_bytes <= 262144`
  - `jwks_refetch_min_interval_seconds >= 1`
- [ ] 新規 `tests/Unit/Enums/OidcSigningAlgorithmTest.php` — `none` / `HS256` が `tryFrom()` で null（負のコントロール）
- [ ] 新規 `tests/Unit/Support/AttemptFingerprintTest.php` —
      **同じ入力でも用途が違えば別の指紋になる**（domain separation の実挙動）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- `APP_KEY` のローテートで進行中の試行が失効する。**これは受容した判断**であり docblock に根拠を書く。

---

## A2: モデル 3 本 + 移行 3 本 + Factory 3 本 + 秘密の値型

### 変更箇所
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

    // IdP の subject の原文 (監査・表示用)。**引き当てには使わない**。
    $table->string('subject', 255);

    // ★引き当てと一意制約は **バイト列由来の keyed fingerprint** で行う。
    //   照合順序 (collation) に依存しないため、`Alice` と `alice` が
    //   **確実に別の身元**になる。列の照合順序の設定ミス 1 つで
    //   「別人が同じアカウントに入る」ことが起きない形にする。
    $table->char('subject_fingerprint', 64);

    // ★申告メール: 暗号化して持つが **索引を意図的に付けない**。
    //   索引を付けると「メールで引ける」経路が実装として復活し、正典 v1 の I1/I2 が崩れる。
    //   blind index も付けない (configureCipherSweet で addBlindIndex を呼ばない)。
    $table->text('claimed_email_encrypted')->nullable();

    $table->timestamp('last_login_at')->nullable();
    $table->timestamps();

    // 並行初回ログインでの二重作成を DB で止める (C1 の競合対策の本体)。
    // ★制約名を明示する — C1 が**この制約の違反だけ**を回復対象にするため。
    $table->unique(
        ['organization_oidc_connection_id', 'subject_fingerprint'],
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
 * ★`__debugInfo()` は伏字を返す (dd / var_dump / 例外のスタックでも平文が出ない)。
 * ★平文の取り出しは {@see self::revealForTokenExchange()} の 1 メソッドだけである。
 *   このメソッドを呼んでよいのは OidcTokenExchanger だけであり、
 *   tests/Architecture/EnterpriseSsoSecretExposureGateTest が呼び出し元を exact-fit で pin する。
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

    /** @return array{client_secret: string} */
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
     *   引き当ての鍵は (organization_oidc_connection_id, subject_fingerprint) だけである。
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
  - **`Alice` と `alice` が別の身元になる**（指紋がバイト列由来であることの実挙動）
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseIdentityCipherSweetTest.php` — 申告メールが平文で保存されない
- [ ] 新規 `tests/Unit/ValueObjects/ConnectionSecretTest.php`
  - **`__toString()` を持たない**（`method_exists` が false）
  - `__debugInfo()` / `var_export` / `json_encode` に平文が出ない
- [ ] Factory 3 本が `RefreshDatabase` 下で動く
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **移行 3 本目（試行表）はテンプレートに無い上積み**である → F3 で逸脱 D37 として登録する。
- `cascadeOnDelete` により組織削除で接続・身元が消える。**利用者は消えない**（他のログイン手段が残りうる）。この非対称は意図であり、E1 の昇格が「離脱後も自分のアカウントを保てる」ための前提になる。

---

## A3: `users.email` の nullable 化（企業 SSO のみの利用者）

### 変更箇所
- 新規: `database/migrations/2026_08_23_000050_make_users_email_nullable.php`
- 変更: `app/Models/User.php`（`email` の型注釈・`$fillable` の扱い）
- 変更: `app/Auth/EncryptedUserProvider.php`（null のメールで引かれない）

### なぜ必要か（Codex Round 1 の [Critical] への回答）

企業 SSO でしか入れない利用者は **使えるメールアドレスを 1 件も持たない**。
選択肢は 3 つあり、採るのは (c) である:

| 案 | 判断 |
|---|---|
| (a) 仮のメール文字列を作る（`sub@example.invalid` 等） | **採らない**。偽のメールは nOAuth の再現面と衝突の温床になり、通知の誤送先にもなる |
| (b) `hasVerifiedEmail()` を認証方式込みで再定義する | **採らない**。既存の `verified` middleware の意味論を変えるのは波及が広すぎる |
| (c) **`email` を nullable にし、`email_verified_at` は now() で作る** | **採る**。「IdP が本人確認した。**確認すべきメールが無い**」の意味であり、`hasVerifiedEmail()` は既存の実装のまま真になる。middleware の意味論を変えない |

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
 * 規則: https のみ / userinfo なし / query なし / fragment なし /
 *       正規化できる絶対 URL / 末尾のスラッシュを正規化 / 長さ上限。
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
     *  4. **endpoint は https の絶対 URL** — ★同一 origin は**要求しない**。
     *     OIDC 標準の要件ではなく、実在の IdP (issuer と JWKS が別 origin) を拒否する。
     *     正典も同一 origin を要件にしていない。各 endpoint は個別に pin 済み経路を通る
     *  5. **応答サイズ上限** — 期待と違う応答を DTO に固定しない
     *
     * @throws EnterpriseSsoAttemptRejectedException 機械可読な理由文字列のみを持つ
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
    /** @param non-empty-list<TokenEndpointAuthMethod> $tokenEndpointAuthMethods */
    private function __construct(
        public OidcIssuerUrl $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public array $tokenEndpointAuthMethods,
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
            // ★previous に載せない (body が例外の連鎖で展開されうる)。
            throw new EnterpriseSsoAttemptRejectedException('discovery_not_json');
        }

        if (! is_array($decoded)) {
            throw new EnterpriseSsoAttemptRejectedException('discovery_not_object');
        }

        $issuer = OidcIssuerUrl::fromString(self::requireString($decoded, 'issuer'));
        if (! hash_equals($expectedIssuer->value, $issuer->value)) {
            throw new EnterpriseSsoAttemptRejectedException('discovery_issuer_mismatch');
        }

        // 各 endpoint は https の絶対 URL であること (同一 origin は要求しない)。
        $authorization = self::requireHttpsUrl($decoded, 'authorization_endpoint');
        $token = self::requireHttpsUrl($decoded, 'token_endpoint');
        $jwks = self::requireHttpsUrl($decoded, 'jwks_uri');

        // ★対応する client 認証方式を確かめる。basic を優先し、無ければ post。
        //   どちらも無い IdP は拒否する (v1 の対応範囲を型で表す)。
        $methods = self::supportedAuthMethods($decoded);
        if ($methods === []) {
            throw new EnterpriseSsoAttemptRejectedException('discovery_no_supported_auth_method');
        }

        return new self($issuer, $authorization, $token, $jwks, $methods);
    }
}
```

### キャッシュの保存スキーマ（不変条件 11）

| キー | 値 |
|---|---|
| `enterprise-sso:metadata:{issuer の sha256}` | `array{issuer: string, authorization_endpoint: string, token_endpoint: string, jwks_uri: string, auth_methods: list<string>}`（**素の配列とスカラーのみ**） |
| `enterprise-sso:jwks:{issuer の sha256}` | `array<int, array<string, string>>`（JWK の必要要素のみ。**素の配列**） |
| `enterprise-sso:jwks-refetched-at:{接続 id}` | `int`（UNIX 時刻。**スカラー**） |

読み戻しは **DTO へ明示的に組み立て直して検査**し、失敗したら `forget` して miss 扱いにする。
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
  - endpoint が http なら拒否する
  - 3xx 応答を**成功として扱わない**
  - サイズ上限超過を拒否する
  - JSON でない / オブジェクトでない応答を拒否する
  - 対応する client 認証方式が無い IdP を拒否する
  - **キャッシュの破損値を読み戻したら `forget` して取り直す**
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcDiscoveryPinnedPathTest.php` —
      **実装が `PinnedHttpClient` を通る**（ssrf-pin のテスト seam で観測。F4）
- [ ] 新規 `tests/Unit/ValueObjects/OidcIssuerUrlTest.php` —
      http / userinfo つき / query つき / fragment つき / 相対 URL を拒否する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- pin 済み経路に一本化することで、`PinnedHttpClient` の障害が discovery の単一障害点になる。
  これは**意図した集約**であり、迂回路を作らないことが不変条件 I3 の実体である。

---

## B2: トークン交換（body 付き pinned 要求）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/OidcTokenExchanger.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/OidcTokenResponse.php`

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
 *     境界で**固定の理由コードの例外**へ変換する
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
            $headers['Authorization'] = 'Basic '.base64_encode(
                rawurlencode($connection->client_id).':'
                .rawurlencode($connection->clientSecret()->revealForTokenExchange())
            );
        } else {
            $form['client_secret'] = $connection->clientSecret()->revealForTokenExchange();
        }

        try {
            $response = $this->pinned->fetch(
                PinnedRequest::post($metadata->tokenEndpoint, $form, $headers)
                    ->withoutRedirects()
                    ->withMaxBodyBytes(Config::integer('enterprise-sso.token.max_body_bytes')),
                Deadline::in(Config::integer('enterprise-sso.token.request_timeout_seconds')),
            );
        } catch (Throwable) {
            // ★previous に載せない。理由コードだけを外へ出す。
            throw new EnterpriseSsoAttemptRejectedException('token_exchange_failed');
        }

        return OidcTokenResponse::fromPinnedResponse($response);
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
  - 3xx を成功として扱わない
  - **例外文言・例外の連鎖・ログ・要求の記録に client secret / 認可コード / トークンが出ない**
    （G3 の実挙動側の裏取り。**主たる証明はここにある**）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 前段の版上げが未了だと本施策は着手できない（TODO の依存に明記する）。

---

## B3: ID トークンの検証（`firebase/php-jwt`）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseIdTokenVerifier.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/VerifiedIdTokenClaims.php`
- 変更: `composer.json`（`firebase/php-jwt` を**直接依存として明示**。既に v7.0.5 が解決済み）

### 拒否条件（deny-by-default。1 つでも該当したらその試行を拒否）

| 層 | 拒否する条件 |
|---|---|
| JWT の形 | malformed（3 セグメントでない / base64url でない / ヘッダが JSON でない） |
| ヘッダ | `alg` が `OidcSigningAlgorithm` の case でない（`none` / HMAC は enum に無い）／`kid` の欠落 |
| JWKS | `kid` に一致する鍵が無い（→ **再取得を 1 回だけ**）／**`kid` の重複**／`kty` が `alg` と不整合／EC の `crv` が `alg` と不整合／`use` が `sig` でない／`key_ops` があって `verify` を含まない |
| 署名 | 検証に失敗した |
| claim の型 | `iss` / `sub` / `nonce` が文字列でない／`aud` が文字列でも文字列配列でもない／`exp` / `iat` / `nbf` が整数でない |
| claim の値 | `iss` が登録済み issuer と不一致／`aud` に自分の client_id を含まない／複数 audience または `azp` があるのに `azp` != client_id／`sub` が空・長さ超過／**`exp` の欠落**／**`iat` の欠落**／`exp` 超過／`iat` が未来／`nbf` が未来（いずれも `leeway_seconds` の範囲で）／`nonce` の指紋が試行と不一致 |

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
      複数 audience で `azp` 不一致 / `sub` 欠落・空・長さ超過 / `exp` 超過 / `nonce` 不一致）
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
 *  - callback で取り出して照合する
 *  - **成功時のみ破棄**する。結合不一致では**破棄しない**
 *    (攻撃者が被害者の結合を消せる形にしない)
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
     *   結果を表す値を返し、**commit の後に**拒否の例外を投げる。
     */
    public function consume(string $state, #[SensitiveParameter] string $browserBindingSecret): ConsumedLoginAttempt
    {
        $result = DB::transaction(function () use ($state, $browserBindingSecret): AttemptConsumeResult {
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

            if ($row->delete() !== true) {
                return AttemptConsumeResult::consumeFailed();
            }

            // 行をそのまま外へ出さない。具体型・期限・復号結果を検査して DTO へ畳む。
            return AttemptConsumeResult::consumed(ConsumedLoginAttempt::fromModel($row));
        });

        // ★commit の後に拒否する。理由コードは一様な失敗としてしか外へ出さない。
        return $result->attemptOrFail();
    }
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`first()` の null を早期に処理。アーリーリターン）
- [x] DTOを返している（`ConsumedLoginAttempt`。Eloquent モデルを外へ出さない）
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseLoginAttemptStoreTest.php`
  - **2 本の独立した DB 接続と同期点**で、1 本目が行ロックを保持している間に 2 本目を開始し、
    **片方だけが成功する**（`--parallel` を同時アクセスの代用にしない）
  - **別のブラウザで callback URL を開くと失敗する**（login CSRF。結合不一致で拒否）
  - 結合不一致では**行が消えない**（他人の試行を消せない）
  - **期限切れの行は拒否と同時に消える**（トランザクションが巻き戻らないことの回帰）
  - 用途別の指紋が相互に使い回せない（`state` の指紋を結合の指紋として使えない）
  - 複数タブで同時に開始しても互いの結合の秘密を壊さない
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
 * 事前検索だけでは、同一 (接続 id, subject) の並行 callback で
 * 利用者・身元・所属が二重に作られる。したがって:
 *  - 身元表の enterprise_identities_connection_subject_unique を根拠にする
 *  - 利用者の作成・身元の作成・組織所属の作成を **1 トランザクション**に束ねる
 *  - ★**その制約の違反だけ**を回復対象にする。制約名を照合し、
 *    他の一意制約違反 (users の blind index 等) は**再送出**する
 *    (握り潰すと別の不具合を「競合」として隠す)
 *  - 回復時はトランザクション全体がロールバック済みなので**孤児は残らない**
 */
public function resolve(OrganizationOidcConnection $connection, VerifiedIdTokenClaims $claims): User
{
    $fingerprint = AttemptFingerprint::of(FingerprintPurpose::Subject, $claims->subject);

    $existing = $this->findIdentity($connection, $fingerprint);
    if ($existing !== null) {
        return $existing->user;   // アーリーリターン
    }

    try {
        return DB::transaction(fn (): User => $this->createUserWithIdentityAndMembership($connection, $claims, $fingerprint));
    } catch (UniqueConstraintViolationException $e) {
        // ★制約名を照合する。身元表の競合**だけ**を回復対象にする。
        if (! $this->isIdentityUniqueViolation($e)) {
            throw $e;
        }

        $identity = $this->findIdentity($connection, $fingerprint);
        if ($identity === null) {
            throw new EnterpriseSsoAttemptRejectedException('provision_conflict_unresolved');
        }

        return $identity->user;
    }
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
  - **並行初回ログインでも 1 利用者・1 身元・1 所属だけが成立する**（2 接続 + 同期点）
  - 競合で失敗した側に**孤児の利用者が残らない**
  - **身元表以外の一意制約違反は再送出される**（握り潰さないことの負のコントロール）
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
 *  3. **consume** (行ロック。トランザクションを閉じる) —
 *     ロックの保持中に外向き HTTP を行うと、ロックが外部の応答時間に引きずられる
 *  4. **接続が Active か再確認** (開始後に運営が無効化した場合にログインさせない)
 *  5. 外向き取得 (discovery → token 交換 → JWKS)
 *  6. ID トークンの検証
 *  7. JIT
 *  8. **確定の直前にもう一度 Active か確認**
 *  9. Auth::login(remember: false) → session()->regenerate() → ブラウザ結合の秘密を破棄
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
  - **開始後に接続を無効化するとログインできない**（外向き取得の前と確定の直前の 2 か所）
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
 *   Verified/Active/Disabled → Draft  (★**認証材料を更新した**)
 *
 * ## 認証材料の更新は必ず Draft へ戻す
 *
 * issuer / client_id / client_secret のいずれかを変えたのに Active のままだと、
 * **未検証の構成で直ちにログインできる**。したがって:
 *  - 表示名だけの更新は状態を変えない
 *  - **認証材料の更新は Draft へ戻し、verified_at を消す** (再確認と再有効化が必須)
 *  - 更新と状態変更は **同一トランザクション**で行う (片方だけが残る窓を作らない)
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
  - **認証材料を更新すると Draft へ戻り `verified_at` が消える**
  - **表示名だけの更新では状態が変わらない**
  - **Active の接続の issuer を変えた直後はログインできない**（実挙動の裏取り）
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
- 変更: `routes/web.php`, `app/Providers/AppServiceProvider.php`（`dontFlash` の登録が必要なら）

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
  - 権限のないメンバーは 403（`Gate::authorize`）
  - **更新系 6 route すべてが再認証なしで弾かれる**
  - **validation 失敗時に client secret がセッションへ残らない**（`dontFlash`）
  - **伏字の見本を送っても秘密が上書きされない**（未入力は据え置き）
  - **一覧の生成が秘密を一度も復号しない**（復号を観測する seam で検査）
  - 応答・Inertia props に client secret の原文が出ない
  - 確認 (`verify`) が専用の流量制限を持ち、他の管理操作と bucket を共有しない
  - **認証材料を更新すると一覧の状態が Draft になる**（D1 との結線）
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
- 新規: `app/Mail/EmailPromotionMail.php`
- 新規: `app/Exceptions/Auth/EmailPromotionConflictException.php`
- 新規: `app/DataTransferObjects/Auth/VerifiedEmail.php`

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

### トークンと状態（[Critical] への回答）

| 項目 | 形 |
|---|---|
| トークン | **原文を保存せず指紋のみ**（B4 と同じ用途ラベル付きの導出） |
| 結合 | `user_id` を持ち、確認時に**認証済みの利用者と一致**すること |
| 期限 | `expires_at`（設定値） |
| 一回使用 | **B4 と同じ原子的な形**（`SELECT … FOR UPDATE` → 検査 → `DELETE` → commit の後に拒否） |
| 再送 | 新しいトークンを発行したら**旧トークンを失効させる**（同一利用者の未消費行を消す） |

### 昇格の条件と衝突

- **本人確認**: 確認メールのトークンを踏んだときにだけ確定する（IdP の申告メールをそのまま昇格させない）
- **認可**: 対象は**認証済みの自分自身のみ**
- **監査**: 変更を記録する（既存の監査基盤へ載せる）
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

### (b) を「保証外」ではなく fail-closed で満たす（Codex Round 1 の [Critical] への回答）

正典の G2 は「変数経由の間接呼び出しは検出できない」と自ら書いている。
しかし AGENTS.md (b) は「**保証範囲の外にした構文で保護対象の操作を書けるなら、
検出力の主張を狭めるか、未解決として失敗させるかのどちらか**」と定める。
外向き HTTP は変数経由でも書けるので、「保証外」と書くだけでは規約に適合しない。

**採る形**: `App\Services\EnterpriseSso` は**自分たちが書く小さな領域**なので、
「主張を狭める」より「**未解決を落とす**」ほうが安く強い。

- 走査根の中で**解決できない呼び出し**（変数経由のメソッド呼び出し・
  可変クラス名・`call_user_func` 系・文字列からの生成）を検出したら **gate を失敗させる**
- 未解決を**無言で候補から外さない**
- したがって「素の HTTP 呼び出しが無い」という主張は**走査根の中で成立する**
- G1・G3・G4・G5 も同じ扱いにする

### 各 gate が固定するもの

| gate | 固定する内容 |
|---|---|
| G1 | 企業 SSO の名前空間・controller・身元モデルに**メールで利用者を引く記法**が無い（`whereBlind('email', …)` を含む）。加えて**申告メールの列（または対応する blind index）を含む索引が 0 本**であることを**スキーマの読み取りだけ**で確かめる（破壊操作を伴わない = 禁止事項 3） |
| G2 | `App\Services\EnterpriseSso` 配下に **`Http` ファサード・`HttpFactory` の使用が無い**（許可一覧を持たない）。外向きは `PinnedHttpClient` だけ。**自動リダイレクト追従を有効にする記法**も無い |
| G3 | 接続の秘密が**受け渡しの型に存在しない**（語彙の走査に加え、**対象の型の構築子引数・公開項目・直列化の形を型単位で検査**する）。例外が**機械可読な理由文字列だけ**を持つ。`ConnectionSecret::revealForTokenExchange()` の**呼び出し元を exact-fit で pin**（トークン交換 1 本だけ）。**主たる証明は実挙動の漏洩テスト（B2・D2）にあると gate 自身が宣言する** |
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
- [ ] 走査器の自己検査で「変数経由の呼び出しを**未解決として落とす**」ことを固定する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **走査器を 1 本に共有する**ので、走査根の定義を誤ると 5 本が同時に空振りする。3 の空振り検査がその唯一の防波堤になる。

---

## F2: aicue 側の目録登録（10 route の全分類）

### 変更箇所
- 変更: `app/Enums/Security/ThrottleCoverageExemption.php`（既存 case で足りるなら足さない）
- 変更: `app/Enums/Security/ControllerAuthorizationExemption.php`（同上）
- 変更: `tests/Support/Routing/NestedRouteDefenseInventory.php`
- 変更: `tests/Architecture/RecentAuthRouteTest.php`
- 変更: `tests/Architecture/CachePayloadPlainDataGateTest.php` の目録（B1 のキャッシュ経路）
- **変更しない**: `tests/Architecture/TwoFactorEnforcementAllowlistTest.php` / `app/Enums/Account/AccountDeletionFreezeAllowance.php`

### 追加する 10 route の全分類（[Warning] への回答）

| # | route | throttle | 認可 | nested 防御 | recent-auth |
|---|---|---|---|---|---|
| 1 | `enterprise-sso.login` | **持たない** → exemption（外向き通信をしない開始画面。GET・DB を変えない） | 母集団外（GET） | parameter なし | 不要（未認証面） |
| 2 | `enterprise-sso.redirect` | `throttle:enterprise-sso-start` | **母集団外だが明示分類する**（下記） | `{connectionSlug}` は `NonResourceParameter`（組織に属さない公開の識別名） | 不要（未認証面） |
| 3 | `enterprise-sso.callback` | `throttle:enterprise-sso-callback` | 母集団外（GET） | parameter なし | 不要（未認証面） |
| 4 | `organizations.sso.index` | **持たない** → exemption（読み取り専用。既存の一覧 route と同じ扱い） | 母集団外（GET） | `{organization}` = `ScopedBinder` | 不要（閲覧のみ） |
| 5 | `organizations.sso.store` | `throttle:enterprise-sso-manage` | `Gate::authorize` | `{organization}` = `ScopedBinder` | **必要** |
| 6 | `organizations.sso.update` | 同上 | `Gate::authorize` | + `{oidcConnection}` = `ScopeBindings` | **必要** |
| 7 | `organizations.sso.verify` | `throttle:enterprise-sso-verify`（専用） | `Gate::authorize` | 同上 | **必要** |
| 8 | `organizations.sso.activate` | `throttle:enterprise-sso-manage` | `Gate::authorize` | 同上 | **必要** |
| 9 | `organizations.sso.disable` | 同上 | `Gate::authorize` | 同上 | **必要** |
| 10 | `organizations.sso.destroy` | 同上 | `Gate::authorize` | 同上 | **必要** |

→ named limiter を持つのは **8 本**（2・3・5〜10）。exemption は **2 本**（1・4）。

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
| 対象パス | `app/Models/EnterpriseSsoLoginAttempt.php` / `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php` / `app/DataTransferObjects/EnterpriseSso/ConsumedLoginAttempt.php` / `app/DataTransferObjects/EnterpriseSso/AttemptConsumeResult.php` / `app/Console/Commands/EnterpriseSso/PruneLoginAttempts.php` / `database/migrations/2026_08_23_000300_create_enterprise_sso_login_attempts_table.php` / `database/factories/EnterpriseSsoLoginAttemptFactory.php` / `tests/Feature/EnterpriseSso/EnterpriseLoginAttemptStoreTest.php` / `tests/Feature/EnterpriseSso/PruneLoginAttemptsTest.php` |
| 業務要件起因の説明 | 正典はログイン試行の保管先を表として持たない。aicue は `state` の使用権の唯一性を**セッションドライバの種別と `->block()` の書き忘れに依存させない**ため、DB の一意制約と行ロックへ寄せた |
| 揃え続ける不変条件と保証機構 | 「同じ試行の使用権をちょうど 1 つの要求だけが得る」「その試行を開始したブラウザだけが使える」を `EnterpriseLoginAttemptStoreTest` の並行検査と別ブラウザ検査が固定する |
| 再判定の条件 | 本形が正典へ還流されて正典側の版が上がったら、独自差分ではなく**新しい正典追従**になるので登録を消す。また正典が同等の原子性とブラウザ結合を別方式で持ったときも見直す |
| 決めた日 | `2026-08-23` |
| 決めた人 | `開発者` |
| 根拠 | `devnotes/20260823-0015-enterprise-oidc-sso-adoption/` |
| 状態 | `監視中` |
| 見直し期限 | `2027-08-23`（基準日から 400 日以内） |

> `routes/console.php` は**対象パスに入れない** — 既存ファイルであり、
> 掃除の登録 1 行のためにファイル全体を D37 の対象にすると、
> 「全登録の和集合で重複しない」という値域の要件を将来の登録と衝突させる。

### テスト計画
- [ ] `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が緑（9 行ちょうど・順序・値域・**和集合で重複しない**）
- [ ] `tests/Architecture/TemplateDivergenceFingerprintTest.php` が緑（件数 3 点一致）
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
| 判断根拠 | (1) 新規ファイルが 45 本前後、`routes/web.php` に 10 route を足し、7 つの目録と 2 つの台帳ファイルを触る。(2) **前段依存**（`kent013/laravel-ssrf-pin` の `^0.4` 化）が別 TODO で先行するため、その完了を待って独立ブランチで積む必要がある。(3) A3（`users.email` の nullable 化）は既存テーブルへの変更で、PHPStan の洗い出しが広範囲に及ぶ。(4) gate 5 本をテストファーストで赤→緑にする工程が長く、他施策と混ぜると赤の出所が分からなくなる |
| 競合リスク | `routes/web.php` / `app/Models/User.php` / `tests/Support/Routing/NestedRouteDefenseInventory.php` / `tests/Architecture/RecentAuthRouteTest.php` / `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` は**他の TODO も触る中心ファイル**である。とくに `LedgerPins.php` の件数 pin は他の逸脱登録と衝突しやすいので、マージ直前に件数を取り直す |

### 段の順序（直列。前段が緑になってから次へ）

| 段 | 施策 | 前提 |
|---|---|---|
| 前段 | — | `ssrf-pin-v04-upgrade` の完了（受入条件 3 点: GET の本文取得 / **body 付き POST の本文取得** / どちらも SSRF 判定を通る） |
| A | A1・A2・A3・F3 | 前段 |
| B | B1・B2・B3・B4・F4 | A |
| C | C1・C2 | B |
| D | D1・D2 | C |
| E | E1 | D（A3 と一体で意味を持つ） |
| F | F1・F2 | 各段が自分の gate を持って緑にしたうえで、取りまとめる |

> **gate は最後にまとめて足さない**。各段が自分の gate を同じ変更で持って緑にする
> （禁止事項 1: 不変条件は対応するテストへの登録まで含めて「実装済み」）。
> F は目録の登録漏れを閉じる取りまとめの段である。

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

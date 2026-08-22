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

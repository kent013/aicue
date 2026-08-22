# 対応マトリクス: design-review Round 2

## 追加で実読して確定した前提

| 確認事項 | 結果 |
|---|---|
| 並行テストのハーネス | **別 TODO `process-concurrency-harness-adoption` が設計済み**（`devnotes/20260822-2315-…`）。正典 `process-concurrency-test-harness` v1 の 6 要素（子プロセスの入口 / **transaction 外フィクスチャ** / 子のキャッシュを配列固定 / 合図待ちでファイル状態のキャッシュを捨てる / 締切つきの待ち / 実プロセス版は 1 本）をテンプレートからバイト一致で取り込む形。**本設計の並行テストはこのハーネスに乗る** |
| `dontFlash` の実績 | リポジトリに**使用実績なし**。Laravel 12 の作法は `bootstrap/app.php` の `withExceptions()` 内 `$exceptions->dontFlash([...])` |
| モデルの文書 | `docs/architecture.md` / `docs/factories.md` が実在する（新モデルの登録先） |

---

## A1

### [Critical] `subject_fingerprint` を `APP_KEY` 由来にするとアカウントが分裂する

- 判断: **対応する（指摘は正しい。設計の誤り）**
- 根拠: `state` / `nonce` / ブラウザ結合は**寿命 10 分の一時値**なので `APP_KEY` 由来でよいが、
  `subject` の指紋は**身元の主キー**であり永続する。`APP_KEY` をローテートすると
  既存の指紋を再現できず、次回ログインで**別の利用者・別の身元が JIT で作られる**。
  「失効するのは進行中の試行だけ」という根拠が成立しなくなっていた。
- 対応内容: **指紋をやめる**。`subject` 列そのものに
  **pgsql の `COLLATE "C"`（バイト単位の照合）** を与え、
  `(organization_oidc_connection_id, subject)` に一意制約を張る。
  - 鍵が要らない → ローテーションの問題が**構造ごと消える**
  - 照合順序を列の定義で固定するので、`Alice` と `alice` は**確実に別の身元**になる
  - `AttemptFingerprint` は**一時値だけ**を扱う型になり、名前と役割が一致する
  - 追加の検査: **列の照合順序が `C` であること**をスキーマの読み取りで固定する
    （設定が外れたら赤になる。実挙動の `Alice` ≠ `alice` と二層で守る）

### [Warning] メール昇格のトークンの用途ラベルが一覧に無い

- 判断: **対応する**
- 対応内容: 用途を `State` / `Nonce` / `BrowserBinding` / **`EmailPromotionToken`** の 4 種にした
  （`Subject` は上記のとおり削除）。用途を流用できないことをテストで固定する。

---

## A2

### [Critical] `__debugInfo()` は `var_export` から秘密を隠さない

- 判断: **対応する（成立しない保証を撤回する）**
- 根拠: 正しい。`__debugInfo()` が効くのは `var_dump()` 系だけで、
  `var_export()` / `serialize()` / Reflection は private の値を出す。
  「任意の PHP の内省から隠す」保証は置けない。
- 対応内容: docblock の保証を **`var_dump()` 系に限定**し、
  「`var_export` にも出ない」というテストと主張を**削除**した。
  代わりに:
  - **秘密の値型をログ・dump・直列化の関数へ渡す記法を G3 が禁じる**
  - **主たる証明は実挙動の漏洩テスト**（例外・監査・ログ・要求の記録）に置く
  - 保証しないもの（Reflection・`var_export`・`serialize`）を docblock に明記する

### [Warning] 3 モデルの casts / hidden / relation / Factory generics が本文に無い

- 判断: **対応する**
- 対応内容: モデルごとの表を作り、cast（暗号化 / enum / `immutable_datetime`）・
  `$hidden`・relation・Factory の generics を明記した。
  **`toArray()` から暗号文も秘密の値型も出ない**ことをテストで固定する。

---

## A3

### [Warning] `email = null` + `email_verified_at = now()` は、後で email だけが入ると自動で確認済みになる

- 判断: **対応する**
- 対応内容: **null → 非 null の更新経路を棚卸し**し、
  **メール昇格以外の経路では `email_verified_at` を必ず消す**ことを固定した。
  昇格の確定では「以前の値を維持する」のではなく
  **新しいメールを実際に確認した時刻へ更新する**（監査上の意味を保つ）。

### [Warning] 新モデルの文書更新が施策に無い

- 判断: **対応する**
- 対応内容: `docs/architecture.md` と `docs/factories.md` への **4 モデルの追加**を
  A2・E1 の変更対象へ入れた。`EmailPromotion` も `MassAssignmentSafetyTest` の母集団に入る。

---

## B1

### [Critical] issuer の末尾スラッシュ正規化は完全一致要件を弱める

- 判断: **対応する**
- 根拠: 正しい。`https://idp.example/tenant` と `…/tenant/` は別の識別子になりうる。
- 対応内容: **正規化しない**。登録した文字列をそのまま識別子として保ち、
  discovery 文書の `issuer` と**仕様どおり完全一致**させる。
  well-known URL の組み立ては「issuer のパスの**後ろに** `/.well-known/openid-configuration` を付ける」
  形にし、**パス付き issuer で正しく組み立つ**テストを追加した。

### [Critical] `token_endpoint_auth_methods_supported` は optional で、欠落時の既定は basic

- 判断: **対応する**
- 根拠: 正しい。欠落を「対応方式なし」として拒否すると仕様準拠の IdP を拒否する。
- 対応内容: **欠落時は `client_secret_basic` として扱う**。
  明示されている場合だけ basic → post の優先で選ぶ。
  欠落 / 空配列 / 未知値だけ / basic と post の混在を**別々にテスト**する。

### [Warning] endpoint の検査は userinfo と fragment を拒否する。query は禁じない

- 判断: **対応する**
- 対応内容: 検査を「https / userinfo なし / fragment なし / 絶対 URL」にした。
  **query は禁じない**（標準上の根拠が無い）。

---

## B2

### [Critical] `PinnedFailure` を分岐していない

- 判断: **対応する（明確なバグ）**
- 根拠: `fetch()` は `PinnedResponse|PinnedFailure` を返す。失敗は**値**で返るので
  `catch (Throwable)` では捕まらない。
- 対応内容: `PinnedFailure` を**明示的に分岐**して固定の理由コードの例外へ変換する。
  DTO を組み立てる前に **2xx / body 上限 / JSON オブジェクト / 必須の `id_token`** を確定させる。

### [Warning] `token.connect_timeout_seconds` が使われていない

- 判断: **対応する**
- 対応内容: `Deadline` へ接続の期限と全体の期限の両方を渡す形にする。
  ^0.4 の API が個別の接続期限を受けないなら**設定項目ごと削除する**
  （「参照されない設定を作らない」を守る）と明記した。

### [Warning] Basic の資格情報の符号化が独自

- 判断: **対応する**
- 対応内容: RFC 6749 §2.3.1 に従い、**`application/x-www-form-urlencoded` の規則で符号化**してから
  `:` で連結し base64 する（自前の `rawurlencode` 連結にしない）。
  空白・`+`・`:`・非 ASCII を含む資格情報でテストする。

### [Warning] 漏洩テストは base64 と urlencoded の形も対象にする

- 判断: **対応する**
- 対応内容: 漏洩テストの照合対象を **平文 / base64 / form-urlencoded の 3 形**にした。

---

## B3

### [Warning] JWK の `use` は optional

- 判断: **対応する**
- 対応内容: **`use` が存在するときだけ `sig` を要求**する（`key_ops` と同じ条件付き検査）。

### [Warning] audience / azp の規則を論理和で書かない

- 判断: **対応する**
- 対応内容: 3 条に分けた —
  (1) `aud` は必ず client_id を含む /
  (2) `aud` が複数なら `azp` **必須** /
  (3) `azp` が存在するなら文字列で client_id と一致。

### [Warning] `composer.lock` が変更対象に無い

- 判断: **対応する**
- 対応内容: `composer.json` と `composer.lock` を同じ施策の変更対象にした。

---

## B4

### [Critical] 独立接続の並行テストがグローバル `RefreshDatabase` と両立しない

- 判断: **対応する（別 TODO のハーネスに乗る）**
- 根拠: 正しい。テストのトランザクションの中で作ったフィクスチャは 2 本目の接続から見えない。
- 対応内容: **別 TODO `process-concurrency-harness-adoption` のハーネスに乗る**と明記し、
  **前段依存を 2 本**にした。ハーネスが持つ 6 要素のうち本設計が使うのは
  「transaction 外フィクスチャ」「ready / go ファイルでの同期」「締切つきの待ち」
  「子のキャッシュ store を配列固定」であり、
  **実プロセス版は 1 本に絞る**（正典の規定）。細かい分岐は同一プロセスのテストへ回す。
  B4・C1・C2 の並行テストは**同じハーネスを共有**する。

### [Warning] 失敗時にセッションの結合の秘密が残り続ける

- 判断: **対応する**
- 対応内容: **行が不可逆に consume された失敗では、対応するセッションの値も削除**する。
  **結合の不一致のように行を保持する場合だけ秘密も保持**する（攻撃者が消せる形にしない）。

---

## C1

### [Critical] `APP_KEY` ローテート後に既存の身元へ到達できない

- 判断: **対応する**（A1 の是正で構造ごと解消）
- 対応内容: 指紋をやめて `COLLATE "C"` の列にしたので、鍵に依存しない。
  引き当ては `subject` の値そのもので行う。

### [Warning] `findIdentity()` は relation 起点で書く

- 判断: **対応する**
- 対応内容: `$connection->identities()` 起点で引くと明記した
  （クラス起点で接続 id を条件にする書き方へ流れる余地を残さない。不変条件 3 と整合）。

---

## C2

### [Critical] Active の確認 2 回では無効化との競合を閉じられない / 拒否されても JIT の副作用が残る

- 判断: **対応する（指摘は正しい）**
- 対応内容: **線形化点を接続の行ロックに定める**。
  外向き取得と ID トークンの検証を**終えてから**、
  1 つのトランザクションで
  「接続の行を `lockForUpdate()` → **Active を確認** → **JIT** → commit」を行う。
  無効化 (`disable`) も**同じ行を `lockForUpdate()` する**ので、両者は直列化される。
  - **無効化が先に線形化したら、JIT もログインも起きない**（Active の確認で落ちる）
  - **callback が先なら、無効化はその後に成立する**（次回から入れない）
  - 拒否されたときに**利用者・身元・所属が残らない**（同一トランザクション内なので巻き戻る）
  並行テストで両方向を固定する。

### [Warning] セッションからの結合の秘密の取り出しが契約に無い

- 判断: **対応する**
- 対応内容: `state` の指紋から**試行ごとのセッションキー**を導き、
  値が**非空の文字列でなければ外向き取得の前に一様拒否**する、と明記した。

---

## D2

### [Critical] `dontFlash` の登録場所が曖昧

- 判断: **対応する**
- 根拠: リポジトリに使用実績が無いので、実装者判断に委ねると秘密が old input に残る。
- 対応内容: **`bootstrap/app.php` の `withExceptions()` 内で
  `$exceptions->dontFlash(['client_secret'])`** と実装点を確定し、変更ファイルに加えた。

### [Warning] 読み取り route の認可を明示する

- 判断: **対応する**
- 対応内容: **action ごとの ability と期待する応答の表**を作った（`index` も認可を通る）。

---

## E1

### [Critical] スキーマ・route・設定・用途ラベルが未設計

- 判断: **対応する**
- 対応内容: A2 と同じ粒度で
  **列・外部キー・一意制約（トークンの指紋）・利用者ごとの未消費の扱い・cast・relation**を書き、
  **route 3 本**（発行 / 再送 / 確認）を method・認証・認可・throttle・recent-auth・no-store・CSRF つきで設計し、
  `config/enterprise-sso.php` に **`email_promotion.ttl_seconds`**（正数・上限つき）を足し、
  用途ラベル `EmailPromotionToken` を A1 に足した。

### [Warning] 昇格の確定では `email_verified_at` を更新する

- 判断: **対応する**（A3 と同じ是正）

---

## F1

### [Critical] 「すべての変数経由の呼び出しを未解決として失敗」は実装不能

- 判断: **対応する（契約を狭める）**
- 根拠: 正しい。`$this->pinned->fetch()` のような通常のオブジェクト呼び出しまで
  未解決にすると、設計中のコードがそのまま赤になる。
  字句走査では受け手の型まで解決できない。
- 対応内容: 禁止対象を **「動的なメソッド名 (`$obj->$name()`) / 可変クラス名 (`new $cls`) /
  可変 callable (`call_user_func` 系・文字列からの呼び出し)」** に限定した。
  通常の固定メソッド呼び出しは**構築子の引数の宣言型と PHPDoc から解決できる範囲**で判定し、
  **その範囲を docblock に明記する**（「すべての呼び出しを解決できる」とは主張しない）。
  完全な型解決が要る判定は作らない。

### [Warning] G3 の「例外は理由文字列だけ」を型の検査だけで証明できない

- 判断: **対応する**
- 対応内容: 例外の型そのものを
  **理由の enum だけを受け取り、`previous` を受け取れない構築子**に閉じた
  （型で `previous` を渡せないので、vendor 例外の連鎖が構造的に起きない）。
  実挙動の漏洩テストと組み合わせて保証範囲を書く。

---

## F2

### [Critical] route の全分類が E1 を含んでいない

- 判断: **対応する**
- 対応内容: **新規 route 13 本**（企業 SSO 3 + 組織側 7 + メール昇格 3）を母集団にして
  throttle・認可・recent-auth・nested・no-store を**全件分類**する表に作り替えた。

---

## F3

### [Warning] `routes/console.php` の扱いが対応表と本文で食い違う

- 判断: **対応する（本文の判断を採り、理由を明示する）**
- 根拠: 台帳の値域は「対象パスは**全登録の和集合で重複しない**」ことを要求する。
  `routes/console.php` は**既存の共有ファイル**であり、掃除の登録 1 行のために
  D37 の対象にすると、将来この 1 ファイルを触る別の逸脱と**必ず衝突する**。
- 対応内容: **除外する。ただし追跡が切れない根拠を書く** —
  掃除の実行点は `PruneLoginAttempts` コマンド（D37 の対象パス）に本体があり、
  `routes/console.php` の 1 行はその**呼び出しの登録**にすぎない。
  コマンドが D37 に載っている限り、機構としての追跡は切れない。
  この判断を D37 の本文に明記する。

### [Warning] D37 の対象が B4 だけでなく `AttemptFingerprint` と C2 の結線にも広がる

- 判断: **対応する**
- 対応内容: `AttemptFingerprint` と `FingerprintPurpose` を D37 の対象パスへ加えた
  （C2 の controller は正典にも在る資産なので対象にしない。**この線引きの根拠**も本文に書く）。

---

## 横断

### [Critical] 新モデル 4 本の文書更新が施策に無い

- 判断: **対応する**（A3 の項と同じ。A2・E1 の変更対象へ `docs/architecture.md` /
  `docs/factories.md` を追加した）

### [Warning] 検証コマンドの受入条件が無い

- 判断: **対応する**
- 対応内容: 「受入条件（検証コマンド）」の節を新設し、
  `composer test` / `composer phpstan` / `vendor/bin/pint --test` / pnpm の検証コマンドを列挙した。
  **`composer fix` / `pnpm lint:fix` は検証の代替にならない**と明記した。

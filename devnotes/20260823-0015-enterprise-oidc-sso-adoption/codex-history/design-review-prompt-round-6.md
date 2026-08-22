# Round 6: Round 5 の残件 5 件への対応

Round 5 の全体判定は CHANGES_REQUESTED だった。指摘された 5 件（承認阻害 2 件・文書整合 3 件）に
**すべて対応した**。設計本文は 1 本のままで、変更したのは下に全文を示す箇所だけである
（Round 5 で APPROVE 済みの A1 / A3 / B1 / B2 / B3 / C2 / F1 / F2 は、下記の波及以外に変更が無い）。

なお本ラウンドは、監督者の裁量でラウンド上限を延長して再開したものである。
`kent013/laravel-ssrf-pin ^0.4` 前提であることは Round 5 までと変わらない（別 TODO で並行実装中）。

## 対応マトリクス

### [Critical] D1 / D2: `verify` の線形化

- 判断: **対応する**（指摘のとおり。提示された 6 手順の形をそのまま採った）
- 対応内容: `verify` **だけ**を明示の三段（提示の 6 手順を段で括ると 3 つ）にした。
  1. **ロックなし**で `ConnectionCredentialsSnapshot` を読む
  2. **ロックなし・トランザクションの外**で外向き取得と検証
  3. `DB::transaction` → `lockForUpdate()` → 一致の再確認 → 一致時のみ `Verified`
- **比較子の選択**: 指摘の「`updated_at` に頼らない。認証材料そのもの、または専用 revision」に対し、
  **専用の `credentials_revision` 列を主の比較子**に選んだ。理由:
  - **client secret を比較しないで済む** = `verify` の経路が**秘密を一度も復号しない**
    （D2 の「一覧では秘密を一度も復号しない」と同じ思想を verify にも通せる）
  - issuer / client_id / client_secret の 3 つを 1 つの整数で漏れなく表せる
  - 加えて **issuer / client_id の実値も第 2 の比較子として突き合わせる**。
    これは主の比較子の代わりではなく「**+1 を忘れた書き手がいたら落ちる**」ための層である
    （revision は書き手の規律に依存する値なので、値そのものを見る層を 1 枚重ねた）
- **書き手を 1 か所に閉じた**: issuer / client_id / client_secret を変える唯一のメソッド
  `applyCredentialChange()` が、**必ず** +1 と `Draft` 化を同時に行う
- **並行テストを追加した**: 指摘された「verify の外部取得中に認証材料を更新すると、
  古い verify の結果が採用されない」を本命に、負のコントロール
  （**表示名だけの更新では verify が成功する** = `updated_at` 代用なら落ちるテスト）も置いた
- **`verify` を待たせる方法**: 実プロセスのハーネスには乗せず、**偽 IdP（F4）の応答直前に
  ready/go の待ち合わせ点**を差し込む形にした（F4 に `?Closure $beforeRespond` を追加）。
  止めたいのは DB の同時実行ではなく**外向き HTTP の応答**なので、同一プロセスで足りる。
  **この分割で何を示せて何を示せないか**も設計に書いた（同時 verify の第 3 段どうしの排他は
  直接は示さない。依拠する `lockForUpdate()` が実プロセスで効くことは B4 の 1 本が示す）
- **D2 側も直した**: 「更新系 5 本すべてが同一トランザクションで行ロック」という記述は
  `verify` と矛盾するので、**4 本と `verify` の 2 通りに割った表**へ書き換え、
  controller が外向き取得を包むトランザクションを張らないことも明記した

### [Critical] E1: 確認画面の描画方式

- 判断: **対応する**。提示された 2 択のうち **(a) 専用 Blade に確定**した
- 対応内容と根拠:
  - **本リポジトリに同じ形の先例がある** — `resources/views/mcp/authorize.blade.php`
    （外部 OAuth client の consent 画面）が「サーバが描画した hidden にトークン相当を載せ、
    明示の POST で確定する」standalone Blade であり、docblock に
    「Inertia / Vite に依存しない（consent はアプリ本体の SPA shell を必要としないため）」とある。
    確認画面はこれと同じ性格である（メールから 1 枚開いて、押したら抜ける）
  - **(b) を採らなかった理由**: Inertia は page object を `history.state` へ載せるので、
    prop に置いた時点でトークンが**ブラウザ履歴に残る**。`encryptHistory()` で緩和はできるが、
    それは「履歴の暗号化に依存する」ことであり当初の意図をそのまま捨てる判断になる。
    Blade なら**そもそも履歴に載らない**
  - 指摘された明記事項をすべて書いた: **変更ファイル**（`resources/views/auth/email-promotion/confirm.blade.php`
    新規 + controller が `response()->view()` を返す）/ **CSRF**（`@csrf`）/ **`no-store`**（route に
    `no-store` alias）/ **`Referrer-Policy`** / **外部リソースなし** / **design token**
- ★**`Referrer-Policy` は「ヘッダ」ではなく `<meta name="referrer">` で効かせる**ことにした。
  実読して分かったことが 2 つある:
  1. `App\Http\Middleware\SecurityHeaders` は **web group** の middleware で、
     `$next` の**後**に `Referrer-Policy: strict-origin-when-cross-origin` を**無条件に `set()`** する。
     group は route middleware より外側なので、**route 側で立てても上書きされる**。
     つまり「route に middleware を足す」ではこの要求は満たせない
  2. その `SecurityHeaders.php` は `docs/template-fingerprints.json` の `entries` に**在り**、
     かつ `tests/Support/TemplateDivergence/adoption-debt.tsv` にも**在る**。
     触ると「変更したまま債務に残す」が選べず、同期か逸脱登録かを迫られる
  - よって **document 側の `<meta name="referrer" content="no-referrer">` で閉じた**。
    baseline の `strict-origin-when-cross-origin` は**cross-origin には origin しか送らない**ので
    第三者への完全 URL の漏れは元々無く、`meta` が足すのは**同一オリジンでも送らない**という
    一段の締めである。1 枚の画面のために共有 baseline へ route 名の分岐を持ち込むのは釣り合わない
- ★**design token は「参照しない」と明記した**（できない約束を書かない）。
  本 Blade は Vite / Tailwind のパイプラインに乗らないので `tokens.css` の CSS 変数も
  utility も使えない。これは本リポジトリで既に確立した扱いで、
  `errors/layout.blade.php` の docblock が「DESIGN.md の生 CSS 禁止は Vite/Tailwind に乗る
  Svelte への規約であり、本 blade は例外。色は token を参照できないのでニュートラルな
  プレースホルダを hex 直書き」と宣言している。**同じ docblock を本 Blade にも置く**。
  `tests/js/architecture/contrast-invariant.test.ts` は token inventory を入力にする検査であり
  **Blade は母集団に入らない**（実読で確認）
- ★**Inertia でないことの波及**も書いた: `DocumentTitleCoverageTest` は
  「**Inertia を render する GET named route**」だけを母集団にする（実読で確認）ので
  本 route は**母集団外**であり、**exemption の登録も要らない**。タイトルは Blade の `<title>` が持つ。
  `InertiaRenderPageExistsInvariantTest` も同様に無関係。結果として
  **E1 の波及は `resources/js/` に 1 行も無い**

### [Warning] B4: docblock の矛盾

- 判断: **対応する**（指摘のとおり矛盾していた）
- 対応内容: 提示された文言へ書き換えたうえで、**元の docblock が持っていた理由を落とさなかった**。
  「業務上の拒否を例外にすると、同じトランザクションで行っている**期限切れ行のオンアクセス掃除まで
  巻き戻る**」が元の理由であり、これは業務上の拒否の側の根拠として今も生きている。
  障害側については「巻き戻ることを**受け入れる**（掃除の正本は日次の実行点で、
  オンアクセスはその前倒しに過ぎない）」と明示した

### [Warning] C1: 冒頭 docblock の旧記述

- 判断: **対応する**
- 対応内容: 「接続 id と**生の subject**（`COLLATE "C"` なのでバイト一致）」へ直し、
  **なぜ指紋にしないか**（APP_KEY ローテートで身元へ到達できなくなりアカウントが分裂する）も
  同じ場所に書いた（Round 2 の判断がここだけ反映されていなかったのが原因なので、根拠ごと置いた）

### [Warning] A2: CHECK 制約の実体と制約名

- 判断: **対応する**
- 対応内容: 生 SQL と**明示の制約名**を移行のコード例へ書いた。制約名を明示するのは
  (1) 違反時に出所が一目で分かる (2) スキーマ読み取りテストが `pg_constraint.conname` を
  名前で引ける、の 2 つのためである（名前を DB に決めさせるとテストが書けない）
- **制御文字も DB の不変条件に含めた**（DTO だけの保証にしなかった）。理由は、同じ列について
  長さは DB で閉じるのに文字種は DTO だけ、というのは二層の理屈が一貫しないため。
  **名前を分けて 2 本**置いた（長さ違反と文字種違反を名前だけで切り分けられる）
- ★**保証範囲を誇張しないよう書いた**（Round 5 が「実装時に確認すればよい」に挙げた
  「subject を ASCII 限定にするか UTF-8 の非制御文字まで許すか」への回答でもある）:
  - 対象は **C0（`U+0001`〜`U+001F`）と `DEL`（`U+007F`）だけ**。**入力側 DTO の検査と同じ集合**に揃えた
    （2 層が違う集合を見ていたら二層の意味が消えるため）
  - **`U+0000` は書かない** — pgsql の `text`/`varchar` は NUL を格納できず、
    `E'...'` の文字クラスにも書けない。**格納層で不可能なものを CHECK に二重に書かない**
  - **C1 制御文字（`U+0080`〜`U+009F`）と `U+200B` 等の書式文字は対象外＝許す**。
    「制御文字を一切通さない」とは書かない。負のコントロールのテストで**保証外が保証外のまま**であることを固定する

## 変更した箇所の全文

以下、変更した節だけを全文で示す。示していない節は Round 5 から変更が無い。

### A2 — 移行 1（`credentials_revision` 列を追加）

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

    // ★**認証材料の版**。issuer / client_id / client_secret のいずれかが変わるたびに +1 する。
    //   用途は 1 つだけ — D1 の `verify` が「**外向き取得の間に認証材料が変わっていないこと**」を
    //   ロックの中で確かめるための比較子である (D1「verify だけは二段構成にする」節が正本)。
    //   ★**`updated_at` で代用しない**: 時刻の精度で同一に見えうるうえ、
    //     認証に関与しない表示名の更新まで巻き込んで verify を落とす。
    //   ★書き手は D1 の 1 メソッドだけであり、そこで**必ず** Draft 化と同時に +1 する。
    $table->unsignedBigInteger('credentials_revision')->default(1);

    $table->timestamps();

    // 1 組織に複数の接続を許す (合併・複数 IdP の企業がある)。組織単位の検索用。
    $table->index('organization_id');
});
```

### A2 — 移行 2（CHECK 制約 2 本の実体・制約名・保証範囲）

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
    //       バイト長の保証にならない。**CHECK 制約を別に置く**
    //       (身元の主キーなので、型の検査だけに頼らず DB でも閉じる)。実体は下の生 SQL。

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

// ★CHECK 制約は Blueprint に API が無いので**生 SQL で置く**。
//   pgsql 固定でよい (phpunit.xml が DB_CONNECTION=pgsql を force しており、テストも本番も pgsql)。
//   ★**制約名を明示する** — (1) 違反したときに出所が一目で分かる
//   (2) スキーマ読み取りテストが `pg_constraint.conname` を名前で引ける
//   (名前を DB に決めさせると、テストが「在ることの確認」を書けない)。
DB::statement(<<<'SQL'
    ALTER TABLE enterprise_identities
        ADD CONSTRAINT enterprise_identities_subject_octet_length_check
        CHECK (octet_length(subject) BETWEEN 1 AND 255)
    SQL);

// ★制御文字の禁止も **DB の不変条件に含める**（DTO だけの保証にしない）。
//   身元の主キーなので、上のバイト長と同じ理由で 2 層目を DB に置く。
//   ★**名前を分ける** — 長さ違反と文字種違反を、違反の名前だけで切り分けられるようにする。
DB::statement(<<<'SQL'
    ALTER TABLE enterprise_identities
        ADD CONSTRAINT enterprise_identities_subject_no_control_chars_check
        CHECK (subject !~ E'[\\x01-\\x1F\\x7F]')
    SQL);
```

> **この 2 本の CHECK が保証する範囲（誇張しない）**
>
> - 対象は **C0 制御文字（`U+0001`〜`U+001F`）と `DEL`（`U+007F`）だけ**である。
>   **入力側 DTO（`VerifiedIdTokenClaims`）の検査と同じ集合**に揃えてある
>   （2 層が違う集合を見ていると、片方だけ通る値が生まれて二層の意味が消える）。
> - **`U+0000`（NUL）は集合に書けないし、書く必要も無い** — pgsql の `text` / `varchar` は
>   NUL を格納できず、ドライバの段で失敗する（`E'...'` の文字クラスにも NUL は書けない）。
>   **格納層で不可能なものを CHECK で二重に書かない**。
> - **C1 制御文字（`U+0080`〜`U+009F`）と Unicode の書式文字（`U+200B` 等）は対象外**である。
>   これらは**許す**。「制御文字を一切通さない」とは書かない（言い過ぎになる）。
> - **down() で制約を明示的に落とす必要は無い** — 表ごと `dropIfExists` するので制約も消える。

### A2 — モデルの契約（`credentials_revision` の扱いを追記）

### モデルの契約（cast / hidden / relation / Factory generics）

| モデル | cast | `$hidden` | relation | Factory |
|---|---|---|---|---|
| `OrganizationOidcConnection` | `status` → `OidcConnectionStatus::class` / `verified_at` → `immutable_datetime` / `client_secret_encrypted` → `EncryptedSecretCast::class`（`ConnectionSecret` を返す） | `client_secret_encrypted` | `organization()` (BelongsTo) / `identities()` (HasMany) / `loginAttempts()` (HasMany) | `@use HasFactory<OrganizationOidcConnectionFactory>` |
| `EnterpriseIdentity` | `last_login_at` → `immutable_datetime`（申告メールは CipherSweet が担当） | `claimed_email_encrypted` | `connection()` (BelongsTo) / `user()` (BelongsTo) | `@use HasFactory<EnterpriseIdentityFactory>` |
| `EnterpriseSsoLoginAttempt` | `expires_at` → `immutable_datetime` / `pkce_verifier_encrypted` → `encrypted` | `pkce_verifier_encrypted` / `state_fingerprint` / `nonce_fingerprint` / `browser_binding_fingerprint` | `connection()` (BelongsTo) | `@use HasFactory<EnterpriseSsoLoginAttemptFactory>` |

- `$fillable` は **3 モデルとも空**（生成は Service が明示的に組み立てる。mass assignment を作らない）
- **`toArray()` から暗号文も秘密の値型も出ない**ことをテストで固定する（`$hidden` の実効確認）
- ★`credentials_revision` は **cast も `$hidden` も要らない**（`unsignedBigInteger` がそのまま int で来る、
  秘密ではない）。ただし **D2 の `SsoConnectionSummary` には載せない** —
  画面が使う値ではなく、**D1 の内部の比較子**である。
  外へ出すと「画面から見える版番号」として別の意味を持ち始める（I4 と同じ思想）

```php
// app/Models/Organization.php へ追加 (D2 の scopeBindings が引く relation)
/** @return HasMany<OrganizationOidcConnection, $this> */
public function oidcConnections(): HasMany
{
    return $this->hasMany(OrganizationOidcConnection::class);
}
```

### A2 — テスト計画

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseIdentityIsolationTest.php`
  - **申告メールの列（または対応する blind index）を含む索引が 0 本**である
    （**スキーマの読み取りのみ**。`migrate:fresh` 等の破壊操作を伴わない = 禁止事項 3）
  - **`subject` 列の照合順序が `C` である**（スキーマの読み取り。設定が外れたら赤）
  - **`Alice` と `alice` が別の身元になる**（照合順序の実挙動。上の検査と二層）
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseSsoModelHidingTest.php` —
      3 モデルの `toArray()` に暗号文・秘密の値型が出ない
- [ ] `subject` の**バイト長 256 以上**と**制御文字を含む値**が DTO の構築で拒否される
- [ ] **DB の CHECK 制約 2 本が名前で実在する**（`pg_constraint.conname` を読む。
      `enterprise_identities_subject_octet_length_check` /
      `enterprise_identities_subject_no_control_chars_check`。**スキーマの読み取りのみ**）
- [ ] **DTO を迂回して直接書いても DB が拒む**（二層目が実際に効くことの実挙動。
      256 バイトの `subject` / `"a\x1Fb"` を直に insert して**制約違反になる**）
- [ ] **C1 制御文字（`U+0085`）と `U+200B` は通る**（★負のコントロール。
      「制御文字を一切通さない」と誤読させないため、**保証外が保証外のままである**ことを固定する）
- [ ] **`credentials_revision` の既定値が 1 である**（Factory で作った接続が 1 から始まる）
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

### B4 — `consume()` の docblock（矛盾を解消）

```php
{
    /**
     * 使用権を取得する。取得できた要求だけが値を読み出せる。
     *
     * ★**業務上の拒否では例外を投げない。DB・基盤の障害は例外として伝播し巻き戻す。**
     *   - **業務上の拒否**（行が無い / 期限切れ / ブラウザ結合の不一致）は
     *     すべて {@see AttemptConsumeResult} の分類として**返す**。ここを例外にすると、
     *     同じトランザクションで行っている**期限切れ行のオンアクセス掃除まで巻き戻り**、
     *     「オンアクセスでも掃除する」が成立しない。
     *   - **DB・基盤の障害**（{@see EnterpriseSsoAttemptStoreFailure} と、その他の
     *     予期しない例外）は**握り潰さず伝播させ**、トランザクションごと巻き戻す。
     *     ★このときオンアクセス掃除が巻き戻ることは**受け入れる** —
     *     掃除の正本は日次の実行点であり、オンアクセスはその前倒しに過ぎない。
     *     基盤の障害を分類へ混ぜると、**壊れていることが「普通の失敗」として見えなくなる**。
     * ★**本メソッドは業務上の拒否について例外を投げず、分類結果をそのまま返す**。
     *   呼び出し側 ({@see EnterpriseCallbackAuthenticator}) が
     *   「行が消えた失敗か / 行を保持した失敗か」で**セッションの秘密の始末を分け**、
     *   その後で**外向きの一様な例外へ変換する**。
     *   (HTTP の応答が一様であることと、内部で理由を区別することは両立する。)
     */
    public function consume(string $state, #[SensitiveParameter] string $browserBindingSecret): AttemptConsumeResult
    {
        // …（本体は Round 5 から変更なし）…
```

### B4 — 並行テストの土台（`verify` を乗せない理由を追記）

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

★**D1 の `verify` の並行テストは、このハーネスに乗せない**。理由は形が違うからである。
B4 / C1 / C2 が要るのは「**2 つの DB トランザクションを本当に同時に走らせる**」ことで、
これは実プロセスでないと作れない。一方 `verify` で止めたいのは
**外向き HTTP の応答**であり、これは**偽 IdP（F4）の側に待ち合わせ点を置けば同一プロセスで作れる**:

- 偽 IdP の discovery の応答を返す直前に **ready を立てて go を待つ**
  （B4 のハーネスと**同じ ready / go の作法**を使う。新しい同期の道具を作らない）
- テストは ready を待ってから `update` を実行し、そのあと go を立てる
- したがって「取得中に材料が変わった」を**実プロセスを起こさずに**再現できる

★この分割は手抜きではなく**保証の切り分け**である。`verify` の線形化が依存しているのは
(1)「取得の間ロックを持たない」(2)「ロックの中で版を比べる」の 2 点で、
**本テストが直接示すのは (1) と (2) の判定そのもの**である。

★**保証しないことも書く**: 上記は「**同時に走る 2 つの `verify` の第 3 段が互いに排他される**」を
**直接は示さない**（同一プロセスの待ち合わせでは 2 つの実トランザクションを同時に走らせられない）。
第 3 段の排他が依拠するのは `lockForUpdate()` という**同じ 1 つの機構**であり、
それが実プロセスで効くことは **B4 の実プロセス版 1 本**が示している。
つまりここは「**機構は別途証明済み、本テストは適用箇所を証明する**」という
2 段の論拠であって、`verify` の同時実行そのものの実測ではない。

### C1 — 冒頭 docblock（旧記述を修正）

### 変更後コード（要点）

```php
/**
 * 初回ログインでの利用者の自動作成 (always-JIT)。
 *
 * ★**メールアドレスで利用者を引かない** (正典 v1 / I1)。
 *   引き当ての鍵は **接続 id と生の subject** だけである
 *   (列の照合順序が `COLLATE "C"` なので**バイト一致**。A2 の移行 2 が正本)。
 *   ★**指紋 (HMAC) にしない** — 鍵に依存する値を鍵にすると、APP_KEY をローテートした瞬間に
 *     既存の身元へ到達できなくなり**アカウントが分裂する**。列の照合順序なら鍵に依存しない。
 *   申告メールは EnterpriseIdentity に暗号化して持つが、**引き当てには使わない**。
 *

### D1 — 全文（`verify` の二段構成を含む）

## D1: 接続の状態遷移サービス

### 変更箇所
- 新規: `app/Services/EnterpriseSso/OidcConnectionTransitionService.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/ConnectionCredentialsSnapshot.php`
  （`verify` の第 1 段が読む**認証材料の版のスナップショット**。**client secret を持たない**）
- 新規: `app/DataTransferObjects/EnterpriseSso/VerifyOutcome.php`
  （`verified` / `alreadyVerified` / `staleCredentials` / `connectionGone` の 4 値。
  ★**画面へは一様に出さない** — これは運営の操作の結果なので、
  「材料が変わったのでやり直してください」と**具体的に伝える**。
  存在を隠す必要があるのは未認証の経路であって、認可を通った運営操作ではない）

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
 * ★**`verify` だけはこの形にしない**。`verify` は外向き HTTP を伴うので、同じ形にすると
 *   **通信の間ずっと DB のロックを保持する**ことになり、B4 / C2 が避けている形と矛盾する。
 *   `verify` は下の**二段構成**で線形化する。
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

### `verify` だけは二段構成にする（Round 5 の [Critical] D1 / D2 への回答）

**解きたい競合**は次の 3 手順である。

1. `verify` が**旧**の issuer で discovery / JWKS を取得する
2. その間に `update` が issuer / client_id / client secret を変える
3. `verify` が接続の行をロックし、**新しい認証材料を旧い取得結果で `Verified` にする**

外向き取得の前にロックを取れば消えるが、それは**通信の間ロックを保持する**形であり、
B4 / C2 が避けている形と同じになる（IdP が遅い・落ちているときに管理操作が全部詰まる）。
そこで **`verify` だけを明示の二段構成**にする。

```php
/**
 * 接続先情報の取得に成功したことを確認し、Draft → Verified へ進める。
 *
 * ★**外向き取得の間、DB のロックを一切保持しない**。段は 3 つに分かれる。
 *
 *   第 1 段 (ロックなし): 検証の対象となる**スナップショット**を読む
 *   第 2 段 (ロックなし・トランザクションの外): 外向き取得と検証
 *   第 3 段 (トランザクション + 行ロック): 一致の再確認と遷移
 *
 * ★**第 2 段をトランザクションの中に入れない**。中に入れると、ロックを取っていなくても
 *   pgsql のトランザクションが外部 HTTP の往復のあいだ開きっぱなしになる
 *   (idle in transaction が積み上がる)。開くのは第 3 段だけである。
 */
public function verify(OrganizationOidcConnection $connection): VerifyOutcome
{
    // ── 第 1 段: スナップショット (ロックなし)
    // ★client secret は**含めない**。verify は discovery と JWKS を取るだけで
    //   秘密を必要としない = **verify の経路は秘密を一度も復号しない** (D2 の DTO と同じ思想)。
    $snapshot = ConnectionCredentialsSnapshot::of($connection);
    //   → readonly {int $connectionId, string $issuer, string $clientId, int $credentialsRevision}

    // ── 第 2 段: 外向き取得 (ロックなし・トランザクションの外)
    // 取得の失敗で接続の状態を変えない (上の「取得の失敗で接続を殺さない」)。
    $metadata = $this->discovery->fetch($snapshot->issuer);   // B1。PinnedHttpClient 経由

    // ── 第 3 段: 一致の再確認と遷移 (ここで初めてトランザクションと行ロック)
    return DB::transaction(function () use ($snapshot, $metadata): VerifyOutcome {
        $fresh = OrganizationOidcConnection::query()
            ->whereKey($snapshot->connectionId)
            ->lockForUpdate()
            ->first();

        // 接続が消えていた → 結果を捨てる (アーリーリターン)
        if ($fresh === null) {
            return VerifyOutcome::connectionGone();
        }

        // ★**主の比較子は credentials_revision** である。
        //   認証材料 (issuer / client_id / client secret) を変える経路は D1 の 1 メソッドだけで、
        //   そこが必ず +1 する。1 つの整数で「材料が変わったか」を漏れなく表せる。
        if ($fresh->credentials_revision !== $snapshot->credentialsRevision) {
            return VerifyOutcome::staleCredentials();   // ★結果を捨てる。Draft のまま
        }

        // ★**第 2 の比較子**として issuer / client_id そのものも突き合わせる。
        //   これは主の比較子の代わりではなく、「**+1 を忘れた書き手がいたら落ちる**」ための層である
        //   (revision は書き手の規律に依存する値なので、値そのものを見る層を 1 枚重ねる)。
        //   ★client secret は比較しない — 秘密を復号せずに済ませる方を採る。
        //     secret の変更も必ず revision を +1 するので、主の比較子が捕まえる。
        if ($fresh->issuer !== $snapshot->issuer || $fresh->client_id !== $snapshot->clientId) {
            return VerifyOutcome::staleCredentials();
        }

        // ★**同じ材料を別の要求が既に Verified にしていた場合は、何もせず成功とする**。
        //   revision が一致している = 検証したのと同じ材料なので、これは競合ではなく重複である。
        //   遷移表に Verified → Verified を足さない (表を正確に保つ) 代わりに、
        //   ここで明示的に「遷移しない成功」として扱う。
        if ($fresh->status === OidcConnectionStatus::Verified) {
            return VerifyOutcome::alreadyVerified();
        }

        // Draft 以外 (Active / Disabled) からは遷移しない。定義外の遷移は例外。
        $this->transitionToVerified($fresh, $metadata);   // status = Verified, verified_at = now()

        return VerifyOutcome::verified();
    });
}
```

**認証材料を変える側（`update`）が守る規約**

```php
// ★issuer / client_id / client_secret のいずれかを変える**唯一の書き手**。
//   3 つを必ず 1 か所に閉じ込めるのは、credentials_revision の +1 を
//   「書き手が思い出す規律」ではなく「経路の性質」にするためである。
private function applyCredentialChange(OrganizationOidcConnection $locked, ...): void
{
    // …変更を適用…
    $locked->credentials_revision = $locked->credentials_revision + 1;   // ★必ず +1
    $locked->status = OidcConnectionStatus::Draft;                       // ★必ず Draft へ
    $locked->verified_at = null;
    $locked->save();
}
```

**この形が保証すること / しないこと**

| | 内容 |
|---|---|
| **保証する** | 外向き取得の**開始から完了までの間**に認証材料が変わったなら、その `verify` の結果は**採用されない**（`Draft` のまま拒否される） |
| **保証する** | 外向き取得の**間、接続の行のロックを保持しない**（IdP が遅くても管理操作が詰まらない） |
| **保証する** | `verify` の経路は **client secret を一度も復号しない** |
| **保証しない** | 「取得した瞬間に IdP 側が正しかった」こと。IdP は `verify` の**後**にいつでも構成を変えられる。`Verified` は**そのときの取得が成功した**という記録に過ぎず、以後の有効性の証明ではない |
| **保証しない** | 拒否された `verify` の**自動再実行**。運営がもう一度押す（拒否は画面にそのまま出す） |

> **なぜ `updated_at` で代用しないか**（Round 5 の指摘のとおり）: 時刻は精度によって
> 同一に見えうるうえ、**認証に関与しない表示名の更新まで巻き込んで** `verify` を落とす。
> 専用の版番号なら「認証材料が変わったときだけ」を正確に表せる。

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
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcConnectionVerifyLinearizationTest.php`
      （`verify` の二段構成。**Round 5 の [Critical] が要求した並行テスト**）
  - ★**本命**: **`verify` の外部取得中に認証材料を更新すると、古い `verify` の結果が採用されない** —
    偽 IdP（F4）の discovery 応答を「要求が届いたら待つ」形にして取得中に固定し、
    その間に別の要求で issuer を変える。取得を再開させたあと、接続が
    **`Draft` のまま**で `verified_at` が null であることを確かめる
  - **client secret だけを変えた場合も採用されない**（revision が主の比較子であることの証明。
    issuer / client_id は同じなので、第 2 の比較子では捕まらない = revision が効いている）
  - **表示名だけを変えた場合は `verify` が成功する**（★負のコントロール。
    `updated_at` で代用していたら落ちる。認証に関与しない更新を巻き込まないこと）
  - **接続が取得中に削除されたら `Verified` にしない**（行が消えている）
  - **同じ材料の `verify` が二重に走っても例外にならず、2 回目は遷移しない成功になる**
  - **`Active` / `Disabled` から `verify` を呼ぶと定義外の遷移として例外になる**
  - **外向き取得の間に接続の行がロックされていない** — 取得を待たせている最中に、
    別の接続でない**同じ接続**の `disable` が**待たずに完了する**ことで示す
    （「ロックを保持していない」を実挙動で固定する。docblock の主張の裏取り）
  - **第 2 段がトランザクションの外にある** — 取得の最中に
    `DB::transactionLevel() === 0` であることを、偽 IdP の待ち合わせ点から確かめる
- [ ] `update` が認証材料を変えると **`credentials_revision` が +1 される**／
      **表示名だけの更新では増えない**（`OidcConnectionTransitionServiceTest` 側）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 状態が増えると画面の分岐が増える。4 状態で固定し、追加しない。

---

### D2 — 削除と更新の制限（5 操作の形を 2 通りへ割った）

### 削除と更新の制限（Round 3 の [Critical] への回答）

| 操作 | 身元が 0 件 | 身元が 1 件以上 |
|---|---|---|
| `destroy` | できる | **拒否**（押下時に「無効化してください」とエラー表示。★ボタンを disabled にしない = 禁止事項 8） |
| `update`（issuer / client_id） | できる（★**`Draft` へ戻る**） | **拒否**（押下時に「新しい接続を作ってください」とエラー表示） |
| `update`（client secret） | できる（`Draft` へ戻る） | できる（`Draft` へ戻る） |
| `update`（表示名） | できる | できる |
| `disable` | できる | **できる（推奨経路）** |

★**7 route のうち状態や認証材料を変える 5 本（`update` / `verify` / `activate` / `disable` / `destroy`）は、
すべて D1 を通して callback と直列化される**。ただし**形は 2 通りある**:

| 経路 | 形 | 理由 |
|---|---|---|
| `update` / `activate` / `disable` / `destroy` の **4 本** | 接続の行を `lockForUpdate()` した**同一トランザクション**で「身元の有無の確認 → 検査 → 変更」 | 外向き通信を伴わないので、ロックを持ったまま完結できる |
| **`verify` の 1 本** | **二段構成**（ロックなしでスナップショット → ロックなしで外向き取得 → トランザクション + 行ロックで `credentials_revision` の一致を再確認 → 一致時のみ遷移） | ★**外向き HTTP の間ロックを保持しない**ため。詳細は D1「`verify` だけは二段構成にする」節が正本 |

★したがって controller 側でも `verify` だけは**トランザクションの張り方が違う**。
`verify` の action は D1 の `verify()` を呼ぶだけにし、
**controller 側で外向き取得を包むトランザクションを張らない**。


### D2 — テスト計画

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
  - **`verify` の action が外向き取得を包むトランザクションを張らない**
    （D1 の二段構成を controller 側が壊していないことの結線。
    偽 IdP の待ち合わせ点で `DB::transactionLevel() === 0` を観測する）
  - **`verify` が `staleCredentials` を返したとき、画面に「材料が変わったのでやり直す」旨が出る**
    （★一様な応答にしない。認可を通った運営操作なので理由を具体的に伝える）
  - **client secret を更新すると一覧の状態が Draft になる**（D1 との結線）
  - **身元がある接続の削除・issuer/client_id の更新が拒否され、押下時にエラーが表示される**
    （ボタンが disabled になっていないことも確認する = 禁止事項 8）
  - **callback と確認の失敗で入力が flash されない**（`code` / `state` / `token` が old input に残らない）
- [ ] 新規 `tests/js/.../oidc-connection.test.ts` — 状態の値域の TS 定数が PHP enum と一致する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### E1 — 変更箇所と波及変更

### 変更箇所
- 新規: `app/Services/Auth/EmailPromotionService.php`（**`App\Services\EnterpriseSso` ではない**）
- 新規: `app/Models/EmailPromotion.php` + 移行 1 本 + Factory 1 本
- 新規: `app/Http/Controllers/Auth/EmailPromotionController.php`
- 新規: `app/Http/Requests/Auth/StoreEmailPromotionRequest.php` / `ConfirmEmailPromotionRequest.php`
- 新規: `app/Mail/EmailPromotionMail.php`
- 新規: `app/Exceptions/Auth/EmailPromotionConflictException.php`
- 新規: `app/DataTransferObjects/Auth/VerifiedEmail.php`
- 新規: `app/Console/Commands/Auth/PruneEmailPromotions.php`
- **新規: `resources/views/auth/email-promotion/confirm.blade.php`**
  （★確認画面は **standalone Blade**。Inertia の props にトークンを載せないための選択。
  「確認画面の描画方式」節が正本）
- 変更: `routes/web.php`（route 4 本。**既存の認証済み group の内側**）/ `routes/console.php`（掃除の登録）
- 変更: `docs/architecture.md` / `docs/factories.md`（**新モデル 1 本を登録する**）

### 波及変更

- **TypeScript 型定義: なし** — ★確認画面は standalone Blade で、**Svelte のページを 1 枚も足さない**
  （「確認画面の描画方式」節）。Inertia の Props も増えない
- **API Resource / DTO: あり** — `app/DataTransferObjects/Auth/VerifiedEmail.php`（新規）
- **テストファイル: あり** —
  `ControllerAuthorizationGateTest`（自分自身の資源として exemption 登録）/
  `RecentAuthRouteTest`（`store` / `resend` は必要、確認 2 本は不要の分類）/
  `ThrottleCoverageInventoryTest`（`email-promotion` / `email-promotion-confirm`）/
  `MassAssignmentSafetyTest`（新モデル 1 本）/ `ValidationAttributeCoverageTest`
- ★**`DocumentTitleCoverageTest` / `InertiaRenderPageExistsInvariantTest` は母集団外**
  （Inertia を render しないため。理由は「確認画面の描画方式」節）

### E1 — 確認トークンの受け渡し / 確認画面の描画方式

### 確認トークンの受け渡しと、その保証範囲（Round 4 の [Warning] への回答）

メールのリンクは **URL の query にトークンを載せる**（`?token=…`）。
これは「メールから 1 クリックで確認画面へ来られる」ために要る。
**露出を隠しきれる方式ではない**ので、保証する範囲と**保証しない範囲**を書き切る。

**固定すること**:

- **GET は DB の状態を変えない**（画面を返すだけ）
- **トークンの有効・無効で画面を変えない**（一様。存在の探り当てを作らない）
- **`no-referrer`** を効かせる（**方式は下の「確認画面の描画方式」節が正本**。
  ヘッダではなく **`<meta name="referrer">`** で document に効かせる。理由も同節に書く）
- 確認画面は**外部リソースを一切読み込まない**（Referer が出る経路を作らない）
- **アプリのログ・監査・例外に完全な URL を記録しない**（トークンは平文でも指紋でも出さない）
- 画面から POST へ渡すときは **専用 Blade が描画した form の hidden 項目**に載せる
  （★**Inertia を使わない画面にする**。したがって Inertia の props にも
  `history.state` の page object にも載らず、履歴の暗号化に依存しない。下節が正本）
- 失敗時に入力を **flash しない**（`withInput()` を使わない）。
  `token` は一般名なのでグローバルの `dontFlash` へ足さず、**経路側で閉じる**（D2 と同じ判断）
- トークンを受ける引数に **`#[SensitiveParameter]`** を付ける

**保証しないこと（誇張しない）**:

- **リバースプロキシや CDN のアクセスログ**、**ブラウザの履歴**、
  **利用者が URL を他人へ貼ること**による露出は防げない。
  緩和は **60 分の期限**と **一回だけの consume** であり、
  露出しても**使われる窓が短く、1 回しか効かない**ことに寄せている

### 確認画面の描画方式（Round 5 の [Critical] E1 への回答）

**結論: 専用の standalone Blade を 1 枚足す。Inertia を使わない。**

本設計の他の画面はすべて Inertia だが、**本リポジトリには既に同じ形の先例がある** —
`resources/views/mcp/authorize.blade.php`（外部 OAuth client の consent 画面）である。
そこも「**サーバが描画した hidden にトークン相当の値を載せ、明示の POST で確定する**」形で、
docblock に「Inertia / Vite に依存しない standalone Blade（consent はアプリ本体の SPA shell を
必要としないため）」と書いてある。**確認画面はこれと同じ性格**である:
メールのリンクから 1 枚だけ開き、押したら別の画面へ抜ける。SPA の shell も、
前後の画面遷移も、共有 props も要らない。

**なぜ Inertia の prop を採らないか**（Round 5 が挙げた選択肢 (b) を採らない理由）:
Inertia は page object を `history.state` へ載せるため、prop へ置いた瞬間に
**トークンがブラウザの履歴に残る**。`encryptHistory()` で緩和はできるが、
それは「履歴の暗号化に依存する」ことであり、当初の意図をそのまま捨てる判断になる。
Blade なら**そもそも履歴に載らない**ので、依存を増やさずに意図を満たせる。
「他の画面が Inertia だから」は、この 1 枚だけ性格が違う以上、決め手にならない。

#### 変更ファイル

- **新規: `resources/views/auth/email-promotion/confirm.blade.php`**
- 変更: `app/Http/Controllers/Auth/EmailPromotionController.php` —
  `showConfirm()` が `response()->view('auth.email-promotion.confirm', [...])` を返す
  （★`Inertia::render` を呼ばない）

#### 画面の中身（守ること）

```blade
{{-- メール昇格の確認画面。**standalone Blade** (Inertia / Vite に依存しない)。
     形の先例は resources/views/mcp/authorize.blade.php。
     トークンを Inertia の page object へ載せない = ブラウザ履歴に残さないための選択である。 --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- ★この document からの Referer を止める。ヘッダで上書きしない理由は下記。 --}}
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex">
    <title>メールアドレスの確認 | {{ config('app.name') }}</title>
    <style>/* インライン CSS のみ。@vite / 外部 CSS / 外部フォントを一切読み込まない */</style>
</head>
<body>
    <form method="POST" action="{{ route('settings.email-promotion.confirm') }}">
        @csrf
        {{-- ★サーバが描画した hidden。props にも history.state にも載らない --}}
        <input type="hidden" name="token" value="{{ $token }}">
        <button type="submit">このメールアドレスを確定する</button>
    </form>
</body>
</html>
```

| 要求（Round 5） | どう満たすか |
|---|---|
| **CSRF** | `@csrf`。POST は web group の既定の CSRF 検査を通る |
| **`no-store`** | route に **`no-store` alias**（`App\Http\Middleware\NoStoreResponse`）を付ける。加えて認証済み応答には `NoStoreCacheHeadersForAuthenticatedPages` の baseline も当たる（**二重だが、後者は「認証済み」に依存するので明示的に付ける方を正本にする**） |
| **`Referrer-Policy: no-referrer`** | ★**ヘッダではなく `<meta name="referrer">` で効かせる**（理由は下） |
| **外部リソースなし** | `@vite` なし・外部 CSS / フォント / 画像なし・インライン `<style>` のみ・外部リンクなし |
| **design token** | ★**参照しない。参照できない**（下記） |

#### なぜヘッダではなく `<meta name="referrer">` か（実読して確定した）

`App\Http\Middleware\SecurityHeaders` は **web group の middleware** であり、
`$response = $next($request)` の**後**に `Referrer-Policy: strict-origin-when-cross-origin` を
**無条件に `set()` する**。group の middleware は route middleware より外側なので、
**route 側で `no-referrer` を立てても SecurityHeaders が後から上書きする**。
つまり「route に middleware を足す」ではこの要求は満たせない。

満たす道は 2 つある。

1. `SecurityHeaders` に route 名の allowlist を足す
   （既存の `security.capture_permissions_policy_routes` と同じ作法）
2. **document 側の `<meta name="referrer" content="no-referrer">`**

★**2 を採る**。理由は 2 つある。

- **1 は乖離台帳の負債を踏む**。`app/Http/Middleware/SecurityHeaders.php` は
  `docs/template-fingerprints.json` の `entries` に**在り**、かつ
  `tests/Support/TemplateDivergence/adoption-debt.tsv` にも**在る**（実読で確認）。
  採用時債務に在るパスを変更すると「変更したまま債務に残す」が選べず
  （突合 gate が `mutatedDebtPaths` で落ちる）、**同期か逸脱登録かの判断を強制される**。
  **1 枚の画面の Referer を止めるために、テンプレート共有の baseline ヘッダ機構へ
  route 名の分岐を持ち込むのは釣り合わない**（思考原則 2）。
- **baseline だけでも第三者への漏れは既に無い**。`strict-origin-when-cross-origin` は
  **cross-origin には origin しか送らない**ので、トークンを含む完全な URL が
  外部へ出ることはそもそも無い。`meta` が足すのは**同一オリジン内でも送らない**という
  一段の締めであり、これは document 単位で閉じれば足りる。

★したがって F3 の「テンプレート共有ファイルに触れない」という結論は**維持される**
（本節の判断はその結論を守るための選択でもある）。

#### design token を参照しないことの明示

本 Blade は **Vite / Tailwind のパイプラインに乗らない**ので、
`resources/css/tokens.css` の CSS 変数も Tailwind の utility も使えない。
これは本リポジトリで**既に確立した扱い**である —
`resources/views/errors/layout.blade.php` の docblock が
「DESIGN.md の『生 CSS / inline style 禁止』は Vite/Tailwind パイプラインに乗る Svelte
コンポーネントへの規約であり、本 blade はそのパイプラインに依存できないため
inline CSS が正当な例外。色は DS token を参照できないためニュートラルな
プレースホルダを hex 直書きで固定する」と宣言しており、
`legal/layout.blade.php` と `mcp/authorize.blade.php` も同じ形である。

★**本画面も同じ扱いにする**。すなわち **design token を参照しない**。
「token 経由で参照する」と書くと**実装できない約束**になるので書かない。
代わりに**同じ docblock を本 Blade にも置き、例外である理由を明示する**。
色は既存 standalone Blade と同じニュートラル系の hex に揃える
（新しいパレットを作らない）。`tests/js/architecture/contrast-invariant.test.ts` は
token inventory を入力にする検査であり、**Blade は母集団に入らない**（実読で確認）。

#### 目録・gate への影響（Inertia でないことの波及）

- ★`DocumentTitleCoverageTest` は **「Inertia を render する GET named route」だけ**を
  母集団にする（実読で確認）。本 route は Inertia を render しないので**母集団に入らない**。
  タイトルは Blade の `<title>` が持つ。**exemption の登録も不要**である。
- ★`InertiaRenderPageExistsInvariantTest` も同様に無関係（`Inertia::render` を呼ばない）。
- したがって E1 の波及は **`resources/js/` に 1 行も無い**（Svelte のページを足さない）。

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

### E1 — テスト計画

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
  - ★**確認画面が Inertia ではない** — 応答が `X-Inertia` を持たず、
    本文に Inertia の root（`data-page` 属性）が**無い**
    （トークンが page object 経由で履歴へ載る経路が存在しないことの証明）
  - ★**トークンが hidden 項目として描画される**（`name="token"` の hidden が 1 つ在る）
  - ★**`<meta name="referrer" content="no-referrer">` が在る**
  - ★**応答が `Cache-Control: no-store` を持つ**
  - ★**外部リソースを 1 つも読み込まない** — 本文に `@vite` の産物・
    外部 host を指す `<link>` / `<script>` / `<img>` が**無い**
  - ★**GET が状態を変えない**（既出）／**GET だけでは `email_verified_at` が動かない**
- [ ] 新規 `tests/Feature/Auth/EnterpriseOnlyUserEmailTest.php`（A3 と共有）—
      昇格前は `email = null` でパスワード再設定が使えず、昇格後に使えるようになる。
      `email_verified_at` は昇格の前後とも**確認済みのまま**
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### F3 — 乖離台帳の確認（E1 の判断が台帳の結論を守っていること）

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

> ★**この結論は「たまたま」ではなく、E1 で一度守る判断をしている**。
> 確認画面へ `Referrer-Policy: no-referrer` を付ける素直な実装は
> `app/Http/Middleware/SecurityHeaders.php` に route 名の allowlist を足す形だが、
> 同ファイルは **`entries` に在り、かつ `adoption-debt.tsv` にも在る**（実読で確認）。
> 触れば「変更したまま債務に残す」が選べなくなり、同期か逸脱登録かを迫られる。
> E1 は**画面側の `<meta name="referrer">` で閉じる**ことでこれを避けた
> （E1「確認画面の描画方式」節が正本）。
> **共有ファイルへ手を伸ばす前に、画面・route 側で閉じられないかを先に問うこと。**

→ **形式上の登録義務は発生しない**。しかし記録の原則が
「**登録するか迷ったら登録する**」「テンプレートに無い領域への上積みは登録側へ倒す」と定めており、
ログイン試行の機構は**正典 v1 に無い上積み**である。よって登録する。

> **実装時の再確認**: 上記は本設計の時点の照合である。着手時に
> `docs/template-fingerprints.json` を取り直して同じ突き合わせを行う
> （テンプレート台帳が更新されていれば結論が変わりうる）。

### F4 — 設計の要点（偽 IdP に待ち合わせ点を追加）

### 設計の要点

- **テストレーンは外向き HTTP を既定で拒否する**（AGENTS.md）。実 IdP へ出ない。
- 偽の IdP の許可環境は**外部ログインと同じ `testing` / `bughunt.local`** に絞る
  （`local` を外す理由は既存の `SSO_ENVIRONMENTS` の docblock と同じ）
- **同じ事実を 2 か所に書かない**（AGENTS.md ドメイン規約 9）:
  差し替えの宣言は `ExternalFakeDeclaration`、外部到達点の目録は `ExternalSeamInventory` が持つ
- 本番コードが偽の実装のクラス名を参照しないことは既存の `FakeClassReferenceInvariantTest` が全走査する
- **接続先 URL の入力規則は https 必須**なので、偽の IdP は**本番のモデルに登録しない**。
  差し替えの seam でだけ扱う
- ★**discovery の応答に「待ち合わせ点」を差し込めるようにする**（D1 の `verify` の並行テスト用）。
  `FakeOidcDiscoveryService` は、**テストが渡したときだけ**応答を返す直前に呼ぶ
  callback（`?Closure $beforeRespond`）を持つ。既定は `null` で**何もしない**。
  ★sleep を持たせない — 待ちは B4 のハーネスと同じ **ready / go と締切つきの待ち**で行い、
  **時間に依存する同期を作らない**（不安定なテストの元になる）

## Round 5 が「実装時に確認すればよい」とした項目への現時点の回答

承認阻害ではないと理解しているが、答えられるものは答えておく。

| 項目 | 現時点の扱い |
|---|---|
| ssrf-pin v0.4 の確定 API と例外の契約 | 別 TODO `ssrf-pin-v04-upgrade` の完了が**前段①**。受入条件 3 点は設計の「段の順序」に固定済み |
| `PinnedHttpClient` の例外 → 固定理由コードの変換 | v0.4 の確定後に対応表を作る。**取得の失敗で接続の状態を変えない**という上位の規則は版に依存しないので設計は変わらない |
| `COLLATE "C"` と CHECK 制約のスキーマ取得結果の表記 | 制約名を明示したので `pg_constraint.conname` で引ける。テスト計画に「**名前で実在する**」を追加 |
| G2 の保護対象語彙による誤検出 | 実装時に走らせて調整する（gate は各段が自分で緑にする規約） |
| 並行ハーネスの ready/go の同期点 | 前段②の完了後に確定。`verify` は**同じ作法**の待ち合わせ点を偽 IdP 側に置く（新しい同期の道具を作らない） |
| URL query のトークンがプロキシ / CDN のログに残ること | **運用上受容する**と設計に明記済み（保証しない範囲）。緩和は 60 分の期限と一回だけの consume |
| subject を ASCII 限定にするか UTF-8 の非制御文字まで許すか | **UTF-8 の非制御文字まで許す**。禁じるのは C0 と DEL だけで、C1 と書式文字は許す。**入力側 DTO と DB の CHECK が同じ集合**を見る（上記 A2 の対応マトリクス参照） |

## 依頼

上記 5 件の対応で承認阻害が解消できているかを判定してほしい。
とくに次の 3 点を見てほしい。

1. `verify` の三段構成が、指摘された競合（旧取得結果で新しい認証材料を Verified にする）を
   **本当に閉じているか**。`credentials_revision` を主の比較子にした選択と、
   issuer / client_id を第 2 の比較子として重ねた層の置き方は妥当か
2. 確認画面を **standalone Blade** に確定した判断と、
   `Referrer-Policy` を**ヘッダではなく `<meta>` で閉じた**判断（共有 baseline を触らないため）が妥当か。
   design token を「参照しない」と明記したことが DESIGN.md の規約と整合しているか
3. A2 の CHECK 2 本の**保証範囲の書き方**が、言い過ぎでも言い足りなくもないか

施策別の判定と全体判定を出してほしい。

# Round 7: Round 6 の残件 3 件（+ Warning 2 件）への対応

Round 6 の指摘 3 件は**すべて正しく、すべて対応した**（反論・見送りは 0 件）。
Warning 2 件も対応した。変更したのは D1 / D2 / F4 と B4 の「並行テストの土台」節だけで、
Round 6 で APPROVE 済みの 13 施策は**下記の波及以外に変更が無い**。

## 対応マトリクス

### [Critical] D1: tenant-scoped 再取得

- 判断: **対応する**
- 根拠: 指摘が正しい。設計は冒頭で「クラス起点の主キー同一性クエリを書かない」
  （不変条件 3）と宣言しているのに、第 3 段の再取得がまさにその形だった。
  **自分の宣言と矛盾していた**うえ、指摘のとおり**再取得の経路そのものが組織スコープを失う**。
- 対応内容:
  - `verify()` の引数を `(Organization $organization, OrganizationOidcConnection $connection)` にし、
    第 3 段を `$organization->oidcConnections()->whereKey(…)->lockForUpdate()->first()` へ変えた
  - ★指摘のとおり **`update` / `activate` / `disable` / `destroy` のロック付き再取得も
    relation 起点へ統一**し、D1 の docblock に**規約として**書いた
    （「入口で binding が通ったから中は自由」にしない、という理由込みで）
  - controller は **scoped binding で解決した `Organization`** を渡す。
    **payload 由来の組織 id を入れない**（不変条件 1）ことも D2 に明記した
  - テストを 2 本足した: **他組織の接続 id では再取得できない**／
    `ModelDirectFetchInvariantTest` の目録に**本設計由来の登録が 1 件も増えない**

### [Critical] D1: client secret 変更の比較層

- 判断: **対応する**
- 根拠: 指摘が正しい。第 2 の比較子を置いた目的は
  「**唯一の writer という規律だけに依存しない**」ことなのに、見ている値が issuer / client_id だけで、
  **client secret については主張が破れていた**。「主張の射程」と「実際に見ている値」が
  食い違っていたので、片手落ちだった。
- 対応内容: 提示された 3 案のうち **raw ciphertext の digest 比較**を採った。
  - `ConnectionCredentialsSnapshot` へ `clientSecretCiphertextDigest` を追加し、第 3 段で
    `hash('sha256', (string) $fresh->getRawOriginal('client_secret_encrypted'))` と `hash_equals` で比べる
  - ★**平文を復号しない**ので「verify の経路は client secret を一度も復号しない」という
    Round 5 で採った利点を捨てずに済む
  - 他 2 案を採らなかった理由: **Architecture gate は規律の側を強めるだけで、
    実行時に破れたときには何も起きない**。DB trigger は本リポジトリに前例が無く、
    移行の可搬性と可読性を落とす。**実行時に fail-closed へ倒れる**のは digest 比較だけである
  - 比較子が **3 層**になったことを表で明示した
  - ★**digest 比較が fail-closed へ倒れること**も書いた（同じ平文でも再暗号化で暗号文が変わるので、
    「同じ secret を保存し直した」ケースでも拒否側へ倒れる）。
    **後から「バグ」として緩めない**ための記録として、負のコントロールのテストに置いた
  - テストを 2 本足した: **revision を据え置いたまま secret だけ変えても採用されない**（第 3 層の単独証明）／
    **revision を据え置いたまま issuer だけ変えても採用されない**（第 2 層の単独証明）

### [Critical] D1 / F4: verify 待ち合わせテストのデッドロック

- 判断: **対応する**
- 根拠: 指摘が正しく、**設計のままではテストが書けない**。
  B4 のハーネスの作法をそのまま持ち込んだのが誤りだった。
  ハーネスが要るのは「2 つの DB トランザクションを同時に走らせる」場合であり、
  `verify` で要るのは**順序**（スナップショットの後・応答の前）だけである。
- 対応内容: 提示された前者（**同期 callback 注入**）を採った。
  - `beforeRespond` を「待つもの」ではなく「**やって戻るもの**」と定義し直した。
    テストは callback の中で更新 / `disable` / `DB::transactionLevel()` の表明を行って戻る
  - ★**ready / go も sleep も締切も使わない**。順序は呼び出しの構造が保証するので、
    **時間に依存する同期が 1 つも要らない**
  - B4「並行テストの土台」節と F4「設計の要点」の両方を書き換えた。
    ★**デッドロックする理由も本文に残した**（同じ誤りを次にやらないため）

### [Warning] 「二段構成」と「三段」の混在

- 判断: **対応する**。段は 3 つなので **「三段構成」へ統一**した（全 10 か所）。
  ★B4 の「掃除は日次とオンアクセスの**二段**」は別の意味なので触っていない。

### [Warning] `ConnectionCredentialsSnapshot` の説明

- 判断: **対応する**。「client secret を持たない」→
  「**client secret の平文も値型も持たない。持つのは暗号文の digest だけ**」へ書き換えた。
  併せて**画面へ出さない内部の値である**ことも明記した。

### 実装時に確認すればよいとされた項目

- 移行での `Illuminate\Support\Facades\DB` の import → **設計に注記した**
  （`Schema` しか使わない既存の移行から写すと落ちるため）。
  rollback で表ごと落ちることは既に「down() で制約を明示的に落とす必要は無い」と書いてある。

## 変更した箇所の全文

示していない節は Round 6 から変更が無い。

### D1 — 全文（relation 起点の再取得 / 3 層の比較子 / 三段構成）

## D1: 接続の状態遷移サービス

### 変更箇所
- 新規: `app/Services/EnterpriseSso/OidcConnectionTransitionService.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/ConnectionCredentialsSnapshot.php`
  （`verify` の第 1 段が読む**認証材料のスナップショット**。
  ★**client secret の平文も値型も持たない** — 持つのは
  **暗号文そのものの SHA-256 digest** だけである（復号せずに「書き換わったか」だけを見る）。
  `$hidden` や `toArray()` の対象にもならない内部の値であり、**画面へ出さない**）
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
 *   `verify` は下の**三段構成**で線形化する。
 *
 * ★ロックしないと次の競合が起きる:
 *   (1) 管理操作が「身元 0 件」を確認 → (2) callback が行をロックして JIT →
 *   (3) 管理操作が issuer を更新 / 物理削除
 *   = **身元があるのに名前空間が変わる / 身元だけが消える**。
 *
 * ★**ロック付きの再取得は 5 操作とも relation 起点に統一する** (Round 6 の [Critical] への回答)。
 *
 *     $organization->oidcConnections()->whereKey($id)->lockForUpdate()->first()
 *
 *   クラス起点の主キー同一性クエリ (`OrganizationOidcConnection::query()->whereKey(…)`) で書かない —
 *   AGENTS.md セキュリティ不変条件 3 が deny-by-default で分類を求める形であり、
 *   かつ**再取得の経路そのものが組織スコープを失う**。
 *   親の `$organization` は route の scoped binding が解決したものだけを受け取り、
 *   **payload 由来の組織 id を入れない** (不変条件 1)。
 *   ★入口の binding が済んでいても**再取得の側で改めて relation 起点にする**。
 *   「入口で確認したから中は自由」は、経路が増えたときに必ず崩れる。
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

### `verify` だけは三段構成にする（Round 5 の [Critical] D1 / D2 への回答）

**解きたい競合**は次の 3 手順である。

1. `verify` が**旧**の issuer で discovery / JWKS を取得する
2. その間に `update` が issuer / client_id / client secret を変える
3. `verify` が接続の行をロックし、**新しい認証材料を旧い取得結果で `Verified` にする**

外向き取得の前にロックを取れば消えるが、それは**通信の間ロックを保持する**形であり、
B4 / C2 が避けている形と同じになる（IdP が遅い・落ちているときに管理操作が全部詰まる）。
そこで **`verify` だけを明示の三段構成**にする。

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
public function verify(Organization $organization, OrganizationOidcConnection $connection): VerifyOutcome
{
    // ── 第 1 段: スナップショット (ロックなし)
    // ★client secret の**平文も値型も持たない**。verify は discovery と JWKS を取るだけで
    //   秘密を必要としない = **verify の経路は秘密を一度も復号しない** (D2 の DTO と同じ思想)。
    //   ただし「secret が変わったか」を復号せずに見るため、**暗号文そのものの digest** は持つ (下記)。
    $snapshot = ConnectionCredentialsSnapshot::of($connection);
    //   → readonly {int $connectionId, string $issuer, string $clientId,
    //               int $credentialsRevision, string $clientSecretCiphertextDigest}

    // ── 第 2 段: 外向き取得 (ロックなし・トランザクションの外)
    // 取得の失敗で接続の状態を変えない (上の「取得の失敗で接続を殺さない」)。
    $metadata = $this->discovery->fetch($snapshot->issuer);   // B1。PinnedHttpClient 経由

    // ── 第 3 段: 一致の再確認と遷移 (ここで初めてトランザクションと行ロック)
    return DB::transaction(function () use ($organization, $snapshot, $metadata): VerifyOutcome {
        // ★**relation 起点で引く**。クラス起点の主キー同一性クエリ
        //   (OrganizationOidcConnection::query()->whereKey(…)) で書かない —
        //   AGENTS.md セキュリティ不変条件 3 が deny-by-default で分類を求める形であり、
        //   かつ**再取得の経路そのものが組織スコープを失う**。
        //   親は scoped binding で解決済みの $organization であり、
        //   ★**payload 由来の組織 id をここへ入れない** (不変条件 1)。
        $fresh = $organization->oidcConnections()
            ->whereKey($snapshot->connectionId)
            ->lockForUpdate()
            ->first();

        // 接続が消えていた (または組織の外へ出た) → 結果を捨てる (アーリーリターン)
        if ($fresh === null) {
            return VerifyOutcome::connectionGone();
        }

        // ★**主の比較子は credentials_revision** である。
        //   認証材料 (issuer / client_id / client secret) を変える経路は D1 の 1 メソッドだけで、
        //   そこが必ず +1 する。1 つの整数で「材料が変わったか」を漏れなく表せる。
        if ($fresh->credentials_revision !== $snapshot->credentialsRevision) {
            return VerifyOutcome::staleCredentials();   // ★結果を捨てる。Draft のまま
        }

        // ★**第 2 の比較子**として、認証材料の**値そのもの**を 3 つとも突き合わせる。
        //   これは主の比較子の代わりではなく、「**+1 を忘れた書き手がいたら落ちる**」ための層である
        //   (revision は書き手の規律に依存する値なので、値を見る層を 1 枚重ねる)。
        //   ★**3 つとも見る**のが要点である。issuer / client_id だけだと、
        //     「**client secret を変えたのに +1 を忘れた**」場合に古い結果が採用されてしまい、
        //     この層が主張している「規律に依存しない」が client secret について成立しない。
        //   ★client secret は**復号しない**。**暗号文そのものの digest** を比べる —
        //     復号せずに「値が書き換わったか」だけを見られる (verify は平文を必要としない)。
        //     暗号文は保存のたびに変わりうる (同じ平文でも再暗号化で別の暗号文になる) ので、
        //     この比較は**空振りする側 = 拒否する側**へ倒れる。fail-closed であり安全側である。
        $freshDigest = hash('sha256', (string) $fresh->getRawOriginal('client_secret_encrypted'));

        if ($fresh->issuer !== $snapshot->issuer
            || $fresh->client_id !== $snapshot->clientId
            || ! hash_equals($snapshot->clientSecretCiphertextDigest, $freshDigest)
        ) {
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

**比較子は 3 層である（Round 6 の [Critical] への回答）**

| 層 | 見るもの | 何を捕まえるか |
|---|---|---|
| **主** | `credentials_revision` | 認証材料の**あらゆる**変更（書き手が規律を守っている限り） |
| **第 2** | `issuer` / `client_id` の**実値** | ★**`+1` を忘れた書き手**（issuer / client_id を変えた場合） |
| **第 3** | `client_secret_encrypted` の**暗号文の digest** | ★**`+1` を忘れた書き手**（client secret を変えた場合）。★復号しない |

> Round 6 の指摘のとおり、第 2 層が issuer / client_id **だけ**だと、
> 「client secret を変えながら revision を増やし忘れた」場合に古い結果が採用されてしまい、
> この層の主張（**唯一の書き手という規律だけに依存しない**）が client secret について破れる。
> **暗号文の digest を比べれば、平文を復号せずに同じ層を張れる**。

**この形が保証すること / しないこと**

| | 内容 |
|---|---|
| **保証する** | 外向き取得の**開始から完了までの間**に認証材料が変わったなら、その `verify` の結果は**採用されない**（`Draft` のまま拒否される） |
| **保証する** | 外向き取得の**間、接続の行のロックを保持しない**（IdP が遅くても管理操作が詰まらない） |
| **保証する** | `verify` の経路は **client secret を一度も復号しない**（比べるのは暗号文の digest だけ） |
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
      （`verify` の三段構成。**Round 5 の [Critical] が要求した並行テスト**）
  ★同期の割り込み注入（F4 の `beforeRespond`）で作る。**待ち合わせを使わない**
  （理由は B4「並行テストの土台」節。同一プロセスで待たせるとデッドロックする）
  - ★**本命**: **`verify` の外部取得中に認証材料を更新すると、古い `verify` の結果が採用されない** —
    `beforeRespond` の中で issuer を変えて戻る。`verify()` から戻ったあと、接続が
    **`Draft` のまま**で `verified_at` が null であることを確かめる
  - **client secret だけを変えた場合も採用されない**（issuer / client_id は同じなので、
    **revision と暗号文 digest のどちらか**が効いていることの証明）
  - ★**`credentials_revision` を据え置いたまま client secret だけ変えても採用されない**
    （**第 3 の比較子＝暗号文 digest の単独の証明**。DB へ直接書いて revision を増やさずに
    secret を差し替える。この 1 本が無いと「+1 を忘れた書き手」への主張が
    client secret について空手形になる = Round 6 の指摘そのもの）
  - ★**`credentials_revision` を据え置いたまま issuer だけ変えても採用されない**
    （**第 2 の比較子の単独の証明**）
  - **表示名だけを変えた場合は `verify` が成功する**（★負のコントロール。
    `updated_at` で代用していたら落ちる。認証に関与しない更新を巻き込まないこと）
  - ★**同じ平文の client secret を保存し直しただけでも採用されない**
    （通常経路では revision の +1 が先に効くが、**digest の層も同じ向きに倒れる**
    ことをここで固定する。暗号文は再暗号化で変わるので、digest 比較は
    **偽陽性の側＝拒否の側**へ倒れる = fail-closed である。運営はもう一度押せばよい。
    ★この挙動を「バグ」として後から緩めないための記録である）
  - **接続が取得中に削除されたら `Verified` にしない**（行が消えている）
  - ★**他組織の接続 id では再取得できない** — relation 起点であることの証明
    （組織を跨いだ id を渡すと `connectionGone` になり、`Verified` にならない）
  - **同じ材料の `verify` が二重に走っても例外にならず、2 回目は遷移しない成功になる**
  - **`Active` / `Disabled` から `verify` を呼ぶと定義外の遷移として例外になる**
  - **外向き取得の間に接続の行がロックされていない** — `beforeRespond` の中で
    **同じ接続**の `disable` が**待たずに完了する**ことで示す
    （「ロックを保持していない」を実挙動で固定する。docblock の主張の裏取り）
  - **第 2 段がトランザクションの外にある** — `beforeRespond` の中で
    `DB::transactionLevel() === 0` を表明する
- [ ] `update` が認証材料を変えると **`credentials_revision` が +1 される**／
      **表示名だけの更新では増えない**（`OidcConnectionTransitionServiceTest` 側）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 状態が増えると画面の分岐が増える。4 状態で固定し、追加しない。

---

### D2 — 削除と更新の制限（controller が Organization を渡す規約を追記）

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
| **`verify` の 1 本** | **三段構成**（ロックなしでスナップショット → ロックなしで外向き取得 → トランザクション + 行ロックで `credentials_revision` の一致を再確認 → 一致時のみ遷移） | ★**外向き HTTP の間ロックを保持しない**ため。詳細は D1「`verify` だけは三段構成にする」節が正本 |

★したがって controller 側でも `verify` だけは**トランザクションの張り方が違う**。
`verify` の action は D1 の `verify()` を呼ぶだけにし、
**controller 側で外向き取得を包むトランザクションを張らない**。

★**5 操作すべてで、controller は D1 へ「scoped binding で解決した `Organization`」を渡す**
（Round 6 の [Critical] への回答）。D1 側はロック付きの再取得を
`$organization->oidcConnections()->whereKey(…)->lockForUpdate()` の**relation 起点に統一**する。
route は既に `scopeBindings()` で親子の整合を binding の段で閉じている（層 2 = 404）ので、
controller が**組織 id を payload から受け取ることは無い**（不変条件 1）。
★**「入口で binding が通ったから中は自由」にしない** — 再取得の側でも組織スコープを通す。

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
  - ★**5 操作のロック付き再取得がすべて relation 起点である** —
    `ModelDirectFetchInvariantTest` / `DirectFetchInventory` に
    **本設計由来のクラス起点の主キー同一性クエリが 1 件も増えない**ことで固定する
    （deny-by-default なので、増やせば目録への登録が要り、レビューで必ず見える）
  - **`verify` の action が外向き取得を包むトランザクションを張らない**
    （D1 の三段構成を controller 側が壊していないことの結線。
    偽 IdP の待ち合わせ点で `DB::transactionLevel() === 0` を観測する）
  - **`verify` が `staleCredentials` を返したとき、画面に「材料が変わったのでやり直す」旨が出る**
    （★一様な応答にしない。認可を通った運営操作なので理由を具体的に伝える）
  - **client secret を更新すると一覧の状態が Draft になる**（D1 との結線）
  - **身元がある接続の削除・issuer/client_id の更新が拒否され、押下時にエラーが表示される**
    （ボタンが disabled になっていないことも確認する = 禁止事項 8）
  - **callback と確認の失敗で入力が flash されない**（`code` / `state` / `token` が old input に残らない）
- [ ] 新規 `tests/js/.../oidc-connection.test.ts` — 状態の値域の TS 定数が PHP enum と一致する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### B4 — 並行テストの土台（待ち合わせを外し、同期の割り込み注入へ）

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

★**D1 の `verify` の割り込みテストは、このハーネスに乗せない**。理由は形が違うからである。
B4 / C1 / C2 が要るのは「**2 つの DB トランザクションを本当に同時に走らせる**」ことで、
これは実プロセスでないと作れない。一方 `verify` で要るのは**順序**だけである —
「スナップショットを取った**後**・応答を返す**前**に更新が割り込む」が作れれば足りる。

★**待ち合わせ（ready / go）を使わない**（Round 6 の [Critical] への回答）。
同一プロセスで「callback が ready を立てて go を待つ」形にすると**必ずデッドロックする**:
PHP の呼び出しは同期なので、(1) テストが `verify()` を呼ぶ → (2) callback が go 待ちで止まる →
(3) `verify()` が戻らないので**テストは更新も go の作成もできない**。

★採る形は**同期の割り込み注入**である。偽 IdP（F4）の応答直前の callback が、
**そのまま自分で割り込みを行って戻る**:

```php
$fake->beforeRespond(function () use ($connection): void {
    // ★この時点は「スナップショットを取った後・応答を返す前」である。
    //   ここで認証材料を更新する = 割り込みそのもの。待たない。
    $this->transitions->update($organization, $connection, issuer: 'https://new.example.test');

    // ロックを保持していないことの表明もここで行う (同じ接続の disable が待たずに通る)。
    // 第 2 段がトランザクションの外にあることの表明もここで行う。
    expect(DB::transactionLevel())->toBe(0);
});
```

- **時間に依存しない**（sleep も締切も要らない）。順序は呼び出しの構造そのものが保証する
- **新しい同期の道具を足さない**（ready / go すら使わない）

★この分割は手抜きではなく**保証の切り分け**である。`verify` の線形化が依存しているのは
(1)「取得の間ロックを持たない」(2)「ロックの中で版と値を比べる」の 2 点で、
**本テストが直接示すのは (1) と (2) の判定そのもの**である。

★**保証しないことも書く**: 上記は「**同時に走る 2 つの `verify` の第 3 段が互いに排他される**」を
**直接は示さない**（同一プロセスの待ち合わせでは 2 つの実トランザクションを同時に走らせられない）。
第 3 段の排他が依拠するのは `lockForUpdate()` という**同じ 1 つの機構**であり、
それが実プロセスで効くことは **B4 の実プロセス版 1 本**が示している。
つまりここは「**機構は別途証明済み、本テストは適用箇所を証明する**」という
2 段の論拠であって、`verify` の同時実行そのものの実測ではない。

### F4 — 設計の要点（`beforeRespond` の定義を「やって戻る」へ）

### 設計の要点

- **テストレーンは外向き HTTP を既定で拒否する**（AGENTS.md）。実 IdP へ出ない。
- 偽の IdP の許可環境は**外部ログインと同じ `testing` / `bughunt.local`** に絞る
  （`local` を外す理由は既存の `SSO_ENVIRONMENTS` の docblock と同じ）
- **同じ事実を 2 か所に書かない**（AGENTS.md ドメイン規約 9）:
  差し替えの宣言は `ExternalFakeDeclaration`、外部到達点の目録は `ExternalSeamInventory` が持つ
- 本番コードが偽の実装のクラス名を参照しないことは既存の `FakeClassReferenceInvariantTest` が全走査する
- **接続先 URL の入力規則は https 必須**なので、偽の IdP は**本番のモデルに登録しない**。
  差し替えの seam でだけ扱う
- ★**discovery の応答に「割り込みの注入点」を差し込めるようにする**（D1 の `verify` の割り込みテスト用）。
  `FakeOidcDiscoveryService` は、**テストが渡したときだけ**応答を返す直前に呼ぶ
  callback（`?Closure $beforeRespond`）を持つ。既定は `null` で**何もしない**。
  ★**callback は「待つ」ものではなく「やって戻る」もの**である（Round 6 の [Critical] への回答）。
  同一プロセスで callback に待たせると、`verify()` が戻らないためテスト本体が
  割り込みを起こせず**デッドロックする**。テストは callback の中で更新を実行してそのまま戻る。
  ★したがって **sleep も ready / go も締切も持たせない** — 順序は呼び出しの構造が保証する

### A2 — 移行 2 の保証範囲（`DB` facade の import を注記）


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

## 依頼

上記 3 件（+ Warning 2 件）の対応で承認阻害が解消できているかを判定してほしい。
とくに次を見てほしい。

1. relation 起点への統一が、`verify` だけでなく **5 操作すべて**で漏れなく書けているか
2. **3 層の比較子**が、指摘された「規律だけに依存する穴」を本当に閉じているか。
   digest 比較が **fail-closed の側へ倒れる**という性質の書き方が妥当か
3. **同期 callback 注入**でテストが実際に成立するか（デッドロックしないか）、
   かつそれで何を示せて何を示せないかの切り分けが正確か

施策別の判定と全体判定を出してほしい。

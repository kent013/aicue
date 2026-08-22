# 対応マトリクス: design-review Round 6

Round 6 の全体判定は **CHANGES_REQUESTED**。ただし **16 施策中 13 が APPROVE** になり、
Round 5 の残件だった **A2 / B4 / C1 / E1 はすべて APPROVE** で閉じた。
残る 3 件（D1 / D2 / F4）は、いずれも `verify` の三段構成の**実装例**への指摘である。
**3 件とも対応する**（反論・見送りは 0 件）。

## [Critical] D1: tenant-scoped 再取得（クラス起点の主キー同一性クエリ）

- 判断: **対応する**
- 根拠: 指摘が正しい。設計は冒頭で「クラス起点の主キー同一性クエリを書かない」
  （AGENTS.md セキュリティ不変条件 3）と宣言しているのに、
  第 3 段の再取得を `OrganizationOidcConnection::query()->whereKey(…)` で書いていた。
  **自分の宣言と矛盾している**うえ、指摘のとおり**再取得の経路そのものが組織スコープを失う**。
  入口の scoped binding が済んでいることは、再取得の側を免除する理由にならない
  （「入口で確認したから中は自由」は経路が増えたときに必ず崩れる）。
- 対応内容:
  - `verify()` の引数を `(Organization $organization, OrganizationOidcConnection $connection)` にし、
    第 3 段の再取得を
    `$organization->oidcConnections()->whereKey($snapshot->connectionId)->lockForUpdate()->first()`
    へ変えた
  - ★**`verify` だけでなく `update` / `activate` / `disable` / `destroy` の
    ロック付き再取得も relation 起点へ統一**した（指摘のとおり）。D1 の docblock に規約として書いた
  - controller は **scoped binding で解決した `Organization`** を渡す。
    **payload 由来の組織 id を入れない**（不変条件 1）ことも明記した
  - 再取得が空になる場合を `connectionGone` に寄せた（消えた場合と組織の外に出た場合が同じ結果になる）
  - テストに **「他組織の接続 id では再取得できない」** と、
    `ModelDirectFetchInvariantTest` / `DirectFetchInventory` に
    **本設計由来の登録が 1 件も増えない**ことを追加した

## [Critical] D1: client secret 変更の比較層

- 判断: **対応する**
- 根拠: 指摘が正しい。第 2 の比較子を置いた目的は
  「**`+1` を忘れた書き手がいたら落ちる**（唯一の writer という規律だけに依存しない）」ことなのに、
  見ている値が issuer / client_id だけだったので、
  **client secret を変えて revision を増やし忘れた場合にその主張が破れる**。
  「主張の射程」と「実際に見ている値」が食い違っており、片手落ちだった。
- 対応内容: 提示された 3 案のうち **raw ciphertext の digest 比較**を採った。
  - `ConnectionCredentialsSnapshot` に `clientSecretCiphertextDigest` を足し、
    第 3 段で `hash('sha256', (string) $fresh->getRawOriginal('client_secret_encrypted'))` と
    `hash_equals` で比べる
  - ★**平文を復号しない**ので、「verify の経路は client secret を一度も復号しない」という
    設計の主張は保たれる（Round 5 で採った利点を捨てずに済む）
  - 他の 2 案（Architecture gate / DB trigger）を採らなかった理由:
    gate は「書き手を 1 か所に固定する」という**規律の側**を強めるだけで、
    **実行時に破れたときには何も起きない**。DB trigger は本リポジトリに前例が無く、
    移行の可搬性と可読性を落とす。**実行時に fail-closed へ倒れる**のは digest 比較だけである
  - 比較子が **3 層**になったことを表で明示した（主 = revision / 第 2 = issuer・client_id の実値 /
    第 3 = 暗号文の digest）
  - ★**digest 比較が fail-closed へ倒れること**も書いた: 同じ平文でも再暗号化で暗号文が変わるため、
    「同じ secret を保存し直した」ケースでも verify は拒否側へ倒れる。
    これは**空振りではなく安全側**であり、**後から「バグ」として緩めない**ための記録として
    負のコントロールのテストに置いた
  - テストを 2 本足した: **revision を据え置いたまま secret だけ変えても採用されない**（第 3 層の単独証明）/
    **revision を据え置いたまま issuer だけ変えても採用されない**（第 2 層の単独証明）

## [Critical] D1 / F4: verify 待ち合わせテストのデッドロック

- 判断: **対応する**
- 根拠: 指摘が正しく、**設計のままではテストが書けない**。PHP の呼び出しは同期なので、
  (1) テストが `verify()` を呼ぶ → (2) fake の callback が go 待ちで停止 →
  (3) `verify()` が戻らないのでテスト本体は update も go の作成もできない、で確実に止まる。
  B4 のハーネスの作法をそのまま持ち込んだのが誤りで、**ハーネスが要るのは
  「2 つの DB トランザクションを同時に走らせる」場合**であり、
  `verify` で要るのは**順序**だけだった（「スナップショットの後・応答の前」）。
- 対応内容: 提示された前者（**同期 callback 注入**）を採った。
  - `beforeRespond` は「待つもの」ではなく「**やって戻るもの**」と定義し直した。
    テストは callback の中で更新 / `disable` / `DB::transactionLevel()` の表明を行い、そのまま戻る
  - ★**ready / go も sleep も締切も使わない**。順序は呼び出しの構造そのものが保証するので、
    **時間に依存する同期が 1 つも要らない**（不安定なテストの元を作らない）
  - B4「並行テストの土台」節と F4「設計の要点」の両方を、この形へ書き換えた
    （**デッドロックする理由も残した** — 同じ誤りを次にやらないため）

## [Warning] 「二段構成」と「三段」の混在

- 判断: **対応する**
- 対応内容: 指摘のとおり段は 3 つ（外部取得の前 / 外部取得 / commit の段）なので、
  **「三段構成」へ統一**した（見出し・本文・施策一覧・D2 の表・テスト計画の全 10 か所）。
  ★B4 の「掃除は日次とオンアクセスの**二段**」は別の意味なので**触っていない**。

## [Warning] `ConnectionCredentialsSnapshot` の説明

- 判断: **対応する**
- 対応内容: digest を持つことになったので、
  「client secret を持たない」→「**client secret の平文も値型も持たない。持つのは暗号文の digest だけ**」
  へ書き換えた（指摘のとおり、前の書き方では digest まで否定して読める）。
  併せて **画面へ出さない内部の値である**ことも明記した。

## Codex が「実装時に確認すればよい」とした項目

- 移行での `Illuminate\Support\Facades\DB` の import / rollback で表ごと落ちること
  → **前者は設計に注記した**（`Schema` しか使わない既存の移行から写すと落ちるため）。
  後者は既に「down() で制約を明示的に落とす必要は無い」と書いてある。

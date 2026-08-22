# 概念設計レビュー Round 4

Round 3 の [Critical] 4 件・[Warning] 3 件への対応を反映した。対応マトリクスと修正後の全文を示す。

# 対応マトリクス: conceptual-review Round 3

## [Critical] 予約語を「登録・分類・route 照合」するだけで、保存を拒否する契約が無い
- 判断: **対応する**
- 根拠: 指摘が正しい。予約語設定と route 整合検査が在っても、作成・改名で `admin` を保存できれば
  AG-039 は未充足である。成功条件も「検査が実在すること」しか要求していなかった。
- 対応内容: 改善アイデア B へ「保存を拒否する契約を型で持つ」節を追加した。
  - **型を 2 段にする**。`OrganizationSlug` = 構文だけの不変型 /
    `AssignableOrganizationSlug` = 構文妥当 **かつ** 非予約語の不変型。
  - **保存経路が受けられるのは `AssignableOrganizationSlug` だけ**にし、構文だけの型を
    保存へ渡す道を型で消す (書き込み経路 1 本を Architecture 検査で固定)。
  - **`fromInput()` と `deriveFromName()` の両方**で予約語を拒否する
    (導出側を素通りさせると「管理」という名前の組織を作るだけで `admin` が取れる)。
  - 予約語での新規作成・改名の拒否を Feature テストで固定し、成功条件 3 に入れた。
  - 既存識別名が新しい予約語に該当する場合の migration は衝突時と同じ **fail-closed**
    (自動で付け替えない) と明記した。

## [Critical] AG-047 の分類単位が「入口」だけでは不十分 (1 入口に複数の解決点がある)
- 判断: **対応する**
- 根拠: 指摘が正しい。1 つの controller / command / Filament action / MCP handler が
  `actor_derived` の解決と任意文字列での検索を**両方**持てる。入口を 1 種別へ分類すると後者が隠れる。
- 対応内容: 母集団を **2 段**にした。
  - 第 1 段: 機械経路の**全入口**を機械抽出して完全一致で目録化する。
  - 第 2 段: 各入口の中の「組織または組織帰属資源を確定する**すべての解決点**」を抽出し、
    **解決点ごとに**許可 provenance を分類する。複数あればそれぞれが独立に契約を満たす。
  - `not_organization_scoped` を名乗れるのは**解決点が 0 件であることを検査した入口だけ**にした
    (申告だけでは通さない)。
  - 成功条件 5 を 2 段の形へ書き換えた。

## [Critical] Filament の母集団条件「組織解決を持ち得る構成要素」が循環している
- 判断: **対応する**
- 根拠: 指摘が正しい。何が「持ち得る」かを走査器が事前に判定できるなら、その判定から漏れた
  構成要素は母集団にも入らず全数性を失う。AGENTS.md §走査器の共通規約 (b) の
  「母集団が 0 件なのに緑」と同じ失敗の別形である。
- 対応内容: Filament の母集団を「対象 panel に属する **application-defined の構成要素全件**
  (Resource / Page / RelationManager / Widget / Action …)」へ改め、
  **組織解決の有無で絞らない**ことを明記した。対象種別の正本は詳細設計に置き、
  **未知の Filament 構成種別が現れたら fail-closed** にすることも書いた。

## [Critical] 旧 URL の「走査根ベース」と、列挙した走査根・表・成功条件が一致していない
- 判断: **対応する**
- 根拠: 指摘が正しい。走査根に `doc/` / README / manifest / テスト / bug-hunt inventory が
  入っていないのに、表ではそれらを対象として挙げていた。成功条件も 9 行の表を「6 系統」と呼んでいた。
- 対応内容: 母集団を **git 追跡下ファイル全数**とし、そこから
  「**走査する / 走査しない (理由付き) / 未分類**」の 3 分類へ**排他的に**割る形へ改めた
  (AGENTS.md §禁止する文 の 3 分類と同じ形)。未分類が 1 件でも現れたら赤になる。
  「走査する」には PHP 全層 / `resources/js/` / `resources/views/` / `tests/` (js・Browser 含む) /
  `docs/` / `doc/` / README / manifest / `public/*.js` / bug-hunt の `inventory/` と
  `.claude/` 配下の目録・注釈 / 生成テンプレートを入れた。
  従来の表は「見落としやすいので詳細設計で個別に確認する箇所」へ格下げし、**母集団の定義ではない**と明記した。
  成功条件 6 を「定義した走査根で検査可能なリポジトリ内の旧 URL の生成・記述が 0 件。
  未分類の置き場所・形式が 0 件。リポジトリ外の状態は保証外」へ書き換えた。

## [Warning] 検証コマンドの件数表記が「9 本」になっている (実際は 10 本)
- 判断: **対応する**
- 根拠: 単純な誤り。
- 対応内容: 「10 本」へ訂正した。

## [Warning] migration 前処理の順序が「小文字化 → 衝突確認」に読める
- 判断: **対応する**
- 根拠: そのとおり。更新してから検査すると、衝突を潰した後になる。
- 対応内容: 4 段の順序として固定した — (1) 更新せずに正規化後の値を計算 →
  (2) 衝突を検査し、あれば fail-closed で停止 → (3) 衝突が無い場合だけ小文字化 →
  (4) `CHECK` と `UNIQUE` を付与。同じ順序を予約語にも適用することを明記した。

## [Warning] `OrganizationSlug` の不変条件 (構文だけか、保存可能か) が曖昧
- 判断: **対応する**
- 根拠: 型名から後者にも読めるという指摘は正しい。
- 対応内容: 「型の境界」節を 2 段の型として書き直し、それぞれの不変条件を明記した
  (`OrganizationSlug` = 構文的に妥当で正規化済み。**保存してよいことは意味しない** /
  `AssignableOrganizationSlug` = 構文妥当かつ非予約語。**保存経路が受け取れるのはこの型だけ**)。

## [Suggestion] 使命整合 / DB 制約 / 期待効果 / 型境界の追認
- 判断: **見送る** (肯定的評価であり変更を要さない)


---

## 修正後の概念設計 (全文)

# 概念設計: organization-tenancy-ag-catchup

家系の機能台帳 (lctl) feature `organization-tenancy` への追従。

| 項目 | 値 |
|---|---|
| 正典 feature revision | `46-c1830b632b4d` |
| 台帳 revision | `81f0e624363b0c707a424c0695253eb6d1536451` |
| aicue セル | `status: pending` / `version: t0` / `assessment: divergence_candidate` |
| 観測点 (台帳側) | `aicue@e7f954ed` 実読 (差分巡回 2026-08-18 夕) |
| 本設計の実測点 | 本リポジトリ HEAD (`2dc4e2ec`) |

---

## 背景・課題

### 正典が求めていること

`organization-tenancy` の正典は「1 つのサービスを複数の組織が使うための土台」であり、
基点はテンプレート t0。2026-08-05 のオーナー裁定 (AG-036〜AG-047) で **4 系統**が確定した。

| 系統 | 裁定 | 内容 |
|---|---|---|
| (1) 前段の帰属検証 | AG-036 | URL に現れた資源が現在の組織に属することを**入力検証より前**の段で確かめ、違えば 403 ではなく **404**。403 は資源の存在を認めてしまうため |
| (2) 現在組織の決め方 | AG-037 | 「いまどの組織として操作しているか」は **URL だけ**で決める。保持列と切替エンドポイントは撤去する。**2 方式の併存は認めない** |
| (3) 個人組織 | AG-038 | 個人組織を種別として区別するのをやめ、種別フラグを撤去する |
| (4) 識別名 (slug) | AG-039 / AG-039b / AG-039c / AG-046 / AG-047 | URL に人が読める識別名を出す。予約語 (理由 3 分類必須)・文字種と正規化・大小無視の一意性・改名 (30 日 5 回まで / 旧識別名は解放) ・**機械が使う経路は不変の内部識別子で組織を指す** |
| (追加) 権限判定 | AG-040 | 権限ライブラリのチーム厳格チェックを `true` に揃える (条件なしの確定) |

正典の現行実装は laravel-claude-template (`T104` / `@454943e`) と aigenba の 2 本で、
どちらも `implemented`。残る 4 本 (aicue / spirux / motivation / metamovics のうち
metamovics はテンプレート全量取り込みで implemented) が追従対象である。

### aicue セルの pending 理由 (台帳の記述をそのまま写す)

> 現在の組織の保持列・切替の route・種別フラグがそろって実在し AG-037 / AG-038 は未充足。
> 識別名は URL に出しているが予約語の設定も改名の経路も無く AG-039 系 / AG-046 / AG-047 も未充足。
> AG-040 だけが設定値の真で満たされている。
> 実読で新たに分かった点として、`app/Services/Organization/CurrentOrganizationResolver.php` は
> 「保持列が指す組織を所属の再確認で自己修復する」実装であり、**AG-037 が撤去を求めている当の方式を
> 強化する向きに育っている (追従の距離が縮まらずに広がる)**。

> 【領域深掘り 2026-08-17】現在の組織の保持列の読み書きは利用者モデル・サービス提供者・画面へ渡す
> 前段処理・通知の controller・組織の controller (書き込み)・切替の controller の 6 か所に分布し、
> 切替の経路も種別フラグも健在である。…
> **組織の経路の鍵が面によって割れている (切替だけ主キー、設定・更新・招待は識別名)。
> AG-037 の撤去でこの不揃いも同時に消える。**

### 本設計での実測 (台帳の観測を HEAD で裏取りした結果)

| 事実 | 実測 |
|---|---|
| `users.current_organization_id` | `database/migrations/2026_06_11_074000_create_organizations_tables.php` で定義。production コード 10 ファイルが読み書き (`User` / `AppServiceProvider` / `HandleInertiaRequests` / `NotificationCenterService` / `NotificationController` / `OrganizationProvisioningService` / `OrganizationMembershipService` / `CurrentOrganizationResolver` / `OrganizationController` / `OrganizationSwitchController`) |
| 切替 route | `POST organizations/{organization}/switch` (`organizations.switch`) が実在。**この 1 本だけ主キー binding**で、他の組織 route は `{organization:slug}` |
| 自己修復 | `CurrentOrganizationResolver::resolve()` / `heal()` が条件付き UPDATE で保持列を書き戻す |
| current 依存 controller | `ResolvesCurrentOrganization` trait の利用者が **23 クラス** (Projects 系 11 / Capture 系 4 / Billing 系 2 / Onboarding 系 3 / Dashboard / Admin / middleware 2) |
| 種別フラグ | `organizations.is_personal` 実在。`OrganizationProvisioningService::provisionPersonalOrganization()` の冪等判定がこのフラグ |
| 識別名 | `Str::slug($name) ?: 'org'` + `'-'.Str::lower(Str::random(6))` の**生成のみ**。文字種検証・長さ検証・予約語・正規化・大小無視の一意性・改名経路のすべてが 0 件 |
| 機械経路 (AG-047) | `routes/api.php` / `routes/ai.php` / `routes/console.php` / Filament に組織の URL 引数は 0 件 (`OrganizationRouteParamWebOnlyInvariantTest` が `{organization}` param を web+auth 限定に固定済み)。**実態は満たしているが、識別名で組織を指さないことを固定する検査が無い** |
| AG-040 | `config/laratrust.php` の `teams.strict_check` が真 (充足) |

### 問題の本質 (なぜ直すのか)

AI-CUE の使命は「現場に既にある SOP を起点に、AI が設計した動画シナリオをスマホ (PWA) で
ナビ撮影させ、専門知識ゼロの作業者でも標準化されたマニュアル動画を作れるようにする」ことである。

現行の「サーバ側に保存された最後に見た組織」方式は、この使命の**現場側**で直接害になる:

1. **共用端末で誤組織の手順書を撮る**。撮影 PWA (`start_url=/app`) は URL に組織を持たない。
   同じ端末を複数の作業者・複数の取引先組織で使い回すと、開いた瞬間に何が出るかは
   「サーバに残っている前回の値」で決まる。作業者は URL を読まないので気付けない。
2. **URL を共有できない**。「この手順書を見て」と送った URL が、受け手の保持列次第で
   別組織の画面 (または 404) になる。現場の申し送りが URL で完結しない。
3. **自己修復が事故を静かに拡大する**。`CurrentOrganizationResolver` は保持列が壊れていると
   「所属組織 id 昇順の先頭」へ勝手に付け替える。利用者から見ると**組織が黙って切り替わる**。
4. 台帳の言うとおり、この方向の実装が育つほど正典への追従距離は**広がる**。

---

## 改善アイデア

aicue に割り当てられた未追従項目 **AG-037 / AG-038 / AG-039 系 / AG-046 / AG-047** だけを
スコープにし、正典と同じ形へ揃える。5 系統。

### A. 識別名を値として確定させる (AG-039b / AG-039c)

- 識別名を**値オブジェクト 1 本**に集約する。文字種 (小文字英数字とハイフン)・長さ・
  先頭末尾ハイフン禁止・連続ハイフン禁止・大文字は小文字へ正規化、を 1 か所で持つ。
  値オブジェクトは**検証と正規化を通った値だけを保持する不変型**にする
  (生成に成功した時点で「妥当な識別名である」が型で言える)。
- 一意性は**大文字小文字を区別しない**。保存層の小文字強制と DB の unique index の二重で担保する
  (アプリ層の事前確認だけだと競合で抜ける)。
  **DB は PostgreSQL 18 固定** (`docker-compose.yml` が dev/devcontainer を pgsql に固定し、
  `phpunit.xml` が「テストは本番同等の PostgreSQL で回す (sqlite/pgsql 二重運用なし)」と宣言している)。
  したがって式 index の可搬性は論点にならない。

  **採る方式**: `slug` 列に **`CHECK (slug = lower(slug))` 制約**と**通常の `UNIQUE` index** を
  両方張る。値オブジェクトは「アプリ経路が小文字を書く」ことを担保するだけで、**DB の保証ではない** —
  直接 SQL・未検出の書き込み経路・将来の一括処理が `Foo` と `foo` を同居させ得るため、
  「値が常に小文字である」こと自体を DB 制約にする。
  この 2 本の合成が「大小無視の一意性」になる (小文字でない値は CHECK で入らない →
  保存済みの値は全部小文字 → 通常の UNIQUE が大小無視の一意性と一致する)。
  `UNIQUE (lower(slug))` 単独は同じ集合を守るが、**「列の値は小文字である」という設計意図が
  制約から読めない**ので採らない (大文字混じりの値が入ったまま一意性だけ守られる状態を許してしまう)。
  - **migration の順序を固定する** (更新より先に検査する):
    1. **更新せずに**正規化後の値を計算する
    2. 正規化後に衝突する行が無いかを検査し、あれば **migration を fail-closed で止める**
       (黙って片方を書き換えない)
    3. 衝突が無い場合だけ既存値を小文字化する
    4. `CHECK` と `UNIQUE` を付与する

    同じ順序を**予約語**にも適用する — 既存の識別名が新しい予約語に該当する場合も
    2 の段で fail-closed で止める (自動で付け替えない)。
  - **CHECK 制約が実際に効くこと**を、値オブジェクトを迂回した書き込み (直接 SQL) が
    落ちる Feature テストで固定する。
  - **並行改名で一意制約違反が起きること**を実 DB の Feature テストで固定し、
    違反の種類を識別して利用者向けエラーへ落とす (識別できない違反は隠さず再送出)。
- 生成 (組織名からの導出) と利用者入力の両方が同じ値オブジェクトを通る。

### B. 予約語を持ち、ルート表との整合を機械検査する (AG-039)

- 予約語一覧を設定ファイルに置き、**各語に理由 3 分類のいずれかの記載を必須**にする
  (`route_conflict` = ルート衝突 / `authority_impersonation` = 権威の詐称 /
  `syntax_conflict` = 構文衝突)。
- 「識別名の位置に現れうる固定セグメントが予約語に登録されていること」を
  route 表から機械検査する Architecture 検査を新設する。**予約語を書き忘れた新 route は赤になる**。

**「予約語を登録して route と照合する」だけでは AG-039 は満たせない。** 登録と照合が在っても、
作成・改名で `admin` を**保存できてしまえば**意味が無い。保存を拒否する契約を型で持つ:

- **型を 2 段にする**。
  - `OrganizationSlug` = **構文だけ**の不変型 (文字種・長さ・先頭末尾/連続ハイフン・小文字正規化)。
  - `AssignableOrganizationSlug` = **保存してよい識別名**の不変型
    (= 構文妥当 **かつ** 非予約語)。生成には予約語判定器が要る。
- **Service が保存できるのは `AssignableOrganizationSlug` だけ**にする。
  構文だけの型を保存経路へ渡す道を**型で消す** (`Organization` の識別名を書ける経路は
  この型を受ける 1 本に限り、Architecture 検査で固定する)。
- **作成・改名・既存組織の移行が同じ予約語判定を通る**。
  `fromInput()` (利用者入力) と `deriveFromName()` (組織名からの導出) の**両方**で予約語を拒否する
  — 導出側を素通りさせると、「管理」という名前の組織を作るだけで `admin` が取れてしまう。
- 予約語での新規作成・改名が拒否されることを **Feature テスト**で固定する (成功条件に入れる)。
- 既存の識別名が新しい予約語に該当する場合の migration 方針は、衝突時と同じ **fail-closed**
  (自動で付け替えない。運用で解消してから migration を通す)。

### C. 改名経路を持ち、回数制限と旧識別名の扱いを確定する (AG-046)

- 改名 route を 1 本足す。**30 日あたり 5 回**まで。
- 最終権威は「組織行を行ロックした後の再判定」。事前判定は画面表示のための早期拒否にすぎない。
- **旧識別名は予約せず解放する** (履歴表に一意制約を張らない)。
- 施行時は全組織が残 5 回から始まる (導入前の改名は遡及計上できない)。テンプレートと
  aigenba も同じ制約を申し送りにしており、**緩い側の失敗として許容**する。

### D. 種別フラグを撤去する (AG-038)

- `organizations.is_personal` を落とす。
- 登録時の初期組織生成の冪等判定を「種別フラグの有無」から**「所属組織が 0 件かどうか」**へ置換する。
- 判定はトランザクション内で利用者行を**取り直して行ロック**し、ロック後のクエリで所属を数える
  (呼び出し側の読み込み済みリレーションに依存しない)。
- 呼び出しサイトを Architecture 検査で固定する。

### E. 現在組織を URL 単一方式にする (AG-037) — 本設計の主柱

- 業務面の route を**すべて `/organizations/{organization:slug}/` 配下へ移設**する。
  対象は dashboard / projects 系 / capture (撮影 PWA) 系 / billing 系 / onboarding 系 /
  notifications / manage (メンバー管理) の **約 57 本**。
- 組織の解決は既存の `MembershipScopedOrganizationBinder` (routing 層 = 入力検証より前) 1 本に
  寄せる。**AG-036 の形はすでに aicue が持っており、変わるのは「どこから org を取るか」だけ**。
- `users.current_organization_id` 列・`organizations.switch` route・`CurrentOrganizationResolver`・
  `ResolvesCurrentOrganization` の current 解決部を**同じ変更ですべて消す**
  (AGENTS.md 思考原則 3: 後方互換の並走を残さない)。
- 画面へ渡す組織文脈は URL の binding から導出する共有プロパティ 1 本にし、
  **組織 route 以外では `null`** になる。
- 組織文脈を持たない入口 (料金ページ / メール確認後 / ソーシャル登録後 / PWA の `start_url`) からの
  導線は、**状態を保存しない分岐 route 1 本**で受ける。所属が 1 組織ならその組織へ転送、
  複数なら組織を選ぶ画面、0 件なら組織作成へ。
  これは正典 (laravel-claude-template T104) が同じ問題に対して採った形をそのまま採る。
  **状態を保存しないので「保持列と切替エンドポイントの禁止」と矛盾しない。**

### F. 機械経路が組織を「不変の内部識別子」で指すことを固定する (AG-047)

裁定の文言は「機械が使う経路は**不変の内部識別子**で組織を指す」である。
**「識別名 (slug) を使っていない」ことは、この裁定の充足条件ではない** —
表示名 (`name`)・利用者が与えた任意文字列・将来足される可変フィールドで組織を引く経路は
「slug 不使用」の検査をすり抜けるうえ、改名やリネームで**別組織へ黙って作用する**。
したがって検査は「slug の否定」ではなく **deny-by-default の識別子契約**にする。

**母集団は目録ではなく機械で作る。** 「目録に登録された入口だけを見る」形は、
新しい controller / command / Filament の構成要素 / MCP tool が目録にも走査候補にも入らないまま
緑になる (AGENTS.md §走査器の共通規約 (b) の「違反 0 件と母集団 0 件を区別する」に反する)。
母集団は次の 4 本の走査根から**機械的に**抽出し、**走査根が解決できない・空である場合は fail-closed** にする。

母集団は **2 段**にする。入口を 1 種別へ分類するだけでは、**1 つの入口が複数の組織解決を持つ**
実装 (片方は `actor_derived`、もう片方は任意文字列での検索) を隠せてしまうためである。

**第 1 段 — 入口の全数抽出**。組織解決の有無に**かかわらず**全件を機械抽出する:

| 面 | 母集団の作り方 |
|---|---|
| api / ai (MCP の HTTP 面) | route collection から `api/` `ai` 由来の全 action |
| console | 登録された全 Artisan command (`app/Console/Commands/` 配下の具象クラス + `routes/console.php` の無名 command) |
| Filament | 対象 panel に属する **application-defined の構成要素全件** (Resource / Page / RelationManager / Widget / Action …)。**「組織解決を持ち得るもの」で母集団を絞らない** — 絞る判定から漏れた構成要素が母集団にも入らず全数性を失う (循環)。対象種別の正本は詳細設計に置き、**未知の Filament 構成種別が現れたら fail-closed** にする |
| MCP tool | 登録された全 tool / handler (`App\Enums\Mcp\ToolName` の全ケースと実装の突合を含む) |

**第 2 段 — 入口の中の解決点の全数抽出**。各入口の中で
「組織、または組織に帰属する資源を確定する**すべての解決点**」を抽出し、
**解決点ごとに**許可 provenance を分類する。1 つの入口に複数の解決点があれば、
**それぞれが独立に契約を満たす**必要がある。

- `not_organization_scoped` を名乗れるのは、その入口の**解決点が 0 件であることを検査した場合だけ**。
  「組織を扱わないと申告した」だけでは通さない。
- 未登録・余剰登録・重複登録・空の走査根は落ちる (fail-closed)。

- 許可する解決の種別は次の 3 つだけ。**入力面ごとに許可元を限定する**
  (AG-047 の識別子の不変性と、AGENTS.md 不変条件 1「tenant キー不信」・
  不変条件 3「cross-org 不可」は**別々に**満たす必要がある):

  | 種別 | 許可する形 | 明示的に禁じる形 |
  |---|---|---|
  | `primary_key_binding` | **route binding の内部主キーだけ** (Filament の `{record}` を含む) | request body / query string の `organization_id` 等の tenant キー受け取り (不変条件 1 違反) |
  | `actor_derived` | 認証済み credential (API キー / OAuth token / MCP consent) の**帰属**から確定する request attribute | 利用者入力を経由する値。actor が持たない組織を引数で指定する形 |
  | `relation_scoped` | **信頼済みの親**から tenant-scoped relation だけを辿って組織を確定する | 親の確定方法が解決できない場合 |

- **`relation_scoped` は再帰的な provenance 契約**である。「親の relation を辿った」だけでは
  信頼の起点が無く、親自身が識別名・表示名・任意文字列で引かれていたら同じ穴が開く。
  契約は次のとおり定義する:

  > 親資源が `primary_key_binding` または `actor_derived` によって確定されており、
  > かつその親から **tenant-scoped relation のみ**を辿って組織を確定すること。

  **親の確定方法が解決できなければ fail-closed** で落とす (未解決を許可と同じ値へ混ぜない)。
- 組織を扱わない入口は `not_organization_scoped` として**理由付きで明示 exempt** する
  (目録に見える形で宣言する。deny-by-default)。
- **`primary_key_binding` は「操作してよい組織か」を保証しない**。主キーで指せることと、
  actor がその組織を操作できることは別である。認可は従来どおり `Gate::authorize` と
  `ControllerAuthorizationGateTest` の担当で、本検査はそこを肩代わりしない
  (この非対称を docblock に書く)。
- **負例で両方向を裏取りする** (AGENTS.md §静的検査 (gate) と走査器の共通規約 (c)):
  識別名で引く形 (`{organization:slug}` を web 以外に置く / `where('slug', $input)`)、
  表示名で引く形 (`where('name', $input)`)、任意文字列で引く形を**検出できること**と、
  許可された 3 種別を**誤検出しないこと**の両方を固定する。
- **解決できない形は落とす (fail-closed)**。走査根が解決できない・母集団が空・
  分類が付けられない形は、無言で候補から外さずに gate を失敗させる (同規約 (b))。
- `Organization::getRouteKeyName()` は **`id` のまま据え置く**。
  Filament の `{record}` はこれに依存しており、`slug` へ変えると管理画面が
  可変の識別名で組織を指すことになり AG-047 に真正面から抵触する。
  web の組織 route は**すべて明示の `{organization:slug}` binding**で書き、
  field 無指定 binding は 0 件にする (切替 route の撤去でその唯一の利用者が消える)。
- **保証しないものを docblock に書く**: 効くのは 4 本の走査根から抽出できる母集団だけである。
  実行時に組み立てた文字列で解決する形・vendor 内部の解決・リポジトリ外の手順には**無言で効かない**。
  「この検査があれば機械経路は 1 つも可変識別子を使っていない」とは読めない。

---

## 期待効果

### 使命 (North Star) への貢献

- **保持列と自己修復に起因する誤組織表示が構造的に消える**。撮影 PWA が開く画面は URL が決めるので、
  共用端末でも「サーバに残っていた前回の組織」が黙って出ることはない。
  ただし**消えるのはこの原因だけ**である — `/app` の分岐から複数組織の選択画面へ進んだ利用者が
  そこで押し間違える余地は残る (それは選択画面の UI の問題であり、本設計が消す原因ではない)。
  「思考ゼロ・編集ゼロ」を掲げる以上、作業者に「いま何組織を見ているか」を推測させないこと自体が要件である。
- **URL が申し送りになる**。「この手順書を撮って」を URL の共有で完結できる。
  正確には — **同じ組織へのアクセス権を持つ利用者の間では、表示対象は保持状態ではなく
  URL と route binding で一意に決まる**。アクセス権を持たない相手には従来どおり 404 になる
  (組織の存在を漏らさない。不変条件 #2)。URL が「誰でも見える鍵」になるわけではない。
- **組織が黙って切り替わらない**。自己修復の撤去で、利用者が指示していない組織移動が消える。

### 台帳・保守面

- lctl の aicue セルが `pending` / `divergence_candidate` から前進する
  (implemented への格上げ判断はキュレーターの責務であり、本設計はその根拠を作るところまで)。
- 組織 route の鍵が識別名 1 本に揃う (切替だけ主キーという不揃いが route ごと消える)。
- 現在組織の分岐 (「未設定なら 404」「dangling なら自己修復」) が消え、
  テナント境界の判定点が routing 層 1 か所になる。

---

## 実装方針 (概要)

変更は 1 バッチ。**AG-037 は 2 方式の併存を認めない裁定**なので、
「保持列を残したまま URL 方式も足す」中間状態は作らない。

コミット順序だけは固定する (先に識別名を堅くしてから、識別名を全 URL の前置きにする):

```
A → B → C  (識別名: 値オブジェクト → 予約語 + route 整合検査 → 改名)
     ↓
D          (種別フラグ撤去。A〜C と独立だが provisioning を 1 回で書き換えるため先に済ませる)
     ↓
E          (route 移設 + 保持列/切替/自己修復の撤去。ここで初めて識別名が全 URL の前置きになる)
     ↓
F          (機械経路の識別子の固定検査)
     ↓
乖離台帳の更新 (D4 の書き換え / 新規登録 / 採用時債務の処理)
```

### 主な変更コンポーネント

| 層 | 変更 |
|---|---|
| 値 | `App\Support\Organization\OrganizationSlug` (新設) / `OrganizationSlugReservedWords` (新設) |
| 設定 | `config/organization-slug-reserved.php` (新設。理由 3 分類必須) |
| モデル | `Organization` (`is_personal` cast 撤去) / `User` (`currentOrganization` relation 撤去) / `OrganizationSlugRename` (新設) |
| Service | `OrganizationProvisioningService` (冪等判定の置換 + 識別名生成の値オブジェクト化) / `OrganizationSlugRenameLimiter` (新設) / `OrganizationMembershipService` (保持列書き込みの撤去) / `CurrentOrganizationResolver` (**削除**) |
| Routing | `routes/web.php` (業務面を組織配下へ移設) / `MembershipScopedOrganizationBinder` (据え置き) / `EnsureProjectBelongsToCurrentOrganization` → 組織 binding 由来へ改称・改修 |
| Controller | `ResolvesCurrentOrganization` を使う 23 クラスの org 取得元を route binding へ / `OrganizationSwitchController` (**削除**) / 組織選択の分岐 route (新設) / 改名 controller (新設) |
| Inertia / 前段 | `HandleInertiaRequests` の `currentOrganization` を binding 由来へ |
| フロント | `shared-props.ts` (型) / `AppLayout.svelte` (組織切替 UI の撤去・href 生成) / `SidebarUserMenu.svelte` / `Capture/Account.svelte` / 組織選択画面 (新設) / 改名 UI |
| migration | `drop is_personal` / `drop current_organization_id` / `organization_slug_renames` 作成 / 識別名の `CHECK (slug = lower(slug))` + 通常 `UNIQUE` index (既存行の正規化と衝突検査つき) |
| 検査 | 予約語 × route 表整合 (新設) / 初期組織生成の呼び出しサイト固定 (新設) / 機械経路の組織識別子 (新設) / 改名制限 (新設) / 既存 `ProjectRouteCurrentOrgGuardTest` `TenantBoundaryOrderingTest` `NestedRouteIdorDefenseTest` の更新 |
| 乖離台帳 | `docs/template-divergence.md` D4 の書き換え + 新規登録 / `LedgerPins` の件数更新 / 採用時債務の処理 |

---

## 制約・前提

### 満たし続けるセキュリティ不変条件 (AGENTS.md §セキュリティ不変条件)

1. **tenant キー不信** — 組織・所有者・実行者のキーを payload から受け取らない。
   識別名は URL segment であって payload ではない。改名の入力は「新しい識別名」だけで、
   **どの組織かは URL の binding が決める**。
2. **子は親に属する** — nested route の不整合は認可より前に 404。
   移設後は `{organization}` → `{project}` → `{manual}` → `{cut}` → `{take}` の鎖になり、
   `NestedRouteIdorDefenseTest` の inventory 登録が必須。
3. **cross-org 不可** — 組織を跨ぐ read/write をしない。組織の解決は membership スコープの
   binder 経由のみ。`ModelDirectFetchInvariantTest` / `DirectFetchInventory` の分類が要る。
4. 窓口経由の prompt 防御 — 本設計は LLM 経路に触れない。
5. **権限判定は常に `laratrust_team_id` を明示** (strict_check=true。AG-040。既に充足)。
6. PII は CipherSweet — 本設計は PII 列に触れない。
7. 課金の冪等性 — 本設計は billing route の **URL だけ**を動かす。冪等機構には触れない。
8. SSRF 検査 — 触れない。
9. **変更系 route は認可を通る** — 移設で route が増減するので `ControllerAuthorizationGateTest` の
   deny-by-default に全数が乗る。**層 2 (テナント境界 = 404) は層 3 (認可 = 403) より前**。
10. **層 2 は binding の直後・FormRequest より前で閉じる** — 実行順の正本は
    `bootstrap/app.php` の priority list。`TenantBoundaryOrderingTest` /
    `ProjectRouteCurrentOrgGuardTest` の更新が必須。
11. キャッシュに入れるのは素のデータだけ — `AppServiceProvider` の
    保持列由来のキャッシュキーが消えるだけで、機構には触れない。

### 正典側の不変条件 (追従先)

- 現在の組織は **URL のみ**で決まる。保持列と切替エンドポイントは存在してはならない。
- 個人組織を種別として区別しない。初期組織生成の冪等判定は所属 0 件で行い、行ロックする。
- 識別名は小文字英数字とハイフン。先頭末尾と連続のハイフンは不可。大文字は小文字へ正規化。
  一意性は大小無視。
- 予約語は理由 3 分類の記載必須。識別名の位置に現れる固定セグメントは予約語であること。
- 改名は 30 日 5 回まで。旧識別名は予約せず解放。
- 機械が使う経路は不変の内部識別子で組織を指す。

### 既存アーキテクチャとの整合

- `MembershipScopedOrganizationBinder` は既に routing 層にあり、非メンバー・不在 id を等しく 404 にする。
  **AG-036 の形は既に持っている**。本設計はその適用範囲を業務面へ広げるだけである。
- `Organization::getRouteKeyName()` は **`id` のまま据え置く**。
  `OrganizationRouteParamWebOnlyInvariantTest` の「routeKeyName は id」の pin もそのまま残す。
  理由は 2 つ — (a) Filament の `{record}` がこれで解決するので `slug` にすると
  管理画面が可変の識別名で組織を指すことになり AG-047 に抵触する、
  (b) 「識別名で解決するのは web の組織 route だけ」を**明示 binding の有無**で読めるようにするため。
  代わりに **field 無指定の `{organization}` binding を 0 件にする**
  (唯一の利用者だった切替 route が消える)。詳細設計の完了条件として、
  field 無指定 binding の全数棚卸しとゼロ化を機械検査で固定する。
- 撮影 PWA の `start_url` は `/app`。SW は `/capture-sw.js` (scope=`/`) で登録しており、
  **移設で SW の scope は壊れない**。`/app` は組織選択の分岐 route として残す。
- `docs/template-divergence.md` の **D4** (web `{project}` の org スコープ guard を middleware 層に置く)
  は current org 前提で書かれているため、同じ変更で書き換える。

### 破壊的変更としての扱い

- 旧 URL は route ごと消えて 404 になる。**並走も転送も置かない** (AGENTS.md 思考原則 3)。
- テンプレートは「本番利用者が居ないため代償なし」と書いているが、**aicue は自分で判断する**。
- 転送を置かない代わりに、**リポジトリ内の旧 URL の生成・記述をゼロにする**。
  母集団は原則 **git 追跡下ファイル全数**とし、そこから**対象形式を分類する**
  (「見落としやすい箇所の一覧」を母集団の代わりにしない)。
  分類は次の 3 つで、**どれにも分類していない置き場所・形式が現れたら fail-closed** で落ちる
  (AGENTS.md §走査器の共通規約 (b) / §禁止する文 の「3 つへ排他的に分類」と同じ形):

  | 分類 | 対象 |
  |---|---|
  | **走査する** | PHP 全層 (Controller / Service / Job / Event / Listener / Resource / Blade / config の `route()` 呼び出しと URL 直書き) / `resources/js/` / `resources/views/` / `tests/` (Feature / Browser / `tests/js/`) / `docs/` / `doc/` / ルート直下の `README` / manifest (`public/*.webmanifest`) / `public/*.js` / bug-hunt の `inventory/` と `.claude/` 配下の目録・注釈 / 生成テンプレート |
  | **走査しない (理由付き)** | バイナリ / `public/build` 等の生成物 / `vendor` / `node_modules` / `devnotes` (設計の記録であり実行されない。ただし本設計ディレクトリの旧 URL 記述は履歴として残してよい) |
  | **未分類** | **1 件でも現れたら赤**。分類を足す変更がレビューで必ず見える |

  下表は「見落としやすいので詳細設計で必ず個別に確認する箇所」であって、母集団の定義ではない:

  | 系統 | 具体 |
  |---|---|
  | 生成時点で永続化される URL | queue 済み通知・送信予約メール・DB に保存された本文 |
  | 通知・メール本文 | `NotificationController` / 通知クラス / Mailable / `NotificationCenterService` |
  | 撮影 PWA | `manifest.webmanifest` の `start_url` / `capture-sw.js` のキャッシュ判定 / 復路 (`capture.home`) |
  | 導入ガイドの生成物 | `SnippetBuilder` (MCP / CLI セットアップ手順に URL が載る) |
  | 目録・注釈 | bug-hunt の `inventory/annotations.toml` (route を足したら注釈も足す規約) |

- **保証外 (誇張しない)**: 次の 3 つは**リポジトリ内の作業では閉じられない**。
  詳細設計のリスク欄に明記し、「旧 URL は 1 つも残らない」とは書かない。
  (a) 利用者のブックマーク・外部サービスに登録済みの URL、
  (b) デプロイ時点で既に queue に積まれている / 送信済みのメール本文、
  (c) ブラウザの履歴・bfcache・開いたままの旧画面 (次の遷移で 404 になる)。

- **PWA のキャッシュ済み画面は「旧 URL の残存」にはならない** — `capture-sw.js` の
  `shouldCacheRequest` は `/build/*` の fingerprinted asset **だけ**をキャッシュし、
  `/app/*` の navigation と JSON/XHR は早期 return でネットワーク素通しである (実読で確認)。
  よって古い画面が SW から復元されることはない。SW の scope も `/` のままで変わらない。

---

## スコープ外

**裁定が確定していない項目・他リポジトリの責務・他 feature の所有物は入れない。**

| 項目 | 理由 |
|---|---|
| AG-036 (前段の帰属検証) | **aicue 形が標準形として採用された側**であり既に充足。本設計は org の取得元を変えるだけで、層の位置は動かさない |
| AG-040 (チーム厳格チェック) | `config/laratrust.php` の設定値が真で充足済み |
| AG-040 の配布物 (`TeamScopedRoleCheckInvariantTest` + 走査器) | 台帳が **「還流候補」** と書く未確定項目。aigenba にのみ実在し、他 5 本は設定値が真なだけ。裁定が「検査を配れ」と言っていない以上、先回りして作らない (思考原則 2) |
| 正典の版の呼び名 (t0 → t1 等) | 台帳が「未確定 (議題)」と明記。**キュレーターの責務**であり aicue が決めるものではない |
| lctl への書き込み (`append_event` / セル更新) | 設計エージェントの責務外。登録は後段 |
| 組織そのものを削除する route | 正典 boundary が「`OrganizationPolicy::delete` は 6 リポジトリ全数が持ち、route を持つのは aigenba と spirux の 2 本」と書く。**aicue に無いことは逸脱ではない** |
| 中間層 (CustomTeam) の階層変更 | aicue は既に Organization → CustomTeam → Project の 3 階層で正典と同形 |
| 招待の役割列 | 台帳が「auth-invitation-flow 側の事実なのでそちらへ寄せ、ここでは触れない」と明記 |
| 接続の取り消し層 / MCP 組織書き込みスコープ | 所有は `mcp-org-write-scope` feature。AG-125 の管轄 |
| 認証後の着地画面の組織アクセス状態 (4 値) | 所有は auth 側 (AG-113)。`auth-post-auth-redirect` / `auth-invitation-in-app-discovery` の管轄 |
| aigenba の `TemplatePolicy` 是正 | aigenba の責務 |
| シート課金同期・org-tree versioning・Filament 管理パネルの権限境界 | 正典 boundary が「含まない」と明記 |
| 撮影 PWA の manifest を組織別に動的生成する | `start_url` は分岐 route `/app` のままで足りる。動的 manifest は「あったら便利」(思考原則 2) |
| 旧 URL からの転送・並走 | 思考原則 3 (後方互換の並走を残さない) と正典の実装判断に従う |

---

## 型の境界 (PHPStan level 10 を通す前提)

概要レベルで先に確定させ、詳細設計で具体化する。

- **識別名の型は 2 段**で、不変条件を型名で言い分ける。
  - `OrganizationSlug` — 不変条件は「**構文的に妥当で正規化済み**」だけ (文字種・長さ・
    先頭末尾/連続ハイフン・小文字)。**保存してよいことは意味しない**。
  - `AssignableOrganizationSlug` — 不変条件は「構文的に妥当 **かつ** 非予約語」。
    **保存経路が受け取れるのはこの型だけ**で、構文だけの型を保存する道は型で消えている。
  どちらも不変型で、生成は名前付きコンストラクタ 2 本
  (`fromInput()` = 利用者入力 / `deriveFromName()` = 組織名からの導出) に限る。
- 予約語設定は理由分類を **backed enum** で表し、`config/` の配列 shape を**読み込み直後に検証して
  型付きの値へ変換**する (`array<string, string>` を素で持ち回らない)。
  分類が付いていない語・未知の分類は**読み込み時に例外**にする (fail-closed)。
- 改名の回数判定は Eloquent の曖昧な配列ではなく、**判定に必要な値だけを持つ型付きの結果**で返す
  (残り回数・次に改名できる時刻)。
- `currentOrganization` の共有プロパティは `?array` の直書きにせず、**専用の型 1 本**
  (`CurrentOrganizationData|null` 相当の不変 DTO) に固定する。配列化は Inertia へ渡す
  **最終の 1 か所だけ**で行う。組織 route 以外では必ず `null` になることを Feature テストで固定し、
  TypeScript 側 (`resources/js/lib/shared-props.ts`) の型も同じ変更で更新する。
- 改名 endpoint は **FormRequest → 型付き識別名 → Service → Inertia response** の境界を通す。
  `response()->json()` の直書きはしない (禁止事項 4)。

---

## 成功条件 (どうなれば追従できたと判断するか)

1. `current_organization_id` が `app/` `routes/` `config/` `resources/js/` と
   **撤去 migration 以外の `database/`** に 1 件も無い。切替 route が 0 件。
   field 無指定の `{organization}` binding が 0 件。
2. `is_personal` が **撤去 migration 以外に 1 件も無い**。初期組織生成の冪等判定が所属 0 件 + 行ロック。
3. 識別名の値オブジェクト 2 段 (`OrganizationSlug` / `AssignableOrganizationSlug`)・
   理由分類必須の予約語設定・改名台帳・30 日 5 回の制限・改名 route が実在し、
   予約語と route 表の整合を機械検査が固定している。
   **保存経路が受けるのは `AssignableOrganizationSlug` だけ**であることを Architecture 検査が固定し、
   予約語での新規作成・改名が拒否されることを Feature テストが固定している
   (利用者入力の経路と、組織名からの導出の経路の**両方**)。
4. 大小無視の一意性が **DB 制約**で担保されている (`CHECK (slug = lower(slug))` + 通常 `UNIQUE`)。
   値オブジェクトを迂回した直接 SQL が CHECK で落ちること、並行改名の競合が一意制約違反になることが
   実 DB のテストで確認されている。
5. 機械経路 (api / ai / console / Filament / MCP) について、**第 1 段で入口が全数抽出**され
   (Filament は組織解決の有無で絞らず application-defined の構成要素全件)、
   **第 2 段で各入口の中の解決点が全数抽出**され、解決点ごとに許可 3 種別
   (`primary_key_binding` / `actor_derived` / `relation_scoped`) のいずれかへ分類されている。
   `not_organization_scoped` は解決点 0 件を検査した入口だけが名乗れる。
   未分類・余剰登録・重複登録・空の走査根・未知の Filament 構成種別は deny-by-default で落ちる。
   負例で両方向 (識別名 / 表示名 / 任意文字列で引く形の検出、許可 3 種別の誤検出なし) が裏取りされている。
6. **定義した走査根 (git 追跡下ファイル全数の 3 分類) で検査可能なリポジトリ内の旧 URL の生成・記述が 0 件**。
   未分類の置き場所・形式が 0 件。リポジトリ外の状態 (ブックマーク / 送信済みメール / ブラウザ履歴) は保証外と明記されている。
7. 移設後の nested route が `NestedRouteIdorDefenseTest` の inventory に全数登録されている。
8. **AGENTS.md の `VERIFICATION_COMMANDS` マーカー内に列挙された検証コマンドが全 green**。
   本設計時点の正本の内容は次の 10 本である (詳細設計・実装の時点で正本と同期していることを確認する。
   ここに写した一覧が正本と食い違ったら**正本が正しい**):
   `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
   `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
   `pnpm build:packages` / `pnpm test:packages`。
9. 乖離台帳 (`docs/template-divergence.md` / `LedgerPins` / 採用時債務一覧) が整合している。


---

## 再レビュー依頼

Round 3 の Critical 4 件 (予約語の保存拒否契約 / AG-047 の解決点単位の分類 /
Filament 母集団の循環 / 旧 URL の走査母集団) と Warning 3 件への対応が十分かを判定してほしい。

本フェーズは**概念設計**であり、クラス名の最終形・メソッドシグネチャ・DDL の具体・
テストケース名の水準は次フェーズ (詳細設計) の責務である。
概念設計として決めるべきこと (方針・境界・保証範囲・スコープ・成功条件) が確定しているかで判定してほしい。

全体判定を APPROVED / CHANGES_REQUESTED で示すこと。

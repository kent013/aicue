# 概念設計レビュー Round 3

Round 2 の [Critical] 4 件・[Warning] 4 件への対応を反映した。対応マトリクスと修正後の全文を示す。

# 対応マトリクス: conceptual-review Round 2

## [Critical] 成功条件 8 の検証コマンドが AGENTS.md の必須一覧に足りない (packages 系 3 本)
- 判断: **対応する**
- 根拠: 指摘のとおり。`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` が
  抜けていた。AGENTS.md は `VERIFICATION_COMMANDS` マーカーで囲った一覧を正本とし、
  `tests/js/architecture/verification-commands-doc-sync.test.ts` が package.json との同期を
  deny-by-default で強制している。
- 対応内容: 成功条件 8 を「AGENTS.md の `VERIFICATION_COMMANDS` マーカー内に列挙された検証コマンドが
  全 green」へ書き換え、**正本を参照する形**にした。本設計時点の 10 本を参考として写したうえで、
  「ここに写した一覧が正本と食い違ったら正本が正しい」と明記した (2 か所に書くと必ず食い違うため)。

## [Critical] 通常の unique index だけでは PostgreSQL が大小無視の一意性を保証しない
- 判断: **対応する**
- 根拠: 指摘が正しい。値オブジェクトと Architecture 検査は**アプリ経路**の保証であって
  DB の制約ではない。直接 SQL・未検出の書き込み経路・将来の一括処理が `Foo` と `foo` を
  同居させられる以上、「保存層の小文字強制と DB unique index の二重担保」は成立していなかった。
- 対応内容: **`CHECK (slug = lower(slug))` + 通常 `UNIQUE` index** の併用を採用した
  (提案された 3 案のうち、「列の値は常に小文字」という設計意図が制約から読める形を選んだ。
  `UNIQUE (lower(slug))` 単独は、大文字混じりの値が入ったまま一意性だけ守られる状態を許す)。
  併せて次の 3 点を明記した — migration 前検査 (既存行の小文字化 + 正規化後の衝突が無いことを
  制約付与の前に確認し、衝突があれば fail-closed で止める) / CHECK が実際に効くことを
  直接 SQL の Feature テストで固定 / 並行改名の一意制約違反を実 DB で固定し違反の種類を識別する。

## [Warning] migration 欄の「小文字式 unique index」が A 節と矛盾
- 判断: **対応する**
- 根拠: そのとおりの不整合。
- 対応内容: 主な変更コンポーネント表の migration 欄と成功条件 4 を、採用した
  `CHECK (slug = lower(slug))` + 通常 `UNIQUE` に統一した。

## [Critical] `relation_scoped` に信頼の起点が無い
- 判断: **対応する**
- 根拠: 指摘が正しい。親が識別名・表示名・任意文字列で引かれていれば、relation を辿った先も
  同じ穴になる。「relation 経由だから安全」は親の provenance を問わない限り成立しない。
- 対応内容: `relation_scoped` を**再帰的な provenance 契約**として定義し直した:
  「親資源が `primary_key_binding` または `actor_derived` によって確定されており、かつその親から
  tenant-scoped relation のみを辿って組織を確定すること」。
  **親の確定方法が解決できなければ fail-closed** で落とすことも明記した
  (AGENTS.md §走査器の共通規約 (b) の「未解決を解決済みと同じ値へ混ぜない」)。

## [Critical] 「全数目録化」の母集団の抽出方法が未定義 (目録だけを見る検査は全数性を持たない)
- 判断: **対応する**
- 根拠: 指摘が正しい。目録に登録された入口だけを見る形は、新しい controller / command /
  Filament 構成要素 / MCP tool が目録にも走査候補にも入らないまま緑になる。
  これは AGENTS.md §走査器の共通規約 (b) の「『違反が 0 件』と『母集団が 0 件』を区別する」に
  真正面から抵触する。docblock に限界を書くことで全数性の穴が塞がらないという指摘も正しい
  (同規約が「走査器の限界を書き足すことは、既にある見逃しを規約適合へ変えない」と明記している)。
- 対応内容: 「母集団は目録ではなく機械で作る」を節の冒頭に立て、走査根 4 本を表で定義した
  (api/ai の route collection 全 action / 登録済み Artisan command 全数 /
  対象 panel の Filament 構成要素全数 / 登録済み MCP tool・handler 全数)。
  抽出した母集団の**全件**を許可 3 種別か `not_organization_scoped` へ**完全一致で分類**し、
  未登録・余剰登録・重複登録・空の走査根を落とすことを明記した。
  docblock の「保証しないもの」も、母集団の外 (実行時に組み立てた文字列・vendor 内部・
  リポジトリ外) だけを指すよう書き直した。

## [Warning] `primary_key_binding` の範囲が広すぎる (payload の tenant キーを許してしまう)
- 判断: **対応する**
- 根拠: そのとおり。「route / 引数の主キー」と書くと request body の `organization_id` まで読める。
  それは AGENTS.md 不変条件 1 (tenant キー不信) 違反である。
  また「主キーで指せること」と「actor がその組織を操作できること」が別物、という指摘も正しい。
- 対応内容: 許可 3 種別の表を「許可する形 / 明示的に禁じる形」の 2 列にし、入力面ごとに限定した。
  `primary_key_binding` は **route binding の内部主キーだけ**で、request body / query string の
  tenant キー受け取りは禁止。`actor_derived` は認証済み credential の帰属だけ。
  加えて「`primary_key_binding` は『操作してよい組織か』を保証しない。認可は `Gate::authorize` と
  `ControllerAuthorizationGateTest` の担当で、本検査はそこを肩代わりしない」を明記した。

## [Warning] 「現場端末の誤組織事故が構造的に消える」が広すぎる
- 判断: **対応する**
- 根拠: 選択画面での押し間違いまで消えるようには読ませてはいけない (誇張しない)。
- 対応内容: 「**保持列と自己修復に起因する**誤組織表示が構造的に消える」へ限定し、
  「消えるのはこの原因だけで、選択画面での押し間違いの余地は残る」と明記した。

## [Warning] 旧 URL 棚卸しの 6 系統は「全数」と呼ぶには狭い
- 判断: **対応する**
- 根拠: そのとおり。PHP 全層の URL 生成・永続化された URL・文書類が抜けており、
  リポジトリ外の状態は原理的に検査できない。
- 対応内容: 「6 系統の一覧」を閉じた母集団と呼ぶのをやめ、**走査根ベース**
  (git 追跡下の PHP 全数 + `resources/js/` + `docs/` + `.claude/`) で抽出する形へ改めた。
  表には PHP の URL 生成全層 (Controller / Service / Job / Event / Listener / Resource /
  Blade / config の `route()` と直書き)・生成時点で永続化される URL・文書類を追加した。
  さらに **保証外 3 点**を明記した — 利用者のブックマークと外部登録済み URL /
  デプロイ時点で queue に積まれている・送信済みのメール本文 / ブラウザ履歴・bfcache・
  開いたままの旧画面。「旧 URL は 1 つも残らない」とは書かない。

## [Warning] `currentOrganization` の「明示型」が抽象的
- 判断: **対応する**
- 根拠: level 10 の契約としては具体型の指定が要る。
- 対応内容: `CurrentOrganizationData|null` 相当の**専用の不変 DTO 1 本**に固定し、
  配列化は Inertia へ渡す**最終の 1 か所だけ**で行う、と具体化した。

## [Suggestion] 使命整合 / URL 共有の限定 / 型境界 / スコープ切り分けの追認
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
  - **migration 前検査を持つ**: 既存行の小文字化と、正規化後に衝突する行が無いことを
    制約を張る**前**に確認する。衝突があれば migration を fail-closed で止める
    (黙って片方を書き換えない)。
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

| 面 | 母集団の作り方 |
|---|---|
| api / ai (MCP の HTTP 面) | route collection から `api/` `ai` 由来の全 action |
| console | 登録された全 Artisan command (`app/Console/Commands/` 配下の具象クラス + `routes/console.php` の無名 command) |
| Filament | 対象 panel の Resource / Page / Action など**組織解決を持ち得る構成要素**の全数 |
| MCP tool | 登録された全 tool / handler (`App\Enums\Mcp\ToolName` の全ケースと実装の突合を含む) |

抽出した母集団の**全件**を、許可 3 種別か `not_organization_scoped` のどちらかへ
**完全一致で分類**する。未登録・余剰登録・重複登録は落ちる。

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
- 転送を置かない代わりに、**リポジトリ内で旧 URL を生成する箇所を走査根ベースで閉じる**。
  「6 系統の一覧」を閉じた母集団と呼ぶのは誇張なので、**走査根 (git 追跡下の PHP 全数 +
  `resources/js/` + `docs/` + `.claude/`) から機械的に抽出**し、下表は
  「見落としやすいので詳細設計で必ず個別に確認する箇所」として持つ:

  | 系統 | 具体 |
  |---|---|
  | PHP の URL 生成全層 | Controller / Service / Job / Event / Listener / Resource / Blade / config の `route()` 呼び出しと URL 直書き |
  | **生成時点で永続化される URL** | queue 済み通知・送信予約メール・DB に保存された本文。**デプロイ時点で queue に積まれている旧 URL は撤去できない** (下の保証外を参照) |
  | 通知・メール本文 | `NotificationController` / 通知クラス / Mailable / `NotificationCenterService` |
  | 画面のリンク・route helper | Svelte 側の `/projects/...` `/billing` `/app` の直書きと `route()` 呼び出し |
  | 撮影 PWA | `manifest.webmanifest` の `start_url` / `capture-sw.js` のキャッシュ判定 / 復路 (`capture.home`) |
  | 導入ガイドの生成物 | `SnippetBuilder` (MCP / CLI セットアップ手順に URL が載る) |
  | 目録・注釈 | bug-hunt の `inventory/annotations.toml` (route を足したら注釈も足す規約) |
  | 文書 | `docs/` / `doc/` / `README` / 運用手順 / 生成テンプレート |
  | テスト | 旧 URL 直書きの Feature / Browser / js テスト |

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

- `OrganizationSlug` は**検証・正規化に成功した値だけを持つ不変の値オブジェクト**。
  生成は名前付きコンストラクタ 2 本 (`fromInput()` = 利用者入力 / `deriveFromName()` = 組織名からの導出) に限る。
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
3. 識別名の値オブジェクト・理由分類必須の予約語設定・改名台帳・30 日 5 回の制限・改名 route が実在し、
   予約語と route 表の整合を機械検査が固定している。
4. 大小無視の一意性が **DB 制約**で担保されている (`CHECK (slug = lower(slug))` + 通常 `UNIQUE`)。
   値オブジェクトを迂回した直接 SQL が CHECK で落ちること、並行改名の競合が一意制約違反になることが
   実 DB のテストで確認されている。
5. 機械経路 (api / ai / console / Filament / MCP) の**組織を確定する入口が全数分類され**、
   許可された 3 種別 (`primary_key_binding` / `actor_derived` / `relation_scoped`) 以外が
   deny-by-default で落ちる。負例で両方向が裏取りされている。
6. **旧 URL を生成する箇所が 1 件も残っていない** (上表の 6 系統の棚卸しが完了している)。
7. 移設後の nested route が `NestedRouteIdorDefenseTest` の inventory に全数登録されている。
8. **AGENTS.md の `VERIFICATION_COMMANDS` マーカー内に列挙された検証コマンドが全 green**。
   本設計時点の正本の内容は次の 9 本である (詳細設計・実装の時点で正本と同期していることを確認する。
   ここに写した一覧が正本と食い違ったら**正本が正しい**):
   `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
   `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
   `pnpm build:packages` / `pnpm test:packages`。
9. 乖離台帳 (`docs/template-divergence.md` / `LedgerPins` / 採用時債務一覧) が整合している。


---

## 再レビュー依頼

Round 2 の Critical 4 件 (検証コマンド / DB の大小無視一意性 / relation_scoped の信頼起点 /
母集団の機械抽出) と Warning 4 件への対応が十分かを判定してほしい。

なお本フェーズは**概念設計**であり、クラス名・シグネチャ・migration の具体的な DDL・
テストケース名の水準は次フェーズ (詳細設計) の責務である。
概念設計として決めるべきこと (方針・境界・保証範囲・スコープ・成功条件) が確定しているかで判定してほしい。

全体判定を APPROVED / CHANGES_REQUESTED で示すこと。

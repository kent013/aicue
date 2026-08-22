# Round 2: Round 1 指摘への対応

Round 1 の Critical 2 件・Warning 5 件・Suggestion 3 件すべてに対応した。対応マトリクスと改訂後の概念設計全文を示す。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] 自己検証が OCR の 3 語を検出できることを保証していない (scanner 共有だけでは証明にならない)
- 判断: **対応する**
- 根拠: 正典 (3) は「検出器自身の正しさ」を求めており、走査器を共有しても**検索語ごとに語境界・記号・大小の扱いが変わる**ため、語ごとに裏取りが要るのは正しい。AGENTS.md (c)(e) とも整合する。
- 対応内容: 自己検証を**検索語でパラメータ化**する設計へ変更。撤去語ごとに正例・負例のマトリクスを持たせ、負例は AGENTS.md (e) が要求する接頭辞つき・接尾辞つき・打ち消しつきの 3 形に加えて、複数拡張子・複数ファイルにまたがる検出を必ず含める。見本は「走査根の外に置き、走査器へ直接渡す経路」を設ける (走査根の母集団を汚さないため)。

## [Critical] A の「該当なし」宣言が不完全 (実行時不在の観測軸を黙って省略している)
- 判断: **対応する**
- 根拠: 正典 (1) は観測軸を 4 つ (route 名 / メソッド×URI / クラス・表 / 実 HTTP 404 と無副作用) 挙げており、黙って省くのは (b) の「無言で候補から外さない」に反する。家系の裁定も「該当なしは理由つきで宣言する」形だけを認めている。
- 対応内容: **撤去物 × 観測軸の対応表**を概念設計に新設し、A と B の全軸を「検査する」または「該当なし (理由)」で埋める。

## [Warning] `config('...') === null` では null 値で復活したキーを通してしまう
- 判断: **対応する**
- 根拠: 事実として正しい。値の判定と存在の判定は別物であり、fail-open になる。
- 対応内容: 設定木を配列として取得し **キーの存在有無**で判定する形へ変更。配列でない場合も fail-closed で落とす。

## [Warning] `confirmPassword` キーの「生成して列挙する」仕様が曖昧で、空の母集団が全件 false で通る
- 判断: **対応する**
- 根拠: 「母集団が 0 件なのに緑」は AGENTS.md (b) 第 3 項が名指しで禁じている形そのもの。
- 対応内容: 探索対象の設定木・探索する位置 (キー名の再帰一致)・母集団の最低件数 (実測 2 件を下限として pin)・`false` と見なす許容値 (**厳密に `false` のみ**) を概念設計に明文化する。

## [Warning] A の「middleware 登録の形」の字句定義が不足しており fail-closed を満たさない
- 判断: **対応する** (設計を大きく書き直す)
- 根拠: 指摘は正しい。「形で許す」だけでは、許可形に当たらない出現の扱いが未定義になり、見逃す方向へ倒れる余地が残る。
- 対応内容: 走査を**判定可能性で 3 層に割り、層ごとに有限の許可形を宣言し、許可形に一致しない出現はすべて違反にする** (未分類は落とす) 形へ変更した。実測で全出現を数え、この 3 層で過不足なく分類できることを確認済み:
  - Tier 1 (本番面の PHP): `PhpTokenScan::normalize()` が**コメントを除いた**トークン列を返すので、文字列リテラル中の出現だけを見る。許可形は (i) 配列キー位置 (直後が `=>`)、(ii) `route(` / `->name(` の引数、の 2 形のみ。実測の該当は `config/seo.php` の 1 件 ((i)) だけ
  - Tier 2 (本番面の非 PHP: `.svelte` / `.ts` / `.js` / `.css` / `.json` / `.yaml`): 許可形は**行頭がコメント記号 (`//` `*` `#` `<!--`) の行**の 1 形のみ。実測の該当は `Security.svelte` の 1 件だけ
  - Tier 3 (`.github/` と `scripts/`): 許可形**なし**。トークン 0 件固定。実測 0 件
- なお `password.confirm` の他の出現 (PHP の docblock / 行コメント 10 件) は Tier 1 のトークン走査で**そもそも母集団に入らない**ため、許可一覧に載せる必要がない。

## [Warning] 効果の主張が広すぎる (未知の再有効化形は防げない)
- 判断: **対応する**
- 根拠: AGENTS.md「検出力の主張の書き方」が誇張を禁じている。
- 対応内容: 期待効果を「宣言した許可形の外にある出現をすべて落とす (未分類は違反) ことによる再流入防止と、`.github/` `scripts/` のトークン再流入防止」に限定して書き直す。あわせて**保証しないもの**を明記する (分割連結・定数経由・動的組み立て)。

## [Warning] 走査根ごとの「ファイル数の床値」は保守コストになる
- 判断: **対応する** (提案どおり数値の完全一致をやめる)
- 根拠: 正典は床値の数値までは要求しておらず、要求は「母集団が空なのに緑にならないこと」である。数値 pin は言語ファイル整理などの無関係な変更で赤くなる。
- 対応内容: 「各走査根が解決でき、かつ対象拡張子のファイルが**1 件以上ある**こと」を空振り検査とする。数値の完全一致は置かない。

## [Suggestion] 自己検証の見本は走査根の外にあるため、走査器へ直接渡す経路が要る
- 判断: **対応する**
- 対応内容: 走査器の入口を「ファイル一覧を受け取って候補を返す純関数」にし、見本はその入口へ直接渡す。母集団の非空を契約とするのは**使う側の gate** に置く (AGENTS.md (b) 第 3 項の但し書きどおり)。

## [Suggestion] B の探索語が CI / scripts へ流入し得るかは別途確認が必要
- 判断: **対応する**
- 対応内容: 実測した (`.github/` `scripts/` に OCR の 4 語はいずれも 0 件)。流入経路としては、常時有効化を戻す運用スクリプトや CI の env 設定が現実的であるため走査根に含める意味はあると判断し、その理由を設計へ書く。

## [Suggestion] 使命整合・台帳駆動フレームワークを作らない判断は妥当 / PHPStan 適合
- 判断: **見送る (現行方針を維持)**
- 根拠: 追認の内容であり設計変更を要さない。PHPStan level 10 については詳細設計で型注釈まで書く。


---

## 追加の実測情報 (対応の裏取り)

Round 1 で「未知の構文が fail-closed でない」「実際に CI/scripts へ流入し得るか未確認」と指摘された点について、リポジトリを実測した結果:

- `password.confirm` の**厳密なトークン一致** (後続が `[A-Za-z0-9_.-]` でない) は、走査根 (app/ bootstrap/ config/ lang/ routes/ resources/ scripts/ .github/) 全体で **12 件**。内訳は PHP のコメント / docblock 10 件、`config/seo.php:90` の配列キー 1 件、`resources/js/pages/Settings/Security.svelte:64` のブロックコメント行 1 件。`.github/` と `scripts/` は **0 件**
- OCR の 4 語の実測: `ocr_analysis_enabled` は PHP コメント 2 件 (`app/Support/Manual/AcceptedSourceDocumentTypes.php:13` の docblock、`config/manual.php:58` の行コメント) と Svelte のブロックコメント 1 件のみ。`OCR_ANALYSIS_ENABLED` / `imageSourceDocumentsEnabled` / `imagesEnabled` は走査根全体で **0 件**。`.github/` と `scripts/` は 4 語とも 0 件
- `confirmPassword` キーの設定木実測 (`php artisan` 起動下): `fortify-options.two-factor-authentication.confirmPassword => false` と `fortify-options.passkeys.confirmPassword => false` の **2 件**。`config('fortify')` 側には解決後キーとして現れない
- `config('manual')` の解決後キー一覧に `ocr_analysis_enabled` は**存在しない** (30 キーを実測)

したがって、改訂後の Tier 1〜3 の許可形 (Tier 1 は 2 形、Tier 2 は 1 形、Tier 3 は 0 形) は**実測の全出現を過不足なく分類でき**、これ以外の出現はすべて違反として落ちる (未分類を落とす = fail-closed)。

---

## 改訂後の概念設計 (全文)

# 概念設計: password-confirm-surface-removal-gate-v1

家系の機能台帳 lctl の feature `surface-removal-absence-gate` (canonical v1) への追従設計。

> Round 1 の Codex レビュー (CHANGES_REQUESTED / Critical 2・Warning 5) を反映済み。
> 対応の判断は `codex-history/conceptual-review-decisions-round-1.md`。

## 背景・課題

### 正典が求めていること (v1)

「撤去した表面 (route / 画面 / API / 機構) が黙って戻らないこと」を機械で守る型。正典 v1 の必須要素は 5 つ:

1. **実行時の不在層** — 撤去物が動いているアプリに実在しないことを固定する (route 名の不在 / メソッド×URI の不在 / クラス・表の不在 / 実 HTTP が 404 で副作用が無い)
2. **静的な字句走査層** — production surface へ撤去物の参照が再流入していないことを字句で 0 件固定する。**場所の許可一覧を持たない**
3. **検出器自身の自己検証** — わざと違反させた正例と、反応してはならない負例の**両方**で検出力を裏取りする
4. **消しすぎていないことの確認層** — 残すべきもの (置換先) が生きていることを別に固定する
5. **走査根に `.github/` と `scripts/` を必ず含める** — 撤去の見張りが CI 設定と運用スクリプトを見ておらず、撤去後に CI が全滅した実測事故 (motivation@668f0266) の教訓。**スキーマ移行履歴 (`database/migrations/`) は走査根に含めない** (撤去した表名は移行履歴に必ず残るため、含めると原理的に赤くなる)

加えて三角測量として、正典は「検査対象の列挙が腐らないこと」= **母集団を生成し、既定拒否にする**ことを求める。

家系の先行実装 aigenba に対して 2026-08-18 の巡回が承認した v1 解釈のうち、本設計が依拠するもの:

- (a) 「場所を並べた許可一覧」を作らない限り、**形で許す**のは v1 適合である (場所で許すより狭く、機械で確かめられる)
- (b) 叩く URL を持たない撤去物については、実行時層を等価の別の形で定義してよい
- (c) 該当しない層は「該当なし」と**理由つきで宣言**してよい

### aicue の現状 (実読による)

撤去物の不在を固定する資産は `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` の **1 本のみ** (44 行)。中身は登録済み route を全数走査し `password.confirm` middleware を持つ route が 1 本も無いことを deny-by-default で固定する実行時検査である。

| 正典 v1 の要素 | aicue の現状 | 判定 |
|---|---|---|
| (1) 実行時の不在層 | あり (route 全数走査 + deny-by-default) | **部分的**。空振り検出が「route 総数 > 0」しかなく、`gatherMiddleware()` が全 route で空を返す壊れ方 (middleware 解決の破壊) を検出できない |
| (2) 静的な字句走査層 | **無い** | 欠落 |
| (3) 検出器自身の自己検証 (正例 / 負例) | **無い** | 欠落 |
| (4) 消しすぎていないことの確認層 | **無い** (置換先 recent-auth の生存を固定していない) | 欠落 |
| (5) 走査根に `.github/` と `scripts/` | 該当する走査が無い | 欠落 |

`tests/Architecture/BughuntNamingResidualTest.php` は字句走査型だが、固定するのは**改名の完遂 (旧名の残留)** であり、本 feature が扱う撤去物の不在ではない (家系では別 feature `rename-residual-name-gate` の受け持ちであることが 2026-08-18 の巡回で確定している)。したがって aicue は依然として字句走査層を持たない。

### 実測で判明した、この撤去物に固有の事実 (設計の前提)

`php artisan route:list` の実測で、**`password.confirm` という名前の route は今も存在する**:

```
GET  /user/confirm-password          password.confirm         (Fortify の救済 redirect view)
POST /user/confirm-password          password.confirm.store   (Fortify の照合 endpoint)
GET  /user/confirmed-password-status password.confirmation    (Fortify の状態プローブ)
```

つまり **aicue が撤去したのは route / 画面ではなく、「Fortify 標準の step-up 機構 (`password.confirm` middleware による保護)」という機構**である。撤去の理由と再流入経路は既存 docblock が正しく書いている:

- SSO-only ユーザー (password 未設定) がその route で**詰む**
- `confirmPasswordView` は recent-auth への redirect でしかなく `auth.password_confirmed_at` を満たせないため**無限ループ**になる (bug-hunt F-11)
- `laravel/passkeys` は config 既定が `management_middleware = ['password.confirm']` で、`fortify-options.passkeys.confirmPassword` を落とすと**設定 1 つで即座に復活する**

この事実は正典の当てはめ方を 2 点で変える:

- 正典の言う「実 HTTP が 404」「route 名の不在」は、この撤去物には**該当しない**。該当しない層は黙って落とさず、下記の観測軸対応表で**軸ごとに理由つきで宣言する** (解釈 (c))
- **字句走査で `password.confirm` を素朴に 0 件固定することはできない**。`config/seo.php` の route 名キー、撤去の理由を書いた説明コメントなど、**正当な残存が実在する**。したがって「許可一覧を持たない」という正典の要件は、**場所ではなく形で許す** (解釈 (a)) ことで満たす

### 撤去物 × 実行時観測軸の対応表 (正典 (1) の全軸を埋める)

| 観測軸 | A: `password.confirm` step-up 機構 | B: OCR 機能フラグ `manual.ocr_analysis_enabled` |
|---|---|---|
| route 名の不在 | **該当なし**。撤去したのは機構であり固有の route を持たない。同名 route (`password.confirm` / `.store` / `password.confirmation`) は Fortify が救済 redirect と状態プローブとして**意図的に残している現役資産**である | **該当なし**。フラグは設定値であり route を持たない (フラグの有無で route 集合は変わらない) |
| メソッド×URI の不在 | **該当なし** (同上。URI `user/confirm-password` は現役) | **該当なし** |
| クラス・表の不在 | **該当なし**。撤去で消えたクラス・表は無い (機構は Fortify 側のクラスであり vendor に存在し続ける)。aicue 側が撤去したのは**その機構の適用**である | **検査する**。撤去で消えたメソッド `AcceptedSourceDocumentTypes::imagesEnabled()` が宣言として存在しないことを静的層で固定する。表の追加・削除は無い |
| 実 HTTP が 404 で副作用なし | **該当なし** (同上) | **該当なし** |
| 機構に対応する等価の実行時層 (解釈 (b)) | **検査する** (3 つ) — (i) `password.confirm` middleware を持つ route が 1 本も無い / (ii) 再有効化スイッチ (`confirmPassword` キー) が生成された母集団のうえで全件 `false` / (iii) 置換先 generic recent-auth が生きている | **検査する** (2 つ) — (i) 設定木 `manual` に `ocr_analysis_enabled` **キーが存在しない** / (ii) 常時有効化の帰結 (画像 SOP の受理) は T242 が残した既存テストが固定しており、docblock からそれを指す (二重に持たない) |

### aicue の他の撤去済み表面の棚卸し (追加の要否)

| # | 撤去した表面 | 出典 | 現状の担保 | 判定 |
|---|---|---|---|---|
| A | Fortify 標準 step-up 機構 (`password.confirm` middleware) | bug-hunt F-11 | 実行時層 1 本のみ | **本設計の中核。v1 へ揃える** |
| B | OCR 機能フラグ `manual.ocr_analysis_enabled` (常時有効化) | T242 (2026-08-21) | **機械の担保ゼロ**。撤去 PR の施策 S10 が grep を人手で 1 回見ただけ。config には撤去した旨のコメントだけが残る | **本設計で追加する** |
| C | 滞留回収の旧実装 (コマンド 5 本 / クラス / メソッド宣言) | T171 | 静的 gate `RetiredRecoveryReferenceGateTest` あり。実行時層・自己検証は無い | 追加しない (別 TODO)。担保がゼロではなく、v1 化は独立に判断できる |
| D | `organization_invitations.project_role` 列 (裁定 AG-079) | D9 | 残存は `database/migrations/` のみ (実測)。正典は移行履歴を走査根から除く | **不要**。app/ 側に参照が 1 件も無く、列を戻すには migration の新設が要るため再流入経路が実質無い |
| E | worktree-local flock (D10) | D10 | グローバルロックの目録 `GlobalTestLockInventoryTest` が事実上の消しすぎ検出 | 追加しない (再流入経路が薄い) |
| F | phantom password (`Str::password(32)`) の保存 (D13) | D13 | `LoginMethodInventoryTest` / `SocialAuthTest` / `RecentAuthTest` が `hasPassword()` の真実性を固定 | 追加しない (実行時層が既に等価の担保になっている) |
| G | scripts 台帳の CI 検査 (T210) / post-commit collector (T110) | T210 / T110 | 無し | 追加しない。どちらも「置き換え先へ一本化した」変更であり、戻ると壊れる不変条件が別 gate 側にある |

→ **追加するのは B のみ**。A を v1 の標準形へ揃え、B を同じ枠組みへ相乗りさせる。C は担保がゼロでないため、本設計では棚卸し結果として記録するに留める (思考原則 2: 今必要なものだけ作る)。

## 改善アイデア

**撤去物 1 件ごとに手書きする motivation 形**を採る (aigenba 形の「撤去項目の台帳から 4 層を機械駆動する」形は、対象が 2 件の aicue には過大)。ただし**走査根の列挙と走査器だけは 1 本に寄せる** (AGENTS.md「同じ列挙を 2 本持たない」)。

### 1. 走査根の単一出典を新設する

`tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php`

- 走査根: `app/` `bootstrap/` `config/` `lang/` `routes/` `resources/` に加えて **`.github/` と `scripts/`** (正典 (5))
- `database/migrations/` は**含めない**ことを docblock に理由つきで書く (正典の明文)
- **存在しない根は fail-fast**。**各根について対象拡張子のファイルが 1 件以上あること**を空振り検査とする (数値の完全一致は置かない — 無関係な整理で赤くなる保守コストを避ける)
- 追跡下のファイルだけを見る (`git ls-files`)。既存の `Tests\Support\TrackedPhpSourceFiles` は拡張子 `.php` に限られ `.yml` / `.svelte` / シェルスクリプトを拾えないため、**同じ作法で拡張子を広げた兄弟**として置き、両者の関係を docblock に書く

### 2. 撤去語の走査器を新設する

`tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php`

**入口は「ファイル一覧と撤去語を受け取り、分類済みの出現を返す純関数」**にする。母集団の非空を契約とするのは**使う側の gate** に置く (AGENTS.md (b) 第 3 項の但し書き)。これにより自己検証の見本を走査根に置かずに走査器へ直接渡せる。

**語の境界判定** (AGENTS.md (e)。区切りを宣言し、非対称に定義する):

- 撤去語の直後が継続文字集合 `[A-Za-z0-9_.\-]` に属するなら**一致としない**。これにより `password.confirm.store` / `password.confirmation` / `password.confirmed` を巻き込まない
- 撤去語の直前が継続文字集合 `[A-Za-z0-9_\-]` に属するなら**一致としない**。**直前側だけ `.` を継続文字から外す**のは、`auth.password_confirmed_at` のような別語の一部と、`config.password.confirm` のような**同一語への到達路**を区別するためである (非対称であることと理由を docblock に書く)

**判定可能性による 3 層** (層ごとに有限の許可形を宣言し、**許可形に一致しない出現はすべて違反**にする = 未分類は落とす):

| 層 | 対象 | 許可形 (これ以外はすべて違反) | 実測の該当 |
|---|---|---|---|
| Tier 1 | 本番面の `.php` | `PhpTokenScan::normalize()` で**コメントを除いた**トークン列を取り、文字列リテラル中の出現だけを母集団にする。許可形は (i) **配列キー位置** (直後のトークンが `=>`)、(ii) `route(` / `->name(` の直後の引数、の 2 形のみ | A: `config/seo.php` の 1 件 ((i)) / B: 0 件 |
| Tier 2 | 本番面の非 PHP (`.svelte` `.ts` `.js` `.css` `.json` `.yaml` `.yml`) | 出現行を trim した先頭が `//` `*` `#` `<!--` のいずれかで始まる**コメント行**の 1 形のみ | A: `resources/js/pages/Settings/Security.svelte` の 1 件 / B: `SourceDocumentUploadNotice.svelte` の 1 件 |
| Tier 3 | `.github/` と `scripts/` | **許可形なし** (0 件固定)。CI 設定と運用スクリプトにこれらの語が現れる正当な用途は存在しない。正典 (5) の事故教訓が直接効く層 | A: 0 件 / B: 0 件 |

**解決できない形は落とす** (AGENTS.md (b)): 読めないファイル・トークン化に失敗する PHP は無言で候補から外さず、走査の失敗として利用側へ返す。

**保証しないもの (誇張しない)**: 撤去語を分割して連結する書き方・定数経由の参照・実行時に組み立てた文字列には**沈黙する**。Tier 1 の PHP コメントと Tier 2 のコメント行の**中では沈黙する** (中に middleware 登録を書くことはできないため、保護対象の操作を書ける構文ではない)。

### 3. A (password.confirm 機構) を v1 標準形へ揃える

既存 `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` は**消さず・名前も変えず**、層を足す:

- **層 1 (実行時の不在)**: 既存の deny-by-default 走査を維持し、**空振り検出を強化**する。「route 総数 > 0」に加えて「文字列 middleware を 1 つ以上持つ route が 1 本以上ある」ことを固定する (middleware 解決自体が壊れて全 route が空を返す形で緑になるのを防ぐ)
- **層 2 (再有効化スイッチの既定拒否)**: `config('fortify')` と `config('fortify-options')` の設定木を**再帰的に走査**し、**キー名が `confirmPassword` に完全一致する要素**を母集団として生成する。母集団は**最低 2 件** (実測: `fortify-options.two-factor-authentication.confirmPassword` / `fortify-options.passkeys.confirmPassword`) を下限に pin し、検出した全件が**厳密に `false`** であることを要求する。設定木が配列でない場合は fail-closed で落とす。キーを名指しで書かないので、依存パッケージ更新で新しい `confirmPassword` キーが増えたら**既定で赤くなる**
- **層 3 (消しすぎていない)**: 置換先 generic recent-auth が生きていることを固定する — `recent-auth.confirm` / `recent-auth.password` の route が実在し、`RequireRecentAuth` middleware を実際に持つ route が 1 本以上あること
- **観測軸の宣言**: 上記の対応表を docblock へ写し、「該当なし」の軸を理由つきで宣言する

新設する静的 gate `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php`:

- 走査根の単一出典と走査器を使い、Tier 1〜3 を回す
- 走査が空振りしていないこと (各根が解決でき、対象ファイルが 1 件以上) を固定する
- **既存担保との関係**: `PasskeyPackageContractTest` は `fortify-options.passkeys.confirmPassword` を**名指しで** pin している。本設計の層 2 は**生成された母集団**を見るもので役割が違う (新しいキーの出現を捕まえる) ことを docblock に書き、二重化ではないことを明示する

### 4. B (OCR 機能フラグ) の不在 gate を追加する

`tests/Architecture/OcrFeatureFlagAbsenceGateTest.php`

- **静的層**: 撤去した 4 語 — 設定キー `ocr_analysis_enabled` / env 名 `OCR_ANALYSIS_ENABLED` / Inertia prop 名 `imageSourceDocumentsEnabled` / 撤去したメソッド名 `imagesEnabled` — を Tier 1〜3 で走査する。実測で Tier 3 は 0 件、Tier 1/2 の該当はコメントのみ。**`.github/` と `scripts/` を走査根に含める理由**: 常時有効化を戻す運用スクリプトや CI の env 設定が現実的な再流入経路であるため
- **実行時層**: `config('manual')` を配列として取得し、**キー `ocr_analysis_enabled` が存在しないこと**を固定する (値が `null` で復活しても落ちるように、値ではなく**存在**で判定する)。配列でない場合も fail-closed
- **消しすぎていないことの確認**: 常時有効化の帰結 (画像 SOP が受理されること) は T242 が残した既存テストが固定しており、docblock からそれを指す。同じ検査を二重に持たない

### 5. 自己検証 (正典 (3)) — 検索語でパラメータ化する

`tests/Architecture/fixtures/surface-removal/` に見本を置き、走査器へ**直接**渡す (走査根の母集団には入れない)。

**撤去語ごとに**正例・負例のマトリクスを持つ:

- **正例** (検出できなければならない): Tier 1 の各違反構文 (middleware 配列の要素 / `middleware()` 引数 / 設定配列の値)、Tier 2 の非コメント行での出現、Tier 3 の任意の出現。**複数拡張子・複数ファイルにまたがる**正例を必ず含める
- **負例** (反応してはならない): AGENTS.md (e) が要求する 3 形 — 接頭辞つき (`x-password.confirm` / `legacy_ocr_analysis_enabled`)、接尾辞つき (`password.confirm.store` / `password.confirmation` / `ocr_analysis_enabled_at`)、打ち消しつき (`no-password.confirm` / `disable_ocr_analysis_enabled`) — に加えて、Tier 1 の許可形 2 種と Tier 2 のコメント行

**テストファーストで先に赤くする** (AGENTS.md「同じ PR で揃える 4 点」の 1)。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」を支える現場向けアプリで、SSO-only の現場作業者が**設定 1 つで詰む**事故 (bug-hunt F-11) の再発を、実行時 1 層だけでなく静的層・自己検証・消しすぎ検出まで揃えて塞ぐ。テンプレート取り込みや依存パッケージ更新で黙って戻る経路が実在する撤去物であり、守りの厚さが直接ユーザー体験を守る
- **効果の範囲 (誇張しない)**: 本設計が防げるのは (i) **宣言した許可形の外にある出現をすべて落とす**ことによる本番面への再流入、(ii) `.github/` と `scripts/` へのトークン再流入、(iii) 再有効化スイッチが**新しいキーとして**増えたときの見落とし、の 3 つである。分割連結・定数経由・動的組み立てには沈黙する
- 正典 v1 の 5 要素をすべて満たし、aicue の台帳セルを `pending` → `implemented (v1)` にできる
- 撤去済み表面の棚卸しが設計として残るため、次の撤去のときに「不在 gate を置くか」を必ず考える足場になる

## 実装方針（概要）

| 層 | 新設 / 変更 | ファイル |
|---|---|---|
| 走査根の単一出典 | 新設 | `tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php` |
| 走査器 | 新設 | `tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php` |
| A 実行時層 (強化) | 変更 | `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` |
| A 静的層 + 自己検証 | 新設 | `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php` |
| B 静的層 + 実行時層 | 新設 | `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php` |
| 自己検証の見本 | 新設 | `tests/Architecture/fixtures/surface-removal/` |

**アプリコード (app/ config/ routes/ resources/) は 1 行も変更しない**。本設計はテスト層だけの上積みである。

## 制約・前提

- PHP 8.4 / Laravel 12 / Pest / PHPStan level 10 / `RefreshDatabase` はグローバル適用 (個別 `DatabaseTransactions` 禁止)
- AGENTS.md「静的検査 (gate) と走査器の共通規約」の 5 条 (a)〜(e) に従う。とくに (b) fail-closed・(c) 正例と負例の両方向・(e) 区切り文字の宣言
- AGENTS.md「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」に従う
- 既存テストを削除・上書きしない (`PasswordConfirmMiddlewareAbsenceTest.php` は**名前を変えず**層を足す)
- 既存の担保と二重化しない (`PasskeyPackageContractTest` / T242 の既存テストとの役割分担を docblock に書く)

## スコープ外

- **aigenba 形の「撤去項目の台帳から 4 層を機械駆動する」構造**。対象が 2 件の aicue には過大 (思考原則 2)。3 件目の撤去物が来たときに再判定する
- **改名残留 (`rename-residual-name-gate`) の関心事**。`BughuntNamingResidualTest` はそちらの資産であり触らない
- **棚卸し表の C (滞留回収) の v1 化**。既に静的 gate があり担保がゼロでないため、別 TODO で扱う
- **棚卸し表の D〜G**。再流入経路が薄いか、等価の担保が既にある
- **`database/migrations/` の走査**。正典が明文で除外している
- **アプリコード・`docs/` の変更**。本設計はテスト層のみ
- **走査器の索引 (家系先行実装が持つ「走査器の書き方を検査する仕組み」) の新設**。AGENTS.md がその新設を再検討する条件を別に定めており、本設計はその条件に当たらない
- **分割連結・定数経由・動的組み立ての検出**。字句走査の原理的な限界であり、保証範囲外として docblock に明記する

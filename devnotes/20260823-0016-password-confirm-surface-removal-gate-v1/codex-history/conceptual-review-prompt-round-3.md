# Round 3: Round 2 指摘への対応

Round 2 の Critical 2 件・Warning 3 件・Suggestion 4 件すべてに対応した。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Critical] Tier 2 の「行頭がコメント記号なら許可」は拡張子横断で安全でない (CSS の `#`/`*`、JS の generator、Svelte の 3 構文領域)
- 判断: **対応する (許可形そのものを撤廃する)**
- 根拠: 指摘は完全に正しい。`#` は CSS の id セレクタ、`*` はユニバーサルセレクタや JS の generator になり得る。Svelte は markup / `<script>` / `<style>` の 3 領域を持つため、行頭記号による分類は**実行コードを許してしまう**。fail-closed ではない。
- 対応内容: 提示された 3 案のうち **2 案目 (Tier 2 ではコメントを許可せず、残存コメントから撤去語の字面を除く)** を採る。理由は、拡張子・構文領域ごとのコメント字句解析を自作するより、**許可形を 0 個にして正典 (2) の「許可一覧を持たない (0 件固定)」を字義どおり満たす**ほうが、実装も保証範囲の説明も単純で、家系の先行実装 (`laravel-clause-template:tests/js/architecture/retired-script-name.test.ts` が「撤去名の文字列を自ファイルに置かない」形を採っている) とも整合するため。
- 具体: 実測で Tier 2 に残る出現は**非 PHP の 2 件だけ**であり、いずれも Svelte のブロックコメント内の説明文である。実装 PR で**コメント本文から撤去語の字面だけを外す** (意味は保つ):
  - `resources/js/pages/Settings/Security.svelte:64` → 「Fortify 標準のパスワード確認 step-up は撤去済み (generic recent-auth へ統一)」
  - `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte:7` → 「旧 OCR 有効化フラグ (`config/manual.php` から撤去済み)」
  これにより **Tier 2 と Tier 3 はどちらも許可形 0 個の純粋な 0 件固定**になる。
- 副作用: 「アプリコードを 1 行も変更しない」という Round 1 時点の記述は成立しなくなるため、**コメント 2 行の書き換えのみ**へ改める (振る舞いに影響しない)。自己検証には Codex 指摘の構文境界 (CSS の `#id` / `*` セレクタ、JS/TS の generator、Svelte の 3 領域) を**正例**として置き、これらが確実に違反として検出されることを固定する。

## [Critical] `.github/` と `scripts/` を拡張子の許可集合で列挙するとスクリプトを取りこぼす
- 判断: **対応する**
- 根拠: 実測すると `scripts/` 配下には拡張子なしの実行ファイルが 4 本 (`scripts/codex` / `scripts/claude` / `scripts/claude-account` / `scripts/claude-statusline`)、`.sh` が 15 本、`.py` が 2 本ある。拡張子の許可集合方式ではこれらが母集団から落ち、正典 (5) の事故 (CI 設定と運用スクリプトの見落とし) をそのまま再現する。
- 対応内容: **走査根の母集団は拡張子で絞らず、git 追跡下の通常ファイルを全数**にする。そのうえで:
  - 内容が UTF-8 として読めないファイル・読み取りに失敗したファイルは **fail-closed** (無言で外さず、走査の失敗として利用側 gate を落とす)
  - バイナリの扱いは「NUL バイトを含むファイル」という**機械で判定できる規則**で対象外にし、docblock に「バイナリには沈黙する」と保証範囲として明記する (実測: 走査根 8 本の追跡下ファイルに NUL を含むものは **0 件**)
  - **根ごとに母集団の種別まで確認する**。`scripts/` は「拡張子なしの追跡ファイルが 1 件以上」かつ「`.sh` が 1 件以上」、`.github/` は「`workflows/` 配下の YAML が 1 件以上」を固定する (「各根に 1 件以上ある」だけでは `.github/` の YAML だけ数えて `scripts/` のシェルを 1 件も見ていない状態を防げない、という指摘どおり)
  - 自己検証には `.sh` / 拡張子なし (shebang のみ) / `.yaml` / `.yml` の見本を含める

## [Warning] Tier 1 の「配列キー位置なら許可」は広すぎる (`'password.confirm' => SomeMiddleware::class` が通る)
- 判断: **対応する**
- 根拠: 指摘は正しい。任意の配列キーを許す形は route 名用途を保証しない。
- 対応内容: 許可形を次の**3 条件の連言**まで狭める (どれか 1 つでも欠ければ違反):
  1. 出現が文字列リテラルトークンで、**直後のトークンが `=>`** である (キー位置)
  2. `=>` の**直後のトークンが単独の文字列リテラル**である (見出し文字列。`::class` / 配列リテラル / 定数参照はここで落ちる)
  3. その文字列が、**実際に登録されている route の名前と完全一致**する (`Route::getRoutes()` と突き合わせる)
  条件 3 により、Fortify が同名 route を登録しなくなった時点で**許可も自動的に消える** (許可が腐らない)。`route(` / `->name(` 形の許可は、実測で該当が 0 件であり**使わない形を宣言しない**ため**撤回する** (思考原則 2)。

## [Warning] 許可形は撤去語ごとのポリシーにすべき (共通 scanner が常に許すと OCR 語が route 名として再流入しても通る)
- 判断: **対応する**
- 根拠: そのとおり。走査器が許可ポリシーを持つと、語ごとの妥当性を検証できない。
- 対応内容: 走査器は**出現と構文上の形 (shape) だけを返す純関数**にし、**許可ポリシーは各 gate が指定する**設計へ変更する。
  - A (`password.confirm`): Tier 1 で上記の 3 条件を満たす形 1 種のみ許可。Tier 2 / Tier 3 は許可形なし
  - B (OCR の 4 語): **全 Tier で許可形なし** (0 件固定)

## [Warning] 「宣言した許可形の外にある出現をすべて落とす」の主張は Tier 2 の誤分類が残る間は成立しない
- 判断: **対応する** (Tier 2 の誤分類自体を撤廃したため主張が成立する)
- 対応内容: Tier 2 を許可形 0 個にしたので、主張はそのまま維持できる。ただし保証範囲の但し書き (分割連結・定数経由・動的組み立て・バイナリには沈黙する) は引き続き明記する。

## [Suggestion] `imagesEnabled` は一般名すぎる。宣言・呼び出し形に限定するほうが目的に合う
- 判断: **対応する**
- 根拠: 将来 OCR と無関係な同名メソッドが必要になったとき、本 gate が全 production surface を止める。撤去物の識別子として固有でない。
- 対応内容: `imagesEnabled` は**素のトークン一致では見ない**。Tier 1 の PHP トークン走査で **(i) 宣言形 (`function` の直後の `imagesEnabled`)** と **(ii) `AcceptedSourceDocumentTypes::imagesEnabled` の静的呼び出し形** の 2 形だけを違反とする。非 PHP (Tier 2/3) では見ない (PHP のメソッド名であり非 PHP に現れる正当な経路が無く、現れても意味を持たないため、検出力を主張しない旨を docblock に書く)。

## [Suggestion] 型として固定すべきもの (走査対象ファイル / 検索語 / Tier / 出現位置 / 分類結果 / 許可形 / 未解決)
- 判断: **対応する** (詳細設計で反映)
- 対応内容: 走査器の戻り値を「出現のリスト」と「未解決のリスト」に分けた専用の値オブジェクトにし、未解決を空配列へ混ぜない。詳細設計で PHPStan level 10 を通る型注釈まで書く。

## [Suggestion] 使命整合 / 禁止事項 / スコープ / `bootstrap/` の上積みは妥当
- 判断: **見送る (現行方針を維持)**
- 根拠: 追認であり設計変更を要さない。


---

## 追加の実測 (対応の裏取り)

- 走査根 8 本 (`app/ bootstrap/ config/ lang/ routes/ resources/ .github/ scripts/`) の git 追跡下ファイルを拡張子別に数えた: php 925 / svelte 123 / ts 67 / sh 15 / yaml 5 / gitkeep 4 / css 3 / yml 2 / py 2 / txt 1 / md 1 / json 1 / gitignore 1 / **拡張子なし 4** (`scripts/codex` `scripts/claude` `scripts/claude-account` `scripts/claude-statusline`)。ご指摘のとおり拡張子の許可集合では拡張子なしの 4 本と `.py` 2 本が落ちるため、母集団を全追跡ファイルへ変更した
- 同 8 根の全追跡ファイルのうち **NUL バイトを含むもの (バイナリ) は 0 件**。バイナリ除外規則は現状の母集団を 1 件も減らさない (規則は将来の混入に備えたもの)
- Tier 2 に残る非 PHP の出現は実測で **2 件のみ** (`resources/js/pages/Settings/Security.svelte:64` と `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte:7`。どちらも Svelte のブロックコメント内の説明文)。この 2 行の文言から撤去語の字面を外せば Tier 2 は許可形 0 個で 0 件固定にできる

---

## 改訂後の概念設計 (全文)

# 概念設計: password-confirm-surface-removal-gate-v1

家系の機能台帳 lctl の feature `surface-removal-absence-gate` (canonical v1) への追従設計。

> Round 1 (Critical 2・Warning 5) と Round 2 (Critical 2・Warning 3) の Codex レビューを反映済み。
> 対応の判断は `codex-history/conceptual-review-decisions-round-{1,2}.md`。

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
- **母集団は拡張子で絞らない**。git 追跡下の通常ファイルを**全数**列挙する。実測で `scripts/` には拡張子なしの実行ファイルが 4 本 (`scripts/codex` `scripts/claude` `scripts/claude-account` `scripts/claude-statusline`)、`.sh` が 15 本、`.py` が 2 本あり、拡張子の許可集合方式ではこれらが落ちて正典 (5) の事故 (CI 設定と運用スクリプトの見落とし) をそのまま再現する
- **存在しない根は fail-fast**。読み取りに失敗したファイル・UTF-8 として読めないファイルは**無言で外さず走査の失敗として利用側へ返す** (AGENTS.md (b))
- **バイナリの扱い**: 「NUL バイトを含むファイル」という機械で判定できる規則で対象外にし、docblock に「バイナリには沈黙する」と保証範囲として明記する (実測: 走査根 8 本の追跡下ファイルに NUL を含むものは **0 件**)
- **空振り検査は根ごとに種別まで見る**。「各根に対象ファイルが 1 件以上」だけでは、`.github/` の YAML だけ数えて `scripts/` のシェルを 1 件も走査していない状態を防げない。したがって次を固定する:
  - 各根が解決でき、追跡下ファイルが 1 件以上ある
  - `scripts/` に**拡張子なしの追跡ファイルが 1 件以上**かつ **`.sh` が 1 件以上**ある
  - `.github/workflows/` 配下の YAML が **1 件以上**ある
  - 数値の完全一致は置かない (無関係な整理で赤くなる保守コストを避ける)
- 追跡下のファイルだけを見る (`git ls-files`)。既存の `Tests\Support\TrackedPhpSourceFiles` は拡張子 `.php` に限られるため、**同じ作法で母集団を全ファイルへ広げた兄弟**として置き、両者の関係を docblock に書く

### 2. 撤去語の走査器を新設する

`tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php`

**走査器は許可ポリシーを持たない**。入口は「ファイル一覧と撤去語を受け取り、**出現とその構文上の形 (shape) だけ**を返す純関数」とし、**どの形を許すかは各 gate が指定する**。撤去語ごとに妥当な許可形は異なるため (A の route 名用途は B には無い)、ポリシーを走査器へ埋め込まない。母集団の非空を契約とするのは**使う側の gate** に置く (AGENTS.md (b) 第 3 項の但し書き)。これにより自己検証の見本を走査根に置かずに走査器へ直接渡せる。

**語の境界判定** (AGENTS.md (e)。区切りを宣言し、非対称に定義する):

- 撤去語の直後が継続文字集合 `[A-Za-z0-9_.\-]` に属するなら**一致としない**。これにより `password.confirm.store` / `password.confirmation` / `password.confirmed` / `ocr_analysis_enabled_at` を巻き込まない
- 撤去語の直前が継続文字集合 `[A-Za-z0-9_\-]` に属するなら**一致としない**。**直前側だけ `.` を継続文字から外す**のは、`auth.password_confirmed_at` のような別語の一部と、`config.password.confirm` のような**同一語への到達路**を区別するためである (非対称であることと理由を docblock に書く)

**判定可能性による 3 層**。**許可形に一致しない出現はすべて違反**にする (未分類は落とす = fail-closed):

| 層 | 対象 | 走査方法 | 許可形 |
|---|---|---|---|
| Tier 1 | 本番面 6 根 (`app/` `bootstrap/` `config/` `lang/` `routes/` `resources/`) の `.php` (`.blade.php` を除く) | `PhpTokenScan::normalize()` で**コメント・docblock を除いた**トークン列を取り、文字列リテラルと識別子トークンだけを母集団にする | **gate が指定する** (下記 A / B のポリシー) |
| Tier 2 | 本番面 6 根の**それ以外の全ファイル** (`.svelte` `.ts` `.js` `.css` `.json` `.yaml` `.blade.php` ほか。拡張子で絞らない) | 生テキストの語境界一致 | **なし (0 件固定)** |
| Tier 3 | `.github/` と `scripts/` の**全追跡ファイル** (拡張子なしの実行ファイル・`.sh` `.py` `.yml` `.yaml` を含む) | 生テキストの語境界一致 | **なし (0 件固定)** |

Tier 2 で行頭のコメント記号による許可を**置かない**理由: `#` は CSS の id セレクタ、`*` はユニバーサルセレクタや JS の generator になり得て、Svelte は markup / `<script>` / `<style>` の 3 構文領域を持つ。行頭記号での分類は**実行コード中の出現をコメントと誤認して許す**ため fail-closed にならない。拡張子・構文領域ごとのコメント字句解析を自作するより、**許可形を 0 個にして正典 (2) の「許可一覧を持たない (0 件固定)」を字義どおり満たす**ほうが単純で強い (家系の先行実装 `laravel-claude-template:tests/js/architecture/retired-script-name.test.ts` も「撤去名の文字列を自ファイルに置かない」形を採っている)。

そのために、実測で Tier 2 に残る**非 PHP の 2 件**は、実装 PR で**コメント本文から撤去語の字面だけを外す** (意味は保つ。振る舞いに影響しない):

- `resources/js/pages/Settings/Security.svelte:64` → 「Fortify 標準のパスワード確認 step-up は撤去済み (generic recent-auth へ統一)」
- `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte:7` → 「旧 OCR 有効化フラグ (`config/manual.php` から撤去済み)」

PHP のコメント・docblock は Tier 1 のトークン走査で**そもそも母集団に入らない**ため、撤去の理由を書いた既存の docblock 10 件はそのまま残せる (これらは撤去の記録として価値がある)。

**解決できない形は落とす** (AGENTS.md (b)): 読めないファイル・トークン化に失敗する PHP は無言で候補から外さず、**未解決のリストとして分けて返し** (空配列へ混ぜない)、利用側 gate を失敗させる。

**保証しないもの (誇張しない)**: 撤去語を分割して連結する書き方・定数経由の参照・実行時に組み立てた文字列には**沈黙する**。PHP のコメント・docblock の中では沈黙する (そこに middleware 登録は書けないため、保護対象の操作を書ける構文ではない)。NUL バイトを含むファイル (バイナリ) には沈黙する。

### 3. A (password.confirm 機構) を v1 標準形へ揃える

既存 `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` は**消さず・名前も変えず**、層を足す:

- **層 1 (実行時の不在)**: 既存の deny-by-default 走査を維持し、**空振り検出を強化**する。「route 総数 > 0」に加えて「文字列 middleware を 1 つ以上持つ route が 1 本以上ある」ことを固定する (middleware 解決自体が壊れて全 route が空を返す形で緑になるのを防ぐ)
- **層 2 (再有効化スイッチの既定拒否)**: `config('fortify')` と `config('fortify-options')` の設定木を**再帰的に走査**し、**キー名が `confirmPassword` に完全一致する要素**を母集団として生成する。母集団は**最低 2 件** (実測: `fortify-options.two-factor-authentication.confirmPassword` / `fortify-options.passkeys.confirmPassword`) を下限に pin し、検出した全件が**厳密に `false`** であることを要求する。設定木が配列でない場合は fail-closed で落とす。キーを名指しで書かないので、依存パッケージ更新で新しい `confirmPassword` キーが増えたら**既定で赤くなる**
- **層 3 (消しすぎていない)**: 置換先 generic recent-auth が生きていることを固定する — `recent-auth.confirm` / `recent-auth.password` の route が実在し、`RequireRecentAuth` middleware を実際に持つ route が 1 本以上あること
- **観測軸の宣言**: 上記の対応表を docblock へ写し、「該当なし」の軸を理由つきで宣言する

新設する静的 gate `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php`:

- 走査根の単一出典と走査器を使い、Tier 1〜3 を回す
- 走査が空振りしていないこと (各根が解決でき、種別まで含めて母集団が非空) を固定する
- **A の許可ポリシー (これ以外の出現はすべて違反)**: Tier 1 で次の **3 条件の連言**を満たす形 1 種のみを許す。1 つでも欠ければ違反にする:
  1. 出現が文字列リテラルトークンで、**直後のトークンが `=>`** である (キー位置)
  2. `=>` の**直後のトークンが単独の文字列リテラル**である (見出し文字列。`::class` / 配列リテラル / 定数参照はここで落ちる)
  3. その文字列が、**実際に登録されている route の名前と完全一致**する (`Route::getRoutes()` と突き合わせる)
  条件 3 により、Fortify が同名 route を登録しなくなった時点で**許可も自動的に消える** (許可が腐らない)。実測でこの形に当たるのは `config/seo.php` の 1 件だけである。`route(` / `->name(` 形の許可は実測の該当が 0 件のため**宣言しない** (使わない形を作らない。思考原則 2)
- **Tier 2 / Tier 3 は許可形なし** (0 件固定)
- **既存担保との関係**: `PasskeyPackageContractTest` は `fortify-options.passkeys.confirmPassword` を**名指しで** pin している。本設計の層 2 は**生成された母集団**を見るもので役割が違う (新しいキーの出現を捕まえる) ことを docblock に書き、二重化ではないことを明示する

### 4. B (OCR 機能フラグ) の不在 gate を追加する

`tests/Architecture/OcrFeatureFlagAbsenceGateTest.php`

- **B の許可ポリシー: 全 Tier で許可形なし (0 件固定)**。走査器は形だけを返し、gate が「許可 0 個」を指定する
- **静的層 (語ごとに扱いを変える)**:
  - `ocr_analysis_enabled` (設定キー) / `OCR_ANALYSIS_ENABLED` (env 名) / `imageSourceDocumentsEnabled` (Inertia prop 名): Tier 1〜3 で**語境界一致の 0 件固定**。実測は Tier 3 が 0 件、Tier 1 は PHP コメント内のみ (トークン走査の母集団外)、Tier 2 は上記のコメント書き換えで 0 件になる
  - `imagesEnabled` (撤去したメソッド名): **素のトークン一致では見ない**。一般名すぎて、将来 OCR と無関係な同名メソッドが必要になったときに全 production surface を止めてしまうため。Tier 1 の PHP トークン走査で **(i) 宣言形 (`function` の直後の `imagesEnabled`)** と **(ii) `AcceptedSourceDocumentTypes::imagesEnabled` の静的呼び出し形** の 2 形だけを違反とする。非 PHP (Tier 2 / Tier 3) では**見ない** (PHP のメソッド名であり非 PHP に現れる正当な経路が無く、現れても意味を持たない)。この限定を docblock に検出力の主張として書く
  - **`.github/` と `scripts/` を走査根に含める理由**: 常時有効化を戻す運用スクリプトや CI の env 設定が現実的な再流入経路であるため (実測では 4 語とも 0 件)
- **実行時層**: `config('manual')` を配列として取得し、**キー `ocr_analysis_enabled` が存在しないこと**を固定する (値が `null` で復活しても落ちるように、値ではなく**存在**で判定する)。配列でない場合も fail-closed
- **消しすぎていないことの確認**: 常時有効化の帰結 (画像 SOP が受理されること) は T242 が残した既存テストが固定しており、docblock からそれを指す。同じ検査を二重に持たない

### 5. 自己検証 (正典 (3)) — 検索語でパラメータ化する

`tests/Architecture/fixtures/surface-removal/` に見本を置き、走査器へ**直接**渡す (走査根の母集団には入れない)。

**撤去語ごとに**正例・負例のマトリクスを持つ:

- **正例** (検出できなければならない):
  - Tier 1 の違反構文: middleware 配列の要素 / `->middleware('...')` 引数 / `'password.confirm' => SomeMiddleware::class` (値がクラス参照) / `'password.confirm' => ['throttle' => ...]` (値が配列) / 登録されていない route 名をキーにした形
  - Tier 2 の**構文境界の見本** (Round 2 の指摘に対応): CSS の `#password\.confirm` 風 id セレクタと `*` ユニバーサルセレクタ、JS/TS の行頭 `*` generator メソッド、Svelte の markup / `<script>` / `<style>` の 3 領域それぞれでの出現。**いずれも違反として検出されること**を固定する (許可形が 0 個であることの裏取り)
  - Tier 3 の見本: `.sh` / **拡張子なし (shebang のみ)** / `.yaml` / `.yml` での出現
  - **複数拡張子・複数ファイルにまたがる**正例を必ず含める
  - `imagesEnabled` は宣言形・静的呼び出し形を正例に、通常の動的呼び出し (`$x->imagesEnabled()`) と無関係な同名メソッドの宣言 (別クラス) の扱いを docblock の保証範囲どおり固定する
- **負例** (反応してはならない): AGENTS.md (e) が要求する 3 形 — 接頭辞つき (`x-password.confirm` / `legacy_ocr_analysis_enabled`)、接尾辞つき (`password.confirm.store` / `password.confirmation` / `ocr_analysis_enabled_at`)、打ち消しつき (`no-password.confirm` / `disable_ocr_analysis_enabled`) — に加えて、A の Tier 1 許可形 (3 条件を満たす形)、PHP のコメント / docblock 内の出現、NUL バイトを含むバイナリ見本

**テストファーストで先に赤くする** (AGENTS.md「同じ PR で揃える 4 点」の 1)。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」を支える現場向けアプリで、SSO-only の現場作業者が**設定 1 つで詰む**事故 (bug-hunt F-11) の再発を、実行時 1 層だけでなく静的層・自己検証・消しすぎ検出まで揃えて塞ぐ。テンプレート取り込みや依存パッケージ更新で黙って戻る経路が実在する撤去物であり、守りの厚さが直接ユーザー体験を守る
- **効果の範囲 (誇張しない)**: 本設計が防げるのは (i) **宣言した許可形の外にある出現をすべて落とす**ことによる本番面への再流入 (Tier 2 / Tier 3 は許可形 0 個の純粋な 0 件固定、Tier 1 は route 名見出しの 1 形のみ)、(ii) `.github/` と `scripts/` への**拡張子に依存しない**トークン再流入、(iii) 再有効化スイッチが**新しいキーとして**増えたときの見落とし、の 3 つである。分割連結・定数経由・動的組み立て・PHP のコメント内・NUL を含むバイナリには**沈黙する**
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
| 撤去語の字面をコメントから外す | 変更 (コメント 2 行のみ) | `resources/js/pages/Settings/Security.svelte` / `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte` |

**アプリコードの変更はコメント 2 行の文言だけ**で、振る舞いには一切影響しない。これは Tier 2 の許可形を 0 個にして正典 (2) の「許可一覧を持たない」を字義どおり満たすための最小の代償である (行頭記号によるコメント判定は CSS / JS / Svelte の構文と衝突して fail-closed にならないため採らない)。それ以外はテスト層だけの上積みである。

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
- **アプリコードの振る舞いの変更**。変更するのは上記コメント 2 行の文言だけで、`docs/` は触らない
- **走査器の索引 (家系先行実装が持つ「走査器の書き方を検査する仕組み」) の新設**。AGENTS.md がその新設を再検討する条件を別に定めており、本設計はその条件に当たらない
- **分割連結・定数経由・動的組み立ての検出**。字句走査の原理的な限界であり、保証範囲外として docblock に明記する

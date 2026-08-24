# 概念設計レビュー Round 3 の依頼

Round 2 の Critical 2 件・Warning 5 件・Suggestion 1 件をすべて捌きました。
算術不整合は probe の実バグ (集計順) でした。修正して再測定し、全文を同期しています。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Critical] 証人方式に循環除外の穴がある (自己証人 / 相互証人 / 循環証人)
- 判断: 対応する
- 根拠: 指摘のとおり。同じキー集合を持つ対応表同士が互いを証人にして両方消える。
- 対応内容: 提案の最も単純な形を採った — **証人の資格を「対応表のキー形以外の候補」
  (型の合併 / 定数の配列 / 分岐のラベル) に限る**。派生除外の対象になり得る形は証人になれないので、
  判定は非派生候補を種にした**単調な到達判定**になり、自己証人・相互証人・循環証人が
  構造的に起こらない。負例に自己証人・2 件の相互証人・3 件の循環証人を置くことも明記した。
- 実測: 証人資格を絞った結果、派生として外れるのは 63 件中 **10 件**だけになった
  (以前は 13 件)。残り 53 件を候補に残しても鳴る組は 8 件のままで、検出力も運用性も落ちない。

## [Critical] 名前解決不能の候補を「候補に残して名前対応を不成立」にするのは fail-closed でない
- 判断: 対応する
- 根拠: 指摘のとおり。完全一致しない真の部分写しが規則 1 にも規則 2 にも掛からず無言で通る。
  AGENTS.md §共通規約 (b) の「未解決を解決済みと同じ値へ混ぜない」に反する。
- 対応内容: 「名前解決不能」を**解析の失敗として gate を赤くする**に変更した。
  故障注入の一覧にも「名前解決不能を静かに落とすと負例が緑になる」を追加した。

## [Warning] 再測定値の算術不整合 (508 vs 478 / 304 vs 251 vs 301 / 構築時間の揺れ)
- 判断: 対応する
- 根拠: probe に集計順の実バグがあった (証人の無い派生を候補へ戻す**前**に形別の集計を
  取っていたため、総数と内訳が食い違っていた)。指摘は正しい。
- 対応内容: probe を修正し、`total === 各形の合計` を **assert** するようにした。
  再測定して概念設計の数値を全文同期した (母集団 508 本 / 候補 304 件 =
  106 + 163 + 13 + 22 / 鳴った組 8 件)。構築時間は実測に揺れがあるため「約 3 秒
  (2.9〜3.9 秒)」と幅で書いた。

## [Warning] `.svelte` の複数 script を 1 本へ連結するとスコープが混ざる
- 判断: 対応する
- 対応内容: 詳細設計の確定事項に「module 文脈と実体文脈は**スコープを分離したまま**扱い、
  文脈ごとに別の仮想ファイルへ割る」「3 つ以上の script や想定外の属性は不合格」
  「診断の位置を元の `.svelte` へ行だけでなく列まで逆写像できること」を追加した。
  故障注入にも「1 本へ連結すると偽の重複宣言が出て負の対照が赤くなる」を足した。

## [Warning] #5 の申告が「自動的に stale になる」主張は申告の同一性に規則が入っている場合のみ成立
- 判断: 対応する (主張の根拠を明示する形で維持)
- 根拠: 現行の `reverseSweepKey(php, file, declaration, rule)` は**適用規則を同一性に含んでいる**。
  したがって規則 1 → 規則 2a の遷移で申告は stale になる。主張は成立するが、
  その根拠が本文に無かったのは指摘のとおり。
- 対応内容: 根拠 (同一性に規則が入っていること) を本文に明記し、
  「規則 1 → 規則 2a の遷移で実際に赤くなる負例を置く」を詳細設計の確定事項にした。

## [Warning] 「必須プロパティがある」だけでは型検査が過不足を落とす証明として弱い
- 判断: 対応する
- 対応内容: 正例・負例に「型別名越しの `Record` / `Partial` / union / intersection /
  `keyof` / 取り込んだ型 / `satisfies`」を置くことを決着 2 に明記した。
  「判定不能な型は候補へ戻す」原則は維持している。

## [Suggestion] スコープ外の重複行
- 判断: 対応する
- 対応内容: 重複していた「`.svelte` への直書き禁止規則の新設」の 1 行を削除した。

## [Suggestion] 使命との整合 / スコープ / 型安全性
- 判断: 見送る (承認方向の指摘)

## 修正後の概念設計 (全文)

# 概念設計: enum-ts-sync-gate v3 追従

## 背景・課題

家系の機能台帳 `enum-ts-sync-gate` は 2026-08-22 に正典 **v3** (不変条件 i1〜i16) を確定した
(`design_settled` / `doc_sha aeced2ccfd07`)。aicue のセルは `status=update_pending` /
`version=v2` / `target_version=v3` で、追従が要る。**採否の判断は済んでいる**ので、
本設計は「何を採るか」ではなく「aicue の現物にどう着地させるか」を決める。

aicue が既に満たしている不変条件 (実コードで確認):

| 不変条件 | 現物 |
|---|---|
| i2 発見の段の全数走査 | `php-enum-catalog.ts` が `git ls-files app/**/*.php` を全数走査 (resolved 123 / unresolvable 3) |
| i3 既定拒否の 3 分類 + 件数固定 | `PHP_ENUM_EXEMPTIONS` (95) / `KNOWN_UNRESOLVABLE_PHP_ENUMS` (3) / `ENUM_TS_MIRRORS` (29) |
| i4 発見と抽出の走査器共有 | `detectEnumHeaders` を `classifyPhpFile` と `readPhpEnumValuesFromText` が共用 |
| i5 (program 側) | `createMirrorProgram` が tsconfig 全体で program を組み、起点を縮めない |
| i7 登録済みも逆走査の母集団に残す | `findUnregisteredMirrorCandidates` は PHP 側 `resolved` 全件を渡される |
| i11 (片側) | `REVERSE_SWEEP_EXEMPTIONS` に理由 30 文字・実在・重複無し・件数 pin・stale 判定 |
| i14 / i16 | 0 件・読めないは例外。program 構築は `beforeAll` (300 秒) |

**足りないのは逆走査の狭さだけ**である (台帳の aicue セルの note と一致する):

| 欠け | 現物 |
|---|---|
| i8 母集団 | `collectTsUnionCandidates` は `resources/js` 配下の `.ts` だけ。版管理下の `.ts` 379 本のうち **348 本が母集団外** (`packages/cli` 81 本・`tests/js` 226 本ほか)。`.svelte` **130 本は 1 本も見ていない** |
| i6 `.svelte` | 走査対象外だと docblock が自認している |
| i9 候補の形 | トップレベルの型別名 1 種のみ (定数配列・対応表のキー・分岐のラベルは対象外) |
| i10 規則 2 | 「厳密名対応 + 1 値交差」の片側だけ。語分割名対応 + 両側半分以上の交差を持たない |
| i5 (起点) | tsconfig の `include` 外にある `packages/cli` が program に載っていない |
| i13 失敗メッセージ | PHP 側の位置 (行) を出さない。TS 側も行を出さない |
| i15 保証範囲の宣言 | 「`.svelte`・定数配列・case は走査しない」と宣言したままになる |

## 仮説と検証

**仮説**: 逆走査を正典 v3 の広さへ広げると、現物ツリーに**実在するが未検出のドリフト**が出る。
そのとき鳴る誤検出は、申告 1 行で吸収できる規模 (10 件未満) に収まる。

**検証**: 設計段階で判定式そのものを現物ツリーへ走らせて数えた
(`probe/probe.ts` / 実測は `probe/measurements.md`。未決論点 **q2** の解消経路そのもの)。

実測 (2026-08-24):

- 母集団: `.ts` **378 本** (構文を壊した見本 1 本だけ除外) + `.svelte` **130 本** = 508 本。
  program 構築 **約 3 秒** (実測 2.9〜3.9 秒の揺れ) / source files 5,859
  (i16 の前処理枠 300 秒に対して十分小さい)
- 候補 **304 件** (型の合併 106 / 対応表のキー 163 / 定数配列 22 / 分岐のラベル 13。
  合計が総数と一致することを probe 内で assert している)。
  対応表のキー形のうち派生として外したのは**証人のある 10 件だけ**で、
  証人の無い 53 件は候補として残している
- 鳴った組 **8 件** (規則 1 = 6 / 規則 2a = 1 / 規則 2b = 1)
- **誤検出率**: 鳴った 8 件のうち、真の未登録の写しでないもの (= 申告で逃がすもの) は
  #4 / #5 / #6 / #7 / #8 の **5 件**。真に手を入れるべきものは #1 (実ドリフト) と
  #2 / #3 (事実と食い違う分類理由) の **3 件**

鳴った 8 件の内訳:

| # | 規則 | PHP | TS | 判定 |
|---|---|---|---|---|
| 1 | 2a | `app/Enums/ApiErrorCode.php` | `packages/cli/src/api/schemas.ts::ApiErrorCode` | **実ドリフト**。道具側は `rate_limit_exceeded` を持つがサーバは `rate_limited`。サーバ側の 4 値 (`insufficient_ability` / `actor_not_resolvable` / `idempotency_in_progress` / `idempotency_indeterminate`) が道具側に無い。当該ファイルの docblock 自身が「Mirrors `app/Enums/ApiErrorCode.php`」と書いている |
| 2 | 1 | `app/Enums/ApiKeyAbility.php` | `pages/Organizations/ApiKeys/Index.svelte::ABILITY_LABELS` | 独立した対応表 (`Record<string, string>`)。PHP 側の分類理由「管理画面はチェックボックスの選択状態だけを見る」が**事実と食い違っている** |
| 3 | 1 | `app/Enums/OAuth/OAuthClientKind.php` | `pages/Organizations/ApiKeys/Sessions.svelte::CLIENT_KIND_LABELS` | 同上。分類理由「認可ロジックの内部でのみ使う」が事実と食い違う |
| 4 | 1 | `app/Enums/Manual/CutType.php` | `features/manual/ScenarioEditor.svelte::DragOwner` | 別概念 (ドラッグの所有者) が偶然同値。申告 |
| 5 | 1 | `app/Enums/Notification/NotificationType.php` | `features/notifications/NotificationListItem.svelte` の分岐 | 登録済み型で束縛された分岐。申告 (理由には「既定の枝がある」だけでなく、値が増えたときに既定の絵柄へ落ちる利用者影響と期待動作まで書く) |
| 6 | 1 | `app/Enums/Manual/TakeStatus.php` | `types/manual.ts::SelectableTakeStatus` | 既存の申告 (継続) |
| 7 | 1 | `app/Enums/EnterpriseSso/OidcConnectionStatus.php` | `tests/js/.../oidc-connection.test.ts::ALL_STATUSES` | 検査側が並べた全値。申告 |
| 8 | 2b | `app/Enums/Manual/JobStatus.php` | `types/dashboard.ts::DashboardJobStatus` | 意図した真部分集合 (進行中のみ)。申告 |

**仮説は支持された**。広げた判定式は実ドリフトを 1 件見つけ、誤検出は申告 5 件で吸収できる。
この「10 件未満」は**この時点の観測値**であって将来の保証ではない (件数 pin が増減を可視化する)。
規則 2 を論理和にした差分は次のとおりで、**どちらの式も他方を包含しない**ことが実測で裏付いた:

- 規則 2a (厳密名対応 + 1 値交差) だけが拾ったもの = #1 (実ドリフト)
- 規則 2b (語分割名対応 + 両側半分以上の交差) だけが拾ったもの = #8 (誤検出 1 件)

## 改善アイデア

逆走査を正典 v3 の広さへ広げる。**PHP 側の発見の段は既に v3 なので触らない**。

1. **母集団 (i8)**: 版管理下の `*.ts` / `*.svelte` **全数**。0 件は不合格
2. **`.svelte` の第一級化 (i6)**: `<script>` の範囲だけを残し**行番号を元ファイルと一致**させた
   仮想 TS として同じ program に載せる
3. **候補の形 4 種 (i9)**: リテラル型の合併 / 定数の配列 / 対応表のキー / 分岐のラベル
4. **program の起点 (i5)**: tsconfig が含む全体 ∪ 版管理下の `.ts` ∪ 仮想 `.svelte` ∪ 目録のファイル。
   速さのために縮めない (実測 約 3 秒)
5. **規則 2 の論理和 (i10)**: 2a (厳密名対応 + 1 値交差) ∨ 2b (語分割名対応 + 両側半分以上の交差)
6. **申告の再整備 (i11)**: 広がった判定式に合わせて `REVERSE_SWEEP_EXEMPTIONS` を書き直す。
   理由 30 文字・実在・重複無し・件数 pin・**免除適用前**の stale 判定はすべて維持
7. **負の対照 (i12)**: 母集団・受理範囲・申告のそれぞれに負例を置き、故障注入で赤を実測する
8. **メッセージと宣言 (i13 / i15)**: PHP 側と TS 側の**両方の位置 (ファイル + 行)** を出す。
   docblock の保証範囲を書き換える

## 設計上の決着 (triage が挙げた衝突の処理)

### 決着 1: 母集団から外すのは「わざと構文を壊した見本」1 ディレクトリだけ

`tests/js/support/enum-ts-sync/fixtures/candidates-broken/broken.ts` は
**わざと構文を壊した負の対照**である。i14 は「構文が壊れたファイルを無言で読み飛ばさない」ので、
このファイルが母集団に入ると**本番の gate が恒久的に赤**になる (実測: `mode=included` で
`broken syntax files=1`)。申告では逃がせない — 申告は「候補として鳴ったもの」を逃がす仕組みで、
「読めないファイル」の受け皿ではない。

**決着**: 除外するのは `tests/js/support/enum-ts-sync/fixtures/candidates-broken/` **1 つだけ**とし、
`fixtures/` の残り (t01〜t25 / `candidates/mixed.ts`) と `program-fixtures/` は
**母集団に入れる**。除外は次の 4 条件で縛る。

1. 除外根は `tests/js/support/enum-ts-sync/` の配下に限る (構造で縛る。任意のパスを書けない)
2. **件数を pin する** (増えても減っても赤)。現時点は 1 件
3. **除外根が実在し、その配下の全ファイルが実際に構文診断で落ちること**を検査する。
   これで「除外根に正常なファイルを置いて母集団から静かに消す」経路が塞がる
   (置いた瞬間に**この検査が赤くなる**)
4. 除外を docblock の保証範囲へ明記する

見本を母集団に入れても鳴る組は 8 件で変わらない (実測)。したがってこの最小除外で
**検出力は落ちない**。副作用として、`fixtures/` の見本を書き換えると本番 gate の候補集合も
動く (過剰検出の向きなので許容する)。これは docblock に書く。

### 決着 2: 対応表のキーの「派生」除外は、証人つきでだけ行う

`Record<VideoManualStatus, string>` のような対応表は、キーの過不足を `pnpm typecheck` が落とす。
値をその場で決めていない**派生**であり、独立した写しではない。そのまま候補にすると
申告が許可一覧に膨らむ (i11 が禁じる形になる)。

一方、「束縛先の型はそれ自体が候補になる」は**一般には成り立たない** — 束縛先が
取り込んだ型・`keyof`・条件型・合成型で、候補の 4 形のどれにもならないことがある。
そのときに除外すると**代替の候補が存在せず検出力が落ちる**。

**決着**: 対応表のキー形の候補は、次を**すべて**満たすときだけ派生として外す
(1 つでも欠けたら候補として残す = fail-closed)。

- 明示の型 (注釈または `satisfies`) がある
- 型検査器で解決した結果、その型が**文字列の添字シグネチャを持たない**
- その型の**プロパティが 1 件以上あり、すべて必須** (`Partial<Record<…>>` は
  過不足を落とさないので派生と認めない)
- **証人がある** — 束縛先のキー集合と**同一の値集合を持つ候補が、
  「対応表のキー形**以外**」の候補 (型の合併 / 定数の配列 / 分岐のラベル) の中に
  1 件以上ある**。無ければ候補として残す

**証人を対応表以外に限る理由 (循環の遮断)**: 証人を「任意の候補」にすると、
同じキー集合を持つ対応表 A と B が**互いを証人にして両方消える**。
自分自身を証人にする経路も同時に閉じる必要がある。証人の資格を
「派生除外の対象になり得ない形」に限れば、判定は**非派生の候補を種にした単調な到達判定**になり、
自己証人も相互証人も 3 件の循環も構造的に起こらない (一括の相互参照判定にしない)。
負例には**自己証人・2 件の相互証人・3 件の循環証人**を置く。

判定はすべて**型検査器の解決結果**で行う (構文で `Record` や `satisfies` を当てない)。
型を解決できない場合は除外せず候補に残す。正例・負例には
**型別名越しの `Record` / `Partial` / union / intersection / `keyof` / 取り込んだ型 / `satisfies`**
を置き、「必須プロパティがある」だけで派生と断じていないことを固定する。

実測 (2026-08-24): 派生の条件 3 つまでを満たすのが 63 件、うち**証人があるのは 10 件だけ**で、
残り 53 件は候補として残る。それでも鳴る組は 8 件で変わらない。
すなわち循環を塞いだ厳しい証人条件は**ただで買える**。

### 決着 3: 診断文は正しさで決める。その帰結として乖離台帳を 1 件動かす

`tests/js/architecture/enum-ts-sync.test.ts` と `tsconfig.json` は
`docs/template-fingerprints.json` のキーであり、かつ
`tests/Support/TemplateDivergence/adoption-debt.tsv` に採用時ハッシュ付きで凍結されている。
触ると `TemplateDivergenceFingerprintTest` が `mutatedDebtPaths` で落ちるため、
「変更したまま債務に残す」は選べない (app-design 3-0 段)。

**決着**: **台帳の都合で診断文を捻じ曲げない**。順序を固定する —
(1) 受理範囲の正しい診断文を決める → (2) その結果として既存の負の対照が壊れるかを見る →
(3) 壊れるなら台帳の手当てをする。

見積り: 登録できる TS の置き場が `resources/js/` ∪ `packages/*/src/` になるので、
正しい診断文は両方を挙げる形になり、既存の負の対照が照合している語
(`resources/js/ 配下だけ`) は自然な言い回しでは残らない。したがって
**`tests/js/architecture/enum-ts-sync.test.ts` は変更する**前提で設計する。

手当て (同じ変更で行う):

- `docs/template-divergence.md` に **D50** を新設し、
  「前向きの検査を単一ファイルの構文木方式ではなく、共有の走査器 + 型情報方式で持ち、
  目録を逆走査の gate と共有する」という既存の逸脱を登録する
  (テンプレートは v2 = 3,858 行の単一ファイル / 構文木のみ。aicue は 220 行 + 支援モジュール群)
- `tests/Support/TemplateDivergence/adoption-debt.tsv` から
  `tests/js/architecture/enum-ts-sync.test.ts` の行を削る
- `tests/Support/TemplateDivergence/LedgerPins.php` の
  `DIVERGENCE_ENTRY_COUNT` 46→47、`ADOPTION_DEBT_COUNT` 148→147 を同じ変更で直す

**`tsconfig.json` は変えない**。`packages/cli` は **program の起点に足す**ことで型世界へ入れる
(aigenba の `outsideTsconfig()` と同じ方式)。tsconfig の `include` は本番のビルド設定であって
gate の都合で広げるものではなく、広げると `pnpm typecheck` の対象まで動いてしまう。

変更する `tests/js/support/enum-ts-sync/*.ts` と
`tests/js/architecture/enum-ts-sync-discovery*.test.ts` は指紋台帳のキーに無い
(= テンプレートに無い aicue 固有の上積み) ため、登録の義務は生じない。

### 決着 4: 実ドリフト (#1) は申告で黙らせず、同じ変更で直す

`packages/cli/src/api/schemas.ts` の符号一覧はサーバの `App\Enums\ApiErrorCode` と食い違っている。
「値が食い違ったまま申告する」のは抑制コメントで黙らせるのと同じ形で、禁止事項 2 の精神に反する。

**決着**: 道具側の一覧を **(a) サーバの写し** と **(b) 道具固有の符号** の 2 つに割り、
(a) を目録へ登録して値集合の一致を gate に固定させる。(b) はサーバの enum に無い符号なので
PHP 側と交差せず、鳴らない。両者の合併である `ApiErrorCode` は**申告 1 件**で逃がす
(理由: サーバの符号と道具固有の符号の合併であり、写しの実体は (a) 側で登録済み)。

### 決着 4b: 道具側の是正は型の分割だけで終わらせない

決着 4 の分割は**契約の整理**であり、それだけでは道具の振る舞いが正しくならない。
サーバが `rate_limited` を返すのに道具が `rate_limit_exceeded` で分岐している経路は、
現状 HTTP の状態番号への退避で辛うじて動いている。同じ変更で次を固定する。

- **用途の明示**: サーバの写し (a) / 道具固有の符号 (b) / 公開する合併型 (c) の 3 つが
  それぞれ何のためにあるかを docblock に書く
- **道具側の検査**: サーバ固有の符号・道具固有の符号・**未知の符号**の 3 系統について、
  応答の分類が期待どおりであることを `packages/cli/tests` で固定する
  (未知の符号は拒否せず状態番号へ退避する、が既存の契約である)

### 決着 5: 事実と食い違った PHP 側の分類理由を直す (#2 / #3)

`ApiKeyAbility` / `OAuthClientKind` の `PHP_ENUM_EXEMPTIONS` の理由は
「画面へは出ない / 内部でのみ使う」だが、実際には画面の対応表が値をキーにしている。
**理由の文面を事実に合わせて書き直す** (分類そのものは「対象外」のままでよい —
対応表は未知の値を素の文字列で表示する退避を持ち、値の取りこぼしが画面を壊さない)。
そのうえで対応表の側を**申告**へ登録する。

## 期待効果

- **使命への貢献**: 撮影 PWA と管理画面は制作状態・カット種別・通知種別といった
  サーバ側の選択肢で分岐する。写しがずれると「思考ゼロ・編集ゼロ」の導線が
  無言で 1 本欠ける。逆走査が `.svelte` と道具パッケージまで届くことで、
  **画面の中に直接書かれた写しと、付属コマンドライン道具の写し**が初めて検査対象になる
- **実測された具体効果**: 母集団が 62 本 → 478 本 (`.ts` 348 + `.svelte` 130)。
  候補が 87 件 → 199 件。**実在の未検出ドリフト 1 件**を検出
- **家系への貢献**: 未決論点 q2 (論理和の誤検出件数) に**家系で初の一次観測**を与える

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `tests/js/support/enum-ts-sync/program.ts` | 仮想 `.svelte` を載せる compiler host。起点に版管理下の `.ts` と仮想 `.svelte` を足す。`createMirrorProgram(tsFiles)` の**呼び出し形は変えない** |
| `tests/js/support/enum-ts-sync/svelte-source.ts` (新設) | `.svelte` → 行番号を保った仮想 TS への変換 (単体で検査できる純関数) |
| `tests/js/support/enum-ts-sync/ts-candidates.ts` | 母集団を版管理下の全数へ。候補の形を 4 種へ。派生の除外。行番号を持たせる |
| `tests/js/support/enum-ts-sync/reverse-sweep.ts` | 規則 2 を 2a ∨ 2b の論理和へ |
| `tests/js/support/enum-ts-sync/mirror-inventory.ts` | 登録できる TS の置き場を `resources/js/` ∪ `packages/*/src/` へ。`.svelte` の登録も受ける |
| `tests/js/support/enum-ts-sync/ts-value-sets.ts` | `.svelte` の中の型別名を読めるようにする (仮想パスの解決) |
| `tests/js/architecture/enum-ts-sync-discovery.test.ts` | 除外根の pin と検査、申告の再整備、メッセージに両側の位置、docblock の保証範囲 |
| `tests/js/architecture/enum-ts-sync-discovery-extractor.test.ts` | 新しい母集団・受理範囲・申告の負の対照と故障注入 |
| `tests/js/architecture/enum-ts-sync.test.ts` | `validateMirrors()` の負の対照を新しい受理範囲へ (指紋 + 債務。決着 3 の手当てを伴う) |
| `docs/template-divergence.md` / `adoption-debt.tsv` / `LedgerPins.php` | D50 の新設と債務 1 行の解消、件数 pin 2 つの更新 |
| `packages/cli/src/api/schemas.ts` / `client.ts` | 実ドリフトの是正 (決着 4) |
| `AGENTS.md` ドメイン固有規約 19 / `docs/architecture.md` | 受理する形と保証範囲の更新。**正典 v3 の条文を転載しない** — 書くのは aicue 固有の受理範囲・除外集合・保証外だけで、正典は版 (v3) で指す |

## 制約・前提

- **正本のレーンは `pnpm test`**。`composer test` では走らない (この非対称は維持する)
- 走査器・gate の新設変更なので **AGENTS.md §走査器・gate を新設・変更するときに同じ PR で
  揃える 4 点**が発火する (負例と正例 / 解決できない形を落とす分岐 / 空振り検査 / docblock)
- 静的検査の共通規約 (a)〜(e) のうち、本件は **(b) fail-closed**・**(c) 負例の裏取り**・
  **(d) 使わない走査結果を作らない**・**(e) 語彙一致の否定形** が効く。
  規則 2b は語に分けたトークンの完全一致で判定し、**区切り文字を宣言する** ((e))
- 見本置き場は tsconfig の `exclude` にあり `pnpm typecheck` の対象外である。この関係は変えない
- `.svelte` の登録経路は**用意するが aicue に登録対象は現時点で 0 件**である
  (正典 i6 が要求するため用意する。見本で正例・負例を固定する)

## 詳細設計へ持ち越す確定事項 (概念段階で穴を残さないための宣言)

規則 2b と `.svelte` の仮想化は、詳細設計で**次を必ず確定する**。

**規則 2b (語分割名対応 + 両側半分以上の交差)**

- **区切り**: 何を区切りとして語に割るかを宣言する (大文字境界 / 数字境界 / `_` / `-` / `.`)。
  AGENTS.md §共通規約 (e) が要求する「区切り文字の宣言」である
- **正規化**: 大文字小文字と単純な複数形 (`s` / `es` / `ies`) の畳み方を宣言する
- **主要語**: 「頭の名詞」を**語列の末尾の語**と定義する (英語の複合名詞の主要部)。
  実測の `words[job+statu] head=statu` はこの定義の出力である
- **一致数**: 主要語の一致に加え、共通語数が `min(2, 列挙名の語数)` 以上
- **交差**: 交差の要素数が**両側それぞれの要素数の半分以上** (`ceil` 側で切り上げ)。
  値は集合として扱う。どちらかが空集合なら鳴らさない
- **名前を持たない候補**: 分岐のラベルは判定対象の式の**型の名前**を優先し、
  取れなければ式の字面を使う。**どちらも取れないときは「名前解決不能」という
  解析の失敗として gate を赤くする** (候補に残して名前対応だけ不成立にすると、
  完全一致しない真の部分写しが規則 1 にも規則 2 にも掛からず**無言で通過する**。
  AGENTS.md §共通規約 (b)「未解決を解決済みと同じ値へ混ぜない」)
- **診断**: 2a と 2b の両方に該当しても、**どの規則・どの語・どの値の交差で鳴ったか**を出す
- **負例**: 接頭辞つき・打ち消しつき・接尾辞つきの 3 形を置く (共通規約 (e))

**`.svelte` の仮想化**

- 仮想ファイルのパス規則 (実在ファイルと衝突しないこと。`*.svelte.ts` が実在するため
  素朴な `.ts` 付加は採らない)
- `<script>` が複数ある場合 (`module` 文脈と実体文脈) は**スコープを分離したまま**扱う。
  1 本へ連結すると別スコープの宣言が混ざり、重複宣言と名前解決に偽の結果が出る。
  **文脈ごとに別の仮想ファイルへ割る**のを既定とし、3 つ以上の script や
  想定外の属性は不合格にする
- 診断の位置を元の `.svelte` へ**逆写像**できること (行だけでなく列も)
- `lang="ts"` の有無・属性の並びの扱いと、**扱えない書き方を不合格にする条件**
- 行・列を元ファイルと一致させる方式と、その一致を固定する検査
- 読み取り不能・構文不正のときに**無言で読み飛ばさない**こと

## 故障注入の一覧 (i12。probe の観測を継続的な赤へ移す)

実測は記録であって検査ではない。次の 6 つを**故障注入で赤くなること**まで固定する。

1. 除外根を空にする / 広げる → 除外根の件数 pin と「配下が全件構文で落ちる」検査が赤くなる
2. 派生除外の判定を常に真にする → 証人の無い派生が候補から消え、負の対照が赤くなる
3. 版管理下のファイル列挙を空にする → 「母集団が 0 件」で赤くなる
4. `.svelte` の仮想化を無効にする / module と実体の script を 1 本へ連結する →
   `.svelte` の中の見本候補が消える・偽の重複宣言が出る、で負の対照が赤くなる
5. 規則 2 の論理和から片方を落とす → その式だけが拾う見本が消え、負の対照が赤くなる
6. 申告の生死判定を「免除適用後」に変える → 自分自身を根拠にする申告の見本が通ってしまい赤くなる
7. 証人の資格を「任意の候補」へ緩める → 相互証人・循環証人の見本が消え、負の対照が赤くなる
8. 名前解決不能の分岐ラベルを候補から静かに落とす → 「名前解決不能で赤くなる」負例が緑になる

## スコープ外

- **PHP 側の発見の段**の作り替え (既に v3)
- 目録に登録した写しを見る**前向きの検査**に 4 種すべてを読ませること
  (正典 v3 は逆走査の候補の形を 4 種と定めるだけで、登録の受理範囲は定めない。
  型別名として切り出して登録する、が引き続き案内になる)
- `.svelte` への値集合の直書きを**禁止する**規則の新設 (正典 s2 が不変条件から外した)
- 未決論点 **q1** (テンプレート系の構築費) と **q3** (spirux の切り分け) — 他リポジトリの担当
- `packages/cli` の道具固有の符号 (`site_not_cli_capture` / `use_audits_submit` 等) の棚卸し。
  aicue に対応する controller は無いが、道具の挙動に関わるため本追従では触らない

## 再測定した実測ログ

# 実測ログ (probe.ts。設計時 2026-08-24)

判定式・母集団・派生除外 (証人は対応表キー以外の候補に限る) は概念設計の決着 1/2 と同じ形。
`excluded` = 構文を壊した見本 (`fixtures/candidates-broken/`) だけを母集団から外す。
`included` = その除外もしない (= 除外が要る理由の実測)。
集計は probe 内で `total === 各形の合計` を assert してある。

```
# mode=excluded
tracked .ts=379 .svelte=130
population .ts=378 .svelte=130
php resolved=123 unresolvable=3
program build ms=2953 sourceFiles=5859
derived(object-keys)=63 witnessed(excluded)=10 witnessless(kept)=53
broken syntax files=0 
candidates total=304 {"union":106,"object-keys":163,"switch-cases":13,"const-array":22}
hits total=8 {"1":6,"2b":1,"2a":1}
  [rule 1] app/Enums/Manual/CutType.php <-> resources/js/components/features/manual/ScenarioEditor.svelte:401::DragOwner (union) exact
  [rule 1] app/Enums/Notification/NotificationType.php <-> resources/js/components/features/notifications/NotificationListItem.svelte:67::switch:notification.type (switch-cases) exact
  [rule 1] app/Enums/ApiKeyAbility.php <-> resources/js/pages/Organizations/ApiKeys/Index.svelte:61::ABILITY_LABELS (object-keys) exact
  [rule 1] app/Enums/OAuth/OAuthClientKind.php <-> resources/js/pages/Organizations/ApiKeys/Sessions.svelte:41::CLIENT_KIND_LABELS (object-keys) exact
  [rule 1] app/Enums/Manual/TakeStatus.php <-> resources/js/types/manual.ts:409::SelectableTakeStatus (union) exact
  [rule 1] app/Enums/EnterpriseSso/OidcConnectionStatus.php <-> tests/js/components/features/sso/oidc-connection.test.ts:17::ALL_STATUSES (const-array) exact
  [rule 2a] app/Enums/ApiErrorCode.php <-> packages/cli/src/api/schemas.ts:310::ApiErrorCode (union) apierrorcode = apierrorcode
  [rule 2b] app/Enums/Manual/JobStatus.php <-> resources/js/types/dashboard.ts:10::DashboardJobStatus (union) words[job+statu] head=statu

# mode=included
tracked .ts=379 .svelte=130
population .ts=379 .svelte=130
php resolved=123 unresolvable=3
program build ms=3125 sourceFiles=5860
derived(object-keys)=63 witnessed(excluded)=10 witnessless(kept)=53
broken syntax files=1 tests/js/support/enum-ts-sync/fixtures/candidates-broken/broken.ts
candidates total=304 {"union":106,"object-keys":163,"switch-cases":13,"const-array":22}
hits total=8 {"1":6,"2b":1,"2a":1}
  [rule 1] app/Enums/Manual/CutType.php <-> resources/js/components/features/manual/ScenarioEditor.svelte:401::DragOwner (union) exact
  [rule 1] app/Enums/Notification/NotificationType.php <-> resources/js/components/features/notifications/NotificationListItem.svelte:67::switch:notification.type (switch-cases) exact
  [rule 1] app/Enums/ApiKeyAbility.php <-> resources/js/pages/Organizations/ApiKeys/Index.svelte:61::ABILITY_LABELS (object-keys) exact
  [rule 1] app/Enums/OAuth/OAuthClientKind.php <-> resources/js/pages/Organizations/ApiKeys/Sessions.svelte:41::CLIENT_KIND_LABELS (object-keys) exact
  [rule 1] app/Enums/Manual/TakeStatus.php <-> resources/js/types/manual.ts:409::SelectableTakeStatus (union) exact
  [rule 1] app/Enums/EnterpriseSso/OidcConnectionStatus.php <-> tests/js/components/features/sso/oidc-connection.test.ts:17::ALL_STATUSES (const-array) exact
  [rule 2a] app/Enums/ApiErrorCode.php <-> packages/cli/src/api/schemas.ts:310::ApiErrorCode (union) apierrorcode = apierrorcode
  [rule 2b] app/Enums/Manual/JobStatus.php <-> resources/js/types/dashboard.ts:10::DashboardJobStatus (union) words[job+statu] head=statu
```

---

残っている Critical / Warning があれば指摘してください。無ければ全体判定 APPROVED を出してください。

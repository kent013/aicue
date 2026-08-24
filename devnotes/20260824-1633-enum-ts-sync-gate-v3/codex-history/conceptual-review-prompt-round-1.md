## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【追加の文脈】
- 本件は「家系の機能台帳 (lctl) が確定した正典 v3 への追従」であり、採否の判断は済んでいる。正典の不変条件 i1〜i16 を aicue の現物に着地させる設計である。
- 対象は本番アプリのコードではなく、CI で走る静的検査 (gate) とその走査器である。したがって DTO/JsonResource は直接は関係しないが、AGENTS.md の「静的検査 (gate) と走査器の共通規約」(a)〜(e) と「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」が強く効く。
- 検出力を落とす方向の妥協 (見逃し) は不可、拾いすぎる方向は可、というのが正典と AGENTS.md 双方の原則である。

【正典 v3 の不変条件 (抜粋。判断の基準)】
- i5: TS 側の値集合の抽出は Program + TypeChecker で行う。program の起点は本番と同じ型世界に取り、速さのために起点を縮めない
- i6: .svelte は第一級の解析対象。script 範囲を切り出し、行番号を元ファイルと一致させた仮想 TS として同じ program に載せる。.svelte の中の写しも登録・検査・逆走査の対象になる
- i8: 逆走査のファイル母集団は版管理下の *.ts と *.svelte の全数。走査根の手書きの列挙に依存しない。母集団 0 件は不合格
- i9: 逆走査が拾う候補の形は 4 種 (リテラル型の合併 / 定数の配列 / 対応表のキー / 分岐のラベル)
- i10: 規則 1 = 値集合が完全一致する未登録の宣言。規則 2 = 名前が対応し値が交差する未登録の宣言で、判定式は「厳密な名前対応 + 1 値以上の交差」と「語に分けた名前対応 + 両側から見て半分以上の交差」の論理和
- i11: 逃がし口は許可一覧ではなく申告。理由必須・登録先の実在・重複無し・件数固定・候補でなくなった申告はそれ自体を不合格 (stale)。生死は免除を適用する前の状態で判定する
- i12: 母集団・受理範囲・免除のそれぞれに負の対照を置き、故障注入で赤くなることを実測する
- i13: 失敗メッセージは差分を双方向に列挙し、PHP 側と TS 側の両方の位置を出し、どちらが正本かを書き、直し方を出す
- i14: 読めない・実行できない・対象 0 件は合格ではなく不合格
- i15: 保証しない範囲を検査の冒頭で宣言する
- i16: 型情報の構築費は前処理へ移し、そこにだけ長い持ち時間を与える

【特に見てほしい論点】
- 決着 1 (見本置き場だけを母集団から外す) は i8「全数」の例外として正当化できているか。除外が静かな見逃しを作らないか
- 決着 2 (型に束縛された対応表を候補から外す) は検出力を落としていないか。「束縛先の型がそれ自体候補になる」という論拠に穴はないか
- 決着 3 (指紋台帳・採用時債務に載ったファイルを触らない着地) は、メッセージの語を保持するという制約に依存している。これは脆くないか
- 決着 4 (gate が見つけた実ドリフトを同じ変更で直す) はスコープとして妥当か
- 規則 2b の判定式 (語分割 + 頭の名詞一致 + 語数に応じた一致数 + 両側半分以上の交差) の具体化は詳細設計で行う。概念段階で決めておくべき穴はないか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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

- 母集団: `.ts` 348 本 (見本を除く) + `.svelte` 130 本。program 構築 **3.9 秒** / source files 5,831
- 候補 **199 件** (型の合併 87 / 対応表のキー 77 / 定数配列 22 / 分岐のラベル 13)
- 鳴った組 **8 件** (規則 1 = 6 / 規則 2a = 1 / 規則 2b = 1)

鳴った 8 件の内訳:

| # | 規則 | PHP | TS | 判定 |
|---|---|---|---|---|
| 1 | 2a | `app/Enums/ApiErrorCode.php` | `packages/cli/src/api/schemas.ts::ApiErrorCode` | **実ドリフト**。道具側は `rate_limit_exceeded` を持つがサーバは `rate_limited`。サーバ側の 4 値 (`insufficient_ability` / `actor_not_resolvable` / `idempotency_in_progress` / `idempotency_indeterminate`) が道具側に無い。当該ファイルの docblock 自身が「Mirrors `app/Enums/ApiErrorCode.php`」と書いている |
| 2 | 1 | `app/Enums/ApiKeyAbility.php` | `pages/Organizations/ApiKeys/Index.svelte::ABILITY_LABELS` | 独立した対応表 (`Record<string, string>`)。PHP 側の分類理由「管理画面はチェックボックスの選択状態だけを見る」が**事実と食い違っている** |
| 3 | 1 | `app/Enums/OAuth/OAuthClientKind.php` | `pages/Organizations/ApiKeys/Sessions.svelte::CLIENT_KIND_LABELS` | 同上。分類理由「認可ロジックの内部でのみ使う」が事実と食い違う |
| 4 | 1 | `app/Enums/Manual/CutType.php` | `features/manual/ScenarioEditor.svelte::DragOwner` | 別概念 (ドラッグの所有者) が偶然同値。申告 |
| 5 | 1 | `app/Enums/Notification/NotificationType.php` | `features/notifications/NotificationListItem.svelte` の分岐 | 登録済み型で束縛された分岐。既定の枝を持つ。申告 |
| 6 | 1 | `app/Enums/Manual/TakeStatus.php` | `types/manual.ts::SelectableTakeStatus` | 既存の申告 (継続) |
| 7 | 1 | `app/Enums/EnterpriseSso/OidcConnectionStatus.php` | `tests/js/.../oidc-connection.test.ts::ALL_STATUSES` | 検査側が並べた全値。申告 |
| 8 | 2b | `app/Enums/Manual/JobStatus.php` | `types/dashboard.ts::DashboardJobStatus` | 意図した真部分集合 (進行中のみ)。申告 |

**仮説は支持された**。広げた判定式は実ドリフトを 1 件見つけ、誤検出は申告 5 件で吸収できる。
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
   速さのために縮めない (実測 3.9 秒)
5. **規則 2 の論理和 (i10)**: 2a (厳密名対応 + 1 値交差) ∨ 2b (語分割名対応 + 両側半分以上の交差)
6. **申告の再整備 (i11)**: 広がった判定式に合わせて `REVERSE_SWEEP_EXEMPTIONS` を書き直す。
   理由 30 文字・実在・重複無し・件数 pin・**免除適用前**の stale 判定はすべて維持
7. **負の対照 (i12)**: 母集団・受理範囲・申告のそれぞれに負例を置き、故障注入で赤を実測する
8. **メッセージと宣言 (i13 / i15)**: PHP 側と TS 側の**両方の位置 (ファイル + 行)** を出す。
   docblock の保証範囲を書き換える

## 設計上の決着 (triage が挙げた衝突の処理)

### 決着 1: 見本 (fixtures) を母集団から外す — 「全数」の唯一の例外

`tests/js/support/enum-ts-sync/fixtures/candidates-broken/broken.ts` は
**わざと構文を壊した負の対照**である。i14 は「構文が壊れたファイルを無言で読み飛ばさない」ので、
このファイルが母集団に入ると**本番の gate が恒久的に赤**になる (実測: `mode=included` で
`broken syntax files=1`)。申告では逃がせない — 申告は「候補として鳴ったもの」を逃がす仕組みで、
「読めないファイル」の受け皿ではない。

**決着**: 母集団から外すのは**検出器自身の見本置き場だけ**とし、次の 4 条件で縛る。

1. 除外根は `tests/js/support/enum-ts-sync/` の配下に限る (構造で縛る。任意のパスを書けない)
2. 件数を pin する (増えても減っても赤)
3. 各除外根の**実在**を検査する (根の改名で無言に効かなくなるのを防ぐ)
4. 除外は docblock の保証範囲へ明記する

見本を母集団に入れても鳴る組は 8 件で変わらない (実測) ため、この除外で**検出力は落ちない**。

### 決着 2: 対応表のキーのうち「型に束縛されたもの」は候補にしない

`Record<VideoManualStatus, string>` のような対応表は、キーの過不足を `pnpm typecheck` が落とす。
値をその場で決めていない**派生**であり、独立した写しではない。実測で 115 件がこれに当たり、
そのまま候補にすると**申告が 100 件級の許可一覧に膨らむ** (i11 が禁じる形になる)。

**決着**: 対応表のキー形の候補は、次をすべて満たすとき**派生**として候補から外す。

- 明示の型 (注釈または `satisfies`) がある
- その型が**文字列の添字シグネチャを持たない** (= キーが有限の名前付き型に束縛されている)
- その型の**プロパティが 1 件以上あり、すべて必須** (`Partial<Record<…>>` は過不足を落とさないので
  派生と認めない)

検出力が落ちない根拠: 束縛先の型 (`VideoManualStatus` 等) は**それ自体が候補として拾われる**。
写しの実体は束縛先の側にあり、対応表はその複製ではない。
**定数の配列と分岐のラベルにはこの除外を適用しない** — 要素の型が名前付き型でも
「値を 1 つ書き忘れる」ことを型検査は落とさないためである。

### 決着 3: 乖離台帳に触らない着地を選ぶ

`tests/js/architecture/enum-ts-sync.test.ts` と `tsconfig.json` は
`docs/template-fingerprints.json` のキーであり、かつ
`tests/Support/TemplateDivergence/adoption-debt.tsv` に採用時ハッシュ付きで凍結されている。
触ると `TemplateDivergenceFingerprintTest` が `mutatedDebtPaths` で落ちるため、
「変更したまま債務に残す」は選べない。

**決着**: **どちらも変更しない着地を選ぶ**。

- `tsconfig.json` は変えない。`packages/cli` は **program の起点に足す**ことで型世界へ入れる
  (aigenba の `outsideTsconfig()` と同じ方式。tsconfig の `include` は本番のビルド設定であって
  gate の都合で広げるものではない)
- `tests/js/architecture/enum-ts-sync.test.ts` は変えない。そのため
  `createMirrorProgram(tsFiles)` の**呼び出し形を変えない**こと、
  `validateMirrors()` の既存の負の対照が照合する語 (`resources/js/ 配下だけ` 等) を
  **メッセージから消さない**ことを実装の制約として明記する
- **不可避になったときの退避路**: それでも触る必要が出たら、
  (3) 意図的逸脱として `docs/template-divergence.md` に登録し債務から削る、を選ぶ。
  そのとき `LedgerPins::DIVERGENCE_ENTRY_COUNT` (46→47) と `ADOPTION_DEBT_COUNT` (148→147) を
  同じ変更で直す。**債務に残したまま変更するのは選べない**

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
| `packages/cli/src/api/schemas.ts` / `client.ts` | 実ドリフトの是正 (決着 4) |
| `AGENTS.md` ドメイン固有規約 19 / `docs/architecture.md` | 受理する形と保証範囲の更新 |

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

## スコープ外

- **PHP 側の発見の段**の作り替え (既に v3)
- 目録に登録した写しを見る**前向きの検査**に 4 種すべてを読ませること
  (正典 v3 は逆走査の候補の形を 4 種と定めるだけで、登録の受理範囲は定めない。
  型別名として切り出して登録する、が引き続き案内になる)
- `.svelte` への値集合の直書きを**禁止する**規則の新設 (正典 s2 が不変条件から外した)
- 未決論点 **q1** (テンプレート系の構築費) と **q3** (spirux の切り分け) — 他リポジトリの担当
- `packages/cli` の道具固有の符号 (`site_not_cli_capture` / `use_audits_submit` 等) の棚卸し。
  aicue に対応する controller は無いが、道具の挙動に関わるため本追従では触らない

## 実測ログ (probe の生出力)

# 実測ログ (probe.ts。設計時 2026-08-24)

```
# mode=excluded
tracked .ts=379 .svelte=130
population .ts=348 .svelte=130
php resolved=123 unresolvable=3
program build ms=4299 sourceFiles=5831
broken syntax files=0 
derived(skipped object-keys)=115
candidates total=199 {"union":87,"object-keys":77,"switch-cases":13,"const-array":22}
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
program build ms=3015 sourceFiles=5860
broken syntax files=1 tests/js/support/enum-ts-sync/fixtures/candidates-broken/broken.ts
derived(skipped object-keys)=115
candidates total=218 {"union":106,"object-keys":77,"switch-cases":13,"const-array":22}
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

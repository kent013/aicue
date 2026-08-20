# アプリの使命と禁止事項 (AGENTS.md が正本)

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


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: あなたの役割

あなたは Laravel + Svelte アプリ (AI-CUE) の**実装レビュアー**である。TODO **T236**
「テンプレート乖離台帳を家系の正典 t1 へ追従する (指紋台帳・突合検査・掃除漏れ検出・fail-closed)」
の実装差分をレビューせよ。

## レビュー観点

1. **設計との一致性** — 詳細設計書のとおりに実装されているか。設計から外れた点は
   その理由がコード・コミット・登録簿のいずれかに書かれているか
2. **正確性** — 判定ロジックの穴。とくに「検査は緑なのに穴が開いている」形
   (fail-open / 空集合へ潰れる経路 / 母集団が空でも緑になる経路)
3. **静的検査 (gate) と走査器の共通規約 5 条**への準拠:
   (a) クラス参照は完全修飾名で突き合わせる (本設計は非該当のはず)
   (b) 解決できない形は落とす (fail-closed) / 母集団の非空を見る / 保証範囲を docblock へ明記
   (c) 検出力を負例で両方向に裏取りする
   (d) 集めた走査結果を判定に使わない形を作らない
   (e) 語彙一致の否定形はトークン完全一致 (本設計は非該当のはず)
4. **テスト網羅性** — 負例が本当に検出力を裏取りしているか。「本体を書いた後に通る入力」
   で作られた見せかけの負例になっていないか。dataset 名の件数と設計書の「N 形」が一致するか
5. **PHPStan 適合性と型安全** — ただし `phpstan.neon` の `paths` は
   `app` / `config` / `database` / `routes` であり、**本変更の新規ファイルは `tests/` と
   `scripts/` にあって解析対象外**である。したがって「PHPStan で解析済み」とは報告できない。
   型は人が書いて負例で担保する方針である。この区別が守られているかも見よ
6. **DTO / JsonResource パターン** — 本変更に HTTP 応答は無い (該当なしのはず)
7. **保証範囲の誇張** — docblock・登録簿・AGENTS.md が実装より広い保証を主張していないか。
   逆に、実装が持つ検出力を書き落としていないか。**正本が 1 か所に定まっているか**
   (同じ事実が 2 か所に書かれていたら必ず食い違う)
8. **DESIGN.md 準拠 / Atomic Design 準拠** — 本変更に `resources/js` / `resources/css` の
   変更は 1 件も無い (該当なし)

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に**全体判定**を `APPROVED` または `CHANGES_REQUESTED` の 1 語で書く

---

# user: レビュー対象

## 詳細設計書

**設計書は長大 (86KB) なので、次のファイルを直接読んでレビューに使うこと**
(あなたはファイル読み込みを許可されている):

- 詳細設計: `devnotes/20260821-0000-template-divergence-fingerprint-t1/detailed-design.md`
- 概念設計: `devnotes/20260821-0000-template-divergence-fingerprint-t1/conceptual-design.md`

設計書の要点 (S1〜S11 の施策一覧と受入条件) は詳細設計書の冒頭と末尾にある。

## 実装の全体像

家系 (laravel-claude-template から生成された兄弟リポジトリ群) の正典 t1 を
**role: app 側 (テンプレートの受け手)** へ持ち込んだ。これまで逸脱の登録簿
`docs/template-divergence.md` は**形式**しか機械検査されておらず、
「共有ファイルを変えたのに登録を書かなかった」という**登録漏れそのものは検出できなかった**
(AGENTS.md にも「実体との突合は台帳リポジトリの巡回が行う」と書いてあった)。
本変更はその穴を塞ぐ。

落とすのは 2 つ:
- **(3a)** テンプレートと内容が食い違っているのに、逸脱の登録も採用時債務の記載も無いパス
- **(3b)** 内容がテンプレート準拠へ戻ったのに、逸脱の登録が残っているパス

**母集合の決め方が正典と違う**のが最大の設計判断である。正典 (提供元) は自分の
`git ls-files` を 22KB の規則表 `SharedPathRules` で分類して母集合を作るが、受け手側は
テンプレートの現物を CI に持てない。そこで**正典が公開する指紋台帳
(`docs/template-fingerprints.json`) のキー ∩ 自リポジトリの追跡ファイル**を母集合にした。
規則表は持ち込んでいない (使われない資産になるため)。この差は登録簿の **D33** に登録した。

**採用時債務 (adoption debt)** が 2 つ目の設計判断である。採用時点で 178 パスが
「食い違っているのに登録が無い」状態だった。これを一気に登録簿へ書くのは
「作業量を理由にした逸脱」になり登録簿の原則に反する。かわりに
`tests/Support/TemplateDivergence/adoption-debt.tsv` へ**採用時のアプリ側 sha256 付きで凍結**し、
登録簿の **D34** (`監視中` + 見直し期限 2027-02-28) で期限付きに管理する。
**ハッシュを持つことが要点**で、パスだけを持つ形は「そのパスは食い違っていればいつでも合格」
= 恒久的な許可一覧になってしまう。ハッシュがあれば「採用時の姿のまま」と
「採用後に手を入れた」を区別でき、後者は `mutatedDebtPaths` として落とせる。

## 実測値 (生成器の出力で確定した pin)

```
正典の指紋台帳: 947 キー (laravel-claude-template@0597a0c2… の docs/template-fingerprints.json)
  sha256 = 0c9add21dc79429f6d80e38cfeb95736af750bd760ee9584d2e2b8a1285c0c90 (128420 バイト)
  generated_at_commit = a078806b0574518ddc64966f60f7d536b1338b2f

LedgerPins::FINGERPRINT_POPULATION_COUNT = 281
LedgerPins::ADOPTION_DEBT_COUNT          = 174
LedgerPins::DIVERGENCE_ENTRY_COUNT       = 33

整合式: 281 = 78 (一致) + 29 (相違かつ登録済み) + 174 (債務)
```

C2 の時点では 32 / 176 で、C3 (スキル 2 本の編集 + D35 の登録) で 33 / 174 へ動いた。
スキル 2 本は債務一覧にあったので、編集すると必ず `mutatedDebtPaths` になる。
債務モデルは「変更したまま債務に残す」を許さないので、C3 は
**「登録を書いて債務から削る」**を選んだ (D35)。

## コミット構成

- **C1** `chore: T236 C1 composer.json の識別子を aicue へ反転する` (S1)
- **C2** `feat: T236 C2 テンプレート乖離の突合を指紋台帳で機械化する` (S2〜S10)
- **C3** (未コミット。本レビュー後にコミットする) S11 + D35

## 設計から外れた点 (自己申告。ここを重点的に見てほしい)

1. **`composer.lock` の content-hash が変わった**。詳細設計 S1 は「`name` は
   content-hash 対象外なので lock に差分は出ない」と予測していたが、Composer 2 の
   content-hash は `name` を入力に含むため実際には差分が出た。`composer update --lock`
   で hash だけ更新した (パッケージ版は 1 つも動いていない)。`composer.json` /
   `composer.lock` はどちらも正典の指紋台帳のキーではないので逸脱の登録は不要である。

2. **`FingerprintLedger` の正典からの差が「1 行」ではなく 2 点になった**。
   詳細設計 S2 は「`SharedPathRules::isValidRepoRelativePath()` を
   `RepoRelativePath::isValid()` へ差し替える 1 点」と書いていたが、同じ設計書の
   負例一覧 (11 形) が **「`entries` が object でない (`[]` を含む)」を落とすこと**を
   要求している。正典の実装は連想配列で解釈する (`json_decode($json, true)`) ため
   `{"entries": []}` と `{"entries": {}}` を区別できず、この負例を満たせない。
   そこで解釈を object 形 (`json_decode($json, false)`) にした。過剰検出寄りの上積みであり、
   D33 の観点表に「指紋台帳の解釈」の行として登録した。
   **設計書の「1 行」という記述と負例一覧が互いに矛盾していたので、負例一覧 (テストが
   通らなければならない側) を正とした**。この判断は妥当か。

3. **`GenerationRefused` という 20 本目の PHP ファイルを足した**。詳細設計の
   ファイル一覧表は新規 PHP 19 本だが、同じ設計書の S4 が
   「『ガードによる拒否』(exit 3) と『context DTO の入力違反』(exit 1) は
   **別の例外型**で区別する (`GenerationRefused` / `RuntimeException`)」と要求している。
   1 クラス 1 ファイルの規約に従い独立したファイルにした (表の数え落ちと解釈した)。

4. **生成器スクリプトに `#!/usr/bin/env php` を付けなかった**。正典の同名スクリプトは
   持っているが、本リポジトリの `StrictTypesDeclarationGateTest` は
   **開始タグより前のトークン (shebang = T_INLINE_HTML) があると「未宣言」と判定して落とす**
   (走査器 `StrictTypesDeclarationScanner` の docblock に明記されている挙動)。
   既存の `scripts/ci/*.php` も shebang を持たない。D33 の観点表に行として登録した。

5. **`AppFingerprintBuilder` に「初回生成 = 採用 (seeding)」の分岐を足した**。
   詳細設計 S3 の債務の更新規則は「追加は原則として例外。許すのは前世代の台帳に
   そのパスがあり、現在のアプリ側ハッシュが載せ替え前の正典ハッシュと一致するときだけ」
   と書いているが、この規則だけでは**最初の 174 件をどうやって作るのか**が書かれていない
   (前世代の台帳が無いので追加が常に拒否され、初回生成が不可能になる)。
   「採用時債務」という名前どおり、**前世代の台帳が無い初回生成は「採用」であり、
   未登録の相違をその時点のハッシュで凍結する**と解釈した。
   この分岐が**債務一覧を作り直せる抜け道**になることは
   `AppFingerprintBuilder` の docblock の「保証しないもの」に明記した
   (アプリ側の指紋台帳を消してから生成器を走らせると採用がやり直しになる。
   件数 pin の差分と PR レビューでしか止まらない)。
   **この抜け道の扱いは妥当か。もっと良い塞ぎ方があるか。**

6. **`FingerprintReconciler` に 2 つの fail-closed 検査を足した** (設計書に無い):
   観測の集合が母集合とちょうど一致しない場合と、観測の比較状態が
   テンプレート側ハッシュとの実際の関係と矛盾する場合 (「一致と称しているのに
   ハッシュが違う」等) を例外にする。取り違えを黙って通さないための上積みで、
   両方向の負例を置いた。

7. **`FingerprintLedger::matchesIgnoringGeneratedCommit()` を移植したまま残した**。
   role: app 側では鮮度比較を使わないので**呼び出し元が無い**。設計が
   「差し替えは 1 点」と定めた方針に従って残したが、思考原則 2
   (今必要なものだけ作る) との緊張がある。単体テストで挙動を固定してはある。
   **消すべきか残すべきか意見が欲しい。**

## 検証結果

C2 時点 (10 本すべて green):
```
composer test              : 6296 tests, 6294 passed, 0 failed, 2 skipped, 5 risky (30171 assertions)
composer phpstan           : No errors (level 10 / 1004 files)
vendor/bin/pint --test     : passed
pnpm lint                  : passed
pnpm typecheck             : passed
pnpm test                  : 169 files, 2283 tests passed
pnpm build                 : built
pnpm typecheck:packages    : passed
pnpm build:packages        : passed
pnpm test:packages         : 10 files, 106 tests passed
```
C3 時点の静的検査 8 本は green を確認済み。`composer test` / `pnpm test` /
`pnpm test:packages` は本レビューと並行して再実行中である
(結果はコミット前に確認する)。

テストファーストの実測: `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php`
を本体より先に書き、クラスが存在しないため Pest が `DatasetMissing` で落ちる赤を確認した。
その後 `tests/Architecture/TemplateDivergenceFingerprintTest.php` の F12 で
**`expect()->toContain($needle, $message)` の第 2 引数がメッセージではなく
2 つ目の needle として扱われる**という実際のバグを踏み、テストが落ちたので修正した
(Pest の `toContain()` は可変長引数でメッセージを受け取らない)。

生成器の実測: 初回生成で 281 / 176 / 32、同じ入力での再実行は**両生成物が byte 一致
(冪等) かつ債務追加 0 件**、D35 の登録後の再生成で 281 / 174 / 33 となり
債務一覧からちょうど 2 行が消えた。

## 実装差分 (git diff。生成物 2 本は除く)

生成物 2 本は差分から除いてある:
- `docs/template-fingerprints.json` (288 行 / 281 エントリ。値は**正典側の** sha256)
- `tests/Support/TemplateDivergence/adoption-debt.tsv` (175 行 = ヘッダ 1 + 債務 174)
必要なら実ファイルを読んでよい。

```diff
diff --git a/.claude/skills/app-design/SKILL.md b/.claude/skills/app-design/SKILL.md
index b5acefcf..9861730f 100644
--- a/.claude/skills/app-design/SKILL.md
+++ b/.claude/skills/app-design/SKILL.md
@@ -352,6 +352,21 @@ ### 2-5. 最終確認
 
 ## Phase 3: 完了報告 & TODO登録案内
 
+### 3-0. 乖離台帳の確認段 (必須)
+
+詳細設計に**テンプレートと共有するファイル**の変更が含まれるかを確認する。
+共有ファイルかどうかは `docs/template-fingerprints.json` のキーに**そのパスが在るか**で決まる。
+
+- 在る場合: `docs/template-divergence.md` への登録の追加 (または削除) と、
+  `tests/Support/TemplateDivergence/LedgerPins.php` の件数の更新を**施策として明記する**
+- 採用時債務一覧 (`tests/Support/TemplateDivergence/adoption-debt.tsv`) に在るパスなら、
+  **「変更したまま債務に残す」は選べない** (突合 gate が `mutatedDebtPaths` で落とす)。
+  次の 3 つから選んで設計に書く —
+  (1) 内容を採用時の姿へ戻す / (2) テンプレートへ同期して債務から削る /
+  (3) 意図的逸脱として登録を書き債務から削る
+- 在らない場合も、テンプレートに無い領域への上積みなら
+  「登録するか迷ったら登録する」(登録簿の記録の原則) に従う
+
 ### 3-1. 最終報告
 
 ```
diff --git a/.claude/skills/app-implement/SKILL.md b/.claude/skills/app-implement/SKILL.md
index a4f5d674..b27d483d 100644
--- a/.claude/skills/app-implement/SKILL.md
+++ b/.claude/skills/app-implement/SKILL.md
@@ -230,6 +230,16 @@ ## Phase B: コミット（worktree内）
 
 ### B-1. コミット（worktree内）
 
+コミット前に**乖離台帳の確認段**を通す: 共有ファイル
+(`docs/template-fingerprints.json` のキー) を変えたなら、`docs/template-divergence.md` の
+登録と `tests/Support/TemplateDivergence/LedgerPins.php` の件数を**同じコミットに含める**。
+stage は**変更したファイルを個別に指定する** (`git add docs/template-divergence.md
+tests/Support/TemplateDivergence/LedgerPins.php` のように。ディレクトリ単位の
+`git add docs/` は無関係な変更まで巻き込むので書かない)。
+突合 gate が赤いときに**指紋台帳や債務一覧を書き換えて黙らせない** (登録を書くか内容を戻す)。
+採用時債務一覧に在るファイルを変えた場合は、3 択 (採用時の姿へ戻す / テンプレートへ同期して
+債務から削る / 登録を書いて債務から削る) のどれを採ったかをコミットメッセージに書く。
+
 Phase Aの実装・テスト変更をまとめてコミットする。
 
 ```bash
diff --git a/AGENTS.md b/AGENTS.md
index 8770effa..46abdd3e 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -566,6 +566,21 @@ ## テンプレートとの関係
 (登録メタ表の 9 行・状態の値域・対象パスの実在と重複・件数の 3 点一致)。
 書式の中身は本書に写さない (2 か所に書くと必ず食い違う)。
 
+**実体との突合は `tests/Architecture/TemplateDivergenceFingerprintTest.php` が持つ**
+(家系の正典 t1)。指紋台帳 `docs/template-fingerprints.json` (テンプレート側の内容の sha256) と
+実ファイルを突き合わせ、食い違いに登録が無い場合と、内容が一致へ戻ったのに登録が残っている
+場合を落とす。母集合は正典の指紋台帳のキーを起点に生成し、**採用後にローカルで消しても
+既存のキーは母集合から外れない** (正典側から消えたときだけ外れる)。
+**生成規則の正本は `AppFingerprintBuilder` の docblock** である。
+共有ファイルを変えたら**同じ変更で**登録を足す (または戻す)。件数の pin は
+`tests/Support/TemplateDivergence/LedgerPins.php` の 3 定数に集約してある。
+採用時点で説明が無い食い違いは `tests/Support/TemplateDivergence/adoption-debt.tsv` に
+**採用時のアプリ側 sha256 つきで**凍結して列挙してある (D34。期限付きで縮める)。
+検出するのは**テンプレートと一致していた状態から新たに不一致になった、未登録かつ
+非債務のパス**と、**債務パスが採用時の姿から変わったこと**である。
+突合 gate が赤いときに**指紋台帳や債務一覧を書き換えて黙らせない** (登録を書くか内容を戻す)。
+**保証しないものの正本は突合 gate の docblock** であり、本書に写さない。
+
 ## ドメイン固有規約
 
 <!-- TEMPLATE-MARKER: アプリ固有の規約 (ドメインモデルの不変条件、外部 API、
diff --git a/composer.json b/composer.json
index ec757322..4cb863c8 100644
--- a/composer.json
+++ b/composer.json
@@ -1,6 +1,6 @@
 {
     "$schema": "https://getcomposer.org/schema.json",
-    "name": "rio-development/laravel-claude-template",
+    "name": "rio-development/aicue",
     "type": "project",
     "description": "Laravel + Svelte SaaS template for LLM-driven development",
     "keywords": [
diff --git a/composer.lock b/composer.lock
index f900f60a..5fd5e943 100644
--- a/composer.lock
+++ b/composer.lock
@@ -4,7 +4,7 @@
         "Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies",
         "This file is @generated automatically"
     ],
-    "content-hash": "761402cba74edf8b0f94586c4ac43063",
+    "content-hash": "85e724b7256bd8c371986eced49a0623",
     "packages": [
         {
             "name": "aws/aws-crt-php",
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index f5dd4272..9751cd3a 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 30 件
+登録エントリ: 33 件
 
 ## 記録の原則
 
@@ -58,12 +58,17 @@ ## 見直し期限が切れたときの直し方 (4 通り)
 
 ## この登録簿が保証しないもの
 
-- 実ファイルがテンプレートから逸脱したのに登録が無いこと (登録漏れそのもの) は検出できない。
-  実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)
-- 内容としてテンプレート準拠へ戻したのにファイルが残っている登録も検出できない
 - 登録の中身が正しいことは機械では見ない (空でないこと・値域に収まっていることだけを見る)
 - **削除した番号の再利用**は検出できない (使用済み番号の履歴を持たないため。
   再利用しないことは人が守る規約である)
+- **実体との突合は別の検査が持つ** —
+  `tests/Architecture/TemplateDivergenceFingerprintTest.php` が指紋台帳
+  (`docs/template-fingerprints.json`) と実ファイルを突き合わせ、食い違いに登録が無い場合と、
+  内容が一致へ戻ったのに登録が残っている場合を落とす。
+  **形式検査 (`TemplateDivergenceLedgerFormatTest`) 自身は突合を持たない**
+- **突合が保証しない範囲の正本は突合検査の docblock である** (ここには写さない。
+  2 か所に書くと必ず食い違う)。突合が見ない範囲 (母集合の外・ファイル内部の逸脱・
+  追従遅れ・採用時債務の分類) は台帳リポジトリの巡回が引き続き担う (家系の裁定 AG-159)
 
 ## エントリ形式
 
@@ -1932,3 +1937,169 @@ ### 関連
 - 実装: `tests/Support/Cache/` / `tests/TestCase.php` / `tests/Pest.php` /
   `tests/Architecture/CachePayloadPlainDataGateTest.php`
 - 設計: `devnotes/20260818-1757-cache-runtime-plain-data-guard/`
+
+---
+
+## D33 テンプレート乖離の突合を、正典の分類規則ではなく公開された指紋台帳のキーで行う
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `docs/template-fingerprints.json` / `tests/Architecture/TemplateDivergenceFingerprintTest.php` / `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` / `tests/Support/TemplateDivergence/DivergenceLedgerParser.php` / `tests/Support/TemplateDivergence/FingerprintLedger.php` / `tests/Support/TemplateDivergence/AtomicLedgerWriter.php` / `scripts/update-template-fingerprints.php` |
+| 業務要件起因の説明 | テンプレートの現物を CI に持てないため、母集合を正典の分類規則ではなく正典が公開する指紋台帳のキーで決める。突合は本アプリの登録簿が許す複数の対象パスに合わせて実装する |
+| 揃え続ける不変条件と保証機構 | 3a / 3b の集合等式と fail-closed の 4 規約を保つ (`TemplateDivergenceFingerprintTest` / `TemplateDivergenceFingerprintRulesTest` / `TemplateFingerprintGeneratorTest`) |
+| 再判定の条件 | 正典が母集合の決め方・schema・パス検証の判定を変えたとき / テンプレートの現物を CI で引ける手段ができたとき |
+| 決めた日 | 2026-08-20 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260821-0000-template-divergence-fingerprint-t1/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 母集合の出典 | 自リポジトリの `git ls-files` を `SharedPathRules` (22KB の規則表) で分類する | 正典が公開する指紋台帳のキー ∩ 自リポジトリの追跡ファイル (規則表は持ち込まない) |
+| パスの書式判定 | `SharedPathRules::isValidRepoRelativePath()` | `RepoRelativePath::isValid()` (書式判定だけを切り出した 1 クラス) |
+| 指紋台帳の解釈 | 連想配列で解釈する | object 形で解釈し、空配列と空 object を型で区別する |
+| 正本の正準形 | 検査しない | 正本のバイト列が解釈して直列化し直した結果と完全一致することを要求する (重複キー・整形の崩れを落とす) |
+| 突合の DTO | 対象パスを 1 件だけ持つ `DivergenceEntry` | 対象パスの複数指定を許す本アプリの解析結果をそのまま使う |
+| 生成器の起動 | 提供元で走らせ、子アプリでは role ガードが拒否する | 受け手側で走らせ、入力の正典台帳を `--template-ledger` で渡す (既存台帳が `role: template` なら拒否) |
+| 生成物 | 指紋台帳 1 本 | 指紋台帳 + 採用時債務一覧の 2 本 (平文は `AtomicTextWriter` が書く) |
+| 生成器の先頭行 | `#!/usr/bin/env php` を持つ | 持たない (`StrictTypesDeclarationGateTest` が開始タグより前のトークンを未宣言として落とすため) |
+
+### なぜ正当な差分か (logic-driven)
+
+本リポジトリは**テンプレートの受け手**であり、テンプレートの現物 (working tree) を CI に持てない。
+正典の突合はテンプレート側の分類規則を自分で走らせて母集合を決めるが、受け手側で同じ規則表を
+持つと「使われない 22KB の資産」が増えるだけで不変条件は 1 つも増えない (思考原則 2)。
+そこで**母集合の出典を正典が公開する指紋台帳のキーに置き換えた**。
+正典自身が「検査の本数・クラス名・ファイル配置は不変条件に含めない」と定めているため、
+同じ等式を本リポジトリのモデル (対象パスの複数指定を許す解析器) で実装している。
+
+解釈を object 形にしたのと正準形バイト一致を要求したのは、どちらも**過剰検出寄りへの上積み**である。
+連想配列で解釈すると `{"entries": []}` のような空配列が空 object と区別できず、
+`json_decode` は重複キーを後勝ちで潰すため、どちらも「母集合が黙って空になる」経路になる。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「テンプレートと共有するファイルが食い違ったなら、登録簿の登録か採用時債務の記載が必ずある」
+
+- 集合等式 1 本で両方向 (3a = 未登録の食い違い / 3b = 一致へ戻ったのに残る登録) を落とす
+- 読み取り失敗・解釈不能・git の失敗・母集合 0 件・検査不能はすべて不合格にする (fail-closed)
+- 本機構自身のファイルが母集合に残り regular file であることを必須メンバ pin が固定する
+
+### 保証しないもの
+
+- 保証しないものの正本は `tests/Architecture/TemplateDivergenceFingerprintTest.php` の
+  docblock である (本書と `AGENTS.md` には写さない)
+
+### 関連
+
+- 実装: `tests/Support/TemplateDivergence/` / `tests/Architecture/TemplateDivergenceFingerprintTest.php` /
+  `scripts/update-template-fingerprints.php`
+- 設計: `devnotes/20260821-0000-template-divergence-fingerprint-t1/`
+
+---
+
+## D34 採用時点で説明の無い食い違いを、採用時ハッシュ付きで凍結する層を持つ
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Support/TemplateDivergence/adoption-debt.tsv` / `tests/Support/TemplateDivergence/AdoptionDebtInventory.php` |
+| 業務要件起因の説明 | テンプレートの現物が CI に無いため、採用時点で食い違っていたファイル (174 件) が意図的逸脱なのか追従遅れなのかを機械では区別できない。区別が付くまで採用時の姿を凍結して扱う層を持つ |
+| 揃え続ける不変条件と保証機構 | 債務パスは採用時のアプリ側ハッシュのまま留まること。変えたら `mutatedDebtPaths`、テンプレート一致へ戻ったら `resolvedDebtPaths` が落とす (`TemplateDivergenceFingerprintTest` の F10 / F11) |
+| 再判定の条件 | 一覧が 0 件になったとき (一覧ファイルと本登録を同じ変更で消す) / テンプレート更新の一括取り込みを行うとき / 債務パスの分類が付いたとき |
+| 決めた日 | 2026-08-20 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260821-0000-template-divergence-fingerprint-t1/ |
+| 状態 | 監視中 |
+| 見直し期限 | 2027-02-28 |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 未分類の食い違い | 存在しない (提供元なので食い違いの概念が無い) | 採用時債務一覧に採用時のアプリ側 sha256 付きで凍結する |
+| 凍結の粒度 | — | パス 1 件 + そのときのアプリ側ハッシュ (パスだけを持つ形は恒久的な許可一覧になってしまう) |
+| 一覧が縮む契機 | — | 内容をテンプレートへ戻す / 意図的逸脱として登録簿へ書く の 2 つだけ |
+| 期限の管理 | — | 本登録の状態を `監視中` にし、見直し期限切れを CI の赤で強制する |
+
+### なぜ正当な差分か (logic-driven)
+
+**本登録は「未分類の債務をまとめて正当化する登録」ではない。**
+本書の冒頭は「互換・UX・**作業量**を理由にした逸脱は記録せず是正する」と定めており、
+「件数が多くて書くのが大変だから」は逸脱の理由になり得ない。
+本登録が登録するのは**未分類の債務を期限付きで管理する安全機構を持つこと**そのものであり、
+その業務要件起因は「テンプレートの現物が CI に無く、意図的逸脱と追従遅れを機械で区別できない」
+ことである。**分類を先送りする言い訳ではなく、先送りを期限付きで可視化する装置**として登録する
+(期限切れは CI の赤 = 是正の強制)。
+
+一覧が**採用時のアプリ側ハッシュを持つ**ことが要点である。パスだけを持つと
+「そのパスは食い違っていればいつでも合格」になり、凍結された観測ではなく
+**そのパスに対する恒久的な許可一覧**になってしまう。ハッシュを持てば
+「採用時の姿のまま」と「採用後に手を入れた」を区別でき、後者は違反として落とせる。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「採用時債務に載っているパスは、採用時の姿のまま留まっている」
+
+- 採用時の姿から変わったら `mutatedDebtPaths` が落とす (登録を書くか、戻すか、同期する)
+- テンプレート一致へ戻ったら `resolvedDebtPaths` が落とす (一覧から削れという指示になる)
+- 件数は `LedgerPins::ADOPTION_DEBT_COUNT` と完全一致で pin する (増減のどちらでも赤になる)
+- 2 生成物の世代が食い違ったら F14 が落とす (片方だけ更新された状態を緑にしない)
+
+### 保証しないもの
+
+- 保証しないものの正本は `tests/Support/TemplateDivergence/AdoptionDebtInventory.php` と
+  `tests/Architecture/TemplateDivergenceFingerprintTest.php` の docblock である
+  (本書には写さない)
+
+### 関連
+
+- 実装: `tests/Support/TemplateDivergence/AdoptionDebtInventory.php` /
+  `tests/Support/TemplateDivergence/adoption-debt.tsv`
+- 設計: `devnotes/20260821-0000-template-divergence-fingerprint-t1/`
+
+---
+
+## D35 設計・実装スキルに乖離台帳の確認段とアプリ固有の手順を持たせる
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `.claude/skills/app-design/SKILL.md` / `.claude/skills/app-implement/SKILL.md` |
+| 業務要件起因の説明 | 本アプリの設計・実装スキルは、乖離台帳の確認段と bug-hunt 等のアプリ固有の手順を持つためひな形と異なる。共有ファイルを変えた変更が登録を伴わずにコミットされると突合 gate が赤くなるので、登録の契機を人手の出口に置く必要がある |
+| 揃え続ける不変条件と保証機構 | 共有ファイルを変えたら登録を同じコミットで足す手順を出口に持つこと (突合 gate `TemplateDivergenceFingerprintTest` が登録漏れを赤にすることで手順の実効を担保する) |
+| 再判定の条件 | 登録の契機を機械強制へ移せたとき (指紋台帳のキーとの突合を pre-commit で行う手段ができたとき) / スキルの構成をひな形へ戻すとき |
+| 決めた日 | 2026-08-20 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260821-0000-template-divergence-fingerprint-t1/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 設計スキルの完了段 | 完了報告と TODO 登録の案内だけ | その前に乖離台帳の確認段を持ち、共有ファイルの変更を施策として明記させる |
+| 実装スキルのコミット段 | 変更をまとめてコミットする | コミット前に登録と件数 pin を同じコミットへ含めることを確認させる |
+| 債務一覧に在るパスの扱い | 概念が無い | 「変更したまま残す」を選べないことと 3 択を明示する |
+
+### なぜ正当な差分か (logic-driven)
+
+突合 gate は**登録漏れを検出する**が、**登録を書かせることはできない**。
+検出だけを足すと「CI が赤くなってから登録を書く」流れになり、赤を消す最短経路として
+**指紋台帳や債務一覧を書き換える**誘惑が生まれる (これは検査を書き換えるのと等価である)。
+そこで登録の契機を、設計の完了段と実装のコミット段という**人手の出口 2 か所**に置いた。
+テンプレートのスキルはこの段を持たないので、本アプリのスキルはひな形から外れる。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「共有ファイルを変えた変更は、同じコミットに登録と件数 pin の更新を含む」
+
+- 手順が守られなかった場合は突合 gate が 3a で赤くなる (手順の実効はここが担保する)
+- 債務一覧に在るパスを触った場合は `mutatedDebtPaths` が赤くなる
+
+### 保証しないもの
+
+- **確認段は人手の層であり機械強制ではない**。家系の正典が持つ「role で分岐する二役の書き方」
+  への完全な到達は主張しない
+- スキル文書を読まずに実装した場合は何も止まらない (止まるのは突合 gate の側である)
+
+### 関連
+
+- 実装: `.claude/skills/app-design/SKILL.md` / `.claude/skills/app-implement/SKILL.md`
+- 設計: `devnotes/20260821-0000-template-divergence-fingerprint-t1/`
diff --git a/scripts/update-template-fingerprints.php b/scripts/update-template-fingerprints.php
new file mode 100644
index 00000000..684fd282
--- /dev/null
+++ b/scripts/update-template-fingerprints.php
@@ -0,0 +1,233 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * scripts/update-template-fingerprints.php — アプリ側の指紋台帳
+ * docs/template-fingerprints.json と採用時債務一覧
+ * tests/Support/TemplateDivergence/adoption-debt.tsv の生成器
+ * (家系の裁定 AG-110 の標準形 t1 を role: app 側へ持ち込んだもの)。
+ *
+ * 使い方:
+ *   php scripts/update-template-fingerprints.php --template-ledger=<path> [--adopt-new-template-ledger]
+ *
+ * `--template-ledger` には**正典 (laravel-claude-template) の指紋台帳をそのまま保存した
+ * ファイル**を渡す。取得は機能台帳 lctl の get_source
+ * (project: laravel-claude-template / path: docs/template-fingerprints.json) で行い、
+ * 一時ファイルへ置く (コミットしない)。既定ではそのファイルの sha256 が
+ * `LedgerPins::TEMPLATE_LEDGER_SOURCE_SHA256` と一致することを要求する。
+ * 台帳を新しい世代へ載せ替えるときだけ `--adopt-new-template-ledger` を明示し、
+ * 標準出力に出る新しい pin 値を `LedgerPins` へ書き写す。
+ *
+ * **CI では走らせない**。共有ファイルを変えたときに逸脱を登録するのは人の作業であり、
+ * 本スクリプトは台帳の載せ替え時にだけ使う。
+ *
+ * 判定ロジックは持たず tests/Support/TemplateDivergence の純粋クラスへ委譲する
+ * (規則の正本を 1 箇所にするため。突合 gate と同じ実装を使う)。
+ * root は dirname(__DIR__) 固定で、**差し替える隠しオプションは作らない**
+ * (テストは service を一時ディレクトリの root で直接呼ぶ)。
+ *
+ * 終了コード規約 (scripts/bug-hunt-inventory-check.sh の 0 / 3 規約と同型):
+ *   0 = 生成成功 / 3 = ガードによる拒否 / 1 = 実行不能
+ * 拒否と実行不能は**例外の型**で区別する (GenerationRefused / RuntimeException)。
+ * 3 と、書き込み開始前の 1 では**生成物を 1 バイトも変えない**。
+ */
+
+use Tests\Support\TemplateDivergence\AdoptionDebtInventory;
+use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
+use Tests\Support\TemplateDivergence\FingerprintGenerationContext;
+use Tests\Support\TemplateDivergence\FingerprintGenerationService;
+use Tests\Support\TemplateDivergence\FingerprintLedger;
+use Tests\Support\TemplateDivergence\GenerationRefused;
+use Tests\Support\TemplateDivergence\LedgerPins;
+use Tests\Support\TemplateDivergence\LedgerRole;
+use Tests\Support\TemplateDivergence\TrackedRepositoryFiles;
+
+$root = dirname(__DIR__);
+
+$fail = static function (string $message): never {
+    fwrite(STDERR, 'error: '.$message."\n");
+    exit(1);
+};
+
+$autoload = $root.'/vendor/autoload.php';
+if (! is_file($autoload)) {
+    fwrite(STDERR, "error: vendor/autoload.php が無い。`composer install` を dev 依存込みで実行すること\n");
+    exit(1);
+}
+
+require $autoload;
+
+if (! class_exists(FingerprintGenerationService::class)) {
+    fwrite(STDERR, 'error: Tests\\Support\\TemplateDivergence が autoload されていない。'
+        ."`composer install` を dev 依存込みで実行すること (autoload-dev が要る)\n");
+    exit(1);
+}
+
+// --- 引数解析 (未知・重複・欠落はすべて実行不能) ---
+$templateLedgerPath = null;
+$adoptNewTemplateLedger = false;
+foreach (array_slice($argv, 1) as $argument) {
+    if (str_starts_with($argument, '--template-ledger=')) {
+        if ($templateLedgerPath !== null) {
+            $fail('--template-ledger が 2 回指定されている');
+        }
+        $templateLedgerPath = substr($argument, strlen('--template-ledger='));
+        if ($templateLedgerPath === '') {
+            $fail('--template-ledger の値が空である');
+        }
+
+        continue;
+    }
+    if ($argument === '--adopt-new-template-ledger') {
+        if ($adoptNewTemplateLedger) {
+            $fail('--adopt-new-template-ledger が 2 回指定されている');
+        }
+        $adoptNewTemplateLedger = true;
+
+        continue;
+    }
+
+    $fail("未知の引数である: {$argument}");
+}
+
+if ($templateLedgerPath === null) {
+    $fail('--template-ledger=<path> が要る (正典の指紋台帳を保存したファイル)');
+}
+
+$templateLedgerRaw = is_file($templateLedgerPath) && ! is_link($templateLedgerPath)
+    ? file_get_contents($templateLedgerPath)
+    : false;
+if ($templateLedgerRaw === false) {
+    $fail("正典の指紋台帳を読めない: {$templateLedgerPath}");
+}
+
+// --- role ガード (最も現実的な無効化経路を正規経路の側で塞ぐ) ---
+// 既存のアプリ側台帳が role: template なら、子アプリで正典側の生成を走らせている。
+// これは逸脱検出そのものを消すので**拒否 (3)** で止める。
+$previousLedger = null;
+$fingerprintPath = $root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH;
+if (is_file($fingerprintPath)) {
+    $existingRaw = file_get_contents($fingerprintPath);
+    if ($existingRaw === false) {
+        $fail("既存の指紋台帳を読めない: {$fingerprintPath}");
+    }
+
+    try {
+        $previousLedger = FingerprintLedger::fromJson($existingRaw);
+    } catch (RuntimeException $e) {
+        $fail('既存の指紋台帳を解釈できない: '.$e->getMessage());
+    }
+
+    if ($previousLedger->role !== LedgerRole::App) {
+        fwrite(STDERR, 'refused: 既存の指紋台帳の role が app でない。'
+            ."本リポジトリはテンプレートの受け手なので、正典側の生成器を走らせてはならない。\n");
+        exit(3);
+    }
+}
+
+// --- 登録簿と既存の債務一覧 ---
+$ledgerMarkdown = file_get_contents($root.'/docs/template-divergence.md');
+if ($ledgerMarkdown === false) {
+    $fail('逸脱の登録簿 (docs/template-divergence.md) を読めない');
+}
+
+$parsedLedger = DivergenceLedgerParser::parse($ledgerMarkdown);
+if ($parsedLedger->unparsable || $parsedLedger->parseViolations !== []) {
+    $fail("逸脱の登録簿を解析できない (先に形式検査を通すこと):\n  ".implode("\n  ", $parsedLedger->parseViolations));
+}
+
+$registeredTargetPaths = [];
+foreach ($parsedLedger->entries as $entry) {
+    if ($entry->metadata === null) {
+        $fail('逸脱の登録簿に登録メタ表を解析できない登録がある (先に形式検査を通すこと)');
+    }
+    foreach ($entry->metadata->targetPaths as $targetPath) {
+        $registeredTargetPaths[] = $targetPath;
+    }
+}
+
+$existingDebt = [];
+if (is_file($root.'/'.AdoptionDebtInventory::INVENTORY_PATH)) {
+    try {
+        $existingDebt = AdoptionDebtInventory::read($root)['entries'];
+    } catch (RuntimeException $e) {
+        $fail('既存の採用時債務一覧を解釈できない: '.$e->getMessage());
+    }
+}
+
+// --- 母集合の入力 ---
+try {
+    $trackedPaths = TrackedRepositoryFiles::all($root);
+} catch (RuntimeException $e) {
+    $fail($e->getMessage());
+}
+
+// --- 生成 ---
+try {
+    $context = FingerprintGenerationContext::forRoot(
+        root: $root,
+        expectedTemplateLedgerSha256: LedgerPins::TEMPLATE_LEDGER_SOURCE_SHA256,
+        expectedSourceCommit: LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT,
+        adoptNewTemplateLedger: $adoptNewTemplateLedger,
+        previousLedger: $previousLedger,
+    );
+
+    $report = FingerprintGenerationService::generate(
+        context: $context,
+        templateLedgerRaw: $templateLedgerRaw,
+        trackedPaths: $trackedPaths,
+        hasher: static function (string $relativePath) use ($root): string {
+            $absolute = $root.'/'.$relativePath;
+            $hash = is_link($absolute) || ! is_file($absolute) ? false : hash_file('sha256', $absolute);
+
+            if ($hash === false) {
+                throw new RuntimeException("母集合のファイルのハッシュを計算できない: {$relativePath}");
+            }
+
+            return $hash;
+        },
+        registeredTargetPaths: $registeredTargetPaths,
+        divergenceEntryCount: count($parsedLedger->entries),
+        existingDebt: $existingDebt,
+        tempPathFactory: static function (string $targetPath): string|false {
+            try {
+                return dirname($targetPath).'/.'.basename($targetPath).'.'.bin2hex(random_bytes(8)).'.tmp';
+            } catch (Exception) {
+                return false;
+            }
+        },
+        writer: static fn (string $path, string $data): int|false => file_put_contents($path, $data),
+        reader: static fn (string $path): string|false => is_file($path) ? file_get_contents($path) : false,
+        renamer: static fn (string $from, string $to): bool => rename($from, $to),
+        remover: static fn (string $path): bool => ! is_file($path) || unlink($path),
+    );
+} catch (GenerationRefused $e) {
+    fwrite(STDERR, 'refused: '.$e->getMessage()."\n");
+    exit(3);
+} catch (RuntimeException $e) {
+    fwrite(STDERR, 'error: '.$e->getMessage()."\n");
+    exit(1);
+}
+
+fwrite(STDOUT, sprintf(
+    "生成物を更新した (%s / %s)\n"
+        ."  LedgerPins::FINGERPRINT_POPULATION_COUNT = %d\n"
+        ."  LedgerPins::ADOPTION_DEBT_COUNT          = %d\n"
+        ."  LedgerPins::DIVERGENCE_ENTRY_COUNT       = %d\n"
+        ."  内訳: 一致 %d / 相違 %d / 消滅 %d / 債務へ追加 %d 件%s\n"
+        ."  世代識別子 (template_ledger_commit) = %s\n",
+    LedgerPins::FINGERPRINT_LEDGER_PATH,
+    AdoptionDebtInventory::INVENTORY_PATH,
+    $report['populationCount'],
+    $report['adoptionDebtCount'],
+    $report['divergenceEntryCount'],
+    $report['matched'],
+    $report['mismatched'],
+    $report['missing'],
+    count($report['addedDebt']),
+    $report['seeded'] ? ' (初回生成 = 採用)' : '',
+    $report['templateLedgerCommit'],
+));
+
+exit(0);
diff --git a/tests/Architecture/TemplateDivergenceFingerprintTest.php b/tests/Architecture/TemplateDivergenceFingerprintTest.php
new file mode 100644
index 00000000..2ae3f05f
--- /dev/null
+++ b/tests/Architecture/TemplateDivergenceFingerprintTest.php
@@ -0,0 +1,468 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\TemplateDivergence\AdoptionDebtInventory;
+use Tests\Support\TemplateDivergence\ComparisonState;
+use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
+use Tests\Support\TemplateDivergence\FingerprintLedger;
+use Tests\Support\TemplateDivergence\FingerprintReconciler;
+use Tests\Support\TemplateDivergence\LedgerPins;
+use Tests\Support\TemplateDivergence\LedgerRole;
+use Tests\Support\TemplateDivergence\ParsedLedger;
+use Tests\Support\TemplateDivergence\PathObservation;
+use Tests\Support\TemplateDivergence\ReconciliationResult;
+use Tests\Support\TemplateDivergence\TrackedRepositoryFiles;
+
+/*
+ * 指紋台帳 (`docs/template-fingerprints.json`) と実ファイルの**突合** (家系の正典 t1)。
+ *
+ * 落とすのは 2 つである:
+ *  (3a) テンプレートと内容が食い違っているのに、逸脱の登録も採用時債務の記載も無いパス
+ *  (3b) 内容がテンプレート準拠へ戻ったのに、逸脱の登録が残っているパス
+ * 判定の実体は `FingerprintReconciler` (純関数) にあり、本テストは**現物を読んで観測を組み立て、
+ * 種別ごとに空であることを見るだけ**の薄い層である。検出力 (負例) は
+ * `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` と
+ * `tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php` が固定する。
+ *
+ * 母集合は**正典が公開する指紋台帳のキー ∩ 本リポジトリの追跡ファイル**である
+ * (生成規則の正本は `AppFingerprintBuilder` の docblock)。
+ *
+ * ---------------------------------------------------------------------------
+ * **この検査が保証しないもの** (誇張しない。ここが正本であり AGENTS.md や
+ * docs/template-divergence.md には写さない):
+ *
+ *  1. **粒度はファイル単位**である。共有ファイルの**内部**の逸脱 (規約の一部だけを変えた等) は
+ *     検出しない
+ *  2. **母集合の外には沈黙する**。アプリ固有ファイル (提供元が共有しないと分類したもの。
+ *     `AGENTS.md` / `tests/Pest.php` / `composer.json` / `docs/architecture.md` 等) と、
+ *     正典側にしか無いパス (未受領 / 追従遅れ) は 1 件も見ない
+ *  3. **テンプレート更新への追従遅れは検出しない**。指紋は取り込んだ時点の写しなので、
+ *     正典が先へ進んでも本リポジトリでは食い違いが生じない
+ *  4. **登録済みのパスの追加の drift は検出しない**。既に不一致で登録があるパスは、
+ *     その後どれだけ内容が変わっても「不一致のまま」であり同じ判定になる
+ *     (検出するのは**一致から不一致へ移る瞬間**である)。
+ *     **債務パスは例外**で、採用時ハッシュとの一致まで見るので追加の変更は落ちる
+ *  5. **採用時債務の中身は説明されていない**。意図的逸脱と追従遅れの区別は付いていない
+ *     (分類の契機は登録簿の D34 の見直し期限である)。件数の正本は
+ *     `LedgerPins::ADOPTION_DEBT_COUNT` であり、本 docblock には件数を書かない
+ *  6. **手編集による無効化は止まらない**。指紋台帳 / 債務一覧 / `LedgerPins` / 本検査自身の
+ *     書き換えは検査を書き換えるのと等価であり、PR レビューの義務である。
+ *     F6 が保証するのは**必須メンバが母集合に残り regular file であること**までで、
+ *     登録済みになった本検査の**中身**は固定しない
+ *  7. **`generated_at_commit` の実在は検証しない** (別リポジトリの commit なので原理的に不可能)。
+ *     書式と pin との一致だけを見る
+ *  8. **git 追跡外のファイルは母集合に入らない**
+ *  9. **本検査は突合であって遮断ではない**。逸脱を作れなくするものではなく、
+ *     登録なしに作れなくするものである
+ * 10. **債務一覧の増加は機械では止まらない**。生成器のガードと件数 pin の PR 差分に依存する
+ *     (本検査は履歴を入力に取らないので旧コミットとの比較はできない)
+ * ---------------------------------------------------------------------------
+ *
+ * 実行不能 (指紋台帳 / 登録簿 / 債務一覧が読めない、解釈できない、git が失敗する) は
+ * skip でも緑でもなく**不合格**にする。
+ */
+
+/**
+ * 本機構自身のファイル (必須メンバ pin)。
+ *
+ * 検査を黙らせる変更自体を検査対象にするため、**この一覧のすべてが母集合に在り、
+ * かつ regular file である**ことを F6 が見る。一覧は `LedgerPins` ではなく本ファイルに置く
+ * (pin の置き場に「どのファイルを見るか」を混ぜないため)。
+ *
+ * @return list<string>
+ */
+function fingerprintRequiredMembers(): array
+{
+    return [
+        'tests/Architecture/TemplateDivergenceFingerprintTest.php',
+        'tests/Support/TemplateDivergence/FingerprintLedger.php',
+        'tests/Support/TemplateDivergence/AtomicLedgerWriter.php',
+        'tests/Support/TemplateDivergence/LedgerRole.php',
+        'tests/Support/TemplateDivergence/ComparisonState.php',
+        'scripts/update-template-fingerprints.php',
+    ];
+}
+
+/** 指紋台帳の生バイト列 (読めないことは不合格)。 */
+function fingerprintLedgerRaw(): string
+{
+    $raw = file_get_contents(base_path(LedgerPins::FINGERPRINT_LEDGER_PATH));
+    if ($raw === false) {
+        throw new RuntimeException('指紋台帳 '.LedgerPins::FINGERPRINT_LEDGER_PATH.' を読めない');
+    }
+
+    return $raw;
+}
+
+/** 指紋台帳の DTO。 */
+function fingerprintLedger(): FingerprintLedger
+{
+    return FingerprintLedger::fromJson(fingerprintLedgerRaw());
+}
+
+/**
+ * 採用時債務一覧。
+ *
+ * @return array{templateLedgerCommit: string, entries: array<string, string>}
+ */
+function fingerprintDebt(): array
+{
+    static $cache = null;
+
+    if ($cache === null) {
+        $cache = AdoptionDebtInventory::read(base_path());
+    }
+
+    return $cache;
+}
+
+/** git 追跡ファイルの集合 (パス => true)。 */
+function fingerprintTrackedSet(): array
+{
+    static $cache = null;
+
+    if ($cache === null) {
+        $cache = array_fill_keys(TrackedRepositoryFiles::all(base_path()), true);
+    }
+
+    return $cache;
+}
+
+/**
+ * 母集合の各パスを観測する。
+ *
+ * `MissingCurrent` になるのは **git index / working tree から消えた場合だけ**である。
+ * symlink / 通常ファイルでない / 読めない / ハッシュ計算の失敗は**別種の「検査不能」**として
+ * 記録し、消滅へ畳まない (畳むと「検査不能を消滅へ畳まない」不変条件そのものが壊れる)。
+ *
+ * @return array<string, PathObservation>
+ */
+function fingerprintObservations(): array
+{
+    static $cache = null;
+
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $tracked = fingerprintTrackedSet();
+    $observations = [];
+
+    foreach (fingerprintLedger()->entries as $path => $templateHash) {
+        $absolute = base_path($path);
+
+        if (! array_key_exists($path, $tracked)) {
+            $observations[$path] = new PathObservation(ComparisonState::MissingCurrent, null, null);
+
+            continue;
+        }
+        if (is_link($absolute)) {
+            $observations[$path] = new PathObservation(null, null, 'symlink である (内容の指紋を取らない)');
+
+            continue;
+        }
+        if (! file_exists($absolute)) {
+            // index には残っているが working tree に無い = 削除
+            $observations[$path] = new PathObservation(ComparisonState::MissingCurrent, null, null);
+
+            continue;
+        }
+        if (! is_file($absolute)) {
+            $observations[$path] = new PathObservation(null, null, '通常ファイルでない');
+
+            continue;
+        }
+
+        $hash = hash_file('sha256', $absolute);
+        if ($hash === false) {
+            $observations[$path] = new PathObservation(null, null, 'ハッシュを計算できない');
+
+            continue;
+        }
+
+        $observations[$path] = new PathObservation(
+            $hash === $templateHash ? ComparisonState::Matched : ComparisonState::ContentMismatch,
+            $hash,
+            null,
+        );
+    }
+
+    return $cache = $observations;
+}
+
+/**
+ * 登録簿の解析結果から対象パスのリストを組み立てる (F13 が先に解析の成功を見る)。
+ *
+ * @return list<array{path: string, label: string}>
+ */
+function fingerprintRegisteredPaths(): array
+{
+    static $cache = null;
+
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $parsed = fingerprintParsedDivergenceLedger();
+
+    $registered = [];
+    foreach ($parsed->entries as $entry) {
+        if ($entry->metadata === null) {
+            throw new RuntimeException('逸脱の登録簿に登録メタ表を解析できない登録がある: '.$entry->label());
+        }
+        foreach ($entry->metadata->targetPaths as $path) {
+            $registered[] = ['path' => $path, 'label' => $entry->label()];
+        }
+    }
+
+    return $cache = $registered;
+}
+
+/** 逸脱の登録簿の解析結果。 */
+function fingerprintParsedDivergenceLedger(): ParsedLedger
+{
+    static $cache = null;
+
+    if ($cache === null) {
+        $markdown = file_get_contents(base_path('docs/template-divergence.md'));
+        if ($markdown === false) {
+            throw new RuntimeException('逸脱の登録簿 docs/template-divergence.md を読めない');
+        }
+        $cache = DivergenceLedgerParser::parse($markdown);
+    }
+
+    return $cache;
+}
+
+/** 突合の結果 (1 回だけ計算する)。 */
+function fingerprintReconciliation(): ReconciliationResult
+{
+    static $cache = null;
+
+    if ($cache === null) {
+        $cache = FingerprintReconciler::reconcile(
+            observations: fingerprintObservations(),
+            registered: fingerprintRegisteredPaths(),
+            debt: fingerprintDebt()['entries'],
+            templateHashes: fingerprintLedger()->entries,
+        );
+    }
+
+    return $cache;
+}
+
+test('F0: 指紋台帳・登録簿・債務一覧が実在して読めること (読み取り失敗は不合格)', function (): void {
+    expect(trim(fingerprintLedgerRaw()))->not->toBe('')
+        ->and(fingerprintDebt())->toHaveKey('templateLedgerCommit')
+        ->and(is_file(base_path('docs/template-divergence.md')))->toBeTrue();
+
+    // 負のコントロール: 読めない入力が黙って空へ潰れず例外になること
+    expect(fn (): array => AdoptionDebtInventory::read(base_path('storage/framework/t236-absent')))
+        ->toThrow(RuntimeException::class);
+    expect(fn (): FingerprintLedger => FingerprintLedger::fromJson(''))
+        ->toThrow(RuntimeException::class);
+});
+
+test('F1: 指紋台帳の schema が解釈でき role が app で、正本が正準形バイト一致であること', function (): void {
+    $raw = fingerprintLedgerRaw();
+    $ledger = FingerprintLedger::fromJson($raw);
+
+    expect($ledger->role)->toBe(LedgerRole::App)
+        ->and($ledger->schemaVersion)->toBe(FingerprintLedger::SCHEMA_VERSION)
+        // 重複キー・非正準な整形・キー順の崩れ・末尾改行の欠落をまとめて落とす
+        ->and($raw)->toBe($ledger->toJson());
+});
+
+test('F2: composer.json の name が aicue の識別子と完全一致すること', function (): void {
+    $raw = file_get_contents(base_path('composer.json'));
+    expect($raw)->toBeString();
+
+    /** @var mixed $decoded */
+    $decoded = json_decode((string) $raw, true, 32, JSON_THROW_ON_ERROR);
+    expect($decoded)->toBeArray();
+
+    /** @var array<string, mixed> $decoded */
+    $name = $decoded['name'] ?? null;
+
+    expect($name)->toBe('rio-development/aicue');
+});
+
+test('F3: 母集合の件数が pin と完全一致すること', function (): void {
+    expect(fingerprintLedger()->entries)->toHaveCount(LedgerPins::FINGERPRINT_POPULATION_COUNT);
+});
+
+test('F4: 母集合と git 追跡ファイルがどちらも非空であること (走査の生存確認)', function (): void {
+    expect(fingerprintLedger()->entries)->not->toBeEmpty()
+        ->and(fingerprintTrackedSet())->not->toBeEmpty()
+        ->and(count(fingerprintTrackedSet()))->toBeGreaterThanOrEqual(1000);
+});
+
+test('F5: 指紋台帳の generated_at_commit が出自の pin と一致すること', function (): void {
+    expect(fingerprintLedger()->generatedAtCommit)->toBe(LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT);
+});
+
+test('F6: 本機構自身のファイルが母集合にあり regular file であること', function (): void {
+    $members = fingerprintRequiredMembers();
+    $population = fingerprintLedger()->entries;
+
+    // 一覧そのものが空になったら (= 誰も pin していない状態) 不合格
+    expect($members)->not->toBeEmpty();
+
+    foreach ($members as $member) {
+        expect(array_key_exists($member, $population))->toBeTrue(
+            "本機構のファイルが母集合から外れています: {$member}",
+        );
+        expect(is_file(base_path($member)) && ! is_link(base_path($member)))->toBeTrue(
+            "本機構のファイルが regular file ではありません: {$member}",
+        );
+    }
+});
+
+test('F7: 母集合の全パスを観測でき、消滅と検査不能を混同しないこと', function (): void {
+    $observations = fingerprintObservations();
+
+    expect($observations)->toHaveCount(LedgerPins::FINGERPRINT_POPULATION_COUNT);
+
+    foreach ($observations as $path => $observation) {
+        // 状態が付いた観測と検査不能の観測は排他である (PathObservation が型で保証している)
+        expect($observation->state !== null || $observation->inspectionFailure !== null)->toBeTrue(
+            "観測が状態も理由も持っていません: {$path}",
+        );
+    }
+});
+
+test('F8: 検査不能の観測が 0 件であること (登録済み・債務で吸収させない)', function (): void {
+    expect(fingerprintReconciliation()->inspectionFailures)->toBe([]);
+});
+
+test('F9: 3a / 3b が 0 件であること', function (): void {
+    $result = fingerprintReconciliation();
+
+    expect($result->unregisteredMismatches)->toBe([], fingerprintFailureHint3a($result->unregisteredMismatches))
+        ->and($result->staleRegistrations)->toBe([], fingerprintFailureHint3b($result->staleRegistrations));
+});
+
+test('F10: 採用時債務の規則違反が 0 件であること', function (): void {
+    $result = fingerprintReconciliation();
+
+    expect($result->resolvedDebtPaths)->toBe([], fingerprintFailureHintResolved($result->resolvedDebtPaths))
+        ->and($result->mutatedDebtPaths)->toBe([], fingerprintFailureHintMutated($result->mutatedDebtPaths))
+        ->and($result->doubleDeclaredPaths)->toBe([], '債務一覧と逸脱の登録が同じパスを二重に宣言しています')
+        ->and($result->debtPathsOutsidePopulation)->toBe([], '債務一覧に母集合外のパスがあります (生成器で再生成すること)')
+        ->and($result->duplicateRegisteredPaths)->toBe([], '同じ対象パスを 2 つ以上の登録が挙げています');
+});
+
+test('F11: 採用時債務の件数が pin と完全一致すること', function (): void {
+    expect(fingerprintDebt()['entries'])->toHaveCount(LedgerPins::ADOPTION_DEBT_COUNT);
+});
+
+test('F12: 債務が非空の間は債務一覧のファイルが登録簿に登録されていること', function (): void {
+    $debt = fingerprintDebt()['entries'];
+    if ($debt === []) {
+        // 0 件になったら一覧ファイルと登録を同じ変更で消す (D34 の再判定の条件)
+        expect(true)->toBeTrue();
+
+        return;
+    }
+
+    $registeredPaths = array_column(fingerprintRegisteredPaths(), 'path');
+
+    expect(in_array(AdoptionDebtInventory::INVENTORY_PATH, $registeredPaths, true))->toBeTrue(
+        '債務が残っている間は '.AdoptionDebtInventory::INVENTORY_PATH.' を登録簿へ登録しておくこと',
+    );
+});
+
+test('F13: 逸脱の登録簿の解析が成功していること (解析違反から登録を組み立てない)', function (): void {
+    $parsed = fingerprintParsedDivergenceLedger();
+
+    expect($parsed->unparsable)->toBeFalse()
+        ->and($parsed->parseViolations)->toBe([])
+        ->and($parsed->entries)->not->toBeEmpty();
+
+    foreach ($parsed->entries as $entry) {
+        expect($entry->metadata)->not->toBeNull('登録メタ表を解析できない登録があります: '.$entry->label());
+    }
+});
+
+test('F14: 2 生成物の世代が揃っていて、債務が定義どおり食い違っていること', function (): void {
+    $ledger = fingerprintLedger();
+    $debt = fingerprintDebt();
+
+    // 片方だけが更新された状態を落とす (件数 pin だけでは増減が相殺されて緑になり得る)
+    expect($debt['templateLedgerCommit'])->toBe(
+        $ledger->generatedAtCommit,
+        '債務一覧のヘッダと指紋台帳の generated_at_commit が食い違っています (生成器で再生成すること)',
+    );
+
+    foreach ($debt['entries'] as $path => $adoptionHash) {
+        // 母集合外の債務は F10 の担当なのでここでは hash 比較へ進めない
+        if (! array_key_exists($path, $ledger->entries)) {
+            continue;
+        }
+
+        expect($adoptionHash)->not->toBe(
+            $ledger->entries[$path],
+            "債務パスの採用時ハッシュが正典側ハッシュと同じです (債務は定義上食い違っている): {$path}",
+        );
+    }
+});
+
+/**
+ * 3a の直し方 (失敗メッセージ)。
+ *
+ * @param  list<string>  $paths
+ */
+function fingerprintFailureHint3a(array $paths): string
+{
+    return 'テンプレートと共有するファイルを変えたのに登録が無いパスがあります ('.count($paths).' 件):'.PHP_EOL
+        .implode(PHP_EOL, array_map(static fn (string $p): string => "  - {$p}", $paths)).PHP_EOL
+        .'直し方は 2 通りです (指紋台帳や債務一覧を書き換えて黙らせないこと):'.PHP_EOL
+        .'  1. 意図的逸脱なら docs/template-divergence.md へ登録を足し、'.PHP_EOL
+        .'     LedgerPins::DIVERGENCE_ENTRY_COUNT を同じ変更で 1 増やす'.PHP_EOL
+        .'  2. 逸脱でないなら内容をテンプレート準拠へ戻す';
+}
+
+/**
+ * 3b の直し方 (失敗メッセージ)。
+ *
+ * @param  list<string>  $paths
+ */
+function fingerprintFailureHint3b(array $paths): string
+{
+    return '内容がテンプレート準拠へ戻ったのに登録が残っているパスがあります ('.count($paths).' 件):'.PHP_EOL
+        .implode(PHP_EOL, array_map(static fn (string $p): string => "  - {$p}", $paths)).PHP_EOL
+        .'直し方: 該当パスを登録の対象パス欄から削り (全パスが戻ったなら登録ごと削除し)、'.PHP_EOL
+        .'        LedgerPins::DIVERGENCE_ENTRY_COUNT を同じ変更で直すこと。'.PHP_EOL
+        .'        状態の語 (恒久 / 監視中) で「解消済み」を表さないこと。';
+}
+
+/**
+ * 債務が解消したときの直し方 (失敗メッセージ)。
+ *
+ * @param  list<string>  $paths
+ */
+function fingerprintFailureHintResolved(array $paths): string
+{
+    return '内容がテンプレート準拠へ戻ったのに債務一覧に残っているパスがあります ('.count($paths).' 件):'.PHP_EOL
+        .implode(PHP_EOL, array_map(static fn (string $p): string => "  - {$p}", $paths)).PHP_EOL
+        .'直し方: 該当行を '.AdoptionDebtInventory::INVENTORY_PATH.' から削り、'.PHP_EOL
+        .'        LedgerPins::ADOPTION_DEBT_COUNT を同じ変更で減らすこと。';
+}
+
+/**
+ * 債務パスを触ってしまったときの直し方 (失敗メッセージ)。
+ *
+ * @param  list<string>  $paths
+ */
+function fingerprintFailureHintMutated(array $paths): string
+{
+    return '採用時の姿から変わった債務パスがあります ('.count($paths).' 件):'.PHP_EOL
+        .implode(PHP_EOL, array_map(static fn (string $p): string => "  - {$p}", $paths)).PHP_EOL
+        .'債務は「採用時点の凍結された観測」なので、変更したまま残すことはできません。'.PHP_EOL
+        .'次の 3 つから選んでください:'.PHP_EOL
+        .'  1. 内容を採用時の姿へ戻す'.PHP_EOL
+        .'  2. テンプレート準拠へ同期して債務一覧から削る'.PHP_EOL
+        .'  3. 意図的逸脱として docs/template-divergence.md へ登録を書き、債務一覧から削る'.PHP_EOL
+        .'いずれの場合も LedgerPins の件数を同じ変更で直すこと。';
+}
diff --git a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
index 6bac4c1e..8e5291fb 100644
--- a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
+++ b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
@@ -6,6 +6,7 @@
 use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
 use Tests\Support\TemplateDivergence\DivergenceLedgerRules;
 use Tests\Support\TemplateDivergence\LedgerContext;
+use Tests\Support\TemplateDivergence\LedgerPins;
 use Tests\Support\TemplateDivergence\TodoLedgerReference;
 use Webmozart\Assert\Assert;
 
@@ -19,8 +20,10 @@
  *
  * **この検査が保証しないもの** (誇張しない):
  *  - 実ファイルがテンプレートから逸脱したのに登録が無いこと (登録漏れそのもの)。
- *    実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)
- *  - 内容をテンプレート準拠へ戻したのに残っている登録 (対象パスは実在し続けるため)
+ *    **実体との突合は `tests/Architecture/TemplateDivergenceFingerprintTest.php` が持つ**
+ *    (家系の正典 t1)。本検査は形式だけを見る
+ *  - 内容をテンプレート準拠へ戻したのに残っている登録 (対象パスは実在し続けるため。
+ *    こちらも突合検査の担当である)
  *  - 登録の中身が正しいこと (空でないこと・値域に収まっていることだけを見る)
  *  - 削除した番号の再利用 (使用済み番号の履歴を持たないため)
  *
@@ -28,14 +31,6 @@
  * skip でも緑でもなく**不合格**にする。
  */
 
-/**
- * 登録件数の固定値。
- *
- * **明示件数との同期検査であって、例外を許す一覧ではない**。個別の D 番号を名指しして
- * 規則を免除する仕組みは持たない。登録を足した / 消したら同じ変更でこの値も直す。
- */
-const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 30;
-
 /** 逸脱の登録簿の本文 (読めないことは不合格)。 */
 function templateDivergenceMarkdown(): string
 {
@@ -68,7 +63,7 @@ function templateDivergenceTodoSources(): string
         DivergenceLedgerParser::parse(templateDivergenceMarkdown()),
         new LedgerContext(
             baseDate: CarbonImmutable::today(),
-            pinnedEntryCount: TEMPLATE_DIVERGENCE_ENTRY_COUNT,
+            pinnedEntryCount: LedgerPins::DIVERGENCE_ENTRY_COUNT,
             pathExists: fn (string $path): bool => is_file(base_path($path)),
             directoryExists: fn (string $path): bool => is_dir(base_path($path)),
             // T 番号は TODO 台帳の表のセルとして境界付きで照合する (T1 が T10 に一致しないように)
diff --git a/tests/Support/TemplateDivergence/AdoptionDebtInventory.php b/tests/Support/TemplateDivergence/AdoptionDebtInventory.php
new file mode 100644
index 00000000..90db7428
--- /dev/null
+++ b/tests/Support/TemplateDivergence/AdoptionDebtInventory.php
@@ -0,0 +1,147 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use RuntimeException;
+
+/**
+ * 採用時債務一覧 — 「採用時点で内容が食い違っていたが、登録簿に説明が無いパス」と
+ * **その時点のアプリ側 sha256**。
+ *
+ * ★**免除の許可一覧ではない**。採用時点の凍結された観測である。
+ *   ハッシュを持つので「採用時の姿のまま」と「採用後に手を入れた」を区別でき、
+ *   後者は違反になる (パスだけを持つ形は、そのパスに対する恒久的な許可一覧になってしまう)。
+ * ★一覧が縮む契機は 2 つ = (1) 内容をテンプレートへ戻す /
+ *   (2) 意図的逸脱として登録簿へ書く。期限による棚卸しは登録簿の D34
+ *   (`監視中` + 見直し期限) が持つ。
+ * ★**保証しないもの**: 一覧へ行を足す変更は機械では止まらない (生成器のガードと
+ *   件数 pin の PR 差分に依存する)。各パスが意図的逸脱なのか追従遅れなのかは分類していない。
+ *
+ * 書式は 1 行 1 件・タブ区切りの 2 列で、先頭行が**世代識別子のヘッダ**である:
+ *
+ *     # template_ledger_commit=<40 桁小文字 hex>
+ *     <repo-relative パス>\t<採用時のアプリ側 sha256>
+ *
+ * ヘッダの値は指紋台帳の `generated_at_commit` と突き合わせる (突合 gate の F14)。
+ * 2 生成物は別ディレクトリなのでセット単位の原子性を主張できず、
+ * **片方だけが更新された状態**はこのヘッダの不一致として落ちる。
+ */
+final class AdoptionDebtInventory
+{
+    /** 一覧の置き場 (リポジトリ相対)。登録簿の対象パスとしても登録されている (D34)。 */
+    public const string INVENTORY_PATH = 'tests/Support/TemplateDivergence/adoption-debt.tsv';
+
+    /** ヘッダ行の正準形。 */
+    private const string HEADER_PATTERN = '/^# template_ledger_commit=([0-9a-f]{40})$/';
+
+    /** インスタンス化しない (純関数のみ)。 */
+    private function __construct() {}
+
+    /**
+     * リポジトリの一覧ファイルを読んで検証済みの内容を返す。
+     *
+     * **読めないことは空ではなく例外**にする (fail-open を作らない)。
+     *
+     * @return array{templateLedgerCommit: string, entries: array<string, string>}
+     *
+     * @throws RuntimeException
+     */
+    public static function read(string $root): array
+    {
+        $path = rtrim($root, '/').'/'.self::INVENTORY_PATH;
+        $contents = is_file($path) && ! is_link($path) ? file_get_contents($path) : false;
+
+        if ($contents === false) {
+            throw new RuntimeException("採用時債務一覧を読めない (実行不能として落とす): {$path}");
+        }
+
+        return self::parse($contents);
+    }
+
+    /**
+     * 一覧の本文を検証して返す。
+     *
+     * 落とす形 (内容側 10 形。読み取り失敗を合わせて詳細設計の 11 形):
+     * 空 / 先頭行が世代識別子のヘッダでない / 末尾改行が無い / 空行 /
+     * 列がタブ 2 列でない / 前後に空白がある / パスの重複 /
+     * パスが `RepoRelativePath::isValid()` を通らない / ハッシュが 64 桁小文字 hex でない /
+     * パスの昇順でない。
+     *
+     * @return array{templateLedgerCommit: string, entries: array<string, string>}
+     *
+     * @throws RuntimeException
+     */
+    public static function parse(string $contents): array
+    {
+        if ($contents === '') {
+            throw new RuntimeException('採用時債務一覧が空である (ヘッダ行だけでも必要である)');
+        }
+        if (! str_ends_with($contents, "\n")) {
+            throw new RuntimeException('採用時債務一覧の末尾改行が無い');
+        }
+
+        // 末尾の改行 1 つだけを落とす (余分な改行は空行として検出させる)
+        $lines = explode("\n", substr($contents, 0, -1));
+
+        $header = array_shift($lines);
+        if ($header === null || preg_match(self::HEADER_PATTERN, $header, $matches) !== 1) {
+            throw new RuntimeException('採用時債務一覧の先頭行が `# template_ledger_commit=<40 桁小文字 hex>` でない');
+        }
+
+        $entries = [];
+        foreach ($lines as $index => $line) {
+            $lineNumber = $index + 2; // ヘッダが 1 行目
+            if ($line === '') {
+                throw new RuntimeException("採用時債務一覧の {$lineNumber} 行目が空行である");
+            }
+
+            $columns = explode("\t", $line);
+            if (count($columns) !== 2) {
+                throw new RuntimeException("採用時債務一覧の {$lineNumber} 行目がタブ区切りの 2 列でない");
+            }
+
+            [$path, $hash] = $columns;
+            if (trim($path) !== $path || trim($hash) !== $hash) {
+                throw new RuntimeException("採用時債務一覧の {$lineNumber} 行目の値に前後の空白がある");
+            }
+            if (! RepoRelativePath::isValid($path)) {
+                throw new RuntimeException("採用時債務一覧の {$lineNumber} 行目のパスが単一ファイルパスでない: {$path}");
+            }
+            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
+                throw new RuntimeException("採用時債務一覧の {$lineNumber} 行目のハッシュが 64 桁小文字 hex でない");
+            }
+            if (array_key_exists($path, $entries)) {
+                throw new RuntimeException("採用時債務一覧のパスが重複している: {$path}");
+            }
+
+            $entries[$path] = $hash;
+        }
+
+        $sortedKeys = array_keys($entries);
+        sort($sortedKeys, SORT_STRING);
+        if (array_keys($entries) !== $sortedKeys) {
+            throw new RuntimeException('採用時債務一覧がパスの昇順でない (生成器で再生成すること)');
+        }
+
+        return ['templateLedgerCommit' => $matches[1], 'entries' => $entries];
+    }
+
+    /**
+     * 検証済みの内容から一覧の本文を組み立てる (生成器が使う。読み書きの正準形を 1 か所にする)。
+     *
+     * @param  array<string, string>  $entries
+     */
+    public static function render(string $templateLedgerCommit, array $entries): string
+    {
+        ksort($entries, SORT_STRING);
+
+        $text = '# template_ledger_commit='.$templateLedgerCommit."\n";
+        foreach ($entries as $path => $hash) {
+            $text .= $path."\t".$hash."\n";
+        }
+
+        return $text;
+    }
+}
diff --git a/tests/Support/TemplateDivergence/AppFingerprintBuilder.php b/tests/Support/TemplateDivergence/AppFingerprintBuilder.php
new file mode 100644
index 00000000..4bfbafe6
--- /dev/null
+++ b/tests/Support/TemplateDivergence/AppFingerprintBuilder.php
@@ -0,0 +1,254 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use RuntimeException;
+
+/**
+ * 正典の指紋台帳と自リポジトリの追跡ファイルから、`role: app` の指紋台帳と
+ * 採用時債務一覧を組み立てる (純関数。I/O は注入する)。
+ *
+ * 母集合の定義 (**2 通り**):
+ *  - 初回生成 (前世代の台帳が無い): {正典のキー} ∩ {現在の git 追跡ファイル}
+ *  - 2 回目以降: {正典のキー} ∩ ({現在の git 追跡ファイル} ∪ {旧アプリ台帳のキー})
+ * 2 項目の和集合を取るのは、**ローカルでファイルを消してから再生成しても母集合から
+ * 外せないようにする**ためである (消えたパスは突合 gate で `MissingCurrent` になる)。
+ * 母集合から外れるのは**正典側から消えたパスだけ**である。
+ * 値は**正典側の sha256 をそのまま写す** (テンプレート側の内容の指紋である)。
+ *
+ * 債務の更新規則:
+ *  - **初回生成は「採用」である**。未登録の相違はすべて**その時点のアプリ側ハッシュで凍結**する
+ *    (これが「採用時債務」の由来である)
+ *  - **維持**: 既存の債務パスは採用時ハッシュをそのまま持ち越す (凍結された観測なので更新しない)
+ *  - **削除**: 内容が正典と一致へ戻った / 登録簿へ登録された パスは債務から外す
+ *  - **追加**: 2 回目以降で既存の債務に無いパスの追加は**原則として拒否**する。許すのは
+ *    前世代の台帳にそのパスがあり、**現在のアプリ側ハッシュが前世代の正典ハッシュと一致する**
+ *    ときだけである (= 差が生じた原因がテンプレート側の前進であることの証明)。
+ *    それ以外は「自分が変えたのに登録していない食い違い」なので通さない
+ *    (登録を書くか内容を戻す)
+ *
+ * **保証しないもの**:
+ *  - 正典側に存在しないパス (自リポジトリの追加) は母集合に入らない
+ *  - 正典側にしか無いパス (未受領 / 追従遅れ) も母集合に入らない
+ *  - **初回生成の経路は債務一覧を作り直せる**。アプリ側の指紋台帳を消してから生成器を
+ *    走らせると採用がやり直しになるので、この経路は**件数 pin の差分と PR レビュー**でしか
+ *    止まらない (指紋台帳・債務一覧・pin・gate 自身の手編集が止まらないのと同じ原理的限界)
+ */
+final class AppFingerprintBuilder
+{
+    /** インスタンス化しない (純関数のみ)。 */
+    private function __construct() {}
+
+    /**
+     * @param  list<string>  $trackedPaths  git 追跡ファイル (重複や不正パスは例外。並び順は問わない)
+     * @param  callable(string): string  $hasher  自リポジトリのファイルの sha256
+     *                                            (**戻り値が 64 桁小文字 hex でなければ例外**。読めない場合も例外)
+     * @param  list<string>  $registeredTargetPaths  登録簿の全対象パス
+     * @param  array<string, string>  $existingDebt  既存の債務一覧 (path => 採用時のアプリ側 sha256)
+     * @return array{
+     *     ledger: FingerprintLedger,
+     *     debt: array<string, string>,
+     *     matched: int,
+     *     mismatched: int,
+     *     missing: int,
+     *     addedDebt: list<string>,
+     *     seeded: bool,
+     * }
+     *
+     * @throws GenerationRefused 債務へ新規パスを追加しようとしたとき
+     * @throws RuntimeException 入力が不正なとき / 母集合が 0 件のとき / ハッシュを計算できないとき
+     */
+    public static function build(
+        FingerprintLedger $templateLedger,
+        array $trackedPaths,
+        callable $hasher,
+        array $registeredTargetPaths,
+        array $existingDebt,
+        ?FingerprintLedger $previousLedger,
+    ): array {
+        if ($templateLedger->role !== LedgerRole::Template) {
+            throw new RuntimeException('入力が正典の指紋台帳でない (role: template を要求する)');
+        }
+        if ($previousLedger !== null && $previousLedger->role !== LedgerRole::App) {
+            throw new RuntimeException('前世代の指紋台帳の role が app でない');
+        }
+
+        $tracked = self::uniquePathSet($trackedPaths, 'git 追跡ファイル');
+        $registered = self::uniquePathSet($registeredTargetPaths, '登録簿の対象パス');
+        self::assertDebtShape($existingDebt);
+
+        $seeding = $previousLedger === null;
+
+        // --- 母集合 (正典のキーとの積。2 回目以降は旧台帳のキーも候補に残す) ---
+        $candidates = $tracked;
+        if ($previousLedger !== null) {
+            foreach (array_keys($previousLedger->entries) as $path) {
+                $candidates[$path] = true;
+            }
+        }
+
+        $population = [];
+        foreach ($templateLedger->entries as $path => $templateHash) {
+            if (array_key_exists($path, $candidates)) {
+                $population[$path] = $templateHash;
+            }
+        }
+        ksort($population, SORT_STRING);
+
+        if ($population === []) {
+            throw new RuntimeException(
+                '母集合が 0 件と算出された。0 件は合格ではなく実行不能として落とす (正典 boundary (5b))。',
+            );
+        }
+
+        $debt = [];
+        $addedDebt = [];
+        $matched = 0;
+        $mismatched = 0;
+        $missing = 0;
+
+        foreach ($population as $path => $templateHash) {
+            $isRegistered = array_key_exists($path, $registered);
+
+            if (! array_key_exists($path, $tracked)) {
+                // 旧台帳にだけあるパス = ローカルで消された (母集合からは外さない)
+                $missing++;
+                if (array_key_exists($path, $existingDebt)) {
+                    throw new RuntimeException(
+                        "債務パスが git 追跡から消えている: {$path} — 復元するか、逸脱として登録簿へ書くこと",
+                    );
+                }
+                if (! $isRegistered) {
+                    throw new GenerationRefused(
+                        "git 追跡から消えた未登録パスは債務へ追加できない: {$path} — 復元するか登録簿へ書くこと",
+                    );
+                }
+
+                continue;
+            }
+
+            $currentHash = self::hashOf($hasher, $path);
+
+            if ($currentHash === $templateHash) {
+                $matched++;
+
+                continue; // 一致へ戻ったパスは債務からも外れる
+            }
+
+            $mismatched++;
+
+            if ($isRegistered) {
+                continue; // 登録簿に説明がある = 債務ではない
+            }
+
+            if (array_key_exists($path, $existingDebt)) {
+                $debt[$path] = $existingDebt[$path]; // 採用時ハッシュを持ち越す (更新しない)
+
+                continue;
+            }
+
+            if ($seeding) {
+                $debt[$path] = $currentHash; // 採用: この時点の姿を凍結する
+                $addedDebt[] = $path;
+
+                continue;
+            }
+
+            $previousTemplateHash = $previousLedger->entries[$path] ?? null;
+            if ($previousTemplateHash !== null && $currentHash === $previousTemplateHash) {
+                // 前世代の正典と一致していた = 差の原因はテンプレート側の前進である
+                $debt[$path] = $currentHash;
+                $addedDebt[] = $path;
+
+                continue;
+            }
+
+            throw new GenerationRefused(sprintf(
+                '債務一覧へ新規パスを追加しようとした: %s — 自分で変えた食い違いは債務にできない。'
+                    .'逸脱として docs/template-divergence.md へ登録するか、内容をテンプレートへ戻すこと。',
+                $path,
+            ));
+        }
+
+        ksort($debt, SORT_STRING);
+        sort($addedDebt, SORT_STRING);
+
+        return [
+            'ledger' => new FingerprintLedger(
+                FingerprintLedger::SCHEMA_VERSION,
+                LedgerRole::App,
+                $templateLedger->generatedAtCommit,
+                $population,
+            ),
+            'debt' => $debt,
+            'matched' => $matched,
+            'mismatched' => $mismatched,
+            'missing' => $missing,
+            'addedDebt' => $addedDebt,
+            'seeded' => $seeding,
+        ];
+    }
+
+    /**
+     * パスの一覧を「重複なし・書式が正しい」集合へ変える。
+     *
+     * @param  list<string>  $paths
+     * @return array<string, true>
+     *
+     * @throws RuntimeException
+     */
+    private static function uniquePathSet(array $paths, string $label): array
+    {
+        $set = [];
+        foreach ($paths as $path) {
+            if (! RepoRelativePath::isValid($path)) {
+                throw new RuntimeException("{$label} に単一ファイルパスでない値がある: ".var_export($path, true));
+            }
+            if (array_key_exists($path, $set)) {
+                throw new RuntimeException("{$label} にパスの重複がある: {$path}");
+            }
+            $set[$path] = true;
+        }
+
+        return $set;
+    }
+
+    /**
+     * 既存の債務一覧の形を確かめる。
+     *
+     * @param  array<string, string>  $existingDebt
+     *
+     * @throws RuntimeException
+     */
+    private static function assertDebtShape(array $existingDebt): void
+    {
+        foreach ($existingDebt as $path => $hash) {
+            if (! RepoRelativePath::isValid((string) $path)) {
+                throw new RuntimeException('既存の債務一覧に単一ファイルパスでないキーがある: '.var_export($path, true));
+            }
+            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
+                throw new RuntimeException("既存の債務一覧の採用時ハッシュが 64 桁小文字 hex でない: {$path}");
+            }
+        }
+    }
+
+    /**
+     * 注入されたハッシュ関数を呼び、戻り値の書式まで確かめる。
+     *
+     * @param  callable(string): string  $hasher
+     *
+     * @throws RuntimeException
+     */
+    private static function hashOf(callable $hasher, string $path): string
+    {
+        $hash = $hasher($path);
+
+        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
+            throw new RuntimeException("ハッシュ関数が 64 桁小文字 hex を返さなかった: {$path}");
+        }
+
+        return $hash;
+    }
+}
diff --git a/tests/Support/TemplateDivergence/AtomicLedgerWriter.php b/tests/Support/TemplateDivergence/AtomicLedgerWriter.php
new file mode 100644
index 00000000..999d5c18
--- /dev/null
+++ b/tests/Support/TemplateDivergence/AtomicLedgerWriter.php
@@ -0,0 +1,113 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use RuntimeException;
+
+/**
+ * 指紋台帳の原子的置換。
+ *
+ * 同一ディレクトリの一時ファイルへ書き、(1) 書き込みバイト長、(2) 読み直した内容が
+ * `FingerprintLedger::fromJson()` を通ること、を確認してから rename で置換する。
+ * **どの段で失敗しても正本のバイト列を変えない** (切り詰められた JSON を正本に残さない)。
+ *
+ * 一時ファイルの契約:
+ *  (a) 一時ファイルは正本と同一ディレクトリに作る (rename を同一 FS に閉じる)
+ *  (b) 一時パスの dirname が正本の dirname と一致しなければ**書き込み前に** fail する
+ *  (c) 一時パス生成の失敗は正本に触れずに失敗を返す
+ *  (d) write / read / rename のどの失敗でも一時ファイルの削除を試み、削除にも失敗したら
+ *      **元の失敗に加えてその旨を報告する** (「削除失敗時も残らない」とは主張しない)
+ *  (e) rename 成功後は一時ファイルが存在しない (rename が消費するため)
+ *
+ * I/O はすべて注入する (失敗注入でユニットテストできるようにするため)。
+ *
+ * ★**本クラスは JSON 専用である** — 読み戻しの検証が `FingerprintLedger::fromJson()` に
+ *   固定されているため、採用時債務一覧のような平文の生成物には使えない。
+ *   平文は `AtomicTextWriter` (検証関数を注入する版) が書く。両者は同じ 5 つの契約を持ち、
+ *   違いは読み戻しの検証を誰が行うかと、失敗を**戻り値で返すか例外で投げるか**だけである。
+ *   本クラスは正典 (laravel-claude-template) からの移植なので戻り値の形を保つ。
+ *   **呼び出し側は戻り値が null でないことを必ず判定して失敗させること**
+ *   (無視すると fail-open になる。`FingerprintGenerationService` がそれを固定している)。
+ */
+final class AtomicLedgerWriter
+{
+    /** インスタンス化しない (純関数のみ)。 */
+    private function __construct() {}
+
+    /**
+     * @param  callable(): (string|false)  $tempPathFactory  同一ディレクトリの一時パス生成
+     * @param  callable(string, string): (int|false)  $writer  file_put_contents 相当
+     * @param  callable(string): (string|false)  $reader  file_get_contents 相当
+     * @param  callable(string, string): bool  $renamer  rename 相当
+     * @param  callable(string): bool  $remover  unlink 相当 (掃除)
+     * @return string|null 失敗理由 (null = 置換成功)
+     */
+    public static function replace(
+        string $targetPath,
+        string $contents,
+        callable $tempPathFactory,
+        callable $writer,
+        callable $reader,
+        callable $renamer,
+        callable $remover,
+    ): ?string {
+        $tempPath = $tempPathFactory();
+        if ($tempPath === false || $tempPath === '') {
+            return '一時ファイルのパスを生成できない (正本には触れていない)';
+        }
+
+        if (dirname($tempPath) !== dirname($targetPath)) {
+            return sprintf(
+                '一時ファイルが正本と別ディレクトリにある (rename が同一 FS に閉じない): %s vs %s',
+                dirname($tempPath),
+                dirname($targetPath),
+            );
+        }
+
+        $written = $writer($tempPath, $contents);
+        if ($written === false || $written !== strlen($contents)) {
+            return self::cleanup(
+                $remover,
+                $tempPath,
+                sprintf(
+                    '一時ファイルへの書き込みが完了しなかった (期待 %d バイト / 実際 %s)',
+                    strlen($contents),
+                    $written === false ? 'write 失敗' : (string) $written,
+                ),
+            );
+        }
+
+        $readBack = $reader($tempPath);
+        if ($readBack === false) {
+            return self::cleanup($remover, $tempPath, '一時ファイルを読み直せない');
+        }
+
+        try {
+            FingerprintLedger::fromJson($readBack);
+        } catch (RuntimeException $e) {
+            return self::cleanup($remover, $tempPath, '書き出した内容が指紋台帳として解釈できない: '.$e->getMessage());
+        }
+
+        if (! $renamer($tempPath, $targetPath)) {
+            return self::cleanup($remover, $tempPath, 'rename による正本の置換に失敗した');
+        }
+
+        return null;
+    }
+
+    /**
+     * 一時ファイルの掃除を試み、失敗理由を組み立てる。
+     *
+     * @param  callable(string): bool  $remover
+     */
+    private static function cleanup(callable $remover, string $tempPath, string $reason): string
+    {
+        if ($remover($tempPath)) {
+            return $reason.' (正本は変更していない)';
+        }
+
+        return $reason." (正本は変更していない。ただし一時ファイル {$tempPath} の削除にも失敗した — 手で消すこと)";
+    }
+}
diff --git a/tests/Support/TemplateDivergence/AtomicTextWriter.php b/tests/Support/TemplateDivergence/AtomicTextWriter.php
new file mode 100644
index 00000000..29d9ea99
--- /dev/null
+++ b/tests/Support/TemplateDivergence/AtomicTextWriter.php
@@ -0,0 +1,111 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use RuntimeException;
+use Throwable;
+
+/**
+ * 平文の生成物 (採用時債務一覧) の原子的置換。
+ *
+ * `AtomicLedgerWriter` と同じ 5 つの契約 (同一ディレクトリ / dirname 不一致は書き込み前に fail /
+ * 書き込みバイト長の確認 / **読み戻しの検証** / 失敗時は一時ファイルの掃除) を持つ。
+ * 違いは 2 点だけである:
+ *  1. 読み戻しの検証を**注入された検証関数**が行う (JSON 専用の
+ *     `FingerprintLedger::fromJson()` を平文に使えないため)
+ *  2. **失敗は例外で返す** (`replace(): void` + `RuntimeException`)。
+ *     移植元の `AtomicLedgerWriter::replace()` は失敗理由を戻り値で返す形なので、
+ *     **呼び出し側が戻り値を無視すると fail-open になる**。本クラスは新規なので
+ *     その形を持ち込まない (正典との差は `docs/template-divergence.md` D33 の範囲内である)
+ *
+ * ★**保証しないもの**: 原子性は**1 ファイル単位**である。異なるディレクトリの 2 生成物を
+ *   セットとして原子的に置き換えることはできない (rename が跨げない)。
+ *   片方だけが更新された状態は**突合 gate の F14 (世代識別子の突き合わせ)** が落とす。
+ */
+final class AtomicTextWriter
+{
+    /** インスタンス化しない (純関数のみ)。 */
+    private function __construct() {}
+
+    /**
+     * @param  callable(): (string|false)  $tempPathFactory  同一ディレクトリの一時パス生成
+     * @param  callable(string, string): (int|false)  $writer  file_put_contents 相当
+     * @param  callable(string): (string|false)  $reader  file_get_contents 相当
+     * @param  callable(string, string): bool  $renamer  rename 相当
+     * @param  callable(string): bool  $remover  unlink 相当 (掃除)
+     * @param  callable(string): void  $validator  読み戻した内容の検証 (不合格は例外を投げる)
+     *
+     * @throws RuntimeException どの段で失敗しても投げる (正本のバイト列は変えない)
+     */
+    public static function replace(
+        string $targetPath,
+        string $contents,
+        callable $tempPathFactory,
+        callable $writer,
+        callable $reader,
+        callable $renamer,
+        callable $remover,
+        callable $validator,
+    ): void {
+        $tempPath = $tempPathFactory();
+        if ($tempPath === false || $tempPath === '') {
+            throw new RuntimeException('一時ファイルのパスを生成できない (正本には触れていない)');
+        }
+
+        if (dirname($tempPath) !== dirname($targetPath)) {
+            throw new RuntimeException(sprintf(
+                '一時ファイルが正本と別ディレクトリにある (rename が同一 FS に閉じない): %s vs %s',
+                dirname($tempPath),
+                dirname($targetPath),
+            ));
+        }
+
+        $written = $writer($tempPath, $contents);
+        if ($written === false || $written !== strlen($contents)) {
+            self::fail(
+                $remover,
+                $tempPath,
+                sprintf(
+                    '一時ファイルへの書き込みが完了しなかった (期待 %d バイト / 実際 %s)',
+                    strlen($contents),
+                    $written === false ? 'write 失敗' : (string) $written,
+                ),
+            );
+        }
+
+        $readBack = $reader($tempPath);
+        if ($readBack === false) {
+            self::fail($remover, $tempPath, '一時ファイルを読み直せない');
+        }
+
+        try {
+            $validator((string) $readBack);
+        } catch (Throwable $e) {
+            self::fail($remover, $tempPath, '書き出した内容が検証を通らない: '.$e->getMessage());
+        }
+
+        if (! $renamer($tempPath, $targetPath)) {
+            self::fail($remover, $tempPath, 'rename による正本の置換に失敗した');
+        }
+    }
+
+    /**
+     * 一時ファイルの掃除を試み、例外を投げる。
+     *
+     * @param  callable(string): bool  $remover
+     *
+     * @throws RuntimeException 常に投げる
+     */
+    private static function fail(callable $remover, string $tempPath, string $reason): never
+    {
+        if ($remover($tempPath)) {
+            throw new RuntimeException($reason.' (正本は変更していない)');
+        }
+
+        throw new RuntimeException(
+            $reason." (正本は変更していない。ただし一時ファイル {$tempPath} の削除にも失敗した — 手で消すこと)",
+        );
+    }
+}
diff --git a/tests/Support/TemplateDivergence/ComparisonState.php b/tests/Support/TemplateDivergence/ComparisonState.php
new file mode 100644
index 00000000..cf99c158
--- /dev/null
+++ b/tests/Support/TemplateDivergence/ComparisonState.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+/**
+ * 指紋台帳のキー 1 件に対する working tree の状態 (3 値)。
+ *
+ * `role: app` の比較ドメインは**指紋台帳のキー集合のみ**なので、
+ * 「現在 shared だが台帳に無い (= 子アプリによる追加)」という状態は持たない
+ * (詳細設計 §0 変更 1。追加は逸脱ではなく拡張である)。
+ */
+enum ComparisonState
+{
+    /** 内容一致。 */
+    case Matched;
+    /** 内容相違。 */
+    case ContentMismatch;
+    /** git 追跡から消えた (削除)。 */
+    case MissingCurrent;
+}
diff --git a/tests/Support/TemplateDivergence/DivergenceLedgerRules.php b/tests/Support/TemplateDivergence/DivergenceLedgerRules.php
index 025664fc..52218475 100644
--- a/tests/Support/TemplateDivergence/DivergenceLedgerRules.php
+++ b/tests/Support/TemplateDivergence/DivergenceLedgerRules.php
@@ -11,9 +11,11 @@
  * 逸脱の登録簿の形式違反を列挙する (純関数)。
  *
  * **保証しない範囲** (誇張しない):
- *  - 実ファイルがテンプレートから逸脱したのに登録が無いことは検出しない
- *    (実体との突合は台帳リポジトリの巡回が持つ。家系の裁定 AG-159)
- *  - 内容がテンプレート準拠へ戻った登録の残置も検出しない (対象パスは実在し続けるため)
+ *  - 実ファイルがテンプレートから逸脱したのに登録が無いことは**本検査では**検出しない。
+ *    突合は `tests/Architecture/TemplateDivergenceFingerprintTest.php` が持つ (家系の正典 t1)
+ *  - 内容がテンプレート準拠へ戻った登録の残置も**本検査では**検出しない (同上)
+ *  - 突合が見ない範囲 (母集合の外・ファイル内部の逸脱・追従遅れ・採用時債務の分類) は
+ *    台帳リポジトリの巡回が引き続き担う (家系の裁定 AG-159)
  *  - 登録の中身が正しいことは見ない (空でないこと・値域に収まっていることだけを見る)
  *  - 登録エントリ領域より前の節と、エントリの中の `###` 見出し・地の文は見ない
  *  - 削除した番号の再利用は検出しない (使用済み番号の履歴を持たないため)
diff --git a/tests/Support/TemplateDivergence/FingerprintGenerationContext.php b/tests/Support/TemplateDivergence/FingerprintGenerationContext.php
new file mode 100644
index 00000000..2bf5b39d
--- /dev/null
+++ b/tests/Support/TemplateDivergence/FingerprintGenerationContext.php
@@ -0,0 +1,94 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use RuntimeException;
+
+/**
+ * 生成 1 回分の入力条件 (readonly DTO)。
+ *
+ * ★CLI が `LedgerPins` から組み立て、**service はこれだけを見る**
+ *   (service は `LedgerPins` を読まない)。こうしないと合成した正典台帳での
+ *   正常系テストが書けない (CLI が `dirname(__DIR__)` で自分のリポジトリを指す作りだと、
+ *   一時リポジトリでプロセスを起動しても出力先が本物のリポジトリになる)。
+ *
+ * ★**root を差し替える隠しオプションは CLI には作らない**。root を引数で受けるのは
+ *   service とこの DTO までで、CLI 側は `dirname(__DIR__)` 固定である。
+ *
+ * コンストラクタで落とす 6 形:
+ *  1. 期待 sha256 が 64 桁小文字 hex でない
+ *  2. 期待 source commit が 40 桁小文字 hex でない
+ *  3. 出力先 2 つが同一
+ *  4. 出力先が root 配下の**規定のパス**でない
+ *  5. 前世代台帳がある場合にその `role` が `App` でない
+ *  6. `adoptNewTemplateLedger === false` なのに前世代台帳の `generated_at_commit` が
+ *     期待 source commit と一致しない
+ *
+ * 5 は CLI からは到達しない (CLI は role ガードで**拒否 = 終了コード 3** を先に返す)。
+ * 型の側でも閉じておくための防御であり、単体テストが直接構築して固定する。
+ */
+final readonly class FingerprintGenerationContext
+{
+    public function __construct(
+        public string $root,
+        public string $expectedTemplateLedgerSha256,
+        public string $expectedSourceCommit,
+        public bool $adoptNewTemplateLedger,
+        public ?FingerprintLedger $previousLedger,
+        public string $fingerprintOutputPath,
+        public string $debtOutputPath,
+    ) {
+        if (preg_match('/^[0-9a-f]{64}$/', $expectedTemplateLedgerSha256) !== 1) {
+            throw new RuntimeException('期待する正典台帳の sha256 が 64 桁小文字 hex でない');
+        }
+        if (preg_match('/^[0-9a-f]{40}$/', $expectedSourceCommit) !== 1) {
+            throw new RuntimeException('期待する正典台帳の generated_at_commit が 40 桁小文字 hex でない');
+        }
+        if ($fingerprintOutputPath === $debtOutputPath) {
+            throw new RuntimeException('2 つの生成物の出力先が同一である');
+        }
+
+        $base = rtrim($root, '/');
+        if ($fingerprintOutputPath !== $base.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH) {
+            throw new RuntimeException('指紋台帳の出力先が規定のパスでない: '.$fingerprintOutputPath);
+        }
+        if ($debtOutputPath !== $base.'/'.AdoptionDebtInventory::INVENTORY_PATH) {
+            throw new RuntimeException('採用時債務一覧の出力先が規定のパスでない: '.$debtOutputPath);
+        }
+
+        if ($previousLedger !== null && $previousLedger->role !== LedgerRole::App) {
+            throw new RuntimeException('前世代の指紋台帳の role が app でない');
+        }
+        if (! $adoptNewTemplateLedger
+            && $previousLedger !== null
+            && $previousLedger->generatedAtCommit !== $expectedSourceCommit) {
+            throw new RuntimeException(
+                '前世代の指紋台帳の generated_at_commit が pin と一致しない '
+                    ."(前世代: {$previousLedger->generatedAtCommit} / pin: {$expectedSourceCommit})",
+            );
+        }
+    }
+
+    /** 規定の出力先を root から組み立てる (CLI と単体テストで同じ導出を使う)。 */
+    public static function forRoot(
+        string $root,
+        string $expectedTemplateLedgerSha256,
+        string $expectedSourceCommit,
+        bool $adoptNewTemplateLedger,
+        ?FingerprintLedger $previousLedger,
+    ): self {
+        $base = rtrim($root, '/');
+
+        return new self(
+            root: $root,
+            expectedTemplateLedgerSha256: $expectedTemplateLedgerSha256,
+            expectedSourceCommit: $expectedSourceCommit,
+            adoptNewTemplateLedger: $adoptNewTemplateLedger,
+            previousLedger: $previousLedger,
+            fingerprintOutputPath: $base.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH,
+            debtOutputPath: $base.'/'.AdoptionDebtInventory::INVENTORY_PATH,
+        );
+    }
+}
diff --git a/tests/Support/TemplateDivergence/FingerprintGenerationService.php b/tests/Support/TemplateDivergence/FingerprintGenerationService.php
new file mode 100644
index 00000000..b80f34a5
--- /dev/null
+++ b/tests/Support/TemplateDivergence/FingerprintGenerationService.php
@@ -0,0 +1,175 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use RuntimeException;
+
+/**
+ * 生成 1 回分の判定と書き出し。
+ *
+ * ★**判定はすべてここに閉じる**。CLI (`scripts/update-template-fingerprints.php`) は
+ *   引数解析と終了コードの写像だけを持つ薄い層である。root・入力・出力先・writer・
+ *   pin の期待値をすべて引数で受けるので、**一時ディレクトリを root にして直接呼べる**
+ *   (プロセスを起動しないテストが書ける)。
+ *
+ * ★**両生成物の内容は書き込みを始める前に完全に組み立て、検証まで終える**
+ *   (組み立て中の失敗で正本に触れないため)。異なるディレクトリの 2 ファイルなので
+ *   **セット単位の原子性は主張しない** — 書き込み開始後の I/O 失敗では片方だけが
+ *   更新され得る。その状態は突合 gate の F5 / F9・F10 / **F14 (世代識別子)** の
+ *   いずれかで必ず不合格になる。とくに**件数が変わらない部分更新は F14 が検出する**
+ *   (件数 pin だけでは増減が相殺されて緑になり得る)。
+ *
+ * ★**`AtomicLedgerWriter::replace()` の戻り値を無視しない**。非 null は即座に例外にする
+ *   (戻り値を無視すると fail-open になる)。この配線は単体テストが固定する。
+ *
+ * 終了コードの写像は例外の型で決まる: `GenerationRefused` = 3 / `RuntimeException` = 1。
+ */
+final class FingerprintGenerationService
+{
+    /** インスタンス化しない (純関数のみ)。 */
+    private function __construct() {}
+
+    /**
+     * @param  string  $templateLedgerRaw  入力の正典台帳の**生バイト列**
+     * @param  list<string>  $trackedPaths  git 追跡ファイル
+     * @param  callable(string): string  $hasher  repo-relative パス => sha256
+     * @param  list<string>  $registeredTargetPaths  登録簿の全対象パス
+     * @param  int  $divergenceEntryCount  登録簿の登録件数 (報告に載せるだけ。判定には使わない)
+     * @param  array<string, string>  $existingDebt  既存の債務一覧
+     * @param  callable(string): (string|false)  $tempPathFactory  正本のパスを受けて一時パスを返す
+     * @param  callable(string, string): (int|false)  $writer
+     * @param  callable(string): (string|false)  $reader
+     * @param  callable(string, string): bool  $renamer
+     * @param  callable(string): bool  $remover
+     * @return array{
+     *     populationCount: int,
+     *     adoptionDebtCount: int,
+     *     divergenceEntryCount: int,
+     *     matched: int,
+     *     mismatched: int,
+     *     missing: int,
+     *     addedDebt: list<string>,
+     *     templateLedgerCommit: string,
+     *     seeded: bool,
+     * }
+     *
+     * @throws GenerationRefused ガードによる拒否 (終了コード 3)
+     * @throws RuntimeException 実行不能 (終了コード 1)
+     */
+    public static function generate(
+        FingerprintGenerationContext $context,
+        string $templateLedgerRaw,
+        array $trackedPaths,
+        callable $hasher,
+        array $registeredTargetPaths,
+        int $divergenceEntryCount,
+        array $existingDebt,
+        callable $tempPathFactory,
+        callable $writer,
+        callable $reader,
+        callable $renamer,
+        callable $remover,
+    ): array {
+        // --- 入力の出自 (pin との一致) ---
+        $actualSha256 = hash('sha256', $templateLedgerRaw);
+        if ($actualSha256 !== $context->expectedTemplateLedgerSha256 && ! $context->adoptNewTemplateLedger) {
+            throw new GenerationRefused(sprintf(
+                '入力の正典台帳が pin と違う (実測 %s / pin %s)。'
+                    .'台帳を載せ替えるなら --adopt-new-template-ledger を明示すること。',
+                $actualSha256,
+                $context->expectedTemplateLedgerSha256,
+            ));
+        }
+
+        // --- 入力の構造と正準形 (非正準な JSON を採用経路から通さない) ---
+        $templateLedger = FingerprintLedger::fromJson($templateLedgerRaw);
+        if ($templateLedgerRaw !== $templateLedger->toJson()) {
+            throw new RuntimeException(
+                '入力の正典台帳が正準形バイト一致でない (重複キー / 非正準な整形 / 末尾改行の欠落)。'
+                    .'正典側の生成器で作られた台帳をそのまま渡すこと。',
+            );
+        }
+        if ($templateLedger->role !== LedgerRole::Template) {
+            throw new RuntimeException('入力の正典台帳の role が template でない');
+        }
+        if ($trackedPaths === []) {
+            throw new RuntimeException('git 追跡ファイルが 0 件と算出された (実行不能として落とす)');
+        }
+
+        // --- 母集合の縮小の拒否 (同じ正典入力のまま狭めさせない) ---
+        if (! $context->adoptNewTemplateLedger && $context->previousLedger !== null) {
+            $dropped = array_values(array_diff(
+                array_keys($context->previousLedger->entries),
+                array_keys($templateLedger->entries),
+            ));
+            if ($dropped !== []) {
+                throw new GenerationRefused(
+                    '同じ正典入力のまま母集合を縮小しようとした (正典側から消えていないパス: '
+                        .implode(', ', array_slice($dropped, 0, 10)).')',
+                );
+            }
+        }
+
+        $built = AppFingerprintBuilder::build(
+            $templateLedger,
+            $trackedPaths,
+            $hasher,
+            $registeredTargetPaths,
+            $existingDebt,
+            $context->previousLedger,
+        );
+
+        // --- 生成物を書き込み前に完全に組み立て、検証まで終える ---
+        $ledgerContents = $built['ledger']->toJson();
+        if ($ledgerContents !== FingerprintLedger::fromJson($ledgerContents)->toJson()) {
+            throw new RuntimeException('組み立てた指紋台帳が正準形でない (生成器の不整合)');
+        }
+
+        $debtContents = AdoptionDebtInventory::render($templateLedger->generatedAtCommit, $built['debt']);
+        $parsedDebt = AdoptionDebtInventory::parse($debtContents);
+        if ($parsedDebt['entries'] !== $built['debt']) {
+            throw new RuntimeException('組み立てた採用時債務一覧を読み戻せない (生成器の不整合)');
+        }
+
+        // --- 書き出し (どちらも読み戻して検証してから rename する) ---
+        $reason = AtomicLedgerWriter::replace(
+            $context->fingerprintOutputPath,
+            $ledgerContents,
+            static fn (): string|false => $tempPathFactory($context->fingerprintOutputPath),
+            $writer,
+            $reader,
+            $renamer,
+            $remover,
+        );
+        if ($reason !== null) {
+            throw new RuntimeException('指紋台帳を置換できない: '.$reason);
+        }
+
+        AtomicTextWriter::replace(
+            $context->debtOutputPath,
+            $debtContents,
+            static fn (): string|false => $tempPathFactory($context->debtOutputPath),
+            $writer,
+            $reader,
+            $renamer,
+            $remover,
+            static function (string $contents): void {
+                AdoptionDebtInventory::parse($contents);
+            },
+        );
+
+        return [
+            'populationCount' => count($built['ledger']->entries),
+            'adoptionDebtCount' => count($built['debt']),
+            'divergenceEntryCount' => $divergenceEntryCount,
+            'matched' => $built['matched'],
+            'mismatched' => $built['mismatched'],
+            'missing' => $built['missing'],
+            'addedDebt' => $built['addedDebt'],
+            'templateLedgerCommit' => $templateLedger->generatedAtCommit,
+            'seeded' => $built['seeded'],
+        ];
+    }
+}
diff --git a/tests/Support/TemplateDivergence/FingerprintLedger.php b/tests/Support/TemplateDivergence/FingerprintLedger.php
new file mode 100644
index 00000000..6d841237
--- /dev/null
+++ b/tests/Support/TemplateDivergence/FingerprintLedger.php
@@ -0,0 +1,151 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use JsonException;
+use RuntimeException;
+use stdClass;
+
+/**
+ * 指紋台帳 `docs/template-fingerprints.json` の DTO と直列化。
+ *
+ * 解釈不能はすべて例外 (正典の boundary (5c)「検査自体が実行不能なら fail」)。
+ * `generated_at_commit` は情報フィールドであり **鮮度比較では比較しない** —
+ * 生成コミット自身を比較に含めると「生成時点で未存在の commit」を要求する循環になる。
+ *
+ * 正典 (laravel-claude-template) からの移植で、差は 2 点だけである
+ * (`docs/template-divergence.md` D33 に登録済み):
+ *  1. キーの書式判定を `SharedPathRules::isValidRepoRelativePath()` から
+ *     `RepoRelativePath::isValid()` へ差し替えた (規則表を持ち込まないため)
+ *  2. 解釈を**object 形で** (`json_decode($json, false)`) 行う。正典は連想配列形で解釈するため
+ *     `{"entries": []}` のような**空配列と空 object の混同を受理してしまう**。
+ *     本リポジトリは突合 gate が「entries が object であること」を負例で固定するので、
+ *     両者を型で区別できる object 形にした (過剰検出寄りへの上積み)
+ *
+ * **重複キーは本クラスでは検出できない** (`json_decode` が後勝ちで潰すため)。
+ * 検出は利用側が**正準形バイト一致** (`$raw === self::fromJson($raw)->toJson()`) を
+ * 要求することで行う (突合 gate の F1)。
+ */
+final readonly class FingerprintLedger
+{
+    public const int SCHEMA_VERSION = 1;
+
+    /** JSON の必須キー (過不足はいずれも fail)。 */
+    private const array REQUIRED_KEYS = ['schema_version', 'role', 'generated_at_commit', 'entries'];
+
+    /**
+     * @param  array<string, string>  $entries  repo-relative パス => sha256 (小文字 hex 64 桁)。キー昇順
+     */
+    public function __construct(
+        public int $schemaVersion,
+        public LedgerRole $role,
+        public string $generatedAtCommit,
+        public array $entries,
+    ) {}
+
+    /**
+     * JSON 文字列から DTO を作る。
+     *
+     * @throws RuntimeException 解釈不能なとき (5c)
+     */
+    public static function fromJson(string $json): self
+    {
+        try {
+            /** @var mixed $decoded */
+            $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
+        } catch (JsonException $e) {
+            throw new RuntimeException('指紋台帳の JSON を解釈できない: '.$e->getMessage(), previous: $e);
+        }
+
+        if (! $decoded instanceof stdClass) {
+            throw new RuntimeException('指紋台帳の最上位が object でない');
+        }
+
+        $keys = array_keys(get_object_vars($decoded));
+        sort($keys, SORT_STRING);
+        $expected = self::REQUIRED_KEYS;
+        sort($expected, SORT_STRING);
+        if ($keys !== $expected) {
+            throw new RuntimeException(
+                '指紋台帳のキー集合が正準形と一致しない (期待: '.implode(', ', $expected).')',
+            );
+        }
+
+        /** @var mixed $schemaVersion */
+        $schemaVersion = $decoded->schema_version;
+        if (! is_int($schemaVersion) || $schemaVersion !== self::SCHEMA_VERSION) {
+            throw new RuntimeException('指紋台帳の schema_version が '.self::SCHEMA_VERSION.' でない');
+        }
+
+        /** @var mixed $roleValue */
+        $roleValue = $decoded->role;
+        if (! is_string($roleValue)) {
+            throw new RuntimeException('指紋台帳の role が文字列でない');
+        }
+        $role = LedgerRole::tryFrom($roleValue);
+        if ($role === null) {
+            throw new RuntimeException("指紋台帳の role が値域外である: {$roleValue}");
+        }
+
+        /** @var mixed $commit */
+        $commit = $decoded->generated_at_commit;
+        if (! is_string($commit) || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
+            throw new RuntimeException('指紋台帳の generated_at_commit が 40 桁小文字 hex でない');
+        }
+
+        /** @var mixed $rawEntries */
+        $rawEntries = $decoded->entries;
+        if (! $rawEntries instanceof stdClass) {
+            throw new RuntimeException('指紋台帳の entries が object でない');
+        }
+
+        $entries = [];
+        /** @var mixed $hash */
+        foreach (get_object_vars($rawEntries) as $path => $hash) {
+            // 十進整数だけで出来たキーは PHP 側で int になるため文字列へ戻してから判定する
+            // (黙って候補から外さない = 共通規約 (b))
+            $pathKey = (string) $path;
+            if (! RepoRelativePath::isValid($pathKey)) {
+                throw new RuntimeException('指紋台帳のキーが repo-relative な単一ファイルパスでない: '.var_export($pathKey, true));
+            }
+            if (! is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
+                throw new RuntimeException("指紋台帳の値が sha256 hex でない: {$pathKey}");
+            }
+            $entries[$pathKey] = $hash;
+        }
+
+        $sortedKeys = array_keys($entries);
+        sort($sortedKeys, SORT_STRING);
+        if (array_keys($entries) !== $sortedKeys) {
+            throw new RuntimeException('指紋台帳の entries がキー昇順でない (生成器で再生成すること)');
+        }
+
+        return new self($schemaVersion, $role, $commit, $entries);
+    }
+
+    /** 正準形へ直列化する (キー昇順 + 4 空白インデント + 末尾改行)。 */
+    public function toJson(): string
+    {
+        $entries = $this->entries;
+        ksort($entries, SORT_STRING);
+
+        return json_encode([
+            'schema_version' => $this->schemaVersion,
+            'role' => $this->role->value,
+            'generated_at_commit' => $this->generatedAtCommit,
+            'entries' => (object) $entries,
+        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
+    }
+
+    /**
+     * 鮮度比較。`generated_at_commit` は比較しない (循環回避)。
+     */
+    public function matchesIgnoringGeneratedCommit(self $other): bool
+    {
+        return $this->schemaVersion === $other->schemaVersion
+            && $this->role === $other->role
+            && $this->entries === $other->entries;
+    }
+}
diff --git a/tests/Support/TemplateDivergence/FingerprintReconciler.php b/tests/Support/TemplateDivergence/FingerprintReconciler.php
new file mode 100644
index 00000000..763fcf48
--- /dev/null
+++ b/tests/Support/TemplateDivergence/FingerprintReconciler.php
@@ -0,0 +1,187 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use RuntimeException;
+
+/**
+ * 3a / 3b と採用時債務の規則の判定 (純関数)。
+ *
+ * 突合の本体は**集合の等式 1 本**である:
+ *   {母集合のうち不一致のパス} == ({全登録の対象パス} ∩ {母集合}) ∪ {債務一覧のパス}
+ * 等式なので ⊃ (不一致なのに未登録 = 3a) も ⊂ (一致へ戻ったのに登録が残る = 3b) も落ちる。
+ * 債務側はさらに**採用時ハッシュとの一致**まで見る (下記の 3 分岐)。
+ *
+ * ★**登録の状態 (`恒久` / `監視中`) は読まない**。状態を突合のフィルタにすると、
+ *   内容をテンプレートへ戻した後に状態だけ変えて 3b を回避できてしまう。
+ * ★結果は**種別ごとに分けて返す** (集めて使わない形を作らないため = 共通規約 (d))。
+ * ★**すべての種別を評価してから返す** (早期 return しない)。1 回の実行でどの違反も全部見える。
+ * ★**登録の対象パスの書式・実在・値域は見ない**。それは形式検査
+ *   (`TemplateDivergenceLedgerFormatTest` の TD3) の担当で、本クラスは**重複だけ**を
+ *   自分で検出する (重複は突合の正しさに直接効くため)。解析が成功していることは
+ *   利用側 gate の F13 が同じ実行の中で確かめる。
+ *
+ * ★**取り違えは黙って通さない** (fail-closed): 観測の集合が母集合と一致しない場合と、
+ *   観測の比較状態がテンプレート側ハッシュとの実際の関係と矛盾する場合は例外にする。
+ *
+ * **保証しないもの**: 粒度はファイル単位であり、ファイルの内部のどこが逸脱したかは見ない。
+ * 母集合の外 (アプリ固有ファイル / 正典側にしか無いパス) には沈黙する。
+ * 負例と正例は `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` が持つ。
+ */
+final class FingerprintReconciler
+{
+    /** インスタンス化しない (純関数のみ)。 */
+    private function __construct() {}
+
+    /**
+     * @param  array<string, PathObservation>  $observations  母集合の全キーに対する観測
+     * @param  list<array{path: string, label: string}>  $registered  全登録の対象パス
+     *                                                                (**リストで受ける**。`array<string, string>` で受けると配列構築の時点で後勝ちに潰れて
+     *                                                                同一パスの重複が見えなくなる)
+     * @param  array<string, string>  $debt  債務一覧 (パス => 採用時のアプリ側ハッシュ)
+     * @param  array<string, string>  $templateHashes  母集合のパス => 正典側ハッシュ
+     *
+     * @throws RuntimeException 観測の集合が母集合と一致しない / 観測が自己矛盾している
+     */
+    public static function reconcile(
+        array $observations,
+        array $registered,
+        array $debt,
+        array $templateHashes,
+    ): ReconciliationResult {
+        self::assertObservationsCoverPopulation($observations, $templateHashes);
+
+        // --- 登録の対象パスを数える (重複はここで見える) ---
+        $registeredCounts = [];
+        foreach ($registered as $entry) {
+            $registeredCounts[$entry['path']] = ($registeredCounts[$entry['path']] ?? 0) + 1;
+        }
+        $duplicateRegisteredPaths = array_keys(array_filter(
+            $registeredCounts,
+            static fn (int $count): bool => $count >= 2,
+        ));
+
+        // --- 検査不能 (どの種別へも畳まない) ---
+        $inspectionFailures = [];
+        foreach ($observations as $path => $observation) {
+            if ($observation->inspectionFailure !== null) {
+                $inspectionFailures[] = $path;
+            }
+        }
+
+        // --- 債務一覧の現況 ---
+        $debtPathsOutsidePopulation = [];
+        $doubleDeclaredPaths = [];
+        $resolvedDebtPaths = [];
+        $mutatedDebtPaths = [];
+        foreach ($debt as $path => $adoptionHash) {
+            if (! array_key_exists($path, $templateHashes)) {
+                // 母集合外の債務はハッシュ比較へ進めない (未定義キーで途中終了させない)
+                $debtPathsOutsidePopulation[] = $path;
+
+                continue;
+            }
+            if (array_key_exists($path, $registeredCounts)) {
+                $doubleDeclaredPaths[] = $path;
+            }
+
+            $observation = $observations[$path];
+            if ($observation->inspectionFailure !== null) {
+                continue; // 検査不能として既に報告済み
+            }
+            if ($observation->currentHash === null) {
+                $mutatedDebtPaths[] = $path; // 削除された = 採用時の姿ではない
+
+                continue;
+            }
+            if ($observation->currentHash === $adoptionHash) {
+                continue; // 採用時の姿のまま = 未解消債務として許容する
+            }
+            if ($observation->currentHash === $templateHashes[$path]) {
+                $resolvedDebtPaths[] = $path; // 一致へ戻った = 一覧から削れ
+
+                continue;
+            }
+            $mutatedDebtPaths[] = $path; // 登録を書くか、採用時の姿へ戻すか、テンプレートへ同期する
+        }
+
+        // --- 母集合 − 債務 の範囲で 3a / 3b ---
+        $unregisteredMismatches = [];
+        $staleRegistrations = [];
+        foreach ($templateHashes as $path => $templateHash) {
+            if (array_key_exists($path, $debt)) {
+                continue;
+            }
+            $observation = $observations[$path];
+            if ($observation->inspectionFailure !== null) {
+                continue;
+            }
+
+            $isRegistered = array_key_exists($path, $registeredCounts);
+            $isMatched = $observation->state === ComparisonState::Matched;
+
+            if (! $isMatched && ! $isRegistered) {
+                $unregisteredMismatches[] = $path;
+            }
+            if ($isMatched && $isRegistered) {
+                $staleRegistrations[] = $path;
+            }
+        }
+
+        return new ReconciliationResult(
+            unregisteredMismatches: self::sorted($unregisteredMismatches),
+            staleRegistrations: self::sorted($staleRegistrations),
+            resolvedDebtPaths: self::sorted($resolvedDebtPaths),
+            mutatedDebtPaths: self::sorted($mutatedDebtPaths),
+            doubleDeclaredPaths: self::sorted($doubleDeclaredPaths),
+            debtPathsOutsidePopulation: self::sorted($debtPathsOutsidePopulation),
+            duplicateRegisteredPaths: self::sorted($duplicateRegisteredPaths),
+            inspectionFailures: self::sorted($inspectionFailures),
+        );
+    }
+
+    /**
+     * 観測が母集合とちょうど一致し、比較状態が矛盾していないことを確かめる。
+     *
+     * @param  array<string, PathObservation>  $observations
+     * @param  array<string, string>  $templateHashes
+     *
+     * @throws RuntimeException
+     */
+    private static function assertObservationsCoverPopulation(array $observations, array $templateHashes): void
+    {
+        $population = self::sorted(array_keys($templateHashes));
+        $observed = self::sorted(array_keys($observations));
+
+        if ($population !== $observed) {
+            throw new RuntimeException(sprintf(
+                '観測の集合が母集合と一致しない (母集合にだけある: %s / 観測にだけある: %s)',
+                implode(', ', array_diff($population, $observed)) ?: '無し',
+                implode(', ', array_diff($observed, $population)) ?: '無し',
+            ));
+        }
+
+        foreach ($observations as $path => $observation) {
+            if ($observation->state === ComparisonState::Matched && $observation->currentHash !== $templateHashes[$path]) {
+                throw new RuntimeException("観測が一致と称しているのに正典側ハッシュと違う: {$path}");
+            }
+            if ($observation->state === ComparisonState::ContentMismatch && $observation->currentHash === $templateHashes[$path]) {
+                throw new RuntimeException("観測が相違と称しているのに正典側ハッシュと同じ: {$path}");
+            }
+        }
+    }
+
+    /**
+     * @param  list<string>|array<int|string, string>  $paths
+     * @return list<string>
+     */
+    private static function sorted(array $paths): array
+    {
+        $values = array_values($paths);
+        sort($values, SORT_STRING);
+
+        return $values;
+    }
+}
diff --git a/tests/Support/TemplateDivergence/GenerationRefused.php b/tests/Support/TemplateDivergence/GenerationRefused.php
new file mode 100644
index 00000000..112f0ba1
--- /dev/null
+++ b/tests/Support/TemplateDivergence/GenerationRefused.php
@@ -0,0 +1,23 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use RuntimeException;
+
+/**
+ * 生成器の**ガードによる拒否** (終了コード 3)。
+ *
+ * ★「実行不能」(終了コード 1) と**別の型**にしてある。同じ例外型で理由文字列だけを
+ *   変える形にすると、CLI 側の終了コードの写像が文字列一致に依存して壊れる。
+ *   拒否は「入力も環境も正しいが、やってはいけない生成を要求された」ことを表し、
+ *   実行不能は「そもそも判定できない」ことを表す。
+ *
+ * 拒否になるのは 4 経路だけである:
+ *  1. 既存のアプリ側指紋台帳が `role: template` である (子アプリで正典側の生成を走らせている)
+ *  2. 入力の正典台帳の sha256 が pin と違うのに `--adopt-new-template-ledger` が無い
+ *  3. 採用時債務一覧へ**新規パスを追加**しようとした
+ *  4. 同じ正典入力のまま母集合を**縮小**しようとした
+ */
+final class GenerationRefused extends RuntimeException {}
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
new file mode 100644
index 00000000..8b1a5cae
--- /dev/null
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+/**
+ * 逸脱の登録簿と指紋台帳の固定値 (不変の scalar 定数だけを持つ)。
+ *
+ * ★**解析・ファイル I/O・git 実行を一切持たない**。値の置き場所を 1 か所にするための型である。
+ *   Pest のテストファイルに書いた `const` は**そのファイルが読み込まれた後にしか見えない**ため、
+ *   2 つの gate (形式検査と突合) が同じ値を読むにはクラス定数である必要がある。
+ * ★**これは免除の一覧ではない**。個別のパスや D 番号を名指しして規則を免除する仕組みは
+ *   本機構のどこにも無い。
+ */
+final class LedgerPins
+{
+    /** インスタンス化しない (定数の置き場)。 */
+    private function __construct() {}
+
+    /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
+    public const int DIVERGENCE_ENTRY_COUNT = 33;
+
+    /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
+    public const int FINGERPRINT_POPULATION_COUNT = 281;
+
+    /**
+     * 採用時債務の件数。
+     *
+     * ★機械が保証するのは**無断の増減の検出**までである (一覧と本定数を同じ変更で
+     *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
+     *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
+     */
+    public const int ADOPTION_DEBT_COUNT = 174;
+
+    /** 取り込んだ正典台帳の generated_at_commit (指紋台帳の出自 pin)。 */
+    public const string TEMPLATE_LEDGER_SOURCE_COMMIT = 'a078806b0574518ddc64966f60f7d536b1338b2f';
+
+    /**
+     * 取り込んだ正典台帳ファイル自身の sha256 (生成器の入力ガード)。
+     *
+     * 取得元は laravel-claude-template の `docs/template-fingerprints.json`
+     * (読み取りコミット `0597a0c24d7fa7a054e3337704ccc97e4409b866` / 947 キー / 128420 バイト)。
+     * 別の台帳を食わせるには生成器へ `--adopt-new-template-ledger` を明示する。
+     */
+    public const string TEMPLATE_LEDGER_SOURCE_SHA256 = '0c9add21dc79429f6d80e38cfeb95736af750bd760ee9584d2e2b8a1285c0c90';
+
+    /** アプリ側の指紋台帳の置き場 (リポジトリ相対)。 */
+    public const string FINGERPRINT_LEDGER_PATH = 'docs/template-fingerprints.json';
+}
diff --git a/tests/Support/TemplateDivergence/LedgerRole.php b/tests/Support/TemplateDivergence/LedgerRole.php
new file mode 100644
index 00000000..a3d37b5a
--- /dev/null
+++ b/tests/Support/TemplateDivergence/LedgerRole.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+/**
+ * 指紋台帳の役割。検査の意味 (鮮度 / 突合) を切り替える単一の軸。
+ *
+ * - `template` = 提供元 (laravel-claude-template)。検査は「指紋台帳の鮮度」。
+ * - `app` = 生成された子アプリ。検査は裁定 (3a)/(3b) の突合。
+ *
+ * 反転はテンプレートからのアプリ生成時に行う (feature `template-init-bootstrap` の担当)。
+ */
+enum LedgerRole: string
+{
+    case Template = 'template';
+    case App = 'app';
+}
diff --git a/tests/Support/TemplateDivergence/PathObservation.php b/tests/Support/TemplateDivergence/PathObservation.php
new file mode 100644
index 00000000..21eb00d6
--- /dev/null
+++ b/tests/Support/TemplateDivergence/PathObservation.php
@@ -0,0 +1,67 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use InvalidArgumentException;
+
+/**
+ * 母集合のパス 1 件に対する観測 (readonly DTO)。
+ *
+ * ★**検査不能は `ComparisonState` の 3 値では表せない**。`MissingCurrent` へ畳むと
+ *   「検査不能を消滅へ畳まない」という不変条件そのものを破ってしまうので、
+ *   状態を **nullable** にして「状態が付かない観測 = 検査不能」を型で表す。
+ *
+ * ★許す組み合わせは**次の 4 形だけ**で、それ以外はコンストラクタで例外にする:
+ *    - `Matched`         + 64 桁 hex + null
+ *    - `ContentMismatch` + 64 桁 hex + null
+ *    - `MissingCurrent`  + null      + null   (git index / working tree から消えた)
+ *    - null              + null      + 空でない理由 (symlink / 非 regular / 読めない / hash 失敗)
+ *
+ * 落とす 7 形と、許す 4 形が構築できることは
+ * `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` が両方向で固定する。
+ */
+final readonly class PathObservation
+{
+    public function __construct(
+        public ?ComparisonState $state,
+        public ?string $currentHash,
+        public ?string $inspectionFailure,
+    ) {
+        if ($inspectionFailure !== null && $state !== null) {
+            throw new InvalidArgumentException('検査不能の観測に比較状態を付けられない (畳むと検査不能が消える)');
+        }
+
+        if ($inspectionFailure !== null) {
+            if ($inspectionFailure === '') {
+                throw new InvalidArgumentException('検査不能の理由が空文字である (理由の無い検査不能を作らない)');
+            }
+            if ($currentHash !== null) {
+                throw new InvalidArgumentException('検査不能の観測にハッシュを付けられない');
+            }
+
+            return;
+        }
+
+        if ($state === null) {
+            throw new InvalidArgumentException('比較状態も検査不能の理由も無い観測は作れない');
+        }
+
+        if ($state === ComparisonState::MissingCurrent) {
+            if ($currentHash !== null) {
+                throw new InvalidArgumentException('消滅した観測にハッシュを付けられない');
+            }
+
+            return;
+        }
+
+        if ($currentHash === null) {
+            throw new InvalidArgumentException('内容を比較した観測にはハッシュが要る');
+        }
+
+        if (preg_match('/^[0-9a-f]{64}$/', $currentHash) !== 1) {
+            throw new InvalidArgumentException('観測のハッシュが 64 桁小文字 hex でない');
+        }
+    }
+}
diff --git a/tests/Support/TemplateDivergence/ReconciliationResult.php b/tests/Support/TemplateDivergence/ReconciliationResult.php
new file mode 100644
index 00000000..9a4d6ec8
--- /dev/null
+++ b/tests/Support/TemplateDivergence/ReconciliationResult.php
@@ -0,0 +1,49 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+/**
+ * 突合の結果。**種別ごとに分けて持つ** (集めて使わない形を作らないため = 共通規約 (d))。
+ *
+ * 利用側の gate は `isClean()` で畳まず**種別ごとに個別 assert する**。
+ * どの種別を見落としているかが人のレビューで分かるようにするためである
+ * (`isClean()` は「全種別を 1 度に見たい」単体テスト側の便宜として置く)。
+ */
+final readonly class ReconciliationResult
+{
+    /**
+     * @param  list<string>  $unregisteredMismatches  3a: 不一致なのに登録も債務も無い
+     * @param  list<string>  $staleRegistrations  3b: 一致へ戻ったのに登録が残っている
+     * @param  list<string>  $resolvedDebtPaths  債務規則 (i): 一致へ戻ったのに債務一覧に残っている
+     * @param  list<string>  $mutatedDebtPaths  債務規則 (i-2): 採用時の姿から変わっている (登録するか戻す)
+     * @param  list<string>  $doubleDeclaredPaths  債務規則 (ii): 債務と登録の二重宣言
+     * @param  list<string>  $debtPathsOutsidePopulation  債務一覧に母集合外のパスがある
+     * @param  list<string>  $duplicateRegisteredPaths  同一パスを 2 つ以上の登録が挙げている
+     * @param  list<string>  $inspectionFailures  検査不能 (symlink / 非 regular file / 読めない)
+     */
+    public function __construct(
+        public array $unregisteredMismatches,
+        public array $staleRegistrations,
+        public array $resolvedDebtPaths,
+        public array $mutatedDebtPaths,
+        public array $doubleDeclaredPaths,
+        public array $debtPathsOutsidePopulation,
+        public array $duplicateRegisteredPaths,
+        public array $inspectionFailures,
+    ) {}
+
+    /** 8 種別すべてが空か。 */
+    public function isClean(): bool
+    {
+        return $this->unregisteredMismatches === []
+            && $this->staleRegistrations === []
+            && $this->resolvedDebtPaths === []
+            && $this->mutatedDebtPaths === []
+            && $this->doubleDeclaredPaths === []
+            && $this->debtPathsOutsidePopulation === []
+            && $this->duplicateRegisteredPaths === []
+            && $this->inspectionFailures === [];
+    }
+}
diff --git a/tests/Support/TemplateDivergence/RepoRelativePath.php b/tests/Support/TemplateDivergence/RepoRelativePath.php
new file mode 100644
index 00000000..577759b9
--- /dev/null
+++ b/tests/Support/TemplateDivergence/RepoRelativePath.php
@@ -0,0 +1,51 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+/**
+ * 指紋台帳のキーと登録簿の対象パスに使える「リポジトリ相対の単一ファイルパス」の判定 (純関数)。
+ *
+ * 正典 (laravel-claude-template) は同じ判定を `SharedPathRules::isValidRepoRelativePath()` に
+ * 持つが、`SharedPathRules` は**提供元の `git ls-files` を分類する規則表**であり、
+ * 本リポジトリは母集合の出典を正典が公開する指紋台帳のキーに置いたため分類規則そのものを
+ * 使わない。規則表ごと持ち込むと使われない資産になるので、書式判定だけを本クラスへ切り出した
+ * (この差は `docs/template-divergence.md` D33 に登録済み)。
+ *
+ * **判定できない形は false を返す** (呼び出し側が違反にする)。黙って候補から外さない
+ * (静的検査の共通規約 (b))。次の 8 形を明示的に落とす:
+ *  1. 空文字 / 2. 絶対パス (`/` 始まり) / 3. 要素が空 (`a//b`) /
+ *  4. `.` を要素に含む / 5. `..` を要素に含む / 6. NUL を含む /
+ *  7. 末尾が `/` (ディレクトリ表記) / 8. 制御文字を含む
+ *
+ * **保証しないもの**: 実在・追跡状態・regular file かどうかは見ない (書式だけを見る)。
+ * 実在と種別は利用側 (突合 gate の F7 / F13) が git index と `is_file` / `is_link` で判定する。
+ * Windows 形式の区切り (`\`) やドライブ表記も「単なる 1 文字」として扱う
+ * (本リポジトリの追跡パスは POSIX 区切りだけである)。
+ */
+final class RepoRelativePath
+{
+    /** インスタンス化しない (純関数のみ)。 */
+    private function __construct() {}
+
+    public static function isValid(string $path): bool
+    {
+        if ($path === '' || str_starts_with($path, '/') || str_ends_with($path, '/')) {
+            return false;
+        }
+
+        // NUL は制御文字の集合に含まれるが、切り詰め攻撃の入口なので独立して落とす
+        if (str_contains($path, "\0") || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
+            return false;
+        }
+
+        foreach (explode('/', $path) as $segment) {
+            if ($segment === '' || $segment === '.' || $segment === '..') {
+                return false;
+            }
+        }
+
+        return true;
+    }
+}
diff --git a/tests/Support/TemplateDivergence/TrackedRepositoryFiles.php b/tests/Support/TemplateDivergence/TrackedRepositoryFiles.php
new file mode 100644
index 00000000..9b6f720b
--- /dev/null
+++ b/tests/Support/TemplateDivergence/TrackedRepositoryFiles.php
@@ -0,0 +1,68 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use RuntimeException;
+use Symfony\Component\Process\Process;
+
+/**
+ * git 追跡下の全ファイルを列挙する (拡張子で絞らない)。
+ *
+ * ★`Tests\Support\TrackedPhpSourceFiles` は `-- *.php` 限定なので本用途には使えない
+ *   (母集合は拡張子を問わない全追跡ファイルである)。本クラスは
+ *   **TemplateDivergence の検査専用**であり、他 gate の走査根を置き換える主張はしない
+ *   (寄せる作業に見合う不変条件の増加が無い。AGENTS.md の単一出典要求は
+ *   「PHP 全数の走査」に向けられている)。
+ *
+ * ★**保証しないもの**:
+ *   - 未追跡ファイルは列挙しない (本機構が守る境界は commit / CI である)
+ *   - git が無い / 失敗した場合は**空を返さず例外にする** (fail-open 防止)
+ *   - index に残っているが working tree に無いパスも列挙する
+ *     (削除の検出は利用側が行う。突合 gate では `MissingCurrent` になる)
+ *   - **母集団の非空を契約にしない**。0 件が異常かどうかは利用側の gate が判定する
+ *     (突合 gate の F4 / 生成器の実行不能判定)
+ */
+final class TrackedRepositoryFiles
+{
+    /** インスタンス化しない (純関数のみ)。 */
+    private function __construct() {}
+
+    /**
+     * @return list<string> repo-relative パスの昇順 (重複なし)
+     *
+     * @throws RuntimeException git を実行できない / 失敗した / 不正なパスを返した
+     */
+    public static function all(string $root): array
+    {
+        $process = new Process(['git', 'ls-files', '-z'], $root);
+        $process->run();
+
+        if (! $process->isSuccessful()) {
+            throw new RuntimeException(
+                'git ls-files の実行に失敗した (実行不能は fail): '.trim($process->getErrorOutput()),
+            );
+        }
+
+        $paths = [];
+        foreach (explode("\0", $process->getOutput()) as $path) {
+            if ($path === '') {
+                continue;
+            }
+            if (! RepoRelativePath::isValid($path)) {
+                // 解決できない形は黙って外さず落とす (共通規約 (b))
+                throw new RuntimeException('git ls-files が単一ファイルパスでない値を返した: '.var_export($path, true));
+            }
+            if (array_key_exists($path, $paths)) {
+                throw new RuntimeException("git ls-files が同じパスを 2 回返した: {$path}");
+            }
+            $paths[$path] = true;
+        }
+
+        $result = array_keys($paths);
+        sort($result, SORT_STRING);
+
+        return $result;
+    }
+}
diff --git a/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php b/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php
new file mode 100644
index 00000000..98893d20
--- /dev/null
+++ b/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php
@@ -0,0 +1,532 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\TemplateDivergence\AdoptionDebtInventory;
+use Tests\Support\TemplateDivergence\ComparisonState;
+use Tests\Support\TemplateDivergence\FingerprintLedger;
+use Tests\Support\TemplateDivergence\FingerprintReconciler;
+use Tests\Support\TemplateDivergence\LedgerPins;
+use Tests\Support\TemplateDivergence\LedgerRole;
+use Tests\Support\TemplateDivergence\PathObservation;
+use Tests\Support\TemplateDivergence\RepoRelativePath;
+
+/*
+ * 指紋台帳の突合 (`FingerprintReconciler`) と、その入力を作る純関数
+ * (`FingerprintLedger` / `RepoRelativePath` / `PathObservation` /
+ * `AdoptionDebtInventory`) の**負例と正例**を固定する。
+ *
+ * ★負例が本テストの存在理由である (共通規約 (c))。突合 gate
+ *   (`tests/Architecture/TemplateDivergenceFingerprintTest.php`) は現物を読むだけの
+ *   薄い層なので、「検出器が何も検出できないまま緑」という状態は**ここでしか**落とせない。
+ *
+ * ★検体は文字列と配列で組み立てる。DB もファイルシステムも触らない
+ *   (実ファイルを読むのは「現物が通ること」を見る正例だけである)。
+ *
+ * ★件数の正本は各 dataset の名前である。詳細設計の「N 形」と一致していること:
+ *   `FingerprintLedger::fromJson()` = 11 形 / `RepoRelativePath::isValid()` = 8 形 /
+ *   `PathObservation` = 7 形 / `AdoptionDebtInventory` = 11 形 (読み取り失敗 1 + 内容 10) /
+ *   `FingerprintReconciler` = 8 種別。
+ *
+ * 生成器側 (`AppFingerprintBuilder` / `AtomicLedgerWriter` / `AtomicTextWriter` /
+ * `FingerprintGenerationContext` / `FingerprintGenerationService` / 実プロセス) の
+ * 負例は `tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php` が持つ。
+ */
+
+/** 検体で使う 64 桁小文字 hex (末尾の 1 文字だけを変えて別ハッシュを作る)。 */
+function fingerprintHash(string $tail = 'a'): string
+{
+    return str_repeat('0', 63).$tail;
+}
+
+/** 検体で使う 40 桁小文字 hex の commit。 */
+function fingerprintCommit(string $tail = 'a'): string
+{
+    return str_repeat('1', 39).$tail;
+}
+
+/**
+ * 正準形の指紋台帳 JSON を組み立てる (DTO の直列化をそのまま使う)。
+ *
+ * @param  array<string, string>  $entries
+ */
+function fingerprintLedgerJson(array $entries, LedgerRole $role = LedgerRole::App): string
+{
+    return (new FingerprintLedger(
+        FingerprintLedger::SCHEMA_VERSION,
+        $role,
+        fingerprintCommit(),
+        $entries,
+    ))->toJson();
+}
+
+/** 採用時債務一覧の検体を組み立てる (ヘッダ + 昇順の 2 列)。 */
+function adoptionDebtText(string $commit, string ...$lines): string
+{
+    return '# template_ledger_commit='.$commit."\n".($lines === [] ? '' : implode("\n", $lines)."\n");
+}
+
+// ---------------------------------------------------------------------------
+// FingerprintLedger::fromJson() — 11 形の負例と正例
+// ---------------------------------------------------------------------------
+
+test('負例: FingerprintLedger::fromJson() が解釈不能な指紋台帳を例外にする', function (string $json): void {
+    expect(fn (): FingerprintLedger => FingerprintLedger::fromJson($json))
+        ->toThrow(RuntimeException::class);
+})->with([
+    '1: JSON として不正' => ['{"schema_version": 1,}'],
+    '2: 最上位が object でない (空配列を含む)' => ['[]'],
+    '3: キー集合が正準形と不一致 (余分なキー)' => [
+        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":{},"extra":1}',
+    ],
+    '4: schema_version が 1 でない' => [
+        '{"schema_version":2,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":{}}',
+    ],
+    '5: role が文字列でない' => [
+        '{"schema_version":1,"role":1,"generated_at_commit":"'.str_repeat('1', 40).'","entries":{}}',
+    ],
+    '6: role が値域外' => [
+        '{"schema_version":1,"role":"library","generated_at_commit":"'.str_repeat('1', 40).'","entries":{}}',
+    ],
+    '7: generated_at_commit が 40 桁小文字 hex でない' => [
+        '{"schema_version":1,"role":"app","generated_at_commit":"ABC","entries":{}}',
+    ],
+    '8: entries が object でない (空配列を含む)' => [
+        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":[]}',
+    ],
+    '9: キーが repo-relative な単一ファイルパスでない' => [
+        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":'
+            .'{"../escape.php":"'.str_repeat('0', 64).'"}}',
+    ],
+    '10: 値が 64 桁小文字 hex でない' => [
+        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":'
+            .'{"a.php":"deadbeef"}}',
+    ],
+    '11: キーが昇順でない' => [
+        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":'
+            .'{"b.php":"'.str_repeat('0', 64).'","a.php":"'.str_repeat('0', 64).'"}}',
+    ],
+]);
+
+test('正例: FingerprintLedger::fromJson() が正準形の指紋台帳を受理する', function (): void {
+    $ledger = FingerprintLedger::fromJson(fingerprintLedgerJson([
+        'a.php' => fingerprintHash('a'),
+        'b.php' => fingerprintHash('b'),
+    ]));
+
+    expect($ledger->schemaVersion)->toBe(1)
+        ->and($ledger->role)->toBe(LedgerRole::App)
+        ->and($ledger->generatedAtCommit)->toBe(fingerprintCommit())
+        ->and($ledger->entries)->toBe(['a.php' => fingerprintHash('a'), 'b.php' => fingerprintHash('b')]);
+});
+
+test('正例: entries が空 object の指紋台帳は解釈できる (母集合の非空は gate が見る)', function (): void {
+    expect(FingerprintLedger::fromJson(fingerprintLedgerJson([]))->entries)->toBe([]);
+});
+
+test('FingerprintLedger の鮮度比較は generated_at_commit を無視する', function (): void {
+    $entries = ['a.php' => fingerprintHash()];
+    $left = new FingerprintLedger(1, LedgerRole::App, fingerprintCommit('a'), $entries);
+    $right = new FingerprintLedger(1, LedgerRole::App, fingerprintCommit('b'), $entries);
+
+    expect($left->matchesIgnoringGeneratedCommit($right))->toBeTrue()
+        ->and($left->matchesIgnoringGeneratedCommit(
+            new FingerprintLedger(1, LedgerRole::Template, fingerprintCommit('a'), $entries),
+        ))->toBeFalse();
+});
+
+// ---------------------------------------------------------------------------
+// 正準形バイト一致 (F1 の上積み) — 重複キー・整形の崩れを落とす
+// ---------------------------------------------------------------------------
+
+test('負例: 非正準な指紋台帳は解釈して直列化し直すとバイト一致しない', function (string $json): void {
+    expect($json)->not->toBe(FingerprintLedger::fromJson($json)->toJson());
+})->with([
+    '最上位キーの重複' => [
+        '{"schema_version":1,"role":"app","role":"app","generated_at_commit":"'.str_repeat('1', 40).'",'
+            .'"entries":{"a.php":"'.str_repeat('0', 64).'"}}'."\n",
+    ],
+    'entries 内のパス重複' => [
+        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":'
+            .'{"a.php":"'.str_repeat('0', 64).'","a.php":"'.str_repeat('0', 64).'"}}'."\n",
+    ],
+    '整形の崩れ (最小化された JSON)' => [
+        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":'
+            .'{"a.php":"'.str_repeat('0', 64).'"}}'."\n",
+    ],
+    '末尾改行が無い' => [
+        rtrim(fingerprintLedgerJson(['a.php' => fingerprintHash()]), "\n"),
+    ],
+]);
+
+test('正例: 現物の指紋台帳が正準形バイト一致である', function (): void {
+    $raw = file_get_contents(base_path('docs/template-fingerprints.json'));
+    expect($raw)->toBeString();
+
+    $ledger = FingerprintLedger::fromJson((string) $raw);
+
+    expect((string) $raw)->toBe($ledger->toJson())
+        ->and($ledger->role)->toBe(LedgerRole::App)
+        ->and($ledger->generatedAtCommit)->toBe(LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT)
+        ->and($ledger->entries)->toHaveCount(LedgerPins::FINGERPRINT_POPULATION_COUNT);
+});
+
+// ---------------------------------------------------------------------------
+// RepoRelativePath::isValid() — 8 形の負例と正例
+// ---------------------------------------------------------------------------
+
+test('負例: RepoRelativePath::isValid() が単一ファイルパスでない形を落とす', function (string $path): void {
+    expect(RepoRelativePath::isValid($path))->toBeFalse();
+})->with([
+    '1: 空文字' => [''],
+    '2: 絶対パス' => ['/etc/passwd'],
+    '3: 要素が空' => ['app//Example.php'],
+    '4: 要素が . ' => ['app/./Example.php'],
+    '5: 要素が ..' => ['app/../Example.php'],
+    '6: NUL を含む' => ["app/Example.php\0"],
+    '7: 末尾がスラッシュ (ディレクトリ表記)' => ['app/'],
+    '8: 制御文字を含む' => ["app/Ex\tample.php"],
+]);
+
+test('正例: RepoRelativePath::isValid() が実在する追跡パスの形を受理する', function (string $path): void {
+    expect(RepoRelativePath::isValid($path))->toBeTrue();
+})->with([
+    'tests/Pest.php',
+    '.claude/skills/app-design/SKILL.md',
+    'docs/template-divergence.md',
+    'scripts/ci/drop-test-db.php',
+    'lang/ja/auth.php',
+]);
+
+// ---------------------------------------------------------------------------
+// PathObservation — 許容 4 形 / 不正 7 形
+// ---------------------------------------------------------------------------
+
+test('正例: PathObservation が許容する 4 形はすべて構築できる', function (): void {
+    $matched = new PathObservation(ComparisonState::Matched, fingerprintHash(), null);
+    $mismatch = new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('b'), null);
+    $missing = new PathObservation(ComparisonState::MissingCurrent, null, null);
+    $failed = new PathObservation(null, null, 'symlink である');
+
+    expect($matched->state)->toBe(ComparisonState::Matched)
+        ->and($mismatch->currentHash)->toBe(fingerprintHash('b'))
+        ->and($missing->currentHash)->toBeNull()
+        ->and($failed->inspectionFailure)->toBe('symlink である')
+        ->and($failed->state)->toBeNull();
+});
+
+test('負例: PathObservation が矛盾した組み合わせを例外にする', function (?ComparisonState $state, ?string $hash, ?string $failure): void {
+    expect(fn (): PathObservation => new PathObservation($state, $hash, $failure))
+        ->toThrow(InvalidArgumentException::class);
+})->with([
+    '1: MissingCurrent にハッシュが付いている' => [ComparisonState::MissingCurrent, fingerprintHash(), null],
+    '2: MissingCurrent に検査不能の理由が付いている' => [ComparisonState::MissingCurrent, null, '読めない'],
+    '3: Matched なのにハッシュが無い' => [ComparisonState::Matched, null, null],
+    '4: ContentMismatch なのにハッシュが無い' => [ComparisonState::ContentMismatch, null, null],
+    '5: 正常状態に検査不能の理由が付いている' => [ComparisonState::Matched, null, '読めない'],
+    '6: 3 つすべて null (状態も理由も無い)' => [null, null, null],
+    '7: 検査不能の理由が空文字' => [null, null, ''],
+]);
+
+test('負例: PathObservation がハッシュの書式違反を例外にする', function (): void {
+    expect(fn (): PathObservation => new PathObservation(ComparisonState::Matched, 'DEADBEEF', null))
+        ->toThrow(InvalidArgumentException::class);
+});
+
+// ---------------------------------------------------------------------------
+// FingerprintReconciler — 8 種別を個別に発火させる / 正常入力では全種別が空
+// ---------------------------------------------------------------------------
+
+/**
+ * 突合の検体。母集合は 4 パス固定で、テンプレート側ハッシュは `t` 系である。
+ *
+ * @return array<string, string>
+ */
+function reconcilerTemplateHashes(): array
+{
+    return [
+        'kept.php' => fingerprintHash('1'),
+        'registered.php' => fingerprintHash('2'),
+        'debt.php' => fingerprintHash('3'),
+        'plain.php' => fingerprintHash('4'),
+    ];
+}
+
+/**
+ * 母集合を検体の一部だけに絞る (突合は観測と母集合がちょうど一致することを要求する)。
+ *
+ * @return array<string, string>
+ */
+function reconcilerHashesFor(string ...$paths): array
+{
+    return array_intersect_key(reconcilerTemplateHashes(), array_flip($paths));
+}
+
+test('正例: 一致・登録済み相違・採用時のままの債務だけなら 8 種別すべて空', function (): void {
+    $result = FingerprintReconciler::reconcile(
+        observations: [
+            // テンプレートと一致している (未登録・非債務でよい)
+            'kept.php' => new PathObservation(ComparisonState::Matched, fingerprintHash('1'), null),
+            // 相違だが登録簿に説明がある
+            'registered.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('9'), null),
+            // 相違かつ債務一覧にあり、採用時の姿のまま
+            'debt.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('8'), null),
+            'plain.php' => new PathObservation(ComparisonState::Matched, fingerprintHash('4'), null),
+        ],
+        registered: [['path' => 'registered.php', 'label' => 'D1']],
+        debt: ['debt.php' => fingerprintHash('8')],
+        templateHashes: reconcilerTemplateHashes(),
+    );
+
+    expect($result->isClean())->toBeTrue()
+        ->and($result->unregisteredMismatches)->toBe([])
+        ->and($result->staleRegistrations)->toBe([])
+        ->and($result->resolvedDebtPaths)->toBe([])
+        ->and($result->mutatedDebtPaths)->toBe([])
+        ->and($result->doubleDeclaredPaths)->toBe([])
+        ->and($result->debtPathsOutsidePopulation)->toBe([])
+        ->and($result->duplicateRegisteredPaths)->toBe([])
+        ->and($result->inspectionFailures)->toBe([]);
+});
+
+test('負例: 3a — 相違なのに登録も債務も無いパスを検出する', function (): void {
+    $result = FingerprintReconciler::reconcile(
+        observations: [
+            'plain.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('9'), null),
+        ],
+        registered: [],
+        debt: [],
+        templateHashes: ['plain.php' => fingerprintHash('4')],
+    );
+
+    expect($result->unregisteredMismatches)->toBe(['plain.php'])
+        ->and($result->isClean())->toBeFalse();
+});
+
+test('負例: 3a — 消えたパス (MissingCurrent) も未登録なら 3a 側へ倒れる', function (): void {
+    $result = FingerprintReconciler::reconcile(
+        observations: ['plain.php' => new PathObservation(ComparisonState::MissingCurrent, null, null)],
+        registered: [],
+        debt: [],
+        templateHashes: ['plain.php' => fingerprintHash('4')],
+    );
+
+    expect($result->unregisteredMismatches)->toBe(['plain.php'])
+        ->and($result->inspectionFailures)->toBe([]);
+});
+
+test('負例: 3b — 一致へ戻ったのに登録が残っているパスを検出する', function (): void {
+    $result = FingerprintReconciler::reconcile(
+        observations: [
+            'registered.php' => new PathObservation(ComparisonState::Matched, fingerprintHash('2'), null),
+        ],
+        registered: [['path' => 'registered.php', 'label' => 'D1']],
+        debt: [],
+        templateHashes: reconcilerHashesFor('registered.php'),
+    );
+
+    expect($result->staleRegistrations)->toBe(['registered.php']);
+});
+
+test('負例: 債務規則 (i) — 一致へ戻ったのに債務一覧に残っているパスを検出する', function (): void {
+    $result = FingerprintReconciler::reconcile(
+        observations: ['debt.php' => new PathObservation(ComparisonState::Matched, fingerprintHash('3'), null)],
+        registered: [],
+        debt: ['debt.php' => fingerprintHash('8')],
+        templateHashes: reconcilerHashesFor('debt.php'),
+    );
+
+    expect($result->resolvedDebtPaths)->toBe(['debt.php'])
+        ->and($result->mutatedDebtPaths)->toBe([]);
+});
+
+test('負例: 債務規則 (i-2) — 採用時の姿から変わった債務パスを検出する', function (): void {
+    $result = FingerprintReconciler::reconcile(
+        observations: [
+            'debt.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('7'), null),
+        ],
+        registered: [],
+        debt: ['debt.php' => fingerprintHash('8')],
+        templateHashes: reconcilerHashesFor('debt.php'),
+    );
+
+    expect($result->mutatedDebtPaths)->toBe(['debt.php'])
+        ->and($result->resolvedDebtPaths)->toBe([]);
+});
+
+test('負例: 債務規則 (i-2) — 削除された債務パスも採用時の姿から変わった扱いになる', function (): void {
+    $result = FingerprintReconciler::reconcile(
+        observations: ['debt.php' => new PathObservation(ComparisonState::MissingCurrent, null, null)],
+        registered: [],
+        debt: ['debt.php' => fingerprintHash('8')],
+        templateHashes: reconcilerHashesFor('debt.php'),
+    );
+
+    expect($result->mutatedDebtPaths)->toBe(['debt.php'])
+        ->and($result->unregisteredMismatches)->toBe([]);
+});
+
+test('負例: 債務規則 (ii) — 債務と登録の二重宣言を検出する', function (): void {
+    $result = FingerprintReconciler::reconcile(
+        observations: [
+            'debt.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('8'), null),
+        ],
+        registered: [['path' => 'debt.php', 'label' => 'D1']],
+        debt: ['debt.php' => fingerprintHash('8')],
+        templateHashes: reconcilerHashesFor('debt.php'),
+    );
+
+    expect($result->doubleDeclaredPaths)->toBe(['debt.php']);
+});
+
+test('負例: 債務一覧に母集合外のパスがあることを検出する', function (): void {
+    $result = FingerprintReconciler::reconcile(
+        observations: ['plain.php' => new PathObservation(ComparisonState::Matched, fingerprintHash('4'), null)],
+        registered: [],
+        debt: ['outside.php' => fingerprintHash('8')],
+        templateHashes: ['plain.php' => fingerprintHash('4')],
+    );
+
+    expect($result->debtPathsOutsidePopulation)->toBe(['outside.php'])
+        ->and($result->mutatedDebtPaths)->toBe([]);
+});
+
+test('負例: 同一パスを 2 つ以上の登録が挙げていることを検出する', function (): void {
+    $result = FingerprintReconciler::reconcile(
+        observations: [
+            'registered.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('9'), null),
+        ],
+        registered: [
+            ['path' => 'registered.php', 'label' => 'D1'],
+            ['path' => 'registered.php', 'label' => 'D2'],
+        ],
+        debt: [],
+        templateHashes: reconcilerHashesFor('registered.php'),
+    );
+
+    expect($result->duplicateRegisteredPaths)->toBe(['registered.php']);
+});
+
+test('負例: 検査不能の観測は登録済み・債務でも吸収されない', function (array $registered, array $debt): void {
+    $result = FingerprintReconciler::reconcile(
+        observations: ['debt.php' => new PathObservation(null, null, 'symlink である')],
+        registered: $registered,
+        debt: $debt,
+        templateHashes: reconcilerHashesFor('debt.php'),
+    );
+
+    expect($result->inspectionFailures)->toBe(['debt.php'])
+        ->and($result->unregisteredMismatches)->toBe([])
+        ->and($result->mutatedDebtPaths)->toBe([]);
+})->with([
+    '未登録・非債務' => [[], []],
+    '登録済み' => [[['path' => 'debt.php', 'label' => 'D1']], []],
+    '債務一覧にある' => [[], ['debt.php' => '0000000000000000000000000000000000000000000000000000000000000008']],
+]);
+
+test('突合はすべての種別を評価してから返す (早期 return しない)', function (): void {
+    $result = FingerprintReconciler::reconcile(
+        observations: [
+            'kept.php' => new PathObservation(null, null, '読めない'),
+            'registered.php' => new PathObservation(ComparisonState::Matched, fingerprintHash('2'), null),
+            'debt.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('7'), null),
+            'plain.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('9'), null),
+        ],
+        registered: [
+            ['path' => 'registered.php', 'label' => 'D1'],
+            ['path' => 'registered.php', 'label' => 'D2'],
+        ],
+        debt: ['debt.php' => fingerprintHash('8'), 'outside.php' => fingerprintHash('8')],
+        templateHashes: reconcilerTemplateHashes(),
+    );
+
+    expect($result->inspectionFailures)->toBe(['kept.php'])
+        ->and($result->staleRegistrations)->toBe(['registered.php'])
+        ->and($result->duplicateRegisteredPaths)->toBe(['registered.php'])
+        ->and($result->mutatedDebtPaths)->toBe(['debt.php'])
+        ->and($result->unregisteredMismatches)->toBe(['plain.php'])
+        ->and($result->debtPathsOutsidePopulation)->toBe(['outside.php']);
+});
+
+test('突合は観測の集合が母集合と一致しないと例外にする (取り違えを黙って通さない)', function (array $observations, array $templateHashes): void {
+    expect(fn (): mixed => FingerprintReconciler::reconcile(
+        observations: $observations,
+        registered: [],
+        debt: [],
+        templateHashes: $templateHashes,
+    ))->toThrow(RuntimeException::class);
+})->with([
+    '観測にだけあるパス' => [
+        ['unknown.php' => new PathObservation(ComparisonState::Matched, '0000000000000000000000000000000000000000000000000000000000000004', null)],
+        ['plain.php' => '0000000000000000000000000000000000000000000000000000000000000004'],
+    ],
+    '母集合にだけあるパス (観測が欠けている)' => [
+        [],
+        ['plain.php' => '0000000000000000000000000000000000000000000000000000000000000004'],
+    ],
+]);
+
+test('突合は観測の比較状態が正典側ハッシュと矛盾したら例外にする', function (ComparisonState $state, string $hash): void {
+    expect(fn (): mixed => FingerprintReconciler::reconcile(
+        observations: ['plain.php' => new PathObservation($state, $hash, null)],
+        registered: [],
+        debt: [],
+        templateHashes: ['plain.php' => fingerprintHash('4')],
+    ))->toThrow(RuntimeException::class);
+})->with([
+    '一致と称しているのにハッシュが違う' => [ComparisonState::Matched, fingerprintHash('9')],
+    '相違と称しているのにハッシュが同じ' => [ComparisonState::ContentMismatch, fingerprintHash('4')],
+]);
+
+// ---------------------------------------------------------------------------
+// AdoptionDebtInventory — 11 形 (読み取り失敗 1 + 内容 10) の負例と正例
+// ---------------------------------------------------------------------------
+
+test('負例: AdoptionDebtInventory::read() は一覧が読めないと例外にする (1 形目)', function (): void {
+    expect(fn (): array => AdoptionDebtInventory::read(sys_get_temp_dir().'/t236-no-such-root-'.bin2hex(random_bytes(6))))
+        ->toThrow(RuntimeException::class);
+});
+
+test('負例: AdoptionDebtInventory::parse() が壊れた一覧を例外にする', function (string $contents): void {
+    expect(fn (): array => AdoptionDebtInventory::parse($contents))->toThrow(RuntimeException::class);
+})->with([
+    '2: 空' => [''],
+    '3: 先頭行が世代識別子のヘッダでない' => ["# something-else\na.php\t".str_repeat('0', 64)."\n"],
+    '4: 末尾改行が無い' => ['# template_ledger_commit='.str_repeat('1', 40)."\na.php\t".str_repeat('0', 64)],
+    '5: 空行がある' => ['# template_ledger_commit='.str_repeat('1', 40)."\n\na.php\t".str_repeat('0', 64)."\n"],
+    '6: タブ 2 列でない' => ['# template_ledger_commit='.str_repeat('1', 40)."\na.php\n"],
+    '7: 前後に空白がある' => ['# template_ledger_commit='.str_repeat('1', 40)."\n a.php\t".str_repeat('0', 64)."\n"],
+    '8: パスの重複' => ['# template_ledger_commit='.str_repeat('1', 40)."\n"
+        ."a.php\t".str_repeat('0', 64)."\n"
+        ."a.php\t".str_repeat('0', 64)."\n", ],
+    '9: パスが単一ファイルパスでない' => ['# template_ledger_commit='.str_repeat('1', 40)."\n"
+        ."../a.php\t".str_repeat('0', 64)."\n", ],
+    '10: ハッシュが 64 桁小文字 hex でない' => ['# template_ledger_commit='.str_repeat('1', 40)."\na.php\tDEADBEEF\n"],
+    '11: パスの昇順でない' => ['# template_ledger_commit='.str_repeat('1', 40)."\n"
+        ."b.php\t".str_repeat('0', 64)."\n"
+        ."a.php\t".str_repeat('0', 64)."\n", ],
+]);
+
+test('正例: AdoptionDebtInventory::parse() がヘッダと 2 列の一覧を受理する', function (): void {
+    $parsed = AdoptionDebtInventory::parse(adoptionDebtText(
+        fingerprintCommit(),
+        "a.php\t".fingerprintHash('1'),
+        "b.php\t".fingerprintHash('2'),
+    ));
+
+    expect($parsed['templateLedgerCommit'])->toBe(fingerprintCommit())
+        ->and($parsed['entries'])->toBe(['a.php' => fingerprintHash('1'), 'b.php' => fingerprintHash('2')]);
+});
+
+test('正例: ヘッダだけの一覧 (債務 0 件) は受理する (0 件は最終目標である)', function (): void {
+    $parsed = AdoptionDebtInventory::parse(adoptionDebtText(fingerprintCommit()));
+
+    expect($parsed['entries'])->toBe([]);
+});
+
+test('正例: 現物の採用時債務一覧が読めて件数の pin と一致する', function (): void {
+    $parsed = AdoptionDebtInventory::read(base_path());
+
+    expect($parsed['entries'])->toHaveCount(LedgerPins::ADOPTION_DEBT_COUNT)
+        ->and($parsed['templateLedgerCommit'])->toBe(LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT);
+});
diff --git a/tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php b/tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php
new file mode 100644
index 00000000..bf8bad0e
--- /dev/null
+++ b/tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php
@@ -0,0 +1,928 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Process\Process;
+use Tests\Support\TemplateDivergence\AdoptionDebtInventory;
+use Tests\Support\TemplateDivergence\AppFingerprintBuilder;
+use Tests\Support\TemplateDivergence\AtomicLedgerWriter;
+use Tests\Support\TemplateDivergence\AtomicTextWriter;
+use Tests\Support\TemplateDivergence\FingerprintGenerationContext;
+use Tests\Support\TemplateDivergence\FingerprintGenerationService;
+use Tests\Support\TemplateDivergence\FingerprintLedger;
+use Tests\Support\TemplateDivergence\GenerationRefused;
+use Tests\Support\TemplateDivergence\LedgerPins;
+use Tests\Support\TemplateDivergence\LedgerRole;
+use Tests\Support\TemplateDivergence\TrackedRepositoryFiles;
+
+/*
+ * 生成器側の負例と正例を固定する — `AppFingerprintBuilder` (母集合と債務の規則) /
+ * `AtomicLedgerWriter` `AtomicTextWriter` (原子的置換) /
+ * `FingerprintGenerationContext` (入力条件の値域) /
+ * `FingerprintGenerationService` (拒否・実行不能・部分更新) / 実プロセス (引数解析)。
+ *
+ * ★**判定は一時ディレクトリを root にした service を直接呼んで確かめる**。
+ *   CLI は `dirname(__DIR__)` で自分のリポジトリを指す作りなので、プロセスを起動して
+ *   生成の成否を試すと**本物の生成物を書き換えてしまう**。
+ *   実プロセスを起動するのは**書き込み前に終了する経路だけ** (引数の欠落・未知オプション・
+ *   重複オプション・入力ファイル不在) で、そのとき本物の生成物が 1 バイトも変わらないことも見る。
+ *
+ * ★件数の正本は各 dataset の名前である。詳細設計の「N 形」と一致していること:
+ *   `AtomicLedgerWriter` / `AtomicTextWriter` = 各 8 件 (正常系 1 + 失敗 7) /
+ *   `FingerprintGenerationContext` = 6 形 / service の拒否 = 4 経路。
+ */
+
+/** 検体で使う 64 桁小文字 hex。 */
+function generatorHash(string $tail): string
+{
+    return str_repeat('0', 63).$tail;
+}
+
+/** 検体で使う 40 桁小文字 hex の commit。 */
+function generatorCommit(string $tail): string
+{
+    return str_repeat('1', 39).$tail;
+}
+
+/**
+ * 出力先 2 つのディレクトリを持つ一時 root を作る (テスト終了後も /tmp に残るだけ)。
+ */
+function generatorTempRoot(): string
+{
+    $root = sys_get_temp_dir().'/t236-gen-'.bin2hex(random_bytes(8));
+    mkdir($root.'/docs', 0o777, true);
+    mkdir($root.'/tests/Support/TemplateDivergence', 0o777, true);
+
+    return $root;
+}
+
+/**
+ * 正典側の指紋台帳 (role: template) の生バイト列。
+ *
+ * @param  array<string, string>  $entries
+ */
+function generatorTemplateRaw(array $entries, ?string $commit = null): string
+{
+    return (new FingerprintLedger(
+        FingerprintLedger::SCHEMA_VERSION,
+        LedgerRole::Template,
+        $commit ?? generatorCommit('a'),
+        $entries,
+    ))->toJson();
+}
+
+/** 実ファイルを触る I/O 一式 (service へ渡す)。 */
+function generatorIo(): array
+{
+    return [
+        'tempPathFactory' => static fn (string $targetPath): string|false => dirname($targetPath).'/.'
+            .basename($targetPath).'.'.bin2hex(random_bytes(6)).'.tmp',
+        'writer' => static fn (string $path, string $data): int|false => file_put_contents($path, $data),
+        'reader' => static fn (string $path): string|false => is_file($path) ? file_get_contents($path) : false,
+        'renamer' => static fn (string $from, string $to): bool => rename($from, $to),
+        'remover' => static fn (string $path): bool => ! is_file($path) || unlink($path),
+    ];
+}
+
+/**
+ * service を一時 root で 1 回走らせる。
+ *
+ * @param  array<string, string>  $templateEntries
+ * @param  array<string, string>  $files  repo-relative パス => 実際に置く内容
+ * @param  list<string>  $registeredTargetPaths
+ * @param  array<string, string>  $existingDebt
+ */
+function generatorRun(
+    string $root,
+    array $templateEntries,
+    array $files,
+    array $registeredTargetPaths = [],
+    array $existingDebt = [],
+    ?FingerprintLedger $previousLedger = null,
+    bool $adopt = false,
+    ?string $templateCommit = null,
+    ?callable $writer = null,
+): array {
+    foreach ($files as $relative => $contents) {
+        $absolute = $root.'/'.$relative;
+        if (! is_dir(dirname($absolute))) {
+            mkdir(dirname($absolute), 0o777, true);
+        }
+        file_put_contents($absolute, $contents);
+    }
+
+    $raw = generatorTemplateRaw($templateEntries, $templateCommit);
+    $io = generatorIo();
+
+    $context = FingerprintGenerationContext::forRoot(
+        root: $root,
+        expectedTemplateLedgerSha256: hash('sha256', $raw),
+        expectedSourceCommit: FingerprintLedger::fromJson($raw)->generatedAtCommit,
+        adoptNewTemplateLedger: $adopt,
+        previousLedger: $previousLedger,
+    );
+
+    return FingerprintGenerationService::generate(
+        context: $context,
+        templateLedgerRaw: $raw,
+        trackedPaths: array_keys($files),
+        hasher: static fn (string $relative): string => hash_file('sha256', $root.'/'.$relative) ?: '',
+        registeredTargetPaths: $registeredTargetPaths,
+        divergenceEntryCount: 32,
+        existingDebt: $existingDebt,
+        tempPathFactory: $io['tempPathFactory'],
+        writer: $writer ?? $io['writer'],
+        reader: $io['reader'],
+        renamer: $io['renamer'],
+        remover: $io['remover'],
+    );
+}
+
+// ---------------------------------------------------------------------------
+// TrackedRepositoryFiles
+// ---------------------------------------------------------------------------
+
+test('負例: TrackedRepositoryFiles は git リポジトリでない場所で例外にする (空を返さない)', function (): void {
+    $root = generatorTempRoot();
+
+    expect(fn (): array => TrackedRepositoryFiles::all($root))->toThrow(RuntimeException::class);
+});
+
+test('正例: TrackedRepositoryFiles は本リポジトリで非空の昇順一覧を返す', function (): void {
+    $paths = TrackedRepositoryFiles::all(base_path());
+
+    $sorted = $paths;
+    sort($sorted, SORT_STRING);
+
+    expect($paths)->not->toBeEmpty()
+        ->and($paths)->toBe($sorted)
+        ->and($paths)->toContain('tests/Pest.php')
+        ->and(count($paths))->toBe(count(array_unique($paths)));
+});
+
+// ---------------------------------------------------------------------------
+// AppFingerprintBuilder — 母集合と債務の規則
+// ---------------------------------------------------------------------------
+
+test('正例: 初回生成は正典キーと現在の追跡パスの積を母集合にし、未登録の相違を凍結する', function (): void {
+    $template = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('a'), [
+        'kept.php' => generatorHash('1'),
+        'moved.php' => generatorHash('2'),
+        'registered.php' => generatorHash('3'),
+        'template-only.php' => generatorHash('4'),
+    ]);
+
+    $built = AppFingerprintBuilder::build(
+        $template,
+        ['kept.php', 'moved.php', 'registered.php', 'app-only.php'],
+        static fn (string $path): string => match ($path) {
+            'kept.php' => generatorHash('1'),      // 一致
+            'moved.php' => generatorHash('9'),     // 相違・未登録 → 債務へ凍結
+            'registered.php' => generatorHash('8'), // 相違・登録済み → 債務ではない
+            default => generatorHash('0'),
+        },
+        ['registered.php'],
+        [],
+        null,
+    );
+
+    expect(array_keys($built['ledger']->entries))->toBe(['kept.php', 'moved.php', 'registered.php'])
+        ->and($built['ledger']->role)->toBe(LedgerRole::App)
+        ->and($built['ledger']->generatedAtCommit)->toBe(generatorCommit('a'))
+        ->and($built['debt'])->toBe(['moved.php' => generatorHash('9')])
+        ->and($built['matched'])->toBe(1)
+        ->and($built['mismatched'])->toBe(2)
+        ->and($built['missing'])->toBe(0)
+        ->and($built['addedDebt'])->toBe(['moved.php'])
+        ->and($built['seeded'])->toBeTrue();
+});
+
+test('正例: 2 回目以降は既存の債務の採用時ハッシュを持ち越し、解消したものを外す', function (): void {
+    $template = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('a'), [
+        'kept.php' => generatorHash('1'),
+        'resolved.php' => generatorHash('2'),
+    ]);
+    $previous = new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), [
+        'kept.php' => generatorHash('1'),
+        'resolved.php' => generatorHash('2'),
+    ]);
+
+    $built = AppFingerprintBuilder::build(
+        $template,
+        ['kept.php', 'resolved.php'],
+        static fn (string $path): string => match ($path) {
+            'kept.php' => generatorHash('7'),        // 相違のまま (採用時ハッシュを持ち越す)
+            'resolved.php' => generatorHash('2'),    // テンプレート一致へ戻った
+            default => generatorHash('0'),
+        },
+        [],
+        ['kept.php' => generatorHash('7'), 'resolved.php' => generatorHash('5')],
+        $previous,
+    );
+
+    expect($built['debt'])->toBe(['kept.php' => generatorHash('7')])
+        ->and($built['addedDebt'])->toBe([])
+        ->and($built['seeded'])->toBeFalse();
+});
+
+test('正例: 載せ替えで前世代の正典ハッシュと一致する新規債務は通る', function (): void {
+    // 前世代の正典では a.php = hash1 で、アプリもそのまま (= 一致していた)。
+    // 新しい正典で a.php = hash2 へ動いたので、アプリ側は「テンプレートが前進した」側の相違になる。
+    $template = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('b'), [
+        'a.php' => generatorHash('2'),
+    ]);
+    $previous = new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), [
+        'a.php' => generatorHash('1'),
+    ]);
+
+    $built = AppFingerprintBuilder::build(
+        $template,
+        ['a.php'],
+        static fn (string $path): string => generatorHash('1'),
+        [],
+        [],
+        $previous,
+    );
+
+    expect($built['debt'])->toBe(['a.php' => generatorHash('1')])
+        ->and($built['addedDebt'])->toBe(['a.php']);
+});
+
+test('負例: 載せ替えでも前世代の正典ハッシュと一致しない新規債務は拒否される', function (): void {
+    $template = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('b'), [
+        'a.php' => generatorHash('2'),
+    ]);
+    $previous = new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), [
+        'a.php' => generatorHash('1'),
+    ]);
+
+    expect(fn (): array => AppFingerprintBuilder::build(
+        $template,
+        ['a.php'],
+        static fn (string $path): string => generatorHash('9'), // 自分で変えた食い違い
+        [],
+        [],
+        $previous,
+    ))->toThrow(GenerationRefused::class);
+});
+
+test('正例: ローカルで消したパスは母集合に残り消滅として数えられる', function (): void {
+    $template = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('a'), [
+        'gone.php' => generatorHash('1'),
+        'here.php' => generatorHash('2'),
+    ]);
+    $previous = new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), [
+        'gone.php' => generatorHash('1'),
+        'here.php' => generatorHash('2'),
+    ]);
+
+    $built = AppFingerprintBuilder::build(
+        $template,
+        ['here.php'], // gone.php は追跡から消えた
+        static fn (string $path): string => generatorHash('2'),
+        ['gone.php'], // 登録済みなので債務へ入れる必要が無い
+        [],
+        $previous,
+    );
+
+    expect(array_keys($built['ledger']->entries))->toBe(['gone.php', 'here.php'])
+        ->and($built['missing'])->toBe(1)
+        ->and($built['debt'])->toBe([]);
+});
+
+test('正例: 正典側から消えたパスは母集合から外れる', function (): void {
+    $template = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('b'), [
+        'here.php' => generatorHash('2'),
+    ]);
+    $previous = new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), [
+        'dropped.php' => generatorHash('1'),
+        'here.php' => generatorHash('2'),
+    ]);
+
+    $built = AppFingerprintBuilder::build(
+        $template,
+        ['dropped.php', 'here.php'],
+        static fn (string $path): string => generatorHash('2'),
+        [],
+        [],
+        $previous,
+    );
+
+    expect(array_keys($built['ledger']->entries))->toBe(['here.php']);
+});
+
+test('負例: AppFingerprintBuilder が不正な入力を例外にする', function (callable $call): void {
+    expect($call)->toThrow(RuntimeException::class);
+})->with([
+    '入力の role が app である' => [fn (): array => AppFingerprintBuilder::build(
+        new FingerprintLedger(1, LedgerRole::App, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+        ['a.php'],
+        fn (string $p): string => str_repeat('0', 64),
+        [],
+        [],
+        null,
+    )],
+    '母集合が 0 件' => [fn (): array => AppFingerprintBuilder::build(
+        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+        ['b.php'],
+        fn (string $p): string => str_repeat('0', 64),
+        [],
+        [],
+        null,
+    )],
+    'ハッシュ関数が 64 桁 hex を返さない' => [fn (): array => AppFingerprintBuilder::build(
+        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+        ['a.php'],
+        fn (string $p): string => 'DEADBEEF',
+        [],
+        [],
+        null,
+    )],
+    'ハッシュ関数が失敗して例外を投げる' => [fn (): array => AppFingerprintBuilder::build(
+        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+        ['a.php'],
+        fn (string $p): string => throw new RuntimeException('読めない'),
+        [],
+        [],
+        null,
+    )],
+    '追跡パスに重複がある' => [fn (): array => AppFingerprintBuilder::build(
+        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+        ['a.php', 'a.php'],
+        fn (string $p): string => str_repeat('0', 64),
+        [],
+        [],
+        null,
+    )],
+    '追跡パスに不正な形がある' => [fn (): array => AppFingerprintBuilder::build(
+        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+        ['a.php', '../escape.php'],
+        fn (string $p): string => str_repeat('0', 64),
+        [],
+        [],
+        null,
+    )],
+    '登録の対象パスに重複がある' => [fn (): array => AppFingerprintBuilder::build(
+        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+        ['a.php'],
+        fn (string $p): string => str_repeat('0', 64),
+        ['a.php', 'a.php'],
+        [],
+        null,
+    )],
+    '既存の債務のハッシュが 64 桁 hex でない' => [fn (): array => AppFingerprintBuilder::build(
+        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+        ['a.php'],
+        fn (string $p): string => str_repeat('0', 64),
+        [],
+        ['a.php' => 'DEADBEEF'],
+        null,
+    )],
+    '前世代の台帳の role が template である' => [fn (): array => AppFingerprintBuilder::build(
+        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+        ['a.php'],
+        fn (string $p): string => str_repeat('0', 64),
+        [],
+        [],
+        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+    )],
+    '債務パスが git 追跡から消えている' => [fn (): array => AppFingerprintBuilder::build(
+        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), [
+            'a.php' => str_repeat('0', 64),
+            'b.php' => str_repeat('0', 63).'2',
+        ]),
+        ['b.php'],
+        fn (string $p): string => str_repeat('0', 63).'2',
+        [],
+        ['a.php' => str_repeat('0', 63).'9'],
+        new FingerprintLedger(1, LedgerRole::App, str_repeat('1', 40), [
+            'a.php' => str_repeat('0', 64),
+            'b.php' => str_repeat('0', 63).'2',
+        ]),
+    )],
+]);
+
+test('負例: 消えた未登録パスを債務へ追加しようとすると拒否される', function (): void {
+    expect(fn (): array => AppFingerprintBuilder::build(
+        new FingerprintLedger(1, LedgerRole::Template, generatorCommit('a'), [
+            'gone.php' => generatorHash('1'),
+            'here.php' => generatorHash('2'),
+        ]),
+        ['here.php'],
+        static fn (string $path): string => generatorHash('2'),
+        [],
+        [],
+        new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), [
+            'gone.php' => generatorHash('1'),
+            'here.php' => generatorHash('2'),
+        ]),
+    ))->toThrow(GenerationRefused::class);
+});
+
+// ---------------------------------------------------------------------------
+// AtomicLedgerWriter / AtomicTextWriter — 各 8 件 (正常系 1 + 失敗 7)
+// ---------------------------------------------------------------------------
+
+/** 置換対象の正本を用意し、元の内容を返す。 */
+function atomicTarget(string $original): string
+{
+    $dir = sys_get_temp_dir().'/t236-atomic-'.bin2hex(random_bytes(8));
+    mkdir($dir, 0o777, true);
+    $path = $dir.'/ledger.json';
+    file_put_contents($path, $original);
+
+    return $path;
+}
+
+/** 有効な指紋台帳の内容 (writer の読み戻し検証を通る)。 */
+function atomicValidJson(string $tail = 'a'): string
+{
+    return (new FingerprintLedger(1, LedgerRole::App, str_repeat('1', 40), [
+        'a.php' => str_repeat('0', 63).$tail,
+    ]))->toJson();
+}
+
+test('正例: AtomicLedgerWriter は検証を通った内容で正本を置換する', function (): void {
+    $target = atomicTarget(atomicValidJson('a'));
+    $next = atomicValidJson('b');
+    $io = generatorIo();
+
+    $reason = AtomicLedgerWriter::replace(
+        $target,
+        $next,
+        static fn (): string|false => $io['tempPathFactory']($target),
+        $io['writer'],
+        $io['reader'],
+        $io['renamer'],
+        $io['remover'],
+    );
+
+    expect($reason)->toBeNull()
+        ->and(file_get_contents($target))->toBe($next)
+        ->and(glob(dirname($target).'/.*.tmp'))->toBe([]);
+});
+
+test('負例: AtomicLedgerWriter はどの段で失敗しても正本のバイト列を変えない', function (
+    callable $tempPathFactory,
+    callable $writer,
+    callable $reader,
+    callable $renamer,
+    callable $remover,
+    string $contents,
+): void {
+    $original = atomicValidJson('a');
+    $target = atomicTarget($original);
+
+    $reason = AtomicLedgerWriter::replace(
+        $target,
+        $contents,
+        static fn (): string|false => $tempPathFactory($target),
+        $writer,
+        $reader,
+        $renamer,
+        $remover,
+    );
+
+    expect($reason)->toBeString()
+        ->and(file_get_contents($target))->toBe($original);
+})->with(fn (): array => atomicFailureDatasets());
+
+test('正例: AtomicTextWriter は検証関数を通った内容で正本を置換する', function (): void {
+    $target = atomicTarget('# template_ledger_commit='.str_repeat('1', 40)."\n");
+    $next = '# template_ledger_commit='.str_repeat('2', 40)."\n";
+    $io = generatorIo();
+
+    AtomicTextWriter::replace(
+        $target,
+        $next,
+        static fn (): string|false => $io['tempPathFactory']($target),
+        $io['writer'],
+        $io['reader'],
+        $io['renamer'],
+        $io['remover'],
+        static function (string $contents): void {
+            AdoptionDebtInventory::parse($contents);
+        },
+    );
+
+    expect(file_get_contents($target))->toBe($next)
+        ->and(glob(dirname($target).'/.*.tmp'))->toBe([]);
+});
+
+test('負例: AtomicTextWriter はどの段で失敗しても例外を投げ正本を変えない', function (
+    callable $tempPathFactory,
+    callable $writer,
+    callable $reader,
+    callable $renamer,
+    callable $remover,
+    string $contents,
+): void {
+    $original = '# template_ledger_commit='.str_repeat('1', 40)."\n";
+    $target = atomicTarget($original);
+
+    expect(fn (): mixed => AtomicTextWriter::replace(
+        $target,
+        $contents,
+        static fn (): string|false => $tempPathFactory($target),
+        $writer,
+        $reader,
+        $renamer,
+        $remover,
+        static function (string $c): void {
+            AdoptionDebtInventory::parse($c);
+        },
+    ))->toThrow(RuntimeException::class);
+
+    expect(file_get_contents($target))->toBe($original);
+})->with(fn (): array => atomicFailureDatasets(
+    '# template_ledger_commit='.str_repeat('3', 40)."\n",
+    'これは債務一覧として解釈できない',
+));
+
+/**
+ * 原子的置換の失敗注入 7 件。dataset 名を件数の正本とする。
+ *
+ * @return array<string, list<mixed>>
+ */
+function atomicFailureDatasets(?string $validContents = null, ?string $invalidContents = null): array
+{
+    $io = generatorIo();
+    $valid = $validContents ?? atomicValidJson('b');
+    $invalid = $invalidContents ?? '{ これは JSON ではない';
+
+    return [
+        '1: 一時パスを生成できない' => [
+            static fn (string $target): string|false => false,
+            $io['writer'], $io['reader'], $io['renamer'], $io['remover'], $valid,
+        ],
+        '2: 一時パスの dirname が正本と違う' => [
+            static fn (string $target): string|false => sys_get_temp_dir().'/t236-elsewhere.tmp',
+            $io['writer'], $io['reader'], $io['renamer'], $io['remover'], $valid,
+        ],
+        '3: 書き込みが途中で切れた' => [
+            $io['tempPathFactory'],
+            static fn (string $path, string $data): int|false => (int) file_put_contents($path, substr($data, 0, 3)),
+            $io['reader'], $io['renamer'], $io['remover'], $valid,
+        ],
+        '4: 一時ファイルを読み直せない' => [
+            $io['tempPathFactory'], $io['writer'],
+            static fn (string $path): string|false => false,
+            $io['renamer'], $io['remover'], $valid,
+        ],
+        '5: 読み戻した内容が検証を通らない' => [
+            $io['tempPathFactory'], $io['writer'], $io['reader'], $io['renamer'], $io['remover'], $invalid,
+        ],
+        '6: rename に失敗した' => [
+            $io['tempPathFactory'], $io['writer'], $io['reader'],
+            static fn (string $from, string $to): bool => false,
+            $io['remover'], $valid,
+        ],
+        '7: 失敗のうえ一時ファイルの削除にも失敗した' => [
+            $io['tempPathFactory'], $io['writer'], $io['reader'],
+            static fn (string $from, string $to): bool => false,
+            static fn (string $path): bool => false,
+            $valid,
+        ],
+    ];
+}
+
+// ---------------------------------------------------------------------------
+// FingerprintGenerationContext — 6 形
+// ---------------------------------------------------------------------------
+
+test('正例: FingerprintGenerationContext は正しい組み合わせで構築できる', function (): void {
+    $context = FingerprintGenerationContext::forRoot(
+        root: '/tmp/t236-root',
+        expectedTemplateLedgerSha256: str_repeat('a', 64),
+        expectedSourceCommit: str_repeat('1', 40),
+        adoptNewTemplateLedger: false,
+        previousLedger: new FingerprintLedger(1, LedgerRole::App, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+    );
+
+    expect($context->fingerprintOutputPath)->toBe('/tmp/t236-root/'.LedgerPins::FINGERPRINT_LEDGER_PATH)
+        ->and($context->debtOutputPath)->toBe('/tmp/t236-root/'.AdoptionDebtInventory::INVENTORY_PATH);
+});
+
+test('負例: FingerprintGenerationContext が不正な入力条件を例外にする', function (callable $call): void {
+    expect($call)->toThrow(RuntimeException::class);
+})->with([
+    '1: 期待 sha256 が 64 桁小文字 hex でない' => [fn (): FingerprintGenerationContext => FingerprintGenerationContext::forRoot(
+        '/tmp/t236-root', 'DEADBEEF', str_repeat('1', 40), false, null,
+    )],
+    '2: 期待 source commit が 40 桁小文字 hex でない' => [fn (): FingerprintGenerationContext => FingerprintGenerationContext::forRoot(
+        '/tmp/t236-root', str_repeat('a', 64), 'ABC', false, null,
+    )],
+    '3: 出力先 2 つが同一' => [fn (): FingerprintGenerationContext => new FingerprintGenerationContext(
+        root: '/tmp/t236-root',
+        expectedTemplateLedgerSha256: str_repeat('a', 64),
+        expectedSourceCommit: str_repeat('1', 40),
+        adoptNewTemplateLedger: false,
+        previousLedger: null,
+        fingerprintOutputPath: '/tmp/t236-root/docs/same.json',
+        debtOutputPath: '/tmp/t236-root/docs/same.json',
+    )],
+    '4: 出力先が規定のパスでない' => [fn (): FingerprintGenerationContext => new FingerprintGenerationContext(
+        root: '/tmp/t236-root',
+        expectedTemplateLedgerSha256: str_repeat('a', 64),
+        expectedSourceCommit: str_repeat('1', 40),
+        adoptNewTemplateLedger: false,
+        previousLedger: null,
+        fingerprintOutputPath: '/tmp/elsewhere/fingerprints.json',
+        debtOutputPath: '/tmp/t236-root/'.AdoptionDebtInventory::INVENTORY_PATH,
+    )],
+    '5: 前世代の台帳の role が template である' => [fn (): FingerprintGenerationContext => FingerprintGenerationContext::forRoot(
+        '/tmp/t236-root', str_repeat('a', 64), str_repeat('1', 40), false,
+        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+    )],
+    '6: 載せ替えでないのに前世代の commit が pin と違う' => [fn (): FingerprintGenerationContext => FingerprintGenerationContext::forRoot(
+        '/tmp/t236-root', str_repeat('a', 64), str_repeat('1', 40), false,
+        new FingerprintLedger(1, LedgerRole::App, str_repeat('2', 40), ['a.php' => str_repeat('0', 64)]),
+    )],
+]);
+
+// ---------------------------------------------------------------------------
+// FingerprintGenerationService — 拒否 4 経路 / 書き込み前失敗 / 部分更新
+// ---------------------------------------------------------------------------
+
+test('正例: service が両生成物を書き、3 つの pin 値を報告する', function (): void {
+    $root = generatorTempRoot();
+
+    $report = generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
+        files: ['a.php' => 'A', 'b.php' => 'CHANGED'],
+    );
+
+    expect($report['populationCount'])->toBe(2)
+        ->and($report['adoptionDebtCount'])->toBe(1)
+        ->and($report['divergenceEntryCount'])->toBe(32)
+        ->and($report['matched'])->toBe(1)
+        ->and($report['mismatched'])->toBe(1);
+
+    $ledger = FingerprintLedger::fromJson((string) file_get_contents($root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH));
+    $debt = AdoptionDebtInventory::parse((string) file_get_contents($root.'/'.AdoptionDebtInventory::INVENTORY_PATH));
+
+    expect($ledger->role)->toBe(LedgerRole::App)
+        ->and($ledger->entries)->toHaveCount(2)
+        ->and($debt['entries'])->toBe(['b.php' => hash('sha256', 'CHANGED')])
+        ->and($debt['templateLedgerCommit'])->toBe($ledger->generatedAtCommit);
+});
+
+test('負例: service の拒否 4 経路では生成物のバイト列が 1 ビットも変わらない', function (string $case): void {
+    $root = generatorTempRoot();
+
+    // まず正常な生成物を作る (以後これが 1 バイトも変わらないことを見る)
+    generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
+        files: ['a.php' => 'A', 'b.php' => 'CHANGED'],
+    );
+
+    $ledgerPath = $root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH;
+    $debtPath = $root.'/'.AdoptionDebtInventory::INVENTORY_PATH;
+    $ledgerBefore = (string) file_get_contents($ledgerPath);
+    $debtBefore = (string) file_get_contents($debtPath);
+    $previous = FingerprintLedger::fromJson($ledgerBefore);
+    $io = generatorIo();
+
+    $call = match ($case) {
+        // 1: 既存台帳が role: template (CLI は先に exit 3 するが、型の側でも閉じる)
+        'role' => fn (): mixed => FingerprintGenerationContext::forRoot(
+            $root, str_repeat('a', 64), str_repeat('1', 40), false,
+            new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
+        ),
+        // 2: 入力の sha256 が pin と違うのに載せ替えフラグが無い
+        'sha' => function () use ($root, $previous, $io): mixed {
+            $raw = generatorTemplateRaw(['a.php' => hash('sha256', 'A')]);
+
+            return FingerprintGenerationService::generate(
+                context: FingerprintGenerationContext::forRoot(
+                    $root, str_repeat('a', 64), $previous->generatedAtCommit, false, $previous,
+                ),
+                templateLedgerRaw: $raw,
+                trackedPaths: ['a.php'],
+                hasher: static fn (string $p): string => hash('sha256', 'A'),
+                registeredTargetPaths: [],
+                divergenceEntryCount: 32,
+                existingDebt: [],
+                tempPathFactory: $io['tempPathFactory'],
+                writer: $io['writer'],
+                reader: $io['reader'],
+                renamer: $io['renamer'],
+                remover: $io['remover'],
+            );
+        },
+        // 3: 債務へ新規パスを追加しようとした
+        'debt' => fn (): mixed => generatorRun(
+            root: $root,
+            templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
+            files: ['a.php' => 'MUTATED', 'b.php' => 'CHANGED'],
+            existingDebt: ['b.php' => hash('sha256', 'CHANGED')],
+            previousLedger: $previous,
+            templateCommit: $previous->generatedAtCommit,
+        ),
+        // 4: 同じ正典入力のまま母集合を縮小しようとした
+        'shrink' => fn (): mixed => generatorRun(
+            root: $root,
+            templateEntries: ['a.php' => hash('sha256', 'A')],
+            files: ['a.php' => 'A'],
+            previousLedger: $previous,
+            templateCommit: $previous->generatedAtCommit,
+        ),
+    };
+
+    expect($call)->toThrow(RuntimeException::class);
+
+    expect(file_get_contents($ledgerPath))->toBe($ledgerBefore)
+        ->and(file_get_contents($debtPath))->toBe($debtBefore);
+})->with(['role', 'sha', 'debt', 'shrink']);
+
+test('負例: 書き込み開始前の失敗では生成物が作られない', function (callable $call): void {
+    expect($call)->toThrow(RuntimeException::class);
+})->with([
+    '入力の JSON が壊れている' => [function (): mixed {
+        $root = generatorTempRoot();
+        $io = generatorIo();
+        $broken = '{ これは JSON ではない';
+
+        return FingerprintGenerationService::generate(
+            context: FingerprintGenerationContext::forRoot(
+                $root, hash('sha256', $broken), str_repeat('1', 40), false, null,
+            ),
+            templateLedgerRaw: $broken,
+            trackedPaths: ['a.php'],
+            hasher: static fn (string $p): string => str_repeat('0', 64),
+            registeredTargetPaths: [],
+            divergenceEntryCount: 32,
+            existingDebt: [],
+            tempPathFactory: $io['tempPathFactory'],
+            writer: $io['writer'],
+            reader: $io['reader'],
+            renamer: $io['renamer'],
+            remover: $io['remover'],
+        );
+    }],
+    '入力が正準形バイト一致でない' => [function (): mixed {
+        $root = generatorTempRoot();
+        $io = generatorIo();
+        // 末尾改行を削って非正準にする (解釈はできるが正準形と 1 バイト違う)
+        $raw = rtrim(generatorTemplateRaw(['a.php' => hash('sha256', 'A')]), "\n");
+
+        return FingerprintGenerationService::generate(
+            context: FingerprintGenerationContext::forRoot(
+                $root, hash('sha256', $raw), FingerprintLedger::fromJson($raw)->generatedAtCommit, false, null,
+            ),
+            templateLedgerRaw: $raw,
+            trackedPaths: ['a.php'],
+            hasher: static fn (string $p): string => hash('sha256', 'A'),
+            registeredTargetPaths: [],
+            divergenceEntryCount: 32,
+            existingDebt: [],
+            tempPathFactory: $io['tempPathFactory'],
+            writer: $io['writer'],
+            reader: $io['reader'],
+            renamer: $io['renamer'],
+            remover: $io['remover'],
+        );
+    }],
+    '母集合が 0 件' => [function (): mixed {
+        $root = generatorTempRoot();
+
+        return generatorRun(
+            root: $root,
+            templateEntries: ['only-in-template.php' => hash('sha256', 'A')],
+            files: ['other.php' => 'X'],
+        );
+    }],
+    '追跡ファイルが 0 件' => [function (): mixed {
+        $root = generatorTempRoot();
+        $io = generatorIo();
+        $raw = generatorTemplateRaw(['a.php' => hash('sha256', 'A')]);
+
+        return FingerprintGenerationService::generate(
+            context: FingerprintGenerationContext::forRoot(
+                $root, hash('sha256', $raw), FingerprintLedger::fromJson($raw)->generatedAtCommit, false, null,
+            ),
+            templateLedgerRaw: $raw,
+            trackedPaths: [],
+            hasher: static fn (string $p): string => hash('sha256', 'A'),
+            registeredTargetPaths: [],
+            divergenceEntryCount: 32,
+            existingDebt: [],
+            tempPathFactory: $io['tempPathFactory'],
+            writer: $io['writer'],
+            reader: $io['reader'],
+            renamer: $io['renamer'],
+            remover: $io['remover'],
+        );
+    }],
+]);
+
+test('負例: 指紋台帳の置換に失敗したら service は例外にする (戻り値を無視しない)', function (): void {
+    $root = generatorTempRoot();
+
+    expect(fn (): array => generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A')],
+        files: ['a.php' => 'A'],
+        writer: static fn (string $path, string $data): int|false => false,
+    ))->toThrow(RuntimeException::class);
+
+    expect(is_file($root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH))->toBeFalse();
+});
+
+test('部分更新の 3 状態はいずれも世代識別子の突き合わせで赤になる', function (): void {
+    $root = generatorTempRoot();
+    $ledgerPath = $root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH;
+    $debtPath = $root.'/'.AdoptionDebtInventory::INVENTORY_PATH;
+
+    // 第 1 世代
+    generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
+        files: ['a.php' => 'A', 'b.php' => 'CHANGED'],
+        templateCommit: generatorCommit('a'),
+    );
+    $firstLedger = (string) file_get_contents($ledgerPath);
+    $firstDebt = (string) file_get_contents($debtPath);
+
+    // (a) 指紋台帳だけが新世代になる状態を**失敗注入で**作る
+    //     (債務一覧の書き込みだけが失敗する writer を渡す)
+    $io = generatorIo();
+    $failed = false;
+    try {
+        generatorRun(
+            root: $root,
+            templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
+            files: ['a.php' => 'A', 'b.php' => 'CHANGED'],
+            existingDebt: ['b.php' => hash('sha256', 'CHANGED')],
+            previousLedger: FingerprintLedger::fromJson($firstLedger),
+            adopt: true,
+            templateCommit: generatorCommit('b'),
+            writer: static function (string $path, string $data) use ($io, $debtPath): int|false {
+                if (str_contains($path, basename($debtPath))) {
+                    return false;
+                }
+
+                return $io['writer']($path, $data);
+            },
+        );
+    } catch (RuntimeException) {
+        $failed = true;
+    }
+
+    expect($failed)->toBeTrue();
+
+    $judge = static fn (): bool => AdoptionDebtInventory::parse((string) file_get_contents($debtPath))['templateLedgerCommit']
+        === FingerprintLedger::fromJson((string) file_get_contents($ledgerPath))->generatedAtCommit;
+
+    expect($judge())->toBeFalse(); // (a) 指紋台帳だけ新世代
+
+    // (b) 債務一覧だけが新世代になる状態 (rename の順序では起こらないので直接作る)
+    file_put_contents($ledgerPath, $firstLedger);
+    file_put_contents($debtPath, AdoptionDebtInventory::render(generatorCommit('b'), [
+        'b.php' => hash('sha256', 'CHANGED'),
+    ]));
+
+    expect($judge())->toBeFalse();
+
+    // (c) 件数は同じで内容だけ違う部分更新 (世代が揃っていないことで落ちる)
+    file_put_contents($debtPath, AdoptionDebtInventory::render(generatorCommit('c'), [
+        'b.php' => hash('sha256', 'OTHER'),
+    ]));
+
+    expect($judge())->toBeFalse()
+        // 件数だけを見ていたら緑になってしまうことを併せて示す
+        ->and(AdoptionDebtInventory::parse((string) file_get_contents($debtPath))['entries'])
+        ->toHaveCount(count(AdoptionDebtInventory::parse($firstDebt)['entries']));
+});
+
+// ---------------------------------------------------------------------------
+// 実プロセス — 書き込み前に終了する経路だけ (本物の生成物には触れない)
+// ---------------------------------------------------------------------------
+
+test('負例: 生成器は引数が不正なら書き込み前に exit 1 して生成物を変えない', function (array $arguments): void {
+    $ledgerPath = base_path(LedgerPins::FINGERPRINT_LEDGER_PATH);
+    $debtPath = base_path(AdoptionDebtInventory::INVENTORY_PATH);
+    $ledgerBefore = (string) file_get_contents($ledgerPath);
+    $debtBefore = (string) file_get_contents($debtPath);
+
+    $process = new Process(
+        ['php', 'scripts/update-template-fingerprints.php', ...$arguments],
+        base_path(),
+    );
+    $process->run();
+
+    expect($process->getExitCode())->toBe(1, '標準エラー: '.$process->getErrorOutput())
+        ->and(file_get_contents($ledgerPath))->toBe($ledgerBefore)
+        ->and(file_get_contents($debtPath))->toBe($debtBefore);
+})->with([
+    '引数が無い' => [[]],
+    '未知のオプション' => [['--template-ledger=/dev/null', '--unknown']],
+    '--template-ledger の重複' => [['--template-ledger=/dev/null', '--template-ledger=/dev/null']],
+    '--adopt-new-template-ledger の重複' => [[
+        '--template-ledger=/dev/null', '--adopt-new-template-ledger', '--adopt-new-template-ledger',
+    ]],
+    '入力ファイルが存在しない' => [['--template-ledger=/tmp/t236-does-not-exist.json']],
+    '--template-ledger の値が空' => [['--template-ledger=']],
+]);

```

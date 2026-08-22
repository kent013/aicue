【アプリの使命 (North Star) — AGENTS.md より】

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は上に挿入済み）

【背景 — この設計が追従しようとしている家系正典】
本設計は、複数リポジトリで共有される「機能台帳 (lctl)」の feature `surface-removal-absence-gate` canonical v1 への追従設計である。正典 v1 が求める必須要素は以下の 5 つ:
(1) 撤去後の実行時の不在を固定する Feature テスト (route 名の不在・メソッド×URIの不在・クラスやテーブルの不在・実HTTPが404で副作用を持たないことを層に分けて固定する)
(2) production surface への参照の再流入を字句で止める Architecture テスト。route 名・URL 文字列・画面ファイルのパス・クラス名・テーブル名・導線の目印の5形前後を正規表現で検出し、**許可一覧は持たない (0 件固定)**
(3) 検出器自身の正しさを、わざと違反させた positive fixture と、反応してはならない negative fixture の両方で確かめる自己検証を持つこと
(4) 残すべきものまで消していないかを確認する層 (消しすぎていない層)
(5) 走査根は production surface (app/ config/ lang/ routes/ resources/) に加えて **.github/ と scripts/ を必ず含める** (撤去直後に CI 設定に参照が残り CI ジョブ 5 本が全滅した実測事故の教訓)。**スキーマ移行履歴 (database/migrations/) は走査根に含めない** (撤去した表名は移行履歴に必ず残るため原理的に赤くなる)
加えて「検査対象の列挙が腐らない形 (母集団の生成・既定拒否)」を求める。

家系の先行実装 aigenba では、正典 v1 の解釈として次の 3 点が巡回で承認されている:
(a) 「場所を並べた許可一覧」を作らない限り、「撤去した URL と同じメソッド・同じ URI を 404 で宣言している行だけを許す」ように**形で許す**のは v1 適合である
(b) 叩く URL を持たない撤去物については、実行時層を等価の別の形で定義してよい
(c) 該当しない層は「該当なし」と理由つきで宣言してよい

【aicue の現状 (triage 済み事実)】
- 撤去物の不在テストは tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php の 1 本のみ (44 行の実行時検査)
- 台帳での aicue の status は pending

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか。とくに **正典 v1 の必須要素を落としていないか** と **正典が求めていない上積みで過大化していないか** の両方を見ること
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか
8. 正典適合性: 上記 (1)〜(5) の各要素に対し、本設計のどの層が対応しているか／欠落があるかを明示的に判定すること。「該当なしと宣言する」判断が妥当かも判定すること

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: password-confirm-surface-removal-gate-v1

家系の機能台帳 lctl の feature `surface-removal-absence-gate` (canonical v1) への追従設計。

## 背景・課題

### 正典が求めていること (v1)

「撤去した表面 (route / 画面 / API / 機構) が黙って戻らないこと」を機械で守る型。正典 v1 の必須要素は 5 つ:

1. **実行時の不在層** — 撤去物が動いているアプリに実在しないことを固定する (route 名の不在 / メソッド×URI の不在 / クラス・表の不在 / 実 HTTP が 404 で副作用が無い)
2. **静的な字句走査層** — production surface へ撤去物の参照が再流入していないことを字句で 0 件固定する。**場所の許可一覧を持たない**
3. **検出器自身の自己検証** — わざと違反させた正例と、反応してはならない負例の**両方**で検出力を裏取りする
4. **消しすぎていないことの確認層** — 残すべきもの (置換先) が生きていることを別に固定する
5. **走査根に `.github/` と `scripts/` を必ず含める** — 撤去の見張りが CI 設定と運用スクリプトを見ておらず、撤去後に CI が全滅した実測事故 (motivation@668f0266) の教訓。**スキーマ移行履歴 (`database/migrations/`) は走査根に含めない** (撤去した表名は移行履歴に必ず残るため、含めると原理的に赤くなる)

加えて三角測量として、正典は「検査対象の列挙が腐らないこと」= **母集団を生成し、既定拒否にする**ことを求める。

### aicue の現状 (実読による)

撤去物の不在を固定する資産は `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` の **1 本のみ** (44 行)。中身は登録済み route を全数走査し `password.confirm` middleware を持つ route が 1 本も無いことを deny-by-default で固定する実行時検査である。

正典 v1 の 5 要素との対応:

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

- **正典の言う「実 HTTP が 404」「route 名の不在」は、この撤去物には該当しない**。該当しない層は黙って落とすのではなく「該当なし」と**理由つきで宣言する** (家系では aigenba が同型の宣言を行い、2026-08-18 の巡回が正典解釈として認めている)
- **字句走査で `password.confirm` を素朴に 0 件固定することはできない**。`config/seo.php` の route 名キー、`FortifyServiceProvider` の throttle 束縛、撤去の理由を書いた説明コメントなど、**正当な残存が実在する**。したがって「場所の許可一覧を持たない」という正典の要件は、**場所ではなく形で許す** (= middleware 登録の形だけを違反とする) ことで満たす

### aicue の他の撤去済み表面の棚卸し (追加の要否)

| # | 撤去した表面 | 出典 | 現状の担保 | 判定 |
|---|---|---|---|---|
| A | Fortify 標準 step-up 機構 (`password.confirm` middleware) | bug-hunt F-11 | 実行時層 1 本のみ | **本設計の中核。v1 へ揃える** |
| B | OCR 機能フラグ `manual.ocr_analysis_enabled` (常時有効化) | T242 (2026-08-21) | **機械の担保ゼロ**。撤去 PR の S10 が grep を人手で 1 回見ただけ。config には撤去した旨のコメントだけが残る | **本設計で追加する** |
| C | 滞留回収の旧実装 (コマンド 5 本 / クラス / メソッド宣言) | T171 | 静的 gate `RetiredRecoveryReferenceGateTest` あり。実行時層・自己検証は無い | 追加しない (別 TODO)。担保がゼロではなく、v1 化は独立に判断できる |
| D | `organization_invitations.project_role` 列 (裁定 AG-079) | D9 | 残存は `database/migrations/` のみ。正典は移行履歴を走査根から除く | **不要**。app/ 側に参照が 1 件も無く、列を戻すには migration の新設が要るため再流入経路が実質無い |
| E | worktree-local flock (D10) | D10 | グローバルロックの目録 `GlobalTestLockInventoryTest` が事実上の消しすぎ検出 | 追加しない (再流入経路が薄い) |
| F | phantom password (`Str::password(32)`) の保存 (D13) | D13 | `LoginMethodInventoryTest` / `SocialAuthTest` / `RecentAuthTest` が `hasPassword()` の真実性を固定 | 追加しない (実行時層が既に等価の担保になっている) |
| G | scripts 台帳の CI 検査 (T210) / post-commit collector (T110) | T210 / T110 | 無し | 追加しない。どちらも「置き換え先へ一本化した」変更であり、戻ると壊れる不変条件が別 gate 側にある |

→ **追加するのは B のみ**。A を v1 の標準形へ揃え、B を同じ枠組みへ相乗りさせる。C は担保がゼロでないため、本設計では棚卸し結果として記録するに留める (過大化させない)。

## 改善アイデア

**撤去物 1 件ごとに手書きする motivation 形**を採る (aigenba 形の「撤去項目の台帳から 4 層を機械駆動する」形は、対象が 2 件の aicue には過大)。ただし**走査根の列挙だけは 1 本に寄せる** (AGENTS.md「同じ列挙を 2 本持たない」)。

### 1. 走査根の単一出典を新設する

`tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php`

- 走査根: `app/` `bootstrap/` `config/` `lang/` `routes/` `resources/` に加えて **`.github/` と `scripts/`** (正典 (5))
- `database/migrations/` は**含めない**ことを docblock に理由つきで書く (正典の明文)
- **存在しない根は fail-fast**、**根ごとにファイル数の床値**を持ち 0 件は必ず違反にする (母集団が空なのに緑になる形を作らない。AGENTS.md (b) 第 3 項)
- 追跡下のファイルだけを見る (`git ls-files`)。既存の `Tests\Support\TrackedPhpSourceFiles` は拡張子 `.php` に限られ `.yml` / `.svelte` / シェルスクリプトを拾えないため、**同じ作法で拡張子を広げた兄弟**として置き、両者の関係を docblock に書く

### 2. 撤去物ごとの走査器を新設する

`tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php`

- **語の境界判定**: 撤去語を素の部分文字列一致でも正規表現の語境界でも判定しない。**前後の継続文字集合を宣言し、非対称に定義する** (AGENTS.md (e))。`password.confirm` を探すとき `password.confirm.store` / `password.confirmation` を巻き込まないために、後続の継続文字集合に `.` と英数字を含める
- **解決できない形は落とす** (AGENTS.md (b))。読めないファイル・エンコード不正は無言で候補から外さず失敗させる
- **保証しないものを docblock に書く**: 分割して連結した文字列・定数経由・動的組み立てには沈黙する

### 3. A (password.confirm 機構) を v1 標準形へ揃える

既存 `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` は**消さず・置き換えず**、層を足す:

- **層 1 (実行時の不在)**: 既存の deny-by-default 走査を維持し、**空振り検出を強化**する。「route 総数 > 0」に加えて「文字列 middleware を 1 つ以上持つ route が 1 本以上ある」ことを固定する (middleware 解決自体が壊れて全 route が空を返す形で緑になるのを防ぐ)
- **層 2 (再有効化スイッチの既定拒否)**: `fortify.features` と `fortify-options` の設定木から `confirmPassword` キーを**生成して列挙**し、**すべて false** を要求する。キーを名指しで書かないので、依存パッケージの更新で新しい `confirmPassword` キーが増えたら**既定で赤くなる**
- **層 3 (該当なしの宣言)**: 「route 名の不在」「実 HTTP 404」は、Fortify が救済 redirect と状態プローブとして同名 route を今も登録しているため**該当なし**であることを docblock に理由つきで宣言する
- **層 4 (消しすぎていない)**: 置換先 generic recent-auth が生きていることを固定する — `recent-auth.*` の route が実在し、`RequireRecentAuth` middleware を実際に持つ route が 1 本以上あること

新設する静的 gate `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php`:

- **(A) 本番面の根** (`app/` `bootstrap/` `config/` `lang/` `routes/` `resources/`): **middleware 登録の形**での `password.confirm` を 0 件固定する。場所の許可一覧は持たず、**形で許す** (route 名としての用法・throttle 束縛のキー・説明コメントは違反にしない)
- **(B) `.github/` と `scripts/`**: `password.confirm` トークンそのものを **0 件固定**する。CI 設定と運用スクリプトにこの語が現れる正当な用途は存在しないため、ここは素の 0 件でよい。正典 (5) の事故教訓が直接効く層
- **(C) 自己検証**: 正例 (違反する書き方) と負例 (反応してはならない書き方) の**両方向**を固定する。負例には AGENTS.md (e) が要求する**接頭辞つき・接尾辞つき・打ち消しつきの 3 形**を必ず置く (`password.confirmation` / `password.confirm.store` / `no-password.confirm` 等)

### 4. B (OCR 機能フラグ) の不在 gate を追加する

`tests/Architecture/OcrFeatureFlagAbsenceGateTest.php`

- **静的層**: 撤去した設定キー名・env 名・Inertia prop 名の 3 語を、上記の走査根 (`.github/` `scripts/` を含む) で **0 件固定**する。許可一覧は持たない (撤去済みであり正当な残存が無いことを実測で確認済み)
- **実行時層**: `config('manual.ocr_analysis_enabled')` が**解決できない** (未定義) ことを固定する
- **消しすぎていないことの確認**: 常時有効化の帰結 (画像が受理される) は T242 が残した既存テストが固定しており、docblock からそれを指す。同じ検査を二重に持たない
- **自己検証**: 同じ走査器を使うため、検出力の裏取りは (C) と同じ見本で共有する

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」を支える現場向けアプリで、SSO-only の現場作業者が**設定 1 つで詰む**事故 (bug-hunt F-11) の再発を、実行時 1 層だけでなく静的層・自己検証・消しすぎ検出まで揃えて塞ぐ。テンプレート取り込みや依存パッケージ更新で黙って戻る経路が実在する撤去物であり、守りの厚さが直接ユーザー体験を守る
- 正典 v1 の 5 要素をすべて満たし、aicue の台帳セルを `pending` → `implemented (v1)` にできる
- CI 設定と運用スクリプトを走査根に含めるため、家系が実測した「撤去後に CI が全滅する」型の事故を aicue でも予防できる
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
- AGENTS.md「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」に従う (テストファーストで先に赤くする / 解決できない形を落とす分岐 / 空振り検査 / docblock に走査対象と保証しないものを書く)
- 既存テストを削除・上書きしない (`PasswordConfirmMiddlewareAbsenceTest.php` は**名前を変えず**層を足す)
- 既存の担保と二重化しない — `PasskeyPackageContractTest` が `fortify-options.passkeys.confirmPassword` を名指しで pin している。本設計の層 2 は**名指しではなく生成された母集団**を見る点で役割が異なるので、その関係を docblock に書く

## スコープ外

- **aigenba 形の「撤去項目の台帳から 4 層を機械駆動する」構造**。対象が 2 件の aicue には過大 (思考原則 2)。3 件目の撤去物が来たときに再判定する
- **改名残留 (`rename-residual-name-gate`) の関心事**。`BughuntNamingResidualTest` はそちらの資産であり触らない
- **棚卸し表の C (滞留回収) の v1 化**。既に静的 gate があり担保がゼロでないため、別 TODO で扱う
- **棚卸し表の D〜G**。再流入経路が薄いか、等価の担保が既にある
- **`database/migrations/` の走査**。正典が明文で除外している
- **アプリコード・`docs/` の変更**。本設計はテスト層のみ
- **走査器の索引 (家系先行実装が持つ「走査器の書き方を検査する仕組み」) の新設**。AGENTS.md がその新設を再検討する条件を別に定めており、本設計はその条件に当たらない

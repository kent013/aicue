# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC
- ESLint 10.8 + eslint-plugin-svelte 3.22 + svelte-eslint-parser 1.8 / vitest

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策に Pest / vitest テスト）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript 型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（AGENTS.md のセキュリティ不変条件、OWASP Top 10）
10. DESIGN.md 準拠（design token 経由か、hex 直書きを増やさないか）
11. Atomic Design 準拠（atom は単機能・無状態、階層を逆流していないか、SVG 直書きを新設していないか）
12. **AGENTS.md「静的検査 (gate) と走査器の共通規約」への適合**（下に全文添付）。新設 gate が (b) fail-closed / (c) 負例と正例の両方向 / (d) 集めた結果を判定に使う / 母集団の非空 / docblock に走査対象と保証しないものを書く、を満たしているか
13. **正典 (家系の機能台帳 lctl feature) の不変条件を過不足なく満たしているか**。過大化（スコープを広げる）も過小化（4 点そろいのいずれかを落とす）も指摘対象

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## AGENTS.md 「静的検査 (gate) と走査器の共通規約」全文

**対象**: `tests/Support/` 配下の検出器 / gate の中に直接書かれた走査ロジック /
それらを使う gate (`tests/Architecture/` / `tests/js/architecture/`)。
次の 5 条を満たす。家系の機能台帳の正典 v1 をそのまま写したもので、5 条とも
**「検査は緑なのに穴が開いていた」実測事故**から出ている
(設計と既存の食い違いの棚卸しは `devnotes/20260818-0303-scanner-common-conventions/`)。

**条ごとの適用範囲**: (b)〜(d) は**該当するすべての走査**に適用する。
(a) は**クラス名・名前参照を解決する走査**、(e) は**語彙一致を判定する走査**にだけ適用する
(文字列だけを見る走査に (a) は無意味であり、名前を解決する走査に (e) は無関係である)。

- **(a) クラス参照は完全修飾名で突き合わせる**。`use` / group use / 別名つき取り込みを解いた
  完全修飾名で比べる。短名一致は別名つき取り込み 1 つで検査が黙り、末尾の要素だけの一致は
  同名の別クラスを拾う。**構文解析ライブラリの使用は必須ではない** (家系の裁定 AG-154 の (2))。
  字句走査 + 取り込み対応表でよく、条件は (b) と (c) を満たすことだけである
- **(b) 解決できない形は落とす (fail-closed)**。判定を拾いすぎる方向へ倒すのは可、
  見逃す方向へ倒すのは不可。ここでいう「落とす」は**見逃さない**という意味であり、
  正常なコードを違反と断定することではない。具体的には次の 3 つを守る。
  - **未解決を解決済みと同じ値へ混ぜない**。gate が保証すると宣言した範囲の中で参照を
    解決できなかったら、**未解決だと判別できる結果**か解析の失敗として利用側へ返し、
    gate を失敗させる。**無言で候補から外さない**
  - **保証範囲の外にする構文は docblock へ明記する**。明記したなら、その構文について
    **検出力を主張しない** (明記せずに落ちこぼすのは (b) 違反である)。
    ただし**保証範囲は走査器 1 本の docblock だけでは決まらない** — 利用側 gate の名前・
    守ると宣言した不変条件・検出力の主張まで含めて判定する。
    **走査器の限界を書き足すことは、既にある見逃しを規約適合へ変えない**。
    保証範囲の外にした構文で保護対象の操作を書ける場合、利用側 gate は
    **検出力の主張をその構文を除く形へ明示的に狭める**か、**未解決として失敗させる**かのどちらかにする
  - **「違反が 0 件」と「母集団が 0 件」を区別する**。落とすのは後者だけである。
    違反ゼロが正常な gate はいくらでもあるが、**判定に使う母集団が空**なのに緑になる形は、
    走査根の改名・ディレクトリ移動・抽出条件の綴り間違いで**走査が壊れても気付けない**。
    適用対象は「母集団の非空が不変条件である gate」で、**入力を受け取って候補を返し、
    母集団の非空を契約としない再利用可能な検出器は対象外**である
    (その場合は検出器を**使う側の gate** が母集団の非空を持つ)
- **(c) 検出力は負例で裏取りする**。わざと違反させた入力を検出できることと、
  規定どおりの入力を誤検出しないことの**両方向**を固定する
- **(d) 集めた走査結果を判定に使わない形を作らない**。収集するが誰も参照しない出力、
  数えるだけで比べない目録を作らない
- **(e) 語彙一致の否定形は区切り文字で分割したトークンの完全一致で判定する**。
  正規表現の語境界や素の部分文字列一致に頼らない。
  **何を区切りとするかは走査ごとに宣言する** (準拠実装: `tests/js/support/ds-purity.ts` が
  スタイル記述を class トークンへ割る文字集合を宣言し、その文字集合で割れない書き方は
  許可一覧へ登録できないことも併せて書いている)。
  負例には最低でも**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を置く
  (許可語の除去を素の部分文字列で書いたため、この 3 形まで一緒に消えて検出漏れになっていた、
  が本リポジトリの実測である)

### 走査器・gate を新設・変更するときに同じ PR で揃える 4 点

**発火条件**: 走査ロジック・走査対象・名前解決・判定条件・目録のいずれかを新設または変更するとき。
**コメントや docblock を実態に合わせて訂正するだけで検出範囲を変えない変更は発火しない**
(既知の不適合はその場で直さず、棚卸しに記録して別 TODO で追跡する)。

1. **負例と正例**。テストファーストで**先に赤くしてから**本体を書く (思考原則 5)。
   既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する
2. **解決できない形を落とす分岐** ((b))
3. **走査が空振りしていないことの検査**。母集団が空でないこと / 走査根がそれぞれ生きていること
   (準拠実装: `FfmpegProcessLaunchInventoryTest` の「母集団が空でない」検査、
   `PromptGuardrailTest` の「各走査根が解決でき、いずれも空でない」検査)
4. **docblock に走査対象と保証しないものを書く**。中身の正本は docblock 側に置き、
   本書へ写さない

### 本リポジトリでの置き方

- **走査根の単一出典**: git 追跡下の PHP 全数を母集団にする走査は
  `Tests\Support\TrackedPhpSourceFiles` を使う。同じ列挙を 2 本持たない。
  母集団がそれより狭い走査は自分の根を持ってよいが、**存在しない根は fail-fast** で落とす
  (準拠実装 `PrismDirectDispatchScanner::roots()`)
- **負例の置き場は 3 通りとも認める**: 見本ファイル (`tests/Architecture/fixtures/`) /
  検出器の自己検査 (`tests/Unit/Architecture/`) / gate 内の合成入力。
  どこに置いてもよいが、**gate または検出器の docblock から辿れること**。
  1 つへ寄せる作業に見合う効果が無いため寄せない (思考原則 2)

### 検出力の主張の書き方

「検査ファイルが実在する」と「検出力が裏取りされている」は**別物**である。
後者を主張する記述は根拠を**同じ行に併記**し、併記の無い記述は**検出力未確認**と読む。
**遡及して裏取りを付ける作業は求めない** (家系の裁定 AG-154 の (1))。

> **本節の保証範囲 (誇張しない)**: 本節は**人がレビュー時に適用する規約であり、
> 機械では強制しない**。走査器の書き方を検査する仕組み (家系の先行実装が持つ走査器の索引と、
> その索引を文書へ投影して整合を見張る検査) は**作っていない**。したがって本節があっても
> 「すべての gate が 5 条を満たしている」とは読めない。**満たしていない箇所は実在し**、
> `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` に記録してある。
> 索引の新設を再検討する条件は同ディレクトリの概念設計に書いてある
> (新設 gate のレビューで規約の適用漏れが見つかった / 走査器候補の棚卸しをもう一度やる必要が出た /
> 全数性を主張する棚卸しが必要になった、の 3 つ)。

---

## 正典 (lctl get_feature svelte-raw-html-sink-ban) 全文

```json
{
 "ok": true,
 "feature": "svelte-raw-html-sink-ban",
 "feature_revision": "3-dc59a9928099",
 "ledger_revision": "81f0e624363b0c707a424c0695253eb6d1536451",
 "feature_yaml": "id: svelte-raw-html-sink-ban\ntitle: 画面テンプレートで生の HTML を差し込む構文を全面的に禁じ、置き換え先の部品を配る\nstatus: active\nscope: template\nboundary: '含む: 画面テンプレートで文字列を HTML として差し込む構文の既定拒否と、その禁止が実効であることの機械的な裏取り 4 点 — (1) lint 設定で当該規則を error にすること、(2)\n  ファイル内のコメントで規則を無効化できないこと (無効化の 3 形式を負例として実際に lint を走らせて確かめる)、(3) 対象ディレクトリ配下の実ファイルに当該構文が 0 件であることの直接固定、(4)\n  唯一の正当な用途 (サーバ生成の QR を描く) に対する置き換え先の部品と、その部品が依存する応答ヘッダの指示の固定。含まない: lint / 型設定 / 整形設定の基礎そのものは eslint-svelte-ts-baseline。応答ヘッダを配る仕組み自体は\n  security-headers-csp (本 feature が固定するのは、置き換え先の部品が依存する画像取得元の指示が緩まないことだけ)。部品の粒度と設計体系の純度は atomic-design-gates。雛形へ外部由来の文字列を渡すときの防御は\n  prompt-injection-defense。正典の版 t1 の内訳は上記 (1)〜(4) の 4 点そろいである。'\ncanonical_version: t1\nsummary: 画面の部品に文字列を「HTML として」流し込む構文は、値の出どころが 1 か所でも汚れていれば script をそのまま実行してしまう。この feature は、その構文を lint\n  の規則で拒否したうえで、コメントで無効化できないこと・実ファイルに 1 件も残っていないことまで機械で確かめ、唯一の正当な用途 (サーバが作った QR の画像) には差し替え先の部品を配る。禁止だけを配ると現場は使い続けるので、代わりの手段を同時に配ることまでが\n  1 組である。\norigin:\n  repo: laravel-claude-template\n  refs:\n  - lint 設定で当該規則を error にし、対象ディレクトリではコメントによる無効化も封じた (laravel-claude-template@1dda94e で実在確認)\n  - ゲート自身の生存を確かめる専用テスト tests/js/architecture/svelte-raw-html-gate.test.ts (laravel-claude-template@1dda94e\n    で実在確認)\n  - 置き換え先の部品 resources/js/components/atoms/QrCodeImage.svelte と、置換された唯一の実在サイト resources/js/pages/Settings/Security.svelte\n    (laravel-claude-template@1dda94e で実在確認)\n  - 置き換え先が依存する応答ヘッダの指示 (画像の取得元に data URI を許す) を 2 通りの構成の両方で固定する tests/Feature/Security/SecurityHeadersTest.php\n    (laravel-claude-template@1dda94e で実在確認)\n  note: 起源はテンプレートである。もとは eslint-svelte-ts-baseline の note が「生の HTML の差し込みを XSS の防御層として独立に主張するなら別 feature\n    になる」と予告していた論点で、laravel-claude-template@1dda94e で実際に入ったものが lint 設定 1 行に留まらず、ゲート自身の生存検証・実ファイル 0 件の固定・置き換え先の部品・応答ヘッダの依存の固定という\n    4 層になったため切り出した (ledger-schema §10-A2 の例外 2 — 既存 feature の内側に入れるとその feature が別の関心事まで抱えることになる、に当たる)。判断材料はキュレーター巡回\n    2026-08-19 の所見 devnotes/20260819-2221-lctl-curate/sweep-template.md の新規起票候補 A で、家系 6 リポジトリの実読による。\ngates:\n- laravel-claude-template:tests/js/architecture/svelte-raw-html-gate.test.ts — 実際に lint を走らせて振る舞いから確かめる形。無効化の\n  3 形式を負例に持ち、許可一覧の口を持たず、参照が別ファイルへ向いている場合は落とす側へ倒す (laravel-claude-template@1dda94e)\n- laravel-claude-template:tests/Feature/Security/SecurityHeadersTest.php — 置き換え先の部品が依存する画像の取得元の指示を、2 通りの構成の両方で固定する\n  (laravel-claude-template@1dda94e)\n- laravel-claude-template:tests/js/components/atoms/QrCodeImage.test.ts と laravel-claude-template:tests/js/pages/SettingsSecurityTwoFactorQr.test.ts\n  — 置き換え先の部品と、置換された画面の実挙動 (laravel-claude-template@1dda94e)\narea: security\nprojects:\n  laravel-claude-template:\n    status: implemented\n    version: t1\n    note: 起源かつ唯一の実装である。lint 設定の .svelte 全体のブロックで当該規則を error にし、対象ディレクトリではコメントによる無効化も封じた。設定のコメントは「例外を設けるなら、その口を排除できない理由・安全境界・専用テストを含む別のセキュリティ設計としてレビューを通すこと」と書き、許可一覧を持たない方針まで明記している。ゲート自身の生存を確かめる専用テストは実際に\n      lint を走らせて振る舞いから確かめる形で、無効化の 3 形式を負例に持つ。resources/js 配下の当該構文は 0 件である (実読で全数確認)。唯一の実在サイトだった 2 要素認証の\n      QR 表示は、サーバ生成の SVG を data URI の img として描く部品への置換で消えた。部品が data URI に依存するため、応答ヘッダの画像取得元の指示も検査で固定してある。観測点\n      laravel-claude-template@1dda94e 実読。\n    verification:\n      refs:\n      - laravel-claude-template@1dda94e\n      files_touched:\n      - eslint.config.js\n      - resources/js/components/atoms/QrCodeImage.svelte\n      - resources/js/pages/Settings/Security.svelte\n      - DESIGN.md\n      tests:\n      - tests/js/architecture/svelte-raw-html-gate.test.ts\n      - tests/js/components/atoms/QrCodeImage.test.ts\n      - tests/js/pages/SettingsSecurityTwoFactorQr.test.ts\n      - tests/Feature/Security/SecurityHeadersTest.php\n      checked_by:\n        method: curator (新規起票 2026-08-19, mirrors 実読)\n        commit: 1dda94e\n  aigenba:\n    status: reviewing\n    note: 一律禁止をそのまま採れない唯一のリポジトリである。当該構文を使う .svelte は 7 ファイルで (実測)、うち 1 本はテンプレート由来と同型の 2 要素認証の QR 表示だが、残る\n      6 本は教材の本文表示・設問の表示・案内の表示・履歴の詳細といったアプリ固有の用途である。したがって置き換え先の部品を配るだけでは消せず、口ごとに「何を許し、何をどう無害化するか」を決める置換の設計が要る。これは機械では決められないので、採否は\n      aigenba 側の設計判断を待つ。観測点 aigenba@7a827d4 実読。\n  spirux:\n    status: pending\n    note: テンプレート由来の同一箇所がそのまま残存している — 2 要素認証の QR 表示で当該構文を 1 件使う (resources/js/pages/Settings/Security.svelte。observation\n      時点で実在確認)。lint 設定で当該規則を error にしているリポジトリはテンプレート以外に 0 本である (6 リポジトリの eslint.config.js を全数走査した実測)。置き換え先の部品を移植すればそのまま消せる同型の追従であり、採否の判断材料は出そろっているので\n      pending とする。観測点 spirux@1cb8f210 実読。\n  aicue:\n    status: pending\n    note: テンプレート由来の同一箇所がそのまま残存している — 2 要素認証の QR 表示で当該構文を 1 件使う (resources/js/pages/Settings/Security.svelte。observation\n      時点で実在確認)。lint 設定で当該規則を error にしていない点もテンプレートとの差である。置き換え先の部品を移植すればそのまま消せる同型の追従であり、採否の判断材料は出そろっているので\n      pending とする。観測点 aicue@03a69350 実読。\n  motivation:\n    status: pending\n    note: テンプレート由来の同一箇所がそのまま残存している — 2 要素認証の QR 表示で当該構文を 1 件使う (resources/js/pages/Settings/Security.svelte。observation\n      時点で実在確認)。ほかに当該構文の名を含むファイルが 1 本あるが、そちらは「本文は通常のテキストとして描き、この構文を使わない」と説明文で宣言しているだけで実際の使用ではない (実読で確認)。置き換え先の部品を移植すればそのまま消せる同型の追従であり、採否の判断材料は出そろっているので\n      pending とする。観測点 motivation@60f22014 実読。\n  metamovics:\n    status: implemented\n    note: 'テンプレート由来の同一箇所がそのまま残存している — 2 要素認証の QR 表示で当該構文を 1 件使う (resources/js/pages/Settings/Security.svelte。observation\n      時点で実在確認)。置き換え先の部品を移植すればそのまま消せる同型の追従であり、採否の判断材料は出そろっているので pending とする。観測点 metamovics@0ac114b7 実読。\n\n\n      【差分巡回 2026-08-20】テンプレート 0597a0c の一括取り込み (metamovics@9748106) で本 feature の機構が正典と同一内容で入った (ツリー全数照合:\n      正典 6,841 パスの全数が実在し 6,831 が blob 一致。差分 10 ファイルはすべて意図的逸脱として metamovics:D1〜D4 登録済みか shared 外)。metamovics\n      は現在アプリコードを 1 行も自作しておらず、採用の判断は個別の要件ではなくテンプレートを全量取り込む子アプリ運用そのものによる (docs/template-update.md が手順と見直し条件を持つ)。CI\n      は metamovics:D1 により自動実行されないため、implemented の意味は「機構が実在し、取り込み時に devcontainer で一度緑を確認した」までであり「CI で守られ続けている」ではない。観測点\n      metamovics@c753177 実読。'\n    verification:\n      refs:\n      - metamovics@9748106\n      - metamovics@c753177\n      files_touched:\n      - eslint.config.js\n      - resources/js/components/atoms/QrCodeImage.svelte\n      - resources/js/pages/Settings/Security.svelte\n      tests:\n      - tests/js/architecture/svelte-raw-html-gate.test.ts\n      - tests/js/components/atoms/QrCodeImage.test.ts\n      checked_by:\n        method: curator (差分巡回 2026-08-20, mirrors 実読 + ツリー全数 blob 照合)\n        commit: c753177\n",
 "design_md": null,
 "history": {
  "overview": "**位置付け**: 画面の層で効く。開発とテストの層にも掛かる (禁止の実効は lint と検査で保つ)。効くのは開発時とテスト実行時で、画面を書いた瞬間に落ちる。無いと、外部から来た文字列がそのまま HTML として画面へ流し込まれ、閲覧者の browser で script が実行される (乗っ取り・情報の持ち出し) 事故が、レビューの見落とし 1 回で成立する。\n\n何をするものか。画面の部品に文字列を「HTML として」差し込む言語構文を既定で拒否し、その禁止が本当に効いていることを 4 つの角度から機械で確かめる — 規則を error にすること、ファイル内のコメントで無効化できないこと、実ファイルに 1 件も残っていないこと、そして唯一の正当な用途 (サーバが作った QR の図形) には置き換え先の部品を配ること、である。\n\nなぜ家系に必要か。この構文は「便利な逃げ道」であるため、禁止だけを配ると現場は使い続ける。実際、家系の 4 リポジトリには 2 要素認証の QR 表示という同じ 1 箇所が、テンプレート由来のまま残っていた。置き換え先を同時に配れば 4 本ともそのまま消せるので、家系全体の到達点を 1 段上げられる。一方 aigenba だけは教材の本文表示など独自の用途を持ち、口ごとに置換の設計が要る — この「同型の追従 4 本と、設計判断が要る 1 本」に割れる形は、台帳が持つ価値の典型である。\n\nどのリポジトリでどう使われているか。実装はテンプレート 1 本で、規則の有効化・ゲート自身の生存を確かめる専用テスト・置き換え先の部品・部品が依存する応答ヘッダの指示の固定が一式で入っている。他 5 本は未追従で、うち 4 本は同型の追従、1 本 (aigenba) は設計判断待ちである。",
  "background": "もとは eslint-svelte-ts-baseline の一部として観測されていた。2026-08-16 の巡回がこのリポジトリの lint 設定を実読し、「生の HTML を差し込む記法を禁じる規則が有効化されていない」「その箇所に置かれた抑止コメントが元から効いていない」という穴 2 件を記録したうえで、note に「生の HTML の差し込みを XSS の防御層として独立に主張するなら別 feature になる」と予告していた。\n\n2026-08-19 の差分巡回で、テンプレートが laravel-claude-template@1dda94e により穴 2 件をまとめて塞いだことを確認した。入ったものは lint 設定 1 行ではなく、実際に lint を走らせて振る舞いから確かめるゲート・無効化コメントの 3 形式を負例に持つ自己検査・実ファイル 0 件の直接固定・置き換え先の部品・応答ヘッダの依存の固定という 4 層で、置き場所が lint の基礎設定ではなくなった。ledger-schema §10-A2 の例外 2 (既存 feature の内側に入れるとその feature が別の関心事まで抱えることになる) に当たると判断し、予告どおり本 feature として切り出した。\n\n切り出しにあたり、家系 6 リポジトリの画面ファイルを全数走査して追従の形を確かめた。当該構文の実サイトは spirux / aicue / motivation / metamovics に 1 件ずつ (いずれも 2 要素認証の QR 表示) 残り、aigenba は独自の用途を含む 7 ファイルで使っている。前者を pending、後者を reviewing としたのはこの実測による。eslint-svelte-ts-baseline の boundary には「規則を error にしている本数」までを残し、防御層一式は本 feature の範囲であることを明記した。",
  "work_log": "- 2026-08-19 新規起票 (キュレーター巡回)。origin と実装 1 本の観測、追従 4 本・設計判断待ち 1 本の判定 — 実装: laravel-claude-template@1dda94e / 台帳: 本コミット"
 },
 "recent_events": [
  {
   "ts": "2026-08-19T23:30:00+09:00",
   "type": "survey_recorded",
   "mode": "snapshot",
   "actor": "curator",
   "feature": "svelte-raw-html-sink-ban",
   "note": "lctl-curate 巡回 2026-08-19 の新規起票 (mirrors 実読。根拠: devnotes/20260819-2221-lctl-curate/sweep-template.md の新規起票候補 A — 生の HTML を差し込む構文の全面禁止と置き換え先の部品。既存 feature eslint-svelte-ts-baseline の note が予告していた切り出し)",
   "group": "curate-20260819-additions",
   "baseline": {
    "catalog_ref": "devnotes/20260804-0130-thorough-survey/catalog",
    "generator_version": "0.2"
   }
  },
  {
   "ts": "2026-08-19T23:02:52+09:00",
   "type": "area_assigned",
   "actor": "curator",
   "feature": "svelte-raw-html-sink-ban",
   "area": "security",
   "rationale": "画面へ流し込む値からの script 実行を塞ぐ防御層であり、被害が出るのは特定の機能領域ではなく横断的な守りである (分類規則 1 と 3)",
   "refs": [
    "devnotes/20260819-2221-lctl-curate/sweep-template.md"
   ]
  },
  {
   "ts": "2026-08-21T00:30:00+09:00",
   "type": "survey_recorded",
   "mode": "patch",
   "actor": "curator (lctl-curate 差分巡回 2026-08-20)",
   "feature": "svelte-raw-html-sink-ban",
   "note": "差分巡回 2026-08-20 (所見: devnotes/20260820-2322-lctl-curate/sweep-metamovics.md — テンプレート 0597a0c 一括取り込みの全数判定)",
   "observed": [
    {
     "project": "metamovics",
     "set": {
      "status": "implemented",
      "note": "テンプレート由来の同一箇所がそのまま残存している — 2 要素認証の QR 表示で当該構文を 1 件使う (resources/js/pages/Settings/Security.svelte。observation 時点で実在確認)。置き換え先の部品を移植すればそのまま消せる同型の追従であり、採否の判断材料は出そろっているので pending とする。観測点 metamovics@0ac114b7 実読。\n\n【差分巡回 2026-08-20】テンプレート 0597a0c の一括取り込み (metamovics@9748106) で本 feature の機構が正典と同一内容で入った (ツリー全数照合: 正典 6,841 パスの全数が実在し 6,831 が blob 一致。差分 10 ファイルはすべて意図的逸脱として metamovics:D1〜D4 登録済みか shared 外)。metamovics は現在アプリコードを 1 行も自作しておらず、採用の判断は個別の要件ではなくテンプレートを全量取り込む子アプリ運用そのものによる (docs/template-update.md が手順と見直し条件を持つ)。CI は metamovics:D1 により自動実行されないため、implemented の意味は「機構が実在し、取り込み時に devcontainer で一度緑を確認した」までであり「CI で守られ続けている」ではない。観測点 metamovics@c753177 実読。",
      "verification": {
       "refs": [
        "metamovics@9748106",
        "metamovics@c753177"
       ],
       "files_touched": [
        "eslint.config.js",
        "resources/js/components/atoms/QrCodeImage.svelte",
        "resources/js/pages/Settings/Security.svelte"
       ],
       "tests": [
        "tests/js/architecture/svelte-raw-html-gate.test.ts",
        "tests/js/components/atoms/QrCodeImage.test.ts"
       ],
       "checked_by": {
        "method": "curator (差分巡回 2026-08-20, mirrors 実読 + ツリー全数 blob 照合)",
        "commit": "c753177"
       }
      }
     }
    }
   ]
  }
 ],
 "sources": [
  "devnotes/20260819-2221-lctl-curate/sweep-template.md",
  "devnotes/20260820-2322-lctl-curate/sweep-metamovics.md",
  "digest/2026-08-19.md"
 ],
 "relations": [],
 "related_by": [],
 "mentions": {
  "outgoing": [
   "atomic-design-gates",
   "eslint-svelte-ts-baseline",
   "prompt-injection-defense",
   "security-headers-csp"
  ],
  "incoming": [
   "eslint-svelte-ts-baseline"
  ]
 }
}

```

---

## 詳細設計書

# 詳細設計: svelte-raw-html-sink-ban (家系正典 t1 への追従)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロントは Svelte 5 runes + DS token/ramp のみ（`DESIGN.md` が canonical、ds-purity テストが検出）
- component 階層は `atoms → molecules → organisms → features → templates → pages` の単方向 import

## 概念設計リファレンス

`devnotes/20260823-0017-svelte-raw-html-sink-ban/conceptual-design.md`
（Codex `gpt-5.6-terra` レビュー Round 2 で **APPROVED**）

---

## 正典が求める不変条件（全列挙）

出典: lctl `get_feature svelte-raw-html-sink-ban`
（`canonical_version: t1` / `feature_revision: 3-dc59a9928099` / `ledger_revision: 81f0e624…`）。
boundary が「正典の版 t1 の内訳は上記 (1)〜(4) の 4 点そろいである」と明記している。

| ID | 不変条件（正典の文言に対応） | 本設計での保証機構 |
|---|---|---|
| **I1** | lint 設定で当該規則 (`{@html}` の禁止) を **error** にすること | 施策 1（`eslint.config.js`）+ 施策 4 検査 A |
| **I2** | ファイル内のコメントで規則を**無効化できない**こと。無効化の **3 形式**を負例として**実際に lint を走らせて**確かめる | 施策 4 検査 B / B'（対照条件） |
| **I3** | 対象ディレクトリ配下の**実ファイルに当該構文が 0 件**であることの**直接固定** | 施策 3（唯一の実在サイトの除去）+ 施策 4 検査 C |
| **I4** | 唯一の正当な用途（サーバ生成の QR を描く）に対する**置き換え先の部品** | 施策 2（`QrCodeImage.svelte`）+ 施策 5 |
| **I5** | その部品が依存する**応答ヘッダの指示**（画像取得元に `data:` を許す）を **2 通りの構成の両方**で固定 | 施策 7（`SecurityHeadersTest.php`） |
| **I6** | **許可一覧の口を持たない**（例外を設けるなら別のセキュリティ設計としてレビューを通す） | 施策 1 のコメント宣言 + 施策 4 は exemption inventory を**持たない** |
| **I7** | gate は**参照が別ファイルへ向いている場合は落とす側へ倒す**（fail-closed） | 施策 4 検査 D/E（config 解決失敗・母集団 0 件・lint 実行失敗はすべて fail） |

**正典が「含まない」と明記しているもの**（本設計もスコープ外にする）:
lint / 型 / 整形設定の基礎そのもの（`eslint-svelte-ts-baseline`）/
応答ヘッダを配る仕組み自体（`security-headers-csp`。本 feature が固定するのは
「置き換え先の部品が依存する画像取得元の指示が緩まないこと」だけ）/
部品の粒度と設計体系の純度（`atomic-design-gates`）/
雛形へ外部由来の文字列を渡すときの防御（`prompt-injection-defense`）。

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | lint 規則 `svelte/no-at-html-tags` を error にする | `eslint.config.js` | 高 |
| 2 | 置き換え部品 `QrCodeImage` atom を新設 | `resources/js/components/atoms/QrCodeImage.svelte`（新規） | 高 |
| 3 | 唯一の実在サイトを置換し `{@html}` を除去 | `resources/js/pages/Settings/Security.svelte` | 高 |
| 4 | raw HTML sink gate を新設 | `tests/js/architecture/svelte-raw-html-gate.test.ts`（新規） | 高 |
| 5 | 部品テスト | `tests/js/components/atoms/QrCodeImage.test.ts`（新規） | 高 |
| 6 | 画面テスト（QR 表示の実挙動）と既存テストの追随 | `tests/js/pages/SettingsSecurityTwoFactorQr.test.ts`（新規）/ `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts`（既存） | 高 |
| 7 | 応答ヘッダの依存を 2 構成で pin | `tests/Feature/Security/SecurityHeadersTest.php` | 高 |
| 8 | 設計規約への追記 | `DESIGN.md` | 中 |

---

## 施策 1: lint 規則 `svelte/no-at-html-tags` を error にする

### 変更箇所
- ファイル: `eslint.config.js`（`.svelte` 向け rules ブロック。現行 L120 付近）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 4 の新 gate が本変更を検査する。
  既存の `tests/js/architecture/svelte-no-undef-gate.test.ts` は
  `no-undef` の severity / `globals` の完全一致 / `noInlineConfig` の 3 点だけを見るので、
  **rules に 1 本足しても影響しない**（実読で確認）。

### 現行コード
```js
    {
        files: ["**/*.svelte"],
        plugins: { svelte },
        languageOptions: {
            globals: svelteGlobals,
        },
        rules: {
            // .svelte は tsc の検査対象外 (tsc --listFiles に 1 件も現れない)。
            // 未定義識別子を捕まえる機構がここにしか無いので error 固定
            // (spirux:T1054 = SSO 接続追加画面のクラッシュと同型の事故を止める)。
            "no-undef": "error",
            "svelte/require-each-key": "error",
            "svelte/prefer-svelte-reactivity": "error",
            "svelte/prefer-writable-derived": "error",
            "svelte/no-useless-mustaches": ["error", { ignoreStringEscape: true }],
        },
    },
```

### 変更後コード
```js
    {
        files: ["**/*.svelte"],
        plugins: { svelte },
        languageOptions: {
            globals: svelteGlobals,
        },
        rules: {
            // .svelte は tsc の検査対象外 (tsc --listFiles に 1 件も現れない)。
            // 未定義識別子を捕まえる機構がここにしか無いので error 固定
            // (spirux:T1054 = SSO 接続追加画面のクラッシュと同型の事故を止める)。
            "no-undef": "error",
            /*
             * 生の HTML を DOM へ差し込む構文 ({@html}) の全面禁止。
             *
             * 値の出どころが 1 か所でも汚れていれば script がそのまま実行される。
             * 撮影 PWA は同一オリジン・セッション認証なので、XSS の成立は
             * 撮影導線の資格情報にそのまま届く。
             *
             * **許可一覧 (allowlist / exemption inventory) の口は持たない**。
             * 例外を設けるなら、その口を排除できない理由・安全境界・専用テストを含む
             * **別のセキュリティ設計**としてレビューを通すこと
             * (file-scoped override をここに書き足して済ませない)。
             *
             * サーバ生成の SVG (2 要素認証の QR) を描く用途には
             * components/atoms/QrCodeImage.svelte を使う (data URI の <img>)。
             *
             * 実効性の裏取りは tests/js/architecture/svelte-raw-html-gate.test.ts
             * (実際に lint を走らせ、無効化コメント 3 形式が効かないことまで固定する)。
             */
            "svelte/no-at-html-tags": "error",
            "svelte/require-each-key": "error",
            "svelte/prefer-svelte-reactivity": "error",
            "svelte/prefer-writable-derived": "error",
            "svelte/no-useless-mustaches": ["error", { ignoreStringEscape: true }],
        },
    },
```

### PHPStan適合チェック
- 対象外（JS 設定ファイル）

### テスト計画
- [ ] 施策 4 の gate 検査 A（`calculateConfigForFile()` で `.svelte` の実効 severity が error）
- [ ] 施策 4 の gate 検査 B（実際に lint を走らせて違反入力が error になる）
- [ ] `pnpm lint` が全体で green（施策 3 で唯一の違反を除去済みであること）

### リスク
- **順序依存**: 施策 3 より先に本施策だけを入れると `pnpm lint` が赤くなる。
  → 同一コミット内で施策 1〜3 をまとめて入れる（実装順は 2 → 3 → 1）。
- `svelte/no-at-html-tags` は `eslint-plugin-svelte` v3.22 に実在する（`node_modules` 実読で確認）。
  依存追加は不要。

---

## 施策 2: 置き換え部品 `QrCodeImage` atom を新設

### 変更箇所
- ファイル: `resources/js/components/atoms/QrCodeImage.svelte`（新規）

### 波及変更
- TypeScript 型定義: `Props` interface を component 内に持つ（`Avatar.svelte` と同形）。
  別ファイルの `.types.ts` は作らない（`Badge` / `Button` / `Toggle` のように
  「仕様の真実を型ファイルに置く」ほどの選択肢を持たないため。思考原則 2）。
- API Resource/DTO: なし
- テストファイル: 施策 5（新規）

### 現行コード
（新規ファイルのため無し）

### 変更後コード
```svelte
<script lang="ts">
    /**
     * QrCodeImage atom。**サーバが生成した SVG 文字列を data URI の <img> として描く**。
     *
     * 存在理由: 生の HTML を DOM へ差し込む構文 ({@html}) を使わずに
     * サーバ生成の QR を表示するための唯一の手段を配る。
     * {@html} は文字列を DOM 木として解釈させるが、本部品は画像リソースとして読ませる。
     * lint 規則 svelte/no-at-html-tags と対で 1 組である
     * (禁止だけを配ると現場は使い続けるため、代わりの手段を同時に配る)。
     *
     * **保証範囲 (誇張しない)**: 本部品が保証するのは
     * 「SVG 文字列を DOM へ HTML として挿さないこと」までである。
     * browser が画像文脈の SVG をどう扱うかの細部は本部品の保証範囲ではない。
     *
     * data URI は **percent encoding** で作る (base64 を採らない):
     *   - btoa() は非 ASCII を含む SVG で例外を投げる
     *   - TextEncoder 経由の base64 化は安全性が同じで手数だけ増える
     *   - 素朴な文字列連結は `#` (fragment 開始) で切れ、`%` が不正な escape になり、
     *     非 ASCII で壊れる
     */

    interface Props {
        /** サーバが生成した SVG 文字列。**null 許容にしない** (呼び出し側が分岐を持つ) */
        svg: string;
        /** 画像の代替テキスト。必須 (アクセシブルネームの正本) */
        alt: string;
        class?: string;
        testId?: string;
    }

    let { svg, alt, class: extraClass = "", testId }: Props = $props();

    const src = $derived(`data:image/svg+xml,${encodeURIComponent(svg)}`);
</script>

<img {src} {alt} class={extraClass} data-testid={testId} />
```

### PHPStan適合チェック
- 対象外（Svelte component）
- TypeScript: `svg` / `alt` を必須にすることで、呼び出し側の `string | null` を
  そのまま渡すと `pnpm typecheck` が落ちる（nullable の吸収を atom 側に作らない）

### テスト計画
- [ ] 新規テスト `tests/js/components/atoms/QrCodeImage.test.ts`（施策 5 で詳述）
- [ ] `ds-purity` テスト: 本部品は class を token 経由でしか受けないため
      `FILE_SCOPED_ALLOWLIST` への登録は不要（ramp 外 utility を自前で書かない）
- [ ] `svg-inline-allowlist.test.ts`: 本部品は `<svg` 要素を**書かない**ため抵触しない
- [ ] `atomic-import-graph.test.ts`: atom は他層を import しない（本部品は import 0 件）

### リスク
- **`class` prop を受けること**で呼び出し側から任意の utility を差し込める。
  → `Avatar.svelte` が既に同じ形を採っている既存パターンであり、
    ds-purity テストが呼び出し側の class トークンを検査するので統制は効く。
- **percent encoding の副作用**: `encodeURIComponent` は `'` を encode しないが、
  `src` 属性は Svelte が属性値としてエスケープするため属性境界は壊れない。
- **画像サイズ**: `<img>` は intrinsic size を SVG から取る。
  Fortify の QR SVG は `width`/`height` を持つ（現行 `{@html}` でも同じ寸法で描けている）ため、
  レイアウト崩れは起きない。**万一のために `class` で寸法を渡せる口は残す**。

---

## 施策 3: 唯一の実在サイトを置換し `{@html}` を除去

### 変更箇所
- ファイル: `resources/js/pages/Settings/Security.svelte`（L631-643 付近 = 唯一の `{@html}`）
- import 追加（L3-8 の atom import 群）

### 波及変更
- TypeScript 型定義: なし（`qrSvg` の型 `string | null` は変えない）
- API Resource/DTO: なし（`/user/two-factor-qr-code` の応答形は変えない）
- テストファイル: 施策 6（新規 + 既存追随）

### 現行コード
```svelte
                            {#if qrSvg}
                                <!-- QR はサーバ提供の SVG をそのまま描画する。svg 文字列に属性を注入せず、
                                     wrapper を role="img" にしてアクセシブルネームを与える (H14) -->
                                <div
                                    role="img"
                                    aria-label="2 要素認証の設定用 QR コード"
                                    class="self-start rounded-md border border-border bg-surface p-4"
                                    data-testid="two-factor-qr"
                                >
                                    {@html qrSvg}
                                </div>
                            {:else}
```

### 変更後コード
```svelte
                            {#if qrSvg}
                                <!-- QR はサーバ生成の SVG を **data URI の <img>** として描く。
                                     生の HTML を DOM へ差し込む構文は使わない
                                     (禁止の正本は eslint.config.js の svelte/no-at-html-tags)。
                                     アクセシブルネームは img の alt が正本なので、
                                     wrapper の role="img" / aria-label は持たせない (二重命名を避ける)。 -->
                                <div class="self-start rounded-md border border-border bg-surface p-4">
                                    <QrCodeImage
                                        svg={qrSvg}
                                        alt="2 要素認証の設定用 QR コード"
                                        testId="two-factor-qr"
                                    />
                                </div>
                            {:else}
```

import 追加（既存の atom import 群のアルファベット順に沿って `Input` の後）:
```svelte
    import QrCodeImage from "@/components/atoms/QrCodeImage.svelte";
```

### PHPStan適合チェック
- 対象外（Svelte component）

### テスト計画
- [ ] 新規テスト `tests/js/pages/SettingsSecurityTwoFactorQr.test.ts`（施策 6）
- [ ] 既存 `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts` が green のまま
      （`getByTestId("two-factor-qr")` は img 側に移るが**存在検査だけ**なので通る。実読で確認）
- [ ] 既存 `tests/js/pages/SettingsSecurity.test.ts` が green のまま（QR の DOM 形に依存していない）

### リスク
- **状態機械への波及なし**。取得失敗 Alert / 再認証 step-up / 世代管理 / `resetEnrollmentAssets()` は
  いずれも `qrSvg` の**値**しか見ておらず、描画形の変更は届かない（実読で確認）。
- **アクセシビリティの後退**: wrapper の `role="img"` を外すので、
  アクセシブルネームの正本が `alt` 1 か所になる。
  → 施策 6 の新規テストで `getByAltText("2 要素認証の設定用 QR コード")` を固定する。
- **`data-testid` の移動**: wrapper → img。既存テストは存在検査のみなので影響しない。

---

## 施策 4: raw HTML sink gate を新設

### 変更箇所
- ファイル: `tests/js/architecture/svelte-raw-html-gate.test.ts`（新規）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本施策そのもの

### 現行コード
（新規ファイルのため無し）

### 変更後コード（構造と検査項目。実装時に肉付けする）

```ts
import { describe, it, expect } from "vitest";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ESLint } from "eslint";

/*
 * svelte-raw-html-gate — 生の HTML を DOM へ差し込む構文 ({@html}) の
 * 全面禁止が **実効である**ことを、振る舞いから固定する。
 *
 * 家系の機能台帳 lctl feature `svelte-raw-html-sink-ban` (canonical_version t1) の
 * 4 点そろいのうち (1)(2)(3) を担う (残る (4) は QrCodeImage.svelte と
 * tests/Feature/Security/SecurityHeadersTest.php)。
 *
 * 検査する不変条件:
 *   A. [config] resources/js 配下の .svelte で svelte/no-at-html-tags が error
 *   B. [振る舞い] {@html} を含む合成入力を実際に lint すると error になる。
 *      **無効化コメント 3 形式を付けても error のまま**である:
 *        (i)   先頭 <!-- eslint-disable -->
 *        (ii)  先頭 <!-- eslint-disable svelte/no-at-html-tags -->
 *        (iii) 違反行の直前 <!-- eslint-disable-next-line svelte/no-at-html-tags -->
 *   B'. [負例の裏取り] 同じ 3 形式が noInlineConfig:false の**対照条件**では
 *      実際に error を消せる。これが無いと「元から解釈されていない文字列」を
 *      負例と称して緑になる (検出力の空振り)。
 *   C. [実ファイル] resources/js 配下の .svelte 全数に {@html} が 0 件。
 *   D. [正例] {@html} を含まない規定どおりの入力を誤検出しない。
 *   E. [fail-closed] 走査根が解決できない / 母集団が 0 件 /
 *      config 解決が resources/js 配下の .svelte を対象にしていない /
 *      lint 実行が失敗した場合は、いずれも**落とす**。
 *
 * **許可一覧の口は持たない** (正典が明記する方針)。
 * 例外を設けるなら、その口を排除できない理由・安全境界・専用テストを含む
 * 別のセキュリティ設計としてレビューを通すこと。
 *
 * 走査対象: resources/js 配下の `.svelte` 全数 (git 追跡かどうかは見ない)。
 * 検出の区切り: 文字列 `{@html` の出現。**コメント内・文字列リテラル内も違反として数える**
 *   (目標値が 0 件なので、拾いすぎる方向へ倒すのは (b) の許す側である)。
 * 保証しないもの:
 *   - {@html} 以外の raw HTML sink (innerHTML 直代入 / svelte:element の動的タグ /
 *     document.write 等) には**無言で効かない**。
 *   - resources/js の外の .svelte は走査しない (lint 対象と一致させている)。
 *   - browser が画像文脈の SVG をどう扱うかは本 gate の対象ではない。
 */
```

検査の実装方針:

| 検査 | 実装 |
|---|---|
| A | `new ESLint({ cwd: REPO_ROOT })` の `calculateConfigForFile(<resources/js 配下の .svelte パス>)` を解決し、`rules["svelte/no-at-html-tags"]` の severity が `2`（または `"error"`）であることを確認。解決に失敗したら fail |
| B | `eslint.lintText(source, { filePath: <resources/js 配下の仮想 .svelte パス> })` を 4 本（素の違反 + 3 形式）走らせ、いずれも `svelte/no-at-html-tags` の error が **1 件以上**残ることを確認 |
| B' | `new ESLint({ cwd: REPO_ROOT, overrideConfig: { linterOptions: { noInlineConfig: false } } })` で同じ 3 形式を lint し、いずれも error が **0 件になる**ことを確認（負例が負例として効いている裏取り） |
| C | `resources/js` を再帰走査して `.svelte` を集め、各ファイルの本文に `{@html` が含まれないことを確認。違反はパス一覧付きで報告する |
| D | `{expr}` と `{@const}` と `{#if}` を含む正常な .svelte を lint し、`svelte/no-at-html-tags` の報告が 0 件であることを確認 |
| E | 走査根 `resources/js` が存在しなければ fail。`.svelte` の母集団が 0 件なら fail。`lintText` が throw したらそのまま fail（握り潰さない） |

**合成入力はファイルに書き出さない**（`lintText` の `filePath` は仮想パスでよい）。
`resources/js` 配下に fixture ファイルを置くと検査 C の母集団に混ざり、
「実ファイル 0 件」の意味が壊れるため。

### PHPStan適合チェック
- 対象外（vitest テスト）

### テスト計画
- [ ] 本 gate 自身が A〜E を持つ（負例 B・B'、正例 D、fail-closed E）
- [ ] テストファースト: 施策 1 を入れる**前に**本 gate を書いて **A と B が赤くなること**を確認する
      （思考原則 5 / AGENTS.md「走査器・gate を新設するときに揃える 4 点」の 1）
- [ ] 施策 3 を入れる**前に** C が赤くなること（`Security.svelte` の 1 件を検出する）を確認する
- [ ] `pnpm test` に含まれる（`tests/js/architecture/` は既存 vitest レーン）

### リスク
- **ESLint API の版差**: `eslint` は `^10.8.0`。`ESLint` クラスの
  `calculateConfigForFile` / `lintText` / `overrideConfig` はいずれも v10 の公開 API。
  既存 `svelte-no-undef-gate.test.ts` が `calculateConfigForFile` を使って動いているため、
  少なくとも A の経路は実績がある。
- **実行時間**: `lintText` を 8 本程度（B: 4 本 / B': 3 本 / D: 1 本）走らせる。
  `resources/js` 全数を lint するわけではないので、既存 gate と同オーダーに収まる。
- **inline configuration の解釈位置**: Svelte テンプレートの HTML コメントが
  ESLint の directive として解釈されることは B' の対照条件が**実測で**裏取りする。
  もし B' が「3 形式とも対照条件でも error が消えない」となった場合、
  その形式は**この lint 構成では負例として無効**なので、
  **B' が赤くなる = 実装時に形式を選び直せ**という信号になる（fail-closed 側に倒れている）。

---

## 施策 5: 部品テスト `QrCodeImage.test.ts`

### 変更箇所
- ファイル: `tests/js/components/atoms/QrCodeImage.test.ts`（新規）

### 波及変更
- なし

### 変更後コード（検査項目）

```ts
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import QrCodeImage from "@/components/atoms/QrCodeImage.svelte";

describe("QrCodeImage", () => {
    // 1. <img> を描き、生の <svg> 要素を DOM へ出さない
    // 2. alt が渡る (アクセシブルネームの正本)
    // 3. src が data:image/svg+xml, + encodeURIComponent(svg) と完全一致する
    // 4. URI を壊す文字 (#, %, 非 ASCII) を含む SVG でも src が壊れない
    //    = decodeURIComponent(src の payload) が元の svg と一致する
    // 5. script を含む SVG 文字列を渡しても **DOM に <script> 要素が生えない**
    //    (= HTML として解釈されていないことの直接の裏取り)
    // 6. class / testId が渡る
});
```

### PHPStan適合チェック
- 対象外

### テスト計画
- [ ] 上記 6 項目
- [ ] 検査 5 は「本部品の存在理由」そのものの裏取りなので**必須**

### リスク
- 検査 3 の完全一致は実装 (`encodeURIComponent`) と同じ式を書くと
  トートロジーになる。→ 検査 4（`decodeURIComponent` で往復させる）と
  検査 5（`<script>` が生えない）で**性質**を固定し、
  検査 3 は「`data:image/svg+xml,` 接頭辞であること」に留める。

---

## 施策 6: 画面テスト（QR 表示の実挙動）と既存テストの追随

### 変更箇所
- ファイル: `tests/js/pages/SettingsSecurityTwoFactorQr.test.ts`（新規。正典の tests に対応）
- ファイル: `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts`（既存。必要なら追随）

### 波及変更
- なし（`Security.svelte` の props / API は変えない）

### 新規テストの検査項目
1. QR 取得成功時、`two-factor-qr` が **`IMG` 要素**であること
2. その `src` が `data:image/svg+xml,` で始まること
3. `getByAltText("2 要素認証の設定用 QR コード")` で引けること（アクセシブルネームの維持）
4. サーバが返した SVG に `<script>` が含まれていても、**DOM に `<script>` 要素が生えない**こと
   （= 画面の層でも sink が閉じていることの裏取り）
5. QR 取得失敗時は従来どおり `qr-unavailable` Alert が出ること（後退が無いこと）

### 既存テストの扱い
- `SettingsSecurityTwoFactorConfirm.test.ts` は `getByTestId("two-factor-qr")` の
  **存在検査**のみで、stub は `{ svg: "<svg></svg>" }` を返す（実読で確認）。
  置換後も `testId` は img に付くので**修正不要**の見込み。
  実装時に赤くなったら、DOM 形の変更に合わせて最小限だけ直す（削除・上書きはしない）。
- `SettingsSecurity.test.ts` は QR の DOM 形に依存していない（実読で確認）。

### テスト計画
- [ ] 新規 5 項目
- [ ] 既存 2 ファイルが green

### リスク
- jsdom は `data:` URI の画像を実際には読み込まないが、
  本テストが見るのは **DOM の形（要素種別・属性値）**なので影響しない。

---

## 施策 7: 応答ヘッダの依存を 2 構成で pin

### 変更箇所
- ファイル: `tests/Feature/Security/SecurityHeadersTest.php`（既存。テスト追加）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- **`config/security.php` は変更しない**（`img-src` は既定・GTM overlay の
  両方に既に `data:` を含む。実読で確認）

### 現行コード
`config/security.php`（変更しない。pin の対象）:
```php
        'directives' => [
            // …
            'img-src' => "'self' data:",
            // …
        ],
        'gtm_directives' => [
            // …
            'img-src' => "'self' data: https://www.googletagmanager.com https://*.google-analytics.com https://*.googletagmanager.com",
            // …
        ],
```

### 変更後コード（`SecurityHeadersTest.php` に追加する 1 テスト）
```php
/*
 * QrCodeImage (components/atoms/QrCodeImage.svelte) は
 * サーバ生成の SVG を data URI の <img> として描く。
 * これは {@html} を使わずに QR を表示するための唯一の手段であり、
 * **img-src が data: を失うと 2 要素認証の設定画面が壊れる**。
 * よって既定構成と GTM 有効構成の **両方**で data: の存在を固定する。
 * (CSP を配る仕組み自体の検査ではない。依存している 1 点だけを pin する)
 */
test('CSP の img-src は data: を許す (QrCodeImage の前提。既定 / GTM 有効の 2 構成)', function (): void {
    // 既定構成
    $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
    expect($csp)->toMatch('/img-src[^;]*\bdata:/');

    // GTM 有効構成 (production + container id の二重ゲート)
    config([
        'app.env' => 'production',
        'services.google_tag_manager.container_id' => 'GTM-TEST',
    ]);
    $gtmCsp = (string) $this->get('/')->headers->get('Content-Security-Policy');
    expect($gtmCsp)->toMatch('/img-src[^;]*\bdata:/');
});
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`function (): void`）
- [x] null 安全（`(string)` cast で `?string` を潰す。既存 `GtmCspTest` と同形）
- [x] DTO を返している（該当なし）
- [x] Generics の型パラメータが正しい（該当なし）

### テスト計画
- [ ] 上記 1 テスト（2 構成を 1 テストで見る。正典の「2 通りの構成の両方で固定する」に対応）
- [ ] `RefreshDatabase` はグローバル適用済み。個別 `DatabaseTransactions` は使わない
- [ ] Factory 不使用（`/` への GET のみ。DB データ不要）

### リスク
- **GTM 有効構成の作り方**が `GtmCspTest` と重複する。
  → 重複ではなく**関心が違う**（`GtmCspTest` は GTM ホストの追加、本テストは
    `data:` が緩まないこと）。正典が `SecurityHeadersTest.php` を置き場所に定めており、
    「QrCodeImage が依存する 1 点」としてここに置くのが台帳との対応も取れる。
- **`config()` の変更**はテスト単位でロールバックされる（Laravel の標準挙動）。

---

## 施策 8: 設計規約への追記

### 変更箇所
- ファイル: `DESIGN.md`
  - `## Components` 配下に `### QrCodeImage` を追加（`Card` と `Spinner` の間 = 概ね ABC 順の位置に合わせる）
  - `## Do's and Don'ts` の **Don't** に 1 項目追加

### 波及変更
- なし（`DESIGN.md` は指紋台帳のキーに**無い**。実測で確認）

### 変更後コード
`### QrCodeImage`（新規節）:
```markdown
### QrCodeImage

実装: `components/atoms/QrCodeImage.svelte`。**サーバが生成した SVG 文字列を
data URI の `<img>` として描く**。生の HTML を DOM へ差し込む構文 (`{@html}`) を
使わずに QR を表示するための**唯一の手段**であり、lint 規則
`svelte/no-at-html-tags` (eslint.config.js) と対で 1 組である。
props は `svg: string`(必須) / `alt: string`(必須) / `class` / `testId`。
`svg` は **null 許容にしない** — 取得中・取得失敗の分岐は呼び出し側が持つ。
アクセシブルネームの正本は `alt` なので、wrapper 側に `role="img"` を重ねない。
data URI は percent encoding で作る (`btoa()` は非 ASCII の SVG で例外を投げる)。
CSP の `img-src` が `data:` を含むことに依存しており、
`tests/Feature/Security/SecurityHeadersTest.php` が 2 構成で pin している。
```

`## Do's and Don'ts` の **Don't** への追加:
```markdown
- **生の HTML を DOM へ差し込む構文 (`{@html}`) を書かない**。値の出どころが 1 か所でも
  汚れていれば script がそのまま実行される。`eslint.config.js` の
  `svelte/no-at-html-tags` が error で落とし、inline コメントでの無効化も効かない
  (`noInlineConfig`)。**許可一覧の口は無い** — 例外を設けるなら、その口を排除できない
  理由・安全境界・専用テストを含む別のセキュリティ設計としてレビューを通すこと。
  サーバ生成の SVG (2 要素認証の QR) には `QrCodeImage` atom を使う。
  実効性の裏取りは `tests/js/architecture/svelte-raw-html-gate.test.ts`。
```

### PHPStan適合チェック
- 対象外（ドキュメント）

### テスト計画
- [ ] `DESIGN.md` の記述に対応する機械検査は施策 1・4 が持つ（文書側に検査は足さない）
- [ ] `pnpm test` の既存 DS 系テストに影響なし

### リスク
- **二重管理**: `DESIGN.md` に禁止の詳細を書きすぎると `eslint.config.js` のコメントと
  食い違う。→ **正本は `eslint.config.js` と gate の docblock**とし、
  `DESIGN.md` は「使う部品」と「禁止の事実 + 参照先」に留める（上記の分量で確定）。

---

## 乖離台帳の確認（Phase 3 で確定させる材料）

`docs/template-fingerprints.json` の `entries`（281 件）に**在るか**で共有ファイルかが決まる。
本設計の変更対象を全数照合した結果（実測）:

| 変更対象 | 指紋台帳のキーに在るか |
|---|---|
| `eslint.config.js` | **在る** |
| `resources/js/components/atoms/QrCodeImage.svelte` | 無い（新規） |
| `resources/js/pages/Settings/Security.svelte` | 無い |
| `tests/js/architecture/svelte-raw-html-gate.test.ts` | 無い（新規） |
| `tests/js/components/atoms/QrCodeImage.test.ts` | 無い（新規） |
| `tests/js/pages/SettingsSecurityTwoFactorQr.test.ts` | 無い（新規） |
| `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts` | 無い |
| `tests/Feature/Security/SecurityHeadersTest.php` | 無い |
| `DESIGN.md` | 無い |
| `config/security.php` | 無い（かつ**変更しない**） |

`eslint.config.js` は既に**内容がテンプレートと不一致**（ローカル sha256
`613c74ef…` ≠ 台帳値 `6479fb2d…`）で、**D11 の対象パスとして登録済み**である。
また `tests/Support/TemplateDivergence/adoption-debt.tsv` には**含まれない**（実測）。

したがって:

- `LedgerPins::DIVERGENCE_ENTRY_COUNT` (36) / `FINGERPRINT_POPULATION_COUNT` (281) /
  `ADOPTION_DEBT_COUNT` (171) は **いずれも動かさない**。
- 本変更は正典への**接近**（追従）であり、新たな逸脱ではないため
  **新規の D エントリは起こさない**。
- ただし D11 の「揃え続ける不変条件」は `no-undef` 系の 3 点しか書いていない。
  `eslint.config.js` に別の関心事（raw HTML sink の禁止）が同居することになるので、
  **D11 の記述へ 1 行の申し送りを足すかどうか**を実装 PR のレビューで判定する
  （足す場合も対象パス・件数は変わらないため `LedgerPins` は不変）。
  本設計の既定は「**足さない**」— D11 は「同一不変条件・別実装」の登録であり、
  同じファイルに正典由来の規則が 1 本増えることは D11 の主張を変えないため。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1（lint 規則を error）と施策 3（唯一の違反の除去）は**分割すると `pnpm lint` が赤くなる**ため、同一ブランチで一体に入れる必要がある。施策 4 の gate も施策 1・3 の両方に依存する。また `eslint.config.js` は lint 対象全体に効く共有設定であり、他の実装が同時に走ると `pnpm lint` の赤で相互に足を引っ張る。1 本の worktree で通しで入れて main へマージするのが安全である。 |
| 競合リスク | `eslint.config.js` を触る他 TODO があれば競合する（現状 `docs/TODO.md` に該当なしを実装前に確認する）。`resources/js/pages/Settings/Security.svelte` は 2FA / passkey 系の TODO と競合しうるが、変更は QR 描画の 1 ブロックに閉じている。 |

### 実装順（テストファースト）

1. 施策 4 の gate を書く → **A・B・C が赤い**ことを確認（この時点で施策 1・3 は未実施）
2. 施策 2（`QrCodeImage.svelte`）+ 施策 5（部品テスト）→ green
3. 施策 3（`Security.svelte` の置換）→ gate の C が green に、施策 6 の新規テストを追加
4. 施策 1（`eslint.config.js`）→ gate の A・B が green に、`pnpm lint` が green
5. 施策 7（`SecurityHeadersTest.php`）→ green
6. 施策 8（`DESIGN.md`）
7. 全検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
   `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

---

## スコープ外（明記）

- **`{@html}` 以外の raw HTML sink**（`innerHTML` 直代入 / `svelte:element` の動的タグ /
  `document.write` 等）。正典 t1 の対象語彙を勝手に増やさない。
- **lint / 型 / 整形設定の基礎そのもの**（`eslint-svelte-ts-baseline` の範囲）。
- **CSP を配る仕組み**（`SecurityHeaders` middleware / directive の組み立て）。
  本設計は `img-src` に `data:` が居ることを pin するだけで、`config/security.php` は変更しない。
- **サーバ側の QR 生成**（Fortify の `/user/two-factor-qr-code`）。応答形も変えない。
- **許可一覧 / exemption inventory の新設**。正典が「口を持たない」と明記しており、
  口を作ること自体が逸脱になる。
- **`resources/js` の外の `.svelte`**（現状 0 件。走査根は lint 対象と一致させる）。
- **`{@html}` 禁止の他リポジトリへの展開**（家系の台帳側の話）。
- **アクセシビリティの全面見直し**。変えるのは QR 1 箇所のアクセシブルネームの正本だけ。


---

## 関連する現行コード

### eslint.config.js (全文)
```js
import betterTailwind from "eslint-plugin-better-tailwindcss";
import svelte from "eslint-plugin-svelte";
import svelteParser from "svelte-eslint-parser";
import tsParser from "@typescript-eslint/parser";
import globals from "globals";

// Tailwind v4 は CSS-first config。entryPoint に @import "tailwindcss" を宣言した
// app.css を指す。callees は clsx/cva 系を導入したときに lint 対象にするための宣言。
const betterTailwindSettings = {
    "better-tailwindcss": {
        entryPoint: "resources/css/app.css",
        callees: ["classnames", "clsx", "ctl", "cn", "cva", "tw"],
    },
};

/*
 * .svelte に載せる実行時グローバル。
 *
 * **ここに載せてよいのは「実行時に存在するグローバル」だけ**。
 * 型専用名 (WebIDL dictionary = MediaTrackConstraints / RequestInit 等) を足すことは
 * 禁止する。足すと lint は緑になるが、同名を実行時の値として誤用したときにも
 * no-undef が黙る = gate を入れる変更で gate に穴を開けることになる
 * (PHPStan エラーを widen して黙らせるのと同じ悪手。AGENTS.md 禁止事項 2)。
 *
 * .svelte の型注釈に型専用名が必要になったら .ts 側へ逃がす:
 *   1. ロジックごと .ts に移す (第一選択。.ts は tsc の検査対象になるので純増)
 *      — 実例: lib/capture/camera.ts の videoConstraints()
 *   2. 移せない (component props の型等) なら .ts で
 *      `export type X = MediaTrackConstraints;` と別名 export し、
 *      .svelte からは `import type` で参照する (module 参照は no-undef の対象外)
 *
 * アプリ固有の実行時グローバル (window に生やす等) が将来必要になったら、
 * 下の APP_RUNTIME_GLOBALS に理由コメント付きで登録する。
 * svelte-no-undef-gate が「globals.browser + APP_RUNTIME_GLOBALS と完全一致」を
 * deny-by-default で検査するので、無登録の差分は CI で落ちる。
 */
const APP_RUNTIME_GLOBALS = {
    // 現時点で登録なし。追加時は「なぜ実行時グローバルなのか」を必ず添えること。
};

const svelteGlobals = { ...globals.browser, ...APP_RUNTIME_GLOBALS };

export default [
    /*
     * lint 対象 (`pnpm lint` = `eslint resources/js`) の全ファイルで、
     * inline の eslint-disable / eslint-enable を一切許可しない。
     * ルールを黙らせたいときの唯一の手段は **本ファイルの file-scoped override**。
     * override を認めるのは次の 3 条件をすべて満たすときだけ:
     *   (a) 抑制対象が具体的な 1 ファイル (または明示列挙されたファイル群) に閉じている
     *   (b) なぜ安全かがコード側の日本語コメントで説明されている
     *   (c) ここに理由と再検討条件 (いつ外せるか) を書く
     * config に集約すれば diff に必ず現れ、レビュー可能かつ数えられる。
     *
     * **lint 対象を広げるとき** (`pnpm lint` の引数を増やす等) は、
     * tests/js/architecture/svelte-no-undef-gate.test.ts の走査範囲も同時に広げること
     * (宣言と検査の範囲が乖離すると gate が守っているつもりの穴ができる)。
     */
    { linterOptions: { noInlineConfig: true } },
    {
        ignores: [
            "tmp/**",
            "node_modules/**",
            "dist/**",
            "build/**",
            ".git/**",
            "vendor/**",
            "public/build/**",
            "storage/**",
        ],
    },
    {
        files: ["**/*.svelte"],
        languageOptions: {
            parser: svelteParser,
            parserOptions: {
                parser: tsParser,
            },
        },
    },
    {
        files: ["**/*.{js,mjs,cjs,ts,jsx,tsx}"],
        languageOptions: {
            parser: tsParser,
        },
    },
    {
        files: ["**/*.{js,mjs,cjs,ts,jsx,tsx,svelte}"],
        plugins: {
            "better-tailwindcss": betterTailwind,
        },
        settings: betterTailwindSettings,
        rules: {
            "better-tailwindcss/no-conflicting-classes": "error",
            "better-tailwindcss/no-duplicate-classes": "error",
            "better-tailwindcss/no-unnecessary-whitespace": "error",
            "better-tailwindcss/enforce-consistent-class-order": "error",
            "better-tailwindcss/no-unknown-classes": "warn",
        },
    },
    {
        files: ["**/*.svelte"],
        plugins: { svelte },
        languageOptions: {
            globals: svelteGlobals,
        },
        rules: {
            // .svelte は tsc の検査対象外 (tsc --listFiles に 1 件も現れない)。
            // 未定義識別子を捕まえる機構がここにしか無いので error 固定
            // (spirux:T1054 = SSO 接続追加画面のクラッシュと同型の事故を止める)。
            "no-undef": "error",
            "svelte/require-each-key": "error",
            "svelte/prefer-svelte-reactivity": "error",
            "svelte/prefer-writable-derived": "error",
            "svelte/no-useless-mustaches": ["error", { ignoreStringEscape: true }],
        },
    },
];

```

### resources/js/pages/Settings/Security.svelte (QR 描画箇所 L625-660)
```svelte
                                    >
                                        再試行
                                    </Button>
                                {/snippet}
                            </Alert>
                        {:else}
                            {#if qrSvg}
                                <!-- QR はサーバ提供の SVG をそのまま描画する。svg 文字列に属性を注入せず、
                                     wrapper を role="img" にしてアクセシブルネームを与える (H14) -->
                                <div
                                    role="img"
                                    aria-label="2 要素認証の設定用 QR コード"
                                    class="self-start rounded-md border border-border bg-surface p-4"
                                    data-testid="two-factor-qr"
                                >
                                    {@html qrSvg}
                                </div>
                            {:else}
                                <Alert type="warning" testId="qr-unavailable">
                                    QR コードを表示できませんでした。下のセットアップキーを認証アプリに手動入力してください。
                                </Alert>
                            {/if}

                            {#if setupKey}
                                <div class="flex flex-col gap-2">
                                    <p class="text-caption text-text-secondary">
                                        QR コードを読み取れない場合は、次のセットアップキーを認証アプリに手動入力してください。
                                    </p>
                                    <CodeSnippet code={setupKey} testId="two-factor-setup-key" />
                                </div>
                            {:else}
                                <Alert type="warning" testId="setup-key-unavailable">
                                    セットアップキーを表示できませんでした。上の QR コードを認証アプリで読み取ってください。
                                </Alert>
                            {/if}
                        {/if}
```

### resources/js/pages/Settings/Security.svelte (状態機械 L120-135 / L239-315)
```svelte

    /** QR 確認待ち (有効化開始済みだが未確認) */
    let confirming = $state(false);
    let enabling = $state(false);
    /**
     * enrollment 素材。QR と手動セットアップキーは独立に失敗しうる
     * (片方でも enrollment は続行できる = カメラ不可端末 / QR 非対応アプリ / 支援技術利用者を詰ませない)。
     */
    let qrSvg = $state<string | null>(null);
    let setupKey = $state<string | null>(null);
    /** 両方の取得に失敗した = enrollment を続行できない (再試行導線を出す) */
    let enrollmentAssetsFailed = $state(false);
    let loadingEnrollmentAssets = $state(false);
    let recoveryCodes = $state<string[]>([]);
    let loadingRecoveryCodes = $state(false);
    /** 新コード一覧へのフォーカス移動用 (再生成成功時に再保管を促す) */
...
    async function loadEnrollmentAssets(): Promise<void> {
        const generation = ++enrollmentGeneration;
        loadingEnrollmentAssets = true;
        /*
         * 前回の**結果表示**をここで一度に捨てる (取得結果に依らない単一の初期化点)。
         * これが無いと 500 で取得失敗 → 再試行 → 409 の順に遷移したとき、409 分岐は
         * enrollmentAssetsFailed を触らないため「再認証が必要です」と
         * 「設定情報を取得できませんでした」が同時に出る (原因と対処が食い違う表示になる)。
         * ★enrollmentStepUpRetried (自動再開の上限) はここでは戻さない。
         *   戻すと 409 → 自動再開 → 409 → 自動再開 … が無限に回る。
         *   上限を戻せるのは人間の操作 (retryEnrollmentAssets) と enrollment の破棄だけ。
         */
        enrollmentAssetsFailed = false;
        enrollmentStepUpBlocked = false;

        const [qr, secret] = await Promise.all([
            fetchEnrollmentField("/user/two-factor-qr-code", "svg"),
            fetchEnrollmentField("/user/two-factor-secret-key", "secretKey"),
        ]);

        // 世代が進んでいる = 破棄済み or 新しい取得が走っている。結果も loading も触らない
        // (finally で戻すと古い run が新しい run の loading を消してしまう)
        if (generation !== enrollmentGeneration) return;

        // 鮮度切れは「取得失敗」ではない。再認証モーダルを 1 回だけ開き、成立後に同じ取得を再開する
        if (qr.recentAuthRequired || secret.recentAuthRequired) {
            loadingEnrollmentAssets = false;

            // 自動再開の上限。ここを超えたら人間の操作 (再試行ボタン) を待つ
            if (enrollmentStepUpRetried) {
                enrollmentStepUpBlocked = true;
                return;
            }
            enrollmentStepUpRetried = true;

            void guardWithRecentAuth(
                () => void loadEnrollmentAssets(),
                // status 取得失敗 (delegated)。**再取得しない** (ここで再取得すると
                // 409 → status 失敗 → 再取得 の無限ループになる)。
                () => {
                    enrollmentStepUpBlocked = true;
                },
            );

            return;
        }

        qrSvg = qr.value;
        setupKey = secret.value;
        enrollmentAssetsFailed = qr.value === null && secret.value === null;
        loadingEnrollmentAssets = false;
    }

    /**
     * 手動再試行 (取得失敗 Alert / step-up 不能 Alert の両方から呼ぶ)。
     * **自動再開の上限を戻すのはここだけ** (ループを切るのは常に人間の操作)。
     * 結果表示のリセットは loadEnrollmentAssets() 側の単一初期化点が行う。
     */
    function retryEnrollmentAssets(): void {
        enrollmentStepUpRetried = false;
        void loadEnrollmentAssets();
    }

    /**
     * enrollment 素材を画面から破棄する (開始時 / confirm 成功時 / 無効化成功時に呼ぶ)。
     * 世代を進めることで、進行中の取得結果が後から再格納されるのを防ぐ。
     * TOTP secret の残置時間を enrollment 中に限定する目的も兼ねる。
     */
    function resetEnrollmentAssets(): void {
        enrollmentGeneration += 1;
        qrSvg = null;
        setupKey = null;
        enrollmentAssetsFailed = false;
        enrollmentStepUpBlocked = false;
        enrollmentStepUpRetried = false;
        loadingEnrollmentAssets = false;
    }
```

### resources/js/components/atoms/Avatar.svelte (既存 atom の書き方の見本)
```svelte
<script lang="ts">
    /**
     * Avatar atom。src があれば画像、無ければ name の先頭 1 文字 (大文字化) をイニシャル表示する。
     * アバターは真に円形な UI のため rounded-full を使う (DESIGN.md §Shapes の ramp 外例外。
     * tests/js/support/ds-purity.ts の FILE_SCOPED_ALLOWLIST で管理)。
     */

    type AvatarSize = "sm" | "md" | "lg";

    interface Props {
        /** 表示名。src 無し時のイニシャル算出と alt の既定に使う */
        name: string;
        /** 画像 URL。指定時は <img>、無指定時はイニシャル */
        src?: string;
        /** 画像の alt。未指定なら name */
        alt?: string;
        size?: AvatarSize;
        class?: string;
        testId?: string;
    }

    let { name, src, alt, size = "md", class: extraClass = "", testId }: Props = $props();

    const SIZE_CLASSES = {
        sm: "size-7 text-caption",
        md: "size-10 text-body",
        lg: "size-14 text-h3",
    } as const satisfies Record<AvatarSize, string>;

    const computedClass = $derived(
        [
            "inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full",
            "bg-neutral font-medium text-text-secondary select-none",
            SIZE_CLASSES[size],
            extraClass,
        ]
            .filter(Boolean)
            .join(" "),
    );

    // イニシャルは先頭 1 文字を大文字化 (サロゲートペアも 1 文字として扱う)
    const initial = $derived([...name.trim()][0]?.toUpperCase() ?? "");
</script>

{#if src !== undefined}
    <img
        {src}
        alt={alt ?? name}
        class="{computedClass} object-cover"
        data-testid={testId}
    />
{:else}
    <span class={computedClass} aria-label={alt ?? name} data-testid={testId}>
        {initial}
    </span>
{/if}

```

### tests/js/architecture/svelte-no-undef-gate.test.ts (既存の ESLint 実行 gate。L1-100)
```ts
import { describe, it, expect } from "vitest";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ESLint } from "eslint";
import globals from "globals";

/*
 * svelte-no-undef-gate — .svelte の未定義識別子検出を config レベルで固定する。
 *
 * 背景: .svelte は tsc の検査対象外 (tsc --listFiles に 1 件も現れない)。
 * 未定義識別子を捕まえる機構は eslint の no-undef **だけ**であり、
 * これが外れると .svelte 全体が無検査に戻る (spirux:T1054 = SSO 接続追加画面の
 * クラッシュと同型の事故が再発する)。
 *
 * 検査する不変条件:
 *   A. [.svelte のみ] no-undef が error
 *   B. [.svelte のみ] languageOptions.globals が globals.browser と **完全一致**
 *      (型専用名を混ぜて no-undef を骨抜きにしない。追加は eslint.config.js の
 *       APP_RUNTIME_GLOBALS へ理由付きで登録し、本 gate 側も同時に更新する)
 *   C. [lint 対象の全ファイル] linterOptions.noInlineConfig が true
 *      — A/B を inline コメントで黙らせないための **前提条件**。
 *      `pnpm lint` = `eslint resources/js` なので、走査範囲も
 *      **resources/js 配下 × eslint.config.js が files で対象にしている全拡張子**
 *      (.svelte / .js / .mjs / .cjs / .ts / .jsx / .tsx) に一致させる。
 *      .svelte だけ見ると .ts 向け file-scoped override での復活を見逃す。
 *      lint されないファイル (tests/js 等) は ESLint が directive を読まないので対象外。
 *   D. 走査対象が 0 件でない (空振り gate を green として扱わない)
 *
 * gate の名前が指す中心は「.svelte の no-undef」だが、
 * それを支える C は前提の適用範囲 (= lint 対象全体) で検査する。
 * **lint 対象を広げたら本 gate の LINT_TARGET_EXTENSIONS / 走査ルートも同時に広げること。**
 *
 * 実装は laravel-claude-template のものと **別実装**。同一不変条件・別実装の
 * divergence として docs/template-divergence.md D11 に記録している。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "../../../");
const RESOURCES_JS = path.join(REPO_ROOT, "resources/js");

/** 検査対象に落とし込んだ実効 config の view (純関数への入力) */
interface ResolvedConfigView {
    readonly rules?: Record<string, unknown>;
    readonly linterOptions?: { readonly noInlineConfig?: boolean };
    readonly languageOptions?: { readonly globals?: Record<string, unknown> };
}

/** 期待する globals キー集合 (allowlist。eslint.config.js の svelteGlobals と一対一) */
const EXPECTED_GLOBAL_KEYS = Object.keys(globals.browser).sort();

/**
 * lint 対象の拡張子 (= eslint.config.js の files が対象にしている集合)。
 * `pnpm lint` の対象を広げたらここも広げること。
 */
const LINT_TARGET_EXTENSIONS = [".svelte", ".js", ".mjs", ".cjs", ".ts", ".jsx", ".tsx"] as const;

/**
 * [C] inline の eslint-disable が効かないこと。**lint 対象の全拡張子**に適用する
 * (`pnpm lint` = `eslint resources/js` の範囲 × LINT_TARGET_EXTENSIONS)。
 */
function assertNoInlineConfig(resolved: ResolvedConfigView): string[] {
    return resolved.linterOptions?.noInlineConfig === true
        ? []
        : ["linterOptions.noInlineConfig が true でない (inline の eslint-disable が効いてしまう)"];
}

/**
 * [A][B] .svelte 固有の不変条件を検査し、違反理由を返す (空配列 = 適合)。
 * ESLint の設定マージ規則ではなく **解決結果**だけを見る純関数。
 */
function assertSvelteNoUndefConfig(resolved: ResolvedConfigView): string[] {
    const problems: string[] = [];

    const noUndef = resolved.rules?.["no-undef"];
    // flat config の解決結果では severity は数値 (2 = error) を含む配列で返る
    const severity = Array.isArray(noUndef) ? noUndef[0] : noUndef;
    if (severity !== 2 && severity !== "error") {
        problems.push(`no-undef が error でない (実効値: ${JSON.stringify(noUndef)})`);
    }

    const actualKeys = Object.keys(resolved.languageOptions?.globals ?? {}).sort();
    const extra = actualKeys.filter((k) => !EXPECTED_GLOBAL_KEYS.includes(k));
    const missing = EXPECTED_GLOBAL_KEYS.filter((k) => !actualKeys.includes(k));
    if (extra.length > 0) {
        problems.push(
            `globals に globals.browser 外のキーがある: ${extra.join(", ")} ` +
                `(型専用名の登録は禁止。実行時グローバルなら eslint.config.js の ` +
                `APP_RUNTIME_GLOBALS へ理由付きで登録し、本テストの期待値も同時に更新すること)`,
        );
    }
    if (missing.length > 0) {
        problems.push(`globals に globals.browser のキーが不足: ${missing.slice(0, 5).join(", ")}…`);
    }

    return problems;
}

async function sourceFiles(dir: string, exts: readonly string[]): Promise<string[]> {
    const out: string[] = [];
```

### config/security.php (CSP 部分 L65-100) — **本設計では変更しない**
```php
        | form POST で外部に遷移する必要が生じたら form-action にそのドメインを追加すること。
        */
        'directives' => [
            'default-src' => "'self'",
            // 既定は strict。GTM を実際に読み込むとき (production + GTM_CONTAINER_ID の二重ゲート)
            // だけ SecurityHeaders が下の gtm_directives を該当 directive にマージして緩める。
            // これにより GTM を使わない既定テンプレの XSS baseline を緩めない。
            // Stripe.js 導入例: script-src 末尾に " https://js.stripe.com https://checkout.stripe.com" を追記。
            'script-src' => "'self'",
            'style-src' => "'self' 'unsafe-inline' https://fonts.googleapis.com",
            'font-src' => "'self' https://fonts.gstatic.com",
            'img-src' => "'self' data:",
            // connect-src / frame-src は既定では default-src ('self') にフォールバックさせ、
            // GTM 有効時のみ gtm_directives で明示追加する。
            'form-action' => "'self'",
            'frame-ancestors' => "'none'",
            'base-uri' => "'self'",
            'object-src' => "'none'",
        ],

        /*
        | GTM/GA4 用の CSP overlay。GTM を実際に描画する条件 (App\Support\GoogleTagManager::isEnabled()
        | = production かつ container_id 非空) が成立するときだけ SecurityHeaders が
        | 上記 directives にマージする (既存キーは上書き、connect-src / frame-src は追加)。
        | GTM の inline bootstrap snippet 用に script-src へ 'unsafe-inline' を足すが、
        | この緩和は GTM 有効時に限定され、既定 (GTM off) の script-src は 'self' のまま。
        */
        'gtm_directives' => [
            'script-src' => "'self' 'unsafe-inline' https://www.googletagmanager.com",
            'img-src' => "'self' data: https://www.googletagmanager.com https://*.google-analytics.com https://*.googletagmanager.com",
            'connect-src' => "'self' https://www.googletagmanager.com https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com",
            'frame-src' => "'self' https://www.googletagmanager.com",
        ],
    ],

    /*
```

### tests/Feature/Security/SecurityHeadersTest.php (L1-35)
```php
<?php

declare(strict_types=1);

/*
 * SecurityHeaders / RedirectToHttps の挙動検証。
 */

use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;

test('全レスポンスに baseline セキュリティヘッダが付く', function (): void {
    $response = $this->get('/');

    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Content-Security-Policy');
    $response->assertHeader(
        'Permissions-Policy',
        'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")',
    );
});

test('CSP は directive 連想配列から組み立てられる', function (): void {
    config()->set('security.csp.directives', [
        'default-src' => "'self'",
        'frame-ancestors' => "'none'",
    ]);

    $this->get('/')->assertHeader('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'");
});

test('Permissions-Policy は空文字 (opt-out) で非送出になる', function (): void {
```

### tests/Feature/Seo/GtmCspTest.php (GTM 有効構成の作り方の見本)
```php
<?php

declare(strict_types=1);

// CSP (config/security.php) は既定では strict (script-src 'self')。GTM を実際に読み込む
// 条件 (production + GTM_CONTAINER_ID の二重ゲート) のときだけ GTM/GA4 の host-source と
// 'unsafe-inline' を該当 directive にマージする。既定テンプレの XSS baseline は緩めない。

it('keeps a strict CSP baseline when GTM is disabled (default template)', function (): void {
    $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

    expect($csp)->not->toBe('');

    // 既定は strict: script-src に 'unsafe-inline' / GTM ホストを持たない。
    expect($csp)->toContain("script-src 'self';")
        ->not->toContain('googletagmanager.com')
        ->not->toContain('google-analytics.com');

    // baseline directive が保持されている。
    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("object-src 'none'");
});

it('relaxes CSP for GTM/GA4 only when GTM is enabled (production + container id)', function (): void {
    config([
        'app.env' => 'production',
        'services.google_tag_manager.container_id' => 'GTM-TEST',
    ]);

    $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

    // GTM script + GA4 connect。
    expect($csp)->toContain('script-src')
        ->toContain("'unsafe-inline'")
        ->toContain('https://www.googletagmanager.com')
        ->toContain('https://www.google-analytics.com')
        ->toContain('https://*.analytics.google.com');

    // frame-src は GTM noscript iframe、img-src は GTM/GA4 beacon を許可する。
    expect($csp)->toMatch('/frame-src[^;]*https:\/\/www\.googletagmanager\.com/');
    expect($csp)->toMatch('/img-src[^;]*https:\/\/www\.googletagmanager\.com/');
    expect($csp)->toMatch('/img-src[^;]*https:\/\/\*\.google-analytics\.com/');

    // baseline directive は保持されている。
    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("object-src 'none'");
});
```

### docs/template-divergence.md D11 (eslint.config.js の既存逸脱登録)
```markdown

---

## D11 svelte-no-undef-gate を config 静的検査型で別実装 (同一不変条件・別実装)

| 行 | 内容 |
|---|---|
| 対象パス | `tests/js/architecture/svelte-no-undef-gate.test.ts` / `eslint.config.js` |
| 業務要件起因の説明 | 同じ不変条件を守る実装がテンプレート側にあるが手元で読めないため、実装を待たずに設定の静的検査で先に固定した |
| 揃え続ける不変条件と保証機構 | resources/js 配下の全 svelte で no-undef が error / globals が実行時グローバルと完全一致 / lint 対象の全ファイルで inline の抑制が効かない |
| 再判定の条件 | laravel-claude-template の実装を読める状態になったとき (突き合わせて寄せられるなら本登録を消す) |
| 決めた日 | 2026-08-05 |
| 決めた人 | 開発者 |
| 根拠 | T102 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| gate 実装 | `tests/js/architecture/svelte-no-undef-gate.test.ts` (実装未確認 = mirror 未取得) | 同名ファイルを **ESLint `calculateConfigForFile()` による実効設定の静的検査**として独自実装 |

### なぜ正当な差分か(logic-driven)

c2c 台帳 `atomic-design-gates` の AG-023 裁定 (2026-08-05) で
「aicue に svelte-no-undef-gate を補完する」ことは確定しているが、
laravel-claude-template の mirror が本環境に無く、テンプレ実装を読めない。
実装を待って不変条件を無防備のまま放置するより、
**同じ不変条件を別実装で先に固定する**方が実害を早く閉じられる。

### 揃えている不変条件(これは保証し続ける)

> A. 「`resources/js` 配下の**全** `.svelte` で ESLint `no-undef` が error である」
> B. 「その `languageOptions.globals` は**実行時グローバル**
>    (`globals.browser` + `eslint.config.js` の `APP_RUNTIME_GLOBALS` 明示登録) と
>    **完全一致**する — 型専用名を混ぜて no-undef を骨抜きにしない」
> C. 「**lint 対象の全ファイル**
>    (= `pnpm lint` = `eslint resources/js` の範囲 × `eslint.config.js` が `files` で
>    対象にしている全拡張子: `.svelte` / `.js` / `.mjs` / `.cjs` / `.ts` / `.jsx` / `.tsx`) で
>    `linterOptions.noInlineConfig` が true であり、inline の eslint-disable が効かない」

`tests/js/architecture/svelte-no-undef-gate.test.ts` が
ESLint 公開 API `calculateConfigForFile()` で実効設定を解決し、
A/B を全 `.svelte` に、C を lint 対象全ファイルに適用して検査する。
走査 0 件でも fail する (空振り防止)。
検査ロジックは純関数 (`assertSvelteNoUndefConfig` / `assertNoInlineConfig`) に切り出し、
正負のコントロールで検出器の実効性を固定している
(ESLint の flat config マージ規則そのものは試験対象にしない)。

**運用契約 1 (noInlineConfig 体制)**: ルールを黙らせる唯一の手段は
`eslint.config.js` の file-scoped override。override を認めるのは
(a) 抑制対象が具体的な 1 ファイル (または明示列挙) に閉じている
(b) なぜ安全かがコード側コメントで説明されている
(c) config 側に理由と再検討条件が書かれている — の 3 条件をすべて満たすときだけ。

**運用契約 2 (宣言と検査範囲の一致)**: `pnpm lint` の対象を広げる
(引数ディレクトリを増やす / 新しい拡張子を扱う) ときは、本 gate の
`LINT_TARGET_EXTENSIONS` と走査ルートも**同一 PR で**広げること。
宣言 (config コメント) と検査範囲が乖離すると「守っているつもりの穴」ができる。

### 収束条件

laravel-claude-template の mirror が取得できた時点でテンプレ実装と突き合わせ、
実装を寄せられるなら本エントリを解消する。

### 関連

- 実装: `tests/js/architecture/svelte-no-undef-gate.test.ts`, `eslint.config.js`
- 設計: `devnotes/20260805-0101-frontend-baseline-gates/detailed-design.md` 施策 4
- 台帳: c2c `atomic-design-gates` AG-023 (2026-08-05 裁定), `eslint-svelte-ts-baseline`

---

## D12 ページタイトル / description はサーバ単一 SoT (helper 経由必須の JS 契約は不採用)
```


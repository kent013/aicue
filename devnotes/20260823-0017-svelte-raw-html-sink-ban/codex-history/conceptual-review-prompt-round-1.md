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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の前提】
- 本設計は「家系の機能台帳 lctl」の feature `svelte-raw-html-sink-ban` (canonical_version t1) への
  **追従設計**である。正典が定める標準形を最小スコープでそのまま採ることが目的であり、
  独自解を発明することは目的ではない。
- 正典全文 (get_feature の出力) を下に添付する。**正典の boundary が定める 4 点そろい**を
  設計が過不足なく満たしているかを最重要の観点として見てほしい。
- 過大化 (スコープを広げる) も、過小化 (4 点のいずれかを落とす) も指摘対象である。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

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

## 概念設計

# 概念設計: svelte-raw-html-sink-ban (家系正典 t1 への追従)

## 背景・課題

家系の機能台帳 lctl の feature `svelte-raw-html-sink-ban` (canonical_version: **t1**、area: security、
feature_revision `3-dc59a9928099`) は、**画面テンプレートで文字列を HTML として差し込む構文
(`{@html}`) を deny-by-default で全面禁止し、同時に唯一の正当な用途に置き換え先の部品を配る**
という家系標準形である。

正典の boundary は「含む」を **4 点そろい**と明示している:

1. lint 設定で当該規則を **error** にすること
2. **ファイル内のコメントで規則を無効化できない**こと
   (無効化の 3 形式を負例として**実際に lint を走らせて**確かめる)
3. 対象ディレクトリ配下の**実ファイルに当該構文が 0 件**であることの直接固定
4. 唯一の正当な用途 (サーバ生成の QR を描く) に対する**置き換え先の部品**と、
   その部品が依存する**応答ヘッダの指示**の固定

正典側 (laravel-claude-template@1dda94e) は、この 4 点を
`eslint.config.js` / `tests/js/architecture/svelte-raw-html-gate.test.ts` /
`resources/js/components/atoms/QrCodeImage.svelte` /
`tests/Feature/Security/SecurityHeadersTest.php` の一式で実装している。
台帳は **許可一覧の口を持たない**方針まで明記しており、
「例外を設けるなら別のセキュリティ設計としてレビューを通せ」と書いてある。

### aicue の現状 (実測。本設計の起点)

台帳の aicue エントリは `status: pending` / 観測点 `aicue@03a69350`。
今回あらためて実読した結果は次のとおり:

| 正典の要求 | aicue の現状 | 差分 |
|---|---|---|
| (1) lint 規則 error | `eslint.config.js` の `.svelte` rules に `svelte/no-at-html-tags` が**無い** | **欠落** |
| (2) コメントで無効化できない | `{ linterOptions: { noInlineConfig: true } }` が lint 対象全体に**既にある** (D11 が config 静的検査で固定) | 前提は充足。ただし**「実際に lint を走らせた振る舞いの裏取り」は無い** |
| (3) 実ファイル 0 件 | `resources/js/pages/Settings/Security.svelte:640` に `{@html qrSvg}` が **1 件**残存 (`rg` 全数走査で他に 0 件) | **欠落 (残存 1 件)** |
| (4) 置き換え部品 + 応答ヘッダ | `resources/js/components/atoms/QrCodeImage.svelte` は**不在**。CSP は `config/security.php` に `img-src 'self' data:` (既定) と gtm overlay 側 `img-src 'self' data: …` の **2 構成とも既に data: を許可**しているが、**`data:` を pin する検査が無い** (`GtmCspTest` は GTM ホストのみ検査) | 部品が**欠落**、ヘッダは値はあるが**pin が欠落** |

つまり aicue は **4 点のうち 1 点も機械で固定していない**。
残存 1 件はテンプレート由来の同一箇所 (2 要素認証の QR 表示) で、
台帳が「置き換え先の部品を移植すればそのまま消せる同型の追従」と判定しているものである。

### なぜ今やるか (実害の形)

`{@html}` は「便利な逃げ道」であり、**禁止だけを配ると現場は使い続ける**。
現状 aicue では lint も検査も何も無いため、**レビューの見落とし 1 回**で
外部由来の文字列が HTML として画面へ流し込まれ、閲覧者の browser で script が走る
(セッション乗っ取り・撮影データの持ち出し) 事故が成立する。
本アプリは撮影 PWA が同一オリジン・セッション認証で動くため、
XSS の成立は撮影導線の資格情報にそのまま届く。

現在の 1 件 (`qrSvg`) は**自サーバの Fortify が生成した SVG** なので今すぐ悪用される値ではないが、
- 「ここでは使ってよい」という**前例**が resources/js に見えている状態そのものが穴であり、
- `{@html}` を通す限り、**サーバ側の QR 生成器が将来別実装に差し替わった瞬間**に
  無検査の HTML 注入点へ戻る。

## 改善アイデア

**正典 t1 を 4 点そろいでそのまま採る (同型の追従)。許可一覧の口は作らない。**

1. **lint で落とす**: `eslint.config.js` の `.svelte` ブロックに
   `"svelte/no-at-html-tags": "error"` を足す。
   併せて「例外は許可一覧ではなく別のセキュリティ設計としてレビューを通す」方針をコメントで宣言する。
2. **無効化できないことを振る舞いで裏取りする**: 新設 gate
   `tests/js/architecture/svelte-raw-html-gate.test.ts` が **ESLint を実際に走らせて**、
   `{@html}` を含む合成入力が error になること、
   および **無効化コメント 3 形式** (行内 `<!-- eslint-disable-next-line -->` 相当 /
   ファイル先頭の一括無効化 / 対象ルール名指しの無効化) を付けても**なお error のまま**であることを固定する。
3. **実ファイル 0 件を直接固定する**: 同 gate が `resources/js` 配下の `.svelte` 全数を走査し、
   `{@html}` が 0 件であることを固定する (母集団が空なら fail = 空振り防止)。
4. **置き換え先の部品を配り、唯一の実在サイトを置換する**:
   - 新設 atom `resources/js/components/atoms/QrCodeImage.svelte` が、
     サーバ生成の SVG 文字列を **data URI の `<img>`** として描く。
     `<img>` 経由で読まれた SVG は **script を実行せず外部リソースも取得しない**ため、
     `{@html}` と違い**文字列の出どころを信用しなくてよい**。
   - `Settings/Security.svelte` の `{@html qrSvg}` をこの部品に置換する。
   - 部品が `data:` に依存するので、**CSP の `img-src` が `data:` を含むことを
     既定構成と GTM 有効構成の 2 通りで pin する**。

## 期待効果

- **使命への貢献**: 撮影 PWA は同一オリジン・セッション認証で現場の撮影データを扱う。
  XSS が 1 回成立すると、その資格情報とアップロード導線がそのまま奪われる。
  本設計は「レビューの見落とし 1 回で成立する経路」を**言語構文のレベルで閉じる**ことで、
  現場作業者が触る撮影導線の信頼性を守る。
- **家系との整合**: 台帳の pending 4 本のうち aicue 分を implemented へ上げられる。
  正典と**同型**の追従なので、逸脱登録を増やさずに済む。
- **具体的な改善見込み**:
  - `resources/js` 配下の raw HTML sink が **1 件 → 0 件**。
  - 新規の `{@html}` は **`pnpm lint` で即座に落ちる** (書いた瞬間に分かる)。
  - lint を黙らせる手段 (コメント無効化) が**振る舞いで**塞がれていることが機械で分かる。
  - QR 表示が「信用が要る経路」から「信用が要らない経路」へ変わる
    (サーバ側 QR 生成器の差し替えに対して無防備でなくなる)。

## 実装方針（概要）

| # | 施策 | 変更/新設 |
|---|---|---|
| 1 | lint 規則の有効化 | `eslint.config.js` (既存。`.svelte` rules に 1 行 + 方針コメント) |
| 2 | 置き換え部品の新設 | `resources/js/components/atoms/QrCodeImage.svelte` (新規) |
| 3 | 唯一の実在サイトの置換 | `resources/js/pages/Settings/Security.svelte` (`{@html}` 除去) |
| 4 | gate の新設 | `tests/js/architecture/svelte-raw-html-gate.test.ts` (新規) |
| 5 | 部品テスト | `tests/js/components/atoms/QrCodeImage.test.ts` (新規) |
| 6 | 画面テストの追随 | `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts` 等 (既存。QR の描画形が変わる) |
| 7 | 応答ヘッダの pin | `tests/Feature/Security/SecurityHeadersTest.php` (既存。`img-src` の `data:` を 2 構成で固定) |
| 8 | 設計規約への追記 | `DESIGN.md` (正典の `files_touched` に含まれる。`{@html}` 禁止と代替部品の宣言) |

**gate の作り方は AGENTS.md「静的検査 (gate) と走査器の共通規約」の (b)〜(e) に従う**:
- (b) 解決できない形は落とす: ESLint の実行に失敗したら**未解決として fail** させる。
  走査対象の読み取り不能も同様。
- (c) 検出力は**負例と正例の両方向**で裏取りする
  (違反入力が error / 規定どおりの入力を誤検出しない)。
- (d) 集めた結果を判定に使わない形を作らない。
- (e) 語彙一致ではなく構文 (`{@html`) の検出なので (e) は適用対象外だが、
  **検出の区切りは docblock に宣言する**。
- 母集団が空なら fail (走査根の改名・移動で無音化しない)。

## 制約・前提

- **`noInlineConfig: true` は既存**であり、D11 (`docs/template-divergence.md`) が
  「lint 対象の全ファイルで inline の抑制が効かない」を config 静的検査で既に固定している。
  本設計はこれを**壊さず**、正典が求める「**振る舞いでの**裏取り」を新 gate 側で足す。
  二重管理にはしない — D11 の gate は**設定値**を、新 gate は**実際の lint 結果**を見る (層が違う)。
- **`eslint.config.js` は指紋台帳 (`docs/template-fingerprints.json`) のキーに在る共有ファイル**で、
  かつ**既に D11 の対象パスとして逸脱登録済み**である (実測: ローカル sha256 が台帳値と不一致)。
  よって本設計の変更で `LedgerPins` の件数 pin を動かす必要は無い。
  ただし D11 の記述が「no-undef の gate」に閉じているため、**登録の追補が要るかを Phase 3 の
  乖離台帳確認段で判定する** (本設計は正典への**接近**であり新たな逸脱ではない、が既定の読み)。
- 他の変更対象 (`Settings/Security.svelte` / `config/security.php` /
  `tests/Feature/Security/SecurityHeadersTest.php` / `DESIGN.md` /
  新設 3 ファイル) は**指紋台帳のキーに無い** (実測)。
- **CSP の値そのものは変えない** (`img-src 'self' data:` は既定・GTM overlay の両方に既にある)。
  変えるのは**それを pin する検査を足すこと**だけである。
  正典の boundary も「本 feature が固定するのは、置き換え先の部品が依存する画像取得元の指示が
  緩まないことだけ」と明記している (ヘッダを配る仕組み自体は `security-headers-csp` の範囲)。
- Atomic Design: `QrCodeImage` は**単機能・無状態**の atom であり、階層規約
  (`atoms → molecules → …` の単方向) に適合する。`components/atoms/icons/` ではないので
  `svg-inline-allowlist.test.ts` の inline SVG 許可ディレクトリには入れない —
  部品は `<svg>` 要素を**書かない** (`<img>` に data URI を渡すだけ) ので同 gate に抵触しない。
- アクセシビリティ: 現行は wrapper を `role="img"` + `aria-label` にして SVG に属性注入しない形。
  `<img>` へ移ると **`alt` 属性が正規の手段**になるので、wrapper の `role="img"` は不要になる。
- 現行 `Security.svelte` の QR 周りの状態機械 (取得失敗 Alert / 再認証 step-up / 世代管理 /
  `qrSvg = null` によるリセット) は**触らない**。置換するのは**描画の 1 箇所だけ**である。

## スコープ外

- **`{@html}` 以外の sink** (`svelte:element` の動的タグ / `innerHTML` 直代入 /
  `document.write` 等) は扱わない。正典 t1 の対象は
  「画面テンプレートで文字列を HTML として差し込む構文」であり、
  語彙を勝手に増やさない (AGENTS.md「語彙を勝手に増やさない」に同旨)。
- **lint / 型 / 整形の基礎設定そのもの**は `eslint-svelte-ts-baseline` の範囲 (正典 boundary の除外)。
- **応答ヘッダを配る仕組み自体** (`SecurityHeaders` middleware / CSP の組み立て) は
  `security-headers-csp` の範囲。本設計は `img-src` に `data:` が居ることを pin するだけ。
- **部品の粒度と設計体系の純度**は `atomic-design-gates` の範囲。
- **雛形へ外部由来の文字列を渡すときの防御**は `prompt-injection-defense` の範囲。
- **許可一覧 (exemption inventory) の新設**はしない。正典が「許可一覧の口を持たない」と
  明記しており、口を作ること自体が正典からの逸脱になる。
- サーバ側の QR 生成 (Fortify の `two-factor-qr-code` endpoint) の実装変更はしない。
  受け取った SVG 文字列の**描き方**だけを変える。
- **他画面の一括監査**は不要 (実測で `{@html}` は resources/js 全体で 1 件のみ)。
  0 件固定は gate が恒久的に担う。


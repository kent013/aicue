【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

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

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足コンテキスト】
- 本件は本番アプリのコードではなく、**開発時の探索的バグハント基盤 (オプトイン・未使用時 no-op)** の検査の正しさに関する設計である。
- 対象リポジトリのルートは /workspace。関係ファイルは実読してよい:
  - `.claude/skills/app-bug-hunt/coverage/correlate.py` (照合器 675 行。主入力欠落時の fail-open がある)
  - `.claude/skills/app-bug-hunt/coverage/README.md`
  - `app/Http/Middleware/BughuntCoverageMiddleware.php` (既存のオプトイン観測器の作法)
  - `scripts/bug-hunt-shard.sh` (bug-hunt 環境の provision)
  - `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` (終了コード 3 規約の先例)
  - `AGENTS.md` §bug-hunt

---

## 概念設計

# 概念設計: bughunt-route-capture-failclosed

## 背景・課題

bug-hunt の「操作到達カバレッジ」は、`.claude/skills/app-bug-hunt/coverage/correlate.py` が
**実行済み route の記録 (executed.json)** と **機構分母 (operations.md)** を突き合わせ、
「まだ通れていない操作」の worklist を出す仕組みである。

現状の aicue には **記録を作る側が 1 つも無い**。

- `--executed` は任意引数で、省略すると `load_executed(None)` が `present=False` の空記録を返す
  (`correlate.py` L289-296)。
- その結果、`correlate()` は in_scope の全機構を「未実行」として並べ、`main()` は **終了コード 0** で返す
  (`correlate.py` L660-670)。
- 実行済み記録を生成する経路 (browser 側の退避 → 正規化 → route 名解決、あるいはアプリ側の観測器) が
  リポジトリに存在しない。したがって **毎回この経路にしか入らない**。

つまり検査は「全機能が未実行である」という**内容の疑わしい報告を、成功として返している**。
自動化 (SKILL.md Phase 4 後のカバレッジ突合) は終了コードしか見ないので、この嘘をそのまま受け取る。

同種の事故は家系で実測されている: ある走行で対象 107 件のうち 105 件が誤って未実行として並んだ。
原因は表記ゆれではなく生成器の不在であった (lctl 台帳 bughunt-executed-route-capture の経緯)。

さらに現状の照合器は、渡された executed.json の **run_id を検査していない**。
別の走行の記録を渡しても静かに通る (findings 側だけ `--run-id` で絞っている非対称)。

## 改善アイデア

**(1) 照合器を fail-closed にする。** 主入力 (実行済み route の記録) が揃わない走行では
worklist を出さず、終了コード 3 で落とす。「検査が静かに嘘をつく」状態をまずここで消す。

**(2) 実行済み route をアプリ側で機械記録する。** bug-hunt 専用環境の serve プロセスに
観測器 (middleware) を 1 本足し、応答送出後に「解決された route 名・method・状態コード」を
shard ごとの JSONL へ追記する。走行後にそれを束ねて executed.json を作る。

記録の主体を**アプリ自身**に置く理由:

- **route 名を解決する必要が無い** — アプリのルーターが解決した結果 (`$request->route()->getName()`)
  をそのまま書くので、定義順・fallback・HEAD→GET の読み替え・405 の判定がアプリと食い違う余地が無い。
  コード索引 (code-review-graph) にも一切依存しない。
- **LLM を経路に入れない** — 探索エージェントが手で書く方式は、家系で実測された 105/107 の誤報の原因である。
- **変更系 (POST/PUT/DELETE) を取り違えない** — 後述のとおり serve のアクセスログは method を出力しない。

**(3) 走行前の雑音を落とす。** provision の疎通確認 (`curl {url}/login`) が記録に混ざると
`login` が毎回「実行済み」になる。疎通確認の通過後に当該 shard の記録ファイルを空にしてから
探索エージェントへ引き渡す。

### 採らなかった案と理由 (実測にもとづく)

| 案 | 理由 |
|---|---|
| `php artisan serve` のアクセスログを解析する | **実物を確認した**: `tmp/bug-hunt/serve-0.log` は `2026-07-16 14:12:20 /login ... ~ 500.99ms` の形で、**method も状態コードも出力しない**。operations.md の分母は method で定義される (同一 URL の GET と POST は別機構) ため突合できない。Laravel の `ServeCommand::handleOutput` が整形時に落としている (vendor 実装を確認済み)。 |
| ブラウザ (`playwright-cli requests`) の通信履歴を退避する (家系の正典 t1) | 履歴は**ページ全読み込みで消える**ため、遷移のたびに LLM が退避コマンドを叩く規約が要る = 手書きと同じ「叩き忘れたら静かに欠測」の弱点が残る。出力も機械可読な構造を持たず番号付きの文字列行であり、正規化器と検体の維持が要る。aicue には走行実績が無く、検体を実測で得ていない状態でこの経路を積むと、契約が想定で固まる。 |
| 探索エージェントに executed.jsonl を手書きさせる | 上記 105/107 の誤報を起こした方式。採らない。 |

家系の正典 (t1) は browser 退避 3 段だが、**同じ不変条件を別の実装形で満たす前例が spirux にある**
(アプリ側の観測器で採る形。台帳は「t1 相当 (担う不変条件は充足。実装形は正典と異なる)」と評価)。
aicue は spirux と同じ形を採り、逸脱として理由を記録する。

## 期待効果

- **使命への貢献**: bug-hunt は「専門知識ゼロの現場作業者でも使える」ことを守るための探索的検査である。
  その網羅報告が嘘をつくと、未検査の操作が検査済みとして扱われ、詰み・認可漏れが本番へ抜ける。
  報告が信用できる状態に戻すことが本件の効果である。
- 「未実行 worklist の逓減」という KPI が初めて意味を持つ (現状は毎回 100% 未実行なので逓減しない)。
- 走行のたびに、どの操作を実際に叩けたかが機械の記録として devnotes に残る。

## 実装方針（概要）

| # | 施策 | 変更対象 |
|---|---|---|
| 1 | 照合器の fail-closed 化 (主入力検証 + 終了コード 3) | `coverage/correlate.py` / `coverage/test_correlate.py` |
| 2 | 実行済み route の記録器 (アプリ側 middleware) | `app/Http/Middleware/` 新規 / `config/bughunt.php` / `bootstrap/app.php` / Feature テスト |
| 3 | bug-hunt 環境への配線 (env 注入・疎通確認後の初期化) | `scripts/bug-hunt-shard.sh` |
| 4 | shard 別 JSONL を束ねて executed.json を作る | `coverage/build_executed.py` 新規 + 自己テスト |
| 5 | 手順と契約の文書更新 (旧 fail-open 記述の削除) | `SKILL.md` / `coverage/README.md` / `.claude/agents/bughunt-shard.md` |
| 6 | Python 自己テストを `composer test` のレーンへ結線 | `tests/Architecture/` 新規 |

施策 1 だけを入れると bug-hunt の Phase 4 が毎回落ちるため、1〜5 は同一 TODO で入れる
(後方互換の並走を残さない = 旧 fail-open 経路は同じ変更で消す)。

## 制約・前提

- `.claude/skills/app-bug-hunt/` 配下の Python は **標準ライブラリのみ** (AGENTS.md §bug-hunt)。
  検証は `python3 -m unittest`。
- 観測器は bug-hunt 未使用時に **完全 no-op** でなければならない (AGENTS.md §bug-hunt のオプトイン契約)。
  既存の `BughuntCoverageMiddleware` と同じく env フラグ既定 false を第 1 の門にする。
- dev DB 防御・`env -i` 隔離・`BUGHUNT_ORCHESTRATOR` の権限分離は本件で一切緩めない。
- 記録器は観測器であり、**アプリの応答を壊してはならない** (書き込み失敗は警告ログのみ)。
- `php artisan serve --no-reload` は env をそのまま子へ渡す (既存の `BUGHUNT_PCOV*` 注入と同じ経路に乗る)。

## スコープ外

- **コード到達カバレッジ (pcov / merge_pcov.py)**: 別系統。触らない。
- **機構分母 (operations.md) の生成と注釈**: 別 feature の担当。
- **所見台帳 (findings.jsonl / validate_findings.py)**: 別 feature。
- **偽造耐性**: 記録ファイルは worktree 内の書き込み可能な場所にあるため、
  「エージェントが書き換えていないこと」は保証しない (家系の他リポジトリも主張していない)。
- **並列 4 shard での実走行による実測**: 本 TODO では fake provision と自己テストで閉じる。
  実 run は課金を伴うため、次回の bug-hunt 走行が初回のフル稼働になる。

---

## 特に判断を仰ぎたい点

1. 家系の正典 (browser 退避 3 段) ではなく **アプリ側観測器**を選ぶ判断は妥当か。
   逸脱として記録する前提で、この選択に見落としている欠陥はないか。
2. 観測器の門 (gate) の設計。既存 `BughuntCoverageMiddleware` は
   `env(BUGHUNT_PCOV)` + `function_exists('\pcov\start')` の二重で、第 2 の門が
   「pcov 拡張が無ければ構造的に不可能」という強い条件になっている。
   route 記録には対になる拡張が無いため、第 2 の門を何にすべきか
   (`app()->isProduction()` 除外 / `APP_ENV=bughunt.local` 限定 / DB 名判定)。
   `APP_ENV=bughunt.local` 限定にすると Feature テスト (testing 環境) から
   配線を検証できなくなり、「黙って何も記録しない」状態を検出できなくなるという緊張がある。
3. 状態コードから `ok|blocked` への写像。2xx/3xx→ok、4xx/5xx→blocked を提案しているが、
   バリデーション不合格 (422) の POST は「操作に到達したが業務は成立していない」ため
   blocked に落ちる。過小申告の方向なので安全側と考えているが、妥当か。
4. 施策 6 (Python 自己テストを `composer test` から実走させる) はスコープに含めるべきか、
   それとも別 TODO に切るべきか。現状 aicue の Python 自己テストはどのレーンからも実行されていない。

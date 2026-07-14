# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項（AGENTS.md）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 役割

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

# user: 概念設計

本 item は「dev/bughunt/CI 環境への ffmpeg 導入（動画レンダー疎通）」。純粋なプロビジョニング＋疎通テストであり、レンダーパイプラインのロジックは変更しない。以下が概念設計の全文。

## 補足コンテキスト（レビュー材料）
- `config/manual.php` L43-44: `render_ffmpeg_binary` = env('RENDER_FFMPEG_BINARY','ffmpeg') / `render_ffprobe_binary` = env('RENDER_FFPROBE_BINARY','ffprobe')
- `FfmpegVideoComposer` は `Illuminate\Support\Facades\Process` 経由でバイナリ実行。非 0 終了は `RenderCompositionException`。
- 既存 `tests/Unit/Render/FfmpegVideoComposerTest.php` は全て `Process::fake()`（実 ffmpeg に触れない）。
- `docker/Dockerfile` は 2 つの `apt-get install` ブロックを持ち、2 つ目で `fonts-noto-cjk`（字幕フォント `Noto Sans CJK JP`）を導入済み。
- CI (`.github/workflows/ci.yml`) は Dockerfile をビルドせず `ubuntu-latest` + `shivammathur/setup-php` で走る（→ Dockerfile 追記だけでは CI に ffmpeg は入らない）。
- devcontainer は `docker-compose.yml` → `docker/Dockerfile` をビルド。bughunt も同一イメージ内で走る。
- 実行環境には ffmpeg 7.1 が既に導入済み（worktree のテストは実走可能）。

---

（以下、conceptual-design.md 全文）

（レビュアーは devnotes/20260714-1050-ffmpeg-provisioning/conceptual-design.md を読んでよい。以下に全文を転記する）

# 概念設計: ffmpeg-provisioning (dev/bughunt/CI 環境への ffmpeg 導入)

## 背景・課題

bug-hunt (real-llm run) の **F-1-0b（既知・未修正）** の恒久対応。Q1 残件の render 部分。

- **症状**: `ffmpeg` バイナリが実行環境に導入されていないため、完成動画レンダー
  (`app/Services/Render/FfmpegVideoComposer.php`) が `sh: 1: exec: ffmpeg: not found` で失敗する。
  結果、S3 への完成動画生成が bughunt 環境で疎通検証できない。
- **根本原因**: アプリは v1 スコープで「動画合成は自前 ffmpeg」を前提としている
  (AGENTS.md 使命 §v1 スコープ) が、開発／CI／bughunt のコンテナイメージ定義
  (`docker/Dockerfile`) に ffmpeg / ffprobe が入っていない。本番では既に ffmpeg で
  合成済みだが、非本番環境のプロビジョニングが本番に追随していなかった。
- **現状のテストは緑**: 既存の `tests/Unit/Render/FfmpegVideoComposerTest.php` は
  すべて `Process::fake()` でコマンド構造のみ検証しており、実 ffmpeg に一切触れない。
  そのため「バイナリ不在」というインフラ欠落を回帰テストが検出できない構造だった。

## 改善アイデア

1. **コンテナイメージへ ffmpeg を導入**: `docker/Dockerfile` の既存 `apt-get install` 行に
   `ffmpeg` を追記し、dev / bughunt が使うイメージ（devcontainer は `docker-compose.yml` →
   `docker/Dockerfile` をビルド、bughunt も同一イメージ内で走る）で
   `ffmpeg` / `ffprobe` が PATH に入るようにする。ffmpeg パッケージは ffprobe も同梱するため
   両バイナリが一度に揃う。字幕焼き込みで使うフォント (`Noto Sans CJK JP`) は既に
   `fonts-noto-cjk` が導入済みのため追加不要。
2. **CI (GitHub Actions) への導入**: CI (`.github/workflows/ci.yml`) は `docker/Dockerfile` を
   ビルドせず `ubuntu-latest` + `shivammathur/setup-php` で走る。したがって Dockerfile への
   追記だけでは CI に ffmpeg は入らない。CI の php ジョブに ffmpeg 導入ステップを明示追加する
   （後述「制約・前提」で詳述）。
3. **実 ffmpeg 疎通テストの追加**: `FfmpegVideoComposer` が実バイナリを発見し、最小の合成
   （fixture 入力 or lavfi 合成入力）が成功して mp4 を出力できることを検証する
   Unit/Feature テストを 1 本追加する。**ffmpeg 存在時のみ実行する skip guard** を付け、
   バイナリ未導入の環境では skip されて赤化しない（bug-hunt 症状の回帰検出点でありつつ、
   導入前の環境を壊さない）。
4. **（任意）ffmpeg 不在時のエラーメッセージ改善**: 現状 `Process` が
   `exec: ffmpeg: not found` の生 stderr を `RenderCompositionException` に載せる。
   バイナリ不在を早期に判定し「ffmpeg がサーバに導入されていません」等の運用者向け
   明示メッセージにできると調査が速い。ただし本 item の必須ではない（優先度 Low）。

## 期待効果

- **使命への貢献**: 使命の v1 スコープ「動画合成は自前 ffmpeg」を非本番環境でも成立させ、
  「標準化されたマニュアル動画を作れる」という中核価値の end-to-end（撮影→合成→S3 完成動画）
  を bughunt で疎通検証できるようにする。F-1-0b の恒久クローズ。
- **回帰防御**: 実 ffmpeg 疎通テストにより「バイナリ不在」というインフラ欠落を
  （導入済み環境で）検出できる。従来の Process::fake テストでは捕まらなかった層を塞ぐ。
- **開発体験**: dev/bughunt/CI いずれでもレンダー経路を実際に動かせるようになる。

## 実装方針（概要）

| 変更対象 | 変更内容 |
|---------|---------|
| `docker/Dockerfile` | 既存 `apt-get install` に `ffmpeg` を追記（1 行）。ffprobe も同梱される |
| `.github/workflows/ci.yml` | php ジョブに `ffmpeg` 導入ステップを追加（ubuntu 用 apt もしくは setup action） |
| `tests/Unit/Render/FfmpegVideoComposerTest.php`（または新規テスト） | 実 ffmpeg で最小合成が成功する疎通テストを追加。ffmpeg/ffprobe 不在時は skip guard |
| `app/Services/Render/FfmpegVideoComposer.php`（任意） | バイナリ不在の早期判定＋明示エラー文言（優先度 Low、スコープに含めるか設計で判断） |

- 既存の `render_ffmpeg_binary` / `render_ffprobe_binary` は `env()` 経由で既定
  `ffmpeg` / `ffprobe`（`config/manual.php` L43-44）。PATH に入れば追加設定不要。
- 疎通テストは「合成 1 本 → output.mp4 が存在し ffprobe で尺が読める」までを最小確認とする
  （字幕・解像度の細部は既存の Process::fake テストが担保済みなので重複させない）。

## 制約・前提

- **ライセンス注記（設計に明記）**: dev / bughunt / CI は**内部利用（非配布・非商用出力）**の
  ため ffmpeg 導入自体はライセンス上問題なし。本番出力コーデック（H.264/H.265 等）の特許は
  **既存の別論点**であり本 item のスコープ外（本アプリは既に本番で ffmpeg を動画合成に
  使用済み）。本変更は「本番で既に使っているものを非本番環境にも揃える」だけで、
  新たなライセンス面を開かない。
- **CI の実体**: CI は Dockerfile を使わない（`ubuntu-latest` + `setup-php`）。GitHub-hosted
  ubuntu runner には ffmpeg が同梱される場合が多いが、runner イメージ変更に対する決定性の
  ため CI ジョブに明示導入ステップを置く方針とする。skip guard があるため万一入らなくても
  CI は赤化しない（テストが skip される）が、「CI でも疎通したい」という brief の要求を
  満たすには明示導入が必要。
- **テスト実行環境**: worktree/実行環境には既に **ffmpeg 7.1** が導入済み
  （`/usr/bin/ffmpeg` / `/usr/bin/ffprobe`）。したがって設計・実装時の worktree では
  疎通テストは skip されず実走する。
- **並列テスト規約**: 疎通テストは `RefreshDatabase` グローバル適用下で `--parallel` 実行される。
  実 ffmpeg プロセスを起動するため、work dir はテストごとに一意（`sys_get_temp_dir()` 配下の
  ユニーク名）にし、後始末する。DB には触れない Unit テストのため DB 競合はない。
- **skip guard の指標**: `config('manual.render_ffmpeg_binary')` を `which`/実行可否で判定する
  ヘルパ（例: `ffmpeg -version` が成功するか）で skip 判定する。ハードコードした `ffmpeg`
  ではなく config 値を尊重する（env で差し替えた環境でも正しく判定）。

## スコープ外

- 本番デプロイイメージ（本番 Dockerfile / インフラ）の ffmpeg 導入。本番は既に ffmpeg 導入済み
  （使命 v1 スコープ）で本 item の対象は非本番環境。
- 出力コーデックの特許・ライセンス調達（別論点。上記のとおり既存の本番運用の議題）。
- ffmpeg のバージョン固定・ビルドオプション最適化（GPL/LGPL ビルド選択、ハードウェアエンコード等）。
  非本番の疎通が目的のため distro 標準パッケージで足りる。
- レンダーパイプラインのロジック変更（`RenderPipeline` の状態機械・ロック順・チケット 2 フェーズ等は
  一切触らない）。本 item は純粋にプロビジョニング＋疎通テスト。
- 字幕・解像度・音声 map 等の合成詳細の再検証（既存 Process::fake テストが担保済み）。


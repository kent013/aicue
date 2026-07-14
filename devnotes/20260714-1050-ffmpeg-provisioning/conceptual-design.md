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
3. **検出責務を二層に分ける（Codex R1 Critical 反映）**: 「ffmpeg が導入されているか」の検証と
   「合成が疎通するか」の検証を分離する。skip guard だけに頼ると、最も検出したい
   「ffmpeg 未導入」が skip に吸収されて赤化しないため。
   - **(層 1) プロビジョニング検証（必須・skip 不可）**: CI (`.github/workflows/ci.yml`) の
     php ジョブに `ffmpeg -version` / `ffprobe -version` の存在確認ステップを置き、未導入なら
     **fail-fast**。加えて字幕フォントの解決を `fc-match "Noto Sans CJK JP"` が Noto CJK family を
     返すことで確認する（未解決なら fail-fast。ffmpeg は代替フォント/tofu でも正常終了するため、
     フォント解決は smoke テストではなく provisioning 側の責務として検証する。Codex R2 反映）。
   - **(層 1-b) Dockerfile 退行の静的ガード**: CI は `docker/Dockerfile` をビルドしないため、
     ubuntu runner 上の存在確認では Dockerfile からの ffmpeg 行削除を検出できない。これを補うため
     **Architecture テスト**で `docker/Dockerfile` に `ffmpeg` / `fonts-noto-cjk` の apt install が
     含まれることを静的 assert する（AGENTS.md「不変条件は Architecture テストへ登録」準拠）。
     **実イメージをビルドして image 内で検証する方式はコスト理由でスコープ外**とし、本 item は
     静的ガードで退行検出する（実イメージ検証との差はここで明記）。
   - **(層 2) 合成疎通検証（skip guard 付き）**: `FfmpegVideoComposer` が実バイナリを発見し、
     **短い日本語字幕を 1 枚焼き込む最小合成**（プレースホルダ 1 クリップ）が成功して
     mp4 を出力できることを検証する smoke テストを追加。合格条件は「正常終了・output.mp4 が
     存在・ffprobe で尺が読める」（コーデック細部・ビット一致は問わない）。skip guard は
     **ローカル任意環境の便宜に限定**（CI/devcontainer/bughunt は導入済み前提で実走）。
     字幕焼き込みを含めることで ffmpeg 本体・filtergraph・字幕描画・`fonts-noto-cjk` の
     フォント解決を一度に通す（lavfi の色板生成だけでは検証密度が足りない）。
   - **テストファースト段取り**: 実装時はまず smoke テストを追加して現行 image（ffmpeg 不在）で
     fail/skip を観測 → Dockerfile/CI 導入で green に転じることを確認する。

（follow-up・本 item スコープ外）**ffmpeg 不在時のエラーメッセージ改善**: 現状 `Process` は
   `exec: ffmpeg: not found` の生 stderr を `RenderCompositionException` に載せる。バイナリ不在を
   早期判定し運用者向け明示メッセージにする改善は**別 item 候補**とし、本 item の
   acceptance criteria には含めない（スコープを疎通に固定してぶれさせない。Codex R1 反映）。

## 期待効果

- **使命への貢献**: 使命の v1 スコープ「動画合成は自前 ffmpeg」を非本番環境でも成立させ、
  **レンダー工程のバイナリ依存疎通**（ローカル合成成功）を回復する。これまで bughunt では
  ffmpeg 不在でレンダー工程が `not found` で止まっていたが、その前提が解消され、以降の
  S3 書き込み・ジョブ連携も動作しうる状態になる。F-1-0b の恒久クローズ。
  - 注: 追加テストが直接検証するのは**ローカル mp4 出力まで**であり、S3 書き込み・ジョブ連携の
    疎通自体はスコープ外（本 item の効果として言い切らない。Codex R1 反映）。S3 連携疎通は
    別 item として切り出す。
- **回帰防御（多層）**: (層 1) CI のバイナリ存在＋フォント解決の必須チェックで「未導入」を
  fail-fast 検出、(層 1-b) Architecture テストで Dockerfile からの ffmpeg 行削除を静的検出、
  (層 2) 実 ffmpeg smoke テストで「合成が疎通するか」を導入済み環境で検出。従来の
  Process::fake テストでは捕まらなかった「実バイナリ・字幕焼き込み経路」を塞ぐ。
  - 注: 本 item の回帰防御は「今回構築したイメージ＋静的ガード」で成立する。実イメージを
    ビルドして image 内で `ffmpeg -version` を叩く継続的検証はコスト理由でスコープ外
    （静的ガードとの差は上記のとおり）。
- **開発体験**: dev/bughunt/CI いずれでもレンダー経路を実際に動かせるようになる。

## 実装方針（概要）

| 変更対象 | 変更内容 |
|---------|---------|
| `docker/Dockerfile` | 既存 `apt-get install` に `ffmpeg` を追記（1 行）。ffprobe も同梱される |
| `.github/workflows/ci.yml` | php ジョブに `ffmpeg`＋`fonts-noto-cjk` 導入ステップ＋`ffmpeg -version`/`ffprobe -version`/`fc-match` 存在・フォント解決確認（層 1・fail-fast）を追加 |
| `tests/Unit/Render/FfmpegVideoComposerSmokeTest.php`（新規） | 実 ffmpeg で日本語字幕焼き込みを含む最小合成が成功する smoke テスト（層 2・skip guard 付き） |
| `tests/Architecture/DockerfileProvisioningTest.php`（新規） | `docker/Dockerfile` に `ffmpeg`/`fonts-noto-cjk` の apt install が含まれることの静的ガード（層 1-b） |

- 既存の `render_ffmpeg_binary` / `render_ffprobe_binary` は `env()` 経由で既定
  `ffmpeg` / `ffprobe`（`config/manual.php` L43-44）。PATH に入れば追加設定不要。
- 疎通テストは「日本語字幕焼き込みを含む合成 1 本 → output.mp4 が存在し ffprobe で尺が読める」
  までを合格条件とする（解像度・音声 map 等の細部は既存の Process::fake テストが担保済みなので
  重複させない）。

## 制約・前提

- **ライセンス注記（設計に明記）**: 本 item は**既存利用範囲（本番で使用済みの ffmpeg）の
  非本番環境（dev / bughunt / CI）への展開**であり、**法的評価を変更しない**。採用する
  ディストリビューション標準パッケージ（Debian/Ubuntu の `ffmpeg`）のライセンス遵守は既存運用に
  従う。出力コーデック（H.264/H.265 等）の特許は**既存の別論点**であり本 item のスコープ外
  （FFmpeg のライセンス条件は配布形態・ビルド構成に依存し、非商用だけで一律に解消するとは限らない
  ため、本 item では断定しない。Codex R2 反映）。非本番は非配布・非商用出力の内部利用である。
- **CI の実体**: CI は Dockerfile を使わない（`ubuntu-latest` + `setup-php`）。GitHub-hosted
  ubuntu runner には ffmpeg が同梱される場合が多いが、runner イメージ変更に対する決定性の
  ため CI ジョブに明示導入ステップ（`ffmpeg` + `fonts-noto-cjk`）＋存在確認
  （`ffmpeg -version`/`ffprobe -version`/`fc-match "Noto Sans CJK JP"`）を置き、**未導入・未解決なら
  fail-fast**（層 1）。これにより「CI では skip guard で黙って通る」ことを防ぐ（層 2 の smoke テストは
  CI では導入済みのため実走する）。Dockerfile 自体からの ffmpeg 行削除は層 1-b の Architecture
  テストで別途静的検出する（CI は Dockerfile をビルドしないため）。
- **テスト実行環境**: worktree/実行環境には既に **ffmpeg 7.1** が導入済み
  （`/usr/bin/ffmpeg` / `/usr/bin/ffprobe`）。したがって設計・実装時の worktree では
  疎通テストは skip されず実走する。
- **並列テスト規約**: 疎通テストは `RefreshDatabase` グローバル適用下で `--parallel` 実行される。
  実 ffmpeg プロセスを起動するため、work dir はテストごとに一意（`sys_get_temp_dir()` 配下の
  ユニーク名）にし、後始末する。DB には触れない Unit テストのため DB 競合はない。
- **skip guard の指標**: `config('manual.render_ffmpeg_binary')` / `render_ffprobe_binary` を
  実行可否（`{binary} -version` が正常終了するか）で判定するヘルパで skip 判定する。
  ハードコードした `ffmpeg` ではなく config 値を尊重する（env で差し替えた環境でも正しく判定）。
  skip はローカル任意環境の便宜であり、CI では層 1 のバイナリ存在必須チェックが別途 fail-fast する。

## スコープ外

- 本番デプロイイメージ（本番 Dockerfile / インフラ）の ffmpeg 導入。本番は既に ffmpeg 導入済み
  （使命 v1 スコープ）で本 item の対象は非本番環境。
- 出力コーデックの特許・ライセンス調達（別論点。上記のとおり既存の本番運用の議題）。
- ffmpeg のバージョン固定・ビルドオプション最適化（GPL/LGPL ビルド選択、ハードウェアエンコード等）。
  非本番の疎通が目的のため distro 標準パッケージで足りる。
- レンダーパイプラインのロジック変更（`RenderPipeline` の状態機械・ロック順・チケット 2 フェーズ等は
  一切触らない）。本 item は純粋にプロビジョニング＋疎通テスト。
- 解像度・音声 map 等の合成詳細の再検証（既存 Process::fake テストが担保済み。smoke テストは
  字幕焼き込み経路のみ実バイナリで通す）。
- **S3 書き込み・ジョブ連携の疎通検証**（別 item）。追加 smoke テストはローカル mp4 出力までを見る。
- **ffmpeg 不在時のエラーメッセージ改善**（follow-up 別 item 候補。本 item の acceptance criteria 外）。

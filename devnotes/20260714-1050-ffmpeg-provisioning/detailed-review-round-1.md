**施策別レビュー**

- **施策1（`docker/Dockerfile` へ `ffmpeg` 追加）: APPROVE**
  - [Suggestion] `ffmpeg` 追加位置は最小差分で妥当。既存 `fonts-noto-cjk` と役割分離も明確。
  - [Suggestion] 将来の可読性のため、同ブロック内で「render runtime dependency」である旨を1行コメント化する余地あり（任意）。

- **施策2（`.github/workflows/ci.yml` で ffmpeg/font 導入 + fail-fast）: APPROVE**
  - [Warning] `fc-match` は `fontconfig` 依存のため、ランナー差異で未導入の可能性がゼロではありません。  
    **修正案**: `sudo apt-get install -y ffmpeg fonts-noto-cjk fontconfig` を明示し、`fc-match` コマンド存在を前提化。
  - [Suggestion] `grep -qi 'Noto Sans CJK'` は実用上十分だが、より厳密にするなら `fc-match -f '%{family}\n' ...` で family のみ判定するとノイズ耐性が上がる。

- **施策3（`tests/Architecture/DockerfileProvisioningTest.php` 新規）: REQUEST_CHANGES**
  - [Critical] `file_get_contents` を `(string)` キャストすると失敗時に空文字となり、原因が不明瞭な false positive/negative を誘発します。  
    **修正案**:  
    1) `$contents = file_get_contents($path);`  
    2) `expect($contents)->not->toBeFalse();`  
    3) `return $contents;`（`@var string $contents` を添える）  
    で「読めない」失敗を明示してください。
  - [Warning] 正規表現が「独立行の `ffmpeg`」に強く依存し、Dockerfile 整形（1行化等）で意図せず壊れます。  
    **修正案**: `apt-get install` 文脈を含む柔軟パターン（例: `/apt-get install -y[\s\S]*\bffmpeg\b/`）に変更し、整形耐性を上げる。
  - [Suggestion] テスト名に「static guard」「regression guard」を含めると責務がさらに明確です。

- **施策4（`tests/Unit/Render/FfmpegVideoComposerSmokeTest.php` 新規）: REQUEST_CHANGES**
  - [Critical] `renderBinariesAvailable()` の `Process::run([$binary, '-version'])` は、バイナリ未導入時に例外化する実装差異があり得て skip 判定自体が落ちるリスクがあります。  
    **修正案**: `try/catch (\Throwable)` で包み、例外時は `false` を返す実装にして「未導入なら確実に skip」にしてください。
  - [Warning] `afterEach` で `sys_get_temp_dir()/ffmpeg-smoke-*` 全削除は、同一ホスト上の他プロセス干渉余地があります。  
    **修正案**: テスト内で作成した `$workDir` を `beforeEach/afterEach` の共有変数に保持し、そのパスのみ削除。
  - [Warning] `mkdir` の戻り値未検証は PHPStan/堅牢性観点で弱いです。  
    **修正案**: `if (!mkdir(...) && !is_dir($dir)) { throw new \RuntimeException(...); }` を採用。
  - [Suggestion] `subtitleSecondary` の日本語固定は目的適合。加えて `render_subtitle_font` をテスト内で明示セットすると再現性が上がります（任意）。

**レビュー観点サマリ**

- 正確性: 施策3/4に例外系・将来整形耐性の改善余地あり。
- 既存整合性: 既存 `FfmpegVideoComposerTest` と責務分離は良好。
- PHPStan Lv10: ほぼ適合だが、I/O失敗の明示扱いを強化すべき。
- テスト計画: 層1/1-b/2の三層構成は非常に良い。
- DTO/JsonResource / Inertia使い分け: 本件は非対象で逸脱なし。
- セキュリティ: AGENTS.md の不変条件に抵触なし（アプリ境界未変更）。
- 波及網羅性: 影響範囲の切り分けは妥当。
- DESIGN/Atomic: UI非変更のため非対象。

**全体判定**

- **CHANGES_REQUESTED**

主に施策3・4の「失敗時の明示性」と「実行環境差異への耐性」を直せば、全体として高品質に承認可能です。
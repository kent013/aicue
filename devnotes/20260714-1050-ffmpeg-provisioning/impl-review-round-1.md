**レビュー結果（T039: ffmpeg-provisioning）**

**`.github/workflows/ci.yml`**
- [Suggestion] `Provision ffmpeg for render smoke` の追加位置（Prepare environment 後、Pint 前）は設計どおりで妥当です。
- [Suggestion] `ffmpeg -version` / `ffprobe -version` の fail-fast は明確で、未導入を早期検知できます。
- [Suggestion] `fontconfig` 明示導入と `fc-match -f '%{family}\n' ... | grep -qi 'Noto Sans CJK'` は、終了コード依存のみより堅牢です（フォールバック検出意図に合致）。
- [Warning] `grep -qi 'Noto Sans CJK'` は family 表記ゆらぎには比較的強い一方、将来の distro 側 naming 変更には脆い可能性があります（現時点では設計要件を満たしており問題なし）。

**`docker/Dockerfile`**
- [Suggestion] 1つ目の apt install ブロックへ `ffmpeg` を独立行追加できており、apt 継続行も壊れていません。
- [Suggestion] コメントで `ffmpeg`/`ffprobe` 依存理由を明記しており、保守性が上がっています。
- [Suggestion] 既存の `fonts-noto-cjk` ブロックを保持しており、設計の役割分離と一致しています。

**`tests/Architecture/DockerfileProvisioningTest.php`**
- [Suggestion] 施策3の静的ガードとして適切です。CI が Docker build しない前提に対する補完防御として成立しています。
- [Suggestion] `file_get_contents` の `false` を `Assert::string()` で明示 fail + 型 narrowing しており、PHPStan level 10 観点で良い実装です。
- [Suggestion] 正規表現 `/^[ \t]*ffmpeg[ \t]*\\\\?[ \t]*$/m` および `fonts-noto-cjk` 版は「独立行アンカー + 任意バックスラッシュ」を満たし、誤検知/見逃しのバランスは設計意図どおりです。
- [Warning] 設計にもある通り、apt リスト整形（1行化など）時にテスト更新が必要なトレードオフは残ります（承認済み事項）。

**`tests/Unit/Render/FfmpegVideoComposerSmokeTest.php`**
- [Suggestion] 施策4として妥当です。実 ffmpeg 実行・日本語字幕・mp4 出力・ffprobe 実測（`totalDurationMs > 0`）まで確認できています。
- [Suggestion] `Process::run([...])` の配列引数でシェルインジェクション面は安全寄りです。
- [Suggestion] 一時ディレクトリを一意生成し、`try/finally` で作成分のみ削除しており安全です（`sys_get_temp_dir()` 全体操作なし）。
- [Suggestion] `mkdir` 戻り値と `is_dir` の二重確認があり、失敗時の例外化も明確です。
- [Suggestion] skip guard（未導入時 skip）はローカル互換性に配慮しつつ、CI 側 fail-fast と役割分離できています。
- [Suggestion] 既存 `FfmpegVideoComposerTest`（`Process::fake`）と責務分離できており、破壊していない前提はテスト結果からも整合しています。

**横断観点**
- [Suggestion] 設計4施策への一致性は高く、差分はインフラ + テストに限定され、禁止事項（アプリ契約/UI/DB/LLM/prompt 変更）への抵触は見当たりません。
- [Suggestion] PHPStan level 10、Pint、`composer test` 結果の提示内容は本実装の妥当性を十分裏づけます。
- [Critical] 該当なし。

**全体判定**
- **APPROVED**
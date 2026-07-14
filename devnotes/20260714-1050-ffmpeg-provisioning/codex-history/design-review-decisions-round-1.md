# 対応マトリクス: design-review Round 1

## 施策1（Dockerfile）: APPROVE
- [Suggestion] render runtime dependency のコメント → 反映する（`ffmpeg` 行に短いコメントを付す）。

## 施策2（ci.yml）: APPROVE
- [Warning] fc-match は fontconfig 依存でランナー差異あり
  - 判断: 対応する。`apt-get install` に `fontconfig` を明示追加し `fc-match` の存在を前提化。
- [Suggestion] `fc-match -f '%{family}\n'` で family のみ判定
  - 判断: 反映する。`fc-match -f '%{family}\n' "Noto Sans CJK JP" | grep -qi 'Noto Sans CJK'` に変更
    （ノイズ耐性向上）。

## 施策3（DockerfileProvisioningTest）: REQUEST_CHANGES
- [Critical] file_get_contents の (string) キャストが失敗を空文字に潰す
  - 判断: 対応する。`Assert::string($contents)`（Webmozart。AGENTS 準拠）で false を明示 fail させて
    string へ narrow。PHPStan level 10 も満たす。
- [Warning] 正規表現が独立行依存で整形に脆い
  - 判断: 対応する。`/apt-get install -y[\s\S]*\bffmpeg\b/` の apt 文脈込み柔軟パターンへ変更。
    `fonts-noto-cjk` も同様に apt 文脈込みで検証。
- [Suggestion] テスト名に static/regression guard を含める
  - 判断: 反映する。

## 施策4（FfmpegVideoComposerSmokeTest）: REQUEST_CHANGES
- [Critical] Process::run がバイナリ未導入時に例外化し skip 判定が落ちうる
  - 判断: 対応する。`renderBinariesAvailable()` を `try { ... } catch (\Throwable) { return false; }` で包み
    「未導入なら確実に skip」を保証。
- [Warning] afterEach の sys_get_temp_dir 全 glob 削除は他プロセス干渉余地
  - 判断: 対応する（より安全な設計へ）。afterEach glob を廃止し、テスト内で作成した `$workDir` のみを
    `try/finally` で確実に削除する（作成分だけを消す・共有 static 不要・PHPStan 単純）。
- [Warning] mkdir 戻り値未検証
  - 判断: 対応する。`if (! mkdir($dir, 0o755, true) && ! is_dir($dir)) { throw new RuntimeException(...); }`。
- [Suggestion] render_subtitle_font をテスト内で明示セット
  - 判断: 反映する。`config()->set('manual.render_subtitle_font', 'Noto Sans CJK JP')` を明示（再現性）。

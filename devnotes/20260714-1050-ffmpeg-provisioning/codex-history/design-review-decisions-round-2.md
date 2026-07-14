# 対応マトリクス: design-review Round 2

## 施策1: APPROVE（残指摘なし）
## 施策2: APPROVE（fontconfig + family 判定で解消）
## 施策4: APPROVE（例外安全・限定削除・mkdir 検証・font 明示で解消）

## 施策3: [Warning] 貪欲マッチ `[\s\S]*` がコメント・別命令中の ffmpeg でも合格する
- 判断: 対応する
- 根拠: 正当。`/apt-get install -y[\s\S]*\bffmpeg\b/` はファイル末尾まで貪欲探索するため、
  apt install と無関係な箇所の `ffmpeg` 部分一致でも合格し、退行ガードとして弱い。
- 対応内容: Codex 提案の**独立パッケージ行アンカー**へ変更（末尾 `\` はパッケージ位置により有無が
  あるため optional 化）:
  - `expect(dockerfileContents())->toMatch('/^[ \t]*ffmpeg[ \t]*\\\\?[ \t]*$/m');`
  - `expect(dockerfileContents())->toMatch('/^[ \t]*fonts-noto-cjk[ \t]*\\\\?[ \t]*$/m');`
  PHP `preg_match` で挙動検証済み: 正例（独立行 ffmpeg / fonts-noto-cjk・末尾 backslash 有無とも）は
  match=1、負例（コメント `# ... ffmpeg` / `echo something-ffmpeg-related`）は match=0。
  リスク欄に「apt リスト 1 行化リファクタ時はパターン更新が必要だが静的ガードとして許容（R2 も許容明言）」
  を明記。

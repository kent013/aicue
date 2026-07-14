# 詳細設計レビュー Round 3

Round 2 の残 Warning（施策3 の貪欲マッチ）に対応しました。残がなければ APPROVED を明示してください。

## 対応（Round 2 施策3 Warning → 対応）

貪欲マッチ `[\s\S]*` を廃止し、**独立パッケージ行アンカー**へ変更（末尾 `\` はパッケージ位置により
有無があるため optional 化）:

```php
test('static guard: docker/Dockerfile が apt パッケージとして ffmpeg を導入している (render runtime 退行防止)', function (): void {
    expect(dockerfileContents())->toMatch('/^[ \t]*ffmpeg[ \t]*\\\\?[ \t]*$/m');
});

test('static guard: docker/Dockerfile が字幕焼き込み用 CJK フォント (fonts-noto-cjk) を導入している', function (): void {
    expect(dockerfileContents())->toMatch('/^[ \t]*fonts-noto-cjk[ \t]*\\\\?[ \t]*$/m');
});
```

`dockerfileContents()` は Round 2 と同一（`Assert::fileExists` + `Assert::string` で読み込み失敗を明示 fail）。

### 挙動検証（PHP preg_match で実測済み）
- 正例 `    ffmpeg \`（末尾 backslash あり）→ match=1
- 正例 `    fonts-noto-cjk \` → match=1
- 正例 `    ffmpeg`（末尾 backslash なし・最終パッケージ位置）→ match=1
- 負例 `# note: we do not use ffmpeg here` → match=0
- 負例 `RUN echo something-ffmpeg-related` → match=0

apt リストを 1 行へ整形するリファクタ時はパターン更新が必要になるが、静的ガードとして許容
（R2 で許容と明言いただいた前提）。整形で独立行が消えれば test が fail し更新要否が顕在化する。

施策 1/2/4 は Round 2 で APPROVE 済みのため変更なし。

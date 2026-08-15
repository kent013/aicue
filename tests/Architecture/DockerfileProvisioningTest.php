<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * Dockerfile プロビジョニング不変条件 (ffmpeg-provisioning / bug-hunt F-1-0b) の static regression guard。
 *
 * v1 スコープ「動画合成は自前 ffmpeg」を dev/bughunt イメージで成立させ続けるための静的ガード。
 * CI (ubuntu runner) は docker/Dockerfile をビルドしないため、この Architecture テストが
 * Dockerfile からの ffmpeg / 字幕フォント削除という退行を検出する唯一の機械的防御になる
 * (実イメージビルド検証はコスト理由でスコープ外)。
 */

/** docker/Dockerfile の内容 (apt install 行を走査する。読めない場合は Assert で明示 fail) */
function dockerfileContents(): string
{
    $path = base_path('docker/Dockerfile');
    Assert::fileExists($path);
    $contents = file_get_contents($path);
    Assert::string($contents, "docker/Dockerfile を読み込めません: {$path}"); // false を明示 fail + string へ narrow

    return $contents;
}

test('static guard: docker/Dockerfile が apt パッケージとして ffmpeg を導入している (render runtime 退行防止)', function (): void {
    // apt install ブロック内の独立パッケージ行として ffmpeg を検証する。行頭〜行末アンカー (/m) で
    // コメント・別命令中の部分一致 ffmpeg を弾く (design-review R2: 貪欲マッチの誤検知回避)。
    // 末尾 `\` はパッケージ位置により有無があるため optional (`\\?`)
    expect(dockerfileContents())->toMatch('/^[ \t]*ffmpeg[ \t]*\\\\?[ \t]*$/m');
});

test('static guard: docker/Dockerfile が字幕焼き込み用 CJK フォント (fonts-noto-cjk) を導入している', function (): void {
    // 字幕 (Noto Sans CJK JP) のフォント解決前提。tofu 化を防ぐ退行ガード (独立行アンカー)
    expect(dockerfileContents())->toMatch('/^[ \t]*fonts-noto-cjk[ \t]*\\\\?[ \t]*$/m');
});

test('static guard: docker/Dockerfile がコード索引ツールを版固定で導入している', function (): void {
    // .claude/settings.json の PostToolUse hook が差分更新を回す前提。版を上げるときは
    // このテストも同じ変更で直す = 対象外拡張子 (ClaudeHooksWiringTest の台帳) の棚卸しが
    // レビューで必ず見える。
    expect(dockerfileContents())->toMatch('/^RUN uv tool install code-review-graph==2\.3\.7$/m');
});

test('static guard: docker/Dockerfile が索引ツールの導入先を固定し検索パスへ載せている', function (): void {
    // 導入先が HOME 由来だと RUN の位置を動かしただけで別ディレクトリへ落ち、
    // hook がセッションごとに「未導入」と告知するようになる。
    $contents = dockerfileContents();

    expect($contents)->toMatch('#^ENV UV_TOOL_BIN_DIR=/home/vscode/\.local/bin$#m');
    expect($contents)->toMatch('#^ENV PATH="/home/vscode/\.local/bin:\$PATH"$#m');
});

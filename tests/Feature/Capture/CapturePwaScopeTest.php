<?php

declare(strict_types=1);

/*
 * 撮影 PWA の scope 前提を pin する (家系裁定 AG-037 / 施策 8)。
 *
 * 組織 URL 配下 (`/organizations/{slug}/app/...`) が PWA の scope に収まることは、
 * 次の 2 つの**前提**に依存している。前提が崩れたらここが赤くなる。
 *
 *  1. `public/manifest.webmanifest` に `scope` キーが**無い**
 *     (W3C 仕様の既定 scope は `start_url` の親パス = `/`)
 *  2. `start_url` が `/app` (組織を持たない分岐入口) のままである
 *
 * service worker の登録 scope (第 2 引数を渡していないこと) は
 * `tests/js/pages/CaptureShowServiceWorker.test.ts` が固定する (言語が違うので別テスト)。
 *
 * ★**保証しないもの**: ここが見るのは静的ファイルの中身だけである。
 *   実ブラウザで解決される `registration.scope` そのものは Browser lane の担当であり、
 *   本テストは「実効 scope が / である」とは主張しない。
 */

test('manifest に scope キーが無い (既定 scope = /)', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifest)->toBeArray();
    expect(array_key_exists('scope', $manifest))->toBeFalse();
});

test('manifest の start_url は分岐入口 /app のまま', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifest)->toBeArray();
    expect($manifest['start_url'])->toBe('/app');
});

test('start_url は組織文脈を持たない入口 route に一致する', function (): void {
    expect(route('capture.entry', absolute: false))->toBe('/app');
});

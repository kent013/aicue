<?php

declare(strict_types=1);

/*
 * TRUSTED_PROXIES 運用 runbook の記入漏れを機械検出する。
 *
 * `TRUSTED_PROXIES` は本番のプロキシ構成を知らないと正しく書けない
 * (本リポジトリには deploy/ / terraform / k8s manifest / nginx conf が無く、
 * 設計時点では実 hop を確認できていない)。CIDR を推測で決め打ちすると
 * 「hop 取りこぼしによる自己 DoS」か「過大信頼による XFF 偽装」のどちらかに倒れるため、
 * 運用者記入欄を docs/trusted-proxies-runbook.md に置き、placeholder が残っている限り
 * fail させる。
 *
 * placeholder を消すだけで通せてしまう点は承知の上で、
 * 「デプロイ前に人間が必ず一度読む」ことを機械的に強制する装置として置く。
 */

const TRUSTED_PROXIES_RUNBOOK = 'docs/trusted-proxies-runbook.md';

/** 運用者記入欄の未記入マーカー。 */
const OPS_FILL_MARKER = '<!-- OPS-FILL -->';

test('trusted-proxies runbook が存在する', function (): void {
    expect(file_exists(base_path(TRUSTED_PROXIES_RUNBOOK)))->toBeTrue(
        TRUSTED_PROXIES_RUNBOOK.' が存在しません (TRUSTED_PROXIES の運用契約の正本)',
    );
});

test('trusted-proxies runbook に運用者記入欄の placeholder が残っていない', function (): void {
    $contents = file_get_contents(base_path(TRUSTED_PROXIES_RUNBOOK));
    expect($contents)->toBeString();
    /** @var string $contents */
    $lines = [];
    foreach (explode("\n", $contents) as $index => $line) {
        // インラインコードスパン (`...`) 内の出現は「この gate の仕組みの説明」であって
        // 未記入欄ではないため除外する (未記入欄は必ず表セルに素で置かれる)
        $stripped = preg_replace('/`[^`]*`/', '', $line) ?? $line;
        if (str_contains($stripped, OPS_FILL_MARKER)) {
            $lines[] = 'L'.($index + 1).': '.trim($line);
        }
    }

    expect($lines)->toBe([],
        TRUSTED_PROXIES_RUNBOOK.' に未記入の運用者記入欄が残っています。'
        .'実 proxy hop 一覧と CIDR 管理主体を埋めてください (推測で CIDR を決め打ちしないこと)。'
        .PHP_EOL.implode(PHP_EOL, $lines));
});

test('trusted-proxies runbook が必須節を持つ (章立ての drift 検知)', function (): void {
    $contents = file_get_contents(base_path(TRUSTED_PROXIES_RUNBOOK));
    expect($contents)->toBeString();
    /** @var string $contents */
    foreach ([
        '実 proxy hop 一覧',
        'CIDR の管理主体',
        'production:preflight',
        'rollback 条件',
    ] as $section) {
        expect($contents)->toContain($section);
    }
});

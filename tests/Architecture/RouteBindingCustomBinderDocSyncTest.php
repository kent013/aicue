<?php

declare(strict_types=1);

use App\Http\Routing\RouteBindingTypes;
use Webmozart\Assert\Assert;

/*
 * `RouteBindingTypes::CUSTOM_BINDER` と docs/architecture.md の列挙を **双方向** で同期する。
 *
 * なぜ必要か: T106 が `{passkey}` を CUSTOM_BINDER へ足したとき、docs/architecture.md の
 * 「単一 SoT の全 binding param 型 inventory」を説明する節が 1 件のままドリフトした
 * (監査サイクル 2 の docs-freshness §2-2)。`{passkey}` は「他人の passkey を 404 に倒す」=
 * セキュリティ不変条件 2 の実装点であり、inventory から落ちている影響は小さくない。
 *
 * 解析対象は CUSTOM_BINDER:BEGIN 〜 CUSTOM_BINDER:END の HTML コメントで囲まれた範囲のみ。
 * 文書全体を grep すると、別の文脈で `{passkey}` に言及しただけで green になり形骸化する
 * (ci-workflow-inventory.test.ts が「YAML を parse してから歩く」のと同じ考え方)。
 *
 * 本テストは DB を触らない (ファイル読み取りのみ)。
 */

/** 同期マーカー。doc 側を書き換えるときは両方を維持すること。 */
const CUSTOM_BINDER_DOC_BEGIN = '<!-- CUSTOM_BINDER:BEGIN -->';

/** 同期マーカー (終端)。 */
const CUSTOM_BINDER_DOC_END = '<!-- CUSTOM_BINDER:END -->';

/**
 * マーカー間の本文を取り出す (純関数)。
 *
 * マーカーがちょうど 1 組でなければ `null` を返す (呼び出し側が違反として報告する)。
 */
function customBinderDocSection(string $markdown): ?string
{
    if (substr_count($markdown, CUSTOM_BINDER_DOC_BEGIN) !== 1) {
        return null;
    }
    if (substr_count($markdown, CUSTOM_BINDER_DOC_END) !== 1) {
        return null;
    }

    $start = strpos($markdown, CUSTOM_BINDER_DOC_BEGIN);
    $end = strpos($markdown, CUSTOM_BINDER_DOC_END);
    Assert::integer($start);
    Assert::integer($end);
    if ($end <= $start) {
        return null;
    }

    return substr($markdown, $start + strlen(CUSTOM_BINDER_DOC_BEGIN), $end - $start - strlen(CUSTOM_BINDER_DOC_BEGIN));
}

/**
 * マーカー間から `` `{param}` `` トークンを抽出する (純関数)。
 *
 * バッククォート囲みの `{name}` だけを拾う。散文中の `{...}` (例: `{organization:slug}` の
 * 説明や JSON 例) を巻き込まないようにするため、param 名は `[a-zA-Z][a-zA-Z0-9_]*` に限定する。
 *
 * @return list<string> 出現順・重複除去済み
 */
function customBinderDocParams(string $section): array
{
    $matches = [];
    $count = preg_match_all('/`\{([a-zA-Z][a-zA-Z0-9_]*)\}`/', $section, $matches);
    Assert::integer($count, 'CUSTOM_BINDER doc の param 抽出に失敗した');

    /** @var list<string> $names */
    $names = $matches[1];

    return array_values(array_unique($names));
}

/**
 * 定数と doc の乖離を列挙する (純関数)。
 *
 * @param  list<string>  $constantKeys  `RouteBindingTypes::CUSTOM_BINDER` の key
 * @param  list<string>  $docParams  doc のマーカー範囲から抽出した param
 * @return list<string> 違反一覧 (空 = 合格)
 */
function customBinderDocSyncViolations(array $constantKeys, array $docParams): array
{
    $violations = [];

    // forward: 定数にある key が doc に載っているか (追加時の追記漏れ = T106 のドリフト)
    foreach ($constantKeys as $key) {
        if (! in_array($key, $docParams, true)) {
            $violations[] = "CB2: CUSTOM_BINDER の `{{$key}}` が docs/architecture.md の同期マーカー範囲に無い (追加時は 1 行追記すること)";
        }
    }

    // reverse: doc にあるが定数に無い = stale (削除時の消し忘れ)
    foreach ($docParams as $param) {
        if (! in_array($param, $constantKeys, true)) {
            $violations[] = "CB3: docs/architecture.md の `{{$param}}` が CUSTOM_BINDER に無い (削除時は doc の行も消すこと)";
        }
    }

    return $violations;
}

/** docs/architecture.md を読む。 */
function customBinderArchitectureMarkdown(): string
{
    $markdown = file_get_contents(base_path('docs/architecture.md'));
    Assert::string($markdown, 'docs/architecture.md を読めない');

    return $markdown;
}

test('CB1: docs/architecture.md に CUSTOM_BINDER 同期マーカーがちょうど 1 組あること', function (): void {
    $markdown = customBinderArchitectureMarkdown();

    expect(substr_count($markdown, CUSTOM_BINDER_DOC_BEGIN))->toBe(1);
    expect(substr_count($markdown, CUSTOM_BINDER_DOC_END))->toBe(1);
    expect(customBinderDocSection($markdown))->not->toBeNull();
});

test('CB2/CB3: CUSTOM_BINDER と docs/architecture.md の列挙が双方向で一致すること', function (): void {
    $section = customBinderDocSection(customBinderArchitectureMarkdown());
    Assert::string($section, 'CUSTOM_BINDER 同期マーカーがちょうど 1 組でない (CB1 を先に直すこと)');

    $violations = customBinderDocSyncViolations(
        array_keys(RouteBindingTypes::CUSTOM_BINDER),
        customBinderDocParams($section),
    );

    expect($violations)->toBe([], "CUSTOM_BINDER と doc の乖離:\n".implode("\n", $violations));
});

test('CB4: 空振り防止 — doc から 1 件以上抽出でき、件数が定数と一致すること', function (): void {
    $section = customBinderDocSection(customBinderArchitectureMarkdown());
    Assert::string($section, 'CUSTOM_BINDER 同期マーカーがちょうど 1 組でない (CB1 を先に直すこと)');

    $docParams = customBinderDocParams($section);

    expect(count($docParams))->toBeGreaterThanOrEqual(1);
    expect(count($docParams))->toBe(count(RouteBindingTypes::CUSTOM_BINDER));
});

test('CB5 正のコントロール: 定数にだけある key を forward 違反として検出すること', function (): void {
    $violations = customBinderDocSyncViolations(['organization', 'passkey'], ['organization']);

    expect($violations)->toContain(
        'CB2: CUSTOM_BINDER の `{passkey}` が docs/architecture.md の同期マーカー範囲に無い (追加時は 1 行追記すること)',
    );
});

test('CB6 正のコントロール: doc にだけある param を reverse 違反として検出すること', function (): void {
    $violations = customBinderDocSyncViolations(['organization'], ['organization', 'ghost']);

    expect($violations)->toContain(
        'CB3: docs/architecture.md の `{ghost}` が CUSTOM_BINDER に無い (削除時は doc の行も消すこと)',
    );
});

test('CB7 負のコントロール: マーカー外の `{ghost}` は照合対象にならないこと', function (): void {
    $markdown = <<<'MD'
        散文の中で `{ghost}` に言及している (マーカー外なので照合対象にならない)。

        <!-- CUSTOM_BINDER:BEGIN -->
        - `{organization}` — MembershipScopedOrganizationBinder
        <!-- CUSTOM_BINDER:END -->

        こちらも範囲外の `{phantom}`。
        MD;

    $section = customBinderDocSection($markdown);
    Assert::string($section);

    expect(customBinderDocParams($section))->toBe(['organization']);
    expect(customBinderDocSyncViolations(['organization'], customBinderDocParams($section)))->toBe([]);
});

test('CB1 負のコントロール: マーカーが無い / 二重にあると section を取り出せないこと', function (): void {
    expect(customBinderDocSection('マーカーの無い文書'))->toBeNull();
    expect(customBinderDocSection(
        CUSTOM_BINDER_DOC_BEGIN."\na\n".CUSTOM_BINDER_DOC_END."\n".CUSTOM_BINDER_DOC_BEGIN."\nb\n".CUSTOM_BINDER_DOC_END,
    ))->toBeNull();
    // 終端が先に来る (順序が壊れた) ケースも取り出せない
    expect(customBinderDocSection(CUSTOM_BINDER_DOC_END."\na\n".CUSTOM_BINDER_DOC_BEGIN))->toBeNull();
});

<?php

declare(strict_types=1);

use App\Support\Legal\BillingRetention;
use Dom\HTMLDocument;

/*
 * /privacy が宣言する「課金取引記録の保有期間」の **behavioral** 検査
 * (SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C3 / C3b)。
 *
 * 背景: 保持年数は config/legal.php -> App\Support\Legal\BillingRetention -> 規約文面 の
 * 三者が一致していなければならない。静的 gate
 * (tests/Architecture/BillingRetentionConfigSingleSourceTest.php) は「blade が literal を
 * 持たないこと」までしか見られないので、**実際に描画された HTML** の側からもう一度
 * 固定する。節ごと消えた場合も、数字だけ別の文脈に残った場合も、ここで赤くなる。
 *
 * ★このテストが保証するもの:
 *   (a) data-legal-retention="billing-records" のマーカー要素が**ちょうど 1 つ**実在する
 *   (b) 保有期間の**節見出し** (id="retention" かつ h1〜h6) が実在する
 *   (c) 家系の先例由来の固定文言「取引関係書類等」がページ内に実在する
 *   (d) **マーカー要素の内側に** config 由来の年数が「N 年」の形 (数字境界つき) で現れる
 *   (e) config の値を変えると描画も追随する (= literal ではなく SSOT 由来である)
 *
 * ★このテストが保証しないもの (誇張しない):
 *   - 文面の日本語が法的に正しいか / 年数が法令上妥当か (**法務レビューの仕事**。
 *     現在の文面は法務レビュー前の**草案**である)
 *   - 散文の意味と実処理 (purge バッチ) の一致。機械が見るのは数値 1 つ・マーカーの
 *     存在・固定文言 1 語だけである
 *   - purge 対象テーブルの網羅性 (BillingRetentionTargetInventoryTest の担当)
 *   - 「文面が変わったのに consent_version が上がっていない」こと
 *     (本タスクでは版を draft-1 から動かさないため、そもそも検査対象にしない)
 *
 * **見出し番号 (「4.」等) では照合しない**。節の並べ替え・番号の繰り下げは文面の意味を
 * 変えないため、属性 (data-legal-retention / id) と固定文言で照合する。
 */

/** 保有期間を宣言するマーカー要素の属性値。 */
const PRIVACY_RETENTION_MARKER_VALUE = 'billing-records';

/** 節見出しの id (番号ではなくこれで照合する)。 */
const PRIVACY_RETENTION_HEADING_ID = 'retention';

/** 家系の先例 (spirux の /privacy) 由来の固定文言。 */
const PRIVACY_RETENTION_FIXED_PHRASE = '取引関係書類等';

/** マーカー要素のテキスト内容を取り出す (無ければ null)。 */
function privacyRetentionMarkerText(string $html): ?string
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $nodes = $document->querySelectorAll('[data-legal-retention="'.PRIVACY_RETENTION_MARKER_VALUE.'"]');

    if ($nodes->length !== 1) {
        return null;
    }

    $node = $nodes->item(0);

    return $node?->textContent;
}

/**
 * 保有期間の**節見出し**を取り出す (無い / 見出し要素でないなら null)。
 *
 * `<p id="retention">` のような「見出しに見えるだけの要素」を通さないため、
 * 要素名が h1〜h6 であることまで見る。
 *
 * @return array{name: string, text: string}|null
 */
function privacyRetentionHeading(string $html): ?array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $node = $document->getElementById(PRIVACY_RETENTION_HEADING_ID);

    if ($node === null) {
        return null;
    }

    $name = strtolower($node->nodeName);
    if (! in_array($name, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
        return null;
    }

    return ['name' => $name, 'text' => $node->textContent];
}

/**
 * 「N 年」が**独立した数値として**現れるかを見る。
 *
 * 素の部分一致だと years=7 のとき「17 年間」「70 年間」の誤表示を通してしまうため、
 * 数字境界 (前後に別の数字が無いこと) を要求する。
 */
function privacyRetentionDeclaresYears(string $text, int $years): bool
{
    return preg_match('/(?<![0-9０-９])'.$years.'(?![0-9０-９])\s*年/u', $text) === 1;
}

it('(a) /privacy が保有期間のマーカー要素をちょうど 1 つ持つ', function (): void {
    $response = $this->get('/privacy');
    $response->assertOk();

    expect(privacyRetentionMarkerText((string) $response->getContent()))->not->toBeNull(
        'data-legal-retention="'.PRIVACY_RETENTION_MARKER_VALUE.'" の要素が /privacy に '
        .'ちょうど 1 つ存在しません。保有期間の宣言はこのマーカーで機械照合しています '
        .'(見出し番号では照合しない)。resources/views/legal/privacy.blade.php を確認してください。');
});

it('(b) /privacy が保有期間の節見出しを持つ', function (): void {
    $response = $this->get('/privacy');
    $response->assertOk();

    $heading = privacyRetentionHeading((string) $response->getContent());

    expect($heading)->not->toBeNull(
        'id="'.PRIVACY_RETENTION_HEADING_ID.'" の**見出し要素** (h1〜h6) が /privacy にありません。');
    expect($heading['text'] ?? '')->toContain('保有期間');
});

it('(c) /privacy が先例由来の固定文言「取引関係書類等」を持つ', function (): void {
    $response = $this->get('/privacy');
    $response->assertOk();

    // Pest の toContain は可変長 needle を取るため、説明文は toBeTrue 側へ渡す。
    expect(str_contains((string) $response->getContent(), PRIVACY_RETENTION_FIXED_PHRASE))->toBeTrue(
        '固定文言「'.PRIVACY_RETENTION_FIXED_PHRASE.'」が /privacy から消えました。'
        .'この語は家系の先例 (spirux の /privacy) に揃えた文面の要であり、'
        .'保持年数が「何に対する期間なのか」を特定しています。');
});

it('(d) マーカー要素の内側に config 由来の年数が現れる', function (): void {
    $response = $this->get('/privacy');
    $response->assertOk();

    $marker = privacyRetentionMarkerText((string) $response->getContent());

    expect($marker)->not->toBeNull();
    expect(privacyRetentionDeclaresYears((string) $marker, BillingRetention::years()))->toBeTrue(
        '保持年数が「N 年」の形でマーカー要素の内側にありません。数字だけ別の文脈に移ると '
        .'「規約が宣言する年数」が機械照合できなくなります。');
    // 数字が「何の期間なのか」まで含めて 1 要素に収まっていること
    expect((string) $marker)->toContain(PRIVACY_RETENTION_FIXED_PHRASE);
});

it('(e) config の保持年数を変えると /privacy の描画も追随する', function (): void {
    // literal で書かれていたらここが赤くなる (SSOT 由来であることの behavioral 証明)。
    $mutated = BillingRetention::years() + 3;
    config()->set('legal.billing_retention_years', $mutated);

    $response = $this->get('/privacy');
    $response->assertOk();

    $marker = privacyRetentionMarkerText((string) $response->getContent());

    expect($marker)->not->toBeNull();
    expect(privacyRetentionDeclaresYears((string) $marker, $mutated))->toBeTrue(
        'config/legal.php の billing_retention_years を変えても /privacy の表示が変わりません。'
        .'blade に年数の literal が書かれている疑いがあります '
        .'(App\Support\Legal\BillingRetention::years() から描画してください)。');
});

it('負のコントロール: 検出ヘルパが実際に効いている', function (): void {
    // 年数判定は数字境界を要求する (17 年 / 70 年を「7 年」と読まない)
    expect(privacyRetentionDeclaresYears('最長7年間', 7))->toBeTrue();
    expect(privacyRetentionDeclaresYears('最長 7 年間', 7))->toBeTrue();
    expect(privacyRetentionDeclaresYears('最長17年間', 7))->toBeFalse();
    expect(privacyRetentionDeclaresYears('最長70年間', 7))->toBeFalse();
    // 数字が「年」に係っていない (見出し番号など) 場合も年数の宣言とは読まない
    expect(privacyRetentionDeclaresYears('7. その他', 7))->toBeFalse();

    // 節見出しは h1〜h6 に限る (見出しに見えるだけの要素を通さない)
    $heading = '<html><body><h2 id="retention">4. 保有期間</h2></body></html>';
    $notHeading = '<html><body><p id="retention">4. 保有期間</p></body></html>';
    expect(privacyRetentionHeading($heading)['name'] ?? null)->toBe('h2');
    expect(privacyRetentionHeading($notHeading))->toBeNull();

    // マーカーは「ちょうど 1 つ」を要求する (重複すると照合先が決まらない)
    $one = '<html><body><p data-legal-retention="billing-records">x</p></body></html>';
    $two = '<html><body><p data-legal-retention="billing-records">x</p>'
        .'<p data-legal-retention="billing-records">y</p></body></html>';
    expect(privacyRetentionMarkerText($one))->toBe('x');
    expect(privacyRetentionMarkerText($two))->toBeNull();
});

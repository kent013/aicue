<?php

declare(strict_types=1);

use App\Support\Seo\SeoManager;
use App\Support\Seo\SeoMeta;
use App\Support\Seo\SeoUrl;
use Webmozart\Assert\Assert;

/*
 * SeoManager: リクエスト単位のメタ保持 (scoped 束縛 = Octane 安全) と
 * <title> 解決の単一経路 (resolveDocumentTitle / resolvePrivateTitle)。
 */

beforeEach(function (): void {
    config([
        'seo.base_url' => 'https://app.example',
        'seo.site_name' => 'Acme',
        'seo.default_title' => 'Acme',
        'seo.title_separator' => ' | ',
    ]);
});

it('未設定時は get() が null を返す', function (): void {
    expect((new SeoManager)->get())->toBeNull();
});

it('set したメタをそのまま返す', function (): void {
    $manager = new SeoManager;
    $meta = SeoMeta::default(new SeoUrl('https://app.example'), '/');
    $manager->set($meta);

    expect($manager->get())->toBe($meta);
});

it('scoped 束縛: 同一リクエスト内は同一インスタンス、リクエスト境界で状態が漏れない', function (): void {
    $a = app(SeoManager::class);
    $b = app(SeoManager::class);
    // 同一リクエスト内では同一インスタンス
    expect($a)->toBe($b);

    $a->set(SeoMeta::default(new SeoUrl('https://app.example'), '/'));
    expect(app(SeoManager::class)->get())->not->toBeNull();

    // リクエスト境界を跨ぐと scoped instance は破棄され、状態は漏れない (singleton なら漏れる)
    app()->forgetScopedInstances();
    $c = app(SeoManager::class);
    expect($c)->not->toBe($a)
        ->and($c->get())->toBeNull();
});

it('resolveDocumentTitle: controller 供給メタの完成 title を最優先する', function (): void {
    $manager = new SeoManager;
    $manager->set(SeoMeta::default(new SeoUrl('https://app.example'), '/')->withTitle('料金プラン'));

    expect($manager->resolveDocumentTitle('home'))->toBe('料金プラン | Acme');
});

it('resolveDocumentTitle: minimal 分類は minimal_titles を合成する', function (): void {
    config([
        'seo.route_classification.minimal' => ['legal.terms'],
        'seo.minimal_titles' => ['legal.terms' => '利用規約'],
    ]);

    expect((new SeoManager)->resolveDocumentTitle('legal.terms'))->toBe('利用規約 | Acme');
});

it('resolveDocumentTitle: private 経路は app_titles を合成し、未登録 route はサイト名のみ', function (): void {
    config(['seo.app_titles' => ['dashboard' => 'ダッシュボード']]);
    $manager = new SeoManager;

    expect($manager->resolveDocumentTitle('dashboard'))->toBe('ダッシュボード | Acme')
        ->and($manager->resolveDocumentTitle('unknown.route'))->toBe('Acme')
        ->and($manager->resolveDocumentTitle(null))->toBe('Acme');
});

it('setPrivateTitle の動的上書きが app_titles 既定より優先される', function (): void {
    config(['seo.app_titles' => ['projects.show' => 'プロジェクト詳細']]);
    $manager = new SeoManager;
    $manager->setPrivateTitle('My Project');

    expect($manager->resolvePrivateTitle('projects.show'))->toBe('My Project')
        ->and($manager->resolveDocumentTitle('projects.show'))->toBe('My Project | Acme');
});

it('resolveDocumentTitle: 未登録だったアプリ画面が固有 title を返す (仕様固定・h1 と一致)', function (
    string $routeName,
    string $expectedFragment,
): void {
    // 実 config/seo.php の app_titles を検証対象にする (beforeEach は site_name のみ上書き)。
    $manager = new SeoManager;

    expect($manager->resolveDocumentTitle($routeName))
        ->toBe("{$expectedFragment} | Acme");

    // config の実値にも固有名が存在すること (エントリ欠落の drift を検出)。
    // route name 自体が dot を含む (例: projects.categories.index) ため
    // config() の dot 記法では引けない。配列を取得しリテラルキーで参照する。
    $appTitles = config('seo.app_titles');
    Assert::isArray($appTitles);
    expect($appTitles[$routeName] ?? null)->toBe($expectedFragment);
})->with([
    'カテゴリ管理' => ['projects.categories.index', 'カテゴリ管理'],
    'ユーザー管理' => ['manage.users.index', 'ユーザー管理'],
    'API キー' => ['organizations.api-keys.index', 'API キー'],
    '接続セッション' => ['organizations.api-keys.sessions.index', '接続セッション'],
    'CLI 導入ガイド' => ['organizations.onboarding.cli', 'CLI 導入ガイド'],
    'MCP 導入ガイド' => ['organizations.onboarding.mcp', 'MCP 導入ガイド'],
    // F-4-02 (T029 取りこぼし) 回帰防止: 通知一覧 (Notifications/Index.svelte h1「通知」)
    '通知' => ['notifications.index', '通知'],
    // T101 DocumentTitleCoverageTest が検出した未網羅 4 件 (Inertia を render する GET named route)。
    'プラン比較' => ['billing.plans', 'プラン比較'],
    // 見出しが `ようこそ、{組織名}` の動的挨拶文のため、h1 ではなく機能を表す静的名を採る
    'プランの選択' => ['onboarding.checkout', 'プランの選択'],
    '課金手続き中です' => ['onboarding.billing-required', '課金手続き中です'],
    '撮影するマニュアルを選ぶ' => ['capture.manuals.index', '撮影するマニュアルを選ぶ'],
]);

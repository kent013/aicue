<?php

declare(strict_types=1);

use App\Enums\Http\InertiaErrorScreenPassthrough;
use App\Enums\Http\InertiaErrorScreenStatus;

/*
 * Inertia XHR の Error 画面差し替え契約 (deny-by-default) の Architecture invariant。
 *
 * 守るもの:
 *   1. 差し替え対象 status は目録に列挙され、型付き enum + 30 文字以上の根拠を持つ (exact-fit)
 *   2. 素通し理由も型付き enum + 30 文字以上の根拠を持ち、死んだ分類を残さない
 *   3. 目録の各 status に**自己完結 Blade が併存**する (非 Inertia 経路の最後の砦を消していない)
 *   4. 例外応答の最終整形 (respondUsing の単一スロット) を奪う登録が 1 箇所しかない
 *   5. Inertia::render が bootstrap/ に直書きされていない
 *      (InertiaRenderPageExistsInvariantTest の走査対象が app/ + routes/ だけのため)
 *
 * ★保証範囲の限界 (誇張しない): 4 と 5 は **PhpToken による token 走査**であり、
 *   コメント (T_COMMENT / T_DOC_COMMENT) は除外する。しかし
 *   変数経由の動的呼び出し (`$method = 'respond'; $x->$method(...)`)・
 *   別名ラッパー越しの呼び出し・**同名の無関係メソッド** (自作クラスの respond())・
 *   将来 Laravel / Inertia が生やす別 API は検出できない。
 *   だからこそ tests/Feature/Errors/ErrorPagesTest.php に **HTTP 経路の admin ケース**を
 *   置き、振る舞い側からも単一スロットの退行を捕まえる (静的 + 振る舞いの二重化)。
 *
 * ★本 gate は新設契約なので実装後の main では必ず green になる (空振りと区別が付かない)。
 *   よって負のコントロールを併置し、検出器が実際に点灯することを fixture で固定する。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/** 目録の下限 (空振り drift ガード)。 */
function inertiaErrorScreenStatusFloor(): int
{
    return 6;
}

/**
 * 目録の上限。**現在値ちょうど** (exact fit)。
 *
 * ★余裕を 1 でも持たせると、その 1 status は「個別の根拠も再レビューも無しに
 *   Error 画面へ差し替えてよい枠」になる。exact fit なら次の 1 件が必ず
 *   「この数値を変える差分」として現れ、素通しすべきでないかの再検討を強制できる。
 */
function inertiaErrorScreenStatusCap(): int
{
    return 6;
}

/** 根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function inertiaErrorScreenReasonMinLength(): int
{
    return 30;
}

/**
 * 差し替え対象 status の目録 (enum case => 具体的根拠)。
 *
 * @return array<int, array{InertiaErrorScreenStatus, string}> status 値をキーにする
 */
function inertiaErrorScreenStatusInventory(): array
{
    return [
        403 => [InertiaErrorScreenStatus::Forbidden,
            '権限不足は利用者が別画面へ移動すれば作業を継続できる種類の失敗であり、素の HTML を'
            .'モーダルに流し込んで画面から出られなくする理由が無い。文言は権限の詳細を漏らさない中立形。'],

        404 => [InertiaErrorScreenStatus::NotFound,
            'cross-org / 削除済みリソースへの遷移で日常的に発生する。テナント境界 404 と不在 404 は'
            .'同一の固定文言・固定 props になるため、差し替えても存在オラクルを作らない。'],

        419 => [InertiaErrorScreenStatus::PageExpired,
            'セッション切れは撮影 PWA で最も踏まれる。ログイン導線が無いと現場作業者が確実に詰むため、'
            .'認証状態を問わずログインへ倒す (戻り先規則 D1)。復旧には document 再生成が要る。'],

        429 => [InertiaErrorScreenStatus::TooManyRequests,
            'throttle 到達時の待ち時間を本文へ出す (API 封筒の details.retry_after と対称)。'
            .'ヘッダだけでは利用者に伝わらず、いつ再試行してよいか分からないまま放置される。'],

        500 => [InertiaErrorScreenStatus::ServerError,
            '障害時も SPA から出られる導線を残す。app.debug=true では差し替えないため、'
            .'開発時の例外詳細ページを中立文言で潰すことは無い (DebugServerError で素通し)。'],

        503 => [InertiaErrorScreenStatus::ServiceUnavailable,
            'メンテナンス中の待ち時間を本文へ出す。500 と同じく app.debug=true では差し替えず、'
            .'非 Inertia のフルロードには自己完結 Blade がそのまま出る。'],
    ];
}

/**
 * 素通し理由の目録 (enum case value => 具体的根拠)。
 *
 * @return array<string, string>
 */
function inertiaErrorScreenPassthroughInventory(): array
{
    return [
        InertiaErrorScreenPassthrough::SuccessOrRedirectStatus->value => '2xx / 3xx は Fortify の各 Response・back()->with(error)・redirect()->intended() など'
            .'アプリのフロー本体そのもの。差し替えると遷移が消えてフロー全体が壊れる。',

        InertiaErrorScreenPassthrough::MachineReadableEnvelope->value => 'api/* と expectsJson は統一エラー封筒 JSON が正しい応答形であり、'
            .'ApiExceptionRenderer が既に責務を持っている。画面へ差し替えると機械側の契約が壊れる。',

        InertiaErrorScreenPassthrough::OperatorFacingSurface->value => 'admin panel 配下は運営者向けの中立テンプレート (errors.admin.*) に分離済みで、'
            .'顧客向け文言を出さないことが既存契約 (ErrorPagesTest が固定)。',

        InertiaErrorScreenPassthrough::NonInertiaRequest->value => 'X-Inertia を持たないフルロードには自己完結 Blade を返す。Vite / Inertia / DB に'
            .'依存しない最後の砦であり、500 経路で白画面にしないための併存が契約。',

        InertiaErrorScreenPassthrough::StaleAssetVersion->value => '旧 build の bundle には Error ページが存在せず、resolvePage が throw して SPA が'
            .'無反応になる (今日のモーダル表示より悪化する)。両辺が非空文字列で一致する場合のみ差し替える。',

        InertiaErrorScreenPassthrough::InertiaProtocolRedirect->value => 'Location / X-Inertia-Location を持つ応答は Inertia 手順上の遷移 (version mismatch の'
            .'409 や Inertia::location) と外部遷移そのもの。差し替えると資産再読込と決済導線が壊れる。',

        InertiaErrorScreenPassthrough::UnlistedStatus->value => '目録に無い status (409 / 422 / 401 等) は deny-by-default で触らない。特に 422 を'
            .'差し替えるとバリデーションの field errors が消え、利用者の入力が失われる。',

        InertiaErrorScreenPassthrough::DebugServerError->value => 'app.debug=true の 5xx を中立文言で潰すと開発時に原因調査の手段を失う。'
            .'Inertia 公式レシピが local/testing を除外しているのと同じ理由 (本番では差し替える)。',
    ];
}

/**
 * 例外応答の最終整形スロット (Handler の $finalizeResponseCallback) を奪うメソッド名。
 * **単一スロット・last-write-wins** のため、2 箇所目が現れたら黙って先勝ちが無効化される。
 *
 * @return list<string> 小文字で比較する (PHP のメソッド名は case-insensitive)
 */
function inertiaErrorScreenRespondSlotMethods(): array
{
    return ['respond', 'respondusing', 'handleexceptionsusing'];
}

/**
 * index 以降で最初の significant token (whitespace / comment 以外) の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function inertiaErrorScreenNextSignificant(array $tokens, int $index): ?int
{
    $count = count($tokens);
    for ($i = $index; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * ソース中の「スロットを奪う呼び出し」の位置を返す (純関数)。
 *
 * ★**必ず PhpToken::tokenize でコメントを除外する**。素朴な文字列一致
 *   (`str_contains($source, '->respond(')`) にすると、bootstrap/app.php に置いた
 *   「2 本目を足すな」という**注意コメント自体**が検出されて即赤になる。
 *   同じ理由で Inertia::render 検出も token 走査にする。
 *   走査方式は tests/Architecture/InertiaRenderPageExistsInvariantTest.php と揃える。
 *
 * 検出する形: `->{method}(` / `?->{method}(` / `::{method}(`
 * (T_OBJECT_OPERATOR | T_NULLSAFE_OBJECT_OPERATOR | T_DOUBLE_COLON の直後の T_STRING)
 *
 * @return list<string> "{relative}:{line}" 形式
 */
function inertiaErrorScreenRespondSlotHits(string $source, string $relative): array
{
    $methods = inertiaErrorScreenRespondSlotMethods();
    $hits = [];

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
            continue;
        }

        $nameIndex = inertiaErrorScreenNextSignificant($tokens, $i + 1);
        if ($nameIndex === null || ! $tokens[$nameIndex]->is(T_STRING)) {
            continue;
        }
        if (! in_array(strtolower($tokens[$nameIndex]->text), $methods, true)) {
            continue;
        }

        $parenIndex = inertiaErrorScreenNextSignificant($tokens, $nameIndex + 1);
        if ($parenIndex === null || $tokens[$parenIndex]->text !== '(') {
            continue;
        }

        $hits[] = "{$relative}:{$tokens[$nameIndex]->line}";
    }

    return $hits;
}

/**
 * ソース中の `Inertia::render(` / `inertia(` 呼び出しの位置を返す (純関数)。
 * bootstrap/ に直書きされると InertiaRenderPageExistsInvariantTest の走査対象外になり、
 * ページ実在 gate がすり抜ける。
 *
 * @return list<string> "{relative}:{line}" 形式
 */
function inertiaErrorScreenInertiaRenderHits(string $source, string $relative): array
{
    $hits = [];

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // Inertia::render( / \Inertia\Inertia::render( (識別子は case 無視)
        $isFacade = ($token->is(T_STRING) && strcasecmp($token->text, 'Inertia') === 0)
            || ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                && str_ends_with(strtolower($token->text), 'inertia\\inertia'));
        if ($isFacade) {
            $colonIndex = inertiaErrorScreenNextSignificant($tokens, $i + 1);
            if ($colonIndex !== null && $tokens[$colonIndex]->is(T_DOUBLE_COLON)) {
                $methodIndex = inertiaErrorScreenNextSignificant($tokens, $colonIndex + 1);
                if ($methodIndex !== null && $tokens[$methodIndex]->is(T_STRING)
                    && strcasecmp($tokens[$methodIndex]->text, 'render') === 0) {
                    $hits[] = "{$relative}:{$token->line}";

                    continue;
                }
            }
        }

        // inertia( helper (メソッド呼び出し・定義・static 参照は除外)
        $isHelper = ($token->is(T_STRING) && strcasecmp($token->text, 'inertia') === 0)
            || ($token->is(T_NAME_FULLY_QUALIFIED) && strcasecmp($token->text, '\\inertia') === 0);
        if (! $isHelper) {
            continue;
        }

        if ($i > 0) {
            $prev = $tokens[$i - 1];
            if ($prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NS_SEPARATOR])) {
                continue;
            }
        }

        $parenIndex = inertiaErrorScreenNextSignificant($tokens, $i + 1);
        if ($parenIndex === null || $tokens[$parenIndex]->text !== '(') {
            continue;
        }

        // 引数なし inertia() は ResponseFactory 取得でページ参照ではない
        $argIndex = inertiaErrorScreenNextSignificant($tokens, $parenIndex + 1);
        if ($argIndex === null || $tokens[$argIndex]->text === ')') {
            continue;
        }

        $hits[] = "{$relative}:{$token->line}";
    }

    return $hits;
}

/**
 * 目録と enum の突き合わせ違反を返す (純関数)。
 * **純関数にするのは負のコントロールのため** — 壊れた目録を引数で渡して
 * 「検出器が実際に点灯する」ことを恒久テストで固定できるようにする
 * (実ファイルを壊さずに mutation 相当を作る)。
 *
 * @param  array<int, array{InertiaErrorScreenStatus, string}>  $inventory
 * @param  list<InertiaErrorScreenStatus>  $cases
 * @return list<string> 違反の説明
 */
function inertiaErrorScreenInventoryViolations(array $inventory, array $cases): array
{
    $violations = [];

    if (count($inventory) < inertiaErrorScreenStatusFloor()) {
        $violations[] = '目録の件数が下限 ('.inertiaErrorScreenStatusFloor().') を下回っている: '.count($inventory);
    }
    if (count($inventory) > inertiaErrorScreenStatusCap()) {
        $violations[] = '目録の件数が上限 ('.inertiaErrorScreenStatusCap().') を超えている: '.count($inventory);
    }

    $caseValues = array_map(
        static fn (InertiaErrorScreenStatus $status): int => $status->value,
        $cases,
    );

    foreach ($caseValues as $value) {
        if (! array_key_exists($value, $inventory)) {
            $violations[] = "enum case {$value} が目録に無い (stale)";
        }
    }

    foreach ($inventory as $key => [$status, $reason]) {
        if (! in_array($key, $caseValues, true)) {
            $violations[] = "目録のキー {$key} に対応する enum case が無い (stale)";
        }
        if ($status->value !== $key) {
            $violations[] = "目録のキー {$key} と enum case ({$status->value}) が一致しない";
        }
        if (mb_strlen($reason) < inertiaErrorScreenReasonMinLength()) {
            $violations[] = "目録 {$key} の根拠が短すぎる (".mb_strlen($reason).' 文字)';
        }
    }

    return $violations;
}

/**
 * 走査対象ファイル (app/ + bootstrap/ + routes/ + config/ の PHP)。
 *
 * @return list<array{absolute: string, relative: string}>
 */
function inertiaErrorScreenScanFiles(): array
{
    $root = base_path();
    $files = [];
    foreach (['app', 'bootstrap', 'routes', 'config'] as $dir) {
        $path = $root.'/'.$dir;
        if (! is_dir($path)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $absolute = $file->getRealPath();
            if (! is_string($absolute)) {
                continue;
            }
            // bootstrap/cache は生成物 (packages.php / services.php)。契約の対象外。
            if (str_contains($absolute, '/bootstrap/cache/')) {
                continue;
            }
            $files[] = [
                'absolute' => $absolute,
                'relative' => ltrim(str_replace($root, '', $absolute), '/'),
            ];
        }
    }

    return $files;
}

/**
 * status に対して Laravel の例外レンダリングが解決する view 名 (系列 fallback を含む)。
 * 見つからなければ null。
 */
function inertiaErrorScreenResolveView(int $status): ?string
{
    foreach (["errors.{$status}", 'errors.'.substr((string) $status, 0, 1).'xx'] as $candidate) {
        if (view()->exists($candidate)) {
            return $candidate;
        }
    }

    return null;
}

test('差し替え対象 status の目録が下限を下回らない (空振り検出)', function (): void {
    expect(count(inertiaErrorScreenStatusInventory()))
        ->toBeGreaterThanOrEqual(inertiaErrorScreenStatusFloor());
});

test('差し替え対象 status の目録が上限を超えない (exact fit)', function (): void {
    expect(count(inertiaErrorScreenStatusInventory()))
        ->toBeLessThanOrEqual(inertiaErrorScreenStatusCap());
});

test('目録と enum の case 集合が一致する (stale 検出)', function (): void {
    expect(inertiaErrorScreenInventoryViolations(
        inertiaErrorScreenStatusInventory(),
        InertiaErrorScreenStatus::cases(),
    ))->toBe([]);
});

test('目録の根拠が 30 文字以上ある', function (): void {
    $short = [];
    foreach (inertiaErrorScreenStatusInventory() as $key => [, $reason]) {
        if (mb_strlen($reason) < inertiaErrorScreenReasonMinLength()) {
            $short[] = "{$key} ({$reason})";
        }
    }

    expect($short)->toBe([]);
});

test('素通し理由 enum の全 case が目録に 30 文字以上の根拠を持つ', function (): void {
    $inventory = inertiaErrorScreenPassthroughInventory();
    $caseValues = array_map(
        static fn (InertiaErrorScreenPassthrough $case): string => $case->value,
        InertiaErrorScreenPassthrough::cases(),
    );

    sort($caseValues);
    $inventoryKeys = array_keys($inventory);
    sort($inventoryKeys);
    expect($inventoryKeys)->toBe($caseValues, '素通し理由の目録と enum の case 集合が一致しない (stale)');

    $short = [];
    foreach ($inventory as $key => $reason) {
        if (mb_strlen($reason) < inertiaErrorScreenReasonMinLength()) {
            $short[] = $key;
        }
    }
    expect($short)->toBe([]);
});

test('目録の各 status に自己完結 Blade が併存し、実際に render できる', function (): void {
    // 契約: **系列 fallback を許す** (個別 view 必須にはしない)。
    //   Laravel の例外レンダリングが errors.{status} → errors.{4xx|5xx} の順で解決するため、
    //   個別 view を必須にすると「fallback で十分な status」に空の view を量産させることになる。
    // ただし「存在する」だけでは最後の砦として弱いので、**解決した view を実際に render** し、
    //   自己完結条件 (inline <style> を持ち /build/ や @vite や data-page を含まない) まで見る。
    $violations = [];

    foreach (array_keys(inertiaErrorScreenStatusInventory()) as $status) {
        $view = inertiaErrorScreenResolveView($status);
        if ($view === null) {
            $violations[] = "status {$status} に対応する自己完結 Blade が無い";

            continue;
        }

        $html = view($view)->render();
        if (! str_contains($html, '<style>')) {
            $violations[] = "{$view} が inline <style> を持たない (自己完結でない)";
        }
        foreach (['/build/', '@vite', 'data-page'] as $forbidden) {
            if (str_contains($html, $forbidden)) {
                $violations[] = "{$view} が {$forbidden} に依存している (自己完結でない)";
            }
        }
    }

    expect($violations)->toBe([]);
});

test('例外応答の最終整形スロットを奪う登録は bootstrap/app.php の 1 箇所だけ', function (): void {
    $hits = [];
    foreach (inertiaErrorScreenScanFiles() as $target) {
        $source = file_get_contents($target['absolute']);
        if (! is_string($source)) {
            continue;
        }
        $hits = array_merge($hits, inertiaErrorScreenRespondSlotHits($source, $target['relative']));
    }

    expect($hits)->toHaveCount(
        1,
        'respond スロットは単一スロット (last-write-wins)。2 箇所目の登録は先の callback を黙って無効化する: '
        .implode(', ', $hits),
    );
    expect($hits[0])->toStartWith('bootstrap/app.php:');
});

test('bootstrap/ に Inertia::render を直書きしない (ページ実在 gate の網から外れるため)', function (): void {
    $hits = [];
    foreach (inertiaErrorScreenScanFiles() as $target) {
        if (! str_starts_with($target['relative'], 'bootstrap/')) {
            continue;
        }
        $source = file_get_contents($target['absolute']);
        if (! is_string($source)) {
            continue;
        }
        $hits = array_merge($hits, inertiaErrorScreenInertiaRenderHits($source, $target['relative']));
    }

    expect($hits)->toBe([]);
});

test('負のコントロール: respond スロット検出器が fixture ソースで点灯する', function (): void {
    $fixture = <<<'PHP'
    <?php
    $exceptions->respond(fn ($r) => $r);
    Inertia::handleExceptionsUsing(fn ($e) => $e);
    $handler->respondUsing(fn ($r) => $r);
    PHP;

    expect(inertiaErrorScreenRespondSlotHits($fixture, 'fixture.php'))->toHaveCount(3);
});

test('正のコントロール: コメント中の respond 記述は検出しない (false positive 防止)', function (): void {
    $fixture = <<<'PHP'
    <?php
    // 2 本目の $exceptions->respond() を足すな。respondUsing() は単一スロット。
    /** Inertia::handleExceptionsUsing() も同じスロットを奪う。 */
    $x = 1;
    PHP;

    // この検査が無いと、bootstrap/app.php に置く注意コメント自体で gate が壊れる。
    expect(inertiaErrorScreenRespondSlotHits($fixture, 'fixture.php'))->toBe([]);
});

test('負のコントロール: Inertia::render 直書き検出器が fixture ソースで点灯する', function (): void {
    $fixture = <<<'PHP'
    <?php
    use Inertia\Inertia;
    return Inertia::render('Error', []);
    PHP;
    expect(inertiaErrorScreenInertiaRenderHits($fixture, 'fixture.php'))->toHaveCount(1);

    $helper = <<<'PHP'
    <?php
    return inertia('Error', []);
    PHP;
    expect(inertiaErrorScreenInertiaRenderHits($helper, 'fixture.php'))->toHaveCount(1);

    $fqcn = <<<'PHP'
    <?php
    return \Inertia\Inertia::render('Error', []);
    PHP;
    expect(inertiaErrorScreenInertiaRenderHits($fqcn, 'fixture.php'))->toHaveCount(1);
});

test('正のコントロール: コメント中の Inertia::render 記述は検出しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    // bootstrap に Inertia::render を直書きしない (inertia('Error') も同じ)。
    /** Inertia::render を書くと gate の網から外れる。 */
    $x = 1;
    PHP;

    expect(inertiaErrorScreenInertiaRenderHits($fixture, 'fixture.php'))->toBe([]);
});

test('負のコントロール: Blade 併存検査が存在しない status で欠落を報告する', function (): void {
    // 存在しない status 系列 (699 → errors.699 / errors.6xx のどちらも無い)
    expect(inertiaErrorScreenResolveView(699))->toBeNull();
    // 正のコントロール: 目録の status は必ず解決できる
    expect(inertiaErrorScreenResolveView(404))->not->toBeNull();
});

test('負のコントロール: 壊れた目録で inventory 検出器が点灯する', function (): void {
    $sound = inertiaErrorScreenStatusInventory();
    $cases = InertiaErrorScreenStatus::cases();

    // M1 相当: 目録から 404 を削る (floor 未満 + stale)
    $missing = $sound;
    unset($missing[404]);
    expect(inertiaErrorScreenInventoryViolations($missing, $cases))->not->toBe([]);

    // M2 相当: 目録に enum 外のキーが増える (cap 超過 + stale)
    $extra = $sound;
    $extra[410] = [InertiaErrorScreenStatus::NotFound, str_repeat('あ', 40)];
    expect(inertiaErrorScreenInventoryViolations($extra, $cases))->not->toBe([]);

    // M3 相当: 根拠が 30 文字未満
    $shortReason = $sound;
    $shortReason[404] = [InertiaErrorScreenStatus::NotFound, '同上'];
    expect(inertiaErrorScreenInventoryViolations($shortReason, $cases))->not->toBe([]);

    // 正のコントロール: 健全な目録では 0 件
    expect(inertiaErrorScreenInventoryViolations($sound, $cases))->toBe([]);
});

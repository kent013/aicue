## Round 2: Round 1 指摘への対応

Round 1 の Warning 9 件はすべて受け入れ、詳細設計を改訂した。以下が対応内容である。
反例が赤になるか、残る不足がないかを判定してほしい。

---

### [Warning] 横断: 正典照合の実行順 → Step 0 へ前倒しした

- 実装順を **Step 0 (正典照合) → 1 → 2 → 3 (赤の確認) → 4 → 5** に改めた。
- lctl へ到達できないままなら **完了ではなく blocked** として差し戻すことを完了条件に明記。
- 照合記録 (`codex-history/canon-reconciliation.md`) に、正典の commit sha・取得日時・
  3 点 (公開 API / 2 検査の検査対象 / 画面側の型・定数名) の比較結果・
  差異があった場合にどちらへ寄せたかを残すことを完了条件に追加。

### [Warning] 施策 1: payload() を迂回する実装を検出できない → 検査を 3 つ足し、走査を字句単位にした

middleware に対する検査:

```php
test('middleware は共有 prop を中継へ委譲する', function (): void {
    $middleware = 'app/Http/Middleware/HandleInertiaRequests.php';
    expect(phpCallNameOccurrences($middleware, 'payload'))->toBe(1);
    expect(file_get_contents(base_path($middleware)))
        ->toContain('FlashNotificationRelay::SHARED_PROP_KEY');
});

test('middleware は一時メッセージを自分で組み立てない', function (): void {
    $middleware = 'app/Http/Middleware/HandleInertiaRequests.php';
    expect(phpCallNameOccurrences($middleware, 'session'))->toBe(0);
    expect(phpCallNameOccurrences($middleware, 'uuid'))->toBe(0);
});

test('middleware は通知種別を直書きしない', function (): void {
    $literals = phpStringLiterals('app/Http/Middleware/HandleInertiaRequests.php');
    foreach (FlashNotificationRelay::KINDS as $kind) {
        expect($literals)->not->toContain($kind);
    }
});
```

- 走査は `token_get_all` 単位に変更した (コメント・docblock を数えない)。
  `phpCallNameOccurrences` は `T_STRING` のうち直後の非空白字句が `(` のものを数える。
  `phpStringLiterals` は `T_CONSTANT_ENCAPSED_STRING` の中身だけを返す。
- 実測: 現行 `HandleInertiaRequests.php` の `session` / `uuid` の呼び出しは
  今回消す flash の 5 行だけであり、0 件固定は達成可能。
  共有 props で将来 session が要る prop を足すときは支援クラスへ寄せるのが既定、
  それが不自然なら**この検査を意図して直す** (直したことがレビューに見える) と設計へ明記した。
- 「迂回の反例を一時的に書いて赤になることを手で確かめる (コミットしない)」をテスト計画に追加。

### [Warning] 施策 1: 中核ヘルパが `/* … */` で fail-closed 性を確認できない → 契約を確定した

- 対象列挙の失敗 / 読み込み失敗 / 対象 0 件はいずれも `RuntimeException` (緑にしない)
- 戻り値は `list<string>`、同一ファイルの複数出現は 1 件に畳み、相対パス昇順
- 文字列リテラルの字句だけを数える (コメント中の同じ語は数えない)

### [Warning] 施策 2: extractKinds に負のコントロールが無い → 3 本足し、形式を固定した

```ts
const KINDS_FIXTURE = `    public const array KINDS = ['success', 'error', 'info', 'warning'];`;

it("抽出器は正例 fixture から値を取り出せる", () => {
    expect(extractKinds(KINDS_FIXTURE)).toEqual(["success", "error", "info", "warning"]);
});

it("語彙の抽出不能・空配列は fail する", () => {
    expect(() => extractKinds("final class X {}")).toThrow(/degenerate PASS/);
    expect(() => extractKinds("public const array KINDS = [];")).toThrow(/degenerate PASS/);
});

it("抽出できない定数名は fail する", async () => {
    expect(() => extractStringConstant(await readRelay(), "NO_SUCH_CONSTANT"))
        .toThrow(/degenerate PASS/);
});
```

受け付ける定義形式は `public const array KINDS = [ … ];` の 1 つに固定し、
正例 fixture を Pint 整形後の実形式そのものにした。

### [Warning] 施策 5: readFlash の利用強制が無い → TS レーンへ deny-by-default の走査を追加した

```ts
const FLASH_READER_FILE = "lib/stores/flash-to-toast.ts";
const FLASH_CONSUMER_FILES = [
    "components/templates/AppLayout.svelte",
    "components/templates/AuthLayout.svelte",
    "components/templates/GuestLayout.svelte",
] as const;

it("flash-to-toast.ts 以外に共有 prop の直読みが無い", async () => {
    const files = await listSourceFiles(JS_ROOT); // 0 件なら throw
    const offenders = files.filter(
        (relative) => relative !== FLASH_READER_FILE && /\.flash\b/.test(readCache[relative]),
    );
    expect(offenders).toEqual([]);
});

it("共有 prop を消費するレイアウトは readFlash を経由する", async () => {
    for (const relative of FLASH_CONSUMER_FILES) {
        expect(readCache[relative]).toContain("readFlash(");
    }
});
```

`resources/js/lib/shared-props.ts` の型宣言 `flash: FlashPayload;` は前にドットが無いので
当たらない (当たるのは `page.props.flash` / `shared.flash` のような読み出しだけ)。

### [Warning] 施策 3: renderedFlash の mixed 返しが PHPStan level 10 で危うい → 提案どおり直した

```php
/** @return array<string, mixed> */
function renderedFlash(TestResponse $response): array
{
    $page = $response->viewData('page');
    if (! is_array($page)) { throw new RuntimeException('Inertia page が配列ではありません'); }
    $props = $page['props'] ?? null;
    if (! is_array($props)) { throw new RuntimeException('Inertia props が配列ではありません'); }
    $flash = $props[FlashNotificationRelay::SHARED_PROP_KEY] ?? null;
    if (! is_array($flash)) { throw new RuntimeException('共有 prop が配列ではありません'); }

    return $flash;
}
```

### [Warning] 施策 3: 正規化の検査が KINDS[0] だけ → dataset × 全 KINDS に広げた

```php
test('文字列でない値は種別によらず null に正規化される', function (mixed $broken): void {
    foreach (FlashNotificationRelay::KINDS as $kind) {
        $flash = renderedFlash($this->withSession([$kind => $broken])->get('/login'));
        expect($flash[$kind])->toBeNull();
    }
})->with([
    '配列' => [['壊れた値']],
    '整数' => [42],
    '真偽値' => [true],
    'オブジェクト' => [new stdClass],
]);
```

### [Warning] 施策 3: withSession は一時メッセージの寿命を見ていない / 未使用 import

- 既存ケースの名前を「session に置かれた値が…」に直した。
- 本物の経路を通すケースを追加した:

```php
test('本物の一時メッセージが着地で 1 度だけ載る', function (): void {
    Route::middleware('web')->get('/__test/flash-relay-origin', fn () => redirect('/login')
        ->with(FlashNotificationRelay::KINDS[0], '保存しました'));

    $landing = $this->get('/__test/flash-relay-origin');
    $flash = renderedFlash($this->get($landing->headers->get('Location') ?? '/login'));
    expect($flash[FlashNotificationRelay::KINDS[0]])->toBe('保存しました');

    expect(renderedFlash($this->get('/login'))[FlashNotificationRelay::KINDS[0]])->toBeNull();
});
```

- `Inertia` import は使わないなら落とすと明記した。

### [Warning] 施策 5: readFlash の実行時検査と戻り値型が一致していない → 正規化器に変えた

```ts
const isPlainObject = (value: unknown): value is Record<string, unknown> =>
    typeof value === "object" && value !== null && !Array.isArray(value);

const asMessage = (value: unknown): string | null =>
    typeof value === "string" ? value : null;

export function readFlash(props: unknown): FlashPayload | null {
    if (!isPlainObject(props)) return null;
    const value = props[FLASH_SHARED_PROP_KEY];
    if (!isPlainObject(value)) return null;

    const payload: FlashPayload = { [FLASH_VISIT_KEY]: asMessage(value[FLASH_VISIT_KEY]) };
    for (const flashKey of FLASH_KEYS) {
        payload[flashKey] = asMessage(value[flashKey]);
    }

    return payload;
}

export function consumeFlash(flash: FlashPayload | null | undefined): void {
    const key = flash?.[FLASH_VISIT_KEY];
    if (typeof key !== "string" || key === "" || key === lastVisitKey) return;
    lastVisitKey = key;
    for (const flashKey of FLASH_KEYS) {
        const message = flash?.[flashKey];
        if (typeof message === "string" && message !== "") {
            addToast(flashKey, message);
        }
    }
}
```

誤っていたリスク記述 (「真偽判定で非文字列を落とすので実害なし」) は削除し、
保証範囲を「返り値の各値が文字列または null であることまで」と書き直した。

### [Warning] 施策 5: 変更ファイル一覧とテスト計画の矛盾 → 解消した

- `tests/js/lib/flash-to-toast.test.ts` を施策 5 の変更ファイルに入れた。
- 「既存テストの更新は不要」という記述を設計書から全て削除した。
- 追加ケース: 非 object / 配列 / `flash` 欠落 / `flash` が配列 / 種別値が非文字列 /
  見分けキーが非文字列 / 正規化後の payload を `consumeFlash` に渡したときの挙動。

---

以上で全体判定 APPROVED にできるか答えてほしい。残る [Critical] / [Warning] があれば
修正案付きで指摘すること。

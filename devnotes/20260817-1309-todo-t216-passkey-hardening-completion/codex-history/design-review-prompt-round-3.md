# Round 3: Round 2 指摘への対応


## [Warning] 施策 B: 配線検査がコメントや未使用コードでも通る

- 判断: 対応する (指摘のとおりソース文字列の包含は主張に届かない)
- 根拠: 呼び出しを消してコメントに残す / 戻り値を採用しない書き方でも緑になる。
- 対応内容: ソース包含の検査は**廃止**し、**宣言経路そのものを再評価する**テストへ置き換えた
  (新規 `tests/Feature/Auth/PasskeyOriginDeclarationTest.php`)。
  環境変数を `$_SERVER` / `$_ENV` / `putenv` の 3 経路へ注入して
  `config/fortify.php` を評価し、**返ってきた配列**が正規形になっていることを見る。
  検査は 3 本 — (a) `HTTPS://App.Example.com:443/` が `https://app.example.com` になる、
  (b) 末尾のカンマによる空要素が**残る**、(c) 宣言が無いとき `APP_URL` からの導出も正規形になる。
  子プロセス方式 (Symfony Process) は採らない — 同一プロセスで `require` して返り値を
  見れば設定の採用まで確かめられ、`finally` で環境変数を元へ戻す限り
  `--parallel` (プロセス分離) で漏れないためである。復元は「元が未設定なら消す」
  ことまで含めて実装する、と設計へ注記した。

## [Warning] 施策 B: 「DNS 名の字形」と「不正値を変形しない」の境界が一致していない

- 判断: 対応する (指摘の 2 案のうち**後者**を採る)
- 根拠: 正規化器にラベル規則を持たせると DNS 名の規則が 2 か所に書かれ、必ず食い違う。
  妥当性の判断は検証器 1 か所に置くのが正しい。
- 対応内容: 正規化器の契約を
  「`scheme://host[:port]` へ**分解できる**値だけを対象にする。**妥当性は判断しない**」
  へ改めた (「不正値はそのまま返す」という説明は削除)。
  分解できない値 (path / query / fragment / 利用者情報 / 角括弧の IPv6 / 余分なコロン /
  ホスト欠落) はそのまま返す、という部分だけを残した。
  あわせて**抜け道が無いこと**を検証器側の表駆動テストで固定する —
  `-app.example.com` / `app..example.com` / `.example.com` / `app.example.com.` /
  IP リテラルを**正規化器へ通してから**検証器へ渡し、いずれも例外になることを確かめる。
  正規化器のテスト表にも「分解できるので正規化はするが妥当性は見ない」行を追加した。

## [Critical] 施策 C: 既存ゲートだけでは同期購読を保証できない

- 判断: 対応する (指摘のとおり。前提の記述が過大だった)
- 根拠: 実読で確認した — `QueueDispatchAtomicityInventoryTest` が固定するのは
  commit 後ずらし (D1〜D6) と接続の `after_commit` (D4) までであり、
  購読が `ShouldQueue` を実装してキューへ載ることは見ていない。
- 対応内容: 節 C-3 に「既に固定されているもの / 足りないもの」の表を置き、
  足りない 2 点を契約検査 1 本で閉じる設計にした。
  `app('events')->getRawListeners()` から `PasskeyDeleted` の購読を取り出し、
  (a) 形が `[クラス名, メソッド名]` であること (無名関数やオブジェクトは落とす)、
  (b) どのクラスも `ShouldQueue` を実装していないこと、
  (c) 顔ぶれが `[RecordSecurityEvent, ClearRecentAuthOnPasskeyChange]` の
  **2 つちょうど**であること (増減のどちらでも赤) を固定する。
  実読で現状を確認済み: 購読は上記 2 つで、どちらも `ShouldQueue` を実装していない。
  登録の形も `[クラス名, メソッド名]` である
  (`Dispatcher::listen()` が受け取った値をそのまま `listeners[event][]` へ積むことを
  vendor 実読で確認)。
  実挙動側でも、人工的な例外購読だけに頼らず「本物の監査記録が同じトランザクションで
  書かれ、巻き戻ると消える」ことを確かめる、と明記した。
  受け入れ条件にも同項目を追加した。

---

## 修正後の該当箇所 (詳細設計からの抜粋)

### 施策 B-1 正規化器の契約と正規表現
final class PasskeyOriginCanonicalizer
{
    /** scheme ごとの既定 port (書かれていても意味を持たない port) */
    private const DEFAULT_PORTS = ['https' => 443, 'http' => 80];

    /** 接続元 1 件を正規形へ寄せる (解釈できない値は小文字化して返すだけ)。 */
    public static function canonicalize(string $origin): string
    {
        $value = strtolower(trim($origin));

        // scheme://host[:port][/] へ**分解できる**値だけを対象にする。
        // ホスト部の字形を `[a-z0-9.-]+` に限るので、利用者情報 (`user@…`) /
        // 角括弧の IPv6 / 余分なコロン / path / query / fragment を持つ値は一致せず、
        // **そのまま返す** (検証器が位置付きで拒否する)。
        // ★ここでホスト名の**妥当性**は見ない (ラベル規則は検証器 1 か所に置く)。
        if (preg_match('#^([a-z][a-z0-9+.\-]*)://([a-z0-9.\-]+)(?::(\d{1,5}))?/?$#', $value, $matches) !== 1) {
            return $value;
        }

        $scheme = $matches[1];
        $host = $matches[2];
        $port = $matches[3] ?? '';

        if ($port !== '' && (self::DEFAULT_PORTS[$scheme] ?? null) === (int) $port) {
            $port = '';
        }

        return $scheme.'://'.$host.($port === '' ? '' : ':'.$port);
    }

    /**
     * 宣言 (CSV) から接続元の列を作る。**空要素は落とさない**
     * (設定の書き損じ = 余分なカンマ を起動時に表面化させるため)。
     *
     * @param  string|null  $declared  PASSKEYS_ALLOWED_ORIGINS の宣言値 (未宣言は null)
     * @param  string  $derivedOrigin  APP_URL から導出した接続元 (宣言が無いときの既定)
     * @return list<string>
     */
    public static function declaredList(?string $declared, string $derivedOrigin): array
    {
        $csv = $declared === null ? '' : trim($declared);

        // 宣言が無い / 空文字なら APP_URL からの導出 1 件に倒す
        // (env ファイルにキーだけ残す運用を壊さないため、空文字は「未宣言」と同じ扱い)。
        if ($csv === '') {

### 施策 B-4 宣言経路の再評価
#### B-4. 宣言側の配線と実効値の検査 (契約検査へ 2 本)

宣言側が正規化器を呼ばなくなったこと (= 施策 B の配線が外れたこと) を検出する。

**実効値だけを見る検査は検出力が弱い** — 手元の `APP_URL` が既に正規形なら、
`config/fortify.php` から正規化器の呼び出しを外しても緑のままになりうる。
**ソース文字列の包含で代用するのも不十分**である (呼び出しを消してコメントに残す /
戻り値を採用しない書き方でも通る)。

そこで **宣言経路そのものを再評価する**。環境変数を注入して
`config/fortify.php` を評価し、**返ってきた配列が正規形になっている**ことを見る
(新規 `tests/Feature/Auth/PasskeyOriginDeclarationTest.php`)。

```php
/**
 * 環境変数を差し替えて config/fortify.php を評価し、返り値を得る。
 *
 * env() は $_SERVER → $_ENV → putenv の 3 経路を見るため 3 つとも埋める
 * (tests/bootstrap.php が同じ作法を採っている)。**必ず finally で元へ戻す**。
 * 設定ファイルの評価は副作用として fortify-options を同じ値で書き直すだけで、
 * 他への影響を持たない (Features::* は options を config へ書いて識別子を返す builder)。
 *
 * @param  array<string, string>  $overrides
 * @return array<string, mixed>
 */
function evaluateFortifyConfigWith(array $overrides): array
{
    $saved = [];
    foreach ($overrides as $key => $value) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        /** @var array<string, mixed> $config */
        $config = require base_path('config/fortify.php');

        return $config;
    } finally {
        foreach ($saved as $key => [$server, $env, $raw]) {
            // 元が未設定なら消す (空文字で復元しない = 「未宣言」の意味が変わるため)
            $server === null ? array_key_exists($key, $_SERVER) && $_SERVER[$key] = $server : $_SERVER[$key] = $server;
            // 実装では unset() を使って元の「不在」を正確に戻す (下の注記を参照)
        }
    }
}

test('宣言経路が正規形へ寄せる (末尾スラッシュと既定 port と大文字)', function (): void {
    $config = evaluateFortifyConfigWith([
        'PASSKEYS_ALLOWED_ORIGINS' => 'HTTPS://App.Example.com:443/',
    ]);

    expect(data_get($config, 'passkeys.allowed_origins'))->toBe(['https://app.example.com'])
        ->and(data_get($config, 'passkeys.raw_allowed_origins'))->toBe(['https://app.example.com']);
});

test('宣言経路は空要素を残す (書き損じを起動時に表面化させるため)', function (): void {
    $config = evaluateFortifyConfigWith([
        'PASSKEYS_ALLOWED_ORIGINS' => 'https://app.example.com,',
    ]);

    expect(data_get($config, 'passkeys.raw_allowed_origins'))->toBe(['https://app.example.com', ''])
        ->and(data_get($config, 'passkeys.allowed_origins'))->toBe(['https://app.example.com']);
});

test('宣言が無ければ APP_URL から導出し、それも正規形になる', function (): void {
    $config = evaluateFortifyConfigWith([
        'APP_URL' => 'https://App.Example.com:443/app',
        'PASSKEYS_ALLOWED_ORIGINS' => '',
    ]);

    expect(data_get($config, 'passkeys.allowed_origins'))->toBe(['https://app.example.com'])
        ->and(data_get($config, 'passkeys.relying_party_id'))->toBe('app.example.com');
});
```

> **実装上の注記**: 復元は「元が未設定なら `unset($_SERVER[$key])` /
> `unset($_ENV[$key])` / `putenv($key)` (値なし = 削除)」で行う。
> 上の擬似コードの三項演算子はそのまま書かず、素直な `if` で書くこと
> (未設定を空文字で復元すると「未宣言」の意味が変わり、後続のテストへ漏れる)。
> `--parallel` はプロセスごとに独立しているので、`finally` で戻す限り漏れない。

```php
test('実効値の許可する接続元が正規形である (宣言側が正規化器を通っている)', function (): void {
    $origins = config('passkeys.allowed_origins');
    expect($origins)->toBeArray();
    /** @var array<int, mixed> $origins */
    foreach ($origins as $origin) {
        expect($origin)->toBeString();
        /** @var string $origin */
        expect(PasskeyOriginCanonicalizer::canonicalize($origin))->toBe(
            $origin,
            '宣言側 (config/fortify.php) が正規化器を通っていない可能性がある'
        );
    }
});
```

### PHPStan 適合チェック

### 施策 C-3 巻き戻りの前提
#### C-3. 巻き戻りが成立する前提 (保証範囲を限定し、足りない分は固定する)

巻き戻るのは「**同期の購読が、削除と同じトランザクションの中で失敗したとき**」だけである。
購読が commit 後へ回されていたら (キュー投入 / commit 後実行) 削除は確定済みになる。

既存の固定と、足りない分を切り分ける (**実読で確認済み**):

| 前提 | 既に固定されているか |
|---|---|
| 購読が **commit 後へずらされない** (`ShouldHandleEventsAfterCommit` / `ShouldDispatchAfterCommit` / `afterCommit` の真値) | **されている**。`QueueDispatchAtomicityInventoryTest` の D1〜D6 が `app/` 全クラスと first-party の実行時 PHP に対して 0 件で固定 (免除機構を持たない) |
| キュー接続の `after_commit` が `sync` 以外で真にならない | **されている** (同テスト D4) |
| **購読が `ShouldQueue` を実装していない** (= 同期で走る) | **されていない**。上のゲートは commit 後ずらしだけを見ており、キューに載ること自体は見ない |
| **削除イベントの購読の顔ぶれ**が変わっていない (無名関数やキュー化された購読が増えていない) | **されていない** |

足りない 2 つを、契約検査へ 1 本足して閉じる:

```php
test('パスキー削除イベントの購読は同期で走る 2 つだけである (巻き戻りの前提)', function (): void {
    $raw = app('events')->getRawListeners();

    expect($raw)->toHaveKey(PasskeyDeleted::class);

    $classes = [];
    foreach ($raw[PasskeyDeleted::class] as $listener) {
        // 期待する形は [クラス名, メソッド名] だけである。無名関数やオブジェクトが
        // 混ざったら**ここで落とす** (同期かどうかを機械的に判定できなくなるため)。
        expect($listener)->toBeArray('パスキー削除の購読に想定外の形が登録されている');
        /** @var array{0: string, 1: string} $listener */
        expect($listener[0])->toBeString();

        $classes[] = $listener[0];

        // ShouldQueue を実装した購読はキューへ載り、削除の transaction の外で走る。
        expect(is_a($listener[0], ShouldQueue::class, true))->toBeFalse(
            "{$listener[0]} がキュー化された。削除の巻き戻りの前提 (同期購読) が崩れる"
        );
    }

    // 顔ぶれを完全一致で固定する (増減のどちらでも赤くなる)。
    expect($classes)->toBe([RecordSecurityEvent::class, ClearRecentAuthOnPasskeyChange::class]);
});
```

- 実読で確認済みの現状: 購読は 2 つ —
  `RecordSecurityEvent::handlePasskeyDeleted` (`Event::subscribe` 経由) と
  `ClearRecentAuthOnPasskeyChange::handleDeleted`
  (`AppServiceProvider` の `Event::listen`)。どちらも `ShouldQueue` を実装していない。
  登録順もこの順である (監査記録 → 直近認証の失効)。
- **実挙動の側でも本物の購読を確かめる**: HTTP 削除経路のテストで、
  人工的に例外を投げる購読を足す前に「監査記録が削除と同じトランザクションで書かれ、
  巻き戻ると消える」ことを確かめる (`PasskeyAuditTrailTest` が正常系を、
  本テストが巻き戻り系を持つ)。

ドキュメント (`docs/auth-security-mechanisms.md` §5) へ 1 項追記:

> - **削除の原子性はアプリ側が埋めている**。パッケージ側の削除処理は
>   「行を消してからイベントを発火する」形で 2 つをトランザクションで包まない。
>   本アプリは `EnsureLoginMethodRemains` が削除 route 全体をトランザクションで包むため、
>   **同期の購読** (監査記録など) が失敗すると**削除ごと巻き戻る**
>   (購読が commit 後へ回されていたら成り立たない。その形が入らないことは
>   キュー投入の原子性のゲートが別途固定している)。
>   **登録経路にはこの埋め合わせが無い** (手段を減らす操作ではないため関門が付かない) —
>   登録の購読側が失敗した場合、行は残りイベント処理だけが失われる。
>   前提の固定は `PasskeyPackageContractTest`、実挙動は `PasskeyDeletionAtomicityTest`。

### PHPStan 適合チェック

---

上記の対応を踏まえ、各施策の判定と全体判定を再度出してください。

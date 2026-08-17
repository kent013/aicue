# Round 2: Round 1 指摘への対応


## [Critical] 施策 B: 正規化器の正規表現が広すぎる (利用者情報 / 角括弧 IPv6 / 余分なコロン)

- 判断: 対応する
- 根拠: 指摘のとおり `([^/?#]+?)` は `user@host` や `[::1]` を host として飲み込み、
  そこから既定 port だけを落とす形になる。これは「不正値は修復しない」という
  本クラスの約束を破り、検証器へ**部分的に整った不正値**を渡すことになる。
- 対応内容: ホスト部を DNS 名の字形へ絞った
  (`#^([a-z][a-z0-9+.\-]*)://([a-z0-9.\-]+)(?::(\d{1,5}))?/?$#`)。
  表駆動テストへ 4 ケース追加 — `https://user@app.example.com:443` /
  `https://[::1]:443` / `https://app.example.com:8443:9` / `https://:443` は
  いずれも**変形されずに返る** (検証器が位置付きで拒否する)。

## [Warning] 施策 B-4: 実効値だけの検査では配線が外れても緑になりうる

- 判断: 対応する
- 根拠: 手元の `APP_URL` が正規形なら、宣言側から正規化器の呼び出しを外しても
  実効値は正規形のままである。検出目的に対して弱い。
- 対応内容: **配線そのものを固定する検査**を足した
  (`config/fortify.php` のソースに `PasskeyOriginCanonicalizer::declaredList(` が現れること)。
  実効値の検査はその補助として残す。
  設定の再読込を伴う方式は採らない — 環境変数の注入と復元がテストレーンの前提
  (`--parallel` / 設定キャッシュ) に依存し、壊れ方が分かりにくいため。
  宣言 (CSV) から列を作る部分は純粋関数 `declaredList()` として切り出してあるので、
  非正規形の宣言に対する挙動は単体テストで端から端まで確かめられる。

## [Warning] 施策 B: 生値非露出テストが部分漏れを見逃す

- 判断: 対応する
- 根拠: 接続元を丸ごと出さなくても、ホスト部が出れば配備ログへの露出としては同じである。
- 対応内容: 禁止文字列を 3 つ (接続元の全体 / そのホスト部 / 身元の識別子) に分けて
  個別に確かめる形へ変更。相互整合の違反では両方を隠すことを固定する。
  あわせて**例外文に本物らしいホスト名の例を書かない**という規則を設計へ明記した
  (例文が検査を素通りさせたり、逆に自分の例文で赤くしたりするため。
  身元の識別子の文面から `app.example.com` の例示を削除済み)。

## [Suggestion] 施策 B: 「末尾スラッシュ禁止」という文面が運用説明と食い違う

- 判断: 対応する
- 対応内容: 検証器の文面は「検証器へ届く値は正規形であるべき」という言い方に寄せる、
  という規則を設計へ明記した (宣言側では受理されることが読み取れる形にする)。

## [Critical] 施策 C: 巻き戻りの前提 (購読が同期で、同じトランザクション内で走ること)

- 判断: 対応する (ただし前提の固定は既存ゲートに委ねる)
- 根拠: 指摘のとおり、購読が commit 後へ回されていたら削除は確定済みになる。
  ただしこの前提は**本アプリでは既に機械で固定されている** — AGENTS.md ドメイン規約 11
  (キュー投入の原子性) の `QueueDispatchAtomicityInventoryTest` が `app/` 全クラスに対し
  `ShouldHandleEventsAfterCommit` / `ShouldDispatchAfterCommit` / `afterCommit` の
  真値を **0 件で固定**しており、免除機構そのものが無い。
  同じ前提をもう 1 本作ると固定点が 2 か所に割れる。
- 対応内容: 節「C-3 巻き戻りが成立する前提」を新設し、保証範囲を**同期購読**に限定して
  記述した。テスト名も「同期購読の失敗で」へ変更。ドキュメントの文面と
  「保証しないもの」にも同じ限定を書き、既存ゲートを参照する。

## [Warning] 施策 C: `DB::beginTransaction()` を検出していない

- 判断: 一部反論・一部対応
- 根拠: 判定は**部分文字列の包含**なので、字句 `beginTransaction` は
  `DB::beginTransaction()` にも `$connection->beginTransaction()` にも当たる
  (正規表現でも完全一致でもない)。したがって見逃しは無い。
- 対応内容: それでも自己テストに現れていなかったのは指摘のとおりなので、
  正例を 4 種 (`DB::transaction` / `DB::beginTransaction` /
  `$connection->beginTransaction` / `->transaction(`) へ増やした。
  関数名も過信しない名前 `declaresCommonTransactionBoundary` へ改め、
  docblock と失敗メッセージに「代表的な書き方の検知であって網羅ではない」と明記した。

## [Warning] 施策 C: 登録処理の非原子性の固定は主目的から外れる

- 判断: 一部対応 (残すが位置づけを明示)
- 根拠: 登録経路の窓は**削除経路の埋め合わせの説明と対になっている**。
  「削除は埋めた」とだけ書くと、読み手は登録経路も同じだと誤解する。
  可視化しておく価値は残す。
- 対応内容: テスト名を「既知の窓: …」へ変え、赤くなったときの対応
  (アプリ側の実装変更は不要で、本テストと文書の該当記述を消す) を
  テストのコメントと失敗メッセージに明記した。

## [Suggestion] 施策 D: 対象パスに `config/fortify.php` を含めるべきでは

- 判断: 見送る (根拠を設計へ追記)
- 根拠: 宣言側で正規形へ寄せること自体は**正典と同じ形**である
  (正典も宣言の評価時に正規化する)。逸脱しているのは「正規形でなかったときに
  どこで落とすか」だけなので、逸脱していないファイルを対象パスへ書くべきでない。
  登録簿は対象パスの和集合で重複を許さないため、逸脱していない部分まで抱え込むと
  将来の別の逸脱を登録できなくする方向に効く。
- 対応内容: この根拠を施策 D の本文へ追記した。

---

## 修正後の該当箇所 (詳細設計からの抜粋)

### 正規化器の正規表現とテスト表 (施策 B-1)
```php
 * 正規形へ寄せる変形は 3 つだけ:
 *   1. 前後空白の除去と小文字化 (RFC 3986 上 scheme と host は大小文字を区別しない)
 *   2. 根を表す末尾スラッシュ 1 個の除去 (裁定 2026-08-04「末尾スラッシュは正規化受理で統一」)
 *   3. scheme に対応する既定 port の除去 (https は 443 / http は 80)
 *
 * 3 が要る理由: ブラウザが申告する origin は既定 port を含まない。
 * 照合は webauthn-lib の `in_array(..., true)` = **厳密な文字列比較**なので、
 * `https://example.com:443` と書いた設定は一致せず**全ての手続きが無言で失敗する**。
 */
final class PasskeyOriginCanonicalizer
{
    /** scheme ごとの既定 port (書かれていても意味を持たない port) */
    private const DEFAULT_PORTS = ['https' => 443, 'http' => 80];

    /** 接続元 1 件を正規形へ寄せる (解釈できない値は小文字化して返すだけ)。 */
    public static function canonicalize(string $origin): string
    {
        $value = strtolower(trim($origin));

        // scheme://host[:port][/] だけを対象にする。**ホスト部は DNS 名の字形に限る** —
        // 利用者情報 (`user@…`) / 角括弧の IPv6 / 余分なコロンを含む値は
        // ここで一致させない (一致させると「不正値から既定 port だけ落とす」形になり、
        // 修復器ではないという本クラスの約束を破る)。
        // path / query / fragment を持つ値も同様に一致せず、**そのまま返す**
        // (検証器が位置付きで拒否する)。
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
            return [self::canonicalize($derivedOrigin)];
        }

        return array_map(self::canonicalize(...), explode(',', $csv));
    }
}
```

### 宣言側の配線の固定 (施策 B-4)
#### B-4. 宣言側の配線と実効値の検査 (契約検査へ 2 本)

宣言側が正規化器を呼ばなくなったこと (= 施策 B の配線が外れたこと) を検出する。

**実効値だけを見る検査は検出力が弱い** — 手元の `APP_URL` が既に正規形なら、
`config/fortify.php` から正規化器の呼び出しを外しても緑のままになりうる。
そこで **配線そのもの (宣言側のソースに正規化器の呼び出しがあること)** を先に固定し、
実効値の検査はその補助として持つ。

```php
test('宣言側 (config/fortify.php) が正規化器へ委譲している (配線の固定)', function (): void {
    $source = file_get_contents(base_path('config/fortify.php'));
    expect($source)->toBeString();
    /** @var string $source */
    expect($source)->toContain('PasskeyOriginCanonicalizer::declaredList(');
});
```

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

- [x] 戻り値の型が明示 (`canonicalize(): string` / `declaredList(): array` に
      `@return list<string>`)
- [x] null 安全: `declaredList(?string $declared, …)` で `null` を明示的に受ける。
      `config/fortify.php` 側は `env()` の `mixed` を `is_string()` で絞ってから渡す

### 巻き戻りの前提 (施策 C-3)
#### C-3. 巻き戻りが成立する前提 (保証範囲を限定する)

巻き戻るのは「**同期の購読が、削除と同じトランザクションの中で失敗したとき**」だけである。
購読が commit 後へ回されていたら (キュー投入 / commit 後実行) 削除は確定済みになる。

- この前提は**既に機械で固定されている**: AGENTS.md ドメイン規約 11 (キュー投入の原子性) の
  `QueueDispatchAtomicityInventoryTest` が、`app/` 全クラスに対して
  `ShouldHandleEventsAfterCommit` / `ShouldDispatchAfterCommit` / `afterCommit` の
  真値を **0 件で固定**している (免除機構そのものを持たない deny-by-default)。
  したがって「購読が commit 後へ回る」形が入ったら、本設計とは独立にそちらが赤くなる。
- 本施策では**前提を作り直さない**。テスト名と文書で保証範囲を
  「同期購読」に限定して書き、上記の既存ゲートを参照する。

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

上記の対応を踏まえ、各施策の判定と全体判定を再度出してください。残る [Critical] / [Warning] があれば修正案を添えて指摘してください。

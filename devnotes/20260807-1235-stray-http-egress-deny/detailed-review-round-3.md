## Round 3 判定

Round 2 の必須修正2点は解消しています。文字列リテラル由来の括弧をトークン種別に基づいて無害化する方向も妥当です。

ただし、closure 抽出の探索範囲に1点穴が残っています。

### S1: `StrayHttpRequestGuard`

**判定: APPROVE**

`localhost` の許可判定がDNS解決前の文字列照合であること、hosts/DNSの健全性を保証しないことが正確に記述されました。

`AGENTS.md`、クラスdocblock、定数docblockの保証範囲も整合しています。

### S2: guard の自己検査

**判定: APPROVE**

Round 2 のSuggestionは解消しています。

case Aは、guardの実際のinstall経路を通して無引数 `preventStrayRequests()` の効果を検証するため、vendor既定引数の契約テストとして成立します。

### S3: 3レーンへの既定配線

**判定: APPROVE**

追加変更による問題はありません。

### S4: Architecture gate

**判定: REQUEST_CHANGES**

[Warning] `strayHttpEgressClosureBody()` の「最初の `{`」を探す範囲が、`beforeEach(...)` の対応する `)` の内側に限定されていません。

現在の契約は次のとおりです。

> `$openOffset` 以降で最初に現れる `{` を起点にする

この形では、callbackがclosureでない、または構文が変わった場合、後続の `afterEach` や別メソッドのclosureを誤って `beforeEach` 本体として取得できます。

例えば、概念的には次の入力です。

```php
->beforeEach($callback)
->something(function (): void {
    StrayHttpRequestGuard::install($this->app);
})
```

`beforeEach` 自体にはinstallがありませんが、探索範囲が無制限なら後続closureを拾って偽グリーンになり得ます。

修正案:

1. 最初に `strayHttpEgressBalancedInner($code, $openOffset, '(', ')')` で `beforeEach(...)` の引数全体を抽出する。
2. その引数内でclosure開始の `{` を探す。
3. `{` が存在しない場合は `null` を返し、fail-closedにする。
4. 可能なら、引数が `function ... {}` であることもトークンまたは正規化後コードで確認する。
5. 次の負のコントロールを追加する。

```php
test('負のコントロール: beforeEach の後続 closure を本体と誤認しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach($callback)
        ->use(function (): void {
            StrayHttpRequestGuard::install($this->app);
        })
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressCode($fixture)),
    );

    expect(implode("\n", $violations))->toContain('install');
});
```

[Suggestion] opt-out呼び出しの検出では、メソッド名と `(` の間の空白・改行・除去済みコメントを許容してください。

PHPでは次も有効です。

```php
Http::preventStrayRequests /* reason */ (false);
```

`strayHttpEgressCode()` ではコメントが空文字になるため、メソッド名の後から次の非空白文字を探し、それが `(` なら引数解析を行う形が安全です。

### 正規化方式への回答

`T_START_HEREDOC` / `T_END_HEREDOC` のラベルはPHP識別子の制約を受けるため、構造を乱す括弧がラベル名に入る問題はありません。本文は `T_ENCAPSED_AND_WHITESPACE` として無害化対象になるため妥当です。

property hooksやfirst-class callableの括弧・波括弧はPHP構文上対応しており、深度カウントを壊しません。first-class callableの `...` も括弧深度には影響しません。

`${expr}` は非推奨構文ですが、トークン上の開始側に含まれる `{` と終端の `}` は対応します。開始トークンのtextを文字単位で数える現在の方式でも深度は戻ります。

ただし、「残る括弧はすべて構文上の括弧」という前提は、今後PHPが新しい文字列系トークンを追加した場合には再確認が必要です。負のコントロールがあるため、現時点では受容できます。

### S5: 文書変更

**判定: APPROVE**

保証範囲が正確に限定されています。UI/frontend変更はないため、DESIGN.mdおよびAtomic Designは非該当です。

### S6: 既存テストの是正

**判定: APPROVE**

変更ありません。

## 全体判定

**CHANGES_REQUESTED**

残る必須修正は、`strayHttpEgressClosureBody()` の探索範囲を対象の `beforeEach(...)` / `afterEach(...)` 引数内へ閉じることです。トークン正規化そのものとRound 2の必須修正は適切に解消しています。
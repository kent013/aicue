## Round 2 判定

Round 1 の Warning 4 件のうち、3 件は解消しています。S4 の closure 内包検査は方向性は正しいものの、波括弧を生文字列で数える設計では新しい偽赤・偽緑要因が残るため、全体判定は **CHANGES_REQUESTED** です。

### S1: `StrayHttpRequestGuard`

**判定: REQUEST_CHANGES**

[Warning] `localhost` の説明に事実と異なる記述があります。

> 解決先が loopback でなければそもそも到達しない

Laravel HTTP client の許可判定は DNS 解決前の URL 文字列に対する `Str::is()` です。`localhost` が外部 IP に解決される環境でも許可判定を通過し、実際の送信先になり得ます。

修正案:

- 「`localhost` が loopback に解決されることをテスト実行環境の前提とする」と明記する。
- 「解決先が loopback でなければ到達しない」という記述は削除する。
- この前提を機械保証しないなら、保証範囲にも「hosts/DNS の健全性は対象外」と記載する。

`localhost` を残す判断自体は許容できます。問題は許可機構の保証を実態より強く説明している点です。

### S2: guard の自己検査

**判定: APPROVE**

Round 1 の2件は解消しています。

- ファイルローカルの `beforeEach` により、S3 配線前でも実通信しません。
- S3 適用後の二重 install は、同一 Factory 上の middleware 検査によって冪等です。
- `install()` が accumulator を再初期化しますが、どちらの install もテスト本体より前なので記録消失は起きません。
- case G も、HTTP 呼び出し前に install を重ねているため冪等性を正しく検査しています。

case D の理解も正しいです。実際に `StrayRequestException` が投げられた場合、

```php
expect($caught)->not->toBeInstanceOf(StrayRequestException::class);
```

は必ず失敗します。さらに middleware が stray を記録した場合は accumulator の空 assertion も失敗するため、二重に検出できます。

[Suggestion] 骨格の一覧だけ旧テスト名のままです。

```php
case D: ... (ConnectionException まで到達する)
```

を実コードと同じ「stray 判定を通過して送信段まで進む」へ同期してください。また、説明用の `beforeEach(...);` は実装対象コードへ混入しないよう、擬似コードであることを明確にしてください。

### S3: 3レーンへの既定配線

**判定: APPROVE**

二重 install と2つの guard の同時失敗時の扱いが明文化され、Round 1 の指摘は解消しています。

先に throw した guard の詳細だけを表示する設計も、必須要件が「偽グリーンを防ぐこと」である以上、現スコープでは妥当です。

### S4: Architecture gate

**判定: REQUEST_CHANGES**

[Warning] closure 本体の抽出を生文字列の `{` / `}` カウントで行う設計は安全ではありません。

コメントを除去しても、次のものは残ります。

```php
$message = "value={$value}";
$json = '{"enabled":true}';
$fixture = <<<'PHP'
{
}
PHP;
```

これらの波括弧を PHP の構文上の括弧として数えると、closure の終端を誤認します。現行 `tests/Pest.php` で偶然成立しても、将来無関係な文字列を追加しただけで gate が壊れます。

修正案:

- `PhpToken::tokenize()` のトークン列を維持したまま深度を数える。
- `{` / `}` として数えるのは、トークンの `text` が単独の構文記号である場合だけにする。
- `T_CONSTANT_ENCAPSED_STRING`、`T_ENCAPSED_AND_WHITESPACE`、heredoc/nowdoc 内容は深度に含めない。
- 負のコントロールに、closure 内の JSON文字列、文字列補間、heredoc の各ケースを追加する。

[Warning] `preventStrayRequests()` の引数判定も、対応括弧を生文字列で探索する実装なら同じ問題があります。

例えば引数内の文字列や closure に `)` が含まれると、単純な文字探索では終端を誤認します。`strayHttpEgressIsOptOutSource()` も `PhpToken` ベースで、メソッド名の次にある `(` から構文トークンだけを数えて対応する `)` を求めてください。

無引数 `preventStrayRequests()` を許可する判断は問題ありません。これは拒否を有効化する操作であり、既定拒否を弱めません。ただし、将来 Laravel のデフォルト引数の意味が変わるリスクは S2 または vendor 前提の契約テストで固定してください。

Round 1 の「literal false しか検出しない」問題は、提示された契約上は解消しています。残る問題は実装方式の構文安全性です。

### S5: 既存記述の是正

**判定: APPROVE**

コメントと文書のみの変更で、DTO、JsonResource、Inertia Props、認可、TypeScript 型への波及はありません。

UI/frontend 変更はないため、DESIGN.md および Atomic Design 観点は**非該当**です。

### S6: 既存テストの是正

**判定: APPROVE**

外部 URL ごとの限定 `Http::fake()`、assertion 非弱化、opt-out 禁止という是正規律は妥当です。アプリコードを変更しないというスコープにも適合しています。

## 全体判定

**CHANGES_REQUESTED**

必須修正は次の2点です。

1. S4 の closure 抽出と引数判定を、生文字列探索ではなく `PhpToken` ベースにする。
2. S1 の `localhost` に関する保証を正確に書き直す。

Round 1 の中心的な問題だった自己検査の独立性、固定ポート依存、非literal opt-out、closure 外呼び出しの検出方針は適切に改善されています。
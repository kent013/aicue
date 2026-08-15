全体判定: **CHANGES_REQUESTED**

**S1: 禁止語彙と出現位置の型**
判定: **APPROVE**

[Suggestion] `fromTokenId(?int)` は実装上 `int|null` を受けるので妥当です。`T_OPEN_TAG_WITH_ECHO` を `ShortEchoTag` として別扱いし、`needsContextCheck()` から外す判断も自然です。

**S2: 字句走査器**
判定: **REQUEST_CHANGES**

[Warning] `T_FUNCTION` 直後を無条件に読み飛ばすと、無名関数の直後に禁止文が来るケースを取り逃がします。

例:

```php
<?php
$fn = function echo() {};
```

これは構文としては不正寄りですが、`token_get_all()` ベースでは `function` の直後に `T_ECHO` が来た時点で読み飛ばされます。設計は「文がその位置に立てないから安全」と説明していますが、字句走査では構文妥当性を保証していないため、この説明だけでは弱いです。

修正案: `T_FUNCTION` 後の読み飛ばしは、直前が `T_FUNCTION` かつさらに前方文脈が class/interface/trait/enum body 内のメソッド宣言である、または次トークン列が `T_ECHO '('` のように名前宣言として成立する形に限定してください。簡易に行くなら、`function` 後の半予約語名を許す負例を削り、検出側に倒す方が安全です。

[Warning] `T_CONST` / `T_CASE` 直後も同様に、字句上は「名前位置」と断定しすぎです。特に `case ECHO:` は switch label として許容する設計ですが、`case` 直後の `T_ECHO` を常に読み飛ばすため、異常な断片でも沈黙します。

修正案: `T_CASE` は直後が `:` または `=>` に至るまでの label 位置として扱う、`T_CONST` は次が `=` など定数宣言として成立する場合だけ読み飛ばす、という条件を足してください。

**S3: 走査器の自己検査**
判定: **REQUEST_CHANGES**

[Critical] P1〜N11 はよい方向ですが、「読み飛ばし規則が広すぎないこと」を壊すテストが不足しています。現設計の最大リスクは false positive ではなく、読み飛ばしによる false negative です。

修正案: 各 predecessor について、許容したい正規形だけでなく、近傍に禁止文がある変形で検出できることを追加してください。最低限、以下を足すべきです。

```php
<?php function () { echo "x"; };
<?php class Foo { const A = 1; } echo "x";
<?php switch ($x) { case 1: echo "x"; }
<?php #[Attr] echo "x";
```

[Warning] 「検体を php -l 相当で確認」とありますが、実装手順だけだと再現性がありません。

修正案: S3 のテスト内で一時ファイル化して `php -l` する必要まではありませんが、少なくとも不正構文を意図的に含めない方針なら、テスト名またはデータ provider に「valid PHP fixture」と明示してください。

**S4: 走査根の分類と例外の型**
判定: **APPROVE**

[Suggestion] `resources` を走査対象に入れる判断は正しいです。Blade の PHP 開始タグ区間だけでも拾えるため、ここを除外すると `<?=` 禁止が fail-open になります。

**S5: gate 本体**
判定: **REQUEST_CHANGES**

[Critical] `FORBIDDEN_STATEMENT_EXEMPTION_CAP = 1` なのに G10 の説明が「上限を超えないこと」で、実装例も `toBeLessThanOrEqual()` 系に寄っています。設計本文では exact fit と言っているため矛盾しています。

修正案: G10 は `count(forbiddenStatementExemptions()) === FORBIDDEN_STATEMENT_EXEMPTION_CAP` にしてください。減っても赤にする設計なら `<=` は不適合です。

[Warning] G1 の「目録の件数を差し引き」が実装次第で穴になります。ファイル単位で exemption 登録があるから丸ごと除外、という実装になると、同じファイルに `goto` や `<?=` が増えても見逃します。

修正案: exempt file でも全 site を収集し、`path + kind` ごとの実測件数が inventory の `counts` と完全一致することを先に検査し、その一致分だけ G1 から除外してください。未登録 kind は必ず違反として残す設計に固定してください。

[Warning] `FORBIDDEN_STATEMENT_SCANNED_FILE_FLOOR = 1400` は現在値 1552 に対して妥当ですが、将来の大規模削除で不要な摩擦になります。

修正案: 床値は維持してよいですが、失敗メッセージに「実測値と除外件数」を出し、単なるファイル数減少なのか分類漏れなのか判断できるようにしてください。

**S6: 規約の成文化**
判定: **APPROVE**

[Suggestion] 「直接出力は 1 つも無いとは読めない」と保証範囲を明記している点はよいです。S5 の exact fit と同じく、AGENTS.md 側も「件数は完全一致」と書いているので、実装側を `=== CAP` に揃える必要があります。

**主要な修正要求**

1. 読み飛ばし規則を「直前トークンだけ」から、名前位置として成立する最小文脈に絞る。
2. S3 に false negative 防止の負荷テストを追加する。
3. exemption cap は `<=` ではなく `===` で固定する。
4. exempt file を丸ごと除外できないことを、G1/G8 の仕様として明文化する。

この4点が直れば、設計の方向性自体は既存 gate の作法に沿っており、HTTP/DB/UI への副作用も低いです。
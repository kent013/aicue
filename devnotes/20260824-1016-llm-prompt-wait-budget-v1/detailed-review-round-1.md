# 全体判定: CHANGES_REQUESTED

設計の方向性、単一読み取り器への集約、公開面を検査済みの2口に限定する判断は妥当です。コード上の重大なロジック・セキュリティ問題も見当たりません。

ただし、確定済み要件である「ファイル不在の独立分類」がテストで固定されていないことと、分母到達証明の検出力を実際より広く記述している点は修正が必要です。

## 施策1: REQUEST_CHANGES

[Warning] ファイル不在・parse失敗・非mapの「分類」をテストできていません。

現在のテストが確認するのは「違反が空でない」「ファイル名を含む」だけです。これでは、ファイル不在が再び `parse 失敗` に統合されてもテストが緑のままです。追加文脈で確定済みとされている「ファイル不在を独立ラベルにする」を固定できていません。

修正案:

- ファイル不在は `prompt YAML が無い`
- 構文不正は `parse 失敗`
- 非mapは `top-level が連想配列(map)でない`

という安定部分を個別に検証してください。vendor例外本文までpinする必要はありません。

```php
$unresolvable = [
    'ファイル不在' => [
        promptWaitBudgetFixtureDir().'/does-not-exist.yaml',
        'prompt YAML が無い',
    ],
    'parse 不能' => [
        promptWaitBudgetFixtureDir().'/broken.yaml',
        'parse 失敗',
    ],
    '最上位が map でない' => [
        promptWaitBudgetFixtureDir().'/list-top-level.yaml',
        'top-level が連想配列(map)でない',
    ],
];
```

[Suggestion] `is_int()` を `is_numeric()` に変更すると `bool` も通る、という記述は正確ではありません。PHPの `is_numeric(true)` は `false` なので、落ちるのは主に数値文字列とfloatです。red-green-logの期待結果も合わせて訂正してください。

## 施策2: REQUEST_CHANGES

[Warning] `Throwable` 捕捉に関するdocblockが実態を過大評価しています。

`PromptWaitBudget` 自身はcatchを書きませんが、委譲先の `PromptYaml::parseOrFail()` は `Yaml::parseFile()` を `catch (Throwable)` で囲んでいます。そのため、vendor内部の予期しない `Error` なども `parse 失敗` へ分類され得ます。

修正案:

- 「本クラスは自前のcatchを書かないので、テストコード自身のバグを潰さない」という説明を削る。
- 採用時債務で変更しないなら、保証外として次を明記する。

> parse段の失敗分類は既存の `PromptYaml::parseOrFail()` に従う。同ヘルパは `Yaml::parseFile()` が投げる `Throwable` をparse失敗へ分類するため、構文エラーとvendor内部エラーの区別までは保証しない。

実装本体の型・null処理・公開面の設計は適切です。

## 施策3: REQUEST_CHANGES

[Warning] 到達証明は「再帰の破損」を検出できません。

既知5ファイルはすべて `resources/prompts` 直下にあります。そのため、`PromptYaml::paths()` が非再帰実装へ退行しても5本を取得でき、テストは緑です。詳細設計自身も保証外として認識していますが、以下では検出できると記述しています。

- テスト名・失敗メッセージの「再帰の破損」
- テスト計画の「走査根の改名・移動・再帰破損を検出する」
- D50の「分母の到達証明」
- D50比較表の「走査根の改名・移動で赤くなる」という周辺説明

修正案は、今回は検出力の主張を狭めることです。

> 既知5ファイルが現在の列挙結果に含まれることを証明する。再帰走査自体の検出力は保証しない。

再帰まで証明するなら、入れ子の探索根を入力できる検出器が必要ですが、採用時債務の `PromptYaml` 変更を伴うため本施策では広げない判断が妥当です。

[Warning] 本体の不変条件テスト単独では分母0件を検出しません。

従来は同じテスト内に `expect($files)->not->toBeEmpty()` がありました。変更後は別テストへ分離されるため、「全prompt YAMLが宣言する」テストだけをフィルタ実行すると0件で緑になります。

修正案:

```php
test('全 prompt YAML が client_options.timeout (>0 の int) を宣言する', function (): void {
    $files = PromptYaml::paths();

    expect($files)->not->toBeEmpty();

    $violations = [];
    foreach ($files as $file) {
        // ...
    }
});
```

到達証明側との重複は、各不変条件テストを単独実行可能に保つための意図的な重複として許容できます。

## 施策4: APPROVE

`AnalysisBudget` の仕様値を独立して残しつつ、読み取り規則だけを単一化する構造は正しいです。

- `timeout <= 0` を通していた旧実装の穴を閉じられる
- `CLIENT_TIMEOUT_SECONDS` をYAMLから導出しないため、仕様値との突合も維持される
- publicシグネチャと呼び出し側を変えない
- 不要な `Yaml` / `Assert` importも削除する

追加変更は不要です。

## 施策5: APPROVE

OCR変種を `AnalysisBudget::PROMPT_NAMES` に混ぜず、値の突合だけ同じ読み取り器へ寄せる設計は適切です。

`max_tokens` の読み取りに `Yaml` を残す理由も明確で、DTO/API/フロントへの波及はありません。

## 施策6: REQUEST_CHANGES

[Warning] D50が施策3の検出力を過大に記録します。

特に次の主張は修正が必要です。

> 分母の到達証明（既知5本の包含）

「包含」自体は正しいものの、再帰全数走査の検出力まで裏取りしたように読めます。D50の「揃え続ける不変条件」「比較表」「保証しないもの」を、以下の境界で統一してください。

- 全数性は既存 `PromptYaml::paths()` の実装契約に依存する
- 新設テストが裏取りするのは、現在の列挙結果に既知5本が含まれること
- ファイルがすべて直下にある現状では、再帰性の退行は検出できない

D50登録、46→47の件数更新、指紋台帳を書き換えない判断自体は妥当です。

## 施策7: REQUEST_CHANGES

[Warning] 読み取り器を使う箇所の列挙から、施策5の経路が抜けています。

追記案では次の2経路だけを列挙しています。

- `PromptClientTimeoutInvariantTest`
- `AnalysisBudget::clientTimeoutSecondsFromYaml()`

しかし施策5により、`AnalysisTokenBudgetInvariantTest` の `sop-extract-media.yaml` 検査も `PromptWaitBudget::requirePositive()` を直接使用します。

修正案:

- 3経路すべてを列挙する
- または「主な利用箇所は」として非網羅であることを明示する

[Warning] 既存の「実効タイムアウトである」という断定と、直後の「3前提を機械では見ていない」が読み手に矛盾して見えます。

修正案として、既存箇条そのものを次のように条件付きの表現へ直してください。

> 現在の実装では、以下の3前提が成立する限り、LLM呼び出しの実効タイムアウトは `resources/prompts/*.yaml` の `client_options.timeout` である。

これにより「宣言値」と「実効値」の境界が一読で分かります。

## その他の確認結果

- PHPコードの分岐、null処理、array shapeには明白なロジックエラーなし
- PHPStan対象外であることを「型保証」と誤認しない説明は適切
- DTO / JsonResource / Inertia Propsは非該当
- 認可、入力検証、tenant境界、PII、SSRFなどのセキュリティ不変条件への変更なし
- UI変更がないためDESIGN.md / Atomic Designは非該当
- migration、DB操作、既存テスト削除、後方互換実装の残置なし

上記Warningを反映すれば、単一読み取り器への移行方針そのものは承認可能です。
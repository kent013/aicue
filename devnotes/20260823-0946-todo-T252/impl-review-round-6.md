### `tests/Architecture/ArchBaselineTest.php`

Round 5の指摘は解消しています。

- file-scoped `beforeEach` は外部テスト38で検出
- 直接の `skip` / `todo` はfooterとfactory状態の二層で検出
- factory比較の保証を公開状態に限定
- PHPStan level 10、Pint、全PHPテストが通過
- baseline・抑止・型widenなし

S4-3cのfactory比較も、現行Pestに対して妥当です。`attributes` はdescription由来の必須属性を持つため別扱いにし、`Test` + `TestDox` のexact-fitとする判断は正しいです。

### `tests/Support/Architecture/ArchBaseline.php`

`EXPECTED_TOP_LEVEL_CALL_NAMES = ['test']` は、現在のPestのfile-scoped入口をdeny-by-defaultで閉じる契約として適切です。禁止APIの列挙よりも、vendorによる入口追加へ強くなっています。

[Suggestion] 次の説明は少し広すぎます。

> 宿主ファイルの最上位はテストを宣言する以外のことをしない

実装が固定するのは「最上位の素の関数呼び出しが `test` だけ」です。変数代入、クラス・関数宣言、メンバ呼び出しまで禁止しているわけではありません。「最上位の素の関数呼び出しは `test` だけ」に表現を揃えると、保証がより正確です。

### `tests/Support/Architecture/ArchSurfaceScanner.php`

`topLevelCallNames()`の実装は目的に合っています。

- 既存の名前トークン判定を再利用
- 完全一致かつ小文字正規化
- 重複排除・昇順
- 深さ0だけを対象
- 修飾名・完全修飾名も末尾名で検出
- 可変関数・反射を保証外として明記
- 正例・負例あり

[Suggestion] 「メンバ呼び出しはファイル単位の仕掛けにならない」という説明は一般論としては成立しません。static methodや共有オブジェクトのメソッドがグローバルなPest状態を変更するAPIは設計可能です。

現行Pestの対象入口が `beforeEach()` / `uses()` / `pest()` 等の素の関数から始まることは妥当ですが、説明は次のように狭めるのが正確です。

> 現在対象とするPestのfile-scoped入口は素の関数呼び出しであり、メンバ呼び出しは本走査の保証範囲外である。

これは現在の検出力を変えない文書上の精度改善であり、承認を妨げる問題ではありません。

### `tests/Unit/Architecture/ArchBaselineScannerTest.php`

13hとテスト38は適切です。

- 合成入力が実際に変更されたことを確認
- `beforeEach` の追加だけが差になる入力
- メンバ呼び出しとclosure内部を除外する正例
- 宿主実ファイルを外側から検査
- 呼び出し集合をexact-fitで比較

Round 5の負例不足は解消しています。

### `docs/template-divergence.md`

D43は現在の実装と一致しています。登録、実行修飾、footer、file-scoped入口、最上位短絡を、それぞれ担当する検査まで示しています。

上記Suggestionと同様、「file-scoped入口」は現行Pestの素の関数入口を対象とする旨へ狭めるとさらに正確ですが、重大な齟齬ではありません。

### その他のファイル

以下に新たな問題はありません。

- `tests/Support/Architecture/ArchTokenStream.php`
- `tests/Support/Architecture/GlobalFunctionCallScanner.php`
- `tests/Support/Architecture/VendorArchPresetReader.php`
- `tests/Support/Concurrency/ProcessBarrier.php`
- `tests/Support/TemplateDivergence/LedgerPins.php`

## 保証境界の判定

§5の境界設定は妥当です。

リポジトリを編集できる攻撃者に対して、個々のgateが完全な自己防御を持つことは保証できません。外部検査をさらに別の検査で監視し続けると、指摘どおり無限後退します。したがって、

- 通常の変更・事故・既知の無力化を機械検査
- enforcement自身への意図的な改変はgit差分レビュー
- 基盤全体の無力化は別の横断的な不変条件

という分界は適切です。

境界外の具体例として、宿主ファイル内でnamespace-localな `test()` 関数を定義し、Pestのglobal `test()` を名前解決で置き換えるような意図的改変も考えられます。これは現在のトークン列を模倣して検査機構そのものを欺く変更であり、§5がgitレビューへ委ねる攻撃者モデルに属します。存在自体は本件の不承認理由になりません。

## `tests/Pest.php`経由のレーン全体skip

これはT252の境界外とする判断が妥当です。

宿主固有の欠陥ではなく、Architectureレーン全体を1405件規模で無力化するテスト基盤の問題です。本gateだけで対処すると、`phpunit.xml`、runner、Composer script、CI設定まで際限なく監視対象が広がります。

ただし影響が大きいため、別TODOを立てる価値があります。候補となる不変条件は、例えば以下です。

- 既知のskipだけを理由付きinventoryへ登録
- 全実行結果で未知のskip増加を失敗扱いにする
- レーン配線に無条件skipがないことを外部レーンから検査
- runnerの終了コードだけでなくfail/skip内訳を検証

これはT252の完了とは分離すべきです。

## `--filter`終了コードの注記

D43やgateのdocblockには追加しない判断が妥当です。これはPest archベースラインの契約ではなく、Laravel/ParaTest runnerの測定上の性質だからです。

再現手順を残す価値はありますが、置くなら別TODO、テスト運用runbook、または調査devnoteが適切です。本変更の保証説明へ混ぜると主題がぼやけます。

## 全体判定

保証境界は適切で、境界内に未修正のCriticalまたはWarningは見当たりません。Round 5の全指摘は解消しています。

APPROVED
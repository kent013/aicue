# 対応マトリクス: design-review Round 2

## A. [Suggestion] 「fragment の拒否も後方非互換」は誤り

- 判断: 対応する
- 根拠: 指摘のとおり t0 の `isValidSnsCertUrl()` は既に `fragment` を拒否している。
  新たに拒否するのは credential だけである。
- 対応内容: リスク欄を「新たに拒否するのは credential だけ」に訂正した。

## C. [Warning] ロックの外へ出した DNS 解決が並列に走る

- 判断: **明示的にリスクを受容する** (Codex の 4 番目の選択肢)。緩和策は再検討条件として置く
- 根拠:
  - **t0 からの後退ではなく前進である**。t0 は同じ入力に対して
    「書式検証だけ通れば**外向き HTTP 取得を無制限に並列で行う**」実装だった。
    t1 では同じ入力が届いても、行うのは DNS 解決までで、HTTP 取得は permit 1 に直列化される。
    したがって本施策は無認証入力による worker 占有を**減らす**方向にしか働かない
  - 既存の `throttle:webhook-ses` (300/分・IP 単位) が単一 IP からの物量を頭打ちにする
  - 存在しない host の解決は NXDOMAIN で終わり、解決器は否定応答も cache する
    (上限の保証ではないが、実効的な費用は小さい)
  - 緩和策として最有力の「証明書 host の region を TopicArn の allowlist へ束縛する」は
    **裁定 AG-199 の要件 (3)「厳格 URL 検証は t0 の形を維持」から外れる**強化であり、
    いま入れるのは先回りになる (思考原則 2)
- 対応内容: 取得口の docblock と `docs/architecture.md` に、受容の理由・既存 throttle との関係・
  **再検討条件**(受け口の 429 発生率が上がった / DNS 解決の待ち時間が観測で問題になった /
  SNS 通知の 503 率が上がった) と、そのときに採る緩和策 (region 束縛 /
  DNS 解決用の独立した同時実行制限 / 解決器への実効 timeout) を明記した。

## C. [Suggestion] `SnsCertificate` を named constructor にする

- 判断: 対応する
- 対応内容: `SnsCertificate::fromCache(string $pem)` /
  `SnsCertificate::fetched(string $pem)` の 2 つだけを公開し、
  コンストラクタを private にした。

## D. C11 の「行順」は完全な制御フロー証明ではない

- 判断: 対応不要 (Codex も現状の保証として許容と明記)
- 対応内容: 「言語の可視性で閉じていない」という限定を `docs/architecture.md` に書く方針は
  既に施策 K に入っている。

## E. [Critical] C5 が正当な `->verify()` を誤検出する

- 判断: 対応する (実際に必ず赤くなる指摘。走査根の
  `VerifySnsSignature` が `$this->verifier->verify($message)` を持つ)
- 対応内容: C5 の判定を 2 つに絞った —
  (i) `T_STRING` が `withoutVerifying` に完全一致する、
  (ii) 文字列リテラル `'verify'` / `"verify"` の直後が `=>` で、その値が `false`。
  **メソッド呼び出しの `->verify(` は判定に使わない**。
  正例に `$this->verifier->verify($message)` を入れて誤検出しないことを固定する。

## E. [Critical] C12 の `T_NAME_QUALIFIED` 全面禁止は成立しない

- 判断: 対応する (namespace 宣言・use 宣言に同じトークンが出る指摘は正しい)
- 対応内容: 判定を**参照位置に限る**形へ直した —
  `T_NAMESPACE` から `;` または `{` までと、`T_USE` (closure の `use (` を除く) から `;` までを
  文脈として読み飛ばし、**残った `T_NAME_QUALIFIED`** だけを未解決として失敗させる。
  `T_NAME_FULLY_QUALIFIED` (先頭が `\`) は絶対名で解決できるので対象外とする。
  C8 / C9 に「namespace 宣言は正例」「use 宣言は正例」「解決可能な import 済みクラス参照は正例」
  「本体中の部分修飾参照 (`Facades\Http::get()`) は負例」を足した。

## E. [Warning] C13 の関数名とクラス参照は走査方法が違う

- 判断: 対応する
- 対応内容: C13 を 2 つに割った —
  **C13a (関数呼び出し)**: `T_STRING` が語彙に完全一致し、**直後が `(`** で、
  直前が `->` / `?->` / `::` / `function` でないこと。
  **C13b (クラス参照)**: `PhpReferenceScanner` の完全修飾名で照合する。
  それぞれに正例・負例を用意すると書いた。

## F / H. [Warning] `Cache::flush()` が store 全体へ干渉する

- 判断: 対応する
- 根拠: 指摘のとおり。array store はプロセス内で持ち越されるので掃除は必要だが、
  全消しは他レーン・rate limiter・lock へ波及する。
- 対応内容: `Cache::flush()` をやめ、**テスト専用の array store へ既定を切り替える**
  共用ヘルパ `useFreshSnsCertificateCacheStore()` を `tests/Pest.php` へ置く形にした。
  `config(['cache.stores.sns_cert_test' => ['driver' => 'array', 'serialize' => false]])` +
  `config(['cache.default' => 'sns_cert_test'])` + `Cache::forgetDriver('sns_cert_test')` の 3 行で、
  **前のテストの実体を捨てて作り直す**。既存の store には一切触れない。
  ★このヘルパが呼ぶキャッシュ API は `forgetDriver` だけなので、
  `CachePayloadPlainDataGateTest` の面目録では `tests/Pest.php` を
  `role: no-payload-write` で登録できる (`forgetdriver` は payload を書かない API の分類に入っている)。

## F. [Warning] F17 の probe lock を解放する

- 判断: 対応する
- 対応内容: 解放確認で取った lock を必ず `release()` すると明記した。

## F. [Suggestion] F19 の判別性

- 判断: 対応する
- 対応内容: 時刻移動の**前**に `cached()` が PEM を返すことを確かめてから移動し、
  移動後に null になることを見る形にした (別理由の null と区別できる)。

## G. [Suggestion] G10 の書き方

- 判断: 対応する
- 対応内容: certClient は要求 URL を記録したうえで**テスト専用の終了例外**を投げ、
  証明書の中身や後続の署名検証に assertion が依存しない形にすると明記した。

## H. [Warning] 2 回目の assertion を分ける

- 判断: 対応する
- 対応内容: H4 を「(a) 2 回目も受理される」「(b) 外向き HTTP が増えない」
  「(c) `EmailSuppression` が重複作成されない」の 3 つに分けて assert すると書いた。

## I. [Warning] `certificatePem()` の説明と実装が矛盾する

- 判断: 対応する (指摘の「前者 (単純なほう)」を採る)
- 根拠: `signedNotification()` と同じ鍵対を使うので「署名と一致しない証明書」ではない。
  G4 / G5 / G8 が使う封筒は `notification()` (`Signature` が固定のダミー値) なので、
  **証明書が本物でも署名が合わない**ため署名段で落ちる。説明のほうが誤りだった。
- 対応内容: `certificatePem()` を「**PEM として有効なテスト証明書**」と定義し直し、
  「G4 / G5 / G8 はダミー署名の封筒と組み合わせるので署名段で落ちる」と
  テスト側の説明で補うことにした。

## I. [Suggestion] `lambdaStyleNotification()` の override 順

- 判断: 対応する
- 対応内容: 既定値を先に入れてから `$overrides` を適用する順に直した。

## J. 目録は実際の参照 site に合わせて確定する

- 判断: 対応する
- 対応内容: `tests/Pest.php` (`role: no-payload-write`) と
  `tests/Feature/Mail/SnsCertificateFetcherTest.php` (`role: write`) を明示した。
  他のテストファイルはヘルパ経由でしかキャッシュに触れないので登録不要になる見込みで、
  最終的には gate の実測で確定させる。

## K. 文書

- 判断: 対応する
- 対応内容: DNS 解決が permit 1 の対象外で並列に走りうること、無認証入力が
  別々の SNS 風 host を作れること、受容の根拠 (t0 からの後退ではないこと・既存 throttle)、
  再検討条件を `docs/architecture.md` の節に書くと明記した。

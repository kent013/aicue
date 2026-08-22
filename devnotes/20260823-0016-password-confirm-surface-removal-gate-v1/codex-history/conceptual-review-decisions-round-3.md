# 対応マトリクス: conceptual-review Round 3

## [Critical] `imagesEnabled` の宣言検出が設計内で矛盾している (短名照合は AGENTS.md (a) 違反)
- 判断: **対応する**
- 根拠: 指摘のとおり。「`function` の直後の `imagesEnabled`」はクラスを問わず全宣言を拾い、「一般名すぎる」という当初の懸念をまったく解消していない。また `AcceptedSourceDocumentTypes::imagesEnabled` を短名で照合するのは AGENTS.md (a)「クラス参照は完全修飾名で突き合わせる」の直接の違反である (別名つき取り込み 1 つで黙る)。
- 対応内容: `imagesEnabled` の検出を**完全修飾名基準**へ書き換える:
  - **宣言形**: 宣言を包含するクラスの**完全修飾名が `App\Support\Manual\AcceptedSourceDocumentTypes`** である場合だけ違反にする (namespace 宣言 + class 宣言から組み立てる)
  - **静的呼び出し形**: `use` / group use / 別名つき取り込み / 現在の namespace を解いた**完全修飾名**で判定する
  - **解決できないクラス参照は未解決として gate を失敗させる** (AGENTS.md (b))。無言で候補から外さない
  - 別クラスの `imagesEnabled()` 宣言・呼び出しは**負例**として固定する
  - **別名つき取り込み** (`use App\Support\Manual\AcceptedSourceDocumentTypes as Types;` → `Types::imagesEnabled()`) と**同名別クラス** (`App\Other\Thing::imagesEnabled()`) を正例 / 負例に含める

## [Warning] A の許可条件 3 の「その文字列」がキーか値か曖昧
- 判断: **対応する**
- 対応内容: 「**撤去語を含むキー文字列**が、登録済み route 名と完全一致する」と明記する。

## [Warning] 条件 2 の「値が単独の文字列リテラル」はクラス名を文字列で直書きする形を許す
- 判断: **対応する (機械で確かめられる条件を 1 つ足す)**
- 根拠: `'password.confirm' => 'App\Http\Middleware\ExampleMiddleware'` が通ってしまうのは事実。実行時層が最終的に捕まえるとしても、静的層が「見出し文字列である」と断定できないのは正しい。
- 対応内容: (i) 条件 2 に **「値の文字列が `class_exists()` / `interface_exists()` で解決できる場合は違反にする」**を足す (機械で確かめられ、クラス名の直書きを潰す)。(ii) 保証の表現を「**見出し文字列である**」から「**登録済み route 名をキーとする文字列値の対応表の形**」へ改め、断定を弱める。

## [Warning] 期待効果の「宣言した許可形の外にある出現をすべて落とす」が全撤去語に対しては広すぎる
- 判断: **対応する**
- 対応内容: 効果を語ごとに分けて書く:
  - A の `password.confirm` と OCR の固有 3 語 (`ocr_analysis_enabled` / `OCR_ANALYSIS_ENABLED` / `imageSourceDocumentsEnabled`): 語境界に一致する出現を、宣言した例外以外**すべて**検出する
  - `imagesEnabled`: **指定クラスのメソッド宣言**と、**完全修飾名へ解決できる静的呼び出し**だけを検出する (意図的な限定検出)

## [Warning] `imagesEnabled` を Tier 2/3 で全面的に見ない判断と、`.github/`・`scripts/` 必須走査の関係が不足
- 判断: **対応する**
- 根拠: `scripts/ci/` には実際に PHP が 3 本ある (`drop-test-db.php` / `ensure-test-db.php` / `pgsql_test_conn.php`)。「非 PHP に現れても意味を持たない」はシェルから PHP を呼べる以上、強すぎる主張である。
- 対応内容: 層の切り方を**根ではなくファイル種別**で定義し直す:
  - **Tier 1 (PHP トークン走査)** の対象を**走査根 8 本すべての `.php`** (`.blade.php` を除く) に広げる。`.github/` と `scripts/` の PHP も指定クラスの宣言・静的呼び出し検査の対象になる
  - **Tier 2 (生テキスト、許可形なし・0 件固定)** の対象を走査根 8 本すべての**非 PHP 全ファイル**にする (Round 2 の Tier 2 と Tier 3 を統合する。`.github/` と `scripts/` が母集団に入っていることは根ごとの種別検査で別に固定する)
  - 非 PHP における `imagesEnabled` は、**完全修飾の参照文字列** `App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled` を撤去語として 0 件固定する。裸の `imagesEnabled` は**検出力を主張しない**ことを docblock に書く (一般名であり、非 PHP で実行可能な参照になるにはクラスの完全修飾名が必要なため)

## [Warning] CSS 正例 `#password\.confirm` は生テキストとして検索語を連続で含まない
- 判断: **対応する**
- 根拠: そのとおり。バックスラッシュが挟まるので境界一致に掛からず、正例として機能しない。
- 対応内容: 正例を**検索語を実際に連続して含む形**へ差し替える (`#password.confirm { … }` / `* { content: "password.confirm"; }`)。あわせて、**見本ファイルが検索語を実際に含むこと自体を assertion で固定する** (見本が壊れて静かに空振りするのを防ぐ)。

## [Suggestion] 使命整合 / 禁止事項 / スコープ (コメント parser を作らない判断) は妥当
- 判断: **見送る (現行方針を維持)**
- 根拠: 追認であり設計変更を要さない。

# 対応マトリクス: impl-review Round 1

## [Critical] 設計固有の PHPStan コマンドが 4 エラー残る (ArchBaselineTest)

- 判断: **反論する** (実装は変えない。根拠を検証可能な形で残す)
- 根拠:
  - 4 エラーはすべて禁止表明 4 行に対するもので、**本アプリのコードを 1 行も含まない
    次の 2 行で完全に再現する**:

    ```php
    $call = arch('x');
    $call->expect(['sha1'])->not->toBeUsed()->ignoring([]);
    ```

    実測: `vendor/bin/phpstan analyse --level=10` に上記だけのファイルを渡すと
    `method.notFound` 1 + `property.nonObject` 1 + `method.nonObject` 2 = **同じ 4 件**。
    つまり「本実装の書き方」ではなく **Pest arch を使う限りどう書いても出る vendor 側の
    型情報の欠落**である (`Pest\Arch\Autoload` が `Plugin::uses(Architectable::class)` で
    実行時に生やすため、`TestCall` に静的な型が無い)。
  - 消す手段は 3 つしかなく、すべて禁止か設計違反である:
    (1) `@phpstan-ignore` / baseline → **禁止事項 2**
    (2) `mixed` への widen / `TestCall` を universalObjectCrate 化 → **禁止事項 2** かつ
        `phpstan.neon` の変更 (詳細設計が「設定ファイルは変更しない」と明記)
    (3) チェーンを書き換える → S4 が `EXPECTED_CHAIN_TOKENS` で pin している形を崩す。
        そもそも Pest arch を使わない実装は TODO 自体の否定になる
  - pest の `extension.neon` を include しても解消しない (登録するのは
    `HigherOrderTapProxy` / `Expectation` の universal object crate だけで `TestCall` は対象外)。
  - 詳細設計の受入条件の文言は「**1 度確認する**」であり、0 エラーを要求していない。
    意味のある部分 (`tests/Support/Architecture/` の走査器 3 本 + 共通入口 +
    gate の自己検査部 + 走査器の負例) は **0 エラー**である。
- 対応内容: gate の docblock に **2 行の再現手順**と「pest の extension.neon でも解消しない」
  事実を書き足し、レビュアーが数秒で追試できるようにした。実装は変えていない。

## [Warning] S4 がチェーンの文しか固定しておらず、7 規則が実際に登録されたことを固定していない

- 判断: **対応する**
- 根拠: 指摘のとおり `if (false) { … }` で囲めば**綴りを 1 文字も変えずに** 7 本の表明を
  無効化できる。S1〜S5 はすべて緑のままなので、gate が静かに無力化する経路が実在した。
- 対応内容:
  - `ArchSurfaceScanner::tokensBefore()` (直前 N トークンの綴り列。範囲外は例外) と
    `ArchSurfaceScanner::braceDepthAt()` (その位置で開いたままの波括弧の深さ) を新設
  - `ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS` を追加
    (`foreach ( ArchBaseline :: ruleIds ( ) as $ruleId ) {` の 11 トークン)
  - gate に **S4-3b** を追加: 唯一の `arch` 呼び出しの直前 11 トークンが期待形と完全一致し、
    その `foreach` の位置で**波括弧の深さが 0** (= ファイル最上位)、`arch` の位置で深さ 1 であること
  - 走査器の負例を追加: **13b** (tokensBefore の正例と範囲外例外) /
    **13c** (`if (false) {` で囲むと**綴りは同一のまま**深さだけが 0 → 1 に変わることを固定) /
    **13d** (文字列補間の `{$a}` の開き波括弧を数えないと深さが -1 になることを固定)
  - `braceDepthAt` に「深さが負」の分岐は**置かない**。`TOKEN_PARSE` を通った入力では
    波括弧の対応が構文として保証され到達しないため (共通規約 (d))。docblock に明記した
  - 残る限界 (Pest への**登録件数そのもの**を実行時に数えてはいない) は指摘の
    「少なくとも構造まで検査せよ」の線で止めた。実行時に数えるには
    `Pest\TestSuite` の内部表現へさらに結合する必要があり、費用に見合わないと判断した

## [Warning] ArchTokenStream 共有後の fail-closed 契約が公開入口ごとに固定されていない

- 判断: **対応する**
- 根拠: 妥当。現状の不正 PHP 負例は `GlobalFunctionCallScanner` 経由の 1 本だけで、
  将来 `ArchSurfaceScanner` / `VendorArchPresetReader` が共通入口を外しても正例は通り得る。
- 対応内容: 負例 **7b** を追加し、`identifierSites` / `functionNameSites` /
  `dynamicMemberSites` / `statementTokens` / `tokensBefore` / `braceDepthAt` /
  `VendorArchPresetReader::forbiddenSymbolsFromSource` の **公開境界 7 つすべて**で
  トークン化できない入力が `RuntimeException` になることを固定した。

## [Suggestion] ProcessBarrier のコメントの主張が広すぎる

- 判断: **対応する**
- 根拠: `$reader(...)` 自体が可変 callable の第一級 callable 構文であり、S4 が明示的に
  保証範囲外とする経路である。「callable 経由の迂回口を塞ぐ」は誇張だった
  (AGENTS.md §検出力の主張の書き方に照らして不適切)。
- 対応内容: 「S4 は `fromCallable` **という綴り**を 0 件に固定するのでここでは使わない。
  可変 callable 経路そのものは S4 の保証範囲外であり、この書き換えで塞がるものではない」
  へ記述を狭めた。

## [その他] `pnpm test` の 1 件失敗

- 判断: **本 TODO の範囲外として報告する** (実装は変えない)
- 根拠: `tests/js/architecture/file-input-accept-source-inventory.test.ts` の失敗は
  **clean な main (worktree ではないリポジトリルート・作業ツリー無変更) で同じ内容が再現する
  先行破損**である (`pages/Settings/Security.svelte` の生 HTML 免除が実測に無い /
  件数 pin 不一致)。本実装は `resources/js` を 1 行も変更しておらず、因果関係が無い。
  直すには `tests/js/support/file-input-accept-inventory.ts` の目録更新か
  `Settings/Security.svelte` の是正が要り、どちらも T252 の設計に無い別件である
  (詳細設計は「アプリコード・`resources/` は 1 行も変更しない」と明記)。
- 対応内容: 親エージェントへ「main 先行破損 / 別 TODO で追跡すべき」として報告する。

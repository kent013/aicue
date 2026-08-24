# 対応マトリクス: impl-review Round 2

## [Critical] 設計固有の PHPStan コマンドが 4 エラー残る

- 判断: **対応する** (Round 1 の反論を撤回し、0 エラーにした)
- 根拠: 指摘のとおり「既知の 4 エラーを 1 度確認すればよい」は受入条件の読み替えだった。
  また「消す手段は 3 つしかない」も証明されていなかった。**実際に測って確かめた**:

  | 形 | PHPStan level 10 |
  |---|---|
  | `arch('x')->expect(['sha1'])->not->toBeUsed()->ignoring([])` | **4 errors** |
  | `$e = arch('x')->expect(['sha1'])` | 1 error (`TestCall::expect()` 未定義) |
  | **`expect(['sha1'])->not->toBeUsed()->ignoring([])`** | **0 errors** |
  | 戻り値を `Pest\Arch\Contracts\ArchExpectation` で受ける関数境界 | 7 errors (悪化) |

  → エラー源は **`arch()` が返す `TestCall` だけ**であり、`expect()` 側は型が付く。
  `arch($description)` は `test($description)` を呼んで `TestCall` を返し、
  以降のチェーンを実行時 mixin (`Plugin::uses(Architectable::class)`) で解決する**糖衣**にすぎない。
- 対応内容: **禁止表明を `test($description, fn)` + `expect(...)` へ書き換えた**。

  ```php
  foreach (ArchBaseline::ruleIds() as $ruleId) {
      test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
          expect(ArchBaseline::symbolsOf($ruleId))
              ->not->toBeUsed()
              ->ignoring(ArchBaseline::exceptionsOf($ruleId));
      });
  }
  ```

  - **規則の description がテスト名になる点は変わらない** (設計が求めた「主張の弱さが
    テスト一覧から見える」性質を保つ)
  - **表明が実際に評価されることを実測で確かめた**: 一時プローブで
    `expect(['sha1'])->not->toBeUsed()->ignoring([])` (例外なし) を書くと
    `FakeObjectStore.php:200` を名指しして**赤くなり**、例外つきなら緑になった。
    到達可能性・検出力とも `arch()` 形と同じである
  - **`vendor/bin/phpstan analyse --level=10 tests/Support/Architecture
    tests/Architecture/ArchBaselineTest.php tests/Unit/Architecture/ArchBaselineScannerTest.php`
    → 0 errors**。抑止コメント・baseline・widen・設定ファイル変更はいずれも使っていない
  - 副産物として **`arch` は `tests/` 全数で 0 件の禁止名になった** (S4-2)。
    「ちょうど 1 件」を数えるより強い契約であり、`\arch(...)` や
    `use function Pest\arch as x;` で 2 本目を作る経路もまとめて塞ぐ
  - `EXPECTED_CHAIN_TOKENS` / `EXPECTED_CHAIN_HEADER_TOKENS` を新しい形へ更新し、
    チェーンの錨を `arch` の呼び出しから **`toBeUsed` の識別子 1 件** (`CHAIN_ANCHOR_NAME`) へ移した
    (`expect` は `tests/` 全数に何百件もあり錨にできない)。
    期待形の中での錨の位置は `EXPECTED_CHAIN_TOKENS` から `array_search` で引くので、
    **期待形の正本は 1 つのまま**である

## [Critical] S4-3b は波括弧なしの制御構文で迂回できる

- 判断: **対応する** (指摘は正しい。実際に迂回できた)
- 根拠: 指摘の形を gate へ実際に注入して確かめた。
  `if (false)` の直後に改行して `foreach` を書くと、
  `tokensBefore()` は期待形と一致し `braceDepthAt()` も 0 のままで、**S4-3b は緑だった**。
- 対応内容:
  - **S4-3c (実行時の登録確認) が本体の保証**であることを明示し、S4-3b の主張を
    「生成点が 1 つで全規則 ID をちょうど 1 周する形であること」まで**狭めた**
    (到達可能性は主張しない。`braceDepthAt()` の docblock にも同じ限界を書いた)
  - **注入で実測**: 波括弧なし `if (false)` を gate へ入れると
    **S4-3c が赤になり (79 → 72 tests、7 規則 ID すべてを missing として報告)**、
    取り除くと 79 tests 全緑に戻ることを確認した。`test()` 形へ書き換えた後も同じ結果である
  - 走査器の負例 **13c** に指摘の brace-less 形を追加し、
    「綴りは同一・深さも 0 のまま」という**限界そのもの**を固定した
    (共通規約 (b) の「保証範囲の外にする構文は明記し、検出力を主張しない」)

## [Warning] 13c の負例が波括弧つきだけ

- 判断: **対応する** (上と同じ修正に含まれる)
- 対応内容: 13c に brace-less `if (false)` を追加し、`braceDepthAt` が 0 のままであることを
  正例として固定した。あわせてテスト名を
  「波括弧つきの囲みは深さで見抜けるが、波括弧なしの制御構文は見抜けない」へ改めた。

## [解消済み] 7b / ProcessBarrier / ArchTokenStream

Round 1 の指摘どおり対応済みで、Round 2 で解消と判定された。追加変更なし。

## [その他] `pnpm test` の 1 件失敗

- 判断: **本 TODO の範囲外として親エージェントへ報告する** (Round 1 と同じ)
- 根拠: clean な main で同一の失敗を再現済み。本実装は `resources/js` を 1 行も触っていない。
  詳細設計も「アプリコード・`resources/` は 1 行も変更しない」と明記している。
- 対応内容: 別 TODO で追跡すべき先行破損として報告する。

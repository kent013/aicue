# 対応マトリクス: conceptual-review Round 2

## [Critical] 網羅性走査が deny-by-default になっていない (既知 API の列挙にすぎない)
- 判断: **対応する** (指摘は正しい。`rebinding` / `app()` / `Container::getInstance()` /
  未来の Container API はすべて抜けられる)
- 対応内容: §4 施策 1 の「網羅性走査」を 2 条件 → **3 条件**に作り替え、
  「API 名を列挙して禁止」から「**許可を列挙して残りを禁止**」へ反転した。
  1. 登録形の固定: `$this->app-><allowed>(` の `<allowed>` を `bind` / `make` / `environment` の
     3 つに限定 (現行コードが実際に使う form のみ)。それ以外の method 名で fail。
  2. 間接経路の禁止: `app(` / `resolve(` / `App::` / `Container::getInstance()` /
     `$this->app->getContainer()` を同ファイル内で禁止。
  3. fake 参照の集合一致: provider が参照する fake クラス集合 = inventory の fake 集合 +
     明示例外 2 件 (`CannedPromptFakeRegistrar` / `FakeStorageGate`)。
     → 未知の API で配線しても、**fake クラスを参照した時点で**条件 3 が捕まえる。

## [Warning] `refreshApplication()` を「復元の不変条件」として持つのは過剰
- 判断: **対応する**
- 根拠: 指摘どおり、それは provider ではなく Laravel `TestCase` を検査するテストになる。
- 対応内容: 検査表 (§4) から「復元」行を削除。`refreshApplication()` は
  「1 test case 内で env/config を切り替える必要が出た場合の手段」へ格下げ。
  明示的な往復検査は **static (`Prompt::$fake`) だけ**に絞った。

## [Warning] `afterEach` を経た状態はその test case では assert できない
- 判断: **対応する** (事実誤り)
- 対応内容: LLM 検査を「test 本体の `try/finally` 内で `stopFaking()` →
  `expect(Prompt::isFaking())->toBeFalse()` を assert」に修正。`afterEach` は
  **フェイルセーフ**として残すが検査表現にはしない、と §4 / §9 の両方に明記。

## [Warning] Architecture suite の 2 回連続実行は同一プロセス内リーク検出にならない
- 判断: **対応する**
- 対応内容: §8 を「2 回連続実行 = **再実行安定性**の確認 (別プロセスなのでリーク検出ではない)」
  「同一プロセス内の order 依存は `--order-by=random` で見る。**seed をログに残して再現可能にする**」
  に書き換え。

## [Warning] fake クラス母集団のディレクトリ規約が未固定
- 判断: **対応する**
- 対応内容: 施策 2 に「クラス名が `Fake` で始まる / 終わる PHP クラスは `app/**/Fakes/` か
  `app/**/Testing/` 配下にしか置けない」という**配置規約の固定**を追加。
  `app/Services/Billing/FakeFoo.php` 直置きで母集団から逃げる経路を塞ぐ。

## [Warning] §5 に禁止事項 3 の誤参照が残っている
- 判断: **対応する**
- 対応内容: §5 の代替案表から番号参照を削除し、§6.4 と同じ理由付けに統一。

## [Warning] inventory の `risk` / `mutation` が自由記述では形骸化する
- 判断: **対応する**
- 対応内容: `mutation` を **安定 mutation ID の list (`mutationIds`: M1…M7)** に変更。
  §1 の mutation 表を正本とし、gate 側の `MUTATION_COVERAGE` map と inventory の ID 集合が
  **完全一致すること**を 1 test で機械照合する。`risk` はレビュー用説明として維持。

## [Suggestion] route 二重 boot 検査は過大
- 判断: **対応する** (削る)
- 対応内容: §9 から冪等性検査を削除し、「signed route の振る舞いは既存 Feature テストの責務」
  「provider は `Route::has()` で冪等化済み、崩れても後勝ちで無害」と理由を明記。

## [Suggestion] 使命との整合 / 柱 2・柱 3b を外す判断は妥当
- 判断: **見送る** (現状維持)

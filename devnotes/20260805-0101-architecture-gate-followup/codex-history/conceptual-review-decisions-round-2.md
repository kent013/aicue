# 対応マトリクス: conceptual-review Round 2

Round 1 の Critical (description 禁止の根拠不足) は Codex が反論成立を認めたためクローズ。

## [Critical] 施策 3b を「gate なし・手動是正」とするのは禁止事項 1 (テストなしの実装完了) に抵触

- 判断: **対応する**
- 根拠: 指摘は正しい。「gate 対象外」を「テスト対象外」と読めてしまう書き方だった。
  3b は `InvitationAcceptanceController::show` の無効分岐に `setPrivateTitle` を足す =
  **挙動を変える**施策なので、回帰テストなしに完了報告はできない。
  PostgreSQL 不在は実行できないだけで、書かない理由にはならない (指摘のとおり)。
- 対応内容: 概念設計 §実装方針 3「責務境界」に
  「**ただし 3b もテスト必須である**」節を追記し、
  「gate 対象外 = 静的走査 gate を張らない、であってテストを書かないことではない」と明記。
  具体的には既存 `tests/Feature/Organization/InvitationTest.php` に**追加**する
  (削除・上書きはしない。同ファイル L289 / L303 に既に
  `assertInertia(fn ($page) => $page->component('Invitations/Invalid'))` の
  無効分岐テストがあるので、その隣に置く):
  - Inertia 共有 prop `title` (= `SeoManager::resolveDocumentTitle()` の値 =
    サーバ描画 `<title>` と同一文字列) を検証
  - 有効分岐と無効分岐で title が**異なる**ことを固定 (「無効ページなのに
    『組織への招待』」の退行を落とす)
  - 「理由・組織名を開示しない」既存の秘匿契約 (title に組織名を混ぜない) も同時に固定
  施策一覧の表も「(静的 gate なし。Feature テストで固定)」へ修正した。

## [Warning] Carbon のメソッド検出を case-sensitive にすると `->addmonth()` 等がすり抜ける

- 判断: **対応する**
- 根拠: 指摘のとおり。実測して裏を取った (`2026-01-31` 起点):

  | 呼び出し | 結果 |
  |---|---|
  | `->addMonth()` | `2026-03-03` (溢れる) |
  | `->addmonth()` | **`2026-03-03` (溢れる = すり抜けると実害)** |
  | `->addmonths()` / `->addyear()` / `->addquarter()` / `->submonth()` / `->subyears()` | いずれも受理され溢れる |
  | `->AddMonth()` / `->ADDMONTH()` | `UnknownMethodException` (Carbon が拒否) |
  | `->addmonthnooverflow()` | `UnknownMethodException` (Carbon が拒否) |

  全小文字の overflow 形が**実際に動く**ので case 無視比較は必須。
  一方 `*NoOverflow` / `*WithOverflow` は小文字化しても deny 集合と一致しないため、
  case 無視にしても許可側を巻き込まない (安全側に倒れる)。
  既存 `InertiaRenderPageExistsInvariantTest::inertiaIsIdentifier()` が
  同じ理由で `strcasecmp` を使っている先例とも整合する。
- 対応内容: deny 集合を全小文字 12 個に変更し、`T_STRING` を `strtolower()` してから
  完全一致比較する方式へ修正。上記実測表を概念設計に転記し、
  **mixed-case の正/負コントロールをテストに内蔵する**ことを明記した。

## [Warning] 非複合 global use gate が `use function` / `use const` を除外すると穴が残る

- 判断: **対応する**
- 根拠: 指摘のとおり。実測で確認したところ、PHP は class import と**まったく同じ
  warning** を出す (namespace 無しファイル):

  ```
  use function strlen;      → Warning: ... non-compound name 'strlen' has no effect
  use const PHP_VERSION;    → Warning: ... non-compound name 'PHP_VERSION' has no effect
  use RuntimeException;     → Warning: ... non-compound name 'RuntimeException' has no effect
  use function Foo\bar;     → (警告なし。複合名なので正常)
  ```

  gate の目的が「無効な非複合 global use と、それが生む warning の排除」である以上、
  除外すると同じ地雷が別の綴りで残る。gate 名を class import 限定へ狭める案は、
  実測上 warning が同一なので**採らない**。
- 対応内容: 検出方式 (c) を「`use` の直後に `function` / `const` 修飾があれば読み飛ばした上で
  import 要素が非複合かを判定」へ変更。上記実測ブロックを概念設計に転記し、
  **負のコントロールを 3 形態ぶん用意する**ことを明記。
  併せて `use X as Y;` (判定対象は `as` の前) の扱いも明文化した。

## [Warning] 1-hop 解析がメソッド名一致だけだと別オブジェクトの同名呼び出しを誤認しうる

- 判断: **対応する**
- 根拠: 指摘のとおり。`$other->applyTitle()` と `$this->applyTitle()` を区別しないと、
  gate が「タイトルを供給している」と誤判定して**取りこぼす方向**に倒れる (最悪)。
- 対応内容: 1 hop の定義を仕様として固定した (概念設計 §実装方針 3):
  1. 呼び出し形が `$this->name(` または `self::name(` の**直接呼び出し**のみ
     (`$other->name(` / `static::` / 変数メソッド名 `$this->$m(` は辿らない)
  2. `name` が**同一ファイル・同一クラスに宣言**されていること
     (継承基底・trait は辿らない)
  3. その宣言の可視性が `private` または `protected` であること
     (`public` は外部 API なので「このメソッド専用 helper」と見なさない)
  4. 辿るのは 1 段のみ
  併せて「本バッチ時点で 1 hop が必要な route は存在しない (実測)」= 将来の偽陽性への
  保険であり、正のコントロールを fixture で固定して機能保証する旨も明記した。

## [Suggestion] D11 の不変条件を「title と description の SoT」と記述すべき

- 判断: **対応する**
- 根拠: gate の射程が title + description なので、文書もそう書くのが一致する。
- 対応内容: §実装方針 4 の D11 記述を
  「`<title>` と `<meta name="description">` の SoT はそれぞれただ 1 つ (サーバ) で、
  フルロードと SPA 遷移で一致し、同一 `<head>` に重複タグを作らない」へ修正。

## [Suggestion] その他 (使命整合・効果・スコープ・型安全性の肯定的評価)

- 判断: 見送る (対応不要)

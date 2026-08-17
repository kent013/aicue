# 対応マトリクス: design-review Round 1

Codex 全体判定: **CHANGES_REQUESTED** (Critical 2 / Warning 3 / Suggestion 4)。
**Critical 2 件・Warning 3 件すべてに対応した** (反論・見送りは 0 件)。

## [Critical] 施策 4: 3-10 の候補集合から provider が静かに落ちる (検出範囲が黙って狭くなる)
- 判断: **対応する**
- 根拠: 指摘のとおり。改名で `BughuntFakesServiceProvider` は定義 2 (`Fake` で始まる /
  終わる) から外れるため、`ExternalFakeWiringInvariantTest` 3-10 の候補集合
  (`implementationClasses() ∪ namedClasses()`) から落ちる。「いま結果が変わらない」ことは
  `FakeWiringSourceScanner` が `isDeclarationName` のトークンを飛ばす実装で確かめたが、
  **将来 provider が別の配線基盤クラスを名指しした場合の網が狭くなる**という指摘は正しい。
  改名を理由に検査の網を縮めるのは本施策の趣旨 (振る舞いも保証も変えない) に反する。
- 対応内容:
  1. 3-10 の候補集合を `implementationClasses() ∪ namedClasses() ∪
     array_keys(placementExceptions())` へ統一する (詳細設計 施策 4 に差分コードを記載)。
  2. 4-3 の fail-closed 部へ「候補集合に `BughuntFakesServiceProvider` と `FakeStorageGate` が
     含まれること」の明示 assertion を足す (Codex の代替案も併せて採用)。
  3. 変更ファイル一覧に `ExternalFakeWiringInvariantTest.php` を「意味も更新する 3 ファイル」
     として明示し、受け入れ条件 A-6b の対象に入れた。

## [Critical] 受け入れ条件 A-6 の「逆置換でバイト一致」が破綻している
- 判断: **対応する**
- 根拠: 指摘のとおり。施策 4 は docblock・理由文だけでなく**コード (候補集合の式・追加
  assertion)** も意図的に変える。逆置換で main と一致しないので、条件として成立しない。
- 対応内容: A-6 を 3 つへ分解した。
  - **A-6a**: 名前だけを置換した 26 ファイル + 改名 4 ファイル = 逆置換で**バイト一致**。
  - **A-6b**: 意味も更新する 3 ファイル = 逆置換したうえで **PHP のトークン列**
    (コメント・docblock・空白を除去) で比較し、差分が (i) 3-10 の候補集合の式、
    (ii) 4-3 の明示 assertion の **2 か所だけ**であること。1 トークンでも他に差があれば不合格。
  - **A-6c**: 新規テスト 1 件は比較対象外。
- 補足: Codex の修正案 3 (AST か `diff -w` ではなく…) をトークン列比較として具体化した。
  `token_get_all` はこのリポジトリの既存 Architecture テストが実際に使っている手段であり、
  新しい道具を持ち込まない。

## [Warning] `docs/TODO-closed.md` の丸ごと除外は粒度が粗い (家の作法は件数 pin)
- 判断: **対応する** (当初案を撤回する)
- 根拠: 指摘のとおり。`RouteCacheExemptionPremiseTest` と `ForbiddenStatementExemption` は
  どちらも**件数を完全一致で pin** しており、丸ごと除外はこの 1 か所だけ粒度が落ちる。
  当初 pin を避けた理由 (aicue:T214 のクローズ記録で数が動く) は、**赤くなったら pin を
  1 つ動かす**という意図的な摩擦として受け入れられる範囲であり、
  「将来の再流入に無音」という穴の方が高くつく。
- 対応内容: 置き場所の分類を 3 つへ改めた —
  (a) 走査する / (b) 件数を完全一致で pin する (`docs/TODO-closed.md` => 2) /
  (c) 丸ごと除外する (`devnotes/` 接頭辞と本テスト自身。理由必須)。
  `bughuntNamingViolationsIn()` を「pin した件数ちょうど」を要求する形へ書き直し、
  N-4 の負のコントロールに「pin したファイルで件数がずれた入力 (1 件でも 3 件でも) を検出する」を
  追加した。クローズ記録が旧名に触れたときの正しい直し方も docblock に書く。
- 補足: `devnotes/` だけは丸ごと除外のままとする (190 ファイル規模で旧名を含み、件数 pin が
  実務にならない。`ForbiddenStatementTokenInvariantTest` に同じ扱いの前例がある)。

## [Warning] `placementExceptions()` の名前と役割のズレが将来の削除事故を誘発する
- 判断: **対応する** (ただし別メソッド新設は採らない)
- 根拠: ズレの指摘は正しい。ただし別メソッドを新設すると入口が 2 つになり、
  目録が 2 件しかない段階では「どちらに足すか」の判断がそのつど発生する
  (思考原則 2)。また関数名を変えると、本施策が「名前を揃える作業」から逸脱し、
  A-6 の逆置換検証が成立しなくなる。
- 対応内容: Codex が併記した代替案「4-2 / 4-3 で明示 assertion」を採用した。
  用途 2 (参照走査の候補) を docblock に明記し、4-3 の fail-closed 部で
  2 件が候補集合に含まれることを固定する = **落とすと赤くなる**状態にした。

## [Warning] A-9 の「テスト総数 = 元の数 + 5」は flaky
- 判断: **対応する**
- 根拠: 指摘のとおり。dataset 展開・並列実行・環境差で総数は揺れる。改名の受け入れ条件として
  過剰であり、赤の意味が読めなくなる。
- 対応内容: A-9 を「新設の N-1〜N-5 が実在して緑 / 指定した既存 invariant が緑 /
  `composer test` が failed 0 かつ skipped 件数が実装前と同じ」へ差し替えた。

## [Suggestion] 施策 2: warn/info の文言変更を「観測可能差分」として明記する
- 判断: **対応する** — 施策 2 のリスク節に、CLI 出力の文字列が変わること、
  それが A-6a の逆置換で「名前以外は変えていない」と機械的に確かめられることを書いた。

## [Suggestion] 施策 3: `bootstrap/cache/` 全般が追跡外である旨を書く
- 判断: **対応する** — `services.php` 単体ではなく `packages.php` / `config.php` /
  `routes-*.php` を含む生成物全般として書き直し、`FakeClassCatalog::EXCLUDED_PREFIXES` でも
  走査から外れていることを追記した。

## [Suggestion] N-3 の「除外は 3 つちょうど」がファイル数と誤読される
- 判断: **対応する** — 分類の変更に合わせて N-3 を「丸ごと除外の**定義**が 2 つ
  (接頭辞 1 + 自分自身 1)、件数 pin の**定義**が 1 つ」と書き直し、
  ファイル数ではなく定義の数を pin する意味であることをテスト名に書くと明記した。

## [Suggestion] 施策 2 / 3 の APPROVE 判定
- 判断: **対応不要** (追認)

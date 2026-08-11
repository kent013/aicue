# 対応マトリクス: conceptual-review Round 1

Codex 全体判定: CHANGES_REQUESTED (Critical 0 / Warning 3 / Suggestion 4)。
Warning 3 件はいずれも同一論点 (inventory mutation の実証範囲) の別角度であり、**すべて妥当**。

## [Warning] inventory の mutation 実証が弱い

- 判断: **対応する** (指摘は正しい)
- 根拠: `STATUS_WRITE_ALLOWED` / `SCENARIO_VERSION_ALLOWED` から
  `Services/Manual/VideoManualService.php` を外すと gate は赤くなるが、その赤は
  **既存の `duplicate()` の write だけでも成立する**。したがってこの mutation が実証するのは
  「ファイル粒度 gate が実際に効いている」ことまでで、**`create()` の登録が load-bearing である
  ことの証明にはならない**。設計側が「正直に書いた」つもりで、まだ一段誇張していた。
- 対応内容: 概念設計に「gate 実証の 2 分割」を明記する。
  (a) **ファイル粒度 gate の実効性** = allowlist 除外 mutation で確認する (これは
      `duplicate()` 由来でも成立する赤であることを明記)。
  (b) **`create()` 経路そのものの fail-first 保証** = **behavioral 再現テスト**が担う
      (明示代入を消すと赤くなる。これが create() に対する唯一の機械的保証)。
  「inventory 登録によって create() が機械検出されるようになる」とは**書かない**。

## [Warning] ロック規約との関係をもう一段明確にするべき

- 判断: **対応する**
- 根拠: AGENTS.md ドメイン規約 1 の文面は「対象 VideoManual 行を `lockForUpdate()` で取得した
  同一 tx 内」を要求している。`create()` は対象行がまだ存在しないため文面を literal には
  満たせない。`duplicate()` はこの点を docblock で「新規行生成のため lockForUpdate 前だが、
  その tx が生成した排他的新規行であり既存行への並行書き込みではない」と明文化して通している。
  **同じ論拠を create() にも明示的に書くべき**で、暗黙に流用してはならない。
- 対応内容: 概念設計に「**生成経路カテゴリ**」を導入し、`create()` / `duplicate()` を
  同一カテゴリとして扱うことを明記。docs/architecture.md と inventory docblock の経路表にも
  同じ表現で記す (詳細設計で文面を確定)。

## [Warning] 成功判定 (c) の表現が過大

- 判断: **対応する**
- 根拠: 上記 1 件目と同根。成功判定 (c) が「登録を外すと赤くなる」を create() の保証のように
  読める書き方だった。
- 対応内容: 成功判定 (c) を 2 つに割る。
  (c-1) `ScenarioWritePathInventoryTest` は allowlist 無変更のまま緑である。
  (c-2) mutation 2 種を実施する — ①allowlist 除外 → 赤 (ファイル粒度 gate の実効性。
        `duplicate()` 由来でも成立することを明記)、②`create()` の明示代入削除 → 
        **behavioral 再現テストが赤** (create() 経路の fail-first)。

## [Suggestion] 使命との整合性 / 禁止事項 / 実現可能性 / 型安全性

- 判断: **見送る** (肯定的評価のため設計変更不要)
- 根拠: いずれも設計の現状を追認する内容。
- 対応内容: 型安全性の助言 (「テストでは `status` が `VideoManualStatus::Draft`、
  `scenario_version` が `0` として戻ることを直接見る」) は詳細設計のテスト計画に既に一致するため、
  詳細設計でその形を明示する。

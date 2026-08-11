# Round 3: Round 2 指摘への対応

Round 2 の Warning 1 件 (AGENTS.md 正本との文言矛盾) と Suggestion 1 件 (mutation の個別化) を
**両方とも対応**しました。反論はありません。

## 対応 1: [Warning] AGENTS.md 正本との文言矛盾 → 施策 5 として追加

ご指摘のとおりで、しかも重要な点が 1 つあります —
**この矛盾は本設計が持ち込むものではなく、T066 (`duplicate()` の明示代入化) の時点で
既に発生していた**ものです。`duplicate()` も対象行が未存在であり、
「全経路は対象 VideoManual 行を lockForUpdate」を literal には満たしていません。
下位ドキュメントだけで例外を説明すると「正本を読んだ人が下位ドキュメントに辿り着くまで
矛盾に気づかない」状態が固定化します。

施策 5 を追加しました:

| # | 対象 | 変更 |
|---|---|---|
| 5 | `AGENTS.md` | ドメイン規約 1 を「更新経路 / 生成経路」の 2 分類に最小改訂 |

改訂の骨子 (ご提案の二分類をそのまま採用):

- **(i) 既存行の更新**: 対象 VideoManual 行を `lockForUpdate()` した同一 tx 内で反映する
- **(ii) 新規生成**: 対象行は未存在のため、**所有元 Project 行を `lockForUpdate()` した
  同一 tx 内で INSERT** し、そのとき初期状態 (`status` / `scenario_version`) を
  **明示代入する** (DB カラム default に依存しない)

**同じ語彙**を `ScenarioWritePathInventoryTest` の経路表 docblock と
`docs/architecture.md` §シナリオ整合の共有不変条件 にも使い、正本・設計・inventory の
三者を一致させます。

性質の明記として概念設計に次を書きました:

> これは規約の**追加**ではなく**既存規約の適用範囲の明確化**であり、既存の準拠実装
> (`ScenarioService` 等の更新経路) への要求は 1 ミリも緩めない。

つまり (i) の要求は現状のままで、(ii) はむしろ**要求が増える** (明示代入が必須になる) 方向です。

## 対応 2: [Suggestion] mutation ② の個別化 → 対応

ご指摘のとおり、両方同時に消すと先に評価される assertion で停止し、もう片方の保証を
実証できません。mutation ② を分割しました:

- **②-a** `create()` の `status` 明示代入**のみ**を消す → status の assertion が赤
- **②-b** `create()` の `scenario_version` 明示代入**のみ**を消す → scenario_version の assertion が赤

「2 つを**個別に**行う。同時に消すと先に評価される assertion で停止し、もう片方の保証を
実証できない」と理由も併記しました。

## 変更していない点 (確認)

- 保証分担の 3 層 (Architecture test = ファイル粒度 / Behavioral test = `create()` の初期状態 /
  ドキュメント = メソッド単位の経路記録) の記述はそのまま維持
- 代替案 A / B / C / D の却下は維持
- migration の default は残す / pipeline-smoke 側は触らない (原因側 1 箇所)
- 「保証しないもの」節は維持 (第 3 の生成経路には沈黙 / `take_upload_reservations` は記録のみ /
  pipeline-smoke 全体の緑は保証しない)

以上を踏まえ、再判定をお願いします。

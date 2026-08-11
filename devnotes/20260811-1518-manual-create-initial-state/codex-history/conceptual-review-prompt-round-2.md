# Round 2: Round 1 指摘への対応

Round 1 の Warning 3 件は**すべて同一論点 (inventory mutation の実証範囲) の別角度**であり、
**すべて妥当**と判断して**全件対応**しました。反論はありません。

## 対応 1: [Warning] inventory の mutation 実証が弱い → 対応

ご指摘のとおりでした。「allowlist から `VideoManualService.php` を外して赤を確認する」は
**既存の `duplicate()` の write だけでも赤くなる**ため、`create()` 登録の実証にはなりません。
設計側は「正直に書いた」つもりで、まだ一段誇張していました。

概念設計に「gate 実証は 2 つに分けて書く」という表を入れ、実証**しない**ことを明記しました:

| 何を実証するか | 手段 | 実証**しない**こと |
|---|---|---|
| ファイル粒度 gate が実際に効いている | allowlist から `Services/Manual/VideoManualService.php` を一時除外 → `ScenarioWritePathInventoryTest` が赤 → 戻す | **`create()` の登録が load-bearing であること**。この赤は既存の `duplicate()` の write だけでも成立する |
| `create()` の明示代入が消えたら気づけること | `create()` の `forceFill` から `status`/`scenario_version` を消す → **behavioral 再現テストが赤** | 将来新設される第 3 の生成経路 (沈黙する) |

さらに本文へ次の 2 文を追加しました:

> **「inventory 登録によって create() が機械検出されるようになる」とは書かない。**
> `create()` に対する唯一の機械的保証は **behavioral 再現テスト**である。

## 対応 2: [Warning] ロック規約との関係の明文化 → 対応

「生成経路カテゴリ」という節を新設し、`create()` / `duplicate()` を同一カテゴリとして扱うことを
明記しました。要点:

- 規約 1 の文面は「対象 VideoManual 行を lockForUpdate した同一 tx 内」だが、
  `create()`/`duplicate()` は**対象行がまだ存在しない**ため文面を literal には満たせない
- `duplicate()` は既にこの点を docblock で明文化して通している
  (「新規行生成のため lockForUpdate 前だが、その tx が生成した排他的新規行であり
  既存行への並行書き込みではない」)
- **同じ論拠を `create()` にも明示的に書く (暗黙に流用しない)**。経路表では
  「**生成経路** (既存行更新ではなく、Project 行を `lockForUpdate()` した同一 tx 内の新規 INSERT)」
  という同一カテゴリとして扱い、inventory docblock と `docs/architecture.md` の**両方に同じ表現**で記す

## 対応 3: [Warning] 成功判定 (c) が過大 → 対応

成功判定を次のように書き換えました:

- (a) `create()` の戻り値の `status` / `scenario_version` を読むテストが**修正前に赤・修正後に緑**
- (b) pipeline-smoke の fixture 段が通る
- (c-1) `ScenarioWritePathInventoryTest` は **allowlist 無変更のまま緑**である
- (c-2) mutation 2 種で赤化を実証する —
  ① allowlist 除外 → gate が赤 (**ファイル粒度 gate の実効性の確認であり、`duplicate()` の
  既存 write だけでも成立する赤 = `create()` の登録が load-bearing であることの証明ではない**)、
  ② `create()` の明示代入削除 → **behavioral 再現テストが赤** (create() 経路の唯一の機械的保証)

## 対応 4: [Suggestion] 型安全性のテスト形 → 詳細設計へ反映

「テストでは `status` が `VideoManualStatus::Draft`、`scenario_version` が `0` として戻ることを
直接見る」は詳細設計のテスト計画にそのまま採用します
(`refresh()` を挟まない**戻り値インスタンスそのもの**を assert する形にします)。

## 変更していない点 (確認)

- 代替案 A (呼び出し側 refresh) / B (`protected $attributes`) / C (migration default 削除) /
  D (横断 Architecture テスト新設) の却下は維持
- migration の default は残す / pipeline-smoke 側は触らない (原因側 1 箇所)
- 「保証しないもの」節 (第 3 の生成経路には沈黙 / `take_upload_reservations` は記録のみ /
  pipeline-smoke 全体の緑は保証しない) は維持

以上を踏まえ、再判定をお願いします。

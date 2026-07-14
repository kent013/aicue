## Round 3: 概念設計の改訂（Round 2 指摘への対応）

前ラウンドの [Critical] / [Warning] に対応しました。使命・禁止事項は再掲不要。残 Critical/Warning があるか判定してください。

### 対応サマリー

- **[Critical] 新規 manual の lockForUpdate（文言準拠）**: Service を「新 manual を save() → 同一 tx 内で `$locked->manuals()->whereKey($new->id)->lockForUpdate()->firstOrFail()` で再取得しロック → その locked インスタンスの `cuts()` relation 経由で cut を作成」に変更。規約文言「対象 VideoManual 行を lockForUpdate で取得した同一 tx 内で反映」を literal に満たす（既存 ScenarioService 準拠形と一致）。
- **[Warning] inventory 登録は architecture.md だけでは不足**: `ScenarioWritePathInventoryTest.php` の docblock 経路表に `VideoManualService::duplicate()`（書いてよいもの = cuts。lockForUpdate 済み新 manual 経由）を明示追記 + architecture.md 追記。scanner の検出 1/2/4 は duplicate が scenario_version/status/adopted_take_id リテラルを書かないため追加 allowlist 不要（docblock に理由明記）。
- **[Warning] category 存在オラクル**: route が `project.in-current-org` middleware 配下で、middleware は FormRequest 検証より前に cross-org `{project}` を 404、`{manual}∈{project}` も route model binding で検証前 404。よって category exists を route project id にスコープするだけで cross-project/cross-org は 422（存在差を漏らさない）。設計に検証順序を明記。
- **[Warning] 422/404 契約固定**: 不正値・他 project category = 422（FormRequest）、検証後の削除/移動競合のみ Service 再解決 firstOrFail で 404。両方テスト。
- **[Warning] validated('category') が mixed（PHPStan L10）**: FormRequest に型付きアクセサ `title(): string` / `categoryId(): ?int`（validated + Assert で narrow）を追加。Controller はアクセサ経由。
- **[Suggestion] 後続フロー接続テスト**: 「複製直後の CutSequencer::orderedWithLabels が全 cuts をラベル付きで返す」を追加。take 採用後のレンダ長再計算は既存レンダ経路テストの領域とし複製側は cut_length_ms=null 初期化確認に留める。

### 改訂差分（該当箇所のみ抜粋。全文は Round 2 から上記のみ変更）

**Service::duplicate（要点）**:
```
DB::transaction:
  $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
  $lockedSource = $locked->manuals()->whereKey($source->id)->lockForUpdate()->firstOrFail();
  $new = $locked->manuals()->make(['title' => $title]);
  $new->forceFill(['created_by' => $userId])->save();
  if ($categoryId !== null) {
      $category = $locked->categories()->whereKey($categoryId)->firstOrFail(); // 競合時 404
      $new->category()->associate($category)->save();
  }
  // 共有ロック規約 literal 準拠: cuts 書き込み先の新 manual をロックして再取得
  $lockedNew = $locked->manuals()->whereKey($new->id)->lockForUpdate()->firstOrFail();
  // step→point を sort_order 順に複製、parent_cut_id を旧→新 map で張替え、
  // 本文は fill / type・sort_order は forceFill、adopted_take_id/cut_length_ms は書かない(null)
  return $lockedNew;
```

**DuplicateVideoManualRequest（要点）**: StoreVideoManualRequest 厳密踏襲 + `title(): string` / `categoryId(): ?int` アクセサ。

質問: 上記で共有ロック規約・存在オラクル・型安全性・契約固定は十分か。他に v1 スコープで見落としはあるか。

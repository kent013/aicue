# 対応マトリクス: design-review Round 3

Codex 全体判定: CHANGES_REQUESTED (施策1 / 施策3 / 施策6 が REQUEST_CHANGES、他は APPROVE)。
必須修正 2 点 + Suggestion 2 点に対応する。

## [Warning] 施策1: `str_contains()` による否定判定が走査器共通規約 (e) に抵触する

- 判断: 対応する (Codex 提示の代替案のうち「否定検査を削除し、正の委譲文字列だけを固定する
  非常に限定的な pin へさらに縮小する」を採用)
- 根拠: 指摘は正しい。「含まないこと」の部分文字列検査は、alias・接頭辞付き別クラス・
  コメント内記述のいずれでも簡単に誤る。トークン化 + FQN 解決までする専用スキャナは
  1 メソッドの委譲確認のためには過大 (思考原則 2)。「見逃す方向へ倒すのは不可、
  拾いすぎる方向は可」(AGENTS.md 走査器共通規約 (b)) に照らすと、**否定判定は
  見逃しの方向へ倒れるリスクが実害として大きい**ため削除し、
  「委譲文字列が存在するか」という**正の判定 1 つ**だけへ縮小する。
- 対応内容: `EffectiveMaterialType::of(` / `StillDisplayDuration::secondsFor(` を
  含まないことの否定判定 2 件を削除する。残すのは
  `DeterminedCutDuration::milliseconds(` を含むことの正の判定だけである。

## [Warning] 施策1: 検出関数がArchitectureテスト内ローカル定義で、自己テストの配置先と食い違う

- 判断: 対応する
- 対応内容: 正例テストと合成負例の自己テストを**同一 Architecture テストファイル**に置く
  (`tests/Unit/Architecture/` への分離をやめる)。ローカル関数を別レーンから
  共有する前提を作らない。

## [Suggestion] 施策3: 「1度だけ解決」という設計要点の文言が docblock の説明と矛盾する

- 判断: 対応する
- 対応内容: 「URL/ACK の発行条件と尺算出は、同じ 1 回の解決結果 (`$adopted`) を共有する」
  という限定した書き方へ訂正する。

## [Warning] 施策3: `relationLoaded('takes')` だけでは親子整合性を保証しない (`setRelation()` 経由の混入)

- 判断: 対応する
- 根拠: 指摘は正しい。`$cut->setRelation('takes', $arbitraryCollection)` は
  `relationLoaded()` を true にしたまま任意の Collection を仕込める。
  「relation 経由なら親子整合性は構造的に保証される」という前提は、
  relation が常に `HasMany` クエリ経由で作られる場合にしか成立せず、
  `setRelation()` を使う限り成立しない。表示順テストで投入順を検証するために
  設計自身が `setRelation()` を使う可能性が高く、指摘は具体的である。
- 対応内容: `relationLoaded()` 確認の後、`$cut->takes` の**全要素**について
  `take->cut_id === $cut->id` を `Assert` で確認する (fail-closed)。
  DB への再問い合わせではなくメモリ上の値検査なので N+1 は復活しない。

## [Warning] 施策3: nested route の根拠として挙げた既存テストが cross-org のみで同一 org 内の
  project 不整合を固定していない

- 判断: 対応する (実際に確認し、既存テストのカバー範囲外であることを認めた上で新規テストを追加)
- 根拠: `tests/Feature/Capture/CaptureManualBrowsingTest.php` の
  `browsingContext()` ヘルパは 1 organization につき project を 1 個しか作らず、
  既存の `'cross-org の project は index / show とも 404'` は
  **別 organization** の project + その project の manual という組合せしか検証していない。
  「許可された project A の URL に project B (同一 org) の manual を差し込む」ケースは
  現状どの既存テストにも無い。`NestedRouteIdorDefenseTest` 自体も分類の網羅性検査であり
  実際の 404 挙動は検証しない (同テストの docblock に明記されている)。
- 対応内容: `tests/Feature/Capture/CaptureManualBrowsingTest.php` へ新規テストを追加する。
  ```php
  test('同一 org 内の別 project の manual を URL に差し込むと 404 (認可より前)', function (): void {
      [$organization, $owner, $projectA] = browsingContext();
      $projectB = Project::factory()->forOrganization($organization)->create();
      $manualOfB = VideoManual::factory()->forProject($projectB)->create(['status' => 'ready']);

      $this->actingAs($owner)
          ->get("/app/projects/{$projectA->id}/manuals/{$manualOfB->id}")
          ->assertNotFound();
  });
  ```

## [Suggestion] 施策3: 「変更箇所」に「`fromCut()` のシグネチャ」とあるが最終案はシグネチャ不変

- 判断: 対応する
- 対応内容: 「シグネチャ」という語を削除し、「`takes` の取得契約 (relation 読み取り +
  fail-closed 検査)」へ訂正する。

## [Warning] 施策4: リスク節の文言がテスト計画の訂正後の説明と食い違う

- 判断: 対応する (Round 2 でテスト計画は訂正済みだったが、リスク節の同種の文言を見落としていた)
- 対応内容: リスク節も「PHP shape と TS fixture はそれぞれ独立に固定され、
  自動で食い違いを検出する単一の仕組みではない」へ揃える。

## [Warning] 施策6: 「異なる親の Take 混入は構造的に発生しない」としたため必要な負例が消えていた

- 判断: 対応する (施策3の `take.cut_id` 検証の追加に伴い、この負例は再び必要かつ実装可能になる)
- 対応内容: `CaptureCutDataTest` へ「別 cut の Take が `setRelation()` でロード済み relation に
  混入していたら例外」テストを追加する。

全体判定: **CHANGES_REQUESTED**

設計の方向性は妥当です。特に「実際に container 解決して厳密一致で見る」方針は、具象クラス auto-resolve による偽グリーン対策として正しいです。ただし、現状の詳細設計には gate が無音化する穴と、実装者が割れる曖昧さがあります。

## 施策 1: fake 配線 inventory

判定: **APPROVE**

[Suggestion] `ExternalFakeBinding::label()` は現状の 5 件では衝突しませんが、将来同名 interface/class が増えると dataset 名が衝突します。必要なら `str_replace('\\', '.', $this->abstract)` など FQCN ベースにしておくと安定します。

## 施策 2: 走査 helper

判定: **REQUEST_CHANGES**

[Critical] `sourceFiles()` のパス仕様と `FAKE_REFERENCE_ALLOWED` の仕様が食い違っています。  
設計では `sourceFiles()` は「repo ルートからの相対パス」として `app/Providers/...` を返す一方、allowlist は「app/ 相対」として `Providers/...` を持っています。これだと provider allowlist が効かず、実装者判断で割れます。  
修正案: どちらかに統一してください。おすすめは `FakeClassCatalog::appSourceFiles()` は `app/` 相対を返す、`classFromPath()` も app 相対を受ける、と明記することです。

[Warning] `bindPairs()` / `disallowedContainerCalls()` が short class name を FQCN に解決する仕様が不足しています。現行 provider は `FakeTicketCheckoutGateway::class` のように use 済み short name を使っているため、ここが曖昧だと 3-8 が成立しません。  
修正案: scanner 共通で namespace/use map を構築し、`A::class` の `A` を `class-string` FQCN に正規化する、と明記してください。alias / group use も対象に含めるべきです。

[Warning] docblock の型規約が一部弱いです。  
修正案: `bindPairs()` は `@return list<array{abstract: class-string, concrete: class-string|null}>`、`classFromPath()` は `@return class-string` にしてください。`ALLOWED_APP_CALLS` も `array<string, list<class-string>|null>` 相当の docblock があるとよいです。

## 施策 3: 実証ベースの配線 gate

判定: **REQUEST_CHANGES**

[Warning] 3-2 は storage 系でも成立する見込みです。Architecture lane でも Laravel `TestCase` は起動しており、通常 `APP_ENV=testing` かつ `runningUnitTests()` が true になるため `FakeStorageGate` は通ります。ただしこれは `testing` だけの実証です。`bughunt.local` / `local` の allowlist 挙動は固定されません。  
修正案: `allowedEnvironments` に載せた環境は data-driven に fake 解決を確認し、代表的な allowlist 外環境として `staging` も real のままになることを確認してください。`production` だけだと未知環境の混入を捕まえきれません。

[Warning] M5 の期待が不正確です。提示 mutation は既存の `FakeRenderObjectStorage` を concrete に使っているため、3-10 は赤くならない可能性が高いです。3-8 は赤くなります。  
修正案: 表を「M5 は 3-8 が担当。未登録 fake クラスを新規参照する mutation の場合のみ 3-10 も赤」と直してください。

[Warning] `MUTATION_IDS` が M3〜M7 だけで、M1/M2 を含みません。受入条件では M1/M2 が中核なので、coverage test の名前と実体がずれています。  
修正案: `MUTATION_IDS` を M1〜M7 にするか、定数名を `SOURCE_MUTATION_IDS` などに変えて M1/M2 は data-driven 解決検査で担保する、と明記してください。

[Warning] Pest ファイル直下の `MUTATION_IDS` / `fakeWiringProviderSource()` は名前が汎用的です。将来別 Architecture test と衝突し得ます。  
修正案: `EXTERNAL_FAKE_WIRING_MUTATION_IDS` のように prefix するか、小さな test-local helper class に閉じ込めてください。

## 施策 4: 本番コードの fake 参照 全走査 gate

判定: **REQUEST_CHANGES**

[Critical] 4-3 が `implementationClasses()` だけを候補にすると、`FakeStorageGate` や `FakeExternalsServiceProvider` のような placement exception への本番コード参照を検出できません。これは「本番コードは fake クラスを参照しない」という gate の偽グリーンになります。  
修正案: 走査候補を `implementationClasses() ∪ array_keys(placementExceptions())` にしてください。そのうえで provider / fake storage controller など正当な参照元だけを allowlist します。

[Warning] 走査対象が `app/` のみだと、`routes/` や `config/` から fake class を参照する抜け道が残ります。route に Testing controller を直書きしても M7 相当を捕まえられません。  
修正案: 「本番コード全走査」を名乗るなら scan roots を `app/`, `routes/`, `config/`, `bootstrap/` まで広げてください。bootstrap の provider 登録は明示 allowlist に入れればよいです。

[Warning] allowlist のパス基準が施策 2 と未整合です。  
修正案: `FAKE_REFERENCE_ALLOWED` は `app/` 相対か repo 相対かを固定し、scanner 側で同じ形式に正規化してください。

## 施策 5: 走査 helper 自身のテスト

判定: **REQUEST_CHANGES**

[Warning] 12 ケースは過大ではありません。gate 自体が不変条件なので必要です。ただし現行 provider の実パターンを固定するケースが足りません。  
修正案: `$this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class)` のように use 済み short class name を FQCN に解決できること、alias 付き short name を `bindPairs()` が解決できること、`make(FakeStorageGate::class)->enabled()` の chain を許可できることを追加してください。

[Suggestion] `file_get_contents()` を `(string)` cast すると失敗時に空文字になります。3-8 で赤くはなりますが、原因が読み取り失敗だと分かりにくいです。helper 内で `is_string($source) && $source !== ''` を assert すると診断性が上がります。

## 施策 6: ドキュメント追記

判定: **REQUEST_CHANGES**

[Warning] 冒頭の依頼は「tests/ 配下のみを追加するテスト基盤」です。一方、施策 6 は `docs/architecture.md` を変更します。さらに「変更ファイルは 6 本」とありますが、実際は新規 7 本 + docs 追記で 8 本です。  
修正案: 今回本当に tests-only にするなら施策 6 を後続 TODO に落としてください。docs 追記も含めるなら、スコープ説明とファイル数を正しく修正してください。

## 補足

- 3-2 の厳密一致方針は正しいです。`instanceof` だと storage fake が real 継承の場合に対照が壊れます。
- M1/M2/M3/M4/M6 は、設計どおり実装されれば捕まえられる見込みです。
- 最大の修正点は、施策 4 の候補集合と scan roots です。ここを直さないと「登録漏れ」ではなく「本番コードから fake を直接参照する」系の偽グリーンが残ります。
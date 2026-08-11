全体判定: **CHANGES_REQUESTED**

大筋の原因修正は妥当です。`create()` で `status=Draft` / `scenario_version=0` を INSERT 時に明示代入する方針は、実走で出た `$manual->status->value` の null 問題に対する正しい修正です。  
ただし、テストと正本改訂にいくつか取りこぼしがあり、このまま実装に入るには危険です。

## 施策 1: `create()` の初期状態を明示代入する

判定: **APPROVE**

[Suggestion] 「戻り値インスタンスが hydrate 済み」という表現は少し不正確です。DB から hydrate されるのではなく、Eloquent インスタンスに属性が明示セットされる、という話です。

修正案:

> 戻り値インスタンス上でも `status` / `scenario_version` が読み出し可能になる

実装方針自体は問題ありません。`forceFill` + enum cast は既存 `duplicate()` と同型で、DB に入る値も現行 default と一致します。`category()->associate()->save()` の二度目の save でも、明示セット済み属性が消える可能性は低いです。

## 施策 2: 再現テスト

判定: **REQUEST_CHANGES**

[Warning] `category()->associate()->save()` と `appendDocument()` との相互作用をテストしていません。  
重点項目 (d) で明示されているのに、提案テストは `categoryId=null` / `document=null` の経路だけです。これだと「二度目の save 後も戻り値の status/scenario_version が保持される」「SOP 同時アップロード後も pipeline-smoke 相当の戻り値で落ちない」を固定できません。

修正案: 1 本目の fail-first テストを、少なくとも category あり + document ありに寄せてください。

例:

```php
$category = Category::factory()->forProject($project)->create();
$document = UploadedFile::fake()->createWithContent('sop.txt', '手順 1');

$manual = app(VideoManualService::class)->create(
    $project,
    'テスト手順書',
    $category->id,
    $owner->id,
    $document,
);

expect($manual->status)->toBe(VideoManualStatus::Draft);
expect($manual->scenario_version)->toBe(0);
expect($manual->status->value)->toBe('draft');
expect($manual->category_id)->toBe($category->id);
expect($manual->sourceDocuments()->count())->toBe(1);
```

[Warning] mutation ② の説明で「片方の assertion は緑のまま」と書いていますが、同一テスト内では最初の失敗で止まるため、実際には観測できません。

修正案: `status` と `scenario_version` の戻り値契約テストを分けるか、mutation 手順の説明から「もう片方が緑と観測できる」という表現を削ってください。

[Suggestion] テスト追加時は `VideoManualStatus` と `DB`、document ありにするなら `UploadedFile` / 必要なら `Storage` の import 追加を設計に明記してください。

## 施策 3: inventory 経路表

判定: **REQUEST_CHANGES**

[Warning] `T066` を「変更しない」としていますが、テスト名とコメントの「明示代入の fail-first 契約」は過大です。現在の `T066` はファイル粒度であり、`create()` の明示代入を消しても `duplicate()` が残れば通ります。設計本文ではその限界を正しく書いている一方、テスト名がそれを裏切っています。

修正案: `T066` の名称・コメントを「VideoManualService ファイルに status/scenario_version write が少なくとも存在する」程度に弱め、`create()` 単体の保証は `ManualServiceBoundaryTest` が担う、と明記してください。

## 施策 4: `docs/architecture.md`

判定: **REQUEST_CHANGES**

[Warning] 引用ブロックと経路表だけを直す設計になっていますが、直後の既存文言と矛盾します。

現行文:

> 直列化点は VideoManual 行 (Project 行はロックしない...)

今回の設計では `create()` / `duplicate()` の生成経路で Project 行 lock を正当化するため、この文を残すと docs 内で矛盾します。

修正案: この段落も更新してください。

例:

> 更新経路の直列化点は VideoManual 行。生成経路は対象 VideoManual 行が未存在のため、所有元 Project 行を lockForUpdate した tx 内で INSERT する。`duplicate()` の cuts materialization は、新規 manual を保存後に lockForUpdate で再取得してから行う。

## 施策 5: `AGENTS.md`

判定: **REQUEST_CHANGES**

[Warning] 2 分類化の方向は妥当ですが、提案文だと `duplicate()` が書く cuts まで「生成経路」の Project lock だけでよいように読めます。これは既存要求を弱める余地があります。

`duplicate()` は二種類の write を持ちます。

- 新規 manual の `status` / `scenario_version`: INSERT 時の生成初期値
- 新規 manual 配下の `cuts`: 保存後に新 manual を `lockForUpdate()` で再取得してから作成

修正案: AGENTS.md では `duplicate()` を単純に生成経路へ丸めず、cuts についての lock 要求を明示してください。

例:

> `duplicate()` は status/scenario_version の初期代入については生成経路、cuts の materialize については保存済み新 manual を `lockForUpdate()` で再取得した同一 tx 内で行う。

これを入れれば、既存規約を弱めずに T066 以後の実装実態と整合します。

## 保証しないもの

[Warning] 「将来新設される第 3 の生成経路には沈黙する」は少し雑です。新しいファイルで `status` / `scenario_version` を明示 write すれば inventory は検出します。一方、同一 `VideoManualService.php` 内の新メソッドや、DB default 依存で一切 write しない生成経路は検出できません。

修正案:

> 新しい生成経路が同一ファイル内に追加された場合、または DB default に依存して明示 write を持たない場合は、ScenarioWritePathInventoryTest だけでは検出できない。

[Warning] `take_upload_reservations.status` について「戻り値の status を読む呼び出し側は現状 1 つも無い」と断言していますが、この詳細設計内には根拠がありません。  
修正案: recon-brief の具体的な検索結果を引用するか、「recon-brief で確認済み」と明示してください。

## 検証計画

[Warning] 「全 green でコミット」と言いながら、コードブロックは backend 3 本だけです。AGENTS.md の検証コマンド一覧には package 系も含まれます。

修正案: 最終検証リストを AGENTS.md と同期してください。少なくとも `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を落とさないでください。

---

結論として、実装方針は正しいですが、正本改訂と fail-first の実証範囲がまだ甘いです。特に `duplicate()` の cuts lock 要求を AGENTS.md で弱めて読める点と、`create()` の category/document 経路をテストしていない点は修正してください。
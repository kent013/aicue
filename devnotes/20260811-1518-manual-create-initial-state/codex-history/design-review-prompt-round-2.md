# Round 2: Round 1 指摘への対応

Round 1 の **Warning 8 件・Suggestion 3 件をすべて対応**しました。反論はありません。
とくに施策 5 の指摘（`duplicate()` の cuts lock 要求が弱まる）は、設計が自ら掲げた
「(i) を一字も弱めない」という緩和条件に**自分で違反していた**もので、最も重要な修正でした。

---

## 施策 1 [Suggestion] 「hydrate 済み」が不正確 → 修正

docblock 案を次に変更:

> DB カラム default に依存しない = 将来の migration default 変更による silent break を防ぐ +
> **戻り値インスタンス上でも status/scenario_version が読み出せる**ようになる。
> **DB から hydrate されるのではなく、INSERT 前に属性を明示セットするためである**。

---

## 施策 2 [Warning] category あり / document あり の経路をテストしていない → 修正

ご指摘のとおりで、しかも **pipeline-smoke が実際に踏んだ形は `document` あり**でした。
最短経路だけをテストしていたのは重点確認項目 (d) を自分で挙げながらの取りこぼしです。

テスト 1（fail-first の本体）を **category あり + document あり**に変更:

```php
Storage::fake();
[$organization, $owner] = createOrganizationWithOwner();
$project = Project::factory()->forOrganization($organization)->create();
$category = Category::factory()->forProject($project)->create();
$document = UploadedFile::fake()->createWithContent('sop.txt', '手順 1: 装置の電源を入れる');

$manual = app(VideoManualService::class)->create(
    $project, 'テスト手順書', $category->id, $owner->id, $document,
);

expect($manual->status)->toBe(VideoManualStatus::Draft);       // 修正前は null で赤
expect($manual->status->value)->toBe('draft');                 // 実走と同じ症状
expect($manual->category_id)->toBe($category->id);             // 2 度目の save 後も残る
expect($manual->sourceDocuments()->count())->toBe(1);          // appendDocument を通っている
```

これで「`category()->associate()->save()` の 2 度目の save 後も属性が保持される」
「SOP 同時アップロード後も戻り値で落ちない」の両方が固定されます。

## 施策 2 [Warning] mutation ② の「片方は緑」は同一テスト内では観測できない → 修正

ご指摘のとおりです。設計が自分で「同時に消すと観測できない」と書きながら、
同じ罠に落ちていました。**テストを属性ごとに分割**しました:

- **テスト 1**: status 契約（category+SOP あり）
- **テスト 2**: scenario_version 契約（最短経路。分割の目的は mutation の観測可能性そのもの）
- **テスト 3**: DB 実値（戻り値だけ整えて DB は別値、の取り違え防止）

mutation 手順も書き直しました:

```bash
# ②-a: 'status' => VideoManualStatus::Draft の 1 行だけ削除
# 期待: テスト 1 が RED / テスト 2 は GREEN のまま  ← この非対称が観測できることが要件
# ②-b: 'scenario_version' => 0 の 1 行だけ削除
# 期待: テスト 2 が RED / テスト 1 は GREEN のまま
```

「テストを 1 本にまとめていた場合は最初の失敗で停止するため**そもそも非対称を観測できない** —
これが施策 2 でテストを分割した理由である」と根拠も併記しました。

## 施策 2 [Suggestion] import の明記 → 追記

`VideoManualStatus` / `UploadedFile` / `Storage` / `DB` を追加 import として明記
（`Category` / `Project` / `VideoManual` / `VideoManualService` は既存）。

---

## 施策 3 [Warning] T066 のテスト名・コメントが過大 → 修正

ご指摘のとおり、**設計本文が限界を正直に書いているのにテスト名がそれを裏切っている**のは
最悪の形（読んだ人が保証されていないものを保証されていると誤認する）でした。

施策 3 に「T066 テストの**名称・コメント是正**」を追加:

- **assertion は 1 行も変えない**（禁止事項 3 = 既存テストの削除・上書きに当たらないよう
  検査内容は不変で、名前とコメントだけを実態に合わせる）
- 新名称: `T066: VideoManualService ファイルに status/scenario_version の明示 write が
  少なくとも 1 つ存在する (allowlist の degenerate PASS 防止。ファイル粒度であり
  メソッド単位の fail-first ではない)`
- コメント追記: 「メソッド単位の fail-first は本テストでは担えない（create() の明示代入を
  消しても duplicate() が残れば通る）。create()/duplicate() それぞれの初期状態の保証は
  ManualServiceBoundaryTest / ManualDuplicateTest の behavioral テストが担う。」

---

## 施策 4 [Warning] 「直列化点は VideoManual 行 (Project 行はロックしない)」と矛盾 → 修正

ご指摘のとおり、放置すると**同じ節の中で矛盾**します。変更対象に L220-221 の段落を追加し、
次のように書き分けます:

```markdown
- **更新経路**の直列化点は VideoManual 行 (Project 行はロックしない。…直列化粒度を manual に
  意図的に絞る)。親 relation 経由の再解決で「子は親に属する」も同時に担保する
- **生成経路**は対象 VideoManual 行が未存在のため、所有元 Project 行を lockForUpdate した
  同一 tx 内で INSERT する。**免除されるのはその tx が生成した新規行の初期値
  (status / scenario_version) の INSERT のみ**であり、生成後の行に対する後続の書き込みは
  更新経路として扱う — `duplicate()` の cuts materialize は、保存した新 manual を
  lockForUpdate で**再取得してから**行う (`copyCuts` の呼び出し前提)
```

---

## 施策 5 [Warning] `duplicate()` の cuts まで生成経路に丸められ既存要求が弱まる → 修正（最重要）

**最も重要な指摘でした。** 設計は施策 5 のリスク欄で自ら
「緩和策 = (i) の文面を一字も弱めない」と書きながら、提案文がその条件に違反していました。

AGENTS.md 改訂案に **「免除の範囲を広げない」節**を追加:

```markdown
   - **免除の範囲を広げない**: (ii) が `lockForUpdate()` を免除されるのは
     **その tx が生成した新規行の初期値 (`status` / `scenario_version`) の INSERT のみ**である。
     **生成後の行に対する後続の書き込み (`cuts` 等) は (i) 更新経路として扱い**、
     保存済みの新 manual を `lockForUpdate()` で**再取得した**同一 tx 内で行う
     (準拠実装: `duplicate()` は新 manual を save 後に `lockForUpdate()` で再取得してから
     `copyCuts()` を呼ぶ)
```

さらに設計本文に注記を置きました:

> **この「免除の範囲を広げない」節が施策 5 の要**である。これが無いと
> `duplicate()` の cuts まで「生成経路だから Project 行ロックだけでよい」と読め、
> **既存要求を弱めてしまう**。

リスク欄にも実装時の自己点検を追加:

> 改訂後の規約を読んで、`duplicate()` の `copyCuts` が `lockForUpdate()` 済みの新 manual
> 経由でなければならないことが**読み取れるか**を確認する。読み取れなければ文面が緩んでいる（差し戻す）。

同じ文を `docs/architecture.md` と inventory docblock にも置きます（三者同語彙）。

---

## 保証しないもの [Warning] 「第 3 の生成経路には沈黙」が雑 → 精密化

ご指摘のとおり、**新しいファイル**が明示 write すれば deny-by-default で検出されます。
検出限界を 3 分岐に書き直しました:

- **検出される**: 新しいファイルが `status` / `scenario_version` を明示 write した場合
- **検出されない (a)**: 同一 `VideoManualService.php` 内に新メソッドを足して write した場合
  （allowlist はファイル粒度）
- **検出されない (b)**: **明示 write を一切持たず DB default に依存する生成経路**
  （token 走査は「書いていないこと」を見られない）。
  **これは本件とまったく同じバグの再発形であり、gate は沈黙する。**
  この形の再発を防げるのは behavioral テストと人間のレビューだけである

(b) を明示したのは、本件の再発こそが最も起こりやすいからです。

## 保証しないもの [Warning] `take_upload_reservations` の断言に根拠がない → 根拠を明記

設計時の実走査結果を本文に書きました:

- `rg -n -e 'reservation->status' -e 'reservation\?->status' app/ tests/` の全ヒットを確認し、
  `TakeUploadReservation` の `status` を読むのは `TakeRegistrationService` /
  `StaleUploadReservationSweeper` / テスト 2 本（`TakeUploadReservationModelTest` /
  `TakeUploadUrlTest`）のみで、**いずれも DB から取り直した行**である。
  **`TakeUploadService::issue()` の戻り値インスタンスの `status` を読む呼び出し側は 0 件**
- `TakeUploadService::issue()` の戻り値は `TakeUploadTicketData`
  （`presigned` / `ticket` / `client_take_id` のみ）で、`status` を外へ出していない

「別 TODO 候補として記録するに留める（**再発時の調査コストを下げるための記録であり、
対処の約束ではない**）」とも明記しました。

---

## 検証計画 [Warning] backend 3 本だけ → AGENTS.md と同期した全 9 本に

`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を追加し、
「差分が無いから省く、をしない」と明記。
T099 のグローバルテストロック（待ちは正常 / kill しない）の注記も添えました。

---

## 施策 1 [Suggestion] 2 度目の save で属性が消える可能性は低い → 実測で固定する

理屈では消えませんが、**理屈で済ませず** category あり経路のテスト
（テスト 1 の `expect($manual->category_id)->toBe($category->id);` と status の assert）で
実測として固定します。

---

以上を踏まえ、再判定をお願いします。

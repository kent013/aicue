# 概念設計: manual-duplicate-status-explicit

## 背景・課題

`app/Services/Manual/VideoManualService::duplicate()` は複製 manual の
`status` (=draft) と `scenario_version` (=0) を **DB カラムの default 依存**で作っている
(現行コード L80-82・L69 のコメントが「status/scenario_version は DB default = draft/0」
「リテラル書き込みはしない」と明言)。

不変条件「複製 manual は必ず draft・version 0 から始まる」がアプリ層に固定されておらず、
将来 migration で `video_manuals.status` / `scenario_version` の default を変更すると
(例: 別機能都合で default を変える) **複製の初期状態が silent に壊れる**リスクがある
(監査 low)。`create()` も同じく default 依存だが、本件は複製の不変条件に絞る
(brief スコープ)。

## 改善アイデア

`duplicate()` で複製 manual の `status` を `VideoManualStatus::Draft`、
`scenario_version` を `0` に**明示代入**し、不変条件をアプリ層で固定する。
DB default はフォールバックとして残るが、複製の初期状態は default に依存しなくなる。

## 期待効果

- 使命への貢献: マニュアル複製 (別名保存) が常に「未撮影・未解析の draft」から始まる
  不変条件をアプリ層で保証し、将来のスキーマ変更による silent break を防ぐ
  (「思考ゼロ」で複製したユーザーが予期せぬ状態のマニュアルを掴まされない)。
- 監査で指摘された default 依存の脆さを解消 (defense-in-depth)。

## 実装方針（概要）

- `VideoManualService::duplicate()`: 新 manual を作る箇所で `created_by` と同じ
  `forceFill` に `status` (= `VideoManualStatus::Draft`) と `scenario_version` (= 0) を追加代入する。
  - `status` / `scenario_version` は fillable ではない (protected) ため forceFill 経由
    (既存 `created_by` と同じ流儀。保護キーは forceFill / relation で明示代入という実装規約に整合)。
  - メソッド docblock (L69 付近) の「scenario_version / status のリテラル書き込みはしない
    (新規行は DB default 依存)」は実態と逆になるため、「INSERT 時に明示代入して不変条件を
    アプリ層で固定する」旨へ更新する。
- `tests/Architecture/ScenarioWritePathInventoryTest.php`: `STATUS_WRITE_ALLOWED` に
  `Services/Manual/VideoManualService.php` を追加、docblock の inventory テーブル (duplicate 行)
  を「新 manual の INSERT 時に status/scenario_version を明示代入 (新規行生成 + 同一 tx 内反映)」へ更新。
  `SCENARIO_VERSION_ALLOWED` の VideoManualService.php コメントに read/write 両理由を追記
  (監査性維持)。
- **回帰テスト (業務不変条件)**: `tests/Feature/Projects/ManualDuplicateTest.php` に、元 manual が
  status != draft・scenario_version > 0 でも複製先が必ず Draft/0 になること、かつ元 manual の
  status/scenario_version が不変であることを明示検証するテストを追加する
  (静的 inventory は書き込み経路の存在のみを守り、複製結果の値は守らないため)。
- **transaction 境界**: `duplicate()` は複製 manual の INSERT と cuts 複製を単一
  `DB::transaction` 内で完結させる (現行実装どおり。後続変更で境界が崩れないよう設計前提として明記)。
- 既存の takes 非複製 / cuts 複製 / IDOR / 認可 規約は不変 (触らない)。
- 純 backend。route / DTO / FormRequest / 型定義の変更なし。

## 制約・前提

- **シナリオ整合の共有ロック規約 + Architecture テスト inventory の更新が必須 (波及変更)**:
  `ScenarioWritePathInventoryTest` は deny-by-default の静的走査で
  「`video_manuals.status` を `VideoManualStatus::...` で書く経路」(検出 2) を allowlist
  (`STATUS_WRITE_ALLOWED`) 外なら fail させる。現状 `VideoManualService.php` は allowlist 外。
  → `duplicate()` が `'status' => VideoManualStatus::Draft` を書くようになると検出 2 が
  fire するため、**`STATUS_WRITE_ALLOWED` に `Services/Manual/VideoManualService.php` を追加**
  し、inventory の docblock テーブル (duplicate 行) を「新 manual の INSERT 時に status/
  scenario_version を明示代入 (新規行=排他生成)」へ更新する。このテスト自身の既存コメント
  (L21-22) が「将来 duplicate が status を書くよう変わったら STATUS_WRITE_ALLOWED への追加が
  必要」と明示しており、**正規の inventory メンテナンス**である (テストの無効化ではない)。
  - `scenario_version` (検出 1): `VideoManualService.php` は既に `SCENARIO_VERSION_ALLOWED`
    に含まれる (displayXxxJob の read 用) ため、`'scenario_version' => 0` を書いても検出 1 は
    fire しない = allowlist 変更不要。docblock の文言のみ更新する。
- ロック規約の趣旨 (既存行への並行書き込み直列化) との整合: 明示代入は新 manual の
  **最初の save() (INSERT)** 時に `created_by` と同じ forceFill で行う。INSERT する行は
  その tx が生成した新規行で他 tx から触れないため、既存 `create()` の created_by 設定と
  同型で規約に反しない。
- 禁止事項: response()->json 直書き等に該当なし (Service 内部変更のみ)。
- PHPStan L10: `VideoManualStatus::Draft` (enum) / `0` (int) を forceFill に渡す。cast 済みで型安全。

## スコープ外

- `create()` の default 依存 (brief は duplicate 限定。create は別途検討)
- migration の default 値そのものの変更
- 他の status 遷移経路 (analyze / render)

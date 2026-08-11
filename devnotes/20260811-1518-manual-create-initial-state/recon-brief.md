# 実査ブリーフ: VideoManual 生成時の初期状態を明示代入する

> **pipeline-smoke (aicue:T147) を初めて実走したときに発見した**。
> 実行ログ: `RESULT: FAIL (failure_class=unknown)` / fixture 段で
> `ErrorException: Attempt to read property "value" on null`。
> **LLM は 1 回も呼ばれず費用ゼロで落ちた** (fail-fast は正しく効いた)。

## 確認した事実 (実コード)

`app/Services/Manual/VideoManualService.php` の `create()`:

```php
$manual = $locked->manuals()->make(['title' => $title]);
$manual->forceFill(['created_by' => $userId])->save();
```

**`status` も `scenario_version` も明示代入していない**。DB のカラム既定値
(`database/migrations/2026_07_10_000100_create_video_manuals_table.php` の
`$table->string('status')->default('draft')`) に依存している。

その結果、**`create()` の戻り値のインスタンスは `status` が null** である
(INSERT に含めていないので Eloquent がその属性を持たない)。`refresh()` するまで null。

## 同じクラスが「それは危険だ」と明文化している (最重要)

同ファイルの `duplicate()` の docblock:

> 複製 manual は必ず status=Draft・scenario_version=0 から開始する
> (**この初期状態を INSERT 時に明示代入し、DB カラム default に依存しない**
>  **= 将来の migration default 変更による silent break を防ぐ**)

**`duplicate()` は既定値に依存しない方針を採り、その理由まで書いている。
一方 `create()` は依存している。同一クラス内で方針が割れている。**

## 何が起きうるか

1. **今起きたこと**: 呼び出し側が `create()` の戻り値から `status` を読むと null。
   pipeline-smoke がこれを踏んだ (`$manual->status->value`)。
2. **将来起きうること**: migration の default を変えると、`create()` の挙動だけが黙って変わる。
   `duplicate()` の docblock が警告しているのはまさにこれ。
3. `scenario_version` も同様に既定値依存である (実装を読んで確認すること)。

## 設計で決めるべきこと

1. **`create()` を `duplicate()` に揃えるのか、呼び出し側で `refresh()` するのか**。
   **揃えるのが筋**に見える (クラス自身の明文化された方針に従う / 呼び出し側全員に refresh を強制しない)
   が、設計者が根拠を示して決めること。
2. **`ScenarioWritePathInventoryTest` への登録が要る**。AGENTS.md ドメイン規約 1 により
   `video_manuals.status` / `scenario_version` を書く経路は inventory 登録が必須である。
   `duplicate()` は既に `STATUS_WRITE_ALLOWED` / `SCENARIO_VERSION_ALLOWED` に登録済み。
   **`create()` を明示代入に変えると同じ登録が要る**ので、gate が正しく赤くなることを確認してから通すこと。
   なお `create()` は新規行の生成であり既存行への並行書き込みではない (duplicate() と同じ論拠が使える)。
3. **他に既定値依存が無いか**。同種の「INSERT に含めず DB default に頼る」箇所が他にもあるなら、
   本件と同じ問題を抱える。**ただし今必要なものだけ作る** (思考原則 2) ので、
   横断的に潰すか本件だけ直すかは設計者が判断する。機械で守るなら Architecture テスト。
4. **pipeline-smoke 側も直すか**。`$manual->status->value` は `create()` が直れば null にならないが、
   **防御的に refresh するかどうか**は別論点。二重に直すと「どちらが本当の保証か」が曖昧になるので、
   **原因側 1 箇所で直す**のが望ましい。

## 読むべき現行コード

- `app/Services/Manual/VideoManualService.php` (`create()` と `duplicate()` の対比。docblock を必ず読む)
- `app/Models/VideoManual.php` (casts / $fillable / $attributes)
- `database/migrations/*_create_video_manuals_table.php` (default 定義)
- `tests/Architecture/ScenarioWritePathInventoryTest.php` (STATUS_WRITE_ALLOWED / SCENARIO_VERSION_ALLOWED)
- `app/Console/Commands/Development/PipelineSmokeCommand.php` の `runFixtureStage()` (踏んだ側)
- `app/Enums/Manual/VideoManualStatus.php`

## 再現方法 (テストファーストの起点)

**呼び出し側で `create()` の戻り値の `status` を読むテスト**を書けば赤になるはず。
実際に pipeline-smoke がそれで落ちている。**修正前に赤を確認してから直すこと**。

## やらないこと

- **migration の default を消さない** (既存行と他の INSERT 経路に影響する)。
  明示代入を足すだけで、default 自体は保険として残す。
- 品質評価や pipeline-smoke の機能追加は扱わない。

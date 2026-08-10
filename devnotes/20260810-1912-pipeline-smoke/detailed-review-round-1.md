全体判定: **CHANGES_REQUESTED**

オーナー決定の範囲、つまり「品質評価ではなく、bug-hunt レーンで実 LLM 3 段と ffmpeg まで通ることを確認する」という目的には合っています。ただし、実行導線と preflight に実装不能または誤作動しやすい穴があり、このまま実装に入るのは危険です。

## 施策 1: bug-hunt DB 名判定の SSOT

判定: **APPROVE**

[Suggestion] trait 側の例では `BughuntDatabaseGuard` の import が省略されています。実装時は `use App\Support\BughuntDatabaseGuard;` か FQCN にしてください。

設計意図は妥当です。cap と検出 regex を同期させない理由も明確です。

## 施策 2: LLM コスト集計サービス + DTO + enum

判定: **REQUEST_CHANGES**

[Warning] `since` / `until` の境界仕様が未確定です。  
修正案: 明示してください。例: `since <= created_at`、`created_at < until`。また `Y-m-d` 入力時に `until=2026-08-10` を「同日 00:00」扱いにするのか「同日末尾」扱いにするのかも固定してください。

[Warning] `date(created_at)` 軸は timezone の扱いが曖昧です。  
修正案: DB timezone 前提、または app timezone 前提を明文化してください。CLI レポートなので DB timezone 固定でもよいですが、テストで同日境界を固定すべきです。

[Suggestion] DECIMAL 合計値を `(string)` に寄せるだけだと driver によって表示スケールが揺れる可能性があります。CLI 表示側だけでも `0.000000` / `0.00` の桁を揃える方が運用レポートとして読みやすいです。

## 施策 3: `operations:llm-cost-report`

判定: **REQUEST_CHANGES**

[Warning] 日付 option の parse エラー時の終了コードが未定義です。  
修正案: `--since` / `--until` が parse 不能、または `since >= until` の場合は `self::INVALID` とし、テストに入れてください。

[Warning] `--json` の shape は書かれていますが、enum / Carbon / DTO の JSON 表現が暗黙です。  
修正案: command 側で DTO を明示配列化するか、`JsonSerializable` を実装して shape を固定してください。

## 施策 4: ダミー SOP fixture

判定: **APPROVE**

妥当です。`SopTextExtractor` の実ゲートを通す behavioral test にしている点も良いです。

## 施策 5: pipeline smoke コマンド本体

判定: **REQUEST_CHANGES**

[Critical] preflight と fixture 段で Default Project の扱いが矛盾しています。  
preflight では「Default Project の解決」を要求していますが、fixture 段では「Default Project 不在時のみ作成」とあります。前提 P9 により bug-hunt provision 直後は Project が無い可能性があるため、`--check` が常に落ちる設計になり得ます。  
修正案: preflight は `project=existing #id` または `project=will-create` を表示し、不在時は Project 作成可能性だけ確認してください。`--check` では DB 変更しない。実行時の fixture 段で初めて作成する、という分担に直してください。

[Critical] command の constructor injection で `FakeObjectStore` や重い service を解決すると、fail-secure より前に fake 参照・外部設定解決が走る可能性があります。  
修正案: `handle()` 冒頭で fail-secure 4 条件を通した後に、必要な service を `app(...)` で遅延解決してください。特に `FakeObjectStore` は `FakeStorageGate::enabled()` 確認後に解決する、と設計に明記してください。

[Warning] actor / user の解決条件が不足しています。`VideoManualService::create(..., $userId, ...)`、`AnalysisJobService::trigger(..., $actor)`、`RenderJobService::trigger(..., $actor)` に渡す actor が、対象 org に所属していることを preflight で確認すべきです。  
修正案: 対象 org の bug-hunt 用 user を解決し、無ければ preflight failure。Laratrust team context が必要な箇所があるなら明示設定してください。

[Warning] LLM 失敗分類と retry 成功時の扱いが曖昧です。`failure_reason` 行があるだけで `Llm` と分類すると、retry 後に最終成功した実行まで失敗扱いにする可能性があります。  
修正案: pipeline 全体が失敗した場合の分類にのみ `failure_reason` を使う、または `llm-evidence` は `failure_reason IS NULL` の成功行を各 prompt_template で確認する、と分けてください。

[Suggestion] sample take 動画の生成方法を設計に 1 行追加してください。例: ffmpeg で短い mp4 を 1 本生成し、size / content-type / SHA-256 base64 を `TakeUploadInput` に渡す。

## 施策 6: fake 参照 allowlist

判定: **REQUEST_CHANGES**

[Critical] allowlist 追加自体より、fake class の解決タイミングが問題です。`PipelineSmokeCommand` が `FakeObjectStore` を constructor injection すると、本番 artisan 起動時にも解決され得ます。  
修正案: 施策 5 と同じく、fake class は fail-secure 通過後に遅延解決することを設計条件にしてください。その条件付きなら allowlist 追加は許容できます。

[Warning] コメントは「同 species」だけでなく、`handle()` 冒頭の fail-secure 後にしか fake class を解決しない、という実装条件まで書くべきです。

## 施策 7: bug-hunt レーンからの起動導線

判定: **REQUEST_CHANGES**

[Critical] `--run-id` を受けるとありますが、`dev:pipeline-smoke` の signature に `--run-id` がありません。`artisan_with_mode_for_shard ... "$@"` でそのまま転送すると Symfony Console が unknown option で落ちます。  
修正案: `--run-id` は script 側で消費して artisan へ渡さない。run-id metadata 連携は本設計ではスコープ外にする。

[Critical] 既存 main の「`--real-llm` は provision / provision-all のみ」という制約と、pipeline-smoke が実 LLM を要求する設計の接続が曖昧です。  
修正案: `pipeline-smoke` はフラグに依存せず、サブコマンド内部で real LLM 用の `MODE_ENV` / `LLM_KEY_ENV` を構築する、と明記してください。`--real-llm` を要求しない方が安全です。

[Warning] `--force "$@"` の順序は概ね問題ありませんが、script 専用 option と artisan option の境界を明確にしてください。`--check` / `--json` / `--org` は artisan に渡す、`--shard` / `--run-id` は渡さない、という表があると実装ミスを減らせます。

## 施策 8: ドキュメント追記

判定: **APPROVE**

「保証しないもの」の粒度は良いです。特に実 S3、撮影 PWA 実機経路、並行実行、provider 請求額との差を誇張せずに書いている点は妥当です。

## 施策 9: テスト

判定: **REQUEST_CHANGES**

[Warning] `PipelineSmokeCommandTest` の `--check` 成功ケースが CI の ffmpeg 有無に依存する可能性があります。  
修正案: テストでは `manual.render_ffmpeg_binary` / `manual.render_ffprobe_binary` を `PHP_BINARY` など `-version` で 0 を返す実行ファイルに差し替えて、preflight の分岐だけを固定してください。

[Warning] script 側の `pipeline-smoke` テストに `--run-id` を artisan へ転送しないことを追加してください。これは施策 7 の Critical を防ぐ回帰テストになります。

[Suggestion] `LlmCostReportCommandTest` に invalid date と `since >= until` を足してください。

## まとめ

設計の方向性は妥当ですが、現状は以下 4 点がブロッカーです。

- Default Project 不在時に `--check` が落ちる矛盾
- `FakeObjectStore` 等の fake 解決が fail-secure より前に走り得る点
- `--run-id` を artisan command に転送して壊れる点
- pipeline-smoke の real LLM env 構築と既存 flag 制約の接続不足

この 4 点を直せば、目的に対して過剰に広げず実装可能な設計になります。
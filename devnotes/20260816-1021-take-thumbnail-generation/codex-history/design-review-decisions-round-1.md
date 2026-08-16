# 対応マトリクス: design-review Round 1

## [Warning] S1: `thumbnail_path` は既に `$fillable` に入っているのに「サーバ生成値なので足さない」と説明している
- 判断: **対応する (`$fillable` から `thumbnail_path` を外す side に揃える)**
- 根拠: 指摘のとおり説明と現物が矛盾していた。実コードを確認したところ、
  `app/` 配下に `thumbnail_path` を**mass assignment する経路は 1 つも無い**
  (`VideoManualService` / `CaptureTakeService` はどちらも読み取り。書き込みは本タスクの
  条件付き UPDATE が初めて)。したがって fillable に残す理由が実在しない。
  Factory は `Factory::make()` が `Model::unguarded()` の中で実体化するため影響を受けない。
  「後方互換の並走を残さない」(思考原則 3) にも合う。
- 対応内容: S1 の変更後コードへ `$fillable` から `thumbnail_path` を外す差分を追加し、
  「サーバ生成の会計値は 2 列とも fillable 外・書き込みは条件付き UPDATE だけ」と統一した。
  リスク欄に「実装時に `rg "'thumbnail_path' =>" app/` で fill 経路が無いことを再確認する」を追加。

## [Suggestion] S1: `casts()` に `'thumbnail_size_bytes' => 'integer'` を足す
- 判断: **対応する**
- 対応内容: `casts()` へ追加した。**`size_bytes` 側は触らない** (既存の比較箇所への影響を
  持ち込まないため) ことと、その非対称を意図的に残す理由もコメントに書いた。

## [Warning] S4: preflight のテスト期待が実装と矛盾している (先着敗北で PUT されるはずがない)
- 判断: **対応する (指摘どおり。テスト期待が誤り)**
- 根拠: `stillEligible()` が `thumbnail_path !== null` を見る以上、抽出中に先着されたら
  PUT は起きない。設計書のテスト期待が実装案と食い違っていた。
- 対応内容: テスト計画を 2 つに割った。
  (a) **抽出中に先着**: `upload()` が 1 回も呼ばれない / 先着の値が保たれる
  (b) **preflight 通過後・UPDATE 前に先着**: `upload()` fake のコールバックで DB を書き換え、
      PUT は行われるが UPDATE が 0 行になり、**先着の値が保たれ・オブジェクトも削除されない**

## [Warning] S4: preflight は `$fresh` を見るのに key 生成は古い `$take` の relation を使う
- 判断: **対応する (ただし preflight の戻り型は変えない)**
- 根拠: 戻り型を `Take|null` にすると `JobExecutionDedupInventoryTest` の
  `PreflightControlFlow::ReturnsBoolean` (期待戻り型 `bool`) と一致しなくなり、目録 gate が赤くなる。
  一方で指摘の本質 (「preflight と PUT の間に何も挟まない」を字義どおりにする) は
  **key 生成を preflight より前へ move する**ことで完全に満たせる。
  さらに、key が使うのは take / cut / manual / project の**識別子だけ**であり、
  これらは行の生存中に変化しない (take が別 cut へ移ることは無い) ため、
  スナップショットの新旧で値が変わらないことが構造的に保証される。
- 対応内容: `$key = $this->thumbnailKeyFor($take);` を **preflight の前**へ移し、
  「preflight と PUT の間には書き込みどころか読み取りも無い」ことをコメントで明示した
  (relation の遅延読み込みが preflight 後に走る、という副次的な問題も同時に消える)。
  key が不変の識別子だけから決まることも docblock に書いた。

## [Warning] S5: 「同一 tx 内投入」は media queue が同一 DB connection に乗る前提だが明示されていない
- 判断: **対応する**
- 根拠: 指摘のとおり前提の明示が抜けていた。`config/queue.php` の `database-media` は
  `connection => env('DB_QUEUE_CONNECTION')` / `after_commit => false` であり、
  この前提 (driver=database / キュー DB 接続 = 業務 DB / after_commit=false) は
  `QueueDispatchAtomicityGuard` が**全環境の起動時に fail-closed 検査**している
  (AGENTS.md ドメイン固有規約 11)。
- 対応内容: S5 に前提と検査主体を明記し、テスト計画へ rollback 時に jobs 行が残らないことを追加した
  (**主契約は「action 直前の transactionLevel + 1 以上」**であり、rollback テストは
  移設を検出しないという既存の但し書きも併記)。

## [Warning] S8: `has_thumbnail` の述語が endpoint の公開条件とズレる (status=ready を含まない)
- 判断: **対応する**
- 根拠: 指摘のとおり。ready でないテイクで `has_thumbnail=true` を返すと、UI が
  必ず 404 になる `<img>` を描画する (壊れた画像アイコンが出る)。
  述語は endpoint と 1 対 1 でなければならない。
- 対応内容: `'has_thumbnail' => $this->take->status === TakeStatus::Ready && $this->take->thumbnail_path !== null`
  に変更し、「props の値と endpoint の 302 条件が 1 対 1」であることを docblock とテストで固定する
  (T154 の「秘匿境界は props 側」「endpoint が 302 を返す条件と 1 対 1」と同じ作法)。

## [Warning] S10: single-flight が in-flight 中に `Promise.resolve()` を返すため完了を待てない
- 判断: **対応する**
- 根拠: 指摘のとおり。即解決すると scheduler は「再取得が終わった」と誤認し、
  古い `manual` のまま次の試行予算を消費する。
- 対応内容: in-flight の Promise を保持し、並行呼び出しには**同じ Promise を返す**形へ変更した。
  テスト計画にも「in-flight 中の呼び出しが同じ Promise を受け取る」を追加。

## [Warning] S10 / S11: 「最大 4 回・約 29 秒」は watch() のたびに予算がリセットされるため厳密には成立しない
- 判断: **対応する (保証の単位を言い直す)**
- 根拠: 指摘のとおり。集合全体で 1 本の予算を持ち、新しい録画で 0 に戻す設計なので、
  「画面全体で最大 4 回」は嘘になる。予算リセットそのものは**意図した挙動**
  (新しい録画には新しい試行予算を与える) なので、機構ではなく**表現**を直す。
- 対応内容: 保証を
  「**最後に監視へ加わったテイクを起点に最大 4 回 (~29 秒)**。撮影を続ける限り予算は
  そのたびに更新されるが、撮影を止めれば必ず 4 回で停止する」
  と言い直し、S10 のテスト計画へ「3 回発火後に新しい watch → 予算がリセットされ、
  **最後の watch から 4 回で必ず止まる**」を追加した。S11 の文言も同じ表現へ揃えた。

## [Suggestion] S2: `ContentType` が実 adapter の metadata に反映されることを薄い統合テストで確認する
- 判断: **一部対応する (できる範囲を明示し、できない範囲は保証しないと書く)**
- 根拠: `Storage::fake('s3')` はローカル disk であり、`writeStream()` の option は
  metadata として保持されない (`mimeType()` は拡張子から導出される)。
  つまり「実 S3 の metadata に反映されること」はテストレーンでは検証できない。
- 対応内容: 検証できるのは (a) fake adapter の sidecar `content_type`、
  (b) option 名が Flysystem AwsS3V3Adapter の受理オプションに含まれること (コード読解) の 2 つで、
  **実 S3 の応答ヘッダは本タスクのテストでは保証しない**と明記した。

## [Suggestion] S3 / S7 の評価
- 判断: 見送る (変更不要)

# 対応マトリクス: design-review Round 2

Codex 全体判定: **CHANGES_REQUESTED** (Critical 0 / Warning 5 / Suggestion 1)。
Round 1 の Critical 2 件 (T154 違反) は「設計上解消」と確認された。

## [Warning] 施策 1: `VideoManual::latestSucceededRender()` の docblock が事実誤認になる
- 判断: **対応する**
- 根拠: 指摘のとおり。現行 docblock は「受け取れるかの決定は呼び出し側 (ManualListItemData) が
  `output_path !== null` を足して行う」と書いており、責務を Canonical へ移す本設計では嘘になる。
  説明と実装の食い違いは、次に読む人が古い前提で実装する原因になる。
- 対応内容: 施策 1 に「Canonical 移管に伴う既存記述の更新」節を新設し、docblock を
  (1) relation は候補行だけを返す (2) 受け取れるかの決定は `CurrentRenderArtifact`
  (一覧向け入口 `fromLoadedRenderCandidate()`) (3) DTO が合成するのは published と ability だけ
  (4) parity テストは新名 — の 4 点へ全面更新すると明記した。変更箇所一覧にも
  `app/Models/VideoManual.php` を追加した。

## [Warning] 施策 1: 目録の `Canonical` 根拠文が「3 消費者」のまま
- 判断: **対応する**
- 根拠: 一覧行 props が 4 番目の消費者として加わる。目録の根拠文は「何のために succeeded 行を
  引いているか」の記録なので、消費者の数え落としはそのまま記録の誤りになる。
- 対応内容: `RenderArtifactSelectionInventory` の `Canonical` 根拠文を
  「playback / download / 詳細画面 props / 一覧行 props の 4 消費者」に更新することを
  施策 1 の変更箇所と更新節に明記した (施策 5 側には「施策 1 の担当」と交差参照だけ置き、
  二重に書かない)。

## [Warning] 施策 1: `fromLoadedRenderCandidate()` が未ロード時に黙って lazy load する
- 判断: **対応する**
- 根拠: メソッド名が「ロード済みの候補行から」と約束しているのに、実装が約束を強制しないと
  将来の呼び出しで無言の N+1 になる。名前が果たすべき役割を実装で守る。
- 対応内容: 冒頭で `Assert::true($manual->relationLoaded('latestSucceededRender'), …)` を行い、
  未ロードなら例外にする設計へ変更。併せて Unit テスト
  (`CurrentRenderArtifactLoadedCandidateTest`) を新規に計画へ追加した:
  eager load 済みなら追加クエリ 0 本 / `output_path` NULL は null / **未ロードは例外**。

## [Warning] 施策 2: `preload="metadata"` の採用理由が実際の挙動と一致しない
- 判断: **対応する (仕様判断を訂正し `preload="none"` へ変更)**
- 根拠: 指摘が正しい。`autoplay` を付けない以上、再生ボタンの押下は `metadata` でも `none` でも
  1 回必要で「二度手間を避ける」は成立しない。尺は一覧行が `duration_ms` で既に表示しており、
  先読みで増える情報も無い。ならば要求を減らし、`RenderPanel` と同じ値に揃える方がよい。
- 対応内容: `<video preload="none">` に変更。設計の該当節を「`preload` の決着: `none`」へ書き換え、
  Round 1 の説明が誤りだったことも残した (訂正の履歴を消さない)。Vitest の期待値も
  `preload="none"` に更新し、「これは実装が仕様どおりであることの固定であって、
  仕様判断の正しさの保証ではない」と注記した。

## [Warning] 施策 5: 追加 Architecture テストが PHPStan level 10 で落ちうる
  (`file_get_contents()` の `string|false`)
- 判断: **対応する**
- 根拠: 指摘のとおり。かつ同ファイルには既に読み込み helper がある
  (`RenderArtifactSelectionScanner::tokensOf(string $relative)`) ので、自前で読むこと自体が不要。
- 対応内容: 追加ケースを `RenderArtifactSelectionScanner::tokensOf('DataTransferObjects/Manual/ManualListItemData.php')`
  経由に書き換えた (既存パターン優先)。

## [Suggestion] 施策 5: テスト名「受け取り可否の規則」が検査内容より広い
- 判断: **対応する**
- 根拠: 実際に禁じているのは「成果物行の選択を DTO へ書き戻すこと」であり、
  ability / published の判定は DTO に残る。名前は検査していることだけを言うべき。
- 対応内容: テスト名を **「一覧行 DTO は成果物行の選択を Canonical へ委譲する」** に変更し、
  「ability / published の判定は DTO に残る」ことを本文に明記した。

## [Warning] 文書全体: 「サーバ側の変更は DTO 1 ファイルだけ」「Service を触らない」が古い
- 判断: **対応する**
- 対応内容: 施策一覧の注記を
  「新設しないもの: route / Controller / **新しい Service クラス** / Job / migration / config。
   サーバ側の変更は既存 Canonical Service への一覧用入口の追加と、DTO が運ぶ値の変更の 2 点」
  に修正。実装モードの判断根拠も「新規 Service を作らない (既存 Canonical へ入口を 1 つ足すだけ)」
  へ更新した。

## 施策 3 / 4 / 6 (APPROVE)
- 判断: **変更なし** (指摘は維持要求のみ。「閉じたら video が DOM から消える」Vitest ケースと
  null id の二重防御は計画どおり必須で残す)。

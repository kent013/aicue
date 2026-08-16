# 対応マトリクス: conceptual-review Round 1

## [Warning] 2. props と endpoint の認可契約が `Gate::allows('capture', $project)` 依存で脆い
- 判断: **一部対応する (方式は据え置き、機械固定を足す)**
- 根拠: endpoint と同じ ability (`preview`) を行ごとに評価する案は、`TakePolicy::preview` が
  `$take->cut?->videoManual?->project` を辿るため **行数ぶんの lazy load を生む** (本設計の
  主目的である N+1 回避と正面衝突する)。現行の `TakePolicy` は全 ability を
  `ProjectPolicy::capture` へ**委譲するだけ**のクラスであり、判定源は 1 つしかない。
  よって props 側は project 単位に 1 回だけ `capture` を評価する形を維持する。
- 対応内容: 代わりに **behavioral な同値性の pin** を足す。T154 の
  `ManualRowFinishedVideoParityTest` (一覧行 props と endpoint が同じ行を指すことの固定) と
  同じ作法で `CaptureCoverThumbnailParityTest` を新設し、**同一の利用者・同一の manual に対して
  「props の cover が非 null」⇔「その URL が 302 を返す」を HTTP で両方向確認**する。
  `preview` 側に条件が増えたらこのテストが赤くなる = 設計の前提が壊れたことが機械で分かる。
  概念設計 D4 に「同値は `ProjectPolicy::capture` が唯一の判定源であることに依存する」と明記した。

## [Warning] 3. 代表選択 relation の責務分離が曖昧
- 判断: **対応する**
- 根拠: 指摘のとおり。relation に状態判定まで持たせるとドメイン固有規約 12 (T148) の
  検出 B (`adoptedTake` と `TakeStatus::Ready` の同居) に触れる。
- 対応内容: D1 を 3 層に分けて明記した。
  (a) relation = 候補カットを表示順で 1 件選ぶだけ (条件は `thumbnail_path` 非 null のみ)、
  (b) ready 判定は `AdoptedReadyTakeCoverage::readyTakeId()` へ委譲、
  (c) relation ファイルに `TakeStatus::Ready` を書かない。
  タイブレーク (`sort_order` 同値 → `id` 昇順) はテストで固定する旨も追記。
  併せて「(a) と (b) の条件が食い違ったときは cover を出さない (安全側に倒す)」という
  degrade 規則と、その到達可能性 (現行コードでは到達不能) を明記した。

## [Warning] 4. 「進捗が視覚的に分かる」を効果として強く主張しすぎ
- 判断: **対応する**
- 根拠: 過去分・生成失敗・未生成はプレースホルダのままで、進捗表現としては穴がある。
- 対応内容: 期待効果を「識別性の向上」主・「進捗の補助」副に書き換えた。

## [Warning] 5a. 画像ロード失敗時の UI 挙動が無い
- 判断: **対応する**
- 根拠: 署名 URL は期限を持ち、PWA は画面を開いたまま放置されうる。403/404 以外に
  S3 側の失敗もある。壊れた画像アイコンを現場に出さない。
- 対応内容: D2 に「`<img>` の読み込み失敗を捕まえて同寸法のプレースホルダへ戻す」を追加。

## [Warning] 5b. ページネーション無しで lazy loading だけが上限装置
- 判断: **見送る (スコープ外を維持) + 契約だけ足す**
- 根拠: 一覧のページネーションは本タスクの目的 (欠けている 1 要素を埋める) の外で、
  変えると絞り込み・検索の挙動まで波及する。
- 対応内容: 「props には URL を載せず id だけ」を D3 の契約として明記し、テストで固定する。

## [Warning] 6. 「cross-org 404」で何を固定するのかが曖昧
- 判断: **対応する**
- 対応内容: テスト計画の軸を 3 つに分けて明記した
  (index 自体の境界 / cover の id を使った endpoint の cross-org・cross-project 404 /
  props に他 org の take id が混入しないこと)。

## [Suggestion] 7. `cover` を専用 DTO にする
- 判断: **対応する**
- 根拠: `cover` は **2 つの別の行 (cut と take) から合成する 2 つの id** であり、
  「両方 null か両方非 null」を型で表せると PHPStan level 10 で扱いやすい。
  既存 `CutTakeSummaryData` は同種の合成を配列 shape のままにした結果、`toArray()` に
  防御的な三重 null 判定を書くことになっている (同じ形を増やさない)。
- 対応内容: `CaptureManualCoverData` (readonly / cutId / takeId) を切り、
  `CaptureManualSummaryData::$cover` を `?CaptureManualCoverData` にする方針へ変更した。

## [Suggestion] 1 / 6 (使命整合・スコープ)
- 判断: 指摘なし (肯定的評価)。変更しない。

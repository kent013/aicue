# 対応マトリクス: design-review Round 1

## 施策 1

### [Warning] 画面は編集者限定だが操作 API は撮影者も叩ける（誤読を招く）

- 判断: **対応する**
- 根拠: 意図どおりの非対称だが、設計書の書き方が「PC の操作も編集者限定」と読める。
  意図的な非対称は**テストで固定してこそ**意図になる。
- 対応内容: 施策 1 に「権限境界」小節を追加し、
  「**画面 route = `update` (編集者のみ) / 操作 API = `capture` (撮影者以上)**」を表で明示。
  テスト計画に「**撮影者が `capture.takes.adopt` を叩けることを固定する**」を追加した
  (この行が消えたら非対称が事故で壊れたと分かる)。

### [Warning] `$this->cut->adoptedTake` が lazy load になる

- 判断: **対応する（ただし前提を訂正）**
- 根拠: `Model::preventLazyLoading()` は本リポジトリで**有効化されていない**
  (`AppServiceProvider` にあるのは `preventSilentlyDiscardingAttributes` のみ) ので
  「落ち得る」は当たらない。とはいえ暗黙の追加クエリであることは事実で、明示した方がよい。
- 対応内容: `fromCut()` の冒頭で `$cut->loadMissing('adoptedTake')` を呼ぶ形に変更し、
  「lazy loading 禁止は未設定なので落ちはしないが、暗黙の追加クエリを残さない」と注記した。

### [Warning] `captureJson` / `extractErrorMessage` の出所が変更ファイルに無い

- 判断: **対応する（既存 helper の明記）**
- 根拠: 既存 `resources/js/lib/capture/http.ts` に実装済み
  (`credentials: same-origin` / `X-XSRF-TOKEN` / `X-Requested-With` /
  419 で `/app/csrf-cookie` を取り直して 1 回だけ再送 / 422・409 の文言抽出)。
  新設は不要だが、設計書に書いていなかったのは不備。
- 対応内容: 施策 1 に「再利用する frontend helper」小節を追加し、import 元と挙動を明記した。

### [Warning] `app/` の新規コメントに `adopted_take_id` リテラルがある

- 判断: **対応する（事実は訂正しつつ、書き換えは受け入れる）**
- 根拠: `ScenarioWritePathInventoryTest` の走査は `token_get_all()` ベースで、
  検出対象は `T_STRING` と `T_CONSTANT_ENCAPSED_STRING` のみである。
  コメントは `T_COMMENT` / `T_DOC_COMMENT` なので**検出されない**（設計は壊れない）。
  ただし「コメントに書いた語がいつ走査対象になるか」に賭ける理由が無い。
- 対応内容: `app/` 配下の新規コメントでは「採用テイク外部キー」と言い換える方針に変更し、
  「トークン走査はコメントを見ないが、賭けない」と理由を残した。

### [Suggestion] `label = ''` の握り潰し

- 判断: **対応する**
- 対応内容: 見つからない場合は `'カット'` にフォールバックし、
  「親を持たない急所（データ異常）でのみ起きる」ことをコメントで明示。
  Feature テストで step / point 双方のラベル（`手順1` / `急所1-1`）を固定した。

## 施策 2: APPROVE

### [Suggestion] `playbackUrl === null` のとき `<video>` を出さない

- 判断: **対応する**
- 対応内容: `TakePreviewPanel` は `playbackUrl === null` のとき `<video>` を描かず、
  状態タイル + 「このテイクはまだ再生できません（{状態}）」を出す設計に変更した。

## 施策 3

### [Warning] 「新規 props は専用 DTO」と自分で書きながら `takeSummaries` は生配列

- 判断: **対応する**
- 根拠: 設計内の規約違反という指摘は正しい。例外を作るなら理由が要るが、
  ここに例外を作る理由は無い（1 リソース 3 フィールドでも DTO のコストはほぼゼロ）。
- 対応内容: `App\DataTransferObjects\Manual\CutTakeSummaryData` を新設し、
  controller の private helper は「DTO を組んで `toArray()` する」だけにした。
  `AdoptedTakeReferenceInventory` の登録先も `VideoManualController` から
  `CutTakeSummaryData` へ移した（`adoptedTake` を触るのは DTO 側になるため）。

### [Warning] 並び順が `sort_order` だけで `CutSequencer` と揃っていない

- 判断: **対応する**
- 対応内容: `->orderBy('sort_order')->orderBy('id')` に修正した。

### [Warning] `videoCell` の `label` 引数が未使用

- 判断: **対応する**
- 対応内容: 引数を `cutId` だけにし、リンクの `ariaLabel` は snippet 呼び出し側が
  持っている見出し文言を使わず、リンク文言そのもので足りるようにした。

### [Suggestion] Card の中にさらに角丸ボーダーを作るのは避ける

- 判断: **対応する**
- 対応内容: `rounded-md border p-3` をやめ、`border-t border-border pt-3` の
  区切り線 + inline アクションに変更した。

## 施策 4

### [Critical] 「1 分まで」がクライアント判定だけで業務ルールとして強制できない

- 判断: **対応する（保証しない側へ倒す）**
- 根拠: 指摘のとおり。`duration_ms` はクライアント申告で改竄可能、
  metadata が読めない形式では判定自体が働かない。**サーバ側 ffprobe 検査を今回入れるのは
  スコープ超過**（エンコード段が別タスクで来る）。
- 対応内容:
  - UI 文言を断定形（「登録できません」）から事実の提示
    （「動画の長さが 1 分を超えています。1 分以内に切り出してからアップロードしてください。」）へ変更。
  - 設計・テスト名を「**事前チェック（保証ではない）**」に統一し、
    「サーバは尺を強制しない。真の尺による拒否はエンコード段の別タスク」と明記。
  - 「1 分を超える動画は登録できない」とは書かない、を設計の明示条項にした。

### [Warning] memory store に `queued` の Blob が残る

- 判断: **対応する**
- 対応内容: store のインスタンスをコンポーネント側で保持し、
  `outcome.status === "queued"` のときに `store.delete(outcome.clientTakeId)` を呼ぶ
  （PC は保持しない方針を実装で守る）。設計に明記した。

### [Warning] `readDurationMs()` の詳細が未定義

- 判断: **対応する**
- 対応内容: `loadedmetadata` / `error` / timeout(3s) の 3 経路と、
  `URL.revokeObjectURL()` を必ず呼ぶ後始末を含む実装を設計書に全文で書いた。
  読めない場合は `null` を返し**事前チェックを行わない**（詰ませない）。

### [Suggestion] 動画以外を選んだときも `input.value = ""`

- 判断: **対応する**
- 対応内容: 全経路の後始末を `finally` 相当に集約し、同じファイルの再選択で
  `change` が発火しない問題を避ける形にした。

# 対応マトリクス: conceptual-review Round 1

## [Critical] 既存 `capture.takes.*` の route parameter / capture session の整合が不足

- 判断: **反論する (前提が事実と異なる) + 設計へ明示追記する**
- 根拠: 本リポジトリに **capture session という概念は存在しない**。`routes/web.php` L604-621 の
  `capture.takes.*` は 7 本すべてが `/app/projects/{project}/manuals/{manual}/cuts/{cut}/takes[/{take}]`
  の形で、**project / manual / cut / take だけ**を parameter に取る。認証はセッション (web guard)、
  テナント境界は `project.in-current-org` middleware + `scopeBindings` である。
  PC 画面は同じ project / manual / cut を持っているので、追加の解決処理は 1 つも要らない。
- 対応内容: D2 に**既存 route の実シグネチャ表**を貼り、「capture session は存在しない /
  PC が追加で解決するものは無い」ことを明記した。あわせて Codex の懸念どおり
  **URL 組み立て規則が 2 箇所に散る**問題は実在するので、
  `lib/capture/take-endpoints.ts` を唯一の導出元として切り出し、既存 `TakeStrip` も
  そこへ寄せる (新規複製を作らない) ことを施策に追加した。

## [Critical] `/app/*` API を PC から使う場合のセキュリティ UX と Feature テスト

- 判断: **対応する**
- 根拠: 「暗黙の前提にしない」は本リポジトリの規約そのものであり、指摘は正しい。
  no-store / bfcache / Inertia 履歴暗号化 (ドメイン固有規約 3) は**Inertia が描画する
  認証済み画面すべて**に既に効いており PC 面も対象だが、テイク API を PC から叩けることは
  新しい事実なのでテストで固定すべきである。
- 対応内容: 「必須成果物 (Feature テスト)」節を新設し、Codex が挙げた 3 本を含む
  テスト一覧 (cross-org / cross-project / cross-manual / cross-cut 404、保護キー 422、
  権限境界、rendering/analyzing 409、not-ready 422、DL 済み削除 422) を概念設計に固定した。

## [Warning] 「採否の判断が現場から戻る」という効果表現が言い過ぎ

- 判断: **対応する**
- 根拠: `TakePolicy` は撮影者にも adopt を開いたままであり、権限は変えない。
  「戻る」と書くと実装しない権限分離を約束することになる。
- 対応内容: 期待効果を「PC 編集者が**同じ判断を PC 上でも行えるようになる**」に修正し、
  権限分離は今回やらないことをスコープ外に明記した。

## [Warning] doc/10 側の記述との衝突 (PC 専用 BFF route 案との比較を明記せよ)

- 判断: **対応する**
- 根拠: 契約の変更であって単なるコメント変更ではない、という指摘は正しい。
- 対応内容: D2 に**案 A (既存再利用) / 案 B (PC 専用 route から同一 Service を呼ぶ)** の
  比較表を追加し、更新対象ドキュメントに `doc/10` を追加した。

## [Warning] UploadQueue 再利用時の PendingStore contract / 完了後処理 / 失敗表示が未設計

- 判断: **対応する**
- 対応内容: D4 にメモリ `PendingStore` の contract、完了後の `router.reload({only:[...]})`、
  失敗時の表示 (422 quota / 409 in-flight / ネットワーク断) と
  **予約の後始末は既存 sweeper が担う**ことを追記した。

## [Warning] 「レンダ 422 をその場で解消」の効果に条件が要る

- 判断: **対応する**
- 対応内容: 「ready なテイクが存在する場合に限り」と条件付けし、
  `uploading` / `processing` / `failed` / 409 の UI 状態を D7・必須成果物に明記した。

## [Warning] `takeSummaries` の N+1 / props 肥大

- 判断: **対応する**
- 対応内容: 施策 3 に「`withCount` + 採用テイクの eager load による 1 クエリ集約」
  「1 cut あたり 4 フィールドに限定」を明記した。

## [Warning] スコープ: アップロードが P2 だと「PC で業務完了」が成立しない

- 判断: **対応する**
- 根拠: 指摘のとおり。PC ローカル動画取り込みは doc/04 のテイク選択画面の要件本体であり、
  これを外すと「PC のみの利用者が業務を完了できる」という効果が嘘になる。
- 対応内容: 4 施策すべてを P1 (= 完了条件) とし、優先度は**実装順序**として表現し直した。

## [Warning] ページ props 全体の型境界が曖昧

- 判断: **対応する**
- 対応内容: `App\DataTransferObjects\Manual\TakeSelectionPageData` を置き、
  `toArray()` の array shape を phpdoc で固定する方針を D2 / 施策 1 に追記した。
  内部で既存 `CaptureCutData` を合成する (shape の二重管理を作らない)。

## [Suggestion] `thumbnail_url` を先回りで足さない判断は妥当

- 判断: **維持** (変更なし)

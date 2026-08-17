# TODO リスト

<!--
運用規約 (app-todo-add / app-todo-close スキルが操作する):
- このファイルには Open / Conditional のみを置く。完了・廃止した TODO は
  TODO-closed.md (Closed / Obsoleted) へ移動する (/app-todo-close の責務)
- ID 採番: T001 から連番。Open / Conditional / Closed / Obsoleted 全体を通した
  既存最大 ID + 1 を採番する (/app-todo-add の責務)
- 追加には devnotes/{design_dir}/ に conceptual-design.md と detailed-design.md の
  両方が存在することが必須 (なければ Reject)
- テーマ: frontend / backend / infrastructure / test / docs / general
- 優先度: Critical / High / Medium / Low
- モード: incremental (他施策と並行可) / standalone (個別実装セッション)
- 設計列: [設計](devnotes/{design_dir}/) 形式のディレクトリリンク
- Conditional はトリガー条件を満たしたら Open へ昇格させてから着手する
  (Conditional の直接クローズ不可。obsolete は可)
- テーブルが空になってもヘッダー行は残す
-->

## Open

| ID | タイトル | テーマ | 概要 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|
| T085 | bfcache 実復元の iOS 実機受入確認 | test | Playwright 不可のため実機で確認+記録。**T178 (同期判定の前置) のマージ後に実施する** — guard 本体・プローブ応答契約・秘匿状態の語彙が変わり、`docs/supported-browsers.md` の実機受入確認の再確認条件に当たるため。失効セッション経路の観測は「秘匿を維持したまま読み直す」に変わるが、合格終端 (`unauthenticated-redirected` = /login 到達の目視確認込み) は変わらない | High | standalone | [設計](devnotes/20260803-0053-aigenba-alignment/) | 2026-08-03 03:10 |
| T110 | 認証手段変更のメール通知ポリシーの統一設計 | backend | passkey/2FA/SSO の増減通知を一貫したポリシーとして設計する(T108 S7 は監査ログのみで通知は見送り) | Low | standalone | [設計](devnotes/20260805-1550-security-audit-remediation/) | 2026-08-05 17:45 |
| T187 | D&D 並べ替えの iOS 実機受入確認 | test | jsdom / Browser lane では代替できないため実機で確認+記録。**T185 は実機確認を待たずにマージ済み**(ユーザーの明示判断)であり、本 TODO がその未達分を引き継ぐ。記録先は `devnotes/20260816-1021-drag-and-drop-reordering/ios-acceptance.md` の 7 項目チェックリストと状態表 | High | standalone | [設計](devnotes/20260816-1021-drag-and-drop-reordering/) | 2026-08-16 17:45 |
| T194 | ホバー自動再生の実ブラウザ受入確認 | test | 実ブラウザでホバー再生とD&Dを確認。jsdom は `HTMLMediaElement.play()` を実装しておらずテストでは差し替えているため、**実際に映像が再生されること・自動再生ポリシーの実挙動・滞留 200ms の体感**は自動レーンの守備範囲の外にある。**T190 は実ブラウザ確認を待たずにマージ済み**であり、本 TODO がその未達分を引き継ぐ。記録先は `devnotes/20260816-1954-todo-T190/manual-browser-acceptance.md` の 9 項目チェックリストと状態表 (マウスの使えるデスクトップ Chrome / Safari で実施し、採用済みかつ ready かつサムネイル生成済みのテイクを持つカットが要る) | High | standalone | [設計](devnotes/20260816-1757-take-thumbnail-hover-preview/) | 2026-08-16 20:19 |
| T195 | 撮影 PWA 通し再生の iOS 実機受入確認 | test | 実機で通し再生を確認+記録。jsdom は `HTMLMediaElement.play()` / `pause()` / `load()` を実装しておらずテストでは差し替えているため、**実際に映像が連続再生されること・iOS Safari の自動再生ポリシーの実挙動・全画面へ切り替わらないこと (playsinline)・先読みが 1 クリップ 1 回取得になっていること・別アプリへ切り替えたときに裏で再生され続けないこと**は自動レーンの守備範囲の外にある。**T191 は実機確認を待たずにマージ済み**であり、本 TODO がその未達分を引き継ぐ。記録先は `devnotes/20260816-1754-capture-full-scenario-preview/device-acceptance.md` の 11 項目チェックリストと状態表 (iOS Safari 実機で実施し、採用済みかつ ready のテイクを持つカット 2 枚以上 + 使用できる採用テイクが無いカット 1 枚のマニュアルが要る) | High | standalone | [設計](devnotes/20260816-1754-capture-full-scenario-preview/) | 2026-08-16 21:26 |
| T196 | 画像ファイル選択経路の EXIF 向き受入確認 | test | Browser lane (Chromium + WebKit の 2 レーン契約) で向き情報付き JPEG の fixture を選び、再エンコード後の縦横が表示どおりになることを確認+記録。概念設計は「`<img>` デコード時にブラウザが必ず EXIF 向きを適用する」ことを**断定しない**と定めており、jsdom の component テストは canvas を差し替えているため実デコーダの向き適用を 1 度も通っていない。**T192 は本確認を待たずにマージ済み**であり、本 TODO がその未達分を引き継ぐ。記録先は `devnotes/20260816-1758-still-image-cut-capture/browser-orientation-acceptance.md` の手順と結果表 (状態: 未実施)。対象は**ファイル選択経路の 2 つ** — 撮影 PWA の `CaptureFileFallback` と PC の `TakeFileUpload`。シャッター経路 (`CameraRecorder` の `shootStill`) はライブ映像のフレームで EXIF が無いため対象外 | High | standalone | [設計](devnotes/20260816-1758-still-image-cut-capture/) | 2026-08-16 23:18 |
| T204 | 一覧検索欄 placeholder の狭幅実機表示確認 | test | 撮影 PWA の狭幅で検索欄の文言が読めるか実機で確認+記録。T202 が検索欄の placeholder を `タイトルで検索` → `タイトル・本文で検索` (3 文字長い) へ変えたが、**狭幅で途中省略されないか**は自動レーンの守備範囲の外にある — jsdom の JS テストは `placeholder` 属性の**文字列**を共有定数と比較するだけで実描画幅を見ず、Browser lane も表示の切れ方を判定する契約を持たない。**T202 は本確認を待たずにマージ済み**であり、本 TODO がその未達分を引き継ぐ。記録先は `devnotes/20260817-1027-todo-T202/manual-verification.md` の 2 項目 (撮影 PWA `/app/projects/{id}/manuals` を iOS Safari 狭幅 = iPhone SE 幅 375px 相当で / PC 一覧 `/projects/{id}` のキーワード欄で) と状態表 (状態: 未実施)。切れていた場合の是正は `resources/js/lib/manual/search.ts` の**定数 1 か所**の変更で済む (両画面が同じ定数を読むため片側だけ直る事故は起きない) | High | standalone | [設計](devnotes/20260817-0909-manual-search-scope/) | 2026-08-17 11:07 |

完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
| T109 | MCP の idempotency replay をリソース解決より後へ | backend | AppMcpTool::handle() の replay 判定が runTool() より前。REST 側で api.project-in-org < idempotent として閉じたのと同型のハザードが構造的に残る(現時点で write tool 0 本のため実害なし。**起票条件は T139 の trip-wire `McpWriteToolIdempotencyEnforcementTest` が機械化済み** = 最初の write tool 追加で赤くなり必要作業が失敗メッセージで提示される) | MCP に write tool を 1 本でも追加するとき | Medium | incremental | [設計](devnotes/20260805-1550-security-audit-remediation/) | 2026-08-05 17:45 |
| T127 | 既定キュー接続の分割 (課金系を別接続へ) | infrastructure | 短命ジョブと Stripe 課金ジョブで retry_after を分ける。T122 で database の retry_after を 90→600 にした代償として、短命ジョブの回収が最大 510 秒遅れる | その回収遅延が実害として観測されたとき(滞留の苦情・監視アラート) | Medium | standalone | [設計](devnotes/20260806-1635-queue-lease-timeout/) | 2026-08-06 18:40 |
| T128 | CI に workflow_dispatch を追加 | infrastructure | T123 で on.schedule を除去した結果、供給網監査を手動で叩く口が無い。追加する場合は W12 のトリガー集合への登録が gate により必須 | CI 外の定期実行枠組み(オーナー側の宿題)が決まったとき | Low | incremental | [設計](devnotes/20260806-1634-ci-schedule-removal/) | 2026-08-06 18:40 |
| T193 | 動画マニュアルの公開範囲 | backend | 公開範囲の要件を評価し方針を確定する。**今回は見送りのため実行しない**。モード standalone は将来 T-1 が満たされて着手する場合の推奨で、Policy・一覧クエリ・撮影 PWA・ダッシュボード・通知・前提テストの書き換えが 1 つの意味単位で動くため | 【T-1 (主条件)】記録条件 4 つ (1. 対象と要求元 / 2. 見せない相手 / 3. 隠す深さの 3 択 / 4. 受け渡し時点での可視性遷移の有無) がすべて書かれ、かつ適格条件 A〜E がすべて「はい」の要求が 1 件でも来たとき Open へ昇格する。A: 許可主体が作成者本人だけである (担当者数名なら閲覧者リスト、特定ロールならロール認可の要求で対象外)。B: 「内容を取得できない」または「存在を知られない」ことが要る (一覧から消えれば足りるなら絞り込みの要求で対象外)。C: 完成後を含む終端状態まで作成者限定が維持される (受け渡しで解除されるなら状態/承認 workflow の要求で対象外)。D: Project 境界・workflow/状態・ロール認可・閲覧者リストのいずれでも代替できない。E: A〜D を設計責任者が確認し、D の判断理由 (どの代替軸をなぜ退けたか) を 1 段落で記録した。【T-2 (再評価の開始条件。自動昇格ではない)】ProjectPolicy::view が「project メンバーのみ」へ狭められ Project が読み取り境界として機能するようになったとき、本設計を読み直す (不要になる可能性の方が高い)。【T-3】doc/02 §2.4 のデータモデルが受け入れ検査の対象として顧客と合意され、カラムの存在自体が契約になったとき。【昇格条件ではないもの】組織外への共有 (公開リンク・取引先閲覧) は公開範囲ではなく別概念であり、別タスクとして起票する。 | Medium | standalone | [設計](devnotes/20260816-1754-video-manual-visibility-scope/) | 2026-08-16 18:35 |
| T201 | ユーザー登録方式の要件差の評価 | backend | ID発行方式と招待制の差を評価し確定する。**今回は見送りのため実行しない**。モード standalone は将来 T-1 が満たされて着手する場合の推奨で、認証入口・招待経路・回復方式・前提テストの書き換えが 1 つの意味単位で動くため | 【T-1 (主条件)】記録条件 5 つがすべて書かれ、かつ適格条件 A / B / C がすべて「はい」の要求が 1 件でも来たとき。記録があるだけでは昇格しない。記録条件: (1) 対象組織・人数・役割と要求元 (2) メールボックスを用意できない理由 (3) 共有メールボックス・サブアドレスでも不可である理由 (4) 許す操作の範囲 (5) なりすまし許容度。適格条件 A: 要求の実体が「認証の入口」であること (「呼称が欲しい」は表示専用識別子へ、「誰がいつ入ったか」は最終ログイン表示へ)。適格条件 B: 案 A (共有メールボックスでの代行オンボーディング + パスキー) が実地で不成立 = 概念設計 §3-4 の 6 条件のうち 1 つ以上が崩れている事実が記録されていること。「作業者が個人のメールアドレスを持たないだけ」は不成立にしない。適格条件 C: 本人の自力パスワード回復が現行の Fortify email broker では成立しないことを承知した上で、代替回復方式 (権限集中を明示的に受容した管理者リセットを含む) を設計対象に含めることが合意されていること。不昇格になるのは「回復方式が未決定」のときだけ。【T-2 (再評価の開始条件。自動昇格ではない)】verified を業務 group から外す / メール検証の免除経路を作る判断が別要件で入ったとき (第 1 段のコストが大きく下がるため本設計を読み直す)。【T-3】doc/04 §4.2 の入力制約が受入検査の契約要件として顧客と合意されたとき。そのときは表示専用の識別子列で足りるかを最初に問う。パスワードの「半角英数 8〜16 字」は契約要件であっても採らない (PasswordPolicy = 12 字以上 + 大小混在 + 数字 + HIBP からの後退になるため、要件側の訂正として合意する)。【昇格条件ではないもの】「ID の方が現場に馴染む」という選好のみ。 | Medium | standalone | [設計](devnotes/20260817-0003-user-provisioning-model-divergence/) | 2026-08-17 00:33 |

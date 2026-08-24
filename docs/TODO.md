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
| T260 | 生の環境変数 3 面の退避・復元を部品へ集約し直接書き込み gate を新設 (正典 v1 追従) | test | getenv / $_ENV / $_SERVER の 3 面の退避・注入・復元を `Tests\Support\RawEnv\RawEnvSnapshot` へ集約し、既存 3 実装 (ProductionEnvGuardTest の関数群 / evaluateConfigFileWithEnv / evaluateFortifyConfigWithEnv) を同一コミットで削除。契約テスト (読み出し順・存在の保存・3 相・キー拒否・env 読み出し口の作り直し) と、部品の外の直接書き込みを止める deny-by-default gate を新設し、TestDatabaseSchemaUpdateTest を純関数入力へ書き換える。**5 施策は分割不可** (順序は詳細設計の実装手順 11 段が正本。段 7 で gate を先に赤くし違反 4 ファイルを目視)。乖離登録の D 番号と件数 pin (DIVERGENCE_ENTRY_COUNT / 宣言行 / 対象パス数) は T255・T256・T258 と同じ台帳を触るため**実装時に main の最新から再確定**。T249 とは同時進行しない | Medium | standalone | [設計](devnotes/20260824-1633-raw-env-snapshot-restore-v1/) | 2026-08-24 18:11 |
| T261 | PHP 列挙 ⇔ TS 値域の逆走査を正典 v3 へ追従させる (母集団全数・.svelte・4 形・規則 2 の論理和) | test | enum-ts-sync gate の逆走査を正典 v3 (i1-i16) へ追従。母集団を版管理下の *.ts / *.svelte 全数へ広げ .svelte を仮想 TS として第一級の解析対象にし、候補を 4 形 (リテラル型の合併 / 定数の配列 / 対応表のキー / 分岐のラベル) へ、規則 2 を「厳密名対応 + 1 値交差」と「語分割名対応 + 両側半分以上の交差」の論理和へ拡張。program はパッケージごとに自前 tsconfig で組み、目録へ equal / subset の関係欄と locator を導入。設計時に検出済みの実ドリフト 2 件 (CLI の API エラー符号 / OAuth スコープ) も同じ変更で是正する。**順序制約が強い** (段 6 の赤は段 9 まで解けない)。件数 pin (関係 31 / PHP 対象外 93 / 申告 8 / 保留 3 / 除外根 1 / LedgerPins 47・147) は着手時と main 投入直前に現物から数え直す。乖離台帳 D 番号・adoption-debt.tsv の手当ては不可分。設計は 20260824-1633 版が唯一の完成版 (旧 20260824-1012 版は使わない) | High | standalone | [設計](devnotes/20260824-1633-enum-ts-sync-gate-v3/) | 2026-08-24 18:11 |
| T262 | デザイントークン体系を正典 v1 へ追従 (半透明合成 AA / 逆向き被覆 / 参照の閉包 / 文書⇔実装一致) | frontend | 正典 v1 の不変条件 22 本のうち aicue に欠けている条件を 12 施策で充足。半透明背景 × 不透明文字の合成検査 (i16) 新設で実測 5 組が AA 未達になるため、DESIGN.md / tokens.css のブランド色・状態色 (primary 600→700 / hover 700→800 / tertiary 700→800 / success・warning 700→800。danger 据え置き) を同一コミットで是正。逆向き被覆 (i15) / 参照の閉包 (i9) / DESIGN.md §Components ⇔ 部品ファイルの双方向一致 (i10) / @theme 一意性 / 線形化しきい値 0.04045 / 字下げコード拒否の gate も新設。**実装順 S1→S2→S4→S10→S5→S6→S3→S7→S9→S8→S11→S12 は分割不可**。乖離登録 D50 / D51 と件数 pin (DIVERGENCE_ENTRY_COUNT 46→48 / ADOPTION_DEBT_COUNT 148→146 / 宣言行) は他 TODO と衝突するため**実装時に main の最新から再確定**。primary 変更に伴う目視確認 6 面が必須 | High | standalone | [設計](devnotes/20260824-1019-design-token-system-v1/) | 2026-08-24 18:11 |
完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
| T109 | MCP の idempotency replay をリソース解決より後へ | backend | AppMcpTool::handle() の replay 判定が runTool() より前。REST 側で api.project-in-org < idempotent として閉じたのと同型のハザードが構造的に残る(現時点で write tool 0 本のため実害なし。**起票条件は T139 の trip-wire `McpWriteToolIdempotencyEnforcementTest` が機械化済み** = 最初の write tool 追加で赤くなり必要作業が失敗メッセージで提示される) | MCP に write tool を 1 本でも追加するとき | Medium | incremental | [設計](devnotes/20260805-1550-security-audit-remediation/) | 2026-08-05 17:45 |
| T127 | 既定キュー接続の分割 (課金系を別接続へ) | infrastructure | 短命ジョブと Stripe 課金ジョブで retry_after を分ける。T122 で database の retry_after を 90→600 にした代償として、短命ジョブの回収が最大 510 秒遅れる | その回収遅延が実害として観測されたとき(滞留の苦情・監視アラート) | Medium | standalone | [設計](devnotes/20260806-1635-queue-lease-timeout/) | 2026-08-06 18:40 |
| T128 | CI に workflow_dispatch を追加 | infrastructure | T123 で on.schedule を除去した結果、供給網監査を手動で叩く口が無い。追加する場合は W12 のトリガー集合への登録が gate により必須 | CI 外の定期実行枠組み(オーナー側の宿題)が決まったとき | Low | incremental | [設計](devnotes/20260806-1634-ci-schedule-removal/) | 2026-08-06 18:40 |
| T193 | 動画マニュアルの公開範囲 | backend | 公開範囲の要件を評価し方針を確定する。**今回は見送りのため実行しない**。モード standalone は将来 T-1 が満たされて着手する場合の推奨で、Policy・一覧クエリ・撮影 PWA・ダッシュボード・通知・前提テストの書き換えが 1 つの意味単位で動くため | 【T-1 (主条件)】記録条件 4 つ (1. 対象と要求元 / 2. 見せない相手 / 3. 隠す深さの 3 択 / 4. 受け渡し時点での可視性遷移の有無) がすべて書かれ、かつ適格条件 A〜E がすべて「はい」の要求が 1 件でも来たとき Open へ昇格する。A: 許可主体が作成者本人だけである (担当者数名なら閲覧者リスト、特定ロールならロール認可の要求で対象外)。B: 「内容を取得できない」または「存在を知られない」ことが要る (一覧から消えれば足りるなら絞り込みの要求で対象外)。C: 完成後を含む終端状態まで作成者限定が維持される (受け渡しで解除されるなら状態/承認 workflow の要求で対象外)。D: Project 境界・workflow/状態・ロール認可・閲覧者リストのいずれでも代替できない。E: A〜D を設計責任者が確認し、D の判断理由 (どの代替軸をなぜ退けたか) を 1 段落で記録した。【T-2 (再評価の開始条件。自動昇格ではない)】ProjectPolicy::view が「project メンバーのみ」へ狭められ Project が読み取り境界として機能するようになったとき、本設計を読み直す (不要になる可能性の方が高い)。【T-3】doc/02 §2.4 のデータモデルが受け入れ検査の対象として顧客と合意され、カラムの存在自体が契約になったとき。【昇格条件ではないもの】組織外への共有 (公開リンク・取引先閲覧) は公開範囲ではなく別概念であり、別タスクとして起票する。 | Medium | standalone | [設計](devnotes/20260816-1754-video-manual-visibility-scope/) | 2026-08-16 18:35 |
| T201 | ユーザー登録方式の要件差の評価 | backend | ID発行方式と招待制の差を評価し確定する。**今回は見送りのため実行しない**。モード standalone は将来 T-1 が満たされて着手する場合の推奨で、認証入口・招待経路・回復方式・前提テストの書き換えが 1 つの意味単位で動くため | 【T-1 (主条件)】記録条件 5 つがすべて書かれ、かつ適格条件 A / B / C がすべて「はい」の要求が 1 件でも来たとき。記録があるだけでは昇格しない。記録条件: (1) 対象組織・人数・役割と要求元 (2) メールボックスを用意できない理由 (3) 共有メールボックス・サブアドレスでも不可である理由 (4) 許す操作の範囲 (5) なりすまし許容度。適格条件 A: 要求の実体が「認証の入口」であること (「呼称が欲しい」は表示専用識別子へ、「誰がいつ入ったか」は最終ログイン表示へ)。適格条件 B: 案 A (共有メールボックスでの代行オンボーディング + パスキー) が実地で不成立 = 概念設計 §3-4 の 6 条件のうち 1 つ以上が崩れている事実が記録されていること。「作業者が個人のメールアドレスを持たないだけ」は不成立にしない。適格条件 C: 本人の自力パスワード回復が現行の Fortify email broker では成立しないことを承知した上で、代替回復方式 (権限集中を明示的に受容した管理者リセットを含む) を設計対象に含めることが合意されていること。不昇格になるのは「回復方式が未決定」のときだけ。【T-2 (再評価の開始条件。自動昇格ではない)】verified を業務 group から外す / メール検証の免除経路を作る判断が別要件で入ったとき (第 1 段のコストが大きく下がるため本設計を読み直す)。【T-3】doc/04 §4.2 の入力制約が受入検査の契約要件として顧客と合意されたとき。そのときは表示専用の識別子列で足りるかを最初に問う。パスワードの「半角英数 8〜16 字」は契約要件であっても採らない (PasswordPolicy = 12 字以上 + 大小混在 + 数字 + HIBP からの後退になるため、要件側の訂正として合意する)。【昇格条件ではないもの】「ID の方が現場に馴染む」という選好のみ。 | Medium | standalone | [設計](devnotes/20260817-0003-user-provisioning-model-divergence/) | 2026-08-17 00:33 |
| T205 | 作成者を選ぶフィルタの追加 | backend | 作成者を select で選ぶ絞り込みを足す。**T202 で「作成者名の部分一致検索は作らない」と裁定した代替の正攻法**。users.name は CipherSweet + blind index で完全一致しかできず部分一致は原理的に成立しないため、id で選ばせる形なら暗号化を一切弱めずに実現できる。現時点は既存の「自分の作成分のみ」で足りており「あったら便利」の段階 | 1 project の manual 作成者が 3 人を超え、かつ「自分の作成分のみ」では絞れないという要望が出たとき | Low | incremental | [設計](devnotes/20260817-0909-manual-search-scope/) | 2026-08-17 11:20 |
| T206 | 検索索引の強化 (pg_trgm / 全文検索) | infrastructure | T202 のカット本文検索は `%語%` の LIKE で、B-tree 索引が効かず逐次走査になりうる。想定規模 (project あたり cuts 10^3〜10^4) では許容と判断して先回りしない。拡張の導入は運用権限と運用負担を増やすため、実測が想定を超えてから入れる | cuts が 10^6 行を超える、または一覧描画の p95 が 1 秒を超えたとき | Medium | standalone | [設計](devnotes/20260817-0909-manual-search-scope/) | 2026-08-17 11:20 |
| T207 | 撮影 PWA 一覧のページング | backend | 撮影 PWA の一覧は ready/published を `.get()` で全件返す (T202 以前からの既存仕様)。本数が増えると転送量と描画が線形に悪化する。PC 一覧は既にページネーション済み | 1 project の ready/published マニュアルが 200 本を超えたとき | Medium | standalone | [設計](devnotes/20260817-0909-manual-search-scope/) | 2026-08-17 11:20 |

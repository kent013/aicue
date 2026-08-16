# 対応マトリクス: conceptual-review Round 1

## [Warning] 削除可否も行 prop にする (観点 2)
- 判断: **対応する**
- 根拠: DL と削除で UI 契約の粒度が揃っていないのは不整合。将来 `delete` policy が
  manual 個別条件を持ったとき、page prop 側だけ取り残される。
- 対応内容: 行 props を `downloadable: bool` / `deletable: bool` の 2 つにし、
  page 級 prop `canDeleteManuals` は**作らない**。UI は行 prop だけを見る。

## [Warning] 「先頭 manual で policy 代用」は脆い / project-level 能力を 1 回評価せよ (観点 3)
- 判断: **一部対応 + 一部反論**
- 根拠 (反論側): 提案どおり `ProjectPolicy::update` を controller で直接評価すると、
  **controller が問う ability 名そのものが変わる** (`download` / `delete` を問わなくなる)。
  委譲関係 (`VideoManualPolicy::download` → `ProjectPolicy::update`) を controller に
  ハードコードすることになり、policy が分岐した日に**赤くならずに間違う**。
  ability 名は正しいまま「評価回数」だけを畳むほうが安全側である。
- 根拠 (性能側の実測事実): 行ごとに `can()` を呼ぶと権限解決が行数に比例する。
  - `Project::memberRole()` は毎回 `members()->whereKey()->first()` を撃つ (memo 無し)。
  - Laratrust のキャッシュは `config/laratrust.php` で
    `'enabled' => env('LARATRUST_ENABLE_CACHE', env('APP_ENV') === 'production')` =
    **production 以外では無効**。dev/test では `hasRole()` が毎回 DB を見る。
  - per_page=10 × 2 ability = 最大 20 回の権限解決 → 数十クエリ。
  - `Project::memberRole()` 側に memo を入れる案は既存テスト
    (`ConsoleRoleTransitionTest` が同一インスタンスに対しロール変更前後の値を読む) を
    壊すため採らない。
- 対応内容:
  1. 評価の畳み込みを**名前のある場所**へ出す:
     `App\Services\Manual\ManualRowAbilities`（「同一 project の manual に対する
     download / delete の可否は project 単位で決まる」ことを名前と docblock で宣言する）。
     controller はここから `downloadable` / `deletable` を行ごとに受け取る。
  2. 前提を**機械で固定**する。`ManualRowAbilityPremiseTest` (Feature):
     同一 project の 2 manual (status / creator / category が異なる) で
     `can('download')` / `can('delete')` が一致すること、撮影者は両方 false、
     編集者は両方 true になることを固定 = policy が manual 依存になった日に赤くなる。
  3. 行数に比例しないことも**機械で固定**する。`ManualListQueryCountTest` (Feature):
     1 行のページと 10 行のページで発行クエリ数が同数になること (`DB::enableQueryLog`)。
  4. 代表 manual の選び方 (先頭行) は `ManualRowAbilities` の内部実装として閉じ込め、
     controller / UI からは見えないようにする。

## [Warning] stale snapshot の 404 着地 (観点 5)
- 判断: **対応する**
- 対応内容: Feature テストで「published が外れた / 現行世代の成果物が無い状態で
  download を叩くと 404」を固定する (既存契約の非退行 pin)。詳細設計の実装メモに
  「行内 DL は素の `<a>` = 非 Inertia 遷移であり、ブラウザバックで一覧に戻れる」ことと、
  Inertia Error 画面では受けられない (passthrough = NonInertiaRequest) 理由を残す。

## [Warning] `q` 200 文字上限の契約が曖昧 (観点 5)
- 判断: **対応する**
- 根拠: 「切り捨て」か「破棄」かを決めていなかった。`title` の validation は `max:200` なので、
  201 文字以上の検索語が意図どおり一致することは無い。破棄 (= 絞り込み無し) は
  「全件が出る」方向へ倒れて驚きが大きいのに対し、切り詰めは「より広く当たる」方向にしか
  倒れず、詰みを作らない。
- 対応内容: 契約を「**先頭 200 文字だけを検索語として使う (truncate)**」と値オブジェクトの
  docblock に明記。show の絞り込みと destroy の redirect が**同じ値オブジェクトを通る**ため
  結果は必ず一致する。Feature テストで両経路の一致を固定する。

## [Warning] PHP 側 props の型契約が弱い (観点 7)
- 判断: **対応する**
- 対応内容: 行 props を PHP DTO `App\DataTransferObjects\Manual\ManualListItemData`
  (readonly + `toArray()` の array shape 明示) にする。既存 `AnalysisJobData` /
  `RenderJobData` と同じ流儀。`duration_ms: int|null` / `downloadable: bool` /
  `deletable: bool` を含む shape を PHPStan level 10 の対象にする。
  一覧クエリも値オブジェクト `App\DataTransferObjects\Manual\ManualListQuery` にする。

## [Suggestion] 期待効果の書き方 (観点 4)
- 判断: **対応する**
- 対応内容: 「再生時間の有無で出来上がりが分かる」は状態バッジと重複するため、
  主効果を「**完成動画の尺 (配布判断に要る情報) を一覧で把握できる**」に書き換える。

## [Suggestion] 使命整合 / スコープ (観点 1・6)
- 判断: 指摘なし (肯定)。変更しない。

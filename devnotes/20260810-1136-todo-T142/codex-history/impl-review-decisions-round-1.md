# 対応マトリクス: impl-review Round 1

## [Critical] 順序異常行 (`purge_after < requested_at`) が due 抽出に残り削除されうる

- **判断**: 対応する (指摘は正しい)
- **根拠**: `unexpected` として report しながら**その行を物理削除していた**。CHECK 制約が壊れた
  ときに「猶予が経過していないユーザーを早期に消す」向きへ倒れる fail-open で、
  defense-in-depth の意図と真逆だった。
- **対応内容**:
  - `AccountDeletionStateDto::isNormalized()` を新設し (両列非 null かつ `purgeAfter >= requestedAt`)、
    `isDue()` の前提を `isPending()` から `isNormalized()` へ変更 = **DTO 層で fail-closed**。
  - 執行バッチの due 抽出に `whereColumn('deletion_purge_after', '>=', 'deletion_requested_at')` を追加
    (クエリ層でも同じ判断。二重防御)。
  - テスト 2 本を追加:
    - `PurgeDeletionRequestsCommandTest`「期限 < 予約時刻の非正規行は削除されず report + FAILURE」
    - `AccountDeletionGraceTest`「期限 < 予約時刻の非正規な組は執行されない (fail-closed)」
  - **mutation M30 で赤化を実測**して `mutation-evidence.md` に記録。

## [Warning] AutoRechargeTriggerJob の検証が `jobs` 全体の件数になっている

- **判断**: 対応する
- **根拠**: 指摘どおり (a) 退会通知 job で汚染されうる、(b) `reserve()` へ到達する経路を
  1 本も叩いていない、の 2 点で主張を証明できていなかった。
- **対応内容**:
  - `queuedJobClasses()` ヘルパを追加し、`jobs.payload` の `displayName` を復元して
    **クラス名で判定**する (退会通知 job は業務ジョブでないため除外)。sweep 側の assertion も置換。
  - **`reserve()` に至る業務経路を実際に叩くテストを追加**:
    予約中ユーザーで `POST /projects/{p}/manuals/{m}/analyze` (fixture 一式を作成) →
    **409** / `AnalysisJob` 0 件 / `AutoRechargeTriggerJob` 不在 / 業務 job 0 件。

## [Warning] 2FA 必須組織の到達性テストが準拠済みユーザーで、詰みを検出できない

- **判断**: 対応する。**追跡した結果、実在の詰み (Critical 相当) が見つかった**
- **根拠**: 2FA 強制ゲートは priority list で凍結より**前**に走る。未準拠ユーザーの取消 DELETE は
  2FA ゲートが `settings.security` へ倒すが、その `settings.security` は**凍結の allowlist に
  無かった**ため凍結が `/settings` へ倒し返す = **行き先のない相互ブロック**になっていた
  (設計の allowlist の見落とし)。
- **対応内容**:
  - `AccountDeletionFreezeAllowance::SettingsSecurity` を追加 (30 文字以上の根拠つき)。
    件数 pin を 16 → 17 に更新。
  - テストを書き換え: 未準拠ユーザーは取消が `settings.security` へ倒れること、
    `settings.security` / `settings` に**到達できる**こと、準拠を達成すれば取消できることを固定。
  - `docs/architecture.md` の §退会の猶予期間つき削除 に「2FA 必須組織との相互作用」を追記。
  - **mutation M31 で赤化を実測**して記録。

## [Warning] 同一秒内の取消 → 再予約は tuple が一致し古い job も送られる

- **判断**: 対応する (**主張を狭める**方向。実装は変えない)
- **根拠**: 指摘は正しい。ただし同一秒内の再予約では新旧の `purgeAfter` が**同一の値**になるため、
  利用者に誤った期日が届くことはない (実害がない)。秒未満まで比較しても DB 側が
  `timestamp(0)` で丸めるため解決にならず、精度を上げる改修は効果に対して複雑さが勝る
  (思考原則 2)。
- **対応内容**:
  - `AccountDeletionStateDto::matches()` と通知クラスの docblock に
    「保証するのは**値が変わった**予約に対して古い job を送らないことまで」「同一秒内の
    取消 → 再予約は区別できないが、その場合は期日が同一なので誤情報にならない」を明記。
  - テスト名を「値が変わった再予約では古い通知 job が送られない (同一秒内の再予約は区別しない)」へ変更し、
    **同一秒内の再予約では `['mail']` が返る**ことを対照として固定 (誇張しない)。

## [Warning] `U - A` の実 HTTP sweep が parameterless route だけ

- **判断**: 対応する
- **根拠**: 指摘どおり、有効な自組織 parameter を持つ route は 1 本も実 HTTP で測っていなかった。
- **対応内容**: 代表 route を behavioral に追加 —
  `projects.show` / `projects.edit` / `projects.update` を**自組織の実在 project** で叩いて
  `/settings` へ 302 することを固定 (さらに上記の `projects.manuals.analyze` も加わる)。
  全件 sweep を parameterized まで広げないのは、ダミー id ではテナント境界 404 が先に閉じる
  (それが正しい順序である) ため。この限界はテストの docblock に明記済み。

## [Suggestion] 検査 3 の役割をコメントで明確化

- **判断**: 対応する
- **対応内容**: `AccountDeletionFreezeRouteGateTest` 検査 3 の冒頭に、
  「守るのは宣言と実装の一致であり、allowlist の増加は件数 pin / 名指し pin が担う
  (mutation M5 で実測)」を追記。

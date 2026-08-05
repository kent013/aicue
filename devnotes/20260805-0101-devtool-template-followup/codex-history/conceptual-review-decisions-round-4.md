# 対応マトリクス: conceptual-review Round 4

## [Critical] 観点2: 施策 6 に対応する新規テストが無い (既存テストは `writeFileSync` のままでも green)。禁止事項 1 / 思考原則 5 に抵触

- 判断: **対応する**
- 根拠: 完全に正当。施策 6 の当初のテスト方針は
  「既存の config 保存経路が引き続き green であることで担保する」だったが、
  それは**変更前後で同じ結果になる**ので不変条件を何も固定していない。
  AGENTS.md 禁止事項 1 (不変条件は対応するテストへの登録まで含めて「実装済み」) と
  思考原則 5 (テストファースト。fail を確認してから実装に入る) の両方に抵触していた。
- 対応内容: `packages/cli/tests/config/saver.test.ts` を**新設**し、
  検証 3 本を定義した (うち 2 本は実装前に赤い):

  | # | 検証 | 実装前 |
  |---|------|--------|
  | 1 | tmp 書き込みが失敗したとき**既存 config が旧内容のまま残る**。`{path}.{process.pid}.tmp` を**ディレクトリとして先に作る**ことで tmp 書き込みを決定的に失敗させる | **赤** (現行は直接上書きなので旧内容が失われる) |
  | 2 | 正常保存後に `.tmp` 残骸が無く内容が読み戻せる | 緑 (回帰用) |
  | 3 | `src/config/saver.ts` が `writeFileSync` を直接使わず `atomicWriteFile` 経由であること (deny-by-default の構造検査) | **赤** |

  Codex 提案の「helper を注入・spy して呼び出しを検証する」案は採らなかった。
  呼び出しの検証は**実装の形**を固定するだけで、
  検証 1 の**振る舞い**の固定のほうが不変条件として強いためである
  (検証 3 は将来の書き戻しを防ぐ補助ガードとして併置する)。
  受け入れ条件にも「検証 1・3 が `saver.ts` 変更前に赤いことを確認済み」を追加した。

## [Warning] 観点5: tmp+fsync+rename は親ディレクトリを fsync しないので、クラッシュ後の永続性までは保証しない

- 判断: **対応する**
- 根拠: 正当。`credential/atomic-write.ts:30-48` を再確認したところ、
  fsync しているのは**一時ファイルの fd のみ**で、親ディレクトリの fsync は無い。
  「物理的原子性」という言い方は保証範囲を過大に見せていた。
- 対応内容: 用語を **atomic replacement** に統一し、
  施策 6 と判断 6 の両方に「**クラッシュ後の durability ではない**。
  完全な durability が要るなら親ディレクトリ fsync を別途検討する = 本バッチのスコープ外」
  と明記した。判断 6 の §「原子的」の定義 を
  「単一の論理更新 / atomic replacement / durability」の 3 層に整理した。

## [Warning] 観点6: 施策 6 は全 config 保存経路への変更であり、施策 3 と独立に戻せない

- 判断: **対応する**
- 根拠: 正当。施策 6 は `profile:delete` とは影響範囲が違う
  (`profile:add` / `profile:use` を含む全保存経路)。
  同じコミットに入れると、`profile:delete` を戻したいだけの場面で
  atomic replacement まで巻き戻る (あるいはその逆)。
- 対応内容: 実装順を **2 コミット → 4 コミット**に分割した。

  | # | 内容 |
  |---|------|
  | 1 | Track A: skill モデル一本化 (施策 2 → 施策 1) |
  | 2 | Track B-0: packages 検証の配線 (施策 5)。以降のテストが CI で走る前提を先に作る |
  | 3 | Track B-1: config の atomic replacement (施策 6 のテスト → `saver.ts` 1 行) |
  | 4 | Track B-2: `profile:delete` (施策 4 のテスト → 施策 3) |

  同一バッチ (同一 devnotes / 同一 TODO) に含めること自体は妥当という
  Codex の評価に沿って、バッチ分割はしない。

## [Suggestion] 観点7: `nextDefault` が無い場合はプロパティ自体を省略すると `exactOptionalPropertyTypes` と整合しやすい

- 判断: **対応する**
- 根拠: 妥当。実査で `packages/cli/tsconfig.json` に
  **`exactOptionalPropertyTypes: true`** と `noUncheckedIndexedAccess: true` が
  設定済みであることを確認した。`nextDefault: undefined` を渡すと型エラーになる。
  既存コードも同じ作法 (`profile/add.ts:77-87` の `init` 組み立て) を取っている。
- 対応内容: §型安全方針 に該当項を追加し、受け入れ条件にも
  「`nextDefault` 未指定時にプロパティを省略している」を追加した。

## [Suggestion] 観点1 / 観点3 / 観点4 / 観点5 / 観点7 の肯定的評価

- 判断: 対応不要
  (施策 3 の順序が判断 6・8 と一致したこと、`nextDefault` 受理条件、
  逸失欠陥の安全トリガー化、型境界の明確さ はいずれも Round 3 対応で解消済みと確認された)

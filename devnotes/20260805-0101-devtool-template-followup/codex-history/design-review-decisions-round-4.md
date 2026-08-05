# 対応マトリクス: design-review Round 4

## [Critical] 施策3: plan/execute 分離による TOCTOU（確認待ちの間に profile が変わると古い計画で credential を消す）

- 判断: **対応する**
- 根拠: 完全に正当。Round 3 で順序問題を直した結果、新しい穴を開けていた。
  提示された筋書きがそのまま成立する:

  1. `api_url: A` で計画を作る
  2. 確認プロンプト待ちの間に、別プロセスが同名 profile を `api_url: B` へ書き替える
  3. execute が **A の credential を破棄**し、**B の config を削除**する
  4. → **B の credential は在り処 (`profile_hash12`) を導出する `api_url` を失い、
     永久に到達不能な孤児になる**

  Round 2 の Critical（keychain 孤児化）と**同じ種類の害**を、
  別の経路で作り直していた。`writer.deleteProfile()` の内部再検証では
  「profile は存在する」しか見ないので origin 変更を検知できない、という指摘も正しい。
- 対応内容:
  1. **`executeProfileDeletion()` の先頭に TOCTOU ガード**を置いた。
     `planProfileDeletion()` を**もう一度**呼び、`plansMatch()` で最初の計画と突き合わせる。
     食い違えば **credential に触れる前に** `ProfileResolutionError.conflict`（exit 10）で停止する。
     比較対象は Codex 指定どおり `name` / `clearDefault` / `wasDefault` / `nextDefault` /
     `credentials`（`kind` と `origin`）/ `remaining`（順序込み）
  2. **`ProfileWriter.readState()` を新設**（Codex の追加提案）。
     `{ defaultProfile, profiles }` を **1 回の `loadUser()`** で返す。
     計画フェーズが `get()` / `list()` / default を別々に読むと、
     **計画そのものが不整合**になりうるため。
     Round 1 で足そうとしていた `defaultProfileName()` は**廃止**し、
     追加 API を 1 本に抑えた（テストも `readState().defaultProfile` を見る）
  3. exit code 表に 2 行追加（config 変更 → 10 / profile 消失 → 11、どちらも**何も削除しない**）
  4. 実装順序に 4a（TOCTOU ガード）/ 4b（credential 破棄）を明記
  5. `executeProfileDeletion` の JSDoc の収束契約に
     「確認待ちの間に config が書き替わった → 何も触らず exit 10」を追加
  6. リスク表に該当行を追加

## [Suggestion] 施策3: `origin` と `unlocatableReason` は不正な組み合わせを表現できる

- 判断: **対応する**
- 根拠: 妥当。`{ origin: null, reason: null }` や `{ origin: "...", reason: "..." }` が
  型として作れてしまい、`String(plan.unlocatableReason)` という防御的変換も
  「null が来るかもしれない」ことの裏返しだった。
- 対応内容: 判別共用体へ変更した。

  ```ts
  export type CredentialLocation =
      | { kind: "located"; origin: string }
      | { kind: "unlocatable"; reason: string };
  ```

  - `plan.credentials.kind` で分岐すれば `reason` / `origin` が型で確定する
  - `String(null)` 防御が不要になった
  - `credentialsSkipped` も `plan.credentials.kind === "unlocatable"` から導出
  - 型適合チェックに「表現不能状態を型で排除」を明記

## [Warning] 施策4: 確認中の profile 変更に対するテストが無い

- 判断: **対応する**
- 根拠: 正当。Critical への対応で分岐を増やす以上、テストが無ければ
  AGENTS.md 禁止事項 1 に抵触する。
- 対応内容: 検証軸 **5c**（TOCTOU）を新設し、5 ケースを定義した。

  | # | 計画後に起こす変化 | 期待 |
  |---|------------------|------|
  | a | **`api_url` を A → B に変更** | exit 10 相当の throw。**A/B 両方の credential が無傷**、config も無傷 |
  | b | `default_profile` を付け替える | 同上 |
  | c | 別プロファイルを追加する | 同上（`remaining` の食い違い）|
  | d | 対象プロファイル自体を消す | exit 11 相当。credential 無傷 |
  | e | 何も変えない | 正常に削除される（**ガードが誤検知しない**こと）|

  a は Codex が「孤児化防止の必須ケース」と名指ししたケースで、
  コード例つきで設計書に載せた。
  逆確認（ミューテーション）にも
  「TOCTOU ガードを外すと 5c-a が赤くなる」を追加した。

---

## 判定

Critical 1 件・Warning 1 件・Suggestion 1 件をすべて対応（反論なし）。
Round 2（keychain 孤児化）と Round 4（TOCTOU 孤児化）は、
**「config を消すと credential の在り処が失われる」という同じ根**から生えた別経路であり、
どちらも fail-closed で塞いだ。

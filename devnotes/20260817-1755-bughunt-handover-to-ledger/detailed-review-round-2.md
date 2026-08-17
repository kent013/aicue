# 全体判定: CHANGES_REQUESTED

Round 1 の主要な問題は適切に解消されています。特に fail-closed 境界、superseded の扱い、移行断片のフィールド分離、CI 非実行時の保証範囲は明確になりました。

ただし、新たに [Critical] 2 件と [Warning] 5 件が残っています。

## 施策 0: 腐り検知テスト

判定: REQUEST_CHANGES

- [Critical] `REPO_ROOT` の計算が 1 階層不足しています。

  現在の定義では、

  ```python
  SKILL_DIR = /workspace/.claude/skills/app-bug-hunt
  os.path.dirname(os.path.dirname(SKILL_DIR))
  # => /workspace/.claude
  ```

  となり、リポジトリルート `/workspace` になりません。`app/...` 等の全根拠パスが `/workspace/.claude/app/...` として検査されます。

  修正案:

  ```python
  REPO_ROOT = os.path.dirname(
      os.path.dirname(
          os.path.dirname(SKILL_DIR)
      )
  )
  ```

  可能なら `pathlib` で `Path(SKILL_DIR).parents[2]` とし、`REPO_ROOT / "AGENTS.md"` が実在することをテストの前提確認に加えてください。

- [Warning] 原子性テストが依然として「入力検証失敗」しか扱っていません。これは `write_atomically()` が呼ばれない経路なので、temp 作成後の書き込み・chmod・replace 失敗を検証できません。

  修正案: 一時ディレクトリの sentinel を用い、少なくとも次を追加してください。

  - `os.replace` が例外を投げても sentinel が不変
  - 例外後に temp が残らない
  - 成功時に既存 mode を維持
  - 新規出力が 0644 になる

- [Warning] 節見出しが「テスト一覧 33 本」ですが、表は 36 まであります。

  修正案: 「36 本」または「36 契約、ケース分割により実メソッド数は変動」と統一してください。実装完了時には実際のテスト件数を確定させる方が検証記録として明確です。

## 施策 1: 生成器

判定: REQUEST_CHANGES

- [Warning] 機械マーカーの偽装防止が `context` にしか適用されていません。`scope_value`、`source_finding_ids`、commit 等も生成物へ未エスケープで出力されるため、改行と `<!-- entry:` を含めればマーカー行を生成できます。

  修正案: 生成物へ埋め込むすべての文字列について、次のいずれかを実施してください。

  - CR/LF と `ENTRY_MARKER_PREFIX` を拒否する
  - Markdown 用に安全にエスケープする
  - マーカー抽出時に生成器が管理する構造だけを解析する

  少なくとも `scope_kind`、`scope_value`、`source_finding_ids`、run、commit、`supersedes` に対する注入テストが必要です。

- [Warning] 生成器は `supersedes` の target 不在、自己参照、循環を検証しません。その状態で matcher と同じ集合式だけを使うと、matcher が registry を無効化する一方、生成物は誤った `active` / `superseded` を表示できます。

  修正案: 有効性を表示する以上、生成器でも少なくとも次を `RenderError` にしてください。

  - `supersedes` が正しい A-ID 形式
  - target が存在する
  - 自己参照がない
  - 循環がない

  「照合器と同じ規則」という説明も、「照合器の検証を通過できる supersede 関係について同じ active 算出を行う」と限定すると正確です。

- [Warning] 非空文字列が whitespace-only を拒否するか不明です。

  修正案: `title`、`narrative`、`spec_basis` 要素、`reopen_condition`、移行台帳の文字列について `value.strip() != ""` を契約として明記してください。

## 施策 2: 移行台帳

判定: REQUEST_CHANGES

- [Critical] `machine_projection_sha256` 自体が可変で、テスト側に pin されていません。

  現設計では機械項目を書き換え、同時に manifest の hash を更新すればテスト 32 は通ります。`EXPECTED_MIGRATION` が完全一致で固定するのは `entries` の意味論だけで、`provenance.machine_projection_sha256` は含まれていません。したがって次の主張は成立しません。

  > 以後、既存行の機械項目を黙って書き換えるとテストが赤くなる。

  修正案: テスト側にも独立した期待値を置いてください。

  ```python
  EXPECTED_MACHINE_PROJECTION_SHA256 = {
      "A-001": "...",
      "A-002": "...",
      "A-003": "...",
  }
  ```

  テストでは次の三点一致を要求します。

  1. テスト定数
  2. manifest の `machine_projection_sha256`
  3. 現在の adjudications から計算した hash

  また、正規化方式を `separators=(",", ":")` を含めて完全に固定すると、実装差による hash の揺れを避けられます。

- [Warning] `required_fragments` を集合比較すると、同一断片の重複を見逃します。

  修正案: manifest 読み込み時に `(field, value)` の重複を拒否し、期待値との比較は要素数も含めて行ってください。

## 施策 3: A-001 の移行

判定: REQUEST_CHANGES

- [Critical] machine projection の append-only 保証が、上記の可変 hash 問題により未成立です。

  修正案: 施策 2 の三点 pin を導入すれば解消します。

- [Suggestion] `watch_globs` の別タスク化は受理できます。既存不備であり、本移行の成立条件とは分離可能です。ただし「TODO 登録時の候補」では追跡が失われる可能性があります。実装完了前に、後続タスクの具体的な識別子または台帳上の申し送り先を確定するのが望ましいです。

## 施策 4: A-003 の context

判定: APPROVE

一次資料と移行時確認の区別、再オープン条件、機械 invalidation が効かない範囲が明示されています。後から作った経緯を当時の確定事実として偽装しない設計になっています。

## 施策 5: 生成物への置換

判定: APPROVE

「正常に再生成された出力」に保証を限定し、CI 非実行時には stale な生成物が残り得ることも明記されています。全数掲載と有効性も分離されています。

## 施策 6: README 更新

判定: REQUEST_CHANGES

- [Warning] 運用ガードの次の文は、後段の保証範囲とまだ矛盾します。

  > `context` を書かなくても登録は `spec-ledger.md` に……載る。黙って消えることはない。

  再生成を忘れれば、追加された登録は生成物に載りません。

  修正案:

  > `context` が無い登録も、正常に再生成すれば「経緯は未記入」として必ず載る。再生成忘れは CI では検出されない。

## 施策 7: AGENTS.md 更新

判定: APPROVE

JSON schema 不備と JSON 構文不備の境界、再生成時だけ成立する完全性、active と superseded の違いが簡潔にまとまっています。

## 最終評価

使命との整合性、禁止事項、スコープ、技術的実現可能性には問題ありません。A-001 の `watch_globs` を別タスクにする判断も、既知の限界を明記する条件で妥当です。

承認までに必要な主要修正は次の2点です。

1. `REPO_ROOT` を正しい `/workspace` へ直す。
2. machine projection hash をテスト側にも独立して pin し、manifest・現物との三点一致にする。

あわせて、原子性の障害注入テスト、全表示文字列からのマーカー偽装防止、README の再生成限定を反映すれば、次ラウンドでは承認可能な状態です。
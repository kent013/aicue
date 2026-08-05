# 対応マトリクス: design-review Round 2

## [Critical] 施策3: keychain の index 破損時に、列挙不能な資格情報を残したまま config を削除して exit 0 にしている

- 判断: **対応する**（設計を全面的に組み替えた）
- 根拠: 完全に正当。しかも**セキュリティ上の実害**である。
  config を消すと `api_url` が失われ、credential の物理位置
  (`deriveProfileHash12(canonicalOrigin(api_url), name)`) を**二度と導出できなくなる**。
  keychain に残った API キー / OAuth トークンは、利用者からも CLI からも
  到達できない孤児として**永久に固定される**。
  Round 1 で「詰ませない」を優先した結果、より悪い「秘密の孤児化」を作っていた。
  「再実行で必ず収束する」という自分自身の契約にも反していた。
- 対応内容: `purgeProfile()` の戻り値を
  **`{ complete: boolean; indexCorrupted: boolean }`** に変更し、
  backend で本質的に異なる挙動を型で表現した。

  | backend | index 破損時 | `complete` | 呼び出し側 |
  |---------|------------|-----------|-----------|
  | file | プロファイルディレクトリを丸ごと落とせる = 取りこぼし無し | `true` | 警告して削除を完遂 |
  | keychain | 列挙手段が無く item を特定できない | `false` | **config を残して `CredentialStoreFailure` (18)**。手動清掃 → 再実行を案内 |

  - Codex 提案の「config だけ削除する最終手段フラグ」は**採らない**。
    現時点で必要という根拠がなく、思考原則 2（今必要なものだけ作る）に反する。
    必要になったら「秘密を到達不能にする」ことを明示した専用フラグとして別途設計する
  - exit code 表 / リスク表 / テスト計画（5b-e）を同期した

## [Warning] 施策3: `purgeProfile()` が `clearProfile()` 由来の全 `CredentialStoreError` を「index 破損」と解釈する

- 判断: **対応する**
- 根拠: 正当。現在 `CredentialStoreError` を投げるのは `corruptedIndex` だけだが、
  将来 `clearProfile` の内側から復号失敗・削除失敗が同じ型で飛べば**黙って握り潰す**。
  こういう握り潰しは「テストが通るのに壊れている」を作る典型である。
- 対応内容: `CredentialStoreError` に**判別子 `kind`** を追加した
  (`"corrupted-index" | "unknown"`、既定 `"unknown"`)。
  - `corruptedIndex` ファクトリだけが `"corrupted-index"` を名乗る
  - `purgeProfile` は
    `e instanceof CredentialStoreError && e.kind === "corrupted-index"` で**二重に**絞る
  - 既定値があるので**既存の呼び出し元は 1 箇所も変えなくてよい**
  - 施策一覧 / 変更箇所表に `credential/errors.ts` を追加
  - 施策 4 に「index 破損以外の `CredentialStoreError` を握り潰さない」テスト（5b-f）を追加

## [Warning] 施策3: `(e as Error).message` は「ad-hoc な as cast を入れない」という本設計自身の規約違反

- 判断: **対応する**
- 根拠: 正当。自分で書いた型規約を自分の設計コードが破っていた。
  `catch` の束縛は `unknown` なので `as Error` は無検査のダウンキャストである。
- 対応内容: `const message = e instanceof Error ? e.message : String(e);` に変更し、
  型適合チェックにも「catch 節の `unknown` は instanceof で絞る」を明記した。

## [Warning] 施策4: Round 1 で追加した重要分岐のテストが不足（不正 api_url / 破損 index の backend 別 / `credentialIndexCorrupted`）

- 判断: **対応する**
- 根拠: 正当。Round 1 で分岐を増やしたのにテスト計画を同期していなかった。
  分岐だけ増やしてテストを書かないのは AGENTS.md 禁止事項 1 そのものである。
- 対応内容: 検証軸 **5b** を新設し、6 ケースを定義した。
  - a/b/c: 不正 URL・非 http(s) スキーム・空 api_url で
    **credential に触れず** config だけ消える（`credentialsSkipped === true`）
  - d: file backend の破損 index → 削除が**完遂**し、
    `credentialIndexCorrupted === true`、ディレクトリが消える
  - e: keychain backend の破損 index → **exit 18 相当の throw**、
    **config が残る**、他プロファイルの credential も無傷
  - f: `kind: "unknown"` の `CredentialStoreError` はそのまま伝播する
    （握り潰しが復活していないこと）

  併せて逆確認（ミューテーション）チェックを 2 本追加:
  - `complete` を `true` 固定に改悪すると 5b-e が赤くなる
  - `kind` の絞り込みを外すと 5b-f が赤くなる

## Round 1 指摘の撤回について

Codex は `__dirname` の [Critical] を実測根拠により撤回した。設計は変更しない。

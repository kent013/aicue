# 対応マトリクス: design-review Round 3

## [Warning] 施策3: 事前検証の順序が「設計文・exit code 表・コード」で不一致（default 競合がプロンプトの後ろ）

- 判断: **対応する**（構造を組み替えた）
- 根拠: 完全に正当。設計文の実装順は「default 判定 → 確認プロンプト」なのに、
  提示コードは `confirmPrompt()` → `deleteProfileWithCredentials()` の中で初めて
  competing を判定していた。この順序だと **`--yes` の有無で exit code が変わる**:
  非 TTY で `--yes` 無しなら `confirmPrompt` が false を返し、
  本来 exit 10 のケースが **exit 1 に化ける**。
  自分で書いた exit code 表を自分のコードが守っていなかった。
- 対応内容: Codex 提案どおり **plan / execute の 2 段構成**にした。
  - **`planProfileDeletion(writer, name, opts): ProfileDeletionPlan`** — 副作用ゼロ。
    存在確認（exit 11）・default 競合（exit 10）・残プロファイル列挙・`nextDefault` 確定・
    credential の在り処解決をここで済ませる。**stderr へも書かない**
    （警告文言は `unlocatableReason` として計画に載せ、execute 側が出力する）
  - **`executeProfileDeletion(deps, plan): DeleteProfileResult`** — credential → config の実行
  - コマンドは `assertProfileName` → `planProfileDeletion` → `confirmPrompt` →
    `executeProfileDeletion` の順。冗長だった `if (!writer.get(name)) exitWith(...)` の
    事前チェックは削除（例外経路と二重の真実源になるため）
  - 実装順序の節に「事前検証は確認プロンプトより**必ず前**」を不変条件として明記
  - exit code 表に「11 / 10 は `--yes` の有無に関わらず同じ値」を追記
  - リスク表に「事前検証がプロンプトの後ろへ戻る改変 → CLI 契約テスト #2 が赤くなる」を追加

## [Warning] 施策3: 「再実行で必ず収束する」が keychain index 破損時に成立しない

- 判断: **対応する**
- 根拠: 正当。Round 2 で fail-closed に変えたのに、収束契約の文言を更新していなかった。
  手動清掃という**外部操作**が要る以上、「再実行で収束」は嘘である。
- 対応内容: `executeProfileDeletion` の JSDoc で契約を 2 分割して明記した。
  - 通常経路 / config 保存失敗 → **同じコマンドの再実行で収束する**
  - keychain の credential index 破損 → **fail-closed**。再実行だけでは収束せず、
    OS keychain の手動清掃を要求する（取りこぼした秘密を到達不能にしないための仕様）

  概念設計 §冪等性契約 にも同じ限定を追記して両者を同期した。

## [Suggestion] 施策3: 手動清掃案内の keychain service 名が `BIN_NAME` で、実際の保存先 (`KeychainStore` 内部の `SERVICE`) と同一という暗黙前提

- 判断: **対応する**（かつ**定義の二重化を発見した**）
- 根拠: 正当。実査したところ**同じ値の定義が 2 箇所**にあった。
  - `branding.ts:27` — `export const KEYCHAIN_SERVICE = APP_SLUG;`
    （コメントで「OS keychain のサービス名」と明記された**正本**）
  - `credential/keychain.ts:9` — `const SERVICE = \`${BIN_NAME}\`;`（module 内の独自定義）

  今は `APP_SLUG === BIN_NAME` なので偶然一致しているだけで、
  branding.ts が正本を宣言している以上こちらが参照されるべきである。
- 対応内容:
  - `keychain.ts` の `SERVICE` を `branding.KEYCHAIN_SERVICE` 参照に変更（値は不変 = 挙動不変）
  - 案内文言も `KEYCHAIN_SERVICE` を使う
  - 施策一覧 / 変更箇所表に `credential/keychain.ts` を追加
  - リスク表に「案内のサービス名が実際の保存先とずれる」→ 一本化で解消、を追加

## [Warning] 施策4: CLI 契約テスト #2 が `--yes` の有無で exit が変わる回帰を固定できていない

- 判断: **対応する**
- 根拠: 正当。上の Critical と同根で、テスト側も順序を固定していなかった。
- 対応内容: CLI 契約テストの #2 を強化し、#2b を追加した。
  - **#2**: default を `--clear-default` 無し **かつ `--yes` 無し**で実行 →
    `codes[0] === 10`、**`confirmPrompt` の spy が 0 回**（プロンプトへ入っていない証拠）、
    config・credential とも無傷
  - **#2b**: 同じケースに `--yes` を付けても **`codes[0]` は同じく 10**
    （`--yes` の有無で exit が変わらないこと）
  - 逆確認（ミューテーション）に
    「`planProfileDeletion()` を確認プロンプトの後ろへ動かすと #2 が赤くなる」を追加
  - 検証軸 3 の 1 ケース目も「**`planProfileDeletion()` が** throw する
    （計画フェーズは副作用ゼロ）」と出どころを明示

---

## 判定

Warning 3 件・Suggestion 1 件をすべて対応（反論なし）。
Suggestion 1 件は keychain サービス名の定義二重化という実際の不整合の発見につながった。

# 対応マトリクス: design-review Round 3（**APPROVED** 後の Suggestion 反映）

Round 3 で全体判定 **APPROVED**。各施策も APPROVE。以下の 3 件の [Suggestion] は
いずれも低コストで設計の読み違いを減らすため、承認後に反映した
（設計の意味は変えていない = 再レビュー不要と判断）。

## 施策 2 [Suggestion] `countAttached()` は「raw 登録本数」にも読める
- 判断: 対応する
- 根拠: docblock で意味は限定できているが、**呼び出し側は名前しか見ない**ことが多い。
  「数える対象は実効列の種類」という設計判断そのものが名前に出ている方が誤用が減る。
- 対応内容: `RecentAuthMiddleware::countAttached()` → **`countAttachedKinds()`** に改名。
  `isAttached()` は `countAttachedKinds($route) > 0` の薄いラッパのまま。

## 施策 4 [Suggestion] `exactOptionalPropertyTypes` 下では `onDelegated` の素通しが型エラー
- 判断: 対応する（実測を添えて確定）
- 根拠: `tsconfig.json` を実査したところ `"strict": true` のみで
  `exactOptionalPropertyTypes` は**未設定**。したがって現状の書き方で
  `pnpm typecheck` を通る。ただし将来有効化した場合に壊れる箇所なので申し送る。
- 対応内容: 設計に「現状は未設定のためそのまま通る。有効化した場合は
  `...(onDelegated === undefined ? {} : { onDelegated })` へ直すこと」を注記として追加。

## 施策 5 [Suggestion] AGENTS.md 追記案に non-exemptible 6 本を全部書く
- 判断: 対応する
- 根拠: 運用文書だけを読む人が「秘密開示 3 本 + enable」しか見えないと、
  6 本の分類（(a) 開示 / (b) 除去・差し替え）という設計の骨格が伝わらない。
- 対応内容: AGENTS.md 追記案を「(a) 開示 3 本 / (b) 除去・差し替え 3 本」の 2 系統表記に直し、
  組織管理 2 本を non-exemptible に入れなかった線引きも 1 行で書いた。

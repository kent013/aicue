# 対応マトリクス: conceptual-review Round 2

## [Warning] RP ID の検査条件 `[A-Za-z0-9.-]+` では host 形式を保証できない
- 判断: 対応する
- 根拠: 指摘のとおり `-example.com` / `example..com` / `example.com.` が通る。
  「host 形式でないなら停止する」と書きながら判定が緩いのは、検査が形骸化する典型。
  既存の `TrustedHostsConfigValidator` は同じ包含正規表現だが、あちらは
  「scheme / port / path の混入を弾く」目的であり、こちらは
  **RP ID がパスキーの束縛先そのもの**なので厳密さの要求が違う。
- 対応内容: DNS ラベル単位の検査に差し替えた (各ラベル 1〜63 文字・英数字とハイフン・
  先頭末尾がハイフンでない・空ラベル無し・末尾ドット不可・全体 253 文字以下)。
  許可する接続元の host にも同じ検査を通す。

## [Warning] 「空要素は違反」と「config 段で落とす」が整合していない
- 判断: 対応する
- 根拠: 指摘のとおり。正規化して隠すのは「設定事故を起動時に検出する」という目的と正反対。
  Round 1 では機構を増やさない判断で raw を持たせなかったが、
  既存の `trustedproxy.raw_proxies` がまさにこの用途の作法として存在する以上、
  新しい機構ではなく**既存の型に寄せる**方が正しい。
- 対応内容: `raw_allowed_origins` (trim のみ・空要素も保持) を expose し、
  validator が空要素を違反として報告する形にした。framework が読む `allowed_origins` は
  従来どおり空要素を除いた列。

## [Suggestion] config:cache 下で真偽値が成立する / 実行時に env() を再参照しない
- 判断: 対応する (設計の当初意図どおり。明文化する)
- 対応内容: 詳細設計で「Guard は config 経由でのみ読む (env() 直読み禁止)」と明記し、
  config cache 往復テストで固定する。

## [Suggestion] stringList() が不正要素を黙って除去しないことをテストで固定
- 判断: 対応する
- 対応内容: 詳細設計のテスト計画に「非 string 要素が混ざった場合の挙動」を追加する。

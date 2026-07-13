# 対応マトリクス: conceptual-review Round 3

Codex の指摘（保存済み timestamp の大小は時間経過で不変＝「自己回復」主張は誤り／別プロセスの
clock skew も順序を崩す）は妥当。**timestamp 比較を廃し、DB 権威の `scenario_version` スナップショット
方式へ設計変更**する（Codex 提案の「順序を確実に表現できる設計」に該当）。

## [Warning] 3-1 時刻精度・自己回復主張の誤り
- 判断: **設計変更（timestamp → scenario_version snapshot）**
- 根拠: `scenario_version` は manual 行を lock した tx 内で +1 される単調整数。時刻でないため
  同一秒衝突・clock skew の双方が判定に無関係。かつ既存 `render_jobs.scenario_version`（作成時 snapshot）と
  同種の primitive で、実装コストは nullable int 1 列 + failJob での snapshot 書込みのみ。
- 対応内容: `analysis_jobs`/`render_jobs` に `scenario_version_at_terminal`（nullable）を追加し、
  両 failJob が manual lock 内で `manual.scenario_version` を snapshot。
  判定 = `failed && snapshot!==null && manual.scenario_version > snapshot`。

## [Warning] 4-1 「完全解消でなく確率的」
- 判断: **対応する（決定的解消に格上げ）+ 残存エッジ明示**
- 根拠: version snapshot 方式は**シナリオ保存を伴う全経路で決定的に stale 判定**する（確率的でない）。
- 対応内容: 期待効果を「シナリオ保存を伴う全経路で確実に解消（決定的）」に修正。残存エッジは
  「シナリオ保存を全く伴わず take 採用のみ後のレンダ失敗」= version 不変で検出外だが **fail-safe（表示）**
  かつ HIGH 本丸外、と明示。旧データ（snapshot=null）は not stale＝表示（保守的）。

## [Suggestion] 5-1 clock skew
- 判断: **設計変更で解消**
- 根拠: version 方式は時計を使わないため clock skew は原理的に無関係。

## [Suggestion] 3-2 / 6 境界テスト
- 判断: **対応する**
- 対応内容: Feature 判定行列テストに「scenario_version_changed の CTA が保持される（snapshot=失敗時 version）」
  「snapshot が terminal 後不変」を含める。

## CTA 保持の要点（version 比較でも成立する理由）
- 比較軸を「作成時 version」でなく **「失敗確定時 version」** にしたことで、scenario_version_changed 失敗
  （作成 N → 編集 N+1 → 失敗）では snapshot=N+1、manual=N+1 → not stale で CTA 保持。R2 で述べた
  「version 比較は CTA を壊す」問題は、比較軸を失敗時 version にすることで解消する。

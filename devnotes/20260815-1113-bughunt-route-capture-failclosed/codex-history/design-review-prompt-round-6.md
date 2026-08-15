# Round 6: 詳細設計の最終改訂

## 対応マトリクス (Round 5)

- [Warning] 施策1/5: 変更後コードのコメントに引用符付きの旧語彙が残っていた → **対応する**。`# status の語彙は ok|blocked の 2 値だけを受け付ける。` の肯定形へ直した。
- 残る出現箇所を全数確認した: (a) 施策1 の「改名する」説明と (b) 施策5 の gate パターン定義のみ。(a) は devnotes 内の設計文書 (gate の走査対象外)、(b) は `test_naming_no_stale.py` に入るが同ファイルは既存の EXCLUDE_NAMES で自己除外されている (設計のテスト計画でこの自己除外が効くことを確認する項目を持っている)。

---

## 該当箇所 (改訂後)

```python
```python
# 終了コード規約 (scripts/bug-hunt-inventory-check.sh と同じ 3 = 契約違反)
EXIT_OK = 0
EXIT_INPUT_ERROR = 1        # 読み込み・parse の失敗 (従来どおり)
EXIT_INPUT_UNAVAILABLE = 3  # 主入力の可用性違反 = 検査を成立させられない


# status の語彙は ok|blocked の 2 値だけを受け付ける。
VALID_STATUSES = {"ok", "blocked"}


@dataclass
class Executed:
```

施策ごとの判定と全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。

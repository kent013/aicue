# 実測記録: 合成コントラスト (i16) と是正後の値

計算モデル (正典 i16 / q3 の前提):

- 半透明の背景は `color-mix(in oklab, var(--color-X) N%, transparent)` へ展開され、
  透明との混色は**同じ色の alpha `N/100`** になる。
- 合成はチャンネルごとの `a*FG + (1-a)*BG` で、**8bit へ丸めた値**が実際に描かれる。
- 相対輝度の線形化しきい値は **0.04045** (WCAG 2.0/2.1 本文の 0.03928 は 2022-02-22 errata で訂正済み)。
- 下地は推論しない。**実在する不透明な下地すべて** (`neutral` #F4F4F5 / `surface` #FFFFFF) の
  両方で 4.5:1 を要求する。

再現スクリプト: `measure.py` (設計時の一時スクリプト。恒久化しない — 恒久の判定は gate が持つ)。

## 是正の内容

| token | BEFORE | AFTER | Tailwind の段 |
|---|---|---|---|
| `primary` | `#2563EB` | `#1D4ED8` | blue-600 → blue-700 |
| `primary-hover` | `#1D4ED8` | `#1E40AF` | blue-700 → blue-800 |
| `tertiary` | `#0F766E` | `#115E59` | teal-700 → teal-800 |
| `tertiary-hover` | `#115E59` | `#134E4A` | teal-800 → teal-900 |
| `success` | `#15803D` | `#166534` | green-700 → green-800 |
| `warning` | `#B45309` | `#92400E` | amber-700 → amber-800 |
| `danger` | `#B91C1C` | `#B91C1C` | red-700 (据え置き。soft でも 4.98 で足りる) |
| `--color-primary-soft` | `rgba(37, 99, 235, 0.12)` | `rgba(29, 78, 216, 0.12)` | primary の 12% (追従) |

家系の先行事例 (motivation:T194) は success green-700 → green-800、warning amber-700 → amber-800、
tertiary teal-700 → teal-800、tertiary-hover teal-800 → teal-900 と**同じ方向・同じ段**へ動いている。

## 走査で実在が確認された組に対する実測

    ===== BEFORE =====
    -- 不透明ペア --
      text-danger          on bg-surface         =  6.47 
      text-neutral         on bg-danger          =  5.89 
      text-neutral         on bg-primary         =  4.70 
      text-neutral         on bg-primary-hover   =  6.10 
      text-neutral         on bg-success         =  4.56 
      text-neutral         on bg-tertiary        =  4.98 
      text-neutral         on bg-tertiary-hover  =  6.90 
      text-text            on bg-border          = 13.96 
      text-text            on bg-neutral         = 16.12 
      text-text            on bg-surface         = 17.72 
      text-text-secondary  on bg-neutral         =  7.03 
      text-text-secondary  on bg-surface         =  7.73 
      text-surface         on bg-primary         =  5.17 
    -- 半透明背景 × 不透明前景 (下地 neutral / surface の両方) --
      text-danger          on bg-danger      / 10 =  4.98 /  5.45 
      text-primary         on bg-primary     / 10 =  4.13 /  4.49 NG
      text-primary         on bg-primary     / 12 =  4.01 /  4.37 NG
      text-success         on bg-success     / 10 =  4.00 /  4.38 NG
      text-surface         on bg-text        / 70 =  6.88 /  6.57 
      text-tertiary        on bg-tertiary    / 10 =  4.34 /  4.75 NG
      text-text            on bg-danger      / 10 = 13.62 / 14.93 
      text-text            on bg-primary     / 12 = 13.76 / 14.97 
      text-text            on bg-surface     / 80 = 17.42 / 17.72 
      text-text            on bg-warning     / 10 = 14.15 / 15.49 
      text-text-secondary  on bg-surface     / 80 =  7.60 /  7.73 
      text-warning         on bg-warning     / 10 =  4.01 /  4.39 NG
    ===== AFTER =====
    -- 不透明ペア --
      text-danger          on bg-surface         =  6.47 
      text-neutral         on bg-danger          =  5.89 
      text-neutral         on bg-primary         =  6.10 
      text-neutral         on bg-primary-hover   =  7.94 
      text-neutral         on bg-success         =  6.49 
      text-neutral         on bg-tertiary        =  6.90 
      text-neutral         on bg-tertiary-hover  =  8.62 
      text-text            on bg-border          = 13.96 
      text-text            on bg-neutral         = 16.12 
      text-text            on bg-surface         = 17.72 
      text-text-secondary  on bg-neutral         =  7.03 
      text-text-secondary  on bg-surface         =  7.73 
      text-surface         on bg-primary         =  6.70 
    -- 半透明背景 × 不透明前景 (下地 neutral / surface の両方) --
      text-danger          on bg-danger      / 10 =  4.98 /  5.45 
      text-primary         on bg-primary     / 10 =  5.23 /  5.72 
      text-primary         on bg-primary     / 12 =  5.08 /  5.57 
      text-success         on bg-success     / 10 =  5.61 /  6.14 
      text-surface         on bg-text        / 70 =  6.88 /  6.57 
      text-tertiary        on bg-tertiary    / 10 =  5.93 /  6.49 
      text-text            on bg-danger      / 10 = 13.62 / 14.93 
      text-text            on bg-primary     / 12 = 13.44 / 14.72 
      text-text            on bg-surface     / 80 = 17.42 / 17.72 
      text-text            on bg-warning     / 10 = 13.86 / 15.18 
      text-text-secondary  on bg-surface     / 80 =  7.60 /  7.73 
      text-warning         on bg-warning     / 10 =  5.55 /  6.08 

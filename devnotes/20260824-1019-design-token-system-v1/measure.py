#!/usr/bin/env python3
"""i16 の合成モデル (sRGB の重み付き和・8bit 丸め・線形化しきい値 0.04045) による実測。
設計時の根拠を再現するための一時スクリプト (恒久化しない)。"""
def hx(h):
    h=h.lstrip('#'); return [int(h[i:i+2],16) for i in (0,2,4)]
def lin(c):
    c=c/255.0
    return c/12.92 if c<=0.04045 else ((c+0.055)/1.055)**2.4
def lum(rgb):
    r,g,b=[lin(x) for x in rgb]; return 0.2126*r+0.7152*g+0.0722*b
def ratio(a,b):
    l1,l2=lum(a),lum(b); return (max(l1,l2)+0.05)/(min(l1,l2)+0.05)
def comp(fg,a,bg):  # 8bit 丸めを含む合成
    return [round(a*f+(1-a)*b) for f,b in zip(fg,bg)]

BEFORE={'primary':'#2563EB','primary-hover':'#1D4ED8','tertiary':'#0F766E','tertiary-hover':'#115E59',
        'neutral':'#F4F4F5','surface':'#FFFFFF','border':'#E4E4E7','border-strong':'#A1A1AA',
        'text':'#18181B','text-secondary':'#52525B','success':'#15803D','warning':'#B45309','danger':'#B91C1C'}
AFTER=dict(BEFORE, **{'primary':'#1D4ED8','primary-hover':'#1E40AF','tertiary':'#115E59',
                      'tertiary-hover':'#134E4A','success':'#166534','warning':'#92400E'})
# 走査で実在が確認された組 (fg, bg, alpha or None)
OPAQUE=[('danger','surface'),('neutral','danger'),('neutral','primary'),('neutral','primary-hover'),
        ('neutral','success'),('neutral','tertiary'),('neutral','tertiary-hover'),('text','border'),
        ('text','neutral'),('text','surface'),('text-secondary','neutral'),('text-secondary','surface'),
        ('surface','primary')]
ALPHA=[('danger','danger',0.10),('primary','primary',0.10),('primary','primary',0.12),
       ('success','success',0.10),('surface','text',0.70),('tertiary','tertiary',0.10),
       ('text','danger',0.10),('text','primary',0.12),('text','surface',0.80),
       ('text','warning',0.10),('text-secondary','surface',0.80),('warning','warning',0.10)]
for label,C in (('BEFORE',BEFORE),('AFTER',AFTER)):
    print(f"===== {label} =====")
    print("-- 不透明ペア --")
    for fg,bg in OPAQUE:
        r=ratio(hx(C[fg]),hx(C[bg]))
        print(f"  text-{fg:15s} on bg-{bg:15s} = {r:5.2f} {'' if r>=4.5 else 'NG'}")
    print("-- 半透明背景 × 不透明前景 (下地 neutral / surface の両方) --")
    for fg,bg,a in ALPHA:
        vs=[]
        for base in ('neutral','surface'):
            eff=comp(hx(C[bg]),a,hx(C[base])); vs.append(ratio(hx(C[fg]),eff))
        ng='' if min(vs)>=4.5 else 'NG'
        print(f"  text-{fg:15s} on bg-{bg:12s}/{int(a*100):3d} = {vs[0]:5.2f} / {vs[1]:5.2f} {ng}")

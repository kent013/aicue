import re,os,json,glob
root='/workspace'
enums={}
for p in glob.glob(root+'/app/**/*.php',recursive=True):
    s=open(p,encoding='utf-8').read()
    m=re.search(r'^enum\s+(\w+)\s*:\s*string',s,re.M)
    if not m: continue
    name=m.group(1)
    vals=re.findall(r"^\s*case\s+\w+\s*=\s*'([^']*)'\s*;",s,re.M)
    vals2=re.findall(r'^\s*case\s+\w+\s*=\s*"([^"]*)"\s*;',s,re.M)
    v=sorted(set(vals+vals2))
    if v: enums[name]=(os.path.relpath(p,root),v)
print('php string-backed enums:',len(enums))

ts={}
for p in glob.glob(root+'/resources/js/**/*.ts',recursive=True)+glob.glob(root+'/resources/js/**/*.svelte',recursive=True):
    s=open(p,encoding='utf-8').read()
    for m in re.finditer(r'export\s+type\s+(\w+)\s*=\s*(.*?);',s,re.S):
        body=m.group(2)
        lits=re.findall(r'["\']([^"\']+)["\']',body)
        # only pure unions: strip literals and check remainder is only | and whitespace
        rem=re.sub(r'["\'][^"\']*["\']','',body)
        if lits and re.fullmatch(r'[\s|]*',rem):
            ts.setdefault(os.path.relpath(p,root),{})[m.group(1)]=sorted(set(lits))
    for m in re.finditer(r'(?:export\s+)?const\s+(\w+)\s*=\s*\[(.*?)\]\s*as\s+const',s,re.S):
        lits=re.findall(r'["\']([^"\']+)["\']',m.group(2))
        rem=re.sub(r'["\'][^"\']*["\']','',m.group(2))
        if lits and re.fullmatch(r'[\s,]*',rem):
            ts.setdefault(os.path.relpath(p,root),{})[m.group(1)+' (const array)']=sorted(set(lits))
tot=sum(len(v) for v in ts.values())
print('ts pure literal value-sets:',tot)
# match by exact value set
byval={}
for n,(p,v) in enums.items(): byval.setdefault(tuple(v),[]).append((n,p))
print('\n=== TS 値集合 -> 一致する PHP enum ===')
for f,decls in sorted(ts.items()):
    for d,v in sorted(decls.items()):
        hits=byval.get(tuple(v),[])
        print(f'{f}::{d} = {v}  -> {[h[0] for h in hits] if hits else "NO EXACT PHP MATCH"}')

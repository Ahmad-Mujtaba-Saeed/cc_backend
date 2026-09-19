import sys; sys.path.insert(0,'/app')
import docker.python.services.clip_analysis as ca
orig = ca._find_webcam
def dbg(tracks, v, h):
    for t in tracks:
        print('track', t['id'], t['presence'], t['cx'], t['cy'], t['size'], 'spread', round(ca._spread(t['points']),3))
    import numpy as np
    ph, pw = v.shape
    a = [t for t in tracks if t['presence']>=0.3 and t['size']<=0.2 and ca._spread(t['points'])<=0.04]
    if a:
        t=max(a,key=lambda t:t['presence']); cx,cy=t['cx'],t['cy']
        hs=sorted(p['h'] for p in t['points']); fh=hs[len(hs)//2]
        band=v[max(0,int((cy-fh*1.2)*ph)):int((cy+fh*1.2)*ph)+1]
        print('fh',fh,'right prof', ' '.join(f'{x/pw:.3f}:{band[:,x].mean():.2f}' for x in range(int((cx+t['size']*0.9)*pw), int((cx+0.4)*pw))))
        print('left prof', ' '.join(f'{x/pw:.3f}:{band[:,x].mean():.2f}' for x in range(int((cx-t['size']*0.9)*pw), -1, -1)))
    r = orig(tracks, v, h); print('result', r); return r
ca._find_webcam = dbg
ca.analyze_clip(sys.argv[1], '/tmp/wcd', vlm_frames=0)

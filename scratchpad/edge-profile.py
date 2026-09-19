import cv2, numpy as np, sys
cap = cv2.VideoCapture(sys.argv[1]); acc=None; n=0; i=0
while True:
    ok, f = cap.read()
    if not ok: break
    i += 1
    if i % 10: continue
    eh = int(round(f.shape[0]*320/f.shape[1])); g = cv2.cvtColor(cv2.resize(f,(320,eh),interpolation=cv2.INTER_AREA),cv2.COLOR_BGR2GRAY)
    gx = (np.abs(cv2.Sobel(g,cv2.CV_16S,1,0,ksize=3))>60).astype(np.float32)
    acc = gx if acc is None else acc+gx; n+=1
v = acc/n; ph = v.shape[0]
band = v[int(0.37*ph):int(0.52*ph)]
prof = band.mean(axis=0)
print(' '.join(f'{x/320:.3f}:{prof[x]:.2f}' for x in range(0,80)))

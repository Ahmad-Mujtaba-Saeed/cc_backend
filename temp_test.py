import re
script = '''1. **Scene One: The Foggy Road**
   *Image Description:* A dimly lit, winding road shrouded in thick, swirling fog. Shadowy trees line the path, their branches reaching out like skeletal fingers. The atmosphere feels heavy and foreboding.
   *Narration Text:* "They say you shouldn’t travel this road at night…"

2. **Scene Two: The Bunny in the Mist**
   *Image Description:* A small, eerie cartoon bunny appears in the fog, its eyes glowing ominously. It stands on the road, surrounded by mist, with a sinister grin that reveals sharp teeth. The environment becomes darker, the fog swirling around it more intensely.
   *Narration Text:* "A strange bunny waits for you, lurking in the haze…"

3. **Scene Three: The Encounter**
   *Image Description:* A close-up of a terrified traveler’s face, wide-eyed and sweating, as they gaze at the bunny. The road behind them seems to twist and disappear into darkness. The bunny begins to move closer with a menacing leap.
   *Narration Text:* "Once you see it, there’s no turning back...'''
pattern = re.compile(r'(?=(?:\d+\.\s*\*?[^\n]*Scene|Scene\s+\d+|Scene:\s+\d+|\b\d+\b\s*:\s*(?:Image|Visual)))', re.I)
parts = pattern.split(script)
print('parts', len(parts))
for i, p in enumerate(parts):
    print('--- part', i, '---')
    print(repr(p[:120]))
    print()

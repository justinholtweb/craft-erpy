# Pulls each vendor mark out of the downloaded logo files as a list of
# (path d, fill) in the logo's own coordinates, and writes marks.json.
# Bounding boxes are measured afterwards in Chrome (bbox.html), because
# computing them from path data by hand means reimplementing arcs and curves.
import json, re
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen

def attrs(tag):
    return dict(re.findall(r'([\w:-]+)="([^"]*)"', tag))

def elements(svg):
    out = []
    for m in re.finditer(r'<(path|polygon|rect)\b([^>]*)/?>', svg, re.S):
        kind, a = m.group(1), attrs(m.group(2))
        if kind == 'path':
            d = re.sub(r'\s+', ' ', a.get('d', ''))
        elif kind == 'polygon':
            d = 'M' + re.sub(r'\s+', ' ', a['points'].strip()) + 'Z'
        else:
            x, y, w, h = (float(a.get(k, 0)) for k in ('x', 'y', 'width', 'height'))
            d = f'M{x} {y}h{w}v{h}h{-w}Z'
        out.append((d, a))
    return out

def simple_icon(name):
    return [(d, None) for d, _ in elements(open(f'si-{name}.svg').read())]

marks = {}

# Simple Icons: one monochrome path each, 24x24.
for vendor, si in [('sapb1', 'sap'), ('odoo', 'odoo'), ('sage', 'sage'),
                   ('myob', 'myob'), ('businesscentral', 'dynamics365')]:
    marks[vendor] = simple_icon(si)

# Acumatica: the symbol only (class st1 and the two gradient-filled paths),
# not the wordmark or the "The Cloud ERP" strapline.
acu = open('acumatica.svg').read()
marks['acumatica'] = [(d, None) for d, a in elements(acu)
                      if a.get('class') == 'st1' or 'SVGID' in a.get('style', '')]

# Priority: the three-part P symbol, in its own colours.
marks['priority'] = [(d, a['fill']) for d, a in elements(open('priority.svg').read())
                     if a.get('fill') in ('#3B37E3', '#5ECDF6', '#9E42FF')]

# NetSuite: the two-tone N.
marks['netsuite'] = [(d, a['fill']) for d, a in elements(open('wvl-netsuite.svg').read())
                     if a.get('fill') in ('#baccdb', '#125580')]

# Exact: just the equals sign — the first two subpaths of the wordmark path.
exact_d = elements(open('inl-exact-0.svg').read())[0][0]
subs = re.findall(r'[Mm][^Mm]*', exact_d)
marks['exactonline'] = [(''.join(subs[:2]), None)]

# Visma and Unit4: the whole wordmark.
marks['visma'] = [(d, None) for d, _ in elements(open('inl-visma-0.svg').read())]
marks['unit4'] = [(d, None) for d, a in elements(open('unit4.svg').read())
                  if a.get('class') == 'st2']

# AFAS only publishes a raster logo, so the wordmark is set in Arial Black and
# slanted, which is close to the real heavy oblique grotesk.
font = TTFont('/System/Library/Fonts/Supplemental/Arial Black.ttf')
gs, cmap = font.getGlyphSet(), font.getBestCmap()
upm = font['head'].unitsPerEm
pen, x = SVGPathPen(gs), 0
for ch in 'AFAS':
    name = cmap[ord(ch)]
    # flip y (font units are y-up) and shear 12 degrees to the right
    t = TransformPen(pen, (1, 0, -0.21, -1, x, upm))
    gs[name].draw(t)
    x += gs[name].width - 40  # tighten tracking a touch, as the logo does
marks['afas'] = [(pen.getCommands(), None)]

json.dump(marks, open('marks.json', 'w'), indent=1)
print({k: len(v) for k, v in marks.items()})

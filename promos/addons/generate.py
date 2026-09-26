"""Writes the Plugin Store promo deck for every Erpy add-on.

    python3 generate.py            # all twelve
    python3 generate.py sage odoo  # just these

Each add-on gets a self-contained `promos/` directory in its own repo — slides.html, build.sh,
fonts and icons — so it builds with its own ./build.sh and nothing here needs to exist at
release time. This file and its data are the source; edit these, not the generated decks.

Two inputs:

- connectors.json — every connector's capabilities and connection fields, dumped from the
  connectors themselves by tests/tools/dump-connectors.php. The "what it syncs" and "connecting"
  slides are drawn from it, so a slide cannot claim a flow the connector does not declare.
  Regenerate it whenever a connector's capabilities() or settingsFields() change.
- decks.json — the copy that has to be written by a person: the tagline, the vendor traps the
  connector handles, and a mapping example worth showing for that ERP.
"""
import html, json, os, shutil, sys

HERE = os.path.dirname(os.path.abspath(__file__))
PROMOS = os.path.dirname(HERE)                       # craft-erpy/promos
SITES = os.path.dirname(os.path.dirname(PROMOS))     # ~/Sites

ENTITIES = [
    ('product', 'Products'), ('price', 'Prices'), ('inventory', 'Inventory'),
    ('customer', 'Customers'), ('order', 'Orders'), ('orderStatus', 'Order status'),
    ('shipment', 'Shipments'), ('invoice', 'Invoices'), ('payment', 'Payments'), ('credit', 'Credit'),
]
FAMILY = ['acumatica', 'afas', 'businesscentral', 'exactonline', 'myob', 'netsuite',
          'odoo', 'priority', 'sage', 'sapb1', 'unit4', 'visma']

e = lambda s: html.escape(str(s), quote=True)


def css(vendor_colour):
    return '''
  * { margin: 0; padding: 0; box-sizing: border-box; }
  :root {
    --accent: #2F8F55; --accent-light: #4DB47C; --gold: #ffd166; --off-white: #f0f0f5;
    --vendor: %s;
  }
  html { background: #000; }
  body { font-family: 'Inter', -apple-system, sans-serif; color: var(--off-white); -webkit-font-smoothing: antialiased; }
  .slide {
    position: relative; width: 1920px; height: 1080px; overflow: hidden;
    background:
      radial-gradient(ellipse at 85%% 15%%, rgba(47, 143, 85, 0.22) 0%%, transparent 40%%),
      radial-gradient(ellipse at 65%% 5%%, rgba(77, 180, 124, 0.14) 0%%, transparent 35%%),
      radial-gradient(ellipse at 15%% 85%%, rgba(255, 209, 102, 0.07) 0%%, transparent 40%%),
      linear-gradient(135deg, #0f0f1a 0%%, #101c1a 30%%, #12201c 60%%, #0f0f1a 100%%);
  }
  .slide::after {
    content: ''; position: absolute; top: -18%%; left: -12%%; width: 1180px; height: 1180px;
    background: url('assets/watermark.svg') no-repeat center / contain; opacity: 0.03; pointer-events: none;
  }
  .slide::before {
    content: ''; position: absolute; inset: 0 0 auto 0; height: 6px; z-index: 5;
    background: linear-gradient(90deg, var(--accent), var(--accent-light), var(--gold), var(--accent));
  }
  .cover::after { display: none; }
  .inner { position: relative; z-index: 2; height: 100%%; padding: 118px 130px; display: flex; flex-direction: column; justify-content: center; }
  .eyebrow {
    display: flex; align-items: center; gap: 18px; font-size: 20px; font-weight: 500;
    letter-spacing: 0.32em; text-transform: uppercase; color: rgba(240, 240, 245, 0.62); margin-bottom: 30px;
  }
  .eyebrow .line { width: 58px; height: 3px; border-radius: 2px; background: var(--accent-light); }
  h1, h2 { font-family: 'Jersey 20', sans-serif; font-weight: 400; line-height: 0.9; letter-spacing: 0.005em; }
  h2 { font-size: 104px; margin-bottom: 24px; }
  .gradient-text {
    background: linear-gradient(100deg, #2F8F55 0%%, #4DB47C 38%%, #ffd166 68%%, #2F8F55 100%%);
    -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
  }
  .lede { font-size: 30px; line-height: 1.5; color: rgba(240, 240, 245, 0.72); max-width: 60ch; }
  .mono { font-family: 'JetBrains Mono', ui-monospace, 'SF Mono', Menlo, monospace; }
  .footer {
    position: absolute; left: 130px; bottom: 58px; z-index: 3; display: flex; align-items: center; gap: 18px;
    font-size: 22px; letter-spacing: 0.06em; color: rgba(240, 240, 245, 0.4);
  }
  .footer img { width: 40px; height: 40px; }
  .footer b { color: rgba(240, 240, 245, 0.72); font-weight: 600; }
  .panel { background: rgba(26, 26, 46, 0.72); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 22px; }

  /* cover */
  .cover .inner { flex-direction: row; align-items: center; justify-content: space-between; gap: 80px; }
  .cover h1 { font-size: 150px; margin-bottom: 40px; }
  .cover h1 span { display: block; font-size: 96px; color: rgba(240, 240, 245, 0.5); -webkit-text-fill-color: rgba(240, 240, 245, 0.5); margin-bottom: 8px; }
  .cover .tagline { font-family: 'Jersey 20', sans-serif; font-size: 54px; line-height: 1.05; margin-bottom: 34px; max-width: 22ch; }
  .cover .lede { font-size: 28px; max-width: 36ch; }
  .cover .mark { flex: 0 0 auto; width: 440px; height: 440px; filter: drop-shadow(14px 14px 0 rgba(0, 0, 0, 0.35)); }
  .cover .mark img { width: 100%%; height: 100%%; display: block; }
  .badges { display: flex; gap: 16px; margin-top: 46px; flex-wrap: wrap; }
  .badge {
    display: inline-flex; align-items: center; gap: 12px; padding: 14px 26px; font-size: 22px; font-weight: 500;
    color: rgba(240, 240, 245, 0.85); border: 1px solid rgba(255, 255, 255, 0.12); background: rgba(255, 255, 255, 0.05); border-radius: 999px;
  }
  .badge .dot { width: 10px; height: 10px; border-radius: 50%%; background: var(--accent-light); }

  /* what it syncs */
  .syncs .inner, .engine-slide .inner { justify-content: flex-start; padding-top: 92px; }
  .syncs h2 { font-size: 88px; margin-bottom: 18px; }
  .syncs .lede { font-size: 26px; }
  .matrix { margin-top: 26px; padding: 10px 30px; border-collapse: separate; }
  .matrix table { border-collapse: collapse; width: 100%%; }
  .matrix th { font-size: 20px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(240, 240, 245, 0.5); text-align: center; padding: 14px 18px; }
  .matrix th:first-child { text-align: left; }
  .matrix td { font-size: 25px; padding: 8px 18px; border-top: 1px solid rgba(255, 255, 255, 0.06); text-align: center; }
  .matrix td:first-child { text-align: left; color: var(--off-white); font-weight: 500; }
  .cell { display: inline-flex; align-items: center; gap: 10px; padding: 4px 16px; border-radius: 999px; font-size: 21px; font-weight: 600; }
  .cell.pull { background: rgba(77, 180, 124, 0.14); color: #7fd6a3; }
  .cell.push { background: rgba(255, 209, 102, 0.13); color: var(--gold); }
  .cell.both { background: rgba(140, 170, 255, 0.14); color: #aac0ff; }
  .cell small { font-weight: 500; opacity: 0.7; font-size: 17px; }
  .none { color: rgba(240, 240, 245, 0.18); }
  .legend { display: flex; gap: 26px; margin-top: 22px; font-size: 21px; color: rgba(240, 240, 245, 0.55); align-items: center; }

  /* connecting */
  .split { display: flex; gap: 90px; align-items: center; }
  .split .copy { flex: 0 0 700px; }
  .form { flex: 1 1 auto; padding: 38px 44px; }
  .form .title { display: flex; align-items: center; gap: 16px; font-size: 26px; font-weight: 600; margin-bottom: 26px; }
  .form .title img { width: 44px; height: 44px; }
  .form .field { margin-bottom: 15px; }
  .form label { display: block; font-size: 18px; font-weight: 600; color: rgba(240, 240, 245, 0.7); margin-bottom: 7px; }
  .form label i { color: #f87171; font-style: normal; margin-left: 4px; }
  .form .input { height: 44px; border-radius: 8px; background: rgba(0, 0, 0, 0.28); border: 1px solid rgba(255, 255, 255, 0.1); padding: 0 16px; display: flex; align-items: center; font-size: 19px; color: rgba(240, 240, 245, 0.35); }
  .form .input.secret { letter-spacing: 0.3em; color: rgba(240, 240, 245, 0.55); }
  .form .cols { display: grid; grid-template-columns: 1fr 1fr; column-gap: 22px; }
  .form .more { font-size: 18px; color: rgba(240, 240, 245, 0.4); margin-top: 6px; }

  /* traps */
  .cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 30px; margin-top: 44px; }
  .card { padding: 38px 36px; }
  .card .n { font-family: 'Jersey 20', sans-serif; font-size: 58px; color: var(--vendor-text); line-height: 1; margin-bottom: 18px; }
  .card h3 { font-size: 30px; font-weight: 700; line-height: 1.25; margin-bottom: 16px; }
  .card p { font-size: 23px; line-height: 1.5; color: rgba(240, 240, 245, 0.66); }
  .card code, .lede code { font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; font-size: 0.88em; color: #cfe9da; background: rgba(255, 255, 255, 0.06); padding: 1px 7px; border-radius: 5px; }

  /* mapping */
  .rules { flex: 1 1 auto; padding: 34px 38px; display: flex; flex-direction: column; gap: 16px; }
  .rules .head { display: grid; grid-template-columns: 1fr 60px 1fr; font-size: 17px; letter-spacing: 0.14em; text-transform: uppercase; color: rgba(240, 240, 245, 0.42); padding: 0 24px; }
  .maprule { display: grid; grid-template-columns: 1fr 60px 1fr; align-items: center; padding: 20px 24px; border-radius: 14px; border: 1px solid rgba(255, 255, 255, 0.08); background: rgba(255, 255, 255, 0.03); }
  .maprule .src { font-size: 25px; color: rgba(240, 240, 245, 0.6); }
  .maprule .to { color: var(--accent-light); font-size: 26px; text-align: center; }
  .maprule .dst { font-size: 25px; color: var(--off-white); text-align: right; }
  .maprule .note { grid-column: 1 / -1; font-family: 'Inter', sans-serif; font-size: 19px; color: rgba(240, 240, 245, 0.5); margin-top: 10px; }
  .maprule.canon { background: rgba(47, 143, 85, 0.12); border-color: rgba(85, 188, 121, 0.34); }
  .maprule.canon .dst { color: var(--accent-light); }

  /* engine */
  .engine { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-top: 52px; }
  .engine .item { padding: 30px 32px; }
  .engine h3 { font-size: 27px; font-weight: 700; margin-bottom: 10px; }
  .engine p { font-size: 21px; line-height: 1.5; color: rgba(240, 240, 245, 0.62); }
  .engine-head { display: flex; align-items: center; gap: 44px; }
  .engine-head img { width: 150px; height: 150px; flex: 0 0 auto; filter: drop-shadow(10px 10px 0 rgba(0,0,0,0.35)); }

  /* family */
  .family-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 26px 20px; margin-top: 40px; }
  .fam { display: flex; flex-direction: column; align-items: center; gap: 12px; opacity: 0.42; }
  .fam img { width: 104px; height: 104px; }
  .fam span { font-size: 20px; font-weight: 600; }
  .fam.me { opacity: 1; }
  .fam.me img { filter: drop-shadow(0 0 26px rgba(77, 180, 124, 0.55)); transform: scale(1.12); }
  .term { margin-top: 44px; padding: 26px 34px; font-size: 25px; line-height: 1.75; }
  .term .p { color: var(--accent-light); }
  .term .c { color: rgba(240, 240, 245, 0.45); }

  body.single .slide { display: none; }
  body.single .slide.active { display: block; }
''' % vendor_colour


def footer(v):
    return f'<div class="footer"><img src="assets/icon.svg" alt=""> <b>Erpy for {e(v["name"])}</b> &nbsp;·&nbsp; justinholt.com/plugins/craft-erpy/docs/{e(v["slug"])}</div>'


def slide(n, cls, body, v, foot=True):
    return (f'<!-- {n} ============================================================ -->\n'
            f'<section class="slide {cls}" data-n="{n}">\n  <div class="inner">\n{body}\n  </div>\n'
            + (f'  {footer(v)}\n' if foot else '') + '</section>\n')


def eyebrow(text):
    return f'    <div class="eyebrow"><span class="line"></span> {text}</div>'


def s_cover(v):
    names = ' &nbsp;·&nbsp; '.join(e(c['name']) for c in v['connectors'])
    return slide(1, 'cover', f'''    <div class="copy">
{eyebrow('Erpy add-on &nbsp;·&nbsp; ' + e(v['vendor']))}
      <h1 class="gradient-text"><span>Erpy for</span>{e(v['name'])}</h1>
      <p class="tagline">{v['tagline']}</p>
      <p class="lede">{v['lede']}</p>
      <div class="badges">
        <span class="badge"><span class="dot"></span> Free &nbsp;·&nbsp; requires Erpy</span>
        <span class="badge">Craft 5.3+ · Commerce 5 · PHP 8.2+</span>
      </div>
    </div>
    <div class="mark"><img src="assets/icon.svg" alt=""></div>''', v, foot=False)


def s_syncs(v, conns):
    used = [k for k, _ in ENTITIES if any(k in conns[c['handle']]['capabilities']['entities'] for c in v['connectors'])]
    multi = len(v['connectors']) > 1
    head = '<th>Entity</th>' + ''.join(f'<th>{e(c["short"])}</th>' for c in v['connectors']) if multi else '<th>Entity</th><th>Direction</th><th>Delta</th>'
    rows = []
    for key, label in ENTITIES:
        if key not in used:
            continue
        cells = []
        for c in v['connectors']:
            ent = conns[c['handle']]['capabilities']['entities'].get(key)
            if not ent:
                cells.append('<td><span class="none">—</span></td>')
                continue
            d = ent['direction']
            kind, text = {1: ('pull', '&larr; into Craft'), 2: ('push', 'to the ERP &rarr;'), 3: ('both', '&harr; both ways')}[d]
            delta = ' <small>Δ</small>' if ent.get('delta') and multi else ''
            cells.append(f'<td><span class="cell {kind}">{text}{delta}</span></td>')
            if not multi:
                cells.append('<td>' + ('<span class="cell pull">changes only</span>' if ent.get('delta') else ('<span class="none">—</span>' if d == 2 else '<span class="none">full read</span>')) + '</td>')
        rows.append(f'<tr><td>{label}</td>{"".join(cells)}</tr>')
    extras = []
    caps = [conns[c['handle']]['capabilities'] for c in v['connectors']]
    if any(c['multiCompany'] for c in caps):
        extras.append('Several companies — one connection each')
    if any(c['sandbox'] for c in caps):
        extras.append('Sandbox environments supported')
    legend = ('<span class="cell pull">&larr;</span> pulled into Commerce &nbsp; <span class="cell push">&rarr;</span> pushed to the ERP'
              + (' &nbsp; <span class="cell pull"><small>Δ</small></span> changes only, by watermark' if multi else '')
              + ''.join(f' &nbsp;·&nbsp; {x}' for x in extras))
    return slide(2, 'syncs', f'''{eyebrow('What it syncs')}
    <h2 class="gradient-text">{v.get('syncsHeading', 'Exactly what ' + e(v['vendor']) + ' can do')}</h2>
    <p class="lede">Read from the connector&rsquo;s own declaration &mdash; the connection screen shows this same list, so it cannot offer a flow that was never built.</p>
    <div class="matrix panel"><table><tr>{head}</tr>{''.join(rows)}</table></div>
    <div class="legend">{legend}</div>''', v)


def s_connect(v, conns):
    c = v['connectors'][v.get('formConnector', 0)]
    fields = [f for f in conns[c['handle']]['fields'] if f.get('type') != 'heading']
    shown, rest = fields[:8], fields[8:]
    out = []
    for f in shown:
        req = '<i>*</i>' if f.get('required') else ''
        t = f.get('type')
        if t == 'secret':
            inp = '<div class="input secret">••••••••••••</div>'
        elif t == 'boolean':
            inp = '<div class="input">Off</div>'
        elif t == 'select':
            opts = f.get('options') or []
            opts = list(opts.values()) if isinstance(opts, dict) else opts
            first = (opts[0].get('label', '') if isinstance(opts[0], dict) else opts[0]) if opts else ''
            default = f.get('value') or f.get('default')
            if default and isinstance(f.get('options'), dict) and default in f['options']:
                first = f['options'][default]
            inp = f'<div class="input">{e(first)} ▾</div>'
        else:
            inp = f'<div class="input">{e(f.get("placeholder") or "")}</div>'
        out.append(f'<div class="field"><label>{e(f["label"])}{req}</label>{inp}</div>')
    more = f'<div class="more">+ {len(rest)} optional setting{"s" if len(rest) != 1 else ""} — {", ".join(e(f["label"]) for f in rest[:4])}{"…" if len(rest) > 4 else ""}</div>' if rest else ''
    return slide(3, 'connect', f'''    <div class="split">
      <div class="copy">
{eyebrow('Connecting')}
        <h2 class="gradient-text">{v.get('connectHeading', 'One screen to connect')}</h2>
        <p class="lede">{v['connect']}</p>
      </div>
      <div class="form panel">
        <div class="title"><img src="assets/icon.svg" alt=""> New connection &mdash; {e(c['name'])}</div>
        <div class="cols">{''.join(out)}</div>
        {more}
      </div>
    </div>''', v)


def s_traps(v):
    cards = ''.join(f'''
      <div class="card panel"><div class="n">0{i + 1}</div><h3>{t['title']}</h3><p>{t['body']}</p></div>''' for i, t in enumerate(v['traps']))
    return slide(4, 'traps', f'''{eyebrow('Already handled')}
    <h2 class="gradient-text">{v.get('trapsHeading', 'It knows ' + e(v['vendor']) + '&rsquo;s quirks')}</h2>
    <p class="lede">The things a first {e(v['vendor'])} integration learns the hard way, written into the connector so yours doesn&rsquo;t have to.</p>
    <div class="cards">{cards}
    </div>''', v)


def s_mapping(v):
    m = v['mapping']
    rules = ''.join(f'''
        <div class="maprule{' canon' if r.get('canonical') else ''}"><span class="src mono">{r['src']}</span><span class="to">&rarr;</span><span class="dst mono">{r['dst']}</span>{('<span class="note">' + r['note'] + '</span>') if r.get('note') else ''}</div>''' for r in m['rules'])
    return slide(5, 'mapping', f'''    <div class="split">
      <div class="copy">
{eyebrow('Field mapping')}
        <h2 class="gradient-text">A wrong field is an afternoon, not a release</h2>
        <p class="lede">{m['lede']}</p>
      </div>
      <div class="rules panel">
        <div class="head"><span>Reads</span><span></span><span style="text-align:right">Writes</span></div>{rules}
      </div>
    </div>''', v)


def s_engine(v):
    items = [
        ('The same order, never twice', 'A unique database index on every pairing, not a remembered check that could be raced.'),
        ('Changes only, and none missed', 'Delta watermarks advance to the start of a run, with overlap, so nothing edited mid-run falls in a gap.'),
        ('Preview before it writes', 'A dry run reads a real page and reports what it would create, update or skip.'),
        ('Nothing lost when refused', 'A refused document is kept whole on the Problems screen, ready to retry once the cause is fixed.'),
        ('Checkout never waits', 'Orders are always pushed from the queue, so a slow or down ERP never delays or fails a sale.'),
        ('A log that cannot leak', 'Secrets are stripped from error bodies before a connector, or anyone reading the log, sees them.'),
    ]
    cells = ''.join(f'<div class="item panel"><h3>{a}</h3><p>{b}</p></div>' for a, b in items)
    return slide(6, 'engine-slide', f'''    <div class="engine-head">
      <img src="assets/erpy.svg" alt="">
      <div>
{eyebrow('Erpy does the rest')}
        <h2 class="gradient-text" style="margin-bottom:0">Small connector.<br>Shared engine.</h2>
      </div>
    </div>
    <div class="engine">{cells}</div>''', v)


def s_family(v):
    names = {'acumatica': 'Acumatica', 'afas': 'AFAS', 'businesscentral': 'Business Central', 'exactonline': 'Exact Online',
             'myob': 'MYOB', 'netsuite': 'NetSuite', 'odoo': 'Odoo', 'priority': 'Priority', 'sage': 'Sage',
             'sapb1': 'SAP Business One', 'unit4': 'Unit4', 'visma': 'Visma'}
    grid = ''.join(f'<div class="fam{" me" if k == v["key"] else ""}"><img src="assets/family/{k}.svg" alt=""><span>{names[k]}</span></div>' for k in FAMILY)
    return slide(7, 'family', f'''{eyebrow('Installing')}
    <h2 class="gradient-text">One of twelve free add-ons</h2>
    <p class="lede">Erpy is the paid part &mdash; $149, then $129 a year. Every add-on is free, and one Erpy licence drives any of them.</p>
    <div class="family-grid">{grid}</div>
    <div class="term panel mono"><span class="p">$</span> composer require justinholtweb/craft-erpy justinholtweb/craft-erpy-{e(v['key'])}<br><span class="p">$</span> php craft plugin/install erpy &amp;&amp; php craft plugin/install erpy-{e(v['key'])}</div>''', v)


def deck(v, conns):
    vendor_text = v.get('textColour', v['colour'])
    return f'''<!DOCTYPE html>
<!-- Generated by craft-erpy/promos/addons/generate.py from decks.json and connectors.json.
     Edit those and regenerate; a hand edit here is lost next time. -->
<html lang="en">
<head>
<meta charset="utf-8">
<title>Erpy for {e(v['name'])} — Plugin Store promos</title>
<link rel="stylesheet" href="fonts.css">
<style>{css(v['colour'])}
  :root {{ --vendor-text: {vendor_text}; }}
</style>
</head>
<body>
{s_cover(v)}
{s_syncs(v, conns)}
{s_connect(v, conns)}
{s_traps(v)}
{s_mapping(v)}
{s_engine(v)}
{s_family(v)}
<script>
  var n = new URLSearchParams(location.search).get('s');
  if (n) {{
    document.body.classList.add('single');
    var el = document.querySelector('.slide[data-n="' + n + '"]');
    if (el) el.classList.add('active');
  }}
</script>
</body>
</html>
'''


BUILD = '''#!/usr/bin/env bash
# Renders the Plugin Store promo images from slides.html.
# Output: promos/out/erpy-{key}-promo-N.jpg at 1920x1080 (rendered at 2x, downsampled).
# Promos ship as JPEG, never PNG. slides.html is generated by craft-erpy/promos/addons/generate.py.
set -euo pipefail

cd "$(dirname "$0")"
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
SLIDES=${{1:-"1 2 3 4 5 6 7"}}

# Inline the self-hosted fonts so Chrome's file:// origin rules can't block them.
{{
  echo "@font-face{{font-family:'Inter';font-style:normal;font-weight:400 700;font-display:block;src:url(data:font/woff2;base64,$(base64 < assets/inter-latin.woff2 | tr -d '\\n')) format('woff2');}}"
  echo "@font-face{{font-family:'Jersey 20';font-style:normal;font-weight:400;font-display:block;src:url(data:font/woff2;base64,$(base64 < assets/jersey-20-latin.woff2 | tr -d '\\n')) format('woff2');}}"
}} > fonts.css

mkdir -p out
for n in $SLIDES; do
  "$CHROME" \\
    --headless=new \\
    --disable-gpu \\
    --hide-scrollbars \\
    --allow-file-access-from-files \\
    --force-device-scale-factor=2 \\
    --window-size=1920,1080 \\
    --virtual-time-budget=5000 \\
    --screenshot="out/erpy-{key}-promo-$n.png" \\
    "file://$PWD/slides.html?s=$n" >/dev/null 2>&1
  # Chrome can only write PNG; downsample and convert in one pass, then drop the PNG.
  sips --resampleWidth 1920 -s format jpeg -s formatOptions 90 \\
    "out/erpy-{key}-promo-$n.png" --out "out/erpy-{key}-promo-$n.jpg" >/dev/null
  rm "out/erpy-{key}-promo-$n.png"
  echo "  built out/erpy-{key}-promo-$n.jpg"
done
'''

README = '''# Plugin Store promo images

Seven 1920×1080 JPEGs for the Erpy for {name} listing on the Craft Plugin Store, in the theme of
Erpy's page at [justinholt.com/plugins/craft-erpy](https://justinholt.com/plugins/craft-erpy).

```bash
./build.sh          # all slides
./build.sh "2 4"    # just slides 2 and 4
```

Output lands in `out/` as `erpy-{key}-promo-N.jpg`. `fonts.css` is generated and not checked in.

**`slides.html` is generated — don't edit it here.** Every add-on deck comes from one template in
the Erpy repo, `craft-erpy/promos/addons/generate.py`, so the twelve stay consistent. Slide 2 (what
it syncs) and slide 3 (the connection form) are drawn from the connector's own `capabilities()` and
`settingsFields()`; the rest of the copy is in `craft-erpy/promos/addons/decks.json`. The icon is
generated too, by `craft-erpy/promos/icon-gen.py`.

| # | Slide |
|---|-------|
| 1 | Cover — the add-on's icon, free, requires Erpy |
| 2 | What it syncs, per entity and direction |
| 3 | The connection form |
| 4 | Three {vendor} traps the connector already handles |
| 5 | A mapping rule correcting the connector |
| 6 | What Erpy does for every connector |
| 7 | The twelve add-ons, and how to install this one |
'''


def main(only):
    decks = json.load(open(os.path.join(HERE, 'decks.json')))
    conns = json.load(open(os.path.join(HERE, 'connectors.json')))
    for key, v in decks.items():
        if only and key not in only:
            continue
        v['key'] = key
        out = os.path.join(SITES, f'craft-erpy-{key}', 'promos')
        os.makedirs(os.path.join(out, 'assets', 'family'), exist_ok=True)
        for font in ('inter-latin.woff2', 'jersey-20-latin.woff2'):
            shutil.copy(os.path.join(PROMOS, 'assets', font), os.path.join(out, 'assets', font))
        shutil.copy(os.path.join(PROMOS, 'assets', 'watermark.svg'), os.path.join(out, 'assets', 'watermark.svg'))
        shutil.copy(os.path.join(PROMOS, 'assets', 'icon.svg'), os.path.join(out, 'assets', 'erpy.svg'))
        for k in FAMILY:
            shutil.copy(os.path.join(PROMOS, 'assets', 'addons', f'{k}.svg'), os.path.join(out, 'assets', 'family', f'{k}.svg'))
        # assets/icon.svg is written by icon-gen.py
        open(os.path.join(out, 'slides.html'), 'w').write(deck(v, conns))
        open(os.path.join(out, 'build.sh'), 'w').write(BUILD.format(key=key))
        os.chmod(os.path.join(out, 'build.sh'), 0o755)
        open(os.path.join(out, 'README.md'), 'w').write(README.format(name=v['name'], key=key, vendor=v['vendor']))
        open(os.path.join(out, '.gitignore'), 'w').write('fonts.css\n')
        print('  wrote', os.path.relpath(out, SITES))


if __name__ == '__main__':
    main(sys.argv[1:])

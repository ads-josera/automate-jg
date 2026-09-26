/**
 * Geometry lint of the panel pages (browser-automation skill script).
 *
 * Measures what a user notices at a glance and a screenshot review misses:
 * page scrolling sideways, words split across lines, content cut off by a
 * panel, and the branded header / "Cerrar sesión" missing. Runs every page
 * at five widths. Run it once per role (gestor and administrator), logged in
 * through a one-time login link:
 *
 *   ULI=$(ddev drush uli --name="Gestor Aseguramiento" --no-browser | tail -1)
 *   node <browser-automation>/browser.mjs "$ULI" --script scripts/ui_panel_lint.mjs
 *
 * Optional: DETALLE_ID / CONFIRMAR_ID env vars pick the constancias used for
 * the detail and reprocess confirmation pages (the latter must be in error).
 */
export default async function run(page) {
  const base = new URL(page.url()).origin;
  const pages = { lista: '/admin/aseguramiento/constancias', panel: '/admin/aseguramiento', exportar: '/admin/aseguramiento/export' };
  if (process.env.DETALLE_ID) pages.detalle = '/admin/aseguramiento/constancia/' + process.env.DETALLE_ID;
  if (process.env.CONFIRMAR_ID) pages.confirmacion = '/admin/aseguramiento/constancia/' + process.env.CONFIRMAR_ID + '/reprocess';
  const report = {};
  for (const width of [1440, 1280, 1024, 820, 390]) {
    await page.setViewportSize({ width, height: 900 });
    for (const [name, path] of Object.entries(pages)) {
      await page.goto(base + path, { waitUntil: 'domcontentloaded' });
      const issues = await page.evaluate(() => {
        const out = [];
        if (document.documentElement.scrollWidth > window.innerWidth + 2) out.push('scroll horizontal de página');
        const root = document.querySelector('.aseguramiento-dashboard') || document.body;
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
          const node = walker.currentNode;
          for (const m of (node.nodeValue || '').matchAll(/[^\s]{4,}/g)) {
            const range = document.createRange();
            range.setStart(node, m.index); range.setEnd(node, m.index + m[0].length);
            const lines = new Set([...range.getClientRects()].filter(r => r.width).map(r => Math.round(r.top)));
            if (lines.size > 1 && !/https?:\/\/|www\.|-|@/.test(m[0])) out.push('palabra partida: ' + m[0]);
          }
        }
        // Content cut off: wider than an ancestor that hides overflow, with no
        // scrolling box in between.
        for (const el of document.querySelectorAll('.aseguramiento-dashboard table, .aseguramiento-dashboard img, .aseguramiento-dashboard a, .aseguramiento-dashboard button')) {
          const r = el.getBoundingClientRect();
          if (!r.width) continue;
          for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const ox = getComputedStyle(p).overflowX;
            if (ox === 'auto' || ox === 'scroll') break;
            if (ox === 'hidden' || ox === 'clip') {
              const pr = p.getBoundingClientRect();
              if (r.right > pr.right + 2 || r.left < pr.left - 2) out.push('recortado: ' + el.tagName.toLowerCase() + ' ' + (el.innerText || '').trim().slice(0, 20));
              break;
            }
          }
        }
        const hero = document.querySelector('.aseguramiento-dashboard-hero');
        if (!hero) out.push('SIN encabezado de marca');
        if (![...document.querySelectorAll('a')].some(a => /Cerrar sesión/.test(a.innerText))) out.push('SIN Cerrar sesión');
        return [...new Set(out)];
      });
      if (issues.length) report[`${width} ${name}`] = issues;
    }
  }
  return Object.keys(report).length ? report : 'limpio';
}

/**
 * Geometry lint of the panel pages (browser-automation skill script).
 *
 * Measures what a user notices at a glance and a screenshot review misses:
 * page scrolling sideways, words split across lines, content cut off by a
 * panel, a blank band above the header, text contrast of every button and
 * link while hovered, and the branded header / "Cerrar sesión" missing. Runs every page
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
        // Blank band above the branded header (theme regions left behind).
        const heroEl = document.querySelector('.aseguramiento-dashboard-hero');
        const messages = document.querySelector('[data-drupal-messages] .messages, .messages-list .messages');
        // Only on the branded pages: administrators get Drupal's own header.
        if (heroEl && !messages && document.querySelector('.aseguramiento-standalone') && heroEl.getBoundingClientRect().top + scrollY > 48) out.push('espacio en blanco arriba del encabezado: ' + Math.round(heroEl.getBoundingClientRect().top + scrollY) + 'px');
        const hero = document.querySelector('.aseguramiento-dashboard-hero');
        if (!hero) out.push('SIN encabezado de marca');
        if (![...document.querySelectorAll('a')].some(a => /Cerrar sesión/.test(a.innerText))) out.push('SIN Cerrar sesión');
        return [...new Set(out)];
      });
      // Text contrast of every button and link while hovered (one width).
      if (width === 1280) {
        const count = await page.locator('.aseguramiento-dashboard a:visible, .aseguramiento-dashboard button:visible, .aseguramiento-dashboard input[type=submit]:visible').count();
        for (let i = 0; i < count; i++) {
          const el = page.locator('.aseguramiento-dashboard a:visible, .aseguramiento-dashboard button:visible, .aseguramiento-dashboard input[type=submit]:visible').nth(i);
          await el.hover({ timeout: 2000 }).catch(() => {});
          const result = await el.evaluate((node) => {
            const parse = (c) => (c.match(/[\d.]+/g) || []).map(Number);
            const lum = ([r, g, b]) => [r, g, b].map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }).reduce((a, v, i) => a + v * [0.2126, 0.7152, 0.0722][i], 0);
            // Effective background: blend translucent layers up the tree.
            const layers = [];
            for (let p = node; p; p = p.parentElement) {
              const [r, g, b, a = 1] = parse(getComputedStyle(p).backgroundColor);
              if (a > 0) { layers.push([r, g, b, a]); if (a >= 1) break; }
            }
            let bg = [255, 255, 255];
            for (const [r, g, b, a] of layers.reverse()) bg = [r * a + bg[0] * (1 - a), g * a + bg[1] * (1 - a), b * a + bg[2] * (1 - a)];
            const fg = parse(getComputedStyle(node).color).slice(0, 3);
            const [l1, l2] = [lum(fg), lum(bg)].sort((x, y) => y - x);
            return { ratio: (l1 + 0.05) / (l2 + 0.05), label: (node.innerText || node.value || '').trim().slice(0, 24) };
          });
          if (result.ratio < 4.5) issues.push(`contraste bajo al pasar el puntero (${result.ratio.toFixed(1)}:1): ${result.label}`);
        }
        await page.mouse.move(0, 0);
      }
      if (issues.length) report[`${width} ${name}`] = [...new Set(issues)];
    }
  }
  return Object.keys(report).length ? report : 'limpio';
}

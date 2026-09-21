/*
 * THE CHECKS NO HTTP TEST CAN MAKE — run in a headless browser.
 *
 * A fully green server-side suite sat on top of a menu that opened NOTHING
 * on a real click: Stimulus binds no default event to a `<span>`, and a
 * test that builds its own request never dispatches one. So the things
 * this file asserts are the things only a browser knows — whether a click
 * opens the panel, what `elementFromPoint` returns at its centre, whether
 * the panel tracks its cell through a scroll, and what the card actually
 * measures.
 *
 * HOW TO RUN IT
 *
 *   1. Render the sheet:  SHEET_DUMP_DIR=<dir> vendor/bin/phpunit \
 *        tests/Functional/SheetBrowserDumpTest.php
 *   2. In <dir>, link this bundle's `public/roster.css`, the shell's
 *      `shell.css`, this bundle's `assets/controllers` and a copy of
 *      `@hotwired/stimulus`, rewrite the dumped pages' asset links at
 *      those names, and give each page an importmap plus a module script
 *      that registers `roster--sheet`, `roster--sheet-folds` and
 *      `roster--day-menu`.
 *   3. Serve <dir> on 127.0.0.1:8099 and `node sheet_menu_check.mjs`.
 *
 * It needs `playwright-core` and a Chromium build; it is NOT part of
 * `composer check`, because CI has neither.
 */
import { chromium } from 'playwright-core';

const BASE = 'http://127.0.0.1:8099';
const out = [];
const say = (...a) => { const l = a.join(' '); out.push(l); console.log(l); };

const run = async (weeks, w, h) => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: w, height: h } });
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

  await page.goto(`${BASE}/week-${weeks}w.html`);
  await page.waitForTimeout(500);

  say(`\n===== ${weeks} weeks @ ${w}x${h} =====`);
  if (errors.length) say('JS ERRORS: ' + errors.join(' | '));

  // 1. anchor positioning support + the card/scroller geometry
  const geo = await page.evaluate(() => {
    const card = document.querySelector('.sheetcard');
    const wrap = document.querySelector('.psheetwrap');
    const rows = document.querySelectorAll('.strow');
    const row = rows[0];
    return {
      anchored: CSS.supports('position-anchor: --x'),
      card: card.offsetHeight,
      wrap: wrap.offsetHeight,
      chrome: card.offsetHeight - wrap.offsetHeight,
      sheetmax: wrap.style.getPropertyValue('--sheetmax'),
      rowH: row ? Math.round(row.getBoundingClientRect().height) : 0,
      rowsVisible: row ? Math.floor((wrap.clientHeight - (document.querySelector('.psheetwrap thead')?.offsetHeight || 0)) / row.getBoundingClientRect().height) : 0,
      menus: document.querySelectorAll('.pmenuwrap').length,
      verbs: document.querySelectorAll('.pmenuwrap .dmr').length,
    };
  });
  say(`anchor-positioning supported: ${geo.anchored}`);
  say(`card ${geo.card}px  scroller ${geo.wrap}px  chrome ${geo.chrome}px  --sheetmax ${geo.sheetmax}  row ${geo.rowH}px  rows visible ~${geo.rowsVisible}`);
  say(`cells with a menu: ${geo.menus}  verb rows in the document: ${geo.verbs}`);

  // 2. the height must not depend on the scroll position
  const atZero = await page.evaluate(() => document.querySelector('.sheetcard').offsetHeight);
  await page.evaluate(() => window.scrollTo(0, 400));
  await page.evaluate(() => window.dispatchEvent(new Event('resize')));
  await page.waitForTimeout(120);
  const at400 = await page.evaluate(() => document.querySelector('.sheetcard').offsetHeight);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.evaluate(() => window.dispatchEvent(new Event('resize')));
  await page.waitForTimeout(120);
  say(`card height at scrollY 0 = ${atZero}px, at scrollY 400 = ${at400}px  ${atZero === at400 ? 'SAME (pass)' : 'DIFFERENT (FAIL)'}`);

  // 3. the pinned day-header row does not move when the sheet scrolls 600px
  const headBefore = await page.evaluate(() => document.querySelector('.psheetwrap thead th').getBoundingClientRect().top);
  await page.evaluate(() => { document.querySelector('.psheetwrap').scrollTop = 600; });
  await page.waitForTimeout(150);
  const headAfter = await page.evaluate(() => document.querySelector('.psheetwrap thead th').getBoundingClientRect().top);
  say(`day-header top before ${headBefore.toFixed(1)} after a 600px inner scroll ${headAfter.toFixed(1)}  ${Math.abs(headBefore - headAfter) < 1 ? 'UNCHANGED (pass)' : 'MOVED (FAIL)'}`);
  await page.evaluate(() => { document.querySelector('.psheetwrap').scrollTop = 0; });
  await page.waitForTimeout(100);

  // 4. a real click opens the menu, and elementFromPoint at its centre returns it
  const which = await page.evaluate(() => {
    const wrap = document.querySelector('.psheetwrap');
    const port = wrap.getBoundingClientRect();
    const headH = wrap.querySelector('thead').offsetHeight;
    const hits = [...document.querySelectorAll('.pmenuwrap')].filter(el => {
      const r = el.getBoundingClientRect();
      return r.top >= Math.max(port.top + headH, 0) && r.bottom <= Math.min(port.bottom, window.innerHeight);
    });
    return { total: hits.length, idx: [0, Math.floor(hits.length / 2), hits.length - 1] };
  });
  say(`menu cells fully inside the visible band: ${which.total}`);

  for (const i of which.idx) {
    const r = await page.evaluate((i) => {
      const wrap = document.querySelector('.psheetwrap');
      const port = wrap.getBoundingClientRect();
      const headH = wrap.querySelector('thead').offsetHeight;
      const hits = [...document.querySelectorAll('.pmenuwrap')].filter(el => {
        const b = el.getBoundingClientRect();
        return b.top >= Math.max(port.top + headH, 0) && b.bottom <= Math.min(port.bottom, window.innerHeight);
      });
      const cell = hits[i]?.querySelector('.cl');
      if (!cell) return { skip: true };
      const b = cell.getBoundingClientRect();
      return { skip: false, x: b.left + b.width / 2, y: b.top + b.height / 2 };
    }, i);
    if (r.skip) { say(`row #${i}: no cell`); continue; }

    await page.mouse.click(r.x, r.y);
    await page.waitForTimeout(120);

    const res = await page.evaluate(() => {
      const menu = document.querySelector('.pmenulayer > .pmenu');
      if (!menu) return { open: false };
      const b = menu.getBoundingClientRect();
      const at = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
      const cell = document.querySelector('.cl.cl-on');
      return {
        open: true,
        top: Math.round(b.top), left: Math.round(b.left), w: Math.round(b.width), h: Math.round(b.height),
        hit: !!(at && at.closest('.pmenu')),
        outlined: !!cell && getComputedStyle(cell).outlineWidth === '2px',
        verbs: [...menu.querySelectorAll('.dmr .l')].map(e => e.textContent.trim()),
      };
    });
    if (!res.open) { say(`row #${i}: CLICK OPENED NOTHING (FAIL)`); continue; }
    say(`row #${i}: menu at (${res.left},${res.top}) ${res.w}x${res.h}  elementFromPoint=menu:${res.hit ? 'YES' : 'NO'}  cell outlined:${res.outlined}`);
    if (i === which.idx[0]) say(`         verbs: ${res.verbs.join(' | ')}`);

    // 5. the menu follows a 120px scroll of the sheet
    const before = await page.evaluate(() => { const c = document.querySelector('.cl.cl-on').getBoundingClientRect().top; return { menu: document.querySelector('.pmenulayer > .pmenu').getBoundingClientRect().top, cell: c }; });
    await page.evaluate(() => { document.querySelector('.psheetwrap').scrollTop += 120; });
    await page.waitForTimeout(200);
    const after = await page.evaluate(() => {
      const m = document.querySelector('.pmenulayer > .pmenu');
      if (!m) return null;
      const b = m.getBoundingClientRect();
      const at = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
      const c = document.querySelector('.cl.cl-on');
      return { top: b.top, cell: c ? c.getBoundingClientRect().top : null, hit: !!(at && at.closest('.pmenu')), scrolled: document.querySelector('.psheetwrap').scrollTop };
    });
    if (after === null) { say(`         after a 120px scroll the menu CLOSED`); }
    else say(`         scrolled ${after.scrolled}px: cell moved ${(after.cell - before.cell).toFixed(1)}, menu moved ${(after.top - before.menu).toFixed(1)}  ${Math.abs((after.cell - before.cell) - (after.top - before.menu)) < 1 ? 'TRACKS (pass)' : 'DRIFTED (FAIL)'}  elementFromPoint=menu:${after.hit ? 'YES' : 'NO'}`);

    await page.keyboard.press('Escape');
    await page.evaluate(() => { document.querySelector('.psheetwrap').scrollTop = 0; });
    await page.waitForTimeout(120);
  }

  await browser.close();
};

for (const [weeks, w, h] of [[2, 1440, 806], [2, 1440, 900], [4, 1440, 900]]) {
  await run(weeks, w, h);
}

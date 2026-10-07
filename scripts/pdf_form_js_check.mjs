/**
 * @file
 * Runs the Acrobat scripts of the fillable PDF in a minimal Acrobat model.
 *
 * Acrobat Reader cannot run here, so this executes the very scripts
 * embedded in docs/para-entregar/solicitud_aseguramiento_rellenable.pdf (read from the
 * file, not copied) against a fake document: fields with value/fillColor,
 * "this" = document at the top level, and the Validate event Acrobat fires
 * when a field is committed (while the field still holds its old value).
 *
 * Usage (from the project root, Node 18+):
 *   node scripts/pdf_form_js_check.mjs
 */
import { readFileSync } from 'node:fs';

const pdf = readFileSync(new URL('../docs/para-entregar/solicitud_aseguramiento_rellenable.pdf', import.meta.url));
const raw = pdf.toString('latin1');

// Every "/JS (...)" literal string, unescaped and decoded (UTF-16 or bytes).
function literalAt(start) {
  let depth = 1; let out = []; let i = start;
  while (i < raw.length && depth > 0) {
    let c = raw[i++];
    if (c === '\\') {
      const n = raw[i++];
      if (/[0-7]/.test(n)) { let o = n; while (o.length < 3 && /[0-7]/.test(raw[i])) o += raw[i++]; out.push(parseInt(o, 8)); continue; }
      out.push(({ n: 10, r: 13, t: 9, b: 8, f: 12 })[n] ?? n.charCodeAt(0));
      continue;
    }
    if (c === '(') depth++;
    if (c === ')' && --depth === 0) break;
    out.push(c.charCodeAt(0));
  }
  const bytes = Buffer.from(out);
  return bytes[0] === 0xFE && bytes[1] === 0xFF ? bytes.subarray(2).swap16().toString('utf16le') : bytes.toString('latin1');
}
const scripts = [];
for (let at = raw.indexOf('/JS ('); at !== -1; at = raw.indexOf('/JS (', at + 1)) scripts.push(literalAt(at + 5));
const doc = scripts.find((s) => s.includes('var aaDoc = this;'));

let failures = 0;
const check = (ok, label) => { console.log((ok ? '  OK    ' : '  FALLA ') + label); if (!ok) failures++; };
check(doc !== undefined, 'El PDF trae el script del documento');
if (!doc) process.exit(1);

// Minimal Acrobat: fields, colors, "this" = document at top level.
const RED = ['RGB', 0.984, 0.835, 0.835];
const color = { transparent: ['T'] };
const fields = {};
const field = (name, value = '') => (fields[name] = { name, value, fillColor: ['T'], userName: '' });
['solicitante', 'beneficiario_nombre', 'mercancia_asegurada', 'fecha_inicio_seguro', 'origen_ciudad', 'destino_ciudad',
  'medio_transporte', 'moneda', 'valor_factura', 'gastos_fletes', 'gastos_incrementales', 'seguro_contenedor',
  'suma_asegurada_total', 'origen_pais'].forEach((n) => field(n));
fields.origen_ciudad.value = 'Monterrey';
const Doc = { getField: (n) => fields[n] ?? null };
const api = new Function('color', `${doc}\nreturn { aaColor, aaSumar };`).call(Doc, color);
const isRed = (f) => JSON.stringify(f.fillColor) === JSON.stringify(RED);
// Validate event: the field still has its old value until it is committed.
const commit = (name, value, action) => { action({ target: fields[name], value }); fields[name].value = value; };
const validateAmount = (e) => { if (e.target.name === 'valor_factura') api.aaColor(e.target, e.value); api.aaSumar(e.target.name, e.value); };
const validateCurrency = (e) => { api.aaColor(e.target, e.value); api.aaSumar(null, null, e.value); };

console.log('Obligatorios');
check(isRed(fields.solicitante) && isRed(fields.moneda) && isRed(fields.valor_factura), 'Al abrir: obligatorios vacíos en rojo');
check(!isRed(fields.origen_ciudad) && !isRed(fields.origen_pais), 'Al abrir: lleno u opcional sin rojo');
check(!isRed(fields.suma_asegurada_total), 'La suma no es obligatoria (se calcula)');
commit('solicitante', 'José Pérez', (e) => api.aaColor(e.target, e.value));
check(!isRed(fields.solicitante), 'Al llenarlo se quita el rojo');

console.log('Suma asegurada total');
commit('moneda', 'USD', validateCurrency);
commit('valor_factura', '300000', validateAmount);
check(fields.suma_asegurada_total.value === '300000.00', `Se calcula al escribir el valor factura (${fields.suma_asegurada_total.value})`);
commit('gastos_fletes', '200000', validateAmount);
commit('seguro_contenedor', '100000', validateAmount);
check(fields.suma_asegurada_total.value === '600000.00' && !isRed(fields.suma_asegurada_total), 'Exactamente 600,000 USD: sin rojo (el límite se acepta)');
commit('gastos_incrementales', '0.01', validateAmount);
check(isRed(fields.suma_asegurada_total), 'Un centavo arriba: rojo');
check(/Fuera de rango: USD 25 a 600,000\./.test(fields.suma_asegurada_total.userName), `Su ayuda dice el rango ("${fields.suma_asegurada_total.userName}")`);
commit('moneda', 'PESOS', validateCurrency);
check(!isRed(fields.suma_asegurada_total), 'La misma suma en PESOS está en rango: se quita el rojo al cambiar la moneda');
commit('valor_factura', '', validateAmount);
commit('gastos_fletes', '', validateAmount);
commit('seguro_contenedor', '', validateAmount);
commit('gastos_incrementales', '400', validateAmount);
check(isRed(fields.suma_asegurada_total), 'MXN 400: debajo del mínimo, rojo');
commit('gastos_incrementales', '', validateAmount);
check(fields.suma_asegurada_total.value === '' && !isRed(fields.suma_asegurada_total), 'Sin montos: suma vacía y sin rojo');

console.log('Cada campo llama a su script');
const has = (code) => scripts.includes(code);
check(has('aaColor(event.target, event.value); aaSumar(event.target.name, event.value);'), 'Valor factura: obligatorio y suma');
check(scripts.filter((s) => s === 'aaSumar(event.target.name, event.value);').length === 3, 'Fletes, incrementales y seguro: suma');
check(has('aaColor(event.target, event.value); aaSumar(null, null, event.value);'), 'Moneda: obligatoria y recalcula el rango');

process.exit(failures ? 1 : 0);

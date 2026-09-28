"""Regenerates the filled PDF fixtures used by scripts/pdf_form_check.php.

Each file imitates how a different tool saves a filled form. Run from the
project root after `ddev drush php:script scripts/build_pdf_form.php`, with
pypdf and pikepdf installed (local only; nothing of this runs on the server):

    python3 -m venv /tmp/pdfvenv && /tmp/pdfvenv/bin/pip install pypdf pikepdf
    /tmp/pdfvenv/bin/python scripts/fixtures/pdf-form/generar.py

D (macOS Vista Previa / PDFKit) is made with llenar_vista_previa.swift:

    swiftc -O -o /tmp/llenar scripts/fixtures/pdf-form/llenar_vista_previa.swift
    /tmp/llenar docs/solicitud_aseguramiento_rellenable.pdf \
      scripts/fixtures/pdf-form/D_vista_previa_mac.pdf scripts/fixtures/pdf-form/valores.json
"""
import json
import re

import pikepdf
import pypdf

FORM = 'docs/solicitud_aseguramiento_rellenable.pdf'
OUT = 'scripts/fixtures/pdf-form/'
values = json.load(open(OUT + 'valores.json', encoding='utf-8'))


def fill(source, target, data, incremental):
    writer = pypdf.PdfWriter(source, incremental=True) if incremental else pypdf.PdfWriter(clone_from=source)
    writer.update_page_form_field_values(writer.pages[0], data, auto_regenerate=False)
    writer.write(target)


# A: whole file rewritten.
fill(FORM, OUT + 'A_pypdf_completo.pdf', values, False)
# B: incremental update, like "Guardar" in Acrobat.
fill(FORM, OUT + 'B_pypdf_incremental.pdf', values, True)
# C: object streams and a compressed cross-reference stream.
with pikepdf.open(OUT + 'A_pypdf_completo.pdf') as pdf:
    pdf.save(OUT + 'C_objstm_comprimido.pdf', object_stream_mode=pikepdf.ObjectStreamMode.generate, compress_streams=True)
# E: a further incremental save changes two values; the latest must win.
fill(OUT + 'B_pypdf_incremental.pdf', OUT + 'E_dos_guardados.pdf', {'origen_ciudad': 'Guadalajara', 'moneda': 'PESOS'}, True)
# F: password protected (must be rejected with a clear message).
with pikepdf.open(OUT + 'A_pypdf_completo.pdf') as pdf:
    pdf.save(OUT + 'F_protegido.pdf', encryption=pikepdf.Encryption(owner='dueno', user='abrir', R=4))
# G: startxref points to the wrong place (the reader must scan the objects).
raw = open(OUT + 'A_pypdf_completo.pdf', 'rb').read()
open(OUT + 'G_xref_danada.pdf', 'wb').write(re.sub(rb'startxref\s+\d+', b'startxref\n999999', raw))
# H: a required field left empty (must come back as a correction).
fill(FORM, OUT + 'H_sin_medio_transporte.pdf', dict(values, medio_transporte=''), True)
print('ok')

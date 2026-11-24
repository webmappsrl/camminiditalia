"""Genera resources/img/camminiditalia-logo.svg, il logo del pannello Nova (oc:9031).

Il disco ufficiale di Cammini d'Italia resta invariato (forme e colori copiati, nei `d` solo gli
spazi consecutivi ridotti a uno);
accanto, il nome «Cammini d'Italia» in Instrument Sans SemiBold, convertito in tracciati perché
non dipenda dai font caricati da Nova. Il colore del nome cambia con il tema di Nova: la classe
`dark` su <html> (ThemeDropdown.vue di Nova).

Uso, da fuori del progetto (nessuna dipendenza nel repo):

    uv run --with fonttools --with uharfbuzz python -I build-logo.py \
        <disco.svg> <InstrumentSans-SemiBold.ttf> <uscita.svg>

- disco.svg: l'SVG del logo nell'header di camminiditalia.org (uguale a `logo.svg` del design
  system di Cammini d'Italia), viewBox 0 0 104 105;
- InstrumentSans-SemiBold.ttf: quello del design system di Cammini d'Italia (sha256 in
  notes.md), SIL Open Font License 1.1. La SemiBold su https://github.com/Instrument/instrument-sans
  è un'altra versione: disegna in modo diverso alcune lettere del nome e non riproduce il logo
  committato.

L'altezza delle maiuscole rispetto al disco si cambia con la variabile d'ambiente
CAP_HEIGHT_RATIO (default 0.34). Lo script stampa la larghezza risultante: nell'header di Nova
il logo ha al massimo 200 px (lg:w-60 meno ml-2 e px-4, MainHeader.vue), e
tests/Feature/NovaBrandLogoTest.php lo verifica.
"""

import os
import re
import sys
import xml.etree.ElementTree as ET

import uharfbuzz as hb
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

NAME = "Cammini d’Italia"
NAME_CLASS = "cdi-logo-name"
LIGHT_COLOR = "#BB4613"  # primario-700: testo piccolo su fondo chiaro
DARK_COLOR = "#F07821"  # primario: su fondo scuro
DISC_SIZE = 104.0  # diametro del disco nel suo viewBox
DISC_VIEWBOX_HEIGHT = 105.0
GAP = DISC_SIZE / 5  # area di rispetto del design system: 1/5 del diametro
CAP_HEIGHT_RATIO = float(os.environ.get("CAP_HEIGHT_RATIO", "0.34"))  # maiuscole / disco
HEIGHT_PX = 36  # altezza nell'header di Nova (link h-12 = 48 px)
RIGHT_MARGIN = 2  # margine a destra del nome, nelle unità del viewBox, per gli arrotondamenti

SVG_NS = "http://www.w3.org/2000/svg"


def disc_paths(path):
    root = ET.parse(path).getroot()
    paths = [
        (el.get("d"), el.get("fill"))
        for el in root.iter(f"{{{SVG_NS}}}path")
    ]
    if not paths:
        sys.exit(f"Nessun path trovato in {path}")
    return paths


def name_path(font_path, cap_height):
    font = TTFont(font_path)
    glyph_set = font.getGlyphSet()
    font_cap = font["OS/2"].sCapHeight
    scale = cap_height / font_cap

    blob = hb.Blob.from_file_path(font_path)
    hb_font = hb.Font(hb.Face(blob))
    buf = hb.Buffer()
    buf.add_str(NAME)
    buf.guess_segment_properties()
    hb.shape(hb_font, buf, {"kern": True, "liga": True})

    glyph_order = font.getGlyphOrder()
    pen = SVGPathPen(glyph_set, ntos=lambda v: f"{v:.2f}".rstrip("0").rstrip("."))
    x = 0
    for info, pos in zip(buf.glyph_infos, buf.glyph_positions):
        name = glyph_order[info.codepoint]
        # y del font verso l'alto, y dell'SVG verso il basso: si ribalta l'asse.
        transform = (scale, 0, 0, -scale, (x + pos.x_offset) * scale, -pos.y_offset * scale)
        glyph_set[name].draw(TransformPen(pen, transform))
        x += pos.x_advance
    width = x * scale
    return pen.getCommands(), width


def main(disc_svg, font_path, out_path):
    cap_height = DISC_SIZE * CAP_HEIGHT_RATIO
    d_name, name_width = name_path(font_path, cap_height)

    text_x = DISC_SIZE + GAP
    # Maiuscole centrate sul centro del disco: la linea di base sta mezza cap-height sotto.
    baseline = DISC_VIEWBOX_HEIGHT / 2 + cap_height / 2
    total_width = text_x + name_width + RIGHT_MARGIN

    out = [
        f'<svg xmlns="{SVG_NS}" viewBox="0 0 {total_width:.2f} {DISC_VIEWBOX_HEIGHT:g}" '
        f'role="img" aria-label="{NAME}" style="height:{HEIGHT_PX}px;width:auto">',
        f"<style>.{NAME_CLASS}{{fill:{LIGHT_COLOR}}}.dark .{NAME_CLASS}{{fill:{DARK_COLOR}}}</style>",
    ]
    for d, fill in disc_paths(disc_svg):
        d = re.sub(r"\s+", " ", d).strip()
        out.append(f'<path d="{d}" fill="{fill}"/>')
    out.append(
        f'<g transform="translate({text_x:.2f} {baseline:.2f})">'
        f'<path class="{NAME_CLASS}" d="{d_name}"/></g>'
    )
    out.append("</svg>")

    with open(out_path, "w", encoding="utf-8") as fh:
        fh.write("\n".join(out) + "\n")
    print(f"{out_path}: viewBox {total_width:.2f}x{DISC_VIEWBOX_HEIGHT:g}, "
          f"larghezza a {HEIGHT_PX}px = {total_width * HEIGHT_PX / DISC_VIEWBOX_HEIGHT:.0f}px")


if __name__ == "__main__":
    if len(sys.argv) != 4:
        sys.exit(__doc__)
    main(*sys.argv[1:])

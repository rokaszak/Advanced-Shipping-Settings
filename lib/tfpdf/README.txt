tFPDF 1.33 (based on FPDF 1.85), LGPL
Authors: Ian Back, Tycho Veltmeijer
Source: http://fpdf.org/en/script/script92.php

Used by Advanced Shipping Settings to build the printable pickup order sheet.

Changes made to this bundled copy:
- tfpdf.php and font/unifont/ttfonts.php are placed in the ASS\Lib namespace.
- Font metric cache files are never written to the plugin folder.
- Constants in ttfonts.php are only defined when missing.
- Only DejaVu Sans Condensed (regular and bold) is included. See font/unifont/DejaVu_LICENSE.txt.

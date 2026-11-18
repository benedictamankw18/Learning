# Vendored front-end libraries

These files are served from this repository so the LMS works on a LAN with no
internet access. Nothing here is loaded from a CDN.

| Library | Version | Path | License |
| --- | --- | --- | --- |
| Font Awesome Free | 6.4.0 | `font-awesome/css/all.min.css`, `font-awesome/webfonts/` | Icons: CC BY 4.0, Fonts: SIL OFL 1.1, Code: MIT |
| Chart.js | 4.4.0 | `chart/chart.umd.min.js` | MIT |
| jsPDF | 2.5.1 | `jspdf/jspdf.umd.min.js` | MIT |
| jsPDF-AutoTable | 3.7.0 | `autotable/jspdf.plugin.autotable.min.js` | MIT |
| JSZip | 3.10.1 | `jszip/jszip.min.js` | MIT / GPLv3 (dual) |
| SheetJS (xlsx) | 0.18.x | `xlsx/xlsx.full.min.js` | Apache-2.0 |
| Tailwind CSS Play CDN | 3.4.16 | `tailwindcss/tailwind-play.js` | MIT |
| Bootstrap | 5.3.0 | `bootstrap/css/bootstrap.min.css`, `bootstrap/js/bootstrap.bundle.min.js` | MIT |

## Rules

- Keep the directory layout: `font-awesome/css/all.min.css` references
  `../webfonts/`, so the `css/` and `webfonts/` folders must stay siblings.
- Update this table whenever a file is replaced, and bump the version in the
  table so upgrades are deliberate.
- Do not edit the minified files by hand.

## Source URLs

```
https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css
https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-solid-900.woff2
https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-solid-900.ttf
https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-regular-400.woff2
https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-regular-400.ttf
https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-brands-400.woff2
https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-brands-400.ttf
https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-v4compatibility.woff2
https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-v4compatibility.ttf
https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js
https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js
https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.7.0/jspdf.plugin.autotable.min.js
https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js
https://cdn.tailwindcss.com/3.4.16
https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css
https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js
```

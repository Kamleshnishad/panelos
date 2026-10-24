{{-- Page setup and base rules shared by every quotation PDF template that flows across pages. --}}
{{-- DomPDF: tables only, no flex/grid/calc/CSS variables. A position:fixed element repeats on every page. --}}
@@page { margin: 34px 40px 62px 40px; }
body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; line-height: 1.45; color: #1c1f26; }
table { border-collapse: collapse; width: 100%; }
td, th { vertical-align: top; padding: 0; }
.r { text-align: right; }
.b { font-weight: bold; }
.avoid { page-break-inside: avoid; }
#ftr { position: fixed; left: 0; right: 0; bottom: -44px; border-top: 1px solid #c9ced6; padding-top: 6px; font-size: 8.5px; color: #59616f; }

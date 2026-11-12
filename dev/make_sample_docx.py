#!/usr/bin/env python3
"""
Builds the sample worksheets used by the test-suite and the demo data.

All companies, people, phone numbers and IDs in these files are invented.
The files follow the structure of the real «کاربرگ درخواست اعتبار حمایتی توسعه
محصول/فناوری/شتاب‌دهی» closely (same labels, tables, merged cells, tick-box habits),
including the awkward things real files do: text split into many runs, labels with
Arabic letters, ticks typed as "*", text boxes, symbol-font check boxes.

Usage:  python3 dev/make_sample_docx.py [output-dir]      (default: tests/fixtures)
Needs only the Python standard library.
"""
import os
import random
import sys
import zipfile
from xml.sax.saxutils import escape

W_NS = ('xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" '
        'xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" '
        'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
        'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        'xmlns:v="urn:schemas-microsoft-com:vml" '
        'xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"')

rng = random.Random(1405)


# ---------------------------------------------------------------- inline ----
def run(text, bold=False, hl=False):
    props = '<w:rFonts w:cs="B Nazanin"/>'
    if bold:
        props += '<w:b/><w:bCs/>'
    if hl:
        props += '<w:highlight w:val="yellow"/>'
    props += '<w:rtl/>'
    return '<w:r><w:rPr>%s</w:rPr><w:t xml:space="preserve">%s</w:t></w:r>' % (props, escape(text))


def split_runs(text, bold=False, hl=False, pieces=None):
    """Word saves text in arbitrary fragments; do the same so the reader has to merge them."""
    if pieces is None:
        pieces = 1 if len(text) < 6 else rng.randint(1, 4)
    cuts = sorted(rng.sample(range(1, len(text)), min(pieces - 1, max(0, len(text) - 1)))) if pieces > 1 else []
    out, prev = [], 0
    for c in cuts + [len(text)]:
        if c > prev:
            out.append(run(text[prev:c], bold, hl))
        prev = c
    return ''.join(out)


def sym(font, char):
    return '<w:r><w:sym w:font="%s" w:char="%s"/></w:r>' % (font, char)


def tab():
    return '<w:r><w:tab/></w:r>'


def br():
    return '<w:r><w:br/></w:r>'


def para(content='', style=None, num=None, bold=False, hl=False, tabs=False):
    """content: plain text or pre-built run XML (starting with '<')."""
    ppr = ''
    if style:
        ppr += '<w:pStyle w:val="%s"/>' % style
    if tabs:  # tab *stops* (must not be read as tab characters)
        ppr += '<w:tabs><w:tab w:val="left" w:pos="1644"/><w:tab w:val="left" w:pos="7991"/></w:tabs>'
    if num is not None:
        ppr += '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="%d"/></w:numPr>' % num
    ppr += '<w:bidi/>'
    body = content if content.startswith('<') else (split_runs(content, bold, hl) if content else '')
    return '<w:p><w:pPr>%s</w:pPr>%s</w:p>' % (ppr, body)


# ---------------------------------------------------------------- tables ----
def cell(content='', span=1, vmerge=None, shade=False, bold=None):
    """content: text, list of paragraphs (text or XML), or XML starting with '<w:p' / '<w:tbl'."""
    tcpr = '<w:tcW w:w="0" w:type="auto"/>'
    if span > 1:
        tcpr += '<w:gridSpan w:val="%d"/>' % span
    if vmerge == 'restart':
        tcpr += '<w:vMerge w:val="restart"/>'
    elif vmerge == 'cont':
        tcpr += '<w:vMerge/>'
    if shade:
        tcpr += '<w:shd w:val="clear" w:color="auto" w:fill="EAF1DD"/>'
    if bold is None:
        bold = shade
    if isinstance(content, str) and content.startswith('<w:'):
        inner = content
    else:
        items = content if isinstance(content, list) else [content]
        inner = ''.join(i if i.startswith('<w:p') or i.startswith('<w:tbl') else para(i, bold=bold) for i in items)
    if not inner.rstrip().endswith('</w:p>'):
        inner += '<w:p/>'  # a cell must end with a paragraph
    return '<w:tc><w:tcPr>%s</w:tcPr>%s</w:tc>' % (tcpr, inner)


def row(*cells, header=False):
    trpr = '<w:trPr><w:tblHeader/></w:trPr>' if header else ''
    return '<w:tr>%s%s</w:tr>' % (trpr, ''.join(cells))


def table(rows, bidi=True, cols=2):
    borders = ''.join('<w:%s w:val="single" w:sz="4" w:space="0" w:color="auto"/>' % s
                      for s in ('top', 'left', 'bottom', 'right', 'insideH', 'insideV'))
    tblpr = '<w:tblW w:w="5000" w:type="pct"/><w:tblBorders>%s</w:tblBorders>' % borders
    if bidi:
        tblpr = '<w:bidiVisual/>' + tblpr
    grid = ''.join('<w:gridCol w:w="%d"/>' % (9000 // cols) for _ in range(cols))
    return '<w:tbl><w:tblPr>%s</w:tblPr><w:tblGrid>%s</w:tblGrid>%s</w:tbl>' % (tblpr, grid, ''.join(rows))


def kv_table(pairs):
    return table([row(cell(k, shade=True), cell(v)) for k, v in pairs], cols=2)


def textbox(paragraphs_xml):
    """A text box the way Word writes it: the same content twice (Choice + Fallback)."""
    content = '<w:txbxContent>%s</w:txbxContent>' % paragraphs_xml
    choice = ('<mc:Choice Requires="wps"><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
              '<wp:extent cx="5000000" cy="900000"/><wp:docPr id="1" name="Text Box 1"/>'
              '<a:graphic><a:graphicData uri="http://schemas.microsoft.com/office/word/2010/wordprocessingShape">'
              '<wps:wsp><wps:cNvSpPr txBox="1"/><wps:spPr/><wps:txbx>%s</wps:txbx><wps:bodyPr/></wps:wsp>'
              '</a:graphicData></a:graphic></wp:inline></w:drawing></mc:Choice>' % content)
    fallback = '<mc:Fallback><w:pict><v:shape><v:textbox>%s</v:textbox></v:shape></w:pict></mc:Fallback>' % content
    return '<w:p><w:pPr><w:bidi/></w:pPr><w:r><mc:AlternateContent>%s%s</mc:AlternateContent></w:r></w:p>' % (choice, fallback)


# -------------------------------------------------------------- packaging ----
STYLES = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles %s>
<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="B Nazanin"/><w:sz w:val="22"/><w:szCs w:val="24"/><w:lang w:val="en-US" w:bidi="fa-IR"/></w:rPr></w:rPrDefault></w:docDefaults>
<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>
<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="center"/></w:pPr><w:rPr><w:b/><w:bCs/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:bCs/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="200" w:after="100"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:bCs/><w:sz w:val="26"/><w:szCs w:val="26"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="160" w:after="80"/><w:outlineLvl w:val="2"/></w:pPr><w:rPr><w:b/><w:bCs/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:pPr><w:ind w:left="720"/></w:pPr></w:style>
</w:styles>''' % W_NS

NUMBERING = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering %s>
<w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="hybridMultilevel"/><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="&#8226;"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl></w:abstractNum>
<w:abstractNum w:abstractNumId="1"><w:multiLevelType w:val="hybridMultilevel"/><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%%1."/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl></w:abstractNum>
<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>
<w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>
</w:numbering>''' % W_NS

CONTENT_TYPES = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>
<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>
<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
</Types>'''

RELS = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
</Relationships>'''

DOC_RELS = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>
<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>
</Relationships>'''

CORE = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title></dc:title><dc:creator>sample</dc:creator><cp:lastModifiedBy>sample</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">2026-09-01T08:00:00Z</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">2026-09-03T10:30:00Z</dcterms:modified></cp:coreProperties>'''

APPLICATION_HEADER = '"کاربرگ درخواست اعتبار حمایتی توسعه محصول/ فناوری / شتاب‌دهی" «شبکه تاد پارک فاوا»'
JUDGING_HEADER = ''


def save(path, body_xml, header_text=APPLICATION_HEADER, document_xml=None):
    document = document_xml or ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<w:document %s><w:body>%s'
                                '<w:sectPr><w:headerReference w:type="default" r:id="rId3"/><w:pgSz w:w="11906" w:h="16838"/>'
                                '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/><w:bidi/></w:sectPr>'
                                '</w:body></w:document>') % (W_NS, body_xml)
    header = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<w:hdr %s>%s</w:hdr>'
              % (W_NS, para(header_text, bold=True) if header_text else '<w:p/>'))
    info = lambda name: zipfile.ZipInfo(name, date_time=(2026, 9, 1, 8, 0, 0))  # stable output
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        for name, data in (('[Content_Types].xml', CONTENT_TYPES), ('_rels/.rels', RELS),
                           ('word/document.xml', document), ('word/_rels/document.xml.rels', DOC_RELS),
                           ('word/styles.xml', STYLES), ('word/numbering.xml', NUMBERING),
                           ('word/header1.xml', header), ('docProps/core.xml', CORE)):
            zi = info(name)
            zi.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(zi, data.encode('utf-8'))
    return path


# ---------------------------------------------------- application worksheet ----
NOTES = [
    'این کاربرگ برای ثبت درخواست «اعتبار حمایتی توسعه فناوری» طراحی شده است و باید توسط شرکت‌های مستقر در مراکز فناوری عضو شبکه توسعه اقتصاد دیجیتال (تاد) پارک فاوا تکمیل شود.',
    'در تکمیل کاربرگ ضروری است کلیه اطلاعات با دقت و صحت کامل و مستند ارائه شوند.',
    'در صورت نیاز، افزودن ردیف به جداول بلامانع است.',
]

TRL_LINES = [
    (1, 'مشاهده اصول پایه فناوری (ایده یا پدیده علمی تازه شناخته شده است.)'),
    (2, 'تدوین مفهوم و کاربرد بالقوه فناوری (مشخص شده این ایده چگونه می‌تواند به یک فناوری تبدیل شود.)'),
    (3, 'اثبات مفهومی از طریق آزمایش‌های اولیه (با آزمایش‌های ساده نشان داده شده که ایده از نظر علمی قابل انجام است.)'),
    (4, 'ساخت نمونه آزمایشگاهی (یک نمونه ساده در آزمایشگاه ساخته و عملکرد آن بررسی شده است.)'),
    (5, 'اعتبارسنجی نمونه در محیط شبه‌عملیاتی (نمونه در محیطی آزمایش شده که شبیه شرایط واقعی است.)'),
    (6, 'نمونه مهندسی شده و تست در محیط واقعی (نمونه کامل‌تر ساخته و در محیط واقعی تست‌های مهم انجام شده است.)'),
    (7, 'تست نمونه در محیط عملیاتی واقعی (نمونه ساخته شده در شرایط میدانی مورد تست واقع شده است.)'),
    (8, 'آماده‌سازی برای تولید (فناوری نهایی شده و فرآیند تولید آن آماده است.)'),
    (9, 'استفاده کامل در دنیای واقعی (محصول یا فناوری در عملیات واقعی به طور کامل به کار گرفته شده است.)'),
]

REQUEST_OPTIONS = [
    'ابداع محصول جدید (غیر موجود در بازار فعلی)',
    'ارتقاء فناوری محصول موجود',
    'آمادگی ورود به بازار/جذب سرمایه گزار (تهیه چند نمونه قابل ارائه به بازار-زیر 10 عدد)',
    'توسعه بازار (ارتقاء فروش محصول)',
]


def trl_paragraphs(selected, mark='*', style='typed'):
    """style: typed  -> the applicant replaced ☐ by `mark`
              removed -> the applicant deleted the box of the chosen line
              highlight -> every box stays, the chosen line is highlighted"""
    out = [para('ایده پیشنهادی از نظر سطح بلوغ فناوری در چه مرحله‌ای قرار دارد:', bold=True)]
    for n, text in TRL_LINES:
        chosen = n == selected
        line = '%d TRL: %s' % (n, text)
        if style == 'typed':
            out.append(para((mark if chosen else '☐ ') + line))
        elif style == 'removed':
            out.append(para(line if chosen else '☐ ' + line))
        else:
            out.append(para('☐ ' + line, hl=chosen))
    return out


def request_paragraph(selected, mark='*'):
    parts = []
    for i, opt in enumerate(REQUEST_OPTIONS):
        parts.append(opt + ' ' + (mark if i == selected else '☐'))
    return [para('درخواست یا دستاورد طرح در ورود به این برنامه حمایتی:', bold=True),
            para('          '.join(parts) + '      ')]


def application(d):
    b = []
    b.append(para(tabs=True))
    if d.get('cover_in_textbox'):
        b.append(textbox(para('نام شرکت: ' + d['cover_company']) + para('عنوان طرح فناوری: ' + d['cover_title'])
                         + para('تاریخ تکمیل کاربرگ: ' + d['date'])))
    else:
        b.append(para(run('نام شرکت:') + run(' ') + split_runs(d['cover_company'], bold=True), tabs=True))
        b.append(para(run('عنوان طرح فناوری:') + split_runs(d['cover_title'], bold=True), tabs=True))
        b.append(para('تاریخ تکمیل کاربرگ: ' + d['date'], tabs=True))
    b.append(para('خواهشمند است پیش از تکمیل کاربرگ به موارد زیر توجه نمایید:'))
    for n in NOTES:
        b.append(para(n, style='ListParagraph', num=1))

    b.append(para('مشخصات عمومی متقاضی ', style='Heading1'))
    L = d.get('labels', {})
    b.append(kv_table([
        (L.get('company', 'نام شرکت/ شتاب‌دهنده:'), d['company']),
        ('شناسه ملی:', d['national_id']),
        (L.get('kb', 'وضعیت دانش‌بنیانی:'), d['kb']),
        ('تاریخ تأسیس:', d['established']),
        ('وبسایت رسمی شرکت/شتابدهنده:', d['website']),
        ('تعداد کارکنان و تیم تحقیق و توسعه (فعال در طرح پیشنهادی):', d['staff']),
        ('نام مرکز فناوری محل استقرار:', d['center']),
        ('مدت زمان استقرار در مرکز فناوری:', d['residency']),
        ('رقم (ریال) تسهیلات/گرنت دریافتی به ازای این طرح و محل منابع مالی تامین شده:', d['prior']),
        ('نام مدیرعامل و شماره تماس:', d['ceo']),
    ]))

    b.append(para('اطلاعات تکمیلی- شرکت‌های فناور', style='Heading2'))
    rows = []
    if d.get('title') is not None:
        rows.append(row(cell(para(run('عنوان طرح:', bold=True) + run('  ', bold=True) + split_runs(d['title'], bold=True)), span=2)))
    if d.get('output') is not None:
        rows.append(row(cell([para(run('خروجی اصلی', bold=True) + run(' :', bold=True) + run(' پروژه:', bold=True) + split_runs(d['output'], bold=True)), para(), para()], span=2)))
    rows.append(row(cell(''.join(trl_paragraphs(d['trl'], d.get('trl_mark', '*'), d.get('trl_style', 'typed'))), span=2)))
    rows.append(row(cell(''.join(request_paragraph(d['request'], d.get('request_mark', '*'))), span=2)))
    rows.append(row(cell(''.join([para('شرح مختصر ایده با تاکید بر مراحل (علمی و فنی) تحقیقاتی انجام شده تا کنون:', bold=True)]
                                 + [para(x, bold=True) for x in d['idea']] + [para(), para()])), cell('')))
    b.append(table(rows, cols=2))

    b.append(para('مشخصات تیم پروژه ', style='Heading3'))
    head = ['ردیف', 'نام اعضاء فعال در پروژه', 'وضعیت سهامداری در شرکت', ['تخصص', '(با توجه به نیاز طرح پیشنهادی)'],
            ['سابقه کاری', '(در تخصص مربوطه)'], 'سطح تحصیلات', '* وضعیت همکاری (در پروژه)', '**وضعیت سربازی']
    rows = [row(*[cell(h, shade=True) for h in head], header=True)]
    for i, m in enumerate(d['team'], 1):
        rows.append(row(cell(str(i)), *[cell(x) for x in m]))
    rows.append(row(*[cell('') for _ in head]))
    b.append(table(rows, cols=8))
    b.append(para('*وضعیت همکاری: تمام وقت/ پاره وقت'))
    b.append(para('* *وضعیت سربازی: معاف/ خاتمه سربازی / مشمول'))

    b.append(para('معرفی طرح', style='Heading3'))
    b.append(para('خروجی‌(های) مورد انتظار طرح ', style='Heading1'))
    rows = [row(cell('ردیف', shade=True), cell('خروجی یا خروجی‌های مورد انتظار تحقیق', shade=True))]
    for i, o in enumerate(d['outputs'] + [''], 1):
        rows.append(row(cell(str(i)), cell(o)))
    b.append(table(rows, cols=2))

    b.append(para('چالش‌های اجرای طرح', style='Heading1'))
    b.append(table([row(cell([para(x) for x in d['challenges']] + [para()]))], bidi=False, cols=1))

    b.append(para('سوابق تحقیقاتی مرتبط با طرح (توسط اعضای شرکت)', style='Heading1'))
    head = ['ردیف', 'موضوع پروژه تحقیقاتی ', 'مدت (ماه)', 'تعداد نیروی انسانی پروژه', 'مهمترین نتایج و دستاوردها']
    rows = [row(*[cell(h, shade=True) for h in head])]
    for i, r in enumerate(d['research'], 1):
        rows.append(row(cell(str(i)), *[cell(x) for x in r]))
    rows.append(row(cell(str(len(d['research']) + 1)), cell(''), cell(''), cell(''), cell('')))
    b.append(table(rows, cols=5))

    b.append(para('برآورد هزینه‌های طرح', style='Heading1'))
    head = ['ردیف', 'سرفصل هزینه', 'عنوان هزینه', 'هزینه واحد', 'تعداد', d.get('cost_header', 'هزینه کل (میلیون تومان)')]
    rows = [row(*[cell(h, shade=True) for h in head])]
    for i, group in enumerate(d['costs'], 1):
        for j, line in enumerate(group['lines']):
            vm = None if len(group['lines']) == 1 else ('restart' if j == 0 else 'cont')
            rows.append(row(cell(str(i) if j == 0 else '', vmerge=vm, shade=True), cell(group['head'] if j == 0 else '', vmerge=vm, shade=True),
                            *[cell(x) for x in line]))
    rows.append(row(cell('-', shade=True), cell(d.get('total_label', 'مجموع'), shade=True), cell(d['total'], span=4)))
    b.append(table(rows, cols=6))

    b.append(para('برنامه زمانی'))
    rows = [row(cell('ردیف', vmerge='restart', shade=True), cell('عنوان فعالیت', vmerge='restart', shade=True),
                cell('وزن نسبی %', vmerge='restart', shade=True), cell('خروجی یا نتایج', vmerge='restart', shade=True),
                cell('برآورد زمان و تاریخ شروع و پایان (ماه)', span=12, shade=True)),
            row(cell('', vmerge='cont', shade=True), cell('', vmerge='cont', shade=True), cell('', vmerge='cont', shade=True),
                cell('', vmerge='cont', shade=True), *[cell(str(m), shade=True) for m in range(1, 13)])]
    for i, (name, weight, result, months) in enumerate(d['schedule'], 1):
        rows.append(row(cell(str(i)), cell(name), cell(str(weight)), cell(result), *[cell('*' if m in months else '') for m in range(1, 13)]))
    b.append(table(rows, cols=16))

    b.append(para('اطلاعات تکمیلی – شتاب‌دهنده‌ها', style='Heading2'))
    b.append(table([row(cell('ردیف', shade=True), cell('عناوین', shade=True), cell('توضیحات', shade=True)),
                    row(cell('1'), cell('عنوان شتاب‌دهنده:'), cell('---')),
                    row(cell('2'), cell('تاریخ شروع فعالیت شتاب‌دهی:'), cell('----')),
                    row(cell('3'), cell('نام مدیر شتاب‌دهنده:'), cell('---')),
                    row(cell('4'), cell('ایمیل/ شماره تماس:'), cell('----'))], cols=3))

    b.append(para(' سهامداران و هیأت مدیره', style='Heading3'))
    head = ['نام', 'نوع سهامدار', 'درصد سهام', 'سمت', 'حق امضا', 'شناسه ملی (در صورت حقوقی)']
    rows = [row(*[cell(h, shade=True) for h in head], header=True)]
    for s in d['shareholders']:
        rows.append(row(*[cell(x) for x in s]))
    b.append(table(rows, cols=6))

    b.append(para('معرفی بازار', style='Heading2'))
    head = ['ردیف', 'سؤال', 'ویژه شرکت', 'ویژه شتاب‌دهنده']
    rows = [row(*[cell(h, shade=True) for h in head], header=True)]
    for i, (q, a) in enumerate(d['market']):
        rows.append(row(cell(str(i) if i else ''), cell(q), cell(a), cell('')))
    b.append(table(rows, cols=4))
    return ''.join(b)


SAMPLES = {
    # 1 — complete file, ticks typed as "*" exactly like the real worksheet we received
    'sample-proposal-1.docx': dict(
        cover_company='داده‌پردازان نوآور البرز',
        cover_title='سامانه هوشمند پایش مصرف انرژی ساختمان',
        date='1405/07/12',
        company='شرکت داده‌پردازان نوآور البرز', national_id='10101234567', kb='نوپا نوع ۲', established='1401/03/18',
        website='www.alborz-data.example', staff='9 نفر', center='مرکز رشد فناوری اطلاعات البرز', residency='26 ماه',
        prior='صفر', ceo='سارا نیک‌منش  09120000001',
        title='طراحی و ساخت سامانه هوشمند پایش و بهینه‌سازی مصرف انرژی ساختمان‌های اداری',
        output='سامانه نرم‌افزاری تحت وب به همراه 50 گره سخت‌افزاری پایش انرژی – داشبورد مدیریتی – گزارش صرفه‌جویی ماهانه',
        trl=6, request=1,
        idea=['طراحی گره‌های اندازه‌گیری مبتنی بر ESP32 و پروتکل LoRa', 'توسعه مدل پیش‌بینی مصرف با یادگیری ماشین',
              'پایلوت آزمایشی در دو ساختمان اداری و کاهش 14 درصدی مصرف برق'],
        team=[('سارا نیک‌منش', '60%', 'مدیر فنی و معمار سامانه', '12 سال', 'دکتری', 'تمام وقت', '-'),
              ('بهنام فراهانی', '40%', 'طراح سخت‌افزار و سیستم‌های نهفته', '9 سال', 'کارشناسی ارشد', 'تمام وقت', 'خاتمه سربازی'),
              ('نگار صادقی', 'ندارد', 'توسعه‌دهنده یادگیری ماشین', '5 سال', 'کارشناسی ارشد', 'تمام وقت', '-'),
              ('پویا رستمی', 'ندارد', 'توسعه‌دهنده وب', '3 سال', 'کارشناسی', 'پاره وقت', 'معاف')],
        outputs=['نسخه تجاری سامانه پایش انرژی', '50 گره سخت‌افزاری آماده نصب', 'داشبورد مدیریتی و اپلیکیشن موبایل'],
        challenges=['تأمین قطعات الکترونیکی وارداتی', 'یکپارچه‌سازی با سامانه‌های مدیریت ساختمان موجود', 'جذب نیروی متخصص'],
        research=[('پایش هوشمند مصرف برق', '14', '4', 'ثبت اختراع داخلی و پایلوت موفق')],
        costs=[dict(head='نیروی انسانی', lines=[('دستمزد تیم فنی', '650.000.000', '12', '7.800.000.000'), ('', '', '', '')]),
               dict(head='تامین مواد اولیه و اقلام مصرفی', lines=[('قطعات الکترونیکی و برد', '6.200.000.000', '1', '6.200.000.000'), ('', '', '', ''), ('-', '', '', '')]),
               dict(head='تجهیز زیرساخت‌های فنی و آزمایشگاهی', lines=[('سرور و تجهیزات تست', '', '', '3.000.000.000')]),
               dict(head='برون‌سپاری خدمات ساخت و تولید و آنالیز', lines=[('ساخت قالب و مونتاژ', '', '', '1.500.000.000')]),
               dict(head='سایر', lines=[('', '', '', '')])],
        total='18.500.000.000',
        schedule=[('طراحی نهایی سخت‌افزار', 30, 'نمونه مهندسی گره‌ها', [1, 2, 3]),
                  ('توسعه نرم‌افزار و مدل‌ها', 50, 'نسخه بتا سامانه', [3, 4, 5, 6, 7, 8]),
                  ('پایلوت و تجاری‌سازی', 20, 'استقرار در سه ساختمان', [9, 10, 11])],
        shareholders=[('سارا نیک‌منش', 'اصلی', '60', 'رئیس هیأت مدیره', 'دارد', ''), ('بهنام فراهانی', 'اصلی', '40', 'عضو هیأت مدیره', 'دارد', '')],
        market=[('معرفی بازار داخلی (زیر مجموعه وزارت ارتباطات و فناوری اطلاعات)', 'ساختمان‌های اداری دولتی و خصوصی'),
                ('مصرف‌کنندگان هدف', 'مدیران تأسیسات و شرکت‌های خدمات انرژی'), ('رقبای موجود / تأمین‌کنندگان فعلی', 'دو شرکت داخلی با محصول وارداتی'),
                ('حجم بازار هدف', 'حدود 12 هزار ساختمان اداری'), ('مزیت رقابتی', 'بومی بودن، قیمت یک‌سوم نمونه خارجی، مدل پیش‌بینی اختصاصی'),
                ('مشتریان بالقوه', 'شهرداری‌ها، بانک‌ها، دانشگاه‌ها'), ('استراتژی ورود به بازار', 'پایلوت رایگان و قرارداد اشتراک سالانه'),
                ('استراتژی فروش', 'فروش مستقیم سازمانی و همکاری با شرکت‌های خدمات انرژی')],
    ),
    # 2 — harder: cover in a text box, Arabic letters in labels, ☒ ticks, ☑ after the option, total in million tomans
    'sample-proposal-2.docx': dict(
        cover_in_textbox=True,
        cover_company='زیست‌فناوران سبز خزر',
        cover_title='کیت تشخیص سریع آلودگی آب',
        date='1405/06/29',
        labels={'company': 'نام شركت / شتاب دهنده :', 'kb': 'وضعيت دانش بنياني:'},
        company='زیست‌فناوران سبز خزر (سهامی خاص)', national_id='14009876543', kb='فناور', established='1402/11/02',
        website='https://khazar-bio.example', staff='6 نفر (4 نفر در طرح)', center='مرکز نوآوری زیست‌فناوری گیلان', residency='11 ماه',
        prior='---', ceo='دکتر مریم حسین‌زاده - 0913 000 0002',
        title='توسعه و تولید نیمه‌صنعتی کیت تشخیص سریع آلودگی میکروبی آب',
        output='کیت تشخیص 20 دقیقه‌ای به همراه دستگاه خوانشگر قابل حمل',
        trl=4, trl_mark='☒ ', request=0, request_mark='☑',
        idea=['جداسازی و تثبیت آنزیم نشانگر', 'ساخت نمونه آزمایشگاهی خوانشگر نوری'],
        team=[('مریم حسین‌زاده', '70%', 'زیست‌فناوری میکروبی', '15 سال', 'دکتری', 'تمام وقت', '-'),
              ('کاوه امیری', '30%', 'مهندسی پزشکی', '7 سال', 'کارشناسی ارشد', 'تمام وقت', 'خاتمه سربازی')],
        outputs=['کیت تشخیص سریع', 'دستگاه خوانشگر قابل حمل'],
        challenges=['دریافت مجوز از سازمان غذا و دارو', 'پایداری آنزیم در دمای محیط'],
        research=[('زیست‌حسگرهای آنزیمی', '24', '3', 'دو مقاله و یک نمونه آزمایشگاهی')],
        cost_header='هزینه کل (میلیون تومان)',
        costs=[dict(head='نیروی انسانی', lines=[('دستمزد', '90', '12', '1,080')]),
               dict(head='تامین مواد اولیه و اقلام مصرفی', lines=[('مواد آزمایشگاهی', '', '', '870')]),
               dict(head='تجهیز زیرساخت‌های فنی و آزمایشگاهی', lines=[('دستگاه‌ها', '', '', '500')])],
        total='2,450', total_label='جمع کل',
        schedule=[('بهینه‌سازی فرمولاسیون', 40, 'فرمول نهایی', [1, 2, 3, 4]), ('ساخت خوانشگر', 40, 'ده دستگاه', [4, 5, 6, 7, 8]),
                  ('اعتبارسنجی میدانی', 20, 'گزارش اعتبارسنجی', [9, 10, 11, 12])],
        shareholders=[('مریم حسین‌زاده', 'اصلی', '70', 'مدیرعامل', 'دارد', '')],
        market=[('معرفی بازار داخلی (زیر مجموعه وزارت ارتباطات و فناوری اطلاعات)', '---'), ('مصرف‌کنندگان هدف', 'شرکت‌های آب و فاضلاب'),
                ('مزیت رقابتی', 'زمان پاسخ 20 دقیقه در برابر 24 ساعت روش کشت')],
    ),
    # 3 — sparse: company only on the cover, no main-output row, chosen TRL line has its box deleted
    'sample-proposal-3.docx': dict(
        cover_company='گروه فناوری آوای کویر',
        cover_title='پلتفرم آموزش مجازی مهارت‌های فنی',
        date='1405/08/02',
        company='', national_id='', kb='', established='', website='', staff='', center='مرکز رشد یزد', residency='', prior='', ceo='',
        title=None, output=None,
        trl=3, trl_style='removed', request=3, request_mark='*',
        idea=['نسخه اولیه با 12 دوره آموزشی'],
        team=[('حامد دهقان', 'ندارد', 'تولید محتوا', '4 سال', 'کارشناسی', 'تمام وقت', 'معاف')],
        outputs=['اپلیکیشن موبایل آموزش مهارت', 'پنل مدرسان'],
        challenges=['بازاریابی و جذب کاربر'],
        research=[],
        cost_header='هزینه کل (ریال)',
        costs=[dict(head='نیروی انسانی', lines=[('دستمزد', '', '', '4.000.000.000')])],
        total='6.500.000.000',
        schedule=[('تولید محتوا', 100, 'صد ساعت ویدیو', [1, 2, 3, 4, 5, 6])],
        shareholders=[],
        market=[('مصرف‌کنندگان هدف', 'هنرجویان فنی و حرفه‌ای')],
    ),
}


def judging_sheet():
    """The older judging worksheet: the three fields sit in single-cell rows; ticks are symbol-font glyphs."""
    b = [para('کاربرگ داوری (حوزه فنی) برنامه "ارائه تسهیلات توسعه محصول/ فناوری" شبکه تاد پارک فاوا', bold=True),
         para('داور محترم، با سلام و احترام'),
         para('مشخصات طرح:', style='Heading1'),
         table([row(cell(para(run('عنوان کامل طرح:  ', bold=True) + split_runs('دستگاه خشک‌کن خورشیدی محصولات کشاورزی')))),
                row(cell(para(run('نام شرکت/ تیم: ', bold=True) + split_runs('صنایع نوین دشت مغان')))),
                row(cell(para(run('خروجی اصلی مورد انتظار طرح: ', bold=True) + split_runs('ساخت سه دستگاه خشک‌کن با ظرفیت 500 کیلوگرم'))))], cols=1),
         para('لطفا جدول مشخصات مربوط به طرح را در زیر تکمیل فرمایید.', style='Heading1')]
    def opts(items, chosen, checked=('Wingdings 2', 'F0A2'), unchecked=('Wingdings 2', 'F0A3')):
        return ''.join(para(sym(*(checked if i == chosen else unchecked)) + run(' ' + t)) for i, t in enumerate(items))
    trl = ['مشاهده اصول پایه فناوری', 'تدوین مفهوم و کاربرد بالقوه فناوری', 'اثبات مفهومی از طریق آزمایش‌های اولیه', 'ساخت نمونه آزمایشگاهی',
           'اعتبارسنجی نمونه در محیط شبه‌عملیاتی', 'نمونه مهندسی شده و تست در محیط واقعی', 'تست نمونه در محیط عملیاتی واقعی',
           'آماده‌سازی برای تولید', 'استفاده کامل در دنیای واقعی']
    b.append(table([
        row(cell('معیار ارزیابی', shade=True), cell('گزینه مورد تایید', shade=True), cell('توضیحات', shade=True)),
        row(cell('طرح پیشنهادی برای'), cell(opts(['رسیدن به یک محصول جدید است.', 'ارتقای فنی یک محصول موجود است.', 'توسعه بازار محصول موجود'], 1)), cell('')),
        row(cell('سطح آمادگی فناوری محصول '), cell(opts(trl, 6, checked=('Wingdings', 'F06E'), unchecked=('Wingdings', 'F06F'))), cell('')),
    ], cols=3))
    b.append(para('با توجه به جمیع اطلاعات فوق، نظر شما درخصوص حمایت پارک فاوا از این طرح چیست؟', style='Heading1'))
    b.append(para('توصیه می شود.'))
    return ''.join(b)


def main():
    out = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'tests', 'fixtures')
    os.makedirs(out, exist_ok=True)
    made = []
    for name, data in SAMPLES.items():
        made.append(save(os.path.join(out, name), application(data)))
    made.append(save(os.path.join(out, 'old-judging-sheet.docx'), judging_sheet(), header_text=''))
    made.append(save(os.path.join(out, 'empty.docx'), para()))
    # a file that tries to smuggle in a DTD/entity (must be refused)
    evil = ('<?xml version="1.0"?>\n<!DOCTYPE w:document [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>\n'
            '<w:document %s><w:body><w:p><w:r><w:t>&xxe;</w:t></w:r></w:p></w:body></w:document>' % W_NS)
    made.append(save(os.path.join(out, 'evil-doctype.docx'), '', document_xml=evil))
    with open(os.path.join(out, 'not-a-docx.docx'), 'w', encoding='utf-8') as f:
        f.write('this is plain text, not a Word file\n')
    made.append(os.path.join(out, 'not-a-docx.docx'))
    for m in made:
        print('%7d  %s' % (os.path.getsize(m), os.path.relpath(m)))


if __name__ == '__main__':
    main()

import 'dart:math' as math;

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/config/app_config.dart';
import '../../core/theme/app_theme.dart';

/// Renders the HTML the admin panel's editor saves (CMS pages such as Terms,
/// Privacy and About) as native, theme-styled text, instead of printing the
/// markup itself.
///
/// Deliberately small. It understands the tags `Str::sanitizeHtml()` keeps
/// on the server: paragraphs, headings, lists, quotes, links, images,
/// tables, code and rules. It drops script-like elements outright, and
/// renders any other tag's text without the tag. Content with no markup is
/// read as plain text, with blank lines as paragraph breaks, so a page typed
/// before the editor existed still reads properly.
class ZHtml extends StatefulWidget {
  const ZHtml(
    this.html, {
    super.key,
    this.style,
    this.selectable = true,
    this.onLinkTap,
  });

  final String html;

  /// The body text style. Headings, code and captions derive from it.
  final TextStyle? style;

  /// Wraps the content in a [SelectionArea] so passages can be copied.
  final bool selectable;

  /// Called with a link's `href`. Defaults to opening it outside the app.
  final ValueChanged<String>? onLinkTap;

  @override
  State<ZHtml> createState() => _ZHtmlState();
}

class _ZHtmlState extends State<ZHtml> {
  late List<_Node> _nodes = _parseHtml(widget.html);

  /// Link recognizers made by the last build. They are owned here, because a
  /// [TextSpan] never disposes the recognizer it is given.
  final List<TapGestureRecognizer> _recognizers = [];

  @override
  void didUpdateWidget(ZHtml oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.html != widget.html) _nodes = _parseHtml(widget.html);
  }

  @override
  void dispose() {
    _disposeRecognizers();
    super.dispose();
  }

  void _disposeRecognizers() {
    for (final recognizer in _recognizers) {
      recognizer.dispose();
    }
    _recognizers.clear();
  }

  Future<void> _open(String href) async {
    if (widget.onLinkTap != null) {
      widget.onLinkTap!(href);
      return;
    }
    final uri = Uri.tryParse(href);
    if (uri == null) return;
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  }

  @override
  Widget build(BuildContext context) {
    _disposeRecognizers();

    final base = (widget.style ?? AppTypography.bodyLarge).copyWith(
      color: widget.style?.color ?? AppColors.onSurface,
    );

    final blocks = _BlockBuilder(
      base: base,
      recognizers: _recognizers,
      onLink: _open,
    ).build(_nodes);

    final column = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: blocks,
    );

    return widget.selectable ? SelectionArea(child: column) : column;
  }
}

// ---------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------

sealed class _Node {}

final class _Text extends _Node {
  _Text(this.text);
  final String text;
}

final class _Element extends _Node {
  _Element(this.tag, [this.attributes = const {}]);
  final String tag;
  final Map<String, String> attributes;
  final List<_Node> children = [];
}

/// Elements that never have content, so never go on the open-element stack.
const _voidTags = {'br', 'hr', 'img', 'wbr', 'col', 'source', 'input', 'meta', 'link'};

/// Elements whose content must never be shown, let alone run.
const _droppedTags = {
  'script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template',
  'svg', 'head', 'title', 'form', 'button', 'select', 'textarea',
};

/// Elements that start a new block rather than flowing inside a line.
const _blockTags = {
  'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
  'blockquote', 'pre', 'hr', 'table', 'thead', 'tbody', 'tfoot', 'tr',
  'figure', 'figcaption', 'img', 'section', 'article', 'header', 'footer',
  'aside', 'nav', 'main', 'dl', 'dt', 'dd',
};

final _attributePattern = RegExp(
  r'''([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+)))?''',
);

/// Parses [html] into a tree, forgiving the usual hand-edited slips: an
/// unclosed `<li>` or `<p>`, a stray closing tag, a bare `<`.
List<_Node> _parseHtml(String html) {
  if (!html.contains('<')) return _plainText(html);

  final root = _Element('#root');
  final stack = <_Element>[root];
  final lower = html.toLowerCase();
  final text = StringBuffer();
  final length = html.length;
  var i = 0;

  void flushText() {
    if (text.isEmpty) return;
    stack.last.children.add(_Text(decodeHtmlEntities(text.toString())));
    text.clear();
  }

  while (i < length) {
    final char = html[i];

    if (char != '<') {
      text.write(char);
      i++;
      continue;
    }

    if (html.startsWith('<!--', i)) {
      final end = html.indexOf('-->', i + 4);
      i = end < 0 ? length : end + 3;
      continue;
    }

    if (i + 1 < length && (html[i + 1] == '!' || html[i + 1] == '?')) {
      final end = html.indexOf('>', i);
      i = end < 0 ? length : end + 1;
      continue;
    }

    final closing = i + 1 < length && html[i + 1] == '/';
    final nameStart = closing ? i + 2 : i + 1;
    var nameEnd = nameStart;
    while (nameEnd < length && _isNameChar(html.codeUnitAt(nameEnd))) {
      nameEnd++;
    }

    // "a < b" in running text: a literal angle bracket, not a tag.
    if (nameEnd == nameStart) {
      text.write('<');
      i++;
      continue;
    }

    // The tag ends at the first `>` outside a quoted attribute value.
    var tagEnd = nameEnd;
    String? quote;
    while (tagEnd < length) {
      final c = html[tagEnd];
      if (quote != null) {
        if (c == quote) quote = null;
      } else if (c == '"' || c == "'") {
        quote = c;
      } else if (c == '>') {
        break;
      }
      tagEnd++;
    }

    final tag = lower.substring(nameStart, nameEnd);
    final inner = html.substring(nameEnd, math.min(tagEnd, length));
    i = tagEnd < length ? tagEnd + 1 : length;
    flushText();

    if (closing) {
      for (var depth = stack.length - 1; depth > 0; depth--) {
        if (stack[depth].tag == tag) {
          stack.removeRange(depth, stack.length);
          break;
        }
      }
      continue;
    }

    if (_droppedTags.contains(tag)) {
      final close = lower.indexOf('</$tag', i);
      final end = close < 0 ? -1 : html.indexOf('>', close);
      i = end < 0 ? length : end + 1;
      continue;
    }

    // HTML's implied end tags, which editors lean on: a new list item closes
    // the open one, and no block can live inside a paragraph.
    if (tag == 'li') _closeOpen(stack, 'li', boundary: const {'ul', 'ol'});
    if (_blockTags.contains(tag) && stack.last.tag == 'p') stack.removeLast();

    final element = _Element(tag, _attributes(inner));
    stack.last.children.add(element);

    final selfClosing = inner.trimRight().endsWith('/');
    if (!selfClosing && !_voidTags.contains(tag)) stack.add(element);
  }

  flushText();
  return root.children;
}

bool _isNameChar(int c) =>
    (c >= 0x61 && c <= 0x7A) || // a-z
    (c >= 0x41 && c <= 0x5A) || // A-Z
    (c >= 0x30 && c <= 0x39) || // 0-9
    c == 0x2D; // -

/// Closes the innermost open [tag], unless a [boundary] element is nearer,
/// so an `<li>` in a nested list does not close its parent list's item.
void _closeOpen(
  List<_Element> stack,
  String tag, {
  Set<String> boundary = const {},
}) {
  for (var depth = stack.length - 1; depth > 0; depth--) {
    final open = stack[depth].tag;
    if (boundary.contains(open)) return;
    if (open == tag) {
      stack.removeRange(depth, stack.length);
      return;
    }
  }
}

Map<String, String> _attributes(String source) {
  final attributes = <String, String>{};
  for (final match in _attributePattern.allMatches(source)) {
    final name = match.group(1)!.toLowerCase();
    final value = match.group(2) ?? match.group(3) ?? match.group(4) ?? '';
    attributes[name] = decodeHtmlEntities(value);
  }
  return attributes;
}

/// Plain text: blank lines separate paragraphs, single newlines are kept.
List<_Node> _plainText(String source) {
  final paragraphs = source.replaceAll('\r\n', '\n').split(RegExp(r'\n\s*\n'));
  final nodes = <_Node>[];

  for (final paragraph in paragraphs) {
    final trimmed = paragraph.trim();
    if (trimmed.isEmpty) continue;

    final element = _Element('p');
    final lines = trimmed.split('\n');
    for (var i = 0; i < lines.length; i++) {
      if (i > 0) element.children.add(_Element('br'));
      element.children.add(_Text(decodeHtmlEntities(lines[i])));
    }
    nodes.add(element);
  }

  return nodes;
}

const _namedEntities = {
  'amp': '&', 'lt': '<', 'gt': '>', 'quot': '"', 'apos': "'", 'nbsp': ' ',
  'rsquo': '’', 'lsquo': '‘', 'rdquo': '”', 'ldquo': '“',
  'ndash': '–', 'mdash': '—', 'hellip': '…', 'bull': '•',
  'middot': '·', 'copy': '©', 'reg': '®', 'trade': '™',
  'deg': '°', 'times': '×', 'divide': '÷', 'laquo': '«',
  'raquo': '»', 'euro': '€', 'pound': '£', 'yen': '¥',
  'cent': '¢', 'sect': '§', 'para': '¶', 'larr': '←',
  'rarr': '→', 'uarr': '↑', 'darr': '↓', 'check': '✓',
};

final _entityPattern = RegExp(r'&(#[xX][0-9a-fA-F]+|#[0-9]+|[a-zA-Z][a-zA-Z0-9]*);');

/// Decodes named and numeric character references, once. An escaped entity
/// such as `&amp;lt;` comes out as `&lt;`, exactly as a browser shows it.
String decodeHtmlEntities(String value) {
  if (!value.contains('&')) return value;

  return value.replaceAllMapped(_entityPattern, (match) {
    final body = match.group(1)!;

    if (body.startsWith('#')) {
      final hex = body.length > 1 && (body[1] == 'x' || body[1] == 'X');
      final code = int.tryParse(
        hex ? body.substring(2) : body.substring(1),
        radix: hex ? 16 : 10,
      );
      if (code == null || code <= 0 || code > 0x10FFFF) return match.group(0)!;
      return String.fromCharCode(code);
    }

    return _namedEntities[body] ?? _namedEntities[body.toLowerCase()] ?? match.group(0)!;
  });
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

/// A run of inline text with one style and, for links, one recognizer.
class _Run {
  const _Run(this.text, this.style, {this.link, this.isBreak = false});

  final String text;
  final TextStyle style;
  final GestureRecognizer? link;
  final bool isBreak;
}

class _BlockBuilder {
  _BlockBuilder({
    required this.base,
    required this.recognizers,
    required this.onLink,
  });

  final TextStyle base;
  final List<TapGestureRecognizer> recognizers;
  final ValueChanged<String> onLink;

  List<Widget> build(List<_Node> nodes) => _blocks(nodes, base);

  /// Lays out a list of nodes: inline content gathers into paragraphs, and
  /// each block element becomes its own widget.
  List<Widget> _blocks(
    List<_Node> nodes,
    TextStyle style, {
    double gap = AppSpacing.sm,
  }) {
    final out = <Widget>[];
    final runs = <_Run>[];

    void flush() {
      final span = _span(runs, style);
      runs.clear();
      if (span != null) {
        out.add(Padding(
          padding: EdgeInsets.only(bottom: gap),
          child: Text.rich(span),
        ));
      }
    }

    for (final node in nodes) {
      if (node is _Element && _blockTags.contains(node.tag)) {
        flush();
        out.addAll(_block(node, style, gap: gap, atStart: out.isEmpty));
      } else {
        _inline(node, style, runs);
      }
    }
    flush();

    return out;
  }

  List<Widget> _block(
    _Element element,
    TextStyle style, {
    required double gap,
    required bool atStart,
  }) {
    switch (element.tag) {
      case 'h1' || 'h2' || 'h3' || 'h4' || 'h5' || 'h6':
        final runs = <_Run>[];
        final headingStyle = _heading(element.tag);
        for (final child in element.children) {
          _inline(child, headingStyle, runs);
        }
        final span = _span(runs, headingStyle);
        if (span == null) return const [];
        return [
          Padding(
            padding: EdgeInsets.only(
              top: atStart ? 0 : AppSpacing.md,
              bottom: AppSpacing.xs,
            ),
            child: Semantics(header: true, child: Text.rich(span)),
          ),
        ];

      case 'ul' || 'ol':
        return [_list(element, style, ordered: element.tag == 'ol', gap: gap)];

      case 'li':
        // A list item with no list around it still reads as one.
        return [_listItem('•', element.children, style)];

      case 'blockquote':
        return [
          Container(
            margin: EdgeInsets.only(bottom: gap),
            padding: const EdgeInsets.only(left: AppSpacing.sm),
            decoration: BoxDecoration(
              border: Border(
                left: BorderSide(color: AppColors.primary, width: 3),
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: _blocks(
                element.children,
                style.copyWith(
                  color: AppColors.onSurfaceVariant,
                  fontStyle: FontStyle.italic,
                ),
                gap: AppSpacing.xs,
              ),
            ),
          ),
        ];

      case 'pre':
        return [_pre(element, style, gap: gap)];

      case 'hr':
        return [
          Padding(
            padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
            child: Divider(height: 1, color: AppColors.outlineVariant),
          ),
        ];

      case 'img':
        final image = _image(element);
        return image == null
            ? const []
            : [Padding(padding: EdgeInsets.only(bottom: gap), child: image)];

      case 'table' || 'thead' || 'tbody' || 'tfoot' || 'tr':
        return [_table(element, style, gap: gap)];

      case 'figcaption':
        return _blocks(
          element.children,
          style.copyWith(
            fontSize: (style.fontSize ?? 16) * 0.85,
            color: AppColors.onSurfaceVariant,
          ),
          gap: gap,
        );

      case 'dt':
        return _blocks(
          element.children,
          style.copyWith(fontWeight: FontWeight.w700),
          gap: 2,
        );

      case 'dd':
        return [
          Padding(
            padding: const EdgeInsets.only(left: AppSpacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: _blocks(element.children, style, gap: gap),
            ),
          ),
        ];

      default:
        // p, div, section, figure and the rest: a container of blocks.
        return _blocks(element.children, style, gap: gap);
    }
  }

  TextStyle _heading(String tag) {
    final color = base.color ?? AppColors.onSurface;
    final style = switch (tag) {
      'h1' => AppTypography.headlineSmall.copyWith(fontWeight: FontWeight.w700),
      'h2' => AppTypography.titleMedium,
      'h3' => AppTypography.titleSmall.copyWith(fontSize: 16, fontWeight: FontWeight.w700),
      _ => AppTypography.titleSmall.copyWith(fontWeight: FontWeight.w700),
    };
    return style.copyWith(color: color, height: 1.35);
  }

  Widget _list(
    _Element list,
    TextStyle style, {
    required bool ordered,
    required double gap,
  }) {
    final items = list.children.whereType<_Element>().where((e) => e.tag == 'li');
    var number = int.tryParse(list.attributes['start'] ?? '') ?? 1;

    return Padding(
      padding: EdgeInsets.only(bottom: gap),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final item in items)
            _listItem(ordered ? '${number++}.' : '•', item.children, style),
        ],
      ),
    );
  }

  Widget _listItem(String marker, List<_Node> children, TextStyle style) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 26,
            child: Text(
              marker,
              style: style.copyWith(
                color: AppColors.primary,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: _blocks(children, style, gap: 2),
            ),
          ),
        ],
      ),
    );
  }

  Widget _pre(_Element element, TextStyle style, {required double gap}) {
    final buffer = StringBuffer();
    void collect(_Node node) {
      if (node is _Text) {
        buffer.write(node.text);
      } else if (node is _Element) {
        if (node.tag == 'br') buffer.write('\n');
        node.children.forEach(collect);
      }
    }

    element.children.forEach(collect);
    final text = buffer.toString().replaceFirst(RegExp(r'^\n'), '').trimRight();

    return Container(
      margin: EdgeInsets.only(bottom: gap),
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerHigh,
        borderRadius: AppRadius.mdAll,
      ),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Text(
          text,
          style: style.copyWith(
            fontFamily: 'monospace',
            fontFamilyFallback: const ['Courier New', 'Courier'],
            fontSize: (style.fontSize ?? 16) * 0.85,
            height: 1.45,
          ),
        ),
      ),
    );
  }

  Widget? _image(_Element element) {
    final src = (element.attributes['src'] ?? '').trim();
    final remote = src.startsWith('https://') || src.startsWith('http://');
    if (!remote && !(src.startsWith('/') && !src.startsWith('//'))) return null;

    final alt = element.attributes['alt'] ?? '';
    return ClipRRect(
      borderRadius: AppRadius.mdAll,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxHeight: 420),
        child: Image.network(
          AppConfig.resolveMediaUrl(src),
          fit: BoxFit.contain,
          semanticLabel: alt.isEmpty ? null : alt,
          errorBuilder: (_, _, _) => const SizedBox.shrink(),
        ),
      ),
    );
  }

  Widget _table(_Element table, TextStyle style, {required double gap}) {
    final rows = <_Element>[];
    void collectRows(_Element element) {
      for (final child in element.children.whereType<_Element>()) {
        if (child.tag == 'tr') {
          rows.add(child);
        } else if (child.tag == 'thead' || child.tag == 'tbody' || child.tag == 'tfoot') {
          collectRows(child);
        }
      }
    }

    if (table.tag == 'tr') {
      rows.add(table);
    } else {
      collectRows(table);
    }
    if (rows.isEmpty) return const SizedBox.shrink();

    List<_Element> cellsOf(_Element row) => row.children
        .whereType<_Element>()
        .where((c) => c.tag == 'td' || c.tag == 'th')
        .toList();

    final columns = rows.map((r) => cellsOf(r).length).fold<int>(0, math.max);
    if (columns == 0) return const SizedBox.shrink();

    Widget cell(_Element? element) {
      if (element == null) return const SizedBox.shrink();
      final cellStyle = element.tag == 'th'
          ? style.copyWith(fontWeight: FontWeight.w700)
          : style;
      return ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 280),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.xs),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: _blocks(element.children, cellStyle, gap: 0),
          ),
        ),
      );
    }

    return Padding(
      padding: EdgeInsets.only(bottom: gap),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Table(
          defaultColumnWidth: const IntrinsicColumnWidth(),
          border: TableBorder.all(color: AppColors.outlineVariant),
          children: [
            for (final row in rows)
              TableRow(
                children: [
                  for (var i = 0; i < columns; i++)
                    cell(i < cellsOf(row).length ? cellsOf(row)[i] : null),
                ],
              ),
          ],
        ),
      ),
    );
  }

  /// Collects inline content into [runs], applying each tag's styling.
  void _inline(
    _Node node,
    TextStyle style,
    List<_Run> runs, {
    GestureRecognizer? link,
  }) {
    if (node is _Text) {
      runs.add(_Run(node.text, style, link: link));
      return;
    }

    final element = node as _Element;
    var next = style;
    var nextLink = link;

    switch (element.tag) {
      case 'br':
        runs.add(_Run('\n', style, link: link, isBreak: true));
        return;
      case 'img':
        // An image in the middle of a line keeps its words, not its pixels.
        final alt = element.attributes['alt'] ?? '';
        if (alt.isNotEmpty) runs.add(_Run(alt, style, link: link));
        return;
      case 'strong' || 'b':
        next = style.copyWith(fontWeight: FontWeight.w700);
      case 'em' || 'i' || 'cite':
        next = style.copyWith(fontStyle: FontStyle.italic);
      case 'u' || 'ins':
        next = style.copyWith(
          decoration: _withDecoration(style.decoration, TextDecoration.underline),
        );
      case 's' || 'del' || 'strike':
        next = style.copyWith(
          decoration: _withDecoration(style.decoration, TextDecoration.lineThrough),
        );
      case 'code' || 'kbd' || 'samp':
        next = style.copyWith(
          fontFamily: 'monospace',
          fontFamilyFallback: const ['Courier New', 'Courier'],
          fontSize: (style.fontSize ?? 16) * 0.9,
          backgroundColor: AppColors.surfaceContainerHigh,
        );
      case 'small':
        next = style.copyWith(fontSize: (style.fontSize ?? 16) * 0.85);
      case 'a':
        final href = (element.attributes['href'] ?? '').trim();
        if (_isSafeLink(href)) {
          final recognizer = TapGestureRecognizer()..onTap = () => onLink(href);
          recognizers.add(recognizer);
          nextLink = recognizer;
          next = style.copyWith(
            color: AppColors.primary,
            fontWeight: FontWeight.w600,
            decoration: _withDecoration(style.decoration, TextDecoration.underline),
            decorationColor: AppColors.primary,
          );
        }
    }

    for (final child in element.children) {
      // A block element inside inline markup flows as text.
      _inline(child, next, runs, link: nextLink);
    }
  }

  /// Joins runs into one span, collapsing whitespace the way a browser does:
  /// any run of spaces, tabs and newlines is one space, none at the ends.
  TextSpan? _span(List<_Run> runs, TextStyle style) {
    final children = <TextSpan>[];
    var lastWasSpace = true;

    for (final run in runs) {
      if (run.isBreak) {
        children.add(TextSpan(text: '\n', style: run.style));
        lastWasSpace = true;
        continue;
      }

      final buffer = StringBuffer();
      for (final rune in run.text.runes) {
        final space = rune == 0x20 || rune == 0x0A || rune == 0x09 || rune == 0x0D || rune == 0x0C;
        if (space) {
          if (!lastWasSpace) {
            buffer.write(' ');
            lastWasSpace = true;
          }
        } else {
          buffer.writeCharCode(rune);
          lastWasSpace = false;
        }
      }

      if (buffer.isNotEmpty) {
        children.add(TextSpan(
          text: buffer.toString(),
          style: run.style,
          recognizer: run.link,
          mouseCursor: run.link == null ? null : SystemMouseCursors.click,
        ));
      }
    }

    // Trailing whitespace and line breaks add nothing but height.
    while (children.isNotEmpty) {
      final last = children.last;
      final text = last.text ?? '';
      final trimmed = text.trimRight();
      if (trimmed == text) break;
      children.removeLast();
      if (trimmed.isNotEmpty) {
        children.add(TextSpan(
          text: trimmed,
          style: last.style,
          recognizer: last.recognizer,
          mouseCursor: last.mouseCursor,
        ));
        break;
      }
    }

    if (children.isEmpty) return null;
    return TextSpan(style: style, children: children);
  }

  static TextDecoration _withDecoration(TextDecoration? current, TextDecoration added) =>
      current == null || current == TextDecoration.none
          ? added
          : TextDecoration.combine([current, added]);

  /// Web, email and phone links only. A CMS page must not be able to hand
  /// the operating system a `javascript:` or `file:` URL.
  static bool _isSafeLink(String href) {
    final lower = href.toLowerCase();
    return lower.startsWith('https://') ||
        lower.startsWith('http://') ||
        lower.startsWith('mailto:') ||
        lower.startsWith('tel:');
  }
}

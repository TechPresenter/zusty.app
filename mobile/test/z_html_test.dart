import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zuste/shared/widgets/z_html.dart';

/// CMS pages arrive as the HTML the admin editor saved. These pin the
/// renderer to what a reader must see: the words, styled, and never the
/// markup itself.
void main() {
  Future<void> pump(WidgetTester tester, String html, {ValueChanged<String>? onLink}) {
    return tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SingleChildScrollView(child: ZHtml(html, onLinkTap: onLink)),
      ),
    ));
  }

  Finder text(String value) => find.textContaining(value, findRichText: true);

  /// The size the first visible word is drawn at. Text.rich wraps the span
  /// in the ambient default style, so the root style is not the answer.
  double leafFontSize(RichText rich) {
    double? size;
    rich.text.visitChildren((span) {
      if (span is TextSpan && (span.text ?? '').isNotEmpty) size = span.style?.fontSize;
      return size == null;
    });
    return size!;
  }

  testWidgets('renders headings and paragraphs without showing any tags', (tester) async {
    await pump(
      tester,
      '<h2>Who we are</h2><p>Zusty is an influencer marketplace.</p>'
      '<h2>Why we exist</h2><p>Terms &amp; Conditions apply &mdash; always.</p>',
    );

    expect(text('<'), findsNothing);
    expect(text('Who we are'), findsOneWidget);
    expect(text('Zusty is an influencer marketplace.'), findsOneWidget);
    // Entities decode once, as a browser shows them.
    expect(text('Terms & Conditions apply — always.'), findsOneWidget);

    // A heading is its own block, bolder and larger than body text.
    final heading = tester.widget<RichText>(
      find.byWidgetPredicate((w) => w is RichText && w.text.toPlainText() == 'Who we are'),
    );
    final body = tester.widget<RichText>(
      find.byWidgetPredicate((w) => w is RichText && w.text.toPlainText().startsWith('Zusty is')),
    );
    expect(leafFontSize(heading), greaterThan(leafFontSize(body)));
  });

  testWidgets('renders bullet and numbered lists with their markers', (tester) async {
    await pump(
      tester,
      '<ul><li><strong>Account data</strong> — name, email.</li><li>Creator data</li></ul>'
      '<ol><li>Discover</li><li>Book<li>Pay</ol>',
    );

    expect(find.text('•'), findsNWidgets(2));
    expect(find.text('1.'), findsOneWidget);
    expect(find.text('2.'), findsOneWidget);
    // An unclosed <li> is closed by the next one, as in a browser.
    expect(find.text('3.'), findsOneWidget);
    expect(text('Account data — name, email.'), findsOneWidget);
    expect(text('<li>'), findsNothing);
  });

  testWidgets('a mailto link is tappable and reports its href', (tester) async {
    String? opened;
    await pump(
      tester,
      '<p>Write to <a href="mailto:privacy@zustee.com">privacy@zustee.com</a> any time.</p>',
      onLink: (href) => opened = href,
    );

    expect(text('Write to privacy@zustee.com any time.'), findsOneWidget);
    await tester.tapOnText(find.textRange.ofSubstring('privacy@zustee.com'));
    expect(opened, 'mailto:privacy@zustee.com');
  });

  testWidgets('script and style content never reaches the screen', (tester) async {
    await pump(
      tester,
      '<p>Safe</p><script>alert("x")</script><style>p{color:red}</style>'
      '<p>Also <a href="javascript:alert(1)">not a link</a></p>',
    );

    expect(text('Safe'), findsOneWidget);
    expect(text('alert'), findsNothing);
    expect(text('color:red'), findsNothing);
    // An unsafe link keeps its words but loses the link.
    final span = tester
        .widget<RichText>(find.byWidgetPredicate(
          (w) => w is RichText && w.text.toPlainText() == 'Also not a link',
        ))
        .text as TextSpan;
    expect(span.children!.every((c) => (c as TextSpan).recognizer == null), isTrue);
  });

  testWidgets('plain text keeps its paragraphs and line breaks', (tester) async {
    await pump(tester, 'First paragraph.\n\nSecond line one\nSecond line two');

    expect(text('First paragraph.'), findsOneWidget);
    expect(text('Second line one\nSecond line two'), findsOneWidget);
  });
}

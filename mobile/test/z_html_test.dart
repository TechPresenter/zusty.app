import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/core/utils/external_link.dart';
import 'package:zuste/features/support/faqs_screen.dart';
import 'package:zuste/features/support/support_providers.dart';
import 'package:zuste/models/support.dart';
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

  // -------------------------------------------------------------------------
  // Helpers for the cases below.
  // -------------------------------------------------------------------------

  /// The one RichText whose whole text is [plain].
  RichText richExactly(WidgetTester tester, String plain) => tester.widget<RichText>(
        find.byWidgetPredicate((w) => w is RichText && w.text.toPlainText() == plain),
      );

  /// The style of the first visible word.
  TextStyle leafStyle(RichText rich) {
    TextStyle? style;
    rich.text.visitChildren((span) {
      if (span is TextSpan && (span.text ?? '').trim().isNotEmpty) style = span.style;
      return style == null;
    });
    return style!;
  }

  /// The span drawing exactly [value], searched in every RichText.
  TextSpan spanOf(WidgetTester tester, String value) {
    TextSpan? found;
    for (final rich in tester.widgetList<RichText>(find.byType(RichText))) {
      rich.text.visitChildren((span) {
        if (span is TextSpan && span.text == value) found = span;
        return found == null;
      });
      if (found != null) break;
    }
    return found!;
  }

  // -------------------------------------------------------------------------
  // P46: inline wrappers around blocks.
  // -------------------------------------------------------------------------

  group('an inline wrapper around blocks', () {
    testWidgets('keeps headings, paragraphs and lists apart, and its bold', (tester) async {
      // Google Docs wraps a whole paste in <b>.
      await pump(
        tester,
        '<b><h2>1. Scope</h2><p>This policy explains what we collect.</p>'
        '<ul><li>Account data</li></ul></b>',
      );

      expect(text('ScopeThis'), findsNothing);
      final heading = richExactly(tester, '1. Scope');
      final body = richExactly(tester, 'This policy explains what we collect.');
      expect(leafFontSize(heading), greaterThan(leafFontSize(body)));
      expect(find.text('•'), findsOneWidget);
      expect(leafStyle(body).fontWeight, FontWeight.w700);
    });

    testWidgets('inside a paragraph, a list still starts its own block', (tester) async {
      await pump(tester, '<p><b>Intro<ul><li>One</li></ul></b></p>');

      expect(text('IntroOne'), findsNothing);
      expect(text('Intro'), findsOneWidget);
      expect(find.text('•'), findsOneWidget);
    });

    testWidgets('a link around paragraphs links every one of them', (tester) async {
      final opened = <String>[];
      await pump(
        tester,
        '<a href="https://x.y"><p>One</p><p>Two</p></a>',
        onLink: opened.add,
      );

      richExactly(tester, 'One');
      richExactly(tester, 'Two');
      await tester.tapOnText(find.textRange.ofSubstring('Two'));
      await tester.tapOnText(find.textRange.ofSubstring('One'));
      expect(opened, ['https://x.y', 'https://x.y']);
    });

    testWidgets('a block inside a heading starts a new line', (tester) async {
      await pump(tester, '<h2>Fees<p>and charges</p></h2>');

      expect(text('Feesand'), findsNothing);
      expect(text('Fees\nand charges'), findsOneWidget);
    });
  });

  // -------------------------------------------------------------------------
  // P47: content placed straight inside a list.
  // -------------------------------------------------------------------------

  group('content straight inside a list', () {
    testWidgets('a nested list is kept and indented', (tester) async {
      await pump(
        tester,
        '<ul><li>Account data</li><ul><li>Email and phone</li></ul><li>Creator data</li></ul>',
      );

      expect(text('Email and phone'), findsOneWidget);
      expect(find.text('•'), findsNWidgets(3));
      expect(
        tester.getTopLeft(text('Email and phone')).dx,
        greaterThan(tester.getTopLeft(text('Account data')).dx),
      );
    });

    testWidgets('a nested numbered list counts on its own', (tester) async {
      await pump(tester, '<ol><li>A</li><ol><li>A1</li></ol><li>B</li></ol>');

      expect(find.text('1.'), findsNWidgets(2));
      expect(find.text('2.'), findsOneWidget);
      expect(text('A1'), findsOneWidget);
    });

    testWidgets('loose words are not dropped', (tester) async {
      await pump(tester, '<ul><li>A</li>Loose words</ul>');

      expect(text('Loose words'), findsOneWidget);
    });
  });

  // -------------------------------------------------------------------------
  // P48: tag names are read from the original text.
  // -------------------------------------------------------------------------

  testWidgets('mixed-case tags parse, and a dotted capital I breaks nothing', (tester) async {
    String? opened;
    await pump(
      tester,
      '<H2>İstanbul office</H2><P>Visit us.</P>'
      '<UL><LI><A HREF="https://x.y">Map</A></LI></UL><SCRIPT>bad()</SCRIPT><p>After</p>',
      onLink: (href) => opened = href,
    );

    final heading = richExactly(tester, 'İstanbul office');
    final body = richExactly(tester, 'Visit us.');
    expect(leafFontSize(heading), greaterThan(leafFontSize(body)));
    expect(find.text('•'), findsOneWidget);
    expect(text('bad()'), findsNothing);
    expect(text('After'), findsOneWidget);

    await tester.tapOnText(find.textRange.ofSubstring('Map'));
    expect(opened, 'https://x.y');
  });

  testWidgets('an angle bracket before a digit is text, not a tag', (tester) async {
    await pump(tester, '<p>Fees for <10k followers</p><p>Next</p>');

    expect(text('Fees for <10k followers'), findsOneWidget);
    expect(text('Next'), findsOneWidget);
  });

  // -------------------------------------------------------------------------
  // P49: character references.
  // -------------------------------------------------------------------------

  group('decodeHtmlEntities', () {
    test('a reference that is not a character becomes U+FFFD', () {
      for (final reference in [
        '&#55357;',
        '&#xD83D;',
        '&#0;',
        '&#x110000;',
        '&#99999999999999999999;',
      ]) {
        expect(decodeHtmlEntities(reference), '�', reason: reference);
      }
    });

    test('C1 references read as Windows-1252, as in a browser', () {
      expect(decodeHtmlEntities('&#146;'), '’');
      expect(decodeHtmlEntities('&#150;'), '–');
      expect(decodeHtmlEntities('&#128;'), '€');
      // Undefined in Windows-1252: left as the code point.
      expect(decodeHtmlEntities('&#129;'), '\u0081');
    });

    test('named references, case-sensitive, decoded once', () {
      expect(decodeHtmlEntities('&eacute;'), 'é');
      expect(decodeHtmlEntities('&Eacute;'), 'É');
      expect(decodeHtmlEntities('&ge;'), '≥');
      expect(decodeHtmlEntities('&frac12;'), '½');
      expect(decodeHtmlEntities('&nbsp;'), ' ');
      expect(decodeHtmlEntities('&AMP;'), '&');
      expect(decodeHtmlEntities('&Amp;'), '&Amp;');
      expect(decodeHtmlEntities('&amp;lt;'), '&lt;');
      expect(decodeHtmlEntities('&#x1F600;'), '😀');
    });
  });

  testWidgets('a lone surrogate reference shows U+FFFD, not a broken run', (tester) async {
    await pump(tester, '<p>Hi &#55357; there</p>');

    expect(tester.takeException(), isNull);
    expect(text('Hi � there'), findsOneWidget);
  });

  testWidgets('a raw lone surrogate in the text is replaced too', (tester) async {
    await pump(tester, '<p>Hi \uD83D there</p>');

    expect(tester.takeException(), isNull);
    expect(text('Hi � there'), findsOneWidget);
  });

  // -------------------------------------------------------------------------
  // P50 and NEW-zhtml-4: images are inline.
  // -------------------------------------------------------------------------

  group('images', () {
    testWidgets('a linked image is shown and follows the link', (tester) async {
      String? opened;
      await pump(
        tester,
        '<p><a href="https://play.google.com/store/apps/details?id=x">'
        '<img src="https://example.com/badge.png" alt=""></a></p>',
        onLink: (href) => opened = href,
      );

      expect(find.byType(Image), findsOneWidget);
      // Tapped by widget: the test HTTP client fails the image load, so the
      // picture itself has no size to hit.
      final detector = tester.widget<GestureDetector>(
        find.ancestor(of: find.byType(Image), matching: find.byType(GestureDetector)).first,
      );
      detector.onTap!();
      expect(opened, 'https://play.google.com/store/apps/details?id=x');
    });

    testWidgets('an image inside inline formatting is kept with its words', (tester) async {
      await pump(
        tester,
        '<p><b>Our office <img src="https://example.com/o.png" alt="Office"></b></p>',
      );

      expect(find.byType(Image), findsOneWidget);
      expect(text('Our office'), findsOneWidget);
    });

    testWidgets('an image mid-sentence does not split the paragraph', (tester) async {
      await pump(
        tester,
        '<p>Tap <img src="https://example.com/i.png" alt="share"> to share</p>',
      );

      final rich = tester.widget<RichText>(find.byWidgetPredicate(
        (w) => w is RichText && w.text.toPlainText().contains('to share'),
      ));
      expect(rich.text.toPlainText(), contains('Tap'));
      expect(find.byType(Image), findsOneWidget);
    });

    testWidgets('an image alone in a paragraph is not stretched', (tester) async {
      await pump(tester, '<p><img src="https://example.com/icon.png" alt="share"></p>');

      expect(tester.renderObject<RenderBox>(find.byType(Image)).constraints.minWidth, 0);
    });

    testWidgets('an icon in a sentence stays in the sentence', (tester) async {
      await pump(
        tester,
        '<p>Tap the <img src="https://example.com/i.png" alt="share"> icon</p>',
      );

      final rich = tester.widget<RichText>(find.byWidgetPredicate(
        (w) => w is RichText && w.text.toPlainText().contains('Tap the'),
      ));
      expect(rich.text.toPlainText(), contains('icon'));
    });

    testWidgets('width and height attributes cap the size', (tester) async {
      await pump(
        tester,
        '<p><img src="https://example.com/i.png" alt="x" width="24" height="20"></p>',
      );

      final constraints = tester.renderObject<RenderBox>(find.byType(Image)).constraints;
      expect(constraints.maxWidth, 24);
      expect(constraints.maxHeight, 20);
    });

    testWidgets('an image from an unsafe source keeps only its words', (tester) async {
      await pump(tester, '<p><img src="javascript:alert(1)" alt="Logo"></p>');

      expect(find.byType(Image), findsNothing);
      expect(text('Logo'), findsOneWidget);
    });
  });

  // -------------------------------------------------------------------------
  // P51: alignment, links to Zusty's own pages, spanned table cells.
  // -------------------------------------------------------------------------

  testWidgets("the editor's alignment classes align the text, and inherit", (tester) async {
    await pump(
      tester,
      '<h2 class="z-ta-center">Fees</h2><p class="z-ta-right">R</p>'
      '<div class="z-ta-justify"><p>J</p></div><p>Plain</p>',
    );

    expect(richExactly(tester, 'Fees').textAlign, TextAlign.center);
    expect(richExactly(tester, 'R').textAlign, TextAlign.right);
    expect(richExactly(tester, 'J').textAlign, TextAlign.justify);
    expect(richExactly(tester, 'Plain').textAlign, TextAlign.start);
  });

  testWidgets('a path on Zusty opens the page in the app', (tester) async {
    final router = GoRouter(
      routes: [
        GoRoute(
          path: '/',
          builder: (_, _) => const Scaffold(
            body: ZHtml(
              '<p><a href="/privacy">Privacy Policy</a>, '
              '<a href="/pages/terms">Terms</a>, <a href="#top">top</a></p>',
            ),
          ),
        ),
        GoRoute(
          path: '/pages/:slug',
          builder: (_, state) => Scaffold(
            body: Text('page:${state.pathParameters['slug']}'),
          ),
        ),
      ],
    );
    addTearDown(router.dispose);
    await tester.pumpWidget(MaterialApp.router(routerConfig: router));

    // The website's form of the link opens the same page in the app.
    await tester.tapOnText(find.textRange.ofSubstring('Privacy Policy'));
    await tester.pumpAndSettle();
    expect(find.text('page:privacy'), findsOneWidget);

    router.pop();
    await tester.pumpAndSettle();
    await tester.tapOnText(find.textRange.ofSubstring('Terms'));
    await tester.pumpAndSettle();
    expect(find.text('page:terms'), findsOneWidget);

    router.pop();
    await tester.pumpAndSettle();
    // There are no in-page anchors, so '#top' stays plain text.
    expect(spanOf(tester, 'top').recognizer, isNull);
    expect(spanOf(tester, 'Terms').recognizer, isNotNull);
  });

  testWidgets('a cell after a colspan or rowspan stays under its header', (tester) async {
    await pump(
      tester,
      '<table><tr><th colspan="2">Item</th><th>Total</th></tr>'
      '<tr><td rowspan="2">Fee</td><td>a</td><td>₹500</td></tr>'
      '<tr><td>b</td><td>₹700</td></tr></table>',
    );

    final table = tester.widget<Table>(find.byType(Table));
    Finder cell(int row, int column) =>
        find.byWidget(table.children[row].children[column]);

    expect(find.descendant(of: cell(1, 2), matching: text('₹500')), findsOneWidget);
    expect(find.descendant(of: cell(2, 2), matching: text('₹700')), findsOneWidget);
    expect(find.descendant(of: cell(2, 1), matching: text('b')), findsOneWidget);
    // The slot the rowspan covers is empty.
    expect(find.descendant(of: cell(2, 0), matching: find.byType(RichText)), findsNothing);
  });

  testWidgets('a hostile colspan cannot build a huge table', (tester) async {
    await pump(tester, '<table><tr><td colspan="100000">x</td></tr></table>');

    expect(tester.widget<Table>(find.byType(Table)).children.single.children, hasLength(12));
  });

  // -------------------------------------------------------------------------
  // P52 and NEW-zhtml-2: a link nothing on the device can open.
  // -------------------------------------------------------------------------

  group('a link nothing can open', () {
    const launcher = MethodChannel('plugins.flutter.io/url_launcher');

    /// Answers every launch the way [mode] says, and records clipboard
    /// writes. Both mocks are removed when the test ends.
    List<String> mockPlatform(WidgetTester tester, String mode) {
      final messenger = tester.binding.defaultBinaryMessenger;
      final copied = <String>[];

      messenger.setMockMethodCallHandler(launcher, (call) async {
        if (call.method != 'launch') return null;
        if (mode == 'throws') {
          // What url_launcher_android does when no app takes the intent.
          throw PlatformException(code: 'ACTIVITY_NOT_FOUND', message: 'No Activity found');
        }
        return false; // What iOS reports.
      });
      messenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
        if (call.method == 'Clipboard.setData') {
          copied.add('${(call.arguments as Map)['text']}');
        }
        return null;
      });
      addTearDown(() {
        messenger.setMockMethodCallHandler(launcher, null);
        messenger.setMockMethodCallHandler(SystemChannels.platform, null);
      });

      return copied;
    }

    for (final mode in ['throws', 'returns false']) {
      testWidgets('an email link says so and offers to copy the address ($mode)', (tester) async {
        final copied = mockPlatform(tester, mode);
        await pump(
          tester,
          '<p>Write to <a href="mailto:privacy@zustee.com?subject=Data">privacy@zustee.com</a></p>',
        );

        await tester.tapOnText(find.textRange.ofSubstring('privacy@zustee.com'));
        await tester.pumpAndSettle();

        expect(tester.takeException(), isNull);
        expect(
          find.descendant(
            of: find.byType(SnackBar),
            matching: find.text('No email app could open this. Copy privacy@zustee.com instead?'),
          ),
          findsOneWidget,
        );

        await tester.tap(find.widgetWithText(SnackBarAction, 'Copy'));
        await tester.pump();
        expect(copied, ['privacy@zustee.com']);
      });
    }

    testWidgets('a phone number offers to copy the number as written', (tester) async {
      final copied = mockPlatform(tester, 'throws');
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () => openExternalUri(
                context,
                Uri(scheme: 'tel', path: '+911800123456'),
                copyText: '+91 1800 123 456',
              ),
              child: const Text('Call us'),
            ),
          ),
        ),
      ));

      await tester.tap(find.text('Call us'));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('No phone app could open this. Copy +91 1800 123 456 instead?'), findsOneWidget);
      await tester.tap(find.widgetWithText(SnackBarAction, 'Copy'));
      await tester.pump();
      expect(copied, ['+91 1800 123 456']);
    });

    testWidgets('a web link says it could not be opened', (tester) async {
      mockPlatform(tester, 'returns false');
      await pump(tester, '<p><a href="https://example.com/terms">Terms</a></p>');

      await tester.tapOnText(find.textRange.ofSubstring('Terms'));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('Could not open that link.'), findsOneWidget);
      expect(find.byType(SnackBarAction), findsNothing);
    });
  });

  // -------------------------------------------------------------------------
  // P53: plain text, and FAQ search.
  // -------------------------------------------------------------------------

  group('htmlToPlainText', () {
    test('is the text a reader sees', () {
      expect(
        htmlToPlainText('<p>Open <strong>Settings</strong> → Help &amp; Support</p>'),
        'Open Settings → Help & Support',
      );
      expect(htmlToPlainText('<p>a</p><p>b</p>'), 'a\nb');
      expect(htmlToPlainText('<script>x</script>y'), 'y');
      expect(htmlToPlainText('<p>one<br>two</p>'), 'one\ntwo');
      expect(htmlToPlainText('<ul><li>A</li><li>B</li></ul>'), 'A\nB');
      expect(htmlToPlainText('<p>Tap <img src="/i.png" alt="share"> to\n  share</p>'), 'Tap share to share');
      expect(htmlToPlainText('Plain text\n\nstays plain'), 'Plain text\nstays plain');
      expect(htmlToPlainText('<p>Fees for <10k followers</p>'), 'Fees for <10k followers');
    });
  });

  testWidgets('FAQ search matches the answer as it is shown, not its markup', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        helpCentreFaqsProvider.overrideWith(
          (ref) async => const [
            Faq(
              id: 1,
              question: 'Where is help?',
              answer: '<p>Open <strong>Settings</strong> → Help &amp; Support</p>',
            ),
            Faq(id: 2, question: 'Fees for <10k followers?', answer: 'None.'),
          ],
        ),
      ],
      child: MaterialApp(theme: AppTheme.light, home: const FaqsScreen()),
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));

    Future<void> search(String query) async {
      await tester.enterText(find.byType(TextField), query);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
    }

    await search('open settings');
    expect(find.text('Where is help?'), findsOneWidget);
    expect(find.text('No answers found'), findsNothing);

    await search('help & support');
    expect(find.text('Where is help?'), findsOneWidget);

    // A question is plain text, never run through the HTML parser.
    await search('<10k');
    expect(find.text('Fees for <10k followers?'), findsOneWidget);
    expect(find.text('Where is help?'), findsNothing);

    // The markup itself is not searchable.
    await search('strong');
    expect(find.text('No answers found'), findsOneWidget);
    expect(find.text('Where is help?'), findsNothing);
  });
}

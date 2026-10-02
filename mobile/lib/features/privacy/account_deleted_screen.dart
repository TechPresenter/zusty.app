import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../../providers/privacy_provider.dart';
import '../../router/routes.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_scaffold.dart';

/// The goodbye screen, shown signed out, right after the account is erased.
///
/// The router opens here when [accountDeletedProvider] is set as the session
/// ends, so the last thing the person sees is confirmation that it worked,
/// rather than a sign-in form that leaves them wondering.
class AccountDeletedScreen extends ConsumerWidget {
  const AccountDeletedScreen({super.key});

  void _leave(BuildContext context, WidgetRef ref, String route) {
    ref.read(accountDeletedProvider.notifier).state = false;
    context.go(route);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return PopScope(
      canPop: false,
      child: ZScaffold(
        bottomBar: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ZButton(
              label: 'Back to sign in',
              onPressed: () => _leave(context, ref, Routes.login),
            ),
            TextButton(
              onPressed: () => _leave(context, ref, Routes.signUp),
              child: const Text('Create a new account'),
            ),
          ],
        ),
        body: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(vertical: AppSpacing.xl),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                SuccessCheck(
                  size: 96,
                  color: AppColors.success,
                  checkColor: Colors.white,
                  delay: const Duration(milliseconds: 120),
                ),
                const SizedBox(height: AppSpacing.lg),
                Reveal(
                  index: 3,
                  child: Semantics(
                    header: true,
                    child: Text(
                      'Your account has been deleted',
                      textAlign: TextAlign.center,
                      style: AppTypography.headlineMedium,
                    ),
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                Reveal(
                  index: 4,
                  child: Text(
                    'We\'ve erased your profile and personal data and signed you '
                    'out on every device. Records the law requires us to keep, '
                    'like invoices and payments, stay without your name.',
                    textAlign: TextAlign.center,
                    style: AppTypography.bodyMedium.copyWith(
                      color: AppColors.onSurfaceVariant,
                      height: 1.5,
                    ),
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                Reveal(
                  index: 5,
                  child: Text(
                    'A confirmation is on its way to your email. Thank you for '
                    'being part of Zusty.',
                    textAlign: TextAlign.center,
                    style: AppTypography.bodySmall,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

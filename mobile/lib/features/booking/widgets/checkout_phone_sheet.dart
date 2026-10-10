import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/validators.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_dialogs.dart';
import '../../../shared/widgets/z_text_field.dart';

/// Asks for the mobile number the payment gateway needs on every order.
///
/// Shown only when the server answers `phone_required` (or `phone_invalid`):
/// an account made with an email address or Google has no number, and one
/// cannot be verified by text message while SMS is off. The number goes with
/// this one payment and is not added to the account.
///
/// Resolves to the ten digits typed, or null when the payer closes the sheet.
/// [serverError] is shown under the field, e.g. the server's reason for
/// refusing the last number.
Future<String?> askCheckoutPhone(
  BuildContext context, {
  String? initial,
  String? serverError,
}) {
  return showZSheet<String>(
    context,
    title: 'Mobile number for this payment',
    subtitle: 'Our payment partner needs a mobile number on every order. '
        'It is used for this payment only and is not added to your account.',
    builder: (_) => CheckoutPhoneForm(
      initial: initial,
      serverError: serverError,
    ),
  );
}

/// The field and button inside [askCheckoutPhone]'s sheet. Pops the sheet
/// with the number once it passes [Validators.phone].
class CheckoutPhoneForm extends StatefulWidget {
  const CheckoutPhoneForm({super.key, this.initial, this.serverError});

  final String? initial;
  final String? serverError;

  @override
  State<CheckoutPhoneForm> createState() => _CheckoutPhoneFormState();
}

class _CheckoutPhoneFormState extends State<CheckoutPhoneForm> {
  final _form = GlobalKey<FormState>();
  late final _phone = TextEditingController(text: widget.initial ?? '');
  late String? _serverError = widget.serverError;

  @override
  void dispose() {
    _phone.dispose();
    super.dispose();
  }

  void _submit() {
    if (!(_form.currentState?.validate() ?? false)) return;
    Navigator.of(context).pop(_phone.text.trim());
  }

  @override
  Widget build(BuildContext context) {
    return Form(
      key: _form,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ZTextField(
            key: const ValueKey('checkout-phone'),
            label: 'Mobile number',
            hint: '10-digit mobile number',
            helper: 'Indian mobile number (+91)',
            controller: _phone,
            keyboardType: TextInputType.phone,
            prefixIcon: Icons.phone_iphone_rounded,
            autofocus: true,
            textInputAction: TextInputAction.done,
            inputFormatters: [
              FilteringTextInputFormatter.digitsOnly,
              LengthLimitingTextInputFormatter(10),
            ],
            validator: Validators.phone,
            errorText: _serverError,
            onChanged: (_) {
              if (_serverError != null) setState(() => _serverError = null);
            },
            onSubmitted: (_) => _submit(),
          ),
          const SizedBox(height: AppSpacing.md),
          ZButton(
            label: 'Continue to payment',
            icon: Icons.lock_rounded,
            onPressed: _submit,
          ),
        ],
      ),
    );
  }
}

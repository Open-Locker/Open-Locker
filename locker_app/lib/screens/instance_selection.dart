import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:locker_app/screens/login.dart';
import 'package:locker_app/services/user_service.dart';
import 'package:provider/provider.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';

class InstanceSelectionScreen extends StatefulWidget {
  const InstanceSelectionScreen({super.key});
  static const route = '/instance-selection';

  @override
  State<InstanceSelectionScreen> createState() => _InstanceSelectionScreenState();
}

class _InstanceSelectionScreenState extends State<InstanceSelectionScreen> {
  final _formKey = GlobalKey<FormState>();
  final _urlController = TextEditingController();
  bool _isLoading = false;
  String? _errorMessage;

  @override
  void dispose() {
    _urlController.dispose();
    super.dispose();
  }

  Future<void> _validateAndSaveInstance() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final url = _urlController.text.trim();
      final response = await http.get(Uri.parse('$url/api/identify'));

      if (response.statusCode == 200) {
        final instanceInfo = json.decode(response.body);
        final userService = context.read<UserService>();
        
        await userService.setInstanceUrl(url);
        await userService.setInstanceInfo(InstanceInfo.fromJson(instanceInfo));
        
        if (mounted) {
          context.go(LoginScreen.route);
        }
      } else {
        setState(() {
          _errorMessage = 'Server nicht erreichbar oder keine gültige Open-Locker-Instanz';
        });
      }
    } on FormatException {
      setState(() {
        _errorMessage = 'Ungültiges URL-Format';
      });
    } catch (e) {
      setState(() {
        _errorMessage = 'Fehler beim Verbinden mit dem Server';
      });
    } finally {
      if (mounted) {
        setState(() {
          _isLoading = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 840),
          child: Padding(
            padding: const EdgeInsets.all(24.0),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(
                      'Open Locker',
                      style: Theme.of(context).textTheme.headlineMedium,
                    ),
                  ],
                ),
                const SizedBox(height: 32),
                Form(
                  key: _formKey,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      TextFormField(
                        controller: _urlController,
                        decoration: const InputDecoration(
                          labelText: 'Instanz-URL',
                          hintText: 'https://example.com',
                          border: OutlineInputBorder(),
                        ),
                        keyboardType: TextInputType.url,
                        validator: (value) {
                          if (value == null || value.isEmpty) {
                            return 'Bitte geben Sie eine URL ein';
                          }
                          try {
                            final uri = Uri.parse(value);
                            if (!uri.hasScheme || !uri.hasAuthority) {
                              return 'Bitte geben Sie eine gültige URL ein';
                            }
                            return null;
                          } catch (e) {
                            return 'Bitte geben Sie eine gültige URL ein';
                          }
                        },
                        onFieldSubmitted: (_) => _validateAndSaveInstance(),
                      ),
                      if (_errorMessage != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 8.0),
                          child: Text(
                            _errorMessage!,
                            style: TextStyle(color: Theme.of(context).colorScheme.error),
                          ),
                        ),
                      const SizedBox(height: 24),
                      SizedBox(
                        width: double.infinity,
                        child: FilledButton(
                          onPressed: _isLoading ? null : _validateAndSaveInstance,
                          style: FilledButton.styleFrom(
                            padding: const EdgeInsets.symmetric(vertical: 16),
                          ),
                          child: _isLoading
                              ? const SizedBox(
                                  width: 20,
                                  height: 20,
                                  child: CircularProgressIndicator(strokeWidth: 2),
                                )
                              : const Text('Weiter'),
                        ),
                      ),
                    ],
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
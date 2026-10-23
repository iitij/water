import 'package:flutter/material.dart';
import 'app.dart';
import 'core/di.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(WaterFouadApp(deps: FouadDependencies.create()));
}

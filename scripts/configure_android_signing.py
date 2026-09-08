from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: configure_android_signing.py <android/app/build.gradle.kts>')

p = Path(sys.argv[1])
s = p.read_text()
if 'java.util.Properties' not in s:
    s = 'import java.util.Properties\nimport java.io.FileInputStream\n\n' + s

pre = '''val keystoreProperties = Properties()\nval keystorePropertiesFile = rootProject.file("key.properties")\nif (keystorePropertiesFile.exists()) {\n    keystoreProperties.load(FileInputStream(keystorePropertiesFile))\n}\n\n'''
if 'val keystoreProperties = Properties()' not in s:
    s = s.replace('android {', pre + 'android {', 1)

signing = '''android {\n    signingConfigs {\n        create("release") {\n            keyAlias = keystoreProperties["keyAlias"] as String\n            keyPassword = keystoreProperties["keyPassword"] as String\n            storeFile = file(keystoreProperties["storeFile"] as String)\n            storePassword = keystoreProperties["storePassword"] as String\n        }\n    }\n'''
if 'create("release")' not in s:
    s = s.replace('android {\n', signing, 1)

s = s.replace(
    'signingConfig = signingConfigs.getByName("debug")',
    'signingConfig = signingConfigs.getByName("release")',
)

# flutter_local_notifications 10+ requires Java core-library desugaring on Android.
if 'isCoreLibraryDesugaringEnabled = true' not in s:
    marker = 'compileOptions {\n'
    if marker in s:
        s = s.replace(marker, marker + '        isCoreLibraryDesugaringEnabled = true\n', 1)
    else:
        s = s.replace('android {\n', 'android {\n    compileOptions {\n        isCoreLibraryDesugaringEnabled = true\n        sourceCompatibility = JavaVersion.VERSION_11\n        targetCompatibility = JavaVersion.VERSION_11\n    }\n', 1)

if 'coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")' not in s:
    s += '''\n\ndependencies {\n    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")\n}\n'''

p.write_text(s)

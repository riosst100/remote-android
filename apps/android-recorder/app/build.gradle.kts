import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// Release signing credentials live outside version control in
// keystore.properties (see .gitignore) rather than hardcoded here.
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("keystore.properties")
val hasReleaseSigning = keystorePropertiesFile.exists()
if (hasReleaseSigning) {
    keystoreProperties.load(keystorePropertiesFile.inputStream())
}

android {
    namespace = "com.remoterecorder.agent"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.remoterecorder.agent"
        minSdk = 26
        targetSdk = 35
        versionCode = 5
        versionName = "1.2.0"

        // Production server: Nginx terminates TLS on 443 and reverse-proxies
        // /app to Reverb's internal port 8080 (see backend/laravel's Nginx
        // site config), so the app talks to port 443 for both API and WS.
        buildConfigField("String", "API_BASE_URL", "\"https://remote-android.inovasionline.com\"")
        buildConfigField("String", "WS_HOST", "\"remote-android.inovasionline.com\"")
        buildConfigField("int", "WS_PORT", "443")
        buildConfigField("boolean", "WS_TLS", "true")
        buildConfigField("String", "REVERB_APP_KEY", "\"428bda9ed6d2cce55051463b807f1d2a\"")
    }

    signingConfigs {
        if (hasReleaseSigning) {
            create("release") {
                storeFile = rootProject.file(keystoreProperties["storeFile"] as String)
                storePassword = keystoreProperties["storePassword"] as String
                keyAlias = keystoreProperties["keyAlias"] as String
                keyPassword = keystoreProperties["keyPassword"] as String
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            if (hasReleaseSigning) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
        debug {
            isMinifyEnabled = false
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        buildConfig = true
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.work:work-runtime-ktx:2.9.1")
    implementation("androidx.lifecycle:lifecycle-service:2.8.4")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.4")
    implementation("androidx.activity:activity-ktx:1.9.1")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")

    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("org.json:json:20240303")

    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.8.1")

    testImplementation("junit:junit:4.13.2")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.8.1")
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.6.1")
}

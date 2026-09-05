plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "com.remoterecorder.agent"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.remoterecorder.agent"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "1.0.0"

        // 10.24.182.78 = this dev machine's LAN IP, reachable from a
        // physical device on the same Wi-Fi. Emulators would instead use
        // 10.0.2.2 (the emulator's alias for the host's localhost).
        buildConfigField("String", "API_BASE_URL", "\"http://10.24.182.78:8002\"")
        buildConfigField("String", "WS_HOST", "\"10.24.182.78\"")
        buildConfigField("int", "WS_PORT", "8080")
        buildConfigField("boolean", "WS_TLS", "false")
        buildConfigField("String", "REVERB_APP_KEY", "\"d4akrvncqwpiycso6mrj\"")
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            buildConfigField("String", "API_BASE_URL", "\"https://your-server.example.com\"")
            buildConfigField("String", "WS_HOST", "\"your-server.example.com\"")
            buildConfigField("int", "WS_PORT", "443")
            buildConfigField("boolean", "WS_TLS", "true")
            buildConfigField("String", "REVERB_APP_KEY", "\"REPLACE_WITH_REVERB_APP_KEY\"")
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

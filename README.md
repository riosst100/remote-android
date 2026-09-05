# Remote Android Recording Agent

A production-oriented, remote-controlled Android audio recording system: an Android agent that
records on command from a Laravel admin dashboard, streaming audio back to the server in small
chunks over an authenticated upload API, coordinated in real time over Laravel Reverb (WebSockets).

This is a single Git repository containing two independently buildable/deployable projects:

```text
remote-recorder/
├── apps/
│   └── android-recorder/    Kotlin Android agent (no Flutter)
├── backend/
│   └── laravel/             Laravel 12 API + Admin Dashboard + Reverb
├── README.md
└── .gitignore
```

---

## 1. Architecture

```text
                 Laravel Admin Dashboard (Blade + fetch())
                          │
                          ▼
                    Laravel Backend (Sanctum-authenticated API)
                 ┌────────┴────────┐
                 │                 │
             PostgreSQL       Laravel Reverb (WebSocket)
                 │                 │
                 │            private-devices.{id}
                 │                 │
                 └────────┬────────┘
                          │
                          ▼
                Android Recording Agent
                          │
                 Foreground Service (type: microphone)
                          │
                MediaRecorder (AAC/ADTS, chunked)
                          │
                 10–30s Audio Chunks (temp cache files)
                          │
                          ▼
              Laravel Upload API (multipart, checksum-verified)
                          │
                          ▼
           Server File Storage (local disk today, swappable to S3)
```

Every recording is owned by exactly one device; every chunk is scoped by
`(device_id, recording_id, chunk_number)`, so multiple devices can record
simultaneously without any risk of their chunks mixing.

### Why these technology choices

- **Kotlin + native Android APIs, no Flutter.** The agent is a background service, not a UI
  app — `MediaRecorder`, `AudioRecord`/`MediaCodecList` for capability probing, `WorkManager` for
  durable retry, and a hand-rolled OkHttp WebSocket client speaking Reverb's Pusher-compatible
  protocol. No unnecessary third-party frameworks.
- **Laravel Reverb**, not polling, for command delivery — START/STOP must reach a recording
  device promptly, and Reverb's private channels give per-device isolation for free.
- **PostgreSQL**, not MySQL — used for its native `JSONB` type to store device audio
  capabilities and command payloads without a rigid schema.

---

## 2. Repository layout details

- `backend/laravel/` — a standard Laravel 12 app. Run it like any Laravel project.
- `apps/android-recorder/` — a standard Gradle/Kotlin Android app. Open it directly in Android
  Studio, or build from the CLI with `./gradlew`.

Neither project depends on files from the other at build time; they only agree on the HTTP/WS
contract described below.

---

## 3. Backend: local setup

### Requirements

- PHP 8.2+, Composer
- PostgreSQL 14+
- Node.js (only if you want to add a frontend build step later — the dashboard ships with
  zero-build vanilla CSS/JS)

### Install

```bash
cd backend/laravel
composer install
cp .env.example .env   # if starting fresh; a populated .env is already present in this repo
php artisan key:generate
```

### PostgreSQL setup

Create a database and user, then point `.env` at it:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=remote_recorder
DB_USERNAME=postgres
DB_PASSWORD=your-password
```

```bash
createdb remote_recorder   # or use your preferred Postgres admin tool
php artisan migrate --seed
```

The seeder creates one admin user for the dashboard:

```text
email:    admin@example.com
password: password
```

**Change this password before deploying anywhere reachable.**

### Reverb setup

Reverb ships as a first-party Laravel package and is already installed and configured
(`config/broadcasting.php`, `config/reverb.php`, and the `REVERB_*` env vars in `.env`).
Start it alongside the app:

```bash
php artisan reverb:start
```

In production, put Reverb behind a TLS-terminating reverse proxy (`wss://`) — see
[Security considerations](#8-security-considerations).

### Running everything locally

```bash
php artisan serve                 # HTTP API + dashboard, http://127.0.0.1:8000
php artisan reverb:start          # WebSocket server, ws://127.0.0.1:8080
php artisan queue:work            # only needed if you move chunk/finalization work to queues
php artisan schedule:work         # runs the devices:sweep-stale heartbeat-timeout sweep every minute
```

Then visit `http://127.0.0.1:8000/login`.

> **Note on `SANCTUM_STATEFUL_DOMAINS`:** the dashboard's Start/Stop buttons call the same
> `/api/*` endpoints the Android agent uses, authenticated via the admin's browser session
> (Sanctum's SPA-style stateful auth) rather than a bearer token. Sanctum only treats a request as
> "stateful" (session-cookie authenticated) when it carries a `Referer`/`Origin` header matching
> one of `SANCTUM_STATEFUL_DOMAINS` — the default covers `localhost`/`127.0.0.1` on Laravel's
> default dev port. If you serve the dashboard from a different host/port, add it to
> `SANCTUM_STATEFUL_DOMAINS` in `.env`.

### Tests

```bash
php artisan test
```

24 feature/unit tests cover: device registration + token rotation, heartbeat + offline sweep,
start/stop idempotency, capability-based configuration fallback, chunk upload + checksum
validation + duplicate-chunk protection + cross-device authorization, and recording
finalization (sequence verification, checksum re-verification, idempotent re-finalization).

Tests run against an in-memory SQLite database (`phpunit.xml`) regardless of your local
`.env`'s `DB_CONNECTION`, so they don't require PostgreSQL to be running.

---

## 4. Android agent: build & run

### Requirements

- Android Studio (Giraffe+) or a standalone JDK 17 + the Android SDK (`compileSdk 35`,
  `minSdk 26`)
- An Android device or emulator running API 26+

### Build

Open `apps/android-recorder/` directly in Android Studio — it will generate the Gradle wrapper
JAR and sync automatically. From the CLI (with a JDK 17 on `PATH` and `ANDROID_HOME` set):

```bash
cd apps/android-recorder
gradle wrapper --gradle-version 8.9   # one-time, if gradle-wrapper.jar isn't present
./gradlew :app:assembleDebug
```

The debug build points at `http://10.0.2.2:8000` and `ws://10.0.2.2:8080` (the Android emulator's
alias for the host machine's `localhost`) — see `app/build.gradle.kts`'s `buildConfigField`
values. Point these at your real server host for a physical device, and switch the release
build type's values to your production `https://`/`wss://` endpoints before shipping a release
build.

### Install & first-run setup

1. Install the APK and grant notification permission if prompted.
2. Open **Device Service** (the app's only screen) and tap **Grant Microphone Permission**.
3. Tap **Register This Device** — this calls `POST /api/devices/register`, detects this specific
   device's audio capabilities, and stores the returned per-device API token encrypted on-device
   (`EncryptedSharedPreferences`).
4. Optionally tap **Disable Battery Optimization** to reduce the chance an aggressive OEM battery
   manager kills the background service (see [OEM limitations](#7-android-oem-background-limitations)).
5. The device now appears **ONLINE** in the dashboard's Devices page.

There is deliberately no Start/Stop control in the app — all recording control comes from the
Laravel dashboard, per the system's design.

---

## 5. Using the dashboard

1. Log in at `/login`.
2. **Devices** — see every registered device, its live status (ONLINE / RECORDING / OFFLINE /
   ERROR), and start/stop a recording with a quality preset.
3. **Device Detail** — audio capabilities, connection status, and a per-preset preview of the
   configuration that will be requested (the actual configuration used is whatever the device
   itself falls back to if the proposal turns out to be unsupported).
4. **Recordings** — every recording, filterable by device/status.
5. **Recording Detail** — full lifecycle info, chunk count, and a download link once
   `COMPLETED`.
6. **System Logs** — tail of `storage/logs/laravel.log`.

---

## 6. Data-loss trade-off (read this before relying on this system)

Per the design goal of never turning the Android device into permanent recording storage, audio
only ever exists on-device as **temporary chunk files** in the app's cache directory, deleted the
moment the server acknowledges the upload.

**Consequence:** if the device loses power, is force-killed, or permanently loses connectivity
before a buffered chunk has been uploaded, that buffered audio (at most one chunk-length of audio,
bounded by `RECORDER_CHUNK_SECONDS`, default 20s) is lost. `WorkManager`-backed retry with
exponential backoff covers ordinary network flakiness and even process death (WorkManager
persists its queue and resumes after reboot/relaunch), but it cannot recover from a chunk file
that no longer exists on disk.

This is a deliberate trade-off, not an oversight — the alternative (keeping the full recording on
the device until upload completes) was explicitly ruled out by the system's privacy/storage
requirements.

---

## 7. Android OEM background limitations

Some OEM Android skins (notably certain Xiaomi/MIUI, Huawei, Oppo/ColorOS, and some Samsung
configurations) apply their own aggressive background-process killers on top of stock Android
Doze/App Standby, and can kill a foreground service despite it holding a valid
`FOREGROUND_SERVICE_MICROPHONE` type and an active notification. This system does what it can
within official APIs:

- Runs as a true foreground service with the correct service type and a persistent, accurate
  notification while active.
- Prompts the user (via the Setup screen) to request exemption from battery optimization through
  the standard `ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` system dialog — this is a real
  system permission the user must approve, never bypassed.
- Reconnects the WebSocket and resumes heartbeats after a boot, without ever auto-starting a
  recording.

**This system does not, and cannot, guarantee indefinite background execution on every Android
OEM.** If an OEM's process killer terminates the app outright, recording stops and the device will
show as OFFLINE once its heartbeat times out; the admin will see this in the dashboard and can
re-issue a START command once the agent reconnects (manually reopened by the user, or on the
device's next natural wake/unlock if the OS restarts the service).

---

## 8. Security considerations

- **Transport:** production deployments must run the API and Reverb behind TLS (`https://`,
  `wss://`). The Android release build type is pre-configured with `WS_TLS = true`.
- **Device authentication:** each device gets its own Sanctum personal access token, scoped via a
  dedicated `device` auth guard so a device token can never be used against admin-only dashboard
  endpoints, and vice versa. Tokens are rotated on every re-registration.
- **No secrets in the APK.** The Android app never embeds an API secret — only a build-time base
  URL and the public Reverb app key (which is meant to be public in Pusher-protocol clients;
  actual channel authorization is done server-side per connection).
- **Private broadcasting channels:** all device/recording channels require authorization —
  `routes/channels.php` plus two dedicated broadcasting-auth endpoints (one for admin sessions,
  one for device bearer tokens).
- **Upload integrity:** every chunk upload includes a SHA-256 checksum, verified both at upload
  time and again at finalization time before chunks are concatenated.
- **Storage:** recordings live under a private (non-web-servable) disk; the only way to fetch a
  finished recording is the authenticated `/recordings/{recording}/download` dashboard route.
- **Rate limiting:** registration and chunk upload endpoints are throttled.
- **No stealth behavior.** The agent uses only official Android permission and foreground-service
  APIs, always shows an accurate "recording service is active" notification while recording, and
  never attempts to hide microphone usage or bypass the OS's privacy indicators.

### Production checklist

- [ ] Change the seeded admin password (or remove the seeder and create users manually).
- [ ] Set `APP_DEBUG=false`, a strong `APP_KEY`, and real `DB_*` / `REVERB_*` secrets in `.env`.
- [ ] Put the API and Reverb behind HTTPS/WSS.
- [ ] Set `SANCTUM_STATEFUL_DOMAINS` to your real dashboard domain.
- [ ] Point the Android release build's `API_BASE_URL` / `WS_HOST` at production and rebuild.
- [ ] Move `FILESYSTEM_DISK`/`recorder.storage_disk` to S3/R2/MinIO once volume warrants it (the
      storage abstraction — `config/filesystems.php`'s `recordings` disk — was built for this;
      finalization/upload code never references `local` directly).

---

## 9. API reference (summary)

All endpoints are under `/api`. Device endpoints require `Authorization: Bearer <device token>`;
admin endpoints require an authenticated dashboard session (Sanctum SPA-stateful).

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/devices/register` | none | Register/re-register a device, get a token |
| POST | `/devices/heartbeat` | device | Liveness + status ping |
| POST | `/devices/error` | device | Report a permission/hardware/service error |
| GET | `/devices` | admin | List devices |
| GET | `/devices/{device}` | admin | Device detail |
| POST | `/recordings/start` | admin | Start a recording on a device |
| POST | `/recordings/{recording}/stop` | admin | Stop a recording |
| GET | `/recordings` | admin | List recordings |
| GET | `/recordings/{recording}` | admin | Recording detail |
| POST | `/recordings/{recording}/chunks` | device | Upload one audio chunk |
| POST | `/recordings/{recording}/complete` | device | Request finalization |
| POST | `/commands/{command}/ack` | device | Acknowledge a command (`command_received`, `recording_started`, `recording_stopped`, `recording_error`) |
| POST | `/broadcasting/auth` | admin | Reverb channel auth for the dashboard |
| POST | `/devices/broadcasting/auth` | device | Reverb channel auth for the agent |

Broadcast events (Reverb, private channels `admin.devices`, `admin.recordings`,
`devices.{id}`, `recordings.{uuid}`): `DeviceConnected`, `DeviceDisconnected`,
`DeviceStatusChanged`, `RecordingStartRequested`, `RecordingStarted`, `RecordingStopRequested`,
`RecordingStopped`, `RecordingStatusChanged`, `ChunkUploaded`, `RecordingCompleted`,
`RecordingFailed`.

---

## 10. Manual test plan (Android)

Automated Android tests cover pure logic (`RecordingConfig` parsing/fallback JSON, `Command`
parsing). The following require a real device/emulator and are not automated:

- [ ] Denying microphone permission surfaces `MIC_PERMISSION_DENIED` to the server on a START
      attempt, and the recording never silently "succeeds."
- [ ] Capability detection reports realistic values for at least two different physical devices.
- [ ] Start → screen off → wait 2 minutes → screen on: recording continues, chunks keep
      uploading.
- [ ] Start → lock device → wait 2 minutes → unlock: same as above.
- [ ] Disable Wi-Fi/mobile data mid-recording: recording continues; chunks queue via WorkManager
      and drain once connectivity returns; no duplicate chunks appear server-side.
- [ ] Force-kill the app process mid-recording (if the OS allows it): on relaunch, no duplicate
      recording session is created for an already-active recording; pending chunk uploads
      (if their temp files survived) still complete via WorkManager.
- [ ] Send a duplicate START command (e.g. double-click Start in the dashboard quickly): only one
      recording/session is created.
- [ ] Long recordings — 30 min, 1 hour, 2 hours — monitor memory (should stay flat; audio is
      never buffered in memory beyond one chunk), battery, and confirm chunk numbering stays
      contiguous end to end.

---

## 11. Known limitations

- Background execution reliability varies by OEM (see §7) — this is a platform constraint, not
  a bug in this codebase.
- A crashed/killed app can lose at most one chunk-length of unsaved audio (see §6).
- The bundled admin dashboard is intentionally minimal (server-rendered Blade + vanilla JS) —
  it does not include a full audio waveform player, multi-admin role separation, or SSO; extend
  `resources/views/` and `app/Http/Controllers/Web/` as needed.
- FLAC/Opus encoder paths are modeled in the preset ladder and capability negotiation but the
  Android agent's chunked recorder currently implements the AAC/ADTS `MediaRecorder` path only;
  wiring FLAC/Opus would mean swapping in a `MediaCodec`-based encoder behind the same
  `ChunkingAudioRecorder` interface.

# Open-Locker

[![Discord](https://img.shields.io/discord/1330191581273260113?style=flat-square&logo=discord&label=Discord&labelColor=%23FFF)](https://discord.gg/rZ74RYKN3H)

I'll help you create a comprehensive README for the Flutter app setup and extend the existing documentation. Here's how we can structure it:

# Open-Locker Project

## Project Overview
An open-source solution for managing public lockers to store and share items, sponsored by Smart City Hameln-Pyrmont. The project consists of:
- `locker-backend`: Laravel backend
- `locker_app`: Flutter frontend

## Monorepo Structure
This monorepo contains:
- `/locker-backend` - Backend application (Laravel)
- `/locker_app` - Mobile application (Flutter)

## Getting Started

### Prerequisites
- Docker (for backend)
- Flutter SDK (for frontend)
- Git

### 1. Backend Setup
See the [Backend README](/locker-backend/README.md) for detailed setup instructions using either DevContainer or Laravel Sail.


### 2. Flutter App Setup

#### Prerequisites
- Flutter SDK (version 3.19.0 or higher)
- Android Studio/Xcode (for emulator/simulator)
- Dart SDK

#### Installation Steps

1. **Install Flutter**:
   - Follow official installation guide: https://flutter.dev/docs/get-started/install
   - Verify installation:
     ```bash
     flutter doctor
     ```

2. **Navigate to app directory**:
   ```bash
   cd locker_app
   ```

3. **Install dependencies**:
   ```bash
   flutter pub get
   ```

4. **Configure environment**:
   - Copy the example environment file:
     ```bash
     cp .env.example .env
     ```
   - Update the `.env` file with your backend URL (default should work with local backend)

5. **Run the app**:
   ```bash
   flutter run
   ```
   - For specific device:
     ```bash
     flutter run -d <device_id>
     ```
   - To list available devices:
     ```bash
     flutter devices
     ```

#### Platform-Specific Setup

**Android**:
- Ensure Android SDK is installed
- Create an Android emulator or connect physical device

**iOS** (macOS only):
- Install Xcode
- Install CocoaPods:
  ```bash
  sudo gem install cocoapods
  ```
- Navigate to iOS directory and install pods:
  ```bash
  cd ios && pod install && cd ..
  ```

### Common Commands

- Check for outdated packages:
  ```bash
  flutter pub outdated
  ```

- Upgrade dependencies:
  ```bash
  flutter pub upgrade
  ```

- Run tests:
  ```bash
  flutter test
  ```

- Build APK:
  ```bash
  flutter build apk
  ```

- Build iOS app:
  ```bash
  flutter build ios
  ```

## Git Hooks Setup
To ensure code quality:
```bash
./install-hooks.sh
```
Verify setup:
```bash
git config core.hooksPath
```

## Contributing
- Join our weeklies on Discord (Mondays and Tuesdays at 19:30 CET)
- Check our roadmap for current priorities
- Submit issues or pull requests

## Support
For help, reach out in our Discord channels or open a GitHub issue.


[![Discord](https://img.shields.io/discord/1330191581273260113?style=flat-square&logo=discord&label=Discord&labelColor=%23FFF)](https://discord.gg/rZ74RYKN3H)

---

This extended README provides a complete setup guide for both backend and frontend while maintaining the existing project information. The Flutter setup section includes clear steps for different platforms and common development commands.
